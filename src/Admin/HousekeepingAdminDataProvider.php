<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Database\HousekeepingRepository;

final class HousekeepingAdminDataProvider
{
    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventoryRepository;

    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservationRepository;

    /** @var \MustHotelBooking\Database\HousekeepingRepository */
    private $housekeepingRepository;

    public function __construct()
    {
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->housekeepingRepository = \MustHotelBooking\Engine\get_housekeeping_repository();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(string $activeTab = 'room-board'): array
    {
        $activeTab = \sanitize_key($activeTab);
        $today = \current_time('Y-m-d');
        $boardRows = $this->buildRoomBoardRows($today);
        $summaryCards = $this->buildSummaryCards($boardRows);
        $assignmentRows = $activeTab === 'assignments' ? $this->buildAssignmentRows($boardRows) : [];
        $issueRows = \in_array($activeTab, ['maintenance', 'handoff'], true)
            ? $this->buildIssueRows($boardRows, $activeTab === 'maintenance')
            : [];
        $handoffSnapshot = $activeTab === 'handoff' ? $this->buildHandoffSnapshot($boardRows, $issueRows) : [];

        return [
            'active_tab' => $activeTab,
            'today' => $today,
            'board_rows' => $boardRows,
            'summary_cards' => $summaryCards,
            'assignment_rows' => $assignmentRows,
            'assignee_options' => $activeTab === 'assignments' ? $this->getAssignableStaffOptions() : [],
            'issue_rows' => $issueRows,
            'issue_status_labels' => HousekeepingRepository::getIssueStatusLabels(),
            'maintenance_summary_cards' => $activeTab === 'maintenance' ? $this->buildMaintenanceSummaryCards($issueRows) : [],
            'handoff_snapshot' => $handoffSnapshot,
            'handoff_summary_cards' => $activeTab === 'handoff' ? $this->buildHandoffSummaryCards($handoffSnapshot) : [],
            'handoff_rows' => $activeTab === 'handoff' ? $this->buildHandoffRows() : [],
            'status_labels' => HousekeepingRepository::getStatusLabels(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRoomBoardRoom(int $roomId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }

        $boardRows = $this->buildRoomBoardRows(\current_time('Y-m-d'), [$roomId]);

        foreach ($boardRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ((int) ($row['id'] ?? 0) === $roomId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAssignableStaffOptions(): array
    {
        $users = \get_users(
            [
                'role__in' => [
                    StaffAccess::ROLE_HOUSEKEEPING,
                    StaffAccess::ROLE_OPS_MANAGER,
                ],
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => 'all',
            ]
        );
        $options = [];
        $roleLabels = StaffAccess::getRoleLabels();

        foreach ($users as $user) {
            if (!$user instanceof \WP_User || $user->ID <= 0) {
                continue;
            }

            $primaryRole = '';

            foreach ((array) $user->roles as $role) {
                if (isset($roleLabels[$role])) {
                    $primaryRole = (string) $role;
                    break;
                }
            }

            $options[] = [
                'id' => (int) $user->ID,
                'label' => $user->display_name !== '' ? (string) $user->display_name : (string) $user->user_login,
                'role_label' => $primaryRole !== '' && isset($roleLabels[$primaryRole]) ? (string) $roleLabels[$primaryRole] : '',
            ];
        }

        return $options;
    }

    /**
     * @return array<string, int>
     */
    public function getCurrentHandoffSnapshot(): array
    {
        $boardRows = $this->buildRoomBoardRows(\current_time('Y-m-d'));
        $issueRows = $this->buildIssueRows($boardRows, false);

        return $this->buildHandoffSnapshot($boardRows, $issueRows);
    }

    /**
     * @param array<int, int> $limitRoomIds
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomBoardRows(string $today, array $limitRoomIds = []): array
    {
        $rawRooms = $this->inventoryRepository->getInventoryUnitAdminRows();
        $roomTypeMap = $this->getRoomTypeMap();
        $rows = [];

        foreach ($rawRooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0 || empty($room['is_active'])) {
                continue;
            }

            if (!empty($limitRoomIds) && !\in_array($roomId, $limitRoomIds, true)) {
                continue;
            }

            $rows[] = $room;
        }

        if (empty($rows)) {
            return [];
        }

        $roomIds = [];

        foreach ($rows as $row) {
            $roomIds[] = (int) ($row['id'] ?? 0);
        }

        $roomIds = \array_values(
            \array_filter(
                \array_map('intval', $roomIds),
                static function (int $roomId): bool {
                    return $roomId > 0;
                }
            )
        );

        $reservationSummaryMap = $this->reservationRepository->getInventoryRoomReservationSummaryMap($roomIds, $today);
        $housekeepingStatusMap = $this->housekeepingRepository->getRoomStatusMap($roomIds);
        $issueCountsMap = $this->housekeepingRepository->getRoomIssueCountsMap($roomIds);
        $assigneeMap = $this->buildAssigneeLabelMap($housekeepingStatusMap);
        $boardRows = [];

        foreach ($rows as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $boardRow = $this->buildRoomBoardRow(
                $room,
                $roomTypeMap,
                $reservationSummaryMap,
                $housekeepingStatusMap,
                $issueCountsMap,
                $assigneeMap
            );

            if ($boardRow !== null) {
                $boardRows[] = $boardRow;
            }
        }

        return $boardRows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $rows): array
    {
        $counts = [
            HousekeepingRepository::STATUS_DIRTY => 0,
            HousekeepingRepository::STATUS_CLEAN => 0,
            HousekeepingRepository::STATUS_INSPECTED => 0,
            HousekeepingRepository::STATUS_OUT_OF_ORDER => 0,
        ];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $statusKey = (string) ($row['housekeeping_status_key'] ?? '');

            if (isset($counts[$statusKey])) {
                $counts[$statusKey]++;
            }
        }

        return [
            [
                'label' => \__('Rooms on board', 'must-hotel-booking'),
                'value' => (string) \count($rows),
                'descriptor' => \__('Active inventory units shown in housekeeping.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Dirty', 'must-hotel-booking'),
                'value' => (string) $counts[HousekeepingRepository::STATUS_DIRTY],
                'descriptor' => \__('Needs cleaning or first housekeeping update.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Clean', 'must-hotel-booking'),
                'value' => (string) $counts[HousekeepingRepository::STATUS_CLEAN],
                'descriptor' => \__('Cleaned and not yet inspected.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Inspected', 'must-hotel-booking'),
                'value' => (string) $counts[HousekeepingRepository::STATUS_INSPECTED],
                'descriptor' => \__('Ready rooms handed off after inspection.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Out of order', 'must-hotel-booking'),
                'value' => (string) $counts[HousekeepingRepository::STATUS_OUT_OF_ORDER],
                'descriptor' => \__('Blocked by maintenance or inventory room state.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $room
     * @param array<int, array<string, mixed>> $roomTypeMap
     * @param array<int, array<string, mixed>> $reservationSummaryMap
     * @param array<int, array<string, mixed>> $housekeepingStatusMap
     * @param array<int, array<string, int>> $issueCountsMap
     * @param array<int, string> $assigneeMap
     * @return array<string, mixed>|null
     */
    private function buildRoomBoardRow(
        array $room,
        array $roomTypeMap,
        array $reservationSummaryMap,
        array $housekeepingStatusMap,
        array $issueCountsMap,
        array $assigneeMap
    ): ?array {
        $roomId = isset($room['id']) ? (int) $room['id'] : 0;

        if ($roomId <= 0) {
            return null;
        }

        $roomTypeId = isset($room['room_type_id']) ? (int) $room['room_type_id'] : 0;
        $roomType = isset($roomTypeMap[$roomTypeId]) && \is_array($roomTypeMap[$roomTypeId]) ? $roomTypeMap[$roomTypeId] : [];
        $reservationSummary = isset($reservationSummaryMap[$roomId]) && \is_array($reservationSummaryMap[$roomId]) ? $reservationSummaryMap[$roomId] : [];
        $statusRow = isset($housekeepingStatusMap[$roomId]) && \is_array($housekeepingStatusMap[$roomId]) ? $housekeepingStatusMap[$roomId] : [];
        $issueCounts = isset($issueCountsMap[$roomId]) && \is_array($issueCountsMap[$roomId]) ? $issueCountsMap[$roomId] : [];
        $inventoryStatusKey = \sanitize_key((string) ($room['status'] ?? 'available'));
        $statusData = $this->resolveHousekeepingStatusData($inventoryStatusKey, $reservationSummary, $statusRow);
        $context = $this->buildReservationContext($reservationSummary);
        $roomNumber = \trim((string) ($room['room_number'] ?? ''));
        $roomTitle = \trim((string) ($room['title'] ?? ''));
        $assignedToUserId = isset($statusRow['assigned_to_user_id']) ? (int) $statusRow['assigned_to_user_id'] : 0;
        $assignedAt = isset($statusRow['assigned_at']) ? (string) $statusRow['assigned_at'] : '';
        $locationParts = [];

        if (!empty($room['building'])) {
            $locationParts[] = (string) $room['building'];
        }

        if (!empty($room['section'])) {
            $locationParts[] = (string) $room['section'];
        }

        if (isset($room['floor']) && (int) $room['floor'] > 0) {
            $locationParts[] = \sprintf(
                /* translators: %d: floor number */
                \__('Floor %d', 'must-hotel-booking'),
                (int) $room['floor']
            );
        }

        return [
            'id' => $roomId,
            'room_number' => $roomNumber,
            'room_label' => $roomNumber !== '' ? $roomNumber : ($roomTitle !== '' ? $roomTitle : ('#' . $roomId)),
            'room_title' => $roomTitle,
            'room_meta' => \implode(' | ', $locationParts),
            'room_type' => isset($roomType['name']) ? (string) $roomType['name'] : '',
            'inventory_status_key' => $inventoryStatusKey !== '' ? $inventoryStatusKey : 'available',
            'inventory_status_label' => $this->formatInventoryStatusLabel($inventoryStatusKey),
            'housekeeping_status_key' => (string) $statusData['status'],
            'housekeeping_status_label' => (string) $statusData['label'],
            'status_note' => (string) $statusData['note'],
            'has_saved_status' => !empty($statusData['has_saved_status']),
            'current_reservations' => isset($reservationSummary['current_reservations']) ? (int) $reservationSummary['current_reservations'] : 0,
            'future_reservations' => isset($reservationSummary['future_reservations']) ? (int) $reservationSummary['future_reservations'] : 0,
            'reservation_context' => (string) $context['label'],
            'reservation_context_meta' => (string) $context['meta'],
            'assigned_to_user_id' => $assignedToUserId,
            'assigned_to_label' => isset($assigneeMap[$assignedToUserId]) ? (string) $assigneeMap[$assignedToUserId] : '',
            'assigned_at_label' => $assignedAt !== '' ? $this->formatDateTime($assignedAt) : '',
            'unresolved_issue_count' => isset($issueCounts['unresolved_count']) ? (int) $issueCounts['unresolved_count'] : 0,
            'resolved_issue_count' => isset($issueCounts['resolved_count']) ? (int) $issueCounts['resolved_count'] : 0,
            'can_mark_dirty' => (bool) $statusData['can_mark_dirty'],
            'can_mark_clean' => (bool) $statusData['can_mark_clean'],
            'can_mark_inspected' => (bool) $statusData['can_mark_inspected'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRoomTypeMap(): array
    {
        $map = [];

        foreach ($this->inventoryRepository->getRoomTypes() as $roomType) {
            if (!\is_array($roomType)) {
                continue;
            }

            $roomTypeId = isset($roomType['id']) ? (int) $roomType['id'] : 0;

            if ($roomTypeId <= 0) {
                continue;
            }

            $map[$roomTypeId] = $roomType;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $reservationSummary
     * @param array<string, mixed> $statusRow
     * @return array<string, mixed>
     */
    private function resolveHousekeepingStatusData(string $inventoryStatusKey, array $reservationSummary, array $statusRow): array
    {
        $statusLabels = HousekeepingRepository::getStatusLabels();
        $savedStatus = isset($statusRow['status']) ? HousekeepingRepository::normalizeStatus((string) $statusRow['status']) : '';
        $currentReservations = isset($reservationSummary['current_reservations']) ? (int) $reservationSummary['current_reservations'] : 0;
        $updatedAt = isset($statusRow['updated_at']) ? \trim((string) $statusRow['updated_at']) : '';
        $hasInventoryOverride = \in_array($inventoryStatusKey, ['maintenance', 'out_of_service', 'blocked'], true);
        $status = HousekeepingRepository::STATUS_DIRTY;
        $note = \__('Defaulted to dirty until housekeeping updates it.', 'must-hotel-booking');
        $hasSavedStatus = false;

        if ($hasInventoryOverride) {
            $status = HousekeepingRepository::STATUS_OUT_OF_ORDER;
            $note = \sprintf(
                /* translators: %s: inventory room status */
                \__('Inventory status: %s', 'must-hotel-booking'),
                $this->formatInventoryStatusLabel($inventoryStatusKey)
            );
        } elseif ($savedStatus === HousekeepingRepository::STATUS_OUT_OF_ORDER) {
            $status = HousekeepingRepository::STATUS_OUT_OF_ORDER;
            $hasSavedStatus = true;
            $note = $updatedAt !== ''
                ? \sprintf(
                    /* translators: %s: last updated time */
                    \__('Updated %s', 'must-hotel-booking'),
                    $this->formatDateTime($updatedAt)
                )
                : \__('Saved as out of order.', 'must-hotel-booking');
        } elseif ($savedStatus !== '') {
            $status = $savedStatus;
            $hasSavedStatus = true;

            if ($updatedAt !== '') {
                $note = \sprintf(
                    /* translators: %s: last updated time */
                    \__('Updated %s', 'must-hotel-booking'),
                    $this->formatDateTime($updatedAt)
                );
            } else {
                $note = \__('Saved housekeeping status.', 'must-hotel-booking');
            }
        } elseif ($currentReservations > 0) {
            $status = HousekeepingRepository::STATUS_CLEAN;
            $note = \__('Defaulted from current occupied stay until housekeeping updates it.', 'must-hotel-booking');
        }

        return [
            'status' => $status,
            'label' => isset($statusLabels[$status]) ? (string) $statusLabels[$status] : \ucwords(\str_replace('_', ' ', $status)),
            'note' => $note,
            'has_saved_status' => $hasSavedStatus,
            'can_mark_dirty' => $status !== HousekeepingRepository::STATUS_OUT_OF_ORDER && $status !== HousekeepingRepository::STATUS_DIRTY,
            'can_mark_clean' => $status !== HousekeepingRepository::STATUS_OUT_OF_ORDER && $status !== HousekeepingRepository::STATUS_CLEAN,
            'can_mark_inspected' => $status === HousekeepingRepository::STATUS_CLEAN && $currentReservations === 0,
        ];
    }

    /**
     * @param array<string, mixed> $reservationSummary
     * @return array<string, string>
     */
    private function buildReservationContext(array $reservationSummary): array
    {
        $currentReservations = isset($reservationSummary['current_reservations']) ? (int) $reservationSummary['current_reservations'] : 0;
        $futureReservations = isset($reservationSummary['future_reservations']) ? (int) $reservationSummary['future_reservations'] : 0;
        $nextCheckin = isset($reservationSummary['next_checkin']) ? (string) $reservationSummary['next_checkin'] : '';

        if ($currentReservations > 0) {
            return [
                'label' => \__('Occupied now', 'must-hotel-booking'),
                'meta' => $futureReservations > 0 && $nextCheckin !== ''
                    ? \sprintf(
                        /* translators: %s: next check-in date */
                        \__('Next arrival %s', 'must-hotel-booking'),
                        $this->formatDate($nextCheckin)
                    )
                    : \__('Assigned to an in-house stay.', 'must-hotel-booking'),
            ];
        }

        if ($futureReservations > 0) {
            return [
                'label' => $nextCheckin !== ''
                    ? \sprintf(
                        /* translators: %s: next check-in date */
                        \__('Next arrival %s', 'must-hotel-booking'),
                        $this->formatDate($nextCheckin)
                    )
                    : \__('Upcoming assigned stay', 'must-hotel-booking'),
                'meta' => \sprintf(
                    /* translators: %d: number of future assigned reservations */
                    \_n('%d future assigned stay', '%d future assigned stays', $futureReservations, 'must-hotel-booking'),
                    $futureReservations
                ),
            ];
        }

        return [
            'label' => \__('No assigned stay', 'must-hotel-booking'),
            'meta' => \__('No current or upcoming assigned reservation is attached to this unit.', 'must-hotel-booking'),
        ];
    }

    private function formatInventoryStatusLabel(string $status): string
    {
        $status = \sanitize_key($status);
        $labels = [
            'available' => \__('Available', 'must-hotel-booking'),
            'maintenance' => \__('Maintenance', 'must-hotel-booking'),
            'out_of_service' => \__('Out of Service', 'must-hotel-booking'),
            'blocked' => \__('Blocked', 'must-hotel-booking'),
        ];

        if (isset($labels[$status])) {
            return $labels[$status];
        }

        if ($status === '') {
            return \__('Available', 'must-hotel-booking');
        }

        return \ucwords(\str_replace('_', ' ', $status));
    }

    /**
     * @param array<int, array<string, mixed>> $statusMap
     * @return array<int, string>
     */
    private function buildAssigneeLabelMap(array $statusMap): array
    {
        $userIds = [];

        foreach ($statusMap as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $userId = isset($row['assigned_to_user_id']) ? (int) $row['assigned_to_user_id'] : 0;

            if ($userId > 0) {
                $userIds[$userId] = $userId;
            }
        }

        if (empty($userIds)) {
            return [];
        }

        $users = \get_users(
            [
                'include' => \array_values($userIds),
                'fields' => 'all',
            ]
        );
        $map = [];

        foreach ($users as $user) {
            if (!$user instanceof \WP_User || $user->ID <= 0) {
                continue;
            }

            $map[(int) $user->ID] = $user->display_name !== '' ? (string) $user->display_name : (string) $user->user_login;
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $boardRows
     * @return array<int, array<string, mixed>>
     */
    private function buildAssignmentRows(array $boardRows): array
    {
        \usort(
            $boardRows,
            static function (array $left, array $right): int {
                $leftAssigned = !empty($left['assigned_to_user_id']) ? 0 : 1;
                $rightAssigned = !empty($right['assigned_to_user_id']) ? 0 : 1;

                if ($leftAssigned !== $rightAssigned) {
                    return $leftAssigned <=> $rightAssigned;
                }

                return \strcmp((string) ($left['room_label'] ?? ''), (string) ($right['room_label'] ?? ''));
            }
        );

        return $boardRows;
    }

    /**
     * @param array<int, array<string, mixed>> $boardRows
     * @return array<int, array<string, mixed>>
     */
    private function buildIssueRows(array $boardRows, bool $includeResolved): array
    {
        $roomMap = [];
        $roomIds = [];

        foreach ($boardRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $roomIds[] = $roomId;
            $roomMap[$roomId] = $row;
        }

        $issueStatusLabels = HousekeepingRepository::getIssueStatusLabels();
        $issueRows = [];

        foreach ($this->housekeepingRepository->getIssues($roomIds, $includeResolved, 120) as $issueRow) {
            if (!\is_array($issueRow)) {
                continue;
            }

            $roomId = isset($issueRow['inventory_room_id']) ? (int) $issueRow['inventory_room_id'] : 0;
            $room = isset($roomMap[$roomId]) && \is_array($roomMap[$roomId]) ? $roomMap[$roomId] : [];
            $statusKey = HousekeepingRepository::normalizeIssueStatus((string) ($issueRow['status'] ?? ''));

            $issueRows[] = [
                'id' => isset($issueRow['id']) ? (int) $issueRow['id'] : 0,
                'room_id' => $roomId,
                'room_label' => (string) ($room['room_label'] ?? ('#' . $roomId)),
                'room_type' => (string) ($room['room_type'] ?? ''),
                'issue_title' => (string) ($issueRow['issue_title'] ?? ''),
                'issue_details' => (string) ($issueRow['issue_details'] ?? ''),
                'status_key' => $statusKey,
                'status_label' => isset($issueStatusLabels[$statusKey]) ? (string) $issueStatusLabels[$statusKey] : \ucwords(\str_replace('_', ' ', $statusKey)),
                'created_at' => $this->formatDateTime((string) ($issueRow['created_at'] ?? '')),
                'updated_at' => $this->formatDateTime((string) ($issueRow['updated_at'] ?? '')),
                'created_by_label' => $this->getUserDisplayLabel(isset($issueRow['created_by']) ? (int) $issueRow['created_by'] : 0),
                'updated_by_label' => $this->getUserDisplayLabel(isset($issueRow['updated_by']) ? (int) $issueRow['updated_by'] : 0),
            ];
        }

        return $issueRows;
    }

    /**
     * @param array<int, array<string, mixed>> $issueRows
     * @return array<int, array<string, string>>
     */
    private function buildMaintenanceSummaryCards(array $issueRows): array
    {
        $counts = [
            HousekeepingRepository::ISSUE_STATUS_OPEN => 0,
            HousekeepingRepository::ISSUE_STATUS_IN_PROGRESS => 0,
            HousekeepingRepository::ISSUE_STATUS_RESOLVED => 0,
        ];
        $roomIds = [];

        foreach ($issueRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $statusKey = \sanitize_key((string) ($row['status_key'] ?? ''));

            if (isset($counts[$statusKey])) {
                $counts[$statusKey]++;
            }

            $roomId = isset($row['room_id']) ? (int) $row['room_id'] : 0;

            if ($roomId > 0) {
                $roomIds[$roomId] = $roomId;
            }
        }

        return [
            [
                'label' => \__('Open issues', 'must-hotel-booking'),
                'value' => (string) $counts[HousekeepingRepository::ISSUE_STATUS_OPEN],
                'descriptor' => \__('New issues that still need a maintenance response.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('In progress', 'must-hotel-booking'),
                'value' => (string) $counts[HousekeepingRepository::ISSUE_STATUS_IN_PROGRESS],
                'descriptor' => \__('Issues actively being worked on.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Resolved', 'must-hotel-booking'),
                'value' => (string) $counts[HousekeepingRepository::ISSUE_STATUS_RESOLVED],
                'descriptor' => \__('Resolved issues still visible for shift continuity.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Affected rooms', 'must-hotel-booking'),
                'value' => (string) \count($roomIds),
                'descriptor' => \__('Rooms currently carrying at least one maintenance issue.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $boardRows
     * @param array<int, array<string, mixed>> $issueRows
     * @return array<string, int>
     */
    private function buildHandoffSnapshot(array $boardRows, array $issueRows): array
    {
        $snapshot = [
            'dirty_count' => 0,
            'clean_count' => 0,
            'inspected_count' => 0,
            'out_of_order_count' => 0,
            'assigned_count' => 0,
            'open_issue_count' => 0,
        ];

        foreach ($boardRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $statusKey = \sanitize_key((string) ($row['housekeeping_status_key'] ?? ''));

            if ($statusKey === HousekeepingRepository::STATUS_DIRTY) {
                $snapshot['dirty_count']++;
            } elseif ($statusKey === HousekeepingRepository::STATUS_CLEAN) {
                $snapshot['clean_count']++;
            } elseif ($statusKey === HousekeepingRepository::STATUS_INSPECTED) {
                $snapshot['inspected_count']++;
            } elseif ($statusKey === HousekeepingRepository::STATUS_OUT_OF_ORDER) {
                $snapshot['out_of_order_count']++;
            }

            if (!empty($row['assigned_to_user_id'])) {
                $snapshot['assigned_count']++;
            }
        }

        foreach ($issueRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ((string) ($row['status_key'] ?? '') !== HousekeepingRepository::ISSUE_STATUS_RESOLVED) {
                $snapshot['open_issue_count']++;
            }
        }

        return $snapshot;
    }

    /**
     * @param array<string, int> $snapshot
     * @return array<int, array<string, string>>
     */
    private function buildHandoffSummaryCards(array $snapshot): array
    {
        return [
            [
                'label' => \__('Dirty', 'must-hotel-booking'),
                'value' => (string) ($snapshot['dirty_count'] ?? 0),
                'descriptor' => \__('Rooms still needing cleaning before the next shift starts.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Clean', 'must-hotel-booking'),
                'value' => (string) ($snapshot['clean_count'] ?? 0),
                'descriptor' => \__('Rooms cleaned but not yet fully handed off.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Inspected', 'must-hotel-booking'),
                'value' => (string) ($snapshot['inspected_count'] ?? 0),
                'descriptor' => \__('Rooms fully ready after inspection.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Assigned rooms', 'must-hotel-booking'),
                'value' => (string) ($snapshot['assigned_count'] ?? 0),
                'descriptor' => \__('Rooms currently assigned to named housekeeping staff.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Open issues', 'must-hotel-booking'),
                'value' => (string) ($snapshot['open_issue_count'] ?? 0),
                'descriptor' => \__('Unresolved maintenance issues that need handoff visibility.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildHandoffRows(): array
    {
        $rows = [];

        foreach ($this->housekeepingRepository->getRecentHandoffs(20) as $handoffRow) {
            if (!\is_array($handoffRow)) {
                continue;
            }

            $rows[] = [
                'id' => isset($handoffRow['id']) ? (int) $handoffRow['id'] : 0,
                'shift_label' => (string) ($handoffRow['shift_label'] ?? ''),
                'notes' => (string) ($handoffRow['notes'] ?? ''),
                'dirty_count' => isset($handoffRow['dirty_count']) ? (int) $handoffRow['dirty_count'] : 0,
                'clean_count' => isset($handoffRow['clean_count']) ? (int) $handoffRow['clean_count'] : 0,
                'inspected_count' => isset($handoffRow['inspected_count']) ? (int) $handoffRow['inspected_count'] : 0,
                'out_of_order_count' => isset($handoffRow['out_of_order_count']) ? (int) $handoffRow['out_of_order_count'] : 0,
                'assigned_count' => isset($handoffRow['assigned_count']) ? (int) $handoffRow['assigned_count'] : 0,
                'open_issue_count' => isset($handoffRow['open_issue_count']) ? (int) $handoffRow['open_issue_count'] : 0,
                'created_at' => $this->formatDateTime((string) ($handoffRow['created_at'] ?? '')),
                'created_by_label' => $this->getUserDisplayLabel(isset($handoffRow['created_by']) ? (int) $handoffRow['created_by'] : 0),
            ];
        }

        return $rows;
    }

    private function getUserDisplayLabel(int $userId): string
    {
        if ($userId <= 0) {
            return \__('System', 'must-hotel-booking');
        }

        $user = \get_userdata($userId);

        if (!$user instanceof \WP_User) {
            return \__('Unknown', 'must-hotel-booking');
        }

        return $user->display_name !== '' ? (string) $user->display_name : (string) $user->user_login;
    }

    private function formatDate(string $date): string
    {
        $timestamp = \strtotime($date);

        if ($timestamp === false) {
            return $date;
        }

        return \date_i18n((string) \get_option('date_format'), $timestamp);
    }

    private function formatDateTime(string $dateTime): string
    {
        $timestamp = \strtotime($dateTime);

        if ($timestamp === false) {
            return $dateTime;
        }

        return \date_i18n(
            (string) \get_option('date_format') . ' ' . (string) \get_option('time_format'),
            $timestamp
        );
    }
}
