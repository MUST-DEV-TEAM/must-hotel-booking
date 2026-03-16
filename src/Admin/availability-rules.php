<?php

namespace MustHotelBooking\Admin;

/**
 * Build Availability Rules admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_availability_rules_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-availability-rules');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Get availability rules table name.
 */
function get_admin_availability_rules_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_availability';
}

/**
 * Check if availability rules table exists.
 */
function does_admin_availability_rules_table_exist(): bool
{
    global $wpdb;

    $table_name = get_admin_availability_rules_table_name();
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    return \is_string($table_exists) && $table_exists !== '';
}

/**
 * Get date-based restriction type options.
 *
 * @return array<string, string>
 */
function get_availability_date_restriction_type_options(): array
{
    return [
        'closed_arrival' => \__('Closed to arrival dates', 'must-hotel-booking'),
        'closed_departure' => \__('Closed to departure dates', 'must-hotel-booking'),
        'maintenance_block' => \__('Blocked maintenance dates', 'must-hotel-booking'),
    ];
}

/**
 * Get latest numeric rule value.
 */
function get_latest_numeric_availability_rule_value(string $rule_type): int
{
    global $wpdb;

    if (!does_admin_availability_rules_table_exist()) {
        return 0;
    }

    $table_name = get_admin_availability_rules_table_name();
    $value = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT rule_value
            FROM {$table_name}
            WHERE rule_type = %s
            ORDER BY updated_at DESC, id DESC
            LIMIT 1",
            $rule_type
        )
    );

    return \max(0, (int) $value);
}

/**
 * Load date restrictions for admin table.
 *
 * @return array<int, array<string, mixed>>
 */
