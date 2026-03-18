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
            'guest_booking_confirmed_paid' => \__('Guest booking confirmed (paid)', 'must-hotel-booking'),
            'guest_booking_confirmed_pay_at_hotel' => \__('Guest booking confirmed (pay at hotel)', 'must-hotel-booking'),
            'admin_new_booking_paid' => \__('Admin new booking (paid)', 'must-hotel-booking'),
            'admin_new_booking_pay_at_hotel' => \__('Admin new booking (pay at hotel)', 'must-hotel-booking'),
            'guest_booking_cancelled' => \__('Guest booking cancelled', 'must-hotel-booking'),
            'admin_booking_cancelled' => \__('Admin booking cancelled', 'must-hotel-booking'),
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
            '{guest_email}',
            '{room_name}',
            '{checkin}',
            '{checkout}',
            '{total_price}',
            '{currency}',
            '{payment_method}',
            '{payment_status}',
            '{hotel_name}',
            '{hotel_address}',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getDefaultTemplates(): array
    {
        return [
            'guest_booking_confirmed_paid' => [
                'subject' => \__('Booking Confirmed - {booking_id}', 'must-hotel-booking'),
                'body' => __("Hello {guest_name},\n\nYour booking is confirmed and your online payment has been received.\n\nBooking ID: {booking_id}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal: {total_price} {currency}\nPayment Method: {payment_method}\nPayment Status: {payment_status}\n\n{hotel_name}\n{hotel_address}", 'must-hotel-booking'),
            ],
            'guest_booking_confirmed_pay_at_hotel' => [
                'subject' => \__('Reservation Confirmed - {booking_id}', 'must-hotel-booking'),
                'body' => __("Hello {guest_name},\n\nYour reservation is confirmed.\nPayment will be collected at the hotel.\n\nBooking ID: {booking_id}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal: {total_price} {currency}\nPayment Method: {payment_method}\nPayment Status: {payment_status}\n\n{hotel_name}\n{hotel_address}", 'must-hotel-booking'),
            ],
            'admin_new_booking_paid' => [
                'subject' => \__('New Paid Booking - {booking_id}', 'must-hotel-booking'),
                'body' => __("A new paid booking was received.\n\nBooking ID: {booking_id}\nGuest: {guest_name}\nGuest Email: {guest_email}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal: {total_price} {currency}\nPayment Method: {payment_method}\nPayment Status: {payment_status}", 'must-hotel-booking'),
            ],
            'admin_new_booking_pay_at_hotel' => [
                'subject' => \__('New Reservation - Pay at Hotel - {booking_id}', 'must-hotel-booking'),
                'body' => __("A new pay-at-hotel reservation was received.\n\nBooking ID: {booking_id}\nGuest: {guest_name}\nGuest Email: {guest_email}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal: {total_price} {currency}\nPayment Method: {payment_method}\nPayment Status: {payment_status}", 'must-hotel-booking'),
            ],
            'guest_booking_cancelled' => [
                'subject' => \__('Booking Cancelled - {booking_id}', 'must-hotel-booking'),
                'body' => __("Hello {guest_name},\n\nYour booking has been cancelled.\n\nBooking ID: {booking_id}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal: {total_price} {currency}\n\n{hotel_name}", 'must-hotel-booking'),
            ],
            'admin_booking_cancelled' => [
                'subject' => \__('Booking Cancelled - {booking_id}', 'must-hotel-booking'),
                'body' => __("A booking has been cancelled.\n\nBooking ID: {booking_id}\nGuest: {guest_name}\nGuest Email: {guest_email}\nRoom: {room_name}\nCheck-in: {checkin}\nCheck-out: {checkout}\nTotal: {total_price} {currency}\nPayment Method: {payment_method}\nPayment Status: {payment_status}", 'must-hotel-booking'),
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

        $paymentMethod = isset($reservation['payment_method']) ? \sanitize_key((string) $reservation['payment_method']) : '';
        $gateway = \MustHotelBooking\Engine\PaymentEngine::normalizeMethod($paymentMethod);

        if ($gateway === 'stripe') {
            self::sendTemplateToGuest('guest_booking_confirmed_paid', $reservation);
            self::sendTemplateToAdmin('admin_new_booking_paid', $reservation);
            return;
        }

        self::sendTemplateToGuest('guest_booking_confirmed_pay_at_hotel', $reservation);
        self::sendTemplateToAdmin('admin_new_booking_pay_at_hotel', $reservation);
    }

    public static function handleReservationCancelledNotifications(int $reservationId): void
    {
        $reservation = self::getReservationEmailData((int) $reservationId);

        if (!\is_array($reservation)) {
            return;
        }

        self::sendTemplateToGuest('guest_booking_cancelled', $reservation);
        self::sendTemplateToAdmin('admin_booking_cancelled', $reservation);
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

        $paymentMethod = isset($reservation['payment_method']) ? (string) $reservation['payment_method'] : '';
        $paymentStatus = isset($reservation['payment_status']) ? (string) $reservation['payment_status'] : '';
        $currency = MustBookingConfig::get_currency();

        return [
            '{booking_id}' => self::getReservationEmailBookingId($reservation),
            '{guest_name}' => self::getReservationEmailGuestName($reservation),
            '{guest_email}' => (string) ($reservation['guest_email'] ?? ''),
            '{room_name}' => $roomName,
            '{checkin}' => (string) ($reservation['checkin'] ?? ''),
            '{checkout}' => (string) ($reservation['checkout'] ?? ''),
            '{total_price}' => \number_format_i18n((float) ($reservation['total_price'] ?? 0.0), 2),
            '{currency}' => $currency,
            '{payment_method}' => $paymentMethod,
            '{payment_status}' => $paymentStatus,
            '{hotel_name}' => MustBookingConfig::get_hotel_name(),
            '{hotel_address}' => MustBookingConfig::get_hotel_address(),
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
        return MustBookingConfig::get_booking_notification_email();
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

    private static function sendTemplateToGuest(string $templateKey, array $reservation): bool
    {
        $guestEmail = isset($reservation['guest_email']) ? (string) $reservation['guest_email'] : '';
        $emailContent = self::buildReservationEmailContent($templateKey, $reservation);

        return self::sendBookingEmail(
            $guestEmail,
            (string) ($emailContent['subject'] ?? ''),
            (string) ($emailContent['body'] ?? '')
        );
    }

    private static function sendTemplateToAdmin(string $templateKey, array $reservation): bool
    {
        $adminEmail = self::getBookingAdminNotificationEmail();
        $emailContent = self::buildReservationEmailContent($templateKey, $reservation);

        return self::sendBookingEmail(
            $adminEmail,
            (string) ($emailContent['subject'] ?? ''),
            (string) ($emailContent['body'] ?? '')
        );
    }
}