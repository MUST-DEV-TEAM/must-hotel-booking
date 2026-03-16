<?php

namespace MustHotelBooking\Admin;

/**
 * Build Calendar admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_calendar_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-calendar');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Validate and normalize calendar date.
 */
function sanitize_calendar_date(string $date, string $fallback): string
{
    $candidate = \trim($date);

    if (\function_exists(__NAMESPACE__ . '\is_valid_booking_date') && is_valid_booking_date($candidate)) {
        return $candidate;
    }

    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

    if ($parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $candidate) {
        return $candidate;
    }

    return $fallback;
}

/**
 * Get selected calendar range start date.
 */
function get_calendar_start_date(): string
{
    $today = \current_time('Y-m-d');
    $input = isset($_GET['start']) ? \sanitize_text_field((string) \wp_unslash($_GET['start'])) : $today;

    return sanitize_calendar_date($input, $today);
}

/**
 * Get selected calendar date window size.
 */
function get_calendar_days_count(): int
{
    $days = isset($_GET['days']) ? \absint(\wp_unslash($_GET['days'])) : 30;

    if ($days < 7) {
        return 7;
    }

    if ($days > 90) {
        return 90;
    }

    return $days;
}

/**
 * Build calendar range metadata.
 *
 * @return array{start: string, end_exclusive: string, dates: array<int, string>, days: int}
 */
function get_calendar_range_data(string $start_date, int $days): array
{
    $start = new \DateTimeImmutable($start_date);
    $dates = [];

    for ($index = 0; $index < $days; $index++) {
        $dates[] = $start->modify('+' . $index . ' day')->format('Y-m-d');
    }

    return [
        'start' => $start_date,
        'end_exclusive' => $start->modify('+' . $days . ' day')->format('Y-m-d'),
        'dates' => $dates,
        'days' => $days,
    ];
}

/**
 * Get rooms for calendar vertical axis.
 *
 * @return array<int, array<string, mixed>>
 */
