<?php
declare(strict_types=1);

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Engine\PaymentRefundService;
use MustHotelBooking\Provider\Clock\ClockApiClient;
use MustHotelBooking\Provider\Clock\ClockPaymentAccountingService;
use MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService;
use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\ReservationCreateRequest;
use MustHotelBooking\Provider\ProviderManager;

/**
 * @param array<string, mixed> $readiness
 * @return array<string, mixed>
 */
function mhb_e2e_run_write_lifecycle(string $correlationId, array $readiness): array
{
    $stateFile = mhb_e2e_state_file($correlationId);
    $state = mhb_e2e_load_state($stateFile);
    $state['correlation_id'] = $correlationId;
    $state['updated_at_utc'] = \gmdate('c');

    $scenarios = [];
    $manualActions = [];
    $externalRecords = [];
    $writesPerformed = false;

    foreach (['stripe', 'pokpay'] as $gateway) {
        $readyKey = $gateway . '_ready';
        if (empty($readiness[$readyKey])) {
            $scenarios[] = mhb_e2e_scenario($gateway . '.create_fresh_checkout', 'BLOCKED', 'Prerequisites for this provider are not ready.');
            continue;
        }

        if (empty($state['gateways'][$gateway]) || !\is_array($state['gateways'][$gateway])) {
            $created = mhb_e2e_create_gateway_checkout($gateway, $correlationId);
            $scenarios[] = mhb_e2e_scenario($gateway . '.create_fresh_checkout', empty($created['success']) ? 'FAIL' : 'PASS', (string) ($created['message'] ?? ''));

            if (empty($created['success'])) {
                continue;
            }

            $state['gateways'][$gateway] = $created['state'];
            $writesPerformed = true;
            mhb_e2e_save_state($stateFile, $state);
        }

        $gatewayState = \is_array($state['gateways'][$gateway] ?? null) ? $state['gateways'][$gateway] : [];
        $externalRecords[] = mhb_e2e_record_summary($gateway, $gatewayState);
        $checkoutUrl = (string) ($gatewayState['checkout_url'] ?? '');
        $reservationId = (int) ($gatewayState['reservation_id'] ?? 0);

        $verification = mhb_e2e_verify_paid_gateway($gateway, $gatewayState);
        foreach ($verification['scenarios'] as $scenario) {
            $scenarios[] = $scenario;
        }
        $state['gateways'][$gateway] = $verification['state'];
        $gatewayState = $verification['state'];
        mhb_e2e_save_state($stateFile, $state);

        if (empty($verification['paid'])) {
            $manualActions[] = [
                'provider' => $gateway,
                'reservation_id' => $reservationId,
                'checkout_url' => $checkoutUrl,
                'action' => $gateway === 'stripe'
                    ? 'Open the Stripe Checkout URL and complete it with a Stripe test card such as 4242 4242 4242 4242, any future expiry, any CVC, and any ZIP.'
                    : 'Open the PokPay staging checkout URL and complete the staging payment using the available PokPay test/staging payment method.',
            ];
            continue;
        }

        $duplicate = $gateway === 'stripe'
            ? mhb_e2e_replay_duplicate_stripe_checkout($gatewayState)
            : mhb_e2e_replay_duplicate_pokpay_webhook($gatewayState);
        $scenarios[] = $duplicate['scenario'];
        $state['gateways'][$gateway] = $duplicate['state'];
        $gatewayState = $duplicate['state'];
        mhb_e2e_save_state($stateFile, $state);

        $cancel = mhb_e2e_cancel_reservation($gatewayState);
        $scenarios[] = $cancel['scenario'];
        $state['gateways'][$gateway] = $cancel['state'];
        $gatewayState = $cancel['state'];
        mhb_e2e_save_state($stateFile, $state);

        $refund = mhb_e2e_refund_gateway($gateway, $gatewayState);
        foreach ($refund['scenarios'] as $scenario) {
            $scenarios[] = $scenario;
        }
        $state['gateways'][$gateway] = $refund['state'];
        $gatewayState = $refund['state'];
        mhb_e2e_save_state($stateFile, $state);

        $refundDuplicate = $gateway === 'stripe'
            ? mhb_e2e_replay_duplicate_stripe_refund($gatewayState)
            : mhb_e2e_replay_duplicate_pokpay_webhook($gatewayState, true);
        $scenarios[] = $refundDuplicate['scenario'];
        $state['gateways'][$gateway] = $refundDuplicate['state'];
        mhb_e2e_save_state($stateFile, $state);
    }

    $scenarios[] = empty($readiness['clock_inbound_ready'])
        ? mhb_e2e_scenario('clock.inbound_webhook_replay', 'BLOCKED', 'Clock inbound SNS replay prerequisites are missing. This does not block outbound Clock booking/accounting verification.')
        : mhb_e2e_scenario('clock.inbound_webhook_replay', 'PASS', 'Clock inbound SNS webhook prerequisites are ready; replay is not destructive and can be run separately.');

    return [
        'external_writes_performed' => $writesPerformed,
        'scenarios' => $scenarios,
        'manual_actions' => $manualActions,
        'external_records_created' => $externalRecords,
        'state_file' => mhb_e2e_relative_path($stateFile),
    ];
}

