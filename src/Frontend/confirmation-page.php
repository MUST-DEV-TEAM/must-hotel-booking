<?php
namespace MustHotelBooking\Frontend;
use MustHotelBooking\Core\BookingPerformanceMonitor;
use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Engine\BookingAbuseProtection;
use MustHotelBooking\Engine\BookingLifecycleSyncService;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Engine\PaymentProviderFeeService;
use MustHotelBooking\Engine\ReservationEngine;
use MustHotelBooking\Engine\PaymentRefundService;
use MustHotelBooking\Engine\PaymentStatusService;
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
 * @param array<string, mixed> $reservation
 */
function get_confirmation_reservation_checkin_value(array $reservation): string
{
    foreach (['checkin', 'check_in', 'check_in_date', 'arrival', 'arrival_date', 'start_date'] as $key) {
        $value = isset($reservation[$key]) ? \trim((string) $reservation[$key]) : '';
        if ($value !== '') {
            return \sanitize_text_field($value);
        }
    }
    return '';
}
/**
 * @param array<string, mixed> $reservation
 * @return array<string, mixed>
 */
function build_confirmation_cancellation_policy_review(int $reservationId, array $reservation): array
{
    $policyDays = MustBookingConfig::get_cancellation_policy_days();
    $checkinValue = get_confirmation_reservation_checkin_value($reservation);
    $hotelContactMessage = build_confirmation_contact_hotel_message();
    $paymentRows = \MustHotelBooking\Engine\get_payment_repository()->getPaymentsForReservation($reservationId);
    $paymentState = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
    $amountPaid = isset($paymentState['amount_paid']) ? (float) $paymentState['amount_paid'] : 0.0;
    $paymentMethod = \sanitize_key((string) ($paymentState['method'] ?? ''));
    $refundBreakdown = (new PaymentProviderFeeService())->calculateDefaultRefundBreakdown(
        $paymentRows,
        $amountPaid,
        'system',
        0.0,
        \__('Online cancellation more than 21 days before check-in refunds paid amount minus the stored provider fee.', 'must-hotel-booking')
    );
    $base = [
        'reservation_id' => $reservationId,
        'eligible' => false,
        'manual_only' => true,
        'policy_days' => $policyDays,
        'days_until_checkin' => null,
        'checkin' => $checkinValue,
        'paid_amount' => \round($amountPaid, 2),
        'payment_method' => $paymentMethod,
        'provider_fee_amount' => (float) ($refundBreakdown['provider_fee_retained'] ?? 0.0),
        'provider_fee_status' => (string) ($refundBreakdown['provider_fee_status'] ?? 'unknown'),
        'refund_amount' => (float) ($refundBreakdown['final_refund_amount'] ?? 0.0),
        'refund_breakdown' => $refundBreakdown,
        'message' => '',
    ];
    if ($checkinValue === '') {
        $base['message'] = \__('We could not verify the check-in date for this reservation. Please contact the hotel to review cancellation options.', 'must-hotel-booking') . ' ' . $hotelContactMessage;
        return $base;
    }
    $checkinTimestamp = \strtotime($checkinValue);
    if ($checkinTimestamp === false) {
        $base['message'] = \__('We could not verify the check-in date for this reservation. Please contact the hotel to review cancellation options.', 'must-hotel-booking') . ' ' . $hotelContactMessage;
        return $base;
    }
    $nowTimestamp = (int) \current_time('timestamp');
    $secondsUntilCheckin = $checkinTimestamp - $nowTimestamp;
    $daysUntilCheckin = (int) \floor($secondsUntilCheckin / \DAY_IN_SECONDS);
    $isEligible = $secondsUntilCheckin >= ($policyDays * \DAY_IN_SECONDS);
    $base['days_until_checkin'] = $daysUntilCheckin;
    if (!$isEligible) {
        $base['message'] = $hotelContactMessage;
        return $base;
    }
    $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));
    $base['eligible'] = true;
    $base['manual_only'] = false;
    if (\in_array($paymentStatus, ['paid', 'partially_paid', 'partially_refunded'], true)) {
        if (!\in_array($paymentMethod, ['stripe', 'pokpay'], true)) {
            $base['manual_only'] = true;
            $base['message'] = \__('This booking has a paid balance, but automatic online cancellation is only available for supported online payments. Please contact the hotel to review the refund.', 'must-hotel-booking') . ' ' . $hotelContactMessage;
            return $base;
        }
        if ((string) ($refundBreakdown['provider_fee_status'] ?? 'unknown') !== 'known') {
            $base['manual_only'] = true;
            $base['message'] = \__('This booking needs hotel support because the payment processing fee is not known yet. The hotel must review the refund before cancellation.', 'must-hotel-booking') . ' ' . build_confirmation_hotel_contact_details_message();
            return $base;
        }
        $base['message'] = \sprintf(
            /* translators: %d: policy days. */
            \__('This reservation is eligible for online cancellation. The hotel policy allows online cancellation %d or more days before check-in. If you continue, the reservation will be cancelled and you will receive a full refund minus the payment processing fee.', 'must-hotel-booking'),
            $policyDays
        );
        return $base;
    }
    $base['message'] = \sprintf(
        /* translators: %d: policy days. */
        \__('This reservation is eligible for online cancellation. The hotel policy allows online cancellation %d or more days before check-in. No online refund is needed because this booking does not appear to have a paid online payment.', 'must-hotel-booking'),
        $policyDays
    );
    return $base;
}
/**
 * @return array{messages: array<int, string>, booking_id: string, reservation_ids: array<int, int>, payment_method_hint: string, cancellation_review: array<string, mixed>}
 */
