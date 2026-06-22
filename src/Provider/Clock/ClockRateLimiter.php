<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockRateLimiter
{
    private const MIN_INTERVAL_MICROSECONDS = 250000;
    private const SHARED_SLOT_TRANSIENT = 'must_hotel_booking_clock_next_request_at';

    /** @var callable */
    private $clock;

    /** @var callable */
    private $sleeper;

    /** @var callable */
    private $random;

    /** @var float */
    private static $lastRequestAt = 0.0;

    public function __construct(?callable $clock = null, ?callable $sleeper = null, ?callable $random = null)
    {
        $this->clock = $clock ?: static function (): float {
            return \microtime(true);
        };
        $this->sleeper = $sleeper ?: static function (int $microseconds): void {
            if ($microseconds > 0) {
                \usleep($microseconds);
            }
        };
        $this->random = $random ?: static function (int $minimum, int $maximum): int {
            try {
                return \random_int($minimum, $maximum);
            } catch (\Throwable $exception) {
                unset($exception);
                return $minimum;
            }
        };
    }

    public function acquire(): int
    {
        $now = (float) \call_user_func($this->clock);
        $sharedNext = 0.0;

        if (\function_exists('get_transient')) {
            $stored = \get_transient(self::SHARED_SLOT_TRANSIENT);
            $sharedNext = \is_numeric($stored) ? (float) $stored : 0.0;
        }

        $localNext = self::$lastRequestAt > 0.0
            ? self::$lastRequestAt + (self::MIN_INTERVAL_MICROSECONDS / 1000000)
            : 0.0;
        $next = \max($now, $sharedNext, $localNext);
        $waitMicroseconds = (int) \max(0, \ceil(($next - $now) * 1000000));

        if ($waitMicroseconds > 0) {
            \call_user_func($this->sleeper, $waitMicroseconds);
        }

        $grantedAt = (float) \call_user_func($this->clock);
        self::$lastRequestAt = \max($grantedAt, $next);

        if (\function_exists('set_transient')) {
            \set_transient(
                self::SHARED_SLOT_TRANSIENT,
                self::$lastRequestAt + (self::MIN_INTERVAL_MICROSECONDS / 1000000),
                2
            );
        }

        return $waitMicroseconds;
    }

    public function retryDelayMicroseconds(int $attempt, string $retryAfter = ''): int
    {
        $retryAfterSeconds = $this->retryAfterSeconds($retryAfter);

        if ($retryAfterSeconds > 0) {
            return $retryAfterSeconds * 1000000;
        }

        $attempt = \max(1, $attempt);
        $baseMilliseconds = \min(5000, 250 * (2 ** ($attempt - 1)));
        $jitterMilliseconds = (int) \call_user_func($this->random, 25, 175);

        return ($baseMilliseconds + $jitterMilliseconds) * 1000;
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            \call_user_func($this->sleeper, $microseconds);
        }
    }

    public static function resetForTests(): void
    {
        self::$lastRequestAt = 0.0;
    }

    private function retryAfterSeconds(string $retryAfter): int
    {
        $retryAfter = \trim($retryAfter);

        if ($retryAfter === '') {
            return 0;
        }

        if (\ctype_digit($retryAfter)) {
            return \max(0, (int) $retryAfter);
        }

        $timestamp = \strtotime($retryAfter);

        return $timestamp === false ? 0 : \max(0, $timestamp - \time());
    }
}
