<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function __($text, $domain = null): string { unset($domain); return (string) $text; }
    function sanitize_key($value): string { return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function sanitize_text_field($value): string { return \trim((string) $value); }
    function current_time(string $format): string { return $format === 'mysql' ? '2026-06-22 12:00:00' : \gmdate($format); }
    function wp_json_encode($value, int $flags = 0): string { return (string) \json_encode($value, $flags); }
    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): void { unset($hook, $callback, $priority, $acceptedArgs); }
}

namespace MustHotelBooking\Core {
    final class MustBookingConfig
    {
        public static function get_currency(): string { return 'EUR'; }
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
        public function enqueueOnce(array $job): int { unset($job); return 1; }
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class ClockConfig
    {
        public static function paymentPostingMode(): string { return 'auto_detect'; }
        public static function hasVerifiedDepositPaymentEndpoint(): bool { return true; }
    }

    final class ClockAccountingReason
    {
        public const CLOCK_REQUEST_FAILED = 'clock_request_failed';
        public const CLOCK_POSTING_REQUIRES_MANUAL_ACTION = 'clock_posting_requires_manual_action';
        public const CLOCK_BOOKING_NOT_FOUND = 'clock_booking_not_found';
        public const REFUND_REQUIRES_MANUAL_CLOCK_ACTION = 'refund_requires_manual_clock_action';
        public const FUTURE_BOOKING_REQUIRES_DEPOSIT_ENDPOINT = 'future_booking_requires_deposit_endpoint';
        public static function normalize(string $reasonCode, string $fallback): string { return $reasonCode !== '' ? $reasonCode : $fallback; }
        public static function forFolioMessage(string $message, string $errorCode = ''): string { unset($message, $errorCode); return self::CLOCK_REQUEST_FAILED; }
    }

    class ClockFolioService
    {
        public int $postCalls = 0;
        public bool $transferred = false;
        public bool $depositFlag = true;
        public float $balanceBefore = 0.0;
        public float $balanceAfter = -150.0;
        public float $paymentAmount = 150.0;
        public float $standardAfter = 150.0;
        public string $creditItemId = 'credit-1';
        private int $depositBalanceReads = 0;
        private int $standardBalanceReads = 0;

        public function selectOrCreateDepositFolio(string $bookingId, string $currency, int $reservationId = 0, string $preferred = ''): array
        {
            unset($bookingId, $currency, $reservationId, $preferred);
            return ['success' => true, 'folio_id' => 'deposit-1', 'folio' => ['id' => 'deposit-1', 'deposit' => true]];
        }

        public function selectPaymentFolio(string $bookingId, float $amount, string $currency, int $reservationId = 0): array
        {
            unset($bookingId, $amount, $currency, $reservationId);
            return ['success' => false, 'folio_id' => '', 'message' => 'Standard folio selection must not run.'];
        }

        public function readStandardFolioBalances(string $bookingId, int $reservationId = 0, string $exclude = ''): array
        {
            unset($bookingId, $reservationId, $exclude);
            $this->standardBalanceReads++;
            return ['success' => true, 'balances' => ['standard-1' => $this->standardBalanceReads === 1 ? 150.0 : $this->standardAfter], 'message' => ''];
        }

        public function readFolioBalance(string $folioId, int $reservationId = 0): array
        {
            unset($folioId, $reservationId);
            $this->depositBalanceReads++;
            return [
                'success' => true,
                'balance' => $this->depositBalanceReads === 1 ? $this->balanceBefore : $this->balanceAfter,
                'raw_balance' => $this->depositBalanceReads === 1 ? $this->balanceBefore : $this->balanceAfter,
                'deposit' => $this->depositFlag,
                'postable' => true,
                'currency' => 'EUR',
                'message' => '',
            ];
        }

        public function postCreditItem(string $folioId, string $gateway, string $direction, float $amount, string $currency, string $reference, string $idempotencyKey, int $reservationId = 0): array
        {
            unset($folioId, $gateway, $direction, $amount, $currency, $reference, $idempotencyKey, $reservationId);
            $this->postCalls++;
            return ['success' => true, 'credit_item_id' => $this->creditItemId];
        }

