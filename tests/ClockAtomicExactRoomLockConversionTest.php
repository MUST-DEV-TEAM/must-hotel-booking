<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    const ARRAY_A = 'ARRAY_A';

    function sanitize_key(string $value): string
    {
        return \strtolower((string) \preg_replace('/[^a-zA-Z0-9_\-]/', '', $value));
    }

    function sanitize_text_field(string $value): string
    {
        return \trim(\strip_tags($value));
    }

    function sanitize_textarea_field(string $value): string
    {
        return \trim(\strip_tags($value));
    }

    function sanitize_email(string $value): string
    {
        return \filter_var($value, \FILTER_SANITIZE_EMAIL) ?: '';
    }

    function wp_json_encode($value): string
    {
        return (string) \json_encode($value);
    }

    function __(string $value, string $domain = ''): string
    {
        unset($domain);
        return $value;
    }

    function current_time(string $type): string
    {
        return $type === 'mysql' ? '2026-07-16 12:00:00' : '';
    }

    function wp_generate_uuid4(): string
    {
        static $sequence = 0;
        $sequence++;
        return \sprintf('0000000%d-1111-4111-8111-111111111111', $sequence);
    }

    function wp_rand(int $min, int $max): int
    {
        unset($max);
        return $min;
    }

    /** @var array<int, array{hook: string, reservation_id: int}> */
    $atomicHooks = [];

    function do_action(string $hook, int $reservationId): void
    {
        global $atomicHooks;
        $atomicHooks[] = ['hook' => $hook, 'reservation_id' => $reservationId];
    }

    final class AtomicLockWpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';
        /** @var array<int, int> */
        public array $rooms = [21 => 21, 22 => 22, 30 => 30];
        /** @var array<int, array<string, mixed>> */
        public array $locks = [];
        /** @var array<int, array<string, mixed>> */
        public array $reservations = [];
        /** @var array<int, int> */
        public array $mutexOrder = [];
        /** @var array<string, string> */
        public array $engines = [
            'wp_mhb_rooms' => 'InnoDB',
            'wp_mhb_inventory_locks' => 'InnoDB',
            'wp_must_reservations' => 'InnoDB',
        ];
        public int $insertAttempts = 0;
        public int $failInsertAt = 0;
        public bool $failOverlapQuery = false;
        public bool $failDelete = false;
        public bool $failCommit = false;
        /** @var array<string, mixed>|null */
        private ?array $snapshot = null;

        public function prepare(string $query, ...$args): string
        {
            $offset = 0;
            return (string) \preg_replace_callback('/%[dfs]/', static function (array $match) use ($args, &$offset): string {
                $value = $args[$offset++] ?? null;
                if ($match[0] === '%d') {
                    return (string) (int) $value;
                }
                if ($match[0] === '%f') {
                    return (string) (float) $value;
                }
                return "'" . \str_replace("'", "''", (string) $value) . "'";
            }, $query);
        }

        public function get_var(string $query)
        {
            $sql = $this->compact($query);
            if (\preg_match("/SHOW TABLES LIKE '([^']+)'/i", $sql, $matches)) {
                return isset($this->engines[$matches[1]]) ? $matches[1] : null;
            }
            if (\preg_match('/SELECT id FROM wp_mhb_rooms WHERE id = (\d+) FOR UPDATE/i', $sql, $matches)) {
                $roomId = (int) $matches[1];
                $this->mutexOrder[] = $roomId;
                return $this->rooms[$roomId] ?? null;
            }
            if (\strpos($sql, 'SELECT COUNT(*) FROM wp_must_reservations') !== false) {
                if ($this->failOverlapQuery) {
                    $this->last_error = 'Injected overlap query failure';
                    return null;
                }
                $this->last_error = '';
                \preg_match('/assigned_room_id = (\d+)/', $sql, $roomMatch);
                \preg_match("/checkin < '([^']+)'/", $sql, $checkoutMatch);
                \preg_match("/checkout > '([^']+)'/", $sql, $checkinMatch);
                \preg_match("/status NOT IN \('([^']+)', '([^']+)', '([^']+)'\)/", $sql, $statusMatch);
                $nonBlocking = \array_slice($statusMatch, 1, 3);
                $count = 0;
                foreach ($this->reservations as $reservation) {
                    if ((int) ($reservation['assigned_room_id'] ?? 0) === (int) ($roomMatch[1] ?? 0)
                        && (string) ($reservation['checkin'] ?? '') < (string) ($checkoutMatch[1] ?? '')
                        && (string) ($reservation['checkout'] ?? '') > (string) ($checkinMatch[1] ?? '')
                        && !\in_array((string) ($reservation['status'] ?? ''), $nonBlocking, true)
                    ) {
                        $count++;
                    }
                }
                return $count;
            }
            return null;
        }

        /** @return array<int, array<string, string>> */
        public function get_results(string $query, $output = null): array
        {
            unset($output);
            if (\strpos($this->compact($query), 'information_schema.TABLES') === false) {
                return [];
            }
            $rows = [];
            foreach ($this->engines as $table => $engine) {
                $rows[] = ['TABLE_NAME' => $table, 'ENGINE' => $engine];
            }
            return $rows;
        }

        public function query(string $query)
        {
            $sql = $this->compact($query);
            if ($sql === 'START TRANSACTION') {
                $this->snapshot = [
                    'locks' => $this->locks,
                    'reservations' => $this->reservations,
                    'insert_id' => $this->insert_id,
                ];
                return 0;
            }
            if ($sql === 'COMMIT') {
                if ($this->failCommit) {
                    $this->last_error = 'Injected commit failure';
                    return false;
                }
                $this->snapshot = null;
                return 0;
            }
            if ($sql === 'ROLLBACK') {
                if ($this->snapshot !== null) {
                    $this->locks = $this->snapshot['locks'];
                    $this->reservations = $this->snapshot['reservations'];
                    $this->insert_id = (int) $this->snapshot['insert_id'];
                    $this->snapshot = null;
                }
                return 0;
            }
            if (\strpos($sql, 'DELETE FROM wp_mhb_inventory_locks') === 0) {
                if ($this->failDelete) {
                    $this->last_error = 'Injected delete failure';
                    return false;
                }
                \preg_match('/room_id = (\d+)/', $sql, $roomMatch);
                \preg_match("/checkin = '([^']+)'/", $sql, $checkinMatch);
                \preg_match("/checkout = '([^']+)'/", $sql, $checkoutMatch);
                \preg_match("/session_id = '([^']+)'/", $sql, $sessionMatch);
                $deleted = 0;
                foreach ($this->locks as $index => $lock) {
                    if ((int) $lock['room_id'] === (int) ($roomMatch[1] ?? 0)
                        && (string) $lock['checkin'] === (string) ($checkinMatch[1] ?? '')
                        && (string) $lock['checkout'] === (string) ($checkoutMatch[1] ?? '')
                        && (string) $lock['session_id'] === (string) ($sessionMatch[1] ?? '')
                        && (string) $lock['expires_at'] > '2026-07-16 12:00:00'
                    ) {
                        unset($this->locks[$index]);
                        $deleted++;
                    }
                }
                $this->locks = \array_values($this->locks);
                return $deleted;
            }
            return false;
        }

        public function insert(string $table, array $data, array $formats)
        {
            unset($formats);
            if ($table !== 'wp_must_reservations') {
                return false;
            }
            $this->insertAttempts++;
            if ($this->failInsertAt > 0 && $this->insertAttempts === $this->failInsertAt) {
                $this->last_error = 'Injected insert failure';
                return false;
            }
            foreach ($this->reservations as $reservation) {
                if ((string) ($reservation['booking_id'] ?? '') === (string) ($data['booking_id'] ?? '')) {
                    return false;
                }
            }
            $this->insert_id++;
            $data['id'] = $this->insert_id;
            $this->reservations[] = $data;
            return 1;
        }

        private function compact(string $query): string
        {
            return \trim((string) \preg_replace('/\s+/', ' ', $query));
        }
    }
}

