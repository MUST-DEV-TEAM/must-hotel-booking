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
    $args['room_count'] = \function_exists(__NAMESPACE__ . '\normalize_booking_room_count')
        ? normalize_booking_room_count($normalized_context['room_count'] ?? 0)
        : 0;
    $args['accommodation_type'] = (string) ($normalized_context['accommodation_type'] ?? 'standard-rooms');

    return \add_query_arg($args, get_booking_accommodation_page_url());
}

/**
 * Build accommodation-page room selection summary items.
 *
 * @param array<int, int> $selected_room_ids
 * @return array<int, array<string, mixed>>
 */
function get_accommodation_selected_room_items(array $selected_room_ids): array
{
    $items = [];

    foreach ($selected_room_ids as $selected_room_id) {
        $room_id = (int) $selected_room_id;

        if ($room_id <= 0 || !\function_exists(__NAMESPACE__ . '\get_room_record')) {
            continue;
        }

        $room = get_room_record($room_id);

        if (!\is_array($room)) {
            continue;
        }

        $room_view = \function_exists(__NAMESPACE__ . '\get_booking_results_room_view_data')
            ? get_booking_results_room_view_data($room)
            : $room;

        if (\is_array($room_view)) {
            $items[] = $room_view;
        }
    }

    return $items;
}

/**
 * Check whether a room set can host the requested party size.
 *
 * @param array<int, array<string, mixed>> $rooms
 */
function can_accommodation_room_set_host_party(array $rooms, int $guests, int $target_room_count): bool
{
    $target_room_count = \max(1, $target_room_count);
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

    if (\count($capacities) < $target_room_count) {
        return false;
    }

    \rsort($capacities, SORT_NUMERIC);

    return \array_sum(\array_slice($capacities, 0, $target_room_count)) >= \max(1, $guests);
}

/**
 * Build the continue CTA label for the accommodation results toolbar.
 */
function get_accommodation_continue_label(bool $can_continue, int $selected_room_count, int $resolved_room_count): string
{
    if ($can_continue) {
        return \sprintf(
            /* translators: %d is selected room count. */
            \_n('Continue with %d Room', 'Continue with %d Rooms', $selected_room_count, 'must-hotel-booking'),
            $selected_room_count
        );
    }

    return \sprintf(
        /* translators: 1: selected room count, 2: target room count label. */
        \__('Selected %1$d / %2$s', 'must-hotel-booking'),
        $selected_room_count,
        \function_exists(__NAMESPACE__ . '\format_booking_room_count_label')
            ? format_booking_room_count_label($resolved_room_count)
            : (string) $resolved_room_count
    );
}

/**
 * Build a compact selection-status note for the accommodation page.
 *
 * @return array{message: string, tone: string}
 */
function get_accommodation_selection_status_data(int $selected_room_count, int $resolved_room_count, int $selected_capacity, int $guests, bool $can_continue): array
{
    $selected_room_count = \max(0, $selected_room_count);
    $resolved_room_count = \max(1, $resolved_room_count);
    $selected_capacity = \max(0, $selected_capacity);
    $guests = \max(1, $guests);
    $remaining_guests = \max(0, $guests - $selected_capacity);

    if ($can_continue) {
        return [
            'message' => \sprintf(
                /* translators: 1: selected room count, 2: guest count. */
                \__('Selected %1$d room(s). Your current selection can host all %2$d guests.', 'must-hotel-booking'),
                $selected_room_count,
                $guests
            ),
            'tone' => 'success',
        ];
    }

    if ($selected_room_count === 0) {
        return [
            'message' => \sprintf(
                /* translators: 1: room count label, 2: guest count. */
                \__('Choose %1$s that can host %2$d guests.', 'must-hotel-booking'),
                \function_exists(__NAMESPACE__ . '\format_booking_room_count_label')
                    ? \strtolower(format_booking_room_count_label($resolved_room_count))
                    : (string) $resolved_room_count,
                $guests
            ),
            'tone' => 'neutral',
        ];
    }

    if ($remaining_guests > 0 && $selected_room_count >= $resolved_room_count) {
        return [
            'message' => \sprintf(
                /* translators: 1: selected room count, 2: selected capacity, 3: guest count. */
                \__('Selected %1$d room(s). They currently host %2$d of %3$d guests. Increase the room count or choose rooms with more capacity.', 'must-hotel-booking'),
                $selected_room_count,
                $selected_capacity,
                $guests
            ),
            'tone' => 'warning',
        ];
    }

    if ($remaining_guests > 0) {
        return [
            'message' => \sprintf(
                /* translators: 1: selected room count, 2: target room count, 3: remaining guest count. */
                \__('Selected %1$d of %2$d room(s). %3$d guest(s) still need a room.', 'must-hotel-booking'),
                $selected_room_count,
                $resolved_room_count,
                $remaining_guests
            ),
            'tone' => 'neutral',
        ];
    }

    return [
        'message' => \sprintf(
            /* translators: 1: remaining room count, 2: target room count. */
            \__('Selected rooms can host the full party. Choose %1$d more of %2$d room(s) to continue.', 'must-hotel-booking'),
            \max(0, $resolved_room_count - $selected_room_count),
            $resolved_room_count
        ),
        'tone' => 'neutral',
    ];
}