        public function validateRefundFolio(string $bookingId, string $folioId, int $reservationId = 0, bool $requireUnusedDeposit = false): array
        {
            unset($bookingId, $folioId, $reservationId, $requireUnusedDeposit);
            return $this->transferred
                ? ['success' => false, 'message' => 'The Clock deposit appears transferred, deducted, or applied.']
                : ['success' => true, 'folio_id' => 'deposit-1'];
        }
    }
}

namespace MustHotelBooking\Database {
    class PaymentRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $payments;
        public function __construct(array $payments) { $this->payments = $payments; }
        public function getPayment(int $id): ?array { return $this->payments[$id] ?? null; }
    }

    class RefundRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $refunds;
        public function __construct(array $refunds) { $this->refunds = $refunds; }
        public function getRefund(int $id): ?array { return $this->refunds[$id] ?? null; }
        public function updateRefund(int $id, array $data): bool { $this->refunds[$id] = \array_merge($this->refunds[$id] ?? [], $data); return true; }
    }

    class ReservationRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $reservations;
        public function __construct(array $reservations) { $this->reservations = $reservations; }
        public function getReservation(int $id): ?array { return $this->reservations[$id] ?? null; }
        public function updateProviderMetadata(int $id, array $data): bool
        {
            $this->reservations[$id] = \array_merge($this->reservations[$id] ?? [], $data);
            return true;
        }
    }

    class ClockFolioAccountingRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $rows = [];

        public function findPaymentByProviderTransaction(string $gateway, int $reservationId, string $transactionId): ?array
        {
            foreach ($this->rows as $row) {
                if (
                    (string) ($row['gateway'] ?? '') === $gateway
                    && (int) ($row['reservation_id'] ?? 0) === $reservationId
                    && (string) ($row['provider_transaction_id'] ?? '') === $transactionId
                    && \in_array((string) ($row['direction'] ?? ''), ['payment', 'deposit'], true)
                ) {
                    return $row;
                }
            }
            return null;
        }

        public function getOrCreateByIdempotencyKey(string $key, array $data): array
        {
            foreach ($this->rows as $row) {
                if ((string) ($row['idempotency_key'] ?? '') === $key) {
                    return $row;
                }
            }
            $data['id'] = \count($this->rows) + 1;
            $data['idempotency_key'] = $key;
            $this->rows[(int) $data['id']] = $data;
            return $data;
        }

        public function get(int $id): ?array { return $this->rows[$id] ?? null; }
        public function update(int $id, array $data): bool { $this->rows[$id] = \array_merge($this->rows[$id], $data); return true; }
        public function claimPostingAttempt(int $id, string $folioId): bool
        {
            if (!isset($this->rows[$id]) || (string) ($this->rows[$id]['status'] ?? '') === 'posted') {
                return false;
            }
            $this->rows[$id]['status'] = 'retrying';
            $this->rows[$id]['clock_folio_id'] = $folioId;
            return true;
        }
        public function findPostedPaymentForRefund(array $refund): ?array
        {
            foreach ($this->rows as $row) {
                if ((int) ($row['payment_id'] ?? 0) === (int) ($refund['payment_id'] ?? 0) && (string) ($row['status'] ?? '') === 'posted') {
                    return $row;
                }
            }
            return null;
        }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Clock/ClockPaymentAccountingService.php';

    function run_clock_payment_scenario(
        float $balanceAfter,
        bool $depositFlag = true,
        float $standardAfter = 150.0,
        string $creditItemId = 'credit-1',
        float $balanceBefore = 0.0,
        float $paymentAmount = 150.0
    ): array {
        $paymentRepository = new \MustHotelBooking\Database\PaymentRepository([
            1 => ['id' => 1, 'reservation_id' => 10, 'amount' => $paymentAmount, 'currency' => 'EUR', 'method' => 'stripe', 'status' => 'paid', 'transaction_id' => 'pi_case_' . \str_replace(['-', '.'], ['neg', '_'], (string) $balanceAfter) . ($depositFlag ? '_deposit' : '_standard') . '_' . $creditItemId . '_' . (string) $paymentAmount],
        ]);
        $refundRepository = new \MustHotelBooking\Database\RefundRepository([]);
        $reservationRepository = new \MustHotelBooking\Database\ReservationRepository([
            10 => ['id' => 10, 'provider' => 'clock', 'booking_id' => 'BOOK-10', 'provider_booking_id' => 'clock-10', 'provider_reservation_id' => 'clock-10', 'provider_metadata' => '{}'],
        ]);
        $accountingRepository = new \MustHotelBooking\Database\ClockFolioAccountingRepository();
        $folioService = new \MustHotelBooking\Provider\Clock\ClockFolioService();
        $folioService->balanceBefore = $balanceBefore;
        $folioService->balanceAfter = $balanceAfter;
        $folioService->paymentAmount = $paymentAmount;
        $folioService->depositFlag = $depositFlag;
        $folioService->standardAfter = $standardAfter;
        $folioService->creditItemId = $creditItemId;
        $service = new \MustHotelBooking\Provider\Clock\ClockPaymentAccountingService(
            $accountingRepository,
            $paymentRepository,
            $refundRepository,
            $reservationRepository,
            $folioService,
            new \MustHotelBooking\Provider\Storage\ProviderSyncJobRepository()
        );
        $result = $service->syncPaidPayment(1, false);
        return [$result, $accountingRepository->get(1), $reservationRepository->reservations[10] ?? [], $folioService];
    }

    $paymentRepository = new \MustHotelBooking\Database\PaymentRepository([
        1 => ['id' => 1, 'reservation_id' => 10, 'amount' => 150.0, 'currency' => 'EUR', 'method' => 'stripe', 'status' => 'paid', 'transaction_id' => 'pi_same'],
        2 => ['id' => 2, 'reservation_id' => 10, 'amount' => 150.0, 'currency' => 'EUR', 'method' => 'stripe', 'status' => 'paid', 'transaction_id' => 'pi_same'],
    ]);
    $refundRepository = new \MustHotelBooking\Database\RefundRepository([
        5 => ['id' => 5, 'reservation_id' => 10, 'payment_id' => 1, 'booking_id' => 'BOOK-10', 'gateway' => 'stripe', 'provider_payment_reference' => 'pi_same', 'provider_refund_id' => 're_1', 'provider_refund_reference' => 're_1', 'amount' => 150.0, 'currency' => 'EUR', 'clock_refund_item_id' => ''],
    ]);
    $reservationRepository = new \MustHotelBooking\Database\ReservationRepository([
        10 => ['id' => 10, 'provider' => 'clock', 'booking_id' => 'BOOK-10', 'provider_booking_id' => 'clock-10', 'provider_reservation_id' => 'clock-10', 'provider_metadata' => '{}'],
    ]);
    $accountingRepository = new \MustHotelBooking\Database\ClockFolioAccountingRepository();
    $folioService = new \MustHotelBooking\Provider\Clock\ClockFolioService();
    $service = new \MustHotelBooking\Provider\Clock\ClockPaymentAccountingService(
        $accountingRepository,
        $paymentRepository,
        $refundRepository,
        $reservationRepository,
        $folioService,
        new \MustHotelBooking\Provider\Storage\ProviderSyncJobRepository()
    );

    $first = $service->syncPaidPayment(1, false);
    $second = $service->syncPaidPayment(2, false);
    $paymentRows = \array_values(\array_filter($accountingRepository->rows, static function (array $row): bool {
        return \in_array((string) ($row['direction'] ?? ''), ['payment', 'deposit'], true);
    }));
    $paymentAccounting = $paymentRows[0] ?? [];
    $failures = [];

    if (empty($first['success']) || empty($second['success'])) {
        $failures[] = 'Deposit accounting and duplicate replay should succeed.';
    }
    if ($folioService->postCalls !== 1) {
        $failures[] = 'Duplicate provider transactions must post only one Clock deposit credit item.';
    }
    if (\count($paymentRows) !== 1) {
        $failures[] = 'Idempotency must be keyed by provider transaction, reservation, provider, and operation rather than payment row ID.';
    }
    if ((string) ($paymentAccounting['direction'] ?? '') !== 'deposit') {
        $failures[] = 'Online payments must be recorded as deposit accounting.';
    }
    if ((string) ($paymentAccounting['verification_status'] ?? '') !== 'verified_deposit_isolated') {
        $failures[] = 'Deposit accounting must verify that the standard folio remained unchanged.';
    }
    if ((float) ($paymentAccounting['expected_balance'] ?? 0.0) !== -150.0 || (float) ($paymentAccounting['actual_balance'] ?? 0.0) !== -150.0) {
        $failures[] = 'Deposit accounting must store Clock raw signed expected/actual balances, not the normalized deposit amount.';
    }

    $negativeScenario = run_clock_payment_scenario(-150.0);
    $negativeRow = $negativeScenario[1] ?? [];
    if ((string) ($negativeRow['verification_status'] ?? '') !== 'verified_deposit_isolated') {
        $failures[] = 'Clock raw deposit balance 0 -> -150 must verify as an isolated 150 EUR deposit.';
    }

    $partialScenario = run_clock_payment_scenario(-150.0, true, 150.0, 'credit-1', -75.0, 75.0);
    $partialRow = $partialScenario[1] ?? [];
    if ((string) ($partialRow['verification_status'] ?? '') !== 'verified_deposit_isolated') {
        $failures[] = 'A second partial deposit must verify when Clock raw balance moves from -75 to -150.';
    }

    $positiveScenario = run_clock_payment_scenario(150.0);
    $positiveRow = $positiveScenario[1] ?? [];
    if ((string) ($positiveRow['verification_status'] ?? '') !== 'manual_review_deposit_balance_mismatch') {
        $failures[] = 'Clock raw deposit balance 0 -> +150 must require manual review unless Clock documents that sign convention.';
    }

    $mismatchScenario = run_clock_payment_scenario(-140.0);
    $mismatchRow = $mismatchScenario[1] ?? [];
    if ((string) ($mismatchRow['verification_status'] ?? '') !== 'manual_review_deposit_balance_mismatch') {
        $failures[] = 'A deposit folio balance that holds only 140 EUR must require manual review.';
    }

    $invalidFolioScenario = run_clock_payment_scenario(-150.0, false);
    $invalidFolioRow = $invalidFolioScenario[1] ?? [];
    if ((string) ($invalidFolioRow['verification_status'] ?? '') !== 'manual_review_deposit_folio_invalid') {
        $failures[] = 'A selected folio that is not deposit=true must require manual review.';
    }

    $changedStandardScenario = run_clock_payment_scenario(-150.0, true, 0.0);
    $changedStandardRow = $changedStandardScenario[1] ?? [];
    if ((string) ($changedStandardRow['verification_status'] ?? '') !== 'manual_review_normal_folio_changed') {
        $failures[] = 'Deposit verification must not pass when the normal accommodation folio changes.';
    }

    $missingCreditScenario = run_clock_payment_scenario(-150.0, true, 150.0, '');
    $missingCreditRow = $missingCreditScenario[1] ?? [];
    if ((string) ($missingCreditRow['verification_status'] ?? '') !== 'manual_review_credit_item_unconfirmed') {
        $failures[] = 'Deposit verification must require a durable Clock credit-item reference when Clock exposes one.';
    }

    $folioService->transferred = true;
    $refundResult = $service->syncRefund(5, false);
    $refundRows = \array_values(\array_filter($accountingRepository->rows, static function (array $row): bool {
        return (string) ($row['direction'] ?? '') === 'refund';
    }));
    $refundAccounting = $refundRows[0] ?? [];

    if (!empty($refundResult['success'])) {
        $failures[] = 'A transferred or applied deposit must stop automatic refund accounting.';
    }
    if ((string) ($refundAccounting['status'] ?? '') !== 'manual_review') {
        $failures[] = 'Transferred deposit refunds must require manual review.';
    }
    if ($folioService->postCalls !== 1) {
        $failures[] = 'Transferred deposit review must not create a negative Clock credit item.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock deposit isolation tests passed.\n";
}
