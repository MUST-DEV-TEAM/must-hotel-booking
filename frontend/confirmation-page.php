<?php

namespace must_hotel_booking;

/**
 * Build reservation details query.
 *
 * @param string            $where_sql
 * @param array<int, mixed> $where_values
 * @return array<int, array<string, mixed>>
 */
function get_reservation_confirmation_rows(string $where_sql, array $where_values): array
{
    global $wpdb;

    $reservations_table = $wpdb->prefix . 'must_reservations';
    $rooms_table = $wpdb->prefix . 'must_rooms';
    $guests_table = $wpdb->prefix . 'must_guests';

    $sql = "SELECT
            r.id,
            r.booking_id,
            r.room_id,
            r.guest_id,
            r.checkin,
            r.checkout,
            r.guests,
            r.status,
            r.total_price,
            r.payment_status,
            r.created_at,
            rm.name AS room_name,
            g.first_name,
            g.last_name,
            g.email,
            g.phone,
            g.country
        FROM {$reservations_table} r
        LEFT JOIN {$rooms_table} rm ON rm.id = r.room_id
        LEFT JOIN {$guests_table} g ON g.id = r.guest_id
        WHERE {$where_sql}
        ORDER BY r.id ASC";

    if (!empty($where_values)) {
        $sql = $wpdb->prepare($sql, ...$where_values);
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Parse reservation ids from query string.
 *
 * @return array<int, int>
 */
function get_confirmation_reservation_ids_from_query(): array
{
    $raw_ids = isset($_GET['reservation_ids']) ? (string) \wp_unslash($_GET['reservation_ids']) : '';
    $parts = \array_filter(\array_map('trim', \explode(',', $raw_ids)));
    $ids = [];

    foreach ($parts as $part) {
        $reservation_id = \absint($part);

        if ($reservation_id > 0) {
            $ids[$reservation_id] = $reservation_id;
        }
    }

    if (empty($ids)) {
        $single_id = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;

        if ($single_id > 0) {
            $ids[$single_id] = $single_id;
        }
    }

    return \array_values($ids);
}

/**
 * Resolve the primary country value used for guest persistence.
 */
function get_confirmation_primary_country_code(array $billing_form): string
{
    $billing_country = isset($billing_form['billing_country']) ? (string) $billing_form['billing_country'] : '';

    if ($billing_country !== '') {
        return $billing_country;
    }

    return isset($billing_form['country']) ? (string) $billing_form['country'] : '';
}

/**
 * Build seeded billing values from stored guest progress.
 *
 * @param array<string, string> $guest_form
 * @param array<string, mixed>  $stored_billing_form
 * @return array<string, string>
 */
function get_confirmation_billing_form_seed(array $guest_form, array $stored_billing_form = []): array
{
    $guest_country = isset($guest_form['country']) ? resolve_checkout_country_code((string) $guest_form['country']) : '';
    $guest_phone_country_code = isset($guest_form['phone_country_code'])
        ? normalize_checkout_phone_option_value((string) $guest_form['phone_country_code'])
        : get_checkout_default_phone_option_value();
    $guest_phone_number = isset($guest_form['phone_number']) ? (string) $guest_form['phone_number'] : '';

    return [
        'first_name' => isset($stored_billing_form['first_name']) ? (string) $stored_billing_form['first_name'] : (string) ($guest_form['first_name'] ?? ''),
        'last_name' => isset($stored_billing_form['last_name']) ? (string) $stored_billing_form['last_name'] : (string) ($guest_form['last_name'] ?? ''),
        'company' => isset($stored_billing_form['company']) ? (string) $stored_billing_form['company'] : '',
        'country' => isset($stored_billing_form['country']) && (string) $stored_billing_form['country'] !== ''
            ? (string) $stored_billing_form['country']
            : $guest_country,
        'street_address' => isset($stored_billing_form['street_address']) ? (string) $stored_billing_form['street_address'] : '',
        'address_line_2' => isset($stored_billing_form['address_line_2']) ? (string) $stored_billing_form['address_line_2'] : '',
        'city' => isset($stored_billing_form['city']) ? (string) $stored_billing_form['city'] : '',
        'county' => isset($stored_billing_form['county']) ? (string) $stored_billing_form['county'] : '',
        'postcode' => isset($stored_billing_form['postcode']) ? (string) $stored_billing_form['postcode'] : '',
        'phone_country_code' => isset($stored_billing_form['phone_country_code']) && (string) $stored_billing_form['phone_country_code'] !== ''
            ? (string) $stored_billing_form['phone_country_code']
            : $guest_phone_country_code,
        'phone_number' => isset($stored_billing_form['phone_number']) ? (string) $stored_billing_form['phone_number'] : $guest_phone_number,
        'email' => isset($stored_billing_form['email']) ? (string) $stored_billing_form['email'] : (string) ($guest_form['email'] ?? ''),
        'billing_country' => isset($stored_billing_form['billing_country']) && (string) $stored_billing_form['billing_country'] !== ''
            ? (string) $stored_billing_form['billing_country']
            : $guest_country,
        'special_requests' => isset($stored_billing_form['special_requests'])
            ? (string) $stored_billing_form['special_requests']
            : (string) ($guest_form['special_requests'] ?? ''),
    ];
}

/**
 * Extract billing form values.
 *
 * @param array<string, mixed> $source
 * @param array<string, string> $fallback
 * @return array<string, string>
 */
function get_confirmation_billing_form_values(array $source, array $fallback = []): array
{
    $default_phone_country_code = isset($fallback['phone_country_code']) && (string) $fallback['phone_country_code'] !== ''
        ? normalize_checkout_phone_option_value((string) $fallback['phone_country_code'])
        : get_checkout_default_phone_option_value();

    return [
        'first_name' => isset($source['first_name']) ? \sanitize_text_field((string) \wp_unslash($source['first_name'])) : (string) ($fallback['first_name'] ?? ''),
        'last_name' => isset($source['last_name']) ? \sanitize_text_field((string) \wp_unslash($source['last_name'])) : (string) ($fallback['last_name'] ?? ''),
        'company' => isset($source['company']) ? \sanitize_text_field((string) \wp_unslash($source['company'])) : (string) ($fallback['company'] ?? ''),
        'country' => isset($source['country'])
            ? resolve_checkout_country_code(\sanitize_text_field((string) \wp_unslash($source['country'])))
            : (string) ($fallback['country'] ?? ''),
        'street_address' => isset($source['street_address']) ? \sanitize_text_field((string) \wp_unslash($source['street_address'])) : (string) ($fallback['street_address'] ?? ''),
        'address_line_2' => isset($source['address_line_2']) ? \sanitize_text_field((string) \wp_unslash($source['address_line_2'])) : (string) ($fallback['address_line_2'] ?? ''),
        'city' => isset($source['city']) ? \sanitize_text_field((string) \wp_unslash($source['city'])) : (string) ($fallback['city'] ?? ''),
        'county' => isset($source['county']) ? \sanitize_text_field((string) \wp_unslash($source['county'])) : (string) ($fallback['county'] ?? ''),
        'postcode' => isset($source['postcode']) ? \sanitize_text_field((string) \wp_unslash($source['postcode'])) : (string) ($fallback['postcode'] ?? ''),
        'phone_country_code' => isset($source['phone_country_code'])
            ? normalize_checkout_phone_option_value(\sanitize_text_field((string) \wp_unslash($source['phone_country_code'])))
            : $default_phone_country_code,
        'phone_number' => isset($source['phone_number'])
            ? sanitize_checkout_phone_number(\sanitize_text_field((string) \wp_unslash($source['phone_number'])))
            : sanitize_checkout_phone_number((string) ($fallback['phone_number'] ?? '')),
        'email' => isset($source['email']) ? \sanitize_email((string) \wp_unslash($source['email'])) : (string) ($fallback['email'] ?? ''),
        'billing_country' => isset($source['billing_country'])
            ? resolve_checkout_country_code(\sanitize_text_field((string) \wp_unslash($source['billing_country'])))
            : (string) ($fallback['billing_country'] ?? ''),
        'special_requests' => isset($source['special_requests']) ? \sanitize_textarea_field((string) \wp_unslash($source['special_requests'])) : (string) ($fallback['special_requests'] ?? ''),
    ];
}

/**
 * Validate billing information collected on confirmation.
 *
 * @param array<string, string> $billing_form
 * @return array<int, string>
 */
function validate_confirmation_billing_form_values(array $billing_form): array
{
    $errors = [];

    if ($billing_form['first_name'] === '') {
        $errors[] = \__('First name is required.', 'must-hotel-booking');
    }

    if ($billing_form['last_name'] === '') {
        $errors[] = \__('Last name is required.', 'must-hotel-booking');
    }

    if ($billing_form['street_address'] === '') {
        $errors[] = \__('Street address is required.', 'must-hotel-booking');
    }

    if ($billing_form['city'] === '') {
        $errors[] = \__('Town / City is required.', 'must-hotel-booking');
    }

    if ($billing_form['county'] === '') {
        $errors[] = \__('County is required.', 'must-hotel-booking');
    }

    if ($billing_form['postcode'] === '') {
        $errors[] = \__('Postcode / ZIP is required.', 'must-hotel-booking');
    }

    if ($billing_form['email'] === '' || !\is_email($billing_form['email'])) {
        $errors[] = \__('A valid email address is required.', 'must-hotel-booking');
    }

    if (get_confirmation_primary_country_code($billing_form) === '') {
        $errors[] = \__('Country of residence is required.', 'must-hotel-booking');
    }

    if ((string) ($billing_form['phone_number'] ?? '') === '') {
        $errors[] = \__('Phone number is required.', 'must-hotel-booking');
    }

    if ((string) ($billing_form['billing_country'] ?? '') === '') {
        $errors[] = \__('Billing country is required.', 'must-hotel-booking');
    }

    return $errors;
}

/**
 * Merge checkout guest values with confirmation billing values.
 *
 * @param array<string, string> $guest_form
 * @param array<string, string> $billing_form
 * @return array<string, string>
 */
function build_confirmation_guest_form(array $guest_form, array $billing_form): array
{
    return \array_merge(
        $guest_form,
        [
            'first_name' => $billing_form['first_name'],
            'last_name' => $billing_form['last_name'],
            'email' => $billing_form['email'],
            'phone_country_code' => $billing_form['phone_country_code'],
            'phone_number' => $billing_form['phone_number'],
            'country' => get_confirmation_primary_country_code($billing_form),
            'company' => $billing_form['company'],
            'street_address' => $billing_form['street_address'],
            'address_line_2' => $billing_form['address_line_2'],
            'city' => $billing_form['city'],
            'county' => $billing_form['county'],
            'postcode' => $billing_form['postcode'],
            'billing_country' => $billing_form['billing_country'],
            'special_requests' => $billing_form['special_requests'] !== ''
                ? $billing_form['special_requests']
                : (string) ($guest_form['special_requests'] ?? ''),
        ]
    );
}

/**
 * Build status copy for confirmation result screens.
 *
 * @param array<int, array<string, mixed>> $reservations
 * @return array<string, string>
 */
function get_confirmation_result_copy(array $reservations, string $payment_method_hint = ''): array
{
    $statuses = [];
    $payment_statuses = [];

    foreach ($reservations as $reservation) {
        if (!\is_array($reservation)) {
            continue;
        }

        $statuses[] = isset($reservation['status']) ? \sanitize_key((string) $reservation['status']) : '';
        $payment_statuses[] = isset($reservation['payment_status']) ? \sanitize_key((string) $reservation['payment_status']) : '';
    }

    if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'pending_payment') {
        return [
            'heading' => \__('Payment Processing', 'must-hotel-booking'),
            'message' => \__('Your payment is being confirmed. This page will update once Stripe finishes processing your booking.', 'must-hotel-booking'),
        ];
    }

    if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'pending') {
        if ($payment_method_hint === 'bank_transfer') {
            return [
                'heading' => \__('Booking Pending Payment', 'must-hotel-booking'),
                'message' => \__('Your booking request has been received and is waiting for bank transfer payment confirmation.', 'must-hotel-booking'),
            ];
        }

        return [
            'heading' => \__('Booking Pending', 'must-hotel-booking'),
            'message' => \__('Your booking has been received and is awaiting final processing.', 'must-hotel-booking'),
        ];
    }

    if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'expired') {
        return [
            'heading' => \__('Payment Session Expired', 'must-hotel-booking'),
            'message' => \__('The payment session expired before it was completed. Please start the payment step again.', 'must-hotel-booking'),
        ];
    }

    if (!empty($statuses) && \count(\array_unique($statuses)) === 1 && $statuses[0] === 'payment_failed') {
        return [
            'heading' => \__('Payment Failed', 'must-hotel-booking'),
            'message' => \__('We were not able to confirm your payment. Please try again or choose another payment method.', 'must-hotel-booking'),
        ];
    }

    if (!empty($payment_statuses) && \count(\array_unique($payment_statuses)) === 1 && $payment_statuses[0] === 'paid') {
        return [
            'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
            'message' => \__('Your payment was successful and your stay is confirmed.', 'must-hotel-booking'),
        ];
    }

    if ($payment_method_hint === 'pay_at_hotel') {
        return [
            'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
            'message' => \__('Your stay is confirmed. Payment will be collected at the hotel.', 'must-hotel-booking'),
        ];
    }

    return [
        'heading' => \__('Booking Confirmed', 'must-hotel-booking'),
        'message' => \__('Your stay has been confirmed.', 'must-hotel-booking'),
    ];
}

