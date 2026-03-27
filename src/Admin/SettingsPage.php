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
use MustHotelBooking\Portal\PortalRegistry;
use MustHotelBooking\Portal\PortalRouter;

final class SettingsPage
{
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
            'branding' => ['label' => \__('Frontend & Branding', 'must-hotel-booking'), 'icon' => 'dashicons-art'],
            'managed_pages' => ['label' => \__('Managed Pages', 'must-hotel-booking'), 'icon' => 'dashicons-admin-page'],
            'notifications_summary' => ['label' => \__('Notifications & Emails Summary', 'must-hotel-booking'), 'icon' => 'dashicons-email-alt'],
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
        $allowedTasks = ['reinstall_pages', 'reschedule_cron', 'cleanup_expired_locks', 'send_test_email', 'flush_portal_routes', 'repair_inventory_mirror'];

        if (!\in_array($task, $allowedTasks, true)) {
            return [
                'tab' => $tab,
                'notice' => '',
                'errors' => [\__('Unknown maintenance action.', 'must-hotel-booking')],
                'forms' => [],
            ];
        }

        if ($persist) {
            if ($task === 'reinstall_pages') {
                ManagedPages::install();
                PortalRouter::flushRewriteRules();
            } elseif ($task === 'reschedule_cron') {
                LockEngine::unscheduleCleanupCron();
                LockEngine::scheduleCleanupCron();
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

            MustBookingConfig::set_group_settings($group, $values);

            if ($group === 'staff_access') {
                $needsRoleSync = true;
                $needsPageSync = true;
            }

            if ($group === 'managed_pages') {
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
            'branding_saved' => \__('Branding settings saved.', 'must-hotel-booking'),
            'managed_pages_saved' => \__('Managed page assignments saved.', 'must-hotel-booking'),
            'notifications_summary_saved' => \__('Notification and email summary settings saved.', 'must-hotel-booking'),
            'managed_page_repaired' => \__('Managed page action completed.', 'must-hotel-booking'),
            'maintenance_action_completed' => \__('Maintenance action completed.', 'must-hotel-booking'),
        ];

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

        self::renderMaintenanceTab($diagnostics);
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

        self::renderFormStart('payments_summary');
        self::renderWarnings($warnings);
        self::renderPanelStart(\__('Payments Summary', 'must-hotel-booking'), \__('A control surface for high-level payment defaults with shortcuts into the dedicated Payments page.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Enabled methods', 'must-hotel-booking') . '</span><strong>' . \esc_html(!empty($methodNames) ? \implode(', ', $methodNames) : __('No methods enabled', 'must-hotel-booking')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Default payment mode', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($bookingRules['default_payment_mode'] ?? 'guest_choice')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Stripe environment', 'must-hotel-booking') . '</span><strong>' . \esc_html(PaymentEngine::getSiteEnvironmentLabel()) . '</strong></article>';
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
     * @param array<string, mixed> $diagnostics
     */
    private static function renderMaintenanceTab(array $diagnostics): void
    {
        $environment = isset($diagnostics['environment']) && \is_array($diagnostics['environment']) ? $diagnostics['environment'] : [];
        $payments = isset($diagnostics['payments']) && \is_array($diagnostics['payments']) ? $diagnostics['payments'] : [];
        $emails = isset($diagnostics['emails']) && \is_array($diagnostics['emails']) ? $diagnostics['emails'] : [];
        $cron = isset($diagnostics['cron']) && \is_array($diagnostics['cron']) ? $diagnostics['cron'] : [];
        $updater = isset($diagnostics['updater']) && \is_array($diagnostics['updater']) ? $diagnostics['updater'] : [];

        self::renderPanelStart(\__('Diagnostics & Maintenance', 'must-hotel-booking'), \__('Turn system health into direct admin actions for setup repair and release readiness.', 'must-hotel-booking'));
        echo '<div class="must-settings-summary-grid">';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Plugin version', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($environment['plugin_version'] ?? '')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('DB version', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($environment['database_version'] ?? '')) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Rooms', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($environment['room_count'] ?? 0)) . '</strong></article>';
        echo '<article class="must-settings-summary-card"><span class="must-settings-summary-label">' . \esc_html__('Cron', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($cron['next_run'] ?? '')) . '</strong></article>';
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
            ['label' => __('Payment config', 'must-hotel-booking'), 'status' => !empty($payments['stripe_enabled']) && empty($payments['stripe_configured']) ? 'warning' : 'ok', 'text' => !empty($payments['stripe_enabled']) ? __('Stripe is enabled on this site.', 'must-hotel-booking') : __('Stripe is disabled.', 'must-hotel-booking')],
            ['label' => __('Cron / cleanup', 'must-hotel-booking'), 'status' => (string) ($cron['status'] ?? 'warning'), 'text' => (string) ($cron['message'] ?? '')],
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
        foreach (['reinstall_pages' => __('Reinstall missing managed pages', 'must-hotel-booking'), 'flush_portal_routes' => __('Flush portal routes', 'must-hotel-booking'), 'reschedule_cron' => __('Reschedule cleanup cron', 'must-hotel-booking'), 'cleanup_expired_locks' => __('Clear expired locks', 'must-hotel-booking'), 'repair_inventory_mirror' => __('Repair inventory mirror', 'must-hotel-booking'), 'send_test_email' => __('Send test email', 'must-hotel-booking')] as $task => $label) {
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
        self::renderPanelEnd();
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
