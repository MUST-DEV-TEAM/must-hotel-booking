<?php

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$detail = isset($moduleData['detail']) && \is_array($moduleData['detail']) ? $moduleData['detail'] : null;
$sourceOptions = isset($moduleData['source_options']) && \is_array($moduleData['source_options']) ? $moduleData['source_options'] : [];
$canEditReservation = \current_user_can(StaffAccess::CAP_EDIT_RESERVATIONS) || \current_user_can('manage_options');
$canCancelReservation = \current_user_can(StaffAccess::CAP_CANCEL_RESERVATION) || \current_user_can('manage_options');
$canManagePayment = \current_user_can(StaffAccess::CAP_MARK_PAYMENT_AS_PAID) || \current_user_can('manage_options');
$canOpenGuests = \current_user_can(StaffAccess::CAP_VIEW_GUESTS) || \current_user_can('manage_options');
$canOpenCalendar = \current_user_can(StaffAccess::CAP_VIEW_CALENDAR) || \current_user_can('manage_options');
$canQuickBook = \current_user_can(StaffAccess::CAP_CREATE_QUICK_BOOKING) || \current_user_can('manage_options');
$returnMode = isset($_GET['mode']) ? \sanitize_key((string) \wp_unslash($_GET['mode'])) : '';

$renderFeedItem = static function (
    string $title,
    string $message = '',
    string $tone = 'info',
    string $badgeLabel = '',
    string $reference = '',
    string $actionUrl = '',
    string $actionLabel = ''
): void {
    echo '<div class="must-portal-feed-item must-portal-feed-item--stacked">';
    echo '<div class="must-portal-feed-body">';
    echo '<div class="must-portal-feed-meta"><strong>' . \esc_html($title) . '</strong>';

    if ($message !== '') {
        echo '<span>' . \esc_html($message) . '</span>';
    }

    if ($reference !== '') {
        echo '<small class="must-portal-feed-reference">' . \esc_html($reference) . '</small>';
    }

    echo '</div><div class="must-portal-feed-actions">';
    PortalRenderer::renderBadge($tone, $badgeLabel);

    if ($actionUrl !== '' && $actionLabel !== '') {
        echo '<a class="must-portal-inline-link" href="' . \esc_url($actionUrl) . '">' . \esc_html($actionLabel) . '</a>';
    }

    echo '</div></div></div>';
};

$buildReservationUrl = static function (array $overrides = []) use ($filters): string {
    $args = [
        'search' => (string) ($filters['search'] ?? ''),
        'status' => (string) ($filters['status'] ?? ''),
        'payment_status' => (string) ($filters['payment_status'] ?? ''),
        'payment_method' => (string) ($filters['payment_method'] ?? ''),
        'checkin_from' => (string) ($filters['checkin_from'] ?? ''),
        'checkin_to' => (string) ($filters['checkin_to'] ?? ''),
        'checkin_month' => (string) ($filters['checkin_month'] ?? ''),
        'per_page' => (int) ($filters['per_page'] ?? 20),
        'paged' => (int) ($filters['paged'] ?? 1),
    ];

    if (!empty($filters['room_id'])) {
        $args['room_id'] = (int) $filters['room_id'];
    }

    foreach ($overrides as $key => $value) {
        $args[$key] = $value;
    }

    $args = \array_filter(
        $args,
        static function ($value): bool {
            return $value !== '' && $value !== null;
        }
    );

    if (isset($args['quick_filter']) && $args['quick_filter'] === 'all') {
        unset($args['quick_filter']);
    }

    if (isset($args['paged']) && (int) $args['paged'] <= 1) {
        unset($args['paged']);
    }

    if (isset($args['per_page']) && (int) $args['per_page'] === 20) {
        unset($args['per_page']);
    }

    return PortalRouter::getModuleUrl('reservations', $args);
};

