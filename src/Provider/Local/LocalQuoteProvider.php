<?php

namespace MustHotelBooking\Provider\Local;

use MustHotelBooking\Engine\PricingEngine;
use MustHotelBooking\Engine\RatePlanEngine;
use MustHotelBooking\Provider\Contracts\QuoteProviderInterface;
use MustHotelBooking\Provider\Dto\QuoteRequest;

final class LocalQuoteProvider implements QuoteProviderInterface
{
    public function calculateTotal(
        int $roomId,
        string $checkin,
        string $checkout,
        int $guests = 1,
        string $couponCode = '',
        int $ratePlanId = 0
    ): array {
        return PricingEngine::calculateTotal($roomId, $checkin, $checkout, $guests, $couponCode, $ratePlanId);
    }

    public function buildCheckoutRoomItems(QuoteRequest $request): array
    {
        return PricingEngine::buildCheckoutRoomItems(
            $request->getContext(),
            $request->getCouponCode(),
            $request->getGuestForm(),
            $request->shouldUseStrictRoomGuests()
        );
    }

    public function getRoomRatePlansWithPricing(int $roomId, string $checkin, string $checkout, int $guests = 1): array
    {
        return RatePlanEngine::getRoomRatePlansWithPricing($roomId, $checkin, $checkout, $guests);
    }
}
