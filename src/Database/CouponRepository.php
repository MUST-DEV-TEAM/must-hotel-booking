<?php

namespace MustHotelBooking\Database;

final class CouponRepository extends AbstractRepository
{
    public function couponsTableExists(): bool
    {
        return $this->tableExists('coupons');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAdminCouponRows(array $filters = []): array
    {
        if (!$this->couponsTableExists()) {
            return [];
        }

        $filters = $this->normalizeFilters($filters);
        $usageJoin = $this->buildUsageJoin();
        $where = [];
        $params = [];
        $today = (string) $filters['today'];

        if ($filters['search'] !== '') {
            $like = '%' . $this->wpdb->esc_like((string) $filters['search']) . '%';
            $where[] = '(c.code LIKE %s OR c.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['status'] === 'active') {
            $where[] = 'c.is_active = 1 AND c.valid_from <= %s AND c.valid_until >= %s AND (c.usage_limit = 0 OR COALESCE(usage.used_count, 0) < c.usage_limit)';
            $params[] = $today;
            $params[] = $today;
        } elseif ($filters['status'] === 'inactive') {
            $where[] = 'c.is_active = 0';
        } elseif ($filters['status'] === 'expired') {
            $where[] = 'c.valid_until < %s';
            $params[] = $today;
        } elseif ($filters['status'] === 'scheduled') {
            $where[] = 'c.valid_from > %s';
            $params[] = $today;
        } elseif ($filters['status'] === 'fully_used') {
            $where[] = 'c.usage_limit > 0 AND COALESCE(usage.used_count, 0) >= c.usage_limit';
        } elseif ($filters['status'] === 'currently_valid') {
            $where[] = 'c.valid_from <= %s AND c.valid_until >= %s';
            $params[] = $today;
            $params[] = $today;
        }

        if ($filters['discount_type'] !== '') {
            $where[] = 'c.discount_type = %s';
            $params[] = (string) $filters['discount_type'];
        }

        $sql = 'SELECT
                c.id,
                c.code,
                c.name,
                c.is_active,
                c.discount_type,
                c.discount_value,
                c.minimum_booking_amount,
                c.valid_from,
                c.valid_until,
                c.usage_limit,
                c.updated_at,
                c.created_at,
                COALESCE(usage.used_count, 0) AS used_count,
                COALESCE(usage.last_used_at, \'\') AS last_used_at,
                COALESCE(usage.future_reservation_count, 0) AS future_reservation_count
            FROM ' . $this->table('coupons') . ' c
            ' . $usageJoin;

        if (!empty($where)) {
            $sql .= ' WHERE ' . \implode(' AND ', $where);
        }

        $sql .= ' ORDER BY c.created_at DESC, c.id DESC LIMIT %d OFFSET %d';
        $params[] = (int) $filters['per_page'];
        $params[] = (int) $filters['offset'];
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    public function countAdminCouponRows(array $filters = []): int
    {
        if (!$this->couponsTableExists()) {
            return 0;
        }

        $filters = $this->normalizeFilters($filters);
        $usageJoin = $this->buildUsageJoin();
        $where = [];
        $params = [];
        $today = (string) $filters['today'];

        if ($filters['search'] !== '') {
            $like = '%' . $this->wpdb->esc_like((string) $filters['search']) . '%';
            $where[] = '(c.code LIKE %s OR c.name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['status'] === 'active') {
            $where[] = 'c.is_active = 1 AND c.valid_from <= %s AND c.valid_until >= %s AND (c.usage_limit = 0 OR COALESCE(usage.used_count, 0) < c.usage_limit)';
            $params[] = $today;
            $params[] = $today;
        } elseif ($filters['status'] === 'inactive') {
            $where[] = 'c.is_active = 0';
        } elseif ($filters['status'] === 'expired') {
            $where[] = 'c.valid_until < %s';
            $params[] = $today;
        } elseif ($filters['status'] === 'scheduled') {
            $where[] = 'c.valid_from > %s';
            $params[] = $today;
        } elseif ($filters['status'] === 'fully_used') {
            $where[] = 'c.usage_limit > 0 AND COALESCE(usage.used_count, 0) >= c.usage_limit';
        } elseif ($filters['status'] === 'currently_valid') {
            $where[] = 'c.valid_from <= %s AND c.valid_until >= %s';
            $params[] = $today;
            $params[] = $today;
        }

        if ($filters['discount_type'] !== '') {
            $where[] = 'c.discount_type = %s';
            $params[] = (string) $filters['discount_type'];
        }

        $sql = 'SELECT COUNT(*)
            FROM ' . $this->table('coupons') . ' c
            ' . $usageJoin;

        if (!empty($where)) {
            $sql .= ' WHERE ' . \implode(' AND ', $where);
        }

        $count = empty($params)
            ? $this->wpdb->get_var($sql)
            : $this->wpdb->get_var($this->wpdb->prepare($sql, ...$params));

        return $count !== null ? (int) $count : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCouponById(int $couponId): ?array
    {
        if ($couponId <= 0 || !$this->couponsTableExists()) {
            return null;
        }

        $usageJoin = $this->buildUsageJoin();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT
                    c.id,
                    c.code,
                    c.name,
                    c.is_active,
                    c.discount_type,
                    c.discount_value,
                    c.minimum_booking_amount,
                    c.valid_from,
                    c.valid_until,
                    c.usage_limit,
                    c.updated_at,
                    c.created_at,
                    COALESCE(usage.used_count, 0) AS used_count,
                    COALESCE(usage.last_used_at, \'\') AS last_used_at,
                    COALESCE(usage.future_reservation_count, 0) AS future_reservation_count
                FROM ' . $this->table('coupons') . ' c
                ' . $usageJoin . '
                WHERE c.id = %d
                LIMIT 1',
                $couponId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCouponByCode(string $couponCode): ?array
    {
        if (!$this->couponsTableExists()) {
            return null;
        }

        $normalized = \strtoupper(\trim($couponCode));

        if ($normalized === '') {
            return null;
        }

        $usageJoin = $this->buildUsageJoin();
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT
                    c.id,
                    c.code,
                    c.name,
                    c.is_active,
                    c.discount_type,
                    c.discount_value,
                    c.minimum_booking_amount,
                    c.valid_from,
                    c.valid_until,
                    c.usage_limit,
                    c.updated_at,
                    c.created_at,
                    COALESCE(usage.used_count, 0) AS used_count,
                    COALESCE(usage.last_used_at, \'\') AS last_used_at,
                    COALESCE(usage.future_reservation_count, 0) AS future_reservation_count
                FROM ' . $this->table('coupons') . ' c
                ' . $usageJoin . '
                WHERE UPPER(c.code) = %s
                LIMIT 1',
                $normalized
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $couponData
     */
    public function saveCoupon(int $couponId, array $couponData): int
    {
        if (!$this->couponsTableExists()) {
            return 0;
        }

        $payload = [
            'code' => isset($couponData['code']) ? (string) $couponData['code'] : '',
            'name' => isset($couponData['name']) ? (string) $couponData['name'] : '',
            'is_active' => !empty($couponData['is_active']) ? 1 : 0,
            'discount_type' => isset($couponData['discount_type']) ? (string) $couponData['discount_type'] : 'percentage',
            'discount_value' => isset($couponData['discount_value']) ? (float) $couponData['discount_value'] : 0.0,
            'minimum_booking_amount' => isset($couponData['minimum_booking_amount']) ? (float) $couponData['minimum_booking_amount'] : 0.0,
            'valid_from' => isset($couponData['valid_from']) ? (string) $couponData['valid_from'] : '',
            'valid_until' => isset($couponData['valid_until']) ? (string) $couponData['valid_until'] : '',
            'usage_limit' => isset($couponData['usage_limit']) ? (int) $couponData['usage_limit'] : 0,
            'updated_at' => \current_time('mysql'),
        ];

        if ($couponId > 0) {
            $updated = $this->wpdb->update(
                $this->table('coupons'),
                $payload,
                ['id' => $couponId],
                ['%s', '%s', '%d', '%s', '%f', '%f', '%s', '%s', '%d', '%s'],
                ['%d']
            );

            return $updated !== false ? $couponId : 0;
        }

        $payload['created_at'] = \current_time('mysql');
        $inserted = $this->wpdb->insert(
            $this->table('coupons'),
            $payload,
            ['%s', '%s', '%d', '%s', '%f', '%f', '%s', '%s', '%d', '%s', '%s']
        );

        return $inserted !== false ? (int) $this->wpdb->insert_id : 0;
    }

    public function deleteCoupon(int $couponId): bool
    {
        if ($couponId <= 0 || !$this->couponsTableExists()) {
            return false;
        }

        $deleted = $this->wpdb->delete($this->table('coupons'), ['id' => $couponId], ['%d']);

        return $deleted !== false;
    }

    public function couponHasUsage(int $couponId): bool
    {
        if ($couponId <= 0 || !$this->tableExists('reservations')) {
            return false;
        }

        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE coupon_id = %d',
                $couponId
            )
        );

        return $count !== null && (int) $count > 0;
    }

    public function couponCodeExists(string $couponCode, int $excludeId = 0): bool
    {
        if (!$this->couponsTableExists()) {
            return false;
        }

        $normalized = \strtoupper(\trim($couponCode));

        if ($normalized === '') {
            return false;
        }

        if ($excludeId > 0) {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT COUNT(*)
                    FROM ' . $this->table('coupons') . '
                    WHERE UPPER(code) = %s AND id <> %d',
                    $normalized,
                    $excludeId
                )
            );
        } else {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT COUNT(*)
                    FROM ' . $this->table('coupons') . '
                    WHERE UPPER(code) = %s',
                    $normalized
                )
            );
        }

        return $count !== null && (int) $count > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getReservationsUsingCoupon(int $couponId, int $limit = 50): array
    {
        if ($couponId <= 0 || !$this->tableExists('reservations')) {
            return [];
        }

        $limit = \max(1, \min(200, $limit));
        $roomJoin = $this->tableExists('rooms')
            ? ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id'
            : '';
        $guestJoin = $this->tableExists('guests')
            ? ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id'
            : '';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.checkin,
                    r.checkout,
                    r.status,
                    r.payment_status,
                    r.total_price,
                    r.coupon_code,
                    r.coupon_discount_total,
                    COALESCE(rm.name, \'\') AS room_name,
                    CONCAT_WS(\' \', COALESCE(g.first_name, \'\'), COALESCE(g.last_name, \'\')) AS guest_name,
                    COALESCE(g.email, \'\') AS guest_email
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                ' . $guestJoin . '
                WHERE r.coupon_id = %d
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT %d',
                $couponId,
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    private function buildUsageJoin(): string
    {
        if (!$this->tableExists('reservations')) {
            return ' LEFT JOIN (SELECT 0 AS coupon_id, 0 AS used_count, \'\' AS last_used_at, 0 AS future_reservation_count) usage ON usage.coupon_id = c.id';
        }

        $today = \current_time('Y-m-d');
        $consumingStatuses = [
            'confirmed',
            'completed',
        ];

        return ' LEFT JOIN (
                SELECT
                    r.coupon_id,
                    SUM(CASE WHEN r.status IN (\'' . \esc_sql($consumingStatuses[0]) . '\', \'' . \esc_sql($consumingStatuses[1]) . '\') THEN 1 ELSE 0 END) AS used_count,
                    MAX(CASE WHEN r.status IN (\'' . \esc_sql($consumingStatuses[0]) . '\', \'' . \esc_sql($consumingStatuses[1]) . '\') THEN r.created_at ELSE NULL END) AS last_used_at,
                    SUM(CASE WHEN r.checkin >= \'' . \esc_sql($today) . '\' AND r.status IN (\'' . \esc_sql($consumingStatuses[0]) . '\', \'' . \esc_sql($consumingStatuses[1]) . '\') THEN 1 ELSE 0 END) AS future_reservation_count
                FROM ' . $this->table('reservations') . ' r
                WHERE r.coupon_id > 0
                GROUP BY r.coupon_id
            ) usage ON usage.coupon_id = c.id';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $paged = isset($filters['paged']) ? (int) $filters['paged'] : 1;

        return [
            'search' => isset($filters['search']) ? \sanitize_text_field((string) $filters['search']) : '',
            'status' => isset($filters['status']) ? \sanitize_key((string) $filters['status']) : '',
            'discount_type' => isset($filters['discount_type']) ? \sanitize_key((string) $filters['discount_type']) : '',
            'today' => isset($filters['today']) ? \sanitize_text_field((string) $filters['today']) : \current_time('Y-m-d'),
            'per_page' => \max(1, \min(100, $perPage)),
            'paged' => \max(1, $paged),
            'offset' => (\max(1, $paged) - 1) * \max(1, \min(100, $perPage)),
        ];
    }
}
