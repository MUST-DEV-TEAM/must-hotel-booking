<?php
namespace MustHotelBooking\Core;
use MustHotelBooking\Admin\SettingsDiagnostics;
use MustHotelBooking\Provider\Clock\ClockConfig;
use MustHotelBooking\Provider\ProviderManager;
final class SupportDiagnosticsEndpoint
{
    private const OPTION_NAME = 'must_hotel_booking_support_diagnostics';
    private const REST_NAMESPACE = 'must-support/v1';
    private const REST_ROUTE = '/health';
    private const LEGACY_STATIC_SUBDIR = 'must-support';
    private const LEGACY_STATIC_FILENAME_PREFIX = 'health-';
    private const LEGACY_STATIC_REFRESH_CRON_HOOK = 'must_support_diagnostics_refresh_static_report';
    public static function registerHooks(): void
    {
        \add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        \add_action('admin_post_must_support_diagnostics_settings', [self::class, 'handleSettingsPost']);
        \add_action('init', [self::class, 'cleanupLegacyStaticArtifacts'], 20);
    }
    public static function registerRestRoutes(): void
    {
        \register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [self::class, 'handleHealthRequest'],
                'permission_callback' => '__return_true',
            ]
        );
    }
    public static function handleHealthRequest(\WP_REST_Request $request)
    {
        self::sendNoCacheHeaders();
        $settings = self::getSettings();
        if (empty($settings['enabled'])) {
            return self::notFoundResponse();
        }
        $providedToken = (string) $request->get_param('token');
        $storedToken = (string) ($settings['token'] ?? '');
        if ($storedToken === '' || $providedToken === '' || !\hash_equals($storedToken, $providedToken)) {
            return self::notFoundResponse();
        }
        $response = \rest_ensure_response(self::buildReport($settings));
        if ($response instanceof \WP_REST_Response) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            $response->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        return $response;
    }
    public static function handleSettingsPost(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die(\esc_html__('You do not have permission to manage support diagnostics.', 'must-hotel-booking'));
        }
        \check_admin_referer('must_support_diagnostics_settings', 'must_support_diagnostics_nonce');
        $settings = self::getSettings();
        $action = isset($_POST['support_diagnostics_action']) && !\is_array($_POST['support_diagnostics_action'])
            ? \sanitize_key((string) \wp_unslash($_POST['support_diagnostics_action']))
            : 'save';
        $settings['include_logs'] = !empty($_POST['support_diagnostics_include_logs']);
        $settings['log_limit'] = isset($_POST['support_diagnostics_log_limit'])
            ? self::normalizeLogLimit((int) \absint(\wp_unslash($_POST['support_diagnostics_log_limit'])))
            : 25;
        if ($action === 'enable') {
            $settings['enabled'] = true;
            if ((string) ($settings['token'] ?? '') === '') {
                $settings['token'] = self::generateToken();
            }
        } elseif ($action === 'disable') {
            $settings['enabled'] = false;
        } elseif ($action === 'regenerate') {
            $settings['enabled'] = true;
            $settings['token'] = self::generateToken();
        } elseif ($action === 'clear') {
            $settings['enabled'] = false;
            $settings['token'] = '';
        }
        $settings['updated_at'] = \current_time('mysql');
        \update_option(self::OPTION_NAME, self::sanitizeSettings($settings), false);
        \wp_safe_redirect(
            \admin_url('admin.php?page=must-hotel-booking-settings&tab=maintenance&support_diagnostics_saved=1')
        );
        exit;
    }
    public static function renderSettingsCard(): void
    {
        if (!\current_user_can('manage_options')) {
            return;
        }
        $settings = self::getSettings();
        $enabled = !empty($settings['enabled']);
        $token = (string) ($settings['token'] ?? '');
        $endpointUrl = $token !== '' ? self::getEndpointUrl($token) : '';
        $logLimit = self::normalizeLogLimit((int) ($settings['log_limit'] ?? 25));
        echo '<section class="postbox must-dashboard-panel must-settings-panel">';
        echo '<div class="must-dashboard-panel-inner">';
        echo '<div class="must-dashboard-panel-heading">';
        echo '<div>';
        echo '<h2>' . \esc_html__('MUST Support Diagnostics', 'must-hotel-booking') . '</h2>';
        echo '<p>' . \esc_html__('Enable a temporary, token-protected, read-only JSON support report for debugging live Clock, Stripe, cron, portal, payment, refund, and plugin health without exposing secrets.', 'must-hotel-booking') . '</p>';
        echo '</div>';
        echo '<span class="must-dashboard-status-badge ' . \esc_attr($enabled ? 'is-ok' : 'is-info') . '">';
        echo \esc_html($enabled ? __('Enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking'));
        echo '</span>';
        echo '</div>';
        if (isset($_GET['support_diagnostics_saved'])) {
            echo '<div class="notice notice-success inline"><p>' . \esc_html__('Support diagnostics settings saved.', 'must-hotel-booking') . '</p></div>';
        }
        echo '<form method="post" action="' . \esc_url(\admin_url('admin-post.php')) . '" class="must-settings-form">';
        \wp_nonce_field('must_support_diagnostics_settings', 'must_support_diagnostics_nonce');
        echo '<input type="hidden" name="action" value="must_support_diagnostics_settings" />';
        echo '<div class="must-settings-grid must-settings-grid--2">';
        echo '<div class="must-settings-field">';
        echo '<label>' . \esc_html__('Endpoint status', 'must-hotel-booking') . '</label>';
        echo '<p class="description">' . \esc_html($enabled ? __('The diagnostics URL is currently available to anyone with the token.', 'must-hotel-booking') : __('The diagnostics URL is disabled and returns not found.', 'must-hotel-booking')) . '</p>';
        echo '</div>';
        echo '<div class="must-settings-field">';
        echo '<label>' . \esc_html__('Recent logs limit', 'must-hotel-booking') . '</label>';
        echo '<select name="support_diagnostics_log_limit">';
        foreach ([10, 25, 50, 100] as $limit) {
            echo '<option value="' . \esc_attr((string) $limit) . '"' . \selected($logLimit, $limit, false) . '>' . \esc_html((string) $limit) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . \esc_html__('Maximum recent safe rows to include from operational tables.', 'must-hotel-booking') . '</p>';
        echo '</div>';
        echo '<label class="must-settings-toggle">';
        echo '<input type="checkbox" name="support_diagnostics_include_logs" value="1"' . \checked(!empty($settings['include_logs']), true, false) . ' />';
        echo '<span><strong>' . \esc_html__('Include recent sanitized logs', 'must-hotel-booking') . '</strong>';
        echo '<small>' . \esc_html__('Only safe summary fields are included. Secrets, tokens, emails, phone numbers, raw payloads, and request bodies are removed.', 'must-hotel-booking') . '</small></span>';
        echo '</label>';
        echo '</div>';
        if ($endpointUrl !== '') {
            echo '<div class="must-settings-field">';
            echo '<label for="must-support-diagnostics-url">' . \esc_html__('Support diagnostics URL', 'must-hotel-booking') . '</label>';
            echo '<input id="must-support-diagnostics-url" type="text" readonly value="' . \esc_attr($endpointUrl) . '" onclick="this.select();" />';
            echo '<p class="description">' . \esc_html__('Open this URL, copy the JSON, and send it to support. Disable or regenerate the token when finished.', 'must-hotel-booking') . '</p>';
            echo '</div>';
        } else {
            echo '<p class="description">' . \esc_html__('Enable diagnostics to generate a support URL.', 'must-hotel-booking') . '</p>';
        }
        echo '<div class="must-dashboard-action-strip">';
        echo '<span class="must-dashboard-action-strip-label">' . \esc_html__('Actions', 'must-hotel-booking') . '</span>';
        if ($enabled) {
            echo '<button type="submit" name="support_diagnostics_action" value="save" class="button">' . \esc_html__('Save Settings', 'must-hotel-booking') . '</button>';
            echo '<button type="submit" name="support_diagnostics_action" value="regenerate" class="button">' . \esc_html__('Regenerate Token', 'must-hotel-booking') . '</button>';
            echo '<button type="submit" name="support_diagnostics_action" value="disable" class="button button-secondary">' . \esc_html__('Disable Diagnostics', 'must-hotel-booking') . '</button>';
            echo '<button type="submit" name="support_diagnostics_action" value="clear" class="button button-link-delete">' . \esc_html__('Disable and Clear Token', 'must-hotel-booking') . '</button>';
        } else {
            echo '<button type="submit" name="support_diagnostics_action" value="enable" class="button button-primary">' . \esc_html__('Enable and Generate URL', 'must-hotel-booking') . '</button>';
            echo '<button type="submit" name="support_diagnostics_action" value="save" class="button">' . \esc_html__('Save Settings', 'must-hotel-booking') . '</button>';
        }
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</section>';
    }
    /**
     * @return array<string, mixed>
     */
    private static function buildReport(array $settings): array
    {
        $diagnostics = SettingsDiagnostics::getData();
        $clockSummary = ClockConfig::summary();
        $clockRequestSummary = self::getClockRequestSummary($diagnostics);
        $phase1TrialSummary = self::getPhase1TrialSummary($diagnostics, $clockSummary, $clockRequestSummary);
        $report = [
            'success' => true,
            'generated_at' => \current_time('mysql'),
            'endpoint' => [
                'enabled' => true,
                'include_logs' => !empty($settings['include_logs']),
                'log_limit' => self::normalizeLogLimit((int) ($settings['log_limit'] ?? 25)),
            ],
            'environment' => self::getEnvironmentSummary(),
            'critical_findings' => self::getCriticalFindings($diagnostics, $phase1TrialSummary),
            'provider' => [
                'configured_mode' => ProviderManager::configuredKey(),
                'active_mode' => ProviderManager::activeKey(),
                'clock_summary' => $clockSummary,
            ],
            'diagnostics' => self::pickDiagnostics($diagnostics),
            'cron_statuses' => self::getPluginCronStatuses(),
            'refund_summary' => self::getRefundSummary(),
            'clock_request_summary' => $clockRequestSummary,
            'phase1_trial_summary' => $phase1TrialSummary,
        ];
        if (!empty($settings['include_logs'])) {
            $report['recent_logs'] = self::getRecentSafeLogs(self::normalizeLogLimit((int) ($settings['log_limit'] ?? 25)));
        }
        return self::sanitizeReport($report);
    }
    /**
     * @return array<string, mixed>
     */
    private static function getEnvironmentSummary(): array
    {
        $theme = \wp_get_theme();
        return [
            'plugin_version' => \defined('MUST_HOTEL_BOOKING_VERSION') ? MUST_HOTEL_BOOKING_VERSION : '',
            'wp_version' => \get_bloginfo('version'),
            'php_version' => \PHP_VERSION,
            'site_url' => \site_url(),
            'home_url' => \home_url(),
            'is_ssl' => \is_ssl(),
            'timezone' => \wp_timezone_string(),
            'current_time' => \current_time('mysql'),
            'active_theme' => $theme->exists() ? $theme->get('Name') . ' ' . $theme->get('Version') : '',
            'multisite' => \is_multisite(),
            'wp_debug' => \defined('WP_DEBUG') && WP_DEBUG,
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function pickDiagnostics(array $diagnostics): array
    {
        return [
            'environment' => isset($diagnostics['environment']) && \is_array($diagnostics['environment']) ? $diagnostics['environment'] : [],
            'tables' => isset($diagnostics['tables']) && \is_array($diagnostics['tables']) ? $diagnostics['tables'] : [],
            'pages' => isset($diagnostics['pages']) && \is_array($diagnostics['pages']) ? $diagnostics['pages'] : [],
            'cron' => isset($diagnostics['cron']) && \is_array($diagnostics['cron']) ? $diagnostics['cron'] : [],
            'payments' => isset($diagnostics['payments']) && \is_array($diagnostics['payments']) ? $diagnostics['payments'] : [],
            'emails' => isset($diagnostics['emails']) && \is_array($diagnostics['emails']) ? $diagnostics['emails'] : [],
            'provider' => isset($diagnostics['provider']) && \is_array($diagnostics['provider']) ? $diagnostics['provider'] : [],
            'updater' => isset($diagnostics['updater']) && \is_array($diagnostics['updater']) ? $diagnostics['updater'] : [],
            'summary' => [
                'critical_issues' => (int) ($diagnostics['critical_issues'] ?? 0),
                'warnings' => (int) ($diagnostics['warnings'] ?? 0),
            ],
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @param array<string, mixed> $phase1TrialSummary
     * @return array<int, string>
     */
    private static function getCriticalFindings(array $diagnostics, array $phase1TrialSummary = []): array
    {
        $findings = [];
        $cron = isset($diagnostics['cron']) && \is_array($diagnostics['cron']) ? $diagnostics['cron'] : [];
        if ((string) ($cron['health'] ?? '') !== 'ok') {
            $message = (string) ($cron['message'] ?? '');
            $findings[] = $message !== '' ? $message : 'A required plugin cron job is not healthy.';
        }
        $provider = isset($diagnostics['provider']) && \is_array($diagnostics['provider']) ? $diagnostics['provider'] : [];
        if (isset($provider['request_logs']) && \is_array($provider['request_logs'])) {
            $outbound = isset($provider['request_logs']['outbound_summary']) && \is_array($provider['request_logs']['outbound_summary'])
                ? $provider['request_logs']['outbound_summary']
                : [];
            $lastError = (string) ($outbound['last_error'] ?? '');
            $lastOperation = (string) ($outbound['last_error_operation'] ?? '');
            $isKnownLocalOnlyPaymentNotice =
                $lastOperation === 'clock.reservation_payment_update'
                && self::isKnownLocalOnlyPaymentStatusMessage($lastError)
                && (string) ($phase1TrialSummary['clock_payment_sync_mode'] ?? '') === 'local_only'
                && empty($phase1TrialSummary['new_clock_payment_update_failure_detected']);
            if ($lastError !== '' && !$isKnownLocalOnlyPaymentNotice) {
                $findings[] = $lastError;
            }
        }
        $refundSummary = self::getRefundSummary();
        if ((int) ($refundSummary['manual_review'] ?? 0) > 0) {
            $findings[] = \sprintf(
                '%d refund(s) require manual Clock review.',
                (int) $refundSummary['manual_review']
            );
        }
        if (empty($findings)) {
            $findings[] = 'No critical support findings detected by this report.';
        }
        return \array_values(\array_unique($findings));
    }
    /**
     * @return array<string, mixed>
     */
    private static function getPluginCronStatuses(): array
    {
        $crons = \_get_cron_array();
        if (!\is_array($crons)) {
            $crons = [];
        }
        $pluginHooks = [];
        foreach ($crons as $timestamp => $hooks) {
            if (!\is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                if (!\is_string($hook)) {
                    continue;
                }
                if (\strpos($hook, 'must_') === false && \strpos($hook, 'mhb_') === false && \strpos($hook, 'hotel_booking') === false) {
                    continue;
                }
                if (!isset($pluginHooks[$hook])) {
                    $pluginHooks[$hook] = [
                        'hook' => $hook,
                        'next_run_timestamp' => (int) $timestamp,
                        'next_run' => \wp_date('Y-m-d H:i:s', (int) $timestamp),
                    ];
                }
            }
        }
        \ksort($pluginHooks);
        return [
            'total_plugin_crons' => \count($pluginHooks),
            'items' => \array_values($pluginHooks),
        ];
    }
    /**
     * @return array<string, mixed>
     */
    private static function getRefundSummary(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'must_refunds';
        if (!self::tableExists($table)) {
            return [
                'table_exists' => false,
                'total' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'manual_review' => 0,
                'latest_error' => '',
            ];
        }
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $succeeded = self::columnExists($table, 'status')
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status = 'succeeded'")
            : 0;
        $failed = self::columnExists($table, 'status')
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status IN ('failed', 'error')")
            : 0;
        $manualReview = self::columnExists($table, 'clock_sync_status')
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE clock_sync_status = 'manual_review'")
            : 0;
        $latestError = self::columnExists($table, 'error_message')
            ? (string) $wpdb->get_var("SELECT error_message FROM `{$table}` WHERE error_message <> '' ORDER BY id DESC LIMIT 1")
            : '';
        return [
            'table_exists' => true,
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'manual_review' => $manualReview,
            'latest_error' => $latestError,
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function getClockRequestSummary(array $diagnostics): array
    {
        $provider = isset($diagnostics['provider']) && \is_array($diagnostics['provider']) ? $diagnostics['provider'] : [];
        $requestLogs = isset($provider['request_logs']) && \is_array($provider['request_logs']) ? $provider['request_logs'] : [];
        $outbound = isset($requestLogs['outbound_summary']) && \is_array($requestLogs['outbound_summary']) ? $requestLogs['outbound_summary'] : [];
        return [
            'total' => (int) ($outbound['total'] ?? 0),
            'successful' => (int) ($outbound['successful'] ?? 0),
            'failed' => (int) ($outbound['failed'] ?? 0),
            'last_error' => (string) ($outbound['last_error'] ?? ''),
            'last_error_operation' => (string) ($outbound['last_error_operation'] ?? ''),
            'last_error_http_status' => (int) ($outbound['last_error_http_status'] ?? 0),
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @param array<string, mixed> $clockSummary
     * @param array<string, mixed> $clockRequestSummary
     * @return array<string, mixed>
     */
    private static function getPhase1TrialSummary(array $diagnostics, array $clockSummary, array $clockRequestSummary): array
    {
        $latest = self::getLatestPaidReservationTrialRow();
        $lockCleanupCronScheduled = self::isCronHookScheduled('must_hotel_booking_cleanup_expired_locks');
        $providerSyncCronScheduled = self::isCronHookScheduled('must_hotel_booking_process_provider_sync_jobs');
        $clockAutoSyncCronScheduled = self::isCronHookScheduled('must_hotel_booking_clock_auto_reservation_sync');
        if (empty($latest)) {
            return [
                'latest_paid_reservation_id' => 0,
                'latest_booking_reference' => '',
                'latest_payment_id' => 0,
                'latest_payment_status' => '',
                'latest_payment_paid_at' => '',
                'latest_reservation_status' => '',
                'latest_reservation_payment_status' => '',
                'booking_created_locally' => false,
                'booking_created_in_clock' => false,
                'stripe_payment_paid' => false,
                'local_reservation_confirmed' => false,
                'clock_payment_sync_mode' => (string) ($clockSummary['clock_payment_status_sync_mode'] ?? ''),
                'new_clock_payment_update_failure_detected' => false,
                'lock_cleanup_cron_scheduled' => $lockCleanupCronScheduled,
                'provider_sync_cron_scheduled' => $providerSyncCronScheduled,
                'clock_auto_sync_cron_scheduled' => $clockAutoSyncCronScheduled,
                'phase1a_passed' => $lockCleanupCronScheduled && $providerSyncCronScheduled && $clockAutoSyncCronScheduled,
                'phase1b_passed' => false,
                'overall_status' => 'no_paid_trial_found',
                'known_local_only_payment_sync_notice' => self::knownLocalOnlyPaymentNotice($clockSummary, $clockRequestSummary),
                'notes' => [
                    'No paid reservation was found to validate Phase 1B automatically.',
                ],
            ];
        }
        $reservationId = (int) ($latest['reservation_id'] ?? 0);
        $paymentId = (int) ($latest['payment_id'] ?? 0);
        $bookingReference = (string) ($latest['booking_reference'] ?? '');
        $paymentStatus = (string) ($latest['payment_status'] ?? '');
        $paymentPaidAt = (string) ($latest['payment_paid_at'] ?? '');
        $paymentCreatedAt = (string) ($latest['payment_created_at'] ?? '');
        $reservationStatus = (string) ($latest['reservation_status'] ?? '');
        $reservationPaymentStatus = (string) ($latest['reservation_payment_status'] ?? '');
        $provider = (string) ($latest['provider'] ?? '');
        $providerBookingId = (string) ($latest['provider_booking_id'] ?? '');
        $providerReservationId = (string) ($latest['provider_reservation_id'] ?? '');
        $reservationCreatedAt = (string) ($latest['reservation_created_at'] ?? '');
        $bookingCreatedLocally = $reservationId > 0 && $bookingReference !== '';
        $stripePaymentPaid = $paymentId > 0 && $paymentStatus === 'paid' && $paymentPaidAt !== '';
        $localReservationConfirmed = \in_array($reservationStatus, ['confirmed', 'completed'], true)
            && $reservationPaymentStatus === 'paid';
        $hasClockProviderId = $provider === ProviderManager::CLOCK_MODE
            && ($providerBookingId !== '' || $providerReservationId !== '');
        $clockReservationCreateSucceeded = self::hasSuccessfulClockReservationCreateNear($reservationCreatedAt, $paymentPaidAt);
        $bookingCreatedInClock = $hasClockProviderId && $clockReservationCreateSucceeded;
        $newClockPaymentUpdateFailureDetected = self::hasNewClockPaymentUpdateFailureAfter(
            $paymentCreatedAt !== '' ? $paymentCreatedAt : $paymentPaidAt
        );
        $clockPaymentSyncMode = (string) ($clockSummary['clock_payment_status_sync_mode'] ?? '');
        $phase1aPassed = $lockCleanupCronScheduled && $providerSyncCronScheduled && $clockAutoSyncCronScheduled;
        $phase1bPassed =
            $bookingCreatedLocally
            && $bookingCreatedInClock
            && $stripePaymentPaid
            && $localReservationConfirmed
            && !$newClockPaymentUpdateFailureDetected
            && ($clockPaymentSyncMode === 'local_only' || $clockPaymentSyncMode === 'provider_update');
        $notes = [];
        if ($phase1aPassed) {
            $notes[] = 'Phase 1A passed: required plugin cron jobs are scheduled.';
        } else {
            $notes[] = 'Phase 1A failed: one or more required plugin cron jobs are missing.';
        }
        if ($phase1bPassed) {
            $notes[] = 'Phase 1B passed: latest paid Stripe booking is confirmed locally, exists in Clock, and did not create a new Clock payment-update failure.';
        } else {
            $notes[] = 'Phase 1B did not pass automatically. Check the boolean fields in this section.';
        }
        return [
            'latest_paid_reservation_id' => $reservationId,
            'latest_booking_reference' => $bookingReference,
            'latest_payment_id' => $paymentId,
            'latest_payment_status' => $paymentStatus,
            'latest_payment_paid_at' => $paymentPaidAt,
            'latest_reservation_status' => $reservationStatus,
            'latest_reservation_payment_status' => $reservationPaymentStatus,
            'booking_created_locally' => $bookingCreatedLocally,
            'booking_created_in_clock' => $bookingCreatedInClock,
            'stripe_payment_paid' => $stripePaymentPaid,
            'local_reservation_confirmed' => $localReservationConfirmed,
            'clock_payment_sync_mode' => $clockPaymentSyncMode,
            'new_clock_payment_update_failure_detected' => $newClockPaymentUpdateFailureDetected,
            'lock_cleanup_cron_scheduled' => $lockCleanupCronScheduled,
            'provider_sync_cron_scheduled' => $providerSyncCronScheduled,
            'clock_auto_sync_cron_scheduled' => $clockAutoSyncCronScheduled,
            'phase1a_passed' => $phase1aPassed,
            'phase1b_passed' => $phase1bPassed,
            'overall_status' => $phase1aPassed && $phase1bPassed ? 'passed' : 'needs_review',
            'known_local_only_payment_sync_notice' => self::knownLocalOnlyPaymentNotice($clockSummary, $clockRequestSummary),
            'notes' => $notes,
        ];
    }
    /**
     * @return array<string, mixed>
     */
    private static function getLatestPaidReservationTrialRow(): array
    {
        global $wpdb;
        $paymentsTable = $wpdb->prefix . 'must_payments';
        $reservationsTable = $wpdb->prefix . 'must_reservations';
        if (
            !self::tableExists($paymentsTable)
            || !self::tableExists($reservationsTable)
            || !self::columnExists($paymentsTable, 'id')
            || !self::columnExists($paymentsTable, 'reservation_id')
            || !self::columnExists($paymentsTable, 'status')
            || !self::columnExists($reservationsTable, 'id')
        ) {
            return [];
        }
        $paidAtSelect = self::columnExists($paymentsTable, 'paid_at') ? 'p.paid_at' : "''";
        $paymentCreatedSelect = self::columnExists($paymentsTable, 'created_at') ? 'p.created_at' : "''";
        $reservationCreatedSelect = self::columnExists($reservationsTable, 'created_at') ? 'r.created_at' : "''";
        $bookingReferenceSelect = self::columnExists($reservationsTable, 'booking_id') ? 'r.booking_id' : "''";
        $reservationStatusSelect = self::columnExists($reservationsTable, 'status') ? 'r.status' : "''";
        $reservationPaymentStatusSelect = self::columnExists($reservationsTable, 'payment_status') ? 'r.payment_status' : "''";
        $providerSelect = self::columnExists($reservationsTable, 'provider') ? 'r.provider' : "''";
        $providerBookingIdSelect = self::columnExists($reservationsTable, 'provider_booking_id') ? 'r.provider_booking_id' : "''";
        $providerReservationIdSelect = self::columnExists($reservationsTable, 'provider_reservation_id') ? 'r.provider_reservation_id' : "''";
        $row = $wpdb->get_row(
            "SELECT
            p.id AS payment_id,
            p.reservation_id AS reservation_id,
            p.status AS payment_status,
            {$paidAtSelect} AS payment_paid_at,
            {$paymentCreatedSelect} AS payment_created_at,
            r.id AS local_reservation_id,
            {$bookingReferenceSelect} AS booking_reference,
            {$reservationStatusSelect} AS reservation_status,
            {$reservationPaymentStatusSelect} AS reservation_payment_status,
            {$providerSelect} AS provider,
            {$providerBookingIdSelect} AS provider_booking_id,
            {$providerReservationIdSelect} AS provider_reservation_id,
            {$reservationCreatedSelect} AS reservation_created_at
        FROM `{$paymentsTable}` p
        INNER JOIN `{$reservationsTable}` r ON r.id = p.reservation_id
        WHERE p.status = 'paid'
        ORDER BY p.id DESC
        LIMIT 1",
            ARRAY_A
        );
        return \is_array($row) ? $row : [];
    }
    private static function hasSuccessfulClockReservationCreateNear(string $reservationCreatedAt, string $paymentPaidAt): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhb_provider_request_logs';
        if (
            !self::tableExists($table)
            || !self::columnExists($table, 'operation')
            || !self::columnExists($table, 'created_at')
        ) {
            return false;
        }
        $windowStart = $reservationCreatedAt !== '' ? $reservationCreatedAt : $paymentPaidAt;
        $windowEnd = $paymentPaidAt !== '' ? $paymentPaidAt : $reservationCreatedAt;
        if ($windowStart === '') {
            return false;
        }
        if ($windowEnd === '') {
            $windowEnd = \current_time('mysql');
        }
        $successWhere = '';
        if (self::columnExists($table, 'http_status')) {
            $successWhere .= ' AND http_status >= 200 AND http_status < 300';
        }
        if (self::columnExists($table, 'error_message')) {
            $successWhere .= " AND (error_message IS NULL OR error_message = '')";
        }
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
            FROM `{$table}`
            WHERE operation = %s
            AND created_at >= DATE_SUB(%s, INTERVAL 10 MINUTE)
            AND created_at <= DATE_ADD(%s, INTERVAL 10 MINUTE)
            {$successWhere}",
                'clock.reservation_create',
                $windowStart,
                $windowEnd
            )
        );
        return $count > 0;
    }
    private static function hasNewClockPaymentUpdateFailureAfter(string $timestamp): bool
    {
        global $wpdb;
        if ($timestamp === '') {
            return false;
        }
        $table = $wpdb->prefix . 'mhb_provider_request_logs';
        if (
            !self::tableExists($table)
            || !self::columnExists($table, 'operation')
            || !self::columnExists($table, 'created_at')
        ) {
            return false;
        }
        $failureConditions = [];
        if (self::columnExists($table, 'error_message')) {
            $failureConditions[] = "(error_message IS NOT NULL AND error_message <> '')";
        }
        if (self::columnExists($table, 'http_status')) {
            $failureConditions[] = '(http_status < 200 OR http_status >= 300)';
        }
        if (self::columnExists($table, 'success')) {
            $failureConditions[] = 'success = 0';
        }
        if (empty($failureConditions)) {
            return false;
        }
        $failureSql = '(' . \implode(' OR ', $failureConditions) . ')';
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
            FROM `{$table}`
            WHERE operation = %s
            AND created_at >= %s
            AND {$failureSql}",
                'clock.reservation_payment_update',
                $timestamp
            )
        );
        return $count > 0;
    }
    private static function isCronHookScheduled(string $hook): bool
    {
        return \wp_next_scheduled($hook) !== false;
    }
    /**
     * @param array<string, mixed> $clockSummary
     * @param array<string, mixed> $clockRequestSummary
     */
    private static function knownLocalOnlyPaymentNotice(array $clockSummary, array $clockRequestSummary): string
    {
        $mode = (string) ($clockSummary['clock_payment_status_sync_mode'] ?? '');
        $lastOperation = (string) ($clockRequestSummary['last_error_operation'] ?? '');
        $lastError = (string) ($clockRequestSummary['last_error'] ?? '');
        if (
            $mode === 'local_only'
            && $lastOperation === 'clock.reservation_payment_update'
            && self::isKnownLocalOnlyPaymentStatusMessage($lastError)
        ) {
            return 'Historical Clock payment-update errors are ignored because payment status sync is intentionally local_only.';
        }
        return '';
    }
    private static function isKnownLocalOnlyPaymentStatusMessage(string $message): bool
    {
        $message = \strtolower($message);
        return \strpos($message, 'clock reservation status update endpoint is not configured') !== false
            || \strpos($message, 'clock payment status sync endpoint is not configured') !== false
            || \strpos($message, 'local payment status was recorded only in the mirror reservation') !== false
            || \strpos($message, 'payment status remains local-only') !== false;
    }
    /**
     * @return array<string, mixed>
     */
    private static function getRecentSafeLogs(int $limit): array
    {
        global $wpdb;
        return [
            'activity' => self::getRecentRows($wpdb->prefix . 'must_activity_log', $limit),
            'provider_sync_jobs' => self::getRecentRows($wpdb->prefix . 'mhb_provider_sync_jobs', $limit),
            'provider_request_logs' => self::getRecentRows($wpdb->prefix . 'mhb_provider_request_logs', $limit),
            'payments' => self::getRecentRows($wpdb->prefix . 'must_payments', $limit),
            'refunds' => self::getRecentRows($wpdb->prefix . 'must_refunds', $limit),
        ];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getRecentRows(string $table, int $limit): array
    {
        global $wpdb;
        if (!self::isSafeTableName($table) || !self::tableExists($table) || !self::columnExists($table, 'id')) {
            return [];
        }
        $limit = self::normalizeLogLimit($limit);
        $rows = $wpdb->get_results('SELECT * FROM `' . $table . '` ORDER BY id DESC LIMIT ' . (int) $limit, ARRAY_A);
        if (!\is_array($rows)) {
            return [];
        }
        $safeRows = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $safeRows[] = self::pickSafeRowFields($row);
        }
        return $safeRows;
    }
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function pickSafeRowFields(array $row): array
    {
        $allowedKeys = [
            'id',
            'provider',
            'operation',
            'event_type',
            'severity',
            'entity_type',
            'entity_id',
            'reservation_id',
            'payment_id',
            'refund_id',
            'status',
            'payment_status',
            'sync_status',
            'clock_sync_status',
            'http_status',
            'response_code',
            'duration_ms',
            'attempts',
            'attempt_count',
            'max_attempts',
            'message',
            'error_message',
            'notice',
            'reference',
            'transaction_id',
            'stripe_refund_id',
            'created_at',
            'updated_at',
            'last_attempt_at',
            'next_attempt_at',
            'completed_at',
            'paid_at',
        ];
        $safe = [];
        foreach ($allowedKeys as $key) {
            if (!\array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (\is_array($value) || \is_object($value)) {
                continue;
            }
            $safe[$key] = self::maskSensitiveText((string) $value);
        }
        return $safe;
    }
    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private static function sanitizeReport(array $report): array
    {
        $sanitized = self::sanitizeValue($report);
        return \is_array($sanitized) ? $sanitized : [];
    }
    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeValue($value, string $key = '')
    {
        if ($key !== '' && self::isSensitiveKey($key)) {
            return null;
        }
        if (\is_array($value)) {
            $isList = self::isListArray($value);
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $childKeyString = \is_string($childKey) ? $childKey : (string) $childKey;
                if (!$isList && self::isSensitiveKey($childKeyString)) {
                    continue;
                }
                $childClean = self::sanitizeValue($childValue, $childKeyString);
                if ($isList) {
                    $clean[] = $childClean;
                } else {
                    $clean[$childKeyString] = $childClean;
                }
            }
            return $clean;
        }
        if (\is_bool($value) || \is_int($value) || \is_float($value) || $value === null) {
            return $value;
        }
        return self::maskSensitiveText((string) $value);
    }
    /**
     * @param array<mixed> $array
     */
    private static function isListArray(array $array): bool
    {
        if (\function_exists('array_is_list')) {
            return \array_is_list($array);
        }
        $i = 0;
        foreach (\array_keys($array) as $key) {
            if ($key !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
    private static function isSensitiveKey(string $key): bool
    {
        $key = \strtolower($key);
        $blockedExact = [
            'token',
            'password',
            'secret',
            'authorization',
            'cookie',
            'nonce',
            'raw_body',
            'request_body',
            'response_body',
            'payload',
            'context_json',
            'metadata',
            'email',
            'guest_email',
            'customer_email',
            'sender_email',
            'booking_recipient',
            'phone',
            'guest_phone',
            'customer_phone',
            'mobile',
        ];
        if (\in_array($key, $blockedExact, true)) {
            return true;
        }
        $blockedContains = [
            'access_token',
            'refresh_token',
            'bearer',
            'authorization',
            'cookie',
            'raw_',
            'payload',
            'request_body',
            'response_body',
            'card',
        ];
        foreach ($blockedContains as $needle) {
            if (\strpos($key, $needle) !== false) {
                return true;
            }
        }
        if (\strpos($key, 'api_key') !== false && \substr($key, -4) !== '_set') {
            return true;
        }
        if (\strpos($key, 'webhook_secret') !== false && \substr($key, -4) !== '_set') {
            return true;
        }
        if (\strpos($key, 'secret') !== false && \substr($key, -4) !== '_set') {
            return true;
        }
        return false;
    }
    private static function maskSensitiveText(string $value): string
    {
        $value = \sanitize_text_field($value);
        $value = (string) \preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email-redacted]', $value);
        $value = (string) \preg_replace('/\b(phone|tel|mobile|whatsapp)\s*[:=]\s*\+?\d[\d\s().\-]{7,}\d\b/i', '$1: [phone-redacted]', $value);
        $value = (string) \preg_replace('/(?<![\d:])\+\d[\d\s().\-]{7,}\d(?![\d:])/', '[phone-redacted]', $value);
        $value = (string) \preg_replace('/(sk_live_|sk_test_|whsec_|pk_live_|pk_test_)[A-Za-z0-9_]+/', '[key-redacted]', $value);
        return $value;
    }
    private static function tableExists(string $table): bool
    {
        global $wpdb;
        if (!self::isSafeTableName($table)) {
            return false;
        }
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return (string) $found === $table;
    }
    private static function columnExists(string $table, string $column): bool
    {
        global $wpdb;
        if (!self::isSafeTableName($table) || !\preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
        return (string) $found === $column;
    }
    private static function isSafeTableName(string $table): bool
    {
        return \preg_match('/^[A-Za-z0-9_]+$/', $table) === 1;
    }
    private static function notFoundResponse(): \WP_Error
    {
        return new \WP_Error(
            'must_support_not_found',
            \__('Not found.', 'must-hotel-booking'),
            ['status' => 404]
        );
    }
    /**
     * @return array<string, mixed>
     */
    private static function getSettings(): array
    {
        $raw = \get_option(self::OPTION_NAME, []);
        if (!\is_array($raw)) {
            $raw = [];
        }
        return self::sanitizeSettings($raw);
    }
    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function sanitizeSettings(array $settings): array
    {
        return [
            'enabled' => !empty($settings['enabled']),
            'token' => isset($settings['token']) ? \sanitize_text_field((string) $settings['token']) : '',
            'include_logs' => \array_key_exists('include_logs', $settings) ? !empty($settings['include_logs']) : true,
            'log_limit' => self::normalizeLogLimit((int) ($settings['log_limit'] ?? 25)),
            'updated_at' => isset($settings['updated_at']) ? \sanitize_text_field((string) $settings['updated_at']) : '',
        ];
    }
    private static function normalizeLogLimit(int $limit): int
    {
        if (\in_array($limit, [10, 25, 50, 100], true)) {
            return $limit;
        }
        return 25;
    }
    private static function generateToken(): string
    {
        try {
            return \bin2hex(\random_bytes(32));
        } catch (\Exception $exception) {
            return \wp_generate_password(64, false, false);
        }
    }
    private static function getEndpointUrl(string $token): string
    {
        return \add_query_arg(
            ['token' => $token],
            \rest_url(self::REST_NAMESPACE . self::REST_ROUTE)
        );
    }
    private static function sendNoCacheHeaders(): void
    {
        if (\headers_sent()) {
            return;
        }
        \nocache_headers();
        \header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        \header('Pragma: no-cache');
        \header('Expires: 0');
        \header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
    public static function cleanupLegacyStaticArtifacts(): void
    {
        if ((string) \get_option('must_hotel_booking_support_diagnostics_legacy_cleaned', '') === '1') {
            return;
        }
        while (($timestamp = \wp_next_scheduled(self::LEGACY_STATIC_REFRESH_CRON_HOOK)) !== false) {
            \wp_unschedule_event((int) $timestamp, self::LEGACY_STATIC_REFRESH_CRON_HOOK);
        }
        $uploads = \wp_upload_dir();
        if (empty($uploads['error']) && !empty($uploads['basedir'])) {
            $dir = \trailingslashit((string) $uploads['basedir']) . self::LEGACY_STATIC_SUBDIR;
            if (\is_dir($dir)) {
                $pattern = \trailingslashit($dir) . self::LEGACY_STATIC_FILENAME_PREFIX . '*.json';
                foreach ((array) \glob($pattern) as $file) {
                    if (\is_string($file) && \is_file($file)) {
                        \unlink($file);
                    }
                }
            }
        }
        \update_option('must_hotel_booking_support_diagnostics_legacy_cleaned', '1', false);
    }
}