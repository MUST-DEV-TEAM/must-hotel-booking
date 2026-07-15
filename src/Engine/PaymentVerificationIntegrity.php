<?php

namespace MustHotelBooking\Engine;

final class PaymentVerificationIntegrity
{
    /** @param array<string, mixed> $providerPayment @param array<int, array<string, mixed>> $allocations */
    public function normalize(array $providerPayment, array $allocations): array
    {
        $provider = \sanitize_key((string) ($providerPayment['provider'] ?? ''));
        $mode = \sanitize_key((string) ($providerPayment['provider_mode'] ?? ''));
        $account = \strtolower(\trim((string) ($providerPayment['provider_account_fingerprint'] ?? '')));
        $transactionReference = \trim((string) ($providerPayment['provider_transaction_reference'] ?? ''));
        $attemptReference = \trim((string) ($providerPayment['provider_attempt_reference'] ?? ''));
        $currency = \strtoupper(\trim((string) ($providerPayment['currency'] ?? '')));
        $total = (int) ($providerPayment['total_amount_minor'] ?? -1);
        $normalized = [];

        foreach ($allocations as $allocation) {
            $reservationId = (int) ($allocation['reservation_id'] ?? 0);
            $paymentId = (int) ($allocation['payment_id'] ?? 0);
            $amount = (int) ($allocation['amount_minor'] ?? -1);
            $allocationCurrency = \strtoupper(\trim((string) ($allocation['currency'] ?? '')));
            if ($reservationId <= 0 || $paymentId <= 0 || $amount < 0 || $allocationCurrency !== $currency) {
                return ['valid' => false, 'reason_code' => 'invalid_payment_allocation'];
            }
            $normalized[] = [
                'reservation_id' => $reservationId,
                'payment_id' => $paymentId,
                'amount_minor' => $amount,
                'currency' => $allocationCurrency,
            ];
        }

        \usort($normalized, static function (array $left, array $right): int {
            return [$left['reservation_id'], $left['payment_id']] <=> [$right['reservation_id'], $right['payment_id']];
        });
        $allocated = (int) \array_sum(\array_column($normalized, 'amount_minor'));
        if ($provider === '' || $mode === '' || $account === '' || $transactionReference === '' || $attemptReference === '' || $currency === '' || $total < 0 || empty($normalized) || $allocated !== $total) {
            return ['valid' => false, 'reason_code' => 'provider_total_allocation_mismatch'];
        }

        $ownershipKey = \hash('sha256', \implode('|', [$provider, $mode, $account, $transactionReference]));
        $allocationSetHash = \hash('sha256', (string) \wp_json_encode($normalized));
        return [
            'valid' => true,
            'group' => [
                'provider' => $provider,
                'provider_mode' => $mode,
                'provider_account_fingerprint' => $account,
                'provider_transaction_reference' => $transactionReference,
                'provider_attempt_reference' => $attemptReference,
                'total_amount_minor' => $total,
                'currency' => $currency,
                'ownership_key' => $ownershipKey,
                'allocation_set_hash' => $allocationSetHash,
                'allocation_count' => \count($normalized),
            ],
            'allocations' => $normalized,
        ];
    }
}
