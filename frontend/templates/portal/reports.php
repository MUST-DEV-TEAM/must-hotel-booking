<?php

use MustHotelBooking\Portal\PortalRenderer;

PortalRenderer::renderSummaryCards((array) ($moduleData['kpis'] ?? []));

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservation breakdowns', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Status and payment distribution for the selected range.', 'must-hotel-booking') . '</p></div></div>';

foreach ((array) ($moduleData['breakdowns'] ?? []) as $label => $rows) {
    echo '<h3 class="must-portal-subheading">' . \esc_html(\ucwords(\str_replace('_', ' ', (string) $label))) . '</h3><div class="must-portal-mini-list">';

    foreach ((array) $rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<div class="must-portal-mini-item"><strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['value'] ?? '')) . '</span></div>';
    }

    echo '</div>';
}

echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Top accommodations', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Highest-performing accommodation types in the current range.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-mini-list">';

foreach ((array) ($moduleData['top_accommodations'] ?? []) as $row) {
    if (!\is_array($row)) {
        continue;
    }

    echo '<div class="must-portal-mini-item"><strong>' . \esc_html((string) ($row['room_name'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['revenue'] ?? '')) . '</span></div>';
}

echo '</div></article></section>';

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Coupons', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Top discount codes used in the current report.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-mini-list">';

foreach ((array) ($moduleData['coupons'] ?? []) as $row) {
    if (!\is_array($row)) {
        continue;
    }

    echo '<div class="must-portal-mini-item"><strong>' . \esc_html((string) ($row['coupon_code'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['discount_total'] ?? '')) . '</span></div>';
}

echo '</div></article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Issues', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Report-level integrity warnings and exceptions.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-mini-list">';

foreach ((array) ($moduleData['issues'] ?? []) as $row) {
    if (!\is_array($row)) {
        continue;
    }

    echo '<div class="must-portal-mini-item"><strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['value'] ?? '')) . '</span></div>';
}

echo '</div></article></section>';
