<?php

namespace MustHotelBooking\Provider\Dto;

final class QuoteRequest
{
    /** @var array<string, mixed> */
    private $context;

    /** @var string */
    private $couponCode;

    /** @var array<string, string> */
    private $guestForm;

    /** @var bool */
    private $strictRoomGuests;

    /** @param array<string, mixed> $context @param array<string, string> $guestForm */
    public function __construct(array $context, string $couponCode = '', array $guestForm = [], bool $strictRoomGuests = false)
    {
        $this->context = $context;
        $this->couponCode = $couponCode;
        $this->guestForm = $guestForm;
        $this->strictRoomGuests = $strictRoomGuests;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getCouponCode(): string
    {
        return $this->couponCode;
    }

    /** @return array<string, string> */
    public function getGuestForm(): array
    {
        return $this->guestForm;
    }

    public function shouldUseStrictRoomGuests(): bool
    {
        return $this->strictRoomGuests;
    }
}
