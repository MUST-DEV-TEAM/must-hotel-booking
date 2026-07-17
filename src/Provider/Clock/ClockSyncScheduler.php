<?php
namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;

final class ClockSyncScheduler
{
    private const CATALOG_HOOK = 'must_hotel_booking_clock_full_catalog_sync';
    private const RESERVATION_HOOK = 'must_hotel_booking_clock_reservation_fallback_sync';
    private const STATE_OPTION = 'must_hotel_booking_clock_sync_state';
    private const RUNS_OPTION = 'must_hotel_booking_clock_sync_recent_runs';
    private const LOCK_PREFIX = 'must_hotel_booking_clock_sync_lock_';
    private const LOCK_TTL_SECONDS = 900;

    public static function getCatalogHook(): string
    {
        return self::CATALOG_HOOK;
    }

    public static function getReservationHook(): string
    {
        return self::RESERVATION_HOOK;
    }

    /** @param array<string, array<string, mixed>> $schedules @return array<string, array<string, mixed>> */
    public static function registerCronIntervals(array $schedules): array
    {
        foreach ([5, 10, 15, 30, 60] as $minutes) {
            $key = self::scheduleName($minutes);
            $schedules[$key] = [
                'interval' => $minutes * 60,
                'display' => \sprintf(
                    \__('Every %d Minutes (MUST Clock Sync)', 'must-hotel-booking'),
                    $minutes
                ),
            ];
        }
        return $schedules;
    }

    public static function registerHooks(): void
    {
        \add_filter('cron_schedules', [self::class, 'registerCronIntervals']);
        \add_action(self::CATALOG_HOOK, [self::class, 'runCatalogCron']);
        \add_action(self::RESERVATION_HOOK, [self::class, 'runReservationCron']);
    }

    public static function scheduleCron(bool $repair = false): void
    {
        if ($repair) {
            self::unscheduleCron();
        }
        self::scheduleCatalogCron();
        self::scheduleRecurringCron(
            self::RESERVATION_HOOK,
            ClockConfig::reservationFallbackSyncEnabled(),
            ClockConfig::reservationFallbackIntervalMinutes()
        );
    }

    public static function unscheduleCron(): void
    {
        foreach ([self::CATALOG_HOOK, self::RESERVATION_HOOK] as $hook) {
            self::clearHook($hook);
        }
        ClockReservationAutoSyncScheduler::unscheduleCron();
    }

    public static function repairSchedules(): array
    {
        self::scheduleCron(true);
        self::recordRun('catalog', 'repair', 'success', ['mappings' => 0], '');
        return self::getDiagnostics();
    }

    public static function runCatalogCron(): void
    {
        self::runSync('catalog', 'automatic');
    }

    public static function runReservationCron(): void
    {
        self::runSync('reservations', 'automatic');
    }

    /** @return array<string, mixed> */
    public static function runManualSync(string $syncType): array
    {
        return self::runSync($syncType, 'manual');
    }

    public static function recordWebhookReceived(string $status = 'success', string $message = ''): void
    {
        $state = self::state();
        $state['last_webhook_received'] = self::now();
        \update_option(self::STATE_OPTION, $state, false);
        self::recordRun('webhook', 'webhook', $status, ['reservation_mirrors' => 0, 'invalidated_cache_items' => 0], $message);
    }

    /** @return array<string, mixed> */
    public static function getDiagnostics(): array
    {
        $state = self::state();
        $catalogNext = self::nextRun(self::CATALOG_HOOK);
        $reservationNext = self::nextRun(self::RESERVATION_HOOK);
        $locks = [
            'catalog' => self::lockInfo('catalog'),
            'reservations' => self::lockInfo('reservations'),
        ];
        $autoSyncHealth = self::autoSyncHealth($state, $locks, [
            'catalog' => $catalogNext,
            'reservations' => $reservationNext,
        ]);

        return [
            'auto_sync_health' => $autoSyncHealth,
            'wp_cron_disabled' => \defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON,
            'last_full_catalog_sync' => (string) ($state['last_catalog_sync'] ?? ''),
            'last_reservation_fallback_sync' => (string) ($state['last_reservations_sync'] ?? ''),
            'last_webhook_received' => (string) ($state['last_webhook_received'] ?? ''),
            'last_manual_sync' => (string) ($state['last_manual_sync'] ?? ''),
            'last_automatic_sync' => (string) ($state['last_automatic_sync'] ?? ''),
            'last_successful_sync' => (string) ($state['last_successful_sync'] ?? ''),
            'next_full_catalog_sync' => $catalogNext,
            'next_reservation_fallback_sync' => $reservationNext,
            'locks' => $locks,
            'last_errors' => [
                'catalog' => (string) ($state['last_error_catalog'] ?? ''),
                'reservations' => (string) ($state['last_error_reservations'] ?? ''),
            ],
            'recent_runs' => self::recentRuns(),
        ];
    }

