<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Engine\BookingLifecycleSyncService;
use MustHotelBooking\Engine\BookingStatusEngine;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\ReservationAmendmentService;
use MustHotelBooking\Provider\Clock\ClockPaymentReconciliationService;
use MustHotelBooking\Provider\ProviderReservationActionPolicy;
use MustHotelBooking\Provider\ProviderReservationView;

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

    private PaymentAdminActions $paymentAdminActions;

    public function __construct()
    {
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
        $this->paymentAdminActions = new PaymentAdminActions();
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

        if ($this->shouldBlockProviderBackedAction($reservationId, $action, ProviderReservationActionPolicy::SURFACE_ADMIN_POST)) {
            $this->redirectToReturnUrl($page, ['notice' => 'provider_backed_read_only']);
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
                $notice = $this->cancelReservationNotice($reservationId);
                break;
            case 'checkin':
                $notice = $this->checkInReservationNotice($reservationId);
                break;
            case 'checkout':
                $notice = $this->checkOutReservationNotice($reservationId);
                break;
            case 'assign_room':
                $notice = $this->assignRoomNotice($reservationId);
                break;
            case 'update_stay':
                $notice = $this->updateStayNotice($reservationId);
                break;
            case 'amend_reservation':
                $notice = $this->amendReservationNotice($reservationId);
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

        if ($this->shouldBlockProviderBackedAction($reservationId, $action, ProviderReservationActionPolicy::SURFACE_ADMIN_GET)) {
            $this->redirectToReturnUrl($page, ['notice' => 'provider_backed_read_only']);
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
                $notice = $this->cancelReservationNotice($reservationId);
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

            if ($this->isProviderBackedReservation((int) $reservationId)) {
                $success = false;
            } elseif ($bulkAction === 'confirm') {
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

    private function isProviderBackedReservation(int $reservationId): bool
    {
        $reservation = $this->reservationRepository->getProviderMetadata($reservationId);

        return \is_array($reservation) && ProviderReservationView::isProviderBacked($reservation);
    }

    private function shouldBlockProviderBackedAction(int $reservationId, string $action, string $surface): bool
    {
        $reservation = $this->reservationRepository->getProviderMetadata($reservationId);

        return \is_array($reservation)
            && ProviderReservationActionPolicy::shouldBlockProviderBackedAction($reservation, $action, $surface);
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
        return $this->paymentAdminActions->applyAdminPaymentAction($reservationId, 'mark_pending');
    }

    private function markReservationPaid(int $reservationId): bool
    {
        return $this->paymentAdminActions->applyAdminPaymentAction($reservationId, 'mark_paid');
    }

    private function markReservationUnpaid(int $reservationId): bool
    {
        return $this->paymentAdminActions->applyAdminPaymentAction($reservationId, 'mark_unpaid');
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

        $result = (new BookingLifecycleSyncService())->applyReservationStatusTransition(
            $reservationId,
            'cancelled',
            \sanitize_key((string) ($reservation['payment_status'] ?? '')),
            [
                'source' => 'admin',
                'operation' => 'cancel_only',
                'reason' => 'admin_cancelled',
            ]
        );

        return !empty($result['success']);
    }

    private function cancelReservationNotice(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $providerContext = ProviderReservationView::metadata($reservation);

        if (empty($providerContext['is_provider_backed'])) {
            return $this->cancelReservation($reservationId) ? 'reservation_cancelled' : 'action_failed';
        }

        if (!ProviderReservationActionPolicy::supportsProviderAction($providerContext, 'cancel', ProviderReservationActionPolicy::SURFACE_ADMIN_POST)) {
            return 'provider_backed_read_only';
        }

        $result = (new ClockPaymentReconciliationService())->cancelReservation($reservationId, 'admin_cancelled', 'admin');

        if (!empty($result['success'])) {
            return 'reservation_cancelled';
        }

        if (!empty($result['queued'])) {
            return 'reservation_cancellation_queued';
        }

        return 'reservation_cancellation_failed';
    }

    private function checkInReservationNotice(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $providerContext = ProviderReservationView::metadata($reservation);

        if (empty($providerContext['is_provider_backed'])) {
            return 'action_failed';
        }

        if (!ProviderReservationActionPolicy::supportsProviderAction($providerContext, 'checkin', ProviderReservationActionPolicy::SURFACE_ADMIN_POST)) {
            return 'provider_backed_read_only';
        }

        $result = (new ClockPaymentReconciliationService())->checkInReservation($reservationId, 'admin');

        if (!empty($result['success'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_checked_in',
                'info',
                \__('Clock guest check-in completed.', 'must-hotel-booking')
            );

            return 'reservation_checked_in';
        }

        if (!empty($result['queued'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_checkin_queued',
                'warning',
                \__('Clock guest check-in was queued for provider retry.', 'must-hotel-booking')
            );

            return 'reservation_checkin_queued';
        }

        return 'reservation_checkin_failed';
    }

    private function checkOutReservationNotice(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $providerContext = ProviderReservationView::metadata($reservation);

        if (empty($providerContext['is_provider_backed'])) {
            return 'action_failed';
        }

        if (!ProviderReservationActionPolicy::supportsProviderAction($providerContext, 'checkout', ProviderReservationActionPolicy::SURFACE_ADMIN_POST)) {
            return 'provider_backed_read_only';
        }

        $result = (new ClockPaymentReconciliationService())->checkOutReservation($reservationId, 'admin');

        if (!empty($result['success'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_checked_out',
                'info',
                \__('Clock guest check-out completed.', 'must-hotel-booking')
            );

            return 'reservation_checked_out';
        }

        if (!empty($result['queued'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_checkout_queued',
                'warning',
                \__('Clock guest check-out was queued for provider retry.', 'must-hotel-booking')
            );

            return 'reservation_checkout_queued';
        }

        return 'reservation_checkout_failed';
    }

    private function assignRoomNotice(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $roomId = isset($_POST['assigned_room_id']) ? \absint(\wp_unslash($_POST['assigned_room_id'])) : 0;
        $result = (new ReservationAmendmentService())->amend($reservationId, [
            'target_assigned_room_id' => $roomId,
        ], 'admin');

        if (!empty($result['success'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'room_assigned',
                'info',
                \__('Room assignment completed.', 'must-hotel-booking')
            );

            return 'reservation_room_assigned';
        }

        if (!empty($result['queued'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_room_assignment_queued',
                'warning',
                \__('Room assignment was queued for provider retry.', 'must-hotel-booking')
            );

            return 'reservation_room_assignment_queued';
        }

        return 'reservation_room_assignment_failed';
    }

    private function amendReservationNotice(int $reservationId): string
    {
        $result = (new ReservationAmendmentService())->amend($reservationId, [
            'target_room_type_id' => isset($_POST['target_room_type_id']) ? \absint(\wp_unslash($_POST['target_room_type_id'])) : 0,
            'target_assigned_room_id' => isset($_POST['target_assigned_room_id']) ? \absint(\wp_unslash($_POST['target_assigned_room_id'])) : 0,
            'target_rate_plan_id' => isset($_POST['target_rate_plan_id']) ? \absint(\wp_unslash($_POST['target_rate_plan_id'])) : 0,
            'target_checkin' => isset($_POST['target_checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['target_checkin'])) : '',
            'target_checkout' => isset($_POST['target_checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['target_checkout'])) : '',
        ], 'admin');

        if (!empty($result['success'])) {
            return !empty($result['manual_review_required'])
                ? 'reservation_amendment_completed_review'
                : 'reservation_amendment_completed';
        }

        if (!empty($result['queued'])) {
            return 'reservation_amendment_queued';
        }

        if (!empty($result['manual_review_required'])) {
            return 'reservation_amendment_review';
        }

        return 'reservation_amendment_failed';
    }

    private function updateStayNotice(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $providerContext = ProviderReservationView::metadata($reservation);

        if (empty($providerContext['is_provider_backed'])) {
            return 'action_failed';
        }

        if (!ProviderReservationActionPolicy::supportsProviderAction($providerContext, 'update_stay', ProviderReservationActionPolicy::SURFACE_ADMIN_POST)) {
            return 'provider_backed_read_only';
        }

        $checkin = isset($_POST['stay_checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['stay_checkin'])) : '';
        $checkout = isset($_POST['stay_checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['stay_checkout'])) : '';
        $result = (new ReservationAmendmentService())->amend($reservationId, [
            'target_checkin' => $checkin,
            'target_checkout' => $checkout,
        ], 'admin');

        if (!empty($result['success']) && !empty($result['pricing_pending'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                !empty($result['queued']) ? 'reservation_stay_pricing_queued' : 'reservation_stay_pricing_pending',
                'warning',
                \__('Clock stay dates updated, but pricing reconciliation is pending.', 'must-hotel-booking')
            );

            return !empty($result['queued']) ? 'reservation_stay_pricing_queued' : 'reservation_stay_pricing_pending';
        }

        if (!empty($result['success'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_stay_updated',
                'info',
                \__('Clock stay dates updated.', 'must-hotel-booking')
            );

            return 'reservation_stay_updated';
        }

        if (!empty($result['queued'])) {
            $this->logReservationActivity(
                $reservationId,
                $reservation,
                'reservation_stay_update_queued',
                'warning',
                \__('Clock stay-date edit was queued for provider retry.', 'must-hotel-booking')
            );

            return 'reservation_stay_update_queued';
        }

        return 'reservation_stay_update_failed';
    }

    private function saveGuestDetails(int $reservationId): string
    {
        $reservation = $this->reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return 'reservation_not_found';
        }

        $providerContext = ProviderReservationView::metadata($reservation);

        $firstName = isset($_POST['guest_first_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_first_name'])) : '';
        $lastName = isset($_POST['guest_last_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_last_name'])) : '';
        $email = isset($_POST['guest_email']) ? \sanitize_email((string) \wp_unslash($_POST['guest_email'])) : '';
        $phone = isset($_POST['guest_phone']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_phone'])) : '';
        $country = isset($_POST['guest_country']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_country'])) : '';

        if ($email !== '' && !\is_email($email)) {
            return 'invalid_guest_email';
        }

        if (!empty($providerContext['is_provider_backed'])) {
            if (!ProviderReservationActionPolicy::supportsProviderAction($providerContext, 'save_guest', ProviderReservationActionPolicy::SURFACE_ADMIN_POST)) {
                return 'provider_backed_read_only';
            }

            $result = (new ClockPaymentReconciliationService())->updateGuestDetails(
                $reservationId,
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                    'country' => $country,
                ],
                'admin'
            );

            if (!empty($result['success'])) {
                $this->logReservationActivity(
                    $reservationId,
                    $reservation,
                    'guest_updated',
                    'info',
                    \__('Clock guest details updated.', 'must-hotel-booking')
                );

                return 'reservation_guest_updated';
            }

            if (!empty($result['queued'])) {
                $this->logReservationActivity(
                    $reservationId,
                    $reservation,
                    'reservation_guest_update_queued',
                    'warning',
                    \__('Clock guest detail edit was queued for provider retry.', 'must-hotel-booking')
                );

                return 'reservation_guest_update_queued';
            }

            return 'reservation_guest_update_failed';
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
        $bookingId   = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';
        $reference   = $bookingId !== '' ? $bookingId : ('RES-' . $reservationId);
        $actorUserId = \get_current_user_id();
        $actorRole   = '';
        $actorIp     = '';

        if ($actorUserId > 0) {
            $actorUser = \wp_get_current_user();

            if ($actorUser instanceof \WP_User && !empty($actorUser->roles)) {
                $actorRole = (string) \reset($actorUser->roles);
            }
        }

        if (isset($_SERVER['REMOTE_ADDR'])) {
            $actorIp = \sanitize_text_field(\wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }

        $this->activityRepository->createActivity(
            [
                'event_type'    => $eventType,
                'severity'      => $severity,
                'entity_type'   => 'reservation',
                'entity_id'     => $reservationId,
                'reference'     => $reference,
                'message'       => $message,
                'actor_user_id' => $actorUserId,
                'actor_role'    => $actorRole,
                'actor_ip'      => $actorIp,
                'context_json'  => \wp_json_encode(
                    [
                        'reservation_id' => $reservationId,
                        'booking_id'     => $bookingId,
                        'actor_user_id'  => $actorUserId,
                        'actor_role'     => $actorRole,
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
