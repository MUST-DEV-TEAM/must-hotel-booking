<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Engine\PaymentStatusService;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\ProviderReservationView;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;

final class ReservationAdminDataProvider
{
    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservationRepository;

    /** @var \MustHotelBooking\Database\PaymentRepository */
    private $paymentRepository;

    /** @var \MustHotelBooking\Database\RoomRepository */
    private $roomRepository;

    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventoryRepository;

    /** @var \MustHotelBooking\Database\RatePlanRepository */
    private $ratePlanRepository;

    /** @var \MustHotelBooking\Database\ActivityRepository */
    private $activityRepository;

    private ProviderSyncJobRepository $providerSyncJobRepository;

    private ProviderMappingRepository $providerMappingRepository;

    /** @var array<int, bool> */
    private array $clockPhysicalRoomMappingCache = [];

    public function __construct()
    {
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->ratePlanRepository = \MustHotelBooking\Engine\get_rate_plan_repository();
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
        $this->providerSyncJobRepository = new ProviderSyncJobRepository();
        $this->providerMappingRepository = new ProviderMappingRepository();
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function getListPageData(array $request): array
    {
        $filters = $this->normalizeListFilters($request);
        $rows = [];
        $totalItems = 0;

        if ($this->shouldFilterListRowsByDerivedPaymentState($filters)) {
            $candidateFilters = $this->stripDerivedPaymentFilters($filters);
            $candidateFilters['paginate'] = false;
            $candidateRows = $this->reservationRepository->getAdminReservationListRows($candidateFilters);
            $filteredRows = $this->applyDerivedPaymentFilters(
                $this->decorateRowsWithPaymentState($candidateRows),
                $filters
            );
            $totalItems = \count($filteredRows);
            $totalPages = (int) \max(1, \ceil($totalItems / $filters['per_page']));
            $filters['paged'] = \max(1, \min($filters['paged'], $totalPages));
            $offset = ($filters['paged'] - 1) * $filters['per_page'];
            $rows = \array_slice($filteredRows, $offset, $filters['per_page']);
        } else {
            $totalItems = $this->reservationRepository->countAdminReservationListRows($filters);
            $totalPages = (int) \max(1, \ceil($totalItems / $filters['per_page']));
            $filters['paged'] = \max(1, \min($filters['paged'], $totalPages));
            $rows = $this->decorateRowsWithPaymentState(
                $this->reservationRepository->getAdminReservationListRows($filters)
            );
        }

        $totalPages = (int) \max(1, \ceil($totalItems / $filters['per_page']));

        return [
            'filters' => $filters,
            'quick_filters' => $this->buildQuickFilters($filters),
            'rows' => $this->formatListRows($rows),
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $filters['paged'],
                'per_page' => $filters['per_page'],
            ],
            'status_options' => get_reservation_status_options(),
            'payment_status_options' => get_reservation_payment_status_options(),
            'payment_method_options' => $this->getPaymentMethodOptions(),
            'room_options' => $this->roomRepository->getRoomSelectorRows(),
            'bulk_actions' => [
                'confirm' => \__('Confirm selected', 'must-hotel-booking'),
                'mark_paid' => \__('Mark paid', 'must-hotel-booking'),
                'cancel' => \__('Cancel selected', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetailPageData(int $reservationId, string $mode = 'view'): ?array
    {
        $reservation = $this->reservationRepository->getAdminReservationDetails($reservationId);

        if (!\is_array($reservation)) {
            return null;
        }

        $bookingId = $this->formatReservationReference($reservation);
        $paymentRows = $this->paymentRepository->getPaymentsForReservation($reservationId);
        $paymentSummary = $this->paymentRepository->getReservationPaymentSummary($reservationId);
        $paymentState = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
        $roomTypeId = isset($reservation['room_type_id']) ? (int) $reservation['room_type_id'] : (int) ($reservation['room_id'] ?? 0);
        $assignedRoomId = isset($reservation['assigned_room_id']) ? (int) $reservation['assigned_room_id'] : 0;
        $assignedRoom = $assignedRoomId > 0 ? $this->inventoryRepository->getInventoryRoomById($assignedRoomId) : null;
        $assignableRooms = $roomTypeId > 0 ? $this->inventoryRepository->getRoomsByType($roomTypeId) : [];
        $ratePlanId = isset($reservation['rate_plan_id']) ? (int) $reservation['rate_plan_id'] : 0;
        $ratePlan = $ratePlanId > 0 ? $this->ratePlanRepository->getRatePlanById($ratePlanId) : null;
        $timelineRows = $this->buildTimelineRows($reservation, $paymentRows);
        $emailRows = [];

        foreach ($timelineRows as $timelineRow) {
            if (!\is_array($timelineRow)) {
                continue;
            }

            $eventType = isset($timelineRow['event_type']) ? (string) $timelineRow['event_type'] : '';

            if ($eventType === 'email_sent' || $eventType === 'email_failed') {
                $emailRows[] = $timelineRow;
            }
        }

        $totalPrice = isset($reservation['total_price']) ? (float) $reservation['total_price'] : 0.0;
        $amountPaid = isset($paymentState['amount_paid']) ? (float) $paymentState['amount_paid'] : 0.0;
        $amountDue = isset($paymentState['amount_due']) ? (float) $paymentState['amount_due'] : \max(0.0, $totalPrice - $amountPaid);
        $providerContext = $this->buildProviderContext($reservation, $reservationId, $paymentState);

        if (!empty($providerContext['is_clock'])) {
            $assignableRooms = $this->filterClockMappedInventoryRooms($assignableRooms);
        }

        return [
            'id' => $reservationId,
            'mode' => $mode === 'edit' ? 'edit' : 'view',
            'booking_id' => $bookingId,
            'reservation' => $reservation,
            'summary' => [
                'reservation_status' => $this->formatReservationStatusLabel((string) ($reservation['status'] ?? '')),
                'reservation_status_key' => (string) ($reservation['status'] ?? ''),
                'payment_status' => $this->formatPaymentStatusLabel((string) ($paymentState['derived_status'] ?? (string) ($reservation['payment_status'] ?? ''))),
                'payment_status_key' => (string) ($paymentState['derived_status'] ?? (string) ($reservation['payment_status'] ?? '')),
                'payment_method' => $this->formatPaymentMethodLabel((string) ($paymentState['method'] ?? (string) ($paymentSummary['latest_method'] ?? ''))),
                'payment_method_key' => (string) ($paymentState['method'] ?? (string) ($paymentSummary['latest_method'] ?? '')),
                'created_at' => $this->formatDateTime((string) ($reservation['created_at'] ?? '')),
                'source' => (string) ($reservation['booking_source'] ?? ''),
            ],
            'guest' => [
                'id' => isset($reservation['guest_id']) ? (int) $reservation['guest_id'] : 0,
                'first_name' => (string) ($reservation['first_name'] ?? ''),
                'last_name' => (string) ($reservation['last_name'] ?? ''),
                'email' => (string) ($reservation['email'] ?? ''),
                'phone' => (string) ($reservation['phone'] ?? ''),
                'country' => (string) ($reservation['country'] ?? ''),
                'full_name' => $this->formatGuestName($reservation),
                'guest_url' => isset($reservation['guest_id']) && (int) $reservation['guest_id'] > 0
                    ? get_admin_guests_page_url(['guest_id' => (int) $reservation['guest_id']])
                    : '',
            ],
            'stay' => [
                'accommodation' => isset($reservation['room_name']) && (string) $reservation['room_name'] !== ''
                    ? (string) $reservation['room_name']
                    : \__('Unassigned', 'must-hotel-booking'),
                'checkin' => (string) ($reservation['checkin'] ?? ''),
                'checkout' => (string) ($reservation['checkout'] ?? ''),
                'nights' => $this->calculateNights((string) ($reservation['checkin'] ?? ''), (string) ($reservation['checkout'] ?? '')),
                'guests' => isset($reservation['guests']) ? (int) $reservation['guests'] : 0,
                'assigned_room' => \is_array($assignedRoom)
                    ? ((string) ($assignedRoom['room_number'] ?? ''))
                    : '',
                'rate_plan' => \is_array($ratePlan) ? (string) ($ratePlan['name'] ?? '') : '',
                'booking_source' => (string) ($reservation['booking_source'] ?? ''),
                'notes' => (string) ($reservation['notes'] ?? ''),
                'edit_notice' => \__('Authorized staff can correct stay dates here. Once the guest is checked in, only the checkout date should be adjusted from this workspace.', 'must-hotel-booking'),
            ],
            'pricing' => [
                'currency' => MustBookingConfig::get_currency(),
                'stored_total' => $totalPrice,
                'amount_paid' => $amountPaid,
                'amount_due' => $amountDue,
                'coupon_code' => (string) ($reservation['coupon_code'] ?? ''),
                'coupon_discount_total' => isset($reservation['coupon_discount_total']) ? (float) $reservation['coupon_discount_total'] : 0.0,
            ],
            'payments' => $this->formatPaymentRows($paymentRows),
            'payment_summary' => $paymentSummary,
            'payment_state' => $paymentState,
            'emails' => $emailRows,
            'timeline' => $timelineRows,
            'assignable_rooms' => $assignableRooms,
            'provider' => $providerContext,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFrontDeskQueueData(string $queueKey): array
    {
        $queueKey = \sanitize_key($queueKey);
        $today = \current_time('Y-m-d');
        $baseRows = $queueKey === 'checkout'
            ? $this->reservationRepository->getFrontDeskCheckoutQueueRows($today, 50)
            : $this->reservationRepository->getFrontDeskCheckinQueueRows($today, 50);
        $rows = $this->decorateRowsWithPaymentState($baseRows);
        $assignedRoomMap = $this->loadAssignedRoomsForFrontDeskQueue($rows);
        $formattedRows = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];
            $assignedRoomId = isset($row['assigned_room_id']) ? (int) $row['assigned_room_id'] : 0;
            $assignedRoom = $assignedRoomId > 0 && isset($assignedRoomMap[$assignedRoomId]) && \is_array($assignedRoomMap[$assignedRoomId])
                ? $assignedRoomMap[$assignedRoomId]
                : null;
            $assignedRoomNumber = \is_array($assignedRoom) ? \trim((string) ($assignedRoom['room_number'] ?? '')) : '';
            $assignedRoomTitle = \is_array($assignedRoom) ? \trim((string) ($assignedRoom['title'] ?? '')) : '';
            $assignedRoomLabel = $assignedRoomNumber !== ''
                ? $assignedRoomNumber
                : ($assignedRoomTitle !== '' ? $assignedRoomTitle : '');
            $amountDueRaw = isset($paymentState['amount_due']) ? (float) $paymentState['amount_due'] : 0.0;
            $paymentStatusKey = (string) ($paymentState['derived_status'] ?? (string) ($row['payment_status'] ?? ''));
            $queueDate = $queueKey === 'checkout'
                ? (string) ($row['checkout'] ?? '')
                : (string) ($row['checkin'] ?? '');
            $isOverdue = $queueDate !== '' && $queueDate < $today;
            $formattedRows[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : 0,
                'booking_id' => $this->formatReservationReference($row),
                'provider' => ProviderReservationView::metadata($row),
                'guest' => $this->formatGuestName($row),
                'guest_email' => (string) ($row['guest_email'] ?? ''),
                'accommodation' => isset($row['room_name']) && (string) $row['room_name'] !== ''
                    ? (string) $row['room_name']
                    : \__('Unassigned', 'must-hotel-booking'),
                'assigned_room' => $assignedRoomLabel,
                'checkin' => (string) ($row['checkin'] ?? ''),
                'checkout' => (string) ($row['checkout'] ?? ''),
                'reservation_status' => $this->formatReservationStatusLabel((string) ($row['status'] ?? '')),
                'reservation_status_key' => (string) ($row['status'] ?? ''),
                'payment_status' => $this->formatPaymentStatusLabel($paymentStatusKey),
                'payment_status_key' => $paymentStatusKey,
                'amount_due' => $this->formatMoney($amountDueRaw),
                'amount_due_raw' => $amountDueRaw,
                'has_due' => $amountDueRaw > 0.0,
                'queue_label' => $queueKey === 'checkout'
                    ? ($isOverdue ? \__('Overdue checkout', 'must-hotel-booking') : \__('Due today', 'must-hotel-booking'))
                    : ($isOverdue ? \__('Overdue arrival', 'must-hotel-booking') : \__('Arriving today', 'must-hotel-booking')),
                'queue_tone' => $isOverdue ? 'error' : 'warning',
                'payment_workspace_needed' => $amountDueRaw > 0.0 || \in_array($paymentStatusKey, ['failed', 'pending', 'partially_paid', 'pay_at_hotel'], true),
            ];
        }

        return [
            'queue_key' => $queueKey,
            'queue_title' => $queueKey === 'checkout'
                ? \__('Check-out Queue', 'must-hotel-booking')
                : \__('Check-in Queue', 'must-hotel-booking'),
            'queue_description' => $queueKey === 'checkout'
                ? \__('Review departures and finish guest stays using the existing reservation and payment workspaces.', 'must-hotel-booking')
                : \__('Review arriving reservations and move guests into house using the existing reservation workspace.', 'must-hotel-booking'),
            'empty_message' => $queueKey === 'checkout'
                ? \__('No reservations are currently waiting for checkout.', 'must-hotel-booking')
                : \__('No reservations are currently waiting for check-in.', 'must-hotel-booking'),
            'summary_cards' => $this->buildFrontDeskQueueSummaryCards($formattedRows, $queueKey),
            'rows' => $formattedRows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFrontDeskRoomMoveData(): array
    {
        $today = \current_time('Y-m-d');
        $now = \current_time('mysql');
        $baseRows = $this->reservationRepository->getFrontDeskRoomMoveRows(50);
        $assignedRoomMap = $this->loadAssignedRoomsForFrontDeskQueue($baseRows);
        $formattedRows = [];
        $moveableCount = 0;
        $overdueCount = 0;

        foreach ($baseRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
            $assignedRoomId = isset($row['assigned_room_id']) ? (int) $row['assigned_room_id'] : 0;
            $roomTypeId = isset($row['room_type_id']) ? (int) $row['room_type_id'] : (int) ($row['room_id'] ?? 0);
            $assignedRoom = $assignedRoomId > 0 && isset($assignedRoomMap[$assignedRoomId]) && \is_array($assignedRoomMap[$assignedRoomId])
                ? $assignedRoomMap[$assignedRoomId]
                : null;
            $candidateRooms = [];

            if ($roomTypeId > 0 && $reservationId > 0) {
                $window = $this->resolveFrontDeskMoveWindow((string) ($row['checkin'] ?? ''), (string) ($row['checkout'] ?? ''), $today);
                $candidateRooms = $this->inventoryRepository->getAvailableRooms(
                    $roomTypeId,
                    $window['checkin'],
                    $window['checkout'],
                    ['cancelled', 'expired', 'payment_failed'],
                    $now
                );
            }

            $providerContext = ProviderReservationView::metadata($row);
            $isProviderBacked = !empty($providerContext['is_provider_backed']);
            $isClockBacked = !empty($providerContext['is_clock']) && (string) ($providerContext['provider'] ?? '') === ProviderManager::CLOCK_MODE;
            $moveOptions = [];

            foreach ($candidateRooms as $candidateRoom) {
                if (!\is_array($candidateRoom)) {
                    continue;
                }

                $candidateId = isset($candidateRoom['id']) ? (int) $candidateRoom['id'] : 0;

                if ($candidateId <= 0 || $candidateId === $assignedRoomId) {
                    continue;
                }

                if ($isClockBacked && !$this->isClockPhysicalRoomMapped($candidateId)) {
                    continue;
                }

                $moveOptions[] = [
                    'id' => $candidateId,
                    'label' => $this->formatInventoryRoomLabel($candidateRoom),
                ];
            }

            if (!empty($moveOptions) && (!$isProviderBacked || $isClockBacked)) {
                $moveableCount++;
            }

            if ((string) ($row['checkout'] ?? '') !== '' && (string) ($row['checkout'] ?? '') <= $today) {
                $overdueCount++;
            }

            $formattedRows[] = [
                'id' => $reservationId,
                'booking_id' => $this->formatReservationReference($row),
                'provider' => $providerContext,
                'guest' => $this->formatGuestName($row),
                'guest_email' => (string) ($row['guest_email'] ?? ''),
                'accommodation' => isset($row['room_name']) && (string) $row['room_name'] !== ''
                    ? (string) $row['room_name']
                    : \__('Unassigned', 'must-hotel-booking'),
                'assigned_room' => \is_array($assignedRoom)
                    ? $this->formatInventoryRoomLabel($assignedRoom)
                    : \__('No room assigned', 'must-hotel-booking'),
                'checkin' => (string) ($row['checkin'] ?? ''),
                'checkout' => (string) ($row['checkout'] ?? ''),
                'reservation_status' => $this->formatReservationStatusLabel((string) ($row['status'] ?? '')),
                'reservation_status_key' => (string) ($row['status'] ?? ''),
                'desk_state' => \__('In house', 'must-hotel-booking'),
                'desk_state_key' => ((string) ($row['checkout'] ?? '') !== '' && (string) ($row['checkout'] ?? '') <= $today) ? 'warning' : 'success',
                'move_options' => (!$isProviderBacked || $isClockBacked) ? $moveOptions : [],
                'can_move' => (!$isProviderBacked || $isClockBacked) && !empty($moveOptions),
            ];
        }

        return [
            'queue_title' => \__('Room Move / Upgrade', 'must-hotel-booking'),
            'queue_description' => \__('Reassign checked-in guests to another compatible room without leaving the Front Desk workspace.', 'must-hotel-booking'),
            'empty_message' => \__('No checked-in reservations are currently ready for room reassignment.', 'must-hotel-booking'),
            'summary_cards' => [
                [
                    'label' => \__('In-house stays', 'must-hotel-booking'),
                    'value' => (string) \count($formattedRows),
                    'meta' => \__('Checked-in reservations currently eligible for desk review.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Move options ready', 'must-hotel-booking'),
                    'value' => (string) $moveableCount,
                    'meta' => \__('Reservations with at least one compatible room available now.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Due out today', 'must-hotel-booking'),
                    'value' => (string) $overdueCount,
                    'meta' => \__('Checked-in stays whose checkout date is today or earlier.', 'must-hotel-booking'),
                ],
            ],
            'rows' => $formattedRows,
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function normalizeListFilters(array $request): array
    {
        $statusOptions = get_reservation_status_options();
        $paymentStatusOptions = get_reservation_payment_status_options();
        $paymentMethodOptions = $this->getPaymentMethodOptions();
        $quickFilter = isset($request['quick_filter'])
            ? \sanitize_key((string) $request['quick_filter'])
            : (isset($request['preset']) ? \sanitize_key((string) $request['preset']) : '');
        $validQuickFilters = [
            '',
            'all',
            'arrivals_today',
            'departures_today',
            'in_house_today',
            'upcoming',
            'pending',
            'confirmed',
            'unpaid',
            'paid',
            'cancelled',
            'failed_payment',
        ];

        if (!\in_array($quickFilter, $validQuickFilters, true)) {
            $quickFilter = '';
        }

        if ($quickFilter === 'all') {
            $quickFilter = '';
        }

        $status = isset($request['status']) ? \sanitize_key((string) $request['status']) : '';
        $paymentStatus = isset($request['payment_status']) ? \sanitize_key((string) $request['payment_status']) : '';
        $paymentMethod = isset($request['payment_method']) ? \sanitize_key((string) $request['payment_method']) : '';

        if ($status !== '' && !isset($statusOptions[$status])) {
            $status = '';
        }

        if ($paymentStatus !== '' && !isset($paymentStatusOptions[$paymentStatus])) {
            $paymentStatus = '';
        }

        if ($paymentMethod !== '' && !isset($paymentMethodOptions[$paymentMethod])) {
            $paymentMethod = '';
        }

        return [
            'quick_filter' => $quickFilter,
            'search' => isset($request['search'])
                ? \sanitize_text_field((string) $request['search'])
                : (isset($request['s']) ? \sanitize_text_field((string) $request['s']) : ''),
            'status' => $status,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'room_id' => isset($request['room_id']) ? \absint($request['room_id']) : 0,
            'checkin_from' => isset($request['checkin_from']) ? \sanitize_text_field((string) $request['checkin_from']) : '',
            'checkin_to' => isset($request['checkin_to']) ? \sanitize_text_field((string) $request['checkin_to']) : '',
            'checkin_month' => isset($request['checkin_month']) ? \sanitize_text_field((string) $request['checkin_month']) : '',
            'per_page' => isset($request['per_page']) ? (int) $request['per_page'] : 20,
            'paged' => isset($request['paged']) ? (int) $request['paged'] : 1,
            'today' => \current_time('Y-m-d'),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildQuickFilters(array $filters): array
    {
        $counts = $this->buildQuickFilterCounts((string) $filters['today']);
        $current = (string) ($filters['quick_filter'] !== '' ? $filters['quick_filter'] : 'all');
        $tabs = [
            'all' => \__('All', 'must-hotel-booking'),
            'arrivals_today' => \__('Arrivals Today', 'must-hotel-booking'),
            'departures_today' => \__('Departures Today', 'must-hotel-booking'),
            'in_house_today' => \__('In-House', 'must-hotel-booking'),
            'upcoming' => \__('Upcoming', 'must-hotel-booking'),
            'pending' => \__('Pending', 'must-hotel-booking'),
            'confirmed' => \__('Confirmed', 'must-hotel-booking'),
            'unpaid' => \__('Unpaid', 'must-hotel-booking'),
            'paid' => \__('Paid', 'must-hotel-booking'),
            'cancelled' => \__('Cancelled', 'must-hotel-booking'),
            'failed_payment' => \__('Failed Payment', 'must-hotel-booking'),
        ];
        $rows = [];

        foreach ($tabs as $slug => $label) {
            $rows[] = [
                'slug' => $slug,
                'label' => $label,
                'count' => isset($counts[$slug === 'in_house_today' ? 'in_house' : $slug]) ? (int) $counts[$slug === 'in_house_today' ? 'in_house' : $slug] : 0,
                'url' => $this->buildQuickFilterUrl($filters, $slug),
                'current' => $current === $slug,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildQuickFilterUrl(array $filters, string $slug): string
    {
        $args = [
            'search' => (string) ($filters['search'] ?? ''),
            'status' => (string) ($filters['status'] ?? ''),
            'payment_status' => (string) ($filters['payment_status'] ?? ''),
            'payment_method' => (string) ($filters['payment_method'] ?? ''),
            'checkin_from' => (string) ($filters['checkin_from'] ?? ''),
            'checkin_to' => (string) ($filters['checkin_to'] ?? ''),
            'checkin_month' => (string) ($filters['checkin_month'] ?? ''),
        ];

        if (!empty($filters['room_id'])) {
            $args['room_id'] = (int) $filters['room_id'];
        }

        if ($slug !== 'all') {
            $args['quick_filter'] = $slug;
        }

        return get_admin_reservations_page_url(\array_filter(
            $args,
            static function ($value): bool {
                return $value !== '';
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function decorateRowsWithPaymentState(array $rows): array
    {
        $reservationIds = $this->collectReservationIds($rows);
        $paymentsByReservation = $this->paymentRepository->getPaymentsForReservationIds($reservationIds);

        foreach ($rows as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }

            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
            $paymentRows = isset($paymentsByReservation[$reservationId]) && \is_array($paymentsByReservation[$reservationId])
                ? $paymentsByReservation[$reservationId]
                : [];
            $rows[$index]['payment_state'] = PaymentStatusService::buildReservationPaymentState($row, $paymentRows);
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function collectReservationIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['id'])) {
                continue;
            }

            $reservationId = (int) $row['id'];

            if ($reservationId > 0) {
                $ids[$reservationId] = $reservationId;
            }
        }

        return \array_values($ids);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function shouldFilterListRowsByDerivedPaymentState(array $filters): bool
    {
        if ((string) ($filters['payment_status'] ?? '') !== '' || (string) ($filters['payment_method'] ?? '') !== '') {
            return true;
        }

        return \in_array((string) ($filters['quick_filter'] ?? ''), ['unpaid', 'paid', 'failed_payment'], true);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function stripDerivedPaymentFilters(array $filters): array
    {
        $filters['payment_status'] = '';
        $filters['payment_method'] = '';

        if (\in_array((string) ($filters['quick_filter'] ?? ''), ['unpaid', 'paid', 'failed_payment'], true)) {
            $filters['quick_filter'] = '';
        }

        return $filters;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function applyDerivedPaymentFilters(array $rows, array $filters): array
    {
        return \array_values(\array_filter($rows, function (array $row) use ($filters): bool {
            if ((string) ($filters['payment_status'] ?? '') !== '' && !$this->matchesPaymentStatusFilter($row, (string) $filters['payment_status'])) {
                return false;
            }

            if ((string) ($filters['payment_method'] ?? '') !== '' && !$this->matchesPaymentMethodFilter($row, (string) $filters['payment_method'])) {
                return false;
            }

            $quickFilter = (string) ($filters['quick_filter'] ?? '');

            if (\in_array($quickFilter, ['unpaid', 'paid', 'failed_payment'], true) && !$this->matchesDerivedQuickFilter($row, $quickFilter)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchesPaymentStatusFilter(array $row, string $paymentStatus): bool
    {
        $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];

        return (string) ($paymentState['derived_status'] ?? '') === $paymentStatus;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchesPaymentMethodFilter(array $row, string $method): bool
    {
        $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];

        return (string) ($paymentState['method'] ?? '') === $method;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchesDerivedQuickFilter(array $row, string $quickFilter): bool
    {
        $status = (string) ($row['status'] ?? '');
        $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];
        $derivedStatus = (string) ($paymentState['derived_status'] ?? '');

        if ($quickFilter === 'paid') {
            return $derivedStatus === 'paid';
        }

        if ($quickFilter === 'failed_payment') {
            return $status === 'payment_failed' || $derivedStatus === 'failed';
        }

        if ($quickFilter === 'unpaid') {
            return !\in_array($status, ['cancelled', 'blocked', 'expired'], true)
                && $this->hasOutstandingBalance($row, $paymentState);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $paymentState
     */
    private function hasOutstandingBalance(array $row, array $paymentState): bool
    {
        $total = isset($row['total_price']) ? (float) $row['total_price'] : 0.0;
        $amountDue = isset($paymentState['amount_due'])
            ? (float) $paymentState['amount_due']
            : \max(0.0, $total - (float) ($paymentState['amount_paid'] ?? 0.0));

        if ($total <= 0.0 || $amountDue <= 0.0) {
            return false;
        }

        return \in_array(
            (string) ($paymentState['derived_status'] ?? ''),
            ['unpaid', 'pending', 'partially_paid', 'pay_at_hotel', 'failed'],
            true
        );
    }

    /**
     * @return array<string, int>
     */
    private function buildQuickFilterCounts(string $today): array
    {
        $counts = $this->reservationRepository->getAdminReservationQuickFilterCounts($today);
        $rows = $this->decorateRowsWithPaymentState(
            $this->reservationRepository->getAdminReservationQuickFilterRows()
        );
        $counts['unpaid'] = 0;
        $counts['paid'] = 0;
        $counts['failed_payment'] = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ($this->matchesDerivedQuickFilter($row, 'unpaid')) {
                $counts['unpaid']++;
            }

            if ($this->matchesDerivedQuickFilter($row, 'paid')) {
                $counts['paid']++;
            }

            if ($this->matchesDerivedQuickFilter($row, 'failed_payment')) {
                $counts['failed_payment']++;
            }
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatListRows(array $rows): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
            $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];
            $paymentStatusKey = (string) ($paymentState['derived_status'] ?? (string) ($row['payment_status'] ?? ''));
            $paymentMethodKey = (string) ($paymentState['method'] ?? (string) ($row['payment_method'] ?? ''));
            $providerContext = ProviderReservationView::metadata($row);
            $formatted[] = [
                'id' => $reservationId,
                'booking_id' => $this->formatReservationReference($row),
                'provider' => $providerContext,
                'is_provider_backed' => !empty($providerContext['is_provider_backed']),
                'guest' => $this->formatGuestName($row),
                'guest_id' => isset($row['guest_id']) ? (int) $row['guest_id'] : 0,
                'guest_url' => isset($row['guest_id']) && (int) $row['guest_id'] > 0
                    ? get_admin_guests_page_url(['guest_id' => (int) $row['guest_id']])
                    : '',
                'guest_email' => (string) ($row['guest_email'] ?? ''),
                'guest_phone' => (string) ($row['guest_phone'] ?? ''),
                'accommodation' => isset($row['room_name']) && (string) $row['room_name'] !== ''
                    ? (string) $row['room_name']
                    : \__('Unassigned', 'must-hotel-booking'),
                'checkin' => (string) ($row['checkin'] ?? ''),
                'checkout' => (string) ($row['checkout'] ?? ''),
                'nights' => $this->calculateNights((string) ($row['checkin'] ?? ''), (string) ($row['checkout'] ?? '')),
                'guests' => isset($row['guests']) ? (int) $row['guests'] : 0,
                'reservation_status' => $this->formatReservationStatusLabel((string) ($row['status'] ?? '')),
                'reservation_status_key' => (string) ($row['status'] ?? ''),
                'cancellation_requested' => !empty($row['cancellation_requested']),
                'cancellation_requested_at' => (string) ($row['cancellation_requested_at'] ?? ''),
                'cancellation_requested_by' => isset($row['cancellation_requested_by']) ? (int) $row['cancellation_requested_by'] : 0,
                'payment_status' => $this->formatPaymentStatusLabel($paymentStatusKey),
                'payment_status_key' => $paymentStatusKey,
                'payment_method' => $this->formatPaymentMethodLabel($paymentMethodKey),
                'payment_method_key' => $paymentMethodKey,
                'total' => $this->formatMoney((float) ($row['total_price'] ?? 0)),
                'created' => $this->formatDateTime((string) ($row['created_at'] ?? '')),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array<string, mixed>
     */
    private function buildProviderContext(array $reservation, int $reservationId, array $paymentState = []): array
    {
        $context = ProviderReservationView::metadata($reservation);

        if (empty($context['is_provider_backed'])) {
            return $context;
        }

        $provider = isset($context['provider']) ? (string) $context['provider'] : '';
        $jobSummary = $this->providerSyncJobRepository->getTargetSummary($provider, 'reservation', $reservationId);
        $context['sync_jobs'] = $this->formatProviderSyncJobSummary($jobSummary);
        $context['payment_sync'] = ProviderReservationView::paymentContext($reservation, $paymentState);
        $context['pricing_sync'] = $this->formatProviderPricingSync(
            $this->decodeProviderMetadata($reservation['provider_metadata'] ?? null)
        );
        $context['guest_sync'] = $this->formatProviderGuestSync(
            $this->decodeProviderMetadata($reservation['provider_metadata'] ?? null)
        );

        return $context;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function formatProviderSyncJobSummary(array $summary): array
    {
        $lastUpdatedAt = isset($summary['last_updated_at']) ? (string) $summary['last_updated_at'] : '';

        if ($lastUpdatedAt !== '') {
            $lastUpdatedAt = $this->formatDateTime($lastUpdatedAt);
        }

        return [
            'counts' => isset($summary['counts']) && \is_array($summary['counts']) ? $summary['counts'] : [],
            'open_count' => isset($summary['open_count']) ? (int) $summary['open_count'] : 0,
            'problem_count' => isset($summary['problem_count']) ? (int) $summary['problem_count'] : 0,
            'last_error' => (string) ($summary['last_error'] ?? ''),
            'last_updated_at' => $lastUpdatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function formatProviderPricingSync(array $metadata): array
    {
        $last = isset($metadata['last_provider_pricing_refresh']) && \is_array($metadata['last_provider_pricing_refresh'])
            ? $metadata['last_provider_pricing_refresh']
            : [];
        $required = !empty($metadata['pricing_reconciliation_required']);

        if (empty($last) && !$required) {
            return [];
        }

        $status = isset($last['sync_status']) ? \sanitize_key((string) $last['sync_status']) : '';

        if ($status === '') {
            $status = $required ? 'pending_retry' : (!empty($last['success']) ? 'synced' : '');
        }

        return [
            'required' => $required,
            'success' => !empty($last['success']),
            'status' => $status,
            'status_label' => ProviderReservationView::formatStatusLabel($status),
            'error' => (string) ($last['sync_error'] ?? ''),
            'synced_at' => $this->formatDateTime((string) ($last['synced_at'] ?? '')),
            'total_price' => isset($last['total_price']) && \is_numeric($last['total_price'])
                ? $this->formatMoney((float) $last['total_price'])
                : '',
            'amount_due' => isset($last['amount_due']) && \is_numeric($last['amount_due'])
                ? $this->formatMoney((float) $last['amount_due'])
                : '',
            'financial_adjustment_required' => !empty($metadata['financial_adjustment_required']),
            'financial_adjustment_type' => (string) ($metadata['financial_adjustment_type'] ?? ($last['financial_adjustment_type'] ?? '')),
            'financial_adjustment_status' => (string) ($metadata['financial_adjustment_status'] ?? ($last['financial_adjustment_status'] ?? '')),
            'financial_adjustment_amount' => isset($metadata['financial_adjustment_amount']) && \is_numeric($metadata['financial_adjustment_amount'])
                ? $this->formatMoney((float) $metadata['financial_adjustment_amount'])
                : (isset($last['financial_adjustment_amount']) && \is_numeric($last['financial_adjustment_amount'])
                    ? $this->formatMoney((float) $last['financial_adjustment_amount'])
                    : ''),
            'pricing_source' => (string) ($last['pricing_source'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function formatProviderGuestSync(array $metadata): array
    {
        $last = isset($metadata['last_provider_guest_update']) && \is_array($metadata['last_provider_guest_update'])
            ? $metadata['last_provider_guest_update']
            : [];
        $required = !empty($metadata['provider_guest_update_required']);

        if (empty($last) && !$required) {
            return [];
        }

        $status = isset($last['sync_status']) ? \sanitize_key((string) $last['sync_status']) : '';

        if ($status === '') {
            $status = $required ? 'pending_retry' : (!empty($last['success']) ? 'synced' : '');
        }

        return [
            'required' => $required,
            'success' => !empty($last['success']),
            'status' => $status,
            'status_label' => ProviderReservationView::formatStatusLabel($status),
            'error' => (string) ($last['sync_error'] ?? ''),
            'synced_at' => $this->formatDateTime((string) ($last['synced_at'] ?? '')),
        ];
    }

    /**
     * @param mixed $metadata
     * @return array<string, mixed>
     */
    private function decodeProviderMetadata($metadata): array
    {
        if (\is_array($metadata)) {
            return $metadata;
        }

        if (!\is_string($metadata) || \trim($metadata) === '') {
            return [];
        }

        $decoded = \json_decode($metadata, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<int, array<string, mixed>>
     */
    private function filterClockMappedInventoryRooms(array $rooms): array
    {
        $mapped = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($this->isClockPhysicalRoomMapped($roomId)) {
                $mapped[] = $room;
            }
        }

        return $mapped;
    }

    private function isClockPhysicalRoomMapped(int $roomId): bool
    {
        if ($roomId <= 0) {
            return false;
        }

        if (isset($this->clockPhysicalRoomMappingCache[$roomId])) {
            return $this->clockPhysicalRoomMappingCache[$roomId];
        }

        $mapping = $this->providerMappingRepository->findByLocal(
            ProviderManager::CLOCK_MODE,
            'physical_room',
            $roomId,
            'mhb_rooms'
        );
        $mapped = \is_array($mapping) && (string) ($mapping['external_id'] ?? '') !== '';
        $this->clockPhysicalRoomMappingCache[$roomId] = $mapped;

        return $mapped;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function loadAssignedRoomsForFrontDeskQueue(array $rows): array
    {
        $assignedRooms = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $assignedRoomId = isset($row['assigned_room_id']) ? (int) $row['assigned_room_id'] : 0;

            if ($assignedRoomId <= 0 || isset($assignedRooms[$assignedRoomId])) {
                continue;
            }

            $assignedRoom = $this->inventoryRepository->getInventoryRoomById($assignedRoomId);

            if (\is_array($assignedRoom)) {
                $assignedRooms[$assignedRoomId] = $assignedRoom;
            }
        }

        return $assignedRooms;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildFrontDeskQueueSummaryCards(array $rows, string $queueKey): array
    {
        $totalRows = \count($rows);
        $overdueCount = 0;
        $assignedCount = 0;
        $dueCount = 0;
        $dueAmount = 0.0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ((string) ($row['queue_tone'] ?? '') === 'error') {
                $overdueCount++;
            }

            if (\trim((string) ($row['assigned_room'] ?? '')) !== '') {
                $assignedCount++;
            }

            if (!empty($row['has_due'])) {
                $dueCount++;
                $dueAmount += isset($row['amount_due_raw']) ? (float) $row['amount_due_raw'] : 0.0;
            }
        }

        return [
            [
                'label' => $queueKey === 'checkout' ? \__('Awaiting checkout', 'must-hotel-booking') : \__('Awaiting check-in', 'must-hotel-booking'),
                'value' => (string) $totalRows,
                'meta' => $queueKey === 'checkout'
                    ? \__('Confirmed stays still open at the desk.', 'must-hotel-booking')
                    : \__('Arrivals ready for front-desk follow-up.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Overdue', 'must-hotel-booking'),
                'value' => (string) $overdueCount,
                'meta' => $queueKey === 'checkout'
                    ? \__('Departures that should already be closed out.', 'must-hotel-booking')
                    : \__('Arrivals that were not checked in on time.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Assigned rooms', 'must-hotel-booking'),
                'value' => (string) $assignedCount,
                'meta' => \__('Queue items with a physical room already linked.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Balance due', 'must-hotel-booking'),
                'value' => (string) $dueCount,
                'meta' => \sprintf(
                    /* translators: %s: formatted due amount */
                    \__('Outstanding total: %s', 'must-hotel-booking'),
                    $this->formatMoney($dueAmount)
                ),
            ],
        ];
    }

    /**
     * @return array{checkin: string, checkout: string}
     */
    private function resolveFrontDeskMoveWindow(string $checkin, string $checkout, string $today): array
    {
        $effectiveCheckin = $checkin !== '' && $checkin > $today ? $checkin : $today;
        $effectiveCheckout = $checkout;

        if ($effectiveCheckout === '' || $effectiveCheckout <= $effectiveCheckin) {
            $timestamp = \strtotime($effectiveCheckin . ' +1 day');
            $effectiveCheckout = $timestamp !== false ? \wp_date('Y-m-d', $timestamp) : $effectiveCheckin;
        }

        return [
            'checkin' => $effectiveCheckin,
            'checkout' => $effectiveCheckout,
        ];
    }

    /**
     * @param array<string, mixed> $room
     */
    private function formatInventoryRoomLabel(array $room): string
    {
        $roomNumber = \trim((string) ($room['room_number'] ?? ''));
        $title = \trim((string) ($room['title'] ?? ''));

        if ($roomNumber !== '' && $title !== '') {
            return $roomNumber . ' - ' . $title;
        }

        if ($roomNumber !== '') {
            return $roomNumber;
        }

        if ($title !== '') {
            return $title;
        }

        return isset($room['id']) ? '#' . (int) $room['id'] : \__('Room', 'must-hotel-booking');
    }

    /**
     * @param array<int, array<string, mixed>> $paymentRows
     * @return array<int, array<string, string>>
     */
    private function formatPaymentRows(array $paymentRows): array
    {
        $rows = [];

        foreach ($paymentRows as $paymentRow) {
            if (!\is_array($paymentRow)) {
                continue;
            }

            $rows[] = [
                'amount' => $this->formatMoney((float) ($paymentRow['amount'] ?? 0), (string) ($paymentRow['currency'] ?? MustBookingConfig::get_currency())),
                'method' => $this->formatPaymentMethodLabel((string) ($paymentRow['method'] ?? '')),
                'status' => $this->formatPaymentStatusLabel((string) ($paymentRow['status'] ?? '')),
                'status_key' => (string) ($paymentRow['status'] ?? ''),
                'transaction_id' => (string) ($paymentRow['transaction_id'] ?? ''),
                'paid_at' => $this->formatDateTime((string) ($paymentRow['paid_at'] ?? '')),
                'created_at' => $this->formatDateTime((string) ($paymentRow['created_at'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<int, array<string, mixed>> $paymentRows
     * @return array<int, array<string, string>>
     */
    private function buildTimelineRows(array $reservation, array $paymentRows): array
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        $reference = $this->formatReservationReference($reservation);
        $activityRows = $this->activityRepository->getRecentActivitiesForReservation($reservationId, $reference, 20);

        if (!empty($activityRows)) {
            return $this->formatActivityRows($activityRows);
        }

        $rows = [
            [
                'event_type' => 'reservation_created',
                'severity' => 'info',
                'message' => \sprintf(\__('Reservation %s created.', 'must-hotel-booking'), $reference),
                'reference' => $reference,
                'created_at' => $this->formatDateTime((string) ($reservation['created_at'] ?? '')),
            ],
        ];

        foreach ($paymentRows as $paymentRow) {
            if (!\is_array($paymentRow)) {
                continue;
            }

            $status = (string) ($paymentRow['status'] ?? '');
            $rows[] = [
                'event_type' => $status === 'failed' ? 'payment_failed' : 'payment_recorded',
                'severity' => $status === 'failed' ? 'error' : ($status === 'pending' ? 'warning' : 'info'),
                'message' => $status === 'paid'
                    ? \sprintf(\__('Payment received for %s.', 'must-hotel-booking'), $reference)
                    : ($status === 'failed'
                        ? \sprintf(\__('Payment failed for %s.', 'must-hotel-booking'), $reference)
                        : \sprintf(\__('Payment recorded for %s.', 'must-hotel-booking'), $reference)),
                'reference' => $reference,
                'created_at' => $this->formatDateTime(
                    isset($paymentRow['paid_at']) && (string) $paymentRow['paid_at'] !== ''
                        ? (string) $paymentRow['paid_at']
                        : (string) ($paymentRow['created_at'] ?? '')
                ),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $activityRows
     * @return array<int, array<string, string>>
     */
    private function formatActivityRows(array $activityRows): array
    {
        $rows = [];

        foreach ($activityRows as $activityRow) {
            if (!\is_array($activityRow)) {
                continue;
            }

            $rows[] = [
                'event_type' => (string) ($activityRow['event_type'] ?? ''),
                'severity' => (string) ($activityRow['severity'] ?? 'info'),
                'message' => (string) ($activityRow['message'] ?? ''),
                'reference' => (string) ($activityRow['reference'] ?? ''),
                'created_at' => $this->formatDateTime((string) ($activityRow['created_at'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function getPaymentMethodOptions(): array
    {
        $catalog = PaymentMethodRegistry::getCatalog();
        $options = [];

        foreach ($catalog as $methodKey => $meta) {
            $options[$methodKey] = isset($meta['label']) ? (string) $meta['label'] : $methodKey;
        }

        if (!isset($options['pay_at_hotel'])) {
            $options['pay_at_hotel'] = \__('Pay at hotel', 'must-hotel-booking');
        }

        return $options;
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

    /**
     * @param array<string, mixed> $row
     */
    private function formatGuestName(array $row): string
    {
        $guestName = isset($row['guest_name']) ? \trim((string) $row['guest_name']) : '';

        if ($guestName !== '') {
            return $guestName;
        }

        $firstName = isset($row['first_name']) ? \trim((string) $row['first_name']) : '';
        $lastName = isset($row['last_name']) ? \trim((string) $row['last_name']) : '';
        $fullName = \trim($firstName . ' ' . $lastName);

        return $fullName !== '' ? $fullName : \__('Guest details missing', 'must-hotel-booking');
    }

    private function formatReservationStatusLabel(string $status): string
    {
        $labels = get_reservation_status_options();

        return isset($labels[$status]) ? (string) $labels[$status] : $status;
    }

    private function formatPaymentStatusLabel(string $status): string
    {
        $labels = get_reservation_payment_status_options();

        return isset($labels[$status]) ? (string) $labels[$status] : ($status !== '' ? $status : \__('Unpaid', 'must-hotel-booking'));
    }

    private function formatPaymentMethodLabel(string $method): string
    {
        $method = \sanitize_key($method);
        $options = $this->getPaymentMethodOptions();

        return isset($options[$method]) ? (string) $options[$method] : ($method !== '' ? $method : \__('No payment recorded', 'must-hotel-booking'));
    }

    private function calculateNights(string $checkin, string $checkout): int
    {
        if ($checkin === '' || $checkout === '') {
            return 0;
        }

        try {
            $checkinDate = new \DateTimeImmutable($checkin);
            $checkoutDate = new \DateTimeImmutable($checkout);
        } catch (\Exception $exception) {
            return 0;
        }

        return \max(0, (int) $checkinDate->diff($checkoutDate)->days);
    }

    private function formatMoney(float $amount, string $currency = ''): string
    {
        $resolvedCurrency = $currency !== '' ? $currency : MustBookingConfig::get_currency();

        return \number_format_i18n($amount, 2) . ' ' . $resolvedCurrency;
    }

    private function formatDateTime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            return $value;
        }

        return $date->format('Y-m-d H:i');
    }
}
