<?php

if (!\defined('ABSPATH')) {
    exit;
}

$state = \MustHotelBooking\Portal\PortalController::preparePortalPage();
\MustHotelBooking\Portal\PortalRenderer::renderPortalPage($state);
