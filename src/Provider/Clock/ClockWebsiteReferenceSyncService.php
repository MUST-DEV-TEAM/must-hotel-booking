<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;

final class ClockWebsiteReferenceSyncService
{
    /** @var ClockApiClient */
    private $client;

    public function __construct(?ClockApiClient $client = null)
    {
        $this->client = $client ?: new ClockApiClient();
    }

    /** @return array{success: bool, message: string} */
    public function syncReservationReference(int $reservationId, string $source = 'admin'): array
    {
        $repository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation = $repository->getReservation($reservationId);

        if (!\is_array($reservation) || (string) ($reservation['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return $this->result(false, \__('Clock reservation was not found.', 'must-hotel-booking'));
        }

        $websiteReference = \trim((string) ($reservation['booking_id'] ?? ''));
        $clockBookingId = $this->clockBookingId($reservation);

        if ($websiteReference === '' || $clockBookingId === '') {
            return $this->recordResult(
                $reservation,
                false,
                \__('Website or Clock booking reference is missing.', 'must-hotel-booking'),
                $source,
                []
            );
        }

        $remote = $this->fetchBooking($clockBookingId, $reservationId);

        if (empty($remote['success'])) {
            return $this->recordResult($reservation, false, (string) ($remote['message'] ?? ''), $source, []);
        }

        $remoteBooking = isset($remote['booking']) && \is_array($remote['booking']) ? $remote['booking'] : [];
        $note = $this->firstString($remoteBooking, ['note', 'notes', 'client_requests']);
        $payload = ClockBookingReferenceMapper::applyWebsiteReferenceToPayload(
            ['booking' => ['note' => $note]],
            $websiteReference,
            'reference_number'
        );
        $idempotencyKey = 'mhb-clock-website-ref-' . \substr(\hash('sha256', $reservationId . '|' . $clockBookingId . '|' . $websiteReference), 0, 48);
        $response = $this->client->request(
            'PUT',
            '/bookings/' . \rawurlencode($clockBookingId),
            [
                'body' => $payload['payload'],
                'idempotency_key' => $idempotencyKey,
                'reservation_id' => $reservationId,
                'external_id' => $clockBookingId,
                'booking_reference' => $websiteReference,
                'api_type' => 'pms_api',
                'endpoint_name' => 'booking_update',
            ],
            'clock.booking.website_reference_sync'
        );

        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== ''
                ? $response->getErrorMessage()
                : \__('Clock rejected the website booking reference update.', 'must-hotel-booking');

            return $this->recordResult($reservation, false, $message, $source, $payload);
        }

        return $this->recordResult(
            $reservation,
            true,
            \__('Website booking reference was sent to Clock.', 'must-hotel-booking'),
            $source,
            $payload
        );
    }

    /** @param array<string, mixed> $reservation */
    private function clockBookingId(array $reservation): string
    {
        foreach (['provider_booking_id', 'provider_reservation_id', 'provider_payload_ref'] as $key) {
            $value = isset($reservation[$key]) ? \trim((string) $reservation[$key]) : '';

            if ($value !== '') {
                return $value;
            }
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);

        return isset($metadata['clock_booking_id']) ? \trim((string) $metadata['clock_booking_id']) : '';
    }

    /** @return array{success: bool, booking?: array<string, mixed>, message?: string} */
    private function fetchBooking(string $clockBookingId, int $reservationId): array
    {
        $path = ClockConfig::reservationFetchPath();
        $path = $path !== '' ? $path : '/bookings/{booking_id}';
        $path = \str_replace(
            ['{booking_id}', '{reservation_id}', ':booking_id', ':reservation_id'],
            \rawurlencode($clockBookingId),
            $path
        );
        $response = $this->client->get(
            $path,
            [],
            'clock.booking.website_reference_reread',
            [
                'reservation_id' => $reservationId,
                'external_id' => $clockBookingId,
                'api_type' => 'pms_api',
                'endpoint_name' => 'booking_view',
                'bypass_cache' => true,
            ]
        );

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => $response->getErrorMessage() !== ''
                    ? $response->getErrorMessage()
                    : \__('Unable to reread the Clock booking before updating the website reference.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'booking' => $this->reservationSource($response->getData()),
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $payload
     * @return array{success: bool, message: string}
     */
    private function recordResult(array $reservation, bool $success, string $message, string $source, array $payload): array
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $now = \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
        $metadata['website_booking_reference'] = (string) ($reservation['booking_id'] ?? '');
        $metadata['website_reference_sent_to_clock'] = $success;
        $metadata['clock_reference_storage_field'] = (string) ($payload['primary_field'] ?? ($metadata['clock_reference_storage_field'] ?? ''));
        $metadata['clock_reference_fallback_fields'] = isset($payload['fallback_fields']) && \is_array($payload['fallback_fields'])
            ? $payload['fallback_fields']
            : (isset($metadata['clock_reference_fallback_fields']) && \is_array($metadata['clock_reference_fallback_fields']) ? $metadata['clock_reference_fallback_fields'] : []);
        $metadata['last_clock_reference_sync'] = [
            'success' => $success,
            'sync_status' => $success ? 'synced' : 'failed',
            'synced_at' => $now,
            'source' => $source,
            'storage_field' => (string) ($metadata['clock_reference_storage_field'] ?? ''),
            'fallback_fields' => $metadata['clock_reference_fallback_fields'],
            'sync_error' => $success ? '' : $message,
        ];

        if ($reservationId > 0) {
            \MustHotelBooking\Engine\get_reservation_repository()->updateProviderMetadata($reservationId, [
                'provider_metadata' => $metadata,
                'provider_sync_status' => $success ? 'synced' : 'manual_review',
                'provider_sync_error' => $success ? '' : $message,
                'provider_synced_at' => $success ? $now : ($reservation['provider_synced_at'] ?? null),
            ]);

            \MustHotelBooking\Engine\get_activity_repository()->createActivity([
                'event_type' => $success ? 'clock_website_reference_sent' : 'clock_website_reference_failed',
                'severity' => $success ? 'info' : 'warning',
                'entity_type' => 'reservation',
                'entity_id' => $reservationId,
                'reference' => (string) ($reservation['booking_id'] ?? ''),
                'message' => $message,
                'context_json' => \wp_json_encode([
                    'reservation_id' => $reservationId,
                    'booking_id' => (string) ($reservation['booking_id'] ?? ''),
                    'clock_booking_id' => $this->clockBookingId($reservation),
                    'source' => $source,
                ]),
            ]);
        }

        return $this->result($success, $message);
    }

    /** @return array{success: bool, message: string} */
    private function result(bool $success, string $message): array
    {
        return [
            'success' => $success,
            'message' => $message,
        ];
    }

    /** @param mixed $data @return array<string, mixed> */
    private function reservationSource($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        foreach (['reservation', 'booking', 'data', 'result'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $data[$key];
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys): string
    {
        return ClockBookingReferenceMapper::firstString($source, $keys);
    }

    /** @param mixed $metadata */
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
}
