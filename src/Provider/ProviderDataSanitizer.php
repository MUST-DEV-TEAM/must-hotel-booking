<?php

namespace MustHotelBooking\Provider;

final class ProviderDataSanitizer
{
    /**
     * @param mixed $value
     * @return mixed
     */
    public static function sanitize($value, string $key = '')
    {
        if (self::isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (\is_array($value)) {
            $sanitized = [];

            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = self::sanitize(
                    $childValue,
                    \is_string($childKey) ? $childKey : ''
                );
            }

            return $sanitized;
        }

        if (\is_object($value)) {
            return self::sanitize((array) $value, $key);
        }

        if (\is_string($value)) {
            return self::sanitizeText($value);
        }

        return $value;
    }

    public static function sanitizeText(string $value): string
    {
        $value = self::sanitizeUrls($value);
        $value = self::sanitizeQueryLikeText($value);
        $value = (string) \preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $value);
        $value = (string) \preg_replace('/(sk_live_|sk_test_|whsec_|pk_live_|pk_test_)[A-Za-z0-9_]+/', '[key-redacted]', $value);
        $value = (string) \preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email-redacted]', $value);
        $value = (string) \preg_replace('/\b(phone|tel|mobile|whatsapp)\s*[:=]\s*\+?\d[\d\s().\-]{7,}\d\b/i', '$1: [phone-redacted]', $value);
        $value = (string) \preg_replace('/(?<![\d:])\+\d[\d\s().\-]{7,}\d(?![\d:])/', '[phone-redacted]', $value);
        $value = (string) \preg_replace('/\b(self[_ -]?service[_ -]?pin|door[_ -]?code|pin)\s*[:=]\s*[A-Za-z0-9\-]{3,}\b/i', '$1: [redacted]', $value);

        return $value;
    }

    private static function sanitizeQueryLikeText(string $value): string
    {
        $sanitized = preg_replace_callback(
            '/((?:^|[?&\s])(?:access(?:_|%5f)token|cancel(?:_|%5f)token|auth(?:_|%5f)token|refresh(?:_|%5f)token|id(?:_|%5f)token|authorization|client(?:_|%5f)secret|api(?:_|%5f)key|key(?:_|%5f)secret|webhook(?:_|%5f)secret|payment(?:_|%5f)token|token)(?:=|%3d))[^&#\s"\'<>]*/i',
            static function (array $matches): string {
                $prefix = (string) ($matches[1] ?? '');
                $equalsPosition = strpos($prefix, '=');
                if ($equalsPosition === false) {
                    $equalsPosition = stripos($prefix, '%3d');
                    if ($equalsPosition === false) {
                        return $prefix . rawurlencode('[REDACTED]');
                    }
                    return substr($prefix, 0, $equalsPosition + 3) . rawurlencode('[REDACTED]');
                }
                return substr($prefix, 0, $equalsPosition + 1) . rawurlencode('[REDACTED]');
            },
            $value
        );

        return is_string($sanitized) ? $sanitized : $value;
    }

    private static function sanitizeUrls(string $value): string
    {
        $sanitized = \preg_replace_callback(
            '/https?:\/\/[^\s<>"\']+/i',
            static function (array $matches): string {
                return self::sanitizeUrlQuery((string) ($matches[0] ?? ''));
            },
            $value
        );

        return \is_string($sanitized) ? $sanitized : $value;
    }

    private static function sanitizeUrlQuery(string $url): string
    {
        $fragment = '';
        $fragmentPosition = \strpos($url, '#');
        if ($fragmentPosition !== false) {
            $fragment = \substr($url, $fragmentPosition);
            $url = \substr($url, 0, $fragmentPosition);
        }

        $queryPosition = \strpos($url, '?');
        if ($queryPosition === false) {
            return $url . $fragment;
        }

        $prefix = \substr($url, 0, $queryPosition + 1);
        $segments = \explode('&', \substr($url, $queryPosition + 1));
        foreach ($segments as $index => $segment) {
            $equalsPosition = \strpos($segment, '=');
            $name = $equalsPosition === false ? $segment : \substr($segment, 0, $equalsPosition);
            if (!self::isSensitiveUrlParameter($name)) {
                continue;
            }

            $segments[$index] = $name . '=' . \rawurlencode('[REDACTED]');
        }

        return $prefix . \implode('&', $segments) . $fragment;
    }

    private static function isSensitiveUrlParameter(string $name): bool
    {
        $decoded = \strtolower(\rawurldecode(\str_replace('+', ' ', $name)));
        $decoded = \strtolower(\rawurldecode($decoded));

        return \in_array($decoded, [
            'access_token',
            'cancel_token',
            'token',
            'auth_token',
            'authorization',
            'refresh_token',
            'id_token',
            'client_secret',
            'api_key',
            'key_secret',
            'webhook_secret',
            'payment_token',
        ], true);
    }

    public static function maskIdentifier(string $value): string
    {
        $value = \trim($value);
        $length = \strlen($value);

        if ($length === 0) {
            return '';
        }

        if ($length <= 4) {
            return \str_repeat('*', $length);
        }

        return \substr($value, 0, 2) . \str_repeat('*', \max(3, $length - 4)) . \substr($value, -2);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = \strtolower(\trim($key));

        if ($key === '') {
            return false;
        }

        return \preg_match(
            '/(^|_|\-)(authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|key[_-]?secret|webhook[_-]?secret|card([_-]?(number|no))?|pan([_-]?number)?|cvv|cvc|(guest[_-]?)?(email|phone|telephone|mobile|whatsapp|first[_-]?name|last[_-]?name|full[_-]?name)|self[_-]?service[_-]?(key|pin)|door[_-]?(code|codes)|pin)$/i',
            $key
        ) === 1;
    }
}
