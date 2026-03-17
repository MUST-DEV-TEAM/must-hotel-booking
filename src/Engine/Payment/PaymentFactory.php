<?php

namespace MustHotelBooking\Engine\Payment;

final class PaymentFactory
{
    public static function create(string $method): PaymentInterface
    {
        $requestedMethod = \sanitize_key($method);
        $storageMethod = $requestedMethod !== '' ? $requestedMethod : 'pay_at_hotel';
        $gateway = self::normalizeMethod($requestedMethod);

        switch ($gateway) {
            case 'stripe':
                return new StripePayment($storageMethod);

            case 'cash':
            default:
                return new CashPayment($storageMethod);
        }
    }

    public static function normalizeMethod(string $method): string
    {
        $method = \sanitize_key($method);

        switch ($method) {
            case '':
                return '';

            case 'stripe':
                return 'stripe';

            case 'cash':
            case 'pay_at_hotel':
                return 'cash';

            default:
                return '';
        }
    }
}