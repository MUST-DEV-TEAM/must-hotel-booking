<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\PaymentStatusService;

final class GuestAdminDataProvider
{
    private \MustHotelBooking\Database\GuestRepository $guestRepository;
    private \MustHotelBooking\Database\PaymentRepository $paymentRepository;
    private \MustHotelBooking\Database\ActivityRepository $activityRepository;

    public function __construct()
    {
        $this->guestRepository = \MustHotelBooking\Engine\get_guest_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
    }

    /**
     * @param array<string, mixed> $saveState
     * @return array<string, mixed>
     */
    public function getPageData(GuestAdminQuery $query, array $saveState): array
    {
        $filters = $query->getFilters();
        $totalItems = $this->guestRepository->countAdminGuestListRows($filters);
        $totalPages = (int) \max(1, \ceil($totalItems / \max(1, (int) ($filters['per_page'] ?? 20))));
        $filters['paged'] = \max(1, \min((int) ($filters['paged'] ?? 1), $totalPages));
        $rows = $this->guestRepository->getAdminGuestListRows($filters);
        $selectedGuestId = isset($saveState['selected_guest_id']) && (int) $saveState['selected_guest_id'] > 0
            ? (int) $saveState['selected_guest_id']
            : $query->getGuestId();

        return [
            'filters' => $filters,
            'country_options' => $this->buildCountryOptions(),
            'summary_cards' => $this->buildSummaryCards($rows, $totalItems),
            'rows' => $this->formatGuestRows($rows),
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => (int) $filters['paged'],
                'per_page' => (int) $filters['per_page'],
            ],
            'detail' => $selectedGuestId > 0 ? $this->buildGuestDetail($selectedGuestId, $saveState) : null,
            'errors' => isset($saveState['errors']) && \is_array($saveState['errors']) ? $saveState['errors'] : [],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $rows, int $totalItems): array
    {
        $inHouse = 0;
        $upcoming = 0;
        $attention = 0;
        $flagged = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            if ((string) ($row['in_house_checkin'] ?? '') !== '') {
                $inHouse++;
            }

            if ((string) ($row['next_checkin'] ?? '') !== '') {
                $upcoming++;
            }

            if ((int) ($row['upcoming_unpaid_count'] ?? 0) > 0 || (int) ($row['upcoming_failed_count'] ?? 0) > 0) {
                $attention++;
            }

            if (!empty($row['vip_flag']) || !empty($row['problem_flag'])) {
                $flagged++;
            }
        }