namespace MustHotelBooking\Core {
    final class ReservationStatus
    {
        public static function isConfirmed(string $status): bool
        {
            return \in_array($status, ['confirmed', 'completed'], true);
        }
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Engine {
    final class CancellationEngine
    {
        public static function getCancellationPolicy(int $ratePlanId): ?array
        {
            unset($ratePlanId);
            return null;
        }
    }

    final class ReservationEngine
    {
        public static function buildReservationNote(int $roomId, array $guestForm): string
        {
            unset($roomId, $guestForm);
            return 'Website booking';
        }
    }

    /** @var object|null */
    $atomicRepository = null;

    function get_reservation_repository()
    {
        global $atomicRepository;
        return $atomicRepository;
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockBookingReferenceMapper
    {
        public static function extractClockIdentifiers(array $reservation): array
        {
            unset($reservation);
            return ['clock_booking_id' => '', 'clock_booking_reference' => ''];
        }
    }
}

namespace {
    require __DIR__ . '/../src/Database/AbstractRepository.php';
    require __DIR__ . '/../src/Database/ReservationRepository.php';
    require __DIR__ . '/../src/Provider/Clock/ClockMirrorReservationService.php';

    use MustHotelBooking\Database\ReservationRepository;
    use MustHotelBooking\Provider\Clock\ClockMirrorReservationService;

    function atomic_lock(int $roomId, string $session = 'session-a', string $expires = '2026-07-16 12:10:00'): array
    {
        return [
            'room_id' => $roomId,
            'checkin' => '2026-08-01',
            'checkout' => '2026-08-03',
            'session_id' => $session,
            'expires_at' => $expires,
        ];
    }

    function atomic_row(int $physicalRoomId, string $bookingId): array
    {
        return [
            'booking_id' => $bookingId,
            'room_id' => 10,
            'room_type_id' => 10,
            'assigned_room_id' => $physicalRoomId,
            'rate_plan_id' => 30,
            'guest_id' => 55,
            'checkin' => '2026-08-01',
            'checkout' => '2026-08-03',
            'guests' => 2,
            'status' => 'pending_payment',
            'booking_source' => 'website',
            'notes' => 'Website booking',
            'total_price' => 200.0,
            'payment_status' => 'pending',
            'confirmation_flow' => 'legacy',
            'provider' => 'clock',
            'provider_sync_status' => 'pending_payment',
            'provider_metadata' => ['physical_room_id' => $physicalRoomId],
        ];
    }

    /** @param mixed $actual */
    function atomic_expect($actual, $expected, string $message, array &$failures): void
    {
        if ($actual !== $expected) {
            $failures[] = $message . ' Expected ' . \var_export($expected, true) . ', got ' . \var_export($actual, true) . '.';
        }
    }

    $failures = [];

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21)];
    $repository = new ReservationRepository($wpdb);
    $result = $repository->createProviderMirrorReservationsFromLocks([atomic_row(21, 'MHB-ONE')], 'session-a');
    atomic_expect($result['success'] ?? null, true, 'A valid exact lock must convert successfully.', $failures);
    atomic_expect($result['reservation_ids'] ?? null, [1], 'A valid conversion must return its reservation ID.', $failures);
    atomic_expect(\count($wpdb->reservations), 1, 'A valid conversion must insert one reservation.', $failures);
    atomic_expect(\count($wpdb->locks), 0, 'A valid conversion must consume its exact lock.', $failures);

