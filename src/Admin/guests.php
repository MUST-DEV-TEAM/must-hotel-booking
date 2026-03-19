<?php

namespace MustHotelBooking\Admin;

/**
 * Build Guests admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_guests_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-guests');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
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
    return \MustHotelBooking\Engine\get_guest_repository()->getAdminGuestRows();
}

/**
 * Render guests admin page.
 */
function render_admin_guests_page(): void
{
    ensure_admin_capability();

    $guestRepository = \MustHotelBooking\Engine\get_guest_repository();
    $reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
    $guests_table_exists = $guestRepository->guestsTableExists();
    $reservations_table_exists = $reservationRepository->reservationsTableExists();
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
