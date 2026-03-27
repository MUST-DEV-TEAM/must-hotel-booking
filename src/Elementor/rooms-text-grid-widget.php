<?php

namespace MustHotelBooking\Elementor;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;

/**
 * Register Elementor rooms text grid widget styles.
 */
function register_elementor_rooms_text_grid_widget_styles(): void
{
    \wp_register_style(
        'must-hotel-booking-rooms-text-grid-widget',
        MUST_HOTEL_BOOKING_URL . 'assets/css/rooms-text-grid-widget.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );
}

/**
 * Build room selector options for the widget editor.
 *
 * @return array<string, string>
 */
function get_rooms_text_grid_room_options(): array
{
    $options = [
        '' => \__('Select a room', 'must-hotel-booking'),
    ];

    foreach (RoomData::getRoomSelectorRows(false, false) as $room) {
        if (!\is_array($room)) {
            continue;
        }

        $room_id = isset($room['id']) ? (int) $room['id'] : 0;
        $room_name = isset($room['name']) ? \trim((string) $room['name']) : '';

        if ($room_id <= 0 || $room_name === '') {
            continue;
        }

        $category_label = get_rooms_text_grid_room_category_label(
            isset($room['category']) ? (string) $room['category'] : ''
        );

        $options[(string) $room_id] = $category_label !== ''
            ? \sprintf(
                /* translators: 1: room name, 2: room category label. */
                \__('%1$s (%2$s)', 'must-hotel-booking'),
                $room_name,
                $category_label
            )
            : $room_name;
    }

    return $options;
}

/**
 * Resolve a human-readable room category label.
 */
function get_rooms_text_grid_room_category_label(string $category): string
{
    $category = \sanitize_key($category);
    $categories = RoomCatalog::getCategories();

    if (isset($categories[$category])) {
        return (string) $categories[$category];
    }

    if ($category === '') {
        return '';
    }

    return \ucwords(\str_replace('-', ' ', $category));
}

/**
 * Normalize selected-room repeater rows.
 *
 * @param array<int, mixed> $selected_rooms
 * @return array<int, array<string, mixed>>
 */
function normalize_rooms_text_grid_selected_rooms(array $selected_rooms): array
{
    $normalized = [];
    $seen_room_ids = [];

    foreach ($selected_rooms as $selected_room) {
        if (!\is_array($selected_room)) {
            continue;
        }

        $room_id = isset($selected_room['room_id']) ? \absint($selected_room['room_id']) : 0;

        if ($room_id <= 0 || isset($seen_room_ids[$room_id])) {
            continue;
        }

        $custom_link = isset($selected_room['custom_link']) && \is_array($selected_room['custom_link'])
            ? $selected_room['custom_link']
            : [];

        $normalized[] = [
            'room_id' => $room_id,
            'custom_link' => [
                'url' => \esc_url_raw((string) ($custom_link['url'] ?? '')),
                'is_external' => !empty($custom_link['is_external']),
                'nofollow' => !empty($custom_link['nofollow']),
            ],
        ];
        $seen_room_ids[$room_id] = true;
    }

    return $normalized;
}

/**
 * Check whether a room record should render in the public text grid.
 *
 * @param array<string, mixed> $room
 */
function is_rooms_text_grid_room_visible(array $room): bool
{
    return !isset($room['is_active']) || !empty($room['is_active']);
}

/**
 * Fetch room rows for widget rendering.
 *
 * @param array<int, mixed> $selected_rooms
 * @return array<int, array<string, mixed>>
 */
function get_rooms_for_text_grid_widget_render(string $source_mode, array $selected_rooms, int $limit): array
{
    $limit = $limit > 0 ? \max(1, \min(200, $limit)) : 0;

    if ($source_mode === 'selected_rooms') {
        $normalized_selection = normalize_rooms_text_grid_selected_rooms($selected_rooms);

        if (empty($normalized_selection)) {
            return [];
        }

        $room_ids = \array_map(
            static function (array $selected_room): int {
                return (int) ($selected_room['room_id'] ?? 0);
            },
            $normalized_selection
        );
        $rooms = RoomData::getRoomsByIds($room_ids);
        $rooms_by_id = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $room_id = isset($room['id']) ? (int) $room['id'] : 0;

            if ($room_id > 0) {
                $rooms_by_id[$room_id] = $room;
            }
        }

        $ordered_rooms = [];

        foreach ($normalized_selection as $selected_room) {
            $room_id = (int) ($selected_room['room_id'] ?? 0);

            if ($room_id <= 0 || !isset($rooms_by_id[$room_id])) {
                continue;
            }

            $room = $rooms_by_id[$room_id];

            if (!\is_array($room) || !is_rooms_text_grid_room_visible($room)) {
                continue;
            }

            $room['custom_link'] = isset($selected_room['custom_link']) && \is_array($selected_room['custom_link'])
                ? $selected_room['custom_link']
                : [];
            $ordered_rooms[] = $room;

            if ($limit > 0 && \count($ordered_rooms) >= $limit) {
                break;
            }
        }

        return $ordered_rooms;
    }

    $rooms = RoomData::getRoomsForTextGrid($limit);

    foreach ($rooms as $index => $room) {
        if (!\is_array($room)) {
            unset($rooms[$index]);
            continue;
        }

        $room['custom_link'] = [];
        $rooms[$index] = $room;
    }

    return \array_values($rooms);
}

