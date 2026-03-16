<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomViewBuilder;

final class AvailabilityAjaxController
{
    public static function normalize_disabled_dates_window_days(int $windowDays): int
    {
        if ($windowDays < 30) {
            return 30;
        }

        if ($windowDays > 365) {
            return 365;
        }

        return $windowDays;
    }

    public static function normalize_availability_room_category(string $category): string
    {
        return RoomCatalog::normalizeCategory($category);
    }

    /**
     * @return array<int, int>
     */
    public static function get_available_room_ids_for_guests(int $guests, string $category = 'standard-rooms'): array
    {
        return get_room_repository()->getRoomIdsByTypeAndGuests(
            self::normalize_availability_room_category($category),
            \max(1, $guests)
        );
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<int, int>
     */
    public static function get_room_capacity_map_for_room_ids(array $roomIds): array
    {
        $roomIds = \array_values(
            \array_filter(
                \array_map('\absint', $roomIds),
                static function (int $roomId): bool {
                    return $roomId > 0;
                }
            )
        );

        if (empty($roomIds)) {
            return [];
        }

        return get_room_repository()->getRoomCapacityMap($roomIds);
    }

    /**
     * @param array<int, int> $roomIds
     * @return array<int, array<int, array<string, string>>>
     */
    public static function get_reservation_ranges_by_room_ids(array $roomIds, string $rangeStart, string $rangeEndExclusive): array
    {
        $roomIds = \array_values(
            \array_filter(
                \array_map('\absint', $roomIds),
                static function (int $roomId): bool {
                    return $roomId > 0;
                }
            )
        );

        if (
            empty($roomIds) ||
            !AvailabilityEngine::isValidBookingDate($rangeStart) ||
            !AvailabilityEngine::isValidBookingDate($rangeEndExclusive) ||
            $rangeStart >= $rangeEndExclusive
        ) {
            return [];
        }

        $nonBlockingStatuses = ReservationStatus::getInventoryNonBlockingStatuses();
        $rangesByRoom = get_availability_repository()->getReservationRangesByRoomIds(
            $roomIds,
            $rangeStart,
            $rangeEndExclusive,
            $nonBlockingStatuses
        );

        LockEngine::cleanupExpiredLocks();
        $lockRangesByRoom = get_availability_repository()->getActiveLockRangesByRoomIds(
            $roomIds,
            $rangeStart,
            $rangeEndExclusive,
            LockEngine::getCurrentUtcDatetime(),
            LockEngine::getOrCreateSessionId()
        );

        foreach ($lockRangesByRoom as $roomId => $lockRanges) {
            $roomId = (int) $roomId;

            if ($roomId <= 0 || !\is_array($lockRanges)) {
                continue;
            }

            if (!isset($rangesByRoom[$roomId]) || !\is_array($rangesByRoom[$roomId])) {
                $rangesByRoom[$roomId] = [];
            }

            $rangesByRoom[$roomId] = \array_merge($rangesByRoom[$roomId], $lockRanges);
        }

        return $rangesByRoom;
    }

    /**
     * @param array<int, array<string, string>> $roomRanges
     */
    public static function is_room_free_for_range(array $roomRanges, string $checkin, string $checkout): bool
    {
        foreach ($roomRanges as $range) {
            if (!\is_array($range)) {
                continue;
            }

            $existingCheckin = isset($range['checkin']) ? (string) $range['checkin'] : '';
            $existingCheckout = isset($range['checkout']) ? (string) $range['checkout'] : '';

            if (
                !AvailabilityEngine::isValidBookingDate($existingCheckin) ||
                !AvailabilityEngine::isValidBookingDate($existingCheckout) ||
                $existingCheckin >= $existingCheckout
            ) {
                continue;
            }

            if ($existingCheckin < $checkout && $existingCheckout > $checkin) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, int> $roomIds
     * @param array<int, array<int, array<string, string>>> $rangesByRoom
     */
    public static function has_any_room_available_for_range(array $roomIds, array $rangesByRoom, string $checkin, string $checkout): bool
    {
        foreach ($roomIds as $roomId) {
            $roomId = (int) $roomId;

            if ($roomId <= 0) {
                continue;
            }

            $roomRanges = isset($rangesByRoom[$roomId]) && \is_array($rangesByRoom[$roomId]) ? $rangesByRoom[$roomId] : [];

            if (self::is_room_free_for_range($roomRanges, $checkin, $checkout)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, int> $roomIds
     * @param array<int, array<int, array<string, string>>> $rangesByRoom
     * @param array<int, int> $capacityMap
     */
    public static function can_room_ids_host_party_for_range(
        array $roomIds,
        array $rangesByRoom,
        array $capacityMap,
        string $checkin,
        string $checkout,
        int $guests,
        int $roomCount
    ): bool {
        $guests = \max(1, $guests);
        $roomCount = \max(1, $roomCount);
        $freeRoomCapacities = [];

        foreach ($roomIds as $roomId) {
            $roomId = (int) $roomId;

            if ($roomId <= 0) {
                continue;
            }

            $roomRanges = isset($rangesByRoom[$roomId]) && \is_array($rangesByRoom[$roomId]) ? $rangesByRoom[$roomId] : [];

            if (!self::is_room_free_for_range($roomRanges, $checkin, $checkout)) {
                continue;
            }

            $capacity = isset($capacityMap[$roomId]) ? (int) $capacityMap[$roomId] : 0;

            if ($capacity > 0) {
                $freeRoomCapacities[] = $capacity;
            }
        }

        if (\count($freeRoomCapacities) < $roomCount) {
            return false;
        }

        \rsort($freeRoomCapacities, SORT_NUMERIC);

        return \array_sum(\array_slice($freeRoomCapacities, 0, $roomCount)) >= $guests;
    }

    /**
     * @return array<int, string>
     */
    public static function get_disabled_checkin_dates_for_guests(int $guests, int $windowDays = 180, string $category = 'standard-rooms'): array
    {
        $windowDays = self::normalize_disabled_dates_window_days($windowDays);
        $startDate = \current_time('Y-m-d');
        $start = new \DateTimeImmutable($startDate);
        $rangeEndExclusive = $start->modify('+' . ($windowDays + 1) . ' day')->format('Y-m-d');
        $roomIds = self::get_available_room_ids_for_guests($guests, $category);
        $disabledDates = [];

        if (empty($roomIds)) {
            for ($index = 0; $index < $windowDays; $index++) {
                $disabledDates[] = $start->modify('+' . $index . ' day')->format('Y-m-d');
            }

            return $disabledDates;
        }

        $rangesByRoom = self::get_reservation_ranges_by_room_ids($roomIds, $startDate, $rangeEndExclusive);

        for ($index = 0; $index < $windowDays; $index++) {
            $checkin = $start->modify('+' . $index . ' day')->format('Y-m-d');
            $checkout = $start->modify('+' . ($index + 1) . ' day')->format('Y-m-d');

            if (!self::has_any_room_available_for_range($roomIds, $rangesByRoom, $checkin, $checkout)) {
                $disabledDates[] = $checkin;
            }
        }

        return $disabledDates;
    }

    /**
     * @return array<int, string>
     */
    public static function get_disabled_checkout_dates_for_guests(string $checkin, int $guests, int $windowDays = 180, string $category = 'standard-rooms'): array
    {
        if (!AvailabilityEngine::isValidBookingDate($checkin)) {
            return [];
        }

        $windowDays = self::normalize_disabled_dates_window_days($windowDays);
        $checkinDate = new \DateTimeImmutable($checkin);
        $rangeEndExclusive = $checkinDate->modify('+' . ($windowDays + 1) . ' day')->format('Y-m-d');
        $roomIds = self::get_available_room_ids_for_guests($guests, $category);
        $disabledDates = [];

        if (empty($roomIds)) {
            for ($nights = 1; $nights <= $windowDays; $nights++) {
                $disabledDates[] = $checkinDate->modify('+' . $nights . ' day')->format('Y-m-d');
            }

            return $disabledDates;
        }

        $rangesByRoom = self::get_reservation_ranges_by_room_ids($roomIds, $checkin, $rangeEndExclusive);

        for ($nights = 1; $nights <= $windowDays; $nights++) {
            $checkout = $checkinDate->modify('+' . $nights . ' day')->format('Y-m-d');

            if (!self::has_any_room_available_for_range($roomIds, $rangesByRoom, $checkin, $checkout)) {
                $disabledDates[] = $checkout;
            }
        }

        return $disabledDates;
    }

    /**
     * @return array<int, string>
     */
    public static function get_disabled_checkin_dates_for_party(int $guests, int $roomCount, int $windowDays = 180, string $category = 'standard-rooms'): array
    {
        $windowDays = self::normalize_disabled_dates_window_days($windowDays);
        $startDate = \current_time('Y-m-d');
        $start = new \DateTimeImmutable($startDate);
        $rangeEndExclusive = $start->modify('+' . ($windowDays + 1) . ' day')->format('Y-m-d');
        $roomIds = self::get_available_room_ids_for_guests(1, $category);
        $disabledDates = [];

        if (empty($roomIds)) {
            for ($index = 0; $index < $windowDays; $index++) {
                $disabledDates[] = $start->modify('+' . $index . ' day')->format('Y-m-d');
            }

            return $disabledDates;
        }

        $rangesByRoom = self::get_reservation_ranges_by_room_ids($roomIds, $startDate, $rangeEndExclusive);
        $capacityMap = self::get_room_capacity_map_for_room_ids($roomIds);

        for ($index = 0; $index < $windowDays; $index++) {
            $checkin = $start->modify('+' . $index . ' day')->format('Y-m-d');
            $checkout = $start->modify('+' . ($index + 1) . ' day')->format('Y-m-d');

            if (!self::can_room_ids_host_party_for_range($roomIds, $rangesByRoom, $capacityMap, $checkin, $checkout, $guests, $roomCount)) {
                $disabledDates[] = $checkin;
            }
        }

        return $disabledDates;
    }

    /**
     * @return array<int, string>
     */
    public static function get_disabled_checkout_dates_for_party(string $checkin, int $guests, int $roomCount, int $windowDays = 180, string $category = 'standard-rooms'): array
    {
        if (!AvailabilityEngine::isValidBookingDate($checkin)) {
            return [];
        }

        $windowDays = self::normalize_disabled_dates_window_days($windowDays);
        $checkinDate = new \DateTimeImmutable($checkin);
        $rangeEndExclusive = $checkinDate->modify('+' . ($windowDays + 1) . ' day')->format('Y-m-d');
        $roomIds = self::get_available_room_ids_for_guests(1, $category);
        $disabledDates = [];

        if (empty($roomIds)) {
            for ($nights = 1; $nights <= $windowDays; $nights++) {
                $disabledDates[] = $checkinDate->modify('+' . $nights . ' day')->format('Y-m-d');
            }

            return $disabledDates;
        }

        $rangesByRoom = self::get_reservation_ranges_by_room_ids($roomIds, $checkin, $rangeEndExclusive);
        $capacityMap = self::get_room_capacity_map_for_room_ids($roomIds);

        for ($nights = 1; $nights <= $windowDays; $nights++) {
            $checkout = $checkinDate->modify('+' . $nights . ' day')->format('Y-m-d');

            if (!self::can_room_ids_host_party_for_range($roomIds, $rangesByRoom, $capacityMap, $checkin, $checkout, $guests, $roomCount)) {
                $disabledDates[] = $checkout;
            }
        }

        return $disabledDates;
    }

    /**
     * @return array<int, string>
     */
    public static function get_disabled_checkin_dates_for_room(int $roomId, int $guests, int $windowDays = 180): array
    {
        $windowDays = self::normalize_disabled_dates_window_days($windowDays);
        $start = new \DateTimeImmutable(\current_time('Y-m-d'));
        $disabledDates = [];

        for ($index = 0; $index < $windowDays; $index++) {
            $checkin = $start->modify('+' . $index . ' day')->format('Y-m-d');
            $checkout = $start->modify('+' . ($index + 1) . ' day')->format('Y-m-d');

            if (!AvailabilityEngine::checkAvailability($roomId, $checkin, $checkout, LockEngine::getOrCreateSessionId())) {
                $disabledDates[] = $checkin;
            }
        }

        return $disabledDates;
    }

    /**
     * @return array<int, string>
     */
    public static function get_disabled_checkout_dates_for_room(string $checkin, int $roomId, int $guests, int $windowDays = 180): array
    {
        if (!AvailabilityEngine::isValidBookingDate($checkin)) {
            return [];
        }

        $windowDays = self::normalize_disabled_dates_window_days($windowDays);
        $checkinDate = new \DateTimeImmutable($checkin);
        $disabledDates = [];

        for ($nights = 1; $nights <= $windowDays; $nights++) {
            $checkout = $checkinDate->modify('+' . $nights . ' day')->format('Y-m-d');

            if (!AvailabilityEngine::checkAvailability($roomId, $checkin, $checkout, LockEngine::getOrCreateSessionId())) {
                $disabledDates[] = $checkout;
            }
        }

        return $disabledDates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get_available_room_for_ajax_by_id(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array
    {
        $room = get_room_repository()->getRoomById($roomId);

        if (!\is_array($room)) {
            return null;
        }

        $guests = \max(1, $guests);
        $roomMaxGuests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

        if ($roomMaxGuests > 0 && $guests > $roomMaxGuests) {
            return null;
        }

        if (!AvailabilityEngine::checkAvailability($roomId, $checkin, $checkout, LockEngine::getOrCreateSessionId())) {
            return null;
        }

        return $room;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function get_available_rooms_for_ajax(string $checkin, string $checkout, int $guests = 1, string $category = 'standard-rooms'): array
    {
        return AvailabilityEngine::getAvailableRooms($checkin, $checkout, $guests, $category);
    }

    public static function ajax_must_get_disabled_dates(): void
    {
        $maxBookingGuests = BookingRules::getMaxBookingGuestsLimit();
        $guests = isset($_REQUEST['guests']) ? \max(1, \min($maxBookingGuests, \absint(\wp_unslash($_REQUEST['guests'])))) : 1;
        $roomCount = BookingRules::normalizeRoomCount($_REQUEST['room_count'] ?? 0);
        $windowDays = isset($_REQUEST['window_days']) ? \absint(\wp_unslash($_REQUEST['window_days'])) : 180;
        $windowDays = self::normalize_disabled_dates_window_days($windowDays);
        $checkin = isset($_REQUEST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_REQUEST['checkin'])) : '';
        $roomId = isset($_REQUEST['room_id']) ? \absint(\wp_unslash($_REQUEST['room_id'])) : 0;
        $accommodationType = isset($_REQUEST['accommodation_type'])
            ? self::normalize_availability_room_category(\sanitize_text_field((string) \wp_unslash($_REQUEST['accommodation_type'])))
            : 'standard-rooms';

        if ($checkin !== '' && !AvailabilityEngine::isValidBookingDate($checkin)) {
            \wp_send_json_error(['message' => \__('Invalid check-in date.', 'must-hotel-booking')], 400);
        }

        if ($roomId > 0) {
            $disabledCheckinDates = self::get_disabled_checkin_dates_for_room($roomId, $guests, $windowDays);
            $disabledCheckoutDates = $checkin !== ''
                ? self::get_disabled_checkout_dates_for_room($checkin, $roomId, $guests, $windowDays)
                : [];
        } else {
            $resolvedRoomCount = BookingRules::resolveRoomCount($guests, $roomCount, $accommodationType);

            if ($resolvedRoomCount > 1) {
                $disabledCheckinDates = self::get_disabled_checkin_dates_for_party($guests, $resolvedRoomCount, $windowDays, $accommodationType);
                $disabledCheckoutDates = $checkin !== ''
                    ? self::get_disabled_checkout_dates_for_party($checkin, $guests, $resolvedRoomCount, $windowDays, $accommodationType)
                    : [];
            } else {
                $disabledCheckinDates = self::get_disabled_checkin_dates_for_guests($guests, $windowDays, $accommodationType);
                $disabledCheckoutDates = $checkin !== ''
                    ? self::get_disabled_checkout_dates_for_guests($checkin, $guests, $windowDays, $accommodationType)
                    : [];
            }
        }

        \wp_send_json_success([
            'room_id' => $roomId,
            'guests' => $guests,
            'room_count' => $roomCount,
            'checkin' => $checkin,
            'accommodation_type' => $accommodationType,
            'window_days' => $windowDays,
            'disabled_checkin_dates' => $disabledCheckinDates,
            'disabled_checkout_dates' => $disabledCheckoutDates,
        ]);
    }

    public static function ajax_must_check_availability(): void
    {
        $checkin = isset($_REQUEST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_REQUEST['checkin'])) : '';
        $checkout = isset($_REQUEST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_REQUEST['checkout'])) : '';
        $maxBookingGuests = BookingRules::getMaxBookingGuestsLimit();
        $guests = isset($_REQUEST['guests']) ? \max(1, \min($maxBookingGuests, \absint(\wp_unslash($_REQUEST['guests'])))) : 1;
        $roomCount = BookingRules::normalizeRoomCount($_REQUEST['room_count'] ?? 0);
        $roomId = isset($_REQUEST['room_id']) ? \absint(\wp_unslash($_REQUEST['room_id'])) : 0;
        $accommodationType = isset($_REQUEST['accommodation_type'])
            ? self::normalize_availability_room_category(\sanitize_text_field((string) \wp_unslash($_REQUEST['accommodation_type'])))
            : 'standard-rooms';

        if (!AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout) || $checkin >= $checkout) {
            \wp_send_json_error(['message' => \__('Invalid check-in/check-out dates.', 'must-hotel-booking')], 400);
        }

        $rooms = [];
        $message = '';
        $resolvedRoomCount = $roomId > 0
            ? 1
            : BookingRules::resolveRoomCount($guests, $roomCount, $accommodationType);

        if ($roomId > 0) {
            $room = self::get_available_room_for_ajax_by_id($roomId, $checkin, $checkout, $guests);

            if (\is_array($room)) {
                $rooms[] = $room;
            } else {
                $message = \__('The selected room is not available for the chosen dates and party size.', 'must-hotel-booking');
            }
        } else {
            $rooms = self::get_available_rooms_for_ajax(
                $checkin,
                $checkout,
                $resolvedRoomCount > 1 ? 1 : $guests,
                $accommodationType
            );

            if ($resolvedRoomCount > 1 && !AvailabilityEngine::canRoomSetHostParty($rooms, $guests, $resolvedRoomCount)) {
                $rooms = [];
            }

            if (empty($rooms)) {
                $message = AvailabilityEngine::getAccommodationEmptyResultsMessage(
                    [
                        'guests' => $guests,
                        'room_count' => $roomCount,
                        'accommodation_type' => $accommodationType,
                    ],
                    $resolvedRoomCount
                );
            }
        }

        $payload = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $currentRoomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($currentRoomId <= 0 || !AvailabilityEngine::checkBookingRestrictions($currentRoomId, $checkin, $checkout)) {
                continue;
            }

            $dynamicTotalPrice = null;
            $dynamicRoomSubtotal = null;
            $dynamicNights = null;
            $pricingGuests = $resolvedRoomCount > 1 ? 1 : $guests;
            $pricing = PricingEngine::calculateTotal($currentRoomId, $checkin, $checkout, $pricingGuests);

            if (\is_array($pricing) && !empty($pricing['success'])) {
                if (isset($pricing['total_price'])) {
                    $dynamicTotalPrice = (float) $pricing['total_price'];
                }

                if (isset($pricing['room_subtotal'])) {
                    $dynamicRoomSubtotal = (float) $pricing['room_subtotal'];
                }

                if (isset($pricing['nights'])) {
                    $dynamicNights = (int) $pricing['nights'];
                }
            }

            $roomPayload = [
                'id' => $currentRoomId,
                'name' => isset($room['name']) ? (string) $room['name'] : '',
                'slug' => isset($room['slug']) ? (string) $room['slug'] : '',
                'category' => isset($room['category']) ? (string) $room['category'] : 'standard-rooms',
                'description' => isset($room['description']) ? (string) $room['description'] : '',
                'max_guests' => isset($room['max_guests']) ? (int) $room['max_guests'] : 0,
                'base_price' => isset($room['base_price']) ? (float) $room['base_price'] : 0.0,
                'room_size' => isset($room['room_size']) ? (string) $room['room_size'] : '',
                'beds' => isset($room['beds']) ? (string) $room['beds'] : '',
                'calculated_price' => $dynamicTotalPrice,
                'dynamic_total_price' => $dynamicTotalPrice,
                'dynamic_room_subtotal' => $dynamicRoomSubtotal,
                'dynamic_nights' => $dynamicNights,
                'rate_plans' => RatePlanEngine::getRoomRatePlansWithPricing($currentRoomId, $checkin, $checkout, $pricingGuests),
            ];

            $enrichedPayload = RoomViewBuilder::buildBookingResultsRoomViewData($roomPayload);

            if ($enrichedPayload !== null) {
                $payload[] = $enrichedPayload;
                continue;
            }

            $payload[] = $roomPayload;
        }

        \wp_send_json_success([
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'room_count' => $roomCount,
            'accommodation_type' => $accommodationType,
            'message' => $message,
            'rooms' => $payload,
        ]);
    }

    public static function registerHooks(): void
    {
        \add_action('wp_ajax_must_check_availability', [self::class, 'ajax_must_check_availability']);
        \add_action('wp_ajax_nopriv_must_check_availability', [self::class, 'ajax_must_check_availability']);
        \add_action('wp_ajax_must_get_disabled_dates', [self::class, 'ajax_must_get_disabled_dates']);
        \add_action('wp_ajax_nopriv_must_get_disabled_dates', [self::class, 'ajax_must_get_disabled_dates']);
    }
}