    foreach ([
        'missing' => [[], 'session-a'],
        'expired' => [[atomic_lock(21, 'session-a', '2026-07-16 11:59:59')], 'session-a'],
        'wrong_owner' => [[atomic_lock(21, 'session-a')], 'session-b'],
    ] as $label => $case) {
        $wpdb = new AtomicLockWpdb();
        $wpdb->locks = $case[0];
        $result = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks([atomic_row(21, 'MHB-' . $label)], $case[1]);
        atomic_expect($result['reason_code'] ?? null, 'lock_unavailable', $label . ' lock must fail closed.', $failures);
        atomic_expect(\count($wpdb->reservations), 0, $label . ' lock must create no reservation.', $failures);
    }

    foreach (['pending', 'pending_payment', 'confirmed', 'completed', 'blocked'] as $status) {
        $wpdb = new AtomicLockWpdb();
        $wpdb->locks = [atomic_lock(21)];
        $wpdb->reservations = [\array_merge(atomic_row(21, 'EXISTING-' . $status), ['status' => $status])];
        $result = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks([atomic_row(21, 'NEW-' . $status)], 'session-a');
        atomic_expect($result['reason_code'] ?? null, 'reservation_overlap', $status . ' overlap must block conversion.', $failures);
        atomic_expect(\count($wpdb->locks), 1, $status . ' overlap must leave the lock intact.', $failures);
    }

