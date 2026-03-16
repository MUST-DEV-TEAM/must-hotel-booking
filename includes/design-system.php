<?php

namespace MustHotelBooking\Core;

if (!\defined('ABSPATH')) {
    exit;
}

final class DesignSystem
{
    private const DEFAULT_FONT_FAMILY = 'Instrument Sans, sans-serif';

    /**
     * @return array<string, string>
     */
    public static function getColors(): array
    {
        $tokens = self::getResolvedTokens();
        $fallbacks = self::getFallbackColorMap();
        $elementor_overrides = \function_exists(__NAMESPACE__ . '\get_elementor_design_overrides')
            ? (array) get_elementor_design_overrides()
            : [];

        $primary = self::normalizeColorValue(
            (string) ($elementor_overrides['primary_color'] ?? ''),
            self::normalizeColorValue((string) ($tokens['primary_color'] ?? ''), $fallbacks['primary'])
        );
        $secondary = self::normalizeColorValue((string) ($tokens['secondary_color'] ?? ''), $fallbacks['secondary']);
        $text = self::normalizeColorValue((string) ($tokens['primary_black_color'] ?? ''), $fallbacks['text']);
        $surface = self::normalizeColorValue((string) ($tokens['accent_blue_color'] ?? ''), $fallbacks['surface']);
        $surface_alt = self::normalizeColorValue((string) ($tokens['light_blue_color'] ?? ''), $fallbacks['surface_alt']);
        $accent = self::normalizeColorValue((string) ($tokens['accent_gold_color'] ?? ''), $fallbacks['accent']);
        $border = self::normalizeColorValue((string) ($tokens['secondary_blue_color'] ?? ''), self::withAlpha($text, 0.18));
        $focus = self::normalizeColorValue((string) ($tokens['secondary_color'] ?? ''), $secondary);

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'text' => $text,
            'surface' => $surface,
            'surface_alt' => $surface_alt,
            'surface_muted' => self::withAlpha($surface, 0.72),
            'accent' => $accent,
            'border' => $border,
            'focus' => $focus,
            'on_primary' => self::getContrastingTextColor($primary),
            'on_surface' => self::getContrastingTextColor($surface),
            'on_text' => self::getContrastingTextColor($text),
        ];
    }

    public static function getColor(string $key): string
    {
        $colors = self::getColors();

        return isset($colors[$key]) ? (string) $colors[$key] : '';
    }

    public static function getFontFamily(): string
    {
        $tokens = self::getResolvedTokens();
        $fallback = self::DEFAULT_FONT_FAMILY;

        if (\function_exists(__NAMESPACE__ . '\normalize_design_font_family_value')) {
            return normalize_design_font_family_value((string) ($tokens['font_family'] ?? ''), $fallback);
        }

        $candidate = self::sanitizeFontFamily((string) ($tokens['font_family'] ?? ''));

        return $candidate !== '' ? $candidate : $fallback;
    }

    /**
     * @return array<string, string>|string
     */
    public static function getSpacing(string $key = '')
    {
        $spacing = [
            '2xs' => '4px',
            'xs' => '8px',
            'sm' => '12px',
            'md' => '16px',
            'lg' => '24px',
            'xl' => '32px',
            '2xl' => '48px',
            '3xl' => '64px',
        ];

        if ($key === '') {
            return $spacing;
        }

        return isset($spacing[$key]) ? $spacing[$key] : $spacing['md'];
    }

    /**
     * @return array<string, string>
     */
    public static function getTypography(string $scale = 'body_m'): array
    {
        $tokens = self::getResolvedTokens();
        $scale = \sanitize_key($scale);

        if ($scale === '') {
            $scale = 'body_m';
        }

        $size_key = $scale . '_size';
        $line_height_key = $scale . '_line_height';
        $weight_key = $scale . '_weight';

        return [
            'font_family' => self::getFontFamily(),
            'font_size' => isset($tokens[$size_key]) ? (string) $tokens[$size_key] : '16px',
            'line_height' => isset($tokens[$line_height_key]) ? (string) $tokens[$line_height_key] : '140%',
            'font_weight' => isset($tokens[$weight_key]) ? (string) $tokens[$weight_key] : '400',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getButtonStyles(string $variant = 'primary'): array
    {
        $colors = self::getColors();
        $spacing = (array) self::getSpacing();
        $typography = self::getTypography('button_m');
        $variant = \sanitize_key($variant);

        $background = 'transparent';
        $color = $colors['text'];
        $border = $colors['text'];

        if ($variant === 'ghost') {
            $background = 'transparent';
            $color = $colors['text'];
            $border = $colors['border'];
        } elseif ($variant === 'secondary') {
            $background = $colors['primary'];
            $color = $colors['on_primary'];
            $border = $colors['primary'];
        }

        return [
            'background' => $background,
            'color' => $color,
            'border_color' => $border,
            'border_radius' => '4px',
            'padding_x' => (string) ($spacing['lg'] ?? '24px'),
            'padding_y' => (string) ($spacing['sm'] ?? '12px'),
            'font_family' => (string) $typography['font_family'],
            'font_size' => (string) $typography['font_size'],
            'line_height' => (string) $typography['line_height'],
            'font_weight' => (string) $typography['font_weight'],
            'focus_ring' => $colors['focus'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getFormStyles(): array
    {
        $colors = self::getColors();
        $spacing = (array) self::getSpacing();
        $typography = self::getTypography('body_m');

        return [
            'background' => $colors['surface'],
            'color' => $colors['text'],
            'border_color' => $colors['border'],
            'border_radius' => '4px',
            'padding_x' => (string) ($spacing['md'] ?? '16px'),
            'padding_y' => (string) ($spacing['sm'] ?? '12px'),
            'focus_ring' => $colors['focus'],
            'placeholder_color' => self::withAlpha($colors['text'], 0.58),
            'font_family' => (string) $typography['font_family'],
            'font_size' => (string) $typography['font_size'],
            'line_height' => (string) $typography['line_height'],
            'font_weight' => (string) $typography['font_weight'],
        ];
    }

    /**
     * @param array<string, string> $tokens
     * @return array<string, string>
     */
    public static function getCssVariableMap(array $tokens = []): array
    {
        if (empty($tokens)) {
            $tokens = self::getResolvedTokens();
        }

        $colors = self::getColors();
        $spacing = (array) self::getSpacing();
        $button = self::getButtonStyles();
        $form = self::getFormStyles();

        return [
            '--must-font-family' => self::formatFontFamilyCss(self::getFontFamily()),
            '--must-h1-size' => (string) ($tokens['h1_size'] ?? '54px'),
            '--must-h1-line-height' => (string) ($tokens['h1_line_height'] ?? '110%'),
            '--must-h1-weight' => (string) ($tokens['h1_weight'] ?? '400'),
            '--must-h2-size' => (string) ($tokens['h2_size'] ?? '54px'),
            '--must-h2-line-height' => (string) ($tokens['h2_line_height'] ?? '110%'),
            '--must-h2-weight' => (string) ($tokens['h2_weight'] ?? '400'),
            '--must-h3-size' => (string) ($tokens['h3_size'] ?? '32px'),
            '--must-h3-line-height' => (string) ($tokens['h3_line_height'] ?? '110%'),
            '--must-h3-weight' => (string) ($tokens['h3_weight'] ?? '400'),
            '--must-h4-size' => (string) ($tokens['h4_size'] ?? '24px'),
            '--must-h4-line-height' => (string) ($tokens['h4_line_height'] ?? '110%'),
            '--must-h4-weight' => (string) ($tokens['h4_weight'] ?? '400'),
            '--must-h5-size' => (string) ($tokens['h5_size'] ?? '20px'),
            '--must-h5-line-height' => (string) ($tokens['h5_line_height'] ?? '110%'),
            '--must-h5-weight' => (string) ($tokens['h5_weight'] ?? '400'),
            '--must-h6-size' => (string) ($tokens['h6_size'] ?? '18px'),
            '--must-h6-line-height' => (string) ($tokens['h6_line_height'] ?? '110%'),
            '--must-h6-weight' => (string) ($tokens['h6_weight'] ?? '400'),
            '--must-body-l-size' => (string) ($tokens['body_l_size'] ?? '18px'),
            '--must-body-l-line-height' => (string) ($tokens['body_l_line_height'] ?? '140%'),
            '--must-body-l-weight' => (string) ($tokens['body_l_weight'] ?? '400'),
            '--must-body-m-size' => (string) ($tokens['body_m_size'] ?? '16px'),
            '--must-body-m-line-height' => (string) ($tokens['body_m_line_height'] ?? '140%'),
            '--must-body-m-weight' => (string) ($tokens['body_m_weight'] ?? '400'),
            '--must-body-s-size' => (string) ($tokens['body_s_size'] ?? '14px'),
            '--must-body-s-line-height' => (string) ($tokens['body_s_line_height'] ?? '140%'),
            '--must-body-s-weight' => (string) ($tokens['body_s_weight'] ?? '400'),
            '--must-body-xs-size' => (string) ($tokens['body_xs_size'] ?? '12px'),
            '--must-body-xs-line-height' => (string) ($tokens['body_xs_line_height'] ?? '140%'),
            '--must-body-xs-weight' => (string) ($tokens['body_xs_weight'] ?? '400'),
            '--must-body-xxs-size' => (string) ($tokens['body_xxs_size'] ?? '10px'),
            '--must-body-xxs-line-height' => (string) ($tokens['body_xxs_line_height'] ?? '140%'),
            '--must-body-xxs-weight' => (string) ($tokens['body_xxs_weight'] ?? '400'),
            '--must-body-l' => (string) ($tokens['body_l_size'] ?? '18px'),
            '--must-body-m' => (string) ($tokens['body_m_size'] ?? '16px'),
            '--must-body-s' => (string) ($tokens['body_s_size'] ?? '14px'),
            '--must-body-xs' => (string) ($tokens['body_xs_size'] ?? '12px'),
            '--must-body-xxs' => (string) ($tokens['body_xxs_size'] ?? '10px'),
            '--must-button-l-size' => (string) ($tokens['button_l_size'] ?? '20px'),
            '--must-button-l-line-height' => (string) ($tokens['button_l_line_height'] ?? '120%'),
            '--must-button-l-weight' => (string) ($tokens['button_l_weight'] ?? '500'),
            '--must-button-m-size' => (string) ($tokens['button_m_size'] ?? '16px'),
            '--must-button-m-line-height' => (string) ($tokens['button_m_line_height'] ?? '120%'),
            '--must-button-m-weight' => (string) ($tokens['button_m_weight'] ?? '500'),
            '--must-button-s-size' => (string) ($tokens['button_s_size'] ?? '14px'),
            '--must-button-s-line-height' => (string) ($tokens['button_s_line_height'] ?? '120%'),
            '--must-button-s-weight' => (string) ($tokens['button_s_weight'] ?? '500'),
            '--must-menu-tabs-size' => (string) ($tokens['menu_tabs_size'] ?? '16px'),
            '--must-menu-tabs-line-height' => (string) ($tokens['menu_tabs_line_height'] ?? '120%'),
            '--must-menu-tabs-weight' => (string) ($tokens['menu_tabs_weight'] ?? '500'),
            '--must-primary' => $colors['primary'],
            '--must-secondary' => $colors['secondary'],
            '--must-black' => $colors['text'],
            '--must-text' => $colors['text'],
            '--must-surface' => $colors['surface'],
            '--must-surface-alt' => $colors['surface_alt'],
            '--must-surface-muted' => $colors['surface_muted'],
            '--must-border' => $colors['border'],
            '--must-focus' => $colors['focus'],
            '--must-on-primary' => $colors['on_primary'],
            '--must-on-surface' => $colors['on_surface'],
            '--must-on-text' => $colors['on_text'],
            '--must-accent-blue' => self::normalizeColorValue((string) ($tokens['accent_blue_color'] ?? ''), $colors['surface']),
            '--must-light-blue' => self::normalizeColorValue((string) ($tokens['light_blue_color'] ?? ''), $colors['surface_alt']),
            '--must-secondary-blue' => self::normalizeColorValue((string) ($tokens['secondary_blue_color'] ?? ''), $colors['border']),
            '--must-gold' => self::normalizeColorValue((string) ($tokens['accent_gold_color'] ?? ''), $colors['accent']),
            '--must-space-2xs' => (string) ($spacing['2xs'] ?? '4px'),
            '--must-space-xs' => (string) ($spacing['xs'] ?? '8px'),
            '--must-space-sm' => (string) ($spacing['sm'] ?? '12px'),
            '--must-space-md' => (string) ($spacing['md'] ?? '16px'),
            '--must-space-lg' => (string) ($spacing['lg'] ?? '24px'),
            '--must-space-xl' => (string) ($spacing['xl'] ?? '32px'),
            '--must-space-2xl' => (string) ($spacing['2xl'] ?? '48px'),
            '--must-space-3xl' => (string) ($spacing['3xl'] ?? '64px'),
            '--must-button-bg' => (string) $button['background'],
            '--must-button-text' => (string) $button['color'],
            '--must-button-border' => (string) $button['border_color'],
            '--must-button-radius' => (string) $button['border_radius'],
            '--must-button-padding-inline' => (string) $button['padding_x'],
            '--must-button-padding-block' => (string) $button['padding_y'],
            '--must-button-focus' => (string) $button['focus_ring'],
            '--must-form-bg' => (string) $form['background'],
            '--must-form-color' => (string) $form['color'],
            '--must-form-border' => (string) $form['border_color'],
            '--must-form-radius' => (string) $form['border_radius'],
            '--must-form-padding-inline' => (string) $form['padding_x'],
            '--must-form-padding-block' => (string) $form['padding_y'],
            '--must-form-placeholder' => (string) $form['placeholder_color'],
            '--must-form-focus' => (string) $form['focus_ring'],
        ];
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function buildWidgetInlineStyle(array $overrides = []): string
    {
        $variables = [];

        $font_family = self::sanitizeFontFamily((string) ($overrides['font_family'] ?? ''));

        if ($font_family !== '') {
            $variables['--must-widget-font-family'] = self::formatFontFamilyCss($font_family);
        }

        $surface = self::sanitizeWidgetColor((string) ($overrides['surface'] ?? ''));
        $text = self::sanitizeWidgetColor((string) ($overrides['text'] ?? ''));
        $border = self::sanitizeWidgetColor((string) ($overrides['border'] ?? ''));
        $primary = self::sanitizeWidgetColor((string) ($overrides['primary'] ?? ''));
        $surface_alt = self::sanitizeWidgetColor((string) ($overrides['surface_alt'] ?? ''));
        $button_background = self::sanitizeWidgetColor((string) ($overrides['button_background'] ?? ''));
        $button_text = self::sanitizeWidgetColor((string) ($overrides['button_text'] ?? ''));
        $button_border = self::sanitizeWidgetColor((string) ($overrides['button_border'] ?? ''));
        $focus = self::sanitizeWidgetColor((string) ($overrides['focus'] ?? ''));

        if ($surface !== '') {
            $variables['--must-widget-surface'] = $surface;
            $variables['--must-widget-on-surface'] = $text !== '' ? $text : self::getContrastingTextColor($surface);
        }

        if ($surface_alt !== '') {
            $variables['--must-widget-surface-alt'] = $surface_alt;
        }

        if ($text !== '') {
            $variables['--must-widget-text'] = $text;

            if (!isset($variables['--must-widget-on-surface'])) {
                $variables['--must-widget-on-surface'] = $text;
            }
        }

        if ($border !== '') {
            $variables['--must-widget-border'] = $border;
        }

        if ($primary !== '') {
            $variables['--must-widget-primary'] = $primary;

            if ($button_border === '') {
                $variables['--must-widget-button-border'] = $primary;
            }

            if ($focus === '') {
                $variables['--must-widget-focus'] = $primary;
            }
        }

        if ($button_background !== '') {
            $variables['--must-widget-button-bg'] = $button_background;
        }

        if ($button_text !== '') {
            $variables['--must-widget-button-text'] = $button_text;
        }

        if ($button_border !== '') {
            $variables['--must-widget-button-border'] = $button_border;
        }

        if ($focus !== '') {
            $variables['--must-widget-focus'] = $focus;
        }

        if (empty($variables)) {
            return '';
        }

        $declarations = [];

        foreach ($variables as $name => $value) {
            $declarations[] = $name . ': ' . $value;
        }

        return \implode('; ', $declarations);
    }

    /**
     * @return array<string, string>
     */
    private static function getResolvedTokens(): array
    {
        if (\function_exists(__NAMESPACE__ . '\get_resolved_design_tokens')) {
            $tokens = get_resolved_design_tokens();

            if (\is_array($tokens)) {
                return $tokens;
            }
        }

        return [
            'font_family' => self::DEFAULT_FONT_FAMILY,
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
            'primary_black_color' => '#141414',
            'accent_blue_color' => '#FFFFFF',
            'light_blue_color' => '#E7E8FF',
            'secondary_blue_color' => '#D9DEE5',
            'accent_gold_color' => '#DA1E28',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getFallbackColorMap(): array
    {
        return [
            'primary' => '#F5F2E5',
            'secondary' => '#C1FC7E',
            'text' => '#141414',
            'surface' => '#FFFFFF',
            'surface_alt' => '#E7E8FF',
            'accent' => '#DA1E28',
        ];
    }

    private static function normalizeColorValue(string $value, string $fallback): string
    {
        $value = \trim($value);

        if ($value === '') {
            return $fallback;
        }

        $hex = \sanitize_hex_color($value);

        if (\is_string($hex) && $hex !== '') {
            return \strtoupper($hex);
        }

        if (\preg_match('/^rgba?\([^)]+\)$/i', $value) || \preg_match('/^hsla?\([^)]+\)$/i', $value)) {
            return $value;
        }

        if (\in_array(\strtolower($value), ['transparent', 'inherit', 'currentcolor'], true)) {
            return $value;
        }

        return $fallback;
    }

    private static function sanitizeWidgetColor(string $value): string
    {
        return self::normalizeColorValue($value, '');
    }

    private static function sanitizeFontFamily(string $value): string
    {
        if (\function_exists(__NAMESPACE__ . '\sanitize_design_font_family_candidate')) {
            return sanitize_design_font_family_candidate($value);
        }

        $candidate = \trim(\wp_strip_all_tags($value));
        $candidate = (string) \preg_replace('/[{};<>]/', '', $candidate);
        $candidate = (string) \preg_replace('/\s+/', ' ', $candidate);

        return \trim($candidate);
    }

    private static function formatFontFamilyCss(string $fontFamily): string
    {
        if (\function_exists(__NAMESPACE__ . '\format_design_system_font_family_css')) {
            return format_design_system_font_family_css($fontFamily);
        }

        return $fontFamily !== '' ? $fontFamily : self::DEFAULT_FONT_FAMILY;
    }

    private static function getContrastingTextColor(string $background): string
    {
        $hex = \sanitize_hex_color($background);

        if (!\is_string($hex) || $hex === '') {
            return '#FFFFFF';
        }

        $hex = \ltrim($hex, '#');

        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $red = \hexdec(\substr($hex, 0, 2));
        $green = \hexdec(\substr($hex, 2, 2));
        $blue = \hexdec(\substr($hex, 4, 2));
        $luminance = ((0.299 * $red) + (0.587 * $green) + (0.114 * $blue)) / 255;

        return $luminance > 0.62 ? '#141414' : '#FFFFFF';
    }

    private static function withAlpha(string $color, float $alpha): string
    {
        $hex = \sanitize_hex_color($color);
        $alpha = \max(0.0, \min(1.0, $alpha));

        if (!\is_string($hex) || $hex === '') {
            return $color;
        }

        $hex = \ltrim($hex, '#');

        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return \sprintf(
            'rgba(%d, %d, %d, %s)',
            \hexdec(\substr($hex, 0, 2)),
            \hexdec(\substr($hex, 2, 2)),
            \hexdec(\substr($hex, 4, 2)),
            \number_format($alpha, 2, '.', '')
        );
    }
}
