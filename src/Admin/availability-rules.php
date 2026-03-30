<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;

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

function render_availability_badge(string $label, string $tone = 'info'): string
{
    $toneMap = [
        'ok' => 'is-ok',
        'success' => 'is-ok',
        'warning' => 'is-warning',
        'error' => 'is-error',
        'danger' => 'is-error',
        'info' => 'is-info',
        'muted' => 'is-muted',
    ];
    $className = isset($toneMap[$tone]) ? $toneMap[$tone] : 'is-info';

    return '<span class="must-dashboard-status-badge is-compact must-availability-badge ' . \esc_attr($className) . '">' . \esc_html($label) . '</span>';
}

/**
 * @param array<int, array<string, string>> $actions
 */
function render_availability_empty_state(string $title, string $message, array $actions = []): void
{
    echo '<div class="must-availability-empty-state">';
    echo '<div class="must-availability-empty-state-copy">';
    echo '<h3>' . \esc_html($title) . '</h3>';
    echo '<p>' . \esc_html($message) . '</p>';
    echo '</div>';

    if (!empty($actions)) {
        echo '<div class="must-availability-empty-actions">';

        foreach ($actions as $action) {
            if (!\is_array($action)) {
                continue;
            }

            $url = isset($action['url']) ? (string) $action['url'] : '';

            if ($url === '') {
                continue;
            }

            $className = isset($action['class']) ? (string) $action['class'] : 'button';
            echo '<a class="' . \esc_attr($className) . '" href="' . \esc_url($url) . '">' . \esc_html((string) ($action['label'] ?? 'Open')) . '</a>';
        }

        echo '</div>';
    }

    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $roomOptions
 * @return array<int, string>
 */
function get_availability_room_option_labels(array $roomOptions): array
{
    $labels = [];

    foreach ($roomOptions as $option) {
        if (!\is_array($option)) {
            continue;
        }

        $optionId = isset($option['id']) ? (int) $option['id'] : 0;

        if ($optionId <= 0) {
            continue;
        }

        $labels[$optionId] = (string) ($option['label'] ?? ('#' . $optionId));
    }

    return $labels;
}

/**
 * @param array<string, mixed> $filters
 * @param array<int, array<string, mixed>> $roomOptions
 * @return array<int, array<string, string>>
 */
function get_availability_filter_chips(array $filters, array $roomOptions): array
{
    $chips = [];
    $roomLabels = get_availability_room_option_labels($roomOptions);
    $maps = [
        'status' => [
            'label' => \__('Status', 'must-hotel-booking'),
            'values' => [
                'active' => \__('Active only', 'must-hotel-booking'),
                'inactive' => \__('Inactive only', 'must-hotel-booking'),
            ],
        ],
        'timeline' => [
            'label' => \__('Timing', 'must-hotel-booking'),
            'values' => [
                'current' => \__('Current', 'must-hotel-booking'),
                'future' => \__('Current + Future', 'must-hotel-booking'),
                'past' => \__('Past', 'must-hotel-booking'),
            ],
        ],
        'mode' => [
            'label' => \__('Entry Type', 'must-hotel-booking'),
            'values' => [
                'blocked' => \__('Manual blocks', 'must-hotel-booking'),
                'restriction' => \__('Restrictions only', 'must-hotel-booking'),
            ],
        ],
        'rule_type' => [
            'label' => \__('Rule Type', 'must-hotel-booking'),
            'values' => [
                'manual_block' => \__('Manual block', 'must-hotel-booking'),
                'maintenance_block' => \__('Blocked date range', 'must-hotel-booking'),
                'minimum_stay' => \__('Minimum stay', 'must-hotel-booking'),
                'maximum_stay' => \__('Maximum stay', 'must-hotel-booking'),
                'closed_arrival' => \__('Closed arrival', 'must-hotel-booking'),
                'closed_departure' => \__('Closed departure', 'must-hotel-booking'),
            ],
        ],
    ];

    if (!empty($filters['room_id']) && isset($roomLabels[(int) $filters['room_id']])) {
        $chips[] = [
            'label' => \__('Room / Listing', 'must-hotel-booking'),
            'value' => $roomLabels[(int) $filters['room_id']],
        ];
    }

    if (!empty($filters['search'])) {
        $chips[] = [
            'label' => \__('Search', 'must-hotel-booking'),
            'value' => (string) $filters['search'],
        ];
    }

    foreach ($maps as $key => $map) {
        $value = isset($filters[$key]) ? (string) $filters[$key] : '';

        if ($value === '' || $value === 'all') {
            continue;
        }

        if (!isset($map['values'][$value])) {
            continue;
        }

        $chips[] = [
            'label' => (string) $map['label'],
            'value' => (string) $map['values'][$value],
        ];
    }

    return $chips;
}

/**
 * @param array<string, mixed> $filters
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_availability_filter_chips(array $filters, array $roomOptions): void
{
    $chips = get_availability_filter_chips($filters, $roomOptions);

    if (empty($chips)) {
        return;
    }

    echo '<div class="must-availability-filter-chips">';

    foreach ($chips as $chip) {
        echo '<span class="must-availability-filter-chip"><strong>' . \esc_html((string) ($chip['label'] ?? '')) . '</strong> ' . \esc_html((string) ($chip['value'] ?? '')) . '</span>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_availability_page_header(AvailabilityAdminQuery $query, array $pageData): void
{
    $roomRows = isset($pageData['room_rows']) && \is_array($pageData['room_rows']) ? $pageData['room_rows'] : [];
    $entryRows = isset($pageData['entry_rows']) && \is_array($pageData['entry_rows']) ? $pageData['entry_rows'] : [];
    $summaryCards = isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : [];
    $activeRestrictions = isset($summaryCards[1]['value']) ? (string) $summaryCards[1]['value'] : '0';
    $manualBlocks = isset($summaryCards[2]['value']) ? (string) $summaryCards[2]['value'] : '0';
    $warningCount = isset($summaryCards[3]['value']) ? (string) $summaryCards[3]['value'] : '0';
    $basePageUrl = get_admin_availability_rules_page_url($query->buildUrlArgs());

    echo '<header class="must-dashboard-hero must-availability-page-header">';
    echo '<div class="must-dashboard-hero-copy">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Availability Control Workspace', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Availability Rules', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage blocked date ranges, stay restrictions, and manual blocks in one operational workspace while keeping inventory, reservations, and sellability aligned.', 'must-hotel-booking') . '</p>';
    echo '<div class="must-dashboard-hero-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Listings In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($roomRows)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Entries In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($entryRows)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Active Restrictions', 'must-hotel-booking') . '</strong> ' . \esc_html($activeRestrictions) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Manual Blocks', 'must-hotel-booking') . '</strong> ' . \esc_html($manualBlocks) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Setup Warnings', 'must-hotel-booking') . '</strong> ' . \esc_html($warningCount) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-dashboard-hero-actions">';
    echo '<a class="button button-primary" href="' . \esc_url($basePageUrl . '#must-availability-rule-form') . '">' . \esc_html__('Create Restriction Rule', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url($basePageUrl . '#must-manual-block-form') . '">' . \esc_html__('Create Manual Block', 'must-hotel-booking') . '</a>';

    if (\function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')) {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_calendar_page_url(['start_date' => \current_time('Y-m-d'), 'weeks' => 2])) . '">' . \esc_html__('Open Calendar', 'must-hotel-booking') . '</a>';
    }

    if (\function_exists(__NAMESPACE__ . '\get_admin_rooms_page_url')) {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '">' . \esc_html__('Open Room Listings', 'must-hotel-booking') . '</a>';
    }

    if (\function_exists(__NAMESPACE__ . '\get_admin_pricing_page_url')) {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('Open Rates & Pricing', 'must-hotel-booking') . '</a>';
    }

    echo '</div>';
    echo '</header>';
}

function render_availability_section_navigation(AvailabilityAdminQuery $query): void
{
    if (!\function_exists(__NAMESPACE__ . '\render_dashboard_action_strip')) {
        return;
    }

    $basePageUrl = get_admin_availability_rules_page_url($query->buildUrlArgs());

    render_dashboard_action_strip([
        [
            'label' => \__('Overview', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-availability-overview',
        ],
        [
            'label' => \__('Entries', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-availability-entries',
        ],
        [
            'label' => \__('Restriction Rule', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-availability-rule-form',
        ],
        [
            'label' => \__('Manual Block', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-manual-block-form',
        ],
    ]);
}

/**
 * @param array<string, mixed> $filters
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_availability_filters(array $filters, array $roomOptions, int $roomCount, int $entryCount): void
{
    echo '<section class="postbox must-dashboard-panel must-availability-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Filter Availability Workspace', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Narrow the workspace by accommodation, timing, entry type, rule type, and status while keeping the current availability logic unchanged.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-availability-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Listings In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $roomCount) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Entries In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $entryCount) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url(get_admin_availability_rules_page_url()) . '">' . \esc_html__('Reset Filters', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '" class="must-availability-toolbar">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-availability-rules" />';
    echo '<div class="must-availability-toolbar-grid">';

    echo '<label class="must-availability-toolbar-field is-wide"><span>' . \esc_html__('Search', 'must-hotel-booking') . '</span><input id="must-availability-filter-search" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Rule, block, or accommodation name', 'must-hotel-booking') . '" /><small>' . \esc_html__('Search rule names, manual blocks, room/listing names, and availability entry labels across the current workspace.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-availability-toolbar-field"><span>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</span><select id="must-availability-filter-room" name="room_id"><option value="0">' . \esc_html__('All room listings', 'must-hotel-booking') . '</option>';
    foreach ($roomOptions as $option) {
        if (!\is_array($option)) {
            continue;
        }

        $optionId = isset($option['id']) ? (int) $option['id'] : 0;

        if ($optionId <= 0) {
            continue;
        }

        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($filters['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-availability-toolbar-field"><span>' . \esc_html__('Status', 'must-hotel-booking') . '</span><select id="must-availability-filter-status" name="status">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'active' => __('Active only', 'must-hotel-booking'), 'inactive' => __('Inactive only', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['status'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-availability-toolbar-field"><span>' . \esc_html__('Timing', 'must-hotel-booking') . '</span><select id="must-availability-filter-timeline" name="timeline">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'current' => __('Current', 'must-hotel-booking'), 'future' => __('Current + Future', 'must-hotel-booking'), 'past' => __('Past', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['timeline'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-availability-toolbar-field"><span>' . \esc_html__('Entry type', 'must-hotel-booking') . '</span><select id="must-availability-filter-mode" name="mode">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'blocked' => __('Manual blocks', 'must-hotel-booking'), 'restriction' => __('Restrictions only', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['mode'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-availability-toolbar-field"><span>' . \esc_html__('Rule type', 'must-hotel-booking') . '</span><select id="must-availability-filter-rule-type" name="rule_type">';
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
    echo '</select></label>';

    echo '<div class="must-availability-toolbar-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Apply Filters', 'must-hotel-booking') . '</button>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_availability_rules_page_url()) . '">' . \esc_html__('Clear', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    render_availability_filter_chips($filters, $roomOptions);
    echo '</div>';
    echo '</section>';
}

function get_availability_rule_type_tone(string $ruleType): string
{
    $ruleType = \sanitize_key($ruleType);

    if (\in_array($ruleType, ['manual_block', 'maintenance_block'], true)) {
        return 'warning';
    }

    if (\in_array($ruleType, ['closed_arrival', 'closed_departure'], true)) {
        return 'error';
    }

    if (\in_array($ruleType, ['minimum_stay', 'maximum_stay'], true)) {
        return 'info';
    }

    return 'muted';
}

function get_availability_timeline_tone(string $timeline): string
{
    if ($timeline === 'current') {
        return 'ok';
    }

    if ($timeline === 'future') {
        return 'info';
    }

    return 'muted';
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function render_availability_room_overview(array $rows): void
{
    echo '<section id="must-availability-overview" class="postbox must-dashboard-panel must-availability-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Accommodation Availability Overview', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review how each room/listing is currently covered by restrictions, manual blocks, inventory, and reservation exposure.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-availability-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Listings In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '</div>';
    echo '</div>';

    if (empty($rows)) {
        render_availability_empty_state(
            \__('No room listings matched the current filters.', 'must-hotel-booking'),
            \__('Clear the current filters or open the accommodations workspace if additional room listings need to be configured first.', 'must-hotel-booking'),
            [
                [
                    'label' => \__('Reset Filters', 'must-hotel-booking'),
                    'url' => get_admin_availability_rules_page_url(),
                    'class' => 'button button-primary',
                ],
                [
                    'label' => \__('Open Room Listings', 'must-hotel-booking'),
                    'url' => \function_exists(__NAMESPACE__ . '\get_admin_rooms_page_url') ? get_admin_rooms_page_url(['tab' => 'rooms']) : get_admin_availability_rules_page_url(),
                    'class' => 'button must-dashboard-header-link',
                ],
            ]
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-availability-table-wrap">';
    echo '<table class="widefat striped must-availability-data-table must-availability-overview-table"><thead><tr>';
    echo '<th>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Rules', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Manual Blocks', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reservations', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Warnings', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $category = (string) ($row['category'] ?? '');
        $categoryLabel = $category !== '' ? RoomCatalog::getCategoryLabel($category) : '';

        echo '<tr>';
        echo '<td>';
        echo '<div class="must-availability-row-title">' . \esc_html((string) ($row['name'] ?? '')) . '</div>';
        echo '<div class="must-availability-row-meta">';
        if ($categoryLabel !== '') {
            echo '<span>' . \esc_html($categoryLabel) . '</span>';
        }
        echo '</div>';
        echo '<div class="must-availability-pill-stack">';
        echo render_availability_badge(!empty($row['is_active']) ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking'), !empty($row['is_active']) ? 'ok' : 'muted');
        echo render_availability_badge(!empty($row['is_bookable']) ? __('Bookable', 'must-hotel-booking') : __('Not bookable', 'must-hotel-booking'), !empty($row['is_bookable']) ? 'info' : 'warning');
        echo render_availability_badge(!empty($row['has_inventory']) ? __('Inventory linked', 'must-hotel-booking') : __('No inventory', 'must-hotel-booking'), !empty($row['has_inventory']) ? 'ok' : 'warning');
        echo '</div>';
        echo '</td>';

        echo '<td>';
        echo render_availability_badge(\sprintf(_n('%d scoped rule', '%d scoped rules', (int) ($row['rule_count'] ?? 0), 'must-hotel-booking'), (int) ($row['rule_count'] ?? 0)), (int) ($row['rule_count'] ?? 0) > 0 ? 'info' : 'muted');
        echo '<div class="must-availability-cell-note">' . \esc_html(\sprintf(__('Global rules visible: %d', 'must-hotel-booking'), (int) ($row['global_rule_count'] ?? 0))) . '</div>';
        echo '</td>';

        echo '<td>';
        echo render_availability_badge(\sprintf(__('Current %1$d | Future %2$d', 'must-hotel-booking'), (int) ($row['current_block_count'] ?? 0), (int) ($row['future_block_count'] ?? 0)), ((int) ($row['current_block_count'] ?? 0) + (int) ($row['future_block_count'] ?? 0)) > 0 ? 'warning' : 'muted');
        echo '<div class="must-availability-cell-note">' . \esc_html__('Manual blocks are stored as blocked reservations and directly affect sellability.', 'must-hotel-booking') . '</div>';
        echo '</td>';

        echo '<td>';
        echo render_availability_badge(\sprintf(__('Current %1$d | Future %2$d', 'must-hotel-booking'), (int) ($row['current_reservations'] ?? 0), (int) ($row['future_reservations'] ?? 0)), ((int) ($row['current_reservations'] ?? 0) + (int) ($row['future_reservations'] ?? 0)) > 0 ? 'info' : 'muted');
        if ((string) ($row['next_checkin'] ?? '') !== '') {
            echo '<div class="must-availability-cell-note">' . \esc_html(\sprintf(__('Next arrival %s', 'must-hotel-booking'), (string) $row['next_checkin'])) . '</div>';
        }
        echo '</td>';

        echo '<td>';
        if (empty($row['warnings'])) {
            echo render_availability_badge(\__('Operational', 'must-hotel-booking'), 'ok');
            echo '<div class="must-availability-cell-note">' . \esc_html__('Availability setup looks operational for this listing right now.', 'must-hotel-booking') . '</div>';
        } else {
            echo render_availability_badge(\__('Needs Review', 'must-hotel-booking'), 'warning');
            echo '<ul class="must-availability-warning-list">';
            foreach ((array) ($row['warnings'] ?? []) as $warning) {
                echo '<li>' . \esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        }
        echo '</td>';

        echo '<td><div class="must-availability-row-actions">';
        if ((string) ($row['accommodation_url'] ?? '') !== '') {
            echo '<a class="button button-small button-primary" href="' . \esc_url((string) $row['accommodation_url']) . '">' . \esc_html__('Open Accommodation', 'must-hotel-booking') . '</a>';
        }
        if ((string) ($row['calendar_url'] ?? '') !== '') {
            echo '<a class="button button-small" href="' . \esc_url((string) $row['calendar_url']) . '">' . \esc_html__('Open Calendar', 'must-hotel-booking') . '</a>';
        }
        echo '<a class="button button-small" href="' . \esc_url((string) ($row['filtered_url'] ?? '')) . '">' . \esc_html__('Filter Rules', 'must-hotel-booking') . '</a>';
        echo '</div></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function render_availability_entries_table(array $rows, AvailabilityAdminQuery $query): void
{
    $basePageUrl = get_admin_availability_rules_page_url($query->buildUrlArgs());

    echo '<section id="must-availability-entries" class="postbox must-dashboard-panel must-availability-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Availability Entries', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review restriction rules and manual blocks by scope, timing, stay impact, and current operational state.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-availability-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Entries In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url($basePageUrl . '#must-availability-rule-form') . '">' . \esc_html__('Create Rule', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    if (empty($rows)) {
        render_availability_empty_state(
            \__('No availability entries matched the current filters.', 'must-hotel-booking'),
            \__('Clear the active filters or create a new restriction rule to start shaping sellability and booking behavior again.', 'must-hotel-booking'),
            [
                [
                    'label' => \__('Create Restriction Rule', 'must-hotel-booking'),
                    'url' => $basePageUrl . '#must-availability-rule-form',
                    'class' => 'button button-primary',
                ],
                [
                    'label' => \__('Reset Filters', 'must-hotel-booking'),
                    'url' => get_admin_availability_rules_page_url(),
                    'class' => 'button must-dashboard-header-link',
                ],
            ]
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-availability-table-wrap">';
    echo '<table class="widefat striped must-availability-data-table must-availability-entry-table"><thead><tr>';
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
        $scopeIsGlobal = (int) ($row['room_id'] ?? 0) <= 0;
        $timeline = (string) ($row['timeline'] ?? 'future');
        $timelineLabels = [
            'current' => \__('Current', 'must-hotel-booking'),
            'future' => \__('Future', 'must-hotel-booking'),
            'past' => \__('Past', 'must-hotel-booking'),
        ];
        $timelineLabel = isset($timelineLabels[$timeline]) ? $timelineLabels[$timeline] : \ucfirst($timeline);
        $checkinLabel = (string) ($row['checkin'] ?? '');
        $checkoutLabel = (string) ($row['checkout'] ?? '');

        echo '<tr>';

        echo '<td>';
        echo '<div class="must-availability-row-title"><a href="' . \esc_url($editUrl) . '">' . \esc_html((string) ($row['name'] ?? '')) . '</a></div>';
        echo '<div class="must-availability-row-meta"><span>' . \esc_html($isRule ? __('Restriction', 'must-hotel-booking') : __('Manual block', 'must-hotel-booking')) . '</span></div>';
        echo '</td>';

        echo '<td>';
        echo '<div class="must-availability-row-title">' . \esc_html((string) ($row['room_name'] ?? '')) . '</div>';
        echo '<div class="must-availability-pill-stack">';
        echo render_availability_badge($scopeIsGlobal ? __('Global', 'must-hotel-booking') : __('Accommodation', 'must-hotel-booking'), $scopeIsGlobal ? 'muted' : 'info');
        echo '</div>';
        echo '</td>';

        echo '<td>' . render_availability_badge((string) ($row['rule_type_label'] ?? ''), get_availability_rule_type_tone((string) ($row['rule_type'] ?? ''))) . '</td>';
        echo '<td><div class="must-availability-cell-date">' . \esc_html((string) ($row['availability_date'] ?? '')) . '</div></td>';
        echo '<td><div class="must-availability-cell-date">' . \esc_html((string) ($row['end_date'] ?? '')) . '</div></td>';
        echo '<td><div class="must-availability-cell-date">' . \esc_html((string) ((int) ($row['minimum_stay'] ?? 0) > 0 ? (int) $row['minimum_stay'] : '')) . '</div></td>';
        echo '<td><div class="must-availability-cell-date">' . \esc_html((string) ((int) ($row['maximum_stay'] ?? 0) > 0 ? (int) $row['maximum_stay'] : '')) . '</div></td>';
        echo '<td>' . render_availability_badge($checkinLabel, $checkinLabel === __('Blocked', 'must-hotel-booking') ? 'warning' : 'muted') . '</td>';
        echo '<td>' . render_availability_badge($checkoutLabel, $checkoutLabel === __('Blocked', 'must-hotel-booking') ? 'warning' : 'muted') . '</td>';
        echo '<td>' . render_availability_badge((string) ($row['status_label'] ?? ''), !empty($row['is_active']) ? 'ok' : 'muted') . '</td>';
        echo '<td>' . render_availability_badge($timelineLabel, get_availability_timeline_tone($timeline)) . '</td>';

        echo '<td><div class="must-availability-row-actions">';
        echo '<a class="button button-small button-primary" href="' . \esc_url($editUrl) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
        echo '<a class="button button-small" href="' . \esc_url($duplicateUrl) . '">' . \esc_html__('Duplicate', 'must-hotel-booking') . '</a>';
        if ($toggleUrl !== '') {
            echo '<a class="button button-small" href="' . \esc_url($toggleUrl) . '">' . \esc_html(!empty($row['is_active']) ? __('Deactivate', 'must-hotel-booking') : __('Activate', 'must-hotel-booking')) . '</a>';
        }
        echo '<a class="must-availability-delete-link" href="' . \esc_url($deleteUrl) . '" onclick="return confirm(\'' . \esc_js(__('Delete this availability entry?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
        echo '</div></td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

function render_availability_context_panel(string $bookingNote): void
{
    echo '<section class="postbox must-dashboard-panel must-availability-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Availability Flow', 'must-hotel-booking') . '</h2><p>' . \esc_html__('A quick operational reference for how restrictions, manual blocks, inventory, and reservations interact on sellable dates.', 'must-hotel-booking') . '</p></div>';
    echo '</div>';
    echo '<div class="must-availability-context-card">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Resolution Order', 'must-hotel-booking') . '</span>';
    echo '<p>' . \esc_html($bookingNote) . '</p>';
    echo '<ul class="must-availability-context-list">';
    echo '<li><strong>' . \esc_html__('Restriction rules', 'must-hotel-booking') . '</strong><span>' . \esc_html__('Minimum stay, maximum stay, and closed arrival or departure rules shape whether guests can complete a booking flow.', 'must-hotel-booking') . '</span></li>';
    echo '<li><strong>' . \esc_html__('Manual blocks', 'must-hotel-booking') . '</strong><span>' . \esc_html__('Manual blocks are stored like blocked reservations, so they immediately remove sellability for the selected date range.', 'must-hotel-booking') . '</span></li>';
    echo '<li><strong>' . \esc_html__('Inventory and reservations', 'must-hotel-booking') . '</strong><span>' . \esc_html__('Even when rules are configured correctly, inventory coverage and live reservations still determine the final guest-facing availability outcome.', 'must-hotel-booking') . '</span></li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $form
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_availability_rule_form(array $form, array $roomOptions, AvailabilityAdminQuery $query): void
{
    $isEditMode = (int) ($form['id'] ?? 0) > 0;
    $resetUrl = get_admin_availability_rules_page_url($query->buildUrlArgs()) . '#must-availability-rule-form';

    echo '<section id="must-availability-rule-form" class="postbox must-dashboard-panel must-availability-panel must-availability-form-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html($isEditMode ? __('Edit Restriction Rule', 'must-hotel-booking') : __('Create Restriction Rule', 'must-hotel-booking')) . '</h2><p>' . \esc_html__('Configure blocked date ranges and stay restrictions without changing how the underlying availability engine behaves.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-availability-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Mode', 'must-hotel-booking') . '</strong> ' . \esc_html($isEditMode ? __('Editing existing rule', 'must-hotel-booking') : __('New blank rule', 'must-hotel-booking')) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url($resetUrl) . '">' . \esc_html__('New Blank Rule', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<form method="post" action="' . \esc_url(get_admin_availability_rules_page_url()) . '" class="must-availability-form">';
    \wp_nonce_field('must_availability_save_rule', 'must_availability_rule_nonce');
    echo '<input type="hidden" name="must_availability_action" value="save_rule" />';
    echo '<input type="hidden" name="rule_id" value="' . \esc_attr((string) ($form['id'] ?? 0)) . '" />';
    echo '<div class="must-availability-form-grid">';

    echo '<label class="must-availability-field"><span>' . \esc_html__('Rule scope', 'must-hotel-booking') . '</span><select id="must-availability-rule-room-id" name="room_id"><option value="0">' . \esc_html__('All room listings', 'must-hotel-booking') . '</option>';
    foreach ($roomOptions as $option) {
        if (!\is_array($option)) {
            continue;
        }

        $optionId = isset($option['id']) ? (int) $option['id'] : 0;

        if ($optionId <= 0) {
            continue;
        }

        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($form['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select><small>' . \esc_html__('Use a global rule to affect all accommodations, or scope it to a single listing when only one room type needs the restriction.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-availability-field"><span>' . \esc_html__('Rule name', 'must-hotel-booking') . '</span><input id="must-availability-rule-name" type="text" name="name" value="' . \esc_attr((string) ($form['name'] ?? '')) . '" required /><small>' . \esc_html__('Keep names short and operational so staff can quickly understand why the rule exists.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-availability-field"><span>' . \esc_html__('Rule type', 'must-hotel-booking') . '</span><select id="must-availability-rule-type" name="rule_type">';
    foreach ([
        'maintenance_block' => __('Blocked date range', 'must-hotel-booking'),
        'minimum_stay' => __('Minimum stay', 'must-hotel-booking'),
        'maximum_stay' => __('Maximum stay', 'must-hotel-booking'),
        'closed_arrival' => __('Closed arrival', 'must-hotel-booking'),
        'closed_departure' => __('Closed departure', 'must-hotel-booking'),
    ] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($form['rule_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-availability-field"><span>' . \esc_html__('Start date', 'must-hotel-booking') . '</span><input id="must-availability-rule-start-date" type="date" name="availability_date" value="' . \esc_attr((string) ($form['availability_date'] ?? '')) . '" required /></label>';
    echo '<label class="must-availability-field"><span>' . \esc_html__('End date', 'must-hotel-booking') . '</span><input id="must-availability-rule-end-date" type="date" name="end_date" value="' . \esc_attr((string) ($form['end_date'] ?? '')) . '" required /></label>';
    echo '<label class="must-availability-field"><span>' . \esc_html__('Stay value', 'must-hotel-booking') . '</span><input id="must-availability-rule-value" type="number" min="1" step="1" name="rule_value" value="' . \esc_attr((string) ((int) ($form['rule_value'] ?? 1))) . '" /><small>' . \esc_html__('Used only for minimum-stay and maximum-stay restrictions.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-availability-field is-full"><span>' . \esc_html__('Operational note', 'must-hotel-booking') . '</span><input id="must-availability-rule-reason" type="text" name="reason" value="' . \esc_attr((string) ($form['reason'] ?? '')) . '" /><small>' . \esc_html__('Optional internal context for staff reviewing this rule later.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-availability-checkbox">';
    echo '<input type="checkbox" name="is_active" value="1"' . \checked(!empty($form['is_active']), true, false) . ' />';
    echo '<span class="must-availability-checkbox-copy"><strong>' . \esc_html__('Rule is active', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Inactive rules remain visible for review but do not affect live availability until reactivated.', 'must-hotel-booking') . '</small></span>';
    echo '</label>';

    if (!empty($form['warnings'])) {
        echo '<div class="must-availability-form-alert">';
        echo '<h3>' . \esc_html__('Operational Summary', 'must-hotel-booking') . '</h3>';
        echo '<ul class="must-availability-warning-list">';
        foreach ((array) ($form['warnings'] ?? []) as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    echo '<div class="must-availability-form-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html($isEditMode ? __('Save Restriction Rule', 'must-hotel-booking') : __('Create Restriction Rule', 'must-hotel-booking')) . '</button>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url($resetUrl) . '">' . \esc_html__('Reset Form', 'must-hotel-booking') . '</a>';
    echo '</div>';

    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $form
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_manual_block_form(array $form, array $roomOptions, AvailabilityAdminQuery $query): void
{
    $isEditMode = (int) ($form['id'] ?? 0) > 0;
    $resetUrl = get_admin_availability_rules_page_url($query->buildUrlArgs()) . '#must-manual-block-form';

    echo '<section id="must-manual-block-form" class="postbox must-dashboard-panel must-availability-panel must-availability-form-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html($isEditMode ? __('Edit Manual Block', 'must-hotel-booking') : __('Create Manual Block', 'must-hotel-booking')) . '</h2><p>' . \esc_html__('Block a room/listing for a specific date range while keeping the existing reservation-backed blocking behavior intact.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-availability-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Mode', 'must-hotel-booking') . '</strong> ' . \esc_html($isEditMode ? __('Editing existing block', 'must-hotel-booking') : __('New manual block', 'must-hotel-booking')) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url($resetUrl) . '">' . \esc_html__('New Blank Block', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<form method="post" action="' . \esc_url(get_admin_availability_rules_page_url()) . '" class="must-availability-form">';
    \wp_nonce_field('must_availability_save_block', 'must_availability_block_nonce');
    echo '<input type="hidden" name="must_availability_action" value="save_block" />';
    echo '<input type="hidden" name="block_id" value="' . \esc_attr((string) ($form['id'] ?? 0)) . '" />';
    echo '<div class="must-availability-form-grid">';

    echo '<label class="must-availability-field"><span>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</span><select id="must-availability-block-room-id" name="room_id">';
    foreach ($roomOptions as $option) {
        if (!\is_array($option)) {
            continue;
        }

        $optionId = isset($option['id']) ? (int) $option['id'] : 0;

        if ($optionId <= 0) {
            continue;
        }

        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($form['room_id'] ?? 0), $optionId, false) . '>' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
    }
    echo '</select><small>' . \esc_html__('Choose the specific room/listing that should be unavailable during the selected dates.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-availability-field"><span>' . \esc_html__('Start date', 'must-hotel-booking') . '</span><input id="must-availability-block-checkin" type="date" name="checkin" value="' . \esc_attr((string) ($form['checkin'] ?? '')) . '" required /></label>';
    echo '<label class="must-availability-field"><span>' . \esc_html__('End date', 'must-hotel-booking') . '</span><input id="must-availability-block-checkout" type="date" name="checkout" value="' . \esc_attr((string) ($form['checkout'] ?? '')) . '" required /><small>' . \esc_html__('The end date is exclusive so it matches the existing checkout behavior used by reservations.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-availability-field is-full"><span>' . \esc_html__('Block note', 'must-hotel-booking') . '</span><textarea id="must-availability-block-notes" rows="4" name="notes">' . \esc_textarea((string) ($form['notes'] ?? '')) . '</textarea><small>' . \esc_html__('Optional internal reason for the block, maintenance work, or an operations note for staff.', 'must-hotel-booking') . '</small></label>';

    if (!empty($form['warnings'])) {
        echo '<div class="must-availability-form-alert">';
        echo '<h3>' . \esc_html__('Operational Summary', 'must-hotel-booking') . '</h3>';
        echo '<ul class="must-availability-warning-list">';
        foreach ((array) ($form['warnings'] ?? []) as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    echo '<div class="must-availability-form-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html($isEditMode ? __('Save Manual Block', 'must-hotel-booking') : __('Create Manual Block', 'must-hotel-booking')) . '</button>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url($resetUrl) . '">' . \esc_html__('Reset Form', 'must-hotel-booking') . '</a>';
    echo '</div>';

    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</section>';
}

function render_admin_availability_rules_page(): void
{
    ensure_admin_capability();

    $query = AvailabilityAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new AvailabilityAdminDataProvider())->getPageData($query, get_availability_admin_save_state());
    clear_availability_admin_save_state();

    $filters = isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [];
    $roomOptions = isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : [];
    $roomRows = isset($pageData['room_rows']) && \is_array($pageData['room_rows']) ? $pageData['room_rows'] : [];
    $entryRows = isset($pageData['entry_rows']) && \is_array($pageData['entry_rows']) ? $pageData['entry_rows'] : [];
    $ruleForm = isset($pageData['rule_form']) && \is_array($pageData['rule_form']) ? $pageData['rule_form'] : [];
    $blockForm = isset($pageData['block_form']) && \is_array($pageData['block_form']) ? $pageData['block_form'] : [];

    echo '<div class="wrap must-dashboard-page must-availability-admin">';
    render_availability_page_header($query, $pageData);
    render_availability_admin_notice_from_query();
    render_availability_error_notice(isset($pageData['rule_errors']) && \is_array($pageData['rule_errors']) ? $pageData['rule_errors'] : []);
    render_availability_error_notice(isset($pageData['block_errors']) && \is_array($pageData['block_errors']) ? $pageData['block_errors'] : []);
    render_availability_section_navigation($query);
    render_availability_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    echo '<div class="must-availability-layout">';
    echo '<div class="must-availability-main">';
    render_availability_filters($filters, $roomOptions, \count($roomRows), \count($entryRows));
    render_availability_room_overview($roomRows);
    render_availability_entries_table($entryRows, $query);
    echo '</div>';
    echo '<aside class="must-availability-sidebar">';
    render_availability_context_panel((string) ($pageData['booking_note'] ?? ''));
    render_availability_rule_form($ruleForm, $roomOptions, $query);
    render_manual_block_form($blockForm, $roomOptions, $query);
    echo '</aside>';
    echo '</div>';
    echo '</div>';
}

function enqueue_admin_availability_assets(): void
{
    $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';

    if ($page !== 'must-hotel-booking-availability-rules') {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-availability',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-availability.css',
        ['must-hotel-booking-admin-ui'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_availability_assets');
\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_availability_admin_actions_early', 1);
