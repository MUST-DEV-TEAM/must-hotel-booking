<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Provider\ProviderReservationView;

final class CancellationFinancialCleanupService
{
    /** @return array<string, mixed> */
    public function captureSnapshot(int $reservationId, array $context = []): array
    {
        $repository = get_reservation_repository();
        $reservation = $repository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return [];
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $existing = isset($metadata['cancellation_financial_cleanup'])
            && \is_array($metadata['cancellation_financial_cleanup'])
            ? $metadata['cancellation_financial_cleanup']
            : [];

        if (isset($existing['snapshot']) && \is_array($existing['snapshot'])) {
            return $existing;
        }

        $payments = get_payment_repository()->getPaymentsForReservation($reservationId);
        $paymentState = PaymentStatusService::buildReservationPaymentState($reservation, $payments);
        $paidAmount = \round((float) ($paymentState['amount_paid'] ?? 0.0), 2);
        $penalty = CancellationEngine::getPenaltyDetails($reservationId, $this->now());
        $cancellationFee = \min($paidAmount, \max(0.0, \round((float) ($penalty['penalty_amount'] ?? 0.0), 2)));
        $breakdown = (new PaymentProviderFeeService())->calculateDefaultRefundBreakdown(
            $payments,
            $paidAmount,
            (string) ($context['source'] ?? 'system'),
            $cancellationFee,
            $this->policyReason($penalty)
        );
        $refundableAmount = \min(
            $paidAmount,
            \max(0.0, \round((float) ($breakdown['final_refund_amount'] ?? 0.0), 2))
        );
        $latestPaid = $this->latestPaidPayment($payments);
        $currency = \strtoupper((string) ($latestPaid['currency'] ?? MustBookingConfig::get_currency()));
        $isClock = ProviderReservationView::isProviderBacked($reservation);

        $cleanup = [
            'snapshot' => [
                'original_reservation_total' => \round((float) ($reservation['total_price'] ?? 0.0), 2),
                'gross_paid_amount' => \round((float) ($paymentState['gross_amount_paid'] ?? 0.0), 2),
                'previously_refunded_amount' => \round((float) ($paymentState['amount_refunded'] ?? 0.0), 2),
                'paid_amount' => $paidAmount,
                'cancellation_policy' => $penalty,
                'provider_fee_retained' => \round((float) ($breakdown['provider_fee_retained'] ?? 0.0), 2),
                'provider_fee_status' => \sanitize_key((string) ($breakdown['provider_fee_status'] ?? 'unknown')),
                'cancellation_fee_amount' => $cancellationFee,
                'refundable_amount' => $refundableAmount,
                'non_refundable_amount' => \round(\max(0.0, $paidAmount - $refundableAmount), 2),
                'currency' => $currency,
                'payment_method' => \sanitize_key((string) ($paymentState['method'] ?? '')),
                'cancellation_source' => \sanitize_key((string) ($context['source'] ?? 'unknown')),
                'cancellation_reason' => \sanitize_text_field((string) ($context['reason'] ?? $context['operation'] ?? '')),
                'refund_policy_reason' => \sanitize_text_field((string) ($breakdown['refund_policy_reason'] ?? '')),
                'calculated_by' => \sanitize_key((string) ($breakdown['calculated_by'] ?? 'system')),
                'calculated_at' => $this->now(),
            ],
            'reservation_cancellation_status' => 'pending',
            'refund_review_status' => $paidAmount > 0.0 ? 'pending_review' : 'not_required',
            'gateway_refund_status' => 'not_started',
            'clock_booking_cancellation_status' => $isClock ? 'pending' : 'not_required',
            'clock_payment_accounting_status' => $isClock && $paidAmount > 0.0 ? 'pending' : 'not_required',
            'clock_charge_cleanup_status' => $isClock ? 'manual_clock_charge_cleanup_required' : 'not_required',
            'clock_cancellation_fee_status' => $isClock && $cancellationFee > 0.0
                ? 'manual_clock_cancellation_fee_required'
                : 'not_required',
            'expected_clock_result' => [
                'status' => $isClock ? 'manual_verification_required' : 'not_required',
                'expected_retained_amount' => \round(\max(0.0, $paidAmount - $refundableAmount), 2),
                'expected_final_balance' => null,
                'actual_balance' => null,
                'reason' => $isClock
                    ? 'Clock accommodation-charge reversal and cancellation-fee posting require a documented provider contract before an exact final folio balance can be automated.'
                    : '',
            ],
            'last_error' => '',
            'updated_at' => $this->now(),
        ];

        $metadata['cancellation_financial_cleanup'] = $cleanup;
        $repository->updateProviderMetadata($reservationId, ['provider_metadata' => $metadata]);

        return $cleanup;
    }

    public function markReservationCancelled(int $reservationId, array $context = []): void
    {
        $repository = get_reservation_repository();
        $reservation = $repository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return;
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $cleanup = isset($metadata['cancellation_financial_cleanup'])
            && \is_array($metadata['cancellation_financial_cleanup'])
            ? $metadata['cancellation_financial_cleanup']
            : $this->captureSnapshot($reservationId, $context);

        if (empty($cleanup)) {
            return;
        }

        $cleanup['reservation_cancellation_status'] = 'cancelled';
        $cleanup['cancelled_at'] = $this->now();
        $cleanup['cancellation_source'] = \sanitize_key((string) ($context['source'] ?? 'unknown'));
        $cleanup['cancellation_reason'] = \sanitize_text_field((string) ($context['reason'] ?? $context['operation'] ?? ''));
        $cleanup['clock_booking_cancellation_status'] = ProviderReservationView::isProviderBacked($reservation)
            ? 'complete'
            : 'not_required';
        $cleanup['updated_at'] = $this->now();
        $metadata['cancellation_financial_cleanup'] = $cleanup;
        $repository->updateProviderMetadata($reservationId, ['provider_metadata' => $metadata]);
    }

    /** @param array<string, mixed> $updates */
    public function updateState(int $reservationId, array $updates): void
    {
        $repository = get_reservation_repository();
        $reservation = $repository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return;
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $cleanup = isset($metadata['cancellation_financial_cleanup'])
            && \is_array($metadata['cancellation_financial_cleanup'])
            ? $metadata['cancellation_financial_cleanup']
            : [];

        if (empty($cleanup)) {
            return;
        }

        foreach ($updates as $key => $value) {
            if (\is_string($key)) {
                $cleanup[$key] = $value;
            }
        }

        $cleanup['updated_at'] = $this->now();
        $metadata['cancellation_financial_cleanup'] = $cleanup;
        $repository->updateProviderMetadata($reservationId, ['provider_metadata' => $metadata]);
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

    /** @param array<int, array<string, mixed>> $payments @return array<string, mixed> */
    private function latestPaidPayment(array $payments): array
    {
        foreach ($payments as $payment) {
            if (\is_array($payment) && \sanitize_key((string) ($payment['status'] ?? '')) === 'paid') {
                return $payment;
            }
        }

        return [];
    }

    /** @param array<string, mixed> $penalty */
    private function policyReason(array $penalty): string
    {
        if (empty($penalty['success'])) {
            return \__('Cancellation policy calculation requires manual review.', 'must-hotel-booking');
        }

        return !empty($penalty['penalty_applied'])
            ? \__('Cancellation policy retains a cancellation fee.', 'must-hotel-booking')
            : \__('Cancellation policy does not retain a cancellation fee.', 'must-hotel-booking');
    }

    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
