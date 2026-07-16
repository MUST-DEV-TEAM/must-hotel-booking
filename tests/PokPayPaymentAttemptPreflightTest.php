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

    function sanitize_key($value): string
    {
        return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
    }

    function sanitize_text_field($value): string
    {
        return \trim((string) $value);
    }

    function wp_generate_uuid4(): string
    {
        return '11111111-1111-4111-8111-111111111111';
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

namespace MustHotelBooking\Engine {
    final class FakePaymentRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $rows = [];

        /** @return array<int, array<string, mixed>> */
        public function getPaymentAttemptRows(string $method, string $reference): array
        {
            return \array_values(\array_filter($this->rows, static function (array $row) use ($method, $reference): bool {
                return (string) ($row['method'] ?? '') === $method
                    && (string) ($row['provider_attempt_reference'] ?? '') === $reference;
            }));
        }

        /** @param array<int, int> $ids @param array<string, mixed> $data */
        public function updatePaymentAttemptRows(array $ids, array $data): bool
        {
            if (empty($ids)) {
                return false;
            }
            foreach ($this->rows as $index => $row) {
                if (\in_array((int) ($row['id'] ?? 0), $ids, true)) {
                    $this->rows[$index] = \array_merge($row, $data);
                }
            }
            return true;
        }
    }

    final class FakePaymentRuntime
    {
        public static FakePaymentRepository $repository;
    }

    function get_payment_repository(): FakePaymentRepository
    {
        return FakePaymentRuntime::$repository;
    }

    final class BookingStatusEngine
    {
        /** @param array<int, int> $reservationIds @param array<string, mixed> $attempt */
        public static function createPaymentRows(array $reservationIds, string $method, string $status, string $transactionId, bool $dispatchHooks, array $attempt): array
        {
            unset($dispatchHooks);
            foreach ($reservationIds as $reservationId) {
                FakePaymentRuntime::$repository->rows[] = $attempt + [
                    'id' => \count(FakePaymentRuntime::$repository->rows) + 1,
                    'reservation_id' => $reservationId,
                    'method' => $method,
                    'status' => $status,
                    'transaction_id' => $transactionId,
                    'amount' => 450.0,
                    'currency' => 'EUR',
                ];
            }
            return ['created' => $reservationIds, 'failed' => []];
        }
    }

    final class PaymentEngine
    {
        public static int $providerCreateCalls = 0;
        public static bool $allowPreflight = true;
        /** @var array<int, array<string, mixed>> */
        public static array $rowsAtProviderCreate = [];

        public static function isPokPayConfigured(): bool
        {
            return true;
        }

        /** @return array{status:string} */
        public static function getPokPayCredentialState(): array
        {
            return ['status' => 'verified'];
        }

        public static function getPokPayCheckoutMode(): string
        {
            return 'sdk_confirm_url_redirect';
        }

        public static function getPendingPaymentCleanupMinutes(): int
        {
            return 30;
        }

        /** @param array<string, mixed> $pending @param array<int, int> $reservationIds */
        public static function validateReusablePendingPaymentAttempt(array $pending, string $method, float $amount, string $currency, string $flow): array
        {
            unset($amount, $currency, $flow);
            $rows = get_payment_repository()->getPaymentAttemptRows($method, (string) ($pending['session_id'] ?? ''));
            return self::$allowPreflight && \count($rows) === \count((array) ($pending['reservation_ids'] ?? []))
                ? ['allowed' => true]
                : ['allowed' => false];
        }

        /** @param array<int, int> $reservationIds @param array<string, string> $guestForm */
        public static function createPokPaySdkOrder(array $reservationIds, array $guestForm, float $amount, string $currency): array
        {
            unset($reservationIds, $guestForm, $amount, $currency);
            self::$providerCreateCalls++;
            self::$rowsAtProviderCreate = get_payment_repository()->rows;
            return [
                'success' => true,
                'order_id' => 'sdk-order-1',
                'checkout_url' => 'https://pay-staging.pokpay.io/sdk-orders/sdk-order-1',
                'expires_at' => '2026-10-01 12:00:00',
            ];
        }

        public static function isPokPayCheckoutUrl(string $url): bool
        {
            return \strpos($url, 'https://pay-staging.pokpay.io/') === 0;
        }
    }
}

