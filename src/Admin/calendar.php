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
    $labels = [
        'available' => \__('Available', 'must-hotel-booking'),
        'partial' => \__('Limited', 'must-hotel-booking'),
        'booked' => \__('Booked', 'must-hotel-booking'),
        'confirmed' => \__('Confirmed', 'must-hotel-booking'),
        'completed' => \__('Completed', 'must-hotel-booking'),
        'pending' => \__('Pending', 'must-hotel-booking'),
        'pending_payment' => \__('Pending Payment', 'must-hotel-booking'),
        'blocked' => \__('Blocked', 'must-hotel-booking'),
        'unavailable' => \__('Unavailable', 'must-hotel-booking'),
        'hold' => \__('Hold', 'must-hotel-booking'),
        'cancelled' => \__('Cancelled', 'must-hotel-booking'),
        'filtered' => \__('Hidden', 'must-hotel-booking'),
        'info' => \__('Info', 'must-hotel-booking'),
    ];
    $label = isset($labels[$status]) ? (string) $labels[$status] : \ucfirst(\str_replace('_', ' ', $status !== '' ? $status : 'info'));

    return '<span class="must-dashboard-status-badge must-calendar-status-badge is-' . \esc_attr($status !== '' ? $status : 'info') . '">' . \esc_html($label) . '</span>';
}

function get_calendar_sidebar_mode(array $quickBookingErrors, array $blockErrors): string
{
    if (!empty($quickBookingErrors)) {
        return 'reservation';
    }

    if (!empty($blockErrors)) {
        return 'block';
    }

    $mode = isset($_GET['panel']) ? \sanitize_key((string) \wp_unslash($_GET['panel'])) : 'details';
    $allowed = ['details', 'reservation', 'block'];

    return \in_array($mode, $allowed, true) ? $mode : 'details';
}

function get_calendar_mode_url(CalendarViewQuery $query, string $mode): string
{
    return get_admin_calendar_page_url(
        \array_merge(
            $query->buildUrlArgs(),
            ['panel' => \sanitize_key($mode)]
        )
    );
}

function get_calendar_cell_state_label(array $cell): string
{
    $state = \sanitize_key((string) ($cell['state'] ?? 'available'));
    $labels = [
        'available' => \__('Available', 'must-hotel-booking'),
        'partial' => \__('Limited', 'must-hotel-booking'),
        'booked' => \__('Booked', 'must-hotel-booking'),
        'pending' => \__('Pending', 'must-hotel-booking'),
        'blocked' => \__('Blocked', 'must-hotel-booking'),
        'unavailable' => \__('Unavailable', 'must-hotel-booking'),
        'hold' => \__('Hold', 'must-hotel-booking'),
        'filtered' => \__('Hidden', 'must-hotel-booking'),
    ];

    return isset($labels[$state]) ? (string) $labels[$state] : \ucfirst(\str_replace('_', ' ', $state));
}

/**
 * @param array<string, mixed> $pageData
 */
function render_calendar_page_header(CalendarViewQuery $query, array $pageData): void
{
    $range = isset($pageData['range']) && \is_array($pageData['range']) ? $pageData['range'] : [];
    $filters = isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [];
    $selected = isset($pageData['selected']) && \is_array($pageData['selected']) ? $pageData['selected'] : [];
    $selectedRoom = isset($selected['room']) && \is_array($selected['room']) ? $selected['room'] : [];
    $roomCount = isset($pageData['rows']) && \is_array($pageData['rows']) ? \count($pageData['rows']) : 0;
    $selectedLabel = !empty($selectedRoom) && !empty($selected['label'])
        ? (string) $selectedRoom['name'] . ' / ' . (string) $selected['label']
        : \__('No selection', 'must-hotel-booking');

    echo '<div class="must-dashboard-hero must-calendar-hero">';
    echo '<div class="must-dashboard-hero-copy">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Operations Calendar', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Calendar', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Monitor availability, move through the visible booking window, and act on the selected room and date from one operational workspace that matches the rest of the admin product.', 'must-hotel-booking') . '</p>';
    echo '<div class="must-dashboard-hero-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Window', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($range['label'] ?? '')) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Scope', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(\_n('%d accommodation', '%d accommodations', $roomCount, 'must-hotel-booking'), $roomCount)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Visible States', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(\_n('%d state', '%d states', \count((array) ($filters['visibility'] ?? [])), 'must-hotel-booking'), \count((array) ($filters['visibility'] ?? [])))) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Focus', 'must-hotel-booking') . '</strong> ' . \esc_html($selectedLabel) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-dashboard-hero-actions">';
    echo '<a class="button button-primary" href="' . \esc_url(get_admin_reservation_create_page_url()) . '">' . \esc_html__('Add Reservation', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_availability_rules_page_url()) . '">' . \esc_html__('Availability Rules', 'must-hotel-booking') . '</a>';

    if (\function_exists(__NAMESPACE__ . '\get_admin_dashboard_page_url')) {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_dashboard_page_url()) . '">' . \esc_html__('Open Dashboard', 'must-hotel-booking') . '</a>';
    }

    echo '</div>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $summary
 */
