<?php

namespace MustHotelBooking\Frontend;

if (!\function_exists(__NAMESPACE__ . '\get_room_amenities_intro_text')) {
    function get_room_amenities_intro_text(...$args)
    {
        return \MustHotelBooking\Admin\get_room_amenities_intro_text(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_room_amenity_display_items')) {
    function get_room_amenity_display_items(...$args)
    {
        return \MustHotelBooking\Admin\get_room_amenity_display_items(...$args);
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

if (!\function_exists(__NAMESPACE__ . '\get_room_record')) {
    function get_room_record(...$args)
    {
        return \MustHotelBooking\Admin\get_room_record(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_room_rules_text')) {
    function get_room_rules_text(...$args)
    {
        return \MustHotelBooking\Admin\get_room_rules_text(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_rooms_table_name')) {
    function get_rooms_table_name(...$args)
    {
        return \MustHotelBooking\Admin\get_rooms_table_name(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\is_reservation_confirmed_status')) {
    function is_reservation_confirmed_status(...$args)
    {
        return \MustHotelBooking\Core\is_reservation_confirmed_status(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\normalize_room_category')) {
    function normalize_room_category(...$args)
    {
        return \MustHotelBooking\Admin\normalize_room_category(...$args);
    }
}
