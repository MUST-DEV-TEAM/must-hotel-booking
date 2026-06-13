<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockEndpointRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            'booking_create' => self::definition('booking_create', 'Booking create', 'POST', '/bookings/', 'pms_api', 'Creates a Clock booking/reservation from website checkout.', '', true, []),
            'booking_folios_list' => self::definition('booking_folios_list', 'Booking folios list', 'GET', '/bookings/{booking_id}/folios', 'pms_api', 'Lists folios attached to a Clock booking.', '', true, ['booking_id'], ['booking_id']),
            'booking_deposit_folio_create' => self::definition('booking_deposit_folio_create', 'Booking deposit folio create', 'POST', '/bookings/{booking_id}/folios/', 'pms_api', 'Creates an open deposit folio on a Clock booking using booking_folio.deposit=true.', 'booking_folio create access', true, ['booking_id'], ['booking_id']),
            'folio_view' => self::definition('folio_view', 'Folio view', 'GET', '/folios/{folio_id}', 'base_api', 'Reads a Clock folio for payment/refund verification.', '', true, ['folio_id'], ['folio_id']),
            'folio_credit_item_create' => self::definition('folio_credit_item_create', 'Folio credit item create', 'POST', '/folios/{folio_id}/credit_items', 'base_api', 'Legacy website payment/refund posting as a folio credit item.', 'Folio payment write access', false, ['folio_id'], ['folio_id']),
            'payment_sub_types' => self::definition('payment_sub_types', 'Payment subtypes', 'GET', '/payment_sub_types', 'base_api', 'Reads configured Clock payment subtypes.', '', false, []),
            'booking_deposit_payment_create' => self::definition('booking_deposit_payment_create', 'Booking deposit payment create', 'POST', '/folios/{folio_id}/credit_items', 'base_api', 'Records an already-captured website payment on an open Clock deposit folio.', 'credit_item payment create access', false, ['folio_id'], ['folio_id']),
            'booking_deposit_payment_refund' => self::definition('booking_deposit_payment_refund', 'Booking deposit payment refund', 'POST', '/folios/{folio_id}/credit_items', 'base_api', 'Records a refund of an open Clock deposit by posting a negative payment to the deposit folio.', 'credit_item payment create access', false, ['folio_id'], ['folio_id']),
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        $definitions = self::definitions();
        $key = \sanitize_key($key);

        return isset($definitions[$key]) ? $definitions[$key] : [];
    }

    /** @return array<string, string> */
    public static function defaultTemplates(): array
    {
        $templates = [];

        foreach (self::definitions() as $key => $definition) {
            $templates[$key] = (string) ($definition['default_template'] ?? '');
        }

        return $templates;
    }

    /** @param array<string, mixed> $overrides @return array<string, string> */
    public static function normalizeOverrides(array $overrides, bool $strict = false): array
    {
        $normalized = [];

        foreach (self::definitions() as $key => $definition) {
            $value = isset($overrides[$key]) ? \trim((string) $overrides[$key]) : '';
            $normalized[$key] = $value !== '' ? self::normalizeTemplate($key, $value, $strict) : '';
            unset($definition);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $overrides @return array<int, string> */
    public static function validateOverrides(array $overrides): array
    {
        $errors = [];

        foreach (self::definitions() as $key => $definition) {
            $value = isset($overrides[$key]) ? \trim((string) $overrides[$key]) : '';

            if ($value === '') {
                continue;
            }

            $error = self::validateTemplate($key, $value);

            if ($error !== '') {
                $errors[] = \sprintf(
                    /* translators: 1: endpoint label, 2: validation message. */
                    \__('Clock endpoint override "%1$s" is invalid: %2$s', 'must-hotel-booking'),
                    (string) ($definition['label'] ?? $key),
                    $error
                );
            }
        }

        return $errors;
    }

    public static function resolveTemplate(string $key): string
    {
        $definition = self::get($key);

        if (empty($definition)) {
            return '';
        }

        $settings = ClockConfig::settings();
        $overrides = isset($settings['clock_endpoint_overrides']) && \is_array($settings['clock_endpoint_overrides'])
            ? $settings['clock_endpoint_overrides']
            : [];
        $override = isset($overrides[$key]) ? \trim((string) $overrides[$key]) : '';

        return $override !== '' ? $override : (string) ($definition['default_template'] ?? '');
    }

    /** @param array<string, scalar> $placeholders */
    public static function resolvePath(string $key, array $placeholders = []): string
    {
        $template = self::resolveTemplate($key);

        if ($template === '') {
            return '';
        }

        foreach ($placeholders as $name => $value) {
            $template = \str_replace('{' . $name . '}', \rawurlencode((string) $value), $template);
        }

        return ClockConfig::normalizeOptionalPath($template);
    }

    public static function apiType(string $key): string
    {
        $definition = self::get($key);

        return ClockEndpointResolver::normalizeApiType((string) ($definition['api_area'] ?? 'pms_api'));
    }

    private static function normalizeTemplate(string $key, string $template, bool $strict): string
    {
        if (self::validateTemplate($key, $template) !== '') {
            return $strict ? '' : '';
        }

        return '/' . \ltrim(\trim($template), '/');
    }

    /** @return array<string, mixed> */
    private static function definition(string $key, string $label, string $method, string $template, string $apiArea, string $description, string $rights, bool $required, array $allowedPlaceholders, array $requiredPlaceholders = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'method' => $method,
            'default_template' => $template,
            'api_area' => $apiArea,
            'description' => $description,
            'required_rights' => $rights,
            'required' => $required,
            'allowed_placeholders' => $allowedPlaceholders,
            'required_placeholders' => $requiredPlaceholders,
        ];
    }

    private static function validateTemplate(string $key, string $template): string
    {
        $definition = self::get($key);

        if (empty($definition)) {
            return \__('unknown endpoint key.', 'must-hotel-booking');
        }

        $template = \trim($template);

        if ($template === '') {
            return '';
        }

        if (\preg_match('/[\x00-\x1F\x7F]/', $template) === 1) {
            return \__('control characters are not allowed.', 'must-hotel-booking');
        }

        $decoded = \rawurldecode($template);

        if (\preg_match('#^https?://#i', $template) === 1 || \preg_match('#^https?://#i', $decoded) === 1) {
            return \__('absolute URLs are not allowed.', 'must-hotel-booking');
        }

        if (\strpos($template, '//') === 0 || \strpos($decoded, '//') === 0) {
            return \__('protocol-relative URLs are not allowed.', 'must-hotel-booking');
        }

        if (\stripos($decoded, 'javascript:') !== false) {
            return \__('javascript URLs are not allowed.', 'must-hotel-booking');
        }

        if (\strpos($template, '/') !== 0) {
            return \__('the path must start with /.', 'must-hotel-booking');
        }

        if (\preg_match('#(^|/)\.\.(/|$)#', $decoded) === 1 || \strpos($decoded, '../') !== false || \strpos($decoded, '..\\') !== false) {
            return \__('directory traversal is not allowed.', 'must-hotel-booking');
        }

        if (\preg_match('~^/[^/?#]+:[^/?#]+@~', $decoded) === 1 || \strpos($decoded, '@') !== false) {
            return \__('credentials are not allowed in endpoint paths.', 'must-hotel-booking');
        }

        \preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $template, $matches);
        $used = isset($matches[1]) && \is_array($matches[1]) ? \array_values(\array_unique($matches[1])) : [];
        $allowed = isset($definition['allowed_placeholders']) && \is_array($definition['allowed_placeholders'])
            ? \array_map('strval', $definition['allowed_placeholders'])
            : [];
        $required = isset($definition['required_placeholders']) && \is_array($definition['required_placeholders'])
            ? \array_map('strval', $definition['required_placeholders'])
            : [];

        foreach ($used as $placeholder) {
            if (!\in_array($placeholder, $allowed, true)) {
                return \sprintf(
                    /* translators: %s is an endpoint path placeholder. */
                    \__('unknown placeholder {%s}.', 'must-hotel-booking'),
                    $placeholder
                );
            }
        }

        foreach ($required as $placeholder) {
            if (!\in_array($placeholder, $used, true)) {
                return \sprintf(
                    /* translators: %s is an endpoint path placeholder. */
                    \__('required placeholder {%s} is missing.', 'must-hotel-booking'),
                    $placeholder
                );
            }
        }

        return '';
    }
}