/** @return array<string, mixed> */
function mhb_e2e_create_gateway_checkout(string $gateway, string $correlationId): array
{
    $draft = mhb_e2e_create_clock_backed_reservation($gateway, $correlationId);
    if (empty($draft['success'])) {
        return $draft;
    }

    $reservationId = (int) ($draft['reservation_id'] ?? 0);
    $amount = (float) ($draft['amount'] ?? 0.0);
    $currency = (string) ($draft['currency'] ?? MustBookingConfig::get_currency());
    $guestForm = \is_array($draft['guest_form'] ?? null) ? $draft['guest_form'] : [];
    $successUrl = \add_query_arg(
        ['reservation_ids' => $reservationId, 'payment_method' => $gateway, 'mhb_e2e' => $correlationId],
        ManagedPages::getBookingConfirmationPageUrl()
    );
    $payment = PaymentEngine::processPayment(
        $gateway,
        [$reservationId],
        $amount,
        [
            'guest_form' => $guestForm,
            'currency' => $currency,
            'success_url' => $successUrl,
            'cancel_url' => $successUrl,
        ]
    );

    if (empty($payment['success'])) {
        BookingStatusEngine::failPendingPaymentReservations([$reservationId], $gateway, 'payment_failed');
        return [
            'success' => false,
            'message' => (string) ($payment['message'] ?? 'Unable to start provider checkout.'),
        ];
    }

    $draft['checkout_url'] = (string) ($payment['checkout_url'] ?? $payment['redirect_url'] ?? '');
    $draft['provider_session_id'] = (string) ($payment['session_id'] ?? $payment['transaction_id'] ?? '');
    $draft['checkout_mode'] = (string) ($payment['checkout_mode'] ?? '');
    $draft['created_at_utc'] = \gmdate('c');

    return [
        'success' => true,
        'message' => 'Fresh ' . $gateway . ' checkout created.',
        'state' => $draft,
    ];
}

