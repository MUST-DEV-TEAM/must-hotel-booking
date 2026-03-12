<?php

namespace must_hotel_booking;

/**
 * Get default room description from provided room design references.
 */
function get_default_seed_room_description(): string
{
    return 'At 25 m2, this Standard Room with Mountain View combines comfort with tranquil scenery, showcasing the surrounding mountains while placing you steps away from the sea.';
}

/**
 * Get the built-in room seed catalog.
 *
 * @return array<int, array<string, string>>
 */
function get_seed_room_catalog(): array
{
    $description = get_default_seed_room_description();

    return [
        [
            'name' => 'Duplex Suite',
            'slug' => 'duplex-suite',
            'category' => 'duplex-suite',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Junior Suite',
            'slug' => 'junior-suite',
            'category' => 'suites',
            'room_size' => '45 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Deluxe Suite',
            'slug' => 'deluxe-suite',
            'category' => 'suites',
            'room_size' => '45 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Deluxe Suite with Jacuzzi',
            'slug' => 'deluxe-suite-with-jacuzzi',
            'category' => 'suites',
            'room_size' => '45 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Executive Suite',
            'slug' => 'executive-suite',
            'category' => 'suites',
            'room_size' => '45 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Side Sea View',
            'slug' => 'standard-double-room-with-side-sea-view-1',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Pool Sea View & Balcony',
            'slug' => 'standard-double-room-with-pool-sea-view-balcony-1',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Mountain View',
            'slug' => 'standard-double-room-with-mountain-view-1',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Mountain View',
            'slug' => 'standard-double-room-with-mountain-view-2',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Garden Sea View & Balcony',
            'slug' => 'standard-double-room-with-garden-sea-view-balcony',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Garden Sea View',
            'slug' => 'standard-double-room-with-garden-sea-view',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Pool Sea View & Balcony',
            'slug' => 'standard-double-room-with-pool-sea-view-balcony-2',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Pool View',
            'slug' => 'standard-double-room-with-pool-view',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Side Sea View',
            'slug' => 'standard-double-room-with-side-sea-view-2',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Mountain View',
            'slug' => 'standard-double-room-with-mountain-view-3',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room With Garden & Sea View',
            'slug' => 'standard-double-room-with-garden-and-sea-view',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Twin Room with Mountain View',
            'slug' => 'twin-room-with-mountain-view',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '2 Twin Beds',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Direct Pool Access & Balcony',
            'slug' => 'standard-double-room-with-direct-pool-access-balcony',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
        [
            'name' => 'Standard Double Room with Direct Pool Acces',
            'slug' => 'standard-double-room-with-direct-pool-acces',
            'category' => 'standard-rooms',
            'room_size' => '25 m2',
            'beds' => '1 Double Bed',
            'description' => $description,
        ],
    ];
}

/**
 * Seed default categories/rooms if they are not present yet.
 */
function seed_default_room_catalog(): void
{
    global $wpdb;

    $rooms_table = $wpdb->prefix . 'must_rooms';
    $room_rows = get_seed_room_catalog();

    foreach ($room_rows as $room) {
        $name = isset($room['name']) ? \sanitize_text_field((string) $room['name']) : '';
        $raw_slug = isset($room['slug']) ? (string) $room['slug'] : $name;
        $slug = \sanitize_title($raw_slug);

        if ($name === '' || $slug === '') {
            continue;
        }

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$rooms_table} WHERE slug = %s LIMIT 1",
                $slug
            )
        );

        if ($existing_id > 0) {
            continue;
        }

        $wpdb->insert(
            $rooms_table,
            [
                'name' => $name,
                'slug' => $slug,
                'category' => isset($room['category']) ? \sanitize_key((string) $room['category']) : 'standard-rooms',
                'description' => isset($room['description']) ? \sanitize_textarea_field((string) $room['description']) : '',
                'max_guests' => 2,
                'base_price' => 0.00,
                'extra_guest_price' => 0.00,
                'room_size' => isset($room['room_size']) ? \sanitize_text_field((string) $room['room_size']) : '',
                'beds' => isset($room['beds']) ? \sanitize_text_field((string) $room['beds']) : '',
                'created_at' => \current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%f', '%f', '%s', '%s', '%s']
        );
    }
}

/**
 * Create or update plugin database tables.
 */
