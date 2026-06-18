<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        http_response_code(403);
        echo 'CLI only.' . PHP_EOL;
        exit(1);
    }

    function sanitize_key($key): string
    {
        return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
    }

    function sanitize_text_field($value): string
    {
        return \trim((string) $value);
    }

    function wp_json_encode($value, $flags = 0, $depth = 512)
    {
        return \json_encode($value, $flags, $depth);
    }

    function __($text, $domain = null): string
    {
        unset($domain);
        return (string) $text;
    }

    function current_time($type): string
    {
        return $type === 'mysql' ? '2026-06-13 12:00:00' : '2026-06-13';
    }

    function do_action(string $hook, ...$args): void
    {
        $GLOBALS['mhb_test_actions'][$hook][] = $args;
    }
}

namespace MustHotelBooking\Core {
    final class MustBookingConfig
    {
        public static function get_currency(): string
        {
            return 'EUR';
        }

        public static function get_timezone(): string
        {
            return 'UTC';
        }

        public static function get_checkin_time(): string
        {
            return '14:00';
        }
    }
}

namespace MustHotelBooking\Provider {
    final class ProviderReservationView
    {
        public static function isProviderBacked(array $reservation): bool
        {
            return \sanitize_key((string) ($reservation['provider'] ?? '')) === 'clock';
        }
    }
}

namespace MustHotelBooking\Engine {
    final class PaymentEngine
    {
        public static function normalizeMethod(string $method): string
        {
            return \sanitize_key($method);
        }
    }

    final class FakeReservationRepository
    {
        /** @var array<int, array<string, mixed>> */
        public $rows = [];

        /** @var array<int, array<string, mixed>> */
        public $metadataUpdates = [];

        /** @param array<int, array<string, mixed>> $rows */
        public function __construct(array $rows)
        {
            $this->rows = $rows;
        }

        /** @return array<string, mixed>|null */
        public function getReservation(int $reservationId): ?array
        {
            return $this->rows[$reservationId] ?? null;
        }

        /** @param array<int, int> $reservationIds @return array<int, array<string, mixed>> */
        public function getReservationsByIds(array $reservationIds): array
        {
            $found = [];

            foreach ($reservationIds as $reservationId) {
                if (isset($this->rows[(int) $reservationId])) {
                    $found[] = $this->rows[(int) $reservationId];
                }
            }

            return $found;
        }

        public function updateReservationStatus(int $reservationId, string $status, string $paymentStatus = ''): bool
        {
            if (!isset($this->rows[$reservationId])) {
                return false;
            }

            $this->rows[$reservationId]['status'] = $status;

            if ($paymentStatus !== '') {
                $this->rows[$reservationId]['payment_status'] = $paymentStatus;
            }

            return true;
        }

        /** @param array<string, mixed> $metadata */
        public function updateProviderMetadata(int $reservationId, array $metadata): bool
        {
            $this->metadataUpdates[$reservationId][] = $metadata;

            if (isset($metadata['provider_metadata'])) {
                $this->rows[$reservationId]['provider_metadata'] = \wp_json_encode($metadata['provider_metadata']);
            }

            return true;
        }
    }

    final class FakePaymentRepository
    {
        /** @var array<int, array<int, array<string, mixed>>> */
        private $paymentsByReservation;

        /** @param array<int, array<int, array<string, mixed>>> $paymentsByReservation */
        public function __construct(array $paymentsByReservation)
        {
            $this->paymentsByReservation = $paymentsByReservation;
        }

        /** @return array<int, array<string, mixed>> */
        public function getPaymentsForReservation(int $reservationId): array
        {
            return $this->paymentsByReservation[$reservationId] ?? [];
        }
    }

    final class FakeRefundRepository
    {
        /** @var array<int, array<string, mixed>> */
        public $refunds = [];

        /** @param array<string, mixed> $data */
        public function createRefund(array $data): int
        {
            $data['id'] = \count($this->refunds) + 1;
            $this->refunds[] = $data;

            return (int) $data['id'];
        }

