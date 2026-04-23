<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Engine\BookingAbuseProtection;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\ReservationEngine;
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

    public function __construct(
        ?ClockApiClient $client = null,
        ?ClockCatalogService $catalog = null,
        ?ClockAvailabilityProvider $availability = null,
        ?ClockQuoteProvider $quote = null,
        ?ClockMirrorReservationService $mirror = null
    ) {
        $this->client = $client ?: new ClockApiClient();
        $this->catalog = $catalog ?: new ClockCatalogService($this->client);
        $this->availability = $availability ?: new ClockAvailabilityProvider($this->client, $this->catalog);
        $this->quote = $quote ?: new ClockQuoteProvider($this->client, $this->catalog);
        $this->mirror = $mirror ?: new ClockMirrorReservationService();
    }

    public function buildReservationNote(int $roomId, array $guestForm): string
    {
        return ReservationEngine::buildReservationNote($roomId, $guestForm);
    }

    public function getCheckoutRoomData(int $roomId): ?array
    {
        return ReservationEngine::getCheckoutRoomData($roomId);
    }

    public function getRoomGuestAllocations(array $rooms, int $totalGuests, array $guestForm = [], bool $strict = false): array
    {
        return ReservationEngine::getRoomGuestAllocations($rooms, $totalGuests, $guestForm, $strict);
    }

    public function ensureRoomLock(int $roomId, string $checkin, string $checkout): bool
    {
        if (!$this->availability->checkAvailability($roomId, $checkin, $checkout)) {
            return false;
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

        return !empty($precheck) ? $precheck : ReservationEngine::bootstrapCheckoutSelectionFromRequest($source);
    }

    public function handleBookingRoomSelectionRequest(array $requestSource): array
    {
        $action = isset($requestSource['must_booking_action']) ? \sanitize_key((string) \wp_unslash($requestSource['must_booking_action'])) : '';

        if ($action === 'select_room') {
            $precheck = $this->precheckSelectionSource($requestSource, 'room_id');

            if (!empty($precheck)) {
                return [
                    'handled' => true,
                    'success' => false,
                    'message' => $precheck[0],
                    'redirect_url' => '',
                ];
            }
        }

        return ReservationEngine::handleBookingRoomSelectionRequest($requestSource);
    }

    public function handleAccommodationRoomSelectionRequest(array $requestSource): array
    {
        $action = isset($requestSource['must_accommodation_action'])
            ? \sanitize_key((string) \wp_unslash($requestSource['must_accommodation_action']))
            : '';

        if ($action === 'select_room') {
            $context = BookingValidationEngine::parseRequestContext($requestSource, true);
            $roomId = isset($requestSource['room_id']) ? \absint(\wp_unslash($requestSource['room_id'])) : 0;

            if (!empty($context['is_valid']) && !$this->availability->checkAvailability($roomId, (string) $context['checkin'], (string) $context['checkout'])) {
                return [
                    'success' => false,
                    'messages' => [\__('This room is no longer available in Clock for the selected dates.', 'must-hotel-booking')],
                    'context' => $context,
                    'redirect_url' => '',
                    'should_redirect' => false,
                ];
            }
        }

        return ReservationEngine::handleAccommodationRoomSelectionRequest($requestSource);
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

            if (!$this->ensureRoomLock($roomId, (string) $context['checkin'], (string) $context['checkout'])) {
                return $this->errorResult([\__('One of your selected room locks has expired. Please return to accommodation and confirm your selection again.', 'must-hotel-booking')]);
            }

            if (!$this->availability->checkAvailability($roomId, (string) $context['checkin'], (string) $context['checkout'])) {
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

            $roomMapping = $this->catalog->findAccommodationMapping($roomId);
            $ratePlanMapping = $ratePlanId > 0 ? $this->catalog->findRatePlanMapping($ratePlanId) : null;

            if (!$this->hasExternalId($roomMapping) || ($ratePlanId > 0 && !$this->hasExternalId($ratePlanMapping))) {
                return $this->errorResult([\__('Clock mapping is missing for one of the selected rooms or rate plans.', 'must-hotel-booking')]);
            }

            $validatedRooms[] = [
                'room_id' => $roomId,
                'rate_plan_id' => $ratePlanId,
                'guests' => isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : (int) $context['guests'],
                'pricing' => $pricing,
                'room_mapping' => $this->mappingSummary($roomMapping),
                'rate_plan_mapping' => $this->mappingSummary($ratePlanMapping),
            ];
        }

        $guestId = ReservationEngine::createGuest($guestForm);

        if ($guestId <= 0) {
            return $this->errorResult([\__('Unable to save guest details.', 'must-hotel-booking')]);
        }

        $providerReservation = $this->createClockReservation($context, $guestForm, $validatedRooms, $reservationStatus, $paymentStatus);

        if (empty($providerReservation['success'])) {
            return $this->errorResult([(string) ($providerReservation['message'] ?? \__('Unable to create the reservation in Clock.', 'must-hotel-booking'))]);
        }

        $providerData = isset($providerReservation['reservation']) && \is_array($providerReservation['reservation'])
            ? $providerReservation['reservation']
            : [];
        $reservationIds = [];
        $appliedCouponIds = [];

        foreach ($validatedRooms as $validatedRoom) {
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
        $roomId = isset($source[$roomKey]) ? \absint(\wp_unslash($source[$roomKey])) : 0;

        if ($roomId <= 0) {
            return [];
        }

        $context = BookingValidationEngine::parseRequestContext($source, true);

        if (empty($context['is_valid'])) {
            return [];
        }

        return $this->availability->checkAvailability($roomId, (string) $context['checkin'], (string) $context['checkout'])
            ? []
            : [\__('This room is no longer available in Clock for the selected dates.', 'must-hotel-booking')];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @param array<int, array<string, mixed>> $validatedRooms
     * @return array<string, mixed>
     */
    private function createClockReservation(array $context, array $guestForm, array $validatedRooms, string $reservationStatus, string $paymentStatus): array
    {
        $idempotencyKey = $this->idempotencyKey($context, $guestForm, $validatedRooms, $reservationStatus, $paymentStatus);
        $response = $this->client->request(
            'POST',
            ClockConfig::reservationCreatePath(),
            [
                'idempotency_key' => $idempotencyKey,
                'body' => [
                    'property_id' => ClockConfig::propertyId(),
                    'idempotency_key' => $idempotencyKey,
                    'checkin' => (string) ($context['checkin'] ?? ''),
                    'checkout' => (string) ($context['checkout'] ?? ''),
                    'reservation_status' => $reservationStatus,
                    'payment_status' => $paymentStatus,
                    'guest' => [
                        'first_name' => (string) ($guestForm['first_name'] ?? ''),
                        'last_name' => (string) ($guestForm['last_name'] ?? ''),
                        'email' => (string) ($guestForm['email'] ?? ''),
                        'phone' => \MustHotelBooking\Frontend\combine_checkout_phone_value($guestForm),
                        'country' => (string) ($guestForm['country'] ?? ''),
                    ],
                    'rooms' => $this->providerRoomPayloads($validatedRooms),
                    'total_price' => $this->totalPrice($validatedRooms),
                    'currency' => \MustHotelBooking\Core\MustBookingConfig::get_currency(),
                    'source' => 'website',
                ],
            ],
            'clock.reservation_create'
        );

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => $response->getErrorMessage() !== '' ? $response->getErrorMessage() : \__('Clock reservation create request failed.', 'must-hotel-booking'),
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

        return [
            'success' => true,
            'reservation' => $reservation,
        ];
    }

    /** @param array<int, array<string, mixed>> $validatedRooms @return array<int, array<string, mixed>> */
    private function providerRoomPayloads(array $validatedRooms): array
    {
        $rooms = [];

        foreach ($validatedRooms as $validatedRoom) {
            $pricing = isset($validatedRoom['pricing']) && \is_array($validatedRoom['pricing']) ? $validatedRoom['pricing'] : [];
            $roomMapping = isset($validatedRoom['room_mapping']) && \is_array($validatedRoom['room_mapping']) ? $validatedRoom['room_mapping'] : [];
            $ratePlanMapping = isset($validatedRoom['rate_plan_mapping']) && \is_array($validatedRoom['rate_plan_mapping']) ? $validatedRoom['rate_plan_mapping'] : [];

            $rooms[] = [
                'local_room_id' => isset($validatedRoom['room_id']) ? (int) $validatedRoom['room_id'] : 0,
                'provider_room_id' => (string) ($roomMapping['external_id'] ?? ''),
                'provider_room_code' => (string) ($roomMapping['external_code'] ?? ''),
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