/** @return array<string, mixed> */
function mhb_e2e_create_clock_backed_reservation(string $gateway, string $correlationId): array
{
    if (ProviderManager::activeKey() !== ProviderManager::CLOCK_MODE) {
        return ['success' => false, 'message' => 'Active booking provider is not Clock.'];
    }

    $selection = mhb_e2e_find_available_selection();
    if (empty($selection['success'])) {
        return $selection;
    }

    $sessionId = 'mhbe2e' . \substr(\preg_replace('/[^A-Za-z0-9]/', '', $correlationId . $gateway) ?? '', 0, 40);
    $_COOKIE['must_hotel_booking_lock_session'] = LockEngine::normalizeSessionId($sessionId);

    \MustHotelBooking\Frontend\clear_booking_selection(false);

    $roomId = (int) ($selection['room_id'] ?? 0);
    $ratePlanId = (int) ($selection['rate_plan_id'] ?? 0);
    $context = [
        'checkin' => (string) ($selection['checkin'] ?? ''),
        'checkout' => (string) ($selection['checkout'] ?? ''),
        'guests' => 1,
        'room_count' => 1,
        'accommodation_type' => RoomCatalog::roomTypeBookingValue((int) ($selection['room_type_id'] ?? $roomId)),
        'is_valid' => true,
        'errors' => [],
    ];
    $guestForm = mhb_e2e_guest_form($correlationId, $gateway, $roomId);

    \MustHotelBooking\Frontend\add_room_to_booking_selection($roomId, $context, $ratePlanId);
    LockEngine::createLock($roomId, (string) $context['checkin'], (string) $context['checkout'], LockEngine::getOrCreateSessionId());

    $creation = ProviderManager::active()->reservations()->createReservations(
        new ReservationCreateRequest(
            $context,
            $guestForm,
            '',
            [
                'anti_abuse_surface' => 'public_confirmation',
                'anti_abuse_prechecked' => true,
                'reservation_status' => 'pending_payment',
                'payment_status' => 'pending',
                'clear_selection' => false,
                'increment_coupon_usage' => false,
                'booking_source' => 'e2e',
                'notes' => $correlationId . ' ' . $gateway . ' sandbox lifecycle test',
            ]
        )
    );

    if (!empty($creation['errors'])) {
        return ['success' => false, 'message' => \implode('; ', \array_map('strval', (array) $creation['errors']))];
    }

    $reservationId = (int) ((array) ($creation['reservation_ids'] ?? []))[0];
    $reservation = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
    if (!\is_array($reservation)) {
        return ['success' => false, 'message' => 'Local mirror reservation was not created.'];
    }

    return [
        'success' => true,
        'gateway' => $gateway,
        'reservation_id' => $reservationId,
        'booking_id' => (string) ($reservation['booking_id'] ?? ''),
        'clock_booking_id' => (string) ($reservation['provider_booking_id'] ?? ''),
        'clock_reservation_id' => (string) ($reservation['provider_reservation_id'] ?? ''),
        'amount' => \round((float) ($reservation['total_price'] ?? 0.0), 2),
        'currency' => MustBookingConfig::get_currency(),
        'checkin' => (string) ($reservation['checkin'] ?? ''),
        'checkout' => (string) ($reservation['checkout'] ?? ''),
        'room_id' => (int) ($reservation['room_id'] ?? 0),
        'assigned_room_id' => (int) ($reservation['assigned_room_id'] ?? 0),
        'rate_plan_id' => (int) ($reservation['rate_plan_id'] ?? 0),
        'guest_form' => $guestForm,
    ];
}

/** @return array<string, mixed> */
function mhb_e2e_find_available_selection(): array
{
    $categories = \array_keys(RoomCatalog::getBookingCategories());
    if (empty($categories)) {
        $categories = ['all', 'standard-rooms'];
    }

    $start = new \DateTimeImmutable(\current_time('Y-m-d'));
    for ($offset = 35; $offset <= 95; $offset++) {
        $checkin = $start->modify('+' . $offset . ' days')->format('Y-m-d');
        $checkout = $start->modify('+' . ($offset + 1) . ' days')->format('Y-m-d');
        foreach ($categories as $category) {
            $rooms = ProviderManager::active()->availability()->getAvailableRooms(new AvailabilitySearchRequest($checkin, $checkout, 1, (string) $category));
            foreach ($rooms as $room) {
                if (!\is_array($room)) {
                    continue;
                }
                $roomId = (int) ($room['id'] ?? $room['booking_room_id'] ?? 0);
                if ($roomId <= 0) {
                    continue;
                }
                $plans = ProviderManager::active()->quote()->getRoomRatePlansWithPricing($roomId, $checkin, $checkout, 1);
                foreach ($plans as $plan) {
                    $ratePlanId = (int) ($plan['id'] ?? 0);
                    $pricing = ProviderManager::active()->quote()->calculateTotal($roomId, $checkin, $checkout, 1, '', $ratePlanId);
                    if (!empty($pricing['success']) && (float) ($pricing['total_price'] ?? 0.0) > 0.0 && (int) ($pricing['guarantee_policy_id'] ?? 0) > 0) {
                        return [
                            'success' => true,
                            'checkin' => $checkin,
                            'checkout' => $checkout,
                            'room_id' => $roomId,
                            'room_type_id' => (int) ($room['room_type_id'] ?? $roomId),
                            'rate_plan_id' => $ratePlanId,
                            'amount' => \round((float) ($pricing['total_price'] ?? 0.0), 2),
                        ];
                    }
                }
            }
        }
    }

    return ['success' => false, 'message' => 'No Clock-mapped room/rate with guarantee policy was available in the future test window.'];
}

