<?php

use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

$roomsData = isset($moduleData['rooms_data']) && \is_array($moduleData['rooms_data']) ? $moduleData['rooms_data'] : [];
$unitRows = isset($roomsData['unit_rows']) && \is_array($roomsData['unit_rows']) ? $roomsData['unit_rows'] : [];
$typeOptions = isset($roomsData['type_options']) && \is_array($roomsData['type_options']) ? $roomsData['type_options'] : [];
$unitStatusOptions = isset($roomsData['unit_status_options']) && \is_array($roomsData['unit_status_options']) ? $roomsData['unit_status_options'] : [];
$currentSearch = isset($_GET['search']) && !\is_array($_GET['search']) ? \sanitize_text_field((string) \wp_unslash($_GET['search'])) : '';
$currentRoomTypeId = isset($_GET['room_type_id']) ? \absint(\wp_unslash($_GET['room_type_id'])) : 0;
$currentUnitStatus = isset($_GET['unit_status']) && !\is_array($_GET['unit_status']) ? \sanitize_key((string) \wp_unslash($_GET['unit_status'])) : '';
?>
<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Inventory Rooms', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html__('Review live inventory units, their operational state, and the next workspace to open without using the old split modules.', 'must-hotel-booking'); ?></p>
        </div>
    </div>
    <form class="must-portal-filter-bar" method="get" action="<?php echo \esc_url($moduleUrl); ?>">
        <input type="hidden" name="tab" value="rooms" />
        <input type="search" name="search" value="<?php echo \esc_attr($currentSearch); ?>" placeholder="<?php echo \esc_attr__('Room number, title, location', 'must-hotel-booking'); ?>" />
        <select name="room_type_id">
            <option value=""><?php echo \esc_html__('All room types', 'must-hotel-booking'); ?></option>
            <?php foreach ($typeOptions as $typeOption) : ?>
                <?php if (!\is_array($typeOption)) { continue; } ?>
                <option value="<?php echo \esc_attr((string) ($typeOption['id'] ?? 0)); ?>"<?php echo \selected($currentRoomTypeId, (int) ($typeOption['id'] ?? 0), false); ?>>
                    <?php echo \esc_html((string) ($typeOption['label'] ?? '')); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="unit_status">
            <option value=""><?php echo \esc_html__('All inventory states', 'must-hotel-booking'); ?></option>
            <?php foreach ($unitStatusOptions as $statusKey => $statusLabel) : ?>
                <option value="<?php echo \esc_attr((string) $statusKey); ?>"<?php echo \selected($currentUnitStatus, (string) $statusKey, false); ?>>
                    <?php echo \esc_html((string) $statusLabel); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Apply', 'must-hotel-booking'); ?></button>
    </form>
    <?php if (empty($unitRows)) : ?>
        <?php PortalRenderer::renderEmptyState(\__('No inventory units matched the current filters.', 'must-hotel-booking')); ?>
    <?php else : ?>
        <div class="must-portal-table-wrap">
            <table class="must-portal-table">
                <thead>
                    <tr>
                        <th><?php echo \esc_html__('Room / Unit', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Room Type', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Inventory State', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Housekeeping', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Stay Context', 'must-hotel-booking'); ?></th>
                        <th><?php echo \esc_html__('Workspace', 'must-hotel-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unitRows as $row) : ?>
                        <?php if (!\is_array($row)) { continue; } ?>
                        <?php
                        $roomNumber = (string) ($row['room_number'] ?? '');
                        $roomTitle = (string) ($row['title'] ?? '');
                        $roomLabel = $roomNumber !== '' ? $roomNumber : ($roomTitle !== '' ? $roomTitle : ('#' . (int) ($row['id'] ?? 0)));
                        $meta = [];
                        if (!empty($row['building'])) { $meta[] = (string) $row['building']; }
                        if (!empty($row['section'])) { $meta[] = (string) $row['section']; }
                        if (!empty($row['floor'])) { $meta[] = \sprintf(__('Floor %d', 'must-hotel-booking'), (int) $row['floor']); }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo \esc_html($roomLabel); ?></strong>
                                <?php if ($roomTitle !== '' && $roomTitle !== $roomLabel) : ?><br /><span class="must-portal-muted"><?php echo \esc_html($roomTitle); ?></span><?php endif; ?>
                                <?php if (!empty($meta)) : ?><br /><span class="must-portal-muted"><?php echo \esc_html(\implode(' | ', $meta)); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['type_name'] ?? '')); ?></strong>
                                <?php if (!empty($row['capacity_summary'])) : ?><br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['capacity_summary']); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php PortalRenderer::renderBadge((string) ($row['inventory_status_key'] ?? 'available'), (string) ($row['inventory_status_label'] ?? '')); ?>
                                <?php if (empty($row['is_active'])) : ?><br /><span class="must-portal-muted"><?php echo \esc_html__('Inactive inventory unit.', 'must-hotel-booking'); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php PortalRenderer::renderBadge((string) ($row['housekeeping_status_key'] ?? 'info'), (string) ($row['housekeeping_status_label'] ?? '')); ?>
                                <?php if (!empty($row['housekeeping_status_note'])) : ?><br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['housekeeping_status_note']); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['reservation_context'] ?? '')); ?></strong>
                                <?php if (!empty($row['reservation_context_meta'])) : ?><br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['reservation_context_meta']); ?></span><?php endif; ?>
                                <?php if (!empty($row['warnings']) && \is_array($row['warnings'])) : ?><br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['warnings'][0]); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <div class="must-portal-inline-actions">
                                    <a class="must-portal-inline-link" href="<?php echo \esc_url($buildWorkspaceUrl('statuses', ['search' => $roomNumber !== '' ? $roomNumber : $roomTitle, 'room_type_id' => isset($row['room_type_id']) ? (int) $row['room_type_id'] : 0])); ?>"><?php echo \esc_html__('Statuses', 'must-hotel-booking'); ?></a>
                                    <?php if ($canViewAvailability) : ?>
                                        <a class="must-portal-inline-link" href="<?php echo \esc_url($buildWorkspaceUrl('blocks', ['room_id' => isset($row['room_type_id']) ? (int) $row['room_type_id'] : 0])); ?>"><?php echo \esc_html__('Blocks', 'must-hotel-booking'); ?></a>
                                        <a class="must-portal-inline-link" href="<?php echo \esc_url($buildWorkspaceUrl('rules', ['room_id' => isset($row['room_type_id']) ? (int) $row['room_type_id'] : 0])); ?>"><?php echo \esc_html__('Rules', 'must-hotel-booking'); ?></a>
                                    <?php endif; ?>
                                    <?php if ($canOpenHousekeeping) : ?>
                                        <a class="must-portal-inline-link" href="<?php echo \esc_url(PortalRouter::getModuleUrl('housekeeping', ['tab' => 'room-board'])); ?>"><?php echo \esc_html__('Housekeeping', 'must-hotel-booking'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php PortalRenderer::renderPagination((array) ($roomsData['unit_pagination'] ?? []), 'rooms_availability'); ?>
    <?php endif; ?>
</section>
