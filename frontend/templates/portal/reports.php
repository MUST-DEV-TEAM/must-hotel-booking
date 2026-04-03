<?php

use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$activeTab = isset($moduleData['active_tab']) ? (string) $moduleData['active_tab'] : 'daily-operations';
$tabs = isset($moduleData['tabs']) && \is_array($moduleData['tabs']) ? $moduleData['tabs'] : [];
$filterUrl = isset($moduleData['filter_url']) ? (string) $moduleData['filter_url'] : PortalRouter::getModuleUrl('reports');
$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$filterOptions = isset($moduleData['filter_options']) && \is_array($moduleData['filter_options']) ? $moduleData['filter_options'] : [];
$opsCards = isset($moduleData['ops_cards']) && \is_array($moduleData['ops_cards']) ? $moduleData['ops_cards'] : [];
$financeCards = isset($moduleData['finance_cards']) && \is_array($moduleData['finance_cards']) ? $moduleData['finance_cards'] : [];
$occupancyCards = isset($moduleData['occupancy_cards']) && \is_array($moduleData['occupancy_cards']) ? $moduleData['occupancy_cards'] : [];
$auditRows = isset($moduleData['audit_rows']) && \is_array($moduleData['audit_rows']) ? $moduleData['audit_rows'] : [];
$breakdowns = isset($moduleData['breakdowns']) && \is_array($moduleData['breakdowns']) ? $moduleData['breakdowns'] : [];
$trend = isset($moduleData['trend']) && \is_array($moduleData['trend']) ? $moduleData['trend'] : [];
$stay = isset($moduleData['stay']) && \is_array($moduleData['stay']) ? $moduleData['stay'] : [];
$topAccommodations = isset($moduleData['top_accommodations']) && \is_array($moduleData['top_accommodations']) ? $moduleData['top_accommodations'] : [];
$coupons = isset($moduleData['coupons']) && \is_array($moduleData['coupons']) ? $moduleData['coupons'] : [];
$issues = isset($moduleData['issues']) && \is_array($moduleData['issues']) ? $moduleData['issues'] : [];
$notes = isset($moduleData['notes']) && \is_array($moduleData['notes']) ? $moduleData['notes'] : [];
$canExport = !empty($moduleData['can_export']);
$exportUrl = isset($moduleData['export_url']) ? (string) $moduleData['export_url'] : '';

$buildReportsUrl = static function (string $tab, array $overrides = []) use ($filters, $filterUrl): string {
    $allowedKeys = ['preset', 'date_from', 'date_to'];

    if ($tab === 'daily-operations' || $tab === 'occupancy') {
        $allowedKeys[] = 'room_id';
        $allowedKeys[] = 'reservation_status';
    } elseif ($tab === 'payments-finance') {
        $allowedKeys[] = 'room_id';
        $allowedKeys[] = 'payment_method';
    }

    $baseArgs = ['tab' => $tab];

    foreach ($allowedKeys as $key) {
        if (!isset($filters[$key])) {
            continue;
        }

        $baseArgs[$key] = $filters[$key];
    }

    $args = \array_merge($baseArgs, $overrides);

    foreach ($args as $key => $value) {
        if ($value === '' || $value === 0 || $value === 'all' || $value === null) {
            unset($args[$key]);
        }
    }

    return \add_query_arg($args, $filterUrl);
};

$renderMiniList = static function (array $rows, string $emptyMessage, string $labelKey = 'label', string $valueKey = 'value'): void {
    if (empty($rows)) {
        PortalRenderer::renderEmptyState($emptyMessage);
        return;
    }

    echo '<div class="must-portal-mini-list">';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<div class="must-portal-mini-item"><strong>' . \esc_html((string) ($row[$labelKey] ?? '')) . '</strong><span>' . \esc_html((string) ($row[$valueKey] ?? '')) . '</span></div>';
    }

    echo '</div>';
};

$auditWarningCount = 0;
$auditErrorCount = 0;

