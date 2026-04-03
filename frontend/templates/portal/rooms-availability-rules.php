<?php

use MustHotelBooking\Portal\PortalRenderer;

$currentSearch = isset($_GET['search']) && !\is_array($_GET['search']) ? \sanitize_text_field((string) \wp_unslash($_GET['search'])) : '';
$currentAvailabilityRoomId = isset($_GET['room_id']) ? \absint(\wp_unslash($_GET['room_id'])) : 0;
$currentTimeline = isset($_GET['timeline']) && !\is_array($_GET['timeline']) ? \sanitize_key((string) \wp_unslash($_GET['timeline'])) : '';
$currentAvailabilityStatus = isset($_GET['status']) && !\is_array($_GET['status']) ? \sanitize_key((string) \wp_unslash($_GET['status'])) : '';
$currentRuleType = isset($_GET['rule_type']) && !\is_array($_GET['rule_type']) ? \sanitize_key((string) \wp_unslash($_GET['rule_type'])) : '';
$ruleTypeOptions = [
    'all' => \__('All rule types', 'must-hotel-booking'),
    'maintenance_block' => \__('Blocked date range', 'must-hotel-booking'),
    'minimum_stay' => \__('Minimum stay', 'must-hotel-booking'),
    'maximum_stay' => \__('Maximum stay', 'must-hotel-booking'),
    'closed_arrival' => \__('Closed arrival', 'must-hotel-booking'),
    'closed_departure' => \__('Closed departure', 'must-hotel-booking'),
];

if (!$canViewAvailability) {
    PortalRenderer::renderEmptyState(\__('You do not have permission to view room blocks and availability rules in this module.', 'must-hotel-booking'));
    return;
}

$rulesData = isset($moduleData['rules_data']) && \is_array($moduleData['rules_data']) ? $moduleData['rules_data'] : [];
$entryRows = isset($rulesData['entry_rows']) && \is_array($rulesData['entry_rows']) ? $rulesData['entry_rows'] : [];
$roomRows = isset($rulesData['room_rows']) && \is_array($rulesData['room_rows']) ? $rulesData['room_rows'] : [];
$roomOptions = isset($rulesData['room_options']) && \is_array($rulesData['room_options']) ? $rulesData['room_options'] : [];
?>
<form class="must-portal-filter-bar" method="get" action="<?php echo \esc_url($moduleUrl); ?>">
    <input type="hidden" name="tab" value="rules" />
    <input type="search" name="search" value="<?php echo \esc_attr($currentSearch); ?>" placeholder="<?php echo \esc_attr__('Rule name, room listing, rule type', 'must-hotel-booking'); ?>" />
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
    <select name="status">
        <option value="all"><?php echo \esc_html__('All states', 'must-hotel-booking'); ?></option>
        <option value="active"<?php echo \selected($currentAvailabilityStatus, 'active', false); ?>><?php echo \esc_html__('Active', 'must-hotel-booking'); ?></option>
        <option value="inactive"<?php echo \selected($currentAvailabilityStatus, 'inactive', false); ?>><?php echo \esc_html__('Inactive', 'must-hotel-booking'); ?></option>
    </select>
    <select name="rule_type">
        <?php foreach ($ruleTypeOptions as $ruleTypeKey => $ruleTypeLabel) : ?>
            <option value="<?php echo \esc_attr((string) $ruleTypeKey); ?>"<?php echo \selected($currentRuleType !== '' ? $currentRuleType : 'all', (string) $ruleTypeKey, false); ?>><?php echo \esc_html((string) $ruleTypeLabel); ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Apply', 'must-hotel-booking'); ?></button>
</form>
<section class="must-portal-grid must-portal-grid--2">
    <article class="must-portal-panel">
        <div class="must-portal-panel-header"><div><h2><?php echo \esc_html__('Availability Rules', 'must-hotel-booking'); ?></h2><p><?php echo \esc_html((string) ($rulesData['booking_note'] ?? __('Restriction rules are still resolved at the room-listing level.', 'must-hotel-booking'))); ?></p></div></div>
        <?php if (empty($entryRows)) : ?>
            <?php PortalRenderer::renderEmptyState(\__('No availability rules matched the current filters.', 'must-hotel-booking')); ?>
        <?php else : ?>
            <div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th><?php echo \esc_html__('Rule', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('Room Listing', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('Dates', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('Arrival / Departure', 'must-hotel-booking'); ?></th><th><?php echo \esc_html__('State', 'must-hotel-booking'); ?></th></tr></thead><tbody>
            <?php foreach ($entryRows as $row) : ?>
                <?php if (!\is_array($row)) { continue; } ?>
                <tr>
                    <td><strong><?php echo \esc_html((string) ($row['rule_type_label'] ?? '')); ?></strong><?php if (!empty($row['name'])) : ?><br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['name']); ?></span><?php endif; ?></td>
                    <td><a class="must-portal-inline-link" href="<?php echo \esc_url($buildWorkspaceUrl('rules', ['room_id' => (int) ($row['room_id'] ?? 0)])); ?>"><?php echo \esc_html((string) ($row['room_name'] ?? '')); ?></a></td>
                    <td><?php echo \esc_html((string) ($row['availability_date'] ?? '') . ' - ' . (string) ($row['end_date'] ?? '')); ?></td>
                    <td><?php echo \esc_html((string) ($row['checkin'] ?? '')) . ' / ' . \esc_html((string) ($row['checkout'] ?? '')); ?></td>
                    <td><?php PortalRenderer::renderBadge(!empty($row['is_active']) ? 'ok' : 'disabled', (string) ($row['status_label'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>
    <article class="must-portal-panel">
        <div class="must-portal-panel-header"><div><h2><?php echo \esc_html__('Rule Health by Listing', 'must-hotel-booking'); ?></h2><p><?php echo \esc_html__('The merged workspace still surfaces listing-level restriction health so operations staff can see conflicts before they become front-desk problems.', 'must-hotel-booking'); ?></p></div></div>
        <?php if (empty($roomRows)) : ?>
            <?php PortalRenderer::renderEmptyState(\__('No room-listing rule data matched the current filters.', 'must-hotel-booking')); ?>
        <?php else : ?>
            <?php foreach ($roomRows as $row) : ?>
                <?php if (!\is_array($row)) { continue; } ?>
                <div class="must-portal-feed-item"><div><strong><?php echo \esc_html((string) ($row['name'] ?? '')); ?></strong><span><?php echo \esc_html(\sprintf(__('Rules: %1$d | Current blocks: %2$d | Future blocks: %3$d', 'must-hotel-booking'), (int) ($row['rule_count'] ?? 0), (int) ($row['current_block_count'] ?? 0), (int) ($row['future_block_count'] ?? 0))); ?></span><?php if (!empty($row['warnings']) && \is_array($row['warnings'])) : ?><small class="must-portal-muted"><?php echo \esc_html((string) $row['warnings'][0]); ?></small><?php endif; ?></div><?php PortalRenderer::renderBadge(!empty($row['warnings']) ? 'warning' : 'ok', !empty($row['warnings']) ? __('Attention', 'must-hotel-booking') : __('Healthy', 'must-hotel-booking')); ?></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>
</section>
