<?php

namespace MustHotelBooking\Engine\Payment;

interface PaymentInterface
{
    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function processPayment(array $reservation, float $amount, array $context = []): array;

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function refundPayment(array $reservation, float $amount, array $context = []): array;

    /**
     * @param array<string, mixed> $paymentData
     * @return array<string, mixed>
     */
    public function validatePayment(array $paymentData = []): array;
}
