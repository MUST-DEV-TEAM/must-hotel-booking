<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;

final class ClockFolioPaymentSyncService
{
    public function __construct(?ClockApiClient $client = null)
    {
        unset($client);
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array{success: bool, skipped: bool, message: string, folio_id: string}
     */
    public function syncStripePaymentForReservation(array $reservation, string $transactionId): array
    {
        return $this->syncGatewayPaymentForReservation($reservation, $transactionId, 'stripe');
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array{success: bool, skipped: bool, message: string, folio_id: string}
     */
    public function syncGatewayPaymentForReservation(array $reservation, string $transactionId, string $gateway = 'stripe'): array
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        $gateway = \sanitize_key($gateway) ?: 'stripe';
        $transactionId = \sanitize_text_field($transactionId);

        if ($reservationId <= 0 || (string) ($reservation['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Reservation is not a Clock reservation.',
                'folio_id' => '',
            ];
        }

        if ($transactionId === '') {
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Provider payment/reference is missing.',
                'folio_id' => '',
            ];
        }

        foreach (\MustHotelBooking\Engine\get_payment_repository()->getPaymentsForReservation($reservationId) as $paymentRow) {
            if (
                \is_array($paymentRow)
                && (int) ($paymentRow['id'] ?? 0) > 0
                && \sanitize_key((string) ($paymentRow['method'] ?? '')) === $gateway
                && \sanitize_key((string) ($paymentRow['status'] ?? '')) === 'paid'
                && (string) ($paymentRow['transaction_id'] ?? '') === $transactionId
            ) {
                $result = (new ClockPaymentAccountingService())->syncPaidPayment((int) $paymentRow['id']);

                return [
                    'success' => !empty($result['success']),
                    'skipped' => !empty($result['success']) && \in_array((string) ($result['message'] ?? ''), ['already_posted', 'not_required'], true),
                    'message' => (string) ($result['message'] ?? ''),
                    'folio_id' => '',
                ];
            }
        }

        return [
            'success' => false,
            'skipped' => true,
            'message' => 'Clock folio accounting waits for a recorded paid payment row.',
            'folio_id' => '',
        ];
    }
}
