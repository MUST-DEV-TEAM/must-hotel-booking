<?php

namespace MustHotelBooking\Core;

final class StaffAccess
{
    public const ROLE_WORKER = 'mhb_worker';
    public const ROLE_MANAGER = 'mhb_manager';

    public const CAP_VIEW_DASHBOARD = 'must_hotel_booking_view_dashboard';
    public const CAP_VIEW_RESERVATIONS = 'must_hotel_booking_view_reservations';
    public const CAP_EDIT_RESERVATIONS = 'must_hotel_booking_edit_reservations';
    public const CAP_VIEW_CALENDAR = 'must_hotel_booking_view_calendar';
    public const CAP_CREATE_QUICK_BOOKING = 'must_hotel_booking_create_quick_booking';
    public const CAP_VIEW_GUESTS = 'must_hotel_booking_view_guests';
    public const CAP_VIEW_PAYMENTS = 'must_hotel_booking_view_payments';
    public const CAP_MARK_PAYMENT_AS_PAID = 'must_hotel_booking_mark_payment_as_paid';
    public const CAP_CANCEL_RESERVATION = 'must_hotel_booking_cancel_reservation';
    public const CAP_VIEW_REPORTS = 'must_hotel_booking_view_reports';
    public const CAP_VIEW_ACCOMMODATIONS = 'must_hotel_booking_view_accommodations';
    public const CAP_VIEW_AVAILABILITY_RULES = 'must_hotel_booking_view_availability_rules';
    public const CAP_ACCESS_SETTINGS = 'must_hotel_booking_access_settings';
    public const CAP_ACCESS_PORTAL = 'must_hotel_booking_access_portal';

    private const LEGACY_ROLE_MIGRATION_OPTION = 'must_hotel_booking_staff_role_schema_version';
    private const LEGACY_ROLE_SCHEMA_VERSION = 1;

