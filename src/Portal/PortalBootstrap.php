<?php

namespace MustHotelBooking\Portal;

final class PortalBootstrap
{
    public static function registerHooks(): void
    {
        PortalRouter::registerHooks();
        PortalAccessGuard::registerHooks();
        \add_action('wp_enqueue_scripts', [PortalController::class, 'enqueueAssets']);
    }

    public static function registerRewriteRules(): void
    {
        PortalRouter::registerRewriteRules();
    }
}
