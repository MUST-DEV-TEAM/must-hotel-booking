<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Provider\Clock\ClockConfig;

/**
 * Fail-closed compatibility policy for the complete payment/Clock target.
 */
final class PaymentEnvironmentCompatibilityPolicy
{
    private const CLOCK_BINDINGS_OPTION = 'must_hotel_booking_approved_clock_payment_targets';

    /** @return array<string, mixed> */
    public function evaluateCurrent(string $provider, bool $clockRequired, array $attemptIdentity = []): array
    {
        $provider = \sanitize_key($provider);
        $siteEnvironment = self::configuredSiteEnvironment();
        $gateway = $this->currentGatewayIdentity($provider, $siteEnvironment);
        $clock = $this->currentClockIdentity();

        if (!\in_array($siteEnvironment, ['local', 'staging', 'production'], true)) {
            return $this->deny('site_environment_invalid');
        }
        if (empty($gateway['mode']) || empty($gateway['account_fingerprint'])) {
            return $this->deny('gateway_identity_unavailable');
        }

        $expectedGatewayMode = $provider === 'stripe'
            ? ($siteEnvironment === 'production' ? 'live' : 'test')
            : ($siteEnvironment === 'production' ? 'production' : 'staging');
        if ((string) $gateway['mode'] !== $expectedGatewayMode) {
            return $this->deny('gateway_environment_mismatch');
        }

        if ($clockRequired) {
            $expectedClockEnvironment = $siteEnvironment === 'production' ? 'production' : 'sandbox';
            if ((string) ($clock['environment'] ?? '') !== $expectedClockEnvironment) {
                return $this->deny('clock_environment_mismatch');
            }
            if ((string) ($clock['target_fingerprint'] ?? '') === '') {
                return $this->deny('clock_target_identity_unavailable');
            }
            $approved = self::getApprovedClockTarget($siteEnvironment);
            if ($approved === '') {
                return $this->deny('clock_target_approval_missing');
            }
            if (!\hash_equals($approved, (string) $clock['target_fingerprint'])) {
                return $this->deny('clock_target_approval_mismatch');
            }
        }

        $current = [
            'site_environment' => $siteEnvironment,
            'provider_mode' => (string) $gateway['mode'],
            'provider_account_fingerprint' => (string) $gateway['account_fingerprint'],
            'clock_environment' => $clockRequired ? (string) ($clock['environment'] ?? '') : '',
            'clock_target_fingerprint' => $clockRequired ? (string) ($clock['target_fingerprint'] ?? '') : '',
        ];
        if (!empty($attemptIdentity)) {
            foreach ($current as $field => $value) {
                $stored = (string) ($attemptIdentity[$field] ?? '');
                if ($stored === '' || !\hash_equals($stored, $value)) {
                    return $this->deny($field . '_changed');
                }
            }
        }

        return ['allowed' => true, 'reason_code' => ''] + $current;
    }

    /** @return array<string, string> */
    public function currentGatewayIdentity(string $provider, string $siteEnvironment = ''): array
    {
        $provider = \sanitize_key($provider);
        $siteEnvironment = $siteEnvironment !== ''
            ? PaymentEngine::normalizeStripeEnvironment($siteEnvironment)
            : PaymentEngine::getActiveSiteEnvironment();
        if ($provider === 'stripe') {
            $credentials = PaymentEngine::getStripeEnvironmentCredentials($siteEnvironment);
            $mode = $siteEnvironment === 'production' ? 'live' : 'test';
            $identity = \implode('|', [
                (string) ($credentials['publishable_key'] ?? ''),
                (string) ($credentials['secret_key'] ?? ''),
            ]);
        } elseif ($provider === 'pokpay') {
            $credentials = PaymentEngine::getPokPayEnvironmentCredentials($siteEnvironment);
            $mode = PaymentEngine::getPokPayApiEnvironment($siteEnvironment);
            $identity = \implode('|', [
                (string) ($credentials['merchant_id'] ?? ''),
                (string) ($credentials['key_id'] ?? ''),
                (string) ($credentials['key_secret'] ?? ''),
            ]);
        } else {
            return ['mode' => '', 'account_fingerprint' => ''];
        }
        if (\trim(\str_replace('|', '', $identity)) === '') {
            return ['mode' => $mode, 'account_fingerprint' => ''];
        }
        $salt = \function_exists('wp_salt') ? \wp_salt('auth') : __FILE__;
        return [
            'mode' => $mode,
            'account_fingerprint' => \hash_hmac('sha256', $provider . '|' . $siteEnvironment . '|' . $identity, $salt),
        ];
    }

    /** @return array<string, string> */
    public function currentClockIdentity(): array
    {
        if (!ClockConfig::isEnabled()) {
            return ['environment' => '', 'target_fingerprint' => ''];
        }
        $environment = \sanitize_key(ClockConfig::environment());
        if (!\in_array($environment, ['sandbox', 'production'], true)) {
            return ['environment' => $environment, 'target_fingerprint' => ''];
        }
        $targetUrl = ClockConfig::resolvedBaseUrl(ClockConfig::apiType());
        if ($targetUrl === '') {
            return ['environment' => $environment, 'target_fingerprint' => ''];
        }
        $parts = [
            $environment,
            ClockConfig::apiType(),
            $targetUrl,
            ClockConfig::region(),
            ClockConfig::subscriptionId(),
            ClockConfig::accountId(),
            ClockConfig::propertyId(),
            ClockConfig::wbeHotelId(),
        ];
        return [
            'environment' => $environment,
            'target_fingerprint' => \hash('sha256', 'clock|' . \implode('|', $parts)),
        ];
    }

    public static function getApprovedClockTarget(string $siteEnvironment): string
    {
        $bindings = \get_option(self::CLOCK_BINDINGS_OPTION, []);
        $siteEnvironment = \sanitize_key($siteEnvironment);
        return \is_array($bindings) && isset($bindings[$siteEnvironment]['fingerprint'])
            ? (string) $bindings[$siteEnvironment]['fingerprint']
            : '';
    }

    public static function configuredSiteEnvironment(): string
    {
        $settings = \MustHotelBooking\Core\MustBookingConfig::get_all_settings();
        $environment = \is_array($settings) ? \sanitize_key((string) ($settings['site_environment'] ?? '')) : '';
        return \in_array($environment, ['local', 'staging', 'production'], true) ? $environment : '';
    }

    public static function approveCurrentClockTarget(string $siteEnvironment): bool
    {
        $siteEnvironment = \sanitize_key($siteEnvironment);
        $identity = (new self())->currentClockIdentity();
        $fingerprint = (string) ($identity['target_fingerprint'] ?? '');
        if (!\in_array($siteEnvironment, ['local', 'staging', 'production'], true) || $fingerprint === '') {
            return false;
        }
        $bindings = \get_option(self::CLOCK_BINDINGS_OPTION, []);
        $bindings = \is_array($bindings) ? $bindings : [];
        $bindings[$siteEnvironment] = [
            'fingerprint' => $fingerprint,
            'clock_environment' => (string) ($identity['environment'] ?? ''),
            'approved_at' => \current_time('mysql'),
        ];
        $updated = \update_option(self::CLOCK_BINDINGS_OPTION, $bindings, false);
        return $updated || self::getApprovedClockTarget($siteEnvironment) === $fingerprint;
    }

    /** @return array{allowed:false,reason_code:string} */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason_code' => \sanitize_key($reason)];
    }
}
