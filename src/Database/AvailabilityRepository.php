<?php

namespace MustHotelBooking\Database;

final class AvailabilityRepository extends AbstractRepository
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAvailabilityRules(int $roomId): array
    {
        if ($roomId <= 0 || !$this->tableExists('availability')) {
            return [];
        }

        $sql = $this->wpdb->prepare(
            'SELECT
                id,
                room_id,
                availability_date,
                end_date,
                rule_type,
                rule_value
            FROM ' . $this->table('availability') . '
            WHERE room_id IN (0, %d)
                AND rule_type IN (\'minimum_stay\', \'maximum_stay\', \'closed_arrival\', \'closed_departure\', \'maintenance_block\')
            ORDER BY room_id DESC, updated_at DESC, id DESC',
            $roomId
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, string> $nonBlockingStatuses
     */
    public function hasReservationOverlap(int $roomId, string $checkin, string $checkout, array $nonBlockingStatuses = []): bool
    {
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $sql = $this->wpdb->prepare(
            'SELECT 1
            FROM ' . $this->table('reservations') . '
            WHERE room_id = %d
                AND checkin < %s
                AND checkout > %s
                AND status NOT IN (%s, %s, %s)
            LIMIT 1',
            $roomId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );

        return $this->wpdb->get_var($sql) !== null;
    }

    /**
     * @param array<int, string> $nonBlockingStatuses
     */
    public function checkRoomAvailability(
        int $roomId,
        string $checkin,
        string $checkout,
        array $nonBlockingStatuses = [],
        string $now = '',
        string $excludeSessionId = ''
    ): bool
    {
        if ($roomId <= 0 || $checkin >= $checkout) {
            return false;
        }

        if ($this->hasReservationOverlap($roomId, $checkin, $checkout, $nonBlockingStatuses)) {
            return false;
        }

        if ($now !== '' && $this->hasActiveRoomLockOverlap($roomId, $checkin, $checkout, $now, $excludeSessionId)) {
            return false;
        }

        return true;
    }

    public function updateInventory(
        int $roomId,
        string $availabilityDate,
        string $endDate = '',
        bool $isAvailable = true,
        string $reason = '',
        string $ruleType = '',
        string $ruleValue = ''
    ): bool {
        if ($roomId < 0 || !$this->tableExists('availability')) {
            return false;
        }

        $inserted = $this->wpdb->insert(
            $this->table('availability'),
            [
                'room_id' => $roomId,
                'availability_date' => $availabilityDate !== '' ? $availabilityDate : null,
                'end_date' => $endDate !== '' ? $endDate : null,
                'is_available' => $isAvailable ? 1 : 0,
                'reason' => $reason,
                'rule_type' => $ruleType,
                'rule_value' => $ruleValue,
                'updated_at' => \current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        return $inserted !== false;
    }

    public function cleanupExpiredLocks(string $now): int
    {
        $locksTable = $this->lockTableName();

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM ' . $locksTable . ' WHERE expires_at <= %s',
                $now
            )
        );

        return \is_int($deleted) ? $deleted : 0;
    }

    public function hasActiveRoomLockOverlap(int $roomId, string $checkin, string $checkout, string $now, string $excludeSessionId = ''): bool
    {
        $locksTable = $this->lockTableName();

        if ($excludeSessionId !== '') {
            $sql = $this->wpdb->prepare(
                'SELECT 1
                FROM ' . $locksTable . '
                WHERE room_id = %d
                    AND checkin < %s
                    AND checkout > %s
                    AND expires_at > %s
                    AND session_id <> %s
                LIMIT 1',
                $roomId,
                $checkout,
                $checkin,
                $now,
                $excludeSessionId
            );
        } else {
            $sql = $this->wpdb->prepare(
                'SELECT 1
                FROM ' . $locksTable . '
                WHERE room_id = %d
                    AND checkin < %s
                    AND checkout > %s
                    AND expires_at > %s
                LIMIT 1',
                $roomId,
                $checkout,
                $checkin,
                $now
            );
        }

        return $this->wpdb->get_var($sql) !== null;
    }

    public function hasActiveExactRoomLock(int $roomId, string $checkin, string $checkout, string $sessionId, string $now): bool
    {
        $locksTable = $this->lockTableName();
        $sql = $this->wpdb->prepare(
            'SELECT 1
            FROM ' . $locksTable . '
            WHERE room_id = %d
                AND checkin = %s
                AND checkout = %s
                AND session_id = %s
                AND expires_at > %s
            LIMIT 1',
            $roomId,
            $checkin,
            $checkout,
            $sessionId,
            $now
        );

        return $this->wpdb->get_var($sql) !== null;
    }

    public function upsertRoomLock(int $roomId, string $checkin, string $checkout, string $sessionId, string $expiresAt): bool
    {
        $locksTable = $this->lockTableName();
        $sql = $this->wpdb->prepare(
            'INSERT INTO ' . $locksTable . ' (room_id, checkin, checkout, session_id, expires_at)
            VALUES (%d, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at)',
            $roomId,
            $checkin,
            $checkout,
            $sessionId,
            $expiresAt
        );

        return $this->wpdb->query($sql) !== false;
    }

    public function deleteRoomLock(int $roomId, string $checkin, string $checkout, string $sessionId): bool
    {
        $locksTable = $this->lockTableName();
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM ' . $locksTable . '
                WHERE room_id = %d
                    AND checkin = %s
                    AND checkout = %s
                    AND session_id = %s',
                $roomId,
                $checkin,
                $checkout,
                $sessionId
            )
        );

        return \is_int($deleted) && $deleted > 0;
    }

    public function deleteRoomLocksBySession(int $roomId, string $sessionId): bool
    {
        if ($roomId <= 0 || $sessionId === '') {
            return false;
        }

        $locksTable = $this->lockTableName();
        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM ' . $locksTable . '
                WHERE room_id = %d
                    AND session_id = %s',
                $roomId,
                $sessionId
            )
        );

        return \is_int($deleted) && $deleted > 0;
    }

    /**
     * @param array<int, int> $roomIds
     * @param array<int, string> $nonBlockingStatuses
     * @return array<int, array<int, array<string, string>>>
     */
    public function getReservationRangesByRoomIds(array $roomIds, string $rangeStart, string $rangeEndExclusive, array $nonBlockingStatuses = []): array
    {
        $roomIds = $this->normalizeIds($roomIds);

        if (empty($roomIds)) {
            return [];
        }

        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $rangesByRoom = [];

        foreach ($roomIds as $roomId) {
            $rangesByRoom[$roomId] = [];
        }

        $sql = $this->wpdb->prepare(
            'SELECT room_id, checkin, checkout
            FROM ' . $this->table('reservations') . '
            WHERE room_id IN (' . \implode(',', $roomIds) . ')
                AND checkin < %s
                AND checkout > %s
                AND status NOT IN (%s, %s, %s)
            ORDER BY room_id ASC, checkin ASC, checkout ASC',
            $rangeEndExclusive,
            $rangeStart,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!\is_array($rows)) {
            return $rangesByRoom;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['room_id']) ? (int) $row['room_id'] : 0;
            $checkin = isset($row['checkin']) ? (string) $row['checkin'] : '';
            $checkout = isset($row['checkout']) ? (string) $row['checkout'] : '';

            if ($roomId > 0 && isset($rangesByRoom[$roomId]) && $checkin !== '' && $checkout !== '') {
                $rangesByRoom[$roomId][] = [
                    'checkin' => $checkin,
                    'checkout' => $checkout,
                ];
            }
        }

        return $rangesByRoom;
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<int, array<int, array<string, string>>>
     */
    public function getActiveLockRangesByRoomIds(array $roomIds, string $rangeStart, string $rangeEndExclusive, string $now, string $excludeSessionId = ''): array
    {
        $roomIds = $this->normalizeIds($roomIds);

        if (empty($roomIds)) {
            return [];
        }

        $rangesByRoom = [];

        foreach ($roomIds as $roomId) {
            $rangesByRoom[$roomId] = [];
        }

        $locksTable = $this->lockTableName();

        if ($excludeSessionId !== '') {
            $sql = $this->wpdb->prepare(
                'SELECT room_id, checkin, checkout
                FROM ' . $locksTable . '
                WHERE room_id IN (' . \implode(',', $roomIds) . ')
                    AND checkin < %s
                    AND checkout > %s
                    AND expires_at > %s
                    AND session_id <> %s
                ORDER BY room_id ASC, checkin ASC, checkout ASC',
                $rangeEndExclusive,
                $rangeStart,
                $now,
                $excludeSessionId
            );
        } else {
            $sql = $this->wpdb->prepare(
                'SELECT room_id, checkin, checkout
                FROM ' . $locksTable . '
                WHERE room_id IN (' . \implode(',', $roomIds) . ')
                    AND checkin < %s
                    AND checkout > %s
                    AND expires_at > %s
                ORDER BY room_id ASC, checkin ASC, checkout ASC',
                $rangeEndExclusive,
                $rangeStart,
                $now
            );
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!\is_array($rows)) {
            return $rangesByRoom;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['room_id']) ? (int) $row['room_id'] : 0;
            $checkin = isset($row['checkin']) ? (string) $row['checkin'] : '';
            $checkout = isset($row['checkout']) ? (string) $row['checkout'] : '';

            if ($roomId > 0 && isset($rangesByRoom[$roomId]) && $checkin !== '' && $checkout !== '') {
                $rangesByRoom[$roomId][] = [
                    'checkin' => $checkin,
                    'checkout' => $checkout,
                ];
            }
        }

        return $rangesByRoom;
    }
}
