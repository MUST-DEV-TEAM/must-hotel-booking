<?php
namespace MustHotelBooking\Provider\Clock;
use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Engine\BookingAbuseProtection;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\ReservationEngine;
use MustHotelBooking\Engine\RatePlanEngine;
use MustHotelBooking\Provider\Contracts\ReservationProviderInterface;
use MustHotelBooking\Provider\Dto\QuoteRequest;
use MustHotelBooking\Provider\Dto\ReservationCreateRequest;
final class ClockReservationProvider implements ReservationProviderInterface
{
    /** @var ClockApiClient */
    private $client;
    /** @var ClockCatalogService */
    private $catalog;
    /** @var ClockAvailabilityProvider */
    private $availability;
    /** @var ClockQuoteProvider */
    private $quote;
    /** @var ClockMirrorReservationService */
    private $mirror;
    /** @var ClockRoomSelection */
    private $roomSelection;
    public function __construct(
        ?ClockApiClient $client = null,
        ?ClockCatalogService $catalog = null,
        ?ClockAvailabilityProvider $availability = null,
        ?ClockQuoteProvider $quote = null,
        ?ClockMirrorReservationService $mirror = null,
        ?ClockRoomSelection $roomSelection = null
    ) {
        $this->client = $client ?: new ClockApiClient();
        $this->catalog = $catalog ?: new ClockCatalogService($this->client);
        $this->availability = $availability ?: new ClockAvailabilityProvider($this->client, $this->catalog);
        $this->quote = $quote ?: new ClockQuoteProvider($this->client, $this->catalog);
        $this->mirror = $mirror ?: new ClockMirrorReservationService();
        $this->roomSelection = $roomSelection ?: new ClockRoomSelection($this->catalog);
    }
    public function buildReservationNote(int $roomId, array $guestForm): string
    {
        return ReservationEngine::buildReservationNote($roomId, $guestForm);
    }
    public function getCheckoutRoomData(int $roomId): ?array
    {
        $selection = $this->roomSelection->resolve($roomId);
        return \is_array($selection) && isset($selection['room']) && \is_array($selection['room'])
            ? $selection['room']
            : null;
    }
    public function getRoomGuestAllocations(array $rooms, int $totalGuests, array $guestForm = [], bool $strict = false): array
    {
        return ReservationEngine::getRoomGuestAllocations($rooms, $totalGuests, $guestForm, $strict);
    }
    public function ensureRoomLock(int $roomId, string $checkin, string $checkout): bool
    {
        // This is only a local UI/session lock. It is not a Clock PMS+ inventory hold.
        // A real Clock adapter must re-check provider availability immediately before create.
        $sessionId = LockEngine::getOrCreateSessionId();
        if (!$this->availability->checkAvailability($roomId, $checkin, $checkout, $sessionId)) {
            return false;
        }
        $selection = $this->roomSelection->resolve($roomId);
        if (\is_array($selection) && !empty($selection['is_physical'])) {
            if (LockEngine::hasExactLock($roomId, $checkin, $checkout, $sessionId)) {
                return true;
            }
            return \MustHotelBooking\Engine\get_availability_repository()->upsertRoomLock(
                $roomId,
                $checkin,
                $checkout,
                $sessionId,
                LockEngine::getExpiryDatetime()
            );
        }
        return ReservationEngine::ensureRoomLock($roomId, $checkin, $checkout);
    }
    public function ensureRoomLocks(array $roomIds, string $checkin, string $checkout): bool
    {
        foreach ($roomIds as $roomId) {
            if (!$this->ensureRoomLock((int) $roomId, $checkin, $checkout)) {
                return false;
            }
        }
        return true;
    }
    public function releaseRoomSelectionLock(int $roomId, string $checkin, string $checkout): bool
    {
        $selection = $this->roomSelection->resolve($roomId);
        if (\is_array($selection) && !empty($selection['is_physical'])) {
            return \MustHotelBooking\Engine\LockEngine::releaseExactLock($roomId, $checkin, $checkout);
        }
        if (\MustHotelBooking\Engine\InventoryEngine::hasInventoryForRoomType($roomId)) {
            return \MustHotelBooking\Engine\InventoryEngine::releaseLocksForRoomType($roomId, $checkin, $checkout);
        }
        return \MustHotelBooking\Engine\LockEngine::releaseExactLock($roomId, $checkin, $checkout);
    }
    public function createGuest(array $guestForm): int
    {
        return ReservationEngine::createGuest($guestForm);
    }
    public function bootstrapCheckoutSelectionFromRequest(array $source): array
    {
        $precheck = $this->precheckSelectionSource($source, 'room_id');
        if (!empty($precheck)) {
            return $precheck;
        }
        $roomId = $this->selectionRoomIdFromSource($source, 'room_id');
        $ratePlanId = isset($source['rate_plan_id']) ? \absint(\wp_unslash($source['rate_plan_id'])) : 0;
        $selection = $this->roomSelection->resolve($roomId);
        $room = \is_array($selection) && isset($selection['room']) && \is_array($selection['room']) ? $selection['room'] : null;
        if ($roomId <= 0) {
            return [];
        }
        if (!\is_array($selection) || !\is_array($room)) {
            return [\__('The selected room could not be found.', 'must-hotel-booking')];
        }
        if (empty($selection['is_physical'])) {
            return [\__('Please select an exact room unit before checkout.', 'must-hotel-booking')];
        }
        $context = BookingValidationEngine::parseRequestContext($source, true);
        $context = BookingValidationEngine::applyFixedRoomContext($context, $room);
        if (empty($context['is_valid'])) {
            return (array) ($context['errors'] ?? []);
        }
        if ($ratePlanId > 0 && !$this->isValidRatePlanForSelection($selection, $ratePlanId)) {
            return [\__('The selected rate plan is no longer available for this room.', 'must-hotel-booking')];
        }
        $selectionState = \MustHotelBooking\Frontend\get_booking_selection();
        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();
        $selectedRatePlanMap = \MustHotelBooking\Frontend\get_booking_selected_room_rate_plan_map();
        $hasSameFixedRoomSelection =
            (string) ($selectionState['flow_data']['booking_mode'] ?? '') === 'fixed-room' &&
            (int) ($selectionState['flow_data']['fixed_room_id'] ?? 0) === $roomId &&
            \count($selectedRoomIds) === 1 &&
            (int) $selectedRoomIds[0] === $roomId &&
            (int) ($selectedRatePlanMap[$roomId] ?? 0) === $ratePlanId &&
            \MustHotelBooking\Frontend\do_booking_selection_contexts_match($selectionState['context'] ?? [], $context);
        if (!$hasSameFixedRoomSelection) {
            \MustHotelBooking\Frontend\clear_booking_selection();
        }
        if (!$this->ensureRoomLock($roomId, (string) $context['checkin'], (string) $context['checkout'])) {
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
    public function handleBookingRoomSelectionRequest(array $requestSource): array
    {
        $action = isset($requestSource['must_booking_action']) ? \sanitize_key((string) \wp_unslash($requestSource['must_booking_action'])) : '';
        if ($action !== 'select_room') {
            return [
                'handled' => false,
                'success' => false,
                'message' => '',
                'redirect_url' => '',
            ];
        }
        $nonce = isset($requestSource['must_booking_nonce']) ? (string) \wp_unslash($requestSource['must_booking_nonce']) : '';
        if (!\wp_verify_nonce($nonce, 'must_booking_select_room')) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('Security check failed. Please try again.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }
        $roomId = $this->selectionRoomIdFromSource($requestSource, 'room_id');
        $ratePlanId = isset($requestSource['rate_plan_id']) ? \absint(\wp_unslash($requestSource['rate_plan_id'])) : 0;
        $context = BookingValidationEngine::parseRequestContext($requestSource, true);
        $selection = $this->roomSelection->resolve($roomId);
        if (empty($context['is_valid'])) {
            $firstError = isset($context['errors'][0]) ? (string) $context['errors'][0] : '';
            return [
                'handled' => true,
                'success' => false,
                'message' => $firstError !== '' ? $firstError : \__('Booking request is invalid.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }
        if ($roomId <= 0 || !\is_array($selection)) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('Please select a room to continue.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }
        if (empty($selection['is_physical'])) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('Please select an exact room unit before continuing.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }
        if ($ratePlanId > 0 && !$this->isValidRatePlanForSelection($selection, $ratePlanId)) {
            return [
                'handled' => true,
                'success' => false,
                'message' => \__('The selected rate plan is no longer available for this room.', 'must-hotel-booking'),
                'redirect_url' => '',
            ];
        }
        if (!$this->ensureRoomLock($roomId, (string) $context['checkin'], (string) $context['checkout'])) {
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
                \array_filter(
                    [
                    'room_id' => \is_array($selection) && !empty($selection['is_physical']) ? (int) ($selection['room_type_id'] ?? $roomId) : $roomId,
                    'inventory_room_id' => \is_array($selection) && !empty($selection['is_physical']) ? (int) ($selection['physical_room_id'] ?? 0) : 0,
                    'checkin' => (string) $context['checkin'],
                    'checkout' => (string) $context['checkout'],
                    'guests' => (int) $context['guests'],
                    'room_count' => (int) ($context['room_count'] ?? 1),
                    'accommodation_type' => (string) $context['accommodation_type'],
                    'rate_plan_id' => $ratePlanId,
                    ],
                    static function ($value): bool {
                        return $value !== 0 && $value !== '';
                    }
                ),
                \MustHotelBooking\Frontend\get_checkout_page_url()
            ),
        ];
    }
    public function handleAccommodationRoomSelectionRequest(array $requestSource): array
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
        $roomId = $this->selectionRoomIdFromSource($requestSource, 'room_id');
        $ratePlanId = isset($requestSource['rate_plan_id']) ? \absint(\wp_unslash($requestSource['rate_plan_id'])) : 0;
        $context = BookingValidationEngine::parseRequestContext($requestSource, true);
        $selection = $this->roomSelection->resolve($roomId);
        if (empty($context['is_valid'])) {
            return [
                'success' => false,
                'messages' => (array) ($context['errors'] ?? []),
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }
        if ($roomId <= 0 || !\is_array($selection)) {
            return [
                'success' => false,
                'messages' => [\__('Please select a room to continue.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }
        if ($action === 'select_room' && empty($selection['is_physical'])) {
            return [
                'success' => false,
                'messages' => [\__('Please select an exact room unit before continuing.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
                'should_redirect' => false,
            ];
        }
        if ($action === 'select_room' && $ratePlanId > 0 && !$this->isValidRatePlanForSelection($selection, $ratePlanId)) {
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
        if (!$this->availability->checkAvailability($roomId, (string) $context['checkin'], (string) $context['checkout'], LockEngine::getOrCreateSessionId())) {
            return [
                'success' => false,
                'messages' => [\__('This room is no longer available in Clock for the selected dates.', 'must-hotel-booking')],
                'context' => $context,
                'redirect_url' => '',
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
            : $this->ensureRoomLock($roomId, (string) $context['checkin'], (string) $context['checkout']);
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
                'redirect_url' => \add_query_arg(
                    \array_filter(
                        [
                            'room_id' => !empty($selection['is_physical']) ? (int) ($selection['room_type_id'] ?? $roomId) : $roomId,
                            'inventory_room_id' => !empty($selection['is_physical']) ? (int) ($selection['physical_room_id'] ?? 0) : 0,
                            'checkin' => (string) $context['checkin'],
                            'checkout' => (string) $context['checkout'],
                            'guests' => (int) $context['guests'],
                            'room_count' => (int) ($context['room_count'] ?? 1),
                            'accommodation_type' => (string) $context['accommodation_type'],
                            'rate_plan_id' => $ratePlanId,
                        ],
                        static function ($value): bool {
                            return $value !== 0 && $value !== '';
                        }
                    ),
                    \MustHotelBooking\Frontend\get_checkout_page_url()
                ),
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
    public function continueCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        if (empty($context['is_valid'])) {
            return [
                'success' => false,
                'errors' => (array) ($context['errors'] ?? []),
                'redirect_url' => '',
            ];
        }
        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();
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
        $roomItemsPreview = $this->quote->buildCheckoutRoomItems(new QuoteRequest($context, $couponCode, $guestForm, true));
        if (!empty($roomItemsPreview['errors'])) {
            return [
                'success' => false,
                'errors' => (array) $roomItemsPreview['errors'],
                'redirect_url' => '',
            ];
        }
        if (!$this->ensureRoomLocks($selectedRoomIds, (string) $context['checkin'], (string) $context['checkout'])) {
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
    public function submitCheckout(array $context, array $guestForm, string $couponCode = ''): array
    {
        $result = $this->createReservations(
            new ReservationCreateRequest(
                $context,
                $guestForm,
                $couponCode,
                ['anti_abuse_surface' => BookingAbuseProtection::SURFACE_CHECKOUT]
            )
        );
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
    public function createReservations(ReservationCreateRequest $request): array
    {
        $context = $request->getContext();
        $guestForm = $request->getGuestForm();
        $couponCode = $request->getCouponCode();
        $options = $request->getOptions();
        $reservationStatus = isset($options['reservation_status']) ? \sanitize_key((string) $options['reservation_status']) : 'pending';
        $paymentStatus = isset($options['payment_status']) ? \sanitize_key((string) $options['payment_status']) : 'pending';
        $clearSelection = !isset($options['clear_selection']) || (bool) $options['clear_selection'];
        $clockStatus = 'expected';
        if (!ClockConfig::isPublicBookingConfigured()) {
            return $this->errorResult(ClockConfig::publicBookingConfigurationErrors());
        }
        if (empty($context['is_valid'])) {
            return $this->errorResult((array) ($context['errors'] ?? []));
        }
        $selectedRoomIds = \MustHotelBooking\Frontend\get_booking_selected_room_ids();
        if (empty($selectedRoomIds)) {
            return $this->errorResult([\__('Please select at least one room before continuing.', 'must-hotel-booking')]);
        }
        $antiAbuseResult = BookingAbuseProtection::guardSubmission($context, $guestForm, $options);
        if (empty($antiAbuseResult['allowed'])) {
            return $this->errorResult(
                [
                    isset($antiAbuseResult['message']) && (string) $antiAbuseResult['message'] !== ''
                    ? (string) $antiAbuseResult['message']
                    : BookingAbuseProtection::getGenericFailureMessage(),
                ]
            );
        }
        $validationErrors = BookingValidationEngine::validateGuestForm($guestForm);
        if (!empty($validationErrors)) {
            return $this->errorResult($validationErrors);
        }
        $roomItemsPreview = $this->quote->buildCheckoutRoomItems(new QuoteRequest($context, $couponCode, $guestForm, true));
        if (!empty($roomItemsPreview['errors'])) {
            return $this->errorResult((array) $roomItemsPreview['errors']);
        }
        $roomGuestCounts = isset($roomItemsPreview['room_guest_counts']) && \is_array($roomItemsPreview['room_guest_counts'])
            ? $roomItemsPreview['room_guest_counts']
            : [];
        $selectedRatePlanMap = \MustHotelBooking\Frontend\get_booking_selected_room_rate_plan_map();
        $validatedRooms = [];
        foreach ($selectedRoomIds as $roomId) {
            $roomId = (int) $roomId;
            $ratePlanId = isset($selectedRatePlanMap[$roomId]) ? (int) $selectedRatePlanMap[$roomId] : 0;
            $selection = $this->roomSelection->resolve($roomId);
            if (!$this->ensureRoomLock($roomId, (string) $context['checkin'], (string) $context['checkout'])) {
                return $this->errorResult([\__('One of your selected room locks has expired. Please return to accommodation and confirm your selection again.', 'must-hotel-booking')]);
            }
            if (!$this->availability->checkAvailability($roomId, (string) $context['checkin'], (string) $context['checkout'], LockEngine::getOrCreateSessionId())) {
                return $this->errorResult([\__('One of your selected rooms is no longer available in Clock for the selected dates.', 'must-hotel-booking')]);
            }
            $pricing = $this->quote->calculateTotal(
                $roomId,
                (string) $context['checkin'],
                (string) $context['checkout'],
                isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : (int) $context['guests'],
                $couponCode,
                $ratePlanId
            );
            if (empty($pricing['success']) || !isset($pricing['total_price'])) {
                return $this->errorResult([\__('Unable to calculate final Clock booking total for one of the selected rooms.', 'must-hotel-booking')]);
            }
            $roomMapping = \is_array($selection) && isset($selection['room_mapping']) && \is_array($selection['room_mapping']) ? $selection['room_mapping'] : null;
            $physicalMapping = \is_array($selection) && isset($selection['physical_mapping']) && \is_array($selection['physical_mapping']) ? $selection['physical_mapping'] : null;
            $ratePlanMapping = $this->ratePlanMappingForPricing($pricing, $ratePlanId);
            $resolvedRatePlanId = $ratePlanId > 0 ? $ratePlanId : (int) ($ratePlanMapping['local_id'] ?? 0);
            if (!$this->hasExternalId($roomMapping) || !$this->hasExternalId($ratePlanMapping)) {
                return $this->errorResult([\__('Clock mapping is missing for one of the selected rooms or rate plans.', 'must-hotel-booking')]);
            }
            if (!\is_array($selection) || empty($selection['is_physical']) || !$this->hasExternalId($physicalMapping)) {
                return $this->errorResult([\__('Clock exact-room booking requires a mapped physical room. Please select another room or contact the hotel.', 'must-hotel-booking')]);
            }
            $validatedRooms[] = [
                'room_id' => $roomId,
                'rate_plan_id' => $resolvedRatePlanId,
                'guests' => isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : (int) $context['guests'],
                'pricing' => $pricing,
                'room_mapping' => $this->mappingSummary($roomMapping),
                'physical_room_id' => \is_array($selection) ? (int) ($selection['physical_room_id'] ?? 0) : 0,
                'room_type_id' => \is_array($selection) ? (int) ($selection['room_type_id'] ?? $roomId) : $roomId,
                'physical_mapping' => $this->mappingSummary($physicalMapping),
                'rate_plan_mapping' => $this->mappingSummary($ratePlanMapping),
            ];
        }
        $guestId = ReservationEngine::createGuest($guestForm);
        if ($guestId <= 0) {
            return $this->errorResult([\__('Unable to save guest details.', 'must-hotel-booking')]);
        }
        $returningGuestId = $this->findReturningGuestId($guestForm);
        $reservationIds = [];
        $appliedCouponIds = [];
        foreach ($validatedRooms as $validatedRoom) {
            $providerReservation = $this->createClockBooking($context, $guestForm, $validatedRoom, $clockStatus, $returningGuestId);
            if (empty($providerReservation['success'])) {
                return $this->errorResult([(string) ($providerReservation['message'] ?? \__('Unable to create the booking in Clock.', 'must-hotel-booking'))]);
            }
            $providerData = isset($providerReservation['reservation']) && \is_array($providerReservation['reservation'])
                ? $providerReservation['reservation']
                : [];
            $reservationId = $this->mirror->createMirrorReservation(
                $context,
                $guestForm,
                $guestId,
                $validatedRoom,
                $providerData,
                [
                    'reservation_status' => $reservationStatus,
                    'payment_status' => $paymentStatus,
                ]
            );
            if ($reservationId <= 0) {
                return $this->errorResult([\__('Clock reservation was created, but the local mirror reservation could not be saved.', 'must-hotel-booking')]);
            }
            $reservationIds[] = $reservationId;
            $pricing = isset($validatedRoom['pricing']) && \is_array($validatedRoom['pricing']) ? $validatedRoom['pricing'] : [];
            $appliedCouponId = isset($pricing['applied_coupon_id']) ? (int) $pricing['applied_coupon_id'] : 0;
            if ($appliedCouponId > 0) {
                $appliedCouponIds[$appliedCouponId] = $appliedCouponId;
            }
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
    /** @param array<string, mixed> $source @return array<int, string> */
    private function precheckSelectionSource(array $source, string $roomKey): array
    {
        $roomId = $this->selectionRoomIdFromSource($source, $roomKey);
        if ($roomId <= 0) {
            return [];
        }
        $context = BookingValidationEngine::parseRequestContext($source, true);
        if (empty($context['is_valid'])) {
            return [];
        }
        return $this->availability->checkAvailability($roomId, (string) $context['checkin'], (string) $context['checkout'], LockEngine::getOrCreateSessionId())
            ? []
            : [\__('This room is no longer available in Clock for the selected dates.', 'must-hotel-booking')];
    }
    /** @param array<string, mixed> $source */
    private function selectionRoomIdFromSource(array $source, string $roomKey): int
    {
        foreach (['inventory_room_id', 'physical_room_id', $roomKey] as $key) {
            if (!isset($source[$key])) {
                continue;
            }
            $roomId = \absint(\wp_unslash($source[$key]));
            if ($roomId > 0) {
                if ($key === $roomKey && !\is_array($this->roomSelection->resolve($roomId))) {
                    $selection = $this->roomSelection->resolvePhysicalByExternalId((string) \wp_unslash($source[$key]));
                    if (\is_array($selection)) {
                        return isset($selection['selection_id']) ? (int) $selection['selection_id'] : $roomId;
                    }
                }
                return $roomId;
            }
        }
        return 0;
    }
    /** @param array<string, mixed> $selection */
    private function isValidRatePlanForSelection(array $selection, int $ratePlanId): bool
    {
        if ($ratePlanId <= 0) {
            return true;
        }
        $mapping = $this->catalog->findRatePlanMapping($ratePlanId);
        if (!$this->hasExternalId($mapping)) {
            return false;
        }
        $roomTypeId = isset($selection['room_type_id']) ? (int) $selection['room_type_id'] : 0;
        if ($roomTypeId <= 0) {
            return false;
        }
        if (\is_array(RatePlanEngine::getRoomRatePlan($roomTypeId, $ratePlanId))) {
            return true;
        }
        $ratePlan = \MustHotelBooking\Engine\get_rate_plan_repository()->getRatePlanById($ratePlanId);
        return \is_array($ratePlan) && (!isset($ratePlan['is_active']) || (int) $ratePlan['is_active'] === 1);
    }
    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @param array<string, mixed> $validatedRoom
     * @return array<string, mixed>
     */
    private function createClockBooking(array $context, array $guestForm, array $validatedRoom, string $clockStatus, string $returningGuestId = ''): array
    {
        $idempotencyKey = $this->idempotencyKey($context, $guestForm, [$validatedRoom], $clockStatus, 'clock_pms');
        $body = $this->bookingCreatePayload($context, $guestForm, $validatedRoom, $clockStatus, $returningGuestId);
        $response = $this->client->request(
            'POST',
            ClockConfig::reservationCreatePath(),
            [
                'idempotency_key' => $idempotencyKey,
                'body' => $body,
            ],
            'clock.reservation_create'
        );
        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => \__('We could not confirm this reservation in the hotel system. Please try another date or contact the hotel.', 'must-hotel-booking'),
                'provider_message' => $response->getErrorMessage(),
            ];
        }
        $reservation = $this->reservationSource($response->getData());
        $providerId = $this->firstString($reservation, ['reservation_id', 'booking_id', 'id', 'confirmation_number', 'reference']);
        if ($providerId === '') {
            return [
                'success' => false,
                'message' => \__('Clock reservation response did not include a reservation identifier.', 'must-hotel-booking'),
            ];
        }
        if (!$this->clockReservationHasRequestedPhysicalRoom($reservation, $validatedRoom)) {
            $this->rollbackUnassignedClockBooking($providerId);

            return [
                'success' => false,
                'message' => \__('Clock did not confirm the exact selected room. No payment was collected; please select another room or contact the hotel.', 'must-hotel-booking'),
            ];
        }
        return [
            'success' => true,
            'reservation' => $reservation,
        ];
    }

    /** @param array<string, mixed> $reservation @param array<string, mixed> $validatedRoom */
    private function clockReservationHasRequestedPhysicalRoom(array $reservation, array $validatedRoom): bool
    {
        $physicalMapping = isset($validatedRoom['physical_mapping']) && \is_array($validatedRoom['physical_mapping']) ? $validatedRoom['physical_mapping'] : [];
        $requestedPhysicalId = (string) ($physicalMapping['external_id'] ?? '');

        if ($requestedPhysicalId === '') {
            return false;
        }

        $assignedPhysicalId = $this->firstString($reservation, ['current_room_id', 'arrival_room_id', 'room_id', 'physical_room_id']);

        if ($assignedPhysicalId !== '') {
            return $assignedPhysicalId === $requestedPhysicalId;
        }

        foreach (['room', 'physical_room', 'arrival_room', 'current_room'] as $key) {
            if (isset($reservation[$key]) && \is_array($reservation[$key])) {
                $nestedId = $this->firstString($reservation[$key], ['id', 'room_id', 'physical_room_id']);

                if ($nestedId !== '') {
                    return $nestedId === $requestedPhysicalId;
                }
            }
        }

        return false;
    }

    private function rollbackUnassignedClockBooking(string $providerId): void
    {
        $cancelPath = ClockConfig::reservationCancelPath();

        if ($cancelPath === '' || $providerId === '') {
            return;
        }

        $method = 'POST';
        if (\preg_match('/^\/?\s*(GET|POST|PUT|PATCH|DELETE)\s+(.+)$/i', \trim($cancelPath), $matches)) {
            $method = \strtoupper((string) $matches[1]);
            $cancelPath = \trim((string) $matches[2]);
        }

        $path = \str_replace(['{booking_id}', '{reservation_id}', ':booking_id', ':reservation_id'], $providerId, $cancelPath);
        $this->client->request(
            $method,
            $path,
            [
                'body' => [
                    'booking' => [
                        'status' => 'canceled',
                    ],
                ],
            ],
            'clock.reservation_cancel'
        );
    }
    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @param array<string, mixed> $validatedRoom
     * @return array<string, mixed>
     */
    private function bookingCreatePayload(array $context, array $guestForm, array $validatedRoom, string $clockStatus, string $returningGuestId): array
    {
        $roomMapping = isset($validatedRoom['room_mapping']) && \is_array($validatedRoom['room_mapping']) ? $validatedRoom['room_mapping'] : [];
        $physicalMapping = isset($validatedRoom['physical_mapping']) && \is_array($validatedRoom['physical_mapping']) ? $validatedRoom['physical_mapping'] : [];
        $ratePlanMapping = isset($validatedRoom['rate_plan_mapping']) && \is_array($validatedRoom['rate_plan_mapping']) ? $validatedRoom['rate_plan_mapping'] : [];
        $pricing = isset($validatedRoom['pricing']) && \is_array($validatedRoom['pricing']) ? $validatedRoom['pricing'] : [];
        $selectionRoomId = isset($validatedRoom['room_id']) ? (int) $validatedRoom['room_id'] : 0;
        $roomTypeId = isset($validatedRoom['room_type_id']) ? (int) $validatedRoom['room_type_id'] : $selectionRoomId;
        $noteRoomId = $roomTypeId > 0 ? $roomTypeId : $selectionRoomId;
        $phone = \MustHotelBooking\Frontend\combine_checkout_phone_value($guestForm);
        $booking = [
            'arrival' => (string) ($context['checkin'] ?? ''),
            'departure' => (string) ($context['checkout'] ?? ''),
            'status' => $clockStatus,
            'adults' => isset($validatedRoom['guests']) ? \max(1, (int) $validatedRoom['guests']) : \max(1, (int) ($context['guests'] ?? 1)),
            'children' => 0,
            'reference_number' => $this->clockReferenceNumber($context, $validatedRoom),
            'is_guaranteed' => false,
            'rate_id' => $this->nullableExternalId($ratePlanMapping),
            'arrival_room_type_id' => $this->nullableExternalId($roomMapping),
            'arrival_room_id' => $this->nullableExternalId($physicalMapping),
            'links' => \function_exists('home_url') ? \home_url('/') : '',
            'marketing_source' => 'Website',
            'marketing_channel' => 'Website',
            'marketing_segment' => '',
            'accept_charge_transfers' => true,
            'early_arrival' => false,
            'late_departure' => false,
            'manual_prices_as_text' => '',
            'manual_currency' => '',
            'note' => $this->bookingNote($noteRoomId, $guestForm, $pricing),
        ];
        if ($returningGuestId === '') {
            $booking += [
                'guest_first_name' => (string) ($guestForm['first_name'] ?? ''),
                'guest_last_name' => (string) ($guestForm['last_name'] ?? ''),
                'guest_e_mail' => (string) ($guestForm['email'] ?? ''),
                'guest_phone_number' => $phone,
                'guest_country' => (string) ($guestForm['country'] ?? ''),
                'guest_address' => (string) ($guestForm['address'] ?? ''),
                'guest_city' => (string) ($guestForm['city'] ?? ''),
                'guest_zip_code' => (string) ($guestForm['zip_code'] ?? ''),
            ];
        }
        $payload = [
            'booking' => $booking,
        ];
        if ($returningGuestId !== '') {
            $payload['main_booking_guest'] = $returningGuestId;
        }
        return $payload;
    }
    /** @param array<int, array<string, mixed>> $validatedRooms @return array<int, array<string, mixed>> */
    private function providerRoomPayloads(array $validatedRooms): array
    {
        $rooms = [];
        foreach ($validatedRooms as $validatedRoom) {
            $pricing = isset($validatedRoom['pricing']) && \is_array($validatedRoom['pricing']) ? $validatedRoom['pricing'] : [];
            $roomMapping = isset($validatedRoom['room_mapping']) && \is_array($validatedRoom['room_mapping']) ? $validatedRoom['room_mapping'] : [];
            $physicalMapping = isset($validatedRoom['physical_mapping']) && \is_array($validatedRoom['physical_mapping']) ? $validatedRoom['physical_mapping'] : [];
            $ratePlanMapping = isset($validatedRoom['rate_plan_mapping']) && \is_array($validatedRoom['rate_plan_mapping']) ? $validatedRoom['rate_plan_mapping'] : [];
            $providerPhysicalId = (string) ($physicalMapping['external_id'] ?? '');
            $rooms[] = [
                'local_room_id' => isset($validatedRoom['room_id']) ? (int) $validatedRoom['room_id'] : 0,
                'local_room_type_id' => isset($validatedRoom['room_type_id']) ? (int) $validatedRoom['room_type_id'] : 0,
                'local_physical_room_id' => isset($validatedRoom['physical_room_id']) ? (int) $validatedRoom['physical_room_id'] : 0,
                'provider_room_id' => $providerPhysicalId !== '' ? $providerPhysicalId : (string) ($roomMapping['external_id'] ?? ''),
                'provider_room_code' => $providerPhysicalId !== '' ? (string) ($physicalMapping['external_code'] ?? '') : (string) ($roomMapping['external_code'] ?? ''),
                'provider_room_type_id' => (string) ($roomMapping['external_id'] ?? ''),
                'provider_room_type_code' => (string) ($roomMapping['external_code'] ?? ''),
                'provider_physical_room_id' => $providerPhysicalId,
                'provider_physical_room_code' => (string) ($physicalMapping['external_code'] ?? ''),
                'local_rate_plan_id' => isset($validatedRoom['rate_plan_id']) ? (int) $validatedRoom['rate_plan_id'] : 0,
                'provider_rate_plan_id' => (string) ($ratePlanMapping['external_id'] ?? ''),
                'provider_rate_plan_code' => (string) ($ratePlanMapping['external_code'] ?? ''),
                'guests' => isset($validatedRoom['guests']) ? (int) $validatedRoom['guests'] : 1,
                'total_price' => isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0,
                'room_subtotal' => isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : 0.0,
                'taxes_total' => isset($pricing['taxes_total']) ? (float) $pricing['taxes_total'] : 0.0,
                'fees_total' => isset($pricing['fees_total']) ? (float) $pricing['fees_total'] : 0.0,
                'discount_total' => isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0,
            ];
        }
        return $rooms;
    }
    /** @param array<int, array<string, mixed>> $validatedRooms */
    private function totalPrice(array $validatedRooms): float
    {
        $total = 0.0;
        foreach ($validatedRooms as $validatedRoom) {
            $pricing = isset($validatedRoom['pricing']) && \is_array($validatedRoom['pricing']) ? $validatedRoom['pricing'] : [];
            $total += isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0;
        }
        return \round($total, 2);
    }
    /** @param mixed $data @return array<string, mixed> */
    private function reservationSource($data): array
    {
        if (!\is_array($data)) {
            return [];
        }
        foreach (['reservation', 'booking', 'data', 'result'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $data[$key];
            }
        }
        return $data;
    }
    /** @param array<string, mixed> $pricing @return array<string, mixed>|null */
    private function ratePlanMappingForPricing(array $pricing, int $ratePlanId): ?array
    {
        $providerRateId = $this->providerRateIdFromPricing($pricing);
        if ($providerRateId !== '') {
            $mapping = (new \MustHotelBooking\Provider\Storage\ProviderMappingRepository())->findByExternal(
                \MustHotelBooking\Provider\ProviderManager::CLOCK_MODE,
                'rate_plan',
                $providerRateId
            );
            if ($this->hasExternalId($mapping)) {
                return $mapping;
            }
            return [
                'id' => 0,
                'local_id' => 0,
                'external_id' => $providerRateId,
                'external_code' => '',
                'display_name' => (string) ($pricing['rate_plan_name'] ?? ''),
            ];
        }
        if ($ratePlanId > 0) {
            $mapping = $this->catalog->findRatePlanMapping($ratePlanId);
            if ($this->hasExternalId($mapping)) {
                return $mapping;
            }
        }
        return null;
    }
    /** @param array<string, mixed> $pricing */
    private function providerRateIdFromPricing(array $pricing): string
    {
        foreach (['provider_product', 'provider_quote'] as $key) {
            if (!isset($pricing[$key]) || !\is_array($pricing[$key])) {
                continue;
            }
            $rateId = $this->firstString($pricing[$key], ['rate_id', 'rate_plan_id', 'provider_rate_id', 'clock_rate_id']);
            if ($rateId !== '') {
                return $rateId;
            }
        }
        return $this->firstString($pricing, ['provider_rate_id', 'clock_rate_id']);
    }
    /** @param array<string, mixed> $context @param array<string, mixed> $validatedRoom */
    private function clockReferenceNumber(array $context, array $validatedRoom): string
    {
        $parts = [
            'MHB',
            \gmdate('YmdHis'),
            isset($validatedRoom['room_id']) ? (string) (int) $validatedRoom['room_id'] : '0',
            \substr(\hash('crc32b', (string) ($context['checkin'] ?? '') . (string) ($context['checkout'] ?? '') . $this->uuid()), 0, 8),
        ];
        return \implode('-', $parts);
    }
    /** @param array<string, string> $guestForm @param array<string, mixed> $pricing */
    private function bookingNote(int $roomId, array $guestForm, array $pricing): string
    {
        $note = ReservationEngine::buildReservationNote($roomId, $guestForm);
        $providerProductId = isset($pricing['provider_product_id']) ? (string) $pricing['provider_product_id'] : '';
        if ($providerProductId !== '') {
            $note = \trim($note);
            $note .= ($note !== '' ? "\n\n" : '') . 'Clock product ID: ' . $providerProductId;
        }
        return $note;
    }
    /** @param array<string, string> $guestForm */
    private function findReturningGuestId(array $guestForm): string
    {
        $terms = [];
        $email = isset($guestForm['email']) ? \sanitize_email((string) $guestForm['email']) : '';
        $phone = \MustHotelBooking\Frontend\combine_checkout_phone_value($guestForm);
        if ($email !== '') {
            $terms[] = $email;
        }
        if ($phone !== '') {
            $terms[] = $phone;
        }
        if (empty($terms)) {
            return '';
        }
        $response = $this->client->get(
            '/guests/search',
            [
                'free_text_search' => \implode(' ', \array_values(\array_unique($terms))),
            ],
            'clock.guests.search',
            [
                'api_type' => 'pms_api',
                'endpoint_name' => 'guests_search',
            ]
        );
        if (!$response->isSuccess()) {
            return '';
        }
        $guests = $this->guestSearchItems($response->getData());
        if (\count($guests) !== 1) {
            return '';
        }
        return $this->firstString($guests[0], ['guest_id', 'id']);
    }
    /** @param mixed $data @return array<int, array<string, mixed>> */
    private function guestSearchItems($data): array
    {
        if (!\is_array($data)) {
            return [];
        }
        if ($this->isList($data)) {
            return \array_values(\array_filter($data, 'is_array'));
        }
        foreach (['guests', 'items', 'data', 'results'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->isList($data[$key])
                    ? \array_values(\array_filter($data[$key], 'is_array'))
                    : [];
            }
        }
        return [];
    }
    /** @param array<int|string, mixed> $value */
    private function isList(array $value): bool
    {
        return $value === [] || \array_keys($value) === \range(0, \count($value) - 1);
    }
    /** @param array<string, mixed> $mapping */
    private function nullableExternalId(array $mapping)
    {
        $externalId = (string) ($mapping['external_id'] ?? '');
        return $externalId !== '' && \is_numeric($externalId) ? (int) $externalId : ($externalId !== '' ? $externalId : null);
    }
    private function uuid(): string
    {
        if (\function_exists('wp_generate_uuid4')) {
            return \wp_generate_uuid4();
        }
        return \bin2hex(\random_bytes(16));
    }
    /** @param array<string, mixed>|null $mapping @return array<string, mixed> */
    private function mappingSummary(?array $mapping): array
    {
        if (!\is_array($mapping)) {
            return [];
        }
        return [
            'id' => isset($mapping['id']) ? (int) $mapping['id'] : 0,
            'external_id' => (string) ($mapping['external_id'] ?? ''),
            'external_code' => (string) ($mapping['external_code'] ?? ''),
            'display_name' => (string) ($mapping['display_name'] ?? ''),
        ];
    }
    /** @param array<string, mixed>|null $mapping */
    private function hasExternalId(?array $mapping): bool
    {
        return \is_array($mapping) && (string) ($mapping['external_id'] ?? '') !== '';
    }
    /**
     * @param array<int, string> $errors
     * @return array{errors: array<int, string>, reservation_ids: array<int, int>, applied_coupon_ids: array<int, int>}
     */
    private function errorResult(array $errors): array
    {
        return [
            'errors' => \array_values(\array_filter(\array_map('strval', $errors))),
            'reservation_ids' => [],
            'applied_coupon_ids' => [],
        ];
    }
    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                return \sanitize_text_field((string) $source[$key]);
            }
        }
        return '';
    }
    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @param array<int, array<string, mixed>> $validatedRooms
     */
    private function idempotencyKey(array $context, array $guestForm, array $validatedRooms, string $reservationStatus, string $paymentStatus): string
    {
        $sessionId = \MustHotelBooking\Engine\LockEngine::getOrCreateSessionId();
        $payload = [
            'session_id' => $sessionId,
            'checkin' => (string) ($context['checkin'] ?? ''),
            'checkout' => (string) ($context['checkout'] ?? ''),
            'guest_email' => (string) ($guestForm['email'] ?? ''),
            'rooms' => \array_map(
                static function (array $room): array {
                    return [
                        'room_id' => isset($room['room_id']) ? (int) $room['room_id'] : 0,
                        'rate_plan_id' => isset($room['rate_plan_id']) ? (int) $room['rate_plan_id'] : 0,
                        'guests' => isset($room['guests']) ? (int) $room['guests'] : 1,
                    ];
                },
                $validatedRooms
            ),
            'reservation_status' => $reservationStatus,
            'payment_status' => $paymentStatus,
        ];
        $json = \function_exists('wp_json_encode') ? \wp_json_encode($payload) : \json_encode($payload);
        return 'mhb-clock-' . \substr(\hash('sha256', \is_string($json) ? $json : \serialize($payload)), 0, 48);
    }
}
