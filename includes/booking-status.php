<?php

namespace must_hotel_booking;

/**
 * Get reservation statuses that should continue blocking inventory.
 *
 * @return array<int, string>
 */
function get_inventory_blocking_reservation_statuses(): array
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
 * Get reservation statuses that should no longer block inventory.
 *
 * @return array<int, string>
 */
function get_inventory_non_blocking_reservation_statuses(): array
{
    return [
        'cancelled',
        'expired',
        'payment_failed',
    ];
}

/**
 * Check whether a reservation status should block availability.
 */
function does_reservation_status_block_inventory(string $status): bool
{
    return \in_array(\sanitize_key($status), get_inventory_blocking_reservation_statuses(), true);
}

/**
 * Get reservation statuses considered final guest-facing confirmations.
 *
 * @return array<int, string>
 */
function get_confirmed_reservation_statuses(): array
{
    return [
        'confirmed',
        'completed',
    ];
}

/**
 * Check whether a reservation is in a guest-confirmed state.
 */
function is_reservation_confirmed_status(string $status): bool
{
    return \in_array(\sanitize_key($status), get_confirmed_reservation_statuses(), true);
}
