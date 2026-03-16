<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\PaymentEngine;

/**
 * Build Payments admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_payments_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-payments');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Get supported payment methods catalog.
 *
 * @return array<string, array<string, string>>
 */
function get_payment_methods_catalog(): array
{
    return [
        'pay_at_hotel' => [
            'label' => \__('Pay at hotel', 'must-hotel-booking'),
            'description' => \__('Guest pays during check-in or check-out at the property.', 'must-hotel-booking'),
        ],
        'bank_transfer' => [
            'label' => \__('Bank transfer', 'must-hotel-booking'),
            'description' => \__('Guest pays via manual bank transfer.', 'must-hotel-booking'),
        ],
        'stripe' => [
            'label' => \__('Stripe', 'must-hotel-booking'),
            'description' => \__('Redirect guests to Stripe Checkout for secure card payment.', 'must-hotel-booking'),
        ],
    ];
}

/**
 * Get default payment method enablement states.
 *
 * @return array<string, bool>
 */
function get_default_payment_method_states(): array
{
    return [
        'pay_at_hotel' => true,
        'bank_transfer' => false,
        'stripe' => false,
    ];
}

/**
 * Normalize and merge payment method states against catalog.
 *
 * @param mixed $value
 * @return array<string, bool>
 */
function normalize_payment_method_states($value): array
{
    $defaults = get_default_payment_method_states();
    $catalog = get_payment_methods_catalog();
    $states = [];

    if (\is_array($value)) {
        foreach ($value as $method => $raw_state) {
            if (!\is_string($method) || !isset($catalog[$method])) {
                continue;
            }

            $states[$method] = (bool) $raw_state;
        }
    }

    return \array_merge($defaults, $states);
}

/**
 * Load payment method states from plugin options.
 *
 * @return array<string, bool>
 */
function get_payment_method_states(): array
{
    if (\class_exists(MustBookingConfig::class)) {
        $saved = MustBookingConfig::get_setting('payment_methods', get_default_payment_method_states());

        return normalize_payment_method_states($saved);
    }

    return get_default_payment_method_states();
}

/**
 * Save payment method states to plugin options.
 *
 * @param array<string, bool> $states
 */
function save_payment_method_states(array $states): bool
{
    $normalized = normalize_payment_method_states($states);

    if (\class_exists(MustBookingConfig::class)) {
        MustBookingConfig::set_setting('payment_methods', $normalized);

        return true;
    }

    return false;
}

/**
 * Get enabled payment methods.
 *
 * @return array<int, string>
 */
function get_enabled_payment_methods(): array
{
    $states = get_payment_method_states();
    $enabled = [];

    foreach ($states as $method => $is_enabled) {
        if ($is_enabled) {
            $enabled[] = $method;
        }
    }

    return $enabled;
}

/**
 * Handle save action for payment methods.
 */
