<?php

namespace MustHotelBooking\Provider;

use MustHotelBooking\Provider\Contracts\BookingProviderInterface;

final class ProviderRegistry
{
    /** @var array<string, BookingProviderInterface> */
    private static $providers = [];

    public static function register(BookingProviderInterface $provider): void
    {
        self::$providers[$provider->getKey()] = $provider;
    }

    public static function has(string $key): bool
    {
        return isset(self::$providers[$key]);
    }

    public static function get(string $key): ?BookingProviderInterface
    {
        return self::$providers[$key] ?? null;
    }

    /** @return array<string, BookingProviderInterface> */
    public static function all(): array
    {
        return self::$providers;
    }
}
