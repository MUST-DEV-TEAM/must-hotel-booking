<?php
namespace MustHotelBooking\Provider\Clock;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Database\ClockFolioAccountingRepository;
use MustHotelBooking\Database\PaymentRepository;
use MustHotelBooking\Database\RefundRepository;
use MustHotelBooking\Database\ReservationRepository;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderSyncJobRepository;
final class ClockPaymentAccountingService
{
    /** @var ClockFolioAccountingRepository */
    private $accounting;
    /** @var PaymentRepository */
    private $payments;
    /** @var RefundRepository */
    private $refunds;
    /** @var ReservationRepository */
    private $reservations;
    /** @var ClockFolioService */
    private $folios;
    /** @var ProviderSyncJobRepository */
    private $jobs;
    public function __construct(
        ?ClockFolioAccountingRepository $accounting = null,
        ?PaymentRepository $payments = null,
        ?RefundRepository $refunds = null,
        ?ReservationRepository $reservations = null,
        ?ClockFolioService $folios = null,
        ?ProviderSyncJobRepository $jobs = null
    ) {
        $this->accounting = $accounting ?: \MustHotelBooking\Engine\get_clock_folio_accounting_repository();
        $this->payments = $payments ?: \MustHotelBooking\Engine\get_payment_repository();
        $this->refunds = $refunds ?: \MustHotelBooking\Engine\get_refund_repository();
        $this->reservations = $reservations ?: \MustHotelBooking\Engine\get_reservation_repository();
        $this->folios = $folios ?: new ClockFolioService();
        $this->jobs = $jobs ?: new ProviderSyncJobRepository();
    }
    public static function registerHooks(): void
    {
        \add_action('must_hotel_booking/payment_recorded', [self::class, 'handlePaymentRecorded'], 10, 1);
    }
    /** @param array<string, mixed> $event */
    public static function handlePaymentRecorded(array $event): void
    {
        $paymentId = isset($event['payment_id']) ? (int) $event['payment_id'] : 0;
        if ($paymentId <= 0) {
            return;
        }
        (new self())->syncPaidPayment($paymentId);
    }
    /** @return array<string, mixed> */
    public function syncPaidPayment(int $paymentId, bool $enqueueRetry = true): array
    {
        $payment = $this->payments->getPayment($paymentId);
        if (!\is_array($payment)) {
            return $this->result(false, false, \__('Payment row not found.', 'must-hotel-booking'));
        }
        $gateway = \sanitize_key((string) ($payment['method'] ?? ''));
        $status = \sanitize_key((string) ($payment['status'] ?? ''));
        if (!\in_array($gateway, ['stripe', 'pokpay'], true) || $status !== 'paid') {
            return $this->result(true, false, 'not_required');
        }
        $reservationId = (int) ($payment['reservation_id'] ?? 0);
        $reservation = $this->reservations->getReservation($reservationId);
        if (!\is_array($reservation) || (string) ($reservation['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            return $this->result(true, false, 'not_clock_reservation');
        }
        $transactionId = \sanitize_text_field((string) ($payment['transaction_id'] ?? ''));
        $idempotencyKey = $this->paymentIdempotencyKey($paymentId, $transactionId);
        $amount = \round((float) ($payment['amount'] ?? 0.0), 2);
        $currency = $this->currency((string) ($payment['currency'] ?? ''));
        $clockBookingId = $this->clockBookingId($reservation);
        $clockReservationId = $this->clockReservationId($reservation);
        $accounting = $this->accounting->getOrCreateByIdempotencyKey($idempotencyKey, [
            'payment_id' => $paymentId,
            'reservation_id' => $reservationId,
            'booking_id' => (string) ($reservation['booking_id'] ?? ''),
            'gateway' => $gateway,
            'provider_transaction_id' => $transactionId,
            'clock_booking_id' => $clockBookingId,
            'clock_reservation_id' => $clockReservationId,
            'direction' => 'payment',
            'amount' => $amount,
            'amount_minor' => $this->minorUnits($amount, $currency),
            'currency' => $currency,
            'status' => 'pending',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        if (empty($accounting)) {
            return $this->result(false, false, \__('Unable to create Clock accounting row.', 'must-hotel-booking'));
        }
        $accountingId = (int) ($accounting['id'] ?? 0);
        if ((string) ($accounting['status'] ?? '') === 'posted' && (string) ($accounting['idempotency_key'] ?? '') === $idempotencyKey) {
            return $this->result(true, false, 'already_posted', ['accounting_id' => $accountingId]);
        }
        if ((string) ($accounting['status'] ?? '') === 'handled_manually') {
            return $this->result(true, false, 'handled_manually', ['accounting_id' => $accountingId]);
        }
        if ($transactionId === '') {
            return $this->markManualReview($accountingId, ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION, \__('Provider transaction reference is missing.', 'must-hotel-booking'));
        }
        if ($clockBookingId === '') {
            return $this->markManualReview($accountingId, ClockAccountingReason::CLOCK_BOOKING_NOT_FOUND, \__('Clock booking or reservation ID is missing; local booking remains paid.', 'must-hotel-booking'));
        }
        if ($amount <= 0.0) {
            return $this->markManualReview($accountingId, ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION, \__('Payment amount is not positive.', 'must-hotel-booking'));
        }
        $postingDecision = $this->paymentPostingDecision($reservation);
        if ((string) ($postingDecision['mode'] ?? '') === 'manual') {
            return $this->markManualReview(
                $accountingId,
                (string) ($postingDecision['reason_code'] ?? ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION),
                (string) ($postingDecision['message'] ?? \__('Clock payment accounting requires manual review.', 'must-hotel-booking'))
            );
        }
        if ((string) ($postingDecision['mode'] ?? '') === 'deposit') {
            $folioResult = $this->folios->selectOrCreateDepositFolio(
                $clockBookingId,
                $currency,
                $reservationId,
                (string) ($accounting['clock_folio_id'] ?? '')
            );
        } else {
            $folioResult = $this->folios->selectPaymentFolio($clockBookingId, $amount, $currency, $reservationId);
        }
        if (empty($folioResult['success'])) {
            return $this->markManualReview(
                $accountingId,
                ClockAccountingReason::forFolioMessage((string) ($folioResult['message'] ?? '')),
                (string) ($folioResult['message'] ?? \__('Unable to select a Clock folio.', 'must-hotel-booking'))
            );
        }
        $folioId = (string) ($folioResult['folio_id'] ?? '');
        $direction = (string) ($postingDecision['mode'] ?? '') === 'deposit' ? 'deposit' : 'payment';
        $recovered = $this->recoverPostedRetry($accounting, $accountingId, $folioId, $reservationId);
        if (\is_array($recovered)) {
            return $recovered;
        }
        $postResult = $this->postAndRecord($accountingId, $folioId, $gateway, $direction, $amount, $currency, $transactionId, $idempotencyKey, $reservationId);
        if (empty($postResult['success']) && !empty($postResult['retry']) && $enqueueRetry) {
            $this->enqueueRetry($accountingId, $folioId);
        }
        return $postResult;
    }
    /** @return array<string, mixed> */
    public function syncRefund(int $refundId, bool $enqueueRetry = true): array
    {
        $refund = $this->refunds->getRefund($refundId);
        if (!\is_array($refund)) {
            return $this->result(false, false, \__('Refund row not found.', 'must-hotel-booking'));
        }
        $reservationId = (int) ($refund['reservation_id'] ?? 0);
        $reservation = $this->reservations->getReservation($reservationId);
        if (!\is_array($reservation) || (string) ($reservation['provider'] ?? '') !== ProviderManager::CLOCK_MODE) {
            $this->refunds->updateRefund($refundId, [
                'clock_sync_status' => 'not_required',
                'updated_at' => $this->now(),
            ]);
            return $this->result(true, false, 'not_clock_reservation');
        }
        if ((string) ($refund['clock_refund_item_id'] ?? '') !== '') {
            return $this->result(true, false, 'already_synced');
        }
        $gateway = \sanitize_key((string) ($refund['gateway'] ?? ''));
        $providerRefundId = $this->refundReference($refund);
        $providerPaymentReference = \sanitize_text_field((string) ($refund['provider_payment_reference'] ?? ''));
        $idempotencyKey = $this->refundIdempotencyKey($refundId, $providerRefundId);
        $amount = -1 * \abs(\round((float) ($refund['amount'] ?? 0.0), 2));
        $currency = $this->currency((string) ($refund['currency'] ?? ''));
        $clockBookingId = $this->clockBookingId($reservation);
        $clockReservationId = $this->clockReservationId($reservation);
        $accounting = $this->accounting->getOrCreateByIdempotencyKey($idempotencyKey, [
            'refund_id' => $refundId,
            'payment_id' => (int) ($refund['payment_id'] ?? 0),
            'reservation_id' => $reservationId,
            'booking_id' => (string) ($refund['booking_id'] ?? ($reservation['booking_id'] ?? '')),
            'gateway' => $gateway,
            'provider_transaction_id' => $providerPaymentReference,
            'provider_refund_id' => $providerRefundId,
            'clock_booking_id' => $clockBookingId,
            'clock_reservation_id' => $clockReservationId,
            'direction' => 'refund',
            'amount' => $amount,
            'amount_minor' => -1 * $this->minorUnits(\abs($amount), $currency),
            'currency' => $currency,
            'status' => 'pending',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        if (empty($accounting)) {
            return $this->result(false, false, \__('Unable to create Clock refund accounting row.', 'must-hotel-booking'));
        }
        $accountingId = (int) ($accounting['id'] ?? 0);
        if ((string) ($accounting['status'] ?? '') === 'posted' && (string) ($accounting['idempotency_key'] ?? '') === $idempotencyKey) {
            $this->mirrorRefundPosted($refundId, $accounting);
            return $this->result(true, false, 'already_posted', ['accounting_id' => $accountingId]);
        }
        if ((string) ($accounting['status'] ?? '') === 'handled_manually') {
            return $this->result(true, false, 'handled_manually', ['accounting_id' => $accountingId]);
        }
        if ($clockBookingId === '') {
            $result = $this->markManualReview($accountingId, ClockAccountingReason::CLOCK_BOOKING_NOT_FOUND, \__('Clock booking or reservation ID is missing; refund remains completed locally.', 'must-hotel-booking'));
            $this->mirrorRefundFailure($refundId, 'manual_review', (string) ($result['message'] ?? ''));
            return $result;
        }
        $originalAccounting = $this->accounting->findPostedPaymentForRefund($refund);
        if (!\is_array($originalAccounting)) {
            $message = \__('Refund cannot be posted to Clock because the original successful payment folio is unknown.', 'must-hotel-booking');
            $result = $this->markManualReview($accountingId, ClockAccountingReason::REFUND_REQUIRES_MANUAL_CLOCK_ACTION, $message);
            $this->mirrorRefundFailure($refundId, 'manual_review', $message);
            return $result;
        }
        $folioId = \sanitize_text_field((string) ($originalAccounting['clock_folio_id'] ?? ''));
        $folioResult = $this->folios->validateRefundFolio($clockBookingId, $folioId, $reservationId);
        if (empty($folioResult['success'])) {
            $message = (string) ($folioResult['message'] ?? \__('Unable to validate original Clock payment folio.', 'must-hotel-booking'));
            $result = $this->markManualReview($accountingId, ClockAccountingReason::forFolioMessage($message), $message);
            $this->mirrorRefundFailure($refundId, 'manual_review', $message);
            return $result;
        }
        $reference = $providerRefundId !== '' ? $providerRefundId : $idempotencyKey;
        $recovered = $this->recoverPostedRetry($accounting, $accountingId, $folioId, $reservationId);
        if (\is_array($recovered)) {
            $updated = $this->accounting->get($accountingId);
            $this->mirrorRefundPosted($refundId, \is_array($updated) ? $updated : []);
            return $recovered;
        }
        $postResult = $this->postAndRecord($accountingId, $folioId, $gateway, 'refund', $amount, $currency, $reference, $idempotencyKey, $reservationId);
        if (!empty($postResult['success'])) {
            $updated = $this->accounting->get($accountingId);
            $this->mirrorRefundPosted($refundId, \is_array($updated) ? $updated : []);
        } else {
            $this->mirrorRefundFailure($refundId, !empty($postResult['retry']) ? 'retrying' : 'failed', (string) ($postResult['message'] ?? ''));
            if (!empty($postResult['retry']) && $enqueueRetry) {
                $this->enqueueRetry($accountingId, $folioId);
            }
        }
        return $postResult;
    }
    /** @return array<string, mixed> */
    public function retryAccounting(int $accountingId): array
    {
        $row = $this->accounting->get($accountingId);
        if (!\is_array($row)) {
            return $this->result(false, false, \__('Clock accounting row not found.', 'must-hotel-booking'));
        }
        if ((string) ($row['status'] ?? '') === 'posted') {
            return $this->result(true, false, 'already_posted', ['accounting_id' => $accountingId]);
        }
        if ((string) ($row['status'] ?? '') === 'handled_manually') {
            return $this->result(true, false, 'handled_manually', ['accounting_id' => $accountingId]);
        }
        if ((string) ($row['direction'] ?? '') === 'refund') {
            return $this->syncRefund((int) ($row['refund_id'] ?? 0), false);
        }
        return $this->syncPaidPayment((int) ($row['payment_id'] ?? 0), false);
    }
    /** @param array<string, mixed> $job @return array{success: bool, retry: bool, message: string} */
    public function executeSyncJob(array $job): array
    {
        $accountingId = (int) ($job['target_local_id'] ?? 0);
        $result = $this->retryAccounting($accountingId);
        return [
            'success' => !empty($result['success']),
            'retry' => !empty($result['retry']),
            'message' => isset($result['message']) ? (string) $result['message'] : '',
        ];
    }
    /** @return array<string, mixed> */
    private function postAndRecord(
        int $accountingId,
        string $folioId,
        string $gateway,
        string $direction,
        float $amount,
        string $currency,
        string $reference,
        string $idempotencyKey,
        int $reservationId
    ): array {
        if (!$this->accounting->claimPostingAttempt($accountingId, $folioId)) {
            $current = $this->accounting->get($accountingId);
            if (\is_array($current) && (string) ($current['status'] ?? '') === 'posted') {
                return $this->result(true, false, 'already_posted', ['accounting_id' => $accountingId]);
            }
            return $this->result(true, false, 'already_in_progress', ['accounting_id' => $accountingId]);
        }
        $beforeResult = $this->folios->readFolioBalance($folioId, $reservationId);
        $balanceBefore = !empty($beforeResult['success']) && isset($beforeResult['balance'])
            ? \round((float) $beforeResult['balance'], 2)
            : null;
        $expectedBalance = $balanceBefore !== null
            ? \round($balanceBefore + $amount, 2)
            : null;
        $this->accounting->update($accountingId, [
            'clock_folio_id' => $folioId,
            'balance_before' => $balanceBefore,
            'expected_balance' => $expectedBalance,
            'actual_balance' => null,
            'reconciliation_status' => 'pending_verification',
            'updated_at' => $this->now(),
        ]);
        $postResult = $this->folios->postCreditItem(
            $folioId,
            $gateway,
            $direction,
            $amount,
            $currency,
            $reference,
            $idempotencyKey,
            $reservationId
        );
        if (empty($postResult['success'])) {
            return $this->markFailure(
                $accountingId,
                ClockAccountingReason::forFolioMessage(
                    (string) ($postResult['message'] ?? \__('Clock credit item create request failed.', 'must-hotel-booking')),
                    (string) ($postResult['error_code'] ?? '')
                ),
                (string) ($postResult['message'] ?? \__('Clock credit item create request failed.', 'must-hotel-booking')),
                !empty($postResult['retryable']),
                false,
                (string) ($postResult['error_code'] ?? '')
            );
        }
        $afterResult = $this->folios->readFolioBalance($folioId, $reservationId);
        $actualBalance = !empty($afterResult['success']) && isset($afterResult['balance'])
            ? \round((float) $afterResult['balance'], 2)
            : null;
        $matchesExpected = $expectedBalance !== null
            && $actualBalance !== null
            && \abs($actualBalance - $expectedBalance) < 0.01;
        $verificationStatus = $matchesExpected ? 'verified_expected_balance' : 'balance_review_required';
        $verificationMessage = $matchesExpected
            ? ''
            : (string) ($afterResult['message'] ?? $beforeResult['message'] ?? \__('Clock folio balance could not be reconciled to the expected result.', 'must-hotel-booking'));
        $postedAt = $this->now();
        $this->accounting->update($accountingId, [
            'status' => 'posted',
            'direction' => $direction,
            'clock_credit_item_id' => (string) ($postResult['credit_item_id'] ?? ''),
            'verification_status' => $verificationStatus !== '' ? $verificationStatus : 'unknown',
            'balance_before' => $balanceBefore,
            'expected_balance' => $expectedBalance,
            'actual_balance' => $actualBalance,
            'reconciliation_status' => $matchesExpected ? 'verified' : 'manual_review',
            'last_error_code' => '',
            'last_error' => $verificationMessage,
            'next_retry_at' => null,
            'posted_at' => $postedAt,
            'verified_at' => \in_array(
                $verificationStatus,
                ['verified_paid', 'verified_deposit', 'verified_expected_balance'],
                true
            ) ? $postedAt : null,
            'updated_at' => $postedAt,
        ]);
        return $this->result(true, false, 'posted', ['accounting_id' => $accountingId]);
    }
    /** @param array<string, mixed> $accounting @return array<string, mixed>|null */
    private function recoverPostedRetry(array $accounting, int $accountingId, string $folioId, int $reservationId): ?array
    {
        $status = \sanitize_key((string) ($accounting['status'] ?? ''));
        if (!\in_array($status, ['failed', 'manual_review', 'retrying'], true)) {
            return null;
        }

        if ($folioId === '' || !isset($accounting['expected_balance']) || $accounting['expected_balance'] === '' || $accounting['expected_balance'] === null) {
            return null;
        }

        $expectedBalance = \round((float) $accounting['expected_balance'], 2);
        $balanceResult = $this->folios->readFolioBalance($folioId, $reservationId);
        if (empty($balanceResult['success']) || !isset($balanceResult['balance'])) {
            return null;
        }

        $actualBalance = \round((float) $balanceResult['balance'], 2);
        if (\abs($actualBalance - $expectedBalance) >= 0.01) {
            $this->accounting->update($accountingId, [
                'clock_folio_id' => $folioId,
                'actual_balance' => $actualBalance,
                'updated_at' => $this->now(),
            ]);
            return null;
        }

        $postedAt = (string) ($accounting['posted_at'] ?? '');
        if ($postedAt === '') {
            $postedAt = $this->now();
        }

        $clockCreditItemId = (string) ($accounting['clock_credit_item_id'] ?? '');
        if ($clockCreditItemId === '') {
            $clockCreditItemId = (string) ($accounting['idempotency_key'] ?? '');
        }

        $verifiedAt = $this->now();
        $this->accounting->update($accountingId, [
            'status' => 'posted',
            'clock_folio_id' => $folioId,
            'clock_credit_item_id' => $clockCreditItemId,
            'verification_status' => 'verified_expected_balance',
            'actual_balance' => $actualBalance,
            'reconciliation_status' => 'verified',
            'last_error_code' => '',
            'last_error' => '',
            'next_retry_at' => null,
            'posted_at' => $postedAt,
            'verified_at' => $verifiedAt,
            'updated_at' => $verifiedAt,
        ]);

        return $this->result(true, false, 'recovered_posted', ['accounting_id' => $accountingId]);
    }
    /** @return array<string, mixed> */
    private function markManualReview(int $accountingId, string $reasonCode, string $message): array
    {
        $this->accounting->update($accountingId, [
            'status' => 'manual_review',
            'last_error_code' => ClockAccountingReason::normalize($reasonCode, ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION),
            'last_error' => $message,
            'next_retry_at' => null,
            'updated_at' => $this->now(),
        ]);
        return $this->result(false, false, $message, ['accounting_id' => $accountingId]);
    }
    /** @return array<string, mixed> */
    private function markFailure(int $accountingId, string $reasonCode, string $message, bool $retryable, bool $enqueueRetry, string $errorCode = ''): array
    {
        $status = $retryable ? 'failed' : ($errorCode === 'forbidden' ? 'manual_review' : 'failed');
        $this->accounting->update($accountingId, [
            'status' => $status,
            'last_error_code' => ClockAccountingReason::normalize($reasonCode, ClockAccountingReason::CLOCK_REQUEST_FAILED),
            'last_error' => $message,
            'next_retry_at' => $retryable ? $this->datePlusMinutes(5) : null,
            'updated_at' => $this->now(),
        ]);
        if ($retryable && $enqueueRetry) {
            $row = $this->accounting->get($accountingId);
            $this->enqueueRetry($accountingId, \is_array($row) ? (string) ($row['clock_folio_id'] ?? '') : '');
        }
        return $this->result(false, $retryable, $message, ['accounting_id' => $accountingId]);
    }
    /** @param array<string, mixed> $reservation @return array{mode: string, message: string, reason_code?: string} */
    private function paymentPostingDecision(array $reservation): array
    {
        unset($reservation);
        $configuredMode = ClockConfig::paymentPostingMode();
        if ($configuredMode === 'manual_clock_accounting') {
            return [
                'mode' => 'manual',
                'reason_code' => ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
                'message' => \__(
                    'Clock payment posting mode is manual accounting; the website payment remains paid locally and must be recorded manually in Clock.',
                    'must-hotel-booking'
                ),
            ];
        }
        /*
         * This is an explicit legacy mode only.
         * It is the only mode allowed to post a website payment to the
         * normal accommodation folio.
         */
        if ($configuredMode === 'folio_payment_only') {
            return [
                'mode' => 'folio',
                'message' => '',
            ];
        }
        /*
         * Stripe and PokPay payments are advance deposits regardless of whether
         * the booking is future, same-day, or currently staying.
         */
        if (ClockConfig::hasVerifiedDepositPaymentEndpoint()) {
            return [
                'mode' => 'deposit',
                'message' => '',
            ];
        }
        return [
            'mode' => 'manual',
            'reason_code' => ClockAccountingReason::FUTURE_BOOKING_REQUIRES_DEPOSIT_ENDPOINT,
            'message' => \__(
                'The website payment was not posted to Clock because the verified deposit-folio endpoints are unavailable. The normal accommodation folio was intentionally left untouched.',
                'must-hotel-booking'
            ),
        ];
    }
    /** @param array<string, mixed> $reservation */
    private function isFutureReservation(array $reservation): bool
    {
        $checkin = \trim((string) ($reservation['checkin'] ?? ''));
        if ($checkin === '') {
            return true;
        }
        $today = \function_exists('current_time') ? \current_time('Y-m-d') : \gmdate('Y-m-d');
        return $checkin > $today;
    }
    private function enqueueRetry(int $accountingId, string $folioId): void
    {
        if ($accountingId <= 0) {
            return;
        }
        $this->jobs->enqueueOnce([
            'provider' => ProviderManager::CLOCK_MODE,
            'operation' => 'clock_folio_accounting_sync',
            'target_type' => 'clock_folio_accounting',
            'target_local_id' => $accountingId,
            'target_external_id' => $folioId,
            'status' => ProviderSyncJobRepository::STATUS_PENDING,
            'max_attempts' => 5,
            'payload' => \wp_json_encode(['accounting_id' => $accountingId]),
        ]);
    }
    /** @param array<string, mixed> $accounting */
    private function mirrorRefundPosted(int $refundId, array $accounting): void
    {
        if ($refundId <= 0) {
            return;
        }
        $clockItemId = (string) ($accounting['clock_credit_item_id'] ?? '');
        if ($clockItemId === '') {
            $clockItemId = (string) ($accounting['idempotency_key'] ?? '');
        }
        $this->refunds->updateRefund($refundId, [
            'clock_folio_id' => (string) ($accounting['clock_folio_id'] ?? ''),
            'clock_refund_item_id' => $clockItemId,
            'clock_sync_status' => 'synced',
            'failed_reason' => '',
            'updated_at' => $this->now(),
        ]);
    }
    private function mirrorRefundFailure(int $refundId, string $status, string $message): void
    {
        if ($refundId <= 0) {
            return;
        }
        $this->refunds->updateRefund($refundId, [
            'clock_sync_status' => $status,
            'failed_reason' => $message,
            'updated_at' => $this->now(),
        ]);
    }
    /** @param array<string, mixed> $reservation */
    private function clockBookingId(array $reservation): string
    {
        foreach (['provider_booking_id', 'provider_reservation_id'] as $key) {
            if (isset($reservation[$key]) && \trim((string) $reservation[$key]) !== '') {
                return \sanitize_text_field((string) $reservation[$key]);
            }
        }
        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        foreach (['provider_booking_id', 'provider_reservation_id', 'clock_booking_id', 'booking_id'] as $key) {
            if (isset($metadata[$key]) && \trim((string) $metadata[$key]) !== '') {
                return \sanitize_text_field((string) $metadata[$key]);
            }
        }
        return '';
    }
    /** @param array<string, mixed> $reservation */
    private function clockReservationId(array $reservation): string
    {
        if (isset($reservation['provider_reservation_id']) && \trim((string) $reservation['provider_reservation_id']) !== '') {
            return \sanitize_text_field((string) $reservation['provider_reservation_id']);
        }
        return $this->clockBookingId($reservation);
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
    /** @param array<string, mixed> $refund */
    private function refundReference(array $refund): string
    {
        foreach (['provider_refund_reference', 'provider_refund_id', 'stripe_refund_id'] as $key) {
            if (isset($refund[$key]) && \trim((string) $refund[$key]) !== '') {
                return \sanitize_text_field((string) $refund[$key]);
            }
        }
        return '';
    }
    private function paymentIdempotencyKey(int $paymentId, string $transactionId): string
    {
        return 'clock-accounting-payment-' . $paymentId . '-' . \sha1($transactionId);
    }
    private function refundIdempotencyKey(int $refundId, string $providerRefundId): string
    {
        return 'clock-accounting-refund-' . $refundId . '-' . \sha1($providerRefundId !== '' ? $providerRefundId : (string) $refundId);
    }
    private function minorUnits(float $amount, string $currency): int
    {
        unset($currency);
        return (int) \round($amount * 100);
    }
    private function currency(string $currency): string
    {
        $currency = \strtoupper(\sanitize_text_field($currency));
        return $currency !== '' ? $currency : \strtoupper(MustBookingConfig::get_currency());
    }
    /** @return array<string, mixed> */
    private function result(bool $success, bool $retry, string $message, array $extra = []): array
    {
        return [
            'success' => $success,
            'retry' => $retry,
            'message' => $message,
        ] + $extra;
    }
    private function datePlusMinutes(int $minutes): string
    {
        $timestamp = \time() + (\max(1, $minutes) * 60);
        return \function_exists('wp_date') ? \wp_date('Y-m-d H:i:s', $timestamp) : \gmdate('Y-m-d H:i:s', $timestamp);
    }
    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
