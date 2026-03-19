<?php

namespace MustHotelBooking\Admin;

/**
 * Get capability required for plugin admin pages.
 */
function get_admin_capability(): string
{
    return 'manage_options';
}

/**
 * Ensure the current user can access plugin admin pages.
 */
function ensure_admin_capability(): void
{
    if (!\current_user_can(get_admin_capability())) {
        \wp_die(\esc_html__('You do not have permission to access this page.', 'must-hotel-booking'));
    }
}

/**
 * Register top-level and submenu admin pages.
 */
function register_admin_menu(): void
{
    $capability = get_admin_capability();
    $parentSlug = 'must-hotel-booking';

    \add_menu_page(
        'MUST Hotel Booking',
        'MUST Hotel Booking',
        $capability,
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
            $capability,
            (string) $page['slug'],
            (string) $page['callback']
        );
    }

    foreach (get_hidden_admin_menu_pages() as $page) {
        \add_submenu_page(
            null,
            (string) $page['title'],
            (string) $page['menu_title'],
            $capability,
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

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Dashboard', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Front desk, reservations, payments, and configuration issues are surfaced here first so staff can move straight into the operational page that needs action.', 'must-hotel-booking') . '</p>';

    if (\function_exists(__NAMESPACE__ . '\render_dashboard_quick_booking_notice_from_query')) {
        render_dashboard_quick_booking_notice_from_query();
    }

    if (!$reservationsTableExists) {
        echo '<div class="notice notice-warning"><p>' . \esc_html__('Reservations table was not found. Reactivate the plugin to create the operational booking tables.', 'must-hotel-booking') . '</p></div>';
    }

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
        echo '<div id="must-dashboard-quick-booking" style="margin-top:24px;">';
        render_admin_quick_booking_panel($quickBookingForm, $quickBookingErrors);
        echo '</div>';
    }

    echo '</div>';
}

/**
 * @param array<int, array<string, string>> $kpis
 */
function render_dashboard_kpi_cards(array $kpis): void
{
    echo '<div class="must-dashboard-kpis">';

    foreach ($kpis as $card) {
        if (!\is_array($card)) {
            continue;
        }

        $url = isset($card['url']) ? (string) $card['url'] : '';
        $tag = $url !== '' ? 'a' : 'div';

        echo '<' . $tag . ' class="must-dashboard-kpi-card"';

        if ($url !== '') {
            echo ' href="' . \esc_url($url) . '"';
        }

        echo '>';
        echo '<span class="must-dashboard-kpi-label">' . \esc_html((string) ($card['label'] ?? '')) . '</span>';
        echo '<strong class="must-dashboard-kpi-value">' . \esc_html((string) ($card['value'] ?? '0')) . '</strong>';

        if (!empty($card['descriptor'])) {
            echo '<span class="must-dashboard-kpi-descriptor">' . \esc_html((string) $card['descriptor']) . '</span>';
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
    echo '<h2>' . \esc_html__('Needs Attention', 'must-hotel-booking') . '</h2>';

    if (empty($items)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No urgent operational issues are currently detected.', 'must-hotel-booking') . '</p>';
        echo '</div></div>';

        return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr><th>' . \esc_html__('Severity', 'must-hotel-booking') . '</th><th>' . \esc_html__('Issue', 'must-hotel-booking') . '</th><th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th><th>' . \esc_html__('Action', 'must-hotel-booking') . '</th></tr></thead>';
    echo '<tbody>';

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . render_dashboard_status_badge((string) ($item['severity'] ?? 'info'), true) . '</td>';
        echo '<td><strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong><br /><span class="description">' . \esc_html((string) ($item['message'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html((string) ($item['reference'] ?? '-')) . '</td>';
        echo '<td>';

        if (!empty($item['action_url'])) {
            echo '<a class="button button-small" href="' . \esc_url((string) $item['action_url']) . '">' . \esc_html__('Open', 'must-hotel-booking') . '</a>';
        } else {
            echo '&ndash;';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div></div>';
}

/**
 * @param array<int, array<string, string>> $items
 */
function render_dashboard_health_panel(array $items): void
{
    echo '<div class="postbox must-dashboard-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('System Health', 'must-hotel-booking') . '</h2>';

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
            echo '<p><a href="' . \esc_url((string) $item['action_url']) . '">' . \esc_html__('Open settings', 'must-hotel-booking') . '</a></p>';
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
    echo '<h2>' . \esc_html__('Recent Reservations', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-in', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-out', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Payment', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Total', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="9">' . \esc_html__('No recent reservations were found.', 'must-hotel-booking') . '</td></tr>';
        echo '</tbody></table>';
        echo '</div></div>';

        return;
    }

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . \esc_html((string) ($row['booking_id'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($row['guest'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($row['accommodation'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($row['checkin'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($row['checkout'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($row['status'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($row['payment'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($row['total'] ?? '')) . '</td>';
        echo '<td>';
        echo '<a class="button button-small" href="' . \esc_url((string) ($row['view_url'] ?? '')) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a> ';
        echo '<a class="button button-small" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div></div>';
}

/**
 * @param array<int, array<string, string>> $rows
 */
function render_dashboard_recent_activity_panel(array $rows): void
{
    echo '<div class="postbox must-dashboard-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('Recent Activity', 'must-hotel-booking') . '</h2>';

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
        echo '<span class="must-dashboard-activity-time">' . \esc_html((string) ($row['created_at'] ?? '')) . '</span>';
        echo '</div>';
        echo '<p>' . \esc_html((string) ($row['message'] ?? '')) . '</p>';

        if (!empty($row['reference'])) {
            echo '<p class="description">' . \esc_html((string) $row['reference']) . '</p>';
        }

        if (!empty($row['action_url'])) {
            echo '<p><a href="' . \esc_url((string) $row['action_url']) . '">' . \esc_html__('Open record', 'must-hotel-booking') . '</a></p>';
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
    echo '<h2>' . \esc_html__('Quick Actions', 'must-hotel-booking') . '</h2>';

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

        echo '<a class="button button-secondary must-dashboard-action-button" href="' . \esc_url((string) ($action['url'] ?? '')) . '">' . \esc_html((string) ($action['label'] ?? '')) . '</a>';
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
