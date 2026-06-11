<?php

use MustHotelBooking\Portal\PortalRouter;

$form = isset($moduleData['form']) && \is_array($moduleData['form']) ? $moduleData['form'] : [];
$roomOptions = isset($moduleData['room_options']) && \is_array($moduleData['room_options']) ? $moduleData['room_options'] : [];
$sourceOptions = isset($moduleData['source_options']) && \is_array($moduleData['source_options']) ? $moduleData['source_options'] : [];
$currency = isset($moduleData['currency']) ? (string) $moduleData['currency'] : '';
$formTitle = isset($moduleData['form_title']) ? (string) $moduleData['form_title'] : \__('New Booking', 'must-hotel-booking');
$formDescription = isset($moduleData['form_description'])
    ? (string) $moduleData['form_description']
    : \__('Search available physical rooms, choose one, then send the guest to online payment.', 'must-hotel-booking');

$formAction = PortalRouter::getModuleUrl('front_desk', ['tab' => isset($activeTab) ? (string) $activeTab : 'new-booking']);
$today = \current_datetime()->format('Y-m-d');
$tomorrow = \current_datetime()->modify('+1 day')->format('Y-m-d');

$defaultCheckin = isset($form['checkin']) && (string) $form['checkin'] !== '' ? (string) $form['checkin'] : $today;
$defaultCheckout = isset($form['checkout']) && (string) $form['checkout'] !== '' ? (string) $form['checkout'] : $tomorrow;
$defaultGuests = isset($form['guests']) ? \max(1, (int) $form['guests']) : 1;
$portalPayment = isset($_GET['portal_payment']) ? \sanitize_key((string) \wp_unslash($_GET['portal_payment'])) : '';
$portalReservationIds = isset($_GET['reservation_ids'])
    ? \array_values(\array_filter(\array_map('absint', \explode(',', (string) \wp_unslash($_GET['reservation_ids'])))))
    : [];
$firstPortalReservationId = isset($portalReservationIds[0]) ? (int) $portalReservationIds[0] : 0;

echo '<section class="must-portal-panel must-portal-quick-booking-app" data-must-portal-quick-booking-app data-available-nonce="' . \esc_attr(\wp_create_nonce('must_portal_quick_booking_available_rooms')) . '">';

echo '<div class="must-portal-panel-header"><div><h2>' . \esc_html($formTitle) . '</h2><p>' . \esc_html($formDescription) . '</p></div></div>';
if ($portalPayment === 'success' && $firstPortalReservationId > 0) {
    $viewUrl = PortalRouter::getModuleUrl('reservations', ['reservation_id' => $firstPortalReservationId]);
    $cleanUrl = PortalRouter::getModuleUrl('front_desk', ['tab' => 'new-booking']);

    echo '<div class="must-portal-booking-modal" data-must-portal-success-modal>';
    echo '<div class="must-portal-booking-modal-backdrop" data-must-portal-success-close data-clean-url="' . \esc_url($cleanUrl) . '"></div>';
    echo '<div class="must-portal-booking-modal-card">';
    echo '<div class="must-portal-booking-modal-header">';
    echo '<div>';
    echo '<h3>' . \esc_html__('Booking successful', 'must-hotel-booking') . '</h3>';
    echo '<p>' . \esc_html__('Online payment was completed and the reservation was created successfully.', 'must-hotel-booking') . '</p>';
    echo '</div>';
    echo '<button type="button" class="must-portal-modal-close" data-must-portal-success-close data-clean-url="' . \esc_url($cleanUrl) . '">&times;</button>';
    echo '</div>';

    echo '<div class="must-portal-inline-actions">';
    echo '<a class="must-portal-primary-button" href="' . \esc_url($viewUrl) . '">' . \esc_html__('View booking', 'must-hotel-booking') . '</a>';
    echo '<button type="button" class="must-portal-secondary-button" data-must-portal-success-close data-clean-url="' . \esc_url($cleanUrl) . '">' . \esc_html__("That's all", 'must-hotel-booking') . '</button>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
} elseif ($portalPayment === 'cancel') {
    echo '<div class="must-portal-notice must-portal-notice-warning">';
    echo \esc_html__('Online payment was canceled. You can search again or start another booking.', 'must-hotel-booking');
    echo '</div>';
}
echo '<div class="must-portal-booking-search">';
echo '<label><span>' . \esc_html__('Room type', 'must-hotel-booking') . '</span><select data-must-portal-room-type-filter>';
echo '<option value="0">' . \esc_html__('All room types', 'must-hotel-booking') . '</option>';

foreach ($roomOptions as $room) {
    if (!\is_array($room)) {
        continue;
    }

    $roomId = (int) ($room['id'] ?? 0);
    $roomLabel = (string) ($room['name'] ?? ('#' . $roomId));

    if ($roomId <= 0) {
        continue;
    }

    echo '<option value="' . \esc_attr((string) $roomId) . '">' . \esc_html($roomLabel) . '</option>';
}

echo '</select></label>';
echo '<label><span>' . \esc_html__('Check-in', 'must-hotel-booking') . '</span><input type="text" value="' . \esc_attr($defaultCheckin) . '" autocomplete="off" data-must-portal-search-checkin /></label>';
echo '<label><span>' . \esc_html__('Check-out', 'must-hotel-booking') . '</span><input type="text" value="' . \esc_attr($defaultCheckout) . '" autocomplete="off" data-must-portal-search-checkout /></label>';
echo '<label><span>' . \esc_html__('Guests', 'must-hotel-booking') . '</span><input type="number" min="1" value="' . \esc_attr((string) $defaultGuests) . '" data-must-portal-search-guests /></label>';
echo '<button type="button" class="must-portal-primary-button" data-must-portal-search-button>' . \esc_html__('Search available rooms', 'must-hotel-booking') . '</button>';
echo '</div>';

