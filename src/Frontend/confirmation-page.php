<?php

namespace MustHotelBooking\Frontend;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Engine\ReservationEngine;

/**
 * Parse reservation ids from query string.
 *
 * @return array<int, int>
 */
function get_confirmation_reservation_ids_from_query(): array
{
    return ReservationEngine::getReservationIdsFromSource(\is_array($_GET) ? $_GET : []);
}

/**
 * Resolve the primary country value used for guest persistence.
 */
function get_confirmation_primary_country_code(array $billing_form): string
{
    return BookingValidationEngine::getConfirmationPrimaryCountryCode($billing_form);
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
    return BookingValidationEngine::getConfirmationBillingFormSeed($guest_form, $stored_billing_form);
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
    return BookingValidationEngine::getConfirmationBillingFormValues($source, $fallback);
}

/**
 * Validate billing information collected on confirmation.
 *
 * @param array<string, string> $billing_form
 * @return array<int, string>
 */
function validate_confirmation_billing_form_values(array $billing_form): array
{
    return BookingValidationEngine::validateBillingForm($billing_form);
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
    return BookingValidationEngine::buildConfirmationGuestForm($guest_form, $billing_form);
}

/**
 * Build status copy for confirmation result screens.
 *
 * @param array<int, array<string, mixed>> $reservations
 * @return array<string, string>
 */
