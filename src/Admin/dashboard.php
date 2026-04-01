<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\StaffAccess;

if (!\function_exists(__NAMESPACE__ . '\render_admin_rooms_page')) {
    require_once __DIR__ . '/rooms.php';
}

/**
 * Get capability required for plugin admin pages.
 */
function get_admin_capability(string $pageSlug = ''): string
{
    $pageSlug = $pageSlug !== ''
        ? \sanitize_key($pageSlug)
        : (isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '');

    if ($pageSlug === 'must-hotel-booking-settings') {
        return StaffAccess::getSettingsCapability();
    }

    return 'manage_options';
}

/**
 * Ensure the current user can access plugin admin pages.
 */
function ensure_admin_capability(string $pageSlug = ''): void
{
    $capability = get_admin_capability($pageSlug);

    if (!\current_user_can($capability) && !\current_user_can('manage_options')) {
        \wp_die(\esc_html__('You do not have permission to access this page.', 'must-hotel-booking'));
    }
}

/**
 * Register top-level and submenu admin pages.
 */
function register_admin_menu(): void
{
    $parentSlug = 'must-hotel-booking';

    \add_menu_page(
        'MUST Hotel Booking',
        'MUST Hotel Booking',
        'manage_options',
        $parentSlug,
        __NAMESPACE__ . '\render_admin_dashboard_page',
        'dashicons-calendar',
        26
    );

    foreach (get_admin_menu_pages() as $page) {
        \add_submenu_page(
            $parentSlug,
            (string) $page['title'],
            (string) $page['menu_title'],
            get_admin_capability((string) $page['slug']),
            (string) $page['slug'],
            (string) $page['callback']
        );
    }

    foreach (get_hidden_admin_menu_pages() as $page) {
        \add_submenu_page(
            null,
            (string) $page['title'],
            (string) $page['menu_title'],
            get_admin_capability((string) $page['slug']),
            (string) $page['slug'],
            (string) $page['callback']
        );
    }
}

/**
 * @return array<int, array<string, string>>
 */
function get_admin_menu_pages(): array
{
    return [
        [
            'title' => 'Dashboard',
            'menu_title' => 'Dashboard',
            'slug' => 'must-hotel-booking',
            'callback' => __NAMESPACE__ . '\render_admin_dashboard_page',
        ],
        [
            'title' => 'Reservations',
            'menu_title' => 'Reservations',
            'slug' => 'must-hotel-booking-reservations',
            'callback' => __NAMESPACE__ . '\render_admin_reservations_page',
        ],
        [
            'title' => 'Calendar',
            'menu_title' => 'Calendar',
            'slug' => 'must-hotel-booking-calendar',
            'callback' => __NAMESPACE__ . '\render_admin_calendar_page',
        ],
        [
            'title' => 'Accommodations',
            'menu_title' => 'Accommodations',
            'slug' => 'must-hotel-booking-rooms',
            'callback' => __NAMESPACE__ . '\render_admin_rooms_page',
        ],
        [
            'title' => 'Rates & Pricing',
            'menu_title' => 'Rates & Pricing',
            'slug' => 'must-hotel-booking-pricing',
            'callback' => __NAMESPACE__ . '\render_admin_pricing_page',
        ],
        [
            'title' => 'Availability Rules',
            'menu_title' => 'Availability Rules',
            'slug' => 'must-hotel-booking-availability-rules',
            'callback' => __NAMESPACE__ . '\render_admin_availability_rules_page',
        ],
        [
            'title' => 'Payments',
            'menu_title' => 'Payments',
            'slug' => 'must-hotel-booking-payments',
            'callback' => __NAMESPACE__ . '\render_admin_payments_page',
        ],
        [
            'title' => 'Emails',
            'menu_title' => 'Emails',
            'slug' => 'must-hotel-booking-emails',
            'callback' => __NAMESPACE__ . '\render_admin_emails_page',
        ],
        [
            'title' => 'Guests',
            'menu_title' => 'Guests',
            'slug' => 'must-hotel-booking-guests',
            'callback' => __NAMESPACE__ . '\render_admin_guests_page',
        ],
        [
            'title' => 'Coupons',
            'menu_title' => 'Coupons',
            'slug' => 'must-hotel-booking-coupons',
            'callback' => __NAMESPACE__ . '\render_admin_coupons_page',
        ],
        [
            'title' => 'Reports',
            'menu_title' => 'Reports',
            'slug' => 'must-hotel-booking-reports',
            'callback' => __NAMESPACE__ . '\render_admin_reports_page',
        ],
        [
            'title' => 'Settings',
            'menu_title' => 'Settings',
            'slug' => 'must-hotel-booking-settings',
            'callback' => __NAMESPACE__ . '\render_admin_settings_page',
        ],
    ];
}

