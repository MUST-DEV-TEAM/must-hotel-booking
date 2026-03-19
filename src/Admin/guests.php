<?php

namespace MustHotelBooking\Admin;

/**
 * @param array<string, scalar|int|bool> $args
 */
function get_admin_guests_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-guests');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

/**
 * @param array<string, mixed> $guest
 */
function get_admin_guest_full_name(array $guest): string
{
    $firstName = isset($guest['first_name']) ? \trim((string) $guest['first_name']) : '';
    $lastName = isset($guest['last_name']) ? \trim((string) $guest['last_name']) : '';
    $fullName = \trim($firstName . ' ' . $lastName);

    if ($fullName !== '') {
        return $fullName;
    }

    if (!empty($guest['email'])) {
        return (string) $guest['email'];
    }

    return \__('Guest details missing', 'must-hotel-booking');
}

function is_guests_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-guests';
}

/**
 * @return array<string, mixed>
 */
function get_guests_admin_save_state(): array
{
    global $mustHotelBookingGuestsAdminSaveState;

    if (isset($mustHotelBookingGuestsAdminSaveState) && \is_array($mustHotelBookingGuestsAdminSaveState)) {
        return $mustHotelBookingGuestsAdminSaveState;
    }

    $mustHotelBookingGuestsAdminSaveState = [
        'errors' => [],
        'form' => null,
        'selected_guest_id' => 0,
    ];

    return $mustHotelBookingGuestsAdminSaveState;
}

/**
 * @param array<string, mixed> $state
 */
function set_guests_admin_save_state(array $state): void
{
    global $mustHotelBookingGuestsAdminSaveState;
    $mustHotelBookingGuestsAdminSaveState = $state;
}

function clear_guests_admin_save_state(): void
{
    set_guests_admin_save_state([
        'errors' => [],
        'form' => null,
        'selected_guest_id' => 0,
    ]);
}

function maybe_handle_guests_admin_actions_early(): void
{
    if (!is_guests_admin_request()) {
        return;
    }

    ensure_admin_capability();
    $query = GuestAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod === 'POST') {
        set_guests_admin_save_state((new GuestAdminActions())->handleSaveRequest($query));

        return;
    }

    (new GuestAdminActions())->handleGetAction($query);
}

