<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function __($text, $domain = null): string
    {
        unset($domain);
        return (string) $text;
    }

    function sanitize_key($value): string
    {
        return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
    }

    function sanitize_text_field($value): string
    {
        return \trim((string) $value);
    }

    function sanitize_textarea_field($value): string
    {
        return \trim((string) $value);
    }

    function current_time(string $format): string
    {
        return $format === 'mysql' ? '2026-06-18 12:00:00' : \gmdate($format);
    }

    function wp_json_encode($value, int $flags = 0): string
    {
        return (string) \json_encode($value, $flags);
    }

    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        unset($hook, $callback, $priority, $acceptedArgs);
    }
}

namespace MustHotelBooking\Core {
    final class MustBookingConfig
    {
        public static function get_currency(): string
        {
            return 'EUR';
        }
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
        public static function paymentPostingMode(): string
        {
            return 'deposit_for_future_bookings';
        }

        public static function hasVerifiedDepositPaymentEndpoint(): bool
        {
            return true;
        }
    }

    final class ClockAccountingReason
    {
        public const CLOCK_REQUEST_FAILED = 'clock_request_failed';
        public const CLOCK_POSTING_REQUIRES_MANUAL_ACTION = 'clock_posting_requires_manual_action';
        public const CLOCK_BOOKING_NOT_FOUND = 'clock_booking_not_found';
        public const REFUND_REQUIRES_MANUAL_CLOCK_ACTION = 'refund_requires_manual_clock_action';
        public const FUTURE_BOOKING_REQUIRES_DEPOSIT_ENDPOINT = 'future_booking_requires_deposit_endpoint';

        public static function normalize(string $reasonCode, string $fallback): string
        {
            $reasonCode = \sanitize_key($reasonCode);
            return $reasonCode !== '' ? $reasonCode : $fallback;
        }

        public static function forFolioMessage(string $message, string $errorCode = ''): string
        {
            unset($message, $errorCode);
            return self::CLOCK_REQUEST_FAILED;
        }
    }

    class ClockFolioService
    {
        public int $postCalls = 0;

        /** @return array<string, mixed> */
        public function validateRefundFolio(string $clockBookingId, string $folioId, int $reservationId): array
        {
            unset($clockBookingId, $folioId, $reservationId);
            return ['success' => true, 'folio_id' => 'folio_1'];
        }

        /** @return array{success: bool, balance: float|null, message: string} */
        public function readFolioBalance(string $folioId, int $reservationId = 0): array
        {
            unset($folioId, $reservationId);
            return ['success' => true, 'balance' => 20.0, 'message' => ''];
        }

        /** @return array<string, mixed> */
        public function postCreditItem(
            string $folioId,
            string $gateway,
            string $direction,
            float $amount,
            string $currency,
            string $reference,
            string $idempotencyKey,
            int $reservationId = 0
        ): array {
            unset($folioId, $gateway, $direction, $amount, $currency, $reference, $idempotencyKey, $reservationId);
            $this->postCalls++;
            return ['success' => true, 'credit_item_id' => 'credit_1'];
        }
    }
}

namespace MustHotelBooking\Database {
    class PaymentRepository
    {
    }

    class RefundRepository
    {
        /** @var array<string, mixed> */
        public $refund;

        /** @param array<string, mixed> $refund */
        public function __construct(array $refund)
        {
            $this->refund = $refund;
        }

        /** @return array<string, mixed>|null */
        public function getRefund(int $refundId): ?array
        {
            return (int) ($this->refund['id'] ?? 0) === $refundId ? $this->refund : null;
        }

        /** @param array<string, mixed> $data */
        public function updateRefund(int $refundId, array $data): bool
        {
            if ((int) ($this->refund['id'] ?? 0) !== $refundId) {
                return false;
            }

            $this->refund = \array_merge($this->refund, $data);
            return true;
        }
    }

    class ReservationRepository
    {
        /** @var array<string, mixed> */
        private $reservation;

        /** @param array<string, mixed> $reservation */
        public function __construct(array $reservation)
        {
            $this->reservation = $reservation;
        }

        /** @return array<string, mixed>|null */
        public function getReservation(int $reservationId): ?array
        {
            return (int) ($this->reservation['id'] ?? 0) === $reservationId ? $this->reservation : null;
        }
    }

    class ClockFolioAccountingRepository
    {
        /** @var array<int, array<string, mixed>> */
        public $rows = [];
        /** @var array<string, mixed>|null */
        private $originalPaymentAccounting;

        /**
         * @param array<int, array<string, mixed>> $rows
         * @param array<string, mixed>|null $originalPaymentAccounting
         */
        public function __construct(array $rows, ?array $originalPaymentAccounting)
        {
            foreach ($rows as $row) {
                if (\is_array($row) && isset($row['id'])) {
                    $this->rows[(int) $row['id']] = $row;
                }
            }
            $this->originalPaymentAccounting = $originalPaymentAccounting;
        }