        /** @return array<int, array<string, mixed>> */
        public function getRefundsForReservation(int $reservationId): array
        {
            return \array_values(\array_filter(
                $this->refunds,
                static function (array $row) use ($reservationId): bool {
                    return (int) ($row['reservation_id'] ?? 0) === $reservationId;
                }
            ));
        }
    }

    $GLOBALS['mhb_test_reservations'] = null;
    $GLOBALS['mhb_test_payments'] = null;
    $GLOBALS['mhb_test_refunds'] = null;

    function get_reservation_repository()
    {
        return $GLOBALS['mhb_test_reservations'];
    }

    function get_payment_repository()
    {
        return $GLOBALS['mhb_test_payments'];
    }

    function get_refund_repository()
    {
        return $GLOBALS['mhb_test_refunds'];
    }
}

namespace {
    require __DIR__ . '/../src/Core/ReservationStatus.php';
    require __DIR__ . '/../src/Engine/BookingStatusEngine.php';
    require __DIR__ . '/../src/Engine/PaymentStatusService.php';
    require __DIR__ . '/../src/Engine/PaymentProviderFeeService.php';
    require __DIR__ . '/../src/Engine/CancellationEngine.php';
    require __DIR__ . '/../src/Engine/CancellationFinancialCleanupService.php';
    require __DIR__ . '/../src/Engine/BookingLifecycleSyncService.php';

    $failures = [];

    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $GLOBALS['mhb_test_actions'] = [];
    $GLOBALS['mhb_test_reservations'] = new \MustHotelBooking\Engine\FakeReservationRepository([
        1 => [
            'id' => 1,
            'booking_id' => 'TEST-CLOCK-PAID',
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'total_price' => 100.00,
            'rate_plan_id' => 0,
            'provider' => 'clock',
            'provider_booking_id' => 'clock-booking-paid',
            'provider_reservation_id' => 'clock-booking-paid',
            'provider_metadata' => '',
        ],
        2 => [
            'id' => 2,
            'booking_id' => 'TEST-CLOCK-SNAPSHOT',
            'status' => 'cancelled',
            'payment_status' => 'paid',
            'total_price' => 100.00,
            'rate_plan_id' => 0,
            'provider' => 'clock',
            'provider_booking_id' => 'clock-booking-snapshot',
            'provider_reservation_id' => 'clock-booking-snapshot',
            'provider_metadata' => \wp_json_encode([
                'cancellation_financial_cleanup' => [
                    'reservation_cancellation_status' => 'cancelled',
                    'snapshot' => [
                        'paid_amount' => 100.00,
                        'payment_method' => 'stripe',
                        'provider_fee_retained' => 3.00,
                        'provider_fee_status' => 'known',
                        'cancellation_fee_amount' => 0.00,
                        'refundable_amount' => 97.00,
                        'currency' => 'EUR',
                        'refund_policy_reason' => 'Stored cancellation snapshot.',
                        'calculated_by' => 'clock_webhook',
                        'cancellation_policy' => [
                            'success' => true,
                            'penalty_amount' => 0.0,
                            'penalty_applied' => false,
                        ],
                    ],
                ],
            ]),
        ],
    ]);
    $GLOBALS['mhb_test_payments'] = new \MustHotelBooking\Engine\FakePaymentRepository([
        1 => [
            [
                'id' => 10,
                'reservation_id' => 1,
                'amount' => 100.00,
                'currency' => 'EUR',
                'method' => 'stripe',
                'status' => 'paid',
                'transaction_id' => 'pi_test_paid',
                'provider_fee_status' => 'known',
                'provider_fee_amount' => 3.00,
                'paid_at' => '2026-06-13 11:00:00',
                'created_at' => '2026-06-13 11:00:00',
            ],
        ],
        2 => [
            [
                'id' => 11,
                'reservation_id' => 2,
                'amount' => 100.00,
                'currency' => 'EUR',
                'method' => 'stripe',
                'status' => 'paid',
                'transaction_id' => 'pi_snapshot_paid',
                'provider_fee_status' => 'unknown',
                'provider_fee_amount' => 0.00,
                'paid_at' => '2026-06-13 11:30:00',
                'created_at' => '2026-06-13 11:30:00',
            ],
        ],
    ]);
    $GLOBALS['mhb_test_refunds'] = new \MustHotelBooking\Engine\FakeRefundRepository();

