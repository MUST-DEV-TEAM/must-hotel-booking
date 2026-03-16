<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Engine\PricingEngine;

/**
 * Resolve pricing table name for admin operations.
 */
function get_admin_pricing_table_name(): string
{
    return PricingEngine::getPricingTableName();
}

/**
 * Build Rates & Pricing admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_pricing_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-pricing');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Validate pricing date value.
 */
function is_valid_pricing_rule_date(string $date): bool
{
    $candidate = \trim($date);

    if (AvailabilityEngine::isValidBookingDate($candidate)) {
        return true;
    }

    $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $candidate);

    return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $candidate;
}

/**
 * Build default rule form values.
 *
 * @return array<string, mixed>
 */
function get_pricing_rule_form_defaults(): array
{
    $today = \current_time('Y-m-d');
    $next_month = (new \DateTimeImmutable($today))->modify('+30 day')->format('Y-m-d');

    return [
        'rule_id' => 0,
        'name' => '',
        'start_date' => $today,
        'end_date' => $next_month,
        'seasonal_price' => 0.00,
        'weekend_price' => 0.00,
        'minimum_nights' => 1,
    ];
}

/**
 * Sanitize and validate pricing rule form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_pricing_rule_form_values(array $source): array
{
    $rule_id = isset($source['rule_id']) ? \absint(\wp_unslash($source['rule_id'])) : 0;
    $name = isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '';
    $start_date = isset($source['start_date']) ? \sanitize_text_field((string) \wp_unslash($source['start_date'])) : '';
    $end_date = isset($source['end_date']) ? \sanitize_text_field((string) \wp_unslash($source['end_date'])) : '';
    $seasonal_price_source = $source['seasonal_price'] ?? $source['price_override'] ?? 0;
    $seasonal_price = (float) \wp_unslash($seasonal_price_source);
    $weekend_price = isset($source['weekend_price']) ? (float) \wp_unslash($source['weekend_price']) : 0.0;
    $minimum_nights = isset($source['minimum_nights']) ? \max(1, \absint(\wp_unslash($source['minimum_nights']))) : 1;
    $errors = [];

    if ($name === '') {
        $errors[] = \__('Rule name is required.', 'must-hotel-booking');
    }

    if (!is_valid_pricing_rule_date($start_date) || !is_valid_pricing_rule_date($end_date)) {
        $errors[] = \__('Start date and end date must be valid.', 'must-hotel-booking');
    } elseif ($start_date > $end_date) {
        $errors[] = \__('End date must be on or after start date.', 'must-hotel-booking');
    }

    if ($seasonal_price < 0) {
        $seasonal_price = 0.0;
    }

    if ($weekend_price < 0) {
        $weekend_price = 0.0;
    }

    return [
        'rule_id' => $rule_id,
        'name' => $name,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'seasonal_price' => \round($seasonal_price, 2),
        'weekend_price' => \round($weekend_price, 2),
        'minimum_nights' => $minimum_nights,
        'errors' => $errors,
    ];
}

/**
 * Load one pricing rule row.
 *
 * @return array<string, mixed>|null
 */
function get_pricing_rule_row(int $rule_id): ?array
{
    global $wpdb;

    if ($rule_id <= 0) {
        return null;
    }

    $rule = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id, name, start_date, end_date, price_override, weekend_price, minimum_nights, created_at
            FROM ' . get_admin_pricing_table_name() . ' WHERE id = %d LIMIT 1',
            $rule_id
        ),
        ARRAY_A
    );

    return \is_array($rule) ? $rule : null;
}

/**
 * Load all pricing rules.
 *
 * @return array<int, array<string, mixed>>
 */
function get_pricing_rule_rows(): array
{
    global $wpdb;

    $rows = $wpdb->get_results(
        'SELECT id, name, start_date, end_date, price_override, weekend_price, minimum_nights, created_at
        FROM ' . get_admin_pricing_table_name() . '
        ORDER BY start_date DESC, end_date DESC, id DESC',
        ARRAY_A
    );

    return \is_array($rows) ? $rows : [];
}

