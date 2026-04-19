<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\DisabledDatesRequest;

final class ClockUnavailableAvailabilityProvider implements AvailabilityProviderInterface
{
    public function getAvailableRooms(AvailabilitySearchRequest $request): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function getAvailableRoomById(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function getDisabledDates(DisabledDatesRequest $request): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool
    {
        throw ClockBookingNotEnabledException::forPhase();
    }
}
