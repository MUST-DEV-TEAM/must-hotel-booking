<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\EmailLayoutEngine;

/**
 * @param array<string, scalar|int|bool> $args
 */
function get_admin_emails_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-emails');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

function is_emails_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-emails';
}

/**
 * @return array<string, mixed>
 */
function get_emails_admin_save_state(): array
{
    global $mustHotelBookingEmailsAdminSaveState;

    if (isset($mustHotelBookingEmailsAdminSaveState) && \is_array($mustHotelBookingEmailsAdminSaveState)) {
        return $mustHotelBookingEmailsAdminSaveState;
    }

    $mustHotelBookingEmailsAdminSaveState = [
        'settings_errors' => [],
        'template_errors' => [],
        'test_errors' => [],
        'settings_form' => null,
        'template_form' => null,
        'selected_template_key' => '',
    ];

    return $mustHotelBookingEmailsAdminSaveState;
}

/**
 * @param array<string, mixed> $state
 */
function set_emails_admin_save_state(array $state): void
{
    global $mustHotelBookingEmailsAdminSaveState;
    $mustHotelBookingEmailsAdminSaveState = $state;
}

function clear_emails_admin_save_state(): void
{
    set_emails_admin_save_state([
        'settings_errors' => [],
        'template_errors' => [],
        'test_errors' => [],
        'settings_form' => null,
        'template_form' => null,
        'selected_template_key' => '',
    ]);
}

function maybe_handle_email_admin_actions_early(): void
{
    if (!is_emails_admin_request()) {
        return;
    }

    ensure_admin_capability();
    $query = EmailAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod === 'POST') {
        set_emails_admin_save_state((new EmailAdminActions())->handleSaveRequest($query));
        return;
    }

    (new EmailAdminActions())->handleGetAction($query);
}

function render_emails_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';
    $noticeType = isset($_GET['notice_type']) ? \sanitize_key((string) \wp_unslash($_GET['notice_type'])) : '';
    $message = isset($_GET['message']) ? \rawurldecode((string) \wp_unslash($_GET['message'])) : '';
    $messages = [
        'email_settings_saved' => ['success', \__('Email settings saved successfully.', 'must-hotel-booking')],
        'template_saved' => ['success', \__('Email template saved successfully.', 'must-hotel-booking')],
        'template_enabled' => ['success', \__('Template enabled.', 'must-hotel-booking')],
        'template_disabled' => ['success', \__('Template disabled.', 'must-hotel-booking')],
        'template_reset' => ['success', \__('Template reset to default content.', 'must-hotel-booking')],
        'test_email_sent' => ['success', $message],
        'test_email_failed' => ['error', $message],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
        'template_update_failed' => ['error', \__('Unable to update the selected template.', 'must-hotel-booking')],
    ];

    if ($notice === '' || !isset($messages[$notice])) {
        return;
    }

    $resolvedType = $noticeType !== '' ? $noticeType : (string) $messages[$notice][0];
    $class = $resolvedType === 'success' ? 'notice notice-success' : 'notice notice-error';
    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html((string) $messages[$notice][1]) . '</p></div>';
}

/**
 * @param array<int, string> $errors
 */
function render_emails_error_notice(array $errors): void
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
function render_emails_summary_cards(array $summaryCards): void
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

function get_emails_badge_tone(string $tone): string
{
    $tone = \sanitize_key($tone);

    if (\in_array($tone, ['enabled', 'sent', 'guest', 'paid'], true)) {
        return 'ok';
    }

    if (\in_array($tone, ['warning', 'disabled', 'pay_at_hotel'], true)) {
        return 'warning';
    }

    if (\in_array($tone, ['failed', 'error'], true)) {
        return 'error';
    }

    if (\in_array($tone, ['admin', 'general', 'test'], true)) {
        return 'info';
    }

    return 'muted';
}

function render_emails_badge(string $label, string $tone = 'info'): string
{
    $resolvedTone = get_emails_badge_tone($tone);
    $classMap = [
        'ok' => 'is-ok',
        'warning' => 'is-warning',
        'error' => 'is-error',
        'info' => 'is-info',
        'muted' => 'is-muted',
    ];
    $className = isset($classMap[$resolvedTone]) ? $classMap[$resolvedTone] : $classMap['info'];

    return '<span class="must-dashboard-status-badge is-compact must-emails-badge ' . \esc_attr($className) . '">' . \esc_html($label) . '</span>';
}

