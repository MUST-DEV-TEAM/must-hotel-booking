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

        $sns = self::snsEnvelope($payload);
        $authResult = self::validateWebhookRequest($request, $body, $payload, $sns);

        if (empty($authResult['success'])) {
            $logId = $logs->create([
                'provider' => ProviderManager::CLOCK_MODE,
                'operation' => 'clock.webhook_auth',
                'direction' => 'inbound',
                'request_summary' => [
                    'body_bytes' => \strlen($body),
                    'payload_type' => self::payloadTransportType($payload, $sns),
                    'sns_type' => (string) ($sns['type'] ?? ''),
                    'sns_message_id_present' => (string) ($sns['message_id'] ?? '') !== '',
                    'has_legacy_signature' => self::headerValue($request, ['x-mhb-clock-signature', 'x-clock-signature', 'x-clockpms-signature']) !== '',
                    'has_authorization' => self::headerValue($request, ['authorization']) !== '',
                    'has_token' => self::headerValue($request, ['x-mhb-clock-token']) !== '',
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

        if (\class_exists(ClockSyncScheduler::class)) {
            ClockSyncScheduler::recordWebhookReceived('success');
        }

        if (!empty($sns)) {
            $snsType = (string) ($sns['type'] ?? '');
            if ($snsType === 'SubscriptionConfirmation') {
                $dedupeKey = self::dedupeKey($payload, $body, $sns);
                if (!$logs->acquireIdempotencyLock($dedupeKey)) {
                    return self::retryLaterResponse();
                }

                try {
                    if ($logs->hasSuccessfulLog(ProviderManager::CLOCK_MODE, 'clock.webhook', 'inbound', $dedupeKey)) {
                        return new \WP_REST_Response([
                            'success' => true,
                            'subscription_confirmed' => true,
                            'duplicate' => true,
                        ], 200);
                    }

                    $confirmResult = self::confirmSnsSubscription($sns);
                    $logId = self::createInboundLog($logs, $payload, $dedupeKey, $body, $sns);
                    $success = !empty($confirmResult['success']);
                    $logs->complete($logId, [
                        'success' => $success ? 1 : 0,
                        'error_code' => $success ? '' : (string) ($confirmResult['code'] ?? 'subscription_confirmation_failed'),
                        'error_message' => $success ? '' : (string) ($confirmResult['message'] ?? \__('Clock SNS subscription confirmation failed.', 'must-hotel-booking')),
                        'duration_ms' => self::durationMs($started),
                        'response_summary' => [
                            'sns_type' => $snsType,
                            'subscription_confirmed' => $success,
                            'status' => (int) ($confirmResult['status'] ?? ($success ? 200 : 403)),
                        ],
                    ]);

                    return new \WP_REST_Response([
                        'success' => $success,
                        'subscription_confirmed' => $success,
                        'message' => (string) ($confirmResult['message'] ?? ''),
                    ], (int) ($confirmResult['status'] ?? ($success ? 200 : 403)));
                } finally {
                    $logs->releaseIdempotencyLock($dedupeKey);
                }
            }

            if ($snsType !== 'Notification') {
                $dedupeKey = self::dedupeKey($payload, $body, $sns);
                $logId = self::createInboundLog($logs, $payload, $dedupeKey, $body, $sns);
                $logs->complete($logId, [
                    'success' => 0,
                    'error_code' => 'sns_type_unsupported',
                    'error_message' => \__('Unsupported Amazon SNS message type.', 'must-hotel-booking'),
                    'duration_ms' => self::durationMs($started),
                ]);

                return new \WP_REST_Response([
                    'success' => false,
                    'message' => \__('Unsupported Amazon SNS message type.', 'must-hotel-booking'),
                ], 400);
            }
        }

        if (!empty($sns)) {
            $decodedNotification = self::payloadFromSnsNotification($sns);
            if (empty($decodedNotification['success'])) {
                $dedupeKey = self::dedupeKey($payload, $body, $sns);
                $logId = self::createInboundLog($logs, $payload, $dedupeKey, $body, $sns);
                $logs->complete($logId, [
                    'success' => 0,
                    'error_code' => 'sns_message_json_invalid',
                    'error_message' => (string) ($decodedNotification['message'] ?? \__('Amazon SNS Message must contain valid JSON.', 'must-hotel-booking')),
                    'duration_ms' => self::durationMs($started),
                ]);

                return new \WP_REST_Response([
                    'success' => false,
                    'message' => (string) ($decodedNotification['message'] ?? \__('Amazon SNS Message must contain valid JSON.', 'must-hotel-booking')),
                ], 400);
            }
            $processPayload = (array) ($decodedNotification['payload'] ?? []);
        } else {
            $processPayload = $payload;
        }
        $eventId = self::eventId($processPayload, $sns);
        $dedupeKey = self::dedupeKey($processPayload, $body, $sns);

        if (!$logs->acquireIdempotencyLock($dedupeKey)) {
            return self::retryLaterResponse();
        }

        try {
            if ($logs->hasSuccessfulLog(ProviderManager::CLOCK_MODE, 'clock.webhook', 'inbound', $dedupeKey)) {
                $logId = self::createInboundLog($logs, $processPayload, $dedupeKey, $body, $sns);
                $logs->complete($logId, [
                    'success' => 1,
                    'duration_ms' => self::durationMs($started),
                    'response_summary' => [
                        'duplicate' => true,
                        'payload_type' => self::payloadTransportType($payload, $sns),
                    ],
                ]);

                return new \WP_REST_Response([
                    'success' => true,
                    'duplicate' => true,
                ], 200);
            }

            $logId = self::createInboundLog($logs, $processPayload, $dedupeKey, $body, $sns);
            $result = (new ClockInboundSyncService())->processInboundPayload($processPayload, $eventId);
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
                    'retryable' => !empty($result['retryable']),
                    'reservation_ids' => isset($result['reservation_ids']) && \is_array($result['reservation_ids']) ? \array_map('intval', $result['reservation_ids']) : [],
                    'updated_count' => (int) ($result['updated_count'] ?? 0),
                    'status' => $status,
                ],
            ]);

            return new \WP_REST_Response([
                'success' => $success,
                'unsupported' => $unsupported,
                'retryable' => !empty($result['retryable']),
                'message' => (string) ($result['message'] ?? ''),
                'reservation_ids' => isset($result['reservation_ids']) && \is_array($result['reservation_ids']) ? \array_map('intval', $result['reservation_ids']) : [],
            ], $status);
        } finally {
            $logs->releaseIdempotencyLock($dedupeKey);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function validateWebhookRequest(\WP_REST_Request $request, string $body, array $payload, array $sns = []): array
    {
        if (!ClockConfig::isEnabled()) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'clock_disabled',
                'message' => \__('Clock integration is disabled.', 'must-hotel-booking'),
            ];
        }

        $basicUserConfigured = ClockConfig::webhookBasicUsername() !== '';
        $basicPasswordConfigured = ClockConfig::webhookBasicPassword() !== '';
        if ($basicUserConfigured !== $basicPasswordConfigured) {
            return [
                'success' => false,
                'status' => 503,
                'code' => 'basic_config_incomplete',
                'message' => \__('Clock webhook Basic authentication requires both a username and password.', 'must-hotel-booking'),
            ];
        }
        $basicConfigured = $basicUserConfigured && $basicPasswordConfigured;
        $basicResult = self::validateBasicAuth($request);
        if ($basicConfigured && empty($basicResult['success'])) {
            return $basicResult;
        }

        if (!empty($sns)) {
            $expectedTopicArn = ClockConfig::webhookSnsTopicArn();
            $receivedTopicArn = (string) ($sns['topic_arn'] ?? '');
            if ($expectedTopicArn === '' && !$basicConfigured) {
                return [
                    'success' => false,
                    'status' => 503,
                    'code' => 'sns_source_unpinned',
                    'message' => \__('Clock SNS webhook requires a pinned Topic ARN or complete HTTP Basic authentication.', 'must-hotel-booking'),
                ];
            }
            if ($expectedTopicArn !== '' && !\hash_equals($expectedTopicArn, $receivedTopicArn)) {
                return [
                    'success' => false,
                    'status' => 403,
                    'code' => 'sns_topic_mismatch',
                    'message' => \__('Amazon SNS TopicArn does not match the configured Clock PUSH topic.', 'must-hotel-booking'),
                ];
            }
            $snsResult = self::verifySnsEnvelope($sns);
            if (empty($snsResult['success'])) {
                return $snsResult;
            }

            return ['success' => true, 'auth_method' => !empty($basicResult['success']) ? 'basic_sns' : 'sns'];
        }

        if (!empty($basicResult['success'])) {
            return ['success' => true, 'auth_method' => 'basic'];
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

    /**
     * @return array<string, mixed>
     */
    private static function validateBasicAuth(\WP_REST_Request $request): array
    {
        $expectedUser = ClockConfig::webhookBasicUsername();
        $expectedPassword = ClockConfig::webhookBasicPassword();

        if ($expectedUser === '' && $expectedPassword === '') {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'basic_not_configured',
                'message' => \__('Clock webhook Basic authentication is not configured.', 'must-hotel-booking'),
            ];
        }

        $authorization = self::headerValue($request, ['authorization']);
        if (\stripos($authorization, 'Basic ') !== 0) {
            return [
                'success' => false,
                'status' => 401,
                'code' => 'basic_missing',
                'message' => \__('Clock webhook Basic authentication is missing.', 'must-hotel-booking'),
            ];
        }

        $decoded = \base64_decode(\trim(\substr($authorization, 6)), true);
        if (!\is_string($decoded) || \strpos($decoded, ':') === false) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'basic_invalid',
                'message' => \__('Clock webhook Basic authentication is invalid.', 'must-hotel-booking'),
            ];
        }

        [$username, $password] = \explode(':', $decoded, 2);
        $userOk = $expectedUser !== '' && \hash_equals($expectedUser, $username);
        $passwordOk = $expectedPassword !== '' && \hash_equals($expectedPassword, $password);

        if ($userOk && $passwordOk) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'status' => 403,
            'code' => 'basic_invalid',
            'message' => \__('Clock webhook Basic authentication is invalid.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function snsEnvelope(array $payload): array
    {
        $type = isset($payload['Type']) && \is_scalar($payload['Type']) ? \trim((string) $payload['Type']) : '';
        if (!\in_array($type, ['Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation'], true)) {
            return [];
        }

        return [
            'type' => $type,
            'message_id' => isset($payload['MessageId']) && \is_scalar($payload['MessageId']) ? \sanitize_text_field((string) $payload['MessageId']) : '',
            'topic_arn' => isset($payload['TopicArn']) && \is_scalar($payload['TopicArn']) ? \sanitize_text_field((string) $payload['TopicArn']) : '',
            'subject' => isset($payload['Subject']) && \is_scalar($payload['Subject']) ? \sanitize_text_field((string) $payload['Subject']) : '',
            'message' => isset($payload['Message']) && \is_scalar($payload['Message']) ? (string) $payload['Message'] : '',
            'timestamp' => isset($payload['Timestamp']) && \is_scalar($payload['Timestamp']) ? \sanitize_text_field((string) $payload['Timestamp']) : '',
            'signature_version' => isset($payload['SignatureVersion']) && \is_scalar($payload['SignatureVersion']) ? \trim((string) $payload['SignatureVersion']) : '',
            'signature' => isset($payload['Signature']) && \is_scalar($payload['Signature']) ? \trim((string) $payload['Signature']) : '',
            'signing_cert_url' => isset($payload['SigningCertURL']) && \is_scalar($payload['SigningCertURL']) ? \trim((string) $payload['SigningCertURL']) : '',
            'subscribe_url' => isset($payload['SubscribeURL']) && \is_scalar($payload['SubscribeURL']) ? \trim((string) $payload['SubscribeURL']) : '',
            'token' => isset($payload['Token']) && \is_scalar($payload['Token']) ? \sanitize_text_field((string) $payload['Token']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $sns
     * @return array<string, mixed>
     */
    private static function verifySnsEnvelope(array $sns): array
    {
        $certUrl = (string) ($sns['signing_cert_url'] ?? '');
        if (!self::isSafeSnsUrl($certUrl, false)) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'sns_cert_url_invalid',
                'message' => \__('Amazon SNS signing certificate URL is invalid.', 'must-hotel-booking'),
            ];
        }

        $signature = (string) ($sns['signature'] ?? '');
        $signatureVersion = (string) ($sns['signature_version'] ?? '');
        if ($signature === '' || !\in_array($signatureVersion, ['1', '2'], true)) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'sns_signature_missing',
                'message' => \__('Amazon SNS signature is missing or unsupported.', 'must-hotel-booking'),
            ];
        }

        $certificate = self::fetchSnsCertificate($certUrl);
        if ($certificate === '') {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'sns_certificate_unavailable',
                'message' => \__('Amazon SNS signing certificate could not be fetched.', 'must-hotel-booking'),
            ];
        }

        $message = self::snsStringToSign($sns);
        if ($message === '') {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'sns_message_invalid',
                'message' => \__('Amazon SNS message is missing required signed fields.', 'must-hotel-booking'),
            ];
        }

        $publicKey = \openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'sns_certificate_invalid',
                'message' => \__('Amazon SNS signing certificate is invalid.', 'must-hotel-booking'),
            ];
        }

        $decodedSignature = \base64_decode($signature, true);
        if (!\is_string($decodedSignature)) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'sns_signature_invalid',
                'message' => \__('Amazon SNS signature is invalid.', 'must-hotel-booking'),
            ];
        }

        $algo = $signatureVersion === '2' ? \OPENSSL_ALGO_SHA256 : \OPENSSL_ALGO_SHA1;
        $verified = \openssl_verify($message, $decodedSignature, $publicKey, $algo);

        if ($verified === 1) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'status' => 403,
            'code' => 'sns_signature_invalid',
            'message' => \__('Amazon SNS signature is invalid.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<string, mixed> $sns
     * @return array<string, mixed>
     */
    private static function confirmSnsSubscription(array $sns): array
    {
        $url = (string) ($sns['subscribe_url'] ?? '');
        if (!self::isSafeSnsUrl($url, true)) {
            return [
                'success' => false,
                'status' => 403,
                'code' => 'sns_subscribe_url_invalid',
                'message' => \__('Amazon SNS SubscribeURL is invalid.', 'must-hotel-booking'),
            ];
        }

        $response = self::safeRemoteGet($url, 'sns_subscription_confirmation');
        $status = self::remoteResponseCode($response);

        if ($status >= 200 && $status < 300) {
            return [
                'success' => true,
                'status' => 200,
                'message' => '',
            ];
        }

        return [
            'success' => false,
            'status' => 502,
            'code' => 'sns_subscription_confirmation_failed',
            'message' => \__('Amazon SNS subscription confirmation request failed.', 'must-hotel-booking'),
        ];
    }

    private static function isSafeSnsUrl(string $url, bool $allowQuery): bool
    {
        $parts = \wp_parse_url($url);
        if (!\is_array($parts)) {
            $parts = \parse_url($url);
        }
        if (!\is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? \strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? \strtolower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }

        if (!$allowQuery && isset($parts['query'])) {
            return false;
        }

        if (\preg_match('/^sns[.-][a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $host) !== 1) {
            return false;
        }

        if (!$allowQuery && \preg_match('/^\/SimpleNotificationService-[A-Za-z0-9]+\.pem$/', $path) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $sns
     */
    private static function snsStringToSign(array $sns): string
    {
        $type = (string) ($sns['type'] ?? '');
        $fields = $type === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $values = [
            'Message' => (string) ($sns['message'] ?? ''),
            'MessageId' => (string) ($sns['message_id'] ?? ''),
            'Subject' => (string) ($sns['subject'] ?? ''),
            'SubscribeURL' => (string) ($sns['subscribe_url'] ?? ''),
            'Timestamp' => (string) ($sns['timestamp'] ?? ''),
            'Token' => (string) ($sns['token'] ?? ''),
            'TopicArn' => (string) ($sns['topic_arn'] ?? ''),
            'Type' => $type,
        ];
        $string = '';

        foreach ($fields as $field) {
            if ($field === 'Subject' && $values[$field] === '') {
                continue;
            }
            if ($values[$field] === '') {
                return '';
            }
            $string .= $field . "\n" . $values[$field] . "\n";
        }

        return $string;
    }

    private static function fetchSnsCertificate(string $url): string
    {
        $response = self::safeRemoteGet($url, 'sns_signing_certificate');
        $status = self::remoteResponseCode($response);
        if ($status < 200 || $status >= 300) {
            return '';
        }

        return self::remoteResponseBody($response);
    }

    /**
     * @return mixed
     */
    private static function safeRemoteGet(string $url, string $purpose)
    {
        $filtered = \apply_filters('must_hotel_booking/clock_sns_http_get', null, $url, $purpose);
        if ($filtered !== null) {
            return $filtered;
        }

        return \wp_safe_remote_get($url, [
            'timeout' => 10,
            'redirection' => 0,
            'user-agent' => 'MUST Hotel Booking Clock SNS verifier',
        ]);
    }

    /**
     * @param mixed $response
     */
    private static function remoteResponseCode($response): int
    {
        if (\is_wp_error($response)) {
            return 0;
        }
        if (\function_exists('wp_remote_retrieve_response_code')) {
            return (int) \wp_remote_retrieve_response_code($response);
        }
        return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    }

    /**
     * @param mixed $response
     */
    private static function remoteResponseBody($response): string
    {
        if (\is_wp_error($response)) {
            return '';
        }
        if (\function_exists('wp_remote_retrieve_body')) {
            return (string) \wp_remote_retrieve_body($response);
        }
        return isset($response['body']) && \is_string($response['body']) ? $response['body'] : '';
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
    private static function createInboundLog(ProviderRequestLogRepository $logs, array $payload, string $dedupeKey, string $body, array $sns = []): int
    {
        return $logs->create([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => 'clock.webhook',
            'direction' => 'inbound',
            'correlation_id' => self::eventId($payload, $sns),
            'idempotency_key' => $dedupeKey,
            'external_id' => self::externalId($payload),
            'request_summary' => [
                'event_type' => self::eventType($payload),
                'body_bytes' => \strlen($body),
                'payload_type' => self::payloadTransportType($payload, $sns),
                'sns_type' => (string) ($sns['type'] ?? ''),
                'sns_message_id_present' => (string) ($sns['message_id'] ?? '') !== '',
                'top_level_keys' => \array_slice(\array_keys($payload), 0, 20),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function eventId(array $payload, array $sns = []): string
    {
        if (!empty($sns) && (string) ($sns['message_id'] ?? '') !== '') {
            return (string) $sns['message_id'];
        }

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
     * @param array<string, mixed> $sns
     * @return array<string, mixed>
     */
    private static function payloadFromSnsNotification(array $sns): array
    {
        $message = (string) ($sns['message'] ?? '');
        $decoded = \json_decode($message, true);
        if (!\is_array($decoded) || $decoded === []) {
            return [
                'success' => false,
                'message' => \__('Amazon SNS Message must contain a non-empty JSON object.', 'must-hotel-booking'),
            ];
        }
        $event = $decoded;
        $subject = (string) ($sns['subject'] ?? '');
        if ($subject !== '') {
            $event['Subject'] = $subject;
            $event['event_type'] = $subject;
        }
        $event['event_id'] = (string) ($sns['message_id'] ?? '');
        $event['_sns'] = [
            'type' => (string) ($sns['type'] ?? ''),
            'message_id' => (string) ($sns['message_id'] ?? ''),
            'topic_arn_present' => (string) ($sns['topic_arn'] ?? '') !== '',
        ];
        return [
            'success' => true,
            'payload' => $event,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $sns
     */
    private static function dedupeKey(array $payload, string $body, array $sns = []): string
    {
        $eventId = self::eventId($payload, $sns);
        $type = !empty($sns) ? \sanitize_key((string) ($sns['type'] ?? 'sns')) : 'legacy';
        return $eventId !== '' ? $type . '-event-' . $eventId : $type . '-' . self::bodyHashKey($body);
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
     * @param array<string, mixed> $sns
     */
    private static function payloadTransportType(array $payload, array $sns = []): string
    {
        unset($payload);
        return !empty($sns) ? 'amazon_sns' : 'legacy_json';
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

            if ($name === 'authorization') {
                foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $serverKey) {
                    if (isset($_SERVER[$serverKey]) && \is_scalar($_SERVER[$serverKey])) {
                        $value = \trim((string) \wp_unslash($_SERVER[$serverKey]));
                        if ($value !== '') {
                            return $value;
                        }
                    }
                }
                if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
                    return 'Basic ' . \base64_encode(
                        (string) \wp_unslash($_SERVER['PHP_AUTH_USER'])
                        . ':'
                        . (string) \wp_unslash($_SERVER['PHP_AUTH_PW'])
                    );
                }
            }
        }

        return '';
    }

    private static function retryLaterResponse(): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => false,
            'retryable' => true,
            'message' => \__('Another delivery of this Clock event is already being processed. Retry later.', 'must-hotel-booking'),
        ], 503);
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
