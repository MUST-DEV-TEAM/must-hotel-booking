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
 * Get settings tab labels.
 *
 * @return array<string, string>
 */
function get_settings_tabs(): array
{
    return [
        'general' => \__('General', 'must-hotel-booking'),
        'booking' => \__('Booking', 'must-hotel-booking'),
        'pages' => \__('Pages', 'must-hotel-booking'),
        'design' => \__('Design', 'must-hotel-booking'),
        'diagnostics' => \__('Diagnostics', 'must-hotel-booking'),
    ];
}

/**
 * Get active settings tab.
 */
function get_settings_page_active_tab(): string
{
    $tabs = get_settings_tabs();
    $requested_tab = isset($_GET['tab']) ? \sanitize_key((string) \wp_unslash($_GET['tab'])) : 'general';

    return isset($tabs[$requested_tab]) ? $requested_tab : 'general';
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
 * Get default form values for the General tab.
 *
 * @return array<string, mixed>
 */
function get_general_settings_form_defaults(): array
{
    $hotel_name = \function_exists('get_bloginfo') ? (string) \get_bloginfo('name') : 'Hotel';
    $site_environment = \function_exists(__NAMESPACE__ . '\get_active_site_environment')
        ? get_active_site_environment()
        : 'production';

    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        return [
            'hotel_name' => MustBookingConfig::get_hotel_name(),
            'hotel_address' => MustBookingConfig::get_hotel_address(),
            'currency' => MustBookingConfig::get_currency(),
            'timezone' => MustBookingConfig::get_timezone(),
            'site_environment' => $site_environment,
        ];
    }

    return [
        'hotel_name' => $hotel_name !== '' ? $hotel_name : 'Hotel',
        'hotel_address' => '',
        'currency' => 'USD',
        'timezone' => 'UTC',
        'site_environment' => $site_environment,
    ];
}

/**
 * Build form data for the General tab.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_general_settings_form_data(?array $submitted_form = null): array
{
    $defaults = get_general_settings_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    return $defaults;
}

/**
 * Sanitize and validate General settings form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_general_settings_form_values(array $source): array
{
    $defaults = get_general_settings_form_defaults();
    $hotel_name = isset($source['hotel_name']) ? \sanitize_text_field((string) \wp_unslash($source['hotel_name'])) : '';
    $hotel_address = isset($source['hotel_address']) ? \sanitize_textarea_field((string) \wp_unslash($source['hotel_address'])) : '';
    $currency_raw = isset($source['currency']) ? \sanitize_text_field((string) \wp_unslash($source['currency'])) : '';
    $timezone = isset($source['timezone']) ? \sanitize_text_field((string) \wp_unslash($source['timezone'])) : '';
    $site_environment = isset($source['site_environment']) ? \sanitize_key((string) \wp_unslash($source['site_environment'])) : '';
    $errors = [];

    $currency = normalize_settings_currency($currency_raw);
    $site_environment = \function_exists(__NAMESPACE__ . '\normalize_stripe_environment')
        ? normalize_stripe_environment($site_environment)
        : (string) ($defaults['site_environment'] ?? 'production');

    if ($hotel_name === '') {
        $errors[] = \__('Hotel name is required.', 'must-hotel-booking');
        $hotel_name = (string) $defaults['hotel_name'];
    }

    if (!is_valid_settings_timezone($timezone)) {
        $errors[] = \__('Please select a valid timezone.', 'must-hotel-booking');
        $timezone = (string) $defaults['timezone'];
    }

    return [
        'hotel_name' => $hotel_name,
        'hotel_address' => $hotel_address,
        'currency' => $currency,
        'timezone' => $timezone,
        'site_environment' => $site_environment,
        'errors' => $errors,
    ];
}

/**
 * Get default form values for the Booking tab.
 *
 * @return array<string, mixed>
 */
function get_booking_settings_form_defaults(): array
{
    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        return [
            'checkin_time' => MustBookingConfig::get_checkin_time(),
            'checkout_time' => MustBookingConfig::get_checkout_time(),
            'booking_window' => MustBookingConfig::get_booking_window(),
            'max_booking_guests' => MustBookingConfig::get_max_booking_guests(),
            'max_booking_rooms' => MustBookingConfig::get_max_booking_rooms(),
        ];
    }

    return [
        'checkin_time' => '14:00',
        'checkout_time' => '11:00',
        'booking_window' => 365,
        'max_booking_guests' => 12,
        'max_booking_rooms' => 3,
    ];
}

