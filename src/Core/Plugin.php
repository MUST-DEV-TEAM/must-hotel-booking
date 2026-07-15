<?php

namespace MustHotelBooking\Core;

final class Plugin
{
    public static function activate(): void
    {
        \MustHotelBooking\Database\install_tables();
        \MustHotelBooking\Core\MustBookingConfig::maybe_migrate_payment_policy_defaults();
        (new \MustHotelBooking\Database\DefaultInventoryUnitSyncService())->maybeRunBackfill();
        \MustHotelBooking\Core\ManagedPages::install();
        \MustHotelBooking\Core\StaffAccess::syncRoleCapabilities();

        \MustHotelBooking\Engine\LockEngine::scheduleCleanupCron();

        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::registerHooks();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::scheduleCron();

        \MustHotelBooking\Portal\PortalBootstrap::registerRewriteRules();

        \MustHotelBooking\Provider\Clock\ClockSyncScheduler::registerHooks();
        \MustHotelBooking\Provider\Clock\ClockSyncScheduler::scheduleCron();

        \flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        \MustHotelBooking\Engine\LockEngine::unscheduleCleanupCron();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::unscheduleCron();
        \MustHotelBooking\Provider\Clock\ClockSyncScheduler::unscheduleCron();

        \flush_rewrite_rules();
    }

    public static function maybeUpgradeDatabase(): void
    {
        $db_version = (string) \get_option('must_hotel_booking_db_version', '0.0.0');

        if (\version_compare($db_version, MUST_HOTEL_BOOKING_VERSION, '>=')) {
            \MustHotelBooking\Database\ensure_payment_release_schema();
            \MustHotelBooking\Database\ensure_public_access_schema();
            \MustHotelBooking\Database\ensure_clock_fulfilment_schema();
            \MustHotelBooking\Database\ensure_confirmation_integrity_schema();
            return;
        }

        \MustHotelBooking\Database\install_tables();
        \MustHotelBooking\Core\MustBookingConfig::maybe_migrate_payment_policy_defaults();

        \MustHotelBooking\Engine\LockEngine::scheduleCleanupCron();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::scheduleCron();
        \MustHotelBooking\Provider\Clock\ClockSyncScheduler::scheduleCron();
    }

    public static function initPlugin(): void
    {
        (new \MustHotelBooking\Database\DefaultInventoryUnitSyncService())->maybeRunBackfill();
        \MustHotelBooking\Core\MustBookingConfig::maybe_migrate_payment_policy_defaults();
        \MustHotelBooking\Core\ManagedPages::sync();
        \MustHotelBooking\Core\StaffAccess::syncRoleCapabilities();
        \MustHotelBooking\Core\Updater::boot();
        \MustHotelBooking\Provider\ProviderManager::registerDefaultProviders();
        \MustHotelBooking\Core\PluginSupportWidget::registerHooks();
        \MustHotelBooking\Core\SupportDiagnosticsEndpoint::registerHooks();
        \MustHotelBooking\Core\ActivityLogger::registerHooks();
        \MustHotelBooking\Core\PublicCallbackUrl::registerHooks();

        \MustHotelBooking\Engine\LockEngine::registerHooks();
        \MustHotelBooking\Engine\LockEngine::scheduleCleanupCron();

        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::registerHooks();
        \MustHotelBooking\Provider\Sync\ProviderSyncJobRunner::scheduleCron();

        \MustHotelBooking\Provider\Clock\ClockSyncScheduler::registerHooks();
        \MustHotelBooking\Provider\Clock\ClockSyncScheduler::scheduleCron();

        \MustHotelBooking\Engine\PaymentEngine::registerHooks();
        \MustHotelBooking\Provider\Clock\ClockPaymentAccountingService::registerHooks();
        \MustHotelBooking\Provider\Clock\ClockInboundSyncController::registerHooks();
        \MustHotelBooking\Engine\AvailabilityAjaxController::registerHooks();
        \MustHotelBooking\Engine\EmailEngine::registerHooks();
        \MustHotelBooking\Portal\PortalBootstrap::registerHooks();
        \MustHotelBooking\Frontend\ClockWbeFrontend::registerHooks();

        \do_action('must_hotel_booking/init');
    }
}
