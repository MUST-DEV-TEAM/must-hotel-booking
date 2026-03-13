<?php

namespace must_hotel_booking;

/**
 * Validate a date string in YYYY-MM-DD format.
 */
function is_valid_booking_date(string $date): bool
{
    $parsed = \DateTime::createFromFormat('Y-m-d', $date);

    return $parsed instanceof \DateTime && $parsed->format('Y-m-d') === $date;
}

/**
 * Get availability rules table name.
 */
function get_availability_rules_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_availability';
}

/**
 * Calculate nights between check-in and check-out for availability checks.
 */
function get_availability_nights_count(string $checkin, string $checkout): int
{
    if (!is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return 0;
    }

    try {
        $start = new \DateTimeImmutable($checkin);
        $end = new \DateTimeImmutable($checkout);
    } catch (\Exception $exception) {
        return 0;
    }

    return (int) $start->diff($end)->days;
}

/**
 * Validate if date is within an inclusive range.
 */
function is_date_within_inclusive_range(string $date, string $start_date, string $end_date): bool
{
    return $date >= $start_date && $date <= $end_date;
}

/**
 * Validate if booking range overlaps maintenance date range.
 */
function does_booking_overlap_maintenance_range(string $checkin, string $checkout, string $start_date, string $end_date): bool
{
    return $start_date < $checkout && $end_date >= $checkin;
}

/**
 * Load availability restriction rules for a room and global scope.
 *
 * @return array<int, array<string, mixed>>
 */
function get_availability_restriction_rules(int $room_id): array
{
    global $wpdb;

    if ($room_id <= 0) {
        return [];
    }

    $table_name = get_availability_rules_table_name();
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    if (!\is_string($table_exists) || $table_exists === '') {
        return [];
    }

    $sql = $wpdb->prepare(
        "SELECT
            id,
            room_id,
            availability_date,
            end_date,
            rule_type,
            rule_value
        FROM {$table_name}
        WHERE room_id IN (0, %d)
            AND rule_type IN ('minimum_stay', 'maximum_stay', 'closed_arrival', 'closed_departure', 'maintenance_block')
        ORDER BY room_id DESC, updated_at DESC, id DESC",
        $room_id
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Check booking restrictions from availability rules table.
 */
function check_booking_restrictions(int $room_id, string $checkin, string $checkout): bool
{
    if ($room_id <= 0 || !is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return false;
    }

    $nights = get_availability_nights_count($checkin, $checkout);

    if ($nights <= 0) {
        return false;
    }

    $rules = get_availability_restriction_rules($room_id);

    if (empty($rules)) {
        return true;
    }

    $minimum_stay = 0;
    $maximum_stay = 0;

    foreach ($rules as $rule) {
        if (!\is_array($rule)) {
            continue;
        }

        $rule_type = isset($rule['rule_type']) ? \sanitize_key((string) $rule['rule_type']) : '';
        $start_date = isset($rule['availability_date']) ? (string) $rule['availability_date'] : '';
        $end_date = isset($rule['end_date']) ? (string) $rule['end_date'] : '';

        if ($end_date === '') {
            $end_date = $start_date;
        }

        if (($start_date !== '' && !is_valid_booking_date($start_date)) || ($end_date !== '' && !is_valid_booking_date($end_date))) {
            continue;
        }

        if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) {
            continue;
        }

        if ($rule_type === 'minimum_stay') {
            $value = \max(0, (int) ($rule['rule_value'] ?? 0));

            if ($value > $minimum_stay) {
                $minimum_stay = $value;
            }

            continue;
        }

        if ($rule_type === 'maximum_stay') {
            $value = \max(0, (int) ($rule['rule_value'] ?? 0));

            if ($value > 0 && ($maximum_stay === 0 || $value < $maximum_stay)) {
                $maximum_stay = $value;
            }

            continue;
        }

        if ($start_date === '' || $end_date === '') {
            continue;
        }

        if ($rule_type === 'closed_arrival' && is_date_within_inclusive_range($checkin, $start_date, $end_date)) {
            return false;
        }

        if ($rule_type === 'closed_departure' && is_date_within_inclusive_range($checkout, $start_date, $end_date)) {
            return false;
        }

        if ($rule_type === 'maintenance_block' && does_booking_overlap_maintenance_range($checkin, $checkout, $start_date, $end_date)) {
            return false;
        }
    }

    if ($minimum_stay > 0 && $nights < $minimum_stay) {
        return false;
    }

    if ($maximum_stay > 0 && $nights > $maximum_stay) {
        return false;
    }

    return true;
}

/**
 * Check whether a reservation range overlaps an existing reservation.
 */
function has_room_reservation_overlap(int $room_id, string $checkin, string $checkout): bool
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'must_reservations';
    $non_blocking_statuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
        ? get_inventory_non_blocking_reservation_statuses()
        : ['cancelled', 'expired', 'payment_failed'];

    $sql = $wpdb->prepare(
        "SELECT 1
        FROM {$table_name}
        WHERE room_id = %d
            AND checkin < %s
            AND checkout > %s
            AND status NOT IN (%s, %s, %s)
        LIMIT 1",
        $room_id,
        $checkout,
        $checkin,
        (string) $non_blocking_statuses[0],
        (string) $non_blocking_statuses[1],
        (string) $non_blocking_statuses[2]
    );

    $overlap_found = $wpdb->get_var($sql);

    return $overlap_found !== null;
}

