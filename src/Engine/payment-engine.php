<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;

/**
 * Get empty pending payment flow data.
 *
 * @return array<string, mixed>
 */
function get_empty_pending_payment_flow_data(): array
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
 * Normalize pending payment flow data.
 *
 * @param mixed $value
 * @return array<string, mixed>
 */
function normalize_pending_payment_flow_data($value): array
{
    if (!\is_array($value)) {
        return get_empty_pending_payment_flow_data();
    }

    $reservation_ids = isset($value['reservation_ids']) && \is_array($value['reservation_ids'])
        ? \array_values(
            \array_filter(
                \array_map('intval', $value['reservation_ids']),
                static function (int $reservation_id): bool {
                    return $reservation_id > 0;
                }
            )
        )
        : [];

    return [
        'method' => isset($value['method']) ? \sanitize_key((string) $value['method']) : '',
        'reservation_ids' => $reservation_ids,
        'session_id' => isset($value['session_id']) ? \sanitize_text_field((string) $value['session_id']) : '',
        'checkout_url' => isset($value['checkout_url']) ? \esc_url_raw((string) $value['checkout_url']) : '',
        'expires_at' => isset($value['expires_at']) ? \sanitize_text_field((string) $value['expires_at']) : '',
        'created_at' => isset($value['created_at']) ? \sanitize_text_field((string) $value['created_at']) : '',
    ];
}

/**
 * Get enabled payment methods available at checkout.
 *
 * @return array<string, array<string, string>>
 */
function get_checkout_payment_methods(): array
{
    $catalog = \function_exists(__NAMESPACE__ . '\get_payment_methods_catalog')
        ? get_payment_methods_catalog()
        : [];
    $enabled = \function_exists(__NAMESPACE__ . '\get_enabled_payment_methods')
        ? get_enabled_payment_methods()
        : ['pay_at_hotel'];
    $options = [];

    $preferred_order = ['stripe', 'pay_at_hotel', 'bank_transfer'];

    foreach ($preferred_order as $method) {
        if (!\in_array($method, $enabled, true)) {
            continue;
        }

        $method_key = \sanitize_key((string) $method);

        if (!PaymentEngine::isGatewayAvailable($method_key)) {
            continue;
        }

        if (!isset($catalog[$method_key]) || !\is_array($catalog[$method_key])) {
            continue;
        }

        $options[$method_key] = [
            'label' => isset($catalog[$method_key]['label']) ? (string) $catalog[$method_key]['label'] : $method_key,
            'description' => isset($catalog[$method_key]['description']) ? (string) $catalog[$method_key]['description'] : '',
        ];
    }

    foreach ($enabled as $method) {
        $method_key = \sanitize_key((string) $method);

        if (isset($options[$method_key])) {
            continue;
        }

        if (!PaymentEngine::isGatewayAvailable($method_key)) {
            continue;
        }

        if (!isset($catalog[$method_key]) || !\is_array($catalog[$method_key])) {
            continue;
        }

        $options[$method_key] = [
            'label' => isset($catalog[$method_key]['label']) ? (string) $catalog[$method_key]['label'] : $method_key,
            'description' => isset($catalog[$method_key]['description']) ? (string) $catalog[$method_key]['description'] : '',
        ];
    }

    if (empty($options)) {
        $options['pay_at_hotel'] = [
            'label' => \__('Pay at hotel', 'must-hotel-booking'),
            'description' => \__('Guest pays during check-in or check-out at the property.', 'must-hotel-booking'),
        ];
    }

    return $options;
}

/**
 * Normalize requested payment method to an enabled option.
 */
function normalize_checkout_payment_method(string $payment_method): string
{
    $payment_method = \sanitize_key($payment_method);
    $available_methods = \array_keys(get_checkout_payment_methods());

    if (\in_array($payment_method, $available_methods, true)) {
        return $payment_method;
    }

    return (string) (\reset($available_methods) ?: 'pay_at_hotel');
}

/**
 * Resolve checkout payment method from request/flow state.
 *
 * @param array<string, mixed> $source
 * @param array<string, mixed> $flow_data
 */
