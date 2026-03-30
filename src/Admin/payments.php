<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Engine\PaymentEngine;

/**
 * @param array<string, scalar|int|bool> $args
 */
function get_admin_payments_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-payments');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

/**
 * @return array<string, array<string, string>>
 */
function get_payment_methods_catalog(): array
{
    return PaymentMethodRegistry::getCatalog();
}

/**
 * @return array<string, bool>
 */
function get_payment_method_states(): array
{
    return PaymentMethodRegistry::getStates();
}

/**
 * @return array<int, string>
 */
function get_enabled_payment_methods(): array
{
    return PaymentMethodRegistry::getEnabled();
}

function is_payments_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-payments';
}

/**
 * @return array<string, mixed>
 */
function get_payments_admin_save_state(): array
{
    global $mustHotelBookingPaymentsAdminSaveState;

    if (isset($mustHotelBookingPaymentsAdminSaveState) && \is_array($mustHotelBookingPaymentsAdminSaveState)) {
        return $mustHotelBookingPaymentsAdminSaveState;
    }

    $mustHotelBookingPaymentsAdminSaveState = [
        'errors' => [],
        'settings_errors' => [],
    ];

    return $mustHotelBookingPaymentsAdminSaveState;
}

/**
 * @param array<string, mixed> $state
 */
function set_payments_admin_save_state(array $state): void
{
    global $mustHotelBookingPaymentsAdminSaveState;
    $mustHotelBookingPaymentsAdminSaveState = $state;
}

function clear_payments_admin_save_state(): void
{
    set_payments_admin_save_state([
        'errors' => [],
        'settings_errors' => [],
    ]);
}

function maybe_handle_payments_admin_actions_early(): void
{
    if (!is_payments_admin_request()) {
        return;
    }

    ensure_admin_capability();
    $query = PaymentAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod === 'POST') {
        set_payments_admin_save_state((new PaymentAdminActions())->handleSaveRequest($query));
        return;
    }

    (new PaymentAdminActions())->handleGetAction($query);
}