/** @return array<string, mixed> */
function mhb_e2e_verify_paid_gateway(string $gateway, array $state): array
{
    $reservationId = (int) ($state['reservation_id'] ?? 0);
    $sessionId = (string) ($state['provider_session_id'] ?? '');
    $reservation = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
    $payments = \MustHotelBooking\Engine\get_payment_repository()->getPaymentsForReservation($reservationId);
    $latestPayment = !empty($payments[0]) && \is_array($payments[0]) ? $payments[0] : [];
    $providerPaid = false;
    $providerStatus = '';

    if ($gateway === 'stripe' && $sessionId !== '') {
        $session = PaymentEngine::getStripeCheckoutSession($sessionId);
        $providerSession = \is_array($session['session'] ?? null) ? $session['session'] : [];
        $providerStatus = (string) ($providerSession['payment_status'] ?? '');
        $providerPaid = $providerStatus === 'paid';
        if (!empty($providerSession['payment_intent'])) {
            $state['payment_intent'] = (string) $providerSession['payment_intent'];
        }
        $state['stripe_session'] = mhb_e2e_public_provider_snapshot($providerSession, ['id', 'payment_status', 'status', 'payment_intent']);
    } elseif ($gateway === 'pokpay' && $sessionId !== '') {
        $order = PaymentEngine::getPokPaySdkOrder($sessionId);
        $providerStatus = (string) ($order['status'] ?? '');
        $providerPaid = \in_array(\strtoupper($providerStatus), ['CAPTURED', 'PAID', 'COMPLETED', 'REFUNDED'], true);
        $state['pokpay_order'] = ['status' => $providerStatus];
    }

    $paidRows = \array_values(\array_filter($payments, static function ($row): bool {
        return \is_array($row) && (string) ($row['status'] ?? '') === 'paid';
    }));
    $hasPaidLedger = !empty($paidRows);
    $localPaid = \is_array($reservation)
        && \in_array((string) ($reservation['status'] ?? ''), ['confirmed', 'cancelled'], true)
        && \in_array((string) ($reservation['payment_status'] ?? ''), ['paid', 'refunded'], true)
        && $hasPaidLedger;
    $paymentId = isset($latestPayment['id']) ? (int) $latestPayment['id'] : 0;
    if ($paymentId > 0) {
        $state['payment_id'] = $paymentId;
        $state['provider_payment_reference'] = (string) ($latestPayment['transaction_id'] ?? '');
    }

    $accounting = \MustHotelBooking\Engine\get_clock_folio_accounting_repository()->getForReservation($reservationId);
    $postedPayments = mhb_e2e_filter_positive_accounting($accounting);

    return [
        'paid' => $providerPaid && $localPaid,
        'state' => $state,
        'scenarios' => [
            mhb_e2e_scenario($gateway . '.provider_payment_succeeds', $providerPaid ? 'PASS' : 'BLOCKED', $providerPaid ? 'Provider reports paid/captured.' : 'Provider checkout is not paid yet. Current provider status: ' . $providerStatus),
            mhb_e2e_scenario($gateway . '.local_booking_payment_records', $localPaid ? 'PASS' : ($providerPaid ? 'FAIL' : 'BLOCKED'), $localPaid ? 'Local reservation and payment rows are paid.' : 'Local reservation/payment rows are not paid yet.'),
            mhb_e2e_scenario('clock.' . $gateway . '.positive_accounting', !empty($postedPayments) ? 'PASS' : ($localPaid ? 'FAIL' : 'BLOCKED'), !empty($postedPayments) ? 'Clock positive payment/deposit accounting row is posted.' : 'No posted Clock payment/deposit accounting row found yet.'),
        ],
    ];
}

