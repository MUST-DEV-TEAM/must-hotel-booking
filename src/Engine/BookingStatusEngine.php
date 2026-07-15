<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\ReservationStatus;

final class BookingStatusEngine
{
    /**
     * @param array<int, int> $reservationIds
     */
    public static function updateReservationStatuses(array $reservationIds, string $status, string $paymentStatus, bool $dispatchHooks = true): array
    {
        $reservationRows = self::getPaymentReservationRows($reservationIds);
        $reservationRepository = get_reservation_repository();
        $outcome = ['updated' => [], 'already' => [], 'blocked' => [], 'failed' => [], 'events' => []];

        foreach ($reservationRows as $reservationRow) {
            $reservationId = isset($reservationRow['id']) ? (int) $reservationRow['id'] : 0;
            $previousStatus = isset($reservationRow['status']) ? (string) $reservationRow['status'] : '';
            $previousPaymentStatus = isset($reservationRow['payment_status']) ? (string) $reservationRow['payment_status'] : '';

            if ($reservationId <= 0) {
                $outcome['failed'][] = $reservationId;
                continue;
            }
            if ($previousStatus === $status && ($paymentStatus === '' || $previousPaymentStatus === $paymentStatus)) {
                $outcome['already'][] = $reservationId;
                continue;
            }

            if (
                !ReservationStatus::isConfirmed($previousStatus)
                && ReservationStatus::isConfirmed($status)
                && self::isBlockedOnlineClockConfirmation($reservationId, $paymentStatus)
            ) {
                $outcome['blocked'][] = $reservationId;
                continue;
            }

            if (!$reservationRepository->updateReservationStatus($reservationId, $status, $paymentStatus)) {
                $outcome['failed'][] = $reservationId;
                continue;
            }
            $outcome['updated'][] = $reservationId;

            if ($previousStatus !== 'cancelled' && $status === 'cancelled') {
                $outcome['events'][] = ['hook' => 'must_hotel_booking/reservation_cancelled', 'reservation_id' => $reservationId];
                if ($dispatchHooks) {
                    \do_action('must_hotel_booking/reservation_cancelled', $reservationId);
                }
            }

            if (
                !ReservationStatus::isConfirmed($previousStatus) &&
                ReservationStatus::isConfirmed($status)
            ) {
                $outcome['events'][] = ['hook' => 'must_hotel_booking/reservation_confirmed', 'reservation_id' => $reservationId];
                if ($dispatchHooks) {
                    \do_action('must_hotel_booking/reservation_confirmed', $reservationId);
                }
            }
        }

        return $outcome;
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
    public static function createPaymentRows(array $reservationIds, string $method, string $status, string $transactionId = '', bool $dispatchHooks = true): array
    {
        $reservationRows = self::getPaymentReservationRows($reservationIds);
        $currency = \class_exists(MustBookingConfig::class) ? MustBookingConfig::get_currency() : 'USD';
        $paymentRepository = get_payment_repository();
        $outcome = ['created' => [], 'updated' => [], 'already' => [], 'failed' => [], 'events' => []];

        foreach ($reservationRows as $reservationRow) {
            $reservationId = isset($reservationRow['id']) ? (int) $reservationRow['id'] : 0;

            if ($reservationId <= 0) {
                $outcome['failed'][] = $reservationId;
                continue;
            }

            $existingPaymentId = $transactionId !== ''
                ? $paymentRepository->getLatestPaymentIdForReservationMethodTransaction($reservationId, $method, $transactionId)
                : 0;
            if ($existingPaymentId <= 0) {
                $existingPaymentId = $paymentRepository->getLatestPaymentIdForReservationMethod($reservationId, $method);
            }
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
                $existingPayment = $paymentRepository->getPayment($existingPaymentId);

                if (
                    \is_array($existingPayment)
                    && self::paymentRecordAlreadyMatches($existingPayment, $paymentData, $transactionId)
                ) {
                    $outcome['already'][] = $reservationId;
                    continue;
                }

                if ($transactionId === '') {
                    unset($paymentData['transaction_id']);
                }

                $updated = $paymentRepository->updatePayment($existingPaymentId, $paymentData);

                if ($updated) {
                    $outcome['updated'][] = $reservationId;
                    $event = [
                        'payment_id' => $existingPaymentId,
                        'reservation_id' => $reservationId,
                        'method' => $method,
                        'status' => $status,
                        'amount' => isset($paymentData['amount']) ? (float) $paymentData['amount'] : 0.0,
                        'transaction_id' => $transactionId,
                        'paid_at' => isset($paymentData['paid_at']) ? (string) $paymentData['paid_at'] : '',
                        'created_at' => \current_time('mysql'),
                        'is_update' => true,
                    ];
                    $outcome['events'][] = $event;
                    if ($dispatchHooks) {
                        \do_action('must_hotel_booking/payment_recorded', $event);
                    }
                }
                if (!$updated) {
                    $outcome['failed'][] = $reservationId;
                }

                continue;
            }

            $paymentData['reservation_id'] = $reservationId;
            $paymentData['created_at'] = \current_time('mysql');
            $paymentId = $paymentRepository->createPayment($paymentData);

            if ($paymentId > 0) {
                $outcome['created'][] = $reservationId;
                $event = [
                    'payment_id' => $paymentId,
                    'reservation_id' => $reservationId,
                    'method' => $method,
                    'status' => $status,
                    'amount' => isset($paymentData['amount']) ? (float) $paymentData['amount'] : 0.0,
                    'transaction_id' => $transactionId,
                    'paid_at' => isset($paymentData['paid_at']) ? (string) $paymentData['paid_at'] : '',
                    'created_at' => (string) $paymentData['created_at'],
                    'is_update' => false,
                ];
                $outcome['events'][] = $event;
                if ($dispatchHooks) {
                    \do_action('must_hotel_booking/payment_recorded', $event);
                }
            } else {
                $outcome['failed'][] = $reservationId;
            }
        }

        return $outcome;
    }

