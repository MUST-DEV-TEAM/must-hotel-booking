<?php

namespace MustHotelBooking\Database;

final class HousekeepingRepository extends AbstractRepository
{
    public const STATUS_DIRTY = 'dirty';
    public const STATUS_CLEAN = 'clean';
    public const STATUS_INSPECTED = 'inspected';
    public const STATUS_OUT_OF_ORDER = 'out_of_order';

    private function housekeepingTable(): string
    {
        return $this->table('room_housekeeping_statuses');
    }

    public function housekeepingTableExists(): bool
    {
        return $this->tableExists('room_housekeeping_statuses');
    }

    /**
     * @return array<int, string>
     */
    public static function getAllowedStatuses(): array
    {
        return [
            self::STATUS_DIRTY,
            self::STATUS_CLEAN,
            self::STATUS_INSPECTED,
            self::STATUS_OUT_OF_ORDER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_DIRTY => \__('Dirty', 'must-hotel-booking'),
            self::STATUS_CLEAN => \__('Clean', 'must-hotel-booking'),
            self::STATUS_INSPECTED => \__('Inspected', 'must-hotel-booking'),
            self::STATUS_OUT_OF_ORDER => \__('Out of Order', 'must-hotel-booking'),
        ];
    }

    public static function normalizeStatus(string $status): string
    {
        $status = \sanitize_key($status);

        if (!\in_array($status, self::getAllowedStatuses(), true)) {
            return self::STATUS_DIRTY;
        }

        return $status;
    }

    /**
     * @param array<int, int> $inventoryRoomIds
     * @return array<int, array<string, mixed>>
     */
    public function getRoomStatusMap(array $inventoryRoomIds): array
    {
        $inventoryRoomIds = $this->normalizeIds($inventoryRoomIds);

        if (empty($inventoryRoomIds) || !$this->housekeepingTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT inventory_room_id, status, updated_by, updated_at
                FROM ' . $this->housekeepingTable() . '
                WHERE inventory_room_id IN (' . $this->buildIntegerPlaceholders($inventoryRoomIds) . ')
                ORDER BY inventory_room_id ASC',
                ...$inventoryRoomIds
            ),
            ARRAY_A
        );

        if (!\is_array($rows)) {
            return [];
        }

        $statusMap = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['inventory_room_id']) ? (int) $row['inventory_room_id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $statusMap[$roomId] = [
                'inventory_room_id' => $roomId,
                'status' => self::normalizeStatus((string) ($row['status'] ?? '')),
                'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : 0,
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
            ];
        }

        return $statusMap;
    }

    public function updateRoomStatus(int $inventoryRoomId, string $status, int $updatedBy = 0): bool
    {
        if ($inventoryRoomId <= 0 || !$this->housekeepingTableExists()) {
            return false;
        }

        $result = $this->wpdb->replace(
            $this->housekeepingTable(),
            [
                'inventory_room_id' => $inventoryRoomId,
                'status' => self::normalizeStatus($status),
                'updated_by' => \max(0, $updatedBy),
                'updated_at' => \current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s']
        );

        return $result !== false;
    }
}
