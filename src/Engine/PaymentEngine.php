<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Engine\Payment\PaymentFactory;
use MustHotelBooking\Engine\Payment\PaymentInterface;

final class PaymentEngine
{
    /**
     * @return array<string, mixed>
     */
    public static function getEmptyPendingPaymentFlowData(): array
    {
        return [
            'method' => '',
            'reservation_ids' => [],
            'session_id' => '',
            'checkout_url' => '',
            'expires_at' => '',
            'created_at' => '',
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function normalizePendingPaymentFlowData($value): array
    {
        if (!\is_array($value)) {
            return self::getEmptyPendingPaymentFlowData();
        }

        $reservationIds = isset($value['reservation_ids']) && \is_array($value['reservation_ids'])
            ? \array_values(
                \array_filter(
                    \array_map('intval', $value['reservation_ids']),
                    static function (int $reservationId): bool {
                        return $reservationId > 0;
                    }
                )
            )
            : [];

        return [
            'method' => isset($value['method']) ? \sanitize_key((string) $value['method']) : '',
            'reservation_ids' => $reservationIds,
            'session_id' => isset($value['session_id']) ? \sanitize_text_field((string) $value['session_id']) : '',
            'checkout_url' => isset($value['checkout_url']) ? \esc_url_raw((string) $value['checkout_url']) : '',
            'expires_at' => isset($value['expires_at']) ? \sanitize_text_field((string) $value['expires_at']) : '',
            'created_at' => isset($value['created_at']) ? \sanitize_text_field((string) $value['created_at']) : '',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getCheckoutPaymentMethods(): array
    {
        $catalog = PaymentMethodRegistry::getCatalog();
        $enabled = PaymentMethodRegistry::getEnabled();
        $options = [];
        $preferredOrder = ['stripe', 'pay_at_hotel'];

        foreach ($preferredOrder as $method) {
            if (!\in_array($method, $enabled, true)) {
                continue;
            }

            $methodKey = \sanitize_key((string) $method);

            if (!self::isGatewayAvailable($methodKey)) {
                continue;
            }

            if (!isset($catalog[$methodKey]) || !\is_array($catalog[$methodKey])) {
                continue;
            }

            $options[$methodKey] = [
                'label' => isset($catalog[$methodKey]['label']) ? (string) $catalog[$methodKey]['label'] : $methodKey,
                'description' => isset($catalog[$methodKey]['description']) ? (string) $catalog[$methodKey]['description'] : '',
            ];
        }

        foreach ($enabled as $method) {
            $methodKey = \sanitize_key((string) $method);

            if (isset($options[$methodKey])) {
                continue;
            }

            if (!self::isGatewayAvailable($methodKey)) {
                continue;
            }

            if (!isset($catalog[$methodKey]) || !\is_array($catalog[$methodKey])) {
                continue;
            }

            $options[$methodKey] = [
                'label' => isset($catalog[$methodKey]['label']) ? (string) $catalog[$methodKey]['label'] : $methodKey,
                'description' => isset($catalog[$methodKey]['description']) ? (string) $catalog[$methodKey]['description'] : '',
            ];
        }

        if (empty($options)) {
            $options['pay_at_hotel'] = [
                'label' => \__('Pay at hotel', 'must-hotel-booking'),
                'description' => \__('Guest pays in cash at the property during check-in or check-out.', 'must-hotel-booking'),
            ];
        }

        return $options;
    }

    public static function getGateway(string $method = ''): PaymentInterface
    {
        return PaymentFactory::create($method);
    }

    public static function normalizeMethod(string $method): string
    {
        return PaymentFactory::normalizeMethod($method);
    }

    public static function normalizeCheckoutPaymentMethod(string $paymentMethod): string
    {
        $paymentMethod = \sanitize_key($paymentMethod);
        $availableMethods = \array_keys(self::getCheckoutPaymentMethods());

        if (\in_array($paymentMethod, $availableMethods, true)) {
            return $paymentMethod;
        }

        return (string) (\reset($availableMethods) ?: 'pay_at_hotel');
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $flowData
     */
    public static function getSelectedCheckoutPaymentMethod(array $source, array $flowData = []): string
    {
        $rawMethod = isset($source['payment_method']) ? (string) \wp_unslash($source['payment_method']) : '';

        if ($rawMethod === '' && isset($flowData['payment_method'])) {
            $rawMethod = (string) $flowData['payment_method'];
        }

        return self::normalizeCheckoutPaymentMethod($rawMethod);
    }

    public static function getCheckoutPaymentCtaLabel(string $paymentMethod): string
    {
        $paymentMethod = self::normalizeCheckoutPaymentMethod($paymentMethod);
        $gateway = self::normalizeMethod($paymentMethod);

        if ($gateway === 'stripe') {
            return \__('Pay now with card', 'must-hotel-booking');
        }

        return \__('Confirm reservation', 'must-hotel-booking');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCouponRuleByCode(string $couponCode): ?array
    {
        $coupon = CouponService::getCouponByCode($couponCode);

        if (!\is_array($coupon)) {
            return null;
        }

        return [
            'id' => isset($coupon['id']) ? (int) $coupon['id'] : 0,
            'code' => isset($coupon['code']) ? (string) $coupon['code'] : '',
            'name' => isset($coupon['name']) ? (string) $coupon['name'] : '',
            'enabled' => !empty($coupon['is_active']),
            'type' => ((string) ($coupon['discount_type'] ?? 'percentage')) === 'fixed' ? 'fixed' : 'percent',
            'value' => isset($coupon['discount_value']) ? (float) $coupon['discount_value'] : 0.0,
            'discount_type' => isset($coupon['discount_type']) ? (string) $coupon['discount_type'] : 'percentage',
            'discount_value' => isset($coupon['discount_value']) ? (float) $coupon['discount_value'] : 0.0,
            'minimum_booking_amount' => isset($coupon['minimum_booking_amount']) ? (float) $coupon['minimum_booking_amount'] : 0.0,
            'valid_from' => isset($coupon['valid_from']) ? (string) $coupon['valid_from'] : '',
            'valid_until' => isset($coupon['valid_until']) ? (string) $coupon['valid_until'] : '',
            'usage_limit' => isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0,
            'used_count' => isset($coupon['used_count']) ? (int) $coupon['used_count'] : 0,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getStripeEnvironmentCatalog(): array
    {
        return [
            'local' => [
                'label' => \__('Localhost', 'must-hotel-booking'),
                'description' => \__('Use for local development sites such as localhost or LocalWP domains.', 'must-hotel-booking'),
                'example' => 'http://localhost',
            ],
            'staging' => [
                'label' => \__('Staging / IP website', 'must-hotel-booking'),
                'description' => \__('Use for temporary HTTP or IP-based sites such as http://18.185.56.94/.', 'must-hotel-booking'),
                'example' => 'http://18.185.56.94/',
            ],
            'production' => [
                'label' => \__('Live website', 'must-hotel-booking'),
                'description' => \__('Use for the real HTTPS website such as https://empirebeachresort.al.', 'must-hotel-booking'),
                'example' => 'https://empirebeachresort.al',
            ],
        ];
    }

    public static function detectStripeEnvironmentFromUrl(string $url): string
    {
        $parts = \wp_parse_url($url);
        $host = isset($parts['host']) ? \strtolower((string) $parts['host']) : '';
        $scheme = isset($parts['scheme']) ? \strtolower((string) $parts['scheme']) : '';

        if ($host === '') {
            return 'production';
        }

        if (
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            $host === '::1' ||
            \substr($host, -10) === '.localhost' ||
            \substr($host, -6) === '.local' ||
            \substr($host, -5) === '.test'
        ) {
            return 'local';
        }

        if (\filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return 'staging';
        }

        if ($scheme !== 'https') {
            return 'staging';
        }

        return 'production';
    }

    public static function detectStripeEnvironmentFromSiteUrl(): string
    {
        return self::detectStripeEnvironmentFromUrl((string) \home_url('/'));
    }

    public static function normalizeStripeEnvironment(string $environment): string
    {
        $environment = \sanitize_key($environment);
        $catalog = self::getStripeEnvironmentCatalog();

        if (isset($catalog[$environment])) {
            return $environment;
        }

        return self::detectStripeEnvironmentFromSiteUrl();
    }

    public static function getActiveSiteEnvironment(): string
    {
        $settings = MustBookingConfig::get_all_settings();
        $savedEnvironment = \is_array($settings) && isset($settings['site_environment'])
            ? (string) $settings['site_environment']
            : '';

        if ($savedEnvironment === '') {
            return self::detectStripeEnvironmentFromSiteUrl();
        }

        return self::normalizeStripeEnvironment($savedEnvironment);
    }

    public static function getSiteEnvironmentLabel(string $environment = ''): string
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();
        $catalog = self::getStripeEnvironmentCatalog();

        return isset($catalog[$environment]['label']) ? (string) $catalog[$environment]['label'] : $environment;
    }

    /**
     * @return array{publishable_key: string, secret_key: string, webhook_secret: string}
     */
    public static function getStripeEnvironmentSettingKeys(string $environment): array
    {
        $environment = self::normalizeStripeEnvironment($environment);

        return [
            'publishable_key' => 'stripe_' . $environment . '_publishable_key',
            'secret_key' => 'stripe_' . $environment . '_secret_key',
            'webhook_secret' => 'stripe_' . $environment . '_webhook_secret',
        ];
    }

    /**
     * @return array{publishable_key: string, secret_key: string, webhook_secret: string}
     */
    public static function getStripeEnvironmentCredentials(string $environment = ''): array
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();
        $keys = self::getStripeEnvironmentSettingKeys($environment);
        $settings = MustBookingConfig::get_all_settings();

        return [
            'publishable_key' => \is_array($settings) && isset($settings[$keys['publishable_key']]) ? (string) $settings[$keys['publishable_key']] : '',
            'secret_key' => \is_array($settings) && isset($settings[$keys['secret_key']]) ? (string) $settings[$keys['secret_key']] : '',
            'webhook_secret' => \is_array($settings) && isset($settings[$keys['webhook_secret']]) ? (string) $settings[$keys['webhook_secret']] : '',
        ];
    }

    public static function getStripePublishableKey(): string
    {
        $credentials = self::getStripeEnvironmentCredentials();

        return isset($credentials['publishable_key']) ? (string) $credentials['publishable_key'] : '';
    }

    public static function getStripeSecretKey(): string
    {
        $credentials = self::getStripeEnvironmentCredentials();

        return isset($credentials['secret_key']) ? (string) $credentials['secret_key'] : '';
    }

    public static function getStripeWebhookSecret(): string
    {
        $credentials = self::getStripeEnvironmentCredentials();

        return isset($credentials['webhook_secret']) ? (string) $credentials['webhook_secret'] : '';
    }

    public static function isStripeCheckoutConfigured(): bool
    {
        return self::getStripePublishableKey() !== '' && self::getStripeSecretKey() !== '';
    }

    public static function getStripeCheckoutExpiryMinutes(): int
    {
        return 30;
    }

    public static function getPendingPaymentCleanupMinutes(): int
    {
        return 35;
    }

    public static function getStripeWebhookUrl(): string
    {
        return \rest_url('must-hotel-booking/v1/stripe/webhook');
    }

    /**
     * @return array{reservation_status: string, payment_status: string, clear_selection: bool, increment_coupon_usage: bool}
     */
    public static function getReservationCreationOptions(string $method): array
    {
        $gateway = self::normalizeMethod($method);

        if ($gateway === 'stripe') {
            return [
                'reservation_status' => 'pending_payment',
                'payment_status' => 'pending',
                'clear_selection' => false,
                'increment_coupon_usage' => false,
            ];
        }

        return [
            'reservation_status' => 'confirmed',
            'payment_status' => 'unpaid',
            'clear_selection' => true,
            'increment_coupon_usage' => true,
        ];
    }

    public static function isGatewayAvailable(string $method): bool
    {
        $validation = self::getGateway($method)->validatePayment();

        return !empty($validation['success']);
    }

    public static function supportsReusablePendingReservations(string $method): bool
    {
        return self::normalizeMethod($method) === 'stripe';
    }

    /**
     * @param array<int, int> $reservationIds
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function processPayment(string $method, array $reservationIds, float $amount, array $context = []): array
    {
        $payload = [
            'reservation_ids' => self::normalizeReservationIds($reservationIds),
            'method' => \sanitize_key($method),
        ];

        return self::getGateway($method)->processPayment($payload, $amount, $context);
    }

    /**
     * @param array<int, int> $reservationIds
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function refundPayment(string $method, array $reservationIds, float $amount, array $context = []): array
    {
        $payload = [
            'reservation_ids' => self::normalizeReservationIds($reservationIds),
            'method' => \sanitize_key($method),
        ];

        return self::getGateway($method)->refundPayment($payload, $amount, $context);
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public static function syncReturnSession(string $method, string $sessionId, array $reservationIds): array
    {
        if (self::normalizeMethod($method) !== 'stripe') {
            return [
                'success' => false,
                'message' => '',
            ];
        }

        $reservationIds = self::normalizeReservationIds($reservationIds);
        $sessionResponse = self::getStripeCheckoutSession($sessionId);

        if (empty($sessionResponse['success'])) {
            return [
                'success' => false,
                'message' => isset($sessionResponse['message']) ? (string) $sessionResponse['message'] : '',
            ];
        }

        $session = isset($sessionResponse['session']) && \is_array($sessionResponse['session']) ? $sessionResponse['session'] : [];
        $paymentStatus = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
        $sessionStatus = isset($session['status']) ? (string) $session['status'] : '';

        if ($paymentStatus === 'paid' && $sessionStatus === 'complete') {
            $reservationRows = get_reservation_repository()->getReservationsByIds($reservationIds);
            $shouldIncrementCouponUsage = false;

            foreach ($reservationRows as $reservationRow) {
                $status = isset($reservationRow['status']) ? (string) $reservationRow['status'] : '';

                if (!ReservationStatus::isConfirmed($status)) {
                    $shouldIncrementCouponUsage = true;
                    break;
                }
            }

            BookingStatusEngine::updateReservationStatuses($reservationIds, 'confirmed', 'paid');
            BookingStatusEngine::createPaymentRows(
                $reservationIds,
                'stripe',
                'paid',
                isset($session['payment_intent']) ? (string) $session['payment_intent'] : $sessionId
            );

            if ($shouldIncrementCouponUsage) {
                $couponMetadata = isset($session['metadata']['coupon_ids']) ? (string) $session['metadata']['coupon_ids'] : '';
                $couponIds = $couponMetadata === ''
                    ? []
                    : \array_values(
                        \array_filter(
                            \array_map('intval', \array_map('trim', \explode(',', $couponMetadata))),
                            static function (int $couponId): bool {
                                return $couponId > 0;
                            }
                        )
                    );

                unset($couponIds);
            }

            return [
                'success' => true,
                'state' => 'paid',
            ];
        }

        if ($sessionStatus === 'expired') {
            BookingStatusEngine::failPendingStripeReservations($reservationIds, 'expired');

            return [
                'success' => true,
                'state' => 'expired',
            ];
        }

        return [
            'success' => true,
            'state' => 'pending',
        ];
    }

    public static function isStripeCheckoutUrl(string $url): bool
    {
        $url = \trim($url);

        if ($url === '') {
            return false;
        }

        $parts = \wp_parse_url($url);
        $host = isset($parts['host']) ? \strtolower((string) $parts['host']) : '';
        $scheme = isset($parts['scheme']) ? \strtolower((string) $parts['scheme']) : '';

        if ($host === '' || $scheme !== 'https') {
            return false;
        }

        return $host === 'checkout.stripe.com' || $host === 'pay.stripe.com';
    }

    public static function convertAmountToStripeMinorUnits(float $amount, string $currency): int
    {
        $currency = \strtolower(\trim($currency));
        $amount = \max(0.0, $amount);
        $zeroDecimalCurrencies = [
            'bif',
            'clp',
            'djf',
            'gnf',
            'jpy',
            'kmf',
            'krw',
            'mga',
            'pyg',
            'rwf',
            'ugx',
            'vnd',
            'vuv',
            'xaf',
            'xof',
            'xpf',
        ];

        if (\in_array($currency, $zeroDecimalCurrencies, true)) {
            return (int) \round($amount);
        }

        return (int) \round($amount * 100);
    }

    /**
     * @param array<string, scalar> $body
     * @return array<string, mixed>
     */
    public static function performStripeApiRequest(string $method, string $path, array $body = []): array
    {
        $secretKey = self::getStripeSecretKey();

        if ($secretKey === '') {
            return [
                'success' => false,
                'status_code' => 0,
                'body' => [],
                'message' => \__('Stripe secret key is missing.', 'must-hotel-booking'),
            ];
        }

        $endpoint = 'https://api.stripe.com/v1/' . \ltrim($path, '/');
        $args = [
            'method' => \strtoupper($method),
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $secretKey,
            ],
        ];

        if (!empty($body)) {
            $args['body'] = $body;
        }

        $response = \wp_remote_request($endpoint, $args);

        if (\is_wp_error($response)) {
            return [
                'success' => false,
                'status_code' => 0,
                'body' => [],
                'message' => (string) $response->get_error_message(),
            ];
        }

        $statusCode = (int) \wp_remote_retrieve_response_code($response);
        $decodedBody = \json_decode((string) \wp_remote_retrieve_body($response), true);

        return [
            'success' => $statusCode >= 200 && $statusCode < 300 && \is_array($decodedBody),
            'status_code' => $statusCode,
            'body' => \is_array($decodedBody) ? $decodedBody : [],
            'message' => \is_array($decodedBody) && isset($decodedBody['error']['message'])
                ? (string) $decodedBody['error']['message']
                : '',
        ];
    }

    /**
     * @param array<int, int> $reservationIds
     * @param array<string, string> $guestForm
     * @param array<int, int> $couponIds
     * @return array<string, mixed>
     */
    public static function createStripeCheckoutSession(array $reservationIds, array $guestForm, float $totalAmount, string $currency, array $couponIds = []): array
    {
        $reservationIds = self::normalizeReservationIds($reservationIds);

        if (empty($reservationIds)) {
            return [
                'success' => false,
                'message' => \__('No reservations are available for Stripe checkout.', 'must-hotel-booking'),
            ];
        }

        $hotelName = \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::get_hotel_name()
            : \get_bloginfo('name');
        $amountMinor = self::convertAmountToStripeMinorUnits($totalAmount, $currency);

        if ($amountMinor <= 0) {
            return [
                'success' => false,
                'message' => \__('Stripe requires a positive payment amount.', 'must-hotel-booking'),
            ];
        }

        $successUrl = \add_query_arg(
            [
                'reservation_ids' => \implode(',', $reservationIds),
                'payment_method' => 'stripe',
                'stripe_return' => 'success',
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ],
            ManagedPages::getBookingConfirmationPageUrl()
        );
        $cancelUrl = \add_query_arg(
            [
                'payment_method' => 'stripe',
                'stripe_return' => 'cancel',
            ],
            ManagedPages::getBookingConfirmationPageUrl()
        );
        $expiresAt = \time() + (self::getStripeCheckoutExpiryMinutes() * 60);
        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'payment_method_types[0]' => 'card',
            'line_items[0][price_data][currency]' => \strtolower($currency),
            'line_items[0][price_data][product_data][name]' => $hotelName !== '' ? $hotelName . ' Stay' : 'Hotel Stay',
            'line_items[0][price_data][product_data][description]' => \sprintf(
                \_n('%d room reservation', '%d room reservations', \count($reservationIds), 'must-hotel-booking'),
                \count($reservationIds)
            ),
            'line_items[0][price_data][unit_amount]' => $amountMinor,
            'line_items[0][quantity]' => 1,
            'customer_email' => isset($guestForm['email']) ? (string) $guestForm['email'] : '',
            'metadata[reservation_ids]' => \implode(',', $reservationIds),
            'metadata[coupon_ids]' => \implode(',', \array_values(\array_unique(\array_map('intval', $couponIds)))),
            'metadata[source]' => 'must-hotel-booking',
            'expires_at' => (string) $expiresAt,
        ];

        $response = self::performStripeApiRequest('POST', 'checkout/sessions', $payload);

        if (empty($response['success'])) {
            return [
                'success' => false,
                'message' => isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('Unable to create the Stripe checkout session.', 'must-hotel-booking'),
            ];
        }

        $session = isset($response['body']) && \is_array($response['body']) ? $response['body'] : [];
        $sessionId = isset($session['id']) ? (string) $session['id'] : '';
        $checkoutUrl = isset($session['url']) ? (string) $session['url'] : '';

        if ($sessionId === '' || $checkoutUrl === '') {
            return [
                'success' => false,
                'message' => \__('Stripe returned an incomplete checkout session.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'session_id' => $sessionId,
            'checkout_url' => $checkoutUrl,
            'expires_at' => isset($session['expires_at']) ? \gmdate('Y-m-d H:i:s', (int) $session['expires_at']) : \gmdate('Y-m-d H:i:s', $expiresAt),
            'payment_status' => isset($session['payment_status']) ? (string) $session['payment_status'] : '',
            'status' => isset($session['status']) ? (string) $session['status'] : '',
            'payment_intent' => isset($session['payment_intent']) ? (string) $session['payment_intent'] : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getStripeCheckoutSession(string $sessionId): array
    {
        $sessionId = \trim($sessionId);

        if ($sessionId === '') {
            return [
                'success' => false,
                'message' => \__('Stripe session id is missing.', 'must-hotel-booking'),
            ];
        }

        $response = self::performStripeApiRequest('GET', 'checkout/sessions/' . \rawurlencode($sessionId));

        if (empty($response['success'])) {
            return [
                'success' => false,
                'message' => isset($response['message']) ? (string) $response['message'] : \__('Unable to retrieve Stripe session.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'session' => isset($response['body']) && \is_array($response['body']) ? $response['body'] : [],
        ];
    }

    public static function verifyStripeWebhookSignature(string $payload, string $signatureHeader, string $webhookSecret, int $tolerance = 300): bool
    {
        if ($payload === '' || $signatureHeader === '' || $webhookSecret === '') {
            return false;
        }

        $timestamp = 0;
        $signatures = [];

        foreach (\explode(',', $signatureHeader) as $part) {
            $segments = \explode('=', \trim($part), 2);

            if (\count($segments) !== 2) {
                continue;
            }

            if ($segments[0] === 't') {
                $timestamp = (int) $segments[1];
                continue;
            }

            if ($segments[0] === 'v1') {
                $signatures[] = (string) $segments[1];
            }
        }

        if ($timestamp <= 0 || empty($signatures) || \abs(\time() - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = \hash_hmac('sha256', $signedPayload, $webhookSecret);

        foreach ($signatures as $signature) {
            if (\hash_equals($expectedSignature, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<int, int>
     */
    public static function getReservationIdsFromStripeSession(array $session): array
    {
        $metadata = isset($session['metadata']) && \is_array($session['metadata']) ? $session['metadata'] : [];
        $rawIds = isset($metadata['reservation_ids']) ? (string) $metadata['reservation_ids'] : '';

        if ($rawIds === '') {
            return [];
        }

        return \array_values(
            \array_filter(
                \array_map('intval', \array_map('trim', \explode(',', $rawIds))),
                static function (int $reservationId): bool {
                    return $reservationId > 0;
                }
            )
        );
    }

    public static function cleanupExpiredPendingPaymentReservations(): void
    {
        $cutoff = \gmdate('Y-m-d H:i:s', \time() - (self::getPendingPaymentCleanupMinutes() * 60));
        $reservationIds = get_reservation_repository()->findExpiredPendingPaymentReservationIds($cutoff);

        if (empty($reservationIds)) {
            return;
        }

        BookingStatusEngine::failPendingStripeReservations($reservationIds, 'expired');
    }

    public static function registerPaymentRestRoutes(): void
    {
        \register_rest_route(
            'must-hotel-booking/v1',
            '/stripe/webhook',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'handleStripeWebhookRequest'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function registerHooks(): void
    {
        \add_action('rest_api_init', [self::class, 'registerPaymentRestRoutes']);
        \add_action(LockEngine::getCleanupCronHook(), [self::class, 'cleanupExpiredPendingPaymentReservations']);
    }

    public static function handleStripeWebhookRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = (string) $request->get_body();
        $signatureHeader = (string) $request->get_header('stripe-signature');
        $webhookSecret = self::getStripeWebhookSecret();

        if (!self::verifyStripeWebhookSignature($payload, $signatureHeader, $webhookSecret)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid Stripe signature.'], 400);
        }

        $event = \json_decode($payload, true);

        if (!\is_array($event)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid Stripe payload.'], 400);
        }

        $type = isset($event['type']) ? (string) $event['type'] : '';
        $object = isset($event['data']['object']) && \is_array($event['data']['object']) ? $event['data']['object'] : [];
        $reservationIds = self::getReservationIdsFromStripeSession($object);

        if (empty($reservationIds)) {
            return new \WP_REST_Response(['success' => true], 200);
        }

        if ($type === 'checkout.session.completed') {
            $paymentIntent = isset($object['payment_intent']) ? (string) $object['payment_intent'] : '';
            $sessionId = isset($object['id']) ? (string) $object['id'] : '';
            $couponMetadata = isset($object['metadata']['coupon_ids']) ? (string) $object['metadata']['coupon_ids'] : '';
            $couponIds = $couponMetadata === ''
                ? []
                : \array_values(
                    \array_filter(
                        \array_map('intval', \array_map('trim', \explode(',', $couponMetadata))),
                        static function (int $couponId): bool {
                            return $couponId > 0;
                        }
                    )
                );

            BookingStatusEngine::updateReservationStatuses($reservationIds, 'confirmed', 'paid');
            BookingStatusEngine::createPaymentRows($reservationIds, 'stripe', 'paid', $paymentIntent !== '' ? $paymentIntent : $sessionId);

            unset($couponIds);
        } elseif ($type === 'checkout.session.expired') {
            BookingStatusEngine::failPendingStripeReservations($reservationIds, 'expired');
        }

        return new \WP_REST_Response(['success' => true], 200);
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, int>
     */
    private static function normalizeReservationIds(array $reservationIds): array
    {
        return \array_values(
            \array_filter(
                \array_map('intval', $reservationIds),
                static function (int $reservationId): bool {
                    return $reservationId > 0;
                }
            )
        );
    }
}
