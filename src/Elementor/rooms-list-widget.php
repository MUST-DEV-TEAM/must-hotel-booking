<?php

namespace MustHotelBooking\Elementor;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\RoomData;

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
    return ManagedPages::getRoomsPageUrl();
}

/**
 * Get Booking page URL used for "Book Now" links.
 */
function get_rooms_widget_booking_page_url(): string
{
    return ManagedPages::getBookingPageUrl();
}

/**
 * Fetch room records for widget rendering.
 *
 * @return array<int, array<string, mixed>>
 */
function get_rooms_for_widget_render(string $category, int $limit): array
{
    return RoomData::getRoomsForDisplay($category, $limit);
}

/**
 * Resolve room gallery image URLs for widget cards.
 *
 * @return array<int, string>
 */
function get_room_gallery_urls_for_widget(int $room_id, int $limit = 3): array
{
    return RoomData::getRoomGalleryImageUrls($room_id, $limit, 'medium_large');
}

/**
 * Resolve room main image URL for widget cards.
 */
function get_room_main_image_url_for_widget(int $room_id): string
{
    return RoomData::getRoomMainImageUrl($room_id, 'large');
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
