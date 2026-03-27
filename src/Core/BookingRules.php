<?php

namespace MustHotelBooking\Core;

use MustHotelBooking\Database\RoomRepository;

final class BookingRules
{
    public static function getMaxBookingGuestsLimit(): int
    {
        return \max(1, MustBookingConfig::get_max_booking_guests());
    }

    public static function getMaxBookingRoomsLimit(): int
    {
        return \max(1, MustBookingConfig::get_max_booking_rooms());
    }

    /**
     * @param mixed $value
     */
    public static function normalizeRoomCount($value): int
    {
        $roomCount = \absint(\is_scalar($value) ? (string) $value : 0);

        if ($roomCount <= 0) {
            return 0;
        }

        return \min(self::getMaxBookingRoomsLimit(), $roomCount);
    }

    /**
     * @return array<string, int>
     */
    public static function getRoomCategoryCapacityMap(): array
    {
        static $capacityMap = null;

        if (\is_array($capacityMap)) {
            return $capacityMap;
        }

        $capacityMap = [];

        foreach (RoomCatalog::getCategories() as $categorySlug => $categoryLabel) {
            unset($categoryLabel);

            $normalizedCategory = RoomCatalog::normalizeCategory((string) $categorySlug);
            $capacityMap[$normalizedCategory] = 4;
        }

        $rows = (new RoomRepository())->getRoomCategoryCapacityMap();

        foreach ($rows as $category => $maxGuests) {
            $normalizedCategory = RoomCatalog::normalizeCategory((string) $category);
            $capacityMap[$normalizedCategory] = \max(1, (int) $maxGuests);
        }

        if (empty($capacityMap)) {
            $defaultCategory = RoomCatalog::getDefaultCategory();
            $capacityMap = [
                $defaultCategory => 4,
            ];
        }

        $capacityMap[RoomCatalog::BOOKING_ALL_CATEGORY] = (int) \max($capacityMap);

        return $capacityMap;
    }

    /**
     * @param array<string, mixed>|null $fixedRoom
     */
    public static function getContextMaxRoomCapacity(string $accommodationType = 'standard-rooms', ?array $fixedRoom = null): int
    {
        if (\is_array($fixedRoom) && !empty($fixedRoom)) {
            return \max(1, (int) ($fixedRoom['max_guests'] ?? 0));
        }

        $capacityMap = self::getRoomCategoryCapacityMap();
        $normalizedCategory = RoomCatalog::normalizeBookingCategory($accommodationType);

        if (RoomCatalog::isBookingAllCategory($normalizedCategory)) {
            $allCapacity = isset($capacityMap[RoomCatalog::BOOKING_ALL_CATEGORY])
                ? (int) $capacityMap[RoomCatalog::BOOKING_ALL_CATEGORY]
                : (!empty($capacityMap) ? (int) \max($capacityMap) : 4);

            return \max(1, $allCapacity);
        }

        if (isset($capacityMap[$normalizedCategory])) {
            return \max(1, (int) $capacityMap[$normalizedCategory]);
        }

        $fallbackCapacity = !empty($capacityMap) ? (int) \max($capacityMap) : 4;

        return \max(1, $fallbackCapacity);
    }

    /**
     * @param array<string, mixed>|null $fixedRoom
     */
    public static function resolveRoomCount(int $guests, int $roomCount = 0, string $accommodationType = 'standard-rooms', ?array $fixedRoom = null): int
    {
        if (\is_array($fixedRoom) && !empty($fixedRoom)) {
            return 1;
        }

        $maxRooms = self::getMaxBookingRoomsLimit();
        $normalizedRoomCount = self::normalizeRoomCount($roomCount);
        $roomCapacity = self::getContextMaxRoomCapacity($accommodationType, $fixedRoom);
        $resolvedAutoCount = (int) \ceil(\max(1, $guests) / \max(1, $roomCapacity));
        $resolvedAutoCount = \max(1, \min($maxRooms, $resolvedAutoCount));

        if ($normalizedRoomCount > 0) {
            return \min($maxRooms, $normalizedRoomCount);
        }

        return $resolvedAutoCount;
    }

    /**
     * @param array<string, mixed>|null $fixedRoom
     */
    public static function getContextGuestLimit(string $accommodationType = 'standard-rooms', int $roomCount = 0, ?array $fixedRoom = null): int
    {
        $configuredLimit = self::getMaxBookingGuestsLimit();
        $roomCapacity = self::getContextMaxRoomCapacity($accommodationType, $fixedRoom);
        $allowedRoomCount = \is_array($fixedRoom) && !empty($fixedRoom)
            ? 1
            : \max(1, self::normalizeRoomCount($roomCount) > 0 ? self::normalizeRoomCount($roomCount) : self::getMaxBookingRoomsLimit());

        return \max(1, \min($configuredLimit, $roomCapacity * $allowedRoomCount));
    }

    public static function formatRoomCountLabel(int $roomCount): string
    {
        return \sprintf(
            /* translators: %d is room count. */
            \_n('%d Room', '%d Rooms', \max(1, $roomCount), 'must-hotel-booking'),
            \max(1, $roomCount)
        );
    }
}
