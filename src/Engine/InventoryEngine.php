<?php

namespace MustHotelBooking\Engine;

final class InventoryEngine
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomsByType(int $roomTypeId): array
    {
        return get_inventory_repository()->getRoomsByType($roomTypeId);
    }

    public static function hasInventoryForRoomType(int $roomTypeId): bool
    {
        return get_inventory_repository()->hasInventoryForRoomType($roomTypeId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getAvailableRooms(int $roomTypeId, string $checkin, string $checkout, string $excludeSessionId = ''): array
    {
        if (
            $roomTypeId <= 0 ||
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout ||
            !self::hasInventoryForRoomType($roomTypeId)
        ) {
            return [];
        }

        LockEngine::cleanupExpiredLocks();

        $sessionId = $excludeSessionId !== '' ? LockEngine::normalizeSessionId($excludeSessionId) : '';
        $now = LockEngine::getCurrentUtcDatetime();
        $nonBlockingStatuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
            ? get_inventory_non_blocking_reservation_statuses()
            : ['cancelled', 'expired', 'payment_failed'];
        $rooms = get_inventory_repository()->getAvailableRooms(
            $roomTypeId,
            $checkin,
            $checkout,
            $nonBlockingStatuses,
            $now,
            $sessionId
        );
        $legacyReservationCount = get_inventory_repository()->countUnassignedTypeReservationOverlaps(
            $roomTypeId,
            $checkin,
            $checkout,
            $nonBlockingStatuses
        );

        if ($legacyReservationCount <= 0 || empty($rooms)) {
            return $rooms;
        }

        return \array_slice($rooms, $legacyReservationCount);
    }

    public static function countAvailableRooms(int $roomTypeId, string $checkin, string $checkout, string $excludeSessionId = ''): int
    {
        return \count(self::getAvailableRooms($roomTypeId, $checkin, $checkout, $excludeSessionId));
    }

    /**
     * Lock one physical room for the selected room type and session.
     *
     * @return array<string, mixed>|null
     */
    public static function lockRoomType(int $roomTypeId, string $checkin, string $checkout, string $sessionId = ''): ?array
    {
        $sessionId = $sessionId !== '' ? LockEngine::normalizeSessionId($sessionId) : LockEngine::getOrCreateSessionId();

        if ($sessionId === '') {
            return null;
        }

        $lockedRoom = self::getLockedRoomForType($roomTypeId, $checkin, $checkout, $sessionId);

        if (\is_array($lockedRoom)) {
            return $lockedRoom;
        }

        $availableRooms = self::getAvailableRooms($roomTypeId, $checkin, $checkout, $sessionId);

        if (empty($availableRooms)) {
            return null;
        }

        $room = $availableRooms[0];
        $roomId = isset($room['id']) ? (int) $room['id'] : 0;

        if ($roomId <= 0) {
            return null;
        }

        $created = get_availability_repository()->upsertRoomLock(
            $roomId,
            $checkin,
            $checkout,
            $sessionId,
            LockEngine::getExpiryDatetime()
        );

        return $created ? $room : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getLockedRoomForType(int $roomTypeId, string $checkin, string $checkout, string $sessionId = ''): ?array
    {
        $sessionId = $sessionId !== '' ? LockEngine::normalizeSessionId($sessionId) : LockEngine::getOrCreateSessionId();

        if (
            $roomTypeId <= 0 ||
            $sessionId === '' ||
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout
        ) {
            return null;
        }

        return get_inventory_repository()->getLockedRoomByTypeAndSession(
            $roomTypeId,
            $checkin,
            $checkout,
            $sessionId,
            LockEngine::getCurrentUtcDatetime()
        );
    }

    public static function releaseLocksForRoomType(int $roomTypeId, string $checkin, string $checkout, string $sessionId = ''): bool
    {
        $lockedRoom = self::getLockedRoomForType($roomTypeId, $checkin, $checkout, $sessionId);

        if (!\is_array($lockedRoom)) {
            return false;
        }

        $roomId = isset($lockedRoom['id']) ? (int) $lockedRoom['id'] : 0;

        if ($roomId <= 0) {
            return false;
        }

        $sessionId = $sessionId !== '' ? LockEngine::normalizeSessionId($sessionId) : LockEngine::getOrCreateSessionId();

        return get_availability_repository()->deleteRoomLock($roomId, $checkin, $checkout, $sessionId);
    }

    public static function reserveRoom(int $roomId, int $reservationId): bool
    {
        if ($roomId <= 0 || $reservationId <= 0) {
            return false;
        }

        $room = get_inventory_repository()->getInventoryRoomById($roomId);
        $roomTypeId = \is_array($room) && isset($room['room_type_id']) ? (int) $room['room_type_id'] : 0;

        return get_inventory_repository()->assignRoomToReservation($roomId, $reservationId, $roomTypeId);
    }

    public static function releaseRoom(int $roomId): bool
    {
        $nonBlockingStatuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
            ? get_inventory_non_blocking_reservation_statuses()
            : ['cancelled', 'expired', 'payment_failed'];

        return get_inventory_repository()->releaseRoomAssignments($roomId, $nonBlockingStatuses);
    }
}