function render_calendar_summary_cards(array $summary): void
{
    $cards = [
        [
            'label' => \__('Accommodations Shown', 'must-hotel-booking'),
            'value' => (string) ($summary['accommodations_shown'] ?? 0),
            'descriptor' => \sprintf(__('%d units in scope', 'must-hotel-booking'), (int) ($summary['units_shown'] ?? 0)),
        ],
        [
            'label' => \__('Booked Today', 'must-hotel-booking'),
            'value' => (string) ($summary['booked_today'] ?? 0),
            'descriptor' => \__('Confirmed or in-house occupancy.', 'must-hotel-booking'),
        ],
        [
            'label' => \__('Available Today', 'must-hotel-booking'),
            'value' => (string) ($summary['available_today'] ?? 0),
            'descriptor' => \__('Units still open for staff booking.', 'must-hotel-booking'),
        ],
        [
            'label' => \__('Blocked Today', 'must-hotel-booking'),
            'value' => (string) ($summary['blocked_today'] ?? 0),
            'descriptor' => \sprintf(__('Pending %1$d, holds %2$d', 'must-hotel-booking'), (int) ($summary['pending_today'] ?? 0), (int) ($summary['holds_today'] ?? 0)),
        ],
        [
            'label' => \__('Occupancy Today', 'must-hotel-booking'),
            'value' => (string) ($summary['occupancy_today'] ?? 0) . '%',
            'descriptor' => \__('Based on booked units currently shown.', 'must-hotel-booking'),
        ],
    ];

    if (\function_exists(__NAMESPACE__ . '\render_dashboard_kpi_cards')) {
        render_dashboard_kpi_cards($cards);
        return;
    }

    echo '<div class="must-dashboard-kpis">';

    foreach ($cards as $card) {
        echo '<article class="must-dashboard-kpi-card">';
        echo '<span class="must-dashboard-kpi-label">' . \esc_html((string) ($card['label'] ?? '')) . '</span>';
        echo '<strong class="must-dashboard-kpi-value">' . \esc_html((string) ($card['value'] ?? '0')) . '</strong>';
        echo '<span class="must-dashboard-kpi-descriptor">' . \esc_html((string) ($card['descriptor'] ?? '')) . '</span>';
        echo '</article>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_calendar_filters(CalendarViewQuery $query, array $pageData): void
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
            'weeks' => 1,
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
            'weeks' => 2,
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
            'weeks' => 4,
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
    $panelMode = isset($_GET['panel']) ? \sanitize_key((string) \wp_unslash($_GET['panel'])) : '';
    $visibilityOptionCount = \count((array) ($filters['visibility_options'] ?? []));
    $visibleStateCount = \count((array) ($filters['visibility'] ?? []));
    $quickRangeCount = \count($presetRanges);
    $visibilitySummary = $visibilityOptionCount > 0 && $visibleStateCount < $visibilityOptionCount
        ? \sprintf(\_n('%d visible state', '%d visible states', $visibleStateCount, 'must-hotel-booking'), $visibleStateCount)
        : \__('All states visible', 'must-hotel-booking');
    $quickRangeMeta = \sprintf(\_n('%d preset', '%d presets', $quickRangeCount, 'must-hotel-booking'), $quickRangeCount);
    $visibilityToolbarMeta = $visibilityOptionCount > 0
        ? \sprintf(\__('Visible %1$d of %2$d', 'must-hotel-booking'), $visibleStateCount, $visibilityOptionCount)
        : \__('No states available', 'must-hotel-booking');
    $scopeSnapshot = \__('Full inventory', 'must-hotel-booking');

    if ((int) ($filters['room_id'] ?? 0) > 0 && $currentRoomLabel !== '') {
        $scopeSnapshot = $currentRoomLabel;
    } elseif ($currentCategoryLabel !== '' && !\MustHotelBooking\Core\RoomCatalog::isBookingAllCategory((string) ($filters['category'] ?? ''))) {
        $scopeSnapshot = $currentCategoryLabel;
    }

    echo '<div class="postbox must-dashboard-panel must-calendar-filter-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading must-calendar-filter-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy">';
    echo '<span class="must-dashboard-eyebrow must-calendar-filter-panel-kicker">' . \esc_html__('Board Control Center', 'must-hotel-booking') . '</span>';
    echo '<h2>' . \esc_html__('Board Filters', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Adjust the visible booking window, narrow the accommodation scope, and control which availability states stay visible on the board before you work deeper inside the calendar.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-calendar-filter-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Window', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($range['label'] ?? '')) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Scope', 'must-hotel-booking') . '</strong> ' . \esc_html($scopeSnapshot) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Visibility', 'must-hotel-booking') . '</strong> ' . \esc_html($visibilitySummary) . '</span>';
    echo '</div>';
    echo '</div>';

    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '" class="must-calendar-filter-form">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-calendar" />';

    if ($panelMode !== '' && \in_array($panelMode, ['details', 'reservation', 'block'], true)) {
        echo '<input type="hidden" name="panel" value="' . \esc_attr($panelMode) . '" />';
    }

    echo '<div class="must-calendar-filter-shell">';
    echo '<div class="must-calendar-filter-overview">';
    echo '<div class="must-calendar-filter-intro">';
    echo '<span class="must-dashboard-eyebrow must-calendar-filter-kicker">' . \esc_html__('Current Snapshot', 'must-hotel-booking') . '</span>';
    echo '<h3 class="must-calendar-filter-title">' . \esc_html__('Confirm the board scope before refining it', 'must-hotel-booking') . '</h3>';
    echo '<p class="must-calendar-filter-note">' . \esc_html__('Review the current planning window first, then use the control cards below to tighten the board around the task you are handling.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-calendar-filter-summary">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Window', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($range['label'] ?? '')) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Span', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(_n('%d week', '%d weeks', (int) ($filters['weeks'] ?? 2), 'must-hotel-booking'), (int) ($filters['weeks'] ?? 2))) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Dates', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(_n('%d day', '%d days', (int) ($range['days'] ?? 0), 'must-hotel-booking'), (int) ($range['days'] ?? 0))) . '</span>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-calendar-filter-layout">';
    echo '<div class="must-calendar-filter-main">';
    echo '<section class="must-calendar-filter-card must-calendar-filter-card--window">';
    echo '<div class="must-calendar-filter-card-header">';
    echo '<div class="must-calendar-filter-card-copy">';
    echo '<span class="must-calendar-toolbar-label">' . \esc_html__('Window', 'must-hotel-booking') . '</span>';
    echo '<h3 class="must-calendar-filter-card-title">' . \esc_html__('Board window and cadence', 'must-hotel-booking') . '</h3>';
    echo '<p class="must-calendar-filter-note">' . \esc_html__('Move the board horizon quickly, then decide how much of the upcoming schedule you want visible at once.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-calendar-board-navigation">';
    echo '<a class="button must-calendar-nav-button" href="' . \esc_url(get_admin_calendar_page_url($query->buildUrlArgs(\array_merge((array) ($range['previous_args'] ?? []), ['focus_room_id' => 0, 'focus_date' => '', 'reservation_id' => 0])))) . '">' . \esc_html__('Previous Window', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-calendar-nav-button" href="' . \esc_url(get_admin_calendar_page_url($query->buildUrlArgs(\array_merge((array) ($range['next_args'] ?? []), ['focus_room_id' => 0, 'focus_date' => '', 'reservation_id' => 0])))) . '">' . \esc_html__('Next Window', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-calendar-nav-button is-emphasis" href="' . \esc_url(get_admin_calendar_page_url($query->buildUrlArgs(['start_date' => $today, 'weeks' => 2, 'focus_room_id' => 0, 'focus_date' => '', 'reservation_id' => 0]))) . '">' . \esc_html__('Jump to Today', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-calendar-filter-mini-grid">';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Start Date', 'must-hotel-booking') . '</span><small>' . \esc_html__('Anchor the left edge of the visible booking board.', 'must-hotel-booking') . '</small><input type="date" name="start_date" value="' . \esc_attr((string) ($filters['start_date'] ?? '')) . '" /></label>';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Weeks Visible', 'must-hotel-booking') . '</span><small>' . \esc_html__('Keep the board compact or widen the planning horizon.', 'must-hotel-booking') . '</small><select name="weeks">';

    foreach ((array) ($filters['week_options'] ?? []) as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((int) ($filters['weeks'] ?? 2), (int) $value, false) . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select></label>';
    echo '</div>';
    echo '<div class="must-calendar-toolbar-group must-calendar-toolbar-group--surface">';
    echo '<div class="must-calendar-toolbar-heading">';
    echo '<div class="must-calendar-toolbar-copy">';
    echo '<span class="must-calendar-toolbar-label">' . \esc_html__('Quick Range', 'must-hotel-booking') . '</span>';
    echo '<p class="must-calendar-toolbar-note">' . \esc_html__('Jump the board to a common planning window without resetting the rest of the control bar.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<span class="must-calendar-toolbar-meta">' . \esc_html($quickRangeMeta) . '</span>';
    echo '</div>';
    echo '<div class="must-calendar-chip-list">';

    foreach ($presetRanges as $presetRange) {
        if (!\is_array($presetRange)) {
            continue;
        }

        $presetChipClass = 'must-dashboard-action-chip must-calendar-toolbar-chip';

        if ((string) ($filters['start_date'] ?? '') === $today && (int) ($filters['weeks'] ?? 2) === (int) ($presetRange['weeks'] ?? 0)) {
            $presetChipClass .= ' is-active';
        }

        echo '<a class="' . \esc_attr($presetChipClass) . '" href="' . \esc_url(get_admin_calendar_page_url((array) ($presetRange['args'] ?? []))) . '">' . \esc_html((string) ($presetRange['label'] ?? '')) . '</a>';
    }

    echo '</div>';
    echo '</div>';
    echo '</section>';

    echo '<section class="must-calendar-filter-card">';
    echo '<div class="must-calendar-filter-card-header">';
    echo '<div class="must-calendar-filter-card-copy">';
    echo '<span class="must-calendar-toolbar-label">' . \esc_html__('Scope', 'must-hotel-booking') . '</span>';
    echo '<h3 class="must-calendar-filter-card-title">' . \esc_html__('Accommodation focus', 'must-hotel-booking') . '</h3>';
    echo '<p class="must-calendar-filter-note">' . \esc_html__('Start broad, then narrow to one category or one accommodation when you need to investigate a problem or place a booking.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-calendar-filter-grid">';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Accommodation Category', 'must-hotel-booking') . '</span><small>' . \esc_html__('Limit the board to one room category when you want a cleaner operations view.', 'must-hotel-booking') . '</small><select name="accommodation_type">';

    foreach ((array) ($filters['category_options'] ?? []) as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['category'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select></label>';
    echo '<label class="must-calendar-field"><span>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</span><small>' . \esc_html__('Inspect one accommodation in detail without losing the board context.', 'must-hotel-booking') . '</small><select name="room_id"><option value="">' . \esc_html__('All accommodations', 'must-hotel-booking') . '</option>';

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
    echo '</section>';

    echo '<section class="must-calendar-filter-card">';
    echo '<div class="must-calendar-filter-card-header">';
    echo '<div class="must-calendar-filter-card-copy">';
    echo '<span class="must-calendar-toolbar-label">' . \esc_html__('Visible States', 'must-hotel-booking') . '</span>';
    echo '<h3 class="must-calendar-filter-card-title">' . \esc_html__('Keep only the signals you need', 'must-hotel-booking') . '</h3>';
    echo '<p class="must-calendar-filter-note">' . \esc_html__('Hide states that are not relevant to the task at hand so the calendar reads more like an operational board and less like a spreadsheet.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-calendar-toolbar-group must-calendar-toolbar-group--surface">';
    echo '<div class="must-calendar-toolbar-heading">';
    echo '<div class="must-calendar-toolbar-copy">';
    echo '<span class="must-calendar-toolbar-label">' . \esc_html__('Visibility', 'must-hotel-booking') . '</span>';
    echo '<p class="must-calendar-toolbar-note">' . \esc_html__('Choose which states should stay visible and emphasized while you scan the booking board.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<span class="must-calendar-toolbar-meta">' . \esc_html($visibilityToolbarMeta) . '</span>';
    echo '</div>';
    echo '<div class="must-calendar-visibility-options">';

    foreach ((array) ($filters['visibility_options'] ?? []) as $value => $label) {
        $checked = \in_array((string) $value, (array) ($filters['visibility'] ?? []), true) ? ' checked' : '';
        $stateClass = ' is-' . \sanitize_html_class((string) $value, 'state');
        echo '<label class="must-calendar-filter-chip' . \esc_attr($stateClass) . '">';
        echo '<input class="must-calendar-filter-chip-input" type="checkbox" name="visibility[]" value="' . \esc_attr((string) $value) . '"' . $checked . ' />';
        echo '<span class="must-calendar-filter-chip-ui">';
        echo '<span class="must-calendar-filter-chip-check" aria-hidden="true"></span>';
        echo '<span class="must-calendar-filter-chip-copy">';
        echo '<span class="must-calendar-filter-chip-label">' . \esc_html((string) $label) . '</span>';
        echo '</span>';
        echo '</span>';
        echo '</label>';
    }

    echo '</div>';
    echo '</div>';
    echo '</section>';
    echo '</div>';

    echo '<aside class="must-calendar-filter-side">';
    echo '<section class="must-calendar-filter-card must-calendar-filter-card--summary">';
    echo '<div class="must-calendar-filter-card-header">';
    echo '<div class="must-calendar-filter-card-copy">';
    echo '<span class="must-calendar-toolbar-label">' . \esc_html__('Current Scope', 'must-hotel-booking') . '</span>';
    echo '<h3 class="must-calendar-filter-card-title">' . \esc_html__('Review before updating the board', 'must-hotel-booking') . '</h3>';
    echo '<p class="must-calendar-filter-note">' . \esc_html__('Confirm the current window and scope, then refresh the board with one action.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-calendar-active-filters">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Ends', 'must-hotel-booking') . '</strong> ' . \esc_html(\wp_date(\get_option('date_format'), \strtotime((string) ($filters['end_date'] ?? '')))) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Visibility', 'must-hotel-booking') . '</strong> ' . \esc_html($visibilitySummary) . '</span>';

    if ($currentCategoryLabel !== '' && !\MustHotelBooking\Core\RoomCatalog::isBookingAllCategory((string) ($filters['category'] ?? ''))) {
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Category', 'must-hotel-booking') . '</strong> ' . \esc_html($currentCategoryLabel) . '</span>';
    } else {
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Category', 'must-hotel-booking') . '</strong> ' . \esc_html__('All categories', 'must-hotel-booking') . '</span>';
    }

    if ((int) ($filters['room_id'] ?? 0) > 0 && $currentRoomLabel !== '') {
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</strong> ' . \esc_html($currentRoomLabel) . '</span>';
    } else {
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</strong> ' . \esc_html__('All accommodations', 'must-hotel-booking') . '</span>';
    }

    echo '</div>';
    echo '<div class="must-calendar-filter-actions must-calendar-filter-actions--stacked">';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Update Board', 'must-hotel-booking') . '</button>';
    echo '<a class="button must-calendar-nav-button" href="' . \esc_url(get_admin_calendar_page_url($resetArgs)) . '">' . \esc_html__('Reset View', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</section>';

    if (!empty($pageData['legend']) && \is_array($pageData['legend'])) {
        echo '<section class="must-calendar-filter-card must-calendar-toolbar-group must-calendar-toolbar-group--legend">';
        echo '<div class="must-calendar-filter-card-copy">';
        echo '<span class="must-calendar-toolbar-label">' . \esc_html__('Legend', 'must-hotel-booking') . '</span>';
        echo '<h3 class="must-calendar-filter-card-title">' . \esc_html__('Board state reference', 'must-hotel-booking') . '</h3>';
        echo '<p class="must-calendar-filter-note">' . \esc_html__('Use these visual states to scan the board quickly without reading repeated labels in every cell.', 'must-hotel-booking') . '</p>';
        echo '</div>';
        render_calendar_legend((array) $pageData['legend']);
        echo '</section>';
    }

    echo '</aside>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
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
    $roomCount = \count($rows);
    $dayCount = \count($dates);
    $selected = isset($pageData['selected']) && \is_array($pageData['selected']) ? $pageData['selected'] : [];
    $selectedRoomId = $query->getFocusRoomId();
    $selectedDate = $query->getFocusDate();

    echo '<div class="postbox must-dashboard-panel must-calendar-board-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    render_dashboard_panel_heading(
        \__('Calendar Board', 'must-hotel-booking'),
        \__('Select a room and date cell to inspect stays, availability states, and actions in the contextual sidebar.', 'must-hotel-booking')
    );
    echo '<div class="must-calendar-board-summary">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Showing', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(_n('%d room', '%d rooms', $roomCount, 'must-hotel-booking'), $roomCount)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Columns', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(_n('%d day', '%d days', $dayCount, 'must-hotel-booking'), $dayCount)) . '</span>';

    if (!empty($selected['room']) && !empty($selected['label'])) {
        $room = \is_array($selected['room']) ? $selected['room'] : [];
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Selected', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($room['name'] ?? '')) . ' / ' . \esc_html((string) ($selected['label'] ?? '')) . '</span>';
    }

    echo '</div>';

    if ($roomCount > 10 || $dayCount > 21) {
        echo '<p class="must-calendar-grid-hint">' . \esc_html__('This view is still broad. Narrow the date window or filter to a smaller room set to keep the board more actionable.', 'must-hotel-booking') . '</p>';
    }

    echo '</div>';
    echo '<div class="must-calendar-grid-wrap">';
    echo '<table class="must-calendar-grid"><thead><tr><th class="must-calendar-room-col must-calendar-room-col--header">' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';

    foreach ($dates as $date) {
        $isToday = (string) $date === \current_time('Y-m-d');
        $isSelectedDate = $selectedDate !== '' && $selectedDate === (string) $date;
        $isWeekend = \in_array((int) \wp_date('N', \strtotime((string) $date)), [6, 7], true);
        $dateClasses = ['must-calendar-date-col'];

        if ($isToday) {
            $dateClasses[] = 'is-today';
        }

        if ($isSelectedDate) {
            $dateClasses[] = 'is-focused';
        }

        if ($isWeekend) {
            $dateClasses[] = 'is-weekend';
        }

        echo '<th class="' . \esc_attr(\implode(' ', $dateClasses)) . '"><span>' . \esc_html(\wp_date('D', \strtotime((string) $date))) . '</span><strong>' . \esc_html(\wp_date('j', \strtotime((string) $date))) . '</strong><em>' . \esc_html(\wp_date('M', \strtotime((string) $date))) . '</em></th>';
    }

    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="' . \esc_attr((string) (\count($dates) + 1)) . '" class="must-calendar-empty-grid">' . \esc_html__('No accommodations matched the current calendar filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        $currentCategory = '';
        $columnCount = \count($dates) + 1;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $inventory = isset($row['inventory']) && \is_array($row['inventory']) ? $row['inventory'] : ['total_units' => 1];
            $categoryLabel = (string) ($row['category_label'] ?? '');

            if ($categoryLabel !== '' && $categoryLabel !== $currentCategory) {
                $currentCategory = $categoryLabel;
                echo '<tr class="must-calendar-category-row"><th colspan="' . \esc_attr((string) $columnCount) . '"><span>' . \esc_html($categoryLabel) . '</span></th></tr>';
            }

            $rowClasses = ['must-calendar-row'];

            if ($selectedRoomId > 0 && $selectedRoomId === $roomId) {
                $rowClasses[] = 'is-focused';
            }

            echo '<tr class="' . \esc_attr(\implode(' ', $rowClasses)) . '"><th scope="row" class="must-calendar-room-col">';
            echo '<div class="must-calendar-room-name">' . \esc_html((string) ($row['name'] ?? '')) . '</div>';
            echo '<div class="must-calendar-room-meta">';
            echo '<span>' . \esc_html(\sprintf(_n('%d guest', '%d guests', (int) ($row['max_guests'] ?? 1), 'must-hotel-booking'), (int) ($row['max_guests'] ?? 1))) . '</span>';
            echo '<span>' . \esc_html(\sprintf(_n('%d unit', '%d units', (int) ($inventory['total_units'] ?? 1), 'must-hotel-booking'), (int) ($inventory['total_units'] ?? 1))) . '</span>';
            echo '</div>';

            if (!empty($row['month_totals']) && \is_array($row['month_totals'])) {
                echo '<div class="must-calendar-room-stats">';

                if ((int) ($row['month_totals']['booked'] ?? 0) > 0) {
                    echo '<span class="must-calendar-room-stat">' . \esc_html(\sprintf(__('%d booked', 'must-hotel-booking'), (int) ($row['month_totals']['booked'] ?? 0))) . '</span>';
                }

                if ((int) ($row['month_totals']['pending'] ?? 0) > 0) {
                    echo '<span class="must-calendar-room-stat">' . \esc_html(\sprintf(__('%d pending', 'must-hotel-booking'), (int) ($row['month_totals']['pending'] ?? 0))) . '</span>';
                }

                if ((int) ($row['month_totals']['blocked'] ?? 0) > 0) {
                    echo '<span class="must-calendar-room-stat">' . \esc_html(\sprintf(__('%d blocked', 'must-hotel-booking'), (int) ($row['month_totals']['blocked'] ?? 0))) . '</span>';
                }

                echo '</div>';
            }

            echo '</th>';

            foreach ((array) ($row['cells'] ?? []) as $cell) {
                if (!\is_array($cell)) {
                    continue;
                }

                $cellUrl = get_admin_calendar_page_url(
                    \array_merge(
                        $query->buildUrlArgs(
                            [
                                'focus_room_id' => $roomId,
                                'focus_date' => (string) ($cell['date'] ?? ''),
                                'reservation_id' => 0,
                            ]
                        ),
                        ['panel' => 'details']
                    )
                );
                $state = \sanitize_key((string) ($cell['state'] ?? 'available'));
                $classes = ['must-calendar-cell', 'is-' . $state, 'is-actual-' . \sanitize_key((string) ($cell['actual_state'] ?? $state))];

                if (!empty($cell['today'])) {
                    $classes[] = 'is-today';
                }

                if (!empty($cell['selected'])) {
                    $classes[] = 'is-selected';
                }

                if ($selectedDate !== '' && $selectedDate === (string) ($cell['date'] ?? '')) {
                    $classes[] = 'is-focused-date';
                }

                $stateLabel = get_calendar_cell_state_label($cell);
                $headline = \trim((string) ($cell['headline'] ?? ''));
                $indicators = isset($cell['indicators']) && \is_array($cell['indicators']) ? $cell['indicators'] : [];
                $hasFlags = (int) ($cell['arrivals_count'] ?? 0) > 0 || (int) ($cell['departures_count'] ?? 0) > 0;

                echo '<td class="' . \esc_attr(\implode(' ', $classes)) . '"><a class="must-calendar-cell-link" href="' . \esc_url($cellUrl) . '" aria-label="' . \esc_attr(\sprintf(__('%1$s on %2$s: %3$s', 'must-hotel-booking'), (string) ($row['name'] ?? ''), \wp_date(\get_option('date_format'), \strtotime((string) ($cell['date'] ?? ''))), $stateLabel)) . '">';
                echo '<span class="must-calendar-cell-top">';

                if ($state === 'available') {
                    echo '<span class="must-calendar-cell-dot is-available" aria-hidden="true"></span>';
                } else {
                    echo '<span class="must-calendar-cell-chip is-' . \esc_attr($state) . '">' . \esc_html($stateLabel) . '</span>';
                }

                if ($headline !== '') {
                    echo '<span class="must-calendar-cell-headline">' . \esc_html($headline) . '</span>';
                }

                echo '</span>';

                if (!empty($indicators)) {
                    echo '<span class="must-calendar-cell-indicators">';

                    foreach ($indicators as $indicator) {
                        echo '<span>' . \esc_html((string) $indicator) . '</span>';
                    }

                    echo '</span>';
                }

                if ($hasFlags) {
                    echo '<span class="must-calendar-cell-flags">';

                    if ((int) ($cell['arrivals_count'] ?? 0) > 0) {
                        echo '<span class="is-arrival">' . \esc_html(\sprintf(__('Arrivals %d', 'must-hotel-booking'), (int) ($cell['arrivals_count'] ?? 0))) . '</span>';
                    }

                    if ((int) ($cell['departures_count'] ?? 0) > 0) {
                        echo '<span class="is-departure">' . \esc_html(\sprintf(__('Departures %d', 'must-hotel-booking'), (int) ($cell['departures_count'] ?? 0))) . '</span>';
                    }

                    echo '</span>';
                }

                echo '</a></td>';
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
    $selectedRoom = isset($selected['room']) && \is_array($selected['room']) ? $selected['room'] : [];
    $selectedSelection = isset($selected['selection']) && \is_array($selected['selection']) ? $selected['selection'] : [];
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

    $hasSelection = !empty($selectedRoom) && !empty($selected['date']);
    $sidebarMode = get_calendar_sidebar_mode($quickBookingErrors, $blockErrors);
    $canCreate = !empty($selectedActions['can_create']);

    echo '<div class="postbox must-dashboard-panel must-calendar-context-panel" data-initial-mode="' . \esc_attr($sidebarMode) . '">';
    echo '<div class="must-dashboard-panel-inner">';
    render_dashboard_panel_heading(
        \__('Context Panel', 'must-hotel-booking'),
        $hasSelection
            ? \__('Selection details, linked admin actions, and the current create-reservation or block workflow all live here.', 'must-hotel-booking')
            : \__('Select a room and date range to view details or create an action.', 'must-hotel-booking')
    );

    if (!$hasSelection) {
        echo '<div class="must-dashboard-empty-state must-calendar-context-empty">';
        echo '<p>' . \esc_html__('Select a room and date range to view details or create an action.', 'must-hotel-booking') . '</p>';
        echo '<p class="must-calendar-selection-meta">' . \esc_html__('The board stays in focus on the left while reservation and block workflows only appear here when they are relevant to the selected room and day.', 'must-hotel-booking') . '</p>';
        echo '</div>';
        echo '</div></div>';
        return;
    }

    echo '<div class="must-calendar-context-summary">';
    echo '<span class="must-calendar-context-kicker">' . \esc_html__('Selected Focus', 'must-hotel-booking') . '</span>';
    echo '<h3 class="must-calendar-selection-title">' . \esc_html((string) ($selectedRoom['name'] ?? '')) . '</h3>';
    echo '<p class="must-calendar-selection-meta">' . \esc_html((string) ($selected['label'] ?? '')) . '</p>';
    echo '<div class="must-calendar-selection-badges">';
    echo render_calendar_status_badge((string) ($selectedSummary['actual_state'] ?? $selectedSummary['state'] ?? 'available'));

    if (!empty($selectedSummary['hidden_state'])) {
        echo render_calendar_status_badge('filtered');
    }

    echo '</div>';

    if (!empty($selectedSummary['hidden_state'])) {
        echo '<p class="must-calendar-selection-meta">' . \esc_html__('This date is muted by the current visibility filters, but the underlying room state is still available in the data shown here.', 'must-hotel-booking') . '</p>';
    }

    echo '</div>';
    echo '<div class="must-calendar-context-metrics">';
    echo '<div><span>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($selectedRoom['name'] ?? '')) . '</strong></div>';
    echo '<div><span>' . \esc_html__('Dates', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($selectedSelection['range_label'] ?? (string) ($selected['label'] ?? ''))) . '</strong></div>';
    echo '<div><span>' . \esc_html__('Nights', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) (($selectedSelection['nights'] ?? 1)) . ' ' . \_n('night', 'nights', (int) ($selectedSelection['nights'] ?? 1), 'must-hotel-booking')) . '</strong></div>';
    echo '<div><span>' . \esc_html__('Status', 'must-hotel-booking') . '</span><strong>' . \esc_html(get_calendar_cell_state_label(['state' => (string) ($selectedSummary['actual_state'] ?? $selectedSummary['state'] ?? 'available')])) . '</strong></div>';
    echo '</div>';

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

    echo '</div>';

    if ($canCreate || !empty($quickBookingErrors) || !empty($blockErrors)) {
        echo '<div class="must-calendar-context-nav">';
        echo '<a class="button' . ($sidebarMode === 'details' ? ' is-active' : '') . '" href="' . \esc_url(get_calendar_mode_url($query, 'details')) . '" data-must-calendar-mode-trigger="details">' . \esc_html__('Overview', 'must-hotel-booking') . '</a>';

        if ($canCreate || !empty($quickBookingErrors)) {
            echo '<a class="button button-primary' . ($sidebarMode === 'reservation' ? ' is-active' : '') . '" href="' . \esc_url(get_calendar_mode_url($query, 'reservation')) . '" data-must-calendar-mode-trigger="reservation">' . \esc_html__('Create Reservation', 'must-hotel-booking') . '</a>';
        }

        echo '<a class="button' . ($sidebarMode === 'block' ? ' is-active' : '') . '" href="' . \esc_url(get_calendar_mode_url($query, 'block')) . '" data-must-calendar-mode-trigger="block">' . \esc_html__('Create Block', 'must-hotel-booking') . '</a>';
        echo '</div>';
    }

    echo '<div class="must-calendar-context-sections">';
    echo '<section class="must-calendar-context-section" data-must-calendar-mode="details">';

    if (empty($selected['stays']) && empty($selected['arrivals']) && empty($selected['departures']) && empty($selected['rules']) && empty($selected['locks'])) {
        echo '<div class="must-calendar-context-note"><p>' . \esc_html__('This room and date are currently open inside the visible board scope. Use the actions above to create a reservation or add a manual block without leaving the calendar.', 'must-hotel-booking') . '</p></div>';
    }

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

            echo '<article class="must-calendar-activity-item">';
            echo '<div class="must-calendar-activity-copy">';
            echo '<div class="must-calendar-activity-top"><strong>' . \esc_html((string) ($item['reference'] ?? '')) . '</strong>' . render_calendar_status_badge((string) ($item['status'] ?? 'info')) . '</div>';
            echo '<p>' . \esc_html((string) ($item['guest'] ?? '')) . '</p>';
            echo '<div class="must-calendar-activity-meta">';

            if (!empty($item['date_label'])) {
                echo '<span>' . \esc_html((string) $item['date_label']) . '</span>';
            }

            if (!empty($item['nights_label'])) {
                echo '<span>' . \esc_html((string) $item['nights_label']) . '</span>';
            }

            if (!empty($item['booking_source_label'])) {
                echo '<span>' . \esc_html((string) $item['booking_source_label']) . '</span>';
            }

            if (!empty($item['payment_status_label'])) {
                echo '<span>' . \esc_html((string) $item['payment_status_label']) . '</span>';
            }

            if (!empty($item['total_price_label']) && (float) ($item['total_price'] ?? 0.0) > 0) {
                echo '<span>' . \esc_html((string) $item['total_price_label']) . '</span>';
            }

            echo '</div>';

            if (!empty($item['notes'])) {
                echo '<p class="must-calendar-activity-note">' . \esc_html((string) $item['notes']) . '</p>';
            }

            echo '</div>';
            echo '<div class="must-calendar-activity-actions">';

            if (!empty($item['view_url'])) {
                echo '<a class="button button-small" href="' . \esc_url((string) $item['view_url']) . '">' . \esc_html__('Open Reservation', 'must-hotel-booking') . '</a>';
                echo '<a class="button button-small" href="' . \esc_url((string) $item['view_url']) . '">' . \esc_html__('Edit Reservation', 'must-hotel-booking') . '</a>';
            }

            if (!empty($item['payment_url']) && (string) ($item['status'] ?? '') !== 'blocked') {
                echo '<a class="button button-small" href="' . \esc_url((string) $item['payment_url']) . '">' . \esc_html__('Manage Payment', 'must-hotel-booking') . '</a>';
            }

            if (!empty($item['remove_block_url'])) {
                echo '<a class="button button-small button-link-delete" href="' . \esc_url((string) $item['remove_block_url']) . '">' . \esc_html__('Remove Block', 'must-hotel-booking') . '</a>';
            }

            echo '</div>';
            echo '</article>';
        }

        echo '</div></div>';
    }

    if (!empty($selected['rules'])) {
        echo '<div class="must-calendar-activity-group"><h3>' . \esc_html__('Restrictions', 'must-hotel-booking') . '</h3><div class="must-calendar-activity-list">';

        foreach ((array) $selected['rules'] as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            echo '<article class="must-calendar-activity-item">';
            echo '<div class="must-calendar-activity-copy">';
            echo '<div class="must-calendar-activity-top"><strong>' . \esc_html((string) ($rule['label'] ?? '')) . '</strong></div>';
            echo '<div class="must-calendar-activity-meta"><span>' . \esc_html((string) ($rule['range'] ?? '')) . '</span></div>';

            if (!empty($rule['reason'])) {
                echo '<p class="must-calendar-activity-note">' . \esc_html((string) ($rule['reason'] ?? '')) . '</p>';
            }

            echo '</div>';

            if (!empty($selectedActions['availability_rules_url'])) {
                echo '<div class="must-calendar-activity-actions"><a class="button button-small" href="' . \esc_url((string) $selectedActions['availability_rules_url']) . '">' . \esc_html__('Inspect Rules', 'must-hotel-booking') . '</a></div>';
            }

            echo '</article>';
        }

        echo '</div></div>';
    }

    if (!empty($selected['locks'])) {
        echo '<div class="must-calendar-activity-group"><h3>' . \esc_html__('Temporary Holds', 'must-hotel-booking') . '</h3><div class="must-calendar-activity-list">';

        foreach ((array) $selected['locks'] as $lock) {
            if (!\is_array($lock)) {
                continue;
            }

            echo '<article class="must-calendar-activity-item">';
            echo '<div class="must-calendar-activity-copy">';
            echo '<div class="must-calendar-activity-top"><strong>' . \esc_html((string) ($lock['room_number'] ?? __('Inventory room', 'must-hotel-booking'))) . '</strong>' . render_calendar_status_badge('hold') . '</div>';
            echo '<div class="must-calendar-activity-meta"><span>' . \esc_html((string) ($lock['expires_at'] ?? '')) . '</span></div>';
            echo '</div>';
            echo '</article>';
        }

        echo '</div></div>';
    }

    echo '</section>';

    if (\function_exists(__NAMESPACE__ . '\render_admin_quick_booking_panel')) {
        echo '<section class="must-calendar-context-section" data-must-calendar-mode="reservation">';
        render_admin_quick_booking_panel(
            $quickBookingDefaults,
            $quickBookingErrors,
            [
                'action_url' => get_admin_calendar_page_url($query->buildUrlArgs()),
                'redirect_target' => 'calendar',
                'return_url' => $returnUrl,
                'eyebrow' => \__('Calendar Action', 'must-hotel-booking'),
                'title' => \__('Create Reservation', 'must-hotel-booking'),
                'description' => \__('Turn the selected accommodation and dates into a confirmed reservation without leaving the calendar workspace.', 'must-hotel-booking'),
                'submit_label' => \__('Create Reservation', 'must-hotel-booking'),
                'variant' => 'inline',
                'panel_class' => 'must-calendar-embedded-booking',
            ]
        );
        echo '</section>';
    }

    echo '<section class="must-calendar-context-section" data-must-calendar-mode="block">';
    echo '<div class="must-calendar-inline-card">';
    echo '<div class="must-calendar-inline-head">';
    echo '<span class="must-calendar-context-kicker">' . \esc_html__('Calendar Action', 'must-hotel-booking') . '</span>';
    echo '<h3>' . \esc_html__('Create Block', 'must-hotel-booking') . '</h3>';
    echo '<p>' . \esc_html__('Reserve the selected room inventory for maintenance or other operational reasons directly from the sidebar.', 'must-hotel-booking') . '</p>';
    echo '</div>';

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

    if (!empty($selectedRoom) && (int) ($blockDefaults['room_id'] ?? 0) > 0) {
        echo '<input type="hidden" name="room_id" value="' . \esc_attr((string) ($blockDefaults['room_id'] ?? 0)) . '" />';
        echo '<div class="must-calendar-inline-readonly"><span>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($selectedRoom['name'] ?? '')) . '</strong></div>';
    } else {
        echo '<label><span>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</span><select name="room_id" required><option value="">' . \esc_html__('Select accommodation', 'must-hotel-booking') . '</option>';

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            echo '<option value="' . \esc_attr((string) $roomId) . '"' . \selected((int) ($blockDefaults['room_id'] ?? 0), $roomId, false) . '>' . \esc_html((string) ($room['name'] ?? '')) . '</option>';
        }

        echo '</select></label>';
    }

    echo '<label><span>' . \esc_html__('Start', 'must-hotel-booking') . '</span><input type="date" name="checkin" value="' . \esc_attr((string) ($blockDefaults['checkin'] ?? '')) . '" required /></label>';
    echo '<label><span>' . \esc_html__('End', 'must-hotel-booking') . '</span><input type="date" name="checkout" value="' . \esc_attr((string) ($blockDefaults['checkout'] ?? '')) . '" required /></label>';
    echo '<div class="must-calendar-inline-actions"><button type="submit" class="button button-primary">' . \esc_html__('Create Block', 'must-hotel-booking') . '</button><a class="button" href="' . \esc_url(get_calendar_mode_url($query, 'details')) . '" data-must-calendar-mode-trigger="details">' . \esc_html__('Back to Overview', 'must-hotel-booking') . '</a></div>';
    echo '</form>';
    echo '</div>';
    echo '</section>';
    echo '</div>';
    echo '</div></div>';
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

    echo '<div class="wrap must-dashboard-page must-calendar-page">';
    render_calendar_admin_notice_from_query();
    render_calendar_page_header($query, $pageData);
    render_calendar_summary_cards(isset($pageData['summary']) && \is_array($pageData['summary']) ? $pageData['summary'] : []);
    echo '<div class="must-dashboard-layout must-calendar-workspace">';
    echo '<div class="must-dashboard-main">';
    render_calendar_filters($query, $pageData);
    render_calendar_grid($query, $pageData);
    echo '</div>';
    echo '<div class="must-dashboard-sidebar">';
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
    echo '</div>';
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

    \wp_enqueue_script(
        'must-hotel-booking-admin-calendar',
        MUST_HOTEL_BOOKING_URL . 'assets/js/admin-calendar.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );
}

\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_calendar_assets');
