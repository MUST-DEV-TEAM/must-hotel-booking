<?php

namespace MustHotelBooking\Admin;

/**
 * @param array<string, scalar|int|bool> $args
 */
function get_admin_coupons_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-coupons');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

function is_coupons_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-coupons';
}

/**
 * @return array<string, mixed>
 */
function get_coupons_admin_save_state(): array
{
    global $mustHotelBookingCouponsAdminSaveState;

    if (isset($mustHotelBookingCouponsAdminSaveState) && \is_array($mustHotelBookingCouponsAdminSaveState)) {
        return $mustHotelBookingCouponsAdminSaveState;
    }

    $mustHotelBookingCouponsAdminSaveState = [
        'errors' => [],
        'form' => null,
        'selected_coupon_id' => 0,
    ];

    return $mustHotelBookingCouponsAdminSaveState;
}

/**
 * @param array<string, mixed> $state
 */
function set_coupons_admin_save_state(array $state): void
{
    global $mustHotelBookingCouponsAdminSaveState;
    $mustHotelBookingCouponsAdminSaveState = $state;
}

function clear_coupons_admin_save_state(): void
{
    set_coupons_admin_save_state([
        'errors' => [],
        'form' => null,
        'selected_coupon_id' => 0,
    ]);
}

function maybe_handle_coupon_admin_actions_early(): void
{
    if (!is_coupons_admin_request()) {
        return;
    }

    ensure_admin_capability();
    $query = CouponAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod === 'POST') {
        set_coupons_admin_save_state((new CouponAdminActions())->handleSaveRequest($query));
        return;
    }

    (new CouponAdminActions())->handleGetAction($query);
}

