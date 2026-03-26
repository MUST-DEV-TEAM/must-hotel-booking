<?php

namespace MustHotelBooking\Portal;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;

final class PortalRegistry
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function getDefinitions(): array
    {
        return [
            'dashboard' => [
                'label' => \__('Dashboard', 'must-hotel-booking'),
                'icon' => 'dashicons-dashboard',
                'route' => '',
                'route_alias' => 'dashboard',
                'required_capability' => StaffAccess::CAP_VIEW_DASHBOARD,
                'settings_visibility_key' => 'dashboard',
            ],
            'reservations' => [
                'label' => \__('Reservations', 'must-hotel-booking'),
                'icon' => 'dashicons-clipboard',
                'route' => 'reservations',
                'route_alias' => 'reservations',
                'required_capability' => StaffAccess::CAP_VIEW_RESERVATIONS,
                'settings_visibility_key' => 'reservations',
            ],
            'calendar' => [
                'label' => \__('Calendar', 'must-hotel-booking'),
                'icon' => 'dashicons-calendar-alt',
                'route' => 'calendar',
                'route_alias' => 'calendar',
                'required_capability' => StaffAccess::CAP_VIEW_CALENDAR,
                'settings_visibility_key' => 'calendar',
            ],
            'quick_booking' => [
                'label' => \__('Quick Booking', 'must-hotel-booking'),
                'icon' => 'dashicons-plus-alt2',
                'route' => 'quick-booking',
                'route_alias' => 'quick-booking',
                'required_capability' => StaffAccess::CAP_CREATE_QUICK_BOOKING,
                'settings_visibility_key' => 'quick_booking',
            ],
            'guests' => [
                'label' => \__('Guests', 'must-hotel-booking'),
                'icon' => 'dashicons-groups',
                'route' => 'guests',
                'route_alias' => 'guests',
                'required_capability' => StaffAccess::CAP_VIEW_GUESTS,
                'settings_visibility_key' => 'guests',
            ],
            'payments' => [
                'label' => \__('Payments', 'must-hotel-booking'),
                'icon' => 'dashicons-money-alt',
                'route' => 'payments',
                'route_alias' => 'payments',
                'required_capability' => StaffAccess::CAP_VIEW_PAYMENTS,
                'settings_visibility_key' => 'payments',
            ],
            'reports' => [
                'label' => \__('Reports', 'must-hotel-booking'),
                'icon' => 'dashicons-chart-bar',
                'route' => 'reports',
                'route_alias' => 'reports',
                'required_capability' => StaffAccess::CAP_VIEW_REPORTS,
                'settings_visibility_key' => 'reports',
            ],
            'accommodations' => [
                'label' => \__('Accommodations', 'must-hotel-booking'),
                'icon' => 'dashicons-admin-multisite',
                'route' => 'accommodations',
                'route_alias' => 'accommodations',
                'required_capability' => StaffAccess::CAP_VIEW_ACCOMMODATIONS,
                'settings_visibility_key' => 'accommodations',
            ],
            'availability_rules' => [
                'label' => \__('Availability Rules', 'must-hotel-booking'),
                'icon' => 'dashicons-lock',
                'route' => 'availability-rules',
                'route_alias' => 'availability-rules',
                'required_capability' => StaffAccess::CAP_VIEW_AVAILABILITY_RULES,
                'settings_visibility_key' => 'availability_rules',
            ],
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
     * @return array<string, array<string, string>>
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
     * @return array<string, array<string, string>>
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
     * @return array<string, string>|null
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
