<?php

namespace must_hotel_booking;

/**
 * Split stored phone value into prefix and number pieces.
 *
 * @return array{phone_country_code: string, phone_number: string}
 */
function split_checkout_phone_value(string $phone): array
{
    $normalized = \trim($phone);
    $default_phone_option_value = get_checkout_default_phone_option_value();

    if ($normalized === '') {
        return [
            'phone_country_code' => $default_phone_option_value,
            'phone_number' => '',
        ];
    }

    $dial_codes = [];

    foreach (get_checkout_country_directory() as $country) {
        $dial_codes[(string) $country['dial_code']] = (string) $country['dial_code'];
    }

    \usort(
        $dial_codes,
        static function (string $left, string $right): int {
            return \strlen($right) <=> \strlen($left);
        }
    );

    foreach ($dial_codes as $dial_code) {
        if (\strpos($normalized, $dial_code) === 0) {
            return [
                'phone_country_code' => normalize_checkout_phone_option_value($dial_code),
                'phone_number' => \trim(\substr($normalized, \strlen($dial_code))),
            ];
        }
    }

    return [
        'phone_country_code' => $default_phone_option_value,
        'phone_number' => $normalized,
    ];
}

/**
 * Combine checkout phone input parts into one stored value.
 */
function combine_checkout_phone_value(array $guest_form): string
{
    $phone_country_code = isset($guest_form['phone_country_code'])
        ? normalize_checkout_phone_option_value((string) $guest_form['phone_country_code'])
        : get_checkout_default_phone_option_value();
    $phone_number = isset($guest_form['phone_number']) ? \trim((string) $guest_form['phone_number']) : '';
    $phone_option_details = get_checkout_phone_option_details($phone_country_code);

    if ($phone_number === '') {
        return '';
    }

    return \trim((string) $phone_option_details['dial_code'] . ' ' . $phone_number);
}

/**
 * Build reservation notes from checkout-only fields.
 */
function build_checkout_reservation_note(int $room_id, array $guest_form): string
{
    $sections = [];
    $special_requests = isset($guest_form['special_requests']) ? \trim((string) $guest_form['special_requests']) : '';
    $room_guests = isset($guest_form['room_guests']) && \is_array($guest_form['room_guests']) ? $guest_form['room_guests'] : [];
    $room_guest = isset($room_guests[$room_id]) && \is_array($room_guests[$room_id]) ? $room_guests[$room_id] : [];
    $room_guest_lines = [];

    if ($special_requests !== '') {
        $sections[] = \sprintf(
            /* translators: %s is the submitted special requests text. */
            \__('Special Requests: %s', 'must-hotel-booking'),
            $special_requests
        );
    }

    $room_guest_count = isset($room_guest['guest_count']) ? \trim((string) $room_guest['guest_count']) : '';
    $room_guest_first_name = isset($room_guest['first_name']) ? \trim((string) $room_guest['first_name']) : '';
    $room_guest_last_name = isset($room_guest['last_name']) ? \trim((string) $room_guest['last_name']) : '';

    if ($room_guest_count !== '') {
        $room_guest_lines[] = \sprintf(
            /* translators: %s is room guest count. */
            \__('Guests Number: %s', 'must-hotel-booking'),
            $room_guest_count
        );
    }

    if ($room_guest_first_name !== '') {
        $room_guest_lines[] = \sprintf(
            /* translators: %s is room guest first name. */
            \__('First Name: %s', 'must-hotel-booking'),
            $room_guest_first_name
        );
    }

    if ($room_guest_last_name !== '') {
        $room_guest_lines[] = \sprintf(
            /* translators: %s is room guest last name. */
            \__('Last Name: %s', 'must-hotel-booking'),
            $room_guest_last_name
        );
    }

    if (!empty($room_guest_lines)) {
        $sections[] = \__('Room Guest Information:', 'must-hotel-booking') . "\n" . \implode("\n", $room_guest_lines);
    }

    if (!empty($guest_form['marketing_opt_in'])) {
        $sections[] = \__('Marketing Consent: Yes', 'must-hotel-booking');
    }

    $billing_lines = [];
    $billing_fields = [
        'company' => \__('Company Name', 'must-hotel-booking'),
        'street_address' => \__('Street Address', 'must-hotel-booking'),
        'address_line_2' => \__('Apartment, Suite, Unit, etc.', 'must-hotel-booking'),
        'city' => \__('Town / City', 'must-hotel-booking'),
        'county' => \__('County', 'must-hotel-booking'),
        'postcode' => \__('Postcode / ZIP', 'must-hotel-booking'),
    ];

    foreach ($billing_fields as $field_key => $field_label) {
        $field_value = isset($guest_form[$field_key]) ? \trim((string) $guest_form[$field_key]) : '';

        if ($field_value !== '') {
            $billing_lines[] = $field_label . ': ' . $field_value;
        }
    }

    $billing_country = isset($guest_form['billing_country']) ? \trim((string) $guest_form['billing_country']) : '';

    if ($billing_country !== '') {
        $billing_lines[] = \__('Billing Country', 'must-hotel-booking') . ': ' . get_checkout_country_name($billing_country);
    }

    if (!empty($billing_lines)) {
        $sections[] = \__('Billing Information:', 'must-hotel-booking') . "\n" . \implode("\n", $billing_lines);
    }

    return \trim(\implode("\n\n", $sections));
}

