<?php

namespace MustHotelBooking\Frontend;

use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;
use MustHotelBooking\Database\RoomRepository;

/**
 * Check if current frontend request is the managed Rooms page.
 */
function is_frontend_rooms_page(): bool
{
    return get_current_single_room_host_page_id() > 0;
}

/**
 * Check whether Elementor data contains a supported single-room host widget.
 *
 * @param array<int, mixed> $elements
 */
function elementor_elements_support_single_room_host(array $elements): bool
{
    foreach ($elements as $element) {
        if (!\is_array($element)) {
            continue;
        }

        $widget_type = isset($element['widgetType']) ? (string) $element['widgetType'] : '';

        if ($widget_type === 'must_hotel_booking_rooms_list' || $widget_type === 'must_hotel_booking_rooms_text_grid') {
            return true;
        }

        $child_elements = isset($element['elements']) && \is_array($element['elements'])
            ? $element['elements']
            : [];

        if (!empty($child_elements) && elementor_elements_support_single_room_host($child_elements)) {
            return true;
        }
    }

    return false;
}

/**
 * Determine whether a page can act as a single-room host.
 */
function page_supports_single_room_host(int $page_id): bool
{
    static $support_cache = [];

    if ($page_id <= 0) {
        return false;
    }

    if (isset($support_cache[$page_id])) {
        return $support_cache[$page_id];
    }

    $assigned_rooms_page_id = ManagedPages::getAssignedPageId('page_rooms_id');

    if ($assigned_rooms_page_id > 0 && $assigned_rooms_page_id === $page_id) {
        $support_cache[$page_id] = true;

        return true;
    }

    $stored_data = \get_post_meta($page_id, '_elementor_data', true);
    $elements = [];

    if (\is_array($stored_data)) {
        $elements = $stored_data;
    } elseif (\is_string($stored_data) && $stored_data !== '') {
        $decoded_data = \json_decode($stored_data, true);

        if (\is_array($decoded_data)) {
            $elements = $decoded_data;
        }
    }

    $support_cache[$page_id] = elementor_elements_support_single_room_host($elements);

    return $support_cache[$page_id];
}

/**
 * Resolve the current page ID that should host single-room requests.
 */
function get_current_single_room_host_page_id(): int
{
    if (\is_admin()) {
        return 0;
    }

    $assigned_rooms_page_id = ManagedPages::getAssignedPageId('page_rooms_id');

    if ($assigned_rooms_page_id > 0 && \is_page($assigned_rooms_page_id)) {
        return $assigned_rooms_page_id;
    }

    if (!\is_singular('page')) {
        return 0;
    }

    $page_id = \get_queried_object_id();

    if ($page_id <= 0) {
        $page_id = \get_the_ID();
    }

    if ($page_id <= 0) {
        return 0;
    }

    return page_supports_single_room_host($page_id) ? $page_id : 0;
}

/**
 * Resolve the preferred host page URL for single-room links.
 */
