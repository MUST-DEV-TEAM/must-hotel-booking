<?php

use MustHotelBooking\Portal\PortalRenderer;

PortalRenderer::renderSummaryCards((array) ($moduleData['summary_cards'] ?? []));

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Accommodation rule health', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Restriction coverage and inventory warnings by accommodation.', 'must-hotel-booking') . '</p></div></div>';

foreach ((array) ($moduleData['room_rows'] ?? []) as $row) {
    if (!\is_array($row)) {
        continue;
    }

    echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong><span>' . \esc_html(\sprintf(\__('Rules: %1$d | Blocks: %2$d', 'must-hotel-booking'), (int) ($row['rule_count'] ?? 0), (int) ($row['future_block_count'] ?? 0))) . '</span></div>';
    PortalRenderer::renderBadge(!empty($row['warnings']) ? 'warning' : 'ok', !empty($row['warnings']) ? \__('Attention', 'must-hotel-booking') : \__('Healthy', 'must-hotel-booking'));
    echo '</div>';
}

echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Rules and blocks', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Read-only operational summary for phase 1.', 'must-hotel-booking') . '</p></div></div>';

foreach ((array) ($moduleData['entry_rows'] ?? []) as $row) {
    if (!\is_array($row)) {
        continue;
    }

    echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($row['rule_type_label'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['room_name'] ?? '')) . ' | ' . \esc_html((string) ($row['availability_date'] ?? '')) . ' - ' . \esc_html((string) ($row['end_date'] ?? '')) . '</span></div>';
    PortalRenderer::renderBadge(!empty($row['is_active']) ? 'ok' : 'disabled', (string) ($row['status_label'] ?? ''));
    echo '</div>';
}

echo '</article></section>';
