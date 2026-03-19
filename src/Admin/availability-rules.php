<?php

namespace MustHotelBooking\Admin;

/**
 * @param array<string, scalar> $args
 */
function get_admin_availability_rules_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-availability-rules');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

function is_availability_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-availability-rules';
}

/**
 * @return array<string, mixed>
 */
function get_availability_admin_save_state(): array
{
    global $mustHotelBookingAvailabilityAdminSaveState;

    if (isset($mustHotelBookingAvailabilityAdminSaveState) && \is_array($mustHotelBookingAvailabilityAdminSaveState)) {
        return $mustHotelBookingAvailabilityAdminSaveState;
    }

    $mustHotelBookingAvailabilityAdminSaveState = [
        'errors' => [],
        'rule_errors' => [],
        'block_errors' => [],
        'rule_form' => null,
        'block_form' => null,
    ];

    return $mustHotelBookingAvailabilityAdminSaveState;
}

/**
 * @param array<string, mixed> $state
 */
function set_availability_admin_save_state(array $state): void
{
    global $mustHotelBookingAvailabilityAdminSaveState;
    $mustHotelBookingAvailabilityAdminSaveState = $state;
}

function clear_availability_admin_save_state(): void
{
    set_availability_admin_save_state([
        'errors' => [],
        'rule_errors' => [],
        'block_errors' => [],
        'rule_form' => null,
        'block_form' => null,
    ]);
}

function maybe_handle_availability_admin_actions_early(): void
{
    if (!is_availability_admin_request()) {
        return;
    }

    ensure_admin_capability();
    $query = AvailabilityAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod === 'POST') {
        set_availability_admin_save_state((new AvailabilityAdminActions())->handleSaveRequest($query));
        return;
    }

    (new AvailabilityAdminActions())->handleGetAction($query);
}

