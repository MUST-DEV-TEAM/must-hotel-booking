<?php

namespace MustHotelBooking\Provider\Contracts;

use MustHotelBooking\Provider\ProviderCapabilities;

interface BookingProviderInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    public function getCapabilities(): ProviderCapabilities;

    public function availability(): AvailabilityProviderInterface;

    public function quote(): QuoteProviderInterface;

    public function reservations(): ReservationProviderInterface;
}