/**
 * @return array<int, array<string, string>>
 */
function get_hidden_admin_menu_pages(): array
{
    return [
        [
            'title' => 'Reservation',
            'menu_title' => 'Reservation',
            'slug' => 'must-hotel-booking-reservation',
            'callback' => __NAMESPACE__ . '\render_admin_reservation_detail_page',
        ],
        [
            'title' => 'Add Reservation',
            'menu_title' => 'Add Reservation',
            'slug' => 'must-hotel-booking-reservation-create',
            'callback' => __NAMESPACE__ . '\render_admin_reservation_create_page',
        ],
        [
            'title' => 'Rate Plans',
            'menu_title' => 'Rate Plans',
            'slug' => 'must-hotel-booking-rate-plans',
            'callback' => __NAMESPACE__ . '\render_admin_rate_plans_page',
        ],
        [
            'title' => 'Taxes & Fees',
            'menu_title' => 'Taxes & Fees',
            'slug' => 'must-hotel-booking-taxes',
            'callback' => __NAMESPACE__ . '\render_admin_taxes_page',
        ],
    ];
}

/**
 * Render dashboard admin page.
 */
function render_admin_dashboard_page(): void
{
    ensure_admin_capability();

    $quickBookingState = [
        'errors' => [],
        'form' => null,
    ];

    if (\function_exists(__NAMESPACE__ . '\maybe_handle_admin_quick_booking_submission')) {
        $quickBookingState = maybe_handle_admin_quick_booking_submission();
    }

    $quickBookingErrors = isset($quickBookingState['errors']) && \is_array($quickBookingState['errors'])
        ? $quickBookingState['errors']
        : [];
    $quickBookingForm = isset($quickBookingState['form']) && \is_array($quickBookingState['form'])
        ? $quickBookingState['form']
        : null;
    $dashboardData = (new DashboardDataProvider())->getDashboardData();
    $reservationsTableExists = \MustHotelBooking\Engine\get_reservation_repository()->reservationsTableExists();
    $kpis = isset($dashboardData['kpis']) && \is_array($dashboardData['kpis']) ? $dashboardData['kpis'] : [];
    $attentionItems = isset($dashboardData['attention_items']) && \is_array($dashboardData['attention_items']) ? $dashboardData['attention_items'] : [];
    $healthItems = isset($dashboardData['health_items']) && \is_array($dashboardData['health_items']) ? $dashboardData['health_items'] : [];
    $recentReservations = isset($dashboardData['recent_reservations']) && \is_array($dashboardData['recent_reservations']) ? $dashboardData['recent_reservations'] : [];
    $recentActivity = isset($dashboardData['recent_activity']) && \is_array($dashboardData['recent_activity']) ? $dashboardData['recent_activity'] : [];
    $quickActions = isset($dashboardData['quick_actions']) && \is_array($dashboardData['quick_actions']) ? $dashboardData['quick_actions'] : [];
    $generatedAt = format_admin_dashboard_datetime((string) ($dashboardData['generated_at'] ?? ''));
    $attentionCount = \count($attentionItems);
    $healthReviewCount = count_dashboard_actionable_health_items($healthItems);
    $recentActivityCount = \count($recentActivity);
    $calendarUrl = \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
        ? get_admin_calendar_page_url(['start_date' => \current_time('Y-m-d'), 'weeks' => 2])
        : '';
    $reservationsUrl = \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url')
        ? get_admin_reservations_page_url()
        : '';

    echo '<div class="wrap must-dashboard-page">';
    echo '<div class="must-dashboard-hero">';
    echo '<div class="must-dashboard-hero-copy">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Operations Board', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Dashboard', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Front desk, reservations, payments, and configuration issues are surfaced here first so staff can move straight into the operational page that needs action.', 'must-hotel-booking') . '</p>';
    echo '<div class="must-dashboard-hero-meta">';

    if ($generatedAt !== '') {
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Updated', 'must-hotel-booking') . '</strong> ' . \esc_html($generatedAt) . '</span>';
    }

    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Attention', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(\_n('%d item', '%d items', $attentionCount, 'must-hotel-booking'), $attentionCount)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Health Review', 'must-hotel-booking') . '</strong> ' . \esc_html(\sprintf(\_n('%d check', '%d checks', $healthReviewCount, 'must-hotel-booking'), $healthReviewCount)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Recent Events', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $recentActivityCount) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-dashboard-hero-actions">';
    echo '<a class="button button-primary" href="' . \esc_url(get_admin_reservation_create_page_url()) . '">' . \esc_html__('Add Reservation', 'must-hotel-booking') . '</a>';

    if ($calendarUrl !== '') {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url($calendarUrl) . '">' . \esc_html__('Open Calendar', 'must-hotel-booking') . '</a>';
    }

    if ($reservationsUrl !== '') {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url($reservationsUrl) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a>';
    }

    echo '</div>';
    echo '</div>';

    if (\function_exists(__NAMESPACE__ . '\render_dashboard_quick_booking_notice_from_query')) {
        render_dashboard_quick_booking_notice_from_query();
    }

    if (!$reservationsTableExists) {
        echo '<div class="notice notice-warning"><p>' . \esc_html__('Reservations table was not found. Reactivate the plugin to create the operational booking tables.', 'must-hotel-booking') . '</p></div>';
    }

    render_dashboard_action_strip($quickActions);
    render_dashboard_kpi_cards($kpis);

    echo '<div class="must-dashboard-layout">';
    echo '<div class="must-dashboard-main">';
    render_dashboard_attention_panel($attentionItems);
    render_dashboard_recent_reservations_panel($recentReservations);
    render_dashboard_recent_activity_panel($recentActivity);
    echo '</div>';
    echo '<div class="must-dashboard-sidebar">';
    render_dashboard_quick_actions_panel($quickActions);
    render_dashboard_health_panel($healthItems);
    echo '</div>';
    echo '</div>';

    if (\function_exists(__NAMESPACE__ . '\render_admin_quick_booking_panel')) {
        echo '<div id="must-dashboard-quick-booking" class="must-dashboard-quick-booking-wrap">';
        render_admin_quick_booking_panel(
            $quickBookingForm,
            $quickBookingErrors,
            [
                'eyebrow' => \__('Operations Shortcut', 'must-hotel-booking'),
                'title' => \__('Quick Booking Workspace', 'must-hotel-booking'),
                'description' => \__('Lock in a direct reservation from the dashboard without switching to the full reservation workflow first.', 'must-hotel-booking'),
                'submit_label' => \__('Create Reservation', 'must-hotel-booking'),
            ]
        );
        echo '</div>';
    }

    echo '</div>';
}

