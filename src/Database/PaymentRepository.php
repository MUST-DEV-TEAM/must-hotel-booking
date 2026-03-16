<?php

namespace MustHotelBooking\Database;

final class PaymentRepository extends AbstractRepository
{
    public function getLatestPaymentIdForReservationMethod(int $reservationId, string $method): int
    {
        if ($reservationId <= 0 || \trim($method) === '') {
            return 0;
        }

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT id
                FROM ' . $this->table('payments') . '
                WHERE reservation_id = %d
                    AND method = %s
                ORDER BY id DESC
                LIMIT 1',
                $reservationId,
                $method
            )
        );
    }

    /**
     * @param array<string, mixed> $paymentData
     */
    public function createPayment(array $paymentData): int
    {
        $inserted = $this->wpdb->insert(
            $this->table('payments'),
            $paymentData,
            $this->resolvePaymentFormats($paymentData)
        );

        if ($inserted === false) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $paymentData
     */
    public function updatePayment(int $paymentId, array $paymentData): bool
    {
        if ($paymentId <= 0 || empty($paymentData)) {
            return false;
        }

        $updated = $this->wpdb->update(
            $this->table('payments'),
            $paymentData,
            ['id' => $paymentId],
            $this->resolvePaymentFormats($paymentData),
            ['%d']
        );

        return \is_int($updated);
    }

    /**
     * @return array<int, int>
     */
    public function findReservationIdsByTransactionId(string $transactionId): array
    {
        if (\trim($transactionId) === '') {
            return [];
        }

        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT reservation_id
                FROM ' . $this->table('payments') . '
                WHERE transaction_id = %s',
                $transactionId
            )
        );

        return $this->normalizeIds(\is_array($rows) ? $rows : []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCouponByCode(string $couponCode): ?array
    {
        $needle = \strtoupper(\trim($couponCode));
        $needle = (string) \preg_replace('/[^A-Z0-9_-]/', '', $needle);

        if ($needle === '' || !$this->tableExists('coupons')) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT
                    id,
                    code,
                    discount_type,
                    discount_value,
                    valid_from,
                    valid_until,
                    usage_limit,
                    usage_count
                FROM ' . $this->table('coupons') . '
                WHERE code = %s
                LIMIT 1',
                $needle
            ),
            ARRAY_A
        );

        if (!\is_array($row)) {
            return null;
        }

        $today = \current_time('Y-m-d');
        $validFrom = isset($row['valid_from']) ? (string) $row['valid_from'] : '';
        $validUntil = isset($row['valid_until']) ? (string) $row['valid_until'] : '';

        if ($validFrom === '' || $validUntil === '' || $today < $validFrom || $today > $validUntil) {
            return null;
        }

        $usageLimit = isset($row['usage_limit']) ? (int) $row['usage_limit'] : 0;
        $usageCount = isset($row['usage_count']) ? (int) $row['usage_count'] : 0;

        if ($usageLimit > 0 && $usageCount >= $usageLimit) {
            return null;
        }

        $discountType = isset($row['discount_type']) ? \sanitize_key((string) $row['discount_type']) : 'percentage';

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : 0,
            'code' => isset($row['code']) ? (string) $row['code'] : $needle,
            'type' => $discountType === 'fixed' ? 'fixed' : 'percent',
            'value' => isset($row['discount_value']) ? (float) $row['discount_value'] : 0.0,
            'usage_limit' => $usageLimit,
            'usage_count' => $usageCount,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ];
    }

    public function incrementCouponUsage(int $couponId): bool
    {
        if ($couponId <= 0 || !$this->tableExists('coupons')) {
            return false;
        }

        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table('coupons') . '
                SET usage_count = usage_count + 1
                WHERE id = %d
                    AND (usage_limit = 0 OR usage_count < usage_limit)',
                $couponId
            )
        );

        return \is_int($updated) && $updated > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTaxFeeRules(): array
    {
        if (!$this->tableExists('taxes')) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            'SELECT id, name, rule_type, rule_value, apply_mode
            FROM ' . $this->table('taxes') . '
            ORDER BY id ASC',
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $paymentData
     * @return array<int, string>
     */
    private function resolvePaymentFormats(array $paymentData): array
    {
        $formats = [];

        foreach (\array_keys($paymentData) as $field) {
            if ($field === 'amount') {
                $formats[] = '%f';
                continue;
            }

            if ($field === 'reservation_id') {
                $formats[] = '%d';
                continue;
            }

            $formats[] = '%s';
        }

        return $formats;
    }
}
