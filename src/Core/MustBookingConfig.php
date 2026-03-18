<?php

namespace MustHotelBooking\Core;

/**
 * Centralized configuration manager for MUST Hotel Booking.
 */
class MustBookingConfig
{
    /**
     * Get plugin settings option name.
     */
    public static function get_option_name(): string
    {
        return 'must_hotel_booking_settings';
    }

    /**
     * Get plugin settings defaults.
     *
     * @return array<string, mixed>
     */
    public static function get_default_settings(): array
    {
        return [
            'hotel_name' => self::get_wordpress_hotel_name_fallback(),
            'hotel_address' => '',
            'booking_notification_email' => '',
            'hotel_phone' => '',
            'email_logo_url' => '',
            'email_button_color' => '#141414',
            'email_footer_text' => \__('We look forward to welcoming you.', 'must-hotel-booking'),
            'email_layout_type' => 'classic',
            'custom_email_layout_html' => '',
            'currency' => 'USD',
            'timezone' => self::get_wordpress_timezone_fallback(),
            'tax_rate' => 0.0,
            'booking_window' => 365,
            'max_booking_guests' => 12,
            'max_booking_rooms' => 3,
            'checkin_time' => '14:00',
            'checkout_time' => '11:00',
            'payment_methods' => [
                'pay_at_hotel' => true,
                'stripe' => false,
            ],
            'site_environment' => '',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_webhook_secret' => '',
            'stripe_local_publishable_key' => '',
            'stripe_local_secret_key' => '',
            'stripe_local_webhook_secret' => '',
            'stripe_staging_publishable_key' => '',
            'stripe_staging_secret_key' => '',
            'stripe_staging_webhook_secret' => '',
            'stripe_production_publishable_key' => '',
            'stripe_production_secret_key' => '',
            'stripe_production_webhook_secret' => '',
        ];
    }

    /**
     * Get all plugin settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function get_all_settings(): array
    {
        $settings = self::get_wp_option(self::get_option_name(), []);

        if (!\is_array($settings)) {
            $settings = [];
        }

        return \array_merge(self::get_default_settings(), $settings);
    }

    /**
     * Save all plugin settings.
     *
     * @param array<string, mixed> $settings
     */
    public static function set_all_settings(array $settings): void
    {
        \update_option(self::get_option_name(), $settings);
    }

    /**
     * Get one plugin setting value.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get_setting(string $key, $default = null)
    {
        $settings = self::get_all_settings();

        if (\array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return $default;
    }

    /**
     * Set one plugin setting value.
     *
     * @param mixed $value
     */
    public static function set_setting(string $key, $value): void
    {
        $settings = self::get_all_settings();
        $settings[$key] = $value;
        self::set_all_settings($settings);
    }

    /**
     * Get hotel name setting.
     */
    public static function get_hotel_name(): string
    {
        $value = \trim((string) self::get_setting('hotel_name', self::get_wordpress_hotel_name_fallback()));

        return $value !== '' ? $value : self::get_wordpress_hotel_name_fallback();
    }

    /**
     * Get hotel address setting.
     */
    public static function get_hotel_address(): string
    {
        return \trim((string) self::get_setting('hotel_address', ''));
    }

    /**
     * Get booking notification email.
     */
    public static function get_booking_notification_email(): string
    {
        $email = \sanitize_email((string) self::get_setting('booking_notification_email', ''));

        if (\is_email($email)) {
            return $email;
        }

        $fallback = \sanitize_email((string) self::get_wp_option('admin_email', ''));

        return \is_email($fallback) ? $fallback : '';
    }

    /**
     * Get hotel phone setting.
     */
    public static function get_hotel_phone(): string
    {
        return \trim((string) self::get_setting('hotel_phone', ''));
    }

    /**
     * Get email logo URL.
     */
    public static function get_email_logo_url(): string
    {
        return \esc_url_raw((string) self::get_setting('email_logo_url', ''));
    }

    /**
     * Get email button color.
     */
    public static function get_email_button_color(): string
    {
        $color = (string) self::get_setting('email_button_color', '#141414');
        $color = \sanitize_hex_color($color);

        return \is_string($color) && $color !== '' ? $color : '#141414';
    }

