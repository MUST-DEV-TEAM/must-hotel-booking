<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Engine\PricingEngine;

/**
 * @param array<string, scalar|array<int, string>> $args
 */
function get_admin_calendar_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-calendar');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_calendar_rooms(): array
{
    return \MustHotelBooking\Engine\get_room_repository()->getRoomSelectorRows();
}

/**
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
 * @return array{first_name: string, last_name: string}
 */
function split_calendar_guest_name(string $guestName): array
{
    $normalized = \trim((string) \preg_replace('/\s+/', ' ', $guestName));

    if ($normalized === '') {
        return [
            'first_name' => '',
            'last_name' => '',
        ];
    }

    $parts = \explode(' ', $normalized);

    return [
        'first_name' => (string) \array_shift($parts),
        'last_name' => \trim(\implode(' ', $parts)),
    ];
}

function save_calendar_guest_record(int $guestId, string $guestName, string $email, string $phone): int
{
    $parts = split_calendar_guest_name($guestName);

    return \MustHotelBooking\Engine\get_guest_repository()->saveGuestProfile(
        $guestId,
        (string) $parts['first_name'],
        (string) $parts['last_name'],
        $email,
        $phone
    );
}

function calculate_calendar_reservation_total_price(int $roomId, string $checkin, string $checkout, int $guests): float
{
    $pricing = PricingEngine::calculateTotal($roomId, $checkin, $checkout, $guests);

    if (\is_array($pricing) && !empty($pricing['success']) && isset($pricing['total_price'])) {
        return (float) $pricing['total_price'];
    }

    return 0.0;
}

function does_calendar_room_exist(int $roomId): bool
{
    return $roomId > 0 && \is_array(\MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId));
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function get_calendar_state_redirect_args(array $overrides = []): array
{
    /** @var array<string, mixed> $request */
    $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
    $query = CalendarViewQuery::fromRequest($request);

    return \array_merge($query->buildUrlArgs(), $overrides);
}

/**
 * @return array<string, mixed>
 */
function maybe_handle_calendar_block_submission(): array
{
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod !== 'POST') {
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

    $roomId = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
    $errors = [];

    if (!does_calendar_room_exist($roomId)) {
        $errors[] = \__('Please select a valid accommodation.', 'must-hotel-booking');
    }

    if (!AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout)) {
        $errors[] = \__('Please provide valid dates.', 'must-hotel-booking');
    } elseif ($checkin >= $checkout) {
        $errors[] = \__('The block end date must be after the start date.', 'must-hotel-booking');
    }

    if (empty($errors) && !AvailabilityEngine::checkAvailability($roomId, $checkin, $checkout)) {
        $errors[] = \__('This accommodation is already unavailable for the selected dates.', 'must-hotel-booking');
    }

    $form = [
        'room_id' => $roomId,
        'checkin' => $checkin,
        'checkout' => $checkout,
    ];

    if (!empty($errors)) {
        return [
            'errors' => $errors,
            'form' => $form,
        ];
    }

    $reservationId = \MustHotelBooking\Engine\get_reservation_repository()->createBlockedReservation(
        $roomId,
        $checkin,
        $checkout,
        \current_time('mysql')
    );

    if ($reservationId <= 0) {
        return [
            'errors' => [\__('Unable to create the manual block right now.', 'must-hotel-booking')],
            'form' => $form,
        ];
    }

    \wp_safe_redirect(
        get_admin_calendar_page_url(
            get_calendar_state_redirect_args(
                [
                    'notice' => 'block_created',
                    'focus_room_id' => $roomId,
                    'focus_date' => $checkin,
                    'reservation_id' => $reservationId,
                ]
            )
        )
    );
    exit;
}

function maybe_handle_calendar_delete_block_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_block') {
        return;
    }

    $reservationId = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_calendar_delete_block_' . $reservationId)) {
        \wp_safe_redirect(get_admin_calendar_page_url(get_calendar_state_redirect_args(['notice' => 'invalid_nonce'])));
        exit;
    }

    $deleted = \MustHotelBooking\Engine\get_reservation_repository()->deleteReservation($reservationId, 'blocked');

    \wp_safe_redirect(
        get_admin_calendar_page_url(
            get_calendar_state_redirect_args(
                [
                    'notice' => $deleted ? 'block_deleted' : 'block_delete_failed',
                    'reservation_id' => 0,
                ]
            )
        )
    );
    exit;
}