function format_admin_dashboard_datetime(string $value): string
{
    $value = \trim($value);

    if ($value === '') {
        return '';
    }

    $timestamp = \strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return \wp_date(\get_option('date_format') . ' ' . \get_option('time_format'), $timestamp);
}

function format_admin_dashboard_date(string $value): string
{
    $value = \trim($value);

    if ($value === '') {
        return '';
    }

    $timestamp = \strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return \wp_date(\get_option('date_format'), $timestamp);
}

/**
 * @param array<int, array<string, string>> $items
 */
function count_dashboard_actionable_health_items(array $items): int
{
    $count = 0;

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        $status = \sanitize_key((string) ($item['status'] ?? ''));

        if (!\in_array($status, ['ok', 'healthy'], true)) {
            $count++;
        }
    }

    return $count;
}

function get_dashboard_kpi_icon(string $label, int $index = 0): string
{
    $normalized = \sanitize_key(\str_replace(' ', '_', $label));

    if (\strpos($normalized, 'arrival') !== false) {
        return 'dashicons-arrow-down-alt';
    }

    if (\strpos($normalized, 'departure') !== false) {
        return 'dashicons-arrow-up-alt';
    }

    if (\strpos($normalized, 'house') !== false || \strpos($normalized, 'guest') !== false) {
        return 'dashicons-groups';
    }

    if (\strpos($normalized, 'pending') !== false) {
        return 'dashicons-clock';
    }

    if (\strpos($normalized, 'unpaid') !== false) {
        return 'dashicons-money-alt';
    }

    if (\strpos($normalized, 'occupancy') !== false || \strpos($normalized, 'unit') !== false) {
        return 'dashicons-building';
    }

    if (\strpos($normalized, 'revenue') !== false) {
        return 'dashicons-chart-line';
    }

    if (\strpos($normalized, 'blocked') !== false || \strpos($normalized, 'unavailable') !== false) {
        return 'dashicons-lock';
    }

    $fallbacks = [
        'dashicons-chart-bar',
        'dashicons-admin-home',
        'dashicons-calendar-alt',
        'dashicons-admin-site-alt3',
    ];

    return $fallbacks[$index % \count($fallbacks)];
}

