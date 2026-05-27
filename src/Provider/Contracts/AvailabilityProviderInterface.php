<?php

namespace MustHotelBooking\Provider\Contracts;

use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\DisabledDatesRequest;

interface AvailabilityProviderInterface
{
    /** @return array<int, array<string, mixed>> */
    public function getAvailableRooms(AvailabilitySearchRequest $request): array;

    /** @return array<string, mixed>|null */
    public function getAvailableRoomById(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array;

    /** @return array<string, mixed> */
    public function getDisabledDates(DisabledDatesRequest $request): array;

    public function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool;

    public function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool;
}
