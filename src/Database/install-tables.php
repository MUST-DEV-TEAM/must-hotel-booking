<?php

namespace MustHotelBooking\Database;

/**
 * Explicitly reconcile derived inventory room types against legacy must_rooms.
 *
 * must_rooms remains the authoritative accommodation-type table in this plugin
 * version. mhb_room_types and mhb_rooms still depend on those legacy IDs, but
 * this sync helper should only run from deliberate maintenance or save actions,
 * never as a silent page-load or installer side effect.
 *
 * @return array<string, int>
 */
function seed_inventory_model_from_legacy_rooms(): array
{
    global $wpdb;

    $summary = [
        'legacy_types' => 0,
        'mirrored_types_inserted' => 0,
        'mirrored_types_updated' => 0,
        'inventory_units_created' => 0,
    ];

    $room_types_table = $wpdb->prefix . 'mhb_room_types';
    $inventory_rooms_table = $wpdb->prefix . 'mhb_rooms';
    $legacy_rooms_table = $wpdb->prefix . 'must_rooms';
    $room_types_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $room_types_table
        )
    );
    $inventory_rooms_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $inventory_rooms_table
        )
    );

    if (
        !\is_string($room_types_exists) ||
        $room_types_exists === '' ||
        !\is_string($inventory_rooms_exists) ||
        $inventory_rooms_exists === ''
    ) {
        return $summary;
    }

    $legacy_rooms = $wpdb->get_results(
        'SELECT id, name, description, max_guests, base_price
        FROM ' . $legacy_rooms_table . '
        ORDER BY id ASC',
        ARRAY_A
    );

    if (!\is_array($legacy_rooms)) {
        return $summary;
    }

    foreach ($legacy_rooms as $legacy_room) {
        if (!\is_array($legacy_room)) {
            continue;
        }

        $room_type_id = isset($legacy_room['id']) ? (int) $legacy_room['id'] : 0;

        if ($room_type_id <= 0) {
            continue;
        }

        $summary['legacy_types']++;

        $room_type_data = [
            'name' => isset($legacy_room['name']) ? \sanitize_text_field((string) $legacy_room['name']) : '',
            'description' => isset($legacy_room['description']) ? \sanitize_textarea_field((string) $legacy_room['description']) : '',
            'capacity' => isset($legacy_room['max_guests']) ? \max(1, (int) $legacy_room['max_guests']) : 1,
            'base_price' => isset($legacy_room['base_price']) ? \round((float) $legacy_room['base_price'], 2) : 0.0,
        ];
        $room_type_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id
                FROM ' . $room_types_table . '
                WHERE id = %d
                LIMIT 1',
                $room_type_id
            )
        );

        if ($room_type_exists > 0) {
            $updated = $wpdb->update(
                $room_types_table,
                $room_type_data,
                ['id' => $room_type_id],
                ['%s', '%s', '%d', '%f'],
                ['%d']
            );

            if ($updated !== false) {
                $summary['mirrored_types_updated']++;
            }
        } else {
            $inserted = $wpdb->insert(
                $room_types_table,
                ['id' => $room_type_id] + $room_type_data,
                ['%d', '%s', '%s', '%d', '%f']
            );

            if ($inserted !== false) {
                $summary['mirrored_types_inserted']++;
            }
        }

        $inventory_room_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*)
                FROM ' . $inventory_rooms_table . '
                WHERE room_type_id = %d',
                $room_type_id
            )
        );

        if ($inventory_room_count > 0) {
            continue;
        }

        $insertedUnit = $wpdb->insert(
            $inventory_rooms_table,
            [
                'room_type_id' => $room_type_id,
                'title' => $room_type_data['name'],
                'room_number' => 'RT-' . $room_type_id . '-1',
                'floor' => 0,
                'status' => 'available',
            ],
            ['%d', '%s', '%s', '%d', '%s']
        );

        if ($insertedUnit !== false) {
            $summary['inventory_units_created']++;
        }
    }

    return $summary;
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
        internal_code VARCHAR(100) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_bookable TINYINT(1) NOT NULL DEFAULT 1,
        is_online_bookable TINYINT(1) NOT NULL DEFAULT 1,
        is_calendar_visible TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT(11) NOT NULL DEFAULT 0,
        max_adults SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        max_children SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
        max_guests SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        default_occupancy SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        extra_guest_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        room_size VARCHAR(100) NOT NULL DEFAULT '',
        beds VARCHAR(100) NOT NULL DEFAULT '',
        admin_notes LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY slug (slug),
        KEY category (category),
        KEY max_guests (max_guests),
        KEY is_active (is_active),
        KEY is_bookable (is_bookable),
        KEY sort_order (sort_order)
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

    $tables[] = "CREATE TABLE {$prefix}mhb_room_types (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL DEFAULT '',
        description LONGTEXT NULL,
        capacity SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY  (id),
        KEY capacity (capacity)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_rooms (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        room_type_id BIGINT(20) UNSIGNED NOT NULL,
        title VARCHAR(191) NOT NULL DEFAULT '',
        room_number VARCHAR(100) NOT NULL DEFAULT '',
        floor INT(11) NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'available',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_bookable TINYINT(1) NOT NULL DEFAULT 1,
        is_calendar_visible TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT(11) NOT NULL DEFAULT 0,
        capacity_override SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
        building VARCHAR(100) NOT NULL DEFAULT '',
        section VARCHAR(100) NOT NULL DEFAULT '',
        admin_notes LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY room_type_id (room_type_id),
        KEY status (status),
        KEY is_active (is_active),
        KEY is_bookable (is_bookable),
        KEY sort_order (sort_order),
        UNIQUE KEY room_number (room_number)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_guests (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name VARCHAR(100) NOT NULL DEFAULT '',
        last_name VARCHAR(100) NOT NULL DEFAULT '',
        email VARCHAR(190) NOT NULL DEFAULT '',
        phone VARCHAR(50) NOT NULL DEFAULT '',
        country VARCHAR(100) NOT NULL DEFAULT '',
        admin_notes LONGTEXT NULL,
        vip_flag TINYINT(1) NOT NULL DEFAULT 0,
        problem_flag TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY email (email),
        KEY vip_flag (vip_flag),
        KEY problem_flag (problem_flag)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_reservations (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id VARCHAR(50) NULL DEFAULT NULL,
        room_id BIGINT(20) UNSIGNED NOT NULL,
        room_type_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        assigned_room_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        rate_plan_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        guest_id BIGINT(20) UNSIGNED NOT NULL,
        checkin DATE NOT NULL,
        checkout DATE NOT NULL,
        guests SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        booking_source VARCHAR(50) NOT NULL DEFAULT 'website',
        notes LONGTEXT NULL,
        total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        coupon_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        coupon_code VARCHAR(100) NOT NULL DEFAULT '',
        coupon_discount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_status VARCHAR(50) NOT NULL DEFAULT 'unpaid',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY room_id (room_id),
        KEY room_type_id (room_type_id),
        KEY assigned_room_id (assigned_room_id),
        KEY rate_plan_id (rate_plan_id),
        KEY room_stay (room_id, checkin, checkout),
        UNIQUE KEY booking_id (booking_id),
        KEY guest_id (guest_id),
        KEY stay_dates (checkin, checkout),
        KEY status (status),
        KEY booking_source (booking_source),
        KEY payment_status (payment_status),
        KEY coupon_id (coupon_id),
        KEY coupon_code (coupon_code)
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
        priority INT(11) NOT NULL DEFAULT 10,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY room_id (room_id),
        KEY date_range (start_date, end_date),
        KEY minimum_nights (minimum_nights),
        KEY is_active (is_active),
        KEY room_status_dates (room_id, is_active, start_date, end_date, priority)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_availability (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        room_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        name VARCHAR(191) NOT NULL DEFAULT '',
        availability_date DATE NULL DEFAULT NULL,
        end_date DATE NULL DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_available TINYINT(1) NOT NULL DEFAULT 1,
        reason VARCHAR(191) NOT NULL DEFAULT '',
        rule_type VARCHAR(50) NOT NULL DEFAULT '',
        rule_value VARCHAR(191) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY room_id (room_id),
        KEY is_active (is_active),
        KEY rule_type (rule_type),
        KEY availability_date (availability_date),
        KEY end_date (end_date),
        KEY room_rule (room_id, rule_type),
        KEY room_day (room_id, availability_date),
        KEY room_status_range (room_id, is_active, availability_date, end_date, rule_type)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_inventory_locks (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        room_id BIGINT(20) UNSIGNED NOT NULL,
        checkin DATE NOT NULL,
        checkout DATE NOT NULL,
        session_id VARCHAR(191) NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY room_stay_session (room_id, checkin, checkout, session_id),
        KEY room_stay_expires (room_id, checkin, checkout, expires_at),
        KEY session_id (session_id),
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
        name VARCHAR(191) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        discount_type VARCHAR(20) NOT NULL DEFAULT 'percentage',
        discount_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        minimum_booking_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        valid_from DATE NOT NULL,
        valid_until DATE NOT NULL,
        usage_limit INT(10) UNSIGNED NOT NULL DEFAULT 0,
        usage_count INT(10) UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY code (code),
        KEY valid_range (valid_from, valid_until),
        KEY usage_limit (usage_limit),
        KEY is_active (is_active)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}must_activity_log (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(60) NOT NULL DEFAULT '',
        severity VARCHAR(20) NOT NULL DEFAULT 'info',
        entity_type VARCHAR(60) NOT NULL DEFAULT '',
        entity_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        reference VARCHAR(191) NOT NULL DEFAULT '',
        message VARCHAR(255) NOT NULL DEFAULT '',
        context_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY event_type (event_type),
        KEY severity (severity),
        KEY entity_lookup (entity_type, entity_id),
        KEY created_at (created_at)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_cancellation_policies (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL DEFAULT '',
        hours_before_checkin INT(11) NOT NULL DEFAULT 0,
        penalty_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        description LONGTEXT NULL,
        PRIMARY KEY  (id),
        KEY hours_before_checkin (hours_before_checkin)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_rate_plans (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL DEFAULT '',
        description LONGTEXT NULL,
        cancellation_policy_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY is_active (is_active)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_room_type_rate_plans (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        room_type_id BIGINT(20) UNSIGNED NOT NULL,
        rate_plan_id BIGINT(20) UNSIGNED NOT NULL,
        base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        max_occupancy SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY room_type_rate_plan (room_type_id, rate_plan_id),
        KEY room_type_id (room_type_id),
        KEY rate_plan_id (rate_plan_id)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_rate_plan_prices (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        rate_plan_id BIGINT(20) UNSIGNED NOT NULL,
        `date` DATE NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY  (id),
        UNIQUE KEY rate_plan_day (rate_plan_id, `date`),
        KEY date_key (`date`)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_seasons (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(191) NOT NULL DEFAULT '',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        priority INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY date_window (start_date, end_date),
        KEY priority (priority)
    ) {$charset_collate};";

    $tables[] = "CREATE TABLE {$prefix}mhb_seasonal_prices (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        season_id BIGINT(20) UNSIGNED NOT NULL,
        rate_plan_id BIGINT(20) UNSIGNED NOT NULL,
        modifier_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
        modifier_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY  (id),
        UNIQUE KEY season_rate_plan (season_id, rate_plan_id),
        KEY season_id (season_id),
        KEY rate_plan_id (rate_plan_id)
    ) {$charset_collate};";

    foreach ($tables as $sql) {
        \dbDelta($sql);
    }

    \update_option('must_hotel_booking_db_version', MUST_HOTEL_BOOKING_VERSION);
}
