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

            case 'paypal':
                return new PayPalPayment($storageMethod);

            case 'bank_transfer':
                return new BankTransferPayment($storageMethod);

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

            case 'paypal':
                return 'paypal';

            case 'bank_transfer':
                return 'bank_transfer';

            case 'cash':
            case 'pay_at_hotel':
                return 'cash';

            default:
                return '';
        }
    }
}
