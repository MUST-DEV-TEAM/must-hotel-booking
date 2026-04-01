<?php

namespace MustHotelBooking\Portal;

use MustHotelBooking\Admin\AccommodationAdminDataProvider;
use MustHotelBooking\Admin\AccommodationAdminQuery;
use MustHotelBooking\Admin\AvailabilityAdminDataProvider;
use MustHotelBooking\Admin\AvailabilityAdminQuery;
use MustHotelBooking\Admin\CalendarDataProvider;
use MustHotelBooking\Admin\CalendarViewQuery;
use MustHotelBooking\Admin\DashboardDataProvider;
use MustHotelBooking\Admin\GuestAdminDataProvider;
use MustHotelBooking\Admin\GuestAdminQuery;
use MustHotelBooking\Admin\PaymentAdminActions;
use MustHotelBooking\Admin\PaymentAdminDataProvider;
use MustHotelBooking\Admin\PaymentAdminQuery;
use MustHotelBooking\Admin\ReportAdminDataProvider;
use MustHotelBooking\Admin\ReportAdminQuery;
use MustHotelBooking\Admin\ReservationAdminDataProvider;
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
            self::handleReservationTransition('confirm', StaffAccess::CAP_EDIT_RESERVATIONS);
        }

        if ($action === 'reservation_cancel') {
            self::handleReservationTransition('cancel', StaffAccess::CAP_CANCEL_RESERVATION);
        }

        if ($action === 'reservation_mark_pending') {
            self::handleReservationPaymentStateChange('mark_pending');
        }

        if ($action === 'reservation_mark_paid') {
            self::handleReservationPaymentStateChange('mark_paid');
        }

        if ($action === 'reservation_mark_unpaid') {
            self::handleReservationPaymentStateChange('mark_unpaid');
        }

        if ($action === 'reservation_resend_guest_email') {
            self::handleReservationEmailAction('resend_guest_email');
        }

        if ($action === 'reservation_resend_admin_email') {
            self::handleReservationEmailAction('resend_admin_email');
        }

        if ($action === 'reservation_save_guest') {
            self::handleReservationGuestSave();
        }

        if ($action === 'reservation_save_admin_details') {
            self::handleReservationAdminDetailsSave();
        }

        if ($action === 'payment_mark_paid') {
            self::handlePaymentMarkPaid();
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
        if (!StaffAccess::userCanAccessPortalModule('quick_booking')) {
            return [
                'module_key' => 'quick_booking',
                'errors' => [\__('You do not have permission to create quick bookings.', 'must-hotel-booking')],
            ];
        }

        $nonce = isset($_POST['must_portal_quick_booking_nonce']) ? (string) \wp_unslash($_POST['must_portal_quick_booking_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_portal_quick_booking')) {
            return [
                'module_key' => 'quick_booking',
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $form = \MustHotelBooking\Admin\sanitize_admin_quick_booking_form_values($rawPost);
        $errors = isset($form['errors']) && \is_array($form['errors']) ? $form['errors'] : [];

        if (!empty($errors)) {
            return [
                'module_key' => 'quick_booking',
                'errors' => $errors,
                'form' => $form,
            ];
        }

        $reservationId = \MustHotelBooking\Admin\create_admin_quick_booking_reservation($form);

        if ($reservationId <= 0) {
            return [
                'module_key' => 'quick_booking',
                'errors' => [\__('Unable to create the reservation from the submitted quick booking.', 'must-hotel-booking')],
                'form' => $form,
            ];
        }

        \wp_safe_redirect(
            PortalRouter::getModuleUrl(
                'reservations',
                [
                    'reservation_id' => $reservationId,
                    'portal_notice' => 'quick_booking_created',
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
                BookingStatusEngine::updateReservationStatuses([$reservationId], 'cancelled', $paymentStatus);
                $notice = 'reservation_cancelled';
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

        if (!\current_user_can(StaffAccess::CAP_MARK_PAYMENT_AS_PAID) && !\current_user_can('manage_options')) {
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

        if (!\current_user_can(StaffAccess::CAP_EDIT_RESERVATIONS) && !\current_user_can('manage_options')) {
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

        if (!\current_user_can(StaffAccess::CAP_EDIT_RESERVATIONS) && !\current_user_can('manage_options')) {
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

        if (!\current_user_can(StaffAccess::CAP_EDIT_RESERVATIONS) && !\current_user_can('manage_options')) {
            self::redirectToPortalReservationDetail($reservationId, 'access_denied');
        }

        $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservation = $reservationRepository->getReservation($reservationId);

        if (!\is_array($reservation)) {
            self::redirectToPortalReservationDetail($reservationId, 'reservation_not_found');
        }

        $bookingSource = isset($_POST['booking_source']) ? \sanitize_key((string) \wp_unslash($_POST['booking_source'])) : 'website';
        $notes = isset($_POST['notes']) ? \sanitize_textarea_field((string) \wp_unslash($_POST['notes'])) : '';
        $sourceOptions = \function_exists('\MustHotelBooking\Admin\get_admin_quick_booking_source_options')
            ? \MustHotelBooking\Admin\get_admin_quick_booking_source_options()
            : [];

        if (!isset($sourceOptions[$bookingSource])) {
            $bookingSource = isset($reservation['booking_source']) ? (string) $reservation['booking_source'] : 'website';
        }

        $updated = $reservationRepository->updateReservation(
            $reservationId,
            [
                'booking_source' => $bookingSource,
                'notes' => $notes,
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
            \__('Reservation notes and source updated.', 'must-hotel-booking')
        );

        self::redirectToPortalReservationDetail($reservationId, 'reservation_updated');
    }

    private static function handlePaymentMarkPaid(): void
    {
        $reservationId = isset($_POST['reservation_id']) ? \absint(\wp_unslash($_POST['reservation_id'])) : 0;
        $nonce = isset($_POST['must_portal_payment_nonce']) ? (string) \wp_unslash($_POST['must_portal_payment_nonce']) : '';

        if ($reservationId <= 0 || !\wp_verify_nonce($nonce, 'must_portal_payment_action_mark_paid_' . $reservationId)) {
            \wp_safe_redirect(PortalRouter::getModuleUrl('payments', ['portal_notice' => 'invalid_nonce']));
            exit;
        }

        if (!\current_user_can(StaffAccess::CAP_MARK_PAYMENT_AS_PAID) && !\current_user_can('manage_options')) {
            \wp_safe_redirect(PortalRouter::getModuleUrl('payments', ['reservation_id' => $reservationId, 'portal_notice' => 'access_denied']));
            exit;
        }

        $success = (new PaymentAdminActions())->applyAdminPaymentAction($reservationId, 'mark_paid');

        \wp_safe_redirect(
            PortalRouter::getModuleUrl(
                'payments',
                [
                    'reservation_id' => $reservationId,
                    'portal_notice' => $success ? 'payment_marked_paid' : 'portal_action_failed',
                ]
            )
        );
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
            return self::prepareReservationsData();
        }

        if ($moduleKey === 'calendar') {
            return self::prepareCalendarData();
        }

        if ($moduleKey === 'quick_booking') {
            return self::prepareQuickBookingData($actionState);
        }

        if ($moduleKey === 'guests') {
            return self::prepareGuestsData();
        }

        if ($moduleKey === 'payments') {
            return self::preparePaymentsData();
        }

        if ($moduleKey === 'reports') {
            return self::prepareReportsData();
        }

        if ($moduleKey === 'accommodations') {
            return self::prepareAccommodationsData();
        }

        if ($moduleKey === 'availability_rules') {
            return self::prepareAvailabilityRulesData();
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
                $linkUrl = PortalRouter::getModuleUrl('availability_rules');
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
        foreach (['attention_items', 'recent_activity'] as $key) {
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

        if ($page === 'must-hotel-booking-availability-rules' && StaffAccess::userCanAccessPortalModule('availability_rules')) {
            return PortalRouter::getModuleUrl('availability_rules', self::filterPortalModuleArgs($queryArgs, ['room_id']));
        }

        if ($page === 'must-hotel-booking-rooms' && StaffAccess::userCanAccessPortalModule('accommodations')) {
            return PortalRouter::getModuleUrl('accommodations');
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
            'reservations' => \__('Review arrivals, departures, and booking status changes.', 'must-hotel-booking'),
            'calendar' => \__('Check room occupancy, availability, and date conflicts.', 'must-hotel-booking'),
            'quick_booking' => \__('Create a reservation quickly from the front desk.', 'must-hotel-booking'),
            'guests' => \__('Search guest profiles, stay history, and contact details.', 'must-hotel-booking'),
            'payments' => \__('Follow unpaid balances, Stripe issues, and pay-at-hotel collections.', 'must-hotel-booking'),
            'reports' => \__('Open revenue and operational reporting views.', 'must-hotel-booking'),
            'accommodations' => \__('Review room types, units, and setup details.', 'must-hotel-booking'),
            'availability_rules' => \__('Inspect blocks, maintenance windows, and sellable inventory.', 'must-hotel-booking'),
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
    private static function prepareReservationsData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $provider = new ReservationAdminDataProvider();
        $data = $provider->getListPageData($request);
        $reservationId = isset($_GET['reservation_id']) ? \absint(\wp_unslash($_GET['reservation_id'])) : 0;
        $mode = isset($_GET['mode']) ? \sanitize_key((string) \wp_unslash($_GET['mode'])) : 'view';
        $data['detail'] = $reservationId > 0 ? $provider->getDetailPageData($reservationId, $mode) : null;
        $data['source_options'] = \function_exists('\MustHotelBooking\Admin\get_admin_quick_booking_source_options')
            ? \MustHotelBooking\Admin\get_admin_quick_booking_source_options()
            : [];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareCalendarData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = CalendarViewQuery::fromRequest($request);
        $data = (new CalendarDataProvider())->getPageData($query);
        $summary = isset($data['summary']) && \is_array($data['summary']) ? $data['summary'] : [];
        $data['summary'] = [
            ['label' => \__('Occupancy today', 'must-hotel-booking'), 'value' => (string) ((int) ($summary['occupancy_today'] ?? 0)) . '%', 'meta' => \__('Booked units across visible inventory.', 'must-hotel-booking')],
            ['label' => \__('Booked today', 'must-hotel-booking'), 'value' => (string) (int) ($summary['booked_today'] ?? 0), 'meta' => \__('Units currently occupied today.', 'must-hotel-booking')],
            ['label' => \__('Available today', 'must-hotel-booking'), 'value' => (string) (int) ($summary['available_today'] ?? 0), 'meta' => \__('Units available for sale today.', 'must-hotel-booking')],
            ['label' => \__('Blocked today', 'must-hotel-booking'), 'value' => (string) (int) ($summary['blocked_today'] ?? 0), 'meta' => \__('Maintenance, blocks, or unavailable units.', 'must-hotel-booking')],
        ];

        return $data;
    }

    /**
     * @param array<string, mixed> $actionState
     * @return array<string, mixed>
     */
    private static function prepareQuickBookingData(array $actionState): array
    {
        $defaults = \MustHotelBooking\Admin\get_admin_quick_booking_form_defaults();
        $form = isset($actionState['form']) && \is_array($actionState['form']) ? $actionState['form'] : $defaults;
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
            'source_options' => \MustHotelBooking\Admin\get_admin_quick_booking_source_options(),
            'estimate' => $estimate,
            'currency' => MustBookingConfig::get_currency(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareGuestsData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = GuestAdminQuery::fromRequest($request);

        return (new GuestAdminDataProvider())->getPageData(
            $query,
            [
                'errors' => [],
                'form' => null,
                'selected_guest_id' => $query->getGuestId(),
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function preparePaymentsData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = PaymentAdminQuery::fromRequest($request);

        return (new PaymentAdminDataProvider())->getPageData(
            $query,
            [
                'errors' => [],
                'settings_errors' => [],
            ]
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
        $bookingId = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';
        $reference = $bookingId !== '' ? $bookingId : ('RES-' . $reservationId);

        \MustHotelBooking\Engine\get_activity_repository()->createActivity(
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

    private static function redirectToPortalReservationDetail(int $reservationId, string $notice): void
    {
        $args = self::getPortalReservationRedirectArgs($reservationId);

        if ($notice !== '') {
            $args['portal_notice'] = $notice;
        }

        \wp_safe_redirect(PortalRouter::getModuleUrl('reservations', $args));
        exit;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareReportsData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = ReportAdminQuery::fromRequest($request);

        return (new ReportAdminDataProvider())->getPageData($query);
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareAccommodationsData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = AccommodationAdminQuery::fromRequest($request);

        return (new AccommodationAdminDataProvider())->getPageData($query, []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareAvailabilityRulesData(): array
    {
        /** @var array<string, mixed> $request */
        $request = \is_array($_GET) ? \wp_unslash($_GET) : [];
        $query = AvailabilityAdminQuery::fromRequest($request);

        return (new AvailabilityAdminDataProvider())->getPageData($query, []);
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
            'quick_booking_created' => \__('Reservation created from quick booking.', 'must-hotel-booking'),
            'reservation_confirmed' => \__('Reservation confirmed.', 'must-hotel-booking'),
            'reservation_cancelled' => \__('Reservation cancelled.', 'must-hotel-booking'),
            'reservation_marked_pending' => \__('Reservation moved back to pending.', 'must-hotel-booking'),
            'reservation_marked_paid' => \__('Reservation marked as paid.', 'must-hotel-booking'),
            'reservation_marked_unpaid' => \__('Reservation marked as unpaid.', 'must-hotel-booking'),
            'reservation_guest_updated' => \__('Guest details updated successfully.', 'must-hotel-booking'),
            'reservation_updated' => \__('Reservation notes updated successfully.', 'must-hotel-booking'),
            'reservation_guest_email_resent' => \__('Guest reservation email resent.', 'must-hotel-booking'),
            'reservation_admin_email_resent' => \__('Admin reservation email resent.', 'must-hotel-booking'),
            'payment_marked_paid' => \__('Payment marked as paid.', 'must-hotel-booking'),
            'invalid_nonce' => \__('Security check failed. Please try again.', 'must-hotel-booking'),
            'invalid_guest_email' => \__('Please enter a valid guest email address.', 'must-hotel-booking'),
            'reservation_not_found' => \__('Reservation not found.', 'must-hotel-booking'),
            'access_denied' => \__('You do not have permission for that portal action.', 'must-hotel-booking'),
            'portal_action_failed' => \__('The requested portal action could not be completed.', 'must-hotel-booking'),
        ];

        if ($noticeKey !== '' && isset($messages[$noticeKey])) {
            $notices[] = $messages[$noticeKey];
        }

        return $notices;
    }
}
