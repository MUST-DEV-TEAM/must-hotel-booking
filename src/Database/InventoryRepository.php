<?php

namespace MustHotelBooking\Database;

final class InventoryRepository extends AbstractRepository
{
    private function mhbTable(string $suffix): string
    {
        return $this->wpdb->prefix . 'mhb_' . $suffix;
    }

    private function mhbTableExists(string $suffix): bool
    {
        $tableName = $this->mhbTable($suffix);
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $tableName
            )
        );

        return \is_string($result) && $result !== '';
    }

    private function roomTypesTable(): string
    {
        return $this->mhbTable('room_types');
    }

    private function roomsTable(): string
    {
        return $this->mhbTable('rooms');
    }

    private function inventoryLockTable(): string
    {
        return $this->mhbTable('inventory_locks');
    }

    public function roomTypesTableExists(): bool
    {
        return $this->mhbTableExists('room_types');
    }

    public function inventoryRoomsTableExists(): bool
    {
        return $this->mhbTableExists('rooms');
    }

    public function inventoryLocksTableExists(): bool
    {
        return $this->mhbTableExists('inventory_locks');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRoomTypeById(int $roomTypeId): ?array
    {
        if ($roomTypeId <= 0 || !$this->mhbTableExists('room_types')) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT id, name, description, capacity, base_price
                FROM ' . $this->roomTypesTable() . '
                WHERE id = %d
                LIMIT 1',
                $roomTypeId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRoomTypes(): array
    {
        if (!$this->mhbTableExists('room_types')) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            'SELECT id, name, description, capacity, base_price
            FROM ' . $this->roomTypesTable() . '
            ORDER BY name ASC, id ASC',
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    public function hasInventoryForRoomType(int $roomTypeId): bool
    {
        if (
            $roomTypeId <= 0 ||
            !$this->mhbTableExists('room_types') ||
            !$this->mhbTableExists('rooms')
        ) {
            return false;
        }

        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->roomsTable() . '
                WHERE room_type_id = %d',
                $roomTypeId
            )
        );

        return $count > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInventoryRoomById(int $roomId): ?array
    {
        if ($roomId <= 0 || !$this->mhbTableExists('rooms')) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT id, room_type_id, room_number, floor, status
                FROM ' . $this->roomsTable() . '
                WHERE id = %d
                LIMIT 1',
                $roomId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRoomsByType(int $roomTypeId): array
    {
        if ($roomTypeId <= 0 || !$this->mhbTableExists('rooms')) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, room_type_id, room_number, floor, status
                FROM ' . $this->roomsTable() . '
                WHERE room_type_id = %d
                ORDER BY floor ASC, room_number ASC, id ASC',
                $roomTypeId
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, string> $nonBlockingStatuses
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableRooms(
        int $roomTypeId,
        string $checkin,
        string $checkout,
        array $nonBlockingStatuses,
        string $now,
        string $excludeSessionId = ''
    ): array {
        if (
            $roomTypeId <= 0 ||
            $checkin === '' ||
            $checkout === '' ||
            !$this->mhbTableExists('rooms')
        ) {
            return [];
        }

        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $reservationsTable = $this->table('reservations');
        $locksTable = $this->inventoryLockTable();
        $sql = 'SELECT r.id, r.room_type_id, r.room_number, r.floor, r.status
            FROM ' . $this->roomsTable() . ' r
            WHERE r.room_type_id = %d
                AND r.status = %s
                AND NOT EXISTS (
                    SELECT 1
                    FROM ' . $reservationsTable . ' res
                    WHERE res.assigned_room_id = r.id
                        AND res.checkin < %s
                        AND res.checkout > %s
                        AND res.status NOT IN (%s, %s, %s)
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM ' . $locksTable . ' l
                    WHERE l.room_id = r.id
                        AND l.checkin < %s
                        AND l.checkout > %s
                        AND l.expires_at > %s';

        $params = [
            $roomTypeId,
            'available',
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2],
            $checkout,
            $checkin,
            $now,
        ];

        if ($excludeSessionId !== '') {
            $sql .= ' AND l.session_id <> %s';
            $params[] = $excludeSessionId;
        }

        $sql .= '
                )
            ORDER BY r.floor ASC, r.room_number ASC, r.id ASC';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, string> $nonBlockingStatuses
     */
    public function countUnassignedTypeReservationOverlaps(
        int $roomTypeId,
        string $checkin,
        string $checkout,
        array $nonBlockingStatuses
    ): int {
        if ($roomTypeId <= 0 || !$this->tableExists('reservations')) {
            return 0;
        }

        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE assigned_room_id = 0
                    AND checkin < %s
                    AND checkout > %s
                    AND status NOT IN (%s, %s, %s)
                    AND (room_type_id = %d OR (room_type_id = 0 AND room_id = %d))',
                $checkout,
                $checkin,
                $statuses[0],
                $statuses[1],
                $statuses[2],
                $roomTypeId,
                $roomTypeId
            )
        );

        return $count !== null ? (int) $count : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLockedRoomByTypeAndSession(
        int $roomTypeId,
        string $checkin,
        string $checkout,
        string $sessionId,
        string $now
    ): ?array {
        if (
            $roomTypeId <= 0 ||
            $checkin === '' ||
            $checkout === '' ||
            $sessionId === '' ||
            !$this->mhbTableExists('rooms')
        ) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT r.id, r.room_type_id, r.room_number, r.floor, r.status
                FROM ' . $this->roomsTable() . ' r
                INNER JOIN ' . $this->inventoryLockTable() . ' l
                    ON l.room_id = r.id
                WHERE r.room_type_id = %d
                    AND l.checkin = %s
                    AND l.checkout = %s
                    AND l.session_id = %s
                    AND l.expires_at > %s
                ORDER BY l.expires_at DESC, r.id ASC
                LIMIT 1',
                $roomTypeId,
                $checkin,
                $checkout,
                $sessionId,
                $now
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    public function assignRoomToReservation(int $roomId, int $reservationId, int $roomTypeId = 0): bool
    {
        if ($roomId <= 0 || $reservationId <= 0) {
            return false;
        }

        $data = [
            'assigned_room_id' => $roomId,
        ];
        $formats = ['%d'];

        if ($roomTypeId > 0) {
            $data['room_type_id'] = $roomTypeId;
            $formats[] = '%d';
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

    /**
     * @param array<int, string> $nonBlockingStatuses
     */
    public function releaseRoomAssignments(int $roomId, array $nonBlockingStatuses): bool
    {
        if ($roomId <= 0 || !$this->tableExists('reservations')) {
            return false;
        }

        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table('reservations') . '
                SET assigned_room_id = 0
                WHERE assigned_room_id = %d
                    AND status IN (%s, %s, %s)',
                $roomId,
                $statuses[0],
                $statuses[1],
                $statuses[2]
            )
        );

        return $updated !== false;
    }
}
