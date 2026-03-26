<?php

use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$detail = isset($moduleData['detail']) && \is_array($moduleData['detail']) ? $moduleData['detail'] : null;

PortalRenderer::renderSummaryCards((array) ($moduleData['summary_cards'] ?? []));

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Guests', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Search and review guest profiles and stay history.', 'must-hotel-booking') . '</p></div></div>';
echo '<form class="must-portal-filter-bar" method="get" action="' . \esc_url(PortalRouter::getModuleUrl('guests')) . '">';
echo '<input type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Guest name, email, phone', 'must-hotel-booking') . '" />';
echo '<button type="submit" class="must-portal-secondary-button">' . \esc_html__('Search', 'must-hotel-booking') . '</button></form>';

if (empty($moduleData['rows'])) {
    PortalRenderer::renderEmptyState(\__('No guest profiles matched the current filters.', 'must-hotel-booking'));
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Contact', 'must-hotel-booking') . '</th><th>' . \esc_html__('History', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ((array) $moduleData['rows'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr><td><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['country'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html((string) ($row['email'] ?? '')) . '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['phone'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html(\sprintf(\__('Reservations: %d', 'must-hotel-booking'), (int) ($row['total_reservations'] ?? 0))) . '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['total_spend'] ?? '')) . '</span></td><td>';

        foreach ((array) ($row['status_badges'] ?? []) as $badge) {
            PortalRenderer::renderBadge('info', (string) $badge);
        }

        echo '</td><td><a class="must-portal-inline-link" href="' . \esc_url(PortalRouter::getModuleUrl('guests', ['guest_id' => (int) ($row['id'] ?? 0)])) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a></td></tr>';
    }

    echo '</tbody></table></div>';
    PortalRenderer::renderPagination((array) ($moduleData['pagination'] ?? []), 'guests');
}

echo '</section>';

if (\is_array($detail)) {
    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Guest summary', 'must-hotel-booking') . '</h2><p>' . \esc_html((string) ($detail['summary']['full_name'] ?? '')) . '</p></div></div><div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Total reservations', 'must-hotel-booking'), (string) ($detail['summary']['total_reservations'] ?? 0));
    PortalRenderer::renderDefinitionRow(\__('Completed stays', 'must-hotel-booking'), (string) ($detail['summary']['total_completed_stays'] ?? 0));
    PortalRenderer::renderDefinitionRow(\__('Total spend', 'must-hotel-booking'), (string) ($detail['summary']['total_spend'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Upcoming due', 'must-hotel-booking'), (string) ($detail['payment_summary']['amount_due_upcoming'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Preferred method', 'must-hotel-booking'), (string) ($detail['payment_summary']['preferred_method'] ?? ''));
    echo '</div></article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Guest warnings', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Profile health and payment attention.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($detail['warnings'])) {
        PortalRenderer::renderEmptyState(\__('No guest warnings were detected.', 'must-hotel-booking'));
    } else {
        foreach ((array) $detail['warnings'] as $warning) {
            echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) $warning) . '</strong></div>';
            PortalRenderer::renderBadge('warning');
            echo '</div>';
        }
    }

    echo '</article></section>';
}
