<?php

namespace must_hotel_booking;

/**
 * Check if current frontend request is the managed accommodation step page.
 */
function is_frontend_booking_accommodation_page(): bool
{
    if (\is_admin()) {
        return false;
    }

    $settings = get_plugin_settings();
    $page_id = isset($settings['page_booking_accommodation_id']) ? (int) $settings['page_booking_accommodation_id'] : 0;

    if ($page_id > 0 && \is_page($page_id)) {
        return true;
    }

    return \is_page('booking-accommodation');
}

/**
 * Build accommodation page URL with a booking context.
 *
 * @param array<string, mixed> $context
 */
function get_booking_accommodation_context_url(array $context): string
{
    $normalized_context = normalize_booking_selection_context($context);
    $args = [];

    if ((string) ($normalized_context['checkin'] ?? '') !== '') {
        $args['checkin'] = (string) $normalized_context['checkin'];
    }

    if ((string) ($normalized_context['checkout'] ?? '') !== '') {
        $args['checkout'] = (string) $normalized_context['checkout'];
    }

    $args['guests'] = (int) ($normalized_context['guests'] ?? 1);
    $args['accommodation_type'] = (string) ($normalized_context['accommodation_type'] ?? 'standard-rooms');

    return \add_query_arg($args, get_booking_accommodation_page_url());
}

/**
 * Handle room selection on the accommodation page.
 *
 * @return array<int, string>
 */
function maybe_process_accommodation_room_selection(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_accommodation_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_accommodation_action'])) : '';

    if ($action !== 'select_room') {
        return [];
    }

    $nonce = isset($_POST['must_accommodation_nonce']) ? (string) \wp_unslash($_POST['must_accommodation_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_accommodation_select_room')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    $room_id = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $context = parse_booking_request_context(\is_array($_POST) ? $_POST : [], true);

    if (!$context['is_valid']) {
        return (array) $context['errors'];
    }

    if ($room_id <= 0) {
        return [\__('Please select a room to continue.', 'must-hotel-booking')];
    }

    if (!\function_exists(__NAMESPACE__ . '\create_temporary_reservation_lock')) {
        return [\__('Lock engine is not available.', 'must-hotel-booking')];
    }

    $lock_created = create_temporary_reservation_lock(
        $room_id,
        (string) $context['checkin'],
        (string) $context['checkout']
    );

    if (!$lock_created) {
        return [\__('This room is no longer available for the selected dates.', 'must-hotel-booking')];
    }

    $selection_added = add_room_to_booking_selection(
        $room_id,
        [
            'checkin' => (string) $context['checkin'],
            'checkout' => (string) $context['checkout'],
            'guests' => (int) $context['guests'],
            'accommodation_type' => (string) $context['accommodation_type'],
        ]
    );

    if (!$selection_added) {
        return [\__('Unable to store the selected room.', 'must-hotel-booking')];
    }

    \wp_safe_redirect(get_checkout_page_url());
    exit;
}

/**
 * Build view data for the accommodation step template.
 *
 * @return array<string, mixed>
 */
function get_accommodation_page_view_data(): array
{
    $messages = maybe_process_accommodation_room_selection();
    /** @var array<string, mixed> $raw_get */
    $raw_get = \is_array($_GET) ? $_GET : [];
    $context = parse_booking_request_context($raw_get, false);
    $selection = get_booking_selection();
    $selection_context = normalize_booking_selection_context($selection['context'] ?? []);

    if (
        ((string) ($context['checkin'] ?? '') === '' || (string) ($context['checkout'] ?? '') === '') &&
        (string) ($selection_context['checkin'] ?? '') !== '' &&
        (string) ($selection_context['checkout'] ?? '') !== ''
    ) {
        $context = [
            'checkin' => (string) $selection_context['checkin'],
            'checkout' => (string) $selection_context['checkout'],
            'guests' => (int) $selection_context['guests'],
            'accommodation_type' => (string) $selection_context['accommodation_type'],
            'is_valid' => true,
            'errors' => [],
        ];
    }

    $has_context = (string) ($context['checkin'] ?? '') !== '' && (string) ($context['checkout'] ?? '') !== '';

    if ($has_context && !empty($context['is_valid'])) {
        set_booking_selection_context(
            [
                'checkin' => (string) $context['checkin'],
                'checkout' => (string) $context['checkout'],
                'guests' => (int) $context['guests'],
                'accommodation_type' => (string) $context['accommodation_type'],
            ]
        );
        $selection = get_booking_selection();
    } elseif ($has_context && empty($context['is_valid'])) {
        foreach ((array) ($context['errors'] ?? []) as $error_message) {
            $messages[] = (string) $error_message;
        }
    }

    $selected_room_ids = get_booking_selected_room_ids();
    $rooms = [];

    if ($has_context && !empty($context['is_valid']) && \function_exists(__NAMESPACE__ . '\get_available_rooms')) {
        $room_results = get_available_rooms(
            (string) $context['checkin'],
            (string) $context['checkout'],
            (int) $context['guests'],
            (string) $context['accommodation_type']
        );

        if (\is_array($room_results)) {
            foreach ($room_results as $room) {
                if (!\is_array($room)) {
                    continue;
                }

                $room_id = isset($room['id']) ? (int) $room['id'] : 0;

                if ($room_id <= 0 || \in_array($room_id, $selected_room_ids, true)) {
                    continue;
                }

                if (\function_exists(__NAMESPACE__ . '\calculate_booking_price')) {
                    $pricing = calculate_booking_price(
                        $room_id,
                        (string) $context['checkin'],
                        (string) $context['checkout'],
                        (int) $context['guests']
                    );

                    if (\is_array($pricing) && !empty($pricing['success']) && isset($pricing['total_price'])) {
                        $room['price_preview_total'] = (float) $pricing['total_price'];
                    }
                }

                $room_view = \function_exists(__NAMESPACE__ . '\get_booking_results_room_view_data')
                    ? get_booking_results_room_view_data($room)
                    : $room;

                if ($room_view !== null && \is_array($room_view)) {
                    $rooms[] = $room_view;
                }
            }
        }
    }

    $context_url = $has_context ? get_booking_context_url($context) : get_booking_page_url();

    return [
        'messages' => \array_values(\array_unique(\array_filter(\array_map('strval', $messages)))),
        'rooms' => $rooms,
        'has_context' => $has_context,
        'is_valid' => !empty($context['is_valid']),
        'checkin' => (string) ($context['checkin'] ?? ''),
        'checkout' => (string) ($context['checkout'] ?? ''),
        'guests' => (int) ($context['guests'] ?? 1),
        'accommodation_type' => (string) ($context['accommodation_type'] ?? 'standard-rooms'),
        'selected_room_count' => \count($selected_room_ids),
        'booking_url' => $context_url,
        'checkout_url' => get_checkout_page_url(),
        'accommodation_url' => $has_context ? get_booking_accommodation_context_url($context) : get_booking_accommodation_page_url(),
    ];
}

/**
 * Enqueue accommodation step assets.
 */
function enqueue_booking_accommodation_page_assets(): void
{
    if (!is_frontend_booking_accommodation_page()) {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-page.css',
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_style(
        'must-hotel-booking-rooms-list-widget-lightbox',
        MUST_HOTEL_BOOKING_URL . 'assets/css/rooms-list-widget.css',
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_script(
        'must-hotel-booking-booking-accommodation',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-accommodation.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_booking_accommodation_page_assets');
