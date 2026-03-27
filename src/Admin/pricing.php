<?php

namespace MustHotelBooking\Admin;

/**
 * @param array<string, scalar> $args
 */
function get_admin_pricing_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-pricing');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

function is_pricing_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-pricing';
}

/**
 * @return array<string, mixed>
 */
function get_pricing_admin_save_state(): array
{
    global $mustHotelBookingPricingAdminSaveState;

    if (isset($mustHotelBookingPricingAdminSaveState) && \is_array($mustHotelBookingPricingAdminSaveState)) {
        return $mustHotelBookingPricingAdminSaveState;
    }

    $mustHotelBookingPricingAdminSaveState = [
        'errors' => [],
        'base_price_errors' => [],
        'rule_errors' => [],
        'rule_form' => null,
    ];

    return $mustHotelBookingPricingAdminSaveState;
}

/**
 * @param array<string, mixed> $state
 */
function set_pricing_admin_save_state(array $state): void
{
    global $mustHotelBookingPricingAdminSaveState;
    $mustHotelBookingPricingAdminSaveState = $state;
}

function clear_pricing_admin_save_state(): void
{
    set_pricing_admin_save_state([
        'errors' => [],
        'base_price_errors' => [],
        'rule_errors' => [],
        'rule_form' => null,
    ]);
}

function maybe_handle_pricing_admin_actions_early(): void
{
    if (!is_pricing_admin_request()) {
        return;
    }

    ensure_admin_capability();
    $query = PricingAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod === 'POST') {
        set_pricing_admin_save_state((new PricingAdminActions())->handleSaveRequest($query));
        return;
    }

    (new PricingAdminActions())->handleGetAction($query);
}

