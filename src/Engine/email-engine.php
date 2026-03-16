<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;

/**
 * Get plugin settings key for email templates.
 */
function get_email_templates_setting_key(): string
{
    return 'email_templates';
}

/**
 * Get available email template labels.
 *
 * @return array<string, string>
 */
function get_email_template_labels(): array
{
    return [
        'booking_confirmation' => \__('Booking confirmation', 'must-hotel-booking'),
        'admin_booking_notification' => \__('Admin notification', 'must-hotel-booking'),
        'booking_cancellation' => \__('Booking cancellation', 'must-hotel-booking'),
    ];
}

/**
 * Get supported email placeholders.
 *
 * @return array<int, string>
 */
function get_email_template_placeholders(): array
{
    return [
        '{booking_id}',
        '{guest_name}',
        '{room_name}',
        '{checkin}',
        '{checkout}',
        '{total_price}',
    ];
}

/**
 * Get default email templates.
 *
 * @return array<string, array<string, string>>
 */
function get_default_email_templates(): array
{
    return [
        'booking_confirmation' => [
            'subject' => \__('Booking Confirmation - {booking_id}', 'must-hotel-booking'),
            'body' => __("Hello {guest_name},\n\nYour booking has been confirmed.\n\nBooking ID: {booking_id}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal Price: {total_price}\n\nThank you.", 'must-hotel-booking'),
        ],
        'admin_booking_notification' => [
            'subject' => \__('New Booking Received - {booking_id}', 'must-hotel-booking'),
            'body' => __("A new booking was received.\n\nBooking ID: {booking_id}\nGuest: {guest_name}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal Price: {total_price}", 'must-hotel-booking'),
        ],
        'booking_cancellation' => [
            'subject' => \__('Booking Cancelled - {booking_id}', 'must-hotel-booking'),
            'body' => __("Booking cancellation notice.\n\nBooking ID: {booking_id}\nGuest: {guest_name}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal Price: {total_price}", 'must-hotel-booking'),
        ],
    ];
}

/**
 * Get merged email templates from settings with defaults.
 *
 * @return array<string, array<string, string>>
 */
function get_email_templates(): array
{
    $defaults = get_default_email_templates();
    $stored = [];

    if (\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
        $settings = get_plugin_settings();

        if (\is_array($settings)) {
            $settings_key = get_email_templates_setting_key();

            if (isset($settings[$settings_key]) && \is_array($settings[$settings_key])) {
                $stored = $settings[$settings_key];
            }
        }
    }

    $templates = [];

    foreach ($defaults as $template_key => $default_template) {
        $subject = isset($default_template['subject']) ? (string) $default_template['subject'] : '';
        $body = isset($default_template['body']) ? (string) $default_template['body'] : '';

        if (isset($stored[$template_key]) && \is_array($stored[$template_key])) {
            $subject_candidate = isset($stored[$template_key]['subject']) ? \sanitize_text_field((string) $stored[$template_key]['subject']) : '';
            $body_candidate = isset($stored[$template_key]['body']) ? \sanitize_textarea_field((string) $stored[$template_key]['body']) : '';

            if ($subject_candidate !== '') {
                $subject = $subject_candidate;
            }

            if ($body_candidate !== '') {
                $body = $body_candidate;
            }
        }

        $templates[$template_key] = [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    return $templates;
}

/**
 * Resolve template by key with fallback to defaults.
 *
 * @return array<string, string>
 */
function get_email_template(string $template_key): array
{
    $templates = get_email_templates();

    if (isset($templates[$template_key]) && \is_array($templates[$template_key])) {
        return $templates[$template_key];
    }

    $defaults = get_default_email_templates();

    return isset($defaults[$template_key]) && \is_array($defaults[$template_key])
        ? $defaults[$template_key]
        : ['subject' => '', 'body' => ''];
}

/**
 * Load reservation details needed for notification emails.
 *
 * @return array<string, mixed>|null
 */
function get_reservation_email_data(int $reservation_id): ?array
{
    return get_reservation_repository()->getReservationEmailData($reservation_id);
}

/**
 * Resolve booking ID value for email content.
 */
function get_reservation_email_booking_id(array $reservation): string
{
    $booking_id = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';

    if ($booking_id !== '') {
        return $booking_id;
    }

    $reservation_id = isset($reservation['id']) ? (int) $reservation['id'] : 0;

    return $reservation_id > 0 ? 'RES-' . $reservation_id : 'RES';
}

/**
 * Resolve guest full name for email content.
 */
function get_reservation_email_guest_name(array $reservation): string
{
    $guest_name = \trim(
        (string) ($reservation['first_name'] ?? '') . ' ' . (string) ($reservation['last_name'] ?? '')
    );

    return $guest_name !== '' ? $guest_name : \__('Guest', 'must-hotel-booking');
}

/**
 * Build placeholder map for a reservation.
 *
 * @return array<string, string>
 */
function get_reservation_email_placeholders(array $reservation): array
{
    $room_name = \trim((string) ($reservation['room_name'] ?? ''));

    if ($room_name === '') {
        $room_name = \__('Room', 'must-hotel-booking');
    }

    return [
        '{booking_id}' => get_reservation_email_booking_id($reservation),
        '{guest_name}' => get_reservation_email_guest_name($reservation),
        '{room_name}' => $room_name,
        '{checkin}' => (string) ($reservation['checkin'] ?? ''),
        '{checkout}' => (string) ($reservation['checkout'] ?? ''),
        '{total_price}' => \number_format_i18n((float) ($reservation['total_price'] ?? 0.0), 2),
    ];
}

/**
 * Replace placeholders in email text.
 *
 * @param array<string, string> $placeholders
 */
function replace_email_placeholders(string $content, array $placeholders): string
{
    return \strtr($content, $placeholders);
}

/**
 * Build subject/body from selected template and reservation values.
 *
 * @return array{subject: string, body: string}
 */
function build_reservation_email_content(string $template_key, array $reservation): array
{
    $template = get_email_template($template_key);
    $subject_template = isset($template['subject']) ? (string) $template['subject'] : '';
    $body_template = isset($template['body']) ? (string) $template['body'] : '';
    $placeholders = get_reservation_email_placeholders($reservation);

    return [
        'subject' => replace_email_placeholders($subject_template, $placeholders),
        'body' => replace_email_placeholders($body_template, $placeholders),
    ];
}

/**
 * Resolve notification email recipient for admins.
 */
function get_booking_admin_notification_email(): string
{
    $raw_email = '';

    if (\class_exists(MustBookingConfig::class)) {
        $raw_email = (string) MustBookingConfig::get_wp_option('admin_email', '');
    }

    $email = \sanitize_email($raw_email);

    return \is_email($email) ? $email : '';
}

/**
 * Send plain text booking email.
 */
function send_booking_email(string $recipient_email, string $subject, string $message): bool
{
    $recipient_email = \sanitize_email($recipient_email);
    $subject = \sanitize_text_field($subject);

    if (!\is_email($recipient_email) || $subject === '' || \trim($message) === '') {
        return false;
    }

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    return (bool) \wp_mail($recipient_email, $subject, $message, $headers);
}

/**
 * Send booking confirmation email to the guest.
 */
function send_guest_booking_confirmation_email(array $reservation): bool
{
    $guest_email = isset($reservation['guest_email']) ? (string) $reservation['guest_email'] : '';
    $email_content = build_reservation_email_content('booking_confirmation', $reservation);

    return send_booking_email(
        $guest_email,
        (string) ($email_content['subject'] ?? ''),
        (string) ($email_content['body'] ?? '')
    );
}

/**
 * Send new booking notification email to admin.
 */
function send_admin_new_booking_notification_email(array $reservation): bool
{
    $admin_email = get_booking_admin_notification_email();
    $email_content = build_reservation_email_content('admin_booking_notification', $reservation);

    return send_booking_email(
        $admin_email,
        (string) ($email_content['subject'] ?? ''),
        (string) ($email_content['body'] ?? '')
    );
}

/**
 * Send booking cancellation email to the guest.
 */
function send_guest_booking_cancellation_email(array $reservation): bool
{
    $guest_email = isset($reservation['guest_email']) ? (string) $reservation['guest_email'] : '';
    $email_content = build_reservation_email_content('booking_cancellation', $reservation);

    return send_booking_email(
        $guest_email,
        (string) ($email_content['subject'] ?? ''),
        (string) ($email_content['body'] ?? '')
    );
}

/**
 * Send booking cancellation notification to admin.
 */
function send_admin_booking_cancellation_email(array $reservation): bool
{
    $admin_email = get_booking_admin_notification_email();
    $email_content = build_reservation_email_content('booking_cancellation', $reservation);

    return send_booking_email(
        $admin_email,
        (string) ($email_content['subject'] ?? ''),
        (string) ($email_content['body'] ?? '')
    );
}

/**
 * Handle email notifications after reservation creation.
 */
function handle_reservation_created_email_notifications(int $reservation_id): void
{
    $reservation = get_reservation_email_data((int) $reservation_id);

    if (!\is_array($reservation)) {
        return;
    }

    $status = isset($reservation['status']) ? \sanitize_key((string) $reservation['status']) : '';

    if (\function_exists(__NAMESPACE__ . '\is_reservation_confirmed_status') && !is_reservation_confirmed_status($status)) {
        return;
    }

    send_guest_booking_confirmation_email($reservation);
    send_admin_new_booking_notification_email($reservation);
}

/**
 * Handle email notifications after reservation cancellation.
 */
function handle_reservation_cancelled_email_notifications(int $reservation_id): void
{
    $reservation = get_reservation_email_data((int) $reservation_id);

    if (!\is_array($reservation)) {
        return;
    }

    send_guest_booking_cancellation_email($reservation);
    send_admin_booking_cancellation_email($reservation);
}

/**
 * Bootstrap email notification engine.
 */
function bootstrap_email_engine(): void
{
    \add_action('must_hotel_booking/reservation_created', __NAMESPACE__ . '\handle_reservation_created_email_notifications', 10, 1);
    \add_action('must_hotel_booking/reservation_confirmed', __NAMESPACE__ . '\handle_reservation_created_email_notifications', 10, 1);
    \add_action('must_hotel_booking/reservation_cancelled', __NAMESPACE__ . '\handle_reservation_cancelled_email_notifications', 10, 1);
}

\add_action('must_hotel_booking/init', __NAMESPACE__ . '\bootstrap_email_engine');
