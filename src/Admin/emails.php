<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\EmailLayoutEngine;

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
 * @return array<string, mixed>
 */
function get_email_settings_form_defaults(): array
{
    return [
        'booking_notification_email' => MustBookingConfig::get_booking_notification_email(),
        'hotel_phone' => MustBookingConfig::get_hotel_phone(),
        'email_logo_url' => MustBookingConfig::get_email_logo_url(),
        'email_button_color' => MustBookingConfig::get_email_button_color(),
        'email_footer_text' => MustBookingConfig::get_email_footer_text(),
        'email_layout_type' => MustBookingConfig::get_email_layout_type(),
        'custom_email_layout_html' => MustBookingConfig::get_custom_email_layout_html(),
    ];
}

/**
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_email_settings_form_data(?array $submitted_form = null): array
{
    $defaults = get_email_settings_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    return $defaults;
}

/**
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_email_settings_form_values(array $source): array
{
    $defaults = get_email_settings_form_defaults();
    $booking_notification_email = isset($source['booking_notification_email']) ? \sanitize_email((string) \wp_unslash($source['booking_notification_email'])) : '';
    $hotel_phone = isset($source['hotel_phone']) ? \sanitize_text_field((string) \wp_unslash($source['hotel_phone'])) : '';
    $email_logo_url = isset($source['email_logo_url']) ? \esc_url_raw((string) \wp_unslash($source['email_logo_url'])) : '';
    $email_button_color = isset($source['email_button_color']) ? \sanitize_hex_color((string) \wp_unslash($source['email_button_color'])) : '';
    $email_footer_text = isset($source['email_footer_text']) ? \sanitize_textarea_field((string) \wp_unslash($source['email_footer_text'])) : '';
    $email_layout_type = isset($source['email_layout_type']) ? EmailLayoutEngine::normalizeLayoutType((string) \wp_unslash($source['email_layout_type'])) : EmailLayoutEngine::DEFAULT_LAYOUT_TYPE;
    $custom_email_layout_html = \array_key_exists('custom_email_layout_html', $source)
        ? (string) \wp_unslash($source['custom_email_layout_html'])
        : (string) $defaults['custom_email_layout_html'];
    $errors = [];

    if ($booking_notification_email === '' || !\is_email($booking_notification_email)) {
        $errors[] = \__('Please enter a valid booking notification email.', 'must-hotel-booking');
        $booking_notification_email = (string) $defaults['booking_notification_email'];
    }

    if (!\is_string($email_button_color) || $email_button_color === '') {
        $email_button_color = (string) $defaults['email_button_color'];
    }

    return [
        'booking_notification_email' => $booking_notification_email,
        'hotel_phone' => $hotel_phone,
        'email_logo_url' => $email_logo_url,
        'email_button_color' => $email_button_color,
        'email_footer_text' => $email_footer_text,
        'email_layout_type' => $email_layout_type,
        'custom_email_layout_html' => \str_replace(["\r\n", "\r"], "\n", $custom_email_layout_html),
        'errors' => $errors,
    ];
}

/**
 * @param array<string, mixed> $values
 */
function save_email_settings_values(array $values): void
{
    $settings = MustBookingConfig::get_all_settings();

    foreach ($values as $key => $value) {
        if ($key === 'errors') {
            continue;
        }

        $settings[$key] = $value;
    }

    MustBookingConfig::set_all_settings($settings);
}

/**
 * @param array<string, mixed> $raw_templates
 * @return array<string, array<string, string>>
 */
function sanitize_email_templates_payload(array $raw_templates): array
{
    $templates = [];

    foreach (EmailEngine::getDefaultTemplates() as $template_key => $default_template) {
        $row = isset($raw_templates[$template_key]) && \is_array($raw_templates[$template_key])
            ? $raw_templates[$template_key]
            : [];

        $templates[$template_key] = [
            'subject' => isset($row['subject']) ? \sanitize_text_field((string) \wp_unslash($row['subject'])) : '',
            'body' => isset($row['body']) ? (string) \wp_unslash($row['body']) : '',
        ];
        unset($default_template);
    }

    return $templates;
}

/**
 * @return array<int, string>
 */
function get_email_template_placeholders_for_admin(): array
{
    return EmailEngine::getTemplatePlaceholders();
}