/**
 * @param array<int, array<string, string>> $actions
 */
function render_emails_empty_state(string $title, string $message, array $actions = []): void
{
    echo '<div class="must-emails-empty-state">';
    echo '<div class="must-emails-empty-state-copy">';
    echo '<h3>' . \esc_html($title) . '</h3>';
    echo '<p>' . \esc_html($message) . '</p>';
    echo '</div>';

    if (!empty($actions)) {
        echo '<div class="must-emails-empty-actions">';

        foreach ($actions as $action) {
            if (!\is_array($action) || empty($action['url'])) {
                continue;
            }

            echo '<a class="' . \esc_attr((string) ($action['class'] ?? 'button')) . '" href="' . \esc_url((string) $action['url']) . '">' . \esc_html((string) ($action['label'] ?? 'Open')) . '</a>';
        }

        echo '</div>';
    }

    echo '</div>';
}

/**
 * @param array<string, string> $filters
 * @return array<int, array<string, string>>
 */
function get_emails_filter_chips(array $filters): array
{
    $chips = [];
    $audiences = [
        'guest' => \__('Guest', 'must-hotel-booking'),
        'admin' => \__('Admin', 'must-hotel-booking'),
    ];
    $flows = [
        'paid' => \__('Paid', 'must-hotel-booking'),
        'pay_at_hotel' => \__('Pay at hotel', 'must-hotel-booking'),
        'general' => \__('General', 'must-hotel-booking'),
    ];
    $states = [
        'enabled' => \__('Enabled', 'must-hotel-booking'),
        'disabled' => \__('Disabled', 'must-hotel-booking'),
    ];

    if (!empty($filters['search'])) {
        $chips[] = [
            'label' => \__('Search', 'must-hotel-booking'),
            'value' => (string) $filters['search'],
        ];
    }

    if (!empty($filters['audience']) && isset($audiences[(string) $filters['audience']])) {
        $chips[] = [
            'label' => \__('Audience', 'must-hotel-booking'),
            'value' => $audiences[(string) $filters['audience']],
        ];
    }

    if (!empty($filters['flow_type']) && isset($flows[(string) $filters['flow_type']])) {
        $chips[] = [
            'label' => \__('Flow type', 'must-hotel-booking'),
            'value' => $flows[(string) $filters['flow_type']],
        ];
    }

    if (!empty($filters['enabled']) && isset($states[(string) $filters['enabled']])) {
        $chips[] = [
            'label' => \__('State', 'must-hotel-booking'),
            'value' => $states[(string) $filters['enabled']],
        ];
    }

    return $chips;
}

/**
 * @param array<string, string> $filters
 */
