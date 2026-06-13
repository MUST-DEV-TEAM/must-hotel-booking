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
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Provider\Clock\ClockApiClient;
use MustHotelBooking\Provider\Clock\ClockConfig;
use MustHotelBooking\Provider\Clock\ClockEndpointRegistry;
use MustHotelBooking\Provider\Sync\ProviderSyncJobRunner;

global $wpdb;

$reservation = latestClockReservation($wpdb);
$clockBookingId = (string) ($reservation['provider_booking_id'] ?? '');
if ($clockBookingId === '') {
    $clockBookingId = (string) ($reservation['provider_reservation_id'] ?? '');
}

$client = new ClockApiClient();
$clockFetch = $clockBookingId !== ''
    ? probeClock($client, 'GET', reservationFetchPath($clockBookingId), 'booking', ['api_type' => 'pms_api'])
    : skipped('No local Clock booking ID was available for booking fetch preflight.');
$clockFolios = $clockBookingId !== ''
    ? probeClock($client, 'GET', ClockEndpointRegistry::resolvePath('booking_folios_list', ['booking_id' => $clockBookingId]), 'booking_folios_list', ['api_type' => ClockEndpointRegistry::apiType('booking_folios_list')])
    : skipped('No local Clock booking ID was available for folio list preflight.');
$folioId = firstFolioId($clockFolios['data'] ?? null);
$folioFetch = $folioId !== ''
    ? probeClock($client, 'GET', ClockEndpointRegistry::resolvePath('folio_view', ['folio_id' => $folioId]), 'folio_view', ['api_type' => ClockEndpointRegistry::apiType('folio_view')])
    : skipped('No Clock folio ID was available from folio list response.');

$latestPokPayOrder = latestPaymentTransaction($wpdb, 'pokpay');
$latestStripeReference = latestPaymentTransaction($wpdb, 'stripe');

