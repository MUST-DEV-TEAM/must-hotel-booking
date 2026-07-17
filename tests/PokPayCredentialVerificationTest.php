<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    $GLOBALS['mhb_options'] = [];
    $GLOBALS['mhb_transients'] = [];
    $GLOBALS['mhb_http_response'] = [
        'status' => 200,
        'body' => '{"accessToken":"provider-token","expiresIn":600}',
    ];

    function __($text, $domain = null): string { unset($domain); return (string) $text; }
    function sanitize_key($value): string { return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function sanitize_text_field($value): string { return \trim((string) $value); }
    function current_time(string $format): string { return $format === 'mysql' ? '2026-06-22 12:00:00' : \gmdate($format); }
    function home_url(string $path = ''): string { return 'https://staging.example.test/' . \ltrim($path, '/'); }
    function wp_parse_url(string $url) { return \parse_url($url); }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
    function wp_salt(string $scheme = 'auth'): string { return 'test-salt-' . $scheme; }
    function wp_json_encode($value, int $flags = 0): string { return (string) \json_encode($value, $flags); }
    function get_option(string $key, $default = false) { return $GLOBALS['mhb_options'][$key] ?? $default; }
    function update_option(string $key, $value, bool $autoload = false): bool { unset($autoload); $GLOBALS['mhb_options'][$key] = $value; return true; }
    function get_transient(string $key) { return $GLOBALS['mhb_transients'][$key] ?? false; }
    function set_transient(string $key, $value, int $expiration): bool { unset($expiration); $GLOBALS['mhb_transients'][$key] = $value; return true; }
    function delete_transient(string $key): bool { unset($GLOBALS['mhb_transients'][$key]); return true; }
    function wp_remote_request(string $url, array $args): array { unset($url, $args); return $GLOBALS['mhb_http_response']; }
    function is_wp_error($value): bool { return false; }
    function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['status'] ?? 0); }
    function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
}

namespace MustHotelBooking\Core {
    final class MustBookingConfig
    {
        /** @var array<string, string> */
        public static $settings = [
            'site_environment' => 'staging',
            'pokpay_staging_merchant_id' => 'merchant-123456',
            'pokpay_staging_key_id' => 'key-123456',
            'pokpay_staging_key_secret' => 'secret-123456',
        ];

        /** @return array<string, string> */
        public static function get_all_settings(): array { return self::$settings; }
    }
}

namespace MustHotelBooking\Provider\Storage {
    final class ProviderRequestLogRepository
    {
        /** @var array<int, array<string, mixed>> */
        public static $created = [];
        /** @var array<int, array<string, mixed>> */
        public static $completed = [];

        /** @param array<string, mixed> $data */
        public function create(array $data): int { self::$created[] = $data; return \count(self::$created); }
        /** @param array<string, mixed> $data */
        public function complete(int $id, array $data): bool { self::$completed[$id] = $data; return true; }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/ProviderDataSanitizer.php';
    require __DIR__ . '/../src/Engine/PaymentEngine.php';

    use MustHotelBooking\Core\MustBookingConfig;
    use MustHotelBooking\Engine\PaymentEngine;
    use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;

    $failures = [];

    if (PaymentEngine::getPokPayBaseUrl('staging') !== 'https://api-staging.pokpay.io') {
        $failures[] = 'Staging must use the PokPay staging API.';
    }
    if (PaymentEngine::getPokPayBaseUrl('local') !== 'https://api-staging.pokpay.io') {
        $failures[] = 'Local development must use the PokPay staging API.';
    }
    if (PaymentEngine::getPokPayBaseUrl('production') !== 'https://api.pokpay.io') {
        $failures[] = 'Production must use the PokPay production API.';
    }

    $verified = PaymentEngine::verifyPokPayCredentials('staging');
    if ((string) ($verified['status'] ?? '') !== 'verified' || empty($verified['authentication_success'])) {
        $failures[] = 'Successful token authentication must mark credentials verified.';
    }
    $savedJson = (string) \json_encode($GLOBALS['mhb_options']);
    if (\strpos($savedJson, 'provider-token') !== false || \strpos($savedJson, 'secret-123456') !== false) {
        $failures[] = 'Credential verification state must never persist tokens or key secrets.';
    }
    $requestJson = (string) \json_encode(ProviderRequestLogRepository::$created);
    if (\strpos($requestJson, 'secret-123456') !== false) {
        $failures[] = 'PokPay authentication logs must redact key secrets.';
    }

    MustBookingConfig::$settings['pokpay_staging_key_secret'] = 'rejected-secret';
    $GLOBALS['mhb_http_response'] = [
        'status' => 401,
        'body' => '{"error":{"code":"INVALID_CREDENTIALS","message":"Credentials rejected"}}',
    ];
    $rejected = PaymentEngine::verifyPokPayCredentials('staging');
    if ((string) ($rejected['status'] ?? '') !== 'rejected' || (int) ($rejected['http_status'] ?? 0) !== 401) {
        $failures[] = 'HTTP 401 must mark PokPay credentials rejected.';
    }
    if ((string) ($rejected['error_code'] ?? '') !== 'INVALID_CREDENTIALS') {
        $failures[] = 'Sanitized PokPay error codes must be persisted.';
    }

    MustBookingConfig::$settings['pokpay_staging_key_secret'] = 'changed-again';
    $unverified = PaymentEngine::getPokPayCredentialState('staging');
    if ((string) ($unverified['status'] ?? '') !== 'unverified') {
        $failures[] = 'Changing credentials must invalidate the previous verification fingerprint.';
    }

    $GLOBALS['mhb_http_response'] = [
        'status' => 403,
        'body' => '{"error":{"code":"KEY_REJECTED","message":"Key rejected"}}',
    ];
    $accessTokenMethod = new \ReflectionMethod(PaymentEngine::class, 'getPokPayAccessToken');
    if (\PHP_VERSION_ID < 80100) {
        $accessTokenMethod->setAccessible(true);
    }
    $accessTokenResult = $accessTokenMethod->invoke(null, 'staging');
    $automaticRejected = PaymentEngine::getPokPayCredentialState('staging');
    if (!empty($accessTokenResult['success']) || (string) ($automaticRejected['status'] ?? '') !== 'rejected') {
        $failures[] = 'Automatic PokPay token authentication must persist rejected credential state.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "PokPay credential verification tests passed.\n";
}
