<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function sanitize_key($value): string
    {
        return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
    }

    function current_time(string $type): string
    {
        return $type === 'Y-m-d' ? '2026-07-16' : '2026-07-16 12:00:00';
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Core {
    final class RoomCatalog
    {
        public static function normalizeBookingCategory(string $category): string { return $category; }
        public static function isRoomTypeBookingValue(string $category): bool { return \strpos($category, 'room-type:') === 0; }
        public static function resolveBookingRoomTypeId(string $category): int { return (int) \substr($category, \strlen('room-type:')); }
        public static function isBookingAllCategory(string $category): bool { return $category === 'all'; }
        public static function isClockBackendMode(): bool { return true; }
        public static function getClockBookingRoomTypes(): array { return ['room-type:10' => [], 'room-type:11' => []]; }
    }

    final class RoomData
    {
    }
}

namespace MustHotelBooking\Engine {
    final class AvailabilityEngine
    {
        public static function isValidBookingDate(string $date): bool
        {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
        }
    }

    final class InventoryEngine
    {
        /** @var array<int, array<string, mixed>> */
        public static array $rooms = [];

        public static function getAvailableRooms(int $roomTypeId, string $checkin, string $checkout, string $excludeSessionId = ''): array
        {
            unset($roomTypeId, $checkin, $checkout, $excludeSessionId);
            return self::$rooms;
        }
    }

    final class LockEngine
    {
        public static function getOrCreateSessionId(): string { return 'exact-room-session'; }
    }

    function get_room_repository()
    {
        throw new \RuntimeException('Room repository fallback must not be used in this test.');
    }
}

namespace MustHotelBooking\Provider\Storage {
    final class ProviderMappingRepository
    {
        /** @var array<int, array<string, mixed>> */
        public static array $rateMappings = [[
            'local_id' => 30,
            'external_id' => '900',
            'external_parent_id' => '1001',
            'status' => 'active',
            'metadata' => ['public_visible' => 'yes', 'bookable_type' => 'Pms::RoomType'],
        ]];

        public function listForProvider(string $provider, string $entityType): array
        {
            unset($provider);
            return $entityType === 'rate_plan'
                ? self::$rateMappings
                : [];
        }
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function isConfigured(): bool { return true; }
        public static function ratesAvailabilityPath(): string { return '/rates_availability'; }
        public static function roomStatusesPath(): string { return '/room_statuses'; }
    }

    final class ClockCatalogService
    {
        public function __construct(?ClockApiClient $client = null) { unset($client); }
        public function findAccommodationMapping(int $roomTypeId): ?array
        {
            if ($roomTypeId === 10) {
                return ['external_id' => '1001'];
            }

            return $roomTypeId === 11 ? ['external_id' => '1002'] : null;
        }
        public function findRatePlanMapping(int $ratePlanId): ?array
        {
            return $ratePlanId === 30
                ? ['local_id' => 30, 'external_id' => '900', 'external_parent_id' => '1001', 'status' => 'active', 'metadata' => ['public_visible' => 'yes', 'bookable_type' => 'Pms::RoomType']]
                : null;
        }
    }

    final class ClockApiClient
    {
        /** @var array<int, ClockApiResponse> */
        public array $responses = [];
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function get(string $path, array $query = [], string $operation = '', array $options = []): ClockApiResponse
        {
            $this->calls[] = compact('path', 'query', 'operation', 'options');
            return \array_shift($this->responses) ?: new ClockApiResponse(500, '', null, 'missing_response', 'Missing fake response.');
        }
    }

    final class ClockRoomSelection
    {
        /** @var array<int, array<string, mixed>> */
        public static array $selections = [];
        /** @var array<int, array<int, array<string, mixed>>> */
        public static array $selectionsByType = [];

        public function __construct(?ClockCatalogService $catalog = null, ?\MustHotelBooking\Provider\Storage\ProviderMappingRepository $mappings = null)
        {
            unset($catalog, $mappings);
        }

        public function resolve(int $roomId): ?array
        {
            return self::$selections[$roomId] ?? null;
        }

        public function physicalRoomSelectionsForType(int $roomTypeId): array
        {
            return self::$selectionsByType[$roomTypeId] ?? [];
        }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Contracts/AvailabilityProviderInterface.php';
    require __DIR__ . '/../src/Provider/Dto/AvailabilitySearchRequest.php';
    require __DIR__ . '/../src/Provider/Dto/DisabledDatesRequest.php';
    require __DIR__ . '/../src/Provider/Clock/ClockApiResponse.php';
    require __DIR__ . '/../src/Provider/Clock/ClockRoomStatusService.php';
    require __DIR__ . '/../src/Provider/Clock/ClockAvailabilityProvider.php';

    use MustHotelBooking\Engine\InventoryEngine;
    use MustHotelBooking\Provider\Clock\ClockApiClient;
    use MustHotelBooking\Provider\Clock\ClockApiResponse;
    use MustHotelBooking\Provider\Clock\ClockAvailabilityProvider;
    use MustHotelBooking\Provider\Clock\ClockCatalogService;
    use MustHotelBooking\Provider\Clock\ClockRoomSelection;
    use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
    use MustHotelBooking\Provider\Dto\DisabledDatesRequest;
    use MustHotelBooking\Provider\Storage\ProviderMappingRepository;

    function exact_room_selection(int $localId, string $externalId, int $typeId = 10, string $typeExternalId = '1001'): array
    {
        return [
            'selection_id' => $localId,
            'room_id' => $typeId,
            'room_type_id' => $typeId,
            'physical_room_id' => $localId,
            'is_physical' => true,
            'room' => [
                'id' => $localId,
                'room_type_id' => $typeId,
                'physical_room_id' => $localId,
                'name' => 'Physical ' . $localId,
                'max_guests' => 2,
            ],
            'room_mapping' => ['id' => $typeId + 1, 'external_id' => $typeExternalId, 'external_code' => 'TYPE-' . $typeExternalId],
            'physical_mapping' => ['id' => $localId + 100, 'external_id' => $externalId, 'external_code' => 'ROOM-' . $externalId],
        ];
    }

    function availability_item(bool $firstNight, bool $secondNight = true, string $rateId = '900', string $typeId = '1001'): array
    {
        return [
            'type' => 'Pms::RoomType',
            'id' => $typeId,
            'rates' => [
                $rateId => [
                    '2026-08-01' => ['free' => $firstNight],
                    '2026-08-02' => ['free' => $secondNight],
                ],
            ],
        ];
    }

    function room_statuses(bool $roomAAvailable, bool $roomBAvailable = false, string $typeId = '1001'): array
    {
        return [[
            'room_type_id' => $typeId,
            'room_type' => 'Standard',
            'rooms' => [
                ['id' => 501, 'number' => '101', 'available' => $roomAAvailable],
                ['id' => 502, 'number' => '102', 'available' => $roomBAvailable],
            ],
        ]];
    }

    function decoded_path(array $call): string
    {
        return \rawurldecode((string) ($call['path'] ?? ''));
    }

    $roomA = exact_room_selection(21, '501');
    $roomB = exact_room_selection(22, '502');
    ClockRoomSelection::$selections = [21 => $roomA, 22 => $roomB];
    ClockRoomSelection::$selectionsByType = [10 => [$roomA, $roomB]];
    InventoryEngine::$rooms = [['id' => 21], ['id' => 22]];

    $client = new ClockApiClient();
    $client->responses[] = new ClockApiResponse(200, '', [availability_item(true)]);
    $client->responses[] = new ClockApiResponse(200, '', room_statuses(true, false));
    $provider = new ClockAvailabilityProvider($client, new ClockCatalogService($client), new ProviderMappingRepository(), new ClockRoomSelection());
    $rooms = $provider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10'));
    $failures = [];
    $searchPath = decoded_path($client->calls[0] ?? []);

    if (\count($rooms) !== 1 || (int) ($rooms[0]['id'] ?? 0) !== 21) {
        $failures[] = 'Search must correlate Clock availability to each exact physical room.';
    }
    foreach (['rates[]=900', 'room_types[]=1001', 'adults=2'] as $expectedPair) {
        if (\strpos($searchPath, $expectedPair) === false) {
            $failures[] = 'Type-level search query is missing ' . $expectedPair . '.';
        }
    }
    if (\strpos($searchPath, 'rooms[]=') !== false) {
        $failures[] = 'rates_availability must never receive physical rooms[] for exact-room search.';
    }
    $statusCall = $client->calls[1] ?? [];
    if (($statusCall['path'] ?? '') !== '/room_statuses'
        || ($statusCall['query'] ?? []) !== ['from' => '2026-08-01', 'to' => '2026-08-02', 'room_type_id' => '1001']) {
        $failures[] = 'Single-type search must validate the inclusive occupied range through room_statuses.';
    }

    $client->responses[] = new ClockApiResponse(200, '', [availability_item(true)]);
    $client->responses[] = new ClockApiResponse(200, '', room_statuses(true));
    if (!$provider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', 'exact-room-session', 2, 30, 'final_revalidation')) {
        $failures[] = 'Fresh validation must accept an available exact physical room.';
    }
    $freshCall = $client->calls[2] ?? [];
    $freshPath = decoded_path($freshCall);
    if (\strpos($freshPath, 'room_types[]=1001') === false || \strpos($freshPath, 'rooms[]=') !== false || \strpos($freshPath, 'rates[]=900') === false) {
        $failures[] = 'Fresh exact-room validation must use its parent type and exact selected rate.';
    }
    if (empty($freshCall['options']['bypass_cache'])) {
        $failures[] = 'Fresh exact-room rate validation must bypass the Clock availability cache.';
    }
    $freshStatusCall = $client->calls[3] ?? [];
    if (($freshStatusCall['query'] ?? []) !== ['from' => '2026-08-01', 'to' => '2026-08-02', 'room_type_id' => '1001']
        || empty($freshStatusCall['options']['bypass_cache'])) {
        $failures[] = 'Fresh exact-room status validation must target the parent type and bypass cache.';
    }

    $client->responses[] = new ClockApiResponse(0, '', null, 'http_request_failed', 'Connection failed.');
    if ($provider->getAvailableRoomById(21, '2026-08-01', '2026-08-03', 1) !== null) {
        $failures[] = 'An exact-room provider transport failure must not return a room as available.';
    }
    if ($provider->getLastAvailabilityFailureReason() !== 'provider_unconfirmed') {
        $failures[] = 'An exact-room provider transport failure must be distinguished from confirmed unavailability.';
    }
    $transportFailurePath = decoded_path($client->calls[4] ?? []);
    if (\strpos($transportFailurePath, 'room_types[]=1001') === false || \strpos($transportFailurePath, 'rooms[]=') !== false) {
        $failures[] = 'Transport-failure validation must preserve parent-type selection.';
    }

    $client->responses[] = new ClockApiResponse(200, '', [
        [
            'type' => 'Pms::RoomType',
            'id' => '1001',
            'rates' => ['900' => ['2026-07-16' => ['free' => true]]],
        ],
    ]);
    $provider->getDisabledDates(new DisabledDatesRequest('', 2, 1, 21, 'room-type:10', 1));
    $disabledPath = decoded_path($client->calls[5] ?? []);
    if (\strpos($disabledPath, 'room_types[]=1001') === false || \strpos($disabledPath, 'rooms[]=') !== false) {
        $failures[] = 'A physical-room disabled-date request must remain advisory at its parent type.';
    }

    $calendarClient = new ClockApiClient();
    $calendarClient->responses[] = new ClockApiResponse(200, '', [[
        'type' => 'Pms::RoomType',
        'id' => '1001',
        'rates' => ['900' => ['2026-07-16' => ['free' => true, 'stop_sale' => true]]],
    ]]);
    $calendarProvider = new ClockAvailabilityProvider($calendarClient, new ClockCatalogService($calendarClient), new ProviderMappingRepository(), new ClockRoomSelection());
    $restrictedCalendar = $calendarProvider->getDisabledDates(new DisabledDatesRequest('', 2, 1, 21, 'room-type:10', 1));
    if (!\in_array('2026-07-16', (array) ($restrictedCalendar['disabled_checkin_dates'] ?? []), true)) {
        $failures[] = 'The advisory calendar must not let free=true override a same-date stop-sale restriction.';
    }

    InventoryEngine::$rooms = [['id' => 22]];
    $calendarClient = new ClockApiClient();
    $calendarClient->responses[] = new ClockApiResponse(200, '', [[
        'type' => 'Pms::RoomType',
        'id' => '1001',
        'rates' => ['900' => ['2026-07-16' => ['free' => true]]],
    ]]);
    $calendarProvider = new ClockAvailabilityProvider($calendarClient, new ClockCatalogService($calendarClient), new ProviderMappingRepository(), new ClockRoomSelection());
    $localConflictCalendar = $calendarProvider->getDisabledDates(new DisabledDatesRequest('', 2, 1, 21, 'room-type:10', 1));
    if (!\in_array('2026-07-16', (array) ($localConflictCalendar['disabled_checkin_dates'] ?? []), true)) {
        $failures[] = 'The advisory calendar must merge local exact-room conflicts into type/rate dates.';
    }
    InventoryEngine::$rooms = [['id' => 21], ['id' => 22]];

    $caseClient = new ClockApiClient();
    $caseClient->responses[] = new ClockApiResponse(200, '', [availability_item(true, false)]);
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 30)) {
        $failures[] = 'Every occupied date must have free=true for the exact selected rate.';
    }
    if ($caseProvider->getLastAvailabilityFailureReason() !== 'unavailable' || \count($caseClient->calls) !== 1) {
        $failures[] = 'An explicit rate failure must be unavailable and stop before room_statuses.';
    }

    $restrictedRate = availability_item(true);
    $restrictedRate['rates']['900']['2026-08-01']['stop_sale'] = true;
    $caseClient = new ClockApiClient();
    $caseClient->responses[] = new ClockApiResponse(200, '', [$restrictedRate]);
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 30)
        || $caseProvider->getLastAvailabilityFailureReason() !== 'unavailable') {
        $failures[] = 'Stop-sale and contradictory rate restrictions must be confirmed unavailable.';
    }

    $closedDepartureRate = availability_item(true);
    $closedDepartureRate['rates']['900']['2026-08-03'] = ['free' => true, 'closed_for_departure' => true];
    $caseClient = new ClockApiClient();
    $caseClient->responses[] = new ClockApiResponse(200, '', [$closedDepartureRate]);
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 30)
        || $caseProvider->getLastAvailabilityFailureReason() !== 'unavailable'
        || \count($caseClient->calls) !== 1) {
        $failures[] = 'A closed departure on the checkout date must fail before room_statuses.';
    }

    $erroredRate = availability_item(true);
    $erroredRate['rates']['900']['errors'] = ['restricted'];
    $caseClient = new ClockApiClient();
    $caseClient->responses[] = new ClockApiResponse(200, '', [$erroredRate]);
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 30)
        || $caseProvider->getLastAvailabilityFailureReason() !== 'unavailable'
        || \count($caseClient->calls) !== 1) {
        $failures[] = 'A non-empty applicable rate error must fail before room_statuses.';
    }

    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 31)
        || $caseProvider->getLastAvailabilityFailureReason() !== 'provider_unconfirmed'
        || !empty($caseClient->calls)) {
        $failures[] = 'An unmapped selected rate must fail unconfirmed before any Clock request.';
    }

    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 0)
        || $caseProvider->getLastAvailabilityFailureReason() !== 'provider_unconfirmed'
        || !empty($caseClient->calls)) {
        $failures[] = 'A final write-boundary validation without an exact selected rate must fail before provider requests.';
    }

    $caseClient = new ClockApiClient();
    $caseClient->responses[] = new ClockApiResponse(200, '', [[
        'type' => 'Pms::RoomType',
        'id' => '1001',
        'rates' => ['900' => ['2026-08-01' => ['free' => true]]],
    ]]);
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 30)) {
        $failures[] = 'A selected rate with a missing occupied date must fail closed.';
    }
    if ($caseProvider->getLastAvailabilityFailureReason() !== 'provider_unconfirmed') {
        $failures[] = 'Missing selected-rate date evidence must be provider-unconfirmed.';
    }

    $caseClient = new ClockApiClient();
    $caseClient->responses[] = new ClockApiResponse(200, '', [availability_item(true)]);
    $caseClient->responses[] = new ClockApiResponse(200, '', [[
        'room_type_id' => '1001',
        'rooms' => [['id' => 999, 'available' => true]],
    ]]);
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 30)) {
        $failures[] = 'A missing exact physical-room row must never inherit type availability.';
    }
    if ($caseProvider->getLastAvailabilityFailureReason() !== 'provider_unconfirmed') {
        $failures[] = 'A missing exact physical-room row must be provider-unconfirmed.';
    }

    $caseClient = new ClockApiClient();
    $caseClient->responses[] = new ClockApiResponse(200, '', [availability_item(true)]);
    $caseClient->responses[] = new ClockApiResponse(200, '', room_statuses(false));
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', '', 2, 30)) {
        $failures[] = 'An explicit exact physical-room available=false must fail closed.';
    }
    if ($caseProvider->getLastAvailabilityFailureReason() !== 'unavailable') {
        $failures[] = 'An explicit physical status false must be confirmed unavailable.';
    }

    ProviderMappingRepository::$rateMappings[0]['external_parent_id'] = '9999';
    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10')) !== []
        || $caseProvider->getLastAvailabilityFailureReason() !== 'provider_unconfirmed'
        || !empty($caseClient->calls)) {
        $failures[] = 'A rate whose mapped parent differs from the selected type must fail before provider requests.';
    }
    ProviderMappingRepository::$rateMappings[0]['external_parent_id'] = '1001';

    ProviderMappingRepository::$rateMappings[0]['metadata']['public_visible'] = 'unknown';
    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10')) !== []
        || $caseProvider->getLastAvailabilityFailureReason() !== 'provider_unconfirmed'
        || !empty($caseClient->calls)) {
        $failures[] = 'A rate without confirmed public WBE visibility must not satisfy availability.';
    }
    ProviderMappingRepository::$rateMappings[0]['metadata']['public_visible'] = 'yes';

    ProviderMappingRepository::$rateMappings[0]['status'] = '';
    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10')) !== []
        || !empty($caseClient->calls)) {
        $failures[] = 'A rate without an explicit active status must not satisfy availability.';
    }
    ProviderMappingRepository::$rateMappings[0]['status'] = 'active';

    ProviderMappingRepository::$rateMappings[0]['metadata'] = [
        'public_visible' => 'yes',
        'metadata' => ['bookable_type' => 'Pms::RoomType', 'active' => false],
    ];
    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10')) !== []
        || !empty($caseClient->calls)) {
        $failures[] = 'A nested provider active=false signal must reject the WBE rate.';
    }

    ProviderMappingRepository::$rateMappings[0]['metadata'] = [
        'public_visible' => 'yes',
        'clock_catalog_item' => ['metadata' => ['bookable_type' => 'Pms::Room']],
    ];
    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10')) !== []
        || !empty($caseClient->calls)) {
        $failures[] = 'Nested catalog metadata for a non-room-type rate must be rejected.';
    }
    ProviderMappingRepository::$rateMappings[0]['metadata'] = [
        'public_visible' => 'yes',
        'bookable_type' => 'Pms::RoomType',
    ];

    $caseClient = new ClockApiClient();
    $caseProvider = new ClockAvailabilityProvider($caseClient, new ClockCatalogService($caseClient), new ProviderMappingRepository(), new ClockRoomSelection());
    if ($caseProvider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 3, 'room-type:10')) !== []
        || $caseProvider->getLastAvailabilityFailureReason() !== 'unavailable'
        || !empty($caseClient->calls)) {
        $failures[] = 'A local capacity failure must be confirmed unavailable without provider requests.';
    }

    $roomC = exact_room_selection(23, '601', 11, '1002');
    ClockRoomSelection::$selections[23] = $roomC;
    ClockRoomSelection::$selectionsByType = [10 => [$roomA, $roomB], 11 => [$roomC]];
    InventoryEngine::$rooms = [['id' => 21], ['id' => 22], ['id' => 23]];
    ProviderMappingRepository::$rateMappings[] = [
        'local_id' => 31,
        'external_id' => '901',
        'external_parent_id' => '1002',
        'status' => 'active',
        'metadata' => ['public_visible' => 'yes', 'bookable_type' => 'Pms::RoomType'],
    ];
    $mixedClient = new ClockApiClient();
    $mixedClient->responses[] = new ClockApiResponse(200, '', [
        availability_item(true),
        availability_item(true, true, '901', '1002'),
    ]);
    $mixedClient->responses[] = new ClockApiResponse(200, '', [
        room_statuses(true, false)[0],
        ['room_type_id' => '1002', 'rooms' => [['id' => 601, 'available' => true]]],
    ]);
    $mixedProvider = new ClockAvailabilityProvider($mixedClient, new ClockCatalogService($mixedClient), new ProviderMappingRepository(), new ClockRoomSelection());
    $mixedRooms = $mixedProvider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'all'));
    if (\count($mixedRooms) !== 2) {
        $failures[] = 'Mixed-type search must match confirmed physical rooms from each candidate type.';
    }
    if (\array_key_exists('room_type_id', $mixedClient->calls[1]['query'] ?? [])) {
        $failures[] = 'Mixed-type search must make one unfiltered room_statuses range request.';
    }

    ClockRoomSelection::$selections = [21 => $roomA, 22 => $roomB];
    ClockRoomSelection::$selectionsByType = [10 => [$roomA, $roomB]];
    InventoryEngine::$rooms = [['id' => 21], ['id' => 22]];
    ProviderMappingRepository::$rateMappings = [ProviderMappingRepository::$rateMappings[0]];

    $missingPhysical = $roomA;
    $missingPhysical['physical_mapping'] = null;
    ClockRoomSelection::$selections[21] = $missingPhysical;
    ClockRoomSelection::$selectionsByType[10] = [$missingPhysical];
    $callsBeforeMissingMapping = \count($client->calls);
    if ($provider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10')) !== []) {
        $failures[] = 'A physical selection without a Clock physical mapping must fail closed.';
    }
    if (\count($client->calls) !== $callsBeforeMissingMapping) {
        $failures[] = 'Missing physical mappings must stop before a Clock request.';
    }

    $typeFallback = [
        'selection_id' => 10,
        'room_id' => 10,
        'room_type_id' => 10,
        'physical_room_id' => 0,
        'is_physical' => false,
        'room' => ['id' => 10, 'name' => 'Standard Rooms', 'max_guests' => 2],
        'room_mapping' => ['id' => 11, 'external_id' => '1001'],
        'physical_mapping' => null,
    ];
    ClockRoomSelection::$selections[10] = $typeFallback;
    ClockRoomSelection::$selectionsByType[10] = [];
    $callsBeforeFallback = \count($client->calls);
    if ($provider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10')) !== []) {
        $failures[] = 'Exact-room mode must not expose a room-type fallback as bookable.';
    }
    if (\count($client->calls) !== $callsBeforeFallback) {
        $failures[] = 'Type-only fallback must be rejected before a Clock request.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock exact physical-room availability tests passed.\n";
}