function render_pricing_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'base_price_saved' => ['success', \__('Base price saved successfully.', 'must-hotel-booking')],
        'rule_created' => ['success', \__('Pricing rule created successfully.', 'must-hotel-booking')],
        'rule_updated' => ['success', \__('Pricing rule updated successfully.', 'must-hotel-booking')],
        'rule_deleted' => ['success', \__('Pricing rule deleted successfully.', 'must-hotel-booking')],
        'rule_duplicated' => ['success', \__('Pricing rule duplicated successfully.', 'must-hotel-booking')],
        'rule_activated' => ['success', \__('Pricing rule activated.', 'must-hotel-booking')],
        'rule_deactivated' => ['success', \__('Pricing rule deactivated.', 'must-hotel-booking')],
        'rule_delete_failed' => ['error', \__('Unable to delete pricing rule.', 'must-hotel-booking')],
        'rule_duplicate_failed' => ['error', \__('Unable to duplicate pricing rule.', 'must-hotel-booking')],
        'rule_update_failed' => ['error', \__('Unable to update pricing rule status.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $message = $messages[$notice];
    $class = (string) $message[0] === 'success' ? 'notice notice-success' : 'notice notice-error';
    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html((string) $message[1]) . '</p></div>';
}

/**
 * @param array<int, array<string, string>> $summaryCards
 */
function render_pricing_summary_cards(array $summaryCards): void
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
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_pricing_filters(array $filters, array $roomOptions): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Filters', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-pricing" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-pricing-filter-room">' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</label></th><td><select id="must-pricing-filter-room" name="room_id"><option value="0">' . \esc_html__('All room listings', 'must-hotel-booking') . '</option>';
    foreach ($roomOptions as $option) {
        $optionId = isset($option['id']) ? (int) $option['id'] : 0;
        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($filters['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-filter-search">' . \esc_html__('Search', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-filter-search" class="regular-text" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Rule or accommodation name', 'must-hotel-booking') . '" /></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-filter-status">' . \esc_html__('Status', 'must-hotel-booking') . '</label></th><td><select id="must-pricing-filter-status" name="status">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'active' => __('Active only', 'must-hotel-booking'), 'inactive' => __('Inactive only', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['status'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-filter-timeline">' . \esc_html__('Rule timing', 'must-hotel-booking') . '</label></th><td><select id="must-pricing-filter-timeline" name="timeline">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'current' => __('Current', 'must-hotel-booking'), 'future' => __('Current + Future', 'must-hotel-booking'), 'past' => __('Past', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['timeline'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-filter-setup">' . \esc_html__('Operational check', 'must-hotel-booking') . '</label></th><td><select id="must-pricing-filter-setup" name="setup">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'missing_pricing' => __('Missing pricing', 'must-hotel-booking'), 'overlap' => __('Overlapping rules', 'must-hotel-booking'), 'rate_plan' => __('Has rate plan pricing', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['setup'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-filter-scope">' . \esc_html__('Rule scope', 'must-hotel-booking') . '</label></th><td><select id="must-pricing-filter-scope" name="scope">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'global' => __('Global rules', 'must-hotel-booking'), 'room' => __('Accommodation-specific', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['scope'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-filter-rule-type">' . \esc_html__('Rule type', 'must-hotel-booking') . '</label></th><td><select id="must-pricing-filter-rule-type" name="rule_type">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'nightly' => __('Nightly override', 'must-hotel-booking'), 'weekend' => __('Weekend override', 'must-hotel-booking'), 'mixed' => __('Nightly + weekend', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['rule_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '</tbody></table>';
    \submit_button(\__('Apply Filters', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, string> $errors
 */
function render_pricing_error_notice(array $errors): void
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
 * @param array<int, array<string, mixed>> $rows
 */
function render_pricing_base_rate_table(array $rows): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Base Pricing Overview', 'must-hotel-booking') . '</h2>';
    echo '<p class="description">' . \esc_html__('Direct booking pricing starts from the room/listing base price. Active rate plans can replace that base in the booking flow, and override rules below can change direct pricing for specific dates.', 'must-hotel-booking') . '</p>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Base Price', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Current Nightly Preview', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Override Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Rate Plan Pricing', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Warnings', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Quick Edit', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="7">' . \esc_html__('No room listings matched the current filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $roomUrl = get_admin_rooms_page_url(['tab' => 'rooms', 'action' => 'edit_room', 'type_id' => $roomId]);
            $pricingUrl = get_admin_pricing_page_url(['room_id' => $roomId]);
            $ratePlansUrl = \function_exists(__NAMESPACE__ . '\get_admin_rate_plans_page_url')
                ? get_admin_rate_plans_page_url(['room_type_id' => $roomId])
                : '';

            echo '<tr>';
            echo '<td><strong><a href="' . \esc_url($roomUrl) . '">' . \esc_html((string) ($row['name'] ?? '')) . '</a></strong><br /><span style="color:#646970;">' . \esc_html((string) ($row['category'] ?? '')) . '</span></td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($row['base_price'] ?? 0.0), 2)) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($row['current_nightly'] ?? 0.0), 2)) . '</td>';

            $overrideMeta = \sprintf(
                _n('%d active rule', '%d active rules', (int) ($row['active_rule_count'] ?? 0), 'must-hotel-booking'),
                (int) ($row['active_rule_count'] ?? 0)
            );

            if (!empty($row['current_rule']) && \is_array($row['current_rule'])) {
                $overrideMeta .= ' | ' . \sprintf(
                    /* translators: %s is rule name. */
                    \__('Live: %s', 'must-hotel-booking'),
                    (string) ($row['current_rule']['name'] ?? '')
                );
            } elseif (!empty($row['next_rule']) && \is_array($row['next_rule'])) {
                $overrideMeta .= ' | ' . \sprintf(
                    /* translators: 1: rule name, 2: date. */
                    \__('Next: %1$s on %2$s', 'must-hotel-booking'),
                    (string) ($row['next_rule']['name'] ?? ''),
                    (string) ($row['next_rule']['start_date'] ?? '')
                );
            }

            echo '<td>' . \esc_html($overrideMeta) . '<br /><a href="' . \esc_url($pricingUrl) . '">' . \esc_html__('Filter overrides', 'must-hotel-booking') . '</a></td>';
            echo '<td>' . \esc_html(\sprintf(_n('%d active rate plan', '%d active rate plans', (int) ($row['active_rate_plan_count'] ?? 0), 'must-hotel-booking'), (int) ($row['active_rate_plan_count'] ?? 0))) . ($ratePlansUrl !== '' ? '<br /><a href="' . \esc_url($ratePlansUrl) . '">' . \esc_html__('Open advanced pricing', 'must-hotel-booking') . '</a>' : '') . '</td>';

            echo '<td>';
            if (empty($row['warnings'])) {
                echo '<span style="color:#2271b1;">' . \esc_html__('Pricing looks ready.', 'must-hotel-booking') . '</span>';
            } else {
                echo '<ul style="margin:0;padding-left:18px;">';
                foreach ((array) ($row['warnings'] ?? []) as $warning) {
                    echo '<li>' . \esc_html((string) $warning) . '</li>';
                }
                echo '</ul>';
            }
            echo '</td>';

            echo '<td><form method="post" action="' . \esc_url(get_admin_pricing_page_url()) . '" style="display:flex;gap:8px;align-items:center;">';
            \wp_nonce_field('must_pricing_save_base_price', 'must_pricing_base_nonce');
            echo '<input type="hidden" name="must_pricing_action" value="save_base_price" />';
            echo '<input type="hidden" name="room_id" value="' . \esc_attr((string) $roomId) . '" />';
            echo '<input type="number" min="0" step="0.01" name="base_price" value="' . \esc_attr(\number_format((float) ($row['base_price'] ?? 0.0), 2, '.', '')) . '" style="width:120px;" />';
            echo '<button type="submit" class="button button-secondary">' . \esc_html__('Save', 'must-hotel-booking') . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param PricingAdminQuery $query
 */
function render_pricing_rules_table(array $rows, PricingAdminQuery $query): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Pricing Rules', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Applies To', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Rule Type', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Start', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('End', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Nightly', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Weekend', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Priority', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Usage', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="11">' . \esc_html__('No pricing rules matched the current filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $ruleId = isset($row['id']) ? (int) $row['id'] : 0;
            $editUrl = get_admin_pricing_page_url($query->buildUrlArgs(['action' => 'edit_rule', 'rule_id' => $ruleId]));
            $duplicateUrl = \wp_nonce_url(
                get_admin_pricing_page_url($query->buildUrlArgs(['action' => 'duplicate_rule', 'rule_id' => $ruleId])),
                'must_pricing_duplicate_rule_' . $ruleId
            );
            $toggleUrl = \wp_nonce_url(
                get_admin_pricing_page_url($query->buildUrlArgs(['action' => 'toggle_rule_status', 'rule_id' => $ruleId, 'target' => !empty($row['is_active']) ? 'inactive' : 'active'])),
                'must_pricing_toggle_rule_' . $ruleId
            );
            $deleteUrl = \wp_nonce_url(
                get_admin_pricing_page_url($query->buildUrlArgs(['action' => 'delete_rule', 'rule_id' => $ruleId])),
                'must_pricing_delete_rule_' . $ruleId
            );
            $roomPricingUrl = get_admin_pricing_page_url(['room_id' => (int) ($row['room_id'] ?? 0)]);

            echo '<tr>';
            echo '<td><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong></td>';
            echo '<td>' . \esc_html((string) ($row['room_name'] ?? '')) . ((int) ($row['room_id'] ?? 0) > 0 ? '<br /><a href="' . \esc_url($roomPricingUrl) . '">' . \esc_html__('Filter to this accommodation', 'must-hotel-booking') . '</a>' : '') . '</td>';
            echo '<td>' . \esc_html((string) ($row['rule_type_label'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['start_date'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['end_date'] ?? '')) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($row['price_override'] ?? 0.0), 2)) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($row['weekend_price'] ?? 0.0), 2)) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($row['priority'] ?? 10))) . '</td>';
            echo '<td>' . \esc_html(!empty($row['is_active']) ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking')) . '</td>';
            echo '<td>' . \esc_html((string) \ucfirst((string) ($row['timeline'] ?? 'future'))) . '</td>';
            echo '<td><a class="button button-small" href="' . \esc_url($editUrl) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url($duplicateUrl) . '">' . \esc_html__('Duplicate', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url($toggleUrl) . '">' . \esc_html(!empty($row['is_active']) ? __('Deactivate', 'must-hotel-booking') : __('Activate', 'must-hotel-booking')) . '</a> ';
            echo '<a class="button button-small button-link-delete" href="' . \esc_url($deleteUrl) . '" onclick="return confirm(\'' . \esc_js(__('Delete this pricing rule?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $form
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_pricing_rule_form(array $form, array $roomOptions): void
{
    $isEditMode = (int) ($form['id'] ?? 0) > 0;

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($isEditMode ? __('Edit Pricing Rule', 'must-hotel-booking') : __('Create Pricing Rule', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_pricing_page_url()) . '">';
    \wp_nonce_field('must_pricing_save_rule', 'must_pricing_rule_nonce');
    echo '<input type="hidden" name="must_pricing_action" value="save_rule" />';
    echo '<input type="hidden" name="rule_id" value="' . \esc_attr((string) ($form['id'] ?? 0)) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-pricing-rule-room-id">' . \esc_html__('Rule scope', 'must-hotel-booking') . '</label></th><td><select id="must-pricing-rule-room-id" name="room_id"><option value="0">' . \esc_html__('All room listings', 'must-hotel-booking') . '</option>';
    foreach ($roomOptions as $option) {
        $optionId = isset($option['id']) ? (int) $option['id'] : 0;
        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($form['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . \esc_html__('Room-specific rules beat global rules. Higher priority beats lower priority. If priority ties, the most specific/latest rule wins.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-pricing-rule-name">' . \esc_html__('Rule name', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-rule-name" class="regular-text" type="text" name="name" value="' . \esc_attr((string) ($form['name'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-pricing-rule-start-date">' . \esc_html__('Start date', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-rule-start-date" type="date" name="start_date" value="' . \esc_attr((string) ($form['start_date'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-pricing-rule-end-date">' . \esc_html__('End date', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-rule-end-date" type="date" name="end_date" value="' . \esc_attr((string) ($form['end_date'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-pricing-rule-price-override">' . \esc_html__('Nightly override', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-rule-price-override" type="number" min="0" step="0.01" name="price_override" value="' . \esc_attr(\number_format((float) ($form['price_override'] ?? 0.0), 2, '.', '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-pricing-rule-weekend-price">' . \esc_html__('Weekend override', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-rule-weekend-price" type="number" min="0" step="0.01" name="weekend_price" value="' . \esc_attr(\number_format((float) ($form['weekend_price'] ?? 0.0), 2, '.', '')) . '" />';
    echo '<p class="description">' . \esc_html__('Applied to Friday/Saturday stays when no higher-precedence nightly override wins for that date.', 'must-hotel-booking') . '</p></td></tr>';
    echo '<tr><th scope="row"><label for="must-pricing-rule-minimum-nights">' . \esc_html__('Minimum nights', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-rule-minimum-nights" type="number" min="1" step="1" name="minimum_nights" value="' . \esc_attr((string) ((int) ($form['minimum_nights'] ?? 1))) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-pricing-rule-priority">' . \esc_html__('Priority', 'must-hotel-booking') . '</label></th><td><input id="must-pricing-rule-priority" type="number" min="0" step="1" name="priority" value="' . \esc_attr((string) ((int) ($form['priority'] ?? 10))) . '" required /></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Status', 'must-hotel-booking') . '</th><td><label><input type="checkbox" name="is_active" value="1"' . \checked(!empty($form['is_active']), true, false) . ' /> ' . \esc_html__('Active', 'must-hotel-booking') . '</label></td></tr>';

    if (!empty($form['warnings'])) {
        echo '<tr><th scope="row">' . \esc_html__('Operational summary', 'must-hotel-booking') . '</th><td><ul style="margin:0;padding-left:18px;">';
        foreach ((array) ($form['warnings'] ?? []) as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul></td></tr>';
    }

    echo '</tbody></table>';
    \submit_button($isEditMode ? __('Save Pricing Rule', 'must-hotel-booking') : __('Create Pricing Rule', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('New Blank Rule', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

function render_admin_pricing_page(): void
{
    ensure_admin_capability();

    $query = PricingAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new PricingAdminDataProvider())->getPageData($query, get_pricing_admin_save_state());
    clear_pricing_admin_save_state();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Rates & Pricing', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Direct booking pricing is managed here using room/listing base rates and date-range override rules. Rate Plans remain the advanced pricing layer for package-like pricing and dated rate calendars.', 'must-hotel-booking') . '</p>';
    echo '<p><strong>' . \esc_html__('Calculation order:', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($pageData['calculation_note'] ?? '')) . '</p>';
    echo '<p>';
    if (\function_exists(__NAMESPACE__ . '\get_admin_rate_plans_page_url')) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rate_plans_page_url()) . '">' . \esc_html__('Open Rate Plans', 'must-hotel-booking') . '</a> ';
    }
    if (\function_exists(__NAMESPACE__ . '\get_admin_taxes_page_url')) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_taxes_page_url()) . '">' . \esc_html__('Open Taxes & Fees', 'must-hotel-booking') . '</a> ';
    }
    if (\function_exists(__NAMESPACE__ . '\get_admin_coupons_page_url')) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_coupons_page_url()) . '">' . \esc_html__('Open Coupons', 'must-hotel-booking') . '</a>';
    }
    echo '</p>';

    render_pricing_admin_notice_from_query();
    render_pricing_error_notice(isset($pageData['base_price_errors']) && \is_array($pageData['base_price_errors']) ? $pageData['base_price_errors'] : []);
    render_pricing_error_notice(isset($pageData['rule_errors']) && \is_array($pageData['rule_errors']) ? $pageData['rule_errors'] : []);
    render_pricing_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    render_pricing_filters(
        isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [],
        isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : []
    );
    render_pricing_base_rate_table(isset($pageData['room_rows']) && \is_array($pageData['room_rows']) ? $pageData['room_rows'] : []);
    render_pricing_rules_table(
        isset($pageData['rule_rows']) && \is_array($pageData['rule_rows']) ? $pageData['rule_rows'] : [],
        $query
    );
    render_pricing_rule_form(
        isset($pageData['rule_form']) && \is_array($pageData['rule_form']) ? $pageData['rule_form'] : [],
        isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : []
    );
    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_pricing_admin_actions_early', 1);