function get_selected_checkout_payment_method(array $source, array $flow_data = []): string
{
    $raw_method = isset($source['payment_method']) ? (string) \wp_unslash($source['payment_method']) : '';

    if ($raw_method === '' && isset($flow_data['payment_method'])) {
        $raw_method = (string) $flow_data['payment_method'];
    }

    return normalize_checkout_payment_method($raw_method);
}

/**
 * Get CTA label for confirmation submit button.
 */
function get_checkout_payment_cta_label(string $payment_method): string
{
    $payment_method = normalize_checkout_payment_method($payment_method);
    $gateway = PaymentEngine::normalizeMethod($payment_method);

    if ($gateway === 'stripe') {
        return \__('Pay securely with Stripe', 'must-hotel-booking');
    }

    if ($payment_method === 'bank_transfer') {
        return \__('Confirm and view bank transfer details', 'must-hotel-booking');
    }

    if ($payment_method === 'paypal') {
        return \__('Continue to PayPal', 'must-hotel-booking');
    }

    return \__('Confirm reservation', 'must-hotel-booking');
}

/**
 * Get supported site environments for Stripe configuration.
 *
 * @return array<string, array<string, string>>
 */
function get_stripe_environment_catalog(): array
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

/**
 * Detect the most likely site environment from a URL.
 */
function detect_stripe_environment_from_url(string $url): string
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

/**
 * Detect the most likely current site environment.
 */
function detect_stripe_environment_from_site_url(): string
{
    return detect_stripe_environment_from_url((string) \home_url('/'));
}

/**
 * Normalize a site environment key.
 */
function normalize_stripe_environment(string $environment): string
{
    $environment = \sanitize_key($environment);
    $catalog = get_stripe_environment_catalog();

    if (isset($catalog[$environment])) {
        return $environment;
    }

    return detect_stripe_environment_from_site_url();
}

/**
 * Get the active site environment selected in General settings.
 */
function get_active_site_environment(): string
{
    $saved_environment = \class_exists(MustBookingConfig::class)
        ? (string) MustBookingConfig::get_setting('site_environment', '')
        : '';

    if (\trim($saved_environment) === '') {
        return detect_stripe_environment_from_site_url();
    }

    return normalize_stripe_environment($saved_environment);
}

/**
 * Get a display label for a site environment key.
 */
function get_site_environment_label(string $environment = ''): string
{
    $environment = $environment !== '' ? normalize_stripe_environment($environment) : get_active_site_environment();
    $catalog = get_stripe_environment_catalog();

    return isset($catalog[$environment]['label']) ? (string) $catalog[$environment]['label'] : $environment;
}

/**
 * Get setting keys used by a Stripe environment profile.
 *
 * @return array<string, string>
 */
function get_stripe_environment_setting_keys(string $environment): array
{
    $environment = normalize_stripe_environment($environment);

    return [
        'publishable_key' => 'stripe_' . $environment . '_publishable_key',
        'secret_key' => 'stripe_' . $environment . '_secret_key',
        'webhook_secret' => 'stripe_' . $environment . '_webhook_secret',
    ];
}

/**
 * Get Stripe credentials for an environment profile.
 *
 * @return array<string, string>
 */
function get_stripe_environment_credentials(string $environment = ''): array
{
    $environment = $environment !== '' ? normalize_stripe_environment($environment) : get_active_site_environment();
    $keys = get_stripe_environment_setting_keys($environment);
    $credentials = [
        'publishable_key' => '',
        'secret_key' => '',
        'webhook_secret' => '',
    ];

    if (\class_exists(MustBookingConfig::class)) {
        foreach ($keys as $field => $setting_key) {
            $credentials[$field] = \trim((string) MustBookingConfig::get_setting($setting_key, ''));
        }

        if ($environment === 'production') {
            if ($credentials['publishable_key'] === '') {
                $credentials['publishable_key'] = \trim((string) MustBookingConfig::get_setting('stripe_publishable_key', ''));
            }

            if ($credentials['secret_key'] === '') {
                $credentials['secret_key'] = \trim((string) MustBookingConfig::get_setting('stripe_secret_key', ''));
            }

            if ($credentials['webhook_secret'] === '') {
                $credentials['webhook_secret'] = \trim((string) MustBookingConfig::get_setting('stripe_webhook_secret', ''));
            }
        }
    }

    return $credentials;
}

