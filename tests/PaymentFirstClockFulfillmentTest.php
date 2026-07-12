<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$provider = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationProvider.php');
$payment = (string) \file_get_contents($root . '/src/Engine/PaymentEngine.php');
$failures = [];

if (\strpos($provider, 'public function fulfillPendingOnlinePayment') === false) {
    $failures[] = 'Clock provider must expose a post-payment fulfillment boundary.';
}

$start = \strpos($payment, 'private static function completeVerifiedOnlinePayment');
$end = \strpos($payment, 'private static function ', $start === false ? 0 : $start + 1);
$body = $start !== false && $end !== false ? \substr($payment, $start, $end - $start) : '';
$clock = \strpos($body, 'fulfillPendingOnlinePayment');
$paymentRows = \strpos($body, 'createPaymentRows');
$status = \strpos($body, 'updateReservationStatuses');

if ($body === '' || $clock === false || $paymentRows === false || $status === false) {
    $failures[] = 'Verified payment completion must include Clock fulfillment, payment recording, and confirmation.';
} elseif (!($clock < $paymentRows && $paymentRows < $status)) {
    $failures[] = 'Clock creation must precede payment recording, which must precede local confirmation.';
}

foreach (['amount_total', 'stripe_reservation_metadata_mismatch', 'currencyCode', 'reservationPaymentsMatchPokPayOrder'] as $marker) {
    if (\strpos($payment, $marker) === false) {
        $failures[] = 'Provider verification is missing the ' . $marker . ' check.';
    }
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Payment-first fulfillment test passed.\n";
