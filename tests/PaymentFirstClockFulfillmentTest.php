<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$payment = (string) \file_get_contents($root . '/src/Engine/PaymentEngine.php');
$failures = [];

$cleanupStart = \strpos($payment, 'public static function cleanupExpiredPendingPaymentReservations');
$cleanupEnd = \strpos($payment, 'public static function registerPaymentRestRoutes', $cleanupStart === false ? 0 : $cleanupStart + 1);
$cleanup = $cleanupStart !== false && $cleanupEnd !== false ? \substr($payment, $cleanupStart, $cleanupEnd - $cleanupStart) : '';

$resumeCall = \strpos($cleanup, 'resumeVerifiedPendingClockFulfilment');
$preserveGuard = \strpos($cleanup, 'hasPendingClockFulfilment');
if ($cleanup === '' || $resumeCall === false) {
    $failures[] = 'Expired-payment cleanup must resume a durably verified pending Clock fulfilment.';
} elseif ($preserveGuard !== false && $resumeCall > $preserveGuard) {
    $failures[] = 'Verified pending Clock fulfilment must be resumed before the cleanup preservation guard.';
}

$helperStart = \strpos($payment, 'private static function resumeVerifiedPendingClockFulfilment');
$helperEnd = \strpos($payment, 'private static function ', $helperStart === false ? 0 : $helperStart + 1);
$helper = $helperStart !== false && $helperEnd !== false ? \substr($payment, $helperStart, $helperEnd - $helperStart) : '';

foreach ([
    'PaymentVerificationRepository',
    'getForReservation',
    'attempt_status',
    'provider_attempt_reference',
    'provider_transaction_reference',
    'pending_fulfilment',
    'provider_booking_id',
    'provider_reservation_id',
    'RefundRepository',
    'getRefundsForReservation',
    'validateFinalization',
    'fulfillPendingOnlinePayment',
    'recordAndConfirm',
] as $marker) {
    if ($helper === '' || \strpos($helper, $marker) === false) {
        $failures[] = 'Clock fulfilment recovery is missing the ' . $marker . ' safety guard.';
    }
}

foreach (['getPokPaySdkOrder', 'getStripeCheckoutSession', 'search'] as $forbidden) {
    if ($helper !== '' && \stripos($helper, $forbidden) !== false) {
        $failures[] = 'First-time Clock fulfilment recovery must not perform provider correlation searches.';
    }
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Payment-first fulfillment test passed.\n";
