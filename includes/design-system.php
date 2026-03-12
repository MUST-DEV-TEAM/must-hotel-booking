<?php

namespace must_hotel_booking;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Get immutable default design tokens used by the plugin.
 *
 * @return array<string, string>
 */
function get_design_system_default_tokens(): array
{
    return [
        'font_family' => 'PP Neue Montreal',
        'h1_size' => '54px',
        'h1_line_height' => '110%',
        'h1_weight' => '400',
        'h2_size' => '54px',
        'h2_line_height' => '110%',
        'h2_weight' => '400',
        'h3_size' => '32px',
        'h3_line_height' => '110%',
        'h3_weight' => '400',
        'h4_size' => '24px',
        'h4_line_height' => '110%',
        'h4_weight' => '400',
        'h5_size' => '20px',
        'h5_line_height' => '110%',
        'h5_weight' => '400',
        'h6_size' => '18px',
        'h6_line_height' => '110%',
        'h6_weight' => '400',
        'body_l_size' => '18px',
        'body_l_line_height' => '140%',
        'body_l_weight' => '400',
        'body_m_size' => '16px',
        'body_m_line_height' => '140%',
        'body_m_weight' => '400',
        'body_s_size' => '14px',
        'body_s_line_height' => '140%',
        'body_s_weight' => '400',
        'body_xs_size' => '12px',
        'body_xs_line_height' => '140%',
        'body_xs_weight' => '400',
        'body_xxs_size' => '10px',
        'body_xxs_line_height' => '140%',
        'body_xxs_weight' => '400',
        'button_l_size' => '20px',
        'button_l_line_height' => '120%',
        'button_l_weight' => '500',
        'button_m_size' => '16px',
        'button_m_line_height' => '120%',
        'button_m_weight' => '500',
        'button_s_size' => '14px',
        'button_s_line_height' => '120%',
        'button_s_weight' => '500',
        'menu_tabs_size' => '16px',
        'menu_tabs_line_height' => '120%',
        'menu_tabs_weight' => '500',
        'primary_color' => '#F5F2E5',
        'secondary_color' => '#C1FC7E',
        'primary_black_color' => '#F4F1EE',
        'accent_blue_color' => '#FFFFFF',
        'light_blue_color' => '#E7E8FF',
        'secondary_blue_color' => '#FFFFFF',
        'accent_gold_color' => '#DA1E28',
    ];
}

/**
 * Get editable design settings defaults.
 *
 * @return array<string, mixed>
 */
function get_design_system_plugin_defaults(): array
{
    $tokens = get_design_system_default_tokens();

    return [
        'design_use_elementor_global_styles' => 1,
        'design_font_family' => $tokens['font_family'],
        'design_h1_size' => $tokens['h1_size'],
        'design_h2_size' => $tokens['h2_size'],
        'design_h3_size' => $tokens['h3_size'],
        'design_h4_size' => $tokens['h4_size'],
        'design_h5_size' => $tokens['h5_size'],
        'design_h6_size' => $tokens['h6_size'],
        'design_body_l_size' => $tokens['body_l_size'],
        'design_body_m_size' => $tokens['body_m_size'],
        'design_body_s_size' => $tokens['body_s_size'],
        'design_body_xs_size' => $tokens['body_xs_size'],
        'design_body_xxs_size' => $tokens['body_xxs_size'],
        'design_button_l_size' => $tokens['button_l_size'],
        'design_button_m_size' => $tokens['button_m_size'],
        'design_button_s_size' => $tokens['button_s_size'],
        'design_primary_color' => $tokens['primary_color'],
        'design_secondary_color' => $tokens['secondary_color'],
        'design_primary_black_color' => $tokens['primary_black_color'],
        'design_accent_blue_color' => $tokens['accent_blue_color'],
        'design_light_blue_color' => $tokens['light_blue_color'],
        'design_secondary_blue_color' => $tokens['secondary_blue_color'],
        'design_accent_gold_color' => $tokens['accent_gold_color'],
    ];
}

/**
 * Format a numeric value for CSS output.
 */
function format_design_system_number(float $value): string
{
    $formatted = \number_format($value, 4, '.', '');
    $formatted = \rtrim(\rtrim($formatted, '0'), '.');

    return $formatted === '' ? '0' : $formatted;
}

/**
 * Check if size value is valid.
 */
function is_valid_design_size_value(string $value): bool
{
    $candidate = \trim($value);

    if ($candidate === '') {
        return false;
    }

    if (\is_numeric($candidate)) {
        return (float) $candidate > 0;
    }

    return (bool) \preg_match('/^\d+(?:\.\d+)?(?:px|rem|em|%)$/i', $candidate);
}

/**
 * Normalize size value to CSS value.
 */
function normalize_design_size_value(string $value, string $fallback): string
{
    $candidate = \trim($value);

    if (\is_numeric($candidate)) {
        $number = (float) $candidate;

        if ($number > 0) {
            return format_design_system_number($number) . 'px';
        }
    }

    if (\preg_match('/^(\d+(?:\.\d+)?)(px|rem|em|%)$/i', $candidate, $matches)) {
        return format_design_system_number((float) $matches[1]) . \strtolower((string) $matches[2]);
    }

    return $fallback;
}

/**
 * Check if line-height value is valid.
 */
