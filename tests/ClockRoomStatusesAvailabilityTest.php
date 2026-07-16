<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function roomStatusesPath(): string
        {
            return '/room_statuses';
        }
    }

    final class ClockApiClient
    {
        /** @var array<int, ClockApiResponse> */
        public $responses = [];

        /** @var array<int, array<string, mixed>> */
        public $calls = [];

        public function get(string $path, array $query = [], string $operation = 'clock.get', array $options = []): ClockApiResponse
        {
            $this->calls[] = [
                'path' => $path,
                'query' => $query,
                'operation' => $operation,
                'options' => $options,
            ];

            return \array_shift($this->responses);
        }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Clock/ClockApiResponse.php';

    $serviceFile = __DIR__ . '/../src/Provider/Clock/ClockRoomStatusService.php';
    if (!\is_file($serviceFile)) {
        echo "FAIL\nClockRoomStatusService is missing.\n";
        exit(1);
    }
    require $serviceFile;

    use MustHotelBooking\Provider\Clock\ClockApiClient;
    use MustHotelBooking\Provider\Clock\ClockApiResponse;
    use MustHotelBooking\Provider\Clock\ClockRoomStatusService;

    $failures = [];
    $client = new ClockApiClient();
    $client->responses[] = new ClockApiResponse(200, '', [
        [
            'room_type_id' => 1001,
            'room_type' => 'Deluxe',
            'rooms' => [
                [
                    'id' => 2001,
                    'number' => '101',
                    'available' => true,
                    'housekeeping_status' => 'clean',
                    'housekeeping_notes' => '',
                ],
                [
                    'id' => 2002,
                    'number' => '102',
                    'available' => false,
                    'housekeeping_status' => 'dirty',
                    'housekeeping_notes' => 'Private provider detail',
                ],
            ],
        ],
    ]);

    $service = new ClockRoomStatusService($client);
    $result = $service->fetch('2026-08-10', '2026-08-12', '1001');

    if (($result['status'] ?? '') !== 'confirmed' || ($result['reason'] ?? '') !== '') {
        $failures[] = 'A documented room_statuses response should be confirmed.';
    }
    if (($result['rooms']['2001'] ?? null) !== ['room_type_id' => '1001', 'available' => true]) {
        $failures[] = 'Available physical room rows should be indexed by their exact external ID.';
    }
    if (($result['rooms']['2002'] ?? null) !== ['room_type_id' => '1001', 'available' => false]) {
        $failures[] = 'Explicitly unavailable physical room rows should remain confirmed evidence.';
    }
    if (($client->calls[0]['query'] ?? []) !== ['from' => '2026-08-10', 'to' => '2026-08-12', 'room_type_id' => '1001']) {
        $failures[] = 'The request must use inclusive dates and the optional singular room_type_id only.';
    }
    if (($client->calls[0]['options']['endpoint_name'] ?? '') !== 'room_statuses'
        || ($client->calls[0]['options']['cache_ttl'] ?? 0) !== 15
        || ($client->calls[0]['options']['api_type'] ?? '') !== 'pms_api'
        || !empty($client->calls[0]['options']['bypass_cache'])) {
        $failures[] = 'Intermediate room status reads should use the 15-second endpoint cache.';
    }

    $client->responses[] = new ClockApiResponse(200, '', [[
        'room_type_id' => '1001',
        'rooms' => [['id' => '2001', 'available' => true]],
    ]]);
    $service->fetch('2026-08-10', '2026-08-10', '', true, 'clock.room_statuses.final_revalidation');
    $unfilteredCall = $client->calls[1] ?? [];
    if (($unfilteredCall['query'] ?? []) !== ['from' => '2026-08-10', 'to' => '2026-08-10']) {
        $failures[] = 'Mixed-type search must omit room_type_id and never send a physical-room filter.';
    }
    if (empty($unfilteredCall['options']['bypass_cache'])) {
        $failures[] = 'Fresh final room status reads must bypass the request cache.';
    }

    $client->responses[] = new ClockApiResponse(200, '', [[
        'room_type_id' => '1002',
        'rooms' => [['id' => '3001', 'available' => true]],
    ]]);
    $missingType = $service->fetch('2026-08-10', '2026-08-12', '1001');
    if (($missingType['status'] ?? '') !== 'unconfirmed' || ($missingType['reason'] ?? '') !== 'room_type_missing') {
        $failures[] = 'A filtered response missing the requested room-type group must be unconfirmed.';
    }

    $invalidPayloads = [
        'unexpected wrapper' => ['data' => []],
        'non-boolean availability' => [[
            'room_type_id' => 1001,
            'rooms' => [['id' => 2001, 'available' => 1]],
        ]],
        'conflicting duplicate' => [[
            'room_type_id' => 1001,
            'rooms' => [
                ['id' => 2001, 'available' => true],
                ['id' => 2001, 'available' => false],
            ],
        ]],
        'duplicate across types' => [
            ['room_type_id' => 1001, 'rooms' => [['id' => 2001, 'available' => true]]],
            ['room_type_id' => 1002, 'rooms' => [['id' => 2001, 'available' => true]]],
        ],
    ];

    foreach ($invalidPayloads as $label => $payload) {
        $client->responses[] = new ClockApiResponse(200, '', $payload);
        $invalid = $service->fetch('2026-08-10', '2026-08-12');
        if (($invalid['status'] ?? '') !== 'unconfirmed' || ($invalid['reason'] ?? '') !== 'malformed_response') {
            $failures[] = 'The parser should reject ' . $label . ' as unconfirmed malformed evidence.';
        }
    }

    $client->responses[] = new ClockApiResponse(503, '', null, 'http_503', 'Provider temporarily unavailable');
    $failed = $service->fetch('2026-08-10', '2026-08-12');
    if (($failed['status'] ?? '') !== 'unconfirmed' || ($failed['reason'] ?? '') !== 'request_failed') {
        $failures[] = 'Transport and non-success responses should remain provider-unconfirmed.';
    }
    if (($failed['message'] ?? '') !== 'Provider temporarily unavailable') {
        $failures[] = 'The service should preserve the already-sanitized client error message.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock room statuses availability tests passed.\n";
}