/**
 * Get Stripe publishable key.
 */
function get_stripe_publishable_key(): string
{
    $credentials = get_stripe_environment_credentials();

    return (string) ($credentials['publishable_key'] ?? '');
}

/**
 * Get Stripe secret key.
 */
function get_stripe_secret_key(): string
{
    $credentials = get_stripe_environment_credentials();

    return (string) ($credentials['secret_key'] ?? '');
}

/**
 * Get Stripe webhook signing secret.
 */
function get_stripe_webhook_secret(): string
{
    $credentials = get_stripe_environment_credentials();

    return (string) ($credentials['webhook_secret'] ?? '');
}

/**
 * Check whether Stripe Checkout is configured.
 */
function is_stripe_checkout_configured(): bool
{
    return get_stripe_publishable_key() !== '' && get_stripe_secret_key() !== '';
}

/**
 * Get Stripe Checkout session expiry in minutes.
 */
function get_stripe_checkout_expiry_minutes(): int
{
    return 30;
}

/**
 * Get Stripe pending reservation cleanup grace period in minutes.
 */
function get_pending_payment_cleanup_minutes(): int
{
    return get_stripe_checkout_expiry_minutes() + 10;
}

/**
 * Get Stripe webhook URL.
 */
function get_stripe_webhook_url(): string
{
    return \rest_url('must-hotel-booking/v1/stripe/webhook');
}

/**
 * Check whether the provided URL is a Stripe-hosted Checkout URL.
 */
function is_stripe_checkout_url(string $url): bool
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

/**
 * Convert a decimal money amount into Stripe minor units.
 */
