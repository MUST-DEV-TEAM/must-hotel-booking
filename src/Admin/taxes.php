<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Engine\PricingEngine;

/**
 * Get taxes table name.
 */
function get_admin_taxes_table_name(): string
{
    return PricingEngine::getTaxesTableName();
}

/**
 * Build Taxes & Fees admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_taxes_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-taxes');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Allowed tax/fee rule types.
 *
 * @return array<string, string>
 */
function get_tax_fee_rule_type_options(): array
{
    return [
        'percentage' => \__('Percentage', 'must-hotel-booking'),
        'fixed' => \__('Fixed', 'must-hotel-booking'),
    ];
}

/**
 * Allowed apply mode options.
 *
 * @return array<string, string>
 */
function get_tax_fee_apply_mode_options(): array
{
    return [
        'night' => \__('Per night', 'must-hotel-booking'),
        'stay' => \__('Per stay', 'must-hotel-booking'),
    ];
}

/**
 * Build default taxes/fees form values.
 *
 * @return array<string, mixed>
 */
function get_tax_fee_rule_form_defaults(): array
{
    return [
        'rule_id' => 0,
        'name' => '',
        'rule_type' => 'percentage',
        'rule_value' => 0.00,
        'apply_mode' => 'stay',
    ];
}

/**
 * Sanitize and validate taxes/fees form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_tax_fee_rule_form_values(array $source): array
{
    $rule_id = isset($source['rule_id']) ? \absint(\wp_unslash($source['rule_id'])) : 0;
    $name = isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '';
    $rule_type = isset($source['rule_type']) ? \sanitize_key((string) \wp_unslash($source['rule_type'])) : 'percentage';
    $rule_value = isset($source['rule_value']) ? (float) \wp_unslash($source['rule_value']) : 0.0;
    $apply_mode = isset($source['apply_mode']) ? \sanitize_key((string) \wp_unslash($source['apply_mode'])) : 'stay';
    $errors = [];

    $type_options = get_tax_fee_rule_type_options();
    $apply_options = get_tax_fee_apply_mode_options();

    if ($name === '') {
        $errors[] = \__('Name is required.', 'must-hotel-booking');
    }

    if (!isset($type_options[$rule_type])) {
        $errors[] = \__('Tax type must be percentage or fixed.', 'must-hotel-booking');
    }

    if (!isset($apply_options[$apply_mode])) {
        $errors[] = \__('Apply value must be per night or per stay.', 'must-hotel-booking');
    }

    if ($rule_value < 0) {
        $rule_value = 0.0;
    }

    return [
        'rule_id' => $rule_id,
        'name' => $name,
        'rule_type' => $rule_type,
        'rule_value' => \round($rule_value, 2),
        'apply_mode' => $apply_mode,
        'errors' => $errors,
    ];
}

/**
 * Load one tax/fee rule row.
 *
 * @return array<string, mixed>|null
 */
function get_tax_fee_rule_row(int $rule_id): ?array
{
    global $wpdb;

    if ($rule_id <= 0) {
        return null;
    }

    $sql = $wpdb->prepare(
        'SELECT id, name, rule_type, rule_value, apply_mode, created_at
        FROM ' . get_admin_taxes_table_name() . ' WHERE id = %d LIMIT 1',
        $rule_id
    );

    $row = $wpdb->get_row($sql, ARRAY_A);

    return \is_array($row) ? $row : null;
}

/**
 * Load all tax/fee rules for listing.
 *
 * @return array<int, array<string, mixed>>
 */
function get_tax_fee_rule_rows(): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        'SELECT id, name, rule_type, rule_value, apply_mode, created_at
        FROM ' . get_admin_taxes_table_name() . '
        ORDER BY id DESC',
        ARRAY_A
    );

    return \is_array($rows) ? $rows : [];
}

/**
 * Handle delete action for tax/fee rules.
 */