/**
 * Check if a room is available for the selected date range.
 */
function check_room_availability(int $room_id, string $checkin, string $checkout): bool
{
    if ($room_id <= 0) {
        return false;
    }

    if (!is_valid_booking_date($checkin) || !is_valid_booking_date($checkout)) {
        return false;
    }

    if ($checkin >= $checkout) {
        return false;
    }

    if (!check_booking_restrictions($room_id, $checkin, $checkout)) {
        return false;
    }

    return !has_room_reservation_overlap($room_id, $checkin, $checkout);
}

/**
 * Load a room row for availability lookups.
 *
 * @return array<string, mixed>|null
 */
function get_room_data_for_availability(int $room_id): ?array
{
    if ($room_id <= 0) {
        return null;
    }

    if (\function_exists(__NAMESPACE__ . '\get_room_record')) {
        $room = get_room_record($room_id);

        if (\is_array($room)) {
            return $room;
        }
    }

    global $wpdb;

    $room = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id, name, slug, category, description, max_guests, base_price, room_size, beds FROM ' . $wpdb->prefix . 'must_rooms WHERE id = %d LIMIT 1',
            $room_id
        ),
        ARRAY_A
    );

    return \is_array($room) ? $room : null;
}

/**
 * Check if a room can be booked for the given range and guest count.
 */
function is_room_bookable_for_range(int $room_id, string $checkin, string $checkout, int $guests = 1, string $exclude_session_id = ''): bool
{
    $room = get_room_data_for_availability($room_id);

    if (!\is_array($room)) {
        return false;
    }

    $guests = \max(1, $guests);
    $room_max_guests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

    if ($room_max_guests > 0 && $guests > $room_max_guests) {
        return false;
    }

    if (\function_exists(__NAMESPACE__ . '\cleanup_expired_locks')) {
        cleanup_expired_locks();
    }

    if (!check_room_availability($room_id, $checkin, $checkout)) {
        return false;
    }

    if (\function_exists(__NAMESPACE__ . '\has_active_room_lock_overlap')) {
        $session_id = $exclude_session_id !== ''
            ? $exclude_session_id
            : (\function_exists(__NAMESPACE__ . '\get_or_create_lock_session_id') ? get_or_create_lock_session_id() : '');

        if (has_active_room_lock_overlap($room_id, $checkin, $checkout, $session_id)) {
            return false;
        }
    }

    return true;
}

/**
 * Load one exact room for AJAX availability payloads.
 *
 * @return array<string, mixed>|null
 */
function get_available_room_for_ajax_by_id(int $room_id, string $checkin, string $checkout, int $guests = 1): ?array
{
    $room = get_room_data_for_availability($room_id);

    if (!\is_array($room) || !is_room_bookable_for_range($room_id, $checkin, $checkout, $guests)) {
        return null;
    }

    return $room;
}

/**
 * Get available rooms for a date range and guest count.
 *
 * @return array<int, array<string, mixed>>
 */
