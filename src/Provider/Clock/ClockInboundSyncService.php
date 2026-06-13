<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;

final class ClockInboundSyncService
{
    /** @var ClockApiClient */
    private $client;

    /** @var ProviderSyncJobRepository */
    private $syncJobs;

    public function __construct(?ClockApiClient $client = null, ?ProviderSyncJobRepository $syncJobs = null)
    {
        $this->client = $client ?: new ClockApiClient();
        $this->syncJobs = $syncJobs ?: new ProviderSyncJobRepository();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function processInboundPayload(array $payload, string $eventId = ''): array
    {
        $eventType = $this->eventType($payload);
        $source = $this->reservationEventSource($payload);
        $bookingId = $this->firstString($source, ['booking_id', 'id']);
        $reservationId = $this->firstString($source, ['reservation_id']);

        if (!$this->isBookingEvent($eventType) || ($bookingId === '' && $reservationId === '')) {
            return $this->result(false, 202, \__('Clock webhook payload was authenticated and logged, but this event type is not handled by the reservation sync adapter.', 'must-hotel-booking'), [
                'unsupported' => true,
                'event_type' => $eventType,
            ]);
        }

        $rows = \MustHotelBooking\Engine\get_reservation_repository()->getProviderReservationRowsByExternalIds(
            ProviderManager::CLOCK_MODE,
            $reservationId !== '' ? $reservationId : $bookingId,
            $bookingId
        );

        if (empty($rows)) {
            $externalId = $bookingId !== '' ? $bookingId : $reservationId;
            $upsert = (new ClockReservationSyncService($this->client))->refreshBookingById($externalId, 'webhook_upsert');

            if (!empty($upsert['success'])) {
                return $this->result(true, 200, '', [
                    'event_type' => $eventType,
                    'provider_booking_id' => $bookingId,
                    'provider_reservation_id' => $reservationId,
                    'reservation_ids' => !empty($upsert['reservation_id']) ? [(int) $upsert['reservation_id']] : [],
                    'created_count' => !empty($upsert['created']) ? 1 : 0,
                    'updated_count' => !empty($upsert['updated']) ? 1 : 0,
                ]);
            }

            return $this->result(false, 202, (string) ($upsert['message'] ?? \__('Clock booking event was received, but no local mirror reservation could be created or updated.', 'must-hotel-booking')), [
                'unsupported' => true,
                'event_type' => $eventType,
                'provider_booking_id' => $bookingId,
                'provider_reservation_id' => $reservationId,
            ]);
        }

        $fetchPath = ClockConfig::reservationFetchPath();

        if ($fetchPath !== '') {
            $externalRow = $rows[0];
            $resolvedPath = $this->applyPathTokens($fetchPath, $externalRow);

            if ($resolvedPath !== '') {
                $response = $this->client->request(
                    'GET',
                    $resolvedPath,
                    [
                        'external_id' => $bookingId !== '' ? $bookingId : $reservationId,
                    ],
                    'clock.reservation_webhook_refresh'
                );

                if ($response->isSuccess()) {
                    $freshSource = $this->reservationSource($response->getData());

                    if (!empty($freshSource)) {
                        return $this->applyReservationPayloadToRows($rows, $freshSource, \is_array($response->getData()) ? $response->getData() : [], $eventId, 'webhook_refresh');
                    }
                }
            }
        }

        $result = $this->applyReservationPayloadToRows($rows, $source, $payload, $eventId, 'webhook');

        foreach ($rows as $row) {
            $reservationLocalId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($reservationLocalId > 0 && $fetchPath !== '') {
                $this->enqueueReservationRefresh($reservationLocalId, 'webhook');
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{success: bool, retry: bool, message: string}
     */
    public function executeRefreshJob(array $job): array
    {
        $reservationId = isset($job['target_local_id']) ? (int) $job['target_local_id'] : 0;

        if ($reservationId <= 0) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock refresh job is missing a local reservation ID.', 'must-hotel-booking'),
            ];
        }

        $rows = \MustHotelBooking\Engine\get_reservation_repository()->getProviderReservationRowsByIds([$reservationId]);
        $row = isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : [];

        if (empty($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock refresh job target is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }

        $path = ClockConfig::reservationFetchPath();

        if ($path === '') {
            return [
                'success' => false,
                'retry' => true,
                'message' => \__('Clock reservation fetch endpoint is not configured.', 'must-hotel-booking'),
            ];
        }

        $externalId = $this->externalId($row);
        $resolvedPath = $this->applyPathTokens($path, $row);

        if ($resolvedPath === '') {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock reservation refresh path contains an unresolved token; the reservation may be missing a required provider identifier.', 'must-hotel-booking'),
            ];
        }

        $response = $this->client->request(
            'GET',
            $resolvedPath,
            [
                'reservation_id' => $reservationId,
                'external_id' => $externalId,
            ],
            'clock.reservation_refresh'
        );

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'retry' => true,
                'message' => $response->getErrorMessage() !== '' ? $response->getErrorMessage() : \__('Clock reservation refresh failed.', 'must-hotel-booking'),
            ];
        }

        $source = $this->reservationSource($response->getData());

        if (empty($source)) {
            return [
                'success' => false,
                'retry' => true,
                'message' => \__('Clock reservation refresh response did not include reservation data.', 'must-hotel-booking'),
            ];
        }

        $result = $this->applyReservationPayloadToRows([$row], $source, \is_array($response->getData()) ? $response->getData() : [], '', 'refresh_job');

        return [
            'success' => !empty($result['success']),
            'retry' => empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    public function enqueueReservationRefresh(int $reservationId, string $source = 'manual'): int
    {
        if ($reservationId <= 0) {
            return 0;
        }

        $rows = \MustHotelBooking\Engine\get_reservation_repository()->getProviderReservationRowsByIds([$reservationId]);
        $row = isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : [];

        if (empty($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return 0;
        }

        return $this->syncJobs->enqueueOnce([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => 'reservation_refresh',
            'target_type' => 'reservation',
            'target_local_id' => $reservationId,
            'target_external_id' => $this->externalId($row),
            'status' => ProviderSyncJobRepository::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 5,
            'priority' => 7,
            'payload' => [
                'source' => \sanitize_key($source),
                'local_reservation_id' => $reservationId,
                'provider_booking_id' => (string) ($row['provider_booking_id'] ?? ''),
                'provider_reservation_id' => (string) ($row['provider_reservation_id'] ?? ''),
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $source
     * @param array<string, mixed> $rawPayload
     * @return array<string, mixed>
     */
    private function applyReservationPayloadToRows(array $rows, array $source, array $rawPayload, string $eventId, string $sourceType): array
    {
        $providerStatus = $this->firstString($source, ['provider_status', 'reservation_status', 'booking_status', 'status', 'state']);
        $providerPaymentStatus = $this->firstString($source, ['provider_payment_status', 'payment_status', 'payment_state']);
        $localStatus = $this->mapReservationStatus($providerStatus);
        $localPaymentStatus = $this->mapPaymentStatus($providerPaymentStatus);
        $updatedIds = [];
        $now = $this->now();

        foreach ($rows as $row) {
            if (!\is_array($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
                continue;
            }

            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($reservationId <= 0) {
                continue;
            }

            $metadata = $this->decodeMetadata($row['provider_metadata'] ?? null);
            $metadata['last_inbound_sync'] = [
                'event_id' => $eventId,
                'source' => $sourceType,
                'provider_status' => $providerStatus,
                'provider_payment_status' => $providerPaymentStatus,
                'applied_local_status' => $localStatus,
                'applied_local_payment_status' => $localPaymentStatus,
                'payload_summary' => $this->payloadSummary($source, $rawPayload),
                'synced_at' => $now,
            ];

            \MustHotelBooking\Engine\get_reservation_repository()->updateProviderMetadata($reservationId, [
                'provider_status' => $providerStatus !== '' ? $providerStatus : (string) ($row['provider_status'] ?? ''),
                'provider_payment_status' => $providerPaymentStatus !== '' ? $providerPaymentStatus : (string) ($row['provider_payment_status'] ?? ''),
                'provider_sync_status' => 'synced',
                'provider_synced_at' => $now,
                'provider_sync_error' => '',
                'provider_metadata' => $metadata,
            ]);

            if ($localStatus !== '' || $localPaymentStatus !== '') {
                $targetStatus = $localStatus !== '' ? $localStatus : (string) ($row['status'] ?? '');
                $targetPaymentStatus = $this->safeLocalPaymentStatus((string) ($row['payment_status'] ?? ''), $localPaymentStatus);
                (new \MustHotelBooking\Engine\BookingLifecycleSyncService())->applyReservationStatusTransition(
                    $reservationId,
                    $targetStatus,
                    $targetPaymentStatus,
                    [
                        'source' => $sourceType === 'refresh_job' ? 'clock_refresh' : 'clock_webhook',
                        'operation' => $localStatus === 'cancelled' ? 'cancel_only' : 'status_transition',
                        'event_id' => $eventId,
                        'idempotency_key' => $eventId !== '' ? 'clock-inbound-' . $eventId : '',
                    ]
                );
            }

            $updatedIds[] = $reservationId;
        }

        if (empty($updatedIds)) {
            return $this->result(false, 422, \__('Clock inbound sync did not update any local mirror reservations.', 'must-hotel-booking'));
        }

        return $this->result(true, 200, '', [
            'reservation_ids' => $updatedIds,
            'updated_count' => \count($updatedIds),
        ]);
    }

    /**
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function reservationSource($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        foreach (['reservation', 'booking', 'object', 'data', 'result'] as $key) {
            if (!isset($data[$key])) {
                continue;
            }

            $nested = $data[$key];

            if (\is_array($nested) && (isset($nested['reservation']) || isset($nested['booking']))) {
                $resolved = $this->reservationSource($nested);

                if (!empty($resolved)) {
                    return $resolved;
                }
            }

            if (\is_array($nested)) {
                return $nested;
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function reservationEventSource(array $payload): array
    {
        $message = $payload['Message'] ?? $payload['message'] ?? null;

        if (\is_string($message) && \trim($message) !== '') {
            $decoded = \json_decode($message, true);

            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->reservationSource($payload);
    }

    /** @param array<string, mixed> $payload */
    private function eventType(array $payload): string
    {
        return $this->firstString($payload, ['Subject', 'subject', 'event_type', 'type', 'topic', 'action']);
    }

    private function isBookingEvent(string $eventType): bool
    {
        $eventType = $this->normalizeStatus($eventType);

        return \strpos($eventType, 'booking_') === 0 || \strpos($eventType, 'reservation_') === 0;
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                return \sanitize_text_field((string) $source[$key]);
            }
        }

        return '';
    }

    private function mapReservationStatus(string $providerStatus): string
    {
        $status = $this->normalizeStatus($providerStatus);
        $map = [
            'confirmed' => 'confirmed',
            'booked' => 'confirmed',
            'active' => 'confirmed',
            'completed' => 'completed',
            'checked_out' => 'completed',
            'departed' => 'completed',
            'pending' => 'pending',
            'tentative' => 'pending',
            'provisional' => 'pending',
            'hold' => 'pending',
            'pending_payment' => 'pending_payment',
            'awaiting_payment' => 'pending_payment',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'void' => 'cancelled',
            'deleted' => 'cancelled',
            'expired' => 'expired',
            'payment_failed' => 'payment_failed',
            'failed' => 'payment_failed',
        ];

        return $map[$status] ?? '';
    }

    private function mapPaymentStatus(string $providerPaymentStatus): string
    {
        $status = $this->normalizeStatus($providerPaymentStatus);
        $map = [
            'paid' => 'paid',
            'settled' => 'paid',
            'captured' => 'paid',
            'partially_paid' => 'partially_paid',
            'partial' => 'partially_paid',
            'pending' => 'pending',
            'authorized' => 'pending',
            'requires_payment' => 'pending',
            'unpaid' => 'unpaid',
            'failed' => 'failed',
            'declined' => 'failed',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
            'void' => 'cancelled',
        ];

        return $map[$status] ?? '';
    }

    private function normalizeStatus(string $status): string
    {
        return \sanitize_key((string) \str_replace([' ', '-'], '_', \strtolower(\trim($status))));
    }

    private function safeLocalPaymentStatus(string $current, string $incoming): string
    {
        if ($incoming === '') {
            return '';
        }

        if ($current === 'paid' && \in_array($incoming, ['pending', 'unpaid'], true)) {
            return '';
        }

        return $incoming;
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

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $rawPayload
     * @return array<string, mixed>
     */
    private function payloadSummary(array $source, array $rawPayload): array
    {
        $summary = [
            'event_type' => $this->firstString($rawPayload, ['event_type', 'type', 'topic', 'action']),
            'keys' => \array_slice(\array_keys($source), 0, 20),
        ];

        foreach (['id', 'booking_id', 'reservation_id', 'confirmation_number', 'reference', 'status', 'state', 'payment_status'] as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                $summary[$key] = \sanitize_text_field((string) $source[$key]);
            }
        }

        return $summary;
    }

    /** @param array<string, mixed> $row */
    private function externalId(array $row): string
    {
        $reservationId = (string) ($row['provider_reservation_id'] ?? '');

        return $reservationId !== '' ? $reservationId : (string) ($row['provider_booking_id'] ?? '');
    }

    /** @param array<string, mixed> $row */
    private function applyPathTokens(string $path, array $row): string
    {
        $resolved = \strtr($path, [
            '{reservation_id}' => \rawurlencode((string) ($row['provider_reservation_id'] ?? '')),
            '{booking_id}' => \rawurlencode((string) ($row['provider_booking_id'] ?? '')),
        ]);

        return \preg_match('/\{[a-z_]+\}/i', $resolved) === 1 ? '' : $resolved;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function result(bool $success, int $status, string $message = '', array $extra = []): array
    {
        return \array_merge([
            'success' => $success,
            'status' => $status,
            'message' => $message,
        ], $extra);
    }

    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
