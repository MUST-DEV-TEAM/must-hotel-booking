<?php

namespace MustHotelBooking\Engine\Payment;

final class CashPayment implements PaymentInterface
{
    private string $method;

    public function __construct(string $method = 'pay_at_hotel')
    {
        $this->method = \sanitize_key($method) ?: 'pay_at_hotel';
    }

    public function processPayment(array $reservation, float $amount, array $context = []): array
    {
        $reservationIds = $this->extractReservationIds($reservation);
        $validation = $this->validatePayment([
            'reservation_ids' => $reservationIds,
            'amount' => $amount,
        ]);

        if (empty($validation['success'])) {
            return $validation + ['method' => $this->method];
        }

        \MustHotelBooking\Engine\create_or_update_payment_rows($reservationIds, $this->method, 'pending');

        return [
            'success' => true,
            'method' => $this->method,
            'reservation_ids' => $reservationIds,
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'requires_redirect' => false,
            'redirect_url' => '',
        ];
    }

    public function refundPayment(array $reservation, float $amount, array $context = []): array
    {
        return [
            'success' => false,
            'method' => $this->method,
            'message' => \__('Cash payments must be refunded manually outside the plugin.', 'must-hotel-booking'),
        ];
    }

    public function validatePayment(array $paymentData = []): array
    {
        $reservationIds = $this->extractReservationIds($paymentData);

        if (isset($paymentData['reservation_ids']) && empty($reservationIds)) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('No reservations are available for this payment.', 'must-hotel-booking'),
            ];
        }

        if (isset($paymentData['amount']) && (float) $paymentData['amount'] < 0) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('Payment amount cannot be negative.', 'must-hotel-booking'),
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
}