function get_availability_date_restriction_rows(): array
{
    global $wpdb;

    if (!does_admin_availability_rules_table_exist()) {
        return [];
    }

    $table_name = get_admin_availability_rules_table_name();
    $types = \array_keys(get_availability_date_restriction_type_options());
    $placeholders = \implode(', ', \array_fill(0, \count($types), '%s'));
    $sql = $wpdb->prepare(
        "SELECT id, rule_type, availability_date, end_date, reason, updated_at
        FROM {$table_name}
        WHERE rule_type IN ({$placeholders})
        ORDER BY availability_date DESC, end_date DESC, id DESC",
        ...$types
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Validate restriction date.
 */
function is_valid_availability_rule_date(string $date): bool
{
    $candidate = \trim($date);

    if (\function_exists(__NAMESPACE__ . '\is_valid_booking_date')) {
        return is_valid_booking_date($candidate);
    }

    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

    return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $candidate;
}

/**
 * Handle saving minimum/maximum stay rules.
 *
 * @return array<int, string>
 */
function maybe_handle_availability_stay_rules_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_availability_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_availability_action'])) : '';

    if ($action !== 'save_stay_rules') {
        return [];
    }

    $nonce = isset($_POST['must_availability_stay_nonce']) ? (string) \wp_unslash($_POST['must_availability_stay_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_availability_save_stay_rules')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    $minimum_stay = isset($_POST['minimum_stay']) ? \max(0, \absint(\wp_unslash($_POST['minimum_stay']))) : 0;
    $maximum_stay = isset($_POST['maximum_stay']) ? \max(0, \absint(\wp_unslash($_POST['maximum_stay']))) : 0;
    $errors = [];

    if ($minimum_stay > 0 && $maximum_stay > 0 && $minimum_stay > $maximum_stay) {
        $errors[] = \__('Minimum stay cannot be greater than maximum stay.', 'must-hotel-booking');
    }

    if (!empty($errors)) {
        return $errors;
    }

    global $wpdb;

    if (!does_admin_availability_rules_table_exist()) {
        return [\__('Availability table not found. Please reactivate the plugin.', 'must-hotel-booking')];
    }

    $table_name = get_admin_availability_rules_table_name();
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE rule_type IN (%s, %s)",
            'minimum_stay',
            'maximum_stay'
        )
    );

    $today = \current_time('Y-m-d');
    $now = \current_time('mysql');

    if ($minimum_stay > 0) {
        $wpdb->insert(
            $table_name,
            [
                'room_id' => 0,
                'availability_date' => $today,
                'end_date' => $today,
                'is_available' => 1,
                'reason' => 'Minimum stay',
                'rule_type' => 'minimum_stay',
                'rule_value' => (string) $minimum_stay,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    if ($maximum_stay > 0) {
        $wpdb->insert(
            $table_name,
            [
                'room_id' => 0,
                'availability_date' => $today,
                'end_date' => $today,
                'is_available' => 1,
                'reason' => 'Maximum stay',
                'rule_type' => 'maximum_stay',
                'rule_value' => (string) $maximum_stay,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    \wp_safe_redirect(get_admin_availability_rules_page_url(['notice' => 'stay_rules_saved']));
    exit;
}

/**
 * Handle creation of date restrictions.
 *
 * @return array<int, string>
 */
function maybe_handle_availability_date_rule_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_availability_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_availability_action'])) : '';

    if ($action !== 'save_date_rule') {
        return [];
    }

    $nonce = isset($_POST['must_availability_date_nonce']) ? (string) \wp_unslash($_POST['must_availability_date_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_availability_save_date_rule')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    $rule_type = isset($_POST['rule_type']) ? \sanitize_key((string) \wp_unslash($_POST['rule_type'])) : '';
    $start_date = isset($_POST['start_date']) ? \sanitize_text_field((string) \wp_unslash($_POST['start_date'])) : '';
    $end_date = isset($_POST['end_date']) ? \sanitize_text_field((string) \wp_unslash($_POST['end_date'])) : '';
    $errors = [];
    $type_options = get_availability_date_restriction_type_options();

    if (!isset($type_options[$rule_type])) {
        $errors[] = \__('Please select a valid restriction type.', 'must-hotel-booking');
    }

    if (!is_valid_availability_rule_date($start_date) || !is_valid_availability_rule_date($end_date)) {
        $errors[] = \__('Please provide valid start and end dates.', 'must-hotel-booking');
    } elseif ($start_date > $end_date) {
        $errors[] = \__('End date must be on or after start date.', 'must-hotel-booking');
    }

    if (!empty($errors)) {
        return $errors;
    }

    global $wpdb;

    if (!does_admin_availability_rules_table_exist()) {
        return [\__('Availability table not found. Please reactivate the plugin.', 'must-hotel-booking')];
    }

    $table_name = get_admin_availability_rules_table_name();
    $inserted = $wpdb->insert(
        $table_name,
        [
            'room_id' => 0,
            'availability_date' => $start_date,
            'end_date' => $end_date,
            'is_available' => 0,
            'reason' => (string) $type_options[$rule_type],
            'rule_type' => $rule_type,
            'rule_value' => '',
            'updated_at' => \current_time('mysql'),
        ],
        ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        return [\__('Unable to save availability rule. Please check your database schema.', 'must-hotel-booking')];
    }

    \wp_safe_redirect(get_admin_availability_rules_page_url(['notice' => 'date_rule_saved']));
    exit;
}

/**
 * Handle delete date restriction request.
 */
function maybe_handle_availability_rule_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_rule') {
        return;
    }

    $rule_id = isset($_GET['rule_id']) ? \absint(\wp_unslash($_GET['rule_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($rule_id <= 0 || !\wp_verify_nonce($nonce, 'must_availability_rule_delete_' . $rule_id)) {
        \wp_safe_redirect(get_admin_availability_rules_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    global $wpdb;

    if (!does_admin_availability_rules_table_exist()) {
        \wp_safe_redirect(get_admin_availability_rules_page_url(['notice' => 'table_missing']));
        exit;
    }

    $table_name = get_admin_availability_rules_table_name();
    $deleted = $wpdb->delete($table_name, ['id' => $rule_id], ['%d']);

    \wp_safe_redirect(get_admin_availability_rules_page_url(['notice' => $deleted !== false ? 'rule_deleted' : 'rule_delete_failed']));
    exit;
}

/**
 * Render admin notices for Availability Rules page.
 */
function render_availability_rules_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'stay_rules_saved' => ['success', \__('Stay rules saved successfully.', 'must-hotel-booking')],
        'date_rule_saved' => ['success', \__('Availability rule saved successfully.', 'must-hotel-booking')],
        'rule_deleted' => ['success', \__('Availability rule deleted successfully.', 'must-hotel-booking')],
        'rule_delete_failed' => ['error', \__('Unable to delete availability rule.', 'must-hotel-booking')],
        'table_missing' => ['error', \__('Availability table not found. Please reactivate the plugin.', 'must-hotel-booking')],
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
 * Render availability rules admin page.
 */
function render_admin_availability_rules_page(): void
{
    ensure_admin_capability();

    maybe_handle_availability_rule_delete_request();

    $save_errors = [];
    $save_errors = \array_merge($save_errors, maybe_handle_availability_stay_rules_save_request());
    $save_errors = \array_merge($save_errors, maybe_handle_availability_date_rule_save_request());

    $minimum_stay = get_latest_numeric_availability_rule_value('minimum_stay');
    $maximum_stay = get_latest_numeric_availability_rule_value('maximum_stay');
    $date_rules = get_availability_date_restriction_rows();
    $type_options = get_availability_date_restriction_type_options();
    $table_exists = does_admin_availability_rules_table_exist();

    $form_rule_type = isset($_POST['rule_type']) ? \sanitize_key((string) \wp_unslash($_POST['rule_type'])) : 'closed_arrival';
    $form_start = isset($_POST['start_date']) ? \sanitize_text_field((string) \wp_unslash($_POST['start_date'])) : \current_time('Y-m-d');
    $form_end = isset($_POST['end_date']) ? \sanitize_text_field((string) \wp_unslash($_POST['end_date'])) : $form_start;

    if (!isset($type_options[$form_rule_type])) {
        $form_rule_type = 'closed_arrival';
    }

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Availability Rules', 'must-hotel-booking') . '</h1>';

    render_availability_rules_admin_notice_from_query();

    if (!$table_exists) {
        echo '<div class="notice notice-warning"><p>' . \esc_html__('Availability table was not found. Reactivate the plugin to create database tables.', 'must-hotel-booking') . '</p></div>';
    }

    if (!empty($save_errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($save_errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Stay Restrictions', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_availability_rules_page_url()) . '">';
    \wp_nonce_field('must_availability_save_stay_rules', 'must_availability_stay_nonce');
    echo '<input type="hidden" name="must_availability_action" value="save_stay_rules" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th><label for="must-availability-min-stay">' . \esc_html__('Minimum stay', 'must-hotel-booking') . '</label></th><td><input id="must-availability-min-stay" type="number" min="0" step="1" name="minimum_stay" value="' . \esc_attr((string) $minimum_stay) . '" /><p class="description">' . \esc_html__('Set 0 to disable.', 'must-hotel-booking') . '</p></td></tr>';
    echo '<tr><th><label for="must-availability-max-stay">' . \esc_html__('Maximum stay', 'must-hotel-booking') . '</label></th><td><input id="must-availability-max-stay" type="number" min="0" step="1" name="maximum_stay" value="' . \esc_attr((string) $maximum_stay) . '" /><p class="description">' . \esc_html__('Set 0 to disable.', 'must-hotel-booking') . '</p></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Save Stay Rules', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Date Restrictions', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_availability_rules_page_url()) . '">';
    \wp_nonce_field('must_availability_save_date_rule', 'must_availability_date_nonce');
    echo '<input type="hidden" name="must_availability_action" value="save_date_rule" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th><label for="must-availability-rule-type">' . \esc_html__('Restriction type', 'must-hotel-booking') . '</label></th><td><select id="must-availability-rule-type" name="rule_type">';

    foreach ($type_options as $value => $label) {
        $selected = $form_rule_type === $value ? ' selected' : '';
        echo '<option value="' . \esc_attr($value) . '"' . $selected . '>' . \esc_html($label) . '</option>';
    }

    echo '</select></td></tr>';
    echo '<tr><th><label for="must-availability-start-date">' . \esc_html__('Start date', 'must-hotel-booking') . '</label></th><td><input id="must-availability-start-date" type="date" name="start_date" value="' . \esc_attr($form_start) . '" required /></td></tr>';
    echo '<tr><th><label for="must-availability-end-date">' . \esc_html__('End date', 'must-hotel-booking') . '</label></th><td><input id="must-availability-end-date" type="date" name="end_date" value="' . \esc_attr($form_end) . '" required /></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Add Rule', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';

    echo '<h2>' . \esc_html__('Configured Date Restrictions', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Type', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Start date', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('End date', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($date_rules)) {
        echo '<tr><td colspan="4">' . \esc_html__('No date restrictions found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($date_rules as $rule) {
            $rule_id = isset($rule['id']) ? (int) $rule['id'] : 0;
            $rule_type = isset($rule['rule_type']) ? (string) $rule['rule_type'] : '';
            $delete_url = \wp_nonce_url(
                get_admin_availability_rules_page_url(
                    [
                        'action' => 'delete_rule',
                        'rule_id' => $rule_id,
                    ]
                ),
                'must_availability_rule_delete_' . $rule_id
            );

            echo '<tr>';
            echo '<td>' . \esc_html(isset($type_options[$rule_type]) ? (string) $type_options[$rule_type] : $rule_type) . '</td>';
            echo '<td>' . \esc_html((string) ($rule['availability_date'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($rule['end_date'] ?? '')) . '</td>';
            echo '<td><a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Delete this availability rule?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}