function get_available_rooms_for_ajax(string $checkin, string $checkout, int $guests = 1, string $category = 'standard-rooms'): array
{
    global $wpdb;

    if (!is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        return [];
    }

    $guests = \max(1, $guests);
    $category = normalize_availability_room_category($category);

    if (\function_exists(__NAMESPACE__ . '\cleanup_expired_locks')) {
        cleanup_expired_locks();
    }

    $rooms_table = $wpdb->prefix . 'must_rooms';
    $reservations_table = $wpdb->prefix . 'must_reservations';
    $locks_table = $wpdb->prefix . 'must_locks';
    $now = \function_exists(__NAMESPACE__ . '\get_current_utc_datetime') ? get_current_utc_datetime() : \gmdate('Y-m-d H:i:s');
    $non_blocking_statuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
        ? get_inventory_non_blocking_reservation_statuses()
        : ['cancelled', 'expired', 'payment_failed'];

    $sql = $wpdb->prepare(
        "SELECT
            r.id,
            r.name,
            r.slug,
            r.category,
            r.description,
            r.max_guests,
            r.base_price,
            r.room_size,
            r.beds
        FROM {$rooms_table} r
        WHERE r.category = %s
            AND r.max_guests >= %d
            AND NOT EXISTS (
                SELECT 1
                FROM {$reservations_table} existing
                WHERE existing.room_id = r.id
                    AND existing.checkin < %s
                    AND existing.checkout > %s
                    AND existing.status NOT IN (%s, %s, %s)
            )
            AND NOT EXISTS (
                SELECT 1
                FROM {$locks_table} locked
                WHERE locked.room_id = r.id
                    AND locked.checkin < %s
                    AND locked.checkout > %s
                    AND locked.expires_at > %s
            )
        ORDER BY r.name ASC, r.id ASC",
        $category,
        $guests,
        $checkout,
        $checkin,
        (string) $non_blocking_statuses[0],
        (string) $non_blocking_statuses[1],
        (string) $non_blocking_statuses[2],
        $checkout,
        $checkin,
        $now
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Normalize disabled-date lookup window.
 */
function normalize_disabled_dates_window_days(int $window_days): int
{
    if ($window_days < 30) {
        return 30;
    }

    if ($window_days > 365) {
        return 365;
    }

    return $window_days;
}

/**
 * Normalize room category for availability lookups.
 */
function normalize_availability_room_category(string $category): string
{
    if (\function_exists(__NAMESPACE__ . '\normalize_room_category')) {
        return normalize_room_category($category);
    }

    return 'standard-rooms';
}

/**
 * Load room IDs that can host the selected guest count.
 *
 * @return array<int, int>
 */
function get_available_room_ids_for_guests(int $guests, string $category = 'standard-rooms'): array
{
    global $wpdb;

    $guests = \max(1, $guests);
    $category = normalize_availability_room_category($category);
    $rooms_table = $wpdb->prefix . 'must_rooms';
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id
            FROM {$rooms_table}
            WHERE category = %s
                AND max_guests >= %d
            ORDER BY id ASC",
            $category,
            $guests
        )
    );

    if (!\is_array($rows)) {
        return [];
    }

    return \array_values(
        \array_filter(
            \array_map('\absint', $rows),
            static function (int $room_id): bool {
                return $room_id > 0;
            }
        )
    );
}

/**
 * Load max guest capacity for the provided room IDs.
 *
 * @param array<int, int> $room_ids
 * @return array<int, int>
 */
function get_room_capacity_map_for_room_ids(array $room_ids): array
{
    global $wpdb;

    $room_ids = \array_values(
        \array_filter(
            \array_map('\absint', $room_ids),
            static function (int $room_id): bool {
                return $room_id > 0;
            }
        )
    );

    if (empty($room_ids)) {
        return [];
    }

    $rooms_table = $wpdb->prefix . 'must_rooms';
    $room_ids_sql = \implode(',', $room_ids);
    $rows = $wpdb->get_results(
        "SELECT id, max_guests FROM {$rooms_table} WHERE id IN ({$room_ids_sql})",
        ARRAY_A
    );
    $capacity_map = [];

    if (!\is_array($rows)) {
        return $capacity_map;
    }

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $room_id = isset($row['id']) ? (int) $row['id'] : 0;
        $max_guests = isset($row['max_guests']) ? \max(1, (int) $row['max_guests']) : 1;

        if ($room_id > 0) {
            $capacity_map[$room_id] = $max_guests;
        }
    }

    return $capacity_map;
}

/**
 * Load reservation ranges for selected rooms and date span.
 *
 * @param array<int, int> $room_ids
 * @return array<int, array<int, array<string, string>>>
 */
