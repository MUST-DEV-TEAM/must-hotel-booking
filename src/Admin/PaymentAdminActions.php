<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Engine\PaymentStatusService;

final class PaymentAdminActions
{
    private \MustHotelBooking\Database\ReservationRepository $reservationRepository;
    private \MustHotelBooking\Database\PaymentRepository $paymentRepository;

    public function __construct()
    {
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
    }

    /**
     * @param array<string, scalar|int|bool> $args
     */
    private function redirectToPaymentsPage(array $args): void
    {
        $url = get_admin_payments_page_url($args);

        if (!\wp_safe_redirect($url)) {
            \wp_redirect($url);
        }

        exit;
    }

    public function handleGetAction(PaymentAdminQuery $query): void
    {
        $action = $query->getAction();

        if ($action === '') {
            return;
        }

        if (!\in_array($action, ['mark_paid', 'mark_unpaid', 'mark_pending', 'mark_pay_at_hotel', 'mark_failed', 'resend_guest_email', 'resend_admin_email'], true)) {
            return;
        }

        $reservationId = $query->getReservationId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_payment_action_' . $action . '_' . $reservationId)) {
            $this->redirectToPaymentsPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $notice = $this->applyAdminPaymentAction($reservationId, $action)
            ? $this->mapActionNotice($action)
            : 'action_failed';

        $this->redirectToPaymentsPage($query->buildUrlArgs([
            'notice' => $notice,
            'action' => 'view',
            'reservation_id' => $reservationId,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function handleSaveRequest(PaymentAdminQuery $query): array
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return $this->blankState();
        }

        $action = isset($_POST['must_payments_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_payments_action'])) : '';

        if ($action !== 'save_payment_settings') {
            return $this->blankState();
        }

        return $this->savePaymentSettings($query);
    }

    public function applyAdminPaymentAction(int $reservationId, string $action): bool
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return false;
        }

        $paymentRows = $this->paymentRepository->getPaymentsForReservation($reservationId);
        $state = PaymentStatusService::buildReservationPaymentState($reservation, $paymentRows);

        if (!PaymentStatusService::canTransition($state, $action) && !\in_array($action, ['resend_guest_email', 'resend_admin_email'], true)) {
            return false;
        }

