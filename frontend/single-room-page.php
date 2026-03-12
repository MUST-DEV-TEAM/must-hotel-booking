<?php

namespace must_hotel_booking;

/**
 * Check if current frontend request is the managed Rooms page.
 */
function is_frontend_rooms_page(): bool
{
    if (\is_admin()) {
        return false;
    }

    $settings = get_plugin_settings();
    $rooms_page_id = isset($settings['page_rooms_id']) ? (int) $settings['page_rooms_id'] : 0;

    if ($rooms_page_id > 0 && \is_page($rooms_page_id)) {
        return true;
    }

    return \is_page('rooms');
}

/**
 * Resolve requested room slug from query.
 */
function get_requested_single_room_slug(): string
{
    $slug = isset($_GET['room']) ? (string) \wp_unslash($_GET['room']) : '';

    return \sanitize_title($slug);
}

/**
 * Resolve requested room id from query.
 */
function get_requested_single_room_id(): int
{
    return isset($_GET['room_id']) ? \absint(\wp_unslash($_GET['room_id'])) : 0;
}

/**
 * Determine whether current request is for a single room details page.
 */
function is_single_room_request(): bool
{
    return get_requested_single_room_slug() !== '' || get_requested_single_room_id() > 0;
}

/**
 * Load room row by slug.
 *
 * @return array<string, mixed>|null
 */
function get_room_record_by_slug(string $room_slug): ?array
{
    global $wpdb;

    $room_slug = \sanitize_title($room_slug);

    if ($room_slug === '') {
        return null;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT id, name, slug, category, description, max_guests, base_price, extra_guest_price, room_size, beds
            FROM ' . get_rooms_table_name() . '
            WHERE slug = %s
            LIMIT 1',
            $room_slug
        ),
        ARRAY_A
    );

    return \is_array($row) ? $row : null;
}

/**
 * Resolve room record from current request.
 *
 * @return array<string, mixed>|null
 */
function get_single_room_record_from_request(): ?array
{
    $room_slug = get_requested_single_room_slug();
    $room_id = get_requested_single_room_id();

    if ($room_slug !== '') {
        return get_room_record_by_slug($room_slug);
    }

    if ($room_id > 0) {
        return get_room_record($room_id);
    }

    return null;
}

/**
 * Build single room details URL.
 */
function get_single_room_url(string $slug): string
{
    $rooms_url = get_frontend_page_url('page_rooms_id', '/rooms');

    return \add_query_arg(['room' => \sanitize_title($slug)], $rooms_url);
}

/**
 * Build inquiry URL for room.
 */
function get_single_room_inquiry_url(array $room): string
{
    $room_name = isset($room['name']) ? (string) $room['name'] : \__('Room Inquiry', 'must-hotel-booking');
    $subject = \sprintf(
        /* translators: %s is room name. */
        \__('Inquiry about %s', 'must-hotel-booking'),
        $room_name
    );

    return 'mailto:' . \antispambot((string) \get_option('admin_email')) . '?subject=' . \rawurlencode($subject);
}

/**
 * Get related rooms from same category.
 *
 * @return array<int, array<string, mixed>>
 */
function get_single_room_related_rooms(int $current_room_id, string $category, int $limit = 3): array
{
    global $wpdb;

    if ($current_room_id <= 0 || $category === '') {
        return [];
    }

    $limit = \max(1, \min(6, $limit));
    $booking_page_url = get_booking_page_url();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, slug, category, description, max_guests, room_size
            FROM " . get_rooms_table_name() . "
            WHERE category = %s AND id <> %d
            ORDER BY RAND()
            LIMIT %d",
            $category,
            $current_room_id,
            $limit
        ),
        ARRAY_A
    );

    if (!\is_array($rows)) {
        return [];
    }

    $related_rooms = [];

    foreach ($rows as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $room_id = isset($row['id']) ? (int) $row['id'] : 0;
        $room_slug = isset($row['slug']) ? (string) $row['slug'] : '';

        if ($room_id <= 0 || $room_slug === '') {
            continue;
        }

        $main_image_url = get_room_main_image_url($room_id, 'large');
        $gallery_urls = get_room_gallery_image_urls($room_id, 10, 'large');
        $images = [];

        if ($main_image_url !== '') {
            $images[] = $main_image_url;
        }

        foreach ($gallery_urls as $gallery_url) {
            $gallery_url = (string) $gallery_url;

            if ($gallery_url !== '') {
                $images[] = $gallery_url;
            }
        }

        $images = \array_values(\array_unique($images));

        $related_rooms[] = [
            'id' => $room_id,
            'name' => isset($row['name']) ? (string) $row['name'] : '',
            'slug' => $room_slug,
            'description' => isset($row['description']) ? (string) $row['description'] : '',
            'max_guests' => isset($row['max_guests']) ? (int) $row['max_guests'] : 1,
            'room_size' => isset($row['room_size']) ? (string) $row['room_size'] : '',
            'permalink' => get_single_room_url($room_slug),
            'booking_url' => \add_query_arg(
                ['room_id' => $room_id],
                $booking_page_url
            ),
            'images' => $images,
            'cover_image' => !empty($images) ? (string) $images[0] : '',
        ];
    }

    return $related_rooms;
}