function maybe_handle_payment_methods_save_request(): void
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return;
    }

    $action = isset($_POST['must_payment_methods_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_payment_methods_action'])) : '';

    if ($action !== 'save_payment_methods') {
        return;
    }

    $nonce = isset($_POST['must_payment_methods_nonce']) ? (string) \wp_unslash($_POST['must_payment_methods_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_payment_methods_save')) {
        \wp_safe_redirect(get_admin_payments_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    $catalog = get_payment_methods_catalog();
    $raw_states = isset($_POST['payment_methods']) && \is_array($_POST['payment_methods']) ? $_POST['payment_methods'] : [];
    $states = [];

    foreach ($catalog as $method => $meta) {
        $raw_value = isset($raw_states[$method]) ? (string) \wp_unslash($raw_states[$method]) : '';
        $states[$method] = ($raw_value === '1');
    }

    $saved = save_payment_method_states($states);
    $environment_catalog = PaymentEngine::getStripeEnvironmentCatalog();

    if (\class_exists(MustBookingConfig::class)) {
        foreach (\array_keys($environment_catalog) as $environment_key) {
            $setting_keys = PaymentEngine::getStripeEnvironmentSettingKeys((string) $environment_key);

            foreach ($setting_keys as $field_key => $setting_key) {
                $posted_field_key = 'stripe_' . $environment_key . '_' . $field_key;
                $value = isset($_POST[$posted_field_key])
                    ? \sanitize_text_field((string) \wp_unslash($_POST[$posted_field_key]))
                    : '';

                MustBookingConfig::set_setting($setting_key, $value);
            }
        }

        $production_keys = PaymentEngine::getStripeEnvironmentSettingKeys('production');

        MustBookingConfig::set_setting(
            'stripe_publishable_key',
            (string) MustBookingConfig::get_setting($production_keys['publishable_key'], '')
        );
        MustBookingConfig::set_setting(
            'stripe_secret_key',
            (string) MustBookingConfig::get_setting($production_keys['secret_key'], '')
        );
        MustBookingConfig::set_setting(
            'stripe_webhook_secret',
            (string) MustBookingConfig::get_setting($production_keys['webhook_secret'], '')
        );
    }

    \wp_safe_redirect(
        get_admin_payments_page_url(
            [
                'notice' => $saved ? 'payment_methods_saved' : 'payment_methods_save_failed',
            ]
        )
    );
    exit;
}

/**
 * Render Payments admin notice.
 */
function render_payments_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'payment_methods_saved' => ['success', \__('Payment methods updated successfully.', 'must-hotel-booking')],
        'payment_methods_save_failed' => ['error', \__('Unable to save payment methods.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';

    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';
}

/**
 * Render payment methods configuration admin page.
 */
function render_admin_payments_page(): void
{
    ensure_admin_capability();

    maybe_handle_payment_methods_save_request();

    $catalog = get_payment_methods_catalog();
    $states = get_payment_method_states();
    $environment_catalog = PaymentEngine::getStripeEnvironmentCatalog();
    $active_environment = PaymentEngine::getActiveSiteEnvironment();
    $webhook_url = PaymentEngine::getStripeWebhookUrl();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Payments', 'must-hotel-booking') . '</h1>';

    render_payments_admin_notice_from_query();

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Payment Methods', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_payments_page_url()) . '">';
    \wp_nonce_field('must_payment_methods_save', 'must_payment_methods_nonce');
    echo '<input type="hidden" name="must_payment_methods_action" value="save_payment_methods" />';

    echo '<table class="form-table" role="presentation"><tbody>';

    foreach ($catalog as $method => $meta) {
        $label = isset($meta['label']) ? (string) $meta['label'] : $method;
        $description = isset($meta['description']) ? (string) $meta['description'] : '';
        $enabled = isset($states[$method]) && $states[$method];

        echo '<tr>';
        echo '<th scope="row">' . \esc_html($label) . '</th>';
        echo '<td>';
        echo '<label>';
        echo '<input type="checkbox" name="payment_methods[' . \esc_attr($method) . ']" value="1"' . ($enabled ? ' checked' : '') . ' /> ';
        echo \esc_html__('Enable', 'must-hotel-booking');
        echo '</label>';

        if ($description !== '') {
            echo '<p class="description">' . \esc_html($description) . '</p>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<h2 style="margin-top:24px;">' . \esc_html__('Stripe Checkout', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Store a separate Stripe profile for each site environment. The active profile is selected from General Settings.', 'must-hotel-booking') . '</p>';
    echo '<p><strong>' . \esc_html__('Currently active profile:', 'must-hotel-booking') . '</strong> ' . \esc_html(PaymentEngine::getSiteEnvironmentLabel($active_environment)) . '</p>';

    foreach ($environment_catalog as $environment_key => $environment_meta) {
        if (!\is_string($environment_key)) {
            continue;
        }

        $environment_label = isset($environment_meta['label']) ? (string) $environment_meta['label'] : $environment_key;
        $environment_description = isset($environment_meta['description']) ? (string) $environment_meta['description'] : '';
        $is_active_environment = $environment_key === $active_environment;
        $credentials = PaymentEngine::getStripeEnvironmentCredentials($environment_key);

        echo '<div style="margin-top:20px; padding:16px; border:1px solid #dcdcde; border-radius:8px; background:' . ($is_active_environment ? '#f6ffed' : '#fff') . ';">';
        echo '<h3 style="margin-top:0;">' . \esc_html($environment_label) . ($is_active_environment ? ' <span style="font-size:12px; color:#2271b1;">' . \esc_html__('Active', 'must-hotel-booking') . '</span>' : '') . '</h3>';

        if ($environment_description !== '') {
            echo '<p class="description">' . \esc_html($environment_description) . '</p>';
        }

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="must-stripe-' . \esc_attr($environment_key) . '-publishable-key">' . \esc_html__('Publishable key', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-stripe-' . \esc_attr($environment_key) . '-publishable-key" type="text" class="regular-text" name="stripe_' . \esc_attr($environment_key) . '_publishable_key" value="' . \esc_attr((string) ($credentials['publishable_key'] ?? '')) . '" autocomplete="off" />';
        echo '<p class="description">' . \esc_html__('Starts with pk_. Required when Stripe is enabled for this environment.', 'must-hotel-booking') . '</p></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="must-stripe-' . \esc_attr($environment_key) . '-secret-key">' . \esc_html__('Secret key', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-stripe-' . \esc_attr($environment_key) . '-secret-key" type="password" class="regular-text" name="stripe_' . \esc_attr($environment_key) . '_secret_key" value="' . \esc_attr((string) ($credentials['secret_key'] ?? '')) . '" autocomplete="new-password" />';
        echo '<p class="description">' . \esc_html__('Starts with sk_. Used to create and verify Stripe Checkout sessions.', 'must-hotel-booking') . '</p></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th scope="row"><label for="must-stripe-' . \esc_attr($environment_key) . '-webhook-secret">' . \esc_html__('Webhook signing secret', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-stripe-' . \esc_attr($environment_key) . '-webhook-secret" type="password" class="regular-text" name="stripe_' . \esc_attr($environment_key) . '_webhook_secret" value="' . \esc_attr((string) ($credentials['webhook_secret'] ?? '')) . '" autocomplete="new-password" />';
        echo '<p class="description">' . \esc_html__('Starts with whsec_. Strongly recommended so webhook payloads can be verified.', 'must-hotel-booking') . '</p></td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '</div>';
    }

    echo '<table class="form-table" role="presentation" style="margin-top:16px;"><tbody>';
    echo '<tr>';
    echo '<th scope="row">' . \esc_html__('Webhook URL', 'must-hotel-booking') . '</th>';
    echo '<td><code>' . \esc_html($webhook_url) . '</code>';
    echo '<p class="description">' . \esc_html__('Register this URL in the Stripe dashboard for the environment currently running on this site.', 'must-hotel-booking') . '</p></td>';
    echo '</tr>';
    echo '</tbody></table>';

    \submit_button(\__('Save Payment Settings', 'must-hotel-booking'));

    echo '</form>';
    echo '</div>';
    echo '</div>';
}
