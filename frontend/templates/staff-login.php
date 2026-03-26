<?php

if (!\defined('ABSPATH')) {
    exit;
}

$state = \MustHotelBooking\Portal\PortalAuthController::prepareLoginPage();
\MustHotelBooking\Portal\PortalRenderer::renderLoginPage($state);
