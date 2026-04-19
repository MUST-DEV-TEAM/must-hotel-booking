<?php

namespace MustHotelBooking\Provider\Local;

use MustHotelBooking\Engine\InventoryEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\ReservationEngine;
use MustHotelBooking\Provider\Contracts\ReservationProviderInterface;
use MustHotelBooking\Provider\Dto\ReservationCreateRequest;

final class LocalReservationProvider implements ReservationProviderInterface
{
    public function buildReservationNote(int $roomId, array $guestForm): string
    {
        return ReservationEngine::buildReservationNote($roomId, $guestForm);
    }

    public function getCheckoutRoomData(int $roomId): ?array
    {
        return ReservationEngine::getCheckoutRoomData($roomId);
    }

    public function getRoomGuestAllocations(array $rooms, int $totalGuests, array $guestForm = [], bool $strict = false): array
    {
        return ReservationEngine::getRoomGuestAllocations($rooms, $totalGuests, $guestForm, $strict);
    }

    public function ensureRoomLock(int $roomId, string $checkin, string $checkout): bool
    {
        return ReservationEngine::ensureRoomLock($roomId, $checkin, $checkout);
    }

    public function ensureRoomLocks(array $roomIds, string $checkin, string $checkout): bool
    {
        return ReservationEngine::ensureRoomLocks($roomIds, $checkin, $checkout);
    }

    public function releaseRoomSelectionLock(int $roomId, string $checkin, string $checkout): bool
    {
        if (InventoryEngine::hasInventoryForRoomType($roomId)) {
            return InventoryEngine::releaseLocksForRoomType($roomId, $checkin, $checkout);
        }

        return LockEngine::releaseExactLock($roomId, $checkin, $checkout);
    }

    public function createGuest(array $guestForm): int
    {
        return ReservationEngine::createGuest($guestForm);
    }

    public function bootstrapCheckoutSelectionFromRequest(array $source): array
    {
        return ReservationEngine::bootstrapCheckoutSelectionFromRequest($source);
    }

    public function handleBookingRoomSelectionRequest(array $requestSource): array
    {
        return ReservationEngine::handleBookingRoomSelectionRequest($requestSource);
    }

    public function handleAccommodationRoomSelectionRequest(array $requestSource): array
    {
        return ReservationEngine::handleAccommodationRoomSelectionRequest($requestSource);
    }

    public function continueCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        return ReservationEngine::continueCheckout($context, $guestForm, $couponCode);
    }

    public function submitCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        return ReservationEngine::submitCheckout($context, $guestForm, $couponCode);
    }

    public function createReservations(ReservationCreateRequest $request): array
    {
        return ReservationEngine::createReservations(
            $request->getContext(),
            $request->getGuestForm(),
            $request->getCouponCode(),
            $request->getOptions()
        );
    }
}
