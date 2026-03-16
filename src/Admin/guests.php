<?php

namespace MustHotelBooking\Admin;

/**
 * Get guests table name.
 */
function get_admin_guests_table_name(): string
{
    global $wpdb;

    if (\function_exists(__NAMESPACE__ . '\get_guests_table_name')) {
        return get_guests_table_name();
    }

    return $wpdb->prefix . 'must_guests';
}

/**
 * Get reservations table name for guests metrics.
 */
function get_admin_guests_reservations_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_reservations';
}

/**
 * Check if a table exists.
 */
function does_admin_guests_table_exist(string $table_name): bool
{
    global $wpdb;

    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    return \is_string($table_exists) && $table_exists !== '';
}

/**
 * Build full guest name from row values.
 *
 * @param array<string, mixed> $guest
 */
function get_admin_guest_full_name(array $guest): string
{
    $first_name = isset($guest['first_name']) ? \trim((string) $guest['first_name']) : '';
    $last_name = isset($guest['last_name']) ? \trim((string) $guest['last_name']) : '';
    $full_name = \trim($first_name . ' ' . $last_name);

    if ($full_name !== '') {
        return $full_name;
    }

    if (!empty($guest['email'])) {
        return (string) $guest['email'];
    }

    return \__('N/A', 'must-hotel-booking');
}

/**
 * Load guests with booking totals.
 *
 * @return array<int, array<string, mixed>>
 */
function get_admin_guest_rows(): array
{
    global $wpdb;

    $guests_table = get_admin_guests_table_name();

    if (!does_admin_guests_table_exist($guests_table)) {
        return [];
    }

    $reservations_table = get_admin_guests_reservations_table_name();
    $has_reservations_table = does_admin_guests_table_exist($reservations_table);

    if ($has_reservations_table) {
        $rows = $wpdb->get_results(
            "SELECT
                g.id,
                g.first_name,
                g.last_name,
                g.email,
                g.phone,
                g.country,
                COUNT(r.id) AS total_bookings
            FROM {$guests_table} g
            LEFT JOIN {$reservations_table} r
                ON r.guest_id = g.id
            GROUP BY g.id, g.first_name, g.last_name, g.email, g.phone, g.country
            ORDER BY g.id DESC",
            ARRAY_A
        );
    } else {
        $rows = $wpdb->get_results(
            "SELECT
                g.id,
                g.first_name,
                g.last_name,
                g.email,
                g.phone,
                g.country,
                0 AS total_bookings
            FROM {$guests_table} g
            ORDER BY g.id DESC",
            ARRAY_A
        );
    }

    return \is_array($rows) ? $rows : [];
}

/**
 * Render guests admin page.
 */
function render_admin_guests_page(): void
{
    ensure_admin_capability();

    $guests_table_name = get_admin_guests_table_name();
    $reservations_table_name = get_admin_guests_reservations_table_name();
    $guests_table_exists = does_admin_guests_table_exist($guests_table_name);
    $reservations_table_exists = does_admin_guests_table_exist($reservations_table_name);
    $guests = get_admin_guest_rows();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Guests', 'must-hotel-booking') . '</h1>';

    if (!$guests_table_exists) {
        echo '<div class="notice notice-warning"><p>' . \esc_html__('Guests table was not found. Please reactivate the plugin to create database tables.', 'must-hotel-booking') . '</p></div>';
    } elseif (!$reservations_table_exists) {
        echo '<div class="notice notice-warning"><p>' . \esc_html__('Reservations table was not found. Total bookings are shown as 0.', 'must-hotel-booking') . '</p></div>';
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Email', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Phone', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Country', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Total bookings', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($guests)) {
        echo '<tr><td colspan="5">' . \esc_html__('No guests found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($guests as $guest) {
            if (!\is_array($guest)) {
                continue;
            }

            $name = get_admin_guest_full_name($guest);
            $email = isset($guest['email']) ? (string) $guest['email'] : '';
            $phone = isset($guest['phone']) ? (string) $guest['phone'] : '';
            $country = isset($guest['country']) ? (string) $guest['country'] : '';
            $total_bookings = isset($guest['total_bookings']) ? (int) $guest['total_bookings'] : 0;

            echo '<tr>';
            echo '<td>' . \esc_html($name) . '</td>';
            echo '<td>' . \esc_html($email) . '</td>';
            echo '<td>' . \esc_html($phone !== '' ? $phone : '-') . '</td>';
            echo '<td>' . \esc_html($country !== '' ? $country : '-') . '</td>';
            echo '<td>' . \esc_html((string) $total_bookings) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}
