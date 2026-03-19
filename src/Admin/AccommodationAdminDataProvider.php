<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;

final class AccommodationAdminDataProvider
{
    /** @var \MustHotelBooking\Database\RoomRepository */
    private $roomRepository;

    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventoryRepository;

    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservationRepository;

    /** @var \MustHotelBooking\Database\RatePlanRepository */
    private $ratePlanRepository;

    /** @var \MustHotelBooking\Database\AvailabilityRepository */
    private $availabilityRepository;

    public function __construct()
    {
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->ratePlanRepository = \MustHotelBooking\Engine\get_rate_plan_repository();
        $this->availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function getPageData(AccommodationAdminQuery $query, array $state = []): array
    {
        $today = \current_time('Y-m-d');
        $rawTypes = $this->roomRepository->getAccommodationAdminRows();
        $this->synchronizeInventoryRoomTypes($rawTypes);
        $types = $this->buildAccommodationTypeRows($rawTypes, $today);
        $typeIndex = $this->indexRowsById($types);
        $rawUnits = $this->inventoryRepository->getInventoryUnitAdminRows();
        $units = $this->buildUnitRows($rawUnits, $typeIndex, $today);
        $filteredTypes = $this->filterAccommodationTypes($types, $query);
        $filteredUnits = $this->filterUnits($units, $query);
        $paginatedTypes = $this->paginateRows($filteredTypes, $query->getPaged(), $query->getPerPage());
        $paginatedUnits = $this->paginateRows($filteredUnits, $query->getPaged(), $query->getPerPage());
        $typeErrors = isset($state['type_errors']) && \is_array($state['type_errors']) ? $state['type_errors'] : [];
        $unitErrors = isset($state['unit_errors']) && \is_array($state['unit_errors']) ? $state['unit_errors'] : [];
        $typeForm = $this->getTypeFormData($query, isset($state['type_form']) && \is_array($state['type_form']) ? $state['type_form'] : null);
        $unitForm = $this->getUnitFormData($query, isset($state['unit_form']) && \is_array($state['unit_form']) ? $state['unit_form'] : null);

        return [
            'tab' => $query->getTab(),
            'notice' => $query->getNotice(),
            'summary_cards' => $this->buildSummaryCards($types, $units),
            'type_rows' => $paginatedTypes['rows'],
            'unit_rows' => $paginatedUnits['rows'],
            'type_pagination' => $paginatedTypes['pagination'],
            'unit_pagination' => $paginatedUnits['pagination'],
            'type_form' => $typeForm,
            'unit_form' => $unitForm,
            'type_errors' => $typeErrors,
            'unit_errors' => $unitErrors,
            'category_options' => RoomCatalog::getCategories(),
            'type_options' => $this->buildTypeOptions($types),
            'unit_status_options' => $this->getUnitStatusOptions(),
            'today' => $today,
            'type_filter_count' => \count($filteredTypes),
            'unit_filter_count' => \count($filteredUnits),
            'active_type_filters' => $this->buildTypeFilterChips($query, $paginatedTypes['total_items']),
            'active_unit_filters' => $this->buildUnitFilterChips($query, $paginatedUnits['total_items'], $types),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $typeRows
     */
    private function synchronizeInventoryRoomTypes(array $typeRows): void
    {
        static $didSync = false;

        if ($didSync) {
            return;
        }

        $didSync = true;

        foreach ($typeRows as $typeRow) {
            if (!\is_array($typeRow)) {
                continue;
            }

            $typeId = isset($typeRow['id']) ? (int) $typeRow['id'] : 0;

            if ($typeId <= 0) {
                continue;
            }

            $this->inventoryRepository->syncRoomType(
                $typeId,
                [
                    'name' => (string) ($typeRow['name'] ?? ''),
                    'description' => (string) ($typeRow['description'] ?? ''),
                    'capacity' => (int) ($typeRow['max_guests'] ?? 1),
                    'base_price' => (float) ($typeRow['base_price'] ?? 0.0),
                ]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     * @return array<int, array<string, mixed>>
     */
    private function buildAccommodationTypeRows(array $rawRows, string $today): array
    {
        $typeIds = [];

        foreach ($rawRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $typeId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($typeId > 0) {
                $typeIds[] = $typeId;
            }
        }

        $reservationSummary = $this->reservationRepository->getAccommodationReservationSummaryMap($typeIds, $today);
        $ratePlanSummary = $this->ratePlanRepository->getRoomTypeRatePlanSummaryMap($typeIds);
        $inventorySummary = $this->inventoryRepository->getRoomTypeInventorySummaries($typeIds);
        $availabilitySummary = $this->availabilityRepository->getRoomAvailabilityRuleSummaryMap($typeIds);
        $globalAvailabilityCount = $this->availabilityRepository->getGlobalAvailabilityRuleCount();
        $rows = [];

        foreach ($rawRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $typeId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($typeId <= 0) {
                continue;
            }

            $reservationData = isset($reservationSummary[$typeId]) && \is_array($reservationSummary[$typeId]) ? $reservationSummary[$typeId] : [];
            $ratePlanData = isset($ratePlanSummary[$typeId]) && \is_array($ratePlanSummary[$typeId]) ? $ratePlanSummary[$typeId] : [];
            $inventoryData = $this->findRoomTypeInventorySummary($inventorySummary, $typeId);
            $availabilityData = isset($availabilitySummary[$typeId]) && \is_array($availabilitySummary[$typeId]) ? $availabilitySummary[$typeId] : [];
            $pricingConfigured = (float) ($row['base_price'] ?? 0.0) > 0 || (int) ($ratePlanData['active_assignment_count'] ?? 0) > 0;
            $availabilityConfigured = (int) ($inventoryData['total_units'] ?? 0) > 0 || (int) ($availabilityData['rule_count'] ?? 0) > 0 || $globalAvailabilityCount > 0;
            $capacityWarnings = $this->validateTypeCapacityValues($row);
            $futureReservations = (int) ($reservationData['future_reservations'] ?? 0);
            $isActive = !empty($row['is_active']);
            $isBookable = !empty($row['is_bookable']);
            $warnings = [];

            if (!$pricingConfigured) {
                $warnings[] = \__('Missing base price or active rate plan assignment.', 'must-hotel-booking');
            }

            if (!$availabilityConfigured) {
                $warnings[] = \__('No unit inventory or accommodation-specific availability setup found yet.', 'must-hotel-booking');
            }

            foreach ($capacityWarnings as $warning) {
                $warnings[] = $warning;
            }

            if (!$isActive && $futureReservations > 0) {
                $warnings[] = \__('Inactive while future reservations still reference this accommodation.', 'must-hotel-booking');
            }

            if (!$isBookable && $futureReservations > 0) {
                $warnings[] = \__('Marked not bookable while future reservations still exist.', 'must-hotel-booking');
            }

            $rows[] = [
                'id' => $typeId,
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'category_label' => RoomCatalog::getCategoryLabel((string) ($row['category'] ?? 'standard-rooms')),
                'description' => (string) ($row['description'] ?? ''),
                'internal_code' => (string) ($row['internal_code'] ?? ''),
                'is_active' => $isActive,
                'is_bookable' => $isBookable,
                'is_online_bookable' => !empty($row['is_online_bookable']),
                'is_calendar_visible' => !empty($row['is_calendar_visible']),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'max_adults' => (int) ($row['max_adults'] ?? 0),
                'max_children' => (int) ($row['max_children'] ?? 0),
                'max_guests' => (int) ($row['max_guests'] ?? 0),
                'default_occupancy' => (int) ($row['default_occupancy'] ?? 0),
                'base_price' => (float) ($row['base_price'] ?? 0.0),
                'room_size' => (string) ($row['room_size'] ?? ''),
                'beds' => (string) ($row['beds'] ?? ''),
                'admin_notes' => (string) ($row['admin_notes'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'pricing_configured' => $pricingConfigured,
                'availability_configured' => $availabilityConfigured,
                'future_reservations' => $futureReservations,
                'current_reservations' => (int) ($reservationData['current_reservations'] ?? 0),
                'reservation_count' => (int) ($reservationData['reservation_count'] ?? 0),
                'next_checkin' => (string) ($reservationData['next_checkin'] ?? ''),
                'rate_plan_count' => (int) ($ratePlanData['assignment_count'] ?? 0),
                'active_rate_plan_count' => (int) ($ratePlanData['active_assignment_count'] ?? 0),
                'inventory_units' => (int) ($inventoryData['total_units'] ?? 0),
                'active_inventory_units' => (int) ($inventoryData['active_units'] ?? 0),
                'bookable_inventory_units' => (int) ($inventoryData['bookable_units'] ?? 0),
                'availability_rule_count' => (int) ($availabilityData['rule_count'] ?? 0),
                'maintenance_block_count' => (int) ($availabilityData['maintenance_block_count'] ?? 0),
                'warnings' => $warnings,
                'capacity_summary' => $this->formatCapacitySummary($row),
                'edit_url' => get_admin_rooms_page_url(['tab' => 'types', 'action' => 'edit_type', 'type_id' => $typeId]),
                'duplicate_url' => \wp_nonce_url(
                    get_admin_rooms_page_url(['tab' => 'types', 'action' => 'duplicate_type', 'type_id' => $typeId]),
                    'must_accommodation_duplicate_type_' . $typeId
                ),
                'toggle_url' => \wp_nonce_url(
                    get_admin_rooms_page_url([
                        'tab' => 'types',
                        'action' => 'toggle_type_status',
                        'type_id' => $typeId,
                        'target' => $isActive ? 'inactive' : 'active',
                    ]),
                    'must_accommodation_toggle_type_' . $typeId
                ),
                'calendar_url' => \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
                    ? get_admin_calendar_page_url(['room_id' => $typeId, 'focus_room_id' => $typeId, 'start_date' => $today, 'weeks' => 2])
                    : '',
                'reservations_url' => \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url')
                    ? get_admin_reservations_page_url(['room_id' => $typeId])
                    : '',
                'pricing_url' => \function_exists(__NAMESPACE__ . '\get_admin_pricing_page_url')
                    ? get_admin_pricing_page_url(['room_id' => $typeId])
                    : '',
                'rate_plans_url' => \function_exists(__NAMESPACE__ . '\get_admin_rate_plans_page_url')
                    ? get_admin_rate_plans_page_url(['room_type_id' => $typeId])
                    : '',
                'availability_url' => \function_exists(__NAMESPACE__ . '\get_admin_availability_rules_page_url')
                    ? get_admin_availability_rules_page_url(['room_id' => $typeId])
                    : '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rawRows
     * @param array<int, array<string, mixed>> $typeIndex
     * @return array<int, array<string, mixed>>
     */
    private function buildUnitRows(array $rawRows, array $typeIndex, string $today): array
    {
        $unitIds = [];

        foreach ($rawRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $unitId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($unitId > 0) {
                $unitIds[] = $unitId;
            }
        }

        $reservationSummary = $this->reservationRepository->getInventoryRoomReservationSummaryMap($unitIds, $today);
        $rows = [];

        foreach ($rawRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $unitId = isset($row['id']) ? (int) $row['id'] : 0;
            $typeId = isset($row['room_type_id']) ? (int) $row['room_type_id'] : 0;

            if ($unitId <= 0 || $typeId <= 0) {
                continue;
            }

            $typeRow = isset($typeIndex[$typeId]) && \is_array($typeIndex[$typeId]) ? $typeIndex[$typeId] : [];
            $reservationData = isset($reservationSummary[$unitId]) && \is_array($reservationSummary[$unitId]) ? $reservationSummary[$unitId] : [];
            $futureReservations = (int) ($reservationData['future_reservations'] ?? 0);
            $isActive = !empty($row['is_active']);
            $isBookable = !empty($row['is_bookable']);
            $warnings = [];

            if (!$isActive && $futureReservations > 0) {
                $warnings[] = \__('Inactive while future reservations are already assigned to this unit.', 'must-hotel-booking');
            }

            if (!$isBookable && $futureReservations > 0) {
                $warnings[] = \__('Marked not bookable while future reservations remain assigned.', 'must-hotel-booking');
            }

            if ((string) ($row['status'] ?? '') !== 'available' && $futureReservations > 0) {
                $warnings[] = \__('Operational status is not available while future reservations remain assigned.', 'must-hotel-booking');
            }

            $deleteUrl = '';

            if ((int) ($reservationData['reservation_count'] ?? 0) === 0) {
                $deleteUrl = \wp_nonce_url(
                    get_admin_rooms_page_url(['tab' => 'units', 'action' => 'delete_unit', 'unit_id' => $unitId]),
                    'must_accommodation_delete_unit_' . $unitId
                );
            }

            $rows[] = [
                'id' => $unitId,
                'room_type_id' => $typeId,
                'type_name' => (string) ($typeRow['name'] ?? ($row['room_type_name'] ?? '')),
                'title' => (string) ($row['title'] ?? ''),
                'room_number' => (string) ($row['room_number'] ?? ''),
                'floor' => (int) ($row['floor'] ?? 0),
                'status' => (string) ($row['status'] ?? 'available'),
                'status_label' => $this->getUnitStatusLabel((string) ($row['status'] ?? 'available')),
                'is_active' => $isActive,
                'is_bookable' => $isBookable,
                'is_calendar_visible' => !empty($row['is_calendar_visible']),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'capacity_override' => (int) ($row['capacity_override'] ?? 0),
                'building' => (string) ($row['building'] ?? ''),
                'section' => (string) ($row['section'] ?? ''),
                'admin_notes' => (string) ($row['admin_notes'] ?? ''),
                'pricing_configured' => !empty($typeRow['pricing_configured']),
                'availability_configured' => !empty($typeRow['availability_configured']),
                'capacity_summary' => (int) ($row['capacity_override'] ?? 0) > 0
                    ? \sprintf(__('Override %d guests', 'must-hotel-booking'), (int) $row['capacity_override'])
                    : \sprintf(__('Inherits %d guests', 'must-hotel-booking'), (int) ($typeRow['max_guests'] ?? ($row['room_type_capacity'] ?? 0))),
                'future_reservations' => $futureReservations,
                'current_reservations' => (int) ($reservationData['current_reservations'] ?? 0),
                'reservation_count' => (int) ($reservationData['reservation_count'] ?? 0),
                'next_checkin' => (string) ($reservationData['next_checkin'] ?? ''),
                'warnings' => $warnings,
                'edit_url' => get_admin_rooms_page_url(['tab' => 'units', 'action' => 'edit_unit', 'unit_id' => $unitId]),
                'toggle_url' => \wp_nonce_url(
                    get_admin_rooms_page_url([
                        'tab' => 'units',
                        'action' => 'toggle_unit_status',
                        'unit_id' => $unitId,
                        'target' => $isActive ? 'inactive' : 'active',
                    ]),
                    'must_accommodation_toggle_unit_' . $unitId
                ),
                'delete_url' => $deleteUrl,
                'type_edit_url' => get_admin_rooms_page_url(['tab' => 'types', 'action' => 'edit_type', 'type_id' => $typeId]),
                'calendar_url' => \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
                    ? get_admin_calendar_page_url(['room_id' => $typeId, 'focus_room_id' => $typeId, 'start_date' => $today, 'weeks' => 2])
                    : '',
                'reservations_url' => \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url')
                    ? get_admin_reservations_page_url(['room_id' => $typeId])
                    : '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterAccommodationTypes(array $rows, AccommodationAdminQuery $query): array
    {
        $search = \function_exists('mb_strtolower') ? \mb_strtolower($query->getSearch()) : \strtolower($query->getSearch());
        $category = $query->getCategory();
        $status = $query->getStatus();
        $bookable = $query->getBookable();
        $setup = $query->getSetup();
        $future = $query->getFuture();

        return \array_values(
            \array_filter(
                $rows,
                static function (array $row) use ($search, $category, $status, $bookable, $setup, $future): bool {
                    if ($search !== '') {
                        $haystack = \function_exists('mb_strtolower')
                            ? \mb_strtolower(\implode(' ', [(string) ($row['name'] ?? ''), (string) ($row['slug'] ?? ''), (string) ($row['internal_code'] ?? ''), (string) ($row['description'] ?? ''), (string) ($row['category_label'] ?? '')]))
                            : \strtolower(\implode(' ', [(string) ($row['name'] ?? ''), (string) ($row['slug'] ?? ''), (string) ($row['internal_code'] ?? ''), (string) ($row['description'] ?? ''), (string) ($row['category_label'] ?? '')]));

                        if (\strpos($haystack, $search) === false) {
                            return false;
                        }
                    }

                    if ($category !== '' && (string) ($row['category'] ?? '') !== $category) {
                        return false;
                    }

                    if ($status === 'active' && empty($row['is_active'])) {
                        return false;
                    }

                    if ($status === 'inactive' && !empty($row['is_active'])) {
                        return false;
                    }

                    if ($bookable === 'yes' && empty($row['is_bookable'])) {
                        return false;
                    }

                    if ($bookable === 'no' && !empty($row['is_bookable'])) {
                        return false;
                    }

                    if ($setup === 'missing_pricing' && !empty($row['pricing_configured'])) {
                        return false;
                    }

                    if ($setup === 'missing_availability' && !empty($row['availability_configured'])) {
                        return false;
                    }

                    if ($setup === 'incomplete_capacity' && empty($row['warnings'])) {
                        return false;
                    }

                    if ($setup === 'attention' && empty($row['warnings'])) {
                        return false;
                    }

                    if ($future === 'yes' && (int) ($row['future_reservations'] ?? 0) <= 0) {
                        return false;
                    }

                    if ($future === 'no' && (int) ($row['future_reservations'] ?? 0) > 0) {
                        return false;
                    }

                    return true;
                }
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterUnits(array $rows, AccommodationAdminQuery $query): array
    {
        $search = \function_exists('mb_strtolower') ? \mb_strtolower($query->getSearch()) : \strtolower($query->getSearch());
        $status = $query->getStatus();
        $bookable = $query->getBookable();
        $future = $query->getFuture();
        $unitStatus = $query->getUnitStatus();
        $roomTypeId = $query->getUnitTypeId();

        return \array_values(
            \array_filter(
                $rows,
                static function (array $row) use ($search, $status, $bookable, $future, $unitStatus, $roomTypeId): bool {
                    if ($search !== '') {
                        $haystack = \function_exists('mb_strtolower')
                            ? \mb_strtolower(\implode(' ', [(string) ($row['title'] ?? ''), (string) ($row['room_number'] ?? ''), (string) ($row['type_name'] ?? ''), (string) ($row['building'] ?? ''), (string) ($row['section'] ?? '')]))
                            : \strtolower(\implode(' ', [(string) ($row['title'] ?? ''), (string) ($row['room_number'] ?? ''), (string) ($row['type_name'] ?? ''), (string) ($row['building'] ?? ''), (string) ($row['section'] ?? '')]));

                        if (\strpos($haystack, $search) === false) {
                            return false;
                        }
                    }

                    if ($roomTypeId > 0 && (int) ($row['room_type_id'] ?? 0) !== $roomTypeId) {
                        return false;
                    }

                    if ($status === 'active' && empty($row['is_active'])) {
                        return false;
                    }

                    if ($status === 'inactive' && !empty($row['is_active'])) {
                        return false;
                    }

                    if ($bookable === 'yes' && empty($row['is_bookable'])) {
                        return false;
                    }

                    if ($bookable === 'no' && !empty($row['is_bookable'])) {
                        return false;
                    }

                    if ($future === 'yes' && (int) ($row['future_reservations'] ?? 0) <= 0) {
                        return false;
                    }

                    if ($future === 'no' && (int) ($row['future_reservations'] ?? 0) > 0) {
                        return false;
                    }

                    if ($unitStatus !== '' && (string) ($row['status'] ?? '') !== $unitStatus) {
                        return false;
                    }

                    return true;
                }
            )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function paginateRows(array $rows, int $paged, int $perPage): array
    {
        $totalItems = \count($rows);
        $totalPages = \max(1, (int) \ceil($totalItems / $perPage));
        $paged = \min(\max(1, $paged), $totalPages);
        $offset = ($paged - 1) * $perPage;

        return [
            'rows' => \array_slice($rows, $offset, $perPage),
            'total_items' => $totalItems,
            'pagination' => [
                'current_page' => $paged,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $submittedForm
     * @return array<string, mixed>
     */
    private function getTypeFormData(AccommodationAdminQuery $query, ?array $submittedForm): array
    {
        $defaults = [
            'type_id' => 0,
            'name' => '',
            'slug' => '',
            'category' => 'standard-rooms',
            'description' => '',
            'internal_code' => '',
            'is_active' => 1,
            'is_bookable' => 1,
            'is_online_bookable' => 1,
            'is_calendar_visible' => 1,
            'sort_order' => 0,
            'max_adults' => 1,
            'max_children' => 0,
            'max_guests' => 1,
            'default_occupancy' => 1,
            'base_price' => 0.00,
            'room_size' => '',
            'beds' => '',
            'room_rules' => '',
            'amenities_intro' => '',
            'amenity_keys' => [],
            'main_image_id' => 0,
            'main_image_id_input' => '',
            'gallery_ids' => [],
            'gallery_ids_input' => '',
            'admin_notes' => '',
            'quick_links' => [],
            'configuration' => [],
            'warnings' => [],
        ];

        if (\is_array($submittedForm)) {
            return \array_merge($defaults, $submittedForm);
        }

        if ($query->getAction() !== 'edit_type' || $query->getTypeId() <= 0) {
            return $defaults;
        }

        $roomId = $query->getTypeId();
        $room = $this->roomRepository->getRoomById($roomId);

        if (!\is_array($room)) {
            return $defaults;
        }

        $detailRows = $this->buildAccommodationTypeRows([$room], \current_time('Y-m-d'));
        $detailRow = isset($detailRows[0]) && \is_array($detailRows[0]) ? $detailRows[0] : [];
        $galleryIds = RoomData::getRoomGalleryImageIds($roomId);

        return [
            'type_id' => $roomId,
            'name' => (string) ($room['name'] ?? ''),
            'slug' => (string) ($room['slug'] ?? ''),
            'category' => (string) ($room['category'] ?? 'standard-rooms'),
            'description' => (string) ($room['description'] ?? ''),
            'internal_code' => (string) ($room['internal_code'] ?? ''),
            'is_active' => !empty($room['is_active']) ? 1 : 0,
            'is_bookable' => !empty($room['is_bookable']) ? 1 : 0,
            'is_online_bookable' => !empty($room['is_online_bookable']) ? 1 : 0,
            'is_calendar_visible' => !empty($room['is_calendar_visible']) ? 1 : 0,
            'sort_order' => (int) ($room['sort_order'] ?? 0),
            'max_adults' => (int) ($room['max_adults'] ?? 1),
            'max_children' => (int) ($room['max_children'] ?? 0),
            'max_guests' => (int) ($room['max_guests'] ?? 1),
            'default_occupancy' => (int) ($room['default_occupancy'] ?? 1),
            'base_price' => (float) ($room['base_price'] ?? 0.0),
            'room_size' => (string) ($room['room_size'] ?? ''),
            'beds' => (string) ($room['beds'] ?? ''),
            'room_rules' => RoomData::getRoomRulesText($roomId),
            'amenities_intro' => RoomData::getRoomAmenitiesIntroText($roomId),
            'amenity_keys' => RoomData::getRoomAmenities($roomId),
            'main_image_id' => RoomData::getRoomMainImageId($roomId),
            'main_image_id_input' => (string) RoomData::getRoomMainImageId($roomId),
            'gallery_ids' => $galleryIds,
            'gallery_ids_input' => \implode(',', $galleryIds),
            'admin_notes' => (string) ($room['admin_notes'] ?? ''),
            'quick_links' => $this->extractQuickLinks($detailRow),
            'configuration' => $this->extractConfigurationSummary($detailRow),
            'warnings' => isset($detailRow['warnings']) && \is_array($detailRow['warnings']) ? $detailRow['warnings'] : [],
        ];
    }

    /**
     * @param array<string, mixed>|null $submittedForm
     * @return array<string, mixed>
     */
    private function getUnitFormData(AccommodationAdminQuery $query, ?array $submittedForm): array
    {
        $defaults = [
            'unit_id' => 0,
            'room_type_id' => $query->getUnitTypeId() > 0 ? $query->getUnitTypeId() : 0,
            'title' => '',
            'room_number' => '',
            'floor' => 0,
            'status' => 'available',
            'is_active' => 1,
            'is_bookable' => 1,
            'is_calendar_visible' => 1,
            'sort_order' => 0,
            'capacity_override' => 0,
            'building' => '',
            'section' => '',
            'admin_notes' => '',
            'quick_links' => [],
            'configuration' => [],
            'warnings' => [],
        ];

        if (\is_array($submittedForm)) {
            return \array_merge($defaults, $submittedForm);
        }

        if ($query->getAction() !== 'edit_unit' || $query->getUnitId() <= 0) {
            return $defaults;
        }

        $unitId = $query->getUnitId();
        $unit = $this->inventoryRepository->getInventoryRoomById($unitId);

        if (!\is_array($unit)) {
            return $defaults;
        }

        $roomTypeId = isset($unit['room_type_id']) ? (int) $unit['room_type_id'] : 0;
        $typeIndex = [];

        if ($roomTypeId > 0) {
            $room = $this->roomRepository->getRoomById($roomTypeId);

            if (\is_array($room)) {
                $typeRows = $this->buildAccommodationTypeRows([$room], \current_time('Y-m-d'));
                $typeIndex = $this->indexRowsById($typeRows);
            }
        }

        $unitRows = $this->buildUnitRows([$unit], $typeIndex, \current_time('Y-m-d'));
        $unitRow = isset($unitRows[0]) && \is_array($unitRows[0]) ? $unitRows[0] : [];

        return [
            'unit_id' => $unitId,
            'room_type_id' => $roomTypeId,
            'title' => (string) ($unit['title'] ?? ''),
            'room_number' => (string) ($unit['room_number'] ?? ''),
            'floor' => (int) ($unit['floor'] ?? 0),
            'status' => (string) ($unit['status'] ?? 'available'),
            'is_active' => !empty($unit['is_active']) ? 1 : 0,
            'is_bookable' => !empty($unit['is_bookable']) ? 1 : 0,
            'is_calendar_visible' => !empty($unit['is_calendar_visible']) ? 1 : 0,
            'sort_order' => (int) ($unit['sort_order'] ?? 0),
            'capacity_override' => (int) ($unit['capacity_override'] ?? 0),
            'building' => (string) ($unit['building'] ?? ''),
            'section' => (string) ($unit['section'] ?? ''),
            'admin_notes' => (string) ($unit['admin_notes'] ?? ''),
            'quick_links' => $this->extractUnitQuickLinks($unitRow),
            'configuration' => $this->extractConfigurationSummary($unitRow),
            'warnings' => isset($unitRow['warnings']) && \is_array($unitRow['warnings']) ? $unitRow['warnings'] : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $types
     * @param array<int, array<string, mixed>> $units
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $types, array $units): array
    {
        $activeTypes = 0;
        $activeUnits = 0;
        $missingPricing = 0;
        $missingAvailability = 0;
        $futureReservations = 0;

        foreach ($types as $type) {
            if (!empty($type['is_active'])) {
                $activeTypes++;
            }

            if (empty($type['pricing_configured'])) {
                $missingPricing++;
            }

            if (empty($type['availability_configured'])) {
                $missingAvailability++;
            }

            $futureReservations += (int) ($type['future_reservations'] ?? 0);
        }

        foreach ($units as $unit) {
            if (!empty($unit['is_active'])) {
                $activeUnits++;
            }
        }

        return [
            [
                'label' => \__('Accommodation Types', 'must-hotel-booking'),
                'value' => (string) \count($types),
                'meta' => \sprintf(__('%d active sellable types', 'must-hotel-booking'), $activeTypes),
            ],
            [
                'label' => \__('Physical Units', 'must-hotel-booking'),
                'value' => (string) \count($units),
                'meta' => \sprintf(__('%d active units in inventory', 'must-hotel-booking'), $activeUnits),
            ],
            [
                'label' => \__('Missing Pricing', 'must-hotel-booking'),
                'value' => (string) $missingPricing,
                'meta' => \__('Types still missing a base price or active rate plan.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Missing Availability', 'must-hotel-booking'),
                'value' => (string) $missingAvailability,
                'meta' => \__('Types without units or explicit availability setup.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Future Reservations', 'must-hotel-booking'),
                'value' => (string) $futureReservations,
                'meta' => \__('Upcoming stays currently tied to configured accommodations.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function extractQuickLinks(array $row): array
    {
        return [
            'pricing' => (string) ($row['pricing_url'] ?? ''),
            'rate_plans' => (string) ($row['rate_plans_url'] ?? ''),
            'availability' => (string) ($row['availability_url'] ?? ''),
            'calendar' => (string) ($row['calendar_url'] ?? ''),
            'reservations' => (string) ($row['reservations_url'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function extractUnitQuickLinks(array $row): array
    {
        return [
            'type' => (string) ($row['type_edit_url'] ?? ''),
            'calendar' => (string) ($row['calendar_url'] ?? ''),
            'reservations' => (string) ($row['reservations_url'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function extractConfigurationSummary(array $row): array
    {
        return [
            'pricing' => !empty($row['pricing_configured'])
                ? \__('Pricing configured', 'must-hotel-booking')
                : \__('Pricing still missing', 'must-hotel-booking'),
            'availability' => !empty($row['availability_configured'])
                ? \__('Availability configured', 'must-hotel-booking')
                : \__('Availability setup still missing', 'must-hotel-booking'),
            'future_reservations' => \sprintf(
                \_n('%d future reservation', '%d future reservations', (int) ($row['future_reservations'] ?? 0), 'must-hotel-booking'),
                (int) ($row['future_reservations'] ?? 0)
            ),
            'current_reservations' => \sprintf(
                \_n('%d active stay', '%d active stays', (int) ($row['current_reservations'] ?? 0), 'must-hotel-booking'),
                (int) ($row['current_reservations'] ?? 0)
            ),
            'next_checkin' => (string) ($row['next_checkin'] ?? '') !== ''
                ? \sprintf(__('Next arrival %s', 'must-hotel-booking'), (string) $row['next_checkin'])
                : \__('No upcoming reservation currently scheduled.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function validateTypeCapacityValues(array $row): array
    {
        $maxAdults = \max(0, (int) ($row['max_adults'] ?? 0));
        $maxChildren = \max(0, (int) ($row['max_children'] ?? 0));
        $maxGuests = \max(0, (int) ($row['max_guests'] ?? 0));
        $defaultOccupancy = \max(0, (int) ($row['default_occupancy'] ?? 0));
        $warnings = [];

        if ($maxAdults <= 0) {
            $warnings[] = \__('Max adults must be at least 1.', 'must-hotel-booking');
        }

        if ($maxGuests < $maxAdults) {
            $warnings[] = \__('Max guests is lower than max adults.', 'must-hotel-booking');
        }

        if ($defaultOccupancy <= 0 || $defaultOccupancy > $maxGuests) {
            $warnings[] = \__('Default occupancy must be between 1 and max guests.', 'must-hotel-booking');
        }

        if ($maxChildren > 0 && $maxGuests > ($maxAdults + $maxChildren)) {
            $warnings[] = \__('Max guests exceeds the combined adult and child allowance.', 'must-hotel-booking');
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatCapacitySummary(array $row): string
    {
        return \sprintf(
            __('%1$d adults / %2$d children / %3$d total', 'must-hotel-booking'),
            (int) ($row['max_adults'] ?? 0),
            (int) ($row['max_children'] ?? 0),
            (int) ($row['max_guests'] ?? 0)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $summaryRows
     * @return array<string, mixed>
     */
    private function findRoomTypeInventorySummary(array $summaryRows, int $typeId): array
    {
        foreach ($summaryRows as $summaryRow) {
            if (!\is_array($summaryRow)) {
                continue;
            }

            if ((int) ($summaryRow['room_type_id'] ?? 0) === $typeId) {
                return $summaryRow;
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexRowsById(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $rowId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($rowId > 0) {
                $indexed[$rowId] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $types
     * @return array<int, array<string, mixed>>
     */
    private function buildTypeOptions(array $types): array
    {
        $options = [];

        foreach ($types as $type) {
            if (!\is_array($type)) {
                continue;
            }

            $typeId = isset($type['id']) ? (int) $type['id'] : 0;

            if ($typeId <= 0) {
                continue;
            }

            $label = (string) ($type['name'] ?? ('#' . $typeId));

            if (empty($type['is_active'])) {
                $label .= ' ' . __('(Inactive)', 'must-hotel-booking');
            }

            $options[] = [
                'id' => $typeId,
                'label' => $label,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function getUnitStatusOptions(): array
    {
        return [
            'available' => \__('Available', 'must-hotel-booking'),
            'maintenance' => \__('Maintenance', 'must-hotel-booking'),
            'out_of_service' => \__('Out of Service', 'must-hotel-booking'),
            'blocked' => \__('Blocked', 'must-hotel-booking'),
        ];
    }

    private function getUnitStatusLabel(string $status): string
    {
        $options = $this->getUnitStatusOptions();

        return isset($options[$status]) ? (string) $options[$status] : \ucwords(\str_replace('_', ' ', $status));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildTypeFilterChips(AccommodationAdminQuery $query, int $count): array
    {
        $chips = [];

        if ($query->getSearch() !== '') {
            $chips[] = ['label' => \__('Search', 'must-hotel-booking'), 'value' => $query->getSearch()];
        }

        if ($query->getCategory() !== '') {
            $chips[] = ['label' => \__('Category', 'must-hotel-booking'), 'value' => RoomCatalog::getCategoryLabel($query->getCategory())];
        }

        if ($query->getStatus() !== '') {
            $chips[] = ['label' => \__('Status', 'must-hotel-booking'), 'value' => $query->getStatus()];
        }

        if ($query->getBookable() !== '') {
            $chips[] = ['label' => \__('Bookable', 'must-hotel-booking'), 'value' => $query->getBookable()];
        }

        if ($query->getSetup() !== '') {
            $chips[] = ['label' => \__('Setup', 'must-hotel-booking'), 'value' => \str_replace('_', ' ', $query->getSetup())];
        }

        if ($query->getFuture() !== '') {
            $chips[] = ['label' => \__('Future Reservations', 'must-hotel-booking'), 'value' => $query->getFuture()];
        }

        $chips[] = ['label' => \__('Results', 'must-hotel-booking'), 'value' => (string) $count];

        return $chips;
    }

    /**
     * @param array<int, array<string, mixed>> $types
     * @return array<int, array<string, string>>
     */
    private function buildUnitFilterChips(AccommodationAdminQuery $query, int $count, array $types): array
    {
        $chips = [];

        if ($query->getSearch() !== '') {
            $chips[] = ['label' => \__('Search', 'must-hotel-booking'), 'value' => $query->getSearch()];
        }

        if ($query->getUnitTypeId() > 0) {
            $typeLabel = '#' . $query->getUnitTypeId();

            foreach ($types as $type) {
                if ((int) ($type['id'] ?? 0) === $query->getUnitTypeId()) {
                    $typeLabel = (string) ($type['name'] ?? $typeLabel);
                    break;
                }
            }

            $chips[] = ['label' => \__('Type', 'must-hotel-booking'), 'value' => $typeLabel];
        }

        if ($query->getStatus() !== '') {
            $chips[] = ['label' => \__('Status', 'must-hotel-booking'), 'value' => $query->getStatus()];
        }

        if ($query->getBookable() !== '') {
            $chips[] = ['label' => \__('Bookable', 'must-hotel-booking'), 'value' => $query->getBookable()];
        }

        if ($query->getUnitStatus() !== '') {
            $chips[] = ['label' => \__('Operational State', 'must-hotel-booking'), 'value' => $this->getUnitStatusLabel($query->getUnitStatus())];
        }

        if ($query->getFuture() !== '') {
            $chips[] = ['label' => \__('Future Reservations', 'must-hotel-booking'), 'value' => $query->getFuture()];
        }

        $chips[] = ['label' => \__('Results', 'must-hotel-booking'), 'value' => (string) $count];

        return $chips;
    }
}
