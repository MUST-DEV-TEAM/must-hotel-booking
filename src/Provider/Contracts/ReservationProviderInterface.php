<?php

namespace MustHotelBooking\Provider\Contracts;

use MustHotelBooking\Provider\Dto\ReservationCreateRequest;

interface ReservationProviderInterface
{
    /** @param array<string, mixed> $guestForm */
    public function buildReservationNote(int $roomId, array $guestForm): string;

    /** @return array<string, mixed>|null */
    public function getCheckoutRoomData(int $roomId): ?array;

    /** @param array<int, array<string, mixed>> $rooms @param array<string, mixed> $guestForm @return array{counts: array<int, int>, errors: array<int, string>} */
    public function getRoomGuestAllocations(array $rooms, int $totalGuests, array $guestForm = [], bool $strict = false): array;

    public function ensureRoomLock(int $roomId, string $checkin, string $checkout): bool;

    /** @param array<int, int> $roomIds */
    public function ensureRoomLocks(array $roomIds, string $checkin, string $checkout): bool;

    public function releaseRoomSelectionLock(int $roomId, string $checkin, string $checkout): bool;

    /** @param array<string, string> $guestForm */
    public function createGuest(array $guestForm): int;

    /** @param array<string, mixed> $source @return array<int, string> */
    public function bootstrapCheckoutSelectionFromRequest(array $source): array;

    /** @param array<string, mixed> $requestSource @return array<string, mixed> */
    public function handleBookingRoomSelectionRequest(array $requestSource): array;

    /** @param array<string, mixed> $requestSource @return array<string, mixed> */
    public function handleAccommodationRoomSelectionRequest(array $requestSource): array;

    /** @param array<string, mixed> $context @param array<string, string> $guestForm @return array<string, mixed> */
    public function continueCheckout(array $context, array $guestForm, string $couponCode = ''): array;

    /** @param array<string, mixed> $context @param array<string, string> $guestForm @return array<string, mixed> */
    public function submitCheckout(array $context, array $guestForm, string $couponCode = ''): array;

    /** @return array{errors: array<int, string>, reservation_ids: array<int, int>, applied_coupon_ids: array<int, int>} */
    public function createReservations(ReservationCreateRequest $request): array;
}
