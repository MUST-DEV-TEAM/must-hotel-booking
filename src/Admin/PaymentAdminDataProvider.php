<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Engine\PaymentStatusService;
use MustHotelBooking\Provider\ProviderReservationView;

final class PaymentAdminDataProvider
{
    private \MustHotelBooking\Database\PaymentRepository $paymentRepository;
    private \MustHotelBooking\Database\RefundRepository $refundRepository;
    private \MustHotelBooking\Database\ClockFolioAccountingRepository $clockFolioAccountingRepository;
    private \MustHotelBooking\Database\ReservationRepository $reservationRepository;
    private \MustHotelBooking\Database\ActivityRepository $activityRepository;

    public function __construct()
    {
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->refundRepository = \MustHotelBooking\Engine\get_refund_repository();
        $this->clockFolioAccountingRepository = \MustHotelBooking\Engine\get_clock_folio_accounting_repository();
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
            $providerPayment = ProviderReservationView::paymentContext($row, $state);
            $clockAccountingRows = $reservationId > 0 ? $this->clockFolioAccountingRepository->getForReservation($reservationId) : [];
            $refundRows = $reservationId > 0 ? $this->refundRepository->getRefundsForReservation($reservationId) : [];
            $clockAccountingStatuses = [];

            foreach ($clockAccountingRows as $clockAccountingRow) {
                if (!\is_array($clockAccountingRow)) {
                    continue;
                }

                $clockAccountingStatuses[] = \sanitize_key((string) ($clockAccountingRow['status'] ?? ''));
            }

            $state['clock_accounting_statuses'] = \array_values(\array_filter(\array_unique($clockAccountingStatuses)));

            if (ProviderReservationView::isProviderBacked($row) && !empty($providerPayment['needs_attention'])) {
                $state['needs_review'] = true;
            }

            if (!empty(\array_intersect((array) $state['clock_accounting_statuses'], ['failed', 'manual_review']))) {
                $state['needs_review'] = true;
            }

            foreach ($refundRows as $refundRow) {
                if (\sanitize_key((string) ($refundRow['status'] ?? '')) === 'refund_review_required') {
                    $state['needs_review'] = true;
                    break;
                }
            }

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

        if (\strpos($group, 'clock_accounting_') === 0) {
            $clockStatus = \substr($group, \strlen('clock_accounting_'));
            $clockStatuses = isset($state['clock_accounting_statuses']) && \is_array($state['clock_accounting_statuses'])
                ? $state['clock_accounting_statuses']
                : [];

            return \in_array($clockStatus, \array_map('strval', $clockStatuses), true);
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
        $providerContext = ProviderReservationView::metadata($row);
        $isProviderBacked = !empty($providerContext['is_provider_backed']);
        $actions = [];

        if (!$isProviderBacked) {
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
        }

        $detailUrl = get_admin_payments_page_url($query->buildUrlArgs([
            'action' => 'view',
            'reservation_id' => $reservationId,
        ]));
        $warnings = isset($state['warnings']) && \is_array($state['warnings']) ? $state['warnings'] : [];
        $providerPayment = ProviderReservationView::paymentContext($row, $state);

        if ($isProviderBacked) {
            $warnings[] = \__('Provider-backed payment mutations are read-only in this local payment workspace.', 'must-hotel-booking');

            if (!empty($providerPayment['differs'])) {
                $warnings[] = \__('Provider payment status differs from the local plugin payment state.', 'must-hotel-booking');
            }

            if (!empty($providerPayment['reconciliation_required'])) {
                $warnings[] = \__('Provider payment reconciliation is pending or failed.', 'must-hotel-booking');
            }
        }

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
            'warnings' => $warnings,
            'needs_review' => !empty($state['needs_review']),
            'provider' => $providerContext,
            'provider_payment' => $providerPayment,
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
                'meta' => \__('Online payment failures, pending sessions, and manual follow-up.', 'must-hotel-booking'),
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
        $refundRows = $this->refundRepository->getRefundsForReservation($reservationId);
        $clockAccountingRows = $this->clockFolioAccountingRepository->getForReservation($reservationId);
        $state = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
        $providerContext = ProviderReservationView::metadata($reservation);
        $providerPayment = ProviderReservationView::paymentContext($reservation, $state);

        if (!empty($providerContext['is_provider_backed'])) {
            if (!isset($state['warnings']) || !\is_array($state['warnings'])) {
                $state['warnings'] = [];
            }

            $state['warnings'][] = \__('Provider-backed payment mutations are read-only in this local payment workspace.', 'must-hotel-booking');

            if (!empty($providerPayment['differs'])) {
                $state['warnings'][] = \__('Provider payment status differs from the local plugin payment state.', 'must-hotel-booking');
            }

            if (!empty($providerPayment['reconciliation_required'])) {
                $state['warnings'][] = \__('Provider payment reconciliation is pending or failed.', 'must-hotel-booking');
            }
        }

        foreach ($refundRows as $refundRow) {
            $refundStatus = \sanitize_key((string) ($refundRow['status'] ?? ''));
            if ($refundStatus === 'refund_review_required') {
                $state['warnings'][] = \__('Cancelled paid booking needs a staff refund decision. Choose no refund, partial refund, full refund, or issue a refund-and-cancel action from the refund controls.', 'must-hotel-booking');
                continue;
            }

            $clockSyncStatus = \sanitize_key((string) ($refundRow['clock_sync_status'] ?? ''));
            if (\in_array($clockSyncStatus, ['failed', 'manual_review', 'retrying'], true)) {
                $state['warnings'][] = \__('Gateway refund succeeded but failed to sync to Clock. Retry sync or mark for manual review.', 'must-hotel-booking');
                break;
            }
        }

        foreach ($paymentRows as $paymentRow) {
            $method = \sanitize_key((string) ($paymentRow['method'] ?? ''));
            $status = \sanitize_key((string) ($paymentRow['status'] ?? ''));
            $feeStatus = \sanitize_key((string) ($paymentRow['provider_fee_status'] ?? ''));

            if (\in_array($method, ['stripe', 'pokpay'], true) && $status === 'paid' && $feeStatus !== 'known') {
                $state['warnings'][] = \__('Provider fee is unknown for this paid online payment. Default refunds need manual review until the fee snapshot is available.', 'must-hotel-booking');
                break;
            }
        }

        foreach ($clockAccountingRows as $clockAccountingRow) {
            $clockAccountingStatus = \sanitize_key((string) ($clockAccountingRow['status'] ?? ''));
            $verificationStatus = \sanitize_key((string) ($clockAccountingRow['verification_status'] ?? ''));

            if (\in_array($clockAccountingStatus, ['failed', 'manual_review'], true)) {
                $state['warnings'][] = \__('Clock folio accounting needs admin review.', 'must-hotel-booking');
                break;
            }

            if ($clockAccountingStatus === 'posted' && \in_array($verificationStatus, ['unknown', 'balance_remaining'], true)) {
                $state['warnings'][] = \__('Clock folio accounting was posted, but balance verification needs review.', 'must-hotel-booking');
                break;
            }
        }

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
            'refunds' => $refundRows,
            'clock_accounting' => $clockAccountingRows,
            'timeline' => $timeline,
            'provider' => $providerContext,
            'provider_payment' => $providerPayment,
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
            'public_callback_base_url' => MustBookingConfig::get_public_callback_base_url(),
            'webhook_url' => PaymentEngine::getStripeWebhookUrl(),
            'pokpay_webhook_url' => MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook'),
            'pokpay_environment' => PaymentEngine::getPokPayApiEnvironment(),
            'pokpay_checkout_mode' => MustBookingConfig::get_pokpay_checkout_mode(),
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
            'clock_accounting_manual_review' => \__('Clock accounting: manual review', 'must-hotel-booking'),
            'clock_accounting_failed' => \__('Clock accounting: failed', 'must-hotel-booking'),
            'clock_accounting_handled_manually' => \__('Clock accounting: handled manually', 'must-hotel-booking'),
            'clock_accounting_posted' => \__('Clock accounting: posted', 'must-hotel-booking'),
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
