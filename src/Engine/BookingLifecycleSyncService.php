<?php
namespace MustHotelBooking\Engine;
use MustHotelBooking\Database\ReservationRepository;
final class BookingLifecycleSyncService
{
    /** @var ReservationRepository */
    private $reservations;
    /** @var \MustHotelBooking\Database\PaymentRepository */
    private $payments;
    /** @var \MustHotelBooking\Database\RefundRepository */
    private $refunds;
    public function __construct(
        ?ReservationRepository $reservations = null,
        ?\MustHotelBooking\Database\PaymentRepository $payments = null,
        ?\MustHotelBooking\Database\RefundRepository $refunds = null
    ) {
        $this->reservations = $reservations ?: get_reservation_repository();
        $this->payments = $payments ?: get_payment_repository();
        $this->refunds = $refunds ?: get_refund_repository();
    }
    /**
     * Apply a local lifecycle transition through the standard booking status
     * path so domain hooks, email handlers, and inventory-blocking status rules
     * are shared across website, staff, admin, retry, and provider-originated
     * changes.
     *
     * @param array<string, mixed> $context
     * @return array{success: bool, changed: bool, message: string}
     */
    public function applyReservationStatusTransition(
        int $reservationId,
        string $targetStatus,
        string $targetPaymentStatus = '',
        array $context = []
    ): array {
        $targetStatus = \sanitize_key($targetStatus);
        $targetPaymentStatus = \sanitize_key($targetPaymentStatus);
        if ($reservationId <= 0 || $targetStatus === '') {
            return [
                'success' => false,
                'changed' => false,
                'message' => \__('Reservation lifecycle transition is missing a reservation ID or target status.', 'must-hotel-booking'),
            ];
        }
        $reservation = $this->reservations->getReservation($reservationId);
        if (!\is_array($reservation)) {
            return [
                'success' => false,
                'changed' => false,
                'message' => \__('Reservation not found.', 'must-hotel-booking'),
            ];
        }
        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
        $currentPaymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));
        if ($targetStatus === 'cancelled') {
            (new CancellationFinancialCleanupService())->captureSnapshot($reservationId, $context);
            $refreshedReservation = $this->reservations->getReservation($reservationId);
            if (\is_array($refreshedReservation)) {
                $reservation = $refreshedReservation;
            }
        }
        if ($targetPaymentStatus === '') {
            $targetPaymentStatus = $currentPaymentStatus;
        }
        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $metadata['last_lifecycle_transition'] = [
            'source' => \sanitize_key((string) ($context['source'] ?? 'unknown')),
            'operation' => \sanitize_key((string) ($context['operation'] ?? 'status_transition')),
            'previous_status' => $currentStatus,
            'target_status' => $targetStatus,
            'previous_payment_status' => $currentPaymentStatus,
            'target_payment_status' => $targetPaymentStatus,
            'event_id' => \sanitize_text_field((string) ($context['event_id'] ?? '')),
            'idempotency_key' => \sanitize_text_field((string) ($context['idempotency_key'] ?? '')),
            'synced_at' => $this->now(),
        ];
        $this->reservations->updateProviderMetadata($reservationId, [
            'provider_metadata' => $metadata,
        ]);
        if ($currentStatus === $targetStatus && $currentPaymentStatus === $targetPaymentStatus) {
            if ($targetStatus === 'cancelled') {
                (new CancellationFinancialCleanupService())->markReservationCancelled($reservationId, $context);
                $this->ensureCancellationMoneyReview($reservation, $context);
            }
            return [
                'success' => true,
                'changed' => false,
                'message' => 'already_applied',
            ];
        }
        BookingStatusEngine::updateReservationStatuses(
            [$reservationId],
            $targetStatus,
            $targetPaymentStatus
        );
        $updatedReservation = $this->reservations->getReservation($reservationId);
        if (!\is_array($updatedReservation)) {
            return [
                'success' => false,
                'changed' => false,
                'message' => \__(
                    'Reservation lifecycle transition could not be verified.',
                    'must-hotel-booking'
                ),
            ];
        }
        $persistedStatus = \sanitize_key(
            (string) ($updatedReservation['status'] ?? '')
        );
        $persistedPaymentStatus = \sanitize_key(
            (string) ($updatedReservation['payment_status'] ?? '')
        );
        if (
            $persistedStatus !== $targetStatus
            || $persistedPaymentStatus !== $targetPaymentStatus
        ) {
            return [
                'success' => false,
                'changed' => false,
                'message' => \sprintf(
                    \__(
                        'Reservation lifecycle transition was not persisted. Expected %1$s/%2$s, found %3$s/%4$s.',
                        'must-hotel-booking'
                    ),
                    $targetStatus,
                    $targetPaymentStatus,
                    $persistedStatus,
                    $persistedPaymentStatus
                ),
            ];
        }
        if ($targetStatus === 'cancelled') {
            (new CancellationFinancialCleanupService())->markReservationCancelled($reservationId, $context);
            $this->ensureCancellationMoneyReview(
                $updatedReservation,
                $context
            );
        }
        return [
            'success' => true,
            'changed' => true,
            'message' => '',
        ];
    }
    /** @param array<string, mixed> $reservation @param array<string, mixed> $context */
    private function ensureCancellationMoneyReview(array $reservation, array $context): void
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        if ($reservationId <= 0) {
            return;
        }
        $source = \sanitize_key((string) ($context['source'] ?? ''));
        $operation = \sanitize_key((string) ($context['operation'] ?? ''));
        /*
         * Refund-after-provider-success flows create their own refund records.
         * This review row is only for cancellation-first flows where money may
         * still be held after the reservation has become non-blocking.
         */
        if (\in_array($operation, ['refund_cancel', 'refund_and_cancel'], true)) {
            return;
        }
        $paymentRows = $this->payments->getPaymentsForReservation($reservationId);
        $state = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
        $snapshot = $this->cancellationSnapshot($reservation);
        $method = \sanitize_key((string) ($snapshot['payment_method'] ?? ($state['method'] ?? '')));
        $amountPaid = isset($snapshot['paid_amount'])
            ? (float) $snapshot['paid_amount']
            : (float) ($state['amount_paid'] ?? 0.0);
        if ($amountPaid <= 0.0 || !\in_array($method, ['stripe', 'pokpay'], true)) {
            return;
        }
        if ($this->hasBlockingCancellationRefundState($reservationId)) {
            return;
        }
        $payment = $this->latestPaidPaymentRow($paymentRows, $method);
        $transactionId = \sanitize_text_field((string) ($payment['transaction_id'] ?? ''));
        $paymentId = isset($payment['id']) ? (int) $payment['id'] : 0;
        $feeService = new PaymentProviderFeeService();
        if (!empty($snapshot)) {
            $penaltyDetails = isset($snapshot['cancellation_policy']) && \is_array($snapshot['cancellation_policy'])
                ? $snapshot['cancellation_policy']
                : [];
            $breakdown = [
                'success' => (string) ($snapshot['provider_fee_status'] ?? 'unknown') === 'known' || (float) ($snapshot['provider_fee_retained'] ?? 0.0) > 0.0,
                'provider_fee_status' => \sanitize_key((string) ($snapshot['provider_fee_status'] ?? 'unknown')),
                'original_paid_amount' => \round($amountPaid, 2),
                'provider_fee_retained' => \round((float) ($snapshot['provider_fee_retained'] ?? 0.0), 2),
                'cancellation_fee_amount' => \round((float) ($snapshot['cancellation_fee_amount'] ?? 0.0), 2),
                'final_refund_amount' => \round((float) ($snapshot['refundable_amount'] ?? 0.0), 2),
                'refund_policy_reason' => \sanitize_text_field((string) ($snapshot['refund_policy_reason'] ?? $this->reviewPolicyReason($penaltyDetails))),
                'calculated_by' => \sanitize_key((string) ($snapshot['calculated_by'] ?? ($source !== '' ? $source : 'system'))),
            ];
        } else {
            $penaltyDetails = CancellationEngine::getPenaltyDetails($reservationId, $this->now());
            $penaltyAmount = isset($penaltyDetails['penalty_amount']) ? (float) $penaltyDetails['penalty_amount'] : 0.0;
            $breakdown = $feeService->calculateDefaultRefundBreakdown(
                $paymentRows,
                $amountPaid,
                $source !== '' ? $source : 'system',
                $penaltyAmount,
                $this->reviewPolicyReason($penaltyDetails)
            );
        }
        $feeKnown = (string) ($breakdown['provider_fee_status'] ?? 'unknown') === 'known';
        $idempotencyKey = 'refund-review-cancel-' . $reservationId . '-' . \sha1($transactionId . '|' . $amountPaid);
        $metadata = [
            'source' => $source,
            'operation' => $operation !== '' ? $operation : 'cancel_only',
            'event_id' => \sanitize_text_field((string) ($context['event_id'] ?? '')),
            'trigger_idempotency_key' => \sanitize_text_field((string) ($context['idempotency_key'] ?? '')),
            'provider_fee_status' => (string) ($breakdown['provider_fee_status'] ?? 'unknown'),
            'penalty' => $penaltyDetails,
            'decision_required' => true,
        ];
        $this->refunds->createRefund([
            'reservation_id' => $reservationId,
            'booking_id' => (string) ($reservation['booking_id'] ?? ''),
            'payment_id' => $paymentId,
            'provider' => (string) ($reservation['provider'] ?? ''),
            'clock_booking_id' => (string) ($reservation['provider_booking_id'] ?? ''),
            'clock_reservation_id' => (string) ($reservation['provider_reservation_id'] ?? ''),
            'gateway' => $method,
            'provider_payment_reference' => $transactionId,
            'raw_provider_status' => 'review_required',
            'amount' => $feeKnown ? (float) ($breakdown['final_refund_amount'] ?? 0.0) : 0.0,
            'currency' => \strtoupper((string) ($snapshot['currency'] ?? ($payment['currency'] ?? \MustHotelBooking\Core\MustBookingConfig::get_currency()))),
        ] + $feeService->refundBreakdownData($breakdown) + [
            'reason' => \__('Reservation was cancelled before an automatic refund decision was made.', 'must-hotel-booking'),
            'refund_type' => 'clock_cancellation_review',
            'status' => 'refund_review_required',
            'clock_sync_status' => 'not_required',
            'requested_by_user_id' => 0,
            'idempotency_key' => $idempotencyKey,
            'failed_reason' => $feeKnown
                ? \__('Staff must choose full refund, partial refund, or no refund for this cancelled paid booking.', 'must-hotel-booking')
                : \__('Provider fee or refund inputs are unknown. Staff must review before issuing any refund.', 'must-hotel-booking'),
            'manual_note' => $this->reviewManualNote($source),
            'metadata' => \wp_json_encode($metadata),
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }
    private function hasBlockingCancellationRefundState(int $reservationId): bool
    {
        foreach ($this->refunds->getRefundsForReservation($reservationId) as $refund) {
            if (!\is_array($refund)) {
                continue;
            }
            $status = \sanitize_key((string) ($refund['status'] ?? ''));
            $refundType = \sanitize_key((string) ($refund['refund_type'] ?? ''));
            if ($refundType === 'clock_cancellation_review' && $status === 'refund_review_required') {
                return true;
            }
            if (\in_array($status, ['pending', 'processing', 'succeeded', 'completed', 'manual_pending', 'manual_completed'], true)) {
                return true;
            }
        }
        return false;
    }
    /** @param array<int, array<string, mixed>> $paymentRows @return array<string, mixed> */
    private function latestPaidPaymentRow(array $paymentRows, string $method): array
    {
        foreach ($paymentRows as $row) {
            if (
                \is_array($row)
                && \sanitize_key((string) ($row['method'] ?? '')) === $method
                && \sanitize_key((string) ($row['status'] ?? '')) === 'paid'
            ) {
                return $row;
            }
        }
        return [];
    }
    /** @param array<string, mixed> $penaltyDetails */
    private function reviewPolicyReason(array $penaltyDetails): string
    {
        $policyName = \trim((string) ($penaltyDetails['policy_name'] ?? ''));
        if (!empty($penaltyDetails['penalty_applied'])) {
            return $policyName !== ''
                ? \sprintf(
                    \__('Clock-originated cancellation requires staff review. Cancellation policy %s applies a penalty.', 'must-hotel-booking'),
                    $policyName
                )
                : \__('Clock-originated cancellation requires staff review. A cancellation penalty applies.', 'must-hotel-booking');
        }
        return $policyName !== ''
            ? \sprintf(
                \__('Clock-originated cancellation requires staff review. Cancellation policy checked: %s.', 'must-hotel-booking'),
                $policyName
            )
            : \__('Clock-originated cancellation requires staff review before any refund is issued.', 'must-hotel-booking');
    }
    private function reviewManualNote(string $source): string
    {
        if (\in_array($source, ['clock_webhook', 'clock_refresh', 'clock_sync'], true)) {
            return \__('Clock cancelled this paid booking. The website reservation is cancelled, but the payment still needs a staff refund decision.', 'must-hotel-booking');
        }
        return \__('Paid booking was cancelled without a completed refund. Staff must review held funds.', 'must-hotel-booking');
    }
    /** @param array<string, mixed> $reservation @return array<string, mixed> */
    private function cancellationSnapshot(array $reservation): array
    {
        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $cleanup = isset($metadata['cancellation_financial_cleanup']) && \is_array($metadata['cancellation_financial_cleanup'])
            ? $metadata['cancellation_financial_cleanup']
            : [];
        $snapshot = isset($cleanup['snapshot']) && \is_array($cleanup['snapshot'])
            ? $cleanup['snapshot']
            : [];

        return $snapshot;
    }
    /** @param mixed $metadata @return array<string, mixed> */
    private function decodeMetadata($metadata): array
    {
        if (\is_array($metadata)) {
            return $metadata;
        }
        if (!\is_string($metadata) || \trim($metadata) === '') {
            return [];
        }
        $decoded = \json_decode($metadata, true);
        return \is_array($decoded) ? $decoded : [];
    }
    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
