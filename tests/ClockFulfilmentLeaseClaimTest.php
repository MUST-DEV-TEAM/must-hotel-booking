<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

if (!\defined('ARRAY_A')) {
    \define('ARRAY_A', 'ARRAY_A');
}
if (!\function_exists('sanitize_key')) {
    function sanitize_key($value): string
    {
        return \strtolower(\preg_replace('/[^a-z0-9_\-]/', '', (string) $value) ?: '');
    }
}
if (!\function_exists('current_time')) {
    function current_time(string $format, bool $gmt = false): string
    {
        return '2026-07-16 15:00:00';
    }
}

final class ClockFulfilmentLeaseClaimTestWpdb
{
    /** @var string */
    public $prefix = 'wp_';

    /** @var array<string, mixed> */
    public $reservation;

    /** @var array<int, string> */
    public $queries = [];

    /** @var string */
    public $last_error = '';

    /** @param array<string, mixed> $reservation */
    public function __construct(array $reservation)
    {
        $this->reservation = $reservation;
    }

    public function prepare(string $query, ...$args): string
    {
        $index = 0;
        return (string) \preg_replace_callback('/%[dfs]/', function (array $match) use (&$index, $args): string {
            $value = $args[$index++] ?? '';
            if ($match[0] === '%d') {
                return (string) (int) $value;
            }
            if ($match[0] === '%f') {
                return (string) (float) $value;
            }
            return "'" . \str_replace("'", "''", (string) $value) . "'";
        }, $query);
    }

    /** @return array<string, mixed> */
    public function get_row(string $query, $output): array
    {
        return $this->reservation;
    }

    public function query(string $query)
    {
        $this->queries[] = $query;
        if (\preg_match("/provider_fulfilment_lease_expires_at\\s*=\\s*''/i", $query)) {
            $this->last_error = 'Incorrect DATETIME value';
            return false;
        }

        $this->reservation['provider_sync_status'] = 'creating';
        $this->reservation['provider_fulfilment_lease_expires_at'] = '2099-01-01 00:00:00';
        return 1;
    }
}

require __DIR__ . '/../src/Database/AbstractRepository.php';
require __DIR__ . '/../src/Database/ReservationRepository.php';

/** @return array<string, mixed> */
function clock_lease_claim_row(string $syncStatus = 'pending_fulfilment', $leaseExpiresAt = null): array
{
    return [
        'id' => 150,
        'status' => 'pending_payment',
        'payment_status' => 'pending',
        'provider_sync_status' => $syncStatus,
        'provider_fulfilment_lease_expires_at' => $leaseExpiresAt,
        'provider_booking_id' => '',
        'provider_reservation_id' => '',
    ];
}

$failures = [];
$claimKey = 'lease-key';
$owner = 'owner-a';

$nullDb = new ClockFulfilmentLeaseClaimTestWpdb(clock_lease_claim_row());
$nullRepository = new \MustHotelBooking\Database\ReservationRepository($nullDb);
$nullResult = $nullRepository->claimPendingClockReservation(150, $claimKey, $owner);
if (($nullResult['outcome'] ?? '') !== 'claimed') {
    $failures[] = 'A NULL lease must be atomically claimable under strict MySQL.';
}
if (empty($nullDb->queries) || \preg_match("/provider_fulfilment_lease_expires_at\\s*=\\s*''/i", $nullDb->queries[0])) {
    $failures[] = 'The lease claim must not compare a DATETIME column with an empty string.';
}

$reclaimDb = new ClockFulfilmentLeaseClaimTestWpdb(clock_lease_claim_row('pending_fulfilment', '2026-07-16 14:59:59'));
$reclaimResult = (new \MustHotelBooking\Database\ReservationRepository($reclaimDb))->claimPendingClockReservation(150, $claimKey, $owner);
if (($reclaimResult['outcome'] ?? '') !== 'claimed') {
    $failures[] = 'An expired valid lease on an otherwise pending fulfillment must be atomically reclaimable.';
}

$expiredDb = new ClockFulfilmentLeaseClaimTestWpdb(clock_lease_claim_row('creating', '2026-07-16 14:59:59'));
$expiredResult = (new \MustHotelBooking\Database\ReservationRepository($expiredDb))->claimPendingClockReservation(150, $claimKey, $owner);
if (($expiredResult['outcome'] ?? '') !== 'expired_claim_recovery' || !empty($expiredDb->queries)) {
    $failures[] = 'An expired valid lease must remain a manual-recovery boundary without a new claim.';
}

$activeDb = new ClockFulfilmentLeaseClaimTestWpdb(clock_lease_claim_row('creating', '2026-07-16 15:05:00'));
$activeResult = (new \MustHotelBooking\Database\ReservationRepository($activeDb))->claimPendingClockReservation(150, $claimKey, 'owner-b');
if (($activeResult['outcome'] ?? '') !== 'in_progress' || !empty($activeDb->queries)) {
    $failures[] = 'An active lease must not be claimable by another owner.';
}

$ownerDb = new ClockFulfilmentLeaseClaimTestWpdb(clock_lease_claim_row('creating', '2026-07-16 15:05:00'));
$ownerResult = (new \MustHotelBooking\Database\ReservationRepository($ownerDb))->claimPendingClockReservation(150, $claimKey, $owner);
if (($ownerResult['outcome'] ?? '') !== 'in_progress' || !empty($ownerDb->queries)) {
    $failures[] = 'A current owner must follow the existing in-progress idempotency rule without renewal.';
}

$concurrentDb = new ClockFulfilmentLeaseClaimTestWpdb(clock_lease_claim_row());
$concurrentRepository = new \MustHotelBooking\Database\ReservationRepository($concurrentDb);
$first = $concurrentRepository->claimPendingClockReservation(150, $claimKey, $owner);
$second = $concurrentRepository->claimPendingClockReservation(150, $claimKey, 'owner-b');
if (($first['outcome'] ?? '') !== 'claimed' || ($second['outcome'] ?? '') !== 'in_progress' || \count($concurrentDb->queries) !== 1) {
    $failures[] = 'Only one logical claimant may acquire the atomic Clock lease.';
}

$provider = (string) \file_get_contents(__DIR__ . '/../src/Provider/Clock/ClockReservationProvider.php');
$claimPosition = \strpos($provider, 'claimPendingClockReservation');
$createPosition = \strpos($provider, 'createClockReservationForPendingPayment');
if ($claimPosition === false || $createPosition === false || $claimPosition > $createPosition) {
    $failures[] = 'A failed lease claim must return before any Clock provider create call.';
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock fulfilment lease-claim tests passed.\n";
