<?php

namespace MustHotelBooking\Provider\Dto;

final class DisabledDatesRequest
{
    /** @var string */
    private $checkin;

    /** @var int */
    private $guests;

    /** @var int */
    private $roomCount;

    /** @var int */
    private $roomId;

    /** @var string */
    private $category;

    /** @var int */
    private $windowDays;

    public function __construct(
        string $checkin = '',
        int $guests = 1,
        int $roomCount = 0,
        int $roomId = 0,
        string $category = 'standard-rooms',
        int $windowDays = 180
    ) {
        $this->checkin = $checkin;
        $this->guests = \max(1, $guests);
        $this->roomCount = \max(0, $roomCount);
        $this->roomId = \max(0, $roomId);
        $this->category = $category !== '' ? $category : 'standard-rooms';
        $this->windowDays = \max(1, $windowDays);
    }

    public function getCheckin(): string
    {
        return $this->checkin;
    }

    public function getGuests(): int
    {
        return $this->guests;
    }

    public function getRoomCount(): int
    {
        return $this->roomCount;
    }

    public function getRoomId(): int
    {
        return $this->roomId;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getWindowDays(): int
    {
        return $this->windowDays;
    }
}
