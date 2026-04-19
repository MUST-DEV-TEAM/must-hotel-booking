<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\Contracts\ReservationProviderInterface;
use MustHotelBooking\Provider\Dto\ReservationCreateRequest;

final class ClockUnavailableReservationProvider implements ReservationProviderInterface
{
    public function buildReservationNote(int $roomId, array $guestForm): string
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function getCheckoutRoomData(int $roomId): ?array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function getRoomGuestAllocations(array $rooms, int $totalGuests, array $guestForm = [], bool $strict = false): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function ensureRoomLock(int $roomId, string $checkin, string $checkout): bool
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function ensureRoomLocks(array $roomIds, string $checkin, string $checkout): bool
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function releaseRoomSelectionLock(int $roomId, string $checkin, string $checkout): bool
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function createGuest(array $guestForm): int
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function bootstrapCheckoutSelectionFromRequest(array $source): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function handleBookingRoomSelectionRequest(array $requestSource): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function handleAccommodationRoomSelectionRequest(array $requestSource): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function continueCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function submitCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function createReservations(ReservationCreateRequest $request): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }
}
