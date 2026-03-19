<?php

namespace MustHotelBooking\Database;

final class PaymentRepository extends AbstractRepository
{
    public function paymentsTableExists(): bool
    {
        return $this->tableExists('payments');
    }

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

    public function getRevenueReceivedForDate(string $date): float
    {
        if ($date === '' || !$this->paymentsTableExists()) {
            return 0.0;
        }

        $amount = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COALESCE(SUM(amount), 0)
                FROM ' . $this->table('payments') . '
                WHERE status = %s
                    AND DATE(COALESCE(paid_at, created_at)) = %s',
                'paid',
                $date
            )
        );

        return $amount !== null ? (float) $amount : 0.0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentPaymentRows(int $limit = 10): array
    {
        if (!$this->paymentsTableExists()) {
            return [];
        }

        $limit = \max(1, \min(50, $limit));
        $reservationSelect = '\'\' AS booking_id, \'\' AS reservation_status, \'\' AS room_name, \'\' AS guest_name';
        $reservationJoin = '';

        if ($this->tableExists('reservations')) {
            $reservationSelect = 'COALESCE(r.booking_id, \'\') AS booking_id, COALESCE(r.status, \'\') AS reservation_status, \'\' AS room_name, \'\' AS guest_name';
            $reservationJoin = ' LEFT JOIN ' . $this->table('reservations') . ' r ON r.id = p.reservation_id';

            if ($this->tableExists('rooms')) {
                $reservationSelect = \str_replace('\'\' AS room_name', 'COALESCE(rm.name, \'\') AS room_name', $reservationSelect);
                $reservationJoin .= ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
            }

            if ($this->tableExists('guests')) {
                $reservationSelect = \str_replace('\'\' AS guest_name', 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name', $reservationSelect);
                $reservationJoin .= ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
            }
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    p.id,
                    p.reservation_id,
                    p.amount,
                    p.currency,
                    p.method,
                    p.status,
                    p.transaction_id,
                    p.paid_at,
                    p.created_at,
                    ' . $reservationSelect . '
                FROM ' . $this->table('payments') . ' p
                ' . $reservationJoin . '
                ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
                LIMIT %d',
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStripeIssueRows(string $cutoffDatetime, int $limit = 5): array
    {
        if ($cutoffDatetime === '' || !$this->paymentsTableExists()) {
            return [];
        }

        $limit = \max(1, \min(20, $limit));
        $reservationSelect = '\'\' AS booking_id, \'\' AS payment_status, \'\' AS reservation_status, \'\' AS room_name, \'\' AS guest_name';
        $reservationJoin = '';

        if ($this->tableExists('reservations')) {
            $reservationSelect = 'COALESCE(r.booking_id, \'\') AS booking_id, COALESCE(r.payment_status, \'\') AS payment_status, COALESCE(r.status, \'\') AS reservation_status, \'\' AS room_name, \'\' AS guest_name';
            $reservationJoin = ' LEFT JOIN ' . $this->table('reservations') . ' r ON r.id = p.reservation_id';

            if ($this->tableExists('rooms')) {
                $reservationSelect = \str_replace('\'\' AS room_name', 'COALESCE(rm.name, \'\') AS room_name', $reservationSelect);
                $reservationJoin .= ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
            }

            if ($this->tableExists('guests')) {
                $reservationSelect = \str_replace('\'\' AS guest_name', 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name', $reservationSelect);
                $reservationJoin .= ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
            }
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    p.id,
                    p.reservation_id,
                    p.amount,
                    p.currency,
                    p.method,
                    p.status,
                    p.transaction_id,
                    p.paid_at,
                    p.created_at,
                    ' . $reservationSelect . '
                FROM ' . $this->table('payments') . ' p
                ' . $reservationJoin . '
                WHERE p.method = %s
                    AND (
                        p.status = %s
                        OR (p.status = %s AND p.created_at <= %s)
                    )
                ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
                LIMIT %d',
                'stripe',
                'failed',
                'pending',
                $cutoffDatetime,
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentsForReservation(int $reservationId): array
    {
        if ($reservationId <= 0 || !$this->paymentsTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    id,
                    reservation_id,
                    amount,
                    currency,
                    method,
                    status,
                    transaction_id,
                    paid_at,
                    created_at
                FROM ' . $this->table('payments') . '
                WHERE reservation_id = %d
                ORDER BY COALESCE(paid_at, created_at) DESC, id DESC',
                $reservationId
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatestPaymentForReservation(int $reservationId): ?array
    {
        $rows = $this->getPaymentsForReservation($reservationId);

        return isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : null;
    }

    /**
     * @return array{amount_paid: float, latest_method: string, latest_status: string, latest_transaction_id: string, latest_paid_at: string, latest_created_at: string}
     */
    public function getReservationPaymentSummary(int $reservationId): array
    {
        $summary = [
            'amount_paid' => 0.0,
            'latest_method' => '',
            'latest_status' => '',
            'latest_transaction_id' => '',
            'latest_paid_at' => '',
            'latest_created_at' => '',
        ];

        if ($reservationId <= 0 || !$this->paymentsTableExists()) {
            return $summary;
        }

        $paidAmount = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COALESCE(SUM(amount), 0)
                FROM ' . $this->table('payments') . '
                WHERE reservation_id = %d
                    AND status = %s',
                $reservationId,
                'paid'
            )
        );

        if ($paidAmount !== null) {
            $summary['amount_paid'] = (float) $paidAmount;
        }

        $latestPayment = $this->getLatestPaymentForReservation($reservationId);

        if (!\is_array($latestPayment)) {
            return $summary;
        }

        $summary['latest_method'] = isset($latestPayment['method']) ? (string) $latestPayment['method'] : '';
        $summary['latest_status'] = isset($latestPayment['status']) ? (string) $latestPayment['status'] : '';
        $summary['latest_transaction_id'] = isset($latestPayment['transaction_id']) ? (string) $latestPayment['transaction_id'] : '';
        $summary['latest_paid_at'] = isset($latestPayment['paid_at']) ? (string) $latestPayment['paid_at'] : '';
        $summary['latest_created_at'] = isset($latestPayment['created_at']) ? (string) $latestPayment['created_at'] : '';

        return $summary;
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
