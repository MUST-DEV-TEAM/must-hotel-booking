<?php

namespace MustHotelBooking\Provider\Sync;

use MustHotelBooking\Engine\PaymentRefundService;
use MustHotelBooking\Provider\Clock\ClockPaymentAccountingService;
use MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService;
use MustHotelBooking\Provider\Clock\ClockInboundSyncService;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;

final class ProviderSyncJobRunner
{
    private const CRON_HOOK = 'must_hotel_booking_process_provider_sync_jobs';

    /** @var ProviderSyncJobRepository */
    private $jobs;

    public function __construct(?ProviderSyncJobRepository $jobs = null)
    {
        $this->jobs = $jobs ?: new ProviderSyncJobRepository();
    }

    public static function getCronHook(): string
    {
        return self::CRON_HOOK;
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public static function registerCronInterval(array $schedules): array
    {
        if (!isset($schedules['must_hotel_booking_every_five_minutes'])) {
            $schedules['must_hotel_booking_every_five_minutes'] = [
                'interval' => 5 * 60,
                'display' => \__('Every 5 Minutes (MUST Hotel Booking)', 'must-hotel-booking'),
            ];
        }

        return $schedules;
    }

    public static function scheduleCron(): void
    {
        if (\wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }

        \wp_schedule_event(\time() + (5 * 60), 'must_hotel_booking_every_five_minutes', self::CRON_HOOK);
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
        (new self())->runDueJobs(10);
    }

    public static function registerHooks(): void
    {
        \add_filter('cron_schedules', [self::class, 'registerCronInterval']);
        \add_action(self::CRON_HOOK, [self::class, 'runCron']);
    }

    /**
     * @return array<string, int>
     */
    public function runDueJobs(int $limit = 10): array
    {
        $limit = \max(1, \min(50, $limit));
        $summary = [
            'processed' => 0,
            'succeeded' => 0,
            'retryable' => 0,
            'failed' => 0,
            'skipped' => 0,
            'released_stale' => $this->jobs->releaseStaleRunningJobs(30),
        ];

        foreach ($this->jobs->getDueJobs($limit) as $job) {
            $jobId = isset($job['id']) ? (int) $job['id'] : 0;

            if ($jobId <= 0) {
                $summary['skipped']++;
                continue;
            }

            $claimed = $this->jobs->claimDueJob($jobId);

            if (!\is_array($claimed)) {
                $summary['skipped']++;
                continue;
            }

            $summary['processed']++;

            try {
                $result = $this->executeJob($claimed);
            } catch (\Throwable $exception) {
                $result = [
                    'success' => false,
                    'retry' => true,
                    'message' => $exception->getMessage(),
                ];
            }

            if (!empty($result['success'])) {
                $this->jobs->markSucceeded($jobId);
                $summary['succeeded']++;
                continue;
            }

            $retry = !empty($result['retry']);
            $this->jobs->markFailed(
                $jobId,
                (string) ($result['message'] ?? \__('Provider sync job failed.', 'must-hotel-booking')),
                $retry
            );

            if ($retry) {
                $summary['retryable']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $job
     * @return array{success: bool, retry: bool, message: string}
     */
    private function executeJob(array $job): array
    {
        $provider = isset($job['provider']) ? \sanitize_key((string) $job['provider']) : '';

        if ($provider === ProviderManager::CLOCK_MODE) {
            $operation = isset($job['operation']) ? \sanitize_key((string) $job['operation']) : '';

            if ($operation === 'reservation_refresh') {
                return (new ClockInboundSyncService())->executeRefreshJob($job);
            }

            if ($operation === 'refund_clock_sync') {
                $result = (new PaymentRefundService())->retryClockSync((int) ($job['target_local_id'] ?? 0));

                return [
                    'success' => !empty($result['success']),
                    'retry' => !empty($result['retry']),
                    'message' => isset($result['message']) ? (string) $result['message'] : '',
                ];
            }

            if ($operation === 'clock_folio_accounting_sync') {
                return (new ClockPaymentAccountingService())->executeSyncJob($job);
            }

            return (new ClockPaymentReconciliationService())->executeSyncJob($job);
        }

        return [
            'success' => false,
            'retry' => false,
            'message' => \__('Unsupported provider sync job provider.', 'must-hotel-booking'),
        ];
    }
}
