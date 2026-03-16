<?php

namespace MustHotelBooking\Core;

use MustHotelBooking\Database\RoomRepository;

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
    public static function getRoomsForDisplay(string $category = 'all', int $limit = 50): array
    {
        $normalizedCategory = $category !== '' && $category !== 'all'
            ? RoomCatalog::normalizeCategory($category)
            : 'all';

        return self::repository()->getRoomsForDisplay($normalizedCategory, $limit);
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