/**
 * Build form data for the Booking tab.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_booking_settings_form_data(?array $submitted_form = null): array
{
    $defaults = get_booking_settings_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    return $defaults;
}

/**
 * Sanitize and validate Booking settings form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_booking_settings_form_values(array $source): array
{
    $defaults = get_booking_settings_form_defaults();
    $checkin_time = isset($source['checkin_time']) ? \sanitize_text_field((string) \wp_unslash($source['checkin_time'])) : '';
    $checkout_time = isset($source['checkout_time']) ? \sanitize_text_field((string) \wp_unslash($source['checkout_time'])) : '';
    $booking_window = isset($source['booking_window']) ? \absint(\wp_unslash($source['booking_window'])) : 0;
    $max_booking_guests = isset($source['max_booking_guests']) ? \absint(\wp_unslash($source['max_booking_guests'])) : 0;
    $max_booking_rooms = isset($source['max_booking_rooms']) ? \absint(\wp_unslash($source['max_booking_rooms'])) : 0;
    $errors = [];

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

    if ($max_booking_rooms <= 0) {
        $errors[] = \__('Maximum booking rooms must be greater than 0.', 'must-hotel-booking');
        $max_booking_rooms = (int) $defaults['max_booking_rooms'];
    }

    return [
        'checkin_time' => $checkin_time,
        'checkout_time' => $checkout_time,
        'booking_window' => $booking_window,
        'max_booking_guests' => $max_booking_guests,
        'max_booking_rooms' => $max_booking_rooms,
        'errors' => $errors,
    ];
}

/**
 * Get default form values for the Pages tab.
 *
 * @return array<string, mixed>
 */
function get_pages_settings_form_defaults(): array
{
    $settings = \function_exists(__NAMESPACE__ . '\get_plugin_settings') ? get_plugin_settings() : [];
    $defaults = [];
    $pages_config = \function_exists(__NAMESPACE__ . '\get_frontend_pages_config') ? get_frontend_pages_config() : [];

    foreach ($pages_config as $setting_key => $page_config) {
        unset($page_config);
        $defaults[$setting_key] = isset($settings[$setting_key]) ? (int) $settings[$setting_key] : 0;
    }

    return $defaults;
}

/**
 * Build form data for the Pages tab.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_pages_settings_form_data(?array $submitted_form = null): array
{
    $defaults = get_pages_settings_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    return $defaults;
}

/**
 * Sanitize and validate Pages settings form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_pages_settings_form_values(array $source): array
{
    $defaults = get_pages_settings_form_defaults();
    $pages_config = \function_exists(__NAMESPACE__ . '\get_frontend_pages_config') ? get_frontend_pages_config() : [];
    $errors = [];
    $values = [];

    foreach ($pages_config as $setting_key => $page_config) {
        $page_id = isset($source[$setting_key]) ? \absint(\wp_unslash($source[$setting_key])) : 0;
        $page_title = isset($page_config['title']) ? (string) $page_config['title'] : $setting_key;

        if ($page_id <= 0) {
            $errors[] = \sprintf(
                /* translators: %s is the managed page title. */
                \__('%s page assignment is required.', 'must-hotel-booking'),
                $page_title
            );
            $values[$setting_key] = (int) ($defaults[$setting_key] ?? 0);
            continue;
        }

        $page = \get_post($page_id);

        if (!$page instanceof \WP_Post || $page->post_type !== 'page') {
            $errors[] = \sprintf(
                /* translators: %s is the managed page title. */
                \__('%s must be assigned to a valid WordPress page.', 'must-hotel-booking'),
                $page_title
            );
            $values[$setting_key] = (int) ($defaults[$setting_key] ?? 0);
            continue;
        }

        $values[$setting_key] = $page_id;
    }

    $values['errors'] = $errors;

    return $values;
}

/**
 * Get available WordPress pages for page selectors.
 *
 * @return array<int, string>
 */
