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
            return \esc_url_raw($path);
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
    public static function configurationErrors(): array
    {
        $errors = [];

        $hasExplicitBaseUrl = ClockConfig::pmsApiUrl() !== '' || ClockConfig::baseApiUrl() !== '';

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

        if (!\in_array(ClockConfig::apiType(), self::API_TYPES, true)) {
            $errors[] = \__('Clock API type is invalid.', 'must-hotel-booking');
        }

        return $errors;
    }
}