        /** @return array<string, mixed> */
        public function getOrCreateByIdempotencyKey(string $idempotencyKey, array $data): array
        {
            foreach ($this->rows as $row) {
                if ((string) ($row['idempotency_key'] ?? '') === $idempotencyKey) {
                    return $row;
                }
            }

            $data['id'] = \count($this->rows) + 1;
            $data['idempotency_key'] = $idempotencyKey;
            $this->rows[(int) $data['id']] = $data;

            return $data;
        }

        /** @return array<string, mixed>|null */
        public function get(int $id): ?array
        {
            return $this->rows[$id] ?? null;
        }

        /** @param array<string, mixed> $data */
        public function update(int $id, array $data): bool
        {
            if (!isset($this->rows[$id])) {
                return false;
            }

            $this->rows[$id] = \array_merge($this->rows[$id], $data);
            return true;
        }

        /** @return array<string, mixed>|null */
        public function findPostedPaymentForRefund(array $refund): ?array
        {
            unset($refund);
            return $this->originalPaymentAccounting;
        }

        public function claimPostingAttempt(int $id, string $folioId): bool
        {
            unset($folioId);
            return isset($this->rows[$id]);
        }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Clock/ClockPaymentAccountingService.php';

    $refundId = 7;
    $providerRefundId = 're_1';
    $idempotencyKey = 'clock-accounting-refund-' . $refundId . '-' . \sha1($providerRefundId);

    $refundRepository = new \MustHotelBooking\Database\RefundRepository([
        'id' => $refundId,
        'reservation_id' => 1,
        'payment_id' => 5,
        'booking_id' => 'TEST-CLOCK-REFUND',
        'gateway' => 'stripe',
        'provider_payment_reference' => 'pi_1',
        'provider_refund_id' => $providerRefundId,
        'provider_refund_reference' => $providerRefundId,
        'amount' => 80.0,
        'currency' => 'EUR',
        'status' => 'succeeded',
        'clock_refund_item_id' => '',
        'clock_sync_status' => 'retrying',
    ]);

    $accountingRepository = new \MustHotelBooking\Database\ClockFolioAccountingRepository(
        [[
            'id' => 11,
            'refund_id' => $refundId,
            'reservation_id' => 1,
            'booking_id' => 'TEST-CLOCK-REFUND',
            'gateway' => 'stripe',
            'provider_transaction_id' => 'pi_1',
            'provider_refund_id' => $providerRefundId,
            'clock_booking_id' => 'clock-booking-1',
            'clock_reservation_id' => 'clock-booking-1',
            'clock_folio_id' => 'folio_1',
            'direction' => 'refund',
            'amount' => -80.0,
            'amount_minor' => -8000,
            'currency' => 'EUR',
            'status' => 'failed',
            'expected_balance' => 20.0,
            'balance_before' => 100.0,
            'actual_balance' => null,
            'idempotency_key' => $idempotencyKey,
        ]],
        [
            'id' => 3,
            'payment_id' => 5,
            'status' => 'posted',
            'direction' => 'deposit',
            'clock_folio_id' => 'folio_1',
        ]
    );

    $service = new \MustHotelBooking\Provider\Clock\ClockPaymentAccountingService(
        $accountingRepository,
        new \MustHotelBooking\Database\PaymentRepository(),
        $refundRepository,
        new \MustHotelBooking\Database\ReservationRepository([
            'id' => 1,
            'provider' => 'clock',
            'booking_id' => 'TEST-CLOCK-REFUND',
            'provider_booking_id' => 'clock-booking-1',
            'provider_reservation_id' => 'clock-booking-1',
        ]),
        $folioService = new \MustHotelBooking\Provider\Clock\ClockFolioService(),
        new \MustHotelBooking\Provider\Storage\ProviderSyncJobRepository()
    );

    $result = $service->syncRefund($refundId, false);
    $accountingRow = $accountingRepository->get(11);
    $updatedRefund = $refundRepository->getRefund($refundId);
    $failures = [];

    if (empty($result['success'])) {
        $failures[] = 'Retry recovery should report success when the expected balance already exists.';
    }
    if ($folioService->postCalls !== 0) {
        $failures[] = 'Retry recovery must reread and recover before posting another Clock refund item.';
    }
    if (!\is_array($accountingRow) || (string) ($accountingRow['status'] ?? '') !== 'posted') {
        $failures[] = 'Recovered accounting row must be marked posted.';
    }
    if (!\is_array($accountingRow) || (string) ($accountingRow['reconciliation_status'] ?? '') !== 'verified') {
        $failures[] = 'Recovered accounting row must be marked verified.';
    }
    if (!\is_array($updatedRefund) || (string) ($updatedRefund['clock_sync_status'] ?? '') !== 'synced') {
        $failures[] = 'Recovered refund must be mirrored as synced.';
    }
    if (!\is_array($updatedRefund) || (string) ($updatedRefund['clock_refund_item_id'] ?? '') !== $idempotencyKey) {
        $failures[] = 'Recovered refund should use the accounting idempotency key as the durable Clock placeholder reference.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock payment accounting retry tests passed.\n";
}