function get_confirmation_result_copy(array $reservations, string $payment_method_hint = ''): array
{
    return BookingStatusEngine::getConfirmationResultCopy($reservations, $payment_method_hint);
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
    $pending_payment = PaymentEngine::normalizePendingPaymentFlowData($flow_data['pending_payment'] ?? []);
    $context = BookingValidationEngine::parseRequestContext($selection_context, true);
    $selected_room_ids = get_booking_selected_room_ids();
    $stored_guest_form_source = isset($flow_data['guest_form']) && \is_array($flow_data['guest_form']) ? $flow_data['guest_form'] : [];
    $guest_form = BookingValidationEngine::getCheckoutGuestFormValues($stored_guest_form_source);
    $stored_billing_form = isset($flow_data['billing_form']) && \is_array($flow_data['billing_form']) ? $flow_data['billing_form'] : [];
    $billing_seed = get_confirmation_billing_form_seed($guest_form, $stored_billing_form);
    $billing_form = get_confirmation_billing_form_values(
        $request_method === 'POST' && \is_array($_POST) ? $_POST : $billing_seed,
        $billing_seed
    );
    $payment_method = PaymentEngine::getSelectedCheckoutPaymentMethod($request_source, $flow_data);
    $payment_methods = PaymentEngine::getCheckoutPaymentMethods();
    $confirmation_cta_label = PaymentEngine::getCheckoutPaymentCtaLabel($payment_method);
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
            $billing_errors = BookingValidationEngine::validateBillingForm($billing_form);

            foreach ($billing_errors as $billing_error) {
                $messages[] = $billing_error;
            }

            if (!isset($payment_methods[$payment_method])) {
                $messages[] = \__('Please select a valid payment method.', 'must-hotel-booking');
            }

            if (empty($billing_errors) && isset($payment_methods[$payment_method]) && !empty($context['is_valid'])) {
                $confirmation_guest_form = BookingValidationEngine::buildConfirmationGuestForm($guest_form, $billing_form);
                $effective_coupon_code = $applied_coupon_code !== '' ? $applied_coupon_code : $coupon_code;
                $payment_creation_options = PaymentEngine::getReservationCreationOptions($payment_method);
                $total_amount = isset($summary['total_price']) ? (float) $summary['total_price'] : 0.0;
                $currency = \class_exists(MustBookingConfig::class) ? MustBookingConfig::get_currency() : 'USD';
                update_booking_selection_flow_data([
                    'guest_form' => $confirmation_guest_form,
                    'billing_form' => $billing_form,
                    'coupon_code' => $effective_coupon_code,
                    'payment_method' => $payment_method,
                ]);

                if (PaymentEngine::supportsReusablePendingReservations($payment_method)) {
                    $reservation_ids = [];
                    $created_new_draft = false;
                    $stripe_coupon_ids = [];

                    if (
                        !empty($pending_payment['reservation_ids']) &&
                        BookingStatusEngine::areReusablePendingPaymentReservations((array) $pending_payment['reservation_ids'])
                    ) {
                        $reservation_ids = \array_map('intval', (array) $pending_payment['reservation_ids']);
                    } else {
                        $result = ReservationEngine::createReservations(
                            $context,
                            $confirmation_guest_form,
                            $effective_coupon_code,
                            [
                                'reservation_status' => (string) $payment_creation_options['reservation_status'],
                                'payment_status' => (string) $payment_creation_options['payment_status'],
                                'clear_selection' => (bool) $payment_creation_options['clear_selection'],
                                'increment_coupon_usage' => (bool) $payment_creation_options['increment_coupon_usage'],
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

                    if (empty($stripe_coupon_ids) && $effective_coupon_code !== '') {
                        $coupon_rule = PaymentEngine::getCouponRuleByCode($effective_coupon_code);

                        if (\is_array($coupon_rule) && !empty($coupon_rule['id'])) {
                            $stripe_coupon_ids[] = (int) $coupon_rule['id'];
                        }
                    }

                    if (!empty($reservation_ids) && empty($messages)) {
                        $payment_result = PaymentEngine::processPayment(
                            $payment_method,
                            $reservation_ids,
                            $total_amount,
                            [
                                'guest_form' => $confirmation_guest_form,
                                'currency' => $currency,
                                'coupon_ids' => $stripe_coupon_ids,
                            ]
                        );

                        if (empty($payment_result['success'])) {
                            if ($created_new_draft) {
                                BookingStatusEngine::failPendingStripeReservations($reservation_ids, 'payment_failed');
                            }

                            $messages[] = isset($payment_result['message']) && (string) $payment_result['message'] !== ''
                                ? (string) $payment_result['message']
                                : \__('Unable to start Stripe checkout right now.', 'must-hotel-booking');
                        } else {
                            update_booking_selection_flow_data([
                                'pending_payment' => [
                                    'method' => $payment_method,
                                    'reservation_ids' => $reservation_ids,
                                    'session_id' => (string) ($payment_result['session_id'] ?? $payment_result['transaction_id'] ?? ''),
                                    'checkout_url' => (string) ($payment_result['checkout_url'] ?? $payment_result['redirect_url'] ?? ''),
                                    'expires_at' => (string) ($payment_result['expires_at'] ?? ''),
                                    'created_at' => \current_time('mysql'),
                                ],
                            ]);

                            $stripe_checkout_url = (string) ($payment_result['redirect_url'] ?? '');

                            if (
                                $stripe_checkout_url !== ''
                                && PaymentEngine::isStripeCheckoutUrl($stripe_checkout_url)
                            ) {
                                \wp_redirect($stripe_checkout_url);
                                exit;
                            }

                            if ($created_new_draft) {
                                BookingStatusEngine::failPendingStripeReservations($reservation_ids, 'payment_failed');
                            }

                            update_booking_selection_flow_data([
                                'pending_payment' => PaymentEngine::getEmptyPendingPaymentFlowData(),
                            ]);

                            $messages[] = \__('Stripe returned an invalid checkout URL. Please try again.', 'must-hotel-booking');
                        }
                    }
                } else {
                    $result = ReservationEngine::createReservations(
                        $context,
                        $confirmation_guest_form,
                        $effective_coupon_code,
                        [
                            'reservation_status' => (string) $payment_creation_options['reservation_status'],
                            'payment_status' => (string) $payment_creation_options['payment_status'],
                            'clear_selection' => (bool) $payment_creation_options['clear_selection'],
                            'increment_coupon_usage' => (bool) $payment_creation_options['increment_coupon_usage'],
                        ]
                    );

                    if (!empty($result['errors'])) {
                        foreach ((array) $result['errors'] as $result_error) {
                            $messages[] = (string) $result_error;
                        }
                    } else {
                        $reservation_ids = \array_map('intval', (array) ($result['reservation_ids'] ?? []));
                        $payment_result = PaymentEngine::processPayment(
                            $payment_method,
                            $reservation_ids,
                            $total_amount,
                            [
                                'guest_form' => $confirmation_guest_form,
                                'currency' => $currency,
                            ]
                        );

                        if (empty($payment_result['success'])) {
                            $messages[] = isset($payment_result['message']) && (string) $payment_result['message'] !== ''
                                ? (string) $payment_result['message']
                                : \__('Unable to process the selected payment method right now.', 'must-hotel-booking');
                        } else {
                            update_booking_selection_flow_data([
                                'pending_payment' => PaymentEngine::getEmptyPendingPaymentFlowData(),
                            ]);

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
    }

    $synced_guest_form = BookingValidationEngine::buildConfirmationGuestForm($guest_form, $billing_form);

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
        if (PaymentEngine::normalizeMethod($payment_method_hint) === 'stripe' && $session_id !== '') {
            $sync_result = PaymentEngine::syncReturnSession($payment_method_hint, $session_id, $reservation_ids);

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

        $reservations = ReservationEngine::getConfirmationRowsByIds($reservation_ids);
    } else {
        $booking_id = isset($_GET['booking_id']) ? \sanitize_text_field((string) \wp_unslash($_GET['booking_id'])) : '';

        if ($booking_id !== '') {
            $reservations = ReservationEngine::getConfirmationRowsByBookingId($booking_id);
        }
    }

    if (!empty($reservations)) {
        $status_copy = BookingStatusEngine::getConfirmationResultCopy($reservations, $payment_method_hint);

        if (
            (PaymentEngine::normalizeMethod($payment_method_hint) === 'stripe' || $stripe_return === 'success') &&
            !empty($reservations) &&
            \function_exists(__NAMESPACE__ . '\clear_booking_selection')
        ) {
            foreach ($reservations as $reservation) {
                $status = isset($reservation['status']) ? \sanitize_key((string) ($reservation['status'] ?? '')) : '';

                if (ReservationStatus::isConfirmed($status)) {
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
    if (!ManagedPages::isCurrentPage('page_booking_confirmation_id', 'booking-confirmation')) {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-page.css',
        [],
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
