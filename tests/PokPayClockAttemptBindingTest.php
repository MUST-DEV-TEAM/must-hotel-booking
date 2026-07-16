<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$reservations = (string) \file_get_contents($root . '/src/Database/ReservationRepository.php');
$pokpay = (string) \file_get_contents($root . '/src/Engine/Payment/PokPayPayment.php');
$failures = [];

$reservationStart = \strpos($reservations, 'public function getReservationsByIds');
$reservationEnd = \strpos($reservations, 'public function updateReservationStatus', $reservationStart === false ? 0 : $reservationStart);
$reservationBody = $reservationStart !== false && $reservationEnd !== false
    ? \substr($reservations, $reservationStart, $reservationEnd - $reservationStart)
    : '';

if ($reservationBody === '' || \strpos($reservationBody, 'provider,') === false) {
    $failures[] = 'Payment-attempt reservation hydration must include the reservation provider.';
}

$paymentStart = \strpos($pokpay, 'public function processPayment');
$paymentEnd = \strpos($pokpay, 'public function refundPayment', $paymentStart === false ? 0 : $paymentStart);
$paymentBody = $paymentStart !== false && $paymentEnd !== false
    ? \substr($pokpay, $paymentStart, $paymentEnd - $paymentStart)
    : '';

$persist = \strpos($paymentBody, 'BookingStatusEngine::createPaymentRows');
$preflight = \strpos($paymentBody, 'validateReusablePendingPaymentAttempt');
$providerCreate = \strpos($paymentBody, 'PaymentEngine::createPokPaySdkOrder');
$rebind = \strpos($paymentBody, 'provider_attempt_rebind_failed');
$reread = \strpos($paymentBody, 'pending_attempt_rebind_invalid');

if ($paymentBody === '' || $persist === false || $preflight === false || $providerCreate === false || $rebind === false || $reread === false) {
    $failures[] = 'PokPay checkout must persist, reread, bind, and reread its immutable attempt identity.';
} elseif (!($persist < $preflight && $preflight < $providerCreate && $providerCreate < $rebind && $rebind < $reread)) {
    $failures[] = 'PokPay must durably persist and reread immutable bindings before provider order creation, then reread the provider-bound attempt.';
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "PokPay Clock attempt binding test passed.\n";
