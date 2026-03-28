<?php

namespace MustHotelBooking\Frontend;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\CouponService;
use MustHotelBooking\Engine\PricingEngine;
use MustHotelBooking\Engine\ReservationEngine;

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
                'phone_number' => sanitize_checkout_phone_number(\substr($normalized, \strlen($dial_code))),
            ];
        }
    }

    return [
        'phone_country_code' => $default_phone_option_value,
        'phone_number' => sanitize_checkout_phone_number($normalized),
    ];
}

/**
 * Normalize checkout phone number input to digits only.
 */
function sanitize_checkout_phone_number(string $phone_number): string
{
    return \preg_replace('/\D+/', '', $phone_number) ?? '';
}

/**
 * Combine checkout phone input parts into one stored value.
 */
function combine_checkout_phone_value(array $guest_form): string
{
    $phone_country_code = isset($guest_form['phone_country_code'])
        ? normalize_checkout_phone_option_value((string) $guest_form['phone_country_code'])
        : get_checkout_default_phone_option_value();
    $phone_number = isset($guest_form['phone_number']) ? sanitize_checkout_phone_number((string) $guest_form['phone_number']) : '';
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
    return ReservationEngine::buildReservationNote($room_id, $guest_form);
}

/**
 * Load room row for checkout summaries.
 *
 * @return array<string, mixed>|null
 */
function get_checkout_room_data(int $room_id): ?array
{
    return ReservationEngine::getCheckoutRoomData($room_id);
}

/**
 * Resolve per-room guest counts for the selected rooms.
 *
 * @param array<int, array<string, mixed>> $rooms
 * @param array<string, mixed> $guest_form
 * @return array{counts: array<int, int>, errors: array<int, string>}
 */
function get_checkout_room_guest_allocations(array $rooms, int $total_guests, array $guest_form = [], bool $strict = false): array
{
    return ReservationEngine::getRoomGuestAllocations($rooms, $total_guests, $guest_form, $strict);
}

/**
 * Ensure checkout lock exists for the current session.
 */
function ensure_checkout_room_lock(int $room_id, string $checkin, string $checkout): bool
{
    return ReservationEngine::ensureRoomLock($room_id, $checkin, $checkout);
}

/**
 * Ensure checkout locks exist for all selected rooms.
 *
 * @param array<int, int> $room_ids
 */
function ensure_checkout_room_locks(array $room_ids, string $checkin, string $checkout): bool
{
    return ReservationEngine::ensureRoomLocks($room_ids, $checkin, $checkout);
}

/**
 * Extract guest form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, string>
 */
function get_checkout_guest_form_values(array $source): array
{
    return BookingValidationEngine::getCheckoutGuestFormValues($source);
}

/**
 * Validate guest form values.
 *
 * @param array<string, string> $guest_form
 * @return array<int, string>
 */
function validate_checkout_guest_form_values(array $guest_form): array
{
    return BookingValidationEngine::validateGuestForm($guest_form);
}

/**
 * Insert guest row and return guest ID.
 *
 * @param array<string, string> $guest_form
 */
function create_checkout_guest(array $guest_form): int
{
    return ReservationEngine::createGuest($guest_form);
}

/**
 * Bootstrap checkout selection from a legacy single-room request.
 *
 * @param array<string, mixed> $source
 * @return array<int, string>
 */
function maybe_bootstrap_checkout_selection_from_request(array $source): array
{
    return ReservationEngine::bootstrapCheckoutSelectionFromRequest($source);
}

/**
 * Build selected room pricing items for checkout.
 *
 * @param array<string, mixed> $context
 * @param array<string, mixed> $guest_form
 * @return array{items: array<int, array<string, mixed>>, summary: array<string, float|int|string>, errors: array<int, string>, room_guest_counts: array<int, int>}
 */
function get_checkout_selected_room_items(array $context, string $coupon_code = '', array $guest_form = [], bool $strict_room_guests = false): array
{
    return PricingEngine::buildCheckoutRoomItems($context, $coupon_code, $guest_form, $strict_room_guests);
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

    $result = ReservationEngine::continueCheckout($context, $guest_form, $coupon_code);

    if (empty($result['success'])) {
        return (array) ($result['errors'] ?? []);
    }

    $redirect_url = isset($result['redirect_url']) ? (string) $result['redirect_url'] : '';

    if ($redirect_url !== '') {
        \wp_safe_redirect($redirect_url);
        exit;
    }

    return [];
}

/**
 * Create reservations for all selected rooms.
 *
 * @param array<string, mixed>  $context
 * @param array<string, string> $guest_form
 * @param array<string, mixed>  $options
 * @return array{errors: array<int, string>, reservation_ids: array<int, int>, applied_coupon_ids: array<int, int>}
 */