function is_valid_design_line_height_value(string $value): bool
{
    $candidate = \trim($value);

    if ($candidate === '') {
        return false;
    }

    if (\is_numeric($candidate)) {
        return (float) $candidate > 0;
    }

    return (bool) \preg_match('/^\d+(?:\.\d+)?(?:%|px|rem|em)$/i', $candidate);
}

/**
 * Normalize line-height value to CSS value.
 */
function normalize_design_line_height_value(string $value, string $fallback): string
{
    $candidate = \trim($value);

    if (\is_numeric($candidate)) {
        $number = (float) $candidate;

        if ($number > 0) {
            return format_design_system_number($number);
        }
    }

    if (\preg_match('/^(\d+(?:\.\d+)?)(%|px|rem|em)$/i', $candidate, $matches)) {
        return format_design_system_number((float) $matches[1]) . \strtolower((string) $matches[2]);
    }

    return $fallback;
}

/**
 * Check if font-weight value is valid.
 */
function is_valid_design_font_weight_value(string $value): bool
{
    $candidate = \strtolower(\trim($value));

    if ($candidate === '') {
        return false;
    }

    if (\in_array($candidate, ['normal', 'bold', 'bolder', 'lighter'], true)) {
        return true;
    }

    return (bool) \preg_match('/^[1-9]00$/', $candidate);
}

/**
 * Normalize font-weight value.
 */
function normalize_design_font_weight_value(string $value, string $fallback): string
{
    $candidate = \strtolower(\trim($value));

    if (\in_array($candidate, ['normal', 'bold', 'bolder', 'lighter'], true)) {
        return $candidate;
    }

    if (\preg_match('/^[1-9]00$/', $candidate)) {
        return $candidate;
    }

    return $fallback;
}

/**
 * Check if color value is a valid hex color.
 */
function is_valid_design_color_value(string $value): bool
{
    $normalized = \sanitize_hex_color(\trim($value));

    return \is_string($normalized) && $normalized !== '';
}

/**
 * Normalize color to uppercase hex.
 */
function normalize_design_color_value(string $value, string $fallback): string
{
    $normalized = \sanitize_hex_color(\trim($value));

    if (\is_string($normalized) && $normalized !== '') {
        return \strtoupper($normalized);
    }

    return \strtoupper($fallback);
}

/**
 * Sanitize a font family candidate.
 */
function sanitize_design_font_family_candidate(string $value): string
{
    $candidate = \trim(\wp_strip_all_tags($value));

    if ($candidate === '') {
        return '';
    }

    $candidate = (string) \preg_replace('/[{};<>]/', '', $candidate);
    $candidate = (string) \preg_replace('/\s+/', ' ', $candidate);

    if ($candidate === '') {
        return '';
    }

    if (\strlen($candidate) > 120) {
        $candidate = \substr($candidate, 0, 120);
    }

    return \trim($candidate);
}

/**
 * Normalize font family value.
 */
function normalize_design_font_family_value(string $value, string $fallback): string
{
    $candidate = sanitize_design_font_family_candidate($value);

    return $candidate !== '' ? $candidate : $fallback;
}

/**
 * Get plugin-level design settings merged with defaults.
 *
 * @return array<string, mixed>
 */
function get_design_system_plugin_settings(): array
{
    $defaults = get_design_system_plugin_defaults();
    $raw_settings = [];

    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        $raw_settings = MustBookingConfig::get_design_settings();
    }

    if (!\is_array($raw_settings)) {
        $raw_settings = [];
    }

    $settings = $defaults;
    $settings['design_use_elementor_global_styles'] = (
        isset($raw_settings['design_use_elementor_global_styles']) &&
        (
            $raw_settings['design_use_elementor_global_styles'] === true ||
            (string) $raw_settings['design_use_elementor_global_styles'] === '1'
        )
    ) ? 1 : 0;
    $settings['design_font_family'] = normalize_design_font_family_value(
        (string) ($raw_settings['design_font_family'] ?? $defaults['design_font_family']),
        (string) $defaults['design_font_family']
    );

    $size_fields = [
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
    ];

    foreach ($size_fields as $field_key) {
        $settings[$field_key] = normalize_design_size_value(
            (string) ($raw_settings[$field_key] ?? $defaults[$field_key]),
            (string) $defaults[$field_key]
        );
    }

    $color_fields = [
        'design_primary_color',
        'design_secondary_color',
        'design_primary_black_color',
        'design_accent_blue_color',
        'design_light_blue_color',
        'design_secondary_blue_color',
        'design_accent_gold_color',
    ];

    foreach ($color_fields as $field_key) {
        $settings[$field_key] = normalize_design_color_value(
            (string) ($raw_settings[$field_key] ?? $defaults[$field_key]),
            (string) $defaults[$field_key]
        );
    }

    return $settings;
}

