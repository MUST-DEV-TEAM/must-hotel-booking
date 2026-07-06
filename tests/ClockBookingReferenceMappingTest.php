<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

if (!\function_exists('__')) {
    function __($text, $domain = null) { unset($domain); return $text; }
}
if (!\function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return \trim(\strip_tags((string) $value)); }
}
if (!\function_exists('sanitize_key')) {
    function sanitize_key($value) { return \strtolower((string) \preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $value)); }
}
if (!\function_exists('wp_json_encode')) {
    function wp_json_encode($value) { return \json_encode($value); }
}
if (!\function_exists('mysql2date')) {
    function mysql2date($format, $date) { unset($format); return $date; }
}
if (!\function_exists('get_option')) {
    function get_option($name) { return $name === 'date_format' ? 'Y-m-d' : 'H:i:s'; }
}

$root = \dirname(__DIR__);
require_once $root . '/src/Provider/ProviderManager.php';
require_once $root . '/src/Provider/Clock/ClockBookingReferenceMapper.php';
require_once $root . '/src/Provider/ProviderReservationView.php';

use MustHotelBooking\Provider\Clock\ClockBookingReferenceMapper;
use MustHotelBooking\Provider\ProviderReservationView;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$payload = ClockBookingReferenceMapper::applyWebsiteReferenceToPayload(
    ['booking' => ['note' => "Guest note"]],
    'MHB-20260706-2C838BCF'
);
$assert(($payload['payload']['booking']['reference_number'] ?? '') === 'MHB-20260706-2C838BCF', 'Website reference should be sent in Clock reference_number.');
$assert(\strpos((string) ($payload['payload']['booking']['note'] ?? ''), 'Website booking reference: MHB-20260706-2C838BCF') !== false, 'Website reference should be appended to Clock notes as searchable fallback.');
$assert(($payload['primary_field'] ?? '') === 'reference_number', 'Mapper should report the primary Clock reference field used.');
$assert(\in_array('note', (array) ($payload['fallback_fields'] ?? []), true), 'Mapper should report the note fallback field.');

$fallbackPayload = ClockBookingReferenceMapper::applyWebsiteReferenceToPayload(
    ['booking' => ['note' => 'Existing']],
    'WEB-2C838BCF',
    ''
);
$assert(!isset($fallbackPayload['payload']['booking']['reference_number']), 'Empty primary field should not write an unsupported Clock reference field.');
$assert(($fallbackPayload['primary_field'] ?? '') === 'note', 'Fallback-only payload should report note as the storage field.');
$assert(\strpos((string) ($fallbackPayload['payload']['booking']['note'] ?? ''), 'Website booking reference: WEB-2C838BCF') !== false, 'Fallback-only payload should still store a searchable website reference.');

$ids = ClockBookingReferenceMapper::extractClockIdentifiers([
    'id' => '36591448',
    'reference_number' => '#46',
]);
$assert(($ids['clock_booking_id'] ?? '') === '36591448', 'Clock internal booking ID should be extracted separately.');
$assert(($ids['clock_booking_reference'] ?? '') === '#46', 'Clock human booking reference should be extracted separately.');

$separateIds = ClockBookingReferenceMapper::extractClockIdentifiers([
    'id' => '36591448',
    'booking_id' => '#46',
    'reference_number' => '#46',
]);
$assert(($separateIds['clock_booking_id'] ?? '') === '36591448', 'Clock internal ID should win when Clock also returns a display booking reference.');

$single = ClockBookingReferenceMapper::extractClockIdentifiers(['booking_id' => '36591448']);
$assert(($single['clock_booking_id'] ?? '') === '36591448', 'Single Clock identifier should be stored as booking ID.');
$assert(($single['clock_booking_reference'] ?? '') === '36591448', 'Single Clock identifier should be usable as reference fallback.');

$context = ProviderReservationView::metadata([
    'provider' => 'clock',
    'booking_id' => 'MHB-20260706-2C838BCF',
    'provider_booking_id' => '36591448',
    'provider_reservation_id' => '36591448',
    'provider_metadata' => \json_encode([
        'clock_booking_id' => '36591448',
        'clock_booking_reference' => '#46',
        'website_reference_sent_to_clock' => true,
    ]),
]);
$assert(($context['clock_booking_id'] ?? '') === '36591448', 'Admin provider context should expose Clock booking ID.');
$assert(($context['clock_booking_reference'] ?? '') === '#46', 'Admin provider context should expose Clock booking reference.');
$assert(($context['website_booking_reference'] ?? '') === 'MHB-20260706-2C838BCF', 'Admin provider context should expose website booking reference.');
$assert(($context['website_reference_sent_to_clock'] ?? null) === true, 'Admin provider context should expose website reference sent state.');

$legacyContext = ProviderReservationView::metadata([
    'provider' => 'clock',
    'booking_id' => 'MHB-LEGACY',
    'provider_booking_id' => '36590001',
    'provider_metadata' => '',
]);
$assert(($legacyContext['clock_booking_id'] ?? '') === '36590001', 'Legacy Clock bookings without metadata should still expose provider_booking_id as Clock ID.');
$assert(($legacyContext['clock_booking_reference'] ?? '') === '', 'Legacy Clock bookings without a separate reference should still load safely.');

$repository = (string) \file_get_contents($root . '/src/Database/ReservationRepository.php');
foreach (['r.provider_booking_id LIKE %s', 'r.provider_reservation_id LIKE %s', 'r.provider_payload_ref LIKE %s', 'r.provider_metadata LIKE %s'] as $needle) {
    $assert(\strpos($repository, $needle) !== false, 'Admin reservation search should include ' . $needle . '.');
}

$provider = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationProvider.php');
$assert(\strpos($provider, 'applyWebsiteReferenceToPayload') !== false, 'Clock create payload should use the website reference mapper.');
$assert(\strpos($provider, 'website_booking_reference') !== false, 'Clock create flow should pass the website booking reference before provider write.');

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock booking reference mapping tests passed.\n";
