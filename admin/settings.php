<?php

namespace must_hotel_booking;

/**
 * Build Settings admin page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_settings_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-settings');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Get active settings tab.
 */
function get_settings_page_active_tab(): string
{
    return 'general';
}

/**
 * Get settings tab labels.
 *
 * @return array<string, string>
 */
function get_settings_tabs(): array
{
    return [
        'general' => \__('General', 'must-hotel-booking'),
    ];
}

/**
 * Render settings tabs.
 */
function render_settings_tabs_navigation(string $active_tab): void
{
    $tabs = get_settings_tabs();

    echo '<h2 class="nav-tab-wrapper must-hotel-booking-settings-tabs">';

    foreach ($tabs as $tab_key => $tab_label) {
        $tab_url = get_admin_settings_page_url(['tab' => $tab_key]);
        $tab_class = $tab_key === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
        echo '<a class="' . \esc_attr($tab_class) . '" href="' . \esc_url($tab_url) . '">' . \esc_html($tab_label) . '</a>';
    }

    echo '</h2>';
}

/**
 * Get default settings form values for the General tab.
 *
 * @return array<string, mixed>
 */
function get_settings_form_defaults(): array
{
    $hotel_name = \function_exists('get_bloginfo') ? (string) \get_bloginfo('name') : 'Hotel';

    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        return [
            'hotel_name' => MustBookingConfig::get_hotel_name(),
            'hotel_address' => MustBookingConfig::get_hotel_address(),
            'currency' => MustBookingConfig::get_currency(),
            'timezone' => MustBookingConfig::get_timezone(),
            'checkin_time' => MustBookingConfig::get_checkin_time(),
            'checkout_time' => MustBookingConfig::get_checkout_time(),
            'booking_window' => MustBookingConfig::get_booking_window(),
            'max_booking_guests' => MustBookingConfig::get_max_booking_guests(),
        ];
    }

    return [
        'hotel_name' => $hotel_name !== '' ? $hotel_name : 'Hotel',
        'hotel_address' => '',
        'currency' => 'USD',
        'timezone' => 'UTC',
        'checkin_time' => '14:00',
        'checkout_time' => '11:00',
        'booking_window' => 365,
        'max_booking_guests' => 5,
    ];
}

/**
 * Validate HH:MM time value.
 */
function is_valid_settings_time_value(string $value): bool
{
    $candidate = \trim($value);

    if (!\preg_match('/^\d{2}:\d{2}$/', $candidate)) {
        return false;
    }

    $hour = (int) \substr($candidate, 0, 2);
    $minute = (int) \substr($candidate, 3, 2);

    return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59;
}

/**
 * Validate timezone identifier.
 */
function is_valid_settings_timezone(string $timezone): bool
{
    $candidate = \trim($timezone);

    if ($candidate === '') {
        return false;
    }

    if (\in_array($candidate, \timezone_identifiers_list(), true)) {
        return true;
    }

    return (bool) \preg_match('/^UTC[+-]\d{1,2}(?::\d{2})?$/', $candidate);
}

/**
 * Normalize currency to uppercase letters.
 */
function normalize_settings_currency(string $currency): string
{
    $normalized = \strtoupper(\trim($currency));
    $normalized = (string) \preg_replace('/[^A-Z]/', '', $normalized);

    if ($normalized === '') {
        return 'USD';
    }

    if (\strlen($normalized) > 10) {
        $normalized = \substr($normalized, 0, 10);
    }

    return $normalized;
}

