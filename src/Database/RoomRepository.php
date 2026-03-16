<?php

namespace MustHotelBooking\Database;

final class RoomRepository extends AbstractRepository
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
     * @return array<string, mixed>|null
     */
    public function getRoomById(int $roomId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT id, name, slug, category, description, max_guests, base_price, room_size, beds
                FROM ' . $this->table('rooms') . '
                WHERE id = %d
                LIMIT 1',
                $roomId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRoomBySlug(string $slug): ?array
    {
        $slug = \sanitize_title($slug);

        if ($slug === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT id, name, slug, category, description, max_guests, base_price, room_size, beds
                FROM ' . $this->table('rooms') . '
                WHERE slug = %s
                LIMIT 1',
                $slug
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRoomsByType(string $category, int $guests = 1): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, name, slug, category, description, max_guests, base_price, room_size, beds
                FROM ' . $this->table('rooms') . '
                WHERE category = %s
                    AND max_guests >= %d
                ORDER BY id ASC',
                \sanitize_key($category),
                \max(1, $guests)
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
        string $checkin,
        string $checkout,
        int $guests,
        string $category,
        array $nonBlockingStatuses,
        string $now,
        string $sessionId = ''
    ): array {
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $roomsTable = $this->table('rooms');
        $reservationsTable = $this->table('reservations');
        $locksTable = $this->lockTableName();

        if ($sessionId !== '') {
            $sql = $this->wpdb->prepare(
                "SELECT r.*
                FROM {$roomsTable} r
                WHERE r.category = %s
                    AND r.max_guests >= %d
                    AND NOT EXISTS (
                        SELECT 1
                        FROM {$reservationsTable} res
                        WHERE res.room_id = r.id
                            AND res.checkin < %s
                            AND res.checkout > %s
                            AND res.status NOT IN (%s, %s, %s)
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM {$locksTable} l
                        WHERE l.room_id = r.id
                            AND l.checkin < %s
                            AND l.checkout > %s
                            AND l.expires_at > %s
                            AND l.session_id <> %s
                    )
                ORDER BY r.id ASC",
                \sanitize_key($category),
                \max(1, $guests),
                $checkout,
                $checkin,
                $statuses[0],
                $statuses[1],
                $statuses[2],
                $checkout,
                $checkin,
                $now,
                $sessionId
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT r.*
                FROM {$roomsTable} r
                WHERE r.category = %s
                    AND r.max_guests >= %d
                    AND NOT EXISTS (
                        SELECT 1
                        FROM {$reservationsTable} res
                        WHERE res.room_id = r.id
                            AND res.checkin < %s
                            AND res.checkout > %s
                            AND res.status NOT IN (%s, %s, %s)
                    )
                    AND NOT EXISTS (
                        SELECT 1
                        FROM {$locksTable} l
                        WHERE l.room_id = r.id
                            AND l.checkin < %s
                            AND l.checkout > %s
                            AND l.expires_at > %s
                    )
                ORDER BY r.id ASC",
                \sanitize_key($category),
                \max(1, $guests),
                $checkout,
                $checkin,
                $statuses[0],
                $statuses[1],
                $statuses[2],
                $checkout,
                $checkin,
                $now
            );
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRandomRoomsByType(string $category, int $excludeRoomId = 0, int $limit = 3): array
    {
        if ($excludeRoomId <= 0 || $category === '') {
            return [];
        }

        $limit = \max(1, \min(6, $limit));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, name, slug, category, description, max_guests, room_size
                FROM ' . $this->table('rooms') . '
                WHERE category = %s
                    AND id <> %d
                ORDER BY RAND()
                LIMIT %d',
                \sanitize_key($category),
                $excludeRoomId,
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, int>
     */
    public function getRoomCategoryCapacityMap(): array
    {
        $rows = $this->wpdb->get_results(
            'SELECT category, MAX(max_guests) AS max_guests FROM ' . $this->table('rooms') . ' GROUP BY category',
            ARRAY_A
        );
        $capacityMap = [];

        if (!\is_array($rows)) {
            return $capacityMap;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $category = \sanitize_key((string) ($row['category'] ?? ''));
            $maxGuests = isset($row['max_guests']) ? \max(1, (int) $row['max_guests']) : 1;

            if ($category !== '') {
                $capacityMap[$category] = $maxGuests;
            }
        }

        return $capacityMap;
    }

    /**
     * @return array<int, int>
     */
    public function getRoomIdsByTypeAndGuests(string $category, int $guests): array
    {
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT id
                FROM ' . $this->table('rooms') . '
                WHERE category = %s
                    AND max_guests >= %d
                ORDER BY id ASC',
                \sanitize_key($category),
                \max(1, $guests)
            )
        );

        return $this->normalizeIds(\is_array($rows) ? $rows : []);
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<int, int>
     */
    public function getRoomCapacityMap(array $roomIds): array
    {
        $roomIds = $this->normalizeIds($roomIds);

        if (empty($roomIds)) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            'SELECT id, max_guests FROM ' . $this->table('rooms') . ' WHERE id IN (' . \implode(',', $roomIds) . ')',
            ARRAY_A
        );
        $capacityMap = [];

        if (!\is_array($rows)) {
            return $capacityMap;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $maxGuests = isset($row['max_guests']) ? \max(1, (int) $row['max_guests']) : 1;

            if ($roomId > 0) {
                $capacityMap[$roomId] = $maxGuests;
            }
        }

        return $capacityMap;
    }

    /**
     * @return array<string, int|float>|null
     */
    public function getRoomPricingContext(int $roomId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }

        $room = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT *
                FROM ' . $this->table('rooms') . '
                WHERE id = %d
                LIMIT 1',
                $roomId
            ),
            ARRAY_A
        );

        if (!\is_array($room)) {
            return null;
        }

        $metaRows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT meta_key, meta_value
                FROM ' . $this->table('room_meta') . '
                WHERE room_id = %d
                    AND meta_key IN (\'base_capacity\')',
                $roomId
            ),
            ARRAY_A
        );

        $baseCapacity = isset($room['max_guests']) ? (int) $room['max_guests'] : 1;

        if (\is_array($metaRows)) {
            foreach ($metaRows as $metaRow) {
                if (!\is_array($metaRow) || !isset($metaRow['meta_key'])) {
                    continue;
                }

                if ((string) $metaRow['meta_key'] === 'base_capacity') {
                    $parsedCapacity = (int) ($metaRow['meta_value'] ?? 0);

                    if ($parsedCapacity > 0) {
                        $baseCapacity = $parsedCapacity;
                    }
                }
            }
        }

        if ($baseCapacity <= 0) {
            $baseCapacity = 1;
        }

        return [
            'room_id' => (int) $room['id'],
            'room_base_price' => (float) $room['base_price'],
            'base_capacity' => $baseCapacity,
            'extra_guest_price' => 0.0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getApplicableSeasonalPricingRules(int $roomId, string $checkin, string $checkout, int $nights): array
    {
        if ($roomId <= 0 || $nights <= 0) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    id,
                    name,
                    room_id,
                    start_date,
                    end_date,
                    price_override,
                    weekend_price,
                    minimum_nights
                FROM ' . $this->table('pricing') . '
                WHERE start_date < %s
                    AND end_date >= %s
                    AND minimum_nights <= %d
                    AND (room_id = 0 OR room_id = %d)
                ORDER BY room_id DESC, minimum_nights DESC, start_date DESC, end_date ASC, id DESC',
                $checkout,
                $checkin,
                $nights,
                $roomId
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }
}
