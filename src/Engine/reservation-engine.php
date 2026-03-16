<?php

namespace MustHotelBooking\Engine;

/**
 * Generate a unique booking ID for reservations.
 */
function generate_unique_booking_id(): string
{
    $repository = get_reservation_repository();
    $max_attempts = 8;
    $attempt = 0;

    while ($attempt < $max_attempts) {
        $suffix = \strtoupper(\substr(\str_replace('-', '', \wp_generate_uuid4()), 0, 8));
        $booking_id = 'MHB-' . \gmdate('Ymd') . '-' . $suffix;

        if (!$repository->bookingIdExists($booking_id)) {
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

    $now = \function_exists(__NAMESPACE__ . '\get_current_utc_datetime') ? get_current_utc_datetime() : \gmdate('Y-m-d H:i:s');
    $session_id = \function_exists(__NAMESPACE__ . '\get_or_create_lock_session_id') ? get_or_create_lock_session_id() : '';
    $non_blocking_statuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
        ? get_inventory_non_blocking_reservation_statuses()
        : ['cancelled', 'expired', 'payment_failed'];
    $rows = get_room_repository()->getAvailableRooms(
        $checkin,
        $checkout,
        $guests,
        $category,
        $non_blocking_statuses,
        $now,
        $session_id
    );

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
    return LockEngine::createLock($room_id, $checkin, $checkout, $session_id);
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
    string $session_id = '',
    int $rate_plan_id = 0
): int {
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

    $now = \function_exists(__NAMESPACE__ . '\get_current_utc_datetime') ? get_current_utc_datetime() : \gmdate('Y-m-d H:i:s');
    $booking_id = generate_unique_booking_id();
    $non_blocking_statuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
        ? get_inventory_non_blocking_reservation_statuses()
        : ['cancelled', 'expired', 'payment_failed'];
    $reservation_id = get_reservation_repository()->createReservationFromLock(
        [
            'booking_id' => $booking_id,
            'room_id' => $room_id,
            'rate_plan_id' => \max(0, $rate_plan_id),
            'guest_id' => $guest_id,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'status' => $status,
            'total_price' => $total_price,
            'payment_status' => $payment_status,
            'created_at' => $now,
        ],
        $session_id,
        $now,
        $non_blocking_statuses
    );

    if ($reservation_id <= 0) {
        return 0;
    }

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
    string $notes = '',
    int $rate_plan_id = 0
): int {
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

    $booking_id = generate_unique_booking_id();
    $created_at = \current_time('mysql');
    $non_blocking_statuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
        ? get_inventory_non_blocking_reservation_statuses()
        : ['cancelled', 'expired', 'payment_failed'];
    $reservation_id = get_reservation_repository()->createReservation(
        [
            'booking_id' => $booking_id,
            'room_id' => $room_id,
            'rate_plan_id' => \max(0, $rate_plan_id),
            'guest_id' => $guest_id,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'status' => $status,
            'booking_source' => $booking_source,
            'notes' => $notes,
            'total_price' => $total_price,
            'payment_status' => $payment_status,
            'created_at' => $created_at,
        ],
        $non_blocking_statuses
    );

    if ($reservation_id <= 0) {
        return 0;
    }

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
