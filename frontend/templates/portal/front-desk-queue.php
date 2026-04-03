<?php

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

if (!\defined('ABSPATH')) {
    exit;
}

$queueData = isset($moduleData['queue_data']) && \is_array($moduleData['queue_data']) ? $moduleData['queue_data'] : [];
$rows = isset($queueData['rows']) && \is_array($queueData['rows']) ? $queueData['rows'] : [];
$summaryCards = isset($queueData['summary_cards']) && \is_array($queueData['summary_cards']) ? $queueData['summary_cards'] : [];
$queueTitle = isset($queueData['queue_title']) ? (string) $queueData['queue_title'] : \__('Front Desk Queue', 'must-hotel-booking');
$queueDescription = isset($queueData['queue_description']) ? (string) $queueData['queue_description'] : '';
$emptyMessage = isset($queueData['empty_message']) ? (string) $queueData['empty_message'] : \__('No reservations are waiting in this queue right now.', 'must-hotel-booking');
$formAction = PortalRouter::getModuleUrl('front_desk', ['tab' => $activeTab]);
$canCheckIn = \current_user_can(StaffAccess::CAP_RESERVATION_CHECKIN) || \current_user_can('manage_options');
$canCheckOut = \current_user_can(StaffAccess::CAP_RESERVATION_CHECKOUT) || \current_user_can('manage_options');
$canOpenPayments = StaffAccess::userCanAccessPortalModule('payments') || \current_user_can('manage_options');

$renderQueueActionForm = static function (
    string $action,
    string $label,
    string $icon,
    string $buttonClass,
    string $nonceAction,
    int $reservationId,
    string $confirmMessage = ''
) use ($formAction): void {
    $onsubmit = $confirmMessage !== ''
        ? ' onsubmit="return confirm(\'' . \esc_js($confirmMessage) . '\');"'
        : '';
    echo '<form method="post" action="' . \esc_url($formAction) . '" class="must-portal-reservation-action-form"' . $onsubmit . '>';
    \wp_nonce_field($nonceAction, 'must_portal_reservation_nonce');
    echo '<input type="hidden" name="must_portal_action" value="' . \esc_attr($action) . '" />';
    echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
    echo '<button type="submit" class="' . \esc_attr($buttonClass) . '">';
    echo '<span class="dashicons ' . \esc_attr($icon) . '" aria-hidden="true"></span>';
    echo '<span>' . \esc_html($label) . '</span>';
    echo '</button></form>';
};

PortalRenderer::renderSummaryCards($summaryCards);

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html($queueTitle) . '</h2>';

if ($queueDescription !== '') {
    echo '<p>' . \esc_html($queueDescription) . '</p>';
}

echo '</div></div>';

if (empty($rows)) {
    PortalRenderer::renderEmptyState($emptyMessage);
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Stay', 'must-hotel-booking') . '</th><th>' . \esc_html__('Room', 'must-hotel-booking') . '</th><th>' . \esc_html__('State', 'must-hotel-booking') . '</th><th>' . \esc_html__('Payment', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        $reservationUrl = $reservationId > 0 ? PortalRouter::getModuleUrl('reservations', ['reservation_id' => $reservationId]) : '';
        $paymentsUrl = $canOpenPayments && !empty($row['payment_workspace_needed']) && $reservationId > 0
            ? PortalRouter::getModuleUrl('payments', ['reservation_id' => $reservationId])
            : '';
        $assignedRoom = \trim((string) ($row['assigned_room'] ?? ''));
        $paymentDue = (string) ($row['amount_due'] ?? '');

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
        echo '<td><strong>' . \esc_html((string) ($row['accommodation'] ?? \__('Unassigned', 'must-hotel-booking'))) . '</strong>';
        echo '<br /><span class="must-portal-muted">' . \esc_html($assignedRoom !== '' ? $assignedRoom : \__('No room assigned', 'must-hotel-booking')) . '</span>';
        echo '</td><td>';
        PortalRenderer::renderBadge((string) ($row['queue_tone'] ?? 'warning'), (string) ($row['queue_label'] ?? ''));
        echo '<br />';
        PortalRenderer::renderBadge((string) ($row['reservation_status_key'] ?? 'info'), (string) ($row['reservation_status'] ?? ''));
        echo '</td><td>';
        PortalRenderer::renderBadge((string) ($row['payment_status_key'] ?? 'info'), (string) ($row['payment_status'] ?? ''));
        echo '<br /><span class="must-portal-muted">' . \esc_html(\sprintf(\__('Due: %s', 'must-hotel-booking'), $paymentDue)) . '</span>';
        echo '</td><td><div class="must-portal-inline-actions">';

        if ($reservationUrl !== '') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url($reservationUrl) . '">' . \esc_html__('Open reservation', 'must-hotel-booking') . '</a>';
        }

        if ($paymentsUrl !== '') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url($paymentsUrl) . '">' . \esc_html__('Payments', 'must-hotel-booking') . '</a>';
        }

        if ($activeTab === 'checkin' && $canCheckIn && $reservationId > 0) {
            $renderQueueActionForm(
                'reservation_checkin',
                \__('Check in', 'must-hotel-booking'),
                'dashicons-admin-home',
                'must-portal-reservation-action-button is-primary',
                'must_portal_reservation_action_checkin_' . $reservationId,
                $reservationId,
                \__('Check in this reservation now?', 'must-hotel-booking')
            );
        }

        if ($activeTab === 'checkout' && $canCheckOut && $reservationId > 0) {
            $renderQueueActionForm(
                'reservation_checkout',
                \__('Check out', 'must-hotel-booking'),
                'dashicons-external',
                'must-portal-reservation-action-button',
                'must_portal_reservation_action_checkout_' . $reservationId,
                $reservationId,
                \__('Check out this reservation now?', 'must-hotel-booking')
            );
        }

        echo '</div></td></tr>';
    }

    echo '</tbody></table></div>';
}

echo '</section>';