function get_reservation_ranges_by_room_ids(array $room_ids, string $range_start, string $range_end_exclusive): array
{
    global $wpdb;

    $room_ids = \array_values(
        \array_filter(
            \array_map('\absint', $room_ids),
            static function (int $room_id): bool {
                return $room_id > 0;
            }
        )
    );

    if (
        empty($room_ids) ||
        !is_valid_booking_date($range_start) ||
        !is_valid_booking_date($range_end_exclusive) ||
        $range_start >= $range_end_exclusive
    ) {
        return [];
    }

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $room_ids_sql = \implode(',', $room_ids);
    $non_blocking_statuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
        ? get_inventory_non_blocking_reservation_statuses()
        : ['cancelled', 'expired', 'payment_failed'];
    $sql = $wpdb->prepare(
        "SELECT room_id, checkin, checkout
        FROM {$reservations_table}
        WHERE room_id IN ({$room_ids_sql})
            AND checkin < %s
            AND checkout > %s
            AND status NOT IN (%s, %s, %s)
        ORDER BY room_id ASC, checkin ASC, checkout ASC",
        $range_end_exclusive,
        $range_start,
        (string) $non_blocking_statuses[0],
        (string) $non_blocking_statuses[1],
        (string) $non_blocking_statuses[2]
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);
    $ranges_by_room = [];

    foreach ($room_ids as $room_id) {
        $ranges_by_room[$room_id] = [];
    }

    if (!\is_array($rows)) {
        return $ranges_by_room;
    }

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $room_id = isset($row['room_id']) ? (int) $row['room_id'] : 0;
        $checkin = isset($row['checkin']) ? (string) $row['checkin'] : '';
        $checkout = isset($row['checkout']) ? (string) $row['checkout'] : '';

        if (
            $room_id <= 0 ||
            !isset($ranges_by_room[$room_id]) ||
            !is_valid_booking_date($checkin) ||
            !is_valid_booking_date($checkout) ||
            $checkin >= $checkout
        ) {
            continue;
        }

        $ranges_by_room[$room_id][] = [
            'checkin' => $checkin,
            'checkout' => $checkout,
        ];
    }

    return $ranges_by_room;
}

/**
 * Check whether a room is free for the given range.
 *
 * @param array<int, array<string, string>> $room_ranges
 */
function is_room_free_for_range(array $room_ranges, string $checkin, string $checkout): bool
{
    foreach ($room_ranges as $range) {
        if (!\is_array($range)) {
            continue;
        }

        $existing_checkin = isset($range['checkin']) ? (string) $range['checkin'] : '';
        $existing_checkout = isset($range['checkout']) ? (string) $range['checkout'] : '';

        if (
            !is_valid_booking_date($existing_checkin) ||
            !is_valid_booking_date($existing_checkout) ||
            $existing_checkin >= $existing_checkout
        ) {
            continue;
        }

        if ($existing_checkin < $checkout && $existing_checkout > $checkin) {
            return false;
        }
    }

    return true;
}

/**
 * Check whether at least one room is available for the date range.
 *
 * @param array<int, int> $room_ids
 * @param array<int, array<int, array<string, string>>> $ranges_by_room
 */
function has_any_room_available_for_range(array $room_ids, array $ranges_by_room, string $checkin, string $checkout): bool
{
    foreach ($room_ids as $room_id) {
        $room_id = (int) $room_id;

        if ($room_id <= 0) {
            continue;
        }

        $room_ranges = isset($ranges_by_room[$room_id]) && \is_array($ranges_by_room[$room_id])
            ? $ranges_by_room[$room_id]
            : [];

        if (is_room_free_for_range($room_ranges, $checkin, $checkout)) {
            return true;
        }
    }

    return false;
}

/**
 * Check whether the free rooms can host the requested party across the selected room count.
 *
 * @param array<int, int> $room_ids
 * @param array<int, array<int, array<string, string>>> $ranges_by_room
 * @param array<int, int> $capacity_map
 */
function can_room_ids_host_party_for_range(
    array $room_ids,
    array $ranges_by_room,
    array $capacity_map,
    string $checkin,
    string $checkout,
    int $guests,
    int $room_count
): bool {
    $guests = \max(1, $guests);
    $room_count = \max(1, $room_count);
    $free_room_capacities = [];

    foreach ($room_ids as $room_id) {
        $room_id = (int) $room_id;

        if ($room_id <= 0) {
            continue;
        }

        $room_ranges = isset($ranges_by_room[$room_id]) && \is_array($ranges_by_room[$room_id])
            ? $ranges_by_room[$room_id]
            : [];

        if (!is_room_free_for_range($room_ranges, $checkin, $checkout)) {
            continue;
        }

        $capacity = isset($capacity_map[$room_id]) ? (int) $capacity_map[$room_id] : 0;

        if ($capacity > 0) {
            $free_room_capacities[] = $capacity;
        }
    }

    if (\count($free_room_capacities) < $room_count) {
        return false;
    }

    \rsort($free_room_capacities, SORT_NUMERIC);

    return \array_sum(\array_slice($free_room_capacities, 0, $room_count)) >= $guests;
}