    /** @return array<string, mixed> */
    private static function runSync(string $syncType, string $source): array
    {
        $syncType = self::normalizeSyncType($syncType);
        $source = \in_array($source, ['manual', 'automatic', 'webhook', 'repair'], true) ? $source : 'automatic';
        $started = \microtime(true);

        if (!self::isEnabledForType($syncType)) {
            return self::finishRun($syncType, $source, $started, 'skipped_disabled', [], '');
        }
        if (!self::hasCapabilityForType($syncType)) {
            return self::finishRun($syncType, $source, $started, 'skipped_missing_capability', [], \__('Clock endpoint or capability is not configured for this sync type.', 'must-hotel-booking'));
        }

        $lockToken = self::acquireLock($syncType);
        if ($lockToken === '') {
            return self::finishRun($syncType, $source, $started, 'skipped_locked', [], '');
        }

        try {
            if ($syncType === 'catalog') {
                $result = self::runCatalogSync();
            } else {
                $result = self::runReservationFallbackSync();
            }
        } catch (\Throwable $exception) {
            $result = [
                'status' => 'failed',
                'counts' => [],
                'error' => $exception->getMessage(),
            ];
        } finally {
            self::releaseLock($syncType, $lockToken);
        }

        return self::finishRun(
            $syncType,
            $source,
            $started,
            (string) ($result['status'] ?? 'failed'),
            isset($result['counts']) && \is_array($result['counts']) ? $result['counts'] : [],
            (string) ($result['error'] ?? '')
        );
    }

    /** @return array<string, mixed> */
    private static function runCatalogSync(): array
    {
        if (\class_exists('\MustHotelBooking\Admin\SettingsPage') && \method_exists('\MustHotelBooking\Admin\SettingsPage', 'runClockFullSync')) {
            $summary = \MustHotelBooking\Admin\SettingsPage::runClockFullSync();
        } else {
            $summary = (new ClockCatalogService())->refreshCatalog();
        }
        $errors = isset($summary['errors']) && \is_array($summary['errors']) ? \array_values(\array_map('strval', $summary['errors'])) : [];
        $success = !empty($summary['success']) || empty($errors);
        return [
            'status' => $success ? (!empty($errors) ? 'partial' : 'success') : 'failed',
            'counts' => [
                'room_types' => (int) (($summary['room_types_imported'] ?? 0) + ($summary['room_types_mapped'] ?? 0)),
                'rooms' => (int) (($summary['physical_rooms_imported'] ?? 0) + ($summary['physical_rooms_mapped'] ?? 0)),
                'rates' => (int) (($summary['rates_imported'] ?? 0) + ($summary['rates_mapped'] ?? 0)),
                'mappings' => (int) (($summary['room_types_mapped'] ?? 0) + ($summary['physical_rooms_mapped'] ?? 0) + ($summary['rates_mapped'] ?? 0)),
                'reservation_mirrors' => (int) (($summary['reservations_created'] ?? 0) + ($summary['reservations_updated'] ?? 0)),
            ],
            'error' => \implode('; ', $errors),
        ];
    }

    /** @return array<string, mixed> */
    private static function runReservationFallbackSync(): array
    {
        $summary = (new ClockReservationAutoSyncScheduler())->runScheduledSync();
        return [
            'status' => 'success',
            'counts' => [
                'reservation_mirrors' => (int) ($summary['queued'] ?? 0),
            ],
            'error' => '',
        ];
    }

    /** @param array<string, mixed> $counts @return array<string, mixed> */
    private static function finishRun(string $syncType, string $source, float $started, string $status, array $counts, string $error): array
    {
        $error = self::sanitizeError($error);
        $durationMs = \max(0, (int) \round((\microtime(true) - $started) * 1000));
        $run = self::recordRun($syncType, $source, $status, $counts, $error, $durationMs);
        return $run + ['success' => \in_array($status, ['success', 'partial'], true)];
    }

    /** @param array<string, mixed> $counts @return array<string, mixed> */
    private static function recordRun(string $syncType, string $source, string $status, array $counts, string $error = '', int $durationMs = 0): array
    {
        $now = self::now();
        $run = [
            'run_id' => \function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : \uniqid('clock_sync_', true),
            'sync_type' => $syncType,
            'source' => $source,
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => $durationMs,
            'status' => $status,
            'counts' => self::normalizeCounts($counts),
            'sanitized_error' => self::sanitizeError($error),
        ];
        $runs = self::recentRuns();
        \array_unshift($runs, $run);
        \update_option(self::RUNS_OPTION, \array_slice($runs, 0, 20), false);

        $state = self::state();
        if ($syncType !== 'webhook') {
            $state['last_' . $syncType . '_sync'] = $now;
        }
        if ($source === 'manual') {
            $state['last_manual_sync'] = $now;
        }
        if ($source === 'automatic') {
            $state['last_automatic_sync'] = $now;
        }
        if (\in_array($status, ['success', 'partial'], true)) {
            $state['last_successful_sync'] = $now;
            $state['last_error_' . $syncType] = '';
        } elseif ($error !== '') {
            $state['last_error_' . $syncType] = self::sanitizeError($error);
        }
        \update_option(self::STATE_OPTION, $state, false);

        return $run;
    }