function convert_amount_to_stripe_minor_units(float $amount, string $currency): int
{
    $currency = \strtolower(\trim($currency));
    $amount = \max(0.0, $amount);
    $zero_decimal_currencies = [
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

    if (\in_array($currency, $zero_decimal_currencies, true)) {
        return (int) \round($amount);
    }

    return (int) \round($amount * 100);
}

/**
 * Perform a Stripe API request.
 *
 * @param array<string, scalar> $body
 * @return array<string, mixed>
 */
function perform_stripe_api_request(string $method, string $path, array $body = []): array
{
    $secret_key = get_stripe_secret_key();

    if ($secret_key === '') {
        return [
            'success' => false,
            'status_code' => 0,
            'body' => [],
            'message' => \__('Stripe secret key is not configured.', 'must-hotel-booking'),
        ];
    }

    $request_args = [
        'method' => \strtoupper($method),
        'timeout' => 45,
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
    ];

    if (!empty($body)) {
        $request_args['body'] = \http_build_query($body, '', '&');
    }

    $response = \wp_remote_request('https://api.stripe.com/v1/' . \ltrim($path, '/'), $request_args);

    if (\is_wp_error($response)) {
        return [
            'success' => false,
            'status_code' => 0,
            'body' => [],
            'message' => (string) $response->get_error_message(),
        ];
    }

    $status_code = (int) \wp_remote_retrieve_response_code($response);
    $decoded_body = \json_decode((string) \wp_remote_retrieve_body($response), true);

    return [
        'success' => $status_code >= 200 && $status_code < 300 && \is_array($decoded_body),
        'status_code' => $status_code,
        'body' => \is_array($decoded_body) ? $decoded_body : [],
        'message' => \is_array($decoded_body) && isset($decoded_body['error']['message'])
            ? (string) $decoded_body['error']['message']
            : '',
    ];
}

/**
 * Create a Stripe Checkout session for the selected booking.
 *
 * @param array<int, int> $reservation_ids
 * @param array<string, string> $guest_form
 * @param array<int, int> $coupon_ids
 * @return array<string, mixed>
 */
function create_stripe_checkout_session(array $reservation_ids, array $guest_form, float $total_amount, string $currency, array $coupon_ids = []): array
{
    $reservation_ids = \array_values(
        \array_filter(
            \array_map('intval', $reservation_ids),
            static function (int $reservation_id): bool {
                return $reservation_id > 0;
            }
        )
    );

    if (empty($reservation_ids)) {
        return [
            'success' => false,
            'message' => \__('No reservations are available for Stripe checkout.', 'must-hotel-booking'),
        ];
    }

    $hotel_name = \class_exists(MustBookingConfig::class)
        ? MustBookingConfig::get_hotel_name()
        : \get_bloginfo('name');
    $amount_minor = convert_amount_to_stripe_minor_units($total_amount, $currency);

    if ($amount_minor <= 0) {
        return [
            'success' => false,
            'message' => \__('Stripe requires a positive payment amount.', 'must-hotel-booking'),
        ];
    }

    $success_url = \add_query_arg(
        [
            'reservation_ids' => \implode(',', $reservation_ids),
            'payment_method' => 'stripe',
            'stripe_return' => 'success',
            'session_id' => '{CHECKOUT_SESSION_ID}',
        ],
        get_booking_confirmation_page_url()
    );
    $cancel_url = \add_query_arg(
        [
            'payment_method' => 'stripe',
            'stripe_return' => 'cancel',
        ],
        get_booking_confirmation_page_url()
    );
    $expires_at = \time() + (get_stripe_checkout_expiry_minutes() * 60);
    $payload = [
        'mode' => 'payment',
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'payment_method_types[0]' => 'card',
        'line_items[0][price_data][currency]' => \strtolower($currency),
        'line_items[0][price_data][product_data][name]' => $hotel_name !== '' ? $hotel_name . ' Stay' : 'Hotel Stay',
        'line_items[0][price_data][product_data][description]' => \sprintf(
            /* translators: %d is the number of reserved rooms. */
            \_n('%d room reservation', '%d room reservations', \count($reservation_ids), 'must-hotel-booking'),
            \count($reservation_ids)
        ),
        'line_items[0][price_data][unit_amount]' => $amount_minor,
        'line_items[0][quantity]' => 1,
        'customer_email' => isset($guest_form['email']) ? (string) $guest_form['email'] : '',
        'metadata[reservation_ids]' => \implode(',', $reservation_ids),
        'metadata[coupon_ids]' => \implode(',', \array_values(\array_unique(\array_map('intval', $coupon_ids)))),
        'metadata[source]' => 'must-hotel-booking',
        'expires_at' => (string) $expires_at,
    ];

    $response = perform_stripe_api_request('POST', 'checkout/sessions', $payload);

    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => isset($response['message']) && (string) $response['message'] !== ''
                ? (string) $response['message']
                : \__('Unable to create the Stripe checkout session.', 'must-hotel-booking'),
        ];
    }

    $session = isset($response['body']) && \is_array($response['body']) ? $response['body'] : [];
    $session_id = isset($session['id']) ? (string) $session['id'] : '';
    $checkout_url = isset($session['url']) ? (string) $session['url'] : '';

    if ($session_id === '' || $checkout_url === '') {
        return [
            'success' => false,
            'message' => \__('Stripe returned an incomplete checkout session.', 'must-hotel-booking'),
        ];
    }

    return [
        'success' => true,
        'session_id' => $session_id,
        'checkout_url' => $checkout_url,
        'expires_at' => isset($session['expires_at']) ? \gmdate('Y-m-d H:i:s', (int) $session['expires_at']) : \gmdate('Y-m-d H:i:s', $expires_at),
        'payment_status' => isset($session['payment_status']) ? (string) $session['payment_status'] : '',
        'status' => isset($session['status']) ? (string) $session['status'] : '',
        'payment_intent' => isset($session['payment_intent']) ? (string) $session['payment_intent'] : '',
    ];
}

/**
 * Extract coupon ids from a Stripe session payload.
 *
 * @param array<string, mixed> $session
 * @return array<int, int>
 */
