<?php

namespace MustHotelBooking\Database;

final class GuestRepository extends AbstractRepository
{
    /**
     * @param array<string, mixed> $guestData
     */
    public function createGuest(array $guestData): int
    {
        $inserted = $this->wpdb->insert(
            $this->table('guests'),
            $guestData,
            ['%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return 0;
        }

        return (int) $this->wpdb->insert_id;
    }
}