    /** @return array<string, mixed> */
    private static function state(): array
    {
        $state = \get_option(self::STATE_OPTION, []);
        return \is_array($state) ? $state : [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function recentRuns(): array
    {
        $runs = \get_option(self::RUNS_OPTION, []);
        return \is_array($runs) ? \array_values(\array_filter($runs, 'is_array')) : [];
    }

    private static function scheduleCatalogCron(): void
    {
        if (!ClockConfig::fullCatalogSyncEnabled()) {
            self::clearHook(self::CATALOG_HOOK);
            return;
        }
        $target = self::nextDailyTimestamp(ClockConfig::fullCatalogSyncHour());
        $next = \wp_next_scheduled(self::CATALOG_HOOK);
        if ($next !== false && \function_exists('wp_get_schedule') && \wp_get_schedule(self::CATALOG_HOOK) === 'daily') {
            return;
        }
        self::clearHook(self::CATALOG_HOOK);
        \wp_schedule_event($target, 'daily', self::CATALOG_HOOK);
    }

    private static function scheduleRecurringCron(string $hook, bool $enabled, int $minutes): void
    {
        if (!$enabled) {
            self::clearHook($hook);
            return;
        }
        $schedule = self::scheduleName($minutes);
        $next = \wp_next_scheduled($hook);
        $currentSchedule = \function_exists('wp_get_schedule') ? \wp_get_schedule($hook) : '';
        if ($next !== false && $currentSchedule === $schedule) {
            return;
        }
        self::clearHook($hook);
        \wp_schedule_event(\time() + 60, $schedule, $hook);
    }

    private static function clearHook(string $hook): void
    {
        if (\function_exists('wp_clear_scheduled_hook')) {
            \wp_clear_scheduled_hook($hook);
            return;
        }
        $timestamp = \wp_next_scheduled($hook);
        while ($timestamp !== false) {
            \wp_unschedule_event($timestamp, $hook);
            $timestamp = \wp_next_scheduled($hook);
        }
    }

    private static function nextDailyTimestamp(string $time): int
    {
        $parts = \explode(':', $time);
        $hour = \max(0, \min(23, (int) ($parts[0] ?? 3)));
        $minute = \max(0, \min(59, (int) ($parts[1] ?? 0)));
        $timezone = \function_exists('wp_timezone') ? \wp_timezone() : new \DateTimeZone('UTC');
        $date = new \DateTimeImmutable('now', $timezone);
        $target = $date->setTime($hour, $minute, 0);
        if ($target->getTimestamp() <= \time()) {
            $target = $target->modify('+1 day');
        }
        return $target->getTimestamp();
    }

    private static function acquireLock(string $syncType): string
    {
        $key = self::LOCK_PREFIX . $syncType;
        $now = \time();
        $token = $now . ':' . (\function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : \uniqid('mhb_', true));
        if (\add_option($key, $token, '', false)) {
            return $token;
        }
        $existing = (string) \get_option($key, '');
        $createdAt = (int) \strtok($existing, ':');
        if ($createdAt > 0 && $createdAt > ($now - self::LOCK_TTL_SECONDS)) {
            return '';
        }
        \delete_option($key);
        self::recordRun($syncType, 'automatic', 'partial', [], \__('Stale Clock sync lock was cleared.', 'must-hotel-booking'));
        return \add_option($key, $token, '', false) ? $token : '';
    }

    private static function releaseLock(string $syncType, string $token): void
    {
        $key = self::LOCK_PREFIX . $syncType;
        if ($token !== '' && \hash_equals($token, (string) \get_option($key, ''))) {
            \delete_option($key);
        }
    }

    /** @return array{locked: bool, age_seconds: int, stale: bool} */
    private static function lockInfo(string $syncType): array
    {
        $existing = (string) \get_option(self::LOCK_PREFIX . $syncType, '');
        $createdAt = (int) \strtok($existing, ':');
        $age = $createdAt > 0 ? \max(0, \time() - $createdAt) : 0;
        return [
            'locked' => $existing !== '',
            'age_seconds' => $age,
            'stale' => $age > self::LOCK_TTL_SECONDS,
        ];
    }

    /** @param array<string, mixed> $state @param array<string, array<string, mixed>> $locks @param array<string, string> $nextRuns */
    private static function autoSyncHealth(array $state, array $locks, array $nextRuns): string
    {
        if (\defined('DISABLE_WP_CRON') && \DISABLE_WP_CRON) {
            return 'wp_cron_disabled';
        }
        if (!ClockConfig::fullCatalogSyncEnabled() && !ClockConfig::reservationFallbackSyncEnabled()) {
            return 'disabled';
        }
        foreach ($locks as $lock) {
            if (!empty($lock['locked']) && empty($lock['stale'])) {
                return 'locked';
            }
        }
        $enabledNextRuns = [
            'catalog' => ClockConfig::fullCatalogSyncEnabled() ? ($nextRuns['catalog'] ?? '') : 'disabled',
            'reservations' => ClockConfig::reservationFallbackSyncEnabled() ? ($nextRuns['reservations'] ?? '') : 'disabled',
        ];
        foreach ($enabledNextRuns as $nextRun) {
            if ($nextRun === '') {
                return 'missing';
            }
        }
        if ((string) ($state['last_error_catalog'] ?? '') !== '' || (string) ($state['last_error_reservations'] ?? '') !== '') {
            return 'failing';
        }
        // clock_reservation_fallback_interval_minutes: overdue after 2x the configured interval.
        if (self::isOverdue((string) ($state['last_reservations_sync'] ?? ''), ClockConfig::reservationFallbackIntervalMinutes() * 2 * 60)) {
            return 'overdue';
        }
        if (self::isOverdue((string) ($state['last_catalog_sync'] ?? ''), 36 * 60 * 60)) {
            return 'overdue';
        }
        return 'healthy';
    }

    private static function isOverdue(string $mysqlTime, int $threshold): bool
    {
        if ($mysqlTime === '') {
            return false;
        }
        $timestamp = \strtotime($mysqlTime . ' UTC');
        return $timestamp !== false && (\time() - $timestamp) > $threshold;
    }

    private static function hasCapabilityForType(string $syncType): bool
    {
        if ($syncType === 'reservations') {
            return ClockConfig::reservationFetchPath() !== '';
        }
        return true;
    }

    private static function isEnabledForType(string $syncType): bool
    {
        if (ProviderManager::getConfiguredMode() !== ProviderManager::CLOCK_MODE || !ClockConfig::isEnabled()) {
            return false;
        }
        if ($syncType === 'catalog') {
            return ClockConfig::fullCatalogSyncEnabled();
        }
        return ClockConfig::reservationFallbackSyncEnabled();
    }

    private static function normalizeSyncType(string $syncType): string
    {
        $syncType = \sanitize_key($syncType);
        return \in_array($syncType, ['catalog', 'reservations'], true) ? $syncType : 'catalog';
    }

    /** @param mixed $data */
    private static function countResponseItems($data): int
    {
        if (!\is_array($data)) {
            return 0;
        }
        foreach (['items', 'data', 'results', 'records', 'rates_availability'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return \count($data[$key]);
            }
        }
        if (empty($data)) {
            return 0;
        }
        if (\function_exists('array_is_list')) {
            return \array_is_list($data) ? \count($data) : 1;
        }
        return \array_keys($data) === \range(0, \count($data) - 1) ? \count($data) : 1;
    }

    /** @param array<string, mixed> $counts @return array<string, int> */
    private static function normalizeCounts(array $counts): array
    {
        $keys = ['room_types', 'rooms', 'rates', 'mappings', 'availability_rows', 'reservation_mirrors', 'invalidated_cache_items'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = \max(0, (int) ($counts[$key] ?? 0));
        }
        return $out;
    }

    private static function sanitizeError(string $message): string
    {
        // Redact Authorization headers, api key values, tokens, passwords, and webhook secrets.
        $message = \sanitize_text_field($message);
        $patterns = [
            '/(authorization|api key|api_key|token|secret|password)(\s*[:=]\s*)([^\s,;]+)/i',
            '/(Bearer\s+)[A-Za-z0-9._\-~+\/]+=*/i',
            '/(Basic\s+)[A-Za-z0-9+\/]+=*/i',
        ];
        return \substr((string) \preg_replace($patterns, '$1$2[redacted]', $message), 0, 500);
    }

    private static function nextRun(string $hook): string
    {
        $next = \wp_next_scheduled($hook);
        return \is_numeric($next) ? \wp_date('Y-m-d H:i:s', (int) $next) : '';
    }

    private static function scheduleName(int $minutes): string
    {
        return 'must_hotel_booking_clock_every_' . ClockConfig::normalizeAutoSyncInterval($minutes) . '_minutes';
    }

    private static function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}