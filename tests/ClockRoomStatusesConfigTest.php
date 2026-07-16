<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function __($text, $domain = null): string { unset($domain); return (string) $text; }
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
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock room statuses config tests passed.\n";
}
