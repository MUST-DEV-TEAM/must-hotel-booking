<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        \http_response_code(403);
        echo 'CLI only.' . PHP_EOL;
        exit(1);
    }

    final class WP_REST_Request
    {
        /** @var array<string, string> */
        private $headers;
        /** @var string */
        private $body;

        /** @param array<string, string> $headers */
        public function __construct(string $body, array $headers = [])
        {
            $this->body = $body;
            $this->headers = [];
            foreach ($headers as $name => $value) {
                $this->headers[\strtolower($name)] = $value;
            }
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function get_header(string $name): string
        {
            return $this->headers[\strtolower($name)] ?? '';
        }
    }

    final class WP_REST_Response
    {
        /** @var array<string, mixed> */
        private $data;
        /** @var int */
        private $status;

        /** @param array<string, mixed> $data */
        public function __construct(array $data, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        /** @return array<string, mixed> */
        public function get_data(): array
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }

    function __(string $text, ?string $domain = null): string
    {
        unset($domain);
        return $text;
    }

    function sanitize_text_field(string $value): string
    {
        return \trim(\strip_tags($value));
    }

    function sanitize_key(string $value): string
    {
        return \strtolower((string) \preg_replace('/[^a-zA-Z0-9_\-]/', '', $value));
    }

    function wp_json_encode($value, int $flags = 0): string
    {
        return (string) \json_encode($value, $flags);
    }

    function wp_parse_url(string $url)
    {
        return \parse_url($url);
    }

    function apply_filters(string $hook, $value, ...$args)
    {
        if ($hook === 'must_hotel_booking/clock_sns_http_get' && isset($GLOBALS['mhb_clock_sns_http_get']) && \is_callable($GLOBALS['mhb_clock_sns_http_get'])) {
            return $GLOBALS['mhb_clock_sns_http_get']($value, ...$args);
        }
        return $value;
    }

    function is_wp_error($value): bool
    {
        return false;
    }

    function wp_safe_remote_get(string $url, array $args = []): array
    {
        unset($url, $args);
        return ['response' => ['code' => 500], 'body' => ''];
    }

    function wp_remote_retrieve_response_code($response): int
    {
        return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
    }

    function wp_remote_retrieve_body($response): string
    {
        return isset($response['body']) && \is_string($response['body']) ? $response['body'] : '';
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        /** @var bool */
        public static $enabled = true;
        /** @var string */
        public static $legacySecret = '';
        /** @var string */
        public static $basicUsername = '';
        /** @var string */
        public static $basicPassword = '';

        public static function isEnabled(): bool
        {
            return self::$enabled;
        }

        public static function webhookSecret(): string
        {
            return self::$legacySecret;
        }

        public static function webhookBasicUsername(): string
        {
            return self::$basicUsername;
        }

        public static function webhookBasicPassword(): string
        {
            return self::$basicPassword;
        }
    }

    final class ClockInboundSyncService
    {
        /** @var array<int, array<string, mixed>> */
        public static $processed = [];

        /** @param array<string, mixed> $payload */
        public function processInboundPayload(array $payload, string $eventId): array
        {
            self::$processed[] = [
                'event_id' => $eventId,
                'payload' => $payload,
            ];

            return [
                'success' => true,
                'reservation_ids' => [123],
                'updated_count' => 1,
                'message' => '',
            ];
        }
    }
}

namespace MustHotelBooking\Provider\Storage {
    final class ProviderRequestLogRepository
    {
        /** @var array<int, array<string, mixed>> */
        public static $logs = [];
        /** @var int */
        private static $nextId = 1;

        /** @param array<string, mixed> $data */
        public function create(array $data): int
        {
            $id = self::$nextId++;
            $data['id'] = $id;
            $data['success'] = 0;
            self::$logs[$id] = $data;
            return $id;
        }

        /** @param array<string, mixed> $data */
        public function complete(int $id, array $data): void
        {
            self::$logs[$id] = \array_merge(self::$logs[$id] ?? ['id' => $id], $data);
        }

        public function hasSuccessfulLog(string $provider, string $operation, string $direction, string $idempotencyKey): bool
        {
            foreach (self::$logs as $log) {
                if (($log['provider'] ?? '') === $provider
                    && ($log['operation'] ?? '') === $operation
                    && ($log['direction'] ?? '') === $direction
                    && ($log['idempotency_key'] ?? '') === $idempotencyKey
                    && !empty($log['success'])
                ) {
                    return true;
                }
            }

            return false;
        }

        public static function reset(): void
        {
            self::$logs = [];
            self::$nextId = 1;
        }
    }
}

namespace {
    use MustHotelBooking\Provider\Clock\ClockConfig;
    use MustHotelBooking\Provider\Clock\ClockInboundSyncController;
    use MustHotelBooking\Provider\Clock\ClockInboundSyncService;
    use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;

    require __DIR__ . '/../src/Provider/Clock/ClockInboundSyncController.php';

    final class ClockSnsReplayTest
    {
        /** @var string */
        private $privateKeyPem;
        /** @var string */
        private $certificatePem;
        /** @var array<int, string> */
        private $confirmedUrls = [];

        public function __construct()
        {
            $opensslConfig = $this->opensslConfig();
            $opensslArgs = [
                'private_key_bits' => 2048,
                'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            ];
            if ($opensslConfig !== '') {
                $opensslArgs['config'] = $opensslConfig;
            }
            $key = \openssl_pkey_new($opensslArgs);
            if ($key === false) {
                throw new \RuntimeException('Could not create test RSA key.');
            }

            $csrArgs = $opensslConfig !== '' ? ['config' => $opensslConfig] : [];
            $csr = \openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $key, $csrArgs);
            $cert = \openssl_csr_sign($csr, null, $key, 1, $csrArgs);
            \openssl_pkey_export($key, $privateKeyPem, null, $csrArgs);
            \openssl_x509_export($cert, $certificatePem);
            $this->privateKeyPem = (string) $privateKeyPem;
            $this->certificatePem = (string) $certificatePem;

            $GLOBALS['mhb_clock_sns_http_get'] = function ($value, string $url, string $purpose): array {
                unset($value);
                if ($purpose === 'sns_signing_certificate') {
                    return ['response' => ['code' => 200], 'body' => $this->certificatePem];
                }
                if ($purpose === 'sns_subscription_confirmation') {
                    $this->confirmedUrls[] = $url;
                    return ['response' => ['code' => 200], 'body' => 'ok'];
                }
                return ['response' => ['code' => 500], 'body' => ''];
            };
        }

        private function opensslConfig(): string
        {
            $candidates = [
                'C:\\Program Files (x86)\\Local\\resources\\extraResources\\lightning-services\\php-8.2.29+0\\bin\\win64\\extras\\ssl\\openssl.cnf',
                'C:\\Program Files (x86)\\Local\\resources\\extraResources\\lightning-services\\php-8.2.29+0\\bin\\win32\\extras\\ssl\\openssl.cnf',
                'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf',
                'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf',
            ];

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && \is_file($candidate)) {
                    return $candidate;
                }
            }

            return '';
        }

        public function run(): void
        {
            $this->validBasicAuth();
            $this->invalidBasicAuth();
            $this->validSnsSignature();
            $this->invalidSnsSignature();
            $this->subscriptionConfirmation();
            $this->notificationProcessing();
            $this->duplicateReplay();
        }

        private function validBasicAuth(): void
        {
            $this->reset();
            ClockConfig::$basicUsername = 'clock-user';
            ClockConfig::$basicPassword = 'clock-pass';
            $response = $this->send($this->signedNotification('basic-valid'), [
                'Authorization' => 'Basic ' . \base64_encode('clock-user:clock-pass'),
            ]);
            $this->assertSame(200, $response->get_status(), 'valid Basic auth accepts signed SNS notification');
            $this->assertSame(1, \count(ClockInboundSyncService::$processed), 'valid Basic auth processes one notification');
        }

        private function invalidBasicAuth(): void
        {
            $this->reset();
            ClockConfig::$basicUsername = 'clock-user';
            ClockConfig::$basicPassword = 'clock-pass';
            $response = $this->send($this->signedNotification('basic-invalid'), [
                'Authorization' => 'Basic ' . \base64_encode('clock-user:wrong-pass'),
            ]);
            $this->assertSame(403, $response->get_status(), 'invalid Basic auth is rejected');
            $this->assertSame(0, \count(ClockInboundSyncService::$processed), 'invalid Basic auth does not process notification');
        }

        private function validSnsSignature(): void
        {
            $this->reset();
            $response = $this->send($this->signedNotification('valid-signature'));
            $this->assertSame(200, $response->get_status(), 'valid SNS signature is accepted');
            $this->assertSame(1, \count(ClockInboundSyncService::$processed), 'valid SNS signature processes one notification');
        }

        private function invalidSnsSignature(): void
        {
            $this->reset();
            $payload = $this->signedNotification('invalid-signature');
            $payload['Message'] = \wp_json_encode(['booking_id' => 'tampered', 'status' => 'cancelled']);
            $response = $this->send($payload);
            $this->assertSame(403, $response->get_status(), 'invalid SNS signature is rejected');
            $this->assertSame(0, \count(ClockInboundSyncService::$processed), 'invalid SNS signature does not process notification');
        }

        private function subscriptionConfirmation(): void
        {
            $this->reset();
            $payload = $this->signedSubscriptionConfirmation('subscription-confirm');
            $response = $this->send($payload);
            $data = $response->get_data();
            $this->assertSame(200, $response->get_status(), 'valid subscription confirmation returns 200');
            $this->assertTrue(!empty($data['subscription_confirmed']), 'valid subscription confirmation is marked confirmed');
            $this->assertSame(1, \count($this->confirmedUrls), 'safe SubscribeURL is fetched once');
            $this->assertSame(0, \count(ClockInboundSyncService::$processed), 'subscription confirmation does not call event processor');
        }

        private function notificationProcessing(): void
        {
            $this->reset();
            $response = $this->send($this->signedNotification('process-normal', 'booking_status_changed', ['booking_id' => '36590001', 'status' => 'cancelled']));
            $processed = ClockInboundSyncService::$processed[0] ?? [];
            $payload = isset($processed['payload']) && \is_array($processed['payload']) ? $processed['payload'] : [];
            $this->assertSame(200, $response->get_status(), 'normal notification processing returns 200');
            $this->assertSame('process-normal', (string) ($processed['event_id'] ?? ''), 'SNS MessageId is used as event id');
            $this->assertSame('booking_status_changed', (string) ($payload['event_type'] ?? ''), 'SNS Subject becomes event type');
            $this->assertSame('36590001', (string) ($payload['booking_id'] ?? ''), 'SNS Message JSON becomes event payload');
            $this->assertTrue(isset($payload['_sns']) && \is_array($payload['_sns']), 'SNS metadata is retained in sanitized form');
        }

        private function duplicateReplay(): void
        {
            $this->reset();
            $payload = $this->signedNotification('duplicate-replay');
            $first = $this->send($payload);
            $second = $this->send($payload);
            $secondData = $second->get_data();
            $this->assertSame(200, $first->get_status(), 'first replay notification returns 200');
            $this->assertSame(200, $second->get_status(), 'duplicate replay returns 200');
            $this->assertTrue(!empty($secondData['duplicate']), 'duplicate replay response is marked duplicate');
            $this->assertSame(1, \count(ClockInboundSyncService::$processed), 'duplicate replay does not process twice');
        }

        /** @param array<string, mixed> $payload @param array<string, string> $headers */
        private function send(array $payload, array $headers = []): WP_REST_Response
        {
            return ClockInboundSyncController::handleWebhookRequest(new WP_REST_Request(\wp_json_encode($payload), $headers));
        }

        /** @param array<string, mixed> $message */
        private function signedNotification(string $messageId, string $subject = 'booking_status_changed', array $message = ['booking_id' => '36590000', 'status' => 'confirmed']): array
        {
            $payload = [
                'Type' => 'Notification',
                'MessageId' => $messageId,
                'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:clock-push',
                'Subject' => $subject,
                'Message' => \wp_json_encode($message),
                'Timestamp' => '2026-06-14T12:00:00.000Z',
                'SignatureVersion' => '2',
                'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-1234567890abcdef.pem',
            ];
            $payload['Signature'] = $this->sign($payload);
            return $payload;
        }

        private function signedSubscriptionConfirmation(string $messageId): array
        {
            $payload = [
                'Type' => 'SubscriptionConfirmation',
                'MessageId' => $messageId,
                'Token' => 'test-token',
                'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:clock-push',
                'Message' => 'You have chosen to subscribe to the topic.',
                'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn%3Aaws%3Asns%3Aus-east-1%3A123456789012%3Aclock-push&Token=test-token',
                'Timestamp' => '2026-06-14T12:00:00.000Z',
                'SignatureVersion' => '2',
                'SigningCertURL' => 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-1234567890abcdef.pem',
            ];
            $payload['Signature'] = $this->sign($payload);
            return $payload;
        }

        /** @param array<string, mixed> $payload */
        private function sign(array $payload): string
        {
            $stringToSign = $this->stringToSign($payload);
            \openssl_sign($stringToSign, $signature, $this->privateKeyPem, \OPENSSL_ALGO_SHA256);
            return \base64_encode($signature);
        }

        /** @param array<string, mixed> $payload */
        private function stringToSign(array $payload): string
        {
            $fields = (string) $payload['Type'] === 'Notification'
                ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
                : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
            $out = '';
            foreach ($fields as $field) {
                if ($field === 'Subject' && !isset($payload[$field])) {
                    continue;
                }
                $out .= $field . "\n" . (string) ($payload[$field] ?? '') . "\n";
            }
            return $out;
        }

        private function reset(): void
        {
            ClockConfig::$enabled = true;
            ClockConfig::$legacySecret = '';
            ClockConfig::$basicUsername = '';
            ClockConfig::$basicPassword = '';
            ClockInboundSyncService::$processed = [];
            ProviderRequestLogRepository::reset();
            $this->confirmedUrls = [];
        }

        private function assertSame($expected, $actual, string $message): void
        {
            if ($expected !== $actual) {
                throw new \RuntimeException($message . ' Expected ' . \var_export($expected, true) . ', got ' . \var_export($actual, true) . '.');
            }
        }

        private function assertTrue(bool $condition, string $message): void
        {
            if (!$condition) {
                throw new \RuntimeException($message);
            }
        }
    }

    (new ClockSnsReplayTest())->run();
    echo 'Clock inbound SNS replay tests passed.' . PHP_EOL;
}
