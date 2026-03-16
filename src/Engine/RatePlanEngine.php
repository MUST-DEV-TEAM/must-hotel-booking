<?php

namespace MustHotelBooking\Engine;

final class RatePlanEngine
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRatePlansForRoomType(int $roomTypeId): array
    {
        if ($roomTypeId <= 0) {
            return [];
        }

        $room = get_room_repository()->getRoomById($roomTypeId);

        if (!\is_array($room)) {
            return [];
        }

        $plans = get_rate_plan_repository()->getRatePlansForRoomType($roomTypeId);

        if (!empty($plans)) {
            return \array_values(
                \array_map(
                    static function (array $plan) use ($roomTypeId): array {
                        $plan['id'] = isset($plan['id']) ? (int) $plan['id'] : 0;
                        $plan['room_type_id'] = $roomTypeId;
                        $plan['base_price'] = isset($plan['base_price']) ? (float) $plan['base_price'] : 0.0;
                        $plan['max_occupancy'] = isset($plan['max_occupancy']) ? \max(1, (int) $plan['max_occupancy']) : 1;
                        $plan['is_fallback'] = false;

                        return $plan;
                    },
                    $plans
                )
            );
        }

        return [[
            'id' => 0,
            'room_type_id' => $roomTypeId,
            'name' => \__('Standard Rate', 'must-hotel-booking'),
            'description' => '',
            'cancellation_policy_id' => 0,
            'is_active' => 1,
            'base_price' => isset($room['base_price']) ? (float) $room['base_price'] : 0.0,
            'max_occupancy' => isset($room['max_guests']) ? \max(1, (int) $room['max_guests']) : 1,
            'is_fallback' => true,
        ]];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getRoomRatePlan(int $roomTypeId, int $ratePlanId): ?array
    {
        if ($roomTypeId <= 0) {
            return null;
        }

        $plans = self::getRatePlansForRoomType($roomTypeId);

        foreach ($plans as $plan) {
            if (!\is_array($plan)) {
                continue;
            }

            if ((int) ($plan['id'] ?? 0) === $ratePlanId) {
                return $plan;
            }
        }

        if ($ratePlanId === 0 && !empty($plans[0]) && \is_array($plans[0]) && !empty($plans[0]['is_fallback'])) {
            return $plans[0];
        }

        return null;
    }

    public static function getRatePlanPrice(int $ratePlanId, string $date): float
    {
        if ($ratePlanId <= 0 || !is_valid_booking_date($date)) {
            return 0.0;
        }

        $price = get_rate_plan_repository()->getRatePlanPrice($ratePlanId, $date);

        return $price !== null ? \round((float) $price, 2) : 0.0;
    }

    public static function calculateRatePlanPrice(int $ratePlanId, string $checkin, string $checkout): float
    {
        if (!is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
            return 0.0;
        }

        if ($ratePlanId <= 0) {
            return 0.0;
        }

        $total = 0.0;
        $start = new \DateTimeImmutable($checkin);
        $end = new \DateTimeImmutable($checkout);

        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $total += PricingEngine::calculateSeasonalPrice($ratePlanId, $date->format('Y-m-d'));
        }

        return \round($total, 2);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getRoomRatePlansWithPricing(int $roomId, string $checkin, string $checkout, int $guests = 1): array
    {
        $plans = self::getRatePlansForRoomType($roomId);
        $items = [];

        foreach ($plans as $plan) {
            if (!\is_array($plan)) {
                continue;
            }

            $planId = isset($plan['id']) ? (int) $plan['id'] : 0;
            $maxOccupancy = isset($plan['max_occupancy']) ? \max(1, (int) $plan['max_occupancy']) : 1;

            if ($guests > 0 && $maxOccupancy > 0 && $guests > $maxOccupancy) {
                continue;
            }

            $pricing = PricingEngine::calculateTotal($roomId, $checkin, $checkout, \max(1, $guests), '', $planId);
            $roomSubtotal = isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : 0.0;
            $nights = isset($pricing['nights']) ? \max(1, (int) $pricing['nights']) : 1;
            $nightlyPrice = $roomSubtotal > 0 ? \round($roomSubtotal / $nights, 2) : (float) ($plan['base_price'] ?? 0.0);

            $items[] = [
                'id' => $planId,
                'name' => isset($plan['name']) ? (string) $plan['name'] : '',
                'description' => isset($plan['description']) ? (string) $plan['description'] : '',
                'base_price' => isset($plan['base_price']) ? (float) $plan['base_price'] : 0.0,
                'nightly_price' => $nightlyPrice,
                'total_price' => isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0,
                'max_occupancy' => $maxOccupancy,
                'is_fallback' => !empty($plan['is_fallback']),
            ];
        }

        return $items;
    }
}
