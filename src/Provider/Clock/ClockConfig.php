<?php
namespace MustHotelBooking\Provider\Clock;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;
final class ClockConfig
{
    /** @var array<string, string> */
    private const CATALOG_DEFAULT_PATHS = [
        'room_types' => '/room_types',
        'rooms' => '/rooms',
        'rates' => '/rates',
        'rates_availability' => '/rates_availability',
        'products' => '/products',
        'wbe_room_type_rates' => '/rates',
        'rate_plans' => '/rate_plans',
    ];
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
        return empty(self::configurationErrors());
    }
    public static function isDirectApiConfigured(): bool
    {
        return empty(self::directApiConfigurationErrors());
    }
    public static function isDirectPublicBookingReady(): bool
    {
        return empty(self::directPublicBookingReadinessErrors());
    }
    public static function baseUrl(): string
    {
        return \rtrim((string) (self::settings()['clock_api_base_url'] ?? ''), "/ \t\n\r\0\x0B");
    }
    public static function pmsApiUrl(): string
    {
        return self::normalizeBaseUrl((string) (self::settings()['clock_pms_api_url'] ?? ''));
    }
    public static function baseApiUrl(): string
    {
        return self::normalizeBaseUrl((string) (self::settings()['clock_base_api_url'] ?? ''));
    }
    public static function apiBaseUrlForType(string $apiType): string
    {
        $apiType = ClockEndpointResolver::normalizeApiType($apiType);
        if ($apiType === 'pms_api') {
            return self::pmsApiUrl();
        }
        if ($apiType === 'base_api') {
            return self::baseApiUrl();
        }
        return '';
    }
    public static function resolvedBaseUrl(string $apiType = ''): string
    {
        $url = ClockEndpointResolver::buildUrl($apiType !== '' ? $apiType : self::apiType(), '/');
        return \rtrim($url, '/');
    }
    public static function region(): string
    {
        return ClockEndpointResolver::normalizeRegion((string) (self::settings()['clock_region'] ?? ''));
    }
    public static function apiType(): string
    {
        return ClockEndpointResolver::normalizeApiType((string) (self::settings()['clock_api_type'] ?? 'pms_api'));
    }
    public static function subscriptionId(): string
    {
        return ClockEndpointResolver::normalizeNumericId((string) (self::settings()['clock_subscription_id'] ?? ''));
    }
    public static function accountId(): string
    {
        return ClockEndpointResolver::normalizeNumericId((string) (self::settings()['clock_account_id'] ?? ''));
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
        return \function_exists('sanitize_text_field')
            ? \sanitize_text_field((string) (self::settings()['clock_property_id'] ?? ''))
            : \trim((string) (self::settings()['clock_property_id'] ?? ''));
    }
    public static function environment(): string
    {
        return (string) (self::settings()['clock_environment'] ?? 'production');
    }
    public static function pmsApiEnabled(): bool
    {
        return !empty(self::settings()['clock_pms_api_enabled']);
    }
    public static function baseApiEnabled(): bool
    {
        return !empty(self::settings()['clock_base_api_enabled']);
    }
    public static function isPmsApiConfigured(): bool
    {
        return self::pmsApiEnabled() && empty(self::configurationErrors());
    }
    public static function isBaseApiConfigured(): bool
    {
        return self::baseApiEnabled() && empty(self::baseApiConfigurationErrors());
    }
    public static function allowLocalFallback(): bool
    {
        return !empty(self::settings()['fallback_to_local_when_clock_unavailable']);
    }
    public static function timeoutSeconds(): int
    {
        return \max(1, \min(60, (int) (self::settings()['clock_timeout_seconds'] ?? 15)));
    }
    public static function connectionPath(): string
    {
        $path = self::normalizeOptionalPath((string) (self::settings()['clock_connection_path'] ?? ''));
        return $path !== '' ? $path : self::CATALOG_DEFAULT_PATHS['room_types'];
    }
    public static function availabilityPath(): string
    {
        return self::publicBookingPaths()['availability'];
    }
    public static function ratesAvailabilityPath(): string
    {
        return self::catalogPaths()['rates_availability'];
    }
    public static function productsPath(): string
    {
        return self::catalogPaths()['products'];
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
    public static function paymentPostingMode(): string
    {
        $mode = \sanitize_key((string) (self::settings()['clock_payment_posting_mode'] ?? 'auto_detect'));

        return \in_array($mode, ['auto_detect', 'deposit_for_future_bookings', 'folio_payment_only', 'manual_clock_accounting'], true) ? $mode : 'auto_detect';
    }
    public static function sameDayFolioPaymentEnabled(): bool
    {
        return !empty(self::settings()['clock_same_day_folio_payment_enabled']);
    }
    /** @return array<string, string> */
    public static function endpointOverrides(): array
    {
        $settings = self::settings();
        return isset($settings['clock_endpoint_overrides']) && \is_array($settings['clock_endpoint_overrides'])
            ? ClockEndpointRegistry::normalizeOverrides($settings['clock_endpoint_overrides'])
            : ClockEndpointRegistry::normalizeOverrides([]);
    }
    public static function bookingDepositPaymentPath(): string
    {
        return ClockEndpointRegistry::resolveTemplate('booking_deposit_folio_create');
    }
    public static function bookingDepositRefundPath(): string
    {
        return ClockEndpointRegistry::resolveTemplate('booking_deposit_payment_refund');
    }
    public static function hasVerifiedDepositPaymentEndpoint(): bool
    {
        return self::bookingDepositPaymentPath() !== ''
            && ClockEndpointRegistry::resolveTemplate('booking_deposit_payment_create') !== '';
    }
    public static function webhookSecret(): string
    {
        return (string) (self::settings()['clock_webhook_secret'] ?? '');
    }
    public static function autoSyncEnabled(): bool
    {
        return !empty(self::settings()['clock_auto_sync_enabled']);
    }
    public static function autoSyncIntervalMinutes(): int
    {
        return self::normalizeAutoSyncInterval((int) (self::settings()['clock_auto_sync_interval_minutes'] ?? 15));
    }
    public static function autoSyncBatchSize(): int
    {
        return \max(10, \min(100, (int) (self::settings()['clock_auto_sync_batch_size'] ?? 25)));
    }
    public static function autoSyncPastDays(): int
    {
        return \max(0, \min(90, (int) (self::settings()['clock_auto_sync_past_days'] ?? 2)));
    }
    public static function autoSyncFutureDays(): int
    {
        return \max(1, \min(1095, (int) (self::settings()['clock_auto_sync_future_days'] ?? 365)));
    }
    public static function normalizeAutoSyncInterval(int $minutes): int
    {
        return \in_array($minutes, [5, 10, 15, 30, 60], true) ? $minutes : 15;
    }
    public static function wbeHotelId(): string
    {
        return ClockEndpointResolver::normalizeNumericId((string) (self::settings()['clock_wbe_hotel_id'] ?? ''));
    }
    /** @return array<string, string> */
    public static function catalogPaths(): array
    {
        $settings = self::settings();
        return [
            'room_types' => self::pathOrDefault((string) ($settings['clock_room_types_path'] ?? ''), self::CATALOG_DEFAULT_PATHS['room_types']),
            'rooms' => self::pathOrDefault((string) ($settings['clock_rooms_path'] ?? ''), self::CATALOG_DEFAULT_PATHS['rooms']),
            'rates' => self::pathOrDefault((string) ($settings['clock_rates_path'] ?? ''), self::CATALOG_DEFAULT_PATHS['rates']),
            'rates_availability' => self::pathOrDefault((string) ($settings['clock_rates_availability_path'] ?? ''), self::CATALOG_DEFAULT_PATHS['rates_availability']),
            'products' => self::pathOrDefault((string) ($settings['clock_products_path'] ?? ''), self::CATALOG_DEFAULT_PATHS['products']),
            'wbe_room_type_rates' => self::pathOrDefault((string) ($settings['clock_wbe_room_type_rates_path'] ?? ''), self::CATALOG_DEFAULT_PATHS['wbe_room_type_rates']),
            'rate_plans' => self::pathOrDefault((string) ($settings['clock_rate_plans_path'] ?? ''), self::CATALOG_DEFAULT_PATHS['rate_plans']),
        ];
    }
    /** @return array<string, array<string, mixed>> */
    public static function catalogEndpoints(): array
    {
        $paths = self::catalogPaths();
        return [
            'room_types' => [
                'label' => \__('Clock room types', 'must-hotel-booking'),
                'api_type' => 'pms_api',
                'path' => $paths['room_types'],
                'query' => [],
                'operation' => 'clock.catalog.room_types',
                'endpoint_name' => 'room_types',
            ],
            'rooms' => [
                'label' => \__('Clock physical rooms', 'must-hotel-booking'),
                'api_type' => 'pms_api',
                'path' => $paths['rooms'],
                'query' => [],
                'operation' => 'clock.catalog.rooms',
                'endpoint_name' => 'rooms',
            ],
            'rates' => [
                'label' => \__('Clock rates', 'must-hotel-booking'),
                'api_type' => 'pms_api',
                'path' => $paths['rates'],
                'query' => [],
                'operation' => 'clock.catalog.rates',
                'endpoint_name' => 'rates',
            ],
            'wbe_room_type_rates' => [
                'label' => \__('Clock WBE room-type rates', 'must-hotel-booking'),
                'api_type' => 'pms_api',
                'path' => $paths['wbe_room_type_rates'],
                'query' => ['wbe.eq' => 'true', 'bookable_type.eq' => 'Pms::RoomType'],
                'operation' => 'clock.catalog.wbe_room_type_rates',
                'endpoint_name' => 'wbe_room_type_rates',
            ],
            'rate_plans' => [
                'label' => \__('Clock rate plans', 'must-hotel-booking'),
                'api_type' => 'pms_api',
                'path' => $paths['rate_plans'],
                'query' => [],
                'operation' => 'clock.catalog.rate_plans',
                'endpoint_name' => 'rate_plans',
            ],
        ];
    }
    /** @return array<string, array<string, mixed>> */
    public static function catalogSyncEndpoints(): array
    {
        $endpoints = self::catalogEndpoints();
        return [
            'room_types' => $endpoints['room_types'],
            'rooms' => $endpoints['rooms'],
            'rates' => $endpoints['rates'],
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
            'reservation_cancel' => self::methodPathOrDefault((string) ($settings['clock_reservation_cancel_path'] ?? ''), 'PUT /bookings/{booking_id}'),
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
        return self::isDirectPublicBookingReady();
    }
    /** @return array<int, string> */
    public static function publicBookingConfigurationErrors(): array
    {
        return self::directPublicBookingReadinessErrors();
    }
    /** @return array<int, string> */
    public static function directApiConfigurationErrors(): array
    {
        $errors = self::configurationErrors();
        return $errors;
    }
    /** @return array<int, string> */
    public static function directPublicBookingReadinessErrors(): array
    {
        $errors = self::configurationErrors();
        if (self::ratesAvailabilityPath() === '') {
            $errors[] = \__('Clock rates availability endpoint path is not configured.', 'must-hotel-booking');
        }
        if (self::productsPath() === '') {
            $errors[] = \__('Clock products endpoint path is not configured.', 'must-hotel-booking');
        }
        if (self::reservationCreatePath() === '') {
            $errors[] = \__('Clock booking create endpoint path is not configured.', 'must-hotel-booking');
        }
        $catalogSummary = ClockCatalogService::getCachedCatalogSummary();
        $catalogCounts = isset($catalogSummary['counts']) && \is_array($catalogSummary['counts'])
            ? $catalogSummary['counts']
            : [];
        if (empty($catalogSummary['success']) || (int) ($catalogCounts['room_types'] ?? 0) <= 0) {
            $errors[] = \__('Clock catalog has not been synced successfully yet.', 'must-hotel-booking');
        }
        $mappings = new ProviderMappingRepository();
        if ($mappings->countForProvider(ProviderManager::CLOCK_MODE, 'accommodation') <= 0) {
            $errors[] = \__('No Clock room type mappings are available.', 'must-hotel-booking');
        }
        if ($mappings->countForProvider(ProviderManager::CLOCK_MODE, 'rate_plan') <= 0) {
            $errors[] = \__('No Clock rate mappings are available.', 'must-hotel-booking');
        }
        return \array_values(\array_unique($errors));
    }
    /** @return array<int, string> */
    public static function configurationErrors(): array
    {
        $errors = [];
        if (!self::isEnabled()) {
            $errors[] = \__('Clock integration is disabled.', 'must-hotel-booking');
        }
        if (!self::pmsApiEnabled()) {
            $errors[] = \__('Clock PMS API access is disabled in settings.', 'must-hotel-booking');
        }
        foreach (ClockEndpointResolver::configurationErrors() as $error) {
            $errors[] = $error;
        }
        if (self::apiUser() === '') {
            $errors[] = \__('Clock API user is missing.', 'must-hotel-booking');
        }
        if (self::apiKey() === '') {
            $errors[] = \__('Clock API key is missing.', 'must-hotel-booking');
        }
        return \array_values(\array_unique($errors));
    }
    /** @return array<int, string> */
    public static function baseApiConfigurationErrors(): array
    {
        $errors = [];
        if (!self::isEnabled()) {
            $errors[] = \__('Clock integration is disabled.', 'must-hotel-booking');
        }
        if (!self::baseApiEnabled()) {
            $errors[] = \__('Clock Base API access is disabled in settings.', 'must-hotel-booking');
        }
        foreach (ClockEndpointResolver::configurationErrors() as $error) {
            $errors[] = $error;
        }
        if (self::apiUser() === '') {
            $errors[] = \__('Clock API user is missing.', 'must-hotel-booking');
        }
        if (self::apiKey() === '') {
            $errors[] = \__('Clock API key is missing.', 'must-hotel-booking');
        }
        return \array_values(\array_unique($errors));
    }
    /** @return array<string, mixed> */
    public static function summary(): array
    {
        $configuredProvider = ProviderManager::configured();
        $catalogPaths = self::catalogPaths();
        $publicBookingPaths = self::publicBookingPaths();
        $reconciliationPaths = self::reconciliationPaths();
        $inboundSyncPaths = self::inboundSyncPaths();
        $configuredCatalogPaths = self::configuredPathCount($catalogPaths);
        $configuredPublicBookingPaths = self::configuredPathCount($publicBookingPaths);
        $configuredReconciliationPaths = self::configuredPathCount($reconciliationPaths);
        $configuredInboundSyncPaths = self::configuredPathCount($inboundSyncPaths);
        return [
            'provider_mode' => ProviderManager::getConfiguredMode(),
            'configured_provider' => $configuredProvider ? $configuredProvider->getKey() : '',
            'active_booking_provider' => ProviderManager::activeKey(),
            'fallback_to_local_when_clock_unavailable' => self::allowLocalFallback(),
            'clock_enabled' => self::isEnabled(),
            'clock_configured' => self::isConfigured(),
            'clock_direct_api_configured' => self::isDirectApiConfigured(),
            'clock_direct_public_booking_ready' => self::isDirectPublicBookingReady(),
            'clock_direct_public_booking_errors' => self::directPublicBookingReadinessErrors(),
            'clock_environment' => self::environment(),
            'clock_pms_api_enabled' => self::pmsApiEnabled(),
            'clock_pms_api_configured' => self::isPmsApiConfigured(),
            'clock_base_api_enabled' => self::baseApiEnabled(),
            'clock_base_api_configured' => self::isBaseApiConfigured(),
            'clock_pms_api_url_configured' => self::pmsApiUrl() !== '',
            'clock_base_api_url_configured' => self::baseApiUrl() !== '',
            'clock_region' => self::region(),
            'clock_api_type' => self::apiType(),
            'clock_subscription_id' => self::subscriptionId(),
            'clock_account_id' => self::accountId(),
            'clock_base_url' => self::resolvedBaseUrl(),
            'clock_pms_base_url' => self::resolvedBaseUrl('pms_api'),
            'clock_base_api_url' => self::resolvedBaseUrl('base_api'),
            'clock_legacy_base_url' => self::baseUrl(),
            'clock_api_user_set' => self::apiUser() !== '',
            'clock_api_key_set' => self::apiKey() !== '',
            'clock_credentials_valid' => self::isConfigured(),
            'clock_property_id' => self::propertyId(),
            'clock_wbe_hotel_id' => self::wbeHotelId(),
            'clock_timeout_seconds' => self::timeoutSeconds(),
            'clock_connection_path' => self::connectionPath(),
            'clock_catalog_paths_configured' => $configuredCatalogPaths,
            'clock_catalog_endpoints' => self::catalogEndpoints(),
            'clock_rates_availability_path' => $catalogPaths['rates_availability'],
            'clock_products_path' => $catalogPaths['products'],
            'clock_public_booking_paths_configured' => $configuredPublicBookingPaths,
            'clock_public_booking_configured' => self::isPublicBookingConfigured(),
            'clock_availability_path' => $publicBookingPaths['availability'],
            'clock_quote_path' => $publicBookingPaths['quote'],
            'clock_reservation_create_path' => $publicBookingPaths['reservation_create'],
            'clock_booking_create_endpoint_reachable' => $publicBookingPaths['reservation_create'] !== '',
            'clock_reconciliation_paths_configured' => $configuredReconciliationPaths,
            'clock_reservation_status_update_path' => $reconciliationPaths['reservation_status_update'],
            'clock_payment_status_sync_supported' => $reconciliationPaths['reservation_status_update'] !== '',
            'clock_payment_status_sync_mode' => $reconciliationPaths['reservation_status_update'] !== '' ? 'provider_update' : 'local_only',
            'clock_payment_status_sync_last_notice' => $reconciliationPaths['reservation_status_update'] !== ''
                ? ''
                : \__('Clock payment status sync endpoint is not configured; Stripe/local payment status is kept in the local mirror only.', 'must-hotel-booking'),
            'clock_reservation_cancel_path' => $reconciliationPaths['reservation_cancel'],
            'clock_reservation_room_update_path' => $reconciliationPaths['reservation_room_update'],
            'clock_reservation_stay_update_path' => $reconciliationPaths['reservation_stay_update'],
            'clock_reservation_guest_update_path' => $reconciliationPaths['reservation_guest_update'],
            'clock_inbound_sync_paths_configured' => $configuredInboundSyncPaths,
            'clock_reservation_fetch_path' => $inboundSyncPaths['reservation_fetch'],
            'clock_booking_folio_endpoint_reachable' => ClockEndpointRegistry::resolvePath('booking_folios_list', ['booking_id' => '{booking_id}']) !== '',
            'clock_folio_credit_item_endpoint_reachable' => ClockEndpointRegistry::resolvePath('folio_credit_item_create', ['folio_id' => '{folio_id}']) !== '',
            'clock_endpoint_definitions' => ClockEndpointRegistry::definitions(),
            'clock_endpoint_overrides' => self::endpointOverrides(),
            'clock_booking_deposit_payment_path' => self::bookingDepositPaymentPath(),
            'clock_booking_deposit_payment_configured' => self::hasVerifiedDepositPaymentEndpoint(),
            'clock_booking_deposit_prepayment_endpoint_found_configured' => self::hasVerifiedDepositPaymentEndpoint(),
            'clock_booking_deposit_refund_path' => self::bookingDepositRefundPath(),
            'clock_payment_posting_mode' => self::paymentPostingMode(),
            'clock_same_day_folio_payment_enabled' => self::sameDayFolioPaymentEnabled(),
            'clock_required_rights_missing' => self::requiredRightsMissing(),
            'clock_webhook_secret_set' => self::webhookSecret() !== '',
            'public_callback_base_url' => MustBookingConfig::get_public_callback_base_url(),
            'clock_webhook_url' => \function_exists('rest_url') ? MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/clock/webhook') : '',
            'clock_auto_sync_enabled' => self::autoSyncEnabled(),
            'clock_auto_sync_interval_minutes' => self::autoSyncIntervalMinutes(),
            'clock_auto_sync_batch_size' => self::autoSyncBatchSize(),
            'clock_auto_sync_past_days' => self::autoSyncPastDays(),
            'clock_auto_sync_future_days' => self::autoSyncFutureDays(),
        ];
    }
    /** @param array<string, string> $paths */
    private static function configuredPathCount(array $paths): int
    {
        $count = 0;
        foreach ($paths as $path) {
            if ($path !== '') {
                $count++;
            }
        }
        return $count;
    }
    /** @return array<int, string> */
    private static function requiredRightsMissing(): array
    {
        if (!self::hasVerifiedDepositPaymentEndpoint() && \in_array(self::paymentPostingMode(), ['auto_detect', 'deposit_for_future_bookings'], true)) {
            return [
                \__('Clock deposit folio endpoint configuration is unavailable; future online payments will enter manual Clock accounting instead of posting to a normal folio.', 'must-hotel-booking'),
            ];
        }

        return [];
    }
    public static function normalizePath(string $path): string
    {
        return ClockEndpointResolver::normalizePath($path);
    }
    public static function normalizeOptionalPath(string $path): string
    {
        $path = \trim($path);
        return $path !== '' ? self::normalizePath($path) : '';
    }
    private static function pathOrDefault(string $path, string $default): string
    {
        $path = self::normalizeOptionalPath($path);
        return $path !== '' ? $path : self::normalizePath($default);
    }
    private static function methodPathOrDefault(string $path, string $default): string
    {
        $path = \trim($path);
        $value = $path !== '' ? $path : \trim($default);

        if (\preg_match('/^\/?\s*(GET|POST|PUT|PATCH|DELETE)\s+(.+)$/i', $value, $matches) === 1) {
            $method = \strtoupper($matches[1]);
            $endpointPath = self::normalizePath((string) $matches[2]);

            return $method . ' ' . $endpointPath;
        }

        return self::normalizeOptionalPath($value);
    }
    private static function normalizeBaseUrl(string $url): string
    {
        $url = \trim($url);
        if ($url === '' || \preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        return \rtrim(\esc_url_raw($url), "/ \t\n\r\0\x0B");
    }
}
