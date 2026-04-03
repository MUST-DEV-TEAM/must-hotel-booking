<?php

use MustHotelBooking\Portal\PortalRenderer;

$currentSearch = isset($_GET['search']) && !\is_array($_GET['search']) ? \sanitize_text_field((string) \wp_unslash($_GET['search'])) : '';
$currentAvailabilityRoomId = isset($_GET['room_id']) ? \absint(\wp_unslash($_GET['room_id'])) : 0;
$currentTimeline = isset($_GET['timeline']) && !\is_array($_GET['timeline']) ? \sanitize_key((string) \wp_unslash($_GET['timeline'])) : '';
$currentStartDate = isset($_GET['start_date']) && !\is_array($_GET['start_date']) ? \sanitize_text_field((string) \wp_unslash($_GET['start_date'])) : '';
$currentEndDate = isset($_GET['end_date']) && !\is_array($_GET['end_date']) ? \sanitize_text_field((string) \wp_unslash($_GET['end_date'])) : '';

if (!$canViewAvailability) {
    PortalRenderer::renderEmptyState(\__('You do not have permission to view room blocks and availability rules in this module.', 'must-hotel-booking'));
    return;
}

$blocksData = isset($moduleData['blocks_data']) && \is_array($moduleData['blocks_data']) ? $moduleData['blocks_data'] : [];
$entryRows = isset($blocksData['entry_rows']) && \is_array($blocksData['entry_rows']) ? $blocksData['entry_rows'] : [];
$roomRows = isset($blocksData['room_rows']) && \is_array($blocksData['room_rows']) ? $blocksData['room_rows'] : [];
$roomOptions = isset($blocksData['room_options']) && \is_array($blocksData['room_options']) ? $blocksData['room_options'] : [];
$blockForm = isset($blocksData['block_form']) && \is_array($blocksData['block_form']) ? $blocksData['block_form'] : [];
$blockErrors = isset($blocksData['block_errors']) && \is_array($blocksData['block_errors']) ? $blocksData['block_errors'] : [];
$currentAction = isset($blocksData['current_action']) ? (string) $blocksData['current_action'] : '';
$editingBlockId = isset($blocksData['editing_id']) ? (int) $blocksData['editing_id'] : 0;
$requestState = isset($blocksData['request']) && \is_array($blocksData['request']) ? $blocksData['request'] : [];
$filters = isset($blocksData['filters']) && \is_array($blocksData['filters']) ? $blocksData['filters'] : [];
$currentSearch = isset($filters['search']) ? (string) $filters['search'] : $currentSearch;
$currentAvailabilityRoomId = isset($filters['room_id']) ? (int) $filters['room_id'] : $currentAvailabilityRoomId;
$currentTimeline = isset($filters['timeline']) ? (string) $filters['timeline'] : $currentTimeline;
$currentStartDate = isset($requestState['start_date']) ? (string) $requestState['start_date'] : $currentStartDate;
$currentEndDate = isset($requestState['end_date']) ? (string) $requestState['end_date'] : $currentEndDate;
$currentFilterArgs = [
    'search' => $currentSearch,
    'room_id' => $currentAvailabilityRoomId,
    'timeline' => $currentTimeline,
    'start_date' => $currentStartDate,
    'end_date' => $currentEndDate,
];
$renderStateInputs = static function (string $action = '', int $blockId = 0) use ($currentSearch, $currentAvailabilityRoomId, $currentTimeline, $currentStartDate, $currentEndDate): void {
    echo '<input type="hidden" name="portal_rooms_availability_tab" value="blocks" />';
    echo '<input type="hidden" name="portal_rooms_availability_action" value="' . \esc_attr($action) . '" />';
    echo '<input type="hidden" name="portal_rooms_availability_block_id" value="' . \esc_attr((string) $blockId) . '" />';
    echo '<input type="hidden" name="portal_rooms_availability_filter_room_id" value="' . \esc_attr((string) $currentAvailabilityRoomId) . '" />';
    echo '<input type="hidden" name="portal_rooms_availability_filter_timeline" value="' . \esc_attr($currentTimeline) . '" />';
    echo '<input type="hidden" name="portal_rooms_availability_filter_search" value="' . \esc_attr($currentSearch) . '" />';
    echo '<input type="hidden" name="portal_rooms_availability_filter_start_date" value="' . \esc_attr($currentStartDate) . '" />';
    echo '<input type="hidden" name="portal_rooms_availability_filter_end_date" value="' . \esc_attr($currentEndDate) . '" />';
};
$blockImpactRows = [];

