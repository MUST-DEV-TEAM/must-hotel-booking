<?php

namespace must_hotel_booking;

/**
 * Generate a unique booking ID for reservations.
 */
function generate_unique_booking_id(): string
{
    global $wpdb;

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $max_attempts = 8;
    $attempt = 0;

    while ($attempt < $max_attempts) {
        $suffix = \strtoupper(\substr(\str_replace('-', '', \wp_generate_uuid4()), 0, 8));
        $booking_id = 'MHB-' . \gmdate('Ymd') . '-' . $suffix;

        $exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$reservations_table} WHERE booking_id = %s",
                $booking_id
            )
        );

        if ($exists === 0) {
            return $booking_id;
        }

        $attempt++;
    }

    return 'MHB-' . \gmdate('YmdHis') . '-' . \wp_rand(1000, 9999);
}

/**
 * Get available rooms for a selected date range and guest count.
 *
 * @return array<int, array<string, mixed>>
 */
function get_available_rooms(string $checkin, string $checkout, int $guests = 1, string $category = 'standard-rooms'): array
{
    global $wpdb;

    if (!is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return [];
    }

    $guests = \max(1, $guests);
    $category = \function_exists(__NAMESPACE__ . '\normalize_room_category')
        ? normalize_room_category($category)
        : 'standard-rooms';

    if (\function_exists(__NAMESPACE__ . '\cleanup_expired_locks')) {
        cleanup_expired_locks();
    }

    $rooms_table = $wpdb->prefix . 'must_rooms';
    $reservations_table = $wpdb->prefix . 'must_reservations';
    $locks_table = $wpdb->prefix . 'must_locks';
    $now = \function_exists(__NAMESPACE__ . '\get_current_utc_datetime') ? get_current_utc_datetime() : \gmdate('Y-m-d H:i:s');
    $session_id = \function_exists(__NAMESPACE__ . '\get_or_create_lock_session_id') ? get_or_create_lock_session_id() : '';

    if ($session_id !== '') {
        $sql = $wpdb->prepare(
            "SELECT r.*
            FROM {$rooms_table} r
            WHERE r.category = %s
                AND r.max_guests >= %d
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$reservations_table} res
                    WHERE res.room_id = r.id
                        AND res.checkin < %s
                        AND res.checkout > %s
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$locks_table} l
                    WHERE l.room_id = r.id
                        AND l.checkin < %s
                        AND l.checkout > %s
                        AND l.expires_at > %s
                        AND l.session_id <> %s
                )
            ORDER BY r.id ASC",
            $category,
            $guests,
            $checkout,
            $checkin,
            $checkout,
            $checkin,
            $now,
            $session_id
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT r.*
            FROM {$rooms_table} r
            WHERE r.category = %s
                AND r.max_guests >= %d
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$reservations_table} res
                    WHERE res.room_id = r.id
                        AND res.checkin < %s
                        AND res.checkout > %s
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM {$locks_table} l
                    WHERE l.room_id = r.id
                        AND l.checkin < %s
                        AND l.checkout > %s
                        AND l.expires_at > %s
                )
            ORDER BY r.id ASC",
            $category,
            $guests,
            $checkout,
            $checkin,
            $checkout,
            $checkin,
            $now
        );
    }

    $results = $wpdb->get_results($sql, ARRAY_A);
    $rows = \is_array($results) ? $results : [];

    if (empty($rows) || !\function_exists(__NAMESPACE__ . '\check_booking_restrictions')) {
        return $rows;
    }

    $filtered = [];

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $room_id = isset($row['id']) ? (int) $row['id'] : 0;

        if ($room_id <= 0 || !check_booking_restrictions($room_id, $checkin, $checkout)) {
            continue;
        }

        $filtered[] = $row;
    }

    return $filtered;
}

/**
 * Create a temporary lock when a user selects a room.
 */
function create_temporary_reservation_lock(int $room_id, string $checkin, string $checkout, string $session_id = ''): bool
{
    if (!\function_exists(__NAMESPACE__ . '\create_room_lock')) {
        return false;
    }

    return create_room_lock($room_id, $checkin, $checkout, $session_id);
}

/**
 * Store reservation record after checkout, requiring a valid active lock.
 */
