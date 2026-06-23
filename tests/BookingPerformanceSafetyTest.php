<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$failures = [];

$autoSync = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationAutoSyncScheduler.php');
$jobRunner = (string) \file_get_contents($root . '/src/Provider/Sync/ProviderSyncJobRunner.php');
$clockClient = (string) \file_get_contents($root . '/src/Provider/Clock/ClockApiClient.php');
$clockReservation = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationProvider.php');
$clockAvailability = (string) \file_get_contents($root . '/src/Provider/Clock/ClockAvailabilityProvider.php');
$clockQuote = (string) \file_get_contents($root . '/src/Provider/Clock/ClockQuoteProvider.php');
$clockConfig = (string) \file_get_contents($root . '/src/Provider/Clock/ClockConfig.php');
$mustConfig = (string) \file_get_contents($root . '/src/Core/MustBookingConfig.php');
$checkoutFrontend = (string) \file_get_contents($root . '/src/Frontend/checkout-page.php');
$confirmationFrontend = (string) \file_get_contents($root . '/src/Frontend/confirmation-page.php');

$runScheduledStart = \strpos($autoSync, 'public function runScheduledSync');
$queueStart = \strpos($autoSync, 'private function queueRefreshJobs');
$runScheduledBody = $runScheduledStart !== false && $queueStart !== false
    ? \substr($autoSync, $runScheduledStart, $queueStart - $runScheduledStart)
    : '';

if ($runScheduledBody === '' || \strpos($runScheduledBody, 'runDueJobs') !== false) {
    $failures[] = 'Clock auto-sync scheduling must queue refresh jobs without draining a remote batch in the same cron request.';
}
if (\strpos($jobRunner, '$runner->runDueJobs(1);') === false) {
    $failures[] = 'Provider sync cron must process one remote job per invocation to bound PHP worker time.';
}
if (
    \strpos($jobRunner, 'acquireCronLock') === false
    || \strpos($jobRunner, 'wp_schedule_single_event') === false
    || \strpos($jobRunner, 'getDueJobs(1)') === false
) {
    $failures[] = 'Provider sync cron must prevent overlapping workers and reschedule remaining due jobs.';
}
if (\strpos($autoSync, 'add_option(self::LOCK_KEY') === false || \strpos($autoSync, 'LOCK_TTL_SECONDS') === false) {
    $failures[] = 'Clock auto-sync queueing must use an atomic lock with stale-lock expiration.';
}
if (\strpos($clockClient, "'rates_availability', 'products'") === false || \strpos($clockClient, '? 45 : 60') === false) {
    $failures[] = 'Safe Clock availability and product reads must use the short transient cache.';
}
if (\strpos($clockClient, '$bypassCache') === false || \strpos($clockClient, "'bypass'") === false) {
    $failures[] = 'Clock API requests must support an explicit cache bypass for final validation.';
}
if (\strpos($clockClient, "\$maxAttempts = \$method === 'GET' ? 2 : 1;") === false) {
    $failures[] = 'Clock reads must have at most one retry and writes must not retry automatically.';
}

$frontendCode = $checkoutFrontend . "\n" . $confirmationFrontend;
foreach (['testPokPayCredentials', 'verifyPokPayCredentials', 'performPokPayCredential'] as $credentialProbe) {
    if (\stripos($frontendCode, $credentialProbe) !== false) {
        $failures[] = 'Frontend rendering must not perform provider credential verification.';
        break;
    }
}

$createStart = \strpos($clockReservation, 'public function createReservations');
$createEnd = \strpos($clockReservation, 'private function precheckSelectionSource');
$createBody = $createStart !== false && $createEnd !== false
    ? \substr($clockReservation, $createStart, $createEnd - $createStart)
    : '';
$availabilityPosition = \strpos($createBody, 'checkAvailabilityFresh');
$pricingPosition = \strpos($createBody, 'calculateTotalFresh');
$quoteValidationPosition = \strpos($createBody, 'BookingQuoteDraft::isValidFor');
$priceComparisonPosition = \strpos($createBody, 'BookingQuoteDraft::pricingMatches');
$createPosition = \strpos($createBody, 'createClockBooking');

if (
    $createBody === ''
    || $availabilityPosition === false
    || $pricingPosition === false
    || $quoteValidationPosition === false
    || $priceComparisonPosition === false
    || $createPosition === false
    || $availabilityPosition > $createPosition
    || $pricingPosition > $createPosition
    || $quoteValidationPosition > $createPosition
    || $priceComparisonPosition > $createPosition
) {
    $failures[] = 'Clock reservation creation must validate the signed quote and perform fresh availability/price checks before the provider write.';
}
if (
    \strpos($clockAvailability, 'checkAvailabilityFresh') === false
    || \strpos($clockAvailability, "'bypass_cache' => \$bypassCache") === false
    || \strpos($clockQuote, 'calculateTotalFresh') === false
    || \substr_count($clockQuote, "'bypass_cache' => \$bypassCache") < 2
) {
    $failures[] = 'Final availability, product pricing, and guarantee-policy reads must explicitly bypass the short cache.';
}
if (
    \strpos($clockConfig, 'return \\max(1, \\min(100') === false
    || \strpos($mustConfig, "'clock_auto_sync_interval_minutes' => 60") === false
    || \strpos($mustConfig, "'clock_auto_sync_batch_size' => 1") === false
) {
    $failures[] = 'Hardened Clock auto-sync defaults must be one reservation every 60 minutes without overwriting saved settings.';
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Booking performance safety tests passed.\n";
