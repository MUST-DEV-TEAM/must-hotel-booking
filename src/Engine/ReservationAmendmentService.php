<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Provider\Clock\ClockReservationAmendmentService;
use MustHotelBooking\Provider\ProviderManager;

final class ReservationAmendmentService
{
    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservations;

    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventory;

    /** @var \MustHotelBooking\Database\ActivityRepository */
    private $activity;

    public function __construct()
    {
        $this->reservations = get_reservation_repository();
        $this->inventory = get_inventory_repository();
        $this->activity = get_activity_repository();
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function amend(int $reservationId, array $request, string $source = 'admin'): array
    {
        $reservation = $this->reservations->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return $this->failure(\__('Reservation was not found.', 'must-hotel-booking'));
        }

        $normalized = $this->normalizeRequest($reservation, $request);
        $validation = $this->validateRequest($reservation, $normalized);

        if (empty($validation['success'])) {
            return $validation;
        }

        if (!empty($validation['no_change'])) {
            return [
                'success' => true,
                'queued' => false,
                'no_change' => true,
                'message' => \__('The reservation already matches the requested accommodation, room, rate, and dates.', 'must-hotel-booking'),
            ];
        }

        if ((string) ($reservation['provider'] ?? '') === ProviderManager::CLOCK_MODE) {
            return (new ClockReservationAmendmentService())->amend($reservation, $normalized, $source);
        }

        return $this->amendLocal($reservation, $normalized, $source);
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function amendLocal(array $reservation, array $request, string $source): array
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $lockNames = $this->lockNames($reservationId, (int) $request['target_assigned_room_id']);
        $acquiredLocks = [];

        foreach ($lockNames as $lockName) {
            if (!$this->acquireLock($lockName)) {
                $this->releaseLocks($acquiredLocks);

                return $this->failure(\__('Another room amendment is already running. Retry after it finishes.', 'must-hotel-booking'), true);
            }

            $acquiredLocks[] = $lockName;
        }

        try {
            $latest = $this->reservations->getReservation($reservationId);

            if (!\is_array($latest)) {
                return $this->failure(\__('Reservation was not found after acquiring the amendment lock.', 'must-hotel-booking'));
            }

            $validation = $this->validateRequest($latest, $request);

            if (empty($validation['success'])) {
                return $validation;
            }

            if (!empty($validation['no_change'])) {
                return [
                    'success' => true,
                    'queued' => false,
                    'no_change' => true,
                    'message' => \__('This amendment was already applied.', 'must-hotel-booking'),
                ];
            }

            $pricing = $this->localPricing($latest, $request);

            if (empty($pricing['success'])) {
                return $pricing;
            }

            $oldTotal = \round((float) ($latest['total_price'] ?? 0.0), 2);
            $newTotal = \round((float) ($pricing['total_price'] ?? $oldTotal), 2);
            $priceDelta = \round($newTotal - $oldTotal, 2);
            $idempotencyKey = $this->idempotencyKey($reservationId, $request);
            $metadata = $this->decodeMetadata($latest['provider_metadata'] ?? null);
            $review = $this->financialReview($priceDelta);
            $metadata['last_reservation_amendment'] = [
                'idempotency_key' => $idempotencyKey,
                'source' => $source,
                'status' => 'completed',
                'old_room_type_id' => (int) ($latest['room_type_id'] ?? $latest['room_id'] ?? 0),
                'new_room_type_id' => (int) $request['target_room_type_id'],
                'old_assigned_room_id' => (int) ($latest['assigned_room_id'] ?? 0),
                'new_assigned_room_id' => (int) $request['target_assigned_room_id'],
                'old_rate_plan_id' => (int) ($latest['rate_plan_id'] ?? 0),
                'new_rate_plan_id' => (int) $request['target_rate_plan_id'],
                'old_checkin' => (string) ($latest['checkin'] ?? ''),
                'old_checkout' => (string) ($latest['checkout'] ?? ''),
                'new_checkin' => (string) $request['target_checkin'],
                'new_checkout' => (string) $request['target_checkout'],
                'old_total' => $oldTotal,
                'new_total' => $newTotal,
                'price_delta' => $priceDelta,
                'financial_review_type' => $review['type'],
                'completed_at' => \current_time('mysql'),
            ];
            $metadata['manual_review_required'] = $review['required'];
            $metadata['additional_payment_review_required'] = $review['type'] === 'additional_payment_review_required';
            $metadata['refund_or_credit_review_required'] = $review['type'] === 'refund_or_credit_review_required';

            $updates = [
                'room_id' => (int) $request['target_room_type_id'],
                'room_type_id' => (int) $request['target_room_type_id'],
                'assigned_room_id' => (int) $request['target_assigned_room_id'],
                'rate_plan_id' => (int) $request['target_rate_plan_id'],
                'checkin' => (string) $request['target_checkin'],
                'checkout' => (string) $request['target_checkout'],
                'total_price' => $newTotal,
                'provider_metadata' => \wp_json_encode($metadata),
            ];

            if (isset($pricing['applied_coupon_id'])) {
                $updates['coupon_id'] = (int) $pricing['applied_coupon_id'];
            }
            if (isset($pricing['applied_coupon'])) {
                $updates['coupon_code'] = (string) $pricing['applied_coupon'];
            }
            if (isset($pricing['discount_total'])) {
                $updates['coupon_discount_total'] = \max(0.0, (float) $pricing['discount_total']);
            }

            $transactionStarted = $this->reservations->beginTransaction();
            $physicalRoom = InventoryEngine::hasInventoryForRoomType((int) $request['target_room_type_id']);
            $targetAssignedRoomId = (int) $request['target_assigned_room_id'];

            if ($physicalRoom && $targetAssignedRoomId <= 0) {
                $updated = $this->reservations->updateReservation($reservationId, $updates);
            } else {
                $destinationId = $physicalRoom
                    ? $targetAssignedRoomId
                    : (int) $request['target_room_type_id'];
                $updated = $this->reservations->updateReservationIfDestinationAvailable(
                    $reservationId,
                    $destinationId,
                    $physicalRoom,
                    $this->availabilityCheckin($latest, (string) $request['target_checkin']),
                    (string) $request['target_checkout'],
                    $updates,
                    ReservationStatus::getInventoryNonBlockingStatuses()
                );
            }

            if (!$updated) {
                if ($transactionStarted) {
                    $this->reservations->rollback();
                }

                return $this->failure(\__('The destination became unavailable before the amendment could be saved. The original assignment was preserved.', 'must-hotel-booking'));
            }

            if ($transactionStarted && !$this->reservations->commit()) {
                $this->reservations->rollback();

                return $this->failure(\__('Unable to commit the room amendment. The reservation requires review.', 'must-hotel-booking'));
            }

            $this->recordActivity($reservationId, $latest, $request, $source, $priceDelta, $idempotencyKey);

            return [
                'success' => true,
                'queued' => false,
                'message' => \__('Reservation accommodation amendment completed.', 'must-hotel-booking'),
                'old_total' => $oldTotal,
                'new_total' => $newTotal,
                'price_delta' => $priceDelta,
                'financial_review_type' => $review['type'],
                'manual_review_required' => $review['required'],
            ];
        } finally {
            $this->releaseLocks($acquiredLocks);
        }
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function validateRequest(array $reservation, array $request): array
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $status = \sanitize_key((string) ($reservation['status'] ?? ''));
        $checkedInAt = \trim((string) ($reservation['checked_in_at'] ?? ''));
        $checkedOutAt = \trim((string) ($reservation['checked_out_at'] ?? ''));
        $targetRoomTypeId = (int) ($request['target_room_type_id'] ?? 0);
        $targetAssignedRoomId = (int) ($request['target_assigned_room_id'] ?? 0);
        $checkin = (string) ($request['target_checkin'] ?? '');
        $checkout = (string) ($request['target_checkout'] ?? '');

        if ($reservationId <= 0 || $targetRoomTypeId <= 0) {
            return $this->failure(\__('A valid reservation and destination accommodation are required.', 'must-hotel-booking'));
        }

        if (\in_array($status, ['cancelled', 'completed', 'blocked', 'expired', 'payment_failed'], true) || ($checkedOutAt !== '' && $checkedOutAt !== '0000-00-00 00:00:00')) {
            return $this->failure(\__('Reservation cannot be amended from its current state.', 'must-hotel-booking'));
        }

        if (!AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout) || $checkout <= $checkin) {
            return $this->failure(\__('Enter valid amendment stay dates.', 'must-hotel-booking'));
        }

