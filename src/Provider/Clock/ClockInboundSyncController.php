<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;

final class ClockInboundSyncController
{
    public static function registerHooks(): void
    {
        \add_action('rest_api_init', [self::class, 'registerRestRoutes']);
    }

    public static function registerRestRoutes(): void
    {
        \register_rest_route(
            'must-hotel-booking/v1',
            '/clock/webhook',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'handleWebhookRequest'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function handleWebhookRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $started = \microtime(true);
        $logs = new ProviderRequestLogRepository();
        $body = (string) $request->get_body();
        $authResult = self::validateWebhookRequest($request, $body);

        if (empty($authResult['success'])) {
            $logId = $logs->create([
                'provider' => ProviderManager::CLOCK_MODE,
                'operation' => 'clock.webhook_auth',
                'direction' => 'inbound',
                'request_summary' => [
                    'body_bytes' => \strlen($body),
                    'has_signature' => self::headerValue($request, ['x-mhb-clock-signature', 'x-clock-signature', 'x-clockpms-signature']) !== '',
                    'has_token' => self::headerValue($request, ['x-mhb-clock-token', 'authorization']) !== '',
                ],
            ]);
            $logs->complete($logId, [
                'success' => 0,
                'error_code' => (string) ($authResult['code'] ?? 'auth_failed'),
                'error_message' => (string) ($authResult['message'] ?? \__('Clock webhook authentication failed.', 'must-hotel-booking')),
                'duration_ms' => self::durationMs($started),
            ]);

            return new \WP_REST_Response([
                'success' => false,
                'message' => (string) ($authResult['message'] ?? \__('Clock webhook authentication failed.', 'must-hotel-booking')),
            ], (int) ($authResult['status'] ?? 403));
        }

        $payload = \json_decode($body, true);

        if (!\is_array($payload)) {
            $logId = self::createInboundLog($logs, [], self::bodyHashKey($body), $body);
            $logs->complete($logId, [
                'success' => 0,
                'error_code' => 'invalid_json',
                'error_message' => \__('Clock webhook payload must be valid JSON.', 'must-hotel-booking'),
                'duration_ms' => self::durationMs($started),
            ]);

            return new \WP_REST_Response([
                'success' => false,
                'message' => \__('Clock webhook payload must be valid JSON.', 'must-hotel-booking'),
            ], 400);
        }

        $eventId = self::eventId($payload);
        $dedupeKey = $eventId !== '' ? 'event-' . $eventId : self::bodyHashKey($body);

        if ($logs->hasSuccessfulLog(ProviderManager::CLOCK_MODE, 'clock.webhook', 'inbound', $dedupeKey)) {
            $logId = self::createInboundLog($logs, $payload, $dedupeKey, $body);
            $logs->complete($logId, [
                'success' => 1,
                'duration_ms' => self::durationMs($started),
                'response_summary' => [
                    'duplicate' => true,
                ],
            ]);

            return new \WP_REST_Response([
                'success' => true,
                'duplicate' => true,
            ], 200);
        }

        $logId = self::createInboundLog($logs, $payload, $dedupeKey, $body);
        $result = (new ClockInboundSyncService())->processInboundPayload($payload, $eventId);
        $success = !empty($result['success']);
        $unsupported = !empty($result['unsupported']);
        $status = isset($result['status']) ? (int) $result['status'] : ($success ? 200 : 422);

        $logs->complete($logId, [
            'success' => $success ? 1 : 0,
            'error_code' => $success ? '' : ($unsupported ? 'unsupported_event' : 'inbound_sync_failed'),
            'error_message' => $success ? '' : (string) ($result['message'] ?? \__('Clock inbound sync failed.', 'must-hotel-booking')),
            'duration_ms' => self::durationMs($started),
            'response_summary' => [
                'success' => $success,
                'unsupported' => $unsupported,
                'reservation_ids' => isset($result['reservation_ids']) && \is_array($result['reservation_ids']) ? \array_map('intval', $result['reservation_ids']) : [],
                'updated_count' => (int) ($result['updated_count'] ?? 0),
                'status' => $status,
            ],
        ]);

        return new \WP_REST_Response([
            'success' => $success,
            'unsupported' => $unsupported,
            'message' => (string) ($result['message'] ?? ''),
            'reservation_ids' => isset($result['reservation_ids']) && \is_array($result['reservation_ids']) ? \array_map('intval', $result['reservation_ids']) : [],
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validateWebhookRequest(\WP_REST_Request $request, string $body): array
    {
        if (!ClockConfig::isEnabled()) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'clock_disabled',
                'message' => \__('Clock integration is disabled.', 'must-hotel-booking'),
            ];
        }

        $secret = ClockConfig::webhookSecret();

        if ($secret === '') {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'webhook_secret_missing',
                'message' => \__('Clock webhook secret is not configured.', 'must-hotel-booking'),
            ];
        }

        $token = self::headerValue($request, ['x-mhb-clock-token']);
        $authorization = self::headerValue($request, ['authorization']);

        if (\stripos($authorization, 'Bearer ') === 0) {
            $token = \trim(\substr($authorization, 7));
        }

        if ($token !== '' && \hash_equals($secret, $token)) {
            return ['success' => true];
        }

        $signature = self::headerValue($request, ['x-mhb-clock-signature', 'x-clock-signature', 'x-clockpms-signature']);

        if ($signature !== '' && self::verifySignature($body, $signature, $secret)) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'status' => 403,
            'code' => 'signature_invalid',
            'message' => \__('Clock webhook signature or token is invalid.', 'must-hotel-booking'),
        ];
    }

