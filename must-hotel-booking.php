<?php
/**
 * Plugin Name: MUST Hotel Booking
 * Description: Hotel booking management for room availability, reservations, and checkout workflows.
 * Version: 0.3.26
 * Author: MUST
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: must-hotel-booking
 * Update URI: https://must-hotel-booking.invalid/plugin-update-source
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!\defined('ABSPATH')) {
    exit;
}

\define('MUST_HOTEL_BOOKING_VERSION', '0.3.26');
\define('MUST_HOTEL_BOOKING_FILE', __FILE__);
\define('MUST_HOTEL_BOOKING_PATH', \plugin_dir_path(__FILE__));
\define('MUST_HOTEL_BOOKING_URL', \plugin_dir_url(__FILE__));
\define('MUST_HOTEL_BOOKING_BASENAME', \plugin_basename(__FILE__));

if (!\defined('MUST_HOTEL_BOOKING_PLUGIN_SLUG')) {
    \define('MUST_HOTEL_BOOKING_PLUGIN_SLUG', 'must-hotel-booking');
}

if (!\defined('MUST_HOTEL_BOOKING_UPDATER_ENABLED')) {
    \define('MUST_HOTEL_BOOKING_UPDATER_ENABLED', true);
}

if (!\defined('MUST_HOTEL_BOOKING_GITHUB_REPOSITORY')) {
    \define('MUST_HOTEL_BOOKING_GITHUB_REPOSITORY', 'https://github.com/MUST-DEV-TEAM/must-hotel-booking');
}

if (!\defined('MUST_HOTEL_BOOKING_GITHUB_BRANCH')) {
    \define('MUST_HOTEL_BOOKING_GITHUB_BRANCH', 'main');
}

if (!\defined('MUST_HOTEL_BOOKING_GITHUB_RELEASE_ASSET_PATTERN')) {
    \define('MUST_HOTEL_BOOKING_GITHUB_RELEASE_ASSET_PATTERN', '/must-hotel-booking(?:-[0-9A-Za-z._-]+)?\\.zip$/i');
}

if (!\defined('MUST_HOTEL_BOOKING_GITHUB_TOKEN')) {
    \define('MUST_HOTEL_BOOKING_GITHUB_TOKEN', '');
}

require_once MUST_HOTEL_BOOKING_PATH . 'includes/autoloader.php';
require_once MUST_HOTEL_BOOKING_PATH . 'includes/config.php';

\register_activation_hook(MUST_HOTEL_BOOKING_FILE, [\MustHotelBooking\Core\Plugin::class, 'activate']);
\register_deactivation_hook(MUST_HOTEL_BOOKING_FILE, [\MustHotelBooking\Core\Plugin::class, 'deactivate']);
\add_action('plugins_loaded', [\MustHotelBooking\Core\Plugin::class, 'maybeUpgradeDatabase'], 5);
\add_action('plugins_loaded', [\MustHotelBooking\Core\Plugin::class, 'initPlugin']);
