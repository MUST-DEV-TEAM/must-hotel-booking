<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\ReservationStatus;

final class ReservationConfirmationPolicy
{
    /** @param array<string, mixed> $reservation @param array<int, array<string, mixed>> $payments @param array<int, array<string, mixed>> $evidence @param array<string, mixed> $context */
    public function evaluate(array $reservation, string $targetStatus, string $targetPaymentStatus, array $payments, array $evidence, array $context): array
    {
        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
        $targetStatus = \sanitize_key($targetStatus);
        $flow = \sanitize_key((string) ($reservation['confirmation_flow'] ?? 'legacy')) ?: 'legacy';
        $command = \sanitize_key((string) ($context['command'] ?? ''));
        $source = \sanitize_key((string) ($context['source'] ?? ''));

        if (!ReservationStatus::isConfirmed($targetStatus)) {
            return $this->deny($flow, $source, 'confirmation_target_invalid');
        }
        if (ReservationStatus::isConfirmed($currentStatus)) {
            return ['allowed' => true, 'replay' => true, 'reason_code' => 'not_first_confirmation', 'flow' => $flow, 'source' => $source];
        }
        if (\in_array($currentStatus, ['cancelled', 'expired', 'payment_failed', 'blocked'], true)) {
            return $this->deny($flow, $source, 'non_reconfirmable_reservation_state');
        }
        if (\in_array($flow, ['website_online_stripe', 'website_online_pokpay'], true)) {
            if ($command !== 'gateway_verification' || $source === '') {
                return $this->deny($flow, $source, 'online_confirmation_owner_invalid');
            }
            $provider = $flow === 'website_online_stripe' ? 'stripe' : 'pokpay';
            $expectedMode = \sanitize_key((string) ($context['provider_mode'] ?? ''));
            $expectedAccount = \strtolower(\trim((string) ($context['provider_account_fingerprint'] ?? '')));
            $expectedTransaction = \trim((string) ($context['provider_transaction_reference'] ?? ''));
            $expectedAttempt = \trim((string) ($context['provider_attempt_reference'] ?? ''));
            if ($expectedMode === '' || $expectedAccount === '' || $expectedTransaction === '' || $expectedAttempt === '') {
                return $this->deny($flow, $source, 'provider_identity_binding_missing');
            }
            foreach ($evidence as $row) {
                $paymentId = (int) ($row['payment_id'] ?? 0);
                foreach ($payments as $payment) {
                    if (
                        (int) ($payment['id'] ?? 0) === $paymentId
                        && \sanitize_key((string) ($payment['method'] ?? '')) === $provider
                        && \sanitize_key((string) ($payment['status'] ?? '')) === 'paid'
                        && \sanitize_key((string) ($row['provider_mode'] ?? '')) === $expectedMode
                        && \hash_equals($expectedAccount, \strtolower((string) ($row['provider_account_fingerprint'] ?? '')))
                        && \hash_equals($expectedTransaction, (string) ($row['provider_transaction_reference'] ?? ''))
                        && \hash_equals($expectedAttempt, (string) ($row['provider_attempt_reference'] ?? ''))
                        && \hash_equals((string) ($payment['transaction_id'] ?? ''), (string) ($row['provider_transaction_reference'] ?? ''))
                        && CurrencyMinorUnits::toMinor((float) ($payment['amount'] ?? 0.0), (string) ($payment['currency'] ?? '')) === (int) ($row['amount_minor'] ?? -1)
                        && \strtoupper((string) ($payment['currency'] ?? '')) === \strtoupper((string) ($row['currency'] ?? ''))
                    ) {
                        return [
                            'allowed' => true,
                            'replay' => false,
                            'reason_code' => 'server_verified_payment',
                            'flow' => $flow,
                            'source' => $source,
                            'verification_group_id' => (int) ($row['verification_group_id'] ?? 0),
                            'payment_id' => $paymentId,
                            'claim_hash' => (string) ($row['claim_hash'] ?? ''),
                            'allocation_set_hash' => (string) ($row['allocation_set_hash'] ?? ''),
                        ];
                    }
                }
            }
            return $this->deny($flow, $source, 'server_verified_payment_missing_or_mismatched');
        }
        if (\in_array($flow, ['website_offline_pay_at_hotel', 'staff_offline'], true)) {
            if ($command !== 'offline_confirmation'
                || !\in_array($source, ['public_checkout', 'admin', 'staff_portal'], true)
                || ($flow === 'staff_offline' && $source === 'public_checkout')) {
                return $this->deny($flow, $source, 'offline_confirmation_owner_invalid');
            }
            $payAtHotelPaymentId = 0;
            foreach ($payments as $payment) {
                $method = \sanitize_key((string) ($payment['method'] ?? ''));
                if (\in_array($method, ['stripe', 'pokpay'], true)) {
                    return $this->deny($flow, $source, 'offline_confirmation_online_attempt_exists');
                }
                if ($method === 'pay_at_hotel') {
                    $payAtHotelPaymentId = (int) ($payment['id'] ?? 0);
                }
            }
            if ($payAtHotelPaymentId > 0) {
                return ['allowed' => true, 'replay' => false, 'reason_code' => 'explicit_offline_payment', 'flow' => $flow, 'source' => $source, 'payment_id' => $payAtHotelPaymentId];
            }
            return $this->deny($flow, $source, 'explicit_offline_payment_missing');
        }
        if ($flow === 'clock_import') {
            return $command === 'provider_sync'
                && \in_array($source, ['clock_webhook', 'clock_refresh', 'clock_sync'], true)
                && \sanitize_key((string) ($reservation['booking_source'] ?? '')) === 'clock_pms'
                ? ['allowed' => true, 'replay' => false, 'reason_code' => 'provider_sync', 'flow' => $flow, 'source' => $source]
                : $this->deny($flow, $source, 'provider_sync_confirmation_invalid');
        }
        if ($flow === 'administrative_recovery') {
            return $command === 'administrative_recovery'
                && \in_array($source, ['admin', 'staff_portal'], true)
                && !empty($context['authorized'])
                ? ['allowed' => true, 'replay' => false, 'reason_code' => 'authorized_administrative_recovery', 'flow' => $flow, 'source' => $source]
                : $this->deny($flow, $source, 'administrative_recovery_not_authorized');
        }
        return $this->deny($flow, $source, 'confirmation_flow_ambiguous');
    }

    private function deny(string $flow, string $source, string $reason): array
    {
        return ['allowed' => false, 'replay' => false, 'reason_code' => $reason, 'flow' => $flow, 'source' => $source];
    }
}