    private static function verifySignature(string $body, string $signature, string $secret): bool
    {
        $expected = \hash_hmac('sha256', $body, $secret);
        $parts = \array_map('trim', \explode(',', $signature));

        foreach ($parts as $part) {
            if (\stripos($part, 'sha256=') === 0) {
                $part = \substr($part, 7);
            } elseif (\strpos($part, '=') !== false) {
                $part = (string) \substr($part, \strpos($part, '=') + 1);
            }

            if ($part !== '' && \hash_equals($expected, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function createInboundLog(ProviderRequestLogRepository $logs, array $payload, string $dedupeKey, string $body): int
    {
        return $logs->create([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => 'clock.webhook',
            'direction' => 'inbound',
            'correlation_id' => self::eventId($payload),
            'idempotency_key' => $dedupeKey,
            'external_id' => self::externalId($payload),
            'request_summary' => [
                'event_type' => self::eventType($payload),
                'body_bytes' => \strlen($body),
                'top_level_keys' => \array_slice(\array_keys($payload), 0, 20),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function eventId(array $payload): string
    {
        foreach (['event_id', 'webhook_id', 'notification_id'] as $key) {
            if (isset($payload[$key]) && \is_scalar($payload[$key])) {
                return \sanitize_text_field((string) $payload[$key]);
            }
        }

        if (isset($payload['event']) && \is_array($payload['event']) && isset($payload['event']['id']) && \is_scalar($payload['event']['id'])) {
            return \sanitize_text_field((string) $payload['event']['id']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function eventType(array $payload): string
    {
        foreach (['event_type', 'type', 'topic', 'action'] as $key) {
            if (isset($payload[$key]) && \is_scalar($payload[$key])) {
                return \sanitize_key((string) $payload[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function externalId(array $payload): string
    {
        foreach (['reservation', 'booking', 'object', 'data', 'result'] as $key) {
            if (isset($payload[$key]) && \is_array($payload[$key])) {
                $externalId = self::externalId($payload[$key]);

                if ($externalId !== '') {
                    return $externalId;
                }
            }
        }

        foreach (['provider_reservation_id', 'reservation_id', 'provider_booking_id', 'booking_id', 'id', 'confirmation_number', 'reference'] as $key) {
            if (isset($payload[$key]) && \is_scalar($payload[$key])) {
                return \sanitize_text_field((string) $payload[$key]);
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $names
     */
    private static function headerValue(\WP_REST_Request $request, array $names): string
    {
        foreach ($names as $name) {
            $value = (string) $request->get_header($name);

            if ($value !== '') {
                return \trim($value);
            }
        }

        return '';
    }

    private static function bodyHashKey(string $body): string
    {
        return 'body-' . \substr(\hash('sha256', $body), 0, 64);
    }

    private static function durationMs(float $started): int
    {
        return \max(0, (int) \round((\microtime(true) - $started) * 1000));
    }
}
