<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\EmailEngine;

final class ReservationAdminActions
{
    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservationRepository;

    /** @var \MustHotelBooking\Database\GuestRepository */
    private $guestRepository;

    /** @var \MustHotelBooking\Database\PaymentRepository */
    private $paymentRepository;

    /** @var \MustHotelBooking\Database\ActivityRepository */
    private $activityRepository;

    public function __construct()
    {
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
    }

    public function handleRequest(): void
    {
        $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

        if (!\in_array($page, ['must-hotel-booking-reservations', 'must-hotel-booking-reservation'], true)) {
            return;
        }

        ensure_admin_capability();

        if ($this->handleSingleGetAction($page)) {
            return;
        }

        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return;
        }

        $action = isset($_POST['must_reservation_admin_action'])
            ? \sanitize_key((string) \wp_unslash($_POST['must_reservation_admin_action']))
            : '';

        if ($action === '') {
            return;
        }

        $nonce = isset($_POST['must_reservation_admin_nonce']) ? (string) \wp_unslash($_POST['must_reservation_admin_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_reservation_admin_action')) {
            $this->redirectToReturnUrl($page, ['notice' => 'invalid_nonce']);
        }

        if ($action === 'bulk_apply') {
            $this->handleBulkAction($page);

            return;
        }

        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;

        if ($reservationId <= 0) {
            $this->redirectToReturnUrl($page, ['notice' => 'reservation_not_found']);
        }

        switch ($action) {
            case 'save_guest':
                $notice = $this->saveGuestDetails($reservationId);
                break;
            case 'save_admin_details':
                $notice = $this->saveAdminDetails($reservationId);
                break;
            case 'confirm':
                $notice = $this->confirmReservation($reservationId) ? 'reservation_confirmed' : 'action_failed';
                break;
            case 'mark_pending':
                $notice = $this->markReservationPending($reservationId) ? 'reservation_marked_pending' : 'action_failed';
                break;
            case 'mark_paid':
                $notice = $this->markReservationPaid($reservationId) ? 'reservation_marked_paid' : 'action_failed';
                break;
            case 'mark_unpaid':
                $notice = $this->markReservationUnpaid($reservationId) ? 'reservation_marked_unpaid' : 'action_failed';
                break;
            case 'cancel':
                $notice = $this->cancelReservation($reservationId) ? 'reservation_cancelled' : 'action_failed';
                break;
            case 'resend_guest_email':
                $notice = EmailEngine::resendGuestReservationEmail($reservationId) ? 'reservation_guest_email_resent' : 'action_failed';
                break;
            case 'resend_admin_email':
                $notice = EmailEngine::resendAdminReservationEmail($reservationId) ? 'reservation_admin_email_resent' : 'action_failed';
                break;
            default:
                $notice = 'action_failed';
                break;
        }

