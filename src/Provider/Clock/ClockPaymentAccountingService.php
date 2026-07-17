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
        $amount = \round((float) ($payment['amount'] ?? 0.0), 2);
        $currency = $this->currency((string) ($payment['currency'] ?? ''));
        $clockBookingId = $this->clockBookingId($reservation);
        $clockReservationId = $this->clockReservationId($reservation);
        $idempotencyKey = $this->paymentIdempotencyKey($gateway, $reservationId, $transactionId);
        $accounting = $this->accounting->findPaymentByProviderTransaction($gateway, $reservationId, $transactionId);
        if (!\is_array($accounting)) {
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
        }
        if (empty($accounting)) {
            return $this->result(false, false, \__('Unable to create Clock accounting row.', 'must-hotel-booking'));
        }
        $accountingId = (int) ($accounting['id'] ?? 0);
        if ((string) ($accounting['status'] ?? '') === 'posted') {
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
        $standardBalancesBefore = ['success' => true, 'balances' => [], 'message' => ''];
        if ($direction === 'deposit') {
            $standardBalancesBefore = $this->folios->readStandardFolioBalances($clockBookingId, $reservationId, $folioId);
            if (empty($standardBalancesBefore['success'])) {
                return $this->markManualReview(
                    $accountingId,
                    ClockAccountingReason::CLOCK_REQUEST_FAILED,
                    (string) ($standardBalancesBefore['message'] ?? \__('Unable to snapshot the standard Clock folio before deposit posting.', 'must-hotel-booking'))
                );
            }
        }
        $this->persistAccountingMetadata($reservation, [
            'status' => 'folio_selected',
            'gateway' => $gateway,
            'provider_transaction_id' => $transactionId,
            'clock_folio_id' => $folioId,
            'direction' => $direction,
            'standard_folio_balances_before' => $standardBalancesBefore['balances'] ?? [],
            'updated_at' => $this->now(),
        ]);
        $recovered = $this->recoverPostedRetry($accounting, $accountingId, $folioId, $reservationId);
        if (\is_array($recovered)) {
            return $recovered;
        }
        $postResult = $this->postAndRecord(
            $accountingId,
            $folioId,
            $gateway,
            $direction,
            $amount,
            $currency,
            $transactionId,
            $idempotencyKey,
            $reservationId,
            $clockBookingId,
            \is_array($standardBalancesBefore['balances'] ?? null) ? $standardBalancesBefore['balances'] : []
        );
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
        $originalDirection = \sanitize_key((string) ($originalAccounting['direction'] ?? ''));
        if ($originalDirection !== 'deposit') {
            $message = \__('The original website payment was not recorded on a verified Clock deposit folio. Automatic refund accounting stopped for manual review.', 'must-hotel-booking');
            $result = $this->markManualReview($accountingId, ClockAccountingReason::REFUND_REQUIRES_MANUAL_CLOCK_ACTION, $message);
            $this->mirrorRefundFailure($refundId, 'manual_review', $message);
            return $result;
        }
        $folioResult = $this->folios->validateRefundFolio($clockBookingId, $folioId, $reservationId, true);
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
        $postResult = $this->postAndRecord(
            $accountingId,
            $folioId,
            $gateway,
            'refund',
            $amount,
            $currency,
            $reference,
            $idempotencyKey,
            $reservationId,
            $clockBookingId,
            []
        );
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
        int $reservationId,
        string $clockBookingId,
        array $standardBalancesBefore
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
        $isDepositAccountingTarget = $direction === 'deposit'
            || ($direction === 'refund' && !empty($beforeResult['deposit']));
        $expectedBalance = $this->expectedRawBalance($balanceBefore, $amount, $isDepositAccountingTarget);
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
            if (!empty($postResult['ambiguous'])) {
                return $this->markManualReview(
                    $accountingId,
                    ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
                    (string) ($postResult['message'] ?? \__('Clock returned an ambiguous payment-posting outcome. Verify the folio by provider reference before any new write.', 'must-hotel-booking'))
                );
            }
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
        $depositBalanceVerification = $this->verifyDepositFolioBalance(
            $direction,
            $amount,
            $balanceBefore,
            $actualBalance,
            $beforeResult,
            $afterResult
        );
        $matchesExpected = $depositBalanceVerification['applicable']
            ? !empty($depositBalanceVerification['verified'])
            : ($expectedBalance !== null
                && $actualBalance !== null
                && \abs($actualBalance - $expectedBalance) < 0.01);
        $standardIsolation = ['verified' => true, 'message' => '', 'after' => []];
        if ($direction === 'deposit') {
            $standardIsolation = $this->verifyStandardFolioIsolation(
                $clockBookingId,
                $reservationId,
                $folioId,
                $standardBalancesBefore
            );
        }
        $isolationVerified = !empty($standardIsolation['verified']);
        $creditItemConfirmed = (string) ($postResult['credit_item_id'] ?? '') !== '';
        $verification = $this->accountingVerificationResult(
            $direction,
            $matchesExpected,
            $isolationVerified,
            $creditItemConfirmed,
            $depositBalanceVerification,
            (string) ($standardIsolation['message'] ?? ''),
            (string) ($afterResult['message'] ?? $beforeResult['message'] ?? '')
        );
        $verificationStatus = $verification['status'];
        $verificationMessage = $verification['message'];
        $reconciliationStatus = !empty($verification['verified']) ? 'verified' : 'manual_review';
        $postedAt = $this->now();
        $this->accounting->update($accountingId, [
            'status' => 'posted',
            'direction' => $direction,
            'clock_credit_item_id' => (string) ($postResult['credit_item_id'] ?? ''),
            'verification_status' => $verificationStatus !== '' ? $verificationStatus : 'unknown',
            'balance_before' => $balanceBefore,
            'expected_balance' => $expectedBalance,
            'actual_balance' => $actualBalance,
            'reconciliation_status' => $reconciliationStatus,
            'last_error_code' => !empty($verification['verified']) ? '' : $verificationStatus,
            'last_error' => $verificationMessage,
            'next_retry_at' => null,
            'posted_at' => $postedAt,
            'verified_at' => \in_array(
                $verificationStatus,
                ['verified_paid', 'verified_deposit', 'verified_expected_balance', 'verified_deposit_isolated'],
                true
            ) ? $postedAt : null,
            'updated_at' => $postedAt,
        ]);
        $reservation = $this->reservations->getReservation($reservationId);
        if (\is_array($reservation)) {
            $this->persistAccountingMetadata($reservation, [
                'status' => 'posted',
                'gateway' => $gateway,
                'provider_transaction_id' => $reference,
                'clock_folio_id' => $folioId,
                'clock_credit_item_id' => (string) ($postResult['credit_item_id'] ?? ''),
                'direction' => $direction,
                'standard_folio_unchanged' => $isolationVerified,
                'standard_folio_balances_before' => $standardBalancesBefore,
                'standard_folio_balances_after' => $standardIsolation['after'] ?? [],
                'deposit_balance_verification' => $depositBalanceVerification['diagnostics'] ?? [],
                'updated_at' => $postedAt,
            ]);
        }
        return $this->result(true, false, 'posted', ['accounting_id' => $accountingId]);
    }
    private function isDepositAccountingDirection(string $direction): bool
    {
        return \in_array($direction, ['deposit', 'refund'], true);
    }

    private function expectedRawBalance(?float $balanceBefore, float $amount, bool $depositAccountingTarget): ?float
    {
        if ($balanceBefore === null) {
            return null;
        }

        return $depositAccountingTarget
            ? \round($balanceBefore - $amount, 2)
            : \round($balanceBefore + $amount, 2);
    }

    private function normalizedDepositHeld(float $rawBalance): float
    {
        return \round(\max(0.0, -$rawBalance), 2);
    }

    /**
     * @param array<string, mixed> $beforeResult
     * @param array<string, mixed> $afterResult
     * @return array{applicable: bool, verified: bool, status: string, message: string, diagnostics: array<string, mixed>}
     */
    private function verifyDepositFolioBalance(
        string $direction,
        float $amount,
        ?float $balanceBefore,
        ?float $actualBalance,
        array $beforeResult,
        array $afterResult
    ): array {
        if (!$this->isDepositAccountingDirection($direction)) {
            return [
                'applicable' => false,
                'verified' => false,
                'status' => '',
                'message' => '',
                'diagnostics' => [],
            ];
        }
        if ($direction === 'refund' && empty($beforeResult['deposit']) && empty($afterResult['deposit'])) {
            return [
                'applicable' => false,
                'verified' => false,
                'status' => '',
                'message' => '',
                'diagnostics' => [],
            ];
        }

        $expectedRawBalance = $this->expectedRawBalance($balanceBefore, $amount, true);
        $diagnostics = [
            'clock_deposit_raw_balance_before' => $balanceBefore,
            'clock_deposit_expected_raw_balance' => $expectedRawBalance,
            'clock_deposit_raw_balance_after' => $actualBalance,
            'expected_deposit_amount' => $balanceBefore !== null ? \round($this->normalizedDepositHeld($balanceBefore) + $amount, 2) : null,
            'normalized_deposit_amount_held' => $actualBalance !== null ? $this->normalizedDepositHeld($actualBalance) : null,
            'folio_deposit_before' => !empty($beforeResult['deposit']),
            'folio_deposit_after' => !empty($afterResult['deposit']),
            'folio_postable_before' => !empty($beforeResult['postable']),
            'folio_postable_after' => !empty($afterResult['postable']),
        ];

        if (empty($beforeResult['success']) || empty($afterResult['success']) || $balanceBefore === null || $actualBalance === null || $expectedRawBalance === null) {
            return [
                'applicable' => true,
                'verified' => false,
                'status' => 'manual_review_deposit_balance_mismatch',
                'message' => \__('Clock deposit folio balance could not be read before and after posting. Manual accounting review is required.', 'must-hotel-booking'),
                'diagnostics' => $diagnostics,
            ];
        }

        if (empty($beforeResult['deposit']) || empty($afterResult['deposit'])) {
            return [
                'applicable' => true,
                'verified' => false,
                'status' => 'manual_review_deposit_folio_invalid',
                'message' => \__('Clock did not confirm the accounting target as a deposit folio before and after posting. Manual accounting review is required.', 'must-hotel-booking'),
                'diagnostics' => $diagnostics,
            ];
        }

        $expectedDepositHeld = (float) $diagnostics['expected_deposit_amount'];
        $actualDepositHeld = (float) $diagnostics['normalized_deposit_amount_held'];

        if ($expectedDepositHeld < -0.005 || $actualBalance > 0.005) {
            return [
                'applicable' => true,
                'verified' => false,
                'status' => 'manual_review_deposit_balance_mismatch',
                'message' => \__('Clock returned an unsupported deposit-folio balance sign for the website accounting entry. Manual accounting review is required.', 'must-hotel-booking'),
                'diagnostics' => $diagnostics,
            ];
        }

        if (\abs($actualBalance - $expectedRawBalance) >= 0.01 || \abs($actualDepositHeld - $expectedDepositHeld) >= 0.01) {
            return [
                'applicable' => true,
                'verified' => false,
                'status' => 'manual_review_deposit_balance_mismatch',
                'message' => \__('Clock deposit folio balance did not match the expected signed raw balance and normalized deposit amount. Manual accounting review is required.', 'must-hotel-booking'),
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'applicable' => true,
            'verified' => true,
            'status' => 'verified_deposit_balance',
            'message' => '',
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array{applicable: bool, verified: bool, status: string, message: string, diagnostics: array<string, mixed>} $depositBalanceVerification
     * @return array{verified: bool, status: string, message: string}
     */
    private function accountingVerificationResult(
        string $direction,
        bool $matchesExpected,
        bool $isolationVerified,
        bool $creditItemConfirmed,
        array $depositBalanceVerification,
        string $isolationMessage,
        string $balanceMessage
    ): array {
        if ($direction === 'deposit') {
            if (empty($depositBalanceVerification['verified'])) {
                return [
                    'verified' => false,
                    'status' => (string) ($depositBalanceVerification['status'] ?: 'manual_review_deposit_balance_mismatch'),
                    'message' => (string) ($depositBalanceVerification['message'] ?: $balanceMessage),
                ];
            }

            if (!$isolationVerified) {
                return [
                    'verified' => false,
                    'status' => 'manual_review_normal_folio_changed',
                    'message' => $isolationMessage !== ''
                        ? $isolationMessage
                        : \__('The standard Clock accommodation folio changed during deposit posting. Manual accounting review is required.', 'must-hotel-booking'),
                ];
            }

            if (!$creditItemConfirmed) {
                return [
                    'verified' => false,
                    'status' => 'manual_review_credit_item_unconfirmed',
                    'message' => \__('Clock accepted the deposit posting but did not return a durable credit-item reference. Manual accounting review is required.', 'must-hotel-booking'),
                ];
            }

            return [
                'verified' => true,
                'status' => 'verified_deposit_isolated',
                'message' => '',
            ];
        }

        if (!empty($depositBalanceVerification['applicable']) && empty($depositBalanceVerification['verified'])) {
            return [
                'verified' => false,
                'status' => (string) ($depositBalanceVerification['status'] ?: 'manual_review_deposit_balance_mismatch'),
                'message' => (string) ($depositBalanceVerification['message'] ?: $balanceMessage),
            ];
        }

        return [
            'verified' => $matchesExpected,
            'status' => $matchesExpected ? 'verified_expected_balance' : 'balance_review_required',
            'message' => $matchesExpected
                ? ''
                : ($balanceMessage !== '' ? $balanceMessage : \__('Clock folio balance could not be reconciled to the expected result.', 'must-hotel-booking')),
        ];
    }

    /** @param array<string, mixed> $accounting @return array<string, mixed>|null */
    private function recoverPostedRetry(array $accounting, int $accountingId, string $folioId, int $reservationId): ?array
    {
        $status = \sanitize_key((string) ($accounting['status'] ?? ''));
        if (!\in_array($status, ['failed', 'manual_review', 'retrying'], true)) {
            return null;
        }

        $direction = \sanitize_key((string) ($accounting['direction'] ?? ''));
        $reference = $direction === 'refund'
            ? \sanitize_text_field((string) ($accounting['provider_refund_id'] ?? ''))
            : \sanitize_text_field((string) ($accounting['provider_transaction_id'] ?? ''));
        $amount = \round((float) ($accounting['amount'] ?? 0.0), 2);
        $currency = \strtoupper(\sanitize_text_field((string) ($accounting['currency'] ?? '')));

        if ($folioId === '' || $reference === '' || $currency === '' || \abs($amount) < 0.01) {
            return $this->markManualReview(
                $accountingId,
                ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
                \__('Clock accounting retry is missing the exact provider reference, amount, currency, or folio identity.', 'must-hotel-booking')
            );
        }

        $lookup = $this->folios->findCreditItemByReference($folioId, $reference, $amount, $currency, $reservationId);
        if (empty($lookup['success'])) {
            return $this->markManualReview(
                $accountingId,
                ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
                (string) ($lookup['message'] ?? \__('Clock credit-item reconciliation could not be completed.', 'must-hotel-booking'))
            );
        }
        if (!empty($lookup['ambiguous'])) {
            return $this->markManualReview(
                $accountingId,
                ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
                (string) ($lookup['message'] ?? \__('Clock credit-item reconciliation returned multiple matches.', 'must-hotel-booking'))
            );
        }
        if (empty($lookup['found'])) {
            return (string) ($accounting['last_error_code'] ?? '') === 'rate_limited'
                ? null
                : $this->markManualReview(
                    $accountingId,
                    ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
                    \__('Clock does not document automatic replay for this failed write. No exact credit-item match was found, so manual review is required before posting again.', 'must-hotel-booking')
                );
        }

        $clockCreditItemId = (string) ($lookup['credit_item_id'] ?? '');
        if ($clockCreditItemId === '') {
            return $this->markManualReview(
                $accountingId,
                ClockAccountingReason::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
                \__('Clock matched the provider reference but did not return a durable credit-item ID.', 'must-hotel-booking')
            );
        }

        $balanceResult = $this->folios->readFolioBalance($folioId, $reservationId);
        $actualBalance = !empty($balanceResult['success']) && isset($balanceResult['balance'])
            ? \round((float) $balanceResult['balance'], 2)
            : null;

        $postedAt = (string) ($accounting['posted_at'] ?? '');
        if ($postedAt === '') {
            $postedAt = $this->now();
        }

        $verifiedAt = $this->now();
        $this->accounting->update($accountingId, [
            'status' => 'posted',
            'clock_folio_id' => $folioId,
            'clock_credit_item_id' => $clockCreditItemId,
            'verification_status' => 'verified_credit_item_reference',
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
         * Stripe and PokPay payments are advance deposits regardless of whether
         * the booking is future, same-day, or currently staying.
         * Legacy folio_payment_only configuration is intentionally treated as
         * deposit mode so online payments cannot settle the accommodation folio.
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
            $this->mirrorRefundFailure(
                $refundId,
                'manual_review',
                \__('Clock refund accounting has no durable credit-item ID and cannot be marked synchronized.', 'must-hotel-booking')
            );
            return;
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

    /**
     * @param array<string, float|null> $before
     * @return array{verified: bool, message: string, after: array<string, float|null>}
     */
    private function verifyStandardFolioIsolation(
        string $clockBookingId,
        int $reservationId,
        string $depositFolioId,
        array $before
    ): array {
        $afterResult = $this->folios->readStandardFolioBalances($clockBookingId, $reservationId, $depositFolioId);

        if (empty($afterResult['success'])) {
            return [
                'verified' => false,
                'message' => (string) ($afterResult['message'] ?? \__('Unable to verify that the standard Clock folio remained unchanged.', 'must-hotel-booking')),
                'after' => [],
            ];
        }

        $after = \is_array($afterResult['balances'] ?? null) ? $afterResult['balances'] : [];

        foreach ($before as $folioId => $balanceBefore) {
            if (!\array_key_exists($folioId, $after)) {
                return [
                    'verified' => false,
                    'message' => \__('A standard Clock folio disappeared during deposit verification. Manual accounting review is required.', 'must-hotel-booking'),
                    'after' => $after,
                ];
            }

            $balanceAfter = $after[$folioId];

            if ($balanceBefore === null || $balanceAfter === null) {
                continue;
            }

            if (\abs((float) $balanceBefore - (float) $balanceAfter) >= 0.01) {
                return [
                    'verified' => false,
                    'message' => \__('The standard Clock accommodation folio changed while posting the website deposit. Manual accounting review is required.', 'must-hotel-booking'),
                    'after' => $after,
                ];
            }
        }

        return [
            'verified' => true,
            'message' => '',
            'after' => $after,
        ];
    }

    /** @param array<string, mixed> $reservation @param array<string, mixed> $entry */
    private function persistAccountingMetadata(array $reservation, array $entry): void
    {
        $reservationId = (int) ($reservation['id'] ?? 0);

        if ($reservationId <= 0) {
            return;
        }

        $metadata = $this->decodeMetadata($reservation['provider_metadata'] ?? null);
        $transactionId = \sanitize_text_field((string) ($entry['provider_transaction_id'] ?? ''));
        $metadataKey = $transactionId !== '' ? \sha1($transactionId) : 'latest';
        $metadata['clock_deposit_accounting'] = isset($metadata['clock_deposit_accounting']) && \is_array($metadata['clock_deposit_accounting'])
            ? $metadata['clock_deposit_accounting']
            : [];
        $metadata['clock_deposit_accounting'][$metadataKey] = $entry;
        $metadata['clock_folio_id'] = (string) ($entry['clock_folio_id'] ?? ($metadata['clock_folio_id'] ?? ''));
        $metadata['clock_credit_item_id'] = (string) ($entry['clock_credit_item_id'] ?? ($metadata['clock_credit_item_id'] ?? ''));

        $this->reservations->updateProviderMetadata($reservationId, [
            'provider_metadata' => $metadata,
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
    private function paymentIdempotencyKey(string $gateway, int $reservationId, string $transactionId): string
    {
        return 'clock-accounting-v2-payment-'
            . \sanitize_key($gateway)
            . '-'
            . $reservationId
            . '-'
            . \sha1($transactionId);
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
