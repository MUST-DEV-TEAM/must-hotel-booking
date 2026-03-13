<?php
/**
 * Plugin Name: MUST Hotel Booking
 * Description: Hotel booking management for room availability, reservations, and checkout workflows.
 * Version: 0.3.18
 * Author: MUST
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: must-hotel-booking
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace must_hotel_booking;

if (!\defined('ABSPATH')) {
    exit;
}

\define('MUST_HOTEL_BOOKING_VERSION', '0.3.18');
\define('MUST_HOTEL_BOOKING_FILE', __FILE__);
\define('MUST_HOTEL_BOOKING_PATH', \plugin_dir_path(__FILE__));
\define('MUST_HOTEL_BOOKING_URL', \plugin_dir_url(__FILE__));
\define('MUST_HOTEL_BOOKING_BASENAME', \plugin_basename(__FILE__));

/**
 * Load all plugin modules.
 */
function load_plugin_files(): void
{
    $files = [
        'includes/config.php',
        'includes/booking-status.php',
        'includes/design-system.php',
        'database/install-tables.php',
        'admin/quick-booking.php',
        'admin/dashboard.php',
        'admin/reservations.php',
        'admin/calendar.php',
        'admin/rooms.php',
        'admin/pricing.php',
        'admin/availability-rules.php',
        'admin/coupons.php',
        'admin/guests.php',
        'admin/payments.php',
        'admin/taxes.php',
        'admin/emails.php',
        'admin/settings.php',
        'frontend/booking-selection.php',
        'frontend/formatting.php',
        'frontend/checkout-country-directory.php',
        'frontend/booking-page.php',
        'frontend/accommodation-page.php',
        'frontend/checkout-page.php',
        'frontend/confirmation-page.php',
        'frontend/rooms-page.php',
        'frontend/single-room-page.php',
        'engine/availability-engine.php',
        'engine/reservation-engine.php',
        'engine/pricing-engine.php',
        'engine/lock-engine.php',
        'engine/payment-engine.php',
        'engine/email-engine.php',
        'elementor/booking-search-widget.php',
        'elementor/rooms-list-widget.php',
        'templates/room-card.php',
        'templates/booking-summary.php',
    ];

    foreach ($files as $file) {
        $path = MUST_HOTEL_BOOKING_PATH . $file;

        if (\file_exists($path)) {
            require_once $path;
        }
    }
}

/**
 * Run activation routines.
 */
function activate(): void
{
    install_tables();
    install_frontend_pages();

    if (\function_exists(__NAMESPACE__ . '\schedule_lock_cleanup_cron')) {
        schedule_lock_cleanup_cron();
    }

    \flush_rewrite_rules();
}

/**
 * Run deactivation routines.
 */
function deactivate(): void
{
    if (\function_exists(__NAMESPACE__ . '\unschedule_lock_cleanup_cron')) {
        unschedule_lock_cleanup_cron();
    }

    \flush_rewrite_rules();
}

/**
 * Run database updates when plugin version changes.
 */
function maybe_upgrade_database(): void
{
    $db_version = (string) \get_option('must_hotel_booking_db_version', '0.0.0');

    if (\version_compare($db_version, MUST_HOTEL_BOOKING_VERSION, '>=')) {
        return;
    }

    install_tables();
}

/**
 * Initialize plugin.
 */
function init_plugin(): void
{
    if (\function_exists(__NAMESPACE__ . '\maybe_sync_frontend_pages')) {
        maybe_sync_frontend_pages();
    }

    \do_action('must_hotel_booking/init');
}

load_plugin_files();

\register_activation_hook(MUST_HOTEL_BOOKING_FILE, __NAMESPACE__ . '\activate');
\register_deactivation_hook(MUST_HOTEL_BOOKING_FILE, __NAMESPACE__ . '\deactivate');
\add_action('plugins_loaded', __NAMESPACE__ . '\maybe_upgrade_database', 5);
\add_action('plugins_loaded', __NAMESPACE__ . '\init_plugin');
