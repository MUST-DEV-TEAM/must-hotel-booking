<?php

use MustHotelBooking\Portal\PortalRenderer;

PortalRenderer::renderSummaryCards((array) ($moduleData['kpis'] ?? []));

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Attention', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Operational issues that need follow-up.', 'must-hotel-booking') . '</p></div></div>';

if (empty($moduleData['attention_items'])) {
    PortalRenderer::renderEmptyState(\__('Nothing needs attention right now.', 'must-hotel-booking'));
} else {
    foreach ((array) $moduleData['attention_items'] as $item) {
        if (!\is_array($item)) {
            continue;
        }

        echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['message'] ?? '')) . '</span></div>';
        PortalRenderer::renderBadge((string) ($item['severity'] ?? 'info'));
        echo '</div>';
    }
}

echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('System health', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Current setup and operational readiness checks.', 'must-hotel-booking') . '</p></div></div>';

if (empty($moduleData['health_items'])) {
    PortalRenderer::renderEmptyState(\__('No system checks were returned.', 'must-hotel-booking'));
} else {
    foreach ((array) $moduleData['health_items'] as $item) {
        if (!\is_array($item)) {
            continue;
        }

        echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['message'] ?? '')) . '</span></div>';
        PortalRenderer::renderBadge((string) ($item['status'] ?? 'info'));
        echo '</div>';
    }
}

echo '</article></section>';

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Recent reservations', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Latest booking activity across the property.', 'must-hotel-booking') . '</p></div></div>';

if (empty($moduleData['recent_reservations'])) {
    PortalRenderer::renderEmptyState(\__('No recent reservations available.', 'must-hotel-booking'));
} else {
    foreach ((array) $moduleData['recent_reservations'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($row['booking_id'] ?? '')) . ' - ' . \esc_html((string) ($row['guest'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['accommodation'] ?? '')) . ' - ' . \esc_html((string) ($row['checkin'] ?? '')) . ' - ' . \esc_html((string) ($row['checkout'] ?? '')) . '</span></div>';
        PortalRenderer::renderBadge((string) ($row['status'] ?? 'info'));
        echo '</div>';
    }
}

echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Recent activity', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Email, payment, and reservation timeline highlights.', 'must-hotel-booking') . '</p></div></div>';

if (empty($moduleData['recent_activity'])) {
    PortalRenderer::renderEmptyState(\__('No recent activity available.', 'must-hotel-booking'));
} else {
    foreach ((array) $moduleData['recent_activity'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $timestamp = (string) ($row['created_at'] ?? '');
        echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($row['message'] ?? '')) . '</strong><span>' . \esc_html($timestamp) . '</span></div>';
        PortalRenderer::renderBadge((string) ($row['severity'] ?? 'info'));
        echo '</div>';
    }
}

echo '</article></section>';

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Quick actions', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Jump directly into the next operational area.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-action-grid">';

foreach ((array) ($moduleData['quick_actions'] ?? []) as $action) {
    if (!\is_array($action)) {
        continue;
    }

    echo '<a class="must-portal-action-card" href="' . \esc_url((string) ($action['url'] ?? '#')) . '"><span class="dashicons ' . \esc_attr((string) ($action['icon'] ?? 'dashicons-arrow-right-alt2')) . '"></span><strong>' . \esc_html((string) ($action['label'] ?? '')) . '</strong></a>';
}

echo '</div></section>';
