<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;

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

function render_pricing_badge(string $label, string $tone = 'info'): string
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

    return '<span class="must-dashboard-status-badge is-compact must-pricing-badge ' . \esc_attr($className) . '">' . \esc_html($label) . '</span>';
}

/**
 * @param array<int, array<string, string>> $actions
 */
function render_pricing_empty_state(string $title, string $message, array $actions = []): void
{
    echo '<div class="must-pricing-empty-state">';
    echo '<div class="must-pricing-empty-state-copy">';
    echo '<h3>' . \esc_html($title) . '</h3>';
    echo '<p>' . \esc_html($message) . '</p>';
    echo '</div>';

    if (!empty($actions)) {
        echo '<div class="must-pricing-empty-actions">';

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
function get_pricing_room_option_labels(array $roomOptions): array
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
function get_pricing_filter_chips(array $filters, array $roomOptions): array
{
    $chips = [];
    $roomLabels = get_pricing_room_option_labels($roomOptions);
    $maps = [
        'status' => [
            'label' => \__('Status', 'must-hotel-booking'),
            'values' => [
                'active' => \__('Active only', 'must-hotel-booking'),
                'inactive' => \__('Inactive only', 'must-hotel-booking'),
            ],
        ],
        'timeline' => [
            'label' => \__('Timeline', 'must-hotel-booking'),
            'values' => [
                'current' => \__('Current', 'must-hotel-booking'),
                'future' => \__('Current + Future', 'must-hotel-booking'),
                'past' => \__('Past', 'must-hotel-booking'),
            ],
        ],
        'setup' => [
            'label' => \__('Operational Check', 'must-hotel-booking'),
            'values' => [
                'missing_pricing' => \__('Missing pricing', 'must-hotel-booking'),
                'overlap' => \__('Overlapping rules', 'must-hotel-booking'),
                'rate_plan' => \__('Has rate plan pricing', 'must-hotel-booking'),
            ],
        ],
        'scope' => [
            'label' => \__('Rule Scope', 'must-hotel-booking'),
            'values' => [
                'global' => \__('Global rules', 'must-hotel-booking'),
                'room' => \__('Accommodation-specific', 'must-hotel-booking'),
            ],
        ],
        'rule_type' => [
            'label' => \__('Rule Type', 'must-hotel-booking'),
            'values' => [
                'nightly' => \__('Nightly override', 'must-hotel-booking'),
                'weekend' => \__('Weekend override', 'must-hotel-booking'),
                'mixed' => \__('Nightly + weekend', 'must-hotel-booking'),
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
function render_pricing_filter_chips(array $filters, array $roomOptions): void
{
    $chips = get_pricing_filter_chips($filters, $roomOptions);

    if (empty($chips)) {
        return;
    }

    echo '<div class="must-pricing-filter-chips">';

    foreach ($chips as $chip) {
        echo '<span class="must-pricing-filter-chip"><strong>' . \esc_html((string) ($chip['label'] ?? '')) . '</strong> ' . \esc_html((string) ($chip['value'] ?? '')) . '</span>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_pricing_page_header(PricingAdminQuery $query, array $pageData): void
{
    $roomRows = isset($pageData['room_rows']) && \is_array($pageData['room_rows']) ? $pageData['room_rows'] : [];
    $ruleRows = isset($pageData['rule_rows']) && \is_array($pageData['rule_rows']) ? $pageData['rule_rows'] : [];
    $summaryCards = isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : [];
    $missingPricing = isset($summaryCards[2]['value']) ? (string) $summaryCards[2]['value'] : '0';
    $overlapCount = isset($summaryCards[3]['value']) ? (string) $summaryCards[3]['value'] : '0';
    $basePageUrl = get_admin_pricing_page_url($query->buildUrlArgs());

    echo '<header class="must-dashboard-hero must-pricing-page-header">';
    echo '<div class="must-dashboard-hero-copy">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Direct Pricing Workspace', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Rates & Pricing', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage room/listing base rates and date-based direct-booking override rules in the same pricing workspace, while Rate Plans remain the advanced pricing layer for dated and package-style setups.', 'must-hotel-booking') . '</p>';
    echo '<div class="must-dashboard-hero-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Rooms In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($roomRows)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Rules In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($ruleRows)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Missing Pricing', 'must-hotel-booking') . '</strong> ' . \esc_html($missingPricing) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Rule Overlaps', 'must-hotel-booking') . '</strong> ' . \esc_html($overlapCount) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="must-dashboard-hero-actions">';
    echo '<a class="button button-primary" href="' . \esc_url($basePageUrl . '#must-pricing-rule-form') . '">' . \esc_html__('Create Pricing Rule', 'must-hotel-booking') . '</a>';

    if (\function_exists(__NAMESPACE__ . '\get_admin_rate_plans_page_url')) {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_rate_plans_page_url()) . '">' . \esc_html__('Open Rate Plans', 'must-hotel-booking') . '</a>';
    }

    if (\function_exists(__NAMESPACE__ . '\get_admin_taxes_page_url')) {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_taxes_page_url()) . '">' . \esc_html__('Open Taxes & Fees', 'must-hotel-booking') . '</a>';
    }

    if (\function_exists(__NAMESPACE__ . '\get_admin_coupons_page_url')) {
        echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_coupons_page_url()) . '">' . \esc_html__('Open Coupons', 'must-hotel-booking') . '</a>';
    }

    echo '</div>';
    echo '</header>';
}

function render_pricing_section_navigation(PricingAdminQuery $query): void
{
    if (!\function_exists(__NAMESPACE__ . '\render_dashboard_action_strip')) {
        return;
    }

    $basePageUrl = get_admin_pricing_page_url($query->buildUrlArgs());

    render_dashboard_action_strip([
        [
            'label' => \__('Base Pricing', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-pricing-base-rates',
        ],
        [
            'label' => \__('Override Rules', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-pricing-rules',
        ],
        [
            'label' => \__('Rule Editor', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-pricing-rule-form',
        ],
    ]);
}

/**
 * @param array<string, mixed> $filters
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_pricing_filters(array $filters, array $roomOptions, int $roomCount, int $ruleCount): void
{
    echo '<section class="postbox must-dashboard-panel must-pricing-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Filter Pricing Workspace', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Narrow the pricing workspace by accommodation, rule timing, setup issues, scope, and override type without changing how the pricing engine resolves totals.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-pricing-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Listings In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $roomCount) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Rules In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $ruleCount) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('Reset Filters', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '" class="must-pricing-toolbar">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-pricing" />';
    echo '<div class="must-pricing-toolbar-grid">';

    echo '<label class="must-pricing-toolbar-field is-wide"><span>' . \esc_html__('Search', 'must-hotel-booking') . '</span><input id="must-pricing-filter-search" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Rule or accommodation name', 'must-hotel-booking') . '" /><small>' . \esc_html__('Search room/listing names, override rule names, and accommodation labels across the pricing workspace.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-pricing-toolbar-field"><span>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</span><select id="must-pricing-filter-room" name="room_id"><option value="0">' . \esc_html__('All room listings', 'must-hotel-booking') . '</option>';
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

    echo '<label class="must-pricing-toolbar-field"><span>' . \esc_html__('Status', 'must-hotel-booking') . '</span><select id="must-pricing-filter-status" name="status">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'active' => __('Active only', 'must-hotel-booking'), 'inactive' => __('Inactive only', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['status'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-pricing-toolbar-field"><span>' . \esc_html__('Rule timing', 'must-hotel-booking') . '</span><select id="must-pricing-filter-timeline" name="timeline">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'current' => __('Current', 'must-hotel-booking'), 'future' => __('Current + Future', 'must-hotel-booking'), 'past' => __('Past', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['timeline'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-pricing-toolbar-field"><span>' . \esc_html__('Operational check', 'must-hotel-booking') . '</span><select id="must-pricing-filter-setup" name="setup">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'missing_pricing' => __('Missing pricing', 'must-hotel-booking'), 'overlap' => __('Overlapping rules', 'must-hotel-booking'), 'rate_plan' => __('Has rate plan pricing', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['setup'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-pricing-toolbar-field"><span>' . \esc_html__('Rule scope', 'must-hotel-booking') . '</span><select id="must-pricing-filter-scope" name="scope">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'global' => __('Global rules', 'must-hotel-booking'), 'room' => __('Accommodation-specific', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['scope'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<label class="must-pricing-toolbar-field"><span>' . \esc_html__('Rule type', 'must-hotel-booking') . '</span><select id="must-pricing-filter-rule-type" name="rule_type">';
    foreach (['all' => __('All', 'must-hotel-booking'), 'nightly' => __('Nightly override', 'must-hotel-booking'), 'weekend' => __('Weekend override', 'must-hotel-booking'), 'mixed' => __('Nightly + weekend', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['rule_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<div class="must-pricing-toolbar-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Apply Filters', 'must-hotel-booking') . '</button>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_pricing_page_url()) . '">' . \esc_html__('Clear', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    render_pricing_filter_chips($filters, $roomOptions);
    echo '</div>';
    echo '</section>';
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
    echo '<section id="must-pricing-base-rates" class="postbox must-dashboard-panel must-pricing-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Base Pricing Overview', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Direct booking pricing starts from the room/listing base price. Active rate plans can replace that base in the booking flow, and override rules below can adjust direct pricing for specific dates.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-pricing-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Listings In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '">' . \esc_html__('Open Accommodations', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    if (empty($rows)) {
        render_pricing_empty_state(
            \__('No room listings matched the current filters.', 'must-hotel-booking'),
            \__('Clear one or more filters or open the accommodations workspace if pricing needs to be assigned to additional listings first.', 'must-hotel-booking'),
            [
                [
                    'label' => \__('Reset Filters', 'must-hotel-booking'),
                    'url' => get_admin_pricing_page_url(),
                    'class' => 'button button-primary',
                ],
                [
                    'label' => \__('Open Accommodations', 'must-hotel-booking'),
                    'url' => get_admin_rooms_page_url(['tab' => 'rooms']),
                    'class' => 'button must-dashboard-header-link',
                ],
            ]
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-pricing-table-wrap">';
    echo '<table class="widefat striped must-pricing-data-table must-pricing-base-table"><thead><tr>';
    echo '<th>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Base Price', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Current Nightly Preview', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Override Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Rate Plan Pricing', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Warnings', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Quick Edit', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

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
        $category = (string) ($row['category'] ?? '');
        $categoryLabel = $category !== '' ? RoomCatalog::getCategoryLabel($category) : '';
        $overrideMeta = \sprintf(
            _n('%d active rule', '%d active rules', (int) ($row['active_rule_count'] ?? 0), 'must-hotel-booking'),
            (int) ($row['active_rule_count'] ?? 0)
        );

        if (!empty($row['current_rule']) && \is_array($row['current_rule'])) {
            $overrideDetail = \sprintf(
                \__('Live: %s', 'must-hotel-booking'),
                (string) ($row['current_rule']['name'] ?? '')
            );
        } elseif (!empty($row['next_rule']) && \is_array($row['next_rule'])) {
            $overrideDetail = \sprintf(
                \__('Next: %1$s on %2$s', 'must-hotel-booking'),
                (string) ($row['next_rule']['name'] ?? ''),
                (string) ($row['next_rule']['start_date'] ?? '')
            );
        } else {
            $overrideDetail = \__('No active override is currently scheduled.', 'must-hotel-booking');
        }

        echo '<tr>';
        echo '<td class="must-pricing-room-cell">';
        echo '<div class="must-pricing-row-title"><a href="' . \esc_url($roomUrl) . '">' . \esc_html((string) ($row['name'] ?? '')) . '</a></div>';
        echo '<div class="must-pricing-row-meta">';
        if ($categoryLabel !== '') {
            echo '<span>' . \esc_html($categoryLabel) . '</span>';
        }
        echo '</div>';
        echo '<div class="must-pricing-pill-stack">';
        echo render_pricing_badge(!empty($row['is_active']) ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking'), !empty($row['is_active']) ? 'ok' : 'muted');
        echo render_pricing_badge(!empty($row['is_bookable']) ? __('Bookable', 'must-hotel-booking') : __('Not bookable', 'must-hotel-booking'), !empty($row['is_bookable']) ? 'info' : 'warning');
        echo '</div>';
        echo '</td>';

        echo '<td><div class="must-pricing-money">' . \esc_html(\number_format_i18n((float) ($row['base_price'] ?? 0.0), 2)) . '</div><div class="must-pricing-cell-note">' . \esc_html__('Direct booking base nightly rate.', 'must-hotel-booking') . '</div></td>';
        echo '<td><div class="must-pricing-money">' . \esc_html(\number_format_i18n((float) ($row['current_nightly'] ?? 0.0), 2)) . '</div><div class="must-pricing-cell-note">' . \esc_html__('Preview for the current stay date before fees, discounts, and taxes.', 'must-hotel-booking') . '</div></td>';

        echo '<td>';
        echo render_pricing_badge($overrideMeta, (int) ($row['active_rule_count'] ?? 0) > 0 ? 'info' : 'muted');
        echo '<div class="must-pricing-cell-note">' . \esc_html($overrideDetail) . '</div>';
        echo '<div class="must-pricing-cell-links"><a class="must-dashboard-inline-link" href="' . \esc_url($pricingUrl) . '">' . \esc_html__('Filter overrides', 'must-hotel-booking') . '</a></div>';
        echo '</td>';

        echo '<td>';
        echo render_pricing_badge(
            \sprintf(_n('%d active rate plan', '%d active rate plans', (int) ($row['active_rate_plan_count'] ?? 0), 'must-hotel-booking'), (int) ($row['active_rate_plan_count'] ?? 0)),
            (int) ($row['active_rate_plan_count'] ?? 0) > 0 ? 'info' : 'muted'
        );
        echo '<div class="must-pricing-cell-note">' . \esc_html((int) ($row['active_rate_plan_count'] ?? 0) > 0 ? __('Advanced pricing is available for this listing.', 'must-hotel-booking') : __('No active rate plan pricing is assigned right now.', 'must-hotel-booking')) . '</div>';
        if ($ratePlansUrl !== '') {
            echo '<div class="must-pricing-cell-links"><a class="must-dashboard-inline-link" href="' . \esc_url($ratePlansUrl) . '">' . \esc_html__('Open advanced pricing', 'must-hotel-booking') . '</a></div>';
        }
        echo '</td>';

        echo '<td>';
        if (empty($row['warnings'])) {
            echo render_pricing_badge(\__('Pricing Ready', 'must-hotel-booking'), 'ok');
            echo '<div class="must-pricing-cell-note">' . \esc_html__('No setup warnings are currently blocking direct pricing for this listing.', 'must-hotel-booking') . '</div>';
        } else {
            echo render_pricing_badge(\__('Needs Review', 'must-hotel-booking'), 'warning');
            echo '<ul class="must-pricing-warning-list">';
            foreach ((array) ($row['warnings'] ?? []) as $warning) {
                echo '<li>' . \esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        }
        echo '</td>';

        echo '<td><form method="post" action="' . \esc_url(get_admin_pricing_page_url()) . '" class="must-pricing-inline-form">';
        \wp_nonce_field('must_pricing_save_base_price', 'must_pricing_base_nonce');
        echo '<input type="hidden" name="must_pricing_action" value="save_base_price" />';
        echo '<input type="hidden" name="room_id" value="' . \esc_attr((string) $roomId) . '" />';
        echo '<label class="screen-reader-text" for="must-pricing-base-price-' . \esc_attr((string) $roomId) . '">' . \esc_html__('Base price', 'must-hotel-booking') . '</label>';
        echo '<input id="must-pricing-base-price-' . \esc_attr((string) $roomId) . '" type="number" min="0" step="0.01" name="base_price" value="' . \esc_attr(\number_format((float) ($row['base_price'] ?? 0.0), 2, '.', '')) . '" />';
        echo '<button type="submit" class="button button-secondary">' . \esc_html__('Save', 'must-hotel-booking') . '</button>';
        echo '</form></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

function get_pricing_rule_type_tone(string $ruleTypeLabel): string
{
    $normalized = \sanitize_key(\str_replace(' ', '_', \strtolower($ruleTypeLabel)));

    if (\strpos($normalized, 'mixed') !== false) {
        return 'warning';
    }

    if (\strpos($normalized, 'nightly') !== false || \strpos($normalized, 'weekend') !== false) {
        return 'info';
    }

    return 'muted';
}

function get_pricing_timeline_tone(string $timeline): string
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
function render_pricing_rules_table(array $rows, PricingAdminQuery $query): void
{
    echo '<section id="must-pricing-rules" class="postbox must-dashboard-panel must-pricing-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Pricing Rules', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review direct-booking override rules by scope, timing, rule type, and operational state without changing the underlying pricing logic.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-pricing-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Rules In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url(get_admin_pricing_page_url($query->buildUrlArgs()) . '#must-pricing-rule-form') . '">' . \esc_html__('Create Rule', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';

    if (empty($rows)) {
        render_pricing_empty_state(
            \__('No pricing rules matched the current filters.', 'must-hotel-booking'),
            \__('Clear the current filters or create a new override rule if you need a direct-booking pricing change for a date range.', 'must-hotel-booking'),
            [
                [
                    'label' => \__('Create Pricing Rule', 'must-hotel-booking'),
                    'url' => get_admin_pricing_page_url($query->buildUrlArgs()) . '#must-pricing-rule-form',
                    'class' => 'button button-primary',
                ],
                [
                    'label' => \__('Reset Filters', 'must-hotel-booking'),
                    'url' => get_admin_pricing_page_url(),
                    'class' => 'button must-dashboard-header-link',
                ],
            ]
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-pricing-table-wrap">';
    echo '<table class="widefat striped must-pricing-data-table must-pricing-rule-table"><thead><tr>';
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

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $ruleId = isset($row['id']) ? (int) $row['id'] : 0;
        $editUrl = get_admin_pricing_page_url($query->buildUrlArgs(['action' => 'edit_rule', 'rule_id' => $ruleId])) . '#must-pricing-rule-form';
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
        echo '<td><div class="must-pricing-row-title">' . \esc_html((string) ($row['name'] ?? '')) . '</div><div class="must-pricing-cell-note">' . \esc_html(\sprintf(__('Minimum %d night stay', 'must-hotel-booking'), (int) ($row['minimum_nights'] ?? 1))) . '</div></td>';

        echo '<td>';
        echo '<div class="must-pricing-row-title">' . \esc_html((string) ($row['room_name'] ?? '')) . '</div>';
        echo '<div class="must-pricing-pill-stack">';
        echo render_pricing_badge((string) ($row['scope'] ?? '') === 'global' ? __('Global', 'must-hotel-booking') : __('Accommodation', 'must-hotel-booking'), (string) ($row['scope'] ?? '') === 'global' ? 'warning' : 'info');
        echo '</div>';
        if ((int) ($row['room_id'] ?? 0) > 0) {
            echo '<div class="must-pricing-cell-links"><a class="must-dashboard-inline-link" href="' . \esc_url($roomPricingUrl) . '">' . \esc_html__('Filter to this accommodation', 'must-hotel-booking') . '</a></div>';
        }
        echo '</td>';

        echo '<td>' . render_pricing_badge((string) ($row['rule_type_label'] ?? ''), get_pricing_rule_type_tone((string) ($row['rule_type_label'] ?? ''))) . '</td>';
        echo '<td><div class="must-pricing-cell-date">' . \esc_html((string) ($row['start_date'] ?? '')) . '</div></td>';
        echo '<td><div class="must-pricing-cell-date">' . \esc_html((string) ($row['end_date'] ?? '')) . '</div></td>';
        echo '<td><div class="must-pricing-money">' . \esc_html(\number_format_i18n((float) ($row['price_override'] ?? 0.0), 2)) . '</div></td>';
        echo '<td><div class="must-pricing-money">' . \esc_html(\number_format_i18n((float) ($row['weekend_price'] ?? 0.0), 2)) . '</div></td>';
        echo '<td><div class="must-pricing-money">' . \esc_html((string) ((int) ($row['priority'] ?? 10))) . '</div></td>';
        echo '<td>' . render_pricing_badge(!empty($row['is_active']) ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking'), !empty($row['is_active']) ? 'ok' : 'muted') . '</td>';
        echo '<td>' . render_pricing_badge((string) \ucfirst((string) ($row['timeline'] ?? 'future')), get_pricing_timeline_tone((string) ($row['timeline'] ?? 'future'))) . '</td>';

        echo '<td>';
        echo '<div class="must-pricing-row-actions">';
        echo '<a class="button button-small button-primary" href="' . \esc_url($editUrl) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
        echo '<a class="button button-small" href="' . \esc_url($duplicateUrl) . '">' . \esc_html__('Duplicate', 'must-hotel-booking') . '</a>';
        echo '<a class="button button-small" href="' . \esc_url($toggleUrl) . '">' . \esc_html(!empty($row['is_active']) ? __('Deactivate', 'must-hotel-booking') : __('Activate', 'must-hotel-booking')) . '</a>';
        echo '<a class="must-pricing-delete-link" href="' . \esc_url($deleteUrl) . '" onclick="return confirm(\'' . \esc_js(__('Delete this pricing rule?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

function render_pricing_context_panel(string $calculationNote): void
{
    echo '<section class="postbox must-dashboard-panel must-pricing-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Pricing Flow', 'must-hotel-booking') . '</h2><p>' . \esc_html__('A quick reminder of how direct-booking pricing resolves across this workspace before guests see the final total.', 'must-hotel-booking') . '</p></div>';
    echo '</div>';
    echo '<div class="must-pricing-context-card">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Calculation Order', 'must-hotel-booking') . '</span>';
    echo '<p>' . \esc_html($calculationNote) . '</p>';
    echo '</div>';
    echo '<ul class="must-pricing-context-list">';
    echo '<li><strong>' . \esc_html__('Base rates anchor direct pricing.', 'must-hotel-booking') . '</strong><span>' . \esc_html__('Each room/listing starts from its base nightly rate unless a higher-precedence layer changes that price.', 'must-hotel-booking') . '</span></li>';
    echo '<li><strong>' . \esc_html__('Override rules stay date-specific.', 'must-hotel-booking') . '</strong><span>' . \esc_html__('Use direct pricing rules to adjust nightly or weekend pricing for specific date ranges and occupancy constraints.', 'must-hotel-booking') . '</span></li>';
    echo '<li><strong>' . \esc_html__('Rate plans remain the advanced layer.', 'must-hotel-booking') . '</strong><span>' . \esc_html__('Use Rate Plans when pricing needs a more advanced calendar or package-oriented setup beyond direct override rules.', 'must-hotel-booking') . '</span></li>';
    echo '</ul>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $form
 * @param array<int, array<string, mixed>> $roomOptions
 */
function render_pricing_rule_form(array $form, array $roomOptions, PricingAdminQuery $query): void
{
    $isEditMode = (int) ($form['id'] ?? 0) > 0;

    echo '<section id="must-pricing-rule-form" class="postbox must-dashboard-panel must-pricing-panel must-pricing-rule-form-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html($isEditMode ? __('Edit Pricing Rule', 'must-hotel-booking') : __('Create Pricing Rule', 'must-hotel-booking')) . '</h2><p>' . \esc_html__('Adjust direct-booking pricing with date-based override rules, while keeping the same precedence rules and pricing engine behavior already used by the plugin.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-pricing-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Mode', 'must-hotel-booking') . '</strong> ' . \esc_html($isEditMode ? __('Editing existing rule', 'must-hotel-booking') : __('New blank rule', 'must-hotel-booking')) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url(get_admin_pricing_page_url($query->buildUrlArgs()) . '#must-pricing-rule-form') . '">' . \esc_html__('New Blank Rule', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<form method="post" action="' . \esc_url(get_admin_pricing_page_url()) . '" class="must-pricing-form">';
    \wp_nonce_field('must_pricing_save_rule', 'must_pricing_rule_nonce');
    echo '<input type="hidden" name="must_pricing_action" value="save_rule" />';
    echo '<input type="hidden" name="rule_id" value="' . \esc_attr((string) ($form['id'] ?? 0)) . '" />';
    echo '<div class="must-pricing-form-grid">';

    echo '<label class="must-pricing-field is-full"><span>' . \esc_html__('Rule scope', 'must-hotel-booking') . '</span><select id="must-pricing-rule-room-id" name="room_id"><option value="0">' . \esc_html__('All room listings', 'must-hotel-booking') . '</option>';
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
    echo '</select><small>' . \esc_html__('Room-specific rules beat global rules. Higher priority beats lower priority. If priority ties, the most specific and latest rule wins.', 'must-hotel-booking') . '</small></label>';

    echo '<label class="must-pricing-field is-full"><span>' . \esc_html__('Rule name', 'must-hotel-booking') . '</span><input id="must-pricing-rule-name" type="text" name="name" value="' . \esc_attr((string) ($form['name'] ?? '')) . '" required /></label>';
    echo '<label class="must-pricing-field"><span>' . \esc_html__('Start date', 'must-hotel-booking') . '</span><input id="must-pricing-rule-start-date" type="date" name="start_date" value="' . \esc_attr((string) ($form['start_date'] ?? '')) . '" required /></label>';
    echo '<label class="must-pricing-field"><span>' . \esc_html__('End date', 'must-hotel-booking') . '</span><input id="must-pricing-rule-end-date" type="date" name="end_date" value="' . \esc_attr((string) ($form['end_date'] ?? '')) . '" required /></label>';
    echo '<label class="must-pricing-field"><span>' . \esc_html__('Nightly override', 'must-hotel-booking') . '</span><input id="must-pricing-rule-price-override" type="number" min="0" step="0.01" name="price_override" value="' . \esc_attr(\number_format((float) ($form['price_override'] ?? 0.0), 2, '.', '')) . '" /></label>';
    echo '<label class="must-pricing-field"><span>' . \esc_html__('Weekend override', 'must-hotel-booking') . '</span><input id="must-pricing-rule-weekend-price" type="number" min="0" step="0.01" name="weekend_price" value="' . \esc_attr(\number_format((float) ($form['weekend_price'] ?? 0.0), 2, '.', '')) . '" /><small>' . \esc_html__('Applied to Friday and Saturday stays when no higher-precedence nightly override wins for that date.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-pricing-field"><span>' . \esc_html__('Minimum nights', 'must-hotel-booking') . '</span><input id="must-pricing-rule-minimum-nights" type="number" min="1" step="1" name="minimum_nights" value="' . \esc_attr((string) ((int) ($form['minimum_nights'] ?? 1))) . '" required /></label>';
    echo '<label class="must-pricing-field"><span>' . \esc_html__('Priority', 'must-hotel-booking') . '</span><input id="must-pricing-rule-priority" type="number" min="0" step="1" name="priority" value="' . \esc_attr((string) ((int) ($form['priority'] ?? 10))) . '" required /></label>';
    echo '<label class="must-pricing-checkbox"><input type="checkbox" name="is_active" value="1"' . \checked(!empty($form['is_active']), true, false) . ' /><span><strong>' . \esc_html__('Rule is active', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Inactive rules remain visible for audit and editing, but they do not affect pricing calculations.', 'must-hotel-booking') . '</small></span></label>';

    if (!empty($form['warnings'])) {
        echo '<div class="must-pricing-form-alert">';
        echo '<h3>' . \esc_html__('Operational Summary', 'must-hotel-booking') . '</h3>';
        echo '<ul class="must-pricing-warning-list">';
        foreach ((array) ($form['warnings'] ?? []) as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    echo '<div class="must-pricing-form-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html($isEditMode ? __('Save Pricing Rule', 'must-hotel-booking') : __('Create Pricing Rule', 'must-hotel-booking')) . '</button>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_pricing_page_url($query->buildUrlArgs()) . '#must-pricing-rule-form') . '">' . \esc_html__('Reset Form', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</section>';
}

function render_admin_pricing_page(): void
{
    ensure_admin_capability();

    $query = PricingAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new PricingAdminDataProvider())->getPageData($query, get_pricing_admin_save_state());
    clear_pricing_admin_save_state();
    $roomRows = isset($pageData['room_rows']) && \is_array($pageData['room_rows']) ? $pageData['room_rows'] : [];
    $ruleRows = isset($pageData['rule_rows']) && \is_array($pageData['rule_rows']) ? $pageData['rule_rows'] : [];
    $roomOptions = isset($pageData['room_options']) && \is_array($pageData['room_options']) ? $pageData['room_options'] : [];

    echo '<div class="wrap must-dashboard-page must-pricing-admin">';
    render_pricing_page_header($query, $pageData);
    render_pricing_admin_notice_from_query();
    render_pricing_error_notice(isset($pageData['base_price_errors']) && \is_array($pageData['base_price_errors']) ? $pageData['base_price_errors'] : []);
    render_pricing_error_notice(isset($pageData['rule_errors']) && \is_array($pageData['rule_errors']) ? $pageData['rule_errors'] : []);
    render_pricing_section_navigation($query);
    render_pricing_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    echo '<div class="must-pricing-layout">';
    echo '<div class="must-pricing-main">';
    render_pricing_filters(
        isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [],
        $roomOptions,
        \count($roomRows),
        \count($ruleRows)
    );
    render_pricing_base_rate_table($roomRows);
    render_pricing_rules_table($ruleRows, $query);
    echo '</div>';
    echo '<aside class="must-pricing-sidebar">';
    render_pricing_context_panel((string) ($pageData['calculation_note'] ?? ''));
    render_pricing_rule_form(
        isset($pageData['rule_form']) && \is_array($pageData['rule_form']) ? $pageData['rule_form'] : [],
        $roomOptions,
        $query
    );
    echo '</aside>';
    echo '</div>';
    echo '</div>';
}

function enqueue_admin_pricing_assets(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if ($page !== 'must-hotel-booking-pricing') {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-pricing',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-pricing.css',
        ['must-hotel-booking-admin-ui'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_pricing_admin_actions_early', 1);
\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_pricing_assets');
