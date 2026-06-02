<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;

final class ClockFolioPaymentSyncService
{
    /** @var ClockApiClient */
    private $client;

    public function __construct(?ClockApiClient $client = null)
    {
        $this->client = $client ?: new ClockApiClient(new ProviderRequestLogRepository());
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array{success: bool, skipped: bool, message: string, folio_id: string}
     */
    public function syncStripePaymentForReservation(array $reservation, string $transactionId): array
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        if ($reservationId <= 0 || (string) ($reservation['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => 'Reservation is not a Clock reservation.',
                'folio_id' => '',
            ];
        }

        $transactionId = \sanitize_text_field($transactionId);

        if ($transactionId === '') {
            return $this->storeResult(
                $reservation,
                false,
                '',
                '',
                'Stripe PaymentIntent/reference is missing.',
                ''
            );
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $idempotencyKey = $this->idempotencyKey($reservationId, $transactionId);

        $previous = isset($metadata['clock_folio_payment_sync']) && \is_array($metadata['clock_folio_payment_sync'])
            ? $metadata['clock_folio_payment_sync']
            : [];

        if (
            !empty($previous['success'])
            && (string) ($previous['idempotency_key'] ?? '') === $idempotencyKey
            && (string) ($previous['stripe_reference'] ?? '') === $transactionId
        ) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Clock folio payment was already synced.',
                'folio_id' => (string) ($previous['folio_id'] ?? ($metadata['clock_folio_id'] ?? '')),
            ];
        }

        $folioId = $this->firstString($metadata, ['clock_folio_id', 'folio_id', 'default_folio_id']);

        if ($folioId === '') {
            $folioResult = $this->fetchDefaultFolioId($reservation);

            if (empty($folioResult['success'])) {
                return $this->storeResult(
                    $reservation,
                    false,
                    '',
                    $idempotencyKey,
                    (string) ($folioResult['message'] ?? 'Unable to fetch Clock default folio.'),
                    $transactionId
                );
            }

            $folioId = (string) ($folioResult['folio_id'] ?? '');
        }

        if ($folioId === '') {
            return $this->storeResult(
                $reservation,
                false,
                '',
                $idempotencyKey,
                'Clock default folio response did not include a folio ID.',
                $transactionId
            );
        }

        $amount = $this->paymentAmountForReservation($reservationId, $reservation);

        if ($amount <= 0.0) {
            return $this->storeResult(
                $reservation,
                false,
                $folioId,
                $idempotencyKey,
                'Cannot post Clock folio payment because the paid amount is zero.',
                $transactionId
            );
        }

        $currency = $this->currencyForReservation($reservation);
        $paymentSubType = $this->paymentSubType();

        $body = [
            'credit_item' => [
                'payment_type' => 'on-line',
                'payment_sub_type' => $paymentSubType,
                'text' => 'Website booking payment via Stripe',
                'value' => $this->formatMoney($amount),
                'currency' => $currency,
                'reference' => $transactionId,
            ],
        ];

        $response = $this->client->request(
            'POST',
            '/folios/' . \rawurlencode($folioId) . '/credit_items',
            [
                'api_type' => 'base_api',
                'reservation_id' => $reservationId,
                'external_id' => $folioId,
                'idempotency_key' => $idempotencyKey,
                'body' => $body,
            ],
            'clock.folio_payment_create'
        );

        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== ''
                ? $response->getErrorMessage()
                : 'Clock folio payment create request failed.';

            return $this->storeResult(
                $reservation,
                false,
                $folioId,
                $idempotencyKey,
                $message,
                $transactionId
            );
        }

        return $this->storeResult(
            $reservation,
            true,
            $folioId,
            $idempotencyKey,
            '',
            $transactionId,
            $amount,
            $currency,
            $paymentSubType
        );
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array{success: bool, message: string, folio_id: string}
     */
    private function fetchDefaultFolioId(array $reservation): array
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        $bookingId = $this->clockBookingId($reservation);

        if ($bookingId === '') {
            return [
                'success' => false,
                'message' => 'Clock booking ID is missing.',
                'folio_id' => '',
            ];
        }

        $path = '/bookings/' . \rawurlencode($bookingId) . '/folios/default';

        $response = $this->client->request(
            'GET',
            $path,
            [
                'api_type' => 'pms_api',
                'reservation_id' => $reservationId,
                'external_id' => $bookingId,
            ],
            'clock.default_booking_folio_fetch'
        );

        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== ''
                ? $response->getErrorMessage()
                : 'Clock default booking folio fetch failed.';