/** @return array<string, mixed> */
function mhb_e2e_replay_duplicate_stripe_checkout(array $state): array
{
    $sessionId = (string) ($state['provider_session_id'] ?? '');
    $reservationId = (int) ($state['reservation_id'] ?? 0);
    $before = mhb_e2e_counts($reservationId);
    $session = PaymentEngine::getStripeCheckoutSession($sessionId);
    $object = \is_array($session['session'] ?? null) ? $session['session'] : [];
    $object['metadata']['reservation_ids'] = (string) $reservationId;
    $payload = [
        'id' => 'evt_mhb_e2e_checkout_' . \md5($sessionId),
        'type' => 'checkout.session.completed',
        'data' => ['object' => $object],
    ];
    $status = mhb_e2e_send_signed_stripe_event($payload);
    $after = mhb_e2e_counts($reservationId);
    $unchanged = $status === 200 && $before['paid_payments'] === $after['paid_payments'] && $before['posted_payment_accounting'] === $after['posted_payment_accounting'];
    $state['duplicate_checkout_replay_status'] = $status;

    return [
        'state' => $state,
        'scenario' => mhb_e2e_scenario('stripe.duplicate_webhook_idempotency', $unchanged ? 'PASS' : 'FAIL', 'Signed duplicate checkout webhook HTTP ' . $status . '; paid payment rows ' . $before['paid_payments'] . ' -> ' . $after['paid_payments'] . ', posted Clock payment rows ' . $before['posted_payment_accounting'] . ' -> ' . $after['posted_payment_accounting'] . '.'),
    ];
}

/** @return array<string, mixed> */
function mhb_e2e_replay_duplicate_pokpay_webhook(array $state, bool $afterRefund = false): array
{
    $reservationId = (int) ($state['reservation_id'] ?? 0);
    $orderId = (string) ($state['provider_session_id'] ?? '');
    $before = mhb_e2e_counts($reservationId);
    $request = new \WP_REST_Request('POST', '/must-hotel-booking/v1/pokpay/webhook');
    $request->set_body(\wp_json_encode(['sdkOrder' => ['id' => $orderId]]) ?: '{}');
    $request->set_header('content-type', 'application/json');
    $response = PaymentEngine::handlePokPayWebhookRequest($request);
    $status = $response->get_status();
    $after = mhb_e2e_counts($reservationId);
    $direction = $afterRefund ? 'refund' : 'payment';
    $beforeKey = $afterRefund ? 'posted_refund_accounting' : 'posted_payment_accounting';
    $unchanged = $status === 200 && $before['paid_payments'] === $after['paid_payments'] && $before[$beforeKey] === $after[$beforeKey];
    $state['duplicate_pokpay_webhook_status'] = $status;

    return [
        'state' => $state,
        'scenario' => mhb_e2e_scenario('pokpay.duplicate_' . $direction . '_webhook_idempotency', $unchanged ? 'PASS' : 'FAIL', 'Duplicate PokPay webhook HTTP ' . $status . '; paid payment rows ' . $before['paid_payments'] . ' -> ' . $after['paid_payments'] . ', posted Clock ' . $direction . ' rows ' . $before[$beforeKey] . ' -> ' . $after[$beforeKey] . '.'),
    ];
}

