<?php
namespace MustHotelBooking\Provider;
final class ProviderReservationView
{
    /**
     * @param array<string, mixed> $reservation
     * @return array<string, mixed>
     */
    public static function metadata(array $reservation): array
    {
        $provider = self::normalizeProviderKey((string) ($reservation['provider'] ?? ''));
        $isProviderBacked = self::isProviderBacked($reservation);
        $providerStatus = self::cleanText((string) ($reservation['provider_status'] ?? ''));
        $providerPaymentStatus = self::cleanText((string) ($reservation['provider_payment_status'] ?? ''));
        $providerSyncStatus = self::normalizeProviderKey((string) ($reservation['provider_sync_status'] ?? ''));
        return [
            'provider' => $provider,
            'provider_label' => self::providerLabel($provider),
            'is_provider_backed' => $isProviderBacked,
            'is_clock' => $provider === ProviderManager::CLOCK_MODE,
            'is_mirror' => $isProviderBacked,
            'provider_booking_id' => self::cleanText((string) ($reservation['provider_booking_id'] ?? '')),
            'provider_reservation_id' => self::cleanText((string) ($reservation['provider_reservation_id'] ?? '')),
            'provider_payload_ref' => self::cleanText((string) ($reservation['provider_payload_ref'] ?? '')),
            'provider_status' => $providerStatus,
            'provider_status_label' => self::formatStatusLabel($providerStatus),
            'provider_payment_status' => $providerPaymentStatus,
            'provider_payment_status_label' => self::formatStatusLabel($providerPaymentStatus),
            'provider_sync_status' => $providerSyncStatus,
            'provider_sync_status_label' => self::formatStatusLabel($providerSyncStatus),
            'provider_synced_at' => self::formatDateTime((string) ($reservation['provider_synced_at'] ?? '')),
            'provider_sync_error' => self::cleanText((string) ($reservation['provider_sync_error'] ?? '')),
            'read_only_message' => self::readOnlyMessage($provider),
        ];
    }
    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $paymentState
     * @return array<string, mixed>
     */
    public static function paymentContext(array $reservation, array $paymentState = []): array
    {
        $localStatus = self::normalizeProviderKey((string) ($paymentState['derived_status'] ?? (string) ($reservation['payment_status'] ?? '')));
        $localPaymentMethod = self::normalizeProviderKey((string) ($paymentState['method'] ?? (string) ($reservation['payment_method'] ?? '')));
        $providerStatus = self::cleanText((string) ($reservation['provider_payment_status'] ?? ''));
        $providerStatusKey = self::normalizeProviderKey($providerStatus);
        $metadata = self::decodeMetadata($reservation['provider_metadata'] ?? null);
        $provider = self::normalizeProviderKey((string) ($reservation['provider'] ?? ''));
        $folioPayment = isset($metadata['clock_folio_payment_sync']) && \is_array($metadata['clock_folio_payment_sync'])
            ? $metadata['clock_folio_payment_sync']
            : [];
        $folioPaymentStatus = self::normalizeProviderKey((string) ($folioPayment['sync_status'] ?? ''));
        $folioPaymentError = self::cleanText((string) ($folioPayment['sync_error'] ?? ''));
        $folioPaymentReference = self::cleanText((string) ($folioPayment['stripe_reference'] ?? ''));
        $folioPaymentAmount = self::cleanText((string) ($folioPayment['amount'] ?? ''));
        $folioPaymentCurrency = self::cleanText((string) ($folioPayment['currency'] ?? ''));
        $folioPaymentSubType = self::cleanText((string) ($folioPayment['payment_sub_type'] ?? ''));
        $folioPaymentSyncedAt = self::formatDateTime((string) ($folioPayment['synced_at'] ?? ''));
        $directProviderSyncStatus = self::normalizeProviderKey((string) ($reservation['provider_sync_status'] ?? ''));
        $directProviderSyncError = self::cleanText((string) ($reservation['provider_sync_error'] ?? ''));
        $folioPaymentAlreadySynced = !empty($folioPayment['success']);

        $folioPaymentNeedsManualAccounting =
            $provider === ProviderManager::CLOCK_MODE
            && !$folioPaymentAlreadySynced
            && (
                (
                    !empty($folioPayment)
                    && (
                        $folioPaymentStatus === 'manual_review'
                        || \strpos(\strtolower($folioPaymentError), 'pms_api_booking_folios_default') !== false
                        || \strpos(\strtolower($folioPaymentError), 'booking_folios') !== false
                        || \strpos(\strtolower($folioPaymentError), 'payment_sub_types') !== false
                        || \strpos(\strtolower($folioPaymentError), 'credit_item') !== false
                    )
                )
                || (
                    $directProviderSyncStatus === 'manual_review'
                    && (
                        \strpos(\strtolower($directProviderSyncError), 'pms_api_booking_folios_default') !== false
                        || \strpos(\strtolower($directProviderSyncError), 'booking_folios') !== false
                        || \strpos(\strtolower($directProviderSyncError), 'payment_sub_types') !== false
                        || \strpos(\strtolower($directProviderSyncError), 'credit_item') !== false
                    )
                )
                || \strpos(\strtolower($directProviderSyncError), 'pms_api_booking_folios_default') !== false
                || \strpos(\strtolower($directProviderSyncError), 'booking_folios') !== false
                || \strpos(\strtolower($directProviderSyncError), 'payment_sub_types') !== false
                || \strpos(\strtolower($directProviderSyncError), 'credit_item') !== false
                || (
                    $localStatus === 'paid'
                    && (
                        \strpos($localPaymentMethod, 'stripe') !== false
                        || \strpos($localPaymentMethod, 'pokpay') !== false
                        || $localPaymentMethod === ''
                    )
                )
            );
        $last = isset($metadata['last_payment_reconciliation']) && \is_array($metadata['last_payment_reconciliation'])
            ? $metadata['last_payment_reconciliation']
            : [];
        $required = !empty($metadata['payment_reconciliation_required']);
        $syncStatus = self::normalizeProviderKey((string) ($last['sync_status'] ?? (string) ($reservation['provider_sync_status'] ?? '')));
        $syncError = self::cleanText((string) ($last['sync_error'] ?? (string) ($reservation['provider_sync_error'] ?? '')));
        $syncedAt = self::formatDateTime((string) ($last['synced_at'] ?? (string) ($reservation['provider_synced_at'] ?? '')));
        $differs = $localStatus !== ''
            && $providerStatusKey !== ''
            && !self::paymentStatusesEquivalent($localStatus, $providerStatusKey);
        return [
            'local_status' => $localStatus,
            'local_status_label' => self::formatStatusLabel($localStatus),
            'provider_status' => $providerStatusKey,
            'provider_status_label' => self::formatStatusLabel($providerStatus !== '' ? $providerStatus : $providerStatusKey),
            'differs' => $differs,
            'reconciliation_required' => $required,
            'sync_status' => $syncStatus,
            'sync_status_label' => self::formatStatusLabel($syncStatus),
            'sync_error' => $syncError,
            'synced_at' => $syncedAt,
            'last_reconciliation_success' => !empty($last['success']),
            'last_target_provider_payment_status' => self::cleanText((string) ($last['target_provider_payment_status'] ?? '')),
            'needs_attention' => $differs || $required || $syncError !== '' || $folioPaymentNeedsManualAccounting || \in_array($syncStatus, ['pending_retry', 'failed', 'exhausted'], true),
            'folio_payment_accounting' => [
                'enabled' => $folioPaymentNeedsManualAccounting,
                'success' => !empty($folioPayment['success']),
                'status' => $folioPaymentStatus,
                'status_label' => self::formatStatusLabel($folioPaymentStatus),
                'error' => $folioPaymentError !== '' ? $folioPaymentError : $directProviderSyncError,
                'stripe_reference' => $folioPaymentReference,
                'amount' => $folioPaymentAmount,
                'currency' => $folioPaymentCurrency,
                'payment_sub_type' => $folioPaymentSubType !== '' ? $folioPaymentSubType : 'Stripe',
                'synced_at' => $folioPaymentSyncedAt,
                'message' => $folioPaymentNeedsManualAccounting
                    ? \__('Website payment succeeded, but Clock folio accounting needs review. Confirm booking_folios LIST VIEW, payment_sub_types VIEW, and credit_item payment CREATE rights, then retry from Payments.', 'must-hotel-booking')
                    : '',
            ],
        ];
    }
    /** @param array<string, mixed> $reservation */
    public static function isProviderBacked(array $reservation): bool
    {
        $provider = self::normalizeProviderKey((string) ($reservation['provider'] ?? ''));
        return $provider !== '' && $provider !== ProviderManager::LOCAL_MODE;
    }
    public static function providerLabel(string $provider): string
    {
        $provider = self::normalizeProviderKey($provider);
        if ($provider === '') {
            return \__('Local', 'must-hotel-booking');
        }
        if ($provider === ProviderManager::LOCAL_MODE) {
            return \__('Local', 'must-hotel-booking');
        }
        if ($provider === ProviderManager::CLOCK_MODE) {
            return \__('Clock', 'must-hotel-booking');
        }
        return \ucwords(\str_replace(['_', '-'], ' ', $provider));
    }
    public static function formatStatusLabel(string $status): string
    {
        $status = self::cleanText($status);
        if ($status === '') {
            return \__('Not reported', 'must-hotel-booking');
        }
        $normalized = \sanitize_key($status);
        if ($normalized !== '') {
            return \ucwords(\str_replace(['_', '-'], ' ', $normalized));
        }
        return $status;
    }
    public static function readOnlyMessage(string $provider): string
    {
        $providerLabel = self::providerLabel($provider);
        return \sprintf(
            /* translators: %s: provider label. */
            \__('This reservation is mirrored from %s. Local-only admin and staff mutations are read-only unless a provider-aware action is explicitly available.', 'must-hotel-booking'),
            $providerLabel
        );
    }
    private static function normalizeProviderKey(string $provider): string
    {
        return \sanitize_key($provider);
    }
    private static function cleanText(string $value): string
    {
        return \sanitize_text_field($value);
    }
    /** @param mixed $metadata */
    private static function decodeMetadata($metadata): array
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
    private static function paymentStatusesEquivalent(string $localStatus, string $providerStatus): bool
    {
        $localStatus = self::normalizePaymentStatus($localStatus);
        $providerStatus = self::normalizePaymentStatus($providerStatus);
        return $localStatus !== '' && $localStatus === $providerStatus;
    }
    private static function normalizePaymentStatus(string $status): string
    {
        $status = self::normalizeProviderKey($status);
        $aliases = [
            'not_paid' => 'unpaid',
            'notpaid' => 'unpaid',
            'open' => 'unpaid',
            'captured' => 'paid',
            'settled' => 'paid',
            'complete' => 'paid',
            'completed' => 'paid',
            'authorized' => 'pending',
            'pending_payment' => 'pending',
            'payment_failed' => 'failed',
            'partial' => 'partially_paid',
            'part_paid' => 'partially_paid',
            'partpaid' => 'partially_paid',
            'partiallypaid' => 'partially_paid',
            'canceled' => 'cancelled',
            'voided' => 'cancelled',
        ];
        return isset($aliases[$status]) ? $aliases[$status] : $status;
    }
    private static function formatDateTime(string $value): string
    {
        $value = \trim($value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return '';
        }
        $timestamp = \strtotime($value);
        if ($timestamp === false) {
            return $value;
        }
        return \mysql2date(\get_option('date_format') . ' ' . \get_option('time_format'), $value);
    }
}
