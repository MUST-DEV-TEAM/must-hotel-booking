<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Engine\PaymentStatusService;

final class PaymentAdminDataProvider
{
    private \MustHotelBooking\Database\PaymentRepository $paymentRepository;
    private \MustHotelBooking\Database\ReservationRepository $reservationRepository;
    private \MustHotelBooking\Database\ActivityRepository $activityRepository;

    public function __construct()
    {
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
    }

    /**
     * @param array<string, mixed> $saveState
     * @return array<string, mixed>
     */
    public function getPageData(PaymentAdminQuery $query, array $saveState): array
    {
        $filters = $query->getFilters();
        $baseRows = $this->paymentRepository->getAdminPaymentListRows($filters);
        $reservationIds = \array_values(\array_filter(\array_map('intval', \array_column($baseRows, 'id'))));
        $paymentRowsByReservation = $this->paymentRepository->getPaymentsForReservationIds($reservationIds);
        $rows = [];

        foreach ($baseRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
            $state = PaymentStatusService::buildReservationPaymentState($row, $paymentRowsByReservation[$reservationId] ?? []);

            if (!$this->matchesDerivedFilters($state, $filters)) {
                continue;
            }

            $rows[] = $this->formatListRow($row, $state, $query);
        }

        $summaryCards = $this->buildSummaryCards($rows);
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $totalItems = \count($rows);
        $totalPages = (int) \max(1, \ceil($totalItems / $perPage));
        $currentPage = \max(1, \min((int) ($filters['paged'] ?? 1), $totalPages));
        $offset = ($currentPage - 1) * $perPage;
        $pagedRows = \array_slice($rows, $offset, $perPage);

        return [
            'filters' => $filters,
            'summary_cards' => $summaryCards,
            'rows' => $pagedRows,
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => $currentPage,
                'per_page' => $perPage,
            ],
            'detail' => $query->getReservationId() > 0 ? $this->getDetailData($query->getReservationId()) : null,
            'status_options' => $this->getStatusOptions(),
            'reservation_status_options' => get_reservation_status_options(),
            'method_options' => $this->getMethodOptions(),
            'payment_group_options' => $this->getPaymentGroupOptions(),
            'forms' => isset($saveState['forms']) && \is_array($saveState['forms']) ? $saveState['forms'] : [],
            'settings' => $this->getSettingsData(),
            'settings_errors' => isset($saveState['settings_errors']) && \is_array($saveState['settings_errors']) ? $saveState['settings_errors'] : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDetailDataForReservation(int $reservationId): ?array
    {
        return $this->getDetailData($reservationId);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $filters
     */
    private function matchesDerivedFilters(array $state, array $filters): bool
    {
        $statusFilter = isset($filters['status']) ? (string) $filters['status'] : '';

        if ($statusFilter !== '' && $statusFilter !== (string) ($state['derived_status'] ?? '')) {
            return false;
        }

        if (!empty($filters['due_only']) && (float) ($state['amount_due'] ?? 0.0) <= 0.0) {
            return false;
        }

        $group = isset($filters['payment_group']) ? (string) $filters['payment_group'] : '';

        if ($group === '') {
            return true;
        }

        $derivedStatus = (string) ($state['derived_status'] ?? '');
        $needsReview = !empty($state['needs_review']);
        $amountDue = (float) ($state['amount_due'] ?? 0.0);

        if ($group === 'paid') {
            return $derivedStatus === 'paid';
        }

        if ($group === 'due') {
            return $amountDue > 0.0;
        }

        if ($group === 'pay_at_hotel') {
            return $derivedStatus === 'pay_at_hotel';
        }

        if ($group === 'failed_incomplete') {
            return \in_array($derivedStatus, ['failed', 'pending'], true);
        }

        if ($group === 'needs_review') {
            return $needsReview;
        }

        if ($group === 'partial') {
            return $derivedStatus === 'partially_paid';
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function formatListRow(array $row, array $state, PaymentAdminQuery $query): array
    {
        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        $actions = [];

        foreach (['mark_paid', 'mark_unpaid', 'mark_pending', 'mark_pay_at_hotel', 'mark_failed', 'resend_guest_email'] as $action) {
            if (!PaymentStatusService::canTransition($state, $action) && $action !== 'resend_guest_email') {
                continue;
            }

            if ($action === 'resend_guest_email' && $reservationId <= 0) {
                continue;
            }

            $actions[$action] = \wp_nonce_url(
                get_admin_payments_page_url($query->buildUrlArgs([
                    'action' => $action,
                    'reservation_id' => $reservationId,
                ])),
                'must_payment_action_' . $action . '_' . $reservationId
            );
        }

        $detailUrl = get_admin_payments_page_url($query->buildUrlArgs([
            'action' => 'view',
            'reservation_id' => $reservationId,
        ]));

        return [
            'reservation_id' => $reservationId,
            'booking_id' => $this->formatReservationReference($row),
            'guest' => $this->formatGuestName($row),
            'guest_email' => (string) ($row['guest_email'] ?? ''),
            'accommodation' => (string) ($row['room_name'] ?? ''),
            'payment_method' => $this->formatPaymentMethodLabel((string) ($state['method'] ?? '')),
            'payment_method_key' => (string) ($state['method'] ?? ''),
            'payment_status' => $this->formatPaymentStatusLabel((string) ($state['derived_status'] ?? '')),
            'payment_status_key' => (string) ($state['derived_status'] ?? ''),
            'reservation_status' => $this->formatReservationStatusLabel((string) ($row['status'] ?? '')),
            'reservation_status_key' => (string) ($row['status'] ?? ''),
            'total' => $this->formatMoney((float) ($state['total'] ?? 0.0)),
            'amount_paid' => $this->formatMoney((float) ($state['amount_paid'] ?? 0.0)),
            'amount_due' => $this->formatMoney((float) ($state['amount_due'] ?? 0.0)),
            'amount_due_raw' => (float) ($state['amount_due'] ?? 0.0),
            'has_due' => (float) ($state['amount_due'] ?? 0.0) > 0.0,
            'transaction_id' => (string) ($state['transaction_id'] ?? ''),
            'paid_at' => $this->formatDateTime((string) ($state['paid_at'] ?? '')),
            'updated_at' => $this->formatDateTime((string) ($state['created_at'] ?? '')),
            'warnings' => isset($state['warnings']) && \is_array($state['warnings']) ? $state['warnings'] : [],
            'needs_review' => !empty($state['needs_review']),
            'detail_url' => $detailUrl,
            'reservation_url' => get_admin_reservation_detail_page_url($reservationId),
            'actions' => $actions,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $rows): array
    {
        $paid = 0;
        $unpaid = 0;
        $payAtHotel = 0;
        $failed = 0;
        $review = 0;
        $dueAmount = 0.0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $status = (string) ($row['payment_status_key'] ?? '');

            if ($status === 'paid') {
                $paid++;
            } elseif ($status === 'pay_at_hotel') {
                $payAtHotel++;
            } elseif (\in_array($status, ['failed', 'pending'], true)) {
                $failed++;
            } else {
                $unpaid++;
            }

            if (!empty($row['needs_review'])) {
                $review++;
            }

            $dueAmount += isset($row['amount_due_raw']) ? (float) $row['amount_due_raw'] : 0.0;
        }

        return [
            [
                'label' => \__('Paid', 'must-hotel-booking'),
                'value' => (string) $paid,
                'meta' => \__('Reservations fully covered by payments.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Unpaid / Due', 'must-hotel-booking'),
                'value' => (string) $unpaid,
                'meta' => \sprintf(\__('Outstanding due total: %s', 'must-hotel-booking'), $this->formatMoney($dueAmount)),
            ],
            [
                'label' => \__('Pay At Hotel', 'must-hotel-booking'),
                'value' => (string) $payAtHotel,
                'meta' => \__('Confirmed reservations awaiting on-property collection.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Failed / Incomplete', 'must-hotel-booking'),
                'value' => (string) $failed,
                'meta' => \__('Stripe failures, pending sessions, and manual follow-up.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Needs Review', 'must-hotel-booking'),
                'value' => (string) $review,
                'meta' => \__('Inconsistent reservation and payment states.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDetailData(int $reservationId): ?array
    {
        $reservation = $this->reservationRepository->getAdminReservationDetails($reservationId);

        if (!\is_array($reservation)) {
            return null;
        }

        $paymentRows = $this->paymentRepository->getPaymentsForReservation($reservationId);
        $state = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
        $activityRows = $this->activityRepository->getRecentActivitiesForReservation($reservationId, $this->formatReservationReference($reservation), 20);
        $timeline = [];

        foreach ($activityRows as $activityRow) {
            if (!\is_array($activityRow)) {
                continue;
            }

            $timeline[] = [
                'event_type' => (string) ($activityRow['event_type'] ?? ''),
                'severity' => (string) ($activityRow['severity'] ?? 'info'),
                'message' => (string) ($activityRow['message'] ?? ''),
                'created_at' => $this->formatDateTime((string) ($activityRow['created_at'] ?? '')),
                'reference' => (string) ($activityRow['reference'] ?? ''),
            ];
        }

        return [
            'reservation_id' => $reservationId,
            'booking_id' => $this->formatReservationReference($reservation),
            'reservation' => $reservation,
            'state' => $state,
            'guest_name' => $this->formatGuestName($reservation),
            'guest_email' => (string) ($reservation['email'] ?? ''),
            'guest_phone' => (string) ($reservation['phone'] ?? ''),
            'accommodation' => isset($reservation['room_name']) && (string) $reservation['room_name'] !== ''
                ? (string) $reservation['room_name']
                : \__('Unassigned', 'must-hotel-booking'),
            'payments' => $paymentRows,
            'timeline' => $timeline,
            'reservation_url' => get_admin_reservation_detail_page_url($reservationId),
            'settings_url' => get_admin_payments_page_url(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettingsData(): array
    {
        return [
            'catalog' => PaymentMethodRegistry::getCatalog(),
            'states' => PaymentMethodRegistry::getStates(),
            'environment_catalog' => PaymentEngine::getStripeEnvironmentCatalog(),
            'active_environment' => PaymentEngine::getActiveSiteEnvironment(),
            'webhook_url' => PaymentEngine::getStripeWebhookUrl(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getStatusOptions(): array
    {
        return [
            '' => \__('All payment statuses', 'must-hotel-booking'),
            'paid' => \__('Paid', 'must-hotel-booking'),
            'unpaid' => \__('Unpaid', 'must-hotel-booking'),
            'partially_paid' => \__('Partially Paid', 'must-hotel-booking'),
            'pending' => \__('Pending', 'must-hotel-booking'),
            'failed' => \__('Failed', 'must-hotel-booking'),
            'refunded' => \__('Refunded', 'must-hotel-booking'),
            'pay_at_hotel' => \__('Pay At Hotel', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getPaymentGroupOptions(): array
    {
        return [
            '' => \__('All operational groups', 'must-hotel-booking'),
            'paid' => \__('Paid only', 'must-hotel-booking'),
            'due' => \__('Amount due', 'must-hotel-booking'),
            'partial' => \__('Partial payments', 'must-hotel-booking'),
            'pay_at_hotel' => \__('Pay at hotel', 'must-hotel-booking'),
            'failed_incomplete' => \__('Failed or incomplete', 'must-hotel-booking'),
            'needs_review' => \__('Needs review', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getMethodOptions(): array
    {
        $options = ['' => \__('All payment methods', 'must-hotel-booking')];

        foreach (PaymentMethodRegistry::getCatalog() as $key => $meta) {
            $options[$key] = isset($meta['label']) ? (string) $meta['label'] : (string) $key;
        }

        if (!isset($options['pay_at_hotel'])) {
            $options['pay_at_hotel'] = \__('Pay at hotel', 'must-hotel-booking');
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function formatReservationReference(array $row): string
    {
        $bookingId = isset($row['booking_id']) ? \trim((string) $row['booking_id']) : '';

        if ($bookingId !== '') {
            return $bookingId;
        }

        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;

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

    private function formatPaymentMethodLabel(string $method): string
    {
        $method = \sanitize_key($method);
        $catalog = PaymentMethodRegistry::getCatalog();

        if (isset($catalog[$method]['label'])) {
            return (string) $catalog[$method]['label'];
        }

        if ($method === 'pay_at_hotel') {
            return \__('Pay at hotel', 'must-hotel-booking');
        }

        return $method !== '' ? $method : \__('No payment recorded', 'must-hotel-booking');
    }

    private function formatPaymentStatusLabel(string $status): string
    {
        $labels = [
            'paid' => \__('Paid', 'must-hotel-booking'),
            'unpaid' => \__('Unpaid', 'must-hotel-booking'),
            'partially_paid' => \__('Partially Paid', 'must-hotel-booking'),
            'pending' => \__('Pending', 'must-hotel-booking'),
            'failed' => \__('Failed', 'must-hotel-booking'),
            'refunded' => \__('Refunded', 'must-hotel-booking'),
            'pay_at_hotel' => \__('Pay At Hotel', 'must-hotel-booking'),
        ];

        return isset($labels[$status]) ? $labels[$status] : ($status !== '' ? $status : \__('Unpaid', 'must-hotel-booking'));
    }

    private function formatReservationStatusLabel(string $status): string
    {
        $labels = get_reservation_status_options();

        return isset($labels[$status]) ? (string) $labels[$status] : $status;
    }

    private function formatMoney(float $amount): string
    {
        return \number_format_i18n($amount, 2) . ' ' . MustBookingConfig::get_currency();
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
