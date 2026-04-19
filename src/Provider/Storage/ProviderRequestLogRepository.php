<?php

namespace MustHotelBooking\Provider\Storage;

use MustHotelBooking\Database\AbstractRepository;

final class ProviderRequestLogRepository extends AbstractRepository
{
    private function tableName(): string
    {
        return $this->wpdb->prefix . 'mhb_provider_request_logs';
    }

    public function providerRequestLogsTableExists(): bool
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
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        if (!$this->providerRequestLogsTableExists()) {
            return 0;
        }

        $row = $this->normalizeLogData($data);
        $row['created_at'] = $this->now();
        $inserted = $this->wpdb->insert(
            $this->tableName(),
            $row,
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return $inserted !== false ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function complete(int $id, array $data): bool
    {
        if (!$this->providerRequestLogsTableExists()) {
            return false;
        }

        if ($id <= 0) {
            return false;
        }

        $row = $this->normalizeLogData($data, false);
        unset($row['provider'], $row['operation'], $row['direction'], $row['correlation_id'], $row['idempotency_key'], $row['reservation_id'], $row['external_id'], $row['request_summary'], $row['created_at']);

        if (empty($row)) {
            return true;
        }

        return $this->wpdb->update(
            $this->tableName(),
            $row,
            ['id' => $id],
            $this->formatsFor($row),
            ['%d']
        ) !== false;
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        if (!$this->providerRequestLogsTableExists()) {
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

    public function hasSuccessfulLog(string $provider, string $operation, string $direction, string $idempotencyKey): bool
    {
        if (!$this->providerRequestLogsTableExists()) {
            return false;
        }

        $provider = $this->key($provider, 50);
        $operation = $this->text($operation, 100);
        $direction = $this->key($direction, 20);
        $idempotencyKey = $this->text($idempotencyKey, 191);

        if ($provider === '' || $operation === '' || $idempotencyKey === '') {
            return false;
        }

        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->tableName() . '
                WHERE provider = %s
                    AND operation = %s
                    AND direction = %s
                    AND idempotency_key = %s
                    AND success = 1',
                $provider,
                $operation,
                \in_array($direction, ['inbound', 'outbound'], true) ? $direction : 'inbound',
                $idempotencyKey
            )
        );

        return $count > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInboundSummary(string $provider = ''): array
    {
        $provider = $this->key($provider, 50);
        $summary = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'last_error' => '',
        ];

        if (!$this->providerRequestLogsTableExists()) {
            return $summary;
        }

        $where = 'direction = %s';
        $params = ['inbound'];

        if ($provider !== '') {
            $where .= ' AND provider = %s';
            $params[] = $provider;
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT success, COUNT(*) AS total
                FROM ' . $this->tableName() . '
                WHERE ' . $where . '
                GROUP BY success',
                ...$params
            ),
            ARRAY_A
        );

        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $count = (int) ($row['total'] ?? 0);
            $summary['total'] += $count;

            if (!empty($row['success'])) {
                $summary['successful'] += $count;
            } else {
                $summary['failed'] += $count;
            }
        }

        $lastErrorParams = ['inbound'];
        $lastErrorWhere = 'direction = %s AND success = 0 AND error_message IS NOT NULL AND error_message <> \'\'';

        if ($provider !== '') {
            $lastErrorWhere .= ' AND provider = %s';
            $lastErrorParams[] = $provider;
        }

        $summary['last_error'] = (string) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT error_message
                FROM ' . $this->tableName() . '
                WHERE ' . $lastErrorWhere . '
                ORDER BY created_at DESC, id DESC
                LIMIT 1',
                ...$lastErrorParams
            )
        );

        return $summary;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeLogData(array $data, bool $includeDefaults = true): array
    {
        $defaults = [
            'provider' => '',
            'operation' => '',
            'direction' => 'outbound',
            'correlation_id' => '',
            'idempotency_key' => '',
            'reservation_id' => 0,
            'external_id' => '',
            'http_status' => 0,
            'success' => 0,
            'error_code' => '',
            'error_message' => null,
            'duration_ms' => 0,
            'request_summary' => null,
            'response_summary' => null,
        ];
        $source = $includeDefaults ? \array_merge($defaults, $data) : $data;
        $row = [];

        foreach ($source as $key => $value) {
            switch ($key) {
                case 'provider':
                    $row[$key] = $this->key((string) $value, 50);
                    break;
                case 'operation':
                case 'error_code':
                    $row[$key] = $this->text((string) $value, 100);
                    break;
                case 'direction':
                    $direction = $this->key((string) $value, 20);
                    $row[$key] = \in_array($direction, ['inbound', 'outbound'], true) ? $direction : 'outbound';
                    break;
                case 'correlation_id':
                case 'idempotency_key':
                case 'external_id':
                    $row[$key] = $this->text((string) $value, 191);
                    break;
                case 'reservation_id':
                case 'http_status':
                case 'duration_ms':
                    $row[$key] = \max(0, (int) $value);
                    break;
                case 'success':
                    $row[$key] = !empty($value) ? 1 : 0;
                    break;
                case 'error_message':
                    $row[$key] = $this->nullableText($value);
                    break;
                case 'request_summary':
                case 'response_summary':
                    $row[$key] = $this->json($value);
                    break;
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<int, string> */
    private function formatsFor(array $row): array
    {
        $formats = [];

        foreach ($row as $key => $value) {
            unset($value);
            $formats[] = \in_array($key, ['reservation_id', 'http_status', 'success', 'duration_ms'], true) ? '%d' : '%s';
        }

        return $formats;
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
}
