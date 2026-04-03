<?php

namespace MustHotelBooking\Admin;

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
        $boardRows = [];
        $summaryCards = [];
        $today = \current_time('Y-m-d');

        if ($activeTab === 'room-board') {
            $boardRows = $this->buildRoomBoardRows($today);
            $summaryCards = $this->buildSummaryCards($boardRows);
        }

        return [
            'active_tab' => $activeTab,
            'today' => $today,
            'board_rows' => $boardRows,
            'summary_cards' => $summaryCards,
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
        $boardRows = [];

        foreach ($rows as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $boardRow = $this->buildRoomBoardRow($room, $roomTypeMap, $reservationSummaryMap, $housekeepingStatusMap);

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
     * @return array<string, mixed>|null
     */
    private function buildRoomBoardRow(
        array $room,
        array $roomTypeMap,
        array $reservationSummaryMap,
        array $housekeepingStatusMap
    ): ?array {
        $roomId = isset($room['id']) ? (int) $room['id'] : 0;

        if ($roomId <= 0) {
            return null;
        }

        $roomTypeId = isset($room['room_type_id']) ? (int) $room['room_type_id'] : 0;
        $roomType = isset($roomTypeMap[$roomTypeId]) && \is_array($roomTypeMap[$roomTypeId]) ? $roomTypeMap[$roomTypeId] : [];
        $reservationSummary = isset($reservationSummaryMap[$roomId]) && \is_array($reservationSummaryMap[$roomId]) ? $reservationSummaryMap[$roomId] : [];
        $statusRow = isset($housekeepingStatusMap[$roomId]) && \is_array($housekeepingStatusMap[$roomId]) ? $housekeepingStatusMap[$roomId] : [];
        $inventoryStatusKey = \sanitize_key((string) ($room['status'] ?? 'available'));
        $statusData = $this->resolveHousekeepingStatusData($inventoryStatusKey, $reservationSummary, $statusRow);
        $context = $this->buildReservationContext($reservationSummary);
        $roomNumber = \trim((string) ($room['room_number'] ?? ''));
        $roomTitle = \trim((string) ($room['title'] ?? ''));
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
