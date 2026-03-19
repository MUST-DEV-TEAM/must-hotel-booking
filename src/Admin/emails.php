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
 * @param array<string, string> $filters
 */
function render_emails_filters(array $filters): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Filters', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-emails" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-email-filter-search">' . \esc_html__('Search', 'must-hotel-booking') . '</label></th><td><input id="must-email-filter-search" class="regular-text" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Template name, key, or subject', 'must-hotel-booking') . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-filter-audience">' . \esc_html__('Audience', 'must-hotel-booking') . '</label></th><td><select id="must-email-filter-audience" name="audience">';
    foreach (['' => __('All audiences', 'must-hotel-booking'), 'guest' => __('Guest', 'must-hotel-booking'), 'admin' => __('Admin', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['audience'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-filter-flow">' . \esc_html__('Flow type', 'must-hotel-booking') . '</label></th><td><select id="must-email-filter-flow" name="flow_type">';
    foreach (['' => __('All flow types', 'must-hotel-booking'), 'paid' => __('Paid', 'must-hotel-booking'), 'pay_at_hotel' => __('Pay at hotel', 'must-hotel-booking'), 'general' => __('General', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['flow_type'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-filter-enabled">' . \esc_html__('State', 'must-hotel-booking') . '</label></th><td><select id="must-email-filter-enabled" name="enabled">';
    foreach (['' => __('All states', 'must-hotel-booking'), 'enabled' => __('Enabled', 'must-hotel-booking'), 'disabled' => __('Disabled', 'must-hotel-booking')] as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['enabled'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';

    echo '</tbody></table>';
    \submit_button(\__('Apply Filters', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param EmailAdminQuery $query
 */
function render_email_templates_table(array $rows, EmailAdminQuery $query): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Email Templates', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
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

    if (empty($rows)) {
        echo '<tr><td colspan="9">' . \esc_html__('No email templates matched the current filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
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

            echo '<tr>';
            echo '<td><strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong>';
            if (!empty($row['warnings'])) {
                echo '<ul style="margin:8px 0 0 18px;">';
                foreach ((array) $row['warnings'] as $warning) {
                    echo '<li>' . \esc_html((string) $warning) . '</li>';
                }
                echo '</ul>';
            }
            echo '</td>';
            echo '<td><code>' . \esc_html($templateKey) . '</code></td>';
            echo '<td>' . \esc_html(\ucfirst((string) ($row['audience'] ?? ''))) . '</td>';
            echo '<td>' . \esc_html(\str_replace('_', ' ', \ucfirst((string) ($row['flow_type'] ?? 'general')))) . '</td>';
            echo '<td>' . \esc_html(!empty($row['enabled']) ? __('Enabled', 'must-hotel-booking') : __('Disabled', 'must-hotel-booking')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['subject'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['updated_at'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['last_test_sent'] ?? '')) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . \esc_url($editUrl) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url($toggleUrl) . '">' . \esc_html(!empty($row['enabled']) ? __('Disable', 'must-hotel-booking') : __('Enable', 'must-hotel-booking')) . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url($resetUrl) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<string, mixed>|null $template
 * @param array<string, string> $placeholderLabels
 */
function render_email_template_editor(?array $template, array $placeholderLabels): void
{
    if (!\is_array($template)) {
        return;
    }

    $templateKey = (string) ($template['key'] ?? '');
    $reservationId = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Edit Template', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '">';
    \wp_nonce_field('must_email_template_save', 'must_email_template_nonce');
    echo '<input type="hidden" name="must_email_action" value="save_template" />';
    echo '<input type="hidden" name="template_key" value="' . \esc_attr($templateKey) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . \esc_html__('Template key', 'must-hotel-booking') . '</th><td><code>' . \esc_html($templateKey) . '</code></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Template label', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($template['label'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Audience / flow', 'must-hotel-booking') . '</th><td>' . \esc_html(\ucfirst((string) ($template['audience'] ?? '')) . ' | ' . \str_replace('_', ' ', (string) ($template['flow_type'] ?? 'general'))) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Operational use', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($template['trigger'] ?? '')) . '</td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Enabled', 'must-hotel-booking') . '</th><td><label><input type="checkbox" name="enabled" value="1"' . \checked(!empty($template['enabled']), true, false) . ' /> ' . \esc_html__('Allow this template to send', 'must-hotel-booking') . '</label></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-template-subject">' . \esc_html__('Subject', 'must-hotel-booking') . '</label></th><td><input id="must-email-template-subject" class="large-text" type="text" name="subject" value="' . \esc_attr((string) ($template['subject'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-template-heading">' . \esc_html__('Heading', 'must-hotel-booking') . '</label></th><td><input id="must-email-template-heading" class="regular-text" type="text" name="heading" value="' . \esc_attr((string) ($template['heading'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-template-body">' . \esc_html__('Body / HTML', 'must-hotel-booking') . '</label></th><td><textarea id="must-email-template-body" class="large-text code" rows="12" name="body" required>' . \esc_textarea((string) ($template['body'] ?? '')) . '</textarea></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Save Template', 'must-hotel-booking'));
    echo '</form>';

    echo '<h3>' . \esc_html__('Placeholder Reference', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('Placeholder', 'must-hotel-booking') . '</th><th>' . \esc_html__('Meaning', 'must-hotel-booking') . '</th></tr></thead><tbody>';
    foreach ($placeholderLabels as $placeholder => $label) {
        echo '<tr><td><code>' . \esc_html((string) $placeholder) . '</code></td><td>' . \esc_html((string) $label) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<h3 style="margin-top:24px;">' . \esc_html__('Send Test', 'must-hotel-booking') . '</h3>';
    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url(['template_key' => $templateKey, 'reservation_id' => $reservationId])) . '">';
    \wp_nonce_field('must_email_send_test', 'must_email_test_nonce');
    echo '<input type="hidden" name="must_email_action" value="send_test_email" />';
    echo '<input type="hidden" name="template_key" value="' . \esc_attr($templateKey) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-email-test-recipient">' . \esc_html__('Send to', 'must-hotel-booking') . '</label></th><td><input id="must-email-test-recipient" class="regular-text" type="email" name="test_recipient" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-preview-reservation">' . \esc_html__('Use reservation context', 'must-hotel-booking') . '</label></th><td><input id="must-email-preview-reservation" class="small-text" type="number" min="0" step="1" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
    echo '<p class="description">' . \esc_html__('Leave empty to send a sample preview. Add a reservation ID to render real booking data.', 'must-hotel-booking') . '</p></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Send Test Email', 'must-hotel-booking'), 'secondary');
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<string, mixed>|null $preview
 */
function render_email_preview_panel(?array $preview): void
{
    if (!\is_array($preview) || empty($preview['success'])) {
        return;
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Preview', 'must-hotel-booking') . '</h2>';
    echo '<p><strong>' . \esc_html__('Preview subject:', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($preview['subject'] ?? '')) . '</p>';
    echo '<p><strong>' . \esc_html__('Context:', 'must-hotel-booking') . '</strong> ' . \esc_html(!empty($preview['is_live_context']) ? __('Reservation data', 'must-hotel-booking') : __('Sample data', 'must-hotel-booking')) . '</p>';
    echo '<div style="border:1px solid #dcdcde;background:#fff;padding:16px;overflow:auto;max-height:720px;">' . \wp_kses_post((string) ($preview['html'] ?? '')) . '</div>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $logs
 */
function render_email_log_panel(array $logs): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Recent Email Log', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('When', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Template', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Recipient', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Mode', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Message', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($logs)) {
        echo '<tr><td colspan="7">' . \esc_html__('No recent email activity matched this view.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($logs as $log) {
            if (!\is_array($log)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($log['created_at'] ?? '')) . '</td>';
            echo '<td><code>' . \esc_html((string) ($log['template_key'] ?? '')) . '</code></td>';
            echo '<td>' . \esc_html((string) ($log['recipient_email'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($log['mode'] ?? 'automated')) . '</td>';
            echo '<td>' . \esc_html((string) ($log['status'] ?? 'sent')) . '</td>';
            echo '<td>' . \esc_html((string) ($log['reference'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($log['message'] ?? '')) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $settingsForm
 * @param array<int, string> $layoutPlaceholders
 */
function render_email_settings_panel(array $settingsForm, array $layoutPlaceholders): void
{
    $layoutType = EmailLayoutEngine::normalizeLayoutType((string) ($settingsForm['email_layout_type'] ?? EmailLayoutEngine::DEFAULT_LAYOUT_TYPE));
    $layoutLabels = EmailLayoutEngine::getLayoutTypeLabels();

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Global Email Settings', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '">';
    \wp_nonce_field('must_email_settings_save', 'must_email_settings_nonce');
    echo '<input type="hidden" name="must_email_action" value="save_email_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-email-booking-notification-email">' . \esc_html__('Admin notification email', 'must-hotel-booking') . '</label></th><td><input id="must-email-booking-notification-email" class="regular-text" type="email" name="booking_notification_email" value="' . \esc_attr((string) ($settingsForm['booking_notification_email'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-from-name">' . \esc_html__('From name', 'must-hotel-booking') . '</label></th><td><input id="must-email-from-name" class="regular-text" type="text" name="email_from_name" value="' . \esc_attr((string) ($settingsForm['email_from_name'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-from-email">' . \esc_html__('From email', 'must-hotel-booking') . '</label></th><td><input id="must-email-from-email" class="regular-text" type="email" name="email_from_email" value="' . \esc_attr((string) ($settingsForm['email_from_email'] ?? '')) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-reply-to">' . \esc_html__('Reply-To', 'must-hotel-booking') . '</label></th><td><input id="must-email-reply-to" class="regular-text" type="email" name="email_reply_to" value="' . \esc_attr((string) ($settingsForm['email_reply_to'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-hotel-phone">' . \esc_html__('Hotel phone', 'must-hotel-booking') . '</label></th><td><input id="must-email-hotel-phone" class="regular-text" type="text" name="hotel_phone" value="' . \esc_attr((string) ($settingsForm['hotel_phone'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-logo-url">' . \esc_html__('Email logo URL', 'must-hotel-booking') . '</label></th><td><input id="must-email-logo-url" class="large-text" type="url" name="email_logo_url" value="' . \esc_attr((string) ($settingsForm['email_logo_url'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-button-color">' . \esc_html__('Button color', 'must-hotel-booking') . '</label></th><td><input id="must-email-button-color" type="color" name="email_button_color" value="' . \esc_attr((string) ($settingsForm['email_button_color'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-footer-text">' . \esc_html__('Footer text', 'must-hotel-booking') . '</label></th><td><textarea id="must-email-footer-text" class="large-text" rows="3" name="email_footer_text">' . \esc_textarea((string) ($settingsForm['email_footer_text'] ?? '')) . '</textarea></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-layout-type">' . \esc_html__('Layout type', 'must-hotel-booking') . '</label></th><td><select id="must-email-layout-type" name="email_layout_type">';
    foreach ($layoutLabels as $layoutKey => $layoutLabel) {
        echo '<option value="' . \esc_attr($layoutKey) . '"' . \selected($layoutType, $layoutKey, false) . '>' . \esc_html($layoutLabel) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-email-custom-layout-html">' . \esc_html__('Custom layout HTML', 'must-hotel-booking') . '</label></th><td><textarea id="must-email-custom-layout-html" class="large-text code" rows="12" name="custom_email_layout_html">' . \esc_textarea((string) ($settingsForm['custom_email_layout_html'] ?? '')) . '</textarea>';
    echo '<p class="description">' . \esc_html__('Used when layout type is Custom. Supported layout placeholders:', 'must-hotel-booking') . ' ';
    foreach ($layoutPlaceholders as $index => $placeholder) {
        if ($index > 0) {
            echo ', ';
        }
        echo '<code>' . \esc_html((string) $placeholder) . '</code>';
    }
    echo '</p></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Save Email Settings', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';
}

function render_admin_emails_page(): void
{
    ensure_admin_capability();

    $query = EmailAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new EmailAdminDataProvider())->getPageData($query, get_emails_admin_save_state());
    clear_emails_admin_save_state();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Emails', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage the booking emails actually used by reservation confirmations, pay-at-hotel flows, cancellations, manual resends, and test sends from one operational screen.', 'must-hotel-booking') . '</p>';
    echo '<p><a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url()) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_settings_page_url(['tab' => 'general'])) . '">' . \esc_html__('Open Settings', 'must-hotel-booking') . '</a></p>';

    render_emails_admin_notice_from_query();
    render_emails_error_notice(isset($pageData['settings_errors']) && \is_array($pageData['settings_errors']) ? $pageData['settings_errors'] : []);
    render_emails_error_notice(isset($pageData['template_errors']) && \is_array($pageData['template_errors']) ? $pageData['template_errors'] : []);
    render_emails_error_notice(isset($pageData['test_errors']) && \is_array($pageData['test_errors']) ? $pageData['test_errors'] : []);
    render_emails_error_notice(isset($pageData['warnings']) && \is_array($pageData['warnings']) ? $pageData['warnings'] : []);
    render_emails_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    render_emails_filters(isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : []);
    render_email_templates_table(isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : [], $query);
    render_email_template_editor(
        isset($pageData['selected_template']) && \is_array($pageData['selected_template']) ? $pageData['selected_template'] : null,
        isset($pageData['placeholders']) && \is_array($pageData['placeholders']) ? $pageData['placeholders'] : []
    );
    render_email_preview_panel(isset($pageData['preview']) && \is_array($pageData['preview']) ? $pageData['preview'] : null);
    render_email_log_panel(isset($pageData['logs']) && \is_array($pageData['logs']) ? $pageData['logs'] : []);
    render_email_settings_panel(
        isset($pageData['settings_form']) && \is_array($pageData['settings_form']) ? $pageData['settings_form'] : [],
        isset($pageData['layout_placeholders']) && \is_array($pageData['layout_placeholders']) ? $pageData['layout_placeholders'] : []
    );
    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_email_admin_actions_early', 1);