        if ($checkedInAt !== '' && $checkedInAt !== '0000-00-00 00:00:00' && $checkin !== (string) ($reservation['checkin'] ?? '')) {
            return $this->failure(\__('Check-in date cannot be changed after the guest has checked in.', 'must-hotel-booking'));
        }

        if (!AvailabilityEngine::checkBookingRestrictions($targetRoomTypeId, $checkin, $checkout)) {
            return $this->failure(\__('The proposed stay is blocked by booking restrictions for the destination accommodation.', 'must-hotel-booking'));
        }

        if (InventoryEngine::hasInventoryForRoomType($targetRoomTypeId)) {
            if ($targetAssignedRoomId <= 0) {
                $pureLocalUnassignment =
                    (string) ($reservation['provider'] ?? '') !== ProviderManager::CLOCK_MODE
                    && (int) ($reservation['assigned_room_id'] ?? 0) > 0
                    && ($checkedInAt === '' || $checkedInAt === '0000-00-00 00:00:00')
                    && $targetRoomTypeId === (int) ($reservation['room_type_id'] ?? $reservation['room_id'] ?? 0)
                    && (int) ($request['target_rate_plan_id'] ?? 0) === (int) ($reservation['rate_plan_id'] ?? 0)
                    && $checkin === (string) ($reservation['checkin'] ?? '')
                    && $checkout === (string) ($reservation['checkout'] ?? '');

                if (!$pureLocalUnassignment) {
                    return $this->failure(\__('Select a physical destination room for this accommodation.', 'must-hotel-booking'));
                }

                return [
                    'success' => true,
                    'queued' => false,
                    'no_change' => false,
                    'message' => '',
                ];
            }

            $targetRoom = $this->inventory->getInventoryRoomById($targetAssignedRoomId);

            if (!\is_array($targetRoom) || (int) ($targetRoom['room_type_id'] ?? 0) !== $targetRoomTypeId) {
                return $this->failure(\__('The destination room does not belong to the proposed accommodation.', 'must-hotel-booking'));
            }

            if (
                $targetAssignedRoomId !== (int) ($reservation['assigned_room_id'] ?? 0)
                && (
                    empty($targetRoom['is_active'])
                    || empty($targetRoom['is_bookable'])
                    || \sanitize_key((string) ($targetRoom['status'] ?? '')) !== 'available'
                )
            ) {
                return $this->failure(\__('The destination room is not active, bookable, and available.', 'must-hotel-booking'));
            }

            if ($this->reservations->hasAssignedRoomOverlapExcludingId(
                $reservationId,
                $targetAssignedRoomId,
                $this->availabilityCheckin($reservation, $checkin),
                $checkout,
                ReservationStatus::getInventoryNonBlockingStatuses()
            )) {
                return $this->failure(\__('The destination room is unavailable for the full proposed stay.', 'must-hotel-booking'));
            }

            if (
                $targetAssignedRoomId !== (int) ($reservation['assigned_room_id'] ?? 0)
                && LockEngine::isRoomLocked(
                    $targetAssignedRoomId,
                    $this->availabilityCheckin($reservation, $checkin),
                    $checkout
                )
            ) {
                return $this->failure(\__('The destination room is temporarily held by another booking flow.', 'must-hotel-booking'));
            }
        } elseif ($this->reservations->hasReservationOverlapExcludingId(
            $reservationId,
            $targetRoomTypeId,
            $checkin,
            $checkout,
            ReservationStatus::getInventoryNonBlockingStatuses()
        )) {
            return $this->failure(\__('The destination accommodation is unavailable for the full proposed stay.', 'must-hotel-booking'));
        }

