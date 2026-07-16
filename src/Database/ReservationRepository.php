<?php
namespace MustHotelBooking\Database;
final class ReservationRepository extends AbstractRepository
{
    public function reservationsTableExists(): bool
    {
        return $this->tableExists('reservations');
    }
    private function inventoryLockTable(): string
    {
        return $this->wpdb->prefix . 'mhb_inventory_locks';
    }
    private function inventoryLockTableExists(): bool
    {
        $tableName = $this->inventoryLockTable();
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $tableName
            )
        );
        return \is_string($result) && $result !== '';
    }
    private function lockTableName(): string
    {
        if ($this->inventoryLockTableExists()) {
            return $this->inventoryLockTable();
        }
        return $this->table('locks');
    }
    public function bookingIdExists(string $bookingId): bool
    {
        if (\trim($bookingId) === '') {
            return false;
        }
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $this->table('reservations') . ' WHERE booking_id = %s',
                $bookingId
            )
        );
        return $count > 0;
    }
    /**
     * @return array<string, mixed>
     */
    public function getProviderMetadata(int $reservationId): array
    {
        if ($reservationId <= 0) {
            return [];
        }
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT provider, provider_booking_id, provider_reservation_id, provider_status, provider_payment_status, provider_sync_status, provider_synced_at, provider_sync_error, provider_payload_ref, provider_metadata
                FROM ' . $this->table('reservations') . '
                WHERE id = %d
                LIMIT 1',
                $reservationId
            ),
            ARRAY_A
        );
        return \is_array($row) ? $row : [];
    }
    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    public function getProviderReservationRowsByIds(array $reservationIds): array
    {
        $reservationIds = $this->normalizeIds($reservationIds);
        if (empty($reservationIds)) {
            return [];
        }
        $sql = $this->wpdb->prepare(
            'SELECT id, booking_id, room_id, room_type_id, assigned_room_id, checkin, checkout, status, payment_status, checked_in_at, checked_out_at, provider, provider_booking_id, provider_reservation_id, provider_status, provider_payment_status, provider_sync_status, provider_synced_at, provider_sync_error, provider_payload_ref, provider_metadata
            FROM ' . $this->table('reservations') . '
            WHERE id IN (' . $this->buildIntegerPlaceholders($reservationIds) . ')
            ORDER BY id ASC',
            ...$reservationIds
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProviderReservationRowsByExternalIds(string $provider, string $providerReservationId = '', string $providerBookingId = ''): array
    {
        $provider = $this->providerKey($provider, 50);
        $providerReservationId = $this->providerText($providerReservationId, 191);
        $providerBookingId = $this->providerText($providerBookingId, 191);
        if ($provider === '' || ($providerReservationId === '' && $providerBookingId === '')) {
            return [];
        }
        $clauses = [];
        $params = [$provider];
        if ($providerReservationId !== '') {
            $clauses[] = 'provider_reservation_id = %s';
            $params[] = $providerReservationId;
            $clauses[] = 'provider_payload_ref = %s';
            $params[] = $providerReservationId;
        }
        if ($providerBookingId !== '') {
            $clauses[] = 'provider_booking_id = %s';
            $params[] = $providerBookingId;
            $clauses[] = 'provider_payload_ref = %s';
            $params[] = $providerBookingId;
        }
        $sql = $this->wpdb->prepare(
            'SELECT id, booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, status, payment_status, checked_in_at, checked_out_at, provider, provider_booking_id, provider_reservation_id, provider_status, provider_payment_status, provider_sync_status, provider_synced_at, provider_sync_error, provider_payload_ref, provider_metadata
            FROM ' . $this->table('reservations') . '
            WHERE provider = %s
                AND (' . \implode(' OR ', $clauses) . ')
            ORDER BY id ASC',
            ...$params
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProviderAutoSyncReservationRows(string $provider, int $pastDays = 2, int $futureDays = 365, int $limit = 25): array
    {
        if (!$this->reservationsTableExists()) {
            return [];
        }

        $provider = $this->providerKey($provider, 50);

        if ($provider === '') {
            return [];
        }

        $pastDays = \max(0, \min(90, $pastDays));
        $futureDays = \max(1, \min(1095, $futureDays));
        $limit = \max(1, \min(100, $limit));

        $today = \function_exists('current_time') ? \current_time('Y-m-d') : \gmdate('Y-m-d');
        $fromTimestamp = \strtotime($today . ' -' . $pastDays . ' days');
        $toTimestamp = \strtotime($today . ' +' . $futureDays . ' days');

        $from = $fromTimestamp !== false ? \date('Y-m-d', $fromTimestamp) : $today;
        $to = $toTimestamp !== false ? \date('Y-m-d', $toTimestamp) : $today;

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, status, payment_status, checked_in_at, checked_out_at, provider, provider_booking_id, provider_reservation_id, provider_status, provider_payment_status, provider_sync_status, provider_synced_at, provider_sync_error, provider_payload_ref, provider_metadata
            FROM ' . $this->table('reservations') . '
            WHERE provider = %s
                AND (provider_booking_id <> "" OR provider_reservation_id <> "" OR provider_payload_ref <> "")
                AND status IN (%s, %s, %s)
                AND checkout >= %s
                AND checkin <= %s
            ORDER BY
                CASE WHEN provider_synced_at IS NULL OR provider_synced_at = "" THEN 0 ELSE 1 END ASC,
                provider_synced_at ASC,
                id ASC
            LIMIT %d',
                $provider,
                'pending',
                'pending_payment',
                'confirmed',
                $from,
                $to,
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }


    /**
     * @param array<string, mixed> $metadata
     */
    public function updateProviderMetadata(int $reservationId, array $metadata): bool
    {
        if ($reservationId <= 0) {
            return false;
        }
        $data = $this->normalizeProviderMetadataUpdate($metadata);
        if (empty($data)) {
            return true;
        }
        return $this->wpdb->update(
            $this->table('reservations'),
            $data,
            ['id' => $reservationId],
            $this->providerMetadataFormats($data),
            ['%d']
        ) !== false;
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function normalizeProviderMetadataUpdate(array $metadata): array
    {
        $data = [];
        foreach ($metadata as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            switch ($key) {
                case 'provider':
                    $data[$key] = $this->providerKey((string) $value, 50);
                    break;
                case 'provider_booking_id':
                case 'provider_reservation_id':
                case 'provider_payload_ref':
                    $data[$key] = $this->providerText((string) $value, 191);
                    break;
                case 'provider_status':
                case 'provider_payment_status':
                    $data[$key] = $this->providerText((string) $value, 100);
                    break;
                case 'provider_sync_status':
                    $data[$key] = $this->providerKey((string) $value, 50);
                    break;
                case 'provider_fulfilment_key':
                    $data[$key] = $this->providerText((string) $value, 191);
                    break;
                case 'provider_fulfilment_owner':
                    $data[$key] = $this->providerText((string) $value, 64);
                    break;
                case 'provider_fulfilment_claimed_at':
                case 'provider_fulfilment_lease_expires_at':
                case 'provider_synced_at':
                    $data[$key] = $this->providerNullableText($value);
                    break;
                case 'provider_sync_error':
                    $data[$key] = $this->providerNullableText($value);
                    break;
                case 'provider_metadata':
                    $data[$key] = $this->providerJson($value);
                    break;
            }
        }
        return $data;
    }

    public function ownsPendingClockReservation(int $reservationId, string $claimKey, string $ownerToken): bool
    {
        if ($reservationId <= 0 || $claimKey === '' || $ownerToken === '') {
            return false;
        }
        $owned = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT id FROM ' . $this->table('reservations') . '
                WHERE id = %d
                    AND provider_sync_status = %s
                    AND provider_fulfilment_key = %s
                    AND provider_fulfilment_owner = %s
                    AND provider_fulfilment_lease_expires_at > %s
                    AND provider_booking_id = %s
                    AND provider_reservation_id = %s
                LIMIT 1',
                $reservationId,
                'creating',
                $this->providerText($claimKey, 191),
                $this->providerText($ownerToken, 64),
                \current_time('mysql', true),
                '',
                ''
            )
        );
        return (int) $owned === $reservationId;
    }

    /** @param array<string, mixed> $metadata */
    public function updateClaimedClockReservation(int $reservationId, string $claimKey, string $ownerToken, array $metadata): bool
    {
        $data = $this->normalizeProviderMetadataUpdate($metadata);
        if ($reservationId <= 0 || $claimKey === '' || $ownerToken === '' || empty($data)) {
            return false;
        }
        $updated = $this->wpdb->update(
            $this->table('reservations'),
            $data,
            [
                'id' => $reservationId,
                'provider_sync_status' => 'creating',
                'provider_fulfilment_key' => $this->providerText($claimKey, 191),
                'provider_fulfilment_owner' => $this->providerText($ownerToken, 64),
                'provider_booking_id' => '',
                'provider_reservation_id' => '',
            ],
            $this->providerMetadataFormats($data),
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        return $updated === 1;
    }

    /** @return array<string, mixed>|null */
    public function getReservationForUpdate(int $reservationId): ?array
    {
        if ($reservationId <= 0) {
            return null;
        }
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM ' . $this->table('reservations') . ' WHERE id = %d FOR UPDATE',
                $reservationId
            ),
            ARRAY_A
        );
        return \is_array($row) ? $row : null;
    }

    /**
     * Claim a pending Clock reservation for one verified online payment.
     *
     * @return array{outcome: string, lease_expires_at?: string}
     */
    public function claimPendingClockReservation(int $reservationId, string $claimKey, string $ownerToken, int $leaseSeconds = 300): array
    {
        $claimKey = $this->providerText($claimKey, 191);
        $ownerToken = $this->providerText($ownerToken, 64);
        if ($reservationId <= 0 || $claimKey === '' || $ownerToken === '') {
            return ['outcome' => 'blocked'];
        }

        $reservation = $this->getReservation($reservationId);
        if (!\is_array($reservation)) {
            return ['outcome' => 'blocked'];
        }

        if (\trim((string) ($reservation['provider_booking_id'] ?? '')) !== ''
            || \trim((string) ($reservation['provider_reservation_id'] ?? '')) !== '') {
            return ['outcome' => 'already_fulfilled'];
        }

        $status = \sanitize_key((string) ($reservation['status'] ?? ''));
        $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));
        if ($status !== 'pending_payment' || $paymentStatus !== 'pending') {
            return ['outcome' => 'blocked'];
        }

        $syncStatus = \sanitize_key((string) ($reservation['provider_sync_status'] ?? ''));
        if (!\in_array($syncStatus, ['', 'pending_payment', 'pending_fulfilment', 'creating'], true)) {
            return ['outcome' => 'blocked'];
        }
        $now = \current_time('mysql', true);
        $leaseSeconds = \max(60, \min(900, $leaseSeconds));
        $leaseExpiresAt = \gmdate('Y-m-d H:i:s', \time() + $leaseSeconds);
        $activeLease = \trim((string) ($reservation['provider_fulfilment_lease_expires_at'] ?? ''));
        if ($syncStatus === 'creating' && $activeLease !== '' && $activeLease > $now) {
            return ['outcome' => 'in_progress', 'lease_expires_at' => $activeLease];
        }
        $expiredRecovery = $syncStatus === 'creating' && $activeLease !== '' && $activeLease <= $now;
        if ($expiredRecovery) {
            return ['outcome' => 'expired_claim_recovery'];
        }

        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE ' . $this->table('reservations') . '
                SET provider_sync_status = %s,
                    provider_sync_error = %s,
                    provider_fulfilment_key = %s,
                    provider_fulfilment_owner = %s,
                    provider_fulfilment_claimed_at = %s,
                    provider_fulfilment_lease_expires_at = %s
                WHERE id = %d
                    AND status = %s
                    AND payment_status = %s
                    AND provider_booking_id = %s
                    AND provider_reservation_id = %s
                    AND provider_sync_status IN (%s, %s, %s)
                    AND (provider_fulfilment_lease_expires_at IS NULL OR provider_fulfilment_lease_expires_at <= %s)',
                'creating',
                '',
                $claimKey,
                $ownerToken,
                $now,
                $leaseExpiresAt,
                $reservationId,
                'pending_payment',
                'pending',
                '',
                '',
                '',
                'pending_payment',
                'pending_fulfilment',
                $now
            )
        );

        return $updated === 1
            ? ['outcome' => 'claimed', 'lease_expires_at' => $leaseExpiresAt]
            : ['outcome' => 'in_progress'];
    }
    /**
     * @param array<string, mixed> $reservationData
     * @param array<int, string> $nonBlockingStatuses
     */
    public function createReservation(array $reservationData, array $nonBlockingStatuses = []): int
    {
        $roomId = isset($reservationData['room_id']) ? (int) $reservationData['room_id'] : 0;
        $roomTypeId = isset($reservationData['room_type_id']) ? (int) $reservationData['room_type_id'] : $roomId;
        $assignedRoomId = isset($reservationData['assigned_room_id']) ? (int) $reservationData['assigned_room_id'] : 0;
        $ratePlanId = isset($reservationData['rate_plan_id']) ? (int) $reservationData['rate_plan_id'] : 0;
        $guestId = isset($reservationData['guest_id']) ? (int) $reservationData['guest_id'] : 0;
        $guests = isset($reservationData['guests']) ? (int) $reservationData['guests'] : 0;
        $bookingId = isset($reservationData['booking_id']) ? (string) $reservationData['booking_id'] : '';
        $checkin = isset($reservationData['checkin']) ? (string) $reservationData['checkin'] : '';
        $checkout = isset($reservationData['checkout']) ? (string) $reservationData['checkout'] : '';
        $status = isset($reservationData['status']) ? (string) $reservationData['status'] : 'pending';
        $bookingSource = isset($reservationData['booking_source']) ? (string) $reservationData['booking_source'] : 'website';
        $notes = isset($reservationData['notes']) ? (string) $reservationData['notes'] : '';
        $totalPrice = isset($reservationData['total_price']) ? (float) $reservationData['total_price'] : 0.0;
        $couponId = isset($reservationData['coupon_id']) ? (int) $reservationData['coupon_id'] : 0;
        $couponCode = isset($reservationData['coupon_code']) ? (string) $reservationData['coupon_code'] : '';
        $couponDiscountTotal = isset($reservationData['coupon_discount_total']) ? (float) $reservationData['coupon_discount_total'] : 0.0;
        $paymentStatus = isset($reservationData['payment_status']) ? (string) $reservationData['payment_status'] : 'unpaid';
        $confirmationFlow = $this->normalizeConfirmationFlow((string) ($reservationData['confirmation_flow'] ?? 'legacy'));
        if (\MustHotelBooking\Core\ReservationStatus::isConfirmed($status)) {
            return 0;
        }
        $createdAt = isset($reservationData['created_at']) ? (string) $reservationData['created_at'] : \current_time('mysql');
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $availabilityColumn = $assignedRoomId > 0 ? 'assigned_room_id' : 'room_id';
        $availabilityTargetId = $assignedRoomId > 0 ? $assignedRoomId : $roomId;
        $sql = $this->wpdb->prepare(
            'INSERT INTO ' . $this->table('reservations') . '
                (booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, guests, status, booking_source, notes, total_price, coupon_id, coupon_code, coupon_discount_total, payment_status, confirmation_flow, created_at)
            SELECT %s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s, %s, %f, %d, %s, %f, %s, %s, %s
            WHERE NOT EXISTS (
                SELECT 1
                FROM ' . $this->table('reservations') . ' r
                WHERE r.' . $availabilityColumn . ' = %d
                    AND r.checkin < %s
                    AND r.checkout > %s
                    AND r.status NOT IN (%s, %s, %s)
            )
            LIMIT 1',
            $bookingId,
            $roomId,
            $roomTypeId,
            $assignedRoomId,
            $ratePlanId,
            $guestId,
            $checkin,
            $checkout,
            $guests,
            $status,
            $bookingSource,
            $notes,
            $totalPrice,
            $couponId,
            $couponCode,
            $couponDiscountTotal,
            $paymentStatus,
            $confirmationFlow,
            $createdAt,
            $availabilityTargetId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );
        $inserted = $this->wpdb->query($sql);
        if (!\is_int($inserted) || $inserted < 1) {
            return 0;
        }
        return (int) $this->wpdb->insert_id;
    }
    /**
     * @param array<string, mixed> $reservationData
     * @param array<int, string> $nonBlockingStatuses
     */
    public function createReservationFromLock(array $reservationData, string $sessionId, string $now, array $nonBlockingStatuses = []): int
    {
        $roomId = isset($reservationData['room_id']) ? (int) $reservationData['room_id'] : 0;
        $roomTypeId = isset($reservationData['room_type_id']) ? (int) $reservationData['room_type_id'] : $roomId;
        $assignedRoomId = isset($reservationData['assigned_room_id']) ? (int) $reservationData['assigned_room_id'] : 0;
        $ratePlanId = isset($reservationData['rate_plan_id']) ? (int) $reservationData['rate_plan_id'] : 0;
        $guestId = isset($reservationData['guest_id']) ? (int) $reservationData['guest_id'] : 0;
        $guests = isset($reservationData['guests']) ? (int) $reservationData['guests'] : 0;
        $bookingId = isset($reservationData['booking_id']) ? (string) $reservationData['booking_id'] : '';
        $checkin = isset($reservationData['checkin']) ? (string) $reservationData['checkin'] : '';
        $checkout = isset($reservationData['checkout']) ? (string) $reservationData['checkout'] : '';
        $status = isset($reservationData['status']) ? (string) $reservationData['status'] : 'pending';
        $totalPrice = isset($reservationData['total_price']) ? (float) $reservationData['total_price'] : 0.0;
        $couponId = isset($reservationData['coupon_id']) ? (int) $reservationData['coupon_id'] : 0;
        $couponCode = isset($reservationData['coupon_code']) ? (string) $reservationData['coupon_code'] : '';
        $couponDiscountTotal = isset($reservationData['coupon_discount_total']) ? (float) $reservationData['coupon_discount_total'] : 0.0;
        $paymentStatus = isset($reservationData['payment_status']) ? (string) $reservationData['payment_status'] : 'unpaid';
        $confirmationFlow = $this->normalizeConfirmationFlow((string) ($reservationData['confirmation_flow'] ?? 'legacy'));
        if (\MustHotelBooking\Core\ReservationStatus::isConfirmed($status)) {
            return 0;
        }
        $providerMetadata = $this->providerJson($reservationData['provider_metadata'] ?? null);
        $createdAt = isset($reservationData['created_at']) ? (string) $reservationData['created_at'] : $now;
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $locksTable = $this->lockTableName();
        $lockRoomId = $assignedRoomId > 0 ? $assignedRoomId : $roomId;
        $availabilityColumn = $assignedRoomId > 0 ? 'assigned_room_id' : 'room_id';
        $availabilityTargetId = $assignedRoomId > 0 ? $assignedRoomId : $roomId;
        $sql = $this->wpdb->prepare(
            'INSERT INTO ' . $this->table('reservations') . '
                (booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, guests, status, total_price, coupon_id, coupon_code, coupon_discount_total, payment_status, confirmation_flow, provider_metadata, created_at)
            SELECT %s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %f, %d, %s, %f, %s, %s, %s, %s
            WHERE EXISTS (
                SELECT 1
                FROM ' . $locksTable . ' l
                WHERE l.room_id = %d
                    AND l.checkin = %s
                    AND l.checkout = %s
                    AND l.session_id = %s
                    AND l.expires_at > %s
            )
            AND NOT EXISTS (
                SELECT 1
                FROM ' . $this->table('reservations') . ' r
                WHERE r.' . $availabilityColumn . ' = %d
                    AND r.checkin < %s
                    AND r.checkout > %s
                    AND r.status NOT IN (%s, %s, %s)
            )
            LIMIT 1',
            $bookingId,
            $roomId,
            $roomTypeId,
            $assignedRoomId,
            $ratePlanId,
            $guestId,
            $checkin,
            $checkout,
            $guests,
            $status,
            $totalPrice,
            $couponId,
            $couponCode,
            $couponDiscountTotal,
            $paymentStatus,
            $confirmationFlow,
            $providerMetadata,
            $createdAt,
            $lockRoomId,
            $checkin,
            $checkout,
            $sessionId,
            $now,
            $availabilityTargetId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );
        $inserted = $this->wpdb->query($sql);
        if (!\is_int($inserted) || $inserted < 1) {
            return 0;
        }
        return (int) $this->wpdb->insert_id;
    }
    /**
     * @param array<string, mixed> $reservationData
     */
    public function createProviderMirrorReservation(array $reservationData): int
    {
        $bookingId = isset($reservationData['booking_id']) ? (string) $reservationData['booking_id'] : '';
        $roomId = isset($reservationData['room_id']) ? (int) $reservationData['room_id'] : 0;
        $guestId = isset($reservationData['guest_id']) ? (int) $reservationData['guest_id'] : 0;
        $checkin = isset($reservationData['checkin']) ? (string) $reservationData['checkin'] : '';
        $checkout = isset($reservationData['checkout']) ? (string) $reservationData['checkout'] : '';
        if ($bookingId === '' || $roomId <= 0 || $guestId <= 0 || $checkin === '' || $checkout === '') {
            return 0;
        }
        $data = [
            'booking_id' => $bookingId,
            'room_id' => $roomId,
            'room_type_id' => isset($reservationData['room_type_id']) ? (int) $reservationData['room_type_id'] : $roomId,
            'assigned_room_id' => isset($reservationData['assigned_room_id']) ? (int) $reservationData['assigned_room_id'] : 0,
            'rate_plan_id' => isset($reservationData['rate_plan_id']) ? (int) $reservationData['rate_plan_id'] : 0,
            'guest_id' => $guestId,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => isset($reservationData['guests']) ? (int) $reservationData['guests'] : 1,
            'status' => isset($reservationData['status']) ? (string) $reservationData['status'] : 'pending',
            'booking_source' => isset($reservationData['booking_source']) ? (string) $reservationData['booking_source'] : 'website',
            'notes' => isset($reservationData['notes']) ? (string) $reservationData['notes'] : '',
            'total_price' => isset($reservationData['total_price']) ? (float) $reservationData['total_price'] : 0.0,
            'coupon_id' => isset($reservationData['coupon_id']) ? (int) $reservationData['coupon_id'] : 0,
            'coupon_code' => isset($reservationData['coupon_code']) ? (string) $reservationData['coupon_code'] : '',
            'coupon_discount_total' => isset($reservationData['coupon_discount_total']) ? (float) $reservationData['coupon_discount_total'] : 0.0,
            'payment_status' => isset($reservationData['payment_status']) ? (string) $reservationData['payment_status'] : 'unpaid',
            'confirmation_flow' => $this->normalizeConfirmationFlow((string) ($reservationData['confirmation_flow'] ?? 'clock_import')),
            'provider' => $this->providerKey((string) ($reservationData['provider'] ?? 'local'), 50),
            'provider_booking_id' => $this->providerText((string) ($reservationData['provider_booking_id'] ?? ''), 191),
            'provider_reservation_id' => $this->providerText((string) ($reservationData['provider_reservation_id'] ?? ''), 191),
            'provider_status' => $this->providerText((string) ($reservationData['provider_status'] ?? ''), 100),
            'provider_payment_status' => $this->providerText((string) ($reservationData['provider_payment_status'] ?? ''), 100),
            'provider_sync_status' => $this->providerKey((string) ($reservationData['provider_sync_status'] ?? ''), 50),
            'provider_synced_at' => $this->providerNullableText($reservationData['provider_synced_at'] ?? null),
            'provider_sync_error' => $this->providerNullableText($reservationData['provider_sync_error'] ?? null),
            'provider_payload_ref' => $this->providerText((string) ($reservationData['provider_payload_ref'] ?? ''), 191),
            'provider_metadata' => $this->providerJson($reservationData['provider_metadata'] ?? null),
            'created_at' => isset($reservationData['created_at']) ? (string) $reservationData['created_at'] : \current_time('mysql'),
        ];
        if (\MustHotelBooking\Core\ReservationStatus::isConfirmed((string) $data['status'])) {
            return 0;
        }
        $inserted = $this->wpdb->insert(
            $this->table('reservations'),
            $data,
            $this->resolveReservationFormats($data)
        );
        return $inserted !== false ? (int) $this->wpdb->insert_id : 0;
    }
    /** @param array<string, mixed> $data @return array<int, string> */
    private function providerMetadataFormats(array $data): array
    {
        return \array_fill(0, \count($data), '%s');
    }
    private function providerKey(string $value, int $maxLength): string
    {
        $value = \function_exists('sanitize_key') ? \sanitize_key($value) : \strtolower(\preg_replace('/[^a-zA-Z0-9_\-]/', '', $value) ?? '');
        return \substr($value, 0, $maxLength);
    }
    private function providerText(string $value, int $maxLength): string
    {
        $value = \function_exists('sanitize_text_field') ? \sanitize_text_field($value) : \trim(\strip_tags($value));
        return \substr($value, 0, $maxLength);
    }
    /** @param mixed $value */
    private function providerNullableText($value): ?string
    {
        $value = \is_scalar($value) ? \trim((string) $value) : '';
        return $value !== '' ? $value : null;
    }
    /** @param mixed $value */
    private function providerJson($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_string($value)) {
            return $value;
        }
        $json = \function_exists('wp_json_encode') ? \wp_json_encode($value) : \json_encode($value);
        return \is_string($json) ? $json : null;
    }
    /**
     * @return array<string, mixed>|null
     */
    public function getReservation(int $reservationId): ?array
    {
        if ($reservationId <= 0) {
            return null;
        }
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT *
                FROM ' . $this->table('reservations') . '
                WHERE id = %d
                LIMIT 1',
                $reservationId
            ),
            ARRAY_A
        );
        return \is_array($row) ? $row : null;
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAdminReservationListRows(array $filters = []): array
    {
        if (!$this->reservationsTableExists()) {
            return [];
        }
        $filters = $this->normalizeAdminReservationFilters($filters);
        $hasRoomsTable = $this->tableExists('rooms');
        $hasGuestsTable = $this->tableExists('guests');
        $hasPaymentsTable = $this->tableExists('payments');
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        $guestSelect = '\'\' AS guest_name';
        $guestEmailSelect = '\'\' AS guest_email';
        $guestPhoneSelect = '\'\' AS guest_phone';
        $guestJoin = '';
        $paymentSelect = '\'\' AS payment_method, \'\' AS payment_record_status';
        $paymentJoin = '';
        if ($hasRoomsTable) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
        }
        if ($hasGuestsTable) {
            $guestSelect = 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name';
            $guestEmailSelect = 'COALESCE(g.email, \'\') AS guest_email';
            $guestPhoneSelect = 'COALESCE(g.phone, \'\') AS guest_phone';
            $guestJoin = ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
        }
        if ($hasPaymentsTable) {
            $paymentSelect = 'COALESCE(p.method, \'\') AS payment_method, COALESCE(p.status, \'\') AS payment_record_status';
            $paymentJoin = ' LEFT JOIN ' . $this->table('payments') . ' p
                ON p.id = (
                    SELECT p2.id
                    FROM ' . $this->table('payments') . ' p2
                    WHERE p2.reservation_id = r.id
                    ORDER BY COALESCE(p2.paid_at, p2.created_at) DESC, p2.id DESC
                    LIMIT 1
                )';
        }
        $where = $this->buildAdminReservationWhereClause($filters, $hasRoomsTable, $hasGuestsTable, $hasPaymentsTable);
        $params = $where['params'];
        $sql = 'SELECT
                r.id,
                r.booking_id,
                r.room_id,
                r.guest_id,
                r.checkin,
                r.checkout,
                r.guests,
                r.status,
                r.cancellation_requested,
                r.cancellation_requested_at,
                r.cancellation_requested_by,
                r.total_price,
                r.coupon_id,
                r.coupon_code,
                r.coupon_discount_total,
                r.payment_status,
                r.provider,
                r.provider_booking_id,
                r.provider_reservation_id,
                r.provider_status,
                r.provider_payment_status,
                r.provider_sync_status,
                r.provider_synced_at,
                r.provider_sync_error,
                r.provider_payload_ref,
                r.provider_metadata,
                r.created_at,
                ' . $roomSelect . ',
                ' . $guestSelect . ',
                ' . $guestEmailSelect . ',
                ' . $guestPhoneSelect . ',
                ' . $paymentSelect . '
            FROM ' . $this->table('reservations') . ' r
            ' . $roomJoin . '
            ' . $guestJoin . '
            ' . $paymentJoin .
            $where['sql'] . '
            ORDER BY r.created_at DESC, r.id DESC';
        if ($filters['limit'] > 0) {
            $sql .= ' LIMIT %d';
            $params[] = $filters['limit'];
        } elseif (!empty($filters['paginate'])) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $filters['per_page'];
            $params[] = $filters['offset'];
        }
        if (empty($params)) {
            $rows = $this->wpdb->get_results($sql, ARRAY_A);
        } else {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare($sql, ...$params),
                ARRAY_A
            );
        }
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPendingCancellationRequestRows(int $limit = 5): array
    {
        if (!$this->reservationsTableExists()) {
            return [];
        }
        $limit = \max(1, \min(20, $limit));
        $hasRoomsTable = $this->tableExists('rooms');
        $hasGuestsTable = $this->tableExists('guests');
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        $guestSelect = '\'\' AS guest_name';
        $guestJoin = '';
        if ($hasRoomsTable) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
        }
        if ($hasGuestsTable) {
            $guestSelect = 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name';
            $guestJoin = ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.room_id,
                    r.guest_id,
                    r.checkin,
                    r.checkout,
                    r.status,
                    r.cancellation_requested,
                    r.cancellation_requested_at,
                    r.cancellation_requested_by,
                    ' . $roomSelect . ',
                    ' . $guestSelect . '
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                ' . $guestJoin . '
                WHERE r.cancellation_requested = 1
                    AND r.status NOT IN (%s, %s, %s)
                ORDER BY COALESCE(NULLIF(r.cancellation_requested_at, \'\'), r.created_at) ASC, r.id ASC
                LIMIT %d',
                'cancelled',
                'completed',
                'blocked',
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFrontDeskCheckinQueueRows(string $today, int $limit = 50): array
    {
        return $this->getFrontDeskQueueRows($today, 'checkin', $limit);
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFrontDeskCheckoutQueueRows(string $today, int $limit = 50): array
    {
        return $this->getFrontDeskQueueRows($today, 'checkout', $limit);
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getFrontDeskRoomMoveRows(int $limit = 50): array
    {
        if (!$this->reservationsTableExists()) {
            return [];
        }
        $limit = \max(1, \min(100, $limit));
        $hasRoomsTable = $this->tableExists('rooms');
        $hasGuestsTable = $this->tableExists('guests');
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        $guestSelect = '\'\' AS guest_name';
        $guestEmailSelect = '\'\' AS guest_email';
        $guestJoin = '';
        if ($hasRoomsTable) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
        }
        if ($hasGuestsTable) {
            $guestSelect = 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name';
            $guestEmailSelect = 'COALESCE(g.email, \'\') AS guest_email';
            $guestJoin = ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.room_id,
                    r.room_type_id,
                    r.assigned_room_id,
                    r.guest_id,
                    r.checkin,
                    r.checkout,
                    r.guests,
                    r.status,
                    r.total_price,
                    r.payment_status,
                    r.provider,
                    r.provider_booking_id,
                    r.provider_reservation_id,
                    r.provider_status,
                    r.provider_payment_status,
                    r.provider_sync_status,
                    r.provider_synced_at,
                    r.provider_sync_error,
                    r.provider_payload_ref,
                    r.provider_metadata,
                    r.created_at,
                    r.checked_in_at,
                    r.checked_out_at,
                    ' . $roomSelect . ',
                    ' . $guestSelect . ',
                    ' . $guestEmailSelect . '
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                ' . $guestJoin . '
                WHERE r.status = %s
                    AND r.assigned_room_id > 0
                    AND r.checked_in_at IS NOT NULL
                    AND r.checked_in_at <> %s
                    AND r.checked_in_at <> %s
                    AND (r.checked_out_at IS NULL OR r.checked_out_at = %s OR r.checked_out_at = %s)
                ORDER BY r.checkout ASC, r.id ASC
                LIMIT %d',
                'confirmed',
                '',
                '0000-00-00 00:00:00',
                '',
                '0000-00-00 00:00:00',
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }
    public function countAdminReservationListRows(array $filters = []): int
    {
        if (!$this->reservationsTableExists()) {
            return 0;
        }
        $filters = $this->normalizeAdminReservationFilters($filters);
        $hasRoomsTable = $this->tableExists('rooms');
        $hasGuestsTable = $this->tableExists('guests');
        $hasPaymentsTable = $this->tableExists('payments');
        $roomJoin = $hasRoomsTable ? ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id' : '';
        $guestJoin = $hasGuestsTable ? ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id' : '';
        $paymentJoin = '';
        if ($hasPaymentsTable) {
            $paymentJoin = ' LEFT JOIN ' . $this->table('payments') . ' p
                ON p.id = (
                    SELECT p2.id
                    FROM ' . $this->table('payments') . ' p2
                    WHERE p2.reservation_id = r.id
                    ORDER BY COALESCE(p2.paid_at, p2.created_at) DESC, p2.id DESC
                    LIMIT 1
                )';
        }
        $where = $this->buildAdminReservationWhereClause($filters, $hasRoomsTable, $hasGuestsTable, $hasPaymentsTable);
        $params = $where['params'];
        $sql = 'SELECT COUNT(*)
            FROM ' . $this->table('reservations') . ' r
            ' . $roomJoin . '
            ' . $guestJoin . '
            ' . $paymentJoin .
            $where['sql'];
        $count = empty($params)
            ? $this->wpdb->get_var($sql)
            : $this->wpdb->get_var($this->wpdb->prepare($sql, ...$params));
        return $count !== null ? (int) $count : 0;
    }
    /**
     * @return array<string, int>
     */
    public function getAdminReservationQuickFilterCounts(string $today): array
    {
        $counts = [
            'all' => 0,
            'arrivals_today' => 0,
            'departures_today' => 0,
            'in_house' => 0,
            'upcoming' => 0,
            'pending' => 0,
            'confirmed' => 0,
            'unpaid' => 0,
            'paid' => 0,
            'cancelled' => 0,
            'failed_payment' => 0,
        ];
        if ($today === '' || !$this->reservationsTableExists()) {
            return $counts;
        }
        $stayStatuses = \MustHotelBooking\Core\ReservationStatus::getConfirmedStatuses();
        $activeStatuses = ['pending', 'pending_payment', 'confirmed', 'completed'];
        $pendingStatuses = $this->getPendingReservationStatuses();
        $unpaidStatuses = ['unpaid', 'pending', 'failed'];
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT
                    COUNT(*) AS all_total,
                    SUM(CASE WHEN checkin = %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS arrivals_today,
                    SUM(CASE WHEN checkout = %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS departures_today,
                    SUM(CASE WHEN checkin <= %s AND checkout > %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS in_house,
                    SUM(CASE WHEN checkin > %s AND status IN (%s, %s, %s, %s) THEN 1 ELSE 0 END) AS upcoming,
                    SUM(CASE WHEN status IN (%s, %s) THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS confirmed,
                    SUM(CASE WHEN total_price > 0 AND payment_status IN (%s, %s, %s) AND status NOT IN (%s, %s, %s) THEN 1 ELSE 0 END) AS unpaid,
                    SUM(CASE WHEN payment_status = %s THEN 1 ELSE 0 END) AS paid,
                    SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN status = %s OR payment_status = %s THEN 1 ELSE 0 END) AS failed_payment
                FROM ' . $this->table('reservations'),
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $activeStatuses[0],
                $activeStatuses[1],
                $activeStatuses[2],
                $activeStatuses[3],
                $pendingStatuses[0],
                $pendingStatuses[1],
                'confirmed',
                $unpaidStatuses[0],
                $unpaidStatuses[1],
                $unpaidStatuses[2],
                'cancelled',
                'blocked',
                'expired',
                'paid',
                'cancelled',
                'payment_failed',
                'failed'
            ),
            ARRAY_A
        );
        if (!\is_array($row)) {
            return $counts;
        }
        $counts['all'] = isset($row['all_total']) ? (int) $row['all_total'] : 0;
        $counts['arrivals_today'] = isset($row['arrivals_today']) ? (int) $row['arrivals_today'] : 0;
        $counts['departures_today'] = isset($row['departures_today']) ? (int) $row['departures_today'] : 0;
        $counts['in_house'] = isset($row['in_house']) ? (int) $row['in_house'] : 0;
        $counts['upcoming'] = isset($row['upcoming']) ? (int) $row['upcoming'] : 0;
        $counts['pending'] = isset($row['pending']) ? (int) $row['pending'] : 0;
        $counts['confirmed'] = isset($row['confirmed']) ? (int) $row['confirmed'] : 0;
        $counts['unpaid'] = isset($row['unpaid']) ? (int) $row['unpaid'] : 0;
        $counts['paid'] = isset($row['paid']) ? (int) $row['paid'] : 0;
        $counts['cancelled'] = isset($row['cancelled']) ? (int) $row['cancelled'] : 0;
        $counts['failed_payment'] = isset($row['failed_payment']) ? (int) $row['failed_payment'] : 0;
        return $counts;
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAdminReservationQuickFilterRows(): array
    {
        if (!$this->reservationsTableExists()) {
            return [];
        }
        $rows = $this->wpdb->get_results(
            'SELECT
                id,
                checkin,
                checkout,
                status,
                total_price,
                payment_status
            FROM ' . $this->table('reservations') . '
            ORDER BY id ASC',
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    private function getFrontDeskQueueRows(string $today, string $mode, int $limit): array
    {
        if ($today === '' || !$this->reservationsTableExists()) {
            return [];
        }
        $limit = \max(1, \min(100, $limit));
        $hasRoomsTable = $this->tableExists('rooms');
        $hasGuestsTable = $this->tableExists('guests');
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        $guestSelect = '\'\' AS guest_name';
        $guestEmailSelect = '\'\' AS guest_email';
        $guestJoin = '';
        if ($hasRoomsTable) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
        }
        if ($hasGuestsTable) {
            $guestSelect = 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name';
            $guestEmailSelect = 'COALESCE(g.email, \'\') AS guest_email';
            $guestJoin = ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
        }
        if ($mode === 'checkout') {
            $sql = $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.room_id,
                    r.room_type_id,
                    r.assigned_room_id,
                    r.guest_id,
                    r.checkin,
                    r.checkout,
                    r.guests,
                    r.status,
                    r.total_price,
                    r.payment_status,
                    r.provider,
                    r.provider_booking_id,
                    r.provider_reservation_id,
                    r.provider_status,
                    r.provider_payment_status,
                    r.provider_sync_status,
                    r.provider_synced_at,
                    r.provider_sync_error,
                    r.provider_payload_ref,
                    r.provider_metadata,
                    r.created_at,
                    r.checked_in_at,
                    r.checked_out_at,
                    ' . $roomSelect . ',
                    ' . $guestSelect . ',
                    ' . $guestEmailSelect . '
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                ' . $guestJoin . '
                WHERE r.checkout <= %s
                    AND r.status = %s
                    AND r.checked_in_at IS NOT NULL
                    AND r.checked_in_at <> %s
                    AND r.checked_in_at <> %s
                    AND (r.checked_out_at IS NULL OR r.checked_out_at = %s OR r.checked_out_at = %s)
                ORDER BY r.checkout ASC, r.id ASC
                LIMIT %d',
                $today,
                'confirmed',
                '',
                '0000-00-00 00:00:00',
                '',
                '0000-00-00 00:00:00',
                $limit
            );
        } else {
            $sql = $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.room_id,
                    r.room_type_id,
                    r.assigned_room_id,
                    r.guest_id,
                    r.checkin,
                    r.checkout,
                    r.guests,
                    r.status,
                    r.total_price,
                    r.payment_status,
                    r.provider,
                    r.provider_booking_id,
                    r.provider_reservation_id,
                    r.provider_status,
                    r.provider_payment_status,
                    r.provider_sync_status,
                    r.provider_synced_at,
                    r.provider_sync_error,
                    r.provider_payload_ref,
                    r.provider_metadata,
                    r.created_at,
                    r.checked_in_at,
                    r.checked_out_at,
                    ' . $roomSelect . ',
                    ' . $guestSelect . ',
                    ' . $guestEmailSelect . '
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                ' . $guestJoin . '
                WHERE r.checkin <= %s
                    AND r.checkout >= %s
                    AND r.status IN (%s, %s, %s)
                    AND (r.checked_in_at IS NULL OR r.checked_in_at = %s OR r.checked_in_at = %s)
                    AND (r.checked_out_at IS NULL OR r.checked_out_at = %s OR r.checked_out_at = %s)
                ORDER BY r.checkin ASC, r.id ASC
                LIMIT %d',
                $today,
                $today,
                'pending',
                'pending_payment',
                'confirmed',
                '',
                '0000-00-00 00:00:00',
                '',
                '0000-00-00 00:00:00',
                $limit
            );
        }
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array{arrivals_reservations: int, arrivals_guests: int, departures_reservations: int, departures_guests: int, in_house_stays: int, in_house_guests: int, pending_reservations: int, unpaid_reservations: int, blocked_units: int, occupied_units: int}
     */
    public function getDashboardOperationalSummary(string $today): array
    {
        $summary = [
            'arrivals_reservations' => 0,
            'arrivals_guests' => 0,
            'departures_reservations' => 0,
            'departures_guests' => 0,
            'in_house_stays' => 0,
            'in_house_guests' => 0,
            'pending_reservations' => 0,
            'unpaid_reservations' => 0,
            'blocked_units' => 0,
            'occupied_units' => 0,
        ];
        if ($today === '' || !$this->reservationsTableExists()) {
            return $summary;
        }
        $stayStatuses = \MustHotelBooking\Core\ReservationStatus::getConfirmedStatuses();
        $pendingStatuses = $this->getPendingReservationStatuses();
        $paymentRelevantStatuses = ['pending', 'pending_payment', 'confirmed', 'completed'];
        $unpaidPaymentStatuses = ['unpaid', 'pending', 'failed'];
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT
                    SUM(CASE WHEN checkin = %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS arrivals_reservations,
                    SUM(CASE WHEN checkin = %s AND status IN (%s, %s) THEN guests ELSE 0 END) AS arrivals_guests,
                    SUM(CASE WHEN checkout = %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS departures_reservations,
                    SUM(CASE WHEN checkout = %s AND status IN (%s, %s) THEN guests ELSE 0 END) AS departures_guests,
                    SUM(CASE WHEN checkin <= %s AND checkout > %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS in_house_stays,
                    SUM(CASE WHEN checkin <= %s AND checkout > %s AND status IN (%s, %s) THEN guests ELSE 0 END) AS in_house_guests,
                    SUM(CASE WHEN status IN (%s, %s) THEN 1 ELSE 0 END) AS pending_reservations,
                    SUM(CASE WHEN status IN (%s, %s, %s, %s) AND total_price > 0 AND payment_status IN (%s, %s, %s) THEN 1 ELSE 0 END) AS unpaid_reservations,
                    COUNT(DISTINCT CASE
                        WHEN status = %s AND checkin <= %s AND checkout > %s THEN
                            CASE
                                WHEN assigned_room_id > 0 THEN CONCAT(\'inventory:\', assigned_room_id)
                                WHEN room_id > 0 THEN CONCAT(\'room:\', room_id)
                                ELSE NULL
                            END
                        ELSE NULL
                    END) AS blocked_units,
                    COUNT(DISTINCT CASE
                        WHEN checkin <= %s AND checkout > %s AND status IN (%s, %s) THEN
                            CASE
                                WHEN assigned_room_id > 0 THEN CONCAT(\'inventory:\', assigned_room_id)
                                WHEN room_id > 0 THEN CONCAT(\'room:\', room_id)
                                ELSE NULL
                            END
                        ELSE NULL
                    END) AS occupied_units
                FROM ' . $this->table('reservations'),
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $today,
                $today,
                $stayStatuses[0],
                $stayStatuses[1],
                $pendingStatuses[0],
                $pendingStatuses[1],
                $paymentRelevantStatuses[0],
                $paymentRelevantStatuses[1],
                $paymentRelevantStatuses[2],
                $paymentRelevantStatuses[3],
                $unpaidPaymentStatuses[0],
                $unpaidPaymentStatuses[1],
                $unpaidPaymentStatuses[2],
                'blocked',
                $today,
                $today,
                $today,
                $today,
                $stayStatuses[0],
                $stayStatuses[1]
            ),
            ARRAY_A
        );
        if (!\is_array($row)) {
            return $summary;
        }
        foreach (\array_keys($summary) as $key) {
            $summary[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }
        return $summary;
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentReservationRows(int $limit = 8): array
    {
        return $this->getAdminReservationListRows(['limit' => $limit]);
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getArrivalsWithIncompleteGuestDetails(string $today, int $limit = 5): array
    {
        if ($today === '' || !$this->reservationsTableExists() || !$this->tableExists('guests')) {
            return [];
        }
        $limit = \max(1, \min(20, $limit));
        $statuses = $this->getOperationalReservationStatuses();
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        if ($this->tableExists('rooms')) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.checkin,
                    r.checkout,
                    r.status,
                    ' . $roomSelect . ',
                    CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name,
                    COALESCE(g.email, \'\') AS email,
                    COALESCE(g.phone, \'\') AS phone
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id
                WHERE r.checkin = %s
                    AND r.status IN (%s, %s, %s, %s)
                    AND (
                        r.guest_id = 0
                        OR TRIM(CONCAT_WS(\' \', g.first_name, g.last_name)) = \'\'
                        OR COALESCE(g.email, \'\') = \'\'
                        OR COALESCE(g.phone, \'\') = \'\'
                    )
                ORDER BY r.checkin ASC, r.id DESC
                LIMIT %d',
                $today,
                $statuses[0],
                $statuses[1],
                $statuses[2],
                $statuses[3],
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStaleActiveReservationsPastCheckout(string $today, int $limit = 5): array
    {
        if ($today === '' || !$this->reservationsTableExists()) {
            return [];
        }
        $limit = \max(1, \min(20, $limit));
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        $guestSelect = '\'\' AS guest_name';
        $guestJoin = '';
        if ($this->tableExists('rooms')) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
        }
        if ($this->tableExists('guests')) {
            $guestSelect = 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name';
            $guestJoin = ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.checkin,
                    r.checkout,
                    r.status,
                    ' . $roomSelect . ',
                    ' . $guestSelect . '
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                ' . $guestJoin . '
                WHERE r.checkout < %s
                    AND r.status IN (%s, %s, %s)
                ORDER BY r.checkout ASC, r.id ASC
                LIMIT %d',
                $today,
                'pending',
                'pending_payment',
                'confirmed',
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPotentialConflictRows(int $limit = 5): array
    {
        if (!$this->reservationsTableExists()) {
            return [];
        }
        $limit = \max(1, \min(20, $limit));
        $statuses = $this->getOperationalReservationStatuses();
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        if ($this->tableExists('rooms')) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = a.room_id';
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    a.id AS reservation_id_left,
                    a.booking_id AS booking_id_left,
                    a.checkin AS checkin_left,
                    a.checkout AS checkout_left,
                    a.status AS status_left,
                    b.id AS reservation_id_right,
                    b.booking_id AS booking_id_right,
                    b.checkin AS checkin_right,
                    b.checkout AS checkout_right,
                    b.status AS status_right,
                    ' . $roomSelect . '
                FROM ' . $this->table('reservations') . ' a
                INNER JOIN ' . $this->table('reservations') . ' b
                    ON b.room_id = a.room_id
                    AND b.id > a.id
                    AND a.checkin < b.checkout
                    AND a.checkout > b.checkin
                ' . $roomJoin . '
                WHERE a.room_id > 0
                    AND a.status IN (%s, %s, %s, %s)
                    AND b.status IN (%s, %s, %s, %s)
                ORDER BY a.id DESC, b.id DESC
                LIMIT %d',
                $statuses[0],
                $statuses[1],
                $statuses[2],
                $statuses[3],
                $statuses[0],
                $statuses[1],
                $statuses[2],
                $statuses[3],
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getReservationsMissingPaymentRecords(int $limit = 5): array
    {
        if (!$this->reservationsTableExists() || !$this->tableExists('payments')) {
            return [];
        }
        $limit = \max(1, \min(20, $limit));
        $statuses = $this->getOperationalReservationStatuses();
        $roomSelect = '\'\' AS room_name';
        $roomJoin = '';
        $guestSelect = '\'\' AS guest_name';
        $guestJoin = '';
        if ($this->tableExists('rooms')) {
            $roomSelect = 'COALESCE(rm.name, \'\') AS room_name';
            $roomJoin = ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id';
        }
        if ($this->tableExists('guests')) {
            $guestSelect = 'CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name';
            $guestJoin = ' LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id';
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.status,
                    r.payment_status,
                    r.total_price,
                    r.created_at,
                    ' . $roomSelect . ',
                    ' . $guestSelect . '
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                ' . $guestJoin . '
                LEFT JOIN ' . $this->table('payments') . ' p ON p.reservation_id = r.id
                WHERE r.status IN (%s, %s, %s, %s)
                    AND r.total_price > 0
                    AND r.payment_status IN (%s, %s, %s)
                    AND p.id IS NULL
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT %d',
                $statuses[0],
                $statuses[1],
                $statuses[2],
                $statuses[3],
                'unpaid',
                'pending',
                'failed',
                $limit
            ),
            ARRAY_A
        );
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<string, mixed>|null
     */
    public function getAdminReservationDetails(int $reservationId): ?array
    {
        if ($reservationId <= 0 || !$this->reservationsTableExists()) {
            return null;
        }
        $sql = $this->wpdb->prepare(
            'SELECT
                r.*,
                rm.name AS room_name,
                g.first_name,
                g.last_name,
                g.email,
                g.phone,
                g.country
            FROM ' . $this->table('reservations') . ' r
            LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id
            LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id
            WHERE r.id = %d
            LIMIT 1',
            $reservationId
        );
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        return \is_array($row) ? $row : null;
    }
    /**
     * @param array<int, string> $nonBlockingStatuses
     */
    public function hasReservationOverlapExcludingId(
        int $reservationId,
        int $roomId,
        string $checkin,
        string $checkout,
        array $nonBlockingStatuses = []
    ): bool {
        if (
            $reservationId < 0 ||
            $roomId <= 0 ||
            $checkin === '' ||
            $checkout === '' ||
            !$this->reservationsTableExists()
        ) {
            return false;
        }
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $sql = $this->wpdb->prepare(
            'SELECT 1
            FROM ' . $this->table('reservations') . '
            WHERE room_id = %d
                AND id <> %d
                AND checkin < %s
                AND checkout > %s
                AND status NOT IN (%s, %s, %s)
            LIMIT 1',
            $roomId,
            $reservationId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );
        return $this->wpdb->get_var($sql) !== null;
    }
    /**
     * @param array<int, string> $nonBlockingStatuses
     */
    public function hasAssignedRoomOverlapExcludingId(
        int $reservationId,
        int $assignedRoomId,
        string $checkin,
        string $checkout,
        array $nonBlockingStatuses = []
    ): bool {
        if (
            $reservationId < 0 ||
            $assignedRoomId <= 0 ||
            $checkin === '' ||
            $checkout === '' ||
            !$this->reservationsTableExists()
        ) {
            return false;
        }
        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $sql = $this->wpdb->prepare(
            'SELECT 1
            FROM ' . $this->table('reservations') . '
            WHERE assigned_room_id = %d
                AND id <> %d
                AND checkin < %s
                AND checkout > %s
                AND status NOT IN (%s, %s, %s)
            LIMIT 1',
            $assignedRoomId,
            $reservationId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2]
        );
        return $this->wpdb->get_var($sql) !== null;
    }
    /**
     * @param array<string, mixed> $reservationData
     */
    public function updateReservation(int $reservationId, array $reservationData): bool
    {
        if ($reservationId <= 0 || empty($reservationData) || !$this->reservationsTableExists()) {
            return false;
        }
        if (\array_intersect(['confirmation_flow', 'confirmation_claim_id', 'confirmation_source', 'confirmed_at'], \array_keys($reservationData))) {
            $this->logUnauthorizedConfirmation($reservationId, 'protected_confirmation_metadata_update');
            return false;
        }
        if (isset($reservationData['status']) && $this->isFirstConfirmedTransition($reservationId, (string) $reservationData['status'])) {
            $this->logUnauthorizedConfirmation($reservationId, 'generic_repository_update');
            return false;
        }
        $updated = $this->wpdb->update(
            $this->table('reservations'),
            $reservationData,
            ['id' => $reservationId],
            $this->resolveReservationFormats($reservationData),
            ['%d']
        );
        return $updated !== false;
    }

    /**
     * Atomically update a reservation only when the requested destination is
     * still free for the requested stay.
     *
     * @param array<string, mixed> $reservationData
     * @param array<int, string> $nonBlockingStatuses
     */
    public function updateReservationIfDestinationAvailable(
        int $reservationId,
        int $destinationId,
        bool $physicalRoom,
        string $checkin,
        string $checkout,
        array $reservationData,
        array $nonBlockingStatuses = []
    ): bool {
        if (
            $reservationId <= 0
            || $destinationId <= 0
            || $checkin === ''
            || $checkout === ''
            || empty($reservationData)
            || !$this->reservationsTableExists()
        ) {
            return false;
        }

        $allowedColumns = [
            'room_id',
            'room_type_id',
            'assigned_room_id',
            'rate_plan_id',
            'checkin',
            'checkout',
            'total_price',
            'coupon_id',
            'coupon_code',
            'coupon_discount_total',
            'provider_metadata',
        ];
        $data = [];

        foreach ($reservationData as $column => $value) {
            if (\is_string($column) && \in_array($column, $allowedColumns, true)) {
                $data[$column] = $value;
            }
        }

        if (empty($data)) {
            return false;
        }

        $formats = $this->resolveReservationFormats($data);
        $assignments = [];
        $params = [];
        $index = 0;

        foreach ($data as $column => $value) {
            $assignments[] = 'target.' . $column . ' = ' . $formats[$index];
            $params[] = $value;
            $index++;
        }

        $statuses = $this->normalizeNonBlockingStatuses($nonBlockingStatuses);
        $destinationColumn = $physicalRoom ? 'assigned_room_id' : 'room_id';
        $sql = 'UPDATE ' . $this->table('reservations') . ' target
            LEFT JOIN ' . $this->table('reservations') . ' conflict
                ON conflict.' . $destinationColumn . ' = %d
                AND conflict.id <> target.id
                AND conflict.checkin < %s
                AND conflict.checkout > %s
                AND conflict.status NOT IN (%s, %s, %s)
            SET ' . \implode(', ', $assignments) . '
            WHERE target.id = %d
                AND conflict.id IS NULL';
        $prepareParams = [
            $destinationId,
            $checkout,
            $checkin,
            $statuses[0],
            $statuses[1],
            $statuses[2],
        ];
        $prepareParams = \array_merge($prepareParams, $params, [$reservationId]);
        $prepared = $this->wpdb->prepare($sql, ...$prepareParams);
        $updated = $this->wpdb->query($prepared);

        return \is_int($updated) && $updated === 1;
    }
    public function createBlockedReservation(int $roomId, string $checkin, string $checkout, string $createdAt = ''): int
    {
        if ($roomId <= 0 || $checkin === '' || $checkout === '' || !$this->reservationsTableExists()) {
            return 0;
        }
        $inserted = $this->wpdb->insert(
            $this->table('reservations'),
            [
                'room_id' => $roomId,
                'guest_id' => 0,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'guests' => 1,
                'status' => 'blocked',
                'total_price' => 0.00,
                'payment_status' => 'blocked',
                'created_at' => $createdAt !== '' ? $createdAt : \current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%f', '%s', '%s']
        );
        if ($inserted === false) {
            return 0;
        }
        return (int) $this->wpdb->insert_id;
    }
    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getBlockedReservationRows(array $filters = []): array
    {
        if (!$this->reservationsTableExists()) {
            return [];
        }
        $roomId = isset($filters['room_id']) ? (int) $filters['room_id'] : 0;
        $timeline = isset($filters['timeline']) ? \sanitize_key((string) $filters['timeline']) : '';
        $search = isset($filters['search']) ? \sanitize_text_field((string) $filters['search']) : '';
        $today = isset($filters['today']) ? \sanitize_text_field((string) $filters['today']) : \current_time('Y-m-d');
        $limit = isset($filters['limit']) ? \max(1, (int) $filters['limit']) : 500;
        $where = ['r.status = %s'];
        $params = ['blocked'];
        if ($roomId > 0) {
            $where[] = '(r.room_id = %d OR r.room_type_id = %d)';
            $params[] = $roomId;
            $params[] = $roomId;
        }
        if ($timeline === 'current') {
            $where[] = 'r.checkin <= %s AND r.checkout > %s';
            $params[] = $today;
            $params[] = $today;
        } elseif ($timeline === 'future') {
            $where[] = 'r.checkout > %s';
            $params[] = $today;
        } elseif ($timeline === 'past') {
            $where[] = 'r.checkout <= %s';
            $params[] = $today;
        }
        if ($search !== '') {
            $where[] = '(COALESCE(r.booking_id, \'\') LIKE %s OR COALESCE(rm.name, \'\') LIKE %s OR COALESCE(r.notes, \'\') LIKE %s)';
            $like = '%' . $this->wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql = 'SELECT
                r.id,
                r.booking_id,
                r.room_id,
                r.room_type_id,
                r.assigned_room_id,
                r.checkin,
                r.checkout,
                r.guests,
                r.status,
                r.notes,
                r.total_price,
                r.payment_status,
                r.provider_sync_status,
                r.created_at,
                COALESCE(rm.name, \'\') AS room_name
            FROM ' . $this->table('reservations') . ' r
            LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id
            WHERE ' . \implode(' AND ', $where) . '
            ORDER BY r.checkin DESC, r.checkout DESC, r.id DESC
            LIMIT %d';
        $params[] = $limit;
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBlockedReservationConflicts(int $roomId, string $checkin, string $checkout, int $excludeReservationId = 0): array
    {
        if ($roomId <= 0 || $checkin === '' || $checkout === '' || !$this->reservationsTableExists()) {
            return [];
        }
        $excluded = ['cancelled', 'expired', 'payment_failed'];
        $sql = 'SELECT
                id,
                booking_id,
                room_id,
                room_type_id,
                assigned_room_id,
                checkin,
                checkout,
                guests,
                status,
                payment_status,
                created_at
            FROM ' . $this->table('reservations') . '
            WHERE (room_id = %d OR room_type_id = %d)
                AND checkin < %s
                AND checkout > %s
                AND status NOT IN (%s, %s, %s)';
        $params = [$roomId, $roomId, $checkout, $checkin, $excluded[0], $excluded[1], $excluded[2]];
        if ($excludeReservationId > 0) {
            $sql .= ' AND id <> %d';
            $params[] = $excludeReservationId;
        }
        $sql .= ' ORDER BY checkin ASC, checkout ASC, id ASC';
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, ...$params), ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    public function deleteReservation(int $reservationId, string $requiredStatus = ''): bool
    {
        if ($reservationId <= 0 || !$this->reservationsTableExists()) {
            return false;
        }
        $where = ['id' => $reservationId];
        $formats = ['%d'];
        if ($requiredStatus !== '') {
            $where['status'] = $requiredStatus;
            $formats[] = '%s';
        }
        $deleted = $this->wpdb->delete(
            $this->table('reservations'),
            $where,
            $formats
        );
        return $deleted !== false;
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCalendarReservationRows(string $startDate, string $endExclusive, array $roomIds = []): array
    {
        if ($startDate === '' || $endExclusive === '' || !$this->reservationsTableExists()) {
            return [];
        }
        $roomIds = \array_values(
            \array_filter(
                \array_map('intval', $roomIds),
                static function (int $roomId): bool {
                    return $roomId > 0;
                }
            )
        );
        $params = [$endExclusive, $startDate];
        $roomFilterSql = '';
        if (!empty($roomIds)) {
            $roomFilterSql = ' AND r.room_id IN (' . \implode(', ', \array_fill(0, \count($roomIds), '%d')) . ')';
            $params = \array_merge($params, $roomIds);
        }
        $sql = $this->wpdb->prepare(
            'SELECT
                r.id,
                r.booking_id,
                r.room_id,
                r.room_type_id,
                r.assigned_room_id,
                r.guest_id,
                r.checkin,
                r.checkout,
                r.guests,
                r.status,
                r.booking_source,
                r.notes,
                r.total_price,
                r.payment_status,
                r.created_at,
                rm.name AS room_name,
                CONCAT_WS(\' \', g.first_name, g.last_name) AS guest_name
            FROM ' . $this->table('reservations') . ' r
            LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id
            LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id
            WHERE r.checkin < %s
                AND r.checkout > %s' . $roomFilterSql . '
            ORDER BY r.room_id ASC, r.checkin ASC, r.checkout ASC, r.id ASC',
            ...$params
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @param array<int, int> $roomIds
     * @return array<int, array<string, mixed>>
     */
    public function getAccommodationReservationSummaryMap(array $roomIds, string $today): array
    {
        $roomIds = $this->normalizeIds($roomIds);
        if (empty($roomIds) || $today === '' || !$this->reservationsTableExists()) {
            return [];
        }
        $activeStatuses = ['pending', 'pending_payment', 'confirmed', 'completed'];
        $stayStatuses = ['confirmed', 'completed'];
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    CASE WHEN room_type_id > 0 THEN room_type_id ELSE room_id END AS accommodation_id,
                    COUNT(*) AS reservation_count,
                    SUM(CASE WHEN checkin >= %s AND status IN (%s, %s, %s, %s) THEN 1 ELSE 0 END) AS future_reservations,
                    SUM(CASE WHEN checkin <= %s AND checkout > %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS current_reservations,
                    MIN(CASE WHEN checkin >= %s AND status IN (%s, %s, %s, %s) THEN checkin ELSE NULL END) AS next_checkin
                FROM ' . $this->table('reservations') . '
                WHERE (CASE WHEN room_type_id > 0 THEN room_type_id ELSE room_id END) IN (' . $this->buildIntegerPlaceholders($roomIds) . ')
                GROUP BY accommodation_id
                ORDER BY accommodation_id ASC',
                ...\array_merge(
                    [$today, $activeStatuses[0], $activeStatuses[1], $activeStatuses[2], $activeStatuses[3], $today, $today, $stayStatuses[0], $stayStatuses[1], $today, $activeStatuses[0], $activeStatuses[1], $activeStatuses[2], $activeStatuses[3]],
                    $roomIds
                )
            ),
            ARRAY_A
        );
        $summary = [];
        if (!\is_array($rows)) {
            return $summary;
        }
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $roomId = isset($row['accommodation_id']) ? (int) $row['accommodation_id'] : 0;
            if ($roomId <= 0) {
                continue;
            }
            $summary[$roomId] = [
                'reservation_count' => isset($row['reservation_count']) ? (int) $row['reservation_count'] : 0,
                'future_reservations' => isset($row['future_reservations']) ? (int) $row['future_reservations'] : 0,
                'current_reservations' => isset($row['current_reservations']) ? (int) $row['current_reservations'] : 0,
                'next_checkin' => isset($row['next_checkin']) ? (string) $row['next_checkin'] : '',
            ];
        }
        return $summary;
    }
    /**
     * @param array<int, int> $inventoryRoomIds
     * @return array<int, array<string, mixed>>
     */
    public function getInventoryRoomReservationSummaryMap(array $inventoryRoomIds, string $today): array
    {
        $inventoryRoomIds = $this->normalizeIds($inventoryRoomIds);
        if (empty($inventoryRoomIds) || $today === '' || !$this->reservationsTableExists()) {
            return [];
        }
        $activeStatuses = ['pending', 'pending_payment', 'confirmed', 'completed'];
        $stayStatuses = ['confirmed', 'completed'];
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    assigned_room_id,
                    COUNT(*) AS reservation_count,
                    SUM(CASE WHEN checkin >= %s AND status IN (%s, %s, %s, %s) THEN 1 ELSE 0 END) AS future_reservations,
                    SUM(CASE WHEN checkin <= %s AND checkout > %s AND status IN (%s, %s) THEN 1 ELSE 0 END) AS current_reservations,
                    MIN(CASE WHEN checkin >= %s AND status IN (%s, %s, %s, %s) THEN checkin ELSE NULL END) AS next_checkin
                FROM ' . $this->table('reservations') . '
                WHERE assigned_room_id IN (' . $this->buildIntegerPlaceholders($inventoryRoomIds) . ')
                GROUP BY assigned_room_id
                ORDER BY assigned_room_id ASC',
                ...\array_merge(
                    [$today, $activeStatuses[0], $activeStatuses[1], $activeStatuses[2], $activeStatuses[3], $today, $today, $stayStatuses[0], $stayStatuses[1], $today, $activeStatuses[0], $activeStatuses[1], $activeStatuses[2], $activeStatuses[3]],
                    $inventoryRoomIds
                )
            ),
            ARRAY_A
        );
        $summary = [];
        if (!\is_array($rows)) {
            return $summary;
        }
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $roomId = isset($row['assigned_room_id']) ? (int) $row['assigned_room_id'] : 0;
            if ($roomId <= 0) {
                continue;
            }
            $summary[$roomId] = [
                'reservation_count' => isset($row['reservation_count']) ? (int) $row['reservation_count'] : 0,
                'future_reservations' => isset($row['future_reservations']) ? (int) $row['future_reservations'] : 0,
                'current_reservations' => isset($row['current_reservations']) ? (int) $row['current_reservations'] : 0,
                'next_checkin' => isset($row['next_checkin']) ? (string) $row['next_checkin'] : '',
            ];
        }
        return $summary;
    }
    public function countReservationsForAccommodationType(int $roomId): int
    {
        if ($roomId <= 0 || !$this->reservationsTableExists()) {
            return 0;
        }
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE room_id = %d OR room_type_id = %d',
                $roomId,
                $roomId
            )
        );
    }
    public function countReservationsForInventoryRoom(int $inventoryRoomId): int
    {
        if ($inventoryRoomId <= 0 || !$this->reservationsTableExists()) {
            return 0;
        }
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE assigned_room_id = %d',
                $inventoryRoomId
            )
        );
    }
    /**
     * @return array{bookings_today: int, upcoming_checkins: int, upcoming_checkouts: int, total_bookings_this_month: int}
     */
    public function getDashboardMetrics(string $today, string $monthStart, string $nextMonthStart): array
    {
        $metrics = [
            'bookings_today' => 0,
            'upcoming_checkins' => 0,
            'upcoming_checkouts' => 0,
            'total_bookings_this_month' => 0,
        ];
        if (!$this->reservationsTableExists()) {
            return $metrics;
        }
        $excluded = ['cancelled', 'blocked', 'expired', 'payment_failed', 'pending_payment'];
        $metrics['bookings_today'] = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE DATE(created_at) = %s
                    AND status NOT IN (%s, %s, %s, %s, %s)',
                $today,
                $excluded[0],
                $excluded[1],
                $excluded[2],
                $excluded[3],
                $excluded[4]
            )
        );
        $metrics['upcoming_checkins'] = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE checkin > %s
                    AND status NOT IN (%s, %s, %s, %s, %s)',
                $today,
                $excluded[0],
                $excluded[1],
                $excluded[2],
                $excluded[3],
                $excluded[4]
            )
        );
        $metrics['upcoming_checkouts'] = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE checkout > %s
                    AND status NOT IN (%s, %s, %s, %s, %s)',
                $today,
                $excluded[0],
                $excluded[1],
                $excluded[2],
                $excluded[3],
                $excluded[4]
            )
        );
        $metrics['total_bookings_this_month'] = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $this->table('reservations') . '
                WHERE created_at >= %s
                    AND created_at < %s
                    AND status NOT IN (%s, %s, %s, %s, %s)',
                $monthStart,
                $nextMonthStart,
                $excluded[0],
                $excluded[1],
                $excluded[2],
                $excluded[3],
                $excluded[4]
            )
        );
        return $metrics;
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingReservationRows(string $today, int $limit = 10): array
    {
        if ($today === '' || !$this->reservationsTableExists()) {
            return [];
        }
        $limit = \max(1, \min(50, $limit));
        $excluded = ['cancelled', 'blocked', 'expired', 'payment_failed', 'pending_payment'];
        $sql = $this->wpdb->prepare(
            'SELECT
                id,
                booking_id,
                room_id,
                checkin,
                checkout,
                guests,
                status,
                total_price,
                created_at
            FROM ' . $this->table('reservations') . '
            WHERE checkin >= %s
                AND status NOT IN (%s, %s, %s, %s, %s)
            ORDER BY checkin ASC, checkout ASC, id ASC
            LIMIT %d',
            $today,
            $excluded[0],
            $excluded[1],
            $excluded[2],
            $excluded[3],
            $excluded[4],
            $limit
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    public function getReservationsByIds(array $reservationIds): array
    {
        $reservationIds = $this->normalizeIds($reservationIds);
        if (empty($reservationIds)) {
            return [];
        }
        $sql = $this->wpdb->prepare(
            'SELECT id, booking_id, room_id, room_type_id, assigned_room_id, rate_plan_id, guest_id, checkin, checkout, guests, status, booking_source, provider, total_price, coupon_id, coupon_code, coupon_discount_total, payment_status, confirmation_flow, confirmation_claim_id, confirmation_source, confirmed_at, created_at
            FROM ' . $this->table('reservations') . '
            WHERE id IN (' . $this->buildIntegerPlaceholders($reservationIds) . ')
            ORDER BY id ASC',
            ...$reservationIds
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    public function updateReservationStatus(int $reservationId, string $status, string $paymentStatus = ''): bool
    {
        if ($reservationId <= 0) {
            return false;
        }
        if ($this->isFirstConfirmedTransition($reservationId, $status)) {
            $this->logUnauthorizedConfirmation($reservationId, 'status_repository_update');
            return false;
        }
        return $this->persistStatus($reservationId, $status, $paymentStatus);
    }

    public function persistAuthorizedConfirmation(
        int $reservationId,
        string $status,
        string $paymentStatus,
        ?\MustHotelBooking\Engine\ReservationConfirmationAuthorization $authorization = null
    ): bool {
        if ($reservationId <= 0 || !\MustHotelBooking\Core\ReservationStatus::isConfirmed($status) || !$authorization || !$authorization->matchesTarget($reservationId, $status, $paymentStatus)) {
            return false;
        }
        $current = $this->getReservationForUpdate($reservationId);
        if (
            !\is_array($current)
            || !$authorization->matchesReservation($current)
            || $authorization->source() === ''
            || $authorization->flow() !== \sanitize_key((string) ($current['confirmation_flow'] ?? 'legacy'))
        ) {
            return false;
        }
        if ($authorization->isOnline()) {
            $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $this->table('payment_verifications') . ' v
                 INNER JOIN ' . $this->table('payment_verification_groups') . ' g ON g.id = v.verification_group_id
                 WHERE v.verification_group_id = %d AND v.reservation_id = %d AND v.payment_id = %d AND v.claim_hash = %s
                   AND g.allocation_set_hash = %s AND g.allocation_count = (SELECT COUNT(*) FROM ' . $this->table('payment_verifications') . ' WHERE verification_group_id = g.id)',
                $authorization->verificationGroupId(),
                $reservationId,
                $authorization->paymentId(),
                $authorization->claimHash(),
                $authorization->allocationSetHash()
            ));
            if ($count !== 1) {
                return false;
            }
        } elseif ($authorization->isOffline()) {
            $expectedPaymentStatus = \sanitize_key($paymentStatus) === 'unpaid' ? 'pending' : 'paid';
            $count = (int) $this->wpdb->get_var($this->wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $this->table('payments') . ' WHERE id = %d AND reservation_id = %d AND method = %s AND status = %s',
                $authorization->paymentId(),
                $reservationId,
                'pay_at_hotel',
                $expectedPaymentStatus
            ));
            if ($count !== 1) {
                return false;
            }
        }
        $confirmationClaimId = $authorization->isOnline()
            ? $authorization->verificationGroupId()
            : ($authorization->isOffline() ? $authorization->paymentId() : 0);
        $data = [
            'status' => \sanitize_key($status),
            'payment_status' => \sanitize_key($paymentStatus),
            'confirmation_claim_id' => $confirmationClaimId,
            'confirmation_source' => $authorization->source(),
            'confirmed_at' => \current_time('mysql'),
        ];
        $updated = $this->wpdb->update(
            $this->table('reservations'),
            $data,
            ['id' => $reservationId, 'status' => (string) ($current['status'] ?? '')],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d', '%s']
        );
        return $updated === 1;
    }

    public function setConfirmationFlowForFirstConfirmation(int $reservationId, string $flow): bool
    {
        $flow = $this->normalizeConfirmationFlow($flow);
        if ($reservationId <= 0 || !\in_array($flow, ['website_online_stripe', 'website_online_pokpay', 'clock_import', 'administrative_recovery', 'staff_offline'], true)) {
            return false;
        }
        $updated = $this->wpdb->query($this->wpdb->prepare(
            'UPDATE ' . $this->table('reservations') . ' SET confirmation_flow = %s WHERE id = %d AND confirmation_flow IN (%s, %s) AND status NOT IN (%s, %s)',
            $flow,
            $reservationId,
            '',
            'legacy',
            'confirmed',
            'completed'
        ));
        if ($updated === 1) {
            return true;
        }
        $current = (string) $this->wpdb->get_var($this->wpdb->prepare('SELECT confirmation_flow FROM ' . $this->table('reservations') . ' WHERE id = %d', $reservationId));
        return $current === $flow;
    }

    private function persistStatus(int $reservationId, string $status, string $paymentStatus): bool
    {
        $data = [
            'status' => $status,
        ];
        $formats = ['%s'];
        if ($paymentStatus !== '') {
            $data['payment_status'] = $paymentStatus;
            $formats[] = '%s';
        }
        $updated = $this->wpdb->update(
            $this->table('reservations'),
            $data,
            ['id' => $reservationId],
            $formats,
            ['%d']
        );
        return \is_int($updated);
    }

    private function isFirstConfirmedTransition(int $reservationId, string $targetStatus): bool
    {
        if (!\MustHotelBooking\Core\ReservationStatus::isConfirmed($targetStatus)) {
            return false;
        }
        $current = (string) $this->wpdb->get_var($this->wpdb->prepare('SELECT status FROM ' . $this->table('reservations') . ' WHERE id = %d', $reservationId));
        return !\MustHotelBooking\Core\ReservationStatus::isConfirmed($current);
    }

    private function normalizeConfirmationFlow(string $flow): string
    {
        $flow = \sanitize_key($flow);
        return \in_array($flow, ['website_online_stripe', 'website_online_pokpay', 'website_offline_pay_at_hotel', 'staff_offline', 'clock_import', 'administrative_recovery', 'legacy'], true)
            ? $flow
            : 'legacy';
    }

    private function logUnauthorizedConfirmation(int $reservationId, string $source): void
    {
        (new ActivityRepository())->createActivity([
            'event_type' => 'confirmation_blocked',
            'severity' => 'warning',
            'entity_type' => 'reservation',
            'entity_id' => $reservationId,
            'reference' => 'confirmation-integrity',
            'message' => 'unauthorized_first_confirmation',
            'context_json' => \wp_json_encode([
                'reservation_ids' => [$reservationId],
                'source' => $source,
                'reason_code' => 'authorization_missing',
            ]),
        ]);
    }
    public function updateReservationNotes(int $reservationId, string $notes): bool
    {
        if ($reservationId <= 0) {
            return false;
        }
        $updated = $this->wpdb->update(
            $this->table('reservations'),
            ['notes' => $notes],
            ['id' => $reservationId],
            ['%s'],
            ['%d']
        );
        return \is_int($updated);
    }
    public function updateReservationCouponData(int $reservationId, int $couponId, string $couponCode, float $couponDiscountTotal): bool
    {
        if ($reservationId <= 0) {
            return false;
        }
        $updated = $this->wpdb->update(
            $this->table('reservations'),
            [
                'coupon_id' => \max(0, $couponId),
                'coupon_code' => \strtoupper(\trim($couponCode)),
                'coupon_discount_total' => \max(0.0, \round($couponDiscountTotal, 2)),
            ],
            ['id' => $reservationId],
            ['%d', '%s', '%f'],
            ['%d']
        );
        return \is_int($updated);
    }
    public function assignInventoryRoomToReservation(int $reservationId, int $roomTypeId, int $assignedRoomId): bool
    {
        if ($reservationId <= 0 || $assignedRoomId <= 0) {
            return false;
        }
        $updated = $this->wpdb->update(
            $this->table('reservations'),
            [
                'room_type_id' => \max(0, $roomTypeId),
                'assigned_room_id' => $assignedRoomId,
            ],
            ['id' => $reservationId],
            ['%d', '%d'],
            ['%d']
        );
        return \is_int($updated);
    }
    /**
     * @return array<string, mixed>|null
     */
    public function getReservationEmailData(int $reservationId): ?array
    {
        if ($reservationId <= 0) {
            return null;
        }
        $sql = $this->wpdb->prepare(
            'SELECT
                r.id,
                r.booking_id,
                r.checkin,
                r.checkout,
                r.status,
                r.total_price,
                r.payment_status,
                r.provider_metadata,
                r.assigned_room_id,
                r.rate_plan_id,
                rm.name AS room_name,
                rp.name AS rate_plan_name,
                g.first_name,
                g.last_name,
                g.email AS guest_email,
                COALESCE(p.method, \'\') AS payment_method
            FROM ' . $this->table('reservations') . ' r
            LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id
            LEFT JOIN ' . $this->wpdb->prefix . 'mhb_rate_plans' . ' rp ON rp.id = r.rate_plan_id
            LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id
            LEFT JOIN ' . $this->table('payments') . ' p
                ON p.id = (
                    SELECT p2.id
                    FROM ' . $this->table('payments') . ' p2
                    WHERE p2.reservation_id = r.id
                    ORDER BY p2.id DESC
                    LIMIT 1
                )
            WHERE r.id = %d
            LIMIT 1',
            $reservationId
        );
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        return \is_array($row) ? $row : null;
    }
    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    public function getConfirmationRowsByIds(array $reservationIds): array
    {
        $reservationIds = $this->normalizeIds($reservationIds);
        if (empty($reservationIds)) {
            return [];
        }
        $sql = $this->wpdb->prepare(
            $this->getConfirmationRowsQuery('r.id IN (' . $this->buildIntegerPlaceholders($reservationIds) . ')'),
            ...$reservationIds
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConfirmationRowsByBookingId(string $bookingId): array
    {
        if (\trim($bookingId) === '') {
            return [];
        }
        $sql = $this->wpdb->prepare(
            $this->getConfirmationRowsQuery('r.booking_id = %s'),
            $bookingId
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return \is_array($rows) ? $rows : [];
    }
    /**
     * @return array<int, int>
     */
    public function findExpiredPendingPaymentReservationIds(string $cutoff): array
    {
        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT id
                FROM ' . $this->table('reservations') . '
                WHERE status = %s
                    AND payment_status = %s
                    AND created_at <= %s',
                'pending_payment',
                'pending',
                $cutoff
            )
        );
        return $this->normalizeIds(\is_array($rows) ? $rows : []);
    }
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeAdminReservationFilters(array $filters): array
    {
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $paged = isset($filters['paged']) ? (int) $filters['paged'] : 1;
        $checkinMonth = isset($filters['checkin_month']) ? \sanitize_text_field((string) $filters['checkin_month']) : '';
        if (!\preg_match('/^\d{4}-\d{2}$/', $checkinMonth)) {
            $checkinMonth = '';
        }
        $perPage = \max(1, \min(100, $perPage));
        $paged = \max(1, $paged);
        return [
            'paginate' => !isset($filters['paginate']) || (bool) $filters['paginate'],
            'limit' => $limit > 0 ? \min(200, $limit) : 0,
            'offset' => $limit > 0 ? 0 : (($paged - 1) * $perPage),
            'per_page' => $perPage,
            'paged' => $paged,
            'quick_filter' => isset($filters['quick_filter']) ? \sanitize_key((string) $filters['quick_filter']) : '',
            'search' => isset($filters['search']) ? \sanitize_text_field((string) $filters['search']) : '',
            'status' => isset($filters['status']) ? \sanitize_key((string) $filters['status']) : '',
            'status_group' => isset($filters['status_group']) ? \sanitize_key((string) $filters['status_group']) : '',
            'payment_status' => isset($filters['payment_status']) ? \sanitize_key((string) $filters['payment_status']) : '',
            'payment_group' => isset($filters['payment_group']) ? \sanitize_key((string) $filters['payment_group']) : '',
            'payment_method' => isset($filters['payment_method']) ? \sanitize_key((string) $filters['payment_method']) : '',
            'room_id' => isset($filters['room_id']) ? \absint($filters['room_id']) : 0,
            'date_scope' => isset($filters['date_scope']) ? \sanitize_key((string) $filters['date_scope']) : '',
            'today' => isset($filters['today']) ? \sanitize_text_field((string) $filters['today']) : \current_time('Y-m-d'),
            'checkin_from' => isset($filters['checkin_from']) ? \sanitize_text_field((string) $filters['checkin_from']) : '',
            'checkin_to' => isset($filters['checkin_to']) ? \sanitize_text_field((string) $filters['checkin_to']) : '',
            'checkin_month' => $checkinMonth,
            'created_after' => isset($filters['created_after']) ? \sanitize_text_field((string) $filters['created_after']) : '',
        ];
    }
    /**
     * @param array<string, mixed> $filters
     * @return array{sql: string, params: array<int, mixed>}
     */
    private function buildAdminReservationWhereClause(
        array $filters,
        bool $hasRoomsTable,
        bool $hasGuestsTable,
        bool $hasPaymentsTable
    ): array {
        $where = [];
        $params = [];
        $stayStatuses = \MustHotelBooking\Core\ReservationStatus::getConfirmedStatuses();
        $activeStatuses = $this->getOperationalReservationStatuses();
        $quickFilter = $filters['quick_filter'];
        if ($quickFilter === '' && $filters['date_scope'] !== '') {
            $quickFilter = $filters['date_scope'];
        }
        if ($quickFilter === '' && $filters['status_group'] === 'pending') {
            $quickFilter = 'pending';
        }
        if ($quickFilter === '' && $filters['payment_group'] === 'unpaid') {
            $quickFilter = 'unpaid';
        }
        if ($quickFilter === 'arrivals_today' && $filters['today'] !== '') {
            $where[] = 'r.checkin = %s AND r.status IN (%s, %s)';
            $params[] = $filters['today'];
            $params[] = $stayStatuses[0];
            $params[] = $stayStatuses[1];
        } elseif ($quickFilter === 'departures_today' && $filters['today'] !== '') {
            $where[] = 'r.checkout = %s AND r.status IN (%s, %s)';
            $params[] = $filters['today'];
            $params[] = $stayStatuses[0];
            $params[] = $stayStatuses[1];
        } elseif ($quickFilter === 'in_house_today' && $filters['today'] !== '') {
            $where[] = 'r.checkin <= %s AND r.checkout > %s AND r.status IN (%s, %s)';
            $params[] = $filters['today'];
            $params[] = $filters['today'];
            $params[] = $stayStatuses[0];
            $params[] = $stayStatuses[1];
        } elseif ($quickFilter === 'upcoming' && $filters['today'] !== '') {
            $where[] = 'r.checkin > %s AND r.status IN (%s, %s, %s, %s)';
            $params[] = $filters['today'];
            $params[] = $activeStatuses[0];
            $params[] = $activeStatuses[1];
            $params[] = $activeStatuses[2];
            $params[] = $activeStatuses[3];
        } elseif ($quickFilter === 'pending') {
            $pendingStatuses = $this->getPendingReservationStatuses();
            $where[] = 'r.status IN (%s, %s)';
            $params[] = $pendingStatuses[0];
            $params[] = $pendingStatuses[1];
        } elseif ($quickFilter === 'confirmed') {
            $where[] = 'r.status = %s';
            $params[] = 'confirmed';
        } elseif ($quickFilter === 'unpaid') {
            $where[] = 'r.total_price > 0 AND r.payment_status IN (%s, %s, %s) AND r.status NOT IN (%s, %s, %s)';
            $params[] = 'unpaid';
            $params[] = 'pending';
            $params[] = 'failed';
            $params[] = 'cancelled';
            $params[] = 'blocked';
            $params[] = 'expired';
        } elseif ($quickFilter === 'paid') {
            $where[] = 'r.payment_status = %s';
            $params[] = 'paid';
        } elseif ($quickFilter === 'cancelled') {
            $where[] = 'r.status = %s';
            $params[] = 'cancelled';
        } elseif ($quickFilter === 'failed_payment') {
            $where[] = '(r.status = %s OR r.payment_status = %s)';
            $params[] = 'payment_failed';
            $params[] = 'failed';
        }
        if ($filters['status'] !== '') {
            $where[] = 'r.status = %s';
            $params[] = $filters['status'];
        } elseif ($filters['status_group'] === 'pending' && $quickFilter === '') {
            $pendingStatuses = $this->getPendingReservationStatuses();
            $where[] = 'r.status IN (%s, %s)';
            $params[] = $pendingStatuses[0];
            $params[] = $pendingStatuses[1];
        }
        if ($filters['payment_status'] !== '') {
            $where[] = 'r.payment_status = %s';
            $params[] = $filters['payment_status'];
        } elseif ($filters['payment_group'] === 'unpaid' && $quickFilter === '') {
            $where[] = 'r.total_price > 0 AND r.payment_status IN (%s, %s, %s)';
            $params[] = 'unpaid';
            $params[] = 'pending';
            $params[] = 'failed';
        }
        if ($filters['payment_method'] !== '' && $hasPaymentsTable) {
            $where[] = 'p.method = %s';
            $params[] = $filters['payment_method'];
        }
        if ($filters['room_id'] > 0) {
            $where[] = '(r.room_id = %d OR r.room_type_id = %d)';
            $params[] = $filters['room_id'];
            $params[] = $filters['room_id'];
        }
        if ($filters['checkin_from'] !== '') {
            $where[] = 'r.checkin >= %s';
            $params[] = $filters['checkin_from'];
        }
        if ($filters['checkin_to'] !== '') {
            $where[] = 'r.checkin <= %s';
            $params[] = $filters['checkin_to'];
        }
        if ($filters['checkin_month'] !== '') {
            $monthStart = $filters['checkin_month'] . '-01';
            $monthStartDate = new \DateTimeImmutable($monthStart);
            $monthEnd = $monthStartDate->modify('first day of next month')->format('Y-m-d');
            $where[] = 'r.checkin >= %s AND r.checkin < %s';
            $params[] = $monthStart;
            $params[] = $monthEnd;
        }
        if ($filters['created_after'] !== '') {
            $where[] = 'r.created_at >= %s';
            $params[] = $filters['created_after'];
        }
        if ($filters['search'] !== '') {
            $searchClauses = ['r.booking_id LIKE %s'];
            $searchParams = ['%' . $filters['search'] . '%'];
            $searchClauses[] = 'r.provider_booking_id LIKE %s';
            $searchClauses[] = 'r.provider_reservation_id LIKE %s';
            $searchClauses[] = 'r.provider_payload_ref LIKE %s';
            $searchClauses[] = 'r.provider_metadata LIKE %s';
            $searchParams[] = '%' . $filters['search'] . '%';
            $searchParams[] = '%' . $filters['search'] . '%';
            $searchParams[] = '%' . $filters['search'] . '%';
            $searchParams[] = '%' . $filters['search'] . '%';
            if ($hasGuestsTable) {
                $searchClauses[] = 'g.first_name LIKE %s';
                $searchClauses[] = 'g.last_name LIKE %s';
                $searchClauses[] = 'CONCAT_WS(\' \', g.first_name, g.last_name) LIKE %s';
                $searchClauses[] = 'g.email LIKE %s';
                $searchClauses[] = 'g.phone LIKE %s';
                $searchParams[] = '%' . $filters['search'] . '%';
                $searchParams[] = '%' . $filters['search'] . '%';
                $searchParams[] = '%' . $filters['search'] . '%';
                $searchParams[] = '%' . $filters['search'] . '%';
                $searchParams[] = '%' . $filters['search'] . '%';
            }
            if ($hasRoomsTable) {
                $searchClauses[] = 'rm.name LIKE %s';
                $searchParams[] = '%' . $filters['search'] . '%';
            }
            if ($hasPaymentsTable) {
                $searchClauses[] = 'p.transaction_id LIKE %s';
                $searchParams[] = '%' . $filters['search'] . '%';
            }
            $where[] = '(' . \implode(' OR ', $searchClauses) . ')';
            $params = \array_merge($params, $searchParams);
        }
        if (empty($where)) {
            return [
                'sql' => '',
                'params' => [],
            ];
        }
        return [
            'sql' => ' WHERE ' . \implode(' AND ', $where),
            'params' => $params,
        ];
    }
    /**
     * @return array<int, string>
     */
    private function getOperationalReservationStatuses(): array
    {
        return ['pending', 'pending_payment', 'confirmed', 'completed'];
    }
    /**
     * @return array<int, string>
     */
    private function getPendingReservationStatuses(): array
    {
        return ['pending', 'pending_payment'];
    }
    /**
     * @param array<string, mixed> $reservationData
     * @return array<int, string>
     */
    private function resolveReservationFormats(array $reservationData): array
    {
        $integerFields = ['room_id', 'room_type_id', 'assigned_room_id', 'rate_plan_id', 'guest_id', 'guests', 'coupon_id'];
        $floatFields = ['total_price', 'coupon_discount_total'];
        $formats = [];
        foreach (\array_keys($reservationData) as $field) {
            if (\in_array($field, $integerFields, true)) {
                $formats[] = '%d';
                continue;
            }
            if (\in_array($field, $floatFields, true)) {
                $formats[] = '%f';
                continue;
            }
            $formats[] = '%s';
        }
        return $formats;
    }
    private function getConfirmationRowsQuery(string $whereSql): string
    {
        return 'SELECT
                r.id,
                r.booking_id,
                r.room_id,
                r.room_type_id,
                r.assigned_room_id,
                r.rate_plan_id,
                r.guest_id,
                r.checkin,
                r.checkout,
                r.guests,
                r.status,
                r.total_price,
                r.coupon_id,
                r.coupon_code,
                r.coupon_discount_total,
                r.payment_status,
                r.provider_sync_status,
                r.created_at,
                rm.name AS room_name,
                inv.room_number AS assigned_room_number,
                rp.name AS rate_plan_name,
                g.first_name,
                g.last_name,
                g.email,
                g.phone,
                g.country
            FROM ' . $this->table('reservations') . ' r
            LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id
            LEFT JOIN ' . $this->wpdb->prefix . 'mhb_rooms' . ' inv ON inv.id = r.assigned_room_id
            LEFT JOIN ' . $this->wpdb->prefix . 'mhb_rate_plans' . ' rp ON rp.id = r.rate_plan_id
            LEFT JOIN ' . $this->table('guests') . ' g ON g.id = r.guest_id
            WHERE ' . $whereSql . '
            ORDER BY r.id ASC';
    }
}
