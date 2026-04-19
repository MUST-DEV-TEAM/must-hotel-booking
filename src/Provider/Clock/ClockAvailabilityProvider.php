<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\DisabledDatesRequest;

final class ClockAvailabilityProvider implements AvailabilityProviderInterface
{
    /** @var ClockApiClient */
    private $client;

    /** @var ClockCatalogService */
    private $catalog;

    public function __construct(?ClockApiClient $client = null, ?ClockCatalogService $catalog = null)
    {
        $this->client = $client ?: new ClockApiClient();
        $this->catalog = $catalog ?: new ClockCatalogService($this->client);
    }

    public function getAvailableRooms(AvailabilitySearchRequest $request): array
    {
        if (!ClockConfig::isPublicBookingConfigured() || !$this->isValidStay($request->getCheckin(), $request->getCheckout())) {
            return [];
        }

        $candidates = \MustHotelBooking\Engine\get_room_repository()->getRoomsByType($request->getCategory(), $request->getGuests());
        $mapped = $this->mappedRooms($candidates);

        if (empty($mapped)) {
            return [];
        }

        $response = $this->client->request(
            'POST',
            ClockConfig::availabilityPath(),
            [
                'body' => [
                    'property_id' => ClockConfig::propertyId(),
                    'checkin' => $request->getCheckin(),
                    'checkout' => $request->getCheckout(),
                    'guests' => $request->getGuests(),
                    'category' => $request->getCategory(),
                    'accommodations' => $this->providerRoomPayloads($mapped),
                ],
            ],
            'clock.availability.search'
        );

        if (!$response->isSuccess()) {
            return [];
        }

        $items = $this->extractItems($response->getData());
        $available = [];

        foreach ($mapped as $row) {
            $room = isset($row['room']) && \is_array($row['room']) ? $row['room'] : [];
            $mapping = isset($row['mapping']) && \is_array($row['mapping']) ? $row['mapping'] : [];
            $availability = $this->findAvailabilityForMapping($items, $mapping, \count($mapped) === 1 ? $response->getData() : null);

            if (!$this->isAvailable($availability)) {
                continue;
            }

            $room['available_count'] = $this->availableCount($availability);
            $room['provider'] = 'clock';
            $room['provider_mapping_id'] = isset($mapping['id']) ? (int) $mapping['id'] : 0;
            $room['provider_room_id'] = (string) ($mapping['external_id'] ?? '');
            $available[] = $room;
        }

        return $available;
    }

    public function getAvailableRoomById(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array
    {
        $room = \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId);

        if (!\is_array($room)) {
            return null;
        }

        $maxGuests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

        if ($maxGuests > 0 && \max(1, $guests) > $maxGuests) {
            return null;
        }

        if (!$this->checkAvailability($roomId, $checkin, $checkout)) {
            return null;
        }

        $mapping = $this->catalog->findAccommodationMapping($roomId);
        $room['available_count'] = 1;
        $room['provider'] = 'clock';
        $room['provider_mapping_id'] = \is_array($mapping) && isset($mapping['id']) ? (int) $mapping['id'] : 0;
        $room['provider_room_id'] = \is_array($mapping) ? (string) ($mapping['external_id'] ?? '') : '';

        return $room;
    }

    public function getDisabledDates(DisabledDatesRequest $request): array
    {
        unset($request);

        return [
            'disabled_checkin_dates' => [],
            'disabled_checkout_dates' => [],
        ];
    }

    public function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        unset($excludeSessionId);

        if (!ClockConfig::isPublicBookingConfigured() || !$this->isValidStay($checkin, $checkout)) {
            return false;
        }

