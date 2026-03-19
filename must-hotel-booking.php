<?php
/**
 * Plugin Name: MUST Hotel Booking
 * Description: Hotel booking management for room availability, reservations, and checkout workflows.
 * Version: 0.3.25
 * Author: MUST
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: must-hotel-booking
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!\defined('ABSPATH')) {
    exit;
}

\define('MUST_HOTEL_BOOKING_VERSION', '0.3.25');
\define('MUST_HOTEL_BOOKING_FILE', __FILE__);
\define('MUST_HOTEL_BOOKING_PATH', \plugin_dir_path(__FILE__));
\define('MUST_HOTEL_BOOKING_URL', \plugin_dir_url(__FILE__));
\define('MUST_HOTEL_BOOKING_BASENAME', \plugin_basename(__FILE__));

require_once MUST_HOTEL_BOOKING_PATH . 'includes/autoloader.php';
require_once MUST_HOTEL_BOOKING_PATH . 'includes/config.php';

\register_activation_hook(MUST_HOTEL_BOOKING_FILE, [\MustHotelBooking\Core\Plugin::class, 'activate']);
\register_deactivation_hook(MUST_HOTEL_BOOKING_FILE, [\MustHotelBooking\Core\Plugin::class, 'deactivate']);
\add_action('plugins_loaded', [\MustHotelBooking\Core\Plugin::class, 'maybeUpgradeDatabase'], 5);
\add_action('plugins_loaded', [\MustHotelBooking\Core\Plugin::class, 'initPlugin']);
