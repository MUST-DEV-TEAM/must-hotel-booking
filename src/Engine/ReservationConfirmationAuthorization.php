<?php

namespace MustHotelBooking\Engine;

/** Immutable, single-reservation authorization issued by the central policy. */
final class ReservationConfirmationAuthorization
{
    private int $reservationId;
    private string $snapshotHash;
    private string $targetStatus;
    private string $targetPaymentStatus;
    private string $flow;
    private string $source;
    private int $verificationGroupId;
    private int $paymentId;
    private string $claimHash;
    private string $allocationSetHash;

    /** @param array<string, mixed> $data */
    private function __construct(array $data)
    {
        $this->reservationId = (int) $data['reservation_id'];
        $this->snapshotHash = (string) $data['snapshot_hash'];
        $this->targetStatus = (string) $data['target_status'];
        $this->targetPaymentStatus = (string) $data['target_payment_status'];
        $this->flow = (string) $data['flow'];
        $this->source = (string) $data['source'];
        $this->verificationGroupId = (int) ($data['verification_group_id'] ?? 0);
        $this->paymentId = (int) ($data['payment_id'] ?? 0);
        $this->claimHash = (string) ($data['claim_hash'] ?? '');
        $this->allocationSetHash = (string) ($data['allocation_set_hash'] ?? '');
    }

    /** @param array<string, mixed> $reservation @param array<string, mixed> $decision */
    public static function issue(array $reservation, array $decision, string $targetStatus, string $targetPaymentStatus): self
    {
        return new self([
            'reservation_id' => (int) ($reservation['id'] ?? 0),
            'snapshot_hash' => self::snapshotHash($reservation),
            'target_status' => \sanitize_key($targetStatus),
            'target_payment_status' => \sanitize_key($targetPaymentStatus),
            'flow' => \sanitize_key((string) ($decision['flow'] ?? '')),
            'source' => \sanitize_key((string) ($decision['source'] ?? '')),
            'verification_group_id' => (int) ($decision['verification_group_id'] ?? 0),
            'payment_id' => (int) ($decision['payment_id'] ?? 0),
            'claim_hash' => (string) ($decision['claim_hash'] ?? ''),
            'allocation_set_hash' => (string) ($decision['allocation_set_hash'] ?? ''),
        ]);
    }

    public function matchesTarget(int $reservationId, string $status, string $paymentStatus): bool
    {
        return $this->reservationId === $reservationId
            && $this->targetStatus === \sanitize_key($status)
            && $this->targetPaymentStatus === \sanitize_key($paymentStatus);
    }

    /** @param array<string, mixed> $reservation */
    public function matchesReservation(array $reservation): bool
    {
        return $this->reservationId === (int) ($reservation['id'] ?? 0)
            && \hash_equals($this->snapshotHash, self::snapshotHash($reservation));
    }

    public function reservationId(): int { return $this->reservationId; }
    public function flow(): string { return $this->flow; }
    public function source(): string { return $this->source; }
    public function verificationGroupId(): int { return $this->verificationGroupId; }
    public function paymentId(): int { return $this->paymentId; }
    public function claimHash(): string { return $this->claimHash; }
    public function allocationSetHash(): string { return $this->allocationSetHash; }
    public function isOnline(): bool { return \in_array($this->flow, ['website_online_stripe', 'website_online_pokpay'], true); }
    public function isOffline(): bool { return \in_array($this->flow, ['website_offline_pay_at_hotel', 'staff_offline'], true); }

    /** @param array<string, mixed> $reservation */
    private static function snapshotHash(array $reservation): string
    {
        return \hash('sha256', (string) \wp_json_encode([
            'id' => (int) ($reservation['id'] ?? 0),
            'status' => \sanitize_key((string) ($reservation['status'] ?? '')),
            'payment_status' => \sanitize_key((string) ($reservation['payment_status'] ?? '')),
            'confirmation_flow' => \sanitize_key((string) ($reservation['confirmation_flow'] ?? 'legacy')),
            'confirmation_claim_id' => (int) ($reservation['confirmation_claim_id'] ?? 0),
            'booking_source' => \sanitize_key((string) ($reservation['booking_source'] ?? '')),
            'total_price' => (string) ($reservation['total_price'] ?? ''),
        ]));
    }
}
