<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    $GLOBALS['mhb_clock_test_transients'] = [];

    function get_transient(string $key)
    {
        return $GLOBALS['mhb_clock_test_transients'][$key] ?? false;
    }

    function set_transient(string $key, $value, int $expiration): bool
    {
        unset($expiration);
        $GLOBALS['mhb_clock_test_transients'][$key] = $value;
        return true;
    }

    require __DIR__ . '/../src/Provider/Clock/ClockRateLimiter.php';

    $now = 1000.0;
    $sleeps = [];
    $limiter = new \MustHotelBooking\Provider\Clock\ClockRateLimiter(
        static function () use (&$now): float {
            return $now;
        },
        static function (int $microseconds) use (&$now, &$sleeps): void {
            $sleeps[] = $microseconds;
            $now += $microseconds / 1000000;
        },
        static function (int $minimum, int $maximum): int {
            unset($maximum);
            return $minimum;
        }
    );

    \MustHotelBooking\Provider\Clock\ClockRateLimiter::resetForTests();
    $limiter->acquire();
    $limiter->acquire();
    $limiter->acquire();
    $limiter->acquire();

    $failures = [];
    if (\count($sleeps) !== 3) {
        $failures[] = 'Four immediate Clock requests should require three throttle sleeps.';
    }
    foreach ($sleeps as $sleep) {
        if ($sleep < 250000) {
            $failures[] = 'Clock throttle must leave at least 250ms between requests.';
            break;
        }
    }

    if ($limiter->retryDelayMicroseconds(1, '2') !== 2000000) {
        $failures[] = 'Retry-After seconds must be honored exactly.';
    }
    if ($limiter->retryDelayMicroseconds(2, '') <= $limiter->retryDelayMicroseconds(1, '')) {
        $failures[] = '429 backoff must increase exponentially by attempt.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock rate limiter tests passed.\n";
}
