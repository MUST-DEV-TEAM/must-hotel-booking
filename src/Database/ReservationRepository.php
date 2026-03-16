<?php

namespace MustHotelBooking\Database;

final class ReservationRepository extends AbstractRepository
{
    private function inventoryLockTable(): string
    {
        return $this->wpdb->prefix . 'mhb_inventory_locks';
    }

    private function inventoryLockTableExists(): bool
    {
        $tableName = $this->inventoryLockTable();
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $tableName
            )
        );

        return \is_string($result) && $result !== '';
    }

    private function lockTableName(): string
    {
        if ($this->inventoryLockTableExists()) {
            return $this->inventoryLockTable();
        }

        return $this->table('locks');
    }

    public function bookingIdExists(string $bookingId): bool
    {
        if (\trim($bookingId) === '') {
            return false;
        }

        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $this->table('reservations') . ' WHERE booking_id = %s',
                $bookingId
            )
        );

        return $count > 0;
    }

    /**
     * @param array<string, mixed> $reservationData
     * @param array<int, string> $nonBlockingStatuses
     */
    public function createReservation(array $reservationData, array $nonBlockingStatuses = []): int
    {
        $roomId = isset($reservationData['room_id']) ? (int) $reservationData['room_id'] : 0;
        $roomTypeId = isset($reservationData['room_type_id']) ? (int) $reservationData['room_type_id'] : $roomId;
        $assignedRoomId = isset($reservationData['assigned_room_id']) ? (int) $reservationData['assigned_room_id'] : 0;
        $ratePlanId = isset($reservationData['rate_plan_id']) ? (int) $reservationData['rate_plan_id'] : 0;
        $guestId = isset($reservationData['guest_id']) ? (int) $reservationData['guest_id'] : 0;
        $guests = isset($reservationData['guests']) ? (int) $reservationData['guests'] : 0;
        $bookingId = isset($reservationData['booking_id']) ? (string) $reservationData['booking_id'] : '';
        $checkin = isset($reservationData['checkin']) ? (string) $reservationData['checkin'] : '';
        $checkout = isset($reservationData['checkout']) ? (string) $reservationData['checkout'] : '';
        $status = isset($reservationData['status']) ? (string) $reservationData['status'] : 'pending';
        $bookingSource = isset($reservationData['booking_source']) ? (string) $reservationData['booking_source'] : 'website';
        $notes = isset($reservationData['notes']) ? (string) $reservationData['notes'] : '';
        $totalPrice = isset($reservationData['total_price']) ? (float) $reservationData['total_price'] : 0.0;
        $paymentStatus = isset($reservationData['payment_status']) ? (string) $reservationData['payment_status'] : 'unpaid';
        $createdAt = isset($reservationData['created_at']) ? (string) $reservationData['created_at'] : \current_time('mysql');
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $availabilityColumn = $assignedRoomId > 0 ? 'assigned_room_id' : 'room_id';
        $availabilityTargetId = $assignedRoomId > 0 ? $assignedRoomId : $roomId;

        $sql = $this->wpdb->prepare(
            'INSERT INTO ' . $this->table('reservations') . '
                (booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, guests, status, booking_source, notes, total_price, payment_status, created_at)
            SELECT %s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s, %s, %f, %s, %s
            WHERE NOT EXISTS (
                SELECT 1
                FROM ' . $this->table('reservations') . ' r
                WHERE r.' . $availabilityColumn . ' = %d
                    AND r.checkin < %s
                    AND r.checkout > %s
                    AND r.status NOT IN (%s, %s, %s)
            )
            LIMIT 1',
            $bookingId,
            $roomId,
            $roomTypeId,
            $assignedRoomId,
            $ratePlanId,
            $guestId,
            $checkin,
            $checkout,
            $guests,
            $status,
            $bookingSource,
            $notes,
            $totalPrice,
            $paymentStatus,
            $createdAt,
            $availabilityTargetId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );

        $inserted = $this->wpdb->query($sql);

        if (!\is_int($inserted) || $inserted < 1) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $reservationData
     * @param array<int, string> $nonBlockingStatuses
     */
    public function createReservationFromLock(array $reservationData, string $sessionId, string $now, array $nonBlockingStatuses = []): int
    {
        $roomId = isset($reservationData['room_id']) ? (int) $reservationData['room_id'] : 0;
        $roomTypeId = isset($reservationData['room_type_id']) ? (int) $reservationData['room_type_id'] : $roomId;
        $assignedRoomId = isset($reservationData['assigned_room_id']) ? (int) $reservationData['assigned_room_id'] : 0;
        $ratePlanId = isset($reservationData['rate_plan_id']) ? (int) $reservationData['rate_plan_id'] : 0;
        $guestId = isset($reservationData['guest_id']) ? (int) $reservationData['guest_id'] : 0;
        $guests = isset($reservationData['guests']) ? (int) $reservationData['guests'] : 0;
        $bookingId = isset($reservationData['booking_id']) ? (string) $reservationData['booking_id'] : '';
        $checkin = isset($reservationData['checkin']) ? (string) $reservationData['checkin'] : '';
        $checkout = isset($reservationData['checkout']) ? (string) $reservationData['checkout'] : '';
        $status = isset($reservationData['status']) ? (string) $reservationData['status'] : 'pending';
        $totalPrice = isset($reservationData['total_price']) ? (float) $reservationData['total_price'] : 0.0;
        $paymentStatus = isset($reservationData['payment_status']) ? (string) $reservationData['payment_status'] : 'unpaid';
        $createdAt = isset($reservationData['created_at']) ? (string) $reservationData['created_at'] : $now;
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $locksTable = $this->lockTableName();
        $lockRoomId = $assignedRoomId > 0 ? $assignedRoomId : $roomId;
        $availabilityColumn = $assignedRoomId > 0 ? 'assigned_room_id' : 'room_id';
        $availabilityTargetId = $assignedRoomId > 0 ? $assignedRoomId : $roomId;

        $sql = $this->wpdb->prepare(
            'INSERT INTO ' . $this->table('reservations') . '
                (booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, guests, status, total_price, payment_status, created_at)
            SELECT %s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %f, %s, %s
            WHERE EXISTS (
                SELECT 1
                FROM ' . $locksTable . ' l
                WHERE l.room_id = %d
                    AND l.checkin = %s
                    AND l.checkout = %s
                    AND l.session_id = %s
                    AND l.expires_at > %s
            )
            AND NOT EXISTS (
                SELECT 1
                FROM ' . $this->table('reservations') . ' r
                WHERE r.' . $availabilityColumn . ' = %d
                    AND r.checkin < %s
                    AND r.checkout > %s
                    AND r.status NOT IN (%s, %s, %s)
            )
            LIMIT 1',
            $bookingId,
            $roomId,
            $roomTypeId,
            $assignedRoomId,
            $ratePlanId,
            $guestId,
            $checkin,
            $checkout,
            $guests,
            $status,
            $totalPrice,
            $paymentStatus,
            $createdAt,
            $lockRoomId,
            $checkin,
            $checkout,
            $sessionId,
            $now,
            $availabilityTargetId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );

        $inserted = $this->wpdb->query($sql);

        if (!\is_int($inserted) || $inserted < 1) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getReservation(int $reservationId): ?array
    {
        if ($reservationId <= 0) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT *
                FROM ' . $this->table('reservations') . '
                WHERE id = %d
                LIMIT 1',
                $reservationId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    public function getReservationsByIds(array $reservationIds): array
    {
        $reservationIds = $this->normalizeIds($reservationIds);

        if (empty($reservationIds)) {
            return [];
        }

        $sql = $this->wpdb->prepare(
            'SELECT id, booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, guests, status, total_price, payment_status, created_at
            FROM ' . $this->table('reservations') . '
            WHERE id IN (' . $this->buildIntegerPlaceholders($reservationIds) . ')
            ORDER BY id ASC',
            ...$reservationIds
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    public function updateReservationStatus(int $reservationId, string $status, string $paymentStatus = ''): bool
    {
        if ($reservationId <= 0) {
            return false;
        }

        $data = [
            'status' => $status,
        ];
        $formats = ['%s'];

        if ($paymentStatus !== '') {
            $data['payment_status'] = $paymentStatus;
            $formats[] = '%s';
        }

        $updated = $this->wpdb->update(
            $this->table('reservations'),
            $data,
            ['id' => $reservationId],
            $formats,
            ['%d']
        );

        return \is_int($updated);
    }

    public function updateReservationNotes(int $reservationId, string $notes): bool
    {
        if ($reservationId <= 0) {
            return false;
        }

        $updated = $this->wpdb->update(
            $this->table('reservations'),
            ['notes' => $notes],
            ['id' => $reservationId],
            ['%s'],
            ['%d']
        );

        return \is_int($updated);
    }

    public function assignInventoryRoomToReservation(int $reservationId, int $roomTypeId, int $assignedRoomId): bool
    {
        if ($reservationId <= 0 || $assignedRoomId <= 0) {
            return false;
        }

        $updated = $this->wpdb->update(
            $this->table('reservations'),
            [
                'room_type_id' => \max(0, $roomTypeId),
                'assigned_room_id' => $assignedRoomId,
            ],
            ['id' => $reservationId],
            ['%d', '%d'],
            ['%d']
        );

        return \is_int($updated);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getReservationEmailData(int $reservationId): ?array
    {
        if ($reservationId <= 0) {
            return null;
        }

        $sql = $this->wpdb->prepare(
            'SELECT
                r.id,
                r.booking_id,
                r.checkin,
                r.checkout,
                r.status,
                r.total_price,
                r.assigned_room_id,
                r.rate_plan_id,
                rm.name AS room_name,
                rp.name AS rate_plan_name,
                g.first_name,
                g.last_name,
                g.email AS guest_email
            FROM ' . $this->table('reservations') . ' r
            LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id
            LEFT JOIN ' . $this->wpdb->prefix . 'mhb_rate_plans' . ' rp ON rp.id = r.rate_plan_id
            LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id
            WHERE r.id = %d
            LIMIT 1',
            $reservationId
        );
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return \is_array($row) ? $row : null;
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    public function getConfirmationRowsByIds(array $reservationIds): array
    {
        $reservationIds = $this->normalizeIds($reservationIds);

        if (empty($reservationIds)) {
            return [];
        }

        $sql = $this->wpdb->prepare(
            $this->getConfirmationRowsQuery('r.id IN (' . $this->buildIntegerPlaceholders($reservationIds) . ')'),
            ...$reservationIds
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConfirmationRowsByBookingId(string $bookingId): array
    {
        if (\trim($bookingId) === '') {
            return [];
        }

        $sql = $this->wpdb->prepare(
            $this->getConfirmationRowsQuery('r.booking_id = %s'),
            $bookingId
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, int>
     */
    public function findExpiredPendingPaymentReservationIds(string $cutoff): array
    {
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT id
                FROM ' . $this->table('reservations') . '
                WHERE status = %s
                    AND payment_status = %s
                    AND created_at <= %s',
                'pending_payment',
                'pending',
                $cutoff
            )
        );

        return $this->normalizeIds(\is_array($rows) ? $rows : []);
    }

    private function getConfirmationRowsQuery(string $whereSql): string
    {
        return 'SELECT
                r.id,
                r.booking_id,
                r.room_id,
                r.room_type_id,
                r.assigned_room_id,
                r.rate_plan_id,
                r.guest_id,
                r.checkin,
                r.checkout,
                r.guests,
                r.status,
                r.total_price,
                r.payment_status,
                r.created_at,
                rm.name AS room_name,
                inv.room_number AS assigned_room_number,
                rp.name AS rate_plan_name,
                g.first_name,
                g.last_name,
                g.email,
                g.phone,
                g.country
            FROM ' . $this->table('reservations') . ' r
            LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id
            LEFT JOIN ' . $this->wpdb->prefix . 'mhb_rooms' . ' inv ON inv.id = r.assigned_room_id
            LEFT JOIN ' . $this->wpdb->prefix . 'mhb_rate_plans' . ' rp ON rp.id = r.rate_plan_id
            LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id
            WHERE ' . $whereSql . '
            ORDER BY r.id ASC';
    }
}
