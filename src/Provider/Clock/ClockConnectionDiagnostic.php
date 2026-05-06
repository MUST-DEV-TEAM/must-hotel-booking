<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockConnectionDiagnostic
{
    /**
     * @return array<string, mixed>
     */
    public static function getConfigSummary(): array
    {
        return ClockConfig::summary();
    }

    /**
     * @return array<string, mixed>
     */
    public static function testConnection(): array
    {
        $errors = ClockConfig::configurationErrors();

        if (!empty($errors)) {
            return [
                'success' => false,
                'status' => ClockConfig::isEnabled() ? 'config_missing' : 'disabled',
                'message' => \implode(' ', $errors),
                'http_status' => 0,
                'duration_ms' => 0,
                'summary' => ClockConfig::summary(),
            ];
        }

        $testPath = ClockConfig::connectionPath();
        $testUrl = ClockEndpointResolver::buildUrl('pms_api', $testPath);

        if ($testUrl === '') {
            return [
                'success' => false,
                'status' => 'config_missing',
                'message' => \__('Clock PMS API URL could not be built from the configured region, subscription ID, and account ID.', 'must-hotel-booking'),
                'http_status' => 0,
                'duration_ms' => 0,
                'summary' => ClockConfig::summary(),
            ];
        }

        $response = (new ClockApiClient())->get($testPath, [], 'clock.connection_test.room_types', [
            'api_type' => 'pms_api',
            'endpoint_name' => 'room_types',
        ]);

        if ($response->isSuccess()) {
            return [
                'success' => true,
                'status' => 'connected',
                'message' => \__('Clock PMS API connection succeeded. The room_types catalog endpoint is reachable with Digest authentication.', 'must-hotel-booking'),
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $response->getDurationMs(),
                'summary' => ClockConfig::summary(),
            ];
        }

        if ($response->isAuthFailure()) {
            $status = 'auth_failed';
            $message = \__('Clock rejected the configured credentials.', 'must-hotel-booking');
        } elseif ($response->isForbidden()) {
            $status = 'forbidden';
            $message = \__('Clock rejected the request because the API user lacks permission or the WAF blocked the request.', 'must-hotel-booking');
        } elseif ($response->getStatusCode() === 404) {
            $status = 'bad_endpoint';
            $message = \__('Clock connection path was not found. Check region, API type, subscription ID, account ID, and path.', 'must-hotel-booking');
        } elseif ($response->getStatusCode() === 422) {
            $status = 'validation_error';
            $message = \__('Clock rejected the request parameters.', 'must-hotel-booking');
        } elseif ($response->isRateLimited()) {
            $status = 'rate_limited';
            $message = \__('Clock API rate limit was reached. Retry later.', 'must-hotel-booking');
        } elseif ($response->getStatusCode() >= 500) {
            $status = 'provider_unavailable';
            $message = \__('Clock API is temporarily unavailable.', 'must-hotel-booking');
        } elseif ($response->isConnectivityFailure()) {
            $status = 'endpoint_unreachable';
            $message = \__('Clock endpoint could not be reached.', 'must-hotel-booking');
        } else {
            $status = 'http_error';
            $message = \sprintf(
                /* translators: %d is an HTTP status code. */
                \__('Clock connection returned HTTP %d.', 'must-hotel-booking'),
                $response->getStatusCode()
            );
        }

        $detail = $response->getErrorMessage();

        return [
            'success' => false,
            'status' => $status,
            'message' => $detail !== '' ? $message . ' ' . $detail : $message,
            'http_status' => $response->getStatusCode(),
            'duration_ms' => $response->getDurationMs(),
            'summary' => ClockConfig::summary(),
        ];
    }
}
