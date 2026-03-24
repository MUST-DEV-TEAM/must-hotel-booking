<?php

namespace MustHotelBooking\Database;

final class ReportRepository extends AbstractRepository
{
    private function inventoryRoomsTable(): string
    {
        return $this->wpdb->prefix . 'mhb_rooms';
    }

    private function inventoryRoomsTableExists(): bool
    {
        $tableName = $this->inventoryRoomsTable();
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $tableName
            )
        );

        return \is_string($result) && $result !== '';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getReservationsCreatedInRange(array $filters): array
    {
        if (!$this->tableExists('reservations')) {
            return [];
        }

        $normalized = $this->normalizeFilters($filters);
        $params = [];
        $where = [
            'DATE(r.created_at) >= %s',
            'DATE(r.created_at) <= %s',
        ];
        $params[] = $normalized['date_from'];
        $params[] = $normalized['date_to'];

        if ($normalized['room_id'] > 0) {
            $where[] = '(r.room_id = %d OR r.room_type_id = %d)';
            $params[] = $normalized['room_id'];
            $params[] = $normalized['room_id'];
        }

        if ($normalized['reservation_status'] !== '') {
            $where[] = 'r.status = %s';
            $params[] = $normalized['reservation_status'];
        }

        $roomJoin = $this->tableExists('rooms')
            ? ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id'
            : '';
        $roomSelect = $this->tableExists('rooms')
            ? 'COALESCE(rm.name, \'\') AS room_name'
            : '\'\' AS room_name';

        $sql = 'SELECT
                r.id,
                r.booking_id,
                r.room_id,
                r.room_type_id,
                r.assigned_room_id,
                r.guest_id,
                r.checkin,
                r.checkout,
                r.guests,
                r.status,
                r.total_price,
                r.coupon_id,
                r.coupon_code,
                r.coupon_discount_total,
                r.payment_status,
                r.created_at,
                ' . $roomSelect . '
            FROM ' . $this->table('reservations') . ' r
            ' . $roomJoin . '
            WHERE ' . \implode(' AND ', $where) . '
            ORDER BY r.created_at ASC, r.id ASC';

        $prepared = $this->wpdb->prepare($sql, ...$params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getStayOverlapReservations(array $filters): array
    {
        if (!$this->tableExists('reservations')) {
            return [];
        }

        $normalized = $this->normalizeFilters($filters);
        $params = [];
        $where = [
            'r.checkin <= %s',
            'r.checkout > %s',
        ];
        $params[] = $normalized['date_to'];
        $params[] = $normalized['date_from'];

        if ($normalized['room_id'] > 0) {
            $where[] = '(r.room_id = %d OR r.room_type_id = %d)';
            $params[] = $normalized['room_id'];
            $params[] = $normalized['room_id'];
        }

        if ($normalized['reservation_status'] !== '') {
            $where[] = 'r.status = %s';
            $params[] = $normalized['reservation_status'];
        }

        $roomJoin = $this->tableExists('rooms')
            ? ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id'
            : '';
        $roomSelect = $this->tableExists('rooms')
            ? 'COALESCE(rm.name, \'\') AS room_name'
            : '\'\' AS room_name';

        $sql = 'SELECT
                r.id,
                r.booking_id,
                r.room_id,
                r.room_type_id,
                r.assigned_room_id,
                r.guest_id,
                r.checkin,
                r.checkout,
                r.guests,
                r.status,
                r.total_price,
                r.coupon_id,
                r.coupon_code,
                r.coupon_discount_total,
                r.payment_status,
                r.created_at,
                ' . $roomSelect . '
            FROM ' . $this->table('reservations') . ' r
            ' . $roomJoin . '
            WHERE ' . \implode(' AND ', $where) . '
            ORDER BY r.checkin ASC, r.checkout ASC, r.id ASC';

        $prepared = $this->wpdb->prepare($sql, ...$params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    public function countAvailableInventoryUnits(int $roomId = 0): int
    {
        if ($this->inventoryRoomsTableExists()) {
            if ($roomId > 0) {
                $count = $this->wpdb->get_var(
                    $this->wpdb->prepare(
                        'SELECT COUNT(*)
                        FROM ' . $this->inventoryRoomsTable() . '
                        WHERE room_type_id = %d
                            AND is_active = 1
                            AND is_bookable = 1',
                        $roomId
                    )
                );

                return $count !== null ? (int) $count : 0;
            }

            $count = $this->wpdb->get_var(
                'SELECT COUNT(*)
                FROM ' . $this->inventoryRoomsTable() . '
                WHERE is_active = 1
                    AND is_bookable = 1'
            );

            return $count !== null ? (int) $count : 0;
        }

        if (!$this->tableExists('rooms')) {
            return 0;
        }

        if ($roomId > 0) {
            $count = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT COUNT(*)
                    FROM ' . $this->table('rooms') . '
                    WHERE id = %d
                        AND is_active = 1
                        AND is_bookable = 1',
                    $roomId
                )
            );

            return $count !== null ? (int) $count : 0;
        }

        $count = $this->wpdb->get_var(
            'SELECT COUNT(*)
            FROM ' . $this->table('rooms') . '
            WHERE is_active = 1
                AND is_bookable = 1'
        );

        return $count !== null ? (int) $count : 0;
    }

    public function countPaymentsLinkedToMissingReservations(): int
    {
        if (!$this->tableExists('payments') || !$this->tableExists('reservations')) {
            return 0;
        }

        $count = $this->wpdb->get_var(
            'SELECT COUNT(*)
            FROM ' . $this->table('payments') . ' p
            LEFT JOIN ' . $this->table('reservations') . ' r ON r.id = p.reservation_id
            WHERE p.reservation_id > 0
                AND r.id IS NULL'
        );

        return $count !== null ? (int) $count : 0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $dateFrom = isset($filters['date_from']) ? \sanitize_text_field((string) $filters['date_from']) : '';
        $dateTo = isset($filters['date_to']) ? \sanitize_text_field((string) $filters['date_to']) : '';

        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $dateFrom = \current_time('Y-m-01');
        }

        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $dateTo = \current_time('Y-m-d');
        }

        if ($dateFrom > $dateTo) {
            $swap = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $swap;
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'room_id' => isset($filters['room_id']) ? \absint($filters['room_id']) : 0,
            'reservation_status' => isset($filters['reservation_status']) ? \sanitize_key((string) $filters['reservation_status']) : '',
        ];
    }
}