/**
 * Build single room page view data.
 *
 * @return array<string, mixed>
 */
function get_single_room_page_view_data(): array
{
    $room = get_single_room_record_from_request();

    if (!\is_array($room) || empty($room['id'])) {
        return [
            'success' => false,
            'message' => \__('Room was not found.', 'must-hotel-booking'),
            'room' => null,
            'booking_url' => get_booking_page_url(),
            'rooms_url' => get_frontend_page_url('page_rooms_id', '/rooms'),
            'terms_url' => \home_url('/terms-and-conditions'),
        ];
    }

    $room_id = (int) $room['id'];
    $room_slug = isset($room['slug']) ? (string) $room['slug'] : '';
    $room_category = isset($room['category']) ? (string) $room['category'] : '';
    $main_image_url = get_room_main_image_url($room_id, 'large');
    $gallery_urls = get_room_gallery_image_urls($room_id, 12, 'large');

    if ($main_image_url === '' && !empty($gallery_urls)) {
        $main_image_url = (string) $gallery_urls[0];
        $gallery_urls = \array_slice($gallery_urls, 1);
    }

    $booking_url = \add_query_arg(
        ['room_id' => $room_id],
        get_booking_page_url()
    );
    $related_rooms = get_single_room_related_rooms($room_id, $room_category, 3);

    return [
        'success' => true,
        'message' => '',
        'room' => $room,
        'room_id' => $room_id,
        'room_slug' => $room_slug,
        'room_title' => isset($room['name']) ? (string) $room['name'] : '',
        'description' => isset($room['description']) ? (string) $room['description'] : '',
        'max_guests' => isset($room['max_guests']) ? (int) $room['max_guests'] : 1,
        'room_size' => isset($room['room_size']) ? (string) $room['room_size'] : '',
        'room_rules' => get_room_rules_text($room_id),
        'amenities_intro' => get_room_amenities_intro_text($room_id),
        'amenities' => get_room_amenity_display_items($room_id),
        'main_image_url' => $main_image_url,
        'gallery_urls' => $gallery_urls,
        'related_rooms' => $related_rooms,
        'category_label' => \function_exists(__NAMESPACE__ . '\\get_room_category_label')
            ? get_room_category_label($room_category)
            : $room_category,
        'booking_url' => $booking_url,
        'inquiry_url' => get_single_room_inquiry_url($room),
        'rooms_url' => get_frontend_page_url('page_rooms_id', '/rooms'),
        'terms_url' => \home_url('/terms-and-conditions'),
        'people_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/PeopleFill.svg',
        'surface_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/Surface.svg',
        'arrow_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowRight.svg',
        'bed_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/bed.svg',
        'included_accommodations_title' => \__('Included for all', 'must-hotel-booking'),
        'included_accommodations_kicker' => 'ACCOMODATIONS',
        'included_accommodations' => [
            [
                'label' => \__('Breakfast', 'must-hotel-booking'),
                'icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/breakfast.svg',
            ],
            [
                'label' => \__('Access to the beach', 'must-hotel-booking'),
                'icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/beach.svg',
            ],
            [
                'label' => \__('Access to the pool', 'must-hotel-booking'),
                'icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/pool.svg',
            ],
            [
                'label' => \__('Beach towels', 'must-hotel-booking'),
                'icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/towel.svg',
            ],
            [
                'label' => \__('Parking', 'must-hotel-booking'),
                'icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/parking.svg',
            ],
        ],
        'permalink' => get_single_room_url($room_slug),
    ];
}

/**
 * Override Rooms template with single-room template when room is requested.
 */
function maybe_load_single_room_template(string $template): string
{
    if (!is_frontend_rooms_page() || !is_single_room_request()) {
        return $template;
    }

    $single_template = MUST_HOTEL_BOOKING_PATH . 'frontend/templates/single-room.php';

    if (\file_exists($single_template)) {
        return $single_template;
    }

    return $template;
}

/**
 * Enqueue assets for single-room template.
 */
function enqueue_single_room_page_assets(): void
{
    if (!is_frontend_rooms_page() || !is_single_room_request()) {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-single-room-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/single-room-page.css',
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_script(
        'must-hotel-booking-single-room-page',
        MUST_HOTEL_BOOKING_URL . 'assets/js/single-room-page.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );
}

\add_filter('template_include', __NAMESPACE__ . '\maybe_load_single_room_template', 100);
\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_single_room_page_assets');
