<?php

namespace MustHotelBooking\Engine;

final class CurrencyMinorUnits
{
    /** @var array<int, string> */
    private const ZERO_DECIMAL = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg',
        'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
    ];

    public static function toMinor(float $amount, string $currency): int
    {
        $factor = \in_array(\strtolower(\trim($currency)), self::ZERO_DECIMAL, true) ? 1 : 100;
        return (int) \round(\max(0.0, $amount) * $factor);
    }

    public static function fromMinor(int $amountMinor, string $currency): float
    {
        $factor = \in_array(\strtolower(\trim($currency)), self::ZERO_DECIMAL, true) ? 1 : 100;
        return $factor === 1 ? (float) $amountMinor : \round($amountMinor / $factor, 2);
    }
}
