<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$provider = (string) \file_get_contents(__DIR__ . '/../src/Provider/Clock/ClockReservationProvider.php');
$methodStart = \strpos($provider, 'private function createClockReservationForPendingPayment');
$methodEnd = \strpos($provider, '    /** @param array<string, mixed> $metadata', $methodStart === false ? 0 : $methodStart + 1);
$method = $methodStart !== false && $methodEnd !== false ? \substr($provider, $methodStart, $methodEnd - $methodStart) : '';
$failures = [];

if ($method === '') {
    $failures[] = 'The paid Clock fulfilment method could not be located.';
} else {
    foreach ([
        '$assignedRoomId = isset($reservation[\'assigned_room_id\']) ? (int) $reservation[\'assigned_room_id\'] : 0;',
        '$selection = $this->roomSelection->resolve($assignedRoomId);',
        '$this->availability->checkAvailabilityFresh(',
        "'paid_fulfilment'",
        "(int) (\$selection['physical_room_id'] ?? 0) !== \$assignedRoomId",
        "(int) (\$selection['room_type_id'] ?? 0) !== \$roomId",
        "(int) (\$metadata['physical_room_id'] ?? 0) !== \$assignedRoomId",
        "\$snapshotRoomMapping = isset(\$metadata['room_mapping'])",
        "(string) (\$snapshotPhysicalMapping['external_id'] ?? '') !== (string) (\$physicalMapping['external_id'] ?? '')",
        "\$snapshotRatePlanMapping = isset(\$metadata['rate_plan_mapping'])",
        "(string) (\$snapshotRoomMapping['external_id'] ?? '') !== (string) (\$roomMapping['external_id'] ?? '')",
        "(string) (\$snapshotRatePlanMapping['external_id'] ?? '') !== (string) (\$ratePlanMapping['external_id'] ?? '')",
    ] as $marker) {
        if (\strpos($method, $marker) === false) {
            $failures[] = 'Paid Clock fulfilment must restore and validate the immutable assigned physical room: ' . $marker;
        }
    }

    $physicalGuard = \strpos($method, "if (\$assignedRoomId <= 0");
    $createCall = \strpos($method, '$this->createClockBooking(');
    if ($physicalGuard === false || $createCall === false || $physicalGuard > $createCall) {
        $failures[] = 'A missing durable physical-room identity must stop fulfilment before any Clock create call.';
    }

    $mappingGuard = \strpos($method, 'Clock could not restore the exact selected room after payment.');
    if ($mappingGuard === false || $createCall === false || $mappingGuard > $createCall) {
        $failures[] = 'A mismatched room type, room mapping, or physical mapping must stop fulfilment before any Clock create call.';
    }

    if (\strpos($method, "['physical_room_id'] ?? 0) : 0") !== false) {
        $failures[] = 'The Clock payload must use the validated durable physical-room identity, not a fallback value.';
    }
}

$mirror = (string) \file_get_contents(__DIR__ . '/../src/Provider/Clock/ClockMirrorReservationService.php');
foreach ([
    "'assigned_room_id' => \$assignedRoomId",
    "'physical_room_id' => \$assignedRoomId",
] as $marker) {
    if (\strpos($mirror, $marker) === false) {
        $failures[] = 'The deferred Clock mirror must preserve the selected physical room in durable reservation evidence.';
    }
}

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Clock paid-room restoration regression test passed.\n";
