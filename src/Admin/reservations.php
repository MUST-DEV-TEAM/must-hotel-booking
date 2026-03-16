<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Engine\AvailabilityEngine;

/**
 * Build reservations admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_reservations_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-reservations');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Format reservation booking ID for display.
 */
function format_reservation_booking_id(array $reservation): string
{
    $booking_id = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';

    if ($booking_id !== '') {
        return $booking_id;
    }

    $id = isset($reservation['id']) ? (int) $reservation['id'] : 0;

    return $id > 0 ? 'RES-' . $id : '';
}

/**
 * Get reservation status options.
 *
 * @return array<string, string>
 */
function get_reservation_status_options(): array
{
    return [
        'pending' => \__('Pending', 'must-hotel-booking'),
        'pending_payment' => \__('Pending Payment', 'must-hotel-booking'),
        'confirmed' => \__('Confirmed', 'must-hotel-booking'),
        'cancelled' => \__('Cancelled', 'must-hotel-booking'),
        'expired' => \__('Expired', 'must-hotel-booking'),
        'payment_failed' => \__('Payment Failed', 'must-hotel-booking'),
        'completed' => \__('Completed', 'must-hotel-booking'),
        'blocked' => \__('Blocked', 'must-hotel-booking'),
    ];
}

/**
 * Get payment status options.
 *
 * @return array<string, string>
 */
function get_reservation_payment_status_options(): array
{
    return [
        'unpaid' => \__('Unpaid', 'must-hotel-booking'),
        'pending' => \__('Pending', 'must-hotel-booking'),
        'paid' => \__('Paid', 'must-hotel-booking'),
        'failed' => \__('Failed', 'must-hotel-booking'),
        'cancelled' => \__('Cancelled', 'must-hotel-booking'),
        'refunded' => \__('Refunded', 'must-hotel-booking'),
        'blocked' => \__('Blocked', 'must-hotel-booking'),
    ];
}

/**
 * Get reservations list rows.
 *
 * @return array<int, array<string, mixed>>
 */
function get_reservations_list_rows(): array
{
    global $wpdb;

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $rooms_table = $wpdb->prefix . 'must_rooms';
    $guests_table = $wpdb->prefix . 'must_guests';

    $rows = $wpdb->get_results(
        "SELECT
            r.id,
            r.booking_id,
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
        ORDER BY r.created_at DESC, r.id DESC",
        ARRAY_A
    );

    return \is_array($rows) ? $rows : [];
}

/**
 * Get single reservation row.
 *
 * @return array<string, mixed>|null
 */
function get_reservation_row(int $reservation_id): ?array
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
 * Check overlap with other reservations for same room.
 */
function has_other_reservation_overlap(int $reservation_id, int $room_id, string $checkin, string $checkout): bool
{
    global $wpdb;

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $non_blocking_statuses = \function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')
        ? get_inventory_non_blocking_reservation_statuses()
        : ['cancelled', 'expired', 'payment_failed'];
    $sql = $wpdb->prepare(
        "SELECT 1
        FROM {$reservations_table}
        WHERE room_id = %d
            AND id <> %d
            AND checkin < %s
            AND checkout > %s
            AND status NOT IN (%s, %s, %s)
        LIMIT 1",
        $room_id,
        $reservation_id,
        $checkout,
        $checkin,
        (string) $non_blocking_statuses[0],
        (string) $non_blocking_statuses[1],
        (string) $non_blocking_statuses[2]
    );

    return $wpdb->get_var($sql) !== null;
}

/**
 * Render reservations admin notice from query.
 */
