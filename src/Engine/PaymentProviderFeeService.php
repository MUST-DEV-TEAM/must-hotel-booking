<?php
namespace MustHotelBooking\Engine;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Database\PaymentRepository;
final class PaymentProviderFeeService
{
    /** @var PaymentRepository */
    private $payments;
    public function __construct(?PaymentRepository $payments = null)
    {
        $this->payments = $payments ?: get_payment_repository();
    }
    /**
     * @param array<int, int> $reservationIds
     * @param array<string, mixed> $session
     */
    public function captureStripeFeeSnapshotForReservations(array $reservationIds, array $session): void
    {
        $paymentIntentId = \sanitize_text_field((string) ($session['payment_intent'] ?? ''));
        $sessionId = \sanitize_text_field((string) ($session['id'] ?? ''));
        $transactionId = $paymentIntentId !== '' ? $paymentIntentId : $sessionId;
        $snapshot = $this->fetchStripeFeeSnapshot($paymentIntentId);
        foreach ($reservationIds as $reservationId) {
            $paymentId = $this->payments->getLatestPaymentIdForReservationMethod((int) $reservationId, 'stripe');
            if ($paymentId <= 0) {
                continue;
            }
            $payment = $this->payments->getPayment($paymentId);
            if (!\is_array($payment)) {
                continue;
            }
            $this->payments->updatePayment($paymentId, $this->paymentFeeData($payment, $snapshot, $transactionId));
        }
    }
    /**
     * @param array<int, int> $reservationIds
     * @param array<string, mixed> $order
     */
    public function capturePokPayFeeSnapshotForReservations(array $reservationIds, array $order, string $orderId): void
    {
        $snapshot = $this->extractPokPayFeeSnapshot($order);
        foreach ($reservationIds as $reservationId) {
            $paymentId = $this->payments->getLatestPaymentIdForReservationMethod((int) $reservationId, 'pokpay');
            if ($paymentId <= 0) {
                continue;
            }
            $payment = $this->payments->getPayment($paymentId);
            if (!\is_array($payment)) {
                continue;
            }
            if ((string) ($snapshot['status'] ?? '') !== 'known') {
                $snapshot = $this->buildPokPayConfiguredEstimate((float) ($payment['amount'] ?? 0.0), (string) ($payment['currency'] ?? ''));
            }
            $this->payments->updatePayment($paymentId, $this->paymentFeeData($payment, $snapshot, $orderId));
        }
    }
    /**
     * @param array<int, array<string, mixed>> $paymentRows
     * @return array<string, mixed>
     */
    public function calculateDefaultRefundBreakdown(array $paymentRows, float $amountPaid, string $calculatedBy = 'system', float $cancellationFee = 0.0, string $policyReason = ''): array
    {
        $fee = $this->providerFeeFromPaidRows($paymentRows);
        $feeStatus = (string) ($fee['status'] ?? 'unknown');
        $providerFee = \round(\max(0.0, (float) ($fee['amount'] ?? 0.0)), 2);
        $cancellationFee = \round(\max(0.0, $cancellationFee), 2);
        $refundAmount = \round(\max(0.0, $amountPaid - $providerFee - $cancellationFee), 2);
        return [
            'success' => $feeStatus === 'known' || $providerFee > 0.0,
            'provider_fee_status' => $feeStatus,
            'original_paid_amount' => \round(\max(0.0, $amountPaid), 2),
            'provider_fee_retained' => $providerFee,
            'cancellation_fee_amount' => $cancellationFee,
            'final_refund_amount' => $refundAmount,
            'refund_policy_reason' => $policyReason !== '' ? $policyReason : \__('Paid amount minus stored provider fee snapshot.', 'must-hotel-booking'),
            'calculated_by' => \sanitize_key($calculatedBy) ?: 'system',
        ];
    }
    /**
     * @param array<string, mixed> $breakdown
     * @return array<string, mixed>
     */
    public function refundBreakdownData(array $breakdown): array
    {
        return [
            'original_paid_amount' => \round((float) ($breakdown['original_paid_amount'] ?? 0.0), 2),
            'provider_fee_retained' => \round((float) ($breakdown['provider_fee_retained'] ?? 0.0), 2),
            'cancellation_fee_amount' => \round((float) ($breakdown['cancellation_fee_amount'] ?? 0.0), 2),
            'final_refund_amount' => \round((float) ($breakdown['final_refund_amount'] ?? 0.0), 2),
            'refund_policy_reason' => \sanitize_text_field((string) ($breakdown['refund_policy_reason'] ?? '')),
            'calculated_by' => \sanitize_key((string) ($breakdown['calculated_by'] ?? '')),
        ];
    }
    /** @return array<string, mixed> */
    private function fetchStripeFeeSnapshot(string $paymentIntentId): array
    {
        $paymentIntentId = \sanitize_text_field($paymentIntentId);
        if ($paymentIntentId === '') {
            return $this->unknownSnapshot('stripe_balance_transaction_missing_payment_intent');
        }
        $intentResponse = PaymentEngine::performStripeApiRequest('GET', 'payment_intents/' . \rawurlencode($paymentIntentId) . '?expand[]=latest_charge.balance_transaction');
        if (empty($intentResponse['success'])) {
            return $this->unknownSnapshot((string) ($intentResponse['message'] ?? 'stripe_payment_intent_fetch_failed'));
        }
        $intent = isset($intentResponse['body']) && \is_array($intentResponse['body']) ? $intentResponse['body'] : [];
        $charge = isset($intent['latest_charge']) && \is_array($intent['latest_charge']) ? $intent['latest_charge'] : [];
        if (empty($charge) && isset($intent['latest_charge']) && \is_string($intent['latest_charge'])) {
            $chargeResponse = PaymentEngine::performStripeApiRequest('GET', 'charges/' . \rawurlencode((string) $intent['latest_charge']) . '?expand[]=balance_transaction');
            $charge = !empty($chargeResponse['success']) && isset($chargeResponse['body']) && \is_array($chargeResponse['body'])
                ? $chargeResponse['body']
                : [];
        }
        $balanceTransaction = isset($charge['balance_transaction']) && \is_array($charge['balance_transaction']) ? $charge['balance_transaction'] : [];
        if (empty($balanceTransaction) && isset($charge['balance_transaction']) && \is_string($charge['balance_transaction'])) {
            $btResponse = PaymentEngine::performStripeApiRequest('GET', 'balance_transactions/' . \rawurlencode((string) $charge['balance_transaction']));
            $balanceTransaction = !empty($btResponse['success']) && isset($btResponse['body']) && \is_array($btResponse['body'])
                ? $btResponse['body']
                : [];
        }
        if (empty($balanceTransaction) || !isset($balanceTransaction['fee'])) {
            return $this->unknownSnapshot('stripe_balance_transaction_fee_unavailable');
        }
        $currency = \strtoupper(\sanitize_text_field((string) ($balanceTransaction['currency'] ?? MustBookingConfig::get_currency())));
        $fee = PaymentEngine::convertMinorUnitsToAmount((int) $balanceTransaction['fee'], $currency);
        $net = isset($balanceTransaction['net']) ? PaymentEngine::convertMinorUnitsToAmount((int) $balanceTransaction['net'], $currency) : 0.0;
        return [
            'status' => 'known',
            'amount' => \round($fee, 2),
            'currency' => $currency,
            'net_amount' => \round($net, 2),
            'source' => 'stripe_balance_transaction',
            'balance_transaction_id' => \sanitize_text_field((string) ($balanceTransaction['id'] ?? '')),
            'absorbed_by_customer' => false,
            'metadata' => [
                'stripe_charge_id' => (string) ($charge['id'] ?? ''),
                'stripe_payment_intent_id' => $paymentIntentId,
            ],
        ];
    }
    /** @param array<string, mixed> $order @return array<string, mixed> */
    private function extractPokPayFeeSnapshot(array $order): array
    {
        $fee = $this->firstNumericRecursive($order, [
            'processingFee',
            'providerFee',
            'feeAmount',
            'fee',
            'commissionAmount',
            'commission',
            'totalCommissionAmount',
            'bankCommission',
            'bankCommissionAmount',
        ]);
        $currency = \strtoupper(\sanitize_text_field(
            $this->firstStringRecursive($order, ['currencyCode', 'currency']) ?: MustBookingConfig::get_currency()
        ));
        if ($fee === null) {
            return $this->unknownSnapshot('pokpay_fee_not_returned');
        }
        $net = $this->firstNumericRecursive($order, ['netAmount', 'providerNetAmount', 'settlementAmount']);
        $feeAmount = $this->normalizePokPayMoney((float) $fee, $currency, $order);
        return [
            'status' => 'known',
            'amount' => \round($feeAmount, 2),
            'currency' => $currency,
            'net_amount' => $net !== null ? \round($this->normalizePokPayMoney((float) $net, $currency, $order), 2) : 0.0,
            'source' => 'pokpay_api',
            'balance_transaction_id' => $this->firstStringRecursive($order, ['transactionId', 'paymentId', 'id']),
            'absorbed_by_customer' => false,
            'metadata' => ['pokpay_fee_fields' => \array_keys($order)],
        ];
    }
    /** @return array<string, mixed> */
    private function buildPokPayConfiguredEstimate(float $amount, string $currency): array
    {
        $percent = MustBookingConfig::get_pokpay_fee_percent();
        $fixed = MustBookingConfig::get_pokpay_fee_fixed();
        $feeCurrency = MustBookingConfig::get_pokpay_fee_currency();
        if ($percent <= 0.0 && $fixed <= 0.0) {
            return $this->unknownSnapshot('pokpay_fee_estimate_missing');
        }
        $fee = \round(($amount * ($percent / 100)) + $fixed, 2);
        return [
            'status' => 'known',
            'amount' => \max(0.0, $fee),
            'currency' => $feeCurrency !== '' ? $feeCurrency : \strtoupper(\sanitize_text_field($currency)),
            'net_amount' => \round(\max(0.0, $amount - $fee), 2),
            'source' => 'pokpay_configured_estimate',
            'balance_transaction_id' => '',
            'absorbed_by_customer' => MustBookingConfig::get_pokpay_fee_customer_absorbs(),
            'metadata' => [
                'fee_percent' => $percent,
                'fee_fixed' => $fixed,
                'fee_currency' => $feeCurrency,
                'customer_absorbs_fee' => MustBookingConfig::get_pokpay_fee_customer_absorbs(),
            ],
        ];
    }
    /** @param array<string, mixed> $payment @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function paymentFeeData(array $payment, array $snapshot, string $transactionId): array
    {
        $amount = \round((float) ($payment['amount'] ?? 0.0), 2);
        $fee = \round(\max(0.0, (float) ($snapshot['amount'] ?? 0.0)), 2);
        $net = isset($snapshot['net_amount']) ? \round((float) $snapshot['net_amount'], 2) : \round(\max(0.0, $amount - $fee), 2);
        return [
            'provider_fee_amount' => $fee,
            'provider_fee_currency' => \strtoupper(\sanitize_text_field((string) ($snapshot['currency'] ?? ($payment['currency'] ?? MustBookingConfig::get_currency())))),
            'provider_net_amount' => $net,
            'provider_fee_status' => \sanitize_key((string) ($snapshot['status'] ?? 'unknown')),
            'provider_fee_source' => \sanitize_key((string) ($snapshot['source'] ?? '')),
            'provider_balance_transaction_id' => \sanitize_text_field((string) ($snapshot['balance_transaction_id'] ?? '')),
            'provider_fee_absorbed_by_customer' => !empty($snapshot['absorbed_by_customer']) ? 1 : 0,
            'provider_fee_metadata' => \wp_json_encode([
                'transaction_id' => $transactionId,
                'snapshot' => $snapshot,
            ]),
        ];
    }
    /** @param array<int, array<string, mixed>> $paymentRows @return array<string, mixed> */
    private function providerFeeFromPaidRows(array $paymentRows): array
    {
        foreach ($paymentRows as $row) {
            if (!\is_array($row) || (string) ($row['status'] ?? '') !== 'paid') {
                continue;
            }
            $status = \sanitize_key((string) ($row['provider_fee_status'] ?? 'unknown'));
            if ($status === 'known') {
                return [
                    'status' => 'known',
                    'amount' => (float) ($row['provider_fee_amount'] ?? 0.0),
                ];
            }
        }
        return ['status' => 'unknown', 'amount' => 0.0];
    }
    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstNumeric(array $source, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_numeric($source[$key])) {
                return (float) $source[$key];
            }
        }
        return null;
    }
    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstNumericRecursive(array $source, array $keys): ?float
    {
        $value = $this->firstNumeric($source, $keys);
        if ($value !== null) {
            return $value;
        }
        foreach (['data', 'sdkOrder', 'payment', 'transaction', 'order', 'commissions'] as $nestedKey) {
            if (isset($source[$nestedKey]) && \is_array($source[$nestedKey])) {
                $nested = $this->firstNumericRecursive($source[$nestedKey], $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }
    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstStringRecursive(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                $value = \trim((string) $source[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        foreach (['data', 'sdkOrder', 'payment', 'transaction', 'order', 'commissions'] as $nestedKey) {
            if (isset($source[$nestedKey]) && \is_array($source[$nestedKey])) {
                $nested = $this->firstStringRecursive($source[$nestedKey], $keys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }
        return '';
    }
    /** @param array<string, mixed> $order */
    private function normalizePokPayMoney(float $value, string $currency, array $order): float
    {
        return \round(\max(0.0, $value), 2);
    }    /** @return array<string, mixed> */
    private function unknownSnapshot(string $reason): array
    {
        return [
            'status' => 'unknown',
            'amount' => 0.0,
            'currency' => MustBookingConfig::get_currency(),
            'net_amount' => 0.0,
            'source' => $reason,
            'balance_transaction_id' => '',
            'absorbed_by_customer' => false,
            'metadata' => ['reason' => $reason],
        ];
    }
}
