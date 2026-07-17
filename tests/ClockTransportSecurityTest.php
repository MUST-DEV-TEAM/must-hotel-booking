<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    $GLOBALS['mhb_clock_transport_queue'] = [];
    $GLOBALS['mhb_clock_transport_calls'] = [];
    $GLOBALS['mhb_clock_transport_transients'] = [];

    class WP_Error
    {
        private string $code;
        private string $message;
        public function __construct(string $code, string $message) { $this->code = $code; $this->message = $message; }
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }

    function __(string $text, ?string $domain = null): string { unset($domain); return $text; }
    function sanitize_key($value): string { return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function wp_parse_url(string $url, int $component = -1) { return \parse_url($url, $component); }
    function esc_url_raw(string $url): string { return \filter_var($url, FILTER_SANITIZE_URL) ?: ''; }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
    function get_transient(string $key) { return $GLOBALS['mhb_clock_transport_transients'][$key] ?? false; }
    function set_transient(string $key, $value, int $expiration): bool
    {
        unset($expiration);
        $GLOBALS['mhb_clock_transport_transients'][$key] = $value;
        return true;
    }
    function wp_remote_request(string $url, array $args)
    {
        $GLOBALS['mhb_clock_transport_calls'][] = ['url' => $url, 'args' => $args];
        return \array_shift($GLOBALS['mhb_clock_transport_queue']);
    }
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['status'] ?? 0); }
    function wp_remote_retrieve_header(array $response, string $name): string
    {
        return (string) ($response['headers'][\strtolower($name)] ?? '');
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function apiType(): string { return 'pms_api'; }
        public static function apiBaseUrlForType(string $apiType): string
        {
            return $apiType === 'base_api'
                ? 'https://eu.clock-software.com/base_api/123/456'
                : 'https://eu.clock-software.com/pms_api/123/456';
        }
        public static function region(): string { return 'eu'; }
        public static function subscriptionId(): string { return '123'; }
        public static function accountId(): string { return '456'; }
        public static function apiUser(): string { return 'api-user'; }
        public static function apiKey(): string { return 'api-key'; }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Clock/ClockEndpointResolver.php';
    require __DIR__ . '/../src/Provider/Clock/ClockRateLimiter.php';
    require __DIR__ . '/../src/Provider/Clock/ClockDigestTransport.php';

    $failures = [];
    $resolver = \MustHotelBooking\Provider\Clock\ClockEndpointResolver::class;
    $valid = 'https://eu.clock-software.com/pms_api/123/456/bookings/7?include=folios';
    if ($resolver::buildUrl('pms_api', $valid) !== $valid) {
        $failures[] = 'Absolute Clock URLs inside the configured API base must remain valid.';
    }
    foreach ([
        'http://eu.clock-software.com/pms_api/123/456/bookings/7',
        'https://evil.example/pms_api/123/456/bookings/7',
        'https://eu.clock-software.com/pms_api/123/4567/bookings/7',
        'https://eu.clock-software.com/pms_api/123/456/../admin',
        'https://eu.clock-software.com/pms_api/123/456/%252e%252e/admin',
    ] as $unsafe) {
        if ($resolver::buildUrl('pms_api', $unsafe) !== '') {
            $failures[] = 'Unsafe absolute Clock URL was accepted: ' . $unsafe;
        }
    }

    $now = 1000.0;
    $limiter = new \MustHotelBooking\Provider\Clock\ClockRateLimiter(
        static function () use (&$now): float { return $now; },
        static function (int $microseconds) use (&$now): void { $now += $microseconds / 1000000; },
        static function (int $minimum, int $maximum): int { unset($maximum); return $minimum; }
    );
    \MustHotelBooking\Provider\Clock\ClockRateLimiter::resetForTests();
    $transport = new \MustHotelBooking\Provider\Clock\ClockDigestTransport($limiter);
    $GLOBALS['mhb_clock_transport_queue'] = [
        ['status' => 401, 'headers' => ['www-authenticate' => 'Digest realm="Clock", nonce="old", qop="auth", algorithm=SHA-256']],
        ['status' => 401, 'headers' => ['www-authenticate' => 'Digest realm="Clock", nonce="fresh", qop="auth", algorithm=SHA-256, stale=true']],
        ['status' => 200, 'headers' => []],
    ];
    $GLOBALS['mhb_clock_transport_calls'] = [];
    $response = $transport->request(
        'https://eu.clock-software.com/pms_api/123/456/bookings/7?include=folios',
        ['method' => 'POST', 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"booking":{}}']
    );
    $calls = $GLOBALS['mhb_clock_transport_calls'];
    $authorization = (string) ($calls[2]['args']['headers']['Authorization'] ?? '');
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || \count($calls) !== 3) {
        $failures[] = 'Digest transport must refresh one rejected nonce and complete the authenticated request.';
    }
    if (isset($calls[0]['args']['body'])) {
        $failures[] = 'Digest challenge probe must not send the write body.';
    }
    if (\strpos($authorization, 'nonce="fresh"') === false || \strpos($authorization, 'algorithm=SHA-256') === false || \strpos($authorization, 'qop=auth') === false) {
        $failures[] = 'Refreshed Digest authorization must use the current nonce, supported algorithm, and auth qop.';
    }

    $GLOBALS['mhb_clock_transport_queue'] = [
        ['status' => 401, 'headers' => ['www-authenticate' => 'Digest realm="Clock", nonce="n", qop="auth-int"']],
    ];
    $unsupported = $transport->request($valid, ['method' => 'POST', 'body' => '{}']);
    if (!is_wp_error($unsupported) || $unsupported->get_error_code() !== 'digest_auth_failed') {
        $failures[] = 'Digest auth-int-only challenges must fail closed because the entity-body digest is unsupported.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock transport security tests passed.\n";
}
