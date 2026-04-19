<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Provider\ProviderManager;

final class ClockConfig
{
    /** @return array<string, mixed> */
    public static function settings(): array
    {
        return MustBookingConfig::get_clock_settings();
    }

    public static function isEnabled(): bool
    {
        return MustBookingConfig::is_clock_enabled();
    }

    public static function isConfigured(): bool
    {
        $settings = self::settings();

        return self::isEnabled()
            && self::baseUrl() !== ''
            && (string) ($settings['clock_api_user'] ?? '') !== ''
            && (string) ($settings['clock_api_key'] ?? '') !== '';
    }

    public static function baseUrl(): string
    {
        return \rtrim((string) (self::settings()['clock_api_base_url'] ?? ''), "/ \t\n\r\0\x0B");
    }

    public static function apiUser(): string
    {
        return (string) (self::settings()['clock_api_user'] ?? '');
    }

    public static function apiKey(): string
    {
        return (string) (self::settings()['clock_api_key'] ?? '');
    }

    public static function propertyId(): string
    {
        return (string) (self::settings()['clock_property_id'] ?? '');
    }

    public static function environment(): string
    {
        return (string) (self::settings()['clock_environment'] ?? 'production');
    }

    public static function timeoutSeconds(): int
    {
        return \max(1, \min(60, (int) (self::settings()['clock_timeout_seconds'] ?? 15)));
    }

    public static function connectionPath(): string
    {
        return self::normalizePath((string) (self::settings()['clock_connection_path'] ?? '/'));
    }

    public static function availabilityPath(): string
    {
        return self::publicBookingPaths()['availability'];
    }

    public static function quotePath(): string
    {
        return self::publicBookingPaths()['quote'];
    }

    public static function reservationCreatePath(): string
    {
        return self::publicBookingPaths()['reservation_create'];
    }

    public static function reservationStatusUpdatePath(): string
    {
        return self::reconciliationPaths()['reservation_status_update'];
    }

    public static function reservationCancelPath(): string
    {
        return self::reconciliationPaths()['reservation_cancel'];
    }

    public static function reservationRoomUpdatePath(): string
    {
        return self::reconciliationPaths()['reservation_room_update'];
    }

    public static function reservationStayUpdatePath(): string
    {
        return self::reconciliationPaths()['reservation_stay_update'];
    }

    public static function reservationGuestUpdatePath(): string
    {
        return self::reconciliationPaths()['reservation_guest_update'];
    }

    public static function reservationFetchPath(): string
    {
        return self::inboundSyncPaths()['reservation_fetch'];
    }

    public static function webhookSecret(): string
    {
        return (string) (self::settings()['clock_webhook_secret'] ?? '');
    }

    /** @return array<string, string> */
    public static function catalogPaths(): array
    {
        $settings = self::settings();

        return [
            'room_types' => self::normalizeOptionalPath((string) ($settings['clock_room_types_path'] ?? '')),
            'rooms' => self::normalizeOptionalPath((string) ($settings['clock_rooms_path'] ?? '')),
            'rate_plans' => self::normalizeOptionalPath((string) ($settings['clock_rate_plans_path'] ?? '')),
        ];
    }

    /** @return array<string, string> */
    public static function publicBookingPaths(): array
    {
        $settings = self::settings();

        return [
            'availability' => self::normalizeOptionalPath((string) ($settings['clock_availability_path'] ?? '')),
            'quote' => self::normalizeOptionalPath((string) ($settings['clock_quote_path'] ?? '')),
            'reservation_create' => self::normalizeOptionalPath((string) ($settings['clock_reservation_create_path'] ?? '')),
        ];
    }

    /** @return array<string, string> */
    public static function reconciliationPaths(): array
    {
        $settings = self::settings();

        return [
            'reservation_status_update' => self::normalizeOptionalPath((string) ($settings['clock_reservation_status_update_path'] ?? '')),
            'reservation_cancel' => self::normalizeOptionalPath((string) ($settings['clock_reservation_cancel_path'] ?? '')),
            'reservation_room_update' => self::normalizeOptionalPath((string) ($settings['clock_reservation_room_update_path'] ?? '')),
            'reservation_stay_update' => self::normalizeOptionalPath((string) ($settings['clock_reservation_stay_update_path'] ?? '')),
            'reservation_guest_update' => self::normalizeOptionalPath((string) ($settings['clock_reservation_guest_update_path'] ?? '')),
        ];
    }

    /** @return array<string, string> */
    public static function inboundSyncPaths(): array
    {
        $settings = self::settings();

        return [
            'reservation_fetch' => self::normalizeOptionalPath((string) ($settings['clock_reservation_fetch_path'] ?? '')),
        ];
    }

