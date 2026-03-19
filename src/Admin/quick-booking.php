<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Engine\PricingEngine;
use MustHotelBooking\Engine\ReservationEngine;

/**
 * Build Dashboard admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_dashboard_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Get quick booking form defaults.
 *
 * @return array<string, mixed>
 */
function get_admin_quick_booking_form_defaults(): array
{
    $today = \current_time('Y-m-d');
    $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');

    return [
        'room_id' => 0,
        'checkin' => $today,
        'checkout' => $tomorrow,
        'guests' => 1,
        'guest_name' => '',
        'phone' => '',
        'email' => '',
        'booking_source' => 'website',
        'notes' => '',
    ];
}

/**
 * Get rooms available for quick booking selection.
 *
 * @return array<int, array<string, mixed>>
 */
function get_admin_quick_booking_rooms(): array
{
    if (\function_exists(__NAMESPACE__ . '\get_calendar_rooms')) {
        return get_calendar_rooms();
    }

    return \MustHotelBooking\Engine\get_room_repository()->getRoomSelectorRows();
}

/**
 * Get booking source options for quick booking form.
 *
 * @return array<string, string>
 */
function get_admin_quick_booking_source_options(): array
{
    if (\function_exists(__NAMESPACE__ . '\get_calendar_booking_source_options')) {
        return get_calendar_booking_source_options();
    }

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
 * Check if room exists.
 */
function does_admin_quick_booking_room_exist(int $room_id): bool
{
    if ($room_id <= 0) {
        return false;
    }

    if (\function_exists(__NAMESPACE__ . '\does_calendar_room_exist')) {
        return does_calendar_room_exist($room_id);
    }

    return \is_array(\MustHotelBooking\Engine\get_room_repository()->getRoomById($room_id));
}

/**
 * Get room row for quick booking validation/preview.
 *
 * @return array<string, mixed>|null
 */
function get_admin_quick_booking_room_row(int $room_id): ?array
{
    return \MustHotelBooking\Engine\get_room_repository()->getRoomById($room_id);
}

/**
 * Check room availability for quick booking.
 */
function is_admin_quick_booking_room_available(int $room_id, string $checkin, string $checkout): bool
{
    return AvailabilityEngine::checkAvailability($room_id, $checkin, $checkout);
}

/**
 * Calculate quick booking total price.
 */
function get_admin_quick_booking_total_price(int $room_id, string $checkin, string $checkout, int $guests): float
{
    $pricing = PricingEngine::calculateTotal($room_id, $checkin, $checkout, $guests);

    if (\is_array($pricing) && !empty($pricing['success']) && isset($pricing['total_price'])) {
        return (float) $pricing['total_price'];
    }

    if (\function_exists(__NAMESPACE__ . '\calculate_calendar_reservation_total_price')) {
        return (float) calculate_calendar_reservation_total_price($room_id, $checkin, $checkout, $guests);
    }

    return 0.0;
}

/**
 * Save guest for quick booking and return guest ID.
 */
function save_admin_quick_booking_guest(string $guest_name, string $email, string $phone): int
{
    if (\function_exists(__NAMESPACE__ . '\save_calendar_guest_record')) {
        return save_calendar_guest_record(0, $guest_name, $email, $phone);
    }

    $parts = \preg_split('/\s+/', \trim($guest_name));
    $parts = \is_array($parts) ? \array_values(\array_filter($parts, static function (string $part): bool {
        return $part !== '';
    })) : [];
    $first_name = isset($parts[0]) ? (string) $parts[0] : '';
    $last_name = \trim(\implode(' ', \array_slice($parts, 1)));

    return \MustHotelBooking\Engine\get_guest_repository()->saveGuestProfile(
        0,
        $first_name,
        $last_name,
        $email,
        $phone
    );
}

/**
 * Create reservation from validated quick booking form.
 */
function create_admin_quick_booking_reservation(array $form): int
{
    $room_id = isset($form['room_id']) ? (int) $form['room_id'] : 0;
    $checkin = isset($form['checkin']) ? (string) $form['checkin'] : '';
    $checkout = isset($form['checkout']) ? (string) $form['checkout'] : '';
    $guests = isset($form['guests']) ? \max(1, (int) $form['guests']) : 1;
    $guest_name = isset($form['guest_name']) ? (string) $form['guest_name'] : '';
    $phone = isset($form['phone']) ? (string) $form['phone'] : '';
    $email = isset($form['email']) ? (string) $form['email'] : '';
    $booking_source = isset($form['booking_source']) ? (string) $form['booking_source'] : 'website';
    $notes = isset($form['notes']) ? (string) $form['notes'] : '';

    $guest_id = save_admin_quick_booking_guest($guest_name, $email, $phone);

    if ($guest_id <= 0) {
        return 0;
    }

    $total_price = get_admin_quick_booking_total_price($room_id, $checkin, $checkout, $guests);

    return ReservationEngine::createReservationWithoutLock(
        $room_id,
        $guest_id,
        $checkin,
        $checkout,
        $guests,
        $total_price,
        'unpaid',
        'confirmed',
        $booking_source,
        $notes
    );
}

/**
 * Sanitize and validate quick booking form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_admin_quick_booking_form_values(array $source): array
{
    $defaults = get_admin_quick_booking_form_defaults();
    $room_id = isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0;
    $checkin = isset($source['checkin']) ? \sanitize_text_field((string) \wp_unslash($source['checkin'])) : '';
    $checkout = isset($source['checkout']) ? \sanitize_text_field((string) \wp_unslash($source['checkout'])) : '';
    $guests = isset($source['guests']) ? \max(1, \absint(\wp_unslash($source['guests']))) : 1;
    $guest_name = isset($source['guest_name']) ? \sanitize_text_field((string) \wp_unslash($source['guest_name'])) : '';
    $phone = isset($source['phone']) ? \sanitize_text_field((string) \wp_unslash($source['phone'])) : '';
    $email = isset($source['email']) ? \sanitize_email((string) \wp_unslash($source['email'])) : '';
    $booking_source = isset($source['booking_source']) ? \sanitize_key((string) \wp_unslash($source['booking_source'])) : 'website';
    $notes = isset($source['notes']) ? \sanitize_textarea_field((string) \wp_unslash($source['notes'])) : '';
    $errors = [];

    if (!does_admin_quick_booking_room_exist($room_id)) {
        $errors[] = \__('Please select a valid room.', 'must-hotel-booking');
    }

    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !AvailabilityEngine::isValidBookingDate($checkin) ||
        !AvailabilityEngine::isValidBookingDate($checkout)
    ) {
        $errors[] = \__('Please provide valid check-in and check-out dates.', 'must-hotel-booking');
    } elseif ($checkin >= $checkout) {
        $errors[] = \__('Check-out must be after check-in.', 'must-hotel-booking');
    }

    if ($guest_name === '') {
        $errors[] = \__('Please provide the guest name.', 'must-hotel-booking');
    }

    if ($email === '' || !\is_email($email)) {
        $errors[] = \__('Please provide a valid guest email.', 'must-hotel-booking');
    }

    $source_options = get_admin_quick_booking_source_options();

    if (!isset($source_options[$booking_source])) {
        $errors[] = \__('Please select a valid booking source.', 'must-hotel-booking');
    }

    $room = get_admin_quick_booking_room_row($room_id);

    if (\is_array($room)) {
        $max_guests = isset($room['max_guests']) ? (int) $room['max_guests'] : 1;

        if ($max_guests > 0 && $guests > $max_guests) {
            $errors[] = \__('Selected room cannot host that many guests.', 'must-hotel-booking');
        }
    }

    if (empty($errors) && !is_admin_quick_booking_room_available($room_id, $checkin, $checkout)) {
        $errors[] = \__('This room is not available for the selected dates.', 'must-hotel-booking');
    }

    return [
        'room_id' => $room_id > 0 ? $room_id : (int) $defaults['room_id'],
        'checkin' => $checkin !== '' ? $checkin : (string) $defaults['checkin'],
        'checkout' => $checkout !== '' ? $checkout : (string) $defaults['checkout'],
        'guests' => $guests,
        'guest_name' => $guest_name,
        'phone' => $phone,
        'email' => $email,
        'booking_source' => $booking_source,
        'notes' => $notes,
        'errors' => $errors,
    ];
}

/**
 * Handle quick booking submission from the Dashboard panel.
 *
 * @return array<string, mixed>
 */
function maybe_handle_admin_quick_booking_submission(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $action = isset($_POST['must_quick_booking_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_quick_booking_action'])) : '';

    if ($action !== 'create_quick_booking') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $nonce = isset($_POST['must_quick_booking_nonce']) ? (string) \wp_unslash($_POST['must_quick_booking_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_quick_booking_save')) {
        return [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $form = sanitize_admin_quick_booking_form_values($raw_post);
    $errors = isset($form['errors']) && \is_array($form['errors']) ? $form['errors'] : [];

    if (!empty($errors)) {
        return [
            'errors' => $errors,
            'form' => $form,
        ];
    }

    $reservation_id = create_admin_quick_booking_reservation($form);

    if ($reservation_id <= 0) {
        return [
            'errors' => [\__('Unable to create reservation. This room may no longer be available for the selected dates.', 'must-hotel-booking')],
            'form' => $form,
        ];
    }

    $redirectTarget = isset($_POST['must_quick_booking_redirect_target'])
        ? \sanitize_key((string) \wp_unslash($_POST['must_quick_booking_redirect_target']))
        : 'dashboard';
    $returnUrl = isset($_POST['must_quick_booking_return_url'])
        ? \esc_url_raw((string) \wp_unslash($_POST['must_quick_booking_return_url']))
        : '';

    if (
        $redirectTarget === 'calendar' &&
        $returnUrl !== '' &&
        \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
    ) {
        $redirect_url = \add_query_arg(
            [
                'notice' => 'reservation_created',
                'reservation_id' => $reservation_id,
            ],
            $returnUrl
        );
    } elseif (
        $redirectTarget === 'reservation_detail' &&
        \function_exists(__NAMESPACE__ . '\get_admin_reservation_detail_page_url')
    ) {
        $redirect_url = get_admin_reservation_detail_page_url(
            $reservation_id,
            ['notice' => 'reservation_created']
        );
    } elseif (
        $redirectTarget === 'reservations' &&
        \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url')
    ) {
        $redirect_url = get_admin_reservations_page_url(
            [
                'notice' => 'reservation_created',
                'reservation_id' => $reservation_id,
            ]
        );
    } else {
        $redirect_url = get_admin_dashboard_page_url(
            [
                'notice' => 'quick_booking_created',
                'reservation_id' => $reservation_id,
            ]
        );
    }

    \wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Render quick booking success notice for dashboard.
 */
function render_dashboard_quick_booking_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice !== 'quick_booking_created') {
        return;
    }

    echo '<div class="notice notice-success"><p>' . \esc_html__('Quick booking created successfully.', 'must-hotel-booking') . '</p></div>';
}

/**
 * Render quick booking panel.
 *
 * @param array<string, mixed>|null $submitted_form
 * @param array<int, string> $errors
 * @param array<string, string> $options
 */
function render_admin_quick_booking_panel(?array $submitted_form = null, array $errors = [], array $options = []): void
{
    $form = \array_merge(get_admin_quick_booking_form_defaults(), \is_array($submitted_form) ? $submitted_form : []);
    $rooms = get_admin_quick_booking_rooms();
    $source_options = get_admin_quick_booking_source_options();
    $checkin = (string) ($form['checkin'] ?? '');
    $checkout = (string) ($form['checkout'] ?? '');
    $guests = \max(1, (int) ($form['guests'] ?? 1));
    $actionUrl = isset($options['action_url']) && (string) $options['action_url'] !== ''
        ? (string) $options['action_url']
        : get_admin_dashboard_page_url();
    $redirectTarget = isset($options['redirect_target']) && (string) $options['redirect_target'] !== ''
        ? \sanitize_key((string) $options['redirect_target'])
        : 'dashboard';
    $returnUrl = isset($options['return_url']) && (string) $options['return_url'] !== ''
        ? \esc_url_raw((string) $options['return_url'])
        : '';
    $title = isset($options['title']) && (string) $options['title'] !== ''
        ? (string) $options['title']
        : \__('Quick Booking', 'must-hotel-booking');
    $eyebrow = isset($options['eyebrow']) && (string) $options['eyebrow'] !== ''
        ? (string) $options['eyebrow']
        : \__('Front Desk Shortcut', 'must-hotel-booking');
    $description = isset($options['description']) && (string) $options['description'] !== ''
        ? (string) $options['description']
        : \__('Create a confirmed reservation fast, with live availability and pricing before you submit.', 'must-hotel-booking');
    $submitLabel = isset($options['submit_label']) && (string) $options['submit_label'] !== ''
        ? (string) $options['submit_label']
        : \__('Create Reservation', 'must-hotel-booking');
    $roomLabel = \__('No room selected', 'must-hotel-booking');

    foreach ($rooms as $room) {
        if (!\is_array($room)) {
            continue;
        }

        if ((int) ($room['id'] ?? 0) !== (int) ($form['room_id'] ?? 0)) {
            continue;
        }

        $roomLabel = (string) ($room['name'] ?? $roomLabel);
        break;
    }

    $nightsLabel = \__('Select valid stay dates', 'must-hotel-booking');

    if (AvailabilityEngine::isValidBookingDate($checkin) && AvailabilityEngine::isValidBookingDate($checkout) && $checkin < $checkout) {
        $nightCount = (int) (new \DateTimeImmutable($checkin))->diff(new \DateTimeImmutable($checkout))->format('%a');
        $nightsLabel = \sprintf(
            /* translators: %d is the number of nights. */
            \_n('%d night', '%d nights', $nightCount, 'must-hotel-booking'),
            $nightCount
        );
    }

    $destinationLabel = \__('Returns to dashboard after save', 'must-hotel-booking');

    if ($redirectTarget === 'calendar') {
        $destinationLabel = \__('Returns to calendar after save', 'must-hotel-booking');
    } elseif ($redirectTarget === 'reservation_detail') {
        $destinationLabel = \__('Opens the new reservation detail page', 'must-hotel-booking');
    } elseif ($redirectTarget === 'reservations') {
        $destinationLabel = \__('Opens the reservations list after save', 'must-hotel-booking');
    }

    echo '<div class="must-admin-quick-booking-panel postbox">';
    echo '<div class="must-admin-quick-booking-panel-inner">';
    echo '<div class="must-quick-booking-header">';
    echo '<div class="must-quick-booking-header-copy">';
    echo '<span class="must-quick-booking-eyebrow">' . \esc_html($eyebrow) . '</span>';
    echo '<h2>' . \esc_html($title) . '</h2>';
    echo '<p class="must-quick-booking-description">' . \esc_html($description) . '</p>';
    echo '</div>';
    echo '<div class="must-quick-booking-header-meta">';
    echo '<span class="must-quick-booking-pill is-strong">' . \esc_html($nightsLabel) . '</span>';
    echo '<span class="must-quick-booking-pill">' . \esc_html(\sprintf(_n('%d guest', '%d guests', $guests, 'must-hotel-booking'), $guests)) . '</span>';
    echo '<span class="must-quick-booking-pill">' . \esc_html($roomLabel) . '</span>';
    echo '</div>';
    echo '</div>';

    if (!empty($errors)) {
        echo '<div class="must-quick-booking-errors notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<form method="post" action="' . \esc_url($actionUrl) . '" class="must-quick-booking-form">';
    \wp_nonce_field('must_quick_booking_save', 'must_quick_booking_nonce');
    echo '<input type="hidden" name="must_quick_booking_action" value="create_quick_booking" />';
    echo '<input type="hidden" name="must_quick_booking_redirect_target" value="' . \esc_attr($redirectTarget) . '" />';

    if ($returnUrl !== '') {
        echo '<input type="hidden" name="must_quick_booking_return_url" value="' . \esc_attr($returnUrl) . '" />';
    }

    echo '<div class="must-quick-booking-section must-quick-booking-form-full-row">';
    echo '<div class="must-quick-booking-section-head">';
    echo '<h3>' . \esc_html__('Stay Details', 'must-hotel-booking') . '</h3>';
    echo '<p>' . \esc_html__('Choose the room, date range, and party size before guest details.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-quick-booking-fields-grid is-stay">';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Room', 'must-hotel-booking') . '</span>';
    echo '<select name="room_id" required>';
    echo '<option value="">' . \esc_html__('Select room', 'must-hotel-booking') . '</option>';

    foreach ($rooms as $room) {
        $room_id = isset($room['id']) ? (int) $room['id'] : 0;
        $selected = $room_id === (int) ($form['room_id'] ?? 0) ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $room_id) . '"' . $selected . '>' . \esc_html((string) ($room['name'] ?? '')) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Check-in', 'must-hotel-booking') . '</span>';
    echo '<input type="date" name="checkin" value="' . \esc_attr($checkin) . '" required />';
    echo '</label>';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Check-out', 'must-hotel-booking') . '</span>';
    echo '<input type="date" name="checkout" value="' . \esc_attr($checkout) . '" required />';
    echo '</label>';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Guests', 'must-hotel-booking') . '</span>';
    echo '<input type="number" name="guests" min="1" step="1" value="' . \esc_attr((string) $guests) . '" required />';
    echo '</label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-quick-booking-section must-quick-booking-form-full-row">';
    echo '<div class="must-quick-booking-section-head">';
    echo '<h3>' . \esc_html__('Guest & Source', 'must-hotel-booking') . '</h3>';
    echo '<p>' . \esc_html__('Capture the key guest details and where the booking came from.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-quick-booking-fields-grid is-guest">';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Guest name', 'must-hotel-booking') . '</span>';
    echo '<input type="text" name="guest_name" value="' . \esc_attr((string) ($form['guest_name'] ?? '')) . '" required />';
    echo '</label>';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Phone', 'must-hotel-booking') . '</span>';
    echo '<input type="text" name="phone" value="' . \esc_attr((string) ($form['phone'] ?? '')) . '" />';
    echo '</label>';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Email', 'must-hotel-booking') . '</span>';
    echo '<input type="email" name="email" value="' . \esc_attr((string) ($form['email'] ?? '')) . '" required />';
    echo '</label>';

    echo '<label class="must-quick-booking-field">';
    echo '<span>' . \esc_html__('Booking source', 'must-hotel-booking') . '</span>';
    echo '<select name="booking_source" required>';

    foreach ($source_options as $source_key => $source_label) {
        $selected = ((string) ($form['booking_source'] ?? 'website') === $source_key) ? ' selected' : '';
        echo '<option value="' . \esc_attr($source_key) . '"' . $selected . '>' . \esc_html($source_label) . '</option>';
    }

    echo '</select>';
    echo '</label>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-quick-booking-section must-quick-booking-form-full-row">';
    echo '<div class="must-quick-booking-section-head">';
    echo '<h3>' . \esc_html__('Notes', 'must-hotel-booking') . '</h3>';
    echo '<p>' . \esc_html__('Add any internal context the front desk or reservations team should see later.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<label class="must-quick-booking-field must-quick-booking-form-full-row">';
    echo '<span>' . \esc_html__('Notes', 'must-hotel-booking') . '</span>';
    echo '<textarea name="notes" rows="3">' . \esc_textarea((string) ($form['notes'] ?? '')) . '</textarea>';
    echo '</label>';
    echo '</div>';

    echo '<div class="must-quick-booking-checkout must-quick-booking-form-full-row">';
    echo '<div class="must-quick-booking-live">';
    echo '<div class="must-quick-booking-live-head">';
    echo '<div>';
    echo '<strong>' . \esc_html__('Booking Check', 'must-hotel-booking') . '</strong>';
    echo '<p>' . \esc_html($destinationLabel) . '</p>';
    echo '</div>';
    echo '<span class="must-quick-booking-pill is-strong">' . \esc_html__('Live Preview', 'must-hotel-booking') . '</span>';
    echo '</div>';
    echo '<p class="must-quick-booking-status is-info" aria-live="polite">' . \esc_html__('Select room, dates, and guests to check availability.', 'must-hotel-booking') . '</p>';
    echo '<p class="must-quick-booking-price"><strong>' . \esc_html__('Calculated price', 'must-hotel-booking') . '</strong><span class="must-quick-booking-price-value">-</span></p>';
    echo '<div class="must-quick-booking-submit-row">';
    echo '<p class="must-quick-booking-submit-copy">' . \esc_html__('Submitting creates a confirmed reservation immediately if the room is still available.', 'must-hotel-booking') . '</p>';
    echo '<button type="submit" class="button button-primary">' . \esc_html($submitLabel) . '</button>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
}

/**
 * AJAX preview for quick booking availability and pricing.
 */
function ajax_must_admin_quick_booking_preview(): void
{
    if (!\function_exists('current_user_can') || !\current_user_can(get_admin_capability())) {
        \wp_send_json_error(
            [
                'message' => \__('You do not have permission to perform this action.', 'must-hotel-booking'),
            ],
            403
        );
    }

    $nonce = isset($_POST['nonce']) ? (string) \wp_unslash($_POST['nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_admin_quick_booking_preview')) {
        \wp_send_json_error(
            [
                'message' => \__('Security check failed. Please refresh and try again.', 'must-hotel-booking'),
            ],
            403
        );
    }

    $room_id = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
    $guests = isset($_POST['guests']) ? \max(1, \absint(\wp_unslash($_POST['guests']))) : 1;

    if ($room_id <= 0 || !does_admin_quick_booking_room_exist($room_id)) {
        \wp_send_json_success(
            [
                'available' => false,
                'message' => \__('Please select a valid room.', 'must-hotel-booking'),
                'total_price' => 0.0,
                'formatted_total_price' => '-',
            ]
        );
    }

    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !AvailabilityEngine::isValidBookingDate($checkin) ||
        !AvailabilityEngine::isValidBookingDate($checkout) ||
        $checkin >= $checkout
    ) {
        \wp_send_json_success(
            [
                'available' => false,
                'message' => \__('Please provide a valid check-in/check-out range.', 'must-hotel-booking'),
                'total_price' => 0.0,
                'formatted_total_price' => '-',
            ]
        );
    }

    $room = get_admin_quick_booking_room_row($room_id);

    if (\is_array($room)) {
        $max_guests = isset($room['max_guests']) ? (int) $room['max_guests'] : 1;

        if ($max_guests > 0 && $guests > $max_guests) {
            \wp_send_json_success(
                [
                    'available' => false,
                    'message' => \__('Selected room cannot host that many guests.', 'must-hotel-booking'),
                    'total_price' => 0.0,
                    'formatted_total_price' => '-',
                ]
            );
        }
    }

    if (!is_admin_quick_booking_room_available($room_id, $checkin, $checkout)) {
        \wp_send_json_success(
            [
                'available' => false,
                'message' => \__('This room is unavailable for the selected dates.', 'must-hotel-booking'),
                'total_price' => 0.0,
                'formatted_total_price' => '-',
            ]
        );
    }

    $total_price = get_admin_quick_booking_total_price($room_id, $checkin, $checkout, $guests);
    $currency = \class_exists(MustBookingConfig::class)
        ? MustBookingConfig::get_currency()
        : 'USD';

    \wp_send_json_success(
        [
            'available' => true,
            'message' => \__('Room is available.', 'must-hotel-booking'),
            'total_price' => $total_price,
            'formatted_total_price' => \number_format_i18n($total_price, 2),
            'currency' => $currency,
        ]
    );
}

/**
 * Enqueue quick booking panel assets.
 */
function enqueue_admin_quick_booking_assets(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));
    $supported_pages = ['must-hotel-booking', 'must-hotel-booking-calendar', 'must-hotel-booking-reservation-create'];

    if (!\in_array($page, $supported_pages, true)) {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-quick-booking',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-quick-booking.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_script(
        'must-hotel-booking-admin-quick-booking',
        MUST_HOTEL_BOOKING_URL . 'assets/js/admin-quick-booking.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_localize_script(
        'must-hotel-booking-admin-quick-booking',
        'mustHotelBookingAdminQuickBooking',
        [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'previewAction' => 'must_admin_quick_booking_preview',
            'previewNonce' => \wp_create_nonce('must_admin_quick_booking_preview'),
            'strings' => [
                'incomplete' => \__('Select room, dates, and guests to check availability.', 'must-hotel-booking'),
                'checking' => \__('Checking availability...', 'must-hotel-booking'),
                'available' => \__('Room is available.', 'must-hotel-booking'),
                'unavailable' => \__('Room is unavailable for the selected dates.', 'must-hotel-booking'),
                'requestFailed' => \__('Unable to check availability right now.', 'must-hotel-booking'),
                'priceUnavailable' => '-',
            ],
        ]
    );
}

\add_action('wp_ajax_must_admin_quick_booking_preview', __NAMESPACE__ . '\ajax_must_admin_quick_booking_preview');
\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_quick_booking_assets');
