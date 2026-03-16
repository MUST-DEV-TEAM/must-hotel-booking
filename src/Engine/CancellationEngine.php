<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;

final class CancellationEngine
{
    /**
     * @return array<string, mixed>|null
     */
    public static function getCancellationPolicy(int $ratePlanId): ?array
    {
        if ($ratePlanId <= 0) {
            return null;
        }

        $policy = get_cancellation_policy_repository()->getPolicyByRatePlanId($ratePlanId);

        if (!\is_array($policy)) {
            return null;
        }

        return self::normalizePolicy($policy);
    }

    public static function calculatePenalty(int $reservationId, string $cancelDate): float
    {
        $details = self::getPenaltyDetails($reservationId, $cancelDate);

        return isset($details['penalty_amount']) ? (float) $details['penalty_amount'] : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPenaltyDetails(int $reservationId, string $cancelDate): array
    {
        $reservation = get_reservation_repository()->getReservation($reservationId);

        if (!\is_array($reservation)) {
            return [
                'success' => false,
                'penalty_amount' => 0.0,
                'penalty_percent' => 0.0,
                'penalty_applied' => false,
                'message' => 'Reservation not found.',
            ];
        }

        $ratePlanId = isset($reservation['rate_plan_id']) ? (int) $reservation['rate_plan_id'] : 0;
        $policy = self::getCancellationPolicy($ratePlanId);

        if (!\is_array($policy)) {
            return [
                'success' => true,
                'reservation_id' => $reservationId,
                'rate_plan_id' => $ratePlanId,
                'policy_id' => 0,
                'policy_name' => '',
                'hours_before_checkin' => 0,
                'penalty_percent' => 0.0,
                'penalty_amount' => 0.0,
                'penalty_applied' => false,
                'within_free_window' => true,
                'cancel_date' => $cancelDate,
                'message' => '',
            ];
        }

        $timezone = self::getConfiguredTimezone();
        $checkinDateTime = self::buildCheckinDateTime((string) ($reservation['checkin'] ?? ''), $timezone);
        $cancelDateTime = self::parseDateTime($cancelDate, $timezone);

        if (!$checkinDateTime instanceof \DateTimeImmutable || !$cancelDateTime instanceof \DateTimeImmutable) {
            return [
                'success' => false,
                'reservation_id' => $reservationId,
                'rate_plan_id' => $ratePlanId,
                'policy_id' => (int) ($policy['id'] ?? 0),
                'policy_name' => (string) ($policy['name'] ?? ''),
                'hours_before_checkin' => (int) ($policy['hours_before_checkin'] ?? 0),
                'penalty_percent' => (float) ($policy['penalty_percent'] ?? 0.0),
                'penalty_amount' => 0.0,
                'penalty_applied' => false,
                'within_free_window' => false,
                'cancel_date' => $cancelDate,
                'message' => 'Cancellation date is invalid.',
            ];
        }

        $hoursBeforeCheckin = \max(0, (int) ($policy['hours_before_checkin'] ?? 0));
        $penaltyPercent = \max(0.0, \min(100.0, (float) ($policy['penalty_percent'] ?? 0.0)));
        $totalPrice = isset($reservation['total_price']) ? \max(0.0, (float) $reservation['total_price']) : 0.0;
        $hoursUntilCheckin = ($checkinDateTime->getTimestamp() - $cancelDateTime->getTimestamp()) / 3600;
        $withinFreeWindow = $hoursBeforeCheckin > 0 && $hoursUntilCheckin >= $hoursBeforeCheckin;
        $penaltyAmount = $withinFreeWindow ? 0.0 : \round($totalPrice * ($penaltyPercent / 100), 2);

        return [
            'success' => true,
            'reservation_id' => $reservationId,
            'rate_plan_id' => $ratePlanId,
            'policy_id' => (int) ($policy['id'] ?? 0),
            'policy_name' => (string) ($policy['name'] ?? ''),
            'hours_before_checkin' => $hoursBeforeCheckin,
            'penalty_percent' => $penaltyPercent,
            'penalty_amount' => $penaltyAmount,
            'penalty_applied' => $penaltyAmount > 0,
            'within_free_window' => $withinFreeWindow,
            'hours_until_checkin' => \round($hoursUntilCheckin, 2),
            'cancel_date' => $cancelDateTime->format('Y-m-d H:i:s'),
            'checkin_at' => $checkinDateTime->format('Y-m-d H:i:s'),
            'free_cancellation_deadline' => $checkinDateTime->modify('-' . $hoursBeforeCheckin . ' hours')->format('Y-m-d H:i:s'),
            'reservation_total' => \round($totalPrice, 2),
            'message' => '',
        ];
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private static function normalizePolicy(array $policy): array
    {
        return [
            'id' => isset($policy['id']) ? (int) $policy['id'] : 0,
            'name' => isset($policy['name']) ? (string) $policy['name'] : '',
            'hours_before_checkin' => isset($policy['hours_before_checkin']) ? \max(0, (int) $policy['hours_before_checkin']) : 0,
            'penalty_percent' => isset($policy['penalty_percent']) ? \max(0.0, \min(100.0, (float) $policy['penalty_percent'])) : 0.0,
            'description' => isset($policy['description']) ? (string) $policy['description'] : '',
        ];
    }

    private static function getConfiguredTimezone(): \DateTimeZone
    {
        $timezoneName = \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::get_timezone()
            : 'UTC';

        try {
            return new \DateTimeZone($timezoneName);
        } catch (\Exception $exception) {
            return new \DateTimeZone('UTC');
        }
    }

    private static function buildCheckinDateTime(string $checkinDate, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if ($checkinDate === '') {
            return null;
        }

        $checkinTime = \class_exists(MustBookingConfig::class)
            ? MustBookingConfig::get_checkin_time()
            : '14:00';

        return self::parseDateTime($checkinDate . ' ' . $checkinTime . ':00', $timezone);
    }

    private static function parseDateTime(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        $value = \trim($value);

        if ($value === '') {
            return new \DateTimeImmutable('now', $timezone);
        }

        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
        ];

        foreach ($formats as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat($format, $value, $timezone);

            if ($dateTime instanceof \DateTimeImmutable) {
                if ($format === 'Y-m-d') {
                    return $dateTime->setTime(0, 0, 0);
                }

                if ($format === 'Y-m-d H:i') {
                    return $dateTime->setTime(
                        (int) $dateTime->format('H'),
                        (int) $dateTime->format('i'),
                        0
                    );
                }

                return $dateTime;
            }
        }

        try {
            return new \DateTimeImmutable($value, $timezone);
        } catch (\Exception $exception) {
            return null;
        }
    }
}
