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
            \wp_safe_redirect(PortalRouter::getModuleUrl($moduleKey, ['reservation_id' => $reservationId, 'portal_notice' => 'access_denied']));
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

        \wp_safe_redirect(
            PortalRouter::getModuleUrl(
                $moduleKey,
                [
                    'reservation_id' => $reservationId,
                    'portal_notice' => $notice,
                ]
            )
        );
        exit;
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
        $quickActions = [];

        foreach (PortalRegistry::getVisibleDefinitionsForCurrentUser() as $key => $definition) {
            if ($key === 'dashboard') {
                continue;
            }

            $quickActions[] = [
                'label' => (string) ($definition['label'] ?? $key),
                'url' => PortalRouter::getModuleUrl($key),
                'icon' => (string) ($definition['icon'] ?? 'dashicons-arrow-right-alt2'),
            ];
        }

        $data['quick_actions'] = $quickActions;

        return $data;
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
            'payment_marked_paid' => \__('Payment marked as paid.', 'must-hotel-booking'),
            'invalid_nonce' => \__('Security check failed. Please try again.', 'must-hotel-booking'),
            'access_denied' => \__('You do not have permission for that portal action.', 'must-hotel-booking'),
            'portal_action_failed' => \__('The requested portal action could not be completed.', 'must-hotel-booking'),
        ];

        if ($noticeKey !== '' && isset($messages[$noticeKey])) {
            $notices[] = $messages[$noticeKey];
        }

        return $notices;
    }
}
