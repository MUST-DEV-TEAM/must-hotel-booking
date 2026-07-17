<?php
namespace MustHotelBooking\Engine;
use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Engine\Payment\PaymentFactory;
use MustHotelBooking\Engine\Payment\PaymentInterface;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\ProviderDataSanitizer;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;
final class PaymentEngine
{
    private const POKPAY_VERIFICATION_OPTION = 'must_hotel_booking_pokpay_credential_verification';

    /**
     * @return array<string, mixed>
     */
    public static function getEmptyPendingPaymentFlowData(): array
    {
        return [
            'method' => '',
            'flow' => '',
            'reservation_ids' => [],
            'session_id' => '',
            'checkout_url' => '',
            'checkout_mode' => '',
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
            'flow' => isset($value['flow']) ? \sanitize_key((string) $value['flow']) : '',
            'reservation_ids' => $reservationIds,
            'session_id' => isset($value['session_id']) ? \sanitize_text_field((string) $value['session_id']) : '',
            'checkout_url' => isset($value['checkout_url']) ? \esc_url_raw((string) $value['checkout_url']) : '',
            'checkout_mode' => isset($value['checkout_mode']) ? \sanitize_key((string) $value['checkout_mode']) : '',
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
        return $options;
    }
    /**
     * @return array<int, string>
     */
    public static function getEnabledOnlineCheckoutMethods(): array
    {
        $methods = [];
        foreach (['pokpay', 'stripe'] as $method) {
            if (isset(self::getCheckoutPaymentMethods()[$method])) {
                $methods[] = $method;
            }
        }
        return $methods;
    }
    public static function normalizeDefaultPaymentMode(string $mode, array $methodStates = []): string
    {
        $mode = \sanitize_key($mode);
        $payAtHotelEnabled = \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::is_pay_at_hotel_enabled()
            : false;

        if (!empty($methodStates)) {
            $payAtHotelEnabled = !empty($methodStates['pay_at_hotel']);
        }

        if ($mode === 'pay_at_hotel' && !$payAtHotelEnabled) {
            $mode = '';
        }

        if (\in_array($mode, ['pokpay', 'stripe', 'pay_now', 'guest_choice'], true)) {
            return $mode;
        }
        if ($mode === 'pay_at_hotel' && $payAtHotelEnabled) {
            return 'pay_at_hotel';
        }
        if (empty($methodStates) || !empty($methodStates['pokpay'])) {
            return 'pokpay';
        }
        if (!empty($methodStates['stripe'])) {
            return 'stripe';
        }
        return $payAtHotelEnabled ? 'pay_at_hotel' : 'pokpay';
    }
    public static function resolveDefaultPublicCheckoutPaymentMethod(): string
    {
        $methods = self::getCheckoutPaymentMethods();
        $defaultMode = self::normalizeDefaultPaymentMode(
            (string) MustBookingConfig::get_setting('default_payment_mode', 'pokpay')
        );

        if (isset($methods[$defaultMode])) {
            return $defaultMode;
        }
        foreach (['pokpay', 'stripe', 'pay_at_hotel'] as $method) {
            if (isset($methods[$method])) {
                return $method;
            }
        }
        return '';
    }
    /**
     * @return array<string, mixed>
     */
    public static function getPublicCheckoutPaymentPolicy(): array
    {
        $methods = self::getCheckoutPaymentMethods();
        $onlineMethods = self::getEnabledOnlineCheckoutMethods();
        $payAtHotelEnabled = MustBookingConfig::is_pay_at_hotel_enabled();
        $defaultMethod = self::resolveDefaultPublicCheckoutPaymentMethod();
        $defaultPaymentMode = self::normalizeDefaultPaymentMode(
            (string) MustBookingConfig::get_setting('default_payment_mode', 'pokpay')
        );
        $offlineAllowed = $payAtHotelEnabled && isset($methods['pay_at_hotel']);
        $configurationError = self::getPublicCheckoutConfigurationError($methods, $onlineMethods, $defaultMethod);

        return [
            'default_payment_mode' => $defaultPaymentMode,
            'default_method' => $defaultMethod,
            'pay_at_hotel_enabled' => $payAtHotelEnabled,
            'public_checkout_payment_policy' => $configurationError === '' ? 'online_payment_first' : 'blocked_configuration',
            'enabled_methods' => \array_keys($methods),
            'enabled_online_methods' => $onlineMethods,
            'public_offline_payment_allowed' => $offlineAllowed,
            'backend_rejects_disabled_pay_at_hotel' => true,
            'configuration_error' => $configurationError,
        ];
    }
    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $flowData
     * @return array{success: bool, method: string, message: string, reason: string, methods: array<string, array<string, string>>}
     */
    public static function validatePublicCheckoutPaymentMethod(array $source, array $flowData = []): array
    {
        $methods = self::getCheckoutPaymentMethods();
        $onlineMethods = self::getEnabledOnlineCheckoutMethods();
        $defaultMethod = self::resolveDefaultPublicCheckoutPaymentMethod();
        $configurationError = self::getPublicCheckoutConfigurationError($methods, $onlineMethods, $defaultMethod);
        $rawMethod = '';
        $hasSubmittedMethod = false;

        if (isset($source['payment_method']) && !\is_array($source['payment_method'])) {
            $rawMethod = \sanitize_key((string) \wp_unslash($source['payment_method']));
            $hasSubmittedMethod = $rawMethod !== '';
        }

        if ($hasSubmittedMethod) {
            if ($rawMethod === 'pay_at_hotel' && !MustBookingConfig::is_pay_at_hotel_enabled()) {
                return [
                    'success' => false,
                    'method' => '',
                    'message' => \__('Pay at hotel is not enabled for online checkout. Please choose an online payment method.', 'must-hotel-booking'),
                    'reason' => 'pay_at_hotel_disabled',
                    'methods' => $methods,
                ];
            }
            if (!isset($methods[$rawMethod])) {
                return [
                    'success' => false,
                    'method' => '',
                    'message' => $configurationError !== ''
                        ? $configurationError
                        : \__('Please select a valid payment method.', 'must-hotel-booking'),
                    'reason' => $configurationError !== '' ? 'payment_configuration_error' : 'invalid_payment_method',
                    'methods' => $methods,
                ];
            }
            return [
                'success' => true,
                'method' => $rawMethod,
                'message' => '',
                'reason' => '',
                'methods' => $methods,
            ];
        }

        if ($configurationError !== '' || $defaultMethod === '') {
            return [
                'success' => false,
                'method' => '',
                'message' => $configurationError !== ''
                    ? $configurationError
                    : \__('Online checkout is not configured with an available payment method.', 'must-hotel-booking'),
                'reason' => 'payment_configuration_error',
                'methods' => $methods,
            ];
        }

        $flowMethod = isset($flowData['payment_method']) ? \sanitize_key((string) $flowData['payment_method']) : '';
        if ($flowMethod !== '' && isset($methods[$flowMethod])) {
            return [
                'success' => true,
                'method' => $flowMethod,
                'message' => '',
                'reason' => '',
                'methods' => $methods,
            ];
        }

        return [
            'success' => true,
            'method' => $defaultMethod,
            'message' => '',
            'reason' => '',
            'methods' => $methods,
        ];
    }
    /**
     * @param array<string, mixed> $flowData
     */
    public static function clearInvalidPublicPaymentMethodDraft(array $flowData): bool
    {
        $method = isset($flowData['payment_method']) ? \sanitize_key((string) $flowData['payment_method']) : '';
        if ($method === '') {
            return false;
        }
        $methods = self::getCheckoutPaymentMethods();
        if ($method === 'pay_at_hotel' && !MustBookingConfig::is_pay_at_hotel_enabled()) {
            return true;
        }
        return !isset($methods[$method]);
    }
    /**
     * @param array<string, array<string, string>> $methods
     * @param array<int, string> $onlineMethods
     */
    private static function getPublicCheckoutConfigurationError(array $methods, array $onlineMethods, string $defaultMethod): string
    {
        if (empty($methods)) {
            return \__('Online checkout is not configured. Please contact the hotel before completing this booking.', 'must-hotel-booking');
        }
        if (empty($onlineMethods) && !MustBookingConfig::is_pay_at_hotel_enabled()) {
            return \__('Online checkout needs PokPay or Stripe credentials before public bookings can be confirmed.', 'must-hotel-booking');
        }
        if ((string) MustBookingConfig::get_setting('default_payment_mode', 'pokpay') === 'pay_at_hotel' && !MustBookingConfig::is_pay_at_hotel_enabled()) {
            return \__('Online checkout is misconfigured because Pay at hotel is selected as the default but is disabled.', 'must-hotel-booking');
        }
        if ($defaultMethod === '' || !isset($methods[$defaultMethod])) {
            return \__('Online checkout is not configured with a valid default payment method.', 'must-hotel-booking');
        }
        return '';
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
        return self::resolveDefaultPublicCheckoutPaymentMethod();
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
        $paymentMethod = \sanitize_key($paymentMethod);
        $gateway = self::normalizeMethod($paymentMethod);
        if ($paymentMethod === '') {
            return \__('Payment configuration required', 'must-hotel-booking');
        }
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
                'description' => \__('Use for local development sites; callbacks should be configured with a temporary public HTTPS base only during testing.', 'must-hotel-booking'),
                'example' => '',
            ],
            'staging' => [
                'label' => \__('Staging / IP website', 'must-hotel-booking'),
                'description' => \__('Use for temporary HTTP or IP-based sites such as http://18.185.56.94/.', 'must-hotel-booking'),
                'example' => 'http://18.185.56.94/',
            ],
            'production' => [
                'label' => \__('Live website', 'must-hotel-booking'),
                'description' => \__('Use for the real HTTPS website such as https://new.empirebeachresort.al.', 'must-hotel-booking'),
                'example' => 'https://new.empirebeachresort.al',
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
    public static function getPokPayCheckoutMode(): string
    {
        return \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::get_pokpay_checkout_mode()
            : 'sdk_confirm_url_redirect';
    }
    public static function isPokPayConfigured(): bool
    {
        $credentials = self::getPokPayEnvironmentCredentials();
        return $credentials['merchant_id'] !== '' && $credentials['key_id'] !== '' && $credentials['key_secret'] !== '';
    }

    /** @return array<string, mixed> */
    public static function getPokPayCredentialState(string $environment = ''): array
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();
        $credentials = self::getPokPayEnvironmentCredentials($environment);
        $present = $credentials['merchant_id'] !== '' && $credentials['key_id'] !== '' && $credentials['key_secret'] !== '';
        $default = [
            'environment' => self::getPokPayApiEnvironment($environment),
            'site_environment' => $environment,
            'status' => $present ? 'unverified' : 'missing',
            'authentication_success' => false,
            'http_status' => 0,
            'error_code' => '',
            'error_message' => '',
            'verified_at' => '',
            'merchant_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['merchant_id']),
            'key_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['key_id']),
        ];

        if (!$present || !\function_exists('get_option')) {
            return $default;
        }

        $saved = \get_option(self::POKPAY_VERIFICATION_OPTION, []);
        $entry = \is_array($saved) && isset($saved[$environment]) && \is_array($saved[$environment])
            ? $saved[$environment]
            : [];

        if (
            empty($entry)
            || !isset($entry['credential_fingerprint'])
            || !\hash_equals((string) $entry['credential_fingerprint'], self::pokPayCredentialFingerprint($credentials))
        ) {
            return $default;
        }

        unset($entry['credential_fingerprint']);

        return \array_merge($default, ProviderDataSanitizer::sanitize($entry));
    }

    /** @return array<string, mixed> */
    public static function verifyPokPayCredentials(string $environment = ''): array
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();
        $credentials = self::getPokPayEnvironmentCredentials($environment);
        $base = [
            'environment' => self::getPokPayApiEnvironment($environment),
            'site_environment' => $environment,
            'authentication_success' => false,
            'http_status' => 0,
            'error_code' => '',
            'error_message' => '',
            'verified_at' => \current_time('mysql'),
            'merchant_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['merchant_id']),
            'key_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['key_id']),
        ];

        if ($credentials['merchant_id'] === '' || $credentials['key_id'] === '' || $credentials['key_secret'] === '') {
            $result = \array_merge($base, [
                'status' => 'missing',
                'error_code' => 'credentials_missing',
                'error_message' => \__('PokPay credentials are missing for this environment.', 'must-hotel-booking'),
            ]);
            self::savePokPayCredentialState($environment, $credentials, $result);
            return $result;
        }

        $response = self::performPokPayApiRequest(
            'POST',
            'auth/sdk/login',
            [
                'keyId' => $credentials['key_id'],
                'keySecret' => $credentials['key_secret'],
            ],
            false,
            [
                'environment' => $environment,
                'operation' => 'pokpay.authentication',
                'endpoint_name' => 'auth_sdk_login',
                'merchant_id' => $credentials['merchant_id'],
                'key_id' => $credentials['key_id'],
            ]
        );
        $statusCode = (int) ($response['status_code'] ?? 0);
        $body = isset($response['body']) && \is_array($response['body']) ? $response['body'] : [];
        $data = self::extractPokPayResponseData($body);
        $accessToken = isset($data['accessToken']) && \is_scalar($data['accessToken'])
            ? \trim((string) $data['accessToken'])
            : '';
        $errorCode = self::extractPokPayErrorCode($body);
        $errorMessage = isset($response['message']) ? (string) $response['message'] : '';
        $success = !empty($response['success']) && $accessToken !== '';

        if ($success) {
            self::cachePokPayAccessToken($environment, $credentials, $accessToken, $data);
            $status = 'verified';
        } elseif (\in_array($statusCode, [400, 401, 403], true)) {
            $status = 'rejected';
        } elseif ($statusCode === 0 || $statusCode === 429 || $statusCode >= 500) {
            $status = 'provider_unavailable';
        } else {
            $status = 'malformed';
        }

        if (!$success && \function_exists('delete_transient')) {
            \delete_transient(self::getPokPayAccessTokenCacheKey($environment));
        }

        $result = \array_merge($base, [
            'status' => $status,
            'authentication_success' => $success,
            'http_status' => $statusCode,
            'error_code' => $errorCode,
            'error_message' => ProviderDataSanitizer::sanitizeText($errorMessage),
            'correlation_id' => (string) ($response['correlation_id'] ?? ''),
            'duration_ms' => (int) ($response['duration_ms'] ?? 0),
        ]);
        self::savePokPayCredentialState($environment, $credentials, $result);

        return $result;
    }

