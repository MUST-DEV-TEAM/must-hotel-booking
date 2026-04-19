<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\Contracts\QuoteProviderInterface;
use MustHotelBooking\Provider\Dto\QuoteRequest;

final class ClockUnavailableQuoteProvider implements QuoteProviderInterface
{
    public function calculateTotal(
        int $roomId,
        string $checkin,
        string $checkout,
        int $guests = 1,
        string $couponCode = '',
        int $ratePlanId = 0
    ): array {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function buildCheckoutRoomItems(QuoteRequest $request): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }

    public function getRoomRatePlansWithPricing(int $roomId, string $checkin, string $checkout, int $guests = 1): array
    {
        throw ClockBookingNotEnabledException::forPhase();
    }
}