    /**
     * Get email footer text.
     */
    public static function get_email_footer_text(): string
    {
        return \trim((string) self::get_setting('email_footer_text', \__('We look forward to welcoming you.', 'must-hotel-booking')));
    }

    /**
     * Get active email layout type.
     */
    public static function get_email_layout_type(): string
    {
        return self::normalize_email_layout_type((string) self::get_setting('email_layout_type', 'classic'));
    }

    /**
     * Get custom email layout HTML.
     */
    public static function get_custom_email_layout_html(): string
    {
        $html = (string) self::get_setting('custom_email_layout_html', '');

        return \str_replace(["\r\n", "\r"], "\n", $html);
    }

    /**
     * Get currency setting.
     */
    public static function get_currency(): string
    {
        $value = (string) self::get_setting('currency', 'USD');
        $value = \strtoupper(\trim($value));

        return $value !== '' ? $value : 'USD';
    }

    /**
     * Get timezone setting.
     */
    public static function get_timezone(): string
    {
        $value = \trim((string) self::get_setting('timezone', ''));

        if ($value === '') {
            $value = self::get_wordpress_timezone_fallback();
        }

        return $value !== '' ? $value : 'UTC';
    }

    /**
     * Get tax rate setting.
     */
    public static function get_tax_rate(): float
    {
        $value = (float) self::get_setting('tax_rate', 0.0);

        return $value >= 0 ? $value : 0.0;
    }

    /**
     * Get booking window setting.
     */
    public static function get_booking_window(): int
    {
        $value = \absint((string) self::get_setting('booking_window', 365));

        return $value > 0 ? $value : 365;
    }

    /**
     * Get max booking guests setting.
     */
    public static function get_max_booking_guests(): int
    {
        $value = \absint((string) self::get_setting('max_booking_guests', 12));

        return $value > 0 ? $value : 12;
    }

    /**
     * Get max booking rooms setting.
     */
    public static function get_max_booking_rooms(): int
    {
        $value = \absint((string) self::get_setting('max_booking_rooms', 3));

        return $value > 0 ? $value : 3;
    }

    /**
     * Get check-in time setting in HH:MM.
     */
    public static function get_checkin_time(): string
    {
        return self::normalize_time_value((string) self::get_setting('checkin_time', '14:00'), '14:00');
    }

    /**
     * Get check-out time setting in HH:MM.
     */
    public static function get_checkout_time(): string
    {
        return self::normalize_time_value((string) self::get_setting('checkout_time', '11:00'), '11:00');
    }

    /**
     * Wrapper around WordPress get_option.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get_wp_option(string $option_name, $default = null)
    {
        return \get_option($option_name, $default);
    }

    /**
     * Get timezone fallback from WordPress.
     */
    private static function get_wordpress_timezone_fallback(): string
    {
        $timezone = \function_exists('wp_timezone_string') ? (string) \wp_timezone_string() : '';

        return \trim($timezone) !== '' ? $timezone : 'UTC';
    }

    /**
     * Get hotel name fallback from WordPress site title.
     */
    private static function get_wordpress_hotel_name_fallback(): string
    {
        $site_name = \function_exists('get_bloginfo') ? (string) \get_bloginfo('name') : '';
        $site_name = \trim($site_name);

        return $site_name !== '' ? $site_name : 'Hotel';
    }

    /**
     * Normalize email layout type.
     */
    private static function normalize_email_layout_type(string $value): string
    {
        $value = \sanitize_key($value);
        $allowed = ['classic', 'luxury', 'compact', 'custom'];

        return \in_array($value, $allowed, true) ? $value : 'classic';
    }

    /**
     * Normalize time strings to HH:MM.
     */
    private static function normalize_time_value(string $value, string $fallback): string
    {
        $candidate = \trim($value);

        if (!\preg_match('/^\d{2}:\d{2}$/', $candidate)) {
            return $fallback;
        }

        $hour = (int) \substr($candidate, 0, 2);
        $minute = (int) \substr($candidate, 3, 2);

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return $fallback;
        }

        return \sprintf('%02d:%02d', $hour, $minute);
    }
}