/** @return array<string, mixed> */
function mhb_e2e_cancel_reservation(array $state): array
{
    if (!empty($state['cancelled'])) {
        return ['state' => $state, 'scenario' => mhb_e2e_scenario('reservation.cancellation', 'PASS', 'Reservation was already cancelled in this E2E state.')];
    }

    $reservationId = (int) ($state['reservation_id'] ?? 0);
    $reservation = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
    if (!\is_array($reservation)) {
        return ['state' => $state, 'scenario' => mhb_e2e_scenario('reservation.cancellation', 'FAIL', 'Reservation not found.')];
    }

    $clock = (new ClockPaymentReconciliationService())->cancelReservation($reservationId, 'e2e_cancel', 'e2e');
    if (empty($clock['success']) && empty($clock['queued'])) {
        return ['state' => $state, 'scenario' => mhb_e2e_scenario('reservation.cancellation', 'FAIL', (string) ($clock['message'] ?? 'Clock cancellation failed.'))];
    }

    BookingStatusEngine::updateReservationStatuses([$reservationId], 'cancelled', (string) ($reservation['payment_status'] ?? 'paid'));
    $state['cancelled'] = true;
    $state['clock_cancel_result'] = !empty($clock['success']) ? 'success' : 'queued';

    return ['state' => $state, 'scenario' => mhb_e2e_scenario('reservation.cancellation', 'PASS', 'Reservation cancellation succeeded; refund remains a separate explicit action.')];
}

/** @return array<string, mixed> */
function mhb_e2e_refund_gateway(string $gateway, array $state): array
{
    if (!empty($state['refund_id'])) {
        return mhb_e2e_verify_refund_accounting($gateway, $state);
    }

    $reservationId = (int) ($state['reservation_id'] ?? 0);
    $amount = (float) ($state['amount'] ?? 0.0);
    $refund = (new PaymentRefundService())->requestRefund(
        $reservationId,
        $amount,
        [
            'currency' => (string) ($state['currency'] ?? MustBookingConfig::get_currency()),
            'refund_type' => 'e2e_explicit_refund',
            'reason' => 'E2E explicit sandbox refund ' . (string) ($state['booking_id'] ?? ''),
            'source' => 'e2e_harness',
            'require_known_provider_fee' => false,
        ]
    );

    if (empty($refund['success'])) {
        return [
            'state' => $state,
            'scenarios' => [mhb_e2e_scenario($gateway . '.refund_provider', 'FAIL', (string) ($refund['message'] ?? 'Provider refund failed.'))],
        ];
    }

    $state['refund_id'] = (int) ($refund['refund_id'] ?? 0);
    $state['provider_refund_id'] = (string) ($refund['stripe_refund_id'] ?? $refund['provider_refund_id'] ?? '');

    return mhb_e2e_verify_refund_accounting($gateway, $state);
}

/** @return array<string, mixed> */
function mhb_e2e_verify_refund_accounting(string $gateway, array $state): array
{
    $reservationId = (int) ($state['reservation_id'] ?? 0);
    $refundId = (int) ($state['refund_id'] ?? 0);
    $refund = $refundId > 0 ? \MustHotelBooking\Engine\get_refund_repository()->getRefund($refundId) : null;
    if (\is_array($refund) && (string) ($refund['clock_refund_item_id'] ?? '') === '') {
        (new ClockPaymentAccountingService())->syncRefund($refundId);
        $refund = \MustHotelBooking\Engine\get_refund_repository()->getRefund($refundId);
    }
    $refundOk = \is_array($refund) && \in_array((string) ($refund['status'] ?? ''), ['succeeded', 'refunded', 'completed'], true);
    $accounting = \MustHotelBooking\Engine\get_clock_folio_accounting_repository()->getForReservation($reservationId);
    $postedRefunds = mhb_e2e_filter_accounting($accounting, 'refund', 'posted');

    return [
        'state' => $state,
        'scenarios' => [
            mhb_e2e_scenario($gateway . '.refund_provider', $refundOk ? 'PASS' : 'FAIL', $refundOk ? 'Provider refund row is completed.' : 'Refund row is not completed.'),
            mhb_e2e_scenario('clock.' . $gateway . '.negative_accounting', !empty($postedRefunds) ? 'PASS' : 'FAIL', !empty($postedRefunds) ? 'Clock negative refund credit item is posted.' : 'No posted Clock refund accounting row found.'),
        ],
    ];
}

