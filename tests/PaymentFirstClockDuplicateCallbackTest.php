<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$provider = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationProvider.php');
$repository = (string) \file_get_contents($root . '/src/Database/ReservationRepository.php');
$payment = (string) \file_get_contents($root . '/src/Engine/PaymentEngine.php');
$status = (string) \file_get_contents($root . '/src/Engine/BookingStatusEngine.php');
$failures = [];

foreach (['claimPendingClockReservation', 'provider_sync_status', 'idempotency_key'] as $marker) {
    if (\strpos($provider . $repository, $marker) === false) {
        $failures[] = 'Duplicate Clock fulfillment is missing the ' . $marker . ' guard.';
    }
}

foreach (['providerPaymentAlreadyCompleted', 'stripeCompletedSessionAlreadyRecorded'] as $marker) {
    if (\strpos($payment, $marker) === false) {
        $failures[] = 'Duplicate payment completion is missing the ' . $marker . ' protection.';
    }
}

if (\strpos($payment, 'hasPendingClockFulfilment') === false) {
    $failures[] = 'Expired-payment cleanup can overwrite a pending Clock fulfillment.';
}

foreach (['payment_recorded', 'reservation_confirmed'] as $marker) {
    if (\strpos($status, $marker) === false) {
        $failures[] = 'Duplicate side-effect protection is missing the ' . $marker . ' hook.';
    }
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Payment-first duplicate callback test passed.\n";
