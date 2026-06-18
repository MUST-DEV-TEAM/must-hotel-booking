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

    function __($text, $domain = null): string
    {
        unset($domain);
        return (string) $text;
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

namespace MustHotelBooking\Database {
    class PaymentRepository
    {
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

    final class RefundSafetyReservationRepository
    {
        public function getReservation(int $reservationId): ?array
        {
            if ($reservationId === 1) {
                return [
                    'id' => 1,
                    'booking_id' => 'TEST-REFUND-SAFETY',
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'total_price' => 100.0,
                    'provider' => 'local',
                    'provider_metadata' => '',
                ];
            }

            if ($reservationId === 2) {
                return [
                    'id' => 2,
                    'booking_id' => 'TEST-POKPAY-REFUND-SAFETY',
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'total_price' => 80.0,
                    'provider' => 'local',
                    'provider_metadata' => '',
                ];
            }

            return null;
        }
    }

    final class RefundSafetyPaymentRepository extends \MustHotelBooking\Database\PaymentRepository
    {
        public function getPaymentsForReservation(int $reservationId): array
        {
            if ($reservationId === 1) {
                return [[
                    'id' => 5,
                    'reservation_id' => 1,
                    'amount' => 100.0,
                    'currency' => 'EUR',
                    'method' => 'stripe',
                    'status' => 'paid',
                    'transaction_id' => 'pi_refund_safety',
                    'provider_fee_status' => 'known',
                    'provider_fee_amount' => 3.0,
                    'paid_at' => '2026-06-18 10:00:00',
                    'created_at' => '2026-06-18 10:00:00',
                ]];
            }

            if ($reservationId === 2) {
                return [[
                    'id' => 6,
                    'reservation_id' => 2,
                    'amount' => 80.0,
                    'currency' => 'EUR',
                    'method' => 'pokpay',
                    'status' => 'paid',
                    'transaction_id' => 'pok_refund_safety',
                    'provider_fee_status' => 'known',
                    'provider_fee_amount' => 2.5,
                    'paid_at' => '2026-06-18 10:05:00',
                    'created_at' => '2026-06-18 10:05:00',
                ]];
            }

            return [];
        }
    }

    final class RefundSafetyRefundRepository
    {
        public bool $duplicate = false;

        public function findBlockingProviderRefund(int $reservationId, string $gateway, string $reference, float $amount): ?array
        {
            unset($reservationId, $gateway, $reference, $amount);
            return $this->duplicate ? ['id' => 9, 'status' => 'processing'] : null;
        }

        public function findRetryableProviderRefund(int $reservationId, string $gateway, string $reference, float $amount): ?array
        {
            unset($reservationId, $gateway, $reference, $amount);
            return null;
        }
    }

    $GLOBALS['refund_safety_reservations'] = new RefundSafetyReservationRepository();
    $GLOBALS['refund_safety_payments'] = new RefundSafetyPaymentRepository();
    $GLOBALS['refund_safety_refunds'] = new RefundSafetyRefundRepository();

    function get_reservation_repository()
    {
        return $GLOBALS['refund_safety_reservations'];
    }

    function get_payment_repository()
    {
        return $GLOBALS['refund_safety_payments'];
    }

    function get_refund_repository()
    {
        return $GLOBALS['refund_safety_refunds'];
    }
}

namespace {
    require __DIR__ . '/../src/Engine/PaymentStatusService.php';
    require __DIR__ . '/../src/Engine/PaymentProviderFeeService.php';
    require __DIR__ . '/../src/Engine/PaymentRefundService.php';

    $service = new \MustHotelBooking\Engine\PaymentRefundService();
    $failures = [];

    $currencyMismatch = $service->requestRefund(1, 50.0, ['currency' => 'USD']);
    if (!empty($currencyMismatch['success']) || \strpos((string) ($currencyMismatch['message'] ?? ''), 'currency') === false) {
        $failures[] = 'Currency mismatch must be rejected before any gateway write.';
    }

    $GLOBALS['refund_safety_refunds']->duplicate = true;
    $duplicate = $service->requestRefund(1, 50.0, ['currency' => 'EUR']);
    if (!empty($duplicate['success']) || (int) ($duplicate['refund_id'] ?? 0) !== 9) {
        $failures[] = 'Duplicate refund request must return the existing blocking refund.';
    }

    $pokpayDuplicate = $service->requestRefund(2, 40.0, ['currency' => 'EUR']);
    if (!empty($pokpayDuplicate['success']) || (int) ($pokpayDuplicate['refund_id'] ?? 0) !== 9) {
        $failures[] = 'Duplicate PokPay refund request must return the existing blocking refund.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Payment refund safety tests passed.\n";
}
