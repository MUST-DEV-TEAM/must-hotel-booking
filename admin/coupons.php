<?php

namespace must_hotel_booking;

/**
 * Get coupons table name.
 */
function get_admin_coupons_table_name(): string
{
    global $wpdb;

    if (\function_exists(__NAMESPACE__ . '\get_coupons_table_name')) {
        return get_coupons_table_name();
    }

    return $wpdb->prefix . 'must_coupons';
}

/**
 * Build coupons admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_coupons_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-coupons');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Coupon discount types.
 *
 * @return array<string, string>
 */
function get_coupon_type_options(): array
{
    return [
        'percentage' => \__('Percentage', 'must-hotel-booking'),
        'fixed' => \__('Fixed', 'must-hotel-booking'),
    ];
}

/**
 * Validate coupon date in YYYY-MM-DD format.
 */
function is_valid_coupon_date(string $date): bool
{
    $candidate = \trim($date);

    if (\function_exists(__NAMESPACE__ . '\is_valid_booking_date')) {
        return is_valid_booking_date($candidate);
    }

    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

    return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $candidate;
}

/**
 * Check if coupon code already exists.
 */
function does_coupon_code_exist(string $code, int $exclude_id = 0): bool
{
    global $wpdb;

    if ($code === '') {
        return false;
    }

    if ($exclude_id > 0) {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . get_admin_coupons_table_name() . ' WHERE code = %s AND id <> %d',
                $code,
                $exclude_id
            )
        );
    } else {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . get_admin_coupons_table_name() . ' WHERE code = %s',
                $code
            )
        );
    }

    return $count > 0;
}

/**
 * Build default coupon form values.
 *
 * @return array<string, mixed>
 */
function get_coupon_form_defaults(): array
{
    $today = \current_time('Y-m-d');
    $next_month = (new \DateTimeImmutable($today))->modify('+30 day')->format('Y-m-d');

    return [
        'coupon_id' => 0,
        'code' => '',
        'discount_type' => 'percentage',
        'discount_value' => 0.00,
        'valid_from' => $today,
        'valid_until' => $next_month,
        'usage_limit' => 0,
        'usage_count' => 0,
    ];
}

/**
 * Sanitize and validate coupon form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_coupon_form_values(array $source): array
{
    $coupon_id = isset($source['coupon_id']) ? \absint(\wp_unslash($source['coupon_id'])) : 0;
    $code = isset($source['code']) ? \strtoupper(\sanitize_text_field((string) \wp_unslash($source['code']))) : '';
    $discount_type = isset($source['discount_type']) ? \sanitize_key((string) \wp_unslash($source['discount_type'])) : 'percentage';
    $discount_value = isset($source['discount_value']) ? (float) \wp_unslash($source['discount_value']) : 0.0;
    $valid_from = isset($source['valid_from']) ? \sanitize_text_field((string) \wp_unslash($source['valid_from'])) : '';
    $valid_until = isset($source['valid_until']) ? \sanitize_text_field((string) \wp_unslash($source['valid_until'])) : '';
    $usage_limit = isset($source['usage_limit']) ? \max(0, \absint(\wp_unslash($source['usage_limit']))) : 0;
    $errors = [];

    $code = (string) \preg_replace('/[^A-Z0-9_-]/', '', $code);
    $type_options = get_coupon_type_options();

    if ($code === '') {
        $errors[] = \__('Coupon code is required.', 'must-hotel-booking');
    } elseif (does_coupon_code_exist($code, $coupon_id)) {
        $errors[] = \__('Coupon code already exists.', 'must-hotel-booking');
    }

    if (!isset($type_options[$discount_type])) {
        $errors[] = \__('Coupon type must be percentage or fixed.', 'must-hotel-booking');
    }

    if ($discount_value <= 0) {
        $errors[] = \__('Discount value must be greater than zero.', 'must-hotel-booking');
    }

    if (!is_valid_coupon_date($valid_from) || !is_valid_coupon_date($valid_until)) {
        $errors[] = \__('Valid from and valid until dates are required.', 'must-hotel-booking');
    } elseif ($valid_from > $valid_until) {
        $errors[] = \__('Valid until date must be on or after valid from date.', 'must-hotel-booking');
    }

    return [
        'coupon_id' => $coupon_id,
        'code' => $code,
        'discount_type' => $discount_type,
        'discount_value' => \round($discount_value, 2),
        'valid_from' => $valid_from,
        'valid_until' => $valid_until,
        'usage_limit' => $usage_limit,
        'errors' => $errors,
    ];
}

/**
 * Get coupon row by ID.
 *
 * @return array<string, mixed>|null
 */
