<?php

namespace MustHotelBooking\Core;

final class Plugin
{
    public static function activate(): void
    {
        \MustHotelBooking\Database\install_tables();
        (new \MustHotelBooking\Database\DefaultInventoryUnitSyncService())->maybeRunBackfill();
        \MustHotelBooking\Core\ManagedPages::install();
        \MustHotelBooking\Core\StaffAccess::syncRoleCapabilities();
        \MustHotelBooking\Engine\LockEngine::scheduleCleanupCron();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::registerHooks();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::scheduleCron();
        \MustHotelBooking\Portal\PortalBootstrap::registerRewriteRules();
        \MustHotelBooking\Provider\Clock\ClockReservationAutoSyncScheduler::registerHooks();
        \MustHotelBooking\Provider\Clock\ClockReservationAutoSyncScheduler::scheduleCron();
        \flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        \MustHotelBooking\Engine\LockEngine::unscheduleCleanupCron();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::unscheduleCron();
        \MustHotelBooking\Provider\Clock\ClockReservationAutoSyncScheduler::unscheduleCron();

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
        (new \MustHotelBooking\Database\DefaultInventoryUnitSyncService())->maybeRunBackfill();
        \MustHotelBooking\Core\ManagedPages::sync();
        \MustHotelBooking\Core\StaffAccess::syncRoleCapabilities();
        \MustHotelBooking\Core\Updater::boot();
        \MustHotelBooking\Provider\ProviderManager::registerDefaultProviders();
        \MustHotelBooking\Core\PluginSupportWidget::registerHooks();
        \MustHotelBooking\Core\SupportDiagnosticsEndpoint::registerHooks();
        \MustHotelBooking\Core\ActivityLogger::registerHooks();
        \MustHotelBooking\Engine\LockEngine::registerHooks();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::registerHooks();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::scheduleCron();
        \MustHotelBooking\Provider\Clock\ClockReservationAutoSyncScheduler::registerHooks();
        \MustHotelBooking\Provider\Clock\ClockReservationAutoSyncScheduler::scheduleCron();
        \MustHotelBooking\Engine\PaymentEngine::registerHooks();
        \MustHotelBooking\Provider\Clock\ClockInboundSyncController::registerHooks();
        \MustHotelBooking\Engine\AvailabilityAjaxController::registerHooks();
        \MustHotelBooking\Engine\EmailEngine::registerHooks();
        \MustHotelBooking\Portal\PortalBootstrap::registerHooks();
        \MustHotelBooking\Frontend\ClockWbeFrontend::registerHooks();

        \do_action('must_hotel_booking/init');
    }
}
