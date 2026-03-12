<?php

if (!\defined('ABSPATH')) {
    exit;
}

$view = \must_hotel_booking\get_confirmation_page_view_data();
$success = !empty($view['success']);
$is_form_mode = !empty($view['is_form_mode']);
$can_confirm = !empty($view['can_confirm']);
$messages = isset($view['messages']) && \is_array($view['messages']) ? $view['messages'] : [];
$reservations = isset($view['reservations']) && \is_array($view['reservations']) ? $view['reservations'] : [];
$selected_rooms = isset($view['selected_rooms']) && \is_array($view['selected_rooms']) ? $view['selected_rooms'] : [];
$summary = isset($view['summary']) && \is_array($view['summary']) ? $view['summary'] : [];
$billing_form = isset($view['billing_form']) && \is_array($view['billing_form']) ? $view['billing_form'] : [];
$primary_guest = isset($view['primary_guest']) && \is_array($view['primary_guest']) ? $view['primary_guest'] : null;
$total_price = isset($view['total_price']) ? (float) $view['total_price'] : 0.0;
$booking_url = isset($view['booking_url']) ? (string) $view['booking_url'] : \home_url('/booking');
$checkout_url = isset($view['checkout_url']) ? (string) $view['checkout_url'] : \home_url('/checkout');
$confirmation_url = isset($view['confirmation_url']) ? (string) $view['confirmation_url'] : \home_url('/booking-confirmation');
$coupon_input_value = isset($view['coupon_input_value']) ? (string) $view['coupon_input_value'] : '';
$applied_coupon_code = isset($view['applied_coupon_code']) ? (string) $view['applied_coupon_code'] : '';
$country_options = isset($view['country_options']) && \is_array($view['country_options']) ? $view['country_options'] : [];
$phone_country_code_options = isset($view['phone_country_code_options']) && \is_array($view['phone_country_code_options']) ? $view['phone_country_code_options'] : [];
$arrow_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowRight.svg' : '';
$back_arrow_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowLEFT.svg' : '';
$dropdown_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/poludown.svg' : '';
$check_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/check.svg' : '';
$summary_currency = 'USD';
$fees_total = isset($summary['fees_total']) ? (float) $summary['fees_total'] : 0.0;
$discount_total = isset($summary['discount_total']) ? (float) $summary['discount_total'] : 0.0;
$room_subtotal = isset($summary['room_subtotal']) ? (float) $summary['room_subtotal'] : 0.0;
$subtotal_before_taxes = $room_subtotal + $fees_total - $discount_total;

if (!empty($selected_rooms[0]['room']) && \is_array($selected_rooms[0]['room']) && !empty($selected_rooms[0]['room']['currency'])) {
    $summary_currency = (string) $selected_rooms[0]['room']['currency'];
} elseif (!empty($reservations[0]['currency'])) {
    $summary_currency = (string) $reservations[0]['currency'];
}