/**
 * Sanitize and validate submitted design settings.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_design_settings_form_values(array $source): array
{
    $defaults = get_design_system_plugin_defaults();
    $errors = [];

    $values = [
        'design_use_elementor_global_styles' => isset($source['design_use_elementor_global_styles']) ? 1 : 0,
        'design_font_family' => (string) $defaults['design_font_family'],
    ];

    $font_raw = isset($source['design_font_family'])
        ? (string) \wp_unslash($source['design_font_family'])
        : '';
    $font_candidate = sanitize_design_font_family_candidate($font_raw);

    if ($font_raw !== '' && $font_candidate === '') {
        $errors[] = \__('Font family value is invalid.', 'must-hotel-booking');
    }

    $values['design_font_family'] = $font_candidate !== ''
        ? $font_candidate
        : (string) $defaults['design_font_family'];

    $size_labels = [
        'design_h1_size' => 'H1',
        'design_h2_size' => 'H2',
        'design_h3_size' => 'H3',
        'design_h4_size' => 'H4',
        'design_h5_size' => 'H5',
        'design_h6_size' => 'H6',
        'design_body_l_size' => \__('Body Large', 'must-hotel-booking'),
        'design_body_m_size' => \__('Body Medium', 'must-hotel-booking'),
        'design_body_s_size' => \__('Body Small', 'must-hotel-booking'),
        'design_body_xs_size' => \__('Body XS', 'must-hotel-booking'),
        'design_body_xxs_size' => \__('Body XXS', 'must-hotel-booking'),
        'design_button_l_size' => \__('Button Large', 'must-hotel-booking'),
        'design_button_m_size' => \__('Button Medium', 'must-hotel-booking'),
        'design_button_s_size' => \__('Button Small', 'must-hotel-booking'),
    ];

    foreach ($size_labels as $field_key => $label) {
        $raw = isset($source[$field_key]) ? \sanitize_text_field((string) \wp_unslash($source[$field_key])) : '';

        if ($raw !== '' && !is_valid_design_size_value($raw)) {
            /* translators: %s is a typography field label. */
            $errors[] = \sprintf(\__('%s size must be a positive CSS size, e.g. 16px.', 'must-hotel-booking'), (string) $label);
        }

        $values[$field_key] = $raw !== '' && is_valid_design_size_value($raw)
            ? normalize_design_size_value($raw, (string) $defaults[$field_key])
            : (string) $defaults[$field_key];
    }

    $color_labels = [
        'design_primary_color' => \__('Primary color', 'must-hotel-booking'),
        'design_secondary_color' => \__('Secondary color', 'must-hotel-booking'),
        'design_primary_black_color' => \__('Primary black', 'must-hotel-booking'),
        'design_accent_blue_color' => \__('Accent blue', 'must-hotel-booking'),
        'design_light_blue_color' => \__('Light blue', 'must-hotel-booking'),
        'design_secondary_blue_color' => \__('Secondary blue', 'must-hotel-booking'),
        'design_accent_gold_color' => \__('Accent gold', 'must-hotel-booking'),
    ];

    foreach ($color_labels as $field_key => $label) {
        $raw = isset($source[$field_key]) ? \sanitize_text_field((string) \wp_unslash($source[$field_key])) : '';

        if ($raw !== '' && !is_valid_design_color_value($raw)) {
            /* translators: %s is a color field label. */
            $errors[] = \sprintf(\__('%s must be a valid hex color.', 'must-hotel-booking'), (string) $label);
        }

        $values[$field_key] = $raw !== '' && is_valid_design_color_value($raw)
            ? normalize_design_color_value($raw, (string) $defaults[$field_key])
            : (string) $defaults[$field_key];
    }

    $values['errors'] = $errors;

    return $values;
}

/**
 * Check if Elementor is active and loaded.
 */
function is_elementor_available_for_design_system(): bool
{
    return \class_exists('\Elementor\Plugin') && \did_action('elementor/loaded') > 0;
}

/**
 * Get active Elementor kit settings.
 *
 * @return array<string, mixed>
 */
function get_elementor_active_kit_settings_for_design_system(): array
{
    static $cached = null;

    if (\is_array($cached)) {
        return $cached;
    }

    $cached = [];

    if (!is_elementor_available_for_design_system()) {
        return $cached;
    }

    $plugin = \Elementor\Plugin::$instance ?? null;
    $kits_manager = (\is_object($plugin) && isset($plugin->kits_manager)) ? $plugin->kits_manager : null;

    if (!\is_object($kits_manager)) {
        return $cached;
    }

    $active_kit = null;

    if (\method_exists($kits_manager, 'get_active_kit_for_frontend')) {
        $active_kit = $kits_manager->get_active_kit_for_frontend();
    }

    if (!\is_object($active_kit) && \method_exists($kits_manager, 'get_active_kit')) {
        $active_kit = $kits_manager->get_active_kit();
    }

    if (!\is_object($active_kit)) {
        return $cached;
    }

    $settings = [];

    if (\method_exists($active_kit, 'get_settings_for_display')) {
        $settings = $active_kit->get_settings_for_display();
    }

    if (!\is_array($settings) && \method_exists($active_kit, 'get_settings')) {
        $settings = $active_kit->get_settings();
    }

    $cached = \is_array($settings) ? $settings : [];

    return $cached;
}

/**
 * Normalize Elementor dimension value into CSS size.
 *
 * @param mixed $value
 */
