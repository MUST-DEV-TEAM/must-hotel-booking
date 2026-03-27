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
    $category = isset($source['category']) ? normalize_room_category((string) \wp_unslash($source['category'])) : RoomCatalog::getDefaultCategory();
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
 * @param array<int, int> $galleryIds
 */
function render_room_gallery_preview(array $galleryIds): void
{
    echo '<div class="must-room-gallery-preview">';

    foreach ($galleryIds as $imageId) {
        $html = \wp_get_attachment_image($imageId, 'thumbnail', false, ['class' => 'must-room-gallery-thumb']);

        if (\is_string($html) && $html !== '') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    echo '</div>';
}

function render_room_main_image_preview(int $imageId): void
{
    echo '<div class="must-room-main-image-preview">';

    if ($imageId > 0) {
        $html = \wp_get_attachment_image($imageId, 'medium', false, ['class' => 'must-room-main-image-thumb']);

        if (\is_string($html) && $html !== '') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    echo '</div>';
}

function is_rooms_admin_request(): bool
{
    $page = isset($_REQUEST['page']) ? \sanitize_key((string) \wp_unslash($_REQUEST['page'])) : '';

    return $page === 'must-hotel-booking-rooms';
}

/**
 * @return array<string, mixed>
 */
function get_rooms_admin_save_state(): array
{
    global $mustHotelBookingRoomsAdminSaveState;

    if (isset($mustHotelBookingRoomsAdminSaveState) && \is_array($mustHotelBookingRoomsAdminSaveState)) {
        return $mustHotelBookingRoomsAdminSaveState;
    }

    $mustHotelBookingRoomsAdminSaveState = [
        'category_errors' => [],
        'category_form' => null,
        'type_errors' => [],
        'type_form' => null,
        'unit_errors' => [],
        'unit_form' => null,
    ];

    return $mustHotelBookingRoomsAdminSaveState;
}

/**
 * @param array<string, mixed> $state
 */
function set_rooms_admin_save_state(array $state): void
{
    global $mustHotelBookingRoomsAdminSaveState;
    $mustHotelBookingRoomsAdminSaveState = $state;
}

function maybe_handle_rooms_admin_actions_early(): void
{
    if (!is_rooms_admin_request()) {
        return;
    }

    ensure_admin_capability();
    $query = AccommodationAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($requestMethod === 'POST') {
        set_rooms_admin_save_state((new AccommodationAdminActions())->handleSaveRequest($query));
        return;
    }

    (new AccommodationAdminActions())->handleGetAction($query);
}

/**
 * @return array<string, mixed>
 */
function maybe_handle_rooms_admin_save_request(AccommodationAdminQuery $query): array
{
    unset($query);

    return get_rooms_admin_save_state();
}

function render_rooms_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'category_created' => ['success', \__('Accommodation category created successfully.', 'must-hotel-booking')],
        'category_updated' => ['success', \__('Accommodation category updated successfully.', 'must-hotel-booking')],
        'category_deleted' => ['success', \__('Accommodation category deleted successfully.', 'must-hotel-booking')],
        'category_delete_blocked' => ['error', \__('This category is still assigned to one or more room listings. Reassign those listings before deleting the category.', 'must-hotel-booking')],
        'category_delete_failed' => ['error', \__('Unable to delete the accommodation category.', 'must-hotel-booking')],
        'category_missing' => ['error', \__('Accommodation category not found.', 'must-hotel-booking')],
        'room_created' => ['success', \__('Room listing created successfully.', 'must-hotel-booking')],
        'room_updated' => ['success', \__('Room listing updated successfully.', 'must-hotel-booking')],
        'room_activated' => ['success', \__('Room listing activated.', 'must-hotel-booking')],
        'room_deactivated' => ['success', \__('Room listing deactivated.', 'must-hotel-booking')],
        'room_duplicated' => ['success', \__('Room listing duplicated as a new inactive draft.', 'must-hotel-booking')],
        'room_deleted' => ['success', \__('Room listing deleted cleanly. No linked units, pricing, availability, or reservation blockers remained.', 'must-hotel-booking')],
        'room_duplicate_failed' => ['error', \__('Unable to duplicate the room listing.', 'must-hotel-booking')],
        'room_delete_blocked' => ['error', \__('This room listing still has dependent units, pricing, availability, rate-plan, or reservation data. Clear those blockers before deleting it.', 'must-hotel-booking')],
        'room_delete_failed' => ['error', \__('Unable to delete the room listing cleanly.', 'must-hotel-booking')],
        'room_update_failed' => ['error', \__('Unable to update the room listing state.', 'must-hotel-booking')],
        'room_missing' => ['error', \__('Room listing not found.', 'must-hotel-booking')],
        'unit_created' => ['success', \__('Accommodation unit created successfully.', 'must-hotel-booking')],
        'unit_updated' => ['success', \__('Accommodation unit updated successfully.', 'must-hotel-booking')],
        'unit_activated' => ['success', \__('Accommodation unit activated.', 'must-hotel-booking')],
        'unit_deactivated' => ['success', \__('Accommodation unit deactivated.', 'must-hotel-booking')],
        'unit_deleted' => ['success', \__('Accommodation unit deleted.', 'must-hotel-booking')],
        'unit_delete_failed' => ['error', \__('Unable to delete the accommodation unit.', 'must-hotel-booking')],
        'unit_update_failed' => ['error', \__('Unable to update the accommodation unit state.', 'must-hotel-booking')],
        'unit_has_reservations' => ['error', \__('This unit has reservation history and cannot be deleted. Deactivate it instead.', 'must-hotel-booking')],
        'unit_missing' => ['error', \__('Accommodation unit not found.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
        'workbook_export_failed' => ['error', \__('Unable to generate the accommodation workbook export.', 'must-hotel-booking')],
        'workbook_template_failed' => ['error', \__('Unable to generate the accommodation workbook template.', 'must-hotel-booking')],
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
 * @param array<int, string> $warnings
 */
function render_accommodation_admin_warnings(array $warnings): void
{
    if (empty($warnings)) {
        return;
    }

    echo '<div class="notice notice-warning"><ul>';

    foreach ($warnings as $warning) {
        echo '<li>' . \esc_html((string) $warning) . '</li>';
    }

    echo '</ul></div>';
}

/**
 * @param array<int, string> $errors
 */
function render_accommodation_admin_errors(array $errors): void
{
    if (empty($errors)) {
        return;
    }

    echo '<div class="notice notice-error"><ul>';

    foreach ($errors as $error) {
        echo '<li>' . \esc_html((string) $error) . '</li>';
    }

    echo '</ul></div>';
}

/**
 * @param array<int, array<string, string>> $cards
 */
function render_rooms_summary_cards(array $cards): void
{
    echo '<section class="must-accommodation-summary-grid">';

    foreach ($cards as $card) {
        echo '<article class="must-accommodation-summary-card">';
        echo '<span class="must-accommodation-summary-label">' . \esc_html((string) ($card['label'] ?? '')) . '</span>';
        echo '<strong class="must-accommodation-summary-value">' . \esc_html((string) ($card['value'] ?? '0')) . '</strong>';
        echo '<span class="must-accommodation-summary-meta">' . \esc_html((string) ($card['meta'] ?? '')) . '</span>';
        echo '</article>';
    }

    echo '</section>';
}

function render_accommodation_import_export_panel(AccommodationAdminQuery $query): void
{
    $baseArgs = ['tab' => $query->getTab()];
    $sheetName = AccommodationWorkbookSchema::getAccommodationSheetName();
    $categoryLabels = \implode(', ', \array_values(RoomCatalog::getCategories()));
    $exportUrl = \wp_nonce_url(
        get_admin_rooms_page_url($baseArgs + ['action' => AccommodationImportExportService::ACTION_EXPORT_WORKBOOK]),
        AccommodationImportExportService::ACTION_EXPORT_WORKBOOK
    );
    $templateUrl = \wp_nonce_url(
        get_admin_rooms_page_url($baseArgs + ['action' => AccommodationImportExportService::ACTION_DOWNLOAD_TEMPLATE]),
        AccommodationImportExportService::ACTION_DOWNLOAD_TEMPLATE
    );

    echo '<section class="must-accommodation-panel must-accommodation-import-tools-panel">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html__('Excel Import & Export', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Download a manager-friendly room-listing workbook, start from a clean template, or import room detail changes with row-level validation.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<a class="button button-secondary" href="' . \esc_url($exportUrl) . '">' . \esc_html__('Download Current Data', 'must-hotel-booking') . '</a>';
    echo '<a class="button button-secondary" href="' . \esc_url($templateUrl) . '">' . \esc_html__('Download Template', 'must-hotel-booking') . '</a>';
    echo '</div></div>';
    echo '<div class="must-accommodation-import-tools-grid">';
    echo '<div class="must-accommodation-import-tools-copy">';
    echo '<span class="must-accommodation-kicker">' . \esc_html__('Workbook Structure', 'must-hotel-booking') . '</span>';
    echo '<h3>' . \esc_html__('One editable sheet for room/listing details.', 'must-hotel-booking') . '</h3>';
    echo '<p>' . \esc_html__('Excel is limited to bulk room/listing create and edit work. Categories, physical units, operational inventory, and live availability stay managed inside WordPress admin.', 'must-hotel-booking') . '</p>';
    echo '<ul class="must-accommodation-import-facts">';
    echo '<li>' . \esc_html(\sprintf(__('Edit only the %s sheet. Each row is one sellable room/listing record.', 'must-hotel-booking'), $sheetName)) . '</li>';
    echo '<li>' . \esc_html__('Use the id column to update existing room listings. Leave id empty to create a new room listing.', 'must-hotel-booking') . '</li>';
    echo '<li>' . \esc_html(\sprintf(__('Use accommodation_category values that match the existing admin-managed categories: %s.', 'must-hotel-booking'), $categoryLabels)) . '</li>';
    echo '<li>' . \esc_html__('Slug is handled automatically. New rows generate a slug from the title, and existing rows keep their current slug.', 'must-hotel-booking') . '</li>';
    echo '<li>' . \esc_html__('Images are not imported from Excel. Add or update room images later in WordPress admin.', 'must-hotel-booking') . '</li>';
    echo '<li>' . \esc_html__('Excel no longer manages accommodation units, unit status, inventory locks, or live availability state.', 'must-hotel-booking') . '</li>';
    echo '<li>' . \esc_html__('Only .xlsx is supported for import in this release.', 'must-hotel-booking') . '</li>';
    echo '</ul>';
    echo '</div>';
    echo '<form method="post" enctype="multipart/form-data" class="must-accommodation-import-form">';
    \wp_nonce_field('must_accommodation_import_workbook', 'must_accommodation_import_nonce');
    echo '<input type="hidden" name="must_accommodation_action" value="' . \esc_attr(AccommodationImportExportService::ACTION_IMPORT_WORKBOOK) . '" />';
    echo '<input type="hidden" name="tab" value="' . \esc_attr($query->getTab()) . '" />';
    echo '<label><span>' . \esc_html__('Workbook File', 'must-hotel-booking') . '</span><input type="file" name="accommodation_workbook" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required /></label>';
    echo '<p class="description">' . \esc_html(\sprintf(__('Import processes the %1$s sheet only. Existing room listings are updated by id, blank ids create new listings, invalid accommodation_category values fail at the row level, and units/inventory data are ignored by this workflow.', 'must-hotel-booking'), $sheetName)) . '</p>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Import Room Listing Workbook', 'must-hotel-booking') . '</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed>|null $report
 */
function render_accommodation_import_report(?array $report): void
{
    if (!\is_array($report)) {
        return;
    }

    $status = isset($report['status']) ? \sanitize_key((string) $report['status']) : 'success';
    $errors = isset($report['errors']) && \is_array($report['errors']) ? $report['errors'] : [];
    $fileName = isset($report['file_name']) ? (string) $report['file_name'] : '';
    $importedAt = isset($report['imported_at']) ? (string) $report['imported_at'] : '';
    $statusLabel = $status === 'error'
        ? \__('Import Failed', 'must-hotel-booking')
        : ($status === 'warning' ? \__('Import Completed With Issues', 'must-hotel-booking') : \__('Import Completed', 'must-hotel-booking'));

    echo '<section class="must-accommodation-import-report is-' . \esc_attr($status) . '">';
    echo '<div class="must-accommodation-import-report-head">';
    echo '<div><span class="must-accommodation-kicker">' . \esc_html__('Workbook Import', 'must-hotel-booking') . '</span><h2>' . \esc_html($statusLabel) . '</h2>';

    if ($fileName !== '' || $importedAt !== '') {
        $metaParts = [];

        if ($fileName !== '') {
            $metaParts[] = \sprintf(\__('File: %s', 'must-hotel-booking'), $fileName);
        }

        if ($importedAt !== '') {
            $metaParts[] = \sprintf(\__('Run: %s', 'must-hotel-booking'), $importedAt);
        }

        echo '<p>' . \esc_html(\implode(' | ', $metaParts)) . '</p>';
    }

    echo '</div>';
    echo '<span class="must-accommodation-badge is-' . \esc_attr($status === 'warning' ? 'warning' : ($status === 'error' ? 'danger' : 'success')) . '">' . \esc_html($statusLabel) . '</span>';
    echo '</div>';

    $summaryCards = [
        ['label' => \__('Accommodations Created', 'must-hotel-booking'), 'value' => (string) ((int) ($report['accommodations_created'] ?? 0))],
        ['label' => \__('Accommodations Updated', 'must-hotel-booking'), 'value' => (string) ((int) ($report['accommodations_updated'] ?? 0))],
        ['label' => \__('Rows Skipped', 'must-hotel-booking'), 'value' => (string) ((int) ($report['rows_skipped'] ?? 0))],
        ['label' => \__('Rows Failed', 'must-hotel-booking'), 'value' => (string) ((int) ($report['rows_failed'] ?? 0))],
    ];

    echo '<div class="must-accommodation-import-summary-grid">';

    foreach ($summaryCards as $card) {
        echo '<article class="must-accommodation-import-summary-card">';
        echo '<span>' . \esc_html((string) $card['label']) . '</span>';
        echo '<strong>' . \esc_html((string) $card['value']) . '</strong>';
        echo '</article>';
    }

    echo '</div>';

    if (!empty($errors)) {
        echo '<div class="must-accommodation-import-errors">';
        echo '<h3>' . \esc_html__('Failed Rows', 'must-hotel-booking') . '</h3>';
        echo '<div class="must-accommodation-import-errors-table-wrap"><table class="must-accommodation-import-errors-table"><thead><tr><th>' . \esc_html__('Sheet', 'must-hotel-booking') . '</th><th>' . \esc_html__('Row', 'must-hotel-booking') . '</th><th>' . \esc_html__('Field', 'must-hotel-booking') . '</th><th>' . \esc_html__('Reason', 'must-hotel-booking') . '</th></tr></thead><tbody>';

        foreach ($errors as $error) {
            if (!\is_array($error)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($error['sheet'] ?? '-')) . '</td>';
            echo '<td>' . \esc_html((string) ((int) ($error['row'] ?? 0) > 0 ? (int) ($error['row'] ?? 0) : '-')) . '</td>';
            echo '<td>' . \esc_html((string) ($error['field'] ?? '-')) . '</td>';
            echo '<td>' . \esc_html((string) ($error['message'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div></div>';
    }

    echo '</section>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_accommodation_category_list(array $pageData): void
{
    $rows = isset($pageData['category_rows']) && \is_array($pageData['category_rows']) ? $pageData['category_rows'] : [];

    echo '<section class="must-accommodation-panel must-accommodation-list-panel">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html__('Accommodation Categories', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Manage the top-level groupings that room listings are assigned to. Categories are admin-managed and are no longer created from Excel.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<a class="button button-primary must-open-accommodation-editor" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'categories'])) . '" data-editor-url="' . \esc_url(get_admin_rooms_page_url(['tab' => 'categories'])) . '" data-editor-modal="category">' . \esc_html__('Create Category', 'must-hotel-booking') . '</a>';
    echo '</div></div>';
    echo '<div class="must-accommodation-record-list">';

    if (empty($rows)) {
        echo '<div class="must-accommodation-empty-table">' . \esc_html__('No accommodation categories exist yet.', 'must-hotel-booking') . '</div>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            echo '<article class="must-accommodation-record-card">';
            echo '<div class="must-accommodation-record-head">';
            echo '<div class="must-accommodation-record-main">';
            echo '<div class="must-accommodation-record-title-row">';
            echo '<h3 class="must-accommodation-record-title"><a class="must-open-accommodation-editor" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-url="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-modal="category">' . \esc_html((string) ($row['name'] ?? '')) . '</a></h3>';
            echo '<span class="must-accommodation-record-code">' . \esc_html((string) ($row['slug'] ?? '')) . '</span>';
            echo '</div>';
            echo '<div class="must-accommodation-row-meta">';
            echo '<span>' . \esc_html(\sprintf(__('Sort %d', 'must-hotel-booking'), (int) ($row['sort_order'] ?? 0))) . '</span>';
            echo '<span>' . \esc_html(\sprintf(_n('%d room listing', '%d room listings', (int) ($row['room_count'] ?? 0), 'must-hotel-booking'), (int) ($row['room_count'] ?? 0))) . '</span>';
            echo '</div>';

            if ((string) ($row['description'] ?? '') !== '') {
                echo '<p class="must-accommodation-record-description">' . \esc_html((string) $row['description']) . '</p>';
            }

            echo '</div>';
            echo '<div class="must-accommodation-record-state">';
            render_accommodation_badge(__('Category', 'must-hotel-booking'), 'info');
            render_accommodation_badge(\sprintf(_n('%d active listing', '%d active listings', (int) ($row['active_room_count'] ?? 0), 'must-hotel-booking'), (int) ($row['active_room_count'] ?? 0)), (int) ($row['active_room_count'] ?? 0) > 0 ? 'success' : 'muted');
            render_accommodation_badge(\sprintf(_n('%d bookable listing', '%d bookable listings', (int) ($row['bookable_room_count'] ?? 0), 'must-hotel-booking'), (int) ($row['bookable_room_count'] ?? 0)), (int) ($row['bookable_room_count'] ?? 0) > 0 ? 'neutral' : 'muted');
            echo '</div>';
            echo '</div>';

            echo '<div class="must-accommodation-record-metrics">';
            render_accommodation_metric_card(__('Assigned Listings', 'must-hotel-booking'), (string) ((int) ($row['room_count'] ?? 0)), __('Sellable room records currently using this category', 'must-hotel-booking'), 'neutral');
            render_accommodation_metric_card(__('Active Listings', 'must-hotel-booking'), (string) ((int) ($row['active_room_count'] ?? 0)), __('Listings currently active in admin', 'must-hotel-booking'), (int) ($row['active_room_count'] ?? 0) > 0 ? 'success' : 'muted');
            render_accommodation_metric_card(__('Bookable Listings', 'must-hotel-booking'), (string) ((int) ($row['bookable_room_count'] ?? 0)), __('Listings that can currently be booked', 'must-hotel-booking'), (int) ($row['bookable_room_count'] ?? 0) > 0 ? 'info' : 'muted');
            echo '</div>';

            echo '<div class="must-accommodation-record-foot">';
            echo '<div class="must-accommodation-primary-actions">';
            echo '<a class="button button-primary must-open-accommodation-editor" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-url="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-modal="category">' . \esc_html__('Edit Category', 'must-hotel-booking') . '</a>';
            echo '</div>';
            echo '<div class="must-accommodation-secondary-actions">';

            if (!empty($row['delete_blocked'])) {
                echo '<span>' . \esc_html__('Reassign room listings before deleting', 'must-hotel-booking') . '</span>';
            } else {
                echo '<a class="is-danger" href="' . \esc_url((string) ($row['delete_url'] ?? '')) . '" onclick="return confirm(\'' . \esc_js(__('Delete this accommodation category? This cannot be undone.', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
            }

            echo '</div>';
            echo '</div>';
            echo '</article>';
        }
    }

    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $form
 */
function render_accommodation_category_form(array $form, string $closeUrl = ''): void
{
    $isEditing = isset($form['category_id']) && (int) $form['category_id'] > 0;
    $closeHref = $closeUrl !== '' ? $closeUrl : get_admin_rooms_page_url(['tab' => 'categories']);
    $slugPreview = (string) ($form['slug'] ?? '');

    echo '<section class="must-accommodation-panel must-accommodation-editor-card">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html($isEditing ? __('Edit Accommodation Category', 'must-hotel-booking') : __('Create Accommodation Category', 'must-hotel-booking')) . '</h2><p>' . \esc_html__('Categories are top-level groupings such as Standard Rooms, Deluxe, or Suites. Room listings are assigned to one category, but pricing and booking stay on the listing records.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<a class="button button-secondary must-open-accommodation-editor" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'categories'])) . '" data-editor-url="' . \esc_url(get_admin_rooms_page_url(['tab' => 'categories'])) . '" data-editor-modal="category">' . \esc_html__('New Blank Category', 'must-hotel-booking') . '</a>';
    echo '</div></div>';
    echo '<div class="must-accommodation-editor-layout">';
    echo '<form method="post" class="must-accommodation-editor-form">';
    \wp_nonce_field('must_accommodation_save_category', 'must_accommodation_category_nonce');
    echo '<input type="hidden" name="must_accommodation_action" value="save_category" />';
    echo '<input type="hidden" name="category_id" value="' . \esc_attr((string) ((int) ($form['category_id'] ?? 0))) . '" />';

    echo '<section class="must-accommodation-form-section">';
    echo '<div class="must-accommodation-section-head"><h3>' . \esc_html__('Category Details', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Use categories for high-level grouping only. Do not model sellable room records at this level.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-field-grid is-two-col">';
    echo '<label><span>' . \esc_html__('Category Name', 'must-hotel-booking') . '</span><input type="text" name="name" value="' . \esc_attr((string) ($form['name'] ?? '')) . '" required /></label>';
    echo '<label><span>' . \esc_html__('Sort Order', 'must-hotel-booking') . '</span><input type="number" name="sort_order" value="' . \esc_attr((string) ((int) ($form['sort_order'] ?? 0))) . '" step="1" /></label>';
    echo '<label class="must-accommodation-field-span-2"><span>' . \esc_html__('Category Slug', 'must-hotel-booking') . '</span><input type="text" value="' . \esc_attr($slugPreview !== '' ? $slugPreview : __('Auto-generated from the category name when saved', 'must-hotel-booking')) . '" readonly /></label>';
    echo '<label class="must-accommodation-field-span-2"><span>' . \esc_html__('Description', 'must-hotel-booking') . '</span><textarea name="description" rows="4">' . \esc_textarea((string) ($form['description'] ?? '')) . '</textarea></label>';
    echo '</div>';
    echo '</section>';

    echo '<div class="must-accommodation-form-actions">';
    echo '<button type="submit" class="button button-primary button-large">' . \esc_html($isEditing ? __('Save Category', 'must-hotel-booking') : __('Create Category', 'must-hotel-booking')) . '</button>';
    echo '<a class="button button-secondary must-close-accommodation-editor" href="' . \esc_url($closeHref) . '">' . \esc_html__('Cancel', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</form>';

    render_accommodation_editor_sidebar(
        __('Category Summary', 'must-hotel-booking'),
        [
            'usage' => $isEditing
                ? __('Review assigned room listings before deleting this category.', 'must-hotel-booking')
                : __('Create the category first, then assign room listings to it.', 'must-hotel-booking'),
            'excel' => __('Excel imports reference existing categories only. Categories are managed in admin.', 'must-hotel-booking'),
        ],
        [],
        [],
        []
    );

    echo '</div>';
    echo '</section>';
}

function render_rooms_tabs(AccommodationAdminQuery $query): void
{
    $categoryUrl = get_admin_rooms_page_url($query->buildUrlArgs(['tab' => 'categories', 'paged' => 1]));
    $roomUrl = get_admin_rooms_page_url($query->buildUrlArgs(['tab' => 'rooms', 'paged' => 1]));
    $unitUrl = get_admin_rooms_page_url($query->buildUrlArgs(['tab' => 'units', 'paged' => 1]));

    echo '<nav class="must-accommodation-tabs" aria-label="' . \esc_attr__('Accommodation views', 'must-hotel-booking') . '">';
    echo '<a class="must-accommodation-tab' . ($query->isCategoriesTab() ? ' is-active' : '') . '" href="' . \esc_url($categoryUrl) . '">' . \esc_html__('Categories', 'must-hotel-booking') . '</a>';
    echo '<a class="must-accommodation-tab' . ($query->isRoomsTab() ? ' is-active' : '') . '" href="' . \esc_url($roomUrl) . '">' . \esc_html__('Rooms / Listings', 'must-hotel-booking') . '</a>';
    echo '<a class="must-accommodation-tab' . ($query->isUnitsTab() ? ' is-active' : '') . '" href="' . \esc_url($unitUrl) . '">' . \esc_html__('Units / Rooms', 'must-hotel-booking') . '</a>';
    echo '</nav>';
}

/**
 * @param array<int, array<string, string>> $chips
 */
function render_accommodation_filter_chips(array $chips): void
{
    if (empty($chips)) {
        return;
    }

    echo '<div class="must-accommodation-filter-chips">';

    foreach ($chips as $chip) {
        echo '<span class="must-accommodation-filter-chip"><strong>' . \esc_html((string) ($chip['label'] ?? '')) . '</strong> ' . \esc_html((string) ($chip['value'] ?? '')) . '</span>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $pagination
 * @param array<string, scalar> $args
 */
function render_accommodation_pagination(array $pagination, array $args): void
{
    $totalPages = isset($pagination['total_pages']) ? (int) $pagination['total_pages'] : 1;

    if ($totalPages <= 1) {
        return;
    }

    $currentPage = isset($pagination['current_page']) ? (int) $pagination['current_page'] : 1;
    $big = 999999999;
    $baseUrl = \add_query_arg('paged', $big, get_admin_rooms_page_url($args));
    $links = \paginate_links(
        [
            'base' => \str_replace((string) $big, '%#%', \esc_url_raw($baseUrl)),
            'format' => '',
            'current' => $currentPage,
            'total' => $totalPages,
            'type' => 'list',
            'prev_text' => \__('Previous', 'must-hotel-booking'),
            'next_text' => \__('Next', 'must-hotel-booking'),
        ]
    );

    if (!\is_string($links) || $links === '') {
        return;
    }

    echo '<div class="must-accommodation-pagination">' . $links . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function render_accommodation_badge(string $label, string $tone = 'neutral'): void
{
    echo '<span class="must-accommodation-badge is-' . \esc_attr($tone) . '">' . \esc_html($label) . '</span>';
}

/**
 * @param array<int, string> $warnings
 */
function render_accommodation_warning_list(array $warnings): void
{
    if (empty($warnings)) {
        return;
    }

    echo '<ul class="must-accommodation-warning-list">';

    foreach ($warnings as $warning) {
        echo '<li>' . \esc_html((string) $warning) . '</li>';
    }

    echo '</ul>';
}

/**
 * @param array<string, string> $configuration
 */
function render_accommodation_configuration_summary(array $configuration): void
{
    if (empty($configuration)) {
        echo '<p class="must-accommodation-empty-note">' . \esc_html__('Save the accommodation first to unlock linked setup signals and operational summaries.', 'must-hotel-booking') . '</p>';
        return;
    }

    echo '<dl class="must-accommodation-definition-list">';

    foreach ($configuration as $label => $value) {
        echo '<div><dt>' . \esc_html(\ucwords(\str_replace('_', ' ', $label))) . '</dt><dd>' . \esc_html((string) $value) . '</dd></div>';
    }

    echo '</dl>';
}

/**
 * @param array<string, string> $links
 * @param array<string, string> $labels
 */
function render_accommodation_quick_links(array $links, array $labels): void
{
    $hasLinks = false;

    foreach ($labels as $key => $label) {
        if (!empty($links[$key])) {
            $hasLinks = true;
            break;
        }
    }

    if (!$hasLinks) {
        echo '<p class="must-accommodation-empty-note">' . \esc_html__('Contextual links appear once the record exists.', 'must-hotel-booking') . '</p>';
        return;
    }

    echo '<div class="must-accommodation-link-list">';

    foreach ($labels as $key => $label) {
        $url = isset($links[$key]) ? (string) $links[$key] : '';

        if ($url === '') {
            continue;
        }

        echo '<a class="button button-secondary" href="' . \esc_url($url) . '">' . \esc_html($label) . '</a>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $form
 */
function render_accommodation_type_filters(AccommodationAdminQuery $query, array $form, array $categoryOptions, array $chips, int $count): void
{
    echo '<section class="must-accommodation-panel must-accommodation-filter-panel">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html__('Filter Rooms / Listings', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Surface the sellable room listings that still need pricing, availability, or status cleanup.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<span class="must-accommodation-inline-stat">' . \esc_html(\sprintf(_n('%d listing in view', '%d listings in view', $count, 'must-hotel-booking'), $count)) . '</span>';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</div></div>';
    echo '<form method="get" class="must-accommodation-filter-grid">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-rooms" />';
    echo '<input type="hidden" name="tab" value="rooms" />';

    echo '<label><span>' . \esc_html__('Search', 'must-hotel-booking') . '</span><input type="search" name="search" value="' . \esc_attr($query->getSearch()) . '" placeholder="' . \esc_attr__('Name, slug, code, description', 'must-hotel-booking') . '" /></label>';

    echo '<label><span>' . \esc_html__('Category', 'must-hotel-booking') . '</span><select name="category">';
    echo '<option value="">' . \esc_html__('All categories', 'must-hotel-booking') . '</option>';
    foreach ($categoryOptions as $categoryKey => $categoryLabel) {
        echo '<option value="' . \esc_attr((string) $categoryKey) . '"' . \selected($query->getCategory(), (string) $categoryKey, false) . '>' . \esc_html((string) $categoryLabel) . '</option>';
    }
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Status', 'must-hotel-booking') . '</span><select name="status">';
    echo '<option value="">' . \esc_html__('All statuses', 'must-hotel-booking') . '</option>';
    echo '<option value="active"' . \selected($query->getStatus(), 'active', false) . '>' . \esc_html__('Active', 'must-hotel-booking') . '</option>';
    echo '<option value="inactive"' . \selected($query->getStatus(), 'inactive', false) . '>' . \esc_html__('Inactive', 'must-hotel-booking') . '</option>';
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Bookable', 'must-hotel-booking') . '</span><select name="bookable">';
    echo '<option value="">' . \esc_html__('Any', 'must-hotel-booking') . '</option>';
    echo '<option value="yes"' . \selected($query->getBookable(), 'yes', false) . '>' . \esc_html__('Bookable', 'must-hotel-booking') . '</option>';
    echo '<option value="no"' . \selected($query->getBookable(), 'no', false) . '>' . \esc_html__('Not bookable', 'must-hotel-booking') . '</option>';
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Setup', 'must-hotel-booking') . '</span><select name="setup">';
    echo '<option value="">' . \esc_html__('Any setup state', 'must-hotel-booking') . '</option>';
    echo '<option value="missing_pricing"' . \selected($query->getSetup(), 'missing_pricing', false) . '>' . \esc_html__('Missing pricing', 'must-hotel-booking') . '</option>';
    echo '<option value="missing_availability"' . \selected($query->getSetup(), 'missing_availability', false) . '>' . \esc_html__('Missing availability', 'must-hotel-booking') . '</option>';
    echo '<option value="attention"' . \selected($query->getSetup(), 'attention', false) . '>' . \esc_html__('Needs attention', 'must-hotel-booking') . '</option>';
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Future Reservations', 'must-hotel-booking') . '</span><select name="future">';
    echo '<option value="">' . \esc_html__('Any', 'must-hotel-booking') . '</option>';
    echo '<option value="yes"' . \selected($query->getFuture(), 'yes', false) . '>' . \esc_html__('Has future reservations', 'must-hotel-booking') . '</option>';
    echo '<option value="no"' . \selected($query->getFuture(), 'no', false) . '>' . \esc_html__('No future reservations', 'must-hotel-booking') . '</option>';
    echo '</select></label>';

    echo '<div class="must-accommodation-filter-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Apply Filters', 'must-hotel-booking') . '</button>';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '">' . \esc_html__('Clear', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</form>';

    render_accommodation_filter_chips($chips);
    echo '</section>';
}

/**
 * @param array<int, array<string, mixed>> $typeOptions
 * @param array<string, string> $statusOptions
 * @param array<int, array<string, string>> $chips
 */
function render_accommodation_unit_filters(AccommodationAdminQuery $query, array $typeOptions, array $statusOptions, array $chips, int $count): void
{
    echo '<section class="must-accommodation-panel must-accommodation-filter-panel">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html__('Filter Units / Rooms', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Inspect physical units, operational status, and reservation exposure without leaving the inventory workspace.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<span class="must-accommodation-inline-stat">' . \esc_html(\sprintf(_n('%d unit in view', '%d units in view', $count, 'must-hotel-booking'), $count)) . '</span>';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'units'])) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</div></div>';
    echo '<form method="get" class="must-accommodation-filter-grid">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-rooms" />';
    echo '<input type="hidden" name="tab" value="units" />';

    echo '<label><span>' . \esc_html__('Search', 'must-hotel-booking') . '</span><input type="search" name="search" value="' . \esc_attr($query->getSearch()) . '" placeholder="' . \esc_attr__('Room number, title, building', 'must-hotel-booking') . '" /></label>';

    echo '<label><span>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</span><select name="room_type_id">';
    echo '<option value="0">' . \esc_html__('All room listings', 'must-hotel-booking') . '</option>';
    foreach ($typeOptions as $typeOption) {
        $optionId = isset($typeOption['id']) ? (int) $typeOption['id'] : 0;
        $optionLabel = isset($typeOption['label']) ? (string) $typeOption['label'] : ('#' . $optionId);

        if ($optionId <= 0) {
            continue;
        }

        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected($query->getUnitTypeId(), $optionId, false) . '>' . \esc_html($optionLabel) . '</option>';
    }
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Status', 'must-hotel-booking') . '</span><select name="status">';
    echo '<option value="">' . \esc_html__('All statuses', 'must-hotel-booking') . '</option>';
    echo '<option value="active"' . \selected($query->getStatus(), 'active', false) . '>' . \esc_html__('Active', 'must-hotel-booking') . '</option>';
    echo '<option value="inactive"' . \selected($query->getStatus(), 'inactive', false) . '>' . \esc_html__('Inactive', 'must-hotel-booking') . '</option>';
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Bookable', 'must-hotel-booking') . '</span><select name="bookable">';
    echo '<option value="">' . \esc_html__('Any', 'must-hotel-booking') . '</option>';
    echo '<option value="yes"' . \selected($query->getBookable(), 'yes', false) . '>' . \esc_html__('Bookable', 'must-hotel-booking') . '</option>';
    echo '<option value="no"' . \selected($query->getBookable(), 'no', false) . '>' . \esc_html__('Not bookable', 'must-hotel-booking') . '</option>';
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Operational State', 'must-hotel-booking') . '</span><select name="unit_status">';
    echo '<option value="">' . \esc_html__('Any state', 'must-hotel-booking') . '</option>';
    foreach ($statusOptions as $statusKey => $statusLabel) {
        echo '<option value="' . \esc_attr((string) $statusKey) . '"' . \selected($query->getUnitStatus(), (string) $statusKey, false) . '>' . \esc_html((string) $statusLabel) . '</option>';
    }
    echo '</select></label>';

    echo '<label><span>' . \esc_html__('Future Reservations', 'must-hotel-booking') . '</span><select name="future">';
    echo '<option value="">' . \esc_html__('Any', 'must-hotel-booking') . '</option>';
    echo '<option value="yes"' . \selected($query->getFuture(), 'yes', false) . '>' . \esc_html__('Has future reservations', 'must-hotel-booking') . '</option>';
    echo '<option value="no"' . \selected($query->getFuture(), 'no', false) . '>' . \esc_html__('No future reservations', 'must-hotel-booking') . '</option>';
    echo '</select></label>';

    echo '<div class="must-accommodation-filter-actions">';
    echo '<button type="submit" class="button button-primary">' . \esc_html__('Apply Filters', 'must-hotel-booking') . '</button>';
    echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'units'])) . '">' . \esc_html__('Clear', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</form>';

    render_accommodation_filter_chips($chips);
    echo '</section>';
}

/**
 * @param array<string, string> $configuration
 * @param array<string, string> $quickLinks
 * @param array<string, string> $linkLabels
 * @param array<int, string> $warnings
 */
function render_accommodation_editor_sidebar(string $summaryTitle, array $configuration, array $quickLinks, array $linkLabels, array $warnings): void
{
    echo '<aside class="must-accommodation-editor-rail">';

    echo '<section class="must-accommodation-side-card">';
    echo '<h3>' . \esc_html($summaryTitle) . '</h3>';
    render_accommodation_configuration_summary($configuration);
    echo '</section>';

    echo '<section class="must-accommodation-side-card">';
    echo '<h3>' . \esc_html__('Quick Links', 'must-hotel-booking') . '</h3>';
    render_accommodation_quick_links($quickLinks, $linkLabels);
    echo '</section>';

    echo '<section class="must-accommodation-side-card">';
    echo '<h3>' . \esc_html__('Warnings', 'must-hotel-booking') . '</h3>';

    if (empty($warnings)) {
        echo '<p class="must-accommodation-empty-note">' . \esc_html__('No operational issues are currently detected for this record.', 'must-hotel-booking') . '</p>';
    } else {
        render_accommodation_warning_list($warnings);
    }

    echo '</section>';
    echo '</aside>';
}

function render_accommodation_metric_card(string $label, string $value, string $detail = '', string $tone = 'neutral'): void
{
    echo '<div class="must-accommodation-metric-card is-' . \esc_attr($tone) . '">';
    echo '<span class="must-accommodation-metric-label">' . \esc_html($label) . '</span>';
    echo '<strong class="must-accommodation-metric-value">' . \esc_html($value) . '</strong>';

    if ($detail !== '') {
        echo '<small class="must-accommodation-metric-detail">' . \esc_html($detail) . '</small>';
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $form
 * @param array<string, string> $categoryOptions
 */
function render_accommodation_type_form(array $form, array $categoryOptions, string $closeUrl = ''): void
{
    $isEditing = isset($form['type_id']) && (int) $form['type_id'] > 0;
    $mainImageId = isset($form['main_image_id']) ? (int) $form['main_image_id'] : 0;
    $galleryIds = isset($form['gallery_ids']) && \is_array($form['gallery_ids']) ? \array_map('intval', $form['gallery_ids']) : [];
    $selectedAmenities = isset($form['amenity_keys']) && \is_array($form['amenity_keys']) ? \array_map('strval', $form['amenity_keys']) : [];
    $selectedAmenityMap = \array_fill_keys($selectedAmenities, true);
    $availableAmenities = get_available_room_amenities();
    $selectedAmenityLabels = [];
    $currencyCode = \class_exists(\MustHotelBooking\Core\MustBookingConfig::class)
        ? (string) \MustHotelBooking\Core\MustBookingConfig::get_currency()
        : 'USD';
    $closeHref = $closeUrl !== '' ? $closeUrl : get_admin_rooms_page_url(['tab' => 'rooms']);

    foreach ($availableAmenities as $amenityKey => $amenityMeta) {
        if (!isset($selectedAmenityMap[(string) $amenityKey])) {
            continue;
        }

        $selectedAmenityLabels[] = (string) ($amenityMeta['label'] ?? $amenityKey);
    }

    echo '<section class="must-accommodation-panel must-accommodation-editor-card">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html($isEditing ? __('Edit Room / Listing', 'must-hotel-booking') : __('Create Room / Listing', 'must-hotel-booking')) . '</h2><p>' . \esc_html__('Room listings are the actual sellable accommodation records. Pricing, availability, reservations, and widgets continue to run against these records.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<a class="button button-secondary must-open-accommodation-editor" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '" data-editor-url="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '" data-editor-modal="type">' . \esc_html__('New Blank Listing', 'must-hotel-booking') . '</a>';
    echo '</div></div>';

    echo '<div class="must-accommodation-editor-layout">';
    echo '<form method="post" class="must-accommodation-editor-form">';
    \wp_nonce_field('must_accommodation_save_type', 'must_accommodation_nonce');
    echo '<input type="hidden" name="must_accommodation_action" value="save_type" />';
    echo '<input type="hidden" name="type_id" value="' . \esc_attr((string) ((int) ($form['type_id'] ?? 0))) . '" />';

    echo '<section class="must-accommodation-form-section">';
    echo '<div class="must-accommodation-section-head"><h3>' . \esc_html__('Basic Information', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Use clear naming and category grouping so staff can find the right sellable listing fast.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-field-grid is-two-col">';
    echo '<label><span>' . \esc_html__('Name', 'must-hotel-booking') . '</span><input type="text" name="name" value="' . \esc_attr((string) ($form['name'] ?? '')) . '" required /></label>';
    echo '<label><span>' . \esc_html__('Slug', 'must-hotel-booking') . '</span><input type="text" name="slug" value="' . \esc_attr((string) ($form['slug'] ?? '')) . '" placeholder="' . \esc_attr__('Auto-generated if left blank', 'must-hotel-booking') . '" /></label>';
    echo '<label><span>' . \esc_html__('Accommodation Category', 'must-hotel-booking') . '</span><select name="category" required>';
    foreach ($categoryOptions as $categoryKey => $categoryLabel) {
        echo '<option value="' . \esc_attr((string) $categoryKey) . '"' . \selected((string) ($form['category'] ?? ''), (string) $categoryKey, false) . '>' . \esc_html((string) $categoryLabel) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span>' . \esc_html__('Internal Code', 'must-hotel-booking') . '</span><input type="text" name="internal_code" value="' . \esc_attr((string) ($form['internal_code'] ?? '')) . '" placeholder="' . \esc_attr__('Optional operational code', 'must-hotel-booking') . '" /></label>';
    echo '<label><span>' . \esc_html__('Sort Order', 'must-hotel-booking') . '</span><input type="number" name="sort_order" value="' . \esc_attr((string) ((int) ($form['sort_order'] ?? 0))) . '" step="1" /></label>';
    echo '<label class="must-accommodation-field-span-2"><span>' . \esc_html__('Short Admin Description', 'must-hotel-booking') . '</span><textarea name="description" rows="4">' . \esc_textarea((string) ($form['description'] ?? '')) . '</textarea></label>';
    echo '</div>';
    echo '</section>';

    echo '<section class="must-accommodation-form-section">';
    echo '<div class="must-accommodation-section-head"><h3>' . \esc_html__('Capacity & Occupancy', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Keep occupancy data aligned so availability, pricing, and booking validation stay coherent.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-field-grid is-two-col">';
    echo '<label><span>' . \esc_html__('Max Adults', 'must-hotel-booking') . '</span><input type="number" min="1" name="max_adults" value="' . \esc_attr((string) ((int) ($form['max_adults'] ?? 1))) . '" /></label>';
    echo '<label><span>' . \esc_html__('Max Children', 'must-hotel-booking') . '</span><input type="number" min="0" name="max_children" value="' . \esc_attr((string) ((int) ($form['max_children'] ?? 0))) . '" /></label>';
    echo '<label><span>' . \esc_html__('Max Total Guests', 'must-hotel-booking') . '</span><input type="number" min="1" name="max_guests" value="' . \esc_attr((string) ((int) ($form['max_guests'] ?? 1))) . '" /></label>';
    echo '<label><span>' . \esc_html__('Default Occupancy', 'must-hotel-booking') . '</span><input type="number" min="1" name="default_occupancy" value="' . \esc_attr((string) ((int) ($form['default_occupancy'] ?? 1))) . '" /></label>';
    echo '<label><span>' . \esc_html__('Base Price', 'must-hotel-booking') . '</span><input type="number" min="0" step="0.01" name="base_price" value="' . \esc_attr(\number_format((float) ($form['base_price'] ?? 0.0), 2, '.', '')) . '" /><small>' . \esc_html(\sprintf(__('Fallback nightly base price in %s.', 'must-hotel-booking'), $currencyCode)) . '</small></label>';
    echo '<label><span>' . \esc_html__('Extra Guest Price', 'must-hotel-booking') . '</span><input type="number" min="0" step="0.01" name="extra_guest_price" value="' . \esc_attr(\number_format((float) ($form['extra_guest_price'] ?? 0.0), 2, '.', '')) . '" /><small>' . \esc_html(\sprintf(__('Additional nightly amount per guest in %s.', 'must-hotel-booking'), $currencyCode)) . '</small></label>';
    echo '<label><span>' . \esc_html__('Room Size', 'must-hotel-booking') . '</span><input type="text" name="room_size" value="' . \esc_attr((string) ($form['room_size'] ?? '')) . '" placeholder="' . \esc_attr__('e.g. 28 m2', 'must-hotel-booking') . '" /></label>';
    echo '<label class="must-accommodation-field-span-2"><span>' . \esc_html__('Beds', 'must-hotel-booking') . '</span><input type="text" name="beds" value="' . \esc_attr((string) ($form['beds'] ?? '')) . '" placeholder="' . \esc_attr__('e.g. 1 king bed + sofa bed', 'must-hotel-booking') . '" /></label>';
    echo '</div>';
    echo '</section>';

    echo '<section class="must-accommodation-form-section">';
    echo '<div class="must-accommodation-section-head"><h3>' . \esc_html__('Booking Behavior', 'must-hotel-booking') . '</h3><p>' . \esc_html__('These switches control whether the listing is sellable, visible in operations, and available for online booking.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-behavior-summary" data-booking-behavior-summary>';
    echo '<span class="must-accommodation-behavior-summary-label">' . \esc_html__('Current behavior', 'must-hotel-booking') . '</span>';
    echo '<div class="must-accommodation-behavior-summary-pills" data-booking-behavior-pills></div>';
    echo '</div>';
    echo '<div class="must-accommodation-toggle-grid">';
    echo '<label class="must-accommodation-toggle" data-toggle-card><input type="checkbox" name="is_active" value="1" data-toggle-label-on="' . \esc_attr__('Active', 'must-hotel-booking') . '" data-toggle-label-off="' . \esc_attr__('Inactive', 'must-hotel-booking') . '"' . \checked(!empty($form['is_active']), true, false) . ' /><span><strong>' . \esc_html__('Active', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Inactive listings remain in history but should stop being used for new setup.', 'must-hotel-booking') . '</small></span></label>';
    echo '<label class="must-accommodation-toggle" data-toggle-card><input type="checkbox" name="is_bookable" value="1" data-toggle-label-on="' . \esc_attr__('Staff bookable', 'must-hotel-booking') . '" data-toggle-label-off="' . \esc_attr__('Staff blocked', 'must-hotel-booking') . '"' . \checked(!empty($form['is_bookable']), true, false) . ' /><span><strong>' . \esc_html__('Bookable', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Controls whether staff can allocate this listing to new reservations.', 'must-hotel-booking') . '</small></span></label>';
    echo '<label class="must-accommodation-toggle" data-toggle-card><input type="checkbox" name="is_online_bookable" value="1" data-toggle-label-on="' . \esc_attr__('Online visible', 'must-hotel-booking') . '" data-toggle-label-off="' . \esc_attr__('Offline only', 'must-hotel-booking') . '"' . \checked(!empty($form['is_online_bookable']), true, false) . ' /><span><strong>' . \esc_html__('Available for online booking', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Use this when the listing should be exposed to the booking flow.', 'must-hotel-booking') . '</small></span></label>';
    echo '<label class="must-accommodation-toggle" data-toggle-card><input type="checkbox" name="is_calendar_visible" value="1" data-toggle-label-on="' . \esc_attr__('Shown in calendar', 'must-hotel-booking') . '" data-toggle-label-off="' . \esc_attr__('Hidden from calendar', 'must-hotel-booking') . '"' . \checked(!empty($form['is_calendar_visible']), true, false) . ' /><span><strong>' . \esc_html__('Visible in calendar', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Hide listings from operational boards without deleting them.', 'must-hotel-booking') . '</small></span></label>';
    echo '</div>';
    echo '</section>';

    $mediaSummary = \sprintf(
        __('%1$d amenities selected / %2$d gallery items', 'must-hotel-booking'),
        \count($selectedAmenities),
        \count($galleryIds)
    );
    echo '<details class="must-accommodation-disclosure">';
    echo '<summary><div><strong>' . \esc_html__('Media & Amenities', 'must-hotel-booking') . '</strong><small>' . \esc_html($mediaSummary) . '</small></div></summary>';
    echo '<div class="must-accommodation-disclosure-body">';
    echo '<div class="must-accommodation-field-grid is-two-col">';
    echo '<div class="must-room-main-image-field">';
    echo '<span class="must-accommodation-field-label">' . \esc_html__('Main Image', 'must-hotel-booking') . '</span>';
    echo '<input type="hidden" name="main_image_id" class="must-room-main-image-id" value="' . \esc_attr((string) ((int) ($form['main_image_id'] ?? 0))) . '" />';
    echo '<div class="must-accommodation-media-actions">';
    echo '<button type="button" class="button button-secondary must-room-upload-main-image">' . \esc_html__('Choose Main Image', 'must-hotel-booking') . '</button>';
    echo '<button type="button" class="button button-link-delete must-room-clear-main-image">' . \esc_html__('Clear', 'must-hotel-booking') . '</button>';
    echo '</div>';
    echo '<div class="must-accommodation-preview-card"><span class="must-accommodation-preview-label">' . \esc_html__('Main Image Preview', 'must-hotel-booking') . '</span>';
    render_room_main_image_preview($mainImageId);
    echo '</div>';
    echo '</div>';

    echo '<div class="must-room-gallery-field">';
    echo '<span class="must-accommodation-field-label">' . \esc_html__('Gallery Images', 'must-hotel-booking') . '</span>';
    echo '<input type="hidden" name="gallery_ids" class="must-room-gallery-ids" value="' . \esc_attr((string) ($form['gallery_ids_input'] ?? '')) . '" />';
    echo '<div class="must-accommodation-media-actions">';
    echo '<button type="button" class="button button-secondary must-room-upload-images">' . \esc_html__('Choose Gallery Images', 'must-hotel-booking') . '</button>';
    echo '<button type="button" class="button button-link-delete must-room-clear-images">' . \esc_html__('Clear', 'must-hotel-booking') . '</button>';
    echo '</div>';
    echo '<div class="must-accommodation-preview-card"><span class="must-accommodation-preview-label">' . \esc_html__('Gallery Preview', 'must-hotel-booking') . '</span>';
    render_room_gallery_preview($galleryIds);
    echo '</div>';
    echo '</div>';

    echo '<label class="must-accommodation-field-span-2"><span>' . \esc_html__('Room Rules', 'must-hotel-booking') . '</span><textarea name="room_rules" rows="4">' . \esc_textarea((string) ($form['room_rules'] ?? '')) . '</textarea></label>';
    echo '<label class="must-accommodation-field-span-2"><span>' . \esc_html__('Amenities Intro', 'must-hotel-booking') . '</span><textarea name="amenities_intro" rows="3">' . \esc_textarea((string) ($form['amenities_intro'] ?? '')) . '</textarea></label>';
    echo '<div class="must-accommodation-field-span-2">';
    echo '<div class="must-accommodation-inline-picker">';
    echo '<div class="must-accommodation-inline-picker-copy"><span class="must-accommodation-field-label">' . \esc_html__('Amenities', 'must-hotel-booking') . '</span><small>' . \esc_html(\sprintf(_n('%d amenity selected', '%d amenities selected', \count($selectedAmenities), 'must-hotel-booking'), \count($selectedAmenities))) . '</small></div>';
    echo '<button type="button" class="button button-secondary must-open-submodal" data-modal-target="type-amenities-modal">' . \esc_html__('Edit Amenities', 'must-hotel-booking') . '</button>';
    echo '</div>';
    echo '<div class="must-accommodation-selected-tags"' . (empty($selectedAmenityLabels) ? ' data-empty-text="' . \esc_attr__('No amenities selected yet.', 'must-hotel-booking') . '"' : '') . '>';
    foreach ($selectedAmenityLabels as $selectedAmenityLabel) {
        echo '<span class="must-accommodation-selected-tag">' . \esc_html($selectedAmenityLabel) . '</span>';
    }
    echo '</div>';
    echo '<div class="must-accommodation-submodal" id="type-amenities-modal" hidden>';
    echo '<div class="must-accommodation-submodal-backdrop" data-close-submodal></div>';
    echo '<div class="must-accommodation-submodal-dialog" role="dialog" aria-modal="true" aria-labelledby="type-amenities-modal-title">';
    echo '<div class="must-accommodation-submodal-head"><div><h3 id="type-amenities-modal-title">' . \esc_html__('Amenities', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Choose the guest-facing features that should appear on this room listing.', 'must-hotel-booking') . '</p></div><button type="button" class="must-accommodation-submodal-close" aria-label="' . \esc_attr__('Close amenities panel', 'must-hotel-booking') . '" data-close-submodal>&times;</button></div>';
    echo '<div class="must-accommodation-submodal-toolbar"><input type="search" class="must-accommodation-amenity-search" placeholder="' . \esc_attr__('Search amenities', 'must-hotel-booking') . '" data-amenity-search="type-amenities-modal" /><div class="must-accommodation-submodal-actions"><button type="button" class="button button-secondary must-amenity-bulk-action" data-amenity-bulk="select-all">' . \esc_html__('Select All', 'must-hotel-booking') . '</button><button type="button" class="button button-link must-amenity-bulk-action" data-amenity-bulk="clear">' . \esc_html__('Clear', 'must-hotel-booking') . '</button></div></div>';
    echo '<div class="must-room-amenities-selector" data-amenity-list>';
    foreach ($availableAmenities as $amenityKey => $amenityMeta) {
        $iconFile = isset($amenityMeta['icon']) ? (string) $amenityMeta['icon'] : '';
        $iconUrl = $iconFile !== '' ? MUST_HOTEL_BOOKING_URL . 'assets/img/' . $iconFile : '';
        $isSelected = isset($selectedAmenityMap[(string) $amenityKey]);
        $amenityLabel = (string) ($amenityMeta['label'] ?? $amenityKey);
        echo '<label class="must-room-amenity-option' . ($isSelected ? ' is-selected' : '') . '" data-amenity-label="' . \esc_attr(\strtolower($amenityLabel)) . '">';
        if ($iconUrl !== '') {
            echo '<span class="must-room-amenity-icon-wrap"><img class="must-room-amenity-icon" src="' . \esc_url($iconUrl) . '" alt="" /></span>';
        }
        echo '<span class="must-room-amenity-label">' . \esc_html($amenityLabel) . '</span>';
        echo '<input type="checkbox" name="amenity_keys[]" value="' . \esc_attr((string) $amenityKey) . '"' . \checked($isSelected, true, false) . ' />';
        echo '</label>';
    }
    echo '</div>';
    echo '<div class="must-accommodation-submodal-foot"><button type="button" class="button button-primary must-close-submodal-action" data-close-submodal>' . \esc_html__('Done', 'must-hotel-booking') . '</button></div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</details>';

    $hasNotes = (string) ($form['admin_notes'] ?? '') !== '';
    echo '<details class="must-accommodation-disclosure">';
    echo '<summary><div><strong>' . \esc_html__('Operational Notes', 'must-hotel-booking') . '</strong><small>' . \esc_html($hasNotes ? __('Internal notes saved for this listing', 'must-hotel-booking') : __('No internal notes yet', 'must-hotel-booking')) . '</small></div></summary>';
    echo '<div class="must-accommodation-disclosure-body">';
    echo '<label><span>' . \esc_html__('Admin Notes', 'must-hotel-booking') . '</span><textarea name="admin_notes" rows="5">' . \esc_textarea((string) ($form['admin_notes'] ?? '')) . '</textarea></label>';
    echo '</div>';
    echo '</details>';

    echo '<div class="must-accommodation-editor-actions">';
    echo '<button type="submit" class="button button-primary button-large">' . \esc_html($isEditing ? __('Save Room / Listing', 'must-hotel-booking') : __('Create Room / Listing', 'must-hotel-booking')) . '</button>';
    echo '<a class="button button-secondary must-close-accommodation-editor" href="' . \esc_url($closeHref) . '">' . \esc_html__('Cancel', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</form>';

    render_accommodation_editor_sidebar(
        __('Linked Configuration Summary', 'must-hotel-booking'),
        isset($form['configuration']) && \is_array($form['configuration']) ? $form['configuration'] : [],
        isset($form['quick_links']) && \is_array($form['quick_links']) ? $form['quick_links'] : [],
        [
            'pricing' => __('Open Pricing', 'must-hotel-booking'),
            'rate_plans' => __('Open Rate Plans', 'must-hotel-booking'),
            'availability' => __('Open Availability Rules', 'must-hotel-booking'),
            'calendar' => __('Open Calendar', 'must-hotel-booking'),
            'reservations' => __('View Reservations', 'must-hotel-booking'),
        ],
        isset($form['warnings']) && \is_array($form['warnings']) ? $form['warnings'] : []
    );
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $form
 * @param array<int, array<string, mixed>> $typeOptions
 * @param array<string, string> $statusOptions
 */
function render_accommodation_unit_form(array $form, array $typeOptions, array $statusOptions, string $closeUrl = ''): void
{
    $isEditing = isset($form['unit_id']) && (int) $form['unit_id'] > 0;
    $closeHref = $closeUrl !== '' ? $closeUrl : get_admin_rooms_page_url(['tab' => 'units']);

    echo '<section class="must-accommodation-panel must-accommodation-editor-card">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html($isEditing ? __('Edit Unit / Room', 'must-hotel-booking') : __('Create Unit / Room', 'must-hotel-booking')) . '</h2><p>' . \esc_html__('Units represent the physical rooms the calendar can assign and operations staff can manage directly.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<a class="button button-secondary must-open-accommodation-editor" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'units'])) . '" data-editor-url="' . \esc_url(get_admin_rooms_page_url(['tab' => 'units'])) . '" data-editor-modal="unit">' . \esc_html__('New Blank Unit', 'must-hotel-booking') . '</a>';
    echo '</div></div>';

    echo '<div class="must-accommodation-editor-layout">';
    echo '<form method="post" class="must-accommodation-editor-form">';
    \wp_nonce_field('must_accommodation_save_unit', 'must_accommodation_unit_nonce');
    echo '<input type="hidden" name="must_accommodation_action" value="save_unit" />';
    echo '<input type="hidden" name="unit_id" value="' . \esc_attr((string) ((int) ($form['unit_id'] ?? 0))) . '" />';

    echo '<section class="must-accommodation-form-section">';
    echo '<div class="must-accommodation-section-head"><h3>' . \esc_html__('Basic Information', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Define the actual sellable or assignable room that operations staff work with every day.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-field-grid is-two-col">';
    echo '<label><span>' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</span><select name="room_type_id" required>';
    echo '<option value="0">' . \esc_html__('Select room listing', 'must-hotel-booking') . '</option>';
    foreach ($typeOptions as $typeOption) {
        $optionId = isset($typeOption['id']) ? (int) $typeOption['id'] : 0;
        $optionLabel = isset($typeOption['label']) ? (string) $typeOption['label'] : ('#' . $optionId);

        if ($optionId <= 0) {
            continue;
        }

        echo '<option value="' . \esc_attr((string) $optionId) . '"' . \selected((int) ($form['room_type_id'] ?? 0), $optionId, false) . '>' . \esc_html($optionLabel) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span>' . \esc_html__('Unit Title', 'must-hotel-booking') . '</span><input type="text" name="title" value="' . \esc_attr((string) ($form['title'] ?? '')) . '" placeholder="' . \esc_attr__('e.g. Suite A', 'must-hotel-booking') . '" /></label>';
    echo '<label><span>' . \esc_html__('Internal Reference / Room Number', 'must-hotel-booking') . '</span><input type="text" name="room_number" value="' . \esc_attr((string) ($form['room_number'] ?? '')) . '" required placeholder="' . \esc_attr__('e.g. 101', 'must-hotel-booking') . '" /></label>';
    echo '<label><span>' . \esc_html__('Floor', 'must-hotel-booking') . '</span><input type="number" name="floor" value="' . \esc_attr((string) ((int) ($form['floor'] ?? 0))) . '" step="1" /></label>';
    echo '<label><span>' . \esc_html__('Building', 'must-hotel-booking') . '</span><input type="text" name="building" value="' . \esc_attr((string) ($form['building'] ?? '')) . '" /></label>';
    echo '<label><span>' . \esc_html__('Section', 'must-hotel-booking') . '</span><input type="text" name="section" value="' . \esc_attr((string) ($form['section'] ?? '')) . '" /></label>';
    echo '</div>';
    echo '</section>';

    echo '<section class="must-accommodation-form-section">';
    echo '<div class="must-accommodation-section-head"><h3>' . \esc_html__('Capacity & Operations', 'must-hotel-booking') . '</h3><p>' . \esc_html__('Override only what is truly unit-specific so the linked room-listing setup remains the single source of truth.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-field-grid is-two-col">';
    echo '<label><span>' . \esc_html__('Operational State', 'must-hotel-booking') . '</span><select name="status">';
    foreach ($statusOptions as $statusKey => $statusLabel) {
        echo '<option value="' . \esc_attr((string) $statusKey) . '"' . \selected((string) ($form['status'] ?? 'available'), (string) $statusKey, false) . '>' . \esc_html((string) $statusLabel) . '</option>';
    }
    echo '</select></label>';
    echo '<label><span>' . \esc_html__('Capacity Override', 'must-hotel-booking') . '</span><input type="number" min="0" name="capacity_override" value="' . \esc_attr((string) ((int) ($form['capacity_override'] ?? 0))) . '" /><small>' . \esc_html__('Leave 0 to inherit the linked room listing capacity.', 'must-hotel-booking') . '</small></label>';
    echo '<label><span>' . \esc_html__('Sort Order', 'must-hotel-booking') . '</span><input type="number" name="sort_order" value="' . \esc_attr((string) ((int) ($form['sort_order'] ?? 0))) . '" step="1" /></label>';
    echo '</div>';
    echo '<div class="must-accommodation-toggle-grid">';
    echo '<label class="must-accommodation-toggle"><input type="checkbox" name="is_active" value="1"' . \checked(!empty($form['is_active']), true, false) . ' /><span><strong>' . \esc_html__('Active', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Inactive units stay in history but should stop receiving new assignments.', 'must-hotel-booking') . '</small></span></label>';
    echo '<label class="must-accommodation-toggle"><input type="checkbox" name="is_bookable" value="1"' . \checked(!empty($form['is_bookable']), true, false) . ' /><span><strong>' . \esc_html__('Bookable', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Controls whether this exact unit can be selected for new operational bookings.', 'must-hotel-booking') . '</small></span></label>';
    echo '<label class="must-accommodation-toggle"><input type="checkbox" name="is_calendar_visible" value="1"' . \checked(!empty($form['is_calendar_visible']), true, false) . ' /><span><strong>' . \esc_html__('Visible in calendar', 'must-hotel-booking') . '</strong><small>' . \esc_html__('Hide back-of-house or non-assignable units from the board when needed.', 'must-hotel-booking') . '</small></span></label>';
    echo '</div>';
    echo '</section>';

    $unitHasNotes = (string) ($form['admin_notes'] ?? '') !== '';
    echo '<details class="must-accommodation-disclosure">';
    echo '<summary><div><strong>' . \esc_html__('Operational Notes', 'must-hotel-booking') . '</strong><small>' . \esc_html($unitHasNotes ? __('Internal notes saved for this unit', 'must-hotel-booking') : __('No internal notes yet', 'must-hotel-booking')) . '</small></div></summary>';
    echo '<div class="must-accommodation-disclosure-body">';
    echo '<label><span>' . \esc_html__('Admin Notes', 'must-hotel-booking') . '</span><textarea name="admin_notes" rows="6">' . \esc_textarea((string) ($form['admin_notes'] ?? '')) . '</textarea></label>';
    echo '</div>';
    echo '</details>';

    echo '<div class="must-accommodation-editor-actions">';
    echo '<button type="submit" class="button button-primary button-large">' . \esc_html($isEditing ? __('Save Unit', 'must-hotel-booking') : __('Create Unit', 'must-hotel-booking')) . '</button>';
    echo '<a class="button button-secondary must-close-accommodation-editor" href="' . \esc_url($closeHref) . '">' . \esc_html__('Cancel', 'must-hotel-booking') . '</a>';
    echo '</div>';
    echo '</form>';

    render_accommodation_editor_sidebar(
        __('Unit Configuration Summary', 'must-hotel-booking'),
        isset($form['configuration']) && \is_array($form['configuration']) ? $form['configuration'] : [],
        isset($form['quick_links']) && \is_array($form['quick_links']) ? $form['quick_links'] : [],
        [
            'type' => __('Open Room / Listing', 'must-hotel-booking'),
            'calendar' => __('Open Calendar', 'must-hotel-booking'),
            'reservations' => __('View Reservations', 'must-hotel-booking'),
        ],
        isset($form['warnings']) && \is_array($form['warnings']) ? $form['warnings'] : []
    );
    echo '</div>';
    echo '</section>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_accommodation_type_list(AccommodationAdminQuery $query, array $pageData): void
{
    $rows = isset($pageData['type_rows']) && \is_array($pageData['type_rows']) ? $pageData['type_rows'] : [];
    $pagination = isset($pageData['type_pagination']) && \is_array($pageData['type_pagination']) ? $pageData['type_pagination'] : [];

    echo '<section class="must-accommodation-panel must-accommodation-list-panel">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html__('Rooms / Listings', 'must-hotel-booking') . '</h2><p>' . \esc_html__('These sellable room/listing records drive pricing, availability, reservations, and the public room structure.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<a class="button button-primary must-open-accommodation-editor" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '" data-editor-url="' . \esc_url(get_admin_rooms_page_url(['tab' => 'rooms'])) . '" data-editor-modal="type">' . \esc_html__('Create New Listing', 'must-hotel-booking') . '</a>';
    echo '</div></div>';
    echo '<div class="must-accommodation-record-list">';

    if (empty($rows)) {
        echo '<div class="must-accommodation-empty-table">' . \esc_html__('No room listings matched the current filters.', 'must-hotel-booking') . '</div>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $pricingTone = !empty($row['pricing_configured']) ? 'success' : 'warning';
            $availabilityTone = !empty($row['availability_configured']) ? 'success' : 'warning';
            $reservationDetail = \sprintf(__('Current stays: %d', 'must-hotel-booking'), (int) ($row['current_reservations'] ?? 0));

            if ((string) ($row['next_checkin'] ?? '') !== '') {
                $reservationDetail .= ' / ' . \sprintf(__('Next %s', 'must-hotel-booking'), (string) $row['next_checkin']);
            }

            echo '<article class="must-accommodation-record-card">';
            echo '<div class="must-accommodation-record-head">';
            echo '<div class="must-accommodation-record-main">';
            echo '<div class="must-accommodation-record-title-row">';
            echo '<h3 class="must-accommodation-record-title"><a class="must-open-accommodation-editor" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-url="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-modal="type">' . \esc_html((string) ($row['name'] ?? '')) . '</a></h3>';
            if ((string) ($row['internal_code'] ?? '') !== '') {
                echo '<span class="must-accommodation-record-code">' . \esc_html((string) $row['internal_code']) . '</span>';
            }
            echo '</div>';

            echo '<div class="must-accommodation-row-meta">';
            echo '<span>' . \esc_html((string) ($row['category_label'] ?? '')) . '</span>';
            if ((string) ($row['slug'] ?? '') !== '') {
                echo '<span>' . \esc_html((string) $row['slug']) . '</span>';
            }
            echo '<span>' . \esc_html(\sprintf(__('Sort %d', 'must-hotel-booking'), (int) ($row['sort_order'] ?? 0))) . '</span>';
            echo '</div>';

            if ((string) ($row['description'] ?? '') !== '') {
                echo '<p class="must-accommodation-record-description">' . \esc_html((string) $row['description']) . '</p>';
            }

            if (!empty($row['warnings']) && \is_array($row['warnings'])) {
                echo '<div class="must-accommodation-record-alert">';
                render_accommodation_warning_list($row['warnings']);
                echo '</div>';
            }

            echo '</div>';

            echo '<div class="must-accommodation-record-state">';
            render_accommodation_badge(!empty($row['is_active']) ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking'), !empty($row['is_active']) ? 'success' : 'muted');
            render_accommodation_badge(!empty($row['is_bookable']) ? __('Bookable', 'must-hotel-booking') : __('Not bookable', 'must-hotel-booking'), !empty($row['is_bookable']) ? 'info' : 'warning');
            render_accommodation_badge(!empty($row['is_online_bookable']) ? __('Online', 'must-hotel-booking') : __('Offline only', 'must-hotel-booking'), !empty($row['is_online_bookable']) ? 'neutral' : 'muted');
            render_accommodation_badge(!empty($row['is_calendar_visible']) ? __('Calendar visible', 'must-hotel-booking') : __('Calendar hidden', 'must-hotel-booking'), !empty($row['is_calendar_visible']) ? 'neutral' : 'muted');
            echo '</div>';
            echo '</div>';

            echo '<div class="must-accommodation-record-metrics">';
            render_accommodation_metric_card(__('Capacity', 'must-hotel-booking'), (string) ($row['capacity_summary'] ?? ''), (string) ($row['beds'] ?? ''), 'neutral');
            render_accommodation_metric_card(__('Pricing', 'must-hotel-booking'), \number_format_i18n((float) ($row['base_price'] ?? 0.0), 2), \sprintf(_n('%d active rate plan', '%d active rate plans', (int) ($row['active_rate_plan_count'] ?? 0), 'must-hotel-booking'), (int) ($row['active_rate_plan_count'] ?? 0)), $pricingTone);
            render_accommodation_metric_card(__('Availability', 'must-hotel-booking'), \sprintf(_n('%d unit', '%d units', (int) ($row['inventory_units'] ?? 0), 'must-hotel-booking'), (int) ($row['inventory_units'] ?? 0)), \sprintf(_n('%d rule', '%d rules', (int) ($row['availability_rule_count'] ?? 0), 'must-hotel-booking'), (int) ($row['availability_rule_count'] ?? 0)), $availabilityTone);
            render_accommodation_metric_card(__('Reservations', 'must-hotel-booking'), (string) ((int) ($row['future_reservations'] ?? 0)), $reservationDetail, (int) ($row['future_reservations'] ?? 0) > 0 ? 'neutral' : 'muted');
            echo '</div>';

            echo '<div class="must-accommodation-record-foot">';
            echo '<div class="must-accommodation-primary-actions">';
            echo '<a class="button button-primary must-open-accommodation-editor" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-url="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-modal="type">' . \esc_html__('Edit Listing', 'must-hotel-booking') . '</a>';
            if ((string) ($row['calendar_url'] ?? '') !== '') {
                echo '<a class="button button-secondary" href="' . \esc_url((string) $row['calendar_url']) . '">' . \esc_html__('Calendar', 'must-hotel-booking') . '</a>';
            }
            if ((string) ($row['rate_plans_url'] ?? '') !== '') {
                echo '<a class="button button-secondary" href="' . \esc_url((string) $row['rate_plans_url']) . '">' . \esc_html__('Rate Plans', 'must-hotel-booking') . '</a>';
            }
            echo '</div>';

            echo '<div class="must-accommodation-secondary-actions">';
            echo '<a href="' . \esc_url((string) ($row['toggle_url'] ?? '')) . '">' . \esc_html(!empty($row['is_active']) ? __('Deactivate', 'must-hotel-booking') : __('Activate', 'must-hotel-booking')) . '</a>';
            echo '<a href="' . \esc_url((string) ($row['duplicate_url'] ?? '')) . '">' . \esc_html__('Duplicate', 'must-hotel-booking') . '</a>';
            if ((string) ($row['availability_url'] ?? '') !== '') {
                echo '<a href="' . \esc_url((string) $row['availability_url']) . '">' . \esc_html__('Availability Rules', 'must-hotel-booking') . '</a>';
            }
            if ((string) ($row['reservations_url'] ?? '') !== '') {
                echo '<a href="' . \esc_url((string) $row['reservations_url']) . '">' . \esc_html__('Reservations', 'must-hotel-booking') . '</a>';
            }
            echo '</div>';
            echo '</div>';
            echo '</article>';
        }
    }

    echo '</div>';
    render_accommodation_pagination($pagination, $query->buildUrlArgs(['tab' => 'rooms']));
    echo '</section>';
}

/**
 * @param array<string, mixed> $pageData
 */
function render_accommodation_unit_list(AccommodationAdminQuery $query, array $pageData): void
{
    $rows = isset($pageData['unit_rows']) && \is_array($pageData['unit_rows']) ? $pageData['unit_rows'] : [];
    $pagination = isset($pageData['unit_pagination']) && \is_array($pageData['unit_pagination']) ? $pageData['unit_pagination'] : [];

    echo '<section class="must-accommodation-panel must-accommodation-list-panel">';
    echo '<div class="must-accommodation-panel-head">';
    echo '<div><h2>' . \esc_html__('Units / Rooms', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Manage the physical rooms that inventory, calendar assignment, and operational availability rely on.', 'must-hotel-booking') . '</p></div>';
    echo '<div class="must-accommodation-panel-actions">';
    echo '<a class="button button-primary must-open-accommodation-editor" href="' . \esc_url(get_admin_rooms_page_url(['tab' => 'units'])) . '" data-editor-url="' . \esc_url(get_admin_rooms_page_url(['tab' => 'units'])) . '" data-editor-modal="unit">' . \esc_html__('Create New Unit', 'must-hotel-booking') . '</a>';
    echo '</div></div>';
    echo '<div class="must-accommodation-record-list">';

    if (empty($rows)) {
        echo '<div class="must-accommodation-empty-table">' . \esc_html__('No units matched the current filters.', 'must-hotel-booking') . '</div>';
    } else {
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $setupTone = (!empty($row['pricing_configured']) && !empty($row['availability_configured'])) ? 'success' : 'warning';
            $reservationDetail = \sprintf(__('Current stays: %d', 'must-hotel-booking'), (int) ($row['current_reservations'] ?? 0));

            if ((string) ($row['next_checkin'] ?? '') !== '') {
                $reservationDetail .= ' / ' . \sprintf(__('Next %s', 'must-hotel-booking'), (string) $row['next_checkin']);
            }

            echo '<article class="must-accommodation-record-card is-unit-card">';
            echo '<div class="must-accommodation-record-head">';
            echo '<div class="must-accommodation-record-main">';
            echo '<div class="must-accommodation-record-title-row">';
            echo '<h3 class="must-accommodation-record-title"><a class="must-open-accommodation-editor" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-url="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-modal="unit">' . \esc_html((string) ($row['title'] ?? '')) . '</a></h3>';
            echo '<span class="must-accommodation-record-code">' . \esc_html((string) ($row['room_number'] ?? '')) . '</span>';
            echo '</div>';
            echo '<div class="must-accommodation-row-meta">';
            echo '<span>' . \esc_html((string) ($row['type_name'] ?? '')) . '</span>';
            if ((string) ($row['building'] ?? '') !== '') {
                echo '<span>' . \esc_html((string) $row['building']) . '</span>';
            }
            if ((string) ($row['section'] ?? '') !== '') {
                echo '<span>' . \esc_html((string) $row['section']) . '</span>';
            }
            if ((int) ($row['floor'] ?? 0) !== 0) {
                echo '<span>' . \esc_html(\sprintf(__('Floor %d', 'must-hotel-booking'), (int) ($row['floor'] ?? 0))) . '</span>';
            }
            echo '<span>' . \esc_html(\sprintf(__('Sort %d', 'must-hotel-booking'), (int) ($row['sort_order'] ?? 0))) . '</span>';
            echo '</div>';

            if (!empty($row['warnings']) && \is_array($row['warnings'])) {
                echo '<div class="must-accommodation-record-alert">';
                render_accommodation_warning_list($row['warnings']);
                echo '</div>';
            }

            echo '</div>';

            echo '<div class="must-accommodation-record-state">';
            render_accommodation_badge(!empty($row['is_active']) ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking'), !empty($row['is_active']) ? 'success' : 'muted');
            render_accommodation_badge(!empty($row['is_bookable']) ? __('Bookable', 'must-hotel-booking') : __('Not bookable', 'must-hotel-booking'), !empty($row['is_bookable']) ? 'info' : 'warning');
            render_accommodation_badge((string) ($row['status_label'] ?? __('Available', 'must-hotel-booking')), (string) ($row['status'] ?? '') === 'available' ? 'neutral' : 'warning');
            render_accommodation_badge(!empty($row['is_calendar_visible']) ? __('Calendar visible', 'must-hotel-booking') : __('Calendar hidden', 'must-hotel-booking'), !empty($row['is_calendar_visible']) ? 'neutral' : 'muted');
            echo '</div>';
            echo '</div>';

            echo '<div class="must-accommodation-record-metrics">';
            render_accommodation_metric_card(__('Capacity', 'must-hotel-booking'), (string) ($row['capacity_summary'] ?? ''), __('Unit-level occupancy handling', 'must-hotel-booking'), 'neutral');
            render_accommodation_metric_card(__('Setup', 'must-hotel-booking'), !empty($row['pricing_configured']) ? __('Ready', 'must-hotel-booking') : __('Needs pricing', 'must-hotel-booking'), !empty($row['availability_configured']) ? __('Availability linked', 'must-hotel-booking') : __('Availability missing', 'must-hotel-booking'), $setupTone);
            render_accommodation_metric_card(__('Reservations', 'must-hotel-booking'), (string) ((int) ($row['future_reservations'] ?? 0)), $reservationDetail, (int) ($row['future_reservations'] ?? 0) > 0 ? 'neutral' : 'muted');
            render_accommodation_metric_card(__('Listing Link', 'must-hotel-booking'), (string) ($row['type_name'] ?? ''), __('Shares pricing and rules with its linked room/listing record', 'must-hotel-booking'), 'info');
            echo '</div>';

            echo '<div class="must-accommodation-record-foot">';
            echo '<div class="must-accommodation-primary-actions">';
            echo '<a class="button button-primary must-open-accommodation-editor" href="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-url="' . \esc_url((string) ($row['edit_url'] ?? '')) . '" data-editor-modal="unit">' . \esc_html__('Edit Unit', 'must-hotel-booking') . '</a>';
            if ((string) ($row['calendar_url'] ?? '') !== '') {
                echo '<a class="button button-secondary" href="' . \esc_url((string) $row['calendar_url']) . '">' . \esc_html__('Calendar', 'must-hotel-booking') . '</a>';
            }
            if ((string) ($row['type_edit_url'] ?? '') !== '') {
                echo '<a class="button button-secondary" href="' . \esc_url((string) $row['type_edit_url']) . '">' . \esc_html__('Open Listing', 'must-hotel-booking') . '</a>';
            }
            echo '</div>';

            echo '<div class="must-accommodation-secondary-actions">';
            echo '<a href="' . \esc_url((string) ($row['toggle_url'] ?? '')) . '">' . \esc_html(!empty($row['is_active']) ? __('Deactivate', 'must-hotel-booking') : __('Activate', 'must-hotel-booking')) . '</a>';
            if ((string) ($row['reservations_url'] ?? '') !== '') {
                echo '<a href="' . \esc_url((string) $row['reservations_url']) . '">' . \esc_html__('Reservations', 'must-hotel-booking') . '</a>';
            }
            if ((string) ($row['delete_url'] ?? '') !== '') {
                echo '<a class="is-danger" href="' . \esc_url((string) $row['delete_url']) . '">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
            }
            echo '</div>';
            echo '</div>';
            echo '</article>';
        }
    }

    echo '</div>';
    render_accommodation_pagination($pagination, $query->buildUrlArgs(['tab' => 'units']));
    echo '</section>';
}

function render_admin_rooms_page(): void
{
    ensure_admin_capability();

    $query = AccommodationAdminQuery::fromRequest(\is_array($_REQUEST) ? $_REQUEST : []);
    $saveState = maybe_handle_rooms_admin_save_request($query);
    $pageData = (new AccommodationAdminDataProvider())->getPageData($query, $saveState);
    $categoryErrors = isset($pageData['category_errors']) && \is_array($pageData['category_errors']) ? $pageData['category_errors'] : [];
    $typeErrors = isset($pageData['type_errors']) && \is_array($pageData['type_errors']) ? $pageData['type_errors'] : [];
    $unitErrors = isset($pageData['unit_errors']) && \is_array($pageData['unit_errors']) ? $pageData['unit_errors'] : [];
    $calendarUrl = \function_exists(__NAMESPACE__ . '\get_admin_calendar_page_url')
        ? get_admin_calendar_page_url(['start_date' => \current_time('Y-m-d'), 'weeks' => 2])
        : '';
    $reservationsUrl = \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url')
        ? get_admin_reservations_page_url()
        : '';
    $ratePlansUrl = \function_exists(__NAMESPACE__ . '\get_admin_rate_plans_page_url')
        ? get_admin_rate_plans_page_url()
        : '';
    $importReport = AccommodationImportExportService::consumeImportReport();
    $categoryCloseUrl = get_admin_rooms_page_url($query->buildUrlArgs(['tab' => 'categories']));
    $typeCloseUrl = get_admin_rooms_page_url($query->buildUrlArgs(['tab' => 'rooms']));
    $unitCloseUrl = get_admin_rooms_page_url($query->buildUrlArgs(['tab' => 'units']));
    $shouldOpenCategoryEditor = $query->getAction() === 'edit_category' || !empty($categoryErrors);
    $shouldOpenTypeEditor = $query->getAction() === 'edit_room' || !empty($typeErrors);
    $shouldOpenUnitEditor = $query->getAction() === 'edit_unit' || !empty($unitErrors);

    echo '<div class="wrap must-accommodation-page must-hotel-booking-rooms-admin">';
    echo '<header class="must-accommodation-hero"><div class="must-accommodation-hero-copy"><span class="must-accommodation-kicker">' . \esc_html__('Accommodation Structure', 'must-hotel-booking') . '</span><h1>' . \esc_html__('Accommodations', 'must-hotel-booking') . '</h1><p>' . \esc_html__('Manage top-level accommodation categories separately from the sellable room listings and the physical units behind them, while keeping pricing, reservations, and the calendar aligned.', 'must-hotel-booking') . '</p></div><div class="must-accommodation-hero-actions">';
    if ($calendarUrl !== '') {
        echo '<a class="button button-secondary" href="' . \esc_url($calendarUrl) . '">' . \esc_html__('Open Calendar', 'must-hotel-booking') . '</a>';
    }
    if ($reservationsUrl !== '') {
        echo '<a class="button button-secondary" href="' . \esc_url($reservationsUrl) . '">' . \esc_html__('Open Reservations', 'must-hotel-booking') . '</a>';
    }
    if ($ratePlansUrl !== '') {
        echo '<a class="button button-primary" href="' . \esc_url($ratePlansUrl) . '">' . \esc_html__('Open Rate Plans', 'must-hotel-booking') . '</a>';
    }
    echo '</div></header>';

    render_rooms_admin_notice_from_query();
    render_accommodation_import_report($importReport);
    render_accommodation_admin_warnings(isset($pageData['admin_warnings']) && \is_array($pageData['admin_warnings']) ? $pageData['admin_warnings'] : []);
    render_rooms_summary_cards(isset($pageData['summary_cards']) && \is_array($pageData['summary_cards']) ? $pageData['summary_cards'] : []);
    render_accommodation_import_export_panel($query);
    render_rooms_tabs($query);

    if ($query->isCategoriesTab()) {
        render_accommodation_admin_errors($categoryErrors);
        render_accommodation_category_list($pageData);
        echo '<div class="must-accommodation-editor-modal" id="must-accommodation-category-modal" data-editor-modal-shell="category" data-close-url="' . \esc_url($categoryCloseUrl) . '"' . ($shouldOpenCategoryEditor ? ' data-editor-open="1"' : '') . ' hidden>';
        echo '<div class="must-accommodation-editor-backdrop" data-close-editor></div>';
        echo '<div class="must-accommodation-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="must-accommodation-category-modal-title">';
        echo '<div class="must-accommodation-editor-modal-head"><div><span class="must-accommodation-kicker">' . \esc_html__('Accommodation Category', 'must-hotel-booking') . '</span><h2 id="must-accommodation-category-modal-title">' . \esc_html__('Category Editor', 'must-hotel-booking') . '</h2></div><button type="button" class="must-accommodation-editor-close" aria-label="' . \esc_attr__('Close editor', 'must-hotel-booking') . '" data-close-editor>&times;</button></div>';
        echo '<div class="must-accommodation-editor-slot" data-editor-slot>';
        render_accommodation_category_form(
            isset($pageData['category_form']) && \is_array($pageData['category_form']) ? $pageData['category_form'] : [],
            $categoryCloseUrl
        );
        echo '</div></div></div>';
    } elseif ($query->isRoomsTab()) {
        render_accommodation_admin_errors($typeErrors);
        render_accommodation_type_filters(
            $query,
            isset($pageData['type_form']) && \is_array($pageData['type_form']) ? $pageData['type_form'] : [],
            isset($pageData['category_options']) && \is_array($pageData['category_options']) ? $pageData['category_options'] : [],
            isset($pageData['active_type_filters']) && \is_array($pageData['active_type_filters']) ? $pageData['active_type_filters'] : [],
            isset($pageData['type_filter_count']) ? (int) $pageData['type_filter_count'] : 0
        );
        render_accommodation_type_list($query, $pageData);
        echo '<div class="must-accommodation-editor-modal" id="must-accommodation-type-modal" data-editor-modal-shell="type" data-close-url="' . \esc_url($typeCloseUrl) . '"' . ($shouldOpenTypeEditor ? ' data-editor-open="1"' : '') . ' hidden>';
        echo '<div class="must-accommodation-editor-backdrop" data-close-editor></div>';
        echo '<div class="must-accommodation-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="must-accommodation-type-modal-title">';
        echo '<div class="must-accommodation-editor-modal-head"><div><span class="must-accommodation-kicker">' . \esc_html__('Room / Listing', 'must-hotel-booking') . '</span><h2 id="must-accommodation-type-modal-title">' . \esc_html__('Room / Listing Editor', 'must-hotel-booking') . '</h2></div><button type="button" class="must-accommodation-editor-close" aria-label="' . \esc_attr__('Close editor', 'must-hotel-booking') . '" data-close-editor>&times;</button></div>';
        echo '<div class="must-accommodation-editor-slot" data-editor-slot>';
        render_accommodation_type_form(
            isset($pageData['type_form']) && \is_array($pageData['type_form']) ? $pageData['type_form'] : [],
            isset($pageData['category_options']) && \is_array($pageData['category_options']) ? $pageData['category_options'] : [],
            $typeCloseUrl
        );
        echo '</div></div></div>';
    } else {
        render_accommodation_admin_errors($unitErrors);
        render_accommodation_unit_filters(
            $query,
            isset($pageData['type_options']) && \is_array($pageData['type_options']) ? $pageData['type_options'] : [],
            isset($pageData['unit_status_options']) && \is_array($pageData['unit_status_options']) ? $pageData['unit_status_options'] : [],
            isset($pageData['active_unit_filters']) && \is_array($pageData['active_unit_filters']) ? $pageData['active_unit_filters'] : [],
            isset($pageData['unit_filter_count']) ? (int) $pageData['unit_filter_count'] : 0
        );
        render_accommodation_unit_list($query, $pageData);
        echo '<div class="must-accommodation-editor-modal" id="must-accommodation-unit-modal" data-editor-modal-shell="unit" data-close-url="' . \esc_url($unitCloseUrl) . '"' . ($shouldOpenUnitEditor ? ' data-editor-open="1"' : '') . ' hidden>';
        echo '<div class="must-accommodation-editor-backdrop" data-close-editor></div>';
        echo '<div class="must-accommodation-editor-dialog" role="dialog" aria-modal="true" aria-labelledby="must-accommodation-unit-modal-title">';
        echo '<div class="must-accommodation-editor-modal-head"><div><span class="must-accommodation-kicker">' . \esc_html__('Unit / Room', 'must-hotel-booking') . '</span><h2 id="must-accommodation-unit-modal-title">' . \esc_html__('Unit Editor', 'must-hotel-booking') . '</h2></div><button type="button" class="must-accommodation-editor-close" aria-label="' . \esc_attr__('Close editor', 'must-hotel-booking') . '" data-close-editor>&times;</button></div>';
        echo '<div class="must-accommodation-editor-slot" data-editor-slot>';
        render_accommodation_unit_form(
            isset($pageData['unit_form']) && \is_array($pageData['unit_form']) ? $pageData['unit_form'] : [],
            isset($pageData['type_options']) && \is_array($pageData['type_options']) ? $pageData['type_options'] : [],
            isset($pageData['unit_status_options']) && \is_array($pageData['unit_status_options']) ? $pageData['unit_status_options'] : [],
            $unitCloseUrl
        );
        echo '</div></div></div>';
    }

    echo '</div>';
}

function enqueue_admin_rooms_assets(): void
{
    if (!isset($_GET['page'])) {
        return;
    }

    $page = \sanitize_key((string) \wp_unslash($_GET['page']));

    if ($page !== 'must-hotel-booking-rooms') {
        return;
    }

    \wp_enqueue_style(
        'must-hotel-booking-admin-ui',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-ui.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_style(
        'must-hotel-booking-admin-rooms',
        MUST_HOTEL_BOOKING_URL . 'assets/css/admin-rooms.css',
        ['must-hotel-booking-admin-ui'],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_media();

    \wp_enqueue_script(
        'must-hotel-booking-admin-rooms',
        MUST_HOTEL_BOOKING_URL . 'assets/js/admin-rooms.js',
        ['jquery', 'media-editor', 'media-views'],
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
            'loadingEditor' => \__('Loading editor…', 'must-hotel-booking'),
            'loadingEditorFailed' => \__('Unable to load the editor. Opening the page directly instead.', 'must-hotel-booking'),
            'noAmenitiesSelected' => \__('No amenities selected yet.', 'must-hotel-booking'),
        ]
    );
}

\add_action('admin_init', __NAMESPACE__ . '\maybe_handle_rooms_admin_actions_early');
\add_action('admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_rooms_assets');
