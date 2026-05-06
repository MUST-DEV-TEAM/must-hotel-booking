<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockDirectApiUnsupportedException extends \RuntimeException
{
    public static function forCapability(string $capability): self
    {
        return new self(
            \sprintf(
                /* translators: %s is a Clock direct API capability name. */
                \__('Direct Clock API %s is not implemented yet. Configure Local mode or Clock WBE Inline until the endpoint-specific adapter is built.', 'must-hotel-booking'),
                $capability
            )
        );
    }
}