/**
 * Check whether raw available room rows can host the requested party.
 *
 * @param array<int, array<string, mixed>> $rooms
 */
function can_available_room_rows_host_party(array $rooms, int $guests, int $room_count): bool
{
    $room_count = \max(1, $room_count);
    $capacities = [];

    foreach ($rooms as $room) {
        if (!\is_array($room)) {
            continue;
        }

        $capacity = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

        if ($capacity > 0) {
            $capacities[] = $capacity;
        }
    }

    if (\count($capacities) < $room_count) {
        return false;
    }

    \rsort($capacities, SORT_NUMERIC);

    return \array_sum(\array_slice($capacities, 0, $room_count)) >= \max(1, $guests);
}

/**
 * Build disabled check-in date list where all matching rooms are unavailable.
 *
 * @return array<int, string>
 */
function get_disabled_checkin_dates_for_guests(int $guests, int $window_days = 180, string $category = 'standard-rooms'): array
{
    $window_days = normalize_disabled_dates_window_days($window_days);
    $start_date = \current_time('Y-m-d');
    $start = new \DateTimeImmutable($start_date);
    $range_end_exclusive = $start->modify('+' . ($window_days + 1) . ' day')->format('Y-m-d');
    $room_ids = get_available_room_ids_for_guests($guests, $category);
    $disabled_dates = [];

    if (empty($room_ids)) {
        for ($index = 0; $index < $window_days; $index++) {
            $disabled_dates[] = $start->modify('+' . $index . ' day')->format('Y-m-d');
        }

        return $disabled_dates;
    }

    $ranges_by_room = get_reservation_ranges_by_room_ids($room_ids, $start_date, $range_end_exclusive);

    for ($index = 0; $index < $window_days; $index++) {
        $checkin = $start->modify('+' . $index . ' day')->format('Y-m-d');
        $checkout = $start->modify('+' . ($index + 1) . ' day')->format('Y-m-d');

        if (!has_any_room_available_for_range($room_ids, $ranges_by_room, $checkin, $checkout)) {
            $disabled_dates[] = $checkin;
        }
    }

    return $disabled_dates;
}

/**
 * Build disabled checkout dates for the selected check-in date.
 *
 * @return array<int, string>
 */
function get_disabled_checkout_dates_for_guests(string $checkin, int $guests, int $window_days = 180, string $category = 'standard-rooms'): array
{
    if (!is_valid_booking_date($checkin)) {
        return [];
    }

    $window_days = normalize_disabled_dates_window_days($window_days);
    $checkin_date = new \DateTimeImmutable($checkin);
    $range_end_exclusive = $checkin_date->modify('+' . ($window_days + 1) . ' day')->format('Y-m-d');
    $room_ids = get_available_room_ids_for_guests($guests, $category);
    $disabled_dates = [];

    if (empty($room_ids)) {
        for ($nights = 1; $nights <= $window_days; $nights++) {
            $disabled_dates[] = $checkin_date->modify('+' . $nights . ' day')->format('Y-m-d');
        }

        return $disabled_dates;
    }

    $ranges_by_room = get_reservation_ranges_by_room_ids($room_ids, $checkin, $range_end_exclusive);

    for ($nights = 1; $nights <= $window_days; $nights++) {
        $checkout_date = $checkin_date->modify('+' . $nights . ' day');
        $checkout = $checkout_date->format('Y-m-d');

        // A checkout date must stay within one continuous available stay.
        // If no single room can host the entire range from selected arrival to this departure,
        // the departure date must be disabled.
        if (!has_any_room_available_for_range($room_ids, $ranges_by_room, $checkin, $checkout)) {
            $disabled_dates[] = $checkout;
        }
    }

    return $disabled_dates;
}

/**
 * Build disabled check-in dates for a multi-room party.
 *
 * @return array<int, string>
 */
