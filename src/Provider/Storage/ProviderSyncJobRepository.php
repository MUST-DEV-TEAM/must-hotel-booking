<?php

namespace MustHotelBooking\Provider\Storage;

use MustHotelBooking\Database\AbstractRepository;

final class ProviderSyncJobRepository extends AbstractRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_RETRYABLE = 'retryable';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXHAUSTED = 'exhausted';

    private function tableName(): string
    {
        return $this->wpdb->prefix . 'mhb_provider_sync_jobs';
    }

    public function providerSyncJobsTableExists(): bool
    {
        $tableName = $this->tableName();
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $tableName
            )
        );

        return \is_string($result) && $result !== '';
    }

    /**
     * @param array<string, mixed> $job
     */
    public function enqueue(array $job): int
    {
        if (!$this->providerSyncJobsTableExists()) {
            return 0;
        }

        $row = $this->normalizeJobData($job);

        if ($row['provider'] === '' || $row['operation'] === '' || $row['target_type'] === '') {
            return 0;
        }

        $now = $this->now();
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $inserted = $this->wpdb->insert(
            $this->tableName(),
            $row,
            ['%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $inserted !== false ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * @param array<string, mixed> $job
     */
    public function enqueueOnce(array $job): int
    {
        if (!$this->providerSyncJobsTableExists()) {
            return 0;
        }

        $row = $this->normalizeJobData($job);

        if ($row['provider'] === '' || $row['operation'] === '' || $row['target_type'] === '') {
            return 0;
        }

        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT id
                FROM ' . $this->tableName() . '
                WHERE provider = %s
                    AND operation = %s
                    AND target_type = %s
                    AND target_local_id = %d
                    AND target_external_id = %s
                    AND status IN (%s, %s, %s)
                ORDER BY id DESC
                LIMIT 1',
                $row['provider'],
                $row['operation'],
                $row['target_type'],
                $row['target_local_id'],
                $row['target_external_id'],
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
                self::STATUS_RETRYABLE
            )
        );

        if (\is_numeric($existing) && (int) $existing > 0) {
            return (int) $existing;
        }

        return $this->enqueue($row);
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        if (!$this->providerSyncJobsTableExists()) {
            return null;
        }

        if ($id <= 0) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE id = %d LIMIT 1',
                $id
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getDueJobs(int $limit = 10): array
    {
        if (!$this->providerSyncJobsTableExists()) {
            return [];
        }

        $limit = \max(1, \min(100, $limit));
        $now = $this->now();
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT *
                FROM ' . $this->tableName() . "
                WHERE status IN (%s, %s)
                    AND attempts < max_attempts
                    AND (run_after IS NULL OR run_after <= %s)
                ORDER BY priority ASC, id ASC
                LIMIT %d",
                self::STATUS_PENDING,
                self::STATUS_RETRYABLE,
                $now,
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function claimDueJob(int $id): ?array
    {
        if (!$this->providerSyncJobsTableExists()) {
            return null;
        }

        if ($id <= 0) {
            return null;
        }

        $now = $this->now();
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->tableName() . '
                SET status = %s,
                    locked_at = %s,
                    updated_at = %s
                WHERE id = %d
                    AND status IN (%s, %s)
                    AND attempts < max_attempts
                    AND (run_after IS NULL OR run_after <= %s)
                LIMIT 1',
                self::STATUS_RUNNING,
                $now,
                $now,
                $id,
                self::STATUS_PENDING,
                self::STATUS_RETRYABLE,
                $now
            )
        );

        return \is_int($updated) && $updated > 0 ? $this->get($id) : null;
    }

    public function markRunning(int $id): bool
    {
        return $this->updateStatus($id, self::STATUS_RUNNING, [
            'locked_at' => $this->now(),
        ]);
    }

    public function markSucceeded(int $id): bool
    {
        return $this->updateStatus($id, self::STATUS_SUCCEEDED, [
            'locked_at' => null,
            'last_error' => null,
            'run_after' => null,
        ]);
    }

    public function markFailed(int $id, string $error, bool $retry = true): bool
    {
        $job = $this->get($id);

        if (!\is_array($job)) {
            return false;
        }

        $attempts = \max(0, (int) ($job['attempts'] ?? 0)) + 1;
        $maxAttempts = \max(1, (int) ($job['max_attempts'] ?? 1));
        $status = self::STATUS_FAILED;
        $runAfter = null;

        if ($retry && $attempts < $maxAttempts) {
            $status = self::STATUS_RETRYABLE;
            $runAfter = $this->retryAfter($attempts);
        } elseif ($retry) {
            $status = self::STATUS_EXHAUSTED;
        }

        return $this->updateStatus($id, $status, [
            'attempts' => $attempts,
            'locked_at' => null,
            'last_error' => $error,
            'run_after' => $runAfter,
        ]);
    }

    public function releaseStaleRunningJobs(int $staleAfterMinutes = 30): int
    {
        if (!$this->providerSyncJobsTableExists()) {
            return 0;
        }

        $staleAfterMinutes = \max(5, \min(1440, $staleAfterMinutes));
        $cutoff = $this->dateMinusMinutes($staleAfterMinutes);
        $now = $this->now();
        $retryable = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->tableName() . '
                SET status = %s,
                    locked_at = NULL,
                    run_after = %s,
                    last_error = %s,
                    updated_at = %s
                WHERE status = %s
                    AND locked_at IS NOT NULL
                    AND locked_at <= %s
                    AND attempts < max_attempts',
                self::STATUS_RETRYABLE,
                $now,
                \__('Provider sync job lock expired before completion.', 'must-hotel-booking'),
                $now,
                self::STATUS_RUNNING,
                $cutoff
            )
        );
        $exhausted = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->tableName() . '
                SET status = %s,
                    locked_at = NULL,
                    run_after = NULL,
                    last_error = %s,
                    updated_at = %s
                WHERE status = %s
                    AND locked_at IS NOT NULL
                    AND locked_at <= %s
                    AND attempts >= max_attempts',
                self::STATUS_EXHAUSTED,
                \__('Provider sync job lock expired after all attempts were used.', 'must-hotel-booking'),
                $now,
                self::STATUS_RUNNING,
                $cutoff
            )
        );

        return (\is_int($retryable) ? $retryable : 0) + (\is_int($exhausted) ? $exhausted : 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatusSummary(string $provider = ''): array
    {
        $provider = $this->key($provider, 50);
        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_RUNNING => 0,
            self::STATUS_RETRYABLE => 0,
            self::STATUS_SUCCEEDED => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_EXHAUSTED => 0,
        ];

        if (!$this->providerSyncJobsTableExists()) {
            return [
                'counts' => $counts,
                'due' => 0,
                'last_error' => '',
            ];
        }

        $where = '';
        $params = [];

        if ($provider !== '') {
            $where = ' WHERE provider = %s';
            $params[] = $provider;
        }

        $sql = 'SELECT status, COUNT(*) AS total FROM ' . $this->tableName() . $where . ' GROUP BY status';
        $rows = !empty($params)
            ? $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A)
            : $this->wpdb->get_results($sql, ARRAY_A);

        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');
            $counts[$status] = (int) ($row['total'] ?? 0);
        }

        $dueCountSql = 'SELECT COUNT(*) FROM ' . $this->tableName() . '
            WHERE status IN (%s, %s)
                AND attempts < max_attempts
                AND (run_after IS NULL OR run_after <= %s)';
        $dueParams = [self::STATUS_PENDING, self::STATUS_RETRYABLE, $this->now()];

        if ($provider !== '') {
            $dueCountSql .= ' AND provider = %s';
            $dueParams[] = $provider;
        }

        $lastErrorSql = 'SELECT last_error
            FROM ' . $this->tableName() . '
            WHERE last_error IS NOT NULL
                AND last_error <> \'\'
                AND status IN (%s, %s, %s)';
        $lastErrorParams = [self::STATUS_RETRYABLE, self::STATUS_FAILED, self::STATUS_EXHAUSTED];

        if ($provider !== '') {
            $lastErrorSql .= ' AND provider = %s';
            $lastErrorParams[] = $provider;
        }

        $lastErrorSql .= ' ORDER BY updated_at DESC, id DESC LIMIT 1';

        return [
            'counts' => $counts,
            'due' => (int) $this->wpdb->get_var($this->wpdb->prepare($dueCountSql, ...$dueParams)),
            'last_error' => (string) $this->wpdb->get_var($this->wpdb->prepare($lastErrorSql, ...$lastErrorParams)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTargetSummary(string $provider, string $targetType, int $targetLocalId): array
    {
        $provider = $this->key($provider, 50);
        $targetType = $this->key($targetType, 80);
        $targetLocalId = \max(0, $targetLocalId);
        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_RUNNING => 0,
            self::STATUS_RETRYABLE => 0,
            self::STATUS_SUCCEEDED => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_EXHAUSTED => 0,
        ];

        if (!$this->providerSyncJobsTableExists() || $provider === '' || $targetType === '' || $targetLocalId <= 0) {
            return [
                'counts' => $counts,
                'open_count' => 0,
                'problem_count' => 0,
                'last_error' => '',
                'last_updated_at' => '',
            ];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT status, COUNT(*) AS total
                FROM ' . $this->tableName() . '
                WHERE provider = %s
                    AND target_type = %s
                    AND target_local_id = %d
                GROUP BY status',
                $provider,
                $targetType,
                $targetLocalId
            ),
            ARRAY_A
        );

        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');
            $counts[$status] = (int) ($row['total'] ?? 0);
        }

        $lastRow = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT last_error, updated_at
                FROM ' . $this->tableName() . '
                WHERE provider = %s
                    AND target_type = %s
                    AND target_local_id = %d
                    AND last_error IS NOT NULL
                    AND last_error <> \'\'
                ORDER BY updated_at DESC, id DESC
                LIMIT 1',
                $provider,
                $targetType,
                $targetLocalId
            ),
            ARRAY_A
        );

        $openCount = (int) $counts[self::STATUS_PENDING]
            + (int) $counts[self::STATUS_RUNNING]
            + (int) $counts[self::STATUS_RETRYABLE];
        $problemCount = (int) $counts[self::STATUS_RETRYABLE]
            + (int) $counts[self::STATUS_FAILED]
            + (int) $counts[self::STATUS_EXHAUSTED];

        return [
            'counts' => $counts,
            'open_count' => $openCount,
            'problem_count' => $problemCount,
            'last_error' => \is_array($lastRow) ? (string) ($lastRow['last_error'] ?? '') : '',
            'last_updated_at' => \is_array($lastRow) ? (string) ($lastRow['updated_at'] ?? '') : '',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentProblemJobs(string $provider = '', int $limit = 5): array
    {
        if (!$this->providerSyncJobsTableExists()) {
            return [];
        }

        $provider = $this->key($provider, 50);
        $limit = \max(1, \min(25, $limit));
        $statuses = [self::STATUS_RETRYABLE, self::STATUS_FAILED, self::STATUS_EXHAUSTED];
        $params = $statuses;
        $where = 'status IN (%s, %s, %s)';

        if ($provider !== '') {
            $where .= ' AND provider = %s';
            $params[] = $provider;
        }

        $params[] = $limit;
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, provider, operation, target_type, target_local_id, target_external_id, status, attempts, max_attempts, run_after, last_error, updated_at
                FROM ' . $this->tableName() . '
                WHERE ' . $where . '
                ORDER BY updated_at DESC, id DESC
                LIMIT %d',
                ...$params
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $updates
     */
    public function updateStatus(int $id, string $status, array $updates = []): bool
    {
        if (!$this->providerSyncJobsTableExists()) {
            return false;
        }

        if ($id <= 0) {
            return false;
        }

        $data = \array_merge($updates, [
            'status' => $this->status($status),
            'updated_at' => $this->now(),
        ]);

        return $this->wpdb->update(
            $this->tableName(),
            $data,
            ['id' => $id],
            $this->formatsFor($data),
            ['%d']
        ) !== false;
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private function normalizeJobData(array $job): array
    {
        return [
            'provider' => $this->key((string) ($job['provider'] ?? ''), 50),
            'operation' => $this->text((string) ($job['operation'] ?? ''), 100),
            'target_type' => $this->key((string) ($job['target_type'] ?? ''), 80),
            'target_local_id' => isset($job['target_local_id']) ? \max(0, (int) $job['target_local_id']) : 0,
            'target_external_id' => $this->text((string) ($job['target_external_id'] ?? ''), 191),
            'status' => $this->status((string) ($job['status'] ?? self::STATUS_PENDING)),
            'attempts' => isset($job['attempts']) ? \max(0, (int) $job['attempts']) : 0,
            'max_attempts' => isset($job['max_attempts']) ? \max(1, (int) $job['max_attempts']) : 5,
            'priority' => isset($job['priority']) ? \max(0, (int) $job['priority']) : 10,
            'run_after' => $this->nullableText($job['run_after'] ?? null),
            'locked_at' => $this->nullableText($job['locked_at'] ?? null),
            'last_error' => $this->nullableText($job['last_error'] ?? null),
            'payload' => $this->json($job['payload'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @return array<int, string> */
    private function formatsFor(array $row): array
    {
        $formats = [];

        foreach ($row as $key => $value) {
            unset($value);
            $formats[] = \in_array($key, ['target_local_id', 'attempts', 'max_attempts', 'priority'], true) ? '%d' : '%s';
        }

        return $formats;
    }

    private function status(string $status): string
    {
        $status = $this->key($status, 40);

        return \in_array($status, [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_RETRYABLE, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_EXHAUSTED], true)
            ? $status
            : self::STATUS_PENDING;
    }

    private function key(string $value, int $maxLength): string
    {
        $value = \function_exists('sanitize_key') ? \sanitize_key($value) : \strtolower(\preg_replace('/[^a-zA-Z0-9_\-]/', '', $value) ?? '');

        return \substr($value, 0, $maxLength);
    }

    private function text(string $value, int $maxLength): string
    {
        $value = \function_exists('sanitize_text_field') ? \sanitize_text_field($value) : \trim(\strip_tags($value));

        return \substr($value, 0, $maxLength);
    }

    /** @param mixed $value */
    private function nullableText($value): ?string
    {
        $value = \is_scalar($value) ? \trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }

    /** @param mixed $value */
    private function json($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (\is_string($value)) {
            return $value;
        }

        $json = \function_exists('wp_json_encode') ? \wp_json_encode($value) : \json_encode($value);

        return \is_string($json) ? $json : null;
    }

    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }

    private function retryAfter(int $attempts): string
    {
        $minutes = [5, 15, 30, 60, 120];
        $index = \max(0, \min(\count($minutes) - 1, $attempts - 1));

        return $this->datePlusMinutes($minutes[$index]);
    }

    private function datePlusMinutes(int $minutes): string
    {
        return \date('Y-m-d H:i:s', \strtotime($this->now()) + (\max(1, $minutes) * 60));
    }

    private function dateMinusMinutes(int $minutes): string
    {
        return \date('Y-m-d H:i:s', \strtotime($this->now()) - (\max(1, $minutes) * 60));
    }
}