/**
 * @return array<int, string>
 */
function get_email_layout_placeholders_for_admin(): array
{
    return EmailLayoutEngine::getSupportedLayoutPlaceholders();
}

/**
 * @return array<string, string>
 */
function get_email_layout_descriptions_for_admin(): array
{
    return [
        'classic' => \__('Neutral, clean, and standard.', 'must-hotel-booking'),
        'luxury' => \__('More premium and hotel-forward.', 'must-hotel-booking'),
        'compact' => \__('Tighter transactional layout.', 'must-hotel-booking'),
        'custom' => \__('Use your own full HTML wrapper with layout placeholders.', 'must-hotel-booking'),
    ];
}

/**
 * @param array<string, mixed> $raw_post
 * @return array<string, mixed>
 */
function process_email_admin_post_action(string $action, array $raw_post, bool $persist): array
{
    $state = [
        'notice' => '',
        'notice_type' => 'success',
        'message' => '',
        'errors' => [],
        'settings_form' => null,
        'templates' => null,
    ];

    if ($action === 'save_email_settings') {
        $nonce = isset($raw_post['must_email_settings_nonce']) ? (string) \wp_unslash($raw_post['must_email_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_email_settings_save')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $settings_form = sanitize_email_settings_form_values($raw_post);
        $state['settings_form'] = $settings_form;

        if (!empty($settings_form['errors'])) {
            $state['errors'] = (array) $settings_form['errors'];
            return $state;
        }

        if ($persist) {
            save_email_settings_values($settings_form);
        }

        $state['notice'] = 'email_settings_saved';

        return $state;
    }

    if ($action === 'save_email_templates') {
        $nonce = isset($raw_post['must_email_nonce']) ? (string) \wp_unslash($raw_post['must_email_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_email_templates_save')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $raw_templates = isset($raw_post['email_templates']) && \is_array($raw_post['email_templates']) ? $raw_post['email_templates'] : [];
        $templates = sanitize_email_templates_payload($raw_templates);
        $state['templates'] = $templates;

        if ($persist) {
            EmailEngine::saveTemplates($templates);
        }

        $state['notice'] = 'templates_saved';

        return $state;
    }

    if ($action === 'send_test_email') {
        $nonce = isset($raw_post['must_email_test_nonce']) ? (string) \wp_unslash($raw_post['must_email_test_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_email_send_test')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $template_key = isset($raw_post['template_key']) ? \sanitize_key((string) \wp_unslash($raw_post['template_key'])) : '';
        $recipient = isset($raw_post['test_recipient']) ? (string) \wp_unslash($raw_post['test_recipient']) : '';
        $result = EmailEngine::sendTestEmail($template_key, $recipient);
        $state['notice_type'] = !empty($result['success']) ? 'success' : 'error';
        $state['message'] = (string) ($result['message'] ?? '');

        return $state;
    }

    if ($action === 'send_all_test_emails') {
        $nonce = isset($raw_post['must_email_all_tests_nonce']) ? (string) \wp_unslash($raw_post['must_email_all_tests_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_email_send_all_tests')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $recipient = isset($raw_post['test_recipient']) ? (string) \wp_unslash($raw_post['test_recipient']) : '';
        $result = EmailEngine::sendAllTestEmails($recipient);
        $message = (string) ($result['message'] ?? '');

        if (!empty($result['failed'])) {
            $message .= ' ' . \implode(' ', \array_map('strval', (array) $result['failed']));
        }

        $state['notice_type'] = !empty($result['success']) ? 'success' : 'error';
        $state['message'] = $message;

        return $state;
    }

    return $state;
}

/**
 * @return array<string, mixed>
 */
function maybe_handle_email_admin_request(): array
{
    $state = [
        'notice' => '',
        'notice_type' => 'success',
        'message' => '',
        'errors' => [],
        'settings_form' => null,
        'templates' => null,
    ];
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return $state;
    }

    $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';

    if ($page !== 'must-hotel-booking-emails') {
        return $state;
    }

    $action = isset($_POST['must_email_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_email_action'])) : '';

    if ($action === '') {
        return $state;
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];

    return process_email_admin_post_action($action, $raw_post, false);
}

/**
 * Handle successful email admin actions before the page renders.
 */
function maybe_handle_email_admin_request_early(): void
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return;
    }

    $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';
    $action = isset($_POST['must_email_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_email_action'])) : '';

    if ($page !== 'must-hotel-booking-emails' || $action === '') {
        return;
    }

    ensure_admin_capability();

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $state = process_email_admin_post_action($action, $raw_post, true);

    if (!empty($state['errors'])) {
        return;
    }

    $redirect_args = [];

    if ((string) $state['notice'] !== '') {
        $redirect_args['notice'] = (string) $state['notice'];
    }

    if ((string) $state['message'] !== '') {
        $redirect_args['notice_type'] = (string) $state['notice_type'];
        $redirect_args['message'] = \rawurlencode((string) $state['message']);
    }

    if (empty($redirect_args)) {
        return;
    }

    \wp_safe_redirect(get_admin_emails_page_url($redirect_args));
    exit;
}

