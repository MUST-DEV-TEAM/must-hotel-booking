<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Engine\AvailabilityEngine;

final class CalendarViewQuery
{
    /** @var array<int, string> */
    private const DEFAULT_VISIBILITY = ['booked', 'pending', 'blocked', 'unavailable', 'hold'];

    /** @var array<int, string> */
    private const ALLOWED_VISIBILITY = ['booked', 'pending', 'blocked', 'unavailable', 'hold', 'cancelled'];

    /** @var int */
    private const DEFAULT_WEEKS = 2;

    /** @var int */
    private const MIN_WEEKS = 1;

    /** @var int */
    private const MAX_WEEKS = 6;

    /** @var string */
    private $month;

    /** @var string */
    private $startDate;

    /** @var string */
    private $endDate;

    /** @var string */
    private $endDateExclusive;

    /** @var int */
    private $weeks;

    /** @var string */
    private $category;

    /** @var int */
    private $roomId;

    /** @var array<int, string> */
    private $visibility;

    /** @var string */
    private $focusDate;

    /** @var int */
    private $focusRoomId;

    /** @var int */
    private $reservationId;

    /**
     * @param array<int, string> $visibility
     */
    private function __construct(
        string $month,
        string $startDate,
        string $endDate,
        string $endDateExclusive,
        int $weeks,
        string $category,
        int $roomId,
        array $visibility,
        string $focusDate,
        int $focusRoomId,
        int $reservationId
    ) {
        $this->month = $month;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->endDateExclusive = $endDateExclusive;
        $this->weeks = $weeks;
        $this->category = $category;
        $this->roomId = \max(0, $roomId);
        $this->visibility = $visibility;
        $this->focusDate = $focusDate;
        $this->focusRoomId = \max(0, $focusRoomId);
        $this->reservationId = \max(0, $reservationId);
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function fromRequest(array $request): self
    {
        $today = \current_time('Y-m-d');
        $focusDate = self::sanitizeDateValue(isset($request['focus_date']) ? (string) $request['focus_date'] : '');
        $requestedMonth = isset($request['month']) ? (string) $request['month'] : '';
        $monthIsExplicit = \preg_match('/^\d{4}-\d{2}$/', \trim($requestedMonth)) === 1;
        $startDate = self::sanitizeDateValue(
            isset($request['start_date'])
                ? (string) $request['start_date']
                : (isset($request['start']) ? (string) $request['start'] : '')
        );

        if ($startDate === '') {
            if ($focusDate !== '') {
                $startDate = $focusDate;
            } elseif ($monthIsExplicit) {
                $month = self::sanitizeMonthInput($requestedMonth, '', $today);
                $startDate = $month . '-01';
            } else {
                $startDate = $today;
            }
        }

        $month = self::sanitizeMonthInput($requestedMonth, $startDate, $today);
        $weeks = self::sanitizeWeeks(
            isset($request['weeks']) ? $request['weeks'] : null,
            $startDate,
            isset($request['end_date']) ? (string) $request['end_date'] : ''
        );
        $range = self::resolveRangeBounds($startDate, $weeks);
        $categoryInput = isset($request['accommodation_type']) ? (string) $request['accommodation_type'] : '';
        $category = $categoryInput !== ''
            ? RoomCatalog::normalizeBookingCategory($categoryInput)
            : RoomCatalog::BOOKING_ALL_CATEGORY;
        $visibility = self::sanitizeVisibility(isset($request['visibility']) ? $request['visibility'] : []);

        return new self(
            $month,
            (string) $range['start'],
            (string) $range['end'],
            (string) $range['end_exclusive'],
            $weeks,
            $category,
            isset($request['room_id']) ? (int) $request['room_id'] : 0,
            $visibility,
            $focusDate,
            isset($request['focus_room_id']) ? (int) $request['focus_room_id'] : 0,
            isset($request['reservation_id']) ? (int) $request['reservation_id'] : 0
        );
    }

    /**
     * @return array<int, string>
     */
    public static function getDefaultVisibility(): array
    {
        return self::DEFAULT_VISIBILITY;
    }

    /**
     * @return array<string, string>
     */
    public static function getVisibilityOptions(): array
    {
        return [
            'booked' => \__('Booked', 'must-hotel-booking'),
            'pending' => \__('Pending', 'must-hotel-booking'),
            'blocked' => \__('Blocked', 'must-hotel-booking'),
            'unavailable' => \__('Unavailable', 'must-hotel-booking'),
            'hold' => \__('Temporary Holds', 'must-hotel-booking'),
            'cancelled' => \__('Cancelled', 'must-hotel-booking'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getWeekOptions(): array
    {
        $options = [];

        for ($week = self::MIN_WEEKS; $week <= self::MAX_WEEKS; $week++) {
            $options[$week] = \sprintf(
                /* translators: %d is number of weeks shown in the board. */
                \_n('%d week', '%d weeks', $week, 'must-hotel-booking'),
                $week
            );
        }

        return $options;
    }

    public function getMonth(): string
    {
        return $this->month;
    }

    public function getStartDate(): string
    {
        return $this->startDate;
    }

    public function getEndDate(): string
    {
        return $this->endDate;
    }

    public function getEndDateExclusive(): string
    {
        return $this->endDateExclusive;
    }

    public function getWeeks(): int
    {
        return $this->weeks;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getRoomId(): int
    {
        return $this->roomId;
    }

    /**
     * @return array<int, string>
     */
    public function getVisibility(): array
    {
        return $this->visibility;
    }

    public function isVisible(string $key): bool
    {
        return \in_array(\sanitize_key($key), $this->visibility, true);
    }

    public function getFocusDate(): string
    {
        return $this->focusDate;
    }

    public function getFocusRoomId(): int
    {
        return $this->focusRoomId;
    }

    public function getReservationId(): int
    {
        return $this->reservationId;
    }

    public function getRangeLabel(): string
    {
        if ($this->startDate === $this->endDate) {
            return \wp_date(\get_option('date_format'), \strtotime($this->startDate));
        }

        return \wp_date('M j', \strtotime($this->startDate)) . ' - ' . \wp_date('M j, Y', \strtotime($this->endDate));
    }

    public function getRangeDayCount(): int
    {
        return \max(1, $this->weeks * 7);
    }

    /**
     * @return array<string, string|int>
     */
    public function getPreviousRangeArgs(): array
    {
        $days = $this->getRangeDayCount();
        $start = (new \DateTimeImmutable($this->startDate))->modify('-' . $days . ' days');

        return [
            'month' => $start->format('Y-m'),
            'start_date' => $start->format('Y-m-d'),
            'weeks' => $this->weeks,
        ];
    }

    /**
     * @return array<string, string|int>
     */
    public function getNextRangeArgs(): array
    {
        $days = $this->getRangeDayCount();
        $start = (new \DateTimeImmutable($this->startDate))->modify('+' . $days . ' days');

        return [
            'month' => $start->format('Y-m'),
            'start_date' => $start->format('Y-m-d'),
            'weeks' => $this->weeks,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getDates(): array
    {
        $dates = [];
        $pointer = new \DateTimeImmutable($this->startDate);
        $end = new \DateTimeImmutable($this->endDateExclusive);

        while ($pointer < $end) {
            $dates[] = $pointer->format('Y-m-d');
            $pointer = $pointer->modify('+1 day');
        }

        return $dates;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        $startDate = \array_key_exists('start_date', $overrides)
            ? self::sanitizeDateValue((string) $overrides['start_date'])
            : $this->startDate;
        $weeks = \array_key_exists('weeks', $overrides)
            ? self::sanitizeWeeks($overrides['weeks'], $startDate, '')
            : $this->weeks;
        $month = $startDate !== ''
            ? \substr($startDate, 0, 7)
            : (isset($overrides['month']) ? (string) $overrides['month'] : $this->month);
        $category = isset($overrides['accommodation_type']) ? (string) $overrides['accommodation_type'] : $this->category;
        $roomId = isset($overrides['room_id']) ? (int) $overrides['room_id'] : $this->roomId;
        $visibility = isset($overrides['visibility']) && \is_array($overrides['visibility'])
            ? self::sanitizeVisibility($overrides['visibility'])
            : $this->visibility;
        $focusDate = isset($overrides['focus_date']) ? self::sanitizeDateValue((string) $overrides['focus_date']) : $this->focusDate;
        $focusRoomId = isset($overrides['focus_room_id']) ? \max(0, (int) $overrides['focus_room_id']) : $this->focusRoomId;
        $reservationId = isset($overrides['reservation_id']) ? \max(0, (int) $overrides['reservation_id']) : $this->reservationId;
        $args = [
            'month' => $month,
            'start_date' => $startDate,
        ];

        if ($weeks !== self::DEFAULT_WEEKS) {
            $args['weeks'] = $weeks;
        }

        if ($category !== '' && !RoomCatalog::isBookingAllCategory($category)) {
            $args['accommodation_type'] = $category;
        }

        if ($roomId > 0) {
            $args['room_id'] = $roomId;
        }

        if ($visibility !== self::DEFAULT_VISIBILITY) {
            $args['visibility'] = $visibility;
        }

        if ($focusDate !== '') {
            $args['focus_date'] = $focusDate;
        }

        if ($focusRoomId > 0) {
            $args['focus_room_id'] = $focusRoomId;
        }

        if ($reservationId > 0) {
            $args['reservation_id'] = $reservationId;
        }

        return $args;
    }

    private static function sanitizeMonthInput(string $month, string $startDate, string $fallbackDate): string
    {
        $candidate = \trim($month);

        if (\preg_match('/^\d{4}-\d{2}$/', $candidate) === 1) {
            $monthDate = $candidate . '-01';

            if (AvailabilityEngine::isValidBookingDate($monthDate)) {
                return $candidate;
            }
        }

        $startCandidate = self::sanitizeDateValue($startDate);

        if ($startCandidate !== '') {
            return \substr($startCandidate, 0, 7);
        }

        return \substr($fallbackDate, 0, 7);
    }

    /**
     * @param mixed $rawWeeks
     */
    private static function sanitizeWeeks($rawWeeks, string $startDate, string $endDate): int
    {
        $weeks = \is_numeric($rawWeeks) ? (int) $rawWeeks : 0;

        if ($weeks >= self::MIN_WEEKS && $weeks <= self::MAX_WEEKS) {
            return $weeks;
        }

        $normalizedEnd = self::sanitizeDateValue($endDate);

        if ($startDate !== '' && $normalizedEnd !== '' && $normalizedEnd >= $startDate) {
            $start = new \DateTimeImmutable($startDate);
            $end = new \DateTimeImmutable($normalizedEnd);
            $days = ((int) $start->diff($end)->format('%a')) + 1;
            $resolvedWeeks = (int) \ceil($days / 7);

            return \max(self::MIN_WEEKS, \min(self::MAX_WEEKS, $resolvedWeeks));
        }

        return self::DEFAULT_WEEKS;
    }

    /**
     * @return array{start: string, end: string, end_exclusive: string}
     */
    private static function resolveRangeBounds(string $startDate, int $weeks): array
    {
        $start = new \DateTimeImmutable($startDate);
        $end = $start->modify('+' . (($weeks * 7) - 1) . ' days');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'end_exclusive' => $end->modify('+1 day')->format('Y-m-d'),
        ];
    }

    /**
     * @param mixed $rawVisibility
     * @return array<int, string>
     */
    private static function sanitizeVisibility($rawVisibility): array
    {
        $items = [];

        if (\is_array($rawVisibility)) {
            $items = $rawVisibility;
        } elseif (\is_string($rawVisibility) && $rawVisibility !== '') {
            $items = \explode(',', $rawVisibility);
        }

        $visibility = [];

        foreach ($items as $item) {
            $key = \sanitize_key((string) $item);

            if ($key !== '' && \in_array($key, self::ALLOWED_VISIBILITY, true)) {
                $visibility[$key] = $key;
            }
        }

        if (empty($visibility)) {
            return self::DEFAULT_VISIBILITY;
        }

        return \array_values($visibility);
    }

    private static function sanitizeDateValue(string $value): string
    {
        $candidate = \trim($value);

        if (AvailabilityEngine::isValidBookingDate($candidate)) {
            return $candidate;
        }

        return '';
    }
}
