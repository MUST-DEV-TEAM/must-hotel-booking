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

    function sanitize_text_field($value): string
    {
        return \trim((string) $value);
    }

    function wp_json_encode($value, int $flags = 0): string
    {
        return (string) \json_encode($value, $flags);
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderManager
    {
        public const CLOCK_MODE = 'clock';
    }
}

namespace MustHotelBooking\Engine {
    final class FakeExactRoomReservationRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $rows = [];

        public function getReservationsByIds(array $reservationIds): array
        {
            $ids = \array_map('intval', $reservationIds);
            return \array_values(\array_filter($this->rows, static function (array $row) use ($ids): bool {
                return \in_array((int) ($row['id'] ?? 0), $ids, true);
            }));
        }
    }

    final class FakeExactRoomPaymentRepository
    {
        /** @var array<int, array<string, mixed>> */
        public array $rows = [];

        public function getPaymentAttemptRows(string $provider, string $reference): array
        {
            return \array_values(\array_filter($this->rows, static function (array $row) use ($provider, $reference): bool {
                return (string) ($row['method'] ?? '') === $provider
                    && (string) ($row['provider_attempt_reference'] ?? '') === $reference;
            }));
        }

        public function updatePaymentAttemptRows(array $ids, array $data): bool
        {
            foreach ($this->rows as $index => $row) {
                if (\in_array((int) ($row['id'] ?? 0), $ids, true)) {
                    $this->rows[$index] = \array_merge($row, $data);
                }
            }
            return !empty($ids);
        }
    }

    final class FakeExactRoomRuntime
    {
        public static FakeExactRoomReservationRepository $reservations;
        public static FakeExactRoomPaymentRepository $payments;
    }

    function get_reservation_repository(): FakeExactRoomReservationRepository
    {
        return FakeExactRoomRuntime::$reservations;
    }

    function get_payment_repository(): FakeExactRoomPaymentRepository
    {
        return FakeExactRoomRuntime::$payments;
    }

    final class PaymentEngine
    {
        public static function normalizeMethod(string $provider): string
        {
            return sanitize_key($provider);
        }
    }

    final class BookingStatusEngine
    {
        public static function areReusablePendingPaymentReservations(array $reservationIds): bool
        {
            return !empty($reservationIds);
        }
    }

    final class CurrencyMinorUnits
    {
        public static function toMinor(float $amount, string $currency): int
        {
            unset($currency);
            return (int) \round($amount * 100);
        }
    }

    final class PaymentEnvironmentCompatibilityPolicy
    {
        public static string $siteEnvironment = 'staging';

        public function evaluateCurrent(string $provider, bool $clockRequired, array $expected = []): array
        {
            unset($provider, $clockRequired);
            $current = [
                'site_environment' => self::$siteEnvironment,
                'provider_mode' => 'staging',
                'provider_account_fingerprint' => 'gateway-account',
                'clock_environment' => 'sandbox',
                'clock_target_fingerprint' => 'clock-target',
            ];
            foreach ($expected as $key => $value) {
                if ((string) ($current[$key] ?? '') !== (string) $value) {
                    return ['allowed' => false, 'reason_code' => 'environment_mismatch'];
                }
            }
            return ['allowed' => true, 'reason_code' => ''] + $current;
        }
    }
}

namespace {
    require __DIR__ . '/../src/Engine/PaymentAttemptIntegrity.php';

    use MustHotelBooking\Engine\FakeExactRoomPaymentRepository;
    use MustHotelBooking\Engine\FakeExactRoomReservationRepository;
    use MustHotelBooking\Engine\FakeExactRoomRuntime;
    use MustHotelBooking\Engine\PaymentAttemptIntegrity;
    use MustHotelBooking\Engine\PaymentEnvironmentCompatibilityPolicy;

    function exact_room_metadata(string $physical = '501', string $roomType = '1001', string $rate = '900', bool $reverse = false): string
    {
        $metadata = [
            'source' => 'public_booking_mvp',
            'room_mapping' => ['external_id' => $roomType, 'external_code' => 'STANDARD'],
            'physical_mapping' => ['external_id' => $physical, 'external_code' => 'DIRECT-POOL'],
            'rate_plan_mapping' => ['external_id' => $rate, 'external_code' => 'FLEX'],
            'mutable_diagnostic' => ['attempts' => 1],
        ];
        if ($reverse) {
            $metadata = \array_reverse($metadata, true);
            $metadata['mutable_diagnostic'] = ['attempts' => 99, 'last_error' => 'ignored'];
            $metadata['room_mapping'] = \array_reverse($metadata['room_mapping'], true);
            $metadata['physical_mapping'] = \array_reverse($metadata['physical_mapping'], true);
            $metadata['rate_plan_mapping'] = \array_reverse($metadata['rate_plan_mapping'], true);
        }
        return (string) \json_encode($metadata);
    }

