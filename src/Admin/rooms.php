<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;

/**
 * Get available room categories.
 *
 * @return array<string, string>
 */
function get_room_categories(): array
{
    return RoomCatalog::getCategories();
}

/**
 * Normalize room category slug.
 */
function normalize_room_category(string $category): string
{
    return RoomCatalog::normalizeCategory($category);
}

/**
 * Convert a room category slug to a display label.
 */
function get_room_category_label(string $category): string
{
    return RoomCatalog::getCategoryLabel($category);
}

/**
 * Build admin rooms page URL.
 *
 * @param array<string, scalar> $args
 */
function get_admin_rooms_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-rooms');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

/**
 * Get available amenity options for rooms.
 *
 * @return array<string, array<string, string>>
 */
function get_available_room_amenities(): array
{
    return RoomCatalog::getAvailableAmenities();
}

/**
 * Normalize an amenity value to a valid amenity key.
 */
function normalize_room_amenity_key(string $value): string
{
    return RoomCatalog::normalizeAmenityKey($value);
}

/**
 * Parse selected amenity values into valid amenity keys.
 *
 * @param mixed $raw_values
 * @return array<int, string>
 */
function parse_room_amenity_keys($raw_values): array
{
    $parts = [];

    if (\is_array($raw_values)) {
        $parts = $raw_values;
    } elseif (\is_string($raw_values)) {
        $split = \preg_split('/[\r\n,]+/', $raw_values);

        if (\is_array($split)) {
            $parts = $split;
        }
    }

    if (empty($parts)) {
        return [];
    }

    $keys = [];

    foreach ($parts as $part) {
        $candidate = normalize_room_amenity_key((string) \wp_unslash($part));

        if ($candidate === '') {
            continue;
        }

        $keys[$candidate] = $candidate;
    }

    return \array_values($keys);
}

/**
 * Parse gallery IDs string into unique attachment IDs.
 *
 * @return array<int, int>
 */
