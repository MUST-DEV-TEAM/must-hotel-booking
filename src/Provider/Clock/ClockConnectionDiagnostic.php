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

        $response = (new ClockApiClient())->get(ClockConfig::connectionPath(), [], 'clock.connection_test');

        if ($response->isSuccess()) {
            return [
                'success' => true,
                'status' => 'connected',
                'message' => \__('Clock connection succeeded.', 'must-hotel-booking'),
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $response->getDurationMs(),
                'summary' => ClockConfig::summary(),
            ];
        }

        if ($response->isAuthFailure()) {
            $status = 'auth_failed';
            $message = \__('Clock rejected the configured credentials.', 'must-hotel-booking');
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