function get_disabled_checkin_dates_for_party(int $guests, int $room_count, int $window_days = 180, string $category = 'standard-rooms'): array
{
    $window_days = normalize_disabled_dates_window_days($window_days);
    $start_date = \current_time('Y-m-d');
    $start = new \DateTimeImmutable($start_date);
    $range_end_exclusive = $start->modify('+' . ($window_days + 1) . ' day')->format('Y-m-d');
    $room_ids = get_available_room_ids_for_guests(1, $category);
    $disabled_dates = [];

    if (empty($room_ids)) {
        for ($index = 0; $index < $window_days; $index++) {
            $disabled_dates[] = $start->modify('+' . $index . ' day')->format('Y-m-d');
        }

        return $disabled_dates;
    }

    $ranges_by_room = get_reservation_ranges_by_room_ids($room_ids, $start_date, $range_end_exclusive);
    $capacity_map = get_room_capacity_map_for_room_ids($room_ids);

    for ($index = 0; $index < $window_days; $index++) {
        $checkin = $start->modify('+' . $index . ' day')->format('Y-m-d');
        $checkout = $start->modify('+' . ($index + 1) . ' day')->format('Y-m-d');

        if (!can_room_ids_host_party_for_range($room_ids, $ranges_by_room, $capacity_map, $checkin, $checkout, $guests, $room_count)) {
            $disabled_dates[] = $checkin;
        }
    }

    return $disabled_dates;
}

/**
 * Build disabled checkout dates for a multi-room party.
 *
 * @return array<int, string>
 */
function get_disabled_checkout_dates_for_party(string $checkin, int $guests, int $room_count, int $window_days = 180, string $category = 'standard-rooms'): array
{
    if (!is_valid_booking_date($checkin)) {
        return [];
    }

    $window_days = normalize_disabled_dates_window_days($window_days);
    $checkin_date = new \DateTimeImmutable($checkin);
    $range_end_exclusive = $checkin_date->modify('+' . ($window_days + 1) . ' day')->format('Y-m-d');
    $room_ids = get_available_room_ids_for_guests(1, $category);
    $disabled_dates = [];

    if (empty($room_ids)) {
        for ($nights = 1; $nights <= $window_days; $nights++) {
            $disabled_dates[] = $checkin_date->modify('+' . $nights . ' day')->format('Y-m-d');
        }

        return $disabled_dates;
    }

    $ranges_by_room = get_reservation_ranges_by_room_ids($room_ids, $checkin, $range_end_exclusive);
    $capacity_map = get_room_capacity_map_for_room_ids($room_ids);

    for ($nights = 1; $nights <= $window_days; $nights++) {
        $checkout = $checkin_date->modify('+' . $nights . ' day')->format('Y-m-d');

        if (!can_room_ids_host_party_for_range($room_ids, $ranges_by_room, $capacity_map, $checkin, $checkout, $guests, $room_count)) {
            $disabled_dates[] = $checkout;
        }
    }

    return $disabled_dates;
}

/**
 * Build disabled check-in dates for one exact room.
 *
 * @return array<int, string>
 */
function get_disabled_checkin_dates_for_room(int $room_id, int $guests, int $window_days = 180): array
{
    $window_days = normalize_disabled_dates_window_days($window_days);
    $start = new \DateTimeImmutable(\current_time('Y-m-d'));
    $disabled_dates = [];

    for ($index = 0; $index < $window_days; $index++) {
        $checkin = $start->modify('+' . $index . ' day')->format('Y-m-d');
        $checkout = $start->modify('+' . ($index + 1) . ' day')->format('Y-m-d');

        if (!is_room_bookable_for_range($room_id, $checkin, $checkout, $guests)) {
            $disabled_dates[] = $checkin;
        }
    }

    return $disabled_dates;
}

/**
 * Build disabled checkout dates for one exact room.
 *
 * @return array<int, string>
 */
function get_disabled_checkout_dates_for_room(string $checkin, int $room_id, int $guests, int $window_days = 180): array
{
    if (!is_valid_booking_date($checkin)) {
        return [];
    }

    $window_days = normalize_disabled_dates_window_days($window_days);
    $checkin_date = new \DateTimeImmutable($checkin);
    $disabled_dates = [];

    for ($nights = 1; $nights <= $window_days; $nights++) {
        $checkout = $checkin_date->modify('+' . $nights . ' day')->format('Y-m-d');

        if (!is_room_bookable_for_range($room_id, $checkin, $checkout, $guests)) {
            $disabled_dates[] = $checkout;
        }
    }

    return $disabled_dates;
}

