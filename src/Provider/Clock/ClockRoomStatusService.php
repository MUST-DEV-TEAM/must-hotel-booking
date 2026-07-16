<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockRoomStatusService
{
    /** @var ClockApiClient */
    private $client;

    public function __construct(?ClockApiClient $client = null)
    {
        $this->client = $client ?: new ClockApiClient();
    }

    /**
     * @return array{
     *     status: string,
     *     rooms: array<string, array{room_type_id: string, available: bool}>,
     *     reason: string,
     *     message: string
     * }
     */
    public function fetch(
        string $from,
        string $toInclusive,
        string $roomTypeExternalId = '',
        bool $bypassCache = false,
        string $operation = 'clock.room_statuses.check'
    ): array {
        $query = [
            'from' => $from,
            'to' => $toInclusive,
        ];

        if ($roomTypeExternalId !== '') {
            $query['room_type_id'] = $roomTypeExternalId;
        }

        $response = $this->client->get(
            ClockConfig::roomStatusesPath(),
            $query,
            $operation !== '' ? $operation : 'clock.room_statuses.check',
            [
                'api_type' => 'pms_api',
                'endpoint_name' => 'room_statuses',
                'cache_ttl' => 15,
                'timeout' => 8,
                'bypass_cache' => $bypassCache,
            ]
        );

        if (!$response->isSuccess()) {
            return $this->unconfirmed('request_failed', $response->getErrorMessage());
        }

        $rooms = $this->parseRooms($response->getData());
        if ($rooms === null) {
            return $this->unconfirmed('malformed_response');
        }

        if ($roomTypeExternalId !== '' && !$this->hasRoomTypeGroup($response->getData(), $roomTypeExternalId)) {
            return $this->unconfirmed('room_type_missing');
        }

        return [
            'status' => 'confirmed',
            'rooms' => $rooms,
            'reason' => '',
            'message' => '',
        ];
    }

    /**
     * @param mixed $data
     * @return array<string, array{room_type_id: string, available: bool}>|null
     */
    private function parseRooms($data): ?array
    {
        if (!\is_array($data) || !$this->isList($data)) {
            return null;
        }

        $parsed = [];

        foreach ($data as $group) {
            if (!\is_array($group)
                || !\array_key_exists('room_type_id', $group)
                || !\array_key_exists('rooms', $group)
                || !\is_array($group['rooms'])
                || !$this->isList($group['rooms'])) {
                return null;
            }

            $roomTypeId = $this->normalizeNumericId($group['room_type_id']);
            if ($roomTypeId === '') {
                return null;
            }

            foreach ($group['rooms'] as $room) {
                if (!\is_array($room)
                    || !\array_key_exists('id', $room)
                    || !\array_key_exists('available', $room)
                    || !\is_bool($room['available'])) {
                    return null;
                }

                $roomId = $this->normalizeNumericId($room['id']);
                if ($roomId === '') {
                    return null;
                }

                $row = [
                    'room_type_id' => $roomTypeId,
                    'available' => $room['available'],
                ];

                if (isset($parsed[$roomId]) && $parsed[$roomId] !== $row) {
                    return null;
                }

                $parsed[$roomId] = $row;
            }
        }

        return $parsed;
    }

    /** @param mixed $data */
    private function hasRoomTypeGroup($data, string $roomTypeExternalId): bool
    {
        if (!\is_array($data)) {
            return false;
        }

        foreach ($data as $group) {
            if (\is_array($group)
                && \array_key_exists('room_type_id', $group)
                && $this->normalizeNumericId($group['room_type_id']) === $roomTypeExternalId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    private function normalizeNumericId($value): string
    {
        if (!\is_int($value) && !\is_string($value)) {
            return '';
        }

        $value = (string) $value;

        return $value !== '' && \ctype_digit($value) ? $value : '';
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return \array_keys($value) === \range(0, \count($value) - 1) || $value === [];
    }

    /**
     * @return array{status: string, rooms: array<string, array{room_type_id: string, available: bool}>, reason: string, message: string}
     */
    private function unconfirmed(string $reason, string $message = ''): array
    {
        return [
            'status' => 'unconfirmed',
            'rooms' => [],
            'reason' => $reason,
            'message' => $message,
        ];
    }
}