function render_coupons_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'coupon_created' => ['success', \__('Coupon created successfully.', 'must-hotel-booking')],
        'coupon_updated' => ['success', \__('Coupon updated successfully.', 'must-hotel-booking')],
        'coupon_enabled' => ['success', \__('Coupon enabled.', 'must-hotel-booking')],
        'coupon_disabled' => ['success', \__('Coupon disabled.', 'must-hotel-booking')],
        'coupon_deleted' => ['success', \__('Coupon deleted successfully.', 'must-hotel-booking')],
        'coupon_delete_blocked' => ['error', \__('Coupon cannot be deleted because reservations already use it.', 'must-hotel-booking')],
        'coupon_not_found' => ['error', \__('Coupon could not be found.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
        'action_failed' => ['error', \__('Unable to complete the requested coupon action.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $class = (string) $messages[$notice][0] === 'success' ? 'notice notice-success' : 'notice notice-error';
    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html((string) $messages[$notice][1]) . '</p></div>';
}

/**
 * @param array<int, string> $errors
 */
function render_coupon_error_notice(array $errors): void
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
function render_coupon_summary_cards(array $summaryCards): void
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
 */
function render_coupon_filters(array $filters): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Filters', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-coupons" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-coupon-filter-search">' . \esc_html__('Search', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-filter-search" class="regular-text" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Code or internal name', 'must-hotel-booking') . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-filter-status">' . \esc_html__('Status', 'must-hotel-booking') . '</label></th><td><select id="must-coupon-filter-status" name="status">';
    foreach (
        [
            '' => __('All statuses', 'must-hotel-booking'),
            'active' => __('Active', 'must-hotel-booking'),
            'inactive' => __('Inactive', 'must-hotel-booking'),
            'expired' => __('Expired', 'must-hotel-booking'),
            'scheduled' => __('Scheduled', 'must-hotel-booking'),
            'fully_used' => __('Fully used', 'must-hotel-booking'),
            'currently_valid' => __('Currently valid', 'must-hotel-booking'),
        ] as $value => $label
    ) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['status'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-filter-type">' . \esc_html__('Discount Type', 'must-hotel-booking') . '</label></th><td><select id="must-coupon-filter-type" name="discount_type">';
    foreach (
        [
            '' => __('All discount types', 'must-hotel-booking'),
            'percentage' => __('Percentage', 'must-hotel-booking'),
            'fixed' => __('Fixed', 'must-hotel-booking'),
        ] as $value => $label
    ) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['discount_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Apply Filters', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_coupons_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function render_coupon_table(array $rows): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Coupons Overview', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('Coupon Code', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Discount', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Valid From', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Valid Until', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Usage', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Remaining', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Last Used', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="10">' . \esc_html__('No coupons matched the current filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            echo '<tr>';
            echo '<td><strong><a href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '">' . \esc_html((string) ($row['code'] ?? '')) . '</a></strong></td>';
            echo '<td>' . \esc_html((string) (($row['name'] ?? '') !== '' ? $row['name'] : '—')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['discount_value'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['valid_from'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['valid_until'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['usage'] ?? 0)) . '</td>';
            echo '<td>' . \esc_html((string) ($row['remaining'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['last_used'] ?? '')) . '</td>';
            echo '<td><a class="button button-small" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url((string) ($row['toggle_url'] ?? '')) . '">' . \esc_html(!empty($row['is_active']) ? __('Disable', 'must-hotel-booking') : __('Enable', 'must-hotel-booking')) . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url((string) ($row['reservations_url'] ?? '')) . '">' . \esc_html__('Reservations', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small button-link-delete" href="' . \esc_url((string) ($row['delete_url'] ?? '')) . '" onclick="return confirm(\'' . \esc_js(__('Delete this coupon?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';

            if (!empty($row['warnings'])) {
                echo '<ul style="margin:8px 0 0 18px;">';
                foreach ((array) $row['warnings'] as $warning) {
                    echo '<li>' . \esc_html((string) $warning) . '</li>';
                }
                echo '</ul>';
            }

            echo '</td></tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<string, mixed>|null $form
 */
function render_coupon_form(?array $form): void
{
    if (!\is_array($form)) {
        return;
    }

    $isEdit = (int) ($form['coupon_id'] ?? 0) > 0;
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($isEdit ? __('Edit Coupon', 'must-hotel-booking') : __('Create Coupon', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_coupons_page_url()) . '">';
    \wp_nonce_field('must_coupon_save', 'must_coupon_nonce');
    echo '<input type="hidden" name="must_coupon_action" value="save_coupon" />';
    echo '<input type="hidden" name="coupon_id" value="' . \esc_attr((string) ($form['coupon_id'] ?? 0)) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-coupon-code">' . \esc_html__('Coupon code', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-code" class="regular-text" type="text" name="code" value="' . \esc_attr((string) ($form['code'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-name">' . \esc_html__('Internal name', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-name" class="regular-text" type="text" name="name" value="' . \esc_attr((string) ($form['name'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Active', 'must-hotel-booking') . '</th><td><label><input type="checkbox" name="is_active" value="1"' . \checked(!empty($form['is_active']), true, false) . ' /> ' . \esc_html__('Coupon can be used in checkout', 'must-hotel-booking') . '</label></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-discount-type">' . \esc_html__('Discount type', 'must-hotel-booking') . '</label></th><td><select id="must-coupon-discount-type" name="discount_type"><option value="percentage"' . \selected((string) ($form['discount_type'] ?? ''), 'percentage', false) . '>' . \esc_html__('Percentage', 'must-hotel-booking') . '</option><option value="fixed"' . \selected((string) ($form['discount_type'] ?? ''), 'fixed', false) . '>' . \esc_html__('Fixed', 'must-hotel-booking') . '</option></select></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-discount-value">' . \esc_html__('Discount value', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-discount-value" type="number" min="0.01" step="0.01" name="discount_value" value="' . \esc_attr((string) ($form['discount_value'] ?? 0)) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-minimum-booking-amount">' . \esc_html__('Minimum booking amount', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-minimum-booking-amount" type="number" min="0" step="0.01" name="minimum_booking_amount" value="' . \esc_attr((string) ($form['minimum_booking_amount'] ?? 0)) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-valid-from">' . \esc_html__('Valid from', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-valid-from" type="date" name="valid_from" value="' . \esc_attr((string) ($form['valid_from'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-valid-until">' . \esc_html__('Valid until', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-valid-until" type="date" name="valid_until" value="' . \esc_attr((string) ($form['valid_until'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-coupon-usage-limit">' . \esc_html__('Usage limit', 'must-hotel-booking') . '</label></th><td><input id="must-coupon-usage-limit" type="number" min="0" step="1" name="usage_limit" value="' . \esc_attr((string) ($form['usage_limit'] ?? 0)) . '" />';
    echo '<p class="description">' . \esc_html__('Set 0 for unlimited usage.', 'must-hotel-booking') . '</p></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Used / Remaining', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ((int) ($form['used_count'] ?? 0))) . ' / ' . \esc_html(isset($form['remaining_usage']) && $form['remaining_usage'] !== null ? (string) $form['remaining_usage'] : __('Unlimited', 'must-hotel-booking')) . '</td></tr>';
    echo '</tbody></table>';
    \submit_button($isEdit ? __('Save Coupon', 'must-hotel-booking') : __('Create Coupon', 'must-hotel-booking'));
    echo '</form>';

    if (!empty($form['warnings'])) {
        echo '<h3>' . \esc_html__('Warnings', 'must-hotel-booking') . '</h3><ul>';
        foreach ((array) $form['warnings'] as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

/**
 * @param array<string, mixed>|null $detail
 */
function render_coupon_detail(?array $detail): void
{
    if (!\is_array($detail)) {
        return;
    }

    $coupon = isset($detail['coupon']) && \is_array($detail['coupon']) ? $detail['coupon'] : [];
    $state = isset($detail['state']) && \is_array($detail['state']) ? $detail['state'] : [];
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Coupon Detail', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped" style="margin-bottom:20px;"><tbody>';
    echo '<tr><th>' . \esc_html__('Coupon code', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($coupon['code'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Name', 'must-hotel-booking') . '</th><td>' . \esc_html((string) (($coupon['name'] ?? '') !== '' ? $coupon['name'] : '—')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($state['status'] ?? 'inactive')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Usable now', 'must-hotel-booking') . '</th><td>' . \esc_html(!empty($state['usable_now']) ? __('Yes', 'must-hotel-booking') : __('No', 'must-hotel-booking')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Reservations using this coupon', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ((int) ($coupon['used_count'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Future reservations using this coupon', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ((int) ($coupon['future_reservation_count'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Last used', 'must-hotel-booking') . '</th><td>' . \esc_html((string) (($coupon['last_used_at'] ?? '') !== '' ? $coupon['last_used_at'] : '—')) . '</td></tr>';
    echo '</tbody></table>';

    if (!empty($detail['warnings'])) {
        echo '<h3>' . \esc_html__('Warnings', 'must-hotel-booking') . '</h3><ul>';
        foreach ((array) $detail['warnings'] as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul>';
    }

    echo '<h3>' . \esc_html__('Reservations Using This Coupon', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th><th>' . \esc_html__('Stay', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Discount', 'must-hotel-booking') . '</th><th>' . \esc_html__('Action', 'must-hotel-booking') . '</th></tr></thead><tbody>';

    if (empty($detail['usage_rows'])) {
        echo '<tr><td colspan="7">' . \esc_html__('No reservations have used this coupon yet.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ((array) $detail['usage_rows'] as $usageRow) {
            if (!\is_array($usageRow)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($usageRow['booking_id'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($usageRow['guest_name'] ?? '')) . '<br /><span style="color:#646970;">' . \esc_html((string) ($usageRow['guest_email'] ?? '')) . '</span></td>';
            echo '<td>' . \esc_html((string) ($usageRow['room_name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($usageRow['checkin'] ?? '')) . ' → ' . \esc_html((string) ($usageRow['checkout'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($usageRow['status'] ?? '')) . ' | ' . \esc_html((string) ($usageRow['payment_status'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($usageRow['discount_total'] ?? '')) . '</td>';
            echo '<td><a class="button button-small" href="' . \esc_url((string) ($usageRow['detail_url'] ?? '')) . '">' . \esc_html__('Open Reservation', 'must-hotel-booking') . '</a></td>';
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
function render_coupon_pagination(array $pagination, array $args): void
{
    $totalPages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;

    if ($totalPages <= 1) {
        return;
    }

    $currentPage = isset($pagination['current_page']) ? (int) $pagination['current_page'] : 1;
    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo \wp_kses_post(
        \paginate_links([
            'base' => \esc_url_raw(get_admin_coupons_page_url(\array_merge($args, ['paged' => '%#%']))),
            'format' => '',
            'current' => $currentPage,
            'total' => $totalPages,
        ])
    );
    echo '</div></div>';
}

function render_admin_coupons_page(): void
{
    ensure_admin_capability();

    $query = CouponAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new CouponAdminDataProvider())->getPageData($query, get_coupons_admin_save_state());
    clear_coupons_admin_save_state();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Coupons', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage booking discounts used by checkout, reservation totals, and payment collection from one operational screen.', 'must-hotel-booking') . '</p>';
    echo '<p><a class="button button-secondary" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('Open Rates & Pricing', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments', 'must-hotel-booking') . '</a></p>';

    render_coupons_admin_notice_from_query();
    render_coupon_error_notice(isset($pageData['errors']) && \is_array($pageData['errors']) ? $pageData['errors'] : []);
    render_coupon_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    render_coupon_filters(isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : []);
    echo '<div class="notice notice-info inline"><p>' . \esc_html((string) ($pageData['calculation_note'] ?? '')) . '</p></div>';
    render_coupon_table(isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : []);
    render_coupon_pagination(
        isset($pageData['pagination']) && \is_array($pageData['pagination']) ? $pageData['pagination'] : [],
        $query->buildUrlArgs()
    );
    render_coupon_form(isset($pageData['form']) && \is_array($pageData['form']) ? $pageData['form'] : null);
    render_coupon_detail(isset($pageData['detail']) && \is_array($pageData['detail']) ? $pageData['detail'] : null);
    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_coupon_admin_actions_early', 1);
