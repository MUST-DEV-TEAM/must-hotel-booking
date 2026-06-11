<?php

namespace MustHotelBooking\Database;

final class ClockFolioAccountingRepository extends AbstractRepository
{
    public function accountingTableExists(): bool
    {
        return $this->tableExists('clock_folio_accounting');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        if (!$this->accountingTableExists()) {
            return 0;
        }

        $row = $this->normalizeData($data);
        $inserted = $this->wpdb->insert(
            $this->table('clock_folio_accounting'),
            $row,
            $this->formats($row)
        );

        return $inserted === false ? 0 : (int) $this->wpdb->insert_id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if ($id <= 0 || empty($data) || !$this->accountingTableExists()) {
            return false;
        }

        $row = $this->normalizeData($data, false);
        $updated = $this->wpdb->update(
            $this->table('clock_folio_accounting'),
            $row,
            ['id' => $id],
            $this->formats($row),
            ['%d']
        );

        return \is_int($updated);
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        if ($id <= 0 || !$this->accountingTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('clock_folio_accounting') . ' WHERE id = %d LIMIT 1',
                $id
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $idempotencyKey): ?array
    {
        $idempotencyKey = \trim($idempotencyKey);

        if ($idempotencyKey === '' || !$this->accountingTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('clock_folio_accounting') . '
                WHERE idempotency_key = %s
                ORDER BY id DESC
                LIMIT 1',
                $idempotencyKey
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    public function getOrCreateByIdempotencyKey(string $idempotencyKey, array $data): array
    {
        $existing = $this->findByIdempotencyKey($idempotencyKey);

        if (\is_array($existing)) {
            return $existing;
        }

        $data['idempotency_key'] = $idempotencyKey;
        $id = $this->create($data);

        return $id > 0 ? (array) $this->get($id) : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getForReservation(int $reservationId): array
    {
        if ($reservationId <= 0 || !$this->accountingTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('clock_folio_accounting') . '
                WHERE reservation_id = %d
                ORDER BY created_at DESC, id DESC',
                $reservationId
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function findPostedPaymentForRefund(array $refund): ?array
    {
        if (!$this->accountingTableExists()) {
            return null;
        }

        $paymentId = isset($refund['payment_id']) ? (int) $refund['payment_id'] : 0;
        $reservationId = isset($refund['reservation_id']) ? (int) $refund['reservation_id'] : 0;
        $gateway = isset($refund['gateway']) ? \sanitize_key((string) $refund['gateway']) : '';
        $providerPaymentReference = isset($refund['provider_payment_reference'])
            ? \trim((string) $refund['provider_payment_reference'])
            : '';

        if ($paymentId > 0) {
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    'SELECT * FROM ' . $this->table('clock_folio_accounting') . '
                    WHERE direction = %s
                        AND payment_id = %d
                        AND status = %s
                        AND clock_folio_id <> \'\'
                    ORDER BY id DESC
                    LIMIT 1',
                    'payment',
                    $paymentId,
                    'posted'
                ),
                ARRAY_A
            );

            if (\is_array($row)) {
                return $row;
            }
        }

        if ($reservationId <= 0 || $gateway === '' || $providerPaymentReference === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('clock_folio_accounting') . '
                WHERE direction = %s
                    AND reservation_id = %d
                    AND gateway = %s
                    AND provider_transaction_id = %s
                    AND status = %s
                    AND clock_folio_id <> \'\'
                ORDER BY id DESC
                LIMIT 1',
                'payment',
                $reservationId,
                $gateway,
                $providerPaymentReference,
                'posted'
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    public function getSummary(): array
    {
        $summary = [
            'table_exists' => $this->accountingTableExists(),
            'total' => 0,
            'posted' => 0,
            'failed' => 0,
            'manual_review' => 0,
            'pending' => 0,
            'latest_error' => '',
        ];

        if (!$summary['table_exists']) {
            return $summary;
        }

        $table = $this->table('clock_folio_accounting');
        $summary['total'] = (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        $summary['posted'] = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'posted'");
        $summary['failed'] = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'");
        $summary['manual_review'] = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'manual_review'");
        $summary['pending'] = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending', 'retrying')");
        $summary['latest_error'] = (string) $this->wpdb->get_var(
            "SELECT last_error FROM {$table} WHERE last_error <> '' ORDER BY updated_at DESC, id DESC LIMIT 1"
        );

        return $summary;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeData(array $data, bool $withDefaults = true): array
    {
        $now = $this->now();
        $defaults = $withDefaults ? [
            'payment_id' => 0,
            'refund_id' => 0,
            'reservation_id' => 0,
            'booking_id' => '',
            'gateway' => '',
            'provider_transaction_id' => '',
            'provider_refund_id' => '',
            'clock_booking_id' => '',
            'clock_reservation_id' => '',
            'clock_folio_id' => '',
            'clock_credit_item_id' => '',
            'direction' => '',
            'amount' => 0.0,
            'amount_minor' => 0,
            'currency' => '',
            'status' => 'pending',
            'verification_status' => '',
            'idempotency_key' => '',
            'attempts' => 0,
            'last_error_code' => '',
            'last_error' => '',
            'next_retry_at' => null,
            'posted_at' => null,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ] : [];

        $row = \array_merge($defaults, $data);

        if (isset($row['gateway'])) {
            $row['gateway'] = \sanitize_key((string) $row['gateway']);
        }

        if (isset($row['direction'])) {
            $row['direction'] = \sanitize_key((string) $row['direction']);
        }

        if (isset($row['status'])) {
            $row['status'] = \sanitize_key((string) $row['status']);
        }

        if (isset($row['verification_status'])) {
            $row['verification_status'] = \sanitize_key((string) $row['verification_status']);
        }

        if (isset($row['currency'])) {
            $row['currency'] = \strtoupper(\sanitize_text_field((string) $row['currency']));
        }

        foreach (['booking_id', 'provider_transaction_id', 'provider_refund_id', 'clock_booking_id', 'clock_reservation_id', 'clock_folio_id', 'clock_credit_item_id', 'idempotency_key', 'last_error_code'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = \sanitize_text_field((string) $row[$field]);
            }
        }

        if (isset($row['last_error'])) {
            $row['last_error'] = \sanitize_textarea_field((string) $row['last_error']);
        }

        return $row;
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function formats(array $data): array
    {
        $formats = [];

        foreach ($data as $key => $value) {
            if (\in_array($key, ['payment_id', 'refund_id', 'reservation_id', 'amount_minor', 'attempts'], true)) {
                $formats[] = '%d';
                continue;
            }

            if ($key === 'amount') {
                $formats[] = '%f';
                continue;
            }

            $formats[] = '%s';
        }

        return $formats;
    }

    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
