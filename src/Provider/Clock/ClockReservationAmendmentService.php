<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;

final class ClockReservationAmendmentService
{
    /** @var ClockApiClient */
    private $client;

    /** @var ProviderMappingRepository */
    private $mappings;

    /** @var ProviderSyncJobRepository */
    private $jobs;

    /** @var ClockQuoteProvider */
    private $quote;

    public function __construct(
        ?ClockApiClient $client = null,
        ?ProviderMappingRepository $mappings = null,
        ?ProviderSyncJobRepository $jobs = null,
        ?ClockQuoteProvider $quote = null
    ) {
        $this->client = $client ?: new ClockApiClient();
        $this->mappings = $mappings ?: new ProviderMappingRepository();
        $this->jobs = $jobs ?: new ProviderSyncJobRepository();
        $this->quote = $quote ?: new ClockQuoteProvider();
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function amend(array $reservation, array $request, string $source = 'admin'): array
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $bookingId = $this->bookingId($reservation);

        if ($reservationId <= 0 || $bookingId === '') {
            return $this->failure(\__('Clock reservation is missing its local or provider identifier.', 'must-hotel-booking'));
        }

        $checkedInAt = \trim((string) ($reservation['checked_in_at'] ?? ''));
        $targetRoomId = (int) ($request['target_assigned_room_id'] ?? 0);
        $currentRoomId = (int) ($reservation['assigned_room_id'] ?? 0);
        $targetRoomTypeId = (int) ($request['target_room_type_id'] ?? 0);
        $currentRoomTypeId = (int) ($reservation['room_type_id'] ?? $reservation['room_id'] ?? 0);
        $targetRatePlanId = (int) ($request['target_rate_plan_id'] ?? 0);
        $currentRatePlanId = (int) ($reservation['rate_plan_id'] ?? 0);
        $isCheckedIn = $checkedInAt !== '' && $checkedInAt !== '0000-00-00 00:00:00';

        if (
            $isCheckedIn
            && (
                $targetRoomId !== $currentRoomId
                || $targetRoomTypeId !== $currentRoomTypeId
                || $targetRatePlanId !== $currentRatePlanId
            )
        ) {
            return $this->manualReviewFailure(
                $reservation,
                $request,
                \__('Clock documents booking arrival-room updates but does not document creating an in-house room-change entry. Move or upgrade the checked-in guest in Clock and refresh the mirror, or obtain Clock support confirmation for the required API operation.', 'must-hotel-booking'),
                $source
            );
        }

        $target = $this->resolveTargetMappings($request);

        if (empty($target['success'])) {
            return $target;
        }

        $remote = $this->fetchBooking($bookingId, $reservationId);

        if (empty($remote['success'])) {
            return $this->queueRetry($reservation, $request, $target, $source, (string) $remote['message'], false);
        }

        $idempotencyKey = $this->idempotencyKey($reservationId, $bookingId, $request, $target);
        $quote = $this->quoteTarget($reservation, $request);

        if ($this->remoteMatches($remote['booking'], $request, $target, !$isCheckedIn) && !empty($quote['success'])) {
            return $this->applyReread($reservation, $request, $target, $remote['booking'], $quote, $source, $idempotencyKey);
        }

        if (empty($quote['success'])) {
            return $quote;
        }

        $payload = $this->updatePayload($request, $target, $remote['booking'], !$isCheckedIn);
        $response = $this->client->request(
            'PUT',
            '/bookings/' . \rawurlencode($bookingId),
            [
                'body' => $payload,
                'idempotency_key' => $idempotencyKey,
                'reservation_id' => $reservationId,
                'external_id' => $bookingId,
                'api_type' => 'pms_api',
                'endpoint_name' => 'booking_update',
            ],
            'clock.booking.amendment'
        );

        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== ''
                ? $response->getErrorMessage()
                : \__('Clock rejected the booking amendment.', 'must-hotel-booking');
            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                return $this->queueRetry($reservation, $request, $target, $source, $message, true);
            }

            if ($statusCode <= 0 || $statusCode >= 500) {
                $confirmed = $this->fetchBooking($bookingId, $reservationId);
                if (
                    !empty($confirmed['success'])
                    && $this->remoteMatches($confirmed['booking'], $request, $target, !$isCheckedIn)
                ) {
                    return $this->applyReread(
                        $reservation,
                        $request,
                        $target,
                        $confirmed['booking'],
                        $quote,
                        $source,
                        $idempotencyKey
                    );
                }
            }