function get_preferred_single_room_host_page_url(): string
{
    $rooms_page_url = ManagedPages::getRoomsPageUrl();

    if ($rooms_page_url !== '') {
        return $rooms_page_url;
    }

    $host_page_id = get_current_single_room_host_page_id();

    if ($host_page_id <= 0) {
        return '';
    }

    $permalink = \get_permalink($host_page_id);

    return \is_string($permalink) ? $permalink : '';
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
 * Resolve requested physical inventory room id from query.
 */
function get_requested_single_physical_room_id(): int
{
    if (isset($_GET['inventory_room_id'])) {
        return \absint(\wp_unslash($_GET['inventory_room_id']));
    }

    return isset($_GET['physical_room_id']) ? \absint(\wp_unslash($_GET['physical_room_id'])) : 0;
}

/**
 * Determine whether current request is for a single room details page.
 */
function is_single_room_request(): bool
{
    return get_requested_single_room_slug() !== '' || get_requested_single_room_id() > 0 || get_requested_single_physical_room_id() > 0;
}

/**
 * Load room row by slug.
 *
 * @return array<string, mixed>|null
 */
function get_room_record_by_slug(string $room_slug): ?array
{
    return RoomData::getRoomBySlug($room_slug);
}

/**
 * Resolve room record from current request.
 *
 * @return array<string, mixed>|null
 */
function get_single_room_record_from_request(): ?array
{
    $physical_room_id = get_requested_single_physical_room_id();
    $room_slug = get_requested_single_room_slug();
    $room_id = get_requested_single_room_id();

    if ($physical_room_id > 0) {
        $physical_room = RoomData::getPhysicalRoomDisplayById($physical_room_id);

        if (\is_array($physical_room)) {
            return $physical_room;
        }
    }

    if ($room_slug !== '') {
        return get_room_record_by_slug($room_slug);
    }

    if ($room_id > 0) {
        return RoomData::getRoom($room_id);
    }

    return null;
}

/**
 * Build single room details URL.
 */
function get_single_room_url(string $slug, int $physical_room_id = 0): string
{
    $slug = \sanitize_title($slug);
    $roomsPageUrl = get_preferred_single_room_host_page_url();

    if ($slug === '' || $roomsPageUrl === '') {
        return '';
    }

    $args = ['room' => $slug];

    if ($physical_room_id > 0) {
        $args['inventory_room_id'] = $physical_room_id;
    }

    return \add_query_arg($args, $roomsPageUrl);
}

/**
 * Build inquiry URL for room.
 */
function get_single_room_inquiry_url(array $room): string
{
    return \home_url('/contact/');
}

/**
 * Get related rooms from same category.
 *
 * @return array<int, array<string, mixed>>
 */
function get_single_room_related_rooms(int $current_room_id, string $category, int $limit = 3): array
{
    if ($current_room_id <= 0 || $category === '') {
        return [];
    }

    $limit = \max(1, \min(6, $limit));
    $booking_page_url = ManagedPages::getBookingPageUrl();
    $rows = (new RoomRepository())->getRandomRoomsByType($category, $current_room_id, $limit);

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

        $main_image_url = RoomData::getRoomMainImageUrl($room_id, 'large');
        $gallery_urls = RoomData::getRoomGalleryImageUrls($room_id, 10, 'large');
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
 * Build a room card row for the similar rooms section.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function build_single_room_similar_room_card(array $row): ?array
{
    $room_id = isset($row['id']) ? (int) $row['id'] : 0;
    $physical_room_id = isset($row['physical_room_id']) ? (int) $row['physical_room_id'] : 0;
    $gallery_room_id = isset($row['gallery_room_id']) ? (int) $row['gallery_room_id'] : $room_id;
    $booking_room_id = isset($row['booking_room_id']) ? (int) $row['booking_room_id'] : $room_id;
    $room_slug = isset($row['details_slug']) ? (string) $row['details_slug'] : (isset($row['slug']) ? (string) $row['slug'] : '');

    if ($room_id <= 0 || $gallery_room_id <= 0 || $room_slug === '') {
        return null;
    }

    $main_image_url = RoomData::getRoomMainImageUrl($gallery_room_id, 'large');
    $gallery_urls = RoomData::getRoomGalleryImageUrls($gallery_room_id, 10, 'large');
    $provider_image_urls = RoomData::getProviderImageUrls($gallery_room_id, $physical_room_id);
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

    foreach ($provider_image_urls as $provider_image_url) {
        $provider_image_url = (string) $provider_image_url;

        if ($provider_image_url !== '') {
            $images[] = $provider_image_url;
        }
    }

    $images = \array_values(\array_unique($images));
    $booking_args = ['room_id' => $booking_room_id];

    if ($physical_room_id > 0) {
        $booking_args = [
            'room_id' => $gallery_room_id,
            'inventory_room_id' => $physical_room_id,
        ];
    }

    return [
        'id' => $room_id,
        'name' => isset($row['name']) ? (string) $row['name'] : '',
        'slug' => $room_slug,
        'description' => isset($row['description']) ? (string) $row['description'] : '',
        'max_guests' => isset($row['max_guests']) ? (int) $row['max_guests'] : 1,
        'room_size' => isset($row['room_size']) ? (string) $row['room_size'] : '',
        'permalink' => get_single_room_url($room_slug, $physical_room_id),
        'booking_url' => \add_query_arg($booking_args, ManagedPages::getBookingPageUrl()),
        'images' => $images,
        'cover_image' => !empty($images) ? (string) $images[0] : '',
    ];
}

/**
 * Get similar rooms, preferring random physical rooms from the same room type.
 *
 * @return array<int, array<string, mixed>>
 */
function get_single_room_similar_rooms(int $current_physical_room_id, int $room_type_id, string $category, int $current_room_id, int $limit = 3): array
{
    $limit = \max(1, \min(6, $limit));
    $similar_rooms = [];
    $seen_ids = [];

    if ($room_type_id > 0) {
        $inventory = \MustHotelBooking\Engine\get_inventory_repository();

        if ($inventory->inventoryRoomsTableExists()) {
            $same_type_rows = $inventory->getRoomsByType($room_type_id);
            \shuffle($same_type_rows);

            foreach ($same_type_rows as $same_type_row) {
                if (!\is_array($same_type_row)) {
                    continue;
                }

                $physical_room_id = isset($same_type_row['id']) ? (int) $same_type_row['id'] : 0;

                if ($physical_room_id <= 0 || $physical_room_id === $current_physical_room_id || isset($seen_ids[$physical_room_id])) {
                    continue;
                }

                $display_row = RoomData::getPhysicalRoomDisplayById($physical_room_id);
                $card = \is_array($display_row) ? build_single_room_similar_room_card($display_row) : null;

                if (\is_array($card)) {
                    $seen_ids[$physical_room_id] = true;
                    $similar_rooms[] = $card;
                }

                if (\count($similar_rooms) >= $limit) {
                    return $similar_rooms;
                }
            }
        }
    }

    $all_rooms = RoomData::getRoomsForDisplay('all', 200);
    \shuffle($all_rooms);

    foreach ($all_rooms as $candidate_row) {
        if (!\is_array($candidate_row)) {
            continue;
        }

        $physical_room_id = isset($candidate_row['physical_room_id']) ? (int) $candidate_row['physical_room_id'] : 0;
        $candidate_id = $physical_room_id > 0 ? $physical_room_id : (int) ($candidate_row['id'] ?? 0);

        if ($candidate_id <= 0 || $candidate_id === $current_physical_room_id || isset($seen_ids[$candidate_id])) {
            continue;
        }

        $card = build_single_room_similar_room_card($candidate_row);

        if (\is_array($card)) {
            $seen_ids[$candidate_id] = true;
            $similar_rooms[] = $card;
        }

        if (\count($similar_rooms) >= $limit) {
            return $similar_rooms;
        }
    }

    if (empty($similar_rooms) && $current_physical_room_id <= 0 && $current_room_id > 0 && $category !== '') {
        return get_single_room_related_rooms($current_room_id, $category, $limit);
    }

    return $similar_rooms;
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
            'booking_url' => ManagedPages::getBookingPageUrl(),
            'rooms_url' => get_preferred_single_room_host_page_url(),
            'terms_url' => \home_url('/terms-conditions/'),
        ];
    }

    $room_id = (int) $room['id'];
    $gallery_room_id = isset($room['gallery_room_id']) ? (int) $room['gallery_room_id'] : $room_id;
    $room_type_id = isset($room['room_type_id']) ? (int) $room['room_type_id'] : $gallery_room_id;
    $physical_room_id = isset($room['physical_room_id']) ? (int) $room['physical_room_id'] : 0;
    $room_slug = isset($room['slug']) ? (string) $room['slug'] : '';
    $room_category = isset($room['category']) ? (string) $room['category'] : '';
    $main_image_url = RoomData::getRoomMainImageUrl($gallery_room_id, 'large');
    $gallery_urls = RoomData::getRoomGalleryImageUrls($gallery_room_id, 12, 'large');
    $provider_image_urls = RoomData::getProviderImageUrls($gallery_room_id, $physical_room_id);

    if ($main_image_url === '' && !empty($gallery_urls)) {
        $main_image_url = (string) $gallery_urls[0];
        $gallery_urls = \array_slice($gallery_urls, 1);
    }

    if ($main_image_url === '' && !empty($provider_image_urls)) {
        $main_image_url = (string) \array_shift($provider_image_urls);
        $gallery_urls = $provider_image_urls;
    } elseif (!empty($provider_image_urls)) {
        $gallery_urls = \array_values(\array_unique(\array_merge($gallery_urls, $provider_image_urls)));
    }

    $booking_args = ['room_id' => $gallery_room_id];

    if ($physical_room_id > 0) {
        $booking_args['inventory_room_id'] = $physical_room_id;
    }

    $booking_url = \add_query_arg($booking_args, ManagedPages::getBookingPageUrl());
    $related_rooms = get_single_room_similar_rooms($physical_room_id, $room_type_id, $room_category, $gallery_room_id, 3);
    $amenities = RoomData::getRoomAmenityDisplayItems($gallery_room_id);

    if (empty($amenities)) {
        $amenities = RoomData::getProviderFeatureDisplayItems($gallery_room_id, $physical_room_id);
    }

    return [
        'success' => true,
        'message' => '',
        'room' => $room,
        'room_id' => $room_id,
        'gallery_room_id' => $gallery_room_id,
        'room_type_id' => $room_type_id,
        'physical_room_id' => $physical_room_id,
        'room_slug' => $room_slug,
        'room_title' => isset($room['name']) ? (string) $room['name'] : '',
        'description' => isset($room['description']) ? (string) $room['description'] : '',
        'max_guests' => isset($room['max_guests']) ? (int) $room['max_guests'] : 1,
        'base_price' => isset($room['base_price']) ? (float) $room['base_price'] : 0.0,
        'currency' => \class_exists(\MustHotelBooking\Core\MustBookingConfig::class)
            ? \MustHotelBooking\Core\MustBookingConfig::get_currency()
            : 'USD',
        'room_size' => isset($room['room_size']) ? (string) $room['room_size'] : '',
        'room_rules' => RoomData::getRoomRulesText($gallery_room_id),
        'amenities_intro' => RoomData::getRoomAmenitiesIntroText($gallery_room_id),
        'amenities' => $amenities,
        'main_image_url' => $main_image_url,
        'gallery_urls' => $gallery_urls,
        'related_rooms' => $related_rooms,
        'category_label' => RoomCatalog::getCategoryLabel($room_category),
        'booking_url' => $booking_url,
        'inquiry_url' => get_single_room_inquiry_url($room),
        'rooms_url' => get_preferred_single_room_host_page_url(),
        'terms_url' => \home_url('/terms-conditions/'),
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
        'permalink' => get_single_room_url($room_slug, $physical_room_id),
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
        [],
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
