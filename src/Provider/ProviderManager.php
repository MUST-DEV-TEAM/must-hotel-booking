<?php

namespace MustHotelBooking\Provider;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Provider\Clock\ClockBookingProvider;
use MustHotelBooking\Provider\Contracts\BookingProviderInterface;
use MustHotelBooking\Provider\Local\LocalBookingProvider;

final class ProviderManager
{
    public const LOCAL_MODE = 'local';
    public const CLOCK_MODE = 'clock';

    /** @var bool */
    private static $registered = false;

    public static function registerDefaultProviders(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        if (!ProviderRegistry::has(self::LOCAL_MODE)) {
            ProviderRegistry::register(new LocalBookingProvider());
        }

        if (!ProviderRegistry::has(self::CLOCK_MODE)) {
            ProviderRegistry::register(new ClockBookingProvider());
        }

        \do_action('must_hotel_booking/providers/register');
    }

    public static function active(): BookingProviderInterface
    {
        self::registerDefaultProviders();

        $provider = self::configured();

        if (
            $provider instanceof BookingProviderInterface
            && $provider->getKey() === self::CLOCK_MODE
            && !\MustHotelBooking\Provider\Clock\ClockConfig::allowLocalFallback()
        ) {
            return $provider;
        }

        if ($provider instanceof BookingProviderInterface && self::isReadyForPublicBooking($provider)) {
            return $provider;
        }

        $local = ProviderRegistry::get(self::LOCAL_MODE);

        if ($local instanceof BookingProviderInterface) {
            return $local;
        }

        $local = new LocalBookingProvider();
        ProviderRegistry::register($local);

        return $local;
    }

    public static function activeKey(): string
    {
        return self::active()->getKey();
    }

    public static function configured(): ?BookingProviderInterface
    {
        self::registerDefaultProviders();

        return ProviderRegistry::get(self::getConfiguredMode());
    }

    public static function configuredKey(): string
    {
        $provider = self::configured();

        return $provider instanceof BookingProviderInterface ? $provider->getKey() : self::LOCAL_MODE;
    }

    public static function getConfiguredMode(): string
    {
        return self::normalizeMode(MustBookingConfig::get_provider_mode());
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = \function_exists('sanitize_key')
            ? \sanitize_key($mode)
            : (string) \preg_replace('/[^a-z0-9_\-]/', '', \strtolower($mode));

        return $mode !== '' ? $mode : self::LOCAL_MODE;
    }

    public static function isReadyForPublicBooking(BookingProviderInterface $provider): bool
    {
        $capabilities = $provider->getCapabilities();

        foreach ([ProviderCapabilities::AVAILABILITY, ProviderCapabilities::QUOTE, ProviderCapabilities::HOLDS, ProviderCapabilities::RESERVATION_CREATE] as $capability) {
            if (!$capabilities->supports($capability)) {
                return false;
            }
        }

        return true;
    }
}