function get_dashboard_action_icon(string $label): string
{
    $normalized = \sanitize_key(\str_replace(' ', '_', $label));

    if (\strpos($normalized, 'calendar') !== false) {
        return 'dashicons-calendar-alt';
    }

    if (\strpos($normalized, 'reservation') !== false || \strpos($normalized, 'arrivals') !== false || \strpos($normalized, 'departures') !== false) {
        return 'dashicons-index-card';
    }

    if (\strpos($normalized, 'payment') !== false) {
        return 'dashicons-money-alt';
    }

    if (\strpos($normalized, 'email') !== false) {
        return 'dashicons-email-alt';
    }

    if (\strpos($normalized, 'setting') !== false) {
        return 'dashicons-admin-generic';
    }

    return 'dashicons-arrow-right-alt2';
}

function get_dashboard_context_badge_tone(string $label): string
{
    $normalized = \sanitize_key(\str_replace(' ', '_', \strtolower($label)));

    if (\preg_match('/confirmed|completed|paid|received|ok|healthy/', $normalized) === 1) {
        return 'ok';
    }

    if (\preg_match('/pending|unpaid|arrival|departure|review|hotel/', $normalized) === 1) {
        return 'warning';
    }

    if (\preg_match('/cancelled|failed|error|blocked|refund|missing/', $normalized) === 1) {
        return 'error';
    }

    return 'info';
}

function render_dashboard_context_badge(string $label): string
{
    $tone = get_dashboard_context_badge_tone($label);
    $classMap = [
        'ok' => 'is-ok',
        'warning' => 'is-warning',
        'error' => 'is-error',
        'info' => 'is-info',
    ];
    $className = isset($classMap[$tone]) ? $classMap[$tone] : $classMap['info'];

    return '<span class="must-dashboard-status-badge is-compact must-dashboard-context-badge ' . \esc_attr($className) . '">' . \esc_html($label) . '</span>';
}

/**
 * @param array<int, array<string, string>> $actions
 */
function render_dashboard_action_strip(array $actions): void
{
    if (empty($actions)) {
        return;
    }

    echo '<div class="must-dashboard-action-strip">';
    echo '<span class="must-dashboard-action-strip-label">' . \esc_html__('Jump To', 'must-hotel-booking') . '</span>';

    foreach (\array_slice($actions, 0, 6) as $action) {
        if (!\is_array($action) || empty($action['url'])) {
            continue;
        }

        $label = (string) ($action['label'] ?? '');
        echo '<a class="must-dashboard-action-chip" href="' . \esc_url((string) $action['url']) . '">';
        echo '<span class="dashicons ' . \esc_attr(get_dashboard_action_icon($label)) . '" aria-hidden="true"></span>';
        echo '<span>' . \esc_html($label) . '</span>';
        echo '</a>';
    }

    echo '</div>';
}

