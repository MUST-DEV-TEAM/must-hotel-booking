<?php

namespace MustHotelBooking\Frontend;

if (!\function_exists(__NAMESPACE__ . '\are_reusable_pending_payment_reservations')) {
    function are_reusable_pending_payment_reservations(...$args)
    {
        return \MustHotelBooking\Engine\are_reusable_pending_payment_reservations(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\calculate_booking_price')) {
    function calculate_booking_price(...$args)
    {
        return \MustHotelBooking\Engine\calculate_booking_price(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\check_room_availability')) {
    function check_room_availability(...$args)
    {
        return \MustHotelBooking\Engine\check_room_availability(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\create_or_update_payment_rows')) {
    function create_or_update_payment_rows(...$args)
    {
        return \MustHotelBooking\Engine\create_or_update_payment_rows(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\create_stripe_checkout_session')) {
    function create_stripe_checkout_session(...$args)
    {
        return \MustHotelBooking\Engine\create_stripe_checkout_session(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\create_temporary_reservation_lock')) {
    function create_temporary_reservation_lock(...$args)
    {
        return \MustHotelBooking\Engine\create_temporary_reservation_lock(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\fail_pending_stripe_reservations')) {
    function fail_pending_stripe_reservations(...$args)
    {
        return \MustHotelBooking\Engine\fail_pending_stripe_reservations(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_available_rooms')) {
    function get_available_rooms(...$args)
    {
        return \MustHotelBooking\Engine\get_available_rooms(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_checkout_payment_cta_label')) {
    function get_checkout_payment_cta_label(...$args)
    {
        return \MustHotelBooking\Engine\get_checkout_payment_cta_label(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_checkout_payment_methods')) {
    function get_checkout_payment_methods(...$args)
    {
        return \MustHotelBooking\Engine\get_checkout_payment_methods(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_coupon_rule_from_table')) {
    function get_coupon_rule_from_table(...$args)
    {
        return \MustHotelBooking\Engine\get_coupon_rule_from_table(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_empty_pending_payment_flow_data')) {
    function get_empty_pending_payment_flow_data(...$args)
    {
        return \MustHotelBooking\Engine\get_empty_pending_payment_flow_data(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_or_create_lock_session_id')) {
    function get_or_create_lock_session_id(...$args)
    {
        return \MustHotelBooking\Engine\get_or_create_lock_session_id(...$args);
    }
}

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

if (!\function_exists(__NAMESPACE__ . '\get_selected_checkout_payment_method')) {
    function get_selected_checkout_payment_method(...$args)
    {
        return \MustHotelBooking\Engine\get_selected_checkout_payment_method(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\has_active_exact_room_lock')) {
    function has_active_exact_room_lock(...$args)
    {
        return \MustHotelBooking\Engine\has_active_exact_room_lock(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\increment_coupon_usage_count')) {
    function increment_coupon_usage_count(...$args)
    {
        return \MustHotelBooking\Engine\increment_coupon_usage_count(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\is_reservation_confirmed_status')) {
    function is_reservation_confirmed_status(...$args)
    {
        return \MustHotelBooking\Core\is_reservation_confirmed_status(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\is_stripe_checkout_url')) {
    function is_stripe_checkout_url(...$args)
    {
        return \MustHotelBooking\Engine\is_stripe_checkout_url(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\is_valid_booking_date')) {
    function is_valid_booking_date(...$args)
    {
        return \MustHotelBooking\Engine\is_valid_booking_date(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\maybe_sync_stripe_return_session')) {
    function maybe_sync_stripe_return_session(...$args)
    {
        return \MustHotelBooking\Engine\maybe_sync_stripe_return_session(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\normalize_pending_payment_flow_data')) {
    function normalize_pending_payment_flow_data(...$args)
    {
        return \MustHotelBooking\Engine\normalize_pending_payment_flow_data(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\normalize_room_category')) {
    function normalize_room_category(...$args)
    {
        return \MustHotelBooking\Admin\normalize_room_category(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\release_room_lock')) {
    function release_room_lock(...$args)
    {
        return \MustHotelBooking\Engine\release_room_lock(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\store_reservation_from_lock')) {
    function store_reservation_from_lock(...$args)
    {
        return \MustHotelBooking\Engine\store_reservation_from_lock(...$args);
    }
}