        switch ($action) {
            case 'mark_paid':
                return $this->markPaid($reservationId, $reservation, $state);
            case 'mark_unpaid':
                return $this->markUnpaid($reservationId, $reservation, $state);
            case 'mark_pending':
                return $this->markPending($reservationId, $reservation, $state);
            case 'mark_pay_at_hotel':
                return $this->markPayAtHotel($reservationId, $reservation, $state);
            case 'mark_failed':
                return $this->markFailed($reservationId, $state);
            case 'resend_guest_email':
                return EmailEngine::resendGuestReservationEmail($reservationId);
            case 'resend_admin_email':
                return EmailEngine::resendAdminReservationEmail($reservationId);
            default:
                return false;
        }
    }

    private function markPaid(int $reservationId, array $reservation, array $state): bool
    {
        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
        $method = $this->resolveLedgerMethod($state, 'pay_at_hotel');
        $transactionId = isset($state['transaction_id']) ? (string) $state['transaction_id'] : '';
        $targetStatus = \in_array($currentStatus, ['confirmed', 'completed'], true) ? $currentStatus : 'confirmed';

        BookingStatusEngine::updateReservationStatuses([$reservationId], $targetStatus, 'paid');
        BookingStatusEngine::createPaymentRows([$reservationId], $method, 'paid', $transactionId);

        return true;
    }

    private function markUnpaid(int $reservationId, array $reservation, array $state): bool
    {
        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
        $method = $this->resolveLedgerMethod($state, 'pay_at_hotel');
        $targetStatus = \in_array($currentStatus, ['pending_payment', 'payment_failed', 'expired'], true)
            ? 'confirmed'
            : $currentStatus;

        $updated = $this->reservationRepository->updateReservationStatus($reservationId, $targetStatus, 'unpaid');

        if (!$updated) {
            return false;
        }

        BookingStatusEngine::createPaymentRows([$reservationId], $method, 'unpaid');

        return true;
    }

    private function markPending(int $reservationId, array $reservation, array $state): bool
    {
        $method = $this->resolveLedgerMethod($state, 'stripe');
        $targetStatus = $method === 'stripe' ? 'pending_payment' : 'pending';
        $updated = $this->reservationRepository->updateReservationStatus($reservationId, $targetStatus, 'pending');

        if (!$updated) {
            return false;
        }

        BookingStatusEngine::createPaymentRows([$reservationId], $method, 'pending');

        return true;
    }

    private function markPayAtHotel(int $reservationId, array $reservation, array $state): bool
    {
        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
        $targetStatus = $currentStatus === 'completed' ? 'completed' : 'confirmed';

        BookingStatusEngine::updateReservationStatuses([$reservationId], $targetStatus, 'unpaid');
        BookingStatusEngine::createPaymentRows([$reservationId], 'pay_at_hotel', 'unpaid');

        return true;
    }

    private function markFailed(int $reservationId, array $state): bool
    {
        $method = $this->resolveLedgerMethod($state, 'stripe');
        $updated = $this->reservationRepository->updateReservationStatus($reservationId, 'payment_failed', 'failed');

        if (!$updated) {
            return false;
        }

        BookingStatusEngine::createPaymentRows([$reservationId], $method, 'failed');

        return true;
    }

    private function resolveLedgerMethod(array $state, string $fallback): string
    {
        $method = isset($state['method']) ? \sanitize_key((string) $state['method']) : '';

        if ($method !== '') {
            return $method;
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function savePaymentSettings(PaymentAdminQuery $query): array
    {
        $nonce = isset($_POST['must_payment_settings_nonce']) ? (string) \wp_unslash($_POST['must_payment_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_payment_settings_save')) {
            return [
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'settings_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            ];
        }

        $catalog = PaymentMethodRegistry::getCatalog();
        $rawStates = isset($_POST['payment_methods']) && \is_array($_POST['payment_methods']) ? $_POST['payment_methods'] : [];
        $states = [];

        foreach ($catalog as $method => $meta) {
            $rawValue = isset($rawStates[$method]) ? (string) \wp_unslash($rawStates[$method]) : '';
            $states[$method] = $rawValue === '1';
        }

        $saved = PaymentMethodRegistry::saveStates($states);

        if (\class_exists(MustBookingConfig::class)) {
            foreach (\array_keys(PaymentEngine::getStripeEnvironmentCatalog()) as $environmentKey) {
                $settingKeys = PaymentEngine::getStripeEnvironmentSettingKeys((string) $environmentKey);

                foreach ($settingKeys as $fieldKey => $settingKey) {
                    $postedFieldKey = 'stripe_' . $environmentKey . '_' . $fieldKey;
                    $value = isset($_POST[$postedFieldKey])
                        ? \sanitize_text_field((string) \wp_unslash($_POST[$postedFieldKey]))
                        : '';
                    MustBookingConfig::set_setting($settingKey, $value);
                }
            }

            $productionKeys = PaymentEngine::getStripeEnvironmentSettingKeys('production');
            MustBookingConfig::set_setting('stripe_publishable_key', (string) MustBookingConfig::get_setting($productionKeys['publishable_key'], ''));
            MustBookingConfig::set_setting('stripe_secret_key', (string) MustBookingConfig::get_setting($productionKeys['secret_key'], ''));
            MustBookingConfig::set_setting('stripe_webhook_secret', (string) MustBookingConfig::get_setting($productionKeys['webhook_secret'], ''));
        }

        $this->redirectToPaymentsPage($query->buildUrlArgs([
            'notice' => $saved ? 'payment_settings_saved' : 'payment_settings_save_failed',
        ]));
    }

    private function mapActionNotice(string $action): string
    {
        $messages = [
            'mark_paid' => 'payment_marked_paid',
            'mark_unpaid' => 'payment_marked_unpaid',
            'mark_pending' => 'payment_marked_pending',
            'mark_pay_at_hotel' => 'payment_marked_pay_at_hotel',
            'mark_failed' => 'payment_marked_failed',
            'resend_guest_email' => 'reservation_guest_email_resent',
            'resend_admin_email' => 'reservation_admin_email_resent',
        ];

        return isset($messages[$action]) ? $messages[$action] : 'action_failed';
    }

    /**
     * @return array<string, mixed>
     */
    private function blankState(): array
    {
        return [
            'errors' => [],
            'settings_errors' => [],
        ];
    }
}
