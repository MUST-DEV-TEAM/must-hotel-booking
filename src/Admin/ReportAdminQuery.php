<?php

namespace MustHotelBooking\Admin;

final class ReportAdminQuery
{
    /** @var string */
    private $preset;

    /** @var string */
    private $dateFrom;

    /** @var string */
    private $dateTo;

    /** @var int */
    private $roomId;

    /** @var string */
    private $reservationStatus;

    /** @var string */
    private $paymentMethod;

    private function __construct(string $preset, string $dateFrom, string $dateTo, int $roomId, string $reservationStatus, string $paymentMethod)
    {
        $this->preset = $preset;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->roomId = $roomId;
        $this->reservationStatus = $reservationStatus;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromRequest(array $source): self
    {
        $preset = isset($source['preset']) ? \sanitize_key((string) \wp_unslash($source['preset'])) : 'this_month';
        $allowedPresets = ['today', 'last_7_days', 'this_month', 'last_month', 'this_year', 'custom'];

        if (!\in_array($preset, $allowedPresets, true)) {
            $preset = 'this_month';
        }

        $today = new \DateTimeImmutable(\current_time('Y-m-d'));
        $dateFrom = '';
        $dateTo = '';

        if ($preset === 'today') {
            $dateFrom = $today->format('Y-m-d');
            $dateTo = $dateFrom;
        } elseif ($preset === 'last_7_days') {
            $dateFrom = $today->modify('-6 days')->format('Y-m-d');
            $dateTo = $today->format('Y-m-d');
        } elseif ($preset === 'this_month') {
            $dateFrom = $today->modify('first day of this month')->format('Y-m-d');
            $dateTo = $today->modify('last day of this month')->format('Y-m-d');
        } elseif ($preset === 'last_month') {
            $dateFrom = $today->modify('first day of last month')->format('Y-m-d');
            $dateTo = $today->modify('last day of last month')->format('Y-m-d');
        } elseif ($preset === 'this_year') {
            $dateFrom = $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d');
            $dateTo = $today->setDate((int) $today->format('Y'), 12, 31)->format('Y-m-d');
        } else {
            $rawFrom = isset($source['date_from']) ? \sanitize_text_field((string) \wp_unslash($source['date_from'])) : '';
            $rawTo = isset($source['date_to']) ? \sanitize_text_field((string) \wp_unslash($source['date_to'])) : '';
            $dateFrom = self::isValidDate($rawFrom) ? $rawFrom : $today->modify('first day of this month')->format('Y-m-d');
            $dateTo = self::isValidDate($rawTo) ? $rawTo : $today->format('Y-m-d');
        }

        if ($dateFrom > $dateTo) {
            $swap = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $swap;
        }

        return new self(
            $preset,
            $dateFrom,
            $dateTo,
            isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0,
            isset($source['reservation_status']) ? \sanitize_key((string) \wp_unslash($source['reservation_status'])) : '',
            isset($source['payment_method']) ? \sanitize_key((string) \wp_unslash($source['payment_method'])) : ''
        );
    }

    public function getPreset(): string
    {
        return $this->preset;
    }

    public function getDateFrom(): string
    {
        return $this->dateFrom;
    }

    public function getDateTo(): string
    {
        return $this->dateTo;
    }

    public function getRoomId(): int
    {
        return $this->roomId;
    }

    public function getReservationStatus(): string
    {
        return $this->reservationStatus;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'room_id' => $this->roomId,
            'reservation_status' => $this->reservationStatus,
            'payment_method' => $this->paymentMethod,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        return \array_merge($this->toArray(), $overrides);
    }

    private static function isValidDate(string $value): bool
    {
        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
