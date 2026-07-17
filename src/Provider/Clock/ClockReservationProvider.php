<?php
namespace MustHotelBooking\Provider\Clock;
use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Engine\BookingAbuseProtection;
use MustHotelBooking\Engine\BookingQuoteDraft;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\PublicBookingAccessService;
use MustHotelBooking\Engine\ReservationEngine;
use MustHotelBooking\Engine\RatePlanEngine;
use MustHotelBooking\Provider\Contracts\ReservationProviderInterface;
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
    private function ensureLocalRoomLock(int $roomId, string $checkin, string $checkout, string $sessionId = ''): bool
    {
        $sessionId = $sessionId !== '' ? $sessionId : LockEngine::getOrCreateSessionId();
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
        $ratePlanId = $this->resolveRatePlanIdForSelection($selection, $ratePlanId);

        if ($ratePlanId <= 0) {
            return [\__('Please select a rate plan before checkout.', 'must-hotel-booking')];
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
        $roomItemsPreview = \MustHotelBooking\Frontend\get_or_create_booking_quote_room_items(
            $context,
            $couponCode,
            $guestForm,
            true,
            'confirmation'
        );
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
            'redirect_url' => (new PublicBookingAccessService())->buildPublicUrl(
                ManagedPages::getBookingConfirmationPageUrl(),
                (array) ($result['reservation_ids'] ?? []),
                PublicBookingAccessService::PURPOSE_VIEW_CONFIRMATION
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
        $confirmationFlow = isset($options['confirmation_flow']) ? \sanitize_key((string) $options['confirmation_flow']) : 'legacy';
        $deferClockCreation = $reservationStatus === 'pending_payment' && $paymentStatus === 'pending';
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
        $lockSessionId = LockEngine::getOrCreateSessionId();
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
        $selectedRooms = \MustHotelBooking\Frontend\get_booking_selected_rooms();
        $flowData = \MustHotelBooking\Frontend\get_booking_selection_flow_data();
        $quoteDraft = isset($flowData['quote_draft']) && \is_array($flowData['quote_draft'])
            ? $flowData['quote_draft']
            : [];
        if (!BookingQuoteDraft::isValidFor($quoteDraft, $context, $selectedRooms, $couponCode, $guestForm)) {
            $reason = BookingQuoteDraft::validationFailureReason($quoteDraft, $context, $selectedRooms, $couponCode, $guestForm);
            $message = $reason === 'expired'
                ? \__('Your booking quote expired. Return to checkout to refresh availability and pricing before trying again. No reservation or payment was created.', 'must-hotel-booking')
                : \__('Your saved booking quote is missing, changed, or could not be verified. Return to checkout and review a fresh quote. No reservation or payment was created.', 'must-hotel-booking');
            return $this->errorResult([$message]);
        }
        $quoteDraft = BookingQuoteDraft::normalize($quoteDraft);
        $context['checkin'] = (string) ($quoteDraft['checkin'] ?? '');
        $context['checkout'] = (string) ($quoteDraft['checkout'] ?? '');
        $context['guests'] = (int) ($quoteDraft['guests'] ?? 1);
        $context['room_count'] = (int) ($quoteDraft['room_count'] ?? \count($selectedRoomIds));
        $context['accommodation_type'] = (string) ($quoteDraft['accommodation_type'] ?? '');
        $couponCode = (string) ($quoteDraft['coupon_code'] ?? '');
        $roomItemsPreview = BookingQuoteDraft::roomItems($quoteDraft);
        $roomGuestCounts = isset($roomItemsPreview['room_guest_counts']) && \is_array($roomItemsPreview['room_guest_counts'])
            ? $roomItemsPreview['room_guest_counts']
            : [];
        $selectedRatePlanMap = \MustHotelBooking\Frontend\get_booking_selected_room_rate_plan_map();
        $validatedRooms = [];
        foreach ($selectedRoomIds as $roomId) {
            $roomId = (int) $roomId;
            $ratePlanId = isset($selectedRatePlanMap[$roomId]) ? (int) $selectedRatePlanMap[$roomId] : 0;
            $selection = $this->roomSelection->resolve($roomId);
            if (!$this->ensureLocalRoomLock($roomId, (string) $context['checkin'], (string) $context['checkout'], $lockSessionId)) {
                return $this->errorResult([\__('One of your selected room locks has expired. Please return to accommodation and confirm your selection again.', 'must-hotel-booking')]);
            }
            $roomGuests = isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : (int) $context['guests'];
            if (!$this->availability->checkAvailabilityFresh(
                $roomId,
                (string) $context['checkin'],
                (string) $context['checkout'],
                $lockSessionId,
                $roomGuests,
                $ratePlanId,
                'final_revalidation'
            )) {
                $failureReason = \method_exists($this->availability, 'getLastAvailabilityFailureReason')
                    ? (string) $this->availability->getLastAvailabilityFailureReason()
                    : '';
                $message = $failureReason === 'provider_unconfirmed'
                    ? \__('Availability could not be confirmed. Please try again. No reservation or payment was created.', 'must-hotel-booking')
                    : \__('One of your selected rooms is no longer available. Return to checkout and choose an available room. No reservation or payment was created.', 'must-hotel-booking');

                return $this->errorResult([$message]);
            }
            $pricing = $this->quote->calculateTotalFresh(
                $roomId,
                (string) $context['checkin'],
                (string) $context['checkout'],
                $roomGuests,
                $couponCode,
                $ratePlanId
            );
            if (empty($pricing['success']) || !isset($pricing['total_price'])) {
                return $this->errorResult([\__('Unable to calculate final Clock booking total for one of the selected rooms.', 'must-hotel-booking')]);
            }
            if (!BookingQuoteDraft::pricingMatches($quoteDraft, $roomId, $ratePlanId, $pricing)) {
                return $this->errorResult([\__('The price changed since you reviewed the booking. Return to checkout to review the latest total before continuing. No reservation or payment was created.', 'must-hotel-booking')]);
            }
            if (!BookingQuoteDraft::guaranteePolicyMatches($quoteDraft, $roomId, $pricing)) {
                return $this->errorResult([\__('The payment or guarantee terms changed since you reviewed the booking. Return to checkout to review the latest terms. No reservation or payment was created.', 'must-hotel-booking')]);
            }
            if (!BookingQuoteDraft::cancellationPolicyMatches($quoteDraft, $roomId, $pricing)) {
                return $this->errorResult([\__('The cancellation policy changed since you reviewed the booking. Return to checkout to review the latest terms. No reservation or payment was created.', 'must-hotel-booking')]);
            }
            /*
             * Online Stripe/PokPay reservations require a verified Clock guarantee
             * policy so Clock can calculate Required Deposit correctly.
             */
            if (
                $reservationStatus === 'pending_payment'
                && (int) ($pricing['guarantee_policy_id'] ?? 0) <= 0
            ) {
                return $this->errorResult([
                    \__(
                        'Clock did not return a guarantee policy for the selected rate. The reservation was not created and no payment was collected.',
                        'must-hotel-booking'
                    ),
                ]);
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
        if ($deferClockCreation) {
            $batchResult = $this->mirror->createPendingMirrorReservationsFromLocks(
                $context,
                $guestForm,
                $guestId,
                $validatedRooms,
                [
                    'reservation_status' => $reservationStatus,
                    'payment_status' => $paymentStatus,
                    'confirmation_flow' => $confirmationFlow,
                    'coupon_code' => $couponCode,
                    'defer_provider_creation' => true,
                    'pending_guest_form' => $guestForm,
                ],
                $lockSessionId
            );
            if (empty($batchResult['success'])) {
                return $this->errorResult([
                    \__('The pending payment reservation could not be saved. Its exact room lock may have expired or the room may no longer be available.', 'must-hotel-booking'),
                ]);
            }
            $reservationIds = isset($batchResult['reservation_ids']) && \is_array($batchResult['reservation_ids'])
                ? \array_values(\array_filter(\array_map('intval', $batchResult['reservation_ids']), static function (int $reservationId): bool {
                    return $reservationId > 0;
                }))
                : [];
            if (\count($reservationIds) !== \count($validatedRooms)) {
                return $this->errorResult([\__('The pending payment reservation could not be saved.', 'must-hotel-booking')]);
            }
            foreach ($validatedRooms as $validatedRoom) {
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
        foreach ($validatedRooms as $validatedRoom) {
            $websiteBookingReference = $this->mirror->generateBookingReference();
            $providerData = [];
            if (!$deferClockCreation) {
                $providerReservation = $this->createClockBooking($context, $guestForm, $validatedRoom, $clockStatus, $returningGuestId, $websiteBookingReference);
                if (empty($providerReservation['success'])) {
                    return $this->errorResult([(string) ($providerReservation['message'] ?? \__('Unable to create the booking in Clock.', 'must-hotel-booking'))]);
                }
                $providerData = isset($providerReservation['reservation']) && \is_array($providerReservation['reservation'])
                    ? $providerReservation['reservation']
                    : [];
                $providerData['_mhb_reference_mapping'] = [
                    'website_booking_reference' => $websiteBookingReference,
                    'website_reference_sent_to_clock' => true,
                    'clock_reference_storage_field' => (string) ($providerReservation['clock_reference_storage_field'] ?? ''),
                    'clock_reference_fallback_fields' => (array) ($providerReservation['clock_reference_fallback_fields'] ?? []),
                    'clock_reference_text' => (string) ($providerReservation['clock_reference_text'] ?? ''),
                ];
            }
            $mirrorOptions = [
                'booking_id' => $websiteBookingReference,
                'reservation_status' => $reservationStatus,
                'payment_status' => $paymentStatus,
                'confirmation_flow' => $confirmationFlow,
                'coupon_code' => $couponCode,
            ];
            if ($deferClockCreation) {
                $mirrorOptions['defer_provider_creation'] = true;
                $mirrorOptions['pending_guest_form'] = $guestForm;
            }
            $reservationId = $this->mirror->createMirrorReservation(
                $context,
                $guestForm,
                $guestId,
                $validatedRoom,
                $providerData,
                $mirrorOptions
            );
            if ($reservationId <= 0) {
                return $this->errorResult([
                    $deferClockCreation
                        ? \__('The pending payment reservation could not be saved.', 'must-hotel-booking')
                        : \__('Clock reservation was created, but the local mirror reservation could not be saved.', 'must-hotel-booking'),
                ]);
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

    /**
     * Create the Clock reservation for an already verified online payment.
     *
     * @param array<int, int> $reservationIds
     * @return array<string, mixed>
     */
    public function fulfillPendingOnlinePayment(array $reservationIds, string $method, string $paymentReference, bool $allowManualReviewRecovery = false): array
    {
        $method = \sanitize_key($method);
        $reservationIds = \array_values(\array_unique(\array_filter(\array_map('intval', $reservationIds), static function (int $id): bool {
            return $id > 0;
        })));
        $paymentReference = \sanitize_text_field($paymentReference);

        if (!\in_array($method, ['stripe', 'pokpay'], true) || empty($reservationIds) || $paymentReference === '') {
            return [
                'success' => false,
                'state' => 'pending_fulfilment',
                'retryable' => false,
                'message' => \__('The verified payment could not be bound to a Clock reservation.', 'must-hotel-booking'),
            ];
        }

        $repository = \MustHotelBooking\Engine\get_reservation_repository();
        $fulfilledReservationIds = [];
        foreach ($reservationIds as $reservationId) {
            $reservation = $repository->getReservation($reservationId);
            if (!\is_array($reservation)) {
                return [
                    'success' => false,
                    'state' => empty($fulfilledReservationIds) ? 'pending_fulfilment' : 'partial_manual_review',
                    'retryable' => false,
                    'reason_code' => 'clock_local_reservation_missing',
                    'reservation_ids' => $reservationIds,
                    'fulfilled_reservation_ids' => $fulfilledReservationIds,
                    'message' => \__('The local payment reservation could not be found.', 'must-hotel-booking'),
                ];
            }

            if (\trim((string) ($reservation['provider_booking_id'] ?? '')) !== ''
                || \trim((string) ($reservation['provider_reservation_id'] ?? '')) !== '') {
                $fulfilledReservationIds[] = $reservationId;
                continue;
            }

            if (\sanitize_key((string) ($reservation['status'] ?? '')) !== 'pending_payment'
                || \sanitize_key((string) ($reservation['payment_status'] ?? '')) !== 'pending') {
                return [
                    'success' => false,
                    'state' => empty($fulfilledReservationIds) ? 'pending_fulfilment' : 'partial_manual_review',
                    'retryable' => false,
                    'reason_code' => 'clock_reservation_state_mismatch',
                    'reservation_ids' => $reservationIds,
                    'fulfilled_reservation_ids' => $fulfilledReservationIds,
                    'message' => \__('This payment reservation is no longer awaiting Clock fulfillment.', 'must-hotel-booking'),
                ];
            }

            $claimKey = 'mhb-clock-payment-' . \substr(\hash('sha256', $reservationId . '|' . $method . '|' . $paymentReference), 0, 48);
            $ownerToken = \substr(\hash('sha256', \wp_generate_uuid4() . '|' . \microtime(true)), 0, 64);
            $claim = $allowManualReviewRecovery
                ? $repository->claimManualReviewClockReservation($reservationId, $claimKey, $ownerToken)
                : $repository->claimPendingClockReservation($reservationId, $claimKey, $ownerToken);
            $claimOutcome = \sanitize_key((string) ($claim['outcome'] ?? 'blocked'));
            if ($claimOutcome === 'already_fulfilled') {
                $fulfilledReservationIds[] = $reservationId;
                continue;
            }
            if ($claimOutcome === 'in_progress') {
                return [
                    'success' => false,
                    'state' => 'pending_fulfilment',
                    'retryable' => true,
                    'reason_code' => 'clock_fulfilment_in_progress',
                    'reservation_ids' => $reservationIds,
                    'fulfilled_reservation_ids' => $fulfilledReservationIds,
                    'message' => \__('Clock reservation fulfillment is already in progress.', 'must-hotel-booking'),
                ];
            }
            if ($claimOutcome === 'expired_claim_recovery') {
                return $this->markPendingClockFulfilmentManualReview(
                    $repository,
                    $reservationIds,
                    $fulfilledReservationIds,
                    \__('A previous Clock fulfillment lease expired. Clock must be reread before another create attempt.', 'must-hotel-booking'),
                    'expired_claim_recovery'
                );
            }
            if ($claimOutcome !== 'claimed') {
                return [
                    'success' => false,
                    'state' => empty($fulfilledReservationIds) ? 'pending_fulfilment' : 'partial_manual_review',
                    'retryable' => false,
                    'reason_code' => 'clock_fulfilment_claim_blocked',
                    'reservation_ids' => $reservationIds,
                    'fulfilled_reservation_ids' => $fulfilledReservationIds,
                    'message' => \__('The verified payment is held for Clock reservation fulfillment.', 'must-hotel-booking'),
                ];
            }

            $claimMetadata = $this->decodeProviderMetadata($reservation['provider_metadata'] ?? null);
            $claimMetadata['website_payment_verified'] = true;
            $claimMetadata['pending_clock_creation'] = true;
            $claimMetadata['clock_fulfilment'] = [
                'state' => 'create_may_be_in_flight',
                'website_booking_reference' => (string) ($reservation['booking_id'] ?? ''),
                'idempotency_key' => $claimKey,
                'claimed_at' => \current_time('mysql'),
                'recovery_required' => 'reread_clock_before_create_if_ambiguous',
            ];
            if (!$repository->updateClaimedClockReservation($reservationId, $claimKey, $ownerToken, [
                'provider_metadata' => $claimMetadata,
            ])) {
                return $this->markPendingClockFulfilmentManualReview(
                    $repository,
                    $reservationIds,
                    $fulfilledReservationIds,
                    \__('Clock fulfillment intent could not be stored before creating the reservation.', 'must-hotel-booking'),
                    'clock_create_intent_persistence_failed'
                );
            }

            $result = $this->createClockReservationForPendingPayment($reservation, $claimKey, $ownerToken);
            if (empty($result['success'])) {
                return $this->markPendingClockFulfilmentManualReview(
                    $repository,
                    $reservationIds,
                    $fulfilledReservationIds,
                    (string) ($result['message'] ?? \__('Clock reservation creation could be ambiguous after payment verification.', 'must-hotel-booking')),
                    (string) ($result['reason_code'] ?? 'clock_create_requires_reread')
                );
            }
            $fulfilledReservationIds[] = $reservationId;
        }

        return [
            'success' => true,
            'state' => 'clock_created',
            'reservation_ids' => $reservationIds,
        ];
    }

    /**
     * A paid group with a failed, partial, or expired Clock write is not safe
     * to retry automatically. The durable payment evidence remains intact and
     * staff must reread Clock using the website reference before recovery.
     *
     * @param array<int, int> $reservationIds
     * @param array<int, int> $fulfilledReservationIds
     * @return array<string, mixed>
     */
    private function markPendingClockFulfilmentManualReview(
        $repository,
        array $reservationIds,
        array $fulfilledReservationIds,
        string $message,
        string $reason
    ): array {
        $fulfilledReservationIds = \array_values(\array_unique(\array_filter(\array_map('intval', $fulfilledReservationIds))));
        $persistenceFailures = [];
        foreach ($reservationIds as $reservationId) {
            $reservation = $repository->getReservation((int) $reservationId);
            if (!\is_array($reservation)) {
                continue;
            }
            $metadata = $this->decodeProviderMetadata($reservation['provider_metadata'] ?? null);
            $metadata['website_payment_verified'] = true;
            $metadata['pending_clock_creation'] = false;
            $metadata['clock_fulfilment'] = [
                'state' => empty($fulfilledReservationIds) ? 'manual_review' : 'partial_manual_review',
                'reason' => \sanitize_key($reason),
                'website_booking_reference' => (string) ($reservation['booking_id'] ?? ''),
                'fulfilled_reservation_ids' => $fulfilledReservationIds,
                'recovery_required' => 'reread_clock_before_create',
                'recorded_at' => \current_time('mysql'),
            ];
            if (!$repository->updateProviderMetadata((int) $reservationId, [
                'provider_sync_status' => 'manual_review',
                'provider_sync_error' => \sanitize_text_field($message),
                'provider_metadata' => $metadata,
            ])) {
                $persistenceFailures[] = (int) $reservationId;
            }
        }

        return [
            'success' => false,
            'state' => empty($fulfilledReservationIds) ? 'manual_review' : 'partial_manual_review',
            'retryable' => !empty($persistenceFailures),
            'reason_code' => !empty($persistenceFailures) ? 'manual_review_persistence_failed' : \sanitize_key($reason),
            'reservation_ids' => \array_values(\array_map('intval', $reservationIds)),
            'fulfilled_reservation_ids' => $fulfilledReservationIds,
            'persistence_failures' => $persistenceFailures,
            'message' => $message,
        ];
    }

    /** @param array<string, mixed> $reservation @return array<string, mixed> */
    private function createClockReservationForPendingPayment(array $reservation, string $idempotencyKey, string $ownerToken): array
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        $roomId = isset($reservation['room_id']) ? (int) $reservation['room_id'] : 0;
        $assignedRoomId = isset($reservation['assigned_room_id']) ? (int) $reservation['assigned_room_id'] : 0;
        $ratePlanId = isset($reservation['rate_plan_id']) ? (int) $reservation['rate_plan_id'] : 0;
        $checkin = (string) ($reservation['checkin'] ?? '');
        $checkout = (string) ($reservation['checkout'] ?? '');
        $guests = \max(1, (int) ($reservation['guests'] ?? 1));
        $couponCode = (string) ($reservation['coupon_code'] ?? '');
        $metadata = $this->decodeProviderMetadata($reservation['provider_metadata'] ?? null);

        if ($reservationId <= 0 || $roomId <= 0 || $checkin === '' || $checkout === '') {
            return ['success' => false, 'message' => \__('The pending Clock reservation data is incomplete.', 'must-hotel-booking')];
        }
        if ($assignedRoomId <= 0) {
            return ['success' => false, 'message' => \__('The exact selected room could not be restored from the paid reservation.', 'must-hotel-booking')];
        }
        if (isset($metadata['physical_room_id'])
            && (int) ($metadata['physical_room_id'] ?? 0) > 0
            && (int) ($metadata['physical_room_id'] ?? 0) !== $assignedRoomId
        ) {
            return ['success' => false, 'message' => \__('The paid reservation has conflicting selected-room evidence.', 'must-hotel-booking')];
        }

        $repository = \MustHotelBooking\Engine\get_reservation_repository();
        if (!$repository->ownsPendingClockReservation($reservationId, $idempotencyKey, $ownerToken)) {
            return ['success' => false, 'message' => \__('The Clock fulfillment lease is no longer owned by this request.', 'must-hotel-booking')];
        }

        $guestForm = $this->pendingGuestForm($metadata, (int) ($reservation['guest_id'] ?? 0));
        if ($guestForm['first_name'] === '' || $guestForm['last_name'] === '' || $guestForm['email'] === '') {
            return ['success' => false, 'message' => \__('The saved guest details are incomplete for Clock fulfillment.', 'must-hotel-booking')];
        }

        if (!$this->availability->checkAvailabilityFresh(
            $assignedRoomId,
            $checkin,
            $checkout,
            LockEngine::getOrCreateSessionId(),
            $guests,
            $ratePlanId,
            'paid_fulfilment',
            $reservationId
        )) {
            $failureReason = \method_exists($this->availability, 'getLastAvailabilityFailureReason')
                ? (string) $this->availability->getLastAvailabilityFailureReason()
                : '';

            return [
                'success' => false,
                'reason_code' => $failureReason === 'provider_unconfirmed'
                    ? 'clock_exact_room_provider_unconfirmed'
                    : 'clock_exact_room_unavailable',
                'message' => $failureReason === 'provider_unconfirmed'
                    ? \__('Clock could not confirm exact-room availability after payment verification.', 'must-hotel-booking')
                    : \__('The selected room is no longer available in Clock after payment verification.', 'must-hotel-booking'),
            ];
        }

        $pricing = $this->quote->calculateTotalFresh($roomId, $checkin, $checkout, $guests, $couponCode, $ratePlanId);
        if (empty($pricing['success']) || !isset($pricing['total_price'])) {
            return ['success' => false, 'message' => \__('Clock could not revalidate the paid reservation total.', 'must-hotel-booking')];
        }
        if (\abs((float) $pricing['total_price'] - (float) ($reservation['total_price'] ?? 0.0)) > 0.01) {
            return ['success' => false, 'message' => \__('The Clock reservation total changed after payment verification.', 'must-hotel-booking')];
        }
        if ((int) ($pricing['guarantee_policy_id'] ?? 0) <= 0) {
            return ['success' => false, 'message' => \__('Clock did not return a valid guarantee policy for the paid reservation.', 'must-hotel-booking')];
        }

        $selection = $this->roomSelection->resolve($assignedRoomId);
        if (!\is_array($selection)) {
            return [
                'success' => false,
                'reason_code' => 'clock_exact_room_mapping_missing',
                'message' => \__('The selected Clock room mapping could not be restored.', 'must-hotel-booking'),
            ];
        }
        $roomMapping = isset($selection['room_mapping']) && \is_array($selection['room_mapping']) ? $selection['room_mapping'] : null;
        $physicalMapping = isset($selection['physical_mapping']) && \is_array($selection['physical_mapping']) ? $selection['physical_mapping'] : null;
        $snapshotRoomMapping = isset($metadata['room_mapping']) && \is_array($metadata['room_mapping'])
            ? $metadata['room_mapping']
            : [];
        $snapshotPhysicalMapping = isset($metadata['physical_mapping']) && \is_array($metadata['physical_mapping'])
            ? $metadata['physical_mapping']
            : [];
        $snapshotRatePlanMapping = isset($metadata['rate_plan_mapping']) && \is_array($metadata['rate_plan_mapping'])
            ? $metadata['rate_plan_mapping']
            : [];
        $ratePlanMapping = $this->ratePlanMappingForPricing($pricing, $ratePlanId);
        $resolvedRatePlanId = $ratePlanId > 0 ? $ratePlanId : (int) ($ratePlanMapping['local_id'] ?? 0);
        if (!$this->hasExternalId($roomMapping) || !$this->hasExternalId($ratePlanMapping)) {
            return [
                'success' => false,
                'reason_code' => 'clock_exact_room_mapping_missing',
                'message' => \__('Clock mapping is missing for the paid reservation.', 'must-hotel-booking'),
            ];
        }
        if (
            empty($selection['is_physical'])
            || (int) ($selection['physical_room_id'] ?? 0) !== $assignedRoomId
            || (int) ($selection['room_type_id'] ?? 0) !== $roomId
            || !$this->hasExternalId($physicalMapping)
        ) {
            return [
                'success' => false,
                'reason_code' => 'clock_exact_room_mapping_missing',
                'message' => \__('Clock could not restore the exact selected room after payment.', 'must-hotel-booking'),
            ];
        }
        if (
            (string) ($snapshotRoomMapping['external_id'] ?? '') === ''
            || (string) ($snapshotPhysicalMapping['external_id'] ?? '') === ''
            || (string) ($snapshotRatePlanMapping['external_id'] ?? '') === ''
            || (string) ($snapshotRoomMapping['external_id'] ?? '') !== (string) ($roomMapping['external_id'] ?? '')
            || (string) ($snapshotPhysicalMapping['external_id'] ?? '') !== (string) ($physicalMapping['external_id'] ?? '')
            || (string) ($snapshotRatePlanMapping['external_id'] ?? '') !== (string) ($ratePlanMapping['external_id'] ?? '')
        ) {
            return [
                'success' => false,
                'reason_code' => 'clock_exact_room_mapping_drift',
                'message' => \__('The saved Clock room or rate mapping is incomplete or changed after payment.', 'must-hotel-booking'),
            ];
        }

        $validatedRoom = [
            'room_id' => $roomId,
            'rate_plan_id' => $resolvedRatePlanId,
            'guests' => $guests,
            'pricing' => $pricing,
            'room_mapping' => $this->mappingSummary($roomMapping),
            'physical_room_id' => $assignedRoomId,
            'room_type_id' => isset($reservation['room_type_id']) ? (int) $reservation['room_type_id'] : $roomId,
            'physical_mapping' => $this->mappingSummary($physicalMapping),
            'rate_plan_mapping' => $this->mappingSummary($ratePlanMapping),
        ];
        $context = [
            'is_valid' => true,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'room_count' => 1,
            'accommodation_type' => '',
        ];
        if (!$repository->ownsPendingClockReservation($reservationId, $idempotencyKey, $ownerToken)) {
            return ['success' => false, 'message' => \__('The Clock fulfillment lease expired before reservation creation.', 'must-hotel-booking')];
        }
        $providerReservation = $this->createClockBooking(
            $context,
            $guestForm,
            $validatedRoom,
            'expected',
            $this->findReturningGuestId($guestForm),
            (string) ($reservation['booking_id'] ?? ''),
            $idempotencyKey
        );
        if (empty($providerReservation['success'])) {
            return ['success' => false, 'message' => (string) ($providerReservation['message'] ?? \__('Clock reservation creation failed after payment verification.', 'must-hotel-booking'))];
        }

        $providerData = isset($providerReservation['reservation']) && \is_array($providerReservation['reservation'])
            ? $providerReservation['reservation']
            : [];
        $identifiers = ClockBookingReferenceMapper::extractClockIdentifiers($providerData);
        $providerBookingId = (string) ($identifiers['clock_booking_id'] ?? '');
        $providerReservationId = $this->firstString($providerData, ['reservation_id', 'provider_reservation_id', 'id', 'booking_id']);
        if ($providerReservationId === '') {
            $providerReservationId = $providerBookingId;
        }
        if ($providerBookingId === '' && $providerReservationId === '') {
            return ['success' => false, 'message' => \__('Clock did not return a reservation identifier after payment verification.', 'must-hotel-booking')];
        }

        $referenceMapping = [
            'website_booking_reference' => (string) ($reservation['booking_id'] ?? ''),
            'website_reference_sent_to_clock' => true,
            'clock_reference_storage_field' => (string) ($providerReservation['clock_reference_storage_field'] ?? ''),
            'clock_reference_fallback_fields' => (array) ($providerReservation['clock_reference_fallback_fields'] ?? []),
            'clock_reference_text' => (string) ($providerReservation['clock_reference_text'] ?? ''),
        ];
        $metadata['pending_clock_creation'] = false;
        $metadata['website_payment_verified'] = true;
        $metadata['provider_created_before_payment'] = false;
        $metadata['clock_booking_id'] = $providerBookingId;
        $metadata['clock_booking_reference'] = (string) ($identifiers['clock_booking_reference'] ?? '');
        $metadata['website_reference_sent_to_clock'] = true;
        $metadata['clock_reference_storage_field'] = $referenceMapping['clock_reference_storage_field'];
        $metadata['clock_reference_fallback_fields'] = $referenceMapping['clock_reference_fallback_fields'];
        $metadata['clock_reference_text'] = $referenceMapping['clock_reference_text'];
        $metadata['provider_response'] = $this->responseSummary($providerData);
        $metadata['clock_fulfilment'] = [
            'state' => 'fulfilled',
            'website_booking_reference' => (string) ($reservation['booking_id'] ?? ''),
            'fulfilled_at' => \current_time('mysql'),
        ];
        if (\function_exists('MustHotelBooking\\Frontend\\build_price_breakdown_snapshot')) {
            $metadata['pricing_snapshot'] = \MustHotelBooking\Frontend\build_price_breakdown_snapshot($pricing);
        }
        unset($metadata['pending_guest_form']);

        $updated = $repository->updateClaimedClockReservation($reservationId, $idempotencyKey, $ownerToken, [
            'provider' => ProviderManager::CLOCK_MODE,
            'provider_booking_id' => $providerBookingId,
            'provider_reservation_id' => $providerReservationId,
            'provider_status' => $this->firstString($providerData, ['status', 'state', 'reservation_status']),
            'provider_sync_status' => 'synced',
            'provider_synced_at' => \current_time('mysql'),
            'provider_sync_error' => '',
            'provider_fulfilment_owner' => '',
            'provider_fulfilment_lease_expires_at' => null,
            'provider_payload_ref' => $providerReservationId !== '' ? $providerReservationId : $providerBookingId,
            'provider_metadata' => $metadata,
        ]);
        if (!$updated) {
            return ['success' => false, 'message' => \__('Clock was created but the local Clock reference could not be saved.', 'must-hotel-booking')];
        }

        $this->logReferenceDiagnostics($reservationId, (string) ($reservation['booking_id'] ?? ''), $providerBookingId, (string) ($identifiers['clock_booking_reference'] ?? ''), $referenceMapping);
        return ['success' => true, 'provider_booking_id' => $providerBookingId, 'provider_reservation_id' => $providerReservationId];
    }

    /** @param array<string, mixed> $metadata @return array<string, string> */
    private function pendingGuestForm(array $metadata, int $guestId): array
    {
        $stored = isset($metadata['pending_guest_form']) && \is_array($metadata['pending_guest_form']) ? $metadata['pending_guest_form'] : [];
        $guest = \MustHotelBooking\Engine\get_guest_repository()->getGuestById($guestId);
        $guest = \is_array($guest) ? $guest : [];
        $phone = isset($stored['phone_number']) ? (string) $stored['phone_number'] : (string) ($guest['phone'] ?? '');
        return [
            'first_name' => (string) ($stored['first_name'] ?? $guest['first_name'] ?? ''),
            'last_name' => (string) ($stored['last_name'] ?? $guest['last_name'] ?? ''),
            'email' => \sanitize_email((string) ($stored['email'] ?? $guest['email'] ?? '')),
            'phone_country_code' => (string) ($stored['phone_country_code'] ?? ''),
            'phone_number' => $phone,
            'country' => (string) ($stored['country'] ?? $guest['country'] ?? ''),
            'address' => (string) ($stored['address'] ?? ''),
            'city' => (string) ($stored['city'] ?? ''),
            'zip_code' => (string) ($stored['zip_code'] ?? ''),
        ];
    }

    /** @param mixed $value @return array<string, mixed> */
    private function decodeProviderMetadata($value): array
    {
        if (\is_array($value)) {
            return $value;
        }
        if (!\is_string($value) || \trim($value) === '') {
            return [];
        }
        $decoded = \json_decode($value, true);
        return \is_array($decoded) ? $decoded : [];
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
    private function resolveRatePlanIdForSelection(array $selection, int $ratePlanId): int
    {
        if ($ratePlanId > 0) {
            return $ratePlanId;
        }

        $roomTypeId = isset($selection['room_type_id']) ? (int) $selection['room_type_id'] : 0;

        if ($roomTypeId <= 0) {
            return 0;
        }

        $eligibleIds = [];

        foreach (\MustHotelBooking\Engine\RatePlanEngine::getRatePlansForRoomType($roomTypeId) as $plan) {
            if (!\is_array($plan)) {
                continue;
            }

            if (isset($plan['is_active']) && (int) $plan['is_active'] !== 1) {
                continue;
            }

            $candidateId = isset($plan['id']) ? (int) $plan['id'] : 0;

            if ($candidateId <= 0) {
                continue;
            }

            $mapping = $this->catalog->findRatePlanMapping($candidateId);

            if (!$this->hasExternalId($mapping) || !$this->isMappingPublicVisible($mapping)) {
                continue;
            }

            $eligibleIds[$candidateId] = $candidateId;
        }

        if (\count($eligibleIds) !== 1) {
            return 0;
        }

        return (int) \reset($eligibleIds);
    }

    /** @param array<string, mixed>|null $mapping */
    private function isMappingPublicVisible(?array $mapping): bool
    {
        if (!\is_array($mapping)) {
            return false;
        }

        $metadata = $this->decodeProviderMetadata($mapping['metadata'] ?? []);
        $visibility = isset($metadata['public_visible']) && \is_scalar($metadata['public_visible'])
            ? (string) $metadata['public_visible']
            : '';

        return $visibility !== 'no';
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
    private function createClockBooking(array $context, array $guestForm, array $validatedRoom, string $clockStatus, string $returningGuestId = '', string $websiteBookingReference = '', string $idempotencyKeyOverride = ''): array
    {
        $idempotencyKey = $idempotencyKeyOverride !== ''
            ? $idempotencyKeyOverride
            : $this->idempotencyKey($context, $guestForm, [$validatedRoom], $clockStatus, 'clock_pms');
        $payload = $this->bookingCreatePayload($context, $guestForm, $validatedRoom, $clockStatus, $returningGuestId, $websiteBookingReference);
        $body = $payload['payload'];
        $response = $this->client->request(
            'POST',
            ClockConfig::reservationCreatePath(),
            [
                'idempotency_key' => $idempotencyKey,
                'body' => $body,
                'booking_reference' => $websiteBookingReference,
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
            'clock_reference_storage_field' => (string) ($payload['primary_field'] ?? ''),
            'clock_reference_fallback_fields' => (array) ($payload['fallback_fields'] ?? []),
            'clock_reference_text' => (string) ($payload['reference_text'] ?? ''),
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
    private function bookingCreatePayload(array $context, array $guestForm, array $validatedRoom, string $clockStatus, string $returningGuestId, string $websiteBookingReference = ''): array
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
            'reference_number' => $websiteBookingReference,
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

        $guaranteePolicyId = isset($pricing['guarantee_policy_id'])
            ? (int) $pricing['guarantee_policy_id']
            : 0;

        if ($guaranteePolicyId > 0) {
            $booking['guarantee_policy_id'] = $guaranteePolicyId;
        }
        $payload = [
            'booking' => $booking,
        ];
        if ($returningGuestId !== '') {
            $payload['main_booking_guest'] = $returningGuestId;
        }
        return ClockBookingReferenceMapper::applyWebsiteReferenceToPayload($payload, $websiteBookingReference);
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