function get_coupon_ids_from_stripe_session(array $session): array
{
    $metadata = isset($session['metadata']) && \is_array($session['metadata']) ? $session['metadata'] : [];
    $raw_ids = isset($metadata['coupon_ids']) ? (string) $metadata['coupon_ids'] : '';

    if ($raw_ids === '') {
        return [];
    }

    return \array_values(
        \array_filter(
            \array_map(
                'intval',
                \array_map('trim', \explode(',', $raw_ids))
            ),
            static function (int $coupon_id): bool {
                return $coupon_id > 0;
            }
        )
    );
}

/**
 * Retrieve a Stripe Checkout session by ID.
 *
 * @return array<string, mixed>
 */
function get_stripe_checkout_session(string $session_id): array
{
    $session_id = \trim($session_id);

    if ($session_id === '') {
        return [
            'success' => false,
            'message' => \__('Stripe session id is missing.', 'must-hotel-booking'),
        ];
    }

    $response = perform_stripe_api_request('GET', 'checkout/sessions/' . \rawurlencode($session_id));

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

/**
 * Get payments table name.
 */
function get_payments_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_payments';
}

/**
 * Load reservation rows needed for payment operations.
 *
 * @param array<int, int> $reservation_ids
 * @return array<int, array<string, mixed>>
 */
function get_payment_reservation_rows(array $reservation_ids): array
{
    return get_reservation_repository()->getReservationsByIds($reservation_ids);
}

/**
 * Check whether reservation ids still reference reusable pending Stripe rows.
 *
 * @param array<int, int> $reservation_ids
 */
function are_reusable_pending_payment_reservations(array $reservation_ids): bool
{
    $rows = get_payment_reservation_rows($reservation_ids);

    if (\count($rows) !== \count($reservation_ids)) {
        return false;
    }

    foreach ($rows as $row) {
        $status = isset($row['status']) ? (string) $row['status'] : '';
        $payment_status = isset($row['payment_status']) ? (string) $row['payment_status'] : '';

        if ($status !== 'pending_payment' || $payment_status !== 'pending') {
            return false;
        }
    }

    return true;
}

/**
 * Insert or refresh payment rows for reservations.
 *
 * @param array<int, int> $reservation_ids
 */
function create_or_update_payment_rows(array $reservation_ids, string $method, string $status, string $transaction_id = ''): void
{
    $reservation_rows = get_payment_reservation_rows($reservation_ids);
    $currency = \class_exists(MustBookingConfig::class) ? MustBookingConfig::get_currency() : 'USD';
    $payment_repository = get_payment_repository();

    foreach ($reservation_rows as $reservation_row) {
        $reservation_id = isset($reservation_row['id']) ? (int) $reservation_row['id'] : 0;

        if ($reservation_id <= 0) {
            continue;
        }

        $existing_payment_id = $payment_repository->getLatestPaymentIdForReservationMethod($reservation_id, $method);

        $payment_data = [
            'amount' => isset($reservation_row['total_price']) ? (float) $reservation_row['total_price'] : 0.0,
            'currency' => $currency,
            'method' => $method,
            'status' => $status,
            'transaction_id' => $transaction_id,
        ];

        if ($status === 'paid') {
            $payment_data['paid_at'] = \current_time('mysql');
        }

        if ($existing_payment_id > 0) {
            if ($transaction_id === '') {
                unset($payment_data['transaction_id']);
            }

            $payment_repository->updatePayment($existing_payment_id, $payment_data);
            continue;
        }

        $payment_data['reservation_id'] = $reservation_id;
        $payment_data['created_at'] = \current_time('mysql');
        $payment_repository->createPayment($payment_data);
    }
}

/**
 * Update reservation payment state and trigger confirmation hooks when needed.
 *
 * @param array<int, int> $reservation_ids
 */
