<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$source = (string) \file_get_contents(__DIR__ . '/../src/Provider/Clock/ClockReservationProvider.php');
$start = \strpos($source, 'private function createClockReservationForPendingPayment');
$end = \strpos($source, 'private function pendingGuestForm', $start !== false ? $start : 0);
$body = $start !== false && $end !== false ? \substr($source, $start, $end - $start) : '';
$failures = [];
$availabilityCheck = \strpos($body, 'checkAvailabilityFresh');
$clockWrite = \strpos($body, 'createClockBooking');

if ($body === '' || $availabilityCheck === false || $clockWrite === false || $availabilityCheck > $clockWrite) {
    $failures[] = 'Paid fulfilment must validate exact-room availability before Clock POST.';
}

foreach ([
    '$assignedRoomId',
    '$guests',
    '$ratePlanId',
    "'paid_fulfilment'",
    "'clock_exact_room_provider_unconfirmed'",
    "'clock_exact_room_unavailable'",
    "'clock_exact_room_mapping_missing'",
    "'clock_exact_room_mapping_drift'",
] as $required) {
    if (\strpos($body, $required) === false) {
        $failures[] = 'Paid exact-room fulfilment is missing ' . $required . '.';
    }
}

if (\strpos($source, "(string) (\$result['reason_code'] ?? 'clock_create_requires_reread')") === false) {
    $failures[] = 'Paid availability failures must preserve their precise manual-review reason.';
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock exact-room paid-fulfilment availability contract tests passed.\n";
