<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Core\ReservationStatus;
use MustHotelBooking\Engine\PaymentStatusService;

final class ReportAdminDataProvider
{
    /** @var \MustHotelBooking\Database\ReportRepository */
    private $reportRepository;

    /** @var \MustHotelBooking\Database\PaymentRepository */
    private $paymentRepository;

    /** @var \MustHotelBooking\Database\RoomRepository */
    private $roomRepository;

    public function __construct()
    {
        $this->reportRepository = \MustHotelBooking\Engine\get_report_repository();
        $this->paymentRepository = \MustHotelBooking\Engine\get_payment_repository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(ReportAdminQuery $query): array
    {
        $createdRows = $this->reportRepository->getReservationsCreatedInRange($query->toArray());
        $stayRows = $this->reportRepository->getStayOverlapReservations($query->toArray());
        $allReservationIds = $this->collectReservationIds($createdRows, $stayRows);
        $paymentsByReservation = $this->paymentRepository->getPaymentsForReservationIds($allReservationIds);
        $createdRows = $this->decorateRowsWithPaymentState($createdRows, $paymentsByReservation);
        $stayRows = $this->decorateRowsWithPaymentState($stayRows, $paymentsByReservation);

        if ($query->getPaymentMethod() !== '') {
            $createdRows = \array_values(\array_filter($createdRows, function (array $row) use ($query): bool {
                return $this->matchesPaymentMethodFilter($row, $query->getPaymentMethod());
            }));
            $stayRows = \array_values(\array_filter($stayRows, function (array $row) use ($query): bool {
                return $this->matchesPaymentMethodFilter($row, $query->getPaymentMethod());
            }));
        }

        $currency = MustBookingConfig::get_currency();
        $summary = $this->buildSummary($createdRows, $stayRows, $query);
        $trendRows = $this->buildTrendRows($createdRows, $query);

        return [
            'query' => $query,
            'filters' => $query->toArray(),
            'filter_options' => [
                'presets' => [
                    'today' => \__('Today', 'must-hotel-booking'),
                    'last_7_days' => \__('Last 7 Days', 'must-hotel-booking'),
                    'this_month' => \__('This Month', 'must-hotel-booking'),
                    'last_month' => \__('Last Month', 'must-hotel-booking'),
                    'this_year' => \__('This Year', 'must-hotel-booking'),
                    'custom' => \__('Custom', 'must-hotel-booking'),
                ],
                'rooms' => $this->buildRoomOptions(),
                'reservation_statuses' => $this->buildReservationStatusOptions(),
                'payment_methods' => $this->buildPaymentMethodOptions(),
            ],
            'notes' => [
                \__('Reservation and revenue sections are based on reservations created in the selected date range.', 'must-hotel-booking'),
                \__('Stay and occupancy sections are based on reservations whose stay overlaps the selected date range.', 'must-hotel-booking'),
                \__('Booked revenue uses reservation totals. Amount paid and amount due use the payment ledger and normalized payment state.', 'must-hotel-booking'),
                \__('Coupon discounts are applied after nightly pricing and fees, before taxes.', 'must-hotel-booking'),
            ],
            'kpis' => [
                [
                    'label' => \__('Total Reservations', 'must-hotel-booking'),
                    'value' => \number_format_i18n((int) $summary['total_reservations']),
                    'meta' => \__('Reservations created in the selected range.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Confirmed Reservations', 'must-hotel-booking'),
                    'value' => \number_format_i18n((int) $summary['confirmed_reservations']),
                    'meta' => \__('Confirmed or completed bookings.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Cancelled Reservations', 'must-hotel-booking'),
                    'value' => \number_format_i18n((int) $summary['cancelled_reservations']),
                    'meta' => \__('Cancelled bookings created in range.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Booked Revenue', 'must-hotel-booking'),
                    'value' => $this->formatMoney((float) $summary['gross_revenue'], $currency),
                    'meta' => \__('Reservation total value excluding blocked and cancelled rows.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Amount Paid', 'must-hotel-booking'),
                    'value' => $this->formatMoney((float) $summary['amount_paid'], $currency),
                    'meta' => \__('Paid amount from payment ledger rows.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Amount Due', 'must-hotel-booking'),
                    'value' => $this->formatMoney((float) $summary['amount_due'], $currency),
                    'meta' => \__('Outstanding amount on non-cancelled reservations.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Occupancy', 'must-hotel-booking'),
                    'value' => $summary['occupancy_available'] ? $this->formatPercent((float) $summary['occupancy_percent']) : \__('N/A', 'must-hotel-booking'),
                    'meta' => $summary['occupancy_available']
                        ? \sprintf(
                            \__('%1$s occupied nights from %2$s available nights.', 'must-hotel-booking'),
                            \number_format_i18n((int) $summary['occupied_nights']),
                            \number_format_i18n((int) $summary['available_nights'])
                        )
                        : \__('Occupancy requires active inventory units.', 'must-hotel-booking'),
                ],
                [
                    'label' => \__('Average Booking Value', 'must-hotel-booking'),
                    'value' => $this->formatMoney((float) $summary['average_booking_value'], $currency),
                    'meta' => \__('Booked revenue divided by counted revenue reservations.', 'must-hotel-booking'),
                ],
            ],
            'breakdowns' => [
                'reservation_status' => $this->buildCountBreakdown($createdRows, 'status', true),
                'payment_status' => $this->buildPaymentStatusBreakdown($createdRows),
                'payment_method' => $this->buildPaymentMethodBreakdown($createdRows),
            ],
            'trend' => [
                'granularity_label' => (string) $trendRows['granularity_label'],
                'rows' => (array) $trendRows['rows'],
            ],
            'revenue' => [
                'booked_revenue' => $this->formatMoney((float) $summary['gross_revenue'], $currency),
                'amount_paid' => $this->formatMoney((float) $summary['amount_paid'], $currency),
                'amount_due' => $this->formatMoney((float) $summary['amount_due'], $currency),
                'average_booking_value' => $this->formatMoney((float) $summary['average_booking_value'], $currency),
            ],
            'stay' => [
                'arrivals_count' => (int) $summary['arrivals_count'],
                'departures_count' => (int) $summary['departures_count'],
                'occupied_nights' => (int) $summary['occupied_nights'],
                'available_nights' => (int) $summary['available_nights'],
                'average_length_of_stay' => (float) $summary['average_length_of_stay'],
                'occupancy_available' => (bool) $summary['occupancy_available'],
            ],
            'top_accommodations' => $this->buildAccommodationRows($createdRows),
            'coupons' => $this->buildCouponRows($createdRows),
            'issues' => $this->buildIssues($createdRows),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $createdRows
     * @param array<int, array<string, mixed>> $stayRows
     * @return array<string, int|float|bool>
     */
    private function buildSummary(array $createdRows, array $stayRows, ReportAdminQuery $query): array
    {
        $summary = [
            'total_reservations' => \count($createdRows),
            'confirmed_reservations' => 0,
            'cancelled_reservations' => 0,
            'gross_revenue' => 0.0,
            'amount_paid' => 0.0,
            'amount_due' => 0.0,
            'average_booking_value' => 0.0,
            'revenue_count' => 0,
            'arrivals_count' => 0,
            'departures_count' => 0,
            'occupied_nights' => 0,
            'available_nights' => 0,
            'occupancy_percent' => 0.0,
            'occupancy_available' => false,
            'average_length_of_stay' => 0.0,
        ];
        $confirmableStatuses = ReservationStatus::getConfirmedStatuses();
        $losCount = 0;
        $losTotal = 0;

        foreach ($createdRows as $row) {
            $status = (string) ($row['status'] ?? '');
            $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];

            if (\in_array($status, ['confirmed', 'completed'], true)) {
                $summary['confirmed_reservations']++;
            }

            if ($status === 'cancelled') {
                $summary['cancelled_reservations']++;
            }

            if (!\in_array($status, ['blocked', 'cancelled', 'expired', 'payment_failed'], true)) {
                $summary['gross_revenue'] += (float) ($row['total_price'] ?? 0.0);
                $summary['revenue_count']++;
            }

            $summary['amount_paid'] += isset($paymentState['amount_paid']) ? (float) $paymentState['amount_paid'] : 0.0;

            if (!\in_array($status, ['blocked', 'cancelled', 'expired'], true)) {
                $summary['amount_due'] += isset($paymentState['amount_due']) ? (float) $paymentState['amount_due'] : 0.0;
            }
        }

        foreach ($stayRows as $row) {
            $status = (string) ($row['status'] ?? '');

            if (!\in_array($status, $confirmableStatuses, true)) {
                continue;
            }

            if ((string) ($row['checkin'] ?? '') >= $query->getDateFrom() && (string) ($row['checkin'] ?? '') <= $query->getDateTo()) {
                $summary['arrivals_count']++;
                $nights = $this->calculateNights((string) ($row['checkin'] ?? ''), (string) ($row['checkout'] ?? ''));

                if ($nights > 0) {
                    $losCount++;
                    $losTotal += $nights;
                }
            }

            if ((string) ($row['checkout'] ?? '') >= $query->getDateFrom() && (string) ($row['checkout'] ?? '') <= $query->getDateTo()) {
                $summary['departures_count']++;
            }

            $summary['occupied_nights'] += $this->calculateOverlapNights(
                (string) ($row['checkin'] ?? ''),
                (string) ($row['checkout'] ?? ''),
                $query->getDateFrom(),
                $query->getDateTo()
            );
        }

        $summary['gross_revenue'] = \round((float) $summary['gross_revenue'], 2);
        $summary['amount_paid'] = \round((float) $summary['amount_paid'], 2);
        $summary['amount_due'] = \round((float) $summary['amount_due'], 2);
        $summary['average_booking_value'] = (int) $summary['revenue_count'] > 0
            ? \round((float) $summary['gross_revenue'] / (int) $summary['revenue_count'], 2)
            : 0.0;
        $summary['average_length_of_stay'] = $losCount > 0 ? \round($losTotal / $losCount, 1) : 0.0;

        $inventoryUnits = $this->reportRepository->countAvailableInventoryUnits($query->getRoomId());
        $days = $this->countDaysInclusive($query->getDateFrom(), $query->getDateTo());

        if ($inventoryUnits > 0 && $days > 0) {
            $summary['available_nights'] = $inventoryUnits * $days;
            $summary['occupancy_percent'] = $summary['available_nights'] > 0
                ? \round(((int) $summary['occupied_nights'] / (int) $summary['available_nights']) * 100, 1)
                : 0.0;
            $summary['occupancy_available'] = true;
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildTrendRows(array $rows, ReportAdminQuery $query): array
    {
        $from = new \DateTimeImmutable($query->getDateFrom());
        $to = new \DateTimeImmutable($query->getDateTo());
        $days = (int) $from->diff($to)->format('%a') + 1;
        $granularity = $days > 92 ? 'month' : 'day';
        $buckets = [];

        foreach ($rows as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $bucket = $this->resolveTrendBucket($createdAt, $granularity);

            if ($bucket === '') {
                continue;
            }

            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = [
                    'reservations' => 0,
                    'revenue' => 0.0,
                ];
            }

            $buckets[$bucket]['reservations']++;

            if (!\in_array((string) ($row['status'] ?? ''), ['blocked', 'cancelled', 'expired', 'payment_failed'], true)) {
                $buckets[$bucket]['revenue'] += (float) ($row['total_price'] ?? 0.0);
            }
        }

        \ksort($buckets);
        $formatted = [];

        foreach ($buckets as $bucket => $data) {
            $formatted[] = [
                'label' => $granularity === 'month' ? $this->formatMonthLabel($bucket) : $this->formatDateLabel($bucket),
                'reservations' => \number_format_i18n((int) $data['reservations']),
                'revenue' => $this->formatMoney((float) $data['revenue'], MustBookingConfig::get_currency()),
            ];
        }

        return [
            'granularity_label' => $granularity === 'month' ? \__('Month', 'must-hotel-booking') : \__('Day', 'must-hotel-booking'),
            'rows' => $formatted,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildCountBreakdown(array $rows, string $field, bool $formatReservationStatus = false): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $key = (string) ($row[$field] ?? '');
            $key = $key !== '' ? $key : 'unknown';
            $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
        }

        \arsort($counts);
        $formatted = [];

        foreach ($counts as $key => $count) {
            $formatted[] = [
                'label' => $formatReservationStatus ? $this->formatReservationStatusLabel($key) : $key,
                'value' => \number_format_i18n((int) $count),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildPaymentStatusBreakdown(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];
            $key = (string) ($paymentState['derived_status'] ?? 'unknown');
            $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
        }

        \arsort($counts);
        $formatted = [];

        foreach ($counts as $key => $count) {
            $formatted[] = [
                'label' => $this->formatPaymentStatusLabel($key),
                'value' => \number_format_i18n((int) $count),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildPaymentMethodBreakdown(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];
            $method = (string) ($paymentState['method'] ?? '');
            $method = $method !== '' ? $method : 'unknown';
            $counts[$method] = isset($counts[$method]) ? $counts[$method] + 1 : 1;
        }

        \arsort($counts);
        $formatted = [];

        foreach ($counts as $method => $count) {
            $formatted[] = [
                'label' => $this->formatPaymentMethodLabel($method),
                'value' => \number_format_i18n((int) $count),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string|int>>
     */
    private function buildAccommodationRows(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $roomId = isset($row['room_id']) ? (int) $row['room_id'] : 0;
            $key = $roomId > 0 ? (string) $roomId : 'unknown';

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'room_id' => $roomId,
                    'room_name' => (string) ($row['room_name'] ?? \__('Unknown accommodation', 'must-hotel-booking')),
                    'reservations' => 0,
                    'nights' => 0,
                    'revenue' => 0.0,
                ];
            }

            $grouped[$key]['reservations']++;
            $grouped[$key]['nights'] += $this->calculateNights((string) ($row['checkin'] ?? ''), (string) ($row['checkout'] ?? ''));

            if (!\in_array((string) ($row['status'] ?? ''), ['blocked', 'cancelled', 'expired', 'payment_failed'], true)) {
                $grouped[$key]['revenue'] += (float) ($row['total_price'] ?? 0.0);
            }
        }

        \uasort($grouped, static function (array $left, array $right): int {
            if ((float) $left['revenue'] === (float) $right['revenue']) {
                return (int) $right['reservations'] <=> (int) $left['reservations'];
            }

            return (float) $right['revenue'] <=> (float) $left['revenue'];
        });

        $formatted = [];

        foreach (\array_slice($grouped, 0, 10, true) as $row) {
            $reservations = (int) $row['reservations'];
            $revenue = (float) $row['revenue'];
            $formatted[] = [
                'room_id' => (int) $row['room_id'],
                'room_name' => (string) $row['room_name'],
                'reservations' => \number_format_i18n($reservations),
                'nights' => \number_format_i18n((int) $row['nights']),
                'revenue' => $this->formatMoney($revenue, MustBookingConfig::get_currency()),
                'average_booking_value' => $this->formatMoney($reservations > 0 ? $revenue / $reservations : 0.0, MustBookingConfig::get_currency()),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildCouponRows(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $couponCode = \strtoupper(\trim((string) ($row['coupon_code'] ?? '')));
            $discount = (float) ($row['coupon_discount_total'] ?? 0.0);

            if ($couponCode === '' || $discount <= 0) {
                continue;
            }

            if (!isset($grouped[$couponCode])) {
                $grouped[$couponCode] = [
                    'coupon_code' => $couponCode,
                    'uses' => 0,
                    'discount_total' => 0.0,
                ];
            }

            $grouped[$couponCode]['uses']++;
            $grouped[$couponCode]['discount_total'] += $discount;
        }

        \uasort($grouped, static function (array $left, array $right): int {
            if ((int) $left['uses'] === (int) $right['uses']) {
                return (float) $right['discount_total'] <=> (float) $left['discount_total'];
            }

            return (int) $right['uses'] <=> (int) $left['uses'];
        });

        $formatted = [];

        foreach (\array_slice($grouped, 0, 10, true) as $row) {
            $formatted[] = [
                'coupon_code' => (string) $row['coupon_code'],
                'uses' => \number_format_i18n((int) $row['uses']),
                'discount_total' => $this->formatMoney((float) $row['discount_total'], MustBookingConfig::get_currency()),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildIssues(array $rows): array
    {
        $counts = [
            'missing_totals' => 0,
            'missing_payment_state' => 0,
            'coupon_inconsistent' => 0,
            'payment_review' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $total = (float) ($row['total_price'] ?? 0.0);
            $paymentStatus = (string) ($row['payment_status'] ?? '');
            $discount = (float) ($row['coupon_discount_total'] ?? 0.0);
            $couponCode = (string) ($row['coupon_code'] ?? '');
            $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];

            if (!\in_array($status, ['blocked', 'cancelled'], true) && $total <= 0.0) {
                $counts['missing_totals']++;
            }

            if ($paymentStatus === '') {
                $counts['missing_payment_state']++;
            }

            if ($discount > 0.0 && \trim($couponCode) === '') {
                $counts['coupon_inconsistent']++;
            }

            if (!empty($paymentState['needs_review'])) {
                $counts['payment_review']++;
            }
        }

        $orphanPayments = $this->reportRepository->countPaymentsLinkedToMissingReservations();
        $issues = [];

        if ($counts['missing_totals'] > 0) {
            $issues[] = [
                'label' => \__('Reservations missing totals', 'must-hotel-booking'),
                'value' => \number_format_i18n($counts['missing_totals']),
            ];
        }

        if ($counts['missing_payment_state'] > 0) {
            $issues[] = [
                'label' => \__('Reservations missing payment state', 'must-hotel-booking'),
                'value' => \number_format_i18n($counts['missing_payment_state']),
            ];
        }

        if ($counts['coupon_inconsistent'] > 0) {
            $issues[] = [
                'label' => \__('Coupon discount rows missing a coupon code', 'must-hotel-booking'),
                'value' => \number_format_i18n($counts['coupon_inconsistent']),
            ];
        }

        if ($counts['payment_review'] > 0) {
            $issues[] = [
                'label' => \__('Reservations with payment warnings', 'must-hotel-booking'),
                'value' => \number_format_i18n($counts['payment_review']),
            ];
        }

        if ($orphanPayments > 0) {
            $issues[] = [
                'label' => \__('Payments linked to missing reservations', 'must-hotel-booking'),
                'value' => \number_format_i18n($orphanPayments),
            ];
        }

        return $issues;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<int, array<string, mixed>>> $paymentsByReservation
     * @return array<int, array<string, mixed>>
     */
    private function decorateRowsWithPaymentState(array $rows, array $paymentsByReservation): array
    {
        foreach ($rows as $index => $row) {
            if (!\is_array($row)) {
                continue;
            }

            $reservationId = isset($row['id']) ? (int) $row['id'] : 0;
            $paymentRows = isset($paymentsByReservation[$reservationId]) && \is_array($paymentsByReservation[$reservationId])
                ? $paymentsByReservation[$reservationId]
                : [];
            $rows[$index]['payment_state'] = PaymentStatusService::buildReservationPaymentState($row, $paymentRows);
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $createdRows
     * @param array<int, array<string, mixed>> $stayRows
     * @return array<int, int>
     */
    private function collectReservationIds(array $createdRows, array $stayRows): array
    {
        $ids = [];

        foreach (\array_merge($createdRows, $stayRows) as $row) {
            if (!\is_array($row) || !isset($row['id'])) {
                continue;
            }

            $id = (int) $row['id'];

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return \array_values($ids);
    }

    private function matchesPaymentMethodFilter(array $row, string $method): bool
    {
        $paymentState = isset($row['payment_state']) && \is_array($row['payment_state']) ? $row['payment_state'] : [];

        return (string) ($paymentState['method'] ?? '') === $method;
    }

    private function countDaysInclusive(string $dateFrom, string $dateTo): int
    {
        if ($dateFrom === '' || $dateTo === '') {
            return 0;
        }

        $from = new \DateTimeImmutable($dateFrom);
        $to = new \DateTimeImmutable($dateTo);

        return (int) $from->diff($to)->format('%a') + 1;
    }

    private function calculateNights(string $checkin, string $checkout): int
    {
        if ($checkin === '' || $checkout === '' || $checkout <= $checkin) {
            return 0;
        }

        $from = new \DateTimeImmutable($checkin);
        $to = new \DateTimeImmutable($checkout);

        return (int) $from->diff($to)->format('%a');
    }

    private function calculateOverlapNights(string $checkin, string $checkout, string $dateFrom, string $dateTo): int
    {
        if ($checkin === '' || $checkout === '' || $dateFrom === '' || $dateTo === '' || $checkout <= $checkin) {
            return 0;
        }

        $start = new \DateTimeImmutable($checkin);
        $end = new \DateTimeImmutable($checkout);
        $rangeStart = new \DateTimeImmutable($dateFrom);
        $rangeEndExclusive = (new \DateTimeImmutable($dateTo))->modify('+1 day');
        $overlapStart = $start > $rangeStart ? $start : $rangeStart;
        $overlapEnd = $end < $rangeEndExclusive ? $end : $rangeEndExclusive;

        if ($overlapEnd <= $overlapStart) {
            return 0;
        }

        return (int) $overlapStart->diff($overlapEnd)->format('%a');
    }

    private function resolveTrendBucket(string $createdAt, string $granularity): string
    {
        if ($createdAt === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($createdAt);
        } catch (\Exception $exception) {
            return '';
        }

        return $granularity === 'month' ? $date->format('Y-m') : $date->format('Y-m-d');
    }

    private function formatMonthLabel(string $bucket): string
    {
        try {
            $date = new \DateTimeImmutable($bucket . '-01');
        } catch (\Exception $exception) {
            return $bucket;
        }

        return $date->format('M Y');
    }

    private function formatDateLabel(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('d M Y');
        } catch (\Exception $exception) {
            return $date;
        }
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return \number_format_i18n($amount, 2) . ' ' . $currency;
    }

    private function formatPercent(float $value): string
    {
        return \number_format_i18n($value, 1) . '%';
    }

    private function formatReservationStatusLabel(string $status): string
    {
        $labels = [
            'pending' => \__('Pending', 'must-hotel-booking'),
            'pending_payment' => \__('Pending Payment', 'must-hotel-booking'),
            'confirmed' => \__('Confirmed', 'must-hotel-booking'),
            'completed' => \__('Completed', 'must-hotel-booking'),
            'cancelled' => \__('Cancelled', 'must-hotel-booking'),
            'payment_failed' => \__('Payment Failed', 'must-hotel-booking'),
            'blocked' => \__('Blocked', 'must-hotel-booking'),
            'expired' => \__('Expired', 'must-hotel-booking'),
            'unknown' => \__('Unknown', 'must-hotel-booking'),
        ];

        return $labels[$status] ?? \ucwords(\str_replace('_', ' ', $status));
    }

    private function formatPaymentStatusLabel(string $status): string
    {
        $labels = [
            'unpaid' => \__('Unpaid', 'must-hotel-booking'),
            'pending' => \__('Pending', 'must-hotel-booking'),
            'partially_paid' => \__('Partially Paid', 'must-hotel-booking'),
            'paid' => \__('Paid', 'must-hotel-booking'),
            'failed' => \__('Failed', 'must-hotel-booking'),
            'refunded' => \__('Refunded', 'must-hotel-booking'),
            'pay_at_hotel' => \__('Pay at Hotel', 'must-hotel-booking'),
            'unknown' => \__('Unknown', 'must-hotel-booking'),
        ];

        return $labels[$status] ?? \ucwords(\str_replace('_', ' ', $status));
    }

    private function formatPaymentMethodLabel(string $method): string
    {
        $catalog = PaymentMethodRegistry::getCatalog();

        if (isset($catalog[$method]['label'])) {
            return (string) ($catalog[$method]['label'] ?? $method);
        }

        if ($method === 'unknown' || $method === '') {
            return \__('Unknown', 'must-hotel-booking');
        }

        return \ucwords(\str_replace('_', ' ', $method));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomOptions(): array
    {
        $options = [];

        foreach ($this->roomRepository->getRoomSelectorRows(true, false) as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $options[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? ''),
            ];
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function buildReservationStatusOptions(): array
    {
        return [
            '' => \__('All reservation statuses', 'must-hotel-booking'),
            'pending' => \__('Pending', 'must-hotel-booking'),
            'pending_payment' => \__('Pending Payment', 'must-hotel-booking'),
            'confirmed' => \__('Confirmed', 'must-hotel-booking'),
            'completed' => \__('Completed', 'must-hotel-booking'),
            'cancelled' => \__('Cancelled', 'must-hotel-booking'),
            'payment_failed' => \__('Payment Failed', 'must-hotel-booking'),
            'blocked' => \__('Blocked', 'must-hotel-booking'),
            'expired' => \__('Expired', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildPaymentMethodOptions(): array
    {
        $options = ['' => \__('All payment methods', 'must-hotel-booking')];

        foreach (PaymentMethodRegistry::getCatalog() as $method => $config) {
            $options[$method] = (string) ($config['label'] ?? $method);
        }

        return $options;
    }
}