function get_calendar_rooms(): array
{
    global $wpdb;

    $rooms_table = $wpdb->prefix . 'must_rooms';
    $rows = $wpdb->get_results("SELECT id, name FROM {$rooms_table} ORDER BY name ASC, id ASC", ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Load reservations overlapping calendar range.
 *
 * @return array<int, array<string, mixed>>
 */
function get_calendar_reservations(string $start_date, string $end_exclusive): array
{
    global $wpdb;

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $rooms_table = $wpdb->prefix . 'must_rooms';
    $guests_table = $wpdb->prefix . 'must_guests';

    $sql = $wpdb->prepare(
        "SELECT
            r.id,
            r.room_id,
            r.guest_id,
            r.checkin,
            r.checkout,
            r.guests,
            r.status,
            r.total_price,
            r.payment_status,
            r.created_at,
            rm.name AS room_name,
            CONCAT_WS(' ', g.first_name, g.last_name) AS guest_name
        FROM {$reservations_table} r
        LEFT JOIN {$rooms_table} rm ON rm.id = r.room_id
        LEFT JOIN {$guests_table} g ON g.id = r.guest_id
        WHERE r.checkin < %s
            AND r.checkout > %s
        ORDER BY r.room_id ASC, r.checkin ASC, r.checkout ASC, r.id ASC",
        $end_exclusive,
        $start_date
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Group reservations by room ID.
 *
 * @param array<int, array<string, mixed>> $reservations
 * @return array<int, array<int, array<string, mixed>>>
 */
function group_calendar_reservations_by_room(array $reservations): array
{
    $grouped = [];

    foreach ($reservations as $reservation) {
        $room_id = isset($reservation['room_id']) ? (int) $reservation['room_id'] : 0;

        if ($room_id <= 0) {
            continue;
        }

        if (!isset($grouped[$room_id])) {
            $grouped[$room_id] = [];
        }

        $grouped[$room_id][] = $reservation;
    }

    return $grouped;
}

/**
 * Build display blocks for one room row.
 *
 * @param array<int, array<string, mixed>> $room_reservations
 * @return array<int, array<string, mixed>>
 */
function build_room_calendar_blocks(array $room_reservations, string $range_start, string $range_end_exclusive): array
{
    $range_start_obj = new \DateTimeImmutable($range_start);
    $blocks = [];

    foreach ($room_reservations as $reservation) {
        $checkin = isset($reservation['checkin']) ? (string) $reservation['checkin'] : '';
        $checkout = isset($reservation['checkout']) ? (string) $reservation['checkout'] : '';

        if (
            !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
            !is_valid_booking_date($checkin) ||
            !is_valid_booking_date($checkout) ||
            $checkin >= $checkout
        ) {
            continue;
        }

        $visible_start = $checkin > $range_start ? $checkin : $range_start;
        $visible_end = $checkout < $range_end_exclusive ? $checkout : $range_end_exclusive;

        if ($visible_start >= $visible_end) {
            continue;
        }

        $visible_start_obj = new \DateTimeImmutable($visible_start);
        $visible_end_obj = new \DateTimeImmutable($visible_end);

        $start_index = (int) $range_start_obj->diff($visible_start_obj)->days;
        $end_index = (int) $range_start_obj->diff($visible_end_obj)->days;

        if ($end_index <= $start_index) {
            continue;
        }

        $status = isset($reservation['status']) ? (string) $reservation['status'] : '';
        $label = $status === 'blocked'
            ? \__('Blocked', 'must-hotel-booking')
            : \sprintf(
                /* translators: %d is reservation ID. */
                \__('Res #%d', 'must-hotel-booking'),
                (int) $reservation['id']
            );

        $guest_name = isset($reservation['guest_name']) ? \trim((string) $reservation['guest_name']) : '';

        if ($guest_name !== '' && $status !== 'blocked') {
            $label .= ': ' . $guest_name;
        }

        $blocks[] = [
            'reservation_id' => (int) $reservation['id'],
            'status' => $status,
            'start_index' => $start_index,
            'end_index' => $end_index,
            'span' => $end_index - $start_index,
            'label' => $label,
            'checkin' => $checkin,
            'checkout' => $checkout,
        ];
    }

    \usort(
        $blocks,
        static function (array $left, array $right): int {
            if ($left['start_index'] === $right['start_index']) {
                return $left['end_index'] <=> $right['end_index'];
            }

            return $left['start_index'] <=> $right['start_index'];
        }
    );

    return $blocks;
}

/**
 * Get reservation details for details panel.
 *
 * @return array<string, mixed>|null
 */
function get_calendar_reservation_details(int $reservation_id): ?array
{
    global $wpdb;

    if ($reservation_id <= 0) {
        return null;
    }

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $rooms_table = $wpdb->prefix . 'must_rooms';
    $guests_table = $wpdb->prefix . 'must_guests';

    $sql = $wpdb->prepare(
        "SELECT
            r.*,
            rm.name AS room_name,
            g.first_name,
            g.last_name,
            g.email,
            g.phone,
            g.country
        FROM {$reservations_table} r
        LEFT JOIN {$rooms_table} rm ON rm.id = r.room_id
        LEFT JOIN {$guests_table} g ON g.id = r.guest_id
        WHERE r.id = %d
        LIMIT 1",
        $reservation_id
    );

    $row = $wpdb->get_row($sql, ARRAY_A);

    return \is_array($row) ? $row : null;
}

/**
 * Get reservation status options for calendar editing.
 *
 * @return array<string, string>
 */
function get_calendar_reservation_status_options(): array
{
    if (\function_exists(__NAMESPACE__ . '\get_reservation_status_options')) {
        $options = get_reservation_status_options();

        if (\is_array($options) && !empty($options)) {
            return $options;
        }
    }

    return [
        'pending' => \__('Pending', 'must-hotel-booking'),
        'confirmed' => \__('Confirmed', 'must-hotel-booking'),
        'cancelled' => \__('Cancelled', 'must-hotel-booking'),
        'completed' => \__('Completed', 'must-hotel-booking'),
        'blocked' => \__('Blocked', 'must-hotel-booking'),
    ];
}

/**
 * Get booking source options for manual reservations.
 *
 * @return array<string, string>
 */
function get_calendar_booking_source_options(): array
{
    return [
        'website' => \__('Website', 'must-hotel-booking'),
        'phone' => \__('Phone', 'must-hotel-booking'),
        'walk_in' => \__('Walk-in', 'must-hotel-booking'),
        'booking_com' => \__('Booking.com', 'must-hotel-booking'),
        'airbnb' => \__('Airbnb', 'must-hotel-booking'),
        'agency' => \__('Agency', 'must-hotel-booking'),
    ];
}

/**
 * Get booking source label from key.
 */
function get_calendar_booking_source_label(string $source): string
{
    $options = get_calendar_booking_source_options();
    $key = \sanitize_key($source);

    if (isset($options[$key])) {
        return (string) $options[$key];
    }

    return (string) ($options['website'] ?? \__('Website', 'must-hotel-booking'));
}

/**
 * Get status options allowed for manual reservation creation.
 *
 * @return array<string, string>
 */
function get_calendar_manual_create_status_options(): array
{
    $status_options = get_calendar_reservation_status_options();
    $allowed = ['pending', 'confirmed'];
    $filtered = [];

    foreach ($allowed as $status_key) {
        if (isset($status_options[$status_key])) {
            $filtered[$status_key] = (string) $status_options[$status_key];
        }
    }

    if (empty($filtered)) {
        return [
            'pending' => \__('Pending', 'must-hotel-booking'),
            'confirmed' => \__('Confirmed', 'must-hotel-booking'),
        ];
    }

    return $filtered;
}

/**
 * Split a full guest name into first and last parts.
 *
 * @return array{first_name: string, last_name: string}
 */
function split_calendar_guest_name(string $guest_name): array
{
    $normalized = \trim((string) \preg_replace('/\s+/', ' ', $guest_name));

    if ($normalized === '') {
        return [
            'first_name' => '',
            'last_name' => '',
        ];
    }

    $parts = \explode(' ', $normalized);
    $first_name = (string) \array_shift($parts);
    $last_name = \trim(\implode(' ', $parts));

    return [
        'first_name' => $first_name,
        'last_name' => $last_name,
    ];
}

/**
 * Create or update guest record and return guest ID.
 */
function save_calendar_guest_record(int $guest_id, string $guest_name, string $email, string $phone): int
{
    global $wpdb;

    $name_parts = split_calendar_guest_name($guest_name);
    $first_name = (string) $name_parts['first_name'];
    $last_name = (string) $name_parts['last_name'];
    $guests_table = $wpdb->prefix . 'must_guests';

    if ($guest_id > 0) {
        $updated = $wpdb->update(
            $guests_table,
            [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
            ],
            ['id' => $guest_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated !== false) {
            return $guest_id;
        }
    }

    if ($email !== '') {
        $existing_guest_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$guests_table} WHERE email = %s LIMIT 1",
                $email
            )
        );

        if ($existing_guest_id > 0) {
            $updated = $wpdb->update(
                $guests_table,
                [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'phone' => $phone,
                ],
                ['id' => $existing_guest_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if ($updated !== false) {
                return $existing_guest_id;
            }
        }
    }

    $inserted = $wpdb->insert(
        $guests_table,
        [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'country' => '',
        ],
        ['%s', '%s', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        return 0;
    }

    return (int) $wpdb->insert_id;
}

/**
 * Calculate reservation total for manual admin reservation creation/edit.
 */
function calculate_calendar_reservation_total_price(int $room_id, string $checkin, string $checkout, int $guests): float
{
    if (\function_exists(__NAMESPACE__ . '\calculate_booking_price')) {
        $pricing = calculate_booking_price($room_id, $checkin, $checkout, $guests);

        if (\is_array($pricing) && !empty($pricing['success']) && isset($pricing['total_price'])) {
            return (float) $pricing['total_price'];
        }
    }

    return 0.0;
}

/**
 * Get status class for reservation block rendering.
 */
function get_calendar_block_status_class(string $status): string
{
    $normalized = \sanitize_key($status);
    $known_statuses = ['confirmed', 'pending', 'blocked', 'cancelled', 'completed'];

    if ($normalized === '' || !\in_array($normalized, $known_statuses, true)) {
        $normalized = 'generic';
    }

    return 'status-' . $normalized;
}

/**
 * Check if room exists.
 */
function does_calendar_room_exist(int $room_id): bool
{
    global $wpdb;

    if ($room_id <= 0) {
        return false;
    }

    $rooms_table = $wpdb->prefix . 'must_rooms';
    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT 1 FROM {$rooms_table} WHERE id = %d LIMIT 1",
            $room_id
        )
    );

    return $exists !== null;
}

/**
 * Check overlap for a reservation update.
 */
function has_calendar_reservation_overlap(int $reservation_id, int $room_id, string $checkin, string $checkout): bool
{
    global $wpdb;

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $sql = $wpdb->prepare(
        "SELECT 1
        FROM {$reservations_table}
        WHERE room_id = %d
            AND id <> %d
            AND checkin < %s
            AND checkout > %s
        LIMIT 1",
        $room_id,
        $reservation_id,
        $checkout,
        $checkin
    );

    return $wpdb->get_var($sql) !== null;
}

/**
 * Build redirect args preserving calendar state.
 *
 * @return array<string, scalar>
 */
function get_calendar_state_redirect_args(int $reservation_id = 0): array
{
    $args = [
        'start' => get_calendar_start_date(),
        'days' => get_calendar_days_count(),
    ];

    if ($reservation_id > 0) {
        $args['reservation_id'] = $reservation_id;
    }

    return $args;
}

/**
 * Handle update reservation action from calendar details panel.
 *
 * @return array<int, string>
 */
function maybe_handle_calendar_reservation_update_submission(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_calendar_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_calendar_action'])) : '';

    if ($action !== 'update_reservation') {
        return [];
    }

    $reservation_id = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
    $nonce = isset($_POST['must_calendar_update_reservation_nonce']) ? (string) \wp_unslash($_POST['must_calendar_update_reservation_nonce']) : '';

    if ($reservation_id <= 0 || !\wp_verify_nonce($nonce, 'must_calendar_update_reservation_' . $reservation_id)) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    $reservation = get_calendar_reservation_details($reservation_id);

    if (!\is_array($reservation)) {
        return [\__('Reservation not found.', 'must-hotel-booking')];
    }

    $room_id = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
    $guests_count = isset($_POST['guests'])
        ? \max(1, \absint(\wp_unslash($_POST['guests'])))
        : \max(1, (int) ($reservation['guests'] ?? 1));
    $status = isset($_POST['status']) ? \sanitize_key((string) \wp_unslash($_POST['status'])) : '';
    $guest_name = isset($_POST['guest_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_name'])) : '';
    $guest_phone = isset($_POST['phone']) ? \sanitize_text_field((string) \wp_unslash($_POST['phone'])) : '';
    $guest_email = isset($_POST['email']) ? \sanitize_email((string) \wp_unslash($_POST['email'])) : '';
    $booking_source = isset($_POST['booking_source']) ? \sanitize_key((string) \wp_unslash($_POST['booking_source'])) : 'website';
    $notes = isset($_POST['notes']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['notes'])) : '';
    $errors = [];

    if (!does_calendar_room_exist($room_id)) {
        $errors[] = \__('Please select a valid room.', 'must-hotel-booking');
    }

    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !is_valid_booking_date($checkin) ||
        !is_valid_booking_date($checkout)
    ) {
        $errors[] = \__('Please provide valid check-in and check-out dates.', 'must-hotel-booking');
    } elseif ($checkin >= $checkout) {
        $errors[] = \__('Checkout must be after check-in.', 'must-hotel-booking');
    }

    $status_options = get_calendar_reservation_status_options();

    if (!isset($status_options[$status])) {
        $errors[] = \__('Please select a valid reservation status.', 'must-hotel-booking');
    }

    $booking_source_options = get_calendar_booking_source_options();

    if (!isset($booking_source_options[$booking_source])) {
        $errors[] = \__('Please select a valid booking source.', 'must-hotel-booking');
    }

    if ($status === 'blocked') {
        $guests_count = 1;

        if ($guest_email !== '' && !\is_email($guest_email)) {
            $errors[] = \__('Please provide a valid guest email.', 'must-hotel-booking');
        }
    } else {
        if ($guest_name === '') {
            $errors[] = \__('Please provide the guest name.', 'must-hotel-booking');
        }

        if ($guest_email === '' || !\is_email($guest_email)) {
            $errors[] = \__('Please provide a valid guest email.', 'must-hotel-booking');
        }
    }

    if (empty($errors) && has_calendar_reservation_overlap($reservation_id, $room_id, $checkin, $checkout)) {
        $errors[] = \__('The selected room is not available for these dates.', 'must-hotel-booking');
    }

    if (!empty($errors)) {
        return $errors;
    }

    global $wpdb;

    $guest_id = (int) ($reservation['guest_id'] ?? 0);

    if ($status === 'blocked') {
        $guest_id = 0;
    } else {
        $guest_id = save_calendar_guest_record($guest_id, $guest_name, $guest_email, $guest_phone);

        if ($guest_id <= 0) {
            return [\__('Unable to save guest details.', 'must-hotel-booking')];
        }
    }

    $total_price = $status === 'blocked'
        ? 0.0
        : calculate_calendar_reservation_total_price($room_id, $checkin, $checkout, $guests_count);
    $payment_status = \sanitize_key((string) ($reservation['payment_status'] ?? 'unpaid'));

    if ($status === 'blocked') {
        $payment_status = 'blocked';
    } elseif ($payment_status === '' || $payment_status === 'blocked') {
        $payment_status = 'unpaid';
    }

    $previous_status = \sanitize_key((string) ($reservation['status'] ?? ''));
    $updated = $wpdb->update(
        $wpdb->prefix . 'must_reservations',
        [
            'room_id' => $room_id,
            'guest_id' => $guest_id,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests_count,
            'status' => $status,
            'booking_source' => $booking_source,
            'notes' => $notes,
            'total_price' => $total_price,
            'payment_status' => $payment_status,
        ],
        ['id' => $reservation_id],
        ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s'],
        ['%d']
    );

    if ($updated === false) {
        return [\__('Unable to update reservation.', 'must-hotel-booking')];
    }

    if ($previous_status !== 'cancelled' && $status === 'cancelled') {
        \do_action('must_hotel_booking/reservation_cancelled', $reservation_id);
    }

    $redirect_args = get_calendar_state_redirect_args($reservation_id);
    $redirect_args['notice'] = 'reservation_updated';
    \wp_safe_redirect(get_admin_calendar_page_url($redirect_args));
    exit;
}

