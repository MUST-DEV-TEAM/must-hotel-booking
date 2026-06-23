<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Core\BookingPerformanceMonitor;
use MustHotelBooking\Provider\ProviderDataSanitizer;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;

final class ClockApiClient
{
    /** @var ProviderRequestLogRepository */
    private $logs;

    /** @var ClockDigestTransport */
    private $transport;

    /** @var ClockRateLimiter */
    private $rateLimiter;

    /** @var array<string, ClockApiResponse> */
    private static $requestCache = [];

    /** @var string */
    private $lastCacheStatus = 'miss';

    public function __construct(
        ?ProviderRequestLogRepository $logs = null,
        ?ClockDigestTransport $transport = null,
        ?ClockRateLimiter $rateLimiter = null
    )
    {
        $this->logs = $logs ?: new ProviderRequestLogRepository();
        $this->rateLimiter = $rateLimiter ?: new ClockRateLimiter();
        $this->transport = $transport ?: new ClockDigestTransport($this->rateLimiter);
    }

    /**
     * @param array<string, scalar|array<int, scalar>> $query
     * @param array<string, mixed> $options
     */
    public function get(string $path, array $query = [], string $operation = 'clock.get', array $options = []): ClockApiResponse
    {
        $options['query'] = $query;

        return $this->request('GET', $path, [
        ] + $options, $operation);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $path, array $options = [], string $operation = 'clock.request'): ClockApiResponse
    {
        $started = \microtime(true);
        $method = \strtoupper($method);
        $operation = $operation !== '' ? $operation : 'clock.request';
        $correlationId = $this->correlationId();
        $apiType = isset($options['api_type']) ? ClockEndpointResolver::normalizeApiType((string) $options['api_type']) : ClockConfig::apiType();
        $endpointName = isset($options['endpoint_name']) ? \sanitize_key((string) $options['endpoint_name']) : $operation;
        $url = $this->buildUrl($path, isset($options['query']) && \is_array($options['query']) ? $options['query'] : [], $apiType);
        $cacheKey = $this->requestCacheKey($method, $url, $options);
        $bypassCache = $method === 'GET' && (!empty($options['bypass_cache']) || !empty($options['force_refresh']));
        $cacheTtl = $method === 'GET' && !$bypassCache ? $this->cacheTtl($endpointName, $options) : 0;

        if ($method === 'GET' && !$bypassCache) {
            $cached = $this->cachedResponse($cacheKey, $cacheTtl);

            if ($cached instanceof ClockApiResponse) {
                $this->recordPerformance($endpointName, $operation, $cached, $this->lastCacheStatus, 0, 0);
                return $cached;
            }
        } else {
            self::$requestCache = [];
        }

        $requestSummary = [
            'method' => $method,
            'url' => $this->redactUrl($url),
            'environment' => ClockConfig::environment(),
            'api_type' => $apiType,
            'endpoint_name' => $endpointName,
            'path' => $path,
            'has_body' => isset($options['body']),
            'subscription_id_set' => ClockConfig::subscriptionId() !== '',
            'account_id_set' => ClockConfig::accountId() !== '',
            'attempt' => 1,
            'cache_mode' => $bypassCache ? 'bypass' : ($cacheTtl > 0 ? 'enabled' : 'request_only'),
            'booking_reference' => isset($options['booking_reference']) ? (string) $options['booking_reference'] : '',
            'payment_id' => isset($options['payment_id']) ? \max(0, (int) $options['payment_id']) : 0,
            'provider_transaction_id' => isset($options['provider_transaction_id']) ? (string) $options['provider_transaction_id'] : '',
            'clock_folio_id' => isset($options['clock_folio_id']) ? (string) $options['clock_folio_id'] : '',
        ];
        if (isset($options['body'])) {
            $requestSummary['body'] = $this->redact($options['body']);
        }
        $logId = $this->measurePerformance(
            'provider_log_write',
            function () use ($operation, $correlationId, $options, $requestSummary): int {
                return $this->logs->create([
                    'provider' => ProviderManager::CLOCK_MODE,
                    'operation' => $operation,
                    'direction' => 'outbound',
                    'correlation_id' => $correlationId,
                    'idempotency_key' => isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : '',
                    'reservation_id' => isset($options['reservation_id']) ? \max(0, (int) $options['reservation_id']) : 0,
                    'external_id' => isset($options['external_id']) ? (string) $options['external_id'] : '',
                    'request_summary' => $requestSummary,
                ]);
            }
        );

        $errors = ClockConfig::configurationErrors();

        if (!empty($errors)) {
            $response = new ClockApiResponse(0, '', null, 'config_missing', \implode(' ', $errors), $this->durationMs($started));
            $this->completeLog($logId, $response, 0, []);
            $this->recordPerformance($endpointName, $operation, $response, 'miss', 0);

            return $response;
        }

        $args = [
            'method' => $method,
            'timeout' => isset($options['timeout'])
                ? \max(1, \min(ClockConfig::timeoutSeconds(), (int) $options['timeout']))
                : ClockConfig::timeoutSeconds(),
            'headers' => $this->headers($correlationId),
        ];

        if (isset($options['body'])) {
            $args['body'] = \function_exists('wp_json_encode')
                ? \wp_json_encode($options['body'])
                : \json_encode($options['body']);
        }

        if (!\function_exists('wp_remote_request')) {
            $response = new ClockApiResponse(0, '', null, 'wordpress_http_missing', 'WordPress HTTP API is unavailable.', $this->durationMs($started));
            $this->completeLog($logId, $response, 0, []);
            $this->recordPerformance($endpointName, $operation, $response, 'miss', 0);

            return $response;
        }

        $attempt = 0;
        $rateLimitEvents = [];
        $response = new ClockApiResponse(0, '', null, 'request_not_attempted', 'Clock request was not attempted.', 0);

        $maxAttempts = $method === 'GET' ? 2 : 1;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $throttleWait = 0;
            $raw = $this->transport->request($url, $args);
            $duration = $this->durationMs($started);

            if (\is_wp_error($raw)) {
                $response = new ClockApiResponse(0, '', null, $raw->get_error_code(), $raw->get_error_message(), $duration);
                break;
            }

            $statusCode = (int) \wp_remote_retrieve_response_code($raw);
            $body = (string) \wp_remote_retrieve_body($raw);
            $data = $this->decodeJson($body);

            if ($body !== '' && $data === null && $statusCode >= 200 && $statusCode < 300 && $this->isJsonDecodeFailure($body)) {
                $response = new ClockApiResponse($statusCode, $body, null, 'bad_response_body', \__('Clock returned a non-JSON response body.', 'must-hotel-booking'), $duration);
            } else {
                $response = new ClockApiResponse($statusCode, $body, $data, '', '', $duration);

                if (!$response->isSuccess() && $statusCode >= 400) {
                    $response = new ClockApiResponse($statusCode, $body, $data, $this->errorCodeForStatus($statusCode), $this->extractErrorMessage($data, $body), $duration);
                }
            }

            if ($statusCode !== 429 || $attempt >= $maxAttempts) {
                break;
            }

            $retryAfter = (string) \wp_remote_retrieve_header($raw, 'retry-after');
            $retryDelay = $this->rateLimiter->retryDelayMicroseconds($attempt, $retryAfter);
            $rateLimitEvents[] = [
                'attempt' => $attempt,
                'http_status' => 429,
                'retry_after' => $retryAfter,
                'backoff_ms' => (int) \round($retryDelay / 1000),
                'throttle_wait_ms' => (int) \round($throttleWait / 1000),
            ];
            $this->rateLimiter->sleep($retryDelay);
        }

