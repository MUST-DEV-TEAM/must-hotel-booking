<?php

namespace MustHotelBooking\Frontend;

use MustHotelBooking\Engine\InventoryEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Engine\PricingEngine;

/**
 * Get the transient key used for session-backed booking selections.
 */
function get_booking_selection_transient_key(): string
{
    $session_id = LockEngine::getOrCreateSessionId();

    if ($session_id === '') {
        $session_id = 'guest';
    }

    return 'must_hotel_booking_selection_' . \md5($session_id);
}

/**
 * Get the default booking selection payload.
 *
 * @return array<string, mixed>
 */
function get_empty_booking_selection(): array
{
    return [
        'context' => [
            'checkin' => '',
            'checkout' => '',
            'guests' => 1,
            'room_count' => 0,
            'accommodation_type' => 'standard-rooms',
        ],
        'selected_rooms' => [],
        'flow_data' => [
            'guest_form' => [],
            'billing_form' => [],
            'coupon_code' => '',
            'payment_method' => '',
            'pending_payment' => PaymentEngine::getEmptyPendingPaymentFlowData(),
            'booking_mode' => '',
            'fixed_room_id' => 0,
        ],
    ];
}

/**
 * Normalize booking flow data stored alongside the selection.
 *
 * @param mixed $flow_data
 * @return array<string, mixed>
 */
function normalize_booking_selection_flow_data($flow_data): array
{
    if (!\is_array($flow_data)) {
        return [
            'guest_form' => [],
            'billing_form' => [],
            'coupon_code' => '',
            'payment_method' => '',
            'pending_payment' => PaymentEngine::getEmptyPendingPaymentFlowData(),
            'booking_mode' => '',
            'fixed_room_id' => 0,
        ];
    }

    $booking_mode = isset($flow_data['booking_mode'])
        ? \sanitize_key((string) $flow_data['booking_mode'])
        : '';

    if (!\in_array($booking_mode, ['', 'fixed-room'], true)) {
        $booking_mode = '';
    }

    return [
        'guest_form' => isset($flow_data['guest_form']) && \is_array($flow_data['guest_form'])
            ? $flow_data['guest_form']
            : [],
        'billing_form' => isset($flow_data['billing_form']) && \is_array($flow_data['billing_form'])
            ? $flow_data['billing_form']
            : [],
        'coupon_code' => isset($flow_data['coupon_code'])
            ? \sanitize_text_field((string) $flow_data['coupon_code'])
            : '',
        'payment_method' => isset($flow_data['payment_method'])
            ? \sanitize_key((string) $flow_data['payment_method'])
            : '',
        'pending_payment' => PaymentEngine::normalizePendingPaymentFlowData($flow_data['pending_payment'] ?? []),
        'booking_mode' => $booking_mode,
        'fixed_room_id' => isset($flow_data['fixed_room_id'])
            ? \absint($flow_data['fixed_room_id'])
            : 0,
    ];
}

/**
 * Check whether the current booking flow is a fixed-room booking.
 */
function is_fixed_room_booking_flow(): bool
{
    $flow_data = get_booking_selection_flow_data();

    return (string) ($flow_data['booking_mode'] ?? '') === 'fixed-room' && (int) ($flow_data['fixed_room_id'] ?? 0) > 0;
}

/**
 * Get the fixed room ID for the current booking flow.
 */
function get_fixed_room_booking_room_id(): int
{
    $flow_data = get_booking_selection_flow_data();

    if ((string) ($flow_data['booking_mode'] ?? '') !== 'fixed-room') {
        return 0;
    }

    return (int) ($flow_data['fixed_room_id'] ?? 0);
}

/**
 * Normalize booking selection context values.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function normalize_booking_selection_context(array $context): array
{
    if (\function_exists(__NAMESPACE__ . '\parse_booking_request_context')) {
        $parsed = parse_booking_request_context($context, false);

        return [
            'checkin' => isset($parsed['checkin']) ? (string) $parsed['checkin'] : '',
            'checkout' => isset($parsed['checkout']) ? (string) $parsed['checkout'] : '',
            'guests' => isset($parsed['guests']) ? (int) $parsed['guests'] : 1,
            'room_count' => isset($parsed['room_count']) ? (int) $parsed['room_count'] : 0,
            'accommodation_type' => isset($parsed['accommodation_type']) ? (string) $parsed['accommodation_type'] : 'standard-rooms',
        ];
    }

    return [
        'checkin' => isset($context['checkin']) ? \sanitize_text_field((string) $context['checkin']) : '',
        'checkout' => isset($context['checkout']) ? \sanitize_text_field((string) $context['checkout']) : '',
        'guests' => isset($context['guests']) ? \max(1, \absint(\wp_unslash($context['guests']))) : 1,
        'room_count' => \function_exists(__NAMESPACE__ . '\normalize_booking_room_count')
            ? normalize_booking_room_count($context['room_count'] ?? 0)
            : 0,
        'accommodation_type' => isset($context['accommodation_type']) ? \sanitize_key((string) $context['accommodation_type']) : 'standard-rooms',
    ];
}

/**
 * Normalize selected room rows.
 *
 * @param mixed $selected_rooms
 * @return array<int, array<string, mixed>>
 */