function update_reservations_payment_state(array $reservation_ids, string $status, string $payment_status): void
{
    $reservation_rows = get_payment_reservation_rows($reservation_ids);
    $reservation_repository = get_reservation_repository();

    foreach ($reservation_rows as $reservation_row) {
        $reservation_id = isset($reservation_row['id']) ? (int) $reservation_row['id'] : 0;
        $previous_status = isset($reservation_row['status']) ? (string) $reservation_row['status'] : '';

        if ($reservation_id <= 0) {
            continue;
        }

        $reservation_repository->updateReservationStatus($reservation_id, $status, $payment_status);

        if ($previous_status !== 'cancelled' && $status === 'cancelled') {
            \do_action('must_hotel_booking/reservation_cancelled', $reservation_id);
        }

        if (
            \function_exists(__NAMESPACE__ . '\is_reservation_confirmed_status') &&
            !is_reservation_confirmed_status($previous_status) &&
            is_reservation_confirmed_status($status)
        ) {
            \do_action('must_hotel_booking/reservation_confirmed', $reservation_id);
        }
    }
}

/**
 * Finalize Stripe-paid reservations.
 *
 * @param array<int, int> $reservation_ids
 * @param array<int, int> $coupon_ids
 */
function finalize_stripe_paid_reservations(array $reservation_ids, string $session_id, string $payment_intent = '', array $coupon_ids = []): void
{
    $reservation_rows = get_payment_reservation_rows($reservation_ids);
    $should_increment_coupon_usage = false;

    foreach ($reservation_rows as $reservation_row) {
        $status = isset($reservation_row['status']) ? (string) $reservation_row['status'] : '';

        if (\function_exists(__NAMESPACE__ . '\is_reservation_confirmed_status') && !is_reservation_confirmed_status($status)) {
            $should_increment_coupon_usage = true;
            break;
        }
    }

    update_reservations_payment_state($reservation_ids, 'confirmed', 'paid');
    create_or_update_payment_rows($reservation_ids, 'stripe', 'paid', $payment_intent !== '' ? $payment_intent : $session_id);

    if ($should_increment_coupon_usage && \function_exists(__NAMESPACE__ . '\increment_coupon_usage_count')) {
        foreach (\array_values(\array_unique(\array_map('intval', $coupon_ids))) as $coupon_id) {
            if ($coupon_id > 0) {
                increment_coupon_usage_count($coupon_id);
            }
        }
    }
}

/**
 * Mark pending Stripe reservations as failed or expired.
 *
 * @param array<int, int> $reservation_ids
 */
function fail_pending_stripe_reservations(array $reservation_ids, string $reservation_status = 'payment_failed'): void
{
    if (!\in_array($reservation_status, ['payment_failed', 'expired', 'cancelled'], true)) {
        $reservation_status = 'payment_failed';
    }

    update_reservations_payment_state($reservation_ids, $reservation_status, 'cancelled');
    create_or_update_payment_rows($reservation_ids, 'stripe', 'failed');
}

/**
 * Sync a returned Stripe session to local reservation state.
 *
 * @param array<int, int> $reservation_ids
 * @return array<string, mixed>
 */
