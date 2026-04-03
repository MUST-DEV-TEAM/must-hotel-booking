<?php

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$activeTab = isset($moduleData['active_tab']) ? (string) $moduleData['active_tab'] : 'room-board';
$boardRows = isset($moduleData['board_rows']) && \is_array($moduleData['board_rows']) ? $moduleData['board_rows'] : [];
$summaryCards = isset($moduleData['summary_cards']) && \is_array($moduleData['summary_cards']) ? $moduleData['summary_cards'] : [];
$tabs = [
    'room-board' => \__('Room Board', 'must-hotel-booking'),
    'assignments' => \__('Assignments', 'must-hotel-booking'),
    'inspection' => \__('Inspection', 'must-hotel-booking'),
    'maintenance' => \__('Maintenance Issues', 'must-hotel-booking'),
    'handoff' => \__('Shift Handoff', 'must-hotel-booking'),
];
$moduleUrl = PortalRouter::getModuleUrl('housekeeping');
$roomBoardUrl = PortalRouter::getModuleUrl('housekeeping', ['tab' => 'room-board']);
$canUpdateStatus = \current_user_can(StaffAccess::CAP_HOUSEKEEPING_UPDATE_STATUS) || \current_user_can('manage_options');
$canInspectRoom = \current_user_can(StaffAccess::CAP_HOUSEKEEPING_INSPECT) || \current_user_can('manage_options');

$renderStatusAction = static function (string $action, string $label, int $roomId) use ($roomBoardUrl): void {
    $statusKey = \str_replace('housekeeping_mark_', '', $action);

    echo '<form method="post" action="' . \esc_url($roomBoardUrl) . '" class="must-portal-inline-actions">';
    \wp_nonce_field('must_portal_housekeeping_action_' . $statusKey . '_' . $roomId, 'must_portal_housekeeping_nonce');
    echo '<input type="hidden" name="must_portal_action" value="' . \esc_attr($action) . '" />';
    echo '<input type="hidden" name="room_id" value="' . \esc_attr((string) $roomId) . '" />';
    echo '<input type="hidden" name="portal_housekeeping_tab" value="room-board" />';
    echo '<button type="submit" class="must-portal-secondary-button">' . \esc_html($label) . '</button>';
    echo '</form>';
};

if ($activeTab === 'room-board') {
    PortalRenderer::renderSummaryCards($summaryCards);
}
?>
<div class="must-portal-tabs">
    <nav class="must-portal-tab-nav">
        <?php foreach ($tabs as $tabKey => $tabLabel) : ?>
            <a class="must-portal-tab-link<?php echo $activeTab === $tabKey ? ' is-active' : ''; ?>"
               href="<?php echo \esc_url(\add_query_arg('tab', $tabKey, $moduleUrl)); ?>">
                <?php echo \esc_html($tabLabel); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="must-portal-tab-content">
        <?php if ($activeTab !== 'room-board') : ?>
            <p class="must-portal-coming-soon">
                <?php
                \printf(
                    /* translators: %s: tab label */
                    \esc_html__('%s will be added in a later housekeeping slice.', 'must-hotel-booking'),
                    \esc_html($tabs[$activeTab] ?? $activeTab)
                );
                ?>
            </p>
        <?php else : ?>
            <section class="must-portal-panel">
                <div class="must-portal-panel-header">
                    <div>
                        <h2><?php echo \esc_html__('Room Board', 'must-hotel-booking'); ?></h2>
                        <p><?php echo \esc_html__('Track each active inventory unit, see basic occupancy context, and update the first housekeeping statuses directly from the portal.', 'must-hotel-booking'); ?></p>
                    </div>
                </div>

                <?php if (empty($boardRows)) : ?>
                    <?php PortalRenderer::renderEmptyState(\__('No active inventory rooms are available for the housekeeping board yet.', 'must-hotel-booking')); ?>
                <?php else : ?>
                    <div class="must-portal-table-wrap">
                        <table class="must-portal-table">
                            <thead>
                                <tr>
                                    <th><?php echo \esc_html__('Room / Unit', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Room Type', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Operational Context', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Housekeeping Status', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Actions', 'must-hotel-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($boardRows as $row) : ?>
                                    <?php if (!\is_array($row)) { continue; } ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo \esc_html((string) ($row['room_label'] ?? '')); ?></strong>
                                            <?php if (!empty($row['room_title']) && (string) $row['room_title'] !== (string) ($row['room_label'] ?? '')) : ?>
                                                <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['room_title']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($row['room_meta'])) : ?>
                                                <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['room_meta']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo \esc_html((string) (($row['room_type'] ?? '') !== '' ? $row['room_type'] : __('Not mapped', 'must-hotel-booking'))); ?>
                                        </td>
                                        <td>
                                            <strong><?php echo \esc_html((string) ($row['reservation_context'] ?? '')); ?></strong>
                                            <?php if (!empty($row['reservation_context_meta'])) : ?>
                                                <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['reservation_context_meta']); ?></span>
                                            <?php endif; ?>
                                            <div class="must-portal-inline-actions">
                                                <?php PortalRenderer::renderBadge((string) ($row['inventory_status_key'] ?? 'available'), (string) ($row['inventory_status_label'] ?? '')); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php PortalRenderer::renderBadge((string) ($row['housekeeping_status_key'] ?? 'info'), (string) ($row['housekeeping_status_label'] ?? '')); ?>
                                            <?php if (!empty($row['status_note'])) : ?>
                                                <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['status_note']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="must-portal-inline-actions">
                                                <?php
                                                $roomId = (int) ($row['id'] ?? 0);
                                                $renderedAction = false;

                                                if ($canUpdateStatus && !empty($row['can_mark_dirty'])) {
                                                    $renderStatusAction('housekeeping_mark_dirty', \__('Mark dirty', 'must-hotel-booking'), $roomId);
                                                    $renderedAction = true;
                                                }

                                                if ($canUpdateStatus && !empty($row['can_mark_clean'])) {
                                                    $renderStatusAction('housekeeping_mark_clean', \__('Mark clean', 'must-hotel-booking'), $roomId);
                                                    $renderedAction = true;
                                                }

                                                if ($canInspectRoom && !empty($row['can_mark_inspected'])) {
                                                    $renderStatusAction('housekeeping_mark_inspected', \__('Mark inspected', 'must-hotel-booking'), $roomId);
                                                    $renderedAction = true;
                                                }

                                                if (!$renderedAction) {
                                                    echo '<span class="must-portal-muted">' . \esc_html__('No status action available.', 'must-hotel-booking') . '</span>';
                                                }
                                                ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