function normalize_booking_selection_rooms($selected_rooms): array
{
    if (!\is_array($selected_rooms)) {
        return [];
    }

    $normalized = [];

    foreach ($selected_rooms as $selected_room) {
        if (!\is_array($selected_room)) {
            continue;
        }

        $room_id = isset($selected_room['room_id']) ? (int) $selected_room['room_id'] : 0;

        if ($room_id <= 0) {
            continue;
        }

        $normalized[$room_id] = [
            'room_id' => $room_id,
            'rate_plan_id' => isset($selected_room['rate_plan_id']) ? \max(0, (int) $selected_room['rate_plan_id']) : 0,
            'added_at' => isset($selected_room['added_at']) ? (string) $selected_room['added_at'] : '',
        ];
    }

    return \array_values($normalized);
}

/**
 * Get selected room rows for the current visitor.
 *
 * @return array<int, array<string, mixed>>
 */
function get_booking_selected_rooms(): array
{
    $selection = get_booking_selection();

    return normalize_booking_selection_rooms($selection['selected_rooms'] ?? []);
}

/**
 * @return array<int, int>
 */
function get_booking_selected_room_rate_plan_map(): array
{
    $map = [];

    foreach (get_booking_selected_rooms() as $selected_room) {
        $room_id = isset($selected_room['room_id']) ? (int) $selected_room['room_id'] : 0;

        if ($room_id <= 0) {
            continue;
        }

        $map[$room_id] = isset($selected_room['rate_plan_id']) ? \max(0, (int) $selected_room['rate_plan_id']) : 0;
    }

    return $map;
}

function get_booking_selected_room_rate_plan_id(int $room_id): int
{
    $room_id = \max(0, $room_id);
    $map = get_booking_selected_room_rate_plan_map();

    return isset($map[$room_id]) ? (int) $map[$room_id] : 0;
}

/**
 * Get the current booking selection.
 *
 * @return array<string, mixed>
 */
function get_booking_selection(): array
{
    $selection = \get_transient(get_booking_selection_transient_key());
    $defaults = get_empty_booking_selection();

    if (!\is_array($selection)) {
        return $defaults;
    }

    return [
        'context' => normalize_booking_selection_context(
            isset($selection['context']) && \is_array($selection['context'])
                ? $selection['context']
                : []
        ),
        'selected_rooms' => normalize_booking_selection_rooms($selection['selected_rooms'] ?? []),
        'flow_data' => normalize_booking_selection_flow_data($selection['flow_data'] ?? []),
    ];
}

/**
 * Persist a booking selection for the current visitor.
 *
 * @param array<string, mixed> $selection
 */
function save_booking_selection(array $selection): void
{
    $normalized = [
        'context' => normalize_booking_selection_context(
            isset($selection['context']) && \is_array($selection['context'])
                ? $selection['context']
                : []
        ),
        'selected_rooms' => normalize_booking_selection_rooms($selection['selected_rooms'] ?? []),
        'flow_data' => normalize_booking_selection_flow_data($selection['flow_data'] ?? []),
    ];

    \set_transient(
        get_booking_selection_transient_key(),
        $normalized,
        \defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400
    );
}

/**
 * Compare two booking selection contexts.
 *
 * @param array<string, mixed> $first
 * @param array<string, mixed> $second
 */
function do_booking_selection_contexts_match(array $first, array $second): bool
{
    $left = normalize_booking_selection_context($first);
    $right = normalize_booking_selection_context($second);

    return
        (string) $left['checkin'] === (string) $right['checkin'] &&
        (string) $left['checkout'] === (string) $right['checkout'] &&
        (int) $left['guests'] === (int) $right['guests'] &&
        (int) $left['room_count'] === (int) $right['room_count'] &&
        (string) $left['accommodation_type'] === (string) $right['accommodation_type'];
}