function install_tables(): void
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix = $wpdb->prefix;

    $tables = [];

    $tables[] = "CREATE TABLE {$prefix}must_rooms (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL,
        slug VARCHAR(191) NOT NULL,
        category VARCHAR(100) NOT NULL DEFAULT 'standard-rooms',
        description LONGTEXT NULL,
        max_guests SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        extra_guest_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        room_size VARCHAR(100) NOT NULL DEFAULT '',
        beds VARCHAR(100) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY slug (slug),
        KEY category (category),
        KEY max_guests (max_guests)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_room_meta (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        room_id BIGINT(20) UNSIGNED NOT NULL,
        meta_key VARCHAR(191) NOT NULL,
        meta_value LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY room_id (room_id),
        KEY meta_key (meta_key)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_guests (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name VARCHAR(100) NOT NULL DEFAULT '',
        last_name VARCHAR(100) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(50) NOT NULL DEFAULT '',
        country VARCHAR(100) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY email (email)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_reservations (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id VARCHAR(50) NULL DEFAULT NULL,
        room_id BIGINT(20) UNSIGNED NOT NULL,
        guest_id BIGINT(20) UNSIGNED NOT NULL,
        checkin DATE NOT NULL,
        checkout DATE NOT NULL,
        guests SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        booking_source VARCHAR(50) NOT NULL DEFAULT 'website',
        notes LONGTEXT NULL,
        total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_status VARCHAR(50) NOT NULL DEFAULT 'unpaid',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY room_id (room_id),
        KEY room_stay (room_id, checkin, checkout),
        UNIQUE KEY booking_id (booking_id),
        KEY guest_id (guest_id),
        KEY stay_dates (checkin, checkout),
        KEY status (status),
        KEY booking_source (booking_source),
        KEY payment_status (payment_status)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_pricing (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        room_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(191) NOT NULL DEFAULT '',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        price_override DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        weekend_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        minimum_nights SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY room_id (room_id),
        KEY date_range (start_date, end_date),
        KEY minimum_nights (minimum_nights)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_availability (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        room_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        availability_date DATE NULL DEFAULT NULL,
        end_date DATE NULL DEFAULT NULL,
        is_available TINYINT(1) NOT NULL DEFAULT 1,
        reason VARCHAR(191) NOT NULL DEFAULT '',
        rule_type VARCHAR(50) NOT NULL DEFAULT '',
        rule_value VARCHAR(191) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY room_id (room_id),
        KEY rule_type (rule_type),
        KEY availability_date (availability_date),
        KEY end_date (end_date),
        KEY room_rule (room_id, rule_type),
        KEY room_day (room_id, availability_date)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_locks (
        room_id BIGINT(20) UNSIGNED NOT NULL,
        checkin DATE NOT NULL,
        checkout DATE NOT NULL,
        session_id VARCHAR(191) NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY  (room_id, checkin, checkout, session_id),
        KEY room_stay_expires (room_id, checkin, checkout, expires_at),
        KEY expires_at (expires_at)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_payments (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        reservation_id BIGINT(20) UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(10) NOT NULL DEFAULT 'USD',
        method VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        transaction_id VARCHAR(191) NOT NULL DEFAULT '',
        paid_at DATETIME NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY reservation_id (reservation_id),
        KEY status (status),
        KEY transaction_id (transaction_id)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_taxes (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL DEFAULT '',
        rule_type VARCHAR(20) NOT NULL DEFAULT 'percentage',
        rule_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        apply_mode VARCHAR(20) NOT NULL DEFAULT 'stay',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY rule_type (rule_type),
        KEY apply_mode (apply_mode)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_coupons (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(100) NOT NULL DEFAULT '',
        discount_type VARCHAR(20) NOT NULL DEFAULT 'percentage',
        discount_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        valid_from DATE NOT NULL,
        valid_until DATE NOT NULL,
        usage_limit INT(10) UNSIGNED NOT NULL DEFAULT 0,
        usage_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY code (code),
        KEY valid_range (valid_from, valid_until),
        KEY usage_limit (usage_limit)
    ) {$charset_collate};";

    foreach ($tables as $sql) {
        \dbDelta($sql);
    }

    seed_default_room_catalog();

    \update_option('must_hotel_booking_db_version', MUST_HOTEL_BOOKING_VERSION);
}