function get_settings_page_options(): array
{
    $pages = \get_pages(
        [
            'sort_column' => 'menu_order,post_title',
            'sort_order' => 'ASC',
        ]
    );
    $options = [];

    foreach ($pages as $page) {
        if (!$page instanceof \WP_Post) {
            continue;
        }

        $status_suffix = $page->post_status !== 'publish'
            ? ' (' . \ucfirst((string) $page->post_status) . ')'
            : '';

        $options[(int) $page->ID] = (string) $page->post_title . $status_suffix;
    }

    return $options;
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
 * Save values to plugin settings.
 *
 * @param array<string, mixed> $values
 */
function save_settings_values(array $values): void
{
    if (!\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        return;
    }

    $settings = MustBookingConfig::get_all_settings();

    foreach ($values as $key => $value) {
        if ($key === 'errors') {
            continue;
        }

        $settings[$key] = $value;
    }

    MustBookingConfig::set_all_settings($settings);
}

/**
 * Get a tab key for a settings action.
 */
function get_settings_action_tab(string $action): string
{
    $map = [
        'save_general_settings' => 'general',
        'save_booking_settings' => 'booking',
        'save_page_settings' => 'pages',
        'sync_frontend_pages' => 'pages',
        'save_design_settings' => 'design',
        'run_diagnostics' => 'diagnostics',
    ];

    return isset($map[$action]) ? $map[$action] : 'general';
}

/**
 * Process one settings-page POST action.
 *
 * @param array<string, mixed> $raw_post
 * @return array<string, mixed>
 */
function process_settings_post_action(string $action, array $raw_post, bool $persist): array
{
    $state = [
        'tab' => get_settings_action_tab($action),
        'notice' => '',
        'errors' => [],
        'general_form' => null,
        'booking_form' => null,
        'pages_form' => null,
        'design_form' => null,
    ];

    if (!\class_exists(__NAMESPACE__ . '\MustBookingConfig') && $action !== 'run_diagnostics' && $action !== 'sync_frontend_pages') {
        $state['errors'] = [\__('Configuration class is unavailable.', 'must-hotel-booking')];
        return $state;
    }

    if ($action === 'save_general_settings') {
        $nonce = isset($raw_post['must_settings_nonce']) ? (string) \wp_unslash($raw_post['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_save_general')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $general_form = sanitize_general_settings_form_values($raw_post);
        $state['general_form'] = $general_form;

        if (!empty($general_form['errors'])) {
            $state['errors'] = (array) $general_form['errors'];
            return $state;
        }

        if ($persist) {
            save_settings_values($general_form);
        }

        $state['notice'] = 'general_settings_saved';

        return $state;
    }

    if ($action === 'save_booking_settings') {
        $nonce = isset($raw_post['must_settings_nonce']) ? (string) \wp_unslash($raw_post['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_save_booking')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $booking_form = sanitize_booking_settings_form_values($raw_post);
        $state['booking_form'] = $booking_form;

        if (!empty($booking_form['errors'])) {
            $state['errors'] = (array) $booking_form['errors'];
            return $state;
        }

        if ($persist) {
            save_settings_values($booking_form);
        }

        $state['notice'] = 'booking_settings_saved';

        return $state;
    }

    if ($action === 'save_page_settings') {
        $nonce = isset($raw_post['must_settings_nonce']) ? (string) \wp_unslash($raw_post['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_save_pages')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $pages_form = sanitize_pages_settings_form_values($raw_post);
        $state['pages_form'] = $pages_form;

        if (!empty($pages_form['errors'])) {
            $state['errors'] = (array) $pages_form['errors'];
            return $state;
        }

        if ($persist) {
            save_settings_values($pages_form);
        }

        $state['notice'] = 'page_settings_saved';

        return $state;
    }

    if ($action === 'sync_frontend_pages') {
        $nonce = isset($raw_post['must_settings_nonce']) ? (string) \wp_unslash($raw_post['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_sync_pages')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        if ($persist && \function_exists(__NAMESPACE__ . '\install_frontend_pages')) {
            install_frontend_pages();
        }

        $state['notice'] = 'pages_synced';

        return $state;
    }

    if ($action === 'save_design_settings') {
        $nonce = isset($raw_post['must_settings_nonce']) ? (string) \wp_unslash($raw_post['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_save_design')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        if (!\function_exists(__NAMESPACE__ . '\sanitize_design_settings_form_values')) {
            $state['errors'] = [\__('Design settings helpers are unavailable.', 'must-hotel-booking')];
            return $state;
        }

        $design_form = sanitize_design_settings_form_values($raw_post);
        $state['design_form'] = $design_form;

        if (!empty($design_form['errors'])) {
            $state['errors'] = (array) $design_form['errors'];
            return $state;
        }

        if ($persist) {
            save_settings_values($design_form);
        }

        $state['notice'] = 'design_settings_saved';

        return $state;
    }

    if ($action === 'run_diagnostics') {
        $nonce = isset($raw_post['must_settings_nonce']) ? (string) \wp_unslash($raw_post['must_settings_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_settings_run_diagnostics')) {
            $state['errors'] = [\__('Security check failed. Please try again.', 'must-hotel-booking')];
            return $state;
        }

        $state['notice'] = 'diagnostics_refreshed';

        return $state;
    }

    return $state;
}

/**
 * Handle settings save action when the page is rendering.
 *
 * @return array<string, mixed>
 */
function maybe_handle_settings_save_request(): array
{
    $state = [
        'tab' => get_settings_page_active_tab(),
        'notice' => '',
        'errors' => [],
        'general_form' => null,
        'booking_form' => null,
        'pages_form' => null,
        'design_form' => null,
    ];

    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return $state;
    }

    $page = isset($_GET['page']) ? \sanitize_key((string) \wp_unslash($_GET['page'])) : '';

    if ($page !== 'must-hotel-booking-settings') {
        return $state;
    }

    $action = isset($_POST['must_settings_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_settings_action'])) : '';

    if ($action === '') {
        return $state;
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];

    return process_settings_post_action($action, $raw_post, false);
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

    if ($page !== 'must-hotel-booking-settings' || $action === '') {
        return;
    }

    ensure_admin_capability();

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $state = process_settings_post_action($action, $raw_post, true);

    if (!empty($state['errors']) || (string) $state['notice'] === '') {
        return;
    }

    \wp_safe_redirect(
        get_admin_settings_page_url(
            [
                'tab' => (string) $state['tab'],
                'notice' => (string) $state['notice'],
            ]
        )
    );
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

    $messages = [
        'general_settings_saved' => \__('General settings saved successfully.', 'must-hotel-booking'),
        'booking_settings_saved' => \__('Booking settings saved successfully.', 'must-hotel-booking'),
        'page_settings_saved' => \__('Managed page assignments saved successfully.', 'must-hotel-booking'),
        'pages_synced' => \__('Managed frontend pages were synced successfully.', 'must-hotel-booking'),
        'design_settings_saved' => \__('Design settings saved successfully.', 'must-hotel-booking'),
        'diagnostics_refreshed' => \__('System checks refreshed.', 'must-hotel-booking'),
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    echo '<div class="notice notice-success"><p>' . \esc_html($messages[$notice]) . '</p></div>';
}

/**
 * Render General settings section.
 *
 * @param array<string, mixed> $form
 */
function render_general_settings_section(array $form): void
{
    $timezone = isset($form['timezone']) ? (string) $form['timezone'] : 'UTC';
    $site_environment = isset($form['site_environment']) ? (string) $form['site_environment'] : 'production';
    $environment_catalog = \function_exists(__NAMESPACE__ . '\get_stripe_environment_catalog')
        ? get_stripe_environment_catalog()
        : [
            'local' => ['label' => \__('Localhost', 'must-hotel-booking'), 'description' => '', 'example' => 'http://localhost'],
            'staging' => ['label' => \__('Staging / IP website', 'must-hotel-booking'), 'description' => '', 'example' => 'http://18.185.56.94/'],
            'production' => ['label' => \__('Live website', 'must-hotel-booking'), 'description' => '', 'example' => 'https://empirebeachresort.al'],
        ];
    $current_site_url = (string) \home_url('/');

    echo '<div class="postbox" style="max-width:960px; padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Hotel Configuration', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('These settings define the hotel identity and the basic defaults used across the booking flow.', 'must-hotel-booking') . '</p>';
    echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'general'])) . '">';
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
    echo '<tr><th scope="row"><label for="must-settings-site-environment">' . \esc_html__('Site environment', 'must-hotel-booking') . '</label></th>';
    echo '<td><select id="must-settings-site-environment" name="site_environment">';

    foreach ($environment_catalog as $environment_key => $environment_meta) {
        $environment_label = isset($environment_meta['label']) ? (string) $environment_meta['label'] : $environment_key;
        echo '<option value="' . \esc_attr($environment_key) . '"' . \selected($site_environment, $environment_key, false) . '>' . \esc_html($environment_label) . '</option>';
    }

    echo '</select>';
    echo '<p class="description">' . \esc_html__('This decides which Stripe credentials are active on this site.', 'must-hotel-booking') . '</p>';

    foreach ($environment_catalog as $environment_meta) {
        if (!\is_array($environment_meta)) {
            continue;
        }

        $environment_label = isset($environment_meta['label']) ? (string) $environment_meta['label'] : '';
        $environment_description = isset($environment_meta['description']) ? (string) $environment_meta['description'] : '';
        $environment_example = isset($environment_meta['example']) ? (string) $environment_meta['example'] : '';
        $description_parts = [];

        if ($environment_description !== '') {
            $description_parts[] = $environment_description;
        }

        if ($environment_example !== '') {
            $description_parts[] = \sprintf(
                /* translators: %s is an example site URL. */
                \__('Example: %s', 'must-hotel-booking'),
                $environment_example
            );
        }

        if (empty($description_parts)) {
            continue;
        }

        echo '<p class="description"><strong>' . \esc_html($environment_label) . ':</strong> ' . \esc_html(\implode(' ', $description_parts)) . '</p>';
    }

    echo '<p class="description">' . \esc_html(\sprintf(
        /* translators: %s is the current site URL. */
        __('Current site URL: %s', 'must-hotel-booking'),
        $current_site_url
    )) . '</p></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Save General Settings', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';
}

/**
 * Render Booking settings section.
 *
 * @param array<string, mixed> $form
 */
function render_booking_settings_section(array $form): void
{
    echo '<div class="postbox" style="max-width:960px; padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Booking Rules', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('These values control the limits and default timing used by the public booking experience.', 'must-hotel-booking') . '</p>';
    echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'booking'])) . '">';
    \wp_nonce_field('must_settings_save_booking', 'must_settings_nonce');
    echo '<input type="hidden" name="must_settings_action" value="save_booking_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-settings-checkin-time">' . \esc_html__('Default check-in time', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-checkin-time" type="time" name="checkin_time" value="' . \esc_attr((string) $form['checkin_time']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-checkout-time">' . \esc_html__('Default check-out time', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-checkout-time" type="time" name="checkout_time" value="' . \esc_attr((string) $form['checkout_time']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-booking-window">' . \esc_html__('Booking window (days ahead)', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-booking-window" type="number" min="1" step="1" name="booking_window" value="' . \esc_attr((string) $form['booking_window']) . '" required />';
    echo '<p class="description">' . \esc_html__('Guests can search and reserve dates up to this many days ahead.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-max-booking-guests">' . \esc_html__('Maximum booking guests', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-max-booking-guests" type="number" min="1" step="1" name="max_booking_guests" value="' . \esc_attr((string) $form['max_booking_guests']) . '" required />';
    echo '<p class="description">' . \esc_html__('Sets the guest limit used by the booking page and search widget.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-settings-max-booking-rooms">' . \esc_html__('Maximum booking rooms', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-settings-max-booking-rooms" type="number" min="1" step="1" name="max_booking_rooms" value="' . \esc_attr((string) $form['max_booking_rooms']) . '" required />';
    echo '<p class="description">' . \esc_html__('Sets the room-count limit used by the multi-room booking flow.', 'must-hotel-booking') . '</p></td></tr>';

    echo '</tbody></table>';
    \submit_button(\__('Save Booking Settings', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';
}

/**
 * Render Pages settings section.
 *
 * @param array<string, mixed> $form
 */
function render_pages_settings_section(array $form): void
{
    $page_options = get_settings_page_options();
    $pages_config = \function_exists(__NAMESPACE__ . '\get_frontend_pages_config') ? get_frontend_pages_config() : [];

    echo '<div class="postbox" style="max-width:1100px; padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Managed Frontend Pages', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('Assign the WordPress pages used by the public booking flow. These should remain normal pages, not posts or custom post types.', 'must-hotel-booking') . '</p>';
    echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'pages'])) . '">';
    \wp_nonce_field('must_settings_save_pages', 'must_settings_nonce');
    echo '<input type="hidden" name="must_settings_action" value="save_page_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    foreach ($pages_config as $setting_key => $page_config) {
        $page_id = isset($form[$setting_key]) ? (int) $form[$setting_key] : 0;
        $page = $page_id > 0 ? \get_post($page_id) : null;
        $edit_url = ($page instanceof \WP_Post) ? \get_edit_post_link($page_id, 'raw') : '';
        $view_url = ($page instanceof \WP_Post) ? \get_permalink($page_id) : '';
        $template = isset($page_config['template']) ? (string) $page_config['template'] : '';

        echo '<tr><th scope="row"><label for="must-settings-' . \esc_attr($setting_key) . '">' . \esc_html((string) $page_config['title']) . '</label></th>';
        echo '<td><select id="must-settings-' . \esc_attr($setting_key) . '" name="' . \esc_attr($setting_key) . '" required>';
        echo '<option value="0">' . \esc_html__('Select a page', 'must-hotel-booking') . '</option>';

        foreach ($page_options as $option_id => $option_label) {
            echo '<option value="' . \esc_attr((string) $option_id) . '"' . \selected($page_id, $option_id, false) . '>' . \esc_html($option_label) . '</option>';
        }

        echo '</select>';
        echo '<p class="description">';
        echo \esc_html(
            \sprintf(
                /* translators: 1: page slug, 2: template path. */
                \__('Default slug: %1$s. Template: %2$s.', 'must-hotel-booking'),
                (string) ($page_config['slug'] ?? ''),
                $template
            )
        );

        if ($edit_url !== '') {
            echo ' <a href="' . \esc_url($edit_url) . '">' . \esc_html__('Edit page', 'must-hotel-booking') . '</a>';
        }

        if (\is_string($view_url) && $view_url !== '') {
            echo ' | <a href="' . \esc_url($view_url) . '" target="_blank" rel="noopener noreferrer">' . \esc_html__('View page', 'must-hotel-booking') . '</a>';
        }

        echo '</p></td></tr>';
    }

    echo '</tbody></table>';
    \submit_button(\__('Save Page Assignments', 'must-hotel-booking'));
    echo '</form>';
    echo '</div>';

    echo '<div class="postbox" style="max-width:1100px; padding:16px; margin-top:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Sync Managed Pages', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('If a managed booking page was deleted or never created, you can re-run the page installer here.', 'must-hotel-booking') . '</p>';
    echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'pages'])) . '">';
    \wp_nonce_field('must_settings_sync_pages', 'must_settings_nonce');
    echo '<input type="hidden" name="must_settings_action" value="sync_frontend_pages" />';
    \submit_button(\__('Create / Sync Managed Pages', 'must-hotel-booking'), 'secondary');
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

    echo '<div class="postbox" style="max-width:1100px; padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Design System', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('These design tokens control the plugin UI when you are not inheriting Elementor global styles.', 'must-hotel-booking') . '</p>';
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
    echo '<p class="description">' . \esc_html__('Example: PP Neue Montreal', 'must-hotel-booking') . '</p></td></tr>';

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
 * Check whether a database table exists.
 */
function does_settings_diagnostic_table_exist(string $table_name): bool
{
    if ($table_name === '') {
        return false;
    }

    global $wpdb;

    $match = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    return \is_string($match) && $match !== '';
}

/**
 * Build runtime diagnostics for the Settings page.
 *
 * @return array<string, mixed>
 */
function get_settings_diagnostics_data(): array
{
    global $wpdb;

    $table_checks = [
        'Rooms' => $wpdb->prefix . 'must_rooms',
        'Room Meta' => $wpdb->prefix . 'must_room_meta',
        'Guests' => $wpdb->prefix . 'must_guests',
        'Reservations' => $wpdb->prefix . 'must_reservations',
        'Pricing' => $wpdb->prefix . 'must_pricing',
        'Availability' => $wpdb->prefix . 'must_availability',
        'Locks' => $wpdb->prefix . 'must_locks',
        'Payments' => $wpdb->prefix . 'must_payments',
        'Taxes' => $wpdb->prefix . 'must_taxes',
        'Coupons' => $wpdb->prefix . 'must_coupons',
    ];
    $tables = [];
    $critical_issues = 0;
    $warnings = 0;

    foreach ($table_checks as $label => $table_name) {
        $exists = does_settings_diagnostic_table_exist($table_name);

        if (!$exists) {
            $critical_issues++;
        }

        $tables[] = [
            'label' => $label,
            'table_name' => $table_name,
            'status' => $exists ? 'healthy' : 'error',
            'message' => $exists
                ? \__('Table found.', 'must-hotel-booking')
                : \__('Missing. Reactivate the plugin or run the installer.', 'must-hotel-booking'),
        ];
    }

    $pages = [];
    $pages_config = \function_exists(__NAMESPACE__ . '\get_frontend_pages_config') ? get_frontend_pages_config() : [];
    $settings = \function_exists(__NAMESPACE__ . '\get_plugin_settings') ? get_plugin_settings() : [];

    foreach ($pages_config as $setting_key => $page_config) {
        $page_id = isset($settings[$setting_key]) ? (int) $settings[$setting_key] : 0;
        $page = $page_id > 0 ? \get_post($page_id) : null;
        $healthy = $page instanceof \WP_Post && $page->post_type === 'page';

        if (!$healthy) {
            $critical_issues++;
        }

        $pages[] = [
            'label' => (string) ($page_config['title'] ?? $setting_key),
            'page_id' => $page_id,
            'status' => $healthy ? 'healthy' : 'error',
            'message' => $healthy
                ? \sprintf(
                    /* translators: 1: page title, 2: page status. */
                    \__('Assigned to "%1$s" (%2$s).', 'must-hotel-booking'),
                    (string) $page->post_title,
                    (string) $page->post_status
                )
                : \__('Not assigned to a valid page.', 'must-hotel-booking'),
            'edit_url' => $healthy ? \get_edit_post_link($page_id, 'raw') : '',
            'view_url' => $healthy ? \get_permalink($page_id) : '',
        ];
    }

    $cron_hook = \function_exists(__NAMESPACE__ . '\get_lock_cleanup_cron_hook') ? get_lock_cleanup_cron_hook() : '';
    $next_cron = $cron_hook !== '' ? \wp_next_scheduled($cron_hook) : false;
    $cron_scheduled = $next_cron !== false;

    if (!$cron_scheduled) {
        $critical_issues++;
    }

    $cron = [
        'status' => $cron_scheduled ? 'healthy' : 'error',
        'message' => $cron_scheduled
            ? \__('Recurring lock cleanup is scheduled.', 'must-hotel-booking')
            : \__('Recurring lock cleanup is not scheduled.', 'must-hotel-booking'),
        'next_run' => $cron_scheduled && \is_numeric($next_cron)
            ? \wp_date('Y-m-d H:i:s', (int) $next_cron)
            : \__('Not scheduled', 'must-hotel-booking'),
        'hook' => $cron_hook,
    ];

    $enabled_methods = \function_exists(__NAMESPACE__ . '\get_enabled_payment_methods') ? get_enabled_payment_methods() : [];
    $payment_catalog = \function_exists(__NAMESPACE__ . '\get_payment_methods_catalog') ? get_payment_methods_catalog() : [];
    $enabled_method_labels = [];

    foreach ($enabled_methods as $method_key) {
        $enabled_method_labels[] = isset($payment_catalog[$method_key]['label'])
            ? (string) $payment_catalog[$method_key]['label']
            : (string) $method_key;
    }

    $stripe_enabled = \in_array('stripe', $enabled_methods, true);
    $stripe_configured = \function_exists(__NAMESPACE__ . '\is_stripe_checkout_configured') ? is_stripe_checkout_configured() : false;
    $stripe_webhook_secret_set = \function_exists(__NAMESPACE__ . '\get_stripe_webhook_secret')
        ? get_stripe_webhook_secret() !== ''
        : false;

    if ($stripe_enabled && !$stripe_configured) {
        $warnings++;
    }

    if ($stripe_enabled && !$stripe_webhook_secret_set) {
        $warnings++;
    }

    $payments = [
        'enabled_methods' => $enabled_method_labels,
        'stripe_enabled' => $stripe_enabled,
        'stripe_configured' => $stripe_configured,
        'stripe_webhook_secret_set' => $stripe_webhook_secret_set,
        'stripe_environment' => \function_exists(__NAMESPACE__ . '\get_site_environment_label')
            ? get_site_environment_label()
            : '',
        'stripe_webhook_url' => \function_exists(__NAMESPACE__ . '\get_stripe_webhook_url')
            ? get_stripe_webhook_url()
            : '',
    ];

    $room_count = 0;

    if (does_settings_diagnostic_table_exist($wpdb->prefix . 'must_rooms')) {
        $room_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'must_rooms');
    }

    if ($room_count <= 0) {
        $warnings++;
    }

    $environment = [
        'plugin_version' => \defined('MUST_HOTEL_BOOKING_VERSION') ? MUST_HOTEL_BOOKING_VERSION : '',
        'database_version' => (string) \get_option('must_hotel_booking_db_version', ''),
        'wordpress_version' => (string) \get_bloginfo('version'),
        'php_version' => \PHP_VERSION,
        'site_url' => (string) \home_url('/'),
        'site_environment' => \function_exists(__NAMESPACE__ . '\get_site_environment_label')
            ? get_site_environment_label()
            : '',
        'room_count' => $room_count,
        'email_template_count' => \function_exists(__NAMESPACE__ . '\get_email_templates')
            ? \count((array) get_email_templates())
            : 0,
    ];

    $overall_status = 'healthy';

    if ($critical_issues > 0) {
        $overall_status = 'error';
    } elseif ($warnings > 0) {
        $overall_status = 'warning';
    }

    return [
        'overall_status' => $overall_status,
        'critical_issues' => $critical_issues,
        'warnings' => $warnings,
        'tables' => $tables,
        'pages' => $pages,
        'cron' => $cron,
        'payments' => $payments,
        'environment' => $environment,
    ];
}

/**
 * Render one diagnostics status badge.
 */
function render_settings_status_badge(string $status): string
{
    $status = \sanitize_key($status);
    $map = [
        'healthy' => ['label' => \__('Healthy', 'must-hotel-booking'), 'background' => '#d1fae5', 'color' => '#065f46'],
        'warning' => ['label' => \__('Warning', 'must-hotel-booking'), 'background' => '#fef3c7', 'color' => '#92400e'],
        'error' => ['label' => \__('Error', 'must-hotel-booking'), 'background' => '#fee2e2', 'color' => '#991b1b'],
    ];
    $resolved = isset($map[$status]) ? $map[$status] : $map['warning'];

    return '<span style="display:inline-block; padding:4px 10px; border-radius:999px; background:' . \esc_attr($resolved['background']) . '; color:' . \esc_attr($resolved['color']) . '; font-weight:600;">' . \esc_html($resolved['label']) . '</span>';
}

/**
 * Render Diagnostics tab content.
 */
function render_diagnostics_settings_section(): void
{
    $diagnostics = get_settings_diagnostics_data();
    $payments = isset($diagnostics['payments']) && \is_array($diagnostics['payments']) ? $diagnostics['payments'] : [];
    $cron = isset($diagnostics['cron']) && \is_array($diagnostics['cron']) ? $diagnostics['cron'] : [];
    $environment = isset($diagnostics['environment']) && \is_array($diagnostics['environment']) ? $diagnostics['environment'] : [];
    $overall_status = isset($diagnostics['overall_status']) ? (string) $diagnostics['overall_status'] : 'warning';

    echo '<div class="postbox" style="max-width:1100px; padding:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('System Health', 'must-hotel-booking') . '</h2>';
    echo '<p>' . \esc_html__('This tab runs runtime configuration and integrity checks. It is useful for QA and release readiness, but it does not replace code-level automated tests.', 'must-hotel-booking') . '</p>';
    echo '<p>' . render_settings_status_badge($overall_status) . '</p>';
    echo '<p>';
    echo \esc_html(
        \sprintf(
            /* translators: 1: critical issue count, 2: warning count. */
            \__('Critical issues: %1$d. Warnings: %2$d.', 'must-hotel-booking'),
            (int) ($diagnostics['critical_issues'] ?? 0),
            (int) ($diagnostics['warnings'] ?? 0)
        )
    );
    echo '</p>';
    echo '<form method="post" action="' . \esc_url(get_admin_settings_page_url(['tab' => 'diagnostics'])) . '">';
    \wp_nonce_field('must_settings_run_diagnostics', 'must_settings_nonce');
    echo '<input type="hidden" name="must_settings_action" value="run_diagnostics" />';
    \submit_button(\__('Run System Checks', 'must-hotel-booking'), 'secondary');
    echo '</form>';
    echo '</div>';

    echo '<div class="postbox" style="max-width:1100px; padding:16px; margin-top:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Environment', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>' . \esc_html__('Plugin version', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($environment['plugin_version'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Database version', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($environment['database_version'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('WordPress version', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($environment['wordpress_version'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('PHP version', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($environment['php_version'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Site URL', 'must-hotel-booking') . '</th><td><code>' . \esc_html((string) ($environment['site_url'] ?? '')) . '</code></td></tr>';
    echo '<tr><th>' . \esc_html__('Active site environment', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($environment['site_environment'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Configured rooms', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($environment['room_count'] ?? 0)) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Email templates', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($environment['email_template_count'] ?? 0)) . '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="postbox" style="max-width:1100px; padding:16px; margin-top:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Database Tables', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Table', 'must-hotel-booking') . '</th><th>' . \esc_html__('Details', 'must-hotel-booking') . '</th></tr></thead><tbody>';

    foreach ((array) ($diagnostics['tables'] ?? []) as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . render_settings_status_badge((string) ($row['status'] ?? 'warning')) . '</td>';
        echo '<td><strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong><br /><code>' . \esc_html((string) ($row['table_name'] ?? '')) . '</code></td>';
        echo '<td>' . \esc_html((string) ($row['message'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="postbox" style="max-width:1100px; padding:16px; margin-top:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Managed Pages', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Page', 'must-hotel-booking') . '</th><th>' . \esc_html__('Details', 'must-hotel-booking') . '</th></tr></thead><tbody>';

    foreach ((array) ($diagnostics['pages'] ?? []) as $row) {
        if (!\is_array($row)) {
            continue;
        }

        echo '<tr>';
        echo '<td>' . render_settings_status_badge((string) ($row['status'] ?? 'warning')) . '</td>';
        echo '<td><strong>' . \esc_html((string) ($row['label'] ?? '')) . '</strong></td>';
        echo '<td>' . \esc_html((string) ($row['message'] ?? ''));

        if (!empty($row['edit_url'])) {
            echo ' <a href="' . \esc_url((string) $row['edit_url']) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a>';
        }

        if (!empty($row['view_url'])) {
            echo ' | <a href="' . \esc_url((string) $row['view_url']) . '" target="_blank" rel="noopener noreferrer">' . \esc_html__('View', 'must-hotel-booking') . '</a>';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="postbox" style="max-width:1100px; padding:16px; margin-top:16px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Cron & Payments', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>' . \esc_html__('Lock cleanup cron', 'must-hotel-booking') . '</th><td>' . render_settings_status_badge((string) ($cron['status'] ?? 'warning')) . ' ' . \esc_html((string) ($cron['message'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Next lock cleanup run', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($cron['next_run'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Cron hook', 'must-hotel-booking') . '</th><td><code>' . \esc_html((string) ($cron['hook'] ?? '')) . '</code></td></tr>';
    echo '<tr><th>' . \esc_html__('Enabled payment methods', 'must-hotel-booking') . '</th><td>' . \esc_html(!empty($payments['enabled_methods']) ? \implode(', ', (array) $payments['enabled_methods']) : __('None enabled', 'must-hotel-booking')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Active Stripe profile', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($payments['stripe_environment'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Stripe configuration', 'must-hotel-booking') . '</th><td>' . render_settings_status_badge(!empty($payments['stripe_configured']) ? 'healthy' : (!empty($payments['stripe_enabled']) ? 'warning' : 'warning')) . ' ';
    echo \esc_html(
        !empty($payments['stripe_enabled'])
            ? (!empty($payments['stripe_configured']) ? __('Stripe keys are configured.', 'must-hotel-booking') : __('Stripe is enabled but not fully configured.', 'must-hotel-booking'))
            : __('Stripe is currently disabled.', 'must-hotel-booking')
    );
    echo '</td></tr>';
    echo '<tr><th>' . \esc_html__('Stripe webhook secret', 'must-hotel-booking') . '</th><td>' . render_settings_status_badge(!empty($payments['stripe_webhook_secret_set']) ? 'healthy' : (!empty($payments['stripe_enabled']) ? 'warning' : 'warning')) . ' ';
    echo \esc_html(!empty($payments['stripe_webhook_secret_set']) ? __('Present', 'must-hotel-booking') : __('Not configured', 'must-hotel-booking')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Stripe webhook URL', 'must-hotel-booking') . '</th><td><code>' . \esc_html((string) ($payments['stripe_webhook_url'] ?? '')) . '</code>';

    if (\function_exists(__NAMESPACE__ . '\get_admin_payments_page_url')) {
        echo ' <a href="' . \esc_url(get_admin_payments_page_url()) . '">' . \esc_html__('Open Payments settings', 'must-hotel-booking') . '</a>';
    }

    echo '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Render settings admin page.
 */
function render_admin_settings_page(): void
{
    ensure_admin_capability();

    $save_state = maybe_handle_settings_save_request();
    $active_tab = isset($save_state['tab']) ? (string) $save_state['tab'] : get_settings_page_active_tab();
    $errors = isset($save_state['errors']) && \is_array($save_state['errors']) ? $save_state['errors'] : [];
    $notice = isset($save_state['notice']) ? (string) $save_state['notice'] : '';
    $general_form = get_general_settings_form_data(
        isset($save_state['general_form']) && \is_array($save_state['general_form']) ? $save_state['general_form'] : null
    );
    $booking_form = get_booking_settings_form_data(
        isset($save_state['booking_form']) && \is_array($save_state['booking_form']) ? $save_state['booking_form'] : null
    );
    $pages_form = get_pages_settings_form_data(
        isset($save_state['pages_form']) && \is_array($save_state['pages_form']) ? $save_state['pages_form'] : null
    );
    $design_form = get_design_settings_form_data(
        isset($save_state['design_form']) && \is_array($save_state['design_form']) ? $save_state['design_form'] : null
    );

    echo '<div class="wrap must-hotel-booking-settings-page">';
    echo '<h1>' . \esc_html__('Settings', 'must-hotel-booking') . '</h1>';

    render_settings_admin_notice($notice);
    render_settings_tabs_navigation($active_tab);

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    if ($active_tab === 'general') {
        render_general_settings_section($general_form);
    } elseif ($active_tab === 'booking') {
        render_booking_settings_section($booking_form);
    } elseif ($active_tab === 'pages') {
        render_pages_settings_section($pages_form);
    } elseif ($active_tab === 'design') {
        render_design_settings_section($design_form);
    } elseif ($active_tab === 'diagnostics') {
        render_diagnostics_settings_section();
    }

    echo '</div>';
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_settings_save_request_early', 1);