            return $this->manualReviewFailure($reservation, $request, $message, $source);
        }

        $reread = $this->fetchBooking($bookingId, $reservationId);

        if (empty($reread['success'])) {
            return $this->queueRetry(
                $reservation,
                $request,
                $target,
                $source,
                \__('Clock accepted the amendment, but the booking reread failed. Local state was not finalized.', 'must-hotel-booking'),
                true
            );
        }

        if (!$this->remoteMatches($reread['booking'], $request, $target, !$isCheckedIn)) {
            return $this->manualReviewFailure(
                $reservation,
                $request,
                \__('Clock reread did not confirm the requested room, room type, rate, or dates. Local assignment was preserved.', 'must-hotel-booking'),
                $source
            );
        }

        return $this->applyReread($reservation, $request, $target, $reread['booking'], $quote, $source, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $job
     * @return array{success: bool, retry: bool, message: string}
     */
    public function executeSyncJob(array $job): array
    {
        $payload = $this->decodeArray($job['payload'] ?? null);
        $reservationId = (int) ($job['target_local_id'] ?? 0);
        $reservation = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
        $request = isset($payload['request']) && \is_array($payload['request']) ? $payload['request'] : [];

        if (!\is_array($reservation) || (string) ($reservation['provider'] ?? '') !== ProviderManager::CLOCK_MODE || empty($request)) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock amendment retry target is invalid.', 'must-hotel-booking'),
            ];
        }

        $result = $this->amend($reservation, $request, (string) ($payload['source'] ?? 'provider_retry'));

        return [
            'success' => !empty($result['success']),
            'retry' => empty($result['success']) && (!empty($result['queued']) || !empty($result['retryable'])),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function resolveTargetMappings(array $request): array
    {
        $roomTypeId = (int) ($request['target_room_type_id'] ?? 0);
        $roomId = (int) ($request['target_assigned_room_id'] ?? 0);
        $ratePlanId = (int) ($request['target_rate_plan_id'] ?? 0);
        $roomTypeMapping = $this->mappings->findByLocal(ProviderManager::CLOCK_MODE, 'accommodation', $roomTypeId, 'must_rooms');
        $roomMapping = $roomId > 0
            ? $this->mappings->findByLocal(ProviderManager::CLOCK_MODE, 'physical_room', $roomId, 'mhb_rooms')
            : null;
        $rateMapping = $ratePlanId > 0
            ? $this->mappings->findByLocal(ProviderManager::CLOCK_MODE, 'rate_plan', $ratePlanId, 'mhb_rate_plans')
            : null;

        if (!\is_array($roomTypeMapping) || \trim((string) ($roomTypeMapping['external_id'] ?? '')) === '') {
            return $this->failure(\__('Clock room-type mapping is missing for the proposed accommodation.', 'must-hotel-booking'));
        }

        if ($roomId > 0 && (!\is_array($roomMapping) || \trim((string) ($roomMapping['external_id'] ?? '')) === '')) {
            return $this->failure(\__('Clock physical-room mapping is missing for the destination room.', 'must-hotel-booking'));
        }

        if ($ratePlanId > 0 && (!\is_array($rateMapping) || \trim((string) ($rateMapping['external_id'] ?? '')) === '')) {
            return $this->failure(\__('Clock rate mapping is missing for the proposed rate plan.', 'must-hotel-booking'));
        }

        return [
            'success' => true,
            'provider_room_type_id' => (string) $roomTypeMapping['external_id'],
            'provider_room_id' => \is_array($roomMapping) ? (string) ($roomMapping['external_id'] ?? '') : '',
            'provider_rate_id' => \is_array($rateMapping) ? (string) ($rateMapping['external_id'] ?? '') : '',
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function quoteTarget(array $reservation, array $request): array
    {
        $pricing = $this->quote->calculateTotal(
            (int) ($request['target_room_type_id'] ?? 0),
            (string) ($request['target_checkin'] ?? ''),
            (string) ($request['target_checkout'] ?? ''),
            \max(1, (int) ($reservation['guests'] ?? 1)),
            '',
            (int) ($request['target_rate_plan_id'] ?? 0)
        );

        if (!\is_array($pricing) || empty($pricing['success']) || !isset($pricing['total_price'])) {
            return $this->failure(
                isset($pricing['message']) && \is_string($pricing['message']) && $pricing['message'] !== ''
                    ? $pricing['message']
                    : \__('Clock did not return an available quoted product for the proposed amendment.', 'must-hotel-booking')
            );
        }

        return $pricing;
    }

    /** @return array<string, mixed> */
    private function fetchBooking(string $bookingId, int $reservationId): array
    {
        $response = $this->client->get(
            '/bookings/' . \rawurlencode($bookingId),
            [],
            'clock.booking.amendment_reread',
            [
                'reservation_id' => $reservationId,
                'external_id' => $bookingId,
                'api_type' => 'pms_api',
                'endpoint_name' => 'booking',
            ]
        );

        if (!$response->isSuccess()) {
            return $this->failure(
                $response->getErrorMessage() !== ''
                    ? $response->getErrorMessage()
                    : \__('Clock booking could not be reread.', 'must-hotel-booking'),
                true
            );
        }

        $booking = $this->bookingSource($response->getData());

        return !empty($booking)
            ? ['success' => true, 'booking' => $booking]
            : $this->failure(\__('Clock booking reread returned no usable booking object.', 'must-hotel-booking'), true);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $target
     * @param array<string, mixed> $remote
     * @return array<string, mixed>
     */
    private function updatePayload(array $request, array $target, array $remote, bool $includeAccommodationFields): array
    {
        $booking = [
            'arrival' => (string) $request['target_checkin'],
            'departure' => (string) $request['target_checkout'],
        ];

        if ($includeAccommodationFields) {
            $booking['arrival_room_type_id'] = $this->numericOrString((string) $target['provider_room_type_id']);
            if ((string) ($target['provider_room_id'] ?? '') !== '') {
                $booking['arrival_room_id'] = $this->numericOrString((string) $target['provider_room_id']);
            }
            if ((string) ($target['provider_rate_id'] ?? '') !== '') {
                $booking['rate_id'] = $this->numericOrString((string) $target['provider_rate_id']);
            }
        }
        if (isset($remote['lock_version']) && \is_numeric($remote['lock_version'])) {
            $booking['lock_version'] = (int) $remote['lock_version'];
        }

        return ['booking' => $booking];
    }

    /**
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $request
     * @param array<string, mixed> $target
     */
    private function remoteMatches(array $remote, array $request, array $target, bool $compareAccommodationFields): bool
    {
        $datesMatch = (string) ($remote['arrival'] ?? '') === (string) $request['target_checkin']
            && (string) ($remote['departure'] ?? '') === (string) $request['target_checkout'];

        if (!$datesMatch || !$compareAccommodationFields) {
            return $datesMatch;
        }

        return (string) ($remote['arrival_room_type_id'] ?? '') === (string) $target['provider_room_type_id']
            && (
                (string) ($target['provider_room_id'] ?? '') === ''
                || (string) ($remote['arrival_room_id'] ?? $remote['current_room_id'] ?? '') === (string) $target['provider_room_id']
            )
            && (
                (string) ($target['provider_rate_id'] ?? '') === ''
                || (string) ($remote['rate_id'] ?? '') === (string) $target['provider_rate_id']
            );
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @param array<string, mixed> $target
     * @param array<string, mixed> $remote
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function applyReread(
        array $reservation,
        array $request,
        array $target,
        array $remote,
        array $quote,
        string $source,
        string $idempotencyKey
    ): array {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $metadata = $this->decodeArray($reservation['provider_metadata'] ?? null);
        $lastAmendment = isset($metadata['last_reservation_amendment']) && \is_array($metadata['last_reservation_amendment'])
            ? $metadata['last_reservation_amendment']
            : [];

        if (
            (string) ($lastAmendment['idempotency_key'] ?? '') === $idempotencyKey
            && (string) ($lastAmendment['status'] ?? '') === 'completed'
            && $this->localMatches($reservation, $request)
        ) {
            return [
                'success' => true,
                'queued' => false,
                'no_change' => true,
                'message' => \__('Clock amendment was already reconciled locally.', 'must-hotel-booking'),
                'old_total' => (float) ($lastAmendment['old_total'] ?? $reservation['total_price'] ?? 0.0),
                'new_total' => (float) ($lastAmendment['new_total'] ?? $reservation['total_price'] ?? 0.0),
                'price_delta' => (float) ($lastAmendment['price_delta'] ?? 0.0),
                'financial_review_type' => (string) ($lastAmendment['financial_review_type'] ?? 'none'),
                'manual_review_required' => !empty($metadata['manual_review_required']),
            ];
        }

        $oldTotal = \round((float) ($reservation['total_price'] ?? 0.0), 2);
        $providerTotal = $this->moneyAmount($remote['total_booking_value'] ?? null);
        $newTotal = $providerTotal !== null ? $providerTotal : \round((float) ($quote['total_price'] ?? $oldTotal), 2);
        $priceDelta = \round($newTotal - $oldTotal, 2);
        $reviewType = $priceDelta > 0.009
            ? 'additional_payment_review_required'
            : ($priceDelta < -0.009 ? 'refund_or_credit_review_required' : 'none');
        $metadata['last_reservation_amendment'] = [
            'idempotency_key' => $idempotencyKey,
            'source' => $source,
            'status' => 'completed',
            'provider_confirmed' => true,
            'provider_booking_id' => $this->bookingId($reservation),
            'provider_room_type_id' => (string) $target['provider_room_type_id'],
            'provider_room_id' => (string) ($target['provider_room_id'] ?? ''),
            'provider_rate_id' => (string) ($target['provider_rate_id'] ?? ''),
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'price_delta' => $priceDelta,
            'pricing_source' => $providerTotal !== null ? 'clock_booking_reread' : 'clock_quote',
            'financial_review_type' => $reviewType,
            'completed_at' => \current_time('mysql'),
        ];
        $metadata['provider_amendment_required'] = false;
        $metadata['manual_review_required'] = $reviewType !== 'none';
        $metadata['additional_payment_review_required'] = $reviewType === 'additional_payment_review_required';
        $metadata['refund_or_credit_review_required'] = $reviewType === 'refund_or_credit_review_required';

        $updated = \MustHotelBooking\Engine\get_reservation_repository()->updateReservation($reservationId, [
            'room_id' => (int) $request['target_room_type_id'],
            'room_type_id' => (int) $request['target_room_type_id'],
            'assigned_room_id' => (int) $request['target_assigned_room_id'],
            'rate_plan_id' => (int) $request['target_rate_plan_id'],
            'checkin' => (string) $request['target_checkin'],
            'checkout' => (string) $request['target_checkout'],
            'total_price' => $newTotal,
            'provider_sync_status' => 'synced',
            'provider_synced_at' => \current_time('mysql'),
            'provider_sync_error' => '',
            'provider_metadata' => \wp_json_encode($metadata),
        ]);

        if (!$updated) {
            return $this->queueRetry(
                $reservation,
                $request,
                $target,
                $source,
                \__('Clock confirmed the amendment, but the local mirror update failed.', 'must-hotel-booking'),
                true
            );
        }

        $this->recordActivity($reservationId, $reservation, $request, $source, $priceDelta, $idempotencyKey);

        return [
            'success' => true,
            'queued' => false,
            'message' => \__('Clock amendment completed and the provider reread matches WordPress.', 'must-hotel-booking'),
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'price_delta' => $priceDelta,
            'financial_review_type' => $reviewType,
            'manual_review_required' => $reviewType !== 'none',
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     */
    private function localMatches(array $reservation, array $request): bool
    {
        return (int) ($reservation['room_type_id'] ?? $reservation['room_id'] ?? 0) === (int) ($request['target_room_type_id'] ?? 0)
            && (int) ($reservation['assigned_room_id'] ?? 0) === (int) ($request['target_assigned_room_id'] ?? 0)
            && (int) ($reservation['rate_plan_id'] ?? 0) === (int) ($request['target_rate_plan_id'] ?? 0)
            && (string) ($reservation['checkin'] ?? '') === (string) ($request['target_checkin'] ?? '')
            && (string) ($reservation['checkout'] ?? '') === (string) ($request['target_checkout'] ?? '');
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @param array<string, mixed> $target
     * @return array<string, mixed>
     */
    private function queueRetry(array $reservation, array $request, array $target, string $source, string $message, bool $writeMayHaveSucceeded): array
    {
        $reservationId = (int) ($reservation['id'] ?? 0);
        $bookingId = $this->bookingId($reservation);
        $idempotencyKey = $this->idempotencyKey($reservationId, $bookingId, $request, $target);
        $jobId = $this->jobs->enqueueOnce([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => 'reservation_amendment',
            'target_type' => 'reservation',
            'target_local_id' => $reservationId,
            'target_external_id' => $bookingId . '#amend-' . \substr(\sha1(\wp_json_encode($request)), 0, 20),
            'status' => ProviderSyncJobRepository::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 5,
            'priority' => 4,
            'last_error' => $message,
            'payload' => [
                'source' => $source,
                'request' => $request,
                'target' => $target,
                'idempotency_key' => $idempotencyKey,
                'provider_write_may_have_succeeded' => $writeMayHaveSucceeded,
            ],
        ]);
        $this->updateFailureMetadata($reservation, $request, $message, $source, $idempotencyKey, 'pending_retry');

        return [
            'success' => false,
            'queued' => $jobId > 0,
            'retryable' => true,
            'message' => $jobId > 0
                ? \__('Clock amendment could not be confirmed immediately. A reread-first reconciliation job was queued.', 'must-hotel-booking')
                : $message,
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function manualReviewFailure(array $reservation, array $request, string $message, string $source): array
    {
        $target = $this->resolveTargetMappings($request);
        $idempotencyKey = $this->idempotencyKey(
            (int) ($reservation['id'] ?? 0),
            $this->bookingId($reservation),
            $request,
            !empty($target['success']) ? $target : []
        );
        $this->updateFailureMetadata($reservation, $request, $message, $source, $idempotencyKey, 'manual_review');

        return [
            'success' => false,
            'queued' => false,
            'manual_review_required' => true,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     */
    private function updateFailureMetadata(array $reservation, array $request, string $message, string $source, string $idempotencyKey, string $status): void
    {
        $metadata = $this->decodeArray($reservation['provider_metadata'] ?? null);
        $metadata['last_reservation_amendment'] = [
            'idempotency_key' => $idempotencyKey,
            'source' => $source,
            'status' => $status,
            'target' => $request,
            'error' => \sanitize_text_field($message),
            'updated_at' => \current_time('mysql'),
        ];
        $metadata['provider_amendment_required'] = true;
        if ($status === 'manual_review') {
            $metadata['manual_review_required'] = true;
        }
        \MustHotelBooking\Engine\get_reservation_repository()->updateProviderMetadata((int) ($reservation['id'] ?? 0), [
            'provider_sync_status' => $status,
            'provider_synced_at' => null,
            'provider_sync_error' => \sanitize_text_field($message),
            'provider_metadata' => $metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $request
     */
    private function recordActivity(int $reservationId, array $reservation, array $request, string $source, float $priceDelta, string $idempotencyKey): void
    {
        \MustHotelBooking\Engine\get_activity_repository()->createActivity([
            'event_type' => 'clock_reservation_amended',
            'severity' => \abs($priceDelta) >= 0.01 ? 'warning' : 'info',
            'entity_type' => 'reservation',
            'entity_id' => $reservationId,
            'reference' => (string) ($reservation['booking_id'] ?? ''),
            'message' => \__('Clock booking amendment was confirmed by provider reread.', 'must-hotel-booking'),
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

    /** @param mixed $data @return array<string, mixed> */
    private function bookingSource($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        foreach (['booking', 'reservation', 'data', 'result', 'object'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->bookingSource($data[$key]);
            }
        }

        return $data;
    }

    /** @param mixed $value @return array<string, mixed> */
    private function decodeArray($value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || \trim($value) === '') {
            return [];
        }

        $decoded = \json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param mixed $money */
    private function moneyAmount($money): ?float
    {
        if (\is_numeric($money)) {
            return \round((float) $money, 2);
        }

        if (!\is_array($money)) {
            return null;
        }

        if (isset($money['cents']) && \is_numeric($money['cents'])) {
            return \round(((int) $money['cents']) / 100, 2);
        }

        if (isset($money['amount']) && \is_numeric($money['amount'])) {
            return \round((float) $money['amount'], 2);
        }

        return null;
    }

    private function bookingId(array $reservation): string
    {
        $bookingId = \trim((string) ($reservation['provider_booking_id'] ?? ''));

        return $bookingId !== '' ? $bookingId : \trim((string) ($reservation['provider_reservation_id'] ?? ''));
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $target */
    private function idempotencyKey(int $reservationId, string $bookingId, array $request, array $target): string
    {
        return 'mhb-clock-amend-' . \substr(\hash('sha256', $reservationId . '|' . $bookingId . '|' . \wp_json_encode($request) . '|' . \wp_json_encode($target)), 0, 48);
    }

    /** @return int|string */
    private function numericOrString(string $value)
    {
        return \ctype_digit($value) ? (int) $value : $value;
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
