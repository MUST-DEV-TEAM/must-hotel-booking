<?php

use MustHotelBooking\Portal\PortalRenderer;

PortalRenderer::renderSummaryCards((array) ($moduleData['summary_cards'] ?? []));

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Accommodation types', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Pricing, capacity, and setup readiness for room types.', 'must-hotel-booking') . '</p></div></div>';

foreach ((array) ($moduleData['type_rows'] ?? []) as $row) {
    if (!\is_array($row)) {
        continue;
    }

    echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['capacity_summary'] ?? '')) . '</span></div>';
    PortalRenderer::renderBadge(!empty($row['warnings']) ? 'warning' : 'ok', !empty($row['warnings']) ? \__('Attention', 'must-hotel-booking') : \__('Ready', 'must-hotel-booking'));
    echo '</div>';
}

echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Inventory units', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Physical inventory connected to accommodation types.', 'must-hotel-booking') . '</p></div></div>';

foreach ((array) ($moduleData['unit_rows'] ?? []) as $row) {
    if (!\is_array($row)) {
        continue;
    }

    echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($row['room_number'] ?? $row['name'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['type_name'] ?? '')) . '</span></div>';
    PortalRenderer::renderBadge((string) ($row['status_key'] ?? $row['status'] ?? 'info'), (string) ($row['status_label'] ?? ''));
    echo '</div>';
}

echo '</article></section>';