function get_coupon_row(int $coupon_id): ?array
{
    global $wpdb;

    if ($coupon_id <= 0) {
        return null;
    }

    $coupon = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id, code, discount_type, discount_value, valid_from, valid_until, usage_limit, usage_count, created_at
            FROM ' . get_admin_coupons_table_name() . ' WHERE id = %d LIMIT 1',
            $coupon_id
        ),
        ARRAY_A
    );

    return \is_array($coupon) ? $coupon : null;
}

/**
 * Get coupon rows for table.
 *
 * @return array<int, array<string, mixed>>
 */
function get_coupon_rows(): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        'SELECT id, code, discount_type, discount_value, valid_from, valid_until, usage_limit, usage_count, created_at
        FROM ' . get_admin_coupons_table_name() . '
        ORDER BY id DESC',
        ARRAY_A
    );

    return \is_array($rows) ? $rows : [];
}

/**
 * Handle delete coupon action.
 */
function maybe_handle_coupon_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_coupon') {
        return;
    }

    $coupon_id = isset($_GET['coupon_id']) ? \absint(\wp_unslash($_GET['coupon_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($coupon_id <= 0 || !\wp_verify_nonce($nonce, 'must_coupon_delete_' . $coupon_id)) {
        \wp_safe_redirect(get_admin_coupons_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    global $wpdb;

    $deleted = $wpdb->delete(get_admin_coupons_table_name(), ['id' => $coupon_id], ['%d']);

    \wp_safe_redirect(get_admin_coupons_page_url(['notice' => $deleted !== false ? 'coupon_deleted' : 'coupon_delete_failed']));
    exit;
}

/**
 * Handle save coupon action.
 *
 * @return array<string, mixed>
 */
function maybe_handle_coupon_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $action = isset($_POST['must_coupon_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_coupon_action'])) : '';

    if ($action !== 'save_coupon') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $nonce = isset($_POST['must_coupon_nonce']) ? (string) \wp_unslash($_POST['must_coupon_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_coupon_save')) {
        return [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $coupon_data = sanitize_coupon_form_values($raw_post);
    $coupon_id = (int) $coupon_data['coupon_id'];

    if (!empty($coupon_data['errors'])) {
        return [
            'errors' => (array) $coupon_data['errors'],
            'form' => $coupon_data,
        ];
    }

    global $wpdb;

    $payload = [
        'code' => (string) $coupon_data['code'],
        'discount_type' => (string) $coupon_data['discount_type'],
        'discount_value' => (float) $coupon_data['discount_value'],
        'valid_from' => (string) $coupon_data['valid_from'],
        'valid_until' => (string) $coupon_data['valid_until'],
        'usage_limit' => (int) $coupon_data['usage_limit'],
    ];

    $saved_coupon_id = 0;

    if ($coupon_id > 0) {
        $updated = $wpdb->update(
            get_admin_coupons_table_name(),
            $payload,
            ['id' => $coupon_id],
            ['%s', '%s', '%f', '%s', '%s', '%d'],
            ['%d']
        );

        if ($updated !== false) {
            $saved_coupon_id = $coupon_id;
        }
    } else {
        $payload['usage_count'] = 0;
        $payload['created_at'] = \current_time('mysql');

        $inserted = $wpdb->insert(
            get_admin_coupons_table_name(),
            $payload,
            ['%s', '%s', '%f', '%s', '%s', '%d', '%d', '%s']
        );

        if ($inserted !== false) {
            $saved_coupon_id = (int) $wpdb->insert_id;
        }
    }

    if ($saved_coupon_id <= 0) {
        return [
            'errors' => [\__('Unable to save coupon. Please check database schema.', 'must-hotel-booking')],
            'form' => $coupon_data,
        ];
    }

    \wp_safe_redirect(
        get_admin_coupons_page_url(
            [
                'notice' => $coupon_id > 0 ? 'coupon_updated' : 'coupon_created',
                'action' => 'edit',
                'coupon_id' => $saved_coupon_id,
            ]
        )
    );
    exit;
}

/**
 * Build coupon form data.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_coupon_form_data(?array $submitted_form = null): array
{
    $defaults = get_coupon_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';
    $coupon_id = isset($_GET['coupon_id']) ? \absint(\wp_unslash($_GET['coupon_id'])) : 0;

    if ($action !== 'edit' || $coupon_id <= 0) {
        return $defaults;
    }

    $coupon = get_coupon_row($coupon_id);

    if (!\is_array($coupon)) {
        return $defaults;
    }

    return [
        'coupon_id' => (int) ($coupon['id'] ?? 0),
        'code' => (string) ($coupon['code'] ?? ''),
        'discount_type' => (string) ($coupon['discount_type'] ?? 'percentage'),
        'discount_value' => (float) ($coupon['discount_value'] ?? 0),
        'valid_from' => (string) ($coupon['valid_from'] ?? $defaults['valid_from']),
        'valid_until' => (string) ($coupon['valid_until'] ?? $defaults['valid_until']),
        'usage_limit' => (int) ($coupon['usage_limit'] ?? 0),
        'usage_count' => (int) ($coupon['usage_count'] ?? 0),
    ];
}

/**
 * Render coupons admin notices.
 */
function render_coupons_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'coupon_created' => ['success', \__('Coupon created successfully.', 'must-hotel-booking')],
        'coupon_updated' => ['success', \__('Coupon updated successfully.', 'must-hotel-booking')],
        'coupon_deleted' => ['success', \__('Coupon deleted successfully.', 'must-hotel-booking')],
        'coupon_delete_failed' => ['error', \__('Unable to delete coupon.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';

    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';
}

/**
 * Render coupons admin page.
 */
function render_admin_coupons_page(): void
{
    ensure_admin_capability();

    maybe_handle_coupon_delete_request();

    $save_state = maybe_handle_coupon_save_request();
    $errors = isset($save_state['errors']) && \is_array($save_state['errors']) ? $save_state['errors'] : [];
    $submitted_form = isset($save_state['form']) && \is_array($save_state['form']) ? $save_state['form'] : null;

    $form = get_coupon_form_data($submitted_form);
    $coupons = get_coupon_rows();
    $types = get_coupon_type_options();
    $is_edit_mode = (int) $form['coupon_id'] > 0;

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Coupons', 'must-hotel-booking') . '</h1>';

    render_coupons_admin_notice_from_query();

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($is_edit_mode ? __('Edit Coupon', 'must-hotel-booking') : __('Create Coupon', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_coupons_page_url()) . '">';
    \wp_nonce_field('must_coupon_save', 'must_coupon_nonce');

    echo '<input type="hidden" name="must_coupon_action" value="save_coupon" />';
    echo '<input type="hidden" name="coupon_id" value="' . \esc_attr((string) $form['coupon_id']) . '" />';

    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-coupon-code">' . \esc_html__('Coupon code', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-coupon-code" type="text" class="regular-text" name="code" value="' . \esc_attr((string) $form['code']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-coupon-type">' . \esc_html__('Discount type', 'must-hotel-booking') . '</label></th>';
    echo '<td><select id="must-coupon-type" name="discount_type">';

    foreach ($types as $value => $label) {
        $selected = ((string) $form['discount_type'] === $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-coupon-value">' . \esc_html__('Discount value', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-coupon-value" type="number" min="0.01" step="0.01" name="discount_value" value="' . \esc_attr((string) $form['discount_value']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-coupon-valid-from">' . \esc_html__('Valid from', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-coupon-valid-from" type="date" name="valid_from" value="' . \esc_attr((string) $form['valid_from']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-coupon-valid-until">' . \esc_html__('Valid until', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-coupon-valid-until" type="date" name="valid_until" value="' . \esc_attr((string) $form['valid_until']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-coupon-usage-limit">' . \esc_html__('Usage limit', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-coupon-usage-limit" type="number" min="0" step="1" name="usage_limit" value="' . \esc_attr((string) $form['usage_limit']) . '" required />';
    echo '<p class="description">' . \esc_html__('Set 0 for unlimited usage.', 'must-hotel-booking') . '</p></td></tr>';
    echo '</tbody></table>';

    \submit_button($is_edit_mode ? __('Update Coupon', 'must-hotel-booking') : __('Create Coupon', 'must-hotel-booking'));

    if ($is_edit_mode) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_coupons_page_url()) . '">' . \esc_html__('Add New Coupon', 'must-hotel-booking') . '</a>';
    }

    echo '</form>';
    echo '</div>';

    echo '<h2>' . \esc_html__('Coupons List', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Coupon code', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Discount type', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Discount value', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Valid from', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Valid until', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Usage limit', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Used', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($coupons)) {
        echo '<tr><td colspan="8">' . \esc_html__('No coupons found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($coupons as $coupon) {
            $coupon_id = isset($coupon['id']) ? (int) $coupon['id'] : 0;
            $type = isset($coupon['discount_type']) ? (string) $coupon['discount_type'] : 'percentage';
            $edit_url = get_admin_coupons_page_url(['action' => 'edit', 'coupon_id' => $coupon_id]);
            $delete_url = \wp_nonce_url(
                get_admin_coupons_page_url(['action' => 'delete_coupon', 'coupon_id' => $coupon_id]),
                'must_coupon_delete_' . $coupon_id
            );

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($coupon['code'] ?? '')) . '</td>';
            echo '<td>' . \esc_html(isset($types[$type]) ? $types[$type] : $type) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($coupon['discount_value'] ?? 0), 2)) . '</td>';
            echo '<td>' . \esc_html((string) ($coupon['valid_from'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($coupon['valid_until'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($coupon['usage_limit'] ?? 0))) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($coupon['usage_count'] ?? 0))) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . \esc_url($edit_url) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Delete this coupon?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}
