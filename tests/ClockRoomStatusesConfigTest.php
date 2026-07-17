<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function __($text, $domain = null): string { unset($domain); return (string) $text; }
    function sanitize_key($value): string { return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function wp_parse_url(string $url, int $component = -1) { return \parse_url($url, $component); }
    function esc_url_raw(string $url): string { return \filter_var($url, FILTER_SANITIZE_URL) ?: ''; }
}

namespace MustHotelBooking\Core {
    final class MustBookingConfig
    {
        /** @var array<string, mixed> */
        public static $settings = [];

        public static function get_clock_settings(): array { return self::$settings; }
        public static function is_clock_enabled(): bool { return true; }
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Clock/ClockEndpointResolver.php';
    require __DIR__ . '/../src/Provider/Clock/ClockConfig.php';

    use MustHotelBooking\Core\MustBookingConfig;
    use MustHotelBooking\Provider\Clock\ClockConfig;

    $failures = [];
    if (!\method_exists(ClockConfig::class, 'roomStatusesPath')) {
        $failures[] = 'ClockConfig must expose the room_statuses path.';
    } else {
        MustBookingConfig::$settings = [];
        if (ClockConfig::roomStatusesPath() !== '/room_statuses') {
            $failures[] = 'The room_statuses path must default to /room_statuses.';
        }

        MustBookingConfig::$settings = ['clock_room_statuses_path' => 'custom/statuses?unsafe=1'];
        if (ClockConfig::roomStatusesPath() !== '/custom/statuses?unsafe=1') {
            $failures[] = 'The configured room_statuses path must use normal Clock path normalization.';
        }

        MustBookingConfig::$settings = [
            'clock_pms_api_enabled' => 1,
            'clock_base_api_enabled' => 0,
            'clock_pms_api_url' => 'https://proxy.example/pms/123/456',
            'clock_api_user' => 'user',
            'clock_api_key' => 'key',
        ];
        if (ClockConfig::configurationErrors() !== []) {
            $failures[] = 'A valid explicit PMS API base must satisfy only PMS URL configuration.';
        }
        if (ClockConfig::baseApiConfigurationErrors() === []) {
            $failures[] = 'Base API validation must remain independent when Base API is disabled or unconfigured.';
        }

        MustBookingConfig::$settings['clock_base_api_enabled'] = 1;
        MustBookingConfig::$settings['clock_base_api_url'] = 'https://proxy.example/base/123/456/%252e%252e/admin';
        if (ClockConfig::baseApiUrl() !== '') {
            $failures[] = 'Configured Clock API bases must reject encoded path traversal.';
        }

        MustBookingConfig::$settings['clock_webhook_sns_topic_arn'] = 'arn:aws:sns:eu-central-1:123456789012:clock-push';
        if (ClockConfig::webhookSnsTopicArn() !== 'arn:aws:sns:eu-central-1:123456789012:clock-push') {
            $failures[] = 'A valid Clock SNS Topic ARN must be retained for source pinning.';
        }
        MustBookingConfig::$settings['clock_webhook_sns_topic_arn'] = 'https://example.test/not-an-arn';
        if (ClockConfig::webhookSnsTopicArn() !== '') {
            $failures[] = 'Invalid Clock SNS Topic ARN configuration must fail closed.';
        }
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock room statuses config tests passed.\n";
}
