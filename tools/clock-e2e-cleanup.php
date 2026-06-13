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

use MustHotelBooking\Engine\BookingStatusEngine;
use function MustHotelBooking\Engine\get_payment_repository;
use function MustHotelBooking\Engine\get_reservation_repository;

$args = \array_slice($argv, 1);
$reservationId = 0;
$method = 'stripe';
$status = 'cancelled';

foreach ($args as $arg) {
    if (\strpos($arg, '--reservation-id=') === 0) {
        $reservationId = \absint(\substr($arg, \strlen('--reservation-id=')));
        continue;
    }

    if (\strpos($arg, '--method=') === 0) {
        $method = \sanitize_key((string) \substr($arg, \strlen('--method=')));
        continue;
    }

    if (\strpos($arg, '--status=') === 0) {
        $status = \sanitize_key((string) \substr($arg, \strlen('--status=')));
        continue;
    }

    fwrite(STDERR, 'Unknown argument: ' . $arg . PHP_EOL);
    exit(1);
}

if ($reservationId <= 0) {
    fwrite(STDERR, 'Missing --reservation-id.' . PHP_EOL);
    exit(1);
}

if (!\in_array($status, ['payment_failed', 'expired', 'cancelled'], true)) {
    fwrite(STDERR, 'Invalid --status.' . PHP_EOL);
    exit(1);
}

if ($method === '') {
    fwrite(STDERR, 'Invalid --method.' . PHP_EOL);
    exit(1);
}

$beforeRows = get_reservation_repository()->getReservationsByIds([$reservationId]);
$before = isset($beforeRows[0]) && \is_array($beforeRows[0]) ? $beforeRows[0] : [];

if (empty($before)) {
    fwrite(STDERR, 'Reservation not found.' . PHP_EOL);
    exit(1);
}

BookingStatusEngine::failPendingPaymentReservations([$reservationId], $method, $status);

$afterRows = get_reservation_repository()->getReservationsByIds([$reservationId]);
$after = isset($afterRows[0]) && \is_array($afterRows[0]) ? $afterRows[0] : [];
$payments = get_payment_repository()->getPaymentsForReservation($reservationId);
$latestPayment = isset($payments[0]) && \is_array($payments[0]) ? $payments[0] : [];

echo \wp_json_encode([
    'success' => true,
    'reservation_id' => $reservationId,
    'method' => $method,
    'requested_status' => $status,
    'before' => [
        'booking_id' => (string) ($before['booking_id'] ?? ''),
        'status' => (string) ($before['status'] ?? ''),
        'payment_status' => (string) ($before['payment_status'] ?? ''),
        'provider' => (string) ($before['provider'] ?? ''),
        'provider_reservation_id' => (string) ($before['provider_reservation_id'] ?? ''),
    ],
    'after' => [
        'booking_id' => (string) ($after['booking_id'] ?? ''),
        'status' => (string) ($after['status'] ?? ''),
        'payment_status' => (string) ($after['payment_status'] ?? ''),
        'provider' => (string) ($after['provider'] ?? ''),
        'provider_reservation_id' => (string) ($after['provider_reservation_id'] ?? ''),
    ],
    'latest_payment' => [
        'id' => (int) ($latestPayment['id'] ?? 0),
        'status' => (string) ($latestPayment['status'] ?? ''),
        'transaction_id' => (string) ($latestPayment['transaction_id'] ?? ''),
    ],
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
