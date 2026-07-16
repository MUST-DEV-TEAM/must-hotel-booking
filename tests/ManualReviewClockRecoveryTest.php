<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$payment = (string) \file_get_contents($root . '/src/Engine/PaymentEngine.php');
$provider = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationProvider.php');
$repository = (string) \file_get_contents($root . '/src/Database/ReservationRepository.php');
$logs = (string) \file_get_contents($root . '/src/Provider/Storage/ProviderRequestLogRepository.php');
$failures = [];

foreach ([
    'public static function reconcileManualReviewClockFulfilment',
    "'manual_review'",
    'provider_fulfilment_key',
    '$logs->providerRequestLogsTableExists()',
    "hasLog(ProviderManager::CLOCK_MODE, 'clock.reservation_create', 'outbound', \$claimKey)",
    'resumeVerifiedPendingClockFulfilment([$reservationId], true)',
    'OnlinePaymentVerificationService',
] as $marker) {
    if (\strpos($payment, $marker) === false) {
        $failures[] = 'Manual-review Clock recovery is missing its required first-time-create guard: ' . $marker;
    }
}

foreach ([
    'public function claimManualReviewClockReservation',
    "provider_sync_status = %s",
    "'manual_review'",
] as $marker) {
    if (\strpos($repository, $marker) === false) {
        $failures[] = 'Manual-review recovery must use a dedicated atomic lease claim: ' . $marker;
    }
}

foreach ([
    'bool $allowManualReviewRecovery = false',
    'claimManualReviewClockReservation($reservationId, $claimKey, $ownerToken)',
] as $marker) {
    if (\strpos($provider, $marker) === false) {
        $failures[] = 'Only the internal manual-review recovery path may use the dedicated lease claim: ' . $marker;
    }
}

if (\strpos($logs, 'public function hasLog(') === false) {
    $failures[] = 'Manual-review recovery must be able to reject an existing Clock create request by idempotency key.';
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Manual-review Clock recovery regression test passed.\n";