function handle_confirmation_cancellation_post_request(): array
{
    $result = [
        'messages' => [],
        'booking_id' => '',
        'reservation_ids' => [],
        'payment_method_hint' => '',
        'cancellation_review' => [],
    ];
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    if ($requestMethod !== 'POST' || !\is_array($_POST)) {
        return $result;
    }
    $requestAction = isset($_POST['must_confirmation_action'])
        ? \sanitize_key((string) \wp_unslash($_POST['must_confirmation_action']))
        : '';
    if ($requestAction !== 'confirm_cancellation') {
        return $result;
    }
    $nonce = isset($_POST['must_cancellation_nonce']) ? (string) \wp_unslash($_POST['must_cancellation_nonce']) : '';
    if (!\wp_verify_nonce($nonce, 'must_confirm_cancellation')) {
        $result['messages'][] = \__('Security check failed. Please try again.', 'must-hotel-booking');
        return $result;
    }
    $reservationId = isset($_POST['reservation_id']) ? \absint($_POST['reservation_id']) : 0;
    $bookingId = isset($_POST['booking_id']) ? \sanitize_text_field((string) \wp_unslash($_POST['booking_id'])) : '';
    $token = isset($_POST['cancel_token']) ? \sanitize_text_field((string) \wp_unslash($_POST['cancel_token'])) : '';
    $reservation = $reservationId > 0
        ? \MustHotelBooking\Engine\get_reservation_repository()->getReservationEmailData($reservationId)
        : null;
    if (!\is_array($reservation)) {
        $result['messages'][] = \__('This cancellation request is no longer valid.', 'must-hotel-booking');
        return $result;
    }
    $result['payment_method_hint'] = (string) ($reservation['payment_method'] ?? '');
    if (
        (string) ($reservation['booking_id'] ?? '') !== $bookingId
        || !EmailEngine::isValidGuestCancellationToken($reservationId, $bookingId, (string) ($reservation['guest_email'] ?? ''), $token)
    ) {
        $result['messages'][] = \__('This cancellation request is invalid or has expired.', 'must-hotel-booking');
        return $result;
    }
    $result['booking_id'] = $bookingId;
    $result['reservation_ids'] = [$reservationId];
    $status = \sanitize_key((string) ($reservation['status'] ?? ''));
    if ($status === 'cancelled') {
        $result['messages'][] = \__('This reservation is already cancelled.', 'must-hotel-booking');
        return $result;
    }
    if (\in_array($status, ['completed', 'blocked'], true)) {
        $result['messages'][] = \__('This reservation can no longer be cancelled online.', 'must-hotel-booking');
        return $result;
    }
    $review = build_confirmation_cancellation_policy_review($reservationId, $reservation);
    $result['cancellation_review'] = $review;
    if (empty($review['eligible']) || !empty($review['manual_only'])) {
        $result['messages'][] = build_confirmation_contact_hotel_message();
        return $result;
    }
    $paymentRows = \MustHotelBooking\Engine\get_payment_repository()->getPaymentsForReservation($reservationId);
    $paymentState = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);
    $amountPaid = isset($paymentState['amount_paid']) ? (float) $paymentState['amount_paid'] : 0.0;
    $paymentMethod = \sanitize_key((string) ($paymentState['method'] ?? ''));
    $refundBreakdown = isset($review['refund_breakdown']) && \is_array($review['refund_breakdown'])
        ? $review['refund_breakdown']
        : (new PaymentProviderFeeService())->calculateDefaultRefundBreakdown(
            $paymentRows,
            $amountPaid,
            'system',
            0.0,
            \__('Guest cancellation refunds paid amount minus stored provider fee.', 'must-hotel-booking')
        );
    $refundAmount = \round((float) ($refundBreakdown['final_refund_amount'] ?? 0.0), 2);
    if ($amountPaid > 0.0) {
        if ($refundAmount <= 0.0) {
            $cancelResult = cancel_confirmation_reservation_without_refund($reservationId, $reservation);
            if (empty($cancelResult['success']) && empty($cancelResult['queued'])) {
                $result['messages'][] = isset($cancelResult['message']) && (string) $cancelResult['message'] !== ''
                    ? (string) $cancelResult['message']
                    : \__('Unable to cancel this reservation automatically. Please contact the hotel.', 'must-hotel-booking');
                return $result;
            }
            $result['messages'][] = !empty($cancelResult['queued'])
                ? \__('Your cancellation request has been queued and will be synced with the hotel system. No refund is due under the current cancellation policy.', 'must-hotel-booking')
                : \__('Your reservation has been cancelled. No refund is due under the current cancellation policy.', 'must-hotel-booking');
            return $result;
        }
        if (!\in_array($paymentMethod, ['stripe', 'pokpay'], true)) {
            $result['messages'][] = \__('This booking has a paid balance, but automatic online cancellation is only available for supported online payments. Please contact the hotel to review the refund.', 'must-hotel-booking');
            return $result;
        }
        $refundResult = (new PaymentRefundService())->requestRefund(
            $reservationId,
            $refundAmount,
            [
                'currency' => (string) ($reservation['currency'] ?? MustBookingConfig::get_currency()),
                'refund_type' => 'guest_cancellation',
                'reason' => \__('Guest cancelled inside online cancellation policy.', 'must-hotel-booking'),
                'cancel_reservation' => true,
                'source' => 'guest_cancellation_policy',
                'calculated_by' => 'system',
                'require_known_provider_fee' => true,
                'refund_breakdown' => $refundBreakdown,
            ]
        );
        if (empty($refundResult['success'])) {
            $result['messages'][] = isset($refundResult['message']) && (string) $refundResult['message'] !== ''
                ? (string) $refundResult['message']
                : \__('Unable to process the cancellation refund. Please contact the hotel.', 'must-hotel-booking');
            return $result;
        }
        if ((string) ($refundResult['status'] ?? '') === 'manual_pending') {
            $result['messages'][] = \__('The automatic refund could not be completed. Please contact the hotel to review the cancellation and refund.', 'must-hotel-booking');
            return $result;
        }
        $result['messages'][] = \__('Your reservation cancellation has been submitted and the eligible refund has been started.', 'must-hotel-booking');
        return $result;
    }
    $cancelResult = cancel_confirmation_reservation_without_refund($reservationId, $reservation);
    if (empty($cancelResult['success']) && empty($cancelResult['queued'])) {
        $result['messages'][] = isset($cancelResult['message']) && (string) $cancelResult['message'] !== ''
            ? (string) $cancelResult['message']
            : \__('Unable to cancel this reservation automatically. Please contact the hotel.', 'must-hotel-booking');
        return $result;
    }
    $result['messages'][] = !empty($cancelResult['queued'])
        ? \__('Your cancellation request has been queued and will be synced with the hotel system.', 'must-hotel-booking')
        : \__('Your reservation has been cancelled.', 'must-hotel-booking');
    return $result;
}
/**
 * @return array{messages: array<int, string>, booking_id: string, reservation_ids: array<int, int>, payment_method_hint: string, cancellation_review: array<string, mixed>}
 */
