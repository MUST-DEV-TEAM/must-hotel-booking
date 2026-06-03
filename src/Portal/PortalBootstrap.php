<?php

namespace MustHotelBooking\Portal;

final class PortalBootstrap
{
    public static function registerHooks(): void
    {
        PortalRouter::registerHooks();
        PortalAccessGuard::registerHooks();
        \add_action('wp_enqueue_scripts', [PortalController::class, 'enqueueAssets']);
        \add_action('wp_ajax_must_portal_quick_booking_disabled_dates', [PortalController::class, 'ajaxQuickBookingDisabledDates']);
        \add_action('wp_ajax_must_portal_quick_booking_available_rooms', [PortalController::class, 'ajaxQuickBookingAvailableRooms']);
        \add_action('wp_ajax_must_portal_quick_booking_preview', [PortalController::class, 'ajaxQuickBookingPreview']);
    }

    public static function registerRewriteRules(): void
    {
        PortalRouter::registerRewriteRules();
    }
}
