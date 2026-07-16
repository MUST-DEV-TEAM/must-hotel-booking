<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$source = (string) \file_get_contents(__DIR__ . '/../src/Provider/Clock/ClockAvailabilityProvider.php');
$javascript = (string) \file_get_contents(__DIR__ . '/../assets/js/booking-page.js');
$start = \strpos($source, 'public function getDisabledDates');
$end = \strpos($source, 'public function checkAvailability', $start !== false ? $start : 0);
$body = $start !== false && $end !== false ? \substr($source, $start, $end - $start) : '';
$failures = [];

foreach ([
    'availabilityTargetForDisabledDates',
    'clock.rates_availability.disabled_dates',
    'disabledCheckinDatesForPhysicalSelection',
    'disabledDatesFailOpen',
] as $required) {
    if ($body === '' || \strpos($body, $required) === false) {
        $failures[] = 'Disabled-date advisory flow is missing ' . $required . '.';
    }
}

if (\strpos($body, 'roomStatuses->fetch') !== false) {
    $failures[] = 'The 180-day advisory calendar must not scan room_statuses.';
}
if (\strpos($source, "'selector' => self::AVAILABILITY_SELECTOR_ROOM_TYPES") === false) {
    $failures[] = 'Disabled-date targets must use parent room_types[].';
}
if (\strpos($source, 'AVAILABILITY_SELECTOR_ROOMS') !== false) {
    $failures[] = 'Exact physical rooms[] must not be sent to rates_availability.';
}
foreach ([
    "disabledDatesSource === 'clock_rates_availability'",
    "disabledDatesStatus !== 'ok'",
    'refreshUnavailableDayClasses();',
] as $marker) {
    if (\strpos($javascript, $marker) === false) {
        $failures[] = 'Provider calendar failure must retain already-known unavailable dates: ' . $marker;
    }
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock exact-room disabled-date contract tests passed.\n";
