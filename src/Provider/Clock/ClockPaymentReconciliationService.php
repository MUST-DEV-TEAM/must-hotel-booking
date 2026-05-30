<?php
namespace MustHotelBooking\Provider\Clock;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;
final class ClockPaymentReconciliationService
{
    /** @var ClockApiClient */
    private $client;
    /** @var ProviderSyncJobRepository */
    private $syncJobs;
    /** @var ProviderRequestLogRepository */
    private $requestLogs;
    /** @var ClockCatalogService */
    private $catalog;
    /** @var ClockQuoteProvider */
    private $quote;
    public function __construct(
        ?ClockApiClient $client = null,
        ?ProviderSyncJobRepository $syncJobs = null,
        ?ProviderRequestLogRepository $requestLogs = null,
        ?ClockCatalogService $catalog = null,
        ?ClockQuoteProvider $quote = null
    ) {
        $this->requestLogs = $requestLogs ?: new ProviderRequestLogRepository();
        $this->client = $client ?: new ClockApiClient($this->requestLogs);
        $this->syncJobs = $syncJobs ?: new ProviderSyncJobRepository();
        $this->catalog = $catalog ?: new ClockCatalogService($this->client);
        $this->quote = $quote ?: new ClockQuoteProvider($this->client, $this->catalog);
    }
    /** @param array<int, int> $reservationIds */
    public function reconcilePaymentSucceeded(array $reservationIds, string $paymentMethod = 'stripe', string $transactionId = ''): void
    {
        if (!$this->directReservationUpdateSupported()) {
            return;
        }
        foreach ($this->clockReservationRows($reservationIds) as $row) {
            $this->reconcileRow($row, [
                'operation' => 'payment_succeeded',
                'target_provider_status' => 'confirmed',
                'target_provider_payment_status' => 'paid',
                'local_payment_method' => \sanitize_key($paymentMethod),
                'transaction_id' => \sanitize_text_field($transactionId),
                'endpoint_path' => ClockConfig::reservationStatusUpdatePath(),
                'request_operation' => 'clock.reservation_payment_update',
                'retry_operation' => 'reservation_payment_update',
                'missing_endpoint_retry' => false,
                'missing_endpoint_status' => 'local_only',
                'missing_endpoint_message' => \__('Clock reservation status update endpoint is not configured; local payment status was recorded only in the mirror reservation.', 'must-hotel-booking'),
            ]);
        }
    }
    /** @param array<int, int> $reservationIds */
    public function reconcilePaymentFailed(array $reservationIds, string $reason = 'payment_failed', string $paymentMethod = 'stripe'): void
    {
        if (!$this->directReservationUpdateSupported()) {
            return;
        }
        $reason = \sanitize_key($reason);
        if (!\in_array($reason, ['payment_failed', 'expired', 'cancelled'], true)) {
            $reason = 'payment_failed';
        }
        foreach ($this->clockReservationRows($reservationIds) as $row) {
            $this->reconcileRow($row, [
                'operation' => $reason,
                'target_provider_status' => $reason === 'expired' ? 'expired' : 'cancelled',
                'target_provider_payment_status' => 'cancelled',
                'local_payment_method' => \sanitize_key($paymentMethod),
                'transaction_id' => '',
                'endpoint_path' => ClockConfig::reservationCancelPath(),
                'request_operation' => 'clock.reservation_cancel',
                'retry_operation' => 'reservation_cancel',
                'missing_endpoint_retry' => true,
                'missing_endpoint_status' => 'pending_retry',
                'missing_endpoint_message' => \__('Clock reservation cancel endpoint is not configured; provider cancellation was queued for later reconciliation.', 'must-hotel-booking'),
            ]);
        }
    }
    /**
     * @return array{success: bool, queued: bool, message: string}
     */
    public function cancelReservation(int $reservationId, string $reason = 'admin_cancelled', string $source = 'admin'): array
    {
        if (!$this->directReservationUpdateSupported()) {
            return $this->unsupportedActionResult();
        }
        $rows = $this->clockReservationRows([$reservationId]);
        $row = isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : [];
        if (empty($row)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        $currentStatus = \sanitize_key((string) ($row['status'] ?? ''));
        if (\in_array($currentStatus, ['cancelled', 'blocked', 'completed'], true)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation cannot be cancelled from its current state.', 'must-hotel-booking'),
            ];
        }
        $targetProviderPaymentStatus = (string) ($row['provider_payment_status'] ?? '');
        if ($targetProviderPaymentStatus === '') {
            $targetProviderPaymentStatus = (string) ($row['payment_status'] ?? '');
        }
        if ($targetProviderPaymentStatus === '') {
            $targetProviderPaymentStatus = 'unpaid';
        }
        return $this->reconcileRow($row, [
            'operation' => \sanitize_key($reason) !== '' ? \sanitize_key($reason) : 'admin_cancelled',
            'target_provider_status' => 'cancelled',
            'target_provider_payment_status' => $targetProviderPaymentStatus,
            'target_local_status' => 'cancelled',
            'target_local_payment_status' => (string) ($row['payment_status'] ?? ''),
            'local_payment_method' => '',
            'transaction_id' => '',
            'endpoint_path' => ClockConfig::reservationCancelPath(),
            'request_operation' => 'clock.reservation_cancel',
            'retry_operation' => 'reservation_cancel',
            'metadata_key' => 'last_provider_cancellation',
            'required_flag' => 'provider_cancellation_required',
            'source' => $source === 'portal' ? 'must_hotel_booking_portal' : 'must_hotel_booking_admin',
            'missing_endpoint_retry' => true,
            'missing_endpoint_status' => 'pending_retry',
            'missing_endpoint_message' => \__('Clock reservation cancel endpoint is not configured; provider cancellation was queued for later reconciliation.', 'must-hotel-booking'),
        ]);
    }
    /**
     * @return array{success: bool, queued: bool, message: string}
     */
    public function checkInReservation(int $reservationId, string $source = 'admin'): array
    {
        if (!$this->directReservationUpdateSupported()) {
            return $this->unsupportedActionResult();
        }
        $rows = $this->clockReservationRows([$reservationId]);
        $row = isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : [];
        if (empty($row)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        $status = \sanitize_key((string) ($row['status'] ?? ''));
        $checkedInAt = \trim((string) ($row['checked_in_at'] ?? ''));
        if (!\in_array($status, ['confirmed', 'pending', 'pending_payment'], true)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation cannot be checked in from its current state.', 'must-hotel-booking'),
            ];
        }
        if ($checkedInAt !== '' && $checkedInAt !== '0000-00-00 00:00:00') {
            return [
                'success' => true,
                'queued' => false,
                'message' => \__('Reservation is already checked in.', 'must-hotel-booking'),
            ];
        }
        return $this->reconcileRow($row, $this->operationalAction(
            'checkin',
            'checked_in',
            '',
            'now',
            $source,
            $this->operationalPaymentStatus($row)
        ));
    }
    /**
     * @return array{success: bool, queued: bool, message: string}
     */
    public function checkOutReservation(int $reservationId, string $source = 'admin'): array
    {
        if (!$this->directReservationUpdateSupported()) {
            return $this->unsupportedActionResult();
        }
        $rows = $this->clockReservationRows([$reservationId]);
        $row = isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : [];
        if (empty($row)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        $status = \sanitize_key((string) ($row['status'] ?? ''));
        $checkedInAt = \trim((string) ($row['checked_in_at'] ?? ''));
        $checkedOutAt = \trim((string) ($row['checked_out_at'] ?? ''));
        if ($status !== 'confirmed') {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation cannot be checked out from its current state.', 'must-hotel-booking'),
            ];
        }
        if ($checkedInAt === '' || $checkedInAt === '0000-00-00 00:00:00') {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation must be checked in before check-out.', 'must-hotel-booking'),
            ];
        }
        if ($checkedOutAt !== '' && $checkedOutAt !== '0000-00-00 00:00:00') {
            return [
                'success' => true,
                'queued' => false,
                'message' => \__('Reservation is already checked out.', 'must-hotel-booking'),
            ];
        }
        return $this->reconcileRow($row, $this->operationalAction(
            'checkout',
            'checked_out',
            'completed',
            '',
            $source,
            $this->operationalPaymentStatus($row)
        ));
    }
    /**
     * @return array{success: bool, queued: bool, message: string}
     */
    public function assignRoom(int $reservationId, int $targetRoomId, string $source = 'admin'): array
    {
        if (!$this->directReservationUpdateSupported()) {
            return $this->unsupportedActionResult();
        }
        $row = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
        if (!\is_array($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        if ($targetRoomId <= 0) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Clock room assignment requires a mapped target room.', 'must-hotel-booking'),
            ];
        }
        $status = \sanitize_key((string) ($row['status'] ?? ''));
        $checkedOutAt = \trim((string) ($row['checked_out_at'] ?? ''));
        if (\in_array($status, ['cancelled', 'completed', 'blocked'], true) || ($checkedOutAt !== '' && $checkedOutAt !== '0000-00-00 00:00:00')) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation cannot be assigned or moved from its current state.', 'must-hotel-booking'),
            ];
        }
        $roomTypeId = isset($row['room_type_id']) ? (int) $row['room_type_id'] : (int) ($row['room_id'] ?? 0);
        $inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $targetRoom = $inventoryRepository->getInventoryRoomById($targetRoomId);
        if (!\is_array($targetRoom)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Target room was not found.', 'must-hotel-booking'),
            ];
        }
        $targetRoomTypeId = isset($targetRoom['room_type_id']) ? (int) $targetRoom['room_type_id'] : 0;
        if ($roomTypeId > 0 && $targetRoomTypeId > 0 && $targetRoomTypeId !== $roomTypeId) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Target room does not match the reservation accommodation.', 'must-hotel-booking'),
            ];
        }
        $mapping = $this->catalog->findPhysicalRoomMapping($targetRoomId);
        if (!$this->hasExternalId($mapping)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Clock physical room mapping is missing for the selected room.', 'must-hotel-booking'),
            ];
        }
        if (!$this->isRoomAvailableForReservation($row, $targetRoomId, $roomTypeId)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Target room is not available for this reservation window.', 'must-hotel-booking'),
            ];
        }
        return $this->reconcileRow($row, $this->roomAssignmentAction(
            $row,
            $targetRoom,
            $mapping,
            $source
        ));
    }
    /**
     * @return array{success: bool, queued: bool, message: string}
     */
    public function updateStayDates(int $reservationId, string $checkin, string $checkout, string $source = 'admin'): array
    {
        if (!$this->directReservationUpdateSupported()) {
            return $this->unsupportedActionResult();
        }
        $row = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
        if (!\is_array($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        $checkin = \sanitize_text_field($checkin);
        $checkout = \sanitize_text_field($checkout);
        if (
            !\MustHotelBooking\Engine\AvailabilityEngine::isValidBookingDate($checkin)
            || !\MustHotelBooking\Engine\AvailabilityEngine::isValidBookingDate($checkout)
            || $checkout <= $checkin
        ) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Enter valid stay dates.', 'must-hotel-booking'),
            ];
        }
        $status = \sanitize_key((string) ($row['status'] ?? ''));
        $checkedInAt = \trim((string) ($row['checked_in_at'] ?? ''));
        $checkedOutAt = \trim((string) ($row['checked_out_at'] ?? ''));
        if (\in_array($status, ['cancelled', 'completed', 'blocked', 'expired', 'payment_failed'], true) || ($checkedOutAt !== '' && $checkedOutAt !== '0000-00-00 00:00:00')) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation cannot be edited from its current state.', 'must-hotel-booking'),
            ];
        }
        if (
            $checkedInAt !== ''
            && $checkedInAt !== '0000-00-00 00:00:00'
            && $checkin !== (string) ($row['checkin'] ?? '')
        ) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Check-in date cannot be changed after the guest has already checked in.', 'must-hotel-booking'),
            ];
        }
        if ($checkin === (string) ($row['checkin'] ?? '') && $checkout === (string) ($row['checkout'] ?? '')) {
            return [
                'success' => true,
                'queued' => false,
                'message' => \__('Stay dates are already up to date.', 'must-hotel-booking'),
            ];
        }
        $roomTypeId = isset($row['room_type_id']) ? (int) $row['room_type_id'] : (int) ($row['room_id'] ?? 0);
        if ($roomTypeId <= 0) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation is missing its accommodation context.', 'must-hotel-booking'),
            ];
        }
        if (!\MustHotelBooking\Engine\AvailabilityEngine::checkBookingRestrictions($roomTypeId, $checkin, $checkout)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('The selected stay dates are blocked by current booking rules for this accommodation.', 'must-hotel-booking'),
            ];
        }
        $availabilityError = $this->stayAvailabilityError($row, $roomTypeId, $checkin, $checkout);
        if ($availabilityError !== '') {
            return [
                'success' => false,
                'queued' => false,
                'message' => $availabilityError,
            ];
        }
        return $this->reconcileRow($row, $this->stayUpdateAction(
            $row,
            $checkin,
            $checkout,
            $source
        ));
    }
    /**
     * @param array<string, mixed> $guestData
     * @return array{success: bool, queued: bool, message: string}
     */
    public function updateGuestDetails(int $reservationId, array $guestData, string $source = 'admin'): array
    {
        if (!$this->directReservationUpdateSupported()) {
            return $this->unsupportedActionResult();
        }
        $row = \MustHotelBooking\Engine\get_reservation_repository()->getAdminReservationDetails($reservationId);
        if (!\is_array($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        $status = \sanitize_key((string) ($row['status'] ?? ''));
        if (\in_array($status, ['cancelled', 'completed', 'blocked', 'expired', 'payment_failed'], true)) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Reservation guest details cannot be edited from its current state.', 'must-hotel-booking'),
            ];
        }
        $guest = $this->sanitizeGuestUpdate($guestData);
        if ((string) ($guest['email'] ?? '') !== '' && !\is_email((string) $guest['email'])) {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Please enter a valid guest email address.', 'must-hotel-booking'),
            ];
        }
        if ($this->guestDetailsMatch($row, $guest)) {
            return [
                'success' => true,
                'queued' => false,
                'message' => \__('Guest details are already up to date.', 'must-hotel-booking'),
            ];
        }
        return $this->reconcileRow($row, $this->guestUpdateAction($row, $guest, $source));
    }
    /**
     * @param array<string, mixed> $job
     * @return array{success: bool, retry: bool, message: string}
     */
    public function executeSyncJob(array $job): array
    {
        if (!$this->directReservationUpdateSupported()) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Direct Clock API reservation sync/update adapters are not implemented yet.', 'must-hotel-booking'),
            ];
        }
        $operation = isset($job['operation']) ? \sanitize_key((string) $job['operation']) : '';
        $reservationId = isset($job['target_local_id']) ? (int) $job['target_local_id'] : 0;
        $payload = $this->decodePayload($job['payload'] ?? null);
        if ($operation === 'reservation_pricing_refresh') {
            return $this->executePricingRefreshJob($job, $payload);
        }
        $action = $this->actionForSyncJob($operation, $payload);
        if (empty($action)) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Unsupported Clock sync job operation.', 'must-hotel-booking'),
            ];
        }
        if ($reservationId <= 0) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock sync job is missing a local reservation ID.', 'must-hotel-booking'),
            ];
        }
        $rows = \MustHotelBooking\Engine\get_reservation_repository()->getProviderReservationRowsByIds([$reservationId]);
        $row = isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : [];
        if (empty($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock sync job target reservation is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        $externalId = $this->externalId($row);
        if ($externalId === '') {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock sync job target reservation has no provider identifier.', 'must-hotel-booking'),
            ];
        }
        $targetProviderStatus = (string) ($action['target_provider_status'] ?? '');
        $targetProviderPaymentStatus = (string) ($action['target_provider_payment_status'] ?? '');
        $idempotencyContext = (string) ($action['idempotency_context'] ?? '');
        $idempotencyKey = isset($payload['idempotency_key']) && \is_scalar($payload['idempotency_key'])
            ? \sanitize_text_field((string) $payload['idempotency_key'])
            : $this->idempotencyKey($reservationId, $externalId, $targetProviderStatus, $targetProviderPaymentStatus, $idempotencyContext);
        if ($this->isSupersededSyncJob($row, $action, $idempotencyKey)) {
            return [
                'success' => true,
                'retry' => false,
                'message' => \__('Clock sync job was superseded by a newer provider operation request.', 'must-hotel-booking'),
            ];
        }
        if ($this->isAlreadyReconciled($row, $action, $idempotencyKey)) {
            return [
                'success' => true,
                'retry' => false,
                'message' => \__('Clock sync job was already reconciled.', 'must-hotel-booking'),
            ];
        }
        $path = (string) ($action['endpoint_path'] ?? '');
        $payload = $this->ensurePayload($payload, $row, $action, $idempotencyKey);
        if ($path === '') {
            $message = \__('Clock reconciliation endpoint is not configured.', 'must-hotel-booking');
            $this->recordSkippedLog($row, $action, $idempotencyKey);
            $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, false, 'pending_retry', $message);
            return [
                'success' => false,
                'retry' => true,
                'message' => $message,
            ];
        }
        $endpoint = $this->endpointMethodAndPath($path);
        $resolvedPath = $this->applyPathTokens($endpoint['path'], $row, $action);
        if ($resolvedPath === '') {
            $message = \__('Clock reconciliation endpoint path contains an unresolved token; the reservation may be missing a required provider identifier.', 'must-hotel-booking');
            $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, false, 'pending_retry', $message);
            return [
                'success' => false,
                'retry' => false,
                'message' => $message,
            ];
        }
        $responseOptions = [
            'idempotency_key' => $idempotencyKey,
            'reservation_id' => $reservationId,
            'external_id' => $externalId,
        ];
        if (!\in_array($endpoint['method'], ['GET', 'DELETE'], true)) {
            $responseOptions['body'] = $this->providerRequestBody($payload, $action);
        }
        $response = $this->client->request(
            $endpoint['method'],
            $resolvedPath,
            $responseOptions,
            (string) ($action['request_operation'] ?? 'clock.reservation_status')
        );
        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== '' ? $response->getErrorMessage() : \__('Clock reconciliation request failed.', 'must-hotel-booking');
            $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, false, 'pending_retry', $message);
            return [
                'success' => false,
                'retry' => true,
                'message' => $message,
            ];
        }
        $providerState = $this->providerState($response->getData());
        $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, true, 'synced', '', $providerState);
        $pricingResult = $this->refreshPricingAfterStayUpdate($reservationId, $row, $action, $idempotencyKey, true);
        return [
            'success' => true,
            'retry' => false,
            'message' => !empty($pricingResult['pricing_pending']) ? (string) ($pricingResult['message'] ?? '') : '',
        ];
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     * @return array{success: bool, queued: bool, message: string}
     */
    private function reconcileRow(array $row, array $action): array
    {
        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        $externalId = $this->externalId($row);
        if ($reservationId <= 0 || $externalId === '') {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Clock reservation is missing a local or provider identifier.', 'must-hotel-booking'),
            ];
        }
        $targetProviderStatus = (string) ($action['target_provider_status'] ?? '');
        $targetProviderPaymentStatus = (string) ($action['target_provider_payment_status'] ?? '');
        $idempotencyKey = $this->idempotencyKey(
            $reservationId,
            $externalId,
            $targetProviderStatus,
            $targetProviderPaymentStatus,
            (string) ($action['idempotency_context'] ?? '')
        );
        if ($this->isAlreadyReconciled($row, $action, $idempotencyKey)) {
            return [
                'success' => true,
                'queued' => false,
                'message' => \__('Clock reservation was already reconciled.', 'must-hotel-booking'),
            ];
        }
        $path = (string) ($action['endpoint_path'] ?? '');
        $payload = $this->payload($row, $action, $idempotencyKey);
        if ($path === '') {
            $this->recordSkippedLog($row, $action, $idempotencyKey);
            $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, false, (string) $action['missing_endpoint_status'], (string) $action['missing_endpoint_message']);
            if (!empty($action['missing_endpoint_retry'])) {
                $this->enqueueRetry($row, $action, $payload, (string) $action['missing_endpoint_message']);
            }
            return [
                'success' => false,
                'queued' => !empty($action['missing_endpoint_retry']),
                'message' => (string) $action['missing_endpoint_message'],
            ];
        }
        $endpoint = $this->endpointMethodAndPath($path);
        $resolvedPath = $this->applyPathTokens($endpoint['path'], $row, $action);
        if ($resolvedPath === '') {
            $message = \__('Clock reconciliation endpoint path contains an unresolved token; the reservation may be missing a required provider identifier.', 'must-hotel-booking');
            $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, false, 'pending_retry', $message);
            return [
                'success' => false,
                'queued' => false,
                'message' => $message,
            ];
        }
        $responseOptions = [
            'idempotency_key' => $idempotencyKey,
            'reservation_id' => $reservationId,
            'external_id' => $externalId,
        ];
        if (!\in_array($endpoint['method'], ['GET', 'DELETE'], true)) {
            $responseOptions['body'] = $this->providerRequestBody($payload, $action);
        }
        $response = $this->client->request(
            $endpoint['method'],
            $resolvedPath,
            $responseOptions,
            (string) ($action['request_operation'] ?? 'clock.reservation_status')
        );
        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== '' ? $response->getErrorMessage() : \__('Clock reconciliation request failed.', 'must-hotel-booking');
            $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, false, 'pending_retry', $message);
            $this->enqueueRetry($row, $action, $payload, $message);
            return [
                'success' => false,
                'queued' => true,
                'message' => $message,
            ];
        }
        $providerState = $this->providerState($response->getData());
        $this->updateMetadata($reservationId, $row, $action, $idempotencyKey, true, 'synced', '', $providerState);
        $pricingResult = $this->refreshPricingAfterStayUpdate($reservationId, $row, $action, $idempotencyKey, true);
        return [
            'success' => true,
            'queued' => !empty($pricingResult['queued']),
            'message' => !empty($pricingResult['pricing_pending']) ? (string) ($pricingResult['message'] ?? '') : '',
            'pricing_pending' => !empty($pricingResult['pricing_pending']),
        ];
    }
    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $payload
     * @return array{success: bool, retry: bool, message: string}
     */
    private function executePricingRefreshJob(array $job, array $payload): array
    {
        $reservationId = isset($job['target_local_id']) ? (int) $job['target_local_id'] : 0;
        if ($reservationId <= 0) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock pricing refresh job is missing a local reservation ID.', 'must-hotel-booking'),
            ];
        }
        $row = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
        if (!\is_array($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return [
                'success' => false,
                'retry' => false,
                'message' => \__('Clock pricing refresh job target is not a Clock mirror reservation.', 'must-hotel-booking'),
            ];
        }
        $checkin = $this->payloadString($payload, 'local_target_checkin', (string) ($row['checkin'] ?? ''));
        $checkout = $this->payloadString($payload, 'local_target_checkout', (string) ($row['checkout'] ?? ''));
        if ($checkin !== (string) ($row['checkin'] ?? '') || $checkout !== (string) ($row['checkout'] ?? '')) {
            return [
                'success' => true,
                'retry' => false,
                'message' => \__('Clock pricing refresh job was superseded by newer mirror dates.', 'must-hotel-booking'),
            ];
        }
        $idempotencyKey = $this->payloadString($payload, 'idempotency_key', $this->pricingIdempotencyKey($row, $checkin, $checkout));
        $result = $this->refreshPricingForRow($row, $checkin, $checkout, $idempotencyKey, false);
        return [
            'success' => !empty($result['success']),
            'retry' => empty($result['success']),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     * @return array{success: bool, queued: bool, message: string, pricing_pending: bool}
     */
    private function refreshPricingAfterStayUpdate(int $reservationId, array $row, array $action, string $parentIdempotencyKey, bool $enqueueOnFailure): array
    {
        if ((string) ($action['retry_operation'] ?? '') !== 'reservation_stay_update') {
            return [
                'success' => true,
                'queued' => false,
                'message' => '',
                'pricing_pending' => false,
            ];
        }
        $latestRow = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
        if (!\is_array($latestRow)) {
            $latestRow = $row;
        }
        $checkin = (string) ($action['target_local_checkin'] ?? (string) ($latestRow['checkin'] ?? ''));
        $checkout = (string) ($action['target_local_checkout'] ?? (string) ($latestRow['checkout'] ?? ''));
        $pricingIdempotencyKey = $this->pricingIdempotencyKey($latestRow, $checkin, $checkout, $parentIdempotencyKey);
        return $this->refreshPricingForRow($latestRow, $checkin, $checkout, $pricingIdempotencyKey, $enqueueOnFailure);
    }
    /**
     * @param array<string, mixed> $row
     * @return array{success: bool, queued: bool, message: string, pricing_pending: bool}
     */
    private function refreshPricingForRow(array $row, string $checkin, string $checkout, string $idempotencyKey, bool $enqueueOnFailure): array
    {
        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        if ($reservationId <= 0 || $checkin === '' || $checkout === '') {
            return [
                'success' => false,
                'queued' => false,
                'message' => \__('Clock pricing refresh is missing reservation dates.', 'must-hotel-booking'),
                'pricing_pending' => true,
            ];
        }
        if ($this->isPricingAlreadyReconciled($row, $checkin, $checkout, $idempotencyKey)) {
            return [
                'success' => true,
                'queued' => false,
                'message' => '',
                'pricing_pending' => false,
            ];
        }
        $pricing = $this->fetchPricingQuote($row, $checkin, $checkout);
        if (empty($pricing['success'])) {
            $message = (string) ($pricing['message'] ?? \__('Clock pricing refresh failed.', 'must-hotel-booking'));
            $this->updatePricingMetadata($reservationId, $row, $checkin, $checkout, [], $idempotencyKey, false, 'pending_retry', $message);
            $queued = $enqueueOnFailure && $this->enqueuePricingRetry($row, $checkin, $checkout, $idempotencyKey, $message) > 0;
            return [
                'success' => false,
                'queued' => $queued,
                'message' => $queued
                    ? \__('Clock stay dates were updated, but pricing refresh was queued for retry.', 'must-hotel-booking')
                    : $message,
                'pricing_pending' => true,
            ];
        }
        $this->updatePricingMetadata($reservationId, $row, $checkin, $checkout, $pricing, $idempotencyKey, true, 'synced', '');
        return [
            'success' => true,
            'queued' => false,
            'message' => '',
            'pricing_pending' => false,
        ];
    }
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function fetchPricingQuote(array $row, string $checkin, string $checkout): array
    {
        $roomId = isset($row['room_id']) ? (int) $row['room_id'] : 0;
        $guests = isset($row['guests']) ? \max(1, (int) $row['guests']) : 1;
        $ratePlanId = isset($row['rate_plan_id']) ? (int) $row['rate_plan_id'] : 0;
        $couponCode = isset($row['coupon_code']) ? (string) $row['coupon_code'] : '';
        if ($roomId <= 0) {
            return [
                'success' => false,
                'message' => \__('Clock pricing refresh is missing the local accommodation.', 'must-hotel-booking'),
            ];
        }
        if (ClockConfig::quotePath() === '') {
            return [
                'success' => false,
                'message' => \__('Clock quote endpoint is not configured; pricing refresh was queued for later reconciliation.', 'must-hotel-booking'),
            ];
        }
        $pricing = $this->quote->calculateTotal($roomId, $checkin, $checkout, $guests, $couponCode, $ratePlanId);
        if (empty($pricing['success']) || !isset($pricing['total_price'])) {
            return [
                'success' => false,
                'message' => (string) ($pricing['message'] ?? \__('Clock quote response did not include usable pricing.', 'must-hotel-booking')),
            ];
        }
        $pricing['pricing_source'] = 'clock_quote';
        return $pricing;
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $pricing
     */
    private function updatePricingMetadata(int $reservationId, array $row, string $checkin, string $checkout, array $pricing, string $idempotencyKey, bool $success, string $syncStatus, string $syncError): void
    {
        $metadata = $this->decodeMetadata($row['provider_metadata'] ?? null);
        $totalPrice = $success && isset($pricing['total_price']) ? \round((float) $pricing['total_price'], 2) : (float) ($row['total_price'] ?? 0.0);
        $paymentSummary = \MustHotelBooking\Engine\get_payment_repository()->getReservationPaymentSummary($reservationId);
        $amountPaid = isset($paymentSummary['amount_paid']) ? \round((float) $paymentSummary['amount_paid'], 2) : 0.0;
        $amountDue = \round(\max(0.0, $totalPrice - $amountPaid), 2);
        $providerPaymentStatus = $success
            ? (string) ($pricing['provider_payment_status'] ?? (string) ($row['provider_payment_status'] ?? ''))
            : (string) ($row['provider_payment_status'] ?? '');
        $metadata['last_provider_pricing_refresh'] = [
            'idempotency_key' => $idempotencyKey,
            'success' => $success,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'pricing_source' => (string) ($pricing['pricing_source'] ?? ''),
            'previous_total_price' => isset($row['total_price']) ? \round((float) $row['total_price'], 2) : 0.0,
            'total_price' => $totalPrice,
            'room_subtotal' => isset($pricing['room_subtotal']) ? \round((float) $pricing['room_subtotal'], 2) : null,
            'fees_total' => isset($pricing['fees_total']) ? \round((float) $pricing['fees_total'], 2) : null,
            'taxes_total' => isset($pricing['taxes_total']) ? \round((float) $pricing['taxes_total'], 2) : null,
            'discount_total' => isset($pricing['discount_total']) ? \round((float) $pricing['discount_total'], 2) : null,
            'amount_paid' => $amountPaid,
            'amount_due' => $amountDue,
            'sync_status' => $syncStatus,
            'sync_error' => $syncError,
            'synced_at' => $this->now(),
        ];
        $metadata['pricing_reconciliation_required'] = !$success;
        if ($success && isset($pricing['provider_pricing']) && \is_array($pricing['provider_pricing'])) {
            $metadata['last_provider_pricing_refresh']['provider_pricing'] = $pricing['provider_pricing'];
        }
        $metadataUpdates = [
            'provider_payment_status' => $providerPaymentStatus,
            'provider_sync_status' => $syncStatus,
            'provider_synced_at' => $success ? $this->now() : null,
            'provider_sync_error' => $syncError,
            'provider_metadata' => $metadata,
        ];
        \MustHotelBooking\Engine\get_reservation_repository()->updateProviderMetadata($reservationId, $metadataUpdates);
        if (!$success) {
            return;
        }
        $updates = [
            'total_price' => $totalPrice,
        ];
        if (isset($pricing['discount_total'])) {
            $updates['coupon_discount_total'] = \max(0.0, \round((float) $pricing['discount_total'], 2));
        }
        if (isset($pricing['applied_coupon']) && \is_scalar($pricing['applied_coupon']) && \trim((string) $pricing['applied_coupon']) !== '') {
            $updates['coupon_code'] = \sanitize_text_field((string) $pricing['applied_coupon']);
        }
        \MustHotelBooking\Engine\get_reservation_repository()->updateReservation($reservationId, $updates);
    }
    /**
     * @param array<string, mixed> $row
     */
    private function enqueuePricingRetry(array $row, string $checkin, string $checkout, string $idempotencyKey, string $error): int
    {
        $targetExternalId = $this->externalId($row) . '#pricing-' . $checkin . '-' . $checkout;
        return $this->syncJobs->enqueueOnce([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => 'reservation_pricing_refresh',
            'target_type' => 'reservation',
            'target_local_id' => isset($row['id']) ? (int) $row['id'] : 0,
            'target_external_id' => $targetExternalId,
            'status' => ProviderSyncJobRepository::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 5,
            'priority' => 6,
            'last_error' => $error,
            'payload' => [
                'source' => 'must_hotel_booking_stay_update',
                'idempotency_key' => $idempotencyKey,
                'local_reservation_id' => isset($row['id']) ? (int) $row['id'] : 0,
                'provider_booking_id' => (string) ($row['provider_booking_id'] ?? ''),
                'provider_reservation_id' => (string) ($row['provider_reservation_id'] ?? ''),
                'local_target_checkin' => $checkin,
                'local_target_checkout' => $checkout,
            ],
        ]);
    }
    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    private function clockReservationRows(array $reservationIds): array
    {
        $rows = \MustHotelBooking\Engine\get_reservation_repository()->getProviderReservationRowsByIds($reservationIds);
        $clockRows = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || (string) ($row['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
                continue;
            }
            $clockRows[] = $row;
        }
        return $clockRows;
    }
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function providerRequestBody(array $payload, array $action): array
    {
        $requestOperation = (string) ($action['request_operation'] ?? '');
        if ($requestOperation === 'clock.reservation_cancel') {
            return [
                'booking' => [
                    'status' => 'canceled',
                ],
            ];
        }
        return $payload;
    }
    private function payload(array $row, array $action, string $idempotencyKey): array
    {
        $targetGuest = isset($action['target_guest']) && \is_array($action['target_guest'])
            ? $this->sanitizeGuestUpdate($action['target_guest'])
            : [];
        $payload = [
            'property_id' => ClockConfig::propertyId(),
            'idempotency_key' => $idempotencyKey,
            'local_reservation_id' => isset($row['id']) ? (int) $row['id'] : 0,
            'local_booking_id' => (string) ($row['booking_id'] ?? ''),
            'provider_booking_id' => (string) ($row['provider_booking_id'] ?? ''),
            'provider_reservation_id' => (string) ($row['provider_reservation_id'] ?? ''),
            'reservation_status' => (string) ($action['target_provider_status'] ?? ''),
            'payment_status' => (string) ($action['target_provider_payment_status'] ?? ''),
            'local_reservation_status' => (string) ($row['status'] ?? ''),
            'local_payment_status' => (string) ($row['payment_status'] ?? ''),
            'local_current_checkin' => (string) ($row['checkin'] ?? ''),
            'local_current_checkout' => (string) ($row['checkout'] ?? ''),
            'local_target_checkin' => (string) ($action['target_local_checkin'] ?? ''),
            'local_target_checkout' => (string) ($action['target_local_checkout'] ?? ''),
            'payment_method' => (string) ($action['local_payment_method'] ?? ''),
            'transaction_id' => (string) ($action['transaction_id'] ?? ''),
            'reason' => (string) ($action['operation'] ?? ''),
            'local_target_reservation_status' => (string) ($action['target_local_status'] ?? ''),
            'local_target_payment_status' => (string) ($action['target_local_payment_status'] ?? ''),
            'local_target_checked_in_at' => (string) ($action['target_local_checked_in_at'] ?? ''),
            'local_target_checked_out_at' => (string) ($action['target_local_checked_out_at'] ?? ''),
            'local_current_assigned_room_id' => isset($row['assigned_room_id']) ? (int) $row['assigned_room_id'] : 0,
            'local_target_assigned_room_id' => isset($action['target_local_assigned_room_id']) ? (int) $action['target_local_assigned_room_id'] : 0,
            'local_target_room_type_id' => isset($action['target_local_room_type_id']) ? (int) $action['target_local_room_type_id'] : 0,
            'provider_room_id' => (string) ($action['target_provider_room_id'] ?? ''),
            'provider_room_code' => (string) ($action['target_provider_room_code'] ?? ''),
            'provider_room_label' => (string) ($action['target_provider_room_label'] ?? ''),
            'metadata_key' => (string) ($action['metadata_key'] ?? 'last_payment_reconciliation'),
            'required_flag' => (string) ($action['required_flag'] ?? 'payment_reconciliation_required'),
            'source' => (string) ($action['source'] ?? 'must_hotel_booking_public_payment'),
        ];
        if (!empty($targetGuest)) {
            $payload['local_current_guest_id'] = isset($row['guest_id']) ? (int) $row['guest_id'] : 0;
            $payload['local_current_guest'] = [
                'first_name' => (string) ($row['first_name'] ?? ''),
                'last_name' => (string) ($row['last_name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
            ];
            $payload['guest'] = $targetGuest;
            $payload['guest_first_name'] = (string) ($targetGuest['first_name'] ?? '');
            $payload['guest_last_name'] = (string) ($targetGuest['last_name'] ?? '');
            $payload['guest_email'] = (string) ($targetGuest['email'] ?? '');
            $payload['guest_phone'] = (string) ($targetGuest['phone'] ?? '');
            $payload['guest_country'] = (string) ($targetGuest['country'] ?? '');
        }
        return $payload;
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     * @param array<string, mixed> $payload
     */
    private function enqueueRetry(array $row, array $action, array $payload, string $error): void
    {
        $targetExternalId = $this->externalId($row);
        if ((string) ($action['retry_operation'] ?? '') === 'reservation_room_assignment') {
            $targetExternalId .= '#room-' . (string) ((int) ($action['target_local_assigned_room_id'] ?? 0));
        }
        if ((string) ($action['retry_operation'] ?? '') === 'reservation_stay_update') {
            $targetExternalId .= '#stay-' . (string) ($action['target_local_checkin'] ?? '') . '-' . (string) ($action['target_local_checkout'] ?? '');
        }
        if ((string) ($action['retry_operation'] ?? '') === 'reservation_guest_update') {
            $guest = isset($action['target_guest']) && \is_array($action['target_guest']) ? $action['target_guest'] : [];
            $targetExternalId .= '#guest-' . $this->guestHash($guest);
        }
        $this->syncJobs->enqueueOnce([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => (string) ($action['retry_operation'] ?? 'reservation_status'),
            'target_type' => 'reservation',
            'target_local_id' => isset($row['id']) ? (int) $row['id'] : 0,
            'target_external_id' => $targetExternalId,
            'status' => ProviderSyncJobRepository::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => 5,
            'priority' => 5,
            'last_error' => $error,
            'payload' => $payload,
        ]);
    }
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function actionForSyncJob(string $operation, array $payload): array
    {
        if ($operation === 'reservation_payment_update' || $operation === 'reservation_status_update' || $operation === 'reservation_status') {
            return [
                'operation' => isset($payload['reason']) ? \sanitize_key((string) $payload['reason']) : 'payment_succeeded',
                'target_provider_status' => $this->payloadString($payload, 'reservation_status', 'confirmed'),
                'target_provider_payment_status' => $this->payloadString($payload, 'payment_status', 'paid'),
                'target_local_status' => $this->payloadString($payload, 'local_target_reservation_status', ''),
                'target_local_payment_status' => $this->payloadString($payload, 'local_target_payment_status', ''),
                'target_local_checked_in_at' => $this->payloadString($payload, 'local_target_checked_in_at', ''),
                'target_local_checked_out_at' => $this->payloadString($payload, 'local_target_checked_out_at', ''),
                'local_payment_method' => $this->payloadString($payload, 'payment_method', 'stripe'),
                'transaction_id' => $this->payloadString($payload, 'transaction_id', ''),
                'endpoint_path' => ClockConfig::reservationStatusUpdatePath(),
                'request_operation' => 'clock.reservation_payment_update',
                'retry_operation' => 'reservation_payment_update',
                'metadata_key' => $this->payloadString($payload, 'metadata_key', 'last_payment_reconciliation'),
                'required_flag' => $this->payloadString($payload, 'required_flag', 'payment_reconciliation_required'),
                'source' => $this->payloadString($payload, 'source', 'must_hotel_booking_public_payment'),
            ];
        }
        if ($operation === 'reservation_cancel') {
            $reason = isset($payload['reason']) ? \sanitize_key((string) $payload['reason']) : 'cancelled';
            return [
                'operation' => $reason,
                'target_provider_status' => $this->payloadString($payload, 'reservation_status', $reason === 'expired' ? 'expired' : 'cancelled'),
                'target_provider_payment_status' => $this->payloadString($payload, 'payment_status', 'cancelled'),
                'target_local_status' => $this->payloadString($payload, 'local_target_reservation_status', ''),
                'target_local_payment_status' => $this->payloadString($payload, 'local_target_payment_status', ''),
                'target_local_checked_in_at' => $this->payloadString($payload, 'local_target_checked_in_at', ''),
                'target_local_checked_out_at' => $this->payloadString($payload, 'local_target_checked_out_at', ''),
                'local_payment_method' => $this->payloadString($payload, 'payment_method', 'stripe'),
                'transaction_id' => $this->payloadString($payload, 'transaction_id', ''),
                'endpoint_path' => ClockConfig::reservationCancelPath(),
                'request_operation' => 'clock.reservation_cancel',
                'retry_operation' => 'reservation_cancel',
                'metadata_key' => $this->payloadString($payload, 'metadata_key', 'last_payment_reconciliation'),
                'required_flag' => $this->payloadString($payload, 'required_flag', 'payment_reconciliation_required'),
                'source' => $this->payloadString($payload, 'source', 'must_hotel_booking_public_payment'),
            ];
        }
        if ($operation === 'reservation_checkin' || $operation === 'reservation_checkout') {
            $isCheckout = $operation === 'reservation_checkout';
            return [
                'operation' => $isCheckout ? 'checkout' : 'checkin',
                'target_provider_status' => $this->payloadString($payload, 'reservation_status', $isCheckout ? 'checked_out' : 'checked_in'),
                'target_provider_payment_status' => $this->payloadString($payload, 'payment_status', ''),
                'target_local_status' => $this->payloadString($payload, 'local_target_reservation_status', $isCheckout ? 'completed' : ''),
                'target_local_payment_status' => $this->payloadString($payload, 'local_target_payment_status', ''),
                'target_local_checked_in_at' => $this->payloadString($payload, 'local_target_checked_in_at', ''),
                'target_local_checked_out_at' => $this->payloadString($payload, 'local_target_checked_out_at', ''),
                'local_payment_method' => $this->payloadString($payload, 'payment_method', ''),
                'transaction_id' => $this->payloadString($payload, 'transaction_id', ''),
                'endpoint_path' => ClockConfig::reservationStatusUpdatePath(),
                'request_operation' => $isCheckout ? 'clock.reservation_checkout' : 'clock.reservation_checkin',
                'retry_operation' => $operation,
                'metadata_key' => $this->payloadString($payload, 'metadata_key', $isCheckout ? 'last_provider_checkout' : 'last_provider_checkin'),
                'required_flag' => $this->payloadString($payload, 'required_flag', $isCheckout ? 'provider_checkout_required' : 'provider_checkin_required'),
                'source' => $this->payloadString($payload, 'source', 'must_hotel_booking_admin'),
            ];
        }
        if ($operation === 'reservation_room_assignment') {
            $providerRoomId = $this->payloadString($payload, 'provider_room_id', '');
            $providerRoomCode = $this->payloadString($payload, 'provider_room_code', '');
            $localRoomId = $this->payloadInt($payload, 'local_target_assigned_room_id', 0);
            return [
                'operation' => 'room_assignment',
                'target_provider_status' => $this->payloadString($payload, 'reservation_status', ''),
                'target_provider_payment_status' => $this->payloadString($payload, 'payment_status', ''),
                'target_local_status' => $this->payloadString($payload, 'local_target_reservation_status', ''),
                'target_local_payment_status' => $this->payloadString($payload, 'local_target_payment_status', ''),
                'target_local_checked_in_at' => $this->payloadString($payload, 'local_target_checked_in_at', ''),
                'target_local_checked_out_at' => $this->payloadString($payload, 'local_target_checked_out_at', ''),
                'target_local_assigned_room_id' => $localRoomId,
                'target_local_room_type_id' => $this->payloadInt($payload, 'local_target_room_type_id', 0),
                'target_provider_room_id' => $providerRoomId,
                'target_provider_room_code' => $providerRoomCode,
                'target_provider_room_label' => $this->payloadString($payload, 'provider_room_label', ''),
                'local_payment_method' => $this->payloadString($payload, 'payment_method', ''),
                'transaction_id' => $this->payloadString($payload, 'transaction_id', ''),
                'endpoint_path' => ClockConfig::reservationRoomUpdatePath(),
                'request_operation' => 'clock.reservation_room_assignment',
                'retry_operation' => 'reservation_room_assignment',
                'metadata_key' => $this->payloadString($payload, 'metadata_key', 'last_provider_room_assignment'),
                'required_flag' => $this->payloadString($payload, 'required_flag', 'provider_room_assignment_required'),
                'source' => $this->payloadString($payload, 'source', 'must_hotel_booking_admin'),
                'idempotency_context' => 'room:' . ($providerRoomId !== '' ? $providerRoomId : (string) $localRoomId) . ':' . $providerRoomCode,
            ];
        }
        if ($operation === 'reservation_stay_update') {
            $checkin = $this->payloadString($payload, 'local_target_checkin', '');
            $checkout = $this->payloadString($payload, 'local_target_checkout', '');
            return [
                'operation' => 'stay_update',
                'target_provider_status' => $this->payloadString($payload, 'reservation_status', ''),
                'target_provider_payment_status' => $this->payloadString($payload, 'payment_status', ''),
                'target_local_status' => $this->payloadString($payload, 'local_target_reservation_status', ''),
                'target_local_payment_status' => $this->payloadString($payload, 'local_target_payment_status', ''),
                'target_local_checked_in_at' => $this->payloadString($payload, 'local_target_checked_in_at', ''),
                'target_local_checked_out_at' => $this->payloadString($payload, 'local_target_checked_out_at', ''),
                'target_local_checkin' => $checkin,
                'target_local_checkout' => $checkout,
                'local_payment_method' => $this->payloadString($payload, 'payment_method', ''),
                'transaction_id' => $this->payloadString($payload, 'transaction_id', ''),
                'endpoint_path' => ClockConfig::reservationStayUpdatePath(),
                'request_operation' => 'clock.reservation_stay_update',
                'retry_operation' => 'reservation_stay_update',
                'metadata_key' => $this->payloadString($payload, 'metadata_key', 'last_provider_stay_update'),
                'required_flag' => $this->payloadString($payload, 'required_flag', 'provider_stay_update_required'),
                'source' => $this->payloadString($payload, 'source', 'must_hotel_booking_admin'),
                'idempotency_context' => 'stay:' . $checkin . ':' . $checkout,
            ];
        }
        if ($operation === 'reservation_guest_update') {
            $guest = $this->sanitizeGuestUpdate([
                'first_name' => $this->payloadString($payload, 'guest_first_name', ''),
                'last_name' => $this->payloadString($payload, 'guest_last_name', ''),
                'email' => $this->payloadString($payload, 'guest_email', ''),
                'phone' => $this->payloadString($payload, 'guest_phone', ''),
                'country' => $this->payloadString($payload, 'guest_country', ''),
            ]);
            return [
                'operation' => 'guest_update',
                'target_provider_status' => $this->payloadString($payload, 'reservation_status', ''),
                'target_provider_payment_status' => $this->payloadString($payload, 'payment_status', ''),
                'target_local_status' => $this->payloadString($payload, 'local_target_reservation_status', ''),
                'target_local_payment_status' => $this->payloadString($payload, 'local_target_payment_status', ''),
                'target_local_checked_in_at' => $this->payloadString($payload, 'local_target_checked_in_at', ''),
                'target_local_checked_out_at' => $this->payloadString($payload, 'local_target_checked_out_at', ''),
                'target_guest' => $guest,
                'local_payment_method' => $this->payloadString($payload, 'payment_method', ''),
                'transaction_id' => $this->payloadString($payload, 'transaction_id', ''),
                'endpoint_path' => ClockConfig::reservationGuestUpdatePath(),
                'request_operation' => 'clock.reservation_guest_update',
                'retry_operation' => 'reservation_guest_update',
                'metadata_key' => $this->payloadString($payload, 'metadata_key', 'last_provider_guest_update'),
                'required_flag' => $this->payloadString($payload, 'required_flag', 'provider_guest_update_required'),
                'source' => $this->payloadString($payload, 'source', 'must_hotel_booking_admin'),
                'idempotency_context' => 'guest:' . $this->guestHash($guest),
            ];
        }
        return [];
    }
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function ensurePayload(array $payload, array $row, array $action, string $idempotencyKey): array
    {
        $fallback = $this->payload($row, $action, $idempotencyKey);
        foreach ($fallback as $key => $value) {
            if (!isset($payload[$key]) || $payload[$key] === '') {
                $payload[$key] = $value;
            }
        }
        $payload['idempotency_key'] = $idempotencyKey;
        return $payload;
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     * @param array<string, mixed> $providerState
     */
    private function updateMetadata(int $reservationId, array $row, array $action, string $idempotencyKey, bool $success, string $syncStatus, string $syncError = '', array $providerState = []): void
    {
        $metadata = $this->decodeMetadata($row['provider_metadata'] ?? null);
        $targetProviderStatus = (string) ($action['target_provider_status'] ?? '');
        $targetProviderPaymentStatus = (string) ($action['target_provider_payment_status'] ?? '');
        $metadataKey = \sanitize_key((string) ($action['metadata_key'] ?? 'last_payment_reconciliation'));
        $requiredFlag = \sanitize_key((string) ($action['required_flag'] ?? 'payment_reconciliation_required'));
        $targetLocalStatus = \sanitize_key((string) ($action['target_local_status'] ?? ''));
        $targetLocalPaymentStatus = \sanitize_key((string) ($action['target_local_payment_status'] ?? ''));
        $targetCheckedInAt = (string) ($action['target_local_checked_in_at'] ?? '');
        $targetCheckedOutAt = (string) ($action['target_local_checked_out_at'] ?? '');
        $targetCheckin = (string) ($action['target_local_checkin'] ?? '');
        $targetCheckout = (string) ($action['target_local_checkout'] ?? '');
        $targetAssignedRoomId = isset($action['target_local_assigned_room_id']) ? (int) $action['target_local_assigned_room_id'] : 0;
        $targetRoomTypeId = isset($action['target_local_room_type_id']) ? (int) $action['target_local_room_type_id'] : 0;
        $targetProviderRoomId = (string) ($action['target_provider_room_id'] ?? '');
        $targetProviderRoomCode = (string) ($action['target_provider_room_code'] ?? '');
        $targetProviderRoomLabel = (string) ($action['target_provider_room_label'] ?? '');
        $targetGuest = isset($action['target_guest']) && \is_array($action['target_guest'])
            ? $this->sanitizeGuestUpdate($action['target_guest'])
            : [];
        if ($metadataKey === '') {
            $metadataKey = 'last_payment_reconciliation';
        }
        if ($requiredFlag === '') {
            $requiredFlag = 'payment_reconciliation_required';
        }
        $metadata[$metadataKey] = [
            'idempotency_key' => $idempotencyKey,
            'operation' => (string) ($action['operation'] ?? ''),
            'success' => $success,
            'target_provider_status' => $targetProviderStatus,
            'target_provider_payment_status' => $targetProviderPaymentStatus,
            'target_local_status' => $targetLocalStatus,
            'target_local_payment_status' => $targetLocalPaymentStatus,
            'target_local_checked_in_at' => $targetCheckedInAt,
            'target_local_checked_out_at' => $targetCheckedOutAt,
            'target_local_checkin' => $targetCheckin,
            'target_local_checkout' => $targetCheckout,
            'target_local_assigned_room_id' => $targetAssignedRoomId,
            'target_local_room_type_id' => $targetRoomTypeId,
            'target_provider_room_id' => $targetProviderRoomId,
            'target_provider_room_code' => $targetProviderRoomCode,
            'target_provider_room_label' => $targetProviderRoomLabel,
            'target_guest' => $targetGuest,
            'sync_status' => $syncStatus,
            'sync_error' => $syncError,
            'synced_at' => $this->now(),
        ];
        $metadata[$requiredFlag] = !$success && $syncStatus !== 'local_only';
        $applyTargetState = $success || $syncStatus === 'local_only';
        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservationRepository->updateProviderMetadata($reservationId, [
            'provider_status' => $applyTargetState
                ? $this->firstString(
                    $providerState,
                    ['status', 'state', 'reservation_status'],
                    $targetProviderStatus !== '' ? $targetProviderStatus : (string) ($row['provider_status'] ?? '')
                )
                : (string) ($row['provider_status'] ?? ''),
            'provider_payment_status' => $applyTargetState
                ? $this->firstString(
                    $providerState,
                    ['payment_status', 'payment_state'],
                    $targetProviderPaymentStatus !== '' ? $targetProviderPaymentStatus : (string) ($row['provider_payment_status'] ?? '')
                )
                : (string) ($row['provider_payment_status'] ?? ''),
            'provider_sync_status' => $syncStatus,
            'provider_synced_at' => $success || $syncStatus === 'local_only' ? $this->now() : null,
            'provider_sync_error' => $syncError,
            'provider_metadata' => $metadata,
        ]);
        if ($success && $targetLocalStatus !== '') {
            $reservationRepository->updateReservationStatus(
                $reservationId,
                $targetLocalStatus,
                $targetLocalPaymentStatus !== '' ? $targetLocalPaymentStatus : (string) ($row['payment_status'] ?? '')
            );
        }
        if ($success) {
            $updates = [];
            if ($targetCheckedInAt !== '') {
                $updates['checked_in_at'] = $targetCheckedInAt === 'now' ? $this->now() : $targetCheckedInAt;
            }
            if ($targetCheckedOutAt !== '') {
                $updates['checked_out_at'] = $targetCheckedOutAt === 'now' ? $this->now() : $targetCheckedOutAt;
            }
            if ($targetCheckin !== '') {
                $updates['checkin'] = $targetCheckin;
            }
            if ($targetCheckout !== '') {
                $updates['checkout'] = $targetCheckout;
            }
            if ($targetAssignedRoomId > 0) {
                $updates['assigned_room_id'] = $targetAssignedRoomId;
            }
            if ($targetRoomTypeId > 0) {
                $updates['room_type_id'] = $targetRoomTypeId;
            }
            if (!empty($updates)) {
                $reservationRepository->updateReservation($reservationId, $updates);
            }
            if (!empty($targetGuest)) {
                $this->applyLocalGuestUpdate($reservationId, $row, $targetGuest);
            }
        }
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     */
    private function recordSkippedLog(array $row, array $action, string $idempotencyKey): void
    {
        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        $externalId = $this->externalId($row);
        $logId = $this->requestLogs->create([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => (string) ($action['request_operation'] ?? 'clock.reservation_status'),
            'direction' => 'outbound',
            'idempotency_key' => $idempotencyKey,
            'reservation_id' => $reservationId,
            'external_id' => $externalId,
            'request_summary' => [
                'skipped' => true,
                'reason' => 'endpoint_missing',
            ],
        ]);
        if ($logId > 0) {
            $this->requestLogs->complete($logId, [
                'success' => 0,
                'error_code' => 'endpoint_missing',
                'error_message' => (string) ($action['missing_endpoint_message'] ?? ''),
                'response_summary' => [
                    'queued_retry' => !empty($action['missing_endpoint_retry']),
                ],
            ]);
        }
    }
    /**
     * @param array<string, mixed> $row
     */
    private function isAlreadyReconciled(array $row, array $action, string $idempotencyKey): bool
    {
        $metadata = $this->decodeMetadata($row['provider_metadata'] ?? null);
        $metadataKey = \sanitize_key((string) ($action['metadata_key'] ?? 'last_payment_reconciliation'));
        $targetProviderStatus = (string) ($action['target_provider_status'] ?? '');
        $targetProviderPaymentStatus = (string) ($action['target_provider_payment_status'] ?? '');
        if ($metadataKey === '') {
            $metadataKey = 'last_payment_reconciliation';
        }
        $last = isset($metadata[$metadataKey]) && \is_array($metadata[$metadataKey])
            ? $metadata[$metadataKey]
            : [];
        return (string) ($last['idempotency_key'] ?? '') === $idempotencyKey
            && \in_array((string) ($row['provider_sync_status'] ?? ''), ['synced', 'local_only'], true)
            && ($targetProviderStatus === '' || (string) ($row['provider_status'] ?? '') === $targetProviderStatus)
            && ($targetProviderPaymentStatus === '' || (string) ($row['provider_payment_status'] ?? '') === $targetProviderPaymentStatus);
    }
    /**
     * @param array<string, mixed> $row
     */
    private function isPricingAlreadyReconciled(array $row, string $checkin, string $checkout, string $idempotencyKey): bool
    {
        $metadata = $this->decodeMetadata($row['provider_metadata'] ?? null);
        $last = isset($metadata['last_provider_pricing_refresh']) && \is_array($metadata['last_provider_pricing_refresh'])
            ? $metadata['last_provider_pricing_refresh']
            : [];
        return !empty($last['success'])
            && (string) ($last['idempotency_key'] ?? '') === $idempotencyKey
            && (string) ($last['checkin'] ?? '') === $checkin
            && (string) ($last['checkout'] ?? '') === $checkout
            && empty($metadata['pricing_reconciliation_required']);
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     */
    private function isSupersededSyncJob(array $row, array $action, string $idempotencyKey): bool
    {
        if (!\in_array((string) ($action['retry_operation'] ?? ''), ['reservation_room_assignment', 'reservation_stay_update', 'reservation_guest_update'], true)) {
            return false;
        }
        $metadata = $this->decodeMetadata($row['provider_metadata'] ?? null);
        $metadataKey = \sanitize_key((string) ($action['metadata_key'] ?? ''));
        if ($metadataKey === '') {
            $retryOperation = (string) ($action['retry_operation'] ?? '');
            if ($retryOperation === 'reservation_stay_update') {
                $metadataKey = 'last_provider_stay_update';
            } elseif ($retryOperation === 'reservation_guest_update') {
                $metadataKey = 'last_provider_guest_update';
            } else {
                $metadataKey = 'last_provider_room_assignment';
            }
        }
        $last = isset($metadata[$metadataKey]) && \is_array($metadata[$metadataKey])
            ? $metadata[$metadataKey]
            : [];
        $latestIdempotencyKey = (string) ($last['idempotency_key'] ?? '');
        return $latestIdempotencyKey !== '' && $latestIdempotencyKey !== $idempotencyKey;
    }
    /**
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function decodePayload($payload): array
    {
        if (\is_array($payload)) {
            return $payload;
        }
        if (!\is_string($payload) || \trim($payload) === '') {
            return [];
        }
        $decoded = \json_decode($payload, true);
        return \is_array($decoded) ? $decoded : [];
    }
    /** @param mixed $metadata */
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
    /** @param array<string, mixed> $row */
    private function externalId(array $row): string
    {
        $reservationId = (string) ($row['provider_reservation_id'] ?? '');
        return $reservationId !== '' ? $reservationId : (string) ($row['provider_booking_id'] ?? '');
    }
    /** @param array<string, mixed> $row @param array<string, mixed> $action */
    private function applyPathTokens(string $path, array $row, array $action = []): string
    {
        $resolved = \strtr($path, [
            '{reservation_id}' => \rawurlencode((string) ($row['provider_reservation_id'] ?? '')),
            '{booking_id}' => \rawurlencode((string) ($row['provider_booking_id'] ?? '')),
            '{provider_room_id}' => \rawurlencode((string) ($action['target_provider_room_id'] ?? '')),
            '{provider_room_code}' => \rawurlencode((string) ($action['target_provider_room_code'] ?? '')),
            '{checkin}' => \rawurlencode((string) ($action['target_local_checkin'] ?? '')),
            '{checkout}' => \rawurlencode((string) ($action['target_local_checkout'] ?? '')),
        ]);
        return \preg_match('/\{[a-z_]+\}/i', $resolved) === 1 ? '' : $resolved;
    }
    /** @param mixed $data @return array<string, mixed> */
    private function providerState($data): array
    {
        if (!\is_array($data)) {
            return [];
        }
        foreach (['reservation', 'booking', 'data', 'result'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $data[$key];
            }
        }
        return $data;
    }
    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys, string $fallback = ''): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                return \sanitize_text_field((string) $source[$key]);
            }
        }
        return $fallback;
    }
    /** @param array<string, mixed> $payload */
    private function payloadString(array $payload, string $key, string $fallback): string
    {
        if (!isset($payload[$key]) || !\is_scalar($payload[$key])) {
            return $fallback;
        }
        $value = \sanitize_text_field((string) $payload[$key]);
        return $value !== '' ? $value : $fallback;
    }
    /** @param array<string, mixed> $payload */
    private function payloadInt(array $payload, string $key, int $fallback): int
    {
        if (!isset($payload[$key]) || !\is_scalar($payload[$key])) {
            return $fallback;
        }
        return \max(0, (int) $payload[$key]);
    }
    /**
     * @return array<string, mixed>
     */
    private function operationalAction(string $operation, string $providerStatus, string $localStatus, string $checkedInAt, string $source, string $providerPaymentStatus): array
    {
        $operation = \sanitize_key($operation);
        $isCheckout = $operation === 'checkout';
        return [
            'operation' => $isCheckout ? 'checkout' : 'checkin',
            'target_provider_status' => $providerStatus,
            'target_provider_payment_status' => $providerPaymentStatus,
            'target_local_status' => $localStatus,
            'target_local_payment_status' => '',
            'target_local_checked_in_at' => $checkedInAt,
            'target_local_checked_out_at' => $isCheckout ? 'now' : '',
            'local_payment_method' => '',
            'transaction_id' => '',
            'endpoint_path' => ClockConfig::reservationStatusUpdatePath(),
            'request_operation' => $isCheckout ? 'clock.reservation_checkout' : 'clock.reservation_checkin',
            'retry_operation' => $isCheckout ? 'reservation_checkout' : 'reservation_checkin',
            'metadata_key' => $isCheckout ? 'last_provider_checkout' : 'last_provider_checkin',
            'required_flag' => $isCheckout ? 'provider_checkout_required' : 'provider_checkin_required',
            'source' => $source === 'portal' ? 'must_hotel_booking_portal' : 'must_hotel_booking_admin',
            'missing_endpoint_retry' => true,
            'missing_endpoint_status' => 'pending_retry',
            'missing_endpoint_message' => \__('Clock reservation status update endpoint is not configured; provider operation was queued for later reconciliation.', 'must-hotel-booking'),
        ];
    }
    /** @param array<string, mixed> $row */
    private function operationalPaymentStatus(array $row): string
    {
        $providerPaymentStatus = (string) ($row['provider_payment_status'] ?? '');
        if ($providerPaymentStatus !== '') {
            return $providerPaymentStatus;
        }
        return (string) ($row['payment_status'] ?? '');
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $targetRoom
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function roomAssignmentAction(array $row, array $targetRoom, array $mapping, string $source): array
    {
        $targetRoomId = isset($targetRoom['id']) ? (int) $targetRoom['id'] : 0;
        $targetRoomTypeId = isset($targetRoom['room_type_id']) ? (int) $targetRoom['room_type_id'] : 0;
        $providerRoomId = (string) ($mapping['external_id'] ?? '');
        $providerRoomCode = (string) ($mapping['external_code'] ?? '');
        return [
            'operation' => 'room_assignment',
            'target_provider_status' => (string) ($row['provider_status'] ?? ''),
            'target_provider_payment_status' => '',
            'target_local_status' => '',
            'target_local_payment_status' => '',
            'target_local_checked_in_at' => '',
            'target_local_checked_out_at' => '',
            'target_local_assigned_room_id' => $targetRoomId,
            'target_local_room_type_id' => $targetRoomTypeId,
            'target_provider_room_id' => $providerRoomId,
            'target_provider_room_code' => $providerRoomCode,
            'target_provider_room_label' => $this->roomLabel($targetRoom),
            'local_payment_method' => '',
            'transaction_id' => '',
            'endpoint_path' => ClockConfig::reservationRoomUpdatePath(),
            'request_operation' => 'clock.reservation_room_assignment',
            'retry_operation' => 'reservation_room_assignment',
            'metadata_key' => 'last_provider_room_assignment',
            'required_flag' => 'provider_room_assignment_required',
            'source' => $source === 'portal' ? 'must_hotel_booking_portal' : 'must_hotel_booking_admin',
            'idempotency_context' => 'room:' . $providerRoomId . ':' . $providerRoomCode . ':' . (string) $targetRoomId,
            'missing_endpoint_retry' => true,
            'missing_endpoint_status' => 'pending_retry',
            'missing_endpoint_message' => \__('Clock reservation room update endpoint is not configured; provider room assignment was queued for later reconciliation.', 'must-hotel-booking'),
        ];
    }
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function stayUpdateAction(array $row, string $checkin, string $checkout, string $source): array
    {
        return [
            'operation' => 'stay_update',
            'target_provider_status' => (string) ($row['provider_status'] ?? ''),
            'target_provider_payment_status' => '',
            'target_local_status' => '',
            'target_local_payment_status' => '',
            'target_local_checked_in_at' => '',
            'target_local_checked_out_at' => '',
            'target_local_checkin' => $checkin,
            'target_local_checkout' => $checkout,
            'local_payment_method' => '',
            'transaction_id' => '',
            'endpoint_path' => ClockConfig::reservationStayUpdatePath(),
            'request_operation' => 'clock.reservation_stay_update',
            'retry_operation' => 'reservation_stay_update',
            'metadata_key' => 'last_provider_stay_update',
            'required_flag' => 'provider_stay_update_required',
            'source' => $source === 'portal' ? 'must_hotel_booking_portal' : 'must_hotel_booking_admin',
            'idempotency_context' => 'stay:' . $checkin . ':' . $checkout,
            'missing_endpoint_retry' => true,
            'missing_endpoint_status' => 'pending_retry',
            'missing_endpoint_message' => \__('Clock reservation stay update endpoint is not configured; provider stay-date edit was queued for later reconciliation.', 'must-hotel-booking'),
        ];
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $guest
     * @return array<string, mixed>
     */
    private function guestUpdateAction(array $row, array $guest, string $source): array
    {
        return [
            'operation' => 'guest_update',
            'target_provider_status' => (string) ($row['provider_status'] ?? ''),
            'target_provider_payment_status' => '',
            'target_local_status' => '',
            'target_local_payment_status' => '',
            'target_local_checked_in_at' => '',
            'target_local_checked_out_at' => '',
            'target_guest' => $guest,
            'local_payment_method' => '',
            'transaction_id' => '',
            'endpoint_path' => ClockConfig::reservationGuestUpdatePath(),
            'request_operation' => 'clock.reservation_guest_update',
            'retry_operation' => 'reservation_guest_update',
            'metadata_key' => 'last_provider_guest_update',
            'required_flag' => 'provider_guest_update_required',
            'source' => $source === 'portal' ? 'must_hotel_booking_portal' : 'must_hotel_booking_admin',
            'idempotency_context' => 'guest:' . $this->guestHash($guest),
            'missing_endpoint_retry' => true,
            'missing_endpoint_status' => 'pending_retry',
            'missing_endpoint_message' => \__('Clock reservation guest update endpoint is not configured; provider guest edit was queued for later reconciliation.', 'must-hotel-booking'),
        ];
    }
    /**
     * @param array<string, mixed> $guestData
     * @return array<string, string>
     */
    private function sanitizeGuestUpdate(array $guestData): array
    {
        return [
            'first_name' => \sanitize_text_field((string) ($guestData['first_name'] ?? '')),
            'last_name' => \sanitize_text_field((string) ($guestData['last_name'] ?? '')),
            'email' => \sanitize_email((string) ($guestData['email'] ?? '')),
            'phone' => \sanitize_text_field((string) ($guestData['phone'] ?? '')),
            'country' => \sanitize_text_field((string) ($guestData['country'] ?? '')),
        ];
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $guest
     */
    private function guestDetailsMatch(array $row, array $guest): bool
    {
        return \trim((string) ($row['first_name'] ?? '')) === (string) ($guest['first_name'] ?? '')
            && \trim((string) ($row['last_name'] ?? '')) === (string) ($guest['last_name'] ?? '')
            && \trim((string) ($row['email'] ?? '')) === (string) ($guest['email'] ?? '')
            && \trim((string) ($row['phone'] ?? '')) === (string) ($guest['phone'] ?? '')
            && \trim((string) ($row['country'] ?? '')) === (string) ($guest['country'] ?? '');
    }
    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $guest
     */
    private function applyLocalGuestUpdate(int $reservationId, array $row, array $guest): void
    {
        if (!isset($row['guest_id'])) {
            $currentRow = \MustHotelBooking\Engine\get_reservation_repository()->getReservation($reservationId);
            if (\is_array($currentRow)) {
                $row = \array_merge($row, $currentRow);
            }
        }
        $guestId = \MustHotelBooking\Engine\get_guest_repository()->saveAdminGuestProfile(
            isset($row['guest_id']) ? (int) $row['guest_id'] : 0,
            (string) ($guest['first_name'] ?? ''),
            (string) ($guest['last_name'] ?? ''),
            (string) ($guest['email'] ?? ''),
            (string) ($guest['phone'] ?? ''),
            (string) ($guest['country'] ?? '')
        );
        if ($guestId > 0 && $guestId !== (int) ($row['guest_id'] ?? 0)) {
            \MustHotelBooking\Engine\get_reservation_repository()->updateReservation($reservationId, ['guest_id' => $guestId]);
        }
    }
    /** @param array<string, mixed> $guest */
    private function guestHash(array $guest): string
    {
        $normalized = $this->sanitizeGuestUpdate($guest);
        $encoded = \function_exists('wp_json_encode') ? \wp_json_encode($normalized) : \json_encode($normalized);
        return \substr(\sha1(\is_string($encoded) ? $encoded : ''), 0, 16);
    }
    /** @param array<string, mixed> $reservation */
    private function stayAvailabilityError(array $reservation, int $roomTypeId, string $checkin, string $checkout): string
    {
        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
        $nonBlockingStatuses = \MustHotelBooking\Core\ReservationStatus::getInventoryNonBlockingStatuses();
        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        if (!\MustHotelBooking\Engine\InventoryEngine::hasInventoryForRoomType($roomTypeId)) {
            return $reservationRepository->hasReservationOverlapExcludingId($reservationId, $roomTypeId, $checkin, $checkout, $nonBlockingStatuses)
                ? \__('The selected stay dates are not available for this accommodation.', 'must-hotel-booking')
                : '';
        }
        $assignedRoomId = isset($reservation['assigned_room_id']) ? (int) $reservation['assigned_room_id'] : 0;
        if ($assignedRoomId > 0) {
            return $reservationRepository->hasAssignedRoomOverlapExcludingId($reservationId, $assignedRoomId, $checkin, $checkout, $nonBlockingStatuses)
                ? \__('The assigned physical room is not available for the selected stay dates. Move the reservation to an available mapped room before editing the stay.', 'must-hotel-booking')
                : '';
        }
        $inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $availableRooms = $inventoryRepository->getAvailableRooms(
            $roomTypeId,
            $checkin,
            $checkout,
            $nonBlockingStatuses,
            \current_time('mysql')
        );
        $unassignedOverlapCount = $inventoryRepository->countUnassignedTypeReservationOverlaps(
            $roomTypeId,
            $checkin,
            $checkout,
            $nonBlockingStatuses
        );
        if (
            $this->reservationDatesOverlap((string) ($reservation['checkin'] ?? ''), (string) ($reservation['checkout'] ?? ''), $checkin, $checkout)
            && \MustHotelBooking\Core\ReservationStatus::blocksInventory((string) ($reservation['status'] ?? ''))
        ) {
            $unassignedOverlapCount = \max(0, $unassignedOverlapCount - 1);
        }
        return \count($availableRooms) > $unassignedOverlapCount
            ? ''
            : \__('The selected stay dates are not available for this accommodation.', 'must-hotel-booking');
    }
    private function reservationDatesOverlap(string $firstCheckin, string $firstCheckout, string $secondCheckin, string $secondCheckout): bool
    {
        if ($firstCheckin === '' || $firstCheckout === '' || $secondCheckin === '' || $secondCheckout === '') {
            return false;
        }
        return $firstCheckin < $secondCheckout && $firstCheckout > $secondCheckin;
    }
    /** @param array<string, mixed> $reservation */
    private function isRoomAvailableForReservation(array $reservation, int $targetRoomId, int $roomTypeId): bool
    {
        if ($targetRoomId <= 0) {
            return false;
        }
        $currentAssignedRoomId = isset($reservation['assigned_room_id']) ? (int) $reservation['assigned_room_id'] : 0;
        if ($targetRoomId === $currentAssignedRoomId) {
            return true;
        }
        if ($roomTypeId <= 0) {
            return false;
        }
        $today = \current_time('Y-m-d');
        $effectiveCheckin = isset($reservation['checkin']) && (string) $reservation['checkin'] > $today
            ? (string) $reservation['checkin']
            : $today;
        $effectiveCheckout = isset($reservation['checkout']) ? (string) $reservation['checkout'] : '';
        if ($effectiveCheckout === '' || $effectiveCheckout <= $effectiveCheckin) {
            $timestamp = \strtotime($effectiveCheckin . ' +1 day');
            $effectiveCheckout = $timestamp !== false ? \wp_date('Y-m-d', $timestamp) : $effectiveCheckin;
        }
        $availableRooms = \MustHotelBooking\Engine\get_inventory_repository()->getAvailableRooms(
            $roomTypeId,
            $effectiveCheckin,
            $effectiveCheckout,
            ['cancelled', 'expired', 'payment_failed'],
            \current_time('mysql')
        );
        foreach ($availableRooms as $availableRoom) {
            if (\is_array($availableRoom) && isset($availableRoom['id']) && (int) $availableRoom['id'] === $targetRoomId) {
                return true;
            }
        }
        return false;
    }
    /** @param array<string, mixed>|null $mapping */
    private function hasExternalId(?array $mapping): bool
    {
        return \is_array($mapping) && (string) ($mapping['external_id'] ?? '') !== '';
    }
    /** @param array<string, mixed> $room */
    private function roomLabel(array $room): string
    {
        $roomNumber = \trim((string) ($room['room_number'] ?? ''));
        if ($roomNumber !== '') {
            return $roomNumber;
        }
        $title = \trim((string) ($room['title'] ?? ''));
        return $title !== '' ? $title : (string) ((int) ($room['id'] ?? 0));
    }
    private function idempotencyKey(int $reservationId, string $externalId, string $providerStatus, string $providerPaymentStatus, string $context = ''): string
    {
        return 'mhb-clock-reconcile-' . \substr(\hash('sha256', $reservationId . '|' . $externalId . '|' . $providerStatus . '|' . $providerPaymentStatus . '|' . $context), 0, 48);
    }
    /**
     * @return array{method: string, path: string}
     */
    private function endpointMethodAndPath(string $path): array
    {
        $path = \trim($path);
        $method = 'POST';
        if (\preg_match('/^\/?\s*(GET|POST|PUT|PATCH|DELETE)\s+(.+)$/i', $path, $matches)) {
            $method = \strtoupper((string) $matches[1]);
            $path = \trim((string) $matches[2]);
        }
        return [
            'method' => $method,
            'path' => $path,
        ];
    }
    private function directReservationUpdateSupported(): bool
    {
        return ClockConfig::reservationStatusUpdatePath() !== ''
            || ClockConfig::reservationCancelPath() !== ''
            || ClockConfig::reservationRoomUpdatePath() !== ''
            || ClockConfig::reservationStayUpdatePath() !== ''
            || ClockConfig::reservationGuestUpdatePath() !== '';
    }
    /**
     * @return array{success: bool, queued: bool, message: string}
     */
    private function unsupportedActionResult(): array
    {
        return [
            'success' => false,
            'queued' => false,
            'message' => \__('Clock reservation update/cancellation endpoint paths are not configured yet.', 'must-hotel-booking'),
        ];
    }
    /** @param array<string, mixed> $row */
    private function pricingIdempotencyKey(array $row, string $checkin, string $checkout, string $parentIdempotencyKey = ''): string
    {
        $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
        $externalId = $this->externalId($row);
        return 'mhb-clock-pricing-' . \substr(\hash('sha256', $reservationId . '|' . $externalId . '|' . $checkin . '|' . $checkout . '|' . $parentIdempotencyKey), 0, 48);
    }
    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