    /**
     * @return array<string, string>
     */
    public static function getRoleLabels(): array
    {
        return [
            self::ROLE_WORKER => \__('Worker', 'must-hotel-booking'),
            self::ROLE_MANAGER => \__('Manager', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getRoleDescriptions(): array
    {
        return [
            self::ROLE_WORKER => \__('Operational staff handling arrivals, departures, guest service, and daily booking workflows from the staff portal.', 'must-hotel-booking'),
            self::ROLE_MANAGER => \__('Senior operations staff with broader oversight across reservations, payments, reports, accommodations, and availability.', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getCapabilityLabels(): array
    {
        return [
            'view_dashboard' => \__('Dashboard', 'must-hotel-booking'),
            'view_reservations' => \__('View reservations', 'must-hotel-booking'),
            'edit_reservations' => \__('Edit reservations', 'must-hotel-booking'),
            'view_calendar' => \__('View calendar', 'must-hotel-booking'),
            'create_quick_booking' => \__('Create quick booking', 'must-hotel-booking'),
            'view_guests' => \__('View guests', 'must-hotel-booking'),
            'view_payments' => \__('View payments', 'must-hotel-booking'),
            'mark_payment_as_paid' => \__('Mark payment paid', 'must-hotel-booking'),
            'cancel_reservation' => \__('Cancel reservation', 'must-hotel-booking'),
            'view_reports' => \__('View reports', 'must-hotel-booking'),
            'view_accommodations' => \__('View accommodations', 'must-hotel-booking'),
            'view_availability_rules' => \__('View availability rules', 'must-hotel-booking'),
            'access_settings' => \__('Access settings', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getCapabilityKeyMap(): array
    {
        return [
            'view_dashboard' => self::CAP_VIEW_DASHBOARD,
            'view_reservations' => self::CAP_VIEW_RESERVATIONS,
            'edit_reservations' => self::CAP_EDIT_RESERVATIONS,
            'view_calendar' => self::CAP_VIEW_CALENDAR,
            'create_quick_booking' => self::CAP_CREATE_QUICK_BOOKING,
            'view_guests' => self::CAP_VIEW_GUESTS,
            'view_payments' => self::CAP_VIEW_PAYMENTS,
            'mark_payment_as_paid' => self::CAP_MARK_PAYMENT_AS_PAID,
            'cancel_reservation' => self::CAP_CANCEL_RESERVATION,
            'view_reports' => self::CAP_VIEW_REPORTS,
            'view_accommodations' => self::CAP_VIEW_ACCOMMODATIONS,
            'view_availability_rules' => self::CAP_VIEW_AVAILABILITY_RULES,
            'access_settings' => self::CAP_ACCESS_SETTINGS,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getPortalModuleCapabilityMap(): array
    {
        return [
            'dashboard' => self::CAP_VIEW_DASHBOARD,
            'reservations' => self::CAP_VIEW_RESERVATIONS,
            'calendar' => self::CAP_VIEW_CALENDAR,
            'quick_booking' => self::CAP_CREATE_QUICK_BOOKING,
            'guests' => self::CAP_VIEW_GUESTS,
            'payments' => self::CAP_VIEW_PAYMENTS,
            'reports' => self::CAP_VIEW_REPORTS,
            'accommodations' => self::CAP_VIEW_ACCOMMODATIONS,
            'availability_rules' => self::CAP_VIEW_AVAILABILITY_RULES,
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function getDefaultCapabilityMatrix(): array
    {
        return [
            self::ROLE_WORKER => [
                'view_dashboard' => true,
                'view_reservations' => true,
                'edit_reservations' => true,
                'view_calendar' => true,
                'create_quick_booking' => true,
                'view_guests' => true,
                'view_payments' => true,
                'mark_payment_as_paid' => true,
                'cancel_reservation' => false,
                'view_reports' => false,
                'view_accommodations' => false,
                'view_availability_rules' => false,
                'access_settings' => false,
            ],
            self::ROLE_MANAGER => [
                'view_dashboard' => true,
                'view_reservations' => true,
                'edit_reservations' => true,
                'view_calendar' => true,
                'create_quick_booking' => true,
                'view_guests' => true,
                'view_payments' => true,
                'mark_payment_as_paid' => true,
                'cancel_reservation' => true,
                'view_reports' => true,
                'view_accommodations' => true,
                'view_availability_rules' => true,
                'access_settings' => false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public static function getCapabilityMatrix(): array
    {
        $matrix = MustBookingConfig::get_setting('capability_matrix', self::getDefaultCapabilityMatrix());
        $defaults = self::getDefaultCapabilityMatrix();

        if (!\is_array($matrix)) {
            return $defaults;
        }

        foreach ($defaults as $role => $capabilities) {
            $row = isset($matrix[$role]) && \is_array($matrix[$role]) ? $matrix[$role] : [];

            foreach ($capabilities as $capability => $enabled) {
                $defaults[$role][$capability] = !empty($row[$capability]);
                unset($enabled);
            }
        }

        return $defaults;
    }

    /**
     * @return array<int, string>
     */
    public static function getPortalAccessRoles(): array
    {
        $saved = MustBookingConfig::get_setting('portal_access_roles', [self::ROLE_WORKER, self::ROLE_MANAGER]);
        $valid = [];

        foreach (\is_array($saved) ? $saved : [] as $role) {
            $normalized = self::normalizeRoleSlug((string) $role);

            if (isset(self::getRoleLabels()[$normalized])) {
                $valid[$normalized] = $normalized;
            }
        }

        return !empty($valid) ? \array_values($valid) : [self::ROLE_WORKER, self::ROLE_MANAGER];
    }

    /**
     * @return array<int, string>
     */
    public static function getPortalRoleSlugs(): array
    {
        return \array_keys(self::getRoleLabels());
    }

    /**
     * @return array<int, string>
     */
    public static function getAllPluginCapabilities(): array
    {
        return \array_values(\array_unique(\array_merge(
            \array_values(self::getCapabilityKeyMap()),
            [self::CAP_ACCESS_PORTAL]
        )));
    }

    public static function getSettingsCapability(): string
    {
        return self::CAP_ACCESS_SETTINGS;
    }

    public static function currentUserCanManageSettings(): bool
    {
        return self::userCanManageSettings();
    }

    public static function userCanManageSettings(?\WP_User $user = null): bool
    {
        $user = $user instanceof \WP_User ? $user : \wp_get_current_user();

        if (!$user instanceof \WP_User || $user->ID <= 0) {
            return false;
        }

        if (\user_can($user, 'manage_options')) {
            return true;
        }

        return \user_can($user, self::getSettingsCapability());
    }

    public static function userCanAccessPortal(?\WP_User $user = null): bool
    {
        $user = $user instanceof \WP_User ? $user : \wp_get_current_user();

        if (!$user instanceof \WP_User || $user->ID <= 0) {
            return false;
        }

        if (\user_can($user, 'manage_options')) {
            return true;
        }

        return \user_can($user, self::CAP_ACCESS_PORTAL);
    }

    public static function userCanAccessPortalModule(string $moduleKey, ?\WP_User $user = null): bool
    {
        $user = $user instanceof \WP_User ? $user : \wp_get_current_user();

        if (!$user instanceof \WP_User || $user->ID <= 0) {
            return false;
        }

        if (\user_can($user, 'manage_options')) {
            return true;
        }

        if (!self::userCanAccessPortal($user)) {
            return false;
        }

        $capability = (string) (self::getPortalModuleCapabilityMap()[$moduleKey] ?? '');

        return $capability !== '' && \user_can($user, $capability);
    }

    public static function isPortalRestrictedUser(?\WP_User $user = null): bool
    {
        $user = $user instanceof \WP_User ? $user : \wp_get_current_user();

        if (!$user instanceof \WP_User || $user->ID <= 0 || \user_can($user, 'manage_options')) {
            return false;
        }

        return self::userCanAccessPortal($user) || self::userHasPortalRole($user);
    }

    public static function shouldHideWpAdminForUser(?\WP_User $user = null): bool
    {
        return !empty(MustBookingConfig::get_setting('hide_wp_admin_for_workers', true))
            && self::isPortalRestrictedUser($user);
    }

    public static function userHasPortalRole(?\WP_User $user = null): bool
    {
        $user = $user instanceof \WP_User ? $user : \wp_get_current_user();

        if (!$user instanceof \WP_User || $user->ID <= 0) {
            return false;
        }

        $roles = \is_array($user->roles) ? $user->roles : [];

        foreach ($roles as $role) {
            if (\in_array(self::normalizeRoleSlug((string) $role), self::getPortalRoleSlugs(), true)) {
                return true;
            }
        }

        return false;
    }

    public static function syncRoleCapabilities(): void
    {
        self::ensurePortalRolesExist();
        self::maybeMigrateLegacyRolesAndSettings();

        $roles = self::getRoleLabels();
        $matrix = self::getCapabilityMatrix();
        $portalRoles = self::getPortalAccessRoles();
        $capabilityMap = self::getCapabilityKeyMap();
        $allCaps = self::getAllPluginCapabilities();

        foreach ($roles as $roleSlug => $roleLabel) {
            $roleObject = \get_role($roleSlug);

            if (!$roleObject instanceof \WP_Role) {
                \add_role(
                    $roleSlug,
                    $roleLabel,
                    [
                        'read' => true,
                    ]
                );

                $roleObject = \get_role($roleSlug);
            }

            if (!$roleObject instanceof \WP_Role) {
                continue;
            }

            $roleObject->add_cap('read');

            foreach ($allCaps as $capability) {
                $roleObject->remove_cap($capability);
            }

            foreach ($capabilityMap as $capabilityKey => $capability) {
                if (!empty($matrix[$roleSlug][$capabilityKey])) {
                    $roleObject->add_cap($capability);
                }
            }

            if (\in_array($roleSlug, $portalRoles, true)) {
                $roleObject->add_cap(self::CAP_ACCESS_PORTAL);
            }
        }

        $administrator = \get_role('administrator');

        if ($administrator instanceof \WP_Role) {
            foreach ($allCaps as $capability) {
                $administrator->add_cap($capability);
            }
        }

        self::removeLegacyRoles();
    }

    /**
     * @return array<string, string>
     */
    private static function getLegacyRoleMap(): array
    {
        return [
            'receptionist' => self::ROLE_WORKER,
            'housekeeping' => self::ROLE_WORKER,
            'accountant' => self::ROLE_WORKER,
            'manager' => self::ROLE_MANAGER,
        ];
    }

    private static function ensurePortalRolesExist(): void
    {
        foreach (self::getRoleLabels() as $roleSlug => $roleLabel) {
            if (\get_role($roleSlug) instanceof \WP_Role) {
                continue;
            }

            \add_role(
                $roleSlug,
                $roleLabel,
                [
                    'read' => true,
                ]
            );
        }
    }

    private static function maybeMigrateLegacyRolesAndSettings(): void
    {
        $version = (int) \get_option(self::LEGACY_ROLE_MIGRATION_OPTION, 0);

        if ($version >= self::LEGACY_ROLE_SCHEMA_VERSION) {
            return;
        }

        $legacyRoles = \array_keys(self::getLegacyRoleMap());
        $users = \get_users(
            [
                'role__in' => $legacyRoles,
                'fields' => 'all',
            ]
        );

        foreach ($users as $user) {
            if (!$user instanceof \WP_User) {
                continue;
            }

            $roles = \is_array($user->roles) ? $user->roles : [];
            $targetRole = self::ROLE_WORKER;

            foreach ($roles as $role) {
                $normalized = self::normalizeRoleSlug((string) $role);

                if ($normalized === self::ROLE_MANAGER) {
                    $targetRole = self::ROLE_MANAGER;
                    break;
                }
            }

            if (!\user_can($user, 'manage_options')) {
                $user->add_role($targetRole);
            }

            foreach ($legacyRoles as $legacyRole) {
                if (\in_array($legacyRole, $roles, true)) {
                    $user->remove_role($legacyRole);
                }
            }
        }

        MustBookingConfig::set_group_settings(
            'staff_access',
            [
                'portal_access_roles' => self::getPortalAccessRoles(),
                'capability_matrix' => self::getCapabilityMatrix(),
            ]
        );

        \update_option(self::LEGACY_ROLE_MIGRATION_OPTION, self::LEGACY_ROLE_SCHEMA_VERSION);
    }

    private static function removeLegacyRoles(): void
    {
        foreach (\array_keys(self::getLegacyRoleMap()) as $legacyRole) {
            \remove_role($legacyRole);
        }
    }

    private static function normalizeRoleSlug(string $role): string
    {
        $role = \sanitize_key($role);
        $legacyMap = self::getLegacyRoleMap();

        return isset($legacyMap[$role]) ? $legacyMap[$role] : $role;
    }
}
