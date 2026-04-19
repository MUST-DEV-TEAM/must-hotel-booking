<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Contracts\BookingProviderInterface;
use MustHotelBooking\Provider\Contracts\QuoteProviderInterface;
use MustHotelBooking\Provider\Contracts\ReservationProviderInterface;
use MustHotelBooking\Provider\ProviderCapabilities;
use MustHotelBooking\Provider\ProviderManager;

final class ClockBookingProvider implements BookingProviderInterface
{
    /** @var AvailabilityProviderInterface|null */
    private $availability;

    /** @var QuoteProviderInterface|null */
    private $quote;

    /** @var ReservationProviderInterface|null */
    private $reservations;

    public function getKey(): string
    {
        return ProviderManager::CLOCK_MODE;
    }

    public function getLabel(): string
    {
        return \__('Clock', 'must-hotel-booking');
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return ClockConfig::isPublicBookingConfigured()
            ? ProviderCapabilities::clockPublicBooking()
            : ProviderCapabilities::clockScaffold();
    }

    public function availability(): AvailabilityProviderInterface
    {
        if (!$this->availability instanceof AvailabilityProviderInterface) {
            $this->availability = ClockConfig::isPublicBookingConfigured()
                ? new ClockAvailabilityProvider()
                : new ClockUnavailableAvailabilityProvider();
        }

        return $this->availability;
    }

    public function quote(): QuoteProviderInterface
    {
        if (!$this->quote instanceof QuoteProviderInterface) {
            $this->quote = ClockConfig::isPublicBookingConfigured()
                ? new ClockQuoteProvider()
                : new ClockUnavailableQuoteProvider();
        }

        return $this->quote;
    }

    public function reservations(): ReservationProviderInterface
    {
        if (!$this->reservations instanceof ReservationProviderInterface) {
            $this->reservations = ClockConfig::isPublicBookingConfigured()
                ? new ClockReservationProvider()
                : new ClockUnavailableReservationProvider();
        }

        return $this->reservations;
    }
}
