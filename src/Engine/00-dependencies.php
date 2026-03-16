<?php

namespace MustHotelBooking\Engine;

if (!\function_exists(__NAMESPACE__ . '\get_accommodation_empty_results_message')) {
    function get_accommodation_empty_results_message(...$args)
    {
        return \MustHotelBooking\Frontend\get_accommodation_empty_results_message(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_booking_confirmation_page_url')) {
    function get_booking_confirmation_page_url(...$args)
    {
        return \MustHotelBooking\Frontend\get_booking_confirmation_page_url(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_booking_results_room_view_data')) {
    function get_booking_results_room_view_data(...$args)
    {
        return \MustHotelBooking\Frontend\get_booking_results_room_view_data(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_enabled_payment_methods')) {
    function get_enabled_payment_methods(...$args)
    {
        return \MustHotelBooking\Admin\get_enabled_payment_methods(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')) {
    function get_inventory_non_blocking_reservation_statuses(...$args)
    {
        return \MustHotelBooking\Core\get_inventory_non_blocking_reservation_statuses(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_max_booking_guests_limit')) {
    function get_max_booking_guests_limit(...$args)
    {
        return \MustHotelBooking\Frontend\get_max_booking_guests_limit(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_payment_methods_catalog')) {
    function get_payment_methods_catalog(...$args)
    {
        return \MustHotelBooking\Admin\get_payment_methods_catalog(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
    function get_plugin_settings(...$args)
    {
        return \MustHotelBooking\Frontend\get_plugin_settings(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_room_record')) {
    function get_room_record(...$args)
    {
        return \MustHotelBooking\Admin\get_room_record(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\is_reservation_confirmed_status')) {
    function is_reservation_confirmed_status(...$args)
    {
        return \MustHotelBooking\Core\is_reservation_confirmed_status(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\normalize_booking_room_count')) {
    function normalize_booking_room_count(...$args)
    {
        return \MustHotelBooking\Frontend\normalize_booking_room_count(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\normalize_room_category')) {
    function normalize_room_category(...$args)
    {
        return \MustHotelBooking\Admin\normalize_room_category(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\resolve_booking_room_count')) {
    function resolve_booking_room_count(...$args)
    {
        return \MustHotelBooking\Frontend\resolve_booking_room_count(...$args);
    }
}