foreach ($roomRows as $roomRow) {
    if (!\is_array($roomRow)) {
        continue;
    }

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
        <div class="must-portal-panel-header">
            <div>
                <h2><?php echo \esc_html__('Manual Blocks', 'must-hotel-booking'); ?></h2>
                <p><?php echo \esc_html((string) ($blocksData['booking_note'] ?? __('Manual blocks are stored as blocked reservations and still affect sellability.', 'must-hotel-booking'))); ?></p>
            </div>
        </div>
        <?php if (empty($entryRows)) : ?>
            <?php PortalRenderer::renderEmptyState(\__('No manual blocks matched the current filters.', 'must-hotel-booking')); ?>
        <?php else : ?>
            <div class="must-portal-table-wrap">
                <table class="must-portal-table">
                    <thead>
                        <tr>
                            <th><?php echo \esc_html__('Block', 'must-hotel-booking'); ?></th>
                            <th><?php echo \esc_html__('Room Listing', 'must-hotel-booking'); ?></th>
                            <th><?php echo \esc_html__('Dates', 'must-hotel-booking'); ?></th>
                            <th><?php echo \esc_html__('Timeline', 'must-hotel-booking'); ?></th>
                            <th><?php echo \esc_html__('State', 'must-hotel-booking'); ?></th>
                            <?php if ($canManageBlocks) : ?>
                                <th><?php echo \esc_html__('Actions', 'must-hotel-booking'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entryRows as $row) : ?>
                            <?php if (!\is_array($row)) { continue; } ?>
                            <tr>
                                <td>
                                    <strong><?php echo \esc_html((string) ($row['name'] ?? __('Manual block', 'must-hotel-booking'))); ?></strong>
                                    <?php if (!empty($row['reference'])) : ?>
                                        <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['reference']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><a class="must-portal-inline-link" href="<?php echo \esc_url($buildWorkspaceUrl('blocks', ['room_id' => (int) ($row['room_id'] ?? 0)])); ?>"><?php echo \esc_html((string) ($row['room_name'] ?? '')); ?></a></td>
                                <td><?php echo \esc_html((string) ($row['availability_date'] ?? '') . ' - ' . (string) ($row['end_date'] ?? '')); ?></td>
                                <td><?php echo \esc_html(\ucfirst((string) ($row['timeline'] ?? ''))); ?></td>
                                <td><?php PortalRenderer::renderBadge(!empty($row['is_active']) ? 'ok' : 'disabled', (string) ($row['status_label'] ?? '')); ?></td>
                                <?php if ($canManageBlocks) : ?>
                                    <td>
                                        <div class="must-portal-inline-actions">
                                            <a class="must-portal-secondary-button" href="<?php echo \esc_url($buildWorkspaceUrl('blocks', \array_merge($currentFilterArgs, ['action' => 'edit_block', 'block_id' => (int) ($row['id'] ?? 0)]))); ?>">
                                                <?php echo \esc_html__('Edit', 'must-hotel-booking'); ?>
                                            </a>
                                            <form method="post" action="<?php echo \esc_url($moduleUrl); ?>">
                                                <input type="hidden" name="must_portal_action" value="rooms_availability_delete_block" />
                                                <input type="hidden" name="block_id" value="<?php echo \esc_attr((string) ((int) ($row['id'] ?? 0))); ?>" />
                                                <?php \wp_nonce_field('must_availability_delete_block_' . (int) ($row['id'] ?? 0), 'must_availability_block_delete_nonce'); ?>
                                                <?php $renderStateInputs($currentAction, $editingBlockId); ?>
                                                <button type="submit" class="must-portal-danger-button" onclick="return window.confirm('<?php echo \esc_js(__('Release this block?', 'must-hotel-booking')); ?>');">
                                                    <?php echo \esc_html__('Release', 'must-hotel-booking'); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="must-portal-panel">
        <div class="must-portal-panel-header">
            <div>
                <h2><?php echo \esc_html__('Edit Block', 'must-hotel-booking'); ?></h2>
                <p><?php echo \esc_html__('Adjust an existing operational block or release it when the room can be sold again.', 'must-hotel-booking'); ?></p>
            </div>
        </div>

        <?php if (!$canManageBlocks) : ?>
            <?php PortalRenderer::renderEmptyState(\__('You can review block impact here, but only supervisor and operations roles can edit or release manual blocks.', 'must-hotel-booking')); ?>
        <?php elseif ($currentAction !== 'edit_block' || $editingBlockId <= 0) : ?>
            <?php PortalRenderer::renderEmptyState(\__('Select a block from the table to edit its dates, room listing, or release it.', 'must-hotel-booking')); ?>
        <?php else : ?>
            <?php foreach ($blockErrors as $error) : ?>
                <div class="must-portal-flash is-error"><?php echo \esc_html((string) $error); ?></div>
            <?php endforeach; ?>

            <?php if (!empty($blockForm['warnings']) && \is_array($blockForm['warnings'])) : ?>
                <?php foreach ($blockForm['warnings'] as $warning) : ?>
                    <p class="must-portal-muted"><?php echo \esc_html((string) $warning); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo \esc_url($moduleUrl); ?>">
                <input type="hidden" name="must_portal_action" value="rooms_availability_save_block" />
                <input type="hidden" name="block_id" value="<?php echo \esc_attr((string) ((int) ($blockForm['id'] ?? 0))); ?>" />
                <?php \wp_nonce_field('must_availability_save_block', 'must_availability_block_nonce'); ?>
                <?php $renderStateInputs('edit_block', (int) ($blockForm['id'] ?? 0)); ?>
                <div class="must-portal-form-grid">
                    <label>
                        <span><?php echo \esc_html__('Room listing', 'must-hotel-booking'); ?></span>
                        <select name="room_id">
                            <option value=""><?php echo \esc_html__('Select a room listing', 'must-hotel-booking'); ?></option>
                            <?php foreach ($roomOptions as $roomOption) : ?>
                                <?php if (!\is_array($roomOption)) { continue; } ?>
                                <option value="<?php echo \esc_attr((string) ($roomOption['id'] ?? 0)); ?>"<?php echo \selected((int) ($blockForm['room_id'] ?? 0), (int) ($roomOption['id'] ?? 0), false); ?>><?php echo \esc_html((string) ($roomOption['label'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?php echo \esc_html__('Start date', 'must-hotel-booking'); ?></span>
                        <input type="date" name="checkin" value="<?php echo \esc_attr((string) ($blockForm['checkin'] ?? '')); ?>" />
                    </label>
                    <label>
                        <span><?php echo \esc_html__('End date', 'must-hotel-booking'); ?></span>
                        <input type="date" name="checkout" value="<?php echo \esc_attr((string) ($blockForm['checkout'] ?? '')); ?>" />
                    </label>
                    <label class="must-portal-form-full">
                        <span><?php echo \esc_html__('Internal notes', 'must-hotel-booking'); ?></span>
                        <textarea name="notes" rows="4"><?php echo \esc_textarea((string) ($blockForm['notes'] ?? '')); ?></textarea>
                    </label>
                </div>
                <div class="must-portal-inline-actions">
                    <button type="submit" class="must-portal-primary-button"><?php echo \esc_html__('Save block', 'must-hotel-booking'); ?></button>
                    <a class="must-portal-secondary-button" href="<?php echo \esc_url($buildWorkspaceUrl('blocks', $currentFilterArgs)); ?>"><?php echo \esc_html__('Done', 'must-hotel-booking'); ?></a>
                </div>
            </form>
        <?php endif; ?>
    </article>
</section>

<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Blocked Listing Impact', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html__('Block impact still resolves at the sellable room-listing level, even though the merged module also shows physical inventory and housekeeping state.', 'must-hotel-booking'); ?></p>
        </div>
    </div>
    <?php if (empty($blockImpactRows)) : ?>
        <?php PortalRenderer::renderEmptyState(\__('No room listings currently show manual block impact for the selected filters.', 'must-hotel-booking')); ?>
    <?php else : ?>
        <?php foreach ($blockImpactRows as $row) : ?>
            <?php if (!\is_array($row)) { continue; } ?>
            <div class="must-portal-feed-item">
                <div>
                    <strong><?php echo \esc_html((string) ($row['name'] ?? '')); ?></strong>
                    <span><?php echo \esc_html(\sprintf(__('Current blocks: %1$d | Future blocks: %2$d | Future reservations: %3$d', 'must-hotel-booking'), (int) ($row['current_block_count'] ?? 0), (int) ($row['future_block_count'] ?? 0), (int) ($row['future_reservations'] ?? 0))); ?></span>
                    <?php if (!empty($row['warnings']) && \is_array($row['warnings'])) : ?>
                        <small class="must-portal-muted"><?php echo \esc_html((string) $row['warnings'][0]); ?></small>
                    <?php endif; ?>
                </div>
                <?php PortalRenderer::renderBadge(!empty($row['warnings']) ? 'warning' : 'ok', !empty($row['warnings']) ? __('Attention', 'must-hotel-booking') : __('Stable', 'must-hotel-booking')); ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