echo '<p class="must-portal-date-status" data-must-portal-search-status></p>';

echo '<div class="must-portal-room-results-wrap">';
echo '<table class="must-portal-room-results-table">';
echo '<thead><tr>';
echo '<th>' . \esc_html__('Room', 'must-hotel-booking') . '</th>';
echo '<th>' . \esc_html__('Type', 'must-hotel-booking') . '</th>';
echo '<th>' . \esc_html__('Max guests', 'must-hotel-booking') . '</th>';
echo '<th>' . \esc_html__('Total', 'must-hotel-booking') . '</th>';
echo '<th>' . \esc_html__('Action', 'must-hotel-booking') . '</th>';
echo '</tr></thead>';
echo '<tbody data-must-portal-room-results>';
echo '<tr><td colspan="5">' . \esc_html__('Click search to load available rooms.', 'must-hotel-booking') . '</td></tr>';
echo '</tbody>';
echo '</table>';
echo '</div>';

echo '<div class="must-portal-booking-modal" data-must-portal-booking-modal hidden>';
echo '<div class="must-portal-booking-modal-backdrop" data-must-portal-modal-close></div>';
echo '<div class="must-portal-booking-modal-card">';
echo '<div class="must-portal-booking-modal-header">';
echo '<div><h3>' . \esc_html__('Guest details', 'must-hotel-booking') . '</h3><p data-must-portal-modal-room-label></p></div>';
echo '<button type="button" class="must-portal-modal-close" data-must-portal-modal-close>&times;</button>';
echo '</div>';

echo '<form method="post" action="' . \esc_url($formAction) . '" class="must-portal-form-grid must-portal-quick-booking-form">';

\wp_nonce_field('must_portal_quick_booking', 'must_portal_quick_booking_nonce');
echo '<input type="hidden" name="must_portal_action" value="quick_booking_create" />';
echo '<input type="hidden" name="room_id" value="0" data-must-portal-modal-room-id />';
echo '<input type="hidden" name="checkin" value="' . \esc_attr($defaultCheckin) . '" data-must-portal-checkin data-must-portal-modal-checkin />';
echo '<input type="hidden" name="checkout" value="' . \esc_attr($defaultCheckout) . '" data-must-portal-checkout data-must-portal-modal-checkout />';
echo '<input type="hidden" name="guests" value="' . \esc_attr((string) $defaultGuests) . '" data-must-portal-modal-guests />';

echo '<label><span>' . \esc_html__('Guest name', 'must-hotel-booking') . '</span><input type="text" name="guest_name" value="' . \esc_attr((string) ($form['guest_name'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('Email', 'must-hotel-booking') . '</span><input type="email" name="email" value="' . \esc_attr((string) ($form['email'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('Phone', 'must-hotel-booking') . '</span><input type="text" name="phone" value="' . \esc_attr((string) ($form['phone'] ?? '')) . '" required /></label>';
$countryOptions = \function_exists('MustHotelBooking\\Frontend\\get_checkout_country_options')
    ? \MustHotelBooking\Frontend\get_checkout_country_options()
    : ['AL' => \__('Albania', 'must-hotel-booking')];

$currentCountry = isset($form['country']) && (string) $form['country'] !== ''
    ? (string) $form['country']
    : 'AL';

echo '<label><span>' . \esc_html__('Country', 'must-hotel-booking') . '</span><select name="country" required>';

foreach ($countryOptions as $countryKey => $countryOption) {
    if (\is_array($countryOption)) {
        $countryCode = (string) (
            $countryOption['code']
            ?? $countryOption['value']
            ?? $countryOption['country_code']
            ?? $countryKey
        );

        $countryLabel = (string) (
            $countryOption['label']
            ?? $countryOption['name']
            ?? $countryOption['country']
            ?? $countryCode
        );
    } else {
        $countryCode = (string) $countryKey;
        $countryLabel = (string) $countryOption;
    }

    if ($countryCode === '') {
        continue;
    }

    echo '<option value="' . \esc_attr($countryCode) . '"' . \selected($currentCountry, $countryCode, false) . '>' . \esc_html($countryLabel) . '</option>';
}

echo '</select></label>';
echo '<label><span>' . \esc_html__('Address', 'must-hotel-booking') . '</span><input type="text" name="street_address" value="' . \esc_attr((string) ($form['street_address'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('City', 'must-hotel-booking') . '</span><input type="text" name="city" value="' . \esc_attr((string) ($form['city'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('Postcode / ZIP', 'must-hotel-booking') . '</span><input type="text" name="postcode" value="' . \esc_attr((string) ($form['postcode'] ?? '')) . '" required /></label>';

echo '<label><span>' . \esc_html__('Booking source', 'must-hotel-booking') . '</span><select name="booking_source">';
foreach ($sourceOptions as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($form['booking_source'] ?? 'staff_portal'), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}
echo '</select></label>';

echo '<label class="must-portal-form-full"><span>' . \esc_html__('Notes', 'must-hotel-booking') . '</span><textarea name="notes" rows="4">' . \esc_textarea((string) ($form['notes'] ?? '')) . '</textarea></label>';

echo '<p class="must-portal-date-status must-portal-form-full" data-must-portal-modal-status></p>';
echo '<div class="must-portal-form-full must-portal-inline-actions">';
echo '<button type="submit" class="must-portal-primary-button">' . \esc_html__('Continue to online payment', 'must-hotel-booking') . '</button>';
echo '<button type="button" class="must-portal-secondary-button" data-must-portal-modal-close>' . \esc_html__('Cancel', 'must-hotel-booking') . '</button>';
echo '</div>';

echo '</form>';
echo '</div>';
echo '</div>';

echo '</section>';