foreach ($auditRows as $auditRow) {
    if (!\is_array($auditRow)) {
        continue;
    }

    $severity = \sanitize_key((string) ($auditRow['severity'] ?? 'info'));

    if ($severity === 'warning') {
        $auditWarningCount++;
    } elseif ($severity === 'error') {
        $auditErrorCount++;
    }
}

if ($activeTab === 'daily-operations') {
    PortalRenderer::renderSummaryCards($opsCards);
} elseif ($activeTab === 'payments-finance') {
    PortalRenderer::renderSummaryCards($financeCards);
} elseif ($activeTab === 'occupancy') {
    PortalRenderer::renderSummaryCards($occupancyCards);
} elseif ($activeTab === 'audit-log') {
    PortalRenderer::renderSummaryCards([
        [
            'label' => \__('Activity Rows', 'must-hotel-booking'),
            'value' => \number_format_i18n(\count($auditRows)),
            'meta' => \__('Recent activity records in the selected date range.', 'must-hotel-booking'),
        ],
        [
            'label' => \__('Warnings', 'must-hotel-booking'),
            'value' => \number_format_i18n($auditWarningCount),
            'meta' => \__('Warning-severity activity rows in range.', 'must-hotel-booking'),
        ],
        [
            'label' => \__('Errors', 'must-hotel-booking'),
            'value' => \number_format_i18n($auditErrorCount),
            'meta' => \__('Error-severity activity rows in range.', 'must-hotel-booking'),
        ],
    ]);
}
?>
<div class="must-portal-tabs">
    <nav class="must-portal-tab-nav">
        <?php foreach ($tabs as $tabKey => $tabLabel) : ?>
            <a class="must-portal-tab-link<?php echo $activeTab === $tabKey ? ' is-active' : ''; ?>" href="<?php echo \esc_url($buildReportsUrl((string) $tabKey)); ?>">
                <?php echo \esc_html((string) $tabLabel); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="must-portal-tab-content">
        <section class="must-portal-panel">
            <div class="must-portal-panel-header">
                <div>
                    <h2><?php echo \esc_html__('Report filters', 'must-hotel-booking'); ?></h2>
                    <p><?php echo \esc_html__('The same date range drives the operational, finance, occupancy, and audit views, but each role only sees the tabs they are allowed to use.', 'must-hotel-booking'); ?></p>
                </div>
                <?php if ($canExport && $exportUrl !== '') : ?>
                    <div class="must-portal-inline-actions">
                        <a class="must-portal-secondary-button" href="<?php echo \esc_url($exportUrl); ?>"><?php echo \esc_html__('Export CSV', 'must-hotel-booking'); ?></a>
                    </div>
                <?php endif; ?>
            </div>
            <form class="must-portal-filter-bar" method="get" action="<?php echo \esc_url($filterUrl); ?>">
                <input type="hidden" name="tab" value="<?php echo \esc_attr($activeTab); ?>" />
                <select name="preset">
                    <?php foreach ((array) ($filterOptions['presets'] ?? []) as $presetKey => $presetLabel) : ?>
                        <option value="<?php echo \esc_attr((string) $presetKey); ?>"<?php echo \selected((string) ($filters['preset'] ?? ''), (string) $presetKey, false); ?>>
                            <?php echo \esc_html((string) $presetLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="date_from" value="<?php echo \esc_attr((string) ($filters['date_from'] ?? '')); ?>" />
                <input type="date" name="date_to" value="<?php echo \esc_attr((string) ($filters['date_to'] ?? '')); ?>" />

                <?php if ($activeTab !== 'audit-log') : ?>
                    <select name="room_id">
                        <option value=""><?php echo \esc_html__('All accommodations', 'must-hotel-booking'); ?></option>
                        <?php foreach ((array) ($filterOptions['rooms'] ?? []) as $roomOption) : ?>
                            <?php if (!\is_array($roomOption)) { continue; } ?>
                            <option value="<?php echo \esc_attr((string) ($roomOption['id'] ?? 0)); ?>"<?php echo \selected((int) ($filters['room_id'] ?? 0), (int) ($roomOption['id'] ?? 0), false); ?>>
                                <?php echo \esc_html((string) (($roomOption['label'] ?? '') !== '' ? $roomOption['label'] : ($roomOption['name'] ?? ''))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($activeTab === 'daily-operations' || $activeTab === 'occupancy') : ?>
                    <select name="reservation_status">
                        <option value=""><?php echo \esc_html__('All reservation states', 'must-hotel-booking'); ?></option>
                        <?php foreach ((array) ($filterOptions['reservation_statuses'] ?? []) as $statusKey => $statusLabel) : ?>
                            <option value="<?php echo \esc_attr((string) $statusKey); ?>"<?php echo \selected((string) ($filters['reservation_status'] ?? ''), (string) $statusKey, false); ?>>
                                <?php echo \esc_html((string) $statusLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ($activeTab === 'payments-finance') : ?>
                    <select name="payment_method">
                        <option value=""><?php echo \esc_html__('All payment methods', 'must-hotel-booking'); ?></option>
                        <?php foreach ((array) ($filterOptions['payment_methods'] ?? []) as $methodKey => $methodLabel) : ?>
                            <option value="<?php echo \esc_attr((string) $methodKey); ?>"<?php echo \selected((string) ($filters['payment_method'] ?? ''), (string) $methodKey, false); ?>>
                                <?php echo \esc_html((string) $methodLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="must-portal-secondary-button"><?php echo \esc_html__('Apply', 'must-hotel-booking'); ?></button>
            </form>
        </section>

        <?php if ($activeTab === 'daily-operations') : ?>
            <section class="must-portal-grid must-portal-grid--2">
                <article class="must-portal-panel">
                    <div class="must-portal-panel-header">
                        <div>
                            <h2><?php echo \esc_html__('Reservation status mix', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('Operational reservation counts for the current reporting range.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <?php $renderMiniList((array) ($breakdowns['reservation_status'] ?? []), \__('No reservation status data matched the current filters.', 'must-hotel-booking')); ?>
                </article>
                <article class="must-portal-panel">
                    <div class="must-portal-panel-header">
                        <div>
                            <h2><?php echo \esc_html__('Operational issues', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('Booking and payment integrity warnings that need staff attention.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <?php $renderMiniList($issues, \__('No operational issues were flagged for the current range.', 'must-hotel-booking')); ?>
                </article>
            </section>

            <section class="must-portal-panel">
                <div class="must-portal-panel-header">
                    <div>
                        <h2><?php echo \esc_html__('Reservation trend', 'must-hotel-booking'); ?></h2>
                        <p><?php echo \esc_html(\sprintf(__('Reservation creation trend grouped by %s.', 'must-hotel-booking'), (string) ($trend['granularity_label'] ?? __('period', 'must-hotel-booking')))); ?></p>
                    </div>
                </div>
                <?php if (empty($trend['rows'])) : ?>
                    <?php PortalRenderer::renderEmptyState(\__('No trend data is available for the current range.', 'must-hotel-booking')); ?>
                <?php else : ?>
                    <div class="must-portal-table-wrap">
                        <table class="must-portal-table">
                            <thead>
                                <tr>
                                    <th><?php echo \esc_html__('Period', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Reservations', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Booked Revenue', 'must-hotel-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ((array) $trend['rows'] as $row) : ?>
                                    <?php if (!\is_array($row)) { continue; } ?>
                                    <tr>
                                        <td><strong><?php echo \esc_html((string) ($row['label'] ?? '')); ?></strong></td>
                                        <td><?php echo \esc_html((string) ($row['reservations'] ?? '')); ?></td>
                                        <td><?php echo \esc_html((string) ($row['revenue'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php elseif ($activeTab === 'payments-finance') : ?>
            <section class="must-portal-grid must-portal-grid--2">
                <article class="must-portal-panel">
                    <div class="must-portal-panel-header">
                        <div>
                            <h2><?php echo \esc_html__('Payment status mix', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('Normalized payment-state distribution from the reservation ledger.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <?php $renderMiniList((array) ($breakdowns['payment_status'] ?? []), \__('No payment status breakdown is available for the current filters.', 'must-hotel-booking')); ?>
                </article>
                <article class="must-portal-panel">
                    <div class="must-portal-panel-header">
                        <div>
                            <h2><?php echo \esc_html__('Payment method mix', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('Collections grouped by the current payment method label.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <?php $renderMiniList((array) ($breakdowns['payment_method'] ?? []), \__('No payment method breakdown is available for the current filters.', 'must-hotel-booking')); ?>
                </article>
            </section>

            <section class="must-portal-grid must-portal-grid--2">
                <article class="must-portal-panel">
                    <div class="must-portal-panel-header">
                        <div>
                            <h2><?php echo \esc_html__('Coupon usage', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('Top discount codes tied to reservations in the selected range.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <?php if (empty($coupons)) : ?>
                        <?php PortalRenderer::renderEmptyState(\__('No coupon activity matched the current finance filters.', 'must-hotel-booking')); ?>
                    <?php else : ?>
                        <div class="must-portal-table-wrap">
                            <table class="must-portal-table">
                                <thead>
                                    <tr>
                                        <th><?php echo \esc_html__('Coupon', 'must-hotel-booking'); ?></th>
                                        <th><?php echo \esc_html__('Uses', 'must-hotel-booking'); ?></th>
                                        <th><?php echo \esc_html__('Discount Total', 'must-hotel-booking'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($coupons as $row) : ?>
                                        <?php if (!\is_array($row)) { continue; } ?>
                                        <tr>
                                            <td><strong><?php echo \esc_html((string) ($row['coupon_code'] ?? '')); ?></strong></td>
                                            <td><?php echo \esc_html((string) ($row['uses'] ?? '')); ?></td>
                                            <td><?php echo \esc_html((string) ($row['discount_total'] ?? '')); ?></td>
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
                            <h2><?php echo \esc_html__('Finance issues', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('Warnings related to payment state, totals, and orphan ledger links.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <?php $renderMiniList($issues, \__('No finance issues were flagged for the current range.', 'must-hotel-booking')); ?>
                </article>
            </section>

        <?php elseif ($activeTab === 'occupancy') : ?>
            <section class="must-portal-grid must-portal-grid--2">
                <article class="must-portal-panel">
                    <div class="must-portal-panel-header">
                        <div>
                            <h2><?php echo \esc_html__('Stay flow', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('Arrivals, departures, and denominator context for the selected date range.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <div class="must-portal-definition-list">
                        <?php PortalRenderer::renderDefinitionRow(\__('Arrivals', 'must-hotel-booking'), \number_format_i18n((int) ($stay['arrivals_count'] ?? 0))); ?>
                        <?php PortalRenderer::renderDefinitionRow(\__('Departures', 'must-hotel-booking'), \number_format_i18n((int) ($stay['departures_count'] ?? 0))); ?>
                        <?php PortalRenderer::renderDefinitionRow(\__('Occupied nights', 'must-hotel-booking'), \number_format_i18n((int) ($stay['occupied_nights'] ?? 0))); ?>
                        <?php PortalRenderer::renderDefinitionRow(\__('Available nights', 'must-hotel-booking'), \number_format_i18n((int) ($stay['available_nights'] ?? 0))); ?>
                    </div>
                </article>
                <article class="must-portal-panel">
                    <div class="must-portal-panel-header">
                        <div>
                            <h2><?php echo \esc_html__('Occupancy notes', 'must-hotel-booking'); ?></h2>
                            <p><?php echo \esc_html__('This first occupancy slice reuses the current room inventory model and stay-overlap math.', 'must-hotel-booking'); ?></p>
                        </div>
                    </div>
                    <?php if (empty($notes)) : ?>
                        <?php PortalRenderer::renderEmptyState(\__('No occupancy notes are available.', 'must-hotel-booking')); ?>
                    <?php else : ?>
                        <div class="must-portal-mini-list">
                            <?php foreach ($notes as $note) : ?>
                                <div class="must-portal-mini-item">
                                    <strong><?php echo \esc_html__('Note', 'must-hotel-booking'); ?></strong>
                                    <span><?php echo \esc_html((string) $note); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            </section>

            <section class="must-portal-panel">
                <div class="must-portal-panel-header">
                    <div>
                        <h2><?php echo \esc_html__('Top accommodations', 'must-hotel-booking'); ?></h2>
                        <p><?php echo \esc_html__('Current best-performing accommodation types by reservations, nights, and booked revenue.', 'must-hotel-booking'); ?></p>
                    </div>
                </div>
                <?php if (empty($topAccommodations)) : ?>
                    <?php PortalRenderer::renderEmptyState(\__('No accommodation performance rows matched the current occupancy filters.', 'must-hotel-booking')); ?>
                <?php else : ?>
                    <div class="must-portal-table-wrap">
                        <table class="must-portal-table">
                            <thead>
                                <tr>
                                    <th><?php echo \esc_html__('Accommodation', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Reservations', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Nights', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Booked Revenue', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Avg Booking', 'must-hotel-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topAccommodations as $row) : ?>
                                    <?php if (!\is_array($row)) { continue; } ?>
                                    <tr>
                                        <td><strong><?php echo \esc_html((string) ($row['room_name'] ?? '')); ?></strong></td>
                                        <td><?php echo \esc_html((string) ($row['reservations'] ?? '')); ?></td>
                                        <td><?php echo \esc_html((string) ($row['nights'] ?? '')); ?></td>
                                        <td><?php echo \esc_html((string) ($row['revenue'] ?? '')); ?></td>
                                        <td><?php echo \esc_html((string) ($row['average_booking_value'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

        <?php else : ?>
            <section class="must-portal-panel">
                <div class="must-portal-panel-header">
                    <div>
                        <h2><?php echo \esc_html__('Audit activity', 'must-hotel-booking'); ?></h2>
                        <p><?php echo \esc_html__('A first useful cross-module audit feed using the existing activity log. This view stays simple on purpose: timestamp, severity, actor, message, and the first relevant portal link.', 'must-hotel-booking'); ?></p>
                    </div>
                </div>
                <?php if (empty($auditRows)) : ?>
                    <?php PortalRenderer::renderEmptyState(\__('No audit activity matched the selected date range.', 'must-hotel-booking')); ?>
                <?php else : ?>
                    <div class="must-portal-table-wrap">
                        <table class="must-portal-table">
                            <thead>
                                <tr>
                                    <th><?php echo \esc_html__('When', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Severity', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Event', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Entity', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Actor', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Detail', 'must-hotel-booking'); ?></th>
                                    <th><?php echo \esc_html__('Open', 'must-hotel-booking'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditRows as $row) : ?>
                                    <?php if (!\is_array($row)) { continue; } ?>
                                    <tr>
                                        <td><strong><?php echo \esc_html((string) ($row['created_at'] ?? '')); ?></strong></td>
                                        <td><?php PortalRenderer::renderBadge((string) ($row['severity'] ?? 'info'), (string) ($row['severity'] ?? 'info')); ?></td>
                                        <td>
                                            <strong><?php echo \esc_html((string) ($row['event_label'] ?? '')); ?></strong>
                                            <?php if (!empty($row['reference'])) : ?><br /><span class="must-portal-muted"><?php echo \esc_html((string) $row['reference']); ?></span><?php endif; ?>
                                        </td>
                                        <td><?php echo \esc_html((string) ($row['entity_label'] ?? '')); ?></td>
                                        <td><?php echo \esc_html((string) ($row['actor_label'] ?? '')); ?></td>
                                        <td><?php echo \esc_html((string) ($row['message'] ?? '')); ?></td>
                                        <td>
                                            <?php if (!empty($row['action_url'])) : ?>
                                                <a class="must-portal-inline-link" href="<?php echo \esc_url((string) $row['action_url']); ?>"><?php echo \esc_html__('Open', 'must-hotel-booking'); ?></a>
                                            <?php else : ?>
                                                <span class="must-portal-muted"><?php echo \esc_html__('No linked portal view', 'must-hotel-booking'); ?></span>
                                            <?php endif; ?>
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
