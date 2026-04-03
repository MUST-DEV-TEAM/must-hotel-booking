<?php

use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$handoffSummaryCards = isset($moduleData['handoff_summary_cards']) && \is_array($moduleData['handoff_summary_cards']) ? $moduleData['handoff_summary_cards'] : [];
$handoffRows = isset($moduleData['handoff_rows']) && \is_array($moduleData['handoff_rows']) ? $moduleData['handoff_rows'] : [];
$issueRows = isset($moduleData['issue_rows']) && \is_array($moduleData['issue_rows']) ? $moduleData['issue_rows'] : [];
$handoffForm = isset($moduleData['handoff_form']) && \is_array($moduleData['handoff_form']) ? $moduleData['handoff_form'] : [];
$handoffShiftLabel = isset($handoffForm['handoff_shift_label']) ? (string) $handoffForm['handoff_shift_label'] : '';
$handoffNotes = isset($handoffForm['handoff_notes']) ? (string) $handoffForm['handoff_notes'] : '';
$handoffUrl = PortalRouter::getModuleUrl('housekeeping', ['tab' => 'handoff']);

if (!empty($handoffSummaryCards)) {
    PortalRenderer::renderSummaryCards($handoffSummaryCards);
}
?>
<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Shift Handoff', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html__('Capture the current room-board state and unresolved issues so the next shift or supervising role can pick up operations without missing context.', 'must-hotel-booking'); ?></p>
        </div>
    </div>

    <?php if ($canCreateHandoff) : ?>
        <form method="post" action="<?php echo \esc_url($handoffUrl); ?>" class="must-portal-form">
            <?php \wp_nonce_field('must_portal_housekeeping_create_handoff', 'must_portal_housekeeping_nonce'); ?>
            <input type="hidden" name="must_portal_action" value="housekeeping_create_handoff" />
            <input type="hidden" name="portal_housekeeping_tab" value="handoff" />
            <div class="must-portal-form-grid">
                <label>
                    <span><?php echo \esc_html__('Shift Label', 'must-hotel-booking'); ?></span>
                    <input type="text" name="handoff_shift_label" value="<?php echo \esc_attr($handoffShiftLabel); ?>" placeholder="<?php echo \esc_attr__('Example: Morning to Evening', 'must-hotel-booking'); ?>" />
                </label>
            </div>
            <label>
                <span><?php echo \esc_html__('Handoff Notes', 'must-hotel-booking'); ?></span>
                <textarea name="handoff_notes" rows="3" placeholder="<?php echo \esc_attr__('Write the practical points the next shift should know.', 'must-hotel-booking'); ?>"><?php echo \esc_textarea($handoffNotes); ?></textarea>
            </label>
            <div class="must-portal-header-actions">
                <button type="submit" class="must-portal-button must-portal-button-primary"><?php echo \esc_html__('Capture Handoff', 'must-hotel-booking'); ?></button>
            </div>
        </form>
    <?php endif; ?>

    <div class="must-portal-grid must-portal-grid--2">
        <section class="must-portal-panel">
            <div class="must-portal-panel-header">
                <div>
                    <h3><?php echo \esc_html__('Unresolved Issues', 'must-hotel-booking'); ?></h3>
                    <p><?php echo \esc_html__('Keep the next shift aligned on open room-linked issues that still need attention.', 'must-hotel-booking'); ?></p>
                </div>
            </div>
            <?php if (empty($issueRows)) : ?>
                <?php PortalRenderer::renderEmptyState(\__('No unresolved maintenance issues are waiting for handoff.', 'must-hotel-booking')); ?>
            <?php else : ?>
                <div class="must-portal-table-wrap">
                    <table class="must-portal-table">
                        <thead>
                            <tr>
                                <th><?php echo \esc_html__('Room / Unit', 'must-hotel-booking'); ?></th>
                                <th><?php echo \esc_html__('Issue', 'must-hotel-booking'); ?></th>
                                <th><?php echo \esc_html__('Status', 'must-hotel-booking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($issueRows as $row) : ?>
                                <?php if (!\is_array($row)) { continue; } ?>
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
                                    <td>
                                        <?php
                                        $handoffBadgeKey = (string) ($row['status_key'] ?? '') === 'in_progress'
                                            ? 'pending'
                                            : ((string) ($row['status_key'] ?? '') === 'resolved' ? 'success' : 'warning');
                                        PortalRenderer::renderBadge($handoffBadgeKey, (string) ($row['status_label'] ?? ''));
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="must-portal-panel">
            <div class="must-portal-panel-header">
                <div>
                    <h3><?php echo \esc_html__('Recent Handoffs', 'must-hotel-booking'); ?></h3>
                    <p><?php echo \esc_html__('Review the latest shift snapshots without opening the full audit log.', 'must-hotel-booking'); ?></p>
                </div>
            </div>
            <?php if (empty($handoffRows)) : ?>
                <?php PortalRenderer::renderEmptyState(\__('No shift handoffs have been captured yet.', 'must-hotel-booking')); ?>
            <?php else : ?>
                <div class="must-portal-table-wrap">
                    <table class="must-portal-table">
                        <thead>
                            <tr>
                                <th><?php echo \esc_html__('Shift', 'must-hotel-booking'); ?></th>
                                <th><?php echo \esc_html__('Snapshot', 'must-hotel-booking'); ?></th>
                                <th><?php echo \esc_html__('Captured', 'must-hotel-booking'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($handoffRows as $row) : ?>
                                <?php if (!\is_array($row)) { continue; } ?>
                                <tr>
                                    <td>
                                        <strong><?php echo \esc_html((string) ($row['shift_label'] ?? '')); ?></strong>
                                        <?php if (!empty($row['notes'])) : ?>
                                            <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['notes']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="must-portal-muted">
                                            <?php
                                            echo \esc_html(\sprintf(
                                                __('Dirty %1$d | Clean %2$d | Inspected %3$d | Assigned %4$d | Open Issues %5$d', 'must-hotel-booking'),
                                                (int) ($row['dirty_count'] ?? 0),
                                                (int) ($row['clean_count'] ?? 0),
                                                (int) ($row['inspected_count'] ?? 0),
                                                (int) ($row['assigned_count'] ?? 0),
                                                (int) ($row['open_issue_count'] ?? 0)
                                            ));
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo \esc_html((string) ($row['created_at'] ?? '')); ?></strong>
                                        <?php if (!empty($row['created_by_label'])) : ?>
                                            <br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['created_by_label']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
