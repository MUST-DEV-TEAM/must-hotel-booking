<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockEndpointResolver
{
    /** @var array<int, string> */
    private const API_TYPES = ['base_api', 'pms_api', 'pos_api', 'yield_management_api'];

    public static function normalizeRegion(string $region): string
    {
        $region = \strtolower(\trim($region));
        $region = (string) \preg_replace('/[^a-z0-9\-]/', '', $region);

        return \substr($region, 0, 60);
    }

    public static function normalizeApiType(string $apiType): string
    {
        $apiType = \sanitize_key($apiType);

        return \in_array($apiType, self::API_TYPES, true) ? $apiType : 'pms_api';
    }

    public static function normalizeNumericId(string $id): string
    {
        return \substr((string) \preg_replace('/\D+/', '', \trim($id)), 0, 30);
    }

    public static function buildUrl(string $apiType, string $path): string
    {
        $apiType = self::normalizeApiType($apiType !== '' ? $apiType : ClockConfig::apiType());

        if (\preg_match('#^https?://#i', $path) === 1) {
            return self::allowedAbsoluteUrl($apiType, $path);
        }

        $explicitBaseUrl = ClockConfig::apiBaseUrlForType($apiType);

        if ($explicitBaseUrl !== '') {
            $path = self::normalizePath($path);

            return $explicitBaseUrl . ($path === '/' ? '' : $path);
        }

        $region = ClockConfig::region();
        $subscriptionId = ClockConfig::subscriptionId();
        $accountId = ClockConfig::accountId();

        if ($region === '' || $subscriptionId === '' || $accountId === '') {
            return '';
        }

        $path = self::normalizePath($path);
        $base = 'https://' . $region . '.clock-software.com/' . $apiType . '/' . $subscriptionId . '/' . $accountId;

        return $base . ($path === '/' ? '' : $path);
    }

    /**
     * Endpoint paths may be stored as absolute URLs on legacy installations.
     * Only accept them when they remain inside the configured API base. This
     * prevents an administrator-supplied path from turning provider calls into
     * arbitrary outbound requests while preserving supported custom proxies.
     */
    public static function allowedAbsoluteUrl(string $apiType, string $url): string
    {
        $apiType = self::normalizeApiType($apiType);
        $url = \trim($url);
        $parts = \wp_parse_url($url);

        if (!\is_array($parts)) {
            $parts = \parse_url($url);
        }

        if (!\is_array($parts)
            || \strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || (string) ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || self::hasUnsafePathSegments((string) ($parts['path'] ?? ''))) {
            return '';
        }

        $configuredBase = ClockConfig::apiBaseUrlForType($apiType);

        if ($configuredBase === '') {
            $region = ClockConfig::region();
            $subscriptionId = ClockConfig::subscriptionId();
            $accountId = ClockConfig::accountId();

            if ($region === '' || $subscriptionId === '' || $accountId === '') {
                return '';
            }

            $configuredBase = 'https://' . $region . '.clock-software.com/' . $apiType . '/' . $subscriptionId . '/' . $accountId;
        }

        return self::urlWithinBase($url, $configuredBase) ? \esc_url_raw($url) : '';
    }

    public static function normalizePath(string $path): string
    {
        $path = \trim($path);

        if ($path === '') {
            return '/';
        }

        if (\preg_match('#^https?://#i', $path) === 1) {
            return \esc_url_raw($path);
        }

        $path = '/' . \trim(\str_replace('\\', '/', $path), '/');
        $path = (string) \preg_replace('#/+#', '/', $path);

        return $path;
    }

    /** @return array<int, string> */
    public static function configurationErrors(string $apiType = ''): array
    {
        $errors = [];
        $apiType = self::normalizeApiType($apiType !== '' ? $apiType : ClockConfig::apiType());

        $hasExplicitBaseUrl = ClockConfig::apiBaseUrlForType($apiType) !== '';

        if ($hasExplicitBaseUrl) {
            return $errors;
        }

        if (ClockConfig::region() === '') {
            $errors[] = \__('Clock subscription region is missing.', 'must-hotel-booking');
        }

        if (ClockConfig::subscriptionId() === '') {
            $errors[] = \__('Clock subscription ID is missing.', 'must-hotel-booking');
        }

        if (ClockConfig::accountId() === '') {
            $errors[] = \__('Clock account ID is missing.', 'must-hotel-booking');
        }

        if (!\in_array($apiType, self::API_TYPES, true)) {
            $errors[] = \__('Clock API type is invalid.', 'must-hotel-booking');
        }

        return $errors;
    }

    private static function urlWithinBase(string $url, string $base): bool
    {
        $urlParts = \wp_parse_url($url);
        $baseParts = \wp_parse_url($base);

        if (!\is_array($urlParts) || !\is_array($baseParts)) {
            return false;
        }

        $urlScheme = \strtolower((string) ($urlParts['scheme'] ?? ''));
        $baseScheme = \strtolower((string) ($baseParts['scheme'] ?? ''));
        $urlHost = \strtolower((string) ($urlParts['host'] ?? ''));
        $baseHost = \strtolower((string) ($baseParts['host'] ?? ''));
        $urlPort = (int) ($urlParts['port'] ?? ($urlScheme === 'https' ? 443 : 80));
        $basePort = (int) ($baseParts['port'] ?? ($baseScheme === 'https' ? 443 : 80));

        if ($urlScheme !== $baseScheme || $urlHost !== $baseHost || $urlPort !== $basePort) {
            return false;
        }

        $basePath = '/' . \trim((string) ($baseParts['path'] ?? ''), '/');
        $urlPath = '/' . \trim((string) ($urlParts['path'] ?? ''), '/');

        return $urlPath === $basePath || \strpos($urlPath, \rtrim($basePath, '/') . '/') === 0;
    }

    public static function hasUnsafePathSegments(string $path): bool
    {
        $decoded = $path;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $next = \rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        $decoded = \str_replace('\\', '/', $decoded);

        foreach (\explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return \strpos($decoded, "\0") !== false;
    }
}