/**
 * AJAX endpoint for disabled booking dates.
 */
function ajax_must_get_disabled_dates(): void
{
    $max_booking_guests = \function_exists(__NAMESPACE__ . '\get_max_booking_guests_limit')
        ? get_max_booking_guests_limit()
        : 5;
    $guests = isset($_REQUEST['guests'])
        ? \max(1, \min($max_booking_guests, \absint(\wp_unslash($_REQUEST['guests']))))
        : 1;
    $room_count = \function_exists(__NAMESPACE__ . '\normalize_booking_room_count')
        ? normalize_booking_room_count($_REQUEST['room_count'] ?? 0)
        : 0;
    $window_days = isset($_REQUEST['window_days']) ? \absint(\wp_unslash($_REQUEST['window_days'])) : 180;
    $window_days = normalize_disabled_dates_window_days($window_days);
    $checkin = isset($_REQUEST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_REQUEST['checkin'])) : '';
    $room_id = isset($_REQUEST['room_id']) ? \absint(\wp_unslash($_REQUEST['room_id'])) : 0;
    $accommodation_type = isset($_REQUEST['accommodation_type'])
        ? normalize_availability_room_category(\sanitize_text_field((string) \wp_unslash($_REQUEST['accommodation_type'])))
        : 'standard-rooms';

    if ($checkin !== '' && !is_valid_booking_date($checkin)) {
        \wp_send_json_error(
            [
                'message' => \__('Invalid check-in date.', 'must-hotel-booking'),
            ],
            400
        );
    }

    if ($room_id > 0) {
        $disabled_checkin_dates = get_disabled_checkin_dates_for_room($room_id, $guests, $window_days);
        $disabled_checkout_dates = $checkin !== ''
            ? get_disabled_checkout_dates_for_room($checkin, $room_id, $guests, $window_days)
            : [];
    } else {
        $resolved_room_count = \function_exists(__NAMESPACE__ . '\resolve_booking_room_count')
            ? resolve_booking_room_count($guests, $room_count, $accommodation_type)
            : 1;

        if ($resolved_room_count > 1) {
            $disabled_checkin_dates = get_disabled_checkin_dates_for_party($guests, $resolved_room_count, $window_days, $accommodation_type);
            $disabled_checkout_dates = $checkin !== ''
                ? get_disabled_checkout_dates_for_party($checkin, $guests, $resolved_room_count, $window_days, $accommodation_type)
                : [];
        } else {
            $disabled_checkin_dates = get_disabled_checkin_dates_for_guests($guests, $window_days, $accommodation_type);
            $disabled_checkout_dates = $checkin !== ''
                ? get_disabled_checkout_dates_for_guests($checkin, $guests, $window_days, $accommodation_type)
                : [];
        }
    }

    \wp_send_json_success(
        [
            'room_id' => $room_id,
            'guests' => $guests,
            'room_count' => $room_count,
            'checkin' => $checkin,
            'accommodation_type' => $accommodation_type,
            'window_days' => $window_days,
            'disabled_checkin_dates' => $disabled_checkin_dates,
            'disabled_checkout_dates' => $disabled_checkout_dates,
        ]
    );
}

/**
 * AJAX endpoint for room availability checks.
 */