        $this->redirectToReturnUrl($page, ['notice' => $notice]);
    }

    private function handleSingleGetAction(string $page): bool
    {
        $action = isset($_GET['reservation_action'])
            ? \sanitize_key((string) \wp_unslash($_GET['reservation_action']))
            : '';

        if ($action === '') {
            return false;
        }

        $reservationId = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_reservation_action_' . $action . '_' . $reservationId)) {
            $this->redirectToReturnUrl($page, ['notice' => 'invalid_nonce']);
        }

        switch ($action) {
            case 'confirm':
                $notice = $this->confirmReservation($reservationId) ? 'reservation_confirmed' : 'action_failed';
                break;
            case 'mark_paid':
                $notice = $this->markReservationPaid($reservationId) ? 'reservation_marked_paid' : 'action_failed';
                break;
            case 'resend_guest_email':
                $notice = EmailEngine::resendGuestReservationEmail($reservationId) ? 'reservation_guest_email_resent' : 'action_failed';
                break;
            case 'cancel':
                $notice = $this->cancelReservation($reservationId) ? 'reservation_cancelled' : 'action_failed';
                break;
            default:
                return false;
        }

        $this->redirectToReturnUrl($page, ['notice' => $notice]);

        return true;
    }

    private function handleBulkAction(string $page): void
    {
        $bulkAction = isset($_POST['bulk_action'])
            ? \sanitize_key((string) \wp_unslash($_POST['bulk_action']))
            : '';
        $reservationIds = isset($_POST['reservation_ids']) && \is_array($_POST['reservation_ids'])
            ? \array_values(\array_filter(\array_map('absint', \wp_unslash($_POST['reservation_ids']))))
            : [];

        if ($bulkAction === '' || empty($reservationIds)) {
            $this->redirectToReturnUrl($page, ['notice' => 'action_failed']);
        }

        $updated = 0;
        $failed = 0;

        foreach (\array_unique($reservationIds) as $reservationId) {
            $success = false;

            if ($bulkAction === 'confirm') {
                $success = $this->confirmReservation((int) $reservationId);
            } elseif ($bulkAction === 'mark_paid') {
                $success = $this->markReservationPaid((int) $reservationId);
            } elseif ($bulkAction === 'cancel') {
                $success = $this->cancelReservation((int) $reservationId);
            }

            if ($success) {
                $updated++;
            } else {
                $failed++;
            }
        }

        $this->redirectToReturnUrl(
            $page,
            [
                'notice' => 'bulk_action_completed',
                'bulk_action' => $bulkAction,
                'updated_count' => $updated,
                'failed_count' => $failed,
            ]
        );
    }

    private function confirmReservation(int $reservationId): bool
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return false;
        }

        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));

        if (\in_array($currentStatus, ['cancelled', 'blocked'], true) || ReservationStatus::isConfirmed($currentStatus)) {
            return false;
        }

        $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));

        if ($paymentStatus === '' || $paymentStatus === 'blocked') {
            $paymentStatus = 'unpaid';
        }

        BookingStatusEngine::updateReservationStatuses([$reservationId], 'confirmed', $paymentStatus);

        return true;
    }

    private function markReservationPending(int $reservationId): bool
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return false;
        }

        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));

        if (\in_array($currentStatus, ['cancelled', 'blocked'], true)) {
            return false;
        }

        $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));
        $targetStatus = $paymentStatus === 'pending' ? 'pending_payment' : 'pending';
        $updated = $this->reservationRepository->updateReservationStatus(
            $reservationId,
            $targetStatus,
            $paymentStatus !== '' ? $paymentStatus : 'unpaid'
        );

        if ($updated) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_pending',
                'warning',
                \__('Reservation moved back to pending.', 'must-hotel-booking')
            );
        }

        return $updated;
    }

    private function markReservationPaid(int $reservationId): bool
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return false;
        }

        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
        $currentPaymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));

        if (\in_array($currentStatus, ['cancelled', 'blocked'], true) || $currentPaymentStatus === 'paid') {
            return false;
        }

        $targetStatus = \in_array($currentStatus, ['confirmed', 'completed'], true) ? $currentStatus : 'confirmed';
        BookingStatusEngine::updateReservationStatuses([$reservationId], $targetStatus, 'paid');

        $latestPayment = $this->paymentRepository->getLatestPaymentForReservation($reservationId);
        $method = \sanitize_key((string) ($latestPayment['method'] ?? ''));

        if ($method === '') {
            $method = 'pay_at_hotel';
        }

        BookingStatusEngine::createPaymentRows([$reservationId], $method, 'paid');

        return true;
    }

    private function markReservationUnpaid(int $reservationId): bool
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return false;
        }

        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));

        if (\in_array($currentStatus, ['cancelled', 'blocked'], true)) {
            return false;
        }

        $updated = $this->reservationRepository->updateReservationStatus($reservationId, $currentStatus, 'unpaid');

        if ($updated) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'payment_marked_unpaid',
                'warning',
                \__('Payment status set to unpaid.', 'must-hotel-booking')
            );
        }

        return $updated;
    }

    private function cancelReservation(int $reservationId): bool
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return false;
        }

        $currentStatus = \sanitize_key((string) ($reservation['status'] ?? ''));

        if (\in_array($currentStatus, ['cancelled', 'blocked', 'completed'], true)) {
            return false;
        }

        BookingStatusEngine::updateReservationStatuses(
            [$reservationId],
            'cancelled',
            \sanitize_key((string) ($reservation['payment_status'] ?? ''))
        );

        return true;
    }

    private function saveGuestDetails(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $firstName = isset($_POST['guest_first_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_first_name'])) : '';
        $lastName = isset($_POST['guest_last_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_last_name'])) : '';
        $email = isset($_POST['guest_email']) ? \sanitize_email((string) \wp_unslash($_POST['guest_email'])) : '';
        $phone = isset($_POST['guest_phone']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_phone'])) : '';
        $country = isset($_POST['guest_country']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_country'])) : '';

        if ($email !== '' && !\is_email($email)) {
            return 'invalid_guest_email';
        }

        $guestId = $this->guestRepository->saveAdminGuestProfile(
            isset($reservation['guest_id']) ? (int) $reservation['guest_id'] : 0,
            $firstName,
            $lastName,
            $email,
            $phone,
            $country
        );

        if ($guestId <= 0) {
            return 'action_failed';
        }

        if ($guestId !== (int) ($reservation['guest_id'] ?? 0)) {
            $this->reservationRepository->updateReservation($reservationId, ['guest_id' => $guestId]);
        }

        $this->logReservationActivity(
            $reservationId,
            $reservation,
            'guest_updated',
            'info',
            \__('Guest details updated.', 'must-hotel-booking')
        );

        return 'reservation_guest_updated';
    }

    private function saveAdminDetails(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $bookingSource = isset($_POST['booking_source']) ? \sanitize_key((string) \wp_unslash($_POST['booking_source'])) : 'website';
        $notes = isset($_POST['notes']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['notes'])) : '';
        $sourceOptions = \function_exists(__NAMESPACE__ . '\get_admin_quick_booking_source_options')
            ? get_admin_quick_booking_source_options()
            : [];

        if (!isset($sourceOptions[$bookingSource])) {
            $bookingSource = isset($reservation['booking_source']) ? (string) $reservation['booking_source'] : 'website';
        }

        $updated = $this->reservationRepository->updateReservation(
            $reservationId,
            [
                'booking_source' => $bookingSource,
                'notes' => $notes,
            ]
        );

        if ($updated) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_updated',
                'info',
                \__('Reservation notes and source updated.', 'must-hotel-booking')
            );
        }

        return $updated ? 'reservation_updated' : 'action_failed';
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function logReservationActivity(
        int $reservationId,
        array $reservation,
        string $eventType,
        string $severity,
        string $message
    ): void {
        $bookingId = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';
        $reference = $bookingId !== '' ? $bookingId : ('RES-' . $reservationId);

        $this->activityRepository->createActivity(
            [
                'event_type' => $eventType,
                'severity' => $severity,
                'entity_type' => 'reservation',
                'entity_id' => $reservationId,
                'reference' => $reference,
                'message' => $message,
                'context_json' => \wp_json_encode(
                    [
                        'reservation_id' => $reservationId,
                        'booking_id' => $bookingId,
                    ]
                ),
            ]
        );
    }

    private function redirectToReturnUrl(string $page, array $args = []): void
    {
        $returnUrl = isset($_REQUEST['return_url']) ? (string) \wp_unslash($_REQUEST['return_url']) : '';
        $reservationId = isset($_REQUEST['reservation_id']) ? \absint(\wp_unslash($_REQUEST['reservation_id'])) : 0;
        $defaultUrl = $page === 'must-hotel-booking-reservation' && $reservationId > 0
            ? get_admin_reservation_detail_page_url($reservationId)
            : get_admin_reservations_page_url();
        $safeReturnUrl = \wp_validate_redirect($returnUrl, $defaultUrl);

        \wp_safe_redirect(\add_query_arg($args, $safeReturnUrl));
        exit;
    }
}