/**
 * Handle create-manual-reservation action from calendar.
 *
 * @return array<string, mixed>
 */
function maybe_handle_calendar_manual_reservation_submission(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $action = isset($_POST['must_calendar_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_calendar_action'])) : '';

    if ($action !== 'create_manual_reservation') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $nonce = isset($_POST['must_calendar_create_reservation_nonce']) ? (string) \wp_unslash($_POST['must_calendar_create_reservation_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_calendar_create_reservation')) {
        return [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];
    }

    $room_id = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
    $guest_name = isset($_POST['guest_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_name'])) : '';
    $phone = isset($_POST['phone']) ? \sanitize_text_field((string) \wp_unslash($_POST['phone'])) : '';
    $email = isset($_POST['email']) ? \sanitize_email((string) \wp_unslash($_POST['email'])) : '';
    $guests_count = isset($_POST['guests']) ? \max(1, \absint(\wp_unslash($_POST['guests']))) : 1;
    $booking_source = isset($_POST['booking_source']) ? \sanitize_key((string) \wp_unslash($_POST['booking_source'])) : 'website';
    $notes = isset($_POST['notes']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['notes'])) : '';
    $status = isset($_POST['status']) ? \sanitize_key((string) \wp_unslash($_POST['status'])) : 'pending';
    $errors = [];

    if (!does_calendar_room_exist($room_id)) {
        $errors[] = \__('Please select a valid room.', 'must-hotel-booking');
    }

    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !is_valid_booking_date($checkin) ||
        !is_valid_booking_date($checkout)
    ) {
        $errors[] = \__('Please provide valid check-in and check-out dates.', 'must-hotel-booking');
    } elseif ($checkin >= $checkout) {
        $errors[] = \__('Checkout must be after check-in.', 'must-hotel-booking');
    }

    if ($guest_name === '') {
        $errors[] = \__('Please provide the guest name.', 'must-hotel-booking');
    }

    if ($email === '' || !\is_email($email)) {
        $errors[] = \__('Please provide a valid guest email.', 'must-hotel-booking');
    }

    $status_options = get_calendar_manual_create_status_options();

    if (!isset($status_options[$status])) {
        $errors[] = \__('Please select a valid reservation status.', 'must-hotel-booking');
    }

    $booking_source_options = get_calendar_booking_source_options();

    if (!isset($booking_source_options[$booking_source])) {
        $errors[] = \__('Please select a valid booking source.', 'must-hotel-booking');
    }

    if (empty($errors)) {
        $is_available = true;

        if (\function_exists(__NAMESPACE__ . '\check_room_availability')) {
            $is_available = check_room_availability($room_id, $checkin, $checkout);
        } else {
            $is_available = !has_calendar_reservation_overlap(0, $room_id, $checkin, $checkout);
        }

        if (!$is_available) {
            $errors[] = \__('This room is already reserved or blocked for the selected range.', 'must-hotel-booking');
        }
    }

    $form_data = [
        'room_id' => $room_id,
        'checkin' => $checkin,
        'checkout' => $checkout,
        'guest_name' => $guest_name,
        'phone' => $phone,
        'email' => $email,
        'guests' => $guests_count,
        'booking_source' => $booking_source,
        'notes' => $notes,
        'status' => $status,
    ];

    if (!empty($errors)) {
        return [
            'errors' => $errors,
            'form' => $form_data,
        ];
    }

    $guest_id = save_calendar_guest_record(0, $guest_name, $email, $phone);

    if ($guest_id <= 0) {
        return [
            'errors' => [\__('Unable to save guest details.', 'must-hotel-booking')],
            'form' => $form_data,
        ];
    }

    $total_price = calculate_calendar_reservation_total_price($room_id, $checkin, $checkout, $guests_count);

    if (!\function_exists(__NAMESPACE__ . '\create_reservation_without_lock')) {
        return [
            'errors' => [\__('Unable to create reservation. Please check database schema.', 'must-hotel-booking')],
            'form' => $form_data,
        ];
    }

    $new_reservation_id = create_reservation_without_lock(
        $room_id,
        $guest_id,
        $checkin,
        $checkout,
        $guests_count,
        $total_price,
        'unpaid',
        $status,
        $booking_source,
        $notes
    );

    if ($new_reservation_id <= 0) {
        return [
            'errors' => [\__('Unable to create reservation. This room may no longer be available for the selected dates.', 'must-hotel-booking')],
            'form' => $form_data,
        ];
    }

    $redirect_args = get_calendar_state_redirect_args($new_reservation_id);
    $redirect_args['notice'] = 'reservation_created';
    \wp_safe_redirect(get_admin_calendar_page_url($redirect_args));
    exit;
}