function render_reservations_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'reservation_updated' => ['success', \__('Reservation updated successfully.', 'must-hotel-booking')],
        'reservation_cancelled' => ['success', \__('Reservation cancelled successfully.', 'must-hotel-booking')],
        'reservation_deleted' => ['success', \__('Reservation deleted successfully.', 'must-hotel-booking')],
        'reservation_not_found' => ['error', \__('Reservation not found.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
        'action_failed' => ['error', \__('Unable to complete the requested action.', 'must-hotel-booking')],
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
 * Handle cancel reservation action.
 */
function maybe_handle_cancel_reservation_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'cancel') {
        return;
    }

    $reservation_id = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($reservation_id <= 0 || !\wp_verify_nonce($nonce, 'must_reservation_cancel_' . $reservation_id)) {
        \wp_safe_redirect(get_admin_reservations_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    $reservation = get_reservation_row($reservation_id);
    $previous_status = \is_array($reservation) ? \sanitize_key((string) ($reservation['status'] ?? '')) : '';

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

    \wp_safe_redirect(get_admin_reservations_page_url(['notice' => $updated !== false ? 'reservation_cancelled' : 'action_failed']));
    exit;
}

/**
 * Handle delete reservation action.
 */
function maybe_handle_delete_reservation_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete') {
        return;
    }

    $reservation_id = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($reservation_id <= 0 || !\wp_verify_nonce($nonce, 'must_reservation_delete_' . $reservation_id)) {
        \wp_safe_redirect(get_admin_reservations_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    global $wpdb;

    $deleted = $wpdb->delete(
        $wpdb->prefix . 'must_reservations',
        ['id' => $reservation_id],
        ['%d']
    );

    \wp_safe_redirect(get_admin_reservations_page_url(['notice' => $deleted !== false ? 'reservation_deleted' : 'action_failed']));
    exit;
}

/**
 * Handle reservation edit submission.
 *
 * @return array<int, string>
 */
function maybe_handle_edit_reservation_submission(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_reservation_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_reservation_action'])) : '';

    if ($action !== 'save_reservation') {
        return [];
    }

    $nonce = isset($_POST['must_reservation_nonce']) ? (string) \wp_unslash($_POST['must_reservation_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_reservation_save')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    $reservation_id = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
    $guests = isset($_POST['guests']) ? \max(1, \absint(\wp_unslash($_POST['guests']))) : 1;
    $status = isset($_POST['status']) ? \sanitize_key((string) \wp_unslash($_POST['status'])) : 'pending';
    $payment_status = isset($_POST['payment_status']) ? \sanitize_key((string) \wp_unslash($_POST['payment_status'])) : 'unpaid';
    $total_price = isset($_POST['total_price']) ? (float) \wp_unslash($_POST['total_price']) : 0.0;
    $errors = [];

    $reservation = get_reservation_row($reservation_id);

    if (!\is_array($reservation)) {
        return [\__('Reservation not found.', 'must-hotel-booking')];
    }

    $previous_status = \sanitize_key((string) ($reservation['status'] ?? ''));

    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !AvailabilityEngine::isValidBookingDate($checkin) ||
        !AvailabilityEngine::isValidBookingDate($checkout)
    ) {
        $errors[] = \__('Please provide valid check-in and check-out dates.', 'must-hotel-booking');
    } elseif ($checkin >= $checkout) {
        $errors[] = \__('Checkout must be after check-in.', 'must-hotel-booking');
    }

    if ($total_price < 0) {
        $total_price = 0;
    }

    $status_options = get_reservation_status_options();
    $payment_status_options = get_reservation_payment_status_options();

    if (!isset($status_options[$status])) {
        $errors[] = \__('Invalid reservation status.', 'must-hotel-booking');
    }

    if (!isset($payment_status_options[$payment_status])) {
        $errors[] = \__('Invalid payment status.', 'must-hotel-booking');
    }

    $room_id = isset($reservation['room_id']) ? (int) $reservation['room_id'] : 0;

    if (empty($errors) && has_other_reservation_overlap($reservation_id, $room_id, $checkin, $checkout)) {
        $errors[] = \__('The selected dates overlap with another reservation.', 'must-hotel-booking');
    }

    if (!empty($errors)) {
        return $errors;
    }

    global $wpdb;

    $updated = $wpdb->update(
        $wpdb->prefix . 'must_reservations',
        [
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'status' => $status,
            'payment_status' => $payment_status,
            'total_price' => \round($total_price, 2),
        ],
        ['id' => $reservation_id],
        ['%s', '%s', '%d', '%s', '%s', '%f'],
        ['%d']
    );

    if ($updated === false) {
        return [\__('Unable to update reservation.', 'must-hotel-booking')];
    }

    if ($previous_status !== 'cancelled' && $status === 'cancelled') {
        \do_action('must_hotel_booking/reservation_cancelled', $reservation_id);
    }

    \wp_safe_redirect(
        get_admin_reservations_page_url(
            [
                'notice' => 'reservation_updated',
                'action' => 'view',
                'reservation_id' => $reservation_id,
            ]
        )
    );
    exit;
}

/**
 * Render reservation details panel.
 */
function render_admin_reservation_view_panel(array $reservation): void
{
    $guest_name = \trim((string) ($reservation['first_name'] ?? '') . ' ' . (string) ($reservation['last_name'] ?? ''));

    echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('View Reservation', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th><td>' . \esc_html(format_reservation_booking_id($reservation)) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Room', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['room_name'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guest Name', 'must-hotel-booking') . '</th><td>' . \esc_html($guest_name) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guest Email', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['email'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guest Phone', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['phone'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Check-in', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['checkin'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Check-out', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['checkout'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Guests', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ((int) ($reservation['guests'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['status'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Payment Status', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['payment_status'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Total price', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((float) ($reservation['total_price'] ?? 0), 2)) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Created At', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['created_at'] ?? '')) . '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Render reservation edit form panel.
 */
function render_admin_reservation_edit_panel(array $reservation): void
{
    $status_options = get_reservation_status_options();
    $payment_status_options = get_reservation_payment_status_options();
    $reservation_id = (int) ($reservation['id'] ?? 0);

    echo '<div class="postbox" style="padding:16px;margin-top:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Edit Reservation', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_reservations_page_url()) . '">';
    \wp_nonce_field('must_reservation_save', 'must_reservation_nonce');

    echo '<input type="hidden" name="must_reservation_action" value="save_reservation" />';
    echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservation_id) . '" />';

    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th><td><strong>' . \esc_html(format_reservation_booking_id($reservation)) . '</strong></td></tr>';
    echo '<tr><th><label for="must-reservation-checkin">' . \esc_html__('Check-in', 'must-hotel-booking') . '</label></th><td><input id="must-reservation-checkin" type="date" name="checkin" value="' . \esc_attr((string) ($reservation['checkin'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th><label for="must-reservation-checkout">' . \esc_html__('Check-out', 'must-hotel-booking') . '</label></th><td><input id="must-reservation-checkout" type="date" name="checkout" value="' . \esc_attr((string) ($reservation['checkout'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th><label for="must-reservation-guests">' . \esc_html__('Guests', 'must-hotel-booking') . '</label></th><td><input id="must-reservation-guests" type="number" name="guests" min="1" value="' . \esc_attr((string) ((int) ($reservation['guests'] ?? 1))) . '" required /></td></tr>';
    echo '<tr><th><label for="must-reservation-status">' . \esc_html__('Status', 'must-hotel-booking') . '</label></th><td><select id="must-reservation-status" name="status">';

    foreach ($status_options as $value => $label) {
        $selected = ((string) ($reservation['status'] ?? '') === $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select></td></tr>';
    echo '<tr><th><label for="must-reservation-payment-status">' . \esc_html__('Payment Status', 'must-hotel-booking') . '</label></th><td><select id="must-reservation-payment-status" name="payment_status">';

    foreach ($payment_status_options as $value => $label) {
        $selected = ((string) ($reservation['payment_status'] ?? '') === $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select></td></tr>';
    echo '<tr><th><label for="must-reservation-total">' . \esc_html__('Total price', 'must-hotel-booking') . '</label></th><td><input id="must-reservation-total" type="number" name="total_price" min="0" step="0.01" value="' . \esc_attr((string) ((float) ($reservation['total_price'] ?? 0))) . '" required /></td></tr>';
    echo '</tbody></table>';

    \submit_button(\__('Save Reservation', 'must-hotel-booking'));

    echo '</form>';
    echo '</div>';
}

/**
 * Render reservations table.
 *
 * @param array<int, array<string, mixed>> $reservations
 */
function render_admin_reservations_table(array $reservations): void
{
    echo '<h2>' . \esc_html__('Reservations', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Guest Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Room', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-in', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-out', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Guests', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Total price', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($reservations)) {
        echo '<tr><td colspan="9">' . \esc_html__('No reservations found.', 'must-hotel-booking') . '</td></tr>';
        echo '</tbody></table>';

        return;
    }

    foreach ($reservations as $reservation) {
        $reservation_id = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        $view_url = get_admin_reservations_page_url(['action' => 'view', 'reservation_id' => $reservation_id]);
        $edit_url = get_admin_reservations_page_url(['action' => 'edit', 'reservation_id' => $reservation_id]);
        $cancel_url = \wp_nonce_url(
            get_admin_reservations_page_url(['action' => 'cancel', 'reservation_id' => $reservation_id]),
            'must_reservation_cancel_' . $reservation_id
        );
        $delete_url = \wp_nonce_url(
            get_admin_reservations_page_url(['action' => 'delete', 'reservation_id' => $reservation_id]),
            'must_reservation_delete_' . $reservation_id
        );
        $guest_name = \trim((string) ($reservation['guest_name'] ?? ''));

        if ($guest_name === '') {
            $guest_name = \__('N/A', 'must-hotel-booking');
        }

        echo '<tr>';
        echo '<td>' . \esc_html(format_reservation_booking_id($reservation)) . '</td>';
        echo '<td>' . \esc_html($guest_name) . '</td>';
        echo '<td>' . \esc_html((string) ($reservation['room_name'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservation['checkin'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservation['checkout'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ((int) ($reservation['guests'] ?? 0))) . '</td>';
        echo '<td>' . \esc_html((string) ($reservation['status'] ?? '')) . '</td>';
        echo '<td>' . \esc_html(\number_format_i18n((float) ($reservation['total_price'] ?? 0), 2)) . '</td>';
        echo '<td>';
        echo '<a class="button button-small" href="' . \esc_url($view_url) . '">' . \esc_html__('View reservation', 'must-hotel-booking') . '</a> ';
        echo '<a class="button button-small" href="' . \esc_url($edit_url) . '">' . \esc_html__('Edit reservation', 'must-hotel-booking') . '</a> ';
        echo '<a class="button button-small" href="' . \esc_url($cancel_url) . '">' . \esc_html__('Cancel reservation', 'must-hotel-booking') . '</a> ';
        echo '<a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Delete this reservation?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete reservation', 'must-hotel-booking') . '</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

/**
 * Render reservations admin page.
 */
function render_admin_reservations_page(): void
{
    ensure_admin_capability();

    maybe_handle_cancel_reservation_request();
    maybe_handle_delete_reservation_request();

    $submit_errors = maybe_handle_edit_reservation_submission();
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';
    $reservation_id = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $selected_reservation = $reservation_id > 0 ? get_reservation_row($reservation_id) : null;
    $reservations = get_reservations_list_rows();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Reservations', 'must-hotel-booking') . '</h1>';

    render_reservations_admin_notice_from_query();

    if (!empty($submit_errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($submit_errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    if (($action === 'view' || $action === 'edit') && $reservation_id > 0) {
        if (\is_array($selected_reservation)) {
            if ($action === 'view') {
                render_admin_reservation_view_panel($selected_reservation);
            } else {
                render_admin_reservation_edit_panel($selected_reservation);
            }
        } else {
            echo '<div class="notice notice-error"><p>' . \esc_html__('Reservation not found.', 'must-hotel-booking') . '</p></div>';
        }
    }

    render_admin_reservations_table($reservations);
    echo '</div>';
}

/**
 * Handle reservation admin actions before page output starts.
 */
function maybe_handle_admin_reservations_actions_early(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if ($page !== 'must-hotel-booking-reservations') {
        return;
    }

    ensure_admin_capability();

    maybe_handle_cancel_reservation_request();
    maybe_handle_delete_reservation_request();
    maybe_handle_edit_reservation_submission();
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_admin_reservations_actions_early', 1);
