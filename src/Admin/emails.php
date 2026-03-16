<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\EmailEngine;

/**
 * Build Emails admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_emails_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-emails');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Get fallback email template labels.
 *
 * @return array<string, string>
 */
function get_fallback_email_template_labels(): array
{
    return EmailEngine::getTemplateLabels();
}

/**
 * Get email template labels for the admin form.
 *
 * @return array<string, string>
 */
function get_email_template_labels_for_admin(): array
{
    return EmailEngine::getTemplateLabels();
}

/**
 * Get email template placeholders for admin display.
 *
 * @return array<int, string>
 */
function get_email_template_placeholders_for_admin(): array
{
    return \array_values(\array_map('strval', EmailEngine::getTemplatePlaceholders()));
}

/**
 * Load templates for email admin screen.
 *
 * @return array<string, array<string, string>>
 */
function get_email_templates_for_admin(): array
{
    return EmailEngine::getTemplates();
}

/**
 * Sanitize templates payload from admin request.
 *
 * @param array<string, mixed> $raw_templates
 * @return array<string, array<string, string>>
 */
function sanitize_email_templates_payload(array $raw_templates): array
{
    $existing_templates = get_email_templates_for_admin();
    $sanitized = [];

    foreach ($existing_templates as $template_key => $template_values) {
        $default_subject = isset($template_values['subject']) ? (string) $template_values['subject'] : '';
        $default_body = isset($template_values['body']) ? (string) $template_values['body'] : '';

        if (!isset($raw_templates[$template_key]) || !\is_array($raw_templates[$template_key])) {
            $sanitized[$template_key] = [
                'subject' => $default_subject,
                'body' => $default_body,
            ];

            continue;
        }

        $row = $raw_templates[$template_key];
        $subject = isset($row['subject']) ? \sanitize_text_field((string) \wp_unslash($row['subject'])) : '';
        $body = isset($row['body']) ? \sanitize_textarea_field((string) \wp_unslash($row['body'])) : '';

        if ($subject === '') {
            $subject = $default_subject;
        }

        if ($body === '') {
            $body = $default_body;
        }

        $sanitized[$template_key] = [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    return $sanitized;
}

/**
 * Handle save templates request.
 *
 * @return array<int, string>
 */
function maybe_handle_email_templates_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_email_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_email_action'])) : '';

    if ($action !== 'save_email_templates') {
        return [];
    }

    $nonce = isset($_POST['must_email_nonce']) ? (string) \wp_unslash($_POST['must_email_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_email_templates_save')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    $raw_templates = isset($_POST['email_templates']) && \is_array($_POST['email_templates']) ? $_POST['email_templates'] : [];
    $sanitized_templates = sanitize_email_templates_payload($raw_templates);
    $settings = MustBookingConfig::get_all_settings();
    $settings[EmailEngine::getTemplatesSettingKey()] = $sanitized_templates;
    MustBookingConfig::set_all_settings($settings);

    \wp_safe_redirect(get_admin_emails_page_url(['notice' => 'templates_saved']));
    exit;
}

/**
 * Render Emails admin notices.
 */
function render_emails_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice !== 'templates_saved') {
        return;
    }

    echo '<div class="notice notice-success"><p>' . \esc_html__('Email templates saved successfully.', 'must-hotel-booking') . '</p></div>';
}

/**
 * Render email templates admin page.
 */
function render_admin_emails_page(): void
{
    ensure_admin_capability();

    $errors = maybe_handle_email_templates_save_request();
    $templates = get_email_templates_for_admin();
    $labels = get_email_template_labels_for_admin();
    $placeholders = get_email_template_placeholders_for_admin();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Emails', 'must-hotel-booking') . '</h1>';

    render_emails_admin_notice_from_query();

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Supported Placeholders', 'must-hotel-booking') . '</h2>';
    echo '<p>';
    echo \esc_html(\implode(', ', $placeholders));
    echo '</p>';
    echo '</div>';

    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '">';
    \wp_nonce_field('must_email_templates_save', 'must_email_nonce');
    echo '<input type="hidden" name="must_email_action" value="save_email_templates" />';

    foreach ($templates as $template_key => $template) {
        $label = isset($labels[$template_key]) ? (string) $labels[$template_key] : $template_key;
        $subject = isset($template['subject']) ? (string) $template['subject'] : '';
        $body = isset($template['body']) ? (string) $template['body'] : '';

        echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
        echo '<h2 style="margin-top:0;">' . \esc_html($label) . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="must-email-subject-' . \esc_attr($template_key) . '">' . \esc_html__('Email subject', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-email-subject-' . \esc_attr($template_key) . '" type="text" class="large-text" name="email_templates[' . \esc_attr($template_key) . '][subject]" value="' . \esc_attr($subject) . '" required /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="must-email-body-' . \esc_attr($template_key) . '">' . \esc_html__('Email body', 'must-hotel-booking') . '</label></th>';
        echo '<td><textarea id="must-email-body-' . \esc_attr($template_key) . '" class="large-text" rows="8" name="email_templates[' . \esc_attr($template_key) . '][body]" required>' . \esc_textarea($body) . '</textarea></td>';
        echo '</tr>';

        echo '</tbody></table>';
        echo '</div>';
    }

    \submit_button(\__('Save Email Templates', 'must-hotel-booking'));

    echo '</form>';
    echo '</div>';
}