    $service = new \MustHotelBooking\Engine\BookingLifecycleSyncService();
    $first = $service->applyReservationStatusTransition(1, 'cancelled', 'paid', [
        'source' => 'clock_webhook',
        'operation' => 'cancel_only',
        'event_id' => 'evt_1',
        'idempotency_key' => 'clock-inbound-evt_1',
    ]);
    $second = $service->applyReservationStatusTransition(1, 'cancelled', 'paid', [
        'source' => 'clock_refresh',
        'operation' => 'cancel_only',
        'event_id' => 'evt_1',
        'idempotency_key' => 'clock-inbound-evt_1',
    ]);
    $snapshotReplay = $service->applyReservationStatusTransition(2, 'cancelled', 'paid', [
        'source' => 'clock_refresh',
        'operation' => 'cancel_only',
        'event_id' => 'evt_2',
        'idempotency_key' => 'clock-inbound-evt_2',
    ]);

    $cancelActions = $GLOBALS['mhb_test_actions']['must_hotel_booking/reservation_cancelled'] ?? [];
    $refunds = $GLOBALS['mhb_test_refunds']->getRefundsForReservation(1);
    $snapshotRefunds = $GLOBALS['mhb_test_refunds']->getRefundsForReservation(2);
    $reservation = $GLOBALS['mhb_test_reservations']->getReservation(1);
    $metadata = \json_decode((string) ($reservation['provider_metadata'] ?? ''), true);
    $cleanup = \is_array($metadata) && isset($metadata['cancellation_financial_cleanup']) && \is_array($metadata['cancellation_financial_cleanup'])
        ? $metadata['cancellation_financial_cleanup']
        : [];
    $snapshot = isset($cleanup['snapshot']) && \is_array($cleanup['snapshot']) ? $cleanup['snapshot'] : [];

    $assert(!empty($first['success']) && !empty($first['changed']), 'First Clock cancellation should change local state.');
    $assert(!empty($second['success']) && empty($second['changed']), 'Duplicate Clock cancellation should be idempotent.');
    $assert(!empty($snapshotReplay['success']) && empty($snapshotReplay['changed']), 'Snapshot replay on an already cancelled reservation should stay idempotent.');
    $assert(\count($cancelActions) === 1, 'Cancellation action should fire exactly once.');
    $assert(\count($refunds) === 1, 'Paid Clock cancellation should create one refund review record.');
    $assert(\count($snapshotRefunds) === 1, 'Stored cancellation snapshot should still create one review row when none exists yet.');
    $assert((string) ($refunds[0]['status'] ?? '') === 'refund_review_required', 'Refund review status should be durable.');
    $assert((string) ($snapshotRefunds[0]['status'] ?? '') === 'refund_review_required', 'Snapshot replay refund review status should be durable.');
    $assert(\abs((float) ($refunds[0]['amount'] ?? 0.0) - 97.00) < 0.01, 'Refund review should preserve calculated refundable amount when fee is known.');
    $assert(\abs((float) ($snapshotRefunds[0]['amount'] ?? 0.0) - 97.00) < 0.01, 'Stored cancellation snapshot must win over later fee recomputation when creating the review row.');
    $assert((string) ($cleanup['reservation_cancellation_status'] ?? '') === 'cancelled', 'Cancellation financial state should record the reservation cancellation separately.');
    $assert((string) ($cleanup['clock_charge_cleanup_status'] ?? '') === 'manual_clock_charge_cleanup_required', 'Clock accommodation charge cleanup should remain an explicit manual-review state.');
    $assert(\abs((float) ($snapshot['paid_amount'] ?? 0.0) - 100.00) < 0.01, 'Cancellation snapshot should preserve the original paid amount.');
    $assert(\abs((float) ($snapshot['refundable_amount'] ?? 0.0) - 97.00) < 0.01, 'Cancellation snapshot should preserve the calculated refundable amount.');

    if (!empty($failures)) {
        echo "FAIL\n";
        foreach ($failures as $failure) {
            echo '- ' . $failure . "\n";
        }
        exit(1);
    }

    echo "PASS\n";
}
