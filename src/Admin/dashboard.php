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
 * Render a placeholder admin page shell.
 */
function render_admin_placeholder_page(string $title): void
{
    ensure_admin_capability();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html($title) . '</h1>';
    echo '<p>' . \esc_html__('This screen is a placeholder and will be implemented in a future iteration.', 'must-hotel-booking') . '</p>';
    echo '</div>';
}

/**
 * Register top-level and submenu admin pages.
 */
function register_admin_menu(): void
{
    $capability = get_admin_capability();
    $parent_slug = 'must-hotel-booking';

    \add_menu_page(
        'MUST Hotel Booking',
        'MUST Hotel Booking',
        $capability,
        $parent_slug,
        __NAMESPACE__ . '\render_admin_dashboard_page',
        'dashicons-calendar',
        26
    );

    \add_submenu_page(
        $parent_slug,
        'Dashboard',
        'Dashboard',
        $capability,
        $parent_slug,
        __NAMESPACE__ . '\render_admin_dashboard_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Reservations',
        'Reservations',
        $capability,
        'must-hotel-booking-reservations',
        __NAMESPACE__ . '\render_admin_reservations_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Calendar',
        'Calendar',
        $capability,
        'must-hotel-booking-calendar',
        __NAMESPACE__ . '\render_admin_calendar_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Rooms',
        'Rooms',
        $capability,
        'must-hotel-booking-rooms',
        __NAMESPACE__ . '\render_admin_rooms_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Rates & Pricing',
        'Rates & Pricing',
        $capability,
        'must-hotel-booking-pricing',
        __NAMESPACE__ . '\render_admin_pricing_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Rate Plans',
        'Rate Plans',
        $capability,
        'must-hotel-booking-rate-plans',
        __NAMESPACE__ . '\render_admin_rate_plans_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Availability Rules',
        'Availability Rules',
        $capability,
        'must-hotel-booking-availability-rules',
        __NAMESPACE__ . '\render_admin_availability_rules_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Coupons',
        'Coupons',
        $capability,
        'must-hotel-booking-coupons',
        __NAMESPACE__ . '\render_admin_coupons_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Guests',
        'Guests',
        $capability,
        'must-hotel-booking-guests',
        __NAMESPACE__ . '\render_admin_guests_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Payments',
        'Payments',
        $capability,
        'must-hotel-booking-payments',
        __NAMESPACE__ . '\render_admin_payments_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Taxes & Fees',
        'Taxes & Fees',
        $capability,
        'must-hotel-booking-taxes',
        __NAMESPACE__ . '\render_admin_taxes_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Emails',
        'Emails',
        $capability,
        'must-hotel-booking-emails',
        __NAMESPACE__ . '\render_admin_emails_page'
    );

    \add_submenu_page(
        $parent_slug,
        'Settings',
        'Settings',
        $capability,
        'must-hotel-booking-settings',
        __NAMESPACE__ . '\render_admin_settings_page'
    );
}

/**
 * Get reservations table name.
 */
function get_dashboard_reservations_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_reservations';
}

/**
 * Check if reservations table exists.
 */
function does_dashboard_reservations_table_exist(): bool
{
    global $wpdb;

    $table_name = get_dashboard_reservations_table_name();
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    return \is_string($table_exists) && $table_exists !== '';
}

/**
 * Get date context for dashboard calculations.
 *
 * @return array{today: string, month_start: string, next_month_start: string}
 */
function get_dashboard_date_context(): array
{
    $now = new \DateTimeImmutable(\current_time('mysql'));

    return [
        'today' => $now->format('Y-m-d'),
        'month_start' => $now->modify('first day of this month')->format('Y-m-01'),
        'next_month_start' => $now->modify('first day of next month')->format('Y-m-01'),
    ];
}

/**
 * Get dashboard metric counters.
 *
 * @return array{bookings_today: int, upcoming_checkins: int, upcoming_checkouts: int, total_bookings_this_month: int}
 */
function get_dashboard_metrics(): array
{
    $metrics = [
        'bookings_today' => 0,
        'upcoming_checkins' => 0,
        'upcoming_checkouts' => 0,
        'total_bookings_this_month' => 0,
    ];

    if (!does_dashboard_reservations_table_exist()) {
        return $metrics;
    }

    global $wpdb;

    $table_name = get_dashboard_reservations_table_name();
    $date_context = get_dashboard_date_context();

    $metrics['bookings_today'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$table_name}
            WHERE DATE(created_at) = %s
                AND status NOT IN ('cancelled', 'blocked', 'expired', 'payment_failed', 'pending_payment')",
            $date_context['today']
        )
    );

    $metrics['upcoming_checkins'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$table_name}
            WHERE checkin > %s
                AND status NOT IN ('cancelled', 'blocked', 'expired', 'payment_failed', 'pending_payment')",
            $date_context['today']
        )
    );

    $metrics['upcoming_checkouts'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$table_name}
            WHERE checkout > %s
                AND status NOT IN ('cancelled', 'blocked', 'expired', 'payment_failed', 'pending_payment')",
            $date_context['today']
        )
    );

    $metrics['total_bookings_this_month'] = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$table_name}
            WHERE created_at >= %s
                AND created_at < %s
                AND status NOT IN ('cancelled', 'blocked', 'expired', 'payment_failed', 'pending_payment')",
            $date_context['month_start'],
            $date_context['next_month_start']
        )
    );

    return $metrics;
}