/**
 * Build a clearer empty-state message for the accommodation results page.
 */
function get_accommodation_empty_results_message(array $context, int $resolved_room_count): string
{
    $guests = \max(1, (int) ($context['guests'] ?? 1));
    $requested_room_count = (int) ($context['room_count'] ?? 0);
    $effective_room_count = $requested_room_count > 0 ? $requested_room_count : $resolved_room_count;
    $room_limit = \function_exists(__NAMESPACE__ . '\get_booking_context_guest_limit')
        ? get_booking_context_guest_limit((string) ($context['accommodation_type'] ?? 'standard-rooms'), $effective_room_count)
        : 0;

    if ($requested_room_count > 0 && $room_limit > 0 && $guests > $room_limit) {
        return \sprintf(
            /* translators: 1: guests count, 2: room count, 3: max supported guests. */
            \__('The selected combination of %1$d guests and %2$d rooms is not possible here. That room count can host up to %3$d guests. Increase the room count or reduce the number of guests.', 'must-hotel-booking'),
            $guests,
            $requested_room_count,
            $room_limit
        );
    }

    if ($effective_room_count > 0) {
        return \sprintf(
            /* translators: 1: guests count, 2: room count. */
            \__('No available combination of %2$d rooms can host %1$d guests for the selected dates.', 'must-hotel-booking'),
            $guests,
            $effective_room_count
        );
    }

    return \sprintf(
        /* translators: %d is guest count. */
        \__('No rooms are available for %d guests on the selected dates.', 'must-hotel-booking'),
        $guests
    );
}

/**
 * Build room-selection state for accommodation-page AJAX responses.
 *
 * @param array<string, mixed> $context
 * @param array<int, string>   $messages
 * @return array<string, mixed>
 */
function get_accommodation_room_selection_state(array $context, array $messages = []): array
{
    $selected_room_ids = get_booking_selected_room_ids();
    $selected_room_items = get_accommodation_selected_room_items($selected_room_ids);
    $requested_room_count = (int) ($context['room_count'] ?? 0);
    $resolved_room_count = \function_exists(__NAMESPACE__ . '\resolve_booking_room_count')
        ? resolve_booking_room_count(
            (int) ($context['guests'] ?? 1),
            $requested_room_count,
            (string) ($context['accommodation_type'] ?? 'standard-rooms')
        )
        : 1;
    $single_room_mode = $resolved_room_count <= 1;
    $selected_capacity = 0;

    foreach ($selected_room_items as $selected_room_item) {
        $selected_capacity += isset($selected_room_item['max_guests']) ? \max(0, (int) $selected_room_item['max_guests']) : 0;
    }

    $remaining_guests = \max(0, (int) ($context['guests'] ?? 1) - $selected_capacity);
    $can_continue = (string) ($context['checkin'] ?? '') !== ''
        && (string) ($context['checkout'] ?? '') !== ''
        && !empty($context['is_valid'])
        && \count($selected_room_ids) === $resolved_room_count
        && $remaining_guests === 0;
    $selection_limit_reached = \count($selected_room_ids) >= $resolved_room_count;
    $selection_status = get_accommodation_selection_status_data(
        \count($selected_room_ids),
        $resolved_room_count,
        $selected_capacity,
        (int) ($context['guests'] ?? 1),
        $can_continue
    );

    return [
        'messages' => \array_values(\array_unique(\array_filter(\array_map('strval', $messages)))),
        'selected_room_ids' => $selected_room_ids,
        'selected_room_count' => \count($selected_room_ids),
        'resolved_room_count' => $resolved_room_count,
        'selection_limit_reached' => $selection_limit_reached,
        'single_room_mode' => $single_room_mode,
        'can_continue' => $can_continue,
        'continue_label' => get_accommodation_continue_label($can_continue, \count($selected_room_ids), $resolved_room_count),
        'checkout_url' => $can_continue ? get_checkout_context_url($context) : '',
        'selection_status_message' => (string) ($selection_status['message'] ?? ''),
        'selection_status_tone' => (string) ($selection_status['tone'] ?? 'neutral'),
    ];
}

