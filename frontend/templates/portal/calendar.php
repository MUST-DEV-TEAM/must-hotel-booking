<?php

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

PortalRenderer::renderSummaryCards((array) ($moduleData['summary'] ?? []));

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$dates = isset($moduleData['range']['dates']) && \is_array($moduleData['range']['dates']) ? $moduleData['range']['dates'] : [];
$rows = isset($moduleData['rows']) && \is_array($moduleData['rows']) ? $moduleData['rows'] : [];
$legend = isset($moduleData['legend']) && \is_array($moduleData['legend']) ? $moduleData['legend'] : [];
$selected = isset($moduleData['selected']) && \is_array($moduleData['selected']) ? $moduleData['selected'] : [];
$currentArgs = isset($moduleData['current_args']) && \is_array($moduleData['current_args']) ? $moduleData['current_args'] : [];
$moduleUrl = PortalRouter::getModuleUrl('calendar');
$selectedRoom = isset($selected['room']) && \is_array($selected['room']) ? $selected['room'] : null;
$selectedDate = isset($selected['date']) ? (string) $selected['date'] : '';
$selectedSummary = isset($selected['summary']) && \is_array($selected['summary']) ? $selected['summary'] : [];
$canOpenPayments = StaffAccess::userCanAccessPortalModule('payments');
$persistentFilterArgs = $currentArgs;

foreach (['start_date', 'weeks', 'focus_room_id', 'focus_date', 'reservation_id'] as $skipKey) {
    unset($persistentFilterArgs[$skipKey]);
}

$clearSelectionUrl = PortalRouter::getModuleUrl(
    'calendar',
    \array_diff_key($currentArgs, \array_flip(['focus_room_id', 'focus_date', 'reservation_id']))
);

$renderHiddenArgs = static function (array $args): void {
    foreach ($args as $key => $value) {
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (!\is_scalar($item) || (string) $item === '') {
                    continue;
                }

                echo '<input type="hidden" name="' . \esc_attr((string) $key) . '[]" value="' . \esc_attr((string) $item) . '" />';
            }

            continue;
        }

        if (!\is_scalar($value) || (string) $value === '') {
            continue;
        }

        echo '<input type="hidden" name="' . \esc_attr((string) $key) . '" value="' . \esc_attr((string) $value) . '" />';
    }
};

$renderReservationSection = static function (string $title, array $items, bool $canOpenPayments): void {
    if (empty($items)) {
        return;
    }

    echo '<h3>' . \esc_html($title) . '</h3>';

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        $status = \sanitize_key((string) ($item['status'] ?? ''));
        $portalUrl = isset($item['portal_url']) ? (string) $item['portal_url'] : '';
        $paymentUrl = isset($item['payment_url']) ? (string) $item['payment_url'] : '';
        $reference = (string) (($item['reference'] ?? '') !== '' ? $item['reference'] : __('Reservation', 'must-hotel-booking'));
        $guestLabel = (string) ($item['guest'] ?? '');
        $metaBits = [];

        if (!empty($item['status_label'])) {
            $metaBits[] = (string) $item['status_label'];
        }

        if (!empty($item['date_label'])) {
            $metaBits[] = (string) $item['date_label'];
        }

        if (!empty($item['booking_source_label'])) {
            $metaBits[] = (string) $item['booking_source_label'];
        }

        echo '<div class="must-portal-feed-item">';
        echo '<div><strong>' . \esc_html($reference) . '</strong>';

        if ($guestLabel !== '') {
            echo '<span>' . \esc_html($guestLabel) . '</span>';
        }

        if (!empty($metaBits)) {
            echo '<small class="must-portal-muted">' . \esc_html(\implode(' | ', $metaBits)) . '</small>';
        }

        echo '</div><div class="must-portal-inline-actions">';

        if ($portalUrl !== '') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url($portalUrl) . '">' . \esc_html($status === 'blocked' ? __('Open block', 'must-hotel-booking') : __('Open reservation', 'must-hotel-booking')) . '</a>';
        }

        if ($canOpenPayments && $paymentUrl !== '' && $status !== 'blocked') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url($paymentUrl) . '">' . \esc_html__('Payments', 'must-hotel-booking') . '</a>';
        }

        echo '</div></div>';
    }
};

