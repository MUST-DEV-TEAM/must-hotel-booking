<?php

namespace MustHotelBooking\Engine;

final class PricingEngine
{
    /**
     * @return array<string, mixed>|null
     */
    public static function getSeasonForDate(string $date): ?array
    {
        return get_season_for_date($date);
    }

    public static function calculateSeasonModifier(int $ratePlanId, string $date): float
    {
        return calculate_season_modifier($ratePlanId, $date);
    }

    public static function calculateSeasonalPrice(int $ratePlanId, string $date): float
    {
        return calculate_seasonal_price($ratePlanId, $date);
    }

    /**
     * @return array<string, mixed>
     */
    public static function calculateTotal(int $roomId, string $checkin, string $checkout, int $guests = 1, string $couponCode = '', int $ratePlanId = 0): array
    {
        return calculate_booking_price($roomId, $checkin, $checkout, $guests, $couponCode, $ratePlanId);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $guestForm
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, float|int|string>, errors: array<int, string>, room_guest_counts: array<int, int>}
     */
    public static function buildCheckoutRoomItems(array $context, string $couponCode = '', array $guestForm = [], bool $strictRoomGuests = false): array
    {
        $items = [];
        $summary = [
            'room_subtotal' => 0.0,
            'fees_total' => 0.0,
            'discount_total' => 0.0,
            'taxes_total' => 0.0,
            'total_price' => 0.0,
            'nights' => 0,
            'applied_coupon' => '',
        ];
        $roomRows = [];

        foreach (\MustHotelBooking\Frontend\get_booking_selected_rooms() as $selectedRoom) {
            $roomId = isset($selectedRoom['room_id']) ? (int) $selectedRoom['room_id'] : 0;
            $ratePlanId = isset($selectedRoom['rate_plan_id']) ? (int) $selectedRoom['rate_plan_id'] : 0;
            $room = ReservationEngine::getCheckoutRoomData($roomId);

            if (!\is_array($room)) {
                continue;
            }

            $ratePlan = RatePlanEngine::getRoomRatePlan($roomId, $ratePlanId);

            if (\is_array($ratePlan)) {
                $room['selected_rate_plan_id'] = $ratePlanId;
                $room['selected_rate_plan_name'] = isset($ratePlan['name']) ? (string) $ratePlan['name'] : '';
                $room['selected_rate_plan_description'] = isset($ratePlan['description']) ? (string) $ratePlan['description'] : '';
                $room['selected_rate_plan_max_occupancy'] = isset($ratePlan['max_occupancy']) ? (int) $ratePlan['max_occupancy'] : 0;
                $room['effective_max_guests'] = isset($ratePlan['max_occupancy'])
                    ? \max(1, \min((int) ($room['max_guests'] ?? 1), (int) $ratePlan['max_occupancy']))
                    : \max(1, (int) ($room['max_guests'] ?? 1));
            }

            $roomRows[] = $room;
        }

        $allocation = ReservationEngine::getRoomGuestAllocations($roomRows, (int) ($context['guests'] ?? 1), $guestForm, $strictRoomGuests);
        $roomGuestCounts = isset($allocation['counts']) && \is_array($allocation['counts']) ? $allocation['counts'] : [];
        $allocationErrors = isset($allocation['errors']) && \is_array($allocation['errors'])
            ? \array_values(\array_filter(\array_map('strval', $allocation['errors'])))
            : [];

        if (!empty($allocationErrors)) {
            return [
                'items' => [],
                'summary' => $summary,
                'errors' => $allocationErrors,
                'room_guest_counts' => $roomGuestCounts,
            ];
        }

        foreach ($roomRows as $room) {
            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $roomGuests = isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : \max(1, (int) ($context['guests'] ?? 1));
            $pricing = self::calculateTotal(
                $roomId,
                (string) ($context['checkin'] ?? ''),
                (string) ($context['checkout'] ?? ''),
                $roomGuests,
                $couponCode,
                isset($room['selected_rate_plan_id']) ? (int) $room['selected_rate_plan_id'] : 0
            );

            if (\is_array($pricing) && !empty($pricing['success'])) {
                $room['dynamic_total_price'] = isset($pricing['total_price']) ? (float) $pricing['total_price'] : null;
                $room['price_preview_total'] = isset($pricing['total_price']) ? (float) $pricing['total_price'] : null;
                $room['dynamic_room_subtotal'] = isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : null;
                $room['dynamic_nights'] = isset($pricing['nights']) ? (int) $pricing['nights'] : null;

                $summary['room_subtotal'] += isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : 0.0;
                $summary['fees_total'] += isset($pricing['fees_total']) ? (float) $pricing['fees_total'] : 0.0;
                $summary['discount_total'] += isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0;
                $summary['taxes_total'] += isset($pricing['taxes_total']) ? (float) $pricing['taxes_total'] : 0.0;
                $summary['total_price'] += isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0;

                if ((int) $summary['nights'] === 0 && isset($pricing['nights'])) {
                    $summary['nights'] = (int) $pricing['nights'];
                }

                if ((string) $summary['applied_coupon'] === '' && !empty($pricing['applied_coupon']) && \is_string($pricing['applied_coupon'])) {
                    $summary['applied_coupon'] = (string) $pricing['applied_coupon'];
                }
            }

            $roomView = \function_exists('\MustHotelBooking\Frontend\get_booking_results_room_view_data')
                ? \MustHotelBooking\Frontend\get_booking_results_room_view_data($room)
                : $room;

            if (!\is_array($roomView)) {
                continue;
            }

            $items[] = [
                'room_id' => $roomId,
                'rate_plan_id' => isset($room['selected_rate_plan_id']) ? (int) $room['selected_rate_plan_id'] : 0,
                'rate_plan' => [
                    'id' => isset($room['selected_rate_plan_id']) ? (int) $room['selected_rate_plan_id'] : 0,
                    'name' => isset($room['selected_rate_plan_name']) ? (string) $room['selected_rate_plan_name'] : '',
                    'description' => isset($room['selected_rate_plan_description']) ? (string) $room['selected_rate_plan_description'] : '',
                    'max_occupancy' => isset($room['selected_rate_plan_max_occupancy']) ? (int) $room['selected_rate_plan_max_occupancy'] : 0,
                ],
                'assigned_guests' => $roomGuests,
                'room' => $roomView,
                'pricing' => \is_array($pricing) ? $pricing : [],
            ];
        }

        return [
            'items' => $items,
            'summary' => $summary,
            'errors' => [],
            'room_guest_counts' => $roomGuestCounts,
        ];
    }
}
