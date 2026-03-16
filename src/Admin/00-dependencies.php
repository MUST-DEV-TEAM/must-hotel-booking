<?php

namespace MustHotelBooking\Admin;

if (!\function_exists(__NAMESPACE__ . '\get_default_email_templates')) {
    function get_default_email_templates(...$args)
    {
        return \MustHotelBooking\Engine\get_default_email_templates(...$args);
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

if (!\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
    function get_plugin_settings(...$args)
    {
        return \MustHotelBooking\Frontend\get_plugin_settings(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\install_frontend_pages')) {
    function install_frontend_pages(...$args)
    {
        return \MustHotelBooking\Frontend\install_frontend_pages(...$args);
    }
}

if (!\function_exists(__NAMESPACE__ . '\update_plugin_settings')) {
    function update_plugin_settings(...$args)
    {
        return \MustHotelBooking\Frontend\update_plugin_settings(...$args);
    }
}