function handle_confirmation_cancellation_request(): array
{
    $postResult = handle_confirmation_cancellation_post_request();
    if (!empty($postResult['messages']) || !empty($postResult['reservation_ids']) || !empty($postResult['booking_id'])) {
        return $postResult;
    }
    $result = [
        'messages' => [],
        'booking_id' => '',
        'reservation_ids' => [],
        'payment_method_hint' => '',
        'cancellation_review' => [],
    ];
    $requestAction = isset($_GET['must_action']) ? \sanitize_key((string) \wp_unslash($_GET['must_action'])) : '';
    if (!\in_array($requestAction, ['review_cancellation', 'cancel_reservation'], true)) {
        return $result;
    }
    $reservationId = isset($_GET['reservation_id']) ? \absint($_GET['reservation_id']) : 0;
    $bookingId = isset($_GET['booking_id']) ? \sanitize_text_field((string) \wp_unslash($_GET['booking_id'])) : '';
    $token = isset($_GET['cancel_token']) ? \sanitize_text_field((string) \wp_unslash($_GET['cancel_token'])) : '';
    $reservation = $reservationId > 0 ? \MustHotelBooking\Engine\get_reservation_repository()->getReservationEmailData($reservationId) : null;
    $result['booking_id'] = '';
    $result['reservation_ids'] = [];
    if (!\is_array($reservation)) {
        $result['messages'][] = \__('This cancellation link is no longer valid.', 'must-hotel-booking');
        return $result;
    }
    $result['payment_method_hint'] = (string) ($reservation['payment_method'] ?? '');
    if (
        (string) ($reservation['booking_id'] ?? '') !== $bookingId
        || !EmailEngine::isValidGuestCancellationToken($reservationId, $bookingId, (string) ($reservation['guest_email'] ?? ''), $token)
    ) {
        $result['messages'][] = \__('This cancellation link is invalid or has expired.', 'must-hotel-booking');
        return $result;
    }
    $result['booking_id'] = $bookingId;
    $result['reservation_ids'] = [$reservationId];
    $status = \sanitize_key((string) ($reservation['status'] ?? ''));
    if ($status === 'cancelled') {
        $result['messages'][] = \__('This reservation is already cancelled.', 'must-hotel-booking');
        return $result;
    }
    if (\in_array($status, ['completed', 'blocked'], true)) {
        $result['messages'][] = \__('This reservation can no longer be cancelled online.', 'must-hotel-booking');
        return $result;
    }
    $review = build_confirmation_cancellation_policy_review($reservationId, $reservation);
    $result['cancellation_review'] = $review;
    $result['messages'][] = (string) ($review['message'] ?? '');
    return $result;
}
/**
 * @param array<string, mixed> $reservation
 * @return array{success: bool, queued: bool, message: string}
 */
