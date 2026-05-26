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
            if (!\is_array($row)) {
                continue;
            }

            $isActive = !isset($row['is_active']) || (int) $row['is_active'] === 1;
            $isBookable = !isset($row['is_bookable']) || (int) $row['is_bookable'] === 1;

            if (!$isActive || !$isBookable) {
                continue;
            }

            $roomNumber = \trim((string) ($row['room_number'] ?? ''));
            $title = \trim((string) ($row['title'] ?? ''));
            $name = $roomNumber !== '' ? $roomNumber : $title;

            if ($name === '') {
                $name = \sprintf(
                    /* translators: %d is an inventory room ID. */
                    \__('Room %d', 'must-hotel-booking'),
                    (int) ($row['id'] ?? 0)
                );
            } elseif ($title !== '' && $title !== $name) {
                $name .= ' - ' . $title;
            }

            $rooms[] = [
                'id' => $roomTypeId,
                'booking_room_id' => $roomTypeId,
                'gallery_room_id' => $roomTypeId,
                'physical_room_id' => isset($row['id']) ? (int) $row['id'] : 0,
                'name' => $name,
                'slug' => (string) ($roomType['slug'] ?? ''),
                'details_slug' => (string) ($roomType['slug'] ?? ''),
                'category' => (string) ($roomType['category'] ?? ''),
                'description' => \sprintf(
                    /* translators: %s is the room type name. */
                    \__('Individual room under %s.', 'must-hotel-booking'),
                    (string) ($roomType['name'] ?? \__('this room type', 'must-hotel-booking'))
                ),
                'max_guests' => isset($row['capacity_override']) && (int) $row['capacity_override'] > 0
                    ? (int) $row['capacity_override']
                    : (int) ($roomType['max_guests'] ?? 1),
                'base_price' => (float) ($roomType['base_price'] ?? 0.0),
                'room_size' => (string) ($roomType['room_size'] ?? ''),
                'beds' => (string) ($roomType['beds'] ?? ''),
                'is_clock_physical_room' => true,
            ];
        }

        return $rooms;
    }

    public static function getRoomMainImageId(int $roomId): int
    {
        return self::repository()->getRoomMainImageId($roomId);
    }

    /**
     * @return array<int, int>
     */
    public static function getRoomGalleryImageIds(int $roomId): array
    {
        return self::repository()->getRoomGalleryImageIds($roomId);
    }

    /**
     * @return array<int, string>
     */
    public static function getRoomAmenities(int $roomId): array
    {
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
