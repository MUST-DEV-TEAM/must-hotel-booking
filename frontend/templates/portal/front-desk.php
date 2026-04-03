<?php

use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $state */
$moduleData = isset($state['module_data']) && \is_array($state['module_data']) ? $state['module_data'] : [];
$activeTab  = isset($moduleData['active_tab']) ? (string) $moduleData['active_tab'] : 'new-booking';

$tabs = [
    'new-booking' => \__('New Booking', 'must-hotel-booking'),
    'walk-in'     => \__('Walk-in', 'must-hotel-booking'),
    'checkin'     => \__('Check-in Queue', 'must-hotel-booking'),
    'checkout'    => \__('Check-out Queue', 'must-hotel-booking'),
    'room-move'   => \__('Room Move / Upgrade', 'must-hotel-booking'),
    'log'         => \__('Desk Log', 'must-hotel-booking'),
];

if (!isset($tabs[$activeTab])) {
    $activeTab = 'new-booking';
}

$moduleUrl = PortalRouter::getModuleUrl('front_desk');

echo '<div class="must-portal-tabs">';
echo '<nav class="must-portal-tab-nav">';

foreach ($tabs as $tabKey => $tabLabel) {
    echo '<a class="must-portal-tab-link' . ($activeTab === $tabKey ? ' is-active' : '') . '" href="' . \esc_url(\add_query_arg('tab', $tabKey, $moduleUrl)) . '">';
    echo \esc_html($tabLabel);
    echo '</a>';
}

echo '</nav>';
echo '<div class="must-portal-tab-content">';

if ($activeTab === 'new-booking') {
    include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/front-desk-new-booking.php';
} elseif ($activeTab === 'checkin' || $activeTab === 'checkout') {
    include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/front-desk-queue.php';
} elseif ($activeTab === 'room-move') {
    include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/front-desk-room-move.php';
} elseif ($activeTab === 'log') {
    include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/front-desk-log.php';
} elseif ($activeTab === 'walk-in') {
    echo '<div class="must-portal-panel"><div class="must-portal-panel-header"><div>';
    echo '<h2>' . \esc_html__('Walk-in', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Walk-in is inactive for now. Use New Booking for manual desk reservations.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<div class="must-portal-header-actions"><a class="must-portal-button must-portal-button-primary" href="' . \esc_url(\add_query_arg('tab', 'new-booking', $moduleUrl)) . '">' . \esc_html__('Open New Booking', 'must-hotel-booking') . '</a></div>';
    echo '</div></div>';
} else {
    echo '<p class="must-portal-coming-soon">';
    \printf(
        /* translators: %s: tab label */
        \esc_html__('%s - coming in the next implementation phase.', 'must-hotel-booking'),
        \esc_html($tabs[$activeTab] ?? $activeTab)
    );
    echo '</p>';
}

echo '</div>';
echo '</div>';
