<?php

use MustHotelBooking\Portal\PortalRenderer;

if (!\defined('ABSPATH')) {
    exit;
}

$logData = isset($moduleData['log_data']) && \is_array($moduleData['log_data']) ? $moduleData['log_data'] : [];
$rows = isset($logData['rows']) && \is_array($logData['rows']) ? $logData['rows'] : [];
$summaryCards = isset($logData['summary_cards']) && \is_array($logData['summary_cards']) ? $logData['summary_cards'] : [];
$title = isset($logData['title']) ? (string) $logData['title'] : \__('Desk Log', 'must-hotel-booking');
$description = isset($logData['description']) ? (string) $logData['description'] : '';
$emptyMessage = isset($logData['empty_message']) ? (string) $logData['empty_message'] : \__('No front-desk activity is available.', 'must-hotel-booking');

PortalRenderer::renderSummaryCards($summaryCards);

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html($title) . '</h2>';

if ($description !== '') {
    echo '<p>' . \esc_html($description) . '</p>';
}

echo '</div></div>';

if (empty($rows)) {
    PortalRenderer::renderEmptyState($emptyMessage);
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('When', 'must-hotel-booking') . '</th><th>' . \esc_html__('Actor', 'must-hotel-booking') . '</th><th>' . \esc_html__('Event', 'must-hotel-booking') . '</th><th>' . \esc_html__('Summary', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr>';
        echo '<td><strong>' . \esc_html((string) ($row['created_at'] ?? '')) . '</strong></td>';
        echo '<td>' . \esc_html((string) ($row['actor_label'] ?? \__('System', 'must-hotel-booking'))) . '</td>';
        echo '<td>';
        PortalRenderer::renderBadge((string) ($row['severity'] ?? 'info'), (string) ($row['event_label'] ?? \__('Activity', 'must-hotel-booking')));
        echo '</td>';
        echo '<td>' . \esc_html((string) ($row['message'] ?? '')) . '</td>';
        echo '<td>';

        if ((string) ($row['action_url'] ?? '') !== '' && (string) ($row['action_label'] ?? '') !== '') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url((string) $row['action_url']) . '">' . \esc_html((string) $row['action_label']) . '</a>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table></div>';
}

echo '</section>';