/** @return array<string, mixed> */
function mhb_e2e_replay_duplicate_stripe_refund(array $state): array
{
    $refundId = (string) ($state['provider_refund_id'] ?? '');
    $reservationId = (int) ($state['reservation_id'] ?? 0);
    if ($refundId === '') {
        return ['state' => $state, 'scenario' => mhb_e2e_scenario('stripe.duplicate_refund_webhook_idempotency', 'FAIL', 'Stripe refund id missing.')];
    }

    $response = PaymentEngine::performStripeApiRequest('GET', 'refunds/' . \rawurlencode($refundId));
    $object = \is_array($response['body'] ?? null) ? $response['body'] : [];
    $before = mhb_e2e_counts($reservationId);
    $status = mhb_e2e_send_signed_stripe_event([
        'id' => 'evt_mhb_e2e_refund_' . \md5($refundId),
        'type' => 'refund.updated',
        'data' => ['object' => $object],
    ]);
    $after = mhb_e2e_counts($reservationId);
    $unchanged = $status === 200 && $before['refund_rows'] === $after['refund_rows'] && $before['posted_refund_accounting'] === $after['posted_refund_accounting'];
    $state['duplicate_refund_replay_status'] = $status;

    return [
        'state' => $state,
        'scenario' => mhb_e2e_scenario('stripe.duplicate_refund_webhook_idempotency', $unchanged ? 'PASS' : 'FAIL', 'Signed duplicate refund webhook HTTP ' . $status . '; refund rows ' . $before['refund_rows'] . ' -> ' . $after['refund_rows'] . ', posted Clock refund rows ' . $before['posted_refund_accounting'] . ' -> ' . $after['posted_refund_accounting'] . '.'),
    ];
}

