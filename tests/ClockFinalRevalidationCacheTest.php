<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    $GLOBALS['mhb_clock_http_queue'] = [
        ['status' => 200, 'body' => '{"available":true,"version":"cached"}', 'headers' => []],
        ['status' => 200, 'body' => '{"available":false,"version":"fresh"}', 'headers' => []],
        ['status' => 200, 'body' => '{"total_price":100,"version":"cached"}', 'headers' => []],
        ['status' => 200, 'body' => '{"total_price":120,"version":"fresh"}', 'headers' => []],
    ];
    $GLOBALS['mhb_clock_http_calls'] = 0;
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
        unset($url, $args);
        $GLOBALS['mhb_clock_http_calls']++;
        return \array_shift($GLOBALS['mhb_clock_http_queue']);
    }
    function is_wp_error($value): bool { unset($value); return false; }
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

    \MustHotelBooking\Provider\Clock\ClockRateLimiter::resetForTests();
    $client = new \MustHotelBooking\Provider\Clock\ClockApiClient();
    $availabilityPath = '/rates_availability?from=2026-07-01&to=2026-07-02';
    $productsPath = '/products?arrival=2026-07-01&departure=2026-07-02';

    $availabilityCached = $client->request('GET', $availabilityPath, ['endpoint_name' => 'rates_availability', 'cache_ttl' => 45]);
    $availabilityRepeated = $client->request('GET', $availabilityPath, ['endpoint_name' => 'rates_availability', 'cache_ttl' => 45]);
    $availabilityFresh = $client->request('GET', $availabilityPath, ['endpoint_name' => 'rates_availability', 'cache_ttl' => 45, 'bypass_cache' => true]);
    $pricingCached = $client->request('GET', $productsPath, ['endpoint_name' => 'products', 'cache_ttl' => 45]);
    $pricingRepeated = $client->request('GET', $productsPath, ['endpoint_name' => 'products', 'cache_ttl' => 45]);
    $pricingFresh = $client->request('GET', $productsPath, ['endpoint_name' => 'products', 'cache_ttl' => 45, 'bypass_cache' => true]);
    $failures = [];

    if (($availabilityCached->getData()['version'] ?? '') !== 'cached' || ($availabilityRepeated->getData()['version'] ?? '') !== 'cached') {
        $failures[] = 'Intermediate availability display should reuse the safe short-lived cache.';
    }
    if (($availabilityFresh->getData()['version'] ?? '') !== 'fresh' || !empty($availabilityFresh->getData()['available'])) {
        $failures[] = 'Final availability validation must bypass the cached available response.';
    }
    if (($pricingCached->getData()['version'] ?? '') !== 'cached' || ($pricingRepeated->getData()['version'] ?? '') !== 'cached') {
        $failures[] = 'Intermediate quote display should reuse the safe short-lived cache.';
    }
    if (($pricingFresh->getData()['version'] ?? '') !== 'fresh' || (float) ($pricingFresh->getData()['total_price'] ?? 0) !== 120.0) {
        $failures[] = 'Final price validation must bypass the cached old total.';
    }
    if ((int) $GLOBALS['mhb_clock_http_calls'] !== 4) {
        $failures[] = 'Only repeated intermediate reads may be deduplicated; both final validations must make fresh HTTP calls.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock final revalidation cache tests passed.\n";
}
