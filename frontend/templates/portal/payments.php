<?php

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$detail = isset($moduleData['detail']) && \is_array($moduleData['detail']) ? $moduleData['detail'] : null;

PortalRenderer::renderSummaryCards((array) ($moduleData['summary_cards'] ?? []));

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payments', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review due balances and payment state from the portal.', 'must-hotel-booking') . '</p></div></div>';
echo '<form class="must-portal-filter-bar" method="get" action="' . \esc_url(PortalRouter::getModuleUrl('payments')) . '">';
echo '<input type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Booking ID, guest, email', 'must-hotel-booking') . '" />';
echo '<select name="payment_group">';

foreach ((array) ($moduleData['payment_group_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['payment_group'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select><button type="submit" class="must-portal-secondary-button">' . \esc_html__('Apply', 'must-hotel-booking') . '</button></form>';

if (empty($moduleData['rows'])) {
    PortalRenderer::renderEmptyState(\__('No payment rows matched the current filters.', 'must-hotel-booking'));
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Reservation', 'must-hotel-booking') . '</th><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Method', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Due', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ((array) $moduleData['rows'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr><td><strong>' . \esc_html((string) ($row['booking_id'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['accommodation'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html((string) ($row['guest'] ?? '')) . '</td><td>' . \esc_html((string) ($row['payment_method'] ?? '')) . '</td><td>';
        PortalRenderer::renderBadge((string) ($row['payment_status_key'] ?? 'info'), (string) ($row['payment_status'] ?? ''));
        echo '</td><td>' . \esc_html((string) ($row['amount_due'] ?? '')) . '</td><td><a class="must-portal-inline-link" href="' . \esc_url(PortalRouter::getModuleUrl('payments', ['reservation_id' => (int) ($row['reservation_id'] ?? 0)])) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a></td></tr>';
    }

    echo '</tbody></table></div>';
    PortalRenderer::renderPagination((array) ($moduleData['pagination'] ?? []), 'payments');
}

echo '</section>';

if (\is_array($detail)) {
    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payment detail', 'must-hotel-booking') . '</h2><p>' . \esc_html((string) ($detail['booking_id'] ?? '')) . '</p></div></div><div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Guest', 'must-hotel-booking'), (string) ($detail['guest_name'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Accommodation', 'must-hotel-booking'), (string) ($detail['accommodation'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Method', 'must-hotel-booking'), (string) ($detail['state']['method'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Amount due', 'must-hotel-booking'), \number_format_i18n((float) ($detail['state']['amount_due'] ?? 0.0), 2) . ' ' . MustBookingConfig::get_currency());
    echo '</div>';

    if ((\current_user_can(StaffAccess::CAP_MARK_PAYMENT_AS_PAID) || \current_user_can('manage_options')) && (float) ($detail['state']['amount_due'] ?? 0.0) > 0.0) {
        echo '<form method="post" action="' . \esc_url(PortalRouter::getModuleUrl('payments', ['reservation_id' => (int) ($detail['reservation_id'] ?? 0)])) . '" class="must-portal-inline-actions">';
        \wp_nonce_field('must_portal_payment_action_mark_paid_' . (int) ($detail['reservation_id'] ?? 0), 'must_portal_payment_nonce');
        echo '<input type="hidden" name="must_portal_action" value="payment_mark_paid" /><input type="hidden" name="reservation_id" value="' . \esc_attr((string) ((int) ($detail['reservation_id'] ?? 0))) . '" /><button type="submit" class="must-portal-primary-button">' . \esc_html__('Mark paid', 'must-hotel-booking') . '</button></form>';
    }

    echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payment timeline', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Recent payment events and follow-up.', 'must-hotel-booking') . '</p></div></div>';

    foreach ((array) ($detail['timeline'] ?? []) as $item) {
        if (!\is_array($item)) {
            continue;
        }

        echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($item['message'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['created_at'] ?? '')) . '</span></div>';
        PortalRenderer::renderBadge((string) ($item['severity'] ?? 'info'));
        echo '</div>';
    }

    echo '</article></section>';
}