function render_dashboard_panel_heading(string $title, string $description = '', string $actionUrl = '', string $actionLabel = ''): void
{
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy">';
    echo '<h2>' . \esc_html($title) . '</h2>';

    if ($description !== '') {
        echo '<p>' . \esc_html($description) . '</p>';
    }

    echo '</div>';

    if ($actionUrl !== '' && $actionLabel !== '') {
        echo '<a class="button must-dashboard-panel-link" href="' . \esc_url($actionUrl) . '">' . \esc_html($actionLabel) . '</a>';
    }

    echo '</div>';
}

/**
 * @param array<int, array<string, string>> $kpis
 */
function render_dashboard_kpi_cards(array $kpis): void
{
    echo '<div class="must-dashboard-kpis">';

    foreach ($kpis as $index => $card) {
        if (!\is_array($card)) {
            continue;
        }

        $url = isset($card['url']) ? (string) $card['url'] : '';
        $label = (string) ($card['label'] ?? '');
        $tag = $url !== '' ? 'a' : 'div';

        echo '<' . $tag . ' class="must-dashboard-kpi-card"';

        if ($url !== '') {
            echo ' href="' . \esc_url($url) . '"';
        }

        echo '>';
        echo '<span class="must-dashboard-kpi-icon dashicons ' . \esc_attr(get_dashboard_kpi_icon($label, (int) $index)) . '" aria-hidden="true"></span>';
        echo '<span class="must-dashboard-kpi-label">' . \esc_html($label) . '</span>';
        echo '<strong class="must-dashboard-kpi-value">' . \esc_html((string) ($card['value'] ?? '0')) . '</strong>';

        if (!empty($card['descriptor'])) {
            echo '<span class="must-dashboard-kpi-descriptor">' . \esc_html((string) $card['descriptor']) . '</span>';
        }

        if ($url !== '') {
            echo '<span class="must-dashboard-kpi-cta">' . \esc_html__('Open view', 'must-hotel-booking') . '</span>';
        }

        echo '</' . $tag . '>';
    }

    echo '</div>';
}

/**
 * @param array<int, array<string, string>> $items
 */
function render_dashboard_attention_panel(array $items): void
{
    echo '<div class="postbox must-dashboard-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    render_dashboard_panel_heading(
        \__('Needs Attention', 'must-hotel-booking'),
        \__('Operational issues that likely need action before they create guest or payment problems.', 'must-hotel-booking'),
        \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url') ? get_admin_reservations_page_url() : '',
        \__('Open reservations', 'must-hotel-booking')
    );

    if (empty($items)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No urgent operational issues are currently detected.', 'must-hotel-booking') . '</p>';
        echo '</div></div>';

        return;
    }

    echo '<div class="must-dashboard-attention-list">';

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        $reference = (string) ($item['reference'] ?? '');
        echo '<article class="must-dashboard-attention-item is-' . \esc_attr(\sanitize_key((string) ($item['severity'] ?? 'info'))) . '">';
        echo '<div class="must-dashboard-attention-top">';
        echo render_dashboard_status_badge((string) ($item['severity'] ?? 'info'), true);

        if ($reference !== '') {
            echo '<span class="must-dashboard-attention-reference">' . \esc_html($reference) . '</span>';
        }

        echo '</div>';
        echo '<h3>' . \esc_html((string) ($item['label'] ?? '')) . '</h3>';
        echo '<p>' . \esc_html((string) ($item['message'] ?? '')) . '</p>';

        if (!empty($item['action_url'])) {
            echo '<a class="button must-dashboard-item-link" href="' . \esc_url((string) $item['action_url']) . '">' . \esc_html__('Open', 'must-hotel-booking') . '</a>';
        }

        echo '</article>';
    }

    echo '</div>';
    echo '</div></div>';
}

/**
 * @param array<int, array<string, string>> $items
 */
