<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$schedulerPath = $root . '/src/Provider/Clock/ClockSyncScheduler.php';
$config = (string) \file_get_contents($root . '/src/Core/MustBookingConfig.php');
$settings = (string) \file_get_contents($root . '/src/Admin/SettingsPage.php');
$plugin = (string) \file_get_contents($root . '/src/Core/Plugin.php');
$diagnostics = (string) \file_get_contents($root . '/src/Admin/SettingsDiagnostics.php');
$failures = [];

if (!\is_file($schedulerPath)) {
    $failures[] = 'ClockSyncScheduler.php must define the canonical Clock sync scheduler.';
} else {
    $scheduler = (string) \file_get_contents($schedulerPath);
    foreach ([
        'must_hotel_booking_clock_full_catalog_sync',
        'must_hotel_booking_clock_availability_rate_sync',
        'must_hotel_booking_clock_reservation_fallback_sync',
    ] as $hook) {
        if (\strpos($scheduler, $hook) === false) {
            $failures[] = "Scheduler must expose canonical hook {$hook}.";
        }
    }
    foreach (['catalog', 'availability_rates', 'reservations', 'webhook'] as $type) {
        if (\strpos($scheduler, "'{$type}'") === false && \strpos($scheduler, "\"{$type}\"") === false) {
            $failures[] = "Scheduler diagnostics must support sync type {$type}.";
        }
    }
    foreach (['skipped_locked', 'skipped_disabled', 'skipped_missing_capability', 'partial'] as $status) {
        if (\strpos($scheduler, $status) === false) {
            $failures[] = "Run log must support status {$status}.";
        }
    }
    if (\strpos($scheduler, 'DISABLE_WP_CRON') === false) {
        $failures[] = 'Scheduler diagnostics must detect DISABLE_WP_CRON.';
    }
    if (\strpos($scheduler, 'sanitizeError') === false || \strpos($scheduler, 'Authorization') === false || \strpos($scheduler, 'api key') === false) {
        $failures[] = 'Scheduler must sanitize stored sync errors and redact credential-shaped text.';
    }
    if (\strpos($scheduler, 'clock_availability_rate_interval_minutes') === false || \strpos($scheduler, '2 * 60') === false) {
        $failures[] = 'Availability/rate overdue health must be based on the configured interval.';
    }
    if (\strpos($scheduler, 'clock_reservation_fallback_interval_minutes') === false || \strpos($scheduler, '2 * 60') === false) {
        $failures[] = 'Reservation fallback overdue health must be based on the configured interval.';
    }
}

foreach ([
    'clock_full_catalog_sync_enabled',
    'clock_full_catalog_sync_hour',
    'clock_availability_rate_sync_enabled',
    'clock_availability_rate_interval_minutes',
    'clock_reservation_fallback_sync_enabled',
    'clock_reservation_fallback_interval_minutes',
    'clock_reservation_fallback_batch_size',
] as $setting) {
    if (\strpos($config, $setting) === false || \strpos($settings, $setting) === false) {
        $failures[] = "Setting {$setting} must be defaulted, sanitized, and rendered.";
    }
}

foreach ([
    'ClockSyncScheduler::registerHooks()',
    'ClockSyncScheduler::scheduleCron()',
    'ClockSyncScheduler::unscheduleCron()',
] as $call) {
    if (\strpos($plugin, $call) === false) {
        $failures[] = "Plugin lifecycle must call {$call}.";
    }
}

foreach ([
    'run_clock_catalog_sync_now',
    'run_clock_availability_rate_sync_now',
    'run_clock_reservation_fallback_sync_now',
    'repair_clock_sync_schedules',
] as $task) {
    if (\strpos($settings, $task) === false) {
        $failures[] = "Admin settings must expose maintenance task {$task}.";
    }
}

foreach ([
    'ClockSyncScheduler::getDiagnostics()',
    'auto_sync_health',
    'recent_runs',
    'last_webhook_received',
] as $needle) {
    if (\strpos($diagnostics . $settings, $needle) === false) {
        $failures[] = "Diagnostics/UI must include {$needle}.";
    }
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock sync architecture tests passed.\n";