function create_checkout_reservations(array $context, array $guest_form, string $coupon_code = '', array $options = []): array
{
    return ReservationEngine::createReservations($context, $guest_form, $coupon_code, $options);
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

    $result = ReservationEngine::submitCheckout($context, $guest_form, $coupon_code);

    if (empty($result['success'])) {
        return (array) ($result['errors'] ?? []);
    }

    $redirect_url = isset($result['redirect_url']) ? (string) $result['redirect_url'] : '';

    if ($redirect_url !== '') {
        \wp_safe_redirect($redirect_url);
        exit;
    }

    return [];
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
    $fixed_room_mode = \function_exists(__NAMESPACE__ . '\is_fixed_room_booking_flow')
        ? is_fixed_room_booking_flow()
        : false;
    $fixed_room_id = $fixed_room_mode && \function_exists(__NAMESPACE__ . '\get_fixed_room_booking_room_id')
        ? get_fixed_room_booking_room_id()
        : 0;
    $context = BookingValidationEngine::parseRequestContext($selection_context, true);
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
    $guest_form = BookingValidationEngine::getCheckoutGuestFormValues($guest_form_source);
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
        $room_items = PricingEngine::buildCheckoutRoomItems($context, $coupon_code, $guest_form);
    }

    if (!empty($room_items['errors'])) {
        foreach ((array) $room_items['errors'] as $room_item_error) {
            $messages[] = (string) $room_item_error;
        }
    }

    if (!empty($room_items['room_guest_counts']) && \is_array($room_items['room_guest_counts'])) {
        foreach ($room_items['room_guest_counts'] as $room_id => $room_guest_count) {
            $room_id = (int) $room_id;

            if ($room_id <= 0 || isset($guest_form['room_guests'][$room_id]['guest_count'])) {
                continue;
            }

            $guest_form['room_guests'][$room_id] = [
                'guest_count' => (string) (int) $room_guest_count,
                'first_name' => '',
                'last_name' => '',
            ];
        }
    }

    $summary_view = isset($room_items['summary']) && \is_array($room_items['summary']) ? $room_items['summary'] : [];
    $applied_coupon_code = isset($summary_view['applied_coupon']) ? \sanitize_text_field((string) $summary_view['applied_coupon']) : '';
    $coupon_input_value = $submitted_coupon_code;
    $coupon_notice = null;

    if ($applied_coupon_code !== '' && ((float) ($summary_view['discount_total'] ?? 0.0)) > 0.0) {
        $coupon_input_value = '';
    }

    if ($request_action === 'preview_coupon' || ($applied_coupon_code !== '' && $coupon_code !== '')) {
        $coupon_notice = CouponService::buildCustomerCouponNotice(
            $coupon_code !== '' ? $coupon_code : $applied_coupon_code,
            \max(
                0.0,
                (float) ($summary_view['room_subtotal'] ?? 0.0) + (float) ($summary_view['fees_total'] ?? 0.0)
            ),
            (string) ($context['checkin'] ?? ''),
            (float) ($summary_view['discount_total'] ?? 0.0)
        );
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
        'room_count' => (int) ($context['room_count'] ?? 0),
        'coupon_code' => $coupon_code,
        'coupon_input_value' => $coupon_input_value,
        'applied_coupon_code' => $applied_coupon_code,
        'coupon_notice' => $coupon_notice,
        'selected_room_count' => \count($selected_room_ids),
        'fixed_room_mode' => $fixed_room_mode,
        'fixed_room_id' => $fixed_room_id,
        'booking_url' => get_booking_context_url($selection_context, $fixed_room_id),
        'accommodation_url' => $fixed_room_mode
            ? get_booking_context_url($selection_context, $fixed_room_id)
            : get_booking_accommodation_context_url($selection_context),
        'checkout_url' => get_checkout_context_url($selection_context, $fixed_room_id),
        'country_options' => get_checkout_country_options(),
        'phone_country_code_options' => get_checkout_phone_code_options(),
    ];
}

/**
 * Enqueue shared booking-process styles for checkout.
 */
function enqueue_checkout_page_assets(): void
{
    if (!ManagedPages::isCurrentPage('page_checkout_id', 'checkout')) {
        return;
    }

    $booking_page_style_version = \defined('MUST_HOTEL_BOOKING_PATH') && \file_exists(MUST_HOTEL_BOOKING_PATH . 'assets/css/booking-page.css')
        ? (string) \filemtime(MUST_HOTEL_BOOKING_PATH . 'assets/css/booking-page.css')
        : MUST_HOTEL_BOOKING_VERSION;
    $phone_fields_script_version = \defined('MUST_HOTEL_BOOKING_PATH') && \file_exists(MUST_HOTEL_BOOKING_PATH . 'assets/js/booking-phone-fields.js')
        ? (string) \filemtime(MUST_HOTEL_BOOKING_PATH . 'assets/js/booking-phone-fields.js')
        : MUST_HOTEL_BOOKING_VERSION;

    \wp_enqueue_style(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-page.css',
        [],
        $booking_page_style_version
    );

    \wp_enqueue_script(
        'must-hotel-booking-phone-fields',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-phone-fields.js',
        [],
        $phone_fields_script_version,
        true
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_checkout_page_assets');
