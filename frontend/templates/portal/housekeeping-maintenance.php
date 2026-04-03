<?php

use MustHotelBooking\Database\HousekeepingRepository;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$maintenanceSummaryCards = isset($moduleData['maintenance_summary_cards']) && \is_array($moduleData['maintenance_summary_cards']) ? $moduleData['maintenance_summary_cards'] : [];
$issueRows = isset($moduleData['issue_rows']) && \is_array($moduleData['issue_rows']) ? $moduleData['issue_rows'] : [];
$issueForm = isset($moduleData['issue_form']) && \is_array($moduleData['issue_form']) ? $moduleData['issue_form'] : [];
$issueFormRoomId = isset($issueForm['issue_room_id']) ? (int) $issueForm['issue_room_id'] : 0;
$issueFormTitle = isset($issueForm['issue_title']) ? (string) $issueForm['issue_title'] : '';
$issueFormDetails = isset($issueForm['issue_details']) ? (string) $issueForm['issue_details'] : '';
$maintenanceUrl = PortalRouter::getModuleUrl('housekeeping', ['tab' => 'maintenance']);

if (!empty($maintenanceSummaryCards)) {
    PortalRenderer::renderSummaryCards($maintenanceSummaryCards);
}
?>
<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Maintenance Issues', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html__('Track room-linked operational issues from the housekeeping workspace without turning this into a separate maintenance platform.', 'must-hotel-booking'); ?></p>
        </div>
    </div>

    <?php if ($canCreateIssue) : ?>
        <form method="post" action="<?php echo \esc_url($maintenanceUrl); ?>" class="must-portal-form">
            <?php \wp_nonce_field('must_portal_housekeeping_create_issue', 'must_portal_housekeeping_nonce'); ?>
            <input type="hidden" name="must_portal_action" value="housekeeping_create_issue" />
            <input type="hidden" name="portal_housekeeping_tab" value="maintenance" />
            <div class="must-portal-form-grid">
                <label>
                    <span><?php echo \esc_html__('Room / Unit', 'must-hotel-booking'); ?></span>
                    <select name="issue_room_id" required>
                        <option value=""><?php echo \esc_html__('Select a room', 'must-hotel-booking'); ?></option>
                        <?php foreach ($boardRows as $roomRow) : ?>
                            <?php if (!\is_array($roomRow)) { continue; } ?>
                            <?php $roomId = isset($roomRow['id']) ? (int) $roomRow['id'] : 0; ?>
                            <option value="<?php echo \esc_attr((string) $roomId); ?>"<?php selected($issueFormRoomId, $roomId); ?>>
                                <?php echo \esc_html((string) ($roomRow['room_label'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php echo \esc_html__('Issue Title', 'must-hotel-booking'); ?></span>
                    <input type="text" name="issue_title" value="<?php echo \esc_attr($issueFormTitle); ?>" placeholder="<?php echo \esc_attr__('Example: AC not cooling', 'must-hotel-booking'); ?>" required />
                </label>
            </div>
            <label>
                <span><?php echo \esc_html__('Issue Details', 'must-hotel-booking'); ?></span>
                <textarea name="issue_details" rows="3" placeholder="<?php echo \esc_attr__('Add the operational details the next person should know.', 'must-hotel-booking'); ?>"><?php echo \esc_textarea($issueFormDetails); ?></textarea>
            </label>
            <div class="must-portal-header-actions">
                <button type="submit" class="must-portal-button must-portal-button-primary"><?php echo \esc_html__('Create Issue', 'must-hotel-booking'); ?></button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (empty($issueRows)) : ?>
        <?php PortalRenderer::renderEmptyState(\__('No maintenance issues are tracked yet.', 'must-hotel-booking')); ?>
    <?php else : ?>
        <div class="must-portal-table-wrap">
            <table class="must-portal-table">
                <thead>
                    <tr>
                        <th><?php echo \esc_html__('Room / Unit', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Issue', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Status', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Updated', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Actions', 'must-hotel-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($issueRows as $row) : ?>
                        <?php if (!\is_array($row)) { continue; } ?>
                        <?php
                        $issueId = isset($row['id']) ? (int) $row['id'] : 0;
                        $statusKey = (string) ($row['status_key'] ?? '');
                        $badgeKey = $statusKey === HousekeepingRepository::ISSUE_STATUS_RESOLVED
                            ? 'success'
                            : ($statusKey === HousekeepingRepository::ISSUE_STATUS_IN_PROGRESS ? 'pending' : 'warning');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['room_label'] ?? '')); ?></strong>
                                <?php if (!empty($row['room_type'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['room_type']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['issue_title'] ?? '')); ?></strong>
                                <?php if (!empty($row['issue_details'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['issue_details']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php PortalRenderer::renderBadge($badgeKey, (string) ($row['status_label'] ?? '')); ?></td>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['updated_at'] ?? '')); ?></strong>
                                <?php if (!empty($row['updated_by_label'])) : ?>
                                    <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['updated_by_label']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="must-portal-inline-actions">
                                    <?php if ($canCreateIssue && $statusKey !== HousekeepingRepository::ISSUE_STATUS_IN_PROGRESS) : ?>
                                        <form method="post" action="<?php echo \esc_url($maintenanceUrl); ?>" class="must-portal-inline-actions">
                                            <?php \wp_nonce_field('must_portal_housekeeping_issue_status_' . $issueId, 'must_portal_housekeeping_nonce'); ?>
                                            <input type="hidden" name="must_portal_action" value="housekeeping_update_issue_status" />
                                            <input type="hidden" name="issue_id" value="<?php echo \esc_attr((string) $issueId); ?>" />
                                            <input type="hidden" name="issue_status" value="<?php echo \esc_attr(HousekeepingRepository::ISSUE_STATUS_IN_PROGRESS); ?>" />
                                            <input type="hidden" name="portal_housekeeping_tab" value="maintenance" />
                                            <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Start', 'must-hotel-booking'); ?></button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canCreateIssue && $statusKey !== HousekeepingRepository::ISSUE_STATUS_RESOLVED) : ?>
                                        <form method="post" action="<?php echo \esc_url($maintenanceUrl); ?>" class="must-portal-inline-actions">
                                            <?php \wp_nonce_field('must_portal_housekeeping_issue_status_' . $issueId, 'must_portal_housekeeping_nonce'); ?>
                                            <input type="hidden" name="must_portal_action" value="housekeeping_update_issue_status" />
                                            <input type="hidden" name="issue_id" value="<?php echo \esc_attr((string) $issueId); ?>" />
                                            <input type="hidden" name="issue_status" value="<?php echo \esc_attr(HousekeepingRepository::ISSUE_STATUS_RESOLVED); ?>" />
                                            <input type="hidden" name="portal_housekeeping_tab" value="maintenance" />
                                            <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Resolve', 'must-hotel-booking'); ?></button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canCreateIssue && $statusKey === HousekeepingRepository::ISSUE_STATUS_RESOLVED) : ?>
                                        <form method="post" action="<?php echo \esc_url($maintenanceUrl); ?>" class="must-portal-inline-actions">
                                            <?php \wp_nonce_field('must_portal_housekeeping_issue_status_' . $issueId, 'must_portal_housekeeping_nonce'); ?>
                                            <input type="hidden" name="must_portal_action" value="housekeeping_update_issue_status" />
                                            <input type="hidden" name="issue_id" value="<?php echo \esc_attr((string) $issueId); ?>" />
                                            <input type="hidden" name="issue_status" value="<?php echo \esc_attr(HousekeepingRepository::ISSUE_STATUS_OPEN); ?>" />
                                            <input type="hidden" name="portal_housekeeping_tab" value="maintenance" />
                                            <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Reopen', 'must-hotel-booking'); ?></button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if (!$canCreateIssue) : ?>
                                        <span class="must-portal-muted"><?php echo \esc_html__('View only', 'must-hotel-booking'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
