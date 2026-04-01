<?php

use MustHotelBooking\Portal\PortalRenderer;

$attentionItems = isset($moduleData['attention_items']) && \is_array($moduleData['attention_items']) ? $moduleData['attention_items'] : [];
$healthItems = isset($moduleData['health_items']) && \is_array($moduleData['health_items']) ? $moduleData['health_items'] : [];
$recentReservations = isset($moduleData['recent_reservations']) && \is_array($moduleData['recent_reservations']) ? $moduleData['recent_reservations'] : [];
$recentActivity = isset($moduleData['recent_activity']) && \is_array($moduleData['recent_activity']) ? $moduleData['recent_activity'] : [];
$quickActions = isset($moduleData['quick_actions']) && \is_array($moduleData['quick_actions']) ? $moduleData['quick_actions'] : [];
$counts = isset($moduleData['portal_counts']) && \is_array($moduleData['portal_counts']) ? $moduleData['portal_counts'] : [];
$showHealth = !empty($moduleData['show_health']);

echo '<section class="must-portal-dashboard-hero">';
echo '<div class="must-portal-dashboard-hero-copy">';
echo '<span class="must-portal-eyebrow">' . \esc_html__('Today', 'must-hotel-booking') . '</span>';
echo '<h2>' . \esc_html__('Front desk board', 'must-hotel-booking') . '</h2>';
echo '<p>' . \esc_html__('Move through arrivals, payments, and guest follow-up from one operational workspace instead of hopping between admin screens.', 'must-hotel-booking') . '</p>';
echo '</div>';
echo '<div class="must-portal-dashboard-hero-metrics">';
echo '<div class="must-portal-dashboard-hero-pill"><strong>' . \esc_html((string) (int) ($counts['attention'] ?? 0)) . '</strong><span>' . \esc_html__('Needs action', 'must-hotel-booking') . '</span></div>';
echo '<div class="must-portal-dashboard-hero-pill"><strong>' . \esc_html((string) (int) ($counts['recent_reservations'] ?? 0)) . '</strong><span>' . \esc_html__('Recent reservations', 'must-hotel-booking') . '</span></div>';
echo '<div class="must-portal-dashboard-hero-pill"><strong>' . \esc_html((string) (int) ($counts['recent_activity'] ?? 0)) . '</strong><span>' . \esc_html__('Activity items', 'must-hotel-booking') . '</span></div>';

if ($showHealth) {
    echo '<div class="must-portal-dashboard-hero-pill is-admin"><strong>' . \esc_html((string) (int) ($counts['health_review'] ?? 0)) . '</strong><span>' . \esc_html__('Health checks to review', 'must-hotel-booking') . '</span></div>';
}

echo '</div>';
echo '</section>';

PortalRenderer::renderSummaryCards((array) ($moduleData['kpis'] ?? []));

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Needs action now', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Operational items that should be reviewed before they become guest-facing problems.', 'must-hotel-booking') . '</p></div></div>';

if (empty($attentionItems)) {
    PortalRenderer::renderEmptyState(\__('Nothing urgent is waiting right now.', 'must-hotel-booking'));
} else {
    foreach ($attentionItems as $item) {
        if (!\is_array($item)) {
            continue;
        }

        $reference = (string) ($item['reference'] ?? '');
        $actionUrl = (string) ($item['action_url'] ?? '');
        echo '<div class="must-portal-feed-item must-portal-feed-item--stacked">';
        echo '<div class="must-portal-feed-body">';
        echo '<div class="must-portal-feed-meta"><strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['message'] ?? '')) . '</span>';

        if ($reference !== '') {
            echo '<small class="must-portal-feed-reference">' . \esc_html($reference) . '</small>';
        }

        echo '</div><div class="must-portal-feed-actions">';
        PortalRenderer::renderBadge((string) ($item['severity'] ?? 'info'));

        if ($actionUrl !== '') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url($actionUrl) . '">' . \esc_html__('Review item', 'must-hotel-booking') . '</a>';
        }

        echo '</div></div></div>';
    }
}

echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Jump into work', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Use the fastest route into the next operational area instead of navigating the full menu.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-action-grid">';

if (empty($quickActions)) {
    PortalRenderer::renderEmptyState(\__('No workspace shortcuts are available right now.', 'must-hotel-booking'));
} else {
    foreach ($quickActions as $action) {
        if (!\is_array($action)) {
            continue;
        }

        echo '<a class="must-portal-action-card" href="' . \esc_url((string) ($action['url'] ?? '#')) . '"><span class="dashicons ' . \esc_attr((string) ($action['icon'] ?? 'dashicons-arrow-right-alt2')) . '"></span><strong>' . \esc_html((string) ($action['label'] ?? '')) . '</strong><small>' . \esc_html((string) ($action['description'] ?? '')) . '</small></a>';
    }
}

