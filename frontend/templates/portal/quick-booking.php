<?php

use MustHotelBooking\Portal\PortalRouter;

$frontDeskUrl = PortalRouter::getModuleUrl('front_desk', ['tab' => 'new-booking']);

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Quick Booking Moved', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Standalone quick booking is deprecated. Use the Front Desk workspace for new reservations.', 'must-hotel-booking') . '</p></div></div>';
echo '<div class="must-portal-inline-actions"><a class="must-portal-primary-button" href="' . \esc_url($frontDeskUrl) . '">' . \esc_html__('Open Front Desk', 'must-hotel-booking') . '</a></div>';
echo '</section>';
