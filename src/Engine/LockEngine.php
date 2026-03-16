<?php

namespace MustHotelBooking\Engine;

final class LockEngine
{
    public static function getCleanupCronHook(): string
    {
        return 'must_hotel_booking_cleanup_expired_locks';
    }

    public static function getTtlSeconds(): int
    {
        return 10 * (\defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60);
    }

    public static function getCurrentUtcDatetime(): string
    {
        return \gmdate('Y-m-d H:i:s');
    }

    public static function getExpiryDatetime(): string
    {
        return \gmdate('Y-m-d H:i:s', \time() + self::getTtlSeconds());
    }

    public static function normalizeSessionId(string $sessionId): string
    {
        $normalized = (string) \preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

        if ($normalized === '') {
            $normalized = (string) \str_replace('-', '', \wp_generate_uuid4());
        }

        return \substr($normalized, 0, 191);
    }

    public static function getOrCreateSessionId(): string
    {
        $cookieName = 'must_hotel_booking_lock_session';
        $sessionId = '';

        if (\is_user_logged_in() && \function_exists('wp_get_session_token')) {
            $sessionId = (string) \wp_get_session_token();
        }

        if ($sessionId === '' && isset($_COOKIE[$cookieName])) {
            $sessionId = (string) \wp_unslash($_COOKIE[$cookieName]);
        }

        $sessionId = self::normalizeSessionId($sessionId);

        if ((!isset($_COOKIE[$cookieName]) || $_COOKIE[$cookieName] !== $sessionId) && !\headers_sent()) {
            $cookiePath = (\defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/';
            $cookieDomain = (\defined('COOKIE_DOMAIN') && \is_string(COOKIE_DOMAIN)) ? COOKIE_DOMAIN : '';

            \setcookie(
                $cookieName,
                $sessionId,
                \time() + (\defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400),
                $cookiePath,
                $cookieDomain,
                \is_ssl(),
                true
            );
        }

        $_COOKIE[$cookieName] = $sessionId;

        return $sessionId;
    }

    public static function createLock(int $roomId, string $checkin, string $checkout, string $sessionId): bool
    {
        if ($roomId <= 0 || !AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout) || $checkin >= $checkout) {
            return false;
        }

        self::cleanupExpiredLocks();

        $sessionId = $sessionId !== '' ? self::normalizeSessionId($sessionId) : self::getOrCreateSessionId();

        if (!AvailabilityEngine::checkAvailability($roomId, $checkin, $checkout, $sessionId)) {
            return false;
        }

        return get_availability_repository()->upsertRoomLock(
            $roomId,
            $checkin,
            $checkout,
            $sessionId,
            self::getExpiryDatetime()
        );
    }

    public static function isRoomLocked(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        self::cleanupExpiredLocks();

        if ($roomId <= 0 || !AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout) || $checkin >= $checkout) {
            return true;
        }

        $excludeSessionId = $excludeSessionId !== '' ? self::normalizeSessionId($excludeSessionId) : '';

        return get_availability_repository()->hasActiveRoomLockOverlap(
            $roomId,
            $checkin,
            $checkout,
            self::getCurrentUtcDatetime(),
            $excludeSessionId
        );
    }

    public static function hasExactLock(int $roomId, string $checkin, string $checkout, string $sessionId = ''): bool
    {
        if ($roomId <= 0 || !AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout) || $checkin >= $checkout) {
            return false;
        }

        $sessionId = $sessionId !== '' ? self::normalizeSessionId($sessionId) : self::getOrCreateSessionId();

        return get_availability_repository()->hasActiveExactRoomLock(
            $roomId,
            $checkin,
            $checkout,
            $sessionId,
            self::getCurrentUtcDatetime()
        );
    }

    public static function releaseExactLock(int $roomId, string $checkin, string $checkout, string $sessionId = ''): bool
    {
        if ($roomId <= 0 || !AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout) || $checkin >= $checkout) {
            return false;
        }

        $sessionId = $sessionId !== '' ? self::normalizeSessionId($sessionId) : self::getOrCreateSessionId();

        return get_availability_repository()->deleteRoomLock($roomId, $checkin, $checkout, $sessionId);
    }

    public static function releaseLock(int $roomId, string $sessionId = ''): bool
    {
        if ($roomId <= 0) {
            return false;
        }

        $sessionId = $sessionId !== '' ? self::normalizeSessionId($sessionId) : self::getOrCreateSessionId();

        return get_availability_repository()->deleteRoomLocksBySession($roomId, $sessionId);
    }

    public static function cleanupExpiredLocks(): int
    {
        return get_availability_repository()->cleanupExpiredLocks(self::getCurrentUtcDatetime());
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function registerCleanupCronInterval(array $schedules): array
    {
        if (!isset($schedules['must_hotel_booking_every_five_minutes'])) {
            $schedules['must_hotel_booking_every_five_minutes'] = [
                'interval' => 5 * 60,
                'display' => \__('Every 5 Minutes (MUST Hotel Booking)', 'must-hotel-booking'),
            ];
        }

        return $schedules;
    }

    public static function runCleanupCron(): void
    {
        self::cleanupExpiredLocks();
    }

    public static function scheduleCleanupCron(): void
    {
        $hook = self::getCleanupCronHook();

        if (\wp_next_scheduled($hook) !== false) {
            return;
        }

        \wp_schedule_event(\time() + (5 * 60), 'must_hotel_booking_every_five_minutes', $hook);
    }

    public static function unscheduleCleanupCron(): void
    {
        if (\function_exists('wp_clear_scheduled_hook')) {
            \wp_clear_scheduled_hook(self::getCleanupCronHook());

            return;
        }

        $hook = self::getCleanupCronHook();
        $timestamp = \wp_next_scheduled($hook);

        while ($timestamp !== false) {
            \wp_unschedule_event($timestamp, $hook);
            $timestamp = \wp_next_scheduled($hook);
        }
    }

    public static function registerHooks(): void
    {
        \add_filter('cron_schedules', [self::class, 'registerCleanupCronInterval']);
        \add_action(self::getCleanupCronHook(), [self::class, 'runCleanupCron']);
    }
}
