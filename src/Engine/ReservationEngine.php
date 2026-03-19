<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\ReservationStatus;

final class ReservationEngine
{
    /**
     * @param array<string, mixed> $guestForm
     */
    public static function buildReservationNote(int $roomId, array $guestForm): string
    {
        $sections = [];
        $specialRequests = isset($guestForm['special_requests']) ? \trim((string) $guestForm['special_requests']) : '';
        $roomGuests = isset($guestForm['room_guests']) && \is_array($guestForm['room_guests']) ? $guestForm['room_guests'] : [];
        $roomGuest = isset($roomGuests[$roomId]) && \is_array($roomGuests[$roomId]) ? $roomGuests[$roomId] : [];
        $roomGuestLines = [];

        if ($specialRequests !== '') {
            $sections[] = \sprintf(
                /* translators: %s is the submitted special requests text. */
                \__('Special Requests: %s', 'must-hotel-booking'),
                $specialRequests
            );
        }

        $roomGuestCount = isset($roomGuest['guest_count']) ? \trim((string) $roomGuest['guest_count']) : '';
        $roomGuestFirstName = isset($roomGuest['first_name']) ? \trim((string) $roomGuest['first_name']) : '';
        $roomGuestLastName = isset($roomGuest['last_name']) ? \trim((string) $roomGuest['last_name']) : '';

        if ($roomGuestCount !== '') {
            $roomGuestLines[] = \sprintf(
                /* translators: %s is room guest count. */
                \__('Guests Number: %s', 'must-hotel-booking'),
                $roomGuestCount
            );
        }

        if ($roomGuestFirstName !== '') {
            $roomGuestLines[] = \sprintf(
                /* translators: %s is room guest first name. */
                \__('First Name: %s', 'must-hotel-booking'),
                $roomGuestFirstName
            );
        }

        if ($roomGuestLastName !== '') {
            $roomGuestLines[] = \sprintf(
                /* translators: %s is room guest last name. */
                \__('Last Name: %s', 'must-hotel-booking'),
                $roomGuestLastName
            );
        }

        if (!empty($roomGuestLines)) {
            $sections[] = \__('Room Guest Information:', 'must-hotel-booking') . "\n" . \implode("\n", $roomGuestLines);
        }

        if (!empty($guestForm['marketing_opt_in'])) {
            $sections[] = \__('Marketing Consent: Yes', 'must-hotel-booking');
        }

        $billingLines = [];
        $billingFields = [
            'company' => \__('Company Name', 'must-hotel-booking'),
            'street_address' => \__('Street Address', 'must-hotel-booking'),
            'address_line_2' => \__('Apartment, Suite, Unit, etc.', 'must-hotel-booking'),
            'city' => \__('Town / City', 'must-hotel-booking'),
            'county' => \__('County', 'must-hotel-booking'),
            'postcode' => \__('Postcode / ZIP', 'must-hotel-booking'),
        ];

        foreach ($billingFields as $fieldKey => $fieldLabel) {
            $fieldValue = isset($guestForm[$fieldKey]) ? \trim((string) $guestForm[$fieldKey]) : '';

            if ($fieldValue !== '') {
                $billingLines[] = $fieldLabel . ': ' . $fieldValue;
            }
        }

        $billingCountry = isset($guestForm['billing_country']) ? \trim((string) $guestForm['billing_country']) : '';

        if ($billingCountry !== '') {
            $billingLines[] = \__('Billing Country', 'must-hotel-booking') . ': ' . \MustHotelBooking\Frontend\get_checkout_country_name($billingCountry);
        }

        if (!empty($billingLines)) {
            $sections[] = \__('Billing Information:', 'must-hotel-booking') . "\n" . \implode("\n", $billingLines);
        }

        return \trim(\implode("\n\n", $sections));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCheckoutRoomData(int $roomId): ?array
    {
        if ($roomId <= 0) {
            return null;
        }

        return get_room_repository()->getRoomById($roomId);
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @param array<string, mixed>             $guestForm
     * @return array{counts: array<int, int>, errors: array<int, string>}
     */
    public static function getRoomGuestAllocations(array $rooms, int $totalGuests, array $guestForm = [], bool $strict = false): array
    {
        $totalGuests = \max(1, $totalGuests);
        $roomCount = \count($rooms);
        $counts = [];
        $errors = [];

        if ($roomCount === 0) {
            return [
                'counts' => [],
                'errors' => $errors,
            ];
        }

        if ($roomCount > $totalGuests) {
            return [
                'counts' => [],
                'errors' => [\__('You cannot assign more rooms than guests. Reduce the room count or increase the party size.', 'must-hotel-booking')],
            ];
        }

        $requestedRoomGuests = isset($guestForm['room_guests']) && \is_array($guestForm['room_guests']) ? $guestForm['room_guests'] : [];
        $hasRequestedCounts = false;

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $requestedGuestCount = isset($requestedRoomGuests[$roomId]['guest_count'])
                ? \absint((string) $requestedRoomGuests[$roomId]['guest_count'])
                : 0;

            if ($requestedGuestCount > 0) {
                $counts[$roomId] = $requestedGuestCount;
                $hasRequestedCounts = true;
            }
        }

        if ($hasRequestedCounts) {
            $assignedTotal = 0;

            foreach ($rooms as $room) {
                if (!\is_array($room)) {
                    continue;
                }

                $roomId = isset($room['id']) ? (int) $room['id'] : 0;
                $roomName = isset($room['name']) ? (string) $room['name'] : \__('Room', 'must-hotel-booking');
                $roomMaxGuests = isset($room['effective_max_guests'])
                    ? \max(1, (int) $room['effective_max_guests'])
                    : (isset($room['max_guests']) ? \max(1, (int) $room['max_guests']) : 1);
                $assignedGuests = isset($counts[$roomId]) ? (int) $counts[$roomId] : 0;

                if ($assignedGuests <= 0) {
                    $errors[] = \sprintf(
                        /* translators: %s is room name. */
                        \__('Please assign guests for %s.', 'must-hotel-booking'),
                        $roomName
                    );
                    continue;
                }

                if ($assignedGuests > $roomMaxGuests) {
                    $errors[] = \sprintf(
                        /* translators: 1: room name, 2: room capacity. */
                        \__('%1$s can host a maximum of %2$d guests.', 'must-hotel-booking'),
                        $roomName,
                        $roomMaxGuests
                    );
                    continue;
                }

                $assignedTotal += $assignedGuests;
            }

            if ($assignedTotal !== $totalGuests) {
                $errors[] = \sprintf(
                    /* translators: %d is requested guest count. */
                    \__('Room guest counts must add up to %d guests.', 'must-hotel-booking'),
                    $totalGuests
                );
            }

            if ($strict || empty($errors)) {
                return [
                    'counts' => $counts,
                    'errors' => $errors,
                ];
            }

            $counts = [];
        }

        $remainingGuests = $totalGuests;

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $counts[$roomId] = 1;
            $remainingGuests--;
        }

        if ($remainingGuests < 0) {
            return [
                'counts' => [],
                'errors' => [\__('You cannot assign more rooms than guests. Reduce the room count or increase the party size.', 'must-hotel-booking')],
            ];
        }

        $roomsByCapacity = $rooms;
        \usort(
            $roomsByCapacity,
            static function (array $left, array $right): int {
                return ((int) ($right['max_guests'] ?? 0)) <=> ((int) ($left['max_guests'] ?? 0));
            }
        );

        foreach ($roomsByCapacity as $room) {
            if ($remainingGuests <= 0) {
                break;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $roomMaxGuests = isset($room['effective_max_guests'])
                ? \max(1, (int) $room['effective_max_guests'])
                : (isset($room['max_guests']) ? \max(1, (int) $room['max_guests']) : 1);
            $alreadyAssigned = isset($counts[$roomId]) ? (int) $counts[$roomId] : 1;
            $remainingCapacity = \max(0, $roomMaxGuests - $alreadyAssigned);

            if ($roomId <= 0 || $remainingCapacity <= 0) {
                continue;
            }

            $increment = \min($remainingGuests, $remainingCapacity);
            $counts[$roomId] = $alreadyAssigned + $increment;
            $remainingGuests -= $increment;
        }

        if ($remainingGuests > 0) {
            return [
                'counts' => [],
                'errors' => [\__('The selected room combination cannot host the full party size.', 'must-hotel-booking')],
            ];
        }

        return [
            'counts' => $counts,
            'errors' => $errors,
        ];
    }

    public static function ensureRoomLock(int $roomId, string $checkin, string $checkout): bool
    {
        if ($roomId <= 0) {
            return false;
        }

        $sessionId = LockEngine::getOrCreateSessionId();

        if ($sessionId === '') {
            return false;
        }

        if (InventoryEngine::hasInventoryForRoomType($roomId)) {
            return \is_array(InventoryEngine::lockRoomType($roomId, $checkin, $checkout, $sessionId));
        }

        if (LockEngine::hasExactLock($roomId, $checkin, $checkout, $sessionId)) {
            return true;
        }

        return LockEngine::createLock($roomId, $checkin, $checkout, $sessionId);
    }

    /**
     * @param array<int, int> $roomIds
     */
    public static function ensureRoomLocks(array $roomIds, string $checkin, string $checkout): bool
    {
        foreach ($roomIds as $roomId) {
            if (!self::ensureRoomLock((int) $roomId, $checkin, $checkout)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $guestForm
     */
    public static function createGuest(array $guestForm): int
    {
        return get_guest_repository()->createGuest(
            [
                'first_name' => (string) ($guestForm['first_name'] ?? ''),
                'last_name' => (string) ($guestForm['last_name'] ?? ''),
                'email' => (string) ($guestForm['email'] ?? ''),
                'phone' => \MustHotelBooking\Frontend\combine_checkout_phone_value($guestForm),
                'country' => \MustHotelBooking\Frontend\get_checkout_country_name((string) ($guestForm['country'] ?? '')),
            ]
        );
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, string>
     */
    public static function bootstrapCheckoutSelectionFromRequest(array $source): array
    {
        $roomId = isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0;
        $ratePlanId = isset($source['rate_plan_id']) ? \absint(\wp_unslash($source['rate_plan_id'])) : 0;

        if ($roomId <= 0) {
            return [];
        }

        $room = self::getCheckoutRoomData($roomId);

        if (!\is_array($room)) {
            return [\__('The selected room could not be found.', 'must-hotel-booking')];
        }

        $context = BookingValidationEngine::parseRequestContext($source, true);
        $context = BookingValidationEngine::applyFixedRoomContext($context, $room);

        if (empty($context['is_valid'])) {
            return (array) ($context['errors'] ?? []);
        }

        if ($ratePlanId > 0 && !\is_array(RatePlanEngine::getRoomRatePlan($roomId, $ratePlanId))) {
            return [\__('The selected rate plan is no longer available for this room.', 'must-hotel-booking')];
        }

        $selection = \MustHotelBooking\Frontend\get_booking_selection();
        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();
        $selectedRatePlanMap = \MustHotelBooking\Frontend\get_booking_selected_room_rate_plan_map();
        $hasSameFixedRoomSelection =
            (string) ($selection['flow_data']['booking_mode'] ?? '') === 'fixed-room' &&
            (int) ($selection['flow_data']['fixed_room_id'] ?? 0) === $roomId &&
            \count($selectedRoomIds) === 1 &&
            (int) $selectedRoomIds[0] === $roomId &&
            (int) ($selectedRatePlanMap[$roomId] ?? 0) === $ratePlanId &&
            \MustHotelBooking\Frontend\do_booking_selection_contexts_match($selection['context'] ?? [], $context);

        if (!$hasSameFixedRoomSelection) {
            \MustHotelBooking\Frontend\clear_booking_selection();
        }

        if (!self::ensureRoomLock($roomId, (string) $context['checkin'], (string) $context['checkout'])) {
            return [\__('The room is no longer available for the selected dates.', 'must-hotel-booking')];
        }

        $added = \MustHotelBooking\Frontend\add_room_to_booking_selection(
            $roomId,
            [
                'checkin' => (string) $context['checkin'],
                'checkout' => (string) $context['checkout'],
                'guests' => (int) $context['guests'],
                'room_count' => (int) ($context['room_count'] ?? 1),
                'accommodation_type' => (string) $context['accommodation_type'],
            ],
            $ratePlanId
        );

        if (!$added) {
            return [\__('Unable to store the selected room.', 'must-hotel-booking')];
        }

        \MustHotelBooking\Frontend\update_booking_selection_flow_data([
            'booking_mode' => 'fixed-room',
            'fixed_room_id' => $roomId,
        ]);

        return [];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function handleBookingRoomSelectionRequest(array $source): array
    {
        $action = isset($source['must_booking_action']) ? \sanitize_key((string) \wp_unslash($source['must_booking_action'])) : '';

        if ($action !== 'select_room') {
            return [
                'handled' => false,
                'success' => false,
                'message' => '',
                'redirect_url' => '',
            ];
        }

        $nonce = isset($source['must_booking_nonce']) ? (string) \wp_unslash($source['must_booking_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_booking_select_room')) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('Security check failed. Please try again.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }

        $roomId = isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0;
        $ratePlanId = isset($source['rate_plan_id']) ? \absint(\wp_unslash($source['rate_plan_id'])) : 0;
        $context = BookingValidationEngine::parseRequestContext($source, true);

        if (empty($context['is_valid'])) {
            $firstError = isset($context['errors'][0]) ? (string) $context['errors'][0] : '';

            return [
                'handled' => true,
                'success' => false,
                'message' => $firstError !== '' ? $firstError : \__('Booking request is invalid.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }

        if ($roomId <= 0) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('Please select a room to continue.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }

        if ($ratePlanId > 0 && !\is_array(RatePlanEngine::getRoomRatePlan($roomId, $ratePlanId))) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('The selected rate plan is no longer available for this room.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }

        $lockCreated = InventoryEngine::hasInventoryForRoomType($roomId)
            ? \is_array(
                InventoryEngine::lockRoomType(
                    $roomId,
                    (string) $context['checkin'],
                    (string) $context['checkout'],
                    LockEngine::getOrCreateSessionId()
                )
            )
            : LockEngine::createLock(
                $roomId,
                (string) $context['checkin'],
                (string) $context['checkout'],
                LockEngine::getOrCreateSessionId()
            );

        if (!$lockCreated) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('This room is no longer available for the selected dates.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }

        return [
            'handled' => true,
            'success' => true,
            'message' => '',
            'redirect_url' => \add_query_arg(
                [
                    'room_id' => $roomId,
                    'checkin' => (string) $context['checkin'],
                    'checkout' => (string) $context['checkout'],
                    'guests' => (int) $context['guests'],
                    'room_count' => (int) ($context['room_count'] ?? 1),
                    'accommodation_type' => (string) $context['accommodation_type'],
                    'rate_plan_id' => $ratePlanId,
                ],
                \MustHotelBooking\Frontend\get_checkout_page_url()
            ),
        ];
    }

    /**
     * @param array<string, mixed> $requestSource
     * @return array<string, mixed>
     */
    public static function handleAccommodationRoomSelectionRequest(array $requestSource): array
    {
        $action = isset($requestSource['must_accommodation_action'])
            ? \sanitize_key((string) \wp_unslash($requestSource['must_accommodation_action']))
            : '';

        if (!\in_array($action, ['select_room', 'remove_selected_room'], true)) {
            return [
                'success' => false,
                'messages' => [],
                'context' => BookingValidationEngine::parseRequestContext($requestSource, false),
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        $roomId = isset($requestSource['room_id']) ? \absint(\wp_unslash($requestSource['room_id'])) : 0;
        $ratePlanId = isset($requestSource['rate_plan_id']) ? \absint(\wp_unslash($requestSource['rate_plan_id'])) : 0;
        $context = BookingValidationEngine::parseRequestContext($requestSource, true);

        if (empty($context['is_valid'])) {
            return [
                'success' => false,
                'messages' => (array) ($context['errors'] ?? []),
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        if ($roomId <= 0) {
            return [
                'success' => false,
                'messages' => [\__('Please select a room to continue.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        if ($action === 'select_room' && $ratePlanId > 0 && !\is_array(RatePlanEngine::getRoomRatePlan($roomId, $ratePlanId))) {
            return [
                'success' => false,
                'messages' => [\__('The selected rate plan is no longer available for this room.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        $nonce = isset($requestSource['must_accommodation_nonce']) ? (string) \wp_unslash($requestSource['must_accommodation_nonce']) : '';
        $nonceAction = $action === 'remove_selected_room'
            ? 'must_accommodation_remove_room_' . $roomId
            : 'must_accommodation_select_room';

        if (!\wp_verify_nonce($nonce, $nonceAction)) {
            return [
                'success' => false,
                'messages' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        if (\MustHotelBooking\Frontend\is_fixed_room_booking_flow()) {
            \MustHotelBooking\Frontend\clear_booking_selection();
        }

        if ($action === 'remove_selected_room') {
            if (!\MustHotelBooking\Frontend\remove_room_from_booking_selection($roomId)) {
                return [
                    'success' => false,
                    'messages' => [\__('Unable to remove the selected room.', 'must-hotel-booking')],
                    'context' => $context,
                    'redirect_url' => '',
                    'should_redirect' => false,
                ];
            }

            return [
                'success' => true,
                'messages' => [],
                'context' => $context,
                'redirect_url' => \MustHotelBooking\Frontend\get_booking_accommodation_context_url($context),
                'should_redirect' => false,
            ];
        }

        $targetRoomCount = BookingRules::resolveRoomCount(
            (int) ($context['guests'] ?? 1),
            (int) ($context['room_count'] ?? 0),
            (string) ($context['accommodation_type'] ?? 'standard-rooms')
        );
        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();

        if (!\in_array($roomId, $selectedRoomIds, true) && \count($selectedRoomIds) >= $targetRoomCount) {
            return [
                'success' => false,
                'messages' => [\__('You have already selected the maximum number of rooms for this stay. Remove one before adding another.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        $lockCreated = \in_array($roomId, $selectedRoomIds, true)
            ? true
            : (
                InventoryEngine::hasInventoryForRoomType($roomId)
                    ? \is_array(
                        InventoryEngine::lockRoomType(
                            $roomId,
                            (string) $context['checkin'],
                            (string) $context['checkout'],
                            LockEngine::getOrCreateSessionId()
                        )
                    )
                    : LockEngine::createLock(
                        $roomId,
                        (string) $context['checkin'],
                        (string) $context['checkout'],
                        LockEngine::getOrCreateSessionId()
                    )
            );

        if (!$lockCreated) {
            return [
                'success' => false,
                'messages' => [\__('This room is no longer available for the selected dates.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        $selectionAdded = \MustHotelBooking\Frontend\add_room_to_booking_selection(
            $roomId,
            [
                'checkin' => (string) $context['checkin'],
                'checkout' => (string) $context['checkout'],
                'guests' => (int) $context['guests'],
                'room_count' => (int) ($context['room_count'] ?? 0),
                'accommodation_type' => (string) $context['accommodation_type'],
            ],
            $ratePlanId
        );

        if (!$selectionAdded) {
            return [
                'success' => false,
                'messages' => [\__('Unable to store the selected room.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }

        \MustHotelBooking\Frontend\update_booking_selection_flow_data([
            'booking_mode' => '',
            'fixed_room_id' => 0,
        ]);

        if ($targetRoomCount <= 1) {
            return [
                'success' => true,
                'messages' => [],
                'context' => $context,
                'redirect_url' => \MustHotelBooking\Frontend\get_checkout_context_url($context),
                'should_redirect' => true,
            ];
        }

        return [
            'success' => true,
            'messages' => [],
            'context' => $context,
            'redirect_url' => \MustHotelBooking\Frontend\get_booking_accommodation_context_url($context),
            'should_redirect' => false,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @return array<string, mixed>
     */
    public static function continueCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        if (empty($context['is_valid'])) {
            return [
                'success' => false,
                'errors' => (array) ($context['errors'] ?? []),
                'redirect_url' => '',
            ];
        }

        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();
        $selectedRatePlanMap = \MustHotelBooking\Frontend\get_booking_selected_room_rate_plan_map();

        if (empty($selectedRoomIds)) {
            return [
                'success' => false,
                'errors' => [\__('Please select at least one room before continuing.', 'must-hotel-booking')],
                'redirect_url' => '',
            ];
        }

        $validationErrors = BookingValidationEngine::validateGuestForm($guestForm);

        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'errors' => $validationErrors,
                'redirect_url' => '',
            ];
        }

        $roomItemsPreview = PricingEngine::buildCheckoutRoomItems($context, $couponCode, $guestForm, true);

        if (!empty($roomItemsPreview['errors'])) {
            return [
                'success' => false,
                'errors' => (array) $roomItemsPreview['errors'],
                'redirect_url' => '',
            ];
        }

        if (!self::ensureRoomLocks($selectedRoomIds, (string) $context['checkin'], (string) $context['checkout'])) {
            return [
                'success' => false,
                'errors' => [\__('One or more selected room locks have expired. Please return to accommodation and confirm them again.', 'must-hotel-booking')],
                'redirect_url' => '',
            ];
        }

        \MustHotelBooking\Frontend\update_booking_selection_flow_data([
            'guest_form' => $guestForm,
            'coupon_code' => $couponCode,
        ]);

        return [
            'success' => true,
            'errors' => [],
            'redirect_url' => ManagedPages::getBookingConfirmationPageUrl(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @param array<string, mixed> $options
     * @return array{errors: array<int, string>, reservation_ids: array<int, int>, applied_coupon_ids: array<int, int>}
     */
    public static function createReservations(array $context, array $guestForm, string $couponCode = '', array $options = []): array
    {
        $reservationStatus = isset($options['reservation_status'])
            ? \sanitize_key((string) $options['reservation_status'])
            : 'pending';
        $paymentStatus = isset($options['payment_status'])
            ? \sanitize_key((string) $options['payment_status'])
            : 'pending';
        $clearSelection = !isset($options['clear_selection']) || (bool) $options['clear_selection'];

        if (empty($context['is_valid'])) {
            return [
                'errors' => (array) ($context['errors'] ?? []),
                'reservation_ids' => [],
                'applied_coupon_ids' => [],
            ];
        }

        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();

        if (empty($selectedRoomIds)) {
            return [
                'errors' => [\__('Please select at least one room before continuing.', 'must-hotel-booking')],
                'reservation_ids' => [],
                'applied_coupon_ids' => [],
            ];
        }

        $validationErrors = BookingValidationEngine::validateGuestForm($guestForm);

        if (!empty($validationErrors)) {
            return [
                'errors' => $validationErrors,
                'reservation_ids' => [],
                'applied_coupon_ids' => [],
            ];
        }

        $roomItemsPreview = PricingEngine::buildCheckoutRoomItems($context, $couponCode, $guestForm, true);

        if (!empty($roomItemsPreview['errors'])) {
            return [
                'errors' => (array) $roomItemsPreview['errors'],
                'reservation_ids' => [],
                'applied_coupon_ids' => [],
            ];
        }

        $roomGuestCounts = isset($roomItemsPreview['room_guest_counts']) && \is_array($roomItemsPreview['room_guest_counts'])
            ? $roomItemsPreview['room_guest_counts']
            : [];
        $validatedRooms = [];
        $sessionId = LockEngine::getOrCreateSessionId();

        foreach ($selectedRoomIds as $roomId) {
            if (!self::ensureRoomLock((int) $roomId, (string) $context['checkin'], (string) $context['checkout'])) {
                return [
                    'errors' => [\__('One of your selected room locks has expired. Please return to accommodation and confirm your selection again.', 'must-hotel-booking')],
                    'reservation_ids' => [],
                    'applied_coupon_ids' => [],
                ];
            }

            if (!AvailabilityEngine::checkAvailability((int) $roomId, (string) $context['checkin'], (string) $context['checkout'], $sessionId)) {
                return [
                    'errors' => [\__('One of your selected rooms is no longer available for the selected dates.', 'must-hotel-booking')],
                    'reservation_ids' => [],
                    'applied_coupon_ids' => [],
                ];
            }

            $pricing = PricingEngine::calculateTotal(
                (int) $roomId,
                (string) $context['checkin'],
                (string) $context['checkout'],
                isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : (int) $context['guests'],
                $couponCode,
                isset($selectedRatePlanMap[$roomId]) ? (int) $selectedRatePlanMap[$roomId] : 0
            );

            if (!\is_array($pricing) || empty($pricing['success']) || !isset($pricing['total_price'])) {
                return [
                    'errors' => [\__('Unable to calculate final booking total for one of the selected rooms.', 'must-hotel-booking')],
                    'reservation_ids' => [],
                    'applied_coupon_ids' => [],
                ];
            }

            $validatedRooms[] = [
                'room_id' => (int) $roomId,
                'rate_plan_id' => isset($selectedRatePlanMap[$roomId]) ? (int) $selectedRatePlanMap[$roomId] : 0,
                'guests' => isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : (int) $context['guests'],
                'pricing' => $pricing,
            ];
        }

        $guestId = self::createGuest($guestForm);

        if ($guestId <= 0) {
            return [
                'errors' => [\__('Unable to save guest details.', 'must-hotel-booking')],
                'reservation_ids' => [],
                'applied_coupon_ids' => [],
            ];
        }

        $reservationRepository = get_reservation_repository();
        $transactionStarted = $reservationRepository->beginTransaction();
        $reservationIds = [];
        $appliedCouponIds = [];

        foreach ($validatedRooms as $validatedRoom) {
            $roomId = isset($validatedRoom['room_id']) ? (int) $validatedRoom['room_id'] : 0;
            $ratePlanId = isset($validatedRoom['rate_plan_id']) ? (int) $validatedRoom['rate_plan_id'] : 0;
            $roomGuests = isset($validatedRoom['guests']) ? (int) $validatedRoom['guests'] : (int) $context['guests'];
            $pricing = isset($validatedRoom['pricing']) && \is_array($validatedRoom['pricing']) ? $validatedRoom['pricing'] : [];

            $reservationId = self::createReservation(
                $roomId,
                $guestId,
                (string) $context['checkin'],
                (string) $context['checkout'],
                $roomGuests,
                (float) ($pricing['total_price'] ?? 0.0),
                $paymentStatus,
                $reservationStatus,
                $ratePlanId
            );

            if ($reservationId <= 0) {
                if ($transactionStarted) {
                    $reservationRepository->rollback();
                }

                return [
                    'errors' => [\__('Unable to complete the reservation for one of the selected rooms.', 'must-hotel-booking')],
                    'reservation_ids' => [],
                    'applied_coupon_ids' => [],
                ];
            }

            $reservationNote = self::buildReservationNote($roomId, $guestForm);
            $cancellationPolicy = $ratePlanId > 0 ? CancellationEngine::getCancellationPolicy($ratePlanId) : null;

            if (\is_array($cancellationPolicy) && (string) ($cancellationPolicy['name'] ?? '') !== '') {
                $reservationNote = \trim($reservationNote);
                $reservationNote .= ($reservationNote !== '' ? "\n\n" : '')
                    . \sprintf(
                        /* translators: %s is cancellation policy name. */
                        \__('Cancellation Policy: %s', 'must-hotel-booking'),
                        (string) $cancellationPolicy['name']
                    );
            }

            if ($reservationNote !== '') {
                $reservationRepository->updateReservationNotes($reservationId, $reservationNote);
            }

            $appliedCouponId = isset($pricing['applied_coupon_id']) ? (int) $pricing['applied_coupon_id'] : 0;
            $appliedCouponCode = isset($pricing['applied_coupon']) ? (string) $pricing['applied_coupon'] : '';
            $couponDiscountTotal = isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0;

            if ($appliedCouponId > 0) {
                $appliedCouponIds[$appliedCouponId] = $appliedCouponId;
            }

            if ($appliedCouponId > 0 || $appliedCouponCode !== '' || $couponDiscountTotal > 0.0) {
                $reservationRepository->updateReservationCouponData(
                    $reservationId,
                    $appliedCouponId,
                    $appliedCouponCode,
                    $couponDiscountTotal
                );
            }

            $reservationIds[] = $reservationId;
        }

        if ($transactionStarted) {
            $reservationRepository->commit();
        }

        if ($clearSelection) {
            \MustHotelBooking\Frontend\clear_booking_selection(false);
        }

        return [
            'errors' => [],
            'reservation_ids' => $reservationIds,
            'applied_coupon_ids' => \array_values($appliedCouponIds),
        ];
    }

    public static function createReservation(
        int $roomId,
        int $guestId,
        string $checkin,
        string $checkout,
        int $guests,
        float $totalPrice,
        string $paymentStatus = 'pending',
        string $reservationStatus = 'pending',
        int $ratePlanId = 0
    ): int {
        if (
            $roomId <= 0 ||
            $guestId <= 0 ||
            $guests <= 0 ||
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout
        ) {
            return 0;
        }

        LockEngine::cleanupExpiredLocks();

        $sessionId = LockEngine::getOrCreateSessionId();
        $inventoryRoomId = 0;

        if (InventoryEngine::hasInventoryForRoomType($roomId)) {
            $lockedInventoryRoom = InventoryEngine::getLockedRoomForType($roomId, $checkin, $checkout, $sessionId);

            if (!\is_array($lockedInventoryRoom)) {
                return 0;
            }

            $inventoryRoomId = isset($lockedInventoryRoom['id']) ? (int) $lockedInventoryRoom['id'] : 0;

            if ($inventoryRoomId <= 0) {
                return 0;
            }
        } elseif (!LockEngine::hasExactLock($roomId, $checkin, $checkout, $sessionId)) {
            return 0;
        }

        $now = LockEngine::getCurrentUtcDatetime();
        $reservationId = get_reservation_repository()->createReservationFromLock(
            [
                'booking_id' => self::generateUniqueBookingId(),
                'room_id' => $roomId,
                'room_type_id' => $roomId,
                'assigned_room_id' => $inventoryRoomId,
                'rate_plan_id' => \max(0, $ratePlanId),
                'guest_id' => $guestId,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => $guests,
                'status' => $reservationStatus,
                'total_price' => $totalPrice,
                'payment_status' => $paymentStatus,
                'created_at' => $now,
            ],
            $sessionId,
            $now,
            self::getNonBlockingReservationStatuses()
        );

        if ($reservationId <= 0) {
            return 0;
        }

        if ($inventoryRoomId > 0) {
            InventoryEngine::reserveRoom($inventoryRoomId, $reservationId);
            LockEngine::releaseExactLock($inventoryRoomId, $checkin, $checkout, $sessionId);
        } else {
            LockEngine::releaseExactLock($roomId, $checkin, $checkout, $sessionId);
        }

        \do_action('must_hotel_booking/reservation_created', $reservationId);

        return $reservationId;
    }

    public static function createReservationWithoutLock(
        int $roomId,
        int $guestId,
        string $checkin,
        string $checkout,
        int $guests,
        float $totalPrice,
        string $paymentStatus = 'unpaid',
        string $reservationStatus = 'pending',
        string $bookingSource = 'website',
        string $notes = '',
        int $ratePlanId = 0
    ): int {
        if (
            $roomId <= 0 ||
            $guestId <= 0 ||
            $guests <= 0 ||
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout
        ) {
            return 0;
        }

        $inventoryRoomId = 0;

        if (InventoryEngine::hasInventoryForRoomType($roomId)) {
            $availableInventoryRooms = InventoryEngine::getAvailableRooms($roomId, $checkin, $checkout);

            if (empty($availableInventoryRooms)) {
                return 0;
            }

            $inventoryRoomId = isset($availableInventoryRooms[0]['id']) ? (int) $availableInventoryRooms[0]['id'] : 0;

            if ($inventoryRoomId <= 0) {
                return 0;
            }
        }

        $reservationId = get_reservation_repository()->createReservation(
            [
                'booking_id' => self::generateUniqueBookingId(),
                'room_id' => $roomId,
                'room_type_id' => $roomId,
                'assigned_room_id' => $inventoryRoomId,
                'rate_plan_id' => \max(0, $ratePlanId),
                'guest_id' => $guestId,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => $guests,
                'status' => $reservationStatus,
                'booking_source' => $bookingSource,
                'notes' => $notes,
                'total_price' => $totalPrice,
                'payment_status' => $paymentStatus,
                'created_at' => \current_time('mysql'),
            ],
            self::getNonBlockingReservationStatuses()
        );

        if ($reservationId <= 0) {
            return 0;
        }

        if ($inventoryRoomId > 0) {
            InventoryEngine::reserveRoom($inventoryRoomId, $reservationId);
        }

        \do_action('must_hotel_booking/reservation_created', $reservationId);

        return $reservationId;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @return array<string, mixed>
     */
    public static function submitCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        $result = self::createReservations($context, $guestForm, $couponCode);

        if (!empty($result['errors'])) {
            return [
                'success' => false,
                'errors' => (array) $result['errors'],
                'redirect_url' => '',
            ];
        }

        return [
            'success' => true,
            'errors' => [],
            'redirect_url' => \add_query_arg(
                ['reservation_ids' => \implode(',', \array_map('intval', (array) ($result['reservation_ids'] ?? [])))],
                ManagedPages::getBookingConfirmationPageUrl()
            ),
        ];
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    public static function getConfirmationRowsByIds(array $reservationIds): array
    {
        return get_reservation_repository()->getConfirmationRowsByIds($reservationIds);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getConfirmationRowsByBookingId(string $bookingId): array
    {
        return get_reservation_repository()->getConfirmationRowsByBookingId($bookingId);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, int>
     */
    public static function getReservationIdsFromSource(array $source): array
    {
        $rawIds = isset($source['reservation_ids']) ? (string) \wp_unslash($source['reservation_ids']) : '';
        $parts = \array_filter(\array_map('trim', \explode(',', $rawIds)));
        $ids = [];

        foreach ($parts as $part) {
            $reservationId = \absint($part);

            if ($reservationId > 0) {
                $ids[$reservationId] = $reservationId;
            }
        }

        if (empty($ids)) {
            $singleId = isset($source['reservation_id']) ? \absint(\wp_unslash($source['reservation_id'])) : 0;

            if ($singleId > 0) {
                $ids[$singleId] = $singleId;
            }
        }

        return \array_values($ids);
    }

    /**
     * @return array<int, string>
     */
    private static function getNonBlockingReservationStatuses(): array
    {
        return ReservationStatus::getInventoryNonBlockingStatuses();
    }

    private static function generateUniqueBookingId(): string
    {
        $repository = get_reservation_repository();
        $maxAttempts = 8;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $suffix = \strtoupper(\substr(\str_replace('-', '', \wp_generate_uuid4()), 0, 8));
            $bookingId = 'MHB-' . \gmdate('Ymd') . '-' . $suffix;

            if (!$repository->bookingIdExists($bookingId)) {
                return $bookingId;
            }

            $attempt++;
        }

        return 'MHB-' . \gmdate('YmdHis') . '-' . \wp_rand(1000, 9999);
    }
}