function maybe_sync_stripe_return_session(string $session_id, array $reservation_ids): array
{
    $session_response = get_stripe_checkout_session($session_id);

    if (empty($session_response['success'])) {
        return [
            'success' => false,
            'message' => isset($session_response['message']) ? (string) $session_response['message'] : '',
        ];
    }

    $session = isset($session_response['session']) && \is_array($session_response['session']) ? $session_response['session'] : [];
    $payment_status = isset($session['payment_status']) ? (string) $session['payment_status'] : '';
    $session_status = isset($session['status']) ? (string) $session['status'] : '';

    if ($payment_status === 'paid' && $session_status === 'complete') {
        finalize_stripe_paid_reservations(
            $reservation_ids,
            $session_id,
            isset($session['payment_intent']) ? (string) $session['payment_intent'] : '',
            get_coupon_ids_from_stripe_session($session)
        );

        return [
            'success' => true,
            'state' => 'paid',
        ];
    }

    if ($session_status === 'expired') {
        fail_pending_stripe_reservations($reservation_ids, 'expired');

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

/**
 * Verify Stripe webhook signature header.
 */
function verify_stripe_webhook_signature(string $payload, string $signature_header, string $webhook_secret, int $tolerance = 300): bool
{
    if ($payload === '' || $signature_header === '' || $webhook_secret === '') {
        return false;
    }

    $timestamp = 0;
    $signatures = [];

    foreach (\explode(',', $signature_header) as $part) {
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

    $signed_payload = $timestamp . '.' . $payload;
    $expected_signature = \hash_hmac('sha256', $signed_payload, $webhook_secret);

    foreach ($signatures as $signature) {
        if (\hash_equals($expected_signature, $signature)) {
            return true;
        }
    }

    return false;
}

/**
 * Extract reservation ids from a Stripe session payload.
 *
 * @param array<string, mixed> $session
 * @return array<int, int>
 */
function get_reservation_ids_from_stripe_session(array $session): array
{
    $metadata = isset($session['metadata']) && \is_array($session['metadata']) ? $session['metadata'] : [];
    $raw_ids = isset($metadata['reservation_ids']) ? (string) $metadata['reservation_ids'] : '';

    if ($raw_ids === '') {
        return [];
    }

    return \array_values(
        \array_filter(
            \array_map(
                'intval',
                \array_map('trim', \explode(',', $raw_ids))
            ),
            static function (int $reservation_id): bool {
                return $reservation_id > 0;
            }
        )
    );
}

/**
 * Handle Stripe webhook callbacks.
 */
function handle_stripe_webhook_request(\WP_REST_Request $request): \WP_REST_Response
{
    $payload = (string) $request->get_body();
    $signature_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string) $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
    $webhook_secret = get_stripe_webhook_secret();

    if ($webhook_secret === '') {
        return new \WP_REST_Response(['received' => false], 400);
    }

    if (!verify_stripe_webhook_signature($payload, $signature_header, $webhook_secret)) {
        return new \WP_REST_Response(['received' => false], 400);
    }

    $event = \json_decode($payload, true);

    if (!\is_array($event)) {
        return new \WP_REST_Response(['received' => false], 400);
    }

    $event_type = isset($event['type']) ? (string) $event['type'] : '';
    $session = isset($event['data']['object']) && \is_array($event['data']['object']) ? $event['data']['object'] : [];
    $reservation_ids = get_reservation_ids_from_stripe_session($session);
    $session_id = isset($session['id']) ? (string) $session['id'] : '';

    if (empty($reservation_ids) && $session_id !== '') {
        $reservation_ids = get_payment_repository()->findReservationIdsByTransactionId($session_id);
    }

    if (!empty($reservation_ids)) {
        if (\in_array($event_type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            finalize_stripe_paid_reservations(
                $reservation_ids,
                $session_id,
                isset($session['payment_intent']) ? (string) $session['payment_intent'] : '',
                get_coupon_ids_from_stripe_session($session)
            );
        } elseif ($event_type === 'checkout.session.expired') {
            fail_pending_stripe_reservations($reservation_ids, 'expired');
        }
    }

    return new \WP_REST_Response(['received' => true], 200);
}

/**
 * Expire abandoned pending-payment reservations.
 */
function cleanup_expired_pending_payment_reservations(): void
{
    $cutoff = \gmdate('Y-m-d H:i:s', \time() - (get_pending_payment_cleanup_minutes() * 60));
    $reservation_ids = get_reservation_repository()->findExpiredPendingPaymentReservationIds($cutoff);

    if (empty($reservation_ids)) {
        return;
    }

    fail_pending_stripe_reservations($reservation_ids, 'expired');
}

/**
 * Register Stripe webhook REST route.
 */
function register_payment_rest_routes(): void
{
    \register_rest_route(
        'must-hotel-booking/v1',
        '/stripe/webhook',
        [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => __NAMESPACE__ . '\handle_stripe_webhook_request',
            'permission_callback' => '__return_true',
        ]
    );
}

/**
 * Bootstrap payment engine.
 */
function bootstrap_payment_engine(): void
{
    \add_action('rest_api_init', __NAMESPACE__ . '\register_payment_rest_routes');
    \add_action(get_lock_cleanup_cron_hook(), __NAMESPACE__ . '\cleanup_expired_pending_payment_reservations');
}

\add_action('must_hotel_booking/init', __NAMESPACE__ . '\bootstrap_payment_engine');