function cancel_confirmation_reservation_without_refund(int $reservationId, array $reservation): array
{
    if ($reservationId <= 0) {
        return [
            'success' => false,
            'queued' => false,
            'message' => \__('Reservation not found.', 'must-hotel-booking'),
        ];
    }
    $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));
    if (
        (string) ($reservation['provider'] ?? '') === \MustHotelBooking\Provider\ProviderManager::CLOCK_MODE
        && \class_exists(\MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService::class)
    ) {
        $clockResult = (new \MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService())->cancelReservation(
            $reservationId,
            'guest_cancelled',
            'public'
        );
        if (empty($clockResult['success']) && empty($clockResult['queued'])) {
            return [
                'success' => false,
                'queued' => false,
                'message' => (string) ($clockResult['message'] ?? \__('Clock cancellation failed.', 'must-hotel-booking')),
            ];
        }
        return [
            'success' => !empty($clockResult['success']),
            'queued' => !empty($clockResult['queued']),
            'message' => (string) ($clockResult['message'] ?? ''),
        ];
    }
    $transition = (new BookingLifecycleSyncService())->applyReservationStatusTransition(
        $reservationId,
        'cancelled',
        $paymentStatus,
        [
            'source' => 'guest',
            'operation' => 'cancel_only',
            'reason' => 'guest_cancelled',
        ]
    );
    return [
        'success' => !empty($transition['success']),
        'queued' => false,
        'message' => (string) ($transition['message'] ?? ''),
    ];
}
function build_confirmation_contact_hotel_message(): string
{
    $days = MustBookingConfig::get_cancellation_policy_days();
    $hotelEmail = MustBookingConfig::get_booking_notification_email();
    $hotelPhone = MustBookingConfig::get_hotel_phone();
    $message = \sprintf(
        /* translators: %d is number of days before check-in. */
        \__('Online cancellation is available until %d days before check-in. This reservation is now inside the cancellation deadline. Please contact the hotel to review cancellation or refund options.', 'must-hotel-booking'),
        $days
    );
    if ($hotelEmail !== '') {
        $message .= ' ' . \sprintf(
            /* translators: %s is hotel email. */
            \__('Email: %s.', 'must-hotel-booking'),
            $hotelEmail
        );
    }
    if ($hotelPhone !== '') {
        $message .= ' ' . \sprintf(
            /* translators: %s is hotel phone. */
            \__('Phone: %s.', 'must-hotel-booking'),
            $hotelPhone
        );
    }
    return $message;
}
function build_confirmation_hotel_contact_details_message(): string
{
    $hotelEmail = MustBookingConfig::get_booking_notification_email();
    $hotelPhone = MustBookingConfig::get_hotel_phone();
    $message = '';
    if ($hotelEmail !== '') {
        $message .= ' ' . \sprintf(
            \__('Email: %s.', 'must-hotel-booking'),
            $hotelEmail
        );
    }
    if ($hotelPhone !== '') {
        $message .= ' ' . \sprintf(
            \__('Phone: %s.', 'must-hotel-booking'),
            $hotelPhone
        );
    }
    return \trim($message);
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
    if (PaymentEngine::clearInvalidPublicPaymentMethodDraft($flow_data)) {
        update_booking_selection_flow_data([
            'payment_method' => '',
            'pending_payment' => PaymentEngine::getEmptyPendingPaymentFlowData(),
        ]);
        $flow_data['payment_method'] = '';
        $flow_data['pending_payment'] = PaymentEngine::getEmptyPendingPaymentFlowData();
    }
    $pending_payment = PaymentEngine::normalizePendingPaymentFlowData($flow_data['pending_payment'] ?? []);
    $context = BookingPerformanceMonitor::measure(
        'booking_validation',
        static function () use ($selection_context): array {
            return BookingValidationEngine::parseRequestContext($selection_context, true);
        }
    );
    $selected_room_ids = get_booking_selected_room_ids();
    $stored_guest_form_source = isset($flow_data['guest_form']) && \is_array($flow_data['guest_form']) ? $flow_data['guest_form'] : [];
    $guest_form = BookingValidationEngine::getCheckoutGuestFormValues($stored_guest_form_source);
    $stored_billing_form = isset($flow_data['billing_form']) && \is_array($flow_data['billing_form']) ? $flow_data['billing_form'] : [];
    $billing_seed = get_confirmation_billing_form_seed($guest_form, $stored_billing_form);
    $billing_form = get_confirmation_billing_form_values(
        $request_method === 'POST' && \is_array($_POST) ? $_POST : $billing_seed,
        $billing_seed
    );
    $payment_state = BookingPerformanceMonitor::measure(
        'payment_provider_initialization',
        static function () use ($request_source, $flow_data): array {
            $validation = PaymentEngine::validatePublicCheckoutPaymentMethod($request_source, $flow_data);
            $method = (string) ($validation['method'] ?? '');
            $methods = isset($validation['methods']) && \is_array($validation['methods'])
                ? $validation['methods']
                : PaymentEngine::getCheckoutPaymentMethods();
            return [
                'success' => !empty($validation['success']),
                'method' => $method,
                'methods' => $methods,
                'cta' => PaymentEngine::getCheckoutPaymentCtaLabel($method),
                'message' => (string) ($validation['message'] ?? ''),
                'reason' => (string) ($validation['reason'] ?? ''),
            ];
        }
    );
    $payment_method = (string) $payment_state['method'];
    $payment_methods = (array) $payment_state['methods'];
    $confirmation_cta_label = (string) $payment_state['cta'];
    if (empty($payment_state['success']) && (string) ($payment_state['message'] ?? '') !== '') {
        $messages[] = (string) $payment_state['message'];
    }
    $request_action = isset($request_source['must_confirmation_action']) ? \sanitize_key((string) \wp_unslash($request_source['must_confirmation_action'])) : '';
    $submitted_coupon_code = isset($request_source['coupon_code']) ? \sanitize_text_field((string) \wp_unslash($request_source['coupon_code'])) : '';
    $persisted_coupon_code = isset($request_source['applied_coupon_code']) ? \sanitize_text_field((string) \wp_unslash($request_source['applied_coupon_code'])) : '';
    $stored_coupon_code = isset($flow_data['coupon_code']) ? \sanitize_text_field((string) $flow_data['coupon_code']) : '';
    $stored_booking_source = isset($flow_data['booking_source']) ? \sanitize_key((string) $flow_data['booking_source']) : 'website';
    $stored_booking_notes = isset($flow_data['booking_notes']) ? \sanitize_textarea_field((string) $flow_data['booking_notes']) : '';
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
    if (!empty($room_items['quote_notice'])) {
        $messages[] = (string) $room_items['quote_notice'];
    }
    if ($request_method === 'GET' && !empty($context['is_valid'])) {
        BookingAbuseProtection::markConfirmationStepStarted($context);
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
            $confirmation_guest_form = BookingValidationEngine::buildConfirmationGuestForm($guest_form, $billing_form);
            $anti_abuse_result = BookingAbuseProtection::guardSubmission(
                $context,
                $confirmation_guest_form,
                [
                    'anti_abuse_surface' => BookingAbuseProtection::SURFACE_CONFIRMATION,
                ]
            );
            if (empty($anti_abuse_result['allowed'])) {
                $messages[] = isset($anti_abuse_result['message']) && (string) $anti_abuse_result['message'] !== ''
                    ? (string) $anti_abuse_result['message']
                    : BookingAbuseProtection::getGenericFailureMessage();
            } else {
                $billing_errors = BookingValidationEngine::validateBillingForm($billing_form);
                foreach ($billing_errors as $billing_error) {
                    $messages[] = $billing_error;
                }
                if (empty($payment_state['success']) || !isset($payment_methods[$payment_method])) {
                    $messages[] = \__('Please select a valid payment method.', 'must-hotel-booking');
                }
                if (empty($billing_errors) && !empty($payment_state['success']) && isset($payment_methods[$payment_method]) && !empty($context['is_valid'])) {
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
                            $result = create_checkout_reservations(
                                $context,
                                $confirmation_guest_form,
                                $effective_coupon_code,
                                [
                                    'anti_abuse_surface' => BookingAbuseProtection::SURFACE_CONFIRMATION,
                                    'anti_abuse_prechecked' => true,
                                    'reservation_status' => (string) $payment_creation_options['reservation_status'],
                                    'payment_status' => (string) $payment_creation_options['payment_status'],
                                    'clear_selection' => (bool) $payment_creation_options['clear_selection'],
                                    'increment_coupon_usage' => (bool) $payment_creation_options['increment_coupon_usage'],
                                    'booking_source' => $stored_booking_source,
                                    'notes' => $stored_booking_notes,
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
                                    BookingStatusEngine::failPendingPaymentReservations(
                                        $reservation_ids,
                                        PaymentEngine::normalizeMethod($payment_method) === 'pokpay' ? 'pokpay' : 'stripe',
                                        'payment_failed'
                                    );
                                }
                                $messages[] = isset($payment_result['message']) && (string) $payment_result['message'] !== ''
                                    ? (string) $payment_result['message']
                                    : \__('Unable to start online checkout right now.', 'must-hotel-booking');
                            } else {
                                $pending_payment = PaymentEngine::normalizePendingPaymentFlowData([
                                    'method' => $payment_method,
                                    'reservation_ids' => $reservation_ids,
                                    'session_id' => (string) ($payment_result['session_id'] ?? $payment_result['transaction_id'] ?? ''),
                                    'checkout_url' => (string) ($payment_result['checkout_url'] ?? $payment_result['redirect_url'] ?? ''),
                                    'checkout_mode' => (string) ($payment_result['checkout_mode'] ?? ''),
                                    'expires_at' => (string) ($payment_result['expires_at'] ?? ''),
                                    'created_at' => \current_time('mysql'),
                                ]);
                                update_booking_selection_flow_data([
                                    'pending_payment' => $pending_payment,
                                ]);
                                $checkout_url = (string) ($payment_result['redirect_url'] ?? $payment_result['checkout_url'] ?? '');
                                $gateway = PaymentEngine::normalizeMethod($payment_method);
                                if ($gateway === 'stripe' && $checkout_url !== '' && PaymentEngine::isStripeCheckoutUrl($checkout_url)) {
                                    \wp_redirect($checkout_url);
                                    exit;
                                }
                                if (
                                    $gateway === 'pokpay'
                                    && !empty($payment_result['requires_redirect'])
                                    && $checkout_url !== ''
                                    && PaymentEngine::isPokPayCheckoutUrl($checkout_url)
                                ) {
                                    \wp_redirect($checkout_url);
                                    exit;
                                }
                                if ($gateway === 'pokpay' && !empty($payment_result['requires_embedded_checkout'])) {
                                    $messages[] = \__('Complete your secure PokPay card payment below.', 'must-hotel-booking');
                                } else {
                                    if ($created_new_draft) {
                                        BookingStatusEngine::failPendingPaymentReservations(
                                            $reservation_ids,
                                            $gateway === 'pokpay' ? 'pokpay' : 'stripe',
                                            'payment_failed'
                                        );
                                    }
                                    update_booking_selection_flow_data([
                                        'pending_payment' => PaymentEngine::getEmptyPendingPaymentFlowData(),
                                    ]);
                                    $pending_payment = PaymentEngine::getEmptyPendingPaymentFlowData();
                                    $messages[] = $gateway === 'pokpay'
                                        ? \__('PokPay checkout could not be started. Please try again.', 'must-hotel-booking')
                                        : \__('The online payment provider returned an invalid checkout response. Please try again.', 'must-hotel-booking');
                                }
                            }
                        }
                    } else {
                        $result = create_checkout_reservations(
                            $context,
                            $confirmation_guest_form,
                            $effective_coupon_code,
                            [
                                'anti_abuse_surface' => BookingAbuseProtection::SURFACE_CONFIRMATION,
                                'anti_abuse_prechecked' => true,
                                'reservation_status' => (string) $payment_creation_options['reservation_status'],
                                'payment_status' => (string) $payment_creation_options['payment_status'],
                                'clear_selection' => (bool) $payment_creation_options['clear_selection'],
                                'increment_coupon_usage' => (bool) $payment_creation_options['increment_coupon_usage'],
                                'booking_source' => $stored_booking_source,
                                'notes' => $stored_booking_notes,
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
        'can_confirm' => !empty($context['is_valid']) && !empty($stored_guest_form_source) && !empty($selected_room_ids) && empty($room_items['errors']) && !empty($payment_state['success']) && $payment_method !== '',
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
function build_confirmation_page_view_data(): array
{
    $cancellationResult = handle_confirmation_cancellation_request();
    $reservation_ids = !empty($cancellationResult['reservation_ids'])
        ? $cancellationResult['reservation_ids']
        : get_confirmation_reservation_ids_from_query();
    $reservations = [];
    $messages = (array) ($cancellationResult['messages'] ?? []);
    $payment_method_hint = isset($_GET['payment_method']) ? \sanitize_key((string) \wp_unslash($_GET['payment_method'])) : '';
    $stripe_return = isset($_GET['stripe_return']) ? \sanitize_key((string) \wp_unslash($_GET['stripe_return'])) : '';
    $session_id = isset($_GET['session_id']) ? \sanitize_text_field((string) \wp_unslash($_GET['session_id'])) : '';
    $pokpay_return = isset($_GET['pokpay_return']) ? \sanitize_key((string) \wp_unslash($_GET['pokpay_return'])) : '';
    $pokpay_order_id = isset($_GET['order_id']) ? \sanitize_text_field((string) \wp_unslash($_GET['order_id'])) : '';
    $requestAction = isset($_GET['must_action']) ? \sanitize_key((string) \wp_unslash($_GET['must_action'])) : '';
    $isCancellationReviewRequest = \in_array($requestAction, ['review_cancellation', 'cancel_reservation'], true);
    if ($payment_method_hint === '' && !empty($cancellationResult['payment_method_hint'])) {
        $payment_method_hint = \sanitize_key((string) $cancellationResult['payment_method_hint']);
    }
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
        if (PaymentEngine::normalizeMethod($payment_method_hint) === 'pokpay' && $pokpay_return !== '') {
            if ($pokpay_order_id === '') {
                $payments_by_reservation = \MustHotelBooking\Engine\get_payment_repository()->getPaymentsForReservationIds($reservation_ids);
                foreach ($reservation_ids as $reservation_id) {
                    $rows = isset($payments_by_reservation[$reservation_id]) && \is_array($payments_by_reservation[$reservation_id])
                        ? $payments_by_reservation[$reservation_id]
                        : [];
                    foreach ($rows as $row) {
                        if (!\is_array($row)) {
                            continue;
                        }
                        $method = isset($row['method']) ? \sanitize_key((string) $row['method']) : '';
                        $status = isset($row['status']) ? \sanitize_key((string) $row['status']) : '';
                        $transaction_id = isset($row['transaction_id']) ? \sanitize_text_field((string) $row['transaction_id']) : '';
                        if ($method === 'pokpay' && $transaction_id !== '' && \in_array($status, ['pending', 'pending_payment'], true)) {
                            $pokpay_order_id = $transaction_id;
                            break 2;
                        }
                    }
                }
            }
            if ($pokpay_return === 'success') {
                if ($pokpay_order_id !== '') {
                    $sync_result = PaymentEngine::finalizePokPayOrder($pokpay_order_id, $reservation_ids);
                    if (!empty($sync_result['success']) && isset($sync_result['state'])) {
                        if ((string) $sync_result['state'] === 'pending') {
                            $messages[] = \__('PokPay has returned, but the payment is still being finalized. Please wait a moment and refresh if needed.', 'must-hotel-booking');
                        } elseif ((string) $sync_result['state'] === 'paid') {
                            $messages[] = \__('PokPay payment confirmed.', 'must-hotel-booking');
                        }
                    } elseif (isset($sync_result['message']) && (string) $sync_result['message'] !== '') {
                        $messages[] = (string) $sync_result['message'];
                    }
                } else {
                    $messages[] = \__('PokPay returned without a payment reference. Please contact the hotel if payment was completed.', 'must-hotel-booking');
                }
            }
            if (\in_array($pokpay_return, ['cancel', 'failed', 'error'], true)) {
                BookingStatusEngine::failPendingPaymentReservations($reservation_ids, 'pokpay', 'payment_failed');
                $messages[] = \__('PokPay payment was cancelled or failed. You can review your booking and try again.', 'must-hotel-booking');
            }
        }
        $reservations = ReservationEngine::getConfirmationRowsByIds($reservation_ids);
    } else {
        $booking_id = !empty($cancellationResult['booking_id'])
            ? (string) $cancellationResult['booking_id']
            : (!$isCancellationReviewRequest && isset($_GET['booking_id'])
                ? \sanitize_text_field((string) \wp_unslash($_GET['booking_id']))
                : '');
        if ($booking_id !== '') {
            $reservations = ReservationEngine::getConfirmationRowsByBookingId($booking_id);
        }
    }
    if (!empty($reservations)) {
        $status_copy = BookingStatusEngine::getConfirmationResultCopy($reservations, $payment_method_hint);
        if (
            (
                PaymentEngine::normalizeMethod($payment_method_hint) === 'stripe'
                || PaymentEngine::normalizeMethod($payment_method_hint) === 'pokpay'
                || $stripe_return === 'success'
                || $pokpay_return === 'success'
            ) &&
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
            'cancellation_review' => isset($cancellationResult['cancellation_review']) && \is_array($cancellationResult['cancellation_review'])
                ? $cancellationResult['cancellation_review']
                : [],
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
 * @return array<string, mixed>
 */
function get_confirmation_page_view_data(): array
{
    return BookingPerformanceMonitor::measure(
        'confirmation_controller',
        static function (): array {
            return build_confirmation_page_view_data();
        }
    );
}
/**
 * Enqueue shared booking-process styles for confirmation.
 */
function enqueue_confirmation_page_assets(): void
{
    if (!ManagedPages::isCurrentPage('page_booking_confirmation_id', 'booking-confirmation')) {
        return;
    }
    $booking_page_style_version = \defined('MUST_HOTEL_BOOKING_PATH') && \file_exists(MUST_HOTEL_BOOKING_PATH . 'assets/css/booking-page.css')
        ? (string) \filemtime(MUST_HOTEL_BOOKING_PATH . 'assets/css/booking-page.css')
        : MUST_HOTEL_BOOKING_VERSION;
    $phone_fields_script_version = \defined('MUST_HOTEL_BOOKING_PATH') && \file_exists(MUST_HOTEL_BOOKING_PATH . 'assets/js/booking-phone-fields.js')
        ? (string) \filemtime(MUST_HOTEL_BOOKING_PATH . 'assets/js/booking-phone-fields.js')
        : MUST_HOTEL_BOOKING_VERSION;
    $flow_data = get_booking_selection_flow_data();
    $pending_payment = PaymentEngine::normalizePendingPaymentFlowData($flow_data['pending_payment'] ?? []);
    $is_pokpay_pending = (string) ($pending_payment['method'] ?? '') === 'pokpay'
        && (string) ($pending_payment['session_id'] ?? '') !== ''
        && !empty($pending_payment['reservation_ids'])
        && (string) ($pending_payment['checkout_mode'] ?? '') === 'embedded_sdk';
    $confirmation_dependencies = [];
    \wp_enqueue_style(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-page.css',
        [],
        $booking_page_style_version
    );
    if ($is_pokpay_pending) {
        \wp_enqueue_script(
            'must-hotel-booking-pokpay-sdk',
            PaymentEngine::getPokPayCdnUrl(),
            [],
            null,
            true
        );
        $confirmation_dependencies[] = 'must-hotel-booking-pokpay-sdk';
    }
    \wp_enqueue_script(
        'must-hotel-booking-booking-confirmation',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-confirmation.js',
        $confirmation_dependencies,
        MUST_HOTEL_BOOKING_VERSION,
        true
    );
    if ($is_pokpay_pending) {
        $guest_form = isset($flow_data['guest_form']) && \is_array($flow_data['guest_form']) ? $flow_data['guest_form'] : [];
        $billing_form = isset($flow_data['billing_form']) && \is_array($flow_data['billing_form']) ? $flow_data['billing_form'] : [];
        $locale = \strtolower((string) \get_locale());
        $pokpay_locale = \strpos($locale, 'it') === 0 ? 'it' : (\strpos($locale, 'sq') === 0 ? 'al' : 'en');
        $holder_name = \trim((string) ($billing_form['first_name'] ?? $guest_form['first_name'] ?? '') . ' ' . (string) ($billing_form['last_name'] ?? $guest_form['last_name'] ?? ''));
        $initial_state = [
            'email' => (string) ($billing_form['email'] ?? $guest_form['email'] ?? ''),
            'holdersName' => $holder_name,
            'countryCode' => \strtoupper((string) ($billing_form['country'] ?? $guest_form['country'] ?? '')),
            'address1' => (string) ($billing_form['address'] ?? ''),
            'locality' => (string) ($billing_form['city'] ?? ''),
            'administrativeArea' => (string) ($billing_form['county'] ?? ''),
            'postalCode' => (string) ($billing_form['postcode'] ?? ''),
            'phoneNumber' => (string) ($billing_form['phone_number'] ?? $guest_form['phone_number'] ?? ''),
        ];
        \wp_localize_script(
            'must-hotel-booking-booking-confirmation',
            'mustHotelBookingPokPay',
            [
                'orderId' => (string) ($pending_payment['session_id'] ?? ''),
                'reservationIds' => \array_values(\array_map('intval', (array) ($pending_payment['reservation_ids'] ?? []))),
                'env' => PaymentEngine::getPokPayApiEnvironment(),
                'locale' => $pokpay_locale,
                'initialState' => \array_filter(
                    $initial_state,
                    static function ($value): bool {
                        return \is_string($value) && \trim($value) !== '';
                    }
                ),
                'finalizeUrl' => \rest_url('must-hotel-booking/v1/pokpay/finalize'),
                'errorUrl' => \rest_url('must-hotel-booking/v1/pokpay/error'),
                'nonce' => \wp_create_nonce('wp_rest'),
                'messages' => [
                    'loading' => \__('Loading secure PokPay checkout...', 'must-hotel-booking'),
                    'processing' => \__('Confirming your payment...', 'must-hotel-booking'),
                    'pending' => \__('PokPay is still finalizing this payment. Please wait a moment and try again.', 'must-hotel-booking'),
                    'failed' => \__('Payment failed. Please check your card details or try another card.', 'must-hotel-booking'),
                    'unavailable' => \__('PokPay checkout could not load. Please refresh the page and try again.', 'must-hotel-booking'),
                ],
            ]
        );
    }
    \wp_enqueue_script(
        'must-hotel-booking-phone-fields',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-phone-fields.js',
        [],
        $phone_fields_script_version,
        true
    );
}
\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_confirmation_page_assets');