function render_guests_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'guest_saved' => ['success', \__('Guest profile saved successfully.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';
    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';
}

/**
 * @param array<int, string> $errors
 */
function render_guests_error_notice(array $errors): void
{
    if (empty($errors)) {
        return;
    }

    echo '<div class="notice notice-error"><ul>';
    foreach ($errors as $error) {
        echo '<li>' . \esc_html((string) $error) . '</li>';
    }
    echo '</ul></div>';
}

/**
 * @param array<int, array<string, string>> $summaryCards
 */
function render_guests_summary_cards(array $summaryCards): void
{
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin:18px 0;">';

    foreach ($summaryCards as $card) {
        if (!\is_array($card)) {
            continue;
        }

        echo '<article class="postbox" style="margin:0;padding:18px 20px;">';
        echo '<p style="margin:0 0 8px 0;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;">' . \esc_html((string) ($card['label'] ?? '')) . '</p>';
        echo '<strong style="display:block;font-size:32px;line-height:1.1;">' . \esc_html((string) ($card['value'] ?? '0')) . '</strong>';
        echo '<p style="margin:8px 0 0 0;color:#646970;">' . \esc_html((string) ($card['meta'] ?? '')) . '</p>';
        echo '</article>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, string> $countryOptions
 */
function render_guests_filters(array $filters, array $countryOptions): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Filters', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-guests" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-guest-filter-search">' . \esc_html__('Search', 'must-hotel-booking') . '</label></th><td><input id="must-guest-filter-search" class="regular-text" type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Guest name, email, phone, booking ID', 'must-hotel-booking') . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-filter-country">' . \esc_html__('Country', 'must-hotel-booking') . '</label></th><td><select id="must-guest-filter-country" name="country"><option value="">' . \esc_html__('All countries', 'must-hotel-booking') . '</option>';
    foreach ($countryOptions as $country => $label) {
        echo '<option value="' . \esc_attr($country) . '"' . \selected((string) ($filters['country'] ?? ''), $country, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-filter-stay-state">' . \esc_html__('Stay status', 'must-hotel-booking') . '</label></th><td><select id="must-guest-filter-stay-state" name="stay_state">';
    foreach (
        [
            '' => __('All guest stays', 'must-hotel-booking'),
            'upcoming' => __('Has upcoming stay', 'must-hotel-booking'),
            'in_house' => __('Currently in house', 'must-hotel-booking'),
            'past_stays' => __('Has past stays', 'must-hotel-booking'),
            'no_completed_stays' => __('No completed stays', 'must-hotel-booking'),
        ] as $value => $label
    ) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['stay_state'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-filter-attention">' . \esc_html__('Payment attention', 'must-hotel-booking') . '</label></th><td><select id="must-guest-filter-attention" name="attention">';
    foreach (
        [
            '' => __('All payment states', 'must-hotel-booking'),
            'unpaid_upcoming' => __('Has unpaid upcoming reservation', 'must-hotel-booking'),
            'failed_payment' => __('Has failed payment history', 'must-hotel-booking'),
        ] as $value => $label
    ) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['attention'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-filter-flagged">' . \esc_html__('Flags', 'must-hotel-booking') . '</label></th><td><select id="must-guest-filter-flagged" name="flagged">';
    foreach (
        [
            '' => __('All guests', 'must-hotel-booking'),
            'flagged' => __('VIP or problem', 'must-hotel-booking'),
            'vip' => __('VIP only', 'must-hotel-booking'),
            'problem' => __('Problem guest only', 'must-hotel-booking'),
        ] as $value => $label
    ) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected((string) ($filters['flagged'] ?? ''), $value, false) . '>' . \esc_html($label) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Notes', 'must-hotel-booking') . '</th><td><label><input type="checkbox" name="has_notes" value="1"' . \checked(!empty($filters['has_notes']), true, false) . ' /> ' . \esc_html__('Only show guests with internal notes', 'must-hotel-booking') . '</label></td></tr>';

    echo '</tbody></table>';
    \submit_button(\__('Apply Filters', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_guests_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function render_guests_table(array $rows): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Guests Overview', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Phone', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Country', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reservations', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Upcoming Stay', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Last Stay', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Total Spend', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status / Flags', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="9">' . \esc_html__('No guests matched the current filters.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            echo '<tr>';
            echo '<td><strong><a href="' . \esc_url((string) ($row['detail_url'] ?? '')) . '">' . \esc_html((string) ($row['name'] ?? '')) . '</a></strong><br /><span style="color:#646970;">' . \esc_html((string) ($row['email'] ?? '')) . '</span></td>';
            echo '<td>' . \esc_html((string) (($row['phone'] ?? '') !== '' ? $row['phone'] : '—')) . '</td>';
            echo '<td>' . \esc_html((string) (($row['country'] ?? '') !== '' ? $row['country'] : '—')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['total_reservations'] ?? 0)) . '</td>';
            echo '<td>' . \esc_html((string) (($row['upcoming_stay'] ?? '') !== '' ? $row['upcoming_stay'] : '—')) . '</td>';
            echo '<td>' . \esc_html((string) (($row['last_stay'] ?? '') !== '' ? $row['last_stay'] : '—')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['total_spend'] ?? '')) . '</td>';
            echo '<td>';
            foreach ((array) ($row['status_badges'] ?? []) as $badge) {
                echo '<span class="must-dashboard-status-badge is-compact must-reservation-badge is-info" style="margin-right:6px;">' . \esc_html((string) $badge) . '</span>';
            }

            if (!empty($row['warnings'])) {
                echo '<ul style="margin:8px 0 0 18px;">';
                foreach ((array) $row['warnings'] as $warning) {
                    echo '<li>' . \esc_html((string) $warning) . '</li>';
                }
                echo '</ul>';
            }
            echo '</td>';
            echo '<td><a class="button button-small" href="' . \esc_url((string) ($row['detail_url'] ?? '')) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small" href="' . \esc_url((string) ($row['reservations_url'] ?? '')) . '">' . \esc_html__('Reservations', 'must-hotel-booking') . '</a></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<string, mixed> $pagination
 * @param array<string, scalar|int|bool> $args
 */
function render_guests_pagination(array $pagination, array $args): void
{
    $totalPages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;

    if ($totalPages <= 1) {
        return;
    }

    $currentPage = isset($pagination['current_page']) ? (int) $pagination['current_page'] : 1;
    echo '<div class="tablenav"><div class="tablenav-pages">';
    echo \wp_kses_post(
        \paginate_links([
            'base' => \esc_url_raw(get_admin_guests_page_url(\array_merge($args, ['paged' => '%#%']))),
            'format' => '',
            'current' => $currentPage,
            'total' => $totalPages,
        ])
    );
    echo '</div></div>';
}

/**
 * @param array<string, mixed>|null $detail
 */
function render_guest_detail(?array $detail): void
{
    if (!\is_array($detail)) {
        return;
    }

    $guest = isset($detail['guest']) && \is_array($detail['guest']) ? $detail['guest'] : [];
    $form = isset($detail['form']) && \is_array($detail['form']) ? $detail['form'] : [];
    $summary = isset($detail['summary']) && \is_array($detail['summary']) ? $detail['summary'] : [];
    $paymentSummary = isset($detail['payment_summary']) && \is_array($detail['payment_summary']) ? $detail['payment_summary'] : [];
    $guestId = isset($guest['id']) ? (int) $guest['id'] : 0;

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Guest Detail', 'must-hotel-booking') . '</h2>';
    echo '<p>';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_reservations_page_url(['search' => (string) ($guest['email'] ?? get_admin_guest_full_name($guest))])) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_payments_page_url(['search' => (string) ($guest['email'] ?? get_admin_guest_full_name($guest))])) . '">' . \esc_html__('Open Payments', 'must-hotel-booking') . '</a> ';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_emails_page_url()) . '">' . \esc_html__('Open Emails', 'must-hotel-booking') . '</a>';
    echo '</p>';

    if (!empty($detail['warnings'])) {
        echo '<div class="notice notice-warning inline"><ul>';
        foreach ((array) $detail['warnings'] as $warning) {
            echo '<li>' . \esc_html((string) $warning) . '</li>';
        }
        echo '</ul></div>';
    }

    echo '<h3>' . \esc_html__('Summary', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped" style="margin-bottom:20px;"><tbody>';
    echo '<tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($summary['full_name'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Email', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($guest['email'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Phone', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($guest['phone'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Country', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($guest['country'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('First reservation', 'must-hotel-booking') . '</th><td>' . \esc_html((string) (($summary['first_reservation_at'] ?? '') !== '' ? $summary['first_reservation_at'] : '—')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Last stay', 'must-hotel-booking') . '</th><td>' . \esc_html((string) (($summary['last_stay_date'] ?? '') !== '' ? $summary['last_stay_date'] : '—')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Next upcoming stay', 'must-hotel-booking') . '</th><td>' . \esc_html((string) (($summary['next_checkin'] ?? '') !== '' ? ($summary['next_checkin'] . ' → ' . (string) ($summary['next_checkout'] ?? '')) : '—')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Currently in house', 'must-hotel-booking') . '</th><td>' . \esc_html(!empty($summary['currently_in_house']) ? __('Yes', 'must-hotel-booking') : __('No', 'must-hotel-booking')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Reservations / Completed / Cancellations', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($summary['total_reservations'] ?? 0) . ' / ' . (string) ($summary['total_completed_stays'] ?? 0) . ' / ' . (string) ($summary['total_cancellations'] ?? 0)) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Total spend', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($summary['total_spend'] ?? '')) . '</td></tr>';
    echo '</tbody></table>';

    echo '<h3>' . \esc_html__('Edit Guest', 'must-hotel-booking') . '</h3>';
    echo '<form method="post" action="' . \esc_url(get_admin_guests_page_url(['guest_id' => $guestId])) . '" style="margin-bottom:20px;">';
    \wp_nonce_field('must_guest_save', 'must_guest_nonce');
    echo '<input type="hidden" name="must_guest_action" value="save_guest" />';
    echo '<input type="hidden" name="guest_id" value="' . \esc_attr((string) $guestId) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-guest-first-name">' . \esc_html__('First name', 'must-hotel-booking') . '</label></th><td><input id="must-guest-first-name" class="regular-text" type="text" name="first_name" value="' . \esc_attr((string) ($form['first_name'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-last-name">' . \esc_html__('Last name', 'must-hotel-booking') . '</label></th><td><input id="must-guest-last-name" class="regular-text" type="text" name="last_name" value="' . \esc_attr((string) ($form['last_name'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-email">' . \esc_html__('Email', 'must-hotel-booking') . '</label></th><td><input id="must-guest-email" class="regular-text" type="email" name="email" value="' . \esc_attr((string) ($form['email'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-phone">' . \esc_html__('Phone', 'must-hotel-booking') . '</label></th><td><input id="must-guest-phone" class="regular-text" type="text" name="phone" value="' . \esc_attr((string) ($form['phone'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-country">' . \esc_html__('Country', 'must-hotel-booking') . '</label></th><td><input id="must-guest-country" class="regular-text" type="text" name="country" value="' . \esc_attr((string) ($form['country'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-guest-admin-notes">' . \esc_html__('Internal notes', 'must-hotel-booking') . '</label></th><td><textarea id="must-guest-admin-notes" class="large-text" rows="4" name="admin_notes">' . \esc_textarea((string) ($form['admin_notes'] ?? '')) . '</textarea></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Flags', 'must-hotel-booking') . '</th><td><label><input type="checkbox" name="vip_flag" value="1"' . \checked(!empty($form['vip_flag']), true, false) . ' /> ' . \esc_html__('VIP guest', 'must-hotel-booking') . '</label><br /><label><input type="checkbox" name="problem_flag" value="1"' . \checked(!empty($form['problem_flag']), true, false) . ' /> ' . \esc_html__('Problem guest', 'must-hotel-booking') . '</label></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Save Guest Profile', 'must-hotel-booking'));
    echo '</form>';

    echo '<h3>' . \esc_html__('Payment Summary', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped" style="margin-bottom:20px;"><tbody>';
    echo '<tr><th>' . \esc_html__('Total paid', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($paymentSummary['total_paid'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Amount due on upcoming reservations', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($paymentSummary['amount_due_upcoming'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Preferred payment method', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($paymentSummary['preferred_method'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Failed payment count', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($paymentSummary['failed_payment_count'] ?? 0)) . '</td></tr>';
    echo '</tbody></table>';

    echo '<h3>' . \esc_html__('Reservation History', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped" style="margin-bottom:20px;"><thead><tr>';
    echo '<th>' . \esc_html__('Booking ID', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-in', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Check-out', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reservation Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Payment Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Total', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ((array) ($detail['reservations'] ?? []) as $reservationRow) {
        if (!\is_array($reservationRow)) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . \esc_html((string) ($reservationRow['booking_id'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservationRow['accommodation'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservationRow['checkin'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservationRow['checkout'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservationRow['reservation_status'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservationRow['payment_status'] ?? '')) . '</td>';
        echo '<td>' . \esc_html((string) ($reservationRow['total'] ?? '')) . '</td>';
        echo '<td><a class="button button-small" href="' . \esc_url((string) ($reservationRow['detail_url'] ?? '')) . '">' . \esc_html__('Reservation', 'must-hotel-booking') . '</a> ';
        echo '<a class="button button-small" href="' . \esc_url((string) ($reservationRow['payment_url'] ?? '')) . '">' . \esc_html__('Payment', 'must-hotel-booking') . '</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<h3>' . \esc_html__('Communication / Email Summary', 'must-hotel-booking') . '</h3>';
    echo '<table class="widefat striped" style="margin-bottom:20px;"><thead><tr>';
    echo '<th>' . \esc_html__('When', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Template', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Recipient', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Message', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';
    if (empty($detail['emails'])) {
        echo '<tr><td colspan="5">' . \esc_html__('No recent guest email activity was found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ((array) $detail['emails'] as $emailRow) {
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
    }
    echo '</tbody></table>';

    echo '<h3>' . \esc_html__('Possible Duplicates', 'must-hotel-booking') . '</h3>';
    if (empty($detail['duplicates'])) {
        echo '<p>' . \esc_html__('No likely duplicate guest records were detected with the current identity rules.', 'must-hotel-booking') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Email', 'must-hotel-booking') . '</th><th>' . \esc_html__('Phone', 'must-hotel-booking') . '</th><th>' . \esc_html__('Action', 'must-hotel-booking') . '</th></tr></thead><tbody>';
        foreach ((array) $detail['duplicates'] as $duplicateRow) {
            if (!\is_array($duplicateRow)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($duplicateRow['name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($duplicateRow['email'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($duplicateRow['phone'] ?? '')) . '</td>';
            echo '<td><a class="button button-small" href="' . \esc_url((string) ($duplicateRow['detail_url'] ?? '')) . '">' . \esc_html__('Open Guest', 'must-hotel-booking') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}

function render_admin_guests_page(): void
{
    ensure_admin_capability();

    $query = GuestAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $pageData = (new GuestAdminDataProvider())->getPageData($query, get_guests_admin_save_state());
    clear_guests_admin_save_state();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Guests', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Review guest identity, stay history, payment context, and recent communication from one operational screen linked to reservations, payments, and emails.', 'must-hotel-booking') . '</p>';

    render_guests_admin_notice_from_query();
    render_guests_error_notice(isset($pageData['errors']) && \is_array($pageData['errors']) ? $pageData['errors'] : []);
    render_guests_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    render_guests_filters(
        isset($pageData['filters']) && \is_array($pageData['filters']) ? $pageData['filters'] : [],
        isset($pageData['country_options']) && \is_array($pageData['country_options']) ? $pageData['country_options'] : []
    );
    render_guests_table(isset($pageData['rows']) && \is_array($pageData['rows']) ? $pageData['rows'] : []);
    render_guests_pagination(
        isset($pageData['pagination']) && \is_array($pageData['pagination']) ? $pageData['pagination'] : [],
        $query->buildUrlArgs()
    );
    render_guest_detail(isset($pageData['detail']) && \is_array($pageData['detail']) ? $pageData['detail'] : null);
    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_guests_admin_actions_early', 1);
