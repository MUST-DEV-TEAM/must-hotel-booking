<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Database\RefundRepository;

final class ClockFolioRefundSyncService
{
    /** @var RefundRepository */
    private $refunds;
    /** @var ClockApiClient */
    private $client;

    public function __construct(?RefundRepository $refunds = null, ?ClockApiClient $client = null)
    {
        $this->refunds = $refunds ?: \MustHotelBooking\Engine\get_refund_repository();
        $this->client = $client ?: new ClockApiClient();
    }

    /** @param array<string, mixed> $refund @return array<string, mixed> */
    public function syncRefund(array $refund): array
    {
        $refundId = (int) ($refund['id'] ?? 0);
        $folioId = \sanitize_text_field((string) ($refund['clock_folio_id'] ?? ''));

        if ($refundId <= 0 || $folioId === '') {
            return ['success' => false, 'message' => \__('Clock folio refund sync is missing a refund or folio ID.', 'must-hotel-booking')];
        }

        if ((string) ($refund['clock_refund_item_id'] ?? '') !== '') {
            return ['success' => true, 'message' => 'already_synced'];
        }

        $idempotencyKey = (string) ($refund['clock_idempotency_key'] ?? ('SOVES-REFUND-' . $refundId));
        $this->refunds->updateRefund($refundId, [
            'clock_sync_status' => 'retrying',
            'updated_at' => \current_time('mysql'),
        ]);

        $description = $this->description($refund);
        $amount = -1 * \abs((float) ($refund['amount'] ?? 0.0));
        $body = [
            'credit_item' => [
                'text' => $description,
                'value' => \round($amount, 2),
                'currency' => (string) ($refund['currency'] ?? ''),
                'reference' => $idempotencyKey,
            ],
        ];

        $response = $this->client->request(
            'POST',
            '/folios/' . \rawurlencode($folioId) . '/credit_items',
            [
                'api_type' => 'base_api',
                'idempotency_key' => $idempotencyKey,
                'reservation_id' => (int) ($refund['reservation_id'] ?? 0),
                'external_id' => $folioId,
                'body' => $body,
            ],
            'clock.refund_credit_item_create'
        );

        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== '' ? $response->getErrorMessage() : \__('Clock refund sync failed.', 'must-hotel-booking');
            $this->refunds->updateRefund($refundId, [
                'clock_sync_status' => 'failed',
                'failed_reason' => $message,
                'updated_at' => \current_time('mysql'),
            ]);

            return ['success' => false, 'message' => $message];
        }

        $data = $response->getData();
        $itemId = $this->firstString(\is_array($data) ? $data : [], ['id', 'credit_item_id', 'payment_id']);
        $this->refunds->updateRefund($refundId, [
            'clock_refund_item_id' => $itemId !== '' ? $itemId : $idempotencyKey,
            'clock_sync_status' => 'synced',
            'failed_reason' => '',
            'updated_at' => \current_time('mysql'),
        ]);

        return ['success' => true, 'message' => 'synced'];
    }

    /** @param array<string, mixed> $refund */
    private function description(array $refund): string
    {
        $parts = [
            'Soves Stripe refund',
            'Soves Refund ID: ' . (string) ($refund['id'] ?? ''),
            'Stripe Refund ID: ' . (string) ($refund['stripe_refund_id'] ?? ''),
            'Stripe PaymentIntent ID: ' . (string) ($refund['stripe_payment_intent_id'] ?? ''),
            'Original Stripe Charge ID: ' . (string) ($refund['stripe_charge_id'] ?? ''),
            'Soves Booking ID: ' . (string) ($refund['booking_id'] ?? ''),
        ];

        if ((string) ($refund['reason'] ?? '') !== '') {
            $parts[] = 'Reason: ' . (string) $refund['reason'];
        }

        return \implode("\n", \array_filter($parts));
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \trim((string) $source[$key]) !== '') {
                return \sanitize_text_field((string) $source[$key]);
            }
        }

        return '';
    }
}