function maybe_handle_tax_fee_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_rule') {
        return;
    }

    $rule_id = isset($_GET['rule_id']) ? \absint(\wp_unslash($_GET['rule_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($rule_id <= 0 || !\wp_verify_nonce($nonce, 'must_tax_fee_rule_delete_' . $rule_id)) {
        \wp_safe_redirect(get_admin_taxes_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    global $wpdb;

    $deleted = $wpdb->delete(get_admin_taxes_table_name(), ['id' => $rule_id], ['%d']);
    \wp_safe_redirect(get_admin_taxes_page_url(['notice' => $deleted !== false ? 'rule_deleted' : 'rule_delete_failed']));
    exit;
}

/**
 * Handle save action for tax/fee rules.
 *
 * @return array<string, mixed>
 */
function maybe_handle_tax_fee_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $action = isset($_POST['must_tax_fee_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_tax_fee_action'])) : '';

    if ($action !== 'save_tax_fee_rule') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $nonce = isset($_POST['must_tax_fee_nonce']) ? (string) \wp_unslash($_POST['must_tax_fee_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_tax_fee_rule_save')) {
        return [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $rule_data = sanitize_tax_fee_rule_form_values($raw_post);
    $rule_id = (int) $rule_data['rule_id'];

    if (!empty($rule_data['errors'])) {
        return [
            'errors' => (array) $rule_data['errors'],
            'form' => $rule_data,
        ];
    }

    global $wpdb;

    $payload = [
        'name' => (string) $rule_data['name'],
        'rule_type' => (string) $rule_data['rule_type'],
        'rule_value' => (float) $rule_data['rule_value'],
        'apply_mode' => (string) $rule_data['apply_mode'],
    ];

    $saved_rule_id = 0;

    if ($rule_id > 0) {
        $updated = $wpdb->update(
            get_admin_taxes_table_name(),
            $payload,
            ['id' => $rule_id],
            ['%s', '%s', '%f', '%s'],
            ['%d']
        );

        if ($updated !== false) {
            $saved_rule_id = $rule_id;
        }
    } else {
        $payload['created_at'] = \current_time('mysql');

        $inserted = $wpdb->insert(
            get_admin_taxes_table_name(),
            $payload,
            ['%s', '%s', '%f', '%s', '%s']
        );

        if ($inserted !== false) {
            $saved_rule_id = (int) $wpdb->insert_id;
        }
    }

    if ($saved_rule_id <= 0) {
        return [
            'errors' => [\__('Unable to save tax/fee rule. Please check database schema.', 'must-hotel-booking')],
            'form' => $rule_data,
        ];
    }

    \wp_safe_redirect(
        get_admin_taxes_page_url(
            [
                'notice' => $rule_id > 0 ? 'rule_updated' : 'rule_created',
                'action' => 'edit',
                'rule_id' => $saved_rule_id,
            ]
        )
    );
    exit;
}

/**
 * Build form data for rendering.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_tax_fee_rule_form_data(?array $submitted_form = null): array
{
    $defaults = get_tax_fee_rule_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';
    $rule_id = isset($_GET['rule_id']) ? \absint(\wp_unslash($_GET['rule_id'])) : 0;

    if ($action !== 'edit' || $rule_id <= 0) {
        return $defaults;
    }

    $rule = get_tax_fee_rule_row($rule_id);

    if (!\is_array($rule)) {
        return $defaults;
    }

    return [
        'rule_id' => (int) ($rule['id'] ?? 0),
        'name' => (string) ($rule['name'] ?? ''),
        'rule_type' => (string) ($rule['rule_type'] ?? 'percentage'),
        'rule_value' => (float) ($rule['rule_value'] ?? 0),
        'apply_mode' => (string) ($rule['apply_mode'] ?? 'stay'),
    ];
}

/**
 * Render taxes admin notices.
 */
function render_taxes_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'rule_created' => ['success', \__('Tax/Fee rule created successfully.', 'must-hotel-booking')],
        'rule_updated' => ['success', \__('Tax/Fee rule updated successfully.', 'must-hotel-booking')],
        'rule_deleted' => ['success', \__('Tax/Fee rule deleted successfully.', 'must-hotel-booking')],
        'rule_delete_failed' => ['error', \__('Unable to delete tax/fee rule.', 'must-hotel-booking')],
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
 * Render Taxes & Fees admin page.
 */
function render_admin_taxes_page(): void
{
    ensure_admin_capability();

    maybe_handle_tax_fee_delete_request();

    $save_state = maybe_handle_tax_fee_save_request();
    $errors = isset($save_state['errors']) && \is_array($save_state['errors']) ? $save_state['errors'] : [];
    $submitted_form = isset($save_state['form']) && \is_array($save_state['form']) ? $save_state['form'] : null;
    $form = get_tax_fee_rule_form_data($submitted_form);
    $is_edit_mode = (int) $form['rule_id'] > 0;
    $rules = get_tax_fee_rule_rows();
    $type_options = get_tax_fee_rule_type_options();
    $apply_options = get_tax_fee_apply_mode_options();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Taxes & Fees', 'must-hotel-booking') . '</h1>';

    render_taxes_admin_notice_from_query();

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($is_edit_mode ? __('Edit Tax/Fee Rule', 'must-hotel-booking') : __('Create Tax/Fee Rule', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_taxes_page_url()) . '">';
    \wp_nonce_field('must_tax_fee_rule_save', 'must_tax_fee_nonce');

    echo '<input type="hidden" name="must_tax_fee_action" value="save_tax_fee_rule" />';
    echo '<input type="hidden" name="rule_id" value="' . \esc_attr((string) $form['rule_id']) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-tax-fee-name">' . \esc_html__('Name', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-tax-fee-name" type="text" class="regular-text" name="name" value="' . \esc_attr((string) $form['name']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-tax-fee-type">' . \esc_html__('Type', 'must-hotel-booking') . '</label></th>';
    echo '<td><select id="must-tax-fee-type" name="rule_type">';

    foreach ($type_options as $value => $label) {
        $selected = ((string) $form['rule_type'] === $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-tax-fee-value">' . \esc_html__('Value', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-tax-fee-value" type="number" min="0" step="0.01" name="rule_value" value="' . \esc_attr((string) $form['rule_value']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-tax-fee-apply">' . \esc_html__('Apply per', 'must-hotel-booking') . '</label></th>';
    echo '<td><select id="must-tax-fee-apply" name="apply_mode">';

    foreach ($apply_options as $value => $label) {
        $selected = ((string) $form['apply_mode'] === $value) ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select></td></tr>';
    echo '</tbody></table>';

    \submit_button($is_edit_mode ? __('Update Rule', 'must-hotel-booking') : __('Create Rule', 'must-hotel-booking'));

    if ($is_edit_mode) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_taxes_page_url()) . '">' . \esc_html__('Add New Rule', 'must-hotel-booking') . '</a>';
    }

    echo '</form>';
    echo '</div>';

    echo '<h2>' . \esc_html__('Configured Rules', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Type', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Value', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Apply', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rules)) {
        echo '<tr><td colspan="5">' . \esc_html__('No taxes or fees rules found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rules as $rule) {
            $rule_id = isset($rule['id']) ? (int) $rule['id'] : 0;
            $rule_type = isset($rule['rule_type']) ? (string) $rule['rule_type'] : 'percentage';
            $apply_mode = isset($rule['apply_mode']) ? (string) $rule['apply_mode'] : 'stay';
            $edit_url = get_admin_taxes_page_url(['action' => 'edit', 'rule_id' => $rule_id]);
            $delete_url = \wp_nonce_url(
                get_admin_taxes_page_url(['action' => 'delete_rule', 'rule_id' => $rule_id]),
                'must_tax_fee_rule_delete_' . $rule_id
            );

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($rule['name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html(isset($type_options[$rule_type]) ? $type_options[$rule_type] : $rule_type) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($rule['rule_value'] ?? 0), 2)) . '</td>';
            echo '<td>' . \esc_html(isset($apply_options[$apply_mode]) ? $apply_options[$apply_mode] : $apply_mode) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . \esc_url($edit_url) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Delete this rule?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}
