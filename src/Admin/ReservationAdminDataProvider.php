<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;

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

    public function __construct()
    {
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->ratePlanRepository = \MustHotelBooking\Engine\get_rate_plan_repository();
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function getListPageData(array $request): array
    {
        $filters = $this->normalizeListFilters($request);
        $totalItems = $this->reservationRepository->countAdminReservationListRows($filters);
        $totalPages = (int) \max(1, \ceil($totalItems / $filters['per_page']));
        $filters['paged'] = \max(1, \min($filters['paged'], $totalPages));
        $rows = $this->reservationRepository->getAdminReservationListRows($filters);

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
        $assignedRoomId = isset($reservation['assigned_room_id']) ? (int) $reservation['assigned_room_id'] : 0;
        $assignedRoom = $assignedRoomId > 0 ? $this->inventoryRepository->getInventoryRoomById($assignedRoomId) : null;
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
        $amountPaid = isset($paymentSummary['amount_paid']) ? (float) $paymentSummary['amount_paid'] : 0.0;
        $amountDue = \max(0.0, $totalPrice - $amountPaid);

        return [
            'id' => $reservationId,
            'mode' => $mode === 'edit' ? 'edit' : 'view',
            'booking_id' => $bookingId,
            'reservation' => $reservation,
            'summary' => [
                'reservation_status' => $this->formatReservationStatusLabel((string) ($reservation['status'] ?? '')),
                'reservation_status_key' => (string) ($reservation['status'] ?? ''),
                'payment_status' => $this->formatPaymentStatusLabel((string) ($reservation['payment_status'] ?? '')),
                'payment_status_key' => (string) ($reservation['payment_status'] ?? ''),
                'payment_method' => $this->formatPaymentMethodLabel((string) ($paymentSummary['latest_method'] ?? '')),
                'payment_method_key' => (string) ($paymentSummary['latest_method'] ?? ''),
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
                'edit_notice' => \__('Stay dates and accommodation are currently managed from booking/calendar workflows until reservation revalidation is promoted into a dedicated admin edit service.', 'must-hotel-booking'),
            ],
            'pricing' => [
                'currency' => MustBookingConfig::get_currency(),
                'stored_total' => $totalPrice,
                'amount_paid' => $amountPaid,
                'amount_due' => $amountDue,
            ],
            'payments' => $this->formatPaymentRows($paymentRows),
            'payment_summary' => $paymentSummary,
            'emails' => $emailRows,
            'timeline' => $timelineRows,
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
        $counts = $this->reservationRepository->getAdminReservationQuickFilterCounts((string) $filters['today']);
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
    private function formatListRows(array $rows): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
            $formatted[] = [
                'id' => $reservationId,
                'booking_id' => $this->formatReservationReference($row),
                'guest' => $this->formatGuestName($row),
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
                'payment_status' => $this->formatPaymentStatusLabel((string) ($row['payment_status'] ?? '')),
                'payment_status_key' => (string) ($row['payment_status'] ?? ''),
                'payment_method' => $this->formatPaymentMethodLabel((string) ($row['payment_method'] ?? '')),
                'payment_method_key' => (string) ($row['payment_method'] ?? ''),
                'total' => $this->formatMoney((float) ($row['total_price'] ?? 0)),
                'created' => $this->formatDateTime((string) ($row['created_at'] ?? '')),
            ];
        }

        return $formatted;
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
