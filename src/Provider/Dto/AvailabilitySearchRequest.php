<?php

namespace MustHotelBooking\Provider\Dto;

final class AvailabilitySearchRequest
{
    /** @var string */
    private $checkin;

    /** @var string */
    private $checkout;

    /** @var int */
    private $guests;

    /** @var string */
    private $category;

    /** @var array<string, mixed> */
    private $context;

    /** @param array<string, mixed> $context */
    public function __construct(string $checkin, string $checkout, int $guests = 1, string $category = 'standard-rooms', array $context = [])
    {
        $this->checkin = $checkin;
        $this->checkout = $checkout;
        $this->guests = \max(1, $guests);
        $this->category = $category !== '' ? $category : 'standard-rooms';
        $this->context = $context;
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        return new self(
            (string) ($context['checkin'] ?? ''),
            (string) ($context['checkout'] ?? ''),
            isset($context['guests']) ? (int) $context['guests'] : 1,
            (string) ($context['accommodation_type'] ?? $context['category'] ?? 'standard-rooms'),
            $context
        );
    }

    public function getCheckin(): string
    {
        return $this->checkin;
    }

    public function getCheckout(): string
    {
        return $this->checkout;
    }

    public function getGuests(): int
    {
        return $this->guests;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}