/**
 * Load room row for checkout summaries.
 *
 * @return array<string, mixed>|null
 */
function get_checkout_room_data(int $room_id): ?array
{
    if ($room_id <= 0) {
        return null;
    }

    if (\function_exists(__NAMESPACE__ . '\get_room_record')) {
        return get_room_record($room_id);
    }

    global $wpdb;

    $room = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id, name, slug, category, description, max_guests, base_price, extra_guest_price, room_size, beds FROM ' . $wpdb->prefix . 'must_rooms WHERE id = %d LIMIT 1',
            $room_id
        ),
        ARRAY_A
    );

    return \is_array($room) ? $room : null;
}

/**
 * Ensure checkout lock exists for the current session.
 */
function ensure_checkout_room_lock(int $room_id, string $checkin, string $checkout): bool
{
    if (
        $room_id <= 0 ||
        !\function_exists(__NAMESPACE__ . '\get_or_create_lock_session_id') ||
        !\function_exists(__NAMESPACE__ . '\has_active_exact_room_lock')
    ) {
        return false;
    }

    $session_id = get_or_create_lock_session_id();

    if ($session_id === '') {
        return false;
    }

    if (has_active_exact_room_lock($room_id, $checkin, $checkout, $session_id)) {
        return true;
    }

    if (!\function_exists(__NAMESPACE__ . '\create_temporary_reservation_lock')) {
        return false;
    }

    return create_temporary_reservation_lock($room_id, $checkin, $checkout, $session_id);
}

/**
 * Ensure checkout locks exist for all selected rooms.
 *
 * @param array<int, int> $room_ids
 */
function ensure_checkout_room_locks(array $room_ids, string $checkin, string $checkout): bool
{
    foreach ($room_ids as $room_id) {
        if (!ensure_checkout_room_lock((int) $room_id, $checkin, $checkout)) {
            return false;
        }
    }

    return true;
}

/**
 * Extract guest form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, string>
 */