function normalize_elementor_dimension_value($value): string
{
    if (\is_array($value)) {
        if (!isset($value['size']) || !\is_numeric($value['size'])) {
            return '';
        }

        $unit_raw = isset($value['unit']) ? \strtolower((string) $value['unit']) : 'px';
        $unit = \in_array($unit_raw, ['px', 'rem', 'em', '%'], true) ? $unit_raw : 'px';

        return normalize_design_size_value(
            format_design_system_number((float) $value['size']) . $unit,
            ''
        );
    }

    $candidate = \trim((string) $value);

    if (!is_valid_design_size_value($candidate)) {
        return '';
    }

    return normalize_design_size_value($candidate, '');
}

/**
 * Normalize Elementor line-height value.
 *
 * @param mixed $value
 */
function normalize_elementor_line_height_value($value): string
{
    if (\is_array($value)) {
        if (!isset($value['size']) || !\is_numeric($value['size'])) {
            return '';
        }

        $unit_raw = isset($value['unit']) ? \strtolower((string) $value['unit']) : '';
        $unit = \in_array($unit_raw, ['%', 'px', 'rem', 'em'], true) ? $unit_raw : '';
        $candidate = format_design_system_number((float) $value['size']) . $unit;

        return normalize_design_line_height_value($candidate, '');
    }

    $candidate = \trim((string) $value);

    if (!is_valid_design_line_height_value($candidate)) {
        return '';
    }

    return normalize_design_line_height_value($candidate, '');
}

/**
 * Normalize Elementor font-weight value.
 *
 * @param mixed $value
 */
function normalize_elementor_font_weight_value($value): string
{
    $candidate = \trim((string) $value);

    if (!is_valid_design_font_weight_value($candidate)) {
        return '';
    }

    return normalize_design_font_weight_value($candidate, '');
}

/**
 * Normalize Elementor font family value.
 *
 * @param mixed $value
 */
function normalize_elementor_font_family_value($value): string
{
    $candidate = sanitize_design_font_family_candidate((string) $value);

    return $candidate !== '' ? $candidate : '';
}

/**
 * Pull first valid size override from Elementor settings keys.
 *
 * @param array<string, mixed> $settings
 * @param array<int, string>   $keys
 */
