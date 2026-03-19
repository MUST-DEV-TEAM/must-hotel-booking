<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\PaymentMethodRegistry;

/**
 * Build reservations list page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_reservations_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-reservations');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

/**
 * Build reservation detail page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_reservation_detail_page_url(int $reservationId, array $args = []): string
{
    $baseArgs = [
        'page' => 'must-hotel-booking-reservation',
        'reservation_id' => $reservationId,
    ];

    return \add_query_arg(\array_merge($baseArgs, $args), \admin_url('admin.php'));
}

/**
 * Build reservation create page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_reservation_create_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-reservation-create');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

/**
 * Format reservation booking ID for display.
 *
 * @param array<string, mixed> $reservation
 */
function format_reservation_booking_id(array $reservation): string
{
    $bookingId = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';

    if ($bookingId !== '') {
        return $bookingId;
    }

    $id = isset($reservation['id']) ? (int) $reservation['id'] : 0;

    return $id > 0 ? 'RES-' . $id : '';
}

/**
 * Get reservation status options.
 *
 * @return array<string, string>
 */
function get_reservation_status_options(): array
{
    return [
        'pending' => \__('Pending', 'must-hotel-booking'),
        'pending_payment' => \__('Pending Payment', 'must-hotel-booking'),
        'confirmed' => \__('Confirmed', 'must-hotel-booking'),
        'cancelled' => \__('Cancelled', 'must-hotel-booking'),
        'expired' => \__('Expired', 'must-hotel-booking'),
        'payment_failed' => \__('Payment Failed', 'must-hotel-booking'),
        'completed' => \__('Completed', 'must-hotel-booking'),
        'blocked' => \__('Blocked', 'must-hotel-booking'),
    ];
}

/**
 * Get payment status options.
 *
 * @return array<string, string>
 */
function get_reservation_payment_status_options(): array
{
    return [
        'unpaid' => \__('Unpaid', 'must-hotel-booking'),
        'pending' => \__('Pending', 'must-hotel-booking'),
        'paid' => \__('Paid', 'must-hotel-booking'),
        'partially_paid' => \__('Partially Paid', 'must-hotel-booking'),
        'pay_at_hotel' => \__('Pay At Hotel', 'must-hotel-booking'),
        'failed' => \__('Failed', 'must-hotel-booking'),
        'cancelled' => \__('Cancelled', 'must-hotel-booking'),
        'refunded' => \__('Refunded', 'must-hotel-booking'),
        'blocked' => \__('Blocked', 'must-hotel-booking'),
    ];
}

/**
 * Get payment method options.
 *
 * @return array<string, string>
 */
function get_reservation_payment_method_options(): array
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
 * @param array<string, mixed> $filters
 * @return array<string, scalar>
 */
function get_admin_reservations_list_query_args(array $filters = []): array
{
    $args = [];

    if (!empty($filters['quick_filter'])) {
        $args['quick_filter'] = (string) $filters['quick_filter'];
    }

    if (!empty($filters['search'])) {
        $args['search'] = (string) $filters['search'];
    }

    if (!empty($filters['status'])) {
        $args['status'] = (string) $filters['status'];
    }

    if (!empty($filters['payment_status'])) {
        $args['payment_status'] = (string) $filters['payment_status'];
    }

    if (!empty($filters['payment_method'])) {
        $args['payment_method'] = (string) $filters['payment_method'];
    }

    if (!empty($filters['room_id'])) {
        $args['room_id'] = (int) $filters['room_id'];
    }

    if (!empty($filters['checkin_from'])) {
        $args['checkin_from'] = (string) $filters['checkin_from'];
    }

    if (!empty($filters['checkin_to'])) {
        $args['checkin_to'] = (string) $filters['checkin_to'];
    }

    if (!empty($filters['checkin_month'])) {
        $args['checkin_month'] = (string) $filters['checkin_month'];
    }

    if (!empty($filters['per_page']) && (int) $filters['per_page'] !== 20) {
        $args['per_page'] = (int) $filters['per_page'];
    }

    if (!empty($filters['paged']) && (int) $filters['paged'] > 1) {
        $args['paged'] = (int) $filters['paged'];
    }

    return $args;
}

/**
 * @param array<string, mixed> $filters
 */
function get_admin_reservations_current_list_url(array $filters = []): string
{
    return get_admin_reservations_page_url(get_admin_reservations_list_query_args($filters));
}

/**
 * Render reservations admin notice from query.
 */
