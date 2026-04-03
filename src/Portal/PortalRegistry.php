<?php

namespace MustHotelBooking\Portal;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;

final class PortalRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getDefinitions(): array
    {
        return [
            'dashboard' => [
                'label'                   => \__('Dashboard', 'must-hotel-booking'),
                'icon'                    => 'dashicons-dashboard',
                'route'                   => '',
                'route_alias'             => 'dashboard',
                'required_capability'     => StaffAccess::CAP_DASHBOARD_VIEW,
                'settings_visibility_key' => 'dashboard',
            ],
            'reservations' => [
                'label'                   => \__('Reservations', 'must-hotel-booking'),
                'icon'                    => 'dashicons-clipboard',
                'route'                   => 'reservations',
                'route_alias'             => 'reservations',
                'required_capability'     => StaffAccess::CAP_RESERVATION_VIEW,
                'settings_visibility_key' => 'reservations',
            ],
            'calendar' => [
                'label'                   => \__('Calendar', 'must-hotel-booking'),
                'icon'                    => 'dashicons-calendar-alt',
                'route'                   => 'calendar',
                'route_alias'             => 'calendar',
                'required_capability'     => StaffAccess::CAP_CALENDAR_VIEW,
                'settings_visibility_key' => 'calendar',
            ],
            'front_desk' => [
                'label'                   => \__('Front Desk', 'must-hotel-booking'),
                'icon'                    => 'dashicons-id-alt',
                'route'                   => 'front-desk',
                'route_alias'             => 'front-desk',
                'required_capability'     => StaffAccess::CAP_RESERVATION_CREATE,
                'settings_visibility_key' => 'front_desk',
            ],
            'guests' => [
                'label'                   => \__('Guests', 'must-hotel-booking'),
                'icon'                    => 'dashicons-groups',
                'route'                   => 'guests',
                'route_alias'             => 'guests',
                'required_capability'     => StaffAccess::CAP_GUEST_VIEW,
                'settings_visibility_key' => 'guests',
            ],
            'payments' => [
                'label'                   => \__('Payments', 'must-hotel-booking'),
                'icon'                    => 'dashicons-money-alt',
                'route'                   => 'payments',
                'route_alias'             => 'payments',
                'required_capability'     => StaffAccess::CAP_PAYMENT_VIEW,
                'settings_visibility_key' => 'payments',
            ],
            'housekeeping' => [
                'label'                   => \__('Housekeeping', 'must-hotel-booking'),
                'icon'                    => 'dashicons-admin-home',
                'route'                   => 'housekeeping',
                'route_alias'             => 'housekeeping',
                'required_capability'     => StaffAccess::CAP_HOUSEKEEPING_VIEW,
                'settings_visibility_key' => 'housekeeping',
            ],
            'rooms_availability' => [
                'label'                   => \__('Rooms & Availability', 'must-hotel-booking'),
                'icon'                    => 'dashicons-admin-multisite',
                'route'                   => 'rooms-availability',
                'route_alias'             => 'rooms-availability',
                'required_capability'     => StaffAccess::CAP_INVENTORY_VIEW,
                'settings_visibility_key' => 'rooms_availability',
            ],
            'reports' => [
                'label'                   => \__('Reports', 'must-hotel-booking'),
                'icon'                    => 'dashicons-chart-bar',
                'route'                   => 'reports',
                'route_alias'             => 'reports',
                'required_capability'     => StaffAccess::CAP_REPORT_VIEW_OPS,
                'required_capabilities'   => [
                    StaffAccess::CAP_REPORT_VIEW_OPS,
                    StaffAccess::CAP_REPORT_VIEW_FINANCE,
                    StaffAccess::CAP_REPORT_VIEW_MANAGEMENT,
                ],
                'settings_visibility_key' => 'reports',
            ],
        ];
    }

    /**
     * Routes that have been permanently moved. Visiting the old URL triggers a
     * 301 redirect to the new destination before any page rendering occurs.
     *
     * Keys are the raw route segment (as it appears in the URL after /staff/).
     * Values are [module_key, query_args] pairs.
     *
     * @return array<string, array{module: string, args: array<string, string>}>
     */
    public static function getDeprecatedRoutes(): array
    {
        return [
            'quick-booking'     => ['module' => 'front_desk',          'args' => ['tab' => 'new-booking']],
            'accommodations'    => ['module' => 'rooms_availability',   'args' => ['tab' => 'rooms']],
            'availability-rules'=> ['module' => 'rooms_availability',   'args' => ['tab' => 'rules']],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function getDefaultModuleVisibility(): array
    {
        $defaults = [];

        foreach (self::getDefinitions() as $key => $definition) {
            unset($definition);
            $defaults[$key] = true;
        }

        return $defaults;
    }

    /**
     * @return array<string, bool>
     */
    public static function getModuleVisibility(): array
    {
        $stored = MustBookingConfig::get_setting('portal_module_visibility', self::getDefaultModuleVisibility());
        $visibility = self::getDefaultModuleVisibility();

        if (!\is_array($stored)) {
            return $visibility;
        }

        foreach ($visibility as $key => $enabled) {
            $visibility[$key] = !empty($stored[$key]);
            unset($enabled);
        }

        return $visibility;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getEnabledDefinitions(): array
    {
        $definitions = [];
        $visibility = self::getModuleVisibility();

        foreach (self::getDefinitions() as $key => $definition) {
            if (empty($visibility[$key])) {
                continue;
            }

            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getVisibleDefinitionsForCurrentUser(): array
    {
        $definitions = [];

        foreach (self::getEnabledDefinitions() as $key => $definition) {
            if (StaffAccess::userCanAccessPortalModule($key)) {
                $definitions[$key] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getDefinition(string $moduleKey): ?array
    {
        $moduleKey = self::normalizeModuleKey($moduleKey);
        $definitions = self::getDefinitions();

        return isset($definitions[$moduleKey]) ? $definitions[$moduleKey] : null;
    }

    public static function normalizeModuleKey(string $moduleKey): string
    {
        $moduleKey = \sanitize_key(\str_replace('-', '_', $moduleKey));

        if ($moduleKey === '') {
            return 'dashboard';
        }

        return isset(self::getDefinitions()[$moduleKey]) ? $moduleKey : 'dashboard';
    }

    public static function resolveRouteToModuleKey(string $route): string
    {
        $route = \trim(\sanitize_text_field($route), '/');

        if ($route === '' || $route === 'dashboard') {
            return 'dashboard';
        }

        foreach (self::getDefinitions() as $key => $definition) {
            $routeAlias = (string) ($definition['route_alias'] ?? $definition['route'] ?? '');

            if ($routeAlias === $route || (string) ($definition['route'] ?? '') === $route) {
                return $key;
            }
        }

        return 'dashboard';
    }
}