echo '</div></article></section>';

echo '<section class="must-portal-grid must-portal-grid--2">';
echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Recent reservations', 'must-hotel-booking') . '</h2><p>' . \esc_html__('The latest bookings created or updated by guests and staff.', 'must-hotel-booking') . '</p></div></div>';

if (empty($recentReservations)) {
    PortalRenderer::renderEmptyState(\__('No recent reservations are available.', 'must-hotel-booking'));
} else {
    foreach ($recentReservations as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<div class="must-portal-feed-item must-portal-feed-item--stacked">';
        echo '<div class="must-portal-feed-body">';
        echo '<div class="must-portal-feed-meta"><strong>' . \esc_html((string) ($row['booking_id'] ?? '')) . ' - ' . \esc_html((string) ($row['guest'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['accommodation'] ?? '')) . ' - ' . \esc_html((string) ($row['checkin'] ?? '')) . ' - ' . \esc_html((string) ($row['checkout'] ?? '')) . '</span><small class="must-portal-feed-reference">' . \esc_html((string) ($row['payment'] ?? '')) . ' - ' . \esc_html((string) ($row['total'] ?? '')) . '</small></div>';
        echo '<div class="must-portal-feed-actions">';
        PortalRenderer::renderBadge((string) ($row['status'] ?? 'info'));
        echo '<div class="must-portal-inline-actions">';

        if (!empty($row['view_url'])) {
            echo '<a class="must-portal-inline-link" href="' . \esc_url((string) $row['view_url']) . '">' . \esc_html__('Open reservation', 'must-hotel-booking') . '</a>';
        }

        if (!empty($row['edit_url'])) {
            echo '<a class="must-portal-inline-link" href="' . \esc_url((string) $row['edit_url']) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
        }

        echo '</div></div></div></div>';
    }
}

echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Recent activity', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Payment, reservation, and email events that have happened most recently.', 'must-hotel-booking') . '</p></div></div>';

if (empty($recentActivity)) {
    PortalRenderer::renderEmptyState(\__('No recent activity is available.', 'must-hotel-booking'));
} else {
    foreach ($recentActivity as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<div class="must-portal-feed-item must-portal-feed-item--stacked">';
        echo '<div class="must-portal-feed-body">';
        echo '<div class="must-portal-feed-meta"><strong>' . \esc_html((string) ($row['message'] ?? '')) . '</strong><span>' . \esc_html((string) ($row['created_at'] ?? '')) . '</span>';

        if (!empty($row['reference'])) {
            echo '<small class="must-portal-feed-reference">' . \esc_html((string) $row['reference']) . '</small>';
        }

        echo '</div><div class="must-portal-feed-actions">';
        PortalRenderer::renderBadge((string) ($row['severity'] ?? 'info'));

        if (!empty($row['action_url'])) {
            echo '<a class="must-portal-inline-link" href="' . \esc_url((string) $row['action_url']) . '">' . \esc_html__('Open record', 'must-hotel-booking') . '</a>';
        }

        echo '</div></div></div>';
    }
}

echo '</article></section>';

if ($showHealth) {
    echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('System health', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Admin-only configuration checks with direct links to the area that needs review.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($healthItems)) {
        PortalRenderer::renderEmptyState(\__('No system health checks were returned.', 'must-hotel-booking'));
    } else {
        foreach ($healthItems as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $actionUrl = (string) ($item['action_url'] ?? '');
            $actionLabel = !empty($item['action_label'])
                ? (string) $item['action_label']
                : \__('Open settings', 'must-hotel-booking');
            echo '<div class="must-portal-feed-item must-portal-feed-item--stacked">';
            echo '<div class="must-portal-feed-body">';
            echo '<div class="must-portal-feed-meta"><strong>' . \esc_html((string) ($item['label'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['message'] ?? '')) . '</span></div>';
            echo '<div class="must-portal-feed-actions">';
            PortalRenderer::renderBadge((string) ($item['status'] ?? 'info'));

            if ($actionUrl !== '') {
                echo '<a class="must-portal-inline-link" href="' . \esc_url($actionUrl) . '">' . \esc_html($actionLabel) . '</a>';
            }

            echo '</div></div></div>';
        }
    }

    echo '</section>';
}
