<?php

namespace MustHotelBooking\Elementor;

if (!\function_exists(__NAMESPACE__ . '\get_booking_page_url')) {
    function get_booking_page_url(...$args)
    {
        return \MustHotelBooking\Frontend\get_booking_page_url(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_frontend_page_url')) {
    function get_frontend_page_url(...$args)
    {
        return \MustHotelBooking\Frontend\get_frontend_page_url(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
    function get_plugin_settings(...$args)
    {
        return \MustHotelBooking\Frontend\get_plugin_settings(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_room_categories')) {
    function get_room_categories(...$args)
    {
        return \MustHotelBooking\Admin\get_room_categories(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_room_category_label')) {
    function get_room_category_label(...$args)
    {
        return \MustHotelBooking\Admin\get_room_category_label(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_room_gallery_image_urls')) {
    function get_room_gallery_image_urls(...$args)
    {
        return \MustHotelBooking\Admin\get_room_gallery_image_urls(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_room_main_image_url')) {
    function get_room_main_image_url(...$args)
    {
        return \MustHotelBooking\Admin\get_room_main_image_url(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_rooms_for_display')) {
    function get_rooms_for_display(...$args)
    {
        return \MustHotelBooking\Admin\get_rooms_for_display(...$args);
    }
}
