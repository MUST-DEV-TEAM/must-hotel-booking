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

    function __($text, $domain = null): string
    {
        unset($domain);
        return (string) $text;
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
}

namespace {
    require __DIR__ . '/../src/Engine/PaymentStatusService.php';

    $failures = [];
    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $partialRefundState = \MustHotelBooking\Engine\PaymentStatusService::buildReservationPaymentState(
        [
            'id' => 1,
            'status' => 'cancelled',
            'payment_status' => 'paid',
            'total_price' => 100.0,
            'provider_metadata' => \json_encode([
                'cancellation_financial_cleanup' => [
                    'reservation_cancellation_status' => 'cancelled',
                    'expected_clock_result' => [
                        'expected_retained_amount' => 20.0,
                    ],
                    'snapshot' => [
                        'paid_amount' => 100.0,
                        'refundable_amount' => 80.0,
                        'non_refundable_amount' => 20.0,
                    ],
                ],
            ]),
        ],
        [
            [
                'amount' => 80.0,
                'method' => 'stripe',
                'status' => 'refunded',
                'transaction_id' => 're_partial',
                'paid_at' => '2026-06-18 10:00:00',
                'created_at' => '2026-06-18 10:00:00',
            ],
            [
                'amount' => 100.0,
                'method' => 'stripe',
                'status' => 'paid',
                'transaction_id' => 'pi_partial',
                'paid_at' => '2026-06-18 09:00:00',
                'created_at' => '2026-06-18 09:00:00',
            ],
        ]
    );

    $fullRefundState = \MustHotelBooking\Engine\PaymentStatusService::buildReservationPaymentState(
        [
            'id' => 2,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'total_price' => 100.0,
            'provider_metadata' => \json_encode([
                'cancellation_financial_cleanup' => [
                    'reservation_cancellation_status' => 'cancelled',
                    'expected_clock_result' => [
                        'expected_retained_amount' => 0.0,
                    ],
                    'snapshot' => [
                        'paid_amount' => 100.0,
                        'refundable_amount' => 100.0,
                        'non_refundable_amount' => 0.0,
                    ],
                ],
            ]),
        ],
        [
            [
                'amount' => 100.0,
                'method' => 'stripe',
                'status' => 'refunded',
                'transaction_id' => 're_full',
                'paid_at' => '2026-06-18 10:30:00',
                'created_at' => '2026-06-18 10:30:00',
            ],
            [
                'amount' => 100.0,
                'method' => 'stripe',
                'status' => 'paid',
                'transaction_id' => 'pi_full',
                'paid_at' => '2026-06-18 09:30:00',
                'created_at' => '2026-06-18 09:30:00',
            ],
        ]
    );

    $assert((string) ($partialRefundState['derived_status'] ?? '') === 'paid', 'Retained cancellation fee should keep the cancelled reservation paid, not partially paid.');
    $assert(\abs((float) ($partialRefundState['amount_due'] ?? -1.0)) < 0.01, 'Retained cancellation fee should zero the cancelled reservation amount due.');
    $assert(\abs((float) ($partialRefundState['effective_total'] ?? -1.0) - 20.0) < 0.01, 'Effective total should switch to the retained cancellation amount.');

    $assert((string) ($fullRefundState['derived_status'] ?? '') === 'refunded', 'Fully refunded cancelled reservation should remain refunded.');
    $assert(\abs((float) ($fullRefundState['amount_due'] ?? -1.0)) < 0.01, 'Fully refunded cancelled reservation should have no amount due.');

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Cancellation payment state tests passed.\n";
}
