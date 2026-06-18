<?php

namespace MustHotelBooking\Database;

final class RefundRepository extends AbstractRepository
{
    public function refundsTableExists(): bool
    {
        return $this->tableExists('refunds');
    }

    private function refundColumnExists(string $column): bool
    {
        if ($column === '' || !$this->refundsTableExists() || !\preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        $found = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW COLUMNS FROM ' . $this->table('refunds') . ' LIKE %s',
                $column
            )
        );

        return (string) $found === $column;
    }

    /** @param array<string, mixed> $data */
    public function createRefund(array $data): int
    {
        $data = $this->filterExistingRefundColumns($data);
        $inserted = $this->wpdb->insert(
            $this->table('refunds'),
            $data,
            $this->formats($data)
        );

        return $inserted === false ? 0 : (int) $this->wpdb->insert_id;
    }

    /** @param array<string, mixed> $data */
    public function updateRefund(int $refundId, array $data): bool
    {
        if ($refundId <= 0 || empty($data)) {
            return false;
        }

        $data = $this->filterExistingRefundColumns($data);

        if (empty($data)) {
            return false;
        }

        $updated = $this->wpdb->update(
            $this->table('refunds'),
            $data,
            ['id' => $refundId],
            $this->formats($data),
            ['%d']
        );

        return \is_int($updated);
    }

    /** @return array<string, mixed>|null */
    public function getRefund(int $refundId): ?array
    {
        if ($refundId <= 0 || !$this->refundsTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . ' WHERE id = %d',
                $refundId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findByStripeRefundId(string $stripeRefundId): ?array
    {
        $stripeRefundId = \trim($stripeRefundId);

        if ($stripeRefundId === '' || !$this->refundsTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . ' WHERE stripe_refund_id = %s ORDER BY id DESC LIMIT 1',
                $stripeRefundId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findOpenByPaymentReference(string $paymentIntentId, string $chargeId = ''): ?array
    {
        $paymentIntentId = \trim($paymentIntentId);
        $chargeId = \trim($chargeId);

        if (!$this->refundsTableExists() || ($paymentIntentId === '' && $chargeId === '')) {
            return null;
        }

        if ($paymentIntentId !== '' && $chargeId !== '') {
            $row = $this->wpdb->get_row(
                $this->wpdb->prepare(
                    'SELECT * FROM ' . $this->table('refunds') . '
                    WHERE stripe_refund_id = \'\'
                        AND (stripe_payment_intent_id = %s OR stripe_charge_id = %s)
                    ORDER BY id DESC
                    LIMIT 1',
                    $paymentIntentId,
                    $chargeId
                ),
                ARRAY_A
            );

            return \is_array($row) ? $row : null;
        }

        $column = $paymentIntentId !== '' ? 'stripe_payment_intent_id' : 'stripe_charge_id';
        $value = $paymentIntentId !== '' ? $paymentIntentId : $chargeId;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . '
                WHERE stripe_refund_id = \'\'
                    AND ' . $column . ' = %s
                ORDER BY id DESC
                LIMIT 1',
                $value
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findOpenManualByProviderReference(int $reservationId, string $gateway, string $providerPaymentReference): ?array
    {
        $gateway = \sanitize_key($gateway);
        $providerPaymentReference = \trim($providerPaymentReference);

        if ($reservationId <= 0 || $gateway === '' || $providerPaymentReference === '' || !$this->refundsTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . '
                WHERE reservation_id = %d
                    AND gateway = %s
                    AND provider_payment_reference = %s
                    AND status IN (\'manual_pending\', \'pending\', \'processing\')
                ORDER BY id DESC
                LIMIT 1',
                $reservationId,
                $gateway,
                $providerPaymentReference
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findBlockingProviderRefund(int $reservationId, string $gateway, string $providerPaymentReference, float $amount): ?array
    {
        $gateway = \sanitize_key($gateway);
        $providerPaymentReference = \trim($providerPaymentReference);
        $amount = \round($amount, 2);

        if ($reservationId <= 0 || $gateway === '' || $providerPaymentReference === '' || $amount <= 0.0 || !$this->refundsTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . '
                WHERE reservation_id = %d
                    AND gateway = %s
                    AND provider_payment_reference = %s
                    AND ABS(amount - %f) < 0.01
                    AND status IN (\'pending\', \'processing\', \'succeeded\', \'completed\', \'manual_pending\', \'manual_completed\')
                ORDER BY id DESC
                LIMIT 1',
                $reservationId,
                $gateway,
                $providerPaymentReference,
                $amount
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findRetryableProviderRefund(int $reservationId, string $gateway, string $providerPaymentReference, float $amount): ?array
    {
        $gateway = \sanitize_key($gateway);
        $providerPaymentReference = \trim($providerPaymentReference);
        $amount = \round($amount, 2);

        if ($reservationId <= 0 || $gateway === '' || $providerPaymentReference === '' || $amount <= 0.0 || !$this->refundsTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . '
                WHERE reservation_id = %d
                    AND gateway = %s
                    AND provider_payment_reference = %s
                    AND ABS(amount - %f) < 0.01
                    AND status = %s
                    AND provider_refund_id = \'\'
                    AND provider_refund_reference = \'\'
                ORDER BY id DESC
                LIMIT 1',
                $reservationId,
                $gateway,
                $providerPaymentReference,
                $amount,
                'failed'
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findCancellationReview(int $reservationId, string $gateway, string $providerPaymentReference): ?array
    {
        $gateway = \sanitize_key($gateway);
        $providerPaymentReference = \trim($providerPaymentReference);

        if ($reservationId <= 0 || $gateway === '' || !$this->refundsTableExists()) {
            return null;
        }

        $sql = 'SELECT * FROM ' . $this->table('refunds') . '
            WHERE reservation_id = %d
                AND gateway = %s
                AND refund_type = %s
                AND status = %s';
        $params = [
            $reservationId,
            $gateway,
            'clock_cancellation_review',
            'refund_review_required',
        ];

        if ($providerPaymentReference !== '') {
            $sql .= ' AND provider_payment_reference = %s';
            $params[] = $providerPaymentReference;
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function getRefundsForReservation(int $reservationId): array
    {
        if ($reservationId <= 0 || !$this->refundsTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . '
                WHERE reservation_id = %d
                ORDER BY created_at DESC, id DESC',
                $reservationId
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /** @return array<int, array<string, mixed>> */
    public function getRetryableClockSyncRefunds(int $limit = 20): array
    {
        if (!$this->refundsTableExists()) {
            return [];
        }

        $limit = \max(1, \min(100, $limit));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('refunds') . '
                WHERE status IN (\'succeeded\', \'processing\')
                    AND clock_refund_item_id = \'\'
                    AND clock_sync_status IN (\'pending\', \'failed\', \'retrying\')
                ORDER BY updated_at ASC, id ASC
                LIMIT %d',
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $data @return array<int, string> */
    private function formats(array $data): array
    {
        $formats = [];

        foreach ($data as $key => $value) {
            if (\in_array($key, ['id', 'reservation_id', 'payment_id', 'requested_by_user_id'], true)) {
                $formats[] = '%d';
                continue;
            }

            if (\in_array($key, ['amount', 'original_paid_amount', 'provider_fee_retained', 'cancellation_fee_amount', 'final_refund_amount'], true)) {
                $formats[] = '%f';
                continue;
            }

            $formats[] = '%s';
        }

        return $formats;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function filterExistingRefundColumns(array $data): array
    {
        $knownBase = [
            'id' => true,
            'reservation_id' => true,
            'booking_id' => true,
            'payment_id' => true,
            'provider' => true,
            'clock_booking_id' => true,
            'clock_reservation_id' => true,
            'clock_folio_id' => true,
            'clock_refund_item_id' => true,
            'gateway' => true,
            'provider_payment_reference' => true,
            'provider_refund_id' => true,
            'provider_refund_reference' => true,
            'raw_provider_status' => true,
            'stripe_payment_intent_id' => true,
            'stripe_charge_id' => true,
            'stripe_refund_id' => true,
            'amount' => true,
            'currency' => true,
            'reason' => true,
            'refund_type' => true,
            'status' => true,
            'clock_sync_status' => true,
            'requested_by_user_id' => true,
            'idempotency_key' => true,
            'clock_idempotency_key' => true,
            'failed_reason' => true,
            'manual_note' => true,
            'metadata' => true,
            'created_at' => true,
            'updated_at' => true,
            'completed_at' => true,
            'manual_completed_at' => true,
        ];
        $filtered = [];

        foreach ($data as $key => $value) {
            if (isset($knownBase[$key]) || $this->refundColumnExists((string) $key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