function get_checkout_guest_form_values(array $source): array
{
    $stored_phone = isset($source['phone']) ? \sanitize_text_field((string) \wp_unslash($source['phone'])) : '';
    $phone_parts = split_checkout_phone_value($stored_phone);
    $phone_country_code = isset($source['phone_country_code'])
        ? normalize_checkout_phone_option_value(\sanitize_text_field((string) \wp_unslash($source['phone_country_code'])))
        : (string) $phone_parts['phone_country_code'];
    $phone_number = isset($source['phone_number'])
        ? \sanitize_text_field((string) \wp_unslash($source['phone_number']))
        : (string) $phone_parts['phone_number'];
    $country_code = isset($source['country'])
        ? resolve_checkout_country_code(\sanitize_text_field((string) \wp_unslash($source['country'])))
        : '';
    $room_guest_counts = isset($source['room_guest_count']) && \is_array($source['room_guest_count']) ? $source['room_guest_count'] : [];
    $room_guest_first_names = isset($source['room_guest_first_name']) && \is_array($source['room_guest_first_name']) ? $source['room_guest_first_name'] : [];
    $room_guest_last_names = isset($source['room_guest_last_name']) && \is_array($source['room_guest_last_name']) ? $source['room_guest_last_name'] : [];
    $room_guests = [];

    foreach (\array_keys($room_guest_counts + $room_guest_first_names + $room_guest_last_names) as $room_id) {
        $normalized_room_id = \absint($room_id);

        if ($normalized_room_id <= 0) {
            continue;
        }

        $room_guests[$normalized_room_id] = [
            'guest_count' => isset($room_guest_counts[$room_id]) ? \sanitize_text_field((string) \wp_unslash($room_guest_counts[$room_id])) : '',
            'first_name' => isset($room_guest_first_names[$room_id]) ? \sanitize_text_field((string) \wp_unslash($room_guest_first_names[$room_id])) : '',
            'last_name' => isset($room_guest_last_names[$room_id]) ? \sanitize_text_field((string) \wp_unslash($room_guest_last_names[$room_id])) : '',
        ];
    }

    return [
        'first_name' => isset($source['first_name']) ? \sanitize_text_field((string) \wp_unslash($source['first_name'])) : '',
        'last_name' => isset($source['last_name']) ? \sanitize_text_field((string) \wp_unslash($source['last_name'])) : '',
        'email' => isset($source['email']) ? \sanitize_email((string) \wp_unslash($source['email'])) : '',
        'phone' => combine_checkout_phone_value([
            'phone_country_code' => $phone_country_code,
            'phone_number' => $phone_number,
        ]),
        'phone_country_code' => $phone_country_code,
        'phone_number' => $phone_number,
        'country' => $country_code,
        'special_requests' => isset($source['special_requests']) ? \sanitize_textarea_field((string) \wp_unslash($source['special_requests'])) : '',
        'marketing_opt_in' => isset($source['marketing_opt_in']) ? '1' : '',
        'room_guests' => $room_guests,
    ];
}

/**
 * Validate guest form values.
 *
 * @param array<string, string> $guest_form
 * @return array<int, string>
 */
function validate_checkout_guest_form_values(array $guest_form): array
{
    $errors = [];

    if ($guest_form['first_name'] === '') {
        $errors[] = \__('First name is required.', 'must-hotel-booking');
    }

    if ($guest_form['last_name'] === '') {
        $errors[] = \__('Last name is required.', 'must-hotel-booking');
    }

    if ($guest_form['email'] === '' || !\is_email($guest_form['email'])) {
        $errors[] = \__('A valid email address is required.', 'must-hotel-booking');
    }

    return $errors;
}

/**
 * Insert guest row and return guest ID.
 *
 * @param array<string, string> $guest_form
 */
function create_checkout_guest(array $guest_form): int
{
    global $wpdb;

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'must_guests',
        [
            'first_name' => $guest_form['first_name'],
            'last_name' => $guest_form['last_name'],
            'email' => $guest_form['email'],
            'phone' => combine_checkout_phone_value($guest_form),
            'country' => get_checkout_country_name((string) ($guest_form['country'] ?? '')),
        ],
        ['%s', '%s', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        return 0;
    }

    return (int) $wpdb->insert_id;
}

/**
 * Bootstrap checkout selection from a legacy single-room request.
 *
 * @param array<string, mixed> $source
 * @return array<int, string>
 */
