<?php

namespace MustHotelBooking\Portal;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;

final class PortalAccessGuard
{
    public static function registerHooks(): void
    {
        \add_action('template_redirect', [self::class, 'maybeGuardPortalRequests'], 1);
        \add_action('admin_init', [self::class, 'maybeRedirectRestrictedAdminAccess'], 1);
        \add_filter('show_admin_bar', [self::class, 'maybeHideAdminBar']);
        \add_filter('login_redirect', [self::class, 'filterLoginRedirect'], 10, 3);
    }

    public static function maybeGuardPortalRequests(): void
    {
        $portalEnabled = !empty(MustBookingConfig::get_setting('enable_staff_portal', false));

        if (PortalRouter::isPortalRequest()) {
            if (!\is_user_logged_in()) {
                \wp_safe_redirect(PortalRouter::getLoginUrl());
                exit;
            }

            if (!StaffAccess::userCanAccessPortal()) {
                \wp_die(\esc_html__('Your account does not have access to the staff portal.', 'must-hotel-booking'));
            }

            if (!$portalEnabled && !\current_user_can('manage_options')) {
                \wp_die(\esc_html__('The staff portal is currently disabled.', 'must-hotel-booking'));
            }

            $moduleKey = PortalRouter::getRequestedModuleKey();

            if (!self::userCanAccessModule($moduleKey)) {
                $redirectModule = self::getFirstAccessibleModuleKey();

                if ($redirectModule === '') {
                    \wp_die(\esc_html__('No staff portal modules are currently available for your account.', 'must-hotel-booking'));
                }

                $redirectUrl = PortalRouter::getModuleUrl($redirectModule);

                \wp_safe_redirect($redirectUrl);
                exit;
            }
        }

        if (PortalRouter::isLoginRequest() && \is_user_logged_in()) {
            if (StaffAccess::userCanAccessPortal()) {
                \wp_safe_redirect(self::getPostLoginRedirectUrl(\wp_get_current_user(), true));
                exit;
            }
        }
    }

    public static function maybeRedirectRestrictedAdminAccess(): void
    {
        if (!\is_admin() || !StaffAccess::shouldHideWpAdminForUser()) {
            return;
        }

        if (\wp_doing_ajax() || (\defined('DOING_CRON') && DOING_CRON)) {
            return;
        }

        $script = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';

        if (\in_array($script, ['admin-ajax.php', 'admin-post.php'], true)) {
            return;
        }

        \wp_safe_redirect(PortalRouter::getPortalUrl());
        exit;
    }

    public static function maybeHideAdminBar(bool $show): bool
    {
        if (StaffAccess::shouldHideWpAdminForUser()) {
            return false;
        }

        return $show;
    }

    /**
     * @param string $redirectTo
     * @param string $requestedRedirectTo
     * @param \WP_User|\WP_Error $user
     */
    public static function filterLoginRedirect(string $redirectTo, string $requestedRedirectTo, $user): string
    {
        unset($requestedRedirectTo);

        if (!$user instanceof \WP_User) {
            return $redirectTo;
        }

        if (!StaffAccess::userCanAccessPortal($user) || \user_can($user, 'manage_options') && !StaffAccess::isPortalRestrictedUser($user)) {
            return $redirectTo;
        }

        return self::getPostLoginRedirectUrl($user);
    }

    public static function getPostLoginRedirectUrl(?\WP_User $user = null, bool $preferPortal = false): string
    {
        $user = $user instanceof \WP_User ? $user : \wp_get_current_user();

        if (!$preferPortal && \user_can($user, 'manage_options') && !StaffAccess::isPortalRestrictedUser($user)) {
            return \admin_url();
        }

        if (!StaffAccess::userCanAccessPortal($user)) {
            return \user_can($user, 'manage_options') ? \admin_url() : \home_url('/');
        }

        if (empty(MustBookingConfig::get_setting('enable_staff_portal', false)) && !\user_can($user, 'manage_options')) {
            return \home_url('/');
        }

        $targetModule = PortalRegistry::normalizeModuleKey((string) MustBookingConfig::get_setting('redirect_worker_after_login', 'dashboard'));

        if (self::userCanAccessModule($targetModule, $user)) {
            return PortalRouter::getModuleUrl($targetModule);
        }

        $fallbackModule = self::getFirstAccessibleModuleKey($user);

        if ($fallbackModule !== '') {
            return PortalRouter::getModuleUrl($fallbackModule);
        }

        return \user_can($user, 'manage_options') ? \admin_url() : \home_url('/');
    }

    public static function getFirstAccessibleModuleKey(?\WP_User $user = null): string
    {
        foreach (PortalRegistry::getEnabledDefinitions() as $key => $definition) {
            unset($definition);

            if (self::userCanAccessModule($key, $user)) {
                return $key;
            }
        }

        return '';
    }

    public static function userCanAccessModule(string $moduleKey, ?\WP_User $user = null): bool
    {
        $moduleKey = PortalRegistry::normalizeModuleKey($moduleKey);
        $visibility = PortalRegistry::getModuleVisibility();

        if (empty($visibility[$moduleKey])) {
            return false;
        }

        return StaffAccess::userCanAccessPortalModule($moduleKey, $user);
    }
}
