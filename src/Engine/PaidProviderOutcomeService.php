<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Database\ActivityRepository;
use MustHotelBooking\Database\PaidProviderObservationRepository;

/** Records provider-paid evidence without confirming or dispatching success hooks. */
final class PaidProviderOutcomeService
{
    private PaidProviderObservationRepository $repository;

    public function __construct(?PaidProviderObservationRepository $repository = null)
    {
        $this->repository = $repository ?: new PaidProviderObservationRepository();
    }

    /** @param array<int, int> $expectedReservationIds @param array<string, mixed> $facts @param array<int, int> $observedReservationIds */
    public function persist(string $provider, array $expectedReservationIds, array $facts, string $failureCode, array $observedReservationIds = [], string $recoveryStatus = 'manual_review'): array
    {
        $provider = PaymentEngine::normalizeMethod($provider);
        $transaction = \sanitize_text_field((string) ($facts['provider_transaction_reference'] ?? ''));
        $attempt = \sanitize_text_field((string) ($facts['provider_attempt_reference'] ?? ''));
        $mode = \sanitize_key((string) ($facts['provider_mode'] ?? ''));
        $account = \strtolower(\trim((string) ($facts['provider_account_fingerprint'] ?? '')));
        if (!\in_array($provider, ['stripe', 'pokpay'], true) || ($transaction === '' && $attempt === '')) {
            return ['success' => false, 'provider_paid' => true, 'reason_code' => 'paid_observation_identity_invalid'];
        }
        $expectedReservationIds = $this->ids($expectedReservationIds);
        $observedReservationIds = $this->ids($observedReservationIds);
        $currency = \strtoupper(\sanitize_text_field((string) ($facts['currency'] ?? '')));
        $amountMinor = isset($facts['total_amount_minor']) && \is_numeric($facts['total_amount_minor'])
            ? (int) $facts['total_amount_minor']
            : -1;
        $reference = $transaction !== '' ? $transaction : $attempt;
        $ownershipKey = \hash('sha256', \implode('|', [$provider, $mode, $account, $reference]));
        $expectedHash = !empty($expectedReservationIds) ? \hash('sha256', \implode(',', $expectedReservationIds)) : '';
        $now = \current_time('mysql');
        $result = $this->repository->record([
            'provider' => $provider,
            'provider_mode' => $mode,
            'provider_account_fingerprint' => $account,
            'provider_transaction_reference' => $transaction,
            'provider_attempt_reference' => $attempt,
            'provider_event_reference' => \sanitize_text_field((string) ($facts['provider_event_reference'] ?? '')),
            'verification_source' => \sanitize_key((string) ($facts['verification_source'] ?? '')),
            'observed_status' => 'paid',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'ownership_key' => $ownershipKey,
            'expected_allocation_set_hash' => $expectedHash,
            'rejected_context_hash' => \hash('sha256', (string) \wp_json_encode([
                'expected' => $expectedReservationIds,
                'observed' => $observedReservationIds,
                'failure_code' => \sanitize_key($failureCode),
            ])),
            'failure_code' => \sanitize_key($failureCode) ?: 'payment_completion_blocked',
            'recovery_status' => \in_array(\sanitize_key($recoveryStatus), ['processing_pending', 'manual_review', 'partial_manual_review'], true)
                ? \sanitize_key($recoveryStatus)
                : 'manual_review',
            'observed_at' => (string) ($facts['provider_completed_at'] ?? '') ?: $now,
            'last_seen_at' => $now,
            'observation_count' => 1,
            'created_at' => $now,
        ], $this->allocations($expectedReservationIds, $observedReservationIds, $amountMinor, $currency, $now, $ownershipKey));
        if (!empty($result['success']) && empty($result['idempotent'])) {
            foreach ($expectedReservationIds as $reservationId) {
                (new ActivityRepository())->createActivity([
                    'event_type' => 'paid_provider_observation',
                    'severity' => 'warning',
                    'entity_type' => 'reservation',
                    'entity_id' => $reservationId,
                    'reference' => 'payment-integrity',
                    'message' => \sanitize_key($failureCode) ?: 'payment_completion_blocked',
                    'context_json' => \wp_json_encode([
                        'provider' => $provider,
                        'provider_paid' => true,
                        'reason_code' => \sanitize_key($failureCode),
                        'observation_id' => (int) ($result['observation_id'] ?? 0),
                    ]),
                ]);
            }
        }
        return $result + ['provider_paid' => true, 'review_persisted' => !empty($result['success'])];
    }

    /** @return array<int, array<string, mixed>> */
    private function allocations(array $expected, array $observed, int $amountMinor, string $currency, string $now, string $ownershipKey): array
    {
        $rows = [];
        foreach (['expected' => $expected, 'observed' => $observed] as $role => $ids) {
            foreach ($ids as $reservationId) {
                $allocationAmount = -1;
                $reservation = get_reservation_repository()->getReservation($reservationId);
                if (\is_array($reservation) && $currency !== '') {
                    $allocationAmount = CurrencyMinorUnits::toMinor((float) ($reservation['total_price'] ?? 0.0), $currency);
                } elseif (\count($ids) === 1) {
                    $allocationAmount = $amountMinor;
                }
                $rows[] = [
                    'reservation_id' => $reservationId,
                    'allocation_role' => $role,
                    'amount_minor' => $allocationAmount,
                    'currency' => $currency,
                    'allocation_hash' => \hash('sha256', \implode('|', [$ownershipKey, $role, $reservationId, $allocationAmount, $currency])),
                    'created_at' => $now,
                ];
            }
        }
        return $rows;
    }

    /** @param array<int, int> $ids @return array<int, int> */
    private function ids(array $ids): array
    {
        $ids = \array_values(\array_unique(\array_filter(\array_map('intval', $ids))));
        \sort($ids);
        return $ids;
    }
}