/**
 * Sanitize and validate General settings form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_settings_form_values(array $source): array
{
    $defaults = get_settings_form_defaults();
    $hotel_name = isset($source['hotel_name']) ? \sanitize_text_field((string) \wp_unslash($source['hotel_name'])) : '';
    $hotel_address = isset($source['hotel_address']) ? \sanitize_textarea_field((string) \wp_unslash($source['hotel_address'])) : '';
    $currency_raw = isset($source['currency']) ? \sanitize_text_field((string) \wp_unslash($source['currency'])) : '';
    $timezone = isset($source['timezone']) ? \sanitize_text_field((string) \wp_unslash($source['timezone'])) : '';
    $checkin_time = isset($source['checkin_time']) ? \sanitize_text_field((string) \wp_unslash($source['checkin_time'])) : '';
    $checkout_time = isset($source['checkout_time']) ? \sanitize_text_field((string) \wp_unslash($source['checkout_time'])) : '';
    $booking_window = isset($source['booking_window']) ? \absint(\wp_unslash($source['booking_window'])) : 0;
    $max_booking_guests = isset($source['max_booking_guests']) ? \absint(\wp_unslash($source['max_booking_guests'])) : 0;
    $errors = [];

    $currency = normalize_settings_currency($currency_raw);

    if ($hotel_name === '') {
        $errors[] = \__('Hotel name is required.', 'must-hotel-booking');
        $hotel_name = (string) $defaults['hotel_name'];
    }

    if (!is_valid_settings_timezone($timezone)) {
        $errors[] = \__('Please select a valid timezone.', 'must-hotel-booking');
        $timezone = (string) $defaults['timezone'];
    }

    if (!is_valid_settings_time_value($checkin_time)) {
        $errors[] = \__('Default check-in time must be in HH:MM format.', 'must-hotel-booking');
        $checkin_time = (string) $defaults['checkin_time'];
    }

    if (!is_valid_settings_time_value($checkout_time)) {
        $errors[] = \__('Default check-out time must be in HH:MM format.', 'must-hotel-booking');
        $checkout_time = (string) $defaults['checkout_time'];
    }

    if ($booking_window <= 0) {
        $errors[] = \__('Booking window must be greater than 0 days.', 'must-hotel-booking');
        $booking_window = (int) $defaults['booking_window'];
    }

    if ($max_booking_guests <= 0) {
        $errors[] = \__('Maximum booking guests must be greater than 0.', 'must-hotel-booking');
        $max_booking_guests = (int) $defaults['max_booking_guests'];
    }

    return [
        'hotel_name' => $hotel_name,
        'hotel_address' => $hotel_address,
        'currency' => $currency,
        'timezone' => $timezone,
        'checkin_time' => $checkin_time,
        'checkout_time' => $checkout_time,
        'booking_window' => $booking_window,
        'max_booking_guests' => $max_booking_guests,
        'errors' => $errors,
    ];
}

/**
 * Get default Design tab form values.
 *
 * @return array<string, mixed>
 */
function get_design_settings_form_defaults(): array
{
    if (\function_exists(__NAMESPACE__ . '\get_design_system_plugin_settings')) {
        return get_design_system_plugin_settings();
    }

    if (\function_exists(__NAMESPACE__ . '\get_design_system_plugin_defaults')) {
        return get_design_system_plugin_defaults();
    }

    return [
        'design_use_elementor_global_styles' => 1,
        'design_font_family' => 'Instrument Sans',
        'design_h1_size' => '54px',
        'design_h2_size' => '54px',
        'design_h3_size' => '32px',
        'design_h4_size' => '24px',
        'design_h5_size' => '20px',
        'design_h6_size' => '18px',
        'design_body_l_size' => '18px',
        'design_body_m_size' => '16px',
        'design_body_s_size' => '14px',
        'design_body_xs_size' => '12px',
        'design_body_xxs_size' => '10px',
        'design_button_l_size' => '20px',
        'design_button_m_size' => '16px',
        'design_button_s_size' => '14px',
        'design_primary_color' => '#F5F2E5',
        'design_secondary_color' => '#C1FC7E',
        'design_primary_black_color' => '#F4F1EE',
        'design_accent_blue_color' => '#FFFFFF',
        'design_light_blue_color' => '#E7E8FF',
        'design_secondary_blue_color' => '#FFFFFF',
        'design_accent_gold_color' => '#DA1E28',
    ];
}

/**
 * Build form data for the General tab.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_settings_form_data(?array $submitted_form = null): array
{
    $defaults = get_settings_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    return $defaults;
}

/**
 * Build form data for the Design tab.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_design_settings_form_data(?array $submitted_form = null): array
{
    $defaults = get_design_settings_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    return $defaults;
}

/**
 * Handle settings save action for both tabs.
 *
 * @return array<string, mixed>
 */