function parse_room_gallery_ids(string $raw_gallery_ids): array
{
    $parts = \array_filter(\array_map('trim', \explode(',', $raw_gallery_ids)));
    $ids = [];

    foreach ($parts as $part) {
        $id = \absint($part);

        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return \array_values($ids);
}

/**
 * Parse main image ID input.
 */
function parse_room_main_image_id(string $raw_image_id): int
{
    return \absint(\trim($raw_image_id));
}

/**
 * Check if a room slug exists.
 */
function does_room_slug_exist(string $slug, int $exclude_room_id = 0): bool
{
    return \MustHotelBooking\Engine\get_room_repository()->roomSlugExists($slug, $exclude_room_id);
}

/**
 * Generate unique room slug.
 */
function generate_unique_room_slug(string $slug, int $exclude_room_id = 0): string
{
    $base_slug = \sanitize_title($slug);

    if ($base_slug === '') {
        $base_slug = 'room';
    }

    if (!does_room_slug_exist($base_slug, $exclude_room_id)) {
        return $base_slug;
    }

    $index = 2;

    while (true) {
        $candidate = $base_slug . '-' . $index;

        if (!does_room_slug_exist($candidate, $exclude_room_id)) {
            return $candidate;
        }

        $index++;
    }
}

/**
 * Sanitize room form values.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_room_form_values(array $source): array
{
    $room_id = isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0;
    $name = isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '';
    $slug_input = isset($source['slug']) ? \sanitize_title((string) \wp_unslash($source['slug'])) : '';
    $category = isset($source['category']) ? normalize_room_category((string) \wp_unslash($source['category'])) : 'standard-rooms';
    $description = isset($source['description']) ? \sanitize_textarea_field((string) \wp_unslash($source['description'])) : '';
    $max_guests = isset($source['max_guests']) ? \max(1, \absint(\wp_unslash($source['max_guests']))) : 1;
    $base_price = isset($source['base_price']) ? (float) \wp_unslash($source['base_price']) : 0.0;
    $room_size = isset($source['room_size']) ? \sanitize_text_field((string) \wp_unslash($source['room_size'])) : '';
    $beds = isset($source['beds']) ? \sanitize_text_field((string) \wp_unslash($source['beds'])) : '';
    $room_rules = isset($source['room_rules']) ? \sanitize_textarea_field((string) \wp_unslash($source['room_rules'])) : '';
    $amenities_intro = isset($source['amenities_intro']) ? \sanitize_textarea_field((string) \wp_unslash($source['amenities_intro'])) : '';
    $amenity_keys_input = isset($source['amenity_keys']) ? $source['amenity_keys'] : [];
    $main_image_input = isset($source['main_image_id']) ? (string) \wp_unslash($source['main_image_id']) : '';
    $gallery_input = isset($source['gallery_ids']) ? (string) \wp_unslash($source['gallery_ids']) : '';

    $errors = [];

    if ($name === '') {
        $errors[] = \__('Room name is required.', 'must-hotel-booking');
    }

    if ($base_price < 0) {
        $base_price = 0.0;
    }

    $slug = generate_unique_room_slug($slug_input !== '' ? $slug_input : $name, $room_id);

    return [
        'room_id' => $room_id,
        'name' => $name,
        'slug' => $slug,
        'category' => $category,
        'description' => $description,
        'max_guests' => $max_guests,
        'base_price' => \round($base_price, 2),
        'extra_guest_price' => 0.0,
        'room_size' => $room_size,
        'beds' => $beds,
        'room_rules' => $room_rules,
        'amenities_intro' => $amenities_intro,
        'amenity_keys' => parse_room_amenity_keys($amenity_keys_input),
        'main_image_id' => parse_room_main_image_id($main_image_input),
        'main_image_id_input' => $main_image_input,
        'gallery_ids' => parse_room_gallery_ids($gallery_input),
        'gallery_ids_input' => $gallery_input,
        'errors' => $errors,
    ];
}

/**
 * Save room details to room meta.
 *
 * @param int                $main_image_id
 * @param string             $room_rules
 * @param string             $amenities_intro
 * @param array<int, string> $amenity_keys
 * @param array<int, int>    $gallery_ids
 */
function save_room_meta_data(
    int $room_id,
    int $main_image_id,
    string $room_rules,
    string $amenities_intro,
    array $amenity_keys,
    array $gallery_ids
): void
{
    \MustHotelBooking\Engine\get_room_repository()->saveRoomMeta(
        $room_id,
        $main_image_id,
        $room_rules,
        $amenities_intro,
        $amenity_keys,
        $gallery_ids
    );
}

/**
 * Create room record.
 *
 * @param array<string, mixed> $room_data
 */
function create_room_record(array $room_data): int
{
    return \MustHotelBooking\Engine\get_room_repository()->createRoom(
        [
            'name' => (string) $room_data['name'],
            'slug' => (string) $room_data['slug'],
            'category' => (string) $room_data['category'],
            'description' => (string) $room_data['description'],
            'max_guests' => (int) $room_data['max_guests'],
            'base_price' => (float) $room_data['base_price'],
            'extra_guest_price' => 0.0,
            'room_size' => (string) $room_data['room_size'],
            'beds' => (string) $room_data['beds'],
            'created_at' => \current_time('mysql'),
        ]
    );
}

/**
 * Update room record.
 *
 * @param array<string, mixed> $room_data
 */
function update_room_record(int $room_id, array $room_data): bool
{
    return \MustHotelBooking\Engine\get_room_repository()->updateRoom(
        $room_id,
        [
            'name' => (string) $room_data['name'],
            'slug' => (string) $room_data['slug'],
            'category' => (string) $room_data['category'],
            'description' => (string) $room_data['description'],
            'max_guests' => (int) $room_data['max_guests'],
            'base_price' => (float) $room_data['base_price'],
            'extra_guest_price' => 0.0,
            'room_size' => (string) $room_data['room_size'],
            'beds' => (string) $room_data['beds'],
        ]
    );
}

/**
 * Delete room and all room meta.
 */
function delete_room_record(int $room_id): bool
{
    return \MustHotelBooking\Engine\get_room_repository()->deleteRoom($room_id);
}

/**
 * Get room by ID.
 *
 * @return array<string, mixed>|null
 */
function get_room_record(int $room_id): ?array
{
    return RoomData::getRoom($room_id);
}

/**
 * Get room amenities.
 *
 * @return array<int, string>
 */
function get_room_amenities(int $room_id): array
{
    return RoomData::getRoomAmenities($room_id);
}

/**
 * Get room gallery image IDs.
 *
 * @return array<int, int>
 */
function get_room_gallery_image_ids(int $room_id): array
{
    return RoomData::getRoomGalleryImageIds($room_id);
}

/**
 * Get room main image attachment ID.
 */
function get_room_main_image_id(int $room_id): int
{
    return RoomData::getRoomMainImageId($room_id);
}

/**
 * Get room main image URL.
 */
function get_room_main_image_url(int $room_id, string $size = 'large'): string
{
    return RoomData::getRoomMainImageUrl($room_id, $size);
}

/**
 * Get room meta value by key.
 */
function get_room_meta_text_value(int $room_id, string $meta_key): string
{
    return RoomData::getRoomMetaTextValue($room_id, $meta_key);
}

/**
 * Get room rules text.
 */
function get_room_rules_text(int $room_id): string
{
    return RoomData::getRoomRulesText($room_id);
}

/**
 * Get room amenities intro text.
 */
function get_room_amenities_intro_text(int $room_id): string
{
    return RoomData::getRoomAmenitiesIntroText($room_id);
}

/**
 * Resolve selected room amenities with labels and icon URLs.
 *
 * @return array<int, array<string, string>>
 */
function get_room_amenity_display_items(int $room_id): array
{
    return RoomData::getRoomAmenityDisplayItems($room_id);
}

/**
 * Get room rows for list table.
 *
 * @return array<int, array<string, mixed>>
 */
function get_rooms_list_rows(): array
{
    return RoomData::getRoomsListRows();
}

/**
 * Get rooms by category for frontend/Elementor rendering.
 *
 * @return array<int, array<string, mixed>>
 */
function get_rooms_for_display(string $category = 'all', int $limit = 50): array
{
    return RoomData::getRoomsForDisplay($category, $limit);
}

/**
 * Get room gallery image URLs.
 *
 * @return array<int, string>
 */
function get_room_gallery_image_urls(int $room_id, int $limit = 3, string $size = 'large'): array
{
    return RoomData::getRoomGalleryImageUrls($room_id, $limit, $size);
}

/**
 * Handle delete action requests.
 */
function maybe_handle_room_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete') {
        return;
    }

    $room_id = isset($_GET['room_id']) ? \absint(\wp_unslash($_GET['room_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($room_id <= 0 || !\wp_verify_nonce($nonce, 'must_room_delete_' . $room_id)) {
        \wp_safe_redirect(get_admin_rooms_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    $deleted = delete_room_record($room_id);

    \wp_safe_redirect(get_admin_rooms_page_url(['notice' => $deleted ? 'room_deleted' : 'room_delete_failed']));
    exit;
}

/**
 * Handle save action requests.
 *
 * @return array<string, mixed>
 */
function maybe_handle_room_save_request(): array
{
    static $did_run = false;
    static $result = null;

    if ($did_run && \is_array($result)) {
        return $result;
    }

    $did_run = true;

    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        $result = [
            'errors' => [],
            'form' => null,
        ];

        return $result;
    }

    $action = isset($_POST['must_room_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_room_action'])) : '';

    if ($action !== 'save_room') {
        $result = [
            'errors' => [],
            'form' => null,
        ];

        return $result;
    }

    $nonce = isset($_POST['must_room_nonce']) ? (string) \wp_unslash($_POST['must_room_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_room_save')) {
        $result = [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];

        return $result;
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $room_data = sanitize_room_form_values($raw_post);
    $room_id = (int) $room_data['room_id'];

    if (!empty($room_data['errors'])) {
        $result = [
            'errors' => (array) $room_data['errors'],
            'form' => $room_data,
        ];

        return $result;
    }

    $saved_room_id = 0;

    if ($room_id > 0) {
        $updated = update_room_record($room_id, $room_data);

        if ($updated) {
            $saved_room_id = $room_id;
        }
    } else {
        $saved_room_id = create_room_record($room_data);
    }

    if ($saved_room_id <= 0) {
        $result = [
            'errors' => [\__('Unable to save room. Please check your database schema.', 'must-hotel-booking')],
            'form' => $room_data,
        ];

        return $result;
    }

    save_room_meta_data(
        $saved_room_id,
        (int) $room_data['main_image_id'],
        (string) $room_data['room_rules'],
        (string) $room_data['amenities_intro'],
        (array) $room_data['amenity_keys'],
        (array) $room_data['gallery_ids']
    );

    \wp_safe_redirect(
        get_admin_rooms_page_url(
            [
                'notice' => $room_id > 0 ? 'room_updated' : 'room_created',
                'action' => 'edit',
                'room_id' => $saved_room_id,
            ]
        )
    );
    exit;
}

/**
 * Build room form defaults.
 *
 * @return array<string, mixed>
 */
function get_room_form_defaults(): array
{
    return [
        'room_id' => 0,
        'name' => '',
        'slug' => '',
        'category' => 'standard-rooms',
        'description' => '',
        'max_guests' => 1,
        'base_price' => 0.00,
        'room_size' => '',
        'beds' => '',
        'room_rules' => '',
        'amenities_intro' => '',
        'amenity_keys' => [],
        'main_image_id' => 0,
        'main_image_id_input' => '',
        'gallery_ids' => [],
        'gallery_ids_input' => '',
    ];
}

/**
 * Build room form data for rendering.
 *
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_room_form_data(?array $submitted_form = null): array
{
    $defaults = get_room_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';
    $room_id = isset($_GET['room_id']) ? \absint(\wp_unslash($_GET['room_id'])) : 0;

    if ($action !== 'edit' || $room_id <= 0) {
        return $defaults;
    }

    $room = get_room_record($room_id);

    if (!\is_array($room)) {
        return $defaults;
    }

    $amenities = get_room_amenities($room_id);
    $amenity_keys = [];

    foreach ($amenities as $amenity_value) {
        $key = normalize_room_amenity_key((string) $amenity_value);

        if ($key === '') {
            continue;
        }

        $amenity_keys[$key] = $key;
    }

    $main_image_id = get_room_main_image_id($room_id);
    $gallery_ids = get_room_gallery_image_ids($room_id);
    $room_rules = get_room_rules_text($room_id);
    $amenities_intro = get_room_amenities_intro_text($room_id);

    return [
        'room_id' => $room_id,
        'name' => (string) ($room['name'] ?? ''),
        'slug' => (string) ($room['slug'] ?? ''),
        'category' => normalize_room_category((string) ($room['category'] ?? 'standard-rooms')),
        'description' => (string) ($room['description'] ?? ''),
        'max_guests' => (int) ($room['max_guests'] ?? 1),
        'base_price' => (float) ($room['base_price'] ?? 0),
        'room_size' => (string) ($room['room_size'] ?? ''),
        'beds' => (string) ($room['beds'] ?? ''),
        'room_rules' => $room_rules,
        'amenities_intro' => $amenities_intro,
        'amenity_keys' => \array_values($amenity_keys),
        'main_image_id' => $main_image_id,
        'main_image_id_input' => (string) $main_image_id,
        'gallery_ids' => $gallery_ids,
        'gallery_ids_input' => \implode(',', $gallery_ids),
    ];
}

/**
 * Render admin notice from query args.
 */
function render_rooms_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'room_created' => ['success', \__('Room created successfully.', 'must-hotel-booking')],
        'room_updated' => ['success', \__('Room updated successfully.', 'must-hotel-booking')],
        'room_deleted' => ['success', \__('Room deleted successfully.', 'must-hotel-booking')],
        'room_delete_failed' => ['error', \__('Unable to delete room.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';

    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';
}

/**
 * Render room gallery preview.
 *
 * @param array<int, int> $gallery_ids
 */
function render_room_gallery_preview(array $gallery_ids): void
{
    echo '<div class="must-room-gallery-preview">';

    foreach ($gallery_ids as $image_id) {
        $html = \wp_get_attachment_image($image_id, 'thumbnail', false, ['class' => 'must-room-gallery-thumb']);

        if (\is_string($html) && $html !== '') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    echo '</div>';
}

/**
 * Render room main image preview.
 */
function render_room_main_image_preview(int $image_id): void
{
    echo '<div class="must-room-main-image-preview">';

    if ($image_id > 0) {
        $html = \wp_get_attachment_image($image_id, 'medium', false, ['class' => 'must-room-main-image-thumb']);

        if (\is_string($html) && $html !== '') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    echo '</div>';
}

/**
 * Determine if current admin request targets the Rooms page.
 */
function is_rooms_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-rooms';
}

/**
 * Handle room save/delete actions early in admin lifecycle.
 */
function maybe_handle_rooms_admin_actions_early(): void
{
    if (!is_rooms_admin_request()) {
        return;
    }

    ensure_admin_capability();
    maybe_handle_room_delete_request();
    maybe_handle_room_save_request();
}

/**
 * Render rooms admin page.
 */
function render_admin_rooms_page(): void
{
    ensure_admin_capability();

    $save_state = maybe_handle_room_save_request();
    $errors = isset($save_state['errors']) && \is_array($save_state['errors']) ? $save_state['errors'] : [];
    $submitted_form = isset($save_state['form']) && \is_array($save_state['form']) ? $save_state['form'] : null;

    $form = get_room_form_data($submitted_form);
    $is_edit_mode = (int) $form['room_id'] > 0;
    $rooms = get_rooms_list_rows();
    $main_image_id = isset($form['main_image_id']) ? \absint($form['main_image_id']) : 0;
    $gallery_ids = isset($form['gallery_ids']) && \is_array($form['gallery_ids']) ? $form['gallery_ids'] : [];
    $selected_amenity_keys = isset($form['amenity_keys']) && \is_array($form['amenity_keys']) ? $form['amenity_keys'] : [];
    $selected_amenity_keys = \array_map(
        static function ($value): string {
            return \sanitize_key((string) $value);
        },
        $selected_amenity_keys
    );
    $available_amenities = get_available_room_amenities();

    echo '<div class="wrap must-hotel-booking-rooms-admin">';
    echo '<h1>' . \esc_html__('Accommodations', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Manage the accommodation catalog, guest capacity, base pricing, media, and front-end room content from one screen.', 'must-hotel-booking') . '</p>';

    render_rooms_admin_notice_from_query();

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="must-room-form-card">';
    echo '<h2>' . \esc_html($is_edit_mode ? __('Edit Accommodation', 'must-hotel-booking') : __('Create Accommodation', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_rooms_page_url()) . '">';
    \wp_nonce_field('must_room_save', 'must_room_nonce');

    echo '<input type="hidden" name="must_room_action" value="save_room" />';
    echo '<input type="hidden" name="room_id" value="' . \esc_attr((string) $form['room_id']) . '" />';

    echo '<table class="form-table" role="presentation">';
    echo '<tbody>';

    echo '<tr><th scope="row"><label for="must-room-name">' . \esc_html__('Room Name', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-room-name" type="text" name="name" class="regular-text" value="' . \esc_attr((string) $form['name']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-slug">' . \esc_html__('Slug', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-room-slug" type="text" name="slug" class="regular-text" value="' . \esc_attr((string) $form['slug']) . '" />';
    echo '<p class="description">' . \esc_html__('Leave empty to auto-generate from room name.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-category">' . \esc_html__('Category', 'must-hotel-booking') . '</label></th>';
    echo '<td><select id="must-room-category" name="category">';

    foreach (get_room_categories() as $category_slug => $category_label) {
        echo '<option value="' . \esc_attr($category_slug) . '"' . \selected((string) $form['category'], (string) $category_slug, false) . '>' . \esc_html((string) $category_label) . '</option>';
    }

    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-description">' . \esc_html__('Description', 'must-hotel-booking') . '</label></th>';
    echo '<td><textarea id="must-room-description" name="description" class="large-text" rows="4">' . \esc_textarea((string) $form['description']) . '</textarea></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-max-guests">' . \esc_html__('Max Guests', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-room-max-guests" type="number" min="1" name="max_guests" value="' . \esc_attr((string) $form['max_guests']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-base-price">' . \esc_html__('Base Price', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-room-base-price" type="number" min="0" step="0.01" name="base_price" value="' . \esc_attr((string) $form['base_price']) . '" required /></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-size">' . \esc_html__('Room Size', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-room-size" type="text" name="room_size" class="regular-text" value="' . \esc_attr((string) $form['room_size']) . '" /></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-beds">' . \esc_html__('Beds', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-room-beds" type="text" name="beds" class="regular-text" value="' . \esc_attr((string) $form['beds']) . '" /></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-rules">' . \esc_html__('Room Rules', 'must-hotel-booking') . '</label></th>';
    echo '<td><textarea id="must-room-rules" name="room_rules" class="large-text" rows="5">' . \esc_textarea((string) $form['room_rules']) . '</textarea>';
    echo '<p class="description">' . \esc_html__('Displayed on the single room page under Room Rules.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row"><label for="must-room-amenities-intro">' . \esc_html__('Amenities Text', 'must-hotel-booking') . '</label></th>';
    echo '<td><textarea id="must-room-amenities-intro" name="amenities_intro" class="large-text" rows="4">' . \esc_textarea((string) $form['amenities_intro']) . '</textarea>';
    echo '<p class="description">' . \esc_html__('Short text displayed above the amenities icons.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row">' . \esc_html__('Amenities', 'must-hotel-booking') . '</th><td>';
    echo '<div class="must-room-amenities-selector">';

    foreach ($available_amenities as $amenity_key => $amenity) {
        $amenity_label = isset($amenity['label']) ? (string) $amenity['label'] : $amenity_key;
        $amenity_icon = isset($amenity['icon']) ? (string) $amenity['icon'] : '';
        $icon_url = $amenity_icon !== '' ? MUST_HOTEL_BOOKING_URL . 'assets/img/' . $amenity_icon : '';
        $is_selected = \in_array($amenity_key, $selected_amenity_keys, true);
        $input_id = 'must-room-amenity-' . \sanitize_html_class($amenity_key);

        echo '<label class="must-room-amenity-option" for="' . \esc_attr($input_id) . '">';

        if ($icon_url !== '') {
            echo '<span class="must-room-amenity-icon-wrap"><img class="must-room-amenity-icon" src="' . \esc_url($icon_url) . '" alt="" aria-hidden="true" /></span>';
        }

        echo '<span class="must-room-amenity-label">' . \esc_html($amenity_label) . '</span>';
        echo '<input id="' . \esc_attr($input_id) . '" type="checkbox" name="amenity_keys[]" value="' . \esc_attr($amenity_key) . '"' . \checked($is_selected, true, false) . ' />';
        echo '</label>';
    }

    echo '</div>';
    echo '<p class="description">' . \esc_html__('Select amenities available for this room.', 'must-hotel-booking') . '</p></td></tr>';

    echo '<tr><th scope="row">' . \esc_html__('Main Image', 'must-hotel-booking') . '</th><td>';
    echo '<div class="must-room-main-image-field">';
    echo '<input type="hidden" name="main_image_id" class="must-room-main-image-id" value="' . \esc_attr((string) $main_image_id) . '" />';
    echo '<button type="button" class="button must-room-upload-main-image">' . \esc_html__('Upload Main Image', 'must-hotel-booking') . '</button> ';
    echo '<button type="button" class="button button-link-delete must-room-clear-main-image">' . \esc_html__('Clear Main Image', 'must-hotel-booking') . '</button>';
    render_room_main_image_preview($main_image_id);
    echo '</div>';
    echo '<p class="description">' . \esc_html__('This image is used as the large room card image.', 'must-hotel-booking') . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">' . \esc_html__('Gallery Images', 'must-hotel-booking') . '</th><td>';
    echo '<div class="must-room-gallery-field">';
    echo '<input type="hidden" name="gallery_ids" class="must-room-gallery-ids" value="' . \esc_attr((string) $form['gallery_ids_input']) . '" />';
    echo '<button type="button" class="button must-room-upload-images">' . \esc_html__('Upload Gallery Images', 'must-hotel-booking') . '</button> ';
    echo '<button type="button" class="button button-link-delete must-room-clear-images">' . \esc_html__('Clear Gallery', 'must-hotel-booking') . '</button>';
    render_room_gallery_preview($gallery_ids);
    echo '</div>';
    echo '<p class="description">' . \esc_html__('Use these for the smaller preview images.', 'must-hotel-booking') . '</p>';
    echo '</td></tr>';

    echo '</tbody>';
    echo '</table>';

    \submit_button($is_edit_mode ? __('Update Accommodation', 'must-hotel-booking') : __('Create Accommodation', 'must-hotel-booking'));

    if ($is_edit_mode) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rooms_page_url()) . '">' . \esc_html__('Add New Accommodation', 'must-hotel-booking') . '</a>';
    }

    echo '</form>';
    echo '</div>';

    echo '<hr />';
    echo '<h2>' . \esc_html__('Accommodation List', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Room Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Slug', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Category', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Capacity', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Base Price', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rooms)) {
        echo '<tr><td colspan="6">' . \esc_html__('No rooms found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rooms as $room) {
            $room_id = isset($room['id']) ? (int) $room['id'] : 0;
            $slug = isset($room['slug']) ? (string) $room['slug'] : '';
            $category = isset($room['category']) ? (string) $room['category'] : 'standard-rooms';
            $edit_url = get_admin_rooms_page_url(['action' => 'edit', 'room_id' => $room_id]);
            $delete_url = \wp_nonce_url(
                get_admin_rooms_page_url(['action' => 'delete', 'room_id' => $room_id]),
                'must_room_delete_' . $room_id
            );

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($room['name'] ?? '')) . '</td>';
            echo '<td><code>' . \esc_html($slug) . '</code></td>';
            echo '<td>' . \esc_html(get_room_category_label($category)) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($room['max_guests'] ?? 0))) . '</td>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($room['base_price'] ?? 0), 2)) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . \esc_url($edit_url) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Delete this room?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Enqueue admin assets for room management.
 */
function enqueue_admin_rooms_assets(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if ($page !== 'must-hotel-booking-rooms') {
        return;
    }

    \wp_enqueue_media();

    \wp_enqueue_style(
        'must-hotel-booking-admin-rooms',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-rooms.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_script(
        'must-hotel-booking-admin-rooms',
        MUST_HOTEL_BOOKING_URL . 'assets/js/admin-rooms.js',
        ['jquery'],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_localize_script(
        'must-hotel-booking-admin-rooms',
        'mustHotelBookingRoomsMedia',
        [
            'mainImageFrameTitle' => \__('Select Main Room Image', 'must-hotel-booking'),
            'mainImageButtonText' => \__('Use Main Image', 'must-hotel-booking'),
            'galleryFrameTitle' => \__('Select Gallery Images', 'must-hotel-booking'),
            'galleryButtonText' => \__('Use Selected Images', 'must-hotel-booking'),
        ]
    );
}

\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_rooms_assets');
\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_rooms_admin_actions_early');