        return [
            [
                'label' => \__('Guests', 'must-hotel-booking'),
                'value' => (string) $totalItems,
                'meta' => \__('Guest profiles currently available in the admin workspace.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('In-House', 'must-hotel-booking'),
                'value' => (string) $inHouse,
                'meta' => \__('Guests who are currently on property.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Upcoming', 'must-hotel-booking'),
                'value' => (string) $upcoming,
                'meta' => \__('Guests with an upcoming stay still on the books.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Needs Attention', 'must-hotel-booking'),
                'value' => (string) $attention,
                'meta' => \__('Upcoming unpaid or failed-payment guests in this view.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Flagged', 'must-hotel-booking'),
                'value' => (string) $flagged,
                'meta' => \__('VIP or problem-flagged guest records.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatGuestRows(array $rows): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $guestId = isset($row['id']) ? (int) $row['id'] : 0;
            $formatted[] = [
                'id' => $guestId,
                'name' => get_admin_guest_full_name($row),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'total_reservations' => (int) ($row['total_reservations'] ?? 0),
                'total_completed_stays' => (int) ($row['total_completed_stays'] ?? 0),
                'upcoming_stay' => (string) ($row['next_checkin'] ?? ''),
                'last_stay' => (string) ($row['last_stay_date'] ?? ''),
                'total_spend' => $this->formatMoney((float) ($row['total_paid'] ?? 0.0)),
                'status_badges' => $this->buildGuestStatusBadges($row),
                'warnings' => $this->buildGuestWarnings($row),
                'detail_url' => get_admin_guests_page_url(['guest_id' => $guestId]),
                'reservations_url' => get_admin_reservations_page_url(['search' => (string) ($row['email'] ?? get_admin_guest_full_name($row))]),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function buildGuestStatusBadges(array $row): array
    {
        $badges = [];

        if ((string) ($row['in_house_checkin'] ?? '') !== '') {
            $badges[] = \__('In house', 'must-hotel-booking');
        } elseif ((string) ($row['next_checkin'] ?? '') !== '') {
            $badges[] = \__('Upcoming', 'must-hotel-booking');
        }

        if (!empty($row['vip_flag'])) {
            $badges[] = \__('VIP', 'must-hotel-booking');
        }

        if (!empty($row['problem_flag'])) {
            $badges[] = \__('Problem', 'must-hotel-booking');
        }

        if ((int) ($row['email_duplicates'] ?? 0) > 1) {
            $badges[] = \__('Possible duplicate', 'must-hotel-booking');
        }

        return $badges;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function buildGuestWarnings(array $row): array
    {
        $warnings = [];

        if ((string) ($row['email'] ?? '') === '') {
            $warnings[] = \__('Missing guest email.', 'must-hotel-booking');
        }

        if ((string) ($row['phone'] ?? '') === '' || (string) ($row['country'] ?? '') === '') {
            $warnings[] = \__('Guest contact profile is incomplete.', 'must-hotel-booking');
        }

        if ((int) ($row['upcoming_unpaid_count'] ?? 0) > 0) {
            $warnings[] = \__('Guest has unpaid upcoming reservations.', 'must-hotel-booking');
        }

        if ((int) ($row['upcoming_failed_count'] ?? 0) > 0) {
            $warnings[] = \__('Guest has failed payment history on upcoming bookings.', 'must-hotel-booking');
        }

        if ((int) ($row['email_duplicates'] ?? 0) > 1) {
            $warnings[] = \__('Possible duplicate guest records share this email.', 'must-hotel-booking');
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $saveState
     * @return array<string, mixed>|null
     */
    private function buildGuestDetail(int $guestId, array $saveState): ?array
    {
        $guest = $this->guestRepository->getGuestOperationalSummary($guestId);

        if (!\is_array($guest)) {
            return null;
        }

        $reservationRows = $this->guestRepository->getGuestReservationHistory($guestId, 50);
        $reservationIds = [];

        foreach ($reservationRows as $reservationRow) {
            if (\is_array($reservationRow) && !empty($reservationRow['id'])) {
                $reservationIds[] = (int) $reservationRow['id'];
            }
        }

        $paymentsByReservation = $this->paymentRepository->getPaymentsForReservationIds($reservationIds);
        $historyRows = [];
        $totalPaid = 0.0;
        $upcomingAmountDue = 0.0;
        $failedPaymentCount = 0;
        $methodCounts = [];
        $today = \current_time('Y-m-d');
        $upcomingReservation = null;
        $inHouseReservation = null;

        foreach ($reservationRows as $reservationRow) {
            if (!\is_array($reservationRow)) {
                continue;
            }

            $reservationId = isset($reservationRow['id']) ? (int) $reservationRow['id'] : 0;
            $paymentRows = $paymentsByReservation[$reservationId] ?? [];
            $paymentState = PaymentStatusService::buildReservationPaymentState($reservationRow, $paymentRows);
            $totalPaid += (float) ($paymentState['amount_paid'] ?? 0.0);

            if ((string) ($paymentState['derived_status'] ?? '') === 'failed') {
                $failedPaymentCount++;
            }

            $method = (string) ($paymentState['method'] ?? '');

            if ($method !== '') {
                if (!isset($methodCounts[$method])) {
                    $methodCounts[$method] = 0;
                }

                $methodCounts[$method]++;
            }

            $checkin = (string) ($reservationRow['checkin'] ?? '');
            $checkout = (string) ($reservationRow['checkout'] ?? '');
            $status = (string) ($reservationRow['status'] ?? '');

            if ($checkin !== '' && $checkin > $today && \in_array($status, ['pending', 'pending_payment', 'confirmed'], true)) {
                $upcomingAmountDue += (float) ($paymentState['amount_due'] ?? 0.0);

                if ($upcomingReservation === null || $checkin < (string) ($upcomingReservation['checkin'] ?? '9999-12-31')) {
                    $upcomingReservation = $reservationRow + ['payment_state' => $paymentState];
                }
            }

            if ($checkin !== '' && $checkout !== '' && $checkin <= $today && $checkout > $today && \in_array($status, ['confirmed', 'completed'], true)) {
                $inHouseReservation = $reservationRow + ['payment_state' => $paymentState];
            }

            $historyRows[] = [
                'id' => $reservationId,
                'booking_id' => format_reservation_booking_id($reservationRow),
                'accommodation' => isset($reservationRow['room_name']) && (string) $reservationRow['room_name'] !== ''
                    ? (string) $reservationRow['room_name']
                    : \__('Unassigned', 'must-hotel-booking'),
                'checkin' => $checkin,
                'checkout' => $checkout,
                'reservation_status' => get_reservation_status_options()[$status] ?? $status,
                'reservation_status_key' => $status,
                'payment_status' => get_reservation_payment_status_options()[(string) ($paymentState['derived_status'] ?? '')] ?? (string) ($paymentState['derived_status'] ?? ''),
                'payment_status_key' => (string) ($paymentState['derived_status'] ?? ''),
                'total' => $this->formatMoney((float) ($reservationRow['total_price'] ?? 0.0)),
                'amount_due' => $this->formatMoney((float) ($paymentState['amount_due'] ?? 0.0)),
                'detail_url' => get_admin_reservation_detail_page_url($reservationId),
                'payment_url' => get_admin_payments_page_url(['reservation_id' => $reservationId]),
            ];
        }

        \arsort($methodCounts);
        $preferredMethod = !empty($methodCounts) ? (string) \array_key_first($methodCounts) : '';
        $duplicates = $this->guestRepository->findPossibleDuplicateGuests($guestId);
        $emailRows = $this->buildGuestEmailRows((string) ($guest['email'] ?? ''), $reservationIds);
        $warnings = $this->buildGuestWarnings($guest);

        if (!empty($duplicates)) {
            $warnings[] = \__('Possible duplicate guest profiles were found.', 'must-hotel-booking');
        }

        if (!empty($guest['admin_notes'])) {
            $warnings[] = \__('Guest profile contains internal notes.', 'must-hotel-booking');
        }

        return [
            'guest' => $guest,
            'form' => isset($saveState['form']) && \is_array($saveState['form'])
                ? \array_merge($guest, $saveState['form'])
                : $guest,
            'summary' => [
                'full_name' => get_admin_guest_full_name($guest),
                'first_reservation_at' => (string) ($guest['first_reservation_at'] ?? ''),
                'last_stay_date' => (string) ($guest['last_stay_date'] ?? ''),
                'next_checkin' => (string) ($guest['next_checkin'] ?? ''),
                'next_checkout' => (string) ($guest['next_checkout'] ?? ''),
                'currently_in_house' => (string) ($guest['in_house_checkin'] ?? '') !== '',
                'total_reservations' => (int) ($guest['total_reservations'] ?? 0),
                'total_completed_stays' => (int) ($guest['total_completed_stays'] ?? 0),
                'total_cancellations' => (int) ($guest['total_cancellations'] ?? 0),
                'total_spend' => $this->formatMoney($totalPaid),
            ],
            'upcoming' => $upcomingReservation,
            'in_house' => $inHouseReservation,
            'reservations' => $historyRows,
            'payment_summary' => [
                'total_paid' => $this->formatMoney($totalPaid),
                'amount_due_upcoming' => $this->formatMoney($upcomingAmountDue),
                'failed_payment_count' => $failedPaymentCount,
                'preferred_method' => $preferredMethod !== '' ? format_admin_reservation_method_label($preferredMethod) : \__('No payment preference yet', 'must-hotel-booking'),
            ],
            'emails' => $emailRows,
            'duplicates' => $this->formatDuplicateRows($duplicates),
            'warnings' => \array_values(\array_unique($warnings)),
        ];
    }

    /**
     * @param array<int, int> $reservationIds
     * @return array<int, array<string, mixed>>
     */
    private function buildGuestEmailRows(string $guestEmail, array $reservationIds): array
    {
        $rows = [];
        $activities = $this->activityRepository->getRecentActivitiesByEventTypes(['email_sent', 'email_failed'], 100);

        foreach ($activities as $activity) {
            if (!\is_array($activity)) {
                continue;
            }

            $context = \json_decode((string) ($activity['context_json'] ?? ''), true);
            $context = \is_array($context) ? $context : [];
            $reservationId = isset($context['reservation_id']) ? (int) $context['reservation_id'] : 0;
            $recipientEmail = isset($context['recipient_email']) ? (string) $context['recipient_email'] : '';

            if (
                $guestEmail !== '' && \strcasecmp($guestEmail, $recipientEmail) !== 0 &&
                !\in_array($reservationId, $reservationIds, true)
            ) {
                continue;
            }

            if ($guestEmail === '' && !\in_array($reservationId, $reservationIds, true)) {
                continue;
            }

            $rows[] = [
                'created_at' => (string) ($activity['created_at'] ?? ''),
                'template_key' => isset($context['template_key']) ? (string) $context['template_key'] : '',
                'recipient_email' => $recipientEmail,
                'mode' => isset($context['email_mode']) ? (string) $context['email_mode'] : 'automated',
                'status' => (string) ($activity['event_type'] ?? '') === 'email_failed' ? 'failed' : 'sent',
                'message' => (string) ($activity['message'] ?? ''),
                'reference' => (string) ($activity['reference'] ?? ''),
            ];
        }

        return \array_slice($rows, 0, 15);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatDuplicateRows(array $rows): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $formatted[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => get_admin_guest_full_name($row),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'detail_url' => get_admin_guests_page_url(['guest_id' => (int) ($row['id'] ?? 0)]),
            ];
        }

        return $formatted;
    }

    /**
     * @return array<string, string>
     */
    private function buildCountryOptions(): array
    {
        return $this->guestRepository->getGuestCountries();
    }

    private function formatMoney(float $amount): string
    {
        return \number_format_i18n($amount, 2) . ' ' . MustBookingConfig::get_currency();
    }
}
