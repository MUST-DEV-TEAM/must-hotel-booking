<?php

namespace MustHotelBooking\Database;

final class GuestRepository extends AbstractRepository
{
    public function guestsTableExists(): bool
    {
        return $this->tableExists('guests');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGuestById(int $guestId): ?array
    {
        if ($guestId <= 0 || !$this->guestsTableExists()) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT id, first_name, last_name, email, phone, country, admin_notes, vip_flag, problem_flag
                FROM ' . $this->table('guests') . '
                WHERE id = %d
                LIMIT 1',
                $guestId
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $guestData
     */
    public function createGuest(array $guestData): int
    {
        if (!$this->guestsTableExists()) {
            return 0;
        }

        $guestData = \array_merge(
            [
                'first_name' => '',
                'last_name' => '',
                'email' => '',
                'phone' => '',
                'country' => '',
                'admin_notes' => '',
                'vip_flag' => 0,
                'problem_flag' => 0,
            ],
            $guestData
        );

        $inserted = $this->wpdb->insert(
            $this->table('guests'),
            [
                'first_name' => (string) $guestData['first_name'],
                'last_name' => (string) $guestData['last_name'],
                'email' => (string) $guestData['email'],
                'phone' => (string) $guestData['phone'],
                'country' => (string) $guestData['country'],
                'admin_notes' => (string) $guestData['admin_notes'],
                'vip_flag' => !empty($guestData['vip_flag']) ? 1 : 0,
                'problem_flag' => !empty($guestData['problem_flag']) ? 1 : 0,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d']
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
            ]
        );
    }

    public function saveAdminGuestProfile(
        int $guestId,
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $country
    ): int {
        if (!$this->guestsTableExists()) {
            return 0;
        }

        $guestData = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'country' => $country,
        ];

        if ($guestId > 0) {
            $updated = $this->wpdb->update(
                $this->table('guests'),
                $guestData,
                ['id' => $guestId],
                ['%s', '%s', '%s', '%s', '%s'],
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
                        'country' => $country,
                    ],
                    ['id' => $existingGuestId],
                    ['%s', '%s', '%s', '%s'],
                    ['%d']
                );

                if ($updated !== false) {
                    return $existingGuestId;
                }
            }
        }