function maybe_handle_settings_save_request(): array
{
    $state = [
        'errors' => [],
        'general_form' => null,
        'notice' => '',
    ];

    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return $state;
    }

    $action = isset($_POST['must_settings_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_settings_action'])) : '';

    if ($action === 'save_general_settings' || $action === 'save_settings') {
        $nonce = isset($_POST['must_settings_nonce']) ? (string) \wp_unslash($_POST['must_settings_nonce']) : '';
        $nonce_ok = \wp_verify_nonce($nonce, 'must_settings_save_general') || \wp_verify_nonce($nonce, 'must_settings_save');

        if (!$nonce_ok) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        if (!\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
            $state['errors'] = [\__('Configuration class is unavailable.', 'must-hotel-booking')];
            return $state;
        }

        /** @var array<string, mixed> $raw_post */
        $raw_post = \is_array($_POST) ? $_POST : [];
        $settings_data = sanitize_settings_form_values($raw_post);

        if (!empty($settings_data['errors'])) {
            $state['errors'] = (array) $settings_data['errors'];
            $state['general_form'] = $settings_data;
            return $state;
        }

        $settings = MustBookingConfig::get_all_settings();
        $settings['hotel_name'] = (string) $settings_data['hotel_name'];
        $settings['hotel_address'] = (string) $settings_data['hotel_address'];
        $settings['currency'] = (string) $settings_data['currency'];
        $settings['timezone'] = (string) $settings_data['timezone'];
        $settings['checkin_time'] = (string) $settings_data['checkin_time'];
        $settings['checkout_time'] = (string) $settings_data['checkout_time'];
        $settings['booking_window'] = (int) $settings_data['booking_window'];
        $settings['max_booking_guests'] = (int) $settings_data['max_booking_guests'];

        MustBookingConfig::set_all_settings($settings);

        $state['notice'] = 'settings_saved';
        $state['general_form'] = $settings_data;

        return $state;
    }

    if ($action === 'save_design_settings') {
        $state['errors'] = [\__('The Design settings screen has been removed from this page.', 'must-hotel-booking')];
    }

    return $state;
}

/**
 * Handle successful settings saves before the page starts rendering.
 */
function maybe_handle_settings_save_request_early(): void
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return;
    }

    $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';
    $action = isset($_POST['must_settings_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_settings_action'])) : '';

    if ($page !== 'must-hotel-booking-settings' || ($action !== 'save_general_settings' && $action !== 'save_settings')) {
        return;
    }

    ensure_admin_capability();

    $nonce = isset($_POST['must_settings_nonce']) ? (string) \wp_unslash($_POST['must_settings_nonce']) : '';
    $nonce_ok = \wp_verify_nonce($nonce, 'must_settings_save_general') || \wp_verify_nonce($nonce, 'must_settings_save');

    if (!$nonce_ok || !\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        return;
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $settings_data = sanitize_settings_form_values($raw_post);

    if (!empty($settings_data['errors'])) {
        return;
    }

    $settings = MustBookingConfig::get_all_settings();
    $settings['hotel_name'] = (string) $settings_data['hotel_name'];
    $settings['hotel_address'] = (string) $settings_data['hotel_address'];
    $settings['currency'] = (string) $settings_data['currency'];
    $settings['timezone'] = (string) $settings_data['timezone'];
    $settings['checkin_time'] = (string) $settings_data['checkin_time'];
    $settings['checkout_time'] = (string) $settings_data['checkout_time'];
    $settings['booking_window'] = (int) $settings_data['booking_window'];
    $settings['max_booking_guests'] = (int) $settings_data['max_booking_guests'];

    MustBookingConfig::set_all_settings($settings);

    \wp_safe_redirect(get_admin_settings_page_url(['notice' => 'settings_saved']));
    exit;
}

/**
 * Render settings admin notice from query params.
 */
function render_settings_admin_notice(string $notice = ''): void
{
    if ($notice === '') {
        $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';
    }

    if ($notice === 'settings_saved') {
        echo '<div class="notice notice-success"><p>' . \esc_html__('Settings saved successfully.', 'must-hotel-booking') . '</p></div>';
    }
}

/**
 * Render General settings section.
 *
 * @param array<string, mixed> $form
 */
