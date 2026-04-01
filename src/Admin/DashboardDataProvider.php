<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\PaymentEngine;

final class DashboardDataProvider
{
    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservationRepository;

    /** @var \MustHotelBooking\Database\PaymentRepository */
    private $paymentRepository;

    /** @var \MustHotelBooking\Database\RoomRepository */
    private $roomRepository;

    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventoryRepository;

    /** @var \MustHotelBooking\Database\AvailabilityRepository */
    private $availabilityRepository;

    /** @var \MustHotelBooking\Database\RatePlanRepository */
    private $ratePlanRepository;

    /** @var \MustHotelBooking\Database\ActivityRepository */
    private $activityRepository;

    public function __construct()
    {
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $this->ratePlanRepository = \MustHotelBooking\Engine\get_rate_plan_repository();
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardData(): array
    {
        $today = \current_time('Y-m-d');
        $now = new \DateTimeImmutable(\current_time('mysql'));
        $currency = MustBookingConfig::get_currency();
        $summary = $this->reservationRepository->getDashboardOperationalSummary($today);
        $legacyRoomCount = $this->roomRepository->countRooms();
        $inventoryRoomCount = $this->inventoryRepository->countInventoryRooms();
        $unitCount = $inventoryRoomCount > 0 ? $inventoryRoomCount : $legacyRoomCount;
        $maintenanceBlocks = $this->availabilityRepository->countActiveMaintenanceBlocks($today);
        $inventoryUnavailable = $this->inventoryRepository->countUnavailableInventoryRooms();
        $revenueAmount = $this->paymentRepository->getRevenueReceivedForDate($today);
        $revenueDescriptor = \__('Payments received today.', 'must-hotel-booking');

        if (!$this->paymentRepository->paymentsTableExists()) {
            $revenueAmount = 0.0;
            $revenueDescriptor = \__('Payments table is missing, so revenue currently falls back to 0.', 'must-hotel-booking');
        }

        $blockedUnits = $summary['blocked_units'] + $maintenanceBlocks;

        if ($inventoryRoomCount > 0) {
            /*
             * Temporary fallback while manual room blocks and physical room statuses are still
             * tracked in separate data structures.
             */
            $blockedUnits += $inventoryUnavailable;
        }

        $occupancyPercent = $unitCount > 0
            ? (int) \round(\min(100, ($summary['occupied_units'] / $unitCount) * 100))
            : 0;

        return [
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'kpis' => $this->buildKpis($summary, $blockedUnits, $occupancyPercent, $unitCount, $currency, $revenueAmount, $revenueDescriptor),
            'attention_items' => $this->buildAttentionItems($today, $now),
            'health_items' => $this->buildHealthItems(),
            'recent_reservations' => $this->buildRecentReservations($currency),
            'recent_activity' => $this->buildRecentActivity(),
            'quick_actions' => $this->buildQuickActions(),
        ];
    }

    /**
     * @param array<string, int> $summary
     * @return array<int, array<string, string>>
     */
    private function buildKpis(
        array $summary,
        int $blockedUnits,
        int $occupancyPercent,
        int $unitCount,
        string $currency,
        float $revenueAmount,
        string $revenueDescriptor
    ): array {
        $inHouseDescriptor = $summary['in_house_stays'] > 0
            ? \sprintf(
                \_n('%d active stay.', '%d active stays.', $summary['in_house_stays'], 'must-hotel-booking'),
                $summary['in_house_stays']
            )
            : \__('No stays in house.', 'must-hotel-booking');

        return [
            [
                'key' => 'arrivals_today',
                'label' => \__('Arrivals Today', 'must-hotel-booking'),
                'value' => (string) $summary['arrivals_reservations'],
                'descriptor' => $this->formatGuestCountDescriptor($summary['arrivals_guests']),
                'url' => get_admin_reservations_page_url(['preset' => 'arrivals_today']),
            ],
            [
                'key' => 'departures_today',
                'label' => \__('Departures Today', 'must-hotel-booking'),
                'value' => (string) $summary['departures_reservations'],
                'descriptor' => $this->formatGuestCountDescriptor($summary['departures_guests']),
                'url' => get_admin_reservations_page_url(['preset' => 'departures_today']),
            ],
            [
                'key' => 'in_house_today',
                'label' => \__('In-House Guests', 'must-hotel-booking'),
                'value' => (string) $summary['in_house_guests'],
                'descriptor' => $inHouseDescriptor,
                'url' => get_admin_reservations_page_url(['preset' => 'in_house_today']),
            ],
            [
                'key' => 'pending_reservations',
                'label' => \__('Pending Reservations', 'must-hotel-booking'),
                'value' => (string) $summary['pending_reservations'],
                'descriptor' => \__('Awaiting confirmation or payment.', 'must-hotel-booking'),
                'url' => get_admin_reservations_page_url(['preset' => 'pending']),
            ],
            [
                'key' => 'unpaid_reservations',
                'label' => \__('Unpaid Reservations', 'must-hotel-booking'),
                'value' => (string) $summary['unpaid_reservations'],
                'descriptor' => \__('Not fully paid yet.', 'must-hotel-booking'),
                'url' => get_admin_payments_page_url(['payment_group' => 'due']),
            ],
            [
                'key' => 'occupancy_today',
                'label' => \__('Occupancy Today', 'must-hotel-booking'),
                'value' => $occupancyPercent . '%',
                'descriptor' => $unitCount > 0
                    ? \sprintf(\__('%1$d / %2$d units occupied.', 'must-hotel-booking'), (int) $summary['occupied_units'], $unitCount)
                    : \__('No active units configured yet.', 'must-hotel-booking'),
                'url' => get_admin_calendar_page_url(['start_date' => \current_time('Y-m-d'), 'weeks' => 2]),
            ],
            [
                'key' => 'revenue_today',
                'label' => \__('Revenue Today', 'must-hotel-booking'),
                'value' => $this->formatMoney($revenueAmount, $currency),
                'descriptor' => $revenueDescriptor,
                'url' => get_admin_payments_page_url(),
            ],
            [
                'key' => 'blocked_units',
                'label' => \__('Blocked / Unavailable Units', 'must-hotel-booking'),
                'value' => (string) $blockedUnits,
                'descriptor' => \__('Manual blocks, maintenance, and room status issues.', 'must-hotel-booking'),
                'url' => get_admin_availability_rules_page_url(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildAttentionItems(string $today, \DateTimeImmutable $now): array
    {
        $items = [];

        foreach ($this->reservationRepository->getAdminReservationListRows(['status_group' => 'pending', 'limit' => 3]) as $reservation) {
            $items[] = $this->makeAttentionItem(
                'warning',
                \__('Pending reservation', 'must-hotel-booking'),
                \__('Reservation still needs confirmation or payment completion.', 'must-hotel-booking'),
                $this->formatReservationReference($reservation),
                $this->getReservationViewUrl((int) ($reservation['id'] ?? 0))
            );
        }

        foreach ($this->reservationRepository->getReservationsMissingPaymentRecords(3) as $reservation) {
            $items[] = $this->makeAttentionItem(
                'error',
                \__('Missing payment record', 'must-hotel-booking'),
                \__('Reservation total exists, but no payment ledger row was found.', 'must-hotel-booking'),
                $this->formatReservationReference($reservation),
                get_admin_payments_page_url(['action' => 'view', 'reservation_id' => (int) ($reservation['id'] ?? 0)])
            );
        }

        $stripeCutoff = $now->modify('-30 minutes')->format('Y-m-d H:i:s');

        foreach ($this->paymentRepository->getStripeIssueRows($stripeCutoff, 3) as $payment) {
            $reference = $this->formatReservationReference(
                [
                    'id' => isset($payment['reservation_id']) ? (int) $payment['reservation_id'] : 0,
                    'booking_id' => isset($payment['booking_id']) ? (string) $payment['booking_id'] : '',
                ]
            );
            $status = (string) ($payment['status'] ?? '');
            $items[] = $this->makeAttentionItem(
                'error',
                $status === 'failed' ? \__('Failed Stripe payment', 'must-hotel-booking') : \__('Incomplete Stripe payment', 'must-hotel-booking'),
                $status === 'failed'
                    ? \__('Stripe reported a failed payment for this reservation.', 'must-hotel-booking')
                    : \__('Stripe payment has remained pending longer than expected.', 'must-hotel-booking'),
                $reference,
                get_admin_payments_page_url(['action' => 'view', 'reservation_id' => (int) ($payment['reservation_id'] ?? 0)])
            );
        }

        foreach ($this->reservationRepository->getPotentialConflictRows(3) as $conflict) {
            $leftReference = $this->formatReservationReference(
                [
                    'id' => isset($conflict['reservation_id_left']) ? (int) $conflict['reservation_id_left'] : 0,
                    'booking_id' => isset($conflict['booking_id_left']) ? (string) $conflict['booking_id_left'] : '',
                ]
            );
            $rightReference = $this->formatReservationReference(
                [
                    'id' => isset($conflict['reservation_id_right']) ? (int) $conflict['reservation_id_right'] : 0,
                    'booking_id' => isset($conflict['booking_id_right']) ? (string) $conflict['booking_id_right'] : '',
                ]
            );
            $items[] = $this->makeAttentionItem(
                'error',
                \__('Possible booking conflict', 'must-hotel-booking'),
                \sprintf(\__('Overlapping reservations were detected for %s.', 'must-hotel-booking'), (string) ($conflict['room_name'] ?? \__('Unknown room', 'must-hotel-booking'))),
                $leftReference . ' / ' . $rightReference,
                get_admin_reservations_page_url()
            );
        }

        foreach ($this->reservationRepository->getArrivalsWithIncompleteGuestDetails($today, 3) as $reservation) {
            $items[] = $this->makeAttentionItem(
                'warning',
                \__('Arrival missing guest details', 'must-hotel-booking'),
                \__('Check-in is today, but guest contact details are incomplete.', 'must-hotel-booking'),
                $this->formatReservationReference($reservation),
                $this->getReservationEditUrl((int) ($reservation['id'] ?? 0))
            );
        }

        foreach ($this->reservationRepository->getStaleActiveReservationsPastCheckout($today, 3) as $reservation) {
            $items[] = $this->makeAttentionItem(
                'error',
                \__('Reservation active after checkout', 'must-hotel-booking'),
                \__('Checkout date has passed, but the reservation still has an active status.', 'must-hotel-booking'),
                $this->formatReservationReference($reservation),
                $this->getReservationEditUrl((int) ($reservation['id'] ?? 0))
            );
        }

        foreach ($this->roomRepository->getRoomsMissingBasePrice(3) as $room) {
            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $items[] = $this->makeAttentionItem(
                'warning',
                \__('Accommodation missing pricing', 'must-hotel-booking'),
                \__('Base price is still 0.00 on this accommodation.', 'must-hotel-booking'),
                (string) ($room['name'] ?? ''),
                $roomId > 0
                    ? get_admin_pricing_page_url(['room_id' => $roomId, 'setup' => 'missing_pricing'])
                    : get_admin_pricing_page_url(['setup' => 'missing_pricing'])
            );
        }

        foreach ($this->ratePlanRepository->getRoomTypesMissingPricing(3) as $roomType) {
            $roomTypeId = isset($roomType['id']) ? (int) $roomType['id'] : 0;
            $items[] = $this->makeAttentionItem(
                'warning',
                \__('Accommodation missing rate pricing', 'must-hotel-booking'),
                \__('No active rate plan or fallback base price is available for this room type.', 'must-hotel-booking'),
                (string) ($roomType['name'] ?? ''),
                $roomTypeId > 0
                    ? get_admin_pricing_page_url(['room_id' => $roomTypeId, 'setup' => 'missing_pricing'])
                    : get_admin_pricing_page_url(['setup' => 'missing_pricing'])
            );
        }

        foreach ($this->inventoryRepository->getRoomTypesMissingInventory(3) as $roomType) {
            $roomTypeId = isset($roomType['id']) ? (int) $roomType['id'] : 0;
            $items[] = $this->makeAttentionItem(
                'warning',
                \__('Accommodation missing availability setup', 'must-hotel-booking'),
                \__('Room type exists without any physical inventory rooms assigned.', 'must-hotel-booking'),
                (string) ($roomType['name'] ?? ''),
                $roomTypeId > 0
                    ? get_admin_availability_rules_page_url(['room_id' => $roomTypeId])
                    : get_admin_availability_rules_page_url()
            );
        }

        foreach ($this->activityRepository->getRecentActivitiesByEventTypes(['email_failed'], 3) as $activity) {
            $context = $this->decodeContext((string) ($activity['context_json'] ?? ''));
            $reservationId = isset($context['reservation_id']) ? (int) $context['reservation_id'] : 0;
            $items[] = $this->makeAttentionItem(
                'error',
                \__('Failed email send', 'must-hotel-booking'),
                (string) ($activity['message'] ?? \__('A booking email failed to send.', 'must-hotel-booking')),
                (string) ($activity['reference'] ?? ''),
                $reservationId > 0 ? $this->getReservationViewUrl($reservationId) : get_admin_emails_page_url()
            );
        }

        if ($this->availabilityRepository->availabilityTableExists() && $this->availabilityRepository->countAvailabilityRules() === 0) {
            $items[] = $this->makeAttentionItem(
                'warning',
                \__('Availability rules not configured', 'must-hotel-booking'),
                \__('No availability rules or date restrictions have been configured yet.', 'must-hotel-booking'),
                '',
                get_admin_availability_rules_page_url()
            );
        }

        if (\in_array('stripe', PaymentMethodRegistry::getEnabled(), true) && !PaymentEngine::isStripeCheckoutConfigured()) {
            $items[] = $this->makeAttentionItem(
                'error',
                \__('Stripe keys missing', 'must-hotel-booking'),
                \__('Stripe is enabled, but the active environment keys are incomplete.', 'must-hotel-booking'),
                PaymentEngine::getSiteEnvironmentLabel(),
                get_admin_payments_page_url()
            );
        }

        if (\in_array('stripe', PaymentMethodRegistry::getEnabled(), true) && PaymentEngine::getStripeWebhookSecret() === '') {
            $items[] = $this->makeAttentionItem(
                'warning',
                \__('Stripe webhook secret missing', 'must-hotel-booking'),
                \__('Stripe webhook payloads cannot be verified until the signing secret is set.', 'must-hotel-booking'),
                PaymentEngine::getSiteEnvironmentLabel(),
                get_admin_payments_page_url()
            );
        }

        \usort(
            $items,
            function (array $left, array $right): int {
                return $this->severityRank((string) ($left['severity'] ?? 'info'))
                    <=> $this->severityRank((string) ($right['severity'] ?? 'info'));
            }
        );

        return \array_slice($items, 0, 12);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildHealthItems(): array
    {
        $diagnostics = \function_exists(__NAMESPACE__ . '\get_settings_diagnostics_data') ? get_settings_diagnostics_data() : [];
        $tableRows = isset($diagnostics['tables']) && \is_array($diagnostics['tables']) ? $diagnostics['tables'] : [];
        $pageRows = isset($diagnostics['pages']) && \is_array($diagnostics['pages']) ? $diagnostics['pages'] : [];
        $reservationRow = $this->findDiagnosticsRow($tableRows, 'Reservations');
        $locksRow = $this->findDiagnosticsRow($tableRows, 'Locks');
        $pagesHealthy = !empty($pageRows);

        foreach ($pageRows as $pageRow) {
            if (!\is_array($pageRow) || (string) ($pageRow['status'] ?? '') !== 'healthy') {
                $pagesHealthy = false;
                break;
            }
        }

        $stripeEnabled = \in_array('stripe', PaymentMethodRegistry::getEnabled(), true);
        $emailTemplates = EmailEngine::getTemplates();
        $emailSender = MustBookingConfig::get_booking_notification_email();
        $hotelCoreHealthy = MustBookingConfig::get_hotel_name() !== ''
            && MustBookingConfig::get_currency() !== ''
            && MustBookingConfig::get_timezone() !== '';

        return [
            $this->makeHealthItem(
                $reservationRow !== null && (string) ($reservationRow['status'] ?? '') === 'healthy' ? 'ok' : 'error',
                \__('Reservations table', 'must-hotel-booking'),
                $reservationRow !== null ? (string) ($reservationRow['message'] ?? '') : \__('Reservation storage is not available.', 'must-hotel-booking'),
                get_admin_settings_page_url(['tab' => 'diagnostics']),
                \__('Open diagnostics', 'must-hotel-booking')
            ),
            $this->makeHealthItem(
                $this->inventoryRepository->inventoryRoomsTableExists() || $this->inventoryRepository->roomTypesTableExists()
                    ? (($locksRow !== null && (string) ($locksRow['status'] ?? '') === 'healthy') ? 'ok' : 'error')
                    : 'ok',
                \__('Inventory and locks', 'must-hotel-booking'),
                $this->inventoryRepository->inventoryRoomsTableExists() || $this->inventoryRepository->roomTypesTableExists()
                    ? (($locksRow !== null && (string) ($locksRow['message'] ?? '') !== '')
                        ? (string) $locksRow['message']
                        : \__('Inventory lock table is missing.', 'must-hotel-booking'))
                    : \__('Legacy room model is active; inventory locks are optional right now.', 'must-hotel-booking'),
                get_admin_settings_page_url(['tab' => 'diagnostics']),
                \__('Open diagnostics', 'must-hotel-booking')
            ),
            $this->makeHealthItem(
                !$stripeEnabled || PaymentEngine::isStripeCheckoutConfigured() ? 'ok' : 'error',
                \__('Stripe keys', 'must-hotel-booking'),
                !$stripeEnabled
                    ? \__('Stripe is not enabled on this site.', 'must-hotel-booking')
                    : (PaymentEngine::isStripeCheckoutConfigured()
                        ? \__('Stripe publishable and secret keys are configured.', 'must-hotel-booking')
                        : \__('Stripe is enabled, but the active keys are incomplete.', 'must-hotel-booking')),
                get_admin_payments_page_url(),
                \__('Open payments', 'must-hotel-booking')
            ),
            $this->makeHealthItem(
                !$stripeEnabled || PaymentEngine::getStripeWebhookSecret() !== '' ? 'ok' : 'warning',
                \__('Webhook secret', 'must-hotel-booking'),
                !$stripeEnabled
                    ? \__('Stripe webhooks are not required while Stripe is disabled.', 'must-hotel-booking')
                    : (PaymentEngine::getStripeWebhookSecret() !== ''
                        ? \__('Webhook signing secret is configured.', 'must-hotel-booking')
                        : \__('Webhook signing secret is missing for the active Stripe environment.', 'must-hotel-booking')),
                get_admin_payments_page_url(),
                \__('Open payments', 'must-hotel-booking')
            ),
            $this->makeHealthItem(
                $emailSender !== '' ? 'ok' : 'error',
                \__('Email sender', 'must-hotel-booking'),
                $emailSender !== ''
                    ? \sprintf(\__('Notification emails will use %s.', 'must-hotel-booking'), $emailSender)
                    : \__('Booking notification email is missing.', 'must-hotel-booking'),
                get_admin_emails_page_url(),
                \__('Open emails', 'must-hotel-booking')
            ),
            $this->makeHealthItem(
                !empty($emailTemplates) ? 'ok' : 'error',
                \__('Email templates', 'must-hotel-booking'),
                !empty($emailTemplates)
                    ? \sprintf(\_n('%d template is available.', '%d templates are available.', \count($emailTemplates), 'must-hotel-booking'), \count($emailTemplates))
                    : \__('No email templates are configured.', 'must-hotel-booking'),
                get_admin_emails_page_url(),
                \__('Open emails', 'must-hotel-booking')
            ),
            $this->makeHealthItem(
                $pagesHealthy ? 'ok' : 'error',
                \__('Booking pages', 'must-hotel-booking'),
                $pagesHealthy ? \__('All managed booking pages are assigned.', 'must-hotel-booking') : \__('One or more managed booking pages are missing or invalid.', 'must-hotel-booking'),
                get_admin_settings_page_url(['tab' => 'pages']),
                \__('Open page settings', 'must-hotel-booking')
            ),
            $this->makeHealthItem(
                $hotelCoreHealthy ? 'ok' : 'warning',
                \__('Hotel core settings', 'must-hotel-booking'),
                $hotelCoreHealthy ? \__('Hotel name, currency, and timezone are configured.', 'must-hotel-booking') : \__('Core hotel identity settings are incomplete.', 'must-hotel-booking'),
                get_admin_settings_page_url(['tab' => 'general']),
                \__('Open general settings', 'must-hotel-booking')
            ),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildRecentReservations(string $currency): array
    {
        $rows = [];

        foreach ($this->reservationRepository->getRecentReservationRows(8) as $reservation) {
            $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;
            $rows[] = [
                'booking_id' => $this->formatReservationReference($reservation),
                'guest' => $this->formatGuestName($reservation),
                'accommodation' => isset($reservation['room_name']) && (string) $reservation['room_name'] !== ''
                    ? (string) $reservation['room_name']
                    : \__('Unassigned', 'must-hotel-booking'),
                'checkin' => (string) ($reservation['checkin'] ?? ''),
                'checkout' => (string) ($reservation['checkout'] ?? ''),
                'status' => $this->formatStatusLabel((string) ($reservation['status'] ?? '')),
                'payment' => $this->formatReservationPayment($reservation),
                'total' => $this->formatMoney((float) ($reservation['total_price'] ?? 0), $currency),
                'view_url' => $this->getReservationViewUrl($reservationId),
                'edit_url' => $this->getReservationEditUrl($reservationId),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildRecentActivity(): array
    {
        $activities = $this->activityRepository->getRecentActivities(8);

        if (!empty($activities)) {
            $rows = [];

            foreach ($activities as $activity) {
                $context = $this->decodeContext((string) ($activity['context_json'] ?? ''));
                $reservationId = isset($context['reservation_id']) ? (int) $context['reservation_id'] : 0;
                $rows[] = [
                    'created_at' => (string) ($activity['created_at'] ?? ''),
                    'severity' => (string) ($activity['severity'] ?? 'info'),
                    'message' => (string) ($activity['message'] ?? ''),
                    'reference' => (string) ($activity['reference'] ?? ''),
                    'action_url' => $reservationId > 0 ? $this->getReservationViewUrl($reservationId) : get_admin_dashboard_page_url(),
                ];
            }

            return $rows;
        }

        $rows = [];

        foreach ($this->reservationRepository->getRecentReservationRows(4) as $reservation) {
            $rows[] = [
                'created_at' => (string) ($reservation['created_at'] ?? ''),
                'severity' => 'info',
                'message' => \sprintf(
                    \__('Reservation %s created.', 'must-hotel-booking'),
                    $this->formatReservationReference($reservation)
                ),
                'reference' => $this->formatReservationReference($reservation),
                'action_url' => $this->getReservationViewUrl((int) ($reservation['id'] ?? 0)),
            ];
        }

        foreach ($this->paymentRepository->getRecentPaymentRows(4) as $payment) {
            $reference = $this->formatReservationReference(
                [
                    'id' => isset($payment['reservation_id']) ? (int) $payment['reservation_id'] : 0,
                    'booking_id' => isset($payment['booking_id']) ? (string) $payment['booking_id'] : '',
                ]
            );
            $status = (string) ($payment['status'] ?? '');
            $method = (string) ($payment['method'] ?? '');
            $rows[] = [
                'created_at' => isset($payment['paid_at']) && (string) $payment['paid_at'] !== ''
                    ? (string) $payment['paid_at']
                    : (string) ($payment['created_at'] ?? ''),
                'severity' => $this->mapPaymentStatusToSeverity($status),
                'message' => $this->formatSyntheticPaymentMessage($status, $method, $reference),
                'reference' => $reference,
                'action_url' => $this->getReservationViewUrl((int) ($payment['reservation_id'] ?? 0)),
            ];
        }

        \usort(
            $rows,
            static function (array $left, array $right): int {
                return \strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
            }
        );

        return \array_slice($rows, 0, 8);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildQuickActions(): array
    {
        return [
            ['label' => \__('Create Reservation', 'must-hotel-booking'), 'url' => get_admin_reservation_create_page_url()],
            ['label' => \__('Open Calendar', 'must-hotel-booking'), 'url' => get_admin_calendar_page_url(['start_date' => \current_time('Y-m-d'), 'weeks' => 2])],
            ['label' => \__("View Today's Arrivals", 'must-hotel-booking'), 'url' => get_admin_reservations_page_url(['preset' => 'arrivals_today'])],
            ['label' => \__("View Today's Departures", 'must-hotel-booking'), 'url' => get_admin_reservations_page_url(['preset' => 'departures_today'])],
            ['label' => \__('Open Payments', 'must-hotel-booking'), 'url' => get_admin_payments_page_url()],
            ['label' => \__('Open Email Templates', 'must-hotel-booking'), 'url' => get_admin_emails_page_url()],
            ['label' => \__('Open Settings', 'must-hotel-booking'), 'url' => get_admin_settings_page_url()],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function makeAttentionItem(string $severity, string $label, string $message, string $reference, string $actionUrl): array
    {
        return [
            'severity' => $severity,
            'label' => $label,
            'message' => $message,
            'reference' => $reference,
            'action_url' => $actionUrl,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function makeHealthItem(string $status, string $label, string $message, string $actionUrl, string $actionLabel = ''): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'message' => $message,
            'action_url' => $actionUrl,
            'action_label' => $actionLabel,
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function formatReservationReference(array $reservation): string
    {
        $bookingId = isset($reservation['booking_id']) ? \trim((string) $reservation['booking_id']) : '';

        if ($bookingId !== '') {
            return $bookingId;
        }

        $reservationId = isset($reservation['id']) ? (int) $reservation['id'] : 0;

        return $reservationId > 0 ? 'RES-' . $reservationId : \__('Reservation', 'must-hotel-booking');
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function formatGuestName(array $reservation): string
    {
        $guestName = isset($reservation['guest_name']) ? \trim((string) $reservation['guest_name']) : '';

        return $guestName !== '' ? $guestName : \__('Guest details missing', 'must-hotel-booking');
    }

    private function formatGuestCountDescriptor(int $guestCount): string
    {
        return \sprintf(\_n('%d guest.', '%d guests.', $guestCount, 'must-hotel-booking'), $guestCount);
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return \number_format_i18n($amount, 2) . ' ' . $currency;
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function formatReservationPayment(array $reservation): string
    {
        $paymentStatus = (string) ($reservation['payment_status'] ?? '');
        $paymentMethod = (string) ($reservation['payment_method'] ?? '');

        if ($paymentStatus === 'paid') {
            return $paymentMethod === 'stripe' ? \__('Paid via Stripe', 'must-hotel-booking') : \__('Paid', 'must-hotel-booking');
        }

        if ($paymentMethod === 'stripe' && $paymentStatus === 'pending') {
            return \__('Stripe pending', 'must-hotel-booking');
        }

        if ($paymentStatus === 'failed') {
            return \__('Payment failed', 'must-hotel-booking');
        }

        if ($paymentStatus === 'unpaid' && $paymentMethod === 'pay_at_hotel') {
            return \__('Pay at hotel', 'must-hotel-booking');
        }

        return $paymentStatus !== '' ? $this->formatStatusLabel($paymentStatus) : \__('Unpaid', 'must-hotel-booking');
    }

    private function formatStatusLabel(string $status): string
    {
        $labels = [
            'pending' => \__('Pending', 'must-hotel-booking'),
            'pending_payment' => \__('Pending Payment', 'must-hotel-booking'),
            'confirmed' => \__('Confirmed', 'must-hotel-booking'),
            'completed' => \__('Completed', 'must-hotel-booking'),
            'cancelled' => \__('Cancelled', 'must-hotel-booking'),
            'payment_failed' => \__('Payment Failed', 'must-hotel-booking'),
            'blocked' => \__('Blocked', 'must-hotel-booking'),
            'unpaid' => \__('Unpaid', 'must-hotel-booking'),
            'paid' => \__('Paid', 'must-hotel-booking'),
            'failed' => \__('Failed', 'must-hotel-booking'),
            'refunded' => \__('Refunded', 'must-hotel-booking'),
        ];

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findDiagnosticsRow(array $rows, string $label): ?array
    {
        foreach ($rows as $row) {
            if (\is_array($row) && (string) ($row['label'] ?? '') === $label) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContext(string $contextJson): array
    {
        $decoded = \json_decode($contextJson, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function getReservationViewUrl(int $reservationId): string
    {
        return get_admin_reservation_detail_page_url($reservationId);
    }

    private function getReservationEditUrl(int $reservationId): string
    {
        return get_admin_reservation_detail_page_url($reservationId, ['mode' => 'edit']);
    }

    private function severityRank(string $severity): int
    {
        if ($severity === 'error') {
            return 0;
        }

        if ($severity === 'warning') {
            return 1;
        }

        return 2;
    }

    private function mapPaymentStatusToSeverity(string $status): string
    {
        if ($status === 'failed') {
            return 'error';
        }

        if ($status === 'pending') {
            return 'warning';
        }

        return 'info';
    }

    private function formatSyntheticPaymentMessage(string $status, string $method, string $reference): string
    {
        $methodLabel = $method === 'stripe'
            ? \__('Stripe', 'must-hotel-booking')
            : ($method === 'pay_at_hotel' ? \__('Pay at hotel', 'must-hotel-booking') : \__('Payment', 'must-hotel-booking'));

        if ($status === 'paid') {
            return \sprintf(\__('%1$s payment received for %2$s.', 'must-hotel-booking'), $methodLabel, $reference);
        }

        if ($status === 'failed') {
            return \sprintf(\__('%1$s payment failed for %2$s.', 'must-hotel-booking'), $methodLabel, $reference);
        }

        return \sprintf(\__('%1$s payment recorded for %2$s.', 'must-hotel-booking'), $methodLabel, $reference);
    }
}