function render_emails_filter_chips(array $filters): void
{
    $chips = get_emails_filter_chips($filters);

    if (empty($chips)) {
        return;
    }

    echo '<div class="must-emails-filter-chips">';

    foreach ($chips as $chip) {
        echo '<span class="must-emails-filter-chip"><strong>' . \esc_html((string) ($chip['label'] ?? '')) . '</strong> ' . \esc_html((string) ($chip['value'] ?? '')) . '</span>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_emails_page_header(EmailAdminQuery $query, array $pageData): void
{
    $rows = isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : [];
    $summaryCards = isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : [];
    $selectedTemplate = isset($pageData['selected_template']) && \is_array($pageData['selected_template']) ? $pageData['selected_template'] : null;
    $selectedLabel = \is_array($selectedTemplate) ? (string) ($selectedTemplate['label'] ?? '') : '';
    $basePageUrl = get_admin_emails_page_url($query->buildUrlArgs());

    echo '<header class="must-dashboard-hero must-emails-page-header">';
    echo '<div class="must-dashboard-hero-copy">';
    echo '<span class="must-dashboard-eyebrow">' . \esc_html__('Email Operations Workspace', 'must-hotel-booking') . '</span>';
    echo '<h1>' . \esc_html__('Emails', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage the booking emails actually used by reservation confirmations, pay-at-hotel flows, cancellations, manual resends, and test sends from one operational screen.', 'must-hotel-booking') . '</p>';
    echo '<div class="must-dashboard-hero-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Templates In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Enabled', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($summaryCards[1]['value'] ?? '0')) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Disabled', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($summaryCards[2]['value'] ?? '0')) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Recent Failures', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($summaryCards[3]['value'] ?? '0')) . '</span>';
    if ($selectedLabel !== '') {
        echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Selected Template', 'must-hotel-booking') . '</strong> ' . \esc_html($selectedLabel) . '</span>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="must-dashboard-hero-actions">';
    echo '<a class="button button-primary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_settings_page_url(['tab' => 'general'])) . '">' . \esc_html__('Open Settings', 'must-hotel-booking') . '</a>';
    echo '<a class="button must-dashboard-header-link" href="' . \esc_url($basePageUrl . '#must-email-settings') . '">' . \esc_html__('Global Email Settings', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</header>';
}

function render_emails_section_navigation(EmailAdminQuery $query, ?array $selectedTemplate, ?array $preview): void
{
    if (!\function_exists(__NAMESPACE__ . '\render_dashboard_action_strip')) {
        return;
    }

    $basePageUrl = get_admin_emails_page_url($query->buildUrlArgs());
    $actions = [
        [
            'label' => \__('Templates', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-email-templates',
        ],
        [
            'label' => \__('Editor', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-email-editor',
        ],
        [
            'label' => \__('Log', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-email-log',
        ],
        [
            'label' => \__('Settings', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-email-settings',
        ],
    ];

    if (\is_array($preview) && !empty($preview['success'])) {
        $actions[] = [
            'label' => \__('Preview', 'must-hotel-booking'),
            'url' => $basePageUrl . '#must-email-preview',
        ];
    }

    render_dashboard_action_strip($actions);
}

/**
 * @param array<string, string> $filters
 */
function render_emails_filters(array $filters, int $rowCount): void
{
    echo '<section class="postbox must-dashboard-panel must-emails-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Filter Email Workspace', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Search by template name, subject, audience, or booking-flow usage so the email workspace stays focused on the templates you need to adjust.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-emails-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Templates In View', 'must-hotel-booking') . '</strong> ' . \esc_html((string) $rowCount) . '</span>';
    echo '<a class="button must-dashboard-panel-link" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Reset Filters', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</div>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '" class="must-emails-toolbar">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-emails" />';
    echo '<div class="must-emails-toolbar-grid">';
    echo '<label class="must-emails-toolbar-field is-wide"><span>' . \esc_html__('Search', 'must-hotel-booking') . '</span><input id="must-email-filter-search" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Template name, key, or subject', 'must-hotel-booking') . '" /><small>' . \esc_html__('Search template names, keys, and email subjects across the booking communication system.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-toolbar-field"><span>' . \esc_html__('Audience', 'must-hotel-booking') . '</span><select id="must-email-filter-audience" name="audience">';
    foreach (['' => __('All audiences', 'must-hotel-booking'), 'guest' => __('Guest', 'must-hotel-booking'), 'admin' => __('Admin', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['audience'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="must-emails-toolbar-field"><span>' . \esc_html__('Flow type', 'must-hotel-booking') . '</span><select id="must-email-filter-flow" name="flow_type">';
    foreach (['' => __('All flow types', 'must-hotel-booking'), 'paid' => __('Paid', 'must-hotel-booking'), 'pay_at_hotel' => __('Pay at hotel', 'must-hotel-booking'), 'general' => __('General', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['flow_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="must-emails-toolbar-field"><span>' . \esc_html__('State', 'must-hotel-booking') . '</span><select id="must-email-filter-enabled" name="enabled">';
    foreach (['' => __('All states', 'must-hotel-booking'), 'enabled' => __('Enabled', 'must-hotel-booking'), 'disabled' => __('Disabled', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['enabled'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<div class="must-emails-toolbar-actions"><button type="submit" class="button button-primary">' . \esc_html__('Apply Filters', 'must-hotel-booking') . '</button><a class="button must-dashboard-header-link" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Clear', 'must-hotel-booking') . '</a></div>';
    echo '</div>';
    echo '</form>';
    render_emails_filter_chips($filters);
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param EmailAdminQuery $query
 */
function render_email_templates_table(array $rows, EmailAdminQuery $query): void
{
    $selectedTemplateKey = $query->getTemplateKey();

    echo '<section id="must-email-templates" class="postbox must-dashboard-panel must-emails-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Email Templates', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review booking-email coverage, enable or disable template usage, and move directly into editing from a cleaner template registry.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-emails-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Templates', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($rows)) . '</span>';
    echo '</div>';
    echo '</div>';

    if (empty($rows)) {
        render_emails_empty_state(
            \__('No email templates matched the current filters.', 'must-hotel-booking'),
            \__('Reset the email filters or broaden the current search to view the full template catalog again.',
                'must-hotel-booking'),
            [
                [
                    'label' => \__('Reset Filters', 'must-hotel-booking'),
                    'url' => get_admin_emails_page_url(),
                    'class' => 'button button-primary',
                ],
            ]
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-emails-table-wrap">';
    echo '<table class="widefat striped must-emails-data-table"><thead><tr>';
    echo '<th>' . \esc_html__('Template Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Key', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Audience', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Flow Type', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Enabled', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Subject', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Last Updated', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Last Test Sent', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $templateKey = (string) ($row['key'] ?? '');
        $editUrl = get_admin_emails_page_url($query->buildUrlArgs(['template_key' => $templateKey]));
        $toggleUrl = \wp_nonce_url(
            get_admin_emails_page_url($query->buildUrlArgs(['action' => 'toggle_template', 'template_key' => $templateKey])),
            'must_email_toggle_template_' . $templateKey
        );
        $resetUrl = \wp_nonce_url(
            get_admin_emails_page_url($query->buildUrlArgs(['action' => 'reset_template', 'template_key' => $templateKey])),
            'must_email_reset_template_' . $templateKey
        );
        $latestLog = isset($row['latest_log']) && \is_array($row['latest_log']) ? $row['latest_log'] : null;
        $isCurrent = $selectedTemplateKey !== '' && $templateKey === $selectedTemplateKey;

        echo '<tr>';
        echo '<td>';
        echo '<div class="must-emails-row-title"><a href="' . \esc_url($editUrl) . '">' . \esc_html((string) ($row['label'] ?? '')) . '</a></div>';
        echo '<div class="must-emails-pill-stack">';
        if ($isCurrent) {
            echo render_emails_badge(__('Selected', 'must-hotel-booking'), 'info');
        }
        echo render_emails_badge(!empty($row['enabled']) ? __('Enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking'), !empty($row['enabled']) ? 'enabled' : 'disabled');
        echo '</div>';
        if (!empty($row['warnings'])) {
            echo '<ul class="must-emails-warning-list">';
            foreach ((array) $row['warnings'] as $warning) {
                echo '<li>' . \esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        } elseif (\is_array($latestLog) && (string) ($latestLog['status'] ?? '') !== '') {
            echo '<div class="must-emails-cell-note">' . \esc_html((string) ($latestLog['message'] ?? '')) . '</div>';
        }
        echo '</td>';
        echo '<td><code class="must-emails-code">' . \esc_html($templateKey) . '</code></td>';
        echo '<td>' . render_emails_badge(\ucfirst((string) ($row['audience'] ?? '')), (string) ($row['audience'] ?? '')) . '</td>';
        echo '<td>' . render_emails_badge(\str_replace('_', ' ', \ucfirst((string) ($row['flow_type'] ?? 'general'))), (string) ($row['flow_type'] ?? 'general')) . '</td>';
        echo '<td>' . render_emails_badge(!empty($row['enabled']) ? __('Enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking'), !empty($row['enabled']) ? 'enabled' : 'disabled') . '</td>';
        echo '<td><div class="must-emails-cell-subject">' . \esc_html((string) ($row['subject'] ?? '')) . '</div></td>';
        echo '<td><div class="must-emails-cell-date">' . \esc_html((string) ($row['updated_at'] ?? '')) . '</div></td>';
        echo '<td>';
        if ((string) ($row['last_test_sent'] ?? '') !== '') {
            echo '<div class="must-emails-cell-date">' . \esc_html((string) ($row['last_test_sent'] ?? '')) . '</div>';
        } else {
            echo '<div class="must-emails-cell-note">' . \esc_html__('No test send recorded yet.', 'must-hotel-booking') . '</div>';
        }
        echo '</td>';
        echo '<td><div class="must-emails-row-actions">';
        echo '<a class="button button-small button-primary" href="' . \esc_url($editUrl) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
        echo '<a class="button button-small" href="' . \esc_url($toggleUrl) . '">' . \esc_html(!empty($row['enabled']) ? __('Disable', 'must-hotel-booking') : __('Enable', 'must-hotel-booking')) . '</a>';
        echo '<a class="button button-small" href="' . \esc_url($resetUrl) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
        echo '</div></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed>|null $template
 * @param array<string, string> $placeholderLabels
 */
function render_email_template_editor(?array $template, array $placeholderLabels): void
{
    echo '<section id="must-email-editor" class="postbox must-dashboard-panel must-emails-panel">';
    echo '<div class="must-dashboard-panel-inner">';

    if (!\is_array($template)) {
        echo '<div class="must-dashboard-panel-heading">';
        echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Edit Template', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Select a template from the registry above to manage its subject, body, placeholders, and test-send flow.', 'must-hotel-booking') . '</p></div>';
        echo '</div>';
        render_emails_empty_state(
            \__('No template is selected.', 'must-hotel-booking'),
            \__('Choose a template from the table above to edit its content, review placeholders, and send test emails from this workspace.'
                , 'must-hotel-booking')
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    $templateKey = (string) ($template['key'] ?? '');
    $reservationId = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
    $audience = (string) ($template['audience'] ?? '');
    $flowType = (string) ($template['flow_type'] ?? 'general');

    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Edit Template', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Update subject lines, headings, and template HTML while keeping the underlying sending logic and booking triggers unchanged.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-emails-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Key', 'must-hotel-booking') . '</strong> ' . \esc_html($templateKey) . '</span>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-emails-pill-stack">';
    echo render_emails_badge(\ucfirst($audience), $audience);
    echo render_emails_badge(\str_replace('_', ' ', \ucfirst($flowType)), $flowType);
    echo render_emails_badge(!empty($template['enabled']) ? __('Enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking'), !empty($template['enabled']) ? 'enabled' : 'disabled');
    echo '</div>';

    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '" class="must-emails-form">';
    \wp_nonce_field('must_email_template_save', 'must_email_template_nonce');
    echo '<input type="hidden" name="must_email_action" value="save_template" />';
    echo '<input type="hidden" name="template_key" value="' . \esc_attr($templateKey) . '" />';
    echo '<div class="must-emails-form-grid">';
    echo '<div class="must-emails-field is-readonly"><span>' . \esc_html__('Template key', 'must-hotel-booking') . '</span><code class="must-emails-code">' . \esc_html($templateKey) . '</code></div>';
    echo '<div class="must-emails-field is-readonly"><span>' . \esc_html__('Template label', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($template['label'] ?? '')) . '</strong></div>';
    echo '<div class="must-emails-field is-readonly"><span>' . \esc_html__('Audience / flow', 'must-hotel-booking') . '</span><strong>' . \esc_html(\ucfirst($audience) . ' | ' . \str_replace('_', ' ', $flowType)) . '</strong></div>';
    echo '<div class="must-emails-field is-readonly"><span>' . \esc_html__('Operational use', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($template['trigger'] ?? '')) . '</strong></div>';
    echo '<label class="must-emails-checkbox"><input type="checkbox" name="enabled" value="1"' . \checked(!empty($template['enabled']), true, false) . ' /><span class="must-emails-checkbox-copy"><strong>' . \esc_html__('Allow this template to send', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Disabling the template blocks this communication even if the booking flow still points to it.', 'must-hotel-booking') . '</small></span></label>';
    echo '<label class="must-emails-field is-full"><span>' . \esc_html__('Subject', 'must-hotel-booking') . '</span><input id="must-email-template-subject" type="text" name="subject" value="' . \esc_attr((string) ($template['subject'] ?? '')) . '" required /></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Heading', 'must-hotel-booking') . '</span><input id="must-email-template-heading" type="text" name="heading" value="' . \esc_attr((string) ($template['heading'] ?? '')) . '" required /></label>';
    echo '<label class="must-emails-field is-full"><span>' . \esc_html__('Body / HTML', 'must-hotel-booking') . '</span><textarea id="must-email-template-body" rows="12" name="body" required>' . \esc_textarea((string) ($template['body'] ?? '')) . '</textarea></label>';
    echo '<div class="must-emails-form-actions"><button type="submit" class="button button-primary">' . \esc_html__('Save Template', 'must-hotel-booking') . '</button></div>';
    echo '</div>';
    echo '</form>';

    echo '<div class="must-emails-subsection">';
    echo '<div class="must-emails-subsection-heading"><h3>' . \esc_html__('Placeholder Reference', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Use only supported booking placeholders so previews and live sends stay consistent.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-emails-table-wrap">';
    echo '<table class="widefat striped must-emails-data-table must-emails-detail-table"><thead><tr><th>' . \esc_html__('Placeholder', 'must-hotel-booking') . '</th><th>' . \esc_html__('Meaning', 'must-hotel-booking') . '</th></tr></thead><tbody>';
    foreach ($placeholderLabels as $placeholder => $label) {
        echo '<tr><td><code>' . \esc_html((string) $placeholder) . '</code></td><td>' . \esc_html((string) $label) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';

    echo '<div class="must-emails-subsection">';
    echo '<div class="must-emails-subsection-heading"><h3>' . \esc_html__('Send Test', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Send a live preview to a test inbox, optionally using a real reservation ID for booking data context.', 'must-hotel-booking') . '</p></div>';
    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url(['template_key' => $templateKey, 'reservation_id' => $reservationId])) . '" class="must-emails-form">';
    \wp_nonce_field('must_email_send_test', 'must_email_test_nonce');
    echo '<input type="hidden" name="must_email_action" value="send_test_email" />';
    echo '<input type="hidden" name="template_key" value="' . \esc_attr($templateKey) . '" />';
    echo '<div class="must-emails-form-grid">';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Send to', 'must-hotel-booking') . '</span><input id="must-email-test-recipient" type="email" name="test_recipient" required /></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Use reservation context', 'must-hotel-booking') . '</span><input id="must-email-preview-reservation" type="number" min="0" step="1" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" /><small>' . \esc_html__('Leave empty to send a sample preview. Add a reservation ID to render real booking data.', 'must-hotel-booking') . '</small></label>';
    echo '<div class="must-emails-form-actions"><button type="submit" class="button">' . \esc_html__('Send Test Email', 'must-hotel-booking') . '</button></div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed>|null $preview
 */
function render_email_preview_panel(?array $preview): void
{
    echo '<section id="must-email-preview" class="postbox must-dashboard-panel must-emails-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Preview', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review the rendered email output before a test send or live booking flow uses the current template.', 'must-hotel-booking') . '</p></div>';
    echo '</div>';

    if (!\is_array($preview) || empty($preview['success'])) {
        render_emails_empty_state(
            \__('No preview is available yet.', 'must-hotel-booking'),
            \__('Select a template and save or preview it with sample or reservation data to inspect the rendered email output here.', 'must-hotel-booking')
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-emails-preview-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Preview subject', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($preview['subject'] ?? '')) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Context', 'must-hotel-booking') . '</strong> ' . \esc_html(!empty($preview['is_live_context']) ? __('Reservation data', 'must-hotel-booking') : __('Sample data', 'must-hotel-booking')) . '</span>';
    echo '</div>';
    echo '<div class="must-emails-preview-frame">' . \wp_kses_post((string) ($preview['html'] ?? '')) . '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<int, array<string, mixed>> $logs
 */
function render_email_log_panel(array $logs): void
{
    echo '<section id="must-email-log" class="postbox must-dashboard-panel must-emails-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Recent Email Log', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review recent automated and test-send activity, delivery failures, and booking-related email references from the activity timeline.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-emails-panel-meta"><span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Log Rows', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($logs)) . '</span></div>';
    echo '</div>';

    if (empty($logs)) {
        render_emails_empty_state(
            \__('No recent email activity matched this view.', 'must-hotel-booking'),
            \__('Email sends and failures appear here after automated booking communication or manual test sends are written to the activity log.', 'must-hotel-booking')
        );
        echo '</div>';
        echo '</section>';

        return;
    }

    echo '<div class="must-emails-table-wrap">';
    echo '<table class="widefat striped must-emails-data-table must-emails-detail-table"><thead><tr>';
    echo '<th>' . \esc_html__('When', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Template', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Recipient', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Mode', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Message', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($logs as $log) {
        if (!\is_array($log)) {
            continue;
        }

        echo '<tr>';
        echo '<td><div class="must-emails-cell-date">' . \esc_html((string) ($log['created_at'] ?? '')) . '</div></td>';
        echo '<td><code class="must-emails-code">' . \esc_html((string) ($log['template_key'] ?? '')) . '</code></td>';
        echo '<td><div class="must-emails-row-title">' . \esc_html((string) ($log['recipient_email'] ?? '')) . '</div></td>';
        echo '<td>' . render_emails_badge((string) ($log['mode'] ?? 'automated'), (string) ($log['mode'] ?? 'automated')) . '</td>';
        echo '<td>' . render_emails_badge((string) ($log['status'] ?? 'sent'), (string) ($log['status'] ?? 'sent')) . '</td>';
        echo '<td>' . \esc_html((string) ($log['reference'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($log['message'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $settingsForm
 * @param array<int, string> $layoutPlaceholders
 */
function render_email_settings_panel(array $settingsForm, array $layoutPlaceholders): void
{
    $layoutType = EmailLayoutEngine::normalizeLayoutType((string) ($settingsForm['email_layout_type'] ?? EmailLayoutEngine::DEFAULT_LAYOUT_TYPE));
    $layoutLabels = EmailLayoutEngine::getLayoutTypeLabels();
    $layoutLabel = isset($layoutLabels[$layoutType]) ? (string) $layoutLabels[$layoutType] : $layoutType;

    echo '<section id="must-email-settings" class="postbox must-dashboard-panel must-emails-panel">';
    echo '<div class="must-dashboard-panel-inner">';
    echo '<div class="must-dashboard-panel-heading">';
    echo '<div class="must-dashboard-panel-heading-copy"><h2>' . \esc_html__('Global Email Settings', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Control the shared sender identity, hotel contact details, branding, and layout wrapper used across the booking email system.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-emails-panel-meta">';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Layout', 'must-hotel-booking') . '</strong> ' . \esc_html($layoutLabel) . '</span>';
    echo '<span class="must-dashboard-hero-pill"><strong>' . \esc_html__('Layout Placeholders', 'must-hotel-booking') . '</strong> ' . \esc_html((string) \count($layoutPlaceholders)) . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '" class="must-emails-form must-emails-settings-form">';
    \wp_nonce_field('must_email_settings_save', 'must_email_settings_nonce');
    echo '<input type="hidden" name="must_email_action" value="save_email_settings" />';
    echo '<div class="must-emails-settings-layout">';
    echo '<div class="must-emails-settings-main">';
    echo '<div class="must-emails-form-grid">';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Admin notification email', 'must-hotel-booking') . '</span><input id="must-email-booking-notification-email" type="email" name="booking_notification_email" value="' . \esc_attr((string) ($settingsForm['booking_notification_email'] ?? '')) . '" required /><small>' . \esc_html__('Booking notifications and operational alerts are sent to this internal inbox.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('From name', 'must-hotel-booking') . '</span><input id="must-email-from-name" type="text" name="email_from_name" value="' . \esc_attr((string) ($settingsForm['email_from_name'] ?? '')) . '" required /><small>' . \esc_html__('Shown to guests as the sender name on all booking emails.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('From email', 'must-hotel-booking') . '</span><input id="must-email-from-email" type="email" name="email_from_email" value="' . \esc_attr((string) ($settingsForm['email_from_email'] ?? '')) . '" required /><small>' . \esc_html__('Used as the actual sender address for outgoing email delivery.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Reply-To', 'must-hotel-booking') . '</span><input id="must-email-reply-to" type="email" name="email_reply_to" value="' . \esc_attr((string) ($settingsForm['email_reply_to'] ?? '')) . '" /><small>' . \esc_html__('Optional guest reply destination if it should differ from the sender address.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Hotel phone', 'must-hotel-booking') . '</span><input id="must-email-hotel-phone" type="text" name="hotel_phone" value="' . \esc_attr((string) ($settingsForm['hotel_phone'] ?? '')) . '" /><small>' . \esc_html__('Displayed in shared contact blocks where templates reference hotel support details.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Email logo URL', 'must-hotel-booking') . '</span><input id="must-email-logo-url" type="url" name="email_logo_url" value="' . \esc_attr((string) ($settingsForm['email_logo_url'] ?? '')) . '" /><small>' . \esc_html__('Optional hosted logo used by the shared email layout header.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Button color', 'must-hotel-booking') . '</span><span class="must-emails-color-input"><input id="must-email-button-color" type="color" name="email_button_color" value="' . \esc_attr((string) ($settingsForm['email_button_color'] ?? '')) . '" /><code class="must-emails-code">' . \esc_html((string) ($settingsForm['email_button_color'] ?? '')) . '</code></span><small>' . \esc_html__('Applied to shared call-to-action buttons inside the booking email layout.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field"><span>' . \esc_html__('Layout type', 'must-hotel-booking') . '</span><select id="must-email-layout-type" name="email_layout_type">';
    foreach ($layoutLabels as $layoutKey => $layoutLabel) {
        echo '<option value="' . \esc_attr($layoutKey) . '"' . \selected($layoutType, $layoutKey, false) . '>' . \esc_html($layoutLabel) . '</option>';
    }
    echo '</select><small>' . \esc_html__('Choose the shared wrapper style used around all template content before the email is sent.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field is-full"><span>' . \esc_html__('Footer text', 'must-hotel-booking') . '</span><textarea id="must-email-footer-text" rows="4" name="email_footer_text">' . \esc_textarea((string) ($settingsForm['email_footer_text'] ?? '')) . '</textarea><small>' . \esc_html__('Appears in the shared email footer after the main template content.', 'must-hotel-booking') . '</small></label>';
    echo '<label class="must-emails-field is-full"><span>' . \esc_html__('Custom layout HTML', 'must-hotel-booking') . '</span><textarea id="must-email-custom-layout-html" class="code" rows="12" name="custom_email_layout_html">' . \esc_textarea((string) ($settingsForm['custom_email_layout_html'] ?? '')) . '</textarea><small>' . \esc_html__('Only used when the layout type is set to Custom. Keep the registered layout placeholders intact so template content still renders correctly.', 'must-hotel-booking') . '</small></label>';
    echo '<div class="must-emails-form-actions"><button type="submit" class="button button-primary">' . \esc_html__('Save Email Settings', 'must-hotel-booking') . '</button></div>';
    echo '</div>';
    echo '</div>';
    echo '<aside class="must-emails-settings-sidebar">';
    echo '<section class="must-emails-context-card">';
    echo '<div class="must-emails-subsection-heading"><h3>' . \esc_html__('Layout Placeholder Reference', 'must-hotel-booking') . '</h3><p>' . \esc_html__('When using the custom layout, only these wrapper placeholders are supported by the shared email renderer.', 'must-hotel-booking') . '</p></div>';
    if (!empty($layoutPlaceholders)) {
        echo '<div class="must-emails-placeholder-list">';
        foreach ($layoutPlaceholders as $placeholder) {
            echo '<span class="must-emails-placeholder-chip"><code>' . \esc_html((string) $placeholder) . '</code></span>';
        }
        echo '</div>';
    } else {
        echo '<p class="must-emails-cell-note">' . \esc_html__('No shared layout placeholders are currently registered.', 'must-hotel-booking') . '</p>';
    }
    echo '</section>';
    echo '</aside>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</section>';
}

function render_admin_emails_page(): void
{
    ensure_admin_capability();

    $query = EmailAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new EmailAdminDataProvider())->getPageData($query, get_emails_admin_save_state());
    clear_emails_admin_save_state();
    $rows = isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : [];
    $selectedTemplate = isset($pageData['selected_template']) && \is_array($pageData['selected_template']) ? $pageData['selected_template'] : null;
    $preview = isset($pageData['preview']) && \is_array($pageData['preview']) ? $pageData['preview'] : null;

    echo '<div class="wrap must-dashboard-page must-emails-admin">';
    render_emails_page_header($query, $pageData);

    render_emails_admin_notice_from_query();
    render_emails_error_notice(isset($pageData['settings_errors']) && \is_array($pageData['settings_errors']) ? $pageData['settings_errors'] : []);
    render_emails_error_notice(isset($pageData['template_errors']) && \is_array($pageData['template_errors']) ? $pageData['template_errors'] : []);
    render_emails_error_notice(isset($pageData['test_errors']) && \is_array($pageData['test_errors']) ? $pageData['test_errors'] : []);
    render_emails_error_notice(isset($pageData['warnings']) && \is_array($pageData['warnings']) ? $pageData['warnings'] : []);
    render_emails_section_navigation($query, $selectedTemplate, $preview);
    render_emails_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    echo '<div class="must-emails-stack">';
    render_emails_filters(isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [], \count($rows));
    render_email_templates_table($rows, $query);
    render_email_template_editor(
        $selectedTemplate,
        isset($pageData['placeholders']) && \is_array($pageData['placeholders']) ? $pageData['placeholders'] : []
    );
    render_email_preview_panel($preview);
    render_email_log_panel(isset($pageData['logs']) && \is_array($pageData['logs']) ? $pageData['logs'] : []);
    render_email_settings_panel(
        isset($pageData['settings_form']) && \is_array($pageData['settings_form']) ? $pageData['settings_form'] : [],
        isset($pageData['layout_placeholders']) && \is_array($pageData['layout_placeholders']) ? $pageData['layout_placeholders'] : []
    );
    echo '</div>';
    echo '</div>';
}

function enqueue_admin_emails_assets(): void
{
    $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';

    if ($page !== 'must-hotel-booking-emails') {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-emails',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-emails.css',
        ['must-hotel-booking-admin-ui'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_emails_assets');
\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_email_admin_actions_early', 1);