    /** @param array<string, mixed> $outcome */
    public static function dispatchReservationStatusEvents(array $outcome): void
    {
        foreach ((array) ($outcome['events'] ?? []) as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $hook = (string) ($event['hook'] ?? '');
            $reservationId = (int) ($event['reservation_id'] ?? 0);
            if ($reservationId > 0 && \in_array($hook, ['must_hotel_booking/reservation_cancelled', 'must_hotel_booking/reservation_confirmed'], true)) {
                \do_action($hook, $reservationId);
            }
        }
    }

    /** @param array<string, mixed> $outcome */
    public static function dispatchPaymentRecordedEvents(array $outcome): void
    {
        foreach ((array) ($outcome['events'] ?? []) as $event) {
            if (\is_array($event) && (int) ($event['payment_id'] ?? 0) > 0) {
                \do_action('must_hotel_booking/payment_recorded', $event);
            }
        }
    }

    /**
     * @param array<string, mixed> $existingPayment
     * @param array<string, mixed> $paymentData
     */
    private static function paymentRecordAlreadyMatches(array $existingPayment, array $paymentData, string $transactionId): bool
    {
        $existingStatus = isset($existingPayment['status']) ? \sanitize_key((string) $existingPayment['status']) : '';
        $targetStatus = isset($paymentData['status']) ? \sanitize_key((string) $paymentData['status']) : '';

        if ($existingStatus !== $targetStatus) {
            return false;
        }

        $existingTransactionId = isset($existingPayment['transaction_id']) ? \trim((string) $existingPayment['transaction_id']) : '';
        $targetTransactionId = \trim($transactionId);

        if ($targetTransactionId !== '' && $existingTransactionId !== $targetTransactionId) {
            return false;
        }

        if ($targetStatus === 'paid') {
            return \trim((string) ($existingPayment['paid_at'] ?? '')) !== '';
        }

        return true;
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function failPendingStripeReservations(array $reservationIds, string $reservationStatus = 'payment_failed'): void
    {
        self::failPendingPaymentReservations($reservationIds, 'stripe', $reservationStatus);
    }

    /**
     * @param array<int, int> $reservationIds
     */
    public static function failPendingPaymentReservations(array $reservationIds, string $method, string $reservationStatus = 'payment_failed'): void
    {
        if (!\in_array($reservationStatus, ['payment_failed', 'expired', 'cancelled'], true)) {
            $reservationStatus = 'payment_failed';
        }

        $method = \sanitize_key($method);
        $method = $method !== '' ? $method : 'stripe';

        self::updateReservationStatuses($reservationIds, $reservationStatus, 'cancelled');
        self::createPaymentRows($reservationIds, $method, 'failed');

        if (\class_exists(\MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService::class)) {
            (new \MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService())->reconcilePaymentFailed($reservationIds, $reservationStatus, $method);
        }
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
        $providerSyncStatuses = [];

        foreach ($reservations as $reservation) {
            if (!\is_array($reservation)) {
                continue;
            }

            $statuses[] = isset($reservation['status']) ? \sanitize_key((string) $reservation['status']) : '';
            $paymentStatuses[] = isset($reservation['payment_status']) ? \sanitize_key((string) $reservation['payment_status']) : '';
            $providerSyncStatuses[] = isset($reservation['provider_sync_status']) ? \sanitize_key((string) $reservation['provider_sync_status']) : '';
        }

        if (\in_array('manual_review', $providerSyncStatuses, true)) {
            return [
                'heading' => \__('Payment Received — Booking Review Required', 'must-hotel-booking'),
                'message' => \__('Your payment was verified, but the hotel must safely review the reservation before it can be confirmed. Do not make another payment.', 'must-hotel-booking'),
            ];
        }

        if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'pending_payment') {
            return [
                'heading' => \__('Payment Processing', 'must-hotel-booking'),
                'message' => \__('Your payment is being confirmed. This page will update once the payment provider finishes processing your booking.', 'must-hotel-booking'),
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

    private static function isBlockedOnlineClockConfirmation(int $reservationId, string $targetPaymentStatus): bool
    {
        $reservation = get_reservation_repository()->getReservation($reservationId);
        if (!\is_array($reservation) || \sanitize_key((string) ($reservation['provider'] ?? '')) !== 'clock') {
            return false;
        }

        $providerBookingId = \trim((string) ($reservation['provider_booking_id'] ?? ''));
        $providerReservationId = \trim((string) ($reservation['provider_reservation_id'] ?? ''));
        if ($providerBookingId === '' && $providerReservationId === '') {
            return true;
        }

        $onlinePayment = false;
        $paidPayment = false;
        foreach (get_payment_repository()->getPaymentsForReservation($reservationId) as $payment) {
            if (!\is_array($payment)) {
                continue;
            }
            $method = \sanitize_key((string) ($payment['method'] ?? ''));
            if (!\in_array($method, ['stripe', 'pokpay'], true)) {
                continue;
            }
            $onlinePayment = true;
            if (
                \sanitize_key((string) ($payment['status'] ?? '')) === 'paid'
                && \trim((string) ($payment['transaction_id'] ?? '')) !== ''
            ) {
                $paidPayment = true;
            }
        }

        return $onlinePayment && (!$paidPayment || \sanitize_key($targetPaymentStatus) !== 'paid');
    }
}
