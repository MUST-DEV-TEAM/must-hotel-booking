<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;
use MustHotelBooking\Core\RoomViewBuilder;

final class AvailabilityEngine
{
    public static function isValidBookingDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        return $parsed instanceof \DateTime && $parsed->format('Y-m-d') === $date;
    }

    public static function getAvailabilityNightsCount(string $checkin, string $checkout): int
    {
        if (!self::isValidBookingDate($checkin) || !self::isValidBookingDate($checkout) || $checkin >= $checkout) {
            return 0;
        }

        try {
            $start = new \DateTimeImmutable($checkin);
            $end = new \DateTimeImmutable($checkout);
        } catch (\Exception $exception) {
            return 0;
        }

        return (int) $start->diff($end)->days;
    }

    public static function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool
    {
        return !empty(AvailabilityRulesService::evaluateRestrictions($roomId, $checkin, $checkout)['allowed']);
    }

    public static function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        if ($roomId <= 0 || !self::isValidBookingDate($checkin) || !self::isValidBookingDate($checkout) || $checkin >= $checkout) {
            return false;
        }

        return !empty(AvailabilityRulesService::evaluateAvailability($roomId, $checkin, $checkout, $excludeSessionId)['bookable']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getAvailableRooms(string $checkin, string $checkout, int $guests = 1, string $category = 'standard-rooms'): array
    {
        if (!self::isValidBookingDate($checkin) || !self::isValidBookingDate($checkout) || $checkin >= $checkout) {
            return [];
        }

        $guests = \max(1, $guests);
        $category = RoomCatalog::normalizeBookingCategory($category);

        LockEngine::cleanupExpiredLocks();

        $sessionId = LockEngine::getOrCreateSessionId();
        $filtered = [];
        $roomTypes = get_room_repository()->getRoomsByType($category, $guests);

        foreach ($roomTypes as $roomType) {
            if (!\is_array($roomType)) {
                continue;
            }

            $roomId = isset($roomType['id']) ? (int) $roomType['id'] : 0;

            if ($roomId <= 0 || !self::checkBookingRestrictions($roomId, $checkin, $checkout)) {
                continue;
            }

            if (InventoryEngine::hasInventoryForRoomType($roomId)) {
                $availableCount = InventoryEngine::countAvailableRooms($roomId, $checkin, $checkout, $sessionId);

                if ($availableCount <= 0) {
                    continue;
                }

                $roomType['available_count'] = $availableCount;
                $filtered[] = $roomType;
                continue;
            }

            if (!self::checkAvailability($roomId, $checkin, $checkout, $sessionId)) {
                continue;
            }

            $roomType['available_count'] = 1;
            $filtered[] = $roomType;
        }

        return $filtered;
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     */
    public static function canRoomSetHostParty(array $rooms, int $guests, int $targetRoomCount): bool
    {
        $targetRoomCount = \max(1, $targetRoomCount);
        $capacities = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $capacity = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

            if ($capacity > 0) {
                $capacities[] = $capacity;
            }
        }

        if (\count($capacities) < $targetRoomCount) {
            return false;
        }

        \rsort($capacities, SORT_NUMERIC);

        return \array_sum(\array_slice($capacities, 0, $targetRoomCount)) >= \max(1, $guests);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function getAccommodationEmptyResultsMessage(array $context, int $resolvedRoomCount): string
    {
        $guests = \max(1, (int) ($context['guests'] ?? 1));
        $requestedRoomCount = (int) ($context['room_count'] ?? 0);
        $effectiveRoomCount = $requestedRoomCount > 0 ? $requestedRoomCount : $resolvedRoomCount;
        $roomLimit = BookingRules::getContextGuestLimit((string) ($context['accommodation_type'] ?? 'standard-rooms'), $effectiveRoomCount);

        if ($requestedRoomCount > 0 && $roomLimit > 0 && $guests > $roomLimit) {
            return \sprintf(
                /* translators: 1: guests count, 2: room count, 3: max supported guests. */
                \__('The selected combination of %1$d guests and %2$d rooms is not possible here. That room count can host up to %3$d guests. Increase the room count or reduce the number of guests.', 'must-hotel-booking'),
                $guests,
                $requestedRoomCount,
                $roomLimit
            );
        }

        if ($effectiveRoomCount > 0) {
            return \sprintf(
                /* translators: 1: guests count, 2: room count. */
                \__('No available combination of %2$d rooms can host %1$d guests for the selected dates.', 'must-hotel-booking'),
                $guests,
                $effectiveRoomCount
            );
        }

        return \sprintf(
            /* translators: %d is guest count. */
            \__('No rooms are available for %d guests on the selected dates.', 'must-hotel-booking'),
            $guests
        );
    }

    /**
     * @return array{message: string, tone: string}
     */
    public static function getAccommodationSelectionStatusData(
        int $selectedRoomCount,
        int $resolvedRoomCount,
        int $selectedCapacity,
        int $guests,
        bool $canContinue
    ): array {
        $selectedRoomCount = \max(0, $selectedRoomCount);
        $resolvedRoomCount = \max(1, $resolvedRoomCount);
        $selectedCapacity = \max(0, $selectedCapacity);
        $guests = \max(1, $guests);
        $remainingGuests = \max(0, $guests - $selectedCapacity);

        if ($canContinue) {
            return [
                'message' => \sprintf(
                    /* translators: 1: selected room count, 2: guest count. */
                    \__('Selected %1$d room(s). Your current selection can host all %2$d guests.', 'must-hotel-booking'),
                    $selectedRoomCount,
                    $guests
                ),
                'tone' => 'success',
            ];
        }

        if ($selectedRoomCount === 0) {
            return [
                'message' => \sprintf(
                    /* translators: 1: room count label, 2: guest count. */
                    \__('Choose %1$s that can host %2$d guests.', 'must-hotel-booking'),
                    \strtolower(BookingRules::formatRoomCountLabel($resolvedRoomCount)),
                    $guests
                ),
                'tone' => 'neutral',
            ];
        }

        if ($remainingGuests > 0 && $selectedRoomCount >= $resolvedRoomCount) {
            return [
                'message' => \sprintf(
                    /* translators: 1: selected room count, 2: selected capacity, 3: guest count. */
                    \__('Selected %1$d room(s). They currently host %2$d of %3$d guests. Increase the room count or choose rooms with more capacity.', 'must-hotel-booking'),
                    $selectedRoomCount,
                    $selectedCapacity,
                    $guests
                ),
                'tone' => 'warning',
            ];
        }

        if ($remainingGuests > 0) {
            return [
                'message' => \sprintf(
                    /* translators: 1: selected room count, 2: target room count, 3: remaining guest count. */
                    \__('Selected %1$d of %2$d room(s). %3$d guest(s) still need a room.', 'must-hotel-booking'),
                    $selectedRoomCount,
                    $resolvedRoomCount,
                    $remainingGuests
                ),
                'tone' => 'neutral',
            ];
        }

        return [
            'message' => \sprintf(
                /* translators: 1: remaining room count, 2: target room count. */
                \__('Selected rooms can host the full party. Choose %1$d more of %2$d room(s) to continue.', 'must-hotel-booking'),
                \max(0, $resolvedRoomCount - $selectedRoomCount),
                $resolvedRoomCount
            ),
            'tone' => 'neutral',
        ];
    }

    public static function getAccommodationContinueLabel(bool $canContinue, int $selectedRoomCount, int $resolvedRoomCount): string
    {
        if ($canContinue) {
            return \sprintf(
                /* translators: %d is selected room count. */
                \_n('Continue with %d Room', 'Continue with %d Rooms', $selectedRoomCount, 'must-hotel-booking'),
                $selectedRoomCount
            );
        }

        return \sprintf(
            /* translators: 1: selected room count, 2: target room count label. */
            \__('Selected %1$d / %2$s', 'must-hotel-booking'),
            $selectedRoomCount,
            BookingRules::formatRoomCountLabel($resolvedRoomCount)
        );
    }

    /**
     * @param array<int, int> $selectedRoomIds
     * @return array<int, array<string, mixed>>
     */
    public static function getAccommodationSelectedRoomItems(array $selectedRoomIds): array
    {
        $items = [];
        $selectedRatePlanMap = \MustHotelBooking\Frontend\get_booking_selected_room_rate_plan_map();

        foreach ($selectedRoomIds as $selectedRoomId) {
            $roomId = (int) $selectedRoomId;

            if ($roomId <= 0) {
                continue;
            }

            $room = RoomData::getRoom($roomId);

            if (!\is_array($room)) {
                continue;
            }

            $ratePlanId = isset($selectedRatePlanMap[$roomId]) ? (int) $selectedRatePlanMap[$roomId] : 0;
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

            $roomView = RoomViewBuilder::buildBookingResultsRoomViewData($room);

            if (\is_array($roomView)) {
                $items[] = $roomView;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<int, string>   $messages
     * @return array<string, mixed>
     */
    public static function getAccommodationSelectionState(array $context, array $messages = []): array
    {
        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();
        $selectedRatePlanMap = \MustHotelBooking\Frontend\get_booking_selected_room_rate_plan_map();
        $selectedRoomItems = self::getAccommodationSelectedRoomItems($selectedRoomIds);
        $requestedRoomCount = (int) ($context['room_count'] ?? 0);
        $resolvedRoomCount = BookingRules::resolveRoomCount(
            (int) ($context['guests'] ?? 1),
            $requestedRoomCount,
            (string) ($context['accommodation_type'] ?? 'standard-rooms')
        );
        $selectedCapacity = 0;

        foreach ($selectedRoomItems as $selectedRoomItem) {
            $selectedCapacity += isset($selectedRoomItem['effective_max_guests'])
                ? \max(0, (int) $selectedRoomItem['effective_max_guests'])
                : (isset($selectedRoomItem['max_guests']) ? \max(0, (int) $selectedRoomItem['max_guests']) : 0);
        }

        $remainingGuests = \max(0, (int) ($context['guests'] ?? 1) - $selectedCapacity);
        $canContinue = (string) ($context['checkin'] ?? '') !== ''
            && (string) ($context['checkout'] ?? '') !== ''
            && !empty($context['is_valid'])
            && \count($selectedRoomIds) === $resolvedRoomCount
            && $remainingGuests === 0;
        $selectionLimitReached = \count($selectedRoomIds) >= $resolvedRoomCount;
        $singleRoomMode = $resolvedRoomCount <= 1;
        $selectionStatus = self::getAccommodationSelectionStatusData(
            \count($selectedRoomIds),
            $resolvedRoomCount,
            $selectedCapacity,
            (int) ($context['guests'] ?? 1),
            $canContinue
        );

        return [
            'messages' => \array_values(\array_unique(\array_filter(\array_map('strval', $messages)))),
            'selected_room_ids' => $selectedRoomIds,
            'selected_room_rate_plans' => $selectedRatePlanMap,
            'selected_room_count' => \count($selectedRoomIds),
            'resolved_room_count' => $resolvedRoomCount,
            'selection_limit_reached' => $selectionLimitReached,
            'single_room_mode' => $singleRoomMode,
            'can_continue' => $canContinue,
            'continue_label' => self::getAccommodationContinueLabel($canContinue, \count($selectedRoomIds), $resolvedRoomCount),
            'checkout_url' => $canContinue ? \MustHotelBooking\Frontend\get_checkout_context_url($context) : '',
            'selection_status_message' => $singleRoomMode ? '' : (string) ($selectionStatus['message'] ?? ''),
            'selection_status_tone' => $singleRoomMode ? 'neutral' : (string) ($selectionStatus['tone'] ?? 'neutral'),
        ];
    }
}
