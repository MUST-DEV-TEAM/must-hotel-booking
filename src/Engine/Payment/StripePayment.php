<?php

namespace MustHotelBooking\Engine\Payment;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\PaymentEngine;

final class StripePayment implements PaymentInterface
{
    private string $method;

    public function __construct(string $method = 'stripe')
    {
        $this->method = \sanitize_key($method) ?: 'stripe';
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

        $result = PaymentEngine::createStripeCheckoutSession(
            $reservationIds,
            $this->extractGuestForm($context),
            $amount,
            $currency,
            $this->extractCouponIds($context),
            $this->extractStripeSessionOptions($context)
        );

        if (empty($result['success'])) {
            return $result + ['method' => $this->method];
        }

        $transactionId = isset($result['session_id']) ? (string) $result['session_id'] : '';
        if ($transactionId === '') {
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'manual_review',
                'provider_attempt_created' => true,
                'reason_code' => 'provider_attempt_reference_missing',
                'message' => \__('Stripe created a checkout response without a usable session reference. Do not retry until the hotel reviews it.', 'must-hotel-booking'),
            ];
        }
        $attempt = isset($context['payment_attempt']) && \is_array($context['payment_attempt']) ? $context['payment_attempt'] : [];
        $attempt['provider_attempt_reference'] = $transactionId;
        $attempt['attempt_checkout_mode'] = 'hosted_redirect';
        $attempt['attempt_expires_at'] = (string) ($result['expires_at'] ?? '');
        if ($attempt['attempt_expires_at'] === '') {
            $attempt['attempt_expires_at'] = \gmdate('Y-m-d H:i:s', \time() + (PaymentEngine::getPendingPaymentCleanupMinutes() * 60));
        }
        $paymentRows = BookingStatusEngine::createPaymentRows($reservationIds, $this->method, 'pending', $transactionId, true, $attempt);
        if (!empty($paymentRows['failed'])) {
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'manual_review',
                'provider_attempt_created' => true,
                'provider_reference' => $transactionId,
                'session_id' => $transactionId,
                'expires_at' => (string) $attempt['attempt_expires_at'],
                'reason_code' => 'pending_attempt_persistence_failed',
                'message' => \__('Stripe checkout was created, but its local attempt identity could not be stored safely. Do not retry until the hotel reviews it.', 'must-hotel-booking'),
            ];
        }
        $checkoutUrl = isset($result['checkout_url']) ? (string) $result['checkout_url'] : '';
        if ($checkoutUrl === '' || !PaymentEngine::isStripeCheckoutUrl($checkoutUrl)) {
            $attemptRows = \MustHotelBooking\Engine\get_payment_repository()->getPaymentAttemptRows($this->method, $transactionId);
            \MustHotelBooking\Engine\get_payment_repository()->updatePaymentAttemptRows(
                \array_values(\array_filter(\array_map('intval', \array_column($attemptRows, 'id')))),
                ['attempt_status' => 'manual_review', 'attempt_failure_code' => 'provider_checkout_response_invalid']
            );
            return [
                'success' => false,
                'method' => $this->method,
                'state' => 'manual_review',
                'provider_attempt_created' => true,
                'provider_reference' => $transactionId,
                'session_id' => $transactionId,
                'expires_at' => (string) $attempt['attempt_expires_at'],
                'reason_code' => 'provider_checkout_response_invalid',
                'message' => \__('Stripe checkout was created without a safe redirect URL. Do not retry until the hotel reviews it.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'method' => $this->method,
            'reservation_ids' => $reservationIds,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'requires_redirect' => true,
            'redirect_url' => $checkoutUrl,
            'checkout_url' => $checkoutUrl,
            'transaction_id' => $transactionId,
            'session_id' => $transactionId,
            'expires_at' => isset($result['expires_at']) ? (string) $result['expires_at'] : '',
            'provider_mode' => (string) ($attempt['attempt_provider_mode'] ?? ''),
            'account_fingerprint' => (string) ($attempt['attempt_account_fingerprint'] ?? ''),
            'provider_reference' => $transactionId,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function extractStripeSessionOptions(array $context): array
    {
        $options = [];

        if (!empty($context['success_url']) && \is_scalar($context['success_url'])) {
            $options['success_url'] = \esc_url_raw((string) $context['success_url']);
        }

        if (!empty($context['cancel_url']) && \is_scalar($context['cancel_url'])) {
            $options['cancel_url'] = \esc_url_raw((string) $context['cancel_url']);
        }

        return $options;
    }
    public function refundPayment(array $reservation, float $amount, array $context = []): array
    {
        $transactionId = isset($context['transaction_id'])
            ? \sanitize_text_field((string) $context['transaction_id'])
            : '';

        if ($transactionId === '') {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('Stripe refunds require a payment intent transaction id.', 'must-hotel-booking'),
            ];
        }

        $currency = $this->resolveCurrency($context);
        $payload = [
            'payment_intent' => $transactionId,
        ];
        $amountMinor = PaymentEngine::convertAmountToStripeMinorUnits($amount, $currency);

        if ($amountMinor > 0) {
            $payload['amount'] = $amountMinor;
        }

        $response = PaymentEngine::performStripeApiRequest('POST', 'refunds', $payload);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('Unable to create the Stripe refund.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'method' => $this->method,
            'refund' => isset($response['body']) && \is_array($response['body']) ? $response['body'] : [],
        ];
    }

    public function validatePayment(array $paymentData = []): array
    {
        if (!PaymentEngine::isStripeCheckoutConfigured()) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('Stripe checkout is not configured.', 'must-hotel-booking'),
            ];
        }

        $reservationIds = $this->extractReservationIds($paymentData);

        if (isset($paymentData['reservation_ids']) && empty($reservationIds)) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('No reservations are available for Stripe checkout.', 'must-hotel-booking'),
            ];
        }

        if (isset($paymentData['amount']) && (float) $paymentData['amount'] <= 0) {
            return [
                'success' => false,
                'method' => $this->method,
                'message' => \__('Stripe requires a positive payment amount.', 'must-hotel-booking'),
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
     * @return array<int, int>
     */
    private function extractCouponIds(array $context): array
    {
        $couponIds = isset($context['coupon_ids']) && \is_array($context['coupon_ids'])
            ? $context['coupon_ids']
            : [];

        return \array_values(
            \array_unique(
                \array_filter(
                    \array_map('intval', $couponIds),
                    static function (int $couponId): bool {
                        return $couponId > 0;
                    }
                )
            )
        );
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
