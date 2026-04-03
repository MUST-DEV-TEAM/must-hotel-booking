<?php

use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$assignmentRows = isset($moduleData['assignment_rows']) && \is_array($moduleData['assignment_rows']) ? $moduleData['assignment_rows'] : [];
$assigneeOptions = isset($moduleData['assignee_options']) && \is_array($moduleData['assignee_options']) ? $moduleData['assignee_options'] : [];
$assignmentsUrl = PortalRouter::getModuleUrl('housekeeping', ['tab' => 'assignments']);
?>
<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Assignments', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html__('Assign rooms to housekeeping staff without leaving the room-board workflow. Authorized supervisory roles can monitor progress and assign work from the same queue.', 'must-hotel-booking'); ?></p>
        </div>
    </div>

    <?php if (empty($assignmentRows)) : ?>
        <?php PortalRenderer::renderEmptyState(\__('No active inventory rooms are available for housekeeping assignments yet.', 'must-hotel-booking')); ?>
    <?php else : ?>
        <div class="must-portal-table-wrap">
            <table class="must-portal-table">
                <thead>
                    <tr>
                        <th><?php echo \esc_html__('Room / Unit', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Operational Context', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Status', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Issues', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Assigned To', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Assignment', 'must-hotel-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignmentRows as $row) : ?>
                        <?php if (!\is_array($row)) { continue; } ?>
                        <?php $roomId = isset($row['id']) ? (int) $row['id'] : 0; ?>
                        <tr>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['room_label'] ?? '')); ?></strong>
                                <?php if (!empty($row['room_type'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['room_type']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($row['room_meta'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['room_meta']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['reservation_context'] ?? '')); ?></strong>
                                <?php if (!empty($row['reservation_context_meta'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['reservation_context_meta']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php PortalRenderer::renderBadge((string) ($row['housekeeping_status_key'] ?? 'info'), (string) ($row['housekeeping_status_label'] ?? '')); ?>
                                <?php if (!empty($row['status_note'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['status_note']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['unresolved_issue_count'])) : ?>
                                    <strong><?php echo \esc_html(\sprintf(_n('%d open issue', '%d open issues', (int) $row['unresolved_issue_count'], 'must-hotel-booking'), (int) $row['unresolved_issue_count'])); ?></strong>
                                <?php else : ?>
                                    <span class="must-portal-muted"><?php echo \esc_html__('No open issues', 'must-hotel-booking'); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($row['resolved_issue_count'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html(\sprintf(_n('%d resolved', '%d resolved', (int) $row['resolved_issue_count'], 'must-hotel-booking'), (int) $row['resolved_issue_count'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['assigned_to_label'])) : ?>
                                    <strong><?php echo \esc_html((string) $row['assigned_to_label']); ?></strong>
                                    <?php if (!empty($row['assigned_at_label'])) : ?>
                                        <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['assigned_at_label']); ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="must-portal-muted"><?php echo \esc_html__('Unassigned', 'must-hotel-booking'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canAssignStaff) : ?>
                                    <form method="post" action="<?php echo \esc_url($assignmentsUrl); ?>" class="must-portal-inline-actions">
                                        <?php \wp_nonce_field('must_portal_housekeeping_assign_' . $roomId, 'must_portal_housekeeping_nonce'); ?>
                                        <input type="hidden" name="must_portal_action" value="housekeeping_assign_room" />
                                        <input type="hidden" name="room_id" value="<?php echo \esc_attr((string) $roomId); ?>" />
                                        <input type="hidden" name="portal_housekeeping_tab" value="assignments" />
                                        <select name="assigned_to_user_id">
                                            <option value="0"><?php echo \esc_html__('Unassigned', 'must-hotel-booking'); ?></option>
                                            <?php foreach ($assigneeOptions as $option) : ?>
                                                <?php if (!\is_array($option)) { continue; } ?>
                                                <?php $optionId = isset($option['id']) ? (int) $option['id'] : 0; ?>
                                                <option value="<?php echo \esc_attr((string) $optionId); ?>"<?php selected((int) ($row['assigned_to_user_id'] ?? 0), $optionId); ?>>
                                                    <?php
                                                    echo \esc_html((string) ($option['label'] ?? ''));

                                                    if (!empty($option['role_label'])) {
                                                        echo ' - ' . \esc_html((string) $option['role_label']);
                                                    }
                                                    ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Save', 'must-hotel-booking'); ?></button>
                                    </form>
                                <?php else : ?>
                                    <span class="must-portal-muted"><?php echo \esc_html__('View only', 'must-hotel-booking'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
