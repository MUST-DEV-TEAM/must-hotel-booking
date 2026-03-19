<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Engine\LockEngine;

final class CalendarDataProvider
{
    /** @var \MustHotelBooking\Database\RoomRepository */
    private $roomRepository;

    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservationRepository;

    /** @var \MustHotelBooking\Database\AvailabilityRepository */
    private $availabilityRepository;

    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventoryRepository;

    public function __construct()
    {
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(CalendarViewQuery $query): array
    {
        $rooms = $this->getFilteredRooms($query);
        $roomIds = $this->extractRoomIds($rooms);
        $range = [
            'month' => $query->getMonth(),
            'label' => $query->getRangeLabel(),
            'start' => $query->getStartDate(),
            'end' => $query->getEndDate(),
            'end_exclusive' => $query->getEndDateExclusive(),
            'dates' => $query->getDates(),
            'today' => \current_time('Y-m-d'),
            'days' => $query->getRangeDayCount(),
            'weeks' => $query->getWeeks(),
            'previous_args' => $query->getPreviousRangeArgs(),
            'next_args' => $query->getNextRangeArgs(),
        ];
        $source = $this->loadSourceData($roomIds, $range['start'], $range['end_exclusive']);
        $indexes = $this->buildIndexes($source, $range['start'], $range['end_exclusive']);
        $rows = $this->buildCalendarRows($rooms, (array) $range['dates'], $indexes, $query);

        return [
            'range' => $range,
            'filters' => [
                'month' => $query->getMonth(),
                'start_date' => $query->getStartDate(),
                'end_date' => $query->getEndDate(),
                'weeks' => $query->getWeeks(),
                'category' => $query->getCategory(),
                'room_id' => $query->getRoomId(),
                'visibility' => $query->getVisibility(),
                'week_options' => CalendarViewQuery::getWeekOptions(),
                'category_options' => $this->getCategoryOptions(),
                'room_options' => $this->buildRoomOptions($query),
                'visibility_options' => CalendarViewQuery::getVisibilityOptions(),
            ],
            'summary' => $this->buildTodaySummary($rooms),
            'legend' => $this->getLegendRows(),
            'rows' => $rows,
            'selected' => $this->buildSelectedPanelData($rooms, $indexes, $query, $range),
        ];
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<string, mixed>
     */
    private function loadSourceData(array $roomIds, string $startDate, string $endExclusive): array
    {
        return [
            'reservations' => $this->reservationRepository->getCalendarReservationRows($startDate, $endExclusive, $roomIds),
            'restrictions' => $this->availabilityRepository->getCalendarRestrictionRows($roomIds, $startDate, $endExclusive),
            'locks' => $this->inventoryRepository->getCalendarLockRows($roomIds, $startDate, $endExclusive, LockEngine::getCurrentUtcDatetime()),
            'inventory' => $this->inventoryRepository->getRoomTypeInventorySummaries($roomIds),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function buildIndexes(array $source, string $startDate, string $endExclusive): array
    {
        $inventoryMap = [];

        foreach ((array) ($source['inventory'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomTypeId = isset($row['room_type_id']) ? (int) $row['room_type_id'] : 0;

            if ($roomTypeId <= 0) {
                continue;
            }

            $inventoryMap[$roomTypeId] = [
                'total_units' => isset($row['total_units']) ? \max(1, (int) $row['total_units']) : 1,
                'unavailable_units' => isset($row['unavailable_units']) ? \max(0, (int) $row['unavailable_units']) : 0,
            ];
        }

        $indexes = [
            'inventory' => $inventoryMap,
            'stays' => [],
            'arrivals' => [],
            'departures' => [],
            'restrictions' => [],
            'locks' => [],
            'reservations_by_id' => [],
        ];

        foreach ((array) ($source['reservations'] ?? []) as $reservation) {
            if (!\is_array($reservation)) {
                continue;
            }

            $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
            $roomId = isset($reservation['room_id']) ? (int) $reservation['room_id'] : 0;

            if ($reservationId <= 0 || $roomId <= 0) {
                continue;
            }

            $indexes['reservations_by_id'][$reservationId] = $reservation;
            $checkin = isset($reservation['checkin']) ? (string) $reservation['checkin'] : '';
            $checkout = isset($reservation['checkout']) ? (string) $reservation['checkout'] : '';

            foreach ($this->iterateDateRange($this->maxDate($checkin, $startDate), $this->minDate($checkout, $endExclusive)) as $date) {
                $indexes['stays'][$roomId][$date][] = $reservation;
            }

            if ($checkin >= $startDate && $checkin < $endExclusive) {
                $indexes['arrivals'][$roomId][$checkin][] = $reservation;
            }

            if ($checkout >= $startDate && $checkout < $endExclusive) {
                $indexes['departures'][$roomId][$checkout][] = $reservation;
            }
        }

        foreach ((array) ($source['restrictions'] ?? []) as $restriction) {
            if (!\is_array($restriction)) {
                continue;
            }

            $roomId = isset($restriction['room_id']) ? (int) $restriction['room_id'] : 0;
            $restrictionStart = isset($restriction['availability_date']) ? (string) $restriction['availability_date'] : '';
            $restrictionEnd = isset($restriction['end_date']) && (string) $restriction['end_date'] !== ''
                ? (string) $restriction['end_date']
                : $restrictionStart;

            if ($restrictionStart === '' || $restrictionEnd === '') {
                continue;
            }

            $visibleStart = $this->maxDate($restrictionStart, $startDate);
            $visibleEndExclusive = $this->minDate(
                (new \DateTimeImmutable($restrictionEnd))->modify('+1 day')->format('Y-m-d'),
                $endExclusive
            );

            foreach ($this->iterateDateRange($visibleStart, $visibleEndExclusive) as $date) {
                $indexes['restrictions'][$roomId][$date][] = $restriction;
            }
        }

        foreach ((array) ($source['locks'] ?? []) as $lock) {
            if (!\is_array($lock)) {
                continue;
            }

            $roomTypeId = isset($lock['room_type_id']) ? (int) $lock['room_type_id'] : 0;
            $checkin = isset($lock['checkin']) ? (string) $lock['checkin'] : '';
            $checkout = isset($lock['checkout']) ? (string) $lock['checkout'] : '';

            if ($roomTypeId <= 0 || $checkin === '' || $checkout === '') {
                continue;
            }

            foreach ($this->iterateDateRange($this->maxDate($checkin, $startDate), $this->minDate($checkout, $endExclusive)) as $date) {
                $indexes['locks'][$roomTypeId][$date][] = $lock;
            }
        }

        return $indexes;
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @param array<int, string> $dates
     * @param array<string, mixed> $indexes
     * @return array<int, array<string, mixed>>
     */
    private function buildCalendarRows(array $rooms, array $dates, array $indexes, CalendarViewQuery $query): array
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

            $inventory = isset($indexes['inventory'][$roomId]) && \is_array($indexes['inventory'][$roomId])
                ? $indexes['inventory'][$roomId]
                : [
                    'total_units' => 1,
                    'unavailable_units' => 0,
                ];
            $monthTotals = [
                'booked' => 0,
                'pending' => 0,
                'blocked' => 0,
                'unavailable' => 0,
            ];
            $cells = [];

            foreach ($dates as $date) {
                $cell = $this->buildCellData($room, $date, $indexes, $inventory, $query);
                $cells[] = $cell;

                if (($cell['state'] ?? '') === 'booked' || ($cell['state'] ?? '') === 'partial') {
                    $monthTotals['booked']++;
                }

                if (($cell['counts']['pending'] ?? 0) > 0) {
                    $monthTotals['pending']++;
                }

                if (($cell['counts']['blocked'] ?? 0) > 0 || !empty($cell['flags']['maintenance_block'])) {
                    $monthTotals['blocked']++;
                }

                if (($cell['state'] ?? '') === 'unavailable' || ($cell['state'] ?? '') === 'hold') {
                    $monthTotals['unavailable']++;
                }
            }

            $rows[] = [
                'id' => $roomId,
                'name' => (string) ($room['name'] ?? ''),
                'category' => (string) ($room['category'] ?? ''),
                'category_label' => (string) ($room['category_label'] ?? ''),
                'max_guests' => isset($room['max_guests']) ? (int) $room['max_guests'] : 1,
                'inventory' => $inventory,
                'month_totals' => $monthTotals,
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $indexes
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>
     */
    private function buildCellData(array $room, string $date, array $indexes, array $inventory, CalendarViewQuery $query): array
    {
        $roomId = isset($room['id']) ? (int) $room['id'] : 0;
        $stays = isset($indexes['stays'][$roomId][$date]) && \is_array($indexes['stays'][$roomId][$date]) ? $indexes['stays'][$roomId][$date] : [];
        $arrivals = isset($indexes['arrivals'][$roomId][$date]) && \is_array($indexes['arrivals'][$roomId][$date]) ? $indexes['arrivals'][$roomId][$date] : [];
        $departures = isset($indexes['departures'][$roomId][$date]) && \is_array($indexes['departures'][$roomId][$date]) ? $indexes['departures'][$roomId][$date] : [];
        $roomRestrictions = isset($indexes['restrictions'][$roomId][$date]) && \is_array($indexes['restrictions'][$roomId][$date]) ? $indexes['restrictions'][$roomId][$date] : [];
        $globalRestrictions = isset($indexes['restrictions'][0][$date]) && \is_array($indexes['restrictions'][0][$date]) ? $indexes['restrictions'][0][$date] : [];
        $restrictions = \array_merge($globalRestrictions, $roomRestrictions);
        $locks = isset($indexes['locks'][$roomId][$date]) && \is_array($indexes['locks'][$roomId][$date]) ? $indexes['locks'][$roomId][$date] : [];
        $counts = [
            'booked' => 0,
            'pending' => 0,
            'blocked' => 0,
            'hold' => \count($locks),
            'cancelled' => 0,
        ];

        foreach ($stays as $reservation) {
            $bucket = $this->getReservationBucket((string) ($reservation['status'] ?? ''));

            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
        }

        $flags = [
            'maintenance_block' => false,
            'closed_arrival' => false,
            'closed_departure' => false,
            'inventory_unavailable' => (int) ($inventory['unavailable_units'] ?? 0) >= (int) ($inventory['total_units'] ?? 1),
        ];

        foreach ($restrictions as $restriction) {
            if (!\is_array($restriction)) {
                continue;
            }

            $ruleType = \sanitize_key((string) ($restriction['rule_type'] ?? ''));

            if ($ruleType === 'maintenance_block') {
                $flags['maintenance_block'] = true;
            }

            if ($ruleType === 'closed_arrival') {
                $flags['closed_arrival'] = true;
            }

            if ($ruleType === 'closed_departure') {
                $flags['closed_departure'] = true;
            }
        }

        $totalUnits = \max(1, (int) ($inventory['total_units'] ?? 1));
        $unavailableUnits = $flags['inventory_unavailable'] ? $totalUnits : \max(0, (int) ($inventory['unavailable_units'] ?? 0));
        $availableUnits = $flags['maintenance_block']
            ? 0
            : \max(0, $totalUnits - ($counts['booked'] + $counts['pending'] + $counts['blocked'] + $counts['hold'] + $unavailableUnits));
        $actualState = 'available';

        if ($flags['maintenance_block'] || $counts['blocked'] > 0) {
            $actualState = 'blocked';
        } elseif ($counts['booked'] > 0 && $availableUnits <= 0) {
            $actualState = 'booked';
        } elseif ($counts['booked'] > 0) {
            $actualState = 'partial';
        } elseif ($counts['pending'] > 0 && $availableUnits <= 0) {
            $actualState = 'pending';
        } elseif ($flags['inventory_unavailable']) {
            $actualState = 'unavailable';
        } elseif ($counts['hold'] > 0 && $availableUnits <= 0) {
            $actualState = 'hold';
        } elseif ($flags['closed_arrival'] || $flags['closed_departure']) {
            $actualState = 'unavailable';
        }

        $state = $this->resolveVisibleCellState($query, $actualState, $counts, $flags, $availableUnits);

        $indicators = [];

        if ($query->isVisible('booked') && $counts['booked'] > 0) {
            $indicators[] = \sprintf(
                /* translators: %d is booked count. */
                \_n('%d booked', '%d booked', $counts['booked'], 'must-hotel-booking'),
                $counts['booked']
            );
        }

        if ($query->isVisible('pending') && $counts['pending'] > 0) {
            $indicators[] = \sprintf(
                /* translators: %d is pending count. */
                \_n('%d pending', '%d pending', $counts['pending'], 'must-hotel-booking'),
                $counts['pending']
            );
        }

        if ($query->isVisible('blocked') && ($counts['blocked'] > 0 || $flags['maintenance_block'])) {
            $indicators[] = $flags['maintenance_block']
                ? \__('Maintenance', 'must-hotel-booking')
                : \__('Blocked', 'must-hotel-booking');
        }

        if ($query->isVisible('hold') && $counts['hold'] > 0) {
            $indicators[] = \sprintf(
                /* translators: %d is temporary hold count. */
                \_n('%d hold', '%d holds', $counts['hold'], 'must-hotel-booking'),
                $counts['hold']
            );
        }

        if ($query->isVisible('unavailable') && ($flags['inventory_unavailable'] || $flags['closed_arrival'] || $flags['closed_departure'])) {
            $indicators[] = $flags['inventory_unavailable']
                ? \__('Unavailable', 'must-hotel-booking')
                : \__('Rule', 'must-hotel-booking');
        }

        if ($query->isVisible('cancelled') && $counts['cancelled'] > 0) {
            $indicators[] = \sprintf(
                /* translators: %d is cancelled count. */
                \_n('%d cancelled', '%d cancelled', $counts['cancelled'], 'must-hotel-booking'),
                $counts['cancelled']
            );
        }

        return [
            'date' => $date,
            'state' => $state,
            'actual_state' => $actualState,
            'hidden_state' => $state === 'filtered',
            'today' => $date === \current_time('Y-m-d'),
            'selected' => $query->getFocusRoomId() === $roomId && $query->getFocusDate() === $date,
            'headline' => $this->buildCellHeadline($state, $availableUnits, $totalUnits, $counts),
            'indicators' => \array_slice($indicators, 0, 2),
            'available_units' => $availableUnits,
            'counts' => $counts,
            'flags' => $flags,
            'arrivals_count' => \count($arrivals),
            'departures_count' => \count($departures),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @param array<string, mixed> $indexes
     * @return array<string, mixed>
     */
    private function buildSelectedPanelData(array $rooms, array $indexes, CalendarViewQuery $query, array $range): array
    {
        $focusRoomId = $query->getFocusRoomId();
        $focusDate = $query->getFocusDate();
        $reservationId = $query->getReservationId();

        if ($reservationId > 0 && isset($indexes['reservations_by_id'][$reservationId]) && \is_array($indexes['reservations_by_id'][$reservationId])) {
            $reservation = $indexes['reservations_by_id'][$reservationId];

            if ($focusRoomId <= 0) {
                $focusRoomId = isset($reservation['room_id']) ? (int) $reservation['room_id'] : 0;
            }

            if ($focusDate === '') {
                $focusDate = $this->maxDate((string) ($reservation['checkin'] ?? ''), (string) ($range['start'] ?? ''));
            }
        }

        $room = $this->findRoomById($rooms, $focusRoomId);

        if (!\is_array($room) || $focusDate === '') {
            return [
                'room' => null,
                'date' => '',
                'label' => '',
                'summary' => null,
                'stays' => [],
                'arrivals' => [],
                'departures' => [],
                'rules' => [],
                'locks' => [],
                'actions' => [],
            ];
        }

        $roomId = (int) ($room['id'] ?? 0);
        $stays = isset($indexes['stays'][$roomId][$focusDate]) && \is_array($indexes['stays'][$roomId][$focusDate]) ? $indexes['stays'][$roomId][$focusDate] : [];
        $arrivals = isset($indexes['arrivals'][$roomId][$focusDate]) && \is_array($indexes['arrivals'][$roomId][$focusDate]) ? $indexes['arrivals'][$roomId][$focusDate] : [];
        $departures = isset($indexes['departures'][$roomId][$focusDate]) && \is_array($indexes['departures'][$roomId][$focusDate]) ? $indexes['departures'][$roomId][$focusDate] : [];
        $rules = [];

        if (isset($indexes['restrictions'][0][$focusDate]) && \is_array($indexes['restrictions'][0][$focusDate])) {
            $rules = \array_merge($rules, $indexes['restrictions'][0][$focusDate]);
        }

        if (isset($indexes['restrictions'][$roomId][$focusDate]) && \is_array($indexes['restrictions'][$roomId][$focusDate])) {
            $rules = \array_merge($rules, $indexes['restrictions'][$roomId][$focusDate]);
        }

        $locks = isset($indexes['locks'][$roomId][$focusDate]) && \is_array($indexes['locks'][$roomId][$focusDate]) ? $indexes['locks'][$roomId][$focusDate] : [];
        $inventory = isset($indexes['inventory'][$roomId]) && \is_array($indexes['inventory'][$roomId]) ? $indexes['inventory'][$roomId] : ['total_units' => 1, 'unavailable_units' => 0];
        $cell = $this->buildCellData($room, $focusDate, $indexes, $inventory, $query);
        $nextDate = (new \DateTimeImmutable($focusDate))->modify('+1 day')->format('Y-m-d');

        return [
            'room' => $room,
            'date' => $focusDate,
            'label' => \wp_date(\get_option('date_format'), \strtotime($focusDate)),
            'summary' => $cell,
            'stays' => $this->formatReservationItems(
                $stays,
                $query->buildUrlArgs(
                    [
                        'focus_room_id' => $roomId,
                        'focus_date' => $focusDate,
                    ]
                )
            ),
            'arrivals' => $this->formatReservationItems(
                $arrivals,
                $query->buildUrlArgs(
                    [
                        'focus_room_id' => $roomId,
                        'focus_date' => $focusDate,
                    ]
                )
            ),
            'departures' => $this->formatReservationItems(
                $departures,
                $query->buildUrlArgs(
                    [
                        'focus_room_id' => $roomId,
                        'focus_date' => $focusDate,
                    ]
                )
            ),
            'rules' => $this->formatRuleItems($rules),
            'locks' => $this->formatLockItems($locks),
            'actions' => [
                'room_url' => \function_exists(__NAMESPACE__ . '\get_admin_rooms_page_url')
                    ? get_admin_rooms_page_url(['tab' => 'types', 'action' => 'edit_type', 'type_id' => $roomId])
                    : '',
                'availability_rules_url' => \function_exists(__NAMESPACE__ . '\get_admin_availability_rules_page_url')
                    ? get_admin_availability_rules_page_url(
                        [
                            'room_id' => $roomId,
                            'start_date' => $focusDate,
                            'end_date' => $nextDate,
                            'mode' => 'blocked',
                        ]
                    )
                    : '',
                'reservations_url' => \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url')
                    ? get_admin_reservations_page_url(
                        [
                            'room_id' => $roomId,
                            'checkin_month' => \substr($focusDate, 0, 7),
                        ]
                    )
                    : '',
                'quick_booking_form' => [
                    'room_id' => $roomId,
                    'checkin' => $focusDate,
                    'checkout' => $nextDate,
                    'guests' => 1,
                    'guest_name' => '',
                    'phone' => '',
                    'email' => '',
                    'booking_source' => 'website',
                    'notes' => '',
                ],
                'block_form' => [
                    'room_id' => $roomId,
                    'checkin' => $focusDate,
                    'checkout' => $nextDate,
                ],
                'can_create' => (int) ($cell['available_units'] ?? 0) > 0,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<string, mixed>
     */
    private function buildTodaySummary(array $rooms): array
    {
        $roomIds = $this->extractRoomIds($rooms);
        $today = \current_time('Y-m-d');
        $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $source = $this->loadSourceData($roomIds, $today, $tomorrow);
        $indexes = $this->buildIndexes($source, $today, $tomorrow);
        $defaultQuery = CalendarViewQuery::fromRequest([]);
        $unitsShown = 0;
        $bookedToday = 0;
        $availableToday = 0;
        $blockedToday = 0;
        $pendingToday = 0;
        $holdsToday = 0;

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $inventory = isset($indexes['inventory'][$roomId]) && \is_array($indexes['inventory'][$roomId])
                ? $indexes['inventory'][$roomId]
                : ['total_units' => 1, 'unavailable_units' => 0];
            $cell = $this->buildCellData($room, $today, $indexes, $inventory, $defaultQuery);
            $unitsShown += \max(1, (int) ($inventory['total_units'] ?? 1));
            $bookedToday += (int) (($cell['counts']['booked'] ?? 0));
            $availableToday += (int) ($cell['available_units'] ?? 0);
            $pendingToday += (int) (($cell['counts']['pending'] ?? 0));
            $holdsToday += (int) (($cell['counts']['hold'] ?? 0));

            if (($cell['state'] ?? '') === 'blocked') {
                $blockedToday += \max(1, (int) ($inventory['total_units'] ?? 1));
            } elseif (($cell['state'] ?? '') === 'unavailable' || ($cell['state'] ?? '') === 'hold') {
                $blockedToday += \max(1, (int) ($inventory['unavailable_units'] ?? 1));
            }
        }

        $occupancyPercent = $unitsShown > 0
            ? (int) \round(\min(100, ($bookedToday / $unitsShown) * 100))
            : 0;

        return [
            'date' => $today,
            'accommodations_shown' => \count($rooms),
            'units_shown' => $unitsShown,
            'booked_today' => $bookedToday,
            'available_today' => $availableToday,
            'blocked_today' => $blockedToday,
            'pending_today' => $pendingToday,
            'holds_today' => $holdsToday,
            'occupancy_today' => $occupancyPercent,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getCategoryOptions(): array
    {
        return [
            RoomCatalog::BOOKING_ALL_CATEGORY => \__('All accommodation types', 'must-hotel-booking'),
        ] + RoomCatalog::getCategories();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomOptions(CalendarViewQuery $query): array
    {
        $rooms = $this->roomRepository->getRoomsForDisplay(RoomCatalog::BOOKING_ALL_CATEGORY, 500);
        $options = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $category = isset($room['category']) ? RoomCatalog::normalizeCategory((string) $room['category']) : 'standard-rooms';

            if ($roomId <= 0) {
                continue;
            }

            if (!RoomCatalog::isBookingAllCategory($query->getCategory()) && $category !== RoomCatalog::normalizeCategory($query->getCategory())) {
                continue;
            }

            $options[] = [
                'id' => $roomId,
                'name' => (string) ($room['name'] ?? ''),
                'category_label' => RoomCatalog::getCategoryLabel($category),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getLegendRows(): array
    {
        return [
            ['state' => 'booked', 'label' => \__('Booked', 'must-hotel-booking'), 'description' => \__('Confirmed or active stays blocking inventory.', 'must-hotel-booking')],
            ['state' => 'pending', 'label' => \__('Pending', 'must-hotel-booking'), 'description' => \__('Reservations awaiting confirmation or payment.', 'must-hotel-booking')],
            ['state' => 'blocked', 'label' => \__('Blocked', 'must-hotel-booking'), 'description' => \__('Manual staff blocks or maintenance windows.', 'must-hotel-booking')],
            ['state' => 'unavailable', 'label' => \__('Unavailable', 'must-hotel-booking'), 'description' => \__('Room status or arrival/departure restrictions.', 'must-hotel-booking')],
            ['state' => 'hold', 'label' => \__('Hold', 'must-hotel-booking'), 'description' => \__('Temporary inventory locks from in-progress bookings.', 'must-hotel-booking')],
            ['state' => 'available', 'label' => \__('Available', 'must-hotel-booking'), 'description' => \__('Ready for a reservation or manual block.', 'must-hotel-booking')],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function extractRoomIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $roomId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($roomId > 0) {
                $ids[] = $roomId;
            }
        }

        return $ids;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getFilteredRooms(CalendarViewQuery $query): array
    {
        $rows = $this->roomRepository->getRoomsForDisplay(RoomCatalog::BOOKING_ALL_CATEGORY, 500);
        $filtered = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $category = isset($row['category']) ? RoomCatalog::normalizeCategory((string) $row['category']) : 'standard-rooms';

            if ($roomId <= 0) {
                continue;
            }

            if (!RoomCatalog::isBookingAllCategory($query->getCategory()) && $category !== RoomCatalog::normalizeCategory($query->getCategory())) {
                continue;
            }

            if ($query->getRoomId() > 0 && $roomId !== $query->getRoomId()) {
                continue;
            }

            $row['category'] = $category;
            $row['category_label'] = RoomCatalog::getCategoryLabel($category);
            $filtered[] = $row;
        }

        \usort(
            $filtered,
            static function (array $left, array $right): int {
                $categoryComparison = \strcmp((string) ($left['category_label'] ?? ''), (string) ($right['category_label'] ?? ''));

                if ($categoryComparison !== 0) {
                    return $categoryComparison;
                }

                return \strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            }
        );

        return $filtered;
    }

    /**
     * @param array<int, array<string, mixed>> $reservations
     * @param array<string, mixed> $contextArgs
     * @return array<int, array<string, mixed>>
     */
    private function formatReservationItems(array $reservations, array $contextArgs = []): array
    {
        $items = [];

        foreach ($reservations as $reservation) {
            if (!\is_array($reservation)) {
                continue;
            }

            $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
            $status = \sanitize_key((string) ($reservation['status'] ?? ''));
            $items[] = [
                'id' => $reservationId,
                'reference' => $this->formatReservationReference($reservation),
                'guest' => $status === 'blocked'
                    ? \__('Manual block', 'must-hotel-booking')
                    : (string) ($reservation['guest_name'] ?? \__('Guest', 'must-hotel-booking')),
                'status' => $status,
                'status_label' => $this->getReservationStatusLabel($status),
                'checkin' => (string) ($reservation['checkin'] ?? ''),
                'checkout' => (string) ($reservation['checkout'] ?? ''),
                'view_url' => $reservationId > 0 && \function_exists(__NAMESPACE__ . '\get_admin_reservation_detail_page_url')
                    ? get_admin_reservation_detail_page_url($reservationId)
                    : '',
                'remove_block_url' => $status === 'blocked' && $reservationId > 0
                    ? \wp_nonce_url(
                        get_admin_calendar_page_url(
                            \array_merge(
                                $contextArgs,
                                [
                                'action' => 'delete_block',
                                'reservation_id' => $reservationId,
                                ]
                            )
                        ),
                        'must_calendar_delete_block_' . $reservationId
                    )
                    : '',
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, string>>
     */
    private function formatRuleItems(array $rules): array
    {
        $items = [];

        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $ruleType = \sanitize_key((string) ($rule['rule_type'] ?? ''));
            $items[] = [
                'type' => $ruleType,
                'label' => $this->getRuleLabel($ruleType),
                'reason' => (string) ($rule['reason'] ?? ''),
                'range' => $this->formatRuleRange(
                    (string) ($rule['availability_date'] ?? ''),
                    (string) ($rule['end_date'] ?? '')
                ),
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $locks
     * @return array<int, array<string, string>>
     */
    private function formatLockItems(array $locks): array
    {
        $items = [];

        foreach ($locks as $lock) {
            if (!\is_array($lock)) {
                continue;
            }

            $items[] = [
                'room_number' => (string) ($lock['room_number'] ?? ''),
                'expires_at' => isset($lock['expires_at']) ? \wp_date(\get_option('date_format') . ' ' . \get_option('time_format'), \strtotime((string) $lock['expires_at'])) : '',
            ];
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<string, mixed>|null
     */
    private function findRoomById(array $rooms, int $roomId): ?array
    {
        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            if ((int) ($room['id'] ?? 0) === $roomId) {
                return $room;
            }
        }

        return null;
    }

    private function buildCellHeadline(string $state, int $availableUnits, int $totalUnits, array $counts): string
    {
        if ($state === 'filtered') {
            return \__('Filtered', 'must-hotel-booking');
        }

        if ($state === 'blocked') {
            return \__('Blocked', 'must-hotel-booking');
        }

        if ($state === 'booked') {
            return $totalUnits > 1
                ? \sprintf(\__('Full %1$d/%2$d', 'must-hotel-booking'), (int) ($counts['booked'] ?? 0), $totalUnits)
                : \__('Booked', 'must-hotel-booking');
        }

        if ($state === 'partial') {
            return \sprintf(\__('%d free', 'must-hotel-booking'), $availableUnits);
        }

        if ($state === 'pending') {
            return \__('Pending', 'must-hotel-booking');
        }

        if ($state === 'hold') {
            return \__('Held', 'must-hotel-booking');
        }

        if ($state === 'unavailable') {
            return \__('Unavailable', 'must-hotel-booking');
        }

        if ($totalUnits > 1) {
            return \sprintf(\__('%d free', 'must-hotel-booking'), $availableUnits);
        }

        return \__('Open', 'must-hotel-booking');
    }

    private function getReservationBucket(string $status): string
    {
        $status = \sanitize_key($status);

        if (\in_array($status, ['confirmed', 'completed'], true)) {
            return 'booked';
        }

        if (\in_array($status, ['pending', 'pending_payment'], true)) {
            return 'pending';
        }

        if ($status === 'blocked') {
            return 'blocked';
        }

        if ($status === 'cancelled') {
            return 'cancelled';
        }

        return ReservationStatus::blocksInventory($status) ? 'booked' : 'cancelled';
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, bool> $flags
     */
    private function resolveVisibleCellState(
        CalendarViewQuery $query,
        string $actualState,
        array $counts,
        array $flags,
        int $availableUnits
    ): string {
        if ($query->isVisible('blocked') && ($counts['blocked'] > 0 || $flags['maintenance_block'])) {
            return 'blocked';
        }

        if ($query->isVisible('booked') && $counts['booked'] > 0) {
            return $availableUnits <= 0 ? 'booked' : 'partial';
        }

        if ($query->isVisible('pending') && $counts['pending'] > 0) {
            return 'pending';
        }

        if ($query->isVisible('unavailable') && ($flags['inventory_unavailable'] || $flags['closed_arrival'] || $flags['closed_departure'])) {
            return 'unavailable';
        }

        if ($query->isVisible('hold') && $counts['hold'] > 0) {
            return 'hold';
        }

        return $actualState === 'available' ? 'available' : 'filtered';
    }

    /**
     * @return array<int, string>
     */
    private function iterateDateRange(string $startDate, string $endExclusive): array
    {
        $dates = [];

        if ($startDate === '' || $endExclusive === '' || $startDate >= $endExclusive) {
            return $dates;
        }

        $pointer = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endExclusive);

        while ($pointer < $end) {
            $dates[] = $pointer->format('Y-m-d');
            $pointer = $pointer->modify('+1 day');
        }

        return $dates;
    }

    private function maxDate(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        return $left > $right ? $left : $right;
    }

    private function minDate(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }

        if ($right === '') {
            return $left;
        }

        return $left < $right ? $left : $right;
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function formatReservationReference(array $reservation): string
    {
        $bookingId = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';

        if ($bookingId !== '') {
            return $bookingId;
        }

        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        return $reservationId > 0 ? 'RES-' . $reservationId : \__('Reservation', 'must-hotel-booking');
    }

    private function getReservationStatusLabel(string $status): string
    {
        $map = [
            'pending' => \__('Pending', 'must-hotel-booking'),
            'pending_payment' => \__('Pending Payment', 'must-hotel-booking'),
            'confirmed' => \__('Confirmed', 'must-hotel-booking'),
            'completed' => \__('Completed', 'must-hotel-booking'),
            'cancelled' => \__('Cancelled', 'must-hotel-booking'),
            'blocked' => \__('Blocked', 'must-hotel-booking'),
        ];

        return isset($map[$status]) ? (string) $map[$status] : \ucfirst(\str_replace('_', ' ', $status));
    }

    private function getRuleLabel(string $ruleType): string
    {
        $map = [
            'maintenance_block' => \__('Maintenance block', 'must-hotel-booking'),
            'closed_arrival' => \__('Closed to arrival', 'must-hotel-booking'),
            'closed_departure' => \__('Closed to departure', 'must-hotel-booking'),
        ];

        return isset($map[$ruleType]) ? (string) $map[$ruleType] : \ucfirst(\str_replace('_', ' ', $ruleType));
    }

    private function formatRuleRange(string $startDate, string $endDate): string
    {
        if ($startDate === '') {
            return '';
        }

        $formattedStart = \wp_date(\get_option('date_format'), \strtotime($startDate));

        if ($endDate === '' || $endDate === $startDate) {
            return $formattedStart;
        }

        return $formattedStart . ' - ' . \wp_date(\get_option('date_format'), \strtotime($endDate));
    }
}