namespace MustHotelBooking\Engine\Payment {
    interface PaymentInterface
    {
        public function processPayment(array $reservation, float $amount, array $context = []): array;
        public function refundPayment(array $reservation, float $amount, array $context = []): array;
        public function validatePayment(array $paymentData = []): array;
    }
}

namespace {
    require __DIR__ . '/../src/Engine/Payment/PokPayPayment.php';

    $attempt = [
        'attempt_status' => 'pending',
        'attempt_site_environment' => 'staging',
        'attempt_provider_mode' => 'staging',
        'attempt_account_fingerprint' => 'gateway-fingerprint',
        'attempt_checkout_mode' => 'sdk_confirm_url_redirect',
        'attempt_clock_environment' => 'sandbox',
        'attempt_clock_target_fingerprint' => 'clock-fingerprint',
        'attempt_reservation_set_hash' => 'reservation-hash',
        'attempt_allocation_set_hash' => 'allocation-hash',
        'attempt_group_amount_minor' => 45000,
        'attempt_currency' => 'EUR',
        'attempt_booking_snapshot_hash' => 'snapshot-hash',
        'attempt_failure_code' => '',
    ];
    $failures = [];

    \MustHotelBooking\Engine\FakePaymentRuntime::$repository = new \MustHotelBooking\Engine\FakePaymentRepository();
    $gateway = new \MustHotelBooking\Engine\Payment\PokPayPayment();
    $result = $gateway->processPayment(['reservation_ids' => [147]], 450.0, ['currency' => 'EUR', 'payment_attempt' => $attempt]);
    $providerSnapshot = \MustHotelBooking\Engine\PaymentEngine::$rowsAtProviderCreate[0] ?? [];
    $stored = \MustHotelBooking\Engine\FakePaymentRuntime::$repository->rows[0] ?? [];

    if (empty($result['success']) || \MustHotelBooking\Engine\PaymentEngine::$providerCreateCalls !== 1) {
        $failures[] = 'A valid PokPay attempt should create exactly one provider order.';
    }
    foreach (['reservation_id', 'amount', 'currency', 'attempt_site_environment', 'attempt_provider_mode', 'attempt_account_fingerprint', 'attempt_clock_environment', 'attempt_clock_target_fingerprint', 'attempt_expires_at', 'provider_attempt_reference'] as $field) {
        if (!\array_key_exists($field, $providerSnapshot) || $providerSnapshot[$field] === '') {
            $failures[] = 'Provider order creation must follow durable ' . $field . ' persistence.';
        }
    }
    if ((string) ($stored['provider_attempt_reference'] ?? '') !== 'sdk-order-1'
        || (string) ($stored['transaction_id'] ?? '') !== 'sdk-order-1'
        || (string) ($stored['attempt_clock_environment'] ?? '') !== 'sandbox'
        || (string) ($stored['attempt_clock_target_fingerprint'] ?? '') !== 'clock-fingerprint') {
        $failures[] = 'The provider-bound attempt must retain the immutable Clock bindings after reread.';
    }

    \MustHotelBooking\Engine\FakePaymentRuntime::$repository = new \MustHotelBooking\Engine\FakePaymentRepository();
    \MustHotelBooking\Engine\PaymentEngine::$providerCreateCalls = 0;
    \MustHotelBooking\Engine\PaymentEngine::$rowsAtProviderCreate = [];
    \MustHotelBooking\Engine\PaymentEngine::$allowPreflight = false;
    $blocked = $gateway->processPayment(['reservation_ids' => [147]], 450.0, ['currency' => 'EUR', 'payment_attempt' => $attempt]);
    if (!empty($blocked['success']) || \MustHotelBooking\Engine\PaymentEngine::$providerCreateCalls !== 0) {
        $failures[] = 'PokPay must not create a provider order when immutable binding reread fails.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "PokPay payment-attempt preflight test passed.\n";
}
