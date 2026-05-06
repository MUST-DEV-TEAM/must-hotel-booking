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
        unset($roomId, $checkin, $checkout, $guests, $couponCode, $ratePlanId);

        return [
            'success' => false,
            'message' => self::message(),
        ];
    }

    public function buildCheckoutRoomItems(QuoteRequest $request): array
    {
        unset($request);

        return [
            'items' => [],
            'summary' => [
                'room_subtotal' => 0.0,
                'fees_total' => 0.0,
                'discount_total' => 0.0,
                'taxes_total' => 0.0,
                'total_price' => 0.0,
                'nights' => 0,
                'applied_coupon' => '',
            ],
            'errors' => [self::message()],
            'room_guest_counts' => [],
        ];
    }

    public function getRoomRatePlansWithPricing(int $roomId, string $checkin, string $checkout, int $guests = 1): array
    {
        unset($roomId, $checkin, $checkout, $guests);

        return [];
    }

    private static function message(): string
    {
        if (\function_exists('current_user_can') && \current_user_can('manage_options')) {
            return \__('Direct Clock API booking is selected, but its real availability/rate/reservation adapters are not implemented yet. Public booking is fail-closed.', 'must-hotel-booking');
        }

        return \__('Booking is temporarily unavailable. Please contact the hotel.', 'must-hotel-booking');
    }
}