/**
 * Build the single-room URL for a room row.
 *
 * @param array<string, mixed> $room
 */
function get_rooms_text_grid_room_url(array $room): string
{
    $slug = isset($room['slug']) ? \sanitize_title((string) $room['slug']) : '';

    if ($slug === '') {
        return '';
    }

    return \add_query_arg(
        ['room' => $slug],
        ManagedPages::getRoomsPageUrl()
    );
}

/**
 * Resolve the final link URL for a room item.
 *
 * @param array<string, mixed> $room
 */
function get_rooms_text_grid_item_link_url(array $room, string $link_behavior): string
{
    if ($link_behavior === 'no_link') {
        return '';
    }

    if ($link_behavior === 'custom_override_or_single_room_page') {
        $custom_link = isset($room['custom_link']) && \is_array($room['custom_link'])
            ? $room['custom_link']
            : [];
        $custom_url = \esc_url_raw((string) ($custom_link['url'] ?? ''));

        if ($custom_url !== '') {
            return $custom_url;
        }
    }

    return get_rooms_text_grid_room_url($room);
}

/**
 * Build widget-level design defaults from plugin branding settings.
 */
function get_rooms_text_grid_wrapper_inline_styles(): string
{
    $styles = [];
    $border_radius = \max(0, \min(40, (int) MustBookingConfig::get_setting('border_radius', 18)));

    $styles[] = '--must-hotel-booking-rooms-text-grid-radius:' . $border_radius . 'px';

    if (!MustBookingConfig::get_setting('inherit_elementor_colors', false)) {
        $text_color = \sanitize_hex_color((string) MustBookingConfig::get_setting('text_color', '#16212b')) ?: '#16212b';
        $primary_color = \sanitize_hex_color((string) MustBookingConfig::get_setting('primary_color', '#0f766e')) ?: '#0f766e';

        $styles[] = '--must-hotel-booking-rooms-text-grid-text-color:' . $text_color;
        $styles[] = '--must-hotel-booking-rooms-text-grid-hover-color:' . $primary_color;
        $styles[] = '--must-hotel-booking-rooms-text-grid-current-color:' . $primary_color;
    }

    if (!MustBookingConfig::get_setting('inherit_elementor_typography', false)) {
        $font_family = \trim((string) MustBookingConfig::get_setting('font_family', 'Instrument Sans'));

        if ($font_family !== '') {
            $styles[] = '--must-hotel-booking-rooms-text-grid-font-family:' . \sanitize_text_field($font_family);
        }
    }

    return \implode(';', $styles);
}

/**
 * Get the current room context for active-item highlighting.
 *
 * @return array{room_id: int, room_slug: string}
 */
function get_rooms_text_grid_current_room_context(): array
{
    static $context = null;

    if (\is_array($context)) {
        return $context;
    }

    if (!ManagedPages::isCurrentPage('page_rooms_id', 'rooms')) {
        $context = [
            'room_id' => 0,
            'room_slug' => '',
        ];

        return $context;
    }

    $room_id = \function_exists('\MustHotelBooking\Frontend\get_requested_single_room_id')
        ? (int) \MustHotelBooking\Frontend\get_requested_single_room_id()
        : 0;
    $room_slug = \function_exists('\MustHotelBooking\Frontend\get_requested_single_room_slug')
        ? (string) \MustHotelBooking\Frontend\get_requested_single_room_slug()
        : '';

    $context = [
        'room_id' => $room_id,
        'room_slug' => \sanitize_title($room_slug),
    ];

    return $context;
}

/**
 * Determine whether a room item matches the currently viewed room.
 *
 * @param array<string, mixed> $room
 */
function is_rooms_text_grid_current_room(array $room): bool
{
    $context = get_rooms_text_grid_current_room_context();
    $room_id = isset($room['id']) ? (int) $room['id'] : 0;
    $room_slug = isset($room['slug']) ? \sanitize_title((string) $room['slug']) : '';

    if ($context['room_id'] > 0 && $room_id > 0 && $context['room_id'] === $room_id) {
        return true;
    }

    return $context['room_slug'] !== '' && $room_slug !== '' && $context['room_slug'] === $room_slug;
}

/**
 * Register Elementor rooms text grid widget.
 *
 * @param mixed $widgets_manager Elementor widgets manager instance.
 */
function register_elementor_rooms_text_grid_widget($widgets_manager): void
{
    static $is_registered = false;

    if ($is_registered) {
        return;
    }

    if (!\class_exists('\Elementor\Widget_Base') || !\is_object($widgets_manager)) {
        return;
    }

    if (!\class_exists(__NAMESPACE__ . '\Rooms_Text_Grid_Widget')) {
        return;
    }

    $widget_instance = new Rooms_Text_Grid_Widget();

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
 * Register Elementor rooms text grid widget for legacy Elementor hooks.
 */
function register_elementor_rooms_text_grid_widget_legacy(): void
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

    register_elementor_rooms_text_grid_widget($widgets_manager);
}

\add_action('elementor/frontend/after_register_styles', __NAMESPACE__ . '\register_elementor_rooms_text_grid_widget_styles');
\add_action('elementor/widgets/register', __NAMESPACE__ . '\register_elementor_rooms_text_grid_widget');
\add_action('elementor/widgets/widgets_registered', __NAMESPACE__ . '\register_elementor_rooms_text_grid_widget_legacy');