/**
 * Render Emails admin notices.
 */
function render_emails_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';
    $notice_type = isset($_GET['notice_type']) ? \sanitize_key((string) \wp_unslash($_GET['notice_type'])) : '';
    $message = isset($_GET['message']) ? (string) \wp_unslash($_GET['message']) : '';
    $messages = [
        'email_settings_saved' => \__('Email settings saved successfully.', 'must-hotel-booking'),
        'templates_saved' => \__('Email templates saved successfully.', 'must-hotel-booking'),
    ];

    if (isset($messages[$notice])) {
        echo '<div class="notice notice-success"><p>' . \esc_html($messages[$notice]) . '</p></div>';
    }

    if ($notice_type === '' || $message === '') {
        return;
    }

    $class = $notice_type === 'success' ? 'notice notice-success' : 'notice notice-error';
    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html(\rawurldecode($message)) . '</p></div>';
}

/**
 * Render one placeholder list.
 *
 * @param array<int, string> $placeholders
 */
function render_email_placeholder_codes(array $placeholders): void
{
    foreach ($placeholders as $index => $placeholder) {
        if ($index > 0) {
            echo ', ';
        }

        echo '<code>' . \esc_html($placeholder) . '</code>';
    }
}

/**
 * Render email templates admin page.
 */
function render_admin_emails_page(): void
{
    ensure_admin_capability();

    $state = maybe_handle_email_admin_request();
    $errors = isset($state['errors']) && \is_array($state['errors']) ? $state['errors'] : [];
    $settings_form = get_email_settings_form_data(
        isset($state['settings_form']) && \is_array($state['settings_form']) ? $state['settings_form'] : null
    );
    $templates = isset($state['templates']) && \is_array($state['templates'])
        ? $state['templates']
        : EmailEngine::getTemplates();
    $labels = EmailEngine::getTemplateLabels();
    $template_placeholders = get_email_template_placeholders_for_admin();
    $layout_placeholders = get_email_layout_placeholders_for_admin();
    $layout_type = EmailLayoutEngine::normalizeLayoutType((string) ($settings_form['email_layout_type'] ?? EmailLayoutEngine::DEFAULT_LAYOUT_TYPE));
    $layout_labels = EmailLayoutEngine::getLayoutTypeLabels();
    $layout_descriptions = get_email_layout_descriptions_for_admin();
    $show_custom_layout = $layout_type === 'custom';
    $general_settings_url = \function_exists(__NAMESPACE__ . '\get_admin_settings_page_url')
        ? get_admin_settings_page_url(['tab' => 'general'])
        : \admin_url('admin.php?page=must-hotel-booking-settings&tab=general');

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
    echo '<h2 style="margin-top:0;">' . \esc_html__('Email System', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('This page controls both the global email wrapper and the per-template inner content. Templates below are not full email pages. They are rendered inside the active layout selected here.', 'must-hotel-booking') . '</p>';
    echo '<p><strong>' . \esc_html__('Current layout:', 'must-hotel-booking') . '</strong> ' . \esc_html((string) ($layout_labels[$layout_type] ?? $layout_type)) . '</p>';
    echo '<p><strong>' . \esc_html__('Hotel name and address:', 'must-hotel-booking') . '</strong> ';
    echo \esc_html(MustBookingConfig::get_hotel_name());
    echo ' | <a href="' . \esc_url($general_settings_url) . '">' . \esc_html__('Edit in General settings', 'must-hotel-booking') . '</a></p>';
    echo '</div>';

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Global Email Settings', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('These values apply to every email template. The layout type is global, and the custom HTML wrapper is only used when Custom is selected.', 'must-hotel-booking') . '</p>';
    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '">';
    \wp_nonce_field('must_email_settings_save', 'must_email_settings_nonce');
    echo '<input type="hidden" name="must_email_action" value="save_email_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-email-booking-notification-email">' . \esc_html__('Booking notification email', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-email-booking-notification-email" type="email" class="regular-text" name="booking_notification_email" value="' . \esc_attr((string) $settings_form['booking_notification_email']) . '" required />';
    echo '<p class="description">' . \esc_html__('Admin booking emails and support contact blocks use this address.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-email-hotel-phone">' . \esc_html__('Hotel phone', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-email-hotel-phone" type="text" class="regular-text" name="hotel_phone" value="' . \esc_attr((string) $settings_form['hotel_phone']) . '" />';
    echo '<p class="description">' . \esc_html__('Used in the email support block and tel links.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-email-logo-url">' . \esc_html__('Email logo URL', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-email-logo-url" type="url" class="large-text" name="email_logo_url" value="' . \esc_attr((string) $settings_form['email_logo_url']) . '" />';
    echo '<p class="description">' . \esc_html__('Optional. If empty, the hotel name is used as the brand block.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-email-button-color">' . \esc_html__('Email button color', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-email-button-color" type="color" name="email_button_color" value="' . \esc_attr((string) $settings_form['email_button_color']) . '" />';
    echo '<p class="description">' . \esc_html__('Global CTA color for built-in layouts and the custom layout placeholder.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-email-footer-text">' . \esc_html__('Email footer text', 'must-hotel-booking') . '</label></th>';
    echo '<td><textarea id="must-email-footer-text" class="large-text" rows="3" name="email_footer_text">' . \esc_textarea((string) $settings_form['email_footer_text']) . '</textarea>';
    echo '<p class="description">' . \esc_html__('Shown in the shared footer area beneath the template body.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-email-layout-type">' . \esc_html__('Email layout type', 'must-hotel-booking') . '</label></th>';
    echo '<td><select id="must-email-layout-type" name="email_layout_type">';

    foreach ($layout_labels as $layout_key => $layout_label) {
        echo '<option value="' . \esc_attr($layout_key) . '"' . \selected($layout_type, $layout_key, false) . '>' . \esc_html($layout_label) . '</option>';
    }

    echo '</select>';
    echo '<div style="margin-top:10px;">';

    foreach ($layout_labels as $layout_key => $layout_label) {
        echo '<p style="margin:6px 0;"><strong>' . \esc_html($layout_label) . ':</strong> ' . \esc_html((string) ($layout_descriptions[$layout_key] ?? '')) . '</p>';
    }

    echo '</div></td></tr>';

    if ($show_custom_layout) {
        echo '<tr><th scope="row"><label for="must-email-custom-layout-html">' . \esc_html__('Custom email layout HTML', 'must-hotel-booking') . '</label></th>';
        echo '<td><textarea id="must-email-custom-layout-html" class="large-text code" rows="18" name="custom_email_layout_html">' . \esc_textarea((string) $settings_form['custom_email_layout_html']) . '</textarea>';
        echo '<p class="description">' . \esc_html__('Write the full HTML wrapper here. Layout placeholders are replaced when the email is rendered. If left empty, the starter custom wrapper is used.', 'must-hotel-booking') . '</p></td></tr>';
    } else {
        echo '<tr><th scope="row">' . \esc_html__('Custom email layout HTML', 'must-hotel-booking') . '</th>';
        echo '<td><p class="description">' . \esc_html__('Switch Email layout type to Custom and save once to edit the full wrapper here.', 'must-hotel-booking') . '</p></td></tr>';
    }

    echo '</tbody></table>';
    \submit_button(\__('Save Email Settings', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Placeholder Reference', 'must-hotel-booking') . '</h2>';
    echo '<h3 style="margin-bottom:8px;">' . \esc_html__('Template placeholders', 'must-hotel-booking') . '</h3>';
    echo '<p>';
    render_email_placeholder_codes($template_placeholders);
    echo '</p>';
    echo '<h3 style="margin:18px 0 8px 0;">' . \esc_html__('Layout placeholders', 'must-hotel-booking') . '</h3>';
    echo '<p>';
    render_email_placeholder_codes($layout_placeholders);
    echo '</p>';
    echo '<p class="description">' . \esc_html__('Template placeholders belong inside the template editors below. Layout placeholders belong inside the global custom wrapper.', 'must-hotel-booking') . '</p>';
    echo '</div>';

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Test Center', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Use these actions to verify the current saved templates and the current global layout without waiting for a live booking event.', 'must-hotel-booking') . '</p>';
    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '" style="margin-bottom:18px;">';
    \wp_nonce_field('must_email_send_all_tests', 'must_email_all_tests_nonce');
    echo '<input type="hidden" name="must_email_action" value="send_all_test_emails" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-email-test-recipient-all">' . \esc_html__('Send all templates to', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-email-test-recipient-all" type="email" class="regular-text" name="test_recipient" required /> ';
    \submit_button(\__('Send All Test Emails', 'must-hotel-booking'), 'secondary', '', false);
    echo '</td></tr>';
    echo '</tbody></table>';
    echo '</form>';

    echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('Template', 'must-hotel-booking') . '</th><th>' . \esc_html__('Recipient', 'must-hotel-booking') . '</th><th>' . \esc_html__('Action', 'must-hotel-booking') . '</th></tr></thead><tbody>';

    foreach ($templates as $template_key => $template) {
        unset($template);
        $form_id = 'must-email-test-form-' . $template_key;
        echo '<tr>';
        echo '<td><strong>' . \esc_html((string) ($labels[$template_key] ?? $template_key)) . '</strong><br /><code>' . \esc_html($template_key) . '</code></td>';
        echo '<td><input type="email" class="regular-text" name="test_recipient" form="' . \esc_attr($form_id) . '" required /></td>';
        echo '<td>';
        echo '<form id="' . \esc_attr($form_id) . '" method="post" action="' . \esc_url(get_admin_emails_page_url()) . '">';
        \wp_nonce_field('must_email_send_test', 'must_email_test_nonce');
        echo '<input type="hidden" name="must_email_action" value="send_test_email" />';
        echo '<input type="hidden" name="template_key" value="' . \esc_attr($template_key) . '" />';
        \submit_button(\__('Send Test', 'must-hotel-booking'), 'secondary', '', false);
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo '<form method="post" action="' . \esc_url(get_admin_emails_page_url()) . '">';
    \wp_nonce_field('must_email_templates_save', 'must_email_nonce');
    echo '<input type="hidden" name="must_email_action" value="save_email_templates" />';

    foreach ($templates as $template_key => $template) {
        $label = (string) ($labels[$template_key] ?? $template_key);
        $subject = (string) ($template['subject'] ?? '');
        $body = (string) ($template['body'] ?? '');

        echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
        echo '<h2 style="margin-top:0;">' . \esc_html($label) . '</h2>';
        echo '<p><code>' . \esc_html($template_key) . '</code></p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="must-email-subject-' . \esc_attr($template_key) . '">' . \esc_html__('Email subject', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-email-subject-' . \esc_attr($template_key) . '" type="text" class="large-text" name="email_templates[' . \esc_attr($template_key) . '][subject]" value="' . \esc_attr($subject) . '" required /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="must-email-body-' . \esc_attr($template_key) . '">' . \esc_html__('Email body', 'must-hotel-booking') . '</label></th>';
        echo '<td><textarea id="must-email-body-' . \esc_attr($template_key) . '" class="large-text code" rows="10" name="email_templates[' . \esc_attr($template_key) . '][body]" required>' . \esc_textarea($body) . '</textarea>';
        echo '<p class="description">' . \esc_html__('This is inner content only. The logo block, support block, footer, CTA, and summary rows come from the global email settings above.', 'must-hotel-booking') . '</p></td>';
        echo '</tr>';

        echo '</tbody></table>';
        echo '</div>';
    }

    \submit_button(\__('Save Email Templates', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_email_admin_request_early', 1);
