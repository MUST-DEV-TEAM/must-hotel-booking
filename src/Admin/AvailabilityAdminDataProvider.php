<?php

namespace MustHotelBooking\Admin;

final class AvailabilityAdminDataProvider
{
    private \MustHotelBooking\Database\AvailabilityRepository $availabilityRepository;
    private \MustHotelBooking\Database\ReservationRepository $reservationRepository;
    private \MustHotelBooking\Database\RoomRepository $roomRepository;
    private \MustHotelBooking\Database\InventoryRepository $inventoryRepository;

    public function __construct()
    {
        $this->availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function getPageData(AvailabilityAdminQuery $query, array $state = []): array
    {
        $today = \current_time('Y-m-d');
        $rooms = $this->roomRepository->getAccommodationAdminRows();
        $roomIds = \array_values(\array_filter(\array_map(static fn(array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $rooms)));
        $availabilitySummaryMap = $this->availabilityRepository->getRoomAvailabilityRuleSummaryMap($roomIds);
        $reservationSummaryMap = $this->reservationRepository->getAccommodationReservationSummaryMap($roomIds, $today);
        $ruleRows = $this->availabilityRepository->getAdminRules(['include_inactive' => true, 'today' => $today]);
        $blockRows = $this->reservationRepository->getBlockedReservationRows(['today' => $today, 'limit' => 1000]);
        $roomRows = $this->buildRoomRows($rooms, $availabilitySummaryMap, $reservationSummaryMap, $ruleRows, $blockRows, $today);
        $entryRows = $this->filterEntryRows($this->buildEntryRows($ruleRows, $blockRows, $today), $query);
        $submittedRuleForm = isset($state['rule_form']) && \is_array($state['rule_form']) ? $state['rule_form'] : null;
        $submittedBlockForm = isset($state['block_form']) && \is_array($state['block_form']) ? $state['block_form'] : null;

        return [
            'today' => $today,
            'filters' => [
                'room_id' => $query->getRoomId(),
                'status' => $query->getStatus(),
                'timeline' => $query->getTimeline(),
                'mode' => $query->getMode(),
                'rule_type' => $query->getRuleType(),
                'search' => $query->getSearch(),
            ],
            'room_options' => $this->buildRoomOptions($rooms),
            'summary_cards' => $this->buildSummaryCards($roomRows, $entryRows),
            'room_rows' => $this->filterRoomRows($roomRows, $query),
            'entry_rows' => $entryRows,
            'rule_form' => $this->getRuleFormData($query, $submittedRuleForm),
            'block_form' => $this->getBlockFormData($query, $submittedBlockForm),
            'rule_errors' => isset($state['rule_errors']) && \is_array($state['rule_errors']) ? $state['rule_errors'] : [],
            'block_errors' => isset($state['block_errors']) && \is_array($state['block_errors']) ? $state['block_errors'] : [],
            'booking_note' => \__('Availability is resolved from active restriction rules, then live inventory, blocked reservations, and temporary locks.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @param array<int, array<string, int>> $availabilitySummaryMap
     * @param array<int, array<string, mixed>> $reservationSummaryMap
     * @param array<int, array<string, mixed>> $ruleRows
     * @param array<int, array<string, mixed>> $blockRows
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomRows(array $rooms, array $availabilitySummaryMap, array $reservationSummaryMap, array $ruleRows, array $blockRows, string $today): array
    {
        $rows = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $availabilityData = $availabilitySummaryMap[$roomId] ?? ['rule_count' => 0, 'maintenance_block_count' => 0];
            $reservationData = $reservationSummaryMap[$roomId] ?? ['future_reservations' => 0, 'current_reservations' => 0, 'next_checkin' => ''];
            $roomRuleRows = \array_values(\array_filter($ruleRows, static fn(array $rule): bool => (int) ($rule['room_id'] ?? 0) === $roomId));
            $globalRuleRows = \array_values(\array_filter($ruleRows, static fn(array $rule): bool => (int) ($rule['room_id'] ?? 0) === 0 && !empty($rule['is_active'])));
            $roomBlockRows = \array_values(\array_filter($blockRows, static fn(array $row): bool => (int) ($row['room_id'] ?? 0) === $roomId));
            $currentBlockCount = \count(\array_filter($roomBlockRows, static fn(array $row): bool => (string) ($row['checkin'] ?? '') <= $today && (string) ($row['checkout'] ?? '') > $today));
            $futureBlockCount = \count(\array_filter($roomBlockRows, static fn(array $row): bool => (string) ($row['checkout'] ?? '') > $today));
            $hasInventory = $this->inventoryRepository->hasInventoryForRoomType($roomId);
            $warnings = [];

            if (!$hasInventory) {
                $warnings[] = \__('No physical inventory units are assigned to this room listing.', 'must-hotel-booking');
            }

            if (empty($room['is_active']) && ((int) ($availabilityData['rule_count'] ?? 0) > 0 || $futureBlockCount > 0)) {
                $warnings[] = \__('Inactive accommodation still has active restrictions or manual blocks.', 'must-hotel-booking');
            }

            if (!empty($room['is_bookable']) && !$hasInventory && (int) ($availabilityData['rule_count'] ?? 0) === 0) {
                $warnings[] = \__('Bookable accommodation has no inventory units and no explicit availability rules.', 'must-hotel-booking');
            }

            if ($futureBlockCount > 0 && (int) ($reservationData['future_reservations'] ?? 0) > 0) {
                $warnings[] = \__('Future manual blocks overlap future reservations on this room listing.', 'must-hotel-booking');
            }

            $rows[] = [
                'id' => $roomId,
                'name' => (string) ($room['name'] ?? ''),
                'category' => (string) ($room['category'] ?? ''),
                'is_active' => !empty($room['is_active']),
                'is_bookable' => !empty($room['is_bookable']),
                'has_inventory' => $hasInventory,
                'rule_count' => (int) ($availabilityData['rule_count'] ?? 0),
                'maintenance_block_count' => (int) ($availabilityData['maintenance_block_count'] ?? 0),
                'global_rule_count' => \count($globalRuleRows),
                'current_block_count' => $currentBlockCount,
                'future_block_count' => $futureBlockCount,
                'future_reservations' => (int) ($reservationData['future_reservations'] ?? 0),
                'current_reservations' => (int) ($reservationData['current_reservations'] ?? 0),
                'next_checkin' => (string) ($reservationData['next_checkin'] ?? ''),
                'warnings' => $warnings,
                'calendar_url' => \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
                    ? get_admin_calendar_page_url(['room_id' => $roomId, 'focus_room_id' => $roomId, 'start_date' => $today, 'weeks' => 2])
                    : '',
                'accommodation_url' => \function_exists(__NAMESPACE__ . '\get_admin_rooms_page_url')
                    ? get_admin_rooms_page_url(['tab' => 'rooms', 'action' => 'edit_room', 'type_id' => $roomId])
                    : '',
                'filtered_url' => get_admin_availability_rules_page_url(['room_id' => $roomId]),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $ruleRows
     * @param array<int, array<string, mixed>> $blockRows
     * @return array<int, array<string, mixed>>
     */
    private function buildEntryRows(array $ruleRows, array $blockRows, string $today): array
    {
        $rows = [];

        foreach ($ruleRows as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $timeline = 'future';
            $startDate = (string) ($rule['availability_date'] ?? '');
            $endDate = (string) ($rule['end_date'] ?? $startDate);

            if ($endDate < $today) {
                $timeline = 'past';
            } elseif ($startDate <= $today && $endDate >= $today) {
                $timeline = 'current';
            }

            $ruleType = (string) ($rule['rule_type'] ?? '');
            $ruleValue = (int) ($rule['rule_value'] ?? 0);
            $rows[] = [
                'id' => (int) ($rule['id'] ?? 0),
                'source' => 'rule',
                'room_id' => (int) ($rule['room_id'] ?? 0),
                'room_name' => (int) ($rule['room_id'] ?? 0) > 0 ? (string) ($rule['room_name'] ?? '') : \__('All accommodations', 'must-hotel-booking'),
                'name' => (string) ($rule['name'] ?? ''),
                'rule_type' => $ruleType,
                'rule_type_label' => $this->getRuleTypeLabel($ruleType),
                'availability_date' => $startDate,
                'end_date' => $endDate,
                'minimum_stay' => $ruleType === 'minimum_stay' ? $ruleValue : 0,
                'maximum_stay' => $ruleType === 'maximum_stay' ? $ruleValue : 0,
                'checkin' => $ruleType === 'closed_arrival' ? \__('Blocked', 'must-hotel-booking') : \__('Allowed', 'must-hotel-booking'),
                'checkout' => $ruleType === 'closed_departure' ? \__('Blocked', 'must-hotel-booking') : \__('Allowed', 'must-hotel-booking'),
                'status_label' => !empty($rule['is_active']) ? \__('Active', 'must-hotel-booking') : \__('Inactive', 'must-hotel-booking'),
                'is_active' => !empty($rule['is_active']),
                'timeline' => $timeline,
                'mode' => 'restriction',
            ];
        }

        foreach ($blockRows as $block) {
            if (!\is_array($block)) {
                continue;
            }

            $timeline = 'future';
            $startDate = (string) ($block['checkin'] ?? '');
            $endDate = (string) ($block['checkout'] ?? '');

            if ($endDate <= $today) {
                $timeline = 'past';
            } elseif ($startDate <= $today && $endDate > $today) {
                $timeline = 'current';
            }

            $rows[] = [
                'id' => (int) ($block['id'] ?? 0),
                'source' => 'block',
                'room_id' => (int) ($block['room_id'] ?? 0),
                'room_name' => (string) ($block['room_name'] ?? ''),
                'name' => (string) (($block['notes'] ?? '') !== '' ? $block['notes'] : __('Manual block', 'must-hotel-booking')),
                'rule_type' => 'manual_block',
                'rule_type_label' => \__('Manual Block', 'must-hotel-booking'),
                'availability_date' => $startDate,
                'end_date' => $endDate,
                'minimum_stay' => 0,
                'maximum_stay' => 0,
                'checkin' => \__('Blocked', 'must-hotel-booking'),
                'checkout' => \__('Blocked', 'must-hotel-booking'),
                'status_label' => \__('Active', 'must-hotel-booking'),
                'is_active' => true,
                'timeline' => $timeline,
                'mode' => 'blocked',
            ];
        }

        \usort(
            $rows,
            static function (array $left, array $right): int {
                $leftStart = (string) ($left['availability_date'] ?? '');
                $rightStart = (string) ($right['availability_date'] ?? '');

                if ($leftStart !== $rightStart) {
                    return $rightStart <=> $leftStart;
                }

                return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
            }
        );

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterRoomRows(array $rows, AvailabilityAdminQuery $query): array
    {
        return \array_values(\array_filter(
            $rows,
            function (array $row) use ($query): bool {
                if ($query->getRoomId() > 0 && (int) ($row['id'] ?? 0) !== $query->getRoomId()) {
                    return false;
                }

                if ($query->getStatus() === 'active' && empty($row['is_active'])) {
                    return false;
                }

                if ($query->getStatus() === 'inactive' && !empty($row['is_active'])) {
                    return false;
                }

                if ($query->getSearch() !== '') {
                    $haystack = \strtolower((string) (($row['name'] ?? '') . ' ' . ($row['category'] ?? '')));

                    if (\strpos($haystack, \strtolower($query->getSearch())) === false) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterEntryRows(array $rows, AvailabilityAdminQuery $query): array
    {
        return \array_values(\array_filter(
            $rows,
            function (array $row) use ($query): bool {
                if ($query->getRoomId() > 0 && (int) ($row['room_id'] ?? 0) !== $query->getRoomId()) {
                    return false;
                }

                if ($query->getStatus() === 'active' && empty($row['is_active'])) {
                    return false;
                }

                if ($query->getStatus() === 'inactive' && !empty($row['is_active'])) {
                    return false;
                }

                if ($query->getTimeline() !== '' && $query->getTimeline() !== 'all' && (string) ($row['timeline'] ?? '') !== $query->getTimeline()) {
                    return false;
                }

                if ($query->getMode() === 'blocked' && (string) ($row['mode'] ?? '') !== 'blocked') {
                    return false;
                }

                if ($query->getMode() === 'restriction' && (string) ($row['mode'] ?? '') !== 'restriction') {
                    return false;
                }

                if ($query->getRuleType() !== '' && $query->getRuleType() !== 'all' && (string) ($row['rule_type'] ?? '') !== $query->getRuleType()) {
                    return false;
                }

                if ($query->getSearch() !== '') {
                    $haystack = \strtolower((string) (($row['name'] ?? '') . ' ' . ($row['room_name'] ?? '') . ' ' . ($row['rule_type_label'] ?? '')));

                    if (\strpos($haystack, \strtolower($query->getSearch())) === false) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomOptions(array $rooms): array
    {
        $options = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $options[] = [
                'id' => $roomId,
                'label' => (string) ($room['name'] ?? ('#' . $roomId)),
            ];
        }

        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $roomRows
     * @param array<int, array<string, mixed>> $entryRows
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $roomRows, array $entryRows): array
    {
        $manualBlocks = 0;
        $activeRestrictions = 0;
        $roomWarnings = 0;

        foreach ($entryRows as $row) {
            if ((string) ($row['mode'] ?? '') === 'blocked') {
                $manualBlocks++;
            } elseif (!empty($row['is_active'])) {
                $activeRestrictions++;
            }
        }

        foreach ($roomRows as $row) {
            if (!empty($row['warnings'])) {
                $roomWarnings++;
            }
        }

        return [
            [
                'label' => \__('Room Listings', 'must-hotel-booking'),
                'value' => (string) \count($roomRows),
                'meta' => \__('Availability rules still apply at the sellable room/listing level.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Active Restrictions', 'must-hotel-booking'),
                'value' => (string) $activeRestrictions,
                'meta' => \__('Closed arrival/departure, stay rules, and maintenance blocks currently tracked here.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Manual Blocks', 'must-hotel-booking'),
                'value' => (string) $manualBlocks,
                'meta' => \__('Manual blocks are stored as blocked reservations and still affect sellability.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Setup Warnings', 'must-hotel-booking'),
                'value' => (string) $roomWarnings,
                'meta' => \__('Room listings with inventory gaps, inactive-state conflicts, or future block issues.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $submittedForm
     * @return array<string, mixed>
     */
    private function getRuleFormData(AvailabilityAdminQuery $query, ?array $submittedForm): array
    {
        $startDate = $query->getStartDate() !== '' ? $query->getStartDate() : \current_time('Y-m-d');
        $defaults = [
            'id' => 0,
            'room_id' => $query->getRoomId(),
            'name' => '',
            'availability_date' => $startDate,
            'end_date' => $query->getEndDate() !== '' ? $query->getEndDate() : $startDate,
            'rule_type' => $query->getRuleType() !== '' && $query->getRuleType() !== 'manual_block' ? $query->getRuleType() : 'maintenance_block',
            'rule_value' => 1,
            'is_active' => 1,
            'reason' => '',
            'warnings' => [],
        ];

        if (\is_array($submittedForm)) {
            $submittedForm['warnings'] = $this->buildRuleWarnings($submittedForm);
            return \array_merge($defaults, $submittedForm);
        }

        if ($query->getAction() !== 'edit_rule' || $query->getRuleId() <= 0) {
            return $defaults;
        }

        $rule = $this->availabilityRepository->getRuleById($query->getRuleId());

        if (!\is_array($rule)) {
            return $defaults;
        }

        return [
            'id' => (int) ($rule['id'] ?? 0),
            'room_id' => (int) ($rule['room_id'] ?? 0),
            'name' => (string) ($rule['name'] ?? ''),
            'availability_date' => (string) ($rule['availability_date'] ?? $defaults['availability_date']),
            'end_date' => (string) ($rule['end_date'] ?? $defaults['end_date']),
            'rule_type' => (string) ($rule['rule_type'] ?? $defaults['rule_type']),
            'rule_value' => (int) ($rule['rule_value'] ?? 1),
            'is_active' => !empty($rule['is_active']) ? 1 : 0,
            'reason' => (string) ($rule['reason'] ?? ''),
            'warnings' => $this->buildRuleWarnings($rule),
        ];
    }

    /**
     * @param array<string, mixed>|null $submittedForm
     * @return array<string, mixed>
     */
    private function getBlockFormData(AvailabilityAdminQuery $query, ?array $submittedForm): array
    {
        $startDate = $query->getStartDate() !== '' ? $query->getStartDate() : \current_time('Y-m-d');
        $defaults = [
            'id' => 0,
            'room_id' => $query->getRoomId(),
            'checkin' => $startDate,
            'checkout' => $query->getEndDate() !== '' ? $query->getEndDate() : (new \DateTimeImmutable($startDate))->modify('+1 day')->format('Y-m-d'),
            'notes' => '',
            'warnings' => [],
        ];

        if (\is_array($submittedForm)) {
            $submittedForm['warnings'] = $this->buildBlockWarnings($submittedForm);
            return \array_merge($defaults, $submittedForm);
        }

        if ($query->getAction() !== 'edit_block' || $query->getBlockId() <= 0) {
            return $defaults;
        }

        $block = $this->reservationRepository->getReservation($query->getBlockId());

        if (!\is_array($block) || (string) ($block['status'] ?? '') !== 'blocked') {
            return $defaults;
        }

        return [
            'id' => (int) ($block['id'] ?? 0),
            'room_id' => (int) ($block['room_id'] ?? 0),
            'checkin' => (string) ($block['checkin'] ?? $defaults['checkin']),
            'checkout' => (string) ($block['checkout'] ?? $defaults['checkout']),
            'notes' => (string) ($block['notes'] ?? ''),
            'warnings' => $this->buildBlockWarnings($block),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<int, string>
     */
    private function buildRuleWarnings(array $rule): array
    {
        $warnings = [];
        $roomId = isset($rule['room_id']) ? (int) $rule['room_id'] : 0;
        $startDate = (string) ($rule['availability_date'] ?? '');
        $endDate = (string) ($rule['end_date'] ?? '');
        $overlaps = $this->availabilityRepository->getOverlappingRules($rule, isset($rule['id']) ? (int) $rule['id'] : 0);

        if (!empty($overlaps)) {
            $warnings[] = \sprintf(_n('%d active overlap exists for the same rule type.', '%d active overlaps exist for the same rule type.', \count($overlaps), 'must-hotel-booking'), \count($overlaps));
        }

        if ($roomId <= 0) {
            $warnings[] = \__('This rule is global and applies unless a room-specific operational state removes sellability first.', 'must-hotel-booking');
        }

        if ($roomId > 0 && $startDate !== '' && $endDate !== '') {
            $reservationConflicts = $this->availabilityRepository->getOverlappingReservationRows($roomId, $startDate, $endDate);

            if (!empty($reservationConflicts)) {
                $warnings[] = \sprintf(_n('%d reservation overlaps this rule window.', '%d reservations overlap this rule window.', \count($reservationConflicts), 'must-hotel-booking'), \count($reservationConflicts));
            }
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<int, string>
     */
    private function buildBlockWarnings(array $block): array
    {
        $roomId = isset($block['room_id']) ? (int) $block['room_id'] : 0;
        $checkin = (string) ($block['checkin'] ?? '');
        $checkout = (string) ($block['checkout'] ?? '');
        $conflicts = $this->reservationRepository->getBlockedReservationConflicts($roomId, $checkin, $checkout, isset($block['id']) ? (int) $block['id'] : 0);
        $warnings = [];

        if (!empty($conflicts)) {
            $warnings[] = \sprintf(_n('%d existing reservation or block overlaps this manual block.', '%d existing reservations or blocks overlap this manual block.', \count($conflicts), 'must-hotel-booking'), \count($conflicts));
        }

        return $warnings;
    }

    private function getRuleTypeLabel(string $ruleType): string
    {
        $labels = [
            'maintenance_block' => \__('Blocked date range', 'must-hotel-booking'),
            'minimum_stay' => \__('Minimum stay', 'must-hotel-booking'),
            'maximum_stay' => \__('Maximum stay', 'must-hotel-booking'),
            'closed_arrival' => \__('Closed arrival', 'must-hotel-booking'),
            'closed_departure' => \__('Closed departure', 'must-hotel-booking'),
            'manual_block' => \__('Manual Block', 'must-hotel-booking'),
        ];

        return $labels[$ruleType] ?? \__('Availability rule', 'must-hotel-booking');
    }
}
