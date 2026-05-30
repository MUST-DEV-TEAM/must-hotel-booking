<?php
namespace MustHotelBooking\Provider\Clock;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Sync\ProviderSyncJobRunner;
final class ClockReservationAutoSyncScheduler
{
    private const CRON_HOOK = 'must_hotel_booking_clock_auto_reservation_sync';
    private const LOCK_KEY = 'must_hotel_booking_clock_auto_sync_lock';
    public static function getCronHook(): string
    {
        return self::CRON_HOOK;
    }
    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function registerCronIntervals(array $schedules): array
    {
        foreach ([5, 10, 15, 30, 60] as $minutes) {
            $key = self::scheduleName($minutes);
            if (!isset($schedules[$key])) {
                $schedules[$key] = [
                    'interval' => $minutes * 60,
                    'display' => \sprintf(
                        /* translators: %d: minutes */
                        \__('Every %d Minutes (MUST Clock Auto Sync)', 'must-hotel-booking'),
                        $minutes
                    ),
                ];
            }
        }
        return $schedules;
    }
    public static function registerHooks(): void
    {
        \add_filter('cron_schedules', [self::class, 'registerCronIntervals']);
        \add_action(self::CRON_HOOK, [self::class, 'runCron']);
    }
    public static function scheduleCron(): void
    {
        if (!self::isRunnable()) {
            self::unscheduleCron();
            return;
        }
        $interval = self::scheduleName(ClockConfig::autoSyncIntervalMinutes());
        $next = \wp_next_scheduled(self::CRON_HOOK);
        $currentSchedule = \function_exists('wp_get_schedule') ? \wp_get_schedule(self::CRON_HOOK) : '';
        if ($next !== false && $currentSchedule === $interval) {
            return;
        }
        if ($next !== false) {
            self::unscheduleCron();
        }
        \wp_schedule_event(\time() + 60, $interval, self::CRON_HOOK);
    }
    public static function unscheduleCron(): void
    {
        if (\function_exists('wp_clear_scheduled_hook')) {
            \wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        $timestamp = \wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp !== false) {
            \wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = \wp_next_scheduled(self::CRON_HOOK);
        }
    }
    public static function runCron(): void
    {
        if (!self::isRunnable()) {
            return;
        }
        if (\get_transient(self::LOCK_KEY)) {
            return;
        }
        $minute = \defined('MINUTE_IN_SECONDS') ? (int) MINUTE_IN_SECONDS : 60;
        \set_transient(self::LOCK_KEY, '1', 10 * $minute);
        try {
            (new self())->runScheduledSync();
        } finally {
            \delete_transient(self::LOCK_KEY);
        }
    }
    /**
     * @return array<string, int>
     */
    public function runScheduledSync(): array
    {
        $batchSize = ClockConfig::autoSyncBatchSize();
        $queued = $this->queueRefreshJobs($batchSize);
        $processed = (new ProviderSyncJobRunner())->runDueJobs($batchSize);
        return [
            'selected' => $queued['selected'],
            'queued' => $queued['queued'],
            'processed' => (int) ($processed['processed'] ?? 0),
            'succeeded' => (int) ($processed['succeeded'] ?? 0),
            'retryable' => (int) ($processed['retryable'] ?? 0),
            'failed' => (int) ($processed['failed'] ?? 0),
        ];
    }
    /**
     * @return array{selected: int, queued: int}
     */
    private function queueRefreshJobs(int $limit): array
    {
        $rows = \MustHotelBooking\Engine\get_reservation_repository()->getProviderAutoSyncReservationRows(
            ProviderManager::CLOCK_MODE,
            ClockConfig::autoSyncPastDays(),
            ClockConfig::autoSyncFutureDays(),
            $limit
        );
        $service = new ClockInboundSyncService();
        $queued = 0;
        foreach ($rows as $row) {
            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($reservationId <= 0) {
                continue;
            }
            if ($service->enqueueReservationRefresh($reservationId, 'auto_cron') > 0) {
                $queued++;
            }
        }
        return [
            'selected' => \count($rows),
            'queued' => $queued,
        ];
    }
    private static function isRunnable(): bool
    {
        return ClockConfig::autoSyncEnabled()
            && ProviderManager::getConfiguredMode() === ProviderManager::CLOCK_MODE
            && ClockConfig::isEnabled()
            && ClockConfig::isDirectApiConfigured()
            && ClockConfig::reservationFetchPath() !== '';
    }
    private static function scheduleName(int $minutes): string
    {
        return 'must_hotel_booking_clock_every_' . ClockConfig::normalizeAutoSyncInterval($minutes) . '_minutes';
    }
}