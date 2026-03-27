<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Database\PricingRuleRepository;

final class AccommodationAuthorityAudit
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

    private PricingRuleRepository $pricingRuleRepository;

    public function __construct()
    {
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->ratePlanRepository = \MustHotelBooking\Engine\get_rate_plan_repository();
        $this->availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $this->pricingRuleRepository = new PricingRuleRepository();
    }

    /**
     * @param array<int, array<string, mixed>> $legacyTypeRows
     * @return array<string, mixed>
     */
    public function getAuditData(array $legacyTypeRows = []): array
    {
        if (empty($legacyTypeRows)) {
            $legacyTypeRows = $this->roomRepository->getAccommodationAdminRows();
        }

        $legacyTypeIndex = $this->indexRowsById($legacyTypeRows);
        $legacyTypeIds = \array_keys($legacyTypeIndex);
        $mirroredTypeRows = $this->inventoryRepository->getRoomTypes();
        $mirroredTypeIndex = $this->indexRowsById($mirroredTypeRows);
        $allTypeIds = \array_values(\array_unique(\array_merge($legacyTypeIds, \array_keys($mirroredTypeIndex))));
        \sort($allTypeIds);

        $inventorySummaryIndex = $this->indexSummaryRowsByRoomTypeId(
            $this->inventoryRepository->getRoomTypeInventorySummaries($allTypeIds)
        );
        $ratePlanSummaryIndex = $this->ratePlanRepository->getRoomTypeRatePlanSummaryMap($allTypeIds);
        $pricingSummaryIndex = $this->pricingRuleRepository->getRoomPricingRuleSummaryMap($allTypeIds);
        $availabilitySummaryIndex = $this->availabilityRepository->getRoomAvailabilityRuleSummaryMap($allTypeIds);
        $reservationSummaryIndex = $this->reservationRepository->getAccommodationReservationSummaryMap(
            $allTypeIds,
            \current_time('Y-m-d')
        );
        $roomDetailsPage = ManagedPages::getPageHealth('page_rooms_id');
        $rows = [];
        $typesWithBlockers = 0;
        $typesRequiringReview = 0;
        $mirroredWithoutLegacy = 0;
        $inventoryUnitCount = 0;

        foreach ($allTypeIds as $typeId) {
            $legacyType = $legacyTypeIndex[$typeId] ?? [];
            $mirroredType = $mirroredTypeIndex[$typeId] ?? [];
            $inventorySummary = $inventorySummaryIndex[$typeId] ?? [];
            $ratePlanSummary = isset($ratePlanSummaryIndex[$typeId]) && \is_array($ratePlanSummaryIndex[$typeId])
                ? $ratePlanSummaryIndex[$typeId]
                : [];
            $pricingSummary = isset($pricingSummaryIndex[$typeId]) && \is_array($pricingSummaryIndex[$typeId])
                ? $pricingSummaryIndex[$typeId]
                : [];
            $availabilitySummary = isset($availabilitySummaryIndex[$typeId]) && \is_array($availabilitySummaryIndex[$typeId])
                ? $availabilitySummaryIndex[$typeId]
                : [];
            $reservationSummary = isset($reservationSummaryIndex[$typeId]) && \is_array($reservationSummaryIndex[$typeId])
                ? $reservationSummaryIndex[$typeId]
                : [];
            $legacyExists = !empty($legacyType);
            $mirroredExists = !empty($mirroredType);
            $inventoryUnits = isset($inventorySummary['total_units']) ? (int) $inventorySummary['total_units'] : 0;
            $ratePlanAssignments = isset($ratePlanSummary['assignment_count']) ? (int) $ratePlanSummary['assignment_count'] : 0;
            $pricingRules = isset($pricingSummary['rule_count']) ? (int) $pricingSummary['rule_count'] : 0;
            $availabilityRules = isset($availabilitySummary['rule_count']) ? (int) $availabilitySummary['rule_count'] : 0;
            $reservations = isset($reservationSummary['reservation_count']) ? (int) $reservationSummary['reservation_count'] : 0;
            $futureReservations = isset($reservationSummary['future_reservations']) ? (int) $reservationSummary['future_reservations'] : 0;
            $currentReservations = isset($reservationSummary['current_reservations']) ? (int) $reservationSummary['current_reservations'] : 0;
            $blockers = [];

            if ($inventoryUnits > 0) {
                $blockers[] = \sprintf(
                    \_n('%d inventory unit exists', '%d inventory units exist', $inventoryUnits, 'must-hotel-booking'),
                    $inventoryUnits
                );
            }

            if ($ratePlanAssignments > 0) {
                $blockers[] = \sprintf(
                    \_n('%d rate plan assignment exists', '%d rate plan assignments exist', $ratePlanAssignments, 'must-hotel-booking'),
                    $ratePlanAssignments
                );
            }

            if ($pricingRules > 0) {
                $blockers[] = \sprintf(
                    \_n('%d pricing rule exists', '%d pricing rules exist', $pricingRules, 'must-hotel-booking'),
                    $pricingRules
                );
            }

            if ($availabilityRules > 0) {
                $blockers[] = \sprintf(
                    \_n('%d availability rule exists', '%d availability rules exist', $availabilityRules, 'must-hotel-booking'),
                    $availabilityRules
                );
            }

            if ($reservations > 0) {
                $blockers[] = \sprintf(
                    \_n('%d reservation still references this type', '%d reservations still reference this type', $reservations, 'must-hotel-booking'),
                    $reservations
                );
            }

            $deleteState = 'review';
            $deleteStateLabel = \__('Review manually', 'must-hotel-booking');

            if (!empty($blockers)) {
                $deleteState = 'blocked';
                $deleteStateLabel = \__('Cannot delete yet', 'must-hotel-booking');
                $typesWithBlockers++;
            } elseif ($legacyExists) {
                $deleteState = 'ready';
                $deleteStateLabel = \__('Safe to delete', 'must-hotel-booking');
                $typesRequiringReview++;
            } elseif ($mirroredExists) {
                $typesRequiringReview++;
            }

            if ($mirroredExists && !$legacyExists) {
                $mirroredWithoutLegacy++;
            }

            $inventoryUnitCount += $inventoryUnits;

            $rows[] = [
                'id' => $typeId,
                'name' => $this->resolveTypeName($legacyType, $mirroredType, $typeId),
                'legacy_exists' => $legacyExists,
                'mirrored_exists' => $mirroredExists,
                'inventory_units' => $inventoryUnits,
                'rate_plan_assignments' => $ratePlanAssignments,
                'pricing_rules' => $pricingRules,
                'availability_rules' => $availabilityRules,
                'reservations' => $reservations,
                'future_reservations' => $futureReservations,
                'current_reservations' => $currentReservations,
                'delete_state' => $deleteState,
                'delete_state_label' => $deleteStateLabel,
                'blockers' => $blockers,
            ];
        }

        $warnings = [];

        if (!empty($legacyTypeIds) && $this->isLegacySyncBridgeActive()) {
            $warnings[] = \__('Legacy accommodation rows in must_rooms still define the IDs used by the internal inventory mirror. Delete those rows only after their inventory, pricing, availability, and reservation blockers are cleared.', 'must-hotel-booking');
        }

        if ($typesWithBlockers > 0) {
            $warnings[] = \sprintf(
                \_n('%d accommodation type still has hard blockers for deletion.', '%d accommodation types still have hard blockers for deletion.', $typesWithBlockers, 'must-hotel-booking'),
                $typesWithBlockers
            );
        }

        if ($mirroredWithoutLegacy > 0) {
            $warnings[] = \sprintf(
                \_n('%d mirrored inventory room type exists without a matching legacy must_rooms row.', '%d mirrored inventory room types exist without matching legacy must_rooms rows.', $mirroredWithoutLegacy, 'must-hotel-booking'),
                $mirroredWithoutLegacy
            );
        }

        if ((string) ($roomDetailsPage['health'] ?? '') !== 'ok') {
            $warnings[] = \__('Room detail links are disabled until a published room-details page is explicitly assigned in settings.', 'must-hotel-booking');
        }

        return [
            'legacy_sync_bridge_active' => $this->isLegacySyncBridgeActive(),
            'legacy_type_count' => \count($legacyTypeIds),
            'mirrored_type_count' => \count($mirroredTypeIndex),
            'inventory_unit_count' => $inventoryUnitCount,
            'types_with_blockers' => $typesWithBlockers,
            'types_requiring_review' => $typesRequiringReview,
            'room_details_page' => $roomDetailsPage,
            'warnings' => $warnings,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTypeDependencyRow(int $typeId): ?array
    {
        if ($typeId <= 0) {
            return null;
        }

        $legacyType = $this->roomRepository->getRoomById($typeId);

        if (!\is_array($legacyType)) {
            return null;
        }

        $audit = $this->getAuditData([$legacyType]);
        $rows = isset($audit['rows']) && \is_array($audit['rows']) ? $audit['rows'] : [];

        foreach ($rows as $row) {
            if (\is_array($row) && (int) ($row['id'] ?? 0) === $typeId) {
                return $row;
            }
        }

        return null;
    }

    private function isLegacySyncBridgeActive(): bool
    {
        return \function_exists('\MustHotelBooking\Database\seed_inventory_model_from_legacy_rooms')
            && $this->inventoryRepository->roomTypesTableExists();
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
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexSummaryRowsByRoomTypeId(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomTypeId = isset($row['room_type_id']) ? (int) $row['room_type_id'] : 0;

            if ($roomTypeId > 0) {
                $indexed[$roomTypeId] = $row;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $legacyType
     * @param array<string, mixed> $mirroredType
     */
    private function resolveTypeName(array $legacyType, array $mirroredType, int $typeId): string
    {
        $legacyName = isset($legacyType['name']) ? \trim((string) $legacyType['name']) : '';

        if ($legacyName !== '') {
            return $legacyName;
        }

        $mirroredName = isset($mirroredType['name']) ? \trim((string) $mirroredType['name']) : '';

        if ($mirroredName !== '') {
            return $mirroredName;
        }

        return '#' . $typeId;
    }
}
