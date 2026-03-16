<?php

namespace MustHotelBooking\Engine;

/**
 * Get lock lifetime in seconds.
 */
function get_lock_ttl_seconds(): int
{
    return 10 * (\defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60);
}

/**
 * Get locks table name.
 */
function get_locks_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'mhb_inventory_locks';
}

/**
 * Get current UTC datetime in MySQL format.
 */
function get_current_utc_datetime(): string
{
    return \gmdate('Y-m-d H:i:s');
}

/**
 * Build lock expiry datetime based on lock TTL.
 */
function get_lock_expiry_datetime(): string
{
    return \gmdate('Y-m-d H:i:s', \time() + get_lock_ttl_seconds());
}

/**
 * Get WP-Cron hook name for lock cleanup.
 */
function get_lock_cleanup_cron_hook(): string
{
    return 'must_hotel_booking_cleanup_expired_locks';
}

/**
 * Register custom 5-minute cron interval.
 *
 * @param array<string, array<string, mixed>> $schedules
 * @return array<string, array<string, mixed>>
 */
function register_lock_cleanup_cron_interval(array $schedules): array
{
    if (!isset($schedules['must_hotel_booking_every_five_minutes'])) {
        $schedules['must_hotel_booking_every_five_minutes'] = [
            'interval' => 5 * 60,
            'display' => \__('Every 5 Minutes (MUST Hotel Booking)', 'must-hotel-booking'),
        ];
    }

    return $schedules;
}

/**
 * Normalize lock session identifiers to a DB-safe value.
 */
function normalize_lock_session_id(string $session_id): string
{
    $normalized = (string) \preg_replace('/[^a-zA-Z0-9_-]/', '', $session_id);

    if ($normalized === '') {
        $normalized = (string) \str_replace('-', '', \wp_generate_uuid4());
    }

    return \substr($normalized, 0, 191);
}

/**
 * Get or create lock session ID for the current visitor.
 */
function get_or_create_lock_session_id(): string
{
    $cookie_name = 'must_hotel_booking_lock_session';
    $session_id = '';

    if (\is_user_logged_in() && \function_exists('wp_get_session_token')) {
        $session_id = (string) \wp_get_session_token();
    }

    if ($session_id === '' && isset($_COOKIE[$cookie_name])) {
        $session_id = (string) \wp_unslash($_COOKIE[$cookie_name]);
    }

    $session_id = normalize_lock_session_id($session_id);

    if ((!isset($_COOKIE[$cookie_name]) || $_COOKIE[$cookie_name] !== $session_id) && !\headers_sent()) {
        $cookie_path = (\defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/';
        $cookie_domain = (\defined('COOKIE_DOMAIN') && \is_string(COOKIE_DOMAIN)) ? COOKIE_DOMAIN : '';

        \setcookie(
            $cookie_name,
            $session_id,
            \time() + (\defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400),
            $cookie_path,
            $cookie_domain,
            \is_ssl(),
            true
        );
    }

    $_COOKIE[$cookie_name] = $session_id;

    return $session_id;
}

/**
 * Cleanup all expired locks.
 */
function cleanup_expired_locks(): int
{
    return get_availability_repository()->cleanupExpiredLocks(get_current_utc_datetime());
}

function is_room_locked(int $room_id, string $checkin, string $checkout, string $exclude_session_id = ''): bool
{
    cleanup_expired_locks();

    return has_active_room_lock_overlap($room_id, $checkin, $checkout, $exclude_session_id);
}

/**
 * Cron callback to remove expired room locks.
 */
function run_lock_cleanup_cron(): void
{
    cleanup_expired_locks();
}

/**
 * Schedule recurring lock cleanup event if missing.
 */
function schedule_lock_cleanup_cron(): void
{
    $hook = get_lock_cleanup_cron_hook();

    if (\wp_next_scheduled($hook) !== false) {
        return;
    }

    \wp_schedule_event(\time() + (5 * 60), 'must_hotel_booking_every_five_minutes', $hook);
}

/**
 * Clear scheduled lock cleanup events.
 */
function unschedule_lock_cleanup_cron(): void
{
    if (\function_exists('wp_clear_scheduled_hook')) {
        \wp_clear_scheduled_hook(get_lock_cleanup_cron_hook());

        return;
    }

    $hook = get_lock_cleanup_cron_hook();
    $timestamp = \wp_next_scheduled($hook);

    while ($timestamp !== false) {
        \wp_unschedule_event($timestamp, $hook);
        $timestamp = \wp_next_scheduled($hook);
    }
}

/**
 * Check if a room has an active overlapping lock owned by another session.
 */
function has_active_room_lock_overlap(int $room_id, string $checkin, string $checkout, string $exclude_session_id = ''): bool
{
    if ($room_id <= 0 || !is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return true;
    }

    $now = get_current_utc_datetime();
    $exclude_session_id = $exclude_session_id !== '' ? normalize_lock_session_id($exclude_session_id) : '';

    return get_availability_repository()->hasActiveRoomLockOverlap($room_id, $checkin, $checkout, $now, $exclude_session_id);
}

/**
 * Check if a lock exists for this exact room/date range/session.
 */
function has_active_exact_room_lock(int $room_id, string $checkin, string $checkout, string $session_id): bool
{
    if ($room_id <= 0 || !is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return false;
    }

    return get_availability_repository()->hasActiveExactRoomLock(
        $room_id,
        $checkin,
        $checkout,
        normalize_lock_session_id($session_id),
        get_current_utc_datetime()
    );
}

/**
 * Create or refresh a temporary lock for a room selection.
 */
function create_room_lock(int $room_id, string $checkin, string $checkout, string $session_id = ''): bool
{
    if ($room_id <= 0 || !is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return false;
    }

    cleanup_expired_locks();

    $session_id = $session_id !== '' ? normalize_lock_session_id($session_id) : get_or_create_lock_session_id();

    if (!check_room_availability($room_id, $checkin, $checkout, $session_id)) {
        return false;
    }

    return get_availability_repository()->upsertRoomLock(
        $room_id,
        $checkin,
        $checkout,
        $session_id,
        get_lock_expiry_datetime()
    );
}

/**
 * Release a lock for an exact room/date range/session.
 */
function release_room_lock(int $room_id, string $checkin, string $checkout, string $session_id = ''): bool
{
    if ($room_id <= 0 || !is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return false;
    }

    $session_id = $session_id !== '' ? normalize_lock_session_id($session_id) : get_or_create_lock_session_id();

    return get_availability_repository()->deleteRoomLock($room_id, $checkin, $checkout, $session_id);
}

function release_room_locks_by_session(int $room_id, string $session_id = ''): bool
{
    if ($room_id <= 0) {
        return false;
    }

    $session_id = $session_id !== '' ? normalize_lock_session_id($session_id) : get_or_create_lock_session_id();

    return get_availability_repository()->deleteRoomLocksBySession($room_id, $session_id);
}

/**
 * Run periodic lock maintenance.
 */
function bootstrap_lock_engine(): void
{
    schedule_lock_cleanup_cron();
}

\add_filter('cron_schedules', __NAMESPACE__ . '\register_lock_cleanup_cron_interval');
\add_action(get_lock_cleanup_cron_hook(), __NAMESPACE__ . '\run_lock_cleanup_cron');
\add_action('must_hotel_booking/init', __NAMESPACE__ . '\bootstrap_lock_engine');