function render_availability_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'rule_created' => ['success', \__('Availability rule created successfully.', 'must-hotel-booking')],
        'rule_updated' => ['success', \__('Availability rule updated successfully.', 'must-hotel-booking')],
        'rule_saved_with_conflict' => ['warning', \__('Availability rule saved, but it overlaps existing reservations.', 'must-hotel-booking')],
        'rule_deleted' => ['success', \__('Availability rule deleted successfully.', 'must-hotel-booking')],
        'rule_duplicated' => ['success', \__('Availability rule duplicated successfully.', 'must-hotel-booking')],
        'rule_activated' => ['success', \__('Availability rule activated.', 'must-hotel-booking')],
        'rule_deactivated' => ['success', \__('Availability rule deactivated.', 'must-hotel-booking')],
        'rule_delete_failed' => ['error', \__('Unable to delete availability rule.', 'must-hotel-booking')],
        'rule_duplicate_failed' => ['error', \__('Unable to duplicate availability rule.', 'must-hotel-booking')],
        'rule_update_failed' => ['error', \__('Unable to update availability rule status.', 'must-hotel-booking')],
        'block_created' => ['success', \__('Manual block created successfully.', 'must-hotel-booking')],
        'block_updated' => ['success', \__('Manual block updated successfully.', 'must-hotel-booking')],
        'block_saved_with_conflict' => ['warning', \__('Manual block saved, but it overlaps existing reservations or blocks.', 'must-hotel-booking')],
        'block_deleted' => ['success', \__('Manual block removed successfully.', 'must-hotel-booking')],
        'block_duplicated' => ['success', \__('Manual block duplicated successfully.', 'must-hotel-booking')],
        'block_delete_failed' => ['error', \__('Unable to remove the selected manual block.', 'must-hotel-booking')],
        'block_duplicate_failed' => ['error', \__('Unable to duplicate the selected manual block.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $message = $messages[$notice];
    $class = 'notice notice-success';

    if ((string) $message[0] === 'warning') {
        $class = 'notice notice-warning';
    } elseif ((string) $message[0] === 'error') {
        $class = 'notice notice-error';
    }

    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html((string) $message[1]) . '</p></div>';
}

/**
 * @param array<int, string> $errors
 */
function render_availability_error_notice(array $errors): void
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
function render_availability_summary_cards(array $summaryCards): void
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
function render_availability_filters(array $filters, array $roomOptions): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Filters', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-availability-rules" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-availability-filter-room">' . \esc_html__('Accommodation type', 'must-hotel-booking') . '</label></th><td><select id="must-availability-filter-room" name="room_id"><option value="0">' . \esc_html__('All accommodation types', 'must-hotel-booking') . '</option>';
    foreach ($roomOptions as $option) {
        $optionId = isset($option['id']) ? (int) $option['id'] : 0;
        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($filters['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-availability-filter-search">' . \esc_html__('Search', 'must-hotel-booking') . '</label></th><td><input id="must-availability-filter-search" class="regular-text" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Rule, block, or accommodation name', 'must-hotel-booking') . '" /></td></tr>';

    echo '<tr><th scope="row"><label for="must-availability-filter-status">' . \esc_html__('Status', 'must-hotel-booking') . '</label></th><td><select id="must-availability-filter-status" name="status">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'active' => __('Active only', 'must-hotel-booking'), 'inactive' => __('Inactive rules only', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['status'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-availability-filter-timeline">' . \esc_html__('Timing', 'must-hotel-booking') . '</label></th><td><select id="must-availability-filter-timeline" name="timeline">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'current' => __('Current', 'must-hotel-booking'), 'future' => __('Current + Future', 'must-hotel-booking'), 'past' => __('Past', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['timeline'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-availability-filter-mode">' . \esc_html__('Entry type', 'must-hotel-booking') . '</label></th><td><select id="must-availability-filter-mode" name="mode">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'blocked' => __('Manual blocks', 'must-hotel-booking'), 'restriction' => __('Restrictions only', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['mode'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-availability-filter-rule-type">' . \esc_html__('Rule type', 'must-hotel-booking') . '</label></th><td><select id="must-availability-filter-rule-type" name="rule_type">';
    foreach ([
        'all' => __('All', 'must-hotel-booking'),
        'manual_block' => __('Manual block', 'must-hotel-booking'),
        'maintenance_block' => __('Blocked date range', 'must-hotel-booking'),
        'minimum_stay' => __('Minimum stay', 'must-hotel-booking'),
        'maximum_stay' => __('Maximum stay', 'must-hotel-booking'),
        'closed_arrival' => __('Closed arrival', 'must-hotel-booking'),
        'closed_departure' => __('Closed departure', 'must-hotel-booking'),
    ] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['rule_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '</tbody></table>';
    \submit_button(\__('Apply Filters', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_availability_rules_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function render_availability_room_overview(array $rows): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Accommodation Availability Overview', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('Accommodation Type', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Rules', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Manual Blocks', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reservations', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Warnings', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="6">' . \esc_html__('No accommodation types matched the current filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            echo '<tr>';
            echo '<td><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong><br /><span style="color:#646970;">' . \esc_html((string) ($row['category'] ?? '')) . '</span></td>';
            echo '<td>' . \esc_html(\sprintf(_n('%d scoped rule', '%d scoped rules', (int) ($row['rule_count'] ?? 0), 'must-hotel-booking'), (int) ($row['rule_count'] ?? 0))) . '<br /><span style="color:#646970;">' . \esc_html(\sprintf(__('Global rules visible: %d', 'must-hotel-booking'), (int) ($row['global_rule_count'] ?? 0))) . '</span></td>';
            echo '<td>' . \esc_html(\sprintf(__('Current %1$d | Future %2$d', 'must-hotel-booking'), (int) ($row['current_block_count'] ?? 0), (int) ($row['future_block_count'] ?? 0))) . '</td>';
            echo '<td>' . \esc_html(\sprintf(__('Current %1$d | Future %2$d', 'must-hotel-booking'), (int) ($row['current_reservations'] ?? 0), (int) ($row['future_reservations'] ?? 0))) . '</td>';
            echo '<td>';

            if (empty($row['warnings'])) {
                echo '<span style="color:#2271b1;">' . \esc_html__('Availability setup looks operational.', 'must-hotel-booking') . '</span>';
            } else {
                echo '<ul style="margin:0;padding-left:18px;">';
                foreach ((array) ($row['warnings'] ?? []) as $warning) {
                    echo '<li>' . \esc_html((string) $warning) . '</li>';
                }
                echo '</ul>';
            }

            echo '</td>';
            echo '<td>';
            if ((string) ($row['accommodation_url'] ?? '') !== '') {
                echo '<a class="button button-small" href="' . \esc_url((string) $row['accommodation_url']) . '">' . \esc_html__('Open Accommodation', 'must-hotel-booking') . '</a> ';
            }
            if ((string) ($row['calendar_url'] ?? '') !== '') {
                echo '<a class="button button-small" href="' . \esc_url((string) $row['calendar_url']) . '">' . \esc_html__('Open Calendar', 'must-hotel-booking') . '</a> ';
            }
            echo '<a class="button button-small" href="' . \esc_url((string) ($row['filtered_url'] ?? '')) . '">' . \esc_html__('Filter Rules', 'must-hotel-booking') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param AvailabilityAdminQuery $query
 */
function render_availability_entries_table(array $rows, AvailabilityAdminQuery $query): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Availability Entries', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Applies To', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Rule Type', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Start', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('End', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Min Stay', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Max Stay', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-in', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-out', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Usage', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="12">' . \esc_html__('No availability entries matched the current filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $entryId = isset($row['id']) ? (int) $row['id'] : 0;
            $isRule = (string) ($row['source'] ?? '') === 'rule';
            $editUrl = get_admin_availability_rules_page_url($query->buildUrlArgs([
                'action' => $isRule ? 'edit_rule' : 'edit_block',
                'rule_id' => $isRule ? $entryId : 0,
                'block_id' => $isRule ? 0 : $entryId,
            ]));
            $duplicateUrl = \wp_nonce_url(
                get_admin_availability_rules_page_url($query->buildUrlArgs([
                    'action' => $isRule ? 'duplicate_rule' : 'duplicate_block',
                    'rule_id' => $isRule ? $entryId : 0,
                    'block_id' => $isRule ? 0 : $entryId,
                ])),
                $isRule ? 'must_availability_duplicate_rule_' . $entryId : 'must_availability_duplicate_block_' . $entryId
            );
            $deleteUrl = \wp_nonce_url(
                get_admin_availability_rules_page_url($query->buildUrlArgs([
                    'action' => $isRule ? 'delete_rule' : 'delete_block',
                    'rule_id' => $isRule ? $entryId : 0,
                    'block_id' => $isRule ? 0 : $entryId,
                ])),
                $isRule ? 'must_availability_delete_rule_' . $entryId : 'must_availability_delete_block_' . $entryId
            );
            $toggleUrl = $isRule
                ? \wp_nonce_url(
                    get_admin_availability_rules_page_url($query->buildUrlArgs([
                        'action' => 'toggle_rule_status',
                        'rule_id' => $entryId,
                        'target' => !empty($row['is_active']) ? 'inactive' : 'active',
                    ])),
                    'must_availability_toggle_rule_' . $entryId
                )
                : '';

            echo '<tr>';
            echo '<td><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong></td>';
            echo '<td>' . \esc_html((string) ($row['room_name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['rule_type_label'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['availability_date'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['end_date'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($row['minimum_stay'] ?? 0) > 0 ? (int) $row['minimum_stay'] : '')) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($row['maximum_stay'] ?? 0) > 0 ? (int) $row['maximum_stay'] : '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['checkin'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['checkout'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['status_label'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) \ucfirst((string) ($row['timeline'] ?? 'future'))) . '</td>';
            echo '<td><a class="button button-small" href="' . \esc_url($editUrl) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url($duplicateUrl) . '">' . \esc_html__('Duplicate', 'must-hotel-booking') . '</a> ';
            if ($toggleUrl !== '') {
                echo '<a class="button button-small" href="' . \esc_url($toggleUrl) . '">' . \esc_html(!empty($row['is_active']) ? __('Deactivate', 'must-hotel-booking') : __('Activate', 'must-hotel-booking')) . '</a> ';
            }
            echo '<a class="button button-small button-link-delete" href="' . \esc_url($deleteUrl) . '" onclick="return confirm(\'' . \esc_js(__('Delete this availability entry?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a></td>';
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
function render_availability_rule_form(array $form, array $roomOptions): void
{
    $isEditMode = (int) ($form['id'] ?? 0) > 0;

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($isEditMode ? __('Edit Restriction Rule', 'must-hotel-booking') : __('Create Restriction Rule', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_availability_rules_page_url()) . '">';
    \wp_nonce_field('must_availability_save_rule', 'must_availability_rule_nonce');
    echo '<input type="hidden" name="must_availability_action" value="save_rule" />';
    echo '<input type="hidden" name="rule_id" value="' . \esc_attr((string) ($form['id'] ?? 0)) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-availability-rule-room-id">' . \esc_html__('Rule scope', 'must-hotel-booking') . '</label></th><td><select id="must-availability-rule-room-id" name="room_id"><option value="0">' . \esc_html__('All accommodation types', 'must-hotel-booking') . '</option>';
    foreach ($roomOptions as $option) {
        $optionId = isset($option['id']) ? (int) $option['id'] : 0;
        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($form['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-rule-name">' . \esc_html__('Rule name', 'must-hotel-booking') . '</label></th><td><input id="must-availability-rule-name" class="regular-text" type="text" name="name" value="' . \esc_attr((string) ($form['name'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-rule-type">' . \esc_html__('Rule type', 'must-hotel-booking') . '</label></th><td><select id="must-availability-rule-type" name="rule_type">';
    foreach ([
        'maintenance_block' => __('Blocked date range', 'must-hotel-booking'),
        'minimum_stay' => __('Minimum stay', 'must-hotel-booking'),
        'maximum_stay' => __('Maximum stay', 'must-hotel-booking'),
        'closed_arrival' => __('Closed arrival', 'must-hotel-booking'),
        'closed_departure' => __('Closed departure', 'must-hotel-booking'),
    ] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($form['rule_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-rule-start-date">' . \esc_html__('Start date', 'must-hotel-booking') . '</label></th><td><input id="must-availability-rule-start-date" type="date" name="availability_date" value="' . \esc_attr((string) ($form['availability_date'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-rule-end-date">' . \esc_html__('End date', 'must-hotel-booking') . '</label></th><td><input id="must-availability-rule-end-date" type="date" name="end_date" value="' . \esc_attr((string) ($form['end_date'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-rule-value">' . \esc_html__('Stay value', 'must-hotel-booking') . '</label></th><td><input id="must-availability-rule-value" type="number" min="1" step="1" name="rule_value" value="' . \esc_attr((string) ((int) ($form['rule_value'] ?? 1))) . '" /><p class="description">' . \esc_html__('Used only for minimum-stay and maximum-stay rules.', 'must-hotel-booking') . '</p></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-rule-reason">' . \esc_html__('Operational note', 'must-hotel-booking') . '</label></th><td><input id="must-availability-rule-reason" class="regular-text" type="text" name="reason" value="' . \esc_attr((string) ($form['reason'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Status', 'must-hotel-booking') . '</th><td><label><input type="checkbox" name="is_active" value="1"' . \checked(!empty($form['is_active']), true, false) . ' /> ' . \esc_html__('Active', 'must-hotel-booking') . '</label></td></tr>';

    if (!empty($form['warnings'])) {
        echo '<tr><th scope="row">' . \esc_html__('Operational summary', 'must-hotel-booking') . '</th><td><ul style="margin:0;padding-left:18px;">';
        foreach ((array) ($form['warnings'] ?? []) as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul></td></tr>';
    }

    echo '</tbody></table>';
    \submit_button($isEditMode ? __('Save Restriction Rule', 'must-hotel-booking') : __('Create Restriction Rule', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $form
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_manual_block_form(array $form, array $roomOptions): void
{
    $isEditMode = (int) ($form['id'] ?? 0) > 0;

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($isEditMode ? __('Edit Manual Block', 'must-hotel-booking') : __('Create Manual Block', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_availability_rules_page_url()) . '">';
    \wp_nonce_field('must_availability_save_block', 'must_availability_block_nonce');
    echo '<input type="hidden" name="must_availability_action" value="save_block" />';
    echo '<input type="hidden" name="block_id" value="' . \esc_attr((string) ($form['id'] ?? 0)) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-availability-block-room-id">' . \esc_html__('Accommodation type', 'must-hotel-booking') . '</label></th><td><select id="must-availability-block-room-id" name="room_id">';
    foreach ($roomOptions as $option) {
        $optionId = isset($option['id']) ? (int) $option['id'] : 0;
        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($form['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-block-checkin">' . \esc_html__('Start date', 'must-hotel-booking') . '</label></th><td><input id="must-availability-block-checkin" type="date" name="checkin" value="' . \esc_attr((string) ($form['checkin'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-block-checkout">' . \esc_html__('End date', 'must-hotel-booking') . '</label></th><td><input id="must-availability-block-checkout" type="date" name="checkout" value="' . \esc_attr((string) ($form['checkout'] ?? '')) . '" required /><p class="description">' . \esc_html__('The end date is exclusive, matching reservation checkout behavior.', 'must-hotel-booking') . '</p></td></tr>';
    echo '<tr><th scope="row"><label for="must-availability-block-notes">' . \esc_html__('Block note', 'must-hotel-booking') . '</label></th><td><textarea id="must-availability-block-notes" class="large-text" rows="4" name="notes">' . \esc_textarea((string) ($form['notes'] ?? '')) . '</textarea></td></tr>';

    if (!empty($form['warnings'])) {
        echo '<tr><th scope="row">' . \esc_html__('Operational summary', 'must-hotel-booking') . '</th><td><ul style="margin:0;padding-left:18px;">';
        foreach ((array) ($form['warnings'] ?? []) as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul></td></tr>';
    }

    echo '</tbody></table>';
    \submit_button($isEditMode ? __('Save Manual Block', 'must-hotel-booking') : __('Create Manual Block', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';
}

function render_admin_availability_rules_page(): void
{
    ensure_admin_capability();

    $query = AvailabilityAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new AvailabilityAdminDataProvider())->getPageData($query, get_availability_admin_save_state());
    clear_availability_admin_save_state();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Availability Rules', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Use this screen to manage blocked date ranges, stay restrictions, and manual blocks without leaving the operational booking flow.', 'must-hotel-booking') . '</p>';
    echo '<p><strong>' . \esc_html__('Resolution order:', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($pageData['booking_note'] ?? '')) . '</p>';
    echo '<p>';
    if (\function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_calendar_page_url(['start_date' => \current_time('Y-m-d'), 'weeks' => 2])) . '">' . \esc_html__('Open Calendar', 'must-hotel-booking') . '</a> ';
    }
    if (\function_exists(__NAMESPACE__ . '\get_admin_rooms_page_url')) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'types'])) . '">' . \esc_html__('Open Accommodations', 'must-hotel-booking') . '</a> ';
    }
    if (\function_exists(__NAMESPACE__ . '\get_admin_pricing_page_url')) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('Open Rates & Pricing', 'must-hotel-booking') . '</a>';
    }
    echo '</p>';

    render_availability_admin_notice_from_query();
    render_availability_error_notice(isset($pageData['rule_errors']) && \is_array($pageData['rule_errors']) ? $pageData['rule_errors'] : []);
    render_availability_error_notice(isset($pageData['block_errors']) && \is_array($pageData['block_errors']) ? $pageData['block_errors'] : []);
    render_availability_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    render_availability_filters(
        isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [],
        isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : []
    );
    render_availability_room_overview(isset($pageData['room_rows']) && \is_array($pageData['room_rows']) ? $pageData['room_rows'] : []);
    render_availability_entries_table(
        isset($pageData['entry_rows']) && \is_array($pageData['entry_rows']) ? $pageData['entry_rows'] : [],
        $query
    );
    render_availability_rule_form(
        isset($pageData['rule_form']) && \is_array($pageData['rule_form']) ? $pageData['rule_form'] : [],
        isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : []
    );
    render_manual_block_form(
        isset($pageData['block_form']) && \is_array($pageData['block_form']) ? $pageData['block_form'] : [],
        isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : []
    );
    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_availability_admin_actions_early', 1);