/**
 * Handle create-manual-block action.
 *
 * @return array<string, mixed>
 */
function maybe_handle_calendar_block_submission(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $action = isset($_POST['must_calendar_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_calendar_action'])) : '';

    if ($action !== 'create_manual_block') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $nonce = isset($_POST['must_calendar_nonce']) ? (string) \wp_unslash($_POST['must_calendar_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_calendar_block_dates')) {
        return [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];
    }

    $room_id = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
    $errors = [];

    if ($room_id <= 0) {
        $errors[] = \__('Please select a room to block.', 'must-hotel-booking');
    }

    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !is_valid_booking_date($checkin) ||
        !is_valid_booking_date($checkout)
    ) {
        $errors[] = \__('Please provide valid dates.', 'must-hotel-booking');
    } elseif ($checkin >= $checkout) {
        $errors[] = \__('Checkout must be after check-in.', 'must-hotel-booking');
    }

    if (empty($errors) && \function_exists(__NAMESPACE__ . '\check_room_availability')) {
        if (!check_room_availability($room_id, $checkin, $checkout)) {
            $errors[] = \__('This room is already reserved or blocked for the selected range.', 'must-hotel-booking');
        }
    }

    if (!empty($errors)) {
        return [
            'errors' => $errors,
            'form' => [
                'room_id' => $room_id,
                'checkin' => $checkin,
                'checkout' => $checkout,
            ],
        ];
    }

    global $wpdb;

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'must_reservations',
        [
            'room_id' => $room_id,
            'guest_id' => 0,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => 1,
            'status' => 'blocked',
            'total_price' => 0.00,
            'payment_status' => 'blocked',
            'created_at' => \current_time('mysql'),
        ],
        ['%d', '%d', '%s', '%s', '%d', '%s', '%f', '%s', '%s']
    );

    if ($inserted === false) {
        return [
            'errors' => [\__('Unable to create block. Please check database schema.', 'must-hotel-booking')],
            'form' => [
                'room_id' => $room_id,
                'checkin' => $checkin,
                'checkout' => $checkout,
            ],
        ];
    }

    \wp_safe_redirect(get_admin_calendar_page_url(['notice' => 'block_created']));
    exit;
}