/**
 * Release all room locks stored in the provided selection.
 *
 * @param array<string, mixed> $selection
 */
function release_booking_selection_locks(array $selection): void
{
    $context = normalize_booking_selection_context(
        isset($selection['context']) && \is_array($selection['context'])
            ? $selection['context']
            : []
    );
    $checkin = isset($context['checkin']) ? (string) $context['checkin'] : '';
    $checkout = isset($context['checkout']) ? (string) $context['checkout'] : '';

    if ($checkin === '' || $checkout === '') {
        return;
    }

    foreach (normalize_booking_selection_rooms($selection['selected_rooms'] ?? []) as $selected_room) {
        $room_id = isset($selected_room['room_id']) ? (int) $selected_room['room_id'] : 0;

        if ($room_id <= 0) {
            continue;
        }

        if (InventoryEngine::hasInventoryForRoomType($room_id)) {
            InventoryEngine::releaseLocksForRoomType($room_id, $checkin, $checkout);
            continue;
        }

        LockEngine::releaseExactLock($room_id, $checkin, $checkout);
    }
}

/**
 * Clear the current visitor booking selection.
 */
function clear_booking_selection(bool $release_locks = true): void
{
    if ($release_locks) {
        release_booking_selection_locks(get_booking_selection());
    }

    \delete_transient(get_booking_selection_transient_key());
}

/**
 * Set booking selection context and reset stored rooms when context changes.
 *
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function set_booking_selection_context(array $context): array
{
    $selection = get_booking_selection();
    $normalized_context = normalize_booking_selection_context($context);

    if (!do_booking_selection_contexts_match($selection['context'], $normalized_context)) {
        release_booking_selection_locks($selection);
        $selection['selected_rooms'] = [];
        $selection['flow_data'] = get_empty_booking_selection()['flow_data'];
    }

    $selection['context'] = $normalized_context;
    save_booking_selection($selection);

    return $selection;
}

/**
 * Get persisted booking flow data for the current visitor.
 *
 * @return array<string, mixed>
 */
function get_booking_selection_flow_data(): array
{
    $selection = get_booking_selection();

    return normalize_booking_selection_flow_data($selection['flow_data'] ?? []);
}

/**
 * Update persisted booking flow data for the current visitor.
 *
 * @param array<string, mixed> $flow_data
 * @return array<string, mixed>
 */
function update_booking_selection_flow_data(array $flow_data): array
{
    $selection = get_booking_selection();
    $current_flow_data = normalize_booking_selection_flow_data($selection['flow_data'] ?? []);

    $selection['flow_data'] = normalize_booking_selection_flow_data(
        \array_merge($current_flow_data, $flow_data)
    );

    save_booking_selection($selection);

    return $selection['flow_data'];
}

/**
 * Get selected room ids for the current visitor.
 *
 * @return array<int, int>
 */
function get_booking_selected_room_ids(): array
{
    $selection = get_booking_selection();
    $room_ids = [];

    foreach (normalize_booking_selection_rooms($selection['selected_rooms'] ?? []) as $selected_room) {
        $room_id = isset($selected_room['room_id']) ? (int) $selected_room['room_id'] : 0;

        if ($room_id > 0) {
            $room_ids[$room_id] = $room_id;
        }
    }

    return \array_values($room_ids);
}

/**
 * Check whether the current visitor has selected at least one room.
 */
function has_booking_selected_rooms(): bool
{
    return !empty(get_booking_selected_room_ids());
}

/**
 * Add a room to the current visitor booking selection.
 *
 * @param array<string, mixed> $context
 */
function add_room_to_booking_selection(int $room_id, array $context, int $rate_plan_id = 0): bool
{
    if ($room_id <= 0) {
        return false;
    }

    $selection = set_booking_selection_context($context);
    $selected_rooms = normalize_booking_selection_rooms($selection['selected_rooms'] ?? []);
    $rate_plan_id = \max(0, $rate_plan_id);

    foreach ($selected_rooms as $index => $selected_room) {
        if ((int) ($selected_room['room_id'] ?? 0) === $room_id) {
            $selected_rooms[$index]['rate_plan_id'] = $rate_plan_id;
            $selection['selected_rooms'] = $selected_rooms;
            save_booking_selection($selection);

            return true;
        }
    }

    $selection['selected_rooms'][] = [
        'room_id' => $room_id,
        'rate_plan_id' => $rate_plan_id,
        'added_at' => \current_time('mysql'),
    ];

    save_booking_selection($selection);

    return true;
}

