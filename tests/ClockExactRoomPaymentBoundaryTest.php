<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function __($message, $domain = ''): string { unset($domain); return (string) $message; }
    function sanitize_key($value): string { return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function sanitize_text_field($value): string { return \trim((string) $value); }
    function sanitize_email($value): string { return \trim((string) $value); }
    function wp_unslash($value) { return $value; }
    function absint($value): int { return \abs((int) $value); }
    function current_time(string $type): string { unset($type); return '2026-07-16 12:00:00'; }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Provider\Storage {
    final class ProviderMappingRepository
    {
        public function findByExternal(string $provider, string $entityType, string $externalId): ?array
        {
            unset($provider, $entityType);
            return $externalId === '900'
                ? ['id' => 30, 'local_id' => 30, 'external_id' => '900', 'external_code' => 'FLEX']
                : null;
        }
    }
}

namespace MustHotelBooking\Engine {
    final class BookingAbuseProtection
    {
        public const SURFACE_CHECKOUT = 'checkout';
        public static function guardSubmission(array $context, array $guestForm, array $options): array
        {
            unset($context, $guestForm, $options);
            return ['allowed' => true];
        }
        public static function getGenericFailureMessage(): string { return 'Blocked'; }
    }

    final class BookingValidationEngine
    {
        public static function validateGuestForm(array $guestForm): array { unset($guestForm); return []; }
        public static function parseRequestContext(array $source, bool $allowDefaults = false): array
        {
            unset($allowDefaults);
            $checkin=(string)($source['checkin'] ?? '');
            $checkout=(string)($source['checkout'] ?? '');
            $valid=$checkin !== '' && $checkout !== '' && $checkin < $checkout;
            return [
                'is_valid'=>$valid,
                'checkin'=>$checkin,
                'checkout'=>$checkout,
                'guests'=>max(1,(int)($source['guests'] ?? 1)),
                'room_count'=>max(1,(int)($source['room_count'] ?? 1)),
                'accommodation_type'=>(string)($source['accommodation_type'] ?? ''),
                'errors'=>$valid ? [] : ['Invalid booking context.'],
            ];
        }
        public static function applyFixedRoomContext(array $context, array $room): array
        {
            unset($room);
            return $context;
        }
    }

    final class RatePlanEngine
    {
        public static function getRatePlansForRoomType(int $roomTypeId): array
        {
            return $roomTypeId === 10
                ? [['id' => 30, 'is_active' => 1, 'max_occupancy' => 2]]
                : [];
        }

        public static function getRoomRatePlan(int $roomTypeId, int $ratePlanId): ?array
        {
            foreach (self::getRatePlansForRoomType($roomTypeId) as $plan) {
                if ((int) ($plan['id'] ?? 0) === $ratePlanId) {
                    return $plan;
                }
            }

            return null;
        }
    }

    final class BookingQuoteDraft
    {
        public static function isValidFor(array $draft, array $context, array $rooms, string $coupon, array $guest): bool
        {
            unset($draft, $context, $rooms, $coupon, $guest);
            return true;
        }
        public static function validationFailureReason(array $draft, array $context, array $rooms, string $coupon, array $guest): string
        {
            unset($draft, $context, $rooms, $coupon, $guest);
            return '';
        }
        public static function normalize(array $draft): array { return $draft; }
        public static function roomItems(array $draft): array { unset($draft); return ['room_guest_counts' => [21 => 2]]; }
        public static function pricingMatches(array $draft, int $roomId, int $ratePlanId, array $pricing): bool
        {
            unset($draft, $roomId, $ratePlanId, $pricing);
            return true;
        }
        public static function guaranteePolicyMatches(array $draft, int $roomId, array $pricing): bool
        {
            unset($draft, $roomId, $pricing);
            return true;
        }
        public static function cancellationPolicyMatches(array $draft, int $roomId, array $pricing): bool
        {
            unset($draft, $roomId, $pricing);
            return true;
        }
    }

    final class LockEngine
    {
        public static function getOrCreateSessionId(): string { return 'boundary-session'; }
        public static function hasExactLock(int $roomId, string $checkin, string $checkout, string $sessionId): bool
        {
            unset($roomId, $checkin, $checkout, $sessionId);
            return true;
        }
    }

    final class ReservationEngine
    {
        public static int $guestCreates = 0;
        public static function createGuest(array $guestForm): int { unset($guestForm); self::$guestCreates++; return 55; }
        public static function buildReservationNote(int $roomId, array $guestForm): string { unset($roomId, $guestForm); return 'Website booking'; }
    }

    final class PaymentEngine
    {
        public static int $pokPayOrderCreates = 0;
        public static function processPayment(): void { self::$pokPayOrderCreates++; }
    }

    final class BoundaryReservationRepository
    {
        /** @var array<string, mixed> */
        public array $reservation = [];
        public int $providerReferenceWrites = 0;
        public int $manualReviewWrites = 0;

        public function getReservation(int $reservationId): ?array
        {
            return $reservationId === (int) ($this->reservation['id'] ?? 0) ? $this->reservation : null;
        }
        public function claimPendingClockReservation(int $reservationId, string $key, string $owner): array
        {
            unset($reservationId, $key, $owner);
            return ['outcome' => 'claimed'];
        }
        public function ownsPendingClockReservation(int $reservationId, string $key, string $owner): bool
        {
            unset($reservationId, $key, $owner);
            return true;
        }
        public function updateClaimedClockReservation(int $reservationId, string $key, string $owner, array $data): bool
        {
            unset($reservationId, $key, $owner);
            if (!empty($data['provider_booking_id']) || !empty($data['provider_reservation_id'])) {
                $this->providerReferenceWrites++;
            }
            return true;
        }
        public function updateProviderMetadata(int $reservationId, array $data): bool
        {
            unset($reservationId, $data);
            $this->manualReviewWrites++;
            return true;
        }
    }

    final class BoundaryGuestRepository
    {
        public function getGuestById(int $guestId): ?array { unset($guestId); return null; }
    }

    /** @var BoundaryReservationRepository|null */
    $boundaryRepository = null;
    function get_reservation_repository(): BoundaryReservationRepository
    {
        global $boundaryRepository;
        return $boundaryRepository;
    }
    function get_guest_repository(): BoundaryGuestRepository { return new BoundaryGuestRepository(); }
}

namespace MustHotelBooking\Frontend {
    $boundarySelection = [];
    function boundary_reset_selection(?array $state = null): void {
        global $boundarySelection;
        $boundarySelection = $state ?? [
            'context' => ['checkin'=>'2026-08-01','checkout'=>'2026-08-03','guests'=>2,'room_count'=>1,'accommodation_type'=>'room-type:10'],
            'selected_rooms' => [['room_id'=>21,'rate_plan_id'=>30]],
            'flow_data' => ['quote_draft'=>['checkin'=>'2026-08-01','checkout'=>'2026-08-03','guests'=>2,'room_count'=>1,'accommodation_type'=>'room-type:10','coupon_code'=>'']],
        ];
    }
    boundary_reset_selection();
    function get_booking_selection(): array { global $boundarySelection; return $boundarySelection; }
    function get_booking_selected_room_ids(): array {
        global $boundarySelection;
        return array_values(array_map(static fn(array $r): int => (int)($r['room_id'] ?? 0), (array)($boundarySelection['selected_rooms'] ?? [])));
    }
    function get_booking_selected_rooms(): array {
        global $boundarySelection;
        return array_values(array_map(static fn(array $r): array => ['id'=>(int)($r['room_id'] ?? 0),'rate_plan_id'=>(int)($r['rate_plan_id'] ?? 0)], (array)($boundarySelection['selected_rooms'] ?? [])));
    }
    function get_booking_selected_room_rate_plan_map(): array {
        global $boundarySelection; $map=[];
        foreach ((array)($boundarySelection['selected_rooms'] ?? []) as $r) { $map[(int)($r['room_id'] ?? 0)] = (int)($r['rate_plan_id'] ?? 0); }
        return $map;
    }
    function get_booking_selection_flow_data(): array { global $boundarySelection; return (array)($boundarySelection['flow_data'] ?? []); }
    function do_booking_selection_contexts_match(array $a, array $b): bool {
        foreach (['checkin','checkout','guests','room_count','accommodation_type'] as $k) { if ((string)($a[$k] ?? '') !== (string)($b[$k] ?? '')) return false; }
        return true;
    }
    function clear_booking_selection(bool $releaseLocks = true): void { unset($releaseLocks); boundary_reset_selection(['context'=>[],'selected_rooms'=>[],'flow_data'=>[]]); }
    function add_room_to_booking_selection(int $roomId, array $context, int $ratePlanId = 0): bool {
        global $boundarySelection;
        $boundarySelection['context']=$context;
        $boundarySelection['selected_rooms']=[['room_id'=>$roomId,'rate_plan_id'=>max(0,$ratePlanId)]];
        return true;
    }
    function update_booking_selection_flow_data(array $data): void {
        global $boundarySelection;
        $boundarySelection['flow_data']=array_merge((array)($boundarySelection['flow_data'] ?? []),$data);
    }
    function combine_checkout_phone_value(array $guestForm): string { unset($guestForm); return ''; }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function isPublicBookingConfigured(): bool { return true; }
        public static function publicBookingConfigurationErrors(): array { return []; }
        public static function reservationCreatePath(): string { return '/bookings'; }
        public static function reservationCancelPath(): string { return 'POST /bookings/{booking_id}/cancel'; }
    }

    final class ClockApiResponse
    {
        private bool $success;
        /** @var mixed */
        private $data;
        public function __construct(bool $success, $data = null) { $this->success = $success; $this->data = $data; }
        public function isSuccess(): bool { return $this->success; }
        public function getData() { return $this->data; }
        public function getErrorMessage(): string { return $this->success ? '' : 'fake failure'; }
    }

    final class ClockApiClient
    {
        public int $createCalls = 0;
        public int $cancelCalls = 0;
        /** @var array<string, mixed> */
        public array $lastCreateRequest = [];
        /** @var array<string, mixed> */
        public array $createResponse = [];

        public function get(string $path, array $query = [], string $operation = '', array $options = []): ClockApiResponse
        {
            unset($path, $query, $operation, $options);
            return new ClockApiResponse(false);
        }
        public function request(string $method, string $path, array $request, string $operation): ClockApiResponse
        {
            unset($method, $path);
            if ($operation === 'clock.reservation_create') {
                $this->createCalls++;
                $this->lastCreateRequest = $request;
                return new ClockApiResponse(true, $this->createResponse);
            }
            if ($operation === 'clock.reservation_cancel') {
                $this->cancelCalls++;
                return new ClockApiResponse(true, []);
            }
            return new ClockApiResponse(false);
        }
    }

    final class ClockCatalogService
    {
        public function findRatePlanMapping(int $ratePlanId): ?array
        {
            return $ratePlanId === 30
                ? ['id' => 30, 'local_id' => 30, 'external_id' => '900', 'external_code' => 'FLEX']
                : null;
        }
    }

    final class ClockAvailabilityProvider
    {
        public bool $available = false;
        public string $lastFailureReason = 'unavailable';
        /** @var array<int, array<string, mixed>> */
        public array $freshCalls = [];
        public function checkAvailability(int $roomId, string $checkin, string $checkout, string $sessionId = ''): bool
        {
            unset($roomId, $checkin, $checkout, $sessionId);
            return $this->available;
        }
        public function getLastAvailabilityFailureReason(): string
        {
            return $this->lastFailureReason;
        }
        public function checkAvailabilityFresh(
            int $roomId,
            string $checkin,
            string $checkout,
            string $sessionId = '',
            int $guests = 1,
            int $ratePlanId = 0,
            string $operationContext = 'final_revalidation',
            int $excludeReservationId = 0
        ): bool
        {
            $this->freshCalls[] = compact('roomId', 'checkin', 'checkout', 'sessionId', 'guests', 'ratePlanId', 'operationContext', 'excludeReservationId');
            return $this->available;
        }
    }

    final class ClockQuoteProvider
    {
        public int $freshCalls = 0;
        /** @var array<string, mixed> */
        public array $pricing = [];
        public function calculateTotalFresh(int $roomId, string $checkin, string $checkout, int $guests, string $couponCode = '', int $ratePlanId = 0): array
        {
            unset($roomId, $checkin, $checkout, $guests, $couponCode, $ratePlanId);
            $this->freshCalls++;
            return $this->pricing;
        }
    }

    final class ClockMirrorReservationService
    {
        public int $creates = 0;
        public int $batchCreates = 0;
        /** @var array<string, mixed> */
        public array $batchResult = ['success' => true, 'reason_code' => '', 'reservation_ids' => [77]];
        /** @var array<int, array<string, mixed>> */
        public array $batchRooms = [];
        public string $batchSessionId = '';
        public function generateBookingReference(): string { return 'WEB-BOUNDARY'; }
        public function createMirrorReservation(array $context, array $guest, int $guestId, array $room, array $provider, array $options): int
        {
            unset($context, $guest, $guestId, $room, $provider, $options);
            $this->creates++;
            return 77;
        }
        public function createPendingMirrorReservationsFromLocks(array $context, array $guest, int $guestId, array $rooms, array $options, string $sessionId): array
        {
            unset($context, $guest, $guestId, $options);
            $this->batchCreates++;
            $this->batchRooms = $rooms;
            $this->batchSessionId = $sessionId;
            return $this->batchResult;
        }
    }

    final class ClockRoomSelection
    {
        /** @var array<string, mixed> */
        public array $selection = [];
        public function resolve(int $roomId): ?array { return $roomId === 21 ? $this->selection : null; }
    }

    final class ClockBookingReferenceMapper
    {
        public static function applyWebsiteReferenceToPayload(array $payload, string $reference): array
        {
            return [
                'payload' => $payload,
                'primary_field' => 'reference_number',
                'fallback_fields' => [],
                'reference_text' => $reference,
            ];
        }
        public static function extractClockIdentifiers(array $reservation): array
        {
            return ['clock_booking_id' => (string) ($reservation['booking_id'] ?? ''), 'clock_booking_reference' => ''];
        }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Dto/ReservationCreateRequest.php';
    require __DIR__ . '/../src/Provider/Contracts/ReservationProviderInterface.php';
    require __DIR__ . '/../src/Provider/Clock/ClockReservationProvider.php';

    use MustHotelBooking\Engine\BoundaryReservationRepository;
    use MustHotelBooking\Engine\PaymentEngine;
    use MustHotelBooking\Engine\ReservationEngine;
    use MustHotelBooking\Provider\Clock\ClockApiClient;
    use MustHotelBooking\Provider\Clock\ClockAvailabilityProvider;
    use MustHotelBooking\Provider\Clock\ClockCatalogService;
    use MustHotelBooking\Provider\Clock\ClockMirrorReservationService;
    use MustHotelBooking\Provider\Clock\ClockQuoteProvider;
    use MustHotelBooking\Provider\Clock\ClockReservationProvider;
    use MustHotelBooking\Provider\Clock\ClockRoomSelection;
    use MustHotelBooking\Provider\Dto\ReservationCreateRequest;

    function boundary_selection(): array
    {
        return [
            'is_physical' => true,
            'room_id' => 10,
            'room_type_id' => 10,
            'physical_room_id' => 21,
            'room_mapping' => ['id' => 10, 'external_id' => '1001', 'external_code' => 'STANDARD'],
            'physical_mapping' => ['id' => 21, 'external_id' => '501', 'external_code' => 'ROOM-501'],
            'room' => ['id'=>21,'room_type_id'=>10,'physical_room_id'=>21,'name'=>'Boundary room','max_guests'=>2],
        ];
    }

    function boundary_reservation(): array
    {
        return [
            'id' => 77,
            'booking_id' => 'WEB-BOUNDARY',
            'room_id' => 10,
            'room_type_id' => 10,
            'assigned_room_id' => 21,
            'rate_plan_id' => 30,
            'guest_id' => 55,
            'checkin' => '2026-08-01',
            'checkout' => '2026-08-03',
            'guests' => 2,
            'coupon_code' => '',
            'total_price' => 200.0,
            'status' => 'pending_payment',
            'payment_status' => 'pending',
            'provider_booking_id' => '',
            'provider_reservation_id' => '',
            'provider_metadata' => [
                'physical_room_id' => 21,
                'room_mapping' => ['external_id' => '1001'],
                'physical_mapping' => ['external_id' => '501'],
                'rate_plan_mapping' => ['external_id' => '900'],
                'pending_guest_form' => [
                    'first_name' => 'Ada',
                    'last_name' => 'Guest',
                    'email' => 'ada@example.test',
                ],
            ],
        ];
    }

    function boundary_provider(
        ClockApiClient $client,
        ClockAvailabilityProvider $availability,
        ClockQuoteProvider $quote,
        ClockMirrorReservationService $mirror,
        ClockRoomSelection $selection
    ): ClockReservationProvider {
        return new ClockReservationProvider($client, new ClockCatalogService(), $availability, $quote, $mirror, $selection);
    }

    $failures = [];
    $guestForm = ['first_name' => 'Ada', 'last_name' => 'Guest', 'email' => 'ada@example.test'];
    $context = ['is_valid' => true, 'checkin' => '2026-08-01', 'checkout' => '2026-08-03', 'guests' => 2];

    \MustHotelBooking\Frontend\boundary_reset_selection(['context'=>[],'selected_rooms'=>[],'flow_data'=>[]]);
    $client=new ClockApiClient();
    $availability=new ClockAvailabilityProvider();
    $availability->available=true;
    $quote=new ClockQuoteProvider();
    $mirror=new ClockMirrorReservationService();
    $selection=new ClockRoomSelection();
    $selection->selection=boundary_selection();
    $bootstrapErrors=boundary_provider($client,$availability,$quote,$mirror,$selection)->bootstrapCheckoutSelectionFromRequest([
        'room_id'=>10,
        'inventory_room_id'=>21,
        'checkin'=>'2026-08-01',
        'checkout'=>'2026-08-03',
        'guests'=>2,
        'room_count'=>1,
        'accommodation_type'=>'room-type:10',
    ]);
    $bootstrapSelection=\MustHotelBooking\Frontend\get_booking_selection();
    if (!empty($bootstrapErrors) || (int)($bootstrapSelection['selected_rooms'][0]['rate_plan_id'] ?? 0) !== 30) {
        $failures[]='A direct fixed-room checkout without rate_plan_id must persist its single applicable mapped Clock rate.';
    }
    \MustHotelBooking\Frontend\boundary_reset_selection();

    $client = new ClockApiClient();
    $availability = new ClockAvailabilityProvider();
    $quote = new ClockQuoteProvider();
    $mirror = new ClockMirrorReservationService();
    $selection = new ClockRoomSelection();
    $selection->selection = boundary_selection();
    ReservationEngine::$guestCreates = 0;
    $checkoutResult = boundary_provider($client, $availability, $quote, $mirror, $selection)->createReservations(
        new ReservationCreateRequest($context, $guestForm, '', [
            'reservation_status' => 'pending_payment',
            'payment_status' => 'pending',
        ])
    );
    if (empty($checkoutResult['errors'])) {
        $failures[] = 'Unavailable exact-room checkout must return a controlled failure.';
    }
    if (ReservationEngine::$guestCreates !== 0 || $mirror->creates !== 0 || $mirror->batchCreates !== 0 || $quote->freshCalls !== 0 || $client->createCalls !== 0) {
        $failures[] = 'Exact-room checkout must fail before guest, mirror, quote-write, payment, or Clock creation boundaries.';
    }
    if (\count($availability->freshCalls) !== 1 || (int) ($availability->freshCalls[0]['roomId'] ?? 0) !== 21) {
        $failures[] = 'Checkout final validation must use the selected physical room.';
    }
    if ((int) ($availability->freshCalls[0]['guests'] ?? 0) !== 2
        || (int) ($availability->freshCalls[0]['ratePlanId'] ?? 0) !== 30
        || (string) ($availability->freshCalls[0]['operationContext'] ?? '') !== 'final_revalidation') {
        $failures[] = 'Checkout final validation must use its exact guests, selected rate, and operation context.';
    }

    $confirmation = (string) \file_get_contents(__DIR__ . '/../src/Frontend/confirmation-page.php');
    $reservationCall = \strpos($confirmation, 'create_checkout_reservations(');
    $paymentCall = \strpos($confirmation, 'PaymentEngine::processPayment');
    if (\strpos($confirmation, 'if (!empty($reservation_ids) && empty($messages))') !== false) {
        $failures[] = 'Informational quote notices must not suppress payment after successful local reservation creation.';
    }
    if ($reservationCall === false || $paymentCall === false || $reservationCall > $paymentCall) {
        $failures[] = 'PokPay order creation must remain downstream of successful local reservation creation.';
    }

    $client = new ClockApiClient();
    $availability = new ClockAvailabilityProvider();
    $availability->available = true;
    $quote = new ClockQuoteProvider();
    $quote->pricing = [
        'success' => true,
        'total_price' => 200.0,
        'guarantee_policy_id' => 44,
        'provider_rate_id' => '900',
    ];
    $mirror = new ClockMirrorReservationService();
    $mirror->batchResult = ['success' => false, 'reason_code' => 'lock_unavailable', 'reservation_ids' => []];
    $selection = new ClockRoomSelection();
    $selection->selection = boundary_selection();
    PaymentEngine::$pokPayOrderCreates = 0;
    $conversionFailure = boundary_provider($client, $availability, $quote, $mirror, $selection)->createReservations(
        new ReservationCreateRequest($context, $guestForm, '', [
            'reservation_status' => 'pending_payment',
            'payment_status' => 'pending',
        ])
    );
    if (empty($conversionFailure['errors']) && !empty($conversionFailure['reservation_ids'])) {
        PaymentEngine::processPayment();
    }
    if (empty($conversionFailure['errors']) || !empty($conversionFailure['reservation_ids'])) {
        $failures[] = 'Atomic lock-conversion failure must return an error and no reservation IDs.';
    }
    if ($mirror->batchCreates !== 1 || $mirror->creates !== 0 || $client->createCalls !== 0) {
        $failures[] = 'Deferred checkout must use one batch mirror call and no single-mirror or Clock create call.';
    }
    if ($mirror->batchSessionId !== 'boundary-session' || (int) ($mirror->batchRooms[0]['physical_room_id'] ?? 0) !== 21) {
        $failures[] = 'Deferred checkout must pass the captured session and exact physical room to atomic conversion.';
    }
    if (PaymentEngine::$pokPayOrderCreates !== 0) {
        $failures[] = 'Atomic lock-conversion failure must create zero PokPay orders.';
    }

    $client = new ClockApiClient();
    $availability = new ClockAvailabilityProvider();
    $availability->available = true;
    $quote = new ClockQuoteProvider();
    $quote->pricing = [
        'success' => true,
        'total_price' => 200.0,
        'guarantee_policy_id' => 44,
        'provider_rate_id' => '900',
    ];
    $mirror = new ClockMirrorReservationService();
    $selection = new ClockRoomSelection();
    $selection->selection = boundary_selection();
    PaymentEngine::$pokPayOrderCreates = 0;
    $conversionSuccess = boundary_provider($client, $availability, $quote, $mirror, $selection)->createReservations(
        new ReservationCreateRequest($context, $guestForm, '', [
            'reservation_status' => 'pending_payment',
            'payment_status' => 'pending',
        ])
    );
    if (empty($conversionSuccess['errors']) && !empty($conversionSuccess['reservation_ids'])) {
        PaymentEngine::processPayment();
    }
    if (!empty($conversionSuccess['errors']) || ($conversionSuccess['reservation_ids'] ?? []) !== [77]) {
        $failures[] = 'Successful atomic conversion must return the complete reservation set.';
    }
    if ($mirror->batchCreates !== 1 || $client->createCalls !== 0) {
        $failures[] = 'Successful pending conversion must still make no Clock create call.';
    }
    if (PaymentEngine::$pokPayOrderCreates !== 1) {
        $failures[] = 'The modeled PokPay boundary must remain downstream of successful atomic conversion.';
    }

    $boundaryRepository = new BoundaryReservationRepository();
    $boundaryRepository->reservation = boundary_reservation();
    $client = new ClockApiClient();
    $availability = new ClockAvailabilityProvider();
    $quote = new ClockQuoteProvider();
    $mirror = new ClockMirrorReservationService();
    $selection = new ClockRoomSelection();
    $selection->selection = boundary_selection();
    $paidUnavailable = boundary_provider($client, $availability, $quote, $mirror, $selection)
        ->fulfillPendingOnlinePayment([77], 'pokpay', 'order-77');
    if (!empty($paidUnavailable['success']) || (string) ($paidUnavailable['state'] ?? '') !== 'manual_review') {
        $failures[] = 'Paid exact-room unavailability must fail closed into manual review.';
    }
    if ($client->createCalls !== 0 || $boundaryRepository->providerReferenceWrites !== 0 || $boundaryRepository->manualReviewWrites !== 1) {
        $failures[] = 'Paid unavailability must not create or persist a Clock reservation and must record manual review.';
    }
    if (\count($availability->freshCalls) !== 1 || (int) ($availability->freshCalls[0]['roomId'] ?? 0) !== 21) {
        $failures[] = 'Paid fulfilment final validation must use the assigned physical room.';
    }
    if ((int) ($availability->freshCalls[0]['guests'] ?? 0) !== 2
        || (int) ($availability->freshCalls[0]['ratePlanId'] ?? 0) !== 30
        || (string) ($availability->freshCalls[0]['operationContext'] ?? '') !== 'paid_fulfilment'
        || (int) ($availability->freshCalls[0]['excludeReservationId'] ?? 0) !== 77) {
        $failures[] = 'Paid fulfilment validation must use saved guests, rate, paid operation context, and exclude its own reservation.';
    }

    $boundaryRepository = new BoundaryReservationRepository();
    $boundaryRepository->reservation = boundary_reservation();
    $client = new ClockApiClient();
    $availability = new ClockAvailabilityProvider();
    $availability->lastFailureReason = 'provider_unconfirmed';
    $quote = new ClockQuoteProvider();
    $mirror = new ClockMirrorReservationService();
    $selection = new ClockRoomSelection();
    $selection->selection = boundary_selection();
    $paidUnconfirmed = boundary_provider($client, $availability, $quote, $mirror, $selection)
        ->fulfillPendingOnlinePayment([77], 'pokpay', 'order-77');
    if (!empty($paidUnconfirmed['success'])
        || (string) ($paidUnconfirmed['state'] ?? '') !== 'manual_review'
        || (string) ($paidUnconfirmed['reason_code'] ?? '') !== 'clock_exact_room_provider_unconfirmed') {
        $failures[] = 'Paid unconfirmed provider evidence must fail closed with a precise manual-review reason.';
    }
    if ($client->createCalls !== 0 || $boundaryRepository->manualReviewWrites !== 1) {
        $failures[] = 'Paid unconfirmed evidence must stop before Clock POST and persist manual review.';
    }

    $boundaryRepository = new BoundaryReservationRepository();
    $boundaryRepository->reservation = boundary_reservation();
    $client = new ClockApiClient();
    $client->createResponse = ['reservation' => ['reservation_id' => 'CLOCK-77', 'arrival_room_id' => '999']];
    $availability = new ClockAvailabilityProvider();
    $availability->available = true;
    $quote = new ClockQuoteProvider();
    $quote->pricing = [
        'success' => true,
        'total_price' => 200.0,
        'guarantee_policy_id' => 44,
        'provider_rate_id' => '900',
    ];
    $mirror = new ClockMirrorReservationService();
    $selection = new ClockRoomSelection();
    $selection->selection = boundary_selection();
    $substitution = boundary_provider($client, $availability, $quote, $mirror, $selection)
        ->fulfillPendingOnlinePayment([77], 'pokpay', 'order-77');
    if (!empty($substitution['success']) || (string) ($substitution['state'] ?? '') !== 'manual_review') {
        $failures[] = 'A different Clock physical room must fail and route to manual review.';
    }
    if ($client->createCalls !== 1 || $client->cancelCalls !== 1 || $boundaryRepository->providerReferenceWrites !== 0) {
        $failures[] = 'A substituted room must be rolled back and never persisted as the requested room.';
    }
    $bookingPayload = $client->lastCreateRequest['body']['booking'] ?? [];
    if ((string) ($bookingPayload['arrival_room_type_id'] ?? '') !== '1001' || (string) ($bookingPayload['arrival_room_id'] ?? '') !== '501') {
        $failures[] = 'Clock create must retain both the type mapping and exact physical-room mapping.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock exact-room payment-boundary tests passed.\n";
}