/**
 * Handle delete-manual-block action.
 */
function maybe_handle_calendar_delete_block_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_block') {
        return;
    }

    $reservation_id = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($reservation_id <= 0 || !\wp_verify_nonce($nonce, 'must_calendar_delete_block_' . $reservation_id)) {
        \wp_safe_redirect(get_admin_calendar_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    global $wpdb;

    $deleted = $wpdb->delete(
        $wpdb->prefix . 'must_reservations',
        [
            'id' => $reservation_id,
            'status' => 'blocked',
        ],
        ['%d', '%s']
    );

    \wp_safe_redirect(get_admin_calendar_page_url(['notice' => $deleted ? 'block_deleted' : 'block_delete_failed']));
    exit;
}

/**
 * Handle cancel reservation action from calendar.
 */
function maybe_handle_calendar_cancel_reservation_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'cancel_reservation') {
        return;
    }

    $reservation_id = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($reservation_id <= 0 || !\wp_verify_nonce($nonce, 'must_calendar_cancel_reservation_' . $reservation_id)) {
        $redirect_args = get_calendar_state_redirect_args($reservation_id);
        $redirect_args['notice'] = 'invalid_nonce';
        \wp_safe_redirect(get_admin_calendar_page_url($redirect_args));
        exit;
    }

    $reservation = get_calendar_reservation_details($reservation_id);

    if (!\is_array($reservation)) {
        $redirect_args = get_calendar_state_redirect_args();
        $redirect_args['notice'] = 'reservation_not_found';
        \wp_safe_redirect(get_admin_calendar_page_url($redirect_args));
        exit;
    }

    $previous_status = \sanitize_key((string) ($reservation['status'] ?? ''));

    global $wpdb;

    $updated = $wpdb->update(
        $wpdb->prefix . 'must_reservations',
        ['status' => 'cancelled'],
        ['id' => $reservation_id],
        ['%s'],
        ['%d']
    );

    if ($updated !== false && $previous_status !== 'cancelled') {
        \do_action('must_hotel_booking/reservation_cancelled', $reservation_id);
    }

    $redirect_args = get_calendar_state_redirect_args($reservation_id);
    $redirect_args['notice'] = $updated !== false ? 'reservation_cancelled' : 'action_failed';
    \wp_safe_redirect(get_admin_calendar_page_url($redirect_args));
    exit;
}

/**
 * Render calendar admin notices.
 */
