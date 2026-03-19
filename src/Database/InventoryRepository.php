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
                'SELECT
                    id,
                    room_type_id,
                    title,
                    room_number,
                    floor,
                    status,
                    is_active,
                    is_bookable,
                    is_calendar_visible,
                    sort_order,
                    capacity_override,
                    building,
                    section,
                    admin_notes
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
                'SELECT
                    id,
                    room_type_id,
                    title,
                    room_number,
                    floor,
                    status,
                    is_active,
                    is_bookable,
                    is_calendar_visible,
                    sort_order,
                    capacity_override,
                    building,
                    section,
                    admin_notes
                FROM ' . $this->roomsTable() . '
                WHERE room_type_id = %d
                ORDER BY sort_order ASC, floor ASC, room_number ASC, id ASC',
                $roomTypeId
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInventoryUnitAdminRows(): array
    {
        if (!$this->mhbTableExists('rooms')) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            'SELECT
                r.id,
                r.room_type_id,
                r.title,
                r.room_number,
                r.floor,
                r.status,
                r.is_active,
                r.is_bookable,
                r.is_calendar_visible,
                r.sort_order,
                r.capacity_override,
                r.building,
                r.section,
                r.admin_notes,
                rt.name AS room_type_name,
                rt.capacity AS room_type_capacity
            FROM ' . $this->roomsTable() . ' r
            LEFT JOIN ' . $this->roomTypesTable() . ' rt ON rt.id = r.room_type_id
            ORDER BY r.sort_order ASC, rt.name ASC, r.floor ASC, r.room_number ASC, r.id ASC',
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    public function roomNumberExists(string $roomNumber, int $excludeRoomId = 0): bool
    {
        $roomNumber = \sanitize_text_field($roomNumber);

        if ($roomNumber === '' || !$this->mhbTableExists('rooms')) {
            return false;
        }

        if ($excludeRoomId > 0) {
            $count = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT COUNT(*)
                    FROM ' . $this->roomsTable() . '
                    WHERE room_number = %s
                        AND id <> %d',
                    $roomNumber,
                    $excludeRoomId
                )
            );
        } else {
            $count = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT COUNT(*)
                    FROM ' . $this->roomsTable() . '
                    WHERE room_number = %s',
                    $roomNumber
                )
            );
        }

        return $count > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function syncRoomType(int $roomTypeId, array $data): bool
    {
        if ($roomTypeId <= 0 || !$this->mhbTableExists('room_types')) {
            return false;
        }

        $payload = [
            'name' => (string) ($data['name'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'capacity' => \max(1, (int) ($data['capacity'] ?? 1)),
            'base_price' => (float) ($data['base_price'] ?? 0.0),
        ];

        $exists = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT id
                FROM ' . $this->roomTypesTable() . '
                WHERE id = %d
                LIMIT 1',
                $roomTypeId
            )
        );

        if ($exists > 0) {
            $updated = $this->wpdb->update(
                $this->roomTypesTable(),
                $payload,
                ['id' => $roomTypeId],
                ['%s', '%s', '%d', '%f'],
                ['%d']
            );

            return $updated !== false;
        }

        $inserted = $this->wpdb->insert(
            $this->roomTypesTable(),
            ['id' => $roomTypeId] + $payload,
            ['%d', '%s', '%s', '%d', '%f']
        );

        return $inserted !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createInventoryRoom(array $data): int
    {
        if (!$this->mhbTableExists('rooms')) {
            return 0;
        }

        $inserted = $this->wpdb->insert(
            $this->roomsTable(),
            [
                'room_type_id' => (int) ($data['room_type_id'] ?? 0),
                'title' => (string) ($data['title'] ?? ''),
                'room_number' => (string) ($data['room_number'] ?? ''),
                'floor' => (int) ($data['floor'] ?? 0),
                'status' => (string) ($data['status'] ?? 'available'),
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'is_bookable' => !empty($data['is_bookable']) ? 1 : 0,
                'is_calendar_visible' => !empty($data['is_calendar_visible']) ? 1 : 0,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'capacity_override' => (int) ($data['capacity_override'] ?? 0),
                'building' => (string) ($data['building'] ?? ''),
                'section' => (string) ($data['section'] ?? ''),
                'admin_notes' => (string) ($data['admin_notes'] ?? ''),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateInventoryRoom(int $roomId, array $data): bool
    {
        if ($roomId <= 0 || !$this->mhbTableExists('rooms')) {
            return false;
        }

        $updated = $this->wpdb->update(
            $this->roomsTable(),
            [
                'room_type_id' => (int) ($data['room_type_id'] ?? 0),
                'title' => (string) ($data['title'] ?? ''),
                'room_number' => (string) ($data['room_number'] ?? ''),
                'floor' => (int) ($data['floor'] ?? 0),
                'status' => (string) ($data['status'] ?? 'available'),
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'is_bookable' => !empty($data['is_bookable']) ? 1 : 0,
                'is_calendar_visible' => !empty($data['is_calendar_visible']) ? 1 : 0,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'capacity_override' => (int) ($data['capacity_override'] ?? 0),
                'building' => (string) ($data['building'] ?? ''),
                'section' => (string) ($data['section'] ?? ''),
                'admin_notes' => (string) ($data['admin_notes'] ?? ''),
            ],
            ['id' => $roomId],
            ['%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    public function deleteInventoryRoom(int $roomId): bool
    {
        if ($roomId <= 0 || !$this->mhbTableExists('rooms')) {
            return false;
        }

        $deleted = $this->wpdb->delete(
            $this->roomsTable(),
            ['id' => $roomId],
            ['%d']
        );

        return $deleted !== false;
    }

    public function countInventoryRooms(): int
    {
        if (!$this->mhbTableExists('rooms')) {
            return 0;
        }

        return (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $this->roomsTable()
        );
    }

    public function countUnavailableInventoryRooms(): int
    {
        if (!$this->mhbTableExists('rooms')) {
            return 0;
        }

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->roomsTable() . '
                WHERE status <> %s',
                'available'
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUnavailableInventoryRooms(int $limit = 5): array
    {
        if (!$this->mhbTableExists('rooms')) {
            return [];
        }

        $limit = \max(1, \min(20, $limit));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, room_type_id, room_number, floor, status
                FROM ' . $this->roomsTable() . '
                WHERE status <> %s
                ORDER BY floor ASC, room_number ASC, id ASC
                LIMIT %d',
                'available',
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, int> $roomTypeIds
     * @return array<int, array<string, mixed>>
     */
    public function getRoomTypeInventorySummaries(array $roomTypeIds): array
    {
        $roomTypeIds = \array_values(
            \array_filter(
                \array_map('intval', $roomTypeIds),
                static function (int $roomTypeId): bool {
                    return $roomTypeId > 0;
                }
            )
        );

        if (empty($roomTypeIds) || !$this->mhbTableExists('rooms')) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    room_type_id,
                    COUNT(*) AS total_units,
                    SUM(CASE WHEN status <> %s THEN 1 ELSE 0 END) AS unavailable_units,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_units,
                    SUM(CASE WHEN is_bookable = 1 THEN 1 ELSE 0 END) AS bookable_units,
                    SUM(CASE WHEN is_calendar_visible = 1 THEN 1 ELSE 0 END) AS calendar_units
                FROM ' . $this->roomsTable() . '
                WHERE room_type_id IN (' . \implode(', ', \array_fill(0, \count($roomTypeIds), '%d')) . ')
                GROUP BY room_type_id
                ORDER BY room_type_id ASC',
                ...\array_merge(['available'], $roomTypeIds)
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, int> $roomTypeIds
     * @return array<int, array<string, mixed>>
     */
    public function getCalendarLockRows(array $roomTypeIds, string $startDate, string $endExclusive, string $now): array
    {
        $roomTypeIds = \array_values(
            \array_filter(
                \array_map('intval', $roomTypeIds),
                static function (int $roomTypeId): bool {
                    return $roomTypeId > 0;
                }
            )
        );

        if (
            empty($roomTypeIds) ||
            $startDate === '' ||
            $endExclusive === '' ||
            $now === '' ||
            !$this->mhbTableExists('rooms') ||
            !$this->mhbTableExists('inventory_locks')
        ) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    l.id,
                    l.room_id AS inventory_room_id,
                    r.room_type_id,
                    r.room_number,
                    l.checkin,
                    l.checkout,
                    l.session_id,
                    l.expires_at
                FROM ' . $this->inventoryLockTable() . ' l
                INNER JOIN ' . $this->roomsTable() . ' r ON r.id = l.room_id
                WHERE r.room_type_id IN (' . \implode(', ', \array_fill(0, \count($roomTypeIds), '%d')) . ')
                    AND l.checkin < %s
                    AND l.checkout > %s
                    AND l.expires_at > %s
                ORDER BY r.room_type_id ASC, l.checkin ASC, l.checkout ASC, l.id ASC',
                ...\array_merge($roomTypeIds, [$endExclusive, $startDate, $now])
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRoomTypesMissingInventory(int $limit = 5): array
    {
        if (
            !$this->mhbTableExists('room_types') ||
            !$this->mhbTableExists('rooms')
        ) {
            return [];
        }

        $limit = \max(1, \min(20, $limit));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    rt.id,
                    rt.name,
                    rt.capacity,
                    rt.base_price
                FROM ' . $this->roomTypesTable() . ' rt
                LEFT JOIN ' . $this->roomsTable() . ' r ON r.room_type_id = rt.id
                WHERE r.id IS NULL
                ORDER BY rt.name ASC, rt.id ASC
                LIMIT %d',
                $limit
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
        $sql = 'SELECT r.id, r.room_type_id, r.title, r.room_number, r.floor, r.status
            FROM ' . $this->roomsTable() . ' r
            WHERE r.room_type_id = %d
                AND r.status = %s
                AND r.is_active = 1
                AND r.is_bookable = 1
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
                'SELECT r.id, r.room_type_id, r.title, r.room_number, r.floor, r.status
                FROM ' . $this->roomsTable() . ' r
                INNER JOIN ' . $this->inventoryLockTable() . ' l
                    ON l.room_id = r.id
                WHERE r.room_type_id = %d
                    AND r.is_active = 1
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
