<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only.' . PHP_EOL;
    exit(1);
}

$root = \dirname(__DIR__, 4);
$wpLoad = $root . '/wp-load.php';

if (!\is_file($wpLoad)) {
    fwrite(STDERR, 'Unable to locate wp-load.php.' . PHP_EOL);
    exit(1);
}

require $wpLoad;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Provider\Clock\ClockConfig;
use MustHotelBooking\Provider\Clock\ClockEndpointRegistry;
use MustHotelBooking\Provider\ProviderManager;

global $wpdb;

$clock = MustBookingConfig::get_clock_settings();
$payments = MustBookingConfig::get_group_settings('payments_summary');
$tablePrefix = (string) $wpdb->prefix;
$activeSiteEnvironment = PaymentEngine::getActiveSiteEnvironment();
$activeStripeCredentials = PaymentEngine::getStripeEnvironmentCredentials($activeSiteEnvironment);

$tableExists = static function (string $table) use ($wpdb): bool {
    return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
};

$countRows = static function (string $table) use ($wpdb, $tableExists): int {
    if (!$tableExists($table)) {
        return 0;
    }

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
};

$latestRows = static function (string $table, string $orderColumn, array $columns) use ($wpdb, $tableExists): array {
    if (!$tableExists($table)) {
        return [];
    }

    $select = \implode(', ', \array_map(static fn (string $column): string => "`{$column}`", $columns));
    $orderColumn = \preg_replace('/[^a-zA-Z0-9_]/', '', $orderColumn);

    return (array) $wpdb->get_results("SELECT {$select} FROM `{$table}` ORDER BY `{$orderColumn}` DESC LIMIT 10", ARRAY_A);
};

$enabledPaymentMethods = PaymentMethodRegistry::getEnabled();
$availableCheckoutMethods = \array_keys(PaymentEngine::getCheckoutPaymentMethods());

$report = [
    'tool' => 'clock-accounting-environment-report',
    'mode' => 'read_only',
    'site' => [
        'home_url' => \function_exists('home_url') ? \home_url('/') : '',
        'site_url' => \function_exists('site_url') ? \site_url('/') : '',
        'rest_url' => \function_exists('rest_url') ? \rest_url('/') : '',
        'plugin_version' => \defined('MUST_HOTEL_BOOKING_VERSION') ? MUST_HOTEL_BOOKING_VERSION : '',
        'provider_mode' => ProviderManager::getConfiguredMode(),
        'active_provider' => ProviderManager::activeKey(),
        'active_site_environment' => $activeSiteEnvironment,
        'public_callback_base_url' => MustBookingConfig::get_public_callback_base_url(),
    ],
    'clock' => [
        'environment' => ClockConfig::environment(),
        'configured' => ClockConfig::isConfigured(),
        'pms_api_enabled' => ClockConfig::pmsApiEnabled(),
        'base_api_enabled' => ClockConfig::baseApiEnabled(),
        'pms_api_url' => ClockConfig::pmsApiUrl(),
        'base_api_url' => ClockConfig::baseApiUrl(),
        'resolved_pms_base_url' => ClockConfig::resolvedBaseUrl('pms_api'),
        'resolved_base_api_url' => ClockConfig::resolvedBaseUrl('base_api'),
        'api_user_set' => ClockConfig::apiUser() !== '',
        'api_key_set' => ClockConfig::apiKey() !== '',
        'posting_mode' => ClockConfig::paymentPostingMode(),
        'same_day_folio_payment_enabled' => ClockConfig::sameDayFolioPaymentEnabled(),
        'deposit_endpoint_configured' => ClockConfig::hasVerifiedDepositPaymentEndpoint(),
        'endpoint_overrides' => ClockConfig::endpointOverrides(),
        'endpoint_override_errors' => ClockEndpointRegistry::validateOverrides((array) ($clock['clock_endpoint_overrides'] ?? [])),
        'webhook_secret_set' => ClockConfig::webhookSecret() !== '',
        'webhook_url' => \function_exists('rest_url') ? MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/clock/webhook') : '',
    ],
    'payments' => [
        'enabled_methods' => $enabledPaymentMethods,
        'available_checkout_methods' => $availableCheckoutMethods,
        'stripe_local_secret_set' => !empty($payments['stripe_local_secret_key']),
        'stripe_staging_secret_set' => !empty($payments['stripe_staging_secret_key']),
        'stripe_production_secret_set' => !empty($payments['stripe_production_secret_key']),
        'stripe_webhook_secret_set' => !empty($activeStripeCredentials['webhook_secret']),
        'pokpay_checkout_mode' => (string) ($payments['pokpay_checkout_mode'] ?? ''),
        'pokpay_local_credentials_set' => !empty($payments['pokpay_local_merchant_id']) && !empty($payments['pokpay_local_key_id']) && !empty($payments['pokpay_local_key_secret']),
        'pokpay_staging_credentials_set' => !empty($payments['pokpay_staging_merchant_id']) && !empty($payments['pokpay_staging_key_id']) && !empty($payments['pokpay_staging_key_secret']),
        'pokpay_production_credentials_set' => !empty($payments['pokpay_production_merchant_id']) && !empty($payments['pokpay_production_key_id']) && !empty($payments['pokpay_production_key_secret']),
        'stripe_webhook_url' => \function_exists('rest_url') ? PaymentEngine::getStripeWebhookUrl() : '',
        'pokpay_webhook_url' => \function_exists('rest_url') ? MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook') : '',
    ],
    'counts' => [
        'reservations' => $countRows($tablePrefix . 'must_reservations'),
        'payments' => $countRows($tablePrefix . 'must_payments'),
        'refunds' => $countRows($tablePrefix . 'must_refunds'),
        'clock_accounting' => $countRows($tablePrefix . 'must_clock_folio_accounting'),
        'provider_logs' => $countRows($tablePrefix . 'mhb_provider_request_logs'),
    ],
    'latest' => [
        'reservations' => $latestRows($tablePrefix . 'must_reservations', 'id', ['id', 'booking_id', 'status', 'payment_status', 'provider', 'provider_reservation_id', 'checkin', 'checkout', 'created_at']),
        'payments' => $latestRows($tablePrefix . 'must_payments', 'id', ['id', 'reservation_id', 'amount', 'currency', 'method', 'status', 'transaction_id', 'paid_at', 'created_at']),
        'clock_accounting' => $latestRows($tablePrefix . 'must_clock_folio_accounting', 'id', ['id', 'payment_id', 'refund_id', 'reservation_id', 'booking_id', 'clock_booking_id', 'clock_folio_id', 'clock_credit_item_id', 'direction', 'amount', 'currency', 'status', 'last_error_code', 'attempts', 'updated_at']),
    ],
];

echo \wp_json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
