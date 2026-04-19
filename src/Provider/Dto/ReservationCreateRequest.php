<?php

namespace MustHotelBooking\Provider\Dto;

final class ReservationCreateRequest
{
    /** @var array<string, mixed> */
    private $context;

    /** @var array<string, string> */
    private $guestForm;

    /** @var string */
    private $couponCode;

    /** @var array<string, mixed> */
    private $options;

    /** @param array<string, mixed> $context @param array<string, string> $guestForm @param array<string, mixed> $options */
    public function __construct(array $context, array $guestForm, string $couponCode = '', array $options = [])
    {
        $this->context = $context;
        $this->guestForm = $guestForm;
        $this->couponCode = $couponCode;
        $this->options = $options;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    /** @return array<string, string> */
    public function getGuestForm(): array
    {
        return $this->guestForm;
    }

    public function getCouponCode(): string
    {
        return $this->couponCode;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array
    {
        return $this->options;
    }
}
