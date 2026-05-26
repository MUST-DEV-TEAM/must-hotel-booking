<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\EmailLayoutEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Provider\Clock\ClockConfig;
use MustHotelBooking\Provider\Clock\ClockCatalogService;
use MustHotelBooking\Provider\Clock\ClockConnectionDiagnostic;
use MustHotelBooking\Provider\Clock\ClockEndpointResolver;
use MustHotelBooking\Provider\Clock\ClockInboundSyncService;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;
use MustHotelBooking\Provider\Sync\ProviderSyncJobRunner;
use MustHotelBooking\Portal\PortalRegistry;
use MustHotelBooking\Portal\PortalRouter;

final class SettingsPage
{
    /** @var array<string, mixed>|null */
    private static $earlyRequestState = null;

    /**
     * @return array<string, array<string, string>>
     */
    public static function getTabs(): array
    {
        return [
            'general' => ['label' => \__('General', 'must-hotel-booking'), 'icon' => 'dashicons-admin-home'],
            'booking_rules' => ['label' => \__('Booking Rules', 'must-hotel-booking'), 'icon' => 'dashicons-clipboard'],
            'checkin_checkout' => ['label' => \__('Check-in / Check-out', 'must-hotel-booking'), 'icon' => 'dashicons-clock'],
            'payments_summary' => ['label' => \__('Payments Summary', 'must-hotel-booking'), 'icon' => 'dashicons-money-alt'],
            'staff_access' => ['label' => \__('Staff & Access', 'must-hotel-booking'), 'icon' => 'dashicons-groups'],
            'staff_users' => ['label' => \__('Staff Users', 'must-hotel-booking'), 'icon' => 'dashicons-admin-users'],
            'branding' => ['label' => \__('Frontend & Branding', 'must-hotel-booking'), 'icon' => 'dashicons-art'],
            'managed_pages' => ['label' => \__('Managed Pages', 'must-hotel-booking'), 'icon' => 'dashicons-admin-page'],
            'notifications_summary' => ['label' => \__('Notifications & Emails Summary', 'must-hotel-booking'), 'icon' => 'dashicons-email-alt'],
            'provider' => ['label' => \__('Provider', 'must-hotel-booking'), 'icon' => 'dashicons-networking'],
            'maintenance' => ['label' => \__('Diagnostics & Maintenance', 'must-hotel-booking'), 'icon' => 'dashicons-admin-tools'],
        ];
    }

    public static function normalizeTab(string $tab): string
    {
        $aliases = [
            'booking' => 'booking_rules',
            'pages' => 'managed_pages',
            'diagnostics' => 'maintenance',
        ];

        $tab = \sanitize_key($tab);
        $tab = isset($aliases[$tab]) ? $aliases[$tab] : $tab;

        return isset(self::getTabs()[$tab]) ? $tab : 'general';
    }

    public static function getActiveTab(): string
    {
        $requested = isset($_GET['tab']) ? (string) \wp_unslash($_GET['tab']) : 'general';

        return self::normalizeTab($requested);
    }

    /**
     * @return array<string, mixed>
     */
    public static function maybeHandleSaveRequest(): array
    {
        if (\is_array(self::$earlyRequestState)) {
            return self::$earlyRequestState;
        }

        $state = self::blankState();
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return $state;
        }

        $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';

        if ($page !== 'must-hotel-booking-settings') {
            return $state;
        }

