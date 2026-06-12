<?php
namespace MustHotelBooking\Engine;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Database\PaymentRepository;
use MustHotelBooking\Database\RefundRepository;
use MustHotelBooking\Database\ReservationRepository;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\ProviderReservationView;
final class PaymentRefundService
{
    /** @var ReservationRepository */
    private $reservations;
    /** @var PaymentRepository */
    private $payments;
    /** @var RefundRepository */
    private $refunds;
    public function __construct(?ReservationRepository $reservations = null, ?PaymentRepository $payments = null, ?RefundRepository $refunds = null)
    {
        $this->reservations = $reservations ?: get_reservation_repository();
        $this->payments = $payments ?: get_payment_repository();
        $this->refunds = $refunds ?: get_refund_repository();
    }
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function requestRefund(int $reservationId, float $amount, array $options = []): array
    {
        $reservation = $this->reservations->getReservation($reservationId);
        if (!\is_array($reservation)) {
            return $this->failure(\__('Reservation not found.', 'must-hotel-booking'));
        }
        $paymentRows = $this->payments->getPaymentsForReservation($reservationId);
        $state = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
        $amount = \round(\max(0.0, $amount), 2);
        $amountPaid = (float) ($state['amount_paid'] ?? 0.0);
        $method = \sanitize_key((string) ($state['method'] ?? ''));
        $transactionId = $this->latestPaidTransactionId($paymentRows);
        $currency = \strtoupper(\sanitize_text_field((string) ($options['currency'] ?? MustBookingConfig::get_currency())));
        $refundType = \sanitize_key((string) ($options['refund_type'] ?? 'refund_only')) ?: 'refund_only';
        $reason = \sanitize_text_field((string) ($options['reason'] ?? ''));
        $cancelAfterRefund = !empty($options['cancel_reservation']);
        $feeService = new PaymentProviderFeeService($this->payments);
        $breakdown = isset($options['refund_breakdown']) && \is_array($options['refund_breakdown'])
            ? $options['refund_breakdown']
            : $feeService->calculateDefaultRefundBreakdown(
                $paymentRows,
                $amountPaid,
                (string) ($options['calculated_by'] ?? $options['source'] ?? 'admin'),
                0.0,
                \__('Admin refund amount uses stored provider fee snapshot as the default.', 'must-hotel-booking')
            );
        $breakdown['final_refund_amount'] = $amount;
        if ($amount <= 0.0) {
            return $this->failure(\__('Refund amount must be greater than zero.', 'must-hotel-booking'));
        }
        if ($amountPaid <= 0.0) {
            return $this->failure(\__('There is no recorded paid balance available to refund.', 'must-hotel-booking'));
        }
        if ($amount > $amountPaid) {
            return $this->failure(\__('Refund amount cannot exceed the remaining refundable amount.', 'must-hotel-booking'));
        }
        if (!\in_array($method, ['stripe', 'pokpay'], true)) {
            return $this->failure(\__('Refunds for this payment method must be handled outside the plugin.', 'must-hotel-booking'));
        }
        if ($transactionId === '') {
            return $this->failure(\__('This online payment is missing its provider reference.', 'must-hotel-booking'));
        }
        if (!empty($options['require_known_provider_fee']) && (string) ($breakdown['provider_fee_status'] ?? 'unknown') !== 'known') {
            return $this->failure(\__('The provider fee is not known yet. Review the payment fee capture before issuing the default refund.', 'must-hotel-booking'));
        }
        if ($method === 'pokpay') {
            return $this->requestPokPayRefund($reservation, $paymentRows, $state, $amount, [
                'currency' => $currency,
                'refund_type' => $refundType,
                'reason' => $reason,
                'cancel_reservation' => $cancelAfterRefund,
                'source' => (string) ($options['source'] ?? 'admin'),
                'transaction_id' => $transactionId,
                'refund_breakdown' => $breakdown,
            ]);
        }
        $now = \current_time('mysql');
        $paymentId = $this->latestPaymentId($paymentRows, $transactionId);
        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $folioId = $this->firstString($metadata, ['clock_folio_id', 'folio_id', 'default_folio_id']);
        $providerResponse = isset($metadata['provider_response']) && \is_array($metadata['provider_response']) ? $metadata['provider_response'] : [];
        if ($folioId === '') {
            $folioId = $this->firstString($providerResponse, ['folio_id', 'default_folio_id']);
        }
        if ($folioId === '' && ProviderReservationView::isProviderBacked($reservation)) {
            $folioRecovery = $this->recoverClockFolioIdForReservation($reservation);
            if (!empty($folioRecovery['success']) && (string) ($folioRecovery['folio_id'] ?? '') !== '') {
                $folioId = (string) $folioRecovery['folio_id'];
            }
        }
        $refundId = $this->refunds->createRefund([
            'reservation_id' => $reservationId,
            'booking_id' => (string) ($reservation['booking_id'] ?? ''),
            'payment_id' => $paymentId,
            'provider' => (string) ($reservation['provider'] ?? ''),
            'clock_booking_id' => (string) ($reservation['provider_booking_id'] ?? ''),
            'clock_reservation_id' => (string) ($reservation['provider_reservation_id'] ?? ''),
            'clock_folio_id' => $folioId,
            'gateway' => 'stripe',
            'provider_payment_reference' => $transactionId,
            'provider_refund_id' => '',
            'provider_refund_reference' => '',
            'raw_provider_status' => 'pending',
            'stripe_payment_intent_id' => $transactionId,
            'amount' => $amount,
            'currency' => $currency,
            ] + $feeService->refundBreakdownData($breakdown) + [
            'reason' => $reason,
            'refund_type' => $refundType,
            'status' => 'pending',
            'clock_sync_status' => ProviderReservationView::isProviderBacked($reservation) ? 'pending' : 'not_required',
            'requested_by_user_id' => \get_current_user_id(),
            'metadata' => \wp_json_encode([
                'cancel_reservation' => $cancelAfterRefund,
                'source' => (string) ($options['source'] ?? 'admin'),
                'provider_fee_status' => (string) ($breakdown['provider_fee_status'] ?? 'unknown'),
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($refundId <= 0) {
            return $this->failure(\__('Unable to create the refund record.', 'must-hotel-booking'));
        }
        $stripeIdempotencyKey = 'soves_refund_' . $refundId;
        $clockIdempotencyKey = 'SOVES-REFUND-' . $refundId;
        $this->refunds->updateRefund($refundId, [
            'idempotency_key' => $stripeIdempotencyKey,
            'clock_idempotency_key' => $clockIdempotencyKey,
            'updated_at' => $now,
        ]);
        $stripePayload = [
            'payment_intent' => $transactionId,
            'amount' => PaymentEngine::convertAmountToStripeMinorUnits($amount, $currency),
            'metadata[soves_booking_id]' => (string) ($reservation['booking_id'] ?? ''),
            'metadata[soves_payment_id]' => (string) $paymentId,
            'metadata[soves_refund_id]' => (string) $refundId,
            'metadata[clock_booking_id]' => (string) ($reservation['provider_booking_id'] ?? ''),
            'metadata[clock_folio_id]' => $folioId,
        ];
        if ($reason !== '') {
            $stripePayload['metadata[refund_reason]'] = $reason;
        }
        $response = PaymentEngine::performStripeApiRequest('POST', 'refunds', $stripePayload, [
            'idempotency_key' => $stripeIdempotencyKey,
        ]);
        if (empty($response['success'])) {
            $message = isset($response['message']) && (string) $response['message'] !== ''
                ? (string) $response['message']
                : \__('Unable to create the Stripe refund.', 'must-hotel-booking');
            $this->refunds->updateRefund($refundId, [
                'status' => 'failed',
                'clock_sync_status' => 'not_required',
                'failed_reason' => $message,
                'raw_provider_status' => 'failed',
                'updated_at' => \current_time('mysql'),
            ]);
            return $this->failure($message, ['refund_id' => $refundId]);
        }
        $refund = isset($response['body']) && \is_array($response['body']) ? $response['body'] : [];
        $stripeRefundId = isset($refund['id']) ? (string) $refund['id'] : '';
        $stripeStatus = $this->normalizeStripeRefundStatus((string) ($refund['status'] ?? 'processing'));
        $stripeChargeId = isset($refund['charge']) ? (string) $refund['charge'] : '';
        $this->refunds->updateRefund($refundId, [
            'stripe_refund_id' => $stripeRefundId,
            'stripe_charge_id' => $stripeChargeId,
            'provider_refund_id' => $stripeRefundId,
            'provider_refund_reference' => $stripeRefundId,
            'status' => $stripeStatus,
            'raw_provider_status' => $stripeStatus,
            'completed_at' => $stripeStatus === 'succeeded' ? \current_time('mysql') : null,
            'updated_at' => \current_time('mysql'),
        ]);
        if ($stripeStatus === 'succeeded') {
            $this->recordLocalRefundLedger($reservation, $state, $amount, $stripeRefundId !== '' ? $stripeRefundId : $transactionId, 'stripe');
            if ($cancelAfterRefund) {
                $this->cancelReservationAfterRefund($reservation);
            }
            $this->syncClockRefund($refundId);
        }
        return [
            'success' => true,
            'notice' => 'payment_refunded',
            'refund_id' => $refundId,
            'stripe_refund_id' => $stripeRefundId,
            'status' => $stripeStatus,
        ];
    }
    /** @param array<string, mixed> $event */
    public function handleStripeWebhookEvent(array $event): bool
    {
        $type = isset($event['type']) ? (string) $event['type'] : '';
        $object = isset($event['data']['object']) && \is_array($event['data']['object']) ? $event['data']['object'] : [];
        if (!\in_array($type, ['refund.created', 'refund.updated', 'charge.refunded'], true)) {
            return false;
        }
        if ($type === 'charge.refunded') {
            $refunds = isset($object['refunds']['data']) && \is_array($object['refunds']['data']) ? $object['refunds']['data'] : [];
            foreach ($refunds as $refundObject) {
                if (\is_array($refundObject)) {
                    $this->handleStripeRefundObject($refundObject, (string) ($object['payment_intent'] ?? ''), (string) ($object['id'] ?? ''));
                }
            }
            return true;
        }
        $this->handleStripeRefundObject($object, (string) ($object['payment_intent'] ?? ''), (string) ($object['charge'] ?? ''));
        return true;
    }
    public function retryClockSync(int $refundId): array
    {
        return $this->syncClockRefund($refundId);
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<int, array<string, mixed>> $paymentRows
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestPokPayRefund(array $reservation, array $paymentRows, array $state, float $amount, array $options): array
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $transactionId = \sanitize_text_field((string) ($options['transaction_id'] ?? ''));
        $currency = \strtoupper(\sanitize_text_field((string) ($options['currency'] ?? MustBookingConfig::get_currency())));
        $refundType = \sanitize_key((string) ($options['refund_type'] ?? 'refund_only')) ?: 'refund_only';
        $reason = \sanitize_text_field((string) ($options['reason'] ?? ''));
        $cancelAfterRefund = !empty($options['cancel_reservation']);
        $amountPaid = (float) ($state['amount_paid'] ?? 0.0);
        $isFullRefund = \abs($amountPaid - $amount) < 0.01;
        $feeService = new PaymentProviderFeeService($this->payments);
        $breakdown = isset($options['refund_breakdown']) && \is_array($options['refund_breakdown'])
            ? $options['refund_breakdown']
            : $feeService->calculateDefaultRefundBreakdown(
                $paymentRows,
                $amountPaid,
                (string) ($options['calculated_by'] ?? $options['source'] ?? 'admin'),
                0.0,
                \__('PokPay refund amount uses stored provider fee snapshot as the default.', 'must-hotel-booking')
            );
        $breakdown['final_refund_amount'] = $amount;

        $blockingRefund = $this->refunds->findBlockingProviderRefund($reservationId, 'pokpay', $transactionId, $amount);
        if (\is_array($blockingRefund)) {
            return $this->failure(\__('A PokPay refund for this payment and amount is already recorded or in progress.', 'must-hotel-booking'), [
                'refund_id' => (int) ($blockingRefund['id'] ?? 0),
                'status' => (string) ($blockingRefund['status'] ?? ''),
            ]);
        }

        $now = \current_time('mysql');
        $paymentId = $this->latestPaymentId($paymentRows, $transactionId);
        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $folioId = $this->firstString($metadata, ['clock_folio_id', 'folio_id', 'default_folio_id']);
        $providerResponse = isset($metadata['provider_response']) && \is_array($metadata['provider_response']) ? $metadata['provider_response'] : [];
        if ($folioId === '') {
            $folioId = $this->firstString($providerResponse, ['folio_id', 'default_folio_id']);
        }
        if ($folioId === '' && ProviderReservationView::isProviderBacked($reservation)) {
            $folioRecovery = $this->recoverClockFolioIdForReservation($reservation);
            if (!empty($folioRecovery['success']) && (string) ($folioRecovery['folio_id'] ?? '') !== '') {
                $folioId = (string) $folioRecovery['folio_id'];
            }
        }

        $refundId = $this->refunds->createRefund([
            'reservation_id' => $reservationId,
            'booking_id' => (string) ($reservation['booking_id'] ?? ''),
            'payment_id' => $paymentId,
            'provider' => (string) ($reservation['provider'] ?? ''),
            'clock_booking_id' => (string) ($reservation['provider_booking_id'] ?? ''),
            'clock_reservation_id' => (string) ($reservation['provider_reservation_id'] ?? ''),
            'clock_folio_id' => $folioId,
            'gateway' => 'pokpay',
            'provider_payment_reference' => $transactionId,
            'provider_refund_id' => '',
            'provider_refund_reference' => '',
            'raw_provider_status' => 'processing',
            'amount' => $amount,
            'currency' => $currency,
            ] + $feeService->refundBreakdownData($breakdown) + [
            'reason' => $reason,
            'refund_type' => $refundType,
            'status' => 'processing',
            'clock_sync_status' => ProviderReservationView::isProviderBacked($reservation) ? 'pending' : 'not_required',
            'requested_by_user_id' => \get_current_user_id(),
            'idempotency_key' => 'pokpay_refund_' . $reservationId . '_' . \md5($transactionId . '|' . \number_format($amount, 2, '.', '')),
            'clock_idempotency_key' => 'SOVES-REFUND-POKPAY-' . $reservationId . '-' . \time(),
            'metadata' => \wp_json_encode([
                'cancel_reservation' => $cancelAfterRefund,
                'source' => (string) ($options['source'] ?? 'admin'),
                'full_refund' => $isFullRefund,
                'amount_minor' => PaymentEngine::convertAmountToMinorUnits($amount, $currency),
                'provider_fee_status' => (string) ($breakdown['provider_fee_status'] ?? 'unknown'),
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($refundId <= 0) {
            return $this->failure(\__('Unable to create the refund record.', 'must-hotel-booking'));
        }

        $this->refunds->updateRefund($refundId, [
            'clock_idempotency_key' => 'SOVES-REFUND-' . $refundId,
            'updated_at' => $now,
        ]);

        $response = PaymentEngine::refundPokPaySdkOrder($transactionId, $amount, $currency, $reason, $isFullRefund);

        if (empty($response['success'])) {
            $message = $this->safeProviderFailureMessage(
                isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('Unable to create the PokPay refund.', 'must-hotel-booking')
            );
            $this->refunds->updateRefund($refundId, [
                'status' => 'manual_pending',
                'clock_sync_status' => ProviderReservationView::isProviderBacked($reservation) ? 'pending' : 'not_required',
                'failed_reason' => $message,
                'manual_note' => \__('PokPay API refund failed or is unavailable. Refund from the POK dashboard, then mark this refund completed in the plugin.', 'must-hotel-booking'),
                'raw_provider_status' => 'manual_pending',
                'updated_at' => \current_time('mysql'),
            ]);

            return [
                'success' => true,
                'notice' => 'pokpay_refund_manual_pending',
                'refund_id' => $refundId,
                'status' => 'manual_pending',
                'message' => $message,
            ];
        }

        $providerRefundId = \sanitize_text_field((string) ($response['provider_refund_id'] ?? ''));
        if ($providerRefundId === '') {
            $providerRefundId = $transactionId;
        }
        $rawStatus = \sanitize_key((string) ($response['status'] ?? 'succeeded')) ?: 'succeeded';

        $this->refunds->updateRefund($refundId, [
            'provider_refund_id' => $providerRefundId,
            'provider_refund_reference' => $providerRefundId,
            'status' => 'succeeded',
            'raw_provider_status' => $rawStatus,
            'completed_at' => \current_time('mysql'),
            'updated_at' => \current_time('mysql'),
        ]);

        $this->recordLocalRefundLedger($reservation, $state, $amount, $providerRefundId, 'pokpay');

        if ($cancelAfterRefund) {
            $this->cancelReservationAfterRefund($reservation);
        }

        $this->syncClockRefund($refundId);

        return [
            'success' => true,
            'notice' => 'payment_refunded',
            'refund_id' => $refundId,
            'provider_refund_id' => $providerRefundId,
            'status' => 'succeeded',
        ];
    }
    /** @return array<string, mixed> */
    public function markClockSyncHandledManually(int $refundId): array
    {
        $refund = $this->refunds->getRefund($refundId);
        if (!\is_array($refund)) {
            return $this->failure(\__('Refund record not found.', 'must-hotel-booking'));
        }
        $updated = $this->refunds->updateRefund($refundId, [
            'clock_sync_status' => 'manual_done',
            'clock_refund_item_id' => (string) ($refund['clock_refund_item_id'] ?? '') !== '' ? (string) $refund['clock_refund_item_id'] : 'manual:SOVES-REFUND-' . $refundId,
            'failed_reason' => '',
            'updated_at' => \current_time('mysql'),
        ]);
        return [
            'success' => $updated,
            'message' => $updated ? '' : \__('Unable to update refund manual-review status.', 'must-hotel-booking'),
        ];
    }

    /** @return array<string, mixed> */
    public function completeManualRefund(int $refundId, string $manualNote = ''): array
    {
        $refund = $this->refunds->getRefund($refundId);
        if (!\is_array($refund)) {
            return $this->failure(\__('Refund record not found.', 'must-hotel-booking'));
        }

        $status = \sanitize_key((string) ($refund['status'] ?? ''));
        if ($status === 'manual_completed' || $status === 'succeeded') {
            return $this->failure(\__('This refund has already been completed.', 'must-hotel-booking'));
        }

        if ($status !== 'manual_pending') {
            return $this->failure(\__('Only manual pending refunds can be completed manually.', 'must-hotel-booking'));
        }

        $reservation = $this->reservations->getReservation((int) ($refund['reservation_id'] ?? 0));
        if (!\is_array($reservation)) {
            return $this->failure(\__('Reservation not found.', 'must-hotel-booking'));
        }

        $paymentRows = $this->payments->getPaymentsForReservation((int) ($refund['reservation_id'] ?? 0));
        $state = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
        $gateway = $this->refundGateway($refund);
        $reference = (string) ($refund['provider_refund_reference'] ?? '');
        if ($reference === '') {
            $reference = (string) ($refund['provider_payment_reference'] ?? '');
        }

        $this->recordLocalRefundLedger($reservation, $state, (float) ($refund['amount'] ?? 0.0), $reference, $gateway !== '' ? $gateway : 'pokpay');

        $metadata = $this->decodeMetadata($refund['metadata'] ?? null);
        if (!empty($metadata['cancel_reservation'])) {
            $this->cancelReservationAfterRefund($reservation);
        }

        $updated = $this->refunds->updateRefund($refundId, [
            'status' => 'manual_completed',
            'completed_at' => \current_time('mysql'),
            'manual_completed_at' => \current_time('mysql'),
            'manual_note' => \sanitize_textarea_field($manualNote),
            'updated_at' => \current_time('mysql'),
        ]);

        if (ProviderReservationView::isProviderBacked($reservation)) {
            $this->syncClockRefund($refundId);
        }

        return [
            'success' => $updated,
            'notice' => 'refund_manual_completed',
            'message' => $updated ? '' : \__('Unable to complete the manual refund.', 'must-hotel-booking'),
        ];
    }
    /** @return array<string, mixed> */
    private function syncClockRefund(int $refundId): array
    {
        $refund = $this->refunds->getRefund($refundId);
        if (!\is_array($refund)) {
            return $this->failure(\__('Refund record not found.', 'must-hotel-booking'));
        }
        if ((string) ($refund['clock_sync_status'] ?? '') === 'not_required') {
            return ['success' => true, 'message' => 'not_required'];
        }
        if ((string) ($refund['clock_refund_item_id'] ?? '') !== '') {
            return ['success' => true, 'message' => 'already_synced'];
        }
        if (!\class_exists(\MustHotelBooking\Provider\Clock\ClockPaymentAccountingService::class)) {
            return $this->failure(\__('Clock payment accounting service is unavailable.', 'must-hotel-booking'));
        }
        $result = (new \MustHotelBooking\Provider\Clock\ClockPaymentAccountingService())->syncRefund($refundId);
        return $result;
    }
    /** @param array<string, mixed> $refundObject */
    private function handleStripeRefundObject(array $refundObject, string $paymentIntentId, string $chargeId): void
    {
        $stripeRefundId = isset($refundObject['id']) ? (string) $refundObject['id'] : '';
        $refund = $this->refunds->findByStripeRefundId($stripeRefundId);
        if (!\is_array($refund)) {
            $refund = $this->refunds->findOpenByPaymentReference($paymentIntentId, $chargeId);
        }
        if (!\is_array($refund)) {
            return;
        }
        $status = $this->normalizeStripeRefundStatus((string) ($refundObject['status'] ?? 'processing'));
        $this->refunds->updateRefund((int) $refund['id'], [
            'stripe_refund_id' => $stripeRefundId !== '' ? $stripeRefundId : (string) ($refund['stripe_refund_id'] ?? ''),
            'stripe_charge_id' => $chargeId !== '' ? $chargeId : (string) ($refund['stripe_charge_id'] ?? ''),
            'status' => $status,
            'completed_at' => $status === 'succeeded' ? \current_time('mysql') : ($refund['completed_at'] ?? null),
            'updated_at' => \current_time('mysql'),
        ]);
        if ($status === 'succeeded') {
            $reservation = $this->reservations->getReservation((int) ($refund['reservation_id'] ?? 0));
            if (\is_array($reservation)) {
                $state = PaymentStatusService::buildReservationPaymentState(
                    $reservation,
                    $this->payments->getPaymentsForReservation((int) ($refund['reservation_id'] ?? 0))
                );
                $this->recordLocalRefundLedger($reservation, $state, (float) ($refund['amount'] ?? 0.0), (string) ($refund['stripe_refund_id'] ?? ''), 'stripe');
            }
            $this->syncClockRefund((int) $refund['id']);
        }
    }
    /** @param array<string, mixed> $reservation @param array<string, mixed> $state */
    private function recordLocalRefundLedger(array $reservation, array $state, float $amount, string $transactionId, string $method = 'stripe'): void
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $method = \sanitize_key($method) ?: 'stripe';
        if ($reservationId <= 0 || $amount <= 0.0) {
            return;
        }
        $existingRows = $this->payments->getPaymentsForReservation($reservationId);
        foreach ($existingRows as $row) {
            if ((string) ($row['status'] ?? '') === 'refunded' && (string) ($row['transaction_id'] ?? '') === $transactionId && \abs((float) ($row['amount'] ?? 0.0) - $amount) < 0.01) {
                return;
            }
        }
        $now = \current_time('mysql');
        $this->payments->createPayment([
                'reservation_id' => $reservationId,
                'amount' => \round($amount, 2),
                'currency' => MustBookingConfig::get_currency(),
                'method' => $method,
                'status' => 'refunded',
                'transaction_id' => $transactionId,
            'paid_at' => $now,
            'created_at' => $now,
        ]);
        $amountPaid = \max(0.0, (float) ($state['amount_paid'] ?? 0.0) - $amount);
        $amountDue = \max(0.0, (float) ($state['total'] ?? 0.0) - $amountPaid);
        $paymentStatus = $amountPaid <= 0.0 ? 'refunded' : ($amountDue > 0.0 ? 'partially_paid' : 'paid');
        $this->reservations->updateReservationStatus($reservationId, (string) ($reservation['status'] ?? 'confirmed'), $paymentStatus);
    }
    /** @param array<string, mixed> $reservation */
    private function cancelReservationAfterRefund(array $reservation): void
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        if ($reservationId <= 0) {
            return;
        }
        if (ProviderReservationView::isProviderBacked($reservation) && \class_exists(\MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService::class)) {
            (new \MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService())->cancelReservation($reservationId, 'refund_cancel');
            return;
        }
        $current = $this->reservations->getReservation($reservationId);
        $this->reservations->updateReservationStatus(
            $reservationId,
            'cancelled',
            \is_array($current) ? (string) ($current['payment_status'] ?? 'refunded') : 'refunded'
        );
    }
    /** @param array<int, array<string, mixed>> $paymentRows */
    private function latestPaymentId(array $paymentRows, string $transactionId): int
    {
        foreach ($paymentRows as $row) {
            if ((string) ($row['status'] ?? '') === 'paid' && (string) ($row['transaction_id'] ?? '') === $transactionId) {
                return (int) ($row['id'] ?? 0);
            }
        }
        return 0;
    }
    /** @param array<int, array<string, mixed>> $paymentRows */
    private function latestPaidTransactionId(array $paymentRows): string
    {
        foreach ($paymentRows as $row) {
            if ((string) ($row['status'] ?? '') === 'paid' && (string) ($row['transaction_id'] ?? '') !== '') {
                return \sanitize_text_field((string) $row['transaction_id']);
            }
        }
        return '';
    }
    private function normalizeStripeRefundStatus(string $status): string
    {
        $status = \sanitize_key($status);
        if (\in_array($status, ['succeeded', 'failed', 'canceled', 'cancelled'], true)) {
            return $status === 'cancelled' ? 'canceled' : $status;
        }
        return 'processing';
    }

    /** @param array<string, mixed> $refund */
    private function refundGateway(array $refund): string
    {
        $gateway = \sanitize_key((string) ($refund['gateway'] ?? ''));

        if ($gateway !== '') {
            return $gateway;
        }

        if ((string) ($refund['stripe_payment_intent_id'] ?? '') !== '' || (string) ($refund['stripe_refund_id'] ?? '') !== '') {
            return 'stripe';
        }

        return \sanitize_key((string) ($refund['provider'] ?? ''));
    }

    private function safeProviderFailureMessage(string $message): string
    {
        $message = \sanitize_text_field($message);

        if ($message === '') {
            return \__('The payment provider could not process the refund.', 'must-hotel-booking');
        }

        return \substr($message, 0, 300);
    }

    /** @param mixed $value @return array<string, mixed> */
    private function decodeMetadata($value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        $decoded = \json_decode((string) $value, true);
        return \is_array($decoded) ? $decoded : [];
    }
    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \trim((string) $source[$key]) !== '') {
                return \sanitize_text_field((string) $source[$key]);
            }
        }
        return '';
    }
    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function failure(string $message, array $extra = []): array
    {
        return $extra + [
            'success' => false,
            'message' => $message,
        ];
    }
    /** @param array<string, mixed> $reservation @return array<string, mixed> */
    private function recoverClockFolioIdForReservation(array $reservation): array
    {
        if (!ProviderReservationView::isProviderBacked($reservation)) {
            return [
                'success' => false,
                'message' => 'not_provider_backed',
                'folio_id' => '',
            ];
        }
        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $existing = $this->findClockFolioIdInArray($metadata);
        if ((string) ($existing['folio_id'] ?? '') !== '') {
            return [
                'success' => true,
                'message' => 'already_available',
                'folio_id' => (string) $existing['folio_id'],
                'source' => (string) ($existing['source'] ?? ''),
            ];
        }
        $externalId = \sanitize_text_field((string) ($reservation['provider_booking_id'] ?? ''));
        if ($externalId === '') {
            $externalId = \sanitize_text_field((string) ($reservation['provider_reservation_id'] ?? ''));
        }
        if ($externalId === '') {
            return [
                'success' => false,
                'message' => 'missing_clock_booking_id',
                'folio_id' => '',
            ];
        }
        if (
            !\class_exists(\MustHotelBooking\Provider\Clock\ClockApiClient::class)
            || !\class_exists(\MustHotelBooking\Provider\Clock\ClockConfig::class)
        ) {
            return [
                'success' => false,
                'message' => 'clock_client_unavailable',
                'folio_id' => '',
            ];
        }
        $path = \MustHotelBooking\Provider\Clock\ClockConfig::reservationFetchPath();
        if ($path === '') {
            $path = '/bookings/{booking_id}';
        }
        $path = \str_replace(
            ['{booking_id}', '{reservation_id}', '{id}', '{clock_booking_id}'],
            \rawurlencode($externalId),
            $path
        );
        $response = (new \MustHotelBooking\Provider\Clock\ClockApiClient())->request(
            'GET',
            $path,
            [
                'api_type' => 'pms_api',
                'reservation_id' => (int) ($reservation['id'] ?? 0),
                'external_id' => $externalId,
            ],
            'clock.reservation_folio_recovery'
        );
        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== ''
                ? $response->getErrorMessage()
                : \__('Unable to recover Clock folio ID from Clock booking.', 'must-hotel-booking');
            return [
                'success' => false,
                'message' => $message,
                'folio_id' => '',
            ];
        }
        $data = $response->getData();
        $found = $this->findClockFolioIdInArray(\is_array($data) ? $data : []);
        if ((string) ($found['folio_id'] ?? '') === '') {
            return [
                'success' => false,
                'message' => 'clock_booking_response_missing_folio_id',
                'folio_id' => '',
            ];
        }
        $folioId = \sanitize_text_field((string) $found['folio_id']);
        $metadata['clock_folio_id'] = $folioId;
        $metadata['clock_folio_id_source'] = (string) ($found['source'] ?? 'clock_booking_fetch');
        $metadata['clock_folio_id_recovered_at'] = \current_time('mysql');
        $this->updateReservationProviderMetadata((int) ($reservation['id'] ?? 0), $metadata);
        return [
            'success' => true,
            'message' => 'recovered',
            'folio_id' => $folioId,
            'source' => (string) ($found['source'] ?? ''),
        ];
    }
    /** @param array<string, mixed> $source @return array<string, string> */
    private function findClockFolioIdInArray(array $source): array
    {
        foreach (['clock_folio_id', 'folio_id', 'default_folio_id'] as $key) {
            if (isset($source[$key]) && \trim((string) $source[$key]) !== '') {
                return [
                    'folio_id' => \sanitize_text_field((string) $source[$key]),
                    'source' => $key,
                ];
            }
        }
        $providerResponse = isset($source['provider_response']) && \is_array($source['provider_response'])
            ? $source['provider_response']
            : [];
        foreach (['clock_folio_id', 'folio_id', 'default_folio_id'] as $key) {
            if (isset($providerResponse[$key]) && \trim((string) $providerResponse[$key]) !== '') {
                return [
                    'folio_id' => \sanitize_text_field((string) $providerResponse[$key]),
                    'source' => 'provider_response.' . $key,
                ];
            }
        }
        foreach (['folio', 'folios', 'account', 'accounts'] as $containerKey) {
            if (isset($source[$containerKey]) && \is_array($source[$containerKey])) {
                $nested = $this->findFirstFolioIdInNestedArray($source[$containerKey], $containerKey);
                if ((string) ($nested['folio_id'] ?? '') !== '') {
                    return $nested;
                }
            }
            if (isset($providerResponse[$containerKey]) && \is_array($providerResponse[$containerKey])) {
                $nested = $this->findFirstFolioIdInNestedArray($providerResponse[$containerKey], 'provider_response.' . $containerKey);
                if ((string) ($nested['folio_id'] ?? '') !== '') {
                    return $nested;
                }
            }
        }
        return [
            'folio_id' => '',
            'source' => '',
        ];
    }
    /** @param array<mixed> $source @return array<string, string> */
    private function findFirstFolioIdInNestedArray(array $source, string $path): array
    {
        foreach ($source as $key => $value) {
            $currentPath = $path . '.' . (string) $key;
            if (\is_array($value)) {
                $nested = $this->findFirstFolioIdInNestedArray($value, $currentPath);
                if ((string) ($nested['folio_id'] ?? '') !== '') {
                    return $nested;
                }
                continue;
            }
            if (\in_array((string) $key, ['id', 'folio_id', 'default_folio_id', 'clock_folio_id'], true) && \trim((string) $value) !== '') {
                return [
                    'folio_id' => \sanitize_text_field((string) $value),
                    'source' => $currentPath,
                ];
            }
        }
        return [
            'folio_id' => '',
            'source' => '',
        ];
    }
    /** @param array<string, mixed> $metadata */
    private function updateReservationProviderMetadata(int $reservationId, array $metadata): bool
    {
        global $wpdb;
        if ($reservationId <= 0) {
            return false;
        }
        $table = $wpdb->prefix . 'must_reservations';
        $encoded = \wp_json_encode($metadata);
        if (!\is_string($encoded) || $encoded === '') {
            return false;
        }
        $updated = $wpdb->update(
            $table,
            [
                'provider_metadata' => $encoded,
                'updated_at' => \current_time('mysql'),
            ],
            ['id' => $reservationId],
            ['%s', '%s'],
            ['%d']
        );
        return \is_int($updated);
    }
}
