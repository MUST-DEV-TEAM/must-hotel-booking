<?php

use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;
use function MustHotelBooking\Admin\get_admin_guest_full_name;

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$detail = isset($moduleData['detail']) && \is_array($moduleData['detail']) ? $moduleData['detail'] : null;
$canEditGuestContact = \current_user_can(StaffAccess::CAP_GUEST_EDIT_CONTACT) || \current_user_can('manage_options');
$canEditGuestFlags = \current_user_can(StaffAccess::CAP_GUEST_EDIT_FLAGS) || \current_user_can('manage_options');
$canAddGuestNote = \current_user_can(StaffAccess::CAP_GUEST_ADD_NOTE) || \current_user_can('manage_options');
$canViewPayments = \current_user_can(StaffAccess::CAP_PAYMENT_VIEW) || \current_user_can('manage_options');
$canViewCommunication = $canEditGuestContact || \current_user_can('manage_options');
$canViewDuplicates = $canEditGuestContact || $canEditGuestFlags || \current_user_can('manage_options');
$canOpenReservations = StaffAccess::userCanAccessPortalModule('reservations') || \current_user_can('manage_options');
$canOpenPayments = StaffAccess::userCanAccessPortalModule('payments') || \current_user_can('manage_options');

$buildGuestUrl = static function (array $overrides = []) use ($filters): string {
    $args = [];

    foreach (['search', 'country', 'stay_state', 'attention', 'flagged'] as $key) {
        if (!empty($filters[$key])) {
            $args[$key] = (string) $filters[$key];
        }
    }

    if (!empty($filters['has_notes'])) {
        $args['has_notes'] = 1;
    }

    if (!empty($filters['per_page']) && (int) $filters['per_page'] !== 20) {
        $args['per_page'] = (int) $filters['per_page'];
    }

    if (!empty($filters['paged']) && (int) $filters['paged'] > 1) {
        $args['paged'] = (int) $filters['paged'];
    }

    foreach ($overrides as $key => $value) {
        if ($value === '' || $value === false || $value === null || $value === 0) {
            unset($args[$key]);
            continue;
        }

        $args[$key] = $value;
    }

    return PortalRouter::getModuleUrl('guests', $args);
};

PortalRenderer::renderSummaryCards((array) ($moduleData['summary_cards'] ?? []));

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Guests', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Search guest profiles and manage the operational guest context your staff needs during the shift.', 'must-hotel-booking') . '</p></div></div>';
echo '<form class="must-portal-filter-bar" method="get" action="' . \esc_url(PortalRouter::getModuleUrl('guests')) . '">';
echo '<input type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Guest name, email, phone, booking ID', 'must-hotel-booking') . '" />';
echo '<button type="submit" class="must-portal-secondary-button">' . \esc_html__('Search', 'must-hotel-booking') . '</button></form>';