/**
 * Get next reservations for the dashboard summary table.
 *
 * @return array<int, array<string, mixed>>
 */
function get_dashboard_next_reservations(int $limit = 10): array
{
    if (!does_dashboard_reservations_table_exist()) {
        return [];
    }

    $limit = \max(1, \min(50, $limit));

    global $wpdb;

    $table_name = get_dashboard_reservations_table_name();
    $date_context = get_dashboard_date_context();
    $sql = $wpdb->prepare(
        "SELECT
            id,
            booking_id,
            room_id,
            checkin,
            checkout,
            guests,
            status,
            total_price,
            created_at
        FROM {$table_name}
        WHERE checkin >= %s
            AND status NOT IN ('cancelled', 'blocked', 'expired', 'payment_failed', 'pending_payment')
        ORDER BY checkin ASC, checkout ASC, id ASC
        LIMIT %d",
        $date_context['today'],
        $limit
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Format booking ID for dashboard output.
 */
function format_dashboard_booking_id(array $reservation): string
{
    $booking_id = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';

    if ($booking_id !== '') {
        return $booking_id;
    }

    $reservation_id = isset($reservation['id']) ? (int) $reservation['id'] : 0;

    if ($reservation_id <= 0) {
        return '';
    }

    return 'RES-' . $reservation_id;
}

/**
 * Render dashboard admin page.
 */
function render_admin_dashboard_page(): void
{
    ensure_admin_capability();

    $quick_booking_state = [
        'errors' => [],
        'form' => null,
    ];

    if (\function_exists(__NAMESPACE__ . '\maybe_handle_admin_quick_booking_submission')) {
        $quick_booking_state = maybe_handle_admin_quick_booking_submission();
    }

    $quick_booking_errors = isset($quick_booking_state['errors']) && \is_array($quick_booking_state['errors'])
        ? $quick_booking_state['errors']
        : [];
    $quick_booking_form = isset($quick_booking_state['form']) && \is_array($quick_booking_state['form'])
        ? $quick_booking_state['form']
        : null;

    $metrics = get_dashboard_metrics();
    $next_reservations = get_dashboard_next_reservations(10);
    $table_exists = does_dashboard_reservations_table_exist();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Dashboard', 'must-hotel-booking') . '</h1>';

    if (\function_exists(__NAMESPACE__ . '\render_dashboard_quick_booking_notice_from_query')) {
        render_dashboard_quick_booking_notice_from_query();
    }

    if (!$table_exists) {
        echo '<div class="notice notice-warning"><p>' . \esc_html__('Reservations table was not found. Please reactivate the plugin to create database tables.', 'must-hotel-booking') . '</p></div>';
    }

    echo '<h2>' . \esc_html__('Booking Overview', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped" style="max-width: 860px;">';
    echo '<tbody>';
    echo '<tr><th>' . \esc_html__('Bookings today', 'must-hotel-booking') . '</th><td><strong>' . \esc_html((string) $metrics['bookings_today']) . '</strong></td></tr>';
    echo '<tr><th>' . \esc_html__('Upcoming check-ins', 'must-hotel-booking') . '</th><td><strong>' . \esc_html((string) $metrics['upcoming_checkins']) . '</strong></td></tr>';
    echo '<tr><th>' . \esc_html__('Upcoming check-outs', 'must-hotel-booking') . '</th><td><strong>' . \esc_html((string) $metrics['upcoming_checkouts']) . '</strong></td></tr>';
    echo '<tr><th>' . \esc_html__('Total bookings this month', 'must-hotel-booking') . '</th><td><strong>' . \esc_html((string) $metrics['total_bookings_this_month']) . '</strong></td></tr>';
    echo '</tbody>';
    echo '</table>';

    if (\function_exists(__NAMESPACE__ . '\render_admin_quick_booking_panel')) {
        render_admin_quick_booking_panel($quick_booking_form, $quick_booking_errors);
    }

    echo '<h2 style="margin-top: 24px;">' . \esc_html__('Next 10 Reservations', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Room ID', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-in', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-out', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Guests', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Total', 'must-hotel-booking') . '</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if (empty($next_reservations)) {
        echo '<tr><td colspan="7">' . \esc_html__('No upcoming reservations found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($next_reservations as $reservation) {
            if (!\is_array($reservation)) {
                continue;
            }

            $booking_id = format_dashboard_booking_id($reservation);
            $room_id = isset($reservation['room_id']) ? (int) $reservation['room_id'] : 0;
            $checkin = isset($reservation['checkin']) ? (string) $reservation['checkin'] : '';
            $checkout = isset($reservation['checkout']) ? (string) $reservation['checkout'] : '';
            $guests = isset($reservation['guests']) ? (int) $reservation['guests'] : 0;
            $status = isset($reservation['status']) ? (string) $reservation['status'] : '';
            $total = isset($reservation['total_price']) ? (float) $reservation['total_price'] : 0.0;

            echo '<tr>';
            echo '<td>' . \esc_html($booking_id) . '</td>';
            echo '<td>' . \esc_html((string) $room_id) . '</td>';
            echo '<td>' . \esc_html($checkin) . '</td>';
            echo '<td>' . \esc_html($checkout) . '</td>';
            echo '<td>' . \esc_html((string) $guests) . '</td>';
            echo '<td>' . \esc_html($status) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n($total, 2)) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
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
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_menu', __NAMESPACE__ . '\register_admin_menu');
\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_admin_dashboard_actions_early', 1);
\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_ui_assets');
