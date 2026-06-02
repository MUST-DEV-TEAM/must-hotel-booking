<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;

final class ClockRoomSelection
{
    /** @var ClockCatalogService */
    private $catalog;

    /** @var ProviderMappingRepository */
    private $mappings;

    public function __construct(?ClockCatalogService $catalog = null, ?ProviderMappingRepository $mappings = null)
    {
        $this->catalog = $catalog ?: new ClockCatalogService();
        $this->mappings = $mappings ?: new ProviderMappingRepository();
    }

    /** @return array<string, mixed>|null */
    public function resolve(int $roomId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }

        $physical = $this->physicalRoomSelection($roomId);

        if (\is_array($physical)) {
            return $physical;
        }

        return $this->roomTypeSelection($roomId);
    }

    /** @return array<string, mixed>|null */
    public function resolvePhysicalByExternalId(string $externalId): ?array
    {
        $externalId = \trim($externalId);

        if ($externalId === '') {
            return null;
        }

        $mapping = $this->mappings->findByExternal(ProviderManager::CLOCK_MODE, 'physical_room', $externalId);
        $localId = \is_array($mapping) ? (int) ($mapping['local_id'] ?? 0) : 0;

        return $localId > 0 ? $this->physicalRoomSelection($localId) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function physicalRoomSelectionsForType(int $roomTypeId): array
    {
        if ($roomTypeId <= 0) {
            return [];
        }

        $inventory = \MustHotelBooking\Engine\get_inventory_repository();

        if (!$inventory->inventoryRoomsTableExists()) {
            return [];
        }

        $selections = [];

        foreach ($inventory->getRoomsByType($roomTypeId) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $selection = $roomId > 0 ? $this->physicalRoomSelection($roomId) : null;

            if (\is_array($selection)) {
                $selections[] = $selection;
            }
        }

        return $selections;
    }

    /** @return array<string, mixed>|null */
    private function physicalRoomSelection(int $physicalRoomId): ?array
    {
        $inventory = \MustHotelBooking\Engine\get_inventory_repository();

        if (!$inventory->inventoryRoomsTableExists()) {
            return null;
        }

        $physicalRoom = $inventory->getInventoryRoomById($physicalRoomId);

        if (!\is_array($physicalRoom)) {
            return null;
        }

        $isActive = !isset($physicalRoom['is_active']) || (int) $physicalRoom['is_active'] === 1;
        $isBookable = !isset($physicalRoom['is_bookable']) || (int) $physicalRoom['is_bookable'] === 1;

        if (!$isActive || !$isBookable) {
            return null;
        }

        $roomTypeId = isset($physicalRoom['room_type_id']) ? (int) $physicalRoom['room_type_id'] : 0;
        $roomType = $roomTypeId > 0 ? \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomTypeId) : null;

        if (!\is_array($roomType)) {
            return null;
        }

        $roomMapping = $this->catalog->findAccommodationMapping($roomTypeId);
        $physicalMapping = $this->catalog->findPhysicalRoomMapping($physicalRoomId);

        if (!$this->hasExternalId($roomMapping) || !$this->hasExternalId($physicalMapping)) {
            return null;
        }

        return [
            'selection_id' => $physicalRoomId,
            'room_id' => $roomTypeId,
            'room_type_id' => $roomTypeId,
            'physical_room_id' => $physicalRoomId,
            'is_physical' => true,
            'room' => $this->physicalRoomDisplayData($physicalRoom, $roomType, $roomMapping, $physicalMapping),
            'room_type' => $roomType,
            'room_mapping' => $roomMapping,
            'physical_mapping' => $physicalMapping,
        ];
    }

    /** @return array<string, mixed>|null */
    private function roomTypeSelection(int $roomTypeId): ?array
    {
        $room = \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomTypeId);

        if (!\is_array($room)) {
            return null;
        }

        $roomMapping = $this->catalog->findAccommodationMapping($roomTypeId);

        if (!$this->hasExternalId($roomMapping)) {
            return null;
        }

        $room['room_type_id'] = $roomTypeId;
        $room['physical_room_id'] = 0;
        $room['gallery_room_id'] = $roomTypeId;

        return [
            'selection_id' => $roomTypeId,
            'room_id' => $roomTypeId,
            'room_type_id' => $roomTypeId,
            'physical_room_id' => 0,
            'is_physical' => false,
            'room' => $room,
            'room_type' => $room,
            'room_mapping' => $roomMapping,
            'physical_mapping' => null,
        ];
    }

    /**
     * @param array<string, mixed> $physicalRoom
     * @param array<string, mixed> $roomType
     * @param array<string, mixed> $roomMapping
     * @param array<string, mixed> $physicalMapping
     * @return array<string, mixed>
     */
    private function physicalRoomDisplayData(array $physicalRoom, array $roomType, array $roomMapping, array $physicalMapping): array
    {
        $physicalRoomId = isset($physicalRoom['id']) ? (int) $physicalRoom['id'] : 0;
        $roomTypeId = isset($roomType['id']) ? (int) $roomType['id'] : 0;
        $roomNumber = \trim((string) ($physicalRoom['room_number'] ?? ''));
        $title = \trim((string) ($physicalRoom['title'] ?? ''));
        $name = $title !== '' ? $title : $roomNumber;

        if ($name === '') {
            $name = \sprintf(
                /* translators: %d is an inventory room ID. */
                \__('Room %d', 'must-hotel-booking'),
                $physicalRoomId
            );
        }

        $description = \trim((string) ($physicalRoom['description'] ?? ''));

        if ($description === '') {
            $description = \trim((string) ($physicalRoom['admin_notes'] ?? ''));
        }

        if ($description === '') {
            $description = (string) ($roomType['description'] ?? '');
        }

        return [
            'id' => $physicalRoomId,
            'booking_room_id' => $physicalRoomId,
            'gallery_room_id' => $roomTypeId,
            'room_type_id' => $roomTypeId,
            'physical_room_id' => $physicalRoomId,
            'name' => $name,
            'slug' => (string) ($roomType['slug'] ?? ''),
            'details_slug' => (string) ($roomType['slug'] ?? ''),
            'category' => (string) ($roomType['category'] ?? ''),
            'description' => $description,
            'max_guests' => isset($physicalRoom['capacity_override']) && (int) $physicalRoom['capacity_override'] > 0
                ? (int) $physicalRoom['capacity_override']
                : (int) ($roomType['max_guests'] ?? 1),
            'base_price' => (float) ($roomType['base_price'] ?? 0.0),
            'room_size' => (string) ($roomType['room_size'] ?? ''),
            'beds' => (string) ($roomType['beds'] ?? ''),
            'available_count' => 1,
            'provider' => 'clock',
            'provider_room_type_id' => (string) ($roomMapping['external_id'] ?? ''),
            'provider_physical_room_id' => (string) ($physicalMapping['external_id'] ?? ''),
            'provider_room_id' => (string) ($physicalMapping['external_id'] ?? ''),
            'is_clock_physical_room' => true,
        ];
    }

    /** @param array<string, mixed>|null $mapping */
    private function hasExternalId(?array $mapping): bool
    {
        return \is_array($mapping) && (string) ($mapping['external_id'] ?? '') !== '';
    }
}
