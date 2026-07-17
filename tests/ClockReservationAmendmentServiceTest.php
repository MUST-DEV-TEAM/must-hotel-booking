<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function __(string $text, ?string $domain = null): string
    {
        unset($domain);
        return $text;
    }

    function sanitize_text_field(string $value): string
    {
        return \trim(\strip_tags($value));
    }

    function current_time(string $format): string
    {
        return $format === 'mysql' ? '2026-06-18 12:00:00' : \gmdate($format);
    }

    function wp_json_encode($value): string
    {
        return (string) \json_encode($value);
    }

    function get_current_user_id(): int
    {
        return 7;
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Provider\Storage {
    class ProviderMappingRepository
    {
        /** @return array<string, mixed>|null */
        public function findByLocal(string $provider, string $type, int $localId, string $table): ?array
        {
            unset($provider, $table);
            $map = [
                'accommodation' => [10 => '410', 20 => '420'],
                'physical_room' => [1 => '501', 2 => '502', 3 => '503'],
                'rate_plan' => [1 => '601', 2 => '602'],
            ];
            return isset($map[$type][$localId]) ? ['external_id' => $map[$type][$localId]] : null;
        }
    }

    class ProviderSyncJobRepository
    {
        public const STATUS_PENDING = 'pending';
        /** @var array<int, array<string, mixed>> */
        public $rows = [];

        /** @param array<string, mixed> $row */
        public function enqueueOnce(array $row): int
        {
            $this->rows[] = $row;
            return \count($this->rows);
        }
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class FakeResponse
    {
        /** @var bool */
        private $success;
        /** @var array<string, mixed> */
        private $data;
        /** @var int */
        private $status;
        /** @var string */
        private $error;

        /** @param array<string, mixed> $data */
        public function __construct(bool $success, array $data = [], int $status = 200, string $error = '')
        {
            $this->success = $success;
            $this->data = $data;
            $this->status = $status;
            $this->error = $error;
        }

        public function isSuccess(): bool { return $this->success; }
        /** @return array<string, mixed> */
        public function getData(): array { return $this->data; }
        public function getStatusCode(): int { return $this->status; }
        public function getErrorMessage(): string { return $this->error; }
    }

    class ClockApiClient
    {
        /** @var array<int, FakeResponse> */
        public $responses = [];
        /** @var array<int, array<string, mixed>> */
        public $calls = [];

        /** @param array<string, mixed> $options */
        public function get(string $path, array $query = [], string $operation = 'clock.get', array $options = []): FakeResponse
        {
            unset($query);
            $this->calls[] = ['method' => 'GET', 'path' => $path, 'operation' => $operation, 'options' => $options];
            return \array_shift($this->responses) ?: new FakeResponse(false, [], 500, 'missing fake response');
        }

        /** @param array<string, mixed> $options */
        public function request(string $method, string $path, array $options = [], string $operation = 'clock.request'): FakeResponse
        {
            $this->calls[] = ['method' => $method, 'path' => $path, 'operation' => $operation, 'options' => $options];
            return \array_shift($this->responses) ?: new FakeResponse(false, [], 500, 'missing fake response');
        }
    }

    class ClockQuoteProvider
    {
        /** @var float */
        public $total = 140.0;

        /** @return array<string, mixed> */
        public function calculateTotal(int $roomId, string $checkin, string $checkout, int $guests, string $coupon, int $ratePlanId): array
        {
            unset($roomId, $checkin, $checkout, $guests, $coupon, $ratePlanId);
            return ['success' => true, 'total_price' => $this->total];
        }
    }
}

namespace MustHotelBooking\Engine {
    final class FakeClockReservationRepository
    {
        /** @var array<string, mixed> */
        public $row = [];
        /** @var bool */
        public $failUpdate = false;
        /** @var int */
        public $updates = 0;

        public function getReservation(int $id): ?array
        {
            return $id === (int) ($this->row['id'] ?? 0) ? $this->row : null;
        }

        /** @param array<string, mixed> $updates */
        public function updateReservation(int $id, array $updates): bool
        {
            if ($this->failUpdate || $id !== (int) ($this->row['id'] ?? 0)) {
                return false;
            }
            $this->row = \array_merge($this->row, $updates);
            $this->updates++;
            return true;
        }

        /** @param array<string, mixed> $updates */
        public function updateProviderMetadata(int $id, array $updates): bool
        {
            if ($id !== (int) ($this->row['id'] ?? 0)) {
                return false;
            }
            $this->row = \array_merge($this->row, $updates);
            return true;
        }
    }

    final class FakeClockActivityRepository
    {
        /** @var array<int, array<string, mixed>> */
        public $rows = [];
        /** @param array<string, mixed> $row */
        public function createActivity(array $row): int
        {
            $this->rows[] = $row;
            return \count($this->rows);
        }
    }

    $GLOBALS['mhb_clock_reservations'] = new FakeClockReservationRepository();
    $GLOBALS['mhb_clock_activity'] = new FakeClockActivityRepository();

    function get_reservation_repository(): FakeClockReservationRepository
    {
        return $GLOBALS['mhb_clock_reservations'];
    }

    function get_activity_repository(): FakeClockActivityRepository
    {
        return $GLOBALS['mhb_clock_activity'];
    }
}

namespace {
    use MustHotelBooking\Engine\FakeClockReservationRepository;
    use MustHotelBooking\Provider\Clock\ClockApiClient;
    use MustHotelBooking\Provider\Clock\ClockQuoteProvider;
    use MustHotelBooking\Provider\Clock\ClockReservationAmendmentService;
    use MustHotelBooking\Provider\Clock\FakeResponse;
    use MustHotelBooking\Provider\Storage\ProviderMappingRepository;
    use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;

    require __DIR__ . '/../src/Provider/Clock/ClockReservationAmendmentService.php';

    final class ClockReservationAmendmentServiceTest
    {
        /** @var FakeClockReservationRepository */
        private $reservations;

        public function __construct()
        {
            $this->reservations = $GLOBALS['mhb_clock_reservations'];
        }

        public function run(): void
        {
            $this->mutationRequiresRereadAndCreatesReview();
            $this->duplicateRequestRereadsBeforeSkippingWrite();
            $this->timeoutQueuesRereadFirstRetry();
            $this->writeTimeoutDoesNotConfirmLocally();
            $this->checkedInRoomMoveIsBlocked();
            $this->checkedInRoomTypeChangeIsBlocked();
            $this->checkedInDateOnlyWriteOmitsRoomFields();
            $this->localFailureAfterProviderSuccessQueuesReconciliation();
            $this->downgradeCreatesRefundReview();
            $this->samePriceClearsFinancialReview();
            $this->missingMappingBlocksMutation();
            $this->completedRetryPreservesFinancialReview();
            $this->syncJobRereadsBeforeAnyRepeatedWrite();
        }

        private function mutationRequiresRereadAndCreatesReview(): void
        {
            $this->reset();
            $client = new ClockApiClient();
            $client->responses = [
                new FakeResponse(true, $this->remote(false)),
                new FakeResponse(true, $this->remote(true)),
                new FakeResponse(true, $this->remote(true)),
            ];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $methods = \array_column($client->calls, 'method');
            $metadata = \json_decode((string) $this->reservations->row['provider_metadata'], true);
            $this->assertSame(['GET', 'PUT', 'GET'], $methods, 'Clock mutation is bracketed by provider rereads');
            $this->assertTrue((bool) ($result['success'] ?? false), 'Clock amendment succeeds after matching reread');
            $this->assertSame(140.0, (float) $this->reservations->row['total_price'], 'provider-confirmed total updates locally');
            $this->assertTrue(!empty($metadata['additional_payment_review_required']), 'price increase creates review state');
            $this->assertSame('paid', (string) $this->reservations->row['payment_status'], 'payment status is unchanged');
            $this->assertSame(0, \count($jobs->rows), 'successful amendment creates no retry job');
        }

        private function duplicateRequestRereadsBeforeSkippingWrite(): void
        {
            $this->reset();
            $client = new ClockApiClient();
            $client->responses = [new FakeResponse(true, $this->remote(true))];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $this->assertTrue((bool) ($result['success'] ?? false), 'already-applied Clock amendment succeeds');
            $this->assertSame(['GET'], \array_column($client->calls, 'method'), 'duplicate request rereads and does not mutate again');
        }

        private function timeoutQueuesRereadFirstRetry(): void
        {
            $this->reset();
            $client = new ClockApiClient();
            $client->responses = [new FakeResponse(false, [], 0, 'timeout')];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $this->assertTrue(!empty($result['queued']), 'provider timeout queues retry');
            $this->assertSame('reservation_amendment', (string) ($jobs->rows[0]['operation'] ?? ''), 'retry uses amendment operation');
            $this->assertSame(1, (int) $this->reservations->row['assigned_room_id'], 'timeout preserves original local room');
        }

        private function writeTimeoutDoesNotConfirmLocally(): void
        {
            $this->reset();
            $client = new ClockApiClient();
            $client->responses = [
                new FakeResponse(true, $this->remote(false)),
                new FakeResponse(false, [], 0, 'write timeout'),
            ];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $this->assertTrue(!empty($result['manual_review_required']), 'unconfirmed write timeout requires manual review instead of blind replay');
            $this->assertSame(['GET', 'PUT', 'GET'], \array_column($client->calls, 'method'), 'write timeout triggers an immediate authoritative reread');
            $this->assertSame(0, \count($jobs->rows), 'ambiguous write is not automatically queued when Clock does not document safe replay');
            $this->assertSame(1, (int) $this->reservations->row['assigned_room_id'], 'write timeout does not update the local room');
            $this->assertSame(100.0, (float) $this->reservations->row['total_price'], 'write timeout does not update the local total');
        }

        private function checkedInRoomMoveIsBlocked(): void
        {
            $this->reset();
            $this->reservations->row['checked_in_at'] = '2026-07-10 15:00:00';
            $client = new ClockApiClient();
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $this->assertTrue(!empty($result['manual_review_required']), 'undocumented in-house Clock move is blocked for review');
            $this->assertSame(0, \count($client->calls), 'blocked in-house move sends no guessed API mutation');
        }

        private function checkedInRoomTypeChangeIsBlocked(): void
        {
            $this->reset();
            $this->reservations->row['checked_in_at'] = '2026-07-10 15:00:00';
            $request = $this->request();
            $request['target_assigned_room_id'] = 1;
            $client = new ClockApiClient();
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $request, 'test');
            $this->assertTrue(!empty($result['manual_review_required']), 'checked-in room-type change is blocked for review');
            $this->assertSame(0, \count($client->calls), 'checked-in room-type change sends no guessed API mutation');
        }

        private function checkedInDateOnlyWriteOmitsRoomFields(): void
        {
            $this->reset();
            $this->reservations->row['checked_in_at'] = '2026-07-10 15:00:00';
            $request = [
                'target_room_type_id' => 10,
                'target_assigned_room_id' => 1,
                'target_rate_plan_id' => 1,
                'target_checkin' => '2026-07-10',
                'target_checkout' => '2026-07-13',
            ];
            $before = $this->remote(false);
            $before['departure'] = '2026-07-12';
            $after = $before;
            $after['departure'] = '2026-07-13';
            $client = new ClockApiClient();
            $client->responses = [
                new FakeResponse(true, $before),
                new FakeResponse(true, $after),
                new FakeResponse(true, $after),
            ];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $request, 'test');
            $body = (array) ($client->calls[1]['options']['body']['booking'] ?? []);
            $this->assertTrue((bool) ($result['success'] ?? false), 'checked-in date-only correction succeeds');
            $this->assertTrue(!isset($body['arrival_room_id']), 'checked-in date-only write omits arrival room');
            $this->assertTrue(!isset($body['arrival_room_type_id']), 'checked-in date-only write omits arrival room type');
            $this->assertTrue(!isset($body['rate_id']), 'checked-in date-only write omits rate');
            $this->assertSame('2026-07-13', (string) ($body['departure'] ?? ''), 'checked-in date-only write updates departure');
        }

        private function localFailureAfterProviderSuccessQueuesReconciliation(): void
        {
            $this->reset();
            $this->reservations->failUpdate = true;
            $client = new ClockApiClient();
            $client->responses = [
                new FakeResponse(true, $this->remote(false)),
                new FakeResponse(true, $this->remote(true)),
                new FakeResponse(true, $this->remote(true)),
            ];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $this->assertTrue(!empty($result['queued']), 'local failure after Clock success queues reconciliation');
            $this->assertSame(1, \count($jobs->rows), 'one reconciliation job is created');
        }

        private function downgradeCreatesRefundReview(): void
        {
            $this->reset();
            $this->reservations->row['total_price'] = 180.0;
            $client = new ClockApiClient();
            $client->responses = [
                new FakeResponse(true, $this->remote(false)),
                new FakeResponse(true, $this->remote(true)),
                new FakeResponse(true, $this->remote(true)),
            ];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $metadata = \json_decode((string) $this->reservations->row['provider_metadata'], true);
            $this->assertTrue((bool) ($result['success'] ?? false), 'Clock downgrade succeeds');
            $this->assertSame(-40.0, (float) ($result['price_delta'] ?? 0), 'Clock downgrade delta is stored');
            $this->assertTrue(!empty($metadata['refund_or_credit_review_required']), 'Clock downgrade requires refund review');
            $this->assertSame('paid', (string) $this->reservations->row['payment_status'], 'Clock downgrade preserves payment status');
        }

        private function samePriceClearsFinancialReview(): void
        {
            $this->reset();
            $this->reservations->row['total_price'] = 140.0;
            $this->reservations->row['provider_metadata'] = \json_encode([
                'manual_review_required' => true,
                'additional_payment_review_required' => true,
            ]);
            $client = new ClockApiClient();
            $client->responses = [
                new FakeResponse(true, $this->remote(true)),
            ];
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $this->request(), 'test');
            $metadata = \json_decode((string) $this->reservations->row['provider_metadata'], true);
            $this->assertTrue((bool) ($result['success'] ?? false), 'same-price Clock amendment succeeds');
            $this->assertTrue(empty($metadata['manual_review_required']), 'same-price amendment clears amendment financial review');
            $this->assertTrue(empty($metadata['additional_payment_review_required']), 'same-price amendment clears additional-payment review');
            $this->assertTrue(empty($metadata['refund_or_credit_review_required']), 'same-price amendment clears refund review');
        }

        private function missingMappingBlocksMutation(): void
        {
            $this->reset();
            $request = $this->request();
            $request['target_room_type_id'] = 999;
            $client = new ClockApiClient();
            $jobs = new ProviderSyncJobRepository();
            $result = $this->service($client, $jobs)->amend($this->reservations->row, $request, 'test');
            $this->assertTrue(empty($result['success']), 'missing Clock mapping blocks amendment');
            $this->assertSame(0, \count($client->calls), 'missing Clock mapping sends no provider request');
        }

        private function completedRetryPreservesFinancialReview(): void
        {
            $this->reset();
            $firstClient = new ClockApiClient();
            $firstClient->responses = [
                new FakeResponse(true, $this->remote(false)),
                new FakeResponse(true, $this->remote(true)),
                new FakeResponse(true, $this->remote(true)),
            ];
            $jobs = new ProviderSyncJobRepository();
            $service = $this->service($firstClient, $jobs);
            $first = $service->amend($this->reservations->row, $this->request(), 'test');
            $this->assertTrue((bool) ($first['success'] ?? false), 'initial Clock amendment succeeds');
            $updates = $this->reservations->updates;
            $activityCount = \count($GLOBALS['mhb_clock_activity']->rows);

            $retryClient = new ClockApiClient();
            $retryClient->responses = [new FakeResponse(true, $this->remote(true))];
            $retry = $this->service($retryClient, $jobs)->amend($this->reservations->row, $this->request(), 'provider_retry');
            $metadata = \json_decode((string) $this->reservations->row['provider_metadata'], true);
            $this->assertTrue(!empty($retry['no_change']), 'completed Clock retry is a local no-op after reread');
            $this->assertSame($updates, $this->reservations->updates, 'completed retry does not rewrite the reservation');
            $this->assertSame($activityCount, \count($GLOBALS['mhb_clock_activity']->rows), 'completed retry does not duplicate the audit event');
            $this->assertTrue(!empty($metadata['additional_payment_review_required']), 'completed retry preserves additional-payment review');
            $this->assertSame(40.0, (float) ($metadata['last_reservation_amendment']['price_delta'] ?? 0), 'completed retry preserves original price delta');
        }

        private function syncJobRereadsBeforeAnyRepeatedWrite(): void
        {
            $this->reset();
            $client = new ClockApiClient();
            $client->responses = [new FakeResponse(true, $this->remote(true))];
            $jobs = new ProviderSyncJobRepository();
            $jobResult = $this->service($client, $jobs)->executeSyncJob([
                'target_local_id' => 1,
                'payload' => [
                    'source' => 'provider_retry',
                    'request' => $this->request(),
                    'provider_write_may_have_succeeded' => true,
                ],
            ]);
            $this->assertTrue((bool) ($jobResult['success'] ?? false), 'retry job reconciles an already-completed provider write');
            $this->assertSame(['GET'], \array_column($client->calls, 'method'), 'retry job rereads before deciding whether another write is needed');
        }

        private function service(ClockApiClient $client, ProviderSyncJobRepository $jobs): ClockReservationAmendmentService
        {
            return new ClockReservationAmendmentService($client, new ProviderMappingRepository(), $jobs, new ClockQuoteProvider());
        }

        /** @return array<string, mixed> */
        private function request(): array
        {
            return [
                'target_room_type_id' => 20,
                'target_assigned_room_id' => 3,
                'target_rate_plan_id' => 2,
                'target_checkin' => '2026-07-10',
                'target_checkout' => '2026-07-12',
            ];
        }

        /** @return array<string, mixed> */
        private function remote(bool $target): array
        {
            return [
                'id' => 9001,
                'arrival' => '2026-07-10',
                'departure' => '2026-07-12',
                'arrival_room_type_id' => $target ? 420 : 410,
                'arrival_room_id' => $target ? 503 : 501,
                'current_room_id' => $target ? 503 : 501,
                'rate_id' => $target ? 602 : 601,
                'lock_version' => $target ? 4 : 3,
                'total_booking_value' => ['cents' => $target ? 14000 : 10000, 'currency' => 'EUR'],
            ];
        }

        private function reset(): void
        {
            $this->reservations->row = [
                'id' => 1,
                'booking_id' => 'CLK-9001',
                'room_id' => 10,
                'room_type_id' => 10,
                'assigned_room_id' => 1,
                'rate_plan_id' => 1,
                'checkin' => '2026-07-10',
                'checkout' => '2026-07-12',
                'guests' => 2,
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'total_price' => 100.0,
                'provider' => 'clock',
                'provider_booking_id' => '9001',
                'provider_reservation_id' => '9001',
                'provider_metadata' => '',
                'checked_in_at' => '',
                'checked_out_at' => '',
            ];
            $this->reservations->failUpdate = false;
            $this->reservations->updates = 0;
        }

        private function assertTrue(bool $condition, string $message): void
        {
            if (!$condition) {
                throw new \RuntimeException($message);
            }
        }

        /** @param mixed $expected @param mixed $actual */
        private function assertSame($expected, $actual, string $message): void
        {
            if ($expected !== $actual) {
                throw new \RuntimeException($message . ' Expected ' . \var_export($expected, true) . ', got ' . \var_export($actual, true));
            }
        }
    }

    try {
        (new ClockReservationAmendmentServiceTest())->run();
        echo "Clock reservation amendment service tests passed.\n";
    } catch (\Throwable $exception) {
        \fwrite(\STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}