/**
 * Process a room-selection request and return a reusable result payload.
 *
 * @param array<string, mixed> $request_source
 * @return array<string, mixed>
 */
function handle_accommodation_room_selection_request(array $request_source): array
{
    $action = isset($request_source['must_accommodation_action'])
        ? \sanitize_key((string) \wp_unslash($request_source['must_accommodation_action']))
        : '';

    if (!\in_array($action, ['select_room', 'remove_selected_room'], true)) {
        return [
            'success' => false,
            'messages' => [],
            'context' => parse_booking_request_context($request_source, false),
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    $room_id = isset($request_source['room_id']) ? \absint(\wp_unslash($request_source['room_id'])) : 0;
    $context = parse_booking_request_context($request_source, true);

    if (!$context['is_valid']) {
        return [
            'success' => false,
            'messages' => (array) ($context['errors'] ?? []),
            'context' => $context,
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    if ($room_id <= 0) {
        return [
            'success' => false,
            'messages' => [\__('Please select a room to continue.', 'must-hotel-booking')],
            'context' => $context,
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    $nonce = isset($request_source['must_accommodation_nonce']) ? (string) \wp_unslash($request_source['must_accommodation_nonce']) : '';
    $nonce_action = $action === 'remove_selected_room'
        ? 'must_accommodation_remove_room_' . $room_id
        : 'must_accommodation_select_room';

    if (!\wp_verify_nonce($nonce, $nonce_action)) {
        return [
            'success' => false,
            'messages' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'context' => $context,
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    if (\function_exists(__NAMESPACE__ . '\is_fixed_room_booking_flow') && is_fixed_room_booking_flow()) {
        clear_booking_selection();
    }

    if ($action === 'remove_selected_room') {
        if (!remove_room_from_booking_selection($room_id)) {
            return [
                'success' => false,
                'messages' => [\__('Unable to remove the selected room.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        return [
            'success' => true,
            'messages' => [],
            'context' => $context,
            'redirect_url' => get_booking_accommodation_context_url($context),
            'should_redirect' => false,
        ];
    }

    $target_room_count = \function_exists(__NAMESPACE__ . '\resolve_booking_room_count')
        ? resolve_booking_room_count(
            (int) ($context['guests'] ?? 1),
            (int) ($context['room_count'] ?? 0),
            (string) ($context['accommodation_type'] ?? 'standard-rooms')
        )
        : 1;
    $selected_room_ids = get_booking_selected_room_ids();

    if (!\in_array($room_id, $selected_room_ids, true) && \count($selected_room_ids) >= $target_room_count) {
        return [
            'success' => false,
            'messages' => [\__('You have already selected the maximum number of rooms for this stay. Remove one before adding another.', 'must-hotel-booking')],
            'context' => $context,
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    if (!\function_exists(__NAMESPACE__ . '\create_temporary_reservation_lock')) {
        return [
            'success' => false,
            'messages' => [\__('Lock engine is not available.', 'must-hotel-booking')],
            'context' => $context,
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    $lock_created = \in_array($room_id, $selected_room_ids, true)
        ? true
        : create_temporary_reservation_lock(
            $room_id,
            (string) $context['checkin'],
            (string) $context['checkout']
        );

    if (!$lock_created) {
        return [
            'success' => false,
            'messages' => [\__('This room is no longer available for the selected dates.', 'must-hotel-booking')],
            'context' => $context,
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    $selection_added = add_room_to_booking_selection(
        $room_id,
        [
            'checkin' => (string) $context['checkin'],
            'checkout' => (string) $context['checkout'],
            'guests' => (int) $context['guests'],
            'room_count' => (int) ($context['room_count'] ?? 0),
            'accommodation_type' => (string) $context['accommodation_type'],
        ]
    );

    if (!$selection_added) {
        return [
            'success' => false,
            'messages' => [\__('Unable to store the selected room.', 'must-hotel-booking')],
            'context' => $context,
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    update_booking_selection_flow_data([
        'booking_mode' => '',
        'fixed_room_id' => 0,
    ]);

    if ($target_room_count <= 1) {
        return [
            'success' => true,
            'messages' => [],
            'context' => $context,
            'redirect_url' => get_checkout_context_url($context),
            'should_redirect' => true,
        ];
    }

    return [
        'success' => true,
        'messages' => [],
        'context' => $context,
        'redirect_url' => get_booking_accommodation_context_url($context),
        'should_redirect' => false,
    ];
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

    $result = handle_accommodation_room_selection_request(\is_array($_POST) ? $_POST : []);

    if (empty($result['context']) || !\is_array($result['context'])) {
        return [];
    }

    if (empty($result['success'])) {
        return (array) ($result['messages'] ?? []);
    }

    $context = $result['context'];
    $redirect_url = isset($result['redirect_url']) ? (string) $result['redirect_url'] : '';

    if ($redirect_url === '') {
        $redirect_url = !empty($result['should_redirect'])
            ? get_checkout_context_url($context)
            : get_booking_accommodation_context_url($context);
    }

    \wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Handle AJAX room-selection actions on the accommodation page.
 */
function handle_accommodation_room_selection_ajax(): void
{
    $result = handle_accommodation_room_selection_request(\is_array($_POST) ? $_POST : []);

    if (empty($result['context']) || !\is_array($result['context'])) {
        \wp_send_json_error([
            'messages' => [\__('Unable to process the room selection request.', 'must-hotel-booking')],
        ], 400);
    }

    $context = $result['context'];
    $state = get_accommodation_room_selection_state($context, (array) ($result['messages'] ?? []));

    if (empty($result['success'])) {
        \wp_send_json_error($state, 400);
    }

    if (!empty($result['should_redirect'])) {
        $state['redirect_url'] = isset($result['redirect_url']) ? (string) $result['redirect_url'] : get_checkout_context_url($context);
    }

    \wp_send_json_success($state);
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
    $was_fixed_room_flow = \function_exists(__NAMESPACE__ . '\is_fixed_room_booking_flow') && is_fixed_room_booking_flow();

    if ($was_fixed_room_flow) {
        clear_booking_selection();
    }

    if (
        ((string) ($context['checkin'] ?? '') === '' || (string) ($context['checkout'] ?? '') === '') &&
        (string) ($selection_context['checkin'] ?? '') !== '' &&
        (string) ($selection_context['checkout'] ?? '') !== ''
    ) {
        $context = [
            'checkin' => (string) $selection_context['checkin'],
            'checkout' => (string) $selection_context['checkout'],
            'guests' => (int) $selection_context['guests'],
            'room_count' => (int) ($selection_context['room_count'] ?? 0),
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
                'room_count' => (int) ($context['room_count'] ?? 0),
                'accommodation_type' => (string) $context['accommodation_type'],
            ]
        );
        update_booking_selection_flow_data([
            'booking_mode' => '',
            'fixed_room_id' => 0,
        ]);
        $selection = get_booking_selection();
    } elseif ($has_context && empty($context['is_valid'])) {
        foreach ((array) ($context['errors'] ?? []) as $error_message) {
            $messages[] = (string) $error_message;
        }
    }

    $selected_room_ids = get_booking_selected_room_ids();
    $selected_room_items = get_accommodation_selected_room_items($selected_room_ids);
    $requested_room_count = (int) ($context['room_count'] ?? 0);
    $resolved_room_count = \function_exists(__NAMESPACE__ . '\resolve_booking_room_count')
        ? resolve_booking_room_count(
            (int) ($context['guests'] ?? 1),
            $requested_room_count,
            (string) ($context['accommodation_type'] ?? 'standard-rooms')
        )
        : 1;
    $single_room_mode = $resolved_room_count <= 1;
    $rooms = [];
    $no_rooms_message = \__('No rooms are available for the selected dates.', 'must-hotel-booking');

    if ($has_context && !empty($context['is_valid']) && \function_exists(__NAMESPACE__ . '\get_available_rooms')) {
        $room_results = get_available_rooms(
            (string) $context['checkin'],
            (string) $context['checkout'],
            $single_room_mode ? (int) $context['guests'] : 1,
            (string) $context['accommodation_type']
        );

        if (\is_array($room_results) && can_accommodation_room_set_host_party($room_results, (int) ($context['guests'] ?? 1), $resolved_room_count)) {
            foreach ($room_results as $room) {
                if (!\is_array($room)) {
                    continue;
                }

                $room_id = isset($room['id']) ? (int) $room['id'] : 0;

                if ($room_id <= 0) {
                    continue;
                }

                if ($single_room_mode && \function_exists(__NAMESPACE__ . '\calculate_booking_price')) {
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
                    $room_view['is_selected'] = \in_array($room_id, $selected_room_ids, true);
                    $rooms[] = $room_view;
                }
            }
        }

        if (empty($rooms)) {
            $no_rooms_message = get_accommodation_empty_results_message($context, $resolved_room_count);
        }
    }

    $selected_capacity = 0;

    foreach ($selected_room_items as $selected_room_item) {
        $selected_capacity += isset($selected_room_item['max_guests']) ? \max(0, (int) $selected_room_item['max_guests']) : 0;
    }

    $remaining_guests = \max(0, (int) ($context['guests'] ?? 1) - $selected_capacity);
    $can_continue = $has_context
        && !empty($context['is_valid'])
        && \count($selected_room_ids) === $resolved_room_count
        && $remaining_guests === 0;
    $selection_limit_reached = \count($selected_room_ids) >= $resolved_room_count;
    $selection_status = get_accommodation_selection_status_data(
        \count($selected_room_ids),
        $resolved_room_count,
        $selected_capacity,
        (int) ($context['guests'] ?? 1),
        $can_continue
    );
    $context_url = $has_context ? get_booking_context_url($context) : get_booking_page_url();

    return [
        'messages' => \array_values(\array_unique(\array_filter(\array_map('strval', $messages)))),
        'rooms' => $rooms,
        'has_context' => $has_context,
        'is_valid' => !empty($context['is_valid']),
        'checkin' => (string) ($context['checkin'] ?? ''),
        'checkout' => (string) ($context['checkout'] ?? ''),
        'guests' => (int) ($context['guests'] ?? 1),
        'room_count' => $requested_room_count,
        'resolved_room_count' => $resolved_room_count,
        'accommodation_type' => (string) ($context['accommodation_type'] ?? 'standard-rooms'),
        'selected_rooms' => $selected_room_items,
        'selected_room_count' => \count($selected_room_ids),
        'selected_capacity' => $selected_capacity,
        'remaining_guests' => $remaining_guests,
        'can_continue' => $can_continue,
        'selection_limit_reached' => $selection_limit_reached,
        'selection_status_message' => (string) ($selection_status['message'] ?? ''),
        'selection_status_tone' => (string) ($selection_status['tone'] ?? 'neutral'),
        'single_room_mode' => $single_room_mode,
        'no_rooms_message' => $no_rooms_message,
        'booking_url' => $context_url,
        'checkout_url' => $has_context ? get_checkout_context_url($context) : get_checkout_page_url(),
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

    \wp_localize_script(
        'must-hotel-booking-booking-accommodation',
        'mustBookingAccommodationConfig',
        [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'ajaxAction' => 'must_booking_accommodation_room_action',
            'labels' => [
                'addRoom' => \__('Add Room', 'must-hotel-booking'),
                'removeRoom' => \__('Remove Room', 'must-hotel-booking'),
                'bookNow' => \__('Book Now', 'must-hotel-booking'),
                'selectionFull' => \__('Selection Full', 'must-hotel-booking'),
                'requestFailed' => \__('Unable to update your room selection right now. Please try again.', 'must-hotel-booking'),
            ],
        ]
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_booking_accommodation_page_assets');
\add_action('wp_ajax_must_booking_accommodation_room_action', __NAMESPACE__ . '\handle_accommodation_room_selection_ajax');
\add_action('wp_ajax_nopriv_must_booking_accommodation_room_action', __NAMESPACE__ . '\handle_accommodation_room_selection_ajax');
