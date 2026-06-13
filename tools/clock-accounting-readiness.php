<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This Clock accounting readiness tool is CLI-only.' . PHP_EOL;
    exit(1);
}

$root = \dirname(__DIR__, 4);
$wpLoad = $root . '/wp-load.php';

if (!\is_file($wpLoad)) {
    fwrite(STDERR, 'Unable to locate wp-load.php. Run this tool from the plugin checkout in a local WordPress install.' . PHP_EOL);
    exit(1);
}

require $wpLoad;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Provider\Clock\ClockConfig;
use MustHotelBooking\Provider\Clock\ClockEndpointRegistry;

$args = \array_slice($argv, 1);
$realClockBookingId = '';

foreach ($args as $arg) {
    if (\strpos($arg, '--real-clock-booking-id=') === 0) {
        $realClockBookingId = \trim((string) \substr($arg, \strlen('--real-clock-booking-id=')));
    }
}

$settings = MustBookingConfig::get_clock_settings();
$overrides = isset($settings['clock_endpoint_overrides']) && \is_array($settings['clock_endpoint_overrides'])
    ? $settings['clock_endpoint_overrides']
    : [];

$report = [
    'tool' => 'clock-accounting-readiness',
    'mode' => 'read_only',
    'remote_clock_writes' => 'not_performed',
    'synthetic_clock_ids' => 'not_allowed',
    'real_clock_booking_id_supplied' => $realClockBookingId !== '' ? 'yes' : 'no',
    'clock_configured' => ClockConfig::isConfigured() ? 'yes' : 'no',
    'clock_payment_posting_mode' => ClockConfig::paymentPostingMode(),
    'clock_same_day_folio_payment_enabled' => ClockConfig::sameDayFolioPaymentEnabled() ? 'yes' : 'no',
    'clock_deposit_endpoint_configured' => ClockConfig::hasVerifiedDepositPaymentEndpoint() ? 'yes' : 'no',
    'endpoint_override_errors' => ClockEndpointRegistry::validateOverrides($overrides),
    'message' => 'Use this tool only for local readiness checks. Real Clock folio/deposit tests require real Clock booking and folio IDs, explicit operator review, and a separate write-enabled test plan.',
];

echo \wp_json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
