<?php

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$activeTab = isset($moduleData['active_tab']) ? (string) $moduleData['active_tab'] : 'rooms';
$tabs = [
    'rooms' => \__('Rooms', 'must-hotel-booking'),
    'room-types' => \__('Room Types', 'must-hotel-booking'),
    'statuses' => \__('Statuses', 'must-hotel-booking'),
    'blocks' => \__('Blocks', 'must-hotel-booking'),
    'rules' => \__('Rules', 'must-hotel-booking'),
    'maintenance' => \__('Maintenance', 'must-hotel-booking'),
];
$moduleUrl = PortalRouter::getModuleUrl('rooms_availability');
$canViewAvailability = \current_user_can(StaffAccess::CAP_AVAILABILITY_RULES_VIEW) || \current_user_can(StaffAccess::CAP_ROOM_BLOCK_MANAGE) || \current_user_can('manage_options');
$canManageBlocks = \current_user_can(StaffAccess::CAP_ROOM_BLOCK_MANAGE) || \current_user_can('manage_options');
$canEditRulesGlobally = \current_user_can(StaffAccess::CAP_AVAILABILITY_RULES_EDIT) || \current_user_can('manage_options');
$canEditRuleRow = static function (array $row) use ($canManageBlocks, $canEditRulesGlobally): bool {
    if ($canEditRulesGlobally) {
        return true;
    }

    return $canManageBlocks && (int) ($row['room_id'] ?? 0) > 0;
};
$canOpenHousekeeping = StaffAccess::userCanAccessPortalModule('housekeeping') || \current_user_can('manage_options');

$buildWorkspaceUrl = static function (string $tab, array $args = []) use ($moduleUrl): string {
    $payload = \array_merge(['tab' => $tab], $args);
    $payload = \array_filter(
        $payload,
        static function ($value): bool {
            return $value !== '' && $value !== null && $value !== 0 && $value !== 'all';
        }
    );

    return \add_query_arg($payload, $moduleUrl);
};

if ($activeTab === 'rooms' && !empty($moduleData['rooms_data']['summary_cards'])) {
    PortalRenderer::renderSummaryCards((array) $moduleData['rooms_data']['summary_cards']);
}

if ($activeTab === 'statuses' && !empty($moduleData['statuses_data']['summary_cards'])) {
    PortalRenderer::renderSummaryCards((array) $moduleData['statuses_data']['summary_cards']);
}

if ($activeTab === 'blocks' && $canViewAvailability && !empty($moduleData['blocks_data']['summary_cards'])) {
    PortalRenderer::renderSummaryCards((array) $moduleData['blocks_data']['summary_cards']);
}

if ($activeTab === 'rules' && $canViewAvailability && !empty($moduleData['rules_data']['summary_cards'])) {
    PortalRenderer::renderSummaryCards((array) $moduleData['rules_data']['summary_cards']);
}
?>
<div class="must-portal-tabs">
    <nav class="must-portal-tab-nav">
        <?php foreach ($tabs as $tabKey => $tabLabel) : ?>
            <a class="must-portal-tab-link<?php echo $activeTab === $tabKey ? ' is-active' : ''; ?>"
               href="<?php echo \esc_url($buildWorkspaceUrl($tabKey)); ?>">
                <?php echo \esc_html($tabLabel); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="must-portal-tab-content">
        <?php
        if ($activeTab === 'rooms') {
            include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/rooms-availability-rooms.php';
        } elseif ($activeTab === 'statuses') {
            include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/rooms-availability-statuses.php';
        } elseif ($activeTab === 'blocks') {
            include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/rooms-availability-blocks.php';
        } elseif ($activeTab === 'rules') {
            include MUST_HOTEL_BOOKING_PATH . 'frontend/templates/portal/rooms-availability-rules.php';
        } else {
            echo '<p class="must-portal-coming-soon">';
            \printf(
                /* translators: %s: tab label */
                \esc_html__('%s will be added in a later Rooms & Availability slice.', 'must-hotel-booking'),
                \esc_html($tabs[$activeTab] ?? $activeTab)
            );
            echo '</p>';
        }
        ?>
    </div>
</div>
