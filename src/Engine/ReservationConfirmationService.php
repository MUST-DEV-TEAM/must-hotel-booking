<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Database\ActivityRepository;
use MustHotelBooking\Database\PaymentVerificationRepository;

final class ReservationConfirmationService
{
    private $reservations;
    private $payments;
    private PaymentVerificationRepository $verifications;
    private ReservationConfirmationPolicy $policy;

    public function __construct($reservations = null, $payments = null, ?PaymentVerificationRepository $verifications = null, ?ReservationConfirmationPolicy $policy = null)
    {
        $this->reservations = $reservations ?: get_reservation_repository();
        $this->payments = $payments ?: get_payment_repository();
        $this->verifications = $verifications ?: new PaymentVerificationRepository();
        $this->policy = $policy ?: new ReservationConfirmationPolicy();
    }

    /** @param array<string, mixed> $context */
    public function confirm(int $reservationId, string $targetStatus = 'confirmed', string $targetPaymentStatus = '', array $context = []): array
    {
        $reservation = $this->reservations->getReservation($reservationId);
        if (!\is_array($reservation)) {
            return ['success' => false, 'changed' => false, 'reason_code' => 'reservation_not_found'];
        }
        $approvedFlow = \sanitize_key((string) ($context['approved_flow'] ?? ''));
        if (\sanitize_key((string) ($reservation['confirmation_flow'] ?? 'legacy')) === 'legacy' && $approvedFlow !== '') {
            if (!$this->classificationMatchesContext($approvedFlow, $context)
                || ($approvedFlow === 'staff_offline' && $this->hasOnlinePaymentAttempt($reservationId))) {
                $this->audit($reservationId, 'confirmation_blocked', 'warning', 'confirmation_flow_classification_invalid', [
                    'flow' => $approvedFlow,
                    'source' => \sanitize_key((string) ($context['source'] ?? '')),
                ]);
                return ['success' => false, 'changed' => false, 'reason_code' => 'confirmation_flow_classification_invalid'];
            }
            if (!$this->reservations->setConfirmationFlowForFirstConfirmation($reservationId, $approvedFlow)) {
                return ['success' => false, 'changed' => false, 'reason_code' => 'confirmation_flow_classification_failed'];
            }
            $reservation = $this->reservations->getReservation($reservationId);
            if (!\is_array($reservation)) {
                return ['success' => false, 'changed' => false, 'reason_code' => 'reservation_not_found'];
            }
        }
        $targetPaymentStatus = \sanitize_key($targetPaymentStatus) ?: \sanitize_key((string) ($reservation['payment_status'] ?? ''));
        $decision = $this->policy->evaluate(
            $reservation,
            $targetStatus,
            $targetPaymentStatus,
            $this->payments->getPaymentsForReservation($reservationId),
            $this->verifications->getForReservation($reservationId),
            $context
        );
        if (empty($decision['allowed'])) {
            $this->audit($reservationId, 'confirmation_blocked', 'warning', (string) ($decision['reason_code'] ?? 'confirmation_blocked'), $decision);
            return ['success' => false, 'changed' => false, 'reason_code' => (string) ($decision['reason_code'] ?? 'confirmation_blocked')];
        }
        if (!empty($decision['replay'])) {
            return ['success' => true, 'changed' => false, 'reason_code' => 'already_confirmed'];
        }
        $authorization = ReservationConfirmationAuthorization::issue($reservation, $decision, $targetStatus, $targetPaymentStatus);
        if (!$this->reservations->persistAuthorizedConfirmation($reservationId, $targetStatus, $targetPaymentStatus, $authorization)) {
            $latest = $this->reservations->getReservation($reservationId);
            if (\is_array($latest) && \MustHotelBooking\Core\ReservationStatus::isConfirmed((string) ($latest['status'] ?? ''))) {
                return ['success' => true, 'changed' => false, 'reason_code' => 'already_confirmed'];
            }
            $this->audit($reservationId, 'confirmation_blocked', 'error', 'authorized_persistence_failed', $decision);
            return ['success' => false, 'changed' => false, 'reason_code' => 'confirmation_persist_failed'];
        }
        $this->audit($reservationId, 'confirmation_authorized', 'info', (string) ($decision['reason_code'] ?? 'authorized'), $decision);
        if (empty($context['defer_event'])) {
            \do_action('must_hotel_booking/reservation_confirmed', $reservationId);
        }
        return ['success' => true, 'changed' => true, 'reason_code' => ''];
    }