        $action = isset($_POST['must_settings_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_settings_action'])) : '';

        if ($action === '') {
            return $state;
        }

        /** @var array<string, mixed> $source */
        $source = \is_array($_POST) ? $_POST : [];

        return self::processPostAction($action, $source, false);
    }

    public static function maybeHandleSaveRequestEarly(): void
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return;
        }

        $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';
        $action = isset($_POST['must_settings_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_settings_action'])) : '';

        if ($page !== 'must-hotel-booking-settings' || $action === '') {
            return;
        }

        ensure_admin_capability('must-hotel-booking-settings');

        /** @var array<string, mixed> $source */
        $source = \is_array($_POST) ? $_POST : [];
        $state = self::processPostAction($action, $source, true);
        self::$earlyRequestState = $state;

        if (!empty($state['errors']) || (string) ($state['notice'] ?? '') === '') {
            return;
        }

        \wp_safe_redirect(
            get_admin_settings_page_url(
                (isset($state['query_args']) && \is_array($state['query_args']) ? $state['query_args'] : []) + [
                    'tab' => (string) ($state['tab'] ?? 'general'),
                    'notice' => (string) ($state['notice'] ?? ''),
                ]
            )
        );
        exit;
    }

    public static function enqueueAssets(): void
    {
        $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';

        if ($page !== 'must-hotel-booking-settings') {
            return;
        }

        \wp_enqueue_style(
            'must-hotel-booking-admin-ui',
            MUST_HOTEL_BOOKING_URL . 'assets/css/admin-ui.css',
            [],
            MUST_HOTEL_BOOKING_VERSION
        );

        \wp_enqueue_media();

        \wp_enqueue_script(
            'must-hotel-booking-admin-settings',
            MUST_HOTEL_BOOKING_URL . 'assets/js/admin-settings.js',
            ['jquery', 'media-editor', 'media-views'],
            MUST_HOTEL_BOOKING_VERSION,
            true
        );

        \wp_localize_script(
            'must-hotel-booking-admin-settings',
            'mustHotelBookingSettingsAdmin',
            [
                'mediaFrameTitle' => \__('Select Image', 'must-hotel-booking'),
                'mediaFrameButton' => \__('Use Image', 'must-hotel-booking'),
                'copyLabel' => \__('Copied to clipboard.', 'must-hotel-booking'),
                'copyFailedLabel' => \__('Unable to copy automatically. Copy the text manually.', 'must-hotel-booking'),
            ]
        );
    }

    public static function render(): void
    {
        ensure_admin_capability('must-hotel-booking-settings');

        $state = self::maybeHandleSaveRequest();
        $activeTab = isset($state['tab']) ? (string) $state['tab'] : self::getActiveTab();
        $diagnostics = SettingsDiagnostics::getData();
        $forms = self::getRenderForms(isset($state['forms']) && \is_array($state['forms']) ? $state['forms'] : []);
        $errors = isset($state['errors']) && \is_array($state['errors']) ? $state['errors'] : [];
        $notice = isset($state['notice']) ? (string) $state['notice'] : '';

        echo '<div class="wrap must-dashboard-page must-settings-page">';
        self::renderHero($diagnostics);
        self::renderAdminNotice($notice);
        self::renderErrorNotice($errors);
        self::renderOverviewCards($diagnostics);
        self::renderTabNavigation($activeTab);
        self::renderActiveTab($activeTab, $forms, $diagnostics);
        echo '</div>';
    }

    /**
     * @return array<string, mixed>
     */
    private static function blankState(): array
    {
        return [
            'tab' => self::getActiveTab(),
            'notice' => '',
            'errors' => [],
            'forms' => [],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $formOverrides
     * @return array<string, array<string, mixed>>
     */
    private static function getRenderForms(array $formOverrides): array
    {
        return [
            'general' => self::getGeneralForm($formOverrides['general'] ?? []),
            'booking_rules' => self::getBookingRulesForm($formOverrides['booking_rules'] ?? []),
            'checkin_checkout' => self::getCheckinCheckoutForm($formOverrides['checkin_checkout'] ?? []),
            'payments_summary' => self::getPaymentsSummaryForm($formOverrides['payments_summary'] ?? []),
            'staff_access' => self::getStaffAccessForm($formOverrides['staff_access'] ?? []),
            'branding' => self::getBrandingForm($formOverrides['branding'] ?? []),
            'managed_pages' => self::getManagedPagesForm($formOverrides['managed_pages'] ?? []),
            'notifications_summary' => self::getNotificationsForm($formOverrides['notifications_summary'] ?? []),
            'provider' => self::getProviderForm($formOverrides['provider'] ?? []),
            'maintenance' => self::getMaintenanceForm($formOverrides['maintenance'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function processPostAction(string $action, array $source, bool $persist): array
    {
        $state = self::blankState();

        if (\strpos($action, 'save_') === 0) {
            return self::processSaveAction($action, $source, $persist);
        }

        if ($action === 'managed_page_action') {
            return self::processManagedPageAction($source, $persist);
        }

        if ($action === 'maintenance_action') {
            return self::processMaintenanceAction($source, $persist);
        }

        if ($action === 'staff_user_action') {
            return self::processStaffUserAction($source, $persist);
        }

        if ($action === 'dangerous_reset_action') {
            return self::processDangerousResetAction($source, $persist);
        }

        if ($action === 'provider_mapping_action') {
            return self::processProviderMappingAction($source, $persist);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function processSaveAction(string $action, array $source, bool $persist): array
    {
        $tab = self::normalizeTab(\str_replace('save_', '', $action));
        $nonce = isset($source['must_settings_nonce']) ? (string) \wp_unslash($source['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_' . $tab)) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        $sanitized = self::sanitizeTabForm($tab, $source);
        $state = [
            'tab' => $tab,
            'notice' => '',
            'errors' => isset($sanitized['errors']) && \is_array($sanitized['errors']) ? $sanitized['errors'] : [],
            'forms' => [
                $tab => isset($sanitized['form']) && \is_array($sanitized['form']) ? $sanitized['form'] : [],
            ],
        ];

        if (!empty($state['errors'])) {
            return $state;
        }

        if ($persist) {
            self::persistUpdates(isset($sanitized['updates']) && \is_array($sanitized['updates']) ? $sanitized['updates'] : []);
        }

        $state['notice'] = $tab . '_saved';

        return $state;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function processManagedPageAction(array $source, bool $persist): array
    {
        $tab = 'managed_pages';
        $nonce = isset($source['must_settings_nonce']) ? (string) \wp_unslash($source['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_managed_pages_action')) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        $pageKey = isset($source['managed_page_key']) ? \sanitize_key((string) \wp_unslash($source['managed_page_key'])) : '';
        $task = isset($source['managed_page_task']) ? \sanitize_key((string) \wp_unslash($source['managed_page_task'])) : '';
        $errors = [];

        if (!isset(ManagedPages::getConfig()[$pageKey])) {
            $errors[] = \__('Unknown managed page target.', 'must-hotel-booking');
        }

        if (!\in_array($task, ['create', 'recreate'], true)) {
            $errors[] = \__('Unknown managed page action.', 'must-hotel-booking');
        }

        if (!empty($errors)) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => $errors,
                'forms' => [],
            ];
        }

        if ($persist) {
            ManagedPages::resumeAutoManagement();

            if ($task === 'recreate') {
                ManagedPages::recreatePage($pageKey);
            } else {
                ManagedPages::ensurePage($pageKey, true);
            }

            ManagedPages::sync();
            PortalRouter::flushRewriteRules();
        }

        return [
            'tab' => $tab,
            'notice' => 'managed_page_repaired',
            'errors' => [],
            'forms' => [],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function processMaintenanceAction(array $source, bool $persist): array
    {
        $tab = isset($source['return_tab']) ? self::normalizeTab((string) \wp_unslash($source['return_tab'])) : 'maintenance';
        $nonce = isset($source['must_settings_nonce']) ? (string) \wp_unslash($source['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_maintenance_action')) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        $task = isset($source['maintenance_task']) ? \sanitize_key((string) \wp_unslash($source['maintenance_task'])) : '';
        $allowedTasks = ['reinstall_pages', 'reschedule_cron', 'cleanup_expired_locks', 'send_test_email', 'flush_portal_routes', 'repair_inventory_mirror', 'apply_clock_api_defaults', 'test_clock_connection', 'fetch_clock_catalog', 'run_provider_sync_jobs', 'queue_clock_reservation_refresh'];

        if (!\in_array($task, $allowedTasks, true)) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('Unknown maintenance action.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        if ($task === 'test_clock_connection') {
            $result = ClockConnectionDiagnostic::testConnection();

            if (empty($result['success'])) {
                $httpStatus = (int) ($result['http_status'] ?? 0);
                $message = (string) ($result['message'] ?? \__('Clock connection test failed.', 'must-hotel-booking'));

                if ($httpStatus > 0) {
                    $message .= ' ' . \sprintf(
                        /* translators: %d is an HTTP status code. */
                        \__('HTTP status: %d.', 'must-hotel-booking'),
                        $httpStatus
                    );
                }

                $message .= ' ' . \__('Technical details were saved in provider request logs without credentials.', 'must-hotel-booking');

                return [
                    'tab' => $tab,
                    'notice' => '',
                    'errors' => [$message],
                    'forms' => [],
                ];
            }

            return [
                'tab' => $tab,
                'notice' => 'clock_connection_test_succeeded',
                'errors' => [],
                'forms' => [],
                'query_args' => [
                    'clock_http_status' => (int) ($result['http_status'] ?? 0),
                    'clock_duration_ms' => (int) ($result['duration_ms'] ?? 0),
                ],
            ];
        }

        if ($task === 'apply_clock_api_defaults') {
            if ($persist) {
                self::applyClockApiDefaults();
            }

            return [
                'tab' => $tab,
                'notice' => 'clock_api_defaults_applied',
                'errors' => [],
                'forms' => [],
            ];
        }

        if ($task === 'fetch_clock_catalog') {
            $catalog = $persist ? (new ClockCatalogService())->refreshCatalog() : ['success' => true, 'partial_success' => false];
            $errors = isset($catalog['errors']) && \is_array($catalog['errors']) ? $catalog['errors'] : [];

            if (empty($catalog['success']) && empty($catalog['partial_success'])) {
                return [
                    'tab' => $tab,
                    'notice' => '',
                    'errors' => !empty($errors)
                        ? \array_values(\array_map('strval', $errors))
                        : [\__('Clock catalog fetch failed. Check the connection diagnostics and request logs.', 'must-hotel-booking')],
                    'forms' => [],
                ];
            }

            return [
                'tab' => $tab,
                'notice' => !empty($errors) ? 'clock_catalog_fetched_with_warnings' : 'clock_catalog_fetched',
                'errors' => [],
                'forms' => [],
            ];
        }

        if ($task === 'run_provider_sync_jobs') {
            $summary = $persist
                ? (new ProviderSyncJobRunner())->runDueJobs(10)
                : ['processed' => 0, 'succeeded' => 0, 'retryable' => 0, 'failed' => 0, 'skipped' => 0, 'released_stale' => 0];

            return [
                'tab' => $tab,
                'notice' => 'provider_sync_jobs_processed',
                'errors' => [],
                'forms' => [],
                'query_args' => [
                    'sync_processed' => (int) ($summary['processed'] ?? 0),
                    'sync_succeeded' => (int) ($summary['succeeded'] ?? 0),
                    'sync_retryable' => (int) ($summary['retryable'] ?? 0),
                    'sync_failed' => (int) ($summary['failed'] ?? 0),
                    'sync_skipped' => (int) ($summary['skipped'] ?? 0),
                    'sync_released_stale' => (int) ($summary['released_stale'] ?? 0),
                ],
            ];
        }

        if ($task === 'queue_clock_reservation_refresh') {
            $reservationId = isset($source['clock_reservation_id']) ? \absint(\wp_unslash($source['clock_reservation_id'])) : 0;

            if ($reservationId <= 0) {
                return [
                    'tab' => $tab,
                    'notice' => '',
                    'errors' => [\__('Enter a valid local reservation ID to queue a Clock refresh.', 'must-hotel-booking')],
                    'forms' => [],
                ];
            }

            $jobId = $persist ? (new ClockInboundSyncService())->enqueueReservationRefresh($reservationId, 'manual') : 0;

            if ($jobId <= 0) {
                return [
                    'tab' => $tab,
                    'notice' => '',
                    'errors' => [\__('Unable to queue Clock reservation refresh. Confirm the reservation is a Clock-linked mirror reservation.', 'must-hotel-booking')],
                    'forms' => [],
                ];
            }

            return [
                'tab' => $tab,
                'notice' => 'clock_reservation_refresh_queued',
                'errors' => [],
                'forms' => [],
                'query_args' => [
                    'clock_refresh_job_id' => $jobId,
                    'clock_reservation_id' => $reservationId,
                ],
            ];
        }

        if ($persist) {
            if ($task === 'reinstall_pages') {
                ManagedPages::resumeAutoManagement();
                ManagedPages::install();
                PortalRouter::flushRewriteRules();
            } elseif ($task === 'reschedule_cron') {
                LockEngine::unscheduleCleanupCron();
                LockEngine::scheduleCleanupCron();
                ProviderSyncJobRunner::unscheduleCron();
                ProviderSyncJobRunner::scheduleCron();
            } elseif ($task === 'cleanup_expired_locks') {
                LockEngine::cleanupExpiredLocks();
            } elseif ($task === 'send_test_email') {
                $recipient = MustBookingConfig::get_booking_notification_email();
                $result = EmailEngine::sendTestEmail('admin_new_booking_pay_at_hotel', $recipient);

                if (empty($result['success'])) {
                    return [
                        'tab' => $tab,
                        'notice' => '',
                        'errors' => [(string) ($result['message'] ?? \__('Unable to send test email.', 'must-hotel-booking'))],
                        'forms' => [],
                    ];
                }
            } elseif ($task === 'flush_portal_routes') {
                PortalRouter::flushRewriteRules();
            } elseif ($task === 'repair_inventory_mirror') {
                $summary = \MustHotelBooking\Database\seed_inventory_model_from_legacy_rooms();

                return [
                    'tab' => $tab,
                    'notice' => 'inventory_mirror_repaired',
                    'errors' => [],
                    'forms' => [],
                    'query_args' => [
                        'legacy_types' => (int) ($summary['legacy_types'] ?? 0),
                        'mirrored_types_inserted' => (int) ($summary['mirrored_types_inserted'] ?? 0),
                        'mirrored_types_updated' => (int) ($summary['mirrored_types_updated'] ?? 0),
                        'inventory_units_created' => (int) ($summary['inventory_units_created'] ?? 0),
                    ],
                ];
            }
        }

        return [
            'tab' => $tab,
            'notice' => 'maintenance_action_completed',
            'errors' => [],
            'forms' => [],
        ];
    }

    private static function applyClockApiDefaults(): void
    {
        $provider = MustBookingConfig::get_group_settings('provider');
        $provider = \array_merge($provider, [
            'provider_mode' => ProviderManager::CLOCK_MODE,
            'clock_enabled' => true,
            'clock_pms_api_enabled' => true,
            'clock_connection_path' => '/room_types',
            'clock_room_types_path' => '/room_types',
            'clock_rooms_path' => '/rooms',
            'clock_rates_path' => '/rates',
            'clock_rates_availability_path' => '/rates_availability',
            'clock_products_path' => '/products',
            'clock_reservation_create_path' => '/bookings/',
            'clock_reservation_fetch_path' => '/bookings/{booking_id}',
            'clock_timeout_seconds' => \max(1, (int) ($provider['clock_timeout_seconds'] ?? 15)),
            'fallback_to_local_when_clock_unavailable' => false,
        ]);

        MustBookingConfig::set_group_settings('provider', $provider);
    }

    /**
     * Handle create / activate / deactivate / delete actions for plugin staff users.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function processStaffUserAction(array $source, bool $persist): array
    {
        $tab = 'staff_users';

        if (!\current_user_can('manage_options')) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('You do not have permission to manage staff users.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        $nonce = isset($source['must_settings_nonce']) ? (string) \wp_unslash($source['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_staff_users_action')) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        $subAction = isset($source['staff_user_sub_action']) ? \sanitize_key((string) \wp_unslash($source['staff_user_sub_action'])) : '';
        $currentUserId = (int) \get_current_user_id();

        if ($subAction === 'create') {
            $username  = isset($source['new_staff_username']) ? \sanitize_user((string) \wp_unslash($source['new_staff_username'])) : '';
            $email     = isset($source['new_staff_email']) ? \sanitize_email((string) \wp_unslash($source['new_staff_email'])) : '';
            $password  = isset($source['new_staff_password']) ? (string) \wp_unslash($source['new_staff_password']) : '';
            $roleSlug  = isset($source['new_staff_role']) ? \sanitize_key((string) \wp_unslash($source['new_staff_role'])) : '';

            $validRoles = StaffAccess::getPortalRoleSlugs();

            if ($username === '') {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Username is required.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($email === '' || !\is_email($email)) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('A valid email address is required.', 'must-hotel-booking')], 'forms' => []];
            }

            if (\strlen($password) < 8) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Password must be at least 8 characters.', 'must-hotel-booking')], 'forms' => []];
            }

            if (!\in_array($roleSlug, $validRoles, true)) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Please select a valid staff role.', 'must-hotel-booking')], 'forms' => []];
            }

            if (\username_exists($username)) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('That username is already taken. Please choose another.', 'must-hotel-booking')], 'forms' => []];
            }

            if (\email_exists($email)) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('That email address is already registered.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($persist) {
                $userId = \wp_create_user($username, $password, $email);

                if (\is_wp_error($userId)) {
                    return ['tab' => $tab, 'notice' => '', 'errors' => [$userId->get_error_message()], 'forms' => []];
                }

                $newUser = \get_user_by('ID', $userId);

                if ($newUser instanceof \WP_User) {
                    $newUser->set_role($roleSlug);
                }
            }

            if ($persist) {
                // Store credentials in a short-lived, admin-scoped transient for one-time display.
                // Nothing sensitive goes into the redirect URL.
                $transientKey = 'mhb_staff_created_creds_' . \get_current_user_id();
                \set_transient($transientKey, [
                    'username' => $username,
                    'email'    => $email,
                    'role'     => $roleSlug,
                    'password' => $password,
                ], 120);
            }

            return [
                'tab'    => $tab,
                'notice' => 'staff_user_created',
                'errors' => [],
                'forms'  => [],
            ];
        }

        if ($subAction === 'activate' || $subAction === 'deactivate') {
            $targetId = isset($source['staff_user_id']) ? (int) $source['staff_user_id'] : 0;
            $targetUser = $targetId > 0 ? \get_user_by('ID', $targetId) : false;

            if (!$targetUser instanceof \WP_User) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Staff user not found.', 'must-hotel-booking')], 'forms' => []];
            }

            if (!StaffAccess::userHasPortalRole($targetUser)) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('This user is not a plugin staff user.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($subAction === 'deactivate' && $targetId === $currentUserId) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('You cannot deactivate your own account.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($persist) {
                if ($subAction === 'deactivate') {
                    \update_user_meta($targetId, StaffAccess::USERMETA_STAFF_DISABLED, '1');
                } else {
                    \delete_user_meta($targetId, StaffAccess::USERMETA_STAFF_DISABLED);
                }
            }

            return [
                'tab' => $tab,
                'notice' => $subAction === 'deactivate' ? 'staff_user_deactivated' : 'staff_user_activated',
                'errors' => [],
                'forms' => [],
            ];
        }

        if ($subAction === 'delete') {
            $targetId = isset($source['staff_user_id']) ? (int) $source['staff_user_id'] : 0;
            $targetUser = $targetId > 0 ? \get_user_by('ID', $targetId) : false;

            if (!$targetUser instanceof \WP_User) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Staff user not found.', 'must-hotel-booking')], 'forms' => []];
            }

            if (\user_can($targetUser, 'manage_options')) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Administrator accounts cannot be deleted through this tool.', 'must-hotel-booking')], 'forms' => []];
            }

            if (!StaffAccess::userHasPortalRole($targetUser)) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('This user does not have a plugin staff role and cannot be deleted here.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($targetId === $currentUserId) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('You cannot delete your own account.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($persist) {
                \wp_delete_user($targetId);
            }

            return [
                'tab' => $tab,
                'notice' => 'staff_user_deleted',
                'errors' => [],
                'forms' => [],
            ];
        }

        return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Unknown action.', 'must-hotel-booking')], 'forms' => []];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function processDangerousResetAction(array $source, bool $persist): array
    {
        $result = DangerousResetService::processRequest($source, $persist);

        return [
            'tab' => 'maintenance',
            'notice' => (string) ($result['notice'] ?? ''),
            'errors' => isset($result['errors']) && \is_array($result['errors']) ? $result['errors'] : [],
            'forms' => [
                'maintenance' => [
                    'dangerous_reset' => isset($result['form']) && \is_array($result['form']) ? $result['form'] : DangerousResetService::getFormState(),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $updates
     */
    private static function persistUpdates(array $updates): void
    {
        $needsRoleSync = false;
        $needsPageSync = false;

        foreach ($updates as $group => $values) {
            if (!\is_string($group) || !\is_array($values)) {
                continue;
            }

            if ($group === 'managed_pages') {
                foreach ($values as $settingKey => $pageId) {
                    if (!\is_string($settingKey)) {
                        continue;
                    }

                    ManagedPages::assignPage($settingKey, (int) \absint($pageId));
                }

                $needsPageSync = true;
                continue;
            }

            MustBookingConfig::set_group_settings($group, $values);

            if ($group === 'staff_access') {
                $needsRoleSync = true;
                $needsPageSync = true;
            }
        }

        if ($needsRoleSync) {
            StaffAccess::syncRoleCapabilities();
        }

        if ($needsPageSync) {
            ManagedPages::sync();
            PortalRouter::flushRewriteRules();
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeTabForm(string $tab, array $source): array
    {
        if ($tab === 'general') {
            return self::sanitizeGeneralForm($source);
        }

        if ($tab === 'booking_rules') {
            return self::sanitizeBookingRulesForm($source);
        }

        if ($tab === 'checkin_checkout') {
            return self::sanitizeCheckinCheckoutForm($source);
        }

        if ($tab === 'payments_summary') {
            return self::sanitizePaymentsSummaryForm($source);
        }

        if ($tab === 'staff_access') {
            return self::sanitizeStaffAccessForm($source);
        }

        if ($tab === 'branding') {
            return self::sanitizeBrandingForm($source);
        }

        if ($tab === 'managed_pages') {
            return self::sanitizeManagedPagesForm($source);
        }

        if ($tab === 'notifications_summary') {
            return self::sanitizeNotificationsForm($source);
        }

        if ($tab === 'provider') {
            return self::sanitizeProviderForm($source);
        }

        return [
            'form' => [],
            'updates' => [],
            'errors' => [\__('Unknown settings tab.', 'must-hotel-booking')],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getGeneralForm(array $overrides): array
    {
        $form = [
            'hotel_name' => (string) MustBookingConfig::get_setting('hotel_name', MustBookingConfig::get_hotel_name()),
            'hotel_legal_name' => (string) MustBookingConfig::get_setting('hotel_legal_name', ''),
            'hotel_address' => (string) MustBookingConfig::get_setting('hotel_address', ''),
            'hotel_phone' => (string) MustBookingConfig::get_setting('hotel_phone', ''),
            'hotel_email' => (string) MustBookingConfig::get_setting('hotel_email', ''),
            'booking_notification_email' => MustBookingConfig::get_booking_notification_email(),
            'default_country' => (string) MustBookingConfig::get_setting('default_country', 'US'),
            'timezone' => MustBookingConfig::get_timezone(),
            'currency' => MustBookingConfig::get_currency(),
            'currency_display' => (string) MustBookingConfig::get_setting('currency_display', 'symbol_code'),
            'date_format' => (string) MustBookingConfig::get_setting('date_format', \get_option('date_format', 'F j, Y')),
            'time_format' => (string) MustBookingConfig::get_setting('time_format', \get_option('time_format', 'H:i')),
            'hotel_logo_url' => (string) MustBookingConfig::get_setting('hotel_logo_url', ''),
            'portal_logo_url' => (string) MustBookingConfig::get_setting('portal_logo_url', ''),
            'site_environment' => PaymentEngine::getActiveSiteEnvironment(),
        ];

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getBookingRulesForm(array $overrides): array
    {
        $form = [
            'booking_window' => (int) MustBookingConfig::get_setting('booking_window', 365),
            'same_day_booking_allowed' => !empty(MustBookingConfig::get_setting('same_day_booking_allowed', true)),
            'same_day_booking_cutoff_time' => (string) MustBookingConfig::get_setting('same_day_booking_cutoff_time', '18:00'),
            'minimum_nights' => (int) MustBookingConfig::get_setting('minimum_nights', 1),
            'maximum_nights' => (int) MustBookingConfig::get_setting('maximum_nights', 30),
            'max_booking_guests' => MustBookingConfig::get_max_booking_guests(),
            'max_booking_rooms' => MustBookingConfig::get_max_booking_rooms(),
            'allow_multi_room_booking' => !empty(MustBookingConfig::get_setting('allow_multi_room_booking', true)),
            'default_reservation_source' => (string) MustBookingConfig::get_setting('default_reservation_source', 'website'),
            'pending_reservation_expiration_minutes' => (int) MustBookingConfig::get_setting('pending_reservation_expiration_minutes', PaymentEngine::getPendingPaymentCleanupMinutes()),
            'require_phone' => !empty(MustBookingConfig::get_setting('require_phone', true)),
            'require_country' => !empty(MustBookingConfig::get_setting('require_country', true)),
            'enable_special_requests' => !empty(MustBookingConfig::get_setting('enable_special_requests', true)),
            'require_terms_acceptance' => !empty(MustBookingConfig::get_setting('require_terms_acceptance', true)),
            'default_reservation_status' => (string) MustBookingConfig::get_setting('default_reservation_status', 'confirmed'),
            'default_payment_mode' => (string) MustBookingConfig::get_setting('default_payment_mode', 'guest_choice'),
            'cancellation_allowed' => !empty(MustBookingConfig::get_setting('cancellation_allowed', true)),
            'cancellation_notice_hours' => (int) MustBookingConfig::get_setting('cancellation_notice_hours', 48),
            'anti_abuse_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_enabled', false)),
            'anti_abuse_honeypot_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_honeypot_enabled', false)),
            'anti_abuse_min_submit_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_min_submit_enabled', false)),
            'anti_abuse_min_submit_seconds' => (int) MustBookingConfig::get_setting('anti_abuse_min_submit_seconds', 5),
            'anti_abuse_throttle_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_throttle_enabled', false)),
            'anti_abuse_max_attempts' => (int) MustBookingConfig::get_setting('anti_abuse_max_attempts', 5),
            'anti_abuse_window_minutes' => (int) MustBookingConfig::get_setting('anti_abuse_window_minutes', 10),
            'anti_abuse_block_duration_minutes' => (int) MustBookingConfig::get_setting('anti_abuse_block_duration_minutes', 30),
            'anti_abuse_logging_enabled' => !empty(MustBookingConfig::get_setting('anti_abuse_logging_enabled', false)),
        ];

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getCheckinCheckoutForm(array $overrides): array
    {
        $form = [
            'checkin_time' => MustBookingConfig::get_checkin_time(),
            'checkout_time' => MustBookingConfig::get_checkout_time(),
            'allow_early_checkin_request' => !empty(MustBookingConfig::get_setting('allow_early_checkin_request', true)),
            'allow_late_checkout_request' => !empty(MustBookingConfig::get_setting('allow_late_checkout_request', true)),
            'arrival_instructions' => (string) MustBookingConfig::get_setting('arrival_instructions', ''),
            'departure_instructions' => (string) MustBookingConfig::get_setting('departure_instructions', ''),
            'guest_checkin_label' => (string) MustBookingConfig::get_setting('guest_checkin_label', \__('Check-in', 'must-hotel-booking')),
            'guest_checkout_label' => (string) MustBookingConfig::get_setting('guest_checkout_label', \__('Check-out', 'must-hotel-booking')),
        ];

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getPaymentsSummaryForm(array $overrides): array
    {
        $form = [
            'deposit_required' => !empty(MustBookingConfig::get_setting('deposit_required', false)),
            'deposit_type' => (string) MustBookingConfig::get_setting('deposit_type', 'percentage'),
            'deposit_value' => (float) MustBookingConfig::get_setting('deposit_value', 0),
            'tax_rate' => (float) MustBookingConfig::get_setting('tax_rate', 0),
        ];

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getStaffAccessForm(array $overrides): array
    {
        $form = [
            'enable_staff_portal' => !empty(MustBookingConfig::get_setting('enable_staff_portal', false)),
            'portal_page_id' => ManagedPages::getAssignedPageId('portal_page_id'),
            'portal_login_page_id' => ManagedPages::getAssignedPageId('portal_login_page_id'),
            'redirect_worker_after_login' => (string) MustBookingConfig::get_setting('redirect_worker_after_login', 'dashboard'),
            'hide_wp_admin_for_workers' => !empty(MustBookingConfig::get_setting('hide_wp_admin_for_workers', true)),
            'portal_access_roles' => StaffAccess::getPortalAccessRoles(),
            'capability_matrix' => StaffAccess::getCapabilityMatrix(),
            'portal_module_visibility' => PortalRegistry::getModuleVisibility(),
        ];

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getBrandingForm(array $overrides): array
    {
        $form = [
            'primary_color' => (string) MustBookingConfig::get_setting('primary_color', '#0f766e'),
            'secondary_color' => (string) MustBookingConfig::get_setting('secondary_color', '#155e75'),
            'accent_color' => (string) MustBookingConfig::get_setting('accent_color', '#f59e0b'),
            'text_color' => (string) MustBookingConfig::get_setting('text_color', '#16212b'),
            'border_radius' => (int) MustBookingConfig::get_setting('border_radius', 18),
            'font_family' => (string) MustBookingConfig::get_setting('font_family', 'Instrument Sans'),
            'inherit_elementor_colors' => !empty(MustBookingConfig::get_setting('inherit_elementor_colors', false)),
            'inherit_elementor_typography' => !empty(MustBookingConfig::get_setting('inherit_elementor_typography', false)),
            'portal_welcome_title' => (string) MustBookingConfig::get_setting('portal_welcome_title', \__('Welcome back', 'must-hotel-booking')),
            'portal_welcome_text' => (string) MustBookingConfig::get_setting('portal_welcome_text', ''),
            'booking_form_style_preset' => (string) MustBookingConfig::get_setting('booking_form_style_preset', 'balanced'),
        ];

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getManagedPagesForm(array $overrides): array
    {
        $form = [];

        foreach (ManagedPages::getConfig() as $settingKey => $config) {
            unset($config);
            $form[$settingKey] = ManagedPages::getAssignedPageId($settingKey);
        }

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getNotificationsForm(array $overrides): array
    {
        $form = [
            'booking_notification_email' => MustBookingConfig::get_booking_notification_email(),
            'email_from_name' => MustBookingConfig::get_email_from_name(),
            'email_from_email' => MustBookingConfig::get_email_from_email(),
            'email_reply_to' => MustBookingConfig::get_email_reply_to(),
            'email_logo_url' => MustBookingConfig::get_email_logo_url(),
            'email_button_color' => MustBookingConfig::get_email_button_color(),
            'email_footer_text' => MustBookingConfig::get_email_footer_text(),
            'email_layout_type' => MustBookingConfig::get_email_layout_type(),
        ];

        return \array_merge($form, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getMaintenanceForm(array $overrides): array
    {
        return [
            'dangerous_reset' => DangerousResetService::getFormState(
                isset($overrides['dangerous_reset']) && \is_array($overrides['dangerous_reset'])
                    ? $overrides['dangerous_reset']
                    : []
            ),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getProviderForm(array $overrides): array
    {
        return \array_merge(MustBookingConfig::get_clock_settings(), $overrides);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeGeneralForm(array $source): array
    {
        $form = [
            'hotel_name' => \sanitize_text_field((string) \wp_unslash($source['hotel_name'] ?? '')),
            'hotel_legal_name' => \sanitize_text_field((string) \wp_unslash($source['hotel_legal_name'] ?? '')),
            'hotel_address' => \sanitize_textarea_field((string) \wp_unslash($source['hotel_address'] ?? '')),
            'hotel_phone' => \sanitize_text_field((string) \wp_unslash($source['hotel_phone'] ?? '')),
            'hotel_email' => \sanitize_email((string) \wp_unslash($source['hotel_email'] ?? '')),
            'booking_notification_email' => \sanitize_email((string) \wp_unslash($source['booking_notification_email'] ?? '')),
            'default_country' => \strtoupper(\sanitize_text_field((string) \wp_unslash($source['default_country'] ?? 'US'))),
            'timezone' => \sanitize_text_field((string) \wp_unslash($source['timezone'] ?? 'UTC')),
            'currency' => \strtoupper(\sanitize_text_field((string) \wp_unslash($source['currency'] ?? 'USD'))),
            'currency_display' => \sanitize_key((string) \wp_unslash($source['currency_display'] ?? 'symbol_code')),
            'date_format' => \sanitize_text_field((string) \wp_unslash($source['date_format'] ?? 'F j, Y')),
            'time_format' => \sanitize_text_field((string) \wp_unslash($source['time_format'] ?? 'H:i')),
            'hotel_logo_url' => \esc_url_raw((string) \wp_unslash($source['hotel_logo_url'] ?? '')),
            'portal_logo_url' => \esc_url_raw((string) \wp_unslash($source['portal_logo_url'] ?? '')),
            'site_environment' => PaymentEngine::normalizeStripeEnvironment((string) \wp_unslash($source['site_environment'] ?? 'production')),
        ];
        $errors = [];

        if ($form['hotel_name'] === '') {
            $errors[] = \__('Hotel name is required.', 'must-hotel-booking');
        }

        if ($form['hotel_email'] !== '' && !\is_email($form['hotel_email'])) {
            $errors[] = \__('Hotel email must be a valid email address.', 'must-hotel-booking');
        }

        if (!\is_email($form['booking_notification_email'])) {
            $errors[] = \__('Booking notification email must be a valid email address.', 'must-hotel-booking');
        }

        if (!self::isValidTimezone($form['timezone'])) {
            $errors[] = \__('Please select a valid timezone.', 'must-hotel-booking');
        }

        if (!\in_array($form['currency_display'], ['symbol', 'symbol_code', 'code'], true)) {
            $form['currency_display'] = 'symbol_code';
        }

        return [
            'form' => $form,
            'updates' => [
                'general' => [
                    'hotel_name' => $form['hotel_name'],
                    'hotel_legal_name' => $form['hotel_legal_name'],
                    'hotel_address' => $form['hotel_address'],
                    'hotel_phone' => $form['hotel_phone'],
                    'hotel_email' => $form['hotel_email'],
                    'default_country' => $form['default_country'],
                    'timezone' => $form['timezone'],
                    'currency' => $form['currency'],
                    'currency_display' => $form['currency_display'],
                    'date_format' => $form['date_format'],
                    'time_format' => $form['time_format'],
                    'hotel_logo_url' => $form['hotel_logo_url'],
                    'portal_logo_url' => $form['portal_logo_url'],
                    'site_environment' => $form['site_environment'],
                ],
                'notifications_summary' => [
                    'booking_notification_email' => $form['booking_notification_email'],
                ],
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeBookingRulesForm(array $source): array
    {
        $form = self::getBookingRulesForm([]);
        $form['booking_window'] = (int) \absint(\wp_unslash($source['booking_window'] ?? $form['booking_window']));
        $form['same_day_booking_allowed'] = self::parseBool($source, 'same_day_booking_allowed');
        $form['same_day_booking_cutoff_time'] = \sanitize_text_field((string) \wp_unslash($source['same_day_booking_cutoff_time'] ?? $form['same_day_booking_cutoff_time']));
        $form['minimum_nights'] = (int) \absint(\wp_unslash($source['minimum_nights'] ?? $form['minimum_nights']));
        $form['maximum_nights'] = (int) \absint(\wp_unslash($source['maximum_nights'] ?? $form['maximum_nights']));
        $form['max_booking_guests'] = (int) \absint(\wp_unslash($source['max_booking_guests'] ?? $form['max_booking_guests']));
        $form['max_booking_rooms'] = (int) \absint(\wp_unslash($source['max_booking_rooms'] ?? $form['max_booking_rooms']));
        $form['allow_multi_room_booking'] = self::parseBool($source, 'allow_multi_room_booking');
        $form['default_reservation_source'] = \sanitize_key((string) \wp_unslash($source['default_reservation_source'] ?? $form['default_reservation_source']));
        $form['pending_reservation_expiration_minutes'] = (int) \absint(\wp_unslash($source['pending_reservation_expiration_minutes'] ?? $form['pending_reservation_expiration_minutes']));
        $form['require_phone'] = self::parseBool($source, 'require_phone');
        $form['require_country'] = self::parseBool($source, 'require_country');
        $form['enable_special_requests'] = self::parseBool($source, 'enable_special_requests');
        $form['require_terms_acceptance'] = self::parseBool($source, 'require_terms_acceptance');
        $form['default_reservation_status'] = \sanitize_key((string) \wp_unslash($source['default_reservation_status'] ?? $form['default_reservation_status']));
        $form['default_payment_mode'] = \sanitize_key((string) \wp_unslash($source['default_payment_mode'] ?? $form['default_payment_mode']));
        $form['cancellation_allowed'] = self::parseBool($source, 'cancellation_allowed');
        $form['cancellation_notice_hours'] = (int) \absint(\wp_unslash($source['cancellation_notice_hours'] ?? $form['cancellation_notice_hours']));
        $form['anti_abuse_enabled'] = self::parseBool($source, 'anti_abuse_enabled');
        $form['anti_abuse_honeypot_enabled'] = self::parseBool($source, 'anti_abuse_honeypot_enabled');
        $form['anti_abuse_min_submit_enabled'] = self::parseBool($source, 'anti_abuse_min_submit_enabled');
        $form['anti_abuse_min_submit_seconds'] = (int) \absint(\wp_unslash($source['anti_abuse_min_submit_seconds'] ?? $form['anti_abuse_min_submit_seconds']));
        $form['anti_abuse_throttle_enabled'] = self::parseBool($source, 'anti_abuse_throttle_enabled');
        $form['anti_abuse_max_attempts'] = (int) \absint(\wp_unslash($source['anti_abuse_max_attempts'] ?? $form['anti_abuse_max_attempts']));
        $form['anti_abuse_window_minutes'] = (int) \absint(\wp_unslash($source['anti_abuse_window_minutes'] ?? $form['anti_abuse_window_minutes']));
        $form['anti_abuse_block_duration_minutes'] = (int) \absint(\wp_unslash($source['anti_abuse_block_duration_minutes'] ?? $form['anti_abuse_block_duration_minutes']));
        $form['anti_abuse_logging_enabled'] = self::parseBool($source, 'anti_abuse_logging_enabled');
        $errors = [];

        if ($form['booking_window'] < 1 || $form['booking_window'] > 3650) {
            $errors[] = \__('Booking window must be between 1 and 3650 days.', 'must-hotel-booking');
        }

        if (!$form['same_day_booking_allowed']) {
            $form['same_day_booking_cutoff_time'] = '';
        } elseif (!self::isValidTime($form['same_day_booking_cutoff_time'])) {
            $errors[] = \__('Same-day booking cutoff must use HH:MM format.', 'must-hotel-booking');
        }

        if ($form['minimum_nights'] < 1 || $form['minimum_nights'] > 365) {
            $errors[] = \__('Minimum nights must be between 1 and 365.', 'must-hotel-booking');
        }

        if ($form['maximum_nights'] < 1 || $form['maximum_nights'] > 365) {
            $errors[] = \__('Maximum nights must be between 1 and 365.', 'must-hotel-booking');
        }

        if ($form['minimum_nights'] > $form['maximum_nights']) {
            $errors[] = \__('Minimum nights cannot be greater than maximum nights.', 'must-hotel-booking');
        }

        if ($form['max_booking_guests'] < 1 || $form['max_booking_guests'] > 100) {
            $errors[] = \__('Maximum guests per reservation must be between 1 and 100.', 'must-hotel-booking');
        }

        if ($form['max_booking_rooms'] < 1 || $form['max_booking_rooms'] > 25) {
            $errors[] = \__('Maximum rooms per reservation must be between 1 and 25.', 'must-hotel-booking');
        }

        if ($form['pending_reservation_expiration_minutes'] < 5 || $form['pending_reservation_expiration_minutes'] > 1440) {
            $errors[] = \__('Pending reservation expiration must be between 5 and 1440 minutes.', 'must-hotel-booking');
        }

        if ($form['cancellation_notice_hours'] > 720) {
            $errors[] = \__('Cancellation notice must be 720 hours or less.', 'must-hotel-booking');
        }

        if ($form['anti_abuse_min_submit_enabled'] && ($form['anti_abuse_min_submit_seconds'] < 1 || $form['anti_abuse_min_submit_seconds'] > 60)) {
            $errors[] = \__('Minimum submit seconds must be between 1 and 60.', 'must-hotel-booking');
        }

        if ($form['anti_abuse_throttle_enabled'] && ($form['anti_abuse_max_attempts'] < 1 || $form['anti_abuse_max_attempts'] > 50)) {
            $errors[] = \__('Maximum attempts must be between 1 and 50.', 'must-hotel-booking');
        }

        if ($form['anti_abuse_throttle_enabled'] && ($form['anti_abuse_window_minutes'] < 1 || $form['anti_abuse_window_minutes'] > 1440)) {
            $errors[] = \__('Throttle window must be between 1 and 1440 minutes.', 'must-hotel-booking');
        }

        if ($form['anti_abuse_throttle_enabled'] && ($form['anti_abuse_block_duration_minutes'] < 1 || $form['anti_abuse_block_duration_minutes'] > 1440)) {
            $errors[] = \__('Throttle block duration must be between 1 and 1440 minutes.', 'must-hotel-booking');
        }

        return [
            'form' => $form,
            'updates' => ['booking_rules' => $form],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeCheckinCheckoutForm(array $source): array
    {
        $form = self::getCheckinCheckoutForm([]);
        $form['checkin_time'] = \sanitize_text_field((string) \wp_unslash($source['checkin_time'] ?? $form['checkin_time']));
        $form['checkout_time'] = \sanitize_text_field((string) \wp_unslash($source['checkout_time'] ?? $form['checkout_time']));
        $form['allow_early_checkin_request'] = self::parseBool($source, 'allow_early_checkin_request');
        $form['allow_late_checkout_request'] = self::parseBool($source, 'allow_late_checkout_request');
        $form['arrival_instructions'] = \sanitize_textarea_field((string) \wp_unslash($source['arrival_instructions'] ?? ''));
        $form['departure_instructions'] = \sanitize_textarea_field((string) \wp_unslash($source['departure_instructions'] ?? ''));
        $form['guest_checkin_label'] = \sanitize_text_field((string) \wp_unslash($source['guest_checkin_label'] ?? ''));
        $form['guest_checkout_label'] = \sanitize_text_field((string) \wp_unslash($source['guest_checkout_label'] ?? ''));
        $errors = [];

        if (!self::isValidTime($form['checkin_time'])) {
            $errors[] = \__('Default check-in time must use HH:MM format.', 'must-hotel-booking');
        }

        if (!self::isValidTime($form['checkout_time'])) {
            $errors[] = \__('Default check-out time must use HH:MM format.', 'must-hotel-booking');
        }

        if ($form['guest_checkin_label'] === '') {
            $form['guest_checkin_label'] = \__('Check-in', 'must-hotel-booking');
        }

        if ($form['guest_checkout_label'] === '') {
            $form['guest_checkout_label'] = \__('Check-out', 'must-hotel-booking');
        }

        return [
            'form' => $form,
            'updates' => ['checkin_checkout' => $form],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizePaymentsSummaryForm(array $source): array
    {
        $form = self::getPaymentsSummaryForm([]);
        $form['deposit_required'] = self::parseBool($source, 'deposit_required');
        $form['deposit_type'] = \sanitize_key((string) \wp_unslash($source['deposit_type'] ?? $form['deposit_type']));
        $form['deposit_value'] = \is_numeric($source['deposit_value'] ?? null) ? (float) $source['deposit_value'] : 0.0;
        $form['tax_rate'] = \is_numeric($source['tax_rate'] ?? null) ? (float) $source['tax_rate'] : 0.0;
        $errors = [];

        if (!\in_array($form['deposit_type'], ['fixed', 'percentage'], true)) {
            $errors[] = \__('Deposit type must be fixed or percentage.', 'must-hotel-booking');
        }

        if ($form['deposit_value'] < 0) {
            $errors[] = \__('Deposit value cannot be negative.', 'must-hotel-booking');
        }

        if ($form['deposit_type'] === 'percentage' && $form['deposit_value'] > 100) {
            $errors[] = \__('Percentage deposits cannot exceed 100%.', 'must-hotel-booking');
        }

        if ($form['tax_rate'] < 0 || $form['tax_rate'] > 100) {
            $errors[] = \__('Tax rate must be between 0 and 100.', 'must-hotel-booking');
        }

        return [
            'form' => $form,
            'updates' => ['payments_summary' => $form],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeStaffAccessForm(array $source): array
    {
        $form = self::getStaffAccessForm([]);
        $form['enable_staff_portal'] = self::parseBool($source, 'enable_staff_portal');
        $form['portal_page_id'] = (int) \absint(\wp_unslash($source['portal_page_id'] ?? 0));
        $form['portal_login_page_id'] = (int) \absint(\wp_unslash($source['portal_login_page_id'] ?? 0));
        $form['redirect_worker_after_login'] = \sanitize_key((string) \wp_unslash($source['redirect_worker_after_login'] ?? $form['redirect_worker_after_login']));
        $form['hide_wp_admin_for_workers'] = self::parseBool($source, 'hide_wp_admin_for_workers');
        $form['portal_access_roles'] = [];
        $form['capability_matrix'] = StaffAccess::getDefaultCapabilityMatrix();
        $form['portal_module_visibility'] = PortalRegistry::getDefaultModuleVisibility();
        $errors = [];
        $redirectOptions = [];

        foreach ((array) ($source['portal_access_roles'] ?? []) as $role) {
            $role = \sanitize_key((string) \wp_unslash($role));

            if (isset(StaffAccess::getRoleLabels()[$role])) {
                $form['portal_access_roles'][$role] = $role;
            }
        }

        foreach (StaffAccess::getDefaultCapabilityMatrix() as $role => $capabilities) {
            $row = isset($source['capability_matrix'][$role]) && \is_array($source['capability_matrix'][$role])
                ? (array) $source['capability_matrix'][$role]
                : [];

            foreach ($capabilities as $capability => $enabled) {
                $form['capability_matrix'][$role][$capability] = !empty($row[$capability]);
                unset($enabled);
            }

            $form['capability_matrix'][$role]['access_settings'] = false;
        }

        foreach (PortalRegistry::getDefinitions() as $moduleKey => $definition) {
            $redirectOptions[$moduleKey] = $moduleKey;
            $form['portal_module_visibility'][$moduleKey] = !empty($source['portal_module_visibility'][$moduleKey]);
            unset($definition);
        }

        if (!isset($redirectOptions[$form['redirect_worker_after_login']])) {
            $form['redirect_worker_after_login'] = 'dashboard';
        }

        foreach (['portal_page_id', 'portal_login_page_id'] as $pageField) {
            $pageId = (int) $form[$pageField];

            if ($pageId <= 0) {
                continue;
            }

            $page = \get_post($pageId);

            if (!$page instanceof \WP_Post || $page->post_type !== 'page') {
                $errors[] = \__('Staff portal assignments must point to valid WordPress pages.', 'must-hotel-booking');
            }
        }

        if ($form['enable_staff_portal'] && empty($form['portal_access_roles'])) {
            $errors[] = \__('Select at least one staff role that can access the portal.', 'must-hotel-booking');
        }

        if ($form['enable_staff_portal'] && !\in_array(true, $form['portal_module_visibility'], true)) {
            $errors[] = \__('Enable at least one portal module while the staff portal is active.', 'must-hotel-booking');
        }

        return [
            'form' => $form,
            'updates' => [
                'staff_access' => [
                    'enable_staff_portal' => $form['enable_staff_portal'],
                    'redirect_worker_after_login' => $form['redirect_worker_after_login'],
                    'hide_wp_admin_for_workers' => $form['hide_wp_admin_for_workers'],
                    'portal_access_roles' => \array_values($form['portal_access_roles']),
                    'capability_matrix' => $form['capability_matrix'],
                    'portal_module_visibility' => $form['portal_module_visibility'],
                ],
                'managed_pages' => [
                    'portal_page_id' => $form['portal_page_id'],
                    'portal_login_page_id' => $form['portal_login_page_id'],
                ],
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeBrandingForm(array $source): array
    {
        $form = self::getBrandingForm([]);
        $form['primary_color'] = \sanitize_hex_color((string) \wp_unslash($source['primary_color'] ?? '')) ?: $form['primary_color'];
        $form['secondary_color'] = \sanitize_hex_color((string) \wp_unslash($source['secondary_color'] ?? '')) ?: $form['secondary_color'];
        $form['accent_color'] = \sanitize_hex_color((string) \wp_unslash($source['accent_color'] ?? '')) ?: $form['accent_color'];
        $form['text_color'] = \sanitize_hex_color((string) \wp_unslash($source['text_color'] ?? '')) ?: $form['text_color'];
        $form['border_radius'] = (int) \absint(\wp_unslash($source['border_radius'] ?? $form['border_radius']));
        $form['font_family'] = \sanitize_text_field((string) \wp_unslash($source['font_family'] ?? $form['font_family']));
        $form['inherit_elementor_colors'] = self::parseBool($source, 'inherit_elementor_colors');
        $form['inherit_elementor_typography'] = self::parseBool($source, 'inherit_elementor_typography');
        $form['portal_welcome_title'] = \sanitize_text_field((string) \wp_unslash($source['portal_welcome_title'] ?? ''));
        $form['portal_welcome_text'] = \sanitize_textarea_field((string) \wp_unslash($source['portal_welcome_text'] ?? ''));
        $form['booking_form_style_preset'] = \sanitize_key((string) \wp_unslash($source['booking_form_style_preset'] ?? $form['booking_form_style_preset']));
        $errors = [];

        if ($form['border_radius'] > 40) {
            $errors[] = \__('Border radius must be between 0 and 40.', 'must-hotel-booking');
        }

        if ($form['font_family'] === '') {
            $form['font_family'] = 'Instrument Sans';
        }

        return [
            'form' => $form,
            'updates' => ['branding' => $form],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeManagedPagesForm(array $source): array
    {
        $form = self::getManagedPagesForm([]);
        $errors = [];

        foreach (ManagedPages::getConfig() as $settingKey => $config) {
            unset($config);
            $form[$settingKey] = (int) \absint(\wp_unslash($source[$settingKey] ?? $form[$settingKey]));

            if ((int) $form[$settingKey] <= 0) {
                continue;
            }

            $page = \get_post((int) $form[$settingKey]);

            if (!$page instanceof \WP_Post || $page->post_type !== 'page') {
                $errors[] = \sprintf(
                    /* translators: %s is a managed page label. */
                    \__('%s must be assigned to a valid WordPress page.', 'must-hotel-booking'),
                    (string) (ManagedPages::getConfig()[$settingKey]['title'] ?? $settingKey)
                );
            }
        }

        return [
            'form' => $form,
            'updates' => ['managed_pages' => $form],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeNotificationsForm(array $source): array
    {
        $form = self::getNotificationsForm([]);
        $form['booking_notification_email'] = \sanitize_email((string) \wp_unslash($source['booking_notification_email'] ?? ''));
        $form['email_from_name'] = \sanitize_text_field((string) \wp_unslash($source['email_from_name'] ?? ''));
        $form['email_from_email'] = \sanitize_email((string) \wp_unslash($source['email_from_email'] ?? ''));
        $form['email_reply_to'] = \sanitize_email((string) \wp_unslash($source['email_reply_to'] ?? ''));
        $form['email_logo_url'] = \esc_url_raw((string) \wp_unslash($source['email_logo_url'] ?? ''));
        $form['email_button_color'] = \sanitize_hex_color((string) \wp_unslash($source['email_button_color'] ?? '')) ?: '#141414';
        $form['email_footer_text'] = \sanitize_textarea_field((string) \wp_unslash($source['email_footer_text'] ?? ''));
        $form['email_layout_type'] = EmailLayoutEngine::normalizeLayoutType((string) \wp_unslash($source['email_layout_type'] ?? 'classic'));
        $errors = [];

        if (!\is_email($form['booking_notification_email'])) {
            $errors[] = \__('Booking notification recipient must be a valid email address.', 'must-hotel-booking');
        }

        if ($form['email_from_name'] === '') {
            $errors[] = \__('Sender name is required.', 'must-hotel-booking');
        }

        if (!\is_email($form['email_from_email'])) {
            $errors[] = \__('Sender email must be a valid email address.', 'must-hotel-booking');
        }

        if ($form['email_reply_to'] !== '' && !\is_email($form['email_reply_to'])) {
            $errors[] = \__('Reply-to must be a valid email address.', 'must-hotel-booking');
        }

        return [
            'form' => $form,
            'updates' => ['notifications_summary' => $form],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeProviderForm(array $source): array
    {
        $form = self::getProviderForm([]);
        $form['website_booking_flow_mode'] = \sanitize_key((string) \wp_unslash($source['website_booking_flow_mode'] ?? $form['website_booking_flow_mode']));
        $form['clock_wbe_inline_head_snippet'] = (string) \wp_unslash($source['clock_wbe_inline_head_snippet'] ?? $form['clock_wbe_inline_head_snippet']);
        $form['provider_mode'] = \sanitize_key((string) \wp_unslash($source['provider_mode'] ?? $form['provider_mode']));
        $form['clock_enabled'] = self::parseBool($source, 'clock_enabled');
        $form['clock_environment'] = \sanitize_key((string) \wp_unslash($source['clock_environment'] ?? $form['clock_environment']));
        $form['clock_pms_api_enabled'] = self::parseBool($source, 'clock_pms_api_enabled');
        $form['clock_base_api_enabled'] = self::parseBool($source, 'clock_base_api_enabled');
        $form['clock_pms_api_url'] = \esc_url_raw((string) \wp_unslash($source['clock_pms_api_url'] ?? ''));
        $form['clock_base_api_url'] = \esc_url_raw((string) \wp_unslash($source['clock_base_api_url'] ?? ''));
        $form['clock_api_base_url'] = \esc_url_raw((string) \wp_unslash($source['clock_api_base_url'] ?? ''));
        $form['clock_region'] = ClockEndpointResolver::normalizeRegion((string) \wp_unslash($source['clock_region'] ?? ''));
        $form['clock_api_type'] = ClockEndpointResolver::normalizeApiType((string) \wp_unslash($source['clock_api_type'] ?? 'pms_api'));
        $form['clock_subscription_id'] = ClockEndpointResolver::normalizeNumericId((string) \wp_unslash($source['clock_subscription_id'] ?? ''));
        $form['clock_account_id'] = ClockEndpointResolver::normalizeNumericId((string) \wp_unslash($source['clock_account_id'] ?? ''));
        $form['clock_api_user'] = \sanitize_text_field((string) \wp_unslash($source['clock_api_user'] ?? ''));
        $form['clock_api_key'] = \sanitize_text_field((string) \wp_unslash($source['clock_api_key'] ?? ''));
        $form['clock_property_id'] = \sanitize_text_field((string) \wp_unslash($source['clock_property_id'] ?? ''));
        $form['clock_wbe_hotel_id'] = ClockEndpointResolver::normalizeNumericId((string) \wp_unslash($source['clock_wbe_hotel_id'] ?? ''));
        $form['fallback_to_local_when_clock_unavailable'] = self::parseBool($source, 'fallback_to_local_when_clock_unavailable');
        $form['clock_connection_path'] = ClockConfig::normalizePath((string) \wp_unslash($source['clock_connection_path'] ?? ($form['clock_connection_path'] ?? '/room_types')));
        $form['clock_room_types_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_room_types_path'] ?? ''));
        $form['clock_rooms_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_rooms_path'] ?? ''));
        $form['clock_rates_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_rates_path'] ?? ''));
        $form['clock_rates_availability_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_rates_availability_path'] ?? ''));
        $form['clock_products_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_products_path'] ?? ''));
        $form['clock_wbe_room_type_rates_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_wbe_room_type_rates_path'] ?? ''));
        $form['clock_rate_plans_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_rate_plans_path'] ?? ''));
        $form['clock_availability_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_availability_path'] ?? ''));
        $form['clock_quote_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_quote_path'] ?? ''));
        $form['clock_reservation_create_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_reservation_create_path'] ?? ''));
        $form['clock_reservation_status_update_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_reservation_status_update_path'] ?? ''));
        $form['clock_reservation_cancel_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_reservation_cancel_path'] ?? ''));
        $form['clock_reservation_room_update_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_reservation_room_update_path'] ?? ''));
        $form['clock_reservation_stay_update_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_reservation_stay_update_path'] ?? ''));
        $form['clock_reservation_guest_update_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_reservation_guest_update_path'] ?? ''));
        $form['clock_reservation_fetch_path'] = ClockConfig::normalizeOptionalPath((string) \wp_unslash($source['clock_reservation_fetch_path'] ?? ''));
        $form['clock_webhook_secret'] = \sanitize_text_field((string) \wp_unslash($source['clock_webhook_secret'] ?? ''));
        $form['clock_timeout_seconds'] = (int) \absint(\wp_unslash($source['clock_timeout_seconds'] ?? $form['clock_timeout_seconds']));
        $errors = [];

        if (!\in_array($form['website_booking_flow_mode'], ['plugin_checkout', 'clock_wbe_inline'], true)) {
            $form['website_booking_flow_mode'] = 'plugin_checkout';
        }

        $form['clock_wbe_inline_head_snippet'] = \str_replace(["\r\n", "\r"], "\n", $form['clock_wbe_inline_head_snippet']);
        $form['clock_wbe_inline_head_snippet'] = \str_replace("\0", '', $form['clock_wbe_inline_head_snippet']);
        $form['clock_wbe_inline_head_snippet'] = \trim((string) \preg_replace('/<\?(php|=)?/i', '', $form['clock_wbe_inline_head_snippet']));

        if (!\in_array($form['provider_mode'], ['local', 'clock'], true)) {
            $form['provider_mode'] = 'local';
        }

        if (!\in_array($form['clock_environment'], ['sandbox', 'production', 'custom'], true)) {
            $form['clock_environment'] = 'production';
        }

        foreach (['clock_pms_api_url' => __('Clock PMS API URL must be a valid URL.', 'must-hotel-booking'), 'clock_base_api_url' => __('Clock Base API URL must be a valid URL.', 'must-hotel-booking'), 'clock_api_base_url' => __('Clock legacy API base URL must be a valid URL.', 'must-hotel-booking')] as $urlField => $message) {
            if ((string) ($form[$urlField] ?? '') !== '' && \filter_var((string) $form[$urlField], \FILTER_VALIDATE_URL) === false) {
                $errors[] = $message;
            }
        }

        if ($form['clock_timeout_seconds'] < 1 || $form['clock_timeout_seconds'] > 60) {
            $errors[] = \__('Clock timeout must be between 1 and 60 seconds.', 'must-hotel-booking');
        }

        if (!empty($errors)) {
            return [
                'form' => $form,
                'updates' => [],
                'errors' => $errors,
            ];
        }

        return [
            'form' => $form,
            'updates' => ['provider' => $form],
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function parseBool(array $source, string $key): bool
    {
        return !empty($source[$key]);
    }

    private static function isValidTime(string $value): bool
    {
        return (bool) \preg_match('/^(2[0-3]|[01]\d):([0-5]\d)$/', \trim($value));
    }

    private static function isValidTimezone(string $timezone): bool
    {
        $timezone = \trim($timezone);

        if ($timezone === '') {
            return false;
        }

        return \in_array($timezone, \timezone_identifiers_list(), true)
            || \preg_match('/^UTC[+-]\d{1,2}(?::\d{2})?$/', $timezone) === 1;
    }

    /**
     * @return array<string, string>
     */
    private static function getCountryOptions(): array
    {
        return [
            'AL' => 'Albania',
            'AT' => 'Austria',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'DE' => 'Germany',
            'ES' => 'Spain',
            'FR' => 'France',
            'GB' => 'United Kingdom',
            'GR' => 'Greece',
            'HR' => 'Croatia',
            'IT' => 'Italy',
            'NL' => 'Netherlands',
            'PL' => 'Poland',
            'PT' => 'Portugal',
            'SE' => 'Sweden',
            'TR' => 'Turkey',
            'US' => 'United States',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getCurrencyOptions(): array
    {
        return [
            'USD' => 'USD',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'ALL' => 'ALL',
            'CHF' => 'CHF',
        ];
    }

    /**
     * Handle Clock provider mapping save / delete actions.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function processProviderMappingAction(array $source, bool $persist): array
    {
        $tab = 'provider';
        $nonce = isset($source['must_settings_nonce']) ? (string) \wp_unslash($source['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_provider_mapping')) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        $subAction = isset($source['mapping_sub_action']) ? \sanitize_key((string) \wp_unslash($source['mapping_sub_action'])) : '';

        if ($subAction === 'delete_mapping') {
            $mappingId = isset($source['mapping_id']) ? \absint(\wp_unslash($source['mapping_id'])) : 0;

            if ($mappingId <= 0) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Invalid mapping ID.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($persist) {
                (new ProviderMappingRepository())->delete($mappingId);
            }

            return ['tab' => $tab, 'notice' => 'provider_mapping_deleted', 'errors' => [], 'forms' => []];
        }

        if ($subAction === 'save_mapping') {
            $entityType = isset($source['mapping_entity_type']) ? \sanitize_key((string) \wp_unslash($source['mapping_entity_type'])) : '';
            $localId = isset($source['mapping_local_id']) ? \absint(\wp_unslash($source['mapping_local_id'])) : 0;
            $externalId = isset($source['mapping_external_id']) ? \sanitize_text_field((string) \wp_unslash($source['mapping_external_id'])) : '';
            $externalCode = isset($source['mapping_external_code']) ? \sanitize_text_field((string) \wp_unslash($source['mapping_external_code'])) : '';
            $externalParentId = isset($source['mapping_external_parent_id']) ? \sanitize_text_field((string) \wp_unslash($source['mapping_external_parent_id'])) : '';
            $displayName = isset($source['mapping_display_name']) ? \sanitize_text_field((string) \wp_unslash($source['mapping_display_name'])) : '';

            $allowedEntityTypes = ['accommodation' => 'must_rooms', 'physical_room' => 'mhb_rooms', 'rate_plan' => 'mhb_rate_plans'];

            if (!isset($allowedEntityTypes[$entityType])) {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Unknown mapping entity type.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($localId <= 0 || $externalId === '') {
                return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Local ID and external ID are both required.', 'must-hotel-booking')], 'forms' => []];
            }

            if ($persist) {
                $catalogItem = self::findClockCatalogItemForMapping($entityType, $externalId);
                $metadata = [
                    'mapping_source' => 'admin_manual',
                    'phase' => 'direct_clock_phase_3',
                ];

                if (!empty($catalogItem)) {
                    $metadata['clock_catalog_item'] = $catalogItem;

                    if ($entityType === 'rate_plan') {
                        $metadata['public_visible'] = (string) ($catalogItem['public_visible'] ?? 'unknown');
                    }
                }

                (new ProviderMappingRepository())->save([
                    'provider' => ProviderManager::CLOCK_MODE,
                    'entity_type' => $entityType,
                    'local_table' => $allowedEntityTypes[$entityType],
                    'local_id' => $localId,
                    'external_id' => $externalId,
                    'external_code' => $externalCode,
                    'external_parent_id' => $externalParentId,
                    'display_name' => $displayName,
                    'status' => 'active',
                    'metadata' => $metadata,
                    'last_synced_at' => \current_time('mysql'),
                ]);
            }

            return ['tab' => $tab, 'notice' => 'provider_mapping_saved', 'errors' => [], 'forms' => []];
        }

        return ['tab' => $tab, 'notice' => '', 'errors' => [\__('Unknown mapping action.', 'must-hotel-booking')], 'forms' => []];
    }

    /**
     * @param array<string, mixed> $diagnostics
     */
    private static function renderHero(array $diagnostics): void
    {
        $critical = (int) ($diagnostics['critical_issues'] ?? 0);
        $warnings = (int) ($diagnostics['warnings'] ?? 0);
        $hotelName = MustBookingConfig::get_hotel_name();
        $staffPortalEnabled = !empty(MustBookingConfig::get_setting('enable_staff_portal', false));

        echo '<div class="must-dashboard-hero must-settings-hero">';
        echo '<div class="must-dashboard-hero-copy">';
        echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Control Center', 'must-hotel-booking') . '</span>';
        echo '<h1>' . \esc_html__('Settings', 'must-hotel-booking') . '</h1>';
        echo '<p class="description">' . \esc_html__('Centralize hotel identity, booking rules, staff access, managed pages, email settings, and maintenance work from one consistent admin surface.', 'must-hotel-booking') . '</p>';
        echo '<div class="must-dashboard-hero-meta">';
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Hotel', 'must-hotel-booking') . '</strong> ' . \esc_html($hotelName !== '' ? $hotelName : __('Not set', 'must-hotel-booking')) . '</span>';
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Critical', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $critical) . '</span>';
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Warnings', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $warnings) . '</span>';
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Staff Portal', 'must-hotel-booking') . '</strong> ' . \esc_html($staffPortalEnabled ? __('Active', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking')) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="must-dashboard-hero-actions">';
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_settings_page_url(['tab' => 'maintenance'])) . '">' . \esc_html__('Review Diagnostics', 'must-hotel-booking') . '</a>';

        if (\function_exists(__NAMESPACE__ . '\get_admin_payments_page_url')) {
            echo '<a class="button button-primary" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments Page', 'must-hotel-booking') . '</a>';
        }

        echo '</div>';
        echo '</div>';
    }

    private static function renderAdminNotice(string $notice): void
    {
        if ($notice === '') {
            $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';
        }

        $messages = [
            'general_saved' => \__('General settings saved.', 'must-hotel-booking'),
            'booking_rules_saved' => \__('Booking rules saved.', 'must-hotel-booking'),
            'checkin_checkout_saved' => \__('Check-in and check-out settings saved.', 'must-hotel-booking'),
            'payments_summary_saved' => \__('Payment summary settings saved.', 'must-hotel-booking'),
            'staff_access_saved' => \__('Staff access settings saved and role capabilities synced.', 'must-hotel-booking'),
            'staff_user_activated' => \__('Staff user activated. They can now log in to the staff portal.', 'must-hotel-booking'),
            'staff_user_deactivated' => \__('Staff user deactivated. They are blocked from the staff portal.', 'must-hotel-booking'),
            'staff_user_deleted' => \__('Staff user deleted.', 'must-hotel-booking'),
            'branding_saved' => \__('Branding settings saved.', 'must-hotel-booking'),
            'managed_pages_saved' => \__('Managed page assignments saved.', 'must-hotel-booking'),
            'notifications_summary_saved' => \__('Notification and email summary settings saved.', 'must-hotel-booking'),
            'provider_saved' => \__('Provider settings saved.', 'must-hotel-booking'),
            'clock_connection_test_succeeded' => \__('Clock connection test succeeded.', 'must-hotel-booking'),
            'clock_catalog_fetched' => \__('Clock catalog fetched and cached for mapping.', 'must-hotel-booking'),
            'clock_catalog_fetched_with_warnings' => \__('Clock catalog fetch completed with warnings. Review the catalog diagnostics below.', 'must-hotel-booking'),
            'clock_api_defaults_applied' => \__('Clock API defaults applied. Enter the PMS/Base API URLs, API user, and API key, then save and test the connection.', 'must-hotel-booking'),
            'provider_sync_jobs_processed' => \__('Provider sync jobs processed.', 'must-hotel-booking'),
            'clock_reservation_refresh_queued' => \__('Clock reservation refresh queued.', 'must-hotel-booking'),
            'managed_page_repaired' => \__('Managed page action completed.', 'must-hotel-booking'),
            'maintenance_action_completed' => \__('Maintenance action completed.', 'must-hotel-booking'),
            'hotel_operational_reset_completed' => \__('Hotel operational data reset completed.', 'must-hotel-booking'),
            'plugin_factory_reset_completed' => \__('Full plugin factory reset completed.', 'must-hotel-booking'),
            'provider_mapping_saved' => \__('Clock mapping saved.', 'must-hotel-booking'),
            'provider_mapping_deleted' => \__('Clock mapping deleted.', 'must-hotel-booking'),
        ];

        if ($notice === 'staff_user_created') {
            // Read credentials from the short-lived transient, then delete it immediately
            // so they are shown exactly once and never appear in the URL.
            $transientKey = 'mhb_staff_created_creds_' . \get_current_user_id();

            /** @var array<string, string>|false $creds */
            $creds = \get_transient($transientKey);
            \delete_transient($transientKey);

            if (\is_array($creds) && isset($creds['username'], $creds['password'])) {
                $roleLabels = StaffAccess::getRoleLabels();
                $roleLabel  = $roleLabels[$creds['role'] ?? ''] ?? (string) ($creds['role'] ?? '');

                echo '<div class="notice notice-success">';
                echo '<p><strong>' . \esc_html__('Staff user created successfully.', 'must-hotel-booking') . '</strong></p>';
                echo '<table style="border-collapse:collapse;margin-bottom:8px;">';
                echo '<tr><td style="padding:2px 12px 2px 0;font-weight:600;">' . \esc_html__('Username', 'must-hotel-booking') . '</td><td><code>' . \esc_html($creds['username']) . '</code></td></tr>';
                echo '<tr><td style="padding:2px 12px 2px 0;font-weight:600;">' . \esc_html__('Email', 'must-hotel-booking') . '</td><td><code>' . \esc_html($creds['email'] ?? '') . '</code></td></tr>';
                echo '<tr><td style="padding:2px 12px 2px 0;font-weight:600;">' . \esc_html__('Role', 'must-hotel-booking') . '</td><td>' . \esc_html($roleLabel) . '</td></tr>';
                echo '<tr><td style="padding:2px 12px 2px 0;font-weight:600;">' . \esc_html__('Password', 'must-hotel-booking') . '</td><td><code>' . \esc_html($creds['password']) . '</code></td></tr>';
                echo '</table>';
                echo '<p style="color:#666;font-size:12px;">' . \esc_html__('Save these credentials — the password will not be shown again here.', 'must-hotel-booking') . '</p>';
                echo '</div>';
            } else {
                // Transient expired or already consumed (e.g. page refresh after creation).
                echo '<div class="notice notice-success"><p>' . \esc_html__('Staff user created successfully. Credentials were shown once and are no longer available here.', 'must-hotel-booking') . '</p></div>';
            }

            return;
        }

        if ($notice === 'inventory_mirror_repaired') {
            $legacyTypes = isset($_GET['legacy_types']) ? \absint(\wp_unslash($_GET['legacy_types'])) : 0;
            $inserted = isset($_GET['mirrored_types_inserted']) ? \absint(\wp_unslash($_GET['mirrored_types_inserted'])) : 0;
            $updated = isset($_GET['mirrored_types_updated']) ? \absint(\wp_unslash($_GET['mirrored_types_updated'])) : 0;
            $unitsCreated = isset($_GET['inventory_units_created']) ? \absint(\wp_unslash($_GET['inventory_units_created'])) : 0;

            echo '<div class="notice notice-success"><p>' . \esc_html(\sprintf(
                /* translators: 1: legacy types scanned, 2: mirrored types inserted, 3: mirrored types updated, 4: inventory units created. */
                __('Inventory mirror repaired. Scanned %1$d legacy types, inserted %2$d mirrored types, updated %3$d mirrored types, and created %4$d default inventory units.', 'must-hotel-booking'),
                $legacyTypes,
                $inserted,
                $updated,
                $unitsCreated
            )) . '</p></div>';
            return;
        }

        if ($notice === 'provider_sync_jobs_processed') {
            $processed = isset($_GET['sync_processed']) ? \absint(\wp_unslash($_GET['sync_processed'])) : 0;
            $succeeded = isset($_GET['sync_succeeded']) ? \absint(\wp_unslash($_GET['sync_succeeded'])) : 0;
            $retryable = isset($_GET['sync_retryable']) ? \absint(\wp_unslash($_GET['sync_retryable'])) : 0;
            $failed = isset($_GET['sync_failed']) ? \absint(\wp_unslash($_GET['sync_failed'])) : 0;
            $skipped = isset($_GET['sync_skipped']) ? \absint(\wp_unslash($_GET['sync_skipped'])) : 0;
            $releasedStale = isset($_GET['sync_released_stale']) ? \absint(\wp_unslash($_GET['sync_released_stale'])) : 0;

            echo '<div class="notice notice-success"><p>' . \esc_html(\sprintf(
                /* translators: 1: processed jobs, 2: succeeded jobs, 3: retryable jobs, 4: failed jobs, 5: skipped jobs, 6: stale locks released. */
                __('Provider sync run completed. Processed %1$d jobs: %2$d succeeded, %3$d scheduled for retry, %4$d failed, %5$d skipped, %6$d stale locks released.', 'must-hotel-booking'),
                $processed,
                $succeeded,
                $retryable,
                $failed,
                $skipped,
                $releasedStale
            )) . '</p></div>';
            return;
        }

        if ($notice === 'clock_reservation_refresh_queued') {
            $jobId = isset($_GET['clock_refresh_job_id']) ? \absint(\wp_unslash($_GET['clock_refresh_job_id'])) : 0;
            $reservationId = isset($_GET['clock_reservation_id']) ? \absint(\wp_unslash($_GET['clock_reservation_id'])) : 0;

            echo '<div class="notice notice-success"><p>' . \esc_html(\sprintf(
                /* translators: 1: local reservation ID, 2: provider sync job ID. */
                __('Clock refresh queued for local reservation #%1$d as sync job #%2$d.', 'must-hotel-booking'),
                $reservationId,
                $jobId
            )) . '</p></div>';
            return;
        }

        if ($notice === 'clock_connection_test_succeeded') {
            $status = isset($_GET['clock_http_status']) ? \absint(\wp_unslash($_GET['clock_http_status'])) : 0;
            $duration = isset($_GET['clock_duration_ms']) ? \absint(\wp_unslash($_GET['clock_duration_ms'])) : 0;

            echo '<div class="notice notice-success"><p>' . \esc_html(\sprintf(
                /* translators: 1: HTTP status code, 2: request duration in milliseconds. */
                __('Clock PMS API connection succeeded. GET /room_types returned HTTP %1$d in %2$d ms.', 'must-hotel-booking'),
                $status,
                $duration
            )) . '</p></div>';
            return;
        }

        if (!isset($messages[$notice])) {
            return;
        }

        echo '<div class="notice notice-success"><p>' . \esc_html($messages[$notice]) . '</p></div>';
    }

    /**
     * @param array<int, string> $errors
     */
    private static function renderErrorNotice(array $errors): void
    {
        if (empty($errors)) {
            return;
        }

        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    /**
     * @param array<string, mixed> $diagnostics
     */
    private static function renderOverviewCards(array $diagnostics): void
    {
        $pageRows = isset($diagnostics['pages']) && \is_array($diagnostics['pages']) ? $diagnostics['pages'] : [];
        $healthyPages = 0;
        $paymentMethodNames = [];

        foreach ($pageRows as $pageRow) {
            if (\is_array($pageRow) && (string) ($pageRow['health'] ?? '') === 'ok') {
                $healthyPages++;
            }
        }

        $paymentMethods = PaymentMethodRegistry::getEnabled();
        $portalRoles = StaffAccess::getPortalAccessRoles();

        foreach ($paymentMethods as $method) {
            $paymentMethodNames[] = (string) (PaymentMethodRegistry::getCatalog()[$method]['label'] ?? $method);
        }

        $cards = [
            [
                'tab' => 'general',
                'icon' => 'dashicons-admin-home',
                'label' => \__('Hotel identity', 'must-hotel-booking'),
                'value' => MustBookingConfig::get_hotel_name() !== '' ? MustBookingConfig::get_hotel_name() : __('Missing', 'must-hotel-booking'),
                'meta' => MustBookingConfig::get_timezone() . ' / ' . MustBookingConfig::get_currency(),
            ],
            [
                'tab' => 'booking_rules',
                'icon' => 'dashicons-clipboard',
                'label' => \__('Booking rules', 'must-hotel-booking'),
                'value' => \sprintf(
                    /* translators: 1: min nights, 2: max nights. */
                    \__('%1$d-%2$d nights', 'must-hotel-booking'),
                    (int) MustBookingConfig::get_setting('minimum_nights', 1),
                    (int) MustBookingConfig::get_setting('maximum_nights', 30)
                ),
                'meta' => \sprintf(\__('Window: %d days', 'must-hotel-booking'), MustBookingConfig::get_booking_window()),
            ],
            [
                'tab' => 'payments_summary',
                'icon' => 'dashicons-money-alt',
                'label' => \__('Payments', 'must-hotel-booking'),
                'value' => !empty($paymentMethodNames) ? \implode(', ', $paymentMethodNames) : \__('Disabled', 'must-hotel-booking'),
                'meta' => PaymentEngine::isStripeCheckoutConfigured() ? \__('Stripe ready', 'must-hotel-booking') : \__('Review configuration', 'must-hotel-booking'),
            ],
            [
                'tab' => 'staff_access',
                'icon' => 'dashicons-groups',
                'label' => \__('Staff portal / access', 'must-hotel-booking'),
                'value' => !empty(MustBookingConfig::get_setting('enable_staff_portal', false)) ? \__('Portal enabled', 'must-hotel-booking') : \__('Portal disabled', 'must-hotel-booking'),
                'meta' => \sprintf(\_n('%d portal role', '%d portal roles', \count($portalRoles), 'must-hotel-booking'), \count($portalRoles)),
            ],
            [
                'tab' => 'managed_pages',
                'icon' => 'dashicons-admin-page',
                'label' => \__('Managed pages', 'must-hotel-booking'),
                'value' => \sprintf('%d/%d', $healthyPages, \count($pageRows)),
                'meta' => \__('Pages healthy right now', 'must-hotel-booking'),
            ],
            [
                'tab' => 'maintenance',
                'icon' => 'dashicons-admin-tools',
                'label' => \__('Diagnostics health', 'must-hotel-booking'),
                'value' => \sprintf(
                    /* translators: 1: critical issues, 2: warnings. */
                    \__('%1$d critical / %2$d warnings', 'must-hotel-booking'),
                    (int) ($diagnostics['critical_issues'] ?? 0),
                    (int) ($diagnostics['warnings'] ?? 0)
                ),
                'meta' => \__('Open maintenance tab', 'must-hotel-booking'),
            ],
        ];

        echo '<div class="must-dashboard-kpis must-settings-overview">';

        foreach ($cards as $card) {
            echo '<a class="must-dashboard-kpi-card must-settings-overview-card" href="' . \esc_url(get_admin_settings_page_url(['tab' => (string) $card['tab']])) . '">';
            echo '<span class="dashicons ' . \esc_attr((string) $card['icon']) . ' must-dashboard-kpi-icon"></span>';
            echo '<span class="must-dashboard-kpi-label">' . \esc_html((string) $card['label']) . '</span>';
            echo '<strong class="must-dashboard-kpi-value">' . \esc_html((string) $card['value']) . '</strong>';
            echo '<span class="must-dashboard-kpi-descriptor">' . \esc_html((string) $card['meta']) . '</span>';
            echo '<span class="must-dashboard-kpi-cta">' . \esc_html__('Open', 'must-hotel-booking') . '</span>';
            echo '</a>';
        }

        echo '</div>';
    }

    private static function renderTabNavigation(string $activeTab): void
    {
        echo '<nav class="must-settings-tabs" aria-label="' . \esc_attr__('Settings sections', 'must-hotel-booking') . '">';

        foreach (self::getTabs() as $tabKey => $tabMeta) {
            $classes = 'must-settings-tab';

            if ($tabKey === $activeTab) {
                $classes .= ' is-active';
            }

            echo '<a class="' . \esc_attr($classes) . '" href="' . \esc_url(get_admin_settings_page_url(['tab' => $tabKey])) . '">';
            echo '<span class="dashicons ' . \esc_attr((string) ($tabMeta['icon'] ?? 'dashicons-admin-generic')) . '"></span>';
            echo '<span>' . \esc_html((string) ($tabMeta['label'] ?? $tabKey)) . '</span>';
            echo '</a>';
        }

        echo '</nav>';
    }

    /**
     * @param array<string, array<string, mixed>> $forms
     * @param array<string, mixed> $diagnostics
     */
    private static function renderActiveTab(string $activeTab, array $forms, array $diagnostics): void
    {
        if ($activeTab === 'general') {
            self::renderGeneralTab($forms['general']);
            return;
        }

        if ($activeTab === 'booking_rules') {
            self::renderBookingRulesTab($forms['booking_rules']);
            return;
        }

        if ($activeTab === 'checkin_checkout') {
            self::renderCheckinCheckoutTab($forms['checkin_checkout']);
            return;
        }

        if ($activeTab === 'payments_summary') {
            self::renderPaymentsSummaryTab($forms['payments_summary'], $forms['booking_rules'], $diagnostics);
            return;
        }

        if ($activeTab === 'staff_access') {
            self::renderStaffAccessTab($forms['staff_access']);
            return;
        }

        if ($activeTab === 'staff_users') {
            self::renderStaffUsersTab();
            return;
        }

        if ($activeTab === 'branding') {
            self::renderBrandingTab($forms['branding']);
            return;
        }

        if ($activeTab === 'managed_pages') {
            self::renderManagedPagesTab($forms['managed_pages'], $diagnostics);
            return;
        }

        if ($activeTab === 'notifications_summary') {
            self::renderNotificationsTab($forms['notifications_summary'], $diagnostics);
            return;
        }

        if ($activeTab === 'provider') {
            self::renderProviderTab($forms['provider'], $diagnostics);
            return;
        }

        self::renderMaintenanceTab($diagnostics, $forms['maintenance']);
    }

    private static function renderFormStart(string $tab): void
    {
        echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => $tab])) . '" class="must-settings-form">';
        \wp_nonce_field('must_settings_' . $tab, 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="save_' . \esc_attr($tab) . '" />';
    }

    private static function renderFormEnd(string $buttonLabel = ''): void
    {
        echo '<div class="must-settings-savebar">';
        echo '<div class="must-settings-savecopy">';
        echo '<strong>' . \esc_html__('Ready to save', 'must-hotel-booking') . '</strong>';
        echo '<span>' . \esc_html__('Changes are stored in the main plugin settings bundle with validation and defaults applied.', 'must-hotel-booking') . '</span>';
        echo '</div>';
        echo '<button type="submit" class="button button-primary">' . \esc_html($buttonLabel !== '' ? $buttonLabel : __('Save Settings', 'must-hotel-booking')) . '</button>';
        echo '</div>';
        echo '</form>';
    }

    private static function renderPanelStart(string $title, string $description = '', string $actions = ''): void
    {
        echo '<section class="postbox must-dashboard-panel must-settings-panel"><div class="must-dashboard-panel-inner">';
        echo '<div class="must-dashboard-panel-heading">';
        echo '<div><h2>' . \esc_html($title) . '</h2>';

        if ($description !== '') {
            echo '<p>' . \esc_html($description) . '</p>';
        }

        echo '</div>';

        if ($actions !== '') {
            echo '<div class="must-settings-panel-actions">' . $actions . '</div>';
        }

        echo '</div>';
    }

    private static function renderPanelEnd(): void
    {
        echo '</div></section>';
    }

    private static function renderSectionIntro(string $title, string $description = ''): void
    {
        echo '<div class="must-settings-section-intro"><h3>' . \esc_html($title) . '</h3>';

        if ($description !== '') {
            echo '<p>' . \esc_html($description) . '</p>';
        }

        echo '</div>';
    }

    private static function renderBadge(string $status, string $label = ''): void
    {
        $map = [
            'ok' => \__('OK', 'must-hotel-booking'),
            'healthy' => \__('OK', 'must-hotel-booking'),
            'warning' => \__('Warning', 'must-hotel-booking'),
            'missing' => \__('Missing', 'must-hotel-booking'),
            'error' => \__('Missing', 'must-hotel-booking'),
            'disabled' => \__('Disabled', 'must-hotel-booking'),
            'active' => \__('Active', 'must-hotel-booking'),
            'info' => \__('Info', 'must-hotel-booking'),
            'published' => \__('Published', 'must-hotel-booking'),
            'draft' => \__('Draft', 'must-hotel-booking'),
            'trash' => \__('Trash', 'must-hotel-booking'),
        ];
        $class = 'is-info';

        if (\in_array($status, ['ok', 'healthy', 'active', 'published'], true)) {
            $class = 'is-ok';
        } elseif (\in_array($status, ['warning', 'draft'], true)) {
            $class = 'is-warning';
        } elseif (\in_array($status, ['missing', 'error', 'trash'], true)) {
            $class = 'is-error';
        }

        echo '<span class="must-dashboard-status-badge ' . \esc_attr($class) . '">';
        echo \esc_html($label !== '' ? $label : (string) ($map[$status] ?? $status));
        echo '</span>';
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function renderField(array $args): void
    {
        $type = (string) ($args['type'] ?? 'text');
        $name = (string) ($args['name'] ?? '');
        $value = $args['value'] ?? '';
        $label = (string) ($args['label'] ?? '');
        $description = (string) ($args['description'] ?? '');
        $options = isset($args['options']) && \is_array($args['options']) ? $args['options'] : [];

        if ($type === 'checkbox') {
            echo '<label class="must-settings-toggle">';
            echo '<input type="checkbox" name="' . \esc_attr($name) . '" value="1"' . \checked(!empty($value), true, false) . ' />';
            echo '<span><strong>' . \esc_html($label) . '</strong>';

            if ($description !== '') {
                echo '<small>' . \esc_html($description) . '</small>';
            }

            echo '</span></label>';
            return;
        }

        echo '<div class="must-settings-field">';
        echo '<label for="' . \esc_attr($name) . '">' . \esc_html($label) . '</label>';

        if ($type === 'textarea') {
            echo '<textarea id="' . \esc_attr($name) . '" name="' . \esc_attr($name) . '" rows="' . \esc_attr((string) ($args['rows'] ?? 4)) . '">' . \esc_textarea((string) $value) . '</textarea>';
        } elseif ($type === 'select') {
            echo '<select id="' . \esc_attr($name) . '" name="' . \esc_attr($name) . '">';

            foreach ($options as $optionValue => $optionLabel) {
                echo '<option value="' . \esc_attr((string) $optionValue) . '"' . \selected((string) $value, (string) $optionValue, false) . '>' . \esc_html((string) $optionLabel) . '</option>';
            }

            echo '</select>';
        } elseif ($type === 'color') {
            echo '<input id="' . \esc_attr($name) . '" type="color" name="' . \esc_attr($name) . '" value="' . \esc_attr((string) $value) . '" />';
        } elseif ($type === 'media') {
            echo '<div class="must-settings-media">';
            echo '<input id="' . \esc_attr($name) . '" type="text" name="' . \esc_attr($name) . '" value="' . \esc_attr((string) $value) . '" />';
            echo '<button type="button" class="button" data-must-media-target="' . \esc_attr($name) . '">' . \esc_html__('Select', 'must-hotel-booking') . '</button>';
            echo '<button type="button" class="button button-link-delete" data-must-clear-target="' . \esc_attr($name) . '">' . \esc_html__('Clear', 'must-hotel-booking') . '</button>';
            echo '</div>';
        } else {
            echo '<input id="' . \esc_attr($name) . '" type="' . \esc_attr($type) . '" name="' . \esc_attr($name) . '" value="' . \esc_attr((string) $value) . '"';

            if (isset($args['min'])) {
                echo ' min="' . \esc_attr((string) $args['min']) . '"';
            }

            if (isset($args['max'])) {
                echo ' max="' . \esc_attr((string) $args['max']) . '"';
            }

            if (isset($args['step'])) {
                echo ' step="' . \esc_attr((string) $args['step']) . '"';
            }

            echo ' />';
        }

        if ($description !== '') {
            echo '<p class="description">' . \esc_html($description) . '</p>';
        }

        echo '</div>';
    }

    /**
     * @param array<int, string> $warnings
     */
    private static function renderWarnings(array $warnings): void
    {
        if (empty($warnings)) {
            return;
        }

        echo '<div class="must-settings-warning-list">';

        foreach ($warnings as $warning) {
            echo '<div class="must-settings-warning-item">';
            self::renderBadge('warning');
            echo '<span>' . \esc_html((string) $warning) . '</span>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function renderGeneralTab(array $form): void
    {
        self::renderFormStart('general');
        self::renderPanelStart(
            \__('General', 'must-hotel-booking'),
            \__('Define the hotel profile, localization defaults, and core brand assets used across the plugin.', 'must-hotel-booking')
        );
        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Hotel identity', 'must-hotel-booking'), \__('Primary hotel contact details and business identity used throughout admin summaries and guest communications.', 'must-hotel-booking'));
        self::renderField(['label' => __('Hotel name', 'must-hotel-booking'), 'name' => 'hotel_name', 'value' => $form['hotel_name'] ?? '']);
        self::renderField(['label' => __('Legal / business name', 'must-hotel-booking'), 'name' => 'hotel_legal_name', 'value' => $form['hotel_legal_name'] ?? '']);
        self::renderField(['label' => __('Hotel phone', 'must-hotel-booking'), 'name' => 'hotel_phone', 'value' => $form['hotel_phone'] ?? '']);
        self::renderField(['label' => __('Hotel email', 'must-hotel-booking'), 'name' => 'hotel_email', 'type' => 'email', 'value' => $form['hotel_email'] ?? '']);
        self::renderField(['label' => __('Booking notification email', 'must-hotel-booking'), 'name' => 'booking_notification_email', 'type' => 'email', 'value' => $form['booking_notification_email'] ?? '', 'description' => __('Primary recipient for booking alerts and operational notifications.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Address', 'must-hotel-booking'), 'name' => 'hotel_address', 'type' => 'textarea', 'rows' => 4, 'value' => $form['hotel_address'] ?? '']);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Localization', 'must-hotel-booking'), \__('Operational defaults for dates, times, currency, and geography.', 'must-hotel-booking'));
        self::renderField(['label' => __('Default country', 'must-hotel-booking'), 'name' => 'default_country', 'type' => 'select', 'value' => $form['default_country'] ?? 'US', 'options' => self::getCountryOptions()]);
        self::renderField(['label' => __('Currency', 'must-hotel-booking'), 'name' => 'currency', 'type' => 'select', 'value' => $form['currency'] ?? 'USD', 'options' => self::getCurrencyOptions()]);
        self::renderField(['label' => __('Currency display', 'must-hotel-booking'), 'name' => 'currency_display', 'type' => 'select', 'value' => $form['currency_display'] ?? 'symbol_code', 'options' => ['symbol' => __('Symbol only', 'must-hotel-booking'), 'symbol_code' => __('Symbol + code', 'must-hotel-booking'), 'code' => __('Code only', 'must-hotel-booking')]]);

        echo '<div class="must-settings-field"><label for="timezone">' . \esc_html__('Timezone', 'must-hotel-booking') . '</label>';
        echo '<select id="timezone" name="timezone">';
        echo \function_exists('wp_timezone_choice')
            ? \wp_timezone_choice((string) ($form['timezone'] ?? 'UTC'))
            : '<option value="UTC">UTC</option>';
        echo '</select></div>';

        self::renderField(['label' => __('Date format', 'must-hotel-booking'), 'name' => 'date_format', 'value' => $form['date_format'] ?? 'F j, Y']);
        self::renderField(['label' => __('Time format', 'must-hotel-booking'), 'name' => 'time_format', 'value' => $form['time_format'] ?? 'H:i']);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Brand basics', 'must-hotel-booking'), \__('Reusable logo assets and the site environment summary that help staff understand where this installation is running.', 'must-hotel-booking'));
        self::renderField(['label' => __('Hotel logo', 'must-hotel-booking'), 'name' => 'hotel_logo_url', 'type' => 'media', 'value' => $form['hotel_logo_url'] ?? '']);
        self::renderField(['label' => __('Portal logo', 'must-hotel-booking'), 'name' => 'portal_logo_url', 'type' => 'media', 'value' => $form['portal_logo_url'] ?? '']);
        self::renderField(['label' => __('Site environment', 'must-hotel-booking'), 'name' => 'site_environment', 'type' => 'select', 'value' => $form['site_environment'] ?? PaymentEngine::getActiveSiteEnvironment(), 'options' => \array_map(static function (array $meta): string { return (string) ($meta['label'] ?? ''); }, PaymentEngine::getStripeEnvironmentCatalog())]);
        echo '</div>';
        self::renderPanelEnd();
        self::renderFormEnd(\__('Save General Settings', 'must-hotel-booking'));
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function renderBookingRulesTab(array $form): void
    {
        $warnings = [];

        if (empty($form['allow_multi_room_booking']) && (int) ($form['max_booking_rooms'] ?? 1) > 1) {
            $warnings[] = \__('Multi-room booking is disabled while maximum rooms per reservation is greater than 1.', 'must-hotel-booking');
        }

        if (empty($form['cancellation_allowed']) && (int) ($form['cancellation_notice_hours'] ?? 0) > 0) {
            $warnings[] = \__('Cancellation notice hours are set even though cancellation is disabled.', 'must-hotel-booking');
        }

        self::renderFormStart('booking_rules');
        self::renderWarnings($warnings);
        self::renderPanelStart(\__('Booking Rules', 'must-hotel-booking'), \__('Control reservation behavior, guest form requirements, and the operational defaults applied to new bookings.', 'must-hotel-booking'));

        echo '<div class="must-settings-grid must-settings-grid--3">';
        self::renderSectionIntro(\__('Reservation behavior', 'must-hotel-booking'));
        self::renderField(['label' => __('Booking window (days)', 'must-hotel-booking'), 'name' => 'booking_window', 'type' => 'number', 'min' => 1, 'max' => 3650, 'value' => $form['booking_window'] ?? 365]);
        self::renderField(['label' => __('Same-day cutoff', 'must-hotel-booking'), 'name' => 'same_day_booking_cutoff_time', 'type' => 'time', 'value' => $form['same_day_booking_cutoff_time'] ?? '18:00']);
        self::renderField(['label' => __('Minimum nights', 'must-hotel-booking'), 'name' => 'minimum_nights', 'type' => 'number', 'min' => 1, 'max' => 365, 'value' => $form['minimum_nights'] ?? 1]);
        self::renderField(['label' => __('Maximum nights', 'must-hotel-booking'), 'name' => 'maximum_nights', 'type' => 'number', 'min' => 1, 'max' => 365, 'value' => $form['maximum_nights'] ?? 30]);
        self::renderField(['label' => __('Max guests / reservation', 'must-hotel-booking'), 'name' => 'max_booking_guests', 'type' => 'number', 'min' => 1, 'max' => 100, 'value' => $form['max_booking_guests'] ?? 12]);
        self::renderField(['label' => __('Max rooms / reservation', 'must-hotel-booking'), 'name' => 'max_booking_rooms', 'type' => 'number', 'min' => 1, 'max' => 25, 'value' => $form['max_booking_rooms'] ?? 3]);
        self::renderField(['label' => __('Default reservation source', 'must-hotel-booking'), 'name' => 'default_reservation_source', 'type' => 'select', 'value' => $form['default_reservation_source'] ?? 'website', 'options' => ['website' => __('Website', 'must-hotel-booking'), 'phone' => __('Phone', 'must-hotel-booking'), 'walk_in' => __('Walk-in', 'must-hotel-booking'), 'email' => __('Email', 'must-hotel-booking'), 'portal' => __('Portal', 'must-hotel-booking'), 'admin' => __('Admin', 'must-hotel-booking')]]);
        self::renderField(['label' => __('Pending expiration (minutes)', 'must-hotel-booking'), 'name' => 'pending_reservation_expiration_minutes', 'type' => 'number', 'min' => 5, 'max' => 1440, 'value' => $form['pending_reservation_expiration_minutes'] ?? 35]);
        self::renderField(['label' => __('Same-day booking', 'must-hotel-booking'), 'name' => 'same_day_booking_allowed', 'type' => 'checkbox', 'value' => $form['same_day_booking_allowed'] ?? false, 'description' => __('Allow reservations that begin today.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Allow multi-room booking', 'must-hotel-booking'), 'name' => 'allow_multi_room_booking', 'type' => 'checkbox', 'value' => $form['allow_multi_room_booking'] ?? false]);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Guest form rules', 'must-hotel-booking'));
        self::renderField(['label' => __('Require phone', 'must-hotel-booking'), 'name' => 'require_phone', 'type' => 'checkbox', 'value' => $form['require_phone'] ?? true]);
        self::renderField(['label' => __('Require country', 'must-hotel-booking'), 'name' => 'require_country', 'type' => 'checkbox', 'value' => $form['require_country'] ?? true]);
        self::renderField(['label' => __('Enable special requests', 'must-hotel-booking'), 'name' => 'enable_special_requests', 'type' => 'checkbox', 'value' => $form['enable_special_requests'] ?? true]);
        self::renderField(['label' => __('Require terms acceptance', 'must-hotel-booking'), 'name' => 'require_terms_acceptance', 'type' => 'checkbox', 'value' => $form['require_terms_acceptance'] ?? true]);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Operational defaults', 'must-hotel-booking'));
        self::renderField(['label' => __('Default reservation status', 'must-hotel-booking'), 'name' => 'default_reservation_status', 'type' => 'select', 'value' => $form['default_reservation_status'] ?? 'confirmed', 'options' => ['pending' => __('Pending', 'must-hotel-booking'), 'pending_payment' => __('Pending payment', 'must-hotel-booking'), 'confirmed' => __('Confirmed', 'must-hotel-booking'), 'completed' => __('Completed', 'must-hotel-booking'), 'cancelled' => __('Cancelled', 'must-hotel-booking')]]);
        self::renderField(['label' => __('Default payment mode', 'must-hotel-booking'), 'name' => 'default_payment_mode', 'type' => 'select', 'value' => $form['default_payment_mode'] ?? 'guest_choice', 'options' => ['guest_choice' => __('Guest chooses at checkout', 'must-hotel-booking'), 'pay_now' => __('Pay now', 'must-hotel-booking'), 'pay_at_hotel' => __('Pay at hotel', 'must-hotel-booking')]]);
        self::renderField(['label' => __('Allow cancellation', 'must-hotel-booking'), 'name' => 'cancellation_allowed', 'type' => 'checkbox', 'value' => $form['cancellation_allowed'] ?? true]);
        self::renderField(['label' => __('Cancellation notice (hours)', 'must-hotel-booking'), 'name' => 'cancellation_notice_hours', 'type' => 'number', 'min' => 0, 'max' => 720, 'value' => $form['cancellation_notice_hours'] ?? 48]);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--3">';
        self::renderSectionIntro(\__('Anti-Spam Protection', 'must-hotel-booking'), \__('Optional server-side protections for public reservation submits. These checks do not add visible frontend widgets or change the booking layout.', 'must-hotel-booking'));
        self::renderField(['label' => __('Enable anti-spam protections', 'must-hotel-booking'), 'name' => 'anti_abuse_enabled', 'type' => 'checkbox', 'value' => $form['anti_abuse_enabled'] ?? false, 'description' => __('Master switch for all plugin-side abuse protection checks.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Enable hidden honeypot', 'must-hotel-booking'), 'name' => 'anti_abuse_honeypot_enabled', 'type' => 'checkbox', 'value' => $form['anti_abuse_honeypot_enabled'] ?? false]);
        self::renderField(['label' => __('Enable minimum submit time', 'must-hotel-booking'), 'name' => 'anti_abuse_min_submit_enabled', 'type' => 'checkbox', 'value' => $form['anti_abuse_min_submit_enabled'] ?? false]);
        self::renderField(['label' => __('Minimum submit seconds', 'must-hotel-booking'), 'name' => 'anti_abuse_min_submit_seconds', 'type' => 'number', 'min' => 1, 'max' => 60, 'value' => $form['anti_abuse_min_submit_seconds'] ?? 5]);
        self::renderField(['label' => __('Enable throttling', 'must-hotel-booking'), 'name' => 'anti_abuse_throttle_enabled', 'type' => 'checkbox', 'value' => $form['anti_abuse_throttle_enabled'] ?? false]);
        self::renderField(['label' => __('Max attempts', 'must-hotel-booking'), 'name' => 'anti_abuse_max_attempts', 'type' => 'number', 'min' => 1, 'max' => 50, 'value' => $form['anti_abuse_max_attempts'] ?? 5]);
        self::renderField(['label' => __('Window length (minutes)', 'must-hotel-booking'), 'name' => 'anti_abuse_window_minutes', 'type' => 'number', 'min' => 1, 'max' => 1440, 'value' => $form['anti_abuse_window_minutes'] ?? 10]);
        self::renderField(['label' => __('Temporary block (minutes)', 'must-hotel-booking'), 'name' => 'anti_abuse_block_duration_minutes', 'type' => 'number', 'min' => 1, 'max' => 1440, 'value' => $form['anti_abuse_block_duration_minutes'] ?? 30]);
        self::renderField(['label' => __('Enable abuse logging', 'must-hotel-booking'), 'name' => 'anti_abuse_logging_enabled', 'type' => 'checkbox', 'value' => $form['anti_abuse_logging_enabled'] ?? false, 'description' => __('Writes blocked attempts into the activity log for diagnostics.', 'must-hotel-booking')]);
        echo '</div>';

        self::renderPanelEnd();
        self::renderFormEnd(\__('Save Booking Rules', 'must-hotel-booking'));
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function renderCheckinCheckoutTab(array $form): void
    {
        self::renderFormStart('checkin_checkout');
        self::renderPanelStart(\__('Check-in / Check-out', 'must-hotel-booking'), \__('Operational timing defaults, guest-facing timing labels, and stay instructions.', 'must-hotel-booking'));
        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderField(['label' => __('Default check-in time', 'must-hotel-booking'), 'name' => 'checkin_time', 'type' => 'time', 'value' => $form['checkin_time'] ?? '14:00']);
        self::renderField(['label' => __('Default check-out time', 'must-hotel-booking'), 'name' => 'checkout_time', 'type' => 'time', 'value' => $form['checkout_time'] ?? '11:00']);
        self::renderField(['label' => __('Allow early check-in requests', 'must-hotel-booking'), 'name' => 'allow_early_checkin_request', 'type' => 'checkbox', 'value' => $form['allow_early_checkin_request'] ?? true]);
        self::renderField(['label' => __('Allow late check-out requests', 'must-hotel-booking'), 'name' => 'allow_late_checkout_request', 'type' => 'checkbox', 'value' => $form['allow_late_checkout_request'] ?? true]);
        self::renderField(['label' => __('Guest check-in label', 'must-hotel-booking'), 'name' => 'guest_checkin_label', 'value' => $form['guest_checkin_label'] ?? __('Check-in', 'must-hotel-booking')]);
        self::renderField(['label' => __('Guest check-out label', 'must-hotel-booking'), 'name' => 'guest_checkout_label', 'value' => $form['guest_checkout_label'] ?? __('Check-out', 'must-hotel-booking')]);
        self::renderField(['label' => __('Arrival instructions', 'must-hotel-booking'), 'name' => 'arrival_instructions', 'type' => 'textarea', 'rows' => 5, 'value' => $form['arrival_instructions'] ?? '']);
        self::renderField(['label' => __('Departure instructions', 'must-hotel-booking'), 'name' => 'departure_instructions', 'type' => 'textarea', 'rows' => 5, 'value' => $form['departure_instructions'] ?? '']);
        echo '</div>';
        self::renderPanelEnd();
        self::renderFormEnd(\__('Save Stay Timing Settings', 'must-hotel-booking'));
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $bookingRules
     * @param array<string, mixed> $diagnostics
     */
    private static function renderPaymentsSummaryTab(array $form, array $bookingRules, array $diagnostics): void
    {
        $enabledMethods = PaymentMethodRegistry::getEnabled();
        $paymentLabels = PaymentMethodRegistry::getCatalog();
        $methodNames = [];

        foreach ($enabledMethods as $method) {
            $methodNames[] = (string) ($paymentLabels[$method]['label'] ?? $method);
        }

        $warnings = [];

        if (\in_array('stripe', $enabledMethods, true) && !PaymentEngine::isStripeCheckoutConfigured()) {
            $warnings[] = \__('Stripe is enabled but the active publishable or secret key is missing.', 'must-hotel-booking');
        }

        if (\in_array('stripe', $enabledMethods, true) && PaymentEngine::getStripeWebhookSecret() === '') {
            $warnings[] = \__('Stripe webhook signing secret is missing for the active environment.', 'must-hotel-booking');
        }

        if (\in_array('pokpay', $enabledMethods, true) && !PaymentEngine::isPokPayConfigured()) {
            $warnings[] = \__('PokPay is enabled but the active merchant ID, key ID, or key secret is missing.', 'must-hotel-booking');
        }

        self::renderFormStart('payments_summary');
        self::renderWarnings($warnings);
        self::renderPanelStart(\__('Payments Summary', 'must-hotel-booking'), \__('A control surface for high-level payment defaults with shortcuts into the dedicated Payments page.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Enabled methods', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($methodNames) ? \implode(', ', $methodNames) : __('No methods enabled', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Default payment mode', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($bookingRules['default_payment_mode'] ?? 'guest_choice')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Stripe environment', 'must-hotel-booking') . '</span><strong>' . \esc_html(PaymentEngine::getSiteEnvironmentLabel()) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('PokPay API', 'must-hotel-booking') . '</span><strong>' . \esc_html(\ucfirst(PaymentEngine::getPokPayApiEnvironment())) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Webhook URL', 'must-hotel-booking') . '</span><code>' . \esc_html(PaymentEngine::getStripeWebhookUrl()) . '</code></article>';
        echo '</div>';
        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderField(['label' => __('Deposit required', 'must-hotel-booking'), 'name' => 'deposit_required', 'type' => 'checkbox', 'value' => $form['deposit_required'] ?? false]);
        self::renderField(['label' => __('Deposit type', 'must-hotel-booking'), 'name' => 'deposit_type', 'type' => 'select', 'value' => $form['deposit_type'] ?? 'percentage', 'options' => ['percentage' => __('Percentage', 'must-hotel-booking'), 'fixed' => __('Fixed amount', 'must-hotel-booking')]]);
        self::renderField(['label' => __('Deposit value', 'must-hotel-booking'), 'name' => 'deposit_value', 'type' => 'number', 'min' => 0, 'step' => '0.01', 'value' => $form['deposit_value'] ?? 0]);
        self::renderField(['label' => __('Tax rate (%)', 'must-hotel-booking'), 'name' => 'tax_rate', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => '0.01', 'value' => $form['tax_rate'] ?? 0]);
        echo '</div>';
        echo '<div class="must-dashboard-action-strip"><span class="must-dashboard-action-strip-label">' . \esc_html__('Quick actions', 'must-hotel-booking') . '</span>';
        if (\function_exists(__NAMESPACE__ . '\get_admin_payments_page_url')) {
            echo '<a class="must-dashboard-action-chip" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments Page', 'must-hotel-booking') . '</a>';
        }
        echo '<button type="button" class="must-dashboard-action-chip" data-must-copy="payment-webhook-url">' . \esc_html__('Copy Webhook URL', 'must-hotel-booking') . '</button>';
        echo '<code id="payment-webhook-url" class="must-settings-copy-source">' . \esc_html(PaymentEngine::getStripeWebhookUrl()) . '</code>';
        echo '</div>';
        self::renderPanelEnd();
        self::renderFormEnd(\__('Save Payment Summary', 'must-hotel-booking'));
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function renderStaffAccessTab(array $form): void
    {
        $pageOptions = self::getPageSelectOptions();
        $redirectOptions = [];

        foreach (PortalRegistry::getDefinitions() as $moduleKey => $definition) {
            $redirectOptions[$moduleKey] = (string) ($definition['label'] ?? $moduleKey);
        }

        self::renderFormStart('staff_access');
        self::renderPanelStart(\__('Staff & Access', 'must-hotel-booking'), \__('Configure the live staff portal, decide which operational modules are visible globally, and sync the worker and manager capability model that powers access control.', 'must-hotel-booking'));
        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderField(['label' => __('Enable staff portal', 'must-hotel-booking'), 'name' => 'enable_staff_portal', 'type' => 'checkbox', 'value' => $form['enable_staff_portal'] ?? false]);
        self::renderField(['label' => __('Hide wp-admin for worker roles', 'must-hotel-booking'), 'name' => 'hide_wp_admin_for_workers', 'type' => 'checkbox', 'value' => $form['hide_wp_admin_for_workers'] ?? true]);
        self::renderField(['label' => __('Portal page assignment', 'must-hotel-booking'), 'name' => 'portal_page_id', 'type' => 'select', 'value' => $form['portal_page_id'] ?? 0, 'options' => $pageOptions]);
        self::renderField(['label' => __('Portal login page assignment', 'must-hotel-booking'), 'name' => 'portal_login_page_id', 'type' => 'select', 'value' => $form['portal_login_page_id'] ?? 0, 'options' => $pageOptions]);
        self::renderField(['label' => __('Redirect workers after login', 'must-hotel-booking'), 'name' => 'redirect_worker_after_login', 'type' => 'select', 'value' => $form['redirect_worker_after_login'] ?? 'dashboard', 'options' => $redirectOptions]);
        echo '<div class="must-settings-field"><label>' . \esc_html__('Allow portal access by role', 'must-hotel-booking') . '</label><div class="must-settings-checklist">';
        foreach (StaffAccess::getRoleLabels() as $role => $label) {
            echo '<label><input type="checkbox" name="portal_access_roles[]" value="' . \esc_attr($role) . '"' . \checked(\in_array($role, (array) ($form['portal_access_roles'] ?? []), true), true, false) . ' /> ' . \esc_html($label) . '</label>';
        }
        echo '</div></div>';
        echo '</div>';

        echo '<div class="must-settings-section-intro"><h3>' . \esc_html__('Portal module visibility', 'must-hotel-booking') . '</h3><p>' . \esc_html__('A module appears in the portal only when it is enabled here and the signed-in user has the required capability.', 'must-hotel-booking') . '</p></div>';
        echo '<div class="must-settings-module-grid">';

        foreach (PortalRegistry::getDefinitions() as $moduleKey => $definition) {
            $enabled = !empty($form['portal_module_visibility'][$moduleKey]);
            echo '<label class="must-settings-module-card">';
            echo '<input type="checkbox" name="portal_module_visibility[' . \esc_attr($moduleKey) . ']" value="1"' . \checked($enabled, true, false) . ' />';
            echo '<span class="must-settings-module-icon dashicons ' . \esc_attr((string) ($definition['icon'] ?? 'dashicons-screenoptions')) . '"></span>';
            echo '<strong>' . \esc_html((string) ($definition['label'] ?? $moduleKey)) . '</strong>';
            echo '<small>' . \esc_html($enabled ? __('Visible when capability allows', 'must-hotel-booking') : __('Hidden globally', 'must-hotel-booking')) . '</small>';
            echo '</label>';
        }

        echo '</div>';

        echo '<div class="must-settings-capability-matrix">';
        echo '<div class="must-settings-capability-head"><h3>' . \esc_html__('Capability matrix', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Worker and manager capabilities are synced into plugin-specific WordPress caps. Settings stays wp-admin only, so that column remains locked off for portal roles.', 'must-hotel-booking') . '</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('Role', 'must-hotel-booking') . '</th>';

        foreach (StaffAccess::getCapabilityLabels() as $capability => $label) {
            echo '<th>' . \esc_html($label) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach (StaffAccess::getRoleLabels() as $role => $roleLabel) {
            echo '<tr><td><strong>' . \esc_html($roleLabel) . '</strong><br /><span class="description">' . \esc_html((string) (StaffAccess::getRoleDescriptions()[$role] ?? '')) . '</span></td>';

            foreach (StaffAccess::getCapabilityLabels() as $capability => $label) {
                unset($label);
                $checked = !empty($form['capability_matrix'][$role][$capability]);
                $disabled = $capability === 'access_settings';
                echo '<td><label class="must-settings-matrix-checkbox"><input type="checkbox" name="capability_matrix[' . \esc_attr($role) . '][' . \esc_attr($capability) . ']" value="1"' . \checked($checked, true, false) . ($disabled ? ' disabled="disabled"' : '') . ' /><span></span></label></td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table></div>';
        self::renderPanelEnd();
        self::renderFormEnd(\__('Save Staff Access Settings', 'must-hotel-booking'));
    }

    private static function renderStaffUsersTab(): void
    {
        $actionUrl = get_admin_settings_page_url(['tab' => 'staff_users']);
        $roleLabels = StaffAccess::getRoleLabels();

        // ---- Create staff user panel ----------------------------------------
        self::renderPanelStart(
            \__('Create Staff User', 'must-hotel-booking'),
            \__('Create a WordPress user and assign a plugin staff role. This is for plugin portal access only — it does not affect general WordPress user management.', 'must-hotel-booking')
        );

        echo '<form method="post" action="' . \esc_url($actionUrl) . '" class="must-settings-form">';
        \wp_nonce_field('must_settings_staff_users_action', 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="staff_user_action" />';
        echo '<input type="hidden" name="staff_user_sub_action" value="create" />';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="new_staff_username">' . \esc_html__('Username', 'must-hotel-booking') . '</label></th>';
        echo '<td><input type="text" id="new_staff_username" name="new_staff_username" class="regular-text" required autocomplete="off" /></td></tr>';

        echo '<tr><th scope="row"><label for="new_staff_email">' . \esc_html__('Email', 'must-hotel-booking') . '</label></th>';
        echo '<td><input type="email" id="new_staff_email" name="new_staff_email" class="regular-text" required autocomplete="off" /></td></tr>';

        echo '<tr><th scope="row"><label for="new_staff_password">' . \esc_html__('Password', 'must-hotel-booking') . '</label></th>';
        echo '<td><input type="text" id="new_staff_password" name="new_staff_password" class="regular-text" required autocomplete="off" placeholder="' . \esc_attr__('Min. 8 characters', 'must-hotel-booking') . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="new_staff_role">' . \esc_html__('Role', 'must-hotel-booking') . '</label></th>';
        echo '<td><select id="new_staff_role" name="new_staff_role">';

        foreach ($roleLabels as $slug => $label) {
            echo '<option value="' . \esc_attr($slug) . '">' . \esc_html($label) . '</option>';
        }

        echo '</select></td></tr>';
        echo '</table>';

        echo '<p class="submit"><button type="submit" class="button button-primary">' . \esc_html__('Create Staff User', 'must-hotel-booking') . '</button></p>';
        echo '</form>';
        self::renderPanelEnd();

        // ---- Existing staff users panel --------------------------------------
        $staffUsers = \get_users(['role__in' => StaffAccess::getPortalRoleSlugs(), 'orderby' => 'login', 'order' => 'ASC']);

        self::renderPanelStart(
            \__('Plugin Staff Users', 'must-hotel-booking'),
            \__('These are WordPress accounts that hold a plugin staff role. Deactivating a user blocks them from the staff portal only — it does not deactivate their WordPress account. Delete permanently removes the WordPress user.', 'must-hotel-booking')
        );

        if (empty($staffUsers)) {
            echo '<p>' . \esc_html__('No staff users found. Create one above.', 'must-hotel-booking') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">';
            echo '<thead><tr>';
            echo '<th>' . \esc_html__('Username', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Display Name', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Email', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Role', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
            echo '</tr></thead><tbody>';

            $currentUserId = (int) \get_current_user_id();

            foreach ($staffUsers as $staffUser) {
                $userId   = (int) $staffUser->ID;
                $disabled = StaffAccess::isStaffUserDisabled($staffUser);
                $userRole = '';

                foreach ($staffUser->roles as $roleSlug) {
                    if (isset($roleLabels[$roleSlug])) {
                        $userRole = $roleLabels[$roleSlug];
                        break;
                    }
                }

                echo '<tr>';
                echo '<td><strong>' . \esc_html($staffUser->user_login) . '</strong></td>';
                echo '<td>' . \esc_html($staffUser->display_name) . '</td>';
                echo '<td>' . \esc_html($staffUser->user_email) . '</td>';
                echo '<td>' . \esc_html($userRole) . '</td>';
                echo '<td>';
                self::renderBadge($disabled ? 'disabled' : 'active');
                echo '</td>';
                echo '<td>';

                $isSelf = $userId === $currentUserId;

                // Activate / Deactivate
                $toggleAction = $disabled ? 'activate' : 'deactivate';
                $toggleLabel  = $disabled ? \__('Activate', 'must-hotel-booking') : \__('Deactivate', 'must-hotel-booking');

                if ($isSelf && !$disabled) {
                    echo '<span style="color:#999;">' . \esc_html__('(current user)', 'must-hotel-booking') . '</span> ';
                } else {
                    echo '<form method="post" action="' . \esc_url($actionUrl) . '" style="display:inline;">';
                    \wp_nonce_field('must_settings_staff_users_action', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="staff_user_action" />';
                    echo '<input type="hidden" name="staff_user_sub_action" value="' . \esc_attr($toggleAction) . '" />';
                    echo '<input type="hidden" name="staff_user_id" value="' . \esc_attr((string) $userId) . '" />';
                    echo '<button type="submit" class="button button-small">' . \esc_html($toggleLabel) . '</button>';
                    echo '</form> ';
                }

                // Delete
                if (!$isSelf) {
                    $deleteConfirm = \sprintf(
                        /* translators: %s: username */
                        \__('Permanently delete user "%s"? This will remove their WordPress account. This cannot be undone.', 'must-hotel-booking'),
                        $staffUser->user_login
                    );
                    echo '<form method="post" action="' . \esc_url($actionUrl) . '" style="display:inline;">';
                    \wp_nonce_field('must_settings_staff_users_action', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="staff_user_action" />';
                    echo '<input type="hidden" name="staff_user_sub_action" value="delete" />';
                    echo '<input type="hidden" name="staff_user_id" value="' . \esc_attr((string) $userId) . '" />';
                    echo '<button type="submit" class="button button-small" style="color:#b32d2e;" onclick="return confirm(\'' . \esc_js($deleteConfirm) . '\')">' . \esc_html__('Delete', 'must-hotel-booking') . '</button>';
                    echo '</form>';
                }

                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        self::renderPanelEnd();
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function renderBrandingTab(array $form): void
    {
        $elementorAvailable = \class_exists('\Elementor\Plugin');

        self::renderFormStart('branding');
        self::renderPanelStart(\__('Frontend & Branding', 'must-hotel-booking'), \__('Store reusable UI defaults for future frontend and portal rendering without leaking those concerns into each page individually.', 'must-hotel-booking'));
        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderField(['label' => __('Primary color', 'must-hotel-booking'), 'name' => 'primary_color', 'type' => 'color', 'value' => $form['primary_color'] ?? '#0f766e']);
        self::renderField(['label' => __('Secondary color', 'must-hotel-booking'), 'name' => 'secondary_color', 'type' => 'color', 'value' => $form['secondary_color'] ?? '#155e75']);
        self::renderField(['label' => __('Accent color', 'must-hotel-booking'), 'name' => 'accent_color', 'type' => 'color', 'value' => $form['accent_color'] ?? '#f59e0b']);
        self::renderField(['label' => __('Text color', 'must-hotel-booking'), 'name' => 'text_color', 'type' => 'color', 'value' => $form['text_color'] ?? '#16212b']);
        self::renderField(['label' => __('Border radius', 'must-hotel-booking'), 'name' => 'border_radius', 'type' => 'number', 'min' => 0, 'max' => 40, 'value' => $form['border_radius'] ?? 18]);
        self::renderField(['label' => __('Font family', 'must-hotel-booking'), 'name' => 'font_family', 'value' => $form['font_family'] ?? 'Instrument Sans']);
        self::renderField(['label' => __('Inherit Elementor global colors', 'must-hotel-booking'), 'name' => 'inherit_elementor_colors', 'type' => 'checkbox', 'value' => $form['inherit_elementor_colors'] ?? false, 'description' => $elementorAvailable ? __('Elementor is available on this site.', 'must-hotel-booking') : __('Elementor globals are unavailable right now.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Inherit Elementor global typography', 'must-hotel-booking'), 'name' => 'inherit_elementor_typography', 'type' => 'checkbox', 'value' => $form['inherit_elementor_typography'] ?? false, 'description' => $elementorAvailable ? __('Elementor is available on this site.', 'must-hotel-booking') : __('Elementor globals are unavailable right now.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Portal welcome title', 'must-hotel-booking'), 'name' => 'portal_welcome_title', 'value' => $form['portal_welcome_title'] ?? __('Welcome back', 'must-hotel-booking')]);
        self::renderField(['label' => __('Portal welcome text', 'must-hotel-booking'), 'name' => 'portal_welcome_text', 'type' => 'textarea', 'rows' => 4, 'value' => $form['portal_welcome_text'] ?? '']);
        self::renderField(['label' => __('Booking form style preset', 'must-hotel-booking'), 'name' => 'booking_form_style_preset', 'type' => 'select', 'value' => $form['booking_form_style_preset'] ?? 'balanced', 'options' => ['balanced' => __('Balanced', 'must-hotel-booking'), 'editorial' => __('Editorial', 'must-hotel-booking'), 'minimal' => __('Minimal', 'must-hotel-booking')]]);
        echo '</div>';
        self::renderPanelEnd();
        self::renderFormEnd(\__('Save Branding Defaults', 'must-hotel-booking'));
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $diagnostics
     */
    private static function renderManagedPagesTab(array $form, array $diagnostics): void
    {
        $healthRows = ManagedPages::getHealthRows();
        $pageOptions = self::getPageSelectOptions();
        $issues = [];

        foreach ($healthRows as $row) {
            if (\is_array($row) && !empty($row['required']) && \in_array((string) ($row['health'] ?? ''), ['missing', 'invalid'], true)) {
                $issues[] = (string) ($row['label'] ?? '');
            }
        }

        self::renderWarnings(!empty($issues) ? [\sprintf(\__('Required managed pages need repair: %s', 'must-hotel-booking'), \implode(', ', $issues))] : []);
        self::renderPanelStart(\__('Managed Pages', 'must-hotel-booking'), \__('Track the health of public booking, guest, and staff portal page assignments with quick repair actions.', 'must-hotel-booking'));

        foreach (ManagedPages::getGroupLabels() as $groupKey => $groupLabel) {
            echo '<div class="must-settings-page-group">';
            self::renderSectionIntro($groupLabel);
            echo '<div class="must-settings-page-table">';

            foreach ($healthRows as $row) {
                if (!\is_array($row) || (string) ($row['group'] ?? '') !== $groupKey) {
                    continue;
                }

                $settingKey = (string) ($row['setting_key'] ?? '');
                echo '<article class="must-settings-page-row"><form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'managed_pages'])) . '">';
                \wp_nonce_field('must_settings_managed_pages', 'must_settings_nonce');
                echo '<input type="hidden" name="must_settings_action" value="save_managed_pages" />';
                echo '<div class="must-settings-page-main"><div class="must-settings-page-meta">';
                echo '<strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong>';
                self::renderBadge((string) ($row['page_status'] ?? '') !== '' ? (string) ($row['page_status'] ?? '') : (string) ($row['health'] ?? 'warning'), (string) ($row['status_label'] ?? ''));
                self::renderBadge((string) ($row['health'] ?? 'warning'));
                echo '</div>';
                echo '<p>' . \esc_html((string) ($row['message'] ?? '')) . '</p>';
                echo '<div class="must-settings-page-selector"><label for="' . \esc_attr($settingKey) . '">' . \esc_html__('Assigned page', 'must-hotel-booking') . '</label><select id="' . \esc_attr($settingKey) . '" name="' . \esc_attr($settingKey) . '">';

                foreach ($pageOptions as $pageId => $label) {
                    echo '<option value="' . \esc_attr((string) $pageId) . '"' . \selected((string) ($form[$settingKey] ?? 0), (string) $pageId, false) . '>' . \esc_html($label) . '</option>';
                }

                echo '</select></div></div>';
                echo '<div class="must-settings-page-actions">';
                echo '<button type="submit" class="button button-primary">' . \esc_html__('Assign Page', 'must-hotel-booking') . '</button>';

                if (!empty($row['edit_url'])) {
                    echo '<a class="button" href="' . \esc_url((string) $row['edit_url']) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
                }

                if (!empty($row['view_url'])) {
                    echo '<a class="button" href="' . \esc_url((string) $row['view_url']) . '" target="_blank" rel="noopener noreferrer">' . \esc_html__('View', 'must-hotel-booking') . '</a>';
                }

                echo '</div></form>';
                echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'managed_pages'])) . '" class="must-settings-page-inline-action">';
                \wp_nonce_field('must_settings_managed_pages_action', 'must_settings_nonce');
                echo '<input type="hidden" name="must_settings_action" value="managed_page_action" />';
                echo '<input type="hidden" name="managed_page_key" value="' . \esc_attr($settingKey) . '" />';
                echo '<input type="hidden" name="managed_page_task" value="' . \esc_attr((int) ($row['page_id'] ?? 0) > 0 ? 'recreate' : 'create') . '" />';
                echo '<button type="submit" class="button">' . \esc_html((int) ($row['page_id'] ?? 0) > 0 ? __('Recreate', 'must-hotel-booking') : __('Create Missing Page', 'must-hotel-booking')) . '</button>';
                echo '</form></article>';
            }

            echo '</div></div>';
        }

        self::renderPanelEnd();
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $diagnostics
     */
    private static function renderNotificationsTab(array $form, array $diagnostics): void
    {
        $emailData = isset($diagnostics['emails']) && \is_array($diagnostics['emails']) ? $diagnostics['emails'] : [];
        $warnings = [];

        if (empty($emailData['is_configured'])) {
            $warnings[] = \__('Email sending basics are incomplete. Review sender details and booking notification recipient.', 'must-hotel-booking');
        }

        if ((int) ($emailData['recent_failures'] ?? 0) > 0) {
            $warnings[] = \__('Recent email failures were recorded in the activity log.', 'must-hotel-booking');
        }

        self::renderFormStart('notifications_summary');
        self::renderWarnings($warnings);
        self::renderPanelStart(\__('Notifications & Emails Summary', 'must-hotel-booking'), \__('Keep operational sender settings and template status visible without duplicating the full email editor.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Templates', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($emailData['template_count'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Layout', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($emailData['layout_type'] ?? '')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Recent failures', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($emailData['recent_failures'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('System status', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($emailData['is_configured']) ? __('Configured', 'must-hotel-booking') : __('Needs attention', 'must-hotel-booking')) . '</strong></article>';
        echo '</div>';
        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderField(['label' => __('Sender name', 'must-hotel-booking'), 'name' => 'email_from_name', 'value' => $form['email_from_name'] ?? '']);
        self::renderField(['label' => __('Sender email', 'must-hotel-booking'), 'name' => 'email_from_email', 'type' => 'email', 'value' => $form['email_from_email'] ?? '']);
        self::renderField(['label' => __('Reply-to', 'must-hotel-booking'), 'name' => 'email_reply_to', 'type' => 'email', 'value' => $form['email_reply_to'] ?? '']);
        self::renderField(['label' => __('Booking notification recipient', 'must-hotel-booking'), 'name' => 'booking_notification_email', 'type' => 'email', 'value' => $form['booking_notification_email'] ?? '']);
        self::renderField(['label' => __('Email logo', 'must-hotel-booking'), 'name' => 'email_logo_url', 'type' => 'media', 'value' => $form['email_logo_url'] ?? '']);
        self::renderField(['label' => __('Button color', 'must-hotel-booking'), 'name' => 'email_button_color', 'type' => 'color', 'value' => $form['email_button_color'] ?? '#141414']);
        self::renderField(['label' => __('Shared footer text', 'must-hotel-booking'), 'name' => 'email_footer_text', 'type' => 'textarea', 'rows' => 4, 'value' => $form['email_footer_text'] ?? '']);
        self::renderField(['label' => __('Email layout', 'must-hotel-booking'), 'name' => 'email_layout_type', 'type' => 'select', 'value' => $form['email_layout_type'] ?? 'classic', 'options' => EmailLayoutEngine::getLayoutTypeLabels()]);
        echo '</div>';
        echo '<div class="must-dashboard-action-strip"><span class="must-dashboard-action-strip-label">' . \esc_html__('Quick actions', 'must-hotel-booking') . '</span>';
        if (\function_exists(__NAMESPACE__ . '\get_admin_emails_page_url')) {
            echo '<a class="must-dashboard-action-chip" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Open Emails Page', 'must-hotel-booking') . '</a>';
        }
        echo '<a class="must-dashboard-action-chip" href="' . \esc_url(get_admin_settings_page_url(['tab' => 'maintenance'])) . '">' . \esc_html__('Open Diagnostics', 'must-hotel-booking') . '</a>';
        echo '</div>';
        self::renderPanelEnd();
        self::renderFormEnd(\__('Save Notification Summary', 'must-hotel-booking'));
        echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'notifications_summary'])) . '" class="must-settings-secondary-action">';
        \wp_nonce_field('must_settings_maintenance_action', 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="maintenance_action" />';
        echo '<input type="hidden" name="maintenance_task" value="send_test_email" />';
        echo '<input type="hidden" name="return_tab" value="notifications_summary" />';
        echo '<button type="submit" class="button">' . \esc_html__('Send Test Email', 'must-hotel-booking') . '</button>';
        echo '</form>';
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $diagnostics
     */
    private static function renderProviderTab(array $form, array $diagnostics): void
    {
        $providerData = isset($diagnostics['provider']) && \is_array($diagnostics['provider']) ? $diagnostics['provider'] : [];
        $summary = ClockConnectionDiagnostic::getConfigSummary();
        $warnings = [];
        $websiteBookingFlowMode = (string) ($form['website_booking_flow_mode'] ?? 'plugin_checkout');
        $wbeSnippetConfigured = \trim((string) ($form['clock_wbe_inline_head_snippet'] ?? '')) !== '';

        if ($websiteBookingFlowMode === 'clock_wbe_inline' && !$wbeSnippetConfigured) {
            $warnings[] = \__('Clock WBE Inline mode is active, but the required head snippet is missing. Inline booking buttons and forms stay hidden until the snippet is configured.', 'must-hotel-booking');
        }

        if ($websiteBookingFlowMode === 'clock_wbe_inline' && !\is_ssl()) {
            $warnings[] = \__('Clock WBE Inline requires HTTPS on the public website. This WordPress request is not currently running over HTTPS.', 'must-hotel-booking');
        }

        if ($websiteBookingFlowMode === 'clock_wbe_inline' && (int) MustBookingConfig::get_setting('page_rooms_id', 0) <= 0) {
            $warnings[] = \__('No Rooms page is assigned. Direct visits to legacy booking pages will fall back to the site home page while Clock WBE Inline mode is active.', 'must-hotel-booking');
        }

        if ((string) ($form['provider_mode'] ?? 'local') === 'clock' && empty($summary['clock_direct_public_booking_ready'])) {
            $warnings[] = \__('Direct Clock API mode is selected but still needs credentials, endpoint defaults, catalog sync, or mappings before plugin checkout can use Clock safely.', 'must-hotel-booking');
        }

        if (!empty($form['clock_enabled']) && empty($summary['clock_configured'])) {
            $warnings[] = \__('Clock is enabled but direct API credentials or structured URL parts are still missing.', 'must-hotel-booking');
        }

        if (!empty($providerData['mode_warning'])) {
            $warnings[] = (string) $providerData['mode_warning'];
        }

        self::renderFormStart('provider');
        self::renderWarnings($warnings);
        self::renderPanelStart(\__('Website Booking Flow', 'must-hotel-booking'), \__('Choose how public website visitors start booking. Clock WBE Inline launches Clock directly from website buttons and forms on the current page and does not use the plugin checkout flow.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Website flow mode', 'must-hotel-booking') . '</span><strong>' . \esc_html($websiteBookingFlowMode === 'clock_wbe_inline' ? __('Clock WBE Inline', 'must-hotel-booking') : __('Plugin checkout', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('WBE snippet', 'must-hotel-booking') . '</span><strong>' . \esc_html($wbeSnippetConfigured ? __('Configured', 'must-hotel-booking') : __('Missing', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Public booking ownership', 'must-hotel-booking') . '</span><strong>' . \esc_html($websiteBookingFlowMode === 'clock_wbe_inline' ? __('Clock WBE', 'must-hotel-booking') : __('Plugin checkout', 'must-hotel-booking')) . '</strong></article>';
        echo '</div>';
        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Website flow mode', 'must-hotel-booking'), \__('Keep provider mode for backend integrations only. Use this separate setting to choose whether the public website uses the plugin checkout flow or opens Clock WBE Inline directly from frontend buttons and forms.', 'must-hotel-booking'));
        self::renderField(['label' => __('Website booking flow mode', 'must-hotel-booking'), 'name' => 'website_booking_flow_mode', 'type' => 'select', 'value' => $websiteBookingFlowMode, 'options' => ['plugin_checkout' => __('Plugin checkout', 'must-hotel-booking'), 'clock_wbe_inline' => __('Clock WBE Inline', 'must-hotel-booking')]]);
        self::renderField(['label' => __('Clock WBE head snippet', 'must-hotel-booking'), 'name' => 'clock_wbe_inline_head_snippet', 'type' => 'textarea', 'rows' => 10, 'value' => $form['clock_wbe_inline_head_snippet'] ?? '', 'description' => __('Paste the Clock head snippet exactly as provided by Clock. The plugin injects it on normal public frontend pages while Clock WBE Inline mode is active. Public WBE bookings are created in Clock and may not appear locally until real Clock API sync is configured.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Clock WBE hotel ID', 'must-hotel-booking'), 'name' => 'clock_wbe_hotel_id', 'value' => $form['clock_wbe_hotel_id'] ?? '', 'description' => __('Diagnostic reference only. This comes from the WBE URL and is not used as the direct API subscription ID or account ID.', 'must-hotel-booking')]);
        echo '</div>';
        echo '<p class="description">' . \esc_html__('Clock WBE Inline requirements: use HTTPS, allowlist the hotel domain in Clock WBE settings, and place launcher buttons/forms only on pages where guests should start booking.', 'must-hotel-booking') . '</p>';
        self::renderPanelEnd();

        self::renderPanelStart(\__('Backend Provider Mode', 'must-hotel-booking'), \__('Configure the backend reservation provider. This remains separate from the website booking flow mode above.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Configured mode', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['provider_mode'] ?? 'local')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Active booking runtime', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['active_booking_provider'] ?? ProviderManager::activeKey())) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Direct API config', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($summary['clock_direct_api_configured']) ? __('Configured', 'must-hotel-booking') : __('Incomplete', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('PMS API', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($summary['clock_pms_api_configured']) ? __('Configured', 'must-hotel-booking') : (!empty($summary['clock_pms_api_enabled']) ? __('Enabled, incomplete', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking'))) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Base API', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($summary['clock_base_api_configured']) ? __('Configured', 'must-hotel-booking') : (!empty($summary['clock_base_api_enabled']) ? __('Enabled, incomplete', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking'))) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Direct booking readiness', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($summary['clock_direct_public_booking_ready']) ? __('Ready', 'must-hotel-booking') : __('Needs setup', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Local fallback', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($summary['fallback_to_local_when_clock_unavailable']) ? __('Explicitly enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Catalog paths', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['clock_catalog_paths_configured'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Clock API public booking paths', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['clock_public_booking_paths_configured'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Reconciliation paths', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['clock_reconciliation_paths_configured'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Inbound webhook', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($summary['clock_webhook_secret_set']) ? __('Secret set', 'must-hotel-booking') : __('Secret missing', 'must-hotel-booking')) . '</strong></article>';
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Mode selection', 'must-hotel-booking'), \__('Local is the default backend provider. Clock means direct Clock PMS+ API mode through ProviderManager; it is separate from Clock WBE Inline above.', 'must-hotel-booking'));
        self::renderField(['label' => __('Provider mode', 'must-hotel-booking'), 'name' => 'provider_mode', 'type' => 'select', 'value' => $form['provider_mode'] ?? 'local', 'options' => ['local' => __('Local', 'must-hotel-booking'), 'clock' => __('Clock', 'must-hotel-booking')]]);
        self::renderField(['label' => __('Enable Clock configuration', 'must-hotel-booking'), 'name' => 'clock_enabled', 'type' => 'checkbox', 'value' => !empty($form['clock_enabled']), 'description' => __('Allows backend diagnostics and future endpoint-specific Clock API adapters. It does not make WBE Inline count as direct API readiness.', 'must-hotel-booking')]);
        self::renderField(['label' => __('PMS API enabled', 'must-hotel-booking'), 'name' => 'clock_pms_api_enabled', 'type' => 'checkbox', 'value' => !empty($form['clock_pms_api_enabled']), 'description' => __('Required for direct Clock API catalog, availability, rates, and reservations.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Base API enabled', 'must-hotel-booking'), 'name' => 'clock_base_api_enabled', 'type' => 'checkbox', 'value' => !empty($form['clock_base_api_enabled']), 'description' => __('Optional for Phase 1. Required later for webhook/message-channel setup if Clock grants Base API access.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Clock environment', 'must-hotel-booking'), 'name' => 'clock_environment', 'type' => 'select', 'value' => $form['clock_environment'] ?? 'production', 'options' => ['production' => __('Production', 'must-hotel-booking'), 'sandbox' => __('Sandbox / test', 'must-hotel-booking'), 'custom' => __('Custom', 'must-hotel-booking')]]);
        self::renderField(['label' => __('Request timeout (seconds)', 'must-hotel-booking'), 'name' => 'clock_timeout_seconds', 'type' => 'number', 'min' => 1, 'max' => 60, 'value' => $form['clock_timeout_seconds'] ?? 15]);
        self::renderField(['label' => __('Fallback to local if Clock is unavailable', 'must-hotel-booking'), 'name' => 'fallback_to_local_when_clock_unavailable', 'type' => 'checkbox', 'value' => !empty($form['fallback_to_local_when_clock_unavailable']), 'description' => __('Disabled by default. When disabled, direct Clock API mode must not silently create local bookings if Clock is not ready or fails.', 'must-hotel-booking')]);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Direct Clock API connection', 'must-hotel-booking'), \__('Clock PMS+ API URLs are built from region, API type, subscription ID, and account ID. Credentials use Digest authentication with api_user and api_key.', 'must-hotel-booking'));
        self::renderField(['label' => __('PMS API URL', 'must-hotel-booking'), 'name' => 'clock_pms_api_url', 'type' => 'url', 'value' => $form['clock_pms_api_url'] ?? '', 'description' => __('Preferred for the Clock sandbox. Example: https://sky-eu1.clock-software.com/pms_api/172528/16307', 'must-hotel-booking')]);
        self::renderField(['label' => __('Base API URL', 'must-hotel-booking'), 'name' => 'clock_base_api_url', 'type' => 'url', 'value' => $form['clock_base_api_url'] ?? '', 'description' => __('Preferred for later Base API/webhook setup. Example: https://sky-eu1.clock-software.com/base_api/172528/16307', 'must-hotel-booking')]);
        self::renderField(['label' => __('Subscription region', 'must-hotel-booking'), 'name' => 'clock_region', 'value' => $form['clock_region'] ?? '', 'description' => __('Example: sky-eu1. Do not include https:// or clock-software.com.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Default API type', 'must-hotel-booking'), 'name' => 'clock_api_type', 'type' => 'select', 'value' => $form['clock_api_type'] ?? 'pms_api', 'options' => ['pms_api' => 'pms_api', 'base_api' => 'base_api', 'yield_management_api' => 'yield_management_api', 'pos_api' => 'pos_api'], 'description' => __('Legacy default only. Phase 1 catalog calls explicitly use pms_api; future webhook setup will explicitly use base_api.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Subscription ID', 'must-hotel-booking'), 'name' => 'clock_subscription_id', 'value' => $form['clock_subscription_id'] ?? '']);
        self::renderField(['label' => __('Account ID', 'must-hotel-booking'), 'name' => 'clock_account_id', 'value' => $form['clock_account_id'] ?? '']);
        self::renderField(['label' => __('API user', 'must-hotel-booking'), 'name' => 'clock_api_user', 'value' => $form['clock_api_user'] ?? '']);
        self::renderField(['label' => __('API key', 'must-hotel-booking'), 'name' => 'clock_api_key', 'type' => 'password', 'value' => $form['clock_api_key'] ?? '']);
        self::renderField(['label' => __('Legacy API base URL override', 'must-hotel-booking'), 'name' => 'clock_api_base_url', 'type' => 'url', 'value' => $form['clock_api_base_url'] ?? '', 'description' => __('Retained for migration diagnostics only. Direct API requests prefer the explicit PMS/Base API URLs above, then fall back to the structured Clock URL fields.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Legacy property / hotel ID', 'must-hotel-booking'), 'name' => 'clock_property_id', 'value' => $form['clock_property_id'] ?? '', 'description' => __('Retained for older mappings; direct API URL building uses Account ID above.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Connection test path', 'must-hotel-booking'), 'name' => 'clock_connection_path', 'value' => $form['clock_connection_path'] ?? '/room_types', 'description' => __('Relative PMS API path used by the safe connection test. Phase 1 expects /room_types.', 'must-hotel-booking')]);
        echo '<p class="description"><strong>' . \esc_html__('Resolved PMS API URL:', 'must-hotel-booking') . '</strong> <code>' . \esc_html((string) ($summary['clock_pms_base_url'] ?? '')) . '</code></p>';
        echo '<p class="description"><strong>' . \esc_html__('Resolved Base API URL:', 'must-hotel-booking') . '</strong> <code>' . \esc_html((string) ($summary['clock_base_api_url'] ?? '')) . '</code></p>';
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--3">';
        self::renderSectionIntro(\__('Clock catalog paths', 'must-hotel-booking'), \__('Phase 2 uses these read-only PMS API endpoints for catalog fetch and mapping diagnostics only.', 'must-hotel-booking'));
        self::renderField(['label' => __('Room types path', 'must-hotel-booking'), 'name' => 'clock_room_types_path', 'value' => $form['clock_room_types_path'] ?? '']);
        self::renderField(['label' => __('Physical rooms path', 'must-hotel-booking'), 'name' => 'clock_rooms_path', 'value' => $form['clock_rooms_path'] ?? '']);
        self::renderField(['label' => __('Rates path', 'must-hotel-booking'), 'name' => 'clock_rates_path', 'value' => $form['clock_rates_path'] ?? '']);
        self::renderField(['label' => __('Rates availability path', 'must-hotel-booking'), 'name' => 'clock_rates_availability_path', 'value' => $form['clock_rates_availability_path'] ?? '/rates_availability']);
        self::renderField(['label' => __('Products path', 'must-hotel-booking'), 'name' => 'clock_products_path', 'value' => $form['clock_products_path'] ?? '/products']);
        self::renderField(['label' => __('WBE room-type rates path', 'must-hotel-booking'), 'name' => 'clock_wbe_room_type_rates_path', 'value' => $form['clock_wbe_room_type_rates_path'] ?? '', 'description' => __('Uses this path with fixed query wbe.eq=true and bookable_type.eq=Pms::RoomType.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Rate plans path', 'must-hotel-booking'), 'name' => 'clock_rate_plans_path', 'value' => $form['clock_rate_plans_path'] ?? '']);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--3">';
        self::renderSectionIntro(\__('Clock API public booking paths', 'must-hotel-booking'), \__('Endpoint paths used only by the real provider-based Clock API flow for availability, quotes, and reservation creation.', 'must-hotel-booking'));
        self::renderField(['label' => __('Availability path', 'must-hotel-booking'), 'name' => 'clock_availability_path', 'value' => $form['clock_availability_path'] ?? '']);
        self::renderField(['label' => __('Quote path', 'must-hotel-booking'), 'name' => 'clock_quote_path', 'value' => $form['clock_quote_path'] ?? '']);
        self::renderField(['label' => __('Reservation create path', 'must-hotel-booking'), 'name' => 'clock_reservation_create_path', 'value' => $form['clock_reservation_create_path'] ?? '/bookings/']);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--3">';
        self::renderSectionIntro(\__('Clock reconciliation paths', 'must-hotel-booking'), \__('Optional endpoint paths used to update paid reservations, cancel/expire bookings, assign physical rooms, edit stay dates, or sync guest contact details. Use {reservation_id}, {booking_id}, {provider_room_id}, {checkin}, or {checkout} tokens if the endpoint path needs them.', 'must-hotel-booking'));
        self::renderField(['label' => __('Reservation status update path', 'must-hotel-booking'), 'name' => 'clock_reservation_status_update_path', 'value' => $form['clock_reservation_status_update_path'] ?? '']);
        self::renderField(['label' => __('Reservation cancel path', 'must-hotel-booking'), 'name' => 'clock_reservation_cancel_path', 'value' => $form['clock_reservation_cancel_path'] ?? '']);
        self::renderField(['label' => __('Reservation room update path', 'must-hotel-booking'), 'name' => 'clock_reservation_room_update_path', 'value' => $form['clock_reservation_room_update_path'] ?? '', 'description' => __('Used by provider-aware room assignment and room moves for Clock mirrors.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Reservation stay update path', 'must-hotel-booking'), 'name' => 'clock_reservation_stay_update_path', 'value' => $form['clock_reservation_stay_update_path'] ?? '', 'description' => __('Used by provider-aware stay-date edits for Clock mirrors.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Reservation guest update path', 'must-hotel-booking'), 'name' => 'clock_reservation_guest_update_path', 'value' => $form['clock_reservation_guest_update_path'] ?? '', 'description' => __('Used by provider-aware guest/contact edits for Clock mirrors.', 'must-hotel-booking')]);
        echo '</div>';

        echo '<div class="must-settings-grid must-settings-grid--2">';
        self::renderSectionIntro(\__('Clock inbound sync', 'must-hotel-booking'), \__('Webhook updates refresh existing Clock mirror reservations only. Use a shared secret via token or HMAC signature. The endpoint URL is shown for Clock webhook setup.', 'must-hotel-booking'));
        self::renderField(['label' => __('Reservation fetch path', 'must-hotel-booking'), 'name' => 'clock_reservation_fetch_path', 'value' => $form['clock_reservation_fetch_path'] ?? '/bookings/{booking_id}', 'description' => __('Optional path for manual/deferred mirror refresh. Use {reservation_id} or {booking_id} if needed.', 'must-hotel-booking')]);
        self::renderField(['label' => __('Webhook shared secret', 'must-hotel-booking'), 'name' => 'clock_webhook_secret', 'type' => 'password', 'value' => $form['clock_webhook_secret'] ?? '', 'description' => __('Inbound webhook calls must provide this secret as Bearer/X-MHB-Clock-Token or an HMAC SHA-256 signature.', 'must-hotel-booking')]);
        echo '<p class="description"><strong>' . \esc_html__('Webhook URL:', 'must-hotel-booking') . '</strong> <code>' . \esc_html((string) ($summary['clock_webhook_url'] ?? '')) . '</code></p>';
        echo '</div>';
        self::renderPanelEnd();
        self::renderFormEnd(\__('Save Provider Settings', 'must-hotel-booking'));

        self::renderPanelStart(\__('Clock diagnostics', 'must-hotel-booking'), \__('Run a safe backend connection test. The request is logged without credentials for future troubleshooting.', 'must-hotel-booking'));
        echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'provider'])) . '" class="must-settings-secondary-action">';
        \wp_nonce_field('must_settings_maintenance_action', 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="maintenance_action" />';
        echo '<input type="hidden" name="maintenance_task" value="apply_clock_api_defaults" />';
        echo '<input type="hidden" name="return_tab" value="provider" />';
        echo '<button type="submit" class="button button-secondary">' . \esc_html__('Apply Clock API defaults', 'must-hotel-booking') . '</button>';
        echo '</form>';
        echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'provider'])) . '" class="must-settings-secondary-action">';
        \wp_nonce_field('must_settings_maintenance_action', 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="maintenance_action" />';
        echo '<input type="hidden" name="maintenance_task" value="test_clock_connection" />';
        echo '<input type="hidden" name="return_tab" value="provider" />';
        echo '<button type="submit" class="button button-secondary">' . \esc_html__('Test PMS API room types', 'must-hotel-booking') . '</button>';
        echo '</form>';
        echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'provider'])) . '" class="must-settings-secondary-action">';
        \wp_nonce_field('must_settings_maintenance_action', 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="maintenance_action" />';
        echo '<input type="hidden" name="maintenance_task" value="fetch_clock_catalog" />';
        echo '<input type="hidden" name="return_tab" value="provider" />';
        echo '<button type="submit" class="button button-secondary">' . \esc_html__('Fetch Clock catalog', 'must-hotel-booking') . '</button>';
        echo '</form>';
        echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'provider'])) . '" class="must-settings-secondary-action">';
        \wp_nonce_field('must_settings_maintenance_action', 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="maintenance_action" />';
        echo '<input type="hidden" name="maintenance_task" value="queue_clock_reservation_refresh" />';
        echo '<input type="hidden" name="return_tab" value="provider" />';
        echo '<label for="must-clock-refresh-reservation-id">' . \esc_html__('Queue Clock refresh for local reservation ID', 'must-hotel-booking') . '</label> ';
        echo '<input id="must-clock-refresh-reservation-id" type="number" min="1" name="clock_reservation_id" class="small-text" /> ';
        echo '<button type="submit" class="button button-secondary">' . \esc_html__('Queue refresh', 'must-hotel-booking') . '</button>';
        echo '</form>';
        self::renderPanelEnd();

        self::renderClockReadinessPanel($summary, $providerData);
        self::renderClockCatalogPanel();
        self::renderClockSyncHealthPanel($providerData);
        self::renderClockMappingsPanel();
    }

    /**
     * Render the Clock capability readiness checklist.
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $providerData
     */
    private static function renderClockReadinessPanel(array $summary, array $providerData): void
    {
        $mappingRepo = new ProviderMappingRepository();
        $mappingStats = self::getClockMappingStats($mappingRepo);
        $catalogSummary = ClockCatalogService::getCachedCatalogSummary();

        $clockEnabled = !empty($summary['clock_enabled']);
        $clockConfigured = !empty($summary['clock_direct_api_configured']);
        $directPublicBookingReady = !empty($summary['clock_direct_public_booking_ready']);

        $readiness = [
            [
                'label' => \__('PMS API connection settings', 'must-hotel-booking'),
                'ready' => $clockEnabled && $clockConfigured,
                'note' => \__('Direct Clock API requires Clock enabled, PMS API enabled, region, subscription ID, account ID, api_user, and api_key.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Catalog fetched', 'must-hotel-booking'),
                'ready' => !empty($catalogSummary['last_fetched_at']) && empty($catalogSummary['errors']),
                'note' => !empty($catalogSummary['last_fetched_at'])
                    ? \__('Catalog cache has errors. Fetch again after fixing API access or endpoint paths.', 'must-hotel-booking')
                    : \__('Fetch the Clock catalog before completing mappings.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Accommodation room-type mappings', 'must-hotel-booking'),
                'ready' => (int) ($mappingStats['accommodations']['missing'] ?? 0) === 0 && (int) ($mappingStats['accommodations']['total'] ?? 0) > 0,
                'note' => \sprintf(
                    /* translators: 1: mapped count, 2: total count. */
                    \__('Mapped %1$d of %2$d local accommodations to Clock room_type IDs.', 'must-hotel-booking'),
                    (int) ($mappingStats['accommodations']['mapped'] ?? 0),
                    (int) ($mappingStats['accommodations']['total'] ?? 0)
                ),
            ],
            [
                'label' => \__('Rate mappings', 'must-hotel-booking'),
                'ready' => (int) ($mappingStats['rate_plans']['missing'] ?? 0) === 0 && (int) ($mappingStats['rate_plans']['total'] ?? 0) > 0,
                'note' => \sprintf(
                    /* translators: 1: mapped count, 2: total count. */
                    \__('Mapped %1$d of %2$d local rate plans to Clock rate IDs. Optional Clock rate_plan ID can also be stored.', 'must-hotel-booking'),
                    (int) ($mappingStats['rate_plans']['mapped'] ?? 0),
                    (int) ($mappingStats['rate_plans']['total'] ?? 0)
                ),
            ],
            [
                'label' => \__('Physical room mappings', 'must-hotel-booking'),
                'ready' => true,
                'always_show_note' => true,
                'note' => \sprintf(
                    /* translators: 1: mapped count, 2: total count. */
                    \__('Optional: mapped %1$d of %2$d local physical rooms to Clock room IDs.', 'must-hotel-booking'),
                    (int) ($mappingStats['physical_rooms']['mapped'] ?? 0),
                    (int) ($mappingStats['physical_rooms']['total'] ?? 0)
                ),
            ],
            [
                'label' => \__('Public booking', 'must-hotel-booking'),
                'ready' => $clockEnabled && $clockConfigured && $directPublicBookingReady,
                'note' => \__('Direct Clock API booking needs configured credentials, default endpoint paths, catalog sync, and mappings before plugin checkout can create Clock bookings.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Pricing / quote', 'must-hotel-booking'),
                'ready' => $clockEnabled && $clockConfigured && ClockConfig::productsPath() !== '',
                'note' => \__('Clock product pricing uses GET /products and mapped public rates.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Provider mutations (cancel / status / stay / guest)', 'must-hotel-booking'),
                'ready' => false,
                'note' => \__('Direct Clock API reservation update/cancellation adapters are not implemented yet.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Room assignment / move', 'must-hotel-booking'),
                'ready' => false,
                'note' => \__('Direct Clock API room assignment adapter is not implemented yet.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Inbound webhook sync', 'must-hotel-booking'),
                'ready' => !empty($summary['clock_webhook_secret_set']),
                'note' => \__('Webhook authentication and booking payload handling are available. Add a shared secret and configure Clock to call this site URL.', 'must-hotel-booking'),
            ],
        ];

        self::renderPanelStart(\__('Clock readiness', 'must-hotel-booking'), \__('Per-capability readiness for direct Clock API mode. WBE Inline readiness is intentionally not counted here.', 'must-hotel-booking'));
        echo '<div class="must-dashboard-health-list">';

        foreach ($readiness as $item) {
            $status = $item['ready'] ? 'ok' : 'warning';
            echo '<div class="must-dashboard-health-item">';
            self::renderBadge($status);
            echo '<span class="must-dashboard-health-label">' . \esc_html((string) $item['label']) . '</span>';

            if (!$item['ready'] || !empty($item['always_show_note'])) {
                echo '<span class="must-dashboard-health-text">' . \esc_html((string) $item['note']) . '</span>';
            }

            echo '</div>';
        }

        echo '</div>';
        self::renderPanelEnd();
    }

    /** @return array<string, array<string, int>> */
    private static function getClockMappingStats(ProviderMappingRepository $mappingRepo): array
    {
        $roomRepo = \MustHotelBooking\Engine\get_room_repository();
        $ratePlanRepo = \MustHotelBooking\Engine\get_rate_plan_repository();
        $inventoryRepo = \MustHotelBooking\Engine\get_inventory_repository();

        $localRooms = $roomRepo->roomsTableExists() ? $roomRepo->getRoomSelectorRows(true, false) : [];
        $localRatePlans = $ratePlanRepo->ratePlansTableExists() ? $ratePlanRepo->getRatePlans(true) : [];
        $localPhysicalRooms = [];

        if ($inventoryRepo->inventoryRoomsTableExists()) {
            foreach ($inventoryRepo->getRoomTypes() as $roomType) {
                foreach ($inventoryRepo->getRoomsByType((int) ($roomType['id'] ?? 0)) as $physRoom) {
                    if (\is_array($physRoom)) {
                        $localPhysicalRooms[] = $physRoom;
                    }
                }
            }
        }

        $accommodationTotal = \count($localRooms);
        $ratePlanTotal = \count($localRatePlans);
        $physicalRoomTotal = \count($localPhysicalRooms);
        $accommodationMapped = $mappingRepo->countForProvider(ProviderManager::CLOCK_MODE, 'accommodation');
        $ratePlanMapped = $mappingRepo->countForProvider(ProviderManager::CLOCK_MODE, 'rate_plan');
        $physicalRoomMapped = $mappingRepo->countForProvider(ProviderManager::CLOCK_MODE, 'physical_room');

        return [
            'accommodations' => [
                'total' => $accommodationTotal,
                'mapped' => \min($accommodationMapped, $accommodationTotal),
                'missing' => \max(0, $accommodationTotal - $accommodationMapped),
            ],
            'rate_plans' => [
                'total' => $ratePlanTotal,
                'mapped' => \min($ratePlanMapped, $ratePlanTotal),
                'missing' => \max(0, $ratePlanTotal - $ratePlanMapped),
            ],
            'physical_rooms' => [
                'total' => $physicalRoomTotal,
                'mapped' => \min($physicalRoomMapped, $physicalRoomTotal),
                'missing' => \max(0, $physicalRoomTotal - $physicalRoomMapped),
            ],
        ];
    }

    private static function renderClockCatalogPanel(): void
    {
        $snapshot = ClockCatalogService::getCachedCatalogSnapshot();
        $summary = ClockCatalogService::getCachedCatalogSummary();
        $collections = isset($snapshot['collections']) && \is_array($snapshot['collections']) ? $snapshot['collections'] : [];
        $counts = isset($summary['counts']) && \is_array($summary['counts']) ? $summary['counts'] : [];
        $errors = isset($summary['errors']) && \is_array($summary['errors']) ? $summary['errors'] : [];

        self::renderPanelStart(\__('Clock catalog cache', 'must-hotel-booking'), \__('Read-only Clock PMS API catalog data fetched for mapping. This is not availability, quote, or booking creation.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Last fetched', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['last_fetched_at'] ?? '') ?: __('Never', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Status', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['status'] ?? 'missing')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Room types', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($counts['room_types'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Rooms', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($counts['rooms'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Rates', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($counts['rates'] ?? 0)) . '</strong></article>';
        echo '</div>';

        if (!empty($errors)) {
            echo '<h4 style="margin-bottom:4px;">' . \esc_html__('Catalog fetch errors', 'must-hotel-booking') . '</h4>';
            echo '<ul class="ul-disc">';

            foreach ($errors as $key => $error) {
                if (!\is_array($error)) {
                    continue;
                }

                $preview = isset($error['response_preview']) && \is_array($error['response_preview']) ? $error['response_preview'] : [];
                $previewText = !empty($preview) ? self::jsonPreview($preview) : '';

                echo '<li><strong>' . \esc_html((string) $key) . ':</strong> ';
                echo \esc_html((string) ($error['status'] ?? 'unknown'));
                echo ' | ' . \esc_html__('HTTP', 'must-hotel-booking') . ' ' . \esc_html((string) ($error['http_status'] ?? 0));
                echo ' | <code>' . \esc_html((string) ($error['endpoint_path'] ?? '')) . '</code>';
                echo ' ' . \esc_html((string) ($error['message'] ?? ''));

                if ($previewText !== '') {
                    echo '<pre style="max-height:120px;overflow:auto;background:#f6f7f7;padding:8px;">' . \esc_html($previewText) . '</pre>';
                }

                echo '</li>';
            }

            echo '</ul>';
        }

        foreach (ClockConfig::catalogSyncEndpoints() as $key => $endpoint) {
            $collection = isset($collections[$key]) && \is_array($collections[$key]) ? $collections[$key] : [];
            self::renderClockCatalogPreviewTable((string) ($endpoint['label'] ?? $key), $collection);
        }

        self::renderPanelEnd();
    }

    /** @param array<string, mixed> $collection */
    private static function renderClockCatalogPreviewTable(string $label, array $collection): void
    {
        $items = isset($collection['items']) && \is_array($collection['items']) ? $collection['items'] : [];

        echo '<h4 style="margin-top:16px;margin-bottom:4px;">' . \esc_html($label) . '</h4>';

        if (empty($items)) {
            $preview = isset($collection['response_preview']) && \is_array($collection['response_preview']) ? $collection['response_preview'] : [];
            echo '<p class="description">' . \esc_html__('No normalized catalog items are cached for this endpoint.', 'must-hotel-booking') . '</p>';

            if (!empty($preview)) {
                echo '<pre style="max-height:160px;overflow:auto;background:#f6f7f7;padding:8px;">' . \esc_html(self::jsonPreview($preview)) . '</pre>';
            }

            return;
        }

        echo '<table class="widefat striped" style="margin-bottom:8px;"><thead><tr>';
        echo '<th>' . \esc_html__('ID', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Code', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Parent ID', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Raw compact metadata', 'must-hotel-booking') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (\array_slice($items, 0, 10) as $item) {
            if (!\is_array($item)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($item['id'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($item['name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($item['code'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($item['status'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($item['parent_id'] ?? '')) . '</td>';
            echo '<td><code>' . \esc_html(self::jsonPreview(isset($item['metadata']) && \is_array($item['metadata']) ? $item['metadata'] : [])) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /** @param mixed $value */
    private static function jsonPreview($value): string
    {
        $json = \function_exists('wp_json_encode') ? \wp_json_encode($value) : \json_encode($value);

        return \is_string($json) ? \substr($json, 0, 600) : '';
    }

    /**
     * Compact sync job health summary for the provider tab.
     *
     * @param array<string, mixed> $providerData
     */
    private static function renderClockSyncHealthPanel(array $providerData): void
    {
        $syncJobs = isset($providerData['sync_jobs']) && \is_array($providerData['sync_jobs']) ? $providerData['sync_jobs'] : [];
        $syncSummary = isset($syncJobs['summary']) && \is_array($syncJobs['summary']) ? $syncJobs['summary'] : [];
        $syncCounts = isset($syncSummary['counts']) && \is_array($syncSummary['counts']) ? $syncSummary['counts'] : [];
        $syncCron = isset($syncJobs['cron']) && \is_array($syncJobs['cron']) ? $syncJobs['cron'] : [];
        $recentProblemJobs = isset($syncJobs['recent_problem_jobs']) && \is_array($syncJobs['recent_problem_jobs']) ? $syncJobs['recent_problem_jobs'] : [];
        $inboundSync = isset($providerData['inbound_sync']) && \is_array($providerData['inbound_sync']) ? $providerData['inbound_sync'] : [];
        $inboundSummary = isset($inboundSync['summary']) && \is_array($inboundSync['summary']) ? $inboundSync['summary'] : [];

        self::renderPanelStart(\__('Sync &amp; reconciliation health', 'must-hotel-booking'), \__('Compact view of outbound sync jobs, inbound webhook events, and recent errors.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Pending jobs', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($syncCounts[ProviderSyncJobRepository::STATUS_PENDING] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Retryable jobs', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($syncCounts[ProviderSyncJobRepository::STATUS_RETRYABLE] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Failed / exhausted', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ((int) ($syncCounts[ProviderSyncJobRepository::STATUS_FAILED] ?? 0) + (int) ($syncCounts[ProviderSyncJobRepository::STATUS_EXHAUSTED] ?? 0))) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Sync cron', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($syncCron['health'] ?? 'unknown')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Inbound successful', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($inboundSummary['successful'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Inbound failed', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($inboundSummary['failed'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Webhook configured', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($inboundSync['webhook_secret_set']) ? \__('Yes', 'must-hotel-booking') : \__('No', 'must-hotel-booking')) . '</strong></article>';
        echo '</div>';

        if (!empty($syncSummary['last_error'])) {
            echo '<p class="description"><strong>' . \esc_html__('Last outbound sync error:', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $syncSummary['last_error']) . '</p>';
        }

        if (!empty($inboundSummary['last_error'])) {
            echo '<p class="description"><strong>' . \esc_html__('Last inbound error:', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $inboundSummary['last_error']) . '</p>';
        }

        if (!empty($recentProblemJobs)) {
            echo '<h4 style="margin-bottom:4px;">' . \esc_html__('Recent problem jobs', 'must-hotel-booking') . '</h4>';
            echo '<table class="widefat striped" style="margin-bottom:8px;">';
            echo '<thead><tr><th>' . \esc_html__('ID', 'must-hotel-booking') . '</th><th>' . \esc_html__('Operation', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Attempts', 'must-hotel-booking') . '</th><th>' . \esc_html__('Reservation', 'must-hotel-booking') . '</th><th>' . \esc_html__('Last error', 'must-hotel-booking') . '</th></tr></thead><tbody>';

            foreach ($recentProblemJobs as $job) {
                if (!\is_array($job)) {
                    continue;
                }

                echo '<tr>';
                echo '<td>' . \esc_html((string) ($job['id'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($job['operation'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($job['status'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($job['attempts'] ?? '')) . '/' . \esc_html((string) ($job['max_attempts'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($job['target_local_id'] ?? '')) . '</td>';
                echo '<td style="max-width:300px;word-break:break-word;">' . \esc_html(\wp_trim_words((string) ($job['last_error'] ?? ''), 20)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        self::renderPanelEnd();
    }

    /**
     * Render mapping view/edit panel for Clock entity mappings.
     */
    private static function renderClockMappingsPanel(): void
    {
        $mappingRepo = new ProviderMappingRepository();
        $roomRepo = \MustHotelBooking\Engine\get_room_repository();
        $ratePlanRepo = \MustHotelBooking\Engine\get_rate_plan_repository();
        $inventoryRepo = \MustHotelBooking\Engine\get_inventory_repository();

        $accommodationMappings = $mappingRepo->listForProvider(ProviderManager::CLOCK_MODE, 'accommodation');
        $ratePlanMappings = $mappingRepo->listForProvider(ProviderManager::CLOCK_MODE, 'rate_plan');
        $physicalRoomMappings = $mappingRepo->listForProvider(ProviderManager::CLOCK_MODE, 'physical_room');
        $catalogSnapshot = ClockCatalogService::getCachedCatalogSnapshot();
        $catalogCollections = isset($catalogSnapshot['collections']) && \is_array($catalogSnapshot['collections']) ? $catalogSnapshot['collections'] : [];
        $catalogRoomTypes = self::clockCatalogItems($catalogCollections, 'room_types');
        $catalogRooms = self::clockCatalogItems($catalogCollections, 'rooms');
        $catalogRates = self::clockCatalogItems($catalogCollections, 'rates');
        $catalogRatePlans = self::clockCatalogItems($catalogCollections, 'rate_plans');

        // Index mappings by local_id for quick lookup.
        $accommodationMappedIds = [];

        foreach ($accommodationMappings as $m) {
            $accommodationMappedIds[(int) ($m['local_id'] ?? 0)] = $m;
        }

        $ratePlanMappedIds = [];

        foreach ($ratePlanMappings as $m) {
            $ratePlanMappedIds[(int) ($m['local_id'] ?? 0)] = $m;
        }

        $physicalRoomMappedIds = [];

        foreach ($physicalRoomMappings as $m) {
            $physicalRoomMappedIds[(int) ($m['local_id'] ?? 0)] = $m;
        }

        // Local entities.
        $localRooms = $roomRepo->roomsTableExists() ? $roomRepo->getRoomSelectorRows(true, false) : [];
        $localRatePlans = $ratePlanRepo->ratePlansTableExists() ? $ratePlanRepo->getRatePlans(true) : [];
        $localPhysicalRooms = [];

        if ($inventoryRepo->inventoryRoomsTableExists()) {
            foreach ($inventoryRepo->getRoomTypes() as $roomType) {
                foreach ($inventoryRepo->getRoomsByType((int) ($roomType['id'] ?? 0)) as $physRoom) {
                    if (\is_array($physRoom)) {
                        $localPhysicalRooms[] = $physRoom;
                    }
                }
            }
        }

        $pageUrl = get_admin_settings_page_url(['tab' => 'provider']);

        self::renderPanelStart(\__('Clock entity mappings', 'must-hotel-booking'), \__('Link local entities to their Clock counterparts. These mappings drive availability, quotes, room assignment, and rate plan lookups. Missing mappings for active accommodations are flagged.', 'must-hotel-booking'));
        self::renderClockCatalogDatalist('must-clock-room-type-options', $catalogRoomTypes);
        self::renderClockCatalogDatalist('must-clock-room-options', $catalogRooms);
        self::renderClockCatalogDatalist('must-clock-rate-options', $catalogRates);
        self::renderClockCatalogDatalist('must-clock-rate-plan-options', $catalogRatePlans);

        self::renderClockMappingDiagnostics(
            [
                'accommodation' => self::localEntityMap($localRooms, 'name'),
                'physical_room' => self::localEntityMap($localPhysicalRooms, 'title', 'room_number'),
                'rate_plan' => self::localEntityMap($localRatePlans, 'name'),
            ],
            [
                'accommodation' => self::catalogEntityMap($catalogRoomTypes),
                'physical_room' => self::catalogEntityMap($catalogRooms),
                'rate_plan' => self::catalogEntityMap($catalogRates),
            ],
            [
                'accommodation' => $accommodationMappings,
                'physical_room' => $physicalRoomMappings,
                'rate_plan' => $ratePlanMappings,
            ]
        );

        // --- Accommodation mappings ---
        $missingAccommodations = 0;

        foreach ($localRooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            if (!isset($accommodationMappedIds[(int) ($room['id'] ?? 0)])) {
                $missingAccommodations++;
            }
        }

        echo '<h3 style="margin-bottom:4px;">' . \esc_html__('Accommodation mappings', 'must-hotel-booking') . ' (must_rooms -> Clock room_types)</h3>';

        if ($missingAccommodations > 0) {
            echo '<p class="description" style="color:#b32d2e;"><strong>' . \esc_html(\sprintf(
                /* translators: %d: number of unmapped rooms */
                \_n('%d accommodation has no Clock mapping.', '%d accommodations have no Clock mapping.', $missingAccommodations, 'must-hotel-booking'),
                $missingAccommodations
            )) . '</strong></p>';
        }

        if (!empty($localRooms)) {
            echo '<table class="widefat striped" style="margin-bottom:8px;"><thead><tr>';
            echo '<th>' . \esc_html__('Local ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Clock room_type ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Display name', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Action', 'must-hotel-booking') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($localRooms as $room) {
                if (!\is_array($room)) {
                    continue;
                }

                $roomId = (int) ($room['id'] ?? 0);
                $mapping = $accommodationMappedIds[$roomId] ?? null;
                echo '<tr>';
                echo '<td>' . \esc_html((string) $roomId) . '</td>';
                echo '<td>' . \esc_html((string) ($room['name'] ?? '')) . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['external_id'] ?? '') : '-') . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['display_name'] ?? '') : '-') . '</td>';
                echo '<td>';

                if ($mapping) {
                    echo '<form method="post" action="' . \esc_url($pageUrl) . '" style="display:inline;">';
                    \wp_nonce_field('must_settings_provider_mapping', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="provider_mapping_action" />';
                    echo '<input type="hidden" name="mapping_sub_action" value="delete_mapping" />';
                    echo '<input type="hidden" name="mapping_id" value="' . \esc_attr((string) ($mapping['id'] ?? 0)) . '" />';
                    echo '<button type="submit" class="button button-small" onclick="return confirm(\'' . \esc_js(\__('Delete this mapping?', 'must-hotel-booking')) . '\')">' . \esc_html__('Delete', 'must-hotel-booking') . '</button>';
                    echo '</form>';
                } else {
                    echo '<form method="post" action="' . \esc_url($pageUrl) . '" style="display:inline;white-space:nowrap;">';
                    \wp_nonce_field('must_settings_provider_mapping', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="provider_mapping_action" />';
                    echo '<input type="hidden" name="mapping_sub_action" value="save_mapping" />';
                    echo '<input type="hidden" name="mapping_entity_type" value="accommodation" />';
                    echo '<input type="hidden" name="mapping_local_id" value="' . \esc_attr((string) $roomId) . '" />';
                    echo '<input type="text" name="mapping_external_id" list="must-clock-room-type-options" placeholder="' . \esc_attr(\__('Clock room_type ID', 'must-hotel-booking')) . '" class="small-text" required /> ';
                    echo '<input type="text" name="mapping_display_name" placeholder="' . \esc_attr(\__('Name (optional)', 'must-hotel-booking')) . '" class="small-text" /> ';
                    echo '<button type="submit" class="button button-small button-primary">' . \esc_html__('Add', 'must-hotel-booking') . '</button>';
                    echo '</form>';
                }

                echo '</td></tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p class="description">' . \esc_html__('No local accommodations found. Add rooms first.', 'must-hotel-booking') . '</p>';
        }

        // --- Rate plan mappings ---
        echo '<h3 style="margin-top:20px;margin-bottom:4px;">' . \esc_html__('Rate plan mappings', 'must-hotel-booking') . ' (mhb_rate_plans -> Clock rates)</h3>';

        if (!empty($localRatePlans)) {
            echo '<table class="widefat striped" style="margin-bottom:8px;"><thead><tr>';
            echo '<th>' . \esc_html__('Local ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Rate plan', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Clock rate ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Clock rate_plan ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('WRS', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Display name', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Action', 'must-hotel-booking') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($localRatePlans as $plan) {
                if (!\is_array($plan)) {
                    continue;
                }

                $planId = (int) ($plan['id'] ?? 0);
                $mapping = $ratePlanMappedIds[$planId] ?? null;
                echo '<tr>';
                echo '<td>' . \esc_html((string) $planId) . '</td>';
                echo '<td>' . \esc_html((string) ($plan['name'] ?? '')) . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['external_id'] ?? '') : '-') . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['external_parent_id'] ?? '') : '-') . '</td>';
                echo '<td>' . \esc_html($mapping ? self::mappingRateVisibilityLabel($mapping, $catalogRates) : '-') . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['display_name'] ?? '') : '-') . '</td>';
                echo '<td>';

                if ($mapping) {
                    echo '<form method="post" action="' . \esc_url($pageUrl) . '" style="display:inline;">';
                    \wp_nonce_field('must_settings_provider_mapping', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="provider_mapping_action" />';
                    echo '<input type="hidden" name="mapping_sub_action" value="delete_mapping" />';
                    echo '<input type="hidden" name="mapping_id" value="' . \esc_attr((string) ($mapping['id'] ?? 0)) . '" />';
                    echo '<button type="submit" class="button button-small" onclick="return confirm(\'' . \esc_js(\__('Delete this mapping?', 'must-hotel-booking')) . '\')">' . \esc_html__('Delete', 'must-hotel-booking') . '</button>';
                    echo '</form>';
                } else {
                    echo '<form method="post" action="' . \esc_url($pageUrl) . '" style="display:inline;white-space:nowrap;">';
                    \wp_nonce_field('must_settings_provider_mapping', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="provider_mapping_action" />';
                    echo '<input type="hidden" name="mapping_sub_action" value="save_mapping" />';
                    echo '<input type="hidden" name="mapping_entity_type" value="rate_plan" />';
                    echo '<input type="hidden" name="mapping_local_id" value="' . \esc_attr((string) $planId) . '" />';
                    echo '<input type="text" name="mapping_external_id" list="must-clock-rate-options" placeholder="' . \esc_attr(\__('Clock rate ID', 'must-hotel-booking')) . '" class="small-text" required /> ';
                    echo '<input type="text" name="mapping_external_parent_id" list="must-clock-rate-plan-options" placeholder="' . \esc_attr(\__('Clock rate_plan ID', 'must-hotel-booking')) . '" class="small-text" /> ';
                    echo '<input type="text" name="mapping_display_name" placeholder="' . \esc_attr(\__('Name (optional)', 'must-hotel-booking')) . '" class="small-text" /> ';
                    echo '<button type="submit" class="button button-small button-primary">' . \esc_html__('Add', 'must-hotel-booking') . '</button>';
                    echo '</form>';
                }

                echo '</td></tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p class="description">' . \esc_html__('No rate plans found.', 'must-hotel-booking') . '</p>';
        }

        // --- Physical room mappings ---
        echo '<h3 style="margin-top:20px;margin-bottom:4px;">' . \esc_html__('Physical room mappings', 'must-hotel-booking') . ' (mhb_rooms -> Clock rooms, optional)</h3>';

        if (!empty($localPhysicalRooms)) {
            echo '<table class="widefat striped" style="margin-bottom:8px;"><thead><tr>';
            echo '<th>' . \esc_html__('Local ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Room', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Clock room ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Clock room_type ID', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Display name', 'must-hotel-booking') . '</th>';
            echo '<th>' . \esc_html__('Action', 'must-hotel-booking') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($localPhysicalRooms as $physRoom) {
                if (!\is_array($physRoom)) {
                    continue;
                }

                $physId = (int) ($physRoom['id'] ?? 0);
                $mapping = $physicalRoomMappedIds[$physId] ?? null;
                echo '<tr>';
                echo '<td>' . \esc_html((string) $physId) . '</td>';
                echo '<td>' . \esc_html((string) ($physRoom['name'] ?? ($physRoom['room_number'] ?? ''))) . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['external_id'] ?? '') : '-') . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['external_parent_id'] ?? '') : '-') . '</td>';
                echo '<td>' . \esc_html($mapping ? (string) ($mapping['display_name'] ?? '') : '-') . '</td>';
                echo '<td>';

                if ($mapping) {
                    echo '<form method="post" action="' . \esc_url($pageUrl) . '" style="display:inline;">';
                    \wp_nonce_field('must_settings_provider_mapping', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="provider_mapping_action" />';
                    echo '<input type="hidden" name="mapping_sub_action" value="delete_mapping" />';
                    echo '<input type="hidden" name="mapping_id" value="' . \esc_attr((string) ($mapping['id'] ?? 0)) . '" />';
                    echo '<button type="submit" class="button button-small" onclick="return confirm(\'' . \esc_js(\__('Delete this mapping?', 'must-hotel-booking')) . '\')">' . \esc_html__('Delete', 'must-hotel-booking') . '</button>';
                    echo '</form>';
                } else {
                    echo '<form method="post" action="' . \esc_url($pageUrl) . '" style="display:inline;white-space:nowrap;">';
                    \wp_nonce_field('must_settings_provider_mapping', 'must_settings_nonce');
                    echo '<input type="hidden" name="must_settings_action" value="provider_mapping_action" />';
                    echo '<input type="hidden" name="mapping_sub_action" value="save_mapping" />';
                    echo '<input type="hidden" name="mapping_entity_type" value="physical_room" />';
                    echo '<input type="hidden" name="mapping_local_id" value="' . \esc_attr((string) $physId) . '" />';
                    echo '<input type="text" name="mapping_external_id" list="must-clock-room-options" placeholder="' . \esc_attr(\__('Clock room ID', 'must-hotel-booking')) . '" class="small-text" required /> ';
                    echo '<input type="text" name="mapping_external_parent_id" list="must-clock-room-type-options" placeholder="' . \esc_attr(\__('Clock room_type ID', 'must-hotel-booking')) . '" class="small-text" /> ';
                    echo '<input type="text" name="mapping_display_name" placeholder="' . \esc_attr(\__('Name (optional)', 'must-hotel-booking')) . '" class="small-text" /> ';
                    echo '<button type="submit" class="button button-small button-primary">' . \esc_html__('Add', 'must-hotel-booking') . '</button>';
                    echo '</form>';
                }

                echo '</td></tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p class="description">' . \esc_html__('No physical rooms found. Inventory rooms must exist first.', 'must-hotel-booking') . '</p>';
        }

        self::renderPanelEnd();
    }

    /**
     * @param array<string, mixed> $collections
     * @return array<int, array<string, mixed>>
     */
    private static function clockCatalogItems(array $collections, string $key): array
    {
        $collection = isset($collections[$key]) && \is_array($collections[$key]) ? $collections[$key] : [];
        $items = isset($collection['items']) && \is_array($collection['items']) ? $collection['items'] : [];
        $out = [];

        foreach ($items as $item) {
            if (\is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, array<string, mixed>>
     */
    private static function catalogEntityMap(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $id = \trim((string) ($item['id'] ?? ''));

            if ($id !== '') {
                $map[$id] = $item;
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private static function localEntityMap(array $items, string $labelKey, string $fallbackKey = ''): array
    {
        $map = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $id = isset($item['id']) ? (int) $item['id'] : 0;

            if ($id <= 0) {
                continue;
            }

            $label = (string) ($item[$labelKey] ?? '');

            if ($label === '' && $fallbackKey !== '') {
                $label = (string) ($item[$fallbackKey] ?? '');
            }

            $map[$id] = $label !== '' ? $label : '#' . $id;
        }

        return $map;
    }

    /**
     * @param array<string, array<int, string>> $localMaps
     * @param array<string, array<string, array<string, mixed>>> $catalogMaps
     * @param array<string, array<int, array<string, mixed>>> $mappingGroups
     */
    private static function renderClockMappingDiagnostics(array $localMaps, array $catalogMaps, array $mappingGroups): void
    {
        $labels = [
            'accommodation' => \__('Accommodations', 'must-hotel-booking'),
            'physical_room' => \__('Physical rooms', 'must-hotel-booking'),
            'rate_plan' => \__('Rate plans', 'must-hotel-booking'),
        ];

        echo '<h3 style="margin-bottom:4px;">' . \esc_html__('Mapping diagnostics', 'must-hotel-booking') . '</h3>';
        echo '<table class="widefat striped" style="margin-bottom:12px;"><thead><tr>';
        echo '<th>' . \esc_html__('Entity', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Unmapped local', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Unmapped Clock', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Missing local target', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Missing Clock source', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Stale mappings', 'must-hotel-booking') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($labels as $entityType => $label) {
            $diagnostics = self::clockMappingDiagnosticsForEntity(
                $localMaps[$entityType] ?? [],
                $catalogMaps[$entityType] ?? [],
                $mappingGroups[$entityType] ?? []
            );

            echo '<tr>';
            echo '<td><strong>' . \esc_html($label) . '</strong></td>';
            echo '<td>' . \esc_html((string) \count($diagnostics['unmapped_local'])) . '</td>';
            echo '<td>' . \esc_html((string) \count($diagnostics['unmapped_clock'])) . '</td>';
            echo '<td>' . \esc_html((string) \count($diagnostics['missing_local_target'])) . '</td>';
            echo '<td>' . \esc_html((string) \count($diagnostics['missing_clock_source'])) . '</td>';
            echo '<td>' . \esc_html((string) \count($diagnostics['stale_mappings'])) . '</td>';
            echo '</tr>';

            foreach ([
                'unmapped_local' => \__('Unmapped local', 'must-hotel-booking'),
                'unmapped_clock' => \__('Unmapped Clock', 'must-hotel-booking'),
                'missing_local_target' => \__('Missing local target', 'must-hotel-booking'),
                'missing_clock_source' => \__('Missing Clock source', 'must-hotel-booking'),
            ] as $key => $itemLabel) {
                if (empty($diagnostics[$key])) {
                    continue;
                }

                echo '<tr><td></td><td colspan="5"><span class="description"><strong>' . \esc_html($label . ' - ' . $itemLabel) . ':</strong> ' . \esc_html(\implode(', ', \array_slice($diagnostics[$key], 0, 8))) . '</span></td></tr>';
            }
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<int, string> $localMap
     * @param array<string, array<string, mixed>> $catalogMap
     * @param array<int, array<string, mixed>> $mappings
     * @return array<string, array<int, string>>
     */
    private static function clockMappingDiagnosticsForEntity(array $localMap, array $catalogMap, array $mappings): array
    {
        $mappedLocalIds = [];
        $mappedExternalIds = [];
        $missingLocalTarget = [];
        $missingClockSource = [];
        $staleMappings = [];

        foreach ($mappings as $mapping) {
            if (!\is_array($mapping)) {
                continue;
            }

            $mappingId = isset($mapping['id']) ? (int) $mapping['id'] : 0;
            $localId = isset($mapping['local_id']) ? (int) $mapping['local_id'] : 0;
            $externalId = \trim((string) ($mapping['external_id'] ?? ''));

            if ($localId > 0) {
                $mappedLocalIds[$localId] = true;

                if (!isset($localMap[$localId])) {
                    $missingLocalTarget[] = '#' . $mappingId . ' local ' . $localId;
                    $staleMappings[] = '#' . $mappingId;
                }
            }

            if ($externalId !== '') {
                $mappedExternalIds[$externalId] = true;

                if (!isset($catalogMap[$externalId])) {
                    $missingClockSource[] = '#' . $mappingId . ' Clock ' . $externalId;
                    $staleMappings[] = '#' . $mappingId;
                }
            }
        }

        $unmappedLocal = [];

        foreach ($localMap as $id => $label) {
            if (!isset($mappedLocalIds[(int) $id])) {
                $unmappedLocal[] = '#' . (int) $id . ' ' . $label;
            }
        }

        $unmappedClock = [];

        foreach ($catalogMap as $id => $item) {
            if (!isset($mappedExternalIds[(string) $id])) {
                $name = (string) ($item['name'] ?? '');
                $unmappedClock[] = $id . ($name !== '' ? ' ' . $name : '');
            }
        }

        return [
            'unmapped_local' => \array_values($unmappedLocal),
            'unmapped_clock' => \array_values($unmappedClock),
            'missing_local_target' => \array_values($missingLocalTarget),
            'missing_clock_source' => \array_values($missingClockSource),
            'stale_mappings' => \array_values(\array_unique($staleMappings)),
        ];
    }

    /** @param array<string, mixed> $mapping @param array<int, array<string, mixed>> $catalogRates */
    private static function mappingRateVisibilityLabel(array $mapping, array $catalogRates): string
    {
        $externalId = \trim((string) ($mapping['external_id'] ?? ''));

        foreach ($catalogRates as $rate) {
            if (!\is_array($rate) || (string) ($rate['id'] ?? '') !== $externalId) {
                continue;
            }

            return self::publicVisibilityLabel((string) ($rate['public_visible'] ?? 'unknown'));
        }

        $metadata = \json_decode((string) ($mapping['metadata'] ?? ''), true);

        if (\is_array($metadata) && isset($metadata['public_visible'])) {
            return self::publicVisibilityLabel((string) $metadata['public_visible']);
        }

        return self::publicVisibilityLabel('unknown');
    }

    private static function publicVisibilityLabel(string $value): string
    {
        if ($value === 'yes') {
            return \__('Published', 'must-hotel-booking');
        }

        if ($value === 'no') {
            return \__('Hidden', 'must-hotel-booking');
        }

        return \__('Unknown', 'must-hotel-booking');
    }

    /** @return array<string, mixed> */
    private static function findClockCatalogItemForMapping(string $entityType, string $externalId): array
    {
        $snapshot = ClockCatalogService::getCachedCatalogSnapshot();
        $collections = isset($snapshot['collections']) && \is_array($snapshot['collections']) ? $snapshot['collections'] : [];
        $collectionKey = [
            'accommodation' => 'room_types',
            'physical_room' => 'rooms',
            'rate_plan' => 'rates',
        ][$entityType] ?? '';

        if ($collectionKey === '') {
            return [];
        }

        foreach (self::clockCatalogItems($collections, $collectionKey) as $item) {
            if ((string) ($item['id'] ?? '') === $externalId) {
                return $item;
            }
        }

        return [];
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function renderClockCatalogDatalist(string $id, array $items): void
    {
        echo '<datalist id="' . \esc_attr($id) . '">';

        foreach ($items as $item) {
            $externalId = \trim((string) ($item['id'] ?? ''));

            if ($externalId === '') {
                continue;
            }

            $labelParts = [];

            foreach (['name', 'code', 'parent_id'] as $key) {
                $value = \trim((string) ($item[$key] ?? ''));

                if ($value !== '') {
                    $labelParts[] = $value;
                }
            }

            echo '<option value="' . \esc_attr($externalId) . '" label="' . \esc_attr(\implode(' | ', $labelParts)) . '"></option>';
        }

        echo '</datalist>';
    }

    /**
     * @param array<string, mixed> $diagnostics
     */
    private static function renderMaintenanceTab(array $diagnostics, array $form): void
    {
        $environment = isset($diagnostics['environment']) && \is_array($diagnostics['environment']) ? $diagnostics['environment'] : [];
        $payments = isset($diagnostics['payments']) && \is_array($diagnostics['payments']) ? $diagnostics['payments'] : [];
        $emails = isset($diagnostics['emails']) && \is_array($diagnostics['emails']) ? $diagnostics['emails'] : [];
        $antiAbuse = isset($diagnostics['anti_abuse']) && \is_array($diagnostics['anti_abuse']) ? $diagnostics['anti_abuse'] : [];
        $cron = isset($diagnostics['cron']) && \is_array($diagnostics['cron']) ? $diagnostics['cron'] : [];
        $updater = isset($diagnostics['updater']) && \is_array($diagnostics['updater']) ? $diagnostics['updater'] : [];
        $provider = isset($diagnostics['provider']) && \is_array($diagnostics['provider']) ? $diagnostics['provider'] : [];
        $providerSyncJobs = isset($provider['sync_jobs']) && \is_array($provider['sync_jobs']) ? $provider['sync_jobs'] : [];
        $providerInboundSync = isset($provider['inbound_sync']) && \is_array($provider['inbound_sync']) ? $provider['inbound_sync'] : [];
        $providerInboundSummary = isset($providerInboundSync['summary']) && \is_array($providerInboundSync['summary']) ? $providerInboundSync['summary'] : [];
        $providerSyncSummary = isset($providerSyncJobs['summary']) && \is_array($providerSyncJobs['summary']) ? $providerSyncJobs['summary'] : [];
        $providerSyncCounts = isset($providerSyncSummary['counts']) && \is_array($providerSyncSummary['counts']) ? $providerSyncSummary['counts'] : [];
        $providerSyncCron = isset($providerSyncJobs['cron']) && \is_array($providerSyncJobs['cron']) ? $providerSyncJobs['cron'] : [];
        $providerSyncProblemCount = (int) ($providerSyncCounts[\MustHotelBooking\Provider\Storage\ProviderSyncJobRepository::STATUS_FAILED] ?? 0)
            + (int) ($providerSyncCounts[\MustHotelBooking\Provider\Storage\ProviderSyncJobRepository::STATUS_EXHAUSTED] ?? 0);

        self::renderPanelStart(\__('Diagnostics & Maintenance', 'must-hotel-booking'), \__('Turn system health into direct admin actions for setup repair and release readiness.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Plugin version', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($environment['plugin_version'] ?? '')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('DB version', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($environment['database_version'] ?? '')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Rooms', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($environment['room_count'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Cron', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($cron['next_run'] ?? '')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Booking protection', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($antiAbuse['enabled']) ? __('Enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Recent blocked attempts', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($antiAbuse['recent_blocked_total'] ?? 0)) . '</strong></article>';
        echo '</div>';

        echo '<div class="must-settings-status-columns">';
        echo '<div class="must-settings-status-column">';
        self::renderSectionIntro(\__('System checks', 'must-hotel-booking'));
        echo '<div class="must-dashboard-health-list">';
        $items = [
            ['label' => __('Database tables', 'must-hotel-booking'), 'status' => (int) ($diagnostics['critical_issues'] ?? 0) > 0 ? 'warning' : 'ok', 'text' => \sprintf(\__('Critical issues: %d', 'must-hotel-booking'), (int) ($diagnostics['critical_issues'] ?? 0))],
            ['label' => __('Managed pages', 'must-hotel-booking'), 'status' => empty($diagnostics['pages']) ? 'missing' : 'ok', 'text' => \sprintf(\__('Tracked pages: %d', 'must-hotel-booking'), \count((array) ($diagnostics['pages'] ?? [])))],
            ['label' => __('Portal routes', 'must-hotel-booking'), 'status' => 'ok', 'text' => \sprintf(\__('Portal base: /%1$s | Login: /%2$s', 'must-hotel-booking'), PortalRouter::getPortalBasePath(), PortalRouter::getPortalLoginPath())],
            ['label' => __('Email config', 'must-hotel-booking'), 'status' => !empty($emails['is_configured']) ? 'ok' : 'warning', 'text' => !empty($emails['is_configured']) ? __('Email sending basics are configured.', 'must-hotel-booking') : __('Email configuration needs attention.', 'must-hotel-booking')],
            ['label' => __('Payment config', 'must-hotel-booking'), 'status' => ((!empty($payments['stripe_enabled']) && empty($payments['stripe_configured'])) || (!empty($payments['pokpay_enabled']) && empty($payments['pokpay_configured']))) ? 'warning' : 'ok', 'text' => \sprintf(__('Enabled methods: %s', 'must-hotel-booking'), !empty($payments['enabled_methods']) ? \implode(', ', (array) $payments['enabled_methods']) : __('none', 'must-hotel-booking'))],
            ['label' => __('Booking protection', 'must-hotel-booking'), 'status' => !empty($antiAbuse['enabled']) ? 'ok' : 'warning', 'text' => !empty($antiAbuse['enabled']) ? __('Plugin-side anti-spam protections are enabled for public booking when individual checks are turned on.', 'must-hotel-booking') : __('Anti-spam protections are currently disabled.', 'must-hotel-booking')],
            ['label' => __('Provider runtime', 'must-hotel-booking'), 'status' => !empty($provider['mode_warning']) ? 'warning' : 'ok', 'text' => !empty($provider['mode_warning']) ? (string) $provider['mode_warning'] : __('Provider runtime is using the configured booking-ready provider.', 'must-hotel-booking')],
            ['label' => __('Cron / cleanup', 'must-hotel-booking'), 'status' => (string) ($cron['status'] ?? 'warning'), 'text' => (string) ($cron['message'] ?? '')],
            ['label' => __('Provider sync', 'must-hotel-booking'), 'status' => $providerSyncProblemCount > 0 || (string) ($providerSyncCron['health'] ?? '') === 'missing' ? 'warning' : 'ok', 'text' => \sprintf(__('Due jobs: %1$d | Retryable: %2$d | Failed/exhausted: %3$d', 'must-hotel-booking'), (int) ($providerSyncSummary['due'] ?? 0), (int) ($providerSyncCounts[\MustHotelBooking\Provider\Storage\ProviderSyncJobRepository::STATUS_RETRYABLE] ?? 0), $providerSyncProblemCount)],
            ['label' => __('Clock inbound sync', 'must-hotel-booking'), 'status' => (int) ($providerInboundSummary['failed'] ?? 0) > 0 ? 'warning' : 'ok', 'text' => \sprintf(__('Inbound events: %1$d successful, %2$d failed', 'must-hotel-booking'), (int) ($providerInboundSummary['successful'] ?? 0), (int) ($providerInboundSummary['failed'] ?? 0))],
            ['label' => __('GitHub updater', 'must-hotel-booking'), 'status' => !empty($updater['version_consistent']) && !empty($updater['asset_pattern_strict']) ? 'ok' : 'warning', 'text' => !empty($updater['release_readiness_message']) ? (string) $updater['release_readiness_message'] : __('Updater readiness has not been validated yet.', 'must-hotel-booking')],
        ];

        foreach ($items as $item) {
            echo '<div class="must-dashboard-health-item"><div class="must-dashboard-health-header"><strong>' . \esc_html((string) $item['label']) . '</strong>';
            self::renderBadge((string) $item['status']);
            echo '</div><p>' . \esc_html((string) $item['text']) . '</p></div>';
        }

        echo '</div></div>';
        echo '<div class="must-settings-status-column">';
        self::renderSectionIntro(\__('Maintenance actions', 'must-hotel-booking'));
        echo '<div class="must-dashboard-actions-grid">';
        foreach (['reinstall_pages' => __('Reinstall missing managed pages', 'must-hotel-booking'), 'flush_portal_routes' => __('Flush portal routes', 'must-hotel-booking'), 'reschedule_cron' => __('Reschedule cron jobs', 'must-hotel-booking'), 'cleanup_expired_locks' => __('Clear expired locks', 'must-hotel-booking'), 'repair_inventory_mirror' => __('Repair inventory mirror', 'must-hotel-booking'), 'run_provider_sync_jobs' => __('Run provider sync jobs now', 'must-hotel-booking'), 'send_test_email' => __('Send test email', 'must-hotel-booking')] as $task => $label) {
            echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'maintenance'])) . '">';
            \wp_nonce_field('must_settings_maintenance_action', 'must_settings_nonce');
            echo '<input type="hidden" name="must_settings_action" value="maintenance_action" />';
            echo '<input type="hidden" name="maintenance_task" value="' . \esc_attr($task) . '" />';
            echo '<button type="submit" class="button must-dashboard-action-button">' . \esc_html($label) . '</button>';
            echo '</form>';
        }
        echo '<button type="button" class="button must-dashboard-action-button" data-must-copy="must-settings-system-report">' . \esc_html__('Copy system report', 'must-hotel-booking') . '</button>';
        echo '<textarea id="must-settings-system-report" class="must-settings-copy-source must-settings-report-source" readonly>' . \esc_textarea(SettingsDiagnostics::getSystemReportText()) . '</textarea>';
        if (\function_exists(__NAMESPACE__ . '\get_admin_payments_page_url')) {
            echo '<a class="button must-dashboard-action-button" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments Config', 'must-hotel-booking') . '</a>';
        }
        if (\function_exists(__NAMESPACE__ . '\get_admin_rooms_page_url')) {
            echo '<a class="button must-dashboard-action-button" href="' . \esc_url(get_admin_rooms_page_url()) . '">' . \esc_html__('Open Accommodations', 'must-hotel-booking') . '</a>';
        }
        echo '</div></div></div>';
        echo '<div class="must-settings-status-columns">';
        echo '<div class="must-settings-status-column">';
        self::renderSectionIntro(\__('Database table checks', 'must-hotel-booking'));
        echo '<div class="must-dashboard-health-list">';
        foreach ((array) ($diagnostics['tables'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            echo '<div class="must-dashboard-health-item"><div class="must-dashboard-health-header"><strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong>';
            self::renderBadge((string) ($row['status'] ?? 'warning'));
            echo '</div><p><code>' . \esc_html((string) ($row['table_name'] ?? '')) . '</code><br />' . \esc_html((string) ($row['message'] ?? '')) . '</p></div>';
        }
        echo '</div></div>';
        echo '<div class="must-settings-status-column">';
        self::renderSectionIntro(\__('Managed page health', 'must-hotel-booking'));
        echo '<div class="must-dashboard-health-list">';
        foreach ((array) ($diagnostics['pages'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            echo '<div class="must-dashboard-health-item"><div class="must-dashboard-health-header"><strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong>';
            self::renderBadge((string) ($row['health'] ?? 'warning'));
            echo '</div><p>' . \esc_html((string) ($row['message'] ?? '')) . '</p></div>';
        }
        echo '</div></div></div>';

        echo '<div class="must-settings-status-columns">';
        echo '<div class="must-settings-status-column">';
        self::renderSectionIntro(\__('Blocked reservation attempts', 'must-hotel-booking'));
        echo '<div class="must-dashboard-health-list">';

        if (empty($antiAbuse['logging_enabled'])) {
            echo '<div class="must-dashboard-health-item"><div class="must-dashboard-health-header"><strong>' . \esc_html__('Abuse logging is disabled', 'must-hotel-booking') . '</strong>';
            self::renderBadge('warning');
            echo '</div><p>' . \esc_html__('Enable abuse logging in Booking Rules to record and review blocked reservation attempts here.', 'must-hotel-booking') . '</p></div>';
        } elseif (empty($antiAbuse['recent_blocked_attempts']) || !\is_array($antiAbuse['recent_blocked_attempts'])) {
            echo '<div class="must-dashboard-health-item"><div class="must-dashboard-health-header"><strong>' . \esc_html__('No recent blocked attempts', 'must-hotel-booking') . '</strong>';
            self::renderBadge('ok');
            echo '</div><p>' . \esc_html__('Recent blocked public reservation attempts will appear here when the protection layer rejects a request.', 'must-hotel-booking') . '</p></div>';
        } else {
            foreach ((array) $antiAbuse['recent_blocked_attempts'] as $attempt) {
                if (!\is_array($attempt)) {
                    continue;
                }

                $reason = (string) ($attempt['reason_label'] ?? __('Request blocked', 'must-hotel-booking'));
                $metaParts = [];

                if ((string) ($attempt['created_at'] ?? '') !== '') {
                    $metaParts[] = (string) $attempt['created_at'];
                }

                if ((string) ($attempt['surface'] ?? '') !== '') {
                    $metaParts[] = (string) $attempt['surface'];
                }

                if ((string) ($attempt['payment_method'] ?? '') !== '') {
                    $metaParts[] = (string) $attempt['payment_method'];
                }

                if ((string) ($attempt['actor_ip'] ?? '') !== '') {
                    $metaParts[] = 'IP ' . (string) $attempt['actor_ip'];
                }

                $identity = (string) ($attempt['email'] ?? '');

                if ($identity === '') {
                    $identity = (string) ($attempt['reference'] ?? '');
                }

                echo '<div class="must-dashboard-health-item"><div class="must-dashboard-health-header"><strong>' . \esc_html($reason) . '</strong>';
                self::renderBadge('warning');
                echo '</div><p>' . \esc_html(\implode(' | ', $metaParts)) . '</p>';

                if ($identity !== '') {
                    echo '<p><code>' . \esc_html($identity) . '</code></p>';
                }

                if ((string) ($attempt['message'] ?? '') !== '') {
                    echo '<p>' . \esc_html((string) $attempt['message']) . '</p>';
                }

                echo '</div>';
            }
        }

        echo '</div></div>';
        echo '<div class="must-settings-status-column">';
        self::renderSectionIntro(\__('Protection configuration', 'must-hotel-booking'));
        echo '<div class="must-dashboard-health-list">';
        $protectionItems = [
            [
                'label' => __('Master protection', 'must-hotel-booking'),
                'status' => !empty($antiAbuse['enabled']) ? 'ok' : 'warning',
                'text' => !empty($antiAbuse['enabled'])
                    ? __('Enabled from Booking Rules.', 'must-hotel-booking')
                    : __('Disabled from Booking Rules.', 'must-hotel-booking'),
            ],
            [
                'label' => __('Hidden honeypot', 'must-hotel-booking'),
                'status' => !empty($antiAbuse['honeypot_enabled']) ? 'ok' : 'warning',
                'text' => !empty($antiAbuse['honeypot_enabled'])
                    ? __('Enabled on public submit forms.', 'must-hotel-booking')
                    : __('Disabled.', 'must-hotel-booking'),
            ],
            [
                'label' => __('Minimum submit time', 'must-hotel-booking'),
                'status' => !empty($antiAbuse['minimum_submit_enabled']) ? 'ok' : 'warning',
                'text' => !empty($antiAbuse['minimum_submit_enabled'])
                    ? \sprintf(__('Minimum submit time: %d seconds.', 'must-hotel-booking'), (int) ($antiAbuse['minimum_submit_seconds'] ?? 0))
                    : __('Disabled.', 'must-hotel-booking'),
            ],
            [
                'label' => __('Throttling', 'must-hotel-booking'),
                'status' => !empty($antiAbuse['throttle_enabled']) ? 'ok' : 'warning',
                'text' => !empty($antiAbuse['throttle_enabled'])
                    ? \sprintf(__('Max %1$d attempts in %2$d minutes. Temporary block: %3$d minutes.', 'must-hotel-booking'), (int) ($antiAbuse['max_attempts'] ?? 0), (int) ($antiAbuse['window_minutes'] ?? 0), (int) ($antiAbuse['block_duration_minutes'] ?? 0))
                    : __('Disabled.', 'must-hotel-booking'),
            ],
            [
                'label' => __('Abuse logging', 'must-hotel-booking'),
                'status' => !empty($antiAbuse['logging_enabled']) ? 'ok' : 'warning',
                'text' => !empty($antiAbuse['logging_enabled'])
                    ? __('Blocked attempts are written to the activity log.', 'must-hotel-booking')
                    : __('Blocked attempts are not currently recorded in diagnostics.', 'must-hotel-booking'),
            ],
        ];

        foreach ($protectionItems as $item) {
            echo '<div class="must-dashboard-health-item"><div class="must-dashboard-health-header"><strong>' . \esc_html((string) $item['label']) . '</strong>';
            self::renderBadge((string) $item['status']);
            echo '</div><p>' . \esc_html((string) $item['text']) . '</p></div>';
        }

        echo '</div></div></div>';
        self::renderPanelEnd();
        self::renderDangerZoneSection(
            isset($form['dangerous_reset']) && \is_array($form['dangerous_reset'])
                ? $form['dangerous_reset']
                : DangerousResetService::getFormState()
        );
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function renderDangerZoneSection(array $form): void
    {
        if (!DangerousResetService::canCurrentUserAccess()) {
            return;
        }

        self::renderPanelStart(
            \__('Danger Zone', 'must-hotel-booking'),
            \__('Restricted destructive tools for full administrators only. Both actions are irreversible and require typed confirmation plus the current WordPress password.', 'must-hotel-booking')
        );
        echo '<div class="must-danger-zone-intro">';
        echo '<p>' . \esc_html__('Use these actions only when you intentionally need to wipe live booking data or return the plugin to a near first-install state. Neither action recreates demo data.', 'must-hotel-booking') . '</p>';
        echo '</div>';
        echo '<div class="must-danger-zone-grid">';

        foreach (DangerousResetService::getDefinitions() as $target => $definition) {
            if (!\is_array($definition)) {
                continue;
            }

            self::renderDangerousResetCard($target, $definition, $form);
        }

        echo '</div>';
        self::renderPanelEnd();
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $form
     */
    private static function renderDangerousResetCard(string $target, array $definition, array $form): void
    {
        $values = isset($form['values'][$target]) && \is_array($form['values'][$target]) ? $form['values'][$target] : [];
        $isOpen = (string) ($form['active_target'] ?? '') === $target;
        $isFactory = $target === DangerousResetService::TARGET_FACTORY;
        $toneClass = $isFactory
            ? 'must-danger-zone-card--factory'
            : 'must-danger-zone-card--operational';
        $submitClass = $isFactory
            ? 'button must-danger-zone-submit must-danger-zone-submit--factory'
            : 'button must-danger-zone-submit must-danger-zone-submit--operational';

        echo '<details class="must-danger-zone-card ' . \esc_attr($toneClass) . '"' . ($isOpen ? ' open' : '') . '>';
        echo '<summary class="must-danger-zone-summary">';
        echo '<div class="must-danger-zone-summary-copy">';
        echo '<strong>' . \esc_html((string) ($definition['label'] ?? $target)) . '</strong>';
        if ($isFactory) {
            echo '<span class="must-danger-zone-risk">' . \esc_html__('Highest risk', 'must-hotel-booking') . '</span>';
        }
        echo '<span>' . \esc_html((string) ($definition['summary'] ?? '')) . '</span>';
        echo '</div>';
        echo '<span class="must-danger-zone-summary-toggle">' . \esc_html__('Review confirmation', 'must-hotel-booking') . '</span>';
        echo '</summary>';
        echo '<div class="must-danger-zone-body">';
        echo '<p class="must-danger-zone-warning">' . \esc_html((string) ($definition['warning'] ?? '')) . '</p>';
        self::renderDangerousResetItemList(\__('Deletes', 'must-hotel-booking'), (array) ($definition['delete_items'] ?? []));
        self::renderDangerousResetItemList(\__('Preserves', 'must-hotel-booking'), (array) ($definition['preserve_items'] ?? []));
        echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'maintenance'])) . '" class="must-danger-zone-form">';
        \wp_nonce_field((string) ($definition['nonce_action'] ?? ''), 'must_settings_nonce');
        echo '<input type="hidden" name="must_settings_action" value="dangerous_reset_action" />';
        echo '<input type="hidden" name="dangerous_reset_target" value="' . \esc_attr($target) . '" />';
        echo '<label for="must-danger-target-' . \esc_attr($target) . '"><span>' . \esc_html__('Select reset target', 'must-hotel-booking') . '</span>';
        echo '<select id="must-danger-target-' . \esc_attr($target) . '" name="dangerous_reset_target_selection" required>';
        echo '<option value="">' . \esc_html__('Choose one...', 'must-hotel-booking') . '</option>';
        echo '<option value="' . \esc_attr($target) . '"' . \selected((string) ($values['selected_target'] ?? ''), $target, false) . '>' . \esc_html((string) ($definition['target_label'] ?? $target)) . '</option>';
        echo '</select></label>';
        echo '<label for="must-danger-phrase-' . \esc_attr($target) . '"><span>' . \esc_html__('Type the confirmation phrase', 'must-hotel-booking') . '</span>';
        echo '<input id="must-danger-phrase-' . \esc_attr($target) . '" type="text" name="dangerous_reset_confirmation_phrase" value="' . \esc_attr((string) ($values['confirmation_phrase'] ?? '')) . '" spellcheck="false" autocapitalize="off" autocomplete="off" required /></label>';
        echo '<p class="description">' . \esc_html__('Required phrase:', 'must-hotel-booking') . ' <code>' . \esc_html((string) ($definition['confirmation_phrase'] ?? '')) . '</code></p>';
        echo '<label for="must-danger-password-' . \esc_attr($target) . '"><span>' . \esc_html__('Current WordPress password', 'must-hotel-booking') . '</span>';
        echo '<input id="must-danger-password-' . \esc_attr($target) . '" type="password" name="dangerous_reset_password" value="" autocomplete="current-password" required /></label>';
        echo '<label class="must-danger-zone-checkbox"><input type="checkbox" name="dangerous_reset_acknowledge" value="1"' . \checked(!empty($values['acknowledged']), true, false) . ' required /> <span>' . \esc_html__('I understand this action cannot be undone.', 'must-hotel-booking') . '</span></label>';
        echo '<button type="submit" class="' . \esc_attr($submitClass) . '">' . \esc_html((string) ($definition['submit_label'] ?? \__('Run reset', 'must-hotel-booking'))) . '</button>';
        echo '</form>';
        echo '</div>';
        echo '</details>';
    }

    /**
     * @param array<int|string, mixed> $items
     */
    private static function renderDangerousResetItemList(string $label, array $items): void
    {
        if (empty($items)) {
            return;
        }

        echo '<div class="must-danger-zone-list-block">';
        echo '<h4>' . \esc_html($label) . '</h4>';
        echo '<ul class="must-danger-zone-list">';

        foreach ($items as $item) {
            echo '<li>' . \esc_html((string) $item) . '</li>';
        }

        echo '</ul>';
        echo '</div>';
    }

    /**
     * @return array<int|string, string>
     */
    private static function getPageSelectOptions(): array
    {
        $options = [0 => __('Not assigned', 'must-hotel-booking')];

        foreach (\get_pages(['sort_column' => 'menu_order,post_title', 'sort_order' => 'ASC']) as $page) {
            if (!$page instanceof \WP_Post) {
                continue;
            }

            $suffix = $page->post_status !== 'publish' ? ' (' . \ucfirst((string) $page->post_status) . ')' : '';
            $options[(int) $page->ID] = (string) $page->post_title . $suffix;
        }

        return $options;
    }
}
