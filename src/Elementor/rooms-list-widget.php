<?php

namespace MustHotelBooking\Elementor;

/**
 * Register Elementor rooms list widget styles.
 */
function register_elementor_rooms_list_widget_styles(): void
{
    \wp_register_style(
        'must-hotel-booking-rooms-list-widget',
        MUST_HOTEL_BOOKING_URL . 'assets/css/rooms-list-widget.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );
}

/**
 * Register Elementor rooms list widget scripts.
 */
function register_elementor_rooms_list_widget_scripts(): void
{
    \wp_register_script(
        'must-hotel-booking-rooms-list-widget',
        MUST_HOTEL_BOOKING_URL . 'assets/js/rooms-list-widget.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_localize_script(
        'must-hotel-booking-rooms-list-widget',
        'mustBookingRoomsListWidgetConfig',
        [
            'icons' => [
                'lightboxPrev' => MUST_HOTEL_BOOKING_URL . 'assets/img/lightboxleft.svg',
                'lightboxNext' => MUST_HOTEL_BOOKING_URL . 'assets/img/lightboxright.svg',
            ],
        ]
    );
}

/**
 * Get Rooms page URL used for "Additional Details" links.
 */
function get_rooms_widget_rooms_page_url(): string
{
    if (\function_exists(__NAMESPACE__ . '\\get_frontend_page_url')) {
        return get_frontend_page_url('page_rooms_id', '/rooms');
    }

    return \home_url('/rooms');
}

/**
 * Get Booking page URL used for "Book Now" links.
 */
function get_rooms_widget_booking_page_url(): string
{
    if (\function_exists(__NAMESPACE__ . '\\get_booking_page_url')) {
        return get_booking_page_url();
    }

    return \home_url('/booking');
}

/**
 * Fetch room records for widget rendering.
 *
 * @return array<int, array<string, mixed>>
 */
function get_rooms_for_widget_render(string $category, int $limit): array
{
    if (\function_exists(__NAMESPACE__ . '\\get_rooms_for_display')) {
        return get_rooms_for_display($category, $limit);
    }

    global $wpdb;

    $limit = \max(1, \min(200, $limit));
    $rooms_table = $wpdb->prefix . 'must_rooms';
    $category_slug = \sanitize_key($category);

    if ($category_slug !== '' && $category_slug !== 'all') {
        $sql = $wpdb->prepare(
            "SELECT id, name, slug, category, description, max_guests, base_price, room_size, beds
            FROM {$rooms_table}
            WHERE category = %s
            ORDER BY created_at DESC, id DESC
            LIMIT %d",
            $category_slug,
            $limit
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT id, name, slug, category, description, max_guests, base_price, room_size, beds
            FROM {$rooms_table}
            ORDER BY created_at DESC, id DESC
            LIMIT %d",
            $limit
        );
    }

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
}

/**
 * Resolve room gallery image URLs for widget cards.
 *
 * @return array<int, string>
 */
function get_room_gallery_urls_for_widget(int $room_id, int $limit = 3): array
{
    if (\function_exists(__NAMESPACE__ . '\\get_room_gallery_image_urls')) {
        return get_room_gallery_image_urls($room_id, $limit, 'medium_large');
    }

    return [];
}

/**
 * Resolve room main image URL for widget cards.
 */
function get_room_main_image_url_for_widget(int $room_id): string
{
    if (\function_exists(__NAMESPACE__ . '\\get_room_main_image_url')) {
        return get_room_main_image_url($room_id, 'large');
    }

    return '';
}

/**
 * Register Elementor rooms list widget.
 *
 * @param mixed $widgets_manager Elementor widgets manager instance.
 */
function register_elementor_rooms_list_widget($widgets_manager): void
{
    static $is_registered = false;

    if ($is_registered) {
        return;
    }

    if (!\class_exists('\\Elementor\\Widget_Base') || !\is_object($widgets_manager)) {
        return;
    }

    if (!\class_exists(__NAMESPACE__ . '\\Rooms_List_Widget')) {
        return;
    }

    $widget_instance = new Rooms_List_Widget();

    if (\method_exists($widgets_manager, 'register')) {
        $widgets_manager->register($widget_instance);
        $is_registered = true;
        return;
    }

    if (\method_exists($widgets_manager, 'register_widget_type')) {
        $widgets_manager->register_widget_type($widget_instance);
        $is_registered = true;
    }
}

/**
 * Register Elementor rooms list widget for legacy Elementor hooks.
 */
function register_elementor_rooms_list_widget_legacy(): void
{
    if (!\class_exists('\\Elementor\\Plugin')) {
        return;
    }

    $plugin_instance = \Elementor\Plugin::$instance ?? null;
    $widgets_manager = (\is_object($plugin_instance) && isset($plugin_instance->widgets_manager))
        ? $plugin_instance->widgets_manager
        : null;

    if (!\is_object($widgets_manager)) {
        return;
    }

    register_elementor_rooms_list_widget($widgets_manager);
}

\add_action('elementor/frontend/after_register_styles', __NAMESPACE__ . '\\register_elementor_rooms_list_widget_styles');
\add_action('elementor/frontend/after_register_scripts', __NAMESPACE__ . '\\register_elementor_rooms_list_widget_scripts');
\add_action('elementor/widgets/register', __NAMESPACE__ . '\\register_elementor_rooms_list_widget');
\add_action('elementor/widgets/widgets_registered', __NAMESPACE__ . '\\register_elementor_rooms_list_widget_legacy');
