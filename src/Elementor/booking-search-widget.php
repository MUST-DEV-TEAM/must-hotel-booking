<?php

namespace MustHotelBooking\Elementor;

use MustHotelBooking\Core\MustBookingConfig;

/**
 * Register Elementor widget styles.
 */
function register_elementor_booking_search_widget_styles(): void
{
    \wp_register_style(
        'must-hotel-booking-flatpickr',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
        [],
        '4.6.13'
    );

    \wp_register_style(
        'must-hotel-booking-booking-search-widget',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-search-widget.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );
}

/**
 * Register Elementor widget scripts.
 */
function register_elementor_booking_search_widget_scripts(): void
{
    $booking_window_days = 365;
    $max_booking_guests = 5;

    if (\class_exists(MustBookingConfig::class)) {
        $booking_window_days = \max(1, MustBookingConfig::get_booking_window());
        $max_booking_guests = \max(1, MustBookingConfig::get_max_booking_guests());
    }

    $today_date = \current_time('Y-m-d');
    $today_obj = new \DateTimeImmutable($today_date);
    $max_date = $today_obj->modify('+' . $booking_window_days . ' day')->format('Y-m-d');

    \wp_register_script(
        'must-hotel-booking-flatpickr',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js',
        [],
        '4.6.13',
        true
    );

    \wp_register_script(
        'must-hotel-booking-booking-search-widget',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-search-widget.js',
        ['must-hotel-booking-flatpickr'],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_localize_script(
        'must-hotel-booking-booking-search-widget',
        'mustHotelBookingWidgetConfig',
        [
            'today' => $today_date,
            'maxDate' => $max_date,
            'bookingWindowDays' => $booking_window_days,
            'maxGuests' => $max_booking_guests,
            'displayDateFormat' => 'd/m/Y',
            'queryDateFormat' => 'Y-m-d',
        ]
    );
}

/**
 * Get booking page URL for the widget redirect.
 */
function get_booking_page_url_for_widget(): string
{
    $default_url = \home_url('/booking');

    if (!\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
        return $default_url;
    }

    $settings = get_plugin_settings();
    $booking_page_id = isset($settings['page_booking_id']) ? (int) $settings['page_booking_id'] : 0;

    if ($booking_page_id <= 0) {
        return $default_url;
    }

    $permalink = \get_permalink($booking_page_id);

    return \is_string($permalink) && $permalink !== '' ? $permalink : $default_url;
}

/**
 * Resolve the current Elementor document post ID for widget controls.
 */
function get_elementor_document_post_id_for_booking_search_widget(): int
{
    $candidate_ids = [];

    foreach (['post', 'post_id', 'editor_post_id', 'initial_document_id', 'preview_id'] as $request_key) {
        if (!isset($_REQUEST[$request_key])) {
            continue;
        }

        $raw_candidate = \wp_unslash($_REQUEST[$request_key]);

        if (!\is_scalar($raw_candidate)) {
            continue;
        }

        $candidate_id = \absint((string) $raw_candidate);

        if ($candidate_id > 0) {
            $candidate_ids[] = $candidate_id;
        }
    }

    $queried_object_id = \get_queried_object_id();

    if ($queried_object_id > 0) {
        $candidate_ids[] = $queried_object_id;
    }

    $current_post_id = \get_the_ID();

    if ($current_post_id > 0) {
        $candidate_ids[] = $current_post_id;
    }

    global $post;

    if ($post instanceof \WP_Post) {
        $candidate_ids[] = (int) $post->ID;
    }

    foreach ($candidate_ids as $candidate_id) {
        if ($candidate_id > 0) {
            return $candidate_id;
        }
    }

    return 0;
}

/**
 * Get the current Elementor document instance for widget controls.
 *
 * @return object|null
 */
function get_elementor_document_for_booking_search_widget()
{
    if (!\class_exists('\Elementor\Plugin')) {
        return null;
    }

    $plugin = \Elementor\Plugin::$instance ?? null;
    $documents = (\is_object($plugin) && isset($plugin->documents)) ? $plugin->documents : null;

    if (!\is_object($documents)) {
        return null;
    }

    $post_id = get_elementor_document_post_id_for_booking_search_widget();

    if ($post_id > 0 && \method_exists($documents, 'get')) {
        $document = $documents->get($post_id);

        if (\is_object($document)) {
            return $document;
        }
    }

    if (\method_exists($documents, 'get_current')) {
        $current_document = $documents->get_current();

        if (\is_object($current_document)) {
            return $current_document;
        }
    }

    return null;
}

/**
 * Get Elementor element data for the current page.
 *
 * @return array<int, array<string, mixed>>
 */
function get_elementor_elements_data_for_booking_search_widget(): array
{
    $document = get_elementor_document_for_booking_search_widget();

    if (\is_object($document) && \method_exists($document, 'get_elements_data')) {
        $elements_data = $document->get_elements_data();

        if (\is_array($elements_data)) {
            return $elements_data;
        }
    }

    $post_id = get_elementor_document_post_id_for_booking_search_widget();

    if ($post_id <= 0) {
        return [];
    }

    $stored_data = \get_post_meta($post_id, '_elementor_data', true);

    if (\is_array($stored_data)) {
        return $stored_data;
    }

    if (!\is_string($stored_data) || $stored_data === '') {
        return [];
    }

    $decoded_data = \json_decode($stored_data, true);

    return \is_array($decoded_data) ? $decoded_data : [];
}

/**
 * Collect Rooms List widgets from Elementor element data.
 *
 * @param array<int, array<string, mixed>> $elements
 * @return array<int, array<string, mixed>>
 */
function collect_rooms_list_widgets_for_booking_search(array $elements): array
{
    $rooms_list_widgets = [];

    foreach ($elements as $element) {
        if (!\is_array($element)) {
            continue;
        }

        $widget_type = isset($element['widgetType']) ? (string) $element['widgetType'] : '';

        if ($widget_type === 'must_hotel_booking_rooms_list') {
            $rooms_list_widgets[] = $element;
        }

        $child_elements = isset($element['elements']) && \is_array($element['elements'])
            ? $element['elements']
            : [];

        if (!empty($child_elements)) {
            $rooms_list_widgets = \array_merge(
                $rooms_list_widgets,
                collect_rooms_list_widgets_for_booking_search($child_elements)
            );
        }
    }

    return $rooms_list_widgets;
}

/**
 * Build Rooms List select options for the Booking Search widget.
 *
 * @return array<string, string>
 */
function get_rooms_list_widget_options_for_booking_search(): array
{
    $options = [
        '' => \__('Not Connected', 'must-hotel-booking'),
    ];

    $rooms_list_widgets = collect_rooms_list_widgets_for_booking_search(
        get_elementor_elements_data_for_booking_search_widget()
    );

    if (empty($rooms_list_widgets)) {
        return $options;
    }

    $index = 1;

    foreach ($rooms_list_widgets as $rooms_list_widget) {
        $widget_id = isset($rooms_list_widget['id'])
            ? \sanitize_key((string) $rooms_list_widget['id'])
            : '';

        if ($widget_id === '') {
            continue;
        }

        $settings = isset($rooms_list_widget['settings']) && \is_array($rooms_list_widget['settings'])
            ? $rooms_list_widget['settings']
            : [];
        $selected_category = isset($settings['room_category'])
            ? \sanitize_key((string) $settings['room_category'])
            : 'all';
        $category_label = $selected_category !== '' && $selected_category !== 'all' && \function_exists(__NAMESPACE__ . '\get_room_category_label')
            ? get_room_category_label($selected_category)
            : \__('All Categories', 'must-hotel-booking');

        $options[$widget_id] = \sprintf(
            /* translators: 1: widget index on the page, 2: room category label. */
            \__('Rooms List %1$d (%2$s)', 'must-hotel-booking'),
            $index,
            $category_label
        );

        $index++;
    }

    return $options;
}

/**
 * Register Elementor booking search widget.
 *
 * @param mixed $widgets_manager Elementor widgets manager instance.
 */
function register_elementor_booking_search_widget($widgets_manager): void
{
    static $is_registered = false;

    if ($is_registered) {
        return;
    }

    if (!\class_exists('\Elementor\Widget_Base') || !\is_object($widgets_manager)) {
        return;
    }

    if (!\class_exists(__NAMESPACE__ . '\Booking_Search_Widget')) {
        return;
    }

    $widget_instance = new Booking_Search_Widget();

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
 * Register Elementor widget for legacy Elementor hooks.
 */
function register_elementor_booking_search_widget_legacy(): void
{
    if (!\class_exists('\Elementor\Plugin')) {
        return;
    }

    $plugin_instance = \Elementor\Plugin::$instance ?? null;
    $widgets_manager = (\is_object($plugin_instance) && isset($plugin_instance->widgets_manager))
        ? $plugin_instance->widgets_manager
        : null;

    if (!\is_object($widgets_manager)) {
        return;
    }

    register_elementor_booking_search_widget($widgets_manager);
}

/**
 * Register custom Elementor widget category.
 *
 * @param mixed $elements_manager Elementor elements manager instance.
 */
function register_elementor_booking_widget_category($elements_manager): void
{
    if (!\is_object($elements_manager) || !\method_exists($elements_manager, 'add_category')) {
        return;
    }

    $elements_manager->add_category(
        'must-hotel-booking',
        [
            'title' => \esc_html__('MUST Hotel Booking', 'must-hotel-booking'),
            'icon' => 'fa fa-calendar',
        ]
    );
}

\add_action('elementor/frontend/after_register_styles', __NAMESPACE__ . '\register_elementor_booking_search_widget_styles');
\add_action('elementor/frontend/after_register_scripts', __NAMESPACE__ . '\register_elementor_booking_search_widget_scripts');
\add_action('elementor/elements/categories_registered', __NAMESPACE__ . '\register_elementor_booking_widget_category');
\add_action('elementor/widgets/register', __NAMESPACE__ . '\register_elementor_booking_search_widget');
\add_action('elementor/widgets/widgets_registered', __NAMESPACE__ . '\register_elementor_booking_search_widget_legacy');