        $room = \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId);
        $mapping = $this->catalog->findAccommodationMapping($roomId);

        if (!\is_array($room) || !$this->hasExternalId($mapping)) {
            return false;
        }

        $response = $this->client->request(
            'POST',
            ClockConfig::availabilityPath(),
            [
                'body' => [
                    'property_id' => ClockConfig::propertyId(),
                    'checkin' => $checkin,
                    'checkout' => $checkout,
                    'guests' => 1,
                    'accommodations' => $this->providerRoomPayloads([
                        [
                            'room' => $room,
                            'mapping' => $mapping,
                        ],
                    ]),
                ],
            ],
            'clock.availability.check'
        );

        if (!$response->isSuccess()) {
            return false;
        }

        $availability = $this->findAvailabilityForMapping($this->extractItems($response->getData()), $mapping, $response->getData());

        return $this->isAvailable($availability);
    }

    public function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool
    {
        if (!$this->isValidStay($checkin, $checkout)) {
            return false;
        }

        return $roomId > 0 && $this->hasExternalId($this->catalog->findAccommodationMapping($roomId));
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<int, array{room: array<string, mixed>, mapping: array<string, mixed>}>
     */
    private function mappedRooms(array $rooms): array
    {
        $mapped = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $mapping = $this->catalog->findAccommodationMapping($roomId);

            if (!$this->hasExternalId($mapping)) {
                continue;
            }

            $mapped[] = [
                'room' => $room,
                'mapping' => $mapping,
            ];
        }

        return $mapped;
    }

    /** @param array<int, array{room: array<string, mixed>, mapping: array<string, mixed>}> $mapped */
    private function providerRoomPayloads(array $mapped): array
    {
        $payloads = [];

        foreach ($mapped as $row) {
            $room = $row['room'];
            $mapping = $row['mapping'];
            $payloads[] = [
                'local_room_id' => isset($room['id']) ? (int) $room['id'] : 0,
                'provider_room_id' => (string) ($mapping['external_id'] ?? ''),
                'provider_room_code' => (string) ($mapping['external_code'] ?? ''),
                'name' => (string) ($room['name'] ?? ''),
            ];
        }

        return $payloads;
    }

    /** @param mixed $data @return array<int, array<string, mixed>> */
    private function extractItems($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if (\array_values($data) === $data) {
            return $this->onlyArrayItems($data);
        }

        foreach (['available_rooms', 'rooms', 'accommodations', 'availability', 'items', 'data', 'results'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->onlyArrayItems($data[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function onlyArrayItems(array $items): array
    {
        $out = [];

        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }

            if (\is_scalar($key) && !isset($item['id'])) {
                $item['_response_key'] = (string) $key;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $mapping
     * @param mixed $singleResponse
     * @return array<string, mixed>|null
     */
    private function findAvailabilityForMapping(array $items, array $mapping, $singleResponse): ?array
    {
        foreach ($items as $item) {
            if ($this->matchesMapping($item, $mapping)) {
                return $item;
            }
        }

        return \is_array($singleResponse) && $this->availabilitySignal($singleResponse) !== null
            ? $singleResponse
            : null;
    }

    /** @param array<string, mixed>|null $availability */
    private function isAvailable(?array $availability): bool
    {
        return $availability !== null && $this->availabilitySignal($availability) === true;
    }

    /** @param array<string, mixed> $availability */
    private function availableCount(array $availability): int
    {
        foreach (['available_count', 'rooms_available', 'units_available', 'count', 'quantity'] as $key) {
            if (isset($availability[$key]) && \is_numeric($availability[$key])) {
                return \max(1, (int) $availability[$key]);
            }
        }

        return 1;
    }

    /** @param array<string, mixed> $availability */
    private function availabilitySignal(array $availability): ?bool
    {
        foreach (['available', 'is_available', 'bookable'] as $key) {
            if (\array_key_exists($key, $availability)) {
                return $this->truthy($availability[$key]);
            }
        }

        foreach (['available_count', 'rooms_available', 'units_available', 'count', 'quantity'] as $key) {
            if (isset($availability[$key]) && \is_numeric($availability[$key])) {
                return (int) $availability[$key] > 0;
            }
        }

        foreach (['status', 'state'] as $key) {
            if (isset($availability[$key]) && \is_scalar($availability[$key])) {
                $status = \sanitize_key((string) $availability[$key]);

                if (\in_array($status, ['available', 'bookable', 'open', 'ok'], true)) {
                    return true;
                }

                if (\in_array($status, ['unavailable', 'closed', 'sold_out', 'sold-out', 'not_available'], true)) {
                    return false;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $mapping */
    private function matchesMapping(array $item, array $mapping): bool
    {
        $externalId = (string) ($mapping['external_id'] ?? '');
        $externalCode = (string) ($mapping['external_code'] ?? '');

        foreach (['id', 'external_id', 'room_type_id', 'room_id', 'accommodation_id', 'resource_id', 'unit_group_id', '_response_key'] as $key) {
            if (isset($item[$key]) && \is_scalar($item[$key]) && $externalId !== '' && (string) $item[$key] === $externalId) {
                return true;
            }
        }

        foreach (['code', 'room_code', 'external_code'] as $key) {
            if (isset($item[$key]) && \is_scalar($item[$key]) && $externalCode !== '' && (string) $item[$key] === $externalCode) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $value */
    private function truthy($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_numeric($value)) {
            return (int) $value > 0;
        }

        return \in_array(\strtolower(\trim((string) $value)), ['1', 'true', 'yes', 'available', 'bookable', 'ok'], true);
    }

    /** @param array<string, mixed>|null $mapping */
    private function hasExternalId(?array $mapping): bool
    {
        return \is_array($mapping) && (string) ($mapping['external_id'] ?? '') !== '';
    }

    private function isValidStay(string $checkin, string $checkout): bool
    {
        return AvailabilityEngine::isValidBookingDate($checkin)
            && AvailabilityEngine::isValidBookingDate($checkout)
            && $checkin < $checkout;
    }
}
