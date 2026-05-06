<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\DisabledDatesRequest;

final class ClockUnavailableAvailabilityProvider implements AvailabilityProviderInterface
{
    public function getAvailableRooms(AvailabilitySearchRequest $request): array
    {
        unset($request);

        return [];
    }

    public function getAvailableRoomById(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array
    {
        return null;
    }

    public function getDisabledDates(DisabledDatesRequest $request): array
    {
        unset($request);

        return [
            'disabled_checkin_dates' => [],
            'disabled_checkout_dates' => [],
        ];
    }

    public function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        unset($roomId, $checkin, $checkout, $excludeSessionId);

        return false;
    }

    public function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool
    {
        unset($roomId, $checkin, $checkout);

        return false;
    }
}
