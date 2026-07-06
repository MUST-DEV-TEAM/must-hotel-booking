<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$mustConfig = (string) \file_get_contents($root . '/src/Core/MustBookingConfig.php');
$settingsPage = (string) \file_get_contents($root . '/src/Admin/SettingsPage.php');
$settingsDiagnostics = (string) \file_get_contents($root . '/src/Admin/SettingsDiagnostics.php');
$bookingPage = (string) \file_get_contents($root . '/src/Frontend/booking-page.php');
$bookingTemplate = (string) \file_get_contents($root . '/frontend/templates/booking.php');
$checkoutTemplate = (string) \file_get_contents($root . '/frontend/templates/checkout.php');
$bookingJs = (string) \file_get_contents($root . '/assets/js/booking-page.js');
$bookingCss = (string) \file_get_contents($root . '/assets/css/booking-page.css');
$emailEngine = (string) \file_get_contents($root . '/src/Engine/EmailEngine.php');
$quoteDraft = (string) \file_get_contents($root . '/src/Engine/BookingQuoteDraft.php');
$reservationEngine = (string) \file_get_contents($root . '/src/Engine/ReservationEngine.php');
$clockMirror = (string) \file_get_contents($root . '/src/Provider/Clock/ClockMirrorReservationService.php');
$reservationRepository = (string) \file_get_contents($root . '/src/Database/ReservationRepository.php');

$assert(\strpos($mustConfig, "'date_picker_calendar_layout' => 'two_calendars'") !== false, 'Default date picker calendar layout must preserve current two-calendar behavior.');
$assert(\strpos($mustConfig, "'checkout_price_breakdown_mode' => 'total_only'") !== false, 'Default checkout price breakdown mode must preserve current total-only behavior.');
$assert(\strpos($mustConfig, "'email_price_breakdown_mode' => 'total_only'") !== false, 'Default email price breakdown mode must preserve current total-only behavior.');
$assert(\strpos($mustConfig, 'get_date_picker_calendar_layout') !== false, 'Config must expose a sanitized date picker layout getter.');
$assert(\strpos($mustConfig, 'get_checkout_price_breakdown_mode') !== false, 'Config must expose a sanitized checkout breakdown getter.');
$assert(\strpos($mustConfig, 'get_email_price_breakdown_mode') !== false, 'Config must expose a sanitized email breakdown getter.');
$assert(\strpos($mustConfig, "['one_calendar', 'two_calendars']") !== false, 'Config must validate allowed calendar layout values.');
$assert(\strpos($mustConfig, "['total_only', 'date_price_rows']") !== false, 'Config must validate allowed price breakdown mode values.');

foreach (['date_picker_calendar_layout', 'checkout_price_breakdown_mode', 'email_price_breakdown_mode'] as $settingKey) {
    $assert(\strpos($settingsPage, $settingKey) !== false, 'Settings UI must expose ' . $settingKey . '.');
    $assert(\strpos($settingsDiagnostics, $settingKey) !== false, 'Diagnostics must expose ' . $settingKey . '.');
}

$assert(\strpos($bookingPage, 'calendarLayout') !== false, 'Booking page localized config must include active calendar layout.');
$assert(\strpos($bookingTemplate, 'data-calendar-layout') !== false, 'Booking template must expose active calendar layout for JS/CSS.');
$assert(\strpos($bookingTemplate, 'is-one-calendar') !== false, 'Booking template must be able to render one-calendar mode.');
$assert(\strpos($bookingTemplate, 'must-booking-cal-next-inline') !== false, 'One-calendar mode must render a visible next-month control outside the hidden checkout calendar shell.');
$assert(\strpos($bookingJs, 'one_calendar') !== false, 'Booking JS must branch for one-calendar behavior.');
$assert(\strpos($bookingJs, 'handleCalendarDateSelection') !== false, 'Booking JS must keep one-calendar date range selection centralized.');
$assert(\strpos($bookingJs, 'getSingleCalendarUnavailableDates') !== false, 'One-calendar mode must keep combined unavailable dates visible during the selection flow.');
$assert(\strpos($bookingJs, 'isSingleCalendarUnavailableDate') !== false, 'One-calendar mode must ignore clicks on unavailable dates.');
$assert(\strpos($bookingJs, 'must-booking-cal-next-inline') !== false, 'One-calendar mode must bind the visible inline next-month control.');
$assert(\strpos($bookingCss, 'aspect-ratio: 1 / 1') !== false, 'Calendar day cells must be square.');
$assert(\strpos($bookingCss, '--must-booking-calendar-day-size') !== false, 'Calendar day grid must use a controlled responsive day size.');
$assert(\strpos($bookingCss, '--must-booking-calendar-day-size: 75px') !== false, 'Calendar day cells must be capped at 75px.');
$assert(\strpos($bookingCss, 'max-width: 75px') !== false, 'Calendar day cells must not expand beyond 75px.');
$assert(\strpos($bookingCss, 'justify-content: space-between') !== false, 'Calendar day grid must accommodate capped cells across the panel width.');
$assert(\strpos($bookingCss, 'overflow: hidden') !== false, 'Unavailable date slash must be clipped inside the day cell.');
$assert(\strpos($bookingCss, 'border: 1px solid #141414') !== false, 'Unavailable date cells must keep a black border.');
$assert(\strpos($bookingCss, '.must-hotel-booking-page-booking.is-one-calendar .must-booking-cal-shift-prev.is-disabled') !== false, 'One-calendar previous month control must remain visible while disabled.');

$assert(\strpos($checkoutTemplate, 'checkout_price_breakdown_mode') !== false, 'Checkout template must read the checkout breakdown setting.');
$assert(\strpos($checkoutTemplate, 'render_checkout_price_breakdown_rows') !== false, 'Checkout template must render date-price rows through a helper.');
$assert(\strpos($emailEngine, 'email_price_breakdown_mode') !== false, 'Email rendering must read the email breakdown setting.');
$assert(\strpos($emailEngine, 'renderEmailPriceBreakdownRows') !== false, 'Email rendering must render date-price rows through a helper.');
$assert(\strpos($quoteDraft, "'nightly_rates'") !== false, 'Quote drafts must preserve nightly rate rows.');
$assert(\strpos($reservationEngine, 'pricing_snapshot') !== false, 'Local reservations must persist the stored pricing snapshot for later email rendering.');
$assert(\strpos($clockMirror, 'pricing_snapshot') !== false, 'Clock mirror reservations must persist the stored pricing snapshot for later email rendering.');
$assert(\strpos($reservationRepository, 'provider_metadata') !== false && \strpos($reservationRepository, 'getReservationEmailData') !== false, 'Email reservation query must expose provider metadata for stored pricing snapshots.');

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Display options settings tests passed.\n";
