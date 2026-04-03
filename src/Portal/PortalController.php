<?php

namespace MustHotelBooking\Portal;

use MustHotelBooking\Admin\AccommodationAdminDataProvider;
use MustHotelBooking\Admin\AccommodationAdminQuery;
use MustHotelBooking\Admin\AvailabilityAdminDataProvider;
use MustHotelBooking\Admin\AvailabilityAdminQuery;
use MustHotelBooking\Admin\CalendarDataProvider;
use MustHotelBooking\Admin\CalendarViewQuery;
use MustHotelBooking\Admin\DashboardDataProvider;
use MustHotelBooking\Admin\GuestAdminActions;
use MustHotelBooking\Admin\GuestAdminDataProvider;
use MustHotelBooking\Admin\GuestAdminQuery;
use MustHotelBooking\Admin\HousekeepingAdminDataProvider;
use MustHotelBooking\Admin\PaymentAdminActions;
use MustHotelBooking\Admin\PaymentAdminDataProvider;
use MustHotelBooking\Admin\PaymentAdminQuery;
use MustHotelBooking\Admin\ReportAdminDataProvider;
use MustHotelBooking\Admin\ReportAdminQuery;
use MustHotelBooking\Admin\ReservationAdminDataProvider;
use MustHotelBooking\Database\HousekeepingRepository;
use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Engine\BookingStatusEngine;

final class PortalController
{
    /**
     * @return array<string, mixed>
     */
    public static function preparePortalPage(): array
    {
        // Redirect deprecated routes (quick-booking, accommodations, availability-rules)
        // before any rendering takes place.
        self::maybeRedirectDeprecatedRoute();

        $moduleKey = PortalRouter::getRequestedModuleKey();
        $actionState = self::handlePostedActions($moduleKey);

        return [
            'branding' => self::getBrandingData(),
            'user' => self::getCurrentUserData(),
            'navigation' => self::getNavigationItems(),
            'portal_url' => PortalRouter::getPortalUrl(),
            'login_url' => PortalRouter::getLoginUrl(),
            'logout_url' => \wp_logout_url(PortalRouter::getLoginUrl(['logged_out' => 1])),
            'current_module_key' => $moduleKey,
            'current_module' => PortalRegistry::getDefinition($moduleKey),
            'notices' => self::getNotices($actionState),
            'errors' => isset($actionState['errors']) && \is_array($actionState['errors']) ? $actionState['errors'] : [],
            'module_data' => self::getModuleData($moduleKey, $actionState),
        ];
    }

    public static function enqueueAssets(): void
    {
        if (!PortalRouter::isPortalRequest() && !PortalRouter::isLoginRequest()) {
            return;
        }

        \wp_enqueue_style('dashicons');
        \wp_enqueue_style(
            'must-hotel-booking-portal',
            MUST_HOTEL_BOOKING_URL . 'assets/css/portal.css',
            ['dashicons'],
            MUST_HOTEL_BOOKING_VERSION
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function handlePostedActions(string $moduleKey): array
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return [];
        }

        $action = isset($_POST['must_portal_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_portal_action'])) : '';

        if ($action === '') {
            return [];
        }

        if ($action === 'quick_booking_create') {
            return self::handleQuickBookingAction();
        }

        if ($action === 'reservation_confirm') {
            self::handleReservationTransition('confirm', StaffAccess::CAP_RESERVATION_EDIT_BASIC);
            return [];
        }

        if ($action === 'reservation_cancel') {
            self::handleReservationTransition('cancel', StaffAccess::CAP_RESERVATION_CANCEL);
            return [];
        }

        if ($action === 'reservation_checkin') {
            self::handleReservationCheckin();
            return [];
        }

        if ($action === 'reservation_checkout') {
            self::handleReservationCheckout();
            return [];
        }

        if ($action === 'reservation_assign_room') {
            self::handleReservationAssignRoom();
            return [];
        }

        if ($action === 'reservation_update_stay') {
            return self::handleReservationUpdateStay();
        }

        if ($action === 'reservation_mark_no_show') {
            self::handleReservationMarkNoShow();
            return [];
        }

        if ($action === 'reservation_add_note') {
            self::handleReservationAddNote();
            return [];
        }

        if ($action === 'reservation_request_cancel') {
            self::handleReservationRequestCancel();
            return [];
        }

        if ($action === 'reservation_reject_cancel') {
            self::handleReservationRejectCancel();
            return [];
        }

        if ($action === 'reservation_approve_cancel') {
            self::handleReservationApproveCancel();
            return [];
        }

        if ($action === 'reservation_mark_pending') {
            self::handleReservationPaymentStateChange('mark_pending');
            return [];
        }

        if ($action === 'reservation_mark_paid') {
            self::handleReservationPaymentStateChange('mark_paid');
            return [];
        }

        if ($action === 'reservation_mark_unpaid') {
            self::handleReservationPaymentStateChange('mark_unpaid');
            return [];
        }

        if ($action === 'reservation_resend_guest_email') {
            self::handleReservationEmailAction('resend_guest_email');
            return [];
        }

        if ($action === 'reservation_resend_admin_email') {
            self::handleReservationEmailAction('resend_admin_email');
            return [];
        }

        if ($action === 'reservation_save_guest') {
            self::handleReservationGuestSave();
            return [];
        }

        if ($action === 'reservation_save_admin_details') {
            self::handleReservationAdminDetailsSave();
            return [];
        }

        if ($action === 'guest_save_contact') {
            return self::handleGuestSaveContact();
        }

        if ($action === 'guest_save_flags') {
            return self::handleGuestSaveFlags();
        }

        if ($action === 'guest_add_note') {
            return self::handleGuestAddNote();
        }

        if ($action === 'payment_post') {
            return self::handlePaymentPost(false);
        }

        if ($action === 'payment_post_partial') {
            return self::handlePaymentPost(true);
        }

        if ($action === 'payment_mark_paid') {
            return self::handlePaymentStateChange('mark_paid', StaffAccess::CAP_PAYMENT_MARK_PAID, 'payment_marked_paid');
        }

        if ($action === 'payment_mark_unpaid') {
            return self::handlePaymentStateChange('mark_unpaid', StaffAccess::CAP_PAYMENT_RECONCILE, 'payment_marked_unpaid');
        }

        if ($action === 'payment_mark_pending') {
            return self::handlePaymentStateChange('mark_pending', StaffAccess::CAP_PAYMENT_RECONCILE, 'payment_marked_pending');
        }

        if ($action === 'payment_mark_pay_at_hotel') {
            return self::handlePaymentStateChange('mark_pay_at_hotel', StaffAccess::CAP_PAYMENT_RECONCILE, 'payment_marked_pay_at_hotel');
        }

        if ($action === 'payment_mark_failed') {
            return self::handlePaymentStateChange('mark_failed', StaffAccess::CAP_PAYMENT_RECONCILE, 'payment_marked_failed');
        }

        if ($action === 'payment_refund') {
            return self::handlePaymentRefund();
        }

        if ($action === 'payment_issue_receipt') {
            self::handlePaymentDocumentRequest('receipt');
            return [];
        }

        if ($action === 'payment_issue_invoice') {
            self::handlePaymentDocumentRequest('invoice');
            return [];
        }

        if ($action === 'calendar_create_block') {
            return self::handleCalendarCreateBlock();
        }

        if ($action === 'housekeeping_mark_dirty') {
            self::handleHousekeepingStatusUpdate(
                HousekeepingRepository::STATUS_DIRTY,
                StaffAccess::CAP_HOUSEKEEPING_UPDATE_STATUS,
                'housekeeping_marked_dirty'
            );
            return [];
        }

        if ($action === 'housekeeping_mark_clean') {
            self::handleHousekeepingStatusUpdate(
                HousekeepingRepository::STATUS_CLEAN,
                StaffAccess::CAP_HOUSEKEEPING_UPDATE_STATUS,
                'housekeeping_marked_clean'
            );
            return [];
        }

        if ($action === 'housekeeping_mark_inspected') {
            self::handleHousekeepingStatusUpdate(
                HousekeepingRepository::STATUS_INSPECTED,
                StaffAccess::CAP_HOUSEKEEPING_INSPECT,
                'housekeeping_marked_inspected'
            );
            return [];
        }

        if ($action === 'housekeeping_assign_room') {
            return self::handleHousekeepingAssignRoom();
        }

        if ($action === 'housekeeping_create_issue') {
            return self::handleHousekeepingCreateIssue();
        }

        if ($action === 'housekeeping_update_issue_status') {
            self::handleHousekeepingUpdateIssueStatus();
            return [];
        }

        if ($action === 'housekeeping_create_handoff') {
            return self::handleHousekeepingCreateHandoff();
        }

        if ($action === 'rooms_availability_save_block') {
            return self::handleRoomsAvailabilitySaveBlock();
        }

        if ($action === 'rooms_availability_delete_block') {
            self::handleRoomsAvailabilityDeleteBlock();
            return [];
        }

        if ($action === 'rooms_availability_save_rule') {
            return self::handleRoomsAvailabilitySaveRule();
        }

        if ($action === 'rooms_availability_delete_rule') {
            self::handleRoomsAvailabilityDeleteRule();
            return [];
        }

        if ($action === 'rooms_availability_toggle_rule_status') {
            self::handleRoomsAvailabilityToggleRuleStatus();
            return [];
        }