function render_dashboard_health_panel(array $items): void
{
    echo '<div class="postbox must-dashboard-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    render_dashboard_panel_heading(
        \__('System Health', 'must-hotel-booking'),
        \__('Configuration and infrastructure checks that affect booking reliability.', 'must-hotel-booking'),
        \function_exists(__NAMESPACE__ . '\get_admin_settings_page_url') ? get_admin_settings_page_url() : '',
        \__('Open settings', 'must-hotel-booking')
    );

    if (empty($items)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No health data is available yet.', 'must-hotel-booking') . '</p>';
        echo '</div></div>';

        return;
    }

    echo '<div class="must-dashboard-health-list">';

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        echo '<div class="must-dashboard-health-item">';
        echo '<div class="must-dashboard-health-header">';
        echo '<strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong>';
        echo render_dashboard_status_badge((string) ($item['status'] ?? 'warning'));
        echo '</div>';
        echo '<p>' . \esc_html((string) ($item['message'] ?? '')) . '</p>';

        if (!empty($item['action_url'])) {
            $actionLabel = !empty($item['action_label'])
                ? (string) $item['action_label']
                : \__('Open settings', 'must-hotel-booking');
            echo '<p><a class="must-dashboard-inline-link" href="' . \esc_url((string) $item['action_url']) . '">' . \esc_html($actionLabel) . '</a></p>';
        }

        echo '</div>';
    }

    echo '</div>';
    echo '</div></div>';
}

/**
 * @param array<int, array<string, string>> $rows
 */
function render_dashboard_recent_reservations_panel(array $rows): void
{
    echo '<div class="postbox must-dashboard-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    render_dashboard_panel_heading(
        \__('Recent Reservations', 'must-hotel-booking'),
        \__('Latest bookings created or updated by staff and guests.', 'must-hotel-booking'),
        \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url') ? get_admin_reservations_page_url() : '',
        \__('View all', 'must-hotel-booking')
    );

    if (empty($rows)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No recent reservations were found.', 'must-hotel-booking') . '</p>';
        echo '</div></div>';

        return;
    }

    echo '<div class="must-dashboard-reservation-list">';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<article class="must-dashboard-reservation-card">';
        echo '<div class="must-dashboard-reservation-top">';
        echo '<div class="must-dashboard-reservation-copy">';
        echo '<a class="must-dashboard-reservation-booking" href="' . \esc_url((string) ($row['view_url'] ?? '')) . '">' . \esc_html((string) ($row['booking_id'] ?? '')) . '</a>';
        echo '<p class="must-dashboard-reservation-guest">' . \esc_html((string) ($row['guest'] ?? '')) . '</p>';
        echo '</div>';
        echo '<strong class="must-dashboard-reservation-total">' . \esc_html((string) ($row['total'] ?? '')) . '</strong>';
        echo '</div>';
        echo '<div class="must-dashboard-reservation-stay">';
        echo '<strong>' . \esc_html((string) ($row['accommodation'] ?? '')) . '</strong>';
        echo '<span>' . \esc_html(format_admin_dashboard_date((string) ($row['checkin'] ?? ''))) . ' <span class="must-dashboard-reservation-arrow" aria-hidden="true">&rarr;</span> ' . \esc_html(format_admin_dashboard_date((string) ($row['checkout'] ?? ''))) . '</span>';
        echo '</div>';
        echo '<div class="must-dashboard-reservation-footer">';
        echo '<div class="must-dashboard-reservation-badges">';
        echo render_dashboard_context_badge((string) ($row['status'] ?? ''));
        echo render_dashboard_context_badge((string) ($row['payment'] ?? ''));
        echo '</div>';
        echo '<div class="must-dashboard-reservation-actions">';
        echo '<a class="button button-small button-primary" href="' . \esc_url((string) ($row['view_url'] ?? '')) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a>';
        echo '<a class="button button-small" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</article>';
    }

    echo '</div>';
    echo '</div></div>';
}

/**
 * @param array<int, array<string, string>> $rows
 */
