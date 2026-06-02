<?php

namespace MustHotelBooking\Core;

use MustHotelBooking\Admin\SettingsDiagnostics;
use MustHotelBooking\Provider\Clock\ClockConfig;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Portal\PortalRouter;

final class SupportDiagnosticsEndpoint
{
    private const OPTION_NAME = 'must_hotel_booking_support_diagnostics';
    private const REST_NAMESPACE = 'must-support/v1';
    private const REST_ROUTE = '/health';

    public static function registerHooks(): void
    {
        \add_action('rest_api_init', [self::class, 'registerRestRoutes']);
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

    public static function handleHealthRequest(\WP_REST_Request $request)
    {
        $settings = self::getSettings();

        if (empty($settings['enabled'])) {
            return self::notFoundResponse();
        }

        $providedToken = (string) $request->get_param('token');
        $storedToken = (string) ($settings['token'] ?? '');

        if ($storedToken === '' || $providedToken === '' || !\hash_equals($storedToken, $providedToken)) {
            return self::notFoundResponse();
        }

        return \rest_ensure_response(self::buildReport($settings));
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
            echo '<label for="must-support-diagnostics-url">' . \esc_html__('Support endpoint URL', 'must-hotel-booking') . '</label>';
            echo '<input id="must-support-diagnostics-url" type="text" readonly value="' . \esc_attr($endpointUrl) . '" onclick="this.select();" />';
            echo '<p class="description">' . \esc_html__('Send this URL only to trusted support. Disable or regenerate the token when finished.', 'must-hotel-booking') . '</p>';
            echo '</div>';
        } else {
            echo '<p class="description">' . \esc_html__('Enable the endpoint to generate a support URL.', 'must-hotel-booking') . '</p>';
        }

        echo '<div class="must-dashboard-action-strip">';
        echo '<span class="must-dashboard-action-strip-label">' . \esc_html__('Actions', 'must-hotel-booking') . '</span>';

        if ($enabled) {
            echo '<button type="submit" name="support_diagnostics_action" value="save" class="button">' . \esc_html__('Save Settings', 'must-hotel-booking') . '</button>';
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
            'provider' => [
                'configured_mode' => ProviderManager::configuredKey(),
                'active_mode' => ProviderManager::activeKey(),
                'clock_summary' => $clockSummary,
            ],
            'diagnostics' => self::pickDiagnostics($diagnostics),
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
        ];

        if (\in_array($key, $blockedExact, true)) {
            return true;
        }

        $blockedContains = [
            'secret',
            'password',
            'access_token',
            'refresh_token',
            'bearer',
            'authorization',
            'cookie',
            'nonce',
            'raw_',
            'payload',
            'request_body',
            'response_body',
            'card',
            'email',
            'phone',
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

        return false;
    }

    private static function maskSensitiveText(string $value): string
    {
        $value = \sanitize_text_field($value);

        $value = (string) \preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email-redacted]', $value);
        $value = (string) \preg_replace('/\+?\d[\d\s().\-]{7,}\d/', '[phone-redacted]', $value);
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
            ['token' => $token],
            \rest_url(self::REST_NAMESPACE . self::REST_ROUTE)
        );
    }
}