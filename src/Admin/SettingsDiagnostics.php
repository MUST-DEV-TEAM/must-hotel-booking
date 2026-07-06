<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\Updater;
use MustHotelBooking\Engine\BookingAbuseProtection;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\EmailLayoutEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Provider\Clock\ClockConnectionDiagnostic;
use MustHotelBooking\Provider\Clock\ClockCatalogService;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;
use MustHotelBooking\Provider\Sync\ProviderSyncJobRunner;
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
            ['label' => 'Provider Mappings', 'table_name' => $wpdb->prefix . 'mhb_provider_mappings', 'exists' => self::tableExists($wpdb->prefix . 'mhb_provider_mappings')],
            ['label' => 'Provider Request Logs', 'table_name' => $wpdb->prefix . 'mhb_provider_request_logs', 'exists' => self::tableExists($wpdb->prefix . 'mhb_provider_request_logs')],
            ['label' => 'Provider Sync Jobs', 'table_name' => $wpdb->prefix . 'mhb_provider_sync_jobs', 'exists' => self::tableExists($wpdb->prefix . 'mhb_provider_sync_jobs')],
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
        $pokpayEnabled = \in_array('pokpay', $enabledMethods, true);
        $pokpayConfigured = PaymentEngine::isPokPayConfigured();
        $paymentPolicy = PaymentEngine::getPublicCheckoutPaymentPolicy();
        $lastPublicBookingPayment = self::getLastPublicBookingPaymentSummary();

        if ($stripeEnabled && !$stripeConfigured) {
            $warnings++;
        }

        if ($stripeEnabled && !$stripeWebhookSecretSet) {
            $warnings++;
        }

        if ($pokpayEnabled && !$pokpayConfigured) {
            $warnings++;
        }

        $payments = [
            'enabled_methods' => $enabledMethodLabels,
            'stripe_enabled' => $stripeEnabled,
            'stripe_configured' => $stripeConfigured,
            'stripe_webhook_secret_set' => $stripeWebhookSecretSet,
            'stripe_environment' => PaymentEngine::getSiteEnvironmentLabel(),
            'stripe_webhook_url' => PaymentEngine::getStripeWebhookUrl(),
            'pokpay_enabled' => $pokpayEnabled,
            'pokpay_configured' => $pokpayConfigured,
            'pokpay_environment' => PaymentEngine::getPokPayApiEnvironment(),
            'pokpay_webhook_url' => MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook'),
            'default_payment_mode' => (string) MustBookingConfig::get_setting('default_payment_mode', 'pokpay'),
            'pay_at_hotel_enabled' => MustBookingConfig::is_pay_at_hotel_enabled(),
            'public_checkout_payment_policy' => (string) ($paymentPolicy['public_checkout_payment_policy'] ?? ''),
            'enabled_online_methods' => (array) ($paymentPolicy['enabled_online_methods'] ?? []),
            'public_offline_payment_allowed' => !empty($paymentPolicy['public_offline_payment_allowed']),
            'backend_rejects_disabled_pay_at_hotel' => !empty($paymentPolicy['backend_rejects_disabled_pay_at_hotel']),
            'payment_policy_configuration_error' => (string) ($paymentPolicy['configuration_error'] ?? ''),
            'last_public_booking_payment_method' => (string) ($lastPublicBookingPayment['payment_method'] ?? ''),
            'last_public_booking_payment_status' => (string) ($lastPublicBookingPayment['payment_status'] ?? ''),
            'checkout_price_breakdown_mode' => MustBookingConfig::get_checkout_price_breakdown_mode(),
            'deposit_required' => !empty(MustBookingConfig::get_setting('deposit_required', false)),
            'deposit_type' => (string) MustBookingConfig::get_setting('deposit_type', 'percentage'),
            'deposit_value' => (float) MustBookingConfig::get_setting('deposit_value', 0),
        ];

        $updater = Updater::getStatus();

        if (empty($updater['version_consistent']) || empty($updater['asset_pattern_strict'])) {
            $warnings++;
        }

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
            'email_price_breakdown_mode' => MustBookingConfig::get_email_price_breakdown_mode(),
            'recent_failures' => $emailFailures,
            'is_configured' => \is_email($emailSender) && \is_email($bookingRecipient) && !empty($emailTemplates),
        ];
        $recentBlockedAttempts = [];

        foreach ($activityRepository->getRecentActivitiesByEventTypes([BookingAbuseProtection::getBlockedEventType()], 10) as $activity) {
            if (!\is_array($activity)) {
                continue;
            }

            $context = self::decodeContext((string) ($activity['context_json'] ?? ''));
            $recentBlockedAttempts[] = [
                'created_at' => (string) ($activity['created_at'] ?? ''),
                'message' => (string) ($activity['message'] ?? ''),
                'actor_ip' => (string) ($activity['actor_ip'] ?? ''),
                'reference' => (string) ($activity['reference'] ?? ''),
                'reason_code' => (string) ($context['reason_code'] ?? ''),
                'reason_label' => (string) ($context['reason_label'] ?? ''),
                'surface' => (string) ($context['surface'] ?? ''),
                'email' => (string) ($context['submitted_email'] ?? ''),
                'payment_method' => (string) ($context['payment_method'] ?? ''),
            ];
        }

        $antiAbuse = [
            'enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_enabled', false)),
            'honeypot_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_honeypot_enabled', false)),
            'minimum_submit_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_min_submit_enabled', false)),
            'minimum_submit_seconds' => (int) MustBookingConfig::get_setting('anti_abuse_min_submit_seconds', 5),
            'throttle_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_throttle_enabled', false)),
            'max_attempts' => (int) MustBookingConfig::get_setting('anti_abuse_max_attempts', 5),
            'window_minutes' => (int) MustBookingConfig::get_setting('anti_abuse_window_minutes', 10),
            'block_duration_minutes' => (int) MustBookingConfig::get_setting('anti_abuse_block_duration_minutes', 30),
            'logging_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_logging_enabled', false)),
            'recent_blocked_total' => \count($recentBlockedAttempts),
            'recent_blocked_attempts' => $recentBlockedAttempts,
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
            'public_callback_base_url' => MustBookingConfig::get_public_callback_base_url(),
            'site_environment' => PaymentEngine::getSiteEnvironmentLabel(),
            'room_count' => $roomCount,
            'email_template_count' => \count($emailTemplates),
            'hotel_name' => MustBookingConfig::get_hotel_name(),
            'currency' => MustBookingConfig::get_currency(),
            'timezone' => MustBookingConfig::get_timezone(),
            'date_picker_calendar_layout' => MustBookingConfig::get_date_picker_calendar_layout(),
        ];

        $providerSummary = ClockConnectionDiagnostic::getConfigSummary();
        $configuredMode = (string) ($providerSummary['provider_mode'] ?? ProviderManager::getConfiguredMode());
        $activeBookingProvider = (string) ($providerSummary['active_booking_provider'] ?? ProviderManager::activeKey());
        $modeWarning = '';

        if ($configuredMode !== $activeBookingProvider) {
            $modeWarning = \sprintf(
                /* translators: 1: configured provider mode, 2: active runtime provider. */
                \__('Configured provider is %1$s, but active public booking runtime is %2$s because the configured provider is not booking-ready.', 'must-hotel-booking'),
                $configuredMode,
                $activeBookingProvider
            );
            $warnings++;
        }

        if (!empty($providerSummary['clock_enabled']) && empty($providerSummary['clock_direct_api_configured'])) {
            $warnings++;
        }

        if ((string) $configuredMode === 'clock' && empty($providerSummary['clock_direct_public_booking_ready'])) {
            $warnings++;
        }

        $syncJobs = new ProviderSyncJobRepository();
        $requestLogs = new ProviderRequestLogRepository();
        $catalogSummary = ClockCatalogService::getCachedCatalogSummary();
        $syncJobSummary = $syncJobs->getStatusSummary(ProviderManager::CLOCK_MODE);
        $inboundSummary = $requestLogs->getInboundSummary(ProviderManager::CLOCK_MODE);
        $outboundSummary = $requestLogs->getOutboundSummary(ProviderManager::CLOCK_MODE);
        $latestOutbound = $requestLogs->getLatestOutboundLogSummary(ProviderManager::CLOCK_MODE);
        $syncJobCounts = isset($syncJobSummary['counts']) && \is_array($syncJobSummary['counts']) ? $syncJobSummary['counts'] : [];

        if ((string) $configuredMode === 'clock' && !empty($providerSummary['clock_direct_api_configured'])) {
            $catalogErrors = isset($catalogSummary['errors']) && \is_array($catalogSummary['errors']) ? $catalogSummary['errors'] : [];

            if (empty($catalogSummary['last_fetched_at']) || !empty($catalogErrors)) {
                $warnings++;
            }
        }

        $syncCronHook = ProviderSyncJobRunner::getCronHook();
        $nextSyncCron = $syncCronHook !== '' ? \wp_next_scheduled($syncCronHook) : false;
        $syncCronScheduled = $nextSyncCron !== false;

        if (
            (int) ($syncJobCounts[ProviderSyncJobRepository::STATUS_FAILED] ?? 0) > 0
            || (int) ($syncJobCounts[ProviderSyncJobRepository::STATUS_EXHAUSTED] ?? 0) > 0
        ) {
            $warnings++;
        }

        if (!$syncCronScheduled && (string) $configuredMode === 'clock') {
            $warnings++;
        }

        if ((int) ($inboundSummary['failed'] ?? 0) > 0 && (string) $configuredMode === 'clock') {
            $warnings++;
        }

        $provider = [
            'configured_mode' => $configuredMode,
            'active_booking_provider' => $activeBookingProvider,
            'mode_warning' => $modeWarning,
            'clock' => $providerSummary,
            'catalog' => $catalogSummary,
            'sync_jobs' => [
                'summary' => $syncJobSummary,
                'recent_problem_jobs' => $syncJobs->getRecentProblemJobs(ProviderManager::CLOCK_MODE, 5),
                'cron' => [
                    'status' => $syncCronScheduled ? 'healthy' : 'warning',
                    'health' => $syncCronScheduled ? 'ok' : 'missing',
                    'message' => $syncCronScheduled
                        ? \__('Provider sync retry cron is scheduled.', 'must-hotel-booking')
                        : \__('Provider sync retry cron is not scheduled.', 'must-hotel-booking'),
                    'next_run' => $syncCronScheduled && \is_numeric($nextSyncCron)
                        ? \wp_date('Y-m-d H:i:s', (int) $nextSyncCron)
                        : \__('Not scheduled', 'must-hotel-booking'),
                    'hook' => $syncCronHook,
                ],
            ],
            'inbound_sync' => [
                'summary' => $inboundSummary,
                'webhook_secret_set' => !empty($providerSummary['clock_webhook_secret_set']),
                'webhook_basic_auth_set' => !empty($providerSummary['clock_webhook_basic_auth_set']),
                'webhook_basic_auth_incomplete' => !empty($providerSummary['clock_webhook_basic_auth_incomplete']),
                'sns_signature_verification' => true,
                'webhook_url' => (string) ($providerSummary['clock_webhook_url'] ?? ''),
                'reservation_fetch_path' => (string) ($providerSummary['clock_reservation_fetch_path'] ?? ''),
            ],
            'request_logs' => [
                'outbound_summary' => $outboundSummary,
                'latest_outbound' => $latestOutbound,
            ],
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
            'anti_abuse' => $antiAbuse,
            'updater' => $updater,
            'provider' => $provider,
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
            'Public Callback Base URL: ' . (string) ($data['environment']['public_callback_base_url'] ?? ''),
            'Hotel: ' . (string) ($data['environment']['hotel_name'] ?? ''),
            'Currency: ' . (string) ($data['environment']['currency'] ?? ''),
            'Timezone: ' . (string) ($data['environment']['timezone'] ?? ''),
            'Overall Status: ' . (string) ($data['overall_status'] ?? ''),
            'Critical Issues: ' . (int) ($data['critical_issues'] ?? 0),
            'Warnings: ' . (int) ($data['warnings'] ?? 0),
            '',
            '[Anti-Abuse]',
            'Enabled: ' . (!empty($data['anti_abuse']['enabled']) ? 'yes' : 'no'),
            'Honeypot Enabled: ' . (!empty($data['anti_abuse']['honeypot_enabled']) ? 'yes' : 'no'),
            'Minimum Submit Time Enabled: ' . (!empty($data['anti_abuse']['minimum_submit_enabled']) ? 'yes' : 'no'),
            'Minimum Submit Seconds: ' . (string) ($data['anti_abuse']['minimum_submit_seconds'] ?? 0),
            'Throttling Enabled: ' . (!empty($data['anti_abuse']['throttle_enabled']) ? 'yes' : 'no'),
            'Throttle Max Attempts: ' . (string) ($data['anti_abuse']['max_attempts'] ?? 0),
            'Throttle Window Minutes: ' . (string) ($data['anti_abuse']['window_minutes'] ?? 0),
            'Throttle Block Minutes: ' . (string) ($data['anti_abuse']['block_duration_minutes'] ?? 0),
            'Logging Enabled: ' . (!empty($data['anti_abuse']['logging_enabled']) ? 'yes' : 'no'),
            'Recent Blocked Attempts Loaded: ' . (string) ($data['anti_abuse']['recent_blocked_total'] ?? 0),
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

        $lines[] = '';
        $lines[] = '[Updater]';
        $lines[] = 'Repository: ' . (string) ($data['updater']['repository'] ?? '');
        $lines[] = 'Branch: ' . (string) ($data['updater']['branch'] ?? '');
        $lines[] = 'Version Consistent: ' . (!empty($data['updater']['version_consistent']) ? 'yes' : 'no');
        $lines[] = 'Readme Stable Tag: ' . (string) ($data['updater']['readme_stable_tag'] ?? '');
        $lines[] = 'Expected Release Asset: ' . (string) ($data['updater']['expected_release_asset_name'] ?? '');
        $lines[] = 'Token Configured: ' . (!empty($data['updater']['token_configured']) ? 'yes' : 'no');

        $lines[] = '';
        $lines[] = '[Provider]';
        $lines[] = 'Configured Mode: ' . (string) ($data['provider']['configured_mode'] ?? '');
        $lines[] = 'Active Booking Runtime: ' . (string) ($data['provider']['active_booking_provider'] ?? '');
        $clock = isset($data['provider']['clock']) && \is_array($data['provider']['clock']) ? $data['provider']['clock'] : [];
        $lines[] = 'Clock Enabled: ' . (!empty($clock['clock_enabled']) ? 'yes' : 'no');
        $lines[] = 'Clock Configured: ' . (!empty($clock['clock_configured']) ? 'yes' : 'no');
        $lines[] = 'Direct Clock API Configured: ' . (!empty($clock['clock_direct_api_configured']) ? 'yes' : 'no');
        $lines[] = 'Direct Clock Public Booking Ready: ' . (!empty($clock['clock_direct_public_booking_ready']) ? 'yes' : 'no');
        $lines[] = 'Fallback To Local When Clock Unavailable: ' . (!empty($clock['fallback_to_local_when_clock_unavailable']) ? 'yes' : 'no');
        $lines[] = 'Clock Environment: ' . (string) ($clock['clock_environment'] ?? '');
        $lines[] = 'Clock PMS API Enabled: ' . (!empty($clock['clock_pms_api_enabled']) ? 'yes' : 'no');
        $lines[] = 'Clock PMS API Configured: ' . (!empty($clock['clock_pms_api_configured']) ? 'yes' : 'no');
        $lines[] = 'Clock Base API Enabled: ' . (!empty($clock['clock_base_api_enabled']) ? 'yes' : 'no');
        $lines[] = 'Clock Base API Configured: ' . (!empty($clock['clock_base_api_configured']) ? 'yes' : 'no');
        $lines[] = 'Clock PMS API URL Set: ' . (!empty($clock['clock_pms_api_url_configured']) ? 'yes' : 'no');
        $lines[] = 'Clock Base API URL Set: ' . (!empty($clock['clock_base_api_url_configured']) ? 'yes' : 'no');
        $lines[] = 'Clock Region: ' . (string) ($clock['clock_region'] ?? '');
        $lines[] = 'Clock API Type: ' . (string) ($clock['clock_api_type'] ?? '');
        $lines[] = 'Clock Subscription ID: ' . (string) ($clock['clock_subscription_id'] ?? '');
        $lines[] = 'Clock Account ID: ' . (string) ($clock['clock_account_id'] ?? '');
        $lines[] = 'Clock Resolved Base URL: ' . (string) ($clock['clock_base_url'] ?? '');
        $lines[] = 'Clock API User Set: ' . (!empty($clock['clock_api_user_set']) ? 'yes' : 'no');
        $lines[] = 'Clock API Key Set: ' . (!empty($clock['clock_api_key_set']) ? 'yes' : 'no');
        $lines[] = 'Clock Credentials Valid: ' . (!empty($clock['clock_credentials_valid']) ? 'yes' : 'no');
        $lines[] = 'Clock Property ID: ' . (string) ($clock['clock_property_id'] ?? '');
        $lines[] = 'Clock WBE Hotel ID: ' . (string) ($clock['clock_wbe_hotel_id'] ?? '');
        $lines[] = 'Clock Catalog Paths Configured: ' . (string) ($clock['clock_catalog_paths_configured'] ?? 0);
        $catalog = isset($data['provider']['catalog']) && \is_array($data['provider']['catalog']) ? $data['provider']['catalog'] : [];
        $catalogCounts = isset($catalog['counts']) && \is_array($catalog['counts']) ? $catalog['counts'] : [];
        $catalogErrors = isset($catalog['errors']) && \is_array($catalog['errors']) ? $catalog['errors'] : [];
        $lines[] = 'Clock Catalog Last Fetched: ' . (string) ($catalog['last_fetched_at'] ?? '');
        $lines[] = 'Clock Catalog Status: ' . (string) ($catalog['status'] ?? '');
        $lines[] = 'Clock Catalog Room Types: ' . (string) ($catalogCounts['room_types'] ?? 0);
        $lines[] = 'Clock Catalog Rooms: ' . (string) ($catalogCounts['rooms'] ?? 0);
        $lines[] = 'Clock Catalog Rates: ' . (string) ($catalogCounts['rates'] ?? 0);
        $lines[] = 'Clock Catalog Error Count: ' . (string) \count($catalogErrors);
        $requestLogs = isset($data['provider']['request_logs']) && \is_array($data['provider']['request_logs']) ? $data['provider']['request_logs'] : [];
        $outboundSummary = isset($requestLogs['outbound_summary']) && \is_array($requestLogs['outbound_summary']) ? $requestLogs['outbound_summary'] : [];
        $lines[] = 'Clock Outbound Requests Total: ' . (string) ($outboundSummary['total'] ?? 0);
        $lines[] = 'Clock Outbound Requests Failed: ' . (string) ($outboundSummary['failed'] ?? 0);
        $lines[] = 'Clock Outbound Last Error Operation: ' . (string) ($outboundSummary['last_error_operation'] ?? '');
        $lines[] = 'Clock Outbound Last Error HTTP Status: ' . (string) ($outboundSummary['last_error_http_status'] ?? 0);
        $lines[] = 'Clock Outbound Last Error: ' . (string) ($outboundSummary['last_error'] ?? '');
        $latestOutbound = isset($requestLogs['latest_outbound']) && \is_array($requestLogs['latest_outbound']) ? $requestLogs['latest_outbound'] : [];
        $lines[] = 'Clock Latest Outbound Operation: ' . (string) ($latestOutbound['operation'] ?? '');
        $lines[] = 'Clock Latest Outbound HTTP Status: ' . (string) ($latestOutbound['http_status'] ?? 0);
        $lines[] = 'Clock Latest Outbound Success: ' . (!empty($latestOutbound['success']) ? 'yes' : 'no');
        $lines[] = 'Clock Payment Posting Mode: ' . (string) ($clock['clock_payment_posting_mode'] ?? '');
        $lines[] = 'Clock Booking Create Endpoint Reachable/Configured: ' . (!empty($clock['clock_booking_create_endpoint_reachable']) ? 'yes' : 'no');
        $lines[] = 'Clock Booking Folio Endpoint Reachable/Configured: ' . (!empty($clock['clock_booking_folio_endpoint_reachable']) ? 'yes' : 'no');
        $lines[] = 'Clock Folio Credit Item Endpoint Reachable/Configured: ' . (!empty($clock['clock_folio_credit_item_endpoint_reachable']) ? 'yes' : 'no');
        $lines[] = 'Clock Booking Deposit Endpoint Configured: ' . (!empty($clock['clock_booking_deposit_payment_configured']) ? 'yes' : 'no');
        $lines[] = 'Clock Same-Day Folio Payment Enabled: ' . (!empty($clock['clock_same_day_folio_payment_enabled']) ? 'yes' : 'no');
        $lines[] = 'Clock Public Booking Configured: ' . (!empty($clock['clock_public_booking_configured']) ? 'yes' : 'no');
        $lines[] = 'Clock Public Booking Paths Configured: ' . (string) ($clock['clock_public_booking_paths_configured'] ?? 0);
        $lines[] = 'Clock Reconciliation Paths Configured: ' . (string) ($clock['clock_reconciliation_paths_configured'] ?? 0);
        $lines[] = 'Clock Inbound Sync Paths Configured: ' . (string) ($clock['clock_inbound_sync_paths_configured'] ?? 0);
        $lines[] = 'Clock Inbound Auth: SNS signatures supported; Basic auth set: ' . (!empty($clock['clock_webhook_basic_auth_set']) ? 'yes' : 'no') . '; Basic auth incomplete: ' . (!empty($clock['clock_webhook_basic_auth_incomplete']) ? 'yes' : 'no') . '; legacy token/HMAC set: ' . (!empty($clock['clock_webhook_secret_set']) ? 'yes' : 'no');
        $lines[] = 'Clock Webhook URL: ' . (string) ($clock['clock_webhook_url'] ?? '');
        $lines[] = 'Stripe Webhook URL: ' . (string) ($data['payments']['stripe_webhook_url'] ?? '');
        $lines[] = 'PokPay Webhook URL: ' . (string) ($data['payments']['pokpay_webhook_url'] ?? '');
        $inboundSync = isset($data['provider']['inbound_sync']) && \is_array($data['provider']['inbound_sync']) ? $data['provider']['inbound_sync'] : [];
        $inboundSummary = isset($inboundSync['summary']) && \is_array($inboundSync['summary']) ? $inboundSync['summary'] : [];
        $lines[] = 'Clock Inbound Events Total: ' . (string) ($inboundSummary['total'] ?? 0);
        $lines[] = 'Clock Inbound Events Successful: ' . (string) ($inboundSummary['successful'] ?? 0);
        $lines[] = 'Clock Inbound Events Failed: ' . (string) ($inboundSummary['failed'] ?? 0);
        $lines[] = 'Clock Inbound Last Error: ' . (string) ($inboundSummary['last_error'] ?? '');
        $syncJobs = isset($data['provider']['sync_jobs']) && \is_array($data['provider']['sync_jobs']) ? $data['provider']['sync_jobs'] : [];
        $syncSummary = isset($syncJobs['summary']) && \is_array($syncJobs['summary']) ? $syncJobs['summary'] : [];
        $syncCounts = isset($syncSummary['counts']) && \is_array($syncSummary['counts']) ? $syncSummary['counts'] : [];
        $syncCron = isset($syncJobs['cron']) && \is_array($syncJobs['cron']) ? $syncJobs['cron'] : [];
        $lines[] = 'Clock Sync Cron: ' . (string) ($syncCron['health'] ?? '');
        $lines[] = 'Clock Sync Due Jobs: ' . (string) ($syncSummary['due'] ?? 0);
        $lines[] = 'Clock Sync Pending Jobs: ' . (string) ($syncCounts[ProviderSyncJobRepository::STATUS_PENDING] ?? 0);
        $lines[] = 'Clock Sync Retryable Jobs: ' . (string) ($syncCounts[ProviderSyncJobRepository::STATUS_RETRYABLE] ?? 0);
        $lines[] = 'Clock Sync Exhausted Jobs: ' . (string) ($syncCounts[ProviderSyncJobRepository::STATUS_EXHAUSTED] ?? 0);
        $lines[] = 'Clock Sync Last Error: ' . (string) ($syncSummary['last_error'] ?? '');
        $recentProblemJobs = isset($syncJobs['recent_problem_jobs']) && \is_array($syncJobs['recent_problem_jobs']) ? $syncJobs['recent_problem_jobs'] : [];

        foreach ($recentProblemJobs as $job) {
            if (!\is_array($job)) {
                continue;
            }

            $lines[] = \sprintf(
                'Clock Sync Job #%1$d: %2$s %3$s attempts %4$d/%5$d reservation %6$d - %7$s',
                (int) ($job['id'] ?? 0),
                (string) ($job['operation'] ?? ''),
                (string) ($job['status'] ?? ''),
                (int) ($job['attempts'] ?? 0),
                (int) ($job['max_attempts'] ?? 0),
                (int) ($job['target_local_id'] ?? 0),
                (string) ($job['last_error'] ?? '')
            );
        }

        return \implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeContext(string $contextJson): array
    {
        $decoded = \json_decode($contextJson, true);

        return \is_array($decoded) ? $decoded : [];
    }
    /**
     * @return array<string, mixed>
     */
    private static function getLastPublicBookingPaymentSummary(): array
    {
        global $wpdb;

        $reservationsTable = $wpdb->prefix . 'must_reservations';
        $paymentsTable = $wpdb->prefix . 'must_payments';

        if (
            !self::tableExists($reservationsTable)
            || !self::tableExists($paymentsTable)
            || !self::columnExists($reservationsTable, 'id')
            || !self::columnExists($reservationsTable, 'booking_source')
            || !self::columnExists($reservationsTable, 'payment_status')
            || !self::columnExists($paymentsTable, 'id')
            || !self::columnExists($paymentsTable, 'reservation_id')
            || !self::columnExists($paymentsTable, 'method')
        ) {
            return [];
        }

        $paymentStatusSelect = self::columnExists($paymentsTable, 'status')
            ? "COALESCE(p.status, r.payment_status, '')"
            : "COALESCE(r.payment_status, '')";
        $createdSelect = self::columnExists($reservationsTable, 'created_at') ? 'r.created_at' : "''";

        $row = $wpdb->get_row(
            "SELECT
                COALESCE(p.method, '') AS payment_method,
                {$paymentStatusSelect} AS payment_status,
                {$createdSelect} AS created_at
            FROM `{$reservationsTable}` r
            LEFT JOIN `{$paymentsTable}` p ON p.reservation_id = r.id
            WHERE r.booking_source = 'website'
            ORDER BY r.id DESC, p.id DESC
            LIMIT 1",
            ARRAY_A
        );

        return \is_array($row) ? $row : [];
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
    private static function columnExists(string $tableName, string $columnName): bool
    {
        if ($tableName === '' || $columnName === '') {
            return false;
        }

        global $wpdb;
        $match = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$tableName}` LIKE %s",
                $columnName
            )
        );

        return \is_string($match) && $match !== '';
    }
}