/** @return array<string, int> */
function mhb_e2e_counts(int $reservationId): array
{
    $payments = \MustHotelBooking\Engine\get_payment_repository()->getPaymentsForReservation($reservationId);
    $accounting = \MustHotelBooking\Engine\get_clock_folio_accounting_repository()->getForReservation($reservationId);
    $refundRows = 0;
    foreach (\MustHotelBooking\Engine\get_refund_repository()->getRefundsForReservation($reservationId) as $refund) {
        if (\is_array($refund)) {
            $refundRows++;
        }
    }

    return [
        'paid_payments' => \count(\array_filter($payments, static function ($row): bool {
            return \is_array($row) && (string) ($row['status'] ?? '') === 'paid';
        })),
        'refund_rows' => $refundRows,
        'posted_payment_accounting' => \count(mhb_e2e_filter_positive_accounting($accounting)),
        'posted_refund_accounting' => \count(mhb_e2e_filter_accounting($accounting, 'refund', 'posted')),
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function mhb_e2e_filter_accounting(array $rows, string $direction, string $status): array
{
    return \array_values(\array_filter($rows, static function ($row) use ($direction, $status): bool {
        return \is_array($row) && (string) ($row['direction'] ?? '') === $direction && (string) ($row['status'] ?? '') === $status;
    }));
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function mhb_e2e_filter_positive_accounting(array $rows): array
{
    return \array_values(\array_filter($rows, static function ($row): bool {
        return \is_array($row)
            && \in_array((string) ($row['direction'] ?? ''), ['payment', 'deposit'], true)
            && (string) ($row['status'] ?? '') === 'posted';
    }));
}

/** @param array<string, mixed> $event */
function mhb_e2e_send_signed_stripe_event(array $event): int
{
    $payload = \wp_json_encode($event, \JSON_UNESCAPED_SLASHES);
    if (!\is_string($payload)) {
        return 0;
    }
    $timestamp = (string) \time();
    $secret = PaymentEngine::getStripeWebhookSecret();
    $signature = \hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $request = new \WP_REST_Request('POST', '/must-hotel-booking/v1/stripe/webhook');
    $request->set_body($payload);
    $request->set_header('stripe-signature', 't=' . $timestamp . ',v1=' . $signature);
    $request->set_header('content-type', 'application/json');
    $response = PaymentEngine::handleStripeWebhookRequest($request);

    return $response->get_status();
}

/**
 * @param array<string, mixed> $details
 * @return array<string, mixed>
 */
function mhb_e2e_scenario(string $name, string $status, string $message, array $details = []): array
{
    return [
        'name' => $name,
        'status' => \in_array($status, ['PASS', 'FAIL', 'BLOCKED'], true) ? $status : 'FAIL',
        'message' => $message,
        'details' => $details,
    ];
}

/** @return array<string, string> */
function mhb_e2e_guest_form(string $correlationId, string $gateway, int $roomId): array
{
    $safe = \strtolower(\preg_replace('/[^a-z0-9]+/i', '-', $correlationId . '-' . $gateway) ?? 'mhb-e2e');
    return [
        'first_name' => 'MHB',
        'last_name' => 'E2E ' . \strtoupper($gateway),
        'email' => $safe . '@example.test',
        'phone_country_code' => '+355',
        'phone_number' => '690000000',
        'phone' => '+355690000000',
        'country' => 'AL',
        'special_requests' => $correlationId . ' disposable sandbox record for ' . $gateway,
        'marketing_opt_in' => '',
        'room_guest_count' => [$roomId => '1'],
        'room_guest_first_name' => [$roomId => 'MHB'],
        'room_guest_last_name' => [$roomId => 'E2E'],
    ];
}

/** @param array<string, mixed> $state @return array<string, mixed> */
function mhb_e2e_record_summary(string $gateway, array $state): array
{
    return [
        'provider' => $gateway,
        'local_reservation_id' => (int) ($state['reservation_id'] ?? 0),
        'local_booking_id' => (string) ($state['booking_id'] ?? ''),
        'clock_booking_id' => mhb_e2e_mask((string) ($state['clock_booking_id'] ?? '')),
        'clock_reservation_id' => mhb_e2e_mask((string) ($state['clock_reservation_id'] ?? '')),
        'provider_session_or_order_id' => mhb_e2e_mask((string) ($state['provider_session_id'] ?? '')),
        'payment_id' => (int) ($state['payment_id'] ?? 0),
        'refund_id' => (int) ($state['refund_id'] ?? 0),
        'provider_refund_id' => mhb_e2e_mask((string) ($state['provider_refund_id'] ?? '')),
        'amount' => (float) ($state['amount'] ?? 0.0),
        'currency' => (string) ($state['currency'] ?? ''),
        'checkin' => (string) ($state['checkin'] ?? ''),
        'checkout' => (string) ($state['checkout'] ?? ''),
    ];
}

/** @param array<string, mixed> $source @param array<int, string> $keys @return array<string, mixed> */
function mhb_e2e_public_provider_snapshot(array $source, array $keys): array
{
    $snapshot = [];
    foreach ($keys as $key) {
        if (isset($source[$key]) && \is_scalar($source[$key])) {
            $snapshot[$key] = (string) $source[$key];
        }
    }
    return $snapshot;
}

function mhb_e2e_mask(string $value): string
{
    $value = \trim($value);
    if ($value === '' || \strlen($value) <= 8) {
        return $value;
    }
    return \substr($value, 0, 4) . '...' . \substr($value, -4);
}

function mhb_e2e_state_file(string $correlationId): string
{
    $safe = \sanitize_file_name($correlationId);
    if ($safe === '') {
        $safe = 'mhb-e2e-' . \gmdate('YmdHis');
    }
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'evidence';
    if (!\is_dir($dir)) {
        \wp_mkdir_p($dir);
    }
    return $dir . DIRECTORY_SEPARATOR . $safe . '.json';
}

/** @return array<string, mixed> */
function mhb_e2e_load_state(string $path): array
{
    if (!\is_file($path)) {
        return ['gateways' => []];
    }
    $decoded = \json_decode((string) \file_get_contents($path), true);
    return \is_array($decoded) ? $decoded : ['gateways' => []];
}

/** @param array<string, mixed> $state */
function mhb_e2e_save_state(string $path, array $state): void
{
    \file_put_contents($path, \wp_json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
}

function mhb_e2e_relative_path(string $path): string
{
    $root = \dirname(__DIR__, 2);
    $root = \rtrim(\str_replace('\\', '/', $root), '/') . '/';
    $normalized = \str_replace('\\', '/', $path);
    return \strpos($normalized, $root) === 0 ? \substr($normalized, \strlen($root)) : $normalized;
}
