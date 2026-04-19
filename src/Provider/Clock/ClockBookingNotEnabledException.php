<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockBookingNotEnabledException extends \RuntimeException
{
    public static function forPhase(): self
    {
        return new self('Clock booking operations are not enabled in this plugin version.');
    }
}
