<?php

namespace MustHotelBooking\Engine\Payment;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\PaymentEngine;

final class PokPayPayment implements PaymentInterface
{
    private string $method;

    public function __construct(string $method = 'pokpay')
    {
        $this->method = \sanitize_key($method) ?: 'pokpay';
    }

    public function processPayment(array $reservation, float $amount, array $context = []): array
    {
        $reservationIds = $this->extractReservationIds($reservation);
        $currency = $this->resolveCurrency($context);
        $validation = $this->validatePayment([
            'reservation_ids' => $reservationIds,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        if (empty($validation['success'])) {
            return $validation + ['method' => $this->method];
        }

        $result = PaymentEngine::createPokPaySdkOrder(
            $reservationIds,
            $this->extractGuestForm($context),
            $amount,
            $currency
        );

        if (empty($result['success'])) {
            return $result + ['method' => $this->method];
        }

        $orderId = isset($result['order_id']) ? (string) $result['order_id'] : '';
        BookingStatusEngine::createPaymentRows($reservationIds, $this->method, 'pending', $orderId);

        return [
            'success' => true,
            'method' => $this->method,
            'reservation_ids' => $reservationIds,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'requires_redirect' => false,
            'requires_embedded_checkout' => true,
            'redirect_url' => '',
            'checkout_url' => '',
            'transaction_id' => $orderId,
            'session_id' => $orderId,
            'order_id' => $orderId,
            'expires_at' => isset($result['expires_at']) ? (string) $result['expires_at'] : '',
        ];
    }

    public function refundPayment(array $reservation, float $amount, array $context = []): array
    {
        return [
            'success' => false,
            'method' => $this->method,
            'message' => \__('PokPay refunds must be handled from the PokPay dashboard in this version.', 'must-hotel-booking'),
        ];
    }

    public function validatePayment(array $paymentData = []): array
    {
        if (!PaymentEngine::isPokPayConfigured()) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('PokPay checkout is not configured.', 'must-hotel-booking'),
            ];
        }

        $reservationIds = $this->extractReservationIds($paymentData);

        if (isset($paymentData['reservation_ids']) && empty($reservationIds)) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('No reservations are available for PokPay checkout.', 'must-hotel-booking'),
            ];
        }

        if (isset($paymentData['amount']) && (float) $paymentData['amount'] <= 0) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('PokPay requires a positive payment amount.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'method' => $this->method,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function extractReservationIds(array $data): array
    {
        $reservationIds = isset($data['reservation_ids']) && \is_array($data['reservation_ids'])
            ? $data['reservation_ids']
            : [];

        return \array_values(
            \array_filter(
                \array_map('intval', $reservationIds),
                static function (int $reservationId): bool {
                    return $reservationId > 0;
                }
            )
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function extractGuestForm(array $context): array
    {
        $guestForm = isset($context['guest_form']) && \is_array($context['guest_form'])
            ? $context['guest_form']
            : [];
        $normalized = [];

        foreach ($guestForm as $key => $value) {
            if (!\is_string($key) || (\is_array($value) && $key !== 'room_guests')) {
                continue;
            }

            if (\is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveCurrency(array $context): string
    {
        $currency = isset($context['currency'])
            ? \sanitize_text_field((string) $context['currency'])
            : '';

        if ($currency !== '') {
            return \strtoupper($currency);
        }

        return \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::get_currency()
            : 'USD';
    }
}
