<?php

namespace MustHotelBooking\Admin;

/**
 * Build Reports admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_reports_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-reports');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Render Reports admin page shell.
 */
function render_admin_reports_page(): void
{
    ensure_admin_capability();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Reports', 'must-hotel-booking') . '</h1>';
    echo '<div class="postbox" style="padding:16px;max-width:980px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Reporting Foundation', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Operational reporting will expand from this screen, but current live booking, payment, guest, and availability data already lives in the plugin. Use the links below to move into the existing operational views while the dedicated report modules are still being built on top of them.', 'must-hotel-booking') . '</p>';
    echo '<p>';
    echo '<a class="button button-primary" href="' . \esc_url(get_admin_dashboard_page_url()) . '">' . \esc_html__('Open Dashboard', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_guests_page_url()) . '">' . \esc_html__('Open Guests', 'must-hotel-booking') . '</a>';
    echo '</p>';
    echo '</div>';
    echo '</div>';
}
