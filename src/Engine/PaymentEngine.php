<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Engine\Payment\PaymentFactory;
use MustHotelBooking\Engine\Payment\PaymentInterface;

final class PaymentEngine
{
    public static function getGateway(string $method = ''): PaymentInterface
    {
        return PaymentFactory::create($method);
    }

    public static function normalizeMethod(string $method): string
    {
        return PaymentFactory::normalizeMethod($method);
    }

    /**
     * @return array{reservation_status: string, payment_status: string, clear_selection: bool, increment_coupon_usage: bool}
     */
    public static function getReservationCreationOptions(string $method): array
    {
        $gateway = self::normalizeMethod($method);

        if ($gateway === 'stripe') {
            return [
                'reservation_status' => 'pending_payment',
                'payment_status' => 'pending',
                'clear_selection' => false,
                'increment_coupon_usage' => false,
            ];
        }

        if ($gateway === 'bank_transfer' || $gateway === 'paypal') {
            return [
                'reservation_status' => 'pending',
                'payment_status' => 'pending',
                'clear_selection' => true,
                'increment_coupon_usage' => true,
            ];
        }

        return [
            'reservation_status' => 'confirmed',
            'payment_status' => 'unpaid',
            'clear_selection' => true,
            'increment_coupon_usage' => true,
        ];
    }

    public static function isGatewayAvailable(string $method): bool
    {
        $validation = self::getGateway($method)->validatePayment();

        return !empty($validation['success']);
    }

    public static function supportsReusablePendingReservations(string $method): bool
    {
        return self::normalizeMethod($method) === 'stripe';
    }

    /**
     * @param array<int, int> $reservationIds
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function processPayment(string $method, array $reservationIds, float $amount, array $context = []): array
    {
        $payload = [
            'reservation_ids' => self::normalizeReservationIds($reservationIds),
            'method' => \sanitize_key($method),
        ];

        return self::getGateway($method)->processPayment($payload, $amount, $context);
    }

    /**
     * @param array<int, int> $reservationIds
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function refundPayment(string $method, array $reservationIds, float $amount, array $context = []): array
    {
        $payload = [
            'reservation_ids' => self::normalizeReservationIds($reservationIds),
            'method' => \sanitize_key($method),
        ];

        return self::getGateway($method)->refundPayment($payload, $amount, $context);
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public static function syncReturnSession(string $method, string $sessionId, array $reservationIds): array
    {
        if (self::normalizeMethod($method) !== 'stripe') {
            return [
                'success' => false,
                'message' => '',
            ];
        }

        return maybe_sync_stripe_return_session($sessionId, self::normalizeReservationIds($reservationIds));
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, int>
     */
    private static function normalizeReservationIds(array $reservationIds): array
    {
        return \array_values(
            \array_filter(
                \array_map('intval', $reservationIds),
                static function (int $reservationId): bool {
                    return $reservationId > 0;
                }
            )
        );
    }
}