    function exact_room_row(): array
    {
        return [
            'id' => 7,
            'booking_id' => 'MHB-EXACT-7',
            'room_id' => 10,
            'room_type_id' => 10,
            'assigned_room_id' => 21,
            'rate_plan_id' => 30,
            'guest_id' => 40,
            'checkin' => '2026-08-01',
            'checkout' => '2026-08-03',
            'guests' => 2,
            'status' => 'pending_payment',
            'provider' => 'clock',
            'total_price' => 100.0,
            'payment_status' => 'pending',
            'provider_metadata' => exact_room_metadata(),
        ];
    }

    function legacy_snapshot_hash(array $row, string $currency): string
    {
        $snapshot = [[
            (int) $row['id'],
            (int) $row['room_id'],
            (int) $row['rate_plan_id'],
            sanitize_key((string) $row['provider']),
            (string) $row['checkin'],
            (string) $row['checkout'],
            (int) \round((float) $row['total_price'] * 100),
        ]];
        unset($currency);
        \sort($snapshot);
        return \hash('sha256', (string) \json_encode($snapshot));
    }

    FakeExactRoomRuntime::$reservations = new FakeExactRoomReservationRepository();
    FakeExactRoomRuntime::$payments = new FakeExactRoomPaymentRepository();
    FakeExactRoomRuntime::$reservations->rows = [exact_room_row()];
    $integrity = new PaymentAttemptIntegrity(new PaymentEnvironmentCompatibilityPolicy());
    $prepared = $integrity->prepare('pokpay', [7], 100.0, 'EUR', 'sdk_confirm_url_redirect');
    $failures = [];

    if (empty($prepared['allowed'])) {
        $failures[] = 'A complete exact-room reservation must produce a payment attempt.';
    }
    $baseHash = (string) ($prepared['attempt']['attempt_booking_snapshot_hash'] ?? '');
    if ($baseHash === '' || $baseHash === legacy_snapshot_hash(exact_room_row(), 'EUR')) {
        $failures[] = 'Exact-room payment evidence must use a new versioned snapshot contract.';
    }

    $mutations = [
        'assigned physical room' => static function (array $row): array { $row['assigned_room_id'] = 22; return $row; },
        'room type' => static function (array $row): array { $row['room_type_id'] = 11; return $row; },
        'check-in' => static function (array $row): array { $row['checkin'] = '2026-08-02'; return $row; },
        'checkout' => static function (array $row): array { $row['checkout'] = '2026-08-04'; return $row; },
        'guest count' => static function (array $row): array { $row['guests'] = 1; return $row; },
        'rate plan' => static function (array $row): array { $row['rate_plan_id'] = 31; return $row; },
        'amount' => static function (array $row): array { $row['total_price'] = 101.0; return $row; },
        'physical mapping' => static function (array $row): array { $row['provider_metadata'] = exact_room_metadata('502'); return $row; },
        'room-type mapping' => static function (array $row): array { $row['provider_metadata'] = exact_room_metadata('501', '1002'); return $row; },
        'rate mapping' => static function (array $row): array { $row['provider_metadata'] = exact_room_metadata('501', '1001', '901'); return $row; },
    ];
    foreach ($mutations as $label => $mutate) {
        $mutated = $mutate(exact_room_row());
        FakeExactRoomRuntime::$reservations->rows = [$mutated];
        $amount = (float) $mutated['total_price'];
        $candidate = $integrity->prepare('pokpay', [7], $amount, 'EUR', 'sdk_confirm_url_redirect');
        if (empty($candidate['allowed']) || (string) ($candidate['attempt']['attempt_booking_snapshot_hash'] ?? '') === $baseHash) {
            $failures[] = 'The snapshot must change with ' . $label . '.';
        }
    }

