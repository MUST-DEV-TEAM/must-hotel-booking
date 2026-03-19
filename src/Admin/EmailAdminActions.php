<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\EmailLayoutEngine;
use MustHotelBooking\Engine\EmailEngine;

final class EmailAdminActions
{
    /**
     * @param array<string, scalar|int|bool> $args
     */
    private function redirectToEmailsPage(array $args): void
    {
        $url = get_admin_emails_page_url($args);

        if (!\wp_safe_redirect($url)) {
            \wp_redirect($url);
        }

        exit;
    }

    public function handleGetAction(EmailAdminQuery $query): void
    {
        $action = $query->getAction();
        $templateKey = $query->getTemplateKey();

        if ($action === 'toggle_template') {
            $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

            if ($templateKey === '' || !\wp_verify_nonce($nonce, 'must_email_toggle_template_' . $templateKey)) {
                $this->redirectToEmailsPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
            }

            $templates = EmailEngine::getTemplates();
            $template = isset($templates[$templateKey]) && \is_array($templates[$templateKey]) ? $templates[$templateKey] : null;

            if ($template === null) {
                $this->redirectToEmailsPage($query->buildUrlArgs(['notice' => 'template_update_failed']));
            }

            $template['enabled'] = empty($template['enabled']) ? 1 : 0;
            $template['updated_at'] = \current_time('mysql');
            $templates[$templateKey] = $template;
            EmailEngine::saveTemplates($templates);

            $this->redirectToEmailsPage($query->buildUrlArgs([
                'notice' => !empty($template['enabled']) ? 'template_enabled' : 'template_disabled',
            ]));
        }

        if ($action === 'reset_template') {
            $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

            if ($templateKey === '' || !\wp_verify_nonce($nonce, 'must_email_reset_template_' . $templateKey)) {
                $this->redirectToEmailsPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
            }

            $reset = EmailEngine::resetTemplate($templateKey);
            $this->redirectToEmailsPage($query->buildUrlArgs([
                'notice' => $reset ? 'template_reset' : 'template_update_failed',
            ]));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function handleSaveRequest(EmailAdminQuery $query): array
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return $this->blankState();
        }

        $action = isset($_POST['must_email_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_email_action'])) : '';

        if ($action === 'save_email_settings') {
            return $this->saveSettings($query);
        }

        if ($action === 'save_template') {
            return $this->saveTemplate($query);
        }

        if ($action === 'send_test_email') {
            return $this->sendTest($query);
        }

