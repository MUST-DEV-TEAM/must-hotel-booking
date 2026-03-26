<?php

use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

PortalRenderer::renderSummaryCards((array) ($moduleData['summary'] ?? []));

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$dates = isset($moduleData['range']['dates']) && \is_array($moduleData['range']['dates']) ? $moduleData['range']['dates'] : [];

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Calendar filters', 'must-hotel-booking') . '</h2><p>' . \esc_html((string) ($moduleData['range']['label'] ?? '')) . '</p></div></div>';
echo '<form class="must-portal-filter-bar" method="get" action="' . \esc_url(PortalRouter::getModuleUrl('calendar')) . '">';
echo '<input type="date" name="start_date" value="' . \esc_attr((string) ($filters['start_date'] ?? '')) . '" />';
echo '<select name="weeks">';

foreach ((array) ($filters['week_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['weeks'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select><button type="submit" class="must-portal-secondary-button">' . \esc_html__('Update', 'must-hotel-booking') . '</button></form></section>';

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Availability board', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Read-only operational calendar using the shared provider query.', 'must-hotel-booking') . '</p></div></div>';

if (empty($moduleData['rows']) || empty($dates)) {
    PortalRenderer::renderEmptyState(\__('No calendar data is available for the selected filters.', 'must-hotel-booking'));
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table must-portal-calendar-table"><thead><tr><th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';

    foreach ($dates as $date) {
        echo '<th>' . \esc_html(\wp_date('M j', \strtotime((string) $date))) . '</th>';
    }

    echo '</tr></thead><tbody>';

    foreach ((array) $moduleData['rows'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr><td><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['category_label'] ?? '')) . '</span></td>';

        foreach ((array) ($row['cells'] ?? []) as $cell) {
            if (!\is_array($cell)) {
                continue;
            }

            echo '<td class="must-portal-calendar-cell is-' . \esc_attr((string) ($cell['actual_state'] ?? 'available')) . '"><span>' . \esc_html((string) ($cell['headline'] ?? '')) . '</span></td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

echo '</section>';