function maybe_bootstrap_checkout_selection_from_request(array $source): array
{
    $room_id = isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0;

    if ($room_id <= 0) {
        return [];
    }

    $context = parse_booking_request_context($source, true);

    if (!$context['is_valid']) {
        return (array) $context['errors'];
    }

    if (!ensure_checkout_room_lock($room_id, (string) $context['checkin'], (string) $context['checkout'])) {
        return [\__('The room is no longer available for the selected dates.', 'must-hotel-booking')];
    }

    $added = add_room_to_booking_selection(
        $room_id,
        [
            'checkin' => (string) $context['checkin'],
            'checkout' => (string) $context['checkout'],
            'guests' => (int) $context['guests'],
            'accommodation_type' => (string) $context['accommodation_type'],
        ]
    );

    if (!$added) {
        return [\__('Unable to store the selected room.', 'must-hotel-booking')];
    }

    return [];
}

/**
 * Build selected room pricing items for checkout.
 *
 * @param array<string, mixed> $context
 * @return array{items: array<int, array<string, mixed>>, summary: array<string, float|int|string>}
 */
function get_checkout_selected_room_items(array $context, string $coupon_code = ''): array
{
    $items = [];
    $summary = [
        'room_subtotal' => 0.0,
        'fees_total' => 0.0,
        'discount_total' => 0.0,
        'taxes_total' => 0.0,
        'total_price' => 0.0,
        'nights' => 0,
        'applied_coupon' => '',
    ];

    foreach (get_booking_selected_room_ids() as $room_id) {
        $room = get_checkout_room_data((int) $room_id);

        if (!\is_array($room)) {
            continue;
        }

        $pricing = [];

        if (\function_exists(__NAMESPACE__ . '\calculate_booking_price')) {
            $pricing = calculate_booking_price(
                (int) $room_id,
                (string) ($context['checkin'] ?? ''),
                (string) ($context['checkout'] ?? ''),
                (int) ($context['guests'] ?? 1),
                $coupon_code
            );
        }

        if (\is_array($pricing) && !empty($pricing['success'])) {
            $room['dynamic_total_price'] = isset($pricing['total_price']) ? (float) $pricing['total_price'] : null;
            $room['price_preview_total'] = isset($pricing['total_price']) ? (float) $pricing['total_price'] : null;
            $room['dynamic_room_subtotal'] = isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : null;
            $room['dynamic_nights'] = isset($pricing['nights']) ? (int) $pricing['nights'] : null;

            $summary['room_subtotal'] += isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : 0.0;
            $summary['fees_total'] += isset($pricing['fees_total']) ? (float) $pricing['fees_total'] : 0.0;
            $summary['discount_total'] += isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0;
            $summary['taxes_total'] += isset($pricing['taxes_total']) ? (float) $pricing['taxes_total'] : 0.0;
            $summary['total_price'] += isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0;

            if ((int) $summary['nights'] === 0 && isset($pricing['nights'])) {
                $summary['nights'] = (int) $pricing['nights'];
            }

            if (
                (string) $summary['applied_coupon'] === ''
                && !empty($pricing['applied_coupon'])
                && \is_string($pricing['applied_coupon'])
            ) {
                $summary['applied_coupon'] = (string) $pricing['applied_coupon'];
            }
        }

        $room_view = \function_exists(__NAMESPACE__ . '\get_booking_results_room_view_data')
            ? get_booking_results_room_view_data($room)
            : $room;

        if (!\is_array($room_view)) {
            continue;
        }

        $items[] = [
            'room_id' => (int) $room_id,
            'room' => $room_view,
            'pricing' => \is_array($pricing) ? $pricing : [],
        ];
    }

    return [
        'items' => $items,
        'summary' => $summary,
    ];
}

/**
 * Handle remove-room action from checkout page.
 */
