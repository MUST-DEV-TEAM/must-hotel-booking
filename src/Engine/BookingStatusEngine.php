<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\ReservationStatus;

final class BookingStatusEngine
{
    /**
     * @param array<int, int> $reservationIds
     */
    public static function updateReservationStatuses(array $reservationIds, string $status, string $paymentStatus): void
    {
        $reservationRows = self::getPaymentReservationRows($reservationIds);
        $reservationRepository = get_reservation_repository();

        foreach ($reservationRows as $reservationRow) {
            $reservationId = isset($reservationRow['id']) ? (int) $reservationRow['id'] : 0;
            $previousStatus = isset($reservationRow['status']) ? (string) $reservationRow['status'] : '';

            if ($reservationId <= 0) {
                continue;
            }

            $reservationRepository->updateReservationStatus($reservationId, $status, $paymentStatus);

            if ($previousStatus !== 'cancelled' && $status === 'cancelled') {
                \do_action('must_hotel_booking/reservation_cancelled', $reservationId);
            }

            if (
                !ReservationStatus::isConfirmed($previousStatus) &&
                ReservationStatus::isConfirmed($status)
            ) {
                \do_action('must_hotel_booking/reservation_confirmed', $reservationId);
            }
        }
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public static function syncStripeReturnSession(string $sessionId, array $reservationIds): array
    {
        return PaymentEngine::syncReturnSession('stripe', $sessionId, $reservationIds);
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function areReusablePendingPaymentReservations(array $reservationIds): bool
    {
        $rows = self::getPaymentReservationRows($reservationIds);

        if (\count($rows) !== \count($reservationIds)) {
            return false;
        }

        foreach ($rows as $row) {
            $status = isset($row['status']) ? (string) $row['status'] : '';
            $paymentStatus = isset($row['payment_status']) ? (string) $row['payment_status'] : '';

            if ($status !== 'pending_payment' || $paymentStatus !== 'pending') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function createPaymentRows(array $reservationIds, string $method, string $status, string $transactionId = ''): void
    {
        $reservationRows = self::getPaymentReservationRows($reservationIds);
        $currency = \class_exists(MustBookingConfig::class) ? MustBookingConfig::get_currency() : 'USD';
        $paymentRepository = get_payment_repository();

        foreach ($reservationRows as $reservationRow) {
            $reservationId = isset($reservationRow['id']) ? (int) $reservationRow['id'] : 0;

            if ($reservationId <= 0) {
                continue;
            }

            $existingPaymentId = $paymentRepository->getLatestPaymentIdForReservationMethod($reservationId, $method);
            $paymentData = [
                'amount' => isset($reservationRow['total_price']) ? (float) $reservationRow['total_price'] : 0.0,
                'currency' => $currency,
                'method' => $method,
                'status' => $status,
                'transaction_id' => $transactionId,
            ];

            if ($status === 'paid') {
                $paymentData['paid_at'] = \current_time('mysql');
            }

            if ($existingPaymentId > 0) {
                if ($transactionId === '') {
                    unset($paymentData['transaction_id']);
                }

                $paymentRepository->updatePayment($existingPaymentId, $paymentData);
                continue;
            }

            $paymentData['reservation_id'] = $reservationId;
            $paymentData['created_at'] = \current_time('mysql');
            $paymentRepository->createPayment($paymentData);
        }
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function failPendingStripeReservations(array $reservationIds, string $reservationStatus = 'payment_failed'): void
    {
        if (!\in_array($reservationStatus, ['payment_failed', 'expired', 'cancelled'], true)) {
            $reservationStatus = 'payment_failed';
        }

        self::updateReservationStatuses($reservationIds, $reservationStatus, 'cancelled');
        self::createPaymentRows($reservationIds, 'stripe', 'failed');
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

        if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'expired') {
            return [
                'heading' => \__('Payment Session Expired', 'must-hotel-booking'),
                'message' => \__('The payment session expired before it was completed. Please start the payment step again.', 'must-hotel-booking'),
            ];
        }

        if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'payment_failed') {
            return [
                'heading' => \__('Payment Failed', 'must-hotel-booking'),
                'message' => \__('We could not confirm your payment. Please try again.', 'must-hotel-booking'),
            ];
        }

        if (!empty($paymentStatuses) && \count(\array_unique($paymentStatuses)) === 1 && $paymentStatuses[0] === 'paid') {
            return [
                'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
                'message' => \__('Your payment was successful and your stay is confirmed.', 'must-hotel-booking'),
            ];
        }

        if ($gateway === 'cash' || $paymentMethodHint === 'pay_at_hotel') {
            return [
                'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
                'message' => \__('Your reservation is confirmed. Payment will be collected at the hotel.', 'must-hotel-booking'),
            ];
        }

        return [
            'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
            'message' => \__('Your stay has been confirmed.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    private static function getPaymentReservationRows(array $reservationIds): array
    {
        return get_reservation_repository()->getReservationsByIds($reservationIds);
    }
}