function ajax_must_check_availability(): void
{
    $checkin = isset($_REQUEST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_REQUEST['checkin'])) : '';
    $checkout = isset($_REQUEST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_REQUEST['checkout'])) : '';
    $max_booking_guests = \function_exists(__NAMESPACE__ . '\get_max_booking_guests_limit')
        ? get_max_booking_guests_limit()
        : 5;
    $guests = isset($_REQUEST['guests'])
        ? \max(1, \min($max_booking_guests, \absint(\wp_unslash($_REQUEST['guests']))))
        : 1;
    $room_count = \function_exists(__NAMESPACE__ . '\normalize_booking_room_count')
        ? normalize_booking_room_count($_REQUEST['room_count'] ?? 0)
        : 0;
    $room_id = isset($_REQUEST['room_id']) ? \absint(\wp_unslash($_REQUEST['room_id'])) : 0;
    $accommodation_type = isset($_REQUEST['accommodation_type'])
        ? normalize_availability_room_category(\sanitize_text_field((string) \wp_unslash($_REQUEST['accommodation_type'])))
        : 'standard-rooms';

    if (!is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
        \wp_send_json_error(
            [
                'message' => \__('Invalid check-in/check-out dates.', 'must-hotel-booking'),
            ],
            400
        );
    }

    $rooms = [];
    $message = '';

    if ($room_id > 0) {
        $room = get_available_room_for_ajax_by_id($room_id, $checkin, $checkout, $guests);

        if (\is_array($room)) {
            $rooms[] = $room;
        } else {
            $message = \__('The selected room is not available for the chosen dates and party size.', 'must-hotel-booking');
        }
    } else {
        $resolved_room_count = \function_exists(__NAMESPACE__ . '\resolve_booking_room_count')
            ? resolve_booking_room_count($guests, $room_count, $accommodation_type)
            : 1;

        $rooms = get_available_rooms_for_ajax(
            $checkin,
            $checkout,
            $resolved_room_count > 1 ? 1 : $guests,
            $accommodation_type
        );

        if ($resolved_room_count > 1 && !can_available_room_rows_host_party($rooms, $guests, $resolved_room_count)) {
            $rooms = [];
        }

        if (empty($rooms)) {
            $message = \function_exists(__NAMESPACE__ . '\get_accommodation_empty_results_message')
                ? get_accommodation_empty_results_message(
                    [
                        'guests' => $guests,
                        'room_count' => $room_count,
                        'accommodation_type' => $accommodation_type,
                    ],
                    $resolved_room_count
                )
                : \__('No rooms are available for the selected dates.', 'must-hotel-booking');
        }
    }

    $payload = [];

    foreach ($rooms as $room) {
        if (!\is_array($room)) {
            continue;
        }

        $room_id = isset($room['id']) ? (int) $room['id'] : 0;

        if ($room_id <= 0 || !check_booking_restrictions($room_id, $checkin, $checkout)) {
            continue;
        }

        $dynamic_total_price = null;
        $dynamic_room_subtotal = null;
        $dynamic_nights = null;

        if (\function_exists(__NAMESPACE__ . '\calculate_booking_price')) {
            $pricing = calculate_booking_price(
                $room_id,
                $checkin,
                $checkout,
                $guests
            );

            if (\is_array($pricing) && !empty($pricing['success'])) {
                if (isset($pricing['total_price'])) {
                    $dynamic_total_price = (float) $pricing['total_price'];
                }

                if (isset($pricing['room_subtotal'])) {
                    $dynamic_room_subtotal = (float) $pricing['room_subtotal'];
                }

                if (isset($pricing['nights'])) {
                    $dynamic_nights = (int) $pricing['nights'];
                }
            }
        }

        $room_payload = [
            'id' => $room_id,
            'name' => isset($room['name']) ? (string) $room['name'] : '',
            'slug' => isset($room['slug']) ? (string) $room['slug'] : '',
            'category' => isset($room['category']) ? (string) $room['category'] : 'standard-rooms',
            'description' => isset($room['description']) ? (string) $room['description'] : '',
            'max_guests' => isset($room['max_guests']) ? (int) $room['max_guests'] : 0,
            'base_price' => isset($room['base_price']) ? (float) $room['base_price'] : 0.0,
            'room_size' => isset($room['room_size']) ? (string) $room['room_size'] : '',
            'beds' => isset($room['beds']) ? (string) $room['beds'] : '',
            'calculated_price' => $dynamic_total_price,
            'dynamic_total_price' => $dynamic_total_price,
            'dynamic_room_subtotal' => $dynamic_room_subtotal,
            'dynamic_nights' => $dynamic_nights,
        ];

        if (\function_exists(__NAMESPACE__ . '\get_booking_results_room_view_data')) {
            $enriched_payload = get_booking_results_room_view_data($room_payload);

            if ($enriched_payload !== null) {
                $payload[] = $enriched_payload;
                continue;
            }
        }

        $payload[] = $room_payload;
    }

    \wp_send_json_success(
        [
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'room_count' => $room_count,
            'accommodation_type' => $accommodation_type,
            'message' => $message,
            'rooms' => $payload,
        ]
    );
}

\add_action('wp_ajax_must_check_availability', __NAMESPACE__ . '\ajax_must_check_availability');
\add_action('wp_ajax_nopriv_must_check_availability', __NAMESPACE__ . '\ajax_must_check_availability');
\add_action('wp_ajax_must_get_disabled_dates', __NAMESPACE__ . '\ajax_must_get_disabled_dates');
\add_action('wp_ajax_nopriv_must_get_disabled_dates', __NAMESPACE__ . '\ajax_must_get_disabled_dates');
