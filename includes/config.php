<?php

namespace must_hotel_booking;

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
                'bank_transfer' => false,
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
            'design_use_elementor_global_styles' => 1,
            'design_font_family' => 'PP Neue Montreal',
            'design_h1_size' => '54px',
            'design_h2_size' => '54px',
            'design_h3_size' => '32px',
            'design_h4_size' => '24px',
            'design_h5_size' => '20px',
            'design_h6_size' => '18px',
            'design_body_l_size' => '18px',
            'design_body_m_size' => '16px',
            'design_body_s_size' => '14px',
            'design_body_xs_size' => '12px',
            'design_body_xxs_size' => '10px',
            'design_button_l_size' => '20px',
            'design_button_m_size' => '16px',
            'design_button_s_size' => '14px',
            'design_primary_color' => '#F5F2E5',
            'design_secondary_color' => '#C1FC7E',
            'design_primary_black_color' => '#F4F1EE',
            'design_accent_blue_color' => '#FFFFFF',
            'design_light_blue_color' => '#E7E8FF',
            'design_secondary_blue_color' => '#FFFFFF',
            'design_accent_gold_color' => '#DA1E28',
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
     * Get all persisted design settings merged with defaults.
     *
     * @return array<string, mixed>
     */
    public static function get_design_settings(): array
    {
        $settings = self::get_all_settings();
        $defaults = self::get_default_settings();
        $design_keys = [
            'design_use_elementor_global_styles',
            'design_font_family',
            'design_h1_size',
            'design_h2_size',
            'design_h3_size',
            'design_h4_size',
            'design_h5_size',
            'design_h6_size',
            'design_body_l_size',
            'design_body_m_size',
            'design_body_s_size',
            'design_body_xs_size',
            'design_body_xxs_size',
            'design_button_l_size',
            'design_button_m_size',
            'design_button_s_size',
            'design_primary_color',
            'design_secondary_color',
            'design_primary_black_color',
            'design_accent_blue_color',
            'design_light_blue_color',
            'design_secondary_blue_color',
            'design_accent_gold_color',
        ];

        $result = [];

        foreach ($design_keys as $key) {
            $result[$key] = $settings[$key] ?? ($defaults[$key] ?? null);
        }

        return $result;
    }

    /**
     * Get one design setting value.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get_design_setting(string $key, $default = null)
    {
        $settings = self::get_design_settings();

        if (\array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        return $default;
    }

    /**
     * Get "Use Elementor Global Styles" flag.
     */
    public static function use_elementor_global_styles(): bool
    {
        $value = self::get_design_setting('design_use_elementor_global_styles', 1);

        return (string) $value === '1' || $value === 1 || $value === true;
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
