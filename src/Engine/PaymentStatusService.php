<?php

namespace MustHotelBooking\Engine;

final class PaymentStatusService
{
    /**
     * @param array<string, mixed> $reservation
     * @param array<int, array<string, mixed>> $paymentRows
     * @return array<string, mixed>
     */
    public static function buildReservationPaymentState(array $reservation, array $paymentRows = []): array
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        if ($reservationId > 0 && empty($paymentRows)) {
            $paymentRows = \MustHotelBooking\Engine\get_payment_repository()->getPaymentsForReservation($reservationId);
        }

        $total = isset($reservation['total_price']) ? (float) $reservation['total_price'] : 0.0;
        $reservationStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
        $storedPaymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));
        $amountPaid = 0.0;
        $amountRefunded = 0.0;
        $latestPayment = null;

        foreach ($paymentRows as $paymentRow) {
            if (!\is_array($paymentRow)) {
                continue;
            }

            if ($latestPayment === null) {
                $latestPayment = $paymentRow;
            }

            $paymentStatus = \sanitize_key((string) ($paymentRow['status'] ?? ''));

            if ($paymentStatus === 'paid') {
                $amountPaid += (float) ($paymentRow['amount'] ?? 0.0);
                continue;
            }

            if ($paymentStatus === 'refunded') {
                $amountRefunded += (float) ($paymentRow['amount'] ?? 0.0);
            }
        }

        $latestMethod = self::normalizeMethod((string) ($latestPayment['method'] ?? ''));
        $latestStatus = \sanitize_key((string) ($latestPayment['status'] ?? ''));
        $transactionId = (string) ($latestPayment['transaction_id'] ?? '');
        $paidAt = (string) ($latestPayment['paid_at'] ?? '');
        $createdAt = (string) ($latestPayment['created_at'] ?? '');
        $netPaid = \max(0.0, $amountPaid - $amountRefunded);
        $amountDue = \max(0.0, $total - $netPaid);
        $derivedStatus = self::deriveStatus($reservationStatus, $storedPaymentStatus, $latestMethod, $latestStatus, $total, $netPaid, $amountDue);
        $warnings = self::buildWarnings($reservationStatus, $storedPaymentStatus, $latestMethod, $latestStatus, $total, $netPaid, $amountDue, $transactionId, $paidAt, $paymentRows);

        return [
            'reservation_id' => $reservationId,
            'reservation_status' => $reservationStatus,
            'stored_payment_status' => $storedPaymentStatus,
            'payment_rows' => $paymentRows,
            'payment_count' => \count($paymentRows),
            'total' => $total,
            'gross_amount_paid' => \round($amountPaid, 2),
            'amount_refunded' => \round($amountRefunded, 2),
            'amount_paid' => \round($netPaid, 2),
            'amount_due' => \round($amountDue, 2),
            'method' => $latestMethod,
            'latest_payment_status' => $latestStatus,
            'derived_status' => $derivedStatus,
            'transaction_id' => $transactionId,
            'paid_at' => $paidAt,
            'created_at' => $createdAt,
            'warnings' => $warnings,
            'needs_review' => !empty($warnings) || \in_array($derivedStatus, ['failed', 'pending'], true),
        ];
    }

    public static function canTransition(array $state, string $target): bool
    {
        $target = \sanitize_key($target);
        $reservationStatus = \sanitize_key((string) ($state['reservation_status'] ?? ''));
        $derivedStatus = \sanitize_key((string) ($state['derived_status'] ?? ''));
        $method = self::normalizeMethod((string) ($state['method'] ?? ''));
        $amountPaid = isset($state['amount_paid']) ? (float) $state['amount_paid'] : 0.0;

        if (\in_array($reservationStatus, ['cancelled', 'blocked'], true) && $target !== 'mark_paid') {
            return false;
        }

        if ($target === 'mark_paid') {
            return !\in_array($derivedStatus, ['paid', 'refunded'], true);
        }

        if ($target === 'mark_unpaid') {
            return !\in_array($derivedStatus, ['refunded'], true) && !($method === 'stripe' && $amountPaid > 0.0);
        }

        if ($target === 'mark_pending') {
            return !\in_array($derivedStatus, ['paid', 'refunded'], true);
        }

        if ($target === 'mark_pay_at_hotel') {
            return $derivedStatus !== 'refunded';
        }

        if ($target === 'mark_failed') {
            return !\in_array($derivedStatus, ['paid', 'refunded'], true);
        }

        return false;
    }

    private static function normalizeMethod(string $method): string
    {
        $method = \sanitize_key($method);
        $gateway = PaymentEngine::normalizeMethod($method);

        if ($gateway === 'stripe') {
            return 'stripe';
        }

        if ($method === 'pay_at_hotel' || $gateway === 'cash') {
            return 'pay_at_hotel';
        }

        return $method;
    }

    private static function deriveStatus(string $reservationStatus, string $storedPaymentStatus, string $method, string $latestStatus, float $total, float $amountPaid, float $amountDue): string
    {
        if ($storedPaymentStatus === 'refunded' || $latestStatus === 'refunded') {
            if ($amountPaid <= 0.0) {
                return 'refunded';
            }

            if ($amountDue > 0.0) {
                return 'partially_paid';
            }
        }

        if ($total <= 0.0) {
            return 'paid';
        }

        if ($amountPaid >= $total) {
            return 'paid';
        }

        if ($amountPaid > 0.0 && $amountDue > 0.0) {
            return 'partially_paid';
        }

        if ($reservationStatus === 'payment_failed' || $storedPaymentStatus === 'failed' || $latestStatus === 'failed' || $storedPaymentStatus === 'cancelled') {
            return 'failed';
        }

        if ($method === 'pay_at_hotel') {
            return 'pay_at_hotel';
        }

        if ($reservationStatus === 'pending_payment' || $storedPaymentStatus === 'pending' || $latestStatus === 'pending' || $latestStatus === 'processing') {
            return 'pending';
        }

        return 'unpaid';
    }

    /**
     * @param array<int, array<string, mixed>> $paymentRows
     * @return array<int, string>
     */
    private static function buildWarnings(string $reservationStatus, string $storedPaymentStatus, string $method, string $latestStatus, float $total, float $amountPaid, float $amountDue, string $transactionId, string $paidAt, array $paymentRows): array
    {
        $warnings = [];

        if ($total > 0.0 && empty($paymentRows) && \in_array($storedPaymentStatus, ['paid', 'pending', 'failed'], true)) {
            $warnings[] = \__('Reservation has a payment status but no payment ledger rows.', 'must-hotel-booking');
        }

        if ($method === 'pay_at_hotel' && $amountPaid > 0.0 && $storedPaymentStatus !== 'paid') {
            $warnings[] = \__('Pay-at-hotel booking has collected funds but the reservation is not marked paid.', 'must-hotel-booking');
        }

        if ($storedPaymentStatus === 'paid' && $amountDue > 0.0) {
            $warnings[] = \__('Reservation is marked paid but still has amount due.', 'must-hotel-booking');
        }

        if ($method === 'stripe' && $latestStatus === 'paid' && $transactionId === '') {
            $warnings[] = \__('Stripe payment is marked paid but has no transaction reference.', 'must-hotel-booking');
        }

        if (($storedPaymentStatus === 'paid' || $latestStatus === 'paid') && $paidAt === '') {
            $warnings[] = \__('Paid payment is missing a paid timestamp.', 'must-hotel-booking');
        }

        if ($reservationStatus === 'confirmed' && $amountDue > 0.0 && $method === 'stripe') {
            $warnings[] = \__('Reservation is confirmed while Stripe payment is still incomplete.', 'must-hotel-booking');
        }

        if ($latestStatus === 'failed') {
            $warnings[] = \__('Latest payment attempt failed and needs review.', 'must-hotel-booking');
        }

        return \array_values(\array_unique($warnings));
    }
}
