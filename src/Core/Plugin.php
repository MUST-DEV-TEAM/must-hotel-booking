<?php

namespace MustHotelBooking\Core;

final class Plugin
{
    public static function activate(): void
    {
        \MustHotelBooking\Database\install_tables();
        \MustHotelBooking\Frontend\install_frontend_pages();

        if (\function_exists('\MustHotelBooking\Engine\schedule_lock_cleanup_cron')) {
            \MustHotelBooking\Engine\schedule_lock_cleanup_cron();
        }

        \flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        if (\function_exists('\MustHotelBooking\Engine\unschedule_lock_cleanup_cron')) {
            \MustHotelBooking\Engine\unschedule_lock_cleanup_cron();
        }

        \flush_rewrite_rules();
    }

    public static function maybeUpgradeDatabase(): void
    {
        $db_version = (string) \get_option('must_hotel_booking_db_version', '0.0.0');

        if (\version_compare($db_version, MUST_HOTEL_BOOKING_VERSION, '>=')) {
            return;
        }

        \MustHotelBooking\Database\install_tables();
    }

    public static function initPlugin(): void
    {
        if (\function_exists('\MustHotelBooking\Frontend\maybe_sync_frontend_pages')) {
            \MustHotelBooking\Frontend\maybe_sync_frontend_pages();
        }

        \do_action('must_hotel_booking/init');
    }
}