    $reordered = exact_room_row();
    $reordered['provider_metadata'] = exact_room_metadata('501', '1001', '900', true);
    FakeExactRoomRuntime::$reservations->rows = [$reordered];
    $reorderedAttempt = $integrity->prepare('pokpay', [7], 100.0, 'EUR', 'sdk_confirm_url_redirect');
    if (empty($reorderedAttempt['allowed']) || (string) ($reorderedAttempt['attempt']['attempt_booking_snapshot_hash'] ?? '') !== $baseHash) {
        $failures[] = 'Snapshot identity must ignore metadata key order and mutable diagnostics.';
    }

    foreach (['physical_mapping', 'room_mapping', 'rate_plan_mapping'] as $missingKey) {
        $missing = exact_room_row();
        $metadata = (array) \json_decode((string) $missing['provider_metadata'], true);
        unset($metadata[$missingKey]);
        $missing['provider_metadata'] = (string) \json_encode($metadata);
        FakeExactRoomRuntime::$reservations->rows = [$missing];
        $denied = $integrity->prepare('pokpay', [7], 100.0, 'EUR', 'sdk_confirm_url_redirect');
        if (!empty($denied['allowed'])) {
            $failures[] = 'Clock attempt creation must reject missing ' . $missingKey . ' evidence.';
        }
    }

    FakeExactRoomRuntime::$reservations->rows = [exact_room_row()];
    $prepared = $integrity->prepare('pokpay', [7], 100.0, 'EUR', 'sdk_confirm_url_redirect');
    $attempt = (array) ($prepared['attempt'] ?? []);
    $attempt['attempt_expires_at'] = \gmdate('Y-m-d H:i:s', \time() + 10800);
    $attempt['provider_attempt_reference'] = 'pokpay-order-7';
    $paymentRow = $attempt + [
        'id' => 91,
        'reservation_id' => 7,
        'method' => 'pokpay',
        'status' => 'pending',
        'amount' => 100.0,
        'currency' => 'EUR',
    ];
    $paymentRow['attempt_allocation_set_hash'] = $integrity->allocationHash([$paymentRow]);
    FakeExactRoomRuntime::$payments->rows = [$paymentRow];
    $reusable = $integrity->validateReusable(
        ['session_id' => 'pokpay-order-7', 'reservation_ids' => [7]],
        'pokpay',
        100.0,
        'EUR',
        'website_online_pokpay'
    );
    if (empty($reusable['allowed'])) {
        $failures[] = 'Unchanged exact-room evidence must remain reusable: ' . (string) \json_encode($reusable);
    }

    $verified = [
        'provider_attempt_reference' => 'pokpay-order-7',
        'provider_mode' => 'staging',
        'provider_account_fingerprint' => 'gateway-account',
        'total_amount_minor' => 10000,
        'currency' => 'EUR',
    ];
    $finalizable = $integrity->validateFinalization('pokpay', [7], $verified);
    if (empty($finalizable['allowed'])) {
        $failures[] = 'Unchanged exact-room evidence must remain finalizable: ' . (string) \json_encode($finalizable);
    }

    $currencyMismatch = $integrity->validateReusable(
        ['session_id' => 'pokpay-order-7', 'reservation_ids' => [7]],
        'pokpay',
        100.0,
        'USD',
        'website_online_pokpay'
    );
    if (!empty($currencyMismatch['allowed'])) {
        $failures[] = 'Currency must remain bound by the dedicated attempt field.';
    }

    FakeExactRoomRuntime::$payments->rows = [$paymentRow];
    PaymentEnvironmentCompatibilityPolicy::$siteEnvironment = 'production';
    if (!empty($integrity->validateFinalization('pokpay', [7], $verified)['allowed'])) {
        $failures[] = 'Environment must remain bound by the dedicated compatibility policy.';
    }
    PaymentEnvironmentCompatibilityPolicy::$siteEnvironment = 'staging';

    $legacyRow = $paymentRow;
    $legacyRow['attempt_booking_snapshot_hash'] = legacy_snapshot_hash(exact_room_row(), 'EUR');
    $legacyRow['attempt_status'] = 'pending';
    FakeExactRoomRuntime::$payments->rows = [$legacyRow];
    $legacyReuse = $integrity->validateReusable(
        ['session_id' => 'pokpay-order-7', 'reservation_ids' => [7]],
        'pokpay',
        100.0,
        'EUR',
        'website_online_pokpay'
    );
    if (!empty($legacyReuse['allowed']) || (string) (FakeExactRoomRuntime::$payments->rows[0]['attempt_status'] ?? '') !== 'superseded') {
        $failures[] = 'A legacy unpaid snapshot must be rejected and superseded.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Payment-attempt exact-room snapshot tests passed.\n";
}
