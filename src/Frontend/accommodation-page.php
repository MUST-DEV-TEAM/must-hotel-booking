<?php

namespace MustHotelBooking\Frontend;

use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\PricingEngine;
use MustHotelBooking\Engine\RatePlanEngine;
use MustHotelBooking\Engine\ReservationEngine;

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
    return AvailabilityEngine::getAccommodationSelectedRoomItems($selected_room_ids);
}

/**
 * Check whether a room set can host the requested party size.
 *
 * @param array<int, array<string, mixed>> $rooms
 */
function can_accommodation_room_set_host_party(array $rooms, int $guests, int $target_room_count): bool
{
    return AvailabilityEngine::canRoomSetHostParty($rooms, $guests, $target_room_count);
}

/**
 * Build the continue CTA label for the accommodation results toolbar.
 */
function get_accommodation_continue_label(bool $can_continue, int $selected_room_count, int $resolved_room_count): string
{
    return AvailabilityEngine::getAccommodationContinueLabel($can_continue, $selected_room_count, $resolved_room_count);
}

/**
 * Build a compact selection-status note for the accommodation page.
 *
 * @return array{message: string, tone: string}
 */
function get_accommodation_selection_status_data(int $selected_room_count, int $resolved_room_count, int $selected_capacity, int $guests, bool $can_continue): array
{
    return AvailabilityEngine::getAccommodationSelectionStatusData(
        $selected_room_count,
        $resolved_room_count,
        $selected_capacity,
        $guests,
        $can_continue
    );
}

/**
 * Build a clearer empty-state message for the accommodation results page.
 */
function get_accommodation_empty_results_message(array $context, int $resolved_room_count): string
{
    return AvailabilityEngine::getAccommodationEmptyResultsMessage($context, $resolved_room_count);
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
    return AvailabilityEngine::getAccommodationSelectionState($context, $messages);
}

/**
 * Process a room-selection request and return a reusable result payload.
 *
 * @param array<string, mixed> $request_source
 * @return array<string, mixed>
 */
function handle_accommodation_room_selection_request(array $request_source): array
{
    return ReservationEngine::handleAccommodationRoomSelectionRequest($request_source);
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
    $context = BookingValidationEngine::parseRequestContext($raw_get, false);
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
    $selected_rate_plan_map = get_booking_selected_room_rate_plan_map();
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

    if ($has_context && !empty($context['is_valid'])) {
        $room_results = AvailabilityEngine::getAvailableRooms(
            (string) $context['checkin'],
            (string) $context['checkout'],
            $single_room_mode ? (int) $context['guests'] : 1,
            (string) $context['accommodation_type']
        );

        if (\is_array($room_results) && AvailabilityEngine::canRoomSetHostParty($room_results, (int) ($context['guests'] ?? 1), $resolved_room_count)) {
            foreach ($room_results as $room) {
                if (!\is_array($room)) {
                    continue;
                }

                $room_id = isset($room['id']) ? (int) $room['id'] : 0;

                if ($room_id <= 0) {
                    continue;
                }

                if ($single_room_mode) {
                    $pricing = PricingEngine::calculateTotal(
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
                    $display_guests = $single_room_mode ? (int) ($context['guests'] ?? 1) : 1;
                    $rate_plans = RatePlanEngine::getRoomRatePlansWithPricing(
                        $room_id,
                        (string) $context['checkin'],
                        (string) $context['checkout'],
                        $display_guests
                    );
                    $selected_rate_plan_id = isset($selected_rate_plan_map[$room_id]) ? (int) $selected_rate_plan_map[$room_id] : 0;

                    $room_view['rate_plans'] = \array_map(
                        static function (array $rate_plan) use ($room_id, $selected_rate_plan_id): array {
                            $rate_plan['room_id'] = $room_id;
                            $rate_plan['is_selected'] = (int) ($rate_plan['id'] ?? 0) === $selected_rate_plan_id;

                            return $rate_plan;
                        },
                        $rate_plans
                    );
                    $room_view['is_selected'] = \in_array($room_id, $selected_room_ids, true);
                    $room_view['selected_rate_plan_id'] = $selected_rate_plan_id;

                    if (empty($room_view['rate_plans'])) {
                        continue;
                    }

                    $rooms[] = $room_view;
                }
            }
        }

        if (empty($rooms)) {
            $no_rooms_message = AvailabilityEngine::getAccommodationEmptyResultsMessage($context, $resolved_room_count);
        }
    }

    $selection_state = AvailabilityEngine::getAccommodationSelectionState($context);
    $selected_capacity = 0;

    foreach ($selected_room_items as $selected_room_item) {
        $selected_capacity += isset($selected_room_item['effective_max_guests'])
            ? \max(0, (int) $selected_room_item['effective_max_guests'])
            : (isset($selected_room_item['max_guests']) ? \max(0, (int) $selected_room_item['max_guests']) : 0);
    }

    $remaining_guests = \max(0, (int) ($context['guests'] ?? 1) - $selected_capacity);
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
        'can_continue' => !empty($selection_state['can_continue']),
        'selection_limit_reached' => !empty($selection_state['selection_limit_reached']),
        'selection_status_message' => (string) ($selection_state['selection_status_message'] ?? ''),
        'selection_status_tone' => (string) ($selection_state['selection_status_tone'] ?? 'neutral'),
        'single_room_mode' => !empty($selection_state['single_room_mode']),
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
        [],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_style(
        'must-hotel-booking-rooms-list-widget-lightbox',
        MUST_HOTEL_BOOKING_URL . 'assets/css/rooms-list-widget.css',
        [],
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
            'icons' => [
                'lightboxPrev' => MUST_HOTEL_BOOKING_URL . 'assets/img/lightboxleft.svg',
                'lightboxNext' => MUST_HOTEL_BOOKING_URL . 'assets/img/lightboxright.svg',
            ],
            'labels' => [
                'addRoom' => \__('Select', 'must-hotel-booking'),
                'removeRoom' => \__('Remove Room', 'must-hotel-booking'),
                'removeSelection' => \__('Remove Selection', 'must-hotel-booking'),
                'chooseRate' => \__('Choose This Rate', 'must-hotel-booking'),
                'bookNow' => \__('Select', 'must-hotel-booking'),
                'selectionFull' => \__('Selection Full', 'must-hotel-booking'),
                'requestFailed' => \__('Unable to update your room selection right now. Please try again.', 'must-hotel-booking'),
            ],
        ]
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_booking_accommodation_page_assets');
\add_action('wp_ajax_must_booking_accommodation_room_action', __NAMESPACE__ . '\handle_accommodation_room_selection_ajax');
\add_action('wp_ajax_nopriv_must_booking_accommodation_room_action', __NAMESPACE__ . '\handle_accommodation_room_selection_ajax');
