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

    private const STATIC_SUBDIR = 'must-support';
    private const STATIC_FILENAME_PREFIX = 'health-';
    private const STATIC_REFRESH_CRON_HOOK = 'must_support_diagnostics_refresh_static_report';
    private const STATIC_REFRESH_CRON_SCHEDULE = 'must_support_every_five_minutes';

    public static function registerHooks(): void
    {
        \add_filter('cron_schedules', [self::class, 'addCronSchedules']);

        \add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        \add_action('init', [self::class, 'maybeHandlePlainHealthRequest'], 1);
        \add_action('init', [self::class, 'maybeScheduleStaticRefreshCron'], 20);

        \add_action(self::STATIC_REFRESH_CRON_HOOK, [self::class, 'refreshStaticReportFromCron']);
        \add_action('admin_post_must_support_diagnostics_settings', [self::class, 'handleSettingsPost']);
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

    public static function maybeHandlePlainHealthRequest(): void
    {
        if (empty($_GET['must_support_health'])) {
            return;
        }

        self::sendNoCacheHeaders();

        $settings = self::getSettings();

        if (empty($settings['enabled'])) {
            self::sendJsonResponse(
                [
                    'success' => false,
                    'message' => 'Not found.',
                ],
                404
            );
        }

        $providedToken = isset($_GET['token']) && !\is_array($_GET['token'])
            ? (string) \wp_unslash($_GET['token'])
            : '';

        $storedToken = (string) ($settings['token'] ?? '');

        if ($storedToken === '' || $providedToken === '' || !\hash_equals($storedToken, $providedToken)) {
            self::sendJsonResponse(
                [
                    'success' => false,
                    'message' => 'Not found.',
                ],
                404
            );
        }

        self::sendJsonResponse(self::buildReport($settings), 200);
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
        } elseif ($action === 'refresh_static') {
            $settings['enabled'] = true;

            if ((string) ($settings['token'] ?? '') === '') {
                $settings['token'] = self::generateToken();
            }
        }

        $settings['updated_at'] = \current_time('mysql');
        $settings = self::sanitizeSettings($settings);

        \update_option(self::OPTION_NAME, $settings, false);

        if (!empty($settings['enabled']) && (string) ($settings['token'] ?? '') !== '') {
            self::writeStaticReportFile($settings);
            self::maybeScheduleStaticRefreshCron();
        } else {
            self::deleteStaticReportFiles();
            self::unscheduleStaticRefreshCron();
        }

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
        $plainEndpointUrl = $token !== '' ? self::getPlainEndpointUrl($token) : '';
        $staticReportUrl = $token !== '' ? self::getStaticReportUrl($token) : '';
        $logLimit = self::normalizeLogLimit((int) ($settings['log_limit'] ?? 25));

        echo '<section class="postbox must-dashboard-panel must-settings-panel">';
        echo '<div class="must-dashboard-panel-inner">';

        echo '<div class="must-dashboard-panel-heading">';
        echo '<div>';
        echo '<h2>' . \esc_html__('MUST Support Diagnostics Endpoint', 'must-hotel-booking') . '</h2>';
        echo '<p>' . \esc_html__('Enable a temporary, token-protected, read-only support report for debugging live Clock, Stripe, cron, portal, and plugin health without exposing secrets.', 'must-hotel-booking') . '</p>';
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
        echo '<p class="description">' . \esc_html($enabled ? __('The endpoint is currently available to anyone with the token.', 'must-hotel-booking') : __('The endpoint is disabled and returns not found.', 'must-hotel-booking')) . '</p>';
        echo '</div>';

        echo '<div class="must-settings-field">';
        echo '<label>' . \esc_html__('Recent logs limit', 'must-hotel-booking') . '</label>';
        echo '<select name="support_diagnostics_log_limit">';
        foreach ([10, 25, 50, 100] as $limit) {
            echo '<option value="' . \esc_attr((string) $limit) . '"' . \selected($logLimit, $limit, false) . '>' . \esc_html((string) $limit) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . \esc_html__('Maximum recent rows to include from safe operational tables.', 'must-hotel-booking') . '</p>';
        echo '</div>';

        echo '<label class="must-settings-toggle">';
        echo '<input type="checkbox" name="support_diagnostics_include_logs" value="1"' . \checked(!empty($settings['include_logs']), true, false) . ' />';
        echo '<span><strong>' . \esc_html__('Include recent sanitized logs', 'must-hotel-booking') . '</strong>';
        echo '<small>' . \esc_html__('Only safe summary fields are included. Secrets, tokens, emails, phone numbers, raw payloads, and request bodies are removed.', 'must-hotel-booking') . '</small></span>';
        echo '</label>';

        echo '</div>';

        if ($endpointUrl !== '') {
            echo '<div class="must-settings-field">';
            echo '<label for="must-support-diagnostics-url">' . \esc_html__('REST support endpoint URL', 'must-hotel-booking') . '</label>';
            echo '<input id="must-support-diagnostics-url" type="text" readonly value="' . \esc_attr($endpointUrl) . '" onclick="this.select();" />';
            echo '<p class="description">' . \esc_html__('Primary REST endpoint. Send this URL only to trusted support.', 'must-hotel-booking') . '</p>';
            echo '</div>';

            echo '<div class="must-settings-field">';
            echo '<label for="must-support-diagnostics-plain-url">' . \esc_html__('Plain support endpoint URL', 'must-hotel-booking') . '</label>';
            echo '<input id="must-support-diagnostics-plain-url" type="text" readonly value="' . \esc_attr($plainEndpointUrl) . '" onclick="this.select();" />';
            echo '<p class="description">' . \esc_html__('Fallback URL that avoids wp-json routing and adds a refresh parameter to reduce caching issues.', 'must-hotel-booking') . '</p>';
            echo '</div>';

            echo '<div class="must-settings-field">';
            echo '<label for="must-support-diagnostics-static-url">' . \esc_html__('Static support report URL', 'must-hotel-booking') . '</label>';
            echo '<input id="must-support-diagnostics-static-url" type="text" readonly value="' . \esc_attr($staticReportUrl) . '" onclick="this.select();" />';
            echo '<p class="description">' . \esc_html__('Best fallback for external support access. This writes a sanitized JSON file in uploads and refreshes it while diagnostics are enabled.', 'must-hotel-booking') . '</p>';
            echo '</div>';
        } else {
            echo '<p class="description">' . \esc_html__('Enable the endpoint to generate a support URL.', 'must-hotel-booking') . '</p>';
        }

        echo '<div class="must-dashboard-action-strip">';
        echo '<span class="must-dashboard-action-strip-label">' . \esc_html__('Actions', 'must-hotel-booking') . '</span>';

        if ($enabled) {
            echo '<button type="submit" name="support_diagnostics_action" value="save" class="button">' . \esc_html__('Save Settings', 'must-hotel-booking') . '</button>';
            echo '<button type="submit" name="support_diagnostics_action" value="refresh_static" class="button">' . \esc_html__('Refresh Static Report', 'must-hotel-booking') . '</button>';
            echo '<button type="submit" name="support_diagnostics_action" value="regenerate" class="button">' . \esc_html__('Regenerate Token', 'must-hotel-booking') . '</button>';
            echo '<button type="submit" name="support_diagnostics_action" value="disable" class="button button-secondary">' . \esc_html__('Disable Endpoint', 'must-hotel-booking') . '</button>';
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

        $report = [
            'success' => true,
            'generated_at' => \current_time('mysql'),
            'endpoint' => [
                'enabled' => true,
                'include_logs' => !empty($settings['include_logs']),
                'log_limit' => self::normalizeLogLimit((int) ($settings['log_limit'] ?? 25)),
            ],
            'environment' => self::getEnvironmentSummary(),
            'critical_findings' => self::getCriticalFindings($diagnostics),
            'provider' => [
                'configured_mode' => ProviderManager::configuredKey(),
                'active_mode' => ProviderManager::activeKey(),
                'clock_summary' => $clockSummary,
            ],
            'diagnostics' => self::pickDiagnostics($diagnostics),
            'cron_statuses' => self::getPluginCronStatuses(),
            'refund_summary' => self::getRefundSummary(),
            'clock_request_summary' => self::getClockRequestSummary($diagnostics),
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
     * @return array<int, string>
     */
    private static function getCriticalFindings(array $diagnostics): array
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
            if ($lastError !== '') {
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
        $succeeded = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status = 'succeeded'");
        $failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status IN ('failed', 'error')");
        $manualReview = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE clock_sync_status = 'manual_review'");

        $latestError = (string) $wpdb->get_var(
            "SELECT error_message FROM `{$table}` WHERE error_message <> '' ORDER BY id DESC LIMIT 1"
        );

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

        if (!self::isSafeTableName($table) || !self::tableExists($table)) {
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
        $clean = [];

        foreach ($report as $key => $value) {
            $keyString = \is_string($key) ? $key : (string) $key;

            if (self::isSensitiveKey($keyString)) {
                continue;
            }

            if (\is_array($value)) {
                $clean[$keyString] = self::sanitizeReport($value);
                continue;
            }

            if (\is_bool($value) || \is_int($value) || \is_float($value) || $value === null) {
                $clean[$keyString] = $value;
                continue;
            }

            $clean[$keyString] = self::maskSensitiveText((string) $value);
        }

        return $clean;
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
            [
                'token' => $token,
                'refresh' => \time(),
            ],
            \rest_url(self::REST_NAMESPACE . self::REST_ROUTE)
        );
    }

    private static function getPlainEndpointUrl(string $token): string
    {
        return \add_query_arg(
            [
                'must_support_health' => '1',
                'token' => $token,
                'refresh' => \time(),
            ],
            \home_url('/')
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

    /**
     * @param array<string, mixed> $payload
     */
    private static function sendJsonResponse(array $payload, int $statusCode = 200): void
    {
        self::sendNoCacheHeaders();

        \status_header($statusCode);

        if (!\headers_sent()) {
            \header('Content-Type: application/json; charset=' . \get_option('blog_charset'));
        }

        $json = \wp_json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        echo $json !== false ? $json : '{"success":false,"message":"Unable to encode diagnostics report."}';

        exit;
    }

    /**
     * @param array<string, mixed> $schedules
     * @return array<string, mixed>
     */
    public static function addCronSchedules(array $schedules): array
    {
        if (!isset($schedules[self::STATIC_REFRESH_CRON_SCHEDULE])) {
            $schedules[self::STATIC_REFRESH_CRON_SCHEDULE] = [
                'interval' => 5 * 60,
                'display' => \__('Every 5 minutes for MUST support diagnostics', 'must-hotel-booking'),
            ];
        }

        return $schedules;
    }

    public static function maybeScheduleStaticRefreshCron(): void
    {
        $settings = self::getSettings();

        if (empty($settings['enabled']) || (string) ($settings['token'] ?? '') === '') {
            self::unscheduleStaticRefreshCron();
            return;
        }

        if (\wp_next_scheduled(self::STATIC_REFRESH_CRON_HOOK) === false) {
            \wp_schedule_event(
                \time() + 60,
                self::STATIC_REFRESH_CRON_SCHEDULE,
                self::STATIC_REFRESH_CRON_HOOK
            );
        }
    }

    public static function refreshStaticReportFromCron(): void
    {
        $settings = self::getSettings();

        if (empty($settings['enabled']) || (string) ($settings['token'] ?? '') === '') {
            self::deleteStaticReportFiles();
            self::unscheduleStaticRefreshCron();
            return;
        }

        self::writeStaticReportFile($settings);
    }

    private static function unscheduleStaticRefreshCron(): void
    {
        while (($timestamp = \wp_next_scheduled(self::STATIC_REFRESH_CRON_HOOK)) !== false) {
            \wp_unschedule_event((int) $timestamp, self::STATIC_REFRESH_CRON_HOOK);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function writeStaticReportFile(array $settings): bool
    {
        $token = (string) ($settings['token'] ?? '');

        if ($token === '' || empty($settings['enabled'])) {
            return false;
        }

        $dir = self::getStaticReportDir();

        if ($dir === '') {
            return false;
        }

        if (!\wp_mkdir_p($dir)) {
            return false;
        }

        self::writeStaticProtectionFiles($dir);
        self::deleteStaticReportFiles();

        $report = self::buildReport($settings);
        $report['static_report'] = [
            'generated_at' => \current_time('mysql'),
            'refresh_mode' => 'admin_or_wp_cron',
            'auto_refresh_minutes' => 5,
        ];

        $json = \wp_json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        $path = self::getStaticReportPath($token);

        if ($path === '') {
            return false;
        }

        return \file_put_contents($path, $json, \LOCK_EX) !== false;
    }

    private static function deleteStaticReportFiles(): void
    {
        $dir = self::getStaticReportDir();

        if ($dir === '' || !\is_dir($dir)) {
            return;
        }

        $pattern = \trailingslashit($dir) . self::STATIC_FILENAME_PREFIX . '*.json';

        foreach ((array) \glob($pattern) as $file) {
            if (\is_string($file) && \is_file($file)) {
                \unlink($file);
            }
        }
    }

    private static function writeStaticProtectionFiles(string $dir): void
    {
        if ($dir === '' || !\is_dir($dir)) {
            return;
        }

        $indexPath = \trailingslashit($dir) . 'index.html';

        if (!\file_exists($indexPath)) {
            \file_put_contents($indexPath, '');
        }

        $htaccessPath = \trailingslashit($dir) . '.htaccess';

        if (!\file_exists($htaccessPath)) {
            \file_put_contents(
                $htaccessPath,
                "Options -Indexes\n<IfModule mod_headers.c>\nHeader set X-Robots-Tag \"noindex, nofollow, noarchive\"\nHeader set Cache-Control \"no-store, no-cache, must-revalidate, max-age=0\"\nHeader set Pragma \"no-cache\"\nHeader set Expires \"0\"\n</IfModule>\n"
            );
        }
    }

    private static function getStaticReportDir(): string
    {
        $uploads = \wp_upload_dir();

        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }

        return \trailingslashit((string) $uploads['basedir']) . self::STATIC_SUBDIR;
    }

    private static function getStaticReportBaseUrl(): string
    {
        $uploads = \wp_upload_dir();

        if (!empty($uploads['error']) || empty($uploads['baseurl'])) {
            return '';
        }

        return \trailingslashit((string) $uploads['baseurl']) . self::STATIC_SUBDIR;
    }

    private static function getStaticReportFilename(string $token): string
    {
        return self::STATIC_FILENAME_PREFIX . \hash('sha256', $token) . '.json';
    }

    private static function getStaticReportPath(string $token): string
    {
        $dir = self::getStaticReportDir();

        if ($dir === '') {
            return '';
        }

        return \trailingslashit($dir) . self::getStaticReportFilename($token);
    }

    private static function getStaticReportUrl(string $token): string
    {
        $baseUrl = self::getStaticReportBaseUrl();

        if ($baseUrl === '') {
            return '';
        }

        return \trailingslashit($baseUrl) . self::getStaticReportFilename($token);
    }
}