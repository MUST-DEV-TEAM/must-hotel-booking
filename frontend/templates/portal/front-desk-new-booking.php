<?php

use MustHotelBooking\Portal\PortalRouter;

$form = isset($moduleData['form']) && \is_array($moduleData['form']) ? $moduleData['form'] : [];
$roomOptions = isset($moduleData['room_options']) && \is_array($moduleData['room_options']) ? $moduleData['room_options'] : [];
$sourceOptions = isset($moduleData['source_options']) && \is_array($moduleData['source_options']) ? $moduleData['source_options'] : [];
$estimate = isset($moduleData['estimate']) ? (float) $moduleData['estimate'] : 0.0;
$currency = isset($moduleData['currency']) ? (string) $moduleData['currency'] : '';
$formTitle = isset($moduleData['form_title']) ? (string) $moduleData['form_title'] : \__('New Booking', 'must-hotel-booking');
$formDescription = isset($moduleData['form_description']) ? (string) $moduleData['form_description'] : \__('Create a confirmed reservation from the Front Desk workspace without leaving the portal.', 'must-hotel-booking');
$submitLabel = isset($moduleData['submit_label']) ? (string) $moduleData['submit_label'] : \__('Create reservation', 'must-hotel-booking');
$formAction = PortalRouter::getModuleUrl('front_desk', ['tab' => isset($activeTab) ? (string) $activeTab : 'new-booking']);

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html($formTitle) . '</h2><p>' . \esc_html($formDescription) . '</p></div></div>';
echo '<form method="post" action="' . \esc_url($formAction) . '" class="must-portal-form-grid">';
\wp_nonce_field('must_portal_quick_booking', 'must_portal_quick_booking_nonce');
echo '<input type="hidden" name="must_portal_action" value="quick_booking_create" />';

echo '<label><span>' . \esc_html__('Room', 'must-hotel-booking') . '</span><select name="room_id"><option value="0">' . \esc_html__('Select a room', 'must-hotel-booking') . '</option>';

foreach ($roomOptions as $room) {
    if (!\is_array($room)) {
        continue;
    }

    $roomId = (int) ($room['id'] ?? 0);
    $roomLabel = (string) ($room['name'] ?? ('#' . $roomId));
    echo '<option value="' . \esc_attr((string) $roomId) . '"' . \selected((int) ($form['room_id'] ?? 0), $roomId, false) . '>' . \esc_html($roomLabel) . '</option>';
}

echo '</select></label>';
echo '<label><span>' . \esc_html__('Check-in', 'must-hotel-booking') . '</span><input type="date" name="checkin" value="' . \esc_attr((string) ($form['checkin'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('Check-out', 'must-hotel-booking') . '</span><input type="date" name="checkout" value="' . \esc_attr((string) ($form['checkout'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('Guests', 'must-hotel-booking') . '</span><input type="number" min="1" name="guests" value="' . \esc_attr((string) ((int) ($form['guests'] ?? 1))) . '" required /></label>';
echo '<label><span>' . \esc_html__('Guest name', 'must-hotel-booking') . '</span><input type="text" name="guest_name" value="' . \esc_attr((string) ($form['guest_name'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('Phone', 'must-hotel-booking') . '</span><input type="text" name="phone" value="' . \esc_attr((string) ($form['phone'] ?? '')) . '" /></label>';
echo '<label><span>' . \esc_html__('Email', 'must-hotel-booking') . '</span><input type="email" name="email" value="' . \esc_attr((string) ($form['email'] ?? '')) . '" required /></label>';
echo '<label><span>' . \esc_html__('Booking source', 'must-hotel-booking') . '</span><select name="booking_source">';

foreach ($sourceOptions as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($form['booking_source'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select></label>';
echo '<label class="must-portal-form-full"><span>' . \esc_html__('Notes', 'must-hotel-booking') . '</span><textarea name="notes" rows="4">' . \esc_textarea((string) ($form['notes'] ?? '')) . '</textarea></label>';
echo '<div class="must-portal-form-full must-portal-inline-actions"><div class="must-portal-estimate"><strong>' . \esc_html__('Estimated total', 'must-hotel-booking') . '</strong><span>' . \esc_html(\number_format_i18n($estimate, 2) . ' ' . $currency) . '</span></div><button type="submit" class="must-portal-primary-button">' . \esc_html($submitLabel) . '</button></div>';
echo '</form></section>';