$format_money = static function (float $amount, string $currency = 'USD'): string {
    return \must_hotel_booking\format_frontend_money($amount, $currency);
};
?>
<?php \get_header(); ?>
<main class="must-hotel-booking-page must-hotel-booking-page-booking-confirmation must-booking-process-page">
    <div class="must-hotel-booking-container">
        <section class="must-booking-step-header">
            <h1><?php echo \esc_html__('Confirm Your Stay', 'must-hotel-booking'); ?></h1>

            <div class="must-booking-stepper-wrap" aria-label="<?php echo \esc_attr__('Booking steps', 'must-hotel-booking'); ?>">
                <div class="must-booking-stepper">
                    <a href="<?php echo \esc_url($checkout_url); ?>" class="must-booking-stepper-nav is-back">
                        <?php if ($back_arrow_icon_url !== '') : ?>
                            <img src="<?php echo \esc_url($back_arrow_icon_url); ?>" alt="" aria-hidden="true" />
                        <?php endif; ?>
                        <span><?php echo \esc_html__('Back', 'must-hotel-booking'); ?></span>
                    </a>
                    <a href="<?php echo \esc_url($booking_url); ?>" class="must-booking-stepper-step is-link" data-step="1"><?php echo \esc_html__('Calendar', 'must-hotel-booking'); ?></a>
                    <a href="<?php echo \esc_url($checkout_url); ?>" class="must-booking-stepper-step is-link" data-step="2"><?php echo \esc_html__('Guest Information', 'must-hotel-booking'); ?></a>
                    <span class="must-booking-stepper-step is-active" data-step="3"><?php echo \esc_html__('Confirmation', 'must-hotel-booking'); ?></span>
                    <span class="must-booking-stepper-nav is-next" aria-disabled="true">
                        <span><?php echo \esc_html__('Next', 'must-hotel-booking'); ?></span>
                        <?php if ($arrow_icon_url !== '') : ?>
                            <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </section>

        <?php if (!empty($messages)) : ?>
            <div class="must-hotel-booking-messages">
                <?php foreach ($messages as $message) : ?>
                    <p><?php echo \esc_html((string) $message); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success && !empty($reservations)) : ?>
            <section class="must-confirmation-success">
                <div class="must-confirmation-success-head">
                    <h2><?php echo \esc_html__('Booking Confirmed', 'must-hotel-booking'); ?></h2>
                    <strong><?php echo \esc_html($format_money($total_price, $summary_currency)); ?></strong>
                </div>

                <?php foreach ($reservations as $reservation) : ?>
                    <div class="must-confirmation-success-card">
                        <p><strong><?php echo \esc_html((string) ($reservation['room_name'] ?? __('Room', 'must-hotel-booking'))); ?></strong></p>
                        <?php if (!empty($reservation['booking_id'])) : ?>
                            <p><?php echo \esc_html(\sprintf(__('Booking ID: %s', 'must-hotel-booking'), (string) $reservation['booking_id'])); ?></p>
                        <?php endif; ?>
                        <p><?php echo \esc_html(\sprintf(__('Stay: %1$s to %2$s', 'must-hotel-booking'), (string) $reservation['checkin'], (string) $reservation['checkout'])); ?></p>
                        <p><?php echo \esc_html(\sprintf(__('Guests: %d', 'must-hotel-booking'), (int) $reservation['guests'])); ?></p>
                        <p><?php echo \esc_html(\sprintf(__('Room Total: %s', 'must-hotel-booking'), $format_money((float) $reservation['total_price'], $summary_currency))); ?></p>
                    </div>
                <?php endforeach; ?>

                <?php if (\is_array($primary_guest)) : ?>
                    <div class="must-confirmation-success-guest">
                        <h3><?php echo \esc_html__('Guest Details', 'must-hotel-booking'); ?></h3>
                        <p><?php echo \esc_html(\trim((string) $primary_guest['first_name'] . ' ' . (string) $primary_guest['last_name'])); ?></p>
                        <p><?php echo \esc_html((string) $primary_guest['email']); ?></p>
                        <p><?php echo \esc_html((string) $primary_guest['phone']); ?></p>
                        <p><?php echo \esc_html((string) $primary_guest['country']); ?></p>
                    </div>
                <?php endif; ?>
            </section>
        <?php elseif ($is_form_mode) : ?>
            <form method="post" action="<?php echo \esc_url($confirmation_url); ?>" class="must-confirmation-form">
                <?php \wp_nonce_field('must_confirm_booking', 'must_confirmation_nonce'); ?>
                <input type="hidden" name="applied_coupon_code" value="<?php echo \esc_attr($applied_coupon_code); ?>" />

                <section class="must-confirmation-coupon-row">
                    <div class="must-confirmation-coupon-control">
                        <label class="screen-reader-text" for="must-confirmation-coupon"><?php echo \esc_html__('Coupon Code', 'must-hotel-booking'); ?></label>
                        <input
                            id="must-confirmation-coupon"
                            type="text"
                            name="coupon_code"
                            value="<?php echo \esc_attr($coupon_input_value); ?>"
                            placeholder="<?php echo \esc_attr__('Add Coupon', 'must-hotel-booking'); ?>"
                        />
                        <button
                            type="submit"
                            name="must_confirmation_action"
                            value="preview_coupon"
                            class="must-confirmation-coupon-submit"
                            formnovalidate
                            aria-label="<?php echo \esc_attr__('Apply Coupon', 'must-hotel-booking'); ?>"
                        >
                            <?php if ($check_icon_url !== '') : ?>
                                <img src="<?php echo \esc_url($check_icon_url); ?>" alt="" aria-hidden="true" />
                            <?php endif; ?>
                            <span class="screen-reader-text"><?php echo \esc_html__('Apply Coupon', 'must-hotel-booking'); ?></span>
                        </button>
                    </div>
                </section>

                <?php if ($can_confirm) : ?>
                    <div class="must-confirmation-layout">
                        <section class="must-confirmation-billing-panel">
                            <div class="must-confirmation-billing-inner">
                                <div class="must-confirmation-billing-section">
                                    <h2><?php echo \esc_html__('Billing Information', 'must-hotel-booking'); ?></h2>

                                    <div class="must-confirmation-form-grid">
                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('First Name', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="first_name" value="<?php echo \esc_attr((string) ($billing_form['first_name'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('First Name', 'must-hotel-booking'); ?>" required />
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Last Name', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="last_name" value="<?php echo \esc_attr((string) ($billing_form['last_name'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Last Name', 'must-hotel-booking'); ?>" required />
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Company Name (Optional)', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="company" value="<?php echo \esc_attr((string) ($billing_form['company'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Company Name (Optional)', 'must-hotel-booking'); ?>" />
                                        </label>

                                        <label class="must-confirmation-field must-confirmation-field-select">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Country of Residence', 'must-hotel-booking'); ?></span>
                                            <select name="country">
                                                <option value=""><?php echo \esc_html__('Country of Residence', 'must-hotel-booking'); ?></option>
                                                <?php foreach ($country_options as $country_option) : ?>
                                                    <?php
                                                    $country_option_value = isset($country_option['value']) ? (string) $country_option['value'] : '';
                                                    $country_option_label = isset($country_option['label']) ? (string) $country_option['label'] : '';
                                                    ?>
                                                    <option value="<?php echo \esc_attr($country_option_value); ?>"<?php selected($country_option_value, (string) ($billing_form['country'] ?? '')); ?>>
                                                        <?php echo \esc_html($country_option_label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($dropdown_icon_url !== '') : ?>
                                                <img src="<?php echo \esc_url($dropdown_icon_url); ?>" alt="" aria-hidden="true" />
                                            <?php endif; ?>
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Street Address', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="street_address" value="<?php echo \esc_attr((string) ($billing_form['street_address'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Street Address*', 'must-hotel-booking'); ?>" required />
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Apartment, Suite, Unit, etc.', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="address_line_2" value="<?php echo \esc_attr((string) ($billing_form['address_line_2'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Apartment, Suite, Unit, etc (Optional)', 'must-hotel-booking'); ?>" />
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Town / City', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="city" value="<?php echo \esc_attr((string) ($billing_form['city'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Town / City*', 'must-hotel-booking'); ?>" required />
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('County', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="county" value="<?php echo \esc_attr((string) ($billing_form['county'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('County*', 'must-hotel-booking'); ?>" required />
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Postcode / ZIP', 'must-hotel-booking'); ?></span>
                                            <input type="text" name="postcode" value="<?php echo \esc_attr((string) ($billing_form['postcode'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Postcode / ZIP*', 'must-hotel-booking'); ?>" required />
                                        </label>

                                        <label class="must-confirmation-field must-confirmation-field-phone">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Phone Number', 'must-hotel-booking'); ?></span>
                                            <span class="must-confirmation-phone-shell">
                                                <select name="phone_country_code" aria-label="<?php echo \esc_attr__('Phone country code', 'must-hotel-booking'); ?>">
                                                    <?php foreach ($phone_country_code_options as $phone_country_code_option) : ?>
                                                        <?php
                                                        $phone_option_value = isset($phone_country_code_option['value']) ? (string) $phone_country_code_option['value'] : '';
                                                        $phone_option_label = isset($phone_country_code_option['label']) ? (string) $phone_country_code_option['label'] : '';
                                                        ?>
                                                        <option value="<?php echo \esc_attr($phone_option_value); ?>"<?php selected($phone_option_value, (string) ($billing_form['phone_country_code'] ?? '')); ?>>
                                                            <?php echo \esc_html($phone_option_label); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <span class="must-confirmation-phone-divider" aria-hidden="true">|</span>
                                                <input type="text" name="phone_number" value="<?php echo \esc_attr((string) ($billing_form['phone_number'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Phone Number', 'must-hotel-booking'); ?>" />
                                            </span>
                                        </label>

                                        <label class="must-confirmation-field">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Email Address', 'must-hotel-booking'); ?></span>
                                            <input type="email" name="email" value="<?php echo \esc_attr((string) ($billing_form['email'] ?? '')); ?>" placeholder="<?php echo \esc_attr__('Email Address*', 'must-hotel-booking'); ?>" required />
                                        </label>

                                        <label class="must-confirmation-field must-confirmation-field-select">
                                            <span class="screen-reader-text"><?php echo \esc_html__('Country of Residence', 'must-hotel-booking'); ?></span>
                                            <select name="billing_country">
                                                <option value=""><?php echo \esc_html__('Country of Residence', 'must-hotel-booking'); ?></option>
                                                <?php foreach ($country_options as $country_option) : ?>
                                                    <?php
                                                    $country_option_value = isset($country_option['value']) ? (string) $country_option['value'] : '';
                                                    $country_option_label = isset($country_option['label']) ? (string) $country_option['label'] : '';
                                                    ?>
                                                    <option value="<?php echo \esc_attr($country_option_value); ?>"<?php selected($country_option_value, (string) ($billing_form['billing_country'] ?? '')); ?>>
                                                        <?php echo \esc_html($country_option_label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <?php if ($dropdown_icon_url !== '') : ?>
                                                <img src="<?php echo \esc_url($dropdown_icon_url); ?>" alt="" aria-hidden="true" />
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>

                                <div class="must-confirmation-special-section">
                                    <label class="must-confirmation-field must-confirmation-field-textarea">
                                        <span class="screen-reader-text"><?php echo \esc_html__('Special Requests', 'must-hotel-booking'); ?></span>
                                        <textarea name="special_requests" rows="4" placeholder="<?php echo \esc_attr__('Special Requests', 'must-hotel-booking'); ?>"><?php echo \esc_textarea((string) ($billing_form['special_requests'] ?? '')); ?></textarea>
                                    </label>
                                </div>
                            </div>
                        </section>

                        <aside class="must-confirmation-side">
                            <section class="must-confirmation-order-table">
                                <div class="must-confirmation-order-head">
                                    <span><?php echo \esc_html__('Product', 'must-hotel-booking'); ?></span>
                                    <span><?php echo \esc_html__('Subtotal', 'must-hotel-booking'); ?></span>
                                </div>

                                <?php foreach ($selected_rooms as $selected_room_item) : ?>
                                    <?php
                                    $room = isset($selected_room_item['room']) && \is_array($selected_room_item['room']) ? $selected_room_item['room'] : [];
                                    $pricing = isset($selected_room_item['pricing']) && \is_array($selected_room_item['pricing']) ? $selected_room_item['pricing'] : [];
                                    $room_name = isset($room['name']) ? (string) $room['name'] : \__('Room', 'must-hotel-booking');
                                    $room_currency = isset($room['currency']) ? (string) $room['currency'] : $summary_currency;
                                    $room_total = isset($pricing['total_price']) ? (float) $pricing['total_price'] : (isset($room['dynamic_total_price']) ? (float) $room['dynamic_total_price'] : 0.0);
                                    ?>
                                    <div class="must-confirmation-order-row">
                                        <span><?php echo \esc_html($room_name); ?></span>
                                        <span><?php echo \esc_html($format_money($room_total, $room_currency)); ?></span>
                                    </div>
                                <?php endforeach; ?>

                                <div class="must-confirmation-order-row">
                                    <span><?php echo \esc_html__('Subtotal', 'must-hotel-booking'); ?></span>
                                    <span><?php echo \esc_html($format_money($subtotal_before_taxes, $summary_currency)); ?></span>
                                </div>

                                <?php if ($discount_total > 0.0) : ?>
                                    <div class="must-confirmation-order-row">
                                        <span>
                                            <?php
                                            echo \esc_html(
                                                $applied_coupon_code !== ''
                                                    ? \sprintf(__('Discount (%s)', 'must-hotel-booking'), \strtoupper($applied_coupon_code))
                                                    : __('Discount Used', 'must-hotel-booking')
                                            );
                                            ?>
                                        </span>
                                        <span><?php echo \esc_html('-' . $format_money($discount_total, $summary_currency)); ?></span>
                                    </div>
                                <?php endif; ?>

                                <div class="must-confirmation-order-row is-total">
                                    <span><?php echo \esc_html__('Total', 'must-hotel-booking'); ?></span>
                                    <span><?php echo \esc_html($format_money($total_price, $summary_currency)); ?></span>
                                </div>
                            </section>

                            <section class="must-confirmation-payment">
                                <h2><?php echo \esc_html__('VPOS SIA - Pay with Cards', 'must-hotel-booking'); ?></h2>
                                <div class="must-confirmation-payment-box">
                                    <p><?php echo \esc_html__('For fast and safe online payments with VISA and Mastercard cards issued by other banking institutions.', 'must-hotel-booking'); ?></p>
                                </div>
                                <p class="must-confirmation-policy"><?php echo \esc_html__('Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our privacy policy.', 'must-hotel-booking'); ?></p>

                                <button type="submit" name="must_confirmation_action" value="confirm_booking" class="must-confirmation-submit">
                                    <span><?php echo \esc_html__('Confirm your Stay', 'must-hotel-booking'); ?></span>
                                    <?php if ($arrow_icon_url !== '') : ?>
                                        <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                                    <?php endif; ?>
                                </button>
                            </section>
                        </aside>
                    </div>
                <?php else : ?>
                    <p><a href="<?php echo \esc_url($checkout_url); ?>"><?php echo \esc_html__('Back to Guest Information', 'must-hotel-booking'); ?></a></p>
                <?php endif; ?>
            </form>
        <?php else : ?>
            <p><?php echo \esc_html((string) ($view['message'] ?? __('Reservation details are unavailable.', 'must-hotel-booking'))); ?></p>
        <?php endif; ?>
    </div>
</main>
<?php \get_footer(); ?>