function render_payments_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'payment_settings_saved' => ['success', \__('Payment settings saved successfully.', 'must-hotel-booking')],
        'payment_settings_save_failed' => ['error', \__('Unable to save payment settings.', 'must-hotel-booking')],
        'payment_marked_paid' => ['success', \__('Reservation payment marked paid.', 'must-hotel-booking')],
        'payment_marked_unpaid' => ['success', \__('Reservation payment marked unpaid.', 'must-hotel-booking')],
        'payment_marked_pending' => ['success', \__('Reservation payment moved to pending.', 'must-hotel-booking')],
        'payment_marked_pay_at_hotel' => ['success', \__('Reservation updated to pay at hotel.', 'must-hotel-booking')],
        'payment_marked_failed' => ['success', \__('Reservation payment marked failed.', 'must-hotel-booking')],
        'reservation_guest_email_resent' => ['success', \__('Guest confirmation email resent.', 'must-hotel-booking')],
        'reservation_admin_email_resent' => ['success', \__('Admin notification email resent.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
        'action_failed' => ['error', \__('The requested payment action could not be completed.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $message = $messages[$notice];
    $class = (string) $message[0] === 'success' ? 'notice notice-success' : 'notice notice-error';
    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html((string) $message[1]) . '</p></div>';
}

/**
 * @param array<int, string> $errors
 */
function render_payments_error_notice(array $errors): void
{
    if (empty($errors)) {
        return;
    }

    echo '<div class="notice notice-error"><ul>';
    foreach ($errors as $error) {
        echo '<li>' . \esc_html((string) $error) . '</li>';
    }
    echo '</ul></div>';
}

/**
 * @param array<int, array<string, string>> $summaryCards
 */
function render_payments_summary_cards(array $summaryCards): void
{
    if (\function_exists(__NAMESPACE__ . '\render_dashboard_kpi_cards')) {
        $dashboardCards = [];

        foreach ($summaryCards as $card) {
            if (!\is_array($card)) {
                continue;
            }

            $dashboardCards[] = [
                'label' => (string) ($card['label'] ?? ''),
                'value' => (string) ($card['value'] ?? '0'),
                'descriptor' => (string) ($card['meta'] ?? ''),
            ];
        }

        render_dashboard_kpi_cards($dashboardCards);

        return;
    }

    echo '<div class="must-dashboard-kpis">';

    foreach ($summaryCards as $card) {
        if (!\is_array($card)) {
            continue;
        }

        echo '<article class="must-dashboard-kpi-card">';
        echo '<span class="must-dashboard-kpi-label">' . \esc_html((string) ($card['label'] ?? '')) . '</span>';
        echo '<strong class="must-dashboard-kpi-value">' . \esc_html((string) ($card['value'] ?? '0')) . '</strong>';
        echo '<span class="must-dashboard-kpi-descriptor">' . \esc_html((string) ($card['meta'] ?? '')) . '</span>';
        echo '</article>';
    }

    echo '</div>';
}

function get_payments_badge_tone(string $tone): string
{
    $tone = \sanitize_key($tone);

    if (\in_array($tone, ['ok', 'success', 'paid', 'confirmed', 'completed'], true)) {
        return 'ok';
    }

    if (\in_array($tone, ['warning', 'unpaid', 'pending', 'partially_paid', 'needs_review'], true)) {
        return 'warning';
    }

    if (\in_array($tone, ['error', 'failed', 'payment_failed'], true)) {
        return 'error';
    }

    if (\in_array($tone, ['muted', 'refunded', 'cancelled', 'expired'], true)) {
        return 'muted';
    }

    return 'info';
}

function render_payments_badge(string $label, string $tone = 'info'): string
{
    $resolvedTone = get_payments_badge_tone($tone);
    $classMap = [
        'ok' => 'is-ok',
        'warning' => 'is-warning',
        'error' => 'is-error',
        'info' => 'is-info',
        'muted' => 'is-muted',
    ];
    $className = isset($classMap[$resolvedTone]) ? $classMap[$resolvedTone] : $classMap['info'];

    return '<span class="must-dashboard-status-badge is-compact must-payments-badge ' . \esc_attr($className) . '">' . \esc_html($label) . '</span>';
}

function render_payments_status_badge(string $label, string $tone): string
{
    if (\function_exists(__NAMESPACE__ . '\render_admin_reservation_badge')) {
        return render_admin_reservation_badge($label, $tone);
    }

    return render_payments_badge($label, $tone);
}

/**
 * @param array<int, array<string, string>> $actions
 */
function render_payments_empty_state(string $title, string $message, array $actions = []): void
{
    echo '<div class="must-payments-empty-state">';
    echo '<div class="must-payments-empty-state-copy">';
    echo '<h3>' . \esc_html($title) . '</h3>';
    echo '<p>' . \esc_html($message) . '</p>';
    echo '</div>';

    if (!empty($actions)) {
        echo '<div class="must-payments-empty-actions">';

        foreach ($actions as $action) {
            if (!\is_array($action) || empty($action['url'])) {
                continue;
            }

            echo '<a class="' . \esc_attr((string) ($action['class'] ?? 'button')) . '" href="' . \esc_url((string) $action['url']) . '">' . \esc_html((string) ($action['label'] ?? 'Open')) . '</a>';
        }

        echo '</div>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, string> $statusOptions
 * @param array<string, string> $reservationStatusOptions
 * @param array<string, string> $methodOptions
 * @param array<string, string> $paymentGroupOptions
 * @return array<int, array<string, string>>
 */
function get_payments_filter_chips(array $filters, array $statusOptions, array $reservationStatusOptions, array $methodOptions, array $paymentGroupOptions): array
{
    $chips = [];

    if (!empty($filters['search'])) {
        $chips[] = [
            'label' => \__('Search', 'must-hotel-booking'),
            'value' => (string) $filters['search'],
        ];
    }

    $maps = [
        'status' => [
            'label' => \__('Payment status', 'must-hotel-booking'),
            'values' => $statusOptions,
        ],
        'payment_group' => [
            'label' => \__('Operational group', 'must-hotel-booking'),
            'values' => $paymentGroupOptions,
        ],
        'method' => [
            'label' => \__('Payment method', 'must-hotel-booking'),
            'values' => $methodOptions,
        ],
        'reservation_status' => [
            'label' => \__('Reservation status', 'must-hotel-booking'),
            'values' => $reservationStatusOptions,
        ],
    ];

    foreach ($maps as $key => $map) {
        $value = isset($filters[$key]) ? (string) $filters[$key] : '';

        if ($value === '' || !isset($map['values'][$value])) {
            continue;
        }

        $chips[] = [
            'label' => (string) $map['label'],
            'value' => (string) $map['values'][$value],
        ];
    }

    $dateFrom = isset($filters['date_from']) ? (string) $filters['date_from'] : '';
    $dateTo = isset($filters['date_to']) ? (string) $filters['date_to'] : '';

    if ($dateFrom !== '' || $dateTo !== '') {
        $rangeLabel = $dateFrom !== '' && $dateTo !== ''
            ? $dateFrom . ' - ' . $dateTo
            : ($dateFrom !== '' ? \sprintf(__('From %s', 'must-hotel-booking'), $dateFrom) : \sprintf(__('Until %s', 'must-hotel-booking'), $dateTo));
        $chips[] = [
            'label' => \__('Date range', 'must-hotel-booking'),
            'value' => $rangeLabel,
        ];
    }

    if (!empty($filters['due_only'])) {
        $chips[] = [
            'label' => \__('Flag', 'must-hotel-booking'),
            'value' => \__('Amount due only', 'must-hotel-booking'),
        ];
    }

    return $chips;
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, string> $statusOptions
 * @param array<string, string> $reservationStatusOptions
 * @param array<string, string> $methodOptions
 * @param array<string, string> $paymentGroupOptions
 */
function render_payments_filter_chips(array $filters, array $statusOptions, array $reservationStatusOptions, array $methodOptions, array $paymentGroupOptions): void
{
    $chips = get_payments_filter_chips($filters, $statusOptions, $reservationStatusOptions, $methodOptions, $paymentGroupOptions);

    if (empty($chips)) {
        return;
    }

    echo '<div class="must-payments-filter-chips">';

    foreach ($chips as $chip) {
        echo '<span class="must-payments-filter-chip"><strong>' . \esc_html((string) ($chip['label'] ?? '')) . '</strong> ' . \esc_html((string) ($chip['value'] ?? '')) . '</span>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_payments_page_header(PaymentAdminQuery $query, array $pageData): void
{
    $rows = isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : [];
    $pagination = isset($pageData['pagination']) && \is_array($pageData['pagination']) ? $pageData['pagination'] : [];
    $summaryCards = isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : [];
    $settings = isset($pageData['settings']) && \is_array($pageData['settings']) ? $pageData['settings'] : [];
    $states = isset($settings['states']) && \is_array($settings['states']) ? $settings['states'] : [];
    $activeEnvironment = isset($settings['active_environment']) ? (string) $settings['active_environment'] : '';
    $activeEnvironmentLabel = $activeEnvironment !== '' ? PaymentEngine::getSiteEnvironmentLabel($activeEnvironment) : \__('Not configured', 'must-hotel-booking');
    $enabledMethodCount = \count(\array_filter($states));
    $reviewCount = isset($summaryCards[4]['value']) ? (string) $summaryCards[4]['value'] : '0';
    $totalItems = isset($pagination['total_items']) ? (int) $pagination['total_items'] : \count($rows);
    $basePageUrl = get_admin_payments_page_url($query->buildUrlArgs());

    echo '<header class="must-dashboard-hero must-payments-page-header">';
    echo '<div class="must-dashboard-hero-copy">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Payments Ledger Workspace', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Payments', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage paid, unpaid, pay-at-hotel, failed, and incomplete booking payments from the same ledger and reservation state used by checkout, Stripe sync, and booking emails.', 'must-hotel-booking') . '</p>';
    echo '<div class="must-dashboard-hero-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Visible Rows', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Filtered Matches', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $totalItems) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Active Stripe Profile', 'must-hotel-booking') . '</strong> ' . \esc_html($activeEnvironmentLabel) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Enabled Methods', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $enabledMethodCount) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Needs Review', 'must-hotel-booking') . '</strong> ' . \esc_html($reviewCount) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-dashboard-hero-actions">';
    echo '<a class="button button-primary" href="' . \esc_url(get_admin_reservations_page_url(['preset' => 'unpaid'])) . '">' . \esc_html__('Open Unpaid Reservations', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Open Emails', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_settings_page_url(['tab' => 'general'])) . '">' . \esc_html__('Open Settings', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url($basePageUrl . '#must-payment-settings') . '">' . \esc_html__('Gateway Settings', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</header>';
}

function render_payments_section_navigation(PaymentAdminQuery $query, ?array $detail): void
{
    if (!\function_exists(__NAMESPACE__ . '\render_dashboard_action_strip')) {
        return;
    }

    $basePageUrl = get_admin_payments_page_url($query->buildUrlArgs());
    $actions = [
        [
            'label' => \__('Filters', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-payments-filters',
        ],
        [
            'label' => \__('Ledger', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-payments-ledger',
        ],
        [
            'label' => \__('Settings', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-payment-settings',
        ],
    ];

    if (\is_array($detail)) {
        $actions[] = [
            'label' => \__('Detail', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-payment-detail',
        ];
    }

    render_dashboard_action_strip($actions);
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, string> $statusOptions
 * @param array<string, string> $reservationStatusOptions
 * @param array<string, string> $methodOptions
 * @param array<string, string> $paymentGroupOptions
 */
function render_payments_filters(array $filters, array $statusOptions, array $reservationStatusOptions, array $methodOptions, array $paymentGroupOptions, array $pagination, int $visibleCount): void
{
    $totalItems = isset($pagination['total_items']) ? (int) $pagination['total_items'] : $visibleCount;

    echo '<section id="must-payments-filters" class="postbox must-dashboard-panel must-payments-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Filter Payment Workspace', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Search by booking, guest, Stripe reference, or reservation state, then narrow the ledger by payment status, collection mode, and due exposure.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-payments-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Matches', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $totalItems) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Visible', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $visibleCount) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Reset Filters', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '" class="must-payments-toolbar">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-payments" />';
    echo '<div class="must-payments-toolbar-grid">';
    echo '<label class="must-payments-toolbar-field is-wide"><span>' . \esc_html__('Search', 'must-hotel-booking') . '</span><input id="must-payment-filter-search" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Booking ID, guest, email, Stripe reference', 'must-hotel-booking') . '" /><small>' . \esc_html__('Search reservations, guests, gateway references, and payment metadata already stored in the ledger.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-payments-toolbar-field"><span>' . \esc_html__('Payment status', 'must-hotel-booking') . '</span><select id="must-payment-filter-status" name="status">';
    foreach ($statusOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['status'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-payments-toolbar-field"><span>' . \esc_html__('Operational group', 'must-hotel-booking') . '</span><select id="must-payment-filter-group" name="payment_group">';
    foreach ($paymentGroupOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['payment_group'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-payments-toolbar-field"><span>' . \esc_html__('Payment method', 'must-hotel-booking') . '</span><select id="must-payment-filter-method" name="method">';
    foreach ($methodOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['method'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-payments-toolbar-field"><span>' . \esc_html__('Reservation status', 'must-hotel-booking') . '</span><select id="must-payment-filter-reservation-status" name="reservation_status"><option value="">' . \esc_html__('All reservation statuses', 'must-hotel-booking') . '</option>';
    foreach ($reservationStatusOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['reservation_status'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-payments-toolbar-field"><span>' . \esc_html__('Date from', 'must-hotel-booking') . '</span><input id="must-payment-filter-date-from" type="date" name="date_from" value="' . \esc_attr((string) ($filters['date_from'] ?? '')) . '" /><small>' . \esc_html__('Uses payment timestamps when available, or the latest payment activity stored for the reservation.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-payments-toolbar-field"><span>' . \esc_html__('Date to', 'must-hotel-booking') . '</span><input id="must-payment-filter-date-to" type="date" name="date_to" value="' . \esc_attr((string) ($filters['date_to'] ?? '')) . '" /></label>';
    echo '<label class="must-payments-toolbar-flag"><input type="checkbox" name="due_only" value="1"' . \checked(!empty($filters['due_only']), true, false) . ' /><span class="must-payments-toolbar-flag-copy"><strong>' . \esc_html__('Only show reservations with amount due', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Keeps the ledger focused on collection work instead of fully covered stays.', 'must-hotel-booking') . '</small></span></label>';
    echo '<div class="must-payments-toolbar-actions"><button type="submit" class="button button-primary">' . \esc_html__('Apply Filters', 'must-hotel-booking') . '</button><a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Clear', 'must-hotel-booking') . '</a></div>';
    echo '</div>';
    echo '</form>';
    render_payments_filter_chips($filters, $statusOptions, $reservationStatusOptions, $methodOptions, $paymentGroupOptions);
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function render_payments_table(array $rows, array $pagination): void
{
    $totalItems = isset($pagination['total_items']) ? (int) $pagination['total_items'] : \count($rows);

    echo '<section id="must-payments-ledger" class="postbox must-dashboard-panel must-payments-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Payments Overview', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review payment exposure, ledger references, reservation states, and follow-up actions from one cleaner operations table.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-payments-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Rows', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Total Matches', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $totalItems) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="#must-payment-settings">' . \esc_html__('Gateway Settings', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    if (empty($rows)) {
        render_payments_empty_state(
            \__('No reservations matched the current payment filters.', 'must-hotel-booking'),
            \__('Reset the ledger filters or switch to the unpaid reservations queue if the current view is too narrow for the operational task you are handling.', 'must-hotel-booking'),
            [
                [
                    'label' => \__('Reset Filters', 'must-hotel-booking'),
                    'url' => get_admin_payments_page_url(),
                    'class' => 'button button-primary',
                ],
                [
                    'label' => \__('Open Unpaid Reservations', 'must-hotel-booking'),
                    'url' => get_admin_reservations_page_url(['preset' => 'unpaid']),
                    'class' => 'button must-dashboard-header-link',
                ],
            ]
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-payments-table-wrap">';
    echo '<table class="widefat striped must-payments-data-table"><thead><tr>';
    echo '<th>' . \esc_html__('Reservation', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Method', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Payment Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reservation Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Total', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Paid', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Due', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Updated', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $methodLabel = (string) ($row['payment_method'] ?? '');
        $methodKey = (string) ($row['payment_method_key'] ?? '');
        $transactionId = (string) ($row['transaction_id'] ?? '');
        $updatedAt = (string) (($row['paid_at'] ?? '') !== '' ? $row['paid_at'] : ($row['updated_at'] ?? ''));
        $labels = [
            'mark_paid' => \__('Mark Paid', 'must-hotel-booking'),
            'mark_unpaid' => \__('Mark Unpaid', 'must-hotel-booking'),
            'mark_pending' => \__('Mark Pending', 'must-hotel-booking'),
            'mark_pay_at_hotel' => \__('Pay At Hotel', 'must-hotel-booking'),
            'mark_failed' => \__('Mark Failed', 'must-hotel-booking'),
            'resend_guest_email' => \__('Resend Guest Email', 'must-hotel-booking'),
        ];

        echo '<tr>';

        echo '<td>';
        echo '<div class="must-payments-row-title"><a href="' . \esc_url((string) ($row['detail_url'] ?? '')) . '">' . \esc_html((string) ($row['booking_id'] ?? '')) . '</a></div>';
        echo '<div class="must-payments-row-meta"><span>' . \esc_html(\sprintf(__('Reservation #%d', 'must-hotel-booking'), (int) ($row['reservation_id'] ?? 0))) . '</span></div>';
        if ((string) ($row['reservation_url'] ?? '') !== '') {
            echo '<div class="must-payments-cell-links"><a class="must-payments-text-link" href="' . \esc_url((string) $row['reservation_url']) . '">' . \esc_html__('Open reservation', 'must-hotel-booking') . '</a></div>';
        }
        echo '</td>';

        echo '<td><div class="must-payments-row-title">' . \esc_html((string) ($row['guest'] ?? '')) . '</div>';
        if ((string) ($row['guest_email'] ?? '') !== '') {
            echo '<div class="must-payments-row-meta"><span>' . \esc_html((string) $row['guest_email']) . '</span></div>';
        }
        echo '</td>';

        echo '<td><div class="must-payments-row-title">' . \esc_html((string) ($row['accommodation'] ?? '')) . '</div></td>';
        echo '<td>' . render_payments_badge($methodLabel !== '' ? $methodLabel : __('No payment recorded', 'must-hotel-booking'), $methodKey !== '' ? $methodKey : 'muted') . '</td>';
        echo '<td>' . render_payments_status_badge((string) ($row['payment_status'] ?? ''), (string) ($row['payment_status_key'] ?? ''));
        if (!empty($row['needs_review'])) {
            echo '<div class="must-payments-pill-stack">' . render_payments_badge(__('Needs review', 'must-hotel-booking'), 'warning') . '</div>';
        }
        echo '</td>';
        echo '<td>' . render_payments_status_badge((string) ($row['reservation_status'] ?? ''), (string) ($row['reservation_status_key'] ?? '')) . '</td>';
        echo '<td><div class="must-payments-money">' . \esc_html((string) ($row['total'] ?? '')) . '</div></td>';
        echo '<td><div class="must-payments-money">' . \esc_html((string) ($row['amount_paid'] ?? '')) . '</div></td>';
        echo '<td><div class="must-payments-money' . (!empty($row['has_due']) ? ' is-due' : '') . '">' . \esc_html((string) ($row['amount_due'] ?? '')) . '</div></td>';
        echo '<td>';
        if ($transactionId !== '') {
            echo '<div class="must-payments-row-title">' . \esc_html($transactionId) . '</div>';
        } else {
            echo '<div class="must-payments-cell-note">' . \esc_html__('No gateway reference recorded yet.', 'must-hotel-booking') . '</div>';
        }
        echo '</td>';
        echo '<td><div class="must-payments-cell-date">' . \esc_html($updatedAt) . '</div></td>';
        echo '<td><div class="must-payments-row-actions">';

        foreach ((array) ($row['actions'] ?? []) as $action => $url) {
            if (!isset($labels[$action])) {
                continue;
            }

            $className = 'button button-small';

            if ($action === 'mark_paid') {
                $className .= ' button-primary';
            }

            echo '<a class="' . \esc_attr($className) . '" href="' . \esc_url((string) $url) . '">' . \esc_html($labels[$action]) . '</a>';
        }

        echo '</div>';

        if (!empty($row['warnings'])) {
            echo '<ul class="must-payments-warning-list">';
            foreach ((array) $row['warnings'] as $warning) {
                echo '<li>' . \esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $pagination
 * @param array<string, scalar|int|bool> $args
 */
function render_payments_pagination(array $pagination, array $args): void
{
    $totalItems = isset($pagination['total_items']) ? (int) $pagination['total_items'] : 0;
    $totalPages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;
    $currentPage = isset($pagination['current_page']) ? (int) $pagination['current_page'] : 1;

    if ($totalItems <= 0) {
        return;
    }

    echo '<div class="must-payments-pagination">';
    echo '<div class="must-payments-pagination-copy">';
    echo '<span class="must-payments-pagination-total">' . \esc_html(\sprintf(_n('%d reservation in this ledger view', '%d reservations in this ledger view', $totalItems, 'must-hotel-booking'), $totalItems)) . '</span>';
    echo '<span class="must-payments-pagination-page">' . \esc_html(\sprintf(__('Page %1$d of %2$d', 'must-hotel-booking'), $currentPage, $totalPages)) . '</span>';
    echo '</div>';

    if ($totalPages > 1) {
        echo '<div class="tablenav-pages">';
        echo \wp_kses_post(
            \paginate_links([
                'base' => \esc_url_raw(get_admin_payments_page_url(\array_merge($args, ['paged' => '%#%']))),
                'format' => '',
                'current' => $currentPage,
                'total' => $totalPages,
            ])
        );
        echo '</div>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed>|null $detail
 */
function render_payment_detail(?array $detail): void
{
    echo '<section id="must-payment-detail" class="postbox must-dashboard-panel must-payments-panel">';
    echo '<div class="must-dashboard-panel-inner">';

    if (!\is_array($detail)) {
        echo '<div class="must-dashboard-panel-heading">';
        echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Payment Detail', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Select a reservation from the ledger to review payment status, transaction rows, and payment-related activity in more detail.', 'must-hotel-booking') . '</p></div>';
        echo '</div>';
        render_payments_empty_state(
            \__('No payment detail is selected.', 'must-hotel-booking'),
            \__('Open any reservation from the ledger to inspect its payment summary, transaction history, and operational warnings without leaving the Payments page.', 'must-hotel-booking')
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    $reservation = isset($detail['reservation']) && \is_array($detail['reservation']) ? $detail['reservation'] : [];
    $state = isset($detail['state']) && \is_array($detail['state']) ? $detail['state'] : [];
    $warnings = isset($state['warnings']) && \is_array($state['warnings']) ? $state['warnings'] : [];
    $currency = MustBookingConfig::get_currency();
    $metrics = [
        [
            'label' => \__('Reservation Total', 'must-hotel-booking'),
            'value' => \number_format_i18n((float) ($state['total'] ?? 0.0), 2) . ' ' . $currency,
        ],
        [
            'label' => \__('Amount Paid', 'must-hotel-booking'),
            'value' => \number_format_i18n((float) ($state['amount_paid'] ?? 0.0), 2) . ' ' . $currency,
        ],
        [
            'label' => \__('Amount Due', 'must-hotel-booking'),
            'value' => \number_format_i18n((float) ($state['amount_due'] ?? 0.0), 2) . ' ' . $currency,
        ],
    ];

    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Payment Detail', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review the selected reservation payment state, guest context, gateway references, and timeline without changing how payment processing works.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-payments-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Booking', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($detail['booking_id'] ?? '')) . '</span>';
    if ((string) ($detail['reservation_url'] ?? '') !== '') {
        echo '<a class="button must-dashboard-panel-link" href="' . \esc_url((string) $detail['reservation_url']) . '">' . \esc_html__('Open Reservation', 'must-hotel-booking') . '</a>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="must-payments-detail-grid">';
    echo '<div class="must-payments-detail-main">';
    echo '<div class="must-payments-metric-grid">';
    foreach ($metrics as $metric) {
        echo '<article class="must-payments-metric">';
        echo '<span class="must-payments-metric-label">' . \esc_html((string) $metric['label']) . '</span>';
        echo '<strong class="must-payments-metric-value">' . \esc_html((string) $metric['value']) . '</strong>';
        echo '</article>';
    }
    echo '</div>';

    $detailMethodLabel = (string) ($state['method'] ?? '') !== '' ? (string) ($state['method'] ?? '') : __('No payment recorded', 'must-hotel-booking');

    echo '<div class="must-payments-pill-stack">';
    echo render_payments_status_badge((string) (get_reservation_status_options()[(string) ($reservation['status'] ?? '')] ?? (string) ($reservation['status'] ?? '')), (string) ($reservation['status'] ?? ''));
    echo render_payments_status_badge((string) ($state['derived_status'] ?? ''), (string) ($state['derived_status'] ?? ''));
    echo render_payments_badge($detailMethodLabel, (string) ($state['method'] ?? 'info'));
    echo '</div>';

    echo '<div class="must-payments-detail-list">';
    $detailRows = [
        [
            'label' => \__('Guest', 'must-hotel-booking'),
            'value' => \trim((string) ($detail['guest_name'] ?? '') . ((string) ($detail['guest_email'] ?? '') !== '' ? ' | ' . (string) ($detail['guest_email'] ?? '') : '')),
        ],
        [
            'label' => \__('Accommodation', 'must-hotel-booking'),
            'value' => (string) ($detail['accommodation'] ?? ''),
        ],
        [
            'label' => \__('Stay', 'must-hotel-booking'),
            'value' => \trim((string) ($reservation['checkin'] ?? '') . ' -> ' . (string) ($reservation['checkout'] ?? '')),
        ],
        [
            'label' => \__('Stripe / transaction reference', 'must-hotel-booking'),
            'value' => (string) ($state['transaction_id'] ?? ''),
        ],
        [
            'label' => \__('Paid at', 'must-hotel-booking'),
            'value' => (string) ($state['paid_at'] ?? ''),
        ],
        [
            'label' => \__('Guest phone', 'must-hotel-booking'),
            'value' => (string) ($detail['guest_phone'] ?? ''),
        ],
    ];

    foreach ($detailRows as $detailRow) {
        if ((string) ($detailRow['value'] ?? '') === '') {
            continue;
        }

        echo '<div class="must-payments-detail-item">';
        echo '<span class="must-payments-detail-label">' . \esc_html((string) $detailRow['label']) . '</span>';
        echo '<span class="must-payments-detail-value">' . \esc_html((string) $detailRow['value']) . '</span>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="must-payments-detail-side">';
    if (!empty($warnings)) {
        echo '<div class="must-payments-form-alert">';
        echo '<h3>' . \esc_html__('Warnings', 'must-hotel-booking') . '</h3>';
        echo '<ul class="must-payments-warning-list">';
        foreach ($warnings as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    } else {
        echo '<div class="must-payments-context-card">';
        echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Operational State', 'must-hotel-booking') . '</span>';
        echo '<p>' . \esc_html__('No payment warnings are currently attached to this reservation. The ledger, reservation state, and gateway reference appear aligned from the stored data available on this page.', 'must-hotel-booking') . '</p>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="must-payments-subsection">';
    echo '<div class="must-payments-subsection-heading"><h3>' . \esc_html__('Transaction Rows', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Gateway and manual ledger entries attached to this reservation.', 'must-hotel-booking') . '</p></div>';

    if (empty($detail['payments'])) {
        render_payments_empty_state(
            \__('No transaction rows are linked to this reservation yet.', 'must-hotel-booking'),
            \__('The payment state may still be derived from reservation totals, collection mode, and payment workflow status even when no gateway row exists yet.', 'must-hotel-booking')
        );
    } else {
        echo '<div class="must-payments-table-wrap">';
        echo '<table class="widefat striped must-payments-data-table must-payments-detail-table"><thead><tr>';
        echo '<th>' . \esc_html__('Amount', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Method', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Paid At', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Created', 'must-hotel-booking') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ((array) ($detail['payments'] ?? []) as $paymentRow) {
            if (!\is_array($paymentRow)) {
                continue;
            }

            echo '<tr>';
            echo '<td><div class="must-payments-money">' . \esc_html(\number_format_i18n((float) ($paymentRow['amount'] ?? 0.0), 2) . ' ' . (string) ($paymentRow['currency'] ?? MustBookingConfig::get_currency())) . '</div></td>';
            echo '<td>' . render_payments_badge((string) ($paymentRow['method'] ?? '') !== '' ? (string) ($paymentRow['method'] ?? '') : __('No payment recorded', 'must-hotel-booking'), (string) ($paymentRow['method'] ?? 'info')) . '</td>';
            echo '<td>' . render_payments_status_badge((string) ($paymentRow['status'] ?? ''), (string) ($paymentRow['status'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['transaction_id'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['paid_at'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['created_at'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="must-payments-subsection">';
    echo '<div class="must-payments-subsection-heading"><h3>' . \esc_html__('Activity Timeline', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Payment-related activity, gateway follow-up, and reservation events captured for this booking.', 'must-hotel-booking') . '</p></div>';

    if (empty($detail['timeline'])) {
        render_payments_empty_state(
            \__('No payment activity has been logged for this reservation yet.', 'must-hotel-booking'),
            \__('Activity entries appear here when reservation or payment events are written to the operational timeline.', 'must-hotel-booking')
        );
    } else {
        echo '<div class="must-payments-table-wrap">';
        echo '<table class="widefat striped must-payments-data-table must-payments-detail-table"><thead><tr><th>' . \esc_html__('When', 'must-hotel-booking') . '</th><th>' . \esc_html__('Event', 'must-hotel-booking') . '</th><th>' . \esc_html__('Message', 'must-hotel-booking') . '</th></tr></thead><tbody>';

        foreach ((array) ($detail['timeline'] ?? []) as $timelineRow) {
            if (!\is_array($timelineRow)) {
                continue;
            }

            echo '<tr>';
            echo '<td><div class="must-payments-cell-date">' . \esc_html((string) ($timelineRow['created_at'] ?? '')) . '</div></td>';
            echo '<td><div class="must-payments-row-title">' . \esc_html((string) ($timelineRow['event_type'] ?? '')) . '</div><div class="must-payments-pill-stack">' . render_payments_badge((string) ($timelineRow['severity'] ?? 'info'), (string) ($timelineRow['severity'] ?? 'info')) . '</div></td>';
            echo '<td>' . \esc_html((string) ($timelineRow['message'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
    echo '</div>';

    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $settings
 */
function render_payment_settings_panel(array $settings): void
{
    $catalog = isset($settings['catalog']) && \is_array($settings['catalog']) ? $settings['catalog'] : [];
    $states = isset($settings['states']) && \is_array($settings['states']) ? $settings['states'] : [];
    $environmentCatalog = isset($settings['environment_catalog']) && \is_array($settings['environment_catalog']) ? $settings['environment_catalog'] : [];
    $activeEnvironment = isset($settings['active_environment']) ? (string) $settings['active_environment'] : '';
    $webhookUrl = isset($settings['webhook_url']) ? (string) $settings['webhook_url'] : '';
    $enabledMethodCount = \count(\array_filter($states));

    echo '<section id="must-payment-settings" class="postbox must-dashboard-panel must-payments-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Payment Settings', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Manage enabled collection methods and Stripe credentials from a cleaner settings surface without changing how payment processing behaves.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-payments-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Enabled Methods', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $enabledMethodCount) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Active Stripe Profile', 'must-hotel-booking') . '</strong> ' . \esc_html(PaymentEngine::getSiteEnvironmentLabel($activeEnvironment)) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<form method="post" action="' . \esc_url(get_admin_payments_page_url()) . '" class="must-payments-settings-form">';
    \wp_nonce_field('must_payment_settings_save', 'must_payment_settings_nonce');
    echo '<input type="hidden" name="must_payments_action" value="save_payment_settings" />';
    echo '<div class="must-payments-method-grid">';

    foreach ($catalog as $method => $meta) {
        if (!\is_array($meta)) {
            continue;
        }

        $enabled = !empty($states[$method]);
        echo '<label class="must-payments-method-card' . ($enabled ? ' is-enabled' : '') . '">';
        echo '<input type="checkbox" name="payment_methods[' . \esc_attr((string) $method) . ']" value="1"' . \checked($enabled, true, false) . ' />';
        echo '<span class="must-payments-method-copy">';
        echo '<strong>' . \esc_html((string) ($meta['label'] ?? $method)) . '</strong>';
        echo render_payments_badge($enabled ? __('Enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking'), $enabled ? 'ok' : 'muted');
        if (!empty($meta['description'])) {
            echo '<small>' . \esc_html((string) $meta['description']) . '</small>';
        }
        echo '</span>';
        echo '</label>';
    }
    echo '</div>';

    echo '<div class="must-payments-subsection">';
    echo '<div class="must-payments-subsection-heading"><h3>' . \esc_html__('Stripe Checkout', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Keep live and test credentials organized by environment while leaving the existing Stripe workflow unchanged.', 'must-hotel-booking') . '</p></div>';

    foreach ($environmentCatalog as $environmentKey => $environmentMeta) {
        if (!\is_string($environmentKey) || !\is_array($environmentMeta)) {
            continue;
        }

        $credentials = PaymentEngine::getStripeEnvironmentCredentials($environmentKey);
        $isActive = $environmentKey === $activeEnvironment;
        echo '<section class="must-payments-environment-card' . ($isActive ? ' is-active' : '') . '">';
        echo '<div class="must-payments-environment-heading">';
        echo '<div class="must-payments-environment-copy">';
        echo '<h4>' . \esc_html((string) ($environmentMeta['label'] ?? $environmentKey)) . '</h4>';
        if (!empty($environmentMeta['description'])) {
            echo '<p>' . \esc_html((string) $environmentMeta['description']) . '</p>';
        }
        echo '</div>';
        echo '<div class="must-payments-pill-stack">' . render_payments_badge($isActive ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking'), $isActive ? 'ok' : 'muted') . '</div>';
        echo '</div>';

        echo '<div class="must-payments-credential-grid">';
        echo '<label class="must-payments-field"><span>' . \esc_html__('Publishable key', 'must-hotel-booking') . '</span><input id="must-stripe-' . \esc_attr($environmentKey) . '-publishable-key" type="text" name="stripe_' . \esc_attr($environmentKey) . '_publishable_key" value="' . \esc_attr((string) ($credentials['publishable_key'] ?? '')) . '" autocomplete="off" /></label>';
        echo '<label class="must-payments-field"><span>' . \esc_html__('Secret key', 'must-hotel-booking') . '</span><input id="must-stripe-' . \esc_attr($environmentKey) . '-secret-key" type="password" name="stripe_' . \esc_attr($environmentKey) . '_secret_key" value="' . \esc_attr((string) ($credentials['secret_key'] ?? '')) . '" autocomplete="new-password" /></label>';
        echo '<label class="must-payments-field"><span>' . \esc_html__('Webhook signing secret', 'must-hotel-booking') . '</span><input id="must-stripe-' . \esc_attr($environmentKey) . '-webhook-secret" type="password" name="stripe_' . \esc_attr($environmentKey) . '_webhook_secret" value="' . \esc_attr((string) ($credentials['webhook_secret'] ?? '')) . '" autocomplete="new-password" /></label>';
        echo '</div>';
        echo '</section>';
    }

    echo '<div class="must-payments-webhook-box"><span class="must-payments-webhook-label">' . \esc_html__('Webhook URL', 'must-hotel-booking') . '</span><code>' . \esc_html($webhookUrl) . '</code></div>';
    echo '</div>';
    echo '<div class="must-payments-form-actions"><button type="submit" class="button button-primary">' . \esc_html__('Save Payment Settings', 'must-hotel-booking') . '</button></div>';
    echo '</form>';
    echo '</div>';
    echo '</section>';
}

function render_admin_payments_page(): void
{
    ensure_admin_capability();

    $query = PaymentAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new PaymentAdminDataProvider())->getPageData($query, get_payments_admin_save_state());
    clear_payments_admin_save_state();
    $rows = isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : [];
    $pagination = isset($pageData['pagination']) && \is_array($pageData['pagination']) ? $pageData['pagination'] : [];
    $detail = isset($pageData['detail']) && \is_array($pageData['detail']) ? $pageData['detail'] : null;

    echo '<div class="wrap must-dashboard-page must-payments-admin">';
    render_payments_page_header($query, $pageData);
    render_payments_admin_notice_from_query();
    render_payments_error_notice(isset($pageData['settings_errors']) && \is_array($pageData['settings_errors']) ? $pageData['settings_errors'] : []);
    render_payments_section_navigation($query, $detail);
    render_payments_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    echo '<div class="must-payments-stack">';
    render_payments_filters(
        isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [],
        isset($pageData['status_options']) && \is_array($pageData['status_options']) ? $pageData['status_options'] : [],
        isset($pageData['reservation_status_options']) && \is_array($pageData['reservation_status_options']) ? $pageData['reservation_status_options'] : [],
        isset($pageData['method_options']) && \is_array($pageData['method_options']) ? $pageData['method_options'] : [],
        isset($pageData['payment_group_options']) && \is_array($pageData['payment_group_options']) ? $pageData['payment_group_options'] : [],
        $pagination,
        \count($rows)
    );
    render_payments_table($rows, $pagination);
    render_payments_pagination($pagination, $query->buildUrlArgs());
    render_payment_detail($detail);
    render_payment_settings_panel(isset($pageData['settings']) && \is_array($pageData['settings']) ? $pageData['settings'] : []);
    echo '</div>';
    echo '</div>';
}

function enqueue_admin_payments_assets(): void
{
    $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';

    if ($page !== 'must-hotel-booking-payments') {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-payments',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-payments.css',
        ['must-hotel-booking-admin-ui'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_payments_assets');
\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_payments_admin_actions_early', 1);