        $this->completeLog($logId, $response, $attempt, $rateLimitEvents);

        if ($method === 'GET' && !$bypassCache && $response->isSuccess()) {
            $this->storeCachedResponse($cacheKey, $response, $cacheTtl);
        }

        $this->recordPerformance($endpointName, $operation, $response, $bypassCache ? 'bypass' : 'miss', \max(0, $attempt - 1));

        return $response;
    }

    /**
     * @param array<string, scalar|array<int, scalar>> $query
     */
    private function buildUrl(string $path, array $query = [], string $apiType = ''): string
    {
        $path = \trim($path);

        if (\preg_match('#^https?://#i', $path) === 1) {
            $url = $path;
        } else {
            $url = ClockEndpointResolver::buildUrl($apiType !== '' ? $apiType : ClockConfig::apiType(), $path);
        }

        if (!empty($query) && \function_exists('add_query_arg')) {
            $url = \add_query_arg($query, $url);
        }

        return $url;
    }

    /** @return array<string, string> */
    private function headers(string $correlationId): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-MHB-Correlation-ID' => $correlationId,
        ];

        return $headers;
    }

    /** @param array<int, array<string, mixed>> $rateLimitEvents */
    private function completeLog(int $logId, ClockApiResponse $response, int $attempts, array $rateLimitEvents): void
    {
        if ($logId <= 0) {
            return;
        }

        $data = $response->getData();
        $responseSummary = [
            'status_code' => $response->getStatusCode(),
            'error_category' => $response->getErrorCode(),
            'retryable' => $this->isRetryable($response),
            'body_preview' => $this->bodyPreview($response->getBody()),
            'decoded_type' => \gettype($data),
            'decoded_keys' => \is_array($data) ? \array_slice(\array_keys($data), 0, 20) : [],
            'attempts' => $attempts,
            'rate_limit_events' => $rateLimitEvents,
        ];

        if (!$response->isSuccess()) {
            $responseSummary['body'] = $this->bodyForLog($response->getBody());
        }

        $this->measurePerformance(
            'provider_log_write',
            function () use ($logId, $response, $responseSummary): void {
                $this->logs->complete($logId, [
                    'http_status' => $response->getStatusCode(),
                    'success' => $response->isSuccess() ? 1 : 0,
                    'error_code' => $response->getErrorCode(),
                    'error_message' => $response->getErrorMessage(),
                    'duration_ms' => $response->getDurationMs(),
                    'response_summary' => $responseSummary,
                ]);
            }
        );
    }

    /** @return mixed */
    private function decodeJson(string $body)
    {
        if ($body === '') {
            return null;
        }

        $decoded = \json_decode($body, true);

        return \json_last_error() === \JSON_ERROR_NONE ? $decoded : null;
    }

    private function isJsonDecodeFailure(string $body): bool
    {
        \json_decode($body, true);

        return \json_last_error() !== \JSON_ERROR_NONE;
    }

    /**
     * @param mixed $data
     */
    private function extractErrorMessage($data, string $body): string
    {
        if (\is_array($data)) {
            foreach (['message', 'error', 'error_message', 'detail'] as $key) {
                if (isset($data[$key]) && \is_scalar($data[$key])) {
                    return (string) $data[$key];
                }
            }
        }

        return $this->bodyPreview($body);
    }

    private function bodyPreview(string $body): string
    {
        $body = \trim($body);

        if ($body === '') {
            return '';
        }

        $decoded = \json_decode($body, true);

        if (\json_last_error() === \JSON_ERROR_NONE && \is_array($decoded)) {
            $encoded = \function_exists('wp_json_encode')
                ? \wp_json_encode($this->redact($decoded))
                : \json_encode($this->redact($decoded));

            if (\is_string($encoded)) {
                return \substr($encoded, 0, 1000);
            }
        }

        return \substr(ProviderDataSanitizer::sanitizeText($body), 0, 1000);
    }

    private function bodyForLog(string $body): string
    {
        $body = \trim($body);

        if ($body === '') {
            return '';
        }

        $decoded = \json_decode($body, true);

        if (\json_last_error() === \JSON_ERROR_NONE && \is_array($decoded)) {
            $encoded = \function_exists('wp_json_encode')
                ? \wp_json_encode($this->redact($decoded))
                : \json_encode($this->redact($decoded));

            return \is_string($encoded) ? $encoded : '';
        }

        return ProviderDataSanitizer::sanitizeText($body);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function redact($value)
    {
        return ProviderDataSanitizer::sanitize($value);
    }

    private function redactUrl(string $url): string
    {
        return (string) \preg_replace('/([?&](?:api[_-]?key|key|token|password|secret)=)[^&]+/i', '$1[redacted]', $url);
    }

    /** @param array<string, mixed> $options */
    private function requestCacheKey(string $method, string $url, array $options): string
    {
        $body = isset($options['body']) ? $options['body'] : null;
        $encoded = \function_exists('wp_json_encode')
            ? \wp_json_encode([$method, $url, $body])
            : \json_encode([$method, $url, $body]);

        return 'mhb_clock_request_' . \sha1(\is_string($encoded) ? $encoded : $method . '|' . $url);
    }

    /** @param array<string, mixed> $options */
    private function cacheTtl(string $endpointName, array $options): int
    {
        if (isset($options['cache_ttl'])) {
            return \max(0, \min(900, (int) $options['cache_ttl']));
        }

        return \in_array(
            $endpointName,
            ['room_types', 'rooms', 'rates', 'rate_plans', 'wbe_room_type_rates', 'payment_sub_types', 'rates_availability', 'products'],
            true
        ) ? (\in_array($endpointName, ['rates_availability', 'products'], true) ? 45 : 60) : 0;
    }

    private function cachedResponse(string $cacheKey, int $cacheTtl): ?ClockApiResponse
    {
        $this->lastCacheStatus = 'miss';

        if (isset(self::$requestCache[$cacheKey])) {
            $this->lastCacheStatus = 'request_hit';
            return self::$requestCache[$cacheKey];
        }

        if ($cacheTtl <= 0 || !\function_exists('get_transient')) {
            return null;
        }

        $cached = \get_transient($cacheKey);

        if (!\is_array($cached)) {
            return null;
        }

        $response = new ClockApiResponse(
            (int) ($cached['status_code'] ?? 0),
            (string) ($cached['body'] ?? ''),
            $cached['data'] ?? null,
            (string) ($cached['error_code'] ?? ''),
            (string) ($cached['error_message'] ?? ''),
            (int) ($cached['duration_ms'] ?? 0)
        );
        self::$requestCache[$cacheKey] = $response;
        $this->lastCacheStatus = 'transient_hit';

        return $response;
    }

    private function storeCachedResponse(string $cacheKey, ClockApiResponse $response, int $cacheTtl): void
    {
        self::$requestCache[$cacheKey] = $response;

        if ($cacheTtl <= 0 || !\function_exists('set_transient')) {
            return;
        }

        \set_transient(
            $cacheKey,
            [
                'status_code' => $response->getStatusCode(),
                'body' => $response->getBody(),
                'data' => $response->getData(),
                'error_code' => $response->getErrorCode(),
                'error_message' => $response->getErrorMessage(),
                'duration_ms' => $response->getDurationMs(),
            ],
            $cacheTtl
        );
    }

    private function errorCodeForStatus(int $statusCode): string
    {
        if ($statusCode === 401) {
            return 'auth_failed';
        }

        if ($statusCode === 403) {
            return 'forbidden';
        }

        if ($statusCode === 404) {
            return 'bad_endpoint';
        }

        if ($statusCode === 422) {
            return 'validation_error';
        }

        if ($statusCode === 429) {
            return 'rate_limited';
        }

        if ($statusCode >= 500) {
            return 'provider_unavailable';
        }

        return 'http_' . $statusCode;
    }

    private function isRetryable(ClockApiResponse $response): bool
    {
        return $response->getStatusCode() === 429 || $response->getStatusCode() >= 500 || $response->isConnectivityFailure();
    }

    private function correlationId(): string
    {
        if (\class_exists(BookingPerformanceMonitor::class)) {
            return BookingPerformanceMonitor::getCorrelationId();
        }

        if (\function_exists('wp_generate_uuid4')) {
            return \wp_generate_uuid4();
        }

        return \bin2hex(\random_bytes(16));
    }

    private function recordPerformance(
        string $endpointName,
        string $operation,
        ClockApiResponse $response,
        string $cacheStatus,
        int $retryCount,
        ?int $durationOverride = null
    ): void {
        if (!\class_exists(BookingPerformanceMonitor::class)) {
            return;
        }

        $errorCode = \strtolower($response->getErrorCode());
        BookingPerformanceMonitor::recordClockRequest(
            $endpointName,
            $operation,
            $durationOverride !== null ? $durationOverride : $response->getDurationMs(),
            $cacheStatus,
            $response->getStatusCode(),
            $retryCount,
            \strpos($errorCode, 'timeout') !== false || \strpos($errorCode, 'timed_out') !== false
        );
    }

    /**
     * @param callable $callback
     * @return mixed
     */
    private function measurePerformance(string $label, callable $callback)
    {
        if (\class_exists(BookingPerformanceMonitor::class)) {
            return BookingPerformanceMonitor::measure($label, $callback);
        }

        return $callback();
    }

    private function durationMs(float $started): int
    {
        return \max(0, (int) \round((\microtime(true) - $started) * 1000));
    }
}
