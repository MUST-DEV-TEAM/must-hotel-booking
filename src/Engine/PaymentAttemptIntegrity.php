<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Provider\ProviderManager;

/** Immutable identity and exact-allocation checks for pending provider attempts. */
final class PaymentAttemptIntegrity
{
    private PaymentEnvironmentCompatibilityPolicy $compatibility;

    public function __construct(?PaymentEnvironmentCompatibilityPolicy $compatibility = null)
    {
        $this->compatibility = $compatibility ?: new PaymentEnvironmentCompatibilityPolicy();
    }

    /** @param array<int, int> $reservationIds @return array<string, mixed> */
    public function prepare(string $provider, array $reservationIds, float $amount, string $currency, string $checkoutMode): array
    {
        $provider = PaymentEngine::normalizeMethod($provider);
        $reservationIds = $this->ids($reservationIds);
        $rows = get_reservation_repository()->getReservationsByIds($reservationIds);
        if (!\in_array($provider, ['stripe', 'pokpay'], true) || empty($reservationIds) || \count($rows) !== \count($reservationIds)) {
            return $this->deny('attempt_reservation_set_invalid');
        }
        $currency = \strtoupper(\sanitize_text_field($currency));
        $amountMinor = CurrencyMinorUnits::toMinor($amount, $currency);
        $allocationTotal = 0;
        $clockRequired = false;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                return $this->deny('attempt_reservation_set_invalid');
            }
            $allocationTotal += CurrencyMinorUnits::toMinor((float) ($row['total_price'] ?? 0.0), $currency);
            $clockRequired = $clockRequired || \sanitize_key((string) ($row['provider'] ?? '')) === ProviderManager::CLOCK_MODE;
        }
        if ($currency === '' || $amountMinor <= 0 || $allocationTotal !== $amountMinor) {
            return $this->deny('attempt_amount_currency_mismatch');
        }
        $target = $this->compatibility->evaluateCurrent($provider, $clockRequired);
        if (empty($target['allowed'])) {
            return $target;
        }
        return [
            'allowed' => true,
            'reason_code' => '',
            'attempt' => [
                'attempt_status' => 'pending',
                'provider_attempt_reference' => '',
                'attempt_site_environment' => (string) $target['site_environment'],
                'attempt_provider_mode' => (string) $target['provider_mode'],
                'attempt_account_fingerprint' => (string) $target['provider_account_fingerprint'],
                'attempt_checkout_mode' => \sanitize_key($checkoutMode),
                'attempt_clock_environment' => (string) $target['clock_environment'],
                'attempt_clock_target_fingerprint' => (string) $target['clock_target_fingerprint'],
                'attempt_reservation_set_hash' => $this->reservationSetHash($reservationIds),
                'attempt_allocation_set_hash' => '',
                'attempt_group_amount_minor' => $amountMinor,
                'attempt_currency' => $currency,
                'attempt_expires_at' => '',
                'attempt_booking_snapshot_hash' => $this->bookingSnapshotHash($rows, $currency),
                'attempt_failure_code' => '',
            ],
        ];
    }

    /** @param array<string, mixed> $pending @return array<string, mixed> */
    public function validateReusable(array $pending, string $provider, float $amount, string $currency, string $flow): array
    {
        $provider = PaymentEngine::normalizeMethod($provider);
        $attemptReference = \sanitize_text_field((string) ($pending['session_id'] ?? ''));
        $selectorIds = $this->ids((array) ($pending['reservation_ids'] ?? []));
        if ($attemptReference === '' || empty($selectorIds)) {
            return $this->deny('pending_attempt_selector_missing');
        }
        $rows = get_payment_repository()->getPaymentAttemptRows($provider, $attemptReference);
        if (empty($rows)) {
            return $this->deny('pending_attempt_not_durable');
        }
        $reservationIds = $this->ids(\array_column($rows, 'reservation_id'));
        $stored = $rows[0];
        $restartAllowed = BookingStatusEngine::areReusablePendingPaymentReservations($reservationIds);
        if ($reservationIds === $selectorIds
            && (string) ($stored['attempt_status'] ?? '') === 'superseded'
            && (string) ($stored['attempt_failure_code'] ?? '') === 'pending_attempt_expired') {
            return [
                'allowed' => false,
                'exact' => false,
                'restart_allowed' => $restartAllowed,
                'reservation_ids' => $reservationIds,
                'reason_code' => 'pending_attempt_expired',
            ];
        }
        if ($reservationIds !== $selectorIds) {
            return $this->deny('pending_attempt_reservation_mismatch');
        }
        if ((string) ($stored['attempt_status'] ?? '') !== 'pending') {
            return $this->deny('pending_attempt_state_mismatch');
        }
        foreach ($rows as $row) {
            if ((string) ($row['attempt_status'] ?? '') !== 'pending'
                || \sanitize_key((string) ($row['status'] ?? '')) !== 'pending') {
                return $this->deny('pending_attempt_state_mismatch');
            }
            if ((string) ($row['provider_attempt_reference'] ?? '') !== $attemptReference
                || (string) ($row['attempt_reservation_set_hash'] ?? '') !== (string) ($stored['attempt_reservation_set_hash'] ?? '')) {
                return $this->rejectAndSupersede($rows, 'pending_attempt_row_identity_mismatch', false);
            }
        }
        $expires = \strtotime((string) ($stored['attempt_expires_at'] ?? ''));
        if ($expires === false || $expires <= \time()) {
            return $this->rejectAndSupersede($rows, 'pending_attempt_expired', $restartAllowed, $reservationIds);
        }
        $prepared = $this->prepare(
            $provider,
            $reservationIds,
            $amount,
            $currency,
            (string) ($stored['attempt_checkout_mode'] ?? '')
        );
        if (empty($prepared['allowed'])) {
            return $this->rejectAndSupersede($rows, (string) ($prepared['reason_code'] ?? 'pending_attempt_target_mismatch'), false);
        }
        $candidate = (array) $prepared['attempt'];
        $checks = [
            'attempt_site_environment', 'attempt_provider_mode', 'attempt_account_fingerprint',
            'attempt_checkout_mode', 'attempt_clock_environment', 'attempt_clock_target_fingerprint',
            'attempt_reservation_set_hash', 'attempt_group_amount_minor', 'attempt_currency',
            'attempt_booking_snapshot_hash',
        ];
        foreach ($checks as $field) {
            if ((string) ($stored[$field] ?? '') !== (string) ($candidate[$field] ?? '')) {
                return $this->rejectAndSupersede($rows, 'pending_' . $field . '_mismatch', false);
            }
        }
        $allocationHash = $this->allocationHash($rows);
        if ($allocationHash === '' || !\hash_equals((string) ($stored['attempt_allocation_set_hash'] ?? ''), $allocationHash)) {
            return $this->rejectAndSupersede($rows, 'pending_attempt_allocation_mismatch', false);
        }
        if (\sanitize_key($flow) !== 'website_online_' . $provider) {
            return $this->rejectAndSupersede($rows, 'pending_attempt_flow_mismatch', false);
        }
        return [
            'allowed' => true,
            'exact' => true,
            'reason_code' => '',
            'reservation_ids' => $reservationIds,
            'provider_reference' => $attemptReference,
            'checkout_mode' => (string) ($stored['attempt_checkout_mode'] ?? ''),
            'expires_at' => (string) ($stored['attempt_expires_at'] ?? ''),
        ];
    }

    /** @param array<int, int> $reservationIds @param array<string, mixed> $verified @return array<string, mixed> */
    public function validateFinalization(string $provider, array $reservationIds, array $verified): array
    {
        $provider = PaymentEngine::normalizeMethod($provider);
        $reservationIds = $this->ids($reservationIds);
        $attemptReference = \sanitize_text_field((string) ($verified['provider_attempt_reference'] ?? ''));
        $rows = get_payment_repository()->getPaymentAttemptRows($provider, $attemptReference);
        if (empty($rows) || $this->ids(\array_column($rows, 'reservation_id')) !== $reservationIds) {
            return $this->deny('verified_attempt_allocation_missing');
        }
        $stored = $rows[0];
        if (!\in_array(\sanitize_key((string) ($stored['attempt_status'] ?? '')), ['pending', 'verified', 'manual_review'], true)) {
            return $this->deny('verified_attempt_state_invalid');
        }
        $reservationRows = get_reservation_repository()->getReservationsByIds($reservationIds);
        if (\count($reservationRows) !== \count($reservationIds)) {
            return $this->deny('verified_attempt_reservation_set_missing');
        }
        $clockRequired = false;
        foreach ($reservationRows as $reservationRow) {
            $clockRequired = $clockRequired || \sanitize_key((string) ($reservationRow['provider'] ?? '')) === ProviderManager::CLOCK_MODE;
        }
        $compatibility = $this->compatibility->evaluateCurrent($provider, $clockRequired, [
            'site_environment' => (string) ($stored['attempt_site_environment'] ?? ''),
            'provider_mode' => (string) ($stored['attempt_provider_mode'] ?? ''),
            'provider_account_fingerprint' => (string) ($stored['attempt_account_fingerprint'] ?? ''),
            'clock_environment' => (string) ($stored['attempt_clock_environment'] ?? ''),
            'clock_target_fingerprint' => (string) ($stored['attempt_clock_target_fingerprint'] ?? ''),
        ]);
        if (empty($compatibility['allowed'])) {
            return $compatibility;
        }
        if ((string) ($verified['provider_mode'] ?? '') !== (string) ($stored['attempt_provider_mode'] ?? '')
            || (string) ($verified['provider_account_fingerprint'] ?? '') !== (string) ($stored['attempt_account_fingerprint'] ?? '')
            || (int) ($verified['total_amount_minor'] ?? -1) !== (int) ($stored['attempt_group_amount_minor'] ?? -2)
            || \strtoupper((string) ($verified['currency'] ?? '')) !== (string) ($stored['attempt_currency'] ?? '')) {
            return $this->deny('verified_attempt_provider_facts_mismatch');
        }
        if ((string) ($stored['attempt_booking_snapshot_hash'] ?? '') !== $this->bookingSnapshotHash($reservationRows, (string) $stored['attempt_currency'])
            || (string) ($stored['attempt_allocation_set_hash'] ?? '') !== $this->allocationHash($rows)) {
            return $this->deny('verified_attempt_booking_snapshot_mismatch');
        }
        return ['allowed' => true, 'reason_code' => '', 'payment_rows' => $rows];
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function allocationHash(array $rows): string
    {
        $allocations = [];
        foreach ($rows as $row) {
            $paymentId = (int) ($row['id'] ?? 0);
            $reservationId = (int) ($row['reservation_id'] ?? 0);
            $currency = \strtoupper((string) ($row['currency'] ?? ''));
            if ($paymentId <= 0 || $reservationId <= 0 || $currency === '') {
                return '';
            }
            $allocations[] = [$reservationId, $paymentId, CurrencyMinorUnits::toMinor((float) ($row['amount'] ?? 0.0), $currency), $currency];
        }
        \sort($allocations);
        return !empty($allocations) ? \hash('sha256', (string) \wp_json_encode($allocations)) : '';
    }

    /** @param array<int, array<string, mixed>> $rows @param array<int, int> $reservationIds */
    private function rejectAndSupersede(array $rows, string $reason, bool $restartAllowed, array $reservationIds = []): array
    {
        $ids = \array_values(\array_filter(\array_map('intval', \array_column($rows, 'id'))));
        $superseded = get_payment_repository()->updatePaymentAttemptRows($ids, [
            'attempt_status' => 'superseded',
            'attempt_failure_code' => \sanitize_key($reason),
        ]);
        if (!$superseded) {
            $reason = 'pending_attempt_supersede_failed';
            $restartAllowed = false;
        }
        return [
            'allowed' => false,
            'exact' => false,
            'restart_allowed' => $restartAllowed,
            'reservation_ids' => $reservationIds,
            'reason_code' => \sanitize_key($reason),
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function bookingSnapshotHash(array $rows, string $currency): string
    {
        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[] = [
                (int) ($row['id'] ?? 0),
                (int) ($row['room_id'] ?? 0),
                (int) ($row['rate_plan_id'] ?? 0),
                \sanitize_key((string) ($row['provider'] ?? '')),
                (string) ($row['checkin'] ?? ''),
                (string) ($row['checkout'] ?? ''),
                CurrencyMinorUnits::toMinor((float) ($row['total_price'] ?? 0.0), $currency),
            ];
        }
        \sort($snapshot);
        return \hash('sha256', (string) \wp_json_encode($snapshot));
    }

    /** @param array<int, int> $ids */
    private function reservationSetHash(array $ids): string
    {
        return \hash('sha256', \implode(',', $ids));
    }

    /** @param mixed $ids @return array<int, int> */
    private function ids($ids): array
    {
        $ids = \is_array($ids) ? $ids : [];
        $ids = \array_values(\array_unique(\array_filter(\array_map('intval', $ids))));
        \sort($ids);
        return $ids;
    }

    /** @return array{allowed:false,reason_code:string} */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason_code' => \sanitize_key($reason)];
    }
}
