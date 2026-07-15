<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Database\PaymentVerificationRepository;

/** Atomic owner of verified online-payment allocation and local confirmation. */
final class OnlinePaymentVerificationService
{
    private $reservations;
    private $payments;
    private PaymentVerificationRepository $verifications;
    private PaymentVerificationIntegrity $integrity;

    public function __construct($reservations = null, $payments = null, ?PaymentVerificationRepository $verifications = null)
    {
        $this->reservations = $reservations ?: get_reservation_repository();
        $this->payments = $payments ?: get_payment_repository();
        $this->verifications = $verifications ?: new PaymentVerificationRepository();
        $this->integrity = new PaymentVerificationIntegrity();
    }

    /** @param array<int, int> $reservationIds @param array<string, mixed> $verified */
    public function recordAndConfirm(string $provider, array $reservationIds, array $verified): array
    {
        $provider = \sanitize_key($provider);
        $reservationIds = \array_values(\array_unique(\array_filter(\array_map('intval', $reservationIds))));
        \sort($reservationIds);
        if (!\in_array($provider, ['stripe', 'pokpay'], true) || empty($reservationIds) || empty($verified['server_verified'])) {
            return $this->failure('provider_verification_missing');
        }
        $attemptValidation = (new PaymentAttemptIntegrity())->validateFinalization($provider, $reservationIds, $verified);
        if (empty($attemptValidation['allowed'])) {
            return $this->paidFailure($provider, $reservationIds, $verified, (string) ($attemptValidation['reason_code'] ?? 'verified_attempt_mismatch'));
        }
        if (!$this->reservations->beginTransaction()) {
            return $this->paidFailure($provider, $reservationIds, $verified, 'transaction_start_failed');
        }

        $paymentEvents = [];
        $confirmedIds = [];
        $paymentIds = [];
        $deferConfirmation = !empty($verified['defer_confirmation']);
        try {
            $rows = [];
            foreach ($reservationIds as $reservationId) {
                $row = $this->reservations->getReservationForUpdate($reservationId);
                if (!\is_array($row)) {
                    throw new \RuntimeException('reservation_set_mismatch');
                }
                $rows[] = $row;
            }
            if (\count($rows) !== \count($reservationIds)) {
                throw new \RuntimeException('reservation_set_mismatch');
            }
            $currency = \strtoupper(\trim((string) ($verified['currency'] ?? '')));
            $totalMinor = (int) ($verified['total_amount_minor'] ?? -1);
            $expectedFlow = 'website_online_' . $provider;
            $allocationTotal = 0;
            foreach ($rows as $index => $row) {
                $flow = \sanitize_key((string) ($row['confirmation_flow'] ?? 'legacy'));
                if ($flow === 'legacy') {
                    $attemptPaymentId = $this->payments->getLatestPaymentIdForReservationMethodTransaction(
                        (int) ($row['id'] ?? 0),
                        $provider,
                        (string) ($verified['provider_attempt_reference'] ?? '')
                    );
                    if ($attemptPaymentId <= 0 || !$this->reservations->setConfirmationFlowForFirstConfirmation((int) ($row['id'] ?? 0), $expectedFlow)) {
                        throw new \RuntimeException('legacy_online_flow_binding_missing');
                    }
                    $row = $this->reservations->getReservationForUpdate((int) ($row['id'] ?? 0));
                    if (!\is_array($row)) {
                        throw new \RuntimeException('reservation_set_mismatch');
                    }
                    $rows[$index] = $row;
                    $flow = \sanitize_key((string) ($row['confirmation_flow'] ?? 'legacy'));
                }
                if ($flow !== $expectedFlow) {
                    throw new \RuntimeException('confirmation_flow_mismatch');
                }
                if (\in_array(\sanitize_key((string) ($row['status'] ?? '')), ['cancelled', 'expired', 'payment_failed', 'blocked'], true)) {
                    throw new \RuntimeException('non_reconfirmable_reservation_state');
                }
                $allocationTotal += CurrencyMinorUnits::toMinor((float) ($row['total_price'] ?? 0.0), $currency);
            }
            if ($currency === '' || $totalMinor < 0 || $allocationTotal !== $totalMinor || $currency !== \strtoupper(MustBookingConfig::get_currency())) {
                throw new \RuntimeException('provider_total_allocation_mismatch');
            }

            $providerPayment = [
                'provider' => $provider,
                'provider_mode' => (string) ($verified['provider_mode'] ?? ''),
                'provider_account_fingerprint' => (string) ($verified['provider_account_fingerprint'] ?? ''),
                'provider_transaction_reference' => (string) ($verified['provider_transaction_reference'] ?? ''),
                'provider_attempt_reference' => (string) ($verified['provider_attempt_reference'] ?? ''),
                'total_amount_minor' => $totalMinor,
                'currency' => $currency,
            ];
            $ownershipKey = \hash('sha256', \implode('|', [
                $provider,
                \sanitize_key((string) $providerPayment['provider_mode']),
                \strtolower(\trim((string) $providerPayment['provider_account_fingerprint'])),
                \trim((string) $providerPayment['provider_transaction_reference']),
            ]));
            $existingGroup = $this->verifications->findGroupByOwnershipKeyForUpdate($ownershipKey);
            if (\is_array($existingGroup)) {
                $allocations = $this->verifications->getAllocations((int) $existingGroup['id']);
                $storedIds = \array_map(static function (array $row): int { return (int) ($row['reservation_id'] ?? 0); }, $allocations);
                \sort($storedIds);
                $candidate = $this->integrity->normalize($providerPayment, $allocations);
                if (
                    $storedIds !== $reservationIds
                    || empty($candidate['valid'])
                    || (string) ($existingGroup['provider_attempt_reference'] ?? '') !== (string) $providerPayment['provider_attempt_reference']
                    || (string) ($existingGroup['allocation_set_hash'] ?? '') !== (string) ($candidate['group']['allocation_set_hash'] ?? '')
                    || (int) ($existingGroup['total_amount_minor'] ?? -1) !== $totalMinor
                    || (string) ($existingGroup['currency'] ?? '') !== $currency
                ) {
                    throw new \RuntimeException('provider_payment_allocation_mismatch');
                }
                foreach ($allocations as $allocation) {
                    $allocationReservationId = (int) ($allocation['reservation_id'] ?? 0);
                    $currentRow = null;
                    foreach ($rows as $row) {
                        if ((int) ($row['id'] ?? 0) === $allocationReservationId) {
                            $currentRow = $row;
                            break;
                        }
                    }
                    if (
                        !\is_array($currentRow)
                        || (int) ($allocation['amount_minor'] ?? -1) !== CurrencyMinorUnits::toMinor((float) ($currentRow['total_price'] ?? 0.0), $currency)
                        || \strtoupper((string) ($allocation['currency'] ?? '')) !== $currency
                    ) {
                        throw new \RuntimeException('provider_payment_allocation_mismatch');
                    }
                }
                if (!$deferConfirmation) {
                    $confirmation = new ReservationConfirmationService($this->reservations, $this->payments, $this->verifications);
                    foreach ($reservationIds as $reservationId) {
                        $result = $confirmation->confirm($reservationId, 'confirmed', 'paid', [
                            'command' => 'gateway_verification',
                            'source' => (string) ($verified['verification_source'] ?? 'server_fetch'),
                            'provider_mode' => (string) $providerPayment['provider_mode'],
                            'provider_account_fingerprint' => (string) $providerPayment['provider_account_fingerprint'],
                            'provider_transaction_reference' => (string) $providerPayment['provider_transaction_reference'],
                            'provider_attempt_reference' => (string) $providerPayment['provider_attempt_reference'],
                            'defer_event' => true,
                        ]);
                        if (empty($result['success'])) {
                            throw new \RuntimeException((string) ($result['reason_code'] ?? 'confirmation_denied'));
                        }
                        if (!empty($result['changed'])) {
                            $confirmedIds[] = $reservationId;
                        }
                    }
                }
                if (!$this->reservations->commit()) {
                    throw new \RuntimeException('transaction_commit_failed');
                }
                $storedPaymentIds = $this->paymentIds($allocations);
                if (!$deferConfirmation && !empty($confirmedIds)) {
                    foreach ($storedPaymentIds as $reservationId => $paymentId) {
                        $payment = $this->payments->getPayment($paymentId);
                        if (\is_array($payment)) {
                            \do_action('must_hotel_booking/payment_recorded', $payment + ['payment_id' => $paymentId, 'reservation_id' => $reservationId, 'is_update' => true]);
                        }
                    }
                    foreach ($confirmedIds as $reservationId) {
                        \do_action('must_hotel_booking/reservation_confirmed', $reservationId);
                    }
                }
                return ['success' => true, 'state' => $deferConfirmation ? 'verified' : 'paid', 'idempotent' => true, 'confirmed_ids' => $confirmedIds, 'payment_ids_by_reservation' => $storedPaymentIds];
            }

            $allocations = [];
            foreach ($rows as $row) {
                $reservationId = (int) $row['id'];
                $attemptReference = (string) $providerPayment['provider_attempt_reference'];
                $paymentId = $this->payments->getLatestPaymentIdForReservationMethodTransaction($reservationId, $provider, $attemptReference);
                if ($paymentId <= 0) {
                    throw new \RuntimeException('pending_payment_attempt_missing');
                }
                $payment = $this->payments->getPayment($paymentId);
                if (!\is_array($payment) || !\in_array(\sanitize_key((string) ($payment['status'] ?? '')), ['pending', 'paid'], true)) {
                    throw new \RuntimeException('pending_payment_attempt_invalid');
                }
                $paymentData = [
                    'amount' => (float) ($row['total_price'] ?? 0.0),
                    'currency' => $currency,
                    'method' => $provider,
                    'status' => 'paid',
                    'transaction_id' => (string) $providerPayment['provider_transaction_reference'],
                    'paid_at' => \current_time('mysql'),
                ];
                if (!$this->payments->updatePayment($paymentId, $paymentData)) {
                    throw new \RuntimeException('payment_update_failed');
                }
                $amountMinor = CurrencyMinorUnits::toMinor((float) $paymentData['amount'], $currency);
                $allocations[] = ['reservation_id' => $reservationId, 'payment_id' => $paymentId, 'amount_minor' => $amountMinor, 'currency' => $currency];
                $paymentIds[$reservationId] = $paymentId;
                $paymentEvents[] = $paymentData + ['payment_id' => $paymentId, 'reservation_id' => $reservationId, 'is_update' => true];
            }
            $normalized = $this->integrity->normalize($providerPayment, $allocations);
            if (empty($normalized['valid'])) {
                throw new \RuntimeException((string) ($normalized['reason_code'] ?? 'verification_integrity_failed'));
            }
            $group = (array) $normalized['group'] + [
                'verification_source' => \sanitize_key((string) ($verified['verification_source'] ?? 'server_fetch')),
                'provider_event_reference' => \sanitize_text_field((string) ($verified['provider_event_reference'] ?? '')),
                'raw_response_hash' => \sanitize_text_field((string) ($verified['raw_response_hash'] ?? '')),
                'provider_completed_at' => (string) ($verified['provider_completed_at'] ?? '') ?: null,
                'verified_at' => \current_time('mysql'),
                'created_at' => \current_time('mysql'),
            ];
            $evidence = [];
            foreach ((array) $normalized['allocations'] as $allocation) {
                $allocation['claim_hash'] = \hash('sha256', $group['ownership_key'] . '|' . $allocation['reservation_id'] . '|' . $allocation['payment_id'] . '|' . $allocation['amount_minor'] . '|' . $allocation['currency']);
                $allocation['created_at'] = \current_time('mysql');
                $evidence[] = $allocation;
            }
            $stored = $this->verifications->createGroupWithAllocations($group, $evidence);
            if (empty($stored['success']) || !empty($stored['idempotent'])) {
                throw new \RuntimeException((string) ($stored['reason_code'] ?? 'verification_store_race'));
            }
            if ($deferConfirmation) {
                if (!$this->reservations->commit()) {
                    throw new \RuntimeException('transaction_commit_failed');
                }
                return ['success' => true, 'state' => 'verified', 'idempotent' => false, 'confirmed_ids' => [], 'payment_ids_by_reservation' => $paymentIds];
            }
            $confirmation = new ReservationConfirmationService($this->reservations, $this->payments, $this->verifications);
            foreach ($reservationIds as $reservationId) {
                $result = $confirmation->confirm($reservationId, 'confirmed', 'paid', [
                    'command' => 'gateway_verification',
                    'source' => (string) ($verified['verification_source'] ?? 'server_fetch'),
                    'provider_mode' => (string) $providerPayment['provider_mode'],
                    'provider_account_fingerprint' => (string) $providerPayment['provider_account_fingerprint'],
                    'provider_transaction_reference' => (string) $providerPayment['provider_transaction_reference'],
                    'provider_attempt_reference' => (string) $providerPayment['provider_attempt_reference'],
                    'defer_event' => true,
                ]);
                if (empty($result['success'])) {
                    throw new \RuntimeException((string) ($result['reason_code'] ?? 'confirmation_denied'));
                }
                if (!empty($result['changed'])) {
                    $confirmedIds[] = $reservationId;
                }
            }
            if (!$this->reservations->commit()) {
                throw new \RuntimeException('transaction_commit_failed');
            }
        } catch (\Throwable $error) {
            $this->reservations->rollback();
            return $this->paidFailure($provider, $reservationIds, $verified, $error->getMessage());
        }
        foreach ($paymentEvents as $event) {
            \do_action('must_hotel_booking/payment_recorded', $event);
        }
        foreach ($confirmedIds as $reservationId) {
            \do_action('must_hotel_booking/reservation_confirmed', $reservationId);
        }
        return ['success' => true, 'state' => 'paid', 'idempotent' => false, 'confirmed_ids' => $confirmedIds, 'payment_ids_by_reservation' => $paymentIds];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, int> */
    private function paymentIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) ($row['reservation_id'] ?? 0)] = (int) ($row['payment_id'] ?? 0);
        }
        return $ids;
    }

    private function failure(string $reason): array
    {
        return ['success' => false, 'state' => 'manual_review', 'retryable' => false, 'reason_code' => \sanitize_key($reason)];
    }

    /** @param array<int, int> $reservationIds @param array<string, mixed> $verified */
    private function paidFailure(string $provider, array $reservationIds, array $verified, string $reason): array
    {
        $observation = (new PaidProviderOutcomeService())->persist(
            $provider,
            $reservationIds,
            $verified,
            $reason,
            isset($verified['observed_reservation_ids']) && \is_array($verified['observed_reservation_ids'])
                ? $verified['observed_reservation_ids']
                : $reservationIds
        );
        return [
            'success' => false,
            'state' => 'manual_review',
            'retryable' => false,
            'provider_paid' => true,
            'paid_observation_persisted' => !empty($observation['success']),
            'reason_code' => \sanitize_key($reason),
            'observation_reason_code' => (string) ($observation['reason_code'] ?? ''),
        ];
    }
}
