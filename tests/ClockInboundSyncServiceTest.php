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

    function sanitize_key(string $value): string
    {
        return \strtolower((string) \preg_replace('/[^a-zA-Z0-9_\-]/', '', $value));
    }

    function current_time(string $format): string
    {
        return $format === 'mysql' ? '2026-06-18 10:00:00' : \gmdate($format);
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Provider\Storage {
    class ProviderSyncJobRepository
    {
        public const STATUS_PENDING = 'pending';
        /** @var array<int, array<string, mixed>> */
        public $jobs = [];

        /** @param array<string, mixed> $job */
        public function enqueueOnce(array $job): int
        {
            $this->jobs[] = $job;
            return \count($this->jobs);
        }
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function reservationFetchPath(): string
        {
            return '/bookings/{booking_id}';
        }
    }

    final class FakeClockResponse
    {
        public function isSuccess(): bool
        {
            return false;
        }

        public function getData(): array
        {
            return [];
        }

        public function getErrorMessage(): string
        {
            return 'temporary Clock API failure';
        }
    }

    class ClockApiClient
    {
        /** @param array<string, mixed> $context */
        public function request(string $method, string $path, array $context, string $operation): FakeClockResponse
        {
            unset($method, $path, $context, $operation);
            return new FakeClockResponse();
        }
    }

    final class ClockReservationSyncService
    {
        /** @return array<string, mixed> */
        public function refreshBookingById(string $bookingId, string $source = 'manual'): array
        {
            unset($bookingId, $source);
            return [
                'success' => false,
                'message' => 'Clock room mapping is missing.',
            ];
        }
    }
}

namespace MustHotelBooking\Engine {
    final class FakeReservationRepository
    {
        /** @var array<int, array<string, mixed>> */
        public $rows = [];
        /** @var array<int, array<string, mixed>> */
        public $metadataUpdates = [];

        /** @return array<int, array<string, mixed>> */
        public function getProviderReservationRowsByExternalIds(string $provider, string $providerReservationId = '', string $providerBookingId = ''): array
        {
            unset($provider, $providerReservationId, $providerBookingId);
            return $this->rows;
        }

        /** @return array<int, array<string, mixed>> */
        public function getProviderReservationRowsByIds(array $ids): array
        {
            unset($ids);
            return $this->rows;
        }

        /** @param array<string, mixed> $data */
        public function updateProviderMetadata(int $reservationId, array $data): bool
        {
            $this->metadataUpdates[$reservationId] = $data;
            return true;
        }
    }

    final class BookingLifecycleSyncService
    {
        /** @var array<int, array<string, mixed>> */
        public static $transitions = [];

        /** @param array<string, mixed> $context @return array<string, mixed> */
        public function applyReservationStatusTransition(int $reservationId, string $status, string $paymentStatus = '', array $context = []): array
        {
            self::$transitions[] = [
                'reservation_id' => $reservationId,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'context' => $context,
            ];
            return ['success' => true, 'changed' => true, 'message' => ''];
        }
    }

    $GLOBALS['mhb_fake_reservation_repository'] = new FakeReservationRepository();

    function get_reservation_repository(): FakeReservationRepository
    {
        return $GLOBALS['mhb_fake_reservation_repository'];
    }
}

namespace {
    use MustHotelBooking\Engine\BookingLifecycleSyncService;
    use MustHotelBooking\Engine\FakeReservationRepository;
    use MustHotelBooking\Provider\Clock\ClockApiClient;
    use MustHotelBooking\Provider\Clock\ClockInboundSyncService;
    use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;

    require __DIR__ . '/../src/Provider/Clock/ClockInboundSyncService.php';

    final class ClockInboundServiceTest
    {
        /** @var FakeReservationRepository */
        private $reservations;
        /** @var ProviderSyncJobRepository */
        private $jobs;

        public function __construct()
        {
            $this->reservations = $GLOBALS['mhb_fake_reservation_repository'];
            $this->jobs = new ProviderSyncJobRepository();
        }

        public function run(): void
        {
            $this->statusEventFallsBackWhenFetchFails();
            $this->updateEventRetriesWhenFetchFails();
            $this->missingMappingIsRetryable();
            $this->unsupportedEventIsTerminal();
        }

        private function statusEventFallsBackWhenFetchFails(): void
        {
            $this->reset(true);
            $result = $this->service()->processInboundPayload([
                'event_type' => 'booking_canceled',
                'booking_id' => '36590001',
            ], 'message-cancel');

            $transition = BookingLifecycleSyncService::$transitions[0] ?? [];
            $this->assertSame(true, (bool) ($result['success'] ?? false), 'documented cancellation subject applies a durable local fallback');
            $this->assertSame(200, (int) ($result['status'] ?? 0), 'documented cancellation fallback returns 200');
            $this->assertSame('cancelled', (string) ($transition['status'] ?? ''), 'booking_canceled maps to cancelled');
            $this->assertSame(1, \count($this->jobs->jobs), 'status fallback queues a detail refresh');
        }

        private function updateEventRetriesWhenFetchFails(): void
        {
            $this->reset(true);
            $result = $this->service()->processInboundPayload([
                'event_type' => 'booking_update',
                'booking_id' => '36590002',
            ], 'message-update');

            $this->assertSame(false, (bool) ($result['success'] ?? true), 'booking_update is not acknowledged without fetched details');
            $this->assertSame(true, (bool) ($result['retryable'] ?? false), 'booking_update fetch failure is retryable');
            $this->assertSame(503, (int) ($result['status'] ?? 0), 'booking_update fetch failure returns 503');
            $this->assertSame(0, \count(BookingLifecycleSyncService::$transitions), 'booking_update fetch failure does not invent a status');
            $this->assertSame(1, \count($this->jobs->jobs), 'booking_update fetch failure queues reconciliation');
        }

        private function missingMappingIsRetryable(): void
        {
            $this->reset(false);
            $result = $this->service()->processInboundPayload([
                'event_type' => 'booking_new',
                'booking_id' => '36590003',
            ], 'message-new');

            $this->assertSame(false, (bool) ($result['success'] ?? true), 'missing mapping does not report a successful update');
            $this->assertSame(true, (bool) ($result['retryable'] ?? false), 'missing mapping remains retryable');
            $this->assertSame(503, (int) ($result['status'] ?? 0), 'missing mapping returns a retryable HTTP status');
            $this->assertSame(1, \count($this->jobs->jobs), 'missing mapping queues a later booking upsert reconciliation');
        }

        private function unsupportedEventIsTerminal(): void
        {
            $this->reset(true);
            $result = $this->service()->processInboundPayload([
                'event_type' => 'folio_close',
                'folio_id' => '888888',
            ], 'message-folio');

            $this->assertSame(false, (bool) ($result['success'] ?? true), 'unsupported event is not reported as a booking update');
            $this->assertSame(true, (bool) ($result['unsupported'] ?? false), 'unsupported event is explicit');
            $this->assertSame(200, (int) ($result['status'] ?? 0), 'unsupported terminal event is acknowledged without retrying forever');
        }

        private function reset(bool $withRow): void
        {
            $this->reservations->rows = $withRow ? [[
                'id' => 123,
                'provider' => 'clock',
                'provider_booking_id' => '36590001',
                'provider_reservation_id' => '36590001',
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'provider_metadata' => [],
            ]] : [];
            $this->reservations->metadataUpdates = [];
            $this->jobs->jobs = [];
            BookingLifecycleSyncService::$transitions = [];
        }

        private function service(): ClockInboundSyncService
        {
            return new ClockInboundSyncService(new ClockApiClient(), $this->jobs);
        }

        private function assertSame($expected, $actual, string $message): void
        {
            if ($expected !== $actual) {
                throw new \RuntimeException($message . ' Expected ' . \var_export($expected, true) . ', got ' . \var_export($actual, true) . '.');
            }
        }
    }

    (new ClockInboundServiceTest())->run();
    echo 'Clock inbound service tests passed.' . PHP_EOL;
}
