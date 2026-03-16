<?php

namespace MustHotelBooking\Core;

final class ReservationStatus
{
    /**
     * @return array<int, string>
     */
    public static function getInventoryBlockingStatuses(): array
    {
        return [
            'pending',
            'pending_payment',
            'confirmed',
            'completed',
            'blocked',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getInventoryNonBlockingStatuses(): array
    {
        return [
            'cancelled',
            'expired',
            'payment_failed',
        ];
    }

    public static function blocksInventory(string $status): bool
    {
        return \in_array(\sanitize_key($status), self::getInventoryBlockingStatuses(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function getConfirmedStatuses(): array
    {
        return [
            'confirmed',
            'completed',
        ];
    }

    public static function isConfirmed(string $status): bool
    {
        return \in_array(\sanitize_key($status), self::getConfirmedStatuses(), true);
    }
}
