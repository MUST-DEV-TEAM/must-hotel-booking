<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    $GLOBALS['mhb_clock_http_queue'] = [
        ['status' => 429, 'body' => '{"message":"Too Many Requests"}', 'headers' => ['retry-after' => '1']],
        ['status' => 200, 'body' => '{"ok":true}', 'headers' => []],
        ['status' => 200, 'body' => '{"created":true}', 'headers' => []],
    ];
    $GLOBALS['mhb_clock_http_calls'] = 0;
    $GLOBALS['mhb_clock_last_headers'] = [];
    $GLOBALS['mhb_clock_transients'] = [];

    function __($text, $domain = null): string { unset($domain); return (string) $text; }
    function sanitize_key($value): string { return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function wp_json_encode($value, int $flags = 0): string { return (string) \json_encode($value, $flags); }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
    function wp_parse_url(string $url, int $component = -1) { return \parse_url($url, $component); }
    function add_query_arg(array $query, string $url): string { return $url . (empty($query) ? '' : '?' . \http_build_query($query)); }
    function get_transient(string $key) { return $GLOBALS['mhb_clock_transients'][$key] ?? false; }
    function set_transient(string $key, $value, int $expiration): bool { unset($expiration); $GLOBALS['mhb_clock_transients'][$key] = $value; return true; }
    function wp_remote_request(string $url, array $args): array
    {
        unset($url);
        $GLOBALS['mhb_clock_last_headers'] = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        $GLOBALS['mhb_clock_http_calls']++;
        return \array_shift($GLOBALS['mhb_clock_http_queue']);
    }
    function is_wp_error($value): bool { return false; }
    function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['status'] ?? 0); }
    function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
    function wp_remote_retrieve_header(array $response, string $name): string { return (string) ($response['headers'][\strtolower($name)] ?? ''); }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Provider\Storage {
    final class ProviderRequestLogRepository
    {
        public function create(array $data): int { unset($data); return 1; }
        public function complete(int $id, array $data): bool { unset($id, $data); return true; }
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function apiType(): string { return 'pms_api'; }
        public static function environment(): string { return 'sandbox'; }
        public static function subscriptionId(): string { return '1'; }
        public static function accountId(): string { return '2'; }
        public static function timeoutSeconds(): int { return 10; }
        public static function configurationErrors(): array { return []; }
        public static function apiUser(): string { return 'user'; }
        public static function apiKey(): string { return 'key'; }
    }

    final class ClockEndpointResolver
    {
        public static function normalizeApiType(string $type): string { return $type; }
        public static function buildUrl(string $apiType, string $path): string { return 'https://clock.example/' . $apiType . '/' . \ltrim($path, '/'); }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/ProviderDataSanitizer.php';
    require __DIR__ . '/../src/Provider/Clock/ClockApiResponse.php';
    require __DIR__ . '/../src/Provider/Clock/ClockRateLimiter.php';
    require __DIR__ . '/../src/Provider/Clock/ClockDigestTransport.php';
    require __DIR__ . '/../src/Provider/Clock/ClockApiClient.php';

    $now = 1000.0;
    $sleeps = [];
    $limiter = new \MustHotelBooking\Provider\Clock\ClockRateLimiter(
        static function () use (&$now): float { return $now; },
        static function (int $microseconds) use (&$now, &$sleeps): void {
            $sleeps[] = $microseconds;
            $now += $microseconds / 1000000;
        },
        static function (int $minimum, int $maximum): int { unset($maximum); return $minimum; }
    );
    \MustHotelBooking\Provider\Clock\ClockRateLimiter::resetForTests();
    $client = new \MustHotelBooking\Provider\Clock\ClockApiClient(
        new \MustHotelBooking\Provider\Storage\ProviderRequestLogRepository(),
        new \MustHotelBooking\Provider\Clock\ClockDigestTransport($limiter),
        $limiter
    );

    $first = $client->request('GET', '/rates_availability?from=2026-07-01', ['endpoint_name' => 'rates_availability'], 'clock.rates_availability.search');
    $second = $client->request('GET', '/rates_availability?from=2026-07-01', ['endpoint_name' => 'rates_availability'], 'clock.rates_availability.search');
    $idempotent = $client->request('POST', '/bookings', [
        'endpoint_name' => 'reservation_create',
        'idempotency_key' => 'clock-create-123',
        'body' => ['booking' => ['id' => 1]],
    ], 'clock.reservation_create');
    $failures = [];

    if (!$first->isSuccess() || !$second->isSuccess()) {
        $failures[] = 'Clock 429 responses should retry and recover when the next response succeeds.';
    }
    if (!$idempotent->isSuccess() || isset($GLOBALS['mhb_clock_last_headers']['Idempotency-Key'])) {
        $failures[] = 'Clock writes must not transmit an undocumented provider idempotency header.';
    }
    if ((int) $GLOBALS['mhb_clock_http_calls'] !== 3) {
        $failures[] = 'Identical GET requests in one request lifecycle must be deduplicated after the first successful result.';
    }
    if (empty($sleeps) || \max($sleeps) < 1000000) {
        $failures[] = 'Clock 429 handling must honor Retry-After before retrying.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock API rate-limit and request deduplication tests passed.\n";
}
