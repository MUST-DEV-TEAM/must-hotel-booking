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
        public static function getClockBookingRoomTypes(): array { return ['room-type:10' => []]; }
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
        public function listForProvider(string $provider, string $entityType): array
        {
            unset($provider);
            return $entityType === 'rate_plan'
                ? [['external_id' => '900']]
                : [];
        }
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function isConfigured(): bool { return true; }
        public static function ratesAvailabilityPath(): string { return '/rates_availability'; }
    }

    final class ClockCatalogService
    {
        public function __construct(?ClockApiClient $client = null) { unset($client); }
        public function findAccommodationMapping(int $roomTypeId): ?array
        {
            return $roomTypeId === 10 ? ['external_id' => '1001'] : null;
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

    function exact_room_selection(int $localId, string $externalId): array
    {
        return [
            'selection_id' => $localId,
            'room_id' => 10,
            'room_type_id' => 10,
            'physical_room_id' => $localId,
            'is_physical' => true,
            'room' => [
                'id' => $localId,
                'room_type_id' => 10,
                'physical_room_id' => $localId,
                'name' => 'Physical ' . $localId,
                'max_guests' => 2,
            ],
            'room_mapping' => ['id' => 11, 'external_id' => '1001', 'external_code' => 'STANDARD'],
            'physical_mapping' => ['id' => $localId + 100, 'external_id' => $externalId, 'external_code' => 'ROOM-' . $externalId],
        ];
    }

    function availability_item(string $externalId, bool $firstNight, bool $secondNight = true): array
    {
        return [
            'type' => 'Room',
            'id' => $externalId,
            'rates' => [
                '900' => [
                    '2026-08-01' => ['free' => $firstNight],
                    '2026-08-02' => ['free' => $secondNight],
                ],
            ],
        ];
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
    $client->responses[] = new ClockApiResponse(200, '', [
        availability_item('501', true),
        availability_item('502', false),
    ]);
    $provider = new ClockAvailabilityProvider($client, new ClockCatalogService($client), new ProviderMappingRepository(), new ClockRoomSelection());
    $rooms = $provider->getAvailableRooms(new AvailabilitySearchRequest('2026-08-01', '2026-08-03', 2, 'room-type:10'));
    $failures = [];
    $searchPath = decoded_path($client->calls[0] ?? []);

    if (\count($rooms) !== 1 || (int) ($rooms[0]['id'] ?? 0) !== 21) {
        $failures[] = 'Search must correlate Clock availability to each exact physical room.';
    }
    foreach (['rates[]=900', 'rooms[]=501', 'rooms[]=502'] as $expectedPair) {
        if (\strpos($searchPath, $expectedPair) === false) {
            $failures[] = 'Physical search query is missing ' . $expectedPair . '.';
        }
    }
    if (\strpos($searchPath, 'room_types[]=') !== false) {
        $failures[] = 'Physical search must not validate availability with room_types[].';
    }

    $client->responses[] = new ClockApiResponse(200, '', [availability_item('501', true)]);
    if (!$provider->checkAvailabilityFresh(21, '2026-08-01', '2026-08-03', 'exact-room-session')) {
        $failures[] = 'Fresh validation must accept an available exact physical room.';
    }
    $freshCall = $client->calls[1] ?? [];
    $freshPath = decoded_path($freshCall);
    if (\strpos($freshPath, 'rooms[]=501') === false || \strpos($freshPath, 'room_types[]=') !== false) {
        $failures[] = 'Fresh exact-room validation must query only rooms[]=501.';
    }
    if (empty($freshCall['options']['bypass_cache'])) {
        $failures[] = 'Fresh exact-room validation must bypass the Clock availability cache.';
    }

    $client->responses[] = new ClockApiResponse(200, '', [
        [
            'type' => 'Room',
            'id' => '501',
            'rates' => ['900' => ['2026-07-16' => ['free' => true]]],
        ],
    ]);
    $provider->getDisabledDates(new DisabledDatesRequest('', 2, 1, 21, 'room-type:10', 1));
    $disabledPath = decoded_path($client->calls[2] ?? []);
    if (\strpos($disabledPath, 'rooms[]=501') === false || \strpos($disabledPath, 'room_types[]=') !== false) {
        $failures[] = 'A physical-room disabled-date request must query its exact Clock room.';
    }

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