function render_calendar_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'block_created' => ['success', \__('Manual block created successfully.', 'must-hotel-booking')],
        'block_deleted' => ['success', \__('Manual block removed successfully.', 'must-hotel-booking')],
        'reservation_created' => ['success', \__('Reservation created successfully.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
        'block_delete_failed' => ['error', \__('Unable to remove the selected block.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    echo '<div class="notice notice-' . \esc_attr($type === 'success' ? 'success' : 'error') . '"><p>' . \esc_html($message) . '</p></div>';
}

function render_calendar_status_badge(string $status): string
{
    $status = \sanitize_key($status);

    return '<span class="must-calendar-status-badge is-' . \esc_attr($status !== '' ? $status : 'info') . '">' . \esc_html(\ucfirst(\str_replace('_', ' ', $status !== '' ? $status : 'info'))) . '</span>';
}

/**
 * @param array<string, mixed> $summary
 */
function render_calendar_summary_cards(array $summary): void
{
    echo '<div class="must-calendar-summary-grid">';
    echo '<article class="must-calendar-summary-card"><span class="must-calendar-summary-label">' . \esc_html__('Accommodations Shown', 'must-hotel-booking') . '</span><strong class="must-calendar-summary-value">' . \esc_html((string) ($summary['accommodations_shown'] ?? 0)) . '</strong><span class="must-calendar-summary-meta">' . \esc_html(\sprintf(__('%d units in scope', 'must-hotel-booking'), (int) ($summary['units_shown'] ?? 0))) . '</span></article>';
    echo '<article class="must-calendar-summary-card"><span class="must-calendar-summary-label">' . \esc_html__('Booked Today', 'must-hotel-booking') . '</span><strong class="must-calendar-summary-value">' . \esc_html((string) ($summary['booked_today'] ?? 0)) . '</strong><span class="must-calendar-summary-meta">' . \esc_html__('Confirmed or in-house occupancy.', 'must-hotel-booking') . '</span></article>';
    echo '<article class="must-calendar-summary-card"><span class="must-calendar-summary-label">' . \esc_html__('Available Today', 'must-hotel-booking') . '</span><strong class="must-calendar-summary-value">' . \esc_html((string) ($summary['available_today'] ?? 0)) . '</strong><span class="must-calendar-summary-meta">' . \esc_html__('Units still open for staff booking.', 'must-hotel-booking') . '</span></article>';
    echo '<article class="must-calendar-summary-card"><span class="must-calendar-summary-label">' . \esc_html__('Blocked Today', 'must-hotel-booking') . '</span><strong class="must-calendar-summary-value">' . \esc_html((string) ($summary['blocked_today'] ?? 0)) . '</strong><span class="must-calendar-summary-meta">' . \esc_html(\sprintf(__('Pending %1$d, holds %2$d', 'must-hotel-booking'), (int) ($summary['pending_today'] ?? 0), (int) ($summary['holds_today'] ?? 0))) . '</span></article>';
    echo '<article class="must-calendar-summary-card"><span class="must-calendar-summary-label">' . \esc_html__('Occupancy Today', 'must-hotel-booking') . '</span><strong class="must-calendar-summary-value">' . \esc_html((string) ($summary['occupancy_today'] ?? 0)) . '%</strong><span class="must-calendar-summary-meta">' . \esc_html__('Based on booked units currently shown.', 'must-hotel-booking') . '</span></article>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_calendar_filters(CalendarViewQuery $query, array $pageData): void
{
    echo '<div class="must-calendar-toolbar-panel">';
    echo '<div class="must-calendar-toolbar-top">';
    echo '<div>';
    echo '<span class="must-calendar-eyebrow">' . \esc_html__('Operations Calendar', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Calendar', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Choose the board scope directly inside the calendar and keep the rest of the page focused on occupancy, arrivals, departures, and fast actions.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-calendar-toolbar-actions">';
    echo '<a class="button must-calendar-header-link" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-calendar-header-link" href="' . \esc_url(get_admin_availability_rules_page_url()) . '">' . \esc_html__('Availability Rules', 'must-hotel-booking') . '</a>';
    echo '<a class="button button-primary" href="' . \esc_url(get_admin_reservation_create_page_url()) . '">' . \esc_html__('Add Reservation', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_calendar_board_controls(CalendarViewQuery $query, array $pageData): void
{
    $filters = isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [];
    $range = isset($pageData['range']) && \is_array($pageData['range']) ? $pageData['range'] : [];
    $today = \current_time('Y-m-d');
    $currentRoomLabel = '';
    $currentCategoryLabel = '';

    foreach ((array) ($filters['room_options'] ?? []) as $roomOption) {
        if (!\is_array($roomOption)) {
            continue;
        }

        if ((int) ($roomOption['id'] ?? 0) !== (int) ($filters['room_id'] ?? 0)) {
            continue;
        }

        $currentRoomLabel = (string) ($roomOption['name'] ?? '');
        break;
    }

    if (!empty($filters['category']) && isset($filters['category_options'][(string) $filters['category']])) {
        $currentCategoryLabel = (string) $filters['category_options'][(string) $filters['category']];
    }

    $presetRanges = [
        [
            'label' => \__('This Week', 'must-hotel-booking'),
            'args' => $query->buildUrlArgs(
                [
                    'start_date' => $today,
                    'weeks' => 1,
                    'focus_room_id' => 0,
                    'focus_date' => '',
                    'reservation_id' => 0,
                ]
            ),
        ],
        [
            'label' => \__('2 Weeks', 'must-hotel-booking'),
            'args' => $query->buildUrlArgs(
                [
                    'start_date' => $today,
                    'weeks' => 2,
                    'focus_room_id' => 0,
                    'focus_date' => '',
                    'reservation_id' => 0,
                ]
            ),
        ],
        [
            'label' => \__('4 Weeks', 'must-hotel-booking'),
            'args' => $query->buildUrlArgs(
                [
                    'start_date' => $today,
                    'weeks' => 4,
                    'focus_room_id' => 0,
                    'focus_date' => '',
                    'reservation_id' => 0,
                ]
            ),
        ],
    ];
    $resetArgs = $query->buildUrlArgs(
        [
            'start_date' => $today,
            'weeks' => 2,
            'room_id' => 0,
            'accommodation_type' => \MustHotelBooking\Core\RoomCatalog::BOOKING_ALL_CATEGORY,
            'focus_room_id' => 0,
            'focus_date' => '',
            'reservation_id' => 0,
            'visibility' => CalendarViewQuery::getDefaultVisibility(),
        ]
    );

    echo '<div class="must-calendar-board-controls">';
    echo '<div class="must-calendar-grid-head">';
    echo '<div><h2>' . \esc_html__('Calendar Board', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Set the visible weeks, then narrow by accommodation category or a single room listing without leaving the board.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-calendar-grid-head-meta">';
    echo '<span class="must-calendar-active-filter is-strong">' . \esc_html((string) ($range['label'] ?? '')) . '</span>';
    echo '<span class="must-calendar-active-filter">' . \esc_html(\sprintf(_n('%d week', '%d weeks', (int) ($filters['weeks'] ?? 2), 'must-hotel-booking'), (int) ($filters['weeks'] ?? 2))) . '</span>';
    echo '<span class="must-calendar-active-filter">' . \esc_html(\sprintf(_n('%d date', '%d dates', (int) ($range['days'] ?? 0), 'must-hotel-booking'), (int) ($range['days'] ?? 0))) . '</span>';
    echo '</div></div>';

    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '" class="must-calendar-board-filter-form">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-calendar" />';
    echo '<div class="must-calendar-board-navigation">';
    echo '<a class="button" href="' . \esc_url(get_admin_calendar_page_url($query->buildUrlArgs(\array_merge((array) ($range['previous_args'] ?? []), ['focus_room_id' => 0, 'focus_date' => '', 'reservation_id' => 0])))) . '">' . \esc_html__('Previous Window', 'must-hotel-booking') . '</a>';
    echo '<a class="button" href="' . \esc_url(get_admin_calendar_page_url($query->buildUrlArgs(\array_merge((array) ($range['next_args'] ?? []), ['focus_room_id' => 0, 'focus_date' => '', 'reservation_id' => 0])))) . '">' . \esc_html__('Next Window', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-calendar-header-link" href="' . \esc_url(get_admin_calendar_page_url($query->buildUrlArgs(['start_date' => $today, 'weeks' => 2, 'focus_room_id' => 0, 'focus_date' => '', 'reservation_id' => 0]))) . '">' . \esc_html__('Jump to Today', 'must-hotel-booking') . '</a>';
    echo '</div>';

    echo '<div class="must-calendar-board-filter-grid">';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Start Date', 'must-hotel-booking') . '</span><input type="date" name="start_date" value="' . \esc_attr((string) ($filters['start_date'] ?? '')) . '" /></label>';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Weeks Visible', 'must-hotel-booking') . '</span><select name="weeks">';

    foreach ((array) ($filters['week_options'] ?? []) as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((int) ($filters['weeks'] ?? 2), (int) $value, false) . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select></label>';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Accommodation Category', 'must-hotel-booking') . '</span><select name="accommodation_type">';

    foreach ((array) ($filters['category_options'] ?? []) as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['category'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select></label>';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</span><select name="room_id"><option value="">' . \esc_html__('All accommodations', 'must-hotel-booking') . '</option>';

    foreach ((array) ($filters['room_options'] ?? []) as $roomOption) {
        if (!\is_array($roomOption)) {
            continue;
        }

        $roomId = isset($roomOption['id']) ? (int) $roomOption['id'] : 0;
        $label = (string) ($roomOption['name'] ?? '');

        if ((string) ($roomOption['category_label'] ?? '') !== '') {
            $label .= ' / ' . (string) $roomOption['category_label'];
        }

        echo '<option value="' . \esc_attr((string) $roomId) . '"' . \selected((int) ($filters['room_id'] ?? 0), $roomId, false) . '>' . \esc_html($label) . '</option>';
    }

    echo '</select></label>';
    echo '</div>';

    echo '<div class="must-calendar-range-presets"><span>' . \esc_html__('Quick Range', 'must-hotel-booking') . '</span><div class="must-calendar-range-presets-list">';

    foreach ($presetRanges as $presetRange) {
        if (!\is_array($presetRange)) {
            continue;
        }

        echo '<a class="must-calendar-preset-chip" href="' . \esc_url(get_admin_calendar_page_url((array) ($presetRange['args'] ?? []))) . '">' . \esc_html((string) ($presetRange['label'] ?? '')) . '</a>';
    }

    echo '</div></div>';
    echo '<div class="must-calendar-visibility-group"><span>' . \esc_html__('Visibility', 'must-hotel-booking') . '</span><div class="must-calendar-visibility-options">';

    foreach ((array) ($filters['visibility_options'] ?? []) as $value => $label) {
        $checked = \in_array((string) $value, (array) ($filters['visibility'] ?? []), true) ? ' checked' : '';
        echo '<label><input type="checkbox" name="visibility[]" value="' . \esc_attr((string) $value) . '"' . $checked . ' /> <span>' . \esc_html((string) $label) . '</span></label>';
    }

    echo '</div></div>';
    echo '<div class="must-calendar-filter-actions"><button type="submit" class="button button-primary">' . \esc_html__('Update Board', 'must-hotel-booking') . '</button><a class="button" href="' . \esc_url(get_admin_calendar_page_url($resetArgs)) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a></div>';
    echo '<div class="must-calendar-active-filters">';
    echo '<span class="must-calendar-active-filter is-strong">' . \esc_html((string) ($range['label'] ?? '')) . '</span>';
    echo '<span class="must-calendar-active-filter">' . \esc_html__('Ends', 'must-hotel-booking') . ': ' . \esc_html(\wp_date(\get_option('date_format'), \strtotime((string) ($filters['end_date'] ?? '')))) . '</span>';

    if ($currentCategoryLabel !== '' && !\MustHotelBooking\Core\RoomCatalog::isBookingAllCategory((string) ($filters['category'] ?? ''))) {
        echo '<span class="must-calendar-active-filter">' . \esc_html__('Category', 'must-hotel-booking') . ': ' . \esc_html($currentCategoryLabel) . '</span>';
    }

    if ((int) ($filters['room_id'] ?? 0) > 0 && $currentRoomLabel !== '') {
        echo '<span class="must-calendar-active-filter">' . \esc_html__('Room', 'must-hotel-booking') . ': ' . \esc_html($currentRoomLabel) . '</span>';
    }

    echo '</div>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, array<string, string>> $legend
 */
function render_calendar_legend(array $legend): void
{
    echo '<div class="must-calendar-legend">';

    foreach ($legend as $item) {
        if (!\is_array($item)) {
            continue;
        }

        echo '<div class="must-calendar-legend-item">';
        echo '<span class="must-calendar-legend-swatch is-' . \esc_attr((string) ($item['state'] ?? 'available')) . '"></span>';
        echo '<div><strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['description'] ?? '')) . '</span></div>';
        echo '</div>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_calendar_grid(CalendarViewQuery $query, array $pageData): void
{
    $rows = isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : [];
    $dates = isset($pageData['range']['dates']) && \is_array($pageData['range']['dates']) ? $pageData['range']['dates'] : [];
    $range = isset($pageData['range']) && \is_array($pageData['range']) ? $pageData['range'] : [];
    $roomCount = \count($rows);
    $dayCount = \count($dates);

    echo '<div class="must-calendar-grid-card">';
    render_calendar_board_controls($query, $pageData);

    if ($roomCount > 10 || $dayCount > 21) {
        echo '<p class="must-calendar-grid-hint">' . \esc_html__('This board is still broad. Narrow the date window or choose a specific room to stay focused on the operations you need to handle.', 'must-hotel-booking') . '</p>';
    }

    echo '<div class="must-calendar-grid-wrap">';
    echo '<table class="must-calendar-grid"><thead><tr><th class="must-calendar-room-col">' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';

    foreach ($dates as $date) {
        $isToday = (string) $date === \current_time('Y-m-d');
        echo '<th class="must-calendar-date-col' . ($isToday ? ' is-today' : '') . '"><span>' . \esc_html(\wp_date('D', \strtotime((string) $date))) . '</span><strong>' . \esc_html(\wp_date('j', \strtotime((string) $date))) . '</strong></th>';
    }

    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="' . \esc_attr((string) (\count($dates) + 1)) . '" class="must-calendar-empty-grid">' . \esc_html__('No accommodations matched the current calendar filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $inventory = isset($row['inventory']) && \is_array($row['inventory']) ? $row['inventory'] : ['total_units' => 1];
            echo '<tr><th scope="row" class="must-calendar-room-col"><div class="must-calendar-room-name">' . \esc_html((string) ($row['name'] ?? '')) . '</div><div class="must-calendar-room-meta">' . \esc_html((string) ($row['category_label'] ?? '')) . ' / ' . \esc_html(\sprintf(__('%d guests', 'must-hotel-booking'), (int) ($row['max_guests'] ?? 1))) . ' / ' . \esc_html(\sprintf(__('%d units', 'must-hotel-booking'), (int) ($inventory['total_units'] ?? 1))) . '</div></th>';

            foreach ((array) ($row['cells'] ?? []) as $cell) {
                if (!\is_array($cell)) {
                    continue;
                }

                $cellUrl = get_admin_calendar_page_url(
                    $query->buildUrlArgs(
                        [
                            'focus_room_id' => $roomId,
                            'focus_date' => (string) ($cell['date'] ?? ''),
                            'reservation_id' => 0,
                        ]
                    )
                );
                $classes = ['must-calendar-cell', 'is-' . (string) ($cell['state'] ?? 'available')];

                if (!empty($cell['today'])) {
                    $classes[] = 'is-today';
                }

                if (!empty($cell['selected'])) {
                    $classes[] = 'is-selected';
                }

                echo '<td class="' . \esc_attr(\implode(' ', $classes)) . '"><a class="must-calendar-cell-link" href="' . \esc_url($cellUrl) . '"><span class="must-calendar-cell-headline">' . \esc_html((string) ($cell['headline'] ?? '')) . '</span>';

                if (!empty($cell['indicators'])) {
                    echo '<span class="must-calendar-cell-indicators">';

                    foreach ((array) $cell['indicators'] as $indicator) {
                        echo '<span>' . \esc_html((string) $indicator) . '</span>';
                    }

                    echo '</span>';
                }

                echo '<span class="must-calendar-cell-flags">';

                if ((int) ($cell['arrivals_count'] ?? 0) > 0) {
                    echo '<span>' . \esc_html(\sprintf(__('A %d', 'must-hotel-booking'), (int) ($cell['arrivals_count'] ?? 0))) . '</span>';
                }

                if ((int) ($cell['departures_count'] ?? 0) > 0) {
                    echo '<span>' . \esc_html(\sprintf(__('D %d', 'must-hotel-booking'), (int) ($cell['departures_count'] ?? 0))) . '</span>';
                }

                echo '</span></a></td>';
            }

            echo '</tr>';
        }
    }

    echo '</tbody></table></div></div>';
}

/**
 * @param array<int, array<string, mixed>> $rooms
 * @param array<string, mixed>|null $selected
 * @param array<string, mixed>|null $quickBookingForm
 * @param array<int, string> $quickBookingErrors
 * @param array<string, mixed>|null $blockForm
 * @param array<int, string> $blockErrors
 */
function render_calendar_sidebar(
    CalendarViewQuery $query,
    array $rooms,
    ?array $selected,
    ?array $quickBookingForm,
    array $quickBookingErrors,
    ?array $blockForm,
    array $blockErrors
): void {
    $selected = \is_array($selected) ? $selected : [];
    $selectedActions = isset($selected['actions']) && \is_array($selected['actions']) ? $selected['actions'] : [];
    $selectedSummary = isset($selected['summary']) && \is_array($selected['summary']) ? $selected['summary'] : [];
    $quickBookingDefaults = !empty($quickBookingForm)
        ? $quickBookingForm
        : (isset($selectedActions['quick_booking_form']) && \is_array($selectedActions['quick_booking_form']) ? $selectedActions['quick_booking_form'] : null);
    $blockDefaults = !empty($blockForm)
        ? $blockForm
        : (isset($selectedActions['block_form']) && \is_array($selectedActions['block_form']) ? $selectedActions['block_form'] : null);
    $returnUrl = get_admin_calendar_page_url($query->buildUrlArgs());

    if (!\is_array($quickBookingDefaults)) {
        $defaultCheckin = $query->getStartDate() !== '' ? $query->getStartDate() : \current_time('Y-m-d');
        $quickBookingDefaults = [
            'room_id' => $query->getRoomId(),
            'checkin' => $defaultCheckin,
            'checkout' => (new \DateTimeImmutable($defaultCheckin))->modify('+1 day')->format('Y-m-d'),
        ];
    }

    if (!\is_array($blockDefaults)) {
        $defaultBlockStart = $query->getStartDate() !== '' ? $query->getStartDate() : \current_time('Y-m-d');
        $blockDefaults = [
            'room_id' => $query->getRoomId(),
            'checkin' => $defaultBlockStart,
            'checkout' => (new \DateTimeImmutable($defaultBlockStart))->modify('+1 day')->format('Y-m-d'),
        ];
    }

    echo '<section class="must-calendar-workspace">';
    echo '<div class="must-calendar-workspace-top">';
    echo '<div class="must-calendar-side-card">';
    echo '<h2>' . \esc_html__('Selected Day', 'must-hotel-booking') . '</h2>';

    if (empty($selected['room']) || empty($selected['date'])) {
        echo '<p class="must-calendar-empty-state">' . \esc_html__('Choose a calendar cell to inspect arrivals, departures, blocks, rules, and quick actions for that accommodation and date.', 'must-hotel-booking') . '</p>';
    } else {
        $room = isset($selected['room']) && \is_array($selected['room']) ? $selected['room'] : [];
        echo '<p class="must-calendar-selection-title">' . \esc_html((string) ($room['name'] ?? '')) . '</p>';
        echo '<p class="must-calendar-selection-meta">' . \esc_html((string) ($selected['label'] ?? '')) . '</p>';
        echo '<div class="must-calendar-selection-badges">' . render_calendar_status_badge((string) ($selectedSummary['actual_state'] ?? $selectedSummary['state'] ?? 'available')) . '</div>';

        if (!empty($selectedSummary['hidden_state'])) {
            echo '<p class="must-calendar-selection-meta">' . \esc_html__('This cell is muted by the current visibility filter, but the underlying availability is still active.', 'must-hotel-booking') . '</p>';
        }

        echo '<div class="must-calendar-selection-actions">';

        if (!empty($selectedActions['room_url'])) {
            echo '<a class="button" href="' . \esc_url((string) $selectedActions['room_url']) . '">' . \esc_html__('Open Accommodation', 'must-hotel-booking') . '</a>';
        }

        if (!empty($selectedActions['reservations_url'])) {
            echo '<a class="button" href="' . \esc_url((string) $selectedActions['reservations_url']) . '">' . \esc_html__('Open Reservation List', 'must-hotel-booking') . '</a>';
        }

        if (!empty($selectedActions['availability_rules_url'])) {
            echo '<a class="button" href="' . \esc_url((string) $selectedActions['availability_rules_url']) . '">' . \esc_html__('Inspect Rules', 'must-hotel-booking') . '</a>';
        }

        if (!empty($selectedActions['can_create'])) {
            echo '<a class="button button-primary" href="#must-calendar-quick-booking">' . \esc_html__('Quick Book This Date', 'must-hotel-booking') . '</a>';
        }

        echo '<a class="button" href="#must-calendar-block-form">' . \esc_html__('Add Manual Block', 'must-hotel-booking') . '</a>';
        echo '</div>';

        foreach (['stays' => __('Stays', 'must-hotel-booking'), 'arrivals' => __('Arrivals', 'must-hotel-booking'), 'departures' => __('Departures', 'must-hotel-booking')] as $key => $label) {
            $items = isset($selected[$key]) && \is_array($selected[$key]) ? $selected[$key] : [];

            if (empty($items)) {
                continue;
            }

            echo '<div class="must-calendar-activity-group"><h3>' . \esc_html($label) . '</h3><div class="must-calendar-activity-list">';

            foreach ($items as $item) {
                if (!\is_array($item)) {
                    continue;
                }

                echo '<div class="must-calendar-activity-item"><div><strong>' . \esc_html((string) ($item['reference'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['guest'] ?? '')) . '</span></div><div class="must-calendar-activity-actions">' . render_calendar_status_badge((string) ($item['status'] ?? 'info'));

                if (!empty($item['remove_block_url'])) {
                    echo '<a class="button button-small button-link-delete" href="' . \esc_url((string) $item['remove_block_url']) . '">' . \esc_html__('Remove', 'must-hotel-booking') . '</a>';
                }

                if (!empty($item['view_url'])) {
                    echo '<a class="button button-small" href="' . \esc_url((string) $item['view_url']) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a>';
                }

                echo '</div></div>';
            }

            echo '</div></div>';
        }

        if (!empty($selected['rules']) || !empty($selected['locks'])) {
            echo '<div class="must-calendar-activity-group">';

            if (!empty($selected['rules'])) {
                echo '<h3>' . \esc_html__('Restrictions', 'must-hotel-booking') . '</h3><div class="must-calendar-activity-list">';

                foreach ((array) $selected['rules'] as $rule) {
                    if (!\is_array($rule)) {
                        continue;
                    }

                    echo '<div class="must-calendar-activity-item"><div><strong>' . \esc_html((string) ($rule['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($rule['range'] ?? '')) . '</span></div><div>' . \esc_html((string) ($rule['reason'] ?? '')) . '</div></div>';
                }

                echo '</div>';
            }

            if (!empty($selected['locks'])) {
                echo '<h3>' . \esc_html__('Temporary Holds', 'must-hotel-booking') . '</h3><div class="must-calendar-activity-list">';

                foreach ((array) $selected['locks'] as $lock) {
                    if (!\is_array($lock)) {
                        continue;
                    }

                    echo '<div class="must-calendar-activity-item"><div><strong>' . \esc_html((string) ($lock['room_number'] ?? __('Inventory room', 'must-hotel-booking'))) . '</strong><span>' . \esc_html((string) ($lock['expires_at'] ?? '')) . '</span></div></div>';
                }

                echo '</div>';
            }

            echo '</div>';
        }
    }

    echo '</div>';

    echo '<div id="must-calendar-block-form" class="must-calendar-side-card"><h2>' . \esc_html__('Manual Block', 'must-hotel-booking') . '</h2>';

    if (!empty($blockErrors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($blockErrors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<form method="post" action="' . \esc_url(get_admin_calendar_page_url($query->buildUrlArgs())) . '" class="must-calendar-block-form">';
    \wp_nonce_field('must_calendar_block_dates', 'must_calendar_nonce');
    echo '<input type="hidden" name="must_calendar_action" value="create_manual_block" />';
    echo '<label><span>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</span><select name="room_id" required><option value="">' . \esc_html__('Select accommodation', 'must-hotel-booking') . '</option>';

    foreach ($rooms as $room) {
        if (!\is_array($room)) {
            continue;
        }

        $roomId = isset($room['id']) ? (int) $room['id'] : 0;
        echo '<option value="' . \esc_attr((string) $roomId) . '"' . \selected((int) ($blockDefaults['room_id'] ?? 0), $roomId, false) . '>' . \esc_html((string) ($room['name'] ?? '')) . '</option>';
    }

    echo '</select></label>';
    echo '<label><span>' . \esc_html__('Start', 'must-hotel-booking') . '</span><input type="date" name="checkin" value="' . \esc_attr((string) ($blockDefaults['checkin'] ?? '')) . '" required /></label>';
    echo '<label><span>' . \esc_html__('End', 'must-hotel-booking') . '</span><input type="date" name="checkout" value="' . \esc_attr((string) ($blockDefaults['checkout'] ?? '')) . '" required /></label>';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Create Block', 'must-hotel-booking') . '</button>';
    echo '</form></div>';
    echo '</div>';

    if (\function_exists(__NAMESPACE__ . '\render_admin_quick_booking_panel')) {
        echo '<div id="must-calendar-quick-booking" class="must-calendar-quick-booking-wrap">';
        render_admin_quick_booking_panel(
            $quickBookingDefaults,
            $quickBookingErrors,
            [
                'action_url' => get_admin_calendar_page_url($query->buildUrlArgs()),
                'redirect_target' => 'calendar',
                'return_url' => $returnUrl,
                'eyebrow' => \__('Calendar Action', 'must-hotel-booking'),
                'title' => \__('Quick Book From Calendar', 'must-hotel-booking'),
                'description' => \__('Turn the selected room and dates into a confirmed reservation without leaving the calendar workspace.', 'must-hotel-booking'),
                'submit_label' => \__('Create From Calendar', 'must-hotel-booking'),
            ]
        );
        echo '</div>';
    }

    echo '</section>';
}

function render_admin_calendar_page(): void
{
    ensure_admin_capability();

    $quickBookingState = \function_exists(__NAMESPACE__ . '\maybe_handle_admin_quick_booking_submission')
        ? maybe_handle_admin_quick_booking_submission()
        : ['errors' => [], 'form' => null];
    $quickBookingErrors = isset($quickBookingState['errors']) && \is_array($quickBookingState['errors']) ? $quickBookingState['errors'] : [];
    $quickBookingForm = isset($quickBookingState['form']) && \is_array($quickBookingState['form']) ? $quickBookingState['form'] : null;
    $blockState = maybe_handle_calendar_block_submission();
    $blockErrors = isset($blockState['errors']) && \is_array($blockState['errors']) ? $blockState['errors'] : [];
    $blockForm = isset($blockState['form']) && \is_array($blockState['form']) ? $blockState['form'] : null;
    /** @var array<string, mixed> $request */
    $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
    $query = CalendarViewQuery::fromRequest($request);
    $pageData = (new CalendarDataProvider())->getPageData($query);
    $rooms = [];

    foreach ((array) ($pageData['rows'] ?? []) as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $rooms[] = [
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    echo '<div class="wrap must-calendar-page">';
    render_calendar_admin_notice_from_query();
    render_calendar_filters($query, $pageData);
    render_calendar_summary_cards(isset($pageData['summary']) && \is_array($pageData['summary']) ? $pageData['summary'] : []);
    render_calendar_legend(isset($pageData['legend']) && \is_array($pageData['legend']) ? $pageData['legend'] : []);
    render_calendar_grid($query, $pageData);
    render_calendar_sidebar(
        $query,
        \is_array($rooms) ? $rooms : [],
        isset($pageData['selected']) && \is_array($pageData['selected']) ? $pageData['selected'] : null,
        $quickBookingForm,
        $quickBookingErrors,
        $blockForm,
        $blockErrors
    );
    echo '</div>';
}

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
    maybe_handle_calendar_delete_block_request();
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_admin_calendar_actions_early', 1);

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
        [],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_calendar_assets');