function store_reservation_from_lock(
    int $room_id,
    int $guest_id,
    string $checkin,
    string $checkout,
    int $guests,
    float $total_price,
    string $payment_status = 'unpaid',
    string $status = 'pending',
    string $session_id = ''
): int {
    global $wpdb;

    if (
        $room_id <= 0 ||
        $guest_id <= 0 ||
        $guests <= 0 ||
        !is_valid_booking_date($checkin) ||
        !is_valid_booking_date($checkout) ||
        $checkin >= $checkout
    ) {
        return 0;
    }

    if (!\function_exists(__NAMESPACE__ . '\has_active_exact_room_lock')) {
        return 0;
    }

    if (\function_exists(__NAMESPACE__ . '\cleanup_expired_locks')) {
        cleanup_expired_locks();
    }

    $session_id = $session_id !== ''
        ? (\function_exists(__NAMESPACE__ . '\normalize_lock_session_id') ? normalize_lock_session_id($session_id) : $session_id)
        : (\function_exists(__NAMESPACE__ . '\get_or_create_lock_session_id') ? get_or_create_lock_session_id() : '');

    if ($session_id === '' || !has_active_exact_room_lock($room_id, $checkin, $checkout, $session_id)) {
        return 0;
    }

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $locks_table = $wpdb->prefix . 'must_locks';
    $now = \function_exists(__NAMESPACE__ . '\get_current_utc_datetime') ? get_current_utc_datetime() : \gmdate('Y-m-d H:i:s');
    $booking_id = generate_unique_booking_id();

    $sql = $wpdb->prepare(
        "INSERT INTO {$reservations_table}
            (booking_id, room_id, guest_id, checkin, checkout, guests, status, total_price, payment_status, created_at)
        SELECT %s, %d, %d, %s, %s, %d, %s, %f, %s, %s
        WHERE EXISTS (
            SELECT 1
            FROM {$locks_table} l
            WHERE l.room_id = %d
                AND l.checkin = %s
                AND l.checkout = %s
                AND l.session_id = %s
                AND l.expires_at > %s
        )
        AND NOT EXISTS (
            SELECT 1
            FROM {$reservations_table} r
            WHERE r.room_id = %d
                AND r.checkin < %s
                AND r.checkout > %s
        )
        LIMIT 1",
        $booking_id,
        $room_id,
        $guest_id,
        $checkin,
        $checkout,
        $guests,
        $status,
        $total_price,
        $payment_status,
        $now,
        $room_id,
        $checkin,
        $checkout,
        $session_id,
        $now,
        $room_id,
        $checkout,
        $checkin
    );

    $insert_result = $wpdb->query($sql);

    if (!\is_int($insert_result) || $insert_result < 1) {
        return 0;
    }

    $reservation_id = (int) $wpdb->insert_id;

    if (\function_exists(__NAMESPACE__ . '\release_room_lock')) {
        release_room_lock($room_id, $checkin, $checkout, $session_id);
    }

    \do_action('must_hotel_booking/reservation_created', $reservation_id);

    return $reservation_id;
}

/**
 * Store a reservation without using a temporary lock, atomically rejecting overlaps.
 */
function create_reservation_without_lock(
    int $room_id,
    int $guest_id,
    string $checkin,
    string $checkout,
    int $guests,
    float $total_price,
    string $payment_status = 'unpaid',
    string $status = 'pending',
    string $booking_source = 'website',
    string $notes = ''
): int {
    global $wpdb;

    if (
        $room_id <= 0 ||
        $guest_id <= 0 ||
        $guests <= 0 ||
        !is_valid_booking_date($checkin) ||
        !is_valid_booking_date($checkout) ||
        $checkin >= $checkout
    ) {
        return 0;
    }

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $booking_id = generate_unique_booking_id();
    $created_at = \current_time('mysql');

    $sql = $wpdb->prepare(
        "INSERT INTO {$reservations_table}
            (booking_id, room_id, guest_id, checkin, checkout, guests, status, booking_source, notes, total_price, payment_status, created_at)
        SELECT %s, %d, %d, %s, %s, %d, %s, %s, %s, %f, %s, %s
        WHERE NOT EXISTS (
            SELECT 1
            FROM {$reservations_table} r
            WHERE r.room_id = %d
                AND r.checkin < %s
                AND r.checkout > %s
        )
        LIMIT 1",
        $booking_id,
        $room_id,
        $guest_id,
        $checkin,
        $checkout,
        $guests,
        $status,
        $booking_source,
        $notes,
        $total_price,
        $payment_status,
        $created_at,
        $room_id,
        $checkout,
        $checkin
    );

    $insert_result = $wpdb->query($sql);

    if (!\is_int($insert_result) || $insert_result < 1) {
        return 0;
    }

    $reservation_id = (int) $wpdb->insert_id;

    \do_action('must_hotel_booking/reservation_created', $reservation_id);

    return $reservation_id;
}

/**
 * Bootstrap reservation engine module.
 */
function bootstrap_reservation_engine(): void
{
    // Runtime hooks can be added here when frontend checkout handlers are implemented.
}

\add_action('must_hotel_booking/init', __NAMESPACE__ . '\bootstrap_reservation_engine');