if (\is_array($detail)) {
    $reservationId = isset($detail['id']) ? (int) $detail['id'] : 0;
    $summary = isset($detail['summary']) && \is_array($detail['summary']) ? $detail['summary'] : [];
    $guest = isset($detail['guest']) && \is_array($detail['guest']) ? $detail['guest'] : [];
    $stay = isset($detail['stay']) && \is_array($detail['stay']) ? $detail['stay'] : [];
    $pricing = isset($detail['pricing']) && \is_array($detail['pricing']) ? $detail['pricing'] : [];
    $payments = isset($detail['payments']) && \is_array($detail['payments']) ? $detail['payments'] : [];
    $timeline = isset($detail['timeline']) && \is_array($detail['timeline']) ? $detail['timeline'] : [];
    $emails = isset($detail['emails']) && \is_array($detail['emails']) ? $detail['emails'] : [];
    $statusKey = (string) ($summary['reservation_status_key'] ?? '');
    $paymentStatusKey = (string) ($summary['payment_status_key'] ?? '');
    $currency = (string) ($pricing['currency'] ?? '');
    $guestId = isset($guest['id']) ? (int) $guest['id'] : 0;
    $detailUrl = $buildReservationUrl(['reservation_id' => $reservationId]);
    $queueUrl = $buildReservationUrl();
    $guestWorkspaceUrl = $canOpenGuests && $guestId > 0 ? PortalRouter::getModuleUrl('guests', ['guest_id' => $guestId]) : '';
    $calendarUrl = $canOpenCalendar
        ? PortalRouter::getModuleUrl(
            'calendar',
            [
                'start_date' => (string) ($stay['checkin'] ?? \current_time('Y-m-d')),
                'weeks' => 2,
                'reservation_id' => $reservationId,
            ]
        )
        : '';
    $quickBookingUrl = $canQuickBook ? PortalRouter::getModuleUrl('quick_booking') : '';
    $paymentsUrl = StaffAccess::userCanAccessPortalModule('payments') ? PortalRouter::getModuleUrl('payments', ['reservation_id' => $reservationId]) : '';
    $nights = isset($stay['nights']) ? (int) $stay['nights'] : 0;
    $guestsCount = isset($stay['guests']) ? (int) $stay['guests'] : 0;
    $amountDue = isset($pricing['amount_due']) ? (float) $pricing['amount_due'] : 0.0;
    $storedTotal = isset($pricing['stored_total']) ? (float) $pricing['stored_total'] : 0.0;
    $amountPaid = isset($pricing['amount_paid']) ? (float) $pricing['amount_paid'] : 0.0;
    $attentionItems = [];
    $todayTs = \strtotime(\current_time('Y-m-d'));
    $checkoutTs = !empty($stay['checkout']) ? \strtotime((string) $stay['checkout']) : false;

    if ($statusKey === 'pending' || $statusKey === 'pending_payment') {
        $attentionItems[] = [
            'title' => \__('Reservation still needs confirmation', 'must-hotel-booking'),
            'message' => \__('This booking is not fully confirmed yet, so front-desk follow-up is still required.', 'must-hotel-booking'),
            'tone' => 'warning',
            'badge' => \__('Pending', 'must-hotel-booking'),
        ];
    }

    if ($amountDue > 0.0) {
        $attentionItems[] = [
            'title' => \__('Outstanding balance', 'must-hotel-booking'),
            'message' => \sprintf(
                \__('Still due: %1$s %2$s. Review payment status before arrival or checkout.', 'must-hotel-booking'),
                \number_format_i18n($amountDue, 2),
                $currency
            ),
            'tone' => $paymentStatusKey === 'failed' ? 'error' : 'warning',
            'badge' => $paymentStatusKey === 'failed' ? \__('Payment Failed', 'must-hotel-booking') : \__('Payment Due', 'must-hotel-booking'),
        ];
    }

    if ((string) ($guest['email'] ?? '') === '' || !\is_email((string) ($guest['email'] ?? ''))) {
        $attentionItems[] = [
            'title' => \__('Guest email needs review', 'must-hotel-booking'),
            'message' => \__('Communication follow-up may fail until a valid guest email address is saved.', 'must-hotel-booking'),
            'tone' => 'warning',
            'badge' => \__('Missing Email', 'must-hotel-booking'),
        ];
    }

    if ((string) ($stay['assigned_room'] ?? '') === '') {
        $attentionItems[] = [
            'title' => \__('Physical room not assigned yet', 'must-hotel-booking'),
            'message' => \__('Keep an eye on the room plan so this reservation is ready for check-in operations.', 'must-hotel-booking'),
            'tone' => 'info',
            'badge' => \__('Unassigned', 'must-hotel-booking'),
        ];
    }

    if ($checkoutTs !== false && $todayTs !== false && $checkoutTs < $todayTs && !\in_array($statusKey, ['cancelled', 'completed', 'blocked'], true)) {
        $attentionItems[] = [
            'title' => \__('Checkout date already passed', 'must-hotel-booking'),
            'message' => \__('This reservation still looks operational even though the scheduled stay has ended.', 'must-hotel-booking'),
            'tone' => 'error',
            'badge' => \__('Past Checkout', 'must-hotel-booking'),
        ];
    }

    $renderActionForm = static function (
        string $action,
        string $label,
        string $icon,
        string $buttonClass,
        string $nonceAction,
        string $confirmMessage = ''
    ) use ($reservationId, $detailUrl, $returnMode): void {
        $onsubmit = $confirmMessage !== ''
            ? ' onsubmit="return confirm(\'' . \esc_js($confirmMessage) . '\');"'
            : '';
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-reservation-action-form"' . $onsubmit . '>';
        \wp_nonce_field($nonceAction, 'must_portal_reservation_nonce');
        echo '<input type="hidden" name="must_portal_action" value="' . \esc_attr($action) . '" />';
        echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';

        if ($returnMode === 'edit') {
            echo '<input type="hidden" name="portal_return_mode" value="edit" />';
        }

        echo '<button type="submit" class="' . \esc_attr($buttonClass) . '">';
        echo '<span class="dashicons ' . \esc_attr($icon) . '" aria-hidden="true"></span>';
        echo '<span>' . \esc_html($label) . '</span>';
        echo '</button></form>';
    };

    echo '<section class="must-portal-reservation-hero">';
    echo '<div class="must-portal-reservation-hero-copy">';
    echo '<span class="must-portal-eyebrow">' . \esc_html__('Reservation Workspace', 'must-hotel-booking') . '</span>';
    echo '<h2>' . \esc_html((string) ($detail['booking_id'] ?? \__('Reservation', 'must-hotel-booking'))) . '</h2>';
    echo '<p>' . \esc_html((string) ($guest['full_name'] ?? \__('Guest details missing', 'must-hotel-booking'))) . ' | ' . \esc_html((string) ($stay['accommodation'] ?? \__('Unassigned', 'must-hotel-booking'))) . ' | ' . \esc_html((string) ($stay['checkin'] ?? '')) . ' - ' . \esc_html((string) ($stay['checkout'] ?? '')) . '</p>';
    echo '<div class="must-portal-inline-actions">';
    echo '<a class="must-portal-secondary-button" href="' . \esc_url($queueUrl) . '">' . \esc_html__('Back to queue', 'must-hotel-booking') . '</a>';

    if ($calendarUrl !== '') {
        echo '<a class="must-portal-secondary-button" href="' . \esc_url($calendarUrl) . '">' . \esc_html__('Open in calendar', 'must-hotel-booking') . '</a>';
    }

    if ($guestWorkspaceUrl !== '') {
        echo '<a class="must-portal-secondary-button" href="' . \esc_url($guestWorkspaceUrl) . '">' . \esc_html__('Open guest profile', 'must-hotel-booking') . '</a>';
    }

    if ($quickBookingUrl !== '') {
        echo '<a class="must-portal-secondary-button" href="' . \esc_url($quickBookingUrl) . '">' . \esc_html__('Add reservation', 'must-hotel-booking') . '</a>';
    }

    echo '</div></div>';
    echo '<div class="must-portal-reservation-status-grid">';
    echo '<article class="must-portal-reservation-status-card"><span>' . \esc_html__('Reservation status', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['reservation_status'] ?? '')) . '</strong>';
    PortalRenderer::renderBadge($statusKey, (string) ($summary['reservation_status'] ?? ''));
    echo '</article>';
    echo '<article class="must-portal-reservation-status-card"><span>' . \esc_html__('Payment status', 'must-hotel-booking') . '</span><strong>' . \esc_html((string) ($summary['payment_status'] ?? '')) . '</strong>';
    PortalRenderer::renderBadge($paymentStatusKey, (string) ($summary['payment_status'] ?? ''));
    echo '</article>';
    echo '<article class="must-portal-reservation-status-card"><span>' . \esc_html__('Amount due', 'must-hotel-booking') . '</span><strong>' . \esc_html(\number_format_i18n($amountDue, 2) . ' ' . $currency) . '</strong><small>' . \esc_html__('Collected balance still outstanding.', 'must-hotel-booking') . '</small></article>';
    echo '<article class="must-portal-reservation-status-card"><span>' . \esc_html__('Stay facts', 'must-hotel-booking') . '</span><strong>' . \esc_html(\sprintf(\_n('%d night', '%d nights', $nights, 'must-hotel-booking'), $nights)) . '</strong><small>' . \esc_html(\sprintf(\_n('%d guest', '%d guests', $guestsCount, 'must-hotel-booking'), $guestsCount)) . '</small></article>';
    echo '</div></section>';

    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Immediate actions', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Reservation, payment, and communication updates that staff can complete directly here.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-reservation-action-grid">';

    if ($canEditReservation && !\in_array($statusKey, ['confirmed', 'completed', 'cancelled', 'blocked'], true)) {
        $renderActionForm('reservation_confirm', \__('Confirm reservation', 'must-hotel-booking'), 'dashicons-yes-alt', 'must-portal-reservation-action-button is-primary', 'must_portal_reservation_action_confirm_' . $reservationId);
    }

    if ($canManagePayment && !\in_array($statusKey, ['pending', 'pending_payment', 'cancelled', 'blocked'], true)) {
        $renderActionForm('reservation_mark_pending', \__('Mark pending', 'must-hotel-booking'), 'dashicons-clock', 'must-portal-reservation-action-button', 'must_portal_reservation_action_mark_pending_' . $reservationId);
    }

    if ($canManagePayment && !\in_array($statusKey, ['cancelled', 'blocked'], true) && $paymentStatusKey !== 'paid') {
        $renderActionForm('reservation_mark_paid', \__('Mark paid', 'must-hotel-booking'), 'dashicons-money-alt', 'must-portal-reservation-action-button', 'must_portal_reservation_action_mark_paid_' . $reservationId);
    }

    if ($canManagePayment && $paymentStatusKey !== 'unpaid' && !\in_array($statusKey, ['cancelled', 'blocked'], true)) {
        $renderActionForm('reservation_mark_unpaid', \__('Mark unpaid', 'must-hotel-booking'), 'dashicons-backup', 'must-portal-reservation-action-button', 'must_portal_reservation_action_mark_unpaid_' . $reservationId);
    }

    if ($canEditReservation && \is_email((string) ($guest['email'] ?? ''))) {
        $renderActionForm('reservation_resend_guest_email', \__('Resend guest confirmation', 'must-hotel-booking'), 'dashicons-email-alt', 'must-portal-reservation-action-button', 'must_portal_reservation_action_resend_guest_email_' . $reservationId);
    }

    if ($canEditReservation) {
        $renderActionForm('reservation_resend_admin_email', \__('Resend admin email', 'must-hotel-booking'), 'dashicons-email', 'must-portal-reservation-action-button', 'must_portal_reservation_action_resend_admin_email_' . $reservationId);
    }

    if ($canCancelReservation && !\in_array($statusKey, ['cancelled', 'completed', 'blocked'], true)) {
        $renderActionForm(
            'reservation_cancel',
            \__('Cancel reservation', 'must-hotel-booking'),
            'dashicons-dismiss',
            'must-portal-reservation-action-button is-danger',
            'must_portal_reservation_action_cancel_' . $reservationId,
            \__('Cancel this reservation?', 'must-hotel-booking')
        );
    }

    if ($paymentsUrl !== '') {
        echo '<a class="must-portal-reservation-action-button is-link" href="' . \esc_url($paymentsUrl) . '"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span><span>' . \esc_html__('Open payment workspace', 'must-hotel-booking') . '</span></a>';
    }

    echo '</div></article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Needs attention', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Operational warnings surfaced from this reservation, payment state, and guest profile.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($attentionItems)) {
        PortalRenderer::renderEmptyState(\__('Nothing urgent is currently blocking this reservation.', 'must-hotel-booking'));
    } else {
        foreach ($attentionItems as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $renderFeedItem(
                (string) ($item['title'] ?? ''),
                (string) ($item['message'] ?? ''),
                (string) ($item['tone'] ?? 'info'),
                (string) ($item['badge'] ?? ''),
                (string) ($detail['booking_id'] ?? '')
            );
        }
    }

    echo '</article></section>';
    echo '<section class="must-portal-reservation-layout">';
    echo '<div class="must-portal-reservation-main">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Guest details', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Keep guest contact details accurate so check-in and email follow-up stay reliable.', 'must-hotel-booking') . '</p></div></div>';

    if ($canEditReservation) {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid must-portal-reservation-form">';
        \wp_nonce_field('must_portal_reservation_save_guest_' . $reservationId, 'must_portal_reservation_nonce');
        echo '<input type="hidden" name="must_portal_action" value="reservation_save_guest" />';
        echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';

        if ($returnMode === 'edit') {
            echo '<input type="hidden" name="portal_return_mode" value="edit" />';
        }

        echo '<label><span>' . \esc_html__('First name', 'must-hotel-booking') . '</span><input type="text" name="guest_first_name" value="' . \esc_attr((string) ($guest['first_name'] ?? '')) . '" /></label>';
        echo '<label><span>' . \esc_html__('Last name', 'must-hotel-booking') . '</span><input type="text" name="guest_last_name" value="' . \esc_attr((string) ($guest['last_name'] ?? '')) . '" /></label>';
        echo '<label class="must-portal-form-full"><span>' . \esc_html__('Email', 'must-hotel-booking') . '</span><input type="email" name="guest_email" value="' . \esc_attr((string) ($guest['email'] ?? '')) . '" /></label>';
        echo '<label><span>' . \esc_html__('Phone', 'must-hotel-booking') . '</span><input type="text" name="guest_phone" value="' . \esc_attr((string) ($guest['phone'] ?? '')) . '" /></label>';
        echo '<label><span>' . \esc_html__('Country / Residence', 'must-hotel-booking') . '</span><input type="text" name="guest_country" value="' . \esc_attr((string) ($guest['country'] ?? '')) . '" /></label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-primary-button">' . \esc_html__('Save guest details', 'must-hotel-booking') . '</button></div>';
        echo '</form>';
    } else {
        echo '<div class="must-portal-definition-list">';
        PortalRenderer::renderDefinitionRow(\__('Guest', 'must-hotel-booking'), (string) ($guest['full_name'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Email', 'must-hotel-booking'), (string) ($guest['email'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Phone', 'must-hotel-booking'), (string) ($guest['phone'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Country / Residence', 'must-hotel-booking'), (string) ($guest['country'] ?? ''));
        echo '</div>';
    }

    echo '</article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservation notes', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Internal source and notes stay here so staff can capture context without leaving the portal.', 'must-hotel-booking') . '</p></div></div>';

    if ($canEditReservation) {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid must-portal-reservation-form">';
        \wp_nonce_field('must_portal_reservation_save_admin_' . $reservationId, 'must_portal_reservation_nonce');
        echo '<input type="hidden" name="must_portal_action" value="reservation_save_admin_details" />';
        echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';

        if ($returnMode === 'edit') {
            echo '<input type="hidden" name="portal_return_mode" value="edit" />';
        }

        echo '<label><span>' . \esc_html__('Source / Channel', 'must-hotel-booking') . '</span><select name="booking_source">';

        foreach ($sourceOptions as $value => $label) {
            echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($summary['source'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
        }

        echo '</select></label>';
        echo '<label class="must-portal-form-full"><span>' . \esc_html__('Internal notes', 'must-hotel-booking') . '</span><textarea name="notes" rows="5">' . \esc_textarea((string) ($stay['notes'] ?? '')) . '</textarea></label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-primary-button">' . \esc_html__('Save notes', 'must-hotel-booking') . '</button></div>';
        echo '</form>';
    } else {
        echo '<div class="must-portal-definition-list">';
        PortalRenderer::renderDefinitionRow(\__('Source / Channel', 'must-hotel-booking'), (string) ($summary['source'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Internal notes', 'must-hotel-booking'), (string) ($stay['notes'] ?? \__('No internal notes saved.', 'must-hotel-booking')));
        echo '</div>';
    }

    echo '</article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payment records', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Ledger rows and collected-state history for this reservation.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($payments)) {
        PortalRenderer::renderEmptyState(\__('No payment ledger rows are linked to this reservation yet.', 'must-hotel-booking'));
    } else {
        foreach ($payments as $paymentRow) {
            if (!\is_array($paymentRow)) {
                continue;
            }

            $renderFeedItem(
                (string) ($paymentRow['amount'] ?? ''),
                \sprintf(
                    \__('%1$s | Paid: %2$s | Created: %3$s', 'must-hotel-booking'),
                    (string) ($paymentRow['method'] ?? ''),
                    (string) ($paymentRow['paid_at'] ?? \__('Not paid yet', 'must-hotel-booking')),
                    (string) ($paymentRow['created_at'] ?? '')
                ),
                (string) ($paymentRow['status_key'] ?? 'info'),
                (string) ($paymentRow['status'] ?? ''),
                (string) ($paymentRow['transaction_id'] ?? ''),
                $paymentsUrl,
                $paymentsUrl !== '' ? \__('Open payments', 'must-hotel-booking') : ''
            );
        }
    }

    echo '</article>';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Timeline / Activity', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Latest operational activity for this reservation.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($timeline)) {
        PortalRenderer::renderEmptyState(\__('No activity is recorded for this reservation yet.', 'must-hotel-booking'));
    } else {
        foreach ($timeline as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $renderFeedItem(
                (string) ($item['message'] ?? ''),
                (string) ($item['created_at'] ?? ''),
                (string) ($item['severity'] ?? 'info'),
                '',
                (string) ($item['reference'] ?? '')
            );
        }
    }

    echo '</article>';
    echo '</div>';

    echo '<aside class="must-portal-reservation-aside">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Stay overview', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Room, rate, and stay information relevant to front-desk handling.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Accommodation', 'must-hotel-booking'), (string) ($stay['accommodation'] ?? \__('Unassigned', 'must-hotel-booking')));
    PortalRenderer::renderDefinitionRow(\__('Check-in', 'must-hotel-booking'), (string) ($stay['checkin'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Check-out', 'must-hotel-booking'), (string) ($stay['checkout'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Assigned room', 'must-hotel-booking'), (string) ($stay['assigned_room'] ?? \__('Not assigned yet', 'must-hotel-booking')));
    PortalRenderer::renderDefinitionRow(\__('Rate plan', 'must-hotel-booking'), (string) ($stay['rate_plan'] ?? \__('Not set', 'must-hotel-booking')));
    PortalRenderer::renderDefinitionRow(\__('Guests', 'must-hotel-booking'), (string) $guestsCount);
    echo '</div><p class="must-portal-reservation-note">' . \esc_html((string) ($stay['edit_notice'] ?? '')) . '</p></article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Pricing summary', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Stored booking totals and coupon impact.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Final total', 'must-hotel-booking'), \number_format_i18n($storedTotal, 2) . ' ' . $currency);
    PortalRenderer::renderDefinitionRow(\__('Amount paid', 'must-hotel-booking'), \number_format_i18n($amountPaid, 2) . ' ' . $currency);
    PortalRenderer::renderDefinitionRow(\__('Amount due', 'must-hotel-booking'), \number_format_i18n($amountDue, 2) . ' ' . $currency);
    PortalRenderer::renderDefinitionRow(\__('Coupon / Discount', 'must-hotel-booking'), !empty($pricing['coupon_code']) || !empty($pricing['coupon_discount_total'])
        ? \trim((string) ($pricing['coupon_code'] ?? \__('Coupon applied', 'must-hotel-booking'))) . ' | -' . \number_format_i18n((float) ($pricing['coupon_discount_total'] ?? 0.0), 2) . ' ' . $currency
        : \__('No coupon', 'must-hotel-booking'));
    echo '</div></article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservation summary', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Booking identity, payment method, and channel context.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Booking ID', 'must-hotel-booking'), (string) ($detail['booking_id'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Reservation status', 'must-hotel-booking'), (string) ($summary['reservation_status'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Payment status', 'must-hotel-booking'), (string) ($summary['payment_status'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Payment method', 'must-hotel-booking'), (string) ($summary['payment_method'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Created date', 'must-hotel-booking'), (string) ($summary['created_at'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Source / Channel', 'must-hotel-booking'), (string) ($summary['source'] ?? ''));
    echo '</div></article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Emails / Communication', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Delivery history for guest and admin reservation emails.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($emails)) {
        PortalRenderer::renderEmptyState(\__('No email events are recorded for this reservation yet.', 'must-hotel-booking'));
    } else {
        foreach ($emails as $emailRow) {
            if (!\is_array($emailRow)) {
                continue;
            }

            $renderFeedItem(
                (string) ($emailRow['message'] ?? ''),
                (string) ($emailRow['created_at'] ?? ''),
                (string) ($emailRow['severity'] ?? 'info'),
                (string) ($emailRow['event_type'] ?? ''),
                (string) ($emailRow['reference'] ?? '')
            );
        }
    }

    echo '</article>';
    echo '</aside>';
    echo '</section>';
}

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservation queue', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Search, filter, and open any reservation into the portal workspace without going back to wp-admin.', 'must-hotel-booking') . '</p></div></div>';
echo '<form class="must-portal-filter-bar" method="get" action="' . \esc_url(PortalRouter::getModuleUrl('reservations')) . '">';
echo '<input type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Booking ID, guest, email', 'must-hotel-booking') . '" />';
echo '<select name="status"><option value="">' . \esc_html__('All statuses', 'must-hotel-booking') . '</option>';

foreach ((array) ($moduleData['status_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['status'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select>';
echo '<select name="payment_status"><option value="">' . \esc_html__('All payment states', 'must-hotel-booking') . '</option>';

foreach ((array) ($moduleData['payment_status_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['payment_status'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select>';
echo '<select name="payment_method"><option value="">' . \esc_html__('All payment methods', 'must-hotel-booking') . '</option>';

foreach ((array) ($moduleData['payment_method_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['payment_method'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select>';
echo '<button type="submit" class="must-portal-secondary-button">' . \esc_html__('Apply filters', 'must-hotel-booking') . '</button>';
echo '<a class="must-portal-secondary-button" href="' . \esc_url(PortalRouter::getModuleUrl('reservations')) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
echo '</form>';
echo '<div class="must-portal-chip-row">';

foreach ((array) ($moduleData['quick_filters'] ?? []) as $filter) {
    if (!\is_array($filter)) {
        continue;
    }

    $slug = (string) ($filter['slug'] ?? '');
    $chipClass = !empty($filter['current']) ? 'must-portal-chip is-current' : 'must-portal-chip';
    $chipUrl = $buildReservationUrl(['quick_filter' => $slug]);
    echo '<a class="' . \esc_attr($chipClass) . '" href="' . \esc_url($chipUrl) . '">';
    echo '<span>' . \esc_html((string) ($filter['label'] ?? '')) . '</span>';
    echo '<strong>' . \esc_html((string) ((int) ($filter['count'] ?? 0))) . '</strong>';
    echo '</a>';
}

echo '</div>';

if (empty($moduleData['rows'])) {
    PortalRenderer::renderEmptyState(\__('No reservations matched the current filters.', 'must-hotel-booking'));
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Reservation', 'must-hotel-booking') . '</th><th>' . \esc_html__('Stay', 'must-hotel-booking') . '</th><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Payment', 'must-hotel-booking') . '</th><th>' . \esc_html__('Total', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ((array) ($moduleData['rows'] ?? []) as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $rowReservationId = (int) ($row['id'] ?? 0);
        $rowClasses = $detail !== null && $rowReservationId === (int) ($detail['id'] ?? 0) ? ' class="is-current"' : '';
        $rowDetailUrl = $buildReservationUrl(['reservation_id' => $rowReservationId]);
        $rowGuestUrl = $canOpenGuests && (int) ($row['guest_id'] ?? 0) > 0
            ? PortalRouter::getModuleUrl('guests', ['guest_id' => (int) ($row['guest_id'] ?? 0)])
            : '';

        echo '<tr' . $rowClasses . '>';
        echo '<td><strong>' . \esc_html((string) ($row['booking_id'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['created'] ?? '')) . '</span></td>';
        echo '<td><strong>' . \esc_html((string) ($row['accommodation'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['checkin'] ?? '')) . ' - ' . \esc_html((string) ($row['checkout'] ?? '')) . '</span></td>';
        echo '<td><strong>' . \esc_html((string) ($row['guest'] ?? '')) . '</strong>';

        if ((string) ($row['guest_email'] ?? '') !== '') {
            echo '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['guest_email'] ?? '')) . '</span>';
        }

        echo '</td><td>';
        PortalRenderer::renderBadge((string) ($row['reservation_status_key'] ?? 'info'), (string) ($row['reservation_status'] ?? ''));
        echo '</td><td>';
        PortalRenderer::renderBadge((string) ($row['payment_status_key'] ?? 'info'), (string) ($row['payment_status'] ?? ''));
        echo '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['payment_method'] ?? '')) . '</span>';
        echo '</td><td>' . \esc_html((string) ($row['total'] ?? '')) . '</td><td><div class="must-portal-inline-actions">';
        echo '<a class="must-portal-inline-link" href="' . \esc_url($rowDetailUrl) . '">' . \esc_html__('Open workspace', 'must-hotel-booking') . '</a>';

        if ($rowGuestUrl !== '') {
            echo '<a class="must-portal-inline-link" href="' . \esc_url($rowGuestUrl) . '">' . \esc_html__('Guest', 'must-hotel-booking') . '</a>';
        }

        echo '</div></td></tr>';
    }

    echo '</tbody></table></div>';
    PortalRenderer::renderPagination((array) ($moduleData['pagination'] ?? []), 'reservations');
}

echo '</section>';
