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
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:18px 0;">';

    foreach ($summaryCards as $card) {
        if (!\is_array($card)) {
            continue;
        }

        echo '<article class="postbox" style="margin:0;padding:18px 20px;">';
        echo '<p style="margin:0 0 8px 0;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;">' . \esc_html((string) ($card['label'] ?? '')) . '</p>';
        echo '<strong style="display:block;font-size:32px;line-height:1.1;">' . \esc_html((string) ($card['value'] ?? '0')) . '</strong>';
        echo '<p style="margin:8px 0 0 0;color:#646970;">' . \esc_html((string) ($card['meta'] ?? '')) . '</p>';
        echo '</article>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, string> $statusOptions
 * @param array<string, string> $reservationStatusOptions
 * @param array<string, string> $methodOptions
 * @param array<string, string> $paymentGroupOptions
 */
function render_payments_filters(array $filters, array $statusOptions, array $reservationStatusOptions, array $methodOptions, array $paymentGroupOptions): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Filters', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-payments" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-payment-filter-search">' . \esc_html__('Search', 'must-hotel-booking') . '</label></th><td><input id="must-payment-filter-search" class="regular-text" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Booking ID, guest, email, Stripe reference', 'must-hotel-booking') . '" /></td></tr>';

    echo '<tr><th scope="row"><label for="must-payment-filter-status">' . \esc_html__('Payment status', 'must-hotel-booking') . '</label></th><td><select id="must-payment-filter-status" name="status">';
    foreach ($statusOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['status'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-payment-filter-group">' . \esc_html__('Operational group', 'must-hotel-booking') . '</label></th><td><select id="must-payment-filter-group" name="payment_group">';
    foreach ($paymentGroupOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['payment_group'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-payment-filter-method">' . \esc_html__('Payment method', 'must-hotel-booking') . '</label></th><td><select id="must-payment-filter-method" name="method">';
    foreach ($methodOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['method'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-payment-filter-reservation-status">' . \esc_html__('Reservation status', 'must-hotel-booking') . '</label></th><td><select id="must-payment-filter-reservation-status" name="reservation_status"><option value="">' . \esc_html__('All reservation statuses', 'must-hotel-booking') . '</option>';
    foreach ($reservationStatusOptions as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['reservation_status'] ?? ''), (string) $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-payment-filter-date-from">' . \esc_html__('Date range', 'must-hotel-booking') . '</label></th><td>';
    echo '<input id="must-payment-filter-date-from" type="date" name="date_from" value="' . \esc_attr((string) ($filters['date_from'] ?? '')) . '" /> ';
    echo '<input type="date" name="date_to" value="' . \esc_attr((string) ($filters['date_to'] ?? '')) . '" />';
    echo '<p class="description">' . \esc_html__('Uses the payment timestamp when available, otherwise the latest payment activity or reservation creation date.', 'must-hotel-booking') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . \esc_html__('Flags', 'must-hotel-booking') . '</th><td>';
    echo '<label><input type="checkbox" name="due_only" value="1"' . \checked(!empty($filters['due_only']), true, false) . ' /> ' . \esc_html__('Only show reservations with amount due', 'must-hotel-booking') . '</label>';
    echo '</td></tr>';

    echo '</tbody></table>';
    \submit_button(\__('Apply Filters', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function render_payments_table(array $rows): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Payments Overview', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
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

    if (empty($rows)) {
        echo '<tr><td colspan="12">' . \esc_html__('No reservations matched the current payment filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            echo '<tr>';
            echo '<td><strong><a href="' . \esc_url((string) ($row['detail_url'] ?? '')) . '">' . \esc_html((string) ($row['booking_id'] ?? '')) . '</a></strong>';
            if ((string) ($row['reservation_url'] ?? '') !== '') {
                echo '<br /><a href="' . \esc_url((string) $row['reservation_url']) . '">' . \esc_html__('Open reservation', 'must-hotel-booking') . '</a>';
            }
            echo '</td>';
            echo '<td>' . \esc_html((string) ($row['guest'] ?? '')) . '<br /><span style="color:#646970;">' . \esc_html((string) ($row['guest_email'] ?? '')) . '</span></td>';
            echo '<td>' . \esc_html((string) ($row['accommodation'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['payment_method'] ?? '')) . '</td>';
            echo '<td><strong>' . \esc_html((string) ($row['payment_status'] ?? '')) . '</strong>';
            if (!empty($row['needs_review'])) {
                echo '<br /><span style="color:#b32d2e;">' . \esc_html__('Needs review', 'must-hotel-booking') . '</span>';
            }
            echo '</td>';
            echo '<td>' . \esc_html((string) ($row['reservation_status'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['total'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['amount_paid'] ?? '')) . '</td>';
            echo '<td>' . ((bool) ($row['has_due'] ?? false) ? '<strong style="color:#b32d2e;">' : '') . \esc_html((string) ($row['amount_due'] ?? '')) . ((bool) ($row['has_due'] ?? false) ? '</strong>' : '') . '</td>';
            echo '<td>' . \esc_html((string) ($row['transaction_id'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) (($row['paid_at'] ?? '') !== '' ? $row['paid_at'] : ($row['updated_at'] ?? ''))) . '</td>';
            echo '<td>';

            foreach ((array) ($row['actions'] ?? []) as $action => $url) {
                $labels = [
                    'mark_paid' => \__('Mark Paid', 'must-hotel-booking'),
                    'mark_unpaid' => \__('Mark Unpaid', 'must-hotel-booking'),
                    'mark_pending' => \__('Mark Pending', 'must-hotel-booking'),
                    'mark_pay_at_hotel' => \__('Pay At Hotel', 'must-hotel-booking'),
                    'mark_failed' => \__('Mark Failed', 'must-hotel-booking'),
                    'resend_guest_email' => \__('Resend Guest Email', 'must-hotel-booking'),
                ];

                if (!isset($labels[$action])) {
                    continue;
                }

                echo '<a class="button button-small" href="' . \esc_url((string) $url) . '">' . \esc_html($labels[$action]) . '</a> ';
            }

            if (!empty($row['warnings'])) {
                echo '<div style="margin-top:8px;"><ul style="margin:0;padding-left:18px;">';
                foreach ((array) $row['warnings'] as $warning) {
                    echo '<li>' . \esc_html((string) $warning) . '</li>';
                }
                echo '</ul></div>';
            }

            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $pagination
 * @param array<string, scalar|int|bool> $args
 */
function render_payments_pagination(array $pagination, array $args): void
{
    $totalPages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;

    if ($totalPages <= 1) {
        return;
    }

    $currentPage = isset($pagination['current_page']) ? (int) $pagination['current_page'] : 1;
    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo \wp_kses_post(
        \paginate_links([
            'base' => \esc_url_raw(get_admin_payments_page_url(\array_merge($args, ['paged' => '%#%']))),
            'format' => '',
            'current' => $currentPage,
            'total' => $totalPages,
        ])
    );
    echo '</div></div>';
}

/**
 * @param array<string, mixed>|null $detail
 */
function render_payment_detail(?array $detail): void
{
    if (!\is_array($detail)) {
        return;
    }

    $reservation = isset($detail['reservation']) && \is_array($detail['reservation']) ? $detail['reservation'] : [];
    $state = isset($detail['state']) && \is_array($detail['state']) ? $detail['state'] : [];

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Payment Detail', 'must-hotel-booking') . '</h2>';
    echo '<p><a class="button button-secondary" href="' . \esc_url((string) ($detail['reservation_url'] ?? '')) . '">' . \esc_html__('Open Reservation', 'must-hotel-booking') . '</a></p>';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($detail['booking_id'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Reservation status', 'must-hotel-booking') . '</th><td>' . \esc_html(get_reservation_status_options()[(string) ($reservation['status'] ?? '')] ?? (string) ($reservation['status'] ?? '')) . '</td></tr>';
    $detailStatusLabels = [
        'paid' => __('Paid', 'must-hotel-booking'),
        'unpaid' => __('Unpaid', 'must-hotel-booking'),
        'partially_paid' => __('Partially Paid', 'must-hotel-booking'),
        'pending' => __('Pending', 'must-hotel-booking'),
        'failed' => __('Failed', 'must-hotel-booking'),
        'refunded' => __('Refunded', 'must-hotel-booking'),
        'pay_at_hotel' => __('Pay At Hotel', 'must-hotel-booking'),
    ];
    echo '<tr><th scope="row">' . \esc_html__('Payment status', 'must-hotel-booking') . '</th><td>' . \esc_html($detailStatusLabels[(string) ($state['derived_status'] ?? '')] ?? (string) ($state['derived_status'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Payment method', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($state['method'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Total / Paid / Due', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((float) ($state['total'] ?? 0.0), 2) . ' / ' . \number_format_i18n((float) ($state['amount_paid'] ?? 0.0), 2) . ' / ' . \number_format_i18n((float) ($state['amount_due'] ?? 0.0), 2) . ' ' . MustBookingConfig::get_currency()) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Guest', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($detail['guest_name'] ?? '')) . ' | ' . \esc_html((string) ($detail['guest_email'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($detail['accommodation'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Stay', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($reservation['checkin'] ?? '') . ' -> ' . (string) ($reservation['checkout'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Stripe / transaction reference', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($state['transaction_id'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Paid at', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($state['paid_at'] ?? '')) . '</td></tr>';
    echo '</tbody></table>';

    $warnings = isset($state['warnings']) && \is_array($state['warnings']) ? $state['warnings'] : [];

    if (!empty($warnings)) {
        echo '<h3>' . \esc_html__('Warnings', 'must-hotel-booking') . '</h3><ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul>';
    }

    echo '<h3>' . \esc_html__('Transaction Rows', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped"><thead><tr>';
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
        echo '<td>' . \esc_html(\number_format_i18n((float) ($paymentRow['amount'] ?? 0.0), 2) . ' ' . (string) ($paymentRow['currency'] ?? MustBookingConfig::get_currency())) . '</td>';
        echo '<td>' . \esc_html((string) ($paymentRow['method'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($paymentRow['status'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($paymentRow['transaction_id'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($paymentRow['paid_at'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($paymentRow['created_at'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<h3>' . \esc_html__('Activity Timeline', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('When', 'must-hotel-booking') . '</th><th>' . \esc_html__('Event', 'must-hotel-booking') . '</th><th>' . \esc_html__('Message', 'must-hotel-booking') . '</th></tr></thead><tbody>';

    foreach ((array) ($detail['timeline'] ?? []) as $timelineRow) {
        if (!\is_array($timelineRow)) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . \esc_html((string) ($timelineRow['created_at'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($timelineRow['event_type'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($timelineRow['message'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
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

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Payment Settings', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_payments_page_url()) . '">';
    \wp_nonce_field('must_payment_settings_save', 'must_payment_settings_nonce');
    echo '<input type="hidden" name="must_payments_action" value="save_payment_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    foreach ($catalog as $method => $meta) {
        $enabled = !empty($states[$method]);
        echo '<tr><th scope="row">' . \esc_html((string) ($meta['label'] ?? $method)) . '</th><td>';
        echo '<label><input type="checkbox" name="payment_methods[' . \esc_attr((string) $method) . ']" value="1"' . \checked($enabled, true, false) . ' /> ' . \esc_html__('Enable', 'must-hotel-booking') . '</label>';
        if (!empty($meta['description'])) {
            echo '<p class="description">' . \esc_html((string) $meta['description']) . '</p>';
        }
        echo '</td></tr>';
    }

    echo '</tbody></table>';
    echo '<h3>' . \esc_html__('Stripe Checkout', 'must-hotel-booking') . '</h3>';
    echo '<p><strong>' . \esc_html__('Active Stripe profile:', 'must-hotel-booking') . '</strong> ' . \esc_html(PaymentEngine::getSiteEnvironmentLabel($activeEnvironment)) . '</p>';

    foreach ($environmentCatalog as $environmentKey => $environmentMeta) {
        if (!\is_string($environmentKey)) {
            continue;
        }

        $credentials = PaymentEngine::getStripeEnvironmentCredentials($environmentKey);
        $isActive = $environmentKey === $activeEnvironment;
        echo '<div style="margin-top:20px; padding:16px; border:1px solid #dcdcde; border-radius:8px; background:' . ($isActive ? '#f6ffed' : '#fff') . ';">';
        echo '<h3 style="margin-top:0;">' . \esc_html((string) ($environmentMeta['label'] ?? $environmentKey)) . ($isActive ? ' <span style="font-size:12px;color:#2271b1;">' . \esc_html__('Active', 'must-hotel-booking') . '</span>' : '') . '</h3>';
        if (!empty($environmentMeta['description'])) {
            echo '<p class="description">' . \esc_html((string) $environmentMeta['description']) . '</p>';
        }

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="must-stripe-' . \esc_attr($environmentKey) . '-publishable-key">' . \esc_html__('Publishable key', 'must-hotel-booking') . '</label></th><td><input id="must-stripe-' . \esc_attr($environmentKey) . '-publishable-key" type="text" class="regular-text" name="stripe_' . \esc_attr($environmentKey) . '_publishable_key" value="' . \esc_attr((string) ($credentials['publishable_key'] ?? '')) . '" autocomplete="off" /></td></tr>';
        echo '<tr><th scope="row"><label for="must-stripe-' . \esc_attr($environmentKey) . '-secret-key">' . \esc_html__('Secret key', 'must-hotel-booking') . '</label></th><td><input id="must-stripe-' . \esc_attr($environmentKey) . '-secret-key" type="password" class="regular-text" name="stripe_' . \esc_attr($environmentKey) . '_secret_key" value="' . \esc_attr((string) ($credentials['secret_key'] ?? '')) . '" autocomplete="new-password" /></td></tr>';
        echo '<tr><th scope="row"><label for="must-stripe-' . \esc_attr($environmentKey) . '-webhook-secret">' . \esc_html__('Webhook signing secret', 'must-hotel-booking') . '</label></th><td><input id="must-stripe-' . \esc_attr($environmentKey) . '-webhook-secret" type="password" class="regular-text" name="stripe_' . \esc_attr($environmentKey) . '_webhook_secret" value="' . \esc_attr((string) ($credentials['webhook_secret'] ?? '')) . '" autocomplete="new-password" /></td></tr>';
        echo '</tbody></table>';
        echo '</div>';
    }

    echo '<p><strong>' . \esc_html__('Webhook URL:', 'must-hotel-booking') . '</strong> <code>' . \esc_html($webhookUrl) . '</code></p>';
    \submit_button(\__('Save Payment Settings', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';
}

function render_admin_payments_page(): void
{
    ensure_admin_capability();

    $query = PaymentAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new PaymentAdminDataProvider())->getPageData($query, get_payments_admin_save_state());
    clear_payments_admin_save_state();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Payments', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage paid, unpaid, pay-at-hotel, failed, and incomplete booking payments from the same ledger and reservation state used by checkout, Stripe sync, and booking emails.', 'must-hotel-booking') . '</p>';
    echo '<p>';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url(['preset' => 'unpaid'])) . '">' . \esc_html__('Open Unpaid Reservations', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Open Emails', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_settings_page_url(['tab' => 'general'])) . '">' . \esc_html__('Open Settings', 'must-hotel-booking') . '</a>';
    echo '</p>';

    render_payments_admin_notice_from_query();
    render_payments_error_notice(isset($pageData['settings_errors']) && \is_array($pageData['settings_errors']) ? $pageData['settings_errors'] : []);
    render_payments_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    render_payments_filters(
        isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [],
        isset($pageData['status_options']) && \is_array($pageData['status_options']) ? $pageData['status_options'] : [],
        isset($pageData['reservation_status_options']) && \is_array($pageData['reservation_status_options']) ? $pageData['reservation_status_options'] : [],
        isset($pageData['method_options']) && \is_array($pageData['method_options']) ? $pageData['method_options'] : [],
        isset($pageData['payment_group_options']) && \is_array($pageData['payment_group_options']) ? $pageData['payment_group_options'] : []
    );
    render_payments_table(isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : []);
    render_payments_pagination(
        isset($pageData['pagination']) && \is_array($pageData['pagination']) ? $pageData['pagination'] : [],
        $query->buildUrlArgs()
    );
    render_payment_detail(isset($pageData['detail']) && \is_array($pageData['detail']) ? $pageData['detail'] : null);
    render_payment_settings_panel(isset($pageData['settings']) && \is_array($pageData['settings']) ? $pageData['settings'] : []);
    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_payments_admin_actions_early', 1);