            return [
                'success' => false,
                'message' => $message,
                'folio_id' => '',
            ];
        }

        $data = $response->getData();
        $folioId = \is_array($data) ? $this->extractFolioId($data) : '';

        if ($folioId === '') {
            return [
                'success' => false,
                'message' => 'Clock default booking folio response did not include an id field.',
                'folio_id' => '',
            ];
        }

        $this->storeFolioId($reservation, $folioId);

        return [
            'success' => true,
            'message' => '',
            'folio_id' => $folioId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractFolioId(array $data): string
    {
        foreach (['id', 'folio_id', 'default_folio_id'] as $key) {
            if (isset($data[$key]) && \trim((string) $data[$key]) !== '') {
                return \sanitize_text_field((string) $data[$key]);
            }
        }

        if (isset($data['folio']) && \is_array($data['folio'])) {
            return $this->extractFolioId($data['folio']);
        }

        if (isset($data['data']) && \is_array($data['data'])) {
            return $this->extractFolioId($data['data']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function clockBookingId(array $reservation): string
    {
        foreach (['provider_booking_id', 'provider_reservation_id'] as $key) {
            if (isset($reservation[$key]) && \trim((string) $reservation[$key]) !== '') {
                return \sanitize_text_field((string) $reservation[$key]);
            }
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);

        foreach (['provider_booking_id', 'provider_reservation_id', 'clock_booking_id', 'booking_id'] as $key) {
            if (isset($metadata[$key]) && \trim((string) $metadata[$key]) !== '') {
                return \sanitize_text_field((string) $metadata[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function storeFolioId(array $reservation, string $folioId): void
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        if ($reservationId <= 0 || $folioId === '') {
            return;
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);

        $metadata['clock_folio_id'] = $folioId;
        $metadata['clock_folio_id_source'] = 'default_booking_folios_view';
        $metadata['clock_folio_id_synced_at'] = $this->now();

        \MustHotelBooking\Engine\get_reservation_repository()->updateProviderMetadata($reservationId, [
            'provider_metadata' => $metadata,
            'provider_sync_status' => 'synced',
            'provider_synced_at' => $this->now(),
            'provider_sync_error' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array{success: bool, skipped: bool, message: string, folio_id: string}
     */
    private function storeResult(
        array $reservation,
        bool $success,
        string $folioId,
        string $idempotencyKey,
        string $message,
        string $transactionId,
        float $amount = 0.0,
        string $currency = '',
        string $paymentSubType = ''
    ): array {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        if ($reservationId > 0) {
            $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);

            if ($folioId !== '') {
                $metadata['clock_folio_id'] = $folioId;
                $metadata['clock_folio_id_source'] = 'default_booking_folios_view';
            }

            $metadata['clock_folio_payment_sync'] = [
                'success' => $success,
                'sync_status' => $success ? 'synced' : 'manual_review',
                'sync_error' => $message,
                'folio_id' => $folioId,
                'idempotency_key' => $idempotencyKey,
                'stripe_reference' => $transactionId,
                'amount' => $amount > 0.0 ? $this->formatMoney($amount) : '',
                'currency' => $currency,
                'payment_type' => 'on-line',
                'payment_sub_type' => $paymentSubType,
                'synced_at' => $this->now(),
            ];

            \MustHotelBooking\Engine\get_reservation_repository()->updateProviderMetadata($reservationId, [
                'provider_metadata' => $metadata,
                'provider_sync_status' => $success ? 'synced' : 'manual_review',
                'provider_synced_at' => $success ? $this->now() : null,
                'provider_sync_error' => $message,
            ]);
        }

        return [
            'success' => $success,
            'skipped' => false,
            'message' => $message,
            'folio_id' => $folioId,
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function paymentAmountForReservation(int $reservationId, array $reservation): float
    {
        if ($reservationId > 0) {
            $summary = \MustHotelBooking\Engine\get_payment_repository()->getReservationPaymentSummary($reservationId);

            if (\is_array($summary) && isset($summary['amount_paid'])) {
                $amount = \round((float) $summary['amount_paid'], 2);

                if ($amount > 0.0) {
                    return $amount;
                }
            }
        }

        foreach (['total_price', 'total', 'amount_total', 'grand_total'] as $key) {
            if (isset($reservation[$key])) {
                $amount = \round((float) $reservation[$key], 2);

                if ($amount > 0.0) {
                    return $amount;
                }
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function currencyForReservation(array $reservation): string
    {
        if (isset($reservation['currency']) && \trim((string) $reservation['currency']) !== '') {
            return \strtoupper(\sanitize_text_field((string) $reservation['currency']));
        }

        return \strtoupper(MustBookingConfig::get_currency());
    }

    private function paymentSubType(): string
    {
        $settings = MustBookingConfig::get_all_settings();
        $value = isset($settings['clock_stripe_payment_sub_type'])
            ? \trim((string) $settings['clock_stripe_payment_sub_type'])
            : '';

        return $value !== '' ? \sanitize_text_field($value) : 'Stripe';
    }

    /**
     * @param mixed $metadata
     * @return array<string, mixed>
     */
    private function decodeMetadata($metadata): array
    {
        if (\is_array($metadata)) {
            return $metadata;
        }

        if (!\is_string($metadata) || \trim($metadata) === '') {
            return [];
        }

        $decoded = \json_decode($metadata, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \trim((string) $source[$key]) !== '') {
                return \sanitize_text_field((string) $source[$key]);
            }
        }

        return '';
    }

    private function idempotencyKey(int $reservationId, string $transactionId): string
    {
        return 'clock-folio-payment-' . $reservationId . '-' . \sha1($transactionId);
    }

    private function formatMoney(float $amount): string
    {
        return \number_format(\round($amount, 2), 2, '.', '');
    }

    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}