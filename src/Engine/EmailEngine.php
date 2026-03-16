<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\ReservationStatus;

final class EmailEngine
{
    public static function getTemplatesSettingKey(): string
    {
        return 'email_templates';
    }

    /**
     * @return array<string, string>
     */
    public static function getTemplateLabels(): array
    {
        return [
            'booking_confirmation' => \__('Booking confirmation', 'must-hotel-booking'),
            'admin_booking_notification' => \__('Admin notification', 'must-hotel-booking'),
            'booking_cancellation' => \__('Booking cancellation', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getTemplatePlaceholders(): array
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
     * @return array<string, array<string, string>>
     */
    public static function getDefaultTemplates(): array
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
     * @return array<string, array<string, string>>
     */
    public static function getTemplates(): array
    {
        $defaults = self::getDefaultTemplates();
        $settings = MustBookingConfig::get_all_settings();
        $stored = isset($settings[self::getTemplatesSettingKey()]) && \is_array($settings[self::getTemplatesSettingKey()])
            ? $settings[self::getTemplatesSettingKey()]
            : [];
        $templates = [];

        foreach ($defaults as $templateKey => $defaultTemplate) {
            $subject = isset($defaultTemplate['subject']) ? (string) $defaultTemplate['subject'] : '';
            $body = isset($defaultTemplate['body']) ? (string) $defaultTemplate['body'] : '';

            if (isset($stored[$templateKey]) && \is_array($stored[$templateKey])) {
                $subjectCandidate = isset($stored[$templateKey]['subject']) ? \sanitize_text_field((string) $stored[$templateKey]['subject']) : '';
                $bodyCandidate = isset($stored[$templateKey]['body']) ? \sanitize_textarea_field((string) $stored[$templateKey]['body']) : '';

                if ($subjectCandidate !== '') {
                    $subject = $subjectCandidate;
                }

                if ($bodyCandidate !== '') {
                    $body = $bodyCandidate;
                }
            }

            $templates[$templateKey] = [
                'subject' => $subject,
                'body' => $body,
            ];
        }

        return $templates;
    }

    /**
     * @return array<string, string>
     */
    public static function getTemplate(string $templateKey): array
    {
        $templates = self::getTemplates();

        if (isset($templates[$templateKey]) && \is_array($templates[$templateKey])) {
            return $templates[$templateKey];
        }

        $defaults = self::getDefaultTemplates();

        return isset($defaults[$templateKey]) && \is_array($defaults[$templateKey])
            ? $defaults[$templateKey]
            : ['subject' => '', 'body' => ''];
    }

    public static function registerHooks(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        \add_action('must_hotel_booking/reservation_created', [self::class, 'handleReservationCreatedNotifications'], 10, 1);
        \add_action('must_hotel_booking/reservation_confirmed', [self::class, 'handleReservationCreatedNotifications'], 10, 1);
        \add_action('must_hotel_booking/reservation_cancelled', [self::class, 'handleReservationCancelledNotifications'], 10, 1);
        $registered = true;
    }

    public static function handleReservationCreatedNotifications(int $reservationId): void
    {
        $reservation = self::getReservationEmailData((int) $reservationId);

        if (!\is_array($reservation)) {
            return;
        }

        $status = isset($reservation['status']) ? \sanitize_key((string) $reservation['status']) : '';

        if (!ReservationStatus::isConfirmed($status)) {
            return;
        }

        self::sendGuestBookingConfirmationEmail($reservation);
        self::sendAdminNewBookingNotificationEmail($reservation);
    }

    public static function handleReservationCancelledNotifications(int $reservationId): void
    {
        $reservation = self::getReservationEmailData((int) $reservationId);

        if (!\is_array($reservation)) {
            return;
        }

        self::sendGuestBookingCancellationEmail($reservation);
        self::sendAdminBookingCancellationEmail($reservation);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getReservationEmailData(int $reservationId): ?array
    {
        return get_reservation_repository()->getReservationEmailData($reservationId);
    }

    private static function getReservationEmailBookingId(array $reservation): string
    {
        $bookingId = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';

        if ($bookingId !== '') {
            return $bookingId;
        }

        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        return $reservationId > 0 ? 'RES-' . $reservationId : 'RES';
    }

    private static function getReservationEmailGuestName(array $reservation): string
    {
        $guestName = \trim((string) ($reservation['first_name'] ?? '') . ' ' . (string) ($reservation['last_name'] ?? ''));

        return $guestName !== '' ? $guestName : \__('Guest', 'must-hotel-booking');
    }

    /**
     * @return array<string, string>
     */
    private static function getReservationEmailPlaceholders(array $reservation): array
    {
        $roomName = \trim((string) ($reservation['room_name'] ?? ''));

        if ($roomName === '') {
            $roomName = \__('Room', 'must-hotel-booking');
        }

        return [
            '{booking_id}' => self::getReservationEmailBookingId($reservation),
            '{guest_name}' => self::getReservationEmailGuestName($reservation),
            '{room_name}' => $roomName,
            '{checkin}' => (string) ($reservation['checkin'] ?? ''),
            '{checkout}' => (string) ($reservation['checkout'] ?? ''),
            '{total_price}' => \number_format_i18n((float) ($reservation['total_price'] ?? 0.0), 2),
        ];
    }

    /**
     * @param array<string, string> $placeholders
     */
    private static function replacePlaceholders(string $content, array $placeholders): string
    {
        return \strtr($content, $placeholders);
    }

    /**
     * @return array{subject: string, body: string}
     */
    private static function buildReservationEmailContent(string $templateKey, array $reservation): array
    {
        $template = self::getTemplate($templateKey);
        $subjectTemplate = isset($template['subject']) ? (string) $template['subject'] : '';
        $bodyTemplate = isset($template['body']) ? (string) $template['body'] : '';
        $placeholders = self::getReservationEmailPlaceholders($reservation);

        return [
            'subject' => self::replacePlaceholders($subjectTemplate, $placeholders),
            'body' => self::replacePlaceholders($bodyTemplate, $placeholders),
        ];
    }

    private static function getBookingAdminNotificationEmail(): string
    {
        $email = \sanitize_email((string) MustBookingConfig::get_wp_option('admin_email', ''));

        return \is_email($email) ? $email : '';
    }

    private static function sendBookingEmail(string $recipientEmail, string $subject, string $message): bool
    {
        $recipientEmail = \sanitize_email($recipientEmail);
        $subject = \sanitize_text_field($subject);

        if (!\is_email($recipientEmail) || $subject === '' || \trim($message) === '') {
            return false;
        }

        return (bool) \wp_mail(
            $recipientEmail,
            $subject,
            $message,
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    private static function sendGuestBookingConfirmationEmail(array $reservation): bool
    {
        $guestEmail = isset($reservation['guest_email']) ? (string) $reservation['guest_email'] : '';
        $emailContent = self::buildReservationEmailContent('booking_confirmation', $reservation);

        return self::sendBookingEmail(
            $guestEmail,
            (string) ($emailContent['subject'] ?? ''),
            (string) ($emailContent['body'] ?? '')
        );
    }

    private static function sendAdminNewBookingNotificationEmail(array $reservation): bool
    {
        $adminEmail = self::getBookingAdminNotificationEmail();
        $emailContent = self::buildReservationEmailContent('admin_booking_notification', $reservation);

        return self::sendBookingEmail(
            $adminEmail,
            (string) ($emailContent['subject'] ?? ''),
            (string) ($emailContent['body'] ?? '')
        );
    }

    private static function sendGuestBookingCancellationEmail(array $reservation): bool
    {
        $guestEmail = isset($reservation['guest_email']) ? (string) $reservation['guest_email'] : '';
        $emailContent = self::buildReservationEmailContent('booking_cancellation', $reservation);

        return self::sendBookingEmail(
            $guestEmail,
            (string) ($emailContent['subject'] ?? ''),
            (string) ($emailContent['body'] ?? '')
        );
    }

    private static function sendAdminBookingCancellationEmail(array $reservation): bool
    {
        $adminEmail = self::getBookingAdminNotificationEmail();
        $emailContent = self::buildReservationEmailContent('booking_cancellation', $reservation);

        return self::sendBookingEmail(
            $adminEmail,
            (string) ($emailContent['subject'] ?? ''),
            (string) ($emailContent['body'] ?? '')
        );
    }
}