    public static function isPokPayCheckoutBlocked(): bool
    {
        $state = self::getPokPayCredentialState();

        return \in_array((string) ($state['status'] ?? ''), ['missing', 'rejected', 'malformed'], true);
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
        return MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/stripe/webhook');
    }
    /**
     * @return array{reservation_status: string, payment_status: string, confirmation_flow: string, clear_selection: bool, increment_coupon_usage: bool}
     */
    public static function getReservationCreationOptions(string $method): array
    {
        $gateway = self::normalizeMethod($method);
        if ($gateway === 'stripe' || $gateway === 'pokpay') {
            return [
                'reservation_status' => 'pending_payment',
                'payment_status' => 'pending',
                'confirmation_flow' => 'website_online_' . $gateway,
                'clear_selection' => false,
                'increment_coupon_usage' => false,
            ];
        }
        return [
            'reservation_status' => 'pending',
            'payment_status' => 'unpaid',
            'confirmation_flow' => 'website_offline_pay_at_hotel',
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
        $method = self::normalizeMethod($method);
        $reservationIds = self::normalizeReservationIds($reservationIds);
        if (!\in_array($method, ['stripe', 'pokpay'], true)) {
            return self::getGateway($method)->processPayment([
                'reservation_ids' => $reservationIds,
                'method' => $method,
            ], $amount, $context);
        }
        $currency = \strtoupper(\sanitize_text_field((string) ($context['currency'] ?? MustBookingConfig::get_currency())));
        $checkoutMode = $method === 'pokpay' ? self::getPokPayCheckoutMode() : 'hosted_redirect';
        $attempt = (new PaymentAttemptIntegrity())->prepare($method, $reservationIds, $amount, $currency, $checkoutMode);
        if (empty($attempt['allowed'])) {
            return [
                'success' => false,
                'state' => 'integrity_error',
                'retryable' => false,
                'reason_code' => (string) ($attempt['reason_code'] ?? 'payment_target_incompatible'),
                'message' => \__('Online checkout is blocked because the payment and reservation targets cannot be proven compatible. Ask the hotel to review its payment settings.', 'must-hotel-booking'),
            ];
        }
        $context['currency'] = $currency;
        $context['payment_attempt'] = (array) ($attempt['attempt'] ?? []);
        $payload = [
            'reservation_ids' => $reservationIds,
            'method' => $method,
        ];
        return self::getGateway($method)->processPayment($payload, $amount, $context);
    }

    /** @param array<string, mixed> $pending @return array<string, mixed> */
    public static function validateReusablePendingPaymentAttempt(array $pending, string $method, float $amount, string $currency, string $flow): array
    {
        return (new PaymentAttemptIntegrity())->validateReusable($pending, $method, $amount, $currency, $flow);
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
        return self::syncReturnSessionWithPolicy($method, $sessionId, $reservationIds, true, true);
    }

    /**
     * Inspect a browser return with authoritative provider verification while
     * preventing failure/expiry paths from changing local booking state.
     *
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public static function inspectReturnSession(string $method, string $sessionId, array $reservationIds): array
    {
        return self::syncReturnSessionWithPolicy($method, $sessionId, $reservationIds, false, true);
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    private static function syncReturnSessionWithPolicy(
        string $method,
        string $sessionId,
        array $reservationIds,
        bool $allowFailureMutation,
        bool $allowSuccessMutation
    ): array
    {
        if (self::normalizeMethod($method) !== 'stripe') {
            return [
                'success' => false,
                'message' => '',
            ];
        }
        $reservationIds = self::normalizeReservationIds($reservationIds);
        $sessionId = \sanitize_text_field($sessionId);
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
        $transactionId = isset($session['payment_intent']) && (string) $session['payment_intent'] !== ''
            ? (string) $session['payment_intent']
            : $sessionId;
        $binding = self::validateStripeSessionFacts($session, $reservationIds);
        if (empty($binding['success'])) {
            if ($paymentStatus === 'paid' && $sessionStatus === 'complete') {
                $facts = self::buildStripePaidObservationFacts($session, $transactionId, $sessionId);
                $observation = self::persistPaidProviderFailure('stripe', $reservationIds, $facts, (string) ($binding['reason_code'] ?? 'stripe_session_binding_failed'), self::getReservationIdsFromStripeSession($session));
                $binding['provider_paid'] = true;
                $binding['paid_observation_persisted'] = !empty($observation['success']);
            }
            return $binding;
        }
        if ($paymentStatus === 'paid' && $sessionStatus === 'complete') {
            if (!$allowSuccessMutation) {
                return [
                    'success' => true,
                    'state' => 'paid',
                ];
            }
            if (self::stripeCompletedSessionAlreadyRecorded($reservationIds, $transactionId)) {
                return [
                    'success' => true,
                    'state' => 'paid',
                ];
            }
            if (!self::reservationPaymentIdsMatchTransaction($reservationIds, 'stripe', $sessionId)) {
                $facts = self::buildStripePaidObservationFacts($session, $transactionId, $sessionId);
                $observation = self::persistPaidProviderFailure('stripe', $reservationIds, $facts, 'stripe_payment_binding_mismatch', self::getReservationIdsFromStripeSession($session));
                return [
                    'success' => false,
                    'state' => 'integrity_error',
                    'provider_paid' => true,
                    'paid_observation_persisted' => !empty($observation['success']),
                    'message' => \__('Stripe session is not bound to this local payment reservation.', 'must-hotel-booking'),
                    'reason_code' => 'stripe_payment_binding_mismatch',
                ];
            }
            $completion = self::completeVerifiedOnlinePayment(
                'stripe',
                $reservationIds,
                $transactionId,
                $sessionId,
                false,
                'authoritative_provider_reread',
                '',
                self::buildStripePaidObservationFacts($session, $transactionId, $sessionId)
            );
            if (empty($completion['success'])) {
                return $completion;
            }
            (new PaymentProviderFeeService())->captureStripeFeeSnapshotForReservations($reservationIds, $session);
            return [
                'success' => true,
                'state' => 'paid',
            ];
        }
        if ($sessionStatus === 'expired') {
            if (!self::reservationPaymentIdsMatchTransaction($reservationIds, 'stripe', $sessionId)) {
                return [
                    'success' => false,
                    'state' => 'integrity_error',
                    'message' => \__('Expired Stripe session is not bound to this local payment reservation.', 'must-hotel-booking'),
                ];
            }
            if ($allowFailureMutation) {
                BookingStatusEngine::failPendingStripeReservations($reservationIds, 'expired');
            }
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
    public static function isPokPayCheckoutUrl(string $url): bool
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
        return $host === 'pokpay.io'
            || \substr($host, -10) === '.pokpay.io'
            || $host === 'rpay.ai'
            || \substr($host, -8) === '.rpay.ai'
            || $host === 'rpay.al'
            || \substr($host, -8) === '.rpay.al';
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
    public static function convertMinorUnitsToAmount(int $amountMinor, string $currency): float
    {
        $currency = \strtolower(\trim($currency));
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
            return (float) $amountMinor;
        }
        return \round($amountMinor / 100, 2);
    }
    /**
     * @param array<string, scalar> $body
     * @return array<string, mixed>
     */
    public static function performStripeApiRequest(string $method, string $path, array $body = [], array $options = []): array
    {
        $started = \microtime(true);
        $correlationId = self::providerCorrelationId();
        $operation = isset($options['operation'])
            ? \sanitize_text_field((string) $options['operation'])
            : 'stripe.' . \strtolower($method) . '.' . \trim((string) \preg_replace('/[^a-z0-9]+/i', '_', \strtolower($path)), '_');
        $logs = new ProviderRequestLogRepository();
        $logId = $logs->create([
            'provider' => 'stripe',
            'operation' => $operation,
            'direction' => 'outbound',
            'correlation_id' => $correlationId,
            'idempotency_key' => isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : '',
            'reservation_id' => isset($options['reservation_id']) ? (int) $options['reservation_id'] : 0,
            'external_id' => isset($options['external_id']) ? (string) $options['external_id'] : '',
            'request_summary' => [
                'environment' => self::getActiveSiteEnvironment() === 'production' ? 'production' : 'test',
                'endpoint_name' => \trim($path, '/'),
                'http_method' => \strtoupper($method),
                'attempt' => 1,
                'booking_reference' => isset($options['booking_reference']) ? (string) $options['booking_reference'] : '',
                'payment_id' => isset($options['payment_id']) ? (int) $options['payment_id'] : 0,
                'body' => ProviderDataSanitizer::sanitize($body),
            ],
        ]);
        $secretKey = self::getStripeSecretKey();
        if ($secretKey === '') {
            $result = [
                'success' => false,
                'status_code' => 0,
                'body' => [],
                'error_code' => 'credentials_missing',
                'message' => \__('Stripe secret key is missing.', 'must-hotel-booking'),
                'retryable' => false,
                'correlation_id' => $correlationId,
                'duration_ms' => self::providerDurationMs($started),
            ];
            self::completeProviderLog($logs, $logId, $result, []);
            return $result;
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
            $result = [
                'success' => false,
                'status_code' => 0,
                'body' => [],
                'error_code' => (string) $response->get_error_code(),
                'message' => (string) $response->get_error_message(),
                'retryable' => true,
                'correlation_id' => $correlationId,
                'duration_ms' => self::providerDurationMs($started),
            ];
            self::completeProviderLog($logs, $logId, $result, []);
            return $result;
        }
        $statusCode = (int) \wp_remote_retrieve_response_code($response);
        $decodedBody = \json_decode((string) \wp_remote_retrieve_body($response), true);
        $bodyArray = \is_array($decodedBody) ? $decodedBody : [];
        $result = [
            'success' => $statusCode >= 200 && $statusCode < 300 && \is_array($decodedBody),
            'status_code' => $statusCode,
            'body' => $bodyArray,
            'error_code' => isset($bodyArray['error']['code']) ? \sanitize_text_field((string) $bodyArray['error']['code']) : '',
            'message' => isset($bodyArray['error']['message'])
                ? ProviderDataSanitizer::sanitizeText((string) $bodyArray['error']['message'])
                : '',
            'retryable' => $statusCode === 429 || $statusCode >= 500,
            'correlation_id' => $correlationId,
            'duration_ms' => self::providerDurationMs($started),
        ];
        self::completeProviderLog($logs, $logId, $result, []);
        return $result;
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
            $successUrl = (new PublicBookingAccessService())->buildPublicUrl(
                ManagedPages::getBookingConfirmationPageUrl(),
                $reservationIds,
                PublicBookingAccessService::PURPOSE_VIEW_CONFIRMATION,
                [
                    'payment_method' => 'stripe',
                    'stripe_return' => 'success',
                    'session_id' => '{CHECKOUT_SESSION_ID}',
                ]
            );
        }
        if ($cancelUrl === '') {
            $cancelUrl = (new PublicBookingAccessService())->buildPublicUrl(
                ManagedPages::getBookingConfirmationPageUrl(),
                $reservationIds,
                PublicBookingAccessService::PURPOSE_VIEW_CONFIRMATION,
                [
                    'payment_method' => 'stripe',
                    'stripe_return' => 'cancel',
                ]
            );
        }
        if ($successUrl === '' || $cancelUrl === '') {
            return [
                'success' => false,
                'message' => __('Unable to establish a secure confirmation link for Stripe checkout.', 'must-hotel-booking'),
            ];
        }
        $successUrl = MustBookingConfig::build_public_callback_url($successUrl);
        $cancelUrl = MustBookingConfig::build_public_callback_url($cancelUrl);
        $successUrl = \str_replace(
            [
                '%7BCHECKOUT_SESSION_ID%7D',
                '__STRIPE_CHECKOUT_SESSION_ID__',
            ],
            '{CHECKOUT_SESSION_ID}',
            $successUrl
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
        $response = self::performStripeApiRequest('POST', 'checkout/sessions', $payload, [
            'operation' => 'stripe.checkout_session_create',
            'reservation_id' => (int) ($reservationIds[0] ?? 0),
        ]);
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
        $pokpayAmount = \round(\max(0.0, $totalAmount), 2);
        if ($merchantId === '' || $credentials['key_id'] === '' || $credentials['key_secret'] === '') {
            return [
                'success' => false,
                'message' => \__('PokPay credentials are missing for the active environment.', 'must-hotel-booking'),
            ];
        }
        if ($pokpayAmount <= 0) {
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
                \__('%s stay reservation', 'must-hotel-booking'),
                $hotelName
            )
            : \__('Hotel stay reservation', 'must-hotel-booking');
        $confirmationUrl = ManagedPages::getBookingConfirmationPageUrl();
        $publicAccess = new PublicBookingAccessService();
        $successUrl = $publicAccess->buildPublicUrl(
            $confirmationUrl,
            $reservationIds,
            PublicBookingAccessService::PURPOSE_VIEW_CONFIRMATION,
            [
                'payment_method' => 'pokpay',
                'pokpay_return' => 'success',
            ]
        );
        $failUrl = $publicAccess->buildPublicUrl(
            $confirmationUrl,
            $reservationIds,
            PublicBookingAccessService::PURPOSE_VIEW_CONFIRMATION,
            [
                'payment_method' => 'pokpay',
                'pokpay_return' => 'cancel',
            ]
        );
        if ($successUrl === '' || $failUrl === '') {
            return [
                'success' => false,
                'message' => __('Unable to establish a secure confirmation link for PokPay checkout.', 'must-hotel-booking'),
            ];
        }
        $successUrl = MustBookingConfig::build_public_callback_url($successUrl);
        $failUrl = MustBookingConfig::build_public_callback_url($failUrl);
        $payload = [
            'amount' => $pokpayAmount,
            'currencyCode' => \strtoupper($currency),
            'autoCapture' => true,
            'description' => $description . ' #' . \implode(',', $reservationIds),
            'webhookUrl' => MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook'),
            'redirectUrl' => $successUrl,
            'failRedirectUrl' => $failUrl,
            'products' => [
                [
                    'name' => $hotelName !== '' ? $hotelName . ' stay reservation' : 'Hotel stay reservation',
                    'quantity' => 1,
                    'price' => $pokpayAmount,
                ],
            ],
        ];
        $response = self::performPokPayApiRequest(
            'POST',
            'merchants/' . \rawurlencode($merchantId) . '/sdk-orders',
            $payload,
            true,
            [
                'operation' => 'pokpay.sdk_order_create',
                'endpoint_name' => 'sdk_orders_create',
                'reservation_id' => (int) ($reservationIds[0] ?? 0),
                'merchant_id' => $merchantId,
                'key_id' => $credentials['key_id'],
            ]
        );
        if (empty($response['success'])) {
            return [
                'success' => false,
                'status_code' => (int) ($response['status_code'] ?? 0),
                'error_code' => (string) ($response['error_code'] ?? ''),
                'correlation_id' => (string) ($response['correlation_id'] ?? ''),
                'duration_ms' => (int) ($response['duration_ms'] ?? 0),
                'retryable' => !empty($response['retryable']),
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
        $checkoutUrl = self::extractPokPaySdkConfirmUrl($data);
        if ($checkoutUrl === '') {
            self::logPokPayMissingCheckoutUrlDiagnostics($data, $orderId);
        }
        return [
            'success' => true,
            'order_id' => $orderId,
            'transaction_id' => $orderId,
            'session_id' => $orderId,
            'redirect_url' => $checkoutUrl,
            'checkout_url' => $checkoutUrl,
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
        $response = self::performPokPayApiRequest(
            'GET',
            'sdk-orders/' . \rawurlencode($orderId),
            [],
            true,
            [
                'operation' => 'pokpay.sdk_order_fetch',
                'endpoint_name' => 'sdk_order_fetch',
                'external_id' => $orderId,
            ]
        );
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
     * @return array<string, mixed>
     */
    public static function refundPokPaySdkOrder(string $orderId, float $amount, string $currency, string $reason = '', bool $fullRefund = true, string $idempotencyKey = ''): array
    {
        $orderId = \sanitize_text_field($orderId);
        if ($orderId === '') {
            return [
                'success' => false,
                'message' => \__('PokPay order id is missing.', 'must-hotel-booking'),
            ];
        }
        $amount = \round(\max(0.0, $amount), 2);
        $currency = \strtoupper(\sanitize_text_field($currency));
        if ($amount <= 0.0) {
            return [
                'success' => false,
                'message' => \__('PokPay refund amount must be greater than zero.', 'must-hotel-booking'),
            ];
        }
        $credentials = self::getPokPayEnvironmentCredentials();
        $merchantId = \sanitize_text_field((string) ($credentials['merchant_id'] ?? ''));
        if ($merchantId === '') {
            return [
                'success' => false,
                'manual_fallback' => true,
                'message' => \__('PokPay merchant id is missing.', 'must-hotel-booking'),
            ];
        }
        $body = [
            'refundReason' => \sanitize_text_field($reason !== '' ? $reason : \__('Website refund', 'must-hotel-booking')),
        ];
        if (!$fullRefund) {
            $body['refundAmount'] = self::convertAmountToMinorUnits($amount, $currency);
        }
        $path = 'merchants/' . \rawurlencode($merchantId) . '/sdk-orders/' . \rawurlencode($orderId) . '/refund';
        $response = self::performPokPayApiRequest('POST', $path, $body, true, [
            'operation' => 'pokpay.refund_create',
            'endpoint_name' => 'sdk_order_refund',
            'external_id' => $orderId,
            'merchant_id' => $merchantId,
            'idempotency_key' => \sanitize_text_field($idempotencyKey),
        ]);
        if (empty($response['success'])) {
            return [
                'success' => false,
                'manual_fallback' => true,
                'status_code' => (int) ($response['status_code'] ?? 0),
                'message' => isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('PokPay refund request failed.', 'must-hotel-booking'),
                'body' => isset($response['body']) && \is_array($response['body']) ? $response['body'] : [],
            ];
        }
        $data = self::extractPokPayResponseData(isset($response['body']) && \is_array($response['body']) ? $response['body'] : []);
        $status = self::getPokPayRefundStatus($data);
        $providerRefundId = self::extractPokPayRefundReference($data);
        if ($status === '' || $providerRefundId === '') {
            $orderResponse = self::getPokPaySdkOrder($orderId);
            if (!empty($orderResponse['success'])) {
                $orderData = isset($orderResponse['order']) && \is_array($orderResponse['order']) ? $orderResponse['order'] : [];
                $orderStatus = self::getPokPayRefundStatus($orderData);
                $orderRefundId = self::extractPokPayRefundReference($orderData);
                $status = $status !== '' ? $status : $orderStatus;
                $providerRefundId = $providerRefundId !== '' ? $providerRefundId : $orderRefundId;
            }
        }
        if ($status === '') {
            $status = !empty($data['isRefunded']) ? 'succeeded' : 'processing';
        }
        if (!\in_array($status, ['succeeded', 'success', 'completed', 'refunded'], true)) {
            return [
                'success' => false,
                'manual_fallback' => true,
                'status_code' => (int) ($response['status_code'] ?? 0),
                'message' => \__('PokPay accepted the refund request but did not return a completed refund status. Review the order in PokPay before marking the local refund completed.', 'must-hotel-booking'),
                'status' => $status,
                'body' => isset($response['body']) && \is_array($response['body']) ? $response['body'] : [],
            ];
        }
        return [
            'success' => true,
            'provider_refund_id' => $providerRefundId !== '' ? $providerRefundId : $orderId,
            'status' => 'succeeded',
            'raw_status' => $status,
            'status_code' => (int) ($response['status_code'] ?? 0),
            'body' => isset($response['body']) && \is_array($response['body']) ? $response['body'] : [],
        ];
    }
    /**
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public static function finalizePokPayOrder(string $orderId, array $reservationIds, bool $allowClockRetry = false): array
    {
        $orderId = \sanitize_text_field($orderId);
        $reservationIds = self::normalizeReservationIds($reservationIds);
        if ($orderId === '' || empty($reservationIds)) {
            return [
                'success' => false,
                'message' => \__('PokPay payment details are missing.', 'must-hotel-booking'),
            ];
        }
        if (!self::reservationPaymentsMatchPokPayOrder($reservationIds, $orderId)) {
            return [
                'success' => false,
                'state' => 'integrity_error',
                'message' => \__('This PokPay order does not match the local payment reservation.', 'must-hotel-booking'),
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
        if (\in_array($status, ['CAPTURED', 'PAID', 'COMPLETED'], true)) {
            $order = isset($orderResponse['order']) && \is_array($orderResponse['order']) ? $orderResponse['order'] : [];
            $paidFacts = self::buildPokPayPaidObservationFacts($order, $orderId);
            $binding = self::validatePokPayOrderBinding($order, $orderId, $reservationIds);
            if (empty($binding['success'])) {
                $observation = self::persistPaidProviderFailure('pokpay', $reservationIds, $paidFacts, (string) ($binding['reason_code'] ?? 'pokpay_order_binding_failed'));
                $binding['provider_paid'] = true;
                $binding['paid_observation_persisted'] = !empty($observation['success']);
                return $binding;
            }
            if (self::providerPaymentAlreadyCompleted($reservationIds, 'pokpay', $orderId)) {
                return [
                    'success' => true,
                    'state' => 'paid',
                ];
            }
            $completion = self::completeVerifiedOnlinePayment(
                'pokpay',
                $reservationIds,
                $orderId,
                $orderId,
                $allowClockRetry,
                'authoritative_provider_reread',
                '',
                $paidFacts
            );
            if (empty($completion['success'])) {
                return $completion;
            }
            (new PaymentProviderFeeService())->capturePokPayFeeSnapshotForReservations(
                $reservationIds,
                $order,
                $orderId
            );
            if (\function_exists('MustHotelBooking\Frontend\clear_booking_selection')) {
                \MustHotelBooking\Frontend\clear_booking_selection(false);
            }
            $redirectUrl = (new PublicBookingAccessService())->buildPublicUrl(
                ManagedPages::getBookingConfirmationPageUrl(),
                $reservationIds,
                PublicBookingAccessService::PURPOSE_VIEW_CONFIRMATION,
                [
                    'payment_method' => 'pokpay',
                    'pokpay_return' => 'success',
                    'order_id' => $orderId,
                ]
            );
            return [
                'success' => true,
                'state' => 'paid',
                'redirect_url' => $redirectUrl,
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
     * @param array<string, mixed> $data
     */
    private static function extractPokPaySdkConfirmUrl(array $data): string
    {
        foreach ([
            ['sdkOrder', '_self', 'confirmUrl'],
            ['sdkOrder', '_self', 'checkoutUrl'],
            ['sdkOrder', '_self', 'paymentUrl'],
            ['sdkOrder', 'checkoutUrl'],
            ['sdkOrder', 'paymentUrl'],
            ['sdkOrder', 'url'],
            ['_self', 'confirmUrl'],
            ['_self', 'checkoutUrl'],
            ['_self', 'paymentUrl'],
            ['order', '_self', 'confirmUrl'],
            ['order', '_self', 'checkoutUrl'],
            ['order', '_self', 'paymentUrl'],
        ] as $path) {
            $url = self::getNestedString($data, $path);
            if ($url !== '' && self::isPokPayCheckoutUrl($url)) {
                return $url;
            }
        }
        foreach (['sdkOrder', 'order', 'payment', 'checkout', 'data'] as $nestedKey) {
            if (isset($data[$nestedKey]) && \is_array($data[$nestedKey])) {
                $url = self::extractPokPaySdkConfirmUrl($data[$nestedKey]);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        foreach (['confirmUrl', 'checkoutUrl', 'paymentUrl', 'url'] as $key) {
            if (isset($data[$key]) && \is_scalar($data[$key])) {
                $url = \trim((string) $data[$key]);
                if ($url !== '' && self::isPokPayCheckoutUrl($url)) {
                    return $url;
                }
            }
        }
        return '';
    }
    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $path
     */
    private static function getNestedString(array $data, array $path): string
    {
        $cursor = $data;
        $lastIndex = \count($path) - 1;
        foreach ($path as $index => $key) {
            if (!\is_array($cursor) || !\array_key_exists($key, $cursor)) {
                return '';
            }
            if ($index === $lastIndex) {
                return \is_scalar($cursor[$key]) ? \trim((string) $cursor[$key]) : '';
            }
            $cursor = $cursor[$key];
        }
        return '';
    }
    /**
     * @param array<string, mixed> $data
     */
    private static function logPokPayMissingCheckoutUrlDiagnostics(array $data, string $orderId): void
    {
        $sdkOrder = isset($data['sdkOrder']) && \is_array($data['sdkOrder']) ? $data['sdkOrder'] : [];
        $sdkSelf = isset($sdkOrder['_self']) && \is_array($sdkOrder['_self']) ? $sdkOrder['_self'] : [];
        $context = [
            'endpoint' => 'POST merchants/{merchantId}/sdk-orders',
            'order_id' => \sanitize_text_field($orderId),
            'top_level_keys' => \array_values(\array_filter(\array_map('strval', \array_keys($data)))),
            'sdk_order_keys' => \array_values(\array_filter(\array_map('strval', \array_keys($sdkOrder)))),
            'sdk_order_self_keys' => \array_values(\array_filter(\array_map('strval', \array_keys($sdkSelf)))),
            'has_sdk_order_self_confirm_url' => isset($sdkSelf['confirmUrl']) && \is_scalar($sdkSelf['confirmUrl']) && \trim((string) $sdkSelf['confirmUrl']) !== '',
        ];
        \error_log('MUST Hotel Booking PokPay missing confirmUrl: ' . \wp_json_encode($context));
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
        foreach (['sdkOrder', 'order', 'payment', 'data'] as $nestedKey) {
            if (isset($data[$nestedKey]) && \is_array($data[$nestedKey])) {
                $value = self::extractPokPayOrderId($data[$nestedKey]);
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
        foreach (['sdkOrder', 'order', 'payment', 'data'] as $nestedKey) {
            if (isset($data[$nestedKey]) && \is_array($data[$nestedKey])) {
                $value = self::getPokPayOrderStatus($data[$nestedKey]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        if (!empty($data['isRefunded'])) {
            return 'REFUNDED';
        }
        if (!empty($data['isCaptured'])) {
            return 'CAPTURED';
        }
        if (!empty($data['isCompleted'])) {
            return 'CAPTURED';
        }
        $capturedAmount = self::firstNumericValue($data, ['capturedAmount', 'captured_amount']);
        $finalAmount = self::firstNumericValue($data, ['finalAmount', 'final_amount', 'amount']);
        if ($capturedAmount !== null && $finalAmount !== null && $finalAmount > 0 && $capturedAmount >= $finalAmount) {
            return 'CAPTURED';
        }
        return '';
    }
    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    private static function firstNumericValue(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && \is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        foreach (['sdkOrder', 'order', 'payment', 'data'] as $nestedKey) {
            if (isset($data[$nestedKey]) && \is_array($data[$nestedKey])) {
                $value = self::firstNumericValue($data[$nestedKey], $keys);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }
    /**
     * @param array<string, mixed> $data
     */
    private static function extractPokPayRefundReference(array $data): string
    {
        if (isset($data['refund']) && \is_array($data['refund'])) {
            $nested = self::extractPokPayRefundReference($data['refund']);
            if ($nested !== '') {
                return $nested;
            }
        }
        if (isset($data['sdkOrder']) && \is_array($data['sdkOrder'])) {
            $nested = self::extractPokPayRefundReference($data['sdkOrder']);
            if ($nested !== '') {
                return $nested;
            }
        }
        foreach (['refundId', 'refund_id', 'providerRefundId', 'provider_refund_id', 'transactionId', 'id'] as $key) {
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
    private static function getPokPayRefundStatus(array $data): string
    {
        if (isset($data['refund']) && \is_array($data['refund'])) {
            $nested = self::getPokPayRefundStatus($data['refund']);
            if ($nested !== '') {
                return $nested;
            }
        }
        if (isset($data['sdkOrder']) && \is_array($data['sdkOrder'])) {
            $nested = self::getPokPayRefundStatus($data['sdkOrder']);
            if ($nested !== '') {
                return $nested;
            }
        }
        foreach (['status', 'refundStatus', 'refund_status', 'state'] as $key) {
            if (isset($data[$key]) && \is_scalar($data[$key])) {
                return \strtolower(\sanitize_key((string) $data[$key]));
            }
        }
        if (!empty($data['isRefunded'])) {
            return 'succeeded';
        }
        return '';
    }
    private static function getPokPayAccessTokenCacheKey(string $environment = ''): string
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();
        $credentials = self::getPokPayEnvironmentCredentials($environment);
        return 'must_hotel_booking_pokpay_token_' . \md5(
            self::getPokPayApiEnvironment($environment)
            . '|'
            . $credentials['merchant_id']
            . '|'
            . $credentials['key_id']
            . '|'
            . self::pokPayCredentialFingerprint($credentials)
        );
    }
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function performPokPayApiRequest(
        string $method,
        string $path,
        array $body = [],
        bool $authenticate = true,
        array $options = []
    ): array
    {
        return self::performPokPayApiRequestOnce($method, $path, $body, $authenticate, true, $options);
    }
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function performPokPayApiRequestOnce(
        string $method,
        string $path,
        array $body = [],
        bool $authenticate = true,
        bool $allowTokenRetry = true,
        array $options = []
    ): array {
        $started = \microtime(true);
        $environment = isset($options['environment'])
            ? self::normalizeStripeEnvironment((string) $options['environment'])
            : self::getActiveSiteEnvironment();
        $apiEnvironment = self::getPokPayApiEnvironment($environment);
        $credentials = self::getPokPayEnvironmentCredentials($environment);
        $operation = isset($options['operation'])
            ? \sanitize_text_field((string) $options['operation'])
            : self::pokPayOperationName($method, $path);
        $correlationId = self::providerCorrelationId();
        $logs = new ProviderRequestLogRepository();
        $logId = $logs->create([
            'provider' => 'pokpay',
            'operation' => $operation,
            'direction' => 'outbound',
            'correlation_id' => $correlationId,
            'idempotency_key' => isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : '',
            'reservation_id' => isset($options['reservation_id']) ? (int) $options['reservation_id'] : 0,
            'external_id' => isset($options['external_id']) ? (string) $options['external_id'] : '',
            'request_summary' => [
                'environment' => $apiEnvironment,
                'site_environment' => $environment,
                'endpoint_name' => isset($options['endpoint_name']) ? (string) $options['endpoint_name'] : \trim($path, '/'),
                'http_method' => \strtoupper($method),
                'attempt' => $allowTokenRetry ? 1 : 2,
                'merchant_id_masked' => ProviderDataSanitizer::maskIdentifier((string) ($options['merchant_id'] ?? $credentials['merchant_id'])),
                'key_id_masked' => ProviderDataSanitizer::maskIdentifier((string) ($options['key_id'] ?? $credentials['key_id'])),
                'booking_reference' => isset($options['booking_reference']) ? (string) $options['booking_reference'] : '',
                'payment_id' => isset($options['payment_id']) ? (int) $options['payment_id'] : 0,
                'body' => ProviderDataSanitizer::sanitize($body),
            ],
        ]);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($authenticate) {
            $tokenResult = self::getPokPayAccessToken($environment);
            if (empty($tokenResult['success'])) {
                $result = \array_merge($tokenResult, [
                    'correlation_id' => $correlationId,
                    'duration_ms' => self::providerDurationMs($started),
                ]);
                self::completeProviderLog($logs, $logId, $result, []);
                return $result;
            }
            $headers['Authorization'] = 'Bearer ' . (string) ($tokenResult['access_token'] ?? '');
        }
        $idempotencyKey = isset($options['idempotency_key'])
            ? \sanitize_text_field((string) $options['idempotency_key'])
            : '';
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $args = [
            'method' => \strtoupper($method),
            'timeout' => 20,
            'headers' => $headers,
        ];
        if (!empty($body)) {
            $args['body'] = \wp_json_encode($body);
        }
        $response = \wp_remote_request(self::getPokPayBaseUrl($environment) . '/' . \ltrim($path, '/'), $args);
        if (\is_wp_error($response)) {
            $result = [
                'success' => false,
                'status_code' => 0,
                'body' => [],
                'error_code' => (string) $response->get_error_code(),
                'message' => (string) $response->get_error_message(),
                'retryable' => true,
                'correlation_id' => $correlationId,
                'duration_ms' => self::providerDurationMs($started),
            ];
            self::completeProviderLog($logs, $logId, $result, []);
            return $result;
        }
        $statusCode = (int) \wp_remote_retrieve_response_code($response);
        $decodedBody = \json_decode((string) \wp_remote_retrieve_body($response), true);
        $bodyArray = \is_array($decodedBody) ? $decodedBody : [];
        $message = self::extractPokPayErrorMessage($bodyArray);
        $errorCode = self::extractPokPayErrorCode($bodyArray);
        $messageLower = \strtolower($message);
        if (
            $authenticate
            && $allowTokenRetry
            && (
                $statusCode === 401
                || \strpos($messageLower, 'expired token') !== false
                || \strpos($messageLower, 'token expired') !== false
                || \strpos($messageLower, 'jwt expired') !== false
                || \strpos($messageLower, 'unauthorized') !== false
            )
        ) {
            \delete_transient(self::getPokPayAccessTokenCacheKey($environment));
            $result = self::performPokPayApiRequestOnce(
                $method,
                $path,
                $body,
                $authenticate,
                false,
                $options
            );
            self::completeProviderLog(
                $logs,
                $logId,
                [
                    'success' => !empty($result['success']),
                    'status_code' => (int) ($result['status_code'] ?? 0),
                    'error_code' => (string) ($result['error_code'] ?? ''),
                    'message' => (string) ($result['message'] ?? ''),
                    'retryable' => !empty($result['retryable']),
                    'duration_ms' => self::providerDurationMs($started),
                ],
                ['token_retry' => true]
            );
            return $result;
        }
        $result = [
            'success' => $statusCode >= 200 && $statusCode < 300 && \is_array($decodedBody),
            'status_code' => $statusCode,
            'body' => $bodyArray,
            'error_code' => $errorCode,
            'message' => ProviderDataSanitizer::sanitizeText($message),
            'retryable' => $statusCode === 429 || $statusCode >= 500,
            'correlation_id' => $correlationId,
            'duration_ms' => self::providerDurationMs($started),
        ];
        self::completeProviderLog($logs, $logId, $result, [
            'environment' => $apiEnvironment,
            'merchant_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['merchant_id']),
            'key_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['key_id']),
        ]);
        return $result;
    }
    /**
     * @return array<string, mixed>
     */
    private static function getPokPayAccessToken(string $environment = ''): array
    {
        $environment = $environment !== '' ? self::normalizeStripeEnvironment($environment) : self::getActiveSiteEnvironment();
        $credentials = self::getPokPayEnvironmentCredentials($environment);
        if ($credentials['key_id'] === '' || $credentials['key_secret'] === '') {
            return [
                'success' => false,
                'message' => \__('PokPay key id or key secret is missing.', 'must-hotel-booking'),
            ];
        }
        $cacheKey = self::getPokPayAccessTokenCacheKey($environment);
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
            false,
            [
                'environment' => $environment,
                'operation' => 'pokpay.authentication',
                'endpoint_name' => 'auth_sdk_login',
                'merchant_id' => $credentials['merchant_id'],
                'key_id' => $credentials['key_id'],
            ]
        );
        if (empty($response['success'])) {
            $statusCode = (int) ($response['status_code'] ?? 0);
            $status = \in_array($statusCode, [400, 401, 403], true)
                ? 'rejected'
                : (($statusCode === 0 || $statusCode === 429 || $statusCode >= 500) ? 'provider_unavailable' : 'malformed');
            self::savePokPayCredentialState($environment, $credentials, [
                'environment' => self::getPokPayApiEnvironment($environment),
                'site_environment' => $environment,
                'status' => $status,
                'authentication_success' => false,
                'http_status' => $statusCode,
                'error_code' => (string) ($response['error_code'] ?? ''),
                'error_message' => (string) ($response['message'] ?? ''),
                'verified_at' => \current_time('mysql'),
                'merchant_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['merchant_id']),
                'key_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['key_id']),
            ]);
            return [
                'success' => false,
                'status_code' => $statusCode,
                'error_code' => (string) ($response['error_code'] ?? ''),
                'retryable' => !empty($response['retryable']),
                'message' => isset($response['message']) && (string) $response['message'] !== ''
                    ? (string) $response['message']
                    : \__('PokPay authentication failed.', 'must-hotel-booking'),
            ];
        }
        $data = self::extractPokPayResponseData(isset($response['body']) && \is_array($response['body']) ? $response['body'] : []);
        $accessToken = isset($data['accessToken']) ? \trim((string) $data['accessToken']) : '';
        if ($accessToken === '') {
            self::savePokPayCredentialState($environment, $credentials, [
                'environment' => self::getPokPayApiEnvironment($environment),
                'site_environment' => $environment,
                'status' => 'malformed',
                'authentication_success' => false,
                'http_status' => (int) ($response['status_code'] ?? 0),
                'error_code' => 'access_token_missing',
                'error_message' => \__('PokPay authentication returned no access token.', 'must-hotel-booking'),
                'verified_at' => \current_time('mysql'),
                'merchant_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['merchant_id']),
                'key_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['key_id']),
            ]);
            return [
                'success' => false,
                'message' => \__('PokPay authentication returned no access token.', 'must-hotel-booking'),
            ];
        }
        self::cachePokPayAccessToken($environment, $credentials, $accessToken, $data);
        self::savePokPayCredentialState($environment, $credentials, [
            'environment' => self::getPokPayApiEnvironment($environment),
            'site_environment' => $environment,
            'status' => 'verified',
            'authentication_success' => true,
            'http_status' => (int) ($response['status_code'] ?? 0),
            'error_code' => '',
            'error_message' => '',
            'verified_at' => \current_time('mysql'),
            'merchant_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['merchant_id']),
            'key_id_masked' => ProviderDataSanitizer::maskIdentifier($credentials['key_id']),
        ]);
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
     * @param array<string, mixed> $body
     */
    private static function extractPokPayErrorCode(array $body): string
    {
        foreach (['code', 'errorCode', 'error_code', 'type'] as $key) {
            if (isset($body[$key]) && \is_scalar($body[$key])) {
                return \sanitize_text_field((string) $body[$key]);
            }
        }

        if (isset($body['error']) && \is_array($body['error'])) {
            return self::extractPokPayErrorCode($body['error']);
        }

        return '';
    }

    /**
     * @param array{merchant_id:string,key_id:string,key_secret:string} $credentials
     * @param array<string, mixed> $data
     */
    private static function cachePokPayAccessToken(
        string $environment,
        array $credentials,
        string $accessToken,
        array $data
    ): void {
        if ($accessToken === '' || !\function_exists('set_transient')) {
            return;
        }

        $expiresIn = isset($data['expiresIn']) ? (int) $data['expiresIn'] : 300;

        if ($expiresIn > 86400) {
            $expiresIn = (int) \floor($expiresIn / 1000);
        }

        if ($expiresIn <= 0 && isset($data['expiresAt'])) {
            $expiresAt = \strtotime((string) $data['expiresAt']);
            $expiresIn = $expiresAt !== false ? \max(60, $expiresAt - \time()) : 300;
        }

        unset($credentials);
        \set_transient(self::getPokPayAccessTokenCacheKey($environment), $accessToken, \max(60, $expiresIn - 60));
    }

    /**
     * @param array{merchant_id:string,key_id:string,key_secret:string} $credentials
     * @param array<string, mixed> $state
     */
    private static function savePokPayCredentialState(string $environment, array $credentials, array $state): void
    {
        if (!\function_exists('get_option') || !\function_exists('update_option')) {
            return;
        }

        $saved = \get_option(self::POKPAY_VERIFICATION_OPTION, []);
        $saved = \is_array($saved) ? $saved : [];
        $saved[$environment] = ProviderDataSanitizer::sanitize($state);
        $saved[$environment]['credential_fingerprint'] = self::pokPayCredentialFingerprint($credentials);
        \update_option(self::POKPAY_VERIFICATION_OPTION, $saved, false);
    }

    /** @param array{merchant_id:string,key_id:string,key_secret:string} $credentials */
    private static function pokPayCredentialFingerprint(array $credentials): string
    {
        $material = $credentials['merchant_id'] . '|' . $credentials['key_id'] . '|' . $credentials['key_secret'];
        $salt = \function_exists('wp_salt') ? \wp_salt('auth') : __FILE__;

        return \hash_hmac('sha256', $material, $salt);
    }

    private static function pokPayOperationName(string $method, string $path): string
    {
        $normalized = \trim((string) \preg_replace('/[^a-z0-9]+/i', '_', \strtolower($path)), '_');

        return 'pokpay.' . \strtolower($method) . ($normalized !== '' ? '.' . $normalized : '');
    }

    private static function providerCorrelationId(): string
    {
        if (\function_exists('wp_generate_uuid4')) {
            return \wp_generate_uuid4();
        }

        return \bin2hex(\random_bytes(16));
    }

    private static function providerDurationMs(float $started): int
    {
        return \max(0, (int) \round((\microtime(true) - $started) * 1000));
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $responseSummary
     */
    private static function completeProviderLog(
        ProviderRequestLogRepository $logs,
        int $logId,
        array $result,
        array $responseSummary
    ): void {
        if ($logId <= 0) {
            return;
        }

        $body = isset($result['body']) && \is_array($result['body']) ? $result['body'] : [];
        $logs->complete($logId, [
            'http_status' => (int) ($result['status_code'] ?? 0),
            'success' => !empty($result['success']) ? 1 : 0,
            'error_code' => (string) ($result['error_code'] ?? ''),
            'error_message' => (string) ($result['message'] ?? ''),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'response_summary' => \array_merge(
                [
                    'retryable' => !empty($result['retryable']),
                    'provider_error_code' => (string) ($result['error_code'] ?? ''),
                    'provider_error_message' => (string) ($result['message'] ?? ''),
                    'body' => ProviderDataSanitizer::sanitize($body),
                ],
                $responseSummary
            ),
        ]);
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
            if (self::resumeVerifiedPendingClockFulfilment([(int) $reservationId])) {
                continue;
            }
            if (self::hasPendingClockFulfilment([(int) $reservationId])) {
                continue;
            }
            $stripeReservationIds[] = (int) $reservationId;
        }
        foreach ($pokPayReservationIdsByOrder as $orderId => $orderReservationIds) {
            if (self::resumeVerifiedPendingClockFulfilment($orderReservationIds)) {
                continue;
            }
            if (self::hasPendingClockFulfilment($orderReservationIds)) {
                continue;
            }
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
        \register_rest_route(
            'must-hotel-booking/v1',
            '/pokpay/webhook',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'handlePokPayWebhookRequest'],
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
            if ($type === 'checkout.session.completed'
                && (string) ($object['payment_status'] ?? '') === 'paid'
                && (string) ($object['status'] ?? '') === 'complete') {
                $sessionId = isset($object['id']) ? (string) $object['id'] : '';
                $transactionId = isset($object['payment_intent']) && (string) $object['payment_intent'] !== ''
                    ? (string) $object['payment_intent']
                    : $sessionId;
                $facts = self::buildStripePaidObservationFacts($object, $transactionId, $sessionId, 'stripe_signed_webhook', (string) ($event['id'] ?? ''));
                $observation = self::persistPaidProviderFailure('stripe', [], $facts, 'stripe_reservation_metadata_missing', []);
                return new \WP_REST_Response([
                    'success' => true,
                    'state' => 'manual_review',
                    'provider_paid' => true,
                    'paid_observation_persisted' => !empty($observation['success']),
                ], 200);
            }
            return new \WP_REST_Response(['success' => true], 200);
        }
        if ($type === 'checkout.session.completed') {
            if ((string) ($object['payment_status'] ?? '') !== 'paid' || (string) ($object['status'] ?? '') !== 'complete') {
                return new \WP_REST_Response(['success' => true, 'state' => 'pending'], 200);
            }
            $paymentIntent = isset($object['payment_intent']) ? (string) $object['payment_intent'] : '';
            $sessionId = isset($object['id']) ? (string) $object['id'] : '';
            $transactionId = $paymentIntent !== '' ? $paymentIntent : $sessionId;
            if (self::stripeCompletedSessionAlreadyRecorded($reservationIds, $transactionId)) {
                return new \WP_REST_Response(['success' => true], 200);
            }
            $binding = self::validateStripeSessionFacts($object, $reservationIds);
            if (empty($binding['success'])) {
                $facts = self::buildStripePaidObservationFacts($object, $transactionId, $sessionId, 'stripe_signed_webhook', (string) ($event['id'] ?? ''));
                $observation = self::persistPaidProviderFailure('stripe', $reservationIds, $facts, (string) ($binding['reason_code'] ?? 'stripe_session_binding_failed'), self::getReservationIdsFromStripeSession($object));
                $binding['provider_paid'] = true;
                $binding['paid_observation_persisted'] = !empty($observation['success']);
                return new \WP_REST_Response($binding, 409);
            }
            if (!self::reservationPaymentIdsMatchTransaction($reservationIds, 'stripe', $sessionId)) {
                $facts = self::buildStripePaidObservationFacts($object, $transactionId, $sessionId, 'stripe_signed_webhook', (string) ($event['id'] ?? ''));
                $observation = self::persistPaidProviderFailure('stripe', $reservationIds, $facts, 'stripe_payment_binding_mismatch', self::getReservationIdsFromStripeSession($object));
                return new \WP_REST_Response([
                    'success' => false,
                    'state' => 'integrity_error',
                    'provider_paid' => true,
                    'paid_observation_persisted' => !empty($observation['success']),
                    'message' => \__('Stripe session is not bound to this local payment reservation.', 'must-hotel-booking'),
                    'reason_code' => 'stripe_payment_binding_mismatch',
                ], 409);
            }
            $completion = self::completeVerifiedOnlinePayment(
                'stripe',
                $reservationIds,
                $transactionId,
                $sessionId,
                true,
                'stripe_signed_webhook',
                (string) ($event['id'] ?? ''),
                self::buildStripePaidObservationFacts($object, $transactionId, $sessionId, 'stripe_signed_webhook', (string) ($event['id'] ?? ''))
            );
            if (empty($completion['success'])) {
                return new \WP_REST_Response(
                    $completion,
                    !empty($completion['retryable']) ? 503 : 409,
                    !empty($completion['retryable']) ? ['Retry-After' => '30'] : []
                );
            }
            (new PaymentProviderFeeService())->captureStripeFeeSnapshotForReservations($reservationIds, $object);
        } elseif ($type === 'checkout.session.expired') {
            $sessionId = isset($object['id']) ? (string) $object['id'] : '';
            if (!self::reservationPaymentIdsMatchTransaction($reservationIds, 'stripe', $sessionId)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'state' => 'integrity_error',
                    'message' => \__('Expired Stripe session is not bound to this local payment reservation.', 'must-hotel-booking'),
                ], 409);
            }
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
        $publicAccess = new PublicBookingAccessService();
        $requestQuery = method_exists($request, 'get_query_params') ? $request->get_query_params() : [];
        $requestQuery = \is_array($requestQuery) ? $requestQuery : [];
        $accessGrant = $publicAccess->authorizeRequest(
            PublicBookingAccessService::PURPOSE_VIEW_CONFIRMATION,
            $requestQuery,
            \is_array($_COOKIE) ? $_COOKIE : []
        );
        $reservationIds = !empty($accessGrant['success'])
            ? PublicBookingAccessService::normalizeReservationIds((array) ($accessGrant['reservation_ids'] ?? []))
            : [];
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
        \error_log('MUST Hotel Booking PokPay checkout error: ' . \wp_json_encode(ProviderDataSanitizer::sanitize($context)));
        return new \WP_REST_Response(['success' => true], 200);
    }
    public static function handlePokPayWebhookRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        $params = \is_array($params) ? $params : [];
        $data = self::extractPokPayResponseData($params);
        $orderId = self::extractPokPayOrderId($data);
        if ($orderId === '') {
            return new \WP_REST_Response(['success' => false, 'message' => 'Missing PokPay order id.'], 400);
        }
        $reservationIds = get_payment_repository()->findReservationIdsByTransactionId($orderId);
        if (empty($reservationIds)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Unknown PokPay order id.'], 404);
        }
        $result = self::finalizePokPayOrder($orderId, $reservationIds, true);
        return new \WP_REST_Response([
            'success' => !empty($result['success']),
            'state' => isset($result['state']) ? (string) $result['state'] : '',
            'message' => isset($result['message']) ? (string) $result['message'] : '',
        ], !empty($result['success']) ? 200 : (!empty($result['retryable']) ? 503 : 409), !empty($result['retryable']) ? ['Retry-After' => '30'] : []);
    }

    /** @param array<int, int> $reservationIds @return array<string, mixed> */
    private static function completeVerifiedOnlinePayment(
        string $method,
        array $reservationIds,
        string $transactionId,
        string $providerReference,
        bool $allowClockRetry,
        string $verificationSource = 'authoritative_provider_reread',
        string $providerEventReference = '',
        array $observedFacts = []
    ): array {
        $method = self::normalizeMethod($method);
        $reservationIds = self::normalizeReservationIds($reservationIds);
        if (!\in_array($method, ['stripe', 'pokpay'], true) || empty($reservationIds) || \trim($transactionId) === '') {
            return [
                'success' => false,
                'state' => 'integrity_error',
                'message' => \__('The verified payment data is incomplete.', 'must-hotel-booking'),
            ];
        }

        $verifiedFacts = self::buildVerifiedPaymentFacts($method, $reservationIds, $transactionId, $providerReference, $verificationSource, $providerEventReference);
        foreach (['provider_mode', 'provider_account_fingerprint', 'total_amount_minor', 'currency', 'provider_completed_at'] as $observedField) {
            if (\array_key_exists($observedField, $observedFacts) && (string) $observedFacts[$observedField] !== '') {
                $verifiedFacts[$observedField] = $observedFacts[$observedField];
            }
        }
        $verifiedFacts['observed_reservation_ids'] = isset($observedFacts['observed_reservation_ids']) && \is_array($observedFacts['observed_reservation_ids'])
            ? $observedFacts['observed_reservation_ids']
            : $reservationIds;
        if (empty($verifiedFacts['server_verified'])) {
            $observation = self::persistPaidProviderFailure($method, $reservationIds, $verifiedFacts, 'verified_payment_facts_incomplete');
            return [
                'success' => false,
                'state' => 'manual_review',
                'retryable' => false,
                'provider_paid' => true,
                'paid_observation_persisted' => !empty($observation['success']),
                'message' => \__('The authoritative payment result could not be bound to this reservation group.', 'must-hotel-booking'),
            ];
        }
        $durableOwnership = (new OnlinePaymentVerificationService())->recordAndConfirm(
            $method,
            $reservationIds,
            $verifiedFacts + ['defer_confirmation' => true]
        );
        if (empty($durableOwnership['success'])) {
            return [
                'success' => false,
                'state' => 'manual_review',
                'retryable' => false,
                'reason_code' => (string) ($durableOwnership['reason_code'] ?? ''),
                'provider_paid' => true,
                'paid_observation_persisted' => !empty($durableOwnership['paid_observation_persisted']),
                'message' => \__('Verified payment ownership could not be recorded safely.', 'must-hotel-booking'),
            ];
        }
        $attemptRows = get_payment_repository()->getPaymentAttemptRows($method, $providerReference);
        $attemptPaymentIds = \array_values(\array_filter(\array_map('intval', \array_column($attemptRows, 'id'))));
        if (empty($attemptPaymentIds) || !get_payment_repository()->updatePaymentAttemptRows($attemptPaymentIds, ['attempt_status' => 'verified', 'attempt_failure_code' => ''])) {
            $observation = self::persistPaidProviderFailure($method, $reservationIds, $verifiedFacts, 'verified_attempt_status_persistence_failed');
            return [
                'success' => false,
                'state' => 'manual_review',
                'retryable' => false,
                'provider_paid' => true,
                'paid_observation_persisted' => !empty($observation['success']),
                'reason_code' => 'verified_attempt_status_persistence_failed',
                'message' => \__('Payment was verified, but its attempt status could not be stored safely.', 'must-hotel-booking'),
            ];
        }

        $clockRequired = false;
        foreach ($reservationIds as $reservationId) {
            $reservation = get_reservation_repository()->getReservation((int) $reservationId);
            if (\is_array($reservation) && \sanitize_key((string) ($reservation['provider'] ?? '')) === ProviderManager::CLOCK_MODE) {
                $clockRequired = true;
                break;
            }
        }
        if ($clockRequired) {
            $durableVerification = self::recordVerifiedClockPaymentFulfilment(
                $method,
                $reservationIds,
                $transactionId,
                $providerReference
            );
            if (empty($durableVerification['success'])) {
                $observation = self::persistPaidProviderFailure($method, $reservationIds, $verifiedFacts, (string) ($durableVerification['reason_code'] ?? $durableVerification['state'] ?? 'clock_verification_persistence_failed'));
                return \array_merge($durableVerification, [
                    'provider_paid' => true,
                    'paid_observation_persisted' => !empty($observation['success']),
                ]);
            }
            $reservations = new \MustHotelBooking\Provider\Clock\ClockReservationProvider();
            $clockResult = $reservations->fulfillPendingOnlinePayment($reservationIds, $method, $providerReference, $allowClockRetry);
            if (empty($clockResult['success'])) {
                $recoveryStatus = !empty($clockResult['retryable'])
                    ? 'processing_pending'
                    : ((string) ($clockResult['state'] ?? '') === 'partial_manual_review' ? 'partial_manual_review' : 'manual_review');
                $observation = self::persistPaidProviderFailure(
                    $method,
                    $reservationIds,
                    $verifiedFacts,
                    (string) ($clockResult['reason_code'] ?? $clockResult['state'] ?? 'clock_fulfilment_failed'),
                    isset($clockResult['fulfilled_reservation_ids']) && \is_array($clockResult['fulfilled_reservation_ids']) ? $clockResult['fulfilled_reservation_ids'] : [],
                    $recoveryStatus
                );
                return \array_merge($clockResult, [
                    'provider_paid' => true,
                    'paid_observation_persisted' => !empty($observation['success']),
                ]);
            }
        }

        $completion = (new OnlinePaymentVerificationService())->recordAndConfirm($method, $reservationIds, $verifiedFacts);
        if (empty($completion['success']) || !self::allReservationsConfirmed($reservationIds)) {
            $observation = !empty($completion['paid_observation_persisted'])
                ? ['success' => true]
                : self::persistPaidProviderFailure($method, $reservationIds, $verifiedFacts, (string) ($completion['reason_code'] ?? 'confirmation_incomplete'));
            return [
                'success' => false,
                'state' => 'manual_review',
                'retryable' => false,
                'reason_code' => (string) ($completion['reason_code'] ?? ''),
                'provider_paid' => true,
                'paid_observation_persisted' => !empty($observation['success']),
                'message' => \__('Payment was verified, but local confirmation was blocked and requires review.', 'must-hotel-booking'),
            ];
        }

        if (\function_exists('MustHotelBooking\\Frontend\\clear_booking_selection')) {
            \MustHotelBooking\Frontend\clear_booking_selection(false);
        }
        (new \MustHotelBooking\Database\PaidProviderObservationRepository())->markResolved(
            $method,
            $transactionId,
            (string) ($verifiedFacts['provider_mode'] ?? ''),
            (string) ($verifiedFacts['provider_account_fingerprint'] ?? '')
        );
        return [
            'success' => true,
            'state' => 'paid',
        ];
    }

    /** @param array<int, int> $reservationIds @param array<string, mixed> $facts @return array<string, mixed> */
    private static function persistPaidProviderFailure(string $method, array $reservationIds, array $facts, string $reasonCode, array $observedReservationIds = [], string $recoveryStatus = 'manual_review'): array
    {
        return (new PaidProviderOutcomeService())->persist(
            $method,
            $reservationIds,
            $facts,
            $reasonCode,
            $observedReservationIds,
            $recoveryStatus
        );
    }

    /** @param array<int, int> $reservationIds @return array<string, mixed> */
    private static function buildVerifiedPaymentFacts(string $method, array $reservationIds, string $transactionId, string $providerReference, string $verificationSource, string $providerEventReference = ''): array
    {
        $currency = \strtoupper(\sanitize_text_field((string) MustBookingConfig::get_currency()));
        $total = self::sumReservationTotals($reservationIds);
        $environment = self::getActiveSiteEnvironment();
        $identity = (new PaymentEnvironmentCompatibilityPolicy())->currentGatewayIdentity($method, $environment);
        $mode = (string) ($identity['mode'] ?? '');
        $fingerprint = (string) ($identity['account_fingerprint'] ?? '');
        return [
            'server_verified' => $currency !== '' && $total !== null && $total > 0.0 && $transactionId !== '' && $providerReference !== '' && $fingerprint !== '',
            'provider_transaction_reference' => \sanitize_text_field($transactionId),
            'provider_attempt_reference' => \sanitize_text_field($providerReference),
            'provider_mode' => $mode,
            'provider_account_fingerprint' => $fingerprint,
            'total_amount_minor' => $total !== null ? self::convertAmountToMinorUnits($total, $currency) : -1,
            'currency' => $currency,
            'verification_source' => \sanitize_key($verificationSource) ?: 'authoritative_provider_reread',
            'provider_event_reference' => \sanitize_text_field($providerEventReference),
            'raw_response_hash' => '',
            'provider_completed_at' => '',
        ];
    }

    /** @param array<string, mixed> $session @return array<string, mixed> */
    private static function buildStripePaidObservationFacts(array $session, string $transactionId, string $sessionId, string $verificationSource = 'authoritative_provider_reread', string $providerEventReference = ''): array
    {
        $identity = (new PaymentEnvironmentCompatibilityPolicy())->currentGatewayIdentity('stripe');
        return [
            'provider_transaction_reference' => \sanitize_text_field($transactionId),
            'provider_attempt_reference' => \sanitize_text_field($sessionId),
            'provider_mode' => isset($session['livemode']) ? (!empty($session['livemode']) ? 'live' : 'test') : (string) ($identity['mode'] ?? ''),
            'provider_account_fingerprint' => (string) ($identity['account_fingerprint'] ?? ''),
            'total_amount_minor' => isset($session['amount_total']) && \is_numeric($session['amount_total']) ? (int) $session['amount_total'] : -1,
            'currency' => \strtoupper(\sanitize_text_field((string) ($session['currency'] ?? ''))),
            'provider_completed_at' => isset($session['created']) && \is_numeric($session['created']) ? \gmdate('Y-m-d H:i:s', (int) $session['created']) : '',
            'verification_source' => \sanitize_key($verificationSource),
            'provider_event_reference' => \sanitize_text_field($providerEventReference),
        ];
    }

    /** @param array<string, mixed> $order @return array<string, mixed> */
    private static function buildPokPayPaidObservationFacts(array $order, string $orderId): array
    {
        $identity = (new PaymentEnvironmentCompatibilityPolicy())->currentGatewayIdentity('pokpay');
        $currency = \strtoupper(self::firstTextValue($order, ['currencyCode', 'currency', 'currency_code']));
        $amount = self::firstNumericValue($order, ['amount', 'amountPaid', 'amount_paid', 'finalAmount', 'final_amount']);
        $completedAt = self::firstTextValue($order, ['completedAt', 'completed_at', 'capturedAt', 'captured_at']);
        $completedTimestamp = $completedAt !== '' ? \strtotime($completedAt) : false;
        return [
            'provider_transaction_reference' => \sanitize_text_field($orderId),
            'provider_attempt_reference' => \sanitize_text_field($orderId),
            'provider_mode' => (string) ($identity['mode'] ?? ''),
            'provider_account_fingerprint' => (string) ($identity['account_fingerprint'] ?? ''),
            'total_amount_minor' => $amount !== null && $currency !== '' ? self::convertAmountToMinorUnits($amount, $currency) : -1,
            'currency' => $currency,
            'provider_completed_at' => $completedTimestamp !== false ? \gmdate('Y-m-d H:i:s', $completedTimestamp) : '',
            'verification_source' => 'authoritative_provider_reread',
            'provider_event_reference' => '',
        ];
    }

    /** @param array<string, mixed> $session @param array<int, int> $reservationIds @return array<string, mixed> */
    private static function validateStripeSessionFacts(array $session, array $reservationIds): array
    {
        $metadataReservationIds = self::getReservationIdsFromStripeSession($session);
        if (!self::sameReservationIds($metadataReservationIds, $reservationIds)) {
            return [
                'success' => false,
                'state' => 'integrity_error',
                'message' => \__('Stripe session metadata does not match this reservation set.', 'must-hotel-booking'),
                'reason_code' => 'stripe_reservation_metadata_mismatch',
            ];
        }

        $currency = \strtoupper(\sanitize_text_field((string) ($session['currency'] ?? '')));
        $expectedCurrency = \strtoupper(\sanitize_text_field((string) MustBookingConfig::get_currency()));
        $amountMinor = isset($session['amount_total']) && \is_numeric($session['amount_total']) ? (int) $session['amount_total'] : 0;
        $expectedAmount = self::sumReservationTotals($reservationIds);
        if ($currency === '' || $amountMinor <= 0 || $expectedAmount === null || $currency !== $expectedCurrency
            || $amountMinor !== self::convertAmountToMinorUnits($expectedAmount, $currency)) {
            return [
                'success' => false,
                'state' => 'integrity_error',
                'message' => \__('Stripe payment amount or currency does not match the reservation.', 'must-hotel-booking'),
                'reason_code' => 'stripe_amount_currency_mismatch',
            ];
        }

        return ['success' => true];
    }

    /** @param array<string, mixed> $order @param array<int, int> $reservationIds @return array<string, mixed> */
    private static function validatePokPayOrderBinding(array $order, string $orderId, array $reservationIds): array
    {
        if (!self::reservationPaymentsMatchPokPayOrder($reservationIds, $orderId)) {
            return [
                'success' => false,
                'state' => 'integrity_error',
                'message' => \__('This PokPay order does not match the local payment reservation.', 'must-hotel-booking'),
                'reason_code' => 'pokpay_reservation_binding_mismatch',
            ];
        }

        $amount = self::firstNumericValue($order, ['amount', 'amountPaid', 'amount_paid', 'finalAmount', 'final_amount']);
        $currency = \strtoupper(self::firstTextValue($order, ['currencyCode', 'currency', 'currency_code']));
        $expectedAmount = self::sumReservationTotals($reservationIds);
        $expectedCurrency = \strtoupper(\sanitize_text_field((string) MustBookingConfig::get_currency()));
        if ($amount === null || $currency === '' || $expectedAmount === null || $currency !== $expectedCurrency || \abs($amount - $expectedAmount) > 0.01) {
            return [
                'success' => false,
                'state' => 'integrity_error',
                'message' => \__('PokPay payment amount or currency does not match the reservation.', 'must-hotel-booking'),
                'reason_code' => 'pokpay_amount_currency_mismatch',
            ];
        }

        return ['success' => true];
    }

    /** @param array<int, int> $reservationIds */
    private static function providerPaymentAlreadyCompleted(array $reservationIds, string $method, string $transactionId): bool
    {
        foreach (self::normalizeReservationIds($reservationIds) as $reservationId) {
            $paymentId = get_payment_repository()->getLatestPaymentIdForReservationMethodTransaction($reservationId, $method, $transactionId);
            $payment = $paymentId > 0 ? get_payment_repository()->getPayment($paymentId) : null;
            $reservation = get_reservation_repository()->getReservation($reservationId);
            if (
                !\is_array($payment)
                || \sanitize_key((string) ($payment['status'] ?? '')) !== 'paid'
                || !\is_array($reservation)
                || !ReservationStatus::isConfirmed((string) ($reservation['status'] ?? ''))
            ) {
                return false;
            }
        }
        return !empty($reservationIds);
    }

    /** @param array<int, int> $reservationIds */
    private static function allReservationsConfirmed(array $reservationIds): bool
    {
        $rows = get_reservation_repository()->getReservationsByIds($reservationIds);
        if (\count($rows) !== \count(self::normalizeReservationIds($reservationIds))) {
            return false;
        }
        foreach ($rows as $row) {
            if (!\is_array($row) || !ReservationStatus::isConfirmed((string) ($row['status'] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Persist authoritative payment evidence before making any Clock write.
     * This is intentionally distinct from local booking confirmation and does
     * not emit payment/accounting hooks.
     *
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    private static function recordVerifiedClockPaymentFulfilment(
        string $method,
        array $reservationIds,
        string $transactionId,
        string $providerReference
    ): array {
        $reservationIds = self::normalizeReservationIds($reservationIds);
        $currency = \strtoupper(\sanitize_text_field((string) MustBookingConfig::get_currency()));
        $totalAmount = self::sumReservationTotals($reservationIds);
        if ($currency === '' || $totalAmount === null || $totalAmount <= 0.0) {
            return [
                'success' => false,
                'state' => 'manual_review',
                'retryable' => false,
                'message' => \__('Verified payment evidence could not be recorded without a complete local allocation.', 'must-hotel-booking'),
            ];
        }

        $repository = get_reservation_repository();
        $verifiedAt = \current_time('mysql');
        $environment = self::getActiveSiteEnvironment();
        $accountFingerprint = self::paymentProviderAccountFingerprint($method, $environment);
        if (!$repository->beginTransaction()) {
            return [
                'success' => false,
                'state' => 'manual_review',
                'retryable' => false,
                'message' => \__('Verified payment evidence could not be locked before Clock fulfillment.', 'must-hotel-booking'),
            ];
        }

        $lockedReservations = [];
        foreach ($reservationIds as $reservationId) {
            $reservation = $repository->getReservationForUpdate((int) $reservationId);
            if (!\is_array($reservation)) {
                $repository->rollback();
                return [
                    'success' => false,
                    'state' => 'manual_review',
                    'retryable' => false,
                    'message' => \__('A payment allocation could not be found before Clock fulfillment.', 'must-hotel-booking'),
                ];
            }
            if (\sanitize_key((string) ($reservation['provider'] ?? '')) !== ProviderManager::CLOCK_MODE) {
                $repository->rollback();
                return [
                    'success' => false,
                    'state' => 'manual_review',
                    'retryable' => false,
                    'message' => \__('The verified payment group does not map entirely to Clock reservations.', 'must-hotel-booking'),
                ];
            }
            $syncStatus = \sanitize_key((string) ($reservation['provider_sync_status'] ?? ''));
            if ($syncStatus === 'manual_review') {
                $repository->rollback();
                return [
                    'success' => false,
                    'state' => 'manual_review',
                    'retryable' => false,
                    'message' => \__('This verified payment is already held for Clock recovery review.', 'must-hotel-booking'),
                ];
            }
            if (!\in_array($syncStatus, ['', 'pending_payment', 'pending_fulfilment', 'creating', 'synced'], true)) {
                if (!$repository->updateProviderMetadata((int) $reservationId, [
                    'provider_sync_status' => 'manual_review',
                    'provider_sync_error' => 'verified_payment_incompatible_fulfilment_state',
                ]) || !$repository->commit()) {
                    $repository->rollback();
                }
                return [
                    'success' => false,
                    'state' => 'manual_review',
                    'retryable' => false,
                    'message' => \__('The verified payment conflicts with the stored Clock fulfillment state.', 'must-hotel-booking'),
                ];
            }
            $lockedReservations[$reservationId] = $reservation;
        }

        foreach ($lockedReservations as $reservationId => $reservation) {
            $syncStatus = \sanitize_key((string) ($reservation['provider_sync_status'] ?? ''));

            $metadata = self::decodeProviderMetadata((string) ($reservation['provider_metadata'] ?? ''));
            $expectedVerification = [
                'state' => 'verified_clock_fulfilment_pending',
                'payment_method' => $method,
                'provider_transaction_reference' => \sanitize_text_field($transactionId),
                'provider_payment_reference' => \sanitize_text_field($providerReference),
                'amount' => \round((float) ($reservation['total_price'] ?? 0.0), 2),
                'group_amount' => \round($totalAmount, 2),
                'currency' => $currency,
                'environment' => $environment,
                'account_fingerprint' => $accountFingerprint,
                'reservation_allocation' => (int) $reservationId,
                'reservation_group' => $reservationIds,
            ];
            $existingVerification = isset($metadata['online_payment_verification']) && \is_array($metadata['online_payment_verification'])
                ? $metadata['online_payment_verification']
                : [];
            if (!empty($existingVerification) && !self::clockPaymentVerificationMatches($existingVerification, $expectedVerification)) {
                if (!$repository->updateProviderMetadata((int) $reservationId, [
                    'provider_sync_status' => 'manual_review',
                    'provider_sync_error' => 'verified_payment_evidence_mismatch',
                ]) || !$repository->commit()) {
                    $repository->rollback();
                }
                return [
                    'success' => false,
                    'state' => 'manual_review',
                    'retryable' => false,
                    'message' => \__('Stored verified-payment evidence conflicts with this callback and requires review.', 'must-hotel-booking'),
                ];
            }
            if (empty($existingVerification) && $syncStatus === 'creating') {
                if (!$repository->updateProviderMetadata((int) $reservationId, [
                    'provider_sync_status' => 'manual_review',
                    'provider_sync_error' => 'missing_verified_payment_evidence_during_create',
                ]) || !$repository->commit()) {
                    $repository->rollback();
                }
                return [
                    'success' => false,
                    'state' => 'manual_review',
                    'retryable' => false,
                    'message' => \__('An active Clock fulfillment has no durable verified-payment evidence.', 'must-hotel-booking'),
                ];
            }
            if (!empty($existingVerification)) {
                continue;
            }

            $metadata['website_payment_verified'] = true;
            $metadata['pending_clock_creation'] = \trim((string) ($reservation['provider_booking_id'] ?? '')) === ''
                && \trim((string) ($reservation['provider_reservation_id'] ?? '')) === '';
            $metadata['online_payment_verification'] = $expectedVerification + [
                'verified_at' => $verifiedAt,
                'fulfilment_state' => $syncStatus === 'synced' ? 'fulfilled' : 'pending',
                'last_safe_recovery' => $syncStatus === 'synced' ? 'clock_identifiers_already_stored' : 'clock_create_not_started',
            ];
            $providerUpdate = [
                'provider_sync_error' => '',
                'provider_metadata' => $metadata,
            ];
            if ($syncStatus !== 'synced') {
                $providerUpdate['provider_sync_status'] = 'pending_fulfilment';
            }
            if (!$repository->updateProviderMetadata((int) $reservationId, $providerUpdate)) {
                $repository->rollback();
                return [
                    'success' => false,
                    'state' => 'manual_review',
                    'retryable' => false,
                    'message' => \__('Verified payment evidence could not be saved before Clock fulfillment.', 'must-hotel-booking'),
                ];
            }
        }

        if (!$repository->commit()) {
            $repository->rollback();
            return [
                'success' => false,
                'state' => 'manual_review',
                'retryable' => false,
                'message' => \__('Verified payment evidence could not be committed before Clock fulfillment.', 'must-hotel-booking'),
            ];
        }

        return ['success' => true, 'state' => 'pending_fulfilment'];
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $expected */
    private static function clockPaymentVerificationMatches(array $stored, array $expected): bool
    {
        foreach (['payment_method', 'provider_transaction_reference', 'provider_payment_reference', 'currency', 'environment', 'account_fingerprint'] as $key) {
            if ((string) ($stored[$key] ?? '') !== (string) ($expected[$key] ?? '')) {
                return false;
            }
        }
        if ((int) ($stored['reservation_allocation'] ?? 0) !== (int) ($expected['reservation_allocation'] ?? 0)) {
            return false;
        }
        if (\abs((float) ($stored['amount'] ?? -1) - (float) ($expected['amount'] ?? 0)) > 0.01
            || \abs((float) ($stored['group_amount'] ?? -1) - (float) ($expected['group_amount'] ?? 0)) > 0.01) {
            return false;
        }
        return self::sameReservationIds(
            self::normalizeReservationIds((array) ($stored['reservation_group'] ?? [])),
            self::normalizeReservationIds((array) ($expected['reservation_group'] ?? []))
        );
    }

    /** @return array<string, mixed> */
    private static function decodeProviderMetadata(string $value): array
    {
        $decoded = \json_decode($value, true);
        return \is_array($decoded) ? $decoded : [];
    }

    private static function paymentProviderAccountFingerprint(string $method, string $environment): string
    {
        $identity = (new PaymentEnvironmentCompatibilityPolicy())->currentGatewayIdentity($method, $environment);
        return (string) ($identity['account_fingerprint'] ?? '');
    }

    /**
     * Explicit internal recovery for a paid Clock fulfilment that stopped
     * before its first provider create request. This is intentionally not
     * registered as a public route or automatic retry path.
     *
     * @return array<string, mixed>
     */
    public static function reconcileManualReviewClockFulfilment(int $reservationId): array
    {
        if ($reservationId <= 0) {
            return ['success' => false, 'state' => 'blocked'];
        }

        $reservations = get_reservation_repository();
        $reservation = $reservations->getReservation($reservationId);
        if (
            !\is_array($reservation)
            || \sanitize_key((string) ($reservation['provider'] ?? '')) !== ProviderManager::CLOCK_MODE
            || \sanitize_key((string) ($reservation['status'] ?? '')) !== 'pending_payment'
            || \sanitize_key((string) ($reservation['payment_status'] ?? '')) !== 'pending'
            || \sanitize_key((string) ($reservation['provider_sync_status'] ?? '')) !== 'manual_review'
            || \trim((string) ($reservation['provider_booking_id'] ?? '')) !== ''
            || \trim((string) ($reservation['provider_reservation_id'] ?? '')) !== ''
        ) {
            return ['success' => false, 'state' => 'blocked'];
        }

        $metadata = self::decodeProviderMetadata((string) ($reservation['provider_metadata'] ?? ''));
        $fulfilment = isset($metadata['clock_fulfilment']) && \is_array($metadata['clock_fulfilment'])
            ? $metadata['clock_fulfilment']
            : [];
        if (
            \sanitize_key((string) ($fulfilment['state'] ?? '')) !== 'manual_review'
            || \sanitize_key((string) ($fulfilment['reason'] ?? '')) !== 'clock_create_requires_reread'
        ) {
            return ['success' => false, 'state' => 'blocked'];
        }

        $allocations = (new \MustHotelBooking\Database\PaymentVerificationRepository())->getForReservation($reservationId);
        $allocation = \count($allocations) === 1 && \is_array($allocations[0]) ? $allocations[0] : [];
        $method = \sanitize_key((string) ($allocation['provider'] ?? ''));
        $attemptReference = \sanitize_text_field((string) ($allocation['provider_attempt_reference'] ?? ''));
        $claimKey = $method !== '' && $attemptReference !== ''
            ? 'mhb-clock-payment-' . \substr(\hash('sha256', $reservationId . '|' . $method . '|' . $attemptReference), 0, 48)
            : '';
        $logs = new ProviderRequestLogRepository();
        if (
            !\in_array($method, ['stripe', 'pokpay'], true)
            || $claimKey === ''
            || !\hash_equals((string) ($reservation['provider_fulfilment_key'] ?? ''), $claimKey)
            || !$logs->providerRequestLogsTableExists()
            || $logs->hasLog(ProviderManager::CLOCK_MODE, 'clock.reservation_create', 'outbound', $claimKey)
        ) {
            return ['success' => false, 'state' => 'blocked'];
        }

        if (!self::resumeVerifiedPendingClockFulfilment([$reservationId], true)) {
            return ['success' => false, 'state' => 'blocked'];
        }

        $reloaded = $reservations->getReservation($reservationId);
        $confirmed = \is_array($reloaded)
            && \trim((string) ($reloaded['provider_booking_id'] ?? '')) !== ''
            && ReservationStatus::isConfirmed((string) ($reloaded['status'] ?? ''));

        return [
            'success' => $confirmed,
            'state' => \is_array($reloaded) ? \sanitize_key((string) ($reloaded['provider_sync_status'] ?? '')) : 'blocked',
        ];
    }

    /**
     * Resume a Clock create only when the payment verification transaction has
     * already committed its exact ownership/allocation evidence. This is the
     * recovery path for a request interruption between durable verification and
     * the normal Clock fulfillment call; it does not reread payment providers.
     *
     * @param array<int, int> $reservationIds
     */
    private static function resumeVerifiedPendingClockFulfilment(array $reservationIds, bool $allowManualReviewRecovery = false): bool
    {
        $reservationIds = self::normalizeReservationIds($reservationIds);
        if (empty($reservationIds)) {
            return false;
        }

        $reservations = get_reservation_repository();
        $payments = get_payment_repository();
        $verifications = new \MustHotelBooking\Database\PaymentVerificationRepository();
        $refunds = new \MustHotelBooking\Database\RefundRepository();
        $verificationGroupId = 0;
        $method = '';
        $attemptReference = '';
        $transactionReference = '';
        $providerMode = '';
        $accountFingerprint = '';
        $totalAmountMinor = -1;
        $currency = '';

        foreach ($reservationIds as $reservationId) {
            $reservation = $reservations->getReservation($reservationId);
            if (!\is_array($reservation)) {
                return false;
            }
            $syncStatus = \sanitize_key((string) ($reservation['provider_sync_status'] ?? ''));
            if (
                \sanitize_key((string) ($reservation['provider'] ?? '')) !== ProviderManager::CLOCK_MODE
                || \sanitize_key((string) ($reservation['status'] ?? '')) !== 'pending_payment'
                || \sanitize_key((string) ($reservation['payment_status'] ?? '')) !== 'pending'
                || ($allowManualReviewRecovery ? $syncStatus !== 'manual_review' : $syncStatus !== 'pending_fulfilment')
                || \trim((string) ($reservation['provider_booking_id'] ?? '')) !== ''
                || \trim((string) ($reservation['provider_reservation_id'] ?? '')) !== ''
            ) {
                return false;
            }
            if (!empty($refunds->getRefundsForReservation($reservationId))) {
                return false;
            }

            $allocations = $verifications->getForReservation($reservationId);
            if (\count($allocations) !== 1 || !\is_array($allocations[0])) {
                return false;
            }
            $allocation = $allocations[0];
            $allocationGroupId = (int) ($allocation['verification_group_id'] ?? 0);
            $allocationMethod = \sanitize_key((string) ($allocation['provider'] ?? ''));
            $allocationAttemptReference = \sanitize_text_field((string) ($allocation['provider_attempt_reference'] ?? ''));
            $allocationTransactionReference = \sanitize_text_field((string) ($allocation['provider_transaction_reference'] ?? ''));
            $allocationMode = \sanitize_key((string) ($allocation['provider_mode'] ?? ''));
            $allocationFingerprint = (string) ($allocation['provider_account_fingerprint'] ?? '');
            $allocationTotalMinor = (int) ($allocation['total_amount_minor'] ?? -1);
            $allocationCurrency = \strtoupper(\sanitize_text_field((string) ($allocation['group_currency'] ?? '')));
            $paymentId = (int) ($allocation['payment_id'] ?? 0);
            $payment = $paymentId > 0 ? $payments->getPayment($paymentId) : null;

            if (
                $allocationGroupId <= 0
                || !\in_array($allocationMethod, ['stripe', 'pokpay'], true)
                || $allocationAttemptReference === ''
                || $allocationTransactionReference === ''
                || $allocationMode === ''
                || $allocationFingerprint === ''
                || $allocationTotalMinor <= 0
                || $allocationCurrency === ''
                || !\is_array($payment)
                || (int) ($payment['reservation_id'] ?? 0) !== $reservationId
                || \sanitize_key((string) ($payment['method'] ?? '')) !== $allocationMethod
                || \sanitize_key((string) ($payment['status'] ?? '')) !== 'paid'
                || \sanitize_key((string) ($payment['attempt_status'] ?? '')) !== 'verified'
                || \sanitize_text_field((string) ($payment['transaction_id'] ?? '')) !== $allocationTransactionReference
            ) {
                return false;
            }

            if ($verificationGroupId === 0) {
                $verificationGroupId = $allocationGroupId;
                $method = $allocationMethod;
                $attemptReference = $allocationAttemptReference;
                $transactionReference = $allocationTransactionReference;
                $providerMode = $allocationMode;
                $accountFingerprint = $allocationFingerprint;
                $totalAmountMinor = $allocationTotalMinor;
                $currency = $allocationCurrency;
                continue;
            }
            if (
                $verificationGroupId !== $allocationGroupId
                || $method !== $allocationMethod
                || $attemptReference !== $allocationAttemptReference
                || $transactionReference !== $allocationTransactionReference
                || $providerMode !== $allocationMode
                || $accountFingerprint !== $allocationFingerprint
                || $totalAmountMinor !== $allocationTotalMinor
                || $currency !== $allocationCurrency
            ) {
                return false;
            }
        }

        $groupAllocations = $verificationGroupId > 0 ? $verifications->getAllocations($verificationGroupId) : [];
        $allocatedReservationIds = self::normalizeReservationIds(\array_column($groupAllocations, 'reservation_id'));
        if (\count($groupAllocations) !== \count($reservationIds) || $allocatedReservationIds !== $reservationIds) {
            return false;
        }

        $verifiedFacts = [
            'server_verified' => true,
            'provider_transaction_reference' => $transactionReference,
            'provider_attempt_reference' => $attemptReference,
            'provider_mode' => $providerMode,
            'provider_account_fingerprint' => $accountFingerprint,
            'total_amount_minor' => $totalAmountMinor,
            'currency' => $currency,
            'verification_source' => 'durable_pending_clock_recovery',
        ];
        $attemptValidation = (new PaymentAttemptIntegrity())->validateFinalization($method, $reservationIds, $verifiedFacts);
        if (empty($attemptValidation['allowed'])) {
            return false;
        }

        $clockResult = (new \MustHotelBooking\Provider\Clock\ClockReservationProvider())->fulfillPendingOnlinePayment(
            $reservationIds,
            $method,
            $attemptReference,
            $allowManualReviewRecovery
        );
        if (empty($clockResult['success'])) {
            return true;
        }

        $completion = (new OnlinePaymentVerificationService())->recordAndConfirm($method, $reservationIds, $verifiedFacts);
        if (empty($completion['success']) || !self::allReservationsConfirmed($reservationIds)) {
            self::persistPaidProviderFailure(
                $method,
                $reservationIds,
                $verifiedFacts,
                (string) ($completion['reason_code'] ?? 'confirmation_incomplete')
            );
            return true;
        }

        (new \MustHotelBooking\Database\PaidProviderObservationRepository())->markResolved(
            $method,
            $transactionReference,
            $providerMode,
            $accountFingerprint
        );
        return true;
    }

    /** @param array<int, int> $reservationIds */
    private static function hasPendingClockFulfilment(array $reservationIds): bool
    {
        foreach (self::normalizeReservationIds($reservationIds) as $reservationId) {
            $reservation = get_reservation_repository()->getReservation($reservationId);
            $syncStatus = \is_array($reservation)
                ? \sanitize_key((string) ($reservation['provider_sync_status'] ?? ''))
                : '';
            if (
                \is_array($reservation)
                && \sanitize_key((string) ($reservation['provider'] ?? '')) === ProviderManager::CLOCK_MODE
                && \in_array($syncStatus, ['pending_fulfilment', 'creating', 'manual_review', 'synced'], true)
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, int> $reservationIds */
    private static function sumReservationTotals(array $reservationIds): ?float
    {
        $ids = self::normalizeReservationIds($reservationIds);
        $rows = get_reservation_repository()->getReservationsByIds($ids);
        if (empty($ids) || \count($rows) !== \count($ids)) {
            return null;
        }
        $total = 0.0;
        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['total_price']) || !\is_numeric($row['total_price'])) {
                return null;
            }
            $total += (float) $row['total_price'];
        }
        return \round($total, 2);
    }

    /** @param array<int, int> $left @param array<int, int> $right */
    private static function sameReservationIds(array $left, array $right): bool
    {
        $left = \array_values(\array_unique(self::normalizeReservationIds($left)));
        $right = \array_values(\array_unique(self::normalizeReservationIds($right)));
        \sort($left);
        \sort($right);
        return $left === $right && !empty($left);
    }

    /** @param array<string, mixed> $data @param array<int, string> $keys */
    private static function firstTextValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && \is_scalar($data[$key])) {
                return \sanitize_text_field((string) $data[$key]);
            }
        }
        foreach (['sdkOrder', 'order', 'payment', 'data'] as $nestedKey) {
            if (isset($data[$nestedKey]) && \is_array($data[$nestedKey])) {
                $value = self::firstTextValue($data[$nestedKey], $keys);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, int>
     */
    private static function normalizeReservationIds(array $reservationIds): array
    {
        $reservationIds = \array_values(\array_unique(
            \array_filter(
                \array_map('intval', $reservationIds),
                static function (int $reservationId): bool {
                    return $reservationId > 0;
                }
            )
        ));
        \sort($reservationIds, SORT_NUMERIC);
        return $reservationIds;
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

    /** @param array<int, int> $reservationIds */
    private static function reservationPaymentIdsMatchTransaction(array $reservationIds, string $method, string $transactionId): bool
    {
        $method = \sanitize_key($method);
        $transactionId = \sanitize_text_field($transactionId);
        if ($method === '' || $transactionId === '') {
            return false;
        }
        $normalizedReservationIds = self::normalizeReservationIds($reservationIds);
        $paymentIdsMatch = self::sameReservationIds(
            get_payment_repository()->findReservationIdsByTransactionId($transactionId),
            $normalizedReservationIds
        );
        $paymentsByReservation = get_payment_repository()->getPaymentsForReservationIds($reservationIds);
        foreach ($normalizedReservationIds as $reservationId) {
            $matched = false;
            foreach ((array) ($paymentsByReservation[$reservationId] ?? []) as $payment) {
                if (
                    \is_array($payment)
                    && \sanitize_key((string) ($payment['method'] ?? '')) === $method
                    && \sanitize_text_field((string) ($payment['transaction_id'] ?? '')) === $transactionId
                ) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $paymentIdsMatch = false;
                break;
            }
        }
        if ($paymentIdsMatch) {
            return true;
        }

        $verifications = new \MustHotelBooking\Database\PaymentVerificationRepository();
        $groupId = 0;
        foreach ($normalizedReservationIds as $reservationId) {
            $matchedEvidence = null;
            foreach ($verifications->getForReservation($reservationId) as $evidence) {
                if (
                    \sanitize_key((string) ($evidence['provider'] ?? '')) === $method
                    && \sanitize_text_field((string) ($evidence['provider_attempt_reference'] ?? '')) === $transactionId
                ) {
                    $matchedEvidence = $evidence;
                    break;
                }
            }
            if (!\is_array($matchedEvidence)) {
                return false;
            }
            $candidateGroupId = (int) ($matchedEvidence['verification_group_id'] ?? 0);
            if ($candidateGroupId <= 0 || ($groupId > 0 && $groupId !== $candidateGroupId)) {
                return false;
            }
            $groupId = $candidateGroupId;
        }
        $ownedIds = \array_map(static function (array $row): int {
            return (int) ($row['reservation_id'] ?? 0);
        }, $verifications->getAllocations($groupId));
        \sort($ownedIds);
        return $ownedIds === $normalizedReservationIds;
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
        if (!self::reservationPaymentIdsMatchTransaction($reservationIds, 'pokpay', $orderId)) {
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

    /**
     * @param array<int, int> $reservationIds
     */
    private static function stripeCompletedSessionAlreadyRecorded(array $reservationIds, string $transactionId): bool
    {
        $transactionId = \trim($transactionId);
        if ($transactionId === '' || empty($reservationIds)) {
            return false;
        }

        $payments = get_payment_repository();
        foreach ($reservationIds as $reservationId) {
            $paymentId = $payments->getLatestPaymentIdForReservationMethodTransaction((int) $reservationId, 'stripe', $transactionId);
            if ($paymentId <= 0) {
                return false;
            }
            $payment = $payments->getPayment($paymentId);
            $reservation = get_reservation_repository()->getReservation((int) $reservationId);
            if (
                !\is_array($payment)
                || (string) ($payment['status'] ?? '') !== 'paid'
                || !\is_array($reservation)
                || !ReservationStatus::isConfirmed((string) ($reservation['status'] ?? ''))
            ) {
                return false;
            }
        }

        return true;
    }
}
