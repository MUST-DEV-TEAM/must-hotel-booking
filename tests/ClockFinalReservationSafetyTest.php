<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$provider = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationProvider.php');
$start = \strpos($provider, 'public function createReservations');
$end = \strpos($provider, 'private function precheckSelectionSource');
$body = $start !== false && $end !== false ? \substr($provider, $start, $end - $start) : '';
$write = \strpos($body, 'createClockBooking');
$checks = [
    'server quote load' => \strpos($body, 'get_booking_selection_flow_data'),
    'signed quote validation' => \strpos($body, 'BookingQuoteDraft::isValidFor'),
    'fresh availability' => \strpos($body, 'checkAvailabilityFresh'),
    'fresh price' => \strpos($body, 'calculateTotalFresh'),
    'price comparison' => \strpos($body, 'BookingQuoteDraft::pricingMatches'),
    'guarantee comparison' => \strpos($body, 'BookingQuoteDraft::guaranteePolicyMatches'),
];
$failures = [];

if ($body === '' || $write === false) {
    $failures[] = 'Clock reservation creation source could not be inspected.';
}

foreach ($checks as $label => $position) {
    if ($position === false || ($write !== false && $position > $write)) {
        $failures[] = $label . ' must occur before the Clock booking write.';
    }
}

foreach ([
    "\$context['checkin'] = (string) (\$quoteDraft['checkin']",
    "\$context['checkout'] = (string) (\$quoteDraft['checkout']",
    "\$context['guests'] = (int) (\$quoteDraft['guests']",
    "\$couponCode = (string) (\$quoteDraft['coupon_code']",
] as $trustedAssignment) {
    if (\strpos($body, $trustedAssignment) === false) {
        $failures[] = 'Final reservation input must be restored from the verified server-side quote, not request values.';
        break;
    }
}

if (
    \strpos($body, 'No reservation or payment was created.') === false
    || \strpos($body, 'price changed since you reviewed') === false
    || \strpos($body, 'no longer available') === false
) {
    $failures[] = 'Expired, changed-price, and unavailable-room failures must return controlled customer messages.';
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock final reservation safety tests passed.\n";
