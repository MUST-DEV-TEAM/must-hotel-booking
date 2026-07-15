<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;

/** Atomic owner for explicit public/staff pay-at-hotel confirmation. */
final class OfflinePaymentConfirmationService
{
    private $reservations;
    private $payments;

    public function __construct($reservations = null, $payments = null)
    {
        $this->reservations = $reservations ?: get_reservation_repository();
        $this->payments = $payments ?: get_payment_repository();
    }

    /** @param array<int, int> $reservationIds @param array<string, mixed> $context */
    public function confirm(array $reservationIds, string $paymentStatus = 'unpaid', array $context = []): array
    {
        $reservationIds = \array_values(\array_unique(\array_filter(\array_map('intval', $reservationIds))));
        \sort($reservationIds);
        $paymentStatus = \sanitize_key($paymentStatus) ?: 'unpaid';
        $source = \sanitize_key((string) ($context['source'] ?? ''));
        if (empty($reservationIds) || !\in_array($paymentStatus, ['unpaid', 'paid'], true) || !\in_array($source, ['public_checkout', 'admin', 'staff_portal'], true)) {
            return $this->failure('offline_input_invalid', $reservationIds);
        }
        if ($source !== 'public_checkout' && !\current_user_can('manage_options') && !\current_user_can(StaffAccess::CAP_PAYMENT_MARK_PAID) && !\current_user_can(StaffAccess::CAP_RESERVATION_CREATE)) {
            return $this->failure('offline_capability_denied', $reservationIds);
        }
        if (!$this->reservations->beginTransaction()) {
            return $this->failure('transaction_start_failed', $reservationIds);
        }

        $changedIds = [];
        $paymentEvents = [];
        try {
            $rows = [];
            foreach ($reservationIds as $reservationId) {
                $row = $this->reservations->getReservationForUpdate($reservationId);
                if (!\is_array($row)) {
                    throw new \RuntimeException('reservation_set_mismatch');
                }
                $rows[] = $row;
            }
            foreach ($rows as $row) {
                $reservationId = (int) $row['id'];
                $flow = \sanitize_key((string) ($row['confirmation_flow'] ?? 'legacy'));
                $approvedFlow = '';
                if ($flow === 'legacy' && $source !== 'public_checkout') {
                    $approvedFlow = 'staff_offline';
                } elseif (!\in_array($flow, ['website_offline_pay_at_hotel', 'staff_offline'], true)) {
                    throw new \RuntimeException('offline_confirmation_flow_invalid');
                }
                if (\in_array(\sanitize_key((string) ($row['status'] ?? '')), ['cancelled', 'expired', 'payment_failed', 'blocked'], true)) {
                    throw new \RuntimeException('offline_reservation_not_confirmable');
                }
                $existing = null;
                foreach ($this->payments->getPaymentsForReservation($reservationId) as $payment) {
                    $method = \sanitize_key((string) ($payment['method'] ?? ''));
                    if (\in_array($method, ['stripe', 'pokpay'], true)) {
                        throw new \RuntimeException('offline_confirmation_online_attempt_exists');
                    }
                    if ($method === 'pay_at_hotel' && !\is_array($existing)) {
                        $existing = $payment;
                    }
                }
                $paymentData = [
                    'reservation_id' => $reservationId,
                    'amount' => (float) ($row['total_price'] ?? 0.0),
                    'currency' => \strtoupper(MustBookingConfig::get_currency()),
                    'method' => 'pay_at_hotel',
                    'status' => $paymentStatus === 'paid' ? 'paid' : 'pending',
                    'transaction_id' => '',
                ];
                if ($paymentData['status'] === 'paid') {
                    $paymentData['paid_at'] = \current_time('mysql');
                }
                if (\is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
                    $paymentId = (int) $existing['id'];
                    if (!$this->paymentMatches($existing, $paymentData)) {
                        if (!$this->payments->updatePayment($paymentId, $paymentData)) {
                            throw new \RuntimeException('offline_payment_update_failed');
                        }
                        $paymentEvents[] = $paymentData + ['payment_id' => $paymentId, 'is_update' => true];
                    }
                } else {
                    $paymentData['created_at'] = \current_time('mysql');
                    $paymentId = $this->payments->createPayment($paymentData);
                    if ($paymentId <= 0) {
                        throw new \RuntimeException('offline_payment_insert_failed');
                    }
                    $paymentEvents[] = $paymentData + ['payment_id' => $paymentId, 'is_update' => false];
                }
                $confirmation = (new ReservationConfirmationService($this->reservations, $this->payments))->confirm(
                    $reservationId,
                    'confirmed',
                    $paymentStatus,
                    [
                        'command' => 'offline_confirmation',
                        'source' => $source,
                        'approved_flow' => $approvedFlow,
                        'defer_event' => true,
                    ]
                );
                if (empty($confirmation['success'])) {
                    throw new \RuntimeException((string) ($confirmation['reason_code'] ?? 'offline_confirmation_denied'));
                }
                if (!empty($confirmation['changed'])) {
                    $changedIds[] = $reservationId;
                }
            }
            if (!$this->reservations->commit()) {
                throw new \RuntimeException('transaction_commit_failed');
            }
        } catch (\Throwable $error) {
            $this->reservations->rollback();
            return $this->failure($error->getMessage(), $reservationIds);
        }
        foreach ($paymentEvents as $event) {
            \do_action('must_hotel_booking/payment_recorded', $event);
        }
        foreach ($changedIds as $reservationId) {
            \do_action('must_hotel_booking/reservation_confirmed', $reservationId);
        }
        return ['success' => true, 'changed' => !empty($changedIds), 'updated' => $changedIds, 'already' => \array_values(\array_diff($reservationIds, $changedIds)), 'failed' => []];
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $candidate */
    private function paymentMatches(array $existing, array $candidate): bool
    {
        $status = \sanitize_key((string) ($candidate['status'] ?? ''));
        return \abs((float) ($existing['amount'] ?? 0.0) - (float) ($candidate['amount'] ?? 0.0)) < 0.01
            && \strtoupper((string) ($existing['currency'] ?? '')) === \strtoupper((string) ($candidate['currency'] ?? ''))
            && \sanitize_key((string) ($existing['method'] ?? '')) === 'pay_at_hotel'
            && \sanitize_key((string) ($existing['status'] ?? '')) === $status
            && ($status !== 'paid' || \trim((string) ($existing['paid_at'] ?? '')) !== '');
    }

    /** @param array<int, int> $reservationIds */
    private function failure(string $reason, array $reservationIds = []): array
    {
        return ['success' => false, 'changed' => false, 'updated' => [], 'already' => [], 'failed' => \array_values($reservationIds), 'reason_code' => \sanitize_key($reason)];
    }
}
