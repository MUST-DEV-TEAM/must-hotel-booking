<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\EmailLayoutEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Portal\PortalRouter;

final class SettingsDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public static function getData(): array
    {
        global $wpdb;

        $roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $ratePlanRepository = \MustHotelBooking\Engine\get_rate_plan_repository();
        $activityRepository = \MustHotelBooking\Engine\get_activity_repository();

        $tableChecks = [
            ['label' => 'Rooms', 'table_name' => $wpdb->prefix . 'must_rooms', 'exists' => $roomRepository->roomsTableExists()],
            ['label' => 'Room Types', 'table_name' => $wpdb->prefix . 'mhb_room_types', 'exists' => $inventoryRepository->roomTypesTableExists()],
            ['label' => 'Inventory Rooms', 'table_name' => $wpdb->prefix . 'mhb_rooms', 'exists' => $inventoryRepository->inventoryRoomsTableExists()],
            ['label' => 'Room Meta', 'table_name' => $wpdb->prefix . 'must_room_meta', 'exists' => $roomRepository->roomMetaTableExists()],
            ['label' => 'Guests', 'table_name' => $wpdb->prefix . 'must_guests', 'exists' => $guestRepository->guestsTableExists()],
            ['label' => 'Reservations', 'table_name' => $wpdb->prefix . 'must_reservations', 'exists' => $reservationRepository->reservationsTableExists()],
            ['label' => 'Pricing', 'table_name' => $wpdb->prefix . 'must_pricing', 'exists' => self::tableExists($wpdb->prefix . 'must_pricing')],
            ['label' => 'Availability', 'table_name' => $wpdb->prefix . 'must_availability', 'exists' => $availabilityRepository->availabilityTableExists()],
            ['label' => 'Locks', 'table_name' => $wpdb->prefix . 'mhb_inventory_locks', 'exists' => $inventoryRepository->inventoryLocksTableExists()],
            ['label' => 'Payments', 'table_name' => $wpdb->prefix . 'must_payments', 'exists' => $paymentRepository->paymentsTableExists()],
            ['label' => 'Activity Log', 'table_name' => $wpdb->prefix . 'must_activity_log', 'exists' => $activityRepository->activityTableExists()],
            ['label' => 'Taxes', 'table_name' => $wpdb->prefix . 'must_taxes', 'exists' => self::tableExists($wpdb->prefix . 'must_taxes')],
            ['label' => 'Coupons', 'table_name' => $wpdb->prefix . 'must_coupons', 'exists' => self::tableExists($wpdb->prefix . 'must_coupons')],
            ['label' => 'Cancellation Policies', 'table_name' => $wpdb->prefix . 'mhb_cancellation_policies', 'exists' => self::tableExists($wpdb->prefix . 'mhb_cancellation_policies')],
            ['label' => 'Rate Plans', 'table_name' => $wpdb->prefix . 'mhb_rate_plans', 'exists' => $ratePlanRepository->ratePlansTableExists()],
            ['label' => 'Room Type Rate Plans', 'table_name' => $wpdb->prefix . 'mhb_room_type_rate_plans', 'exists' => $ratePlanRepository->roomTypeRatePlansTableExists()],
            ['label' => 'Rate Plan Prices', 'table_name' => $wpdb->prefix . 'mhb_rate_plan_prices', 'exists' => $ratePlanRepository->ratePlanPricesTableExists()],
            ['label' => 'Seasons', 'table_name' => $wpdb->prefix . 'mhb_seasons', 'exists' => $ratePlanRepository->seasonsTableExists()],
            ['label' => 'Seasonal Prices', 'table_name' => $wpdb->prefix . 'mhb_seasonal_prices', 'exists' => $ratePlanRepository->seasonalPricesTableExists()],
        ];

        $tables = [];
        $criticalIssues = 0;
        $warnings = 0;

        foreach ($tableChecks as $check) {
            $exists = !empty($check['exists']);

            if (!$exists) {
                $criticalIssues++;
            }

            $tables[] = [
                'label' => (string) ($check['label'] ?? ''),
                'table_name' => (string) ($check['table_name'] ?? ''),
                'status' => $exists ? 'healthy' : 'error',
                'health' => $exists ? 'ok' : 'missing',
                'message' => $exists
                    ? \__('Table found.', 'must-hotel-booking')
                    : \__('Missing. Re-run installer or database migrations.', 'must-hotel-booking'),
            ];
        }

        $pages = [];

        foreach (ManagedPages::getHealthRows() as $pageRow) {
            if (!\is_array($pageRow)) {
                continue;
            }

            $health = (string) ($pageRow['health'] ?? '');
            $pageRow['status'] = \in_array($health, ['ok', 'disabled'], true)
                ? 'healthy'
                : ($health === 'warning' ? 'warning' : 'error');
            $pages[] = $pageRow;
        }

        foreach ($pages as $pageRow) {
            $health = (string) ($pageRow['health'] ?? '');

            if (\in_array($health, ['missing', 'invalid'], true) && !empty($pageRow['required'])) {
                $criticalIssues++;
            } elseif ($health === 'warning') {
                $warnings++;
            }
        }

        $cronHook = LockEngine::getCleanupCronHook();
        $nextCron = $cronHook !== '' ? \wp_next_scheduled($cronHook) : false;
        $cronScheduled = $nextCron !== false;

        if (!$cronScheduled) {
            $criticalIssues++;
        }

        $cron = [
            'status' => $cronScheduled ? 'healthy' : 'error',
            'health' => $cronScheduled ? 'ok' : 'missing',
            'message' => $cronScheduled
                ? \__('Recurring lock cleanup is scheduled.', 'must-hotel-booking')
                : \__('Recurring lock cleanup is not scheduled.', 'must-hotel-booking'),
            'next_run' => $cronScheduled && \is_numeric($nextCron)
                ? \wp_date('Y-m-d H:i:s', (int) $nextCron)
                : \__('Not scheduled', 'must-hotel-booking'),
            'hook' => $cronHook,
        ];

        $enabledMethods = \function_exists(__NAMESPACE__ . '\get_enabled_payment_methods')
            ? get_enabled_payment_methods()
            : [];
        $paymentCatalog = \function_exists(__NAMESPACE__ . '\get_payment_methods_catalog')
            ? get_payment_methods_catalog()
            : [];
        $enabledMethodLabels = [];

        foreach ($enabledMethods as $methodKey) {
            $enabledMethodLabels[] = isset($paymentCatalog[$methodKey]['label'])
                ? (string) $paymentCatalog[$methodKey]['label']
                : (string) $methodKey;
        }

        $stripeEnabled = \in_array('stripe', $enabledMethods, true);
        $stripeConfigured = PaymentEngine::isStripeCheckoutConfigured();
        $stripeWebhookSecretSet = PaymentEngine::getStripeWebhookSecret() !== '';

        if ($stripeEnabled && !$stripeConfigured) {
            $warnings++;
        }

        if ($stripeEnabled && !$stripeWebhookSecretSet) {
            $warnings++;
        }

        $payments = [
            'enabled_methods' => $enabledMethodLabels,
            'stripe_enabled' => $stripeEnabled,
            'stripe_configured' => $stripeConfigured,
            'stripe_webhook_secret_set' => $stripeWebhookSecretSet,
            'stripe_environment' => PaymentEngine::getSiteEnvironmentLabel(),
            'stripe_webhook_url' => PaymentEngine::getStripeWebhookUrl(),
            'default_payment_mode' => (string) MustBookingConfig::get_setting('default_payment_mode', 'guest_choice'),
            'deposit_required' => !empty(MustBookingConfig::get_setting('deposit_required', false)),
            'deposit_type' => (string) MustBookingConfig::get_setting('deposit_type', 'percentage'),
            'deposit_value' => (float) MustBookingConfig::get_setting('deposit_value', 0),
        ];

        $emailTemplates = EmailEngine::getTemplates();
        $emailSender = MustBookingConfig::get_email_from_email();
        $bookingRecipient = MustBookingConfig::get_booking_notification_email();
        $emailFailures = 0;

        foreach ($activityRepository->getRecentActivitiesByEventTypes(['email_failed'], 10) as $activity) {
            if (\is_array($activity)) {
                $emailFailures++;
            }
        }

        if (!\is_email($emailSender) || !\is_email($bookingRecipient)) {
            $warnings++;
        }

        if (empty($emailTemplates)) {
            $warnings++;
        }

        $emails = [
            'sender_name' => MustBookingConfig::get_email_from_name(),
            'sender_email' => $emailSender,
            'reply_to' => MustBookingConfig::get_email_reply_to(),
            'booking_recipient' => $bookingRecipient,
            'template_count' => \count($emailTemplates),
            'layout_type' => (string) (EmailLayoutEngine::getLayoutTypeLabels()[MustBookingConfig::get_email_layout_type()] ?? MustBookingConfig::get_email_layout_type()),
            'recent_failures' => $emailFailures,
            'is_configured' => \is_email($emailSender) && \is_email($bookingRecipient) && !empty($emailTemplates),
        ];

        $roomCount = $roomRepository->roomsTableExists() ? $roomRepository->countRooms() : 0;

        if ($roomCount <= 0) {
            $warnings++;
        }

        $environment = [
            'plugin_version' => \defined('MUST_HOTEL_BOOKING_VERSION') ? MUST_HOTEL_BOOKING_VERSION : '',
            'database_version' => (string) \get_option('must_hotel_booking_db_version', ''),
            'wordpress_version' => (string) \get_bloginfo('version'),
            'php_version' => \PHP_VERSION,
            'site_url' => (string) \home_url('/'),
            'site_environment' => PaymentEngine::getSiteEnvironmentLabel(),
            'room_count' => $roomCount,
            'email_template_count' => \count($emailTemplates),
            'hotel_name' => MustBookingConfig::get_hotel_name(),
            'currency' => MustBookingConfig::get_currency(),
            'timezone' => MustBookingConfig::get_timezone(),
        ];

        $overallStatus = 'healthy';

        if ($criticalIssues > 0) {
            $overallStatus = 'warning';
        }

        if ($criticalIssues > 2) {
            $overallStatus = 'error';
        }

        return [
            'overall_status' => $overallStatus,
            'critical_issues' => $criticalIssues,
            'warnings' => $warnings,
            'tables' => $tables,
            'pages' => $pages,
            'cron' => $cron,
            'payments' => $payments,
            'emails' => $emails,
            'environment' => $environment,
        ];
    }

    public static function getSystemReportText(): string
    {
        $data = self::getData();
        $lines = [
            'MUST Hotel Booking System Report',
            'Generated: ' . \current_time('mysql'),
            'Plugin Version: ' . (string) ($data['environment']['plugin_version'] ?? ''),
            'Database Version: ' . (string) ($data['environment']['database_version'] ?? ''),
            'WordPress Version: ' . (string) ($data['environment']['wordpress_version'] ?? ''),
            'PHP Version: ' . (string) ($data['environment']['php_version'] ?? ''),
            'Site URL: ' . (string) ($data['environment']['site_url'] ?? ''),
            'Hotel: ' . (string) ($data['environment']['hotel_name'] ?? ''),
            'Currency: ' . (string) ($data['environment']['currency'] ?? ''),
            'Timezone: ' . (string) ($data['environment']['timezone'] ?? ''),
            'Overall Status: ' . (string) ($data['overall_status'] ?? ''),
            'Critical Issues: ' . (int) ($data['critical_issues'] ?? 0),
            'Warnings: ' . (int) ($data['warnings'] ?? 0),
            '',
            '[Managed Pages]',
        ];

        foreach ((array) ($data['pages'] ?? []) as $pageRow) {
            if (!\is_array($pageRow)) {
                continue;
            }

            $lines[] = (string) ($pageRow['label'] ?? '') . ': ' . (string) ($pageRow['health'] ?? '') . ' - ' . (string) ($pageRow['message'] ?? '');
        }

        $lines[] = '';
        $lines[] = '[Portal Routing]';
        $lines[] = 'Portal Base Path: /' . \trim(PortalRouter::getPortalBasePath(), '/');
        $lines[] = 'Portal Login Path: /' . \trim(PortalRouter::getPortalLoginPath(), '/');

        $lines[] = '';
        $lines[] = '[Tables]';

        foreach ((array) ($data['tables'] ?? []) as $tableRow) {
            if (!\is_array($tableRow)) {
                continue;
            }

            $lines[] = (string) ($tableRow['label'] ?? '') . ': ' . (string) ($tableRow['status'] ?? '') . ' - ' . (string) ($tableRow['table_name'] ?? '');
        }

        return \implode("\n", $lines);
    }

    private static function tableExists(string $tableName): bool
    {
        if ($tableName === '') {
            return false;
        }

        global $wpdb;
        $match = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $tableName
            )
        );

        return \is_string($match) && $match !== '';
    }
}
