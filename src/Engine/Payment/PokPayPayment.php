<?php
namespace MustHotelBooking\Engine\Payment;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\PaymentEngine;
final class PokPayPayment implements PaymentInterface
{
    private string $method;
    public function __construct(string $method = 'pokpay')
    {
        $this->method = \sanitize_key($method) ?: 'pokpay';
    }
    public function processPayment(array $reservation, float $amount, array $context = []): array
    {
        $reservationIds = $this->extractReservationIds($reservation);
        $currency = $this->resolveCurrency($context);
        $validation = $this->validatePayment([
            'reservation_ids' => $reservationIds,
            'amount' => $amount,
            'currency' => $currency,
        ]);
        if (empty($validation['success'])) {
            return $validation + ['method' => $this->method];
        }
        $checkoutMode = PaymentEngine::getPokPayCheckoutMode();
        $attempt = isset($context['payment_attempt']) && \is_array($context['payment_attempt']) ? $context['payment_attempt'] : [];
        $provisionalReference = 'pokpay-preorder-' . \wp_generate_uuid4();
        $attempt['provider_attempt_reference'] = $provisionalReference;
        $attempt['attempt_checkout_mode'] = $checkoutMode;
        $attempt['attempt_expires_at'] = \gmdate('Y-m-d H:i:s', \time() + (PaymentEngine::getPendingPaymentCleanupMinutes() * 60));
        $paymentRows = BookingStatusEngine::createPaymentRows($reservationIds, $this->method, 'pending', $provisionalReference, true, $attempt);
        if (!empty($paymentRows['failed'])) {
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'integrity_error',
                'checkout_mode' => $checkoutMode,
                'expires_at' => (string) $attempt['attempt_expires_at'],
                'reason_code' => 'pending_attempt_persistence_failed',
                'message' => \__('PokPay checkout was blocked because its local attempt identity could not be stored safely.', 'must-hotel-booking'),
            ];
        }
        $preflight = PaymentEngine::validateReusablePendingPaymentAttempt(
            ['session_id' => $provisionalReference, 'reservation_ids' => $reservationIds],
            $this->method,
            $amount,
            $currency,
            'website_online_pokpay'
        );
        if (empty($preflight['allowed'])) {
            $attemptRows = \MustHotelBooking\Engine\get_payment_repository()->getPaymentAttemptRows($this->method, $provisionalReference);
            \MustHotelBooking\Engine\get_payment_repository()->updatePaymentAttemptRows(
                \array_values(\array_filter(\array_map('intval', \array_column($attemptRows, 'id')))),
                ['attempt_status' => 'superseded', 'attempt_failure_code' => 'pending_attempt_preflight_invalid']
            );
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'integrity_error',
                'checkout_mode' => $checkoutMode,
                'expires_at' => (string) $attempt['attempt_expires_at'],
                'reason_code' => 'pending_attempt_preflight_invalid',
                'message' => \__('PokPay checkout was blocked because its immutable payment bindings could not be reread safely.', 'must-hotel-booking'),
            ];
        }
        $result = PaymentEngine::createPokPaySdkOrder(
            $reservationIds,
            $this->extractGuestForm($context),
            $amount,
            $currency
        );
        if (empty($result['success'])) {
            return $result + ['method' => $this->method];
        }
        $orderId = isset($result['order_id'])
            ? (string) $result['order_id']
            : (isset($result['transaction_id']) ? (string) $result['transaction_id'] : '');
        if ($orderId === '') {
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'manual_review',
                'provider_attempt_created' => true,
                'reason_code' => 'provider_attempt_reference_missing',
                'message' => \__('PokPay created an order response without a usable reference. Do not retry until the hotel reviews it.', 'must-hotel-booking'),
            ];
        }
        $attempt['attempt_expires_at'] = (string) ($result['expires_at'] ?? $attempt['attempt_expires_at']);
        $paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $attemptRows = $paymentRepository->getPaymentAttemptRows($this->method, $provisionalReference);
        $paymentIds = \array_values(\array_filter(\array_map('intval', \array_column($attemptRows, 'id'))));
        if (empty($paymentIds) || !$paymentRepository->updatePaymentAttemptRows($paymentIds, [
            'transaction_id' => $orderId,
            'provider_attempt_reference' => $orderId,
            'attempt_expires_at' => (string) $attempt['attempt_expires_at'],
        ])) {
            $paymentRepository->updatePaymentAttemptRows($paymentIds, [
                'attempt_status' => 'manual_review',
                'attempt_failure_code' => 'provider_attempt_rebind_failed',
            ]);
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'manual_review',
                'provider_attempt_created' => true,
                'provider_reference' => $orderId,
                'session_id' => $orderId,
                'checkout_mode' => $checkoutMode,
                'expires_at' => (string) $attempt['attempt_expires_at'],
                'reason_code' => 'provider_attempt_rebind_failed',
                'message' => \__('PokPay checkout was created, but its provider reference could not be bound locally. Do not retry until the hotel reviews it.', 'must-hotel-booking'),
            ];
        }
        $rebound = PaymentEngine::validateReusablePendingPaymentAttempt(
            ['session_id' => $orderId, 'reservation_ids' => $reservationIds],
            $this->method,
            $amount,
            $currency,
            'website_online_pokpay'
        );
        if (empty($rebound['allowed'])) {
            $reboundRows = $paymentRepository->getPaymentAttemptRows($this->method, $orderId);
            $paymentRepository->updatePaymentAttemptRows(
                \array_values(\array_filter(\array_map('intval', \array_column($reboundRows, 'id')))),
                ['attempt_status' => 'manual_review', 'attempt_failure_code' => 'pending_attempt_rebind_invalid']
            );
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'manual_review',
                'provider_attempt_created' => true,
                'provider_reference' => $orderId,
                'session_id' => $orderId,
                'checkout_mode' => $checkoutMode,
                'expires_at' => (string) $attempt['attempt_expires_at'],
                'reason_code' => 'pending_attempt_rebind_invalid',
                'message' => \__('PokPay checkout was created, but its immutable payment bindings could not be reread safely. Do not retry until the hotel reviews it.', 'must-hotel-booking'),
            ];
        }
        $checkoutUrl = isset($result['checkout_url'])
            ? (string) $result['checkout_url']
            : (isset($result['redirect_url']) ? (string) $result['redirect_url'] : '');
        if ($checkoutMode === 'sdk_confirm_url_redirect' && ($checkoutUrl === '' || !PaymentEngine::isPokPayCheckoutUrl($checkoutUrl))) {
            $attemptRows = \MustHotelBooking\Engine\get_payment_repository()->getPaymentAttemptRows($this->method, $orderId);
            \MustHotelBooking\Engine\get_payment_repository()->updatePaymentAttemptRows(
                \array_values(\array_filter(\array_map('intval', \array_column($attemptRows, 'id')))),
                ['attempt_status' => 'manual_review', 'attempt_failure_code' => 'provider_checkout_response_invalid']
            );
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'manual_review',
                'provider_attempt_created' => true,
                'provider_reference' => $orderId,
                'session_id' => $orderId,
                'checkout_mode' => $checkoutMode,
                'expires_at' => (string) $attempt['attempt_expires_at'],
                'reason_code' => 'provider_checkout_response_invalid',
                'message' => \__('PokPay checkout was created without a safe confirm URL. Do not retry until the hotel reviews it.', 'must-hotel-booking'),
            ];
        }
        if ($checkoutMode === 'sdk_confirm_url_redirect') {
            return [
                'success' => true,
                'method' => $this->method,
                'reservation_ids' => $reservationIds,
                'status' => 'pending_payment',
                'payment_status' => 'pending',
                'requires_redirect' => true,
                'requires_embedded_checkout' => false,
                'redirect_url' => $checkoutUrl,
                'checkout_url' => $checkoutUrl,
                'checkout_mode' => $checkoutMode,
                'transaction_id' => $orderId,
                'session_id' => $orderId,
                'order_id' => $orderId,
                'expires_at' => isset($result['expires_at']) ? (string) $result['expires_at'] : '',
                'provider_mode' => (string) ($attempt['attempt_provider_mode'] ?? ''),
                'account_fingerprint' => (string) ($attempt['attempt_account_fingerprint'] ?? ''),
                'provider_reference' => $orderId,
            ];
        }
        return [
            'success' => true,
            'method' => $this->method,
            'reservation_ids' => $reservationIds,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'requires_redirect' => false,
            'requires_embedded_checkout' => true,
            'redirect_url' => '',
            'checkout_url' => '',
            'checkout_mode' => $checkoutMode,
            'transaction_id' => $orderId,
            'session_id' => $orderId,
            'order_id' => $orderId,
            'expires_at' => isset($result['expires_at']) ? (string) $result['expires_at'] : '',
            'provider_mode' => (string) ($attempt['attempt_provider_mode'] ?? ''),
            'account_fingerprint' => (string) ($attempt['attempt_account_fingerprint'] ?? ''),
            'provider_reference' => $orderId,
        ];
    }
    public function refundPayment(array $reservation, float $amount, array $context = []): array
    {
        $orderId = isset($context['transaction_id'])
            ? \sanitize_text_field((string) $context['transaction_id'])
            : '';
        if ($orderId === '') {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('PokPay refunds require a PokPay order reference.', 'must-hotel-booking'),
            ];
        }
        $currency = $this->resolveCurrency($context);
        $reason = isset($context['reason']) ? \sanitize_text_field((string) $context['reason']) : '';
        $fullRefund = !isset($context['full_refund']) || !empty($context['full_refund']);
        $response = PaymentEngine::refundPokPaySdkOrder($orderId, $amount, $currency, $reason, $fullRefund);
        if (empty($response['success'])) {
            return [
                'success' => false,
                'method' => $this->method,
                'manual_fallback' => !empty($response['manual_fallback']),
                'message' => isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('Unable to create the PokPay refund.', 'must-hotel-booking'),
            ];
        }
        return $response + [
            'method' => $this->method,
        ];
    }
    public function validatePayment(array $paymentData = []): array
    {
        if (!PaymentEngine::isPokPayConfigured()) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('PokPay checkout is not configured.', 'must-hotel-booking'),
            ];
        }
        $credentialState = PaymentEngine::getPokPayCredentialState();
        if ((string) ($credentialState['status'] ?? '') === 'unverified') {
            $credentialState = PaymentEngine::verifyPokPayCredentials();
        }
        if (\in_array((string) ($credentialState['status'] ?? ''), ['missing', 'rejected', 'malformed'], true)) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('PokPay checkout is disabled because the active credentials were rejected or are malformed. Ask an administrator to verify the PokPay configuration.', 'must-hotel-booking'),
            ];
        }
        $reservationIds = $this->extractReservationIds($paymentData);
        if (isset($paymentData['reservation_ids']) && empty($reservationIds)) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('No reservations are available for PokPay checkout.', 'must-hotel-booking'),
            ];
        }
        if (isset($paymentData['amount']) && (float) $paymentData['amount'] <= 0) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('PokPay requires a positive payment amount.', 'must-hotel-booking'),
            ];
        }
        return [
            'success' => true,
            'method' => $this->method,
        ];
    }
    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function extractReservationIds(array $data): array
    {
        $reservationIds = isset($data['reservation_ids']) && \is_array($data['reservation_ids'])
            ? $data['reservation_ids']
            : [];
        return \array_values(
            \array_filter(
                \array_map('intval', $reservationIds),
                static function (int $reservationId): bool {
                    return $reservationId > 0;
                }
            )
        );
    }
    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function extractGuestForm(array $context): array
    {
        $guestForm = isset($context['guest_form']) && \is_array($context['guest_form'])
            ? $context['guest_form']
            : [];
        $normalized = [];
        foreach ($guestForm as $key => $value) {
            if (!\is_string($key) || (\is_array($value) && $key !== 'room_guests')) {
                continue;
            }
            if (\is_scalar($value)) {
                $normalized[$key] = (string) $value;
            }
        }
        return $normalized;
    }
    /**
     * @param array<string, mixed> $context
     */
    private function resolveCurrency(array $context): string
    {
        $currency = isset($context['currency'])
            ? \sanitize_text_field((string) $context['currency'])
            : '';
        if ($currency !== '') {
            return \strtoupper($currency);
        }
        return \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::get_currency()
            : 'USD';
    }
}