function get_elementor_size_override(array $settings, array $keys): string
{
    foreach ($keys as $key) {
        if (!\array_key_exists($key, $settings)) {
            continue;
        }

        $normalized = normalize_elementor_dimension_value($settings[$key]);

        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

/**
 * Pull first valid line-height override from Elementor settings keys.
 *
 * @param array<string, mixed> $settings
 * @param array<int, string>   $keys
 */
function get_elementor_line_height_override(array $settings, array $keys): string
{
    foreach ($keys as $key) {
        if (!\array_key_exists($key, $settings)) {
            continue;
        }

        $normalized = normalize_elementor_line_height_value($settings[$key]);

        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

/**
 * Pull first valid font-weight override from Elementor settings keys.
 *
 * @param array<string, mixed> $settings
 * @param array<int, string>   $keys
 */
function get_elementor_font_weight_override(array $settings, array $keys): string
{
    foreach ($keys as $key) {
        if (!\array_key_exists($key, $settings)) {
            continue;
        }

        $normalized = normalize_elementor_font_weight_value($settings[$key]);

        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

/**
 * Pull first valid font family override from Elementor settings keys.
 *
 * @param array<string, mixed> $settings
 * @param array<int, string>   $keys
 */
function get_elementor_font_family_override(array $settings, array $keys): string
{
    foreach ($keys as $key) {
        if (!\array_key_exists($key, $settings)) {
            continue;
        }

        $normalized = normalize_elementor_font_family_value($settings[$key]);

        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

/**
 * Build typography map from Elementor global typography collections.
 *
 * @param array<string, mixed> $settings
 * @return array<string, array<string, string>>
 */
function get_elementor_typography_map(array $settings): array
{
    $map = [];

    foreach (['system_typography', 'custom_typography'] as $collection_key) {
        $rows = $settings[$collection_key] ?? null;

        if (!\is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $id = isset($row['_id']) ? \sanitize_key((string) $row['_id']) : '';

            if ($id === '') {
                continue;
            }

            $entry = [];
            $family = normalize_elementor_font_family_value($row['typography_font_family'] ?? '');
            $size = normalize_elementor_dimension_value($row['typography_font_size'] ?? '');
            $line_height = normalize_elementor_line_height_value($row['typography_line_height'] ?? '');
            $weight = normalize_elementor_font_weight_value($row['typography_font_weight'] ?? '');

            if ($family !== '') {
                $entry['font_family'] = $family;
            }

            if ($size !== '') {
                $entry['size'] = $size;
            }

            if ($line_height !== '') {
                $entry['line_height'] = $line_height;
            }

            if ($weight !== '') {
                $entry['weight'] = $weight;
            }

            if (!empty($entry)) {
                $map[$id] = $entry;
            }
        }
    }

    return $map;
}

/**
 * Extract Elementor typography overrides.
 *
 * @param array<string, mixed> $settings
 * @return array<string, string>
 */
function extract_elementor_typography_overrides(array $settings): array
{
    $overrides = [];
    $tags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    $typography_map = get_elementor_typography_map($settings);
    $primary_typography = $typography_map['primary'] ?? [];
    $text_typography = $typography_map['text'] ?? [];
    $accent_typography = $typography_map['accent'] ?? [];
    $secondary_typography = $typography_map['secondary'] ?? [];

    $font_family = get_elementor_font_family_override(
        $settings,
        ['body_typography_font_family', 'typography_body_font_family', 'text_typography_font_family']
    );

    if ($font_family === '' && isset($text_typography['font_family'])) {
        $font_family = (string) $text_typography['font_family'];
    }

    if ($font_family === '' && isset($primary_typography['font_family'])) {
        $font_family = (string) $primary_typography['font_family'];
    }

    if ($font_family !== '') {
        $overrides['font_family'] = $font_family;
    }

    foreach ($tags as $tag) {
        $size = get_elementor_size_override($settings, [$tag . '_typography_font_size']);
        $line_height = get_elementor_line_height_override($settings, [$tag . '_typography_line_height']);
        $weight = get_elementor_font_weight_override($settings, [$tag . '_typography_font_weight']);

        if ($size !== '') {
            $overrides[$tag . '_size'] = $size;
        }

        if ($line_height !== '') {
            $overrides[$tag . '_line_height'] = $line_height;
        }

        if ($weight !== '') {
            $overrides[$tag . '_weight'] = $weight;
        }
    }

    foreach ($tags as $tag) {
        if (!isset($overrides[$tag . '_size']) && isset($primary_typography['size'])) {
            $overrides[$tag . '_size'] = (string) $primary_typography['size'];
        }

        if (!isset($overrides[$tag . '_line_height']) && isset($primary_typography['line_height'])) {
            $overrides[$tag . '_line_height'] = (string) $primary_typography['line_height'];
        }

        if (!isset($overrides[$tag . '_weight']) && isset($primary_typography['weight'])) {
            $overrides[$tag . '_weight'] = (string) $primary_typography['weight'];
        }
    }

    $body_medium_size = get_elementor_size_override(
        $settings,
        ['body_typography_font_size', 'text_typography_font_size', 'typography_text_font_size']
    );
    $body_medium_line_height = get_elementor_line_height_override(
        $settings,
        ['body_typography_line_height', 'text_typography_line_height', 'typography_text_line_height']
    );
    $body_medium_weight = get_elementor_font_weight_override(
        $settings,
        ['body_typography_font_weight', 'text_typography_font_weight', 'typography_text_font_weight']
    );

    if ($body_medium_size === '' && isset($text_typography['size'])) {
        $body_medium_size = (string) $text_typography['size'];
    }

    if ($body_medium_line_height === '' && isset($text_typography['line_height'])) {
        $body_medium_line_height = (string) $text_typography['line_height'];
    }

    if ($body_medium_weight === '' && isset($text_typography['weight'])) {
        $body_medium_weight = (string) $text_typography['weight'];
    }

    if ($body_medium_size !== '') {
        $overrides['body_m_size'] = $body_medium_size;
    }

    if ($body_medium_line_height !== '') {
        $overrides['body_m_line_height'] = $body_medium_line_height;
    }

    if ($body_medium_weight !== '') {
        $overrides['body_m_weight'] = $body_medium_weight;
    }

    if (isset($secondary_typography['size'])) {
        $overrides['body_l_size'] = (string) $secondary_typography['size'];
    }

    if (isset($secondary_typography['line_height'])) {
        $overrides['body_l_line_height'] = (string) $secondary_typography['line_height'];
    }

    if (isset($secondary_typography['weight'])) {
        $overrides['body_l_weight'] = (string) $secondary_typography['weight'];
    }

    $button_medium_size = get_elementor_size_override($settings, ['button_typography_font_size']);
    $button_medium_line_height = get_elementor_line_height_override($settings, ['button_typography_line_height']);
    $button_medium_weight = get_elementor_font_weight_override($settings, ['button_typography_font_weight']);

    if ($button_medium_size === '' && isset($accent_typography['size'])) {
        $button_medium_size = (string) $accent_typography['size'];
    }

    if ($button_medium_line_height === '' && isset($accent_typography['line_height'])) {
        $button_medium_line_height = (string) $accent_typography['line_height'];
    }

    if ($button_medium_weight === '' && isset($accent_typography['weight'])) {
        $button_medium_weight = (string) $accent_typography['weight'];
    }

    if ($button_medium_size !== '') {
        $overrides['button_m_size'] = $button_medium_size;
    }

    if ($button_medium_line_height !== '') {
        $overrides['button_m_line_height'] = $button_medium_line_height;
    }

    if ($button_medium_weight !== '') {
        $overrides['button_m_weight'] = $button_medium_weight;
    }

    $menu_tab_size = get_elementor_size_override($settings, ['menu_typography_font_size', 'nav_menu_typography_font_size']);
    $menu_tab_weight = get_elementor_font_weight_override($settings, ['menu_typography_font_weight', 'nav_menu_typography_font_weight']);
    $menu_tab_line_height = get_elementor_line_height_override($settings, ['menu_typography_line_height', 'nav_menu_typography_line_height']);

    if ($menu_tab_size !== '') {
        $overrides['menu_tabs_size'] = $menu_tab_size;
    }

    if ($menu_tab_weight !== '') {
        $overrides['menu_tabs_weight'] = $menu_tab_weight;
    }

    if ($menu_tab_line_height !== '') {
        $overrides['menu_tabs_line_height'] = $menu_tab_line_height;
    }

    return $overrides;
}

/**
 * Extract Elementor global color overrides.
 *
 * @param array<string, mixed> $settings
 * @return array<string, string>
 */
function extract_elementor_color_overrides(array $settings): array
{
    $colors_by_id = [];
    $custom_colors = [];

    foreach (['system_colors', 'custom_colors'] as $collection_key) {
        $rows = $settings[$collection_key] ?? null;

        if (!\is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $raw_color = isset($row['color']) ? (string) $row['color'] : '';
            $color = normalize_design_color_value($raw_color, '');

            if ($color === '') {
                continue;
            }

            $id = isset($row['_id']) ? \sanitize_key((string) $row['_id']) : '';

            if ($id !== '') {
                $colors_by_id[$id] = $color;
            }

            if ($collection_key === 'custom_colors') {
                $custom_colors[] = $color;
            }
        }
    }

    $overrides = [];

    if (isset($colors_by_id['primary'])) {
        $overrides['primary_color'] = (string) $colors_by_id['primary'];
    }

    if (isset($colors_by_id['secondary'])) {
        $overrides['secondary_color'] = (string) $colors_by_id['secondary'];
        $overrides['secondary_blue_color'] = (string) $colors_by_id['secondary'];
    }

    if (isset($colors_by_id['text'])) {
        $overrides['primary_black_color'] = (string) $colors_by_id['text'];
    }

    if (isset($colors_by_id['accent'])) {
        $overrides['accent_blue_color'] = (string) $colors_by_id['accent'];
        $overrides['accent_gold_color'] = (string) $colors_by_id['accent'];
    }

    if (!isset($overrides['light_blue_color']) && isset($custom_colors[0])) {
        $overrides['light_blue_color'] = (string) $custom_colors[0];
    }

    if (!isset($overrides['secondary_blue_color']) && isset($custom_colors[1])) {
        $overrides['secondary_blue_color'] = (string) $custom_colors[1];
    }

    if (isset($custom_colors[2])) {
        $overrides['accent_gold_color'] = (string) $custom_colors[2];
    }

    return $overrides;
}

/**
 * Get merged Elementor design overrides.
 *
 * @return array<string, string>
 */
function get_elementor_design_overrides(): array
{
    static $cached = null;

    if (\is_array($cached)) {
        return $cached;
    }

    $cached = [];

    $settings = get_elementor_active_kit_settings_for_design_system();

    if (empty($settings)) {
        return $cached;
    }

    $cached = \array_merge(
        extract_elementor_color_overrides($settings),
        extract_elementor_typography_overrides($settings)
    );

    return $cached;
}

/**
 * Get Elementor integration status for settings UI.
 *
 * @return array{active: bool, has_global_colors: bool, has_global_typography: bool}
 */
function get_design_system_elementor_status(): array
{
    if (!is_elementor_available_for_design_system()) {
        return [
            'active' => false,
            'has_global_colors' => false,
            'has_global_typography' => false,
        ];
    }

    $settings = get_elementor_active_kit_settings_for_design_system();
    $colors = extract_elementor_color_overrides($settings);
    $typography = extract_elementor_typography_overrides($settings);

    return [
        'active' => true,
        'has_global_colors' => !empty($colors),
        'has_global_typography' => !empty($typography),
    ];
}

/**
 * Resolve final design tokens using priority:
 * Elementor globals -> plugin settings -> defaults.
 *
 * @return array<string, string>
 */
function get_resolved_design_tokens(): array
{
    static $cached = null;

    if (\is_array($cached)) {
        return $cached;
    }

    $defaults = get_design_system_default_tokens();
    $plugin_settings = get_design_system_plugin_settings();
    $resolved = $defaults;

    $plugin_to_token_map = [
        'design_font_family' => 'font_family',
        'design_h1_size' => 'h1_size',
        'design_h2_size' => 'h2_size',
        'design_h3_size' => 'h3_size',
        'design_h4_size' => 'h4_size',
        'design_h5_size' => 'h5_size',
        'design_h6_size' => 'h6_size',
        'design_body_l_size' => 'body_l_size',
        'design_body_m_size' => 'body_m_size',
        'design_body_s_size' => 'body_s_size',
        'design_body_xs_size' => 'body_xs_size',
        'design_body_xxs_size' => 'body_xxs_size',
        'design_button_l_size' => 'button_l_size',
        'design_button_m_size' => 'button_m_size',
        'design_button_s_size' => 'button_s_size',
        'design_primary_color' => 'primary_color',
        'design_secondary_color' => 'secondary_color',
        'design_primary_black_color' => 'primary_black_color',
        'design_accent_blue_color' => 'accent_blue_color',
        'design_light_blue_color' => 'light_blue_color',
        'design_secondary_blue_color' => 'secondary_blue_color',
        'design_accent_gold_color' => 'accent_gold_color',
    ];

    foreach ($plugin_to_token_map as $setting_key => $token_key) {
        if (isset($plugin_settings[$setting_key]) && (string) $plugin_settings[$setting_key] !== '') {
            $resolved[$token_key] = (string) $plugin_settings[$setting_key];
        }
    }

    $use_elementor = isset($plugin_settings['design_use_elementor_global_styles'])
        && ((string) $plugin_settings['design_use_elementor_global_styles'] === '1');

    if ($use_elementor) {
        $elementor_overrides = get_elementor_design_overrides();

        if (!empty($elementor_overrides)) {
            $resolved = \array_merge($resolved, $elementor_overrides);
        }
    }

    $resolved['font_family'] = normalize_design_font_family_value($resolved['font_family'], $defaults['font_family']);

    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body_l', 'body_m', 'body_s', 'body_xs', 'body_xxs', 'button_l', 'button_m', 'button_s', 'menu_tabs'] as $key) {
        $resolved[$key . '_size'] = normalize_design_size_value($resolved[$key . '_size'], $defaults[$key . '_size']);
    }

    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body_l', 'body_m', 'body_s', 'body_xs', 'body_xxs', 'button_l', 'button_m', 'button_s', 'menu_tabs'] as $key) {
        $resolved[$key . '_line_height'] = normalize_design_line_height_value($resolved[$key . '_line_height'], $defaults[$key . '_line_height']);
        $resolved[$key . '_weight'] = normalize_design_font_weight_value($resolved[$key . '_weight'], $defaults[$key . '_weight']);
    }

    foreach (['primary_color', 'secondary_color', 'primary_black_color', 'accent_blue_color', 'light_blue_color', 'secondary_blue_color', 'accent_gold_color'] as $color_key) {
        $resolved[$color_key] = normalize_design_color_value($resolved[$color_key], $defaults[$color_key]);
    }

    $cached = $resolved;

    return $cached;
}

/**
 * Format CSS font-family declaration for variable output.
 */
function format_design_system_font_family_css(string $font_family): string
{
    $parts = \array_values(
        \array_filter(
            \array_map('trim', \explode(',', $font_family)),
            static function (string $part): bool {
                return $part !== '';
            }
        )
    );

    if (empty($parts)) {
        $parts = ['PP Neue Montreal'];
    }

    $formatted_parts = [];
    $generic_families = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'];

    foreach ($parts as $part) {
        $clean_part = \trim((string) \preg_replace('/["\']/', '', $part));

        if ($clean_part === '') {
            continue;
        }

        $lower = \strtolower($clean_part);

        if (\in_array($lower, $generic_families, true)) {
            $formatted_parts[] = $lower;
            continue;
        }

        $formatted_parts[] = '\'' . $clean_part . '\'';
    }

    if (empty($formatted_parts)) {
        $formatted_parts[] = '\'PP Neue Montreal\'';
    }

    if (!\in_array('sans-serif', $formatted_parts, true) && !\in_array('serif', $formatted_parts, true)) {
        $formatted_parts[] = 'sans-serif';
    }

    return \implode(',', $formatted_parts);
}

/**
 * Build CSS variable declarations.
 */
function build_design_system_css_variables(array $tokens): string
{
    $variables = [
        '--must-font-family' => format_design_system_font_family_css((string) $tokens['font_family']),
        '--must-h1-size' => (string) $tokens['h1_size'],
        '--must-h1-line-height' => (string) $tokens['h1_line_height'],
        '--must-h1-weight' => (string) $tokens['h1_weight'],
        '--must-h2-size' => (string) $tokens['h2_size'],
        '--must-h2-line-height' => (string) $tokens['h2_line_height'],
        '--must-h2-weight' => (string) $tokens['h2_weight'],
        '--must-h3-size' => (string) $tokens['h3_size'],
        '--must-h3-line-height' => (string) $tokens['h3_line_height'],
        '--must-h3-weight' => (string) $tokens['h3_weight'],
        '--must-h4-size' => (string) $tokens['h4_size'],
        '--must-h4-line-height' => (string) $tokens['h4_line_height'],
        '--must-h4-weight' => (string) $tokens['h4_weight'],
        '--must-h5-size' => (string) $tokens['h5_size'],
        '--must-h5-line-height' => (string) $tokens['h5_line_height'],
        '--must-h5-weight' => (string) $tokens['h5_weight'],
        '--must-h6-size' => (string) $tokens['h6_size'],
        '--must-h6-line-height' => (string) $tokens['h6_line_height'],
        '--must-h6-weight' => (string) $tokens['h6_weight'],
        '--must-body-l-size' => (string) $tokens['body_l_size'],
        '--must-body-l-line-height' => (string) $tokens['body_l_line_height'],
        '--must-body-l-weight' => (string) $tokens['body_l_weight'],
        '--must-body-m-size' => (string) $tokens['body_m_size'],
        '--must-body-m-line-height' => (string) $tokens['body_m_line_height'],
        '--must-body-m-weight' => (string) $tokens['body_m_weight'],
        '--must-body-s-size' => (string) $tokens['body_s_size'],
        '--must-body-s-line-height' => (string) $tokens['body_s_line_height'],
        '--must-body-s-weight' => (string) $tokens['body_s_weight'],
        '--must-body-xs-size' => (string) $tokens['body_xs_size'],
        '--must-body-xs-line-height' => (string) $tokens['body_xs_line_height'],
        '--must-body-xs-weight' => (string) $tokens['body_xs_weight'],
        '--must-body-xxs-size' => (string) $tokens['body_xxs_size'],
        '--must-body-xxs-line-height' => (string) $tokens['body_xxs_line_height'],
        '--must-body-xxs-weight' => (string) $tokens['body_xxs_weight'],
        '--must-body-l' => (string) $tokens['body_l_size'],
        '--must-body-m' => (string) $tokens['body_m_size'],
        '--must-body-s' => (string) $tokens['body_s_size'],
        '--must-body-xs' => (string) $tokens['body_xs_size'],
        '--must-body-xxs' => (string) $tokens['body_xxs_size'],
        '--must-button-l-size' => (string) $tokens['button_l_size'],
        '--must-button-l-line-height' => (string) $tokens['button_l_line_height'],
        '--must-button-l-weight' => (string) $tokens['button_l_weight'],
        '--must-button-m-size' => (string) $tokens['button_m_size'],
        '--must-button-m-line-height' => (string) $tokens['button_m_line_height'],
        '--must-button-m-weight' => (string) $tokens['button_m_weight'],
        '--must-button-s-size' => (string) $tokens['button_s_size'],
        '--must-button-s-line-height' => (string) $tokens['button_s_line_height'],
        '--must-button-s-weight' => (string) $tokens['button_s_weight'],
        '--must-menu-tabs-size' => (string) $tokens['menu_tabs_size'],
        '--must-menu-tabs-line-height' => (string) $tokens['menu_tabs_line_height'],
        '--must-menu-tabs-weight' => (string) $tokens['menu_tabs_weight'],
        '--must-primary' => (string) $tokens['primary_color'],
        '--must-secondary' => (string) $tokens['secondary_color'],
        '--must-black' => (string) $tokens['primary_black_color'],
        '--must-accent-blue' => (string) $tokens['accent_blue_color'],
        '--must-light-blue' => (string) $tokens['light_blue_color'],
        '--must-secondary-blue' => (string) $tokens['secondary_blue_color'],
        '--must-gold' => (string) $tokens['accent_gold_color'],
    ];

    $css = ":root{\n";

    foreach ($variables as $name => $value) {
        $css .= $name . ':' . $value . ";\n";
    }

    $css .= "}\n";

    return $css;
}

/**
 * Register design system style handles.
 */
function register_design_system_assets(): void
{
    \wp_register_style(
        'must-hotel-booking-design-system',
        MUST_HOTEL_BOOKING_URL . 'assets/css/design-system.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_register_style(
        'must-hotel-booking-instrument-sans',
        'https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600&display=swap',
        [],
        null
    );
}

/**
 * Check if current admin screen belongs to this plugin.
 */
function is_design_system_plugin_admin_page(): bool
{
    if (!\is_admin() || !isset($_GET['page'])) {
        return false;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    return \strpos($page, 'must-hotel-booking') === 0;
}

/**
 * Check whether Instrument Sans is part of resolved font stack.
 *
 * @param array<string, string> $tokens
 */
function is_instrument_sans_required(array $tokens): bool
{
    $font_family = \strtolower((string) ($tokens['font_family'] ?? ''));

    return \strpos($font_family, 'instrument sans') !== false;
}

/**
 * Detect if Instrument Sans is already registered/enqueued by another source.
 */
function is_instrument_sans_already_loaded(): bool
{
    if (\wp_style_is('must-hotel-booking-instrument-sans', 'enqueued') || \wp_style_is('must-hotel-booking-instrument-sans', 'done')) {
        return true;
    }

    $styles = \wp_styles();

    if (!($styles instanceof \WP_Styles) || !\is_array($styles->registered)) {
        return false;
    }

    foreach ($styles->registered as $registered_style) {
        if (!\is_object($registered_style) || !isset($registered_style->src)) {
            continue;
        }

        $src = (string) $registered_style->src;

        if ($src !== '' && \stripos($src, 'Instrument+Sans') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Enqueue Instrument Sans when required and not already present.
 *
 * @param array<string, string> $tokens
 */
function maybe_enqueue_design_system_font(array $tokens): void
{
    if (!is_instrument_sans_required($tokens)) {
        return;
    }

    if (is_instrument_sans_already_loaded()) {
        return;
    }

    \wp_enqueue_style('must-hotel-booking-instrument-sans');
}

/**
 * Enqueue common design system assets and inline variables.
 */
function enqueue_design_system_common_assets(): void
{
    static $enqueued = false;

    if ($enqueued) {
        return;
    }

    if (!\wp_style_is('must-hotel-booking-design-system', 'registered')) {
        register_design_system_assets();
    }

    $tokens = get_resolved_design_tokens();

    \wp_enqueue_style('must-hotel-booking-design-system');
    \wp_add_inline_style('must-hotel-booking-design-system', build_design_system_css_variables($tokens));
    maybe_enqueue_design_system_font($tokens);

    $enqueued = true;
}

/**
 * Enqueue design system assets for frontend plugin UI.
 */
function enqueue_design_system_frontend_assets(): void
{
    if (\is_admin()) {
        return;
    }

    enqueue_design_system_common_assets();
}

/**
 * Enqueue design system assets for plugin admin pages.
 */
function enqueue_design_system_admin_assets(string $hook_suffix = ''): void
{
    if (!is_design_system_plugin_admin_page()) {
        return;
    }

    enqueue_design_system_common_assets();
}

\add_action('init', __NAMESPACE__ . '\register_design_system_assets');
\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_design_system_frontend_assets', 1);
\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_design_system_admin_assets', 1);
