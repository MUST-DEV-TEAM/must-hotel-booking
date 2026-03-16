<?php

namespace MustHotelBooking\Engine;

final class BookingStatusEngine
{
    /**
     * @param array<int, int> $reservationIds
     */
    public static function updateReservationStatuses(array $reservationIds, string $status, string $paymentStatus): void
    {
        update_reservations_payment_state($reservationIds, $status, $paymentStatus);
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public static function syncStripeReturnSession(string $sessionId, array $reservationIds): array
    {
        return maybe_sync_stripe_return_session($sessionId, $reservationIds);
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function areReusablePendingPaymentReservations(array $reservationIds): bool
    {
        return are_reusable_pending_payment_reservations($reservationIds);
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function createPaymentRows(array $reservationIds, string $method, string $status, string $transactionId = ''): void
    {
        create_or_update_payment_rows($reservationIds, $method, $status, $transactionId);
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function failPendingStripeReservations(array $reservationIds, string $reservationStatus = 'payment_failed'): void
    {
        fail_pending_stripe_reservations($reservationIds, $reservationStatus);
    }

    /**
     * @param array<int, array<string, mixed>> $reservations
     * @return array<string, string>
     */
    public static function getConfirmationResultCopy(array $reservations, string $paymentMethodHint = ''): array
    {
        $gateway = PaymentEngine::normalizeMethod($paymentMethodHint);
        $statuses = [];
        $paymentStatuses = [];

        foreach ($reservations as $reservation) {
            if (!\is_array($reservation)) {
                continue;
            }

            $statuses[] = isset($reservation['status']) ? \sanitize_key((string) $reservation['status']) : '';
            $paymentStatuses[] = isset($reservation['payment_status']) ? \sanitize_key((string) $reservation['payment_status']) : '';
        }

        if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'pending_payment') {
            return [
                'heading' => \__('Payment Processing', 'must-hotel-booking'),
                'message' => \__('Your payment is being confirmed. This page will update once Stripe finishes processing your booking.', 'must-hotel-booking'),
            ];
        }

        if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'pending') {
            if ($paymentMethodHint === 'bank_transfer' || $gateway === 'paypal') {
                return [
                    'heading' => \__('Booking Pending Payment', 'must-hotel-booking'),
                    'message' => $paymentMethodHint === 'paypal'
                        ? \__('Your booking request has been received and is waiting for PayPal payment confirmation.', 'must-hotel-booking')
                        : \__('Your booking request has been received and is waiting for bank transfer payment confirmation.', 'must-hotel-booking'),
                ];
            }

            return [
                'heading' => \__('Booking Pending', 'must-hotel-booking'),
                'message' => \__('Your booking has been received and is awaiting final processing.', 'must-hotel-booking'),
            ];
        }

        if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'expired') {
            return [
                'heading' => \__('Payment Session Expired', 'must-hotel-booking'),
                'message' => \__('The payment session expired before it was completed. Please start the payment step again.', 'must-hotel-booking'),
            ];
        }

        if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'payment_failed') {
            return [
                'heading' => \__('Payment Failed', 'must-hotel-booking'),
                'message' => \__('We were not able to confirm your payment. Please try again or choose another payment method.', 'must-hotel-booking'),
            ];
        }

        if (!empty($paymentStatuses) && \count(\array_unique($paymentStatuses)) === 1 && $paymentStatuses[0] === 'paid') {
            return [
                'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
                'message' => \__('Your payment was successful and your stay is confirmed.', 'must-hotel-booking'),
            ];
        }

        if ($paymentMethodHint === 'pay_at_hotel' || $gateway === 'cash') {
            return [
                'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
                'message' => \__('Your stay is confirmed. Payment will be collected at the hotel.', 'must-hotel-booking'),
            ];
        }

        return [
            'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
            'message' => \__('Your stay has been confirmed.', 'must-hotel-booking'),
        ];
    }
}