    public static function isPublicBookingConfigured(): bool
    {
        return empty(self::publicBookingConfigurationErrors());
    }

    /** @return array<int, string> */
    public static function publicBookingConfigurationErrors(): array
    {
        $errors = self::configurationErrors();
        $paths = self::publicBookingPaths();

        if ($paths['availability'] === '') {
            $errors[] = \__('Clock availability endpoint path is missing.', 'must-hotel-booking');
        }

        if ($paths['quote'] === '') {
            $errors[] = \__('Clock quote endpoint path is missing.', 'must-hotel-booking');
        }

        if ($paths['reservation_create'] === '') {
            $errors[] = \__('Clock reservation create endpoint path is missing.', 'must-hotel-booking');
        }

        if (self::propertyId() === '') {
            $errors[] = \__('Clock property / hotel ID is missing.', 'must-hotel-booking');
        }

        return $errors;
    }

    /** @return array<int, string> */
    public static function configurationErrors(): array
    {
        $errors = [];

        if (!self::isEnabled()) {
            $errors[] = \__('Clock integration is disabled.', 'must-hotel-booking');
        }

        if (self::baseUrl() === '') {
            $errors[] = \__('Clock API base URL is missing.', 'must-hotel-booking');
        }

        if (self::apiUser() === '') {
            $errors[] = \__('Clock API user is missing.', 'must-hotel-booking');
        }

        if (self::apiKey() === '') {
            $errors[] = \__('Clock API key is missing.', 'must-hotel-booking');
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $configuredProvider = ProviderManager::configured();
        $catalogPaths = self::catalogPaths();
        $publicBookingPaths = self::publicBookingPaths();
        $reconciliationPaths = self::reconciliationPaths();
        $inboundSyncPaths = self::inboundSyncPaths();
        $configuredCatalogPaths = 0;
        $configuredPublicBookingPaths = 0;
        $configuredReconciliationPaths = 0;
        $configuredInboundSyncPaths = 0;

        foreach ($catalogPaths as $path) {
            if ($path !== '') {
                $configuredCatalogPaths++;
            }
        }

        foreach ($publicBookingPaths as $path) {
            if ($path !== '') {
                $configuredPublicBookingPaths++;
            }
        }

        foreach ($reconciliationPaths as $path) {
            if ($path !== '') {
                $configuredReconciliationPaths++;
            }
        }

        foreach ($inboundSyncPaths as $path) {
            if ($path !== '') {
                $configuredInboundSyncPaths++;
            }
        }

        return [
            'provider_mode' => ProviderManager::getConfiguredMode(),
            'configured_provider' => $configuredProvider ? $configuredProvider->getKey() : '',
            'active_booking_provider' => ProviderManager::activeKey(),
            'clock_enabled' => self::isEnabled(),
            'clock_configured' => self::isConfigured(),
            'clock_environment' => self::environment(),
            'clock_base_url' => self::baseUrl(),
            'clock_api_user_set' => self::apiUser() !== '',
            'clock_api_key_set' => self::apiKey() !== '',
            'clock_property_id' => self::propertyId(),
            'clock_timeout_seconds' => self::timeoutSeconds(),
            'clock_connection_path' => self::connectionPath(),
            'clock_catalog_paths_configured' => $configuredCatalogPaths,
            'clock_public_booking_paths_configured' => $configuredPublicBookingPaths,
            'clock_public_booking_configured' => self::isPublicBookingConfigured(),
            'clock_availability_path' => $publicBookingPaths['availability'],
            'clock_quote_path' => $publicBookingPaths['quote'],
            'clock_reservation_create_path' => $publicBookingPaths['reservation_create'],
            'clock_reconciliation_paths_configured' => $configuredReconciliationPaths,
            'clock_reservation_status_update_path' => $reconciliationPaths['reservation_status_update'],
            'clock_reservation_cancel_path' => $reconciliationPaths['reservation_cancel'],
            'clock_reservation_room_update_path' => $reconciliationPaths['reservation_room_update'],
            'clock_reservation_stay_update_path' => $reconciliationPaths['reservation_stay_update'],
            'clock_reservation_guest_update_path' => $reconciliationPaths['reservation_guest_update'],
            'clock_inbound_sync_paths_configured' => $configuredInboundSyncPaths,
            'clock_reservation_fetch_path' => $inboundSyncPaths['reservation_fetch'],
            'clock_webhook_secret_set' => self::webhookSecret() !== '',
            'clock_webhook_url' => \function_exists('rest_url') ? \rest_url('must-hotel-booking/v1/clock/webhook') : '',
        ];
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

        return '/' . \ltrim($path, '/');
    }

    public static function normalizeOptionalPath(string $path): string
    {
        $path = \trim($path);

        return $path !== '' ? self::normalizePath($path) : '';
    }
}