/**
 * Handle pricing rule deletion action.
 */
function maybe_handle_pricing_rule_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_rule') {
        return;
    }

    $rule_id = isset($_GET['rule_id']) ? \absint(\wp_unslash($_GET['rule_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($rule_id <= 0 || !\wp_verify_nonce($nonce, 'must_pricing_rule_delete_' . $rule_id)) {
        \wp_safe_redirect(get_admin_pricing_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    global $wpdb;

    $deleted = $wpdb->delete(get_admin_pricing_table_name(), ['id' => $rule_id], ['%d']);

    \wp_safe_redirect(get_admin_pricing_page_url(['notice' => $deleted !== false ? 'rule_deleted' : 'rule_delete_failed']));
    exit;
}

/**
 * Handle pricing rule save action.
 *
 * @return array<string, mixed>
 */
function maybe_handle_pricing_rule_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $action = isset($_POST['must_pricing_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_pricing_action'])) : '';

    if ($action !== 'save_pricing_rule') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $nonce = isset($_POST['must_pricing_nonce']) ? (string) \wp_unslash($_POST['must_pricing_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_pricing_rule_save')) {
        return [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $rule_data = sanitize_pricing_rule_form_values($raw_post);
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
        'start_date' => (string) $rule_data['start_date'],
        'end_date' => (string) $rule_data['end_date'],
        'price_override' => (float) $rule_data['seasonal_price'],
        'weekend_price' => (float) $rule_data['weekend_price'],
        'minimum_nights' => (int) $rule_data['minimum_nights'],
    ];

    $format = ['%s', '%s', '%s', '%f', '%f', '%d'];
    $saved_rule_id = 0;

    if ($rule_id > 0) {
        $updated = $wpdb->update(
            get_admin_pricing_table_name(),
            $payload,
            ['id' => $rule_id],
            $format,
            ['%d']
        );

        if ($updated !== false) {
            $saved_rule_id = $rule_id;
        }
    } else {
        $payload['room_id'] = 0;
        $payload['created_at'] = \current_time('mysql');

        $inserted = $wpdb->insert(
            get_admin_pricing_table_name(),
            $payload,
            ['%s', '%s', '%s', '%f', '%f', '%d', '%d', '%s']
        );

        if ($inserted !== false) {
            $saved_rule_id = (int) $wpdb->insert_id;
        }
    }

    if ($saved_rule_id <= 0) {
        return [
            'errors' => [\__('Unable to save pricing rule. Please check database schema.', 'must-hotel-booking')],
            'form' => $rule_data,
        ];
    }

    \wp_safe_redirect(
        get_admin_pricing_page_url(
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
 * Build pricing form data for screen rendering.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_pricing_rule_form_data(?array $submitted_form = null): array
{
    $defaults = get_pricing_rule_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';
    $rule_id = isset($_GET['rule_id']) ? \absint(\wp_unslash($_GET['rule_id'])) : 0;

    if ($action !== 'edit' || $rule_id <= 0) {
        return $defaults;
    }

    $rule = get_pricing_rule_row($rule_id);

    if (!\is_array($rule)) {
        return $defaults;
    }

    return [
        'rule_id' => (int) ($rule['id'] ?? 0),
        'name' => (string) ($rule['name'] ?? ''),
        'start_date' => (string) ($rule['start_date'] ?? $defaults['start_date']),
        'end_date' => (string) ($rule['end_date'] ?? $defaults['end_date']),
        'seasonal_price' => (float) ($rule['price_override'] ?? 0),
        'weekend_price' => (float) ($rule['weekend_price'] ?? 0),
        'minimum_nights' => (int) ($rule['minimum_nights'] ?? 1),
    ];
}

/**
 * Render pricing admin notices.
 */
function render_pricing_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'rule_created' => ['success', \__('Pricing rule created successfully.', 'must-hotel-booking')],
        'rule_updated' => ['success', \__('Pricing rule updated successfully.', 'must-hotel-booking')],
        'rule_deleted' => ['success', \__('Pricing rule deleted successfully.', 'must-hotel-booking')],
        'rule_delete_failed' => ['error', \__('Unable to delete pricing rule.', 'must-hotel-booking')],
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
 * Render pricing rules management screen.
 */
function render_admin_pricing_page(): void
{
    ensure_admin_capability();

    maybe_handle_pricing_rule_delete_request();

    $save_state = maybe_handle_pricing_rule_save_request();
    $errors = isset($save_state['errors']) && \is_array($save_state['errors']) ? $save_state['errors'] : [];
    $submitted_form = isset($save_state['form']) && \is_array($save_state['form']) ? $save_state['form'] : null;

    $form = get_pricing_rule_form_data($submitted_form);
    $is_edit_mode = (int) $form['rule_id'] > 0;
    $rules = get_pricing_rule_rows();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Rates & Pricing', 'must-hotel-booking') . '</h1>';

    render_pricing_admin_notice_from_query();

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($is_edit_mode ? __('Edit Pricing Rule', 'must-hotel-booking') : __('Create Pricing Rule', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_pricing_page_url()) . '">';
    \wp_nonce_field('must_pricing_rule_save', 'must_pricing_nonce');

    echo '<input type="hidden" name="must_pricing_action" value="save_pricing_rule" />';
    echo '<input type="hidden" name="rule_id" value="' . \esc_attr((string) $form['rule_id']) . '" />';

    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-pricing-name">' . \esc_html__('Name', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-pricing-name" type="text" class="regular-text" name="name" value="' . \esc_attr((string) $form['name']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-start-date">' . \esc_html__('Start date', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-pricing-start-date" type="date" name="start_date" value="' . \esc_attr((string) $form['start_date']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-end-date">' . \esc_html__('End date', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-pricing-end-date" type="date" name="end_date" value="' . \esc_attr((string) $form['end_date']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-seasonal-price">' . \esc_html__('Seasonal price', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-pricing-seasonal-price" type="number" min="0" step="0.01" name="seasonal_price" value="' . \esc_attr((string) $form['seasonal_price']) . '" /></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-weekend-price">' . \esc_html__('Weekend price', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-pricing-weekend-price" type="number" min="0" step="0.01" name="weekend_price" value="' . \esc_attr((string) $form['weekend_price']) . '" /></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-minimum-nights">' . \esc_html__('Minimum nights', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-pricing-minimum-nights" type="number" min="1" step="1" name="minimum_nights" value="' . \esc_attr((string) $form['minimum_nights']) . '" required /></td></tr>';
    echo '</tbody></table>';

    \submit_button($is_edit_mode ? __('Update Rule', 'must-hotel-booking') : __('Create Rule', 'must-hotel-booking'));

    if ($is_edit_mode) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('Add New Rule', 'must-hotel-booking') . '</a>';
    }

    echo '</form>';
    echo '</div>';

    echo '<h2>' . \esc_html__('Seasonal Pricing Rules', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Start date', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('End date', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Seasonal price', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Weekend price', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Minimum nights', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rules)) {
        echo '<tr><td colspan="7">' . \esc_html__('No pricing rules found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rules as $rule) {
            $rule_id = isset($rule['id']) ? (int) $rule['id'] : 0;
            $edit_url = get_admin_pricing_page_url(['action' => 'edit', 'rule_id' => $rule_id]);
            $delete_url = \wp_nonce_url(
                get_admin_pricing_page_url(['action' => 'delete_rule', 'rule_id' => $rule_id]),
                'must_pricing_rule_delete_' . $rule_id
            );

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($rule['name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($rule['start_date'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($rule['end_date'] ?? '')) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($rule['price_override'] ?? 0), 2)) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($rule['weekend_price'] ?? 0), 2)) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($rule['minimum_nights'] ?? 1))) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . \esc_url($edit_url) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Delete this pricing rule?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}