/**
 * Build view data for the pre-submit confirmation step.
 *
 * @return array<string, mixed>
 */
function get_pending_confirmation_page_view_data(): array
{
    /** @var array<string, mixed> $request_source */
    $request_source = \is_array($_GET) ? $_GET : [];
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method === 'POST' && \is_array($_POST)) {
        $request_source = $_POST;
    }

    $messages = [];
    $selection = get_booking_selection();
    $selection_context = normalize_booking_selection_context($selection['context'] ?? []);
    $fixed_room_mode = \function_exists(__NAMESPACE__ . '\is_fixed_room_booking_flow')
        ? is_fixed_room_booking_flow()
        : false;
    $fixed_room_id = $fixed_room_mode && \function_exists(__NAMESPACE__ . '\get_fixed_room_booking_room_id')
        ? get_fixed_room_booking_room_id()
        : 0;
    $flow_data = get_booking_selection_flow_data();
    $pending_payment = \function_exists(__NAMESPACE__ . '\normalize_pending_payment_flow_data')
        ? normalize_pending_payment_flow_data($flow_data['pending_payment'] ?? [])
        : [];
    $context = parse_booking_request_context($selection_context, true);
    $selected_room_ids = get_booking_selected_room_ids();
    $stored_guest_form_source = isset($flow_data['guest_form']) && \is_array($flow_data['guest_form']) ? $flow_data['guest_form'] : [];
    $guest_form = get_checkout_guest_form_values($stored_guest_form_source);
    $stored_billing_form = isset($flow_data['billing_form']) && \is_array($flow_data['billing_form']) ? $flow_data['billing_form'] : [];
    $billing_seed = get_confirmation_billing_form_seed($guest_form, $stored_billing_form);
    $billing_form = get_confirmation_billing_form_values(
        $request_method === 'POST' && \is_array($_POST) ? $_POST : $billing_seed,
        $billing_seed
    );
    $payment_method = \function_exists(__NAMESPACE__ . '\get_selected_checkout_payment_method')
        ? get_selected_checkout_payment_method($request_source, $flow_data)
        : 'pay_at_hotel';
    $payment_methods = \function_exists(__NAMESPACE__ . '\get_checkout_payment_methods')
        ? get_checkout_payment_methods()
        : [
            'pay_at_hotel' => [
                'label' => \__('Pay at hotel', 'must-hotel-booking'),
                'description' => \__('Guest pays during check-in or check-out at the property.', 'must-hotel-booking'),
            ],
        ];
    $confirmation_cta_label = \function_exists(__NAMESPACE__ . '\get_checkout_payment_cta_label')
        ? get_checkout_payment_cta_label($payment_method)
        : \__('Confirm reservation', 'must-hotel-booking');
    $request_action = isset($request_source['must_confirmation_action']) ? \sanitize_key((string) \wp_unslash($request_source['must_confirmation_action'])) : '';
    $submitted_coupon_code = isset($request_source['coupon_code']) ? \sanitize_text_field((string) \wp_unslash($request_source['coupon_code'])) : '';
    $persisted_coupon_code = isset($request_source['applied_coupon_code']) ? \sanitize_text_field((string) \wp_unslash($request_source['applied_coupon_code'])) : '';
    $stored_coupon_code = isset($flow_data['coupon_code']) ? \sanitize_text_field((string) $flow_data['coupon_code']) : '';
    $coupon_code = '';

    if ($request_action === 'preview_coupon') {
        $coupon_code = $submitted_coupon_code;
    } elseif ($submitted_coupon_code !== '') {
        $coupon_code = $submitted_coupon_code;
    } elseif ($persisted_coupon_code !== '') {
        $coupon_code = $persisted_coupon_code;
    } elseif ($stored_coupon_code !== '') {
        $coupon_code = $stored_coupon_code;
    }

    if (empty($selected_room_ids)) {
        $messages[] = \__('Please select at least one room before continuing to confirmation.', 'must-hotel-booking');
        $context['is_valid'] = false;
    }

    if (empty($stored_guest_form_source)) {
        $messages[] = \__('Please complete guest information before confirming your stay.', 'must-hotel-booking');
        $context['is_valid'] = false;
    }

    if (!empty($context['is_valid'])) {
        $lock_ok = ensure_checkout_room_locks($selected_room_ids, (string) $context['checkin'], (string) $context['checkout']);

        if (!$lock_ok) {
            $messages[] = \__('One or more selected room locks have expired. Please return to accommodation and confirm them again.', 'must-hotel-booking');
            $context['is_valid'] = false;
        }
    } else {
        foreach ((array) ($context['errors'] ?? []) as $error_message) {
            $messages[] = (string) $error_message;
        }
    }

    $room_items = [
        'items' => [],
        'summary' => [
            'room_subtotal' => 0.0,
            'fees_total' => 0.0,
            'discount_total' => 0.0,
            'taxes_total' => 0.0,
            'total_price' => 0.0,
            'nights' => 0,
            'applied_coupon' => '',
        ],
    ];

    if (!empty($context['is_valid'])) {
        $room_items = get_checkout_selected_room_items($context, $coupon_code, $guest_form);
    }

    if (!empty($room_items['errors'])) {
        foreach ((array) $room_items['errors'] as $room_item_error) {
            $messages[] = (string) $room_item_error;
        }
    }

    $summary = isset($room_items['summary']) && \is_array($room_items['summary']) ? $room_items['summary'] : [];
    $applied_coupon_code = isset($summary['applied_coupon']) ? \sanitize_text_field((string) $summary['applied_coupon']) : '';
    $coupon_input_value = $submitted_coupon_code;

    if ($applied_coupon_code !== '' && ((float) ($summary['discount_total'] ?? 0.0)) > 0.0) {
        $coupon_input_value = '';
    }

    if (isset($_GET['stripe_return']) && (string) \wp_unslash($_GET['stripe_return']) === 'cancel') {
        $messages[] = \__('Stripe checkout was canceled. You can review your booking and try again.', 'must-hotel-booking');
    }

    if ($request_method === 'POST' && $request_action === 'confirm_booking') {
        $nonce = isset($_POST['must_confirmation_nonce']) ? (string) \wp_unslash($_POST['must_confirmation_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_confirm_booking')) {
            $messages[] = \__('Security check failed. Please try again.', 'must-hotel-booking');
        } else {
            $billing_errors = validate_confirmation_billing_form_values($billing_form);

            foreach ($billing_errors as $billing_error) {
                $messages[] = $billing_error;
            }

            if (!isset($payment_methods[$payment_method])) {
                $messages[] = \__('Please select a valid payment method.', 'must-hotel-booking');
            }

            if (empty($billing_errors) && isset($payment_methods[$payment_method]) && !empty($context['is_valid'])) {
                $confirmation_guest_form = build_confirmation_guest_form($guest_form, $billing_form);
                $effective_coupon_code = $applied_coupon_code !== '' ? $applied_coupon_code : $coupon_code;
                update_booking_selection_flow_data([
                    'guest_form' => $confirmation_guest_form,
                    'billing_form' => $billing_form,
                    'coupon_code' => $effective_coupon_code,
                    'payment_method' => $payment_method,
                ]);

                if ($payment_method === 'stripe') {
                    $reservation_ids = [];
                    $created_new_draft = false;
                    $stripe_coupon_ids = [];

                    if (
                        !empty($pending_payment['reservation_ids']) &&
                        \function_exists(__NAMESPACE__ . '\are_reusable_pending_payment_reservations') &&
                        are_reusable_pending_payment_reservations((array) $pending_payment['reservation_ids'])
                    ) {
                        $reservation_ids = \array_map('intval', (array) $pending_payment['reservation_ids']);
                    } else {
                        $result = create_checkout_reservations(
                            $context,
                            $confirmation_guest_form,
                            $effective_coupon_code,
                            [
                                'reservation_status' => 'pending_payment',
                                'payment_status' => 'pending',
                                'clear_selection' => false,
                                'increment_coupon_usage' => false,
                            ]
                        );

                        if (!empty($result['errors'])) {
                            foreach ((array) $result['errors'] as $result_error) {
                                $messages[] = (string) $result_error;
                            }
                        } else {
                            $reservation_ids = \array_map('intval', (array) ($result['reservation_ids'] ?? []));
                            $created_new_draft = !empty($reservation_ids);
                            $stripe_coupon_ids = \array_map('intval', (array) ($result['applied_coupon_ids'] ?? []));
                        }
                    }

                    if (empty($stripe_coupon_ids) && $effective_coupon_code !== '' && \function_exists(__NAMESPACE__ . '\get_coupon_rule_from_table')) {
                        $coupon_rule = get_coupon_rule_from_table($effective_coupon_code);

                        if (\is_array($coupon_rule) && !empty($coupon_rule['id'])) {
                            $stripe_coupon_ids[] = (int) $coupon_rule['id'];
                        }
                    }

                    if (!empty($reservation_ids) && empty($messages) && \function_exists(__NAMESPACE__ . '\create_stripe_checkout_session')) {
                        $currency = \class_exists(__NAMESPACE__ . '\MustBookingConfig') ? MustBookingConfig::get_currency() : 'USD';
                        $stripe_session = create_stripe_checkout_session(
                            $reservation_ids,
                            $confirmation_guest_form,
                            isset($summary['total_price']) ? (float) $summary['total_price'] : 0.0,
                            $currency,
                            $stripe_coupon_ids
                        );

                        if (empty($stripe_session['success'])) {
                            if ($created_new_draft && \function_exists(__NAMESPACE__ . '\fail_pending_stripe_reservations')) {
                                fail_pending_stripe_reservations($reservation_ids, 'payment_failed');
                            }

                            $messages[] = isset($stripe_session['message']) && (string) $stripe_session['message'] !== ''
                                ? (string) $stripe_session['message']
                                : \__('Unable to start Stripe checkout right now.', 'must-hotel-booking');
                        } else {
                            if (\function_exists(__NAMESPACE__ . '\create_or_update_payment_rows')) {
                                create_or_update_payment_rows($reservation_ids, 'stripe', 'pending', (string) ($stripe_session['session_id'] ?? ''));
                            }

                            update_booking_selection_flow_data([
                                'pending_payment' => [
                                    'method' => 'stripe',
                                    'reservation_ids' => $reservation_ids,
                                    'session_id' => (string) ($stripe_session['session_id'] ?? ''),
                                    'checkout_url' => (string) ($stripe_session['checkout_url'] ?? ''),
                                    'expires_at' => (string) ($stripe_session['expires_at'] ?? ''),
                                    'created_at' => \current_time('mysql'),
                                ],
                            ]);

                            $stripe_checkout_url = (string) ($stripe_session['checkout_url'] ?? '');

                            if (
                                $stripe_checkout_url !== ''
                                && \function_exists(__NAMESPACE__ . '\is_stripe_checkout_url')
                                && is_stripe_checkout_url($stripe_checkout_url)
                            ) {
                                \wp_redirect($stripe_checkout_url);
                                exit;
                            }

                            if ($created_new_draft && \function_exists(__NAMESPACE__ . '\fail_pending_stripe_reservations')) {
                                fail_pending_stripe_reservations($reservation_ids, 'payment_failed');
                            }

                            update_booking_selection_flow_data([
                                'pending_payment' => get_empty_pending_payment_flow_data(),
                            ]);

                            $messages[] = \__('Stripe returned an invalid checkout URL. Please try again.', 'must-hotel-booking');
                        }
                    }
                } else {
                    $reservation_status = $payment_method === 'bank_transfer' ? 'pending' : 'confirmed';
                    $reservation_payment_status = $payment_method === 'bank_transfer' ? 'pending' : 'unpaid';
                    $result = create_checkout_reservations(
                        $context,
                        $confirmation_guest_form,
                        $effective_coupon_code,
                        [
                            'reservation_status' => $reservation_status,
                            'payment_status' => $reservation_payment_status,
                            'clear_selection' => true,
                        ]
                    );

                    if (!empty($result['errors'])) {
                        foreach ((array) $result['errors'] as $result_error) {
                            $messages[] = (string) $result_error;
                        }
                    } else {
                        $reservation_ids = \array_map('intval', (array) ($result['reservation_ids'] ?? []));

                        if (\function_exists(__NAMESPACE__ . '\create_or_update_payment_rows')) {
                            create_or_update_payment_rows(
                                $reservation_ids,
                                $payment_method,
                                $payment_method === 'bank_transfer' ? 'pending' : 'pending'
                            );
                        }

                        $redirect_url = \add_query_arg(
                            [
                                'reservation_ids' => \implode(',', $reservation_ids),
                                'payment_method' => $payment_method,
                            ],
                            get_booking_confirmation_page_url()
                        );

                        \wp_safe_redirect($redirect_url);
                        exit;
                    }
                }
            }
        }
    }

    $synced_guest_form = build_confirmation_guest_form($guest_form, $billing_form);

    update_booking_selection_flow_data([
        'guest_form' => $synced_guest_form,
        'billing_form' => $billing_form,
        'coupon_code' => $applied_coupon_code !== '' ? $applied_coupon_code : $coupon_code,
        'payment_method' => $payment_method,
    ]);

    $messages = \array_values(
        \array_unique(
            \array_filter(
                \array_map('strval', $messages)
            )
        )
    );

    return [
        'success' => false,
        'is_form_mode' => true,
        'can_confirm' => !empty($context['is_valid']) && !empty($stored_guest_form_source) && !empty($selected_room_ids) && empty($room_items['errors']),
        'message' => '',
        'messages' => $messages,
        'reservations' => [],
        'primary_guest' => null,
        'total_price' => isset($summary['total_price']) ? (float) $summary['total_price'] : 0.0,
        'fixed_room_mode' => $fixed_room_mode,
        'fixed_room_id' => $fixed_room_id,
        'booking_url' => get_booking_context_url($selection_context, $fixed_room_id),
        'accommodation_url' => $fixed_room_mode
            ? get_booking_context_url($selection_context, $fixed_room_id)
            : get_booking_accommodation_context_url($selection_context),
        'checkout_url' => get_checkout_context_url($selection_context, $fixed_room_id),
        'confirmation_url' => get_booking_confirmation_page_url(),
        'selected_rooms' => isset($room_items['items']) && \is_array($room_items['items']) ? $room_items['items'] : [],
        'summary' => $summary,
        'billing_form' => $billing_form,
        'guest_form' => $guest_form,
        'payment_method' => $payment_method,
        'payment_methods' => $payment_methods,
        'pending_payment' => $pending_payment,
        'confirmation_cta_label' => $confirmation_cta_label,
        'coupon_code' => $coupon_code,
        'coupon_input_value' => $coupon_input_value,
        'applied_coupon_code' => $applied_coupon_code,
        'selected_room_count' => \count($selected_room_ids),
        'country_options' => get_checkout_country_options(),
        'phone_country_code_options' => get_checkout_phone_code_options(),
    ];
}

