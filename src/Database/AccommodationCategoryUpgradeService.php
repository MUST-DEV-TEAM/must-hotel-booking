<?php

namespace MustHotelBooking\Database;

final class AccommodationCategoryUpgradeService
{
    private const CLEANUP_SUMMARY_OPTION = 'must_hotel_booking_category_cleanup_summary';

    private RoomCategoryRepository $categoryRepository;
    private RoomRepository $roomRepository;
    private InventoryRepository $inventoryRepository;
    private PricingRuleRepository $pricingRuleRepository;
    private AvailabilityRepository $availabilityRepository;
    private RatePlanRepository $ratePlanRepository;
    private ReservationRepository $reservationRepository;

    /** @var \wpdb */
    private $wpdb;

    public function __construct($wpdb = null)
    {
        if ($wpdb !== null) {
            $this->wpdb = $wpdb;
        } else {
            global $wpdb;
            $this->wpdb = $wpdb;
        }

        $this->categoryRepository = new RoomCategoryRepository($this->wpdb);
        $this->roomRepository = new RoomRepository($this->wpdb);
        $this->inventoryRepository = new InventoryRepository($this->wpdb);
        $this->pricingRuleRepository = new PricingRuleRepository($this->wpdb);
        $this->availabilityRepository = new AvailabilityRepository($this->wpdb);
        $this->ratePlanRepository = new RatePlanRepository($this->wpdb);
        $this->reservationRepository = new ReservationRepository($this->wpdb);
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $this->ensureCategoriesExist();
        $summary = $this->cleanupLegacyMixedModelRooms();

        \update_option(self::CLEANUP_SUMMARY_OPTION, $summary, false);

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    public static function getLegacyDefaultCategories(): array
    {
        return [
            'standard-rooms' => 'Standard Rooms',
            'deluxe' => 'Deluxe',
            'suites' => 'Suites',
        ];
    }

    private function ensureCategoriesExist(): void
    {
        if (!$this->categoryRepository->tableExists()) {
            return;
        }

        $existingOptions = $this->categoryRepository->getCategoryOptions();
        $existingSlugs = \array_keys($existingOptions);
        $nextSortOrder = \count($existingSlugs) * 10;
        $roomCategorySlugs = $this->getDistinctRoomCategorySlugs();
        $seedMap = [];

        if (!empty($roomCategorySlugs)) {
            foreach ($roomCategorySlugs as $slug) {
                $seedMap[$slug] = $this->resolveCategoryLabel($slug);
            }
        }

        if (empty($seedMap)) {
            $seedMap = self::getLegacyDefaultCategories();
        }

        foreach ($seedMap as $slug => $name) {
            $normalizedSlug = \sanitize_key((string) $slug);

            if ($normalizedSlug === '' || \in_array($normalizedSlug, $existingSlugs, true)) {
                continue;
            }

            $this->categoryRepository->createCategory([
                'name' => \sanitize_text_field((string) $name),
                'slug' => $normalizedSlug,
                'description' => '',
                'sort_order' => $nextSortOrder,
                'created_at' => \current_time('mysql'),
            ]);

            $existingSlugs[] = $normalizedSlug;
            $nextSortOrder += 10;
        }
    }

    /**
     * @return array<int, string>
     */
    private function getDistinctRoomCategorySlugs(): array
    {
        if (!$this->roomRepository->roomsTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_col(
            'SELECT DISTINCT category
            FROM ' . $this->roomRepository->getRoomsTableName() . '
            WHERE category <> \'\'
            ORDER BY category ASC'
        );

        if (!\is_array($rows)) {
            return [];
        }

        $slugs = [];

        foreach ($rows as $value) {
            $slug = \sanitize_key((string) $value);

            if ($slug !== '') {
                $slugs[$slug] = $slug;
            }
        }

        return \array_values($slugs);
    }

    private function resolveCategoryLabel(string $slug): string
    {
        $slug = \sanitize_key($slug);
        $legacy = self::getLegacyDefaultCategories();

        if (isset($legacy[$slug])) {
            return (string) $legacy[$slug];
        }

        $label = \trim((string) \preg_replace('/\s+/', ' ', \str_replace(['-', '_'], ' ', $slug)));
        $label = \ucwords($label);

        return $label !== '' ? $label : 'Accommodation Category';
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupLegacyMixedModelRooms(): array
    {
        $summary = [
            'ran_at' => \current_time('mysql'),
            'deleted' => [],
            'skipped' => [],
        ];
        $categoryOptions = $this->categoryRepository->getCategoryOptions();

        if (empty($categoryOptions) || !$this->roomRepository->roomsTableExists()) {
            return $summary;
        }

        $rooms = $this->roomRepository->getAccommodationAdminRows();

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0 || !$this->isLegacyMixedModelCandidate($room, $categoryOptions)) {
                continue;
            }

            $deletionSummary = $this->deleteLegacyMixedModelRoom($room);

            if ($deletionSummary['deleted']) {
                $summary['deleted'][] = $deletionSummary;
            } else {
                $summary['skipped'][] = $deletionSummary;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, string> $categoryOptions
     */
    private function isLegacyMixedModelCandidate(array $room, array $categoryOptions): bool
    {
        $slug = \sanitize_key((string) ($room['slug'] ?? ''));
        $categorySlug = \sanitize_key((string) ($room['category'] ?? ''));
        $name = $this->normalizeName((string) ($room['name'] ?? ''));

        if ($slug === '' || !isset($categoryOptions[$slug])) {
            return false;
        }

        if ($categorySlug !== '' && $categorySlug !== $slug) {
            return false;
        }

        $expectedLabels = [
            $this->normalizeName((string) $categoryOptions[$slug]),
            $this->normalizeName($this->resolveCategoryLabel($slug)),
        ];

        foreach (self::getLegacyDefaultCategories() as $legacySlug => $legacyName) {
            if ($legacySlug === $slug) {
                $expectedLabels[] = $this->normalizeName($legacyName);
            }
        }

        return \in_array($name, \array_values(\array_unique($expectedLabels)), true);
    }

    private function normalizeName(string $value): string
    {
        $normalized = \function_exists('mb_strtolower')
            ? \mb_strtolower($value)
            : \strtolower($value);

        return \trim(\preg_replace('/\s+/', ' ', $normalized) ?? '');
    }

    /**
     * @param array<string, mixed> $room
     * @return array<string, mixed>
     */
    private function deleteLegacyMixedModelRoom(array $room): array
    {
        $roomId = isset($room['id']) ? (int) $room['id'] : 0;
        $roomName = (string) ($room['name'] ?? '');
        $roomSlug = \sanitize_key((string) ($room['slug'] ?? ''));
        $unitIds = $this->getInventoryUnitIdsForRoom($roomId);
        $reservationCount = $this->countReservationsReferencingRoom($roomId, $unitIds);

        if ($roomId <= 0) {
            return [
                'deleted' => false,
                'room_id' => 0,
                'name' => '',
                'slug' => '',
                'reason' => 'invalid_room_id',
            ];
        }

        if ($reservationCount > 0) {
            return [
                'deleted' => false,
                'room_id' => $roomId,
                'name' => $roomName,
                'slug' => $roomSlug,
                'reason' => 'reservation_history',
                'reservation_count' => $reservationCount,
            ];
        }

        $deletedCounts = [
            'rate_plan_assignments' => 0,
            'pricing_rules' => 0,
            'availability_rules' => 0,
            'room_meta_rows' => 0,
            'inventory_locks' => 0,
            'inventory_units' => 0,
            'derived_room_types' => 0,
        ];
        $startedTransaction = $this->roomRepository->beginTransaction();

        try {
            if ($this->ratePlanRepository->roomTypeRatePlansTableExists()) {
                $deleted = $this->wpdb->delete(
                    $this->wpdb->prefix . 'mhb_room_type_rate_plans',
                    ['room_type_id' => $roomId],
                    ['%d']
                );
                $deletedCounts['rate_plan_assignments'] = \is_int($deleted) ? $deleted : 0;
            }

            if ($this->pricingRuleRepository->pricingTableExists()) {
                $deleted = $this->wpdb->delete(
                    $this->wpdb->prefix . 'must_pricing',
                    ['room_id' => $roomId],
                    ['%d']
                );
                $deletedCounts['pricing_rules'] = \is_int($deleted) ? $deleted : 0;
            }

            if ($this->availabilityRepository->availabilityTableExists()) {
                $deleted = $this->wpdb->delete(
                    $this->wpdb->prefix . 'must_availability',
                    ['room_id' => $roomId],
                    ['%d']
                );
                $deletedCounts['availability_rules'] = \is_int($deleted) ? $deleted : 0;
            }

            if ($this->roomRepository->roomMetaTableExists()) {
                $deleted = $this->wpdb->delete(
                    $this->roomRepository->getRoomMetaTableName(),
                    ['room_id' => $roomId],
                    ['%d']
                );
                $deletedCounts['room_meta_rows'] = \is_int($deleted) ? $deleted : 0;
            }

            if (!empty($unitIds) && $this->inventoryRepository->inventoryLocksTableExists()) {
                $placeholders = \implode(', ', \array_fill(0, \count($unitIds), '%d'));
                $deleted = $this->wpdb->query(
                    $this->wpdb->prepare(
                        'DELETE FROM ' . $this->wpdb->prefix . 'mhb_inventory_locks
                        WHERE room_id IN (' . $placeholders . ')',
                        ...$unitIds
                    )
                );
                $deletedCounts['inventory_locks'] = \is_int($deleted) ? $deleted : 0;
            }

            if (!empty($unitIds) && $this->inventoryRepository->inventoryRoomsTableExists()) {
                $deleted = $this->wpdb->delete(
                    $this->wpdb->prefix . 'mhb_rooms',
                    ['room_type_id' => $roomId],
                    ['%d']
                );
                $deletedCounts['inventory_units'] = \is_int($deleted) ? $deleted : 0;
            }

            if ($this->inventoryRepository->roomTypesTableExists()) {
                $deleted = $this->wpdb->delete(
                    $this->wpdb->prefix . 'mhb_room_types',
                    ['id' => $roomId],
                    ['%d']
                );
                $deletedCounts['derived_room_types'] = \is_int($deleted) ? $deleted : 0;
            }

            $deletedRoom = $this->roomRepository->deleteRoom($roomId);

            if (!$deletedRoom) {
                throw new \RuntimeException('Unable to delete legacy mixed-model room.');
            }

            if ($startedTransaction) {
                $this->roomRepository->commit();
            }

            return [
                'deleted' => true,
                'room_id' => $roomId,
                'name' => $roomName,
                'slug' => $roomSlug,
                'deleted_counts' => $deletedCounts,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction) {
                $this->roomRepository->rollback();
            }

            return [
                'deleted' => false,
                'room_id' => $roomId,
                'name' => $roomName,
                'slug' => $roomSlug,
                'reason' => 'delete_failed',
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<int, int>
     */
    private function getInventoryUnitIdsForRoom(int $roomId): array
    {
        if ($roomId <= 0 || !$this->inventoryRepository->inventoryRoomsTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT id
                FROM ' . $this->wpdb->prefix . 'mhb_rooms' . '
                WHERE room_type_id = %d',
                $roomId
            )
        );

        if (!\is_array($rows)) {
            return [];
        }

        return \array_values(
            \array_filter(
                \array_map('intval', $rows),
                static function (int $unitId): bool {
                    return $unitId > 0;
                }
            )
        );
    }

    /**
     * @param array<int, int> $unitIds
     */
    private function countReservationsReferencingRoom(int $roomId, array $unitIds): int
    {
        if ($roomId <= 0 || !$this->reservationRepository->reservationsTableExists()) {
            return 0;
        }

        $sql = 'SELECT COUNT(*)
            FROM ' . $this->wpdb->prefix . 'must_reservations' . '
            WHERE room_id = %d OR room_type_id = %d';
        $params = [$roomId, $roomId];

        if (!empty($unitIds)) {
            $sql .= ' OR assigned_room_id IN (' . \implode(', ', \array_fill(0, \count($unitIds), '%d')) . ')';
            $params = \array_merge($params, $unitIds);
        }

        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare($sql, ...$params)
        );

        return $count;
    }
}
