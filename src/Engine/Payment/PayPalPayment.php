<?php

namespace MustHotelBooking\Engine\Payment;

final class PayPalPayment implements PaymentInterface
{
    private string $method;

    public function __construct(string $method = 'paypal')
    {
        $this->method = \sanitize_key($method) ?: 'paypal';
    }

    public function processPayment(array $reservation, float $amount, array $context = []): array
    {
        return $this->validatePayment([
            'reservation_ids' => isset($reservation['reservation_ids']) && \is_array($reservation['reservation_ids'])
                ? $reservation['reservation_ids']
                : [],
            'amount' => $amount,
        ]) + [
            'method' => $this->method,
        ];
    }

    public function refundPayment(array $reservation, float $amount, array $context = []): array
    {
        return [
            'success' => false,
            'method' => $this->method,
            'message' => \__('PayPal refunds are not wired into this plugin yet.', 'must-hotel-booking'),
        ];
    }

    public function validatePayment(array $paymentData = []): array
    {
        return [
            'success' => false,
            'method' => $this->method,
            'message' => \__('PayPal is not configured for this site yet.', 'must-hotel-booking'),
        ];
    }
}
