<?php

namespace MustHotelBooking\Portal;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;

final class PortalAuthController
{
    /**
     * @return array<string, mixed>
     */
    public static function prepareLoginPage(): array
    {
        $values = [
            'identifier' => '',
            'remember' => false,
        ];
        $errors = [];
        $notices = [];
        $portalEnabled = !empty(MustBookingConfig::get_setting('enable_staff_portal', false));

        if (!empty($_GET['logged_out'])) {
            $notices[] = \__('You have been signed out of the staff portal.', 'must-hotel-booking');
        }

        if (isset($_POST['must_portal_action']) && (string) \wp_unslash($_POST['must_portal_action']) === 'portal_login') {
            $values['identifier'] = \sanitize_text_field((string) \wp_unslash($_POST['portal_identifier'] ?? ''));
            $values['remember'] = !empty($_POST['portal_remember']);
            $password = isset($_POST['portal_password']) ? (string) \wp_unslash($_POST['portal_password']) : '';
            $nonce = isset($_POST['must_portal_login_nonce']) ? (string) \wp_unslash($_POST['must_portal_login_nonce']) : '';

            if (!\wp_verify_nonce($nonce, 'must_portal_login')) {
                $errors[] = \__('Security check failed. Please reload the page and try again.', 'must-hotel-booking');
            } elseif (!$portalEnabled && !\current_user_can('manage_options')) {
                $errors[] = \__('The staff portal is currently disabled.', 'must-hotel-booking');
            } elseif ($values['identifier'] === '' || $password === '') {
                $errors[] = \__('Enter your username or email address and password.', 'must-hotel-booking');
            } else {
                $identifier = (string) $values['identifier'];
                $login = $identifier;

                if (\strpos($identifier, '@') !== false) {
                    $userByEmail = \get_user_by('email', $identifier);

                    if ($userByEmail instanceof \WP_User) {
                        $login = (string) $userByEmail->user_login;
                    }
                }

                $user = \wp_signon(
                    [
                        'user_login' => $login,
                        'user_password' => $password,
                        'remember' => !empty($values['remember']),
                    ],
                    \is_ssl()
                );

                if (\is_wp_error($user)) {
                    $errors[] = \__('Unable to sign you in with those credentials.', 'must-hotel-booking');
                } elseif (!$user instanceof \WP_User) {
                    $errors[] = \__('Unable to resolve your account for portal access.', 'must-hotel-booking');
                } elseif (!StaffAccess::userCanAccessPortal($user)) {
                    \wp_logout();
                    $errors[] = \__('Your account does not have staff portal access.', 'must-hotel-booking');
                } elseif (!\user_can($user, 'manage_options') && PortalAccessGuard::getFirstAccessibleModuleKey($user) === '') {
                    \wp_logout();
                    $errors[] = \__('No portal modules are currently enabled for your account.', 'must-hotel-booking');
                } else {
                    \wp_safe_redirect(PortalAccessGuard::getPostLoginRedirectUrl($user));
                    exit;
                }
            }
        }

        if (!$portalEnabled && !\current_user_can('manage_options')) {
            $disabledMessage = \__('The staff portal is currently disabled.', 'must-hotel-booking');

            if (!\in_array($disabledMessage, $errors, true)) {
                $errors[] = $disabledMessage;
            }
        }

        return [
            'branding' => self::getBrandingData(),
            'values' => $values,
            'errors' => $errors,
            'notices' => $notices,
            'action_url' => PortalRouter::getLoginUrl(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getBrandingData(): array
    {
        $portalLogo = (string) MustBookingConfig::get_setting('portal_logo_url', '');
        $hotelLogo = (string) MustBookingConfig::get_setting('hotel_logo_url', '');

        return [
            'hotel_name' => MustBookingConfig::get_hotel_name(),
            'logo_url' => $portalLogo !== '' ? $portalLogo : $hotelLogo,
            'welcome_title' => (string) MustBookingConfig::get_setting('portal_welcome_title', \__('Welcome back', 'must-hotel-booking')),
            'welcome_text' => (string) MustBookingConfig::get_setting('portal_welcome_text', ''),
        ];
    }
}