function render_dashboard_recent_activity_panel(array $rows): void
{
    echo '<div class="postbox must-dashboard-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    render_dashboard_panel_heading(
        \__('Recent Activity', 'must-hotel-booking'),
        \__('A short event trail for reservations, payments, and email operations.', 'must-hotel-booking')
    );

    if (empty($rows)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('Activity logging is enabled. New reservation, payment, and email events will appear here as they happen.', 'must-hotel-booking') . '</p>';
        echo '</div></div>';

        return;
    }

    echo '<div class="must-dashboard-activity-list">';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<div class="must-dashboard-activity-item">';
        echo '<div class="must-dashboard-activity-meta">';
        echo render_dashboard_status_badge((string) ($row['severity'] ?? 'info'), true);
        echo '<span class="must-dashboard-activity-time">' . \esc_html(format_admin_dashboard_datetime((string) ($row['created_at'] ?? ''))) . '</span>';
        echo '</div>';
        echo '<p>' . \esc_html((string) ($row['message'] ?? '')) . '</p>';

        if (!empty($row['reference'])) {
            echo '<p class="description">' . \esc_html((string) $row['reference']) . '</p>';
        }

        if (!empty($row['action_url'])) {
            echo '<p><a class="must-dashboard-inline-link" href="' . \esc_url((string) $row['action_url']) . '">' . \esc_html__('Open record', 'must-hotel-booking') . '</a></p>';
        }

        echo '</div>';
    }

    echo '</div>';
    echo '</div></div>';
}

/**
 * @param array<int, array<string, string>> $actions
 */
function render_dashboard_quick_actions_panel(array $actions): void
{
    echo '<div class="postbox must-dashboard-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    render_dashboard_panel_heading(
        \__('Quick Actions', 'must-hotel-booking'),
        \__('Shortcuts into the most-used operational screens.', 'must-hotel-booking')
    );

    if (empty($actions)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No quick actions are available.', 'must-hotel-booking') . '</p>';
        echo '</div></div>';

        return;
    }

    echo '<div class="must-dashboard-actions-grid">';

    foreach ($actions as $action) {
        if (!\is_array($action)) {
            continue;
        }

        $label = (string) ($action['label'] ?? '');
        echo '<a class="button button-secondary must-dashboard-action-button" href="' . \esc_url((string) ($action['url'] ?? '')) . '">';
        echo '<span class="dashicons ' . \esc_attr(get_dashboard_action_icon($label)) . '" aria-hidden="true"></span>';
        echo '<span>' . \esc_html($label) . '</span>';
        echo '</a>';
    }

    echo '</div>';
    echo '</div></div>';
}

function render_dashboard_status_badge(string $status, bool $compact = false): string
{
    $status = \sanitize_key($status);
    $map = [
        'ok' => ['label' => \__('OK', 'must-hotel-booking'), 'class' => 'is-ok'],
        'healthy' => ['label' => \__('OK', 'must-hotel-booking'), 'class' => 'is-ok'],
        'warning' => ['label' => \__('Warning', 'must-hotel-booking'), 'class' => 'is-warning'],
        'error' => ['label' => \__('Error', 'must-hotel-booking'), 'class' => 'is-error'],
        'info' => ['label' => \__('Info', 'must-hotel-booking'), 'class' => 'is-info'],
    ];
    $resolved = isset($map[$status]) ? $map[$status] : $map['info'];

    return '<span class="must-dashboard-status-badge ' . ($compact ? 'is-compact ' : '') . \esc_attr((string) $resolved['class']) . '">' . \esc_html((string) $resolved['label']) . '</span>';
}

/**
 * Handle dashboard quick-booking actions before admin page output starts.
 */
function maybe_handle_admin_dashboard_actions_early(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if ($page !== 'must-hotel-booking') {
        return;
    }

    ensure_admin_capability();

    if (\function_exists(__NAMESPACE__ . '\maybe_handle_admin_quick_booking_submission')) {
        maybe_handle_admin_quick_booking_submission();
    }
}

/**
 * Enqueue shared admin UI assets on all plugin admin pages.
 */
function enqueue_admin_ui_assets(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if (\strpos($page, 'must-hotel-booking') !== 0) {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-ui',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-ui.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_menu', __NAMESPACE__ . '\register_admin_menu');
\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_admin_dashboard_actions_early', 1);
\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_ui_assets');
