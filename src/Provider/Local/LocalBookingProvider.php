<?php

namespace MustHotelBooking\Provider\Local;

use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Contracts\BookingProviderInterface;
use MustHotelBooking\Provider\Contracts\QuoteProviderInterface;
use MustHotelBooking\Provider\Contracts\ReservationProviderInterface;
use MustHotelBooking\Provider\ProviderCapabilities;
use MustHotelBooking\Provider\ProviderManager;

final class LocalBookingProvider implements BookingProviderInterface
{
    /** @var LocalAvailabilityProvider|null */
    private $availability;

    /** @var LocalQuoteProvider|null */
    private $quote;

    /** @var LocalReservationProvider|null */
    private $reservations;

    public function getKey(): string
    {
        return ProviderManager::LOCAL_MODE;
    }

    public function getLabel(): string
    {
        return \__('Local', 'must-hotel-booking');
    }

    public function getCapabilities(): ProviderCapabilities
    {
        return ProviderCapabilities::local();
    }

    public function availability(): AvailabilityProviderInterface
    {
        if (!$this->availability instanceof LocalAvailabilityProvider) {
            $this->availability = new LocalAvailabilityProvider();
        }

        return $this->availability;
    }

    public function quote(): QuoteProviderInterface
    {
        if (!$this->quote instanceof LocalQuoteProvider) {
            $this->quote = new LocalQuoteProvider();
        }

        return $this->quote;
    }

    public function reservations(): ReservationProviderInterface
    {
        if (!$this->reservations instanceof LocalReservationProvider) {
            $this->reservations = new LocalReservationProvider();
        }

        return $this->reservations;
    }
}