function render_admin_reservations_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    if ($notice === 'bulk_action_completed') {
        $updatedCount = isset($_GET['updated_count']) ? \absint(\wp_unslash($_GET['updated_count'])) : 0;
        $failedCount = isset($_GET['failed_count']) ? \absint(\wp_unslash($_GET['failed_count'])) : 0;
        $bulkAction = isset($_GET['bulk_action']) ? \sanitize_key((string) \wp_unslash($_GET['bulk_action'])) : '';
        $actionLabels = [
            'confirm' => \__('confirm', 'must-hotel-booking'),
            'mark_paid' => \__('mark paid', 'must-hotel-booking'),
            'cancel' => \__('cancel', 'must-hotel-booking'),
        ];
        $actionLabel = isset($actionLabels[$bulkAction]) ? $actionLabels[$bulkAction] : \__('update', 'must-hotel-booking');
        $class = $failedCount > 0 ? 'notice notice-warning' : 'notice notice-success';
        $message = \sprintf(
            \__('Bulk %1$s completed. Updated %2$d reservations; %3$d failed.', 'must-hotel-booking'),
            $actionLabel,
            $updatedCount,
            $failedCount
        );

        echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';

        return;
    }

    $messages = [
        'reservation_created' => ['success', \__('Reservation created successfully.', 'must-hotel-booking')],
        'reservation_updated' => ['success', \__('Reservation details updated successfully.', 'must-hotel-booking')],
        'reservation_guest_updated' => ['success', \__('Guest details updated successfully.', 'must-hotel-booking')],
        'reservation_confirmed' => ['success', \__('Reservation confirmed.', 'must-hotel-booking')],
        'reservation_marked_pending' => ['success', \__('Reservation moved back to pending.', 'must-hotel-booking')],
        'reservation_marked_paid' => ['success', \__('Reservation marked as paid.', 'must-hotel-booking')],
        'reservation_marked_unpaid' => ['success', \__('Reservation marked as unpaid.', 'must-hotel-booking')],
        'reservation_cancelled' => ['success', \__('Reservation cancelled.', 'must-hotel-booking')],
        'reservation_guest_email_resent' => ['success', \__('Guest reservation email resent.', 'must-hotel-booking')],
        'reservation_admin_email_resent' => ['success', \__('Admin reservation email resent.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
        'invalid_guest_email' => ['error', \__('Please enter a valid guest email address.', 'must-hotel-booking')],
        'reservation_not_found' => ['error', \__('Reservation not found.', 'must-hotel-booking')],
        'action_failed' => ['error', \__('Unable to complete the requested reservation action.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';

    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';
}

function get_admin_reservation_badge_tone(string $key): string
{
    $key = \sanitize_key($key);

    if (\in_array($key, ['ok', 'healthy', 'confirmed', 'completed', 'paid'], true)) {
        return 'ok';
    }

    if (\in_array($key, ['warning', 'pending', 'pending_payment', 'unpaid'], true)) {
        return 'warning';
    }

    if (\in_array($key, ['error', 'failed', 'payment_failed', 'cancelled', 'blocked', 'expired'], true)) {
        return 'error';
    }

    return 'info';
}

function render_admin_reservation_badge(string $label, string $tone): string
{
    $resolvedTone = get_admin_reservation_badge_tone($tone);
    $normalizedLabel = $label;

    if ($normalizedLabel === \sanitize_key($normalizedLabel)) {
        $normalizedLabel = \ucwords(\str_replace('_', ' ', $normalizedLabel));
    }

    $classMap = [
        'ok' => 'is-ok',
        'warning' => 'is-warning',
        'error' => 'is-error',
        'info' => 'is-info',
    ];
    $className = isset($classMap[$resolvedTone]) ? $classMap[$resolvedTone] : $classMap['info'];

    return '<span class="must-dashboard-status-badge is-compact must-reservation-badge ' . \esc_attr($className) . '">' . \esc_html($normalizedLabel) . '</span>';
}

function format_admin_reservations_display_date(string $date): string
{
    $date = \trim($date);

    if ($date === '') {
        return '';
    }

    $timestamp = \strtotime($date);

    if ($timestamp === false) {
        return $date;
    }

    return \wp_date(\get_option('date_format'), $timestamp);
}

/**
 * @param array<int, array<string, mixed>> $quickFilters
 * @return array<string, array<string, mixed>>
 */
function get_admin_reservations_quick_filter_map(array $quickFilters): array
{
    $map = [];

    foreach ($quickFilters as $filter) {
        if (!\is_array($filter) || empty($filter['slug'])) {
            continue;
        }

        $map[(string) $filter['slug']] = $filter;
    }

    return $map;
}

/**
 * @param array<int, array<string, mixed>> $quickFilters
 */
function render_admin_reservations_overview(array $quickFilters): void
{
    $filterMap = get_admin_reservations_quick_filter_map($quickFilters);
    $cards = [
        [
            'slug' => 'all',
            'label' => \__('Total Reservations', 'must-hotel-booking'),
            'description' => \__('Every booking currently tracked in the system.', 'must-hotel-booking'),
            'icon' => 'dashicons-index-card',
        ],
        [
            'slug' => 'arrivals_today',
            'label' => \__('Arrivals Today', 'must-hotel-booking'),
            'description' => \__('Guests expected to check in today.', 'must-hotel-booking'),
            'icon' => 'dashicons-arrow-down-alt',
        ],
        [
            'slug' => 'departures_today',
            'label' => \__('Departures Today', 'must-hotel-booking'),
            'description' => \__('Guests scheduled to check out today.', 'must-hotel-booking'),
            'icon' => 'dashicons-arrow-up-alt',
        ],
        [
            'slug' => 'in_house_today',
            'label' => \__('In-House', 'must-hotel-booking'),
            'description' => \__('Reservations currently staying on property.', 'must-hotel-booking'),
            'icon' => 'dashicons-building',
        ],
        [
            'slug' => 'unpaid',
            'label' => \__('Needs Payment', 'must-hotel-booking'),
            'description' => \__('Reservations still waiting on payment collection.', 'must-hotel-booking'),
            'icon' => 'dashicons-money-alt',
        ],
    ];

    echo '<div class="must-reservations-overview">';

    foreach ($cards as $card) {
        $slug = (string) $card['slug'];
        $filter = isset($filterMap[$slug]) ? $filterMap[$slug] : [];
        $count = isset($filter['count']) ? (int) $filter['count'] : 0;
        $url = isset($filter['url']) ? (string) $filter['url'] : get_admin_reservations_page_url();
        $isCurrent = !empty($filter['current']);

        echo '<a class="must-reservations-stat-card' . ($isCurrent ? ' is-current' : '') . '" href="' . \esc_url($url) . '"' . ($isCurrent ? ' aria-current="page"' : '') . '>';
        echo '<span class="must-reservations-stat-icon dashicons ' . \esc_attr((string) $card['icon']) . '" aria-hidden="true"></span>';
        echo '<span class="must-reservations-stat-label">' . \esc_html((string) $card['label']) . '</span>';
        echo '<strong class="must-reservations-stat-value">' . \esc_html((string) $count) . '</strong>';
        echo '<span class="must-reservations-stat-copy">' . \esc_html((string) $card['description']) . '</span>';
        echo '</a>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, mixed> $pageData
 */
function render_admin_reservations_active_filters(array $filters, array $pageData): void
{
    $chips = [];
    $statusOptions = isset($pageData['status_options']) && \is_array($pageData['status_options']) ? $pageData['status_options'] : [];
    $paymentStatusOptions = isset($pageData['payment_status_options']) && \is_array($pageData['payment_status_options']) ? $pageData['payment_status_options'] : [];
    $paymentMethodOptions = isset($pageData['payment_method_options']) && \is_array($pageData['payment_method_options']) ? $pageData['payment_method_options'] : [];
    $roomOptions = isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : [];

    if (!empty($filters['search'])) {
        $chips[] = [
            'label' => \__('Search', 'must-hotel-booking'),
            'value' => (string) $filters['search'],
        ];
    }

    if (!empty($filters['status']) && isset($statusOptions[(string) $filters['status']])) {
        $chips[] = [
            'label' => \__('Reservation', 'must-hotel-booking'),
            'value' => (string) $statusOptions[(string) $filters['status']],
        ];
    }

    if (!empty($filters['payment_status']) && isset($paymentStatusOptions[(string) $filters['payment_status']])) {
        $chips[] = [
            'label' => \__('Payment', 'must-hotel-booking'),
            'value' => (string) $paymentStatusOptions[(string) $filters['payment_status']],
        ];
    }

    if (!empty($filters['payment_method']) && isset($paymentMethodOptions[(string) $filters['payment_method']])) {
        $chips[] = [
            'label' => \__('Method', 'must-hotel-booking'),
            'value' => (string) $paymentMethodOptions[(string) $filters['payment_method']],
        ];
    }

    if (!empty($filters['room_id'])) {
        foreach ($roomOptions as $roomOption) {
            if (!\is_array($roomOption)) {
                continue;
            }

            if ((int) ($roomOption['id'] ?? 0) !== (int) $filters['room_id']) {
                continue;
            }

            $chips[] = [
                'label' => \__('Accommodation', 'must-hotel-booking'),
                'value' => (string) ($roomOption['name'] ?? ''),
            ];
            break;
        }
    }

    if (!empty($filters['checkin_month'])) {
        $monthTimestamp = \strtotime((string) $filters['checkin_month'] . '-01');
        $chips[] = [
            'label' => \__('Month', 'must-hotel-booking'),
            'value' => $monthTimestamp !== false
                ? \wp_date('F Y', $monthTimestamp)
                : (string) $filters['checkin_month'],
        ];
    }

    if (!empty($filters['checkin_from'])) {
        $chips[] = [
            'label' => \__('Check-in from', 'must-hotel-booking'),
            'value' => format_admin_reservations_display_date((string) $filters['checkin_from']),
        ];
    }

    if (!empty($filters['checkin_to'])) {
        $chips[] = [
            'label' => \__('Check-in to', 'must-hotel-booking'),
            'value' => format_admin_reservations_display_date((string) $filters['checkin_to']),
        ];
    }

    if (empty($chips)) {
        return;
    }

    echo '<div class="must-reservations-active-filters">';
    echo '<span class="must-reservations-active-filters-title">' . \esc_html__('Active filters', 'must-hotel-booking') . '</span>';

    foreach ($chips as $chip) {
        echo '<span class="must-reservations-active-filter-chip">';
        echo '<span class="must-reservations-active-filter-label">' . \esc_html((string) $chip['label']) . '</span>';
        echo '<span class="must-reservations-active-filter-value">' . \esc_html((string) $chip['value']) . '</span>';
        echo '</span>';
    }

    echo '<a class="must-reservations-active-filter-reset" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Clear all', 'must-hotel-booking') . '</a>';
    echo '</div>';
}

function format_admin_reservation_method_label(string $method): string
{
    $method = \sanitize_key($method);
    $options = get_reservation_payment_method_options();

    if ($method === '') {
        return \__('No payment recorded', 'must-hotel-booking');
    }

    return isset($options[$method]) ? (string) $options[$method] : $method;
}

/**
 * @param array<int, array<string, mixed>> $quickFilters
 */
function render_admin_reservations_quick_filters(array $quickFilters): void
{
    if (empty($quickFilters)) {
        return;
    }

    echo '<div class="must-reservations-filter-strip">';
    echo '<div class="must-reservations-filter-strip-label">' . \esc_html__('Quick views', 'must-hotel-booking') . '</div>';
    echo '<ul class="must-reservations-quick-filters">';

    foreach ($quickFilters as $filter) {
        if (!\is_array($filter)) {
            continue;
        }

        $class = 'must-reservations-quick-filter' . (!empty($filter['current']) ? ' is-current' : '');
        echo '<li>';
        echo '<a class="' . \esc_attr($class) . '" href="' . \esc_url((string) ($filter['url'] ?? '')) . '"' . (!empty($filter['current']) ? ' aria-current="page"' : '') . '>';
        echo '<span class="must-reservations-quick-filter-label">' . \esc_html((string) ($filter['label'] ?? '')) . '</span>';
        echo '<span class="must-reservations-quick-filter-count">' . \esc_html((string) ((int) ($filter['count'] ?? 0))) . '</span>';
        echo '</a>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, mixed> $pageData
 */
function render_admin_reservations_filters(array $filters, array $pageData): void
{
    $statusOptions = ['' => \__('All reservation statuses', 'must-hotel-booking')] + (isset($pageData['status_options']) && \is_array($pageData['status_options']) ? $pageData['status_options'] : []);
    $paymentStatusOptions = ['' => \__('All payment statuses', 'must-hotel-booking')] + (isset($pageData['payment_status_options']) && \is_array($pageData['payment_status_options']) ? $pageData['payment_status_options'] : []);
    $paymentMethodOptions = ['' => \__('All payment methods', 'must-hotel-booking')] + (isset($pageData['payment_method_options']) && \is_array($pageData['payment_method_options']) ? $pageData['payment_method_options'] : []);
    $roomOptions = isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : [];

    echo '<form method="get" action="' . \esc_url(get_admin_reservations_page_url()) . '" class="must-reservations-toolbar">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-reservations" />';

    if (!empty($filters['quick_filter'])) {
        echo '<input type="hidden" name="quick_filter" value="' . \esc_attr((string) $filters['quick_filter']) . '" />';
    }

    echo '<div class="must-reservations-toolbar-panel">';
    echo '<div class="must-reservations-toolbar-heading">';
    echo '<div>';
    echo '<h2>' . \esc_html__('Refine the reservation list', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Search by guest or booking details, then narrow the queue by reservation state, payment, accommodation, or arrival window.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-reservations-toolbar-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Apply Filters', 'must-hotel-booking') . '</button>';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-reservations-toolbar-grid">';

    echo '<label class="must-reservations-field must-reservations-search-field" for="must-reservations-search">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Search reservations', 'must-hotel-booking') . '</span>';
    echo '<input id="must-reservations-search" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Booking ID, guest, email, phone, accommodation', 'must-hotel-booking') . '" />';
    echo '<span class="must-reservations-field-help">' . \esc_html__('Find a reservation by booking reference, guest, contact details, or accommodation name.', 'must-hotel-booking') . '</span>';
    echo '</label>';

    echo '<label class="must-reservations-field">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Reservation status', 'must-hotel-booking') . '</span>';
    echo '<select name="status">';

    foreach ($statusOptions as $value => $label) {
        $selected = ((string) ($filters['status'] ?? '') === (string) $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $value) . '"' . $selected . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label class="must-reservations-field">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Payment status', 'must-hotel-booking') . '</span>';
    echo '<select name="payment_status">';

    foreach ($paymentStatusOptions as $value => $label) {
        $selected = ((string) ($filters['payment_status'] ?? '') === (string) $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $value) . '"' . $selected . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label class="must-reservations-field">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Payment method', 'must-hotel-booking') . '</span>';
    echo '<select name="payment_method">';

    foreach ($paymentMethodOptions as $value => $label) {
        $selected = ((string) ($filters['payment_method'] ?? '') === (string) $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $value) . '"' . $selected . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label class="must-reservations-field">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Accommodation', 'must-hotel-booking') . '</span>';
    echo '<select name="room_id">';
    echo '<option value="">' . \esc_html__('All accommodations', 'must-hotel-booking') . '</option>';

    foreach ($roomOptions as $roomOption) {
        if (!\is_array($roomOption)) {
            continue;
        }

        $roomId = isset($roomOption['id']) ? (int) $roomOption['id'] : 0;
        $selected = $roomId === (int) ($filters['room_id'] ?? 0) ? ' selected' : '';
        echo '<option value="' . \esc_attr((string) $roomId) . '"' . $selected . '>' . \esc_html((string) ($roomOption['name'] ?? '')) . '</option>';
    }

    echo '</select>';
    echo '</label>';

    echo '<label class="must-reservations-field">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Check-in month', 'must-hotel-booking') . '</span>';
    echo '<input type="month" name="checkin_month" value="' . \esc_attr((string) ($filters['checkin_month'] ?? '')) . '" />';
    echo '</label>';

    echo '<label class="must-reservations-field">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Check-in from', 'must-hotel-booking') . '</span>';
    echo '<input type="date" name="checkin_from" value="' . \esc_attr((string) ($filters['checkin_from'] ?? '')) . '" />';
    echo '</label>';

    echo '<label class="must-reservations-field">';
    echo '<span class="must-reservations-field-label">' . \esc_html__('Check-in to', 'must-hotel-booking') . '</span>';
    echo '<input type="date" name="checkin_to" value="' . \esc_attr((string) ($filters['checkin_to'] ?? '')) . '" />';
    echo '</label>';

    echo '</div>';
    render_admin_reservations_active_filters($filters, $pageData);
    echo '</div>';
    echo '</form>';
}

/**
 * @param array<string, mixed> $pagination
 * @param array<string, mixed> $filters
 */
function render_admin_reservations_pagination(array $pagination, array $filters): void
{
    $totalPages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;
    $currentPage = isset($pagination['current_page']) ? (int) $pagination['current_page'] : 1;
    $totalItems = isset($pagination['total_items']) ? (int) $pagination['total_items'] : 0;

    if ($totalItems <= 0) {
        return;
    }

    echo '<div class="must-reservations-pagination">';
    echo '<div class="must-reservations-pagination-copy">';
    echo '<span class="must-reservations-pagination-total">' . \esc_html(\sprintf(\_n('%d reservation', '%d reservations', $totalItems, 'must-hotel-booking'), $totalItems)) . '</span>';

    if ($totalPages > 1) {
        echo '<span class="must-reservations-pagination-page">' . \esc_html(\sprintf(\__('Page %1$d of %2$d', 'must-hotel-booking'), $currentPage, $totalPages)) . '</span>';
    }

    echo '</div>';

    if ($totalPages > 1) {
        $baseArgs = get_admin_reservations_list_query_args($filters);
        unset($baseArgs['paged']);

        $links = \paginate_links(
            [
                'base' => \add_query_arg('paged', '%#%', get_admin_reservations_page_url($baseArgs)),
                'format' => '',
                'current' => \max(1, $currentPage),
                'total' => $totalPages,
                'type' => 'plain',
                'prev_text' => \__('Previous', 'must-hotel-booking'),
                'next_text' => \__('Next', 'must-hotel-booking'),
            ]
        );

        if (\is_string($links) && $links !== '') {
            echo '<div class="tablenav-pages">' . $links . '</div>';
        }
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $row
 */
function get_admin_reservation_row_action_url(string $action, array $row, string $returnUrl): string
{
    $reservationId = isset($row['id']) ? (int) $row['id'] : 0;

    if ($reservationId <= 0) {
        return '';
    }

    if ($action === 'view') {
        return get_admin_reservation_detail_page_url($reservationId);
    }

    if ($action === 'edit') {
        return get_admin_reservation_detail_page_url($reservationId, ['mode' => 'edit']);
    }

    $baseUrl = get_admin_reservations_page_url(
        [
            'reservation_action' => $action,
            'reservation_id' => $reservationId,
            'return_url' => $returnUrl,
        ]
    );

    return \wp_nonce_url($baseUrl, 'must_reservation_action_' . $action . '_' . $reservationId);
}

/**
 * @param array<string, mixed> $row
 */
function can_confirm_reservation_row(array $row): bool
{
    $status = \sanitize_key((string) ($row['reservation_status_key'] ?? ''));

    return !\in_array($status, ['confirmed', 'completed', 'cancelled', 'blocked'], true);
}

/**
 * @param array<string, mixed> $row
 */
function can_mark_reservation_paid_row(array $row): bool
{
    $status = \sanitize_key((string) ($row['reservation_status_key'] ?? ''));
    $paymentStatus = \sanitize_key((string) ($row['payment_status_key'] ?? ''));

    return !\in_array($status, ['cancelled', 'blocked'], true) && $paymentStatus !== 'paid';
}

/**
 * @param array<string, mixed> $row
 */
function can_cancel_reservation_row(array $row): bool
{
    $status = \sanitize_key((string) ($row['reservation_status_key'] ?? ''));

    return !\in_array($status, ['cancelled', 'completed', 'blocked'], true);
}

/**
 * @param array<string, mixed> $row
 */
function can_resend_reservation_email_row(array $row): bool
{
    return isset($row['guest_email']) && \is_email((string) $row['guest_email']);
}

/**
 * @param array<string, mixed> $pageData
 */
function render_admin_reservations_table(array $pageData): void
{
    $rows = isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : [];
    $filters = isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [];
    $bulkActions = isset($pageData['bulk_actions']) && \is_array($pageData['bulk_actions']) ? $pageData['bulk_actions'] : [];
    $returnUrl = get_admin_reservations_current_list_url($filters);

    echo '<form method="post" action="' . \esc_url(get_admin_reservations_page_url()) . '" class="must-reservations-list-form">';
    \wp_nonce_field('must_reservation_admin_action', 'must_reservation_admin_nonce');
    echo '<input type="hidden" name="must_reservation_admin_action" value="bulk_apply" />';
    echo '<input type="hidden" name="return_url" value="' . \esc_attr($returnUrl) . '" />';

    echo '<div class="must-reservations-table-card">';
    echo '<div class="must-reservations-table-topbar">';
    echo '<div class="must-reservations-table-intro">';
    echo '<h2>' . \esc_html__('Reservation queue', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Use bulk actions for routine updates, or open any reservation for the full guest, payment, and communication context.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-reservations-bulk-bar">';
    echo '<label class="screen-reader-text" for="must-reservations-bulk-action">' . \esc_html__('Bulk action', 'must-hotel-booking') . '</label>';
    echo '<select id="must-reservations-bulk-action" name="bulk_action">';
    echo '<option value="">' . \esc_html__('Bulk actions', 'must-hotel-booking') . '</option>';

    foreach ($bulkActions as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '">' . \esc_html((string) $label) . '</option>';
    }

    echo '</select>';
    echo '<button type="submit" class="button button-secondary">' . \esc_html__('Apply', 'must-hotel-booking') . '</button>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-reservations-table-scroll">';
    echo '<table class="widefat striped must-reservations-table">';
    echo '<thead><tr>';
    echo '<td class="check-column"><label class="screen-reader-text" for="must-reservations-toggle-all">' . \esc_html__('Select all reservations', 'must-hotel-booking') . '</label><input type="checkbox" id="must-reservations-toggle-all" /></td>';
    echo '<th>' . \esc_html__('Booking', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Stay', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Payment', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr class="must-reservations-empty-row"><td colspan="7">';
        echo '<div class="must-reservations-empty-state">';
        echo '<span class="dashicons dashicons-search" aria-hidden="true"></span>';
        echo '<strong>' . \esc_html__('No reservations matched the current filters.', 'must-hotel-booking') . '</strong>';
        echo '<p>' . \esc_html__('Try clearing one or more filters, or widen the date range to bring more reservations into view.', 'must-hotel-booking') . '</p>';
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Reset filters', 'must-hotel-booking') . '</a>';
        echo '</div>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
        echo '</form>';

        return;
    }

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        $viewUrl = get_admin_reservation_row_action_url('view', $row, $returnUrl);
        $editUrl = get_admin_reservation_row_action_url('edit', $row, $returnUrl);
        $guestName = (string) ($row['guest'] ?? '');
        $guestEmail = (string) ($row['guest_email'] ?? '');
        $guestPhone = (string) ($row['guest_phone'] ?? '');
        $paymentMethod = isset($row['payment_method']) && (string) $row['payment_method'] !== ''
            ? (string) $row['payment_method']
            : format_admin_reservation_method_label((string) ($row['payment_method_key'] ?? ''));
        $nights = (int) ($row['nights'] ?? 0);
        $guestCount = (int) ($row['guests'] ?? 0);

        echo '<tr>';
        echo '<td class="check-column must-reservations-selection-cell"><input type="checkbox" class="must-reservation-select" name="reservation_ids[]" value="' . \esc_attr((string) $reservationId) . '" /></td>';
        echo '<th scope="row" class="must-reservations-booking-cell" data-label="' . \esc_attr__('Booking', 'must-hotel-booking') . '">';
        echo '<a class="must-reservations-booking-link" href="' . \esc_url($viewUrl) . '">' . \esc_html((string) ($row['booking_id'] ?? '')) . '</a>';
        echo '<div class="must-reservations-booking-meta">';
        echo '<span class="must-reservations-booking-meta-label">' . \esc_html__('Created', 'must-hotel-booking') . '</span>';
        echo '<strong>' . \esc_html((string) ($row['created'] ?? '')) . '</strong>';
        echo '</div>';
        echo '</th>';

        echo '<td class="must-reservations-guest-cell" data-label="' . \esc_attr__('Guest', 'must-hotel-booking') . '">';
        if (!empty($row['guest_url'])) {
            echo '<div class="must-reservations-guest-name"><a href="' . \esc_url((string) $row['guest_url']) . '">' . \esc_html($guestName !== '' ? $guestName : \__('Guest not attached', 'must-hotel-booking')) . '</a></div>';
        } else {
            echo '<div class="must-reservations-guest-name">' . \esc_html($guestName !== '' ? $guestName : \__('Guest not attached', 'must-hotel-booking')) . '</div>';
        }

        if ($guestEmail !== '') {
            echo '<a class="must-reservations-contact-link" href="mailto:' . \esc_attr($guestEmail) . '">' . \esc_html($guestEmail) . '</a>';
        }

        if ($guestPhone !== '') {
            echo '<a class="must-reservations-contact-link" href="tel:' . \esc_attr($guestPhone) . '">' . \esc_html($guestPhone) . '</a>';
        }

        echo '</td>';

        echo '<td class="must-reservations-stay-cell" data-label="' . \esc_attr__('Stay', 'must-hotel-booking') . '">';
        echo '<div class="must-reservations-stay-title">' . \esc_html((string) ($row['accommodation'] ?? '')) . '</div>';
        echo '<div class="must-reservations-stay-dates">';
        echo '<span>' . \esc_html(format_admin_reservations_display_date((string) ($row['checkin'] ?? ''))) . '</span>';
        echo '<span class="must-reservations-stay-arrow" aria-hidden="true">&rarr;</span>';
        echo '<span>' . \esc_html(format_admin_reservations_display_date((string) ($row['checkout'] ?? ''))) . '</span>';
        echo '</div>';
        echo '<div class="must-reservations-stay-meta">';
        echo '<span>' . \esc_html(\sprintf(\_n('%d night', '%d nights', $nights, 'must-hotel-booking'), $nights)) . '</span>';
        echo '<span>' . \esc_html(\sprintf(\_n('%d guest', '%d guests', $guestCount, 'must-hotel-booking'), $guestCount)) . '</span>';
        echo '</div>';
        echo '</td>';

        echo '<td class="must-reservations-status-cell" data-label="' . \esc_attr__('Status', 'must-hotel-booking') . '">';
        echo '<div class="must-reservations-status-stack">';
        echo '<div class="must-reservations-status-line">';
        echo '<span class="must-reservations-status-label">' . \esc_html__('Reservation', 'must-hotel-booking') . '</span>';
        echo render_admin_reservation_badge((string) ($row['reservation_status'] ?? ''), (string) ($row['reservation_status_key'] ?? ''));
        echo '</div>';
        echo '<div class="must-reservations-status-line">';
        echo '<span class="must-reservations-status-label">' . \esc_html__('Payment', 'must-hotel-booking') . '</span>';
        echo render_admin_reservation_badge((string) ($row['payment_status'] ?? ''), (string) ($row['payment_status_key'] ?? ''));
        echo '</div>';
        echo '</div>';
        echo '</td>';

        echo '<td class="must-reservations-payment-cell" data-label="' . \esc_attr__('Payment', 'must-hotel-booking') . '">';
        echo '<strong class="must-reservations-total">' . \esc_html((string) ($row['total'] ?? '')) . '</strong>';
        echo '<div class="must-reservations-payment-method">' . \esc_html($paymentMethod) . '</div>';
        echo '</td>';

        echo '<td class="must-reservations-actions-cell" data-label="' . \esc_attr__('Actions', 'must-hotel-booking') . '"><div class="must-reservations-row-actions">';
        echo '<a class="button button-small button-primary must-reservations-row-action" href="' . \esc_url($viewUrl) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a>';
        echo '<a class="button button-small must-reservations-row-action" href="' . \esc_url($editUrl) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';

        if (can_confirm_reservation_row($row)) {
            echo '<a class="button button-small must-reservations-row-action is-accent" href="' . \esc_url(get_admin_reservation_row_action_url('confirm', $row, $returnUrl)) . '">' . \esc_html__('Confirm', 'must-hotel-booking') . '</a>';
        }

        if (can_mark_reservation_paid_row($row)) {
            echo '<a class="button button-small must-reservations-row-action is-accent" href="' . \esc_url(get_admin_reservation_row_action_url('mark_paid', $row, $returnUrl)) . '">' . \esc_html__('Mark Paid', 'must-hotel-booking') . '</a>';
        }

        if (can_resend_reservation_email_row($row)) {
            echo '<a class="button button-small must-reservations-row-action" href="' . \esc_url(get_admin_reservation_row_action_url('resend_guest_email', $row, $returnUrl)) . '">' . \esc_html__('Resend Email', 'must-hotel-booking') . '</a>';
        }

        if (can_cancel_reservation_row($row)) {
            echo '<a class="button button-small must-reservations-row-action is-danger" href="' . \esc_url(get_admin_reservation_row_action_url('cancel', $row, $returnUrl)) . '" onclick="return confirm(\'' . \esc_js(__('Cancel this reservation?', 'must-hotel-booking')) . '\');">' . \esc_html__('Cancel', 'must-hotel-booking') . '</a>';
        }

        echo '</div></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
}

function render_admin_reservation_detail_action_form(
    string $action,
    string $label,
    string $actionUrl,
    int $reservationId,
    string $returnUrl,
    string $buttonClass = 'button button-secondary',
    string $confirmMessage = ''
): void {
    $onsubmit = $confirmMessage !== ''
        ? ' onsubmit="return confirm(\'' . \esc_js($confirmMessage) . '\');"'
        : '';

    echo '<form method="post" action="' . \esc_url($actionUrl) . '" class="must-reservation-inline-form"' . $onsubmit . '>';
    \wp_nonce_field('must_reservation_admin_action', 'must_reservation_admin_nonce');
    echo '<input type="hidden" name="must_reservation_admin_action" value="' . \esc_attr($action) . '" />';
    echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
    echo '<input type="hidden" name="return_url" value="' . \esc_attr($returnUrl) . '" />';
    echo '<button type="submit" class="' . \esc_attr($buttonClass) . '">' . \esc_html($label) . '</button>';
    echo '</form>';
}

/**
 * @param array<string, mixed> $detailData
 */
function render_admin_reservation_detail_page_content(array $detailData): void
{
    $reservationId = isset($detailData['id']) ? (int) $detailData['id'] : 0;
    $mode = isset($detailData['mode']) ? (string) $detailData['mode'] : 'view';
    $bookingId = isset($detailData['booking_id']) ? (string) $detailData['booking_id'] : '';
    $summary = isset($detailData['summary']) && \is_array($detailData['summary']) ? $detailData['summary'] : [];
    $guest = isset($detailData['guest']) && \is_array($detailData['guest']) ? $detailData['guest'] : [];
    $stay = isset($detailData['stay']) && \is_array($detailData['stay']) ? $detailData['stay'] : [];
    $pricing = isset($detailData['pricing']) && \is_array($detailData['pricing']) ? $detailData['pricing'] : [];
    $payments = isset($detailData['payments']) && \is_array($detailData['payments']) ? $detailData['payments'] : [];
    $emails = isset($detailData['emails']) && \is_array($detailData['emails']) ? $detailData['emails'] : [];
    $timeline = isset($detailData['timeline']) && \is_array($detailData['timeline']) ? $detailData['timeline'] : [];
    $statusKey = isset($summary['reservation_status_key']) ? (string) $summary['reservation_status_key'] : '';
    $paymentStatusKey = isset($summary['payment_status_key']) ? (string) $summary['payment_status_key'] : '';
    $currentUrl = $mode === 'edit'
        ? get_admin_reservation_detail_page_url($reservationId, ['mode' => 'edit'])
        : get_admin_reservation_detail_page_url($reservationId);
    $calendarUrl = \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
        ? get_admin_calendar_page_url(
            [
                'start_date' => isset($stay['checkin']) ? (string) $stay['checkin'] : \current_time('Y-m-d'),
                'weeks' => 2,
                'room_id' => isset($detailData['reservation']['room_id']) ? (int) $detailData['reservation']['room_id'] : 0,
                'focus_room_id' => isset($detailData['reservation']['room_id']) ? (int) $detailData['reservation']['room_id'] : 0,
                'focus_date' => isset($stay['checkin']) ? (string) $stay['checkin'] : \current_time('Y-m-d'),
                'reservation_id' => $reservationId,
            ]
        )
        : '';

    echo '<div class="must-reservation-detail-header">';
    echo '<div>';
    echo '<h1>' . \esc_html($bookingId !== '' ? $bookingId : \__('Reservation', 'must-hotel-booking')) . '</h1>';
    echo '<p class="description">' . \esc_html__('View guest, stay, payment, and communication context in one place, with safe admin actions for day-to-day reservation handling.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-reservation-header-actions">';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Back to Reservations', 'must-hotel-booking') . '</a>';

    if ($mode === 'edit') {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservation_detail_page_url($reservationId)) . '">' . \esc_html__('View Mode', 'must-hotel-booking') . '</a>';
    } else {
        echo '<a class="button button-primary" href="' . \esc_url(get_admin_reservation_detail_page_url($reservationId, ['mode' => 'edit'])) . '">' . \esc_html__('Edit Guest & Notes', 'must-hotel-booking') . '</a>';
    }

    if ($calendarUrl !== '') {
        echo '<a class="button button-secondary" href="' . \esc_url($calendarUrl) . '">' . \esc_html__('Open in Calendar', 'must-hotel-booking') . '</a>';
    }

    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservation_create_page_url()) . '">' . \esc_html__('Add Reservation', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-reservation-action-bar">';

    if (!\in_array($statusKey, ['confirmed', 'completed', 'cancelled', 'blocked'], true)) {
        render_admin_reservation_detail_action_form('confirm', \__('Confirm Reservation', 'must-hotel-booking'), $currentUrl, $reservationId, $currentUrl);
    }

    if (!\in_array($statusKey, ['pending', 'pending_payment', 'cancelled', 'blocked'], true)) {
        render_admin_reservation_detail_action_form('mark_pending', \__('Mark Pending', 'must-hotel-booking'), $currentUrl, $reservationId, $currentUrl);
    }

    if (!\in_array($statusKey, ['cancelled', 'blocked'], true) && $paymentStatusKey !== 'paid') {
        render_admin_reservation_detail_action_form('mark_paid', \__('Mark Paid', 'must-hotel-booking'), $currentUrl, $reservationId, $currentUrl);
    }

    if ($paymentStatusKey !== 'unpaid' && !\in_array($statusKey, ['cancelled', 'blocked'], true)) {
        render_admin_reservation_detail_action_form('mark_unpaid', \__('Mark Unpaid', 'must-hotel-booking'), $currentUrl, $reservationId, $currentUrl);
    }

    if (\is_email((string) ($guest['email'] ?? ''))) {
        render_admin_reservation_detail_action_form('resend_guest_email', \__('Resend Guest Confirmation', 'must-hotel-booking'), $currentUrl, $reservationId, $currentUrl);
    }

    render_admin_reservation_detail_action_form('resend_admin_email', \__('Resend Admin Email', 'must-hotel-booking'), $currentUrl, $reservationId, $currentUrl);

    if (!\in_array($statusKey, ['cancelled', 'completed', 'blocked'], true)) {
        render_admin_reservation_detail_action_form(
            'cancel',
            \__('Cancel Reservation', 'must-hotel-booking'),
            $currentUrl,
            $reservationId,
            $currentUrl,
            'button button-secondary',
            \__('Cancel this reservation?', 'must-hotel-booking')
        );
    }

    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Open Email Templates', 'must-hotel-booking') . '</a>';
    echo '</div>';

    echo '<div class="must-reservation-detail-layout">';
    echo '<div class="must-reservation-detail-main">';

    echo '<div class="postbox must-dashboard-panel"><div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('Guest Details', 'must-hotel-booking') . '</h2>';

    if ($mode === 'edit') {
        echo '<form method="post" action="' . \esc_url($currentUrl) . '">';
        \wp_nonce_field('must_reservation_admin_action', 'must_reservation_admin_nonce');
        echo '<input type="hidden" name="must_reservation_admin_action" value="save_guest" />';
        echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
        echo '<input type="hidden" name="return_url" value="' . \esc_attr($currentUrl) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label for="must-guest-first-name">' . \esc_html__('First name', 'must-hotel-booking') . '</label></th><td><input id="must-guest-first-name" type="text" name="guest_first_name" value="' . \esc_attr((string) ($guest['first_name'] ?? '')) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="must-guest-last-name">' . \esc_html__('Last name', 'must-hotel-booking') . '</label></th><td><input id="must-guest-last-name" type="text" name="guest_last_name" value="' . \esc_attr((string) ($guest['last_name'] ?? '')) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="must-guest-email">' . \esc_html__('Email', 'must-hotel-booking') . '</label></th><td><input id="must-guest-email" type="email" name="guest_email" value="' . \esc_attr((string) ($guest['email'] ?? '')) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="must-guest-phone">' . \esc_html__('Phone', 'must-hotel-booking') . '</label></th><td><input id="must-guest-phone" type="text" name="guest_phone" value="' . \esc_attr((string) ($guest['phone'] ?? '')) . '" class="regular-text" /></td></tr>';
        echo '<tr><th><label for="must-guest-country">' . \esc_html__('Country / Residence', 'must-hotel-booking') . '</label></th><td><input id="must-guest-country" type="text" name="guest_country" value="' . \esc_attr((string) ($guest['country'] ?? '')) . '" class="regular-text" /></td></tr>';
        echo '</tbody></table>';
        \submit_button(\__('Save Guest Details', 'must-hotel-booking'));
        echo '</form>';
    } else {
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><td>';
        if (!empty($guest['guest_url'])) {
            echo '<a href="' . \esc_url((string) $guest['guest_url']) . '">' . \esc_html((string) ($guest['full_name'] ?? \__('Guest details missing', 'must-hotel-booking'))) . '</a>';
        } else {
            echo \esc_html((string) ($guest['full_name'] ?? \__('Guest details missing', 'must-hotel-booking')));
        }
        echo '</td></tr>';
        echo '<tr><th>' . \esc_html__('Email', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($guest['email'] ?? '')) . '</td></tr>';
        echo '<tr><th>' . \esc_html__('Phone', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($guest['phone'] ?? '')) . '</td></tr>';
        echo '<tr><th>' . \esc_html__('Country / Residence', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($guest['country'] ?? '')) . '</td></tr>';
        echo '</tbody></table>';
    }

    echo '</div></div>';

    echo '<div class="postbox must-dashboard-panel"><div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('Pricing / Totals', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>' . \esc_html__('Base amount', 'must-hotel-booking') . '</th><td>&ndash;</td></tr>';
    $couponCode = isset($pricing['coupon_code']) ? (string) $pricing['coupon_code'] : '';
    $couponDiscountTotal = isset($pricing['coupon_discount_total']) ? (float) $pricing['coupon_discount_total'] : 0.0;
    echo '<tr><th>' . \esc_html__('Coupon / Discount', 'must-hotel-booking') . '</th><td>';
    if ($couponCode !== '' || $couponDiscountTotal > 0.0) {
        echo \esc_html($couponCode !== '' ? $couponCode : __('Coupon applied', 'must-hotel-booking'));
        echo ' | ';
        echo \esc_html('-' . \number_format_i18n($couponDiscountTotal, 2) . ' ' . (string) ($pricing['currency'] ?? ''));
    } else {
        echo '&ndash;';
    }
    echo '</td></tr>';
    echo '<tr><th>' . \esc_html__('Taxes / Fees', 'must-hotel-booking') . '</th><td>&ndash;</td></tr>';
    echo '<tr><th>' . \esc_html__('Final total', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((float) ($pricing['stored_total'] ?? 0), 2) . ' ' . (string) ($pricing['currency'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Amount paid', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((float) ($pricing['amount_paid'] ?? 0), 2) . ' ' . (string) ($pricing['currency'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Amount due', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((float) ($pricing['amount_due'] ?? 0), 2) . ' ' . (string) ($pricing['currency'] ?? '')) . '</td></tr>';
    echo '</tbody></table>';
    echo '</div></div>';

    echo '<div class="postbox must-dashboard-panel"><div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('Payments', 'must-hotel-booking') . '</h2>';

    if (empty($payments)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No payment ledger rows are linked to this reservation yet.', 'must-hotel-booking') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . \esc_html__('Amount', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Method', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Paid At', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Created', 'must-hotel-booking') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($payments as $paymentRow) {
            if (!\is_array($paymentRow)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($paymentRow['amount'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['method'] ?? '')) . '</td>';
            echo '<td>' . render_admin_reservation_badge((string) ($paymentRow['status'] ?? ''), (string) ($paymentRow['status_key'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['transaction_id'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['paid_at'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['created_at'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div></div>';

    echo '<div class="postbox must-dashboard-panel"><div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('Timeline / Activity', 'must-hotel-booking') . '</h2>';

    if (empty($timeline)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No activity is recorded for this reservation yet.', 'must-hotel-booking') . '</p>';
    } else {
        echo '<div class="must-reservation-timeline">';

        foreach ($timeline as $timelineRow) {
            if (!\is_array($timelineRow)) {
                continue;
            }

            echo '<div class="must-reservation-timeline-item">';
            echo '<div class="must-reservation-timeline-meta">';
            echo render_admin_reservation_badge((string) ($timelineRow['severity'] ?? ''), (string) ($timelineRow['severity'] ?? ''));
            echo '<span>' . \esc_html((string) ($timelineRow['created_at'] ?? '')) . '</span>';
            echo '</div>';
            echo '<p>' . \esc_html((string) ($timelineRow['message'] ?? '')) . '</p>';

            if (!empty($timelineRow['reference'])) {
                echo '<p class="description">' . \esc_html((string) $timelineRow['reference']) . '</p>';
            }

            echo '</div>';
        }

        echo '</div>';
    }

    echo '</div></div>';

    echo '</div>';
    echo '<div class="must-reservation-detail-sidebar">';

    echo '<div class="postbox must-dashboard-panel"><div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('Summary', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th><td>' . \esc_html($bookingId) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Reservation status', 'must-hotel-booking') . '</th><td>' . render_admin_reservation_badge((string) ($summary['reservation_status'] ?? ''), $statusKey) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Payment status', 'must-hotel-booking') . '</th><td>' . render_admin_reservation_badge((string) ($summary['payment_status'] ?? ''), $paymentStatusKey) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Payment method', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($summary['payment_method'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Created date', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($summary['created_at'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Last updated', 'must-hotel-booking') . '</th><td>&ndash;</td></tr>';
    echo '<tr><th>' . \esc_html__('Source / Channel', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($summary['source'] ?? '')) . '</td></tr>';
    echo '</tbody></table>';
    echo '</div></div>';

    echo '<div class="postbox must-dashboard-panel"><div class="must-dashboard-panel-inner">';
    echo '<h2>' . \esc_html__('Emails / Communication', 'must-hotel-booking') . '</h2>';

    if (empty($emails)) {
        echo '<p class="must-dashboard-empty-state">' . \esc_html__('No email events are recorded for this reservation yet.', 'must-hotel-booking') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . \esc_html__('When', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Message', 'must-hotel-booking') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($emails as $emailRow) {
            if (!\is_array($emailRow)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($emailRow['created_at'] ?? '')) . '</td>';
            echo '<td>' . render_admin_reservation_badge((string) ($emailRow['event_type'] ?? ''), (string) ($emailRow['severity'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($emailRow['message'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div></div>';

    echo '</div>';
    echo '</div>';
}

function render_admin_reservations_page(): void
{
    ensure_admin_capability();

    $provider = new ReservationAdminDataProvider();
    /** @var array<string, mixed> $request */
    $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
    $pageData = $provider->getListPageData($request);
    $filters = isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [];
    $pagination = isset($pageData['pagination']) && \is_array($pageData['pagination']) ? $pageData['pagination'] : [];
    $quickFilters = isset($pageData['quick_filters']) && \is_array($pageData['quick_filters']) ? $pageData['quick_filters'] : [];
    $calendarUrl = \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
        ? get_admin_calendar_page_url(['start_date' => \current_time('Y-m-d'), 'weeks' => 2])
        : '';

    echo '<div class="wrap must-reservations-page">';
    echo '<div class="must-reservations-header must-reservations-hero">';
    echo '<div class="must-reservations-header-copy">';
    echo '<span class="must-reservations-eyebrow">' . \esc_html__('Front Desk Workspace', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Reservations', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Track arrivals, spot payment exceptions, and move into the full reservation detail view from a cleaner daily operations board.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-reservations-header-actions">';

    if ($calendarUrl !== '') {
        echo '<a class="button must-reservations-header-link" href="' . \esc_url($calendarUrl) . '">' . \esc_html__('Open Calendar', 'must-hotel-booking') . '</a>';
    }

    echo '<a class="button button-primary" href="' . \esc_url(get_admin_reservation_create_page_url()) . '">' . \esc_html__('Add Reservation', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    render_admin_reservations_notice_from_query();
    render_admin_reservations_overview($quickFilters);
    render_admin_reservations_quick_filters($quickFilters);
    render_admin_reservations_filters($filters, $pageData);
    render_admin_reservations_pagination($pagination, $filters);
    render_admin_reservations_table($pageData);
    render_admin_reservations_pagination($pagination, $filters);

    echo '<script>';
    echo 'document.addEventListener("DOMContentLoaded",function(){var toggle=document.getElementById("must-reservations-toggle-all");if(!toggle){return;}toggle.addEventListener("change",function(){document.querySelectorAll(".must-reservation-select").forEach(function(box){box.checked=toggle.checked;});});});';
    echo '</script>';
    echo '</div>';
}

function render_admin_reservation_detail_page(): void
{
    ensure_admin_capability();

    $reservationId = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $mode = isset($_GET['mode']) ? \sanitize_key((string) \wp_unslash($_GET['mode'])) : 'view';
    $provider = new ReservationAdminDataProvider();
    $detailData = $provider->getDetailPageData($reservationId, $mode);

    echo '<div class="wrap">';
    render_admin_reservations_notice_from_query();

    if (!\is_array($detailData)) {
        echo '<h1>' . \esc_html__('Reservation not found', 'must-hotel-booking') . '</h1>';
        echo '<div class="notice notice-error"><p>' . \esc_html__('The selected reservation could not be loaded.', 'must-hotel-booking') . '</p></div>';
        echo '<p><a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Back to Reservations', 'must-hotel-booking') . '</a></p>';
        echo '</div>';

        return;
    }

    render_admin_reservation_detail_page_content($detailData);
    echo '</div>';
}

function render_admin_reservation_create_page(): void
{
    ensure_admin_capability();

    $quickBookingState = [
        'errors' => [],
        'form' => null,
    ];

    if (\function_exists(__NAMESPACE__ . '\maybe_handle_admin_quick_booking_submission')) {
        $quickBookingState = maybe_handle_admin_quick_booking_submission();
    }

    $quickBookingErrors = isset($quickBookingState['errors']) && \is_array($quickBookingState['errors'])
        ? $quickBookingState['errors']
        : [];
    $quickBookingForm = isset($quickBookingState['form']) && \is_array($quickBookingState['form'])
        ? $quickBookingState['form']
        : null;

    echo '<div class="wrap">';
    echo '<div class="must-reservations-header">';
    echo '<div>';
    echo '<h1>' . \esc_html__('Add Reservation', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Use the validated quick-booking flow here to create a manual reservation and land directly on its reservation detail screen.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-reservations-header-actions">';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Back to Reservations', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    render_admin_reservations_notice_from_query();

    if (\function_exists(__NAMESPACE__ . '\render_admin_quick_booking_panel')) {
        render_admin_quick_booking_panel(
            $quickBookingForm,
            $quickBookingErrors,
            [
                'action_url' => get_admin_reservation_create_page_url(),
                'redirect_target' => 'reservation_detail',
                'eyebrow' => \__('Manual Reservation', 'must-hotel-booking'),
                'title' => \__('Create Reservation', 'must-hotel-booking'),
                'description' => \__('Use the fast validated flow here when staff already know the room, dates, and guest details.', 'must-hotel-booking'),
                'submit_label' => \__('Create Reservation', 'must-hotel-booking'),
            ]
        );
    }

    echo '</div>';
}

function maybe_handle_admin_reservations_actions_early(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if (\in_array($page, ['must-hotel-booking-reservations', 'must-hotel-booking-reservation'], true)) {
        (new ReservationAdminActions())->handleRequest();

        return;
    }

    if ($page !== 'must-hotel-booking-reservation-create') {
        return;
    }

    ensure_admin_capability();

    if (\function_exists(__NAMESPACE__ . '\maybe_handle_admin_quick_booking_submission')) {
        maybe_handle_admin_quick_booking_submission();
    }
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_admin_reservations_actions_early', 1);