/**
 * Build view data for booking confirmation template.
 *
 * @return array<string, mixed>
 */
function get_confirmation_page_view_data(): array
{
    $reservation_ids = get_confirmation_reservation_ids_from_query();
    $reservations = [];
    $messages = [];
    $payment_method_hint = isset($_GET['payment_method']) ? \sanitize_key((string) \wp_unslash($_GET['payment_method'])) : '';
    $stripe_return = isset($_GET['stripe_return']) ? \sanitize_key((string) \wp_unslash($_GET['stripe_return'])) : '';
    $session_id = isset($_GET['session_id']) ? \sanitize_text_field((string) \wp_unslash($_GET['session_id'])) : '';

    if (!empty($reservation_ids)) {
        if ($payment_method_hint === 'stripe' && $session_id !== '' && \function_exists(__NAMESPACE__ . '\maybe_sync_stripe_return_session')) {
            $sync_result = maybe_sync_stripe_return_session($session_id, $reservation_ids);

            if (!empty($sync_result['success']) && isset($sync_result['state'])) {
                if ((string) $sync_result['state'] === 'pending') {
                    $messages[] = \__('Stripe has returned, but the payment is still being finalized. Please wait a moment and refresh if needed.', 'must-hotel-booking');
                } elseif ((string) $sync_result['state'] === 'expired') {
                    $messages[] = \__('The Stripe payment session expired before the payment completed.', 'must-hotel-booking');
                }
            } elseif (isset($sync_result['message']) && (string) $sync_result['message'] !== '') {
                $messages[] = (string) $sync_result['message'];
            }
        }

        $placeholders = \implode(', ', \array_fill(0, \count($reservation_ids), '%d'));
        $reservations = get_reservation_confirmation_rows('r.id IN (' . $placeholders . ')', $reservation_ids);
    } else {
        $booking_id = isset($_GET['booking_id']) ? \sanitize_text_field((string) \wp_unslash($_GET['booking_id'])) : '';

        if ($booking_id !== '') {
            $reservations = get_reservation_confirmation_rows('r.booking_id = %s', [$booking_id]);
        }
    }

    if (!empty($reservations)) {
        $status_copy = get_confirmation_result_copy($reservations, $payment_method_hint);

        if (
            ($payment_method_hint === 'stripe' || $stripe_return === 'success') &&
            !empty($reservations) &&
            \function_exists(__NAMESPACE__ . '\clear_booking_selection')
        ) {
            foreach ($reservations as $reservation) {
                $status = isset($reservation['status']) ? \sanitize_key((string) ($reservation['status'] ?? '')) : '';

                if (\function_exists(__NAMESPACE__ . '\is_reservation_confirmed_status') && is_reservation_confirmed_status($status)) {
                    clear_booking_selection(false);
                    break;
                }
            }
        }

        $primary_guest = $reservations[0];
        $total_price = 0.0;

        foreach ($reservations as $reservation) {
            $total_price += isset($reservation['total_price']) ? (float) $reservation['total_price'] : 0.0;
        }

        return [
            'success' => true,
            'is_form_mode' => false,
            'can_confirm' => false,
            'message' => '',
            'messages' => $messages,
            'reservations' => $reservations,
            'primary_guest' => $primary_guest,
            'total_price' => $total_price,
            'status_heading' => (string) ($status_copy['heading'] ?? \__('Booking Confirmed', 'must-hotel-booking')),
            'status_message' => (string) ($status_copy['message'] ?? ''),
            'booking_url' => get_booking_page_url(),
            'accommodation_url' => get_booking_accommodation_page_url(),
            'checkout_url' => get_checkout_page_url(),
            'confirmation_url' => get_booking_confirmation_page_url(),
            'selected_rooms' => [],
            'summary' => [],
            'billing_form' => [],
            'guest_form' => [],
            'payment_method' => $payment_method_hint,
            'payment_methods' => [],
            'confirmation_cta_label' => '',
            'coupon_code' => '',
            'coupon_input_value' => '',
            'applied_coupon_code' => '',
            'selected_room_count' => \count($reservations),
            'country_options' => [],
            'phone_country_code_options' => [],
        ];
    }

    $flow_data = get_booking_selection_flow_data();
    $has_guest_progress = isset($flow_data['guest_form']) && \is_array($flow_data['guest_form']) && !empty($flow_data['guest_form']);

    if (has_booking_selected_rooms() && $has_guest_progress) {
        return get_pending_confirmation_page_view_data();
    }

    return get_pending_confirmation_page_view_data();
}

/**
 * Enqueue shared booking-process styles for confirmation.
 */
function enqueue_confirmation_page_assets(): void
{
    if (!\is_page() || !\is_page((int) (get_plugin_settings()['page_booking_confirmation_id'] ?? 0))) {
        if (!\is_page('booking-confirmation')) {
            return;
        }
    }

    \wp_enqueue_style(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-page.css',
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_script(
        'must-hotel-booking-booking-confirmation',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-confirmation.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_enqueue_script(
        'must-hotel-booking-phone-fields',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-phone-fields.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_confirmation_page_assets');
