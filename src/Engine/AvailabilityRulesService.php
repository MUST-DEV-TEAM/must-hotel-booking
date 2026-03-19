<?php

namespace MustHotelBooking\Engine;

final class AvailabilityRulesService
{
    /**
     * @return array<string, mixed>
     */
    public static function evaluateRestrictions(int $roomId, string $checkin, string $checkout): array
    {
        if (
            $roomId <= 0 ||
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout
        ) {
            return [
                'allowed' => false,
                'nights' => 0,
                'reasons' => [\__('Invalid stay dates.', 'must-hotel-booking')],
                'rules' => [],
                'minimum_stay' => 0,
                'maximum_stay' => 0,
                'closed_arrival' => false,
                'closed_departure' => false,
                'maintenance_block' => false,
            ];
        }

        $nights = AvailabilityEngine::getAvailabilityNightsCount($checkin, $checkout);
        $rules = get_availability_repository()->getApplicableRules($roomId, $checkin, $checkout);
        $minimumStay = 0;
        $maximumStay = 0;
        $closedArrival = false;
        $closedDeparture = false;
        $maintenanceBlock = false;
        $reasons = [];

        foreach ($rules as $rule) {
            if (!\is_array($rule) || empty($rule['is_active'])) {
                continue;
            }

            $ruleType = \sanitize_key((string) ($rule['rule_type'] ?? ''));
            $startDate = (string) ($rule['availability_date'] ?? '');
            $endDate = (string) ($rule['end_date'] ?? $startDate);

            if ($startDate === '') {
                continue;
            }

            if ($endDate === '') {
                $endDate = $startDate;
            }

            if ($ruleType === 'minimum_stay') {
                $minimumStay = \max($minimumStay, \max(0, (int) ($rule['rule_value'] ?? 0)));
                continue;
            }

            if ($ruleType === 'maximum_stay') {
                $value = \max(0, (int) ($rule['rule_value'] ?? 0));

                if ($value > 0 && ($maximumStay === 0 || $value < $maximumStay)) {
                    $maximumStay = $value;
                }

                continue;
            }

            if ($ruleType === 'closed_arrival' && $checkin >= $startDate && $checkin <= $endDate) {
                $closedArrival = true;
                $reasons[] = \__('Check-in is not allowed on the selected arrival date.', 'must-hotel-booking');
                continue;
            }

            if ($ruleType === 'closed_departure' && $checkout >= $startDate && $checkout <= $endDate) {
                $closedDeparture = true;
                $reasons[] = \__('Check-out is not allowed on the selected departure date.', 'must-hotel-booking');
                continue;
            }

            if ($ruleType === 'maintenance_block' && $startDate < $checkout && $endDate >= $checkin) {
                $maintenanceBlock = true;
                $reasons[] = \__('The selected stay overlaps blocked availability dates.', 'must-hotel-booking');
            }
        }

        if ($minimumStay > 0 && $nights < $minimumStay) {
            $reasons[] = \sprintf(
                /* translators: %d is minimum nights. */
                \__('This stay requires at least %d nights.', 'must-hotel-booking'),
                $minimumStay
            );
        }

        if ($maximumStay > 0 && $nights > $maximumStay) {
            $reasons[] = \sprintf(
                /* translators: %d is maximum nights. */
                \__('This stay allows at most %d nights.', 'must-hotel-booking'),
                $maximumStay
            );
        }

        return [
            'allowed' => empty($reasons),
            'nights' => $nights,
            'reasons' => \array_values(\array_unique($reasons)),
            'rules' => $rules,
            'minimum_stay' => $minimumStay,
            'maximum_stay' => $maximumStay,
            'closed_arrival' => $closedArrival,
            'closed_departure' => $closedDeparture,
            'maintenance_block' => $maintenanceBlock,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function evaluateAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): array
    {
        $restrictionState = self::evaluateRestrictions($roomId, $checkin, $checkout);

        if (empty($restrictionState['allowed'])) {
            $restrictionState['bookable'] = false;
            return $restrictionState;
        }

        $excludeSessionId = $excludeSessionId !== '' ? LockEngine::normalizeSessionId($excludeSessionId) : '';

        if (InventoryEngine::hasInventoryForRoomType($roomId)) {
            $availableCount = InventoryEngine::countAvailableRooms($roomId, $checkin, $checkout, $excludeSessionId);
            $restrictionState['available_units'] = $availableCount;
            $restrictionState['bookable'] = $availableCount > 0;

            if ($availableCount <= 0) {
                $restrictionState['reasons'][] = \__('No sellable inventory is available for the selected dates.', 'must-hotel-booking');
            }

            return $restrictionState;
        }

        $available = get_availability_repository()->checkRoomAvailability(
            $roomId,
            $checkin,
            $checkout,
            \MustHotelBooking\Core\ReservationStatus::getInventoryNonBlockingStatuses(),
            LockEngine::getCurrentUtcDatetime(),
            $excludeSessionId
        );

        $restrictionState['bookable'] = $available;

        if (!$available) {
            $restrictionState['reasons'][] = \__('This accommodation is already unavailable for the selected dates.', 'must-hotel-booking');
        }

        return $restrictionState;
    }
}