/**
 * Remove a room from the current visitor booking selection.
 */
function remove_room_from_booking_selection(int $room_id): bool
{
    if ($room_id <= 0) {
        return false;
    }

    $selection = get_booking_selection();
    $selected_rooms = normalize_booking_selection_rooms($selection['selected_rooms'] ?? []);
    $updated_rooms = [];
    $removed = false;
    $context = normalize_booking_selection_context($selection['context'] ?? []);

    foreach ($selected_rooms as $selected_room) {
        $selected_room_id = isset($selected_room['room_id']) ? (int) $selected_room['room_id'] : 0;

        if ($selected_room_id === $room_id) {
            $removed = true;
            continue;
        }

        $updated_rooms[] = $selected_room;
    }

    if (!$removed) {
        return false;
    }

    $selection['selected_rooms'] = $updated_rooms;
    save_booking_selection($selection);

    if (
        isset($context['checkin'], $context['checkout']) &&
        (string) $context['checkin'] !== '' &&
        (string) $context['checkout'] !== ''
    ) {
        if (InventoryEngine::hasInventoryForRoomType($room_id)) {
            InventoryEngine::releaseLocksForRoomType($room_id, (string) $context['checkin'], (string) $context['checkout']);
        } else {
            LockEngine::releaseExactLock($room_id, (string) $context['checkin'], (string) $context['checkout']);
        }
    }

    return true;
}

/**
 * Get enriched selected room data for the current visitor.
 *
 * @return array<int, array<string, mixed>>
 */
function get_booking_selection_room_view_items(): array
{
    $selection = get_booking_selection();
    $context = normalize_booking_selection_context($selection['context'] ?? []);
    $items = [];

    foreach (normalize_booking_selection_rooms($selection['selected_rooms'] ?? []) as $selected_room) {
        $room_id = isset($selected_room['room_id']) ? (int) $selected_room['room_id'] : 0;
        $rate_plan_id = isset($selected_room['rate_plan_id']) ? (int) $selected_room['rate_plan_id'] : 0;

        if ($room_id <= 0 || !\function_exists(__NAMESPACE__ . '\get_room_record')) {
            continue;
        }

        $room = get_room_record($room_id);

        if (!\is_array($room)) {
            continue;
        }

        if (
            isset($context['checkin'], $context['checkout']) &&
            (string) $context['checkin'] !== '' &&
            (string) $context['checkout'] !== ''
        ) {
            $rate_plan = \MustHotelBooking\Engine\RatePlanEngine::getRoomRatePlan($room_id, $rate_plan_id);

            if (\is_array($rate_plan)) {
                $room['selected_rate_plan_id'] = $rate_plan_id;
                $room['selected_rate_plan_name'] = isset($rate_plan['name']) ? (string) $rate_plan['name'] : '';
                $room['selected_rate_plan_description'] = isset($rate_plan['description']) ? (string) $rate_plan['description'] : '';
                $room['selected_rate_plan_max_occupancy'] = isset($rate_plan['max_occupancy']) ? (int) $rate_plan['max_occupancy'] : 0;
                $room['effective_max_guests'] = isset($rate_plan['max_occupancy'])
                    ? \max(1, \min((int) ($room['max_guests'] ?? 1), (int) $rate_plan['max_occupancy']))
                    : \max(1, (int) ($room['max_guests'] ?? 1));
            }

            $pricing = PricingEngine::calculateTotal(
                $room_id,
                (string) $context['checkin'],
                (string) $context['checkout'],
                (int) ($context['guests'] ?? 1),
                '',
                $rate_plan_id
            );

            if (\is_array($pricing) && !empty($pricing['success'])) {
                if (isset($pricing['total_price'])) {
                    $room['dynamic_total_price'] = (float) $pricing['total_price'];
                    $room['price_preview_total'] = (float) $pricing['total_price'];
                }

                if (isset($pricing['room_subtotal'])) {
                    $room['dynamic_room_subtotal'] = (float) $pricing['room_subtotal'];
                }

                if (isset($pricing['nights'])) {
                    $room['dynamic_nights'] = (int) $pricing['nights'];
                }
            }
        }

        $room_view = \function_exists(__NAMESPACE__ . '\get_booking_results_room_view_data')
            ? get_booking_results_room_view_data($room)
            : $room;

        if (!\is_array($room_view)) {
            continue;
        }

        $items[] = [
            'room_id' => $room_id,
            'rate_plan_id' => $rate_plan_id,
            'room' => $room_view,
        ];
    }

    return $items;
}