function render_general_settings_section(array $form): void
{
    $timezone = isset($form['timezone']) ? (string) $form['timezone'] : 'UTC';

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Hotel Configuration', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url()) . '">';
    \wp_nonce_field('must_settings_save_general', 'must_settings_nonce');
    echo '<input type="hidden" name="must_settings_action" value="save_general_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-settings-hotel-name">' . \esc_html__('Hotel name', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-hotel-name" type="text" class="regular-text" name="hotel_name" value="' . \esc_attr((string) $form['hotel_name']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-hotel-address">' . \esc_html__('Hotel address', 'must-hotel-booking') . '</label></th>';
    echo '<td><textarea id="must-settings-hotel-address" class="large-text" rows="3" name="hotel_address">' . \esc_textarea((string) $form['hotel_address']) . '</textarea></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-currency">' . \esc_html__('Currency', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-currency" type="text" class="small-text" name="currency" value="' . \esc_attr((string) $form['currency']) . '" required />';
    echo '<p class="description">' . \esc_html__('Use ISO code, for example USD or EUR.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-timezone">' . \esc_html__('Timezone', 'must-hotel-booking') . '</label></th>';
    echo '<td>';

    if (\function_exists('wp_timezone_choice')) {
        echo '<select id="must-settings-timezone" name="timezone">';
        echo \wp_timezone_choice($timezone);
        echo '</select>';
    } else {
        echo '<input id="must-settings-timezone" type="text" class="regular-text" name="timezone" value="' . \esc_attr($timezone) . '" />';
    }

    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-checkin-time">' . \esc_html__('Default check-in time', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-checkin-time" type="time" name="checkin_time" value="' . \esc_attr((string) $form['checkin_time']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-checkout-time">' . \esc_html__('Default check-out time', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-checkout-time" type="time" name="checkout_time" value="' . \esc_attr((string) $form['checkout_time']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-booking-window">' . \esc_html__('Booking window (days ahead)', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-booking-window" type="number" min="1" step="1" name="booking_window" value="' . \esc_attr((string) $form['booking_window']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-max-booking-guests">' . \esc_html__('Maximum booking guests', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-max-booking-guests" type="number" min="1" step="1" name="max_booking_guests" value="' . \esc_attr((string) $form['max_booking_guests']) . '" required />';
    echo '<p class="description">' . \esc_html__('Sets the guest limit used by the booking page and search widget.', 'must-hotel-booking') . '</p></td></tr>';

    echo '</tbody></table>';

    \submit_button(\__('Save Settings', 'must-hotel-booking'));

    echo '</form>';
    echo '</div>';
}

/**
 * Render Design settings section.
 *
 * @param array<string, mixed> $form
 */
function render_design_settings_section(array $form): void
{
    $elementor_status = \function_exists(__NAMESPACE__ . '\get_design_system_elementor_status')
        ? get_design_system_elementor_status()
        : ['active' => false, 'has_global_colors' => false, 'has_global_typography' => false];

    echo '<div class="postbox" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Design System', 'must-hotel-booking') . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'design'])) . '">';
    \wp_nonce_field('must_settings_save_design', 'must_settings_nonce');
    echo '<input type="hidden" name="must_settings_action" value="save_design_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">' . \esc_html__('Use Elementor Global Styles', 'must-hotel-booking') . '</th><td>';
    echo '<label for="must-settings-use-elementor-global-styles">';
    echo '<input id="must-settings-use-elementor-global-styles" type="checkbox" name="design_use_elementor_global_styles" value="1"' . (!empty($form['design_use_elementor_global_styles']) ? ' checked' : '') . ' /> ';
    echo \esc_html__('Inherit Elementor Global Colors and Typography when available.', 'must-hotel-booking');
    echo '</label>';

    if (!empty($elementor_status['active'])) {
        $status_line = \sprintf(
            /* translators: 1: colors state, 2: typography state. */
            \__('Elementor detected. Global colors: %1$s. Global typography: %2$s.', 'must-hotel-booking'),
            !empty($elementor_status['has_global_colors']) ? \__('found', 'must-hotel-booking') : \__('not found', 'must-hotel-booking'),
            !empty($elementor_status['has_global_typography']) ? \__('found', 'must-hotel-booking') : \__('not found', 'must-hotel-booking')
        );
        echo '<p class="description">' . \esc_html($status_line) . '</p>';
    } else {
        echo '<p class="description">' . \esc_html__('Elementor is not active. Plugin design settings and defaults will be used.', 'must-hotel-booking') . '</p>';
    }

    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-design-font-family">' . \esc_html__('Font family', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-design-font-family" type="text" class="regular-text" name="design_font_family" value="' . \esc_attr((string) $form['design_font_family']) . '" />';
    echo '<p class="description">' . \esc_html__('Example: Instrument Sans', 'must-hotel-booking') . '</p></td></tr>';

    $size_fields = [
        'design_h1_size' => \__('Heading 1 size', 'must-hotel-booking'),
        'design_h2_size' => \__('Heading 2 size', 'must-hotel-booking'),
        'design_h3_size' => \__('Heading 3 size', 'must-hotel-booking'),
        'design_h4_size' => \__('Heading 4 size', 'must-hotel-booking'),
        'design_h5_size' => \__('Heading 5 size', 'must-hotel-booking'),
        'design_h6_size' => \__('Heading 6 size', 'must-hotel-booking'),
        'design_body_l_size' => \__('Body Large size', 'must-hotel-booking'),
        'design_body_m_size' => \__('Body Medium size', 'must-hotel-booking'),
        'design_body_s_size' => \__('Body Small size', 'must-hotel-booking'),
        'design_body_xs_size' => \__('Body XS size', 'must-hotel-booking'),
        'design_body_xxs_size' => \__('Body XXS size', 'must-hotel-booking'),
        'design_button_l_size' => \__('Button Large size', 'must-hotel-booking'),
        'design_button_m_size' => \__('Button Medium size', 'must-hotel-booking'),
        'design_button_s_size' => \__('Button Small size', 'must-hotel-booking'),
    ];

    foreach ($size_fields as $key => $label) {
        echo '<tr><th scope="row"><label for="must-settings-' . \esc_attr($key) . '">' . \esc_html($label) . '</label></th>';
        echo '<td><input id="must-settings-' . \esc_attr($key) . '" type="text" class="small-text" name="' . \esc_attr($key) . '" value="' . \esc_attr((string) $form[$key]) . '" />';
        echo '<p class="description">' . \esc_html__('Use CSS size values, e.g. 16px, 1rem, 110%.', 'must-hotel-booking') . '</p></td></tr>';
    }

    $color_fields = [
        'design_primary_color' => \__('Primary color', 'must-hotel-booking'),
        'design_secondary_color' => \__('Secondary color', 'must-hotel-booking'),
        'design_primary_black_color' => \__('Primary black', 'must-hotel-booking'),
        'design_accent_blue_color' => \__('Accent blue', 'must-hotel-booking'),
        'design_light_blue_color' => \__('Light blue', 'must-hotel-booking'),
        'design_secondary_blue_color' => \__('Secondary blue', 'must-hotel-booking'),
        'design_accent_gold_color' => \__('Accent gold', 'must-hotel-booking'),
    ];

    foreach ($color_fields as $key => $label) {
        echo '<tr><th scope="row"><label for="must-settings-' . \esc_attr($key) . '">' . \esc_html($label) . '</label></th>';
        echo '<td><input id="must-settings-' . \esc_attr($key) . '" type="text" class="regular-text" name="' . \esc_attr($key) . '" value="' . \esc_attr((string) $form[$key]) . '" />';
        echo '<p class="description">' . \esc_html__('Hex color value, e.g. #F5F2E5.', 'must-hotel-booking') . '</p></td></tr>';
    }

    echo '</tbody></table>';

    \submit_button(\__('Save Design Settings', 'must-hotel-booking'));

    echo '</form>';
    echo '</div>';
}

/**
 * Render settings admin page.
 */
function render_admin_settings_page(): void
{
    ensure_admin_capability();

    $save_state = maybe_handle_settings_save_request();
    $errors = isset($save_state['errors']) && \is_array($save_state['errors']) ? $save_state['errors'] : [];
    $submitted_general_form = isset($save_state['general_form']) && \is_array($save_state['general_form']) ? $save_state['general_form'] : null;
    $notice = isset($save_state['notice']) ? (string) $save_state['notice'] : '';
    $general_form = get_settings_form_data($submitted_general_form);

    echo '<div class="wrap must-hotel-booking-settings-page">';
    echo '<h1>' . \esc_html__('Settings', 'must-hotel-booking') . '</h1>';

    render_settings_admin_notice($notice);

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    render_general_settings_section($general_form);

    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_settings_save_request_early', 1);
