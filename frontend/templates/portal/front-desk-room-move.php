<?php

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$roomMoveData = isset($moduleData['room_move_data']) && \is_array($moduleData['room_move_data']) ? $moduleData['room_move_data'] : [];
$rows = isset($roomMoveData['rows']) && \is_array($roomMoveData['rows']) ? $roomMoveData['rows'] : [];
$summaryCards = isset($roomMoveData['summary_cards']) && \is_array($roomMoveData['summary_cards']) ? $roomMoveData['summary_cards'] : [];
$queueTitle = isset($roomMoveData['queue_title']) ? (string) $roomMoveData['queue_title'] : \__('Room Move / Upgrade', 'must-hotel-booking');
$queueDescription = isset($roomMoveData['queue_description']) ? (string) $roomMoveData['queue_description'] : '';
$emptyMessage = isset($roomMoveData['empty_message']) ? (string) $roomMoveData['empty_message'] : \__('No room moves are available right now.', 'must-hotel-booking');
$formAction = PortalRouter::getModuleUrl('front_desk', ['tab' => 'room-move']);
$canAssignRoom = \current_user_can(StaffAccess::CAP_RESERVATION_ASSIGN_ROOM) || \current_user_can('manage_options');

PortalRenderer::renderSummaryCards($summaryCards);

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html($queueTitle) . '</h2>';

if ($queueDescription !== '') {
    echo '<p>' . \esc_html($queueDescription) . '</p>';
}

echo '</div></div>';

if (empty($rows)) {
    PortalRenderer::renderEmptyState($emptyMessage);
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Stay', 'must-hotel-booking') . '</th><th>' . \esc_html__('Current room', 'must-hotel-booking') . '</th><th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th><th>' . \esc_html__('State', 'must-hotel-booking') . '</th><th>' . \esc_html__('Move', 'must-hotel-booking') . '</th></tr></thead><tbody>';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        $reservationUrl = $reservationId > 0 ? PortalRouter::getModuleUrl('reservations', ['reservation_id' => $reservationId]) : '';
        $moveOptions = isset($row['move_options']) && \is_array($row['move_options']) ? $row['move_options'] : [];

        echo '<tr>';
        echo '<td><strong>' . \esc_html((string) ($row['guest'] ?? '')) . '</strong>';

        if ((string) ($row['guest_email'] ?? '') !== '') {
            echo '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['guest_email'] ?? '')) . '</span>';
        }

        if ((string) ($row['booking_id'] ?? '') !== '') {
            echo '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['booking_id'] ?? '')) . '</span>';
        }

        echo '</td>';
        echo '<td><strong>' . \esc_html((string) ($row['checkin'] ?? '')) . ' - ' . \esc_html((string) ($row['checkout'] ?? '')) . '</strong></td>';
        echo '<td><strong>' . \esc_html((string) ($row['assigned_room'] ?? \__('No room assigned', 'must-hotel-booking'))) . '</strong></td>';
        echo '<td><strong>' . \esc_html((string) ($row['accommodation'] ?? \__('Unassigned', 'must-hotel-booking'))) . '</strong></td>';
        echo '<td>';
        PortalRenderer::renderBadge((string) ($row['desk_state_key'] ?? 'success'), (string) ($row['desk_state'] ?? ''));
        echo '<br />';
        PortalRenderer::renderBadge((string) ($row['reservation_status_key'] ?? 'info'), (string) ($row['reservation_status'] ?? ''));
        echo '</td><td>';

        if ($reservationUrl !== '') {
            echo '<div class="must-portal-inline-actions"><a class="must-portal-inline-link" href="' . \esc_url($reservationUrl) . '">' . \esc_html__('Open reservation', 'must-hotel-booking') . '</a></div>';
        }

        if (!$canAssignRoom) {
            echo '<span class="must-portal-muted">' . \esc_html__('Room moves require room assignment access.', 'must-hotel-booking') . '</span>';
        } elseif (empty($moveOptions)) {
            echo '<span class="must-portal-muted">' . \esc_html__('No compatible room is currently available.', 'must-hotel-booking') . '</span>';
        } elseif ($reservationId > 0) {
            echo '<form method="post" action="' . \esc_url($formAction) . '" class="must-portal-form-stack">';
            \wp_nonce_field('must_portal_reservation_assign_room_' . $reservationId, 'must_portal_reservation_nonce');
            echo '<input type="hidden" name="must_portal_action" value="reservation_assign_room" />';
            echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
            echo '<input type="hidden" name="portal_return_module" value="front_desk" />';
            echo '<input type="hidden" name="portal_return_tab" value="room-move" />';
            echo '<label><span class="screen-reader-text">' . \esc_html__('New room', 'must-hotel-booking') . '</span><select name="assigned_room_id" required>';
            echo '<option value="">' . \esc_html__('Select room', 'must-hotel-booking') . '</option>';

            foreach ($moveOptions as $option) {
                if (!\is_array($option)) {
                    continue;
                }

                echo '<option value="' . \esc_attr((string) ((int) ($option['id'] ?? 0))) . '">' . \esc_html((string) ($option['label'] ?? '')) . '</option>';
            }

            echo '</select></label>';
            echo '<button type="submit" class="must-portal-button must-portal-button-primary">' . \esc_html__('Move guest', 'must-hotel-booking') . '</button>';
            echo '</form>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table></div>';
}

echo '</section>';