        $noChange =
            $targetRoomTypeId === (int) ($reservation['room_type_id'] ?? $reservation['room_id'] ?? 0)
            && $targetAssignedRoomId === (int) ($reservation['assigned_room_id'] ?? 0)
            && (int) ($request['target_rate_plan_id'] ?? 0) === (int) ($reservation['rate_plan_id'] ?? 0)
            && $checkin === (string) ($reservation['checkin'] ?? '')
            && $checkout === (string) ($reservation['checkout'] ?? '');

        return [
            'success' => true,
            'queued' => false,
            'no_change' => $noChange,
            'message' => '',
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function availabilityCheckin(array $reservation, string $requestedCheckin): string
    {
        $checkedInAt = \trim((string) ($reservation['checked_in_at'] ?? ''));

        if ($checkedInAt === '' || $checkedInAt === '0000-00-00 00:00:00') {
            return $requestedCheckin;
        }

        $today = \current_time('Y-m-d');

        return $requestedCheckin > $today ? $requestedCheckin : $today;
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function localPricing(array $reservation, array $request): array
    {
        $samePriceContext =
            (int) ($request['target_room_type_id'] ?? 0) === (int) ($reservation['room_type_id'] ?? $reservation['room_id'] ?? 0)
            && (int) ($request['target_rate_plan_id'] ?? 0) === (int) ($reservation['rate_plan_id'] ?? 0)
            && (string) ($request['target_checkin'] ?? '') === (string) ($reservation['checkin'] ?? '')
            && (string) ($request['target_checkout'] ?? '') === (string) ($reservation['checkout'] ?? '');

        if ($samePriceContext) {
            return [
                'success' => true,
                'total_price' => (float) ($reservation['total_price'] ?? 0.0),
            ];
        }

        $pricing = PricingEngine::calculateTotal(
            (int) $request['target_room_type_id'],
            (string) $request['target_checkin'],
            (string) $request['target_checkout'],
            \max(1, (int) ($reservation['guests'] ?? 1)),
            (string) ($reservation['coupon_code'] ?? ''),
            (int) $request['target_rate_plan_id']
        );

        if (!\is_array($pricing) || empty($pricing['success']) || !isset($pricing['total_price'])) {
            return $this->failure(
                isset($pricing['message']) && \is_string($pricing['message']) && $pricing['message'] !== ''
                    ? $pricing['message']
                    : \__('Unable to calculate pricing for the proposed amendment.', 'must-hotel-booking')
            );
        }

        return $pricing;
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function normalizeRequest(array $reservation, array $request): array
    {
        return [
            'target_room_type_id' => isset($request['target_room_type_id'])
                ? \absint($request['target_room_type_id'])
                : (int) ($reservation['room_type_id'] ?? $reservation['room_id'] ?? 0),
            'target_assigned_room_id' => isset($request['target_assigned_room_id'])
                ? \absint($request['target_assigned_room_id'])
                : (int) ($reservation['assigned_room_id'] ?? 0),
            'target_rate_plan_id' => isset($request['target_rate_plan_id'])
                ? \absint($request['target_rate_plan_id'])
                : (int) ($reservation['rate_plan_id'] ?? 0),
            'target_checkin' => isset($request['target_checkin'])
                ? \sanitize_text_field((string) $request['target_checkin'])
                : (string) ($reservation['checkin'] ?? ''),
            'target_checkout' => isset($request['target_checkout'])
                ? \sanitize_text_field((string) $request['target_checkout'])
                : (string) ($reservation['checkout'] ?? ''),
        ];
    }

    /** @return array<int, string> */
    private function lockNames(int $reservationId, int $targetRoomId): array
    {
        $names = ['mhb-amend-reservation-' . $reservationId];

        if ($targetRoomId > 0) {
            $names[] = 'mhb-amend-room-' . $targetRoomId;
        }

        \sort($names, \SORT_STRING);

        return $names;
    }

    private function acquireLock(string $lockName): bool
    {
        global $wpdb;

        if (!isset($wpdb) || !\is_object($wpdb)) {
            return false;
        }

        $value = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName));

        return (string) $value === '1';
    }

    /** @param array<int, string> $lockNames */
    private function releaseLocks(array $lockNames): void
    {
        global $wpdb;

        if (!isset($wpdb) || !\is_object($wpdb)) {
            return;
        }

        foreach (\array_reverse($lockNames) as $lockName) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /** @return array{type: string, required: bool} */
    private function financialReview(float $priceDelta): array
    {
        if ($priceDelta > 0.009) {
            return ['type' => 'additional_payment_review_required', 'required' => true];
        }

        if ($priceDelta < -0.009) {
            return ['type' => 'refund_or_credit_review_required', 'required' => true];
        }

        return ['type' => 'none', 'required' => false];
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     */
    private function recordActivity(int $reservationId, array $reservation, array $request, string $source, float $priceDelta, string $idempotencyKey): void
    {
        $this->activity->createActivity([
            'event_type' => 'reservation_amended',
            'severity' => \abs($priceDelta) >= 0.01 ? 'warning' : 'info',
            'entity_type' => 'reservation',
            'entity_id' => $reservationId,
            'reference' => (string) ($reservation['booking_id'] ?? ''),
            'message' => \__('Reservation accommodation, room, rate, or stay dates were amended.', 'must-hotel-booking'),
            'context_json' => \wp_json_encode([
                'reservation_id' => $reservationId,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'target_room_type_id' => (int) $request['target_room_type_id'],
                'target_assigned_room_id' => (int) $request['target_assigned_room_id'],
                'target_rate_plan_id' => (int) $request['target_rate_plan_id'],
                'target_checkin' => (string) $request['target_checkin'],
                'target_checkout' => (string) $request['target_checkout'],
                'price_delta' => $priceDelta,
            ]),
            'actor_user_id' => \get_current_user_id(),
        ]);
    }

    /** @param mixed $metadata @return array<string, mixed> */
    private function decodeMetadata($metadata): array
    {
        if (\is_array($metadata)) {
            return $metadata;
        }

        if (!\is_string($metadata) || \trim($metadata) === '') {
            return [];
        }

        $decoded = \json_decode($metadata, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $request */
    private function idempotencyKey(int $reservationId, array $request): string
    {
        return 'mhb-reservation-amendment-' . \substr(\hash('sha256', $reservationId . '|' . \wp_json_encode($request)), 0, 48);
    }

    /** @return array<string, mixed> */
    private function failure(string $message, bool $retryable = false): array
    {
        return [
            'success' => false,
            'queued' => false,
            'retryable' => $retryable,
            'message' => $message,
        ];
    }
}