if (empty($moduleData['rows'])) {
    PortalRenderer::renderEmptyState(\__('No guest profiles matched the current filters.', 'must-hotel-booking'));
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Contact', 'must-hotel-booking') . '</th><th>' . \esc_html__('Stay Context', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ((array) $moduleData['rows'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr>';
        echo '<td><strong>' . \esc_html((string) ($row['name'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['country'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html((string) ($row['email'] ?? '')) . '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['phone'] ?? '')) . '</span></td>';
        echo '<td>';
        echo \esc_html(\sprintf(\__('Reservations: %d', 'must-hotel-booking'), (int) ($row['total_reservations'] ?? 0)));

        if ((string) ($row['upcoming_stay'] ?? '') !== '') {
            echo '<br /><span class="must-portal-muted">' . \esc_html(\sprintf(__('Upcoming: %s', 'must-hotel-booking'), (string) $row['upcoming_stay'])) . '</span>';
        } elseif ((string) ($row['last_stay'] ?? '') !== '') {
            echo '<br /><span class="must-portal-muted">' . \esc_html(\sprintf(__('Last stay: %s', 'must-hotel-booking'), (string) $row['last_stay'])) . '</span>';
        }

        if ($canViewPayments) {
            echo '<br /><span class="must-portal-muted">' . \esc_html((string) ($row['total_spend'] ?? '')) . '</span>';
        }

        echo '</td><td>';

        foreach ((array) ($row['status_badges'] ?? []) as $badge) {
            PortalRenderer::renderBadge('info', (string) $badge);
        }

        echo '</td><td><a class="must-portal-inline-link" href="' . \esc_url($buildGuestUrl(['guest_id' => (int) ($row['id'] ?? 0)])) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a></td></tr>';
    }

    echo '</tbody></table></div>';
    PortalRenderer::renderPagination((array) ($moduleData['pagination'] ?? []), 'guests');
}

echo '</section>';

if (\is_array($detail)) {
    $guest = isset($detail['guest']) && \is_array($detail['guest']) ? $detail['guest'] : [];
    $form = isset($detail['form']) && \is_array($detail['form']) ? $detail['form'] : [];
    $summary = isset($detail['summary']) && \is_array($detail['summary']) ? $detail['summary'] : [];
    $paymentSummary = isset($detail['payment_summary']) && \is_array($detail['payment_summary']) ? $detail['payment_summary'] : [];
    $reservations = isset($detail['reservations']) && \is_array($detail['reservations']) ? $detail['reservations'] : [];
    $emails = isset($detail['emails']) && \is_array($detail['emails']) ? $detail['emails'] : [];
    $duplicates = isset($detail['duplicates']) && \is_array($detail['duplicates']) ? $detail['duplicates'] : [];
    $operationalWarnings = isset($detail['operational_warnings']) && \is_array($detail['operational_warnings']) ? $detail['operational_warnings'] : [];
    $paymentWarnings = isset($detail['payment_warnings']) && \is_array($detail['payment_warnings']) ? $detail['payment_warnings'] : [];
    $visibleWarnings = $canViewPayments ? \array_merge($operationalWarnings, $paymentWarnings) : $operationalWarnings;
    $guestId = isset($guest['id']) ? (int) $guest['id'] : 0;
    $detailUrl = $buildGuestUrl(['guest_id' => $guestId]);
    $guestName = get_admin_guest_full_name($guest);
    $guestEmail = (string) ($guest['email'] ?? '');
    $currentNotes = \trim((string) ($guest['admin_notes'] ?? ''));
    $submittedNote = (string) ($form['internal_note'] ?? '');
    $hasVipFlag = !empty($guest['vip_flag']);
    $hasProblemFlag = !empty($guest['problem_flag']);
    $reservationsUrl = $canOpenReservations
        ? PortalRouter::getModuleUrl('reservations', ['search' => $guestEmail !== '' ? $guestEmail : $guestName])
        : '';
    $paymentsUrl = ($canViewPayments && $canOpenPayments)
        ? PortalRouter::getModuleUrl('payments', ['search' => $guestEmail !== '' ? $guestEmail : $guestName])
        : '';

    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Guest summary', 'must-hotel-booking') . '</h2><p>' . \esc_html($guestName) . '</p></div></div>';
    echo '<div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Total reservations', 'must-hotel-booking'), (string) ($summary['total_reservations'] ?? 0));
    PortalRenderer::renderDefinitionRow(\__('Completed stays', 'must-hotel-booking'), (string) ($summary['total_completed_stays'] ?? 0));
    PortalRenderer::renderDefinitionRow(\__('Currently in house', 'must-hotel-booking'), !empty($summary['currently_in_house']) ? \__('Yes', 'must-hotel-booking') : \__('No', 'must-hotel-booking'));
    PortalRenderer::renderDefinitionRow(\__('Next stay', 'must-hotel-booking'), (string) (($summary['next_checkin'] ?? '') !== '' ? ((string) ($summary['next_checkin'] ?? '') . ' - ' . (string) ($summary['next_checkout'] ?? '')) : \__('No upcoming stay', 'must-hotel-booking')));

    if ($canViewPayments) {
        PortalRenderer::renderDefinitionRow(\__('Total spend', 'must-hotel-booking'), (string) ($summary['total_spend'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Upcoming due', 'must-hotel-booking'), (string) ($paymentSummary['amount_due_upcoming'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Preferred method', 'must-hotel-booking'), (string) ($paymentSummary['preferred_method'] ?? ''));
    }

    echo '</div>';

    if ($reservationsUrl !== '' || $paymentsUrl !== '') {
        echo '<div class="must-portal-inline-actions">';

        if ($reservationsUrl !== '') {
            echo '<a class="must-portal-secondary-button" href="' . \esc_url($reservationsUrl) . '">' . \esc_html__('Open reservations', 'must-hotel-booking') . '</a>';
        }

        if ($paymentsUrl !== '') {
            echo '<a class="must-portal-secondary-button" href="' . \esc_url($paymentsUrl) . '">' . \esc_html__('Open payments', 'must-hotel-booking') . '</a>';
        }

        echo '</div>';
    }

    echo '</article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Guest status', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Operational flags and warnings for the current guest profile.', 'must-hotel-booking') . '</p></div></div>';

    if ($hasVipFlag || $hasProblemFlag) {
        echo '<div class="must-portal-inline-actions">';

        if ($hasVipFlag) {
            PortalRenderer::renderBadge('info', \__('VIP', 'must-hotel-booking'));
        }

        if ($hasProblemFlag) {
            PortalRenderer::renderBadge('warning', \__('Problem', 'must-hotel-booking'));
        }

        echo '</div>';
    }

    if (empty($visibleWarnings) && !$hasVipFlag && !$hasProblemFlag) {
        PortalRenderer::renderEmptyState(\__('No guest warnings were detected.', 'must-hotel-booking'));
    } else {
        foreach ($visibleWarnings as $warning) {
            echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) $warning) . '</strong></div>';
            PortalRenderer::renderBadge('warning', \__('Attention', 'must-hotel-booking'));
            echo '</div>';
        }
    }

    if ($canEditGuestFlags) {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid must-portal-reservation-form">';
        \wp_nonce_field('must_portal_guest_save_flags_' . $guestId, 'must_portal_guest_nonce');
        echo '<input type="hidden" name="must_portal_action" value="guest_save_flags" />';
        echo '<input type="hidden" name="guest_id" value="' . \esc_attr((string) $guestId) . '" />';
        echo '<label><span>' . \esc_html__('Profile flags', 'must-hotel-booking') . '</span><span class="must-portal-muted">' . \esc_html__('Use only for operationally relevant guest status.', 'must-hotel-booking') . '</span></label>';
        echo '<label><input type="checkbox" name="vip_flag" value="1"' . \checked(!empty($form['vip_flag']) || $hasVipFlag, true, false) . ' /> ' . \esc_html__('VIP guest', 'must-hotel-booking') . '</label>';
        echo '<label><input type="checkbox" name="problem_flag" value="1"' . \checked(!empty($form['problem_flag']) || $hasProblemFlag, true, false) . ' /> ' . \esc_html__('Problem guest', 'must-hotel-booking') . '</label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-primary-button">' . \esc_html__('Save flags', 'must-hotel-booking') . '</button></div>';
        echo '</form>';
    }

    echo '</article></section>';

    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Contact profile', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Keep contact details current so staff can identify and support the guest correctly.', 'must-hotel-booking') . '</p></div></div>';

    if ($canEditGuestContact) {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid must-portal-reservation-form">';
        \wp_nonce_field('must_portal_guest_save_contact_' . $guestId, 'must_portal_guest_nonce');
        echo '<input type="hidden" name="must_portal_action" value="guest_save_contact" />';
        echo '<input type="hidden" name="guest_id" value="' . \esc_attr((string) $guestId) . '" />';
        echo '<label><span>' . \esc_html__('First name', 'must-hotel-booking') . '</span><input type="text" name="first_name" value="' . \esc_attr((string) ($form['first_name'] ?? '')) . '" /></label>';
        echo '<label><span>' . \esc_html__('Last name', 'must-hotel-booking') . '</span><input type="text" name="last_name" value="' . \esc_attr((string) ($form['last_name'] ?? '')) . '" /></label>';
        echo '<label><span>' . \esc_html__('Email', 'must-hotel-booking') . '</span><input type="email" name="email" value="' . \esc_attr((string) ($form['email'] ?? '')) . '" /></label>';
        echo '<label><span>' . \esc_html__('Phone', 'must-hotel-booking') . '</span><input type="text" name="phone" value="' . \esc_attr((string) ($form['phone'] ?? '')) . '" /></label>';
        echo '<label class="must-portal-form-full"><span>' . \esc_html__('Country / Residence', 'must-hotel-booking') . '</span><input type="text" name="country" value="' . \esc_attr((string) ($form['country'] ?? '')) . '" /></label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-primary-button">' . \esc_html__('Save contact details', 'must-hotel-booking') . '</button></div>';
        echo '</form>';
    } else {
        echo '<div class="must-portal-definition-list">';
        PortalRenderer::renderDefinitionRow(\__('Email', 'must-hotel-booking'), (string) ($guest['email'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Phone', 'must-hotel-booking'), (string) ($guest['phone'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Country / Residence', 'must-hotel-booking'), (string) ($guest['country'] ?? ''));
        echo '</div>';
    }

    echo '</article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Service notes', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Guest-facing service context is kept as appended internal notes for shift handoff.', 'must-hotel-booking') . '</p></div></div>';

    if ($currentNotes !== '') {
        echo '<div class="must-portal-form-grid must-portal-reservation-form">';
        echo '<label class="must-portal-form-full"><span>' . \esc_html__('Current notes', 'must-hotel-booking') . '</span><textarea rows="7" readonly>' . \esc_textarea($currentNotes) . '</textarea></label>';
        echo '</div>';
    } else {
        PortalRenderer::renderEmptyState(\__('No service notes have been added yet.', 'must-hotel-booking'));
    }

    if ($canAddGuestNote) {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid must-portal-reservation-form">';
        \wp_nonce_field('must_portal_guest_add_note_' . $guestId, 'must_portal_guest_nonce');
        echo '<input type="hidden" name="must_portal_action" value="guest_add_note" />';
        echo '<input type="hidden" name="guest_id" value="' . \esc_attr((string) $guestId) . '" />';
        echo '<label class="must-portal-form-full"><span>' . \esc_html__('Add service note', 'must-hotel-booking') . '</span><textarea name="internal_note" rows="4" placeholder="' . \esc_attr__('Add a service handoff note for the next staff member.', 'must-hotel-booking') . '">' . \esc_textarea($submittedNote) . '</textarea></label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-primary-button">' . \esc_html__('Add note', 'must-hotel-booking') . '</button></div>';
        echo '</form>';
    }

    echo '</article></section>';

    if ($canViewPayments) {
        echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payment context', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Current payment posture for the guest across recent and upcoming stays.', 'must-hotel-booking') . '</p></div></div><div class="must-portal-definition-list">';
        PortalRenderer::renderDefinitionRow(\__('Total paid', 'must-hotel-booking'), (string) ($paymentSummary['total_paid'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Amount due on upcoming reservations', 'must-hotel-booking'), (string) ($paymentSummary['amount_due_upcoming'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Preferred payment method', 'must-hotel-booking'), (string) ($paymentSummary['preferred_method'] ?? ''));
        PortalRenderer::renderDefinitionRow(\__('Failed payment count', 'must-hotel-booking'), (string) ($paymentSummary['failed_payment_count'] ?? 0));
        echo '</div></article>';
    }

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Reservation history', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Recent stay history for this guest profile.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($reservations)) {
        PortalRenderer::renderEmptyState(\__('No reservations are attached to this guest profile yet.', 'must-hotel-booking'));
    } else {
        echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th><th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th><th>' . \esc_html__('Stay', 'must-hotel-booking') . '</th><th>' . \esc_html__('Reservation', 'must-hotel-booking') . '</th>';

        if ($canViewPayments) {
            echo '<th>' . \esc_html__('Payment', 'must-hotel-booking') . '</th>';
        }

        echo '<th></th></tr></thead><tbody>';

        foreach ($reservations as $reservationRow) {
            if (!\is_array($reservationRow)) {
                continue;
            }

            $reservationId = isset($reservationRow['id']) ? (int) $reservationRow['id'] : 0;
            $reservationUrl = $reservationId > 0 && $canOpenReservations ? PortalRouter::getModuleUrl('reservations', ['reservation_id' => $reservationId]) : '';
            $paymentUrl = $reservationId > 0 && $canViewPayments && $canOpenPayments ? PortalRouter::getModuleUrl('payments', ['reservation_id' => $reservationId]) : '';

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($reservationRow['booking_id'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($reservationRow['accommodation'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($reservationRow['checkin'] ?? '')) . '<br /><span class="must-portal-muted">' . \esc_html((string) ($reservationRow['checkout'] ?? '')) . '</span></td>';
            echo '<td>' . \esc_html((string) ($reservationRow['reservation_status'] ?? '')) . '</td>';

            if ($canViewPayments) {
                echo '<td>' . \esc_html((string) ($reservationRow['payment_status'] ?? '')) . '<br /><span class="must-portal-muted">' . \esc_html((string) ($reservationRow['total'] ?? '')) . '</span></td>';
            }

            echo '<td>';

            if ($reservationUrl !== '') {
                echo '<a class="must-portal-inline-link" href="' . \esc_url($reservationUrl) . '">' . \esc_html__('Reservation', 'must-hotel-booking') . '</a>';
            }

            if ($paymentUrl !== '') {
                echo ($reservationUrl !== '' ? ' ' : '') . '<a class="must-portal-inline-link" href="' . \esc_url($paymentUrl) . '">' . \esc_html__('Payment', 'must-hotel-booking') . '</a>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</article>';

    if ($canViewCommunication) {
        echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Communication summary', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Recent guest email activity linked to the guest profile.', 'must-hotel-booking') . '</p></div></div>';

        if (empty($emails)) {
            PortalRenderer::renderEmptyState(\__('No recent guest email activity was found.', 'must-hotel-booking'));
        } else {
            echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('When', 'must-hotel-booking') . '</th><th>' . \esc_html__('Template', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Recipient', 'must-hotel-booking') . '</th><th>' . \esc_html__('Message', 'must-hotel-booking') . '</th></tr></thead><tbody>';

            foreach ($emails as $emailRow) {
                if (!\is_array($emailRow)) {
                    continue;
                }

                echo '<tr>';
                echo '<td>' . \esc_html((string) ($emailRow['created_at'] ?? '')) . '</td>';
                echo '<td><code>' . \esc_html((string) ($emailRow['template_key'] ?? '')) . '</code></td>';
                echo '<td>' . \esc_html((string) ($emailRow['status'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($emailRow['recipient_email'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($emailRow['message'] ?? '')) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        echo '</article>';
    }

    if ($canViewDuplicates) {
        echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Possible duplicates', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Likely matching guest profiles based on the current duplicate rules.', 'must-hotel-booking') . '</p></div></div>';

        if (empty($duplicates)) {
            PortalRenderer::renderEmptyState(\__('No likely duplicate guest profiles were detected.', 'must-hotel-booking'));
        } else {
            echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Email', 'must-hotel-booking') . '</th><th>' . \esc_html__('Phone', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

            foreach ($duplicates as $duplicateRow) {
                if (!\is_array($duplicateRow)) {
                    continue;
                }

                $duplicateGuestId = isset($duplicateRow['id']) ? (int) $duplicateRow['id'] : 0;

                echo '<tr>';
                echo '<td>' . \esc_html((string) ($duplicateRow['name'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($duplicateRow['email'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($duplicateRow['phone'] ?? '')) . '</td>';
                echo '<td><a class="must-portal-inline-link" href="' . \esc_url($buildGuestUrl(['guest_id' => $duplicateGuestId])) . '">' . \esc_html__('Open guest', 'must-hotel-booking') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }

        echo '</article>';
    }
}