    foreach (['cancelled', 'expired', 'payment_failed'] as $status) {
        $wpdb = new AtomicLockWpdb();
        $wpdb->locks = [atomic_lock(21)];
        $wpdb->reservations = [\array_merge(atomic_row(21, 'EXISTING-' . $status), ['status' => $status])];
        $result = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks([atomic_row(21, 'NEW-' . $status)], 'session-a');
        atomic_expect($result['success'] ?? null, true, $status . ' overlap must not block conversion.', $failures);
    }

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21, 'session-a'), atomic_lock(21, 'session-b')];
    $first = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks([atomic_row(21, 'MHB-FIRST')], 'session-a');
    $second = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks([atomic_row(21, 'MHB-SECOND')], 'session-b');
    atomic_expect($first['success'] ?? null, true, 'The first logical conversion must succeed.', $failures);
    atomic_expect($second['reason_code'] ?? null, 'reservation_overlap', 'The second logical conversion must observe the committed overlap.', $failures);
    atomic_expect(\count($wpdb->reservations), 1, 'Two logical conversions may create only one reservation.', $failures);

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(22), atomic_lock(21)];
    $repository = new ReservationRepository($wpdb);
    $result = $repository->createProviderMirrorReservationsFromLocks(
        [atomic_row(22, 'MHB-TWO'), atomic_row(21, 'MHB-ONE')],
        'session-a'
    );
    atomic_expect($result['success'] ?? null, true, 'Two valid locks must convert in one batch.', $failures);
    atomic_expect($result['reservation_ids'] ?? null, [1, 2], 'Reservation IDs must preserve selection order.', $failures);
    atomic_expect($wpdb->mutexOrder, [21, 22], 'Physical mutexes must be acquired in ascending room order.', $failures);

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21), atomic_lock(22)];
    $wpdb->failInsertAt = 2;
    $result = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks(
        [atomic_row(21, 'MHB-ROLLBACK-ONE'), atomic_row(22, 'MHB-ROLLBACK-TWO')],
        'session-a'
    );
    atomic_expect($result['reason_code'] ?? null, 'reservation_insert_failed', 'A later insert failure must fail the batch.', $failures);
    atomic_expect(\count($wpdb->reservations), 0, 'A later insert failure must roll back every reservation.', $failures);
    atomic_expect(\count($wpdb->locks), 2, 'A later insert failure must restore every consumed lock.', $failures);

    foreach (['overlap_query', 'lock_delete', 'commit'] as $failureMode) {
        $wpdb = new AtomicLockWpdb();
        $wpdb->locks = [atomic_lock(21)];
        if ($failureMode === 'overlap_query') {
            $wpdb->failOverlapQuery = true;
        } elseif ($failureMode === 'lock_delete') {
            $wpdb->failDelete = true;
        } else {
            $wpdb->failCommit = true;
        }
        $result = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks(
            [atomic_row(21, 'MHB-FAIL-' . $failureMode)],
            'session-a'
        );
        atomic_expect($result['success'] ?? null, false, $failureMode . ' failure must fail closed.', $failures);
        atomic_expect(\count($wpdb->reservations), 0, $failureMode . ' failure must leave no reservation.', $failures);
        atomic_expect(\count($wpdb->locks), 1, $failureMode . ' failure must preserve the exact lock.', $failures);
    }

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21), atomic_lock(22, 'session-b')];
    $result = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks(
        [atomic_row(21, 'MHB-PARTIAL-ONE'), atomic_row(22, 'MHB-PARTIAL-TWO')],
        'session-a'
    );
    atomic_expect($result['reason_code'] ?? null, 'lock_unavailable', 'A missing second owned lock must fail the batch.', $failures);
    atomic_expect(\count($wpdb->reservations), 0, 'A missing second lock must leave zero reservations.', $failures);
    atomic_expect(\count($wpdb->locks), 2, 'A missing second lock must consume neither lock.', $failures);

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21)];
    $duplicateResult = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks(
        [atomic_row(21, 'MHB-DUPLICATE-ONE'), atomic_row(21, 'MHB-DUPLICATE-TWO')],
        'session-a'
    );
    atomic_expect($duplicateResult['reason_code'] ?? null, 'invalid_input', 'Duplicate physical-room input must fail before the transaction.', $failures);
    atomic_expect($wpdb->mutexOrder, [], 'Duplicate physical-room input must acquire no mutex.', $failures);

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(99)];
    $missingRoomResult = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks(
        [atomic_row(99, 'MHB-MISSING-ROOM')],
        'session-a'
    );
    atomic_expect($missingRoomResult['reason_code'] ?? null, 'physical_room_missing', 'A missing physical mutex row must fail closed.', $failures);
    atomic_expect(\count($wpdb->locks), 1, 'A missing physical mutex row must not consume the lock.', $failures);

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21)];
    $wpdb->engines['wp_must_reservations'] = 'MyISAM';
    $result = (new ReservationRepository($wpdb))->createProviderMirrorReservationsFromLocks([atomic_row(21, 'MHB-MYISAM')], 'session-a');
    atomic_expect($result['reason_code'] ?? null, 'transactional_storage_required', 'Nontransactional storage must fail closed.', $failures);
    atomic_expect(\count($wpdb->reservations), 0, 'Storage rejection must occur before mutation.', $failures);

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21), atomic_lock(22)];
    $repository = new ReservationRepository($wpdb);
    $atomicRepository = $repository;
    $atomicHooks = [];
    $service = new ClockMirrorReservationService();
    $serviceResult = $service->createPendingMirrorReservationsFromLocks(
        ['checkin' => '2026-08-01', 'checkout' => '2026-08-03', 'guests' => 2],
        ['first_name' => 'Ada', 'last_name' => 'Guest', 'email' => 'ada@example.test'],
        55,
        [
            [
                'room_id' => 10,
                'room_type_id' => 10,
                'physical_room_id' => 22,
                'rate_plan_id' => 30,
                'guests' => 2,
                'pricing' => ['total_price' => 200.0],
                'room_mapping' => ['external_id' => '1001'],
                'physical_mapping' => ['external_id' => '502'],
                'rate_plan_mapping' => ['external_id' => '900'],
            ],
            [
                'room_id' => 10,
                'room_type_id' => 10,
                'physical_room_id' => 21,
                'rate_plan_id' => 30,
                'guests' => 2,
                'pricing' => ['total_price' => 210.0],
                'room_mapping' => ['external_id' => '1001'],
                'physical_mapping' => ['external_id' => '501'],
                'rate_plan_mapping' => ['external_id' => '900'],
            ],
        ],
        [
            'reservation_status' => 'pending_payment',
            'payment_status' => 'pending',
            'confirmation_flow' => 'legacy',
            'coupon_code' => '',
            'defer_provider_creation' => true,
            'pending_guest_form' => ['first_name' => 'Ada', 'last_name' => 'Guest', 'email' => 'ada@example.test'],
        ],
        'session-a'
    );
    atomic_expect($serviceResult['success'] ?? null, true, 'Clock pending mirrors must use the atomic batch path.', $failures);
    atomic_expect(\count($atomicHooks), 2, 'Committed mirrors must emit one post-commit hook each.', $failures);

    $wpdb = new AtomicLockWpdb();
    $wpdb->locks = [atomic_lock(21), atomic_lock(22)];
    $wpdb->failInsertAt = 2;
    $atomicRepository = new ReservationRepository($wpdb);
    $atomicHooks = [];
    $serviceResult = $service->createPendingMirrorReservationsFromLocks(
        ['checkin' => '2026-08-01', 'checkout' => '2026-08-03', 'guests' => 2],
        ['first_name' => 'Ada'],
        55,
        [
            ['room_id' => 10, 'room_type_id' => 10, 'physical_room_id' => 21, 'rate_plan_id' => 30, 'pricing' => ['total_price' => 200.0]],
            ['room_id' => 10, 'room_type_id' => 10, 'physical_room_id' => 22, 'rate_plan_id' => 30, 'pricing' => ['total_price' => 200.0]],
        ],
        ['reservation_status' => 'pending_payment', 'payment_status' => 'pending', 'defer_provider_creation' => true],
        'session-a'
    );
    atomic_expect($serviceResult['success'] ?? null, false, 'Rolled-back Clock pending mirrors must report failure.', $failures);
    atomic_expect(\count($atomicHooks), 0, 'Rolled-back mirrors must emit no reservation-created hooks.', $failures);

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock atomic exact-room lock conversion tests passed.\n";
}