function maybe_process_checkout_room_removal(): void
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return;
    }

    $action = isset($_POST['must_checkout_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_checkout_action'])) : '';

    if ($action !== 'remove_selected_room') {
        return;
    }

    $room_id = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $nonce = isset($_POST['must_checkout_nonce']) ? (string) \wp_unslash($_POST['must_checkout_nonce']) : '';

    if ($room_id <= 0 || !\wp_verify_nonce($nonce, 'must_checkout_remove_room_' . $room_id)) {
        \wp_safe_redirect(get_checkout_page_url());
        exit;
    }

    remove_room_from_booking_selection($room_id);
    \wp_safe_redirect(get_checkout_page_url());
    exit;
}

/**
 * Validate checkout progress and redirect to confirmation.
 *
 * @param array<string, mixed>  $context
 * @param array<string, string> $guest_form
 * @return array<int, string>
 */
function maybe_process_checkout_continue(array $context, array $guest_form, string $coupon_code = ''): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_checkout_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_checkout_action'])) : '';

    if ($action !== 'continue_to_confirmation') {
        return [];
    }

    $nonce = isset($_POST['must_checkout_nonce']) ? (string) \wp_unslash($_POST['must_checkout_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_checkout_complete')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    if (empty($context['is_valid'])) {
        return (array) ($context['errors'] ?? []);
    }

    $selected_room_ids = get_booking_selected_room_ids();

    if (empty($selected_room_ids)) {
        return [\__('Please select at least one room before continuing.', 'must-hotel-booking')];
    }

    $validation_errors = validate_checkout_guest_form_values($guest_form);

    if (!empty($validation_errors)) {
        return $validation_errors;
    }

    if (!ensure_checkout_room_locks($selected_room_ids, (string) $context['checkin'], (string) $context['checkout'])) {
        return [\__('One or more selected room locks have expired. Please return to accommodation and confirm them again.', 'must-hotel-booking')];
    }

    update_booking_selection_flow_data([
        'guest_form' => $guest_form,
        'coupon_code' => $coupon_code,
    ]);

    \wp_safe_redirect(get_booking_confirmation_page_url());
    exit;
}

/**
 * Create reservations for all selected rooms.
 *
 * @param array<string, mixed>  $context
 * @param array<string, string> $guest_form
 * @return array{errors: array<int, string>, reservation_ids: array<int, int>}
 */
