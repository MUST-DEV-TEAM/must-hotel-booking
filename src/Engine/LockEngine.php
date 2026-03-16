<?php

namespace MustHotelBooking\Engine;

final class LockEngine
{
    public static function createLock(int $roomId, string $checkin, string $checkout, string $sessionId): bool
    {
        return create_room_lock($roomId, $checkin, $checkout, $sessionId);
    }

    public static function isRoomLocked(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        return is_room_locked($roomId, $checkin, $checkout, $excludeSessionId);
    }

    public static function releaseLock(int $roomId, string $sessionId = ''): bool
    {
        return release_room_locks_by_session($roomId, $sessionId);
    }

    public static function cleanupExpiredLocks(): int
    {
        return cleanup_expired_locks();
    }
}
