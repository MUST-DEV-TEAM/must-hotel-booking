<?php

namespace MustHotelBooking\Core;

use MustHotelBooking\Database\RoomRepository;

/**
 * Read-only room data facade backed by the legacy must_rooms authority.
 *
 * Public room pages, selectors, and room content still resolve from
 * must_rooms. Any mirrored inventory room-type structures remain internal and
 * must not replace these reads in this plugin version.
 */
final class RoomData
{
    private static function repository(): RoomRepository
    {
        return new RoomRepository();
    }

    private static function isInternalImportText(string $value): bool
    {
        $value = \trim($value);

        if ($value === '') {
            return true;
        }

        return \preg_match('/^clock\s+pms\s+import$/i', $value) === 1
            || \preg_match('/^imported\s+from\s+clock\s+pms\b/i', $value) === 1;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function firstMetadataText(array $metadata, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($metadata[$key]) && \is_scalar($metadata[$key])) {
                $value = \trim((string) $metadata[$key]);

                if ($value !== '' && !self::isInternalImportText($value)) {
                    return $value;
                }
            }
        }

        foreach (['metadata', 'details', 'content', 'public', 'web', 'wbe'] as $containerKey) {
            if (isset($metadata[$containerKey]) && \is_array($metadata[$containerKey])) {
                $value = self::firstMetadataText($metadata[$containerKey], $keys);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function providerMappingMetadata(string $entityType, int $localId, string $localTable): array
    {
        if ($localId <= 0 || !\class_exists(\MustHotelBooking\Provider\Storage\ProviderMappingRepository::class)) {
            return [];
        }

        $mapping = (new \MustHotelBooking\Provider\Storage\ProviderMappingRepository())->findByLocal(
            \MustHotelBooking\Provider\ProviderManager::CLOCK_MODE,
            $entityType,
            $localId,
            $localTable
        );

        if (!\is_array($mapping)) {
            return [];
        }

        $metadata = $mapping['metadata'] ?? [];

        if (\is_string($metadata) && $metadata !== '') {
            $decoded = \json_decode($metadata, true);
            $metadata = \is_array($decoded) ? $decoded : [];
        }

        return \is_array($metadata) ? $metadata : [];
    }

    private static function hasClockPhysicalMapping(int $physicalRoomId): bool
    {
        if ($physicalRoomId <= 0 || !\class_exists(\MustHotelBooking\Provider\Storage\ProviderMappingRepository::class)) {
            return false;
        }

        $mapping = (new \MustHotelBooking\Provider\Storage\ProviderMappingRepository())->findByLocal(
            \MustHotelBooking\Provider\ProviderManager::CLOCK_MODE,
            'physical_room',
            $physicalRoomId,
            'mhb_rooms'
        );

        return \is_array($mapping) && (string) ($mapping['external_id'] ?? '') !== '';
    }

    /**
     * @return array<int, int>
     */
    private static function parseIdList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $decoded = \json_decode($value, true);
        $parts = \is_array($decoded) ? $decoded : \explode(',', $value);
        $ids = [];

        foreach ($parts as $part) {
            $id = \absint($part);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return \array_values($ids);
    }

    /**
     * @return array<int, string>
     */
    private static function parseAmenityList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $decoded = \json_decode($value, true);
        $parts = \is_array($decoded) ? $decoded : \explode(',', $value);
        $items = [];

        foreach ($parts as $part) {
            $item = \sanitize_key((string) $part);

            if ($item !== '') {
                $items[$item] = $item;
            }
        }

        return \array_values($items);
    }

    public static function getRoomsTableName(): string
    {
        return self::repository()->getRoomsTableName();
    }

    public static function getRoomMetaTableName(): string
    {
        return self::repository()->getRoomMetaTableName();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getRoom(int $roomId): ?array
    {
        return self::repository()->getRoomById($roomId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getRoomBySlug(string $roomSlug): ?array
    {
        return self::repository()->getRoomBySlug($roomSlug);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomsListRows(): array
    {
        return self::repository()->getRoomsListRows();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomSelectorRows(bool $includeInactive = true, bool $onlyBookable = false): array
    {
        return self::repository()->getRoomSelectorRows($includeInactive, $onlyBookable);
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomsByIds(array $roomIds): array
    {
        return self::repository()->getRoomsByIds($roomIds);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomsForTextGrid(int $limit = 0): array
    {
        return self::repository()->getRoomsForTextGrid($limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomsForDisplay(string $category = 'all', int $limit = 50): array
    {
        $limit = \max(1, \min(200, $limit));

        if (RoomCatalog::isClockBackendMode() && \sanitize_key($category) === RoomCatalog::BOOKING_ALL_CATEGORY) {
            $rooms = [];

            foreach (\array_keys(RoomCatalog::getClockBookingRoomTypes()) as $bookingCategory) {
                $roomId = RoomCatalog::resolveBookingRoomTypeId((string) $bookingCategory);
                $room = $roomId > 0 ? self::getRoom($roomId) : null;

                if (!\is_array($room)) {
                    continue;
                }

                $physicalRooms = self::getPhysicalRoomsForDisplay($room);
                $rooms = \array_merge($rooms, !empty($physicalRooms) ? $physicalRooms : [$room]);

                if (\count($rooms) >= $limit) {
                    break;
                }
            }

            if (!empty($rooms)) {
                return \array_slice($rooms, 0, $limit);
            }
        }

        if (RoomCatalog::isRoomTypeBookingValue($category)) {
            $roomId = RoomCatalog::resolveBookingRoomTypeId($category);
            $room = $roomId > 0 ? self::getRoom($roomId) : null;

            if (!\is_array($room)) {
                return [];
            }

            if (RoomCatalog::isClockBackendMode()) {
                $physicalRooms = self::getPhysicalRoomsForDisplay($room);

                if (!empty($physicalRooms)) {
                    return \array_slice($physicalRooms, 0, $limit);
                }
            }

            return [$room];
        }

        $normalizedCategory = $category !== '' && $category !== 'all'
            ? RoomCatalog::normalizeCategory($category)
            : 'all';

        if (RoomCatalog::isClockBackendMode()) {
            $roomTypes = self::repository()->getRoomsForDisplay($normalizedCategory, $limit);
            $rooms = [];

            foreach ($roomTypes as $roomType) {
                if (!\is_array($roomType)) {
                    continue;
                }

                $physicalRooms = self::getPhysicalRoomsForDisplay($roomType);
                $rooms = \array_merge($rooms, !empty($physicalRooms) ? $physicalRooms : [$roomType]);

                if (\count($rooms) >= $limit) {
                    break;
                }
            }

            if (!empty($rooms)) {
                return \array_slice($rooms, 0, $limit);
            }
        }

        return self::repository()->getRoomsForDisplay($normalizedCategory, $limit);
    }

    /**
     * @param array<string, mixed> $roomType
     * @return array<int, array<string, mixed>>
     */
    private static function getPhysicalRoomsForDisplay(array $roomType): array
    {
        $roomTypeId = isset($roomType['id']) ? (int) $roomType['id'] : 0;

        if ($roomTypeId <= 0) {
            return [];
        }

        $inventory = \MustHotelBooking\Engine\get_inventory_repository();

        if (!$inventory->inventoryRoomsTableExists()) {
            return [];
        }

        $rows = $inventory->getRoomsByType($roomTypeId);
        $rooms = [];

        foreach ($rows as $row) {
            $displayRoom = \is_array($row) ? self::buildPhysicalRoomDisplayRow($row, $roomType) : null;

            if (\is_array($displayRoom)) {
                $rooms[] = $displayRoom;
            }
        }

        return $rooms;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getPhysicalRoomDisplayById(int $physicalRoomId): ?array
    {
        if ($physicalRoomId <= 0) {
            return null;
        }

        $inventory = \MustHotelBooking\Engine\get_inventory_repository();

        if (!$inventory->inventoryRoomsTableExists()) {
            return null;
        }

        $physicalRoom = $inventory->getInventoryRoomById($physicalRoomId);

        if (!\is_array($physicalRoom)) {
            return null;
        }

        $roomTypeId = isset($physicalRoom['room_type_id']) ? (int) $physicalRoom['room_type_id'] : 0;
        $roomType = $roomTypeId > 0 ? self::getRoom($roomTypeId) : null;

        if (!\is_array($roomType)) {
            return null;
        }

        return self::buildPhysicalRoomDisplayRow($physicalRoom, $roomType);
    }

    /**
     * @param array<string, mixed> $physicalRoom
     * @param array<string, mixed> $roomType
     * @return array<string, mixed>|null
     */
    private static function buildPhysicalRoomDisplayRow(array $physicalRoom, array $roomType): ?array
    {
        $isActive = !isset($physicalRoom['is_active']) || (int) $physicalRoom['is_active'] === 1;
        $isBookable = !isset($physicalRoom['is_bookable']) || (int) $physicalRoom['is_bookable'] === 1;

        if (!$isActive || !$isBookable) {
            return null;
        }

        $physicalRoomId = isset($physicalRoom['id']) ? (int) $physicalRoom['id'] : 0;
        $roomTypeId = isset($roomType['id']) ? (int) $roomType['id'] : 0;

        if ($physicalRoomId <= 0 || $roomTypeId <= 0) {
            return null;
        }

        if (RoomCatalog::isClockBackendMode() && !self::hasClockPhysicalMapping($physicalRoomId)) {
            return null;
        }

        if (isset($physicalRoom['public_visible']) && (int) $physicalRoom['public_visible'] !== 1) {
            return null;
        }

        $roomNumber = \trim((string) ($physicalRoom['room_number'] ?? ''));
        $title = \trim((string) ($physicalRoom['public_title'] ?? ''));

        if ($title === '') {
            $title = \trim((string) ($physicalRoom['title'] ?? ''));
        }

        $name = $title !== '' ? $title : $roomNumber;

        if ($name === '') {
            $name = \sprintf(
                /* translators: %d is an inventory room ID. */
                \__('Room %d', 'must-hotel-booking'),
                $physicalRoomId
            );
        }

        $description = \trim((string) ($physicalRoom['public_description'] ?? ''));

        if ($description === '') {
            $description = \trim((string) ($physicalRoom['description'] ?? ''));
        }

        if (self::isInternalImportText($description)) {
            $description = '';
        }

        if ($description === '') {
            $adminNotes = \trim((string) ($physicalRoom['admin_notes'] ?? ''));
            $description = self::isInternalImportText($adminNotes) ? '' : $adminNotes;
        }

        if ($description === '') {
            $physicalMetadata = self::providerMappingMetadata('physical_room', $physicalRoomId, 'mhb_rooms');
            $description = self::firstMetadataText($physicalMetadata, ['description', 'descr', 'long_description', 'short_description', 'notes']);
        }

        if ($description === '') {
            $roomTypeDescription = \trim((string) ($roomType['description'] ?? ''));
            $description = self::isInternalImportText($roomTypeDescription) ? '' : $roomTypeDescription;
        }

        if ($description === '') {
            $roomTypeMetadata = self::providerMappingMetadata('accommodation', $roomTypeId, 'must_rooms');
            $description = self::firstMetadataText($roomTypeMetadata, ['description', 'descr', 'long_description', 'short_description', 'notes']);
        }

        $featuredImageId = isset($physicalRoom['featured_image_id']) ? (int) $physicalRoom['featured_image_id'] : 0;
        $galleryImageIds = self::parseIdList((string) ($physicalRoom['gallery_image_ids'] ?? ''));
        $amenities = self::parseAmenityList((string) ($physicalRoom['amenities'] ?? ''));
        $roomSize = \trim((string) ($physicalRoom['room_size'] ?? ''));
        $bedSetup = \trim((string) ($physicalRoom['bed_setup'] ?? ''));
        $maxGuestsOverride = isset($physicalRoom['max_guests_override']) ? (int) $physicalRoom['max_guests_override'] : 0;

        return [
            'id' => $physicalRoomId,
            'booking_room_id' => $physicalRoomId,
            'gallery_room_id' => ($featuredImageId > 0 || !empty($galleryImageIds)) ? $physicalRoomId : $roomTypeId,
            'room_type_id' => $roomTypeId,
            'physical_room_id' => $physicalRoomId,
            'room_number' => $roomNumber,
            'name' => $name,
            'slug' => (string) ($roomType['slug'] ?? ''),
            'details_slug' => (string) ($roomType['slug'] ?? ''),
            'category' => (string) ($roomType['category'] ?? ''),
            'description' => $description,
            'max_guests' => $maxGuestsOverride > 0
                ? $maxGuestsOverride
                : (isset($physicalRoom['capacity_override']) && (int) $physicalRoom['capacity_override'] > 0
                ? (int) $physicalRoom['capacity_override']
                : (int) ($roomType['max_guests'] ?? 1)),
            'base_price' => (float) ($roomType['base_price'] ?? 0.0),
            'room_size' => $roomSize !== '' ? $roomSize : (string) ($roomType['room_size'] ?? ''),
            'beds' => $bedSetup !== '' ? $bedSetup : (string) ($roomType['beds'] ?? ''),
            'bed_setup' => $bedSetup,
            'view_type' => (string) ($physicalRoom['view_type'] ?? ''),
            'floor' => isset($physicalRoom['floor']) ? (int) $physicalRoom['floor'] : 0,
            'main_image_id' => $featuredImageId,
            'gallery_image_ids' => $galleryImageIds,
            'amenity_keys' => $amenities,
            'is_clock_physical_room' => true,
        ];
    }

    public static function getRoomMainImageId(int $roomId): int
    {
        $physicalRoom = self::getPhysicalRoomDisplayById($roomId);

        if (\is_array($physicalRoom) && (int) ($physicalRoom['main_image_id'] ?? 0) > 0) {
            return (int) $physicalRoom['main_image_id'];
        }

        return self::repository()->getRoomMainImageId($roomId);
    }

    /**
     * @return array<int, int>
     */
    public static function getRoomGalleryImageIds(int $roomId): array
    {
        $physicalRoom = self::getPhysicalRoomDisplayById($roomId);

        if (\is_array($physicalRoom) && !empty($physicalRoom['gallery_image_ids']) && \is_array($physicalRoom['gallery_image_ids'])) {
            return \array_map('intval', $physicalRoom['gallery_image_ids']);
        }

        return self::repository()->getRoomGalleryImageIds($roomId);
    }

    /**
     * @return array<int, string>
     */
    public static function getRoomAmenities(int $roomId): array
    {
        $physicalRoom = self::getPhysicalRoomDisplayById($roomId);

        if (\is_array($physicalRoom) && !empty($physicalRoom['amenity_keys']) && \is_array($physicalRoom['amenity_keys'])) {
            return \array_map('strval', $physicalRoom['amenity_keys']);
        }

        return self::repository()->getRoomAmenities($roomId);
    }

    public static function getRoomMetaTextValue(int $roomId, string $metaKey): string
    {
        return self::repository()->getRoomMetaTextValue($roomId, $metaKey);
    }

    public static function getRoomMainImageUrl(int $roomId, string $size = 'large'): string
    {
        $imageId = self::getRoomMainImageId($roomId);

        if ($imageId <= 0) {
            return '';
        }

        $url = \wp_get_attachment_image_url($imageId, $size);

        return \is_string($url) ? $url : '';
    }

    /**
     * @return array<int, string>
     */
    public static function getProviderImageUrls(int $roomTypeId, int $physicalRoomId = 0): array
    {
        $metadataBlocks = [];

        if ($physicalRoomId > 0) {
            $metadataBlocks[] = self::providerMappingMetadata('physical_room', $physicalRoomId, 'mhb_rooms');
        }

        if ($roomTypeId > 0) {
            $metadataBlocks[] = self::providerMappingMetadata('accommodation', $roomTypeId, 'must_rooms');
        }

        $urls = [];

        foreach ($metadataBlocks as $metadata) {
            foreach (self::extractUrlValues($metadata, '/(image|photo|picture|gallery|media|thumbnail|url)$/i') as $url) {
                if (\preg_match('/\.(?:jpe?g|png|webp|gif)(?:\?.*)?$/i', $url) !== 1) {
                    continue;
                }

                $urls[] = $url;
            }
        }

        return \array_values(\array_unique($urls));
    }

    public static function getRoomRulesText(int $roomId): string
    {
        return self::getRoomMetaTextValue($roomId, 'room_rules');
    }

    public static function getRoomAmenitiesIntroText(int $roomId): string
    {
        return self::getRoomMetaTextValue($roomId, 'amenities_intro');
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function getRoomAmenityDisplayItems(int $roomId): array
    {
        $selected = self::getRoomAmenities($roomId);
        $available = RoomCatalog::getAvailableAmenities();
        $items = [];

        foreach ($selected as $selectedValue) {
            $key = RoomCatalog::normalizeAmenityKey((string) $selectedValue);

            if ($key === '' || !isset($available[$key])) {
                continue;
            }

            $amenity = $available[$key];
            $label = isset($amenity['label']) ? (string) $amenity['label'] : '';
            $iconFile = isset($amenity['icon']) ? (string) $amenity['icon'] : '';

            if ($label === '' || $iconFile === '') {
                continue;
            }

            $items[$key] = [
                'key' => $key,
                'label' => $label,
                'icon' => MUST_HOTEL_BOOKING_URL . 'assets/img/' . $iconFile,
            ];
        }

        return \array_values($items);
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function getProviderFeatureDisplayItems(int $roomTypeId, int $physicalRoomId = 0): array
    {
        $metadataBlocks = [];

        if ($physicalRoomId > 0) {
            $metadataBlocks[] = self::providerMappingMetadata('physical_room', $physicalRoomId, 'mhb_rooms');
        }

        if ($roomTypeId > 0) {
            $metadataBlocks[] = self::providerMappingMetadata('accommodation', $roomTypeId, 'must_rooms');
        }

        $labels = [];

        foreach ($metadataBlocks as $metadata) {
            $labels = \array_merge($labels, self::extractFeatureLabels($metadata));
        }

        $items = [];

        foreach (\array_values(\array_unique($labels)) as $label) {
            $label = \trim((string) $label);

            if ($label === '') {
                continue;
            }

            $key = RoomCatalog::normalizeAmenityKey($label);
            $icon = self::featureIconForLabel($label);

            if ($key === '' || isset($items[$key])) {
                continue;
            }

            $items[$key] = [
                'key' => $key,
                'label' => $label,
                'icon' => MUST_HOTEL_BOOKING_URL . 'assets/img/' . $icon,
            ];
        }

        return \array_values($items);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function extractFeatureLabels($value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $labels = [];

        foreach ($value as $key => $item) {
            $keyString = \is_string($key) ? \strtolower($key) : '';
            $isFeatureContainer = \preg_match('/(amenit|facilit|feature|equipment|service)/i', $keyString) === 1;

            if ($isFeatureContainer) {
                $labels = \array_merge($labels, self::flattenFeatureValue($item));
                continue;
            }

            if (\is_array($item)) {
                $labels = \array_merge($labels, self::extractFeatureLabels($item));
            }
        }

        return \array_values(\array_filter(\array_map('strval', $labels)));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function flattenFeatureValue($value): array
    {
        if (\is_scalar($value)) {
            return \preg_split('/\s*[,;|]\s*/', (string) $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (!\is_array($value)) {
            return [];
        }

        if (isset($value['items']) && \is_array($value['items'])) {
            return self::flattenFeatureValue($value['items']);
        }

        $labels = [];

        foreach ($value as $key => $item) {
            if (\is_string($key) && \in_array($key, ['type', 'count', 'keys'], true)) {
                continue;
            }

            if (\is_scalar($item)) {
                $labels[] = (string) $item;
                continue;
            }

            if (\is_array($item)) {
                foreach (['name', 'label', 'title', 'description', 'text'] as $labelKey) {
                    if (isset($item[$labelKey]) && \is_scalar($item[$labelKey])) {
                        $labels[] = (string) $item[$labelKey];
                        continue 2;
                    }
                }

                $labels = \array_merge($labels, self::flattenFeatureValue($item));
            }
        }

        return $labels;
    }

    private static function featureIconForLabel(string $label): string
    {
        $key = RoomCatalog::normalizeAmenityKey($label);
        $map = [
            'safetydepositbox' => 'safetydepositbox.svg',
            'streaming' => 'streaming.svg',
            'dryer' => 'dryer.svg',
            'telephone' => 'telephone.svg',
            'sheets' => 'linen.svg',
            'cablechannels' => 'cablechannels.svg',
            'flatscreentv' => 'flatscreentv.svg',
            'refrigerator' => 'refrigerator.svg',
            'airconditioning' => 'airconditioning.svg',
        ];

        return $map[$key] ?? 'check.svg';
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function extractUrlValues($value, string $keyPattern): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $urls = [];

        foreach ($value as $key => $item) {
            $keyString = \is_string($key) ? $key : '';

            if (\is_scalar($item) && ($keyString === '' || \preg_match($keyPattern, $keyString) === 1)) {
                $url = \esc_url_raw((string) $item);

                if ($url !== '') {
                    $urls[] = $url;
                }
            } elseif (\is_array($item)) {
                $urls = \array_merge($urls, self::extractUrlValues($item, $keyPattern));
            }
        }

        return $urls;
    }

    /**
     * @return array<int, string>
     */
    public static function getRoomGalleryImageUrls(int $roomId, int $limit = 3, string $size = 'large'): array
    {
        $limit = \max(1, \min(10, $limit));
        $ids = self::getRoomGalleryImageIds($roomId);

        if (empty($ids)) {
            return [];
        }

        $urls = [];

        foreach ($ids as $id) {
            $url = \wp_get_attachment_image_url($id, $size);

            if (!\is_string($url) || $url === '') {
                continue;
            }

            $urls[] = $url;

            if (\count($urls) >= $limit) {
                break;
            }
        }

        return $urls;
    }
}