function create_checkout_reservations(array $context, array $guest_form, string $coupon_code = ''): array
{
    if (empty($context['is_valid'])) {
        return [
            'errors' => (array) ($context['errors'] ?? []),
            'reservation_ids' => [],
        ];
    }

    $selected_room_ids = get_booking_selected_room_ids();

    if (empty($selected_room_ids)) {
        return [
            'errors' => [\__('Please select at least one room before continuing.', 'must-hotel-booking')],
            'reservation_ids' => [],
        ];
    }

    $validation_errors = validate_checkout_guest_form_values($guest_form);

    if (!empty($validation_errors)) {
        return [
            'errors' => $validation_errors,
            'reservation_ids' => [],
        ];
    }

    $validated_rooms = [];

    foreach ($selected_room_ids as $room_id) {
        $lock_ok = ensure_checkout_room_lock((int) $room_id, (string) $context['checkin'], (string) $context['checkout']);

        if (!$lock_ok) {
            return [
                'errors' => [\__('One of your selected room locks has expired. Please return to accommodation and confirm your selection again.', 'must-hotel-booking')],
                'reservation_ids' => [],
            ];
        }

        if (\function_exists(__NAMESPACE__ . '\check_room_availability')) {
            $is_available_now = check_room_availability(
                (int) $room_id,
                (string) $context['checkin'],
                (string) $context['checkout']
            );

            if (!$is_available_now) {
                return [
                    'errors' => [\__('One of your selected rooms is no longer available for the selected dates.', 'must-hotel-booking')],
                    'reservation_ids' => [],
                ];
            }
        }

        $pricing = \function_exists(__NAMESPACE__ . '\calculate_booking_price')
            ? calculate_booking_price(
                (int) $room_id,
                (string) $context['checkin'],
                (string) $context['checkout'],
                (int) $context['guests'],
                $coupon_code
            )
            : [];

        if (!\is_array($pricing) || empty($pricing['success']) || !isset($pricing['total_price'])) {
            return [
                'errors' => [\__('Unable to calculate final booking total for one of the selected rooms.', 'must-hotel-booking')],
                'reservation_ids' => [],
            ];
        }

        $validated_rooms[] = [
            'room_id' => (int) $room_id,
            'pricing' => $pricing,
        ];
    }

    $guest_id = create_checkout_guest($guest_form);

    if ($guest_id <= 0) {
        return [
            'errors' => [\__('Unable to save guest details.', 'must-hotel-booking')],
            'reservation_ids' => [],
        ];
    }

    if (!\function_exists(__NAMESPACE__ . '\store_reservation_from_lock')) {
        return [
            'errors' => [\__('Reservation engine is not available.', 'must-hotel-booking')],
            'reservation_ids' => [],
        ];
    }

    global $wpdb;

    $transaction_started = $wpdb->query('START TRANSACTION') !== false;
    $reservation_ids = [];

    foreach ($validated_rooms as $validated_room) {
        $room_id = isset($validated_room['room_id']) ? (int) $validated_room['room_id'] : 0;
        $pricing = isset($validated_room['pricing']) && \is_array($validated_room['pricing']) ? $validated_room['pricing'] : [];

        $reservation_id = store_reservation_from_lock(
            $room_id,
            $guest_id,
            (string) $context['checkin'],
            (string) $context['checkout'],
            (int) $context['guests'],
            (float) ($pricing['total_price'] ?? 0.0),
            'pending',
            'pending'
        );

        if ($reservation_id <= 0) {
            if ($transaction_started) {
                $wpdb->query('ROLLBACK');
            }

            return [
                'errors' => [\__('Unable to complete the reservation for one of the selected rooms.', 'must-hotel-booking')],
                'reservation_ids' => [],
            ];
        }

        $reservation_note = build_checkout_reservation_note($room_id, $guest_form);

        if ($reservation_note !== '') {
            $wpdb->update(
                $wpdb->prefix . 'must_reservations',
                [
                    'notes' => $reservation_note,
                ],
                [
                    'id' => $reservation_id,
                ],
                ['%s'],
                ['%d']
            );
        }

        if (\function_exists(__NAMESPACE__ . '\increment_coupon_usage_count')) {
            $applied_coupon_id = isset($pricing['applied_coupon_id']) ? (int) $pricing['applied_coupon_id'] : 0;

            if ($applied_coupon_id > 0) {
                increment_coupon_usage_count($applied_coupon_id);
            }
        }

        $reservation_ids[] = $reservation_id;
    }

    if ($transaction_started) {
        $wpdb->query('COMMIT');
    }

    clear_booking_selection(false);

    return [
        'errors' => [],
        'reservation_ids' => $reservation_ids,
    ];
}

/**
 * Process checkout submit and create reservations for all selected rooms.
 *
 * @param array<string, mixed>  $context
 * @param array<string, string> $guest_form
 * @return array<int, string>
 */
