<?php

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$detail = isset($moduleData['detail']) && \is_array($moduleData['detail']) ? $moduleData['detail'] : null;

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservation filters', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Search and narrow the live reservation list.', 'must-hotel-booking') . '</p></div></div>';
echo '<form class="must-portal-filter-bar" method="get" action="' . \esc_url(PortalRouter::getModuleUrl('reservations')) . '">';
echo '<input type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Booking ID, guest, email', 'must-hotel-booking') . '" />';
echo '<select name="status"><option value="">' . \esc_html__('All statuses', 'must-hotel-booking') . '</option>';

foreach ((array) ($moduleData['status_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['status'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select>';
echo '<select name="payment_status"><option value="">' . \esc_html__('All payment states', 'must-hotel-booking') . '</option>';

foreach ((array) ($moduleData['payment_status_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['payment_status'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select><button type="submit" class="must-portal-secondary-button">' . \esc_html__('Apply', 'must-hotel-booking') . '</button></form><div class="must-portal-chip-row">';

foreach ((array) ($moduleData['quick_filters'] ?? []) as $filter) {
    if (!\is_array($filter)) {
        continue;
    }

    $classes = ['must-portal-chip'];

    if (!empty($filter['current'])) {
        $classes[] = 'is-current';
    }

    echo '<a class="' . \esc_attr(\implode(' ', $classes)) . '" href="' . \esc_url(PortalRouter::getModuleUrl('reservations', ['quick_filter' => (string) ($filter['slug'] ?? '')])) . '">' . \esc_html((string) ($filter['label'] ?? '')) . ' <span>' . \esc_html((string) ($filter['count'] ?? 0)) . '</span></a>';
}

echo '</div></section>';

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservations', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Operational booking records powered by the existing admin provider.', 'must-hotel-booking') . '</p></div></div>';

if (empty($moduleData['rows'])) {
    PortalRenderer::renderEmptyState(\__('No reservations matched the current filters.', 'must-hotel-booking'));
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Booking', 'must-hotel-booking') . '</th><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Stay', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Payment', 'must-hotel-booking') . '</th><th>' . \esc_html__('Total', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ((array) $moduleData['rows'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr><td><strong>' . \esc_html((string) ($row['booking_id'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['accommodation'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html((string) ($row['guest'] ?? '')) . '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['guest_email'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html((string) ($row['checkin'] ?? '')) . ' - ' . \esc_html((string) ($row['checkout'] ?? '')) . '<br /><span class="must-portal-muted">' . \esc_html(\sprintf(\_n('%d night', '%d nights', (int) ($row['nights'] ?? 0), 'must-hotel-booking'), (int) ($row['nights'] ?? 0))) . '</span></td>';
        echo '<td>';
        PortalRenderer::renderBadge((string) ($row['reservation_status_key'] ?? 'info'), (string) ($row['reservation_status'] ?? ''));
        echo '</td><td>';
        PortalRenderer::renderBadge((string) ($row['payment_status_key'] ?? 'info'), (string) ($row['payment_status'] ?? ''));
        echo '<div class="must-portal-muted">' . \esc_html((string) ($row['payment_method'] ?? '')) . '</div></td><td>' . \esc_html((string) ($row['total'] ?? '')) . '</td><td><a class="must-portal-inline-link" href="' . \esc_url(PortalRouter::getModuleUrl('reservations', ['reservation_id' => (int) ($row['id'] ?? 0)])) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a></td></tr>';
    }

    echo '</tbody></table></div>';
    PortalRenderer::renderPagination((array) ($moduleData['pagination'] ?? []), 'reservations');
}

echo '</section>';

if (\is_array($detail)) {
    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservation detail', 'must-hotel-booking') . '</h2><p>' . \esc_html((string) ($detail['booking_id'] ?? '')) . '</p></div></div><div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Guest', 'must-hotel-booking'), (string) ($detail['guest']['full_name'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Email', 'must-hotel-booking'), (string) ($detail['guest']['email'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Phone', 'must-hotel-booking'), (string) ($detail['guest']['phone'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Accommodation', 'must-hotel-booking'), (string) ($detail['stay']['accommodation'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Stay', 'must-hotel-booking'), (string) ($detail['stay']['checkin'] ?? '') . ' - ' . (string) ($detail['stay']['checkout'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Reservation status', 'must-hotel-booking'), (string) ($detail['summary']['reservation_status'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Payment status', 'must-hotel-booking'), (string) ($detail['summary']['payment_status'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Amount due', 'must-hotel-booking'), \number_format_i18n((float) ($detail['pricing']['amount_due'] ?? 0.0), 2) . ' ' . MustBookingConfig::get_currency());
    echo '</div><div class="must-portal-inline-actions">';

    if (\current_user_can(StaffAccess::CAP_EDIT_RESERVATIONS) || \current_user_can('manage_options')) {
        echo '<form method="post" action="' . \esc_url(PortalRouter::getModuleUrl('reservations', ['reservation_id' => (int) ($detail['id'] ?? 0)])) . '">';
        \wp_nonce_field('must_portal_reservation_action_confirm_' . (int) ($detail['id'] ?? 0), 'must_portal_reservation_nonce');
        echo '<input type="hidden" name="must_portal_action" value="reservation_confirm" /><input type="hidden" name="reservation_id" value="' . \esc_attr((string) ((int) ($detail['id'] ?? 0))) . '" /><button type="submit" class="must-portal-secondary-button">' . \esc_html__('Confirm', 'must-hotel-booking') . '</button></form>';
    }

    if (\current_user_can(StaffAccess::CAP_CANCEL_RESERVATION) || \current_user_can('manage_options')) {
        echo '<form method="post" action="' . \esc_url(PortalRouter::getModuleUrl('reservations', ['reservation_id' => (int) ($detail['id'] ?? 0)])) . '">';
        \wp_nonce_field('must_portal_reservation_action_cancel_' . (int) ($detail['id'] ?? 0), 'must_portal_reservation_nonce');
        echo '<input type="hidden" name="must_portal_action" value="reservation_cancel" /><input type="hidden" name="reservation_id" value="' . \esc_attr((string) ((int) ($detail['id'] ?? 0))) . '" /><button type="submit" class="must-portal-danger-button">' . \esc_html__('Cancel', 'must-hotel-booking') . '</button></form>';
    }

    echo '</div></article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Timeline', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Latest activity for this reservation.', 'must-hotel-booking') . '</p></div></div>';

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
