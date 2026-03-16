<?php

namespace MustHotelBooking\Database;

final class GuestRepository extends AbstractRepository
{
    public function guestsTableExists(): bool
    {
        return $this->tableExists('guests');
    }

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

    public function saveGuestProfile(
        int $guestId,
        string $firstName,
        string $lastName,
        string $email,
        string $phone
    ): int {
        if (!$this->guestsTableExists()) {
            return 0;
        }

        if ($guestId > 0) {
            $updated = $this->wpdb->update(
                $this->table('guests'),
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'phone' => $phone,
                ],
                ['id' => $guestId],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );

            if ($updated !== false) {
                return $guestId;
            }
        }

        if ($email !== '') {
            $existingGuestId = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SELECT id
                    FROM ' . $this->table('guests') . '
                    WHERE email = %s
                    LIMIT 1',
                    $email
                )
            );

            if ($existingGuestId > 0) {
                $updated = $this->wpdb->update(
                    $this->table('guests'),
                    [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'phone' => $phone,
                    ],
                    ['id' => $existingGuestId],
                    ['%s', '%s', '%s'],
                    ['%d']
                );

                if ($updated !== false) {
                    return $existingGuestId;
                }
            }
        }

        return $this->createGuest(
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'country' => '',
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAdminGuestRows(): array
    {
        if (!$this->guestsTableExists()) {
            return [];
        }

        if ($this->tableExists('reservations')) {
            $rows = $this->wpdb->get_results(
                'SELECT
                    g.id,
                    g.first_name,
                    g.last_name,
                    g.email,
                    g.phone,
                    g.country,
                    COUNT(r.id) AS total_bookings
                FROM ' . $this->table('guests') . ' g
                LEFT JOIN ' . $this->table('reservations') . ' r
                    ON r.guest_id = g.id
                GROUP BY g.id, g.first_name, g.last_name, g.email, g.phone, g.country
                ORDER BY g.id DESC',
                ARRAY_A
            );
        } else {
            $rows = $this->wpdb->get_results(
                'SELECT
                    g.id,
                    g.first_name,
                    g.last_name,
                    g.email,
                    g.phone,
                    g.country,
                    0 AS total_bookings
                FROM ' . $this->table('guests') . ' g
                ORDER BY g.id DESC',
                ARRAY_A
            );
        }

        return \is_array($rows) ? $rows : [];
    }
}
