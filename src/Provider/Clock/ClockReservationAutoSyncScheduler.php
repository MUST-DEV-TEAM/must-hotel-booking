<?php
namespace MustHotelBooking\Provider\Clock;
use MustHotelBooking\Provider\ProviderManager;
final class ClockReservationAutoSyncScheduler
{
    private const CRON_HOOK = 'must_hotel_booking_clock_auto_reservation_sync';
    private const LOCK_KEY = 'must_hotel_booking_clock_auto_sync_lock';
    private const LOCK_TTL_SECONDS = 600;
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
        $interval = self::scheduleName(
            ClockConfig::autoSyncIntervalMinutes()
        );
        $next = \wp_next_scheduled(self::CRON_HOOK);
        $currentSchedule = \function_exists('wp_get_schedule')
            ? \wp_get_schedule(self::CRON_HOOK)
            : '';
        if ($next !== false && $currentSchedule === $interval) {
            return;
        }
        if ($next !== false) {
            self::unscheduleCron();
        }
        \wp_schedule_event(
            \time() + 60,
            $interval,
            self::CRON_HOOK
        );
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
        $lockToken = self::acquireLock();
        if ($lockToken === '') {
            return;
        }
        try {
            (new self())->runScheduledSync();
        } finally {
            self::releaseLock($lockToken);
        }
    }
    /**
     * @return array<string, int>
     */
    public function runScheduledSync(): array
    {
        $batchSize = ClockConfig::autoSyncBatchSize();
        $queued = $this->queueRefreshJobs($batchSize);

        /*
         * Queue only. Processing a full remote refresh batch inside the same
         * cron request can occupy PHP workers and the shared Clock limiter for
         * several seconds while a customer request is rendering.
         * ProviderSyncJobRunner drains the queue separately in small slices.
         */
        return [
            'selected' => $queued['selected'],
            'queued' => $queued['queued'],
            'processed' => 0,
            'succeeded' => 0,
            'retryable' => 0,
            'failed' => 0,
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
    private static function acquireLock(): string
    {
        $now = \time();
        $token = $now . ':' . (\function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : \uniqid('mhb_', true));

        if (\add_option(self::LOCK_KEY, $token, '', false)) {
            return $token;
        }

        $existing = (string) \get_option(self::LOCK_KEY, '');
        $createdAt = (int) \strtok($existing, ':');

        if ($createdAt > 0 && $createdAt > ($now - self::LOCK_TTL_SECONDS)) {
            return '';
        }

        \delete_option(self::LOCK_KEY);

        return \add_option(self::LOCK_KEY, $token, '', false) ? $token : '';
    }
    private static function releaseLock(string $token): void
    {
        if ($token !== '' && \hash_equals($token, (string) \get_option(self::LOCK_KEY, ''))) {
            \delete_option(self::LOCK_KEY);
        }
    }
    private static function scheduleName(int $minutes): string
    {
        return 'must_hotel_booking_clock_every_' . ClockConfig::normalizeAutoSyncInterval($minutes) . '_minutes';
    }
}
