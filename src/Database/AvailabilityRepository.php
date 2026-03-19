<?php

namespace MustHotelBooking\Database;

final class AvailabilityRepository extends AbstractRepository
{
    public function availabilityTableExists(): bool
    {
        return $this->tableExists('availability');
    }

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
                name,
                availability_date,
                end_date,
                is_active,
                rule_type,
                rule_value,
                reason
            FROM ' . $this->table('availability') . '
            WHERE room_id IN (0, %d)
                AND is_active = 1
                AND rule_type IN (\'minimum_stay\', \'maximum_stay\', \'closed_arrival\', \'closed_departure\', \'maintenance_block\')
                AND (
                    availability_date IS NULL
                    OR end_date IS NULL
                    OR availability_date <= end_date
                )
            ORDER BY room_id DESC, availability_date DESC, end_date ASC, updated_at DESC, id DESC',
            $roomId
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    public function getLatestRuleValue(string $ruleType): int
    {
        if ($ruleType === '' || !$this->availabilityTableExists()) {
            return 0;
        }

        $value = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT rule_value
                FROM ' . $this->table('availability') . '
                WHERE rule_type = %s
                    AND is_active = 1
                ORDER BY updated_at DESC, id DESC
                LIMIT 1',
                $ruleType
            )
        );

        return \max(0, (int) $value);
    }

    /**
     * @param array<int, string> $ruleTypes
     * @return array<int, array<string, mixed>>
     */
    public function getDateRestrictionRows(array $ruleTypes): array
    {
        $ruleTypes = \array_values(
            \array_filter(
                \array_map('sanitize_key', $ruleTypes),
                static function (string $ruleType): bool {
                    return $ruleType !== '';
                }
            )
        );

        if (empty($ruleTypes) || !$this->availabilityTableExists()) {
            return [];
        }

        $placeholders = \implode(', ', \array_fill(0, \count($ruleTypes), '%s'));
        $sql = $this->wpdb->prepare(
            'SELECT id, room_id, name, is_active, rule_type, availability_date, end_date, reason, rule_value, updated_at
            FROM ' . $this->table('availability') . '
            WHERE rule_type IN (' . $placeholders . ')
                AND is_active = 1
            ORDER BY availability_date DESC, end_date DESC, id DESC',
            ...$ruleTypes
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<int, array<string, mixed>>
     */
    public function getCalendarRestrictionRows(array $roomIds, string $startDate, string $endExclusive): array
    {
        $roomIds = \array_values(
            \array_filter(
                \array_map('intval', $roomIds),
                static function (int $roomId): bool {
                    return $roomId > 0;
                }
            )
        );

        if ($startDate === '' || $endExclusive === '' || !$this->availabilityTableExists()) {
            return [];
        }

        $endInclusive = (new \DateTimeImmutable($endExclusive))->modify('-1 day')->format('Y-m-d');
        $params = [
            'maintenance_block',
            'closed_arrival',
            'closed_departure',
            $endInclusive,
            '',
            $startDate,
        ];
        $roomFilterSql = 'room_id = 0';

        if (!empty($roomIds)) {
            $roomFilterSql .= ' OR room_id IN (' . \implode(', ', \array_fill(0, \count($roomIds), '%d')) . ')';
            $params = \array_merge($params, $roomIds);
        }

        $sql = $this->wpdb->prepare(
            'SELECT
                id,
                room_id,
                availability_date,
                end_date,
                is_available,
                reason,
                rule_type,
                rule_value,
                updated_at
            FROM ' . $this->table('availability') . '
            WHERE rule_type IN (%s, %s, %s)
                AND is_active = 1
                AND availability_date <= %s
                AND (end_date IS NULL OR end_date = %s OR end_date >= %s)
                AND (' . $roomFilterSql . ')
            ORDER BY room_id ASC, availability_date ASC, id ASC',
            ...$params
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    public function countAvailabilityRules(): int
    {
        if (!$this->availabilityTableExists()) {
            return 0;
        }

        return (int) $this->wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $this->table('availability') . ' WHERE is_active = 1'
        );
    }

    public function getGlobalAvailabilityRuleCount(): int
    {
        if (!$this->availabilityTableExists()) {
            return 0;
        }

        return (int) $this->wpdb->get_var(
            'SELECT COUNT(*)
            FROM ' . $this->table('availability') . '
            WHERE room_id = 0
                AND is_active = 1'
        );
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<int, array<string, int>>
     */
    public function getRoomAvailabilityRuleSummaryMap(array $roomIds): array
    {
        $roomIds = \array_values(
            \array_filter(
                \array_map('intval', $roomIds),
                static function (int $roomId): bool {
                    return $roomId > 0;
                }
            )
        );

        if (empty($roomIds) || !$this->availabilityTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    room_id,
                    COUNT(*) AS rule_count,
                    SUM(CASE WHEN rule_type = %s THEN 1 ELSE 0 END) AS maintenance_block_count
                FROM ' . $this->table('availability') . '
                WHERE room_id IN (' . \implode(', ', \array_fill(0, \count($roomIds), '%d')) . ')
                    AND is_active = 1
                GROUP BY room_id
                ORDER BY room_id ASC',
                ...\array_merge(['maintenance_block'], $roomIds)
            ),
            ARRAY_A
        );
        $summary = [];

        if (!\is_array($rows)) {
            return $summary;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['room_id']) ? (int) $row['room_id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $summary[$roomId] = [
                'rule_count' => isset($row['rule_count']) ? (int) $row['rule_count'] : 0,
                'maintenance_block_count' => isset($row['maintenance_block_count']) ? (int) $row['maintenance_block_count'] : 0,
            ];
        }

        return $summary;
    }

    public function countActiveMaintenanceBlocks(string $today): int
    {
        if ($today === '' || !$this->availabilityTableExists()) {
            return 0;
        }

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(DISTINCT room_id)
                FROM ' . $this->table('availability') . '
                WHERE room_id > 0
                    AND rule_type = %s
                    AND is_active = 1
                    AND availability_date <= %s
                    AND (end_date IS NULL OR end_date = %s OR end_date >= %s)',
                'maintenance_block',
                $today,
                '',
                $today
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveMaintenanceBlocks(string $today, int $limit = 5): array
    {
        if ($today === '' || !$this->availabilityTableExists()) {
            return [];
        }

        $limit = \max(1, \min(20, $limit));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    a.id,
                    a.room_id,
                    a.availability_date,
                    a.end_date,
                    a.reason,
                    rm.name AS room_name
                FROM ' . $this->table('availability') . ' a
                LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = a.room_id
                WHERE a.room_id > 0
                    AND a.rule_type = %s
                    AND a.is_active = 1
                    AND a.availability_date <= %s
                    AND (a.end_date IS NULL OR a.end_date = %s OR a.end_date >= %s)
                ORDER BY a.availability_date ASC, a.id DESC
                LIMIT %d',
                'maintenance_block',
                $today,
                '',
                $today,
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    public function saveGlobalStayRules(int $minimumStay, int $maximumStay, string $today, string $now): bool
    {
        if (!$this->availabilityTableExists()) {
            return false;
        }

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM ' . $this->table('availability') . ' WHERE rule_type IN (%s, %s)',
                'minimum_stay',
                'maximum_stay'
            )
        );

        if ($deleted === false) {
            return false;
        }

        if (
            $minimumStay > 0 &&
            !$this->insertAvailabilityRule(0, 'Minimum stay', $today, $today, true, true, 'Minimum stay', 'minimum_stay', (string) $minimumStay, $now)
        ) {
            return false;
        }

        if (
            $maximumStay > 0 &&
            !$this->insertAvailabilityRule(0, 'Maximum stay', $today, $today, true, true, 'Maximum stay', 'maximum_stay', (string) $maximumStay, $now)
        ) {
            return false;
        }

        return true;
    }

    public function createDateRestriction(string $ruleType, string $startDate, string $endDate, string $reason, string $updatedAt): int
    {
        if (
            $ruleType === '' ||
            $startDate === '' ||
            $endDate === '' ||
            !$this->availabilityTableExists()
        ) {
            return 0;
        }

        $inserted = $this->wpdb->insert(
            $this->table('availability'),
            [
                'room_id' => 0,
                'name' => $reason,
                'availability_date' => $startDate,
                'end_date' => $endDate,
                'is_active' => 1,
                'is_available' => 0,
                'reason' => $reason,
                'rule_type' => $ruleType,
                'rule_value' => '',
                'updated_at' => $updatedAt,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }

    public function deleteAvailabilityRule(int $ruleId): bool
    {
        if ($ruleId <= 0 || !$this->availabilityTableExists()) {
            return false;
        }

        $deleted = $this->wpdb->delete(
            $this->table('availability'),
            ['id' => $ruleId],
            ['%d']
        );

        return $deleted !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRuleById(int $ruleId): ?array
    {
        if ($ruleId <= 0 || !$this->availabilityTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT
                    a.id,
                    a.room_id,
                    a.name,
                    a.availability_date,
                    a.end_date,
                    a.is_active,
                    a.is_available,
                    a.reason,
                    a.rule_type,
                    a.rule_value,
                    a.updated_at,
                    COALESCE(r.name, \'\') AS room_name
                FROM ' . $this->table('availability') . ' a
                LEFT JOIN ' . $this->table('rooms') . ' r ON r.id = a.room_id
                WHERE a.id = %d
                LIMIT 1',
                $ruleId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAdminRules(array $filters = []): array
    {
        if (!$this->availabilityTableExists()) {
            return [];
        }

        $includeInactive = !empty($filters['include_inactive']);
        $roomId = isset($filters['room_id']) ? (int) $filters['room_id'] : -1;
        $timeline = isset($filters['timeline']) ? \sanitize_key((string) $filters['timeline']) : '';
        $ruleType = isset($filters['rule_type']) ? \sanitize_key((string) $filters['rule_type']) : '';
        $search = isset($filters['search']) ? \sanitize_text_field((string) $filters['search']) : '';
        $today = isset($filters['today']) ? \sanitize_text_field((string) $filters['today']) : \current_time('Y-m-d');
        $where = [];
        $params = [];

        if (!$includeInactive) {
            $where[] = 'a.is_active = 1';
        }

        if ($roomId >= 0) {
            if ($roomId === 0) {
                $where[] = 'a.room_id = 0';
            } else {
                $where[] = 'a.room_id = %d';
                $params[] = $roomId;
            }
        }

        if ($timeline === 'current') {
            $where[] = 'a.availability_date <= %s AND COALESCE(NULLIF(a.end_date, \'\'), a.availability_date) >= %s';
            $params[] = $today;
            $params[] = $today;
        } elseif ($timeline === 'future') {
            $where[] = 'COALESCE(NULLIF(a.end_date, \'\'), a.availability_date) >= %s';
            $params[] = $today;
        } elseif ($timeline === 'past') {
            $where[] = 'COALESCE(NULLIF(a.end_date, \'\'), a.availability_date) < %s';
            $params[] = $today;
        }

        if ($ruleType !== '' && $ruleType !== 'all') {
            $where[] = 'a.rule_type = %s';
            $params[] = $ruleType;
        }

        if ($search !== '') {
            $where[] = '(a.name LIKE %s OR a.reason LIKE %s OR COALESCE(r.name, \'\') LIKE %s)';
            $like = '%' . $this->wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT
                a.id,
                a.room_id,
                a.name,
                a.availability_date,
                a.end_date,
                a.is_active,
                a.is_available,
                a.reason,
                a.rule_type,
                a.rule_value,
                a.updated_at,
                COALESCE(r.name, \'\') AS room_name,
                COALESCE(r.is_active, 1) AS room_is_active,
                COALESCE(r.is_bookable, 1) AS room_is_bookable
            FROM ' . $this->table('availability') . ' a
            LEFT JOIN ' . $this->table('rooms') . ' r ON r.id = a.room_id';

        if (!empty($where)) {
            $sql .= ' WHERE ' . \implode(' AND ', $where);
        }

        $sql .= ' ORDER BY a.room_id ASC, a.availability_date DESC, a.end_date ASC, a.updated_at DESC, a.id DESC';
        $rows = empty($params)
            ? $this->wpdb->get_results($sql, ARRAY_A)
            : $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $ruleData
     */
    public function saveRule(array $ruleData): int
    {
        if (!$this->availabilityTableExists()) {
            return 0;
        }

        $ruleId = isset($ruleData['id']) ? (int) $ruleData['id'] : 0;
        $payload = [
            'room_id' => isset($ruleData['room_id']) ? (int) $ruleData['room_id'] : 0,
            'name' => isset($ruleData['name']) ? (string) $ruleData['name'] : '',
            'availability_date' => isset($ruleData['availability_date']) ? (string) $ruleData['availability_date'] : '',
            'end_date' => isset($ruleData['end_date']) ? (string) $ruleData['end_date'] : '',
            'is_active' => !empty($ruleData['is_active']) ? 1 : 0,
            'is_available' => !empty($ruleData['is_available']) ? 1 : 0,
            'reason' => isset($ruleData['reason']) ? (string) $ruleData['reason'] : '',
            'rule_type' => isset($ruleData['rule_type']) ? (string) $ruleData['rule_type'] : '',
            'rule_value' => isset($ruleData['rule_value']) ? (string) $ruleData['rule_value'] : '',
            'updated_at' => isset($ruleData['updated_at']) ? (string) $ruleData['updated_at'] : \current_time('mysql'),
        ];

        if ($ruleId > 0) {
            $updated = $this->wpdb->update(
                $this->table('availability'),
                $payload,
                ['id' => $ruleId],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            return $updated === false ? 0 : $ruleId;
        }

        $inserted = $this->wpdb->insert(
            $this->table('availability'),
            $payload,
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        return $inserted === false ? 0 : (int) $this->wpdb->insert_id;
    }

    public function toggleRuleStatus(int $ruleId, bool $isActive): bool
    {
        if ($ruleId <= 0 || !$this->availabilityTableExists()) {
            return false;
        }

        return $this->wpdb->update(
            $this->table('availability'),
            [
                'is_active' => $isActive ? 1 : 0,
                'updated_at' => \current_time('mysql'),
            ],
            ['id' => $ruleId],
            ['%d', '%s'],
            ['%d']
        ) !== false;
    }

    public function duplicateRule(int $ruleId): int
    {
        $rule = $this->getRuleById($ruleId);

        if (!\is_array($rule)) {
            return 0;
        }

        $rule['id'] = 0;
        $rule['name'] = \sprintf(__('%s Copy', 'must-hotel-booking'), (string) ($rule['name'] ?? __('Availability Rule', 'must-hotel-booking')));

        return $this->saveRule($rule);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getApplicableRules(int $roomId, string $checkin, string $checkout): array
    {
        if (
            $roomId <= 0 ||
            $checkin === '' ||
            $checkout === '' ||
            !$this->availabilityTableExists()
        ) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    id,
                    room_id,
                    name,
                    availability_date,
                    end_date,
                    is_active,
                    is_available,
                    reason,
                    rule_type,
                    rule_value,
                    updated_at
                FROM ' . $this->table('availability') . '
                WHERE is_active = 1
                    AND room_id IN (0, %d)
                    AND rule_type IN (\'minimum_stay\', \'maximum_stay\', \'closed_arrival\', \'closed_departure\', \'maintenance_block\')
                    AND availability_date < %s
                    AND COALESCE(NULLIF(end_date, \'\'), availability_date) >= %s
                ORDER BY room_id DESC, availability_date DESC, end_date ASC, updated_at DESC, id DESC',
                $roomId,
                $checkout,
                $checkin
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $ruleData
     * @return array<int, array<string, mixed>>
     */
    public function getOverlappingRules(array $ruleData, int $excludeRuleId = 0): array
    {
        if (!$this->availabilityTableExists()) {
            return [];
        }

        $roomId = isset($ruleData['room_id']) ? (int) $ruleData['room_id'] : 0;
        $ruleType = isset($ruleData['rule_type']) ? \sanitize_key((string) $ruleData['rule_type']) : '';
        $startDate = isset($ruleData['availability_date']) ? (string) $ruleData['availability_date'] : '';
        $endDate = isset($ruleData['end_date']) ? (string) $ruleData['end_date'] : '';
        $isActive = !empty($ruleData['is_active']);

        if ($startDate === '' || $endDate === '' || $ruleType === '' || !$isActive) {
            return [];
        }

        $sql = 'SELECT id, room_id, name, availability_date, end_date, rule_type, rule_value
            FROM ' . $this->table('availability') . '
            WHERE room_id = %d
                AND rule_type = %s
                AND is_active = 1
                AND availability_date <= %s
                AND COALESCE(NULLIF(end_date, \'\'), availability_date) >= %s';
        $params = [$roomId, $ruleType, $endDate, $startDate];

        if ($excludeRuleId > 0) {
            $sql .= ' AND id <> %d';
            $params[] = $excludeRuleId;
        }

        $sql .= ' ORDER BY availability_date DESC, end_date ASC, updated_at DESC, id DESC';
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOverlappingReservationRows(int $roomId, string $checkin, string $checkout, int $excludeReservationId = 0): array
    {
        if ($roomId <= 0 || $checkin === '' || $checkout === '' || !$this->tableExists('reservations')) {
            return [];
        }

        $excludedStatuses = ['cancelled', 'expired', 'payment_failed'];
        $sql = 'SELECT
                id,
                booking_id,
                room_id,
                room_type_id,
                checkin,
                checkout,
                status,
                payment_status,
                total_price,
                created_at
            FROM ' . $this->table('reservations') . '
            WHERE (room_id = %d OR room_type_id = %d)
                AND checkin < %s
                AND checkout > %s
                AND status NOT IN (%s, %s, %s)';
        $params = [$roomId, $roomId, $checkout, $checkin, $excludedStatuses[0], $excludedStatuses[1], $excludedStatuses[2]];

        if ($excludeReservationId > 0) {
            $sql .= ' AND id <> %d';
            $params[] = $excludeReservationId;
        }

        $sql .= ' ORDER BY checkin ASC, checkout ASC, id ASC';
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);

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
                'name' => $reason,
                'availability_date' => $availabilityDate !== '' ? $availabilityDate : null,
                'end_date' => $endDate !== '' ? $endDate : null,
                'is_active' => 1,
                'is_available' => $isAvailable ? 1 : 0,
                'reason' => $reason,
                'rule_type' => $ruleType,
                'rule_value' => $ruleValue,
                'updated_at' => \current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
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

    private function insertAvailabilityRule(
        int $roomId,
        string $name,
        string $availabilityDate,
        string $endDate,
        bool $isActive,
        bool $isAvailable,
        string $reason,
        string $ruleType,
        string $ruleValue,
        string $updatedAt
    ): bool {
        return $this->wpdb->insert(
            $this->table('availability'),
            [
                'room_id' => $roomId,
                'name' => $name,
                'availability_date' => $availabilityDate,
                'end_date' => $endDate,
                'is_active' => $isActive ? 1 : 0,
                'is_available' => $isAvailable ? 1 : 0,
                'reason' => $reason,
                'rule_type' => $ruleType,
                'rule_value' => $ruleValue,
                'updated_at' => $updatedAt,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']
        ) !== false;
    }
}
