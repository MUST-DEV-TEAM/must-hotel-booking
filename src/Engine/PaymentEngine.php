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
        $preferredOrder = ['pokpay', 'stripe', 'pay_at_hotel'];

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

        if ($paymentMethod === 'clock_pms') {
            return \__('Confirm in Clock PMS', 'must-hotel-booking');
        }

        if ($gateway === 'stripe') {
            return \__('Pay now with card', 'must-hotel-booking');
        }

        if ($gateway === 'pokpay') {
            return \__('Pay now with PokPay', 'must-hotel-booking');
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

    /**
     * @return array{merchant_id: string, key_id: string, key_secret: string}
     */
    public static function getPokPayEnvironmentSettingKeys(string $environment): array
    {
        $environment = self::normalizeStripeEnvironment($environment);

        return [
            'merchant_id' => 'pokpay_' . $environment . '_merchant_id',
            'key_id' => 'pokpay_' . $environment . '_key_id',
            'key_secret' => 'pokpay_' . $environment . '_key_secret',
        ];
    }

    /**
     * @return array{merchant_id: string, key_id: string, key_secret: string}
     */
    public static function getPokPayEnvironmentCredentials(string $environment = ''): array
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();
        $keys = self::getPokPayEnvironmentSettingKeys($environment);
        $settings = MustBookingConfig::get_all_settings();

        return [
            'merchant_id' => \is_array($settings) && isset($settings[$keys['merchant_id']]) ? (string) $settings[$keys['merchant_id']] : '',
            'key_id' => \is_array($settings) && isset($settings[$keys['key_id']]) ? (string) $settings[$keys['key_id']] : '',
            'key_secret' => \is_array($settings) && isset($settings[$keys['key_secret']]) ? (string) $settings[$keys['key_secret']] : '',
        ];
    }

    public static function getPokPayApiEnvironment(string $environment = ''): string
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();

        return $environment === 'production' ? 'production' : 'staging';
    }

    public static function getPokPayBaseUrl(string $environment = ''): string
    {
        return self::getPokPayApiEnvironment($environment) === 'production'
            ? 'https://api.pokpay.io'
            : 'https://api-staging.pokpay.io';
    }

    public static function getPokPayCdnUrl(): string
    {
        return 'https://static.pokpay.io/public/dist/pokpayments/pok-payment.js';
    }

    public static function isPokPayConfigured(): bool
    {
        $credentials = self::getPokPayEnvironmentCredentials();

        return $credentials['merchant_id'] !== '' && $credentials['key_id'] !== '' && $credentials['key_secret'] !== '';
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

        if ($gateway === 'stripe' || $gateway === 'pokpay') {
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
        return \in_array(self::normalizeMethod($method), ['stripe', 'pokpay'], true);
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

            if (\class_exists(\MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService::class)) {
                (new \MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService())->reconcilePaymentSucceeded(
                    $reservationIds,
                    'stripe',
                    isset($session['payment_intent']) ? (string) $session['payment_intent'] : $sessionId
                );
            }

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
        return self::convertAmountToMinorUnits($amount, $currency);
    }

    public static function convertAmountToMinorUnits(float $amount, string $currency): int
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
    public static function performStripeApiRequest(string $method, string $path, array $body = [], array $options = []): array
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

        if (!empty($options['idempotency_key'])) {
            $args['headers']['Idempotency-Key'] = \sanitize_text_field((string) $options['idempotency_key']);
        }

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
    public static function createStripeCheckoutSession(array $reservationIds, array $guestForm, float $totalAmount, string $currency, array $couponIds = [], array $options = []): array
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

        $successUrl = isset($options['success_url']) ? \esc_url_raw((string) $options['success_url']) : '';
        $cancelUrl = isset($options['cancel_url']) ? \esc_url_raw((string) $options['cancel_url']) : '';

        if ($successUrl === '') {
            $successUrl = \add_query_arg(
                [
                    'reservation_ids' => \implode(',', $reservationIds),
                    'payment_method' => 'stripe',
                    'stripe_return' => 'success',
                    'session_id' => '{CHECKOUT_SESSION_ID}',
                ],
                ManagedPages::getBookingConfirmationPageUrl()
            );
        }

        if ($cancelUrl === '') {
            $cancelUrl = \add_query_arg(
                [
                    'payment_method' => 'stripe',
                    'stripe_return' => 'cancel',
                ],
                ManagedPages::getBookingConfirmationPageUrl()
            );
        }

        $successUrl = \str_replace('%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $successUrl);
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
     * @param array<int, int> $reservationIds
     * @param array<string, string> $guestForm
     * @return array<string, mixed>
     */
    public static function createPokPaySdkOrder(array $reservationIds, array $guestForm, float $totalAmount, string $currency): array
    {
        $reservationIds = self::normalizeReservationIds($reservationIds);

        if (empty($reservationIds)) {
            return [
                'success' => false,
                'message' => \__('No reservations are available for PokPay checkout.', 'must-hotel-booking'),
            ];
        }

        $credentials = self::getPokPayEnvironmentCredentials();
        $merchantId = $credentials['merchant_id'];
        $amountMinor = self::convertAmountToMinorUnits($totalAmount, $currency);

        if ($merchantId === '' || $credentials['key_id'] === '' || $credentials['key_secret'] === '') {
            return [
                'success' => false,
                'message' => \__('PokPay credentials are missing for the active environment.', 'must-hotel-booking'),
            ];
        }

        if ($amountMinor <= 0) {
            return [
                'success' => false,
                'message' => \__('PokPay requires a positive payment amount.', 'must-hotel-booking'),
            ];
        }

        $hotelName = \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::get_hotel_name()
            : \get_bloginfo('name');
        $description = $hotelName !== ''
            ? \sprintf(
                /* translators: %s is hotel name. */
                \__('%s stay reservation', 'must-hotel-booking'),
                $hotelName
            )
            : \__('Hotel stay reservation', 'must-hotel-booking');
        $payload = [
            'amount' => $amountMinor,
            'currency' => \strtoupper($currency),
            'description' => $description . ' #' . \implode(',', $reservationIds),
        ];

        if (!empty($guestForm['email'])) {
            $payload['email'] = (string) $guestForm['email'];
        }

        $response = self::performPokPayApiRequest(
            'POST',
            'merchants/' . \rawurlencode($merchantId) . '/sdk-orders',
            $payload
        );

        if (empty($response['success'])) {
            return [
                'success' => false,
                'message' => isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('Unable to create the PokPay payment order.', 'must-hotel-booking'),
            ];
        }

        $data = self::extractPokPayResponseData(isset($response['body']) && \is_array($response['body']) ? $response['body'] : []);
        $orderId = self::extractPokPayOrderId($data);

        if ($orderId === '') {
            return [
                'success' => false,
                'message' => \__('PokPay returned an incomplete payment order.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'order_id' => $orderId,
            'status' => self::getPokPayOrderStatus($data),
            'expires_at' => isset($data['expiresAt']) ? (string) $data['expiresAt'] : (isset($data['expires_at']) ? (string) $data['expires_at'] : ''),
            'order' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPokPaySdkOrder(string $orderId): array
    {
        $orderId = \trim($orderId);

        if ($orderId === '') {
            return [
                'success' => false,
                'message' => \__('PokPay order id is missing.', 'must-hotel-booking'),
            ];
        }

        $response = self::performPokPayApiRequest('GET', 'sdk-orders/' . \rawurlencode($orderId));

        if (empty($response['success'])) {
            return [
                'success' => false,
                'message' => isset($response['message']) ? (string) $response['message'] : \__('Unable to retrieve PokPay order.', 'must-hotel-booking'),
            ];
        }

        $data = self::extractPokPayResponseData(isset($response['body']) && \is_array($response['body']) ? $response['body'] : []);

        return [
            'success' => true,
            'order' => $data,
            'status' => self::getPokPayOrderStatus($data),
        ];
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public static function finalizePokPayOrder(string $orderId, array $reservationIds): array
    {
        $orderId = \sanitize_text_field($orderId);
        $reservationIds = self::normalizeReservationIds($reservationIds);

        if ($orderId === '' || empty($reservationIds)) {
            return [
                'success' => false,
                'message' => \__('PokPay payment details are missing.', 'must-hotel-booking'),
            ];
        }

        $orderResponse = self::getPokPaySdkOrder($orderId);

        if (empty($orderResponse['success'])) {
            return [
                'success' => false,
                'message' => isset($orderResponse['message']) ? (string) $orderResponse['message'] : \__('Unable to verify the PokPay payment.', 'must-hotel-booking'),
            ];
        }

        $status = isset($orderResponse['status']) ? \strtoupper((string) $orderResponse['status']) : '';

        if ($status === 'CAPTURED') {
            BookingStatusEngine::updateReservationStatuses($reservationIds, 'confirmed', 'paid');
            BookingStatusEngine::createPaymentRows($reservationIds, 'pokpay', 'paid', $orderId);

            if (\class_exists(\MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService::class)) {
                (new \MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService())->reconcilePaymentSucceeded(
                    $reservationIds,
                    'pokpay',
                    $orderId
                );
            }

            if (\function_exists('MustHotelBooking\Frontend\clear_booking_selection')) {
                \MustHotelBooking\Frontend\clear_booking_selection(false);
            }

            return [
                'success' => true,
                'state' => 'paid',
                'redirect_url' => \add_query_arg(
                    [
                        'reservation_ids' => \implode(',', $reservationIds),
                        'payment_method' => 'pokpay',
                        'pokpay_return' => 'success',
                        'order_id' => $orderId,
                    ],
                    ManagedPages::getBookingConfirmationPageUrl()
                ),
            ];
        }

        if (\in_array($status, ['FAILED', 'DECLINED', 'CANCELED', 'CANCELLED', 'EXPIRED'], true)) {
            BookingStatusEngine::failPendingPaymentReservations(
                $reservationIds,
                'pokpay',
                $status === 'EXPIRED' ? 'expired' : 'payment_failed'
            );

            return [
                'success' => false,
                'state' => $status === 'EXPIRED' ? 'expired' : 'failed',
                'message' => \__('PokPay could not confirm this payment. Please try again or use another card.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'state' => 'pending',
            'message' => \__('PokPay is still finalizing this payment. Please wait a moment and try again.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function extractPokPayResponseData(array $body): array
    {
        if (isset($body['data']) && \is_array($body['data'])) {
            return $body['data'];
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractPokPayOrderId(array $data): string
    {
        foreach (['id', 'orderId', 'sdkOrderId', 'sdk_order_id'] as $key) {
            if (isset($data[$key]) && \is_scalar($data[$key])) {
                $value = \trim((string) $data[$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function getPokPayOrderStatus(array $data): string
    {
        foreach (['status', 'paymentStatus', 'payment_status', 'state'] as $key) {
            if (isset($data[$key]) && \is_scalar($data[$key])) {
                return \strtoupper(\sanitize_key((string) $data[$key]));
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function performPokPayApiRequest(string $method, string $path, array $body = [], bool $authenticate = true): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($authenticate) {
            $tokenResult = self::getPokPayAccessToken();

            if (empty($tokenResult['success'])) {
                return $tokenResult;
            }

            $headers['Authorization'] = 'Bearer ' . (string) ($tokenResult['access_token'] ?? '');
        }

        $args = [
            'method' => \strtoupper($method),
            'timeout' => 20,
            'headers' => $headers,
        ];

        if (!empty($body)) {
            $args['body'] = \wp_json_encode($body);
        }

        $response = \wp_remote_request(self::getPokPayBaseUrl() . '/' . \ltrim($path, '/'), $args);

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
        $bodyArray = \is_array($decodedBody) ? $decodedBody : [];

        return [
            'success' => $statusCode >= 200 && $statusCode < 300 && \is_array($decodedBody),
            'status_code' => $statusCode,
            'body' => $bodyArray,
            'message' => self::extractPokPayErrorMessage($bodyArray),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function getPokPayAccessToken(): array
    {
        $credentials = self::getPokPayEnvironmentCredentials();

        if ($credentials['key_id'] === '' || $credentials['key_secret'] === '') {
            return [
                'success' => false,
                'message' => \__('PokPay key id or key secret is missing.', 'must-hotel-booking'),
            ];
        }

        $cacheKey = 'must_hotel_booking_pokpay_token_' . \md5(self::getPokPayApiEnvironment() . '|' . $credentials['merchant_id'] . '|' . $credentials['key_id']);
        $cachedToken = \get_transient($cacheKey);

        if (\is_string($cachedToken) && $cachedToken !== '') {
            return [
                'success' => true,
                'access_token' => $cachedToken,
            ];
        }

        $response = self::performPokPayApiRequest(
            'POST',
            'auth/sdk/login',
            [
                'keyId' => $credentials['key_id'],
                'keySecret' => $credentials['key_secret'],
            ],
            false
        );

        if (empty($response['success'])) {
            return [
                'success' => false,
                'message' => isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('PokPay authentication failed.', 'must-hotel-booking'),
            ];
        }

        $data = self::extractPokPayResponseData(isset($response['body']) && \is_array($response['body']) ? $response['body'] : []);
        $accessToken = isset($data['accessToken']) ? \trim((string) $data['accessToken']) : '';

        if ($accessToken === '') {
            return [
                'success' => false,
                'message' => \__('PokPay authentication returned no access token.', 'must-hotel-booking'),
            ];
        }

        $expiresIn = isset($data['expiresIn']) ? (int) $data['expiresIn'] : 3000;

        if ($expiresIn <= 0 && isset($data['expiresAt'])) {
            $expiresAt = \strtotime((string) $data['expiresAt']);
            $expiresIn = $expiresAt !== false ? \max(60, $expiresAt - \time()) : 3000;
        }

        \set_transient($cacheKey, $accessToken, \max(60, $expiresIn - 60));

        return [
            'success' => true,
            'access_token' => $accessToken,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function extractPokPayErrorMessage(array $body): string
    {
        if (isset($body['message']) && \is_scalar($body['message'])) {
            return (string) $body['message'];
        }

        if (isset($body['error']['message']) && \is_scalar($body['error']['message'])) {
            return (string) $body['error']['message'];
        }

        if (isset($body['error']) && \is_scalar($body['error'])) {
            return (string) $body['error'];
        }

        return '';
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

        $paymentRowsByReservation = get_payment_repository()->getPaymentsForReservationIds($reservationIds);
        $stripeReservationIds = [];
        $pokPayReservationIdsByOrder = [];

        foreach ($reservationIds as $reservationId) {
            $rows = isset($paymentRowsByReservation[$reservationId]) && \is_array($paymentRowsByReservation[$reservationId])
                ? $paymentRowsByReservation[$reservationId]
                : [];
            $latestRow = isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : [];
            $method = isset($latestRow['method']) ? \sanitize_key((string) $latestRow['method']) : 'stripe';
            $transactionId = isset($latestRow['transaction_id']) ? \sanitize_text_field((string) $latestRow['transaction_id']) : '';

            if ($method === 'pokpay' && $transactionId !== '') {
                if (!isset($pokPayReservationIdsByOrder[$transactionId])) {
                    $pokPayReservationIdsByOrder[$transactionId] = [];
                }

                $pokPayReservationIdsByOrder[$transactionId][] = (int) $reservationId;
                continue;
            }

            $stripeReservationIds[] = (int) $reservationId;
        }

        foreach ($pokPayReservationIdsByOrder as $orderId => $orderReservationIds) {
            $result = self::finalizePokPayOrder((string) $orderId, $orderReservationIds);

            if (!empty($result['success']) && (string) ($result['state'] ?? '') === 'paid') {
                continue;
            }

            if ((string) ($result['state'] ?? '') !== 'failed' && (string) ($result['state'] ?? '') !== 'expired') {
                BookingStatusEngine::failPendingPaymentReservations($orderReservationIds, 'pokpay', 'expired');
            }
        }

        if (!empty($stripeReservationIds)) {
            BookingStatusEngine::failPendingStripeReservations($stripeReservationIds, 'expired');
        }
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

        \register_rest_route(
            'must-hotel-booking/v1',
            '/pokpay/finalize',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'handlePokPayFinalizeRequest'],
                'permission_callback' => '__return_true',
            ]
        );

        \register_rest_route(
            'must-hotel-booking/v1',
            '/pokpay/error',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'handlePokPayErrorRequest'],
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

        if (\in_array($type, ['refund.created', 'refund.updated', 'charge.refunded'], true)) {
            (new PaymentRefundService())->handleStripeWebhookEvent($event);

            return new \WP_REST_Response(['success' => true], 200);
        }

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

            if (\class_exists(\MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService::class)) {
                (new \MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService())->reconcilePaymentSucceeded(
                    $reservationIds,
                    'stripe',
                    $paymentIntent !== '' ? $paymentIntent : $sessionId
                );
            }

            unset($couponIds);
        } elseif ($type === 'checkout.session.expired') {
            BookingStatusEngine::failPendingStripeReservations($reservationIds, 'expired');
        }

        return new \WP_REST_Response(['success' => true], 200);
    }

    public static function handlePokPayFinalizeRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $nonce = (string) $request->get_header('x-wp-nonce');

        if (!\wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid request.'], 403);
        }

        $params = $request->get_json_params();
        $params = \is_array($params) ? $params : [];
        $orderId = isset($params['order_id']) ? \sanitize_text_field((string) $params['order_id']) : '';
        $reservationIds = self::normalizeReservationIdsFromMixed($params['reservation_ids'] ?? []);

        if ($orderId === '' || empty($reservationIds)) {
            return new \WP_REST_Response(['success' => false, 'message' => \__('Payment details are missing.', 'must-hotel-booking')], 400);
        }

        if (\function_exists('MustHotelBooking\Frontend\get_booking_selection_flow_data')) {
            $flowData = \MustHotelBooking\Frontend\get_booking_selection_flow_data();
            $pendingPayment = self::normalizePendingPaymentFlowData($flowData['pending_payment'] ?? []);

            if (
                (string) ($pendingPayment['method'] ?? '') === 'pokpay'
                && (string) ($pendingPayment['session_id'] ?? '') !== ''
                && (string) ($pendingPayment['session_id'] ?? '') !== $orderId
            ) {
                return new \WP_REST_Response(['success' => false, 'message' => \__('This PokPay session no longer matches the active booking.', 'must-hotel-booking')], 409);
            }
        }

        if (!self::reservationPaymentsMatchPokPayOrder($reservationIds, $orderId)) {
            return new \WP_REST_Response(['success' => false, 'message' => \__('This PokPay order does not match the active booking.', 'must-hotel-booking')], 409);
        }

        $result = self::finalizePokPayOrder($orderId, $reservationIds);

        if (empty($result['success'])) {
            return new \WP_REST_Response([
                'success' => false,
                'state' => isset($result['state']) ? (string) $result['state'] : 'failed',
                'message' => isset($result['message']) ? (string) $result['message'] : \__('Unable to confirm the PokPay payment.', 'must-hotel-booking'),
            ], 400);
        }

        return new \WP_REST_Response([
            'success' => true,
            'state' => isset($result['state']) ? (string) $result['state'] : 'pending',
            'message' => isset($result['message']) ? (string) $result['message'] : '',
            'redirect_url' => isset($result['redirect_url']) ? (string) $result['redirect_url'] : '',
        ], 200);
    }

    public static function handlePokPayErrorRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $nonce = (string) $request->get_header('x-wp-nonce');

        if (!\wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_REST_Response(['success' => false], 403);
        }

        $params = $request->get_json_params();
        $params = \is_array($params) ? $params : [];
        $context = [
            'order_id' => isset($params['order_id']) ? \sanitize_text_field((string) $params['order_id']) : '',
            'message' => isset($params['message']) ? \substr(\sanitize_text_field((string) $params['message']), 0, 500) : '',
            'code' => isset($params['code']) ? \substr(\sanitize_key((string) $params['code']), 0, 80) : '',
        ];

        \error_log('MUST Hotel Booking PokPay checkout error: ' . \wp_json_encode($context));

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

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private static function normalizeReservationIdsFromMixed($value): array
    {
        if (\is_string($value)) {
            $value = \explode(',', $value);
        }

        if (!\is_array($value)) {
            return [];
        }

        return self::normalizeReservationIds($value);
    }

    /**
     * @param array<int, int> $reservationIds
     */
    private static function reservationPaymentsMatchPokPayOrder(array $reservationIds, string $orderId): bool
    {
        $reservationIds = self::normalizeReservationIds($reservationIds);
        $orderId = \sanitize_text_field($orderId);

        if ($orderId === '' || empty($reservationIds)) {
            return false;
        }

        $paymentsByReservation = get_payment_repository()->getPaymentsForReservationIds($reservationIds);

        foreach ($reservationIds as $reservationId) {
            $rows = isset($paymentsByReservation[$reservationId]) && \is_array($paymentsByReservation[$reservationId])
                ? $paymentsByReservation[$reservationId]
                : [];
            $hasMatchingPayment = false;

            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $method = isset($row['method']) ? \sanitize_key((string) $row['method']) : '';
                $transactionId = isset($row['transaction_id']) ? \sanitize_text_field((string) $row['transaction_id']) : '';

                if ($method === 'pokpay' && $transactionId === $orderId) {
                    $hasMatchingPayment = true;
                    break;
                }
            }

            if (!$hasMatchingPayment) {
                return false;
            }
        }

        return true;
    }
}
