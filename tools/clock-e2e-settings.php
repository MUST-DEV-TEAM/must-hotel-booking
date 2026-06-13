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
use MustHotelBooking\Provider\Clock\ClockConfig;

$args = \array_slice($argv, 1);
$changes = [];

foreach ($args as $arg) {
    if (\strpos($arg, '--public-callback-base-url=') === 0) {
        $value = \substr($arg, \strlen('--public-callback-base-url='));
        $normalized = MustBookingConfig::normalize_public_callback_base_url((string) $value);

        if ((string) $value !== '' && $normalized === '') {
            fwrite(STDERR, 'Invalid public callback base URL.' . PHP_EOL);
            exit(1);
        }

        $changes['general']['public_callback_base_url'] = $normalized;
        continue;
    }

    if (\strpos($arg, '--clock-payment-posting-mode=') === 0) {
        $mode = \sanitize_key((string) \substr($arg, \strlen('--clock-payment-posting-mode=')));

        if (!\in_array($mode, ['auto_detect', 'deposit_for_future_bookings', 'folio_payment_only', 'manual_clock_accounting'], true)) {
            fwrite(STDERR, 'Invalid Clock payment posting mode.' . PHP_EOL);
            exit(1);
        }

        $changes['provider']['clock_payment_posting_mode'] = $mode;
        continue;
    }

    if (\strpos($arg, '--clock-same-day-folio-payment-enabled=') === 0) {
        $value = \strtolower((string) \substr($arg, \strlen('--clock-same-day-folio-payment-enabled=')));
        $changes['provider']['clock_same_day_folio_payment_enabled'] = \in_array($value, ['1', 'yes', 'true', 'on'], true);
        continue;
    }

    fwrite(STDERR, 'Unknown argument: ' . $arg . PHP_EOL);
    exit(1);
}

if (empty($changes)) {
    fwrite(STDERR, 'No changes requested.' . PHP_EOL);
    exit(1);
}

foreach ($changes as $group => $values) {
    MustBookingConfig::set_group_settings((string) $group, (array) $values);
}

echo \wp_json_encode([
    'success' => true,
    'public_callback_base_url' => MustBookingConfig::get_public_callback_base_url(),
    'stripe_webhook_url' => PaymentEngine::getStripeWebhookUrl(),
    'pokpay_webhook_url' => MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook'),
    'clock_webhook_url' => ClockConfig::summary()['clock_webhook_url'] ?? '',
    'clock_payment_posting_mode' => ClockConfig::paymentPostingMode(),
    'clock_same_day_folio_payment_enabled' => ClockConfig::sameDayFolioPaymentEnabled() ? 'yes' : 'no',
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
