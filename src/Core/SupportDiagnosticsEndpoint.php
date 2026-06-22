<?php
namespace MustHotelBooking\Core;
use MustHotelBooking\Admin\SettingsDiagnostics;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Provider\Clock\ClockApiClient;
use MustHotelBooking\Provider\Clock\ClockConfig;
use MustHotelBooking\Provider\ProviderDataSanitizer;
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
            'future_refund_readiness' => self::getFutureRefundReadiness(),
            'refund_manual_accounting_notice' => self::getRefundManualAccountingNotice(),
            'clock_folio_accounting_summary' => self::getClockFolioAccountingSummary(),
            'clock_folio_payment_accounting_notice' => self::getClockFolioPaymentAccountingNotice($clockRequestSummary),
            'provider_fee_capture_readiness' => self::getProviderFeeCaptureReadiness(),
            'clock_request_summary' => $clockRequestSummary,
            'phase1_trial_summary' => $phase1TrialSummary,
            'production_readiness' => self::getProductionReadiness($diagnostics, $clockSummary, $clockRequestSummary, $phase1TrialSummary),
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
            $isKnownClockFolioPaymentManualAccounting =
                \in_array($lastOperation, ['clock.default_booking_folio_fetch', 'clock.booking_folios_list', 'clock.payment_sub_types_view', 'clock.folio_payment_create', 'clock.refund_credit_item_create'], true)
                && self::isKnownClockFolioPermissionMessage($lastError);
            if ($lastError !== '' && !$isKnownLocalOnlyPaymentNotice && !$isKnownClockFolioPaymentManualAccounting) {
                $findings[] = $lastError;
            }
        }
        $refundSummary = self::getRefundSummary();
        $manualReviewCount = (int) ($refundSummary['manual_review'] ?? 0);
        $failedRefundCount = (int) ($refundSummary['failed'] ?? 0);
        $latestManualReviewReason = (string) ($refundSummary['latest_manual_review_reason'] ?? '');
        $isKnownClockFolioManualAccounting =
            $manualReviewCount > 0
            && $failedRefundCount === 0
            && \stripos($latestManualReviewReason, 'no Clock folio ID') !== false;
        if ($manualReviewCount > 0 && !$isKnownClockFolioManualAccounting) {
            $findings[] = $latestManualReviewReason !== ''
                ? \sprintf(
                    '%d refund(s) require manual Clock review. Latest reason: %s',
                    $manualReviewCount,
                    $latestManualReviewReason
                )
                : \sprintf('%d refund(s) require manual Clock review.', $manualReviewCount);
        }
        if (empty($findings)) {
            $findings[] = 'No critical support findings detected by this report.';
        }
        return \array_values(\array_unique($findings));
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @param array<string, mixed> $clockSummary
     * @param array<string, mixed> $clockRequestSummary
     * @param array<string, mixed> $phase1TrialSummary
     * @return array<string, mixed>
     */
    private static function getProductionReadiness(array $diagnostics, array $clockSummary, array $clockRequestSummary, array $phase1TrialSummary): array
    {
        $settings = MustBookingConfig::get_all_settings();
        $checks = [
            'stripe' => self::getStripeReadiness($diagnostics),
            'pokpay' => self::getPokPayReadiness($diagnostics),
            'clock' => self::getClockReadiness($diagnostics, $clockSummary, $clockRequestSummary),
            'inventory' => self::getInventoryReadiness($diagnostics),
            'email' => self::getEmailReadiness($diagnostics),
            'cron' => self::getCronReadinessForProduction($diagnostics),
            'security' => self::getSecurityReadiness(\is_array($settings) ? $settings : [], $diagnostics),
        ];
        if (!empty($phase1TrialSummary['clock_folio_payment_ready']) && isset($checks['clock']['clock_folio_payment_mode'])) {
            $checks['clock']['clock_folio_payment_mode'] = 'automatic';
        }
        return self::buildProductionReadinessStatus($checks) + ['checks' => $checks];
    }

    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function getPokPayReadiness(array $diagnostics): array
    {
        $payments = isset($diagnostics['payments']) && \is_array($diagnostics['payments']) ? $diagnostics['payments'] : [];
        $enabled = !empty($payments['pokpay_enabled']);
        $state = PaymentEngine::getPokPayCredentialState();
        $status = (string) ($state['status'] ?? 'missing');
        $blockers = [];
        $warnings = [];

        if ($enabled && \in_array($status, ['missing', 'rejected', 'malformed'], true)) {
            $blockers[] = 'PokPay is enabled but the active credentials are missing, rejected, or malformed.';
        } elseif ($enabled && $status === 'unverified') {
            $warnings[] = 'PokPay credentials are present but have not been authenticated by the credential test.';
        } elseif ($enabled && $status === 'provider_unavailable') {
            $warnings[] = 'The latest PokPay credential verification was inconclusive because the provider was unavailable.';
        }

        return [
            'pokpay_enabled' => $enabled,
            'environment' => (string) ($state['environment'] ?? ''),
            'credential_status' => $status,
            'authentication_success' => !empty($state['authentication_success']),
            'http_status' => (int) ($state['http_status'] ?? 0),
            'provider_error_code' => (string) ($state['error_code'] ?? ''),
            'provider_error_message' => (string) ($state['error_message'] ?? ''),
            'verified_at' => (string) ($state['verified_at'] ?? ''),
            'merchant_id_masked' => (string) ($state['merchant_id_masked'] ?? ''),
            'key_id_masked' => (string) ($state['key_id_masked'] ?? ''),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'manual_operations' => [],
            'check_status' => !empty($blockers) ? 'blocked' : (!empty($warnings) ? 'warning' : 'ready'),
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function getStripeReadiness(array $diagnostics): array
    {
        $payments = isset($diagnostics['payments']) && \is_array($diagnostics['payments']) ? $diagnostics['payments'] : [];
        $stripeEnabled = !empty($payments['stripe_enabled']);
        $environment = PaymentEngine::getActiveSiteEnvironment();
        $credentials = PaymentEngine::getStripeEnvironmentCredentials($environment);
        $publishableKeyPresent = (string) ($credentials['publishable_key'] ?? '') !== '';
        $secretKeyPresent = (string) ($credentials['secret_key'] ?? '') !== '';
        $webhookSecretPresent = PaymentEngine::getStripeWebhookSecret() !== '';
        $mode = $environment === 'production' ? 'live' : ($environment !== '' ? 'test' : 'unknown');
        $latestPayment = self::getLatestStripePaymentSummary();
        $lastWebhookSeenAt = self::getLatestActivityAt(['stripe_webhook_received', 'stripe_webhook_processed', 'stripe_webhook_failed']);
        $lastStripeError = self::getLatestStripeError();
        $blockers = [];
        $warnings = [];
        if ($stripeEnabled && !$publishableKeyPresent) {
            $blockers[] = 'Stripe is enabled but the active publishable key is missing.';
        }
        if ($stripeEnabled && !$secretKeyPresent) {
            $blockers[] = 'Stripe is enabled but the active secret key is missing.';
        }
        if ($stripeEnabled && !$webhookSecretPresent) {
            $blockers[] = 'Stripe is enabled but the webhook signing secret is missing.';
        }
        if ($stripeEnabled && (string) ($latestPayment['paid_at'] ?? '') === '' && $lastWebhookSeenAt === '') {
            $warnings[] = 'Stripe is enabled but no successful payment or webhook activity is recorded in local diagnostics.';
        }
        if ($stripeEnabled && $mode === 'test') {
            $warnings[] = 'Stripe test mode is active.';
        }
        return [
            'stripe_enabled' => $stripeEnabled,
            'stripe_mode' => $mode,
            'publishable_key_present' => $publishableKeyPresent,
            'secret_key_present' => $secretKeyPresent,
            'webhook_secret_present' => $webhookSecretPresent,
            'last_successful_payment_at' => (string) ($latestPayment['paid_at'] ?? ''),
            'last_webhook_seen_at' => $lastWebhookSeenAt,
            'last_stripe_error' => $lastStripeError,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'manual_operations' => [],
            'check_status' => !empty($blockers) ? 'blocked' : (!empty($warnings) ? 'warning' : 'ready'),
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @param array<string, mixed> $clockSummary
     * @param array<string, mixed> $clockRequestSummary
     * @return array<string, mixed>
     */
    private static function getClockReadiness(array $diagnostics, array $clockSummary, array $clockRequestSummary): array
    {
        $provider = isset($diagnostics['provider']) && \is_array($diagnostics['provider']) ? $diagnostics['provider'] : [];
        $clockDiagnostics = isset($provider['clock']) && \is_array($provider['clock']) ? $provider['clock'] : [];
        $clock = \array_merge($clockDiagnostics, $clockSummary);
        $configuredMode = (string) ($provider['configured_mode'] ?? ProviderManager::configuredKey());
        $activeMode = (string) ($provider['active_booking_provider'] ?? ProviderManager::activeKey());
        $clockEnabled = !empty($clock['clock_enabled']) || $configuredMode === ProviderManager::CLOCK_MODE || $activeMode === ProviderManager::CLOCK_MODE;
        $clockActive = $configuredMode === ProviderManager::CLOCK_MODE || $activeMode === ProviderManager::CLOCK_MODE;
        $baseConfigured = !empty($clock['clock_base_api_configured']) || !empty($clock['clock_base_api_url_configured']);
        $pmsConfigured = !empty($clock['clock_pms_api_configured']) || !empty($clock['clock_pms_api_url_configured']) || !empty($clock['clock_direct_api_configured']);
        $credentialsPresent = !empty($clock['clock_api_user_set']) && !empty($clock['clock_api_key_set']);
        $lastSuccessCreate = self::getLatestProviderRequestAt('clock.reservation_create', true);
        $lastSuccessCancel = self::getLatestProviderRequestAt('clock.reservation_cancel', true);
        $lastError = (string) ($clockRequestSummary['last_error'] ?? '');
        $lastOperation = (string) ($clockRequestSummary['last_error_operation'] ?? '');
        $missingPermissions = [];
        if (self::isKnownClockFolioPermissionMessage($lastError)) {
            $missingPermissions[] = 'booking_folios_list_view';
            $missingPermissions[] = 'credit_item_payment_create';
        }
        $manualOperations = [];
        if (!empty($missingPermissions)) {
            $manualOperations[] = 'Staff must manually review website payments in Clock until booking_folios LIST VIEW, payment_sub_types VIEW, and credit_item payment CREATE rights are available.';
        }
        $blockers = [];
        $warnings = [];
        if ($clockActive && !$baseConfigured) {
            $blockers[] = 'Clock mode is active but Base API configuration is missing.';
        }
        if ($clockActive && !$pmsConfigured) {
            $blockers[] = 'Clock mode is active but PMS API configuration is missing.';
        }
        if ($clockActive && !$credentialsPresent) {
            $blockers[] = 'Clock mode is active but API credentials are missing.';
        }
        $isBookingCreateError = \strpos($lastOperation, 'reservation_create') !== false || \strpos($lastOperation, 'booking_create') !== false;
        if ($clockActive && $lastError !== '' && !self::isKnownClockFolioPermissionMessage($lastError) && !self::isKnownLocalOnlyPaymentStatusMessage($lastError)) {
            if ($isBookingCreateError) {
                $blockers[] = $lastOperation !== '' ? $lastOperation . ': ' . $lastError : $lastError;
            } else {
                $warnings[] = $lastOperation !== '' ? $lastOperation . ': ' . $lastError : $lastError;
            }
        }
        if (!empty($missingPermissions)) {
            $warnings[] = 'Clock API user appears to be missing folio accounting rights: booking_folios LIST VIEW, payment_sub_types VIEW, or credit_item payment CREATE.';
        }
        return [
            'clock_enabled' => $clockEnabled,
            'base_api_configured' => $baseConfigured,
            'pms_api_configured' => $pmsConfigured,
            'api_credentials_present' => $credentialsPresent,
            'configured_mode' => $configuredMode,
            'active_mode' => $activeMode,
            'last_successful_booking_create' => $lastSuccessCreate,
            'last_successful_cancel' => $lastSuccessCancel,
            'last_failed_clock_operation' => $lastError !== '' ? ['operation' => $lastOperation, 'message' => $lastError] : [],
            'clock_folio_payment_mode' => empty($missingPermissions) ? 'automatic' : 'manual',
            'missing_permissions' => $missingPermissions,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'manual_operations' => $manualOperations,
            'check_status' => !empty($blockers) ? 'blocked' : (!empty($warnings) || !empty($manualOperations) ? 'warning' : 'ready'),
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function getInventoryReadiness(array $diagnostics): array
    {
        global $wpdb;
        $roomsTable = $wpdb->prefix . 'must_rooms';
        $inventoryTable = $wpdb->prefix . 'mhb_rooms';
        $rateTable = $wpdb->prefix . 'mhb_room_type_rate_plans';
        $mappingTable = $wpdb->prefix . 'mhb_provider_mappings';
        $clockActive = ProviderManager::configuredKey() === ProviderManager::CLOCK_MODE || ProviderManager::activeKey() === ProviderManager::CLOCK_MODE;
        $items = [];
        $blockers = [];
        $warnings = [];
        $limitations = [];
        $mappingLimited = false;
        if (!self::tableExists($roomsTable) || !self::columnExists($roomsTable, 'id') || !self::columnExists($roomsTable, 'name')) {
            return [
                'total_active_sellable_rooms_or_room_types' => 0,
                'items_checked' => [],
                'items_missing_clock_mapping' => [],
                'items_missing_inventory' => [],
                'items_missing_price' => [],
                'items_missing_rate' => [],
                'mapping_check_limited' => true,
                'limitations' => ['Sellable room table or required columns are missing.'],
                'blockers' => ['Sellable room inventory cannot be checked because the room table is unavailable.'],
                'warnings' => [],
                'manual_operations' => [],
                'check_status' => 'blocked',
            ];
        }
        $where = [];
        if (self::columnExists($roomsTable, 'is_active')) {
            $where[] = 'is_active = 1';
        }
        if (self::columnExists($roomsTable, 'is_bookable')) {
            $where[] = 'is_bookable = 1';
        }
        if (self::columnExists($roomsTable, 'is_online_bookable')) {
            $where[] = 'is_online_bookable = 1';
        }
        $priceSelect = self::columnExists($roomsTable, 'base_price') ? 'base_price' : '0 AS base_price';
        $rows = $wpdb->get_results('SELECT id, name, ' . $priceSelect . ' FROM `' . $roomsTable . '`' . (!empty($where) ? ' WHERE ' . \implode(' AND ', $where) : '') . ' ORDER BY id ASC LIMIT 200', ARRAY_A);
        if (!\is_array($rows)) {
            $rows = [];
        }
        $inventoryCheckAvailable = self::tableExists($inventoryTable) && self::columnExists($inventoryTable, 'room_type_id');
        $rateCheckAvailable = self::tableExists($rateTable) && self::columnExists($rateTable, 'room_type_id');
        $mappingCheckAvailable = self::tableExists($mappingTable) && self::columnExists($mappingTable, 'local_id') && self::columnExists($mappingTable, 'external_id');
        if (!$inventoryCheckAvailable) {
            $limitations[] = 'Inventory unit table cannot be checked reliably.';
        }
        if (!$rateCheckAvailable) {
            $limitations[] = 'Rate assignment table cannot be checked reliably.';
        }
        if (!$mappingCheckAvailable) {
            $mappingLimited = true;
            $limitations[] = 'Clock mapping table cannot be checked reliably.';
        }
        $missingMapping = [];
        $missingInventory = [];
        $missingPrice = [];
        $missingRate = [];
        $totalPublicUnits = 0;
        $unmappedPublicUnits = 0;
        $unitMetadataWarnings = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $title = (string) ($row['name'] ?? '');
            $price = (float) ($row['base_price'] ?? 0);
            $inventoryUnits = $inventoryCheckAvailable ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $inventoryTable . '` WHERE room_type_id = %d', $id)) : 0;
            $rateCount = $rateCheckAvailable ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $rateTable . '` WHERE room_type_id = %d', $id)) : 0;
            $clockMappings = $mappingCheckAvailable ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $mappingTable . '` WHERE provider = %s AND entity_type = %s AND local_id = %d AND external_id <> %s', ProviderManager::CLOCK_MODE, 'accommodation', $id, '')) : 0;
            $clockPriceResolvable = $clockActive && !empty($clockMappings) && $rateCount > 0 && !empty($inventoryUnits);
            $itemWarnings = [];
            if ($inventoryCheckAvailable && $inventoryUnits <= 0) {
                $missingInventory[] = $id;
                $itemWarnings[] = 'No inventory units are configured.';
                $blockers[] = 'Active sellable accommodation has no inventory units: ' . $title;
            }
            if ($price <= 0) {
                $missingPrice[] = $id;
                $itemWarnings[] = 'Base price is missing or zero.';

                if (!$clockPriceResolvable) {
                    $blockers[] = 'Active sellable accommodation has no price: ' . $title;
                } else {
                    $warnings[] = 'Local base price is missing, but Clock mode will use Clock rate pricing for: ' . $title;
                }
            }
            if ($rateCheckAvailable && $rateCount <= 0) {
                $missingRate[] = $id;
                $itemWarnings[] = 'No rate plan assignment is configured.';
                $blockers[] = 'Active sellable accommodation has no rate assignment: ' . $title;
            }
            if ($clockActive && $mappingCheckAvailable && $clockMappings <= 0) {
                $missingMapping[] = $id;
                $itemWarnings[] = 'No Clock accommodation mapping is configured.';
                $blockers[] = 'Clock mode is active and accommodation has no Clock mapping: ' . $title;
            }
            if ($clockActive && $inventoryCheckAvailable) {
                $publicVisibleExpr = self::columnExists($inventoryTable, 'public_visible') ? 'public_visible = 1' : '1=1';
                $publicUnitRows = $wpdb->get_results($wpdb->prepare('SELECT id, title, room_number'
                    . (self::columnExists($inventoryTable, 'featured_image_id') ? ', featured_image_id' : ', 0 AS featured_image_id')
                    . (self::columnExists($inventoryTable, 'public_title') ? ', public_title' : ', "" AS public_title')
                    . (self::columnExists($inventoryTable, 'public_description') ? ', public_description' : ', "" AS public_description')
                    . ' FROM `' . $inventoryTable . '` WHERE room_type_id = %d AND is_active = 1 AND is_bookable = 1 AND ' . $publicVisibleExpr, $id), ARRAY_A);

                foreach (\is_array($publicUnitRows) ? $publicUnitRows : [] as $unitRow) {
                    if (!\is_array($unitRow)) {
                        continue;
                    }

                    $unitId = (int) ($unitRow['id'] ?? 0);

                    if ($unitId <= 0) {
                        continue;
                    }

                    $totalPublicUnits++;
                    $physicalMappings = $mappingCheckAvailable ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $mappingTable . '` WHERE provider = %s AND entity_type = %s AND local_id = %d AND external_id <> %s', ProviderManager::CLOCK_MODE, 'physical_room', $unitId, '')) : 0;

                    if ($physicalMappings <= 0) {
                        $unmappedPublicUnits++;
                        $blockers[] = 'Clock mode public unit has no Clock physical room mapping: ' . \trim((string) ($unitRow['public_title'] ?? $unitRow['title'] ?? $unitRow['room_number'] ?? ('#' . $unitId)));
                    }

                    if ((int) ($unitRow['featured_image_id'] ?? 0) <= 0 || \trim((string) ($unitRow['public_title'] ?? '')) === '' || \trim((string) ($unitRow['public_description'] ?? '')) === '') {
                        $unitMetadataWarnings++;
                    }
                }
            }
            $items[] = [
                'id' => $id,
                'title' => $title,
                'has_clock_mapping' => $mappingCheckAvailable ? $clockMappings > 0 : false,
                'has_inventory_units' => $inventoryCheckAvailable ? $inventoryUnits > 0 : false,
                'has_price' => $price > 0,
                'has_rate_mapping' => $rateCheckAvailable ? $rateCount > 0 : false,
                'clock_price_resolvable' => $clockPriceResolvable,
                'can_book' => empty($itemWarnings),
                'warnings' => $itemWarnings,
            ];
        }
        if ($unitMetadataWarnings > 0) {
            $warnings[] = $unitMetadataWarnings . ' public unit(s) are missing public image/title/description metadata.';
        }
        if ($clockActive && !\MustHotelBooking\Provider\Clock\ClockConfig::isPublicBookingConfigured()) {
            $warnings[] = 'Guaranteed individual room booking is enabled by Clock mode, but Clock public booking endpoints are not fully configured.';
        }
        if ($mappingLimited || !$inventoryCheckAvailable || !$rateCheckAvailable) {
            $warnings[] = 'Inventory, rate, or Clock mapping checks are limited by unavailable tables or columns.';
        }
        return [
            'total_active_sellable_rooms_or_room_types' => \count($items),
            'total_public_units' => $totalPublicUnits,
            'unmapped_public_units' => $unmappedPublicUnits,
            'public_units_missing_metadata_warning_count' => $unitMetadataWarnings,
            'items_checked' => $items,
            'items_missing_clock_mapping' => $missingMapping,
            'items_missing_inventory' => $missingInventory,
            'items_missing_price' => $missingPrice,
            'items_missing_rate' => $missingRate,
            'mapping_check_limited' => $mappingLimited,
            'limitations' => $limitations,
            'blockers' => \array_values(\array_unique($blockers)),
            'warnings' => $warnings,
            'manual_operations' => [],
            'check_status' => !empty($blockers) ? 'blocked' : (!empty($warnings) ? 'warning' : 'ready'),
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function getEmailReadiness(array $diagnostics): array
    {
        $emails = isset($diagnostics['emails']) && \is_array($diagnostics['emails']) ? $diagnostics['emails'] : [];
        $lastSent = self::getLatestActivityAt(['email_sent']);
        $lastFailed = self::getLatestActivityMessage(['email_failed']);
        $warnings = [];
        $blockers = [];
        $configured = !empty($emails['is_configured']);
        if (!$configured) {
            $warnings[] = 'Email settings cannot be fully determined or are incomplete.';
        }
        if ($lastSent === '' && $lastFailed === '') {
            $warnings[] = 'No recent email success or failure data is recorded.';
        }
        if ((int) ($emails['recent_failures'] ?? 0) > 0 && $configured) {
            $blockers[] = 'Recent confirmation email failures are recorded.';
        }
        return [
            'wp_mail_available' => \function_exists('wp_mail'),
            'guest_email_enabled' => $configured,
            'admin_email_enabled' => $configured,
            'last_guest_email_sent' => $lastSent,
            'last_admin_email_sent' => $lastSent,
            'last_email_error' => (string) ($lastFailed['message'] ?? ''),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'manual_operations' => [],
            'check_status' => !empty($blockers) ? 'blocked' : (!empty($warnings) ? 'warning' : 'ready'),
        ];
    }
    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function getCronReadinessForProduction(array $diagnostics): array
    {
        unset($diagnostics);
        $required = [
            'must_hotel_booking_cleanup_expired_locks',
            'must_hotel_booking_process_provider_sync_jobs',
            'must_hotel_booking_clock_auto_reservation_sync',
        ];
        $scheduled = [];
        foreach (self::getPluginCronStatuses()['items'] as $item) {
            if (\is_array($item) && isset($item['hook'])) {
                $scheduled[] = (string) $item['hook'];
            }
        }
        $missing = \array_values(\array_diff($required, $scheduled));
        return [
            'required_jobs' => $required,
            'scheduled_jobs' => \array_values(\array_unique($scheduled)),
            'missing_jobs' => $missing,
            'blockers' => \array_map(static function (string $hook): string {
                return 'Required WP-Cron job is not scheduled: ' . $hook;
            }, $missing),
            'warnings' => [],
            'manual_operations' => [],
            'check_status' => !empty($missing) ? 'blocked' : 'ready',
        ];
    }
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private static function getSecurityReadiness(array $settings, array $diagnostics): array
    {
        unset($diagnostics);
        $supportSettings = self::getSettings();
        $stripeMode = PaymentEngine::getActiveSiteEnvironment() === 'production' ? 'live' : 'test';
        $warnings = [];
        if (\defined('WP_DEBUG') && WP_DEBUG) {
            $warnings[] = 'WP_DEBUG is enabled.';
        }
        if (!empty($supportSettings['enabled'])) {
            $warnings[] = 'Support diagnostics endpoint is enabled.';
        }
        if (!empty($supportSettings['include_logs'])) {
            $warnings[] = 'Support diagnostics includes sanitized logs.';
        }
        if ((string) ($supportSettings['token'] ?? '') !== '') {
            $warnings[] = 'Diagnostics token should be regenerated or disabled after testing.';
        }
        if ($stripeMode === 'test') {
            $warnings[] = 'Stripe test mode is active.';
        }
        return [
            'wp_debug_enabled' => \defined('WP_DEBUG') && WP_DEBUG,
            'support_diagnostics_enabled' => !empty($supportSettings['enabled']),
            'support_diagnostics_include_logs' => !empty($supportSettings['include_logs']),
            'support_diagnostics_token_present' => (string) ($supportSettings['token'] ?? '') !== '',
            'stripe_test_mode_active' => $stripeMode === 'test',
            'settings_loaded' => !empty($settings),
            'blockers' => [],
            'warnings' => $warnings,
            'manual_operations' => [],
            'check_status' => !empty($warnings) ? 'warning' : 'ready',
        ];
    }
    /**
     * @param array<string, array<string, mixed>> $checks
     * @return array<string, mixed>
     */
    private static function buildProductionReadinessStatus(array $checks): array
    {
        $blockers = [];
        $warnings = [];
        $manualOperations = [];
        foreach ($checks as $checkName => $check) {
            foreach ((array) ($check['blockers'] ?? []) as $message) {
                $blockers[] = $checkName . ': ' . (string) $message;
            }
            foreach ((array) ($check['warnings'] ?? []) as $message) {
                $warnings[] = $checkName . ': ' . (string) $message;
            }
            foreach ((array) ($check['manual_operations'] ?? []) as $message) {
                $manualOperations[] = $checkName . ': ' . (string) $message;
            }
        }
        $blockers = \array_values(\array_unique($blockers));
        $warnings = \array_values(\array_unique($warnings));
        $manualOperations = \array_values(\array_unique($manualOperations));
        return [
            'overall_status' => !empty($blockers) ? 'blocked' : (!empty($warnings) || !empty($manualOperations) ? 'warning' : 'ready'),
            'launch_blockers' => $blockers,
            'warnings' => $warnings,
            'manual_operations' => $manualOperations,
        ];
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
    private static function getRefundManualAccountingNotice(): array
    {
        $refundSummary = self::getRefundSummary();
        $manualReviewCount = (int) ($refundSummary['manual_review'] ?? 0);
        $failedRefundCount = (int) ($refundSummary['failed'] ?? 0);
        $latestReason = (string) ($refundSummary['latest_manual_review_reason'] ?? '');
        $isKnownClockFolioManualAccounting =
            $manualReviewCount > 0
            && $failedRefundCount === 0
            && \stripos($latestReason, 'no Clock folio ID') !== false;
        return [
            'enabled' => $isKnownClockFolioManualAccounting,
            'status' => $isKnownClockFolioManualAccounting ? 'manual_clock_accounting_required' : 'not_applicable',
            'manual_review_count' => $manualReviewCount,
            'failed_refund_count' => $failedRefundCount,
            'message' => $isKnownClockFolioManualAccounting
                ? 'Stripe refunds succeeded, but Clock did not expose a folio ID. Clock PMS folio/accounting must be handled manually, then marked manual done in the plugin.'
                : '',
        ];
    }
    /**
     * @return array<string, mixed>
     */
    private static function getClockFolioAccountingSummary(): array
    {
        if (!\function_exists('MustHotelBooking\\Engine\\get_clock_folio_accounting_repository')) {
            return [
                'table_exists' => false,
                'total' => 0,
            ];
        }

        return \MustHotelBooking\Engine\get_clock_folio_accounting_repository()->getSummary();
    }

    /**
     * @return array<string, mixed>
     */
    private static function getProviderFeeCaptureReadiness(): array
    {
        global $wpdb;

        $paymentsTable = $wpdb->prefix . 'must_payments';
        $hasFeeColumns = self::tableExists($paymentsTable)
            && self::columnExists($paymentsTable, 'provider_fee_amount')
            && self::columnExists($paymentsTable, 'provider_fee_status')
            && self::columnExists($paymentsTable, 'provider_balance_transaction_id');
        $pokpayEstimateConfigured = MustBookingConfig::get_pokpay_fee_percent() > 0.0 || MustBookingConfig::get_pokpay_fee_fixed() > 0.0;
        $pokpayCredentials = PaymentEngine::getPokPayEnvironmentCredentials();
        $pokpayCredentialsPresent = (string) ($pokpayCredentials['merchant_id'] ?? '') !== ''
            && (string) ($pokpayCredentials['key_id'] ?? '') !== ''
            && (string) ($pokpayCredentials['key_secret'] ?? '') !== '';
        $latestStripeFeeStatus = '';
        $latestPokPayFeeStatus = '';
        $latestPokPayFeeSource = '';

        if ($hasFeeColumns) {
            $latestStripeFeeStatus = (string) $wpdb->get_var("SELECT provider_fee_status FROM `{$paymentsTable}` WHERE method = 'stripe' AND status = 'paid' ORDER BY id DESC LIMIT 1");
            $latestPokPayFeeStatus = (string) $wpdb->get_var("SELECT provider_fee_status FROM `{$paymentsTable}` WHERE method = 'pokpay' AND status = 'paid' ORDER BY id DESC LIMIT 1");
            $latestPokPayFeeSource = (string) $wpdb->get_var("SELECT provider_fee_source FROM `{$paymentsTable}` WHERE method = 'pokpay' AND status = 'paid' ORDER BY id DESC LIMIT 1");
        }

        $warnings = [];

        if (!$hasFeeColumns) {
            $warnings[] = 'Payment provider fee snapshot columns are missing; run the plugin database upgrade.';
        }

        if ($latestStripeFeeStatus === 'unknown') {
            $warnings[] = 'Latest paid Stripe payment has unknown fee capture; review Stripe balance transaction access.';
        }

        if (!$pokpayEstimateConfigured) {
            $warnings[] = 'PokPay API fee may be unavailable and no configured fee estimate is set.';
        }

        if (!$pokpayCredentialsPresent) {
            $warnings[] = 'PokPay credentials for the active environment are missing or need refresh.';
        }

        return [
            'stripe_fee_capture' => $hasFeeColumns ? ($latestStripeFeeStatus !== '' ? $latestStripeFeeStatus : 'no_paid_payment_seen') : 'unavailable',
            'pokpay_fee_source' => self::describePokPayFeeSource($latestPokPayFeeStatus, $latestPokPayFeeSource, $pokpayEstimateConfigured),
            'pokpay_credentials_status' => $pokpayCredentialsPresent ? 'present' : 'missing',
            'pokpay_credentials_present' => $pokpayCredentialsPresent,
            'pokpay_credentials_note' => $pokpayCredentialsPresent ? '' : 'Refresh staging/live PokPay credentials before manual payment testing.',
            'clock_folio_accounting_ready' => \function_exists('MustHotelBooking\\Engine\\get_clock_folio_accounting_repository')
                && !empty(\MustHotelBooking\Engine\get_clock_folio_accounting_repository()->getSummary()['table_exists']),
            'warnings' => $warnings,
            'check_status' => empty($warnings) ? 'ready' : 'warning',
        ];
    }

    private static function describePokPayFeeSource(string $latestFeeStatus, string $latestFeeSource, bool $estimateConfigured): string
    {
        if ($latestFeeStatus === 'known') {
            return $latestFeeSource === 'pokpay_api' ? 'api' : 'configured_estimate';
        }

        return $estimateConfigured ? 'configured_estimate_ready' : 'missing';
    }
    /**
     * @param array<string, mixed> $clockRequestSummary
     * @return array<string, mixed>
     */
    private static function getClockFolioPaymentAccountingNotice(array $clockRequestSummary): array
    {
        $accountingSummary = self::getClockFolioAccountingSummary();
        $manualReviewCount = (int) ($accountingSummary['manual_review'] ?? 0);
        $failedCount = (int) ($accountingSummary['failed'] ?? 0);
        $latestError = (string) ($accountingSummary['latest_error'] ?? '');
        $lastOperation = (string) ($clockRequestSummary['last_error_operation'] ?? '');
        $lastError = (string) ($clockRequestSummary['last_error'] ?? '');
        $lastHttpStatus = (int) ($clockRequestSummary['last_error_http_status'] ?? 0);
        $isKnownManualAccounting =
            $manualReviewCount > 0
            || $failedCount > 0
            || (
                \in_array($lastOperation, ['clock.booking_folios_list', 'clock.payment_sub_types_view', 'clock.folio_payment_create', 'clock.refund_credit_item_create'], true)
                && self::isKnownClockFolioPermissionMessage($lastError)
            );
        return [
            'enabled' => $isKnownManualAccounting,
            'status' => $isKnownManualAccounting ? 'clock_folio_accounting_review_required' : 'not_applicable',
            'manual_review_count' => $manualReviewCount,
            'failed_count' => $failedCount,
            'last_error_operation' => $lastOperation,
            'last_error_http_status' => $lastHttpStatus,
            'message' => $isKnownManualAccounting
                ? ($latestError !== '' ? $latestError : 'Clock folio accounting needs review. Confirm booking_folios LIST VIEW, payment_sub_types VIEW, and credit_item payment CREATE rights, then retry eligible rows from the Payments detail page.')
                : '',
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
        $reasonColumn = '';
        if (self::columnExists($table, 'failed_reason')) {
            $reasonColumn = 'failed_reason';
        } elseif (self::columnExists($table, 'error_message')) {
            $reasonColumn = 'error_message';
        }
        $latestError = '';
        $latestManualReviewReason = '';
        if ($reasonColumn !== '') {
            $latestError = (string) $wpdb->get_var(
                "SELECT `{$reasonColumn}` FROM `{$table}` WHERE `{$reasonColumn}` <> '' ORDER BY id DESC LIMIT 1"
            );
            if (self::columnExists($table, 'clock_sync_status')) {
                $latestManualReviewReason = (string) $wpdb->get_var(
                    "SELECT `{$reasonColumn}` FROM `{$table}` WHERE clock_sync_status = 'manual_review' AND `{$reasonColumn}` <> '' ORDER BY id DESC LIMIT 1"
                );
            }
        }
        return [
            'table_exists' => true,
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'manual_review' => $manualReview,
            'latest_error' => $latestManualReviewReason !== '' ? $latestManualReviewReason : $latestError,
            'latest_manual_review_reason' => $latestManualReviewReason,
            'reason_column' => $reasonColumn,
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
    private static function isKnownClockFolioPermissionMessage(string $message): bool
    {
        $message = \strtolower($message);
        return \strpos($message, 'pms_api_booking_folios_default') !== false
            || \strpos($message, 'booking_folios') !== false
            || \strpos($message, 'payment_sub_types') !== false
            || \strpos($message, 'credit_item') !== false
            || (
                \strpos($message, 'booking_folios_default') !== false
                && \strpos($message, 'right') !== false
            );
    }
    /**
     * @return array<string, mixed>
     */
    private static function getFutureRefundReadiness(): array
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
            return [
                'checked' => false,
                'status' => 'not_available',
                'message' => 'Required payment or reservation tables/columns are missing.',
                'latest_paid_reservation_id' => 0,
                'latest_booking_reference' => '',
                'local_provider_metadata_folio_id_present' => false,
                'clock_folio_id_present' => false,
                'clock_folio_id_source' => '',
                'clock_fetch_checked' => false,
                'clock_fetch_status' => '',
                'clock_fetch_message' => '',
                'clock_fetch_folio_id_present' => false,
                'clock_fetch_folio_id_source' => '',
            ];
        }
        $bookingReferenceSelect = self::columnExists($reservationsTable, 'booking_id') ? 'r.booking_id' : "''";
        $providerSelect = self::columnExists($reservationsTable, 'provider') ? 'r.provider' : "''";
        $providerMetadataSelect = self::columnExists($reservationsTable, 'provider_metadata') ? 'r.provider_metadata' : "''";
        $providerBookingIdSelect = self::columnExists($reservationsTable, 'provider_booking_id') ? 'r.provider_booking_id' : "''";
        $providerReservationIdSelect = self::columnExists($reservationsTable, 'provider_reservation_id') ? 'r.provider_reservation_id' : "''";
        $providerWhere = self::columnExists($reservationsTable, 'provider')
            ? $wpdb->prepare(' AND r.provider = %s', ProviderManager::CLOCK_MODE)
            : '';
        $row = $wpdb->get_row(
            "SELECT
            p.id AS payment_id,
            r.id AS reservation_id,
            {$bookingReferenceSelect} AS booking_reference,
            {$providerSelect} AS provider,
            {$providerMetadataSelect} AS provider_metadata,
            {$providerBookingIdSelect} AS provider_booking_id,
            {$providerReservationIdSelect} AS provider_reservation_id
        FROM `{$paymentsTable}` p
        INNER JOIN `{$reservationsTable}` r ON r.id = p.reservation_id
        WHERE p.status = 'paid'
        {$providerWhere}
        ORDER BY p.id DESC
        LIMIT 1",
            ARRAY_A
        );
        if (!\is_array($row)) {
            return [
                'checked' => true,
                'status' => 'no_paid_booking_found',
                'message' => 'No paid Clock booking was found to check future refund readiness.',
                'latest_paid_reservation_id' => 0,
                'latest_booking_reference' => '',
                'local_provider_metadata_folio_id_present' => false,
                'clock_folio_id_present' => false,
                'clock_folio_id_source' => '',
                'clock_fetch_checked' => false,
                'clock_fetch_status' => '',
                'clock_fetch_message' => '',
                'clock_fetch_folio_id_present' => false,
                'clock_fetch_folio_id_source' => '',
            ];
        }
        $reservationId = (int) ($row['reservation_id'] ?? 0);
        $bookingReference = (string) ($row['booking_reference'] ?? '');
        $provider = (string) ($row['provider'] ?? '');
        $metadata = self::decodeJsonObject((string) ($row['provider_metadata'] ?? ''));
        $folio = self::findClockFolioIdInMetadata($metadata);
        $accountingTable = $wpdb->prefix . 'must_clock_folio_accounting';
        $accountingFolioId = '';
        if (
            self::tableExists($accountingTable)
            && self::columnExists($accountingTable, 'payment_id')
            && self::columnExists($accountingTable, 'clock_folio_id')
            && self::columnExists($accountingTable, 'status')
        ) {
            $accountingFolioId = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT clock_folio_id
                    FROM `{$accountingTable}`
                    WHERE payment_id = %d
                        AND status = %s
                        AND direction IN (%s, %s)
                        AND clock_folio_id <> ''
                    ORDER BY id DESC
                    LIMIT 1",
                    (int) ($row['payment_id'] ?? 0),
                    'posted',
                    'deposit',
                    'payment'
                )
            );
        }
        if ($accountingFolioId !== '') {
            $folio = [
                'value' => \sanitize_text_field($accountingFolioId),
                'source' => 'must_clock_folio_accounting.clock_folio_id',
            ];
        }
        $isClockBooking = $provider === ProviderManager::CLOCK_MODE;
        $localFolioPresent = (string) ($folio['value'] ?? '') !== '';
        $clockFetch = [
            'checked' => false,
            'status' => '',
            'message' => '',
            'folio_id_present' => false,
            'folio_id_source' => '',
        ];
        if ($isClockBooking && !$localFolioPresent) {
            $clockFetch = self::checkClockFolioByFetchingBooking($row);
        }
        $ready = $isClockBooking && $localFolioPresent;
        $readyAfterClockFetch = $isClockBooking && !$localFolioPresent && !empty($clockFetch['folio_id_present']);
        $status = $ready ? 'ready' : 'needs_folio_recovery';
        $message = $ready
            ? 'Latest paid Clock booking has a Clock folio ID available for future automatic refund sync.'
            : 'Latest paid Clock booking does not show a Clock folio ID in provider metadata; future refunds may still require manual Clock review.';
        if ($readyAfterClockFetch) {
            $status = 'ready_after_clock_fetch';
            $message = 'Latest paid Clock booking does not show a Clock folio ID in local provider metadata, but the read-only Clock booking fetch exposes one.';
        }
        return [
            'checked' => true,
            'status' => $status,
            'message' => $message,
            'latest_paid_reservation_id' => $reservationId,
            'latest_booking_reference' => $bookingReference,
            'provider' => $provider,
            'local_provider_metadata_folio_id_present' => $localFolioPresent && (string) ($folio['source'] ?? '') !== 'must_clock_folio_accounting.clock_folio_id',
            'clock_accounting_folio_id_present' => $accountingFolioId !== '',
            'clock_folio_id_present' => $ready || $readyAfterClockFetch,
            'clock_folio_id_source' => (string) ($folio['source'] ?? ''),
            'clock_folio_id_value_masked' => $ready || $readyAfterClockFetch ? '[present]' : '',
            'clock_fetch_checked' => !empty($clockFetch['checked']),
            'clock_fetch_status' => (string) ($clockFetch['status'] ?? ''),
            'clock_fetch_message' => (string) ($clockFetch['message'] ?? ''),
            'clock_fetch_folio_id_present' => !empty($clockFetch['folio_id_present']),
            'clock_fetch_folio_id_source' => (string) ($clockFetch['folio_id_source'] ?? ''),
        ];
    }
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function checkClockFolioByFetchingBooking(array $row): array
    {
        $externalId = \sanitize_text_field((string) ($row['provider_booking_id'] ?? ''));
        if ($externalId === '') {
            $externalId = \sanitize_text_field((string) ($row['provider_reservation_id'] ?? ''));
        }
        if ($externalId === '') {
            return [
                'checked' => true,
                'status' => 'missing_clock_booking_id',
                'message' => 'No Clock booking ID is available for a read-only folio readiness fetch.',
                'folio_id_present' => false,
                'folio_id_source' => '',
            ];
        }
        $path = ClockConfig::reservationFetchPath();
        if ($path === '') {
            $path = '/bookings/{booking_id}';
        }
        $path = \str_replace(
            ['{booking_id}', '{reservation_id}', '{id}', '{clock_booking_id}'],
            \rawurlencode($externalId),
            $path
        );
        $response = (new ClockApiClient())->request(
            'GET',
            $path,
            [
                'api_type' => 'pms_api',
                'reservation_id' => (int) ($row['reservation_id'] ?? 0),
                'external_id' => $externalId,
            ],
            'clock.reservation_folio_readiness_check'
        );
        if (!$response->isSuccess()) {
            return [
                'checked' => true,
                'status' => 'clock_fetch_failed',
                'message' => 'Clock booking fetch did not succeed.',
                'folio_id_present' => false,
                'folio_id_source' => '',
            ];
        }
        $data = $response->getData();
        $folio = self::findClockFolioIdInClockResponse(\is_array($data) ? $data : []);
        $folioPresent = (string) ($folio['value'] ?? '') !== '';
        return [
            'checked' => true,
            'status' => $folioPresent ? 'folio_found_in_clock_response' : 'folio_missing_in_clock_response',
            'message' => $folioPresent
                ? 'Read-only Clock booking fetch found a folio ID in the Clock response.'
                : 'Read-only Clock booking fetch did not find a folio ID in the Clock response.',
            'folio_id_present' => $folioPresent,
            'folio_id_source' => (string) ($folio['source'] ?? ''),
        ];
    }
    /**
     * @return array<string, string>
     */
    private static function getLatestStripePaymentSummary(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'must_payments';
        if (!self::tableExists($table) || !self::columnExists($table, 'method') || !self::columnExists($table, 'status')) {
            return [];
        }
        $paidAtColumn = self::columnExists($table, 'paid_at') ? 'paid_at' : 'created_at';
        $createdColumn = self::columnExists($table, 'created_at') ? 'created_at' : $paidAtColumn;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT `' . $paidAtColumn . '` AS paid_at, `' . $createdColumn . '` AS created_at
                FROM `' . $table . '`
                WHERE method = %s AND status = %s
                ORDER BY COALESCE(`' . $paidAtColumn . '`, `' . $createdColumn . '`) DESC, id DESC
                LIMIT 1',
                'stripe',
                'paid'
            ),
            ARRAY_A
        );
        return \is_array($row)
            ? [
                'paid_at' => \sanitize_text_field((string) ($row['paid_at'] ?? '')),
                'created_at' => \sanitize_text_field((string) ($row['created_at'] ?? '')),
            ]
            : [];
    }
    private static function getLatestStripeError(): string
    {
        $activity = self::getLatestActivityMessage(['stripe_webhook_failed']);
        if ((string) ($activity['message'] ?? '') !== '') {
            return (string) $activity['message'];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'mhb_provider_request_logs';
        if (!self::tableExists($table) || !self::columnExists($table, 'operation') || !self::columnExists($table, 'success') || !self::columnExists($table, 'error_message')) {
            return '';
        }
        $message = $wpdb->get_var(
            "SELECT error_message FROM `{$table}` WHERE operation LIKE 'stripe.%' AND success = 0 AND error_message <> '' ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        return \is_string($message) ? self::maskSensitiveText($message) : '';
    }
    /**
     * @param array<int, string> $eventTypes
     */
    private static function getLatestActivityAt(array $eventTypes): string
    {
        $row = self::getLatestActivityMessage($eventTypes);
        return (string) ($row['created_at'] ?? '');
    }
    /**
     * @param array<int, string> $eventTypes
     * @return array<string, string>
     */
    private static function getLatestActivityMessage(array $eventTypes): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'must_activity_log';
        if (empty($eventTypes) || !self::tableExists($table) || !self::columnExists($table, 'event_type')) {
            return [];
        }
        $eventTypes = \array_values(\array_filter(\array_map('sanitize_key', $eventTypes)));
        if (empty($eventTypes)) {
            return [];
        }
        $placeholders = \implode(',', \array_fill(0, \count($eventTypes), '%s'));
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT created_at, message FROM `' . $table . '` WHERE event_type IN (' . $placeholders . ') ORDER BY created_at DESC, id DESC LIMIT 1',
                ...$eventTypes
            ),
            ARRAY_A
        );
        return \is_array($row)
            ? [
                'created_at' => \sanitize_text_field((string) ($row['created_at'] ?? '')),
                'message' => self::maskSensitiveText((string) ($row['message'] ?? '')),
            ]
            : [];
    }
    private static function getLatestProviderRequestAt(string $operation, bool $success): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhb_provider_request_logs';
        if (!self::tableExists($table) || !self::columnExists($table, 'operation') || !self::columnExists($table, 'success')) {
            return '';
        }
        $createdAt = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT created_at FROM `' . $table . '` WHERE provider = %s AND operation = %s AND success = %d ORDER BY created_at DESC, id DESC LIMIT 1',
                ProviderManager::CLOCK_MODE,
                $operation,
                $success ? 1 : 0
            )
        );
        return \is_string($createdAt) ? \sanitize_text_field($createdAt) : '';
    }
    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonObject(string $json): array
    {
        $decoded = \json_decode($json, true);
        return \is_array($decoded) ? $decoded : [];
    }
    /**
     * @param array<string, mixed> $metadata
     * @return array{value:string,source:string}
     */
    private static function findClockFolioIdInMetadata(array $metadata): array
    {
        foreach (['clock_folio_id', 'folio_id', 'default_folio_id'] as $key) {
            if (isset($metadata[$key]) && \trim((string) $metadata[$key]) !== '') {
                return [
                    'value' => \sanitize_text_field((string) $metadata[$key]),
                    'source' => $key,
                ];
            }
        }
        $providerResponse = isset($metadata['provider_response']) && \is_array($metadata['provider_response'])
            ? $metadata['provider_response']
            : [];
        foreach (['clock_folio_id', 'folio_id', 'default_folio_id'] as $key) {
            if (isset($providerResponse[$key]) && \trim((string) $providerResponse[$key]) !== '') {
                return [
                    'value' => \sanitize_text_field((string) $providerResponse[$key]),
                    'source' => 'provider_response.' . $key,
                ];
            }
        }
        foreach (['folios', 'folio', 'accounts'] as $containerKey) {
            if (!isset($providerResponse[$containerKey])) {
                continue;
            }
            $container = $providerResponse[$containerKey];
            if (\is_array($container)) {
                $found = self::findFirstIdInNestedArray($container, $containerKey);
                if ((string) ($found['value'] ?? '') !== '') {
                    return [
                        'value' => (string) $found['value'],
                        'source' => (string) $found['source'],
                    ];
                }
            }
        }
        return [
            'value' => '',
            'source' => '',
        ];
    }
    /**
     * @param array<string, mixed> $source
     * @return array{value:string,source:string}
     */
    private static function findClockFolioIdInClockResponse(array $source): array
    {
        foreach (['clock_folio_id', 'folio_id', 'default_folio_id'] as $key) {
            if (isset($source[$key]) && \trim((string) $source[$key]) !== '') {
                return [
                    'value' => \sanitize_text_field((string) $source[$key]),
                    'source' => $key,
                ];
            }
        }
        $providerResponse = isset($source['provider_response']) && \is_array($source['provider_response'])
            ? $source['provider_response']
            : [];
        foreach (['clock_folio_id', 'folio_id', 'default_folio_id'] as $key) {
            if (isset($providerResponse[$key]) && \trim((string) $providerResponse[$key]) !== '') {
                return [
                    'value' => \sanitize_text_field((string) $providerResponse[$key]),
                    'source' => 'provider_response.' . $key,
                ];
            }
        }
        foreach (['folio', 'folios', 'account', 'accounts'] as $containerKey) {
            if (isset($source[$containerKey]) && \is_array($source[$containerKey])) {
                $found = self::findFirstIdInNestedArray($source[$containerKey], $containerKey);
                if ((string) ($found['value'] ?? '') !== '') {
                    return [
                        'value' => (string) $found['value'],
                        'source' => (string) $found['source'],
                    ];
                }
            }
            if (isset($providerResponse[$containerKey]) && \is_array($providerResponse[$containerKey])) {
                $found = self::findFirstIdInNestedArray($providerResponse[$containerKey], 'provider_response.' . $containerKey);
                if ((string) ($found['value'] ?? '') !== '') {
                    return [
                        'value' => (string) $found['value'],
                        'source' => (string) $found['source'],
                    ];
                }
            }
        }
        return [
            'value' => '',
            'source' => '',
        ];
    }
    /**
     * @param array<mixed> $source
     * @return array{value:string,source:string}
     */
    private static function findFirstIdInNestedArray(array $source, string $path): array
    {
        foreach ($source as $key => $value) {
            $currentPath = $path . '.' . (string) $key;
            if (\is_array($value)) {
                $nested = self::findFirstIdInNestedArray($value, $currentPath);
                if ((string) ($nested['value'] ?? '') !== '') {
                    return $nested;
                }
                continue;
            }
            if (\in_array((string) $key, ['id', 'folio_id', 'default_folio_id', 'clock_folio_id'], true) && \trim((string) $value) !== '') {
                return [
                    'value' => \sanitize_text_field((string) $value),
                    'source' => $currentPath,
                ];
            }
        }
        return [
            'value' => '',
            'source' => '',
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
            'failed_reason',
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
            'pin',
            'self_service_key',
            'self_service_pin',
            'door_code',
            'door_codes',
            'common_door_codes',
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
        return ProviderDataSanitizer::sanitizeText(\sanitize_text_field($value));
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