        return [
            'module_key' => $moduleKey,
            'errors' => [\__('Unknown portal action.', 'must-hotel-booking')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleQuickBookingAction(): array
    {
        if (!\current_user_can(StaffAccess::CAP_RESERVATION_CREATE)) {
            return [
                'module_key' => 'front_desk',
                'errors' => [\__('You do not have permission to create reservations.', 'must-hotel-booking')],
            ];
        }

        $nonce = isset($_POST['must_portal_quick_booking_nonce']) ? (string) \wp_unslash($_POST['must_portal_quick_booking_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_portal_quick_booking')) {
            return [
                'module_key' => 'front_desk',
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $form    = \MustHotelBooking\Admin\sanitize_admin_quick_booking_form_values($rawPost);
        $errors  = isset($form['errors']) && \is_array($form['errors']) ? $form['errors'] : [];

        if (!empty($errors)) {
            return [
                'module_key' => 'front_desk',
                'errors'     => $errors,
                'form'       => $form,
            ];
        }

        $reservationId = \MustHotelBooking\Admin\create_admin_quick_booking_reservation($form);

        if ($reservationId <= 0) {
            return [
                'module_key' => 'front_desk',
                'errors'     => [\__('Unable to create the reservation from the submitted booking.', 'must-hotel-booking')],
                'form'       => $form,
            ];
        }

        \wp_safe_redirect(
            PortalRouter::getModuleUrl(
                'reservations',
                [
                    'reservation_id' => $reservationId,
                    'portal_notice'  => 'quick_booking_created',
                ]
            )
        );
        exit;
    }

    private static function handleReservationTransition(string $transition, string $capability): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';
        $moduleKey = 'reservations';
        $notice = 'portal_action_failed';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_action_' . $transition . '_' . $reservationId)) {
            \wp_safe_redirect(PortalRouter::getModuleUrl($moduleKey, ['portal_notice' => 'invalid_nonce']));
            exit;
        }

        if (!\current_user_can($capability) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
            exit;
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation = $reservationRepository->getReservation($reservationId);

        if (\is_array($reservation)) {
            $status = \sanitize_key((string) ($reservation['status'] ?? ''));
            $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));

            if ($transition === 'confirm' && !\in_array($status, ['cancelled', 'blocked', 'confirmed', 'completed'], true)) {
                BookingStatusEngine::updateReservationStatuses([$reservationId], 'confirmed', $paymentStatus !== '' ? $paymentStatus : 'unpaid');
                $notice = 'reservation_confirmed';
            }

            if ($transition === 'cancel' && !\in_array($status, ['cancelled', 'blocked', 'completed'], true)) {
                if (!empty($reservation['cancellation_requested'])) {
                    $notice = self::approveReservationCancellationRequest($reservationId, $reservation)
                        ? 'cancellation_request_approved'
                        : 'portal_action_failed';
                } else {
                    BookingStatusEngine::updateReservationStatuses([$reservationId], 'cancelled', $paymentStatus);
                    $notice = 'reservation_cancelled';
                }
            }
        }

        self::redirectToPortalReservationDetail($reservationId, $notice);
    }

    private static function handleReservationPaymentStateChange(string $action): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_action_' . $action . '_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_PAYMENT_MARK_PAID) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $noticeMap = [
            'mark_pending' => 'reservation_marked_pending',
            'mark_paid' => 'reservation_marked_paid',
            'mark_unpaid' => 'reservation_marked_unpaid',
        ];
        $success = (new PaymentAdminActions())->applyAdminPaymentAction($reservationId, $action);
        self::redirectToPortalReservationDetail(
            $reservationId,
            $success ? (string) ($noticeMap[$action] ?? 'portal_action_completed') : 'portal_action_failed'
        );
    }

    private static function handleReservationEmailAction(string $action): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_action_' . $action . '_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_EDIT_BASIC) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $success = $action === 'resend_guest_email'
            ? \MustHotelBooking\Engine\EmailEngine::resendGuestReservationEmail($reservationId)
            : \MustHotelBooking\Engine\EmailEngine::resendAdminReservationEmail($reservationId);

        self::redirectToPortalReservationDetail(
            $reservationId,
            $success
                ? ($action === 'resend_guest_email' ? 'reservation_guest_email_resent' : 'reservation_admin_email_resent')
                : 'portal_action_failed'
        );
    }

    private static function handleReservationGuestSave(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_save_guest_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_GUEST_EDIT_CONTACT) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $reservation = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $firstName = isset($_POST['guest_first_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_first_name'])) : '';
        $lastName = isset($_POST['guest_last_name']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_last_name'])) : '';
        $email = isset($_POST['guest_email']) ? \sanitize_email((string) \wp_unslash($_POST['guest_email'])) : '';
        $phone = isset($_POST['guest_phone']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_phone'])) : '';
        $country = isset($_POST['guest_country']) ? \sanitize_text_field((string) \wp_unslash($_POST['guest_country'])) : '';

        if ($email !== '' && !\is_email($email)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_guest_email');
        }

        $guestId = $guestRepository->saveAdminGuestProfile(
            isset($reservation['guest_id']) ? (int) $reservation['guest_id'] : 0,
            $firstName,
            $lastName,
            $email,
            $phone,
            $country
        );

        if ($guestId <= 0) {
            self::redirectToPortalReservationDetail($reservationId, 'portal_action_failed');
        }

        if ($guestId !== (int) ($reservation['guest_id'] ?? 0)) {
            $reservationRepository->updateReservation($reservationId, ['guest_id' => $guestId]);
        }

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'guest_updated',
            'info',
            \__('Guest details updated.', 'must-hotel-booking')
        );

        self::redirectToPortalReservationDetail($reservationId, 'reservation_guest_updated');
    }

    private static function handleReservationAdminDetailsSave(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_save_admin_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_EDIT_BASIC) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $bookingSource = isset($_POST['booking_source']) ? \sanitize_key((string) \wp_unslash($_POST['booking_source'])) : 'website';
        $sourceOptions = \function_exists('\MustHotelBooking\Admin\get_admin_quick_booking_source_options')
            ? \MustHotelBooking\Admin\get_admin_quick_booking_source_options()
            : [];

        if (!isset($sourceOptions[$bookingSource])) {
            $bookingSource = isset($reservation['booking_source']) ? (string) $reservation['booking_source'] : 'website';
        }

        $currentSource = isset($reservation['booking_source']) ? (string) $reservation['booking_source'] : 'website';

        if ($bookingSource === $currentSource) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_updated');
        }

        $updated = $reservationRepository->updateReservation(
            $reservationId,
            [
                'booking_source' => $bookingSource,
            ]
        );

        if (!$updated) {
            self::redirectToPortalReservationDetail($reservationId, 'portal_action_failed');
        }

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'reservation_updated',
            'info',
            \__('Reservation source updated.', 'must-hotel-booking')
        );

        self::redirectToPortalReservationDetail($reservationId, 'reservation_updated');
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleGuestSaveContact(): array
    {
        $guestId = isset($_POST['guest_id']) ? \absint(\wp_unslash($_POST['guest_id'])) : 0;
        $nonce = isset($_POST['must_portal_guest_nonce']) ? (string) \wp_unslash($_POST['must_portal_guest_nonce']) : '';

        if ($guestId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_guest_save_contact_' . $guestId)) {
            self::redirectToPortalGuestDetail($guestId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_GUEST_EDIT_CONTACT) && !\current_user_can('manage_options')) {
            self::redirectToPortalGuestDetail($guestId, 'access_denied');
        }

        $guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $guest = $guestRepository->getGuestById($guestId);

        if (!\is_array($guest)) {
            self::redirectToPortalGuestDetail($guestId, 'guest_not_found');
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $form = GuestAdminActions::sanitizeGuestForm($rawPost);
        $errors = GuestAdminActions::validateGuestForm($form);

        if (!empty($errors)) {
            return self::buildGuestActionState($guestId, $form, $errors);
        }

        $updated = $guestRepository->updateAdminGuestRecord(
            $guestId,
            self::mergeGuestUpdatePayload(
                $guest,
                [
                    'first_name' => (string) $form['first_name'],
                    'last_name' => (string) $form['last_name'],
                    'email' => (string) $form['email'],
                    'phone' => (string) $form['phone'],
                    'country' => (string) $form['country'],
                ]
            )
        );

        if (!$updated) {
            return self::buildGuestActionState(
                $guestId,
                $form,
                [\__('Unable to save the guest profile.', 'must-hotel-booking')]
            );
        }

        self::logGuestActivity(
            $guestId,
            $guest,
            'guest_contact_updated',
            'info',
            \__('Guest contact details updated.', 'must-hotel-booking')
        );

        self::redirectToPortalGuestDetail($guestId, 'guest_contact_updated');
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleGuestSaveFlags(): array
    {
        $guestId = isset($_POST['guest_id']) ? \absint(\wp_unslash($_POST['guest_id'])) : 0;
        $nonce = isset($_POST['must_portal_guest_nonce']) ? (string) \wp_unslash($_POST['must_portal_guest_nonce']) : '';

        if ($guestId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_guest_save_flags_' . $guestId)) {
            self::redirectToPortalGuestDetail($guestId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_GUEST_EDIT_FLAGS) && !\current_user_can('manage_options')) {
            self::redirectToPortalGuestDetail($guestId, 'access_denied');
        }

        $guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $guest = $guestRepository->getGuestById($guestId);

        if (!\is_array($guest)) {
            self::redirectToPortalGuestDetail($guestId, 'guest_not_found');
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $form = GuestAdminActions::sanitizeGuestForm($rawPost);
        $updated = $guestRepository->updateAdminGuestRecord(
            $guestId,
            self::mergeGuestUpdatePayload(
                $guest,
                [
                    'vip_flag' => (int) $form['vip_flag'],
                    'problem_flag' => (int) $form['problem_flag'],
                ]
            )
        );

        if (!$updated) {
            return self::buildGuestActionState(
                $guestId,
                [
                    'vip_flag' => (int) $form['vip_flag'],
                    'problem_flag' => (int) $form['problem_flag'],
                ],
                [\__('Unable to save the guest flags.', 'must-hotel-booking')]
            );
        }

        $statusLabels = [];

        if (!empty($form['vip_flag'])) {
            $statusLabels[] = \__('VIP', 'must-hotel-booking');
        }

        if (!empty($form['problem_flag'])) {
            $statusLabels[] = \__('Problem', 'must-hotel-booking');
        }

        $message = empty($statusLabels)
            ? \__('Guest flags cleared.', 'must-hotel-booking')
            : \sprintf(
                /* translators: %s: comma-separated guest flag labels */
                \__('Guest flags updated: %s.', 'must-hotel-booking'),
                \implode(', ', $statusLabels)
            );

        self::logGuestActivity($guestId, $guest, 'guest_flags_updated', 'info', $message);

        self::redirectToPortalGuestDetail($guestId, 'guest_flags_updated');
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleGuestAddNote(): array
    {
        $guestId = isset($_POST['guest_id']) ? \absint(\wp_unslash($_POST['guest_id'])) : 0;
        $nonce = isset($_POST['must_portal_guest_nonce']) ? (string) \wp_unslash($_POST['must_portal_guest_nonce']) : '';

        if ($guestId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_guest_add_note_' . $guestId)) {
            self::redirectToPortalGuestDetail($guestId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_GUEST_ADD_NOTE) && !\current_user_can('manage_options')) {
            self::redirectToPortalGuestDetail($guestId, 'access_denied');
        }

        $guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $guest = $guestRepository->getGuestById($guestId);

        if (!\is_array($guest)) {
            self::redirectToPortalGuestDetail($guestId, 'guest_not_found');
        }

        $note = isset($_POST['internal_note']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['internal_note'])) : '';
        $note = \trim($note);

        if ($note === '') {
            return self::buildGuestActionState(
                $guestId,
                ['internal_note' => ''],
                [\__('Enter a guest service note before saving.', 'must-hotel-booking')]
            );
        }

        $currentUser = \wp_get_current_user();
        $authorName = $currentUser instanceof \WP_User && $currentUser->display_name !== '' ? (string) $currentUser->display_name : \__('Staff', 'must-hotel-booking');
        $timestamp = \date_i18n('d M Y H:i', \current_time('timestamp'));
        $noteEntry = '[' . $timestamp . ' - ' . $authorName . '] ' . $note;
        $existingNotes = \trim((string) ($guest['admin_notes'] ?? ''));
        $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n\n" . $noteEntry : $noteEntry;

        $updated = $guestRepository->updateAdminGuestRecord(
            $guestId,
            self::mergeGuestUpdatePayload(
                $guest,
                [
                    'admin_notes' => $updatedNotes,
                ]
            )
        );

        if (!$updated) {
            return self::buildGuestActionState(
                $guestId,
                ['internal_note' => $note],
                [\__('Unable to save the guest service note.', 'must-hotel-booking')]
            );
        }

        self::logGuestActivity(
            $guestId,
            $guest,
            'guest_note_added',
            'info',
            \sprintf(
                /* translators: %s: staff member display name */
                \__('Guest service note added by %s.', 'must-hotel-booking'),
                $authorName
            )
        );

        self::redirectToPortalGuestDetail($guestId, 'guest_note_added');
    }

    /**
     * @return array<string, mixed>
     */
    private static function handlePaymentPost(bool $allowPartial): array
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_payment_nonce']) ? (string) \wp_unslash($_POST['must_portal_payment_nonce']) : '';
        $action = $allowPartial ? 'payment_post_partial' : 'payment_post';
        $capability = $allowPartial ? StaffAccess::CAP_PAYMENT_POST_PARTIAL : StaffAccess::CAP_PAYMENT_POST;
        $rawAmount = isset($_POST['amount']) && !\is_array($_POST['amount']) ? (string) \wp_unslash($_POST['amount']) : '';
        $amount = (float) $rawAmount;
        $transactionId = isset($_POST['transaction_id']) && !\is_array($_POST['transaction_id'])
            ? \sanitize_text_field((string) \wp_unslash($_POST['transaction_id']))
            : '';
        $form = [
            'amount' => $amount > 0 ? \number_format($amount, 2, '.', '') : '',
            'transaction_id' => $transactionId,
        ];

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_payment_action_' . $action . '_' . $reservationId)) {
            return self::buildPaymentActionState($reservationId, ['post' => $form], [\__('Security check failed. Please try again.', 'must-hotel-booking')]);
        }

        if (!\current_user_can($capability) && !\current_user_can('manage_options')) {
            return self::buildPaymentActionState($reservationId, ['post' => $form], [\__('You do not have permission to post payments from the portal.', 'must-hotel-booking')]);
        }

        $paymentActions = new PaymentAdminActions();

        if (!$allowPartial) {
            $detail = (new PaymentAdminDataProvider())->getDetailDataForReservation($reservationId);
            $amount = \is_array($detail) ? (float) (($detail['state']['amount_due'] ?? 0.0)) : 0.0;
            $form['amount'] = $amount > 0 ? \number_format($amount, 2, '.', '') : '';
        }

        $result = $paymentActions->recordPostedPayment($reservationId, $amount, 'pay_at_hotel', $transactionId);

        if (empty($result['success'])) {
            return self::buildPaymentActionState(
                $reservationId,
                ['post' => $form],
                [isset($result['message']) ? (string) $result['message'] : \__('Unable to record the payment.', 'must-hotel-booking')]
            );
        }

        self::redirectToPortalPaymentDetail($reservationId, (string) ($result['notice'] ?? 'payment_posted'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function handlePaymentRefund(): array
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_payment_nonce']) ? (string) \wp_unslash($_POST['must_portal_payment_nonce']) : '';
        $rawAmount = isset($_POST['amount']) && !\is_array($_POST['amount']) ? (string) \wp_unslash($_POST['amount']) : '';
        $amount = (float) $rawAmount;
        $form = [
            'amount' => $amount > 0 ? \number_format($amount, 2, '.', '') : '',
        ];

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_payment_action_payment_refund_' . $reservationId)) {
            return self::buildPaymentActionState($reservationId, ['refund' => $form], [\__('Security check failed. Please try again.', 'must-hotel-booking')]);
        }

        if (!\current_user_can(StaffAccess::CAP_PAYMENT_REFUND) && !\current_user_can('manage_options')) {
            return self::buildPaymentActionState($reservationId, ['refund' => $form], [\__('You do not have permission to issue refunds from the portal.', 'must-hotel-booking')]);
        }

        $result = (new PaymentAdminActions())->refundRecordedPayment($reservationId, $amount);

        if (empty($result['success'])) {
            return self::buildPaymentActionState(
                $reservationId,
                ['refund' => $form],
                [isset($result['message']) ? (string) $result['message'] : \__('Unable to issue the refund.', 'must-hotel-booking')]
            );
        }

        self::redirectToPortalPaymentDetail($reservationId, (string) ($result['notice'] ?? 'payment_refunded'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function handlePaymentStateChange(string $action, string $capability, string $successNotice): array
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_payment_nonce']) ? (string) \wp_unslash($_POST['must_portal_payment_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_payment_action_' . $action . '_' . $reservationId)) {
            return self::buildPaymentActionState($reservationId, [], [\__('Security check failed. Please try again.', 'must-hotel-booking')]);
        }

        if (!\current_user_can($capability) && !\current_user_can('manage_options')) {
            return self::buildPaymentActionState($reservationId, [], [\__('You do not have permission to perform that payment action.', 'must-hotel-booking')]);
        }

        $success = (new PaymentAdminActions())->applyAdminPaymentAction($reservationId, $action);

        if (!$success) {
            return self::buildPaymentActionState($reservationId, [], [\__('The requested payment action could not be completed for this reservation state.', 'must-hotel-booking')]);
        }

        self::redirectToPortalPaymentDetail($reservationId, $successNotice);
    }

    private static function handlePaymentDocumentRequest(string $documentType): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_payment_nonce']) ? (string) \wp_unslash($_POST['must_portal_payment_nonce']) : '';
        $capability = $documentType === 'invoice' ? StaffAccess::CAP_PAYMENT_INVOICE : StaffAccess::CAP_PAYMENT_RECEIPT;

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_payment_action_payment_issue_' . $documentType . '_' . $reservationId)) {
            self::redirectToPortalPaymentDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can($capability) && !\current_user_can('manage_options')) {
            self::redirectToPortalPaymentDetail($reservationId, 'access_denied');
        }

        $detail = (new PaymentAdminDataProvider())->getDetailDataForReservation($reservationId);

        if (!\is_array($detail)) {
            self::redirectToPortalPaymentDetail($reservationId, 'reservation_not_found');
        }

        $state = isset($detail['state']) && \is_array($detail['state']) ? $detail['state'] : [];
        $hasRecordedPayments = !empty($detail['payments']) || (float) ($state['amount_paid'] ?? 0.0) > 0.0;
        $canRender = $documentType === 'invoice'
            ? (float) ($state['total'] ?? 0.0) > 0.0
            : $hasRecordedPayments;

        if (!$canRender) {
            self::redirectToPortalPaymentDetail($reservationId, 'portal_action_failed');
        }

        self::renderPaymentDocument($documentType, $detail);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private static function renderPaymentDocument(string $documentType, array $detail): void
    {
        $reservation = isset($detail['reservation']) && \is_array($detail['reservation']) ? $detail['reservation'] : [];
        $state = isset($detail['state']) && \is_array($detail['state']) ? $detail['state'] : [];
        $payments = isset($detail['payments']) && \is_array($detail['payments']) ? $detail['payments'] : [];
        $currency = MustBookingConfig::get_currency();
        $hotelName = MustBookingConfig::get_hotel_name();
        $bookingId = (string) ($detail['booking_id'] ?? ('RES-' . (int) ($detail['reservation_id'] ?? 0)));
        $guestName = (string) ($detail['guest_name'] ?? '');
        $guestEmail = (string) ($detail['guest_email'] ?? '');
        $guestPhone = (string) ($detail['guest_phone'] ?? '');
        $documentLabel = $documentType === 'invoice' ? \__('Invoice', 'must-hotel-booking') : \__('Receipt', 'must-hotel-booking');

        \nocache_headers();
        \status_header(200);
        \header('Content-Type: text/html; charset=' . \get_option('blog_charset'));

        echo '<!DOCTYPE html><html><head><meta charset="' . \esc_attr((string) \get_option('blog_charset')) . '" />';
        echo '<title>' . \esc_html($documentLabel . ' ' . $bookingId) . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;color:#16212b;margin:32px;background:#fff}h1,h2{margin:0 0 8px}p{margin:0 0 10px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border:1px solid #d7dde5;padding:10px;text-align:left;font-size:14px}th{background:#f5f7fa} .meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:24px 0} .meta-block{padding:16px;border:1px solid #d7dde5;border-radius:10px} .summary{margin-top:24px;padding:16px;border:1px solid #d7dde5;border-radius:10px;background:#f9fbfc} .summary strong{display:block;margin-bottom:4px} .print-note{margin-top:24px;font-size:12px;color:#5f6b7a}</style>';
        echo '</head><body>';
        echo '<h1>' . \esc_html($hotelName !== '' ? $hotelName : \__('Hotel', 'must-hotel-booking')) . '</h1>';
        echo '<p>' . \esc_html($documentLabel . ' #' . $bookingId) . '</p>';
        echo '<p>' . \esc_html(\sprintf(\__('Generated %s', 'must-hotel-booking'), \wp_date(\get_option('date_format') . ' ' . \get_option('time_format'), \current_time('timestamp')))) . '</p>';
        echo '<div class="meta">';
        echo '<div class="meta-block"><h2>' . \esc_html__('Guest', 'must-hotel-booking') . '</h2>';
        echo '<p>' . \esc_html($guestName !== '' ? $guestName : \__('Unknown guest', 'must-hotel-booking')) . '</p>';
        if ($guestEmail !== '') {
            echo '<p>' . \esc_html($guestEmail) . '</p>';
        }
        if ($guestPhone !== '') {
            echo '<p>' . \esc_html($guestPhone) . '</p>';
        }
        echo '</div>';
        echo '<div class="meta-block"><h2>' . \esc_html__('Stay', 'must-hotel-booking') . '</h2>';
        echo '<p>' . \esc_html((string) ($detail['accommodation'] ?? \__('Unassigned', 'must-hotel-booking'))) . '</p>';
        echo '<p>' . \esc_html(\sprintf('%s - %s', (string) ($reservation['checkin'] ?? ''), (string) ($reservation['checkout'] ?? ''))) . '</p>';
        echo '<p>' . \esc_html(\sprintf(\__('Reservation status: %s', 'must-hotel-booking'), (string) ($reservation['status'] ?? ''))) . '</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="summary">';
        echo '<strong>' . \esc_html($documentType === 'invoice' ? \__('Invoice summary', 'must-hotel-booking') : \__('Payment summary', 'must-hotel-booking')) . '</strong>';
        echo '<p>' . \esc_html(\sprintf(\__('Reservation total: %1$s %2$s', 'must-hotel-booking'), \number_format_i18n((float) ($state['total'] ?? 0.0), 2), $currency)) . '</p>';
        echo '<p>' . \esc_html(\sprintf(\__('Amount paid: %1$s %2$s', 'must-hotel-booking'), \number_format_i18n((float) ($state['gross_amount_paid'] ?? ($state['amount_paid'] ?? 0.0)), 2), $currency)) . '</p>';
        if ((float) ($state['amount_refunded'] ?? 0.0) > 0.0) {
            echo '<p>' . \esc_html(\sprintf(\__('Amount refunded: %1$s %2$s', 'must-hotel-booking'), \number_format_i18n((float) ($state['amount_refunded'] ?? 0.0), 2), $currency)) . '</p>';
        }
        echo '<p>' . \esc_html(\sprintf(\__('Outstanding due: %1$s %2$s', 'must-hotel-booking'), \number_format_i18n((float) ($state['amount_due'] ?? 0.0), 2), $currency)) . '</p>';
        echo '</div>';

        if (!empty($payments)) {
            echo '<table><thead><tr><th>' . \esc_html__('Amount', 'must-hotel-booking') . '</th><th>' . \esc_html__('Method', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th><th>' . \esc_html__('Recorded', 'must-hotel-booking') . '</th></tr></thead><tbody>';

            foreach ($payments as $paymentRow) {
                if (!\is_array($paymentRow)) {
                    continue;
                }

                echo '<tr>';
                echo '<td>' . \esc_html(\number_format_i18n((float) ($paymentRow['amount'] ?? 0.0), 2) . ' ' . (string) ($paymentRow['currency'] ?? $currency)) . '</td>';
                echo '<td>' . \esc_html((string) ($paymentRow['method'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($paymentRow['status'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) ($paymentRow['transaction_id'] ?? '')) . '</td>';
                echo '<td>' . \esc_html((string) (($paymentRow['paid_at'] ?? '') !== '' ? $paymentRow['paid_at'] : ($paymentRow['created_at'] ?? ''))) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '<p class="print-note">' . \esc_html__('This document was generated from the current portal reservation and payment records. Print or save it using your browser if needed.', 'must-hotel-booking') . '</p>';
        echo '</body></html>';
        exit;
    }

    /**
     * @param array<string, mixed> $actionState
     * @return array<string, mixed>
     */
    private static function getModuleData(string $moduleKey, array $actionState): array
    {
        if ($moduleKey === 'dashboard') {
            return self::prepareDashboardData();
        }

        if ($moduleKey === 'reservations') {
            return self::prepareReservationsData($actionState);
        }

        if ($moduleKey === 'calendar') {
            return self::prepareCalendarData($actionState);
        }

        if ($moduleKey === 'front_desk') {
            return self::prepareFrontDeskData($actionState);
        }

        if ($moduleKey === 'guests') {
            return self::prepareGuestsData($actionState);
        }

        if ($moduleKey === 'payments') {
            return self::preparePaymentsData($actionState);
        }

        if ($moduleKey === 'reports') {
            return self::prepareReportsData();
        }

        if ($moduleKey === 'housekeeping') {
            return self::prepareHousekeepingData($actionState);
        }

        if ($moduleKey === 'rooms_availability') {
            return self::prepareRoomsAvailabilityData($actionState);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareDashboardData(): array
    {
        $data = (new DashboardDataProvider())->getDashboardData();
        $data['kpis'] = self::mapDashboardKpisToPortal(isset($data['kpis']) && \is_array($data['kpis']) ? $data['kpis'] : []);
        $data = self::mapDashboardActionUrlsToPortal($data);
        $data = self::filterDashboardDataForCurrentUser($data);
        $quickActions = [];

        foreach (PortalRegistry::getVisibleDefinitionsForCurrentUser() as $key => $definition) {
            if ($key === 'dashboard') {
                continue;
            }

            $quickActions[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'url' => PortalRouter::getModuleUrl($key),
                'icon' => (string) ($definition['icon'] ?? 'dashicons-arrow-right-alt2'),
                'description' => self::getPortalQuickActionDescription($key),
            ];
        }

        $data['quick_actions'] = $quickActions;
        $data['show_health'] = \current_user_can('manage_options');
        $data['portal_counts'] = [
            'attention' => \count(isset($data['attention_items']) && \is_array($data['attention_items']) ? $data['attention_items'] : []),
            'health_review' => self::countActionableHealthItems(isset($data['health_items']) && \is_array($data['health_items']) ? $data['health_items'] : []),
            'recent_reservations' => \count(isset($data['recent_reservations']) && \is_array($data['recent_reservations']) ? $data['recent_reservations'] : []),
            'recent_activity' => \count(isset($data['recent_activity']) && \is_array($data['recent_activity']) ? $data['recent_activity'] : []),
        ];

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, array<string, mixed>>
     */
    private static function mapDashboardKpisToPortal(array $cards): array
    {
        foreach ($cards as $index => $card) {
            if (!\is_array($card)) {
                continue;
            }

            $key = \sanitize_key((string) ($card['key'] ?? ''));
            $linkUrl = '';

            if ($key === 'arrivals_today') {
                $linkUrl = PortalRouter::getModuleUrl('reservations', ['preset' => 'arrivals_today']);
            } elseif ($key === 'departures_today') {
                $linkUrl = PortalRouter::getModuleUrl('reservations', ['preset' => 'departures_today']);
            } elseif ($key === 'in_house_today') {
                $linkUrl = PortalRouter::getModuleUrl('reservations', ['preset' => 'in_house_today']);
            } elseif ($key === 'pending_reservations') {
                $linkUrl = PortalRouter::getModuleUrl('reservations', ['preset' => 'pending']);
            } elseif ($key === 'unpaid_reservations') {
                $linkUrl = PortalRouter::getModuleUrl('payments', ['payment_group' => 'due']);
            } elseif ($key === 'revenue_today') {
                $linkUrl = PortalRouter::getModuleUrl('payments');
            } elseif ($key === 'occupancy_today') {
                $linkUrl = PortalRouter::getModuleUrl('calendar', ['start_date' => \current_time('Y-m-d'), 'weeks' => 2]);
            } elseif ($key === 'blocked_units') {
                $linkUrl = PortalRouter::getModuleUrl('rooms_availability', ['tab' => 'blocks']);
            }

            if ($linkUrl !== '') {
                $card['link_url'] = $linkUrl;
            }

            $cards[$index] = $card;
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function mapDashboardActionUrlsToPortal(array $data): array
    {
        foreach (['approval_items', 'attention_items', 'recent_activity'] as $key) {
            if (empty($data[$key]) || !\is_array($data[$key])) {
                continue;
            }

            foreach ($data[$key] as $index => $item) {
                if (!\is_array($item)) {
                    continue;
                }

                if (!empty($item['action_url'])) {
                    $item['action_url'] = self::mapAdminUrlToPortalUrl((string) $item['action_url']);
                }

                $data[$key][$index] = $item;
            }
        }

        if (!empty($data['recent_reservations']) && \is_array($data['recent_reservations'])) {
            foreach ($data['recent_reservations'] as $index => $item) {
                if (!\is_array($item)) {
                    continue;
                }

                if (!empty($item['view_url'])) {
                    $item['view_url'] = self::mapAdminUrlToPortalUrl((string) $item['view_url']);
                }

                if (!empty($item['edit_url'])) {
                    $item['edit_url'] = self::mapAdminUrlToPortalUrl((string) $item['edit_url']);
                }

                $data['recent_reservations'][$index] = $item;
            }
        }

        return $data;
    }

    private static function mapAdminUrlToPortalUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $parts = \wp_parse_url($url);

        if (!\is_array($parts) || empty($parts['query'])) {
            return $url;
        }

        $queryArgs = [];
        \parse_str((string) $parts['query'], $queryArgs);
        $page = isset($queryArgs['page']) ? \sanitize_key((string) $queryArgs['page']) : '';

        if ($page === 'must-hotel-booking-reservation' && StaffAccess::userCanAccessPortalModule('reservations')) {
            $reservationId = isset($queryArgs['reservation_id']) ? \absint($queryArgs['reservation_id']) : 0;

            if ($reservationId > 0) {
                return PortalRouter::getModuleUrl('reservations', ['reservation_id' => $reservationId]);
            }
        }

        if ($page === 'must-hotel-booking-reservations' && StaffAccess::userCanAccessPortalModule('reservations')) {
            $allowedArgs = self::filterPortalModuleArgs(
                $queryArgs,
                ['quick_filter', 'preset', 'search', 'status', 'payment_status', 'payment_method', 'room_id', 'checkin_from', 'checkin_to', 'checkin_month', 'paged']
            );

            if (!empty($allowedArgs['preset']) && empty($allowedArgs['quick_filter'])) {
                $allowedArgs['quick_filter'] = (string) $allowedArgs['preset'];
            }

            unset($allowedArgs['preset']);

            return PortalRouter::getModuleUrl('reservations', $allowedArgs);
        }

        if ($page === 'must-hotel-booking-payments' && StaffAccess::userCanAccessPortalModule('payments')) {
            $reservationId = isset($queryArgs['reservation_id']) ? \absint($queryArgs['reservation_id']) : 0;
            $allowedArgs = self::filterPortalModuleArgs($queryArgs, ['payment_group', 'search', 'paged']);

            if ($reservationId > 0) {
                $allowedArgs['reservation_id'] = $reservationId;
            }

            return PortalRouter::getModuleUrl('payments', $allowedArgs);
        }

        if ($page === 'must-hotel-booking-calendar' && StaffAccess::userCanAccessPortalModule('calendar')) {
            return PortalRouter::getModuleUrl(
                'calendar',
                self::filterPortalModuleArgs($queryArgs, ['start_date', 'weeks', 'room_id', 'focus_room_id', 'focus_date', 'reservation_id'])
            );
        }

        if ($page === 'must-hotel-booking-guests' && StaffAccess::userCanAccessPortalModule('guests')) {
            return PortalRouter::getModuleUrl('guests', self::filterPortalModuleArgs($queryArgs, ['guest_id', 'search', 'paged']));
        }

        if ($page === 'must-hotel-booking-availability-rules' && StaffAccess::userCanAccessPortalModule('rooms_availability')) {
            return PortalRouter::getModuleUrl('rooms_availability', \array_merge(['tab' => 'rules'], self::filterPortalModuleArgs($queryArgs, ['room_id'])));
        }

        if ($page === 'must-hotel-booking-rooms' && StaffAccess::userCanAccessPortalModule('rooms_availability')) {
            return PortalRouter::getModuleUrl('rooms_availability', ['tab' => 'rooms']);
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<int, string> $allowedKeys
     * @return array<string, scalar>
     */
    private static function filterPortalModuleArgs(array $args, array $allowedKeys): array
    {
        $filtered = [];

        foreach ($allowedKeys as $key) {
            if (!isset($args[$key]) || !\is_scalar($args[$key])) {
                continue;
            }

            $value = \wp_unslash((string) $args[$key]);

            if ($value === '') {
                continue;
            }

            if (\in_array($key, ['room_id', 'guest_id', 'reservation_id', 'paged', 'weeks', 'focus_room_id'], true)) {
                $filtered[$key] = \absint($value);
                continue;
            }

            $filtered[$key] = \sanitize_text_field($value);
        }

        return $filtered;
    }

    private static function getPortalQuickActionDescription(string $moduleKey): string
    {
        $descriptions = [
            'reservations'      => \__('Review arrivals, departures, and booking status changes.', 'must-hotel-booking'),
            'calendar'          => \__('Check room occupancy, availability, and date conflicts.', 'must-hotel-booking'),
            'front_desk'        => \__('Create reservations, process check-ins and check-outs.', 'must-hotel-booking'),
            'guests'            => \__('Search guest profiles, stay history, and contact details.', 'must-hotel-booking'),
            'payments'          => \__('Follow unpaid balances, Stripe issues, and pay-at-hotel collections.', 'must-hotel-booking'),
            'housekeeping'      => \__('View room readiness, cleaning status, and maintenance issues.', 'must-hotel-booking'),
            'rooms_availability'=> \__('Inspect room inventory, blocks, rules, and operational statuses.', 'must-hotel-booking'),
            'reports'           => \__('Open revenue and operational reporting views.', 'must-hotel-booking'),
        ];

        return isset($descriptions[$moduleKey]) ? $descriptions[$moduleKey] : \__('Open this workspace module.', 'must-hotel-booking');
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function countActionableHealthItems(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $status = \sanitize_key((string) ($item['status'] ?? ''));

            if (\in_array($status, ['warning', 'error'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareReservationsData(array $actionState = []): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $request = self::sanitizeReservationPortalRequestForCurrentUser($request);
        $provider = new ReservationAdminDataProvider();
        $data = $provider->getListPageData($request);
        $reservationId = isset($actionState['selected_reservation_id'])
            ? (int) $actionState['selected_reservation_id']
            : (isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0);
        $mode = isset($_GET['mode']) ? \sanitize_key((string) \wp_unslash($_GET['mode'])) : 'view';
        $detail = $reservationId > 0 ? $provider->getDetailPageData($reservationId, $mode) : null;

        if (\is_array($detail) && isset($actionState['forms']) && \is_array($actionState['forms'])) {
            $detail['forms'] = $actionState['forms'];
        }

        if (!self::currentUserCanViewFinanceContext()) {
            if (isset($data['filters']) && \is_array($data['filters'])) {
                $data['filters']['payment_status'] = '';
                $data['filters']['payment_method'] = '';
            }

            $data['payment_status_options'] = [];
            $data['payment_method_options'] = [];
            $data['quick_filters'] = \array_values(
                \array_filter(
                    isset($data['quick_filters']) && \is_array($data['quick_filters']) ? $data['quick_filters'] : [],
                    static function ($filter): bool {
                        if (!\is_array($filter)) {
                            return false;
                        }

                        return !\in_array(
                            \sanitize_key((string) ($filter['slug'] ?? '')),
                            ['unpaid', 'paid', 'failed_payment'],
                            true
                        );
                    }
                )
            );
        }

        $data['detail'] = $detail;
        $data['source_options'] = \function_exists('\MustHotelBooking\Admin\get_admin_quick_booking_source_options')
            ? \MustHotelBooking\Admin\get_admin_quick_booking_source_options()
            : [];

        return $data;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function sanitizeReservationPortalRequestForCurrentUser(array $request): array
    {
        if (self::currentUserCanViewFinanceContext()) {
            return $request;
        }

        unset($request['payment_status'], $request['payment_method']);

        $quickFilter = isset($request['quick_filter']) ? \sanitize_key((string) $request['quick_filter']) : '';
        $preset = isset($request['preset']) ? \sanitize_key((string) $request['preset']) : '';
        $blockedQuickFilters = ['unpaid', 'paid', 'failed_payment'];

        if (\in_array($quickFilter, $blockedQuickFilters, true)) {
            unset($request['quick_filter']);
        }

        if (\in_array($preset, $blockedQuickFilters, true)) {
            unset($request['preset']);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareCalendarData(array $actionState = []): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];

        if (isset($actionState['calendar_query']) && \is_array($actionState['calendar_query'])) {
            $request = \array_merge($request, $actionState['calendar_query']);
        }

        $query = CalendarViewQuery::fromRequest($request);
        $data = (new CalendarDataProvider())->getPageData($query);
        $summary = isset($data['summary']) && \is_array($data['summary']) ? $data['summary'] : [];
        $data['summary'] = [
            ['label' => \__('Occupancy today', 'must-hotel-booking'), 'value' => (string) ((int) ($summary['occupancy_today'] ?? 0)) . '%', 'meta' => \__('Booked units across visible inventory.', 'must-hotel-booking')],
            ['label' => \__('Booked today', 'must-hotel-booking'), 'value' => (string) (int) ($summary['booked_today'] ?? 0), 'meta' => \__('Units currently occupied today.', 'must-hotel-booking')],
            ['label' => \__('Available today', 'must-hotel-booking'), 'value' => (string) (int) ($summary['available_today'] ?? 0), 'meta' => \__('Units available for sale today.', 'must-hotel-booking')],
            ['label' => \__('Blocked today', 'must-hotel-booking'), 'value' => (string) (int) ($summary['blocked_today'] ?? 0), 'meta' => \__('Maintenance, blocks, or unavailable units.', 'must-hotel-booking')],
        ];
        $data['current_args'] = $query->buildUrlArgs();
        $data['range']['previous_url'] = PortalRouter::getModuleUrl('calendar', (array) ($data['range']['previous_args'] ?? []));
        $data['range']['next_url'] = PortalRouter::getModuleUrl('calendar', (array) ($data['range']['next_args'] ?? []));
        $data['rows'] = self::decorateCalendarRows(
            isset($data['rows']) && \is_array($data['rows']) ? $data['rows'] : [],
            $query
        );
        $data['selected'] = self::decorateCalendarSelection(
            isset($data['selected']) && \is_array($data['selected']) ? $data['selected'] : [],
            $query,
            $actionState
        );

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareFrontDeskLogData(): array
    {
        $eventTypes = [
            'reservation_created',
            'reservation_checked_in',
            'reservation_checked_out',
            'room_assigned',
            'cancellation_requested',
            'cancellation_request_approved',
            'cancellation_request_rejected',
        ];

        if (StaffAccess::userCanAccessPortalModule('payments')) {
            $eventTypes[] = 'payment_recorded';
            $eventTypes[] = 'payment_failed';
            $eventTypes[] = 'payment_refunded';
        }

        $activityRows = \MustHotelBooking\Engine\get_activity_repository()->getRecentActivitiesByEventTypes($eventTypes, 40);
        $rows = [];
        $reservationCount = 0;
        $paymentCount = 0;
        $cancellationCount = 0;

        foreach ($activityRows as $activityRow) {
            if (!\is_array($activityRow)) {
                continue;
            }

            $eventType = \sanitize_key((string) ($activityRow['event_type'] ?? ''));
            $entityType = \sanitize_key((string) ($activityRow['entity_type'] ?? ''));
            $context = self::decodeActivityContext((string) ($activityRow['context_json'] ?? ''));
            $actionUrl = self::buildAuditActionUrl($activityRow, $context);

            if (\str_starts_with($eventType, 'payment_')) {
                $paymentCount++;
            } elseif (\str_contains($eventType, 'cancellation')) {
                $cancellationCount++;
            } else {
                $reservationCount++;
            }

            $rows[] = [
                'created_at' => self::formatPortalDateTime((string) ($activityRow['created_at'] ?? '')),
                'severity' => (string) ($activityRow['severity'] ?? 'info'),
                'event_label' => self::formatAuditEventLabel($eventType),
                'actor_label' => self::formatAuditActorLabel($activityRow),
                'message' => (string) ($activityRow['message'] ?? ''),
                'action_url' => $actionUrl,
                'action_label' => self::buildFrontDeskLogActionLabel($entityType, $actionUrl),
            ];
        }

        return [
            'title' => \__('Desk Log', 'must-hotel-booking'),
            'description' => \__('Recent front-desk operations, using the same activity records already written by reservation and payment actions.', 'must-hotel-booking'),
            'empty_message' => \__('No front-desk activity has been logged yet.', 'must-hotel-booking'),
            'summary_cards' => [
                [
                    'label' => \__('Recent events', 'must-hotel-booking'),
                    'value' => (string) \count($rows),
                    'meta' => \__('Latest front-desk-facing activity entries.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Reservation actions', 'must-hotel-booking'),
                    'value' => (string) $reservationCount,
                    'meta' => \__('Bookings, check-ins, check-outs, and room moves.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Payment events', 'must-hotel-booking'),
                    'value' => (string) $paymentCount,
                    'meta' => \__('Operational payment entries included in the desk feed.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Cancellation decisions', 'must-hotel-booking'),
                    'value' => (string) $cancellationCount,
                    'meta' => \__('Requests and supervisor decisions relevant to the desk.', 'must-hotel-booking'),
                ],
            ],
            'rows' => $rows,
        ];
    }

    private static function buildFrontDeskLogActionLabel(string $entityType, string $actionUrl): string
    {
        if ($actionUrl === '') {
            return '';
        }

        if ($entityType === 'payment') {
            return \__('Open payment', 'must-hotel-booking');
        }

        if ($entityType === 'inventory_room') {
            return \__('Open room board', 'must-hotel-booking');
        }

        return \__('Open reservation', 'must-hotel-booking');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function decorateCalendarRows(array $rows, CalendarViewQuery $query): array
    {
        foreach ($rows as $rowIndex => $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $cells = isset($row['cells']) && \is_array($row['cells']) ? $row['cells'] : [];

            foreach ($cells as $cellIndex => $cell) {
                if (!\is_array($cell)) {
                    continue;
                }

                $date = isset($cell['date']) ? (string) $cell['date'] : '';
                $action = self::buildCalendarCellAction($roomId, $date, $cell, $query);
                $cell['action_url'] = (string) ($action['url'] ?? '');
                $cell['action_label'] = (string) ($action['label'] ?? '');
                $cell['focus_url'] = self::buildCalendarFocusUrl($query, $roomId, $date);
                $cells[$cellIndex] = $cell;
            }

            $row['cells'] = $cells;
            $rows[$rowIndex] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $actionState
     * @return array<string, mixed>
     */
    private static function decorateCalendarSelection(array $selected, CalendarViewQuery $query, array $actionState): array
    {
        $room = isset($selected['room']) && \is_array($selected['room']) ? $selected['room'] : null;
        $roomId = \is_array($room) && isset($room['id']) ? (int) $room['id'] : 0;
        $date = isset($selected['date']) ? (string) $selected['date'] : '';
        $canOpenReservations = StaffAccess::userCanAccessPortalModule('reservations');
        $canOpenRoomsAvailability = StaffAccess::userCanAccessPortalModule('rooms_availability');
        $canViewAvailability = $canOpenRoomsAvailability
            && (\current_user_can(StaffAccess::CAP_AVAILABILITY_RULES_VIEW) || \current_user_can(StaffAccess::CAP_ROOM_BLOCK_MANAGE) || \current_user_can('manage_options'));

        if ($roomId <= 0 || $date === '') {
            $selected['portal_actions'] = [];
            $selected['can_create_block'] = false;
            $selected['block_form'] = isset($actionState['block_form']) && \is_array($actionState['block_form']) ? $actionState['block_form'] : [];
            $selected['block_form_action'] = PortalRouter::getModuleUrl('calendar', $query->buildUrlArgs());

            return $selected;
        }

        foreach (['stays', 'arrivals', 'departures'] as $sectionKey) {
            $items = isset($selected[$sectionKey]) && \is_array($selected[$sectionKey]) ? $selected[$sectionKey] : [];

            foreach ($items as $itemIndex => $item) {
                if (!\is_array($item)) {
                    continue;
                }

                $status = \sanitize_key((string) ($item['status'] ?? ''));
                $itemDate = isset($item['checkin']) ? (string) $item['checkin'] : $date;
                $item['portal_url'] = '';
                $item['payment_url'] = !empty($item['payment_url']) ? self::mapAdminUrlToPortalUrl((string) $item['payment_url']) : '';

                if ($status === 'blocked' && $canViewAvailability) {
                    $item['portal_url'] = self::buildRoomsAvailabilityCalendarUrl('blocks', $roomId, $itemDate);
                } elseif (!empty($item['view_url'])) {
                    $item['portal_url'] = self::mapAdminUrlToPortalUrl((string) $item['view_url']);
                }

                $items[$itemIndex] = $item;
            }

            $selected[$sectionKey] = $items;
        }

        $rules = isset($selected['rules']) && \is_array($selected['rules']) ? $selected['rules'] : [];

        foreach ($rules as $ruleIndex => $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $rule['portal_url'] = $canViewAvailability ? self::buildRoomsAvailabilityCalendarUrl('rules', $roomId, $date) : '';
            $rules[$ruleIndex] = $rule;
        }

        $selected['rules'] = $rules;
        $selected['portal_actions'] = \array_values(
            \array_filter(
                [
                    [
                        'label' => \__('Reservations', 'must-hotel-booking'),
                        'url' => $canOpenReservations
                            ? PortalRouter::getModuleUrl(
                                'reservations',
                                [
                                    'room_id' => $roomId,
                                    'checkin_month' => \substr($date, 0, 7),
                                ]
                            )
                            : '',
                    ],
                    [
                        'label' => \__('Blocks', 'must-hotel-booking'),
                        'url' => $canViewAvailability ? self::buildRoomsAvailabilityCalendarUrl('blocks', $roomId, $date) : '',
                    ],
                    [
                        'label' => \__('Rules', 'must-hotel-booking'),
                        'url' => $canViewAvailability ? self::buildRoomsAvailabilityCalendarUrl('rules', $roomId, $date) : '',
                    ],
                    [
                        'label' => \__('Statuses', 'must-hotel-booking'),
                        'url' => $canOpenRoomsAvailability
                            ? PortalRouter::getModuleUrl('rooms_availability', ['tab' => 'statuses', 'room_type_id' => $roomId])
                            : '',
                    ],
                ],
                static function (array $action): bool {
                    return !empty($action['url']);
                }
            )
        );
        $selected['can_create_block'] = (\current_user_can(StaffAccess::CAP_CALENDAR_CREATE_BLOCK) || \current_user_can('manage_options'))
            && !empty($selected['actions']['can_create']);
        $selected['block_form'] = isset($actionState['block_form']) && \is_array($actionState['block_form'])
            ? $actionState['block_form']
            : (isset($selected['actions']['block_form']) && \is_array($selected['actions']['block_form']) ? $selected['actions']['block_form'] : []);
        $selected['block_form_action'] = PortalRouter::getModuleUrl(
            'calendar',
            $query->buildUrlArgs(
                [
                    'focus_room_id' => $roomId,
                    'focus_date' => $date,
                    'reservation_id' => 0,
                ]
            )
        );

        return $selected;
    }

    /**
     * @param array<string, mixed> $cell
     * @return array{url: string, label: string}
     */
    private static function buildCalendarCellAction(int $roomId, string $date, array $cell, CalendarViewQuery $query): array
    {
        $focusUrl = self::buildCalendarFocusUrl($query, $roomId, $date);
        $canOpenReservations = StaffAccess::userCanAccessPortalModule('reservations');
        $canOpenRoomsAvailability = StaffAccess::userCanAccessPortalModule('rooms_availability');
        $canViewAvailability = $canOpenRoomsAvailability
            && (\current_user_can(StaffAccess::CAP_AVAILABILITY_RULES_VIEW) || \current_user_can(StaffAccess::CAP_ROOM_BLOCK_MANAGE) || \current_user_can('manage_options'));
        $actualState = \sanitize_key((string) ($cell['actual_state'] ?? 'available'));
        $stayCount = isset($cell['stay_count']) ? (int) $cell['stay_count'] : 0;
        $reservationId = isset($cell['primary_reservation_id']) ? (int) $cell['primary_reservation_id'] : 0;
        $ruleCount = isset($cell['rule_count']) ? (int) $cell['rule_count'] : 0;
        $counts = isset($cell['counts']) && \is_array($cell['counts']) ? $cell['counts'] : [];
        $flags = isset($cell['flags']) && \is_array($cell['flags']) ? $cell['flags'] : [];

        if ($roomId <= 0 || $date === '') {
            return ['url' => '', 'label' => ''];
        }

        if (!empty($cell['hidden_state'])) {
            return [
                'url' => $focusUrl,
                'label' => \__('Inspect day', 'must-hotel-booking'),
            ];
        }

        if (\in_array($actualState, ['booked', 'partial', 'pending'], true) && $stayCount === 1 && $reservationId > 0 && $canOpenReservations) {
            return [
                'url' => PortalRouter::getModuleUrl('reservations', ['reservation_id' => $reservationId]),
                'label' => \__('Open reservation', 'must-hotel-booking'),
            ];
        }

        if ($actualState === 'blocked' && $canViewAvailability) {
            $targetTab = (!empty($counts['blocked'])) ? 'blocks' : 'rules';

            return [
                'url' => self::buildRoomsAvailabilityCalendarUrl($targetTab, $roomId, $date),
                'label' => $targetTab === 'blocks'
                    ? \__('Open blocks', 'must-hotel-booking')
                    : \__('Open rules', 'must-hotel-booking'),
            ];
        }

        if ($actualState === 'unavailable' && $canOpenRoomsAvailability) {
            if ($ruleCount > 0 || !empty($flags['maintenance_block']) || !empty($flags['closed_arrival']) || !empty($flags['closed_departure'])) {
                return [
                    'url' => self::buildRoomsAvailabilityCalendarUrl('rules', $roomId, $date),
                    'label' => \__('Open rules', 'must-hotel-booking'),
                ];
            }

            if (!empty($flags['inventory_unavailable'])) {
                return [
                    'url' => PortalRouter::getModuleUrl('rooms_availability', ['tab' => 'statuses', 'room_type_id' => $roomId]),
                    'label' => \__('Open statuses', 'must-hotel-booking'),
                ];
            }
        }

        return [
            'url' => $focusUrl,
            'label' => \__('Inspect day', 'must-hotel-booking'),
        ];
    }

    private static function buildCalendarFocusUrl(CalendarViewQuery $query, int $roomId, string $date): string
    {
        return PortalRouter::getModuleUrl(
            'calendar',
            $query->buildUrlArgs(
                [
                    'focus_room_id' => $roomId,
                    'focus_date' => $date,
                    'reservation_id' => 0,
                ]
            )
        );
    }

    private static function buildRoomsAvailabilityCalendarUrl(string $tab, int $roomId, string $date): string
    {
        if ($tab === 'statuses') {
            return PortalRouter::getModuleUrl('rooms_availability', ['tab' => 'statuses', 'room_type_id' => $roomId]);
        }

        $args = [
            'tab' => $tab,
            'room_id' => $roomId,
            'timeline' => self::resolveCalendarTimeline($date),
            'start_date' => $date,
            'end_date' => $date,
        ];

        if ($tab === 'blocks') {
            $args['mode'] = 'blocked';
        } elseif ($tab === 'rules') {
            $args['mode'] = 'restriction';
        }

        return PortalRouter::getModuleUrl('rooms_availability', $args);
    }

    private static function resolveCalendarTimeline(string $date): string
    {
        $today = \current_time('Y-m-d');

        if ($date < $today) {
            return 'past';
        }

        if ($date > $today) {
            return 'future';
        }

        return 'current';
    }

    /**
     * @param array<string, mixed> $actionState
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $defaultOverrides
     * @param array<string, mixed> $presentation
     * @return array<string, mixed>
     */
    private static function prepareQuickBookingData(array $actionState, array $defaultOverrides = [], array $presentation = []): array
    {
        $defaults = \MustHotelBooking\Admin\get_admin_quick_booking_form_defaults();
        $sourceOptions = \MustHotelBooking\Admin\get_admin_quick_booking_source_options();
        $resolvedDefaults = \array_replace($defaults, $defaultOverrides);
        $form = isset($actionState['form']) && \is_array($actionState['form']) ? $actionState['form'] : $resolvedDefaults;
        $estimate = 0.0;

        if (!empty($form['room_id']) && !empty($form['checkin']) && !empty($form['checkout']) && !empty($form['guests'])) {
            $estimate = \MustHotelBooking\Admin\get_admin_quick_booking_total_price(
                (int) $form['room_id'],
                (string) $form['checkin'],
                (string) $form['checkout'],
                (int) $form['guests']
            );
        }

        return [
            'form' => $form,
            'room_options' => \MustHotelBooking\Admin\get_admin_quick_booking_rooms(),
            'source_options' => $sourceOptions,
            'estimate' => $estimate,
            'currency' => MustBookingConfig::get_currency(),
            'form_title' => (string) ($presentation['form_title'] ?? \__('New Booking', 'must-hotel-booking')),
            'form_description' => (string) ($presentation['form_description'] ?? \__('Create a confirmed reservation from the Front Desk workspace without leaving the portal.', 'must-hotel-booking')),
            'submit_label' => (string) ($presentation['submit_label'] ?? \__('Create reservation', 'must-hotel-booking')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareGuestsData(array $actionState = []): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = GuestAdminQuery::fromRequest($request);

        return (new GuestAdminDataProvider())->getPageData(
            $query,
            [
                'errors' => isset($actionState['errors']) && \is_array($actionState['errors']) ? $actionState['errors'] : [],
                'form' => isset($actionState['form']) && \is_array($actionState['form']) ? $actionState['form'] : null,
                'selected_guest_id' => isset($actionState['selected_guest_id']) ? (int) $actionState['selected_guest_id'] : $query->getGuestId(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function preparePaymentsData(array $actionState = []): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = PaymentAdminQuery::fromRequest($request);

        return (new PaymentAdminDataProvider())->getPageData(
            $query,
            [
                'errors' => isset($actionState['errors']) && \is_array($actionState['errors']) ? $actionState['errors'] : [],
                'forms' => isset($actionState['forms']) && \is_array($actionState['forms']) ? $actionState['forms'] : [],
                'settings_errors' => [],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleCalendarCreateBlock(): array
    {
        $nonce = isset($_POST['must_portal_calendar_nonce']) ? (string) \wp_unslash($_POST['must_portal_calendar_nonce']) : '';
        $roomId = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
        $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
        $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
        $queryState = [
            'focus_room_id' => $roomId,
            'focus_date' => $checkin,
            'reservation_id' => 0,
        ];

        if (!\wp_verify_nonce($nonce, 'must_portal_calendar_block_dates')) {
            self::redirectToPortalCalendar($queryState, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_CALENDAR_CREATE_BLOCK) && !\current_user_can('manage_options')) {
            self::redirectToPortalCalendar($queryState, 'access_denied');
        }

        $errors = [];
        $room = $roomId > 0 ? \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId) : null;

        if (!\is_array($room)) {
            $errors[] = \__('Please select a valid accommodation.', 'must-hotel-booking');
        }

        if (!\MustHotelBooking\Engine\AvailabilityEngine::isValidBookingDate($checkin) || !\MustHotelBooking\Engine\AvailabilityEngine::isValidBookingDate($checkout)) {
            $errors[] = \__('Please provide valid dates.', 'must-hotel-booking');
        } elseif ($checkin >= $checkout) {
            $errors[] = \__('The block end date must be after the start date.', 'must-hotel-booking');
        }

        if (empty($errors) && !\MustHotelBooking\Engine\AvailabilityEngine::checkAvailability($roomId, $checkin, $checkout)) {
            $errors[] = \__('This accommodation is already unavailable for the selected dates.', 'must-hotel-booking');
        }

        $form = [
            'room_id' => $roomId,
            'checkin' => $checkin,
            'checkout' => $checkout,
        ];

        if (!empty($errors)) {
            return [
                'module_key' => 'calendar',
                'errors' => $errors,
                'block_form' => $form,
                'calendar_query' => $queryState,
            ];
        }

        $reservationId = \MustHotelBooking\Engine\get_reservation_repository()->createBlockedReservation(
            $roomId,
            $checkin,
            $checkout,
            \current_time('mysql')
        );

        if ($reservationId <= 0) {
            return [
                'module_key' => 'calendar',
                'errors' => [\__('Unable to create the manual block right now.', 'must-hotel-booking')],
                'block_form' => $form,
                'calendar_query' => $queryState,
            ];
        }

        if (\is_array($room)) {
            self::logCalendarBlockActivity($reservationId, $roomId, $room, $checkin, $checkout);
        }

        self::redirectToPortalCalendar(
            [
                'focus_room_id' => $roomId,
                'focus_date' => $checkin,
                'reservation_id' => $reservationId,
            ],
            'calendar_block_created'
        );
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private static function logReservationActivity(
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

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
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
                    ]
                ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $guest
     */
    private static function logGuestActivity(
        int $guestId,
        array $guest,
        string $eventType,
        string $severity,
        string $message
    ): void {
        $fullName = \trim(
            (isset($guest['first_name']) ? (string) $guest['first_name'] : '') . ' ' .
            (isset($guest['last_name']) ? (string) $guest['last_name'] : '')
        );
        $reference = $fullName !== ''
            ? $fullName
            : (\trim((string) ($guest['email'] ?? '')) !== '' ? (string) $guest['email'] : ('GUEST-' . $guestId));
        $actorUserId = \get_current_user_id();
        $actorRole = '';
        $actorIp = '';

        if ($actorUserId > 0) {
            $actorUser = \wp_get_current_user();

            if ($actorUser instanceof \WP_User && !empty($actorUser->roles)) {
                $actorRole = (string) \reset($actorUser->roles);
            }
        }

        if (isset($_SERVER['REMOTE_ADDR'])) {
            $actorIp = \sanitize_text_field(\wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => $eventType,
                'severity' => $severity,
                'entity_type' => 'guest',
                'entity_id' => $guestId,
                'reference' => $reference,
                'message' => $message,
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'actor_ip' => $actorIp,
                'context_json' => \wp_json_encode(
                    [
                        'guest_id' => $guestId,
                        'guest_email' => isset($guest['email']) ? (string) $guest['email'] : '',
                        'actor_user_id' => $actorUserId,
                    ]
                ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $room
     */
    private static function logCalendarBlockActivity(int $reservationId, int $roomId, array $room, string $checkin, string $checkout): void
    {
        $actorUserId = \get_current_user_id();
        $actorRole = '';
        $actorIp = '';
        $roomName = \trim((string) ($room['name'] ?? ''));
        $reference = $roomName !== '' ? $roomName : ('ROOM-' . $roomId);

        if ($actorUserId > 0) {
            $actorUser = \wp_get_current_user();

            if ($actorUser instanceof \WP_User && !empty($actorUser->roles)) {
                $actorRole = (string) \reset($actorUser->roles);
            }
        }

        if (isset($_SERVER['REMOTE_ADDR'])) {
            $actorIp = \sanitize_text_field(\wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => 'calendar_block_created',
                'severity' => 'warning',
                'entity_type' => 'reservation',
                'entity_id' => $reservationId,
                'reference' => $reference,
                'message' => \sprintf(
                    /* translators: 1: room reference, 2: start date, 3: end date */
                    \__('Manual block created for %1$s from %2$s to %3$s.', 'must-hotel-booking'),
                    $reference,
                    $checkin,
                    $checkout
                ),
                'actor_user_id' => $actorUserId,
                'actor_role' => $actorRole,
                'actor_ip' => $actorIp,
                'context_json' => \wp_json_encode(
                    [
                        'reservation_id' => $reservationId,
                        'room_id' => $roomId,
                        'room_name' => $roomName,
                        'checkin' => $checkin,
                        'checkout' => $checkout,
                        'actor_user_id' => $actorUserId,
                    ]
                ),
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Check-in
    // -------------------------------------------------------------------------

    private static function handleReservationCheckin(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce         = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_action_checkin_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_CHECKIN) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation           = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $status      = \sanitize_key((string) ($reservation['status'] ?? ''));
        $checkedInAt = \trim((string) ($reservation['checked_in_at'] ?? ''));

        // Only check in if the reservation is active and not already checked in.
        if (!\in_array($status, ['confirmed', 'pending', 'pending_payment'], true)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        if ($checkedInAt !== '' && $checkedInAt !== '0000-00-00 00:00:00') {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_already_checked_in');
        }

        $reservationRepository->updateReservation($reservationId, ['checked_in_at' => \current_time('mysql')]);

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'reservation_checked_in',
            'info',
            \__('Guest checked in.', 'must-hotel-booking')
        );

        self::redirectToPortalReservationDetail($reservationId, 'reservation_checked_in');
    }

    // -------------------------------------------------------------------------
    // Check-out
    // -------------------------------------------------------------------------

    private static function handleReservationCheckout(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce         = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_action_checkout_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_CHECKOUT) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation           = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $status        = \sanitize_key((string) ($reservation['status'] ?? ''));
        $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? 'unpaid'));
        $checkedInAt   = \trim((string) ($reservation['checked_in_at'] ?? ''));
        $checkedOutAt  = \trim((string) ($reservation['checked_out_at'] ?? ''));

        if ($status !== 'confirmed') {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        if ($checkedInAt === '' || $checkedInAt === '0000-00-00 00:00:00') {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        if ($checkedOutAt !== '' && $checkedOutAt !== '0000-00-00 00:00:00') {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        $reservationRepository->updateReservation($reservationId, ['checked_out_at' => \current_time('mysql')]);
        BookingStatusEngine::updateReservationStatuses([$reservationId], 'completed', $paymentStatus);

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'reservation_checked_out',
            'info',
            \__('Guest checked out.', 'must-hotel-booking')
        );

        self::redirectToPortalReservationDetail($reservationId, 'reservation_checked_out');
    }

    // -------------------------------------------------------------------------
    // Assign / reassign room
    // -------------------------------------------------------------------------

    private static function handleReservationAssignRoom(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce         = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';
        $roomMoveRequest = self::isFrontDeskRoomMoveRequest();

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_assign_room_' . $reservationId)) {
            self::redirectAfterReservationAssignRoom($reservationId, 'invalid_nonce');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation           = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectAfterReservationAssignRoom($reservationId, 'reservation_not_found');
        }

        $status = \sanitize_key((string) ($reservation['status'] ?? ''));
        $checkedInAt = \trim((string) ($reservation['checked_in_at'] ?? ''));
        $checkedOutAt = \trim((string) ($reservation['checked_out_at'] ?? ''));
        $isCheckedIn = $checkedInAt !== '' && $checkedInAt !== '0000-00-00 00:00:00';
        $isCheckedOut = $checkedOutAt !== '' && $checkedOutAt !== '0000-00-00 00:00:00';
        $requiresMoveCapability = $isCheckedIn && !$isCheckedOut;

        $requiredCapability = $requiresMoveCapability
            ? StaffAccess::CAP_RESERVATION_MOVE_ROOM
            : StaffAccess::CAP_RESERVATION_ASSIGN_ROOM;

        if (!\current_user_can($requiredCapability) && !\current_user_can('manage_options')) {
            self::redirectAfterReservationAssignRoom($reservationId, 'access_denied');
        }

        if (\in_array($status, ['cancelled', 'completed', 'blocked'], true)) {
            self::redirectAfterReservationAssignRoom($reservationId, 'reservation_wrong_status');
        }

        if (
            $roomMoveRequest
            && !$requiresMoveCapability
        ) {
            self::redirectAfterReservationAssignRoom($reservationId, 'reservation_wrong_status');
        }

        if (
            $requiresMoveCapability
            && $isCheckedOut
        ) {
            self::redirectAfterReservationAssignRoom($reservationId, 'reservation_wrong_status');
        }

        // 0 = unassign; any positive integer = assign to that inventory room.
        $newRoomId            = isset($_POST['assigned_room_id']) ? \absint(\wp_unslash($_POST['assigned_room_id'])) : 0;
        $roomTypeId           = isset($reservation['room_type_id']) ? (int) $reservation['room_type_id'] : (int) ($reservation['room_id'] ?? 0);
        $currentAssignedRoomId = isset($reservation['assigned_room_id']) ? (int) $reservation['assigned_room_id'] : 0;
        $inventoryRepository  = \MustHotelBooking\Engine\get_inventory_repository();
        $roomLabel            = \__('Unassigned', 'must-hotel-booking');
        $updated              = false;

        if ($requiresMoveCapability && $newRoomId <= 0) {
            self::redirectAfterReservationAssignRoom($reservationId, 'portal_action_failed');
        }

        if ($newRoomId > 0) {
            $inventoryRoom = $inventoryRepository->getInventoryRoomById($newRoomId);

            if (!\is_array($inventoryRoom)) {
                self::redirectAfterReservationAssignRoom($reservationId, 'portal_action_failed');
            }

            $inventoryRoomTypeId = isset($inventoryRoom['room_type_id']) ? (int) $inventoryRoom['room_type_id'] : 0;

            if ($roomTypeId > 0 && $inventoryRoomTypeId > 0 && $inventoryRoomTypeId !== $roomTypeId) {
                self::redirectAfterReservationAssignRoom($reservationId, 'portal_action_failed');
            }

            if ($newRoomId !== $currentAssignedRoomId) {
                $today = \current_time('Y-m-d');
                $effectiveCheckin = isset($reservation['checkin']) && (string) $reservation['checkin'] > $today
                    ? (string) $reservation['checkin']
                    : $today;
                $effectiveCheckout = isset($reservation['checkout']) ? (string) $reservation['checkout'] : '';

                if ($effectiveCheckout === '' || $effectiveCheckout <= $effectiveCheckin) {
                    $timestamp = \strtotime($effectiveCheckin . ' +1 day');
                    $effectiveCheckout = $timestamp !== false ? \wp_date('Y-m-d', $timestamp) : $effectiveCheckin;
                }

                $availableRooms = $inventoryRepository->getAvailableRooms(
                    $roomTypeId,
                    $effectiveCheckin,
                    $effectiveCheckout,
                    ['cancelled', 'expired', 'payment_failed'],
                    \current_time('mysql')
                );
                $availableRoomIds = [];

                foreach ($availableRooms as $availableRoom) {
                    if (!\is_array($availableRoom)) {
                        continue;
                    }

                    $availableRoomIds[] = isset($availableRoom['id']) ? (int) $availableRoom['id'] : 0;
                }

                if (!\in_array($newRoomId, $availableRoomIds, true)) {
                    self::redirectAfterReservationAssignRoom($reservationId, 'portal_action_failed');
                }
            }

            $roomLabel = \trim((string) ($inventoryRoom['room_number'] ?? ''));

            if ($roomLabel === '') {
                $roomLabel = \trim((string) ($inventoryRoom['title'] ?? ''));
            }

            if ($roomLabel === '') {
                $roomLabel = (string) $newRoomId;
            }

            $updated = $inventoryRepository->assignRoomToReservation($newRoomId, $reservationId, $roomTypeId);
        } else {
            $updated = $reservationRepository->updateReservation($reservationId, ['assigned_room_id' => 0]);
        }

        if (!$updated) {
            self::redirectAfterReservationAssignRoom($reservationId, 'portal_action_failed');
        }

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'room_assigned',
            'info',
            \sprintf(
                /* translators: %s: room label */
                \__('Room assignment updated: %s.', 'must-hotel-booking'),
                $roomLabel
            )
        );

        self::redirectAfterReservationAssignRoom($reservationId, 'reservation_room_assigned');
    }

    // -------------------------------------------------------------------------
    // Stay change
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function handleReservationUpdateStay(): array
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_update_stay_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_EDIT_STAY) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $status = \sanitize_key((string) ($reservation['status'] ?? ''));
        $currentCheckin = (string) ($reservation['checkin'] ?? '');
        $currentCheckout = (string) ($reservation['checkout'] ?? '');
        $checkedInAt = \trim((string) ($reservation['checked_in_at'] ?? ''));
        $checkedOutAt = \trim((string) ($reservation['checked_out_at'] ?? ''));
        $form = [
            'checkin' => isset($_POST['stay_checkin'])
                ? \sanitize_text_field((string) \wp_unslash($_POST['stay_checkin']))
                : $currentCheckin,
            'checkout' => isset($_POST['stay_checkout'])
                ? \sanitize_text_field((string) \wp_unslash($_POST['stay_checkout']))
                : $currentCheckout,
        ];
        $errors = [];

        if (\in_array($status, ['cancelled', 'completed', 'blocked', 'expired', 'payment_failed'], true)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        if ($checkedOutAt !== '' && $checkedOutAt !== '0000-00-00 00:00:00') {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        if (!\MustHotelBooking\Engine\AvailabilityEngine::isValidBookingDate($form['checkin'])) {
            $errors[] = \__('Enter a valid check-in date.', 'must-hotel-booking');
        }

        if (!\MustHotelBooking\Engine\AvailabilityEngine::isValidBookingDate($form['checkout'])) {
            $errors[] = \__('Enter a valid check-out date.', 'must-hotel-booking');
        }

        if (empty($errors) && $form['checkout'] <= $form['checkin']) {
            $errors[] = \__('Check-out must be later than check-in.', 'must-hotel-booking');
        }

        if (
            empty($errors)
            && $checkedInAt !== ''
            && $checkedInAt !== '0000-00-00 00:00:00'
            && $form['checkin'] !== $currentCheckin
        ) {
            $errors[] = \__('Check-in date cannot be changed after the guest has already checked in.', 'must-hotel-booking');
        }

        $roomTypeId = isset($reservation['room_type_id']) ? (int) $reservation['room_type_id'] : (int) ($reservation['room_id'] ?? 0);
        $stayAvailability = [
            'clear_assigned_room' => false,
            'errors' => [],
        ];

        if (empty($errors) && $roomTypeId <= 0) {
            $errors[] = \__('This reservation is missing its accommodation context, so stay dates cannot be updated safely.', 'must-hotel-booking');
        }

        if (
            empty($errors)
            && !\MustHotelBooking\Engine\AvailabilityEngine::checkBookingRestrictions($roomTypeId, $form['checkin'], $form['checkout'])
        ) {
            $errors[] = \__('The selected stay dates are blocked by the current booking rules for this accommodation.', 'must-hotel-booking');
        }

        if (empty($errors)) {
            $stayAvailability = self::validateReservationStayAvailability(
                $reservationId,
                $reservation,
                $roomTypeId,
                $form['checkin'],
                $form['checkout']
            );
            $errors = \array_merge($errors, isset($stayAvailability['errors']) && \is_array($stayAvailability['errors']) ? $stayAvailability['errors'] : []);
        }

        $pricing = null;
        $newTotalPrice = isset($reservation['total_price']) ? (float) $reservation['total_price'] : 0.0;
        $newCouponId = isset($reservation['coupon_id']) ? (int) $reservation['coupon_id'] : 0;
        $newCouponCode = (string) ($reservation['coupon_code'] ?? '');
        $newCouponDiscountTotal = isset($reservation['coupon_discount_total']) ? (float) $reservation['coupon_discount_total'] : 0.0;

        if (empty($errors)) {
            $pricing = \MustHotelBooking\Engine\PricingEngine::calculateTotal(
                $roomTypeId,
                $form['checkin'],
                $form['checkout'],
                \max(1, (int) ($reservation['guests'] ?? 1)),
                (string) ($reservation['coupon_code'] ?? ''),
                (int) ($reservation['rate_plan_id'] ?? 0)
            );

            if (!\is_array($pricing) || empty($pricing['success']) || !isset($pricing['total_price'])) {
                $errors[] = isset($pricing['message']) && \is_string($pricing['message']) && $pricing['message'] !== ''
                    ? (string) $pricing['message']
                    : \__('Unable to recalculate pricing for the updated stay dates.', 'must-hotel-booking');
            } else {
                $newTotalPrice = (float) $pricing['total_price'];
                $newCouponId = isset($pricing['applied_coupon_id']) ? (int) $pricing['applied_coupon_id'] : 0;
                $newCouponCode = isset($pricing['applied_coupon']) ? (string) $pricing['applied_coupon'] : '';
                $newCouponDiscountTotal = isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0;
            }
        }

        if (!empty($errors)) {
            return self::buildReservationActionState(
                $reservationId,
                ['stay' => $form],
                $errors
            );
        }

        $updatePayload = [
            'checkin' => $form['checkin'],
            'checkout' => $form['checkout'],
            'total_price' => $newTotalPrice,
            'coupon_id' => $newCouponId,
            'coupon_code' => $newCouponCode,
            'coupon_discount_total' => $newCouponDiscountTotal,
        ];
        $assignedRoomWasCleared = !empty($stayAvailability['clear_assigned_room']);

        if ($assignedRoomWasCleared) {
            $updatePayload['assigned_room_id'] = 0;
        }

        $updated = $reservationRepository->updateReservation($reservationId, $updatePayload);

        if (!$updated) {
            return self::buildReservationActionState(
                $reservationId,
                ['stay' => $form],
                [\__('Unable to save the updated stay dates.', 'must-hotel-booking')]
            );
        }

        $message = \sprintf(
            /* translators: 1: previous check-in, 2: previous check-out, 3: updated check-in, 4: updated check-out */
            \__('Stay updated from %1$s - %2$s to %3$s - %4$s.', 'must-hotel-booking'),
            $currentCheckin,
            $currentCheckout,
            $form['checkin'],
            $form['checkout']
        );

        if ($assignedRoomWasCleared) {
            $message .= ' ' . \__('Assigned room cleared because the updated stay window no longer fits the current room assignment.', 'must-hotel-booking');
        }

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'reservation_stay_updated',
            'info',
            $message
        );

        self::redirectToPortalReservationDetail(
            $reservationId,
            $assignedRoomWasCleared ? 'reservation_stay_updated_room_unassigned' : 'reservation_stay_updated'
        );
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array<string, mixed>
     */
    private static function validateReservationStayAvailability(
        int $reservationId,
        array $reservation,
        int $roomTypeId,
        string $checkin,
        string $checkout
    ): array {
        $nonBlockingStatuses = \MustHotelBooking\Core\ReservationStatus::getInventoryNonBlockingStatuses();
        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();

        if (!\MustHotelBooking\Engine\InventoryEngine::hasInventoryForRoomType($roomTypeId)) {
            if ($reservationRepository->hasReservationOverlapExcludingId($reservationId, $roomTypeId, $checkin, $checkout, $nonBlockingStatuses)) {
                return [
                    'clear_assigned_room' => false,
                    'errors' => [\__('The selected stay dates are not available for this accommodation.', 'must-hotel-booking')],
                ];
            }

            return [
                'clear_assigned_room' => false,
                'errors' => [],
            ];
        }

        $inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $availableRooms = $inventoryRepository->getAvailableRooms(
            $roomTypeId,
            $checkin,
            $checkout,
            $nonBlockingStatuses,
            \current_time('mysql')
        );
        $availableRoomIds = [];

        foreach ($availableRooms as $availableRoom) {
            if (!\is_array($availableRoom) || !isset($availableRoom['id'])) {
                continue;
            }

            $availableRoomIds[] = (int) $availableRoom['id'];
        }

        $assignedRoomId = isset($reservation['assigned_room_id']) ? (int) $reservation['assigned_room_id'] : 0;
        $selfOverlapsUpdatedWindow = self::reservationDatesOverlap(
            (string) ($reservation['checkin'] ?? ''),
            (string) ($reservation['checkout'] ?? ''),
            $checkin,
            $checkout
        ) && \MustHotelBooking\Core\ReservationStatus::blocksInventory((string) ($reservation['status'] ?? ''));
        $unassignedOverlapCount = $inventoryRepository->countUnassignedTypeReservationOverlaps(
            $roomTypeId,
            $checkin,
            $checkout,
            $nonBlockingStatuses
        );

        if ($selfOverlapsUpdatedWindow && $assignedRoomId <= 0) {
            $unassignedOverlapCount = \max(0, $unassignedOverlapCount - 1);
        }

        $availableCount = \max(0, \count($availableRoomIds) - $unassignedOverlapCount);
        $canKeepAssignedRoom = false;

        if ($assignedRoomId > 0) {
            if (\in_array($assignedRoomId, $availableRoomIds, true)) {
                $canKeepAssignedRoom = true;
            } elseif (
                $selfOverlapsUpdatedWindow
                && !$reservationRepository->hasAssignedRoomOverlapExcludingId($reservationId, $assignedRoomId, $checkin, $checkout, $nonBlockingStatuses)
            ) {
                $canKeepAssignedRoom = true;
            }
        }

        if (!$canKeepAssignedRoom && $availableCount <= 0) {
            return [
                'clear_assigned_room' => false,
                'errors' => [\__('The selected stay dates are not available for this accommodation.', 'must-hotel-booking')],
            ];
        }

        return [
            'clear_assigned_room' => $assignedRoomId > 0 && !$canKeepAssignedRoom,
            'errors' => [],
        ];
    }

    private static function reservationDatesOverlap(string $firstCheckin, string $firstCheckout, string $secondCheckin, string $secondCheckout): bool
    {
        if ($firstCheckin === '' || $firstCheckout === '' || $secondCheckin === '' || $secondCheckout === '') {
            return false;
        }

        return $firstCheckin < $secondCheckout && $firstCheckout > $secondCheckin;
    }

    // -------------------------------------------------------------------------
    // No-show
    // -------------------------------------------------------------------------

    private static function handleReservationMarkNoShow(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_no_show_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_NO_SHOW) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        if (!self::canReservationBeMarkedNoShow($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        BookingStatusEngine::updateReservationStatuses(
            [$reservationId],
            'cancelled',
            \sanitize_key((string) ($reservation['payment_status'] ?? 'unpaid'))
        );
        self::clearReservationCancellationRequest($reservationId);
        self::logReservationActivity(
            $reservationId,
            $reservation,
            'reservation_marked_no_show',
            'warning',
            \sprintf(
                /* translators: %s: staff member display name */
                \__('Reservation marked as no-show by %s. Reservation cancelled.', 'must-hotel-booking'),
                self::getCurrentPortalActorName()
            )
        );

        self::redirectToPortalReservationDetail($reservationId, 'reservation_marked_no_show');
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private static function canReservationBeMarkedNoShow(array $reservation): bool
    {
        $status = \sanitize_key((string) ($reservation['status'] ?? ''));
        $checkedInAt = \trim((string) ($reservation['checked_in_at'] ?? ''));
        $checkedOutAt = \trim((string) ($reservation['checked_out_at'] ?? ''));
        $checkinDate = (string) ($reservation['checkin'] ?? '');
        $today = \current_time('Y-m-d');

        if (!\in_array($status, ['pending', 'pending_payment', 'confirmed'], true)) {
            return false;
        }

        if ($checkinDate === '' || $checkinDate > $today) {
            return false;
        }

        if ($checkedInAt !== '' && $checkedInAt !== '0000-00-00 00:00:00') {
            return false;
        }

        return $checkedOutAt === '' || $checkedOutAt === '0000-00-00 00:00:00';
    }

    // -------------------------------------------------------------------------
    // Add internal note
    // -------------------------------------------------------------------------

    private static function handleReservationAddNote(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce         = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_add_note_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_GUEST_ADD_NOTE) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $note = isset($_POST['internal_note']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['internal_note'])) : '';
        $note = \trim($note);

        if ($note === '') {
            self::redirectToPortalReservationDetail($reservationId, 'portal_action_failed');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation           = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $currentUser  = \wp_get_current_user();
        $authorName   = $currentUser instanceof \WP_User && $currentUser->display_name !== '' ? (string) $currentUser->display_name : \__('Staff', 'must-hotel-booking');
        $timestamp    = \date_i18n('d M Y H:i', \current_time('timestamp'));
        $noteEntry    = '[' . $timestamp . ' — ' . $authorName . '] ' . $note;
        $existing     = \trim((string) ($reservation['notes'] ?? ''));
        $noteEntry    = '[' . $timestamp . ' - ' . $authorName . '] ' . $note;
        $updatedNotes = $existing !== '' ? $existing . "\n\n" . $noteEntry : $noteEntry;

        $reservationRepository->updateReservation($reservationId, ['notes' => $updatedNotes]);

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'note_added',
            'info',
            \sprintf(
                /* translators: %s: staff member display name */
                \__('Internal note added by %s.', 'must-hotel-booking'),
                $authorName
            )
        );

        self::redirectToPortalReservationDetail($reservationId, 'reservation_note_added');
    }

    // -------------------------------------------------------------------------
    // Cancellation request (Front Desk → approval queue)
    // -------------------------------------------------------------------------

    private static function currentUserCanRequestReservationCancellation(): bool
    {
        if (\current_user_can('manage_options')) {
            return true;
        }

        return \current_user_can(StaffAccess::CAP_RESERVATION_EDIT_BASIC)
            && !\current_user_can(StaffAccess::CAP_RESERVATION_CANCEL);
    }

    private static function handleReservationRequestCancel(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce         = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_request_cancel_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!self::currentUserCanRequestReservationCancellation()) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation           = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $status = \sanitize_key((string) ($reservation['status'] ?? ''));

        if (\in_array($status, ['cancelled', 'completed', 'blocked'], true)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        if (!empty($reservation['cancellation_requested'])) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        $reservationRepository->updateReservation(
            $reservationId,
            [
                'cancellation_requested'    => 1,
                'cancellation_requested_at' => \current_time('mysql'),
                'cancellation_requested_by' => \get_current_user_id(),
            ]
        );

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'cancellation_requested',
            'warning',
            \sprintf(
                /* translators: %s: staff member display name */
                \__('Cancellation requested by %s. Pending supervisor approval.', 'must-hotel-booking'),
                self::getCurrentPortalActorName()
            )
        );

        self::redirectToPortalReservationDetail($reservationId, 'cancellation_request_submitted');
    }

    // -------------------------------------------------------------------------
    // Reject cancellation request (Supervisor)
    // -------------------------------------------------------------------------

    private static function handleReservationRejectCancel(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce         = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_reject_cancel_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_CANCEL) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation           = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        if (empty($reservation['cancellation_requested'])) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        self::clearReservationCancellationRequest($reservationId);

        self::logReservationActivity(
            $reservationId,
            $reservation,
            'cancellation_request_rejected',
            'info',
            \sprintf(
                /* translators: %s: staff member display name */
                \__('Cancellation request rejected by %s.', 'must-hotel-booking'),
                self::getCurrentPortalActorName()
            )
        );

        self::redirectToPortalReservationDetail($reservationId, 'cancellation_request_rejected');
    }

    private static function handleReservationApproveCancel(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce         = isset($_POST['must_portal_reservation_nonce']) ? (string) \wp_unslash($_POST['must_portal_reservation_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_reservation_approve_cancel_' . $reservationId)) {
            self::redirectToPortalReservationDetail($reservationId, 'invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_RESERVATION_CANCEL) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation           = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        if (!self::approveReservationCancellationRequest($reservationId, $reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_wrong_status');
        }

        self::redirectToPortalReservationDetail($reservationId, 'cancellation_request_approved');
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private static function approveReservationCancellationRequest(int $reservationId, array $reservation): bool
    {
        $status = \sanitize_key((string) ($reservation['status'] ?? ''));

        if (empty($reservation['cancellation_requested']) || \in_array($status, ['cancelled', 'blocked', 'completed'], true)) {
            return false;
        }

        $paymentStatus = \sanitize_key((string) ($reservation['payment_status'] ?? ''));

        BookingStatusEngine::updateReservationStatuses([$reservationId], 'cancelled', $paymentStatus);
        self::clearReservationCancellationRequest($reservationId);
        self::logReservationActivity(
            $reservationId,
            $reservation,
            'cancellation_request_approved',
            'warning',
            \sprintf(
                /* translators: %s: staff member display name */
                \__('Cancellation request approved by %s. Reservation cancelled.', 'must-hotel-booking'),
                self::getCurrentPortalActorName()
            )
        );

        return true;
    }

    private static function clearReservationCancellationRequest(int $reservationId): void
    {
        \MustHotelBooking\Engine\get_reservation_repository()->updateReservation(
            $reservationId,
            [
                'cancellation_requested'    => 0,
                'cancellation_requested_at' => '',
                'cancellation_requested_by' => 0,
            ]
        );
    }

    private static function getCurrentPortalActorName(): string
    {
        $currentUser = \wp_get_current_user();

        return $currentUser instanceof \WP_User && $currentUser->display_name !== ''
            ? (string) $currentUser->display_name
            : \__('Staff', 'must-hotel-booking');
    }

    /**
     * @return array<string, scalar>
     */
    private static function getPortalReservationRedirectArgs(int $reservationId): array
    {
        $args = [];

        if ($reservationId > 0) {
            $args['reservation_id'] = $reservationId;
        }

        $queryKeys = [
            'search' => 'text',
            'status' => 'key',
            'payment_status' => 'key',
            'payment_method' => 'key',
            'checkin_from' => 'text',
            'checkin_to' => 'text',
            'checkin_month' => 'text',
            'quick_filter' => 'key',
            'room_id' => 'absint',
            'per_page' => 'int',
            'paged' => 'int',
        ];

        foreach ($queryKeys as $key => $type) {
            if (!isset($_GET[$key])) {
                continue;
            }

            $rawValue = \wp_unslash($_GET[$key]);

            if (\is_array($rawValue)) {
                continue;
            }

            if (
                !self::currentUserCanViewFinanceContext()
                && (
                    $key === 'payment_status'
                    || $key === 'payment_method'
                    || ($key === 'quick_filter' && \in_array(\sanitize_key((string) $rawValue), ['unpaid', 'paid', 'failed_payment'], true))
                )
            ) {
                continue;
            }

            if ($type === 'absint') {
                $value = \absint($rawValue);
            } elseif ($type === 'int') {
                $value = (int) $rawValue;
            } elseif ($type === 'key') {
                $value = \sanitize_key((string) $rawValue);
            } else {
                $value = \sanitize_text_field((string) $rawValue);
            }

            if ($value === '' || $value === 0) {
                continue;
            }

            $args[$key] = $value;
        }

        $mode = isset($_POST['portal_return_mode'])
            ? \sanitize_key((string) \wp_unslash($_POST['portal_return_mode']))
            : (isset($_GET['mode']) ? \sanitize_key((string) \wp_unslash($_GET['mode'])) : '');

        if ($mode === 'edit') {
            $args['mode'] = 'edit';
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getPortalCalendarRedirectArgs(array $overrides = []): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = CalendarViewQuery::fromRequest($request);

        return $query->buildUrlArgs($overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function redirectToPortalCalendar(array $overrides = [], string $notice = ''): void
    {
        $args = self::getPortalCalendarRedirectArgs($overrides);

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('calendar', $args));
        exit;
    }

    private static function redirectToPortalReservationDetail(int $reservationId, string $notice): void
    {
        $args = self::getPortalReservationRedirectArgs($reservationId);

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('reservations', $args));
        exit;
    }

    private static function redirectAfterReservationAssignRoom(int $reservationId, string $notice): void
    {
        self::maybeRedirectToRequestedFrontDeskTab(['room-move'], $notice);
        self::redirectToPortalReservationDetail($reservationId, $notice);
    }

    private static function currentUserCanViewFinanceContext(): bool
    {
        return \current_user_can(StaffAccess::CAP_PAYMENT_VIEW)
            || \current_user_can(StaffAccess::CAP_REPORT_VIEW_FINANCE)
            || \current_user_can(StaffAccess::CAP_REPORT_VIEW_MANAGEMENT)
            || \current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function filterDashboardDataForCurrentUser(array $data): array
    {
        $canViewFinance = self::currentUserCanViewFinanceContext();
        $data['show_finance_details'] = $canViewFinance;

        if ($canViewFinance) {
            return $data;
        }

        $data['kpis'] = \array_values(
            \array_filter(
                isset($data['kpis']) && \is_array($data['kpis']) ? $data['kpis'] : [],
                static function ($card): bool {
                    if (!\is_array($card)) {
                        return false;
                    }

                    return !\in_array(
                        \sanitize_key((string) ($card['key'] ?? '')),
                        ['unpaid_reservations', 'revenue_today'],
                        true
                    );
                }
            )
        );

        $data['attention_items'] = \array_values(
            \array_filter(
                isset($data['attention_items']) && \is_array($data['attention_items']) ? $data['attention_items'] : [],
                static function ($item): bool {
                    if (!\is_array($item)) {
                        return false;
                    }

                    return !self::isPaymentDashboardActionUrl((string) ($item['action_url'] ?? ''));
                }
            )
        );

        $recentReservations = [];

        foreach ((array) ($data['recent_reservations'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            unset($row['payment'], $row['total']);
            $recentReservations[] = $row;
        }

        $data['recent_reservations'] = $recentReservations;
        $data['recent_activity'] = \array_values(
            \array_filter(
                isset($data['recent_activity']) && \is_array($data['recent_activity']) ? $data['recent_activity'] : [],
                static function ($row): bool {
                    if (!\is_array($row)) {
                        return false;
                    }

                    $eventType = \sanitize_key((string) ($row['event_type'] ?? ''));

                    return !\str_starts_with($eventType, 'payment_');
                }
            )
        );

        return $data;
    }

    private static function isPaymentDashboardActionUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $paymentsUrl = PortalRouter::getModuleUrl('payments');

        if ($paymentsUrl !== '' && \strpos($url, $paymentsUrl) === 0) {
            return true;
        }

        $parts = \wp_parse_url($url);

        if (!\is_array($parts) || empty($parts['query'])) {
            return false;
        }

        $queryArgs = [];
        \parse_str((string) $parts['query'], $queryArgs);

        return isset($queryArgs['page']) && \sanitize_key((string) $queryArgs['page']) === 'must-hotel-booking-payments';
    }

    private static function isFrontDeskRoomMoveRequest(): bool
    {
        $returnModule = isset($_POST['portal_return_module']) ? \sanitize_key((string) \wp_unslash($_POST['portal_return_module'])) : '';
        $returnTab = isset($_POST['portal_return_tab']) ? \sanitize_key((string) \wp_unslash($_POST['portal_return_tab'])) : '';

        return $returnModule === 'front_desk' && $returnTab === 'room-move';
    }

    /**
     * @param array<int, string> $allowedTabs
     */
    private static function maybeRedirectToRequestedFrontDeskTab(array $allowedTabs, string $notice): void
    {
        $returnModule = isset($_POST['portal_return_module']) ? \sanitize_key((string) \wp_unslash($_POST['portal_return_module'])) : '';
        $returnTab = isset($_POST['portal_return_tab']) ? \sanitize_key((string) \wp_unslash($_POST['portal_return_tab'])) : '';

        if ($returnModule !== 'front_desk' || !\in_array($returnTab, $allowedTabs, true)) {
            return;
        }

        if (!StaffAccess::userCanAccessPortalModule('front_desk')) {
            return;
        }

        $args = ['tab' => $returnTab];

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('front_desk', $args));
        exit;
    }

    /**
     * @param array<string, mixed> $forms
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    private static function buildReservationActionState(int $reservationId, array $forms, array $errors): array
    {
        return [
            'module_key' => 'reservations',
            'errors' => $errors,
            'forms' => $forms,
            'selected_reservation_id' => $reservationId,
        ];
    }

    /**
     * @param array<string, mixed> $forms
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    private static function buildPaymentActionState(int $reservationId, array $forms, array $errors): array
    {
        return [
            'module_key' => 'payments',
            'errors' => $errors,
            'forms' => $forms,
            'selected_reservation_id' => $reservationId,
        ];
    }

    /**
     * @return array<string, scalar>
     */
    private static function getPortalPaymentRedirectArgs(int $reservationId): array
    {
        $args = [];

        if ($reservationId > 0) {
            $args['reservation_id'] = $reservationId;
        }

        $queryKeys = [
            'status' => 'key',
            'method' => 'key',
            'reservation_status' => 'key',
            'payment_group' => 'key',
            'search' => 'text',
            'date_from' => 'text',
            'date_to' => 'text',
            'due_only' => 'int',
            'per_page' => 'int',
            'paged' => 'int',
        ];

        foreach ($queryKeys as $key => $type) {
            if (!isset($_GET[$key])) {
                continue;
            }

            if (
                !self::currentUserCanViewFinanceContext()
                && (
                    $key === 'payment_status'
                    || $key === 'payment_method'
                    || ($key === 'quick_filter' && \in_array(\sanitize_key((string) \wp_unslash($_GET[$key])), ['unpaid', 'paid', 'failed_payment'], true))
                )
            ) {
                continue;
            }

            $rawValue = \wp_unslash($_GET[$key]);

            if (\is_array($rawValue)) {
                continue;
            }

            if ($type === 'int') {
                $value = (int) $rawValue;
            } elseif ($type === 'key') {
                $value = \sanitize_key((string) $rawValue);
            } else {
                $value = \sanitize_text_field((string) $rawValue);
            }

            if ($value === '' || $value === 0) {
                continue;
            }

            $args[$key] = $value;
        }

        return $args;
    }

    private static function redirectToPortalPaymentDetail(int $reservationId, string $notice): void
    {
        $args = self::getPortalPaymentRedirectArgs($reservationId);

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('payments', $args));
        exit;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<int, string> $errors
     * @return array<string, mixed>
     */
    private static function buildGuestActionState(int $guestId, array $form, array $errors): array
    {
        return [
            'module_key' => 'guests',
            'errors' => $errors,
            'form' => $form,
            'selected_guest_id' => $guestId,
        ];
    }

    /**
     * @param array<string, mixed> $guest
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function mergeGuestUpdatePayload(array $guest, array $overrides): array
    {
        return [
            'first_name' => isset($overrides['first_name']) ? (string) $overrides['first_name'] : (string) ($guest['first_name'] ?? ''),
            'last_name' => isset($overrides['last_name']) ? (string) $overrides['last_name'] : (string) ($guest['last_name'] ?? ''),
            'email' => isset($overrides['email']) ? (string) $overrides['email'] : (string) ($guest['email'] ?? ''),
            'phone' => isset($overrides['phone']) ? (string) $overrides['phone'] : (string) ($guest['phone'] ?? ''),
            'country' => isset($overrides['country']) ? (string) $overrides['country'] : (string) ($guest['country'] ?? ''),
            'admin_notes' => isset($overrides['admin_notes']) ? (string) $overrides['admin_notes'] : (string) ($guest['admin_notes'] ?? ''),
            'vip_flag' => isset($overrides['vip_flag']) ? (int) $overrides['vip_flag'] : (int) ($guest['vip_flag'] ?? 0),
            'problem_flag' => isset($overrides['problem_flag']) ? (int) $overrides['problem_flag'] : (int) ($guest['problem_flag'] ?? 0),
        ];
    }

    private static function getPortalGuestRedirectArgs(int $guestId): array
    {
        $args = [];

        if ($guestId > 0) {
            $args['guest_id'] = $guestId;
        }

        $queryKeys = [
            'search' => 'text',
            'country' => 'text',
            'stay_state' => 'key',
            'attention' => 'key',
            'flagged' => 'key',
            'has_notes' => 'int',
            'per_page' => 'int',
            'paged' => 'int',
        ];

        foreach ($queryKeys as $key => $type) {
            if (!isset($_GET[$key])) {
                continue;
            }

            $rawValue = \wp_unslash($_GET[$key]);

            if (\is_array($rawValue)) {
                continue;
            }

            if ($type === 'int') {
                $value = (int) $rawValue;
            } elseif ($type === 'key') {
                $value = \sanitize_key((string) $rawValue);
            } else {
                $value = \sanitize_text_field((string) $rawValue);
            }

            if ($value === '' || $value === 0) {
                continue;
            }

            $args[$key] = $value;
        }

        return $args;
    }

    private static function redirectToPortalGuestDetail(int $guestId, string $notice): void
    {
        $args = self::getPortalGuestRedirectArgs($guestId);

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('guests', $args));
        exit;
    }

    private static function handleHousekeepingStatusUpdate(string $status, string $capability, string $successNotice): void
    {
        $roomId = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
        $nonce = isset($_POST['must_portal_housekeeping_nonce']) ? (string) \wp_unslash($_POST['must_portal_housekeeping_nonce']) : '';
        $status = HousekeepingRepository::normalizeStatus($status);

        if ($roomId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_housekeeping_action_' . $status . '_' . $roomId)) {
            self::redirectToPortalHousekeeping('invalid_nonce');
        }

        if (!\current_user_can($capability) && !\current_user_can('manage_options')) {
            self::redirectToPortalHousekeeping('access_denied');
        }

        $roomBoardRow = (new HousekeepingAdminDataProvider())->getRoomBoardRoom($roomId);

        if (!\is_array($roomBoardRow)) {
            self::redirectToPortalHousekeeping('housekeeping_room_not_found');
        }

        $currentStatus = \sanitize_key((string) ($roomBoardRow['housekeeping_status_key'] ?? ''));
        $currentReservations = isset($roomBoardRow['current_reservations']) ? (int) $roomBoardRow['current_reservations'] : 0;

        if ($currentStatus === HousekeepingRepository::STATUS_OUT_OF_ORDER || $currentStatus === $status) {
            self::redirectToPortalHousekeeping('housekeeping_status_invalid');
        }

        if ($status === HousekeepingRepository::STATUS_INSPECTED && ($currentStatus !== HousekeepingRepository::STATUS_CLEAN || $currentReservations > 0)) {
            self::redirectToPortalHousekeeping('housekeeping_status_invalid');
        }

        $inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $housekeepingRepository = \MustHotelBooking\Engine\get_housekeeping_repository();
        $room = $inventoryRepository->getInventoryRoomById($roomId);

        if (!\is_array($room)) {
            self::redirectToPortalHousekeeping('housekeeping_room_not_found');
        }

        if (!$housekeepingRepository->updateRoomStatus($roomId, $status, \get_current_user_id())) {
            self::redirectToPortalHousekeeping('portal_action_failed');
        }

        self::logHousekeepingActivity($roomId, $room, $status);
        self::redirectToPortalHousekeeping($successNotice);
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleHousekeepingAssignRoom(): array
    {
        $roomId = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
        $assigneeId = isset($_POST['assigned_to_user_id']) ? \absint(\wp_unslash($_POST['assigned_to_user_id'])) : 0;
        $nonce = isset($_POST['must_portal_housekeeping_nonce']) ? (string) \wp_unslash($_POST['must_portal_housekeeping_nonce']) : '';

        if ($roomId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_housekeeping_assign_' . $roomId)) {
            self::redirectToPortalHousekeeping('invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_HOUSEKEEPING_ASSIGN_STAFF) && !\current_user_can('manage_options')) {
            self::redirectToPortalHousekeeping('access_denied');
        }

        $provider = new HousekeepingAdminDataProvider();
        $roomBoardRow = $provider->getRoomBoardRoom($roomId);

        if (!\is_array($roomBoardRow)) {
            self::redirectToPortalHousekeeping('housekeeping_room_not_found');
        }

        $validAssigneeIds = [0];

        foreach ($provider->getAssignableStaffOptions() as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $optionId = isset($option['id']) ? (int) $option['id'] : 0;

            if ($optionId > 0) {
                $validAssigneeIds[] = $optionId;
            }
        }

        if (!\in_array($assigneeId, $validAssigneeIds, true)) {
            self::redirectToPortalHousekeeping('housekeeping_assignment_invalid');
        }

        $inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $room = $inventoryRepository->getInventoryRoomById($roomId);

        if (!\is_array($room)) {
            self::redirectToPortalHousekeeping('housekeeping_room_not_found');
        }

        if (!\MustHotelBooking\Engine\get_housekeeping_repository()->assignRoom($roomId, $assigneeId, \get_current_user_id())) {
            self::redirectToPortalHousekeeping('portal_action_failed');
        }

        self::logHousekeepingAssignmentActivity($roomId, $room, $assigneeId);
        self::redirectToPortalHousekeeping('housekeeping_assignment_updated');
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleHousekeepingCreateIssue(): array
    {
        $nonce = isset($_POST['must_portal_housekeeping_nonce']) ? (string) \wp_unslash($_POST['must_portal_housekeeping_nonce']) : '';
        $roomId = isset($_POST['issue_room_id']) ? \absint(\wp_unslash($_POST['issue_room_id'])) : 0;
        $title = isset($_POST['issue_title']) ? \sanitize_text_field((string) \wp_unslash($_POST['issue_title'])) : '';
        $details = isset($_POST['issue_details']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['issue_details'])) : '';

        if (!\wp_verify_nonce($nonce, 'must_portal_housekeeping_create_issue')) {
            self::redirectToPortalHousekeeping('invalid_nonce');
        }

        if (!\current_user_can(StaffAccess::CAP_HOUSEKEEPING_CREATE_ISSUE) && !\current_user_can('manage_options')) {
            self::redirectToPortalHousekeeping('access_denied');
        }

        $errors = [];

        if ($roomId <= 0) {
            $errors[] = \__('Select a room before creating a maintenance issue.', 'must-hotel-booking');
        }

        if ($title === '') {
            $errors[] = \__('Enter a short maintenance issue title.', 'must-hotel-booking');
        }

        $provider = new HousekeepingAdminDataProvider();
        $roomBoardRow = $provider->getRoomBoardRoom($roomId);

        if ($roomId > 0 && !\is_array($roomBoardRow)) {
            $errors[] = \__('Room record not found.', 'must-hotel-booking');
        }

        if (!empty($errors)) {
            return [
                'module_key' => 'housekeeping',
                'active_tab' => 'maintenance',
                'errors' => $errors,
                'issue_form' => [
                    'issue_room_id' => $roomId,
                    'issue_title' => $title,
                    'issue_details' => $details,
                ],
            ];
        }

        $issueId = \MustHotelBooking\Engine\get_housekeeping_repository()->createIssue($roomId, $title, $details, \get_current_user_id());

        if ($issueId <= 0) {
            self::redirectToPortalHousekeeping('portal_action_failed');
        }

        $inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $room = $inventoryRepository->getInventoryRoomById($roomId);

        if (\is_array($room)) {
            self::logHousekeepingIssueActivity($issueId, $roomId, $room, $title, HousekeepingRepository::ISSUE_STATUS_OPEN);
        }

        self::redirectToPortalHousekeeping('housekeeping_issue_created');
    }

    private static function handleHousekeepingUpdateIssueStatus(): void
    {
        $issueId = isset($_POST['issue_id']) ? \absint(\wp_unslash($_POST['issue_id'])) : 0;
        $rawStatus = isset($_POST['issue_status']) ? \sanitize_key((string) \wp_unslash($_POST['issue_status'])) : '';
        $status = HousekeepingRepository::normalizeIssueStatus($rawStatus);
        $nonce = isset($_POST['must_portal_housekeeping_nonce']) ? (string) \wp_unslash($_POST['must_portal_housekeeping_nonce']) : '';

        if ($issueId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_housekeeping_issue_status_' . $issueId)) {
            self::redirectToPortalHousekeeping('invalid_nonce');
        }

        if ($rawStatus === '' || !\in_array($rawStatus, HousekeepingRepository::getAllowedIssueStatuses(), true)) {
            self::redirectToPortalHousekeeping('portal_action_failed');
        }

        if (!\current_user_can(StaffAccess::CAP_HOUSEKEEPING_CREATE_ISSUE) && !\current_user_can('manage_options')) {
            self::redirectToPortalHousekeeping('access_denied');
        }

        $issue = \MustHotelBooking\Engine\get_housekeeping_repository()->getIssue($issueId);

        if (!\is_array($issue)) {
            self::redirectToPortalHousekeeping('housekeeping_issue_not_found');
        }

        $roomId = isset($issue['inventory_room_id']) ? (int) $issue['inventory_room_id'] : 0;
        $room = \MustHotelBooking\Engine\get_inventory_repository()->getInventoryRoomById($roomId);

        if ($roomId <= 0 || !\is_array($room)) {
            self::redirectToPortalHousekeeping('housekeeping_room_not_found');
        }

        if (!\MustHotelBooking\Engine\get_housekeeping_repository()->updateIssueStatus($issueId, $status, \get_current_user_id())) {
            self::redirectToPortalHousekeeping('portal_action_failed');
        }

        self::logHousekeepingIssueActivity(
            $issueId,
            $roomId,
            $room,
            (string) ($issue['issue_title'] ?? ''),
            $status
        );

        self::redirectToPortalHousekeeping('housekeeping_issue_updated');
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleHousekeepingCreateHandoff(): array
    {
        $nonce = isset($_POST['must_portal_housekeeping_nonce']) ? (string) \wp_unslash($_POST['must_portal_housekeeping_nonce']) : '';
        $shiftLabel = isset($_POST['handoff_shift_label']) ? \sanitize_text_field((string) \wp_unslash($_POST['handoff_shift_label'])) : '';
        $notes = isset($_POST['handoff_notes']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['handoff_notes'])) : '';

        if (!\wp_verify_nonce($nonce, 'must_portal_housekeeping_create_handoff')) {
            self::redirectToPortalHousekeeping('invalid_nonce');
        }

        if (
            !\current_user_can(StaffAccess::CAP_HOUSEKEEPING_UPDATE_STATUS)
            && !\current_user_can(StaffAccess::CAP_HOUSEKEEPING_INSPECT)
            && !\current_user_can('manage_options')
        ) {
            self::redirectToPortalHousekeeping('access_denied');
        }

        if ($shiftLabel === '') {
            $shiftLabel = \sprintf(
                /* translators: %s: current date and time */
                \__('Shift handoff %s', 'must-hotel-booking'),
                \wp_date(\get_option('date_format') . ' ' . \get_option('time_format'))
            );
        }

        $provider = new HousekeepingAdminDataProvider();
        $snapshot = $provider->getCurrentHandoffSnapshot();
        $handoffId = \MustHotelBooking\Engine\get_housekeeping_repository()->createHandoff(
            $shiftLabel,
            $notes,
            $snapshot,
            \get_current_user_id()
        );

        if ($handoffId <= 0) {
            self::redirectToPortalHousekeeping('portal_action_failed');
        }

        self::logHousekeepingHandoffActivity($handoffId, $shiftLabel, $snapshot);
        self::redirectToPortalHousekeeping('housekeeping_handoff_created');
    }

    /**
     * @param array<string, mixed> $room
     */
    private static function logHousekeepingActivity(int $roomId, array $room, string $status): void
    {
        $actor = self::getCurrentPortalActorContext();
        $roomNumber = \trim((string) ($room['room_number'] ?? ''));
        $roomTitle = \trim((string) ($room['title'] ?? ''));
        $reference = self::buildHousekeepingRoomReference($roomId, $room);
        $statusLabels = HousekeepingRepository::getStatusLabels();
        $statusLabel = isset($statusLabels[$status]) ? (string) $statusLabels[$status] : \ucwords(\str_replace('_', ' ', $status));

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => 'housekeeping_status_updated',
                'severity' => $status === HousekeepingRepository::STATUS_DIRTY ? 'warning' : 'info',
                'entity_type' => 'inventory_room',
                'entity_id' => $roomId,
                'reference' => $reference,
                'message' => \sprintf(
                    /* translators: 1: room reference, 2: housekeeping status */
                    \__('Room %1$s marked %2$s.', 'must-hotel-booking'),
                    $reference,
                    \strtolower($statusLabel)
                ),
                'actor_user_id' => (int) $actor['user_id'],
                'actor_role' => (string) $actor['role'],
                'actor_ip' => (string) $actor['ip'],
                'context_json' => \wp_json_encode(
                    [
                        'inventory_room_id' => $roomId,
                        'room_number' => $roomNumber,
                        'room_title' => $roomTitle,
                        'housekeeping_status' => $status,
                        'actor_user_id' => (int) $actor['user_id'],
                    ]
                ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $room
     */
    private static function logHousekeepingAssignmentActivity(int $roomId, array $room, int $assigneeId): void
    {
        $actor = self::getCurrentPortalActorContext();
        $reference = self::buildHousekeepingRoomReference($roomId, $room);
        $assigneeLabel = $assigneeId > 0 ? self::formatAuditActorLabel(['actor_user_id' => $assigneeId]) : \__('Unassigned', 'must-hotel-booking');

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => 'housekeeping_assignment_updated',
                'severity' => 'info',
                'entity_type' => 'inventory_room',
                'entity_id' => $roomId,
                'reference' => $reference,
                'message' => \sprintf(
                    /* translators: 1: room reference, 2: assignee label */
                    \__('Room %1$s assigned to %2$s.', 'must-hotel-booking'),
                    $reference,
                    $assigneeLabel
                ),
                'actor_user_id' => (int) $actor['user_id'],
                'actor_role' => (string) $actor['role'],
                'actor_ip' => (string) $actor['ip'],
                'context_json' => \wp_json_encode(
                    [
                        'inventory_room_id' => $roomId,
                        'room_number' => (string) ($room['room_number'] ?? ''),
                        'room_title' => (string) ($room['title'] ?? ''),
                        'assigned_to_user_id' => $assigneeId,
                        'actor_user_id' => (int) $actor['user_id'],
                    ]
                ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $room
     */
    private static function logHousekeepingIssueActivity(int $issueId, int $roomId, array $room, string $issueTitle, string $status): void
    {
        $actor = self::getCurrentPortalActorContext();
        $reference = self::buildHousekeepingRoomReference($roomId, $room);
        $statusLabels = HousekeepingRepository::getIssueStatusLabels();
        $statusLabel = isset($statusLabels[$status]) ? (string) $statusLabels[$status] : \ucwords(\str_replace('_', ' ', $status));
        $eventType = $status === HousekeepingRepository::ISSUE_STATUS_OPEN ? 'housekeeping_issue_created' : 'housekeeping_issue_updated';

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => $eventType,
                'severity' => $status === HousekeepingRepository::ISSUE_STATUS_RESOLVED ? 'info' : 'warning',
                'entity_type' => 'housekeeping_issue',
                'entity_id' => $issueId,
                'reference' => $reference,
                'message' => \sprintf(
                    /* translators: 1: room reference, 2: issue title, 3: issue status */
                    \__('Maintenance issue for %1$s: %2$s (%3$s).', 'must-hotel-booking'),
                    $reference,
                    $issueTitle !== '' ? $issueTitle : \__('Issue', 'must-hotel-booking'),
                    \strtolower($statusLabel)
                ),
                'actor_user_id' => (int) $actor['user_id'],
                'actor_role' => (string) $actor['role'],
                'actor_ip' => (string) $actor['ip'],
                'context_json' => \wp_json_encode(
                    [
                        'issue_id' => $issueId,
                        'inventory_room_id' => $roomId,
                        'room_number' => (string) ($room['room_number'] ?? ''),
                        'room_title' => (string) ($room['title'] ?? ''),
                        'issue_title' => $issueTitle,
                        'issue_status' => $status,
                        'actor_user_id' => (int) $actor['user_id'],
                    ]
                ),
            ]
        );
    }

    /**
     * @param array<string, int> $snapshot
     */
    private static function logHousekeepingHandoffActivity(int $handoffId, string $shiftLabel, array $snapshot): void
    {
        $actor = self::getCurrentPortalActorContext();

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => 'housekeeping_handoff_created',
                'severity' => 'info',
                'entity_type' => 'housekeeping_handoff',
                'entity_id' => $handoffId,
                'reference' => $shiftLabel,
                'message' => \sprintf(
                    /* translators: %s: shift handoff label */
                    \__('Housekeeping handoff captured: %s.', 'must-hotel-booking'),
                    $shiftLabel
                ),
                'actor_user_id' => (int) $actor['user_id'],
                'actor_role' => (string) $actor['role'],
                'actor_ip' => (string) $actor['ip'],
                'context_json' => \wp_json_encode(
                    [
                        'handoff_id' => $handoffId,
                        'shift_label' => $shiftLabel,
                        'snapshot' => $snapshot,
                        'actor_user_id' => (int) $actor['user_id'],
                    ]
                ),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function getCurrentPortalActorContext(): array
    {
        $actorUserId = \get_current_user_id();
        $actorRole = '';
        $actorIp = '';

        if ($actorUserId > 0) {
            $actorUser = \wp_get_current_user();

            if ($actorUser instanceof \WP_User && !empty($actorUser->roles)) {
                $actorRole = (string) \reset($actorUser->roles);
            }
        }

        if (isset($_SERVER['REMOTE_ADDR'])) {
            $actorIp = \sanitize_text_field(\wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }

        return [
            'user_id' => $actorUserId,
            'role' => $actorRole,
            'ip' => $actorIp,
        ];
    }

    /**
     * @param array<string, mixed> $room
     */
    private static function buildHousekeepingRoomReference(int $roomId, array $room): string
    {
        $roomNumber = \trim((string) ($room['room_number'] ?? ''));
        $roomTitle = \trim((string) ($room['title'] ?? ''));

        return $roomNumber !== '' ? $roomNumber : ($roomTitle !== '' ? $roomTitle : ('ROOM-' . $roomId));
    }

    /**
     * @return array<string, scalar>
     */
    private static function getPortalHousekeepingRedirectArgs(): array
    {
        $args = [];
        $allowedTabs = ['room-board', 'assignments', 'inspection', 'maintenance', 'handoff'];
        $tab = isset($_POST['portal_housekeeping_tab'])
            ? \sanitize_key((string) \wp_unslash($_POST['portal_housekeeping_tab']))
            : (isset($_GET['tab']) ? \sanitize_key((string) \wp_unslash($_GET['tab'])) : 'room-board');

        if (!\in_array($tab, $allowedTabs, true)) {
            $tab = 'room-board';
        }

        if ($tab !== 'room-board') {
            $args['tab'] = $tab;
        }

        return $args;
    }

    private static function redirectToPortalHousekeeping(string $notice): void
    {
        $args = self::getPortalHousekeepingRedirectArgs();

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('housekeeping', $args));
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareReportsData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $tabs = self::getAvailableReportTabs();
        $activeTab = self::resolveActiveReportTab($request, $tabs);
        $query = ReportAdminQuery::fromRequest($request);
        $reportData = (new ReportAdminDataProvider())->getPageData($query);
        $auditRows = self::canViewAuditReports() ? self::buildReportAuditRows($query) : [];

        self::maybeHandleReportExport($request, $activeTab, $query, $reportData, $auditRows);

        return $reportData + [
            'active_tab' => $activeTab,
            'tabs' => $tabs,
            'filter_url' => PortalRouter::getModuleUrl('reports'),
            'can_export' => self::canExportReports(),
            'export_url' => self::canExportReports()
                ? self::buildReportExportUrl($query, $activeTab)
                : '',
            'ops_cards' => self::buildOpsReportCards($reportData),
            'finance_cards' => self::buildFinanceReportCards($reportData),
            'occupancy_cards' => self::buildOccupancyReportCards($reportData),
            'audit_rows' => $auditRows,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getAvailableReportTabs(): array
    {
        $tabs = [];

        if (self::canViewOpsReports()) {
            $tabs['daily-operations'] = \__('Daily Operations', 'must-hotel-booking');
            $tabs['occupancy'] = \__('Occupancy', 'must-hotel-booking');
        }

        if (self::canViewFinanceReports()) {
            $tabs['payments-finance'] = \__('Payments / Finance', 'must-hotel-booking');
        }

        if (self::canViewAuditReports()) {
            $tabs['audit-log'] = \__('Audit Log', 'must-hotel-booking');
        }

        return $tabs;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, string> $tabs
     */
    private static function resolveActiveReportTab(array $request, array $tabs): string
    {
        $defaultTab = !empty($tabs) ? (string) \array_key_first($tabs) : 'daily-operations';
        $tab = isset($request['tab']) ? \sanitize_key((string) $request['tab']) : $defaultTab;

        return isset($tabs[$tab]) ? $tab : $defaultTab;
    }

    private static function canViewOpsReports(): bool
    {
        return \current_user_can(StaffAccess::CAP_REPORT_VIEW_OPS)
            || \current_user_can(StaffAccess::CAP_REPORT_VIEW_MANAGEMENT)
            || \current_user_can('manage_options');
    }

    private static function canViewFinanceReports(): bool
    {
        return \current_user_can(StaffAccess::CAP_REPORT_VIEW_FINANCE)
            || \current_user_can(StaffAccess::CAP_REPORT_VIEW_MANAGEMENT)
            || \current_user_can('manage_options');
    }

    private static function canViewAuditReports(): bool
    {
        return \current_user_can(StaffAccess::CAP_AUDIT_VIEW)
            || \current_user_can('manage_options');
    }

    private static function canExportReports(): bool
    {
        return \current_user_can(StaffAccess::CAP_REPORT_EXPORT)
            || \current_user_can('manage_options');
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function buildOpsReportCards(array $reportData): array
    {
        $stay = isset($reportData['stay']) && \is_array($reportData['stay']) ? $reportData['stay'] : [];

        return [
            [
                'label' => \__('Reservations', 'must-hotel-booking'),
                'value' => (string) (($reportData['kpis'][0]['value'] ?? '0')),
                'meta' => \__('Reservations created in the selected date range.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Confirmed', 'must-hotel-booking'),
                'value' => (string) (($reportData['kpis'][1]['value'] ?? '0')),
                'meta' => \__('Confirmed or completed bookings created in range.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Cancelled', 'must-hotel-booking'),
                'value' => (string) (($reportData['kpis'][2]['value'] ?? '0')),
                'meta' => \__('Cancelled bookings created in range.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Arrivals', 'must-hotel-booking'),
                'value' => \number_format_i18n((int) ($stay['arrivals_count'] ?? 0)),
                'meta' => \__('Stays with check-in inside the selected range.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Departures', 'must-hotel-booking'),
                'value' => \number_format_i18n((int) ($stay['departures_count'] ?? 0)),
                'meta' => \__('Stays with check-out inside the selected range.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function buildFinanceReportCards(array $reportData): array
    {
        $revenue = isset($reportData['revenue']) && \is_array($reportData['revenue']) ? $reportData['revenue'] : [];

        return [
            [
                'label' => \__('Booked Revenue', 'must-hotel-booking'),
                'value' => (string) ($revenue['booked_revenue'] ?? ''),
                'meta' => \__('Reservation total value inside the selected range.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Amount Paid', 'must-hotel-booking'),
                'value' => (string) ($revenue['amount_paid'] ?? ''),
                'meta' => \__('Payment ledger collections tied to the selected range.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Amount Due', 'must-hotel-booking'),
                'value' => (string) ($revenue['amount_due'] ?? ''),
                'meta' => \__('Outstanding balance on non-cancelled reservations.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Average Booking Value', 'must-hotel-booking'),
                'value' => (string) ($revenue['average_booking_value'] ?? ''),
                'meta' => \__('Booked revenue divided by counted revenue reservations.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function buildOccupancyReportCards(array $reportData): array
    {
        $stay = isset($reportData['stay']) && \is_array($reportData['stay']) ? $reportData['stay'] : [];

        return [
            [
                'label' => \__('Occupancy', 'must-hotel-booking'),
                'value' => (string) (($reportData['kpis'][6]['value'] ?? \__('N/A', 'must-hotel-booking'))),
                'meta' => (string) (($reportData['kpis'][6]['meta'] ?? '')),
            ],
            [
                'label' => \__('Occupied Nights', 'must-hotel-booking'),
                'value' => \number_format_i18n((int) ($stay['occupied_nights'] ?? 0)),
                'meta' => \__('Confirmed stay nights overlapping the selected range.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Available Nights', 'must-hotel-booking'),
                'value' => \number_format_i18n((int) ($stay['available_nights'] ?? 0)),
                'meta' => \__('Sellable inventory nights after maintenance-style constraints.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Average Stay', 'must-hotel-booking'),
                'value' => \number_format_i18n((float) ($stay['average_length_of_stay'] ?? 0), 1),
                'meta' => \__('Average length of stay for arrivals inside the range.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function buildReportAuditRows(ReportAdminQuery $query): array
    {
        $activityRows = \MustHotelBooking\Engine\get_activity_repository()->getActivitiesInDateRange(
            $query->getDateFrom(),
            $query->getDateTo(),
            100
        );
        $rows = [];

        foreach ($activityRows as $activityRow) {
            if (!\is_array($activityRow)) {
                continue;
            }

            $context = self::decodeActivityContext((string) ($activityRow['context_json'] ?? ''));
            $entityType = \sanitize_key((string) ($activityRow['entity_type'] ?? ''));
            $rows[] = [
                'created_at' => self::formatPortalDateTime((string) ($activityRow['created_at'] ?? '')),
                'severity' => (string) ($activityRow['severity'] ?? 'info'),
                'event_type' => (string) ($activityRow['event_type'] ?? ''),
                'event_label' => self::formatAuditEventLabel((string) ($activityRow['event_type'] ?? '')),
                'entity_type' => $entityType,
                'entity_label' => self::formatAuditEntityLabel($entityType),
                'reference' => (string) ($activityRow['reference'] ?? ''),
                'message' => (string) ($activityRow['message'] ?? ''),
                'actor_label' => self::formatAuditActorLabel($activityRow),
                'action_url' => self::buildAuditActionUrl($activityRow, $context),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $reportData
     * @param array<int, array<string, string>> $auditRows
     */
    private static function maybeHandleReportExport(array $request, string $activeTab, ReportAdminQuery $query, array $reportData, array $auditRows): void
    {
        $export = isset($request['export']) ? \sanitize_key((string) $request['export']) : '';

        if ($export !== 'csv') {
            return;
        }

        if (!self::canExportReports()) {
            self::redirectToPortalReports($query, ['tab' => $activeTab], 'access_denied');
        }

        $nonce = isset($request['_wpnonce']) ? (string) $request['_wpnonce'] : '';

        if (!\wp_verify_nonce($nonce, 'must_portal_report_export_' . $activeTab)) {
            self::redirectToPortalReports($query, ['tab' => $activeTab], 'invalid_nonce');
        }

        self::streamReportCsvExport($activeTab, $query, $reportData, $auditRows);
    }

    private static function buildReportExportUrl(ReportAdminQuery $query, string $activeTab): string
    {
        return PortalRouter::getModuleUrl(
            'reports',
            $query->buildUrlArgs(
                [
                    'tab' => $activeTab,
                    'export' => 'csv',
                    '_wpnonce' => \wp_create_nonce('must_portal_report_export_' . $activeTab),
                ]
            )
        );
    }

    /**
     * @param array<string, mixed> $reportData
     * @param array<int, array<string, string>> $auditRows
     */
    private static function streamReportCsvExport(string $activeTab, ReportAdminQuery $query, array $reportData, array $auditRows): void
    {
        $rows = self::buildReportExportRows($activeTab, $reportData, $auditRows);
        $filename = 'staff-report-' . $activeTab . '-' . $query->getDateFrom() . '-to-' . $query->getDateTo() . '.csv';

        \nocache_headers();
        \header('Content-Type: text/csv; charset=utf-8');
        \header('Content-Disposition: attachment; filename="' . \sanitize_file_name($filename) . '"');

        $output = \fopen('php://output', 'w');

        if (\is_resource($output)) {
            foreach ($rows as $row) {
                \fputcsv($output, $row);
            }

            \fclose($output);
        }

        exit;
    }

    /**
     * @param array<string, mixed> $reportData
     * @param array<int, array<string, string>> $auditRows
     * @return array<int, array<int, string>>
     */
    private static function buildReportExportRows(string $activeTab, array $reportData, array $auditRows): array
    {
        if ($activeTab === 'audit-log') {
            $rows = [[
                'Timestamp',
                'Severity',
                'Event',
                'Entity',
                'Actor',
                'Reference',
                'Message',
                'Action URL',
            ]];

            foreach ($auditRows as $row) {
                $rows[] = [
                    (string) ($row['created_at'] ?? ''),
                    (string) ($row['severity'] ?? ''),
                    (string) ($row['event_label'] ?? ''),
                    (string) ($row['entity_label'] ?? ''),
                    (string) ($row['actor_label'] ?? ''),
                    (string) ($row['reference'] ?? ''),
                    (string) ($row['message'] ?? ''),
                    (string) ($row['action_url'] ?? ''),
                ];
            }

            return $rows;
        }

        $rows = [['Section', 'Label', 'Value', 'Meta']];

        if ($activeTab === 'daily-operations') {
            foreach (self::buildOpsReportCards($reportData) as $card) {
                $rows[] = ['summary', (string) ($card['label'] ?? ''), (string) ($card['value'] ?? ''), (string) ($card['meta'] ?? '')];
            }

            foreach ((array) ($reportData['breakdowns']['reservation_status'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $rows[] = ['reservation_status', (string) ($row['label'] ?? ''), (string) ($row['value'] ?? ''), ''];
            }

            foreach ((array) ($reportData['trend']['rows'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $rows[] = [
                    'trend',
                    (string) ($row['label'] ?? ''),
                    (string) ($row['reservations'] ?? ''),
                    (string) ($row['revenue'] ?? ''),
                ];
            }

            foreach ((array) ($reportData['issues'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $rows[] = ['issues', (string) ($row['label'] ?? ''), (string) ($row['value'] ?? ''), ''];
            }

            return $rows;
        }

        if ($activeTab === 'payments-finance') {
            foreach (self::buildFinanceReportCards($reportData) as $card) {
                $rows[] = ['summary', (string) ($card['label'] ?? ''), (string) ($card['value'] ?? ''), (string) ($card['meta'] ?? '')];
            }

            foreach ((array) ($reportData['breakdowns']['payment_status'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $rows[] = ['payment_status', (string) ($row['label'] ?? ''), (string) ($row['value'] ?? ''), ''];
            }

            foreach ((array) ($reportData['breakdowns']['payment_method'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $rows[] = ['payment_method', (string) ($row['label'] ?? ''), (string) ($row['value'] ?? ''), ''];
            }

            foreach ((array) ($reportData['coupons'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $rows[] = [
                    'coupons',
                    (string) ($row['coupon_code'] ?? ''),
                    (string) ($row['uses'] ?? ''),
                    (string) ($row['discount_total'] ?? ''),
                ];
            }

            foreach ((array) ($reportData['issues'] ?? []) as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $rows[] = ['issues', (string) ($row['label'] ?? ''), (string) ($row['value'] ?? ''), ''];
            }

            return $rows;
        }

        foreach (self::buildOccupancyReportCards($reportData) as $card) {
            $rows[] = ['summary', (string) ($card['label'] ?? ''), (string) ($card['value'] ?? ''), (string) ($card['meta'] ?? '')];
        }

        foreach ((array) ($reportData['top_accommodations'] ?? []) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $rows[] = [
                'top_accommodations',
                (string) ($row['room_name'] ?? ''),
                (string) ($row['reservations'] ?? ''),
                (string) ($row['revenue'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $activityRow
     * @param array<string, mixed> $context
     */
    private static function buildAuditActionUrl(array $activityRow, array $context): string
    {
        $entityType = \sanitize_key((string) ($activityRow['entity_type'] ?? ''));
        $eventType = \sanitize_key((string) ($activityRow['event_type'] ?? ''));
        $entityId = isset($activityRow['entity_id']) ? (int) $activityRow['entity_id'] : 0;

        if ($eventType === 'calendar_block_created' && StaffAccess::userCanAccessPortalModule('rooms_availability')) {
            $roomId = isset($context['room_id']) ? (int) $context['room_id'] : 0;
            $checkin = isset($context['checkin']) ? (string) $context['checkin'] : '';

            if ($roomId > 0) {
                $args = [
                    'tab' => 'blocks',
                    'room_id' => $roomId,
                ];

                if ($checkin !== '') {
                    $args['start_date'] = $checkin;
                    $args['end_date'] = $checkin;
                }

                return PortalRouter::getModuleUrl('rooms_availability', $args);
            }
        }

        if ($entityType === 'reservation' && StaffAccess::userCanAccessPortalModule('reservations')) {
            $reservationId = isset($context['reservation_id']) ? (int) $context['reservation_id'] : $entityId;

            if ($reservationId > 0) {
                return PortalRouter::getModuleUrl('reservations', ['reservation_id' => $reservationId]);
            }
        }

        if ($entityType === 'payment' && StaffAccess::userCanAccessPortalModule('payments')) {
            $reservationId = isset($context['reservation_id']) ? (int) $context['reservation_id'] : 0;

            if ($reservationId > 0) {
                return PortalRouter::getModuleUrl('payments', ['reservation_id' => $reservationId]);
            }
        }

        if ($entityType === 'guest' && StaffAccess::userCanAccessPortalModule('guests')) {
            $guestId = isset($context['guest_id']) ? (int) $context['guest_id'] : $entityId;

            if ($guestId > 0) {
                return PortalRouter::getModuleUrl('guests', ['guest_id' => $guestId]);
            }
        }

        if ($entityType === 'inventory_room') {
            if (StaffAccess::userCanAccessPortalModule('housekeeping')) {
                $tab = $eventType === 'housekeeping_assignment_updated' ? 'assignments' : 'room-board';

                return PortalRouter::getModuleUrl('housekeeping', ['tab' => $tab]);
            }

            if (StaffAccess::userCanAccessPortalModule('rooms_availability')) {
                return PortalRouter::getModuleUrl('rooms_availability', ['tab' => 'statuses']);
            }
        }

        if ($entityType === 'availability_block' && StaffAccess::userCanAccessPortalModule('rooms_availability')) {
            $roomId = isset($context['room_id']) ? (int) $context['room_id'] : 0;
            $args = ['tab' => 'blocks'];

            if ($roomId > 0) {
                $args['room_id'] = $roomId;
            }

            if ($eventType !== 'availability_block_deleted' && $entityId > 0) {
                $args['action'] = 'edit_block';
                $args['block_id'] = $entityId;
            }

            return PortalRouter::getModuleUrl('rooms_availability', $args);
        }

        if ($entityType === 'availability_rule' && StaffAccess::userCanAccessPortalModule('rooms_availability')) {
            $roomId = isset($context['room_id']) ? (int) $context['room_id'] : 0;
            $args = ['tab' => 'rules'];

            if ($roomId > 0) {
                $args['room_id'] = $roomId;
            }

            if ($eventType !== 'availability_rule_deleted' && $entityId > 0) {
                $args['action'] = 'edit_rule';
                $args['rule_id'] = $entityId;
            }

            return PortalRouter::getModuleUrl('rooms_availability', $args);
        }

        if ($entityType === 'housekeeping_issue' && StaffAccess::userCanAccessPortalModule('housekeeping')) {
            return PortalRouter::getModuleUrl('housekeeping', ['tab' => 'maintenance']);
        }

        if ($entityType === 'housekeeping_handoff' && StaffAccess::userCanAccessPortalModule('housekeeping')) {
            return PortalRouter::getModuleUrl('housekeeping', ['tab' => 'handoff']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $activityRow
     */
    private static function formatAuditActorLabel(array $activityRow): string
    {
        $actorUserId = isset($activityRow['actor_user_id']) ? (int) $activityRow['actor_user_id'] : 0;
        $actorRole = \sanitize_key((string) ($activityRow['actor_role'] ?? ''));

        if ($actorUserId > 0) {
            $user = \get_userdata($actorUserId);

            if ($user instanceof \WP_User && $user->display_name !== '') {
                return $user->display_name;
            }
        }

        if ($actorRole !== '') {
            $label = \preg_replace('/^mhb_/', '', $actorRole);

            return \ucwords(\str_replace('_', ' ', (string) $label));
        }

        return \__('System', 'must-hotel-booking');
    }

    private static function formatAuditEventLabel(string $eventType): string
    {
        $eventType = \sanitize_key($eventType);

        if ($eventType === '') {
            return \__('Activity', 'must-hotel-booking');
        }

        return \ucwords(\str_replace('_', ' ', $eventType));
    }

    private static function formatAuditEntityLabel(string $entityType): string
    {
        $entityType = \sanitize_key($entityType);

        if ($entityType === '') {
            return \__('General', 'must-hotel-booking');
        }

        return \ucwords(\str_replace('_', ' ', $entityType));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeActivityContext(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = \json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private static function formatPortalDateTime(string $datetime): string
    {
        if ($datetime === '') {
            return '';
        }

        $timestamp = \strtotime($datetime);

        if ($timestamp === false) {
            return $datetime;
        }

        return \wp_date(\get_option('date_format') . ' ' . \get_option('time_format'), $timestamp);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function getPortalReportRedirectArgs(ReportAdminQuery $query, array $overrides = []): array
    {
        return $query->buildUrlArgs($overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function redirectToPortalReports(ReportAdminQuery $query, array $overrides = [], string $notice = ''): void
    {
        $args = self::getPortalReportRedirectArgs($query, $overrides);

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('reports', $args));
        exit;
    }

    /**
     * Front Desk workspace data.
     *
     * @param array<string, mixed> $actionState
     * @return array<string, mixed>
     */
    private static function prepareFrontDeskData(array $actionState): array
    {
        $allowedTabs = ['new-booking', 'walk-in', 'checkin', 'checkout', 'room-move', 'log'];
        $activeTab = isset($_GET['tab']) ? \sanitize_key((string) \wp_unslash($_GET['tab'])) : 'new-booking';

        if (!\in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'new-booking';
        }

        $data = [
            'active_tab' => $activeTab,
        ];

        if ($activeTab === 'new-booking') {
            $data = \array_merge($data, self::prepareQuickBookingData($actionState));
        } elseif (\in_array($activeTab, ['checkin', 'checkout'], true)) {
            $data['queue_data'] = (new ReservationAdminDataProvider())->getFrontDeskQueueData($activeTab);
        } elseif ($activeTab === 'room-move') {
            $data['room_move_data'] = (new ReservationAdminDataProvider())->getFrontDeskRoomMoveData();
        } elseif ($activeTab === 'log') {
            $data['log_data'] = self::prepareFrontDeskLogData();
        }

        return $data;
    }

    /**
     * Housekeeping workspace data.
     *
     * @return array<string, mixed>
     */
    private static function prepareHousekeepingData(array $actionState): array
    {
        $allowedTabs = ['room-board', 'assignments', 'inspection', 'maintenance', 'handoff'];
        $activeTab = isset($actionState['active_tab'])
            ? \sanitize_key((string) $actionState['active_tab'])
            : (isset($_GET['tab']) ? \sanitize_key((string) \wp_unslash($_GET['tab'])) : 'room-board');

        if (!\in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'room-board';
        }

        $data = (new HousekeepingAdminDataProvider())->getPageData($activeTab);

        if (isset($actionState['errors']) && \is_array($actionState['errors']) && !empty($actionState['errors'])) {
            $data['errors'] = $actionState['errors'];
        }

        if (isset($actionState['issue_form']) && \is_array($actionState['issue_form'])) {
            $data['issue_form'] = $actionState['issue_form'];
        }

        if (isset($actionState['handoff_form']) && \is_array($actionState['handoff_form'])) {
            $data['handoff_form'] = $actionState['handoff_form'];
        }

        return $data;
    }

    /**
     * @return array<string, scalar>
     */
    private static function getPortalRoomsAvailabilityRequestFromPost(): array
    {
        /** @var array<string, mixed> $source */
        $source = \is_array($_POST) ? $_POST : [];

        return [
            'tab' => isset($source['portal_rooms_availability_tab']) ? \sanitize_key((string) \wp_unslash($source['portal_rooms_availability_tab'])) : '',
            'action' => isset($source['portal_rooms_availability_action']) ? \sanitize_key((string) \wp_unslash($source['portal_rooms_availability_action'])) : '',
            'rule_id' => isset($source['portal_rooms_availability_rule_id']) ? \absint(\wp_unslash($source['portal_rooms_availability_rule_id'])) : 0,
            'block_id' => isset($source['portal_rooms_availability_block_id']) ? \absint(\wp_unslash($source['portal_rooms_availability_block_id'])) : 0,
            'room_id' => isset($source['portal_rooms_availability_filter_room_id']) ? \absint(\wp_unslash($source['portal_rooms_availability_filter_room_id'])) : 0,
            'status' => isset($source['portal_rooms_availability_filter_status']) ? \sanitize_key((string) \wp_unslash($source['portal_rooms_availability_filter_status'])) : '',
            'timeline' => isset($source['portal_rooms_availability_filter_timeline']) ? \sanitize_key((string) \wp_unslash($source['portal_rooms_availability_filter_timeline'])) : '',
            'rule_type' => isset($source['portal_rooms_availability_filter_rule_type']) ? \sanitize_key((string) \wp_unslash($source['portal_rooms_availability_filter_rule_type'])) : '',
            'search' => isset($source['portal_rooms_availability_filter_search']) ? \sanitize_text_field((string) \wp_unslash($source['portal_rooms_availability_filter_search'])) : '',
            'start_date' => isset($source['portal_rooms_availability_filter_start_date']) ? \sanitize_text_field((string) \wp_unslash($source['portal_rooms_availability_filter_start_date'])) : '',
            'end_date' => isset($source['portal_rooms_availability_filter_end_date']) ? \sanitize_text_field((string) \wp_unslash($source['portal_rooms_availability_filter_end_date'])) : '',
        ];
    }

    /**
     * @param array<string, scalar> $overrides
     * @return array<string, scalar>
     */
    private static function getPortalRoomsAvailabilityRedirectArgs(array $overrides = [], string $fallbackTab = 'rooms'): array
    {
        $request = self::getPortalRoomsAvailabilityRequestFromPost();
        $tab = isset($request['tab']) ? (string) $request['tab'] : '';

        if ($tab === '') {
            $tab = $fallbackTab;
        }

        $allowedTabs = ['rooms', 'room-types', 'statuses', 'blocks', 'rules', 'maintenance'];

        if (!\in_array($tab, $allowedTabs, true)) {
            $tab = $fallbackTab;
        }

        $query = AvailabilityAdminQuery::fromRequest($request);
        $args = \array_merge(['tab' => $tab], $query->buildUrlArgs());

        foreach ($overrides as $key => $value) {
            if ($value === '' || $value === 0 || $value === null || $value === 'all') {
                unset($args[$key]);
                continue;
            }

            $args[$key] = $value;
        }

        return $args;
    }

    /**
     * @param array<string, scalar> $overrides
     */
    private static function redirectToPortalRoomsAvailability(string $fallbackTab, array $overrides = [], string $notice = ''): void
    {
        $args = self::getPortalRoomsAvailabilityRedirectArgs($overrides, $fallbackTab);

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('rooms_availability', $args));
        exit;
    }

    private static function canManageRoomsAvailabilityBlocks(): bool
    {
        return \current_user_can(StaffAccess::CAP_ROOM_BLOCK_MANAGE) || \current_user_can('manage_options');
    }

    private static function canEditRoomsAvailabilityRulesGlobally(): bool
    {
        return \current_user_can(StaffAccess::CAP_AVAILABILITY_RULES_EDIT) || \current_user_can('manage_options');
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function canEditRoomsAvailabilityRule(array $rule): bool
    {
        if (self::canEditRoomsAvailabilityRulesGlobally()) {
            return true;
        }

        return self::canManageRoomsAvailabilityBlocks() && (int) ($rule['room_id'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<int, string> $errors
     * @param array<string, mixed> $forms
     * @return array<string, mixed>
     */
    private static function buildRoomsAvailabilityActionState(string $tab, array $request, array $errors, array $forms = []): array
    {
        $request['tab'] = $tab;

        return \array_merge(
            [
                'module_key' => 'rooms_availability',
                'active_tab' => $tab,
                'rooms_availability_request' => $request,
                'errors' => $errors,
            ],
            $forms
        );
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeRoomsAvailabilityRuleValues(array $source): array
    {
        $roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $values = [
            'id' => isset($source['rule_id']) ? \absint(\wp_unslash($source['rule_id'])) : 0,
            'room_id' => isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0,
            'name' => isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '',
            'availability_date' => isset($source['availability_date']) ? \sanitize_text_field((string) \wp_unslash($source['availability_date'])) : '',
            'end_date' => isset($source['end_date']) ? \sanitize_text_field((string) \wp_unslash($source['end_date'])) : '',
            'rule_type' => isset($source['rule_type']) ? \sanitize_key((string) \wp_unslash($source['rule_type'])) : '',
            'rule_value' => isset($source['rule_value']) ? \absint(\wp_unslash($source['rule_value'])) : 0,
            'is_active' => !empty($source['is_active']) ? 1 : 0,
            'is_available' => 0,
            'reason' => isset($source['reason']) ? \sanitize_text_field((string) \wp_unslash($source['reason'])) : '',
            'errors' => [],
        ];

        if ($values['name'] === '') {
            $values['errors'][] = \__('Rule name is required.', 'must-hotel-booking');
        }

        if (!\in_array($values['rule_type'], ['maintenance_block', 'minimum_stay', 'maximum_stay', 'closed_arrival', 'closed_departure'], true)) {
            $values['errors'][] = \__('Select a valid availability rule type.', 'must-hotel-booking');
        }

        if (
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['availability_date']) ||
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['end_date'])
        ) {
            $values['errors'][] = \__('Start date and end date must use YYYY-MM-DD.', 'must-hotel-booking');
        } elseif ((string) $values['availability_date'] > (string) $values['end_date']) {
            $values['errors'][] = \__('End date must be on or after start date.', 'must-hotel-booking');
        }

        if ((int) $values['room_id'] > 0 && !$roomRepository->getRoomById((int) $values['room_id'])) {
            $values['errors'][] = \__('Selected room listing could not be found.', 'must-hotel-booking');
        }

        if (\in_array($values['rule_type'], ['minimum_stay', 'maximum_stay'], true) && (int) $values['rule_value'] < 1) {
            $values['errors'][] = \__('Stay rules require a value of at least 1 night.', 'must-hotel-booking');
        }

        if (!\in_array($values['rule_type'], ['minimum_stay', 'maximum_stay'], true)) {
            $values['rule_value'] = 0;
        }

        if ($values['reason'] === '') {
            $values['reason'] = $values['name'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function sanitizeRoomsAvailabilityBlockValues(array $source): array
    {
        $roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $values = [
            'id' => isset($source['block_id']) ? \absint(\wp_unslash($source['block_id'])) : 0,
            'room_id' => isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0,
            'checkin' => isset($source['checkin']) ? \sanitize_text_field((string) \wp_unslash($source['checkin'])) : '',
            'checkout' => isset($source['checkout']) ? \sanitize_text_field((string) \wp_unslash($source['checkout'])) : '',
            'notes' => isset($source['notes']) ? \sanitize_textarea_field((string) \wp_unslash($source['notes'])) : '',
            'errors' => [],
        ];

        if ((int) $values['room_id'] <= 0 || !$roomRepository->getRoomById((int) $values['room_id'])) {
            $values['errors'][] = \__('Select a valid room listing for the manual block.', 'must-hotel-booking');
        }

        if (
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['checkin']) ||
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['checkout'])
        ) {
            $values['errors'][] = \__('Start date and end date must use YYYY-MM-DD.', 'must-hotel-booking');
        } elseif ((string) $values['checkin'] >= (string) $values['checkout']) {
            $values['errors'][] = \__('The manual block end date must be after the start date.', 'must-hotel-booking');
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function sanitizeRoomsAvailabilityEditRequest(array $request, string $mode): array
    {
        $action = isset($request['action']) ? \sanitize_key((string) $request['action']) : '';

        if ($mode === 'blocked') {
            if ($action !== 'edit_block') {
                unset($request['action'], $request['block_id']);
                return $request;
            }

            $blockId = isset($request['block_id']) ? (int) $request['block_id'] : 0;
            $block = $blockId > 0 ? \MustHotelBooking\Engine\get_reservation_repository()->getReservation($blockId) : null;

            if (!self::canManageRoomsAvailabilityBlocks() || !\is_array($block) || (string) ($block['status'] ?? '') !== 'blocked') {
                unset($request['action'], $request['block_id']);
            }

            return $request;
        }

        if ($action !== 'edit_rule') {
            unset($request['action'], $request['rule_id']);
            return $request;
        }

        $ruleId = isset($request['rule_id']) ? (int) $request['rule_id'] : 0;
        $rule = $ruleId > 0 ? \MustHotelBooking\Engine\get_availability_repository()->getRuleById($ruleId) : null;

        if (!\is_array($rule) || !self::canEditRoomsAvailabilityRule($rule)) {
            unset($request['action'], $request['rule_id']);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleRoomsAvailabilitySaveBlock(): array
    {
        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $request = self::getPortalRoomsAvailabilityRequestFromPost();
        $request['tab'] = 'blocks';
        $request['action'] = 'edit_block';
        $blockId = isset($rawPost['block_id']) ? \absint(\wp_unslash($rawPost['block_id'])) : 0;
        $request['block_id'] = $blockId;
        $nonce = isset($rawPost['must_availability_block_nonce']) ? (string) \wp_unslash($rawPost['must_availability_block_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_availability_save_block')) {
            self::redirectToPortalRoomsAvailability('blocks', ['action' => 'edit_block', 'block_id' => $blockId], 'invalid_nonce');
        }

        if (!self::canManageRoomsAvailabilityBlocks()) {
            self::redirectToPortalRoomsAvailability('blocks', ['action' => 'edit_block', 'block_id' => $blockId], 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $current = $blockId > 0 ? $reservationRepository->getReservation($blockId) : null;

        if (!\is_array($current) || (string) ($current['status'] ?? '') !== 'blocked') {
            self::redirectToPortalRoomsAvailability('blocks', [], 'portal_action_failed');
        }

        $values = self::sanitizeRoomsAvailabilityBlockValues($rawPost);

        if (!empty($values['errors'])) {
            return self::buildRoomsAvailabilityActionState(
                'blocks',
                $request,
                (array) $values['errors'],
                [
                    'block_errors' => (array) $values['errors'],
                    'block_form' => $values,
                ]
            );
        }

        $conflicts = $reservationRepository->getBlockedReservationConflicts(
            (int) $values['room_id'],
            (string) $values['checkin'],
            (string) $values['checkout'],
            $blockId
        );
        $saved = $reservationRepository->updateReservation(
            $blockId,
            [
                'room_id' => (int) $values['room_id'],
                'checkin' => (string) $values['checkin'],
                'checkout' => (string) $values['checkout'],
                'notes' => (string) ($values['notes'] ?? ''),
            ]
        );

        if (!$saved) {
            return self::buildRoomsAvailabilityActionState(
                'blocks',
                $request,
                [\__('Unable to save the manual block.', 'must-hotel-booking')],
                [
                    'block_errors' => [\__('Unable to save the manual block.', 'must-hotel-booking')],
                    'block_form' => $values,
                ]
            );
        }

        $updatedBlock = $reservationRepository->getReservation($blockId);

        if (\is_array($updatedBlock)) {
            self::logRoomsAvailabilityBlockActivity($blockId, $updatedBlock, 'availability_block_updated');
        }

        self::redirectToPortalRoomsAvailability(
            'blocks',
            [
                'action' => 'edit_block',
                'block_id' => $blockId,
                'room_id' => (int) $values['room_id'],
            ],
            !empty($conflicts) ? 'block_saved_with_conflict' : 'block_updated'
        );
    }

    private static function handleRoomsAvailabilityDeleteBlock(): void
    {
        $request = self::getPortalRoomsAvailabilityRequestFromPost();
        $blockId = isset($_POST['block_id']) ? \absint(\wp_unslash($_POST['block_id'])) : 0;
        $nonce = isset($_POST['must_availability_block_delete_nonce']) ? (string) \wp_unslash($_POST['must_availability_block_delete_nonce']) : '';

        if ($blockId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_delete_block_' . $blockId)) {
            self::redirectToPortalRoomsAvailability('blocks', [], 'invalid_nonce');
        }

        if (!self::canManageRoomsAvailabilityBlocks()) {
            self::redirectToPortalRoomsAvailability('blocks', [], 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $block = $reservationRepository->getReservation($blockId);

        if (!\is_array($block) || (string) ($block['status'] ?? '') !== 'blocked') {
            self::redirectToPortalRoomsAvailability('blocks', [], 'portal_action_failed');
        }

        $deleted = $reservationRepository->deleteReservation($blockId, 'blocked');

        if ($deleted) {
            self::logRoomsAvailabilityBlockActivity($blockId, $block, 'availability_block_deleted');
        }

        $overrides = [];

        if (!empty($request['action']) && (string) $request['action'] === 'edit_block' && (int) ($request['block_id'] ?? 0) === $blockId) {
            $overrides['action'] = '';
            $overrides['block_id'] = 0;
        }

        self::redirectToPortalRoomsAvailability('blocks', $overrides, $deleted ? 'block_deleted' : 'block_delete_failed');
    }

    /**
     * @return array<string, mixed>
     */
    private static function handleRoomsAvailabilitySaveRule(): array
    {
        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $request = self::getPortalRoomsAvailabilityRequestFromPost();
        $request['tab'] = 'rules';
        $request['action'] = 'edit_rule';
        $ruleId = isset($rawPost['rule_id']) ? \absint(\wp_unslash($rawPost['rule_id'])) : 0;
        $request['rule_id'] = $ruleId;
        $nonce = isset($rawPost['must_availability_rule_nonce']) ? (string) \wp_unslash($rawPost['must_availability_rule_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_availability_save_rule')) {
            self::redirectToPortalRoomsAvailability('rules', ['action' => 'edit_rule', 'rule_id' => $ruleId], 'invalid_nonce');
        }

        $availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $currentRule = $ruleId > 0 ? $availabilityRepository->getRuleById($ruleId) : null;

        if (!\is_array($currentRule)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'portal_action_failed');
        }

        if (!self::canEditRoomsAvailabilityRule($currentRule)) {
            self::redirectToPortalRoomsAvailability('rules', ['action' => 'edit_rule', 'rule_id' => $ruleId], 'access_denied');
        }

        $values = self::sanitizeRoomsAvailabilityRuleValues($rawPost);

        if (!self::canEditRoomsAvailabilityRulesGlobally() && (int) ($values['room_id'] ?? 0) <= 0) {
            $values['errors'][] = \__('Your role can only edit room-specific availability rules in the portal.', 'must-hotel-booking');
        }

        if (!empty($values['errors'])) {
            return self::buildRoomsAvailabilityActionState(
                'rules',
                $request,
                (array) $values['errors'],
                [
                    'rule_errors' => (array) $values['errors'],
                    'rule_form' => $values,
                ]
            );
        }

        $conflicts = [];

        if ((int) ($values['room_id'] ?? 0) > 0 && (string) ($values['availability_date'] ?? '') !== '' && (string) ($values['end_date'] ?? '') !== '') {
            $conflicts = $availabilityRepository->getOverlappingReservationRows(
                (int) $values['room_id'],
                (string) $values['availability_date'],
                (string) $values['end_date'],
                0
            );
        }

        $savedId = $availabilityRepository->saveRule($values);

        if ($savedId <= 0) {
            return self::buildRoomsAvailabilityActionState(
                'rules',
                $request,
                [\__('Unable to save availability rule.', 'must-hotel-booking')],
                [
                    'rule_errors' => [\__('Unable to save availability rule.', 'must-hotel-booking')],
                    'rule_form' => $values,
                ]
            );
        }

        $updatedRule = $availabilityRepository->getRuleById($savedId);

        if (\is_array($updatedRule)) {
            self::logRoomsAvailabilityRuleActivity($savedId, $updatedRule, 'availability_rule_updated');
        }

        self::redirectToPortalRoomsAvailability(
            'rules',
            [
                'action' => 'edit_rule',
                'rule_id' => $savedId,
                'room_id' => (int) ($values['room_id'] ?? 0),
            ],
            !empty($conflicts) ? 'rule_saved_with_conflict' : 'rule_updated'
        );
    }

    private static function handleRoomsAvailabilityDeleteRule(): void
    {
        $request = self::getPortalRoomsAvailabilityRequestFromPost();
        $ruleId = isset($_POST['rule_id']) ? \absint(\wp_unslash($_POST['rule_id'])) : 0;
        $nonce = isset($_POST['must_availability_rule_delete_nonce']) ? (string) \wp_unslash($_POST['must_availability_rule_delete_nonce']) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_delete_rule_' . $ruleId)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'invalid_nonce');
        }

        $availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $rule = $availabilityRepository->getRuleById($ruleId);

        if (!\is_array($rule)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'portal_action_failed');
        }

        if (!self::canEditRoomsAvailabilityRule($rule)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'access_denied');
        }

        $deleted = $availabilityRepository->deleteAvailabilityRule($ruleId);

        if ($deleted) {
            self::logRoomsAvailabilityRuleActivity($ruleId, $rule, 'availability_rule_deleted');
        }

        $overrides = [];

        if (!empty($request['action']) && (string) $request['action'] === 'edit_rule' && (int) ($request['rule_id'] ?? 0) === $ruleId) {
            $overrides['action'] = '';
            $overrides['rule_id'] = 0;
        }

        self::redirectToPortalRoomsAvailability('rules', $overrides, $deleted ? 'rule_deleted' : 'rule_delete_failed');
    }

    private static function handleRoomsAvailabilityToggleRuleStatus(): void
    {
        $ruleId = isset($_POST['rule_id']) ? \absint(\wp_unslash($_POST['rule_id'])) : 0;
        $target = isset($_POST['target']) ? \sanitize_key((string) \wp_unslash($_POST['target'])) : '';
        $nonce = isset($_POST['must_availability_rule_toggle_nonce']) ? (string) \wp_unslash($_POST['must_availability_rule_toggle_nonce']) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_toggle_rule_' . $ruleId)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'invalid_nonce');
        }

        if (!\in_array($target, ['active', 'inactive'], true)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'portal_action_failed');
        }

        $availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $rule = $availabilityRepository->getRuleById($ruleId);

        if (!\is_array($rule)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'portal_action_failed');
        }

        if (!self::canEditRoomsAvailabilityRule($rule)) {
            self::redirectToPortalRoomsAvailability('rules', [], 'access_denied');
        }

        $updated = $availabilityRepository->toggleRuleStatus($ruleId, $target === 'active');

        if ($updated) {
            $rule['is_active'] = $target === 'active' ? 1 : 0;
            self::logRoomsAvailabilityRuleActivity(
                $ruleId,
                $rule,
                $target === 'active' ? 'availability_rule_activated' : 'availability_rule_deactivated'
            );
        }

        self::redirectToPortalRoomsAvailability(
            'rules',
            [],
            $updated ? ($target === 'active' ? 'rule_activated' : 'rule_deactivated') : 'rule_update_failed'
        );
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function logRoomsAvailabilityBlockActivity(int $blockId, array $block, string $eventType): void
    {
        $actor = self::getCurrentPortalActorContext();
        $roomId = (int) ($block['room_id'] ?? 0);
        $room = $roomId > 0 ? \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId) : null;
        $roomName = \is_array($room) ? \trim((string) ($room['name'] ?? '')) : '';
        $reference = $roomName !== '' ? $roomName : ('ROOM-' . $roomId);
        $checkin = (string) ($block['checkin'] ?? '');
        $checkout = (string) ($block['checkout'] ?? '');
        $message = $eventType === 'availability_block_deleted'
            ? \sprintf(
                /* translators: 1: room listing, 2: check-in date, 3: check-out date */
                \__('Manual block released for %1$s (%2$s to %3$s).', 'must-hotel-booking'),
                $reference,
                $checkin,
                $checkout
            )
            : \sprintf(
                /* translators: 1: room listing, 2: check-in date, 3: check-out date */
                \__('Manual block updated for %1$s (%2$s to %3$s).', 'must-hotel-booking'),
                $reference,
                $checkin,
                $checkout
            );

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => $eventType,
                'severity' => $eventType === 'availability_block_deleted' ? 'warning' : 'info',
                'entity_type' => 'availability_block',
                'entity_id' => $blockId,
                'reference' => $reference,
                'message' => $message,
                'actor_user_id' => (int) $actor['user_id'],
                'actor_role' => (string) $actor['role'],
                'actor_ip' => (string) $actor['ip'],
                'context_json' => \wp_json_encode(
                    [
                        'block_id' => $blockId,
                        'room_id' => $roomId,
                        'room_name' => $roomName,
                        'checkin' => $checkin,
                        'checkout' => $checkout,
                        'actor_user_id' => (int) $actor['user_id'],
                    ]
                ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function logRoomsAvailabilityRuleActivity(int $ruleId, array $rule, string $eventType): void
    {
        $actor = self::getCurrentPortalActorContext();
        $roomId = (int) ($rule['room_id'] ?? 0);
        $room = $roomId > 0 ? \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId) : null;
        $roomName = \is_array($room) ? \trim((string) ($room['name'] ?? '')) : '';
        $scopeLabel = $roomId > 0
            ? ($roomName !== '' ? $roomName : ('ROOM-' . $roomId))
            : \__('All room listings', 'must-hotel-booking');
        $ruleName = \trim((string) ($rule['name'] ?? '')) !== ''
            ? (string) $rule['name']
            : (string) ($rule['rule_type'] ?? ('RULE-' . $ruleId));
        $messages = [
            'availability_rule_updated' => \sprintf(
                /* translators: 1: rule name, 2: rule scope */
                \__('Availability rule "%1$s" updated for %2$s.', 'must-hotel-booking'),
                $ruleName,
                $scopeLabel
            ),
            'availability_rule_deleted' => \sprintf(
                /* translators: 1: rule name, 2: rule scope */
                \__('Availability rule "%1$s" removed from %2$s.', 'must-hotel-booking'),
                $ruleName,
                $scopeLabel
            ),
            'availability_rule_activated' => \sprintf(
                /* translators: 1: rule name, 2: rule scope */
                \__('Availability rule "%1$s" activated for %2$s.', 'must-hotel-booking'),
                $ruleName,
                $scopeLabel
            ),
            'availability_rule_deactivated' => \sprintf(
                /* translators: 1: rule name, 2: rule scope */
                \__('Availability rule "%1$s" deactivated for %2$s.', 'must-hotel-booking'),
                $ruleName,
                $scopeLabel
            ),
        ];

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
            [
                'event_type' => $eventType,
                'severity' => $eventType === 'availability_rule_deleted' ? 'warning' : 'info',
                'entity_type' => 'availability_rule',
                'entity_id' => $ruleId,
                'reference' => $ruleName,
                'message' => isset($messages[$eventType]) ? $messages[$eventType] : $ruleName,
                'actor_user_id' => (int) $actor['user_id'],
                'actor_role' => (string) $actor['role'],
                'actor_ip' => (string) $actor['ip'],
                'context_json' => \wp_json_encode(
                    [
                        'rule_id' => $ruleId,
                        'room_id' => $roomId,
                        'room_name' => $roomName,
                        'rule_type' => (string) ($rule['rule_type'] ?? ''),
                        'availability_date' => (string) ($rule['availability_date'] ?? ''),
                        'end_date' => (string) ($rule['end_date'] ?? ''),
                        'actor_user_id' => (int) $actor['user_id'],
                    ]
                ),
            ]
        );
    }

    /**
     * Rooms & Availability workspace data.
     *
     * @return array<string, mixed>
     */
    private static function prepareRoomsAvailabilityData(array $actionState = []): array
    {
        $allowedTabs = ['rooms', 'room-types', 'statuses', 'blocks', 'rules', 'maintenance'];
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];

        if (isset($actionState['rooms_availability_request']) && \is_array($actionState['rooms_availability_request'])) {
            $request = \array_merge($request, $actionState['rooms_availability_request']);
        }

        $activeTab = isset($actionState['active_tab'])
            ? \sanitize_key((string) $actionState['active_tab'])
            : (isset($request['tab']) ? \sanitize_key((string) $request['tab']) : 'rooms');

        if (!\in_array($activeTab, $allowedTabs, true)) {
            $activeTab = 'rooms';
        }

        $data = [
            'active_tab' => $activeTab,
        ];

        if (\in_array($activeTab, ['rooms', 'statuses'], true)) {
            $roomsData = self::prepareRoomsAvailabilityInventoryData($request);
            $housekeepingData = (new HousekeepingAdminDataProvider())->getPageData('room-board');
            $mergedUnitRows = self::mergeRoomsAvailabilityUnitRows(
                isset($roomsData['unit_rows']) && \is_array($roomsData['unit_rows']) ? $roomsData['unit_rows'] : [],
                isset($housekeepingData['board_rows']) && \is_array($housekeepingData['board_rows']) ? $housekeepingData['board_rows'] : []
            );

            if ($activeTab === 'rooms') {
                $roomsData['unit_rows'] = $mergedUnitRows;
                $data['rooms_data'] = $roomsData;
            } else {
                $data['statuses_data'] = [
                    'rows' => $mergedUnitRows,
                    'summary_cards' => self::buildRoomsAvailabilityStatusSummaryCards($mergedUnitRows),
                    'pagination' => isset($roomsData['unit_pagination']) && \is_array($roomsData['unit_pagination']) ? $roomsData['unit_pagination'] : [],
                    'type_options' => isset($roomsData['type_options']) && \is_array($roomsData['type_options']) ? $roomsData['type_options'] : [],
                    'unit_status_options' => isset($roomsData['unit_status_options']) && \is_array($roomsData['unit_status_options']) ? $roomsData['unit_status_options'] : [],
                ];
            }
        }

        if ($activeTab === 'blocks') {
            $data['blocks_data'] = self::prepareRoomsAvailabilityRulesData($request, 'blocked', $actionState);
        }

        if ($activeTab === 'rules') {
            $data['rules_data'] = self::prepareRoomsAvailabilityRulesData($request, 'restriction', $actionState);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function prepareRoomsAvailabilityInventoryData(array $request): array
    {
        $request['tab'] = 'units';
        $query = AccommodationAdminQuery::fromRequest($request);

        return (new AccommodationAdminDataProvider())->getPageData($query, []);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function prepareRoomsAvailabilityRulesData(array $request, string $mode, array $actionState = []): array
    {
        $request['mode'] = $mode;
        $request = self::sanitizeRoomsAvailabilityEditRequest($request, $mode);
        $query = AvailabilityAdminQuery::fromRequest($request);
        $state = [];

        if ($mode === 'blocked') {
            if (isset($actionState['block_form']) && \is_array($actionState['block_form'])) {
                $state['block_form'] = $actionState['block_form'];
            }

            if (isset($actionState['block_errors']) && \is_array($actionState['block_errors'])) {
                $state['block_errors'] = $actionState['block_errors'];
            }
        } else {
            if (isset($actionState['rule_form']) && \is_array($actionState['rule_form'])) {
                $state['rule_form'] = $actionState['rule_form'];
            }

            if (isset($actionState['rule_errors']) && \is_array($actionState['rule_errors'])) {
                $state['rule_errors'] = $actionState['rule_errors'];
            }
        }

        $data = (new AvailabilityAdminDataProvider())->getPageData($query, $state);
        $data['current_action'] = $query->getAction();
        $data['editing_id'] = $mode === 'blocked' ? $query->getBlockId() : $query->getRuleId();
        $data['request'] = $request;

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $unitRows
     * @param array<int, array<string, mixed>> $boardRows
     * @return array<int, array<string, mixed>>
     */
    private static function mergeRoomsAvailabilityUnitRows(array $unitRows, array $boardRows): array
    {
        $boardMap = [];

        foreach ($boardRows as $boardRow) {
            if (!\is_array($boardRow)) {
                continue;
            }

            $roomId = isset($boardRow['id']) ? (int) $boardRow['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $boardMap[$roomId] = $boardRow;
        }

        $mergedRows = [];

        foreach ($unitRows as $unitRow) {
            if (!\is_array($unitRow)) {
                continue;
            }

            $roomId = isset($unitRow['id']) ? (int) $unitRow['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $boardRow = isset($boardMap[$roomId]) && \is_array($boardMap[$roomId]) ? $boardMap[$roomId] : [];
            $inventoryStatus = \sanitize_key((string) ($unitRow['status'] ?? 'available'));
            $isActive = !empty($unitRow['is_active']);
            $housekeepingStatusKey = isset($boardRow['housekeeping_status_key'])
                ? \sanitize_key((string) $boardRow['housekeeping_status_key'])
                : ($isActive ? HousekeepingRepository::STATUS_DIRTY : 'inactive');
            $housekeepingStatusLabel = isset($boardRow['housekeeping_status_label'])
                ? (string) $boardRow['housekeeping_status_label']
                : ($isActive ? \__('Dirty', 'must-hotel-booking') : \__('Not on board', 'must-hotel-booking'));
            $housekeepingNote = isset($boardRow['status_note'])
                ? (string) $boardRow['status_note']
                : ($isActive
                    ? \__('No housekeeping status has been saved for this active room yet.', 'must-hotel-booking')
                    : \__('Inactive inventory units are not included on the housekeeping room board.', 'must-hotel-booking'));
            $reservationContext = isset($boardRow['reservation_context'])
                ? (string) $boardRow['reservation_context']
                : ((int) ($unitRow['current_reservations'] ?? 0) > 0 ? \__('Occupied now', 'must-hotel-booking') : \__('No assigned stay', 'must-hotel-booking'));
            $reservationContextMeta = isset($boardRow['reservation_context_meta'])
                ? (string) $boardRow['reservation_context_meta']
                : (
                    (int) ($unitRow['future_reservations'] ?? 0) > 0 && (string) ($unitRow['next_checkin'] ?? '') !== ''
                        ? \sprintf(
                            /* translators: %s: next check-in date */
                            \__('Next arrival %s', 'must-hotel-booking'),
                            (string) $unitRow['next_checkin']
                        )
                        : \__('No assigned reservation context is attached to this unit right now.', 'must-hotel-booking')
                );

            $mergedRows[] = $unitRow + [
                'inventory_status_key' => $inventoryStatus !== '' ? $inventoryStatus : 'available',
                'inventory_status_label' => (string) ($unitRow['status_label'] ?? ''),
                'housekeeping_status_key' => $housekeepingStatusKey,
                'housekeeping_status_label' => $housekeepingStatusLabel,
                'housekeeping_status_note' => $housekeepingNote,
                'reservation_context' => $reservationContext,
                'reservation_context_meta' => $reservationContextMeta,
                'inventory_constraint' => $inventoryStatus !== '' && $inventoryStatus !== 'available',
            ];
        }

        return $mergedRows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private static function buildRoomsAvailabilityStatusSummaryCards(array $rows): array
    {
        $inventoryConstraints = 0;
        $dirtyCount = 0;
        $cleanCount = 0;
        $inspectedCount = 0;
        $inHouseCount = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if (!empty($row['inventory_constraint'])) {
                $inventoryConstraints++;
            }

            $statusKey = \sanitize_key((string) ($row['housekeeping_status_key'] ?? ''));

            if ($statusKey === HousekeepingRepository::STATUS_DIRTY) {
                $dirtyCount++;
            } elseif ($statusKey === HousekeepingRepository::STATUS_CLEAN) {
                $cleanCount++;
            } elseif ($statusKey === HousekeepingRepository::STATUS_INSPECTED) {
                $inspectedCount++;
            }

            if ((int) ($row['current_reservations'] ?? 0) > 0) {
                $inHouseCount++;
            }
        }

        return [
            [
                'label' => \__('Units in scope', 'must-hotel-booking'),
                'value' => (string) \count($rows),
                'meta' => \__('Physical inventory units visible in the current status view.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Inventory constraints', 'must-hotel-booking'),
                'value' => (string) $inventoryConstraints,
                'meta' => \__('Rooms currently limited by inventory maintenance, blocked, or out-of-service state.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Dirty', 'must-hotel-booking'),
                'value' => (string) $dirtyCount,
                'meta' => \__('Housekeeping board rooms still needing cleaning or first update.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Clean / Inspected', 'must-hotel-booking'),
                'value' => (string) ($cleanCount + $inspectedCount),
                'meta' => \sprintf(
                    /* translators: 1: clean count, 2: inspected count */
                    \__('Clean: %1$d | Inspected: %2$d', 'must-hotel-booking'),
                    $cleanCount,
                    $inspectedCount
                ),
            ],
            [
                'label' => \__('Occupied now', 'must-hotel-booking'),
                'value' => (string) $inHouseCount,
                'meta' => \__('Units currently attached to in-house assigned stays.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * Issues a 301 redirect when the requested route is a deprecated URL that
     * has been superseded by a new module. Exits after redirecting.
     */
    private static function maybeRedirectDeprecatedRoute(): void
    {
        $route = (string) \get_query_var('must_hotel_booking_portal_route', '');
        $route = \trim(\sanitize_text_field($route), '/');

        if ($route === '') {
            return;
        }

        $deprecated = PortalRegistry::getDeprecatedRoutes();

        if (!isset($deprecated[$route])) {
            return;
        }

        $entry     = $deprecated[$route];
        $moduleKey = (string) ($entry['module'] ?? '');
        $args      = isset($entry['args']) && \is_array($entry['args']) ? $entry['args'] : [];
        $targetUrl = PortalRouter::getModuleUrl($moduleKey, $args);

        if ($targetUrl === '') {
            return;
        }

        \wp_redirect($targetUrl, 301);
        exit;
    }

    /**
     * @return array<int, array<string, string|bool>>
     */
    private static function getNavigationItems(): array
    {
        $items = [];
        $currentModule = PortalRouter::getRequestedModuleKey();

        foreach (PortalRegistry::getVisibleDefinitionsForCurrentUser() as $key => $definition) {
            $items[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'icon' => (string) ($definition['icon'] ?? 'dashicons-arrow-right-alt2'),
                'url' => PortalRouter::getModuleUrl($key),
                'current' => $key === $currentModule,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private static function getCurrentUserData(): array
    {
        $user = \wp_get_current_user();
        $roleLabels = StaffAccess::getRoleLabels();
        $roles = $user instanceof \WP_User && \is_array($user->roles) ? $user->roles : [];
        $roleLabel = \__('Administrator', 'must-hotel-booking');

        foreach ($roles as $role) {
            $normalized = \sanitize_key((string) $role);

            if (isset($roleLabels[$normalized])) {
                $roleLabel = $roleLabels[$normalized];
                break;
            }
        }

        return [
            'display_name' => $user instanceof \WP_User ? (string) $user->display_name : '',
            'email' => $user instanceof \WP_User ? (string) $user->user_email : '',
            'role_label' => $roleLabel,
            'hotel_name' => MustBookingConfig::get_hotel_name(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getBrandingData(): array
    {
        $portalLogo = (string) MustBookingConfig::get_setting('portal_logo_url', '');
        $hotelLogo = (string) MustBookingConfig::get_setting('hotel_logo_url', '');
        $primaryColor = (string) MustBookingConfig::get_setting('primary_color', '#0f766e');
        $secondaryColor = (string) MustBookingConfig::get_setting('secondary_color', '#155e75');
        $accentColor = (string) MustBookingConfig::get_setting('accent_color', '#f59e0b');
        $textColor = (string) MustBookingConfig::get_setting('text_color', '#16212b');

        return [
            'hotel_name' => MustBookingConfig::get_hotel_name(),
            'logo_url' => $portalLogo !== '' ? $portalLogo : $hotelLogo,
            'welcome_title' => (string) MustBookingConfig::get_setting('portal_welcome_title', \__('Welcome back', 'must-hotel-booking')),
            'welcome_text' => (string) MustBookingConfig::get_setting('portal_welcome_text', ''),
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
            'accent_color' => $accentColor,
            'text_color' => $textColor,
            'border_radius' => (string) (int) MustBookingConfig::get_setting('border_radius', 18),
            'font_family' => (string) MustBookingConfig::get_setting('font_family', 'Instrument Sans'),
            'portal_page_url' => ManagedPages::getPortalPageUrl(),
        ];
    }

    /**
     * @param array<string, mixed> $actionState
     * @return array<int, string>
     */
    private static function getNotices(array $actionState): array
    {
        $notices = isset($actionState['notices']) && \is_array($actionState['notices']) ? $actionState['notices'] : [];
        $noticeKey = isset($_GET['portal_notice']) ? \sanitize_key((string) \wp_unslash($_GET['portal_notice'])) : '';
        $messages = [
            'quick_booking_created' => \__('Reservation created from the Front Desk booking flow.', 'must-hotel-booking'),
            'reservation_confirmed' => \__('Reservation confirmed.', 'must-hotel-booking'),
            'reservation_cancelled' => \__('Reservation cancelled.', 'must-hotel-booking'),
            'reservation_checked_in' => \__('Guest checked in.', 'must-hotel-booking'),
            'reservation_checked_out' => \__('Guest checked out.', 'must-hotel-booking'),
            'reservation_room_assigned' => \__('Room assignment updated.', 'must-hotel-booking'),
            'reservation_stay_updated' => \__('Stay dates updated successfully.', 'must-hotel-booking'),
            'reservation_stay_updated_room_unassigned' => \__('Stay dates updated. The previous room assignment was cleared because it no longer matched the updated stay window.', 'must-hotel-booking'),
            'reservation_marked_no_show' => \__('Reservation marked as no-show and cancelled.', 'must-hotel-booking'),
            'reservation_note_added' => \__('Internal note added.', 'must-hotel-booking'),
            'reservation_marked_pending' => \__('Reservation moved back to pending.', 'must-hotel-booking'),
            'reservation_marked_paid' => \__('Reservation marked as paid.', 'must-hotel-booking'),
            'reservation_marked_unpaid' => \__('Reservation marked as unpaid.', 'must-hotel-booking'),
            'reservation_guest_updated' => \__('Guest details updated successfully.', 'must-hotel-booking'),
            'reservation_updated' => \__('Reservation source updated successfully.', 'must-hotel-booking'),
            'guest_contact_updated' => \__('Guest contact details updated.', 'must-hotel-booking'),
            'guest_flags_updated' => \__('Guest flags updated.', 'must-hotel-booking'),
            'guest_note_added' => \__('Guest service note added.', 'must-hotel-booking'),
            'cancellation_request_submitted' => \__('Cancellation request submitted for approval.', 'must-hotel-booking'),
            'cancellation_request_approved' => \__('Cancellation request approved and reservation cancelled.', 'must-hotel-booking'),
            'cancellation_request_rejected' => \__('Cancellation request rejected.', 'must-hotel-booking'),
            'reservation_guest_email_resent' => \__('Guest reservation email resent.', 'must-hotel-booking'),
            'reservation_admin_email_resent' => \__('Admin reservation email resent.', 'must-hotel-booking'),
            'payment_posted' => \__('Payment posted successfully.', 'must-hotel-booking'),
            'payment_partially_posted' => \__('Partial payment posted successfully.', 'must-hotel-booking'),
            'payment_marked_paid' => \__('Payment marked as paid.', 'must-hotel-booking'),
            'payment_marked_unpaid' => \__('Payment marked as unpaid.', 'must-hotel-booking'),
            'payment_marked_pending' => \__('Payment moved back to pending.', 'must-hotel-booking'),
            'payment_marked_pay_at_hotel' => \__('Payment collection mode set to pay at hotel.', 'must-hotel-booking'),
            'payment_marked_failed' => \__('Payment marked as failed.', 'must-hotel-booking'),
            'payment_refunded' => \__('Refund issued successfully.', 'must-hotel-booking'),
            'calendar_block_created' => \__('Manual block created from the calendar.', 'must-hotel-booking'),
            'block_updated' => \__('Manual block updated.', 'must-hotel-booking'),
            'block_saved_with_conflict' => \__('Manual block updated, but it still overlaps existing reservation activity.', 'must-hotel-booking'),
            'block_deleted' => \__('Manual block released.', 'must-hotel-booking'),
            'block_delete_failed' => \__('Unable to release the selected manual block.', 'must-hotel-booking'),
            'rule_updated' => \__('Availability rule updated.', 'must-hotel-booking'),
            'rule_saved_with_conflict' => \__('Availability rule updated, but it overlaps existing reservation activity.', 'must-hotel-booking'),
            'rule_deleted' => \__('Availability rule removed.', 'must-hotel-booking'),
            'rule_delete_failed' => \__('Unable to remove the selected availability rule.', 'must-hotel-booking'),
            'rule_activated' => \__('Availability rule activated.', 'must-hotel-booking'),
            'rule_deactivated' => \__('Availability rule deactivated.', 'must-hotel-booking'),
            'rule_update_failed' => \__('Unable to update the selected availability rule.', 'must-hotel-booking'),
            'housekeeping_marked_dirty' => \__('Room marked dirty.', 'must-hotel-booking'),
            'housekeeping_marked_clean' => \__('Room marked clean.', 'must-hotel-booking'),
            'housekeeping_marked_inspected' => \__('Room marked inspected.', 'must-hotel-booking'),
            'housekeeping_assignment_updated' => \__('Housekeeping assignment updated.', 'must-hotel-booking'),
            'housekeeping_issue_created' => \__('Maintenance issue created.', 'must-hotel-booking'),
            'housekeeping_issue_updated' => \__('Maintenance issue updated.', 'must-hotel-booking'),
            'housekeeping_handoff_created' => \__('Shift handoff saved.', 'must-hotel-booking'),
            'invalid_nonce' => \__('Security check failed. Please try again.', 'must-hotel-booking'),
            'invalid_guest_email' => \__('Please enter a valid guest email address.', 'must-hotel-booking'),
            'guest_not_found' => \__('Guest record not found.', 'must-hotel-booking'),
            'reservation_not_found' => \__('Reservation not found.', 'must-hotel-booking'),
            'housekeeping_room_not_found' => \__('Room record not found.', 'must-hotel-booking'),
            'housekeeping_issue_not_found' => \__('Maintenance issue not found.', 'must-hotel-booking'),
            'housekeeping_assignment_invalid' => \__('That housekeeping assignment is not valid for the selected room or user.', 'must-hotel-booking'),
            'housekeeping_status_invalid' => \__('That housekeeping status change is not available from the current room state.', 'must-hotel-booking'),
            'reservation_wrong_status' => \__('That action is not available for the reservation in its current status.', 'must-hotel-booking'),
            'reservation_already_checked_in' => \__('This reservation is already checked in.', 'must-hotel-booking'),
            'access_denied' => \__('You do not have permission for that portal action.', 'must-hotel-booking'),
            'portal_action_failed' => \__('The requested portal action could not be completed.', 'must-hotel-booking'),
        ];

        if ($noticeKey !== '' && isset($messages[$noticeKey])) {
            $notices[] = $messages[$noticeKey];
        }

        return $notices;
    }
}
