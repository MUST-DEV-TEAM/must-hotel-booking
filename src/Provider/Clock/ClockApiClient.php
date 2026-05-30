<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;

final class ClockApiClient
{
    /** @var ProviderRequestLogRepository */
    private $logs;

    /** @var ClockDigestTransport */
    private $transport;

    public function __construct(?ProviderRequestLogRepository $logs = null, ?ClockDigestTransport $transport = null)
    {
        $this->logs = $logs ?: new ProviderRequestLogRepository();
        $this->transport = $transport ?: new ClockDigestTransport();
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
        $requestSummary = [
            'method' => $method,
            'url' => $this->redactUrl($url),
            'api_type' => $apiType,
            'endpoint_name' => $endpointName,
            'path' => $path,
            'has_body' => isset($options['body']),
            'subscription_id_set' => ClockConfig::subscriptionId() !== '',
            'account_id_set' => ClockConfig::accountId() !== '',
        ];
        if (isset($options['body'])) {
    $requestSummary['body'] = $this->redact($options['body']);
}
        $logId = $this->logs->create([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => $operation,
            'direction' => 'outbound',
            'correlation_id' => $correlationId,
            'idempotency_key' => isset($options['idempotency_key']) ? (string) $options['idempotency_key'] : '',
            'reservation_id' => isset($options['reservation_id']) ? \max(0, (int) $options['reservation_id']) : 0,
            'external_id' => isset($options['external_id']) ? (string) $options['external_id'] : '',
            'request_summary' => $requestSummary,
        ]);

        $errors = ClockConfig::configurationErrors();

        if (!empty($errors)) {
            $response = new ClockApiResponse(0, '', null, 'config_missing', \implode(' ', $errors), $this->durationMs($started));
            $this->completeLog($logId, $response);

            return $response;
        }

        $args = [
            'method' => $method,
            'timeout' => ClockConfig::timeoutSeconds(),
            'headers' => $this->headers($correlationId),
        ];

        if (isset($options['body'])) {
            $args['body'] = \function_exists('wp_json_encode')
                ? \wp_json_encode($options['body'])
                : \json_encode($options['body']);
        }

        if (!\function_exists('wp_remote_request')) {
            $response = new ClockApiResponse(0, '', null, 'wordpress_http_missing', 'WordPress HTTP API is unavailable.', $this->durationMs($started));
            $this->completeLog($logId, $response);

            return $response;
        }

        $raw = $this->transport->request($url, $args);
        $duration = $this->durationMs($started);

        if (\is_wp_error($raw)) {
            $response = new ClockApiResponse(0, '', null, $raw->get_error_code(), $raw->get_error_message(), $duration);
            $this->completeLog($logId, $response);

            return $response;
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

        $this->completeLog($logId, $response);

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

    private function completeLog(int $logId, ClockApiResponse $response): void
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
        ];

        if (!$response->isSuccess()) {
            $responseSummary['body'] = $this->bodyForLog($response->getBody());
        }

        $this->logs->complete($logId, [
            'http_status' => $response->getStatusCode(),
            'success' => $response->isSuccess() ? 1 : 0,
            'error_code' => $response->getErrorCode(),
            'error_message' => $response->getErrorMessage(),
            'duration_ms' => $response->getDurationMs(),
            'response_summary' => $responseSummary,
        ]);
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

        $body = (string) \preg_replace('/("(?:authorization|token|secret|password|api[_-]?key|key)"\s*:\s*")[^"]+(")/i', '$1[redacted]$2', $body);

        return \substr($body, 0, 1000);
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

        return (string) \preg_replace('/("(?:authorization|token|secret|password|api[_-]?key|key)"\s*:\s*")[^"]+(")/i', '$1[redacted]$2', $body);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function redact($value)
    {
        if (!\is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $keyString = \is_string($key) ? \strtolower($key) : '';
            $redacted[$key] = \preg_match('/(authorization|token|secret|password|api[_-]?key|key)$/i', $keyString) === 1
                ? '[redacted]'
                : $this->redact($item);
        }

        return $redacted;
    }

    private function redactUrl(string $url): string
    {
        return (string) \preg_replace('/([?&](?:api[_-]?key|key|token|password|secret)=)[^&]+/i', '$1[redacted]', $url);
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
        if (\function_exists('wp_generate_uuid4')) {
            return \wp_generate_uuid4();
        }

        return \bin2hex(\random_bytes(16));
    }

    private function durationMs(float $started): int
    {
        return \max(0, (int) \round((\microtime(true) - $started) * 1000));
    }
}
