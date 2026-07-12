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
$failures = [];

$defer = \strpos($body, '$deferClockCreation');
$clockWrite = \strpos($body, 'createClockBooking');
$guard = \strpos($body, 'if (!$deferClockCreation)');
$mirror = \strpos($body, 'createMirrorReservation');

if ($body === '' || $defer === false || $guard === false || $mirror === false || $clockWrite === false) {
    $failures[] = 'Clock checkout must expose a deferred online-payment creation branch.';
} elseif ($defer > $guard || $guard > $clockWrite || $mirror < $guard) {
    $failures[] = 'Pending online checkout must bypass the Clock write and create only the local mirror.';
}

foreach (['defer_provider_creation', 'pending_guest_form', 'pending_clock_creation'] as $marker) {
    if (\strpos($provider, $marker) === false) {
        $failures[] = 'Deferred Clock checkout is missing the ' . $marker . ' state marker.';
    }
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Payment-first pending Clock test passed.\n";