$renderRuleSection = static function (array $items): void {
    if (empty($items)) {
        return;
    }

    echo '<h3>' . \esc_html__('Rules', 'must-hotel-booking') . '</h3>';

    foreach ($items as $item) {
        if (!\is_array($item)) {
            continue;
        }

        $portalUrl = isset($item['portal_url']) ? (string) $item['portal_url'] : '';
        echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong>';

        if (!empty($item['range'])) {
            echo '<span>' . \esc_html((string) $item['range']) . '</span>';
        }

        if (!empty($item['reason'])) {
            echo '<small class="must-portal-muted">' . \esc_html((string) $item['reason']) . '</small>';
        }

        echo '</div><div class="must-portal-inline-actions">';

        if ($portalUrl !== '') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url($portalUrl) . '">' . \esc_html__('Open rules', 'must-hotel-booking') . '</a>';
        }

        echo '</div></div>';
    }
};
?>
<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Calendar filters', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html((string) ($moduleData['range']['label'] ?? '')); ?></p>
        </div>
        <div class="must-portal-inline-actions">
            <?php if (!empty($moduleData['range']['previous_url'])) : ?>
                <a class="must-portal-inline-link" href="<?php echo \esc_url((string) $moduleData['range']['previous_url']); ?>"><?php echo \esc_html__('Previous range', 'must-hotel-booking'); ?></a>
            <?php endif; ?>
            <?php if (!empty($selectedRoom) && $selectedDate !== '') : ?>
                <a class="must-portal-inline-link" href="<?php echo \esc_url($clearSelectionUrl); ?>"><?php echo \esc_html__('Clear selection', 'must-hotel-booking'); ?></a>
            <?php endif; ?>
            <?php if (!empty($moduleData['range']['next_url'])) : ?>
                <a class="must-portal-inline-link" href="<?php echo \esc_url((string) $moduleData['range']['next_url']); ?>"><?php echo \esc_html__('Next range', 'must-hotel-booking'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <form class="must-portal-filter-bar" method="get" action="<?php echo \esc_url($moduleUrl); ?>">
        <?php $renderHiddenArgs($persistentFilterArgs); ?>
        <input type="date" name="start_date" value="<?php echo \esc_attr((string) ($filters['start_date'] ?? '')); ?>" />
        <select name="weeks">
            <?php foreach ((array) ($filters['week_options'] ?? []) as $value => $label) : ?>
                <option value="<?php echo \esc_attr((string) $value); ?>"<?php echo \selected((string) ($filters['weeks'] ?? ''), (string) $value, false); ?>>
                    <?php echo \esc_html((string) $label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Update', 'must-hotel-booking'); ?></button>
    </form>
</section>

<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Calendar legend', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html__('Cells now route into the most useful existing portal workflow. If a day has multiple items or no direct destination, the cell opens the selected-day detail on this page.', 'must-hotel-booking'); ?></p>
        </div>
    </div>
    <div class="must-portal-grid must-portal-grid--2">
        <?php foreach ($legend as $item) : ?>
            <?php if (!\is_array($item)) { continue; } ?>
            <div class="must-portal-feed-item">
                <div>
                    <strong><?php echo \esc_html((string) ($item['label'] ?? '')); ?></strong>
                    <span><?php echo \esc_html((string) ($item['description'] ?? '')); ?></span>
                </div>
                <?php PortalRenderer::renderBadge((string) ($item['state'] ?? 'info'), (string) ($item['label'] ?? '')); ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="must-portal-panel">
    <div class="must-portal-panel-header">
        <div>
            <h2><?php echo \esc_html__('Availability board', 'must-hotel-booking'); ?></h2>
            <p><?php echo \esc_html__('Click a cell to inspect the day or jump directly into the linked reservation, block, rule, or room-status context.', 'must-hotel-booking'); ?></p>
        </div>
    </div>

    <?php if (empty($rows) || empty($dates)) : ?>
        <?php PortalRenderer::renderEmptyState(\__('No calendar data is available for the selected filters.', 'must-hotel-booking')); ?>
    <?php else : ?>
        <div class="must-portal-table-wrap">
            <table class="must-portal-table must-portal-calendar-table">
                <thead>
                    <tr>
                        <th><?php echo \esc_html__('Accommodation', 'must-hotel-booking'); ?></th>
                        <?php foreach ($dates as $date) : ?>
                            <th><?php echo \esc_html(\wp_date('M j', \strtotime((string) $date))); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <?php if (!\is_array($row)) { continue; } ?>
                        <tr>
                            <td>
                                <strong><?php echo \esc_html((string) ($row['name'] ?? '')); ?></strong>
                                <br />
                                <span class="must-portal-muted"><?php echo \esc_html((string) ($row['category_label'] ?? '')); ?></span>
                            </td>
                            <?php foreach ((array) ($row['cells'] ?? []) as $cell) : ?>
                                <?php
                                if (!\is_array($cell)) {
                                    continue;
                                }

                                $cellClasses = [
                                    'must-portal-calendar-cell',
                                    'is-' . \sanitize_html_class((string) ($cell['actual_state'] ?? 'available')),
                                ];

                                if (!empty($cell['selected'])) {
                                    $cellClasses[] = 'is-selected';
                                }

                                $cellUrl = isset($cell['action_url']) ? (string) $cell['action_url'] : '';
                                $actionLabel = isset($cell['action_label']) ? (string) $cell['action_label'] : '';
                                ?>
                                <td class="<?php echo \esc_attr(\implode(' ', $cellClasses)); ?>">
                                    <?php if ($cellUrl !== '') : ?>
                                        <a class="must-portal-calendar-cell-link" href="<?php echo \esc_url($cellUrl); ?>" title="<?php echo \esc_attr($actionLabel); ?>">
                                            <span class="must-portal-calendar-cell-label"><?php echo \esc_html((string) ($cell['headline'] ?? '')); ?></span>
                                            <?php foreach ((array) ($cell['indicators'] ?? []) as $indicator) : ?>
                                                <span class="must-portal-calendar-cell-meta"><?php echo \esc_html((string) $indicator); ?></span>
                                            <?php endforeach; ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="must-portal-calendar-cell-content">
                                            <span class="must-portal-calendar-cell-label"><?php echo \esc_html((string) ($cell['headline'] ?? '')); ?></span>
                                            <?php foreach ((array) ($cell['indicators'] ?? []) as $indicator) : ?>
                                                <span class="must-portal-calendar-cell-meta"><?php echo \esc_html((string) $indicator); ?></span>
                                            <?php endforeach; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="must-portal-grid must-portal-grid--2">
    <article class="must-portal-panel">
        <div class="must-portal-panel-header">
            <div>
                <h2><?php echo \esc_html__('Selected day', 'must-hotel-booking'); ?></h2>
                <p><?php echo \esc_html__('Use this detail panel for single-day inspection and the first manual block action.', 'must-hotel-booking'); ?></p>
            </div>
        </div>

        <?php if (!\is_array($selectedRoom) || $selectedDate === '') : ?>
            <?php PortalRenderer::renderEmptyState(\__('Select a calendar cell to inspect its reservation, rule, and block context.', 'must-hotel-booking')); ?>
        <?php else : ?>
            <?php
            $stateKey = \sanitize_key((string) ($selectedSummary['actual_state'] ?? 'available'));
            $stateLabel = \ucwords(\str_replace('_', ' ', $stateKey));
            $blockForm = isset($selected['block_form']) && \is_array($selected['block_form']) ? $selected['block_form'] : [];
            ?>
            <div class="must-portal-feed-item">
                <div>
                    <strong><?php echo \esc_html((string) ($selectedRoom['name'] ?? '')); ?></strong>
                    <span><?php echo \esc_html((string) ($selected['label'] ?? $selectedDate)); ?></span>
                    <?php if (!empty($selected['selection']['range_label'])) : ?>
                        <small class="must-portal-muted"><?php echo \esc_html((string) $selected['selection']['range_label']); ?></small>
                    <?php endif; ?>
                </div>
                <?php PortalRenderer::renderBadge($stateKey, $stateLabel); ?>
            </div>

            <div class="must-portal-definition-list">
                <?php PortalRenderer::renderDefinitionRow(\__('Headline', 'must-hotel-booking'), (string) ($selectedSummary['headline'] ?? __('No summary', 'must-hotel-booking'))); ?>
                <?php PortalRenderer::renderDefinitionRow(\__('Available units', 'must-hotel-booking'), (string) ((int) ($selectedSummary['available_units'] ?? 0))); ?>
                <?php PortalRenderer::renderDefinitionRow(\__('Arrivals', 'must-hotel-booking'), (string) ((int) ($selectedSummary['arrivals_count'] ?? 0))); ?>
                <?php PortalRenderer::renderDefinitionRow(\__('Departures', 'must-hotel-booking'), (string) ((int) ($selectedSummary['departures_count'] ?? 0))); ?>
            </div>

            <?php if (!empty($selectedSummary['indicators'])) : ?>
                <div class="must-portal-stack">
                    <?php foreach ((array) $selectedSummary['indicators'] as $indicator) : ?>
                        <span class="must-portal-muted"><?php echo \esc_html((string) $indicator); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($selected['portal_actions'])) : ?>
                <div class="must-portal-inline-actions">
                    <?php foreach ((array) $selected['portal_actions'] as $action) : ?>
                        <?php if (!\is_array($action) || empty($action['url'])) { continue; } ?>
                        <a class="must-portal-inline-link" href="<?php echo \esc_url((string) $action['url']); ?>"><?php echo \esc_html((string) ($action['label'] ?? __('Open', 'must-hotel-booking'))); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($selected['can_create_block'])) : ?>
                <form class="must-portal-form-grid" method="post" action="<?php echo \esc_url((string) ($selected['block_form_action'] ?? $moduleUrl)); ?>">
                    <?php \wp_nonce_field('must_portal_calendar_block_dates', 'must_portal_calendar_nonce'); ?>
                    <input type="hidden" name="must_portal_action" value="calendar_create_block" />
                    <input type="hidden" name="room_id" value="<?php echo \esc_attr((string) ($blockForm['room_id'] ?? 0)); ?>" />
                    <label>
                        <span><?php echo \esc_html__('Block start', 'must-hotel-booking'); ?></span>
                        <input type="date" name="checkin" value="<?php echo \esc_attr((string) ($blockForm['checkin'] ?? $selectedDate)); ?>" required />
                    </label>
                    <label>
                        <span><?php echo \esc_html__('Block end', 'must-hotel-booking'); ?></span>
                        <input type="date" name="checkout" value="<?php echo \esc_attr((string) ($blockForm['checkout'] ?? $selectedDate)); ?>" required />
                    </label>
                    <div class="must-portal-form-full">
                        <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Create manual block', 'must-hotel-booking'); ?></button>
                    </div>
                </form>
            <?php elseif (!empty($selected['actions']['can_create'])) : ?>
                <p class="must-portal-muted"><?php echo \esc_html__('This role can inspect the day but cannot create manual blocks from the calendar.', 'must-hotel-booking'); ?></p>
            <?php else : ?>
                <p class="must-portal-muted"><?php echo \esc_html__('No sellable inventory is available for a new manual block on the selected day.', 'must-hotel-booking'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </article>

    <article class="must-portal-panel">
        <div class="must-portal-panel-header">
            <div>
                <h2><?php echo \esc_html__('Operational context', 'must-hotel-booking'); ?></h2>
                <p><?php echo \esc_html__('Reservations, rules, and temporary locks attached to the selected day surface here without opening wp-admin.', 'must-hotel-booking'); ?></p>
            </div>
        </div>

        <?php
        $hasContext = !empty($selected['stays']) || !empty($selected['arrivals']) || !empty($selected['departures']) || !empty($selected['rules']) || !empty($selected['locks']);

        if (!$hasContext) {
            PortalRenderer::renderEmptyState(\__('No reservation, rule, or temporary hold context is attached to the selected day.', 'must-hotel-booking'));
        } else {
            $renderReservationSection(\__('Stays', 'must-hotel-booking'), (array) ($selected['stays'] ?? []), $canOpenPayments);
            $renderReservationSection(\__('Arrivals', 'must-hotel-booking'), (array) ($selected['arrivals'] ?? []), $canOpenPayments);
            $renderReservationSection(\__('Departures', 'must-hotel-booking'), (array) ($selected['departures'] ?? []), $canOpenPayments);
            $renderRuleSection((array) ($selected['rules'] ?? []));

            if (!empty($selected['locks']) && \is_array($selected['locks'])) {
                echo '<h3>' . \esc_html__('Temporary holds', 'must-hotel-booking') . '</h3>';

                foreach ((array) $selected['locks'] as $lock) {
                    if (!\is_array($lock)) {
                        continue;
                    }

                    echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($lock['room_number'] ?? __('Hold', 'must-hotel-booking'))) . '</strong>';

                    if (!empty($lock['expires_at'])) {
                        echo '<span>' . \esc_html(\sprintf(__('Expires %s', 'must-hotel-booking'), (string) $lock['expires_at'])) . '</span>';
                    }

                    echo '</div>';
                    PortalRenderer::renderBadge('hold', __('Hold', 'must-hotel-booking'));
                    echo '</div>';
                }
            }
        }
        ?>
    </article>
</section>
