<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;

final class EmailEngine
{
    public const TEMPLATES_SETTING_KEY = 'email_templates';

    public static function getTemplatesSettingKey(): string
    {
        return self::TEMPLATES_SETTING_KEY;
    }

    /**
     * @return array<string, string>
     */
    public static function getTemplateLabels(): array
    {
        $labels = [];

        foreach (self::getTemplateDefinitions() as $templateKey => $definition) {
            $labels[$templateKey] = (string) ($definition['label'] ?? $templateKey);
        }

        return $labels;
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
            '{hotel_email}',
            '{hotel_phone}',
            '{hotel_phone_href}',
            '{hotel_website}',
            '{email_footer_text}',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getDefaultTemplates(): array
    {
        $templates = [];

        foreach (self::getTemplateDefinitions() as $templateKey => $definition) {
            $templates[$templateKey] = [
                'subject' => (string) ($definition['subject'] ?? ''),
                'body' => (string) ($definition['body'] ?? ''),
            ];
        }

        return $templates;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getTemplates(): array
    {
        $defaults = self::getDefaultTemplates();
        $savedTemplates = MustBookingConfig::get_setting(self::getTemplatesSettingKey(), []);

        if (!\is_array($savedTemplates)) {
            $savedTemplates = [];
        }

        $templates = [];

        foreach ($defaults as $templateKey => $defaultTemplate) {
            $row = isset($savedTemplates[$templateKey]) && \is_array($savedTemplates[$templateKey])
                ? $savedTemplates[$templateKey]
                : [];

            $templates[$templateKey] = self::sanitizeTemplateRow($row, $defaultTemplate);
        }

        return $templates;
    }

    /**
     * @return array<string, string>
     */
    public static function getTemplate(string $templateKey): array
    {
        $templates = self::getTemplates();

        return $templates[$templateKey] ?? ['subject' => '', 'body' => ''];
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    public static function saveTemplates(array $templates): void
    {
        $defaults = self::getDefaultTemplates();
        $sanitizedTemplates = [];

        foreach ($defaults as $templateKey => $defaultTemplate) {
            $row = isset($templates[$templateKey]) && \is_array($templates[$templateKey])
                ? $templates[$templateKey]
                : [];

            $sanitizedTemplates[$templateKey] = self::sanitizeTemplateRow($row, $defaultTemplate);
        }

        MustBookingConfig::set_setting(self::getTemplatesSettingKey(), $sanitizedTemplates);
    }

    public static function registerHooks(): void
    {
        \add_action('must_hotel_booking/reservation_created', [self::class, 'handleReservationCreatedNotifications'], 10, 1);
        \add_action('must_hotel_booking/reservation_confirmed', [self::class, 'handleReservationConfirmedNotifications'], 10, 1);
        \add_action('must_hotel_booking/reservation_cancelled', [self::class, 'handleReservationCancelledNotifications'], 10, 1);
    }

    public static function handleReservationCreatedNotifications(int $reservationId): void
    {
        self::sendConfirmedReservationNotifications($reservationId);
    }

    public static function handleReservationConfirmedNotifications(int $reservationId): void
    {
        self::sendConfirmedReservationNotifications($reservationId);
    }

    public static function handleReservationCancelledNotifications(int $reservationId): void
    {
        $reservation = self::getReservationForEmail($reservationId);

        if ($reservation === null) {
            return;
        }

        self::sendNotificationForTemplate('guest_booking_cancelled', $reservation, (string) ($reservation['guest_email'] ?? ''));
        self::sendNotificationForTemplate('admin_booking_cancelled', $reservation, MustBookingConfig::get_booking_notification_email());
    }

    /**
     * @return array{success: bool, message: string}
     */
    public static function sendTestEmail(string $templateKey, string $recipientEmail): array
    {
        $templateKey = \sanitize_key($templateKey);
        $recipientEmail = \sanitize_email($recipientEmail);
        $definitions = self::getTemplateDefinitions();

        if (!isset($definitions[$templateKey])) {
            return [
                'success' => false,
                'message' => \__('Unknown email template.', 'must-hotel-booking'),
            ];
        }

        if (!\is_email($recipientEmail)) {
            return [
                'success' => false,
                'message' => \__('Please enter a valid test email address.', 'must-hotel-booking'),
            ];
        }

        $rendered = self::renderTemplateEmail(
            $templateKey,
            self::buildTestPlaceholders($templateKey, $recipientEmail),
            self::buildTestEmailMeta()
        );

        if (!self::dispatchEmail($recipientEmail, $rendered['subject'], $rendered['html'])) {
            return [
                'success' => false,
                'message' => \sprintf(
                    /* translators: %s is the template label. */
                    \__('Unable to send test email for %s.', 'must-hotel-booking'),
                    (string) ($definitions[$templateKey]['label'] ?? $templateKey)
                ),
            ];
        }

        return [
            'success' => true,
            'message' => \sprintf(
                /* translators: 1: template label, 2: recipient email. */
                \__('Test email for %1$s sent to %2$s.', 'must-hotel-booking'),
                (string) ($definitions[$templateKey]['label'] ?? $templateKey),
                $recipientEmail
            ),
        ];
    }

    /**
     * @return array{success: bool, sent: int, failed: array<int, string>, message: string}
     */
    public static function sendAllTestEmails(string $recipientEmail): array
    {
        $recipientEmail = \sanitize_email($recipientEmail);

        if (!\is_email($recipientEmail)) {
            return [
                'success' => false,
                'sent' => 0,
                'failed' => [],
                'message' => \__('Please enter a valid test email address.', 'must-hotel-booking'),
            ];
        }

        $sent = 0;
        $failed = [];

        foreach (\array_keys(self::getTemplateDefinitions()) as $templateKey) {
            $result = self::sendTestEmail($templateKey, $recipientEmail);

            if (!empty($result['success'])) {
                $sent++;
                continue;
            }

            $failed[] = (string) $result['message'];
        }

        if (empty($failed)) {
            return [
                'success' => true,
                'sent' => $sent,
                'failed' => [],
                'message' => \sprintf(
                    /* translators: 1: count, 2: recipient. */
                    \__('Sent %1$d test emails to %2$s.', 'must-hotel-booking'),
                    $sent,
                    $recipientEmail
                ),
            ];
        }

        return [
            'success' => false,
            'sent' => $sent,
            'failed' => $failed,
            'message' => \sprintf(
                /* translators: 1: success count, 2: failure count, 3: recipient. */
                \__('Sent %1$d test emails and %2$d failed for %3$s.', 'must-hotel-booking'),
                $sent,
                \count($failed),
                $recipientEmail
            ),
        ];
    }

    private static function sendConfirmedReservationNotifications(int $reservationId): void
    {
        $reservation = self::getReservationForEmail($reservationId);

        if ($reservation === null) {
            return;
        }

        if (\sanitize_key((string) ($reservation['status'] ?? '')) !== 'confirmed') {
            return;
        }

        $templateKeys = self::resolveConfirmedTemplateKeys($reservation);
        self::sendNotificationForTemplate($templateKeys['guest'], $reservation, (string) ($reservation['guest_email'] ?? ''));
        self::sendNotificationForTemplate($templateKeys['admin'], $reservation, MustBookingConfig::get_booking_notification_email());
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private static function sendNotificationForTemplate(string $templateKey, array $reservation, string $recipientEmail): void
    {
        $recipientEmail = \sanitize_email($recipientEmail);

        if (!\is_email($recipientEmail)) {
            return;
        }

        $rendered = self::renderTemplateEmail(
            $templateKey,
            self::buildReservationPlaceholders($reservation),
            [
                'reservation_id' => isset($reservation['id']) ? (int) $reservation['id'] : 0,
                'booking_id' => (string) ($reservation['booking_id'] ?? ''),
            ]
        );

        self::dispatchEmail($recipientEmail, $rendered['subject'], $rendered['html']);
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array{guest: string, admin: string}
     */
    private static function resolveConfirmedTemplateKeys(array $reservation): array
    {
        $paymentMethod = self::normalizeStoredPaymentMethod((string) ($reservation['payment_method'] ?? ''));
        $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));

        if ($paymentMethod === 'stripe' || $paymentStatus === 'paid') {
            return [
                'guest' => 'guest_booking_confirmed_paid',
                'admin' => 'admin_new_booking_paid',
            ];
        }

        return [
            'guest' => 'guest_booking_confirmed_pay_at_hotel',
            'admin' => 'admin_new_booking_pay_at_hotel',
        ];
    }

    /**
     * @param array<string, string> $placeholders
     * @param array<string, scalar> $meta
     * @return array{subject: string, html: string}
     */
    private static function renderTemplateEmail(string $templateKey, array $placeholders, array $meta): array
    {
        $definitions = self::getTemplateDefinitions();
        $definition = $definitions[$templateKey] ?? [];
        $template = self::getTemplate($templateKey);
        $subject = self::renderPlainTextTemplate((string) ($template['subject'] ?? ''), $placeholders);
        $heading = self::renderPlainTextTemplate((string) ($definition['heading'] ?? ''), $placeholders);
        $hotelName = $placeholders['{hotel_name}'] ?? '';
        $hotelWebsite = $placeholders['{hotel_website}'] ?? '';
        $logoUrl = MustBookingConfig::get_email_logo_url();
        $cta = self::buildCtaContext($templateKey, $meta);

        $layoutContext = [
            'email_subject' => $subject,
            'email_heading' => $heading !== '' ? $heading : $subject,
            'email_content' => self::renderTemplateBody((string) ($template['body'] ?? ''), $placeholders),
            'email_summary_rows' => EmailLayoutEngine::renderSummaryRows(self::buildSummaryRows($placeholders)),
            'email_cta_url' => (string) ($cta['url'] ?? ''),
            'email_cta_label' => (string) ($cta['label'] ?? ''),
            'email_logo_url' => $logoUrl,
            'email_logo_block' => EmailLayoutEngine::renderLogoBlock($logoUrl, (string) $hotelName, (string) $hotelWebsite),
            'email_button_color' => MustBookingConfig::get_email_button_color(),
            'email_footer_meta' => EmailLayoutEngine::renderFooterMeta([
                'hotel_name' => (string) ($placeholders['{hotel_name}'] ?? ''),
                'hotel_address' => (string) ($placeholders['{hotel_address}'] ?? ''),
                'hotel_website' => (string) ($placeholders['{hotel_website}'] ?? ''),
                'email_footer_text' => (string) ($placeholders['{email_footer_text}'] ?? ''),
            ]),
            'email_support_block' => EmailLayoutEngine::renderSupportBlock([
                'hotel_email' => (string) ($placeholders['{hotel_email}'] ?? ''),
                'hotel_phone' => (string) ($placeholders['{hotel_phone}'] ?? ''),
                'hotel_phone_href' => (string) ($placeholders['{hotel_phone_href}'] ?? ''),
                'hotel_website' => (string) ($placeholders['{hotel_website}'] ?? ''),
            ]),
        ];

        return [
            'subject' => $subject,
            'html' => EmailLayoutEngine::renderEmail(
                MustBookingConfig::get_email_layout_type(),
                $layoutContext,
                MustBookingConfig::get_custom_email_layout_html()
            ),
        ];
    }

    /**
     * @param array<string, string> $placeholders
     */
    private static function renderPlainTextTemplate(string $template, array $placeholders): string
    {
        $rendered = \strtr($template, self::getPlaceholderReplacementMap($placeholders, false));
        $rendered = \sanitize_text_field($rendered);

        return $rendered !== '' ? $rendered : \__('Booking Update', 'must-hotel-booking');
    }

    /**
     * @param array<string, string> $placeholders
     */
    private static function renderTemplateBody(string $template, array $placeholders): string
    {
        $template = \str_replace(["\r\n", "\r"], "\n", $template);
        $containsHtml = (bool) \preg_match('/<\s*[a-z][^>]*>/i', $template);

        if ($containsHtml) {
            $rendered = \strtr($template, self::getPlaceholderReplacementMap($placeholders, true));

            return \force_balance_tags(\wp_kses_post($rendered));
        }

        return \wpautop(\esc_html(\strtr($template, self::getPlaceholderReplacementMap($placeholders, false))));
    }

    /**
     * @param array<string, string> $placeholders
     * @return array<string, string>
     */
    private static function getPlaceholderReplacementMap(array $placeholders, bool $htmlSafe): array
    {
        $map = [];

        foreach ($placeholders as $placeholder => $value) {
            $map[$placeholder] = $htmlSafe ? \esc_html($value) : $value;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array<string, string>
     */
    private static function buildReservationPlaceholders(array $reservation): array
    {
        $hotelPhone = MustBookingConfig::get_hotel_phone();

        return [
            '{booking_id}' => self::resolveBookingId($reservation),
            '{guest_name}' => self::buildGuestName(
                (string) ($reservation['first_name'] ?? ''),
                (string) ($reservation['last_name'] ?? ''),
                (string) ($reservation['guest_email'] ?? '')
            ),
            '{guest_email}' => \sanitize_email((string) ($reservation['guest_email'] ?? '')),
            '{room_name}' => self::resolveRoomName($reservation),
            '{checkin}' => self::formatBookingDate((string) ($reservation['checkin'] ?? '')),
            '{checkout}' => self::formatBookingDate((string) ($reservation['checkout'] ?? '')),
            '{total_price}' => self::formatAmount((float) ($reservation['total_price'] ?? 0)),
            '{currency}' => MustBookingConfig::get_currency(),
            '{payment_method}' => self::formatPaymentMethodLabel(self::normalizeStoredPaymentMethod((string) ($reservation['payment_method'] ?? ''))),
            '{payment_status}' => self::formatPaymentStatusLabel((string) ($reservation['payment_status'] ?? '')),
            '{hotel_name}' => MustBookingConfig::get_hotel_name(),
            '{hotel_address}' => MustBookingConfig::get_hotel_address(),
            '{hotel_email}' => MustBookingConfig::get_booking_notification_email(),
            '{hotel_phone}' => $hotelPhone,
            '{hotel_phone_href}' => self::normalizePhoneHref($hotelPhone),
            '{hotel_website}' => (string) \home_url('/'),
            '{email_footer_text}' => MustBookingConfig::get_email_footer_text(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function buildTestPlaceholders(string $templateKey, string $recipientEmail): array
    {
        $checkinTimestamp = \strtotime('+14 days', (int) \current_time('timestamp'));
        $checkoutTimestamp = \strtotime('+17 days', (int) \current_time('timestamp'));
        $hotelPhone = MustBookingConfig::get_hotel_phone();
        $paymentMethod = \str_contains($templateKey, '_paid') ? 'stripe' : 'pay_at_hotel';
        $paymentStatus = \str_contains($templateKey, 'cancelled')
            ? 'cancelled'
            : (\str_contains($templateKey, '_paid') ? 'paid' : 'unpaid');

        return [
            '{booking_id}' => 'TEST-1001',
            '{guest_name}' => 'Alex Morgan',
            '{guest_email}' => $recipientEmail,
            '{room_name}' => 'Ocean Suite',
            '{checkin}' => $checkinTimestamp ? \wp_date('F j, Y', $checkinTimestamp) : 'June 12, 2026',
            '{checkout}' => $checkoutTimestamp ? \wp_date('F j, Y', $checkoutTimestamp) : 'June 15, 2026',
            '{total_price}' => self::formatAmount(480.00),
            '{currency}' => MustBookingConfig::get_currency(),
            '{payment_method}' => self::formatPaymentMethodLabel($paymentMethod),
            '{payment_status}' => self::formatPaymentStatusLabel($paymentStatus),
            '{hotel_name}' => MustBookingConfig::get_hotel_name(),
            '{hotel_address}' => MustBookingConfig::get_hotel_address(),
            '{hotel_email}' => MustBookingConfig::get_booking_notification_email(),
            '{hotel_phone}' => $hotelPhone,
            '{hotel_phone_href}' => self::normalizePhoneHref($hotelPhone),
            '{hotel_website}' => (string) \home_url('/'),
            '{email_footer_text}' => MustBookingConfig::get_email_footer_text(),
        ];
    }

    /**
     * @param array<string, string> $placeholders
     * @return array<int, array<string, string>>
     */
    private static function buildSummaryRows(array $placeholders): array
    {
        $rows = [
            ['label' => \__('Booking ID', 'must-hotel-booking'), 'value' => (string) ($placeholders['{booking_id}'] ?? '')],
            ['label' => \__('Guest', 'must-hotel-booking'), 'value' => (string) ($placeholders['{guest_name}'] ?? '')],
            ['label' => \__('Room', 'must-hotel-booking'), 'value' => (string) ($placeholders['{room_name}'] ?? '')],
            ['label' => \__('Check-in', 'must-hotel-booking'), 'value' => (string) ($placeholders['{checkin}'] ?? '')],
            ['label' => \__('Check-out', 'must-hotel-booking'), 'value' => (string) ($placeholders['{checkout}'] ?? '')],
            ['label' => \__('Total', 'must-hotel-booking'), 'value' => \trim((string) ($placeholders['{total_price}'] ?? '') . ' ' . (string) ($placeholders['{currency}'] ?? ''))],
            ['label' => \__('Payment Method', 'must-hotel-booking'), 'value' => (string) ($placeholders['{payment_method}'] ?? '')],
            ['label' => \__('Payment Status', 'must-hotel-booking'), 'value' => (string) ($placeholders['{payment_status}'] ?? '')],
        ];

        return \array_values(\array_filter(
            $rows,
            static function (array $row): bool {
                return \trim((string) ($row['value'] ?? '')) !== '';
            }
        ));
    }

    /**
     * @param array<string, scalar> $meta
     * @return array{label: string, url: string}
     */
    private static function buildCtaContext(string $templateKey, array $meta): array
    {
        $definition = self::getTemplateDefinitions()[$templateKey] ?? [];
        $audience = (string) ($definition['audience'] ?? 'guest');
        $label = (string) ($definition['cta_label'] ?? '');
        $bookingId = isset($meta['booking_id']) ? \trim((string) $meta['booking_id']) : '';
        $reservationId = isset($meta['reservation_id']) ? (int) $meta['reservation_id'] : 0;

        if ($audience === 'admin') {
            $url = \function_exists('MustHotelBooking\\Admin\\get_admin_reservations_page_url')
                ? \MustHotelBooking\Admin\get_admin_reservations_page_url(
                    $reservationId > 0 ? ['action' => 'view', 'reservation_id' => $reservationId] : []
                )
                : \admin_url('admin.php?page=must-hotel-booking-reservations');

            return [
                'label' => $label !== '' ? $label : \__('Open reservation', 'must-hotel-booking'),
                'url' => $url,
            ];
        }

        $url = ManagedPages::getBookingConfirmationPageUrl();

        if ($bookingId !== '') {
            $url = \add_query_arg(['booking_id' => $bookingId], $url);
        }

        return [
            'label' => $label !== '' ? $label : \__('View booking', 'must-hotel-booking'),
            'url' => $url,
        ];
    }

    /**
     * @return array<string, scalar>
     */
    private static function buildTestEmailMeta(): array
    {
        return [
            'reservation_id' => 1001,
            'booking_id' => 'TEST-1001',
        ];
    }

    private static function dispatchEmail(string $recipientEmail, string $subject, string $html): bool
    {
        $recipientEmail = \sanitize_email($recipientEmail);

        if (!\is_email($recipientEmail)) {
            return false;
        }

        return (bool) \wp_mail(
            $recipientEmail,
            $subject,
            $html,
            ['Content-Type: text/html; charset=UTF-8']
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getReservationForEmail(int $reservationId): ?array
    {
        if ($reservationId <= 0) {
            return null;
        }

        $reservation = get_reservation_repository()->getReservationEmailData($reservationId);

        return \is_array($reservation) ? $reservation : null;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, string> $defaults
     * @return array<string, string>
     */
    private static function sanitizeTemplateRow(array $template, array $defaults): array
    {
        $subject = isset($template['subject']) ? \sanitize_text_field((string) $template['subject']) : '';
        $body = isset($template['body']) ? \str_replace(["\r\n", "\r"], "\n", \wp_kses_post((string) $template['body'])) : '';

        if ($subject === '') {
            $subject = (string) ($defaults['subject'] ?? '');
        }

        if (\trim($body) === '') {
            $body = (string) ($defaults['body'] ?? '');
        }

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private static function resolveBookingId(array $reservation): string
    {
        $bookingId = \trim((string) ($reservation['booking_id'] ?? ''));

        if ($bookingId !== '') {
            return $bookingId;
        }

        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        return $reservationId > 0 ? 'RES-' . $reservationId : '';
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private static function resolveRoomName(array $reservation): string
    {
        $roomName = \trim((string) ($reservation['room_name'] ?? ''));

        if ($roomName !== '') {
            return $roomName;
        }

        $ratePlanName = \trim((string) ($reservation['rate_plan_name'] ?? ''));

        return $ratePlanName !== '' ? $ratePlanName : \__('Room selection pending', 'must-hotel-booking');
    }

    private static function buildGuestName(string $firstName, string $lastName, string $fallbackEmail): string
    {
        $guestName = \trim($firstName . ' ' . $lastName);

        if ($guestName !== '') {
            return $guestName;
        }

        return $fallbackEmail !== '' ? $fallbackEmail : \__('Guest', 'must-hotel-booking');
    }

    private static function formatBookingDate(string $date): string
    {
        $timestamp = \strtotime($date . ' 00:00:00');

        if ($timestamp === false) {
            return $date;
        }

        return \wp_date('F j, Y', $timestamp);
    }

    private static function formatAmount(float $amount): string
    {
        return \number_format_i18n($amount, 2);
    }

    private static function normalizeStoredPaymentMethod(string $method): string
    {
        $method = \sanitize_key($method);
        $gateway = PaymentEngine::normalizeMethod($method);

        if ($gateway === 'stripe') {
            return 'stripe';
        }

        if ($method === 'pay_at_hotel' || $gateway === 'cash') {
            return 'pay_at_hotel';
        }

        return $method !== '' ? $method : 'pay_at_hotel';
    }

    private static function formatPaymentMethodLabel(string $method): string
    {
        if ($method === 'stripe') {
            return \__('Stripe', 'must-hotel-booking');
        }

        if ($method === 'pay_at_hotel' || $method === 'cash') {
            return \__('Pay at hotel', 'must-hotel-booking');
        }

        return $method !== '' ? \ucwords(\str_replace('_', ' ', $method)) : \__('Not set', 'must-hotel-booking');
    }

    private static function formatPaymentStatusLabel(string $status): string
    {
        $status = \sanitize_key($status);

        if ($status === 'paid') {
            return \__('Paid', 'must-hotel-booking');
        }

        if ($status === 'unpaid') {
            return \__('Unpaid', 'must-hotel-booking');
        }

        if ($status === 'pending') {
            return \__('Pending', 'must-hotel-booking');
        }

        if ($status === 'cancelled') {
            return \__('Cancelled', 'must-hotel-booking');
        }

        return $status !== '' ? \ucwords(\str_replace('_', ' ', $status)) : \__('Not set', 'must-hotel-booking');
    }

    private static function normalizePhoneHref(string $phoneNumber): string
    {
        $phoneNumber = \trim($phoneNumber);

        if ($phoneNumber === '') {
            return '';
        }

        $normalized = (string) \preg_replace('/[^0-9+]/', '', $phoneNumber);

        return $normalized !== '' ? 'tel:' . $normalized : '';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function getTemplateDefinitions(): array
    {
        return [
            'guest_booking_confirmed_paid' => [
                'label' => \__('Guest: Booking Confirmed (Paid)', 'must-hotel-booking'),
                'audience' => 'guest',
                'heading' => \__('Your stay is confirmed', 'must-hotel-booking'),
                'cta_label' => \__('View booking', 'must-hotel-booking'),
                'subject' => \__('Booking {booking_id} confirmed at {hotel_name}', 'must-hotel-booking'),
                'body' => \__("Hello {guest_name},\n\nThank you for choosing {hotel_name}. Your payment has been received and your reservation is confirmed.\n\nYour stay in {room_name} is booked from {checkin} to {checkout}.", 'must-hotel-booking'),
            ],
            'guest_booking_confirmed_pay_at_hotel' => [
                'label' => \__('Guest: Booking Confirmed (Pay at Hotel)', 'must-hotel-booking'),
                'audience' => 'guest',
                'heading' => \__('Your stay is confirmed', 'must-hotel-booking'),
                'cta_label' => \__('View booking', 'must-hotel-booking'),
                'subject' => \__('Booking {booking_id} confirmed at {hotel_name}', 'must-hotel-booking'),
                'body' => \__("Hello {guest_name},\n\nYour reservation at {hotel_name} is confirmed.\n\nYour stay in {room_name} is booked from {checkin} to {checkout}. Payment will be collected at the hotel.", 'must-hotel-booking'),
            ],
            'admin_new_booking_paid' => [
                'label' => \__('Admin: New Booking (Paid)', 'must-hotel-booking'),
                'audience' => 'admin',
                'heading' => \__('New paid booking received', 'must-hotel-booking'),
                'cta_label' => \__('Open reservation', 'must-hotel-booking'),
                'subject' => \__('New paid booking {booking_id} for {guest_name}', 'must-hotel-booking'),
                'body' => \__("A new paid booking has been confirmed.\n\nGuest: {guest_name}\nEmail: {guest_email}\nRoom: {room_name}\nStay: {checkin} to {checkout}", 'must-hotel-booking'),
            ],
            'admin_new_booking_pay_at_hotel' => [
                'label' => \__('Admin: New Booking (Pay at Hotel)', 'must-hotel-booking'),
                'audience' => 'admin',
                'heading' => \__('New pay-at-hotel booking received', 'must-hotel-booking'),
                'cta_label' => \__('Open reservation', 'must-hotel-booking'),
                'subject' => \__('New pay-at-hotel booking {booking_id} for {guest_name}', 'must-hotel-booking'),
                'body' => \__("A new reservation has been confirmed with payment to be collected at the hotel.\n\nGuest: {guest_name}\nEmail: {guest_email}\nRoom: {room_name}\nStay: {checkin} to {checkout}", 'must-hotel-booking'),
            ],
            'guest_booking_cancelled' => [
                'label' => \__('Guest: Booking Cancelled', 'must-hotel-booking'),
                'audience' => 'guest',
                'heading' => \__('Your booking was cancelled', 'must-hotel-booking'),
                'cta_label' => \__('Review booking', 'must-hotel-booking'),
                'subject' => \__('Booking {booking_id} cancelled', 'must-hotel-booking'),
                'body' => \__("Hello {guest_name},\n\nYour booking for {room_name} from {checkin} to {checkout} has been cancelled.\n\nIf you need help with a new reservation, contact {hotel_name}.", 'must-hotel-booking'),
            ],
            'admin_booking_cancelled' => [
                'label' => \__('Admin: Booking Cancelled', 'must-hotel-booking'),
                'audience' => 'admin',
                'heading' => \__('Booking cancelled', 'must-hotel-booking'),
                'cta_label' => \__('Open reservation', 'must-hotel-booking'),
                'subject' => \__('Booking {booking_id} cancelled by or for {guest_name}', 'must-hotel-booking'),
                'body' => \__("A booking has been cancelled.\n\nGuest: {guest_name}\nEmail: {guest_email}\nRoom: {room_name}\nStay: {checkin} to {checkout}", 'must-hotel-booking'),
            ],
        ];
    }
}
