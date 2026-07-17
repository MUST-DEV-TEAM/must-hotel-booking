<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockRateLimiter
{
    private const MIN_INTERVAL_MICROSECONDS = 250000;
    private const SHARED_SLOT_TRANSIENT = 'must_hotel_booking_clock_next_request_at';
    private const SHARED_LOCK_OPTION = 'must_hotel_booking_clock_rate_limiter_lock';

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
        $lockValue = $this->acquireSharedLock();
        $now = (float) \call_user_func($this->clock);
        $sharedNext = 0.0;

        if (\function_exists('get_transient')) {
            $stored = \get_transient(self::SHARED_SLOT_TRANSIENT);
            $sharedNext = \is_numeric($stored) ? (float) $stored : 0.0;
        }

        $localNext = self::$lastRequestAt > 0.0
            ? self::$lastRequestAt + (self::MIN_INTERVAL_MICROSECONDS / 1000000)
            : 0.0;
        $grantedAt = \max($now, $sharedNext, $localNext);
        $waitMicroseconds = (int) \max(0, \ceil(($grantedAt - $now) * 1000000));

        self::$lastRequestAt = $grantedAt;

        if (\function_exists('set_transient')) {
            \set_transient(
                self::SHARED_SLOT_TRANSIENT,
                $grantedAt + (self::MIN_INTERVAL_MICROSECONDS / 1000000),
                2
            );
        }

        $this->releaseSharedLock($lockValue);

        if ($waitMicroseconds > 0) {
            \call_user_func($this->sleeper, $waitMicroseconds);
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

        // Clock documents retry delay as retry counter multiplied by 500ms.
        return \min(5000, $attempt * 500) * 1000;
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

    private function acquireSharedLock(): string
    {
        if (!\function_exists('add_option') || !\function_exists('get_option') || !\function_exists('delete_option')) {
            return '';
        }

        $token = \function_exists('wp_generate_uuid4')
            ? \wp_generate_uuid4()
            : \substr(\hash('sha256', \uniqid('', true)), 0, 32);

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $now = (float) \call_user_func($this->clock);
            $value = $token . '|' . ($now + 1.0);
            if (\add_option(self::SHARED_LOCK_OPTION, $value, '', 'no')) {
                return $value;
            }

            $existing = (string) \get_option(self::SHARED_LOCK_OPTION, '');
            $separator = \strrpos($existing, '|');
            $expiresAt = $separator === false ? 0.0 : (float) \substr($existing, $separator + 1);
            if ($expiresAt <= $now) {
                \delete_option(self::SHARED_LOCK_OPTION);
                continue;
            }

            \call_user_func($this->sleeper, 10000);
        }

        return '';
    }

    private function releaseSharedLock(string $lockValue): void
    {
        if ($lockValue === '' || !\function_exists('get_option') || !\function_exists('delete_option')) {
            return;
        }

        $current = (string) \get_option(self::SHARED_LOCK_OPTION, '');
        if ($current !== '' && \hash_equals($lockValue, $current)) {
            \delete_option(self::SHARED_LOCK_OPTION);
        }
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
