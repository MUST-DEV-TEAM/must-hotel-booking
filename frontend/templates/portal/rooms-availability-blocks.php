<?php

use MustHotelBooking\Portal\PortalRenderer;

$currentSearch = isset($_GET['search']) && !\is_array($_GET['search']) ? \sanitize_text_field((string) \wp_unslash($_GET['search'])) : '';
$currentAvailabilityRoomId = isset($_GET['room_id']) ? \absint(\wp_unslash($_GET['room_id'])) : 0;
$currentTimeline = isset($_GET['timeline']) && !\is_array($_GET['timeline']) ? \sanitize_key((string) \wp_unslash($_GET['timeline'])) : '';

if (!$canViewAvailability) {
    PortalRenderer::renderEmptyState(\__('You do not have permission to view room blocks and availability rules in this module.', 'must-hotel-booking'));
    return;
}

$blocksData = isset($moduleData['blocks_data']) && \is_array($moduleData['blocks_data']) ? $moduleData['blocks_data'] : [];
$entryRows = isset($blocksData['entry_rows']) && \is_array($blocksData['entry_rows']) ? $blocksData['entry_rows'] : [];
$roomRows = isset($blocksData['room_rows']) && \is_array($blocksData['room_rows']) ? $blocksData['room_rows'] : [];
$roomOptions = isset($blocksData['room_options']) && \is_array($blocksData['room_options']) ? $blocksData['room_options'] : [];
$blockImpactRows = [];
foreach ($roomRows as $roomRow) {
    if (!\is_array($roomRow)) { continue; }
    if ((int) ($roomRow['future_block_count'] ?? 0) > 0 || (int) ($roomRow['current_block_count'] ?? 0) > 0 || !empty($roomRow['warnings'])) {
        $blockImpactRows[] = $roomRow;
    }
}
?>
<form class="must-portal-filter-bar" method="get" action="<?php echo \esc_url($moduleUrl); ?>">
    <input type="hidden" name="tab" value="blocks" />
    <input type="search" name="search" value="<?php echo \esc_attr($currentSearch); ?>" placeholder="<?php echo \esc_attr__('Block name, room listing', 'must-hotel-booking'); ?>" />
    <select name="room_id">
        <option value=""><?php echo \esc_html__('All room listings', 'must-hotel-booking'); ?></option>
        <?php foreach ($roomOptions as $roomOption) : ?>
            <?php if (!\is_array($roomOption)) { continue; } ?>
            <option value="<?php echo \esc_attr((string) ($roomOption['id'] ?? 0)); ?>"<?php echo \selected($currentAvailabilityRoomId, (int) ($roomOption['id'] ?? 0), false); ?>><?php echo \esc_html((string) ($roomOption['label'] ?? '')); ?></option>
        <?php endforeach; ?>
    </select>
    <select name="timeline">
        <option value="all"><?php echo \esc_html__('All timelines', 'must-hotel-booking'); ?></option>
        <option value="current"<?php echo \selected($currentTimeline, 'current', false); ?>><?php echo \esc_html__('Current', 'must-hotel-booking'); ?></option>
        <option value="future"<?php echo \selected($currentTimeline, 'future', false); ?>><?php echo \esc_html__('Future', 'must-hotel-booking'); ?></option>
        <option value="past"<?php echo \selected($currentTimeline, 'past', false); ?>><?php echo \esc_html__('Past', 'must-hotel-booking'); ?></option>
    </select>
    <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Apply', 'must-hotel-booking'); ?></button>
</form>
<section class="must-portal-grid must-portal-grid--2">
    <article class="must-portal-panel">
        <div class="must-portal-panel-header"><div><h2><?php echo \esc_html__('Manual Blocks', 'must-hotel-booking'); ?></h2><p><?php echo \esc_html((string) ($blocksData['booking_note'] ?? __('Manual blocks are stored as blocked reservations and still affect sellability.', 'must-hotel-booking'))); ?></p></div></div>
        <?php if (empty($entryRows)) : ?>
            <?php PortalRenderer::renderEmptyState(\__('No manual blocks matched the current filters.', 'must-hotel-booking')); ?>
        <?php else : ?>
            <div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th><?php echo \esc_html__('Block', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('Room Listing', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('Dates', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('Timeline', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('State', 'must-hotel-booking'); ?></th></tr></thead><tbody>
            <?php foreach ($entryRows as $row) : ?>
                <?php if (!\is_array($row)) { continue; } ?>
                <tr>
                    <td><strong><?php echo \esc_html((string) ($row['name'] ?? __('Manual block', 'must-hotel-booking'))); ?></strong></td>
                    <td><a class="must-portal-inline-link" href="<?php echo \esc_url($buildWorkspaceUrl('blocks', ['room_id' => (int) ($row['room_id'] ?? 0)])); ?>"><?php echo \esc_html((string) ($row['room_name'] ?? '')); ?></a></td>
                    <td><?php echo \esc_html((string) ($row['availability_date'] ?? '') . ' - ' . (string) ($row['end_date'] ?? '')); ?></td>
                    <td><?php echo \esc_html(\ucfirst((string) ($row['timeline'] ?? ''))); ?></td>
                    <td><?php PortalRenderer::renderBadge(!empty($row['is_active']) ? 'ok' : 'disabled', (string) ($row['status_label'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>
    <article class="must-portal-panel">
        <div class="must-portal-panel-header"><div><h2><?php echo \esc_html__('Blocked Listing Impact', 'must-hotel-booking'); ?></h2><p><?php echo \esc_html__('Block impact still resolves at the sellable room-listing level, even though the merged module also shows physical inventory and housekeeping state.', 'must-hotel-booking'); ?></p></div></div>
        <?php if (empty($blockImpactRows)) : ?>
            <?php PortalRenderer::renderEmptyState(\__('No room listings currently show manual block impact for the selected filters.', 'must-hotel-booking')); ?>
        <?php else : ?>
            <?php foreach ($blockImpactRows as $row) : ?>
                <?php if (!\is_array($row)) { continue; } ?>
                <div class="must-portal-feed-item"><div><strong><?php echo \esc_html((string) ($row['name'] ?? '')); ?></strong><span><?php echo \esc_html(\sprintf(__('Current blocks: %1$d | Future blocks: %2$d | Future reservations: %3$d', 'must-hotel-booking'), (int) ($row['current_block_count'] ?? 0), (int) ($row['future_block_count'] ?? 0), (int) ($row['future_reservations'] ?? 0))); ?></span><?php if (!empty($row['warnings']) && \is_array($row['warnings'])) : ?><small class="must-portal-muted"><?php echo \esc_html((string) $row['warnings'][0]); ?></small><?php endif; ?></div><?php PortalRenderer::renderBadge(!empty($row['warnings']) ? 'warning' : 'ok', !empty($row['warnings']) ? __('Attention', 'must-hotel-booking') : __('Stable', 'must-hotel-booking')); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>
</section>