function render_calendar_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'block_created' => ['success', \__('Date block created successfully.', 'must-hotel-booking')],
        'block_deleted' => ['success', \__('Date block removed successfully.', 'must-hotel-booking')],
        'reservation_created' => ['success', \__('Reservation created successfully.', 'must-hotel-booking')],
        'reservation_updated' => ['success', \__('Reservation updated successfully.', 'must-hotel-booking')],
        'reservation_cancelled' => ['success', \__('Reservation cancelled successfully.', 'must-hotel-booking')],
        'reservation_not_found' => ['error', \__('Reservation not found.', 'must-hotel-booking')],
        'block_delete_failed' => ['error', \__('Unable to remove date block.', 'must-hotel-booking')],
        'action_failed' => ['error', \__('Unable to complete the requested action.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';

    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';
}

/**
 * Render room/date matrix calendar.
 *
 * @param array<int, array<string, mixed>>                    $rooms
 * @param array<int, array<int, array<string, mixed>>>        $reservations_by_room
 * @param array{start: string, end_exclusive: string, dates: array<int, string>, days: int} $range
 */
function render_admin_calendar_grid(array $rooms, array $reservations_by_room, array $range): void
{
    $dates = $range['dates'];
    $days = (int) $range['days'];
    $start_date = (string) $range['start'];
    $end_exclusive = (string) $range['end_exclusive'];
    $current_days = get_calendar_days_count();

    echo '<div class="must-admin-calendar-grid-wrap">';
    echo '<table class="widefat striped must-admin-calendar-grid">';
    echo '<thead><tr>';
    echo '<th class="must-calendar-room-col">' . \esc_html__('Room', 'must-hotel-booking') . '</th>';

    foreach ($dates as $date) {
        $label = \date_i18n('M j', \strtotime($date));
        echo '<th class="must-calendar-date-col">' . \esc_html($label) . '</th>';
    }

    echo '</tr></thead><tbody>';

    if (empty($rooms)) {
        echo '<tr><td colspan="' . \esc_attr((string) ($days + 1)) . '">' . \esc_html__('No rooms found. Add rooms first.', 'must-hotel-booking') . '</td></tr>';
        echo '</tbody></table></div>';

        return;
    }

    foreach ($rooms as $room) {
        $room_id = isset($room['id']) ? (int) $room['id'] : 0;
        $room_name = isset($room['name']) ? (string) $room['name'] : '';
        $room_reservations = isset($reservations_by_room[$room_id]) ? $reservations_by_room[$room_id] : [];
        $blocks = build_room_calendar_blocks($room_reservations, $start_date, $end_exclusive);

        echo '<tr>';
        echo '<th scope="row" class="must-calendar-room-col">' . \esc_html($room_name !== '' ? $room_name : __('Room', 'must-hotel-booking')) . '</th>';

        $pointer = 0;

        foreach ($blocks as $block) {
            $block_start = (int) $block['start_index'];
            $block_end = (int) $block['end_index'];

            if ($block_end <= $pointer) {
                continue;
            }

            if ($block_start > $pointer) {
                $gap = $block_start - $pointer;
                echo '<td colspan="' . \esc_attr((string) $gap) . '" class="must-calendar-empty-cell"></td>';
                $pointer = $block_start;
            }

            if ($block_end <= $pointer) {
                continue;
            }

            $span = $block_end - $pointer;
            $status = (string) $block['status'];
            $status_class = get_calendar_block_status_class($status);
            $details_url = get_admin_calendar_page_url(
                [
                    'start' => $start_date,
                    'days' => $current_days,
                    'reservation_id' => (int) $block['reservation_id'],
                ]
            );
            $title = \sprintf('%s - %s', (string) $block['checkin'], (string) $block['checkout']);

            echo '<td colspan="' . \esc_attr((string) $span) . '" class="must-calendar-block-cell ' . \esc_attr($status_class) . '">';
            echo '<a href="' . \esc_url($details_url) . '" title="' . \esc_attr($title) . '">' . \esc_html((string) $block['label']) . '</a>';
            echo '</td>';

            $pointer += $span;
        }

        if ($pointer < $days) {
            $remaining = $days - $pointer;
            echo '<td colspan="' . \esc_attr((string) $remaining) . '" class="must-calendar-empty-cell"></td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Render reservation details panel with edit controls.
 *
 * @param array<string, mixed>|null $reservation
 * @param array<int, array<string, mixed>> $rooms
 * @param array<int, string> $edit_errors
 */
function render_admin_calendar_reservation_details(?array $reservation, array $rooms, array $edit_errors = []): void
{
    echo '<div class="must-calendar-details-card">';
    echo '<h2>' . \esc_html__('Reservation Details', 'must-hotel-booking') . '</h2>';

    if (!\is_array($reservation)) {
        echo '<p>' . \esc_html__('Select a reservation block to view details.', 'must-hotel-booking') . '</p>';
        echo '</div>';

        return;
    }

    if (!empty($edit_errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($edit_errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    $reservation_id = (int) ($reservation['id'] ?? 0);
    $guest_name = \trim((string) ($reservation['first_name'] ?? '') . ' ' . (string) ($reservation['last_name'] ?? ''));
    $status = (string) ($reservation['status'] ?? '');
    $booking_id = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';
    $booking_source = isset($reservation['booking_source']) ? \sanitize_key((string) $reservation['booking_source']) : 'website';
    $booking_source_label = get_calendar_booking_source_label($booking_source);
    $notes = isset($reservation['notes']) ? (string) $reservation['notes'] : '';

    echo '<table class="widefat striped">';
    echo '<tbody>';

    if ($booking_id !== '') {
        echo '<tr><th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th><td>' . \esc_html($booking_id) . '</td></tr>';
    }

    echo '<tr><th>' . \esc_html__('Reservation ID', 'must-hotel-booking') . '</th><td>' . \esc_html((string) $reservation_id) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Room', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['room_name'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Check-in', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['checkin'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Check-out', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['checkout'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guests', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ((int) ($reservation['guests'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><td>' . \esc_html($status) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Booking Source', 'must-hotel-booking') . '</th><td>' . \esc_html($booking_source_label) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Payment Status', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['payment_status'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Total Price', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((float) ($reservation['total_price'] ?? 0), 2)) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guest Name', 'must-hotel-booking') . '</th><td>' . \esc_html($guest_name) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guest Email', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['email'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guest Phone', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['phone'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Notes', 'must-hotel-booking') . '</th><td>' . ($notes !== '' ? \nl2br(\esc_html($notes)) : '&mdash;') . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Created At', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['created_at'] ?? '')) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    $state_args = get_calendar_state_redirect_args($reservation_id);
    $form_action_url = get_admin_calendar_page_url($state_args);
    $status_options = get_calendar_reservation_status_options();
    $booking_source_options = get_calendar_booking_source_options();

    echo '<h3 style="margin-top:16px;">' . \esc_html__('Edit Reservation', 'must-hotel-booking') . '</h3>';
    echo '<form method="post" action="' . \esc_url($form_action_url) . '">';
    \wp_nonce_field('must_calendar_update_reservation_' . $reservation_id, 'must_calendar_update_reservation_nonce');
    echo '<input type="hidden" name="must_calendar_action" value="update_reservation" />';
    echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservation_id) . '" />';

    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th><label for="must-calendar-edit-room">' . \esc_html__('Room', 'must-hotel-booking') . '</label></th><td>';
    echo '<select id="must-calendar-edit-room" name="room_id" required>';

    foreach ($rooms as $room) {
        $room_id = isset($room['id']) ? (int) $room['id'] : 0;
        $selected = $room_id === (int) ($reservation['room_id'] ?? 0) ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $room_id) . '"' . $selected . '>' . \esc_html((string) ($room['name'] ?? '')) . '</option>';
    }

    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th><label for="must-calendar-edit-checkin">' . \esc_html__('Check-in', 'must-hotel-booking') . '</label></th><td><input id="must-calendar-edit-checkin" type="date" name="checkin" value="' . \esc_attr((string) ($reservation['checkin'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th><label for="must-calendar-edit-checkout">' . \esc_html__('Check-out', 'must-hotel-booking') . '</label></th><td><input id="must-calendar-edit-checkout" type="date" name="checkout" value="' . \esc_attr((string) ($reservation['checkout'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th><label for="must-calendar-edit-guests">' . \esc_html__('Guests', 'must-hotel-booking') . '</label></th><td><input id="must-calendar-edit-guests" type="number" min="1" step="1" name="guests" value="' . \esc_attr((string) \max(1, (int) ($reservation['guests'] ?? 1))) . '" required /></td></tr>';
    echo '<tr><th><label for="must-calendar-edit-guest-name">' . \esc_html__('Guest Name', 'must-hotel-booking') . '</label></th><td><input id="must-calendar-edit-guest-name" type="text" name="guest_name" value="' . \esc_attr($guest_name) . '" /></td></tr>';
    echo '<tr><th><label for="must-calendar-edit-phone">' . \esc_html__('Phone', 'must-hotel-booking') . '</label></th><td><input id="must-calendar-edit-phone" type="text" name="phone" value="' . \esc_attr((string) ($reservation['phone'] ?? '')) . '" /></td></tr>';
    echo '<tr><th><label for="must-calendar-edit-email">' . \esc_html__('Email', 'must-hotel-booking') . '</label></th><td><input id="must-calendar-edit-email" type="email" name="email" value="' . \esc_attr((string) ($reservation['email'] ?? '')) . '" /></td></tr>';
    echo '<tr><th><label for="must-calendar-edit-booking-source">' . \esc_html__('Booking Source', 'must-hotel-booking') . '</label></th><td>';
    echo '<select id="must-calendar-edit-booking-source" name="booking_source">';

    foreach ($booking_source_options as $value => $label) {
        $selected = $booking_source === $value ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select>';
    echo '</td></tr>';
    echo '<tr><th><label for="must-calendar-edit-notes">' . \esc_html__('Notes', 'must-hotel-booking') . '</label></th><td><textarea id="must-calendar-edit-notes" name="notes" rows="4" class="large-text">' . \esc_textarea($notes) . '</textarea></td></tr>';
    echo '<tr><th><label for="must-calendar-edit-status">' . \esc_html__('Status', 'must-hotel-booking') . '</label></th><td>';
    echo '<select id="must-calendar-edit-status" name="status">';

    foreach ($status_options as $value => $label) {
        $selected = ((string) ($reservation['status'] ?? '') === $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select>';
    echo '</td></tr>';
    echo '</tbody></table>';

    \submit_button(\__('Save Reservation', 'must-hotel-booking'), 'primary', 'submit', false);

    if ($status !== 'cancelled') {
        $cancel_args = get_calendar_state_redirect_args($reservation_id);
        $cancel_args['action'] = 'cancel_reservation';
        $cancel_args['reservation_id'] = $reservation_id;
        $cancel_url = \wp_nonce_url(
            get_admin_calendar_page_url($cancel_args),
            'must_calendar_cancel_reservation_' . $reservation_id
        );

        echo ' <a class="button button-link-delete" href="' . \esc_url($cancel_url) . '" onclick="return confirm(\'' . \esc_js(__('Cancel this booking?', 'must-hotel-booking')) . '\');">' . \esc_html__('Cancel Booking', 'must-hotel-booking') . '</a>';
    }

    echo '</form>';

    if ($status === 'blocked') {
        $delete_args = get_calendar_state_redirect_args($reservation_id);
        $delete_args['action'] = 'delete_block';
        $delete_args['reservation_id'] = $reservation_id;
        $delete_url = \wp_nonce_url(
            get_admin_calendar_page_url($delete_args),
            'must_calendar_delete_block_' . $reservation_id
        );

        echo '<p><a class="button button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Remove this manual block?', 'must-hotel-booking')) . '\');">' . \esc_html__('Remove Block', 'must-hotel-booking') . '</a></p>';
    }

    echo '</div>';
}

/**
 * Render calendar admin page.
 */
function render_admin_calendar_page(): void
{
    ensure_admin_capability();

    maybe_handle_calendar_delete_block_request();
    maybe_handle_calendar_cancel_reservation_request();
    $reservation_edit_errors = maybe_handle_calendar_reservation_update_submission();

    $manual_reservation_state = maybe_handle_calendar_manual_reservation_submission();
    $manual_reservation_errors = isset($manual_reservation_state['errors']) && \is_array($manual_reservation_state['errors'])
        ? $manual_reservation_state['errors']
        : [];
    $manual_reservation_form = isset($manual_reservation_state['form']) && \is_array($manual_reservation_state['form'])
        ? $manual_reservation_state['form']
        : [];

    $block_state = maybe_handle_calendar_block_submission();
    $block_errors = isset($block_state['errors']) && \is_array($block_state['errors']) ? $block_state['errors'] : [];
    $block_form = isset($block_state['form']) && \is_array($block_state['form']) ? $block_state['form'] : [];

    $start_date = get_calendar_start_date();
    $days = get_calendar_days_count();
    $range = get_calendar_range_data($start_date, $days);
    $rooms = get_calendar_rooms();
    $reservations = get_calendar_reservations($range['start'], $range['end_exclusive']);
    $reservations_by_room = group_calendar_reservations_by_room($reservations);

    $previous_start = (new \DateTimeImmutable($range['start']))->modify('-' . $days . ' day')->format('Y-m-d');
    $next_start = (new \DateTimeImmutable($range['start']))->modify('+' . $days . ' day')->format('Y-m-d');

    $selected_reservation_id = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $selected_reservation = $selected_reservation_id > 0 ? get_calendar_reservation_details($selected_reservation_id) : null;

    $manual_form_room_id = isset($manual_reservation_form['room_id']) ? (int) $manual_reservation_form['room_id'] : 0;
    $manual_form_checkin = isset($manual_reservation_form['checkin']) ? (string) $manual_reservation_form['checkin'] : $range['start'];
    $manual_form_checkout = isset($manual_reservation_form['checkout'])
        ? (string) $manual_reservation_form['checkout']
        : (new \DateTimeImmutable($range['start']))->modify('+1 day')->format('Y-m-d');
    $manual_form_guest_name = isset($manual_reservation_form['guest_name']) ? (string) $manual_reservation_form['guest_name'] : '';
    $manual_form_phone = isset($manual_reservation_form['phone']) ? (string) $manual_reservation_form['phone'] : '';
    $manual_form_email = isset($manual_reservation_form['email']) ? (string) $manual_reservation_form['email'] : '';
    $manual_form_guests = isset($manual_reservation_form['guests']) ? \max(1, (int) $manual_reservation_form['guests']) : 1;
    $manual_form_booking_source = isset($manual_reservation_form['booking_source'])
        ? \sanitize_key((string) $manual_reservation_form['booking_source'])
        : 'website';
    $manual_form_notes = isset($manual_reservation_form['notes']) ? (string) $manual_reservation_form['notes'] : '';
    $manual_form_status = isset($manual_reservation_form['status']) ? \sanitize_key((string) $manual_reservation_form['status']) : 'pending';

    $form_room_id = isset($block_form['room_id']) ? (int) $block_form['room_id'] : 0;
    $form_checkin = isset($block_form['checkin']) ? (string) $block_form['checkin'] : $range['start'];
    $form_checkout = isset($block_form['checkout'])
        ? (string) $block_form['checkout']
        : (new \DateTimeImmutable($range['start']))->modify('+1 day')->format('Y-m-d');

    echo '<div class="wrap must-admin-calendar-page">';
    echo '<h1>' . \esc_html__('Calendar', 'must-hotel-booking') . '</h1>';

    render_calendar_admin_notice_from_query();

    if (!empty($manual_reservation_errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($manual_reservation_errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    if (!empty($block_errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($block_errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="must-calendar-toolbar">';
    echo '<a class="button" href="' . \esc_url(get_admin_calendar_page_url(['start' => $previous_start, 'days' => $days])) . '">' . \esc_html__('Previous', 'must-hotel-booking') . '</a> ';
    echo '<a class="button" href="' . \esc_url(get_admin_calendar_page_url(['start' => $next_start, 'days' => $days])) . '">' . \esc_html__('Next', 'must-hotel-booking') . '</a> ';
    echo '<span class="must-calendar-range-label">' . \esc_html($range['start'] . ' - ' . (new \DateTimeImmutable($range['end_exclusive']))->modify('-1 day')->format('Y-m-d')) . '</span>';
    echo '</div>';

    $booking_source_options = get_calendar_booking_source_options();
    $manual_status_options = get_calendar_manual_create_status_options();

    echo '<div class="must-calendar-action-card">';
    echo '<h2>' . \esc_html__('Create Reservation Manually', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_calendar_page_url(['start' => $start_date, 'days' => $days])) . '" class="must-calendar-manual-reservation-form">';
    \wp_nonce_field('must_calendar_create_reservation', 'must_calendar_create_reservation_nonce');
    echo '<input type="hidden" name="must_calendar_action" value="create_manual_reservation" />';

    echo '<label>' . \esc_html__('Room', 'must-hotel-booking');
    echo '<select name="room_id" required>';
    echo '<option value="">' . \esc_html__('Select room', 'must-hotel-booking') . '</option>';

    foreach ($rooms as $room) {
        $room_id = isset($room['id']) ? (int) $room['id'] : 0;
        $selected = $room_id === $manual_form_room_id ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $room_id) . '"' . $selected . '>' . \esc_html((string) ($room['name'] ?? '')) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label>' . \esc_html__('Check-in', 'must-hotel-booking');
    echo '<input type="date" name="checkin" value="' . \esc_attr($manual_form_checkin) . '" required />';
    echo '</label>';

    echo '<label>' . \esc_html__('Check-out', 'must-hotel-booking');
    echo '<input type="date" name="checkout" value="' . \esc_attr($manual_form_checkout) . '" required />';
    echo '</label>';

    echo '<label>' . \esc_html__('Guest Name', 'must-hotel-booking');
    echo '<input type="text" name="guest_name" value="' . \esc_attr($manual_form_guest_name) . '" required />';
    echo '</label>';

    echo '<label>' . \esc_html__('Phone', 'must-hotel-booking');
    echo '<input type="text" name="phone" value="' . \esc_attr($manual_form_phone) . '" />';
    echo '</label>';

    echo '<label>' . \esc_html__('Email', 'must-hotel-booking');
    echo '<input type="email" name="email" value="' . \esc_attr($manual_form_email) . '" required />';
    echo '</label>';

    echo '<label>' . \esc_html__('Guests', 'must-hotel-booking');
    echo '<input type="number" name="guests" min="1" step="1" value="' . \esc_attr((string) $manual_form_guests) . '" required />';
    echo '</label>';

    echo '<label>' . \esc_html__('Booking Source', 'must-hotel-booking');
    echo '<select name="booking_source" required>';

    foreach ($booking_source_options as $source_key => $source_label) {
        $selected = $manual_form_booking_source === $source_key ? ' selected' : '';
        echo '<option value="' . \esc_attr($source_key) . '"' . $selected . '>' . \esc_html($source_label) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label>' . \esc_html__('Status', 'must-hotel-booking');
    echo '<select name="status" required>';

    foreach ($manual_status_options as $status_key => $status_label) {
        $selected = $manual_form_status === $status_key ? ' selected' : '';
        echo '<option value="' . \esc_attr($status_key) . '"' . $selected . '>' . \esc_html($status_label) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label class="must-calendar-form-full-row">' . \esc_html__('Notes', 'must-hotel-booking');
    echo '<textarea name="notes" rows="3">' . \esc_textarea($manual_form_notes) . '</textarea>';
    echo '</label>';

    echo '<button type="submit" class="button button-primary">' . \esc_html__('Create Reservation', 'must-hotel-booking') . '</button>';
    echo '</form>';
    echo '</div>';

    echo '<div class="must-calendar-action-card">';
    echo '<h2>' . \esc_html__('Block Dates Manually', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_calendar_page_url(['start' => $start_date, 'days' => $days])) . '" class="must-calendar-block-form">';
    \wp_nonce_field('must_calendar_block_dates', 'must_calendar_nonce');
    echo '<input type="hidden" name="must_calendar_action" value="create_manual_block" />';

    echo '<label>' . \esc_html__('Room', 'must-hotel-booking');
    echo '<select name="room_id" required>';
    echo '<option value="">' . \esc_html__('Select room', 'must-hotel-booking') . '</option>';

    foreach ($rooms as $room) {
        $room_id = isset($room['id']) ? (int) $room['id'] : 0;
        $selected = $room_id === $form_room_id ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $room_id) . '"' . $selected . '>' . \esc_html((string) ($room['name'] ?? '')) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label>' . \esc_html__('Check-in', 'must-hotel-booking');
    echo '<input type="date" name="checkin" value="' . \esc_attr($form_checkin) . '" required />';
    echo '</label>';

    echo '<label>' . \esc_html__('Check-out', 'must-hotel-booking');
    echo '<input type="date" name="checkout" value="' . \esc_attr($form_checkout) . '" required />';
    echo '</label>';

    echo '<button type="submit" class="button button-primary">' . \esc_html__('Block Dates', 'must-hotel-booking') . '</button>';
    echo '</form>';
    echo '</div>';

    render_admin_calendar_grid($rooms, $reservations_by_room, $range);
    render_admin_calendar_reservation_details($selected_reservation, $rooms, $reservation_edit_errors);

    echo '</div>';
}

/**
 * Handle calendar admin actions before page output starts.
 */
function maybe_handle_admin_calendar_actions_early(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if ($page !== 'must-hotel-booking-calendar') {
        return;
    }

    ensure_admin_capability();

    maybe_handle_calendar_cancel_reservation_request();
    maybe_handle_calendar_reservation_update_submission();
    maybe_handle_calendar_manual_reservation_submission();
    maybe_handle_calendar_block_submission();
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_admin_calendar_actions_early', 1);

/**
 * Enqueue calendar admin assets.
 */
function enqueue_admin_calendar_assets(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if ($page !== 'must-hotel-booking-calendar') {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-calendar',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-calendar.css',
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_calendar_assets');
