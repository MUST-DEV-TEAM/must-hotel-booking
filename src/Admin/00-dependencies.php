<?php

namespace MustHotelBooking\Admin;

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

if (!\function_exists(__NAMESPACE__ . '\create_reservation_without_lock')) {
    function create_reservation_without_lock(...$args)
    {
        return \MustHotelBooking\Engine\create_reservation_without_lock(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_active_site_environment')) {
    function get_active_site_environment(...$args)
    {
        return \MustHotelBooking\Engine\get_active_site_environment(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_coupons_table_name')) {
    function get_coupons_table_name(...$args)
    {
        return \MustHotelBooking\Engine\get_coupons_table_name(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_default_email_templates')) {
    function get_default_email_templates(...$args)
    {
        return \MustHotelBooking\Engine\get_default_email_templates(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_design_system_elementor_status')) {
    function get_design_system_elementor_status(...$args)
    {
        return \MustHotelBooking\Core\get_design_system_elementor_status(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_design_system_plugin_defaults')) {
    function get_design_system_plugin_defaults(...$args)
    {
        return \MustHotelBooking\Core\get_design_system_plugin_defaults(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_design_system_plugin_settings')) {
    function get_design_system_plugin_settings(...$args)
    {
        return \MustHotelBooking\Core\get_design_system_plugin_settings(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_email_template_labels')) {
    function get_email_template_labels(...$args)
    {
        return \MustHotelBooking\Engine\get_email_template_labels(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_email_template_placeholders')) {
    function get_email_template_placeholders(...$args)
    {
        return \MustHotelBooking\Engine\get_email_template_placeholders(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_email_templates')) {
    function get_email_templates(...$args)
    {
        return \MustHotelBooking\Engine\get_email_templates(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_email_templates_setting_key')) {
    function get_email_templates_setting_key(...$args)
    {
        return \MustHotelBooking\Engine\get_email_templates_setting_key(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_frontend_pages_config')) {
    function get_frontend_pages_config(...$args)
    {
        return \MustHotelBooking\Frontend\get_frontend_pages_config(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_inventory_non_blocking_reservation_statuses')) {
    function get_inventory_non_blocking_reservation_statuses(...$args)
    {
        return \MustHotelBooking\Core\get_inventory_non_blocking_reservation_statuses(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_lock_cleanup_cron_hook')) {
    function get_lock_cleanup_cron_hook(...$args)
    {
        return \MustHotelBooking\Engine\get_lock_cleanup_cron_hook(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
    function get_plugin_settings(...$args)
    {
        return \MustHotelBooking\Frontend\get_plugin_settings(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_pricing_table_name')) {
    function get_pricing_table_name(...$args)
    {
        return \MustHotelBooking\Engine\get_pricing_table_name(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_site_environment_label')) {
    function get_site_environment_label(...$args)
    {
        return \MustHotelBooking\Engine\get_site_environment_label(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_stripe_environment_catalog')) {
    function get_stripe_environment_catalog(...$args)
    {
        return \MustHotelBooking\Engine\get_stripe_environment_catalog(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_stripe_environment_credentials')) {
    function get_stripe_environment_credentials(...$args)
    {
        return \MustHotelBooking\Engine\get_stripe_environment_credentials(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_stripe_environment_setting_keys')) {
    function get_stripe_environment_setting_keys(...$args)
    {
        return \MustHotelBooking\Engine\get_stripe_environment_setting_keys(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_stripe_webhook_secret')) {
    function get_stripe_webhook_secret(...$args)
    {
        return \MustHotelBooking\Engine\get_stripe_webhook_secret(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_stripe_webhook_url')) {
    function get_stripe_webhook_url(...$args)
    {
        return \MustHotelBooking\Engine\get_stripe_webhook_url(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\get_taxes_table_name')) {
    function get_taxes_table_name(...$args)
    {
        return \MustHotelBooking\Engine\get_taxes_table_name(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\install_frontend_pages')) {
    function install_frontend_pages(...$args)
    {
        return \MustHotelBooking\Frontend\install_frontend_pages(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\is_stripe_checkout_configured')) {
    function is_stripe_checkout_configured(...$args)
    {
        return \MustHotelBooking\Engine\is_stripe_checkout_configured(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\is_valid_booking_date')) {
    function is_valid_booking_date(...$args)
    {
        return \MustHotelBooking\Engine\is_valid_booking_date(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\normalize_stripe_environment')) {
    function normalize_stripe_environment(...$args)
    {
        return \MustHotelBooking\Engine\normalize_stripe_environment(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\sanitize_design_settings_form_values')) {
    function sanitize_design_settings_form_values(...$args)
    {
        return \MustHotelBooking\Core\sanitize_design_settings_form_values(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\update_plugin_settings')) {
    function update_plugin_settings(...$args)
    {
        return \MustHotelBooking\Frontend\update_plugin_settings(...$args);
    }
}