    public function isNotificationEligible(int $reservationId): bool
    {
        $reservation = $this->reservations->getReservation($reservationId);
        if (!\is_array($reservation) || !\MustHotelBooking\Core\ReservationStatus::isConfirmed((string) ($reservation['status'] ?? ''))) {
            return false;
        }
        $flow = \sanitize_key((string) ($reservation['confirmation_flow'] ?? 'legacy'));
        if (\in_array($flow, ['website_online_stripe', 'website_online_pokpay'], true)) {
            $claimId = (int) ($reservation['confirmation_claim_id'] ?? 0);
            $provider = $flow === 'website_online_stripe' ? 'stripe' : 'pokpay';
            $payments = $this->payments->getPaymentsForReservation($reservationId);
            foreach ($this->verifications->getForReservation($reservationId) as $evidence) {
                if ((int) ($evidence['verification_group_id'] ?? 0) !== $claimId || $claimId <= 0 || \trim((string) ($evidence['claim_hash'] ?? '')) === '') {
                    continue;
                }
                foreach ($payments as $payment) {
                    if ((int) ($payment['id'] ?? 0) === (int) ($evidence['payment_id'] ?? 0)
                        && \sanitize_key((string) ($payment['method'] ?? '')) === $provider
                        && \sanitize_key((string) ($payment['status'] ?? '')) === 'paid'
                        && \hash_equals((string) ($evidence['provider_transaction_reference'] ?? ''), (string) ($payment['transaction_id'] ?? ''))
                        && CurrencyMinorUnits::toMinor((float) ($payment['amount'] ?? 0.0), (string) ($payment['currency'] ?? '')) === (int) ($evidence['amount_minor'] ?? -1)
                        && \strtoupper((string) ($payment['currency'] ?? '')) === \strtoupper((string) ($evidence['currency'] ?? ''))) {
                        return true;
                    }
                }
            }
            return false;
        }
        if (\trim((string) ($reservation['confirmation_source'] ?? '')) === '') {
            return false;
        }
        if (\in_array($flow, ['website_offline_pay_at_hotel', 'staff_offline'], true)) {
            $claimId = (int) ($reservation['confirmation_claim_id'] ?? 0);
            foreach ($this->payments->getPaymentsForReservation($reservationId) as $payment) {
                if ((int) ($payment['id'] ?? 0) === $claimId
                    && $claimId > 0
                    && \sanitize_key((string) ($payment['method'] ?? '')) === 'pay_at_hotel') {
                    return true;
                }
            }
            return false;
        }
        return \in_array($flow, ['clock_import', 'administrative_recovery'], true);
    }

    /** @param array<string, mixed> $context */
    private function classificationMatchesContext(string $flow, array $context): bool
    {
        $command = \sanitize_key((string) ($context['command'] ?? ''));
        $source = \sanitize_key((string) ($context['source'] ?? ''));

        if ($flow === 'staff_offline') {
            return $command === 'offline_confirmation' && \in_array($source, ['admin', 'staff_portal'], true);
        }
        if ($flow === 'clock_import') {
            return $command === 'provider_sync' && \in_array($source, ['clock_webhook', 'clock_refresh', 'clock_sync'], true);
        }
        if ($flow === 'administrative_recovery') {
            return $command === 'administrative_recovery'
                && \in_array($source, ['admin', 'staff_portal'], true)
                && !empty($context['authorized']);
        }
        return false;
    }

    private function hasOnlinePaymentAttempt(int $reservationId): bool
    {
        foreach ($this->payments->getPaymentsForReservation($reservationId) as $payment) {
            if (\in_array(\sanitize_key((string) ($payment['method'] ?? '')), ['stripe', 'pokpay'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $context */
    private function audit(int $reservationId, string $event, string $severity, string $reason, array $context): void
    {
        (new ActivityRepository())->createActivity([
            'event_type' => $event,
            'severity' => $severity,
            'entity_type' => 'reservation',
            'entity_id' => $reservationId,
            'reference' => 'confirmation-integrity',
            'message' => $reason,
            'context_json' => \wp_json_encode([
                'reservation_ids' => [$reservationId],
                'flow' => \sanitize_key((string) ($context['flow'] ?? '')),
                'source' => \sanitize_key((string) ($context['source'] ?? '')),
                'reason_code' => \sanitize_key($reason),
            ]),
        ]);
    }
}
