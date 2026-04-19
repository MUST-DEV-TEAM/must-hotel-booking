<?php

namespace MustHotelBooking\Provider\Storage;

use MustHotelBooking\Database\AbstractRepository;

final class ProviderMappingRepository extends AbstractRepository
{
    private function tableName(): string
    {
        return $this->wpdb->prefix . 'mhb_provider_mappings';
    }

    /**
     * @param array<string, mixed> $mapping
     */
    public function save(array $mapping): int
    {
        $id = isset($mapping['id']) ? \max(0, (int) $mapping['id']) : 0;
        $data = $this->normalizeMappingData($mapping);

        if ($data['provider'] === '' || $data['entity_type'] === '') {
            return 0;
        }

        if ($id <= 0 && (int) $data['local_id'] > 0) {
            $existing = $this->findByLocal(
                (string) $data['provider'],
                (string) $data['entity_type'],
                (int) $data['local_id'],
                (string) $data['local_table']
            );

            if (\is_array($existing)) {
                $id = (int) ($existing['id'] ?? 0);
            }
        }

        if ($id > 0) {
            $data['updated_at'] = $this->now();
            $updated = $this->wpdb->update(
                $this->tableName(),
                $data,
                ['id' => $id],
                $this->formatsFor($data),
                ['%d']
            );

            return $updated !== false ? $id : 0;
        }

        $now = $this->now();
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $inserted = $this->wpdb->insert($this->tableName(), $data, $this->formatsFor($data));

        return $inserted !== false ? (int) $this->wpdb->insert_id : 0;
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
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

    /** @return array<string, mixed>|null */
    public function findByLocal(string $provider, string $entityType, int $localId, string $localTable = ''): ?array
    {
        if ($provider === '' || $entityType === '' || $localId <= 0) {
            return null;
        }

        $sql = 'SELECT * FROM ' . $this->tableName() . ' WHERE provider = %s AND entity_type = %s AND local_id = %d';
        $params = [$this->key($provider), $this->entity($entityType), $localId];

        if ($localTable !== '') {
            $sql .= ' AND local_table = %s';
            $params[] = $this->text($localTable, 100);
        }

        $sql .= ' LIMIT 1';

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare($sql, $params),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByExternal(string $provider, string $entityType, string $externalId): ?array
    {
        if ($provider === '' || $entityType === '' || $externalId === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE provider = %s AND entity_type = %s AND external_id = %s LIMIT 1',
                $this->key($provider),
                $this->entity($entityType),
                $this->text($externalId, 191)
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        return $this->wpdb->delete($this->tableName(), ['id' => $id], ['%d']) !== false;
    }

    /**
     * List all mappings for a provider, optionally filtered by entity type.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForProvider(string $provider, string $entityType = ''): array
    {
        if ($provider === '') {
            return [];
        }

        if ($entityType !== '') {
            $sql = $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE provider = %s AND entity_type = %s ORDER BY local_id ASC',
                $this->key($provider),
                $this->entity($entityType)
            );
        } else {
            $sql = $this->wpdb->prepare(
                'SELECT * FROM ' . $this->tableName() . ' WHERE provider = %s ORDER BY entity_type ASC, local_id ASC',
                $this->key($provider)
            );
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * Count mappings for a provider + entity type.
     */
    public function countForProvider(string $provider, string $entityType = ''): int
    {
        if ($provider === '') {
            return 0;
        }

        if ($entityType !== '') {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $this->tableName() . ' WHERE provider = %s AND entity_type = %s',
                    $this->key($provider),
                    $this->entity($entityType)
                )
            );
        } else {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . $this->tableName() . ' WHERE provider = %s',
                    $this->key($provider)
                )
            );
        }

        return \is_numeric($count) ? (int) $count : 0;
    }

    /** @param array<string, mixed> $mapping @return array<string, mixed> */
    private function normalizeMappingData(array $mapping): array
    {
        return [
            'provider' => $this->key((string) ($mapping['provider'] ?? '')),
            'entity_type' => $this->entity((string) ($mapping['entity_type'] ?? '')),
            'local_table' => $this->text((string) ($mapping['local_table'] ?? ''), 100),
            'local_id' => isset($mapping['local_id']) ? \max(0, (int) $mapping['local_id']) : 0,
            'external_id' => $this->text((string) ($mapping['external_id'] ?? ''), 191),
            'external_code' => $this->text((string) ($mapping['external_code'] ?? ''), 191),
            'external_parent_id' => $this->text((string) ($mapping['external_parent_id'] ?? ''), 191),
            'display_name' => $this->text((string) ($mapping['display_name'] ?? ''), 191),
            'status' => $this->key((string) ($mapping['status'] ?? 'active')),
            'metadata' => $this->json($mapping['metadata'] ?? null),
            'last_synced_at' => $this->nullableText($mapping['last_synced_at'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @return array<int, string> */
    private function formatsFor(array $row): array
    {
        $formats = [];

        foreach ($row as $key => $value) {
            unset($value);
            $formats[] = $key === 'local_id' ? '%d' : '%s';
        }

        return $formats;
    }

    private function key(string $value): string
    {
        $value = \function_exists('sanitize_key') ? \sanitize_key($value) : \strtolower(\preg_replace('/[^a-zA-Z0-9_\-]/', '', $value) ?? '');

        return \substr($value, 0, 50);
    }

    private function entity(string $value): string
    {
        $value = \function_exists('sanitize_key') ? \sanitize_key($value) : \strtolower(\preg_replace('/[^a-zA-Z0-9_\-]/', '', $value) ?? '');

        return \substr($value, 0, 80);
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
