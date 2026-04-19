<?php

namespace MustHotelBooking\Provider\Contracts;

use MustHotelBooking\Provider\Dto\QuoteRequest;

interface QuoteProviderInterface
{
    /** @return array<string, mixed> */
    public function calculateTotal(
        int $roomId,
        string $checkin,
        string $checkout,
        int $guests = 1,
        string $couponCode = '',
        int $ratePlanId = 0
    ): array;

    /** @return array<string, mixed> */
    public function buildCheckoutRoomItems(QuoteRequest $request): array;

    /** @return array<int, array<string, mixed>> */
    public function getRoomRatePlansWithPricing(int $roomId, string $checkin, string $checkout, int $guests = 1): array;
}