        return $this->blankState();
    }

    /**
     * @return array<string, mixed>
     */
    private function saveSettings(EmailAdminQuery $query): array
    {
        $nonce = isset($_POST['must_email_settings_nonce']) ? (string) \wp_unslash($_POST['must_email_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_email_settings_save')) {
            return [
                'settings_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            ];
        }

        $values = [
            'booking_notification_email' => isset($_POST['booking_notification_email']) ? \sanitize_email((string) \wp_unslash($_POST['booking_notification_email'])) : '',
            'email_from_name' => isset($_POST['email_from_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['email_from_name'])) : '',
            'email_from_email' => isset($_POST['email_from_email']) ? \sanitize_email((string) \wp_unslash($_POST['email_from_email'])) : '',
            'email_reply_to' => isset($_POST['email_reply_to']) ? \sanitize_email((string) \wp_unslash($_POST['email_reply_to'])) : '',
            'hotel_phone' => isset($_POST['hotel_phone']) ? \sanitize_text_field((string) \wp_unslash($_POST['hotel_phone'])) : '',
            'email_logo_url' => isset($_POST['email_logo_url']) ? \esc_url_raw((string) \wp_unslash($_POST['email_logo_url'])) : '',
            'email_button_color' => isset($_POST['email_button_color']) ? \sanitize_hex_color((string) \wp_unslash($_POST['email_button_color'])) : '',
            'email_footer_text' => isset($_POST['email_footer_text']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['email_footer_text'])) : '',
            'email_layout_type' => isset($_POST['email_layout_type']) ? EmailLayoutEngine::normalizeLayoutType((string) \wp_unslash($_POST['email_layout_type'])) : EmailLayoutEngine::DEFAULT_LAYOUT_TYPE,
            'custom_email_layout_html' => isset($_POST['custom_email_layout_html']) ? (string) \wp_unslash($_POST['custom_email_layout_html']) : '',
        ];
        $errors = [];

        if (!\is_email($values['booking_notification_email'])) {
            $errors[] = \__('Please enter a valid booking notification email.', 'must-hotel-booking');
        }

        if (!\is_email($values['email_from_email'])) {
            $errors[] = \__('Please enter a valid email sender address.', 'must-hotel-booking');
        }

        if ($values['email_reply_to'] !== '' && !\is_email($values['email_reply_to'])) {
            $errors[] = \__('Reply-to email must be a valid email address.', 'must-hotel-booking');
        }

        if ($values['email_from_name'] === '') {
            $errors[] = \__('Email sender name is required.', 'must-hotel-booking');
        }

        if (!empty($errors)) {
            return [
                'settings_errors' => $errors,
                'settings_form' => $values,
            ];
        }

        foreach ($values as $key => $value) {
            MustBookingConfig::set_setting($key, $value);
        }

        $this->redirectToEmailsPage($query->buildUrlArgs(['notice' => 'email_settings_saved']));
    }

    /**
     * @return array<string, mixed>
     */
    private function saveTemplate(EmailAdminQuery $query): array
    {
        $nonce = isset($_POST['must_email_template_nonce']) ? (string) \wp_unslash($_POST['must_email_template_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_email_template_save')) {
            return [
                'template_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            ];
        }

        $templateKey = isset($_POST['template_key']) ? \sanitize_key((string) \wp_unslash($_POST['template_key'])) : '';
        $records = EmailEngine::getTemplateRecords();

        if (!isset($records[$templateKey])) {
            return [
                'template_errors' => [\__('Unknown email template.', 'must-hotel-booking')],
            ];
        }

        $template = [
            'enabled' => !empty($_POST['enabled']) ? 1 : 0,
            'subject' => isset($_POST['subject']) ? \sanitize_text_field((string) \wp_unslash($_POST['subject'])) : '',
            'heading' => isset($_POST['heading']) ? \sanitize_text_field((string) \wp_unslash($_POST['heading'])) : '',
            'body' => isset($_POST['body']) ? (string) \wp_unslash($_POST['body']) : '',
            'updated_at' => \current_time('mysql'),
        ];
        $errors = [];

        if ($template['subject'] === '') {
            $errors[] = \__('Template subject is required.', 'must-hotel-booking');
        }

        if ($template['heading'] === '') {
            $errors[] = \__('Template heading is required.', 'must-hotel-booking');
        }

        if (\trim($template['body']) === '') {
            $errors[] = \__('Template body is required.', 'must-hotel-booking');
        }

        if (!empty($errors)) {
            return [
                'template_errors' => $errors,
                'template_form' => \array_merge($records[$templateKey], $template),
                'selected_template_key' => $templateKey,
            ];
        }

        $templates = EmailEngine::getTemplates();
        $templates[$templateKey] = $template;
        EmailEngine::saveTemplates($templates);

        $this->redirectToEmailsPage($query->buildUrlArgs([
            'template_key' => $templateKey,
            'notice' => 'template_saved',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function sendTest(EmailAdminQuery $query): array
    {
        $nonce = isset($_POST['must_email_test_nonce']) ? (string) \wp_unslash($_POST['must_email_test_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_email_send_test')) {
            return [
                'test_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            ];
        }

        $templateKey = isset($_POST['template_key']) ? \sanitize_key((string) \wp_unslash($_POST['template_key'])) : '';
        $recipient = isset($_POST['test_recipient']) ? \sanitize_email((string) \wp_unslash($_POST['test_recipient'])) : '';
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $result = EmailEngine::sendTestEmail($templateKey, $recipient, $reservationId);

        $args = $query->buildUrlArgs([
            'template_key' => $templateKey,
            'reservation_id' => $reservationId,
            'notice' => !empty($result['success']) ? 'test_email_sent' : 'test_email_failed',
            'notice_type' => !empty($result['success']) ? 'success' : 'error',
            'message' => \rawurlencode((string) ($result['message'] ?? '')),
        ]);

        $this->redirectToEmailsPage($args);
    }

    /**
     * @return array<string, mixed>
     */
    private function blankState(): array
    {
        return [
            'settings_errors' => [],
            'template_errors' => [],
            'test_errors' => [],
            'settings_form' => null,
            'template_form' => null,
            'selected_template_key' => '',
        ];
    }
}