        return $this->createGuest($guestData);
    }

    /**
     * @param array<string, mixed> $guestData
     */
    public function updateAdminGuestRecord(int $guestId, array $guestData): bool
    {
        if ($guestId <= 0 || !$this->guestsTableExists()) {
            return false;
        }

        $data = [
            'first_name' => isset($guestData['first_name']) ? (string) $guestData['first_name'] : '',
            'last_name' => isset($guestData['last_name']) ? (string) $guestData['last_name'] : '',
            'email' => isset($guestData['email']) ? (string) $guestData['email'] : '',
            'phone' => isset($guestData['phone']) ? (string) $guestData['phone'] : '',
            'country' => isset($guestData['country']) ? (string) $guestData['country'] : '',
            'admin_notes' => isset($guestData['admin_notes']) ? (string) $guestData['admin_notes'] : '',
            'vip_flag' => !empty($guestData['vip_flag']) ? 1 : 0,
            'problem_flag' => !empty($guestData['problem_flag']) ? 1 : 0,
        ];

        $updated = $this->wpdb->update(
            $this->table('guests'),
            $data,
            ['id' => $guestId],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d'],
            ['%d']
        );

        return \is_int($updated);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAdminGuestRows(): array
    {
        return $this->getAdminGuestListRows(
            [
                'per_page' => 50,
                'paged' => 1,
            ]
        );
    }

    /**
     * @return array<string, string>
     */
    public function getGuestCountries(): array
    {
        if (!$this->guestsTableExists()) {
            return [];
        }

        $rows = $this->wpdb->get_col(
            'SELECT DISTINCT country
            FROM ' . $this->table('guests') . '
            WHERE TRIM(country) <> \'\'
            ORDER BY country ASC'
        );
        $options = [];

        foreach (\is_array($rows) ? $rows : [] as $country) {
            $country = \trim((string) $country);

            if ($country !== '') {
                $options[$country] = $country;
            }
        }

        return $options;
    }

    public function countAdminGuestListRows(array $filters = []): int
    {
        if (!$this->guestsTableExists()) {
            return 0;
        }

        $filters = $this->normalizeAdminGuestFilters($filters);
        $reservationsTable = $this->table('reservations');
        $where = $this->buildAdminGuestWhereClause($filters);
        $statsJoin = $this->buildReservationStatsJoin($reservationsTable, (string) $filters['today']);
        $sql = 'SELECT COUNT(*)
            FROM ' . $this->table('guests') . ' g
            ' . $statsJoin . '
            ' . $where['sql'];
        $count = empty($where['params'])
            ? $this->wpdb->get_var($sql)
            : $this->wpdb->get_var($this->wpdb->prepare($sql, ...$where['params']));

        return $count !== null ? (int) $count : 0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function getAdminGuestListRows(array $filters = []): array
    {
        if (!$this->guestsTableExists()) {
            return [];
        }

        $filters = $this->normalizeAdminGuestFilters($filters);
        $reservationsTable = $this->table('reservations');
        $paymentsTable = $this->table('payments');
        $statsJoin = $this->buildReservationStatsJoin($reservationsTable, (string) $filters['today']);
        $paymentJoin = $this->tableExists('payments')
            ? $this->buildPaymentStatsJoin($reservationsTable, $paymentsTable)
            : '';
        $duplicateJoin = $this->buildDuplicateJoin();
        $where = $this->buildAdminGuestWhereClause($filters);
        $sql = 'SELECT
                g.id,
                g.first_name,
                g.last_name,
                g.email,
                g.phone,
                g.country,
                g.admin_notes,
                g.vip_flag,
                g.problem_flag,
                COALESCE(stats.total_reservations, 0) AS total_reservations,
                COALESCE(stats.total_completed_stays, 0) AS total_completed_stays,
                COALESCE(stats.total_cancellations, 0) AS total_cancellations,
                COALESCE(stats.first_reservation_at, \'\') AS first_reservation_at,
                COALESCE(stats.last_stay_date, \'\') AS last_stay_date,
                COALESCE(stats.next_checkin, \'\') AS next_checkin,
                COALESCE(stats.next_checkout, \'\') AS next_checkout,
                COALESCE(stats.in_house_checkin, \'\') AS in_house_checkin,
                COALESCE(stats.in_house_checkout, \'\') AS in_house_checkout,
                COALESCE(stats.upcoming_unpaid_count, 0) AS upcoming_unpaid_count,
                COALESCE(stats.upcoming_failed_count, 0) AS upcoming_failed_count,
                COALESCE(pay.total_paid, 0) AS total_paid,
                COALESCE(pay.failed_payment_count, 0) AS failed_payment_count,
                COALESCE(dup.email_duplicates, 0) AS email_duplicates
            FROM ' . $this->table('guests') . ' g
            ' . $statsJoin . '
            ' . $paymentJoin . '
            ' . $duplicateJoin . '
            ' . $where['sql'] . '
            ORDER BY
                CASE WHEN COALESCE(stats.in_house_checkin, \'\') <> \'\' THEN 0 ELSE 1 END ASC,
                CASE WHEN COALESCE(stats.next_checkin, \'\') <> \'\' THEN 0 ELSE 1 END ASC,
                COALESCE(stats.next_checkin, \'9999-12-31\') ASC,
                COALESCE(stats.last_stay_date, \'0000-00-00\') DESC,
                g.id DESC
            LIMIT %d OFFSET %d';

        $params = $where['params'];
        $params[] = (int) $filters['per_page'];
        $params[] = (int) $filters['offset'];
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...$params),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGuestOperationalSummary(int $guestId): ?array
    {
        if ($guestId <= 0) {
            return null;
        }

        $rows = $this->getAdminGuestListRows(
            [
                'guest_id' => $guestId,
                'per_page' => 1,
                'paged' => 1,
            ]
        );

        return isset($rows[0]) && \is_array($rows[0]) ? $rows[0] : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getGuestReservationHistory(int $guestId, int $limit = 50): array
    {
        if ($guestId <= 0 || !$this->tableExists('reservations')) {
            return [];
        }

        $limit = \max(1, \min(200, $limit));
        $roomJoin = $this->tableExists('rooms')
            ? ' LEFT JOIN ' . $this->table('rooms') . ' rm ON rm.id = r.room_id'
            : '';
        $roomSelect = $this->tableExists('rooms')
            ? 'COALESCE(rm.name, \'\') AS room_name'
            : '\'\' AS room_name';
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT
                    r.id,
                    r.booking_id,
                    r.room_id,
                    r.guest_id,
                    r.checkin,
                    r.checkout,
                    r.status,
                    r.payment_status,
                    r.total_price,
                    r.created_at,
                    ' . $roomSelect . '
                FROM ' . $this->table('reservations') . ' r
                ' . $roomJoin . '
                WHERE r.guest_id = %d
                ORDER BY r.checkin DESC, r.id DESC
                LIMIT %d',
                $guestId,
                $limit
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findPossibleDuplicateGuests(int $guestId): array
    {
        $guest = $this->getGuestById($guestId);

        if (!\is_array($guest)) {
            return [];
        }

        $email = \trim((string) ($guest['email'] ?? ''));
        $phone = \trim((string) ($guest['phone'] ?? ''));
        $firstName = \trim((string) ($guest['first_name'] ?? ''));
        $lastName = \trim((string) ($guest['last_name'] ?? ''));
        $clauses = [];
        $params = [];

        if ($email !== '') {
            $clauses[] = 'LOWER(TRIM(email)) = LOWER(TRIM(%s))';
            $params[] = $email;
        }

        if ($email === '' && $phone !== '' && ($firstName !== '' || $lastName !== '')) {
            $clauses[] = '(TRIM(phone) = TRIM(%s) AND LOWER(TRIM(first_name)) = LOWER(TRIM(%s)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(%s)))';
            $params[] = $phone;
            $params[] = $firstName;
            $params[] = $lastName;
        }

        if (empty($clauses)) {
            return [];
        }

        $params[] = $guestId;
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, first_name, last_name, email, phone, country, admin_notes, vip_flag, problem_flag
                FROM ' . $this->table('guests') . '
                WHERE (' . \implode(' OR ', $clauses) . ')
                    AND id <> %d
                ORDER BY id DESC',
                ...$params
            ),
            ARRAY_A
        );

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeAdminGuestFilters(array $filters): array
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $paged = isset($filters['paged']) ? (int) $filters['paged'] : 1;

        return [
            'guest_id' => isset($filters['guest_id']) ? \absint($filters['guest_id']) : 0,
            'search' => isset($filters['search']) ? \sanitize_text_field((string) $filters['search']) : '',
            'country' => isset($filters['country']) ? \sanitize_text_field((string) $filters['country']) : '',
            'stay_state' => isset($filters['stay_state']) ? \sanitize_key((string) $filters['stay_state']) : '',
            'attention' => isset($filters['attention']) ? \sanitize_key((string) $filters['attention']) : '',
            'flagged' => isset($filters['flagged']) ? \sanitize_key((string) $filters['flagged']) : '',
            'has_notes' => !empty($filters['has_notes']) ? 1 : 0,
            'today' => isset($filters['today']) ? \sanitize_text_field((string) $filters['today']) : \current_time('Y-m-d'),
            'per_page' => \max(1, \min(100, $perPage)),
            'paged' => \max(1, $paged),
            'offset' => (\max(1, $paged) - 1) * \max(1, \min(100, $perPage)),
        ];
    }

    private function buildReservationStatsJoin(string $reservationsTable, string $today): string
    {
        if (!$this->tableExists('reservations')) {
            return '';
        }

        return ' LEFT JOIN (
                SELECT
                    r.guest_id,
                    COUNT(*) AS total_reservations,
                    SUM(CASE WHEN r.status IN (\'confirmed\', \'completed\') AND r.checkout <= \'' . \esc_sql($today) . '\' THEN 1 ELSE 0 END) AS total_completed_stays,
                    SUM(CASE WHEN r.status = \'cancelled\' THEN 1 ELSE 0 END) AS total_cancellations,
                    MIN(r.created_at) AS first_reservation_at,
                    MAX(CASE WHEN r.status IN (\'confirmed\', \'completed\') AND r.checkout <= \'' . \esc_sql($today) . '\' THEN r.checkout ELSE NULL END) AS last_stay_date,
                    MIN(CASE WHEN r.checkin > \'' . \esc_sql($today) . '\' AND r.status IN (\'pending\', \'pending_payment\', \'confirmed\') THEN r.checkin ELSE NULL END) AS next_checkin,
                    MIN(CASE WHEN r.checkin > \'' . \esc_sql($today) . '\' AND r.status IN (\'pending\', \'pending_payment\', \'confirmed\') THEN r.checkout ELSE NULL END) AS next_checkout,
                    MIN(CASE WHEN r.checkin <= \'' . \esc_sql($today) . '\' AND r.checkout > \'' . \esc_sql($today) . '\' AND r.status IN (\'confirmed\', \'completed\') THEN r.checkin ELSE NULL END) AS in_house_checkin,
                    MIN(CASE WHEN r.checkin <= \'' . \esc_sql($today) . '\' AND r.checkout > \'' . \esc_sql($today) . '\' AND r.status IN (\'confirmed\', \'completed\') THEN r.checkout ELSE NULL END) AS in_house_checkout,
                    SUM(CASE WHEN r.checkin >= \'' . \esc_sql($today) . '\' AND r.status IN (\'pending\', \'pending_payment\', \'confirmed\') AND r.total_price > 0 AND r.payment_status IN (\'unpaid\', \'pending\', \'failed\', \'partially_paid\') THEN 1 ELSE 0 END) AS upcoming_unpaid_count,
                    SUM(CASE WHEN r.checkin >= \'' . \esc_sql($today) . '\' AND r.status IN (\'pending\', \'pending_payment\', \'confirmed\') AND r.payment_status = \'failed\' THEN 1 ELSE 0 END) AS upcoming_failed_count
                FROM ' . $reservationsTable . ' r
                GROUP BY r.guest_id
            ) stats ON stats.guest_id = g.id';
    }

    private function buildPaymentStatsJoin(string $reservationsTable, string $paymentsTable): string
    {
        return ' LEFT JOIN (
                SELECT
                    r.guest_id,
                    SUM(CASE WHEN p.status = \'paid\' THEN p.amount ELSE 0 END) AS total_paid,
                    SUM(CASE WHEN p.status = \'failed\' THEN 1 ELSE 0 END) AS failed_payment_count
                FROM ' . $reservationsTable . ' r
                LEFT JOIN ' . $paymentsTable . ' p ON p.reservation_id = r.id
                GROUP BY r.guest_id
            ) pay ON pay.guest_id = g.id';
    }

    private function buildDuplicateJoin(): string
    {
        return ' LEFT JOIN (
                SELECT LOWER(TRIM(email)) AS normalized_email, COUNT(*) AS email_duplicates
                FROM ' . $this->table('guests') . '
                WHERE TRIM(email) <> \'\'
                GROUP BY LOWER(TRIM(email))
            ) dup ON dup.normalized_email = LOWER(TRIM(g.email))';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{sql: string, params: array<int, mixed>}
     */
    private function buildAdminGuestWhereClause(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['guest_id'])) {
            $where[] = 'g.id = %d';
            $params[] = (int) $filters['guest_id'];
        }

        if ($filters['search'] !== '') {
            $like = '%' . $this->wpdb->esc_like((string) $filters['search']) . '%';
            $where[] = '(CONCAT_WS(\' \', g.first_name, g.last_name) LIKE %s OR g.email LIKE %s OR g.phone LIKE %s OR EXISTS (
                SELECT 1
                FROM ' . $this->table('reservations') . ' rs
                WHERE rs.guest_id = g.id
                    AND rs.booking_id LIKE %s
            ))';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['country'] !== '') {
            $where[] = 'g.country = %s';
            $params[] = (string) $filters['country'];
        }

        if ($filters['stay_state'] === 'upcoming') {
            $where[] = 'COALESCE(stats.next_checkin, \'\') <> \'\'';
        } elseif ($filters['stay_state'] === 'in_house') {
            $where[] = 'COALESCE(stats.in_house_checkin, \'\') <> \'\'';
        } elseif ($filters['stay_state'] === 'past_stays') {
            $where[] = 'COALESCE(stats.total_completed_stays, 0) > 0';
        } elseif ($filters['stay_state'] === 'no_completed_stays') {
            $where[] = 'COALESCE(stats.total_completed_stays, 0) = 0';
        }

        if ($filters['attention'] === 'unpaid_upcoming') {
            $where[] = 'COALESCE(stats.upcoming_unpaid_count, 0) > 0';
        } elseif ($filters['attention'] === 'failed_payment') {
            $where[] = '(COALESCE(stats.upcoming_failed_count, 0) > 0 OR COALESCE(pay.failed_payment_count, 0) > 0)';
        }

        if ($filters['flagged'] === 'vip') {
            $where[] = 'g.vip_flag = 1';
        } elseif ($filters['flagged'] === 'problem') {
            $where[] = 'g.problem_flag = 1';
        } elseif ($filters['flagged'] === 'flagged') {
            $where[] = '(g.vip_flag = 1 OR g.problem_flag = 1)';
        }

        if (!empty($filters['has_notes'])) {
            $where[] = 'TRIM(COALESCE(g.admin_notes, \'\')) <> \'\'';
        }

        return [
            'sql' => empty($where) ? '' : ' WHERE ' . \implode(' AND ', $where),
            'params' => $params,
        ];
    }
}