$report = [
    'tool' => 'provider-preflight-report',
    'mode' => 'read_only',
    'mutations' => 'not_performed',
    'clock' => [
        'environment' => ClockConfig::environment(),
        'configured' => ClockConfig::isConfigured(),
        'api_user_set' => ClockConfig::apiUser() !== '',
        'api_key_set' => ClockConfig::apiKey() !== '',
        'webhook_secret_set' => ClockConfig::webhookSecret() !== '',
        'webhook_url_public' => isPublicUrl(MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/clock/webhook')),
        'webhook_url' => MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/clock/webhook'),
        'auto_sync_enabled' => ClockConfig::autoSyncEnabled(),
        'auto_sync_cron_hook' => ProviderSyncJobRunner::getCronHook(),
        'latest_local_reservation_id' => (int) ($reservation['id'] ?? 0),
        'latest_clock_booking_id' => $clockBookingId,
        'booking_fetch' => summarizeProbe($clockFetch),
        'booking_folios_list' => summarizeProbe($clockFolios),
        'folio_fetch' => summarizeProbe($folioFetch),
        'payment_sub_types' => summarizeProbe(probeClock($client, 'GET', ClockEndpointRegistry::resolvePath('payment_sub_types'), 'payment_sub_types', ['api_type' => ClockEndpointRegistry::apiType('payment_sub_types')])),
        'write_endpoint_rights' => 'not_verified_read_only_preflight',
    ],
    'stripe' => [
        'active_environment' => PaymentEngine::getActiveSiteEnvironment(),
        'webhook_secret_set' => stripeWebhookSecretSet(),
        'webhook_url_public' => isPublicUrl(PaymentEngine::getStripeWebhookUrl()),
        'webhook_url' => PaymentEngine::getStripeWebhookUrl(),
        'latest_payment_reference_available' => $latestStripeReference !== '',
        'auth_probe' => summarizePaymentProbe(PaymentEngine::performStripeApiRequest('GET', 'balance')),
    ],
    'pokpay' => [
        'active_environment' => PaymentEngine::getActiveSiteEnvironment(),
        'checkout_mode' => MustBookingConfig::get_pokpay_checkout_mode(),
        'webhook_url_public' => isPublicUrl(MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook')),
        'webhook_url' => MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook'),
        'latest_sdk_order_available' => $latestPokPayOrder !== '',
        'latest_sdk_order_fetch' => $latestPokPayOrder !== ''
            ? summarizePaymentProbe(PaymentEngine::getPokPaySdkOrder($latestPokPayOrder))
            : skipped('No local PokPay SDK order reference was available for read-only fetch.'),
        'refund_endpoint_access' => 'not_verified_read_only_preflight',
    ],
    'blockers' => providerPreflightBlockers(),
];

echo \wp_json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;

/** @return array<string, mixed> */
function latestClockReservation(\wpdb $wpdb): array
{
    $table = $wpdb->prefix . 'must_reservations';
    $row = $wpdb->get_row(
        "SELECT id, booking_id, provider_booking_id, provider_reservation_id FROM `{$table}` WHERE provider = 'clock' AND provider_reservation_id <> '' ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );

    return \is_array($row) ? $row : [];
}

function latestPaymentTransaction(\wpdb $wpdb, string $method): string
{
    $table = $wpdb->prefix . 'must_payments';
    $value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT transaction_id FROM `{$table}` WHERE method = %s AND transaction_id <> '' ORDER BY id DESC LIMIT 1",
            $method
        )
    );

    return \is_scalar($value) ? \sanitize_text_field((string) $value) : '';
}

function reservationFetchPath(string $bookingId): string
{
    $path = ClockConfig::reservationFetchPath() !== '' ? ClockConfig::reservationFetchPath() : '/bookings/{booking_id}';

    return \str_replace(['{booking_id}', '{reservation_id}'], \rawurlencode($bookingId), $path);
}

/** @param array<string, mixed> $options @return array<string, mixed> */
function probeClock(ClockApiClient $client, string $method, string $path, string $endpointName, array $options = []): array
{
    if ($path === '') {
        return skipped('Endpoint path is not configured.');
    }

    $options['endpoint_name'] = $endpointName;
    $response = $client->request($method, $path, $options, 'preflight.' . $endpointName);

    return [
        'success' => $response->isSuccess(),
        'status_code' => $response->getStatusCode(),
        'error_code' => $response->getErrorCode(),
        'message' => $response->isSuccess() ? '' : $response->getErrorMessage(),
        'data' => $response->getData(),
    ];
}

/** @param mixed $probe @return array<string, mixed> */
function summarizeProbe($probe): array
{
    if (!\is_array($probe)) {
        return ['success' => false, 'message' => 'Invalid probe result.'];
    }

    return [
        'success' => !empty($probe['success']),
        'status_code' => isset($probe['status_code']) ? (int) $probe['status_code'] : 0,
        'error_code' => (string) ($probe['error_code'] ?? ''),
        'message' => (string) ($probe['message'] ?? ''),
        'data_shape' => dataShape($probe['data'] ?? null),
    ];
}

/** @param array<string, mixed> $probe @return array<string, mixed> */
function summarizePaymentProbe(array $probe): array
{
    $payload = null;
    foreach (['body', 'order', 'data'] as $key) {
        if (\array_key_exists($key, $probe)) {
            $payload = $probe[$key];
            break;
        }
    }

    return [
        'success' => !empty($probe['success']),
        'status_code' => isset($probe['status_code']) ? (int) $probe['status_code'] : 0,
        'message' => !empty($probe['success']) ? '' : \sanitize_text_field((string) ($probe['message'] ?? '')),
        'response_shape' => dataShape($payload),
    ];
}

/** @return array<string, mixed> */
function skipped(string $message): array
{
    return [
        'success' => false,
        'skipped' => true,
        'message' => $message,
    ];
}

/** @param mixed $data @return array<string, mixed> */
function dataShape($data): array
{
    if (!\is_array($data)) {
        return ['type' => \gettype($data)];
    }

    return [
        'type' => 'array',
        'is_list' => \array_keys($data) === \range(0, \count($data) - 1),
        'keys' => \array_slice(\array_map('strval', \array_keys($data)), 0, 12),
        'count' => \count($data),
    ];
}

/** @param mixed $data */
function firstFolioId($data): string
{
    if (!\is_array($data)) {
        return '';
    }

    if (isset($data['id']) && \is_scalar($data['id'])) {
        return \sanitize_text_field((string) $data['id']);
    }

    foreach (['folios', 'booking_folios', 'data', 'items', 'records'] as $key) {
        if (isset($data[$key]) && \is_array($data[$key])) {
            $found = firstFolioId($data[$key]);
            if ($found !== '') {
                return $found;
            }
        }
    }

    foreach ($data as $item) {
        if (\is_scalar($item) && \trim((string) $item) !== '') {
            return \sanitize_text_field((string) $item);
        }

        if (\is_array($item)) {
            $found = firstFolioId($item);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function isPublicUrl(string $url): bool
{
    $host = \strtolower((string) \parse_url($url, \PHP_URL_HOST));

    return $host !== '' && !\in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function stripeWebhookSecretSet(): bool
{
    $settings = MustBookingConfig::get_group_settings('payments_summary');

    return !empty($settings['stripe_webhook_secret']);
}

/** @return array<int, string> */
function providerPreflightBlockers(): array
{
    $blockers = [];

    if (ClockConfig::webhookSecret() === '') {
        $blockers[] = 'Clock webhook secret is not configured.';
    }

    if (!isPublicUrl(MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/clock/webhook'))) {
        $blockers[] = 'Clock webhook URL is not public.';
    }

    if (!stripeWebhookSecretSet()) {
        $blockers[] = 'Stripe webhook secret is not configured.';
    }

    if (!isPublicUrl(PaymentEngine::getStripeWebhookUrl())) {
        $blockers[] = 'Stripe webhook URL is not public.';
    }

    if (!isPublicUrl(MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook'))) {
        $blockers[] = 'PokPay webhook URL is not public.';
    }

    return $blockers;
}
