<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$scheduler = (string) \file_get_contents($root . '/src/Provider/Clock/ClockReservationAutoSyncScheduler.php');
$runner = (string) \file_get_contents($root . '/src/Provider/Sync/ProviderSyncJobRunner.php');
$repository = (string) \file_get_contents($root . '/src/Provider/Storage/ProviderSyncJobRepository.php');
$config = (string) \file_get_contents($root . '/src/Core/MustBookingConfig.php');
$failures = [];

$scheduledStart = \strpos($scheduler, 'public function runScheduledSync');
$queueStart = \strpos($scheduler, 'private function queueRefreshJobs');
$scheduledBody = $scheduledStart !== false && $queueStart !== false
    ? \substr($scheduler, $scheduledStart, $queueStart - $scheduledStart)
    : '';

if ($scheduledBody === '' || \strpos($scheduledBody, 'runDueJobs') !== false) {
    $failures[] = 'Auto-sync must only queue refresh jobs and must not execute the remote batch in that request.';
}
if (
    \strpos($config, "'clock_auto_sync_interval_minutes' => 60") === false
    || \strpos($config, "'clock_auto_sync_batch_size' => 1") === false
) {
    $failures[] = 'Safe new-install defaults must be one queued reservation every 60 minutes.';
}
if (
    \strpos($runner, '$runner->runDueJobs(1);') === false
    || \strpos($runner, 'wp_schedule_single_event') === false
    || \strpos($runner, 'getDueJobs(1)') === false
) {
    $failures[] = 'The remote worker must process one job and schedule another small slice when work remains.';
}
if (
    \strpos($runner, 'add_option(self::CRON_LOCK_KEY') === false
    || \strpos($runner, 'CRON_LOCK_TTL_SECONDS') === false
    || \strpos($runner, 'delete_option(self::CRON_LOCK_KEY)') === false
    || \strpos($scheduler, 'add_option(self::LOCK_KEY') === false
    || \strpos($scheduler, 'LOCK_TTL_SECONDS') === false
) {
    $failures[] = 'Both cron paths must use atomic option locks and recover expired locks.';
}
if (
    \strpos($repository, 'AND status IN (%s, %s)') === false
    || \strpos($repository, 'SET status = %s,') === false
    || \strpos($repository, 'attempts = attempts + 1') === false
) {
    $failures[] = 'Jobs must be atomically claimed and stale running attempts must be released or exhausted.';
}
if (
    \strpos($runner, 'markFailed(') === false
    || \strpos($runner, '$summary[\'retryable\']++') === false
    || \strpos($runner, '$summary[\'failed\']++') === false
) {
    $failures[] = 'A failed job must be recorded without preventing later scheduled slices.';
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock cron safety tests passed.\n";