function maybe_process_checkout_submit(array $context, array $guest_form, string $coupon_code = ''): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_checkout_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_checkout_action'])) : '';

    if ($action !== 'complete_checkout') {
        return [];
    }

    $nonce = isset($_POST['must_checkout_nonce']) ? (string) \wp_unslash($_POST['must_checkout_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_checkout_complete')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    $result = create_checkout_reservations($context, $guest_form, $coupon_code);

    if (!empty($result['errors'])) {
        return (array) $result['errors'];
    }

    $redirect_url = \add_query_arg(
        ['reservation_ids' => \implode(',', \array_map('intval', (array) ($result['reservation_ids'] ?? [])))],
        get_booking_confirmation_page_url()
    );

    \wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Build view data for checkout template.
 *
 * @return array<string, mixed>
 */
function get_checkout_page_view_data(): array
{
    /** @var array<string, mixed> $request_source */
    $request_source = \is_array($_GET) ? $_GET : [];
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method === 'POST' && \is_array($_POST)) {
        $request_source = $_POST;
    }

    $messages = maybe_bootstrap_checkout_selection_from_request(\is_array($_GET) ? $_GET : []);

    maybe_process_checkout_room_removal();

    $selection = get_booking_selection();
    $selection_context = normalize_booking_selection_context($selection['context'] ?? []);
    $flow_data = get_booking_selection_flow_data();
    $context = parse_booking_request_context($selection_context, true);
    $selected_room_ids = get_booking_selected_room_ids();
    $request_action = isset($request_source['must_checkout_action']) ? \sanitize_key((string) \wp_unslash($request_source['must_checkout_action'])) : '';
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
        $messages[] = \__('Please select at least one room before continuing to guest information.', 'must-hotel-booking');
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

    $guest_form_source = $request_method === 'POST' && \is_array($_POST)
        ? $_POST
        : (isset($flow_data['guest_form']) && \is_array($flow_data['guest_form']) ? $flow_data['guest_form'] : []);
    $guest_form = get_checkout_guest_form_values($guest_form_source);
    $submit_errors = maybe_process_checkout_continue($context, $guest_form, $coupon_code);

    foreach ($submit_errors as $submit_error) {
        $messages[] = (string) $submit_error;
    }

    $room_items = ['items' => [], 'summary' => [
        'room_subtotal' => 0.0,
        'fees_total' => 0.0,
        'discount_total' => 0.0,
        'taxes_total' => 0.0,
        'total_price' => 0.0,
        'nights' => 0,
        'applied_coupon' => '',
    ]];

    if (!empty($context['is_valid'])) {
        $room_items = get_checkout_selected_room_items($context, $coupon_code);
    }

    $summary_view = isset($room_items['summary']) && \is_array($room_items['summary']) ? $room_items['summary'] : [];
    $applied_coupon_code = isset($summary_view['applied_coupon']) ? \sanitize_text_field((string) $summary_view['applied_coupon']) : '';
    $coupon_input_value = $submitted_coupon_code;

    if ($applied_coupon_code !== '' && ((float) ($summary_view['discount_total'] ?? 0.0)) > 0.0) {
        $coupon_input_value = '';
    }

    $messages = \array_values(
        \array_unique(
            \array_filter(
                \array_map('strval', $messages)
            )
        )
    );

    return [
        'is_valid_context' => (bool) $context['is_valid'],
        'messages' => $messages,
        'selected_rooms' => isset($room_items['items']) && \is_array($room_items['items']) ? $room_items['items'] : [],
        'summary' => isset($room_items['summary']) && \is_array($room_items['summary']) ? $room_items['summary'] : [],
        'guest_form' => $guest_form,
        'checkin' => (string) ($context['checkin'] ?? ''),
        'checkout' => (string) ($context['checkout'] ?? ''),
        'guests' => (int) ($context['guests'] ?? 1),
        'coupon_code' => $coupon_code,
        'coupon_input_value' => $coupon_input_value,
        'applied_coupon_code' => $applied_coupon_code,
        'selected_room_count' => \count($selected_room_ids),
        'booking_url' => get_booking_context_url($selection_context),
        'accommodation_url' => get_booking_accommodation_context_url($selection_context),
        'checkout_url' => get_checkout_page_url(),
        'country_options' => get_checkout_country_options(),
        'phone_country_code_options' => get_checkout_phone_code_options(),
    ];
}

/**
 * Enqueue shared booking-process styles for checkout.
 */
function enqueue_checkout_page_assets(): void
{
    if (!\is_page() || !\is_page((int) (get_plugin_settings()['page_checkout_id'] ?? 0))) {
        if (!\is_page('checkout')) {
            return;
        }
    }

    \wp_enqueue_style(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-page.css',
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_checkout_page_assets');
