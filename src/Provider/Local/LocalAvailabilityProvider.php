<?php

namespace MustHotelBooking\Provider\Local;

use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Engine\AvailabilityAjaxController;
use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\DisabledDatesRequest;

final class LocalAvailabilityProvider implements AvailabilityProviderInterface
{
    public function getAvailableRooms(AvailabilitySearchRequest $request): array
    {
        return AvailabilityEngine::getAvailableRooms(
            $request->getCheckin(),
            $request->getCheckout(),
            $request->getGuests(),
            $request->getCategory()
        );
    }

    public function getAvailableRoomById(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array
    {
        return AvailabilityAjaxController::get_available_room_for_ajax_by_id($roomId, $checkin, $checkout, $guests);
    }

    public function getDisabledDates(DisabledDatesRequest $request): array
    {
        $windowDays = AvailabilityAjaxController::normalize_disabled_dates_window_days($request->getWindowDays());
        $checkin = $request->getCheckin();
        $roomId = $request->getRoomId();
        $guests = $request->getGuests();
        $category = $request->getCategory();

        if ($roomId > 0) {
            return [
                'disabled_checkin_dates' => AvailabilityAjaxController::get_disabled_checkin_dates_for_room($roomId, $guests, $windowDays),
                'disabled_checkout_dates' => $checkin !== ''
                    ? AvailabilityAjaxController::get_disabled_checkout_dates_for_room($checkin, $roomId, $guests, $windowDays)
                    : [],
            ];
        }

        $resolvedRoomCount = BookingRules::resolveRoomCount($guests, $request->getRoomCount(), $category);

        if ($resolvedRoomCount > 1) {
            return [
                'disabled_checkin_dates' => AvailabilityAjaxController::get_disabled_checkin_dates_for_party($guests, $resolvedRoomCount, $windowDays, $category),
                'disabled_checkout_dates' => $checkin !== ''
                    ? AvailabilityAjaxController::get_disabled_checkout_dates_for_party($checkin, $guests, $resolvedRoomCount, $windowDays, $category)
                    : [],
            ];
        }

        return [
            'disabled_checkin_dates' => AvailabilityAjaxController::get_disabled_checkin_dates_for_guests($guests, $windowDays, $category),
            'disabled_checkout_dates' => $checkin !== ''
                ? AvailabilityAjaxController::get_disabled_checkout_dates_for_guests($checkin, $guests, $windowDays, $category)
                : [],
        ];
    }

    public function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        return AvailabilityEngine::checkAvailability($roomId, $checkin, $checkout, $excludeSessionId);
    }

    public function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool
    {
        return AvailabilityEngine::checkBookingRestrictions($roomId, $checkin, $checkout);
    }
}
