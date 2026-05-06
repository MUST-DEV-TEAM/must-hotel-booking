<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\Contracts\ReservationProviderInterface;
use MustHotelBooking\Provider\Dto\ReservationCreateRequest;

final class ClockUnavailableReservationProvider implements ReservationProviderInterface
{
    public function buildReservationNote(int $roomId, array $guestForm): string
    {
        unset($roomId, $guestForm);

        return '';
    }

    public function getCheckoutRoomData(int $roomId): ?array
    {
        unset($roomId);

        return null;
    }

    public function getRoomGuestAllocations(array $rooms, int $totalGuests, array $guestForm = [], bool $strict = false): array
    {
        unset($rooms, $totalGuests, $guestForm, $strict);

        return [
            'counts' => [],
            'errors' => [self::message()],
        ];
    }

    public function ensureRoomLock(int $roomId, string $checkin, string $checkout): bool
    {
        unset($roomId, $checkin, $checkout);

        return false;
    }

    public function ensureRoomLocks(array $roomIds, string $checkin, string $checkout): bool
    {
        unset($roomIds, $checkin, $checkout);

        return false;
    }

    public function releaseRoomSelectionLock(int $roomId, string $checkin, string $checkout): bool
    {
        unset($roomId, $checkin, $checkout);

        return false;
    }

    public function createGuest(array $guestForm): int
    {
        unset($guestForm);

        return 0;
    }

    public function bootstrapCheckoutSelectionFromRequest(array $source): array
    {
        unset($source);

        return [self::message()];
    }

    public function handleBookingRoomSelectionRequest(array $requestSource): array
    {
        unset($requestSource);

        return [
            'handled' => true,
            'success' => false,
            'message' => self::message(),
            'redirect_url' => '',
        ];
    }

    public function handleAccommodationRoomSelectionRequest(array $requestSource): array
    {
        return [
            'success' => false,
            'messages' => [self::message()],
            'context' => \MustHotelBooking\Engine\BookingValidationEngine::parseRequestContext($requestSource, false),
            'redirect_url' => '',
            'should_redirect' => false,
        ];
    }

    public function continueCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        unset($context, $guestForm, $couponCode);

        return [
            'success' => false,
            'errors' => [self::message()],
            'redirect_url' => '',
        ];
    }

    public function submitCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        unset($context, $guestForm, $couponCode);

        return [
            'success' => false,
            'errors' => [self::message()],
            'redirect_url' => '',
        ];
    }

    public function createReservations(ReservationCreateRequest $request): array
    {
        unset($request);

        return [
            'errors' => [self::message()],
            'reservation_ids' => [],
            'applied_coupon_ids' => [],
        ];
    }

    private static function message(): string
    {
        if (\function_exists('current_user_can') && \current_user_can('manage_options')) {
            return \__('Direct Clock API booking is selected, but its real reservation adapter is not implemented yet. No local fallback reservation was created.', 'must-hotel-booking');
        }

        return \__('Booking is temporarily unavailable. Please contact the hotel.', 'must-hotel-booking');
    }
}
