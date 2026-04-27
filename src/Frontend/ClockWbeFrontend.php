<?php

namespace MustHotelBooking\Frontend;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\ReservationEngine;

final class ClockWbeFrontend
{
    private static bool $hooksRegistered = false;
    private static bool $snippetInjected = false;

    public static function registerHooks(): void
    {
        if (self::$hooksRegistered) {
            return;
        }

        self::$hooksRegistered = true;

        \add_action('template_redirect', [self::class, 'maybeBypassLegacyBookingFlowPages'], 1);
        \add_action('wp_head', [self::class, 'maybeInjectHeadSnippet'], 1);
    }

    public static function isClockWbeInlineMode(): bool
    {
        return MustBookingConfig::get_website_booking_flow_mode() === 'clock_wbe_inline';
    }

    public static function isReady(): bool
    {
        return self::isClockWbeInlineMode() && self::hasConfiguredHeadSnippet();
    }

    public static function hasConfiguredHeadSnippet(): bool
    {
        return MustBookingConfig::get_clock_wbe_inline_head_snippet() !== '';
    }

    public static function shouldRenderInlineBookingUi(): bool
    {
        return self::isReady();
    }

    public static function shouldShowFrontendConfigurationWarning(): bool
    {
        return self::isClockWbeInlineMode()
            && !self::hasConfiguredHeadSnippet()
            && \current_user_can('manage_options');
    }

    public static function getOperationalWarningMessage(): string
    {
        return \__(
            'Clock WBE Inline mode is active. Public website bookings are created in Clock WBE and may not appear locally until real Clock API sync is configured. Use Clock PMS as the source of truth.',
            'must-hotel-booking'
        );
    }

    public static function getFrontendConfigurationWarningMarkup(): string
    {
        if (!self::shouldShowFrontendConfigurationWarning()) {
            return '';
        }

        $settingsUrl = \admin_url('admin.php?page=must-hotel-booking-settings&tab=provider');
        $message = \__(
            'Clock WBE Inline mode is active, but the required head snippet is missing. Configure it in Provider settings before exposing this booking control.',
            'must-hotel-booking'
        );

        return '<div class="must-hotel-booking-wbe-inline-warning"><p>'
            . \esc_html($message)
            . ' <a href="' . \esc_url($settingsUrl) . '">'
            . \esc_html__('Open Provider Settings', 'must-hotel-booking')
            . '</a></p></div>';
    }

    public static function maybeInjectHeadSnippet(): void
    {
        if (self::$snippetInjected || !self::isReady() || !self::isNormalPublicFrontendRequest()) {
            return;
        }

        self::$snippetInjected = true;

        echo "\n<!-- MUST Hotel Booking: Clock WBE Inline -->\n";
        echo MustBookingConfig::get_clock_wbe_inline_head_snippet();
        echo "\n<!-- /MUST Hotel Booking: Clock WBE Inline -->\n";
    }

    public static function maybeBypassLegacyBookingFlowPages(): void
    {
        if (!self::isClockWbeInlineMode() || !self::isNormalPublicFrontendRequest()) {
            return;
        }

        if (
            ManagedPages::isCurrentPage('page_booking_id', 'booking')
            || ManagedPages::isCurrentPage('page_booking_accommodation_id', 'booking-accommodation')
            || ManagedPages::isCurrentPage('page_checkout_id', 'checkout')
        ) {
            self::redirectAwayFromLegacyBookingFlow();
        }

        if (
            ManagedPages::isCurrentPage('page_booking_confirmation_id', 'booking-confirmation')
            && !self::isRecognizedLocalConfirmationRequest()
        ) {
            self::redirectAwayFromLegacyBookingFlow();
        }
    }

    public static function isRecognizedLocalConfirmationRequest(): bool
    {
        $bookingId = isset($_GET['booking_id'])
            ? \trim(\sanitize_text_field((string) \wp_unslash($_GET['booking_id'])))
            : '';
        $requestAction = isset($_GET['must_action'])
            ? \sanitize_key((string) \wp_unslash($_GET['must_action']))
            : '';
        $stripeReturn = isset($_GET['stripe_return'])
            ? \sanitize_key((string) \wp_unslash($_GET['stripe_return']))
            : '';
        $reservationIds = ReservationEngine::getReservationIdsFromSource(\is_array($_GET) ? $_GET : []);

        if (!empty($reservationIds) && !empty(ReservationEngine::getConfirmationRowsByIds($reservationIds))) {
            return true;
        }

        if ($bookingId !== '' && !empty(ReservationEngine::getConfirmationRowsByBookingId($bookingId))) {
            return true;
        }

        return $requestAction === 'cancel_reservation'
            || ($stripeReturn !== '' && self::hasLocalConfirmationResumeSelection());
    }

    private static function redirectAwayFromLegacyBookingFlow(): void
    {
        $redirectUrl = self::getLegacyBookingBypassRedirectUrl();

        if ($redirectUrl === '') {
            return;
        }

        \wp_safe_redirect($redirectUrl);
        exit;
    }

    private static function getLegacyBookingBypassRedirectUrl(): string
    {
        $roomsPageId = (int) MustBookingConfig::get_setting('page_rooms_id', 0);
        $currentPageId = \get_queried_object_id();
        $assignedRoomsPageUrl = self::getAssignedRoomsPageUrl($roomsPageId);

        if ($assignedRoomsPageUrl !== '' && !($roomsPageId > 0 && $currentPageId === $roomsPageId)) {
            return $assignedRoomsPageUrl;
        }

        return \home_url('/');
    }

    private static function isNormalPublicFrontendRequest(): bool
    {
        if (\is_admin() || \wp_doing_ajax() || \wp_doing_cron()) {
            return false;
        }

        if ((\defined('REST_REQUEST') && REST_REQUEST) || (\function_exists('wp_is_json_request') && \wp_is_json_request())) {
            return false;
        }

        if (
            \is_feed()
            || \is_embed()
            || \is_trackback()
            || (\function_exists('is_robots') && \is_robots())
            || (\function_exists('is_favicon') && \is_favicon())
        ) {
            return false;
        }

        return true;
    }

    private static function hasLocalConfirmationResumeSelection(): bool
    {
        if (!\function_exists(__NAMESPACE__ . '\get_booking_selection_flow_data')) {
            return false;
        }

        $flowData = get_booking_selection_flow_data();
        $guestForm = isset($flowData['guest_form']) && \is_array($flowData['guest_form']) ? $flowData['guest_form'] : [];

        return \function_exists(__NAMESPACE__ . '\has_booking_selected_rooms')
            && has_booking_selected_rooms()
            && !empty($guestForm);
    }

    private static function getAssignedRoomsPageUrl(int $roomsPageId): string
    {
        if ($roomsPageId <= 0) {
            return '';
        }

        $page = \get_post($roomsPageId);

        if (!$page instanceof \WP_Post || $page->post_type !== 'page' || $page->post_status === 'trash') {
            return '';
        }

        $permalink = \get_permalink($roomsPageId);

        return \is_string($permalink) ? $permalink : '';
    }
}
