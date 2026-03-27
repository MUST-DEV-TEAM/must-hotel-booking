<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;

final class AccommodationAdminActions
{
    /** @var \MustHotelBooking\Database\RoomCategoryRepository */
    private $roomCategoryRepository;

    /** @var \MustHotelBooking\Database\RoomRepository */
    private $roomRepository;

    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventoryRepository;

    /** @var \MustHotelBooking\Database\ReservationRepository */
    private $reservationRepository;

    public function __construct()
    {
        $this->roomCategoryRepository = \MustHotelBooking\Engine\get_room_category_repository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
    }

    /**
     * @param array<string, scalar> $args
     */
    private function redirectToRoomsPage(array $args): void
    {
        $url = \admin_url('admin.php?page=must-hotel-booking-rooms');

        if (!empty($args)) {
            $url = \add_query_arg($args, $url);
        }

        if (!\wp_safe_redirect($url)) {
            \wp_redirect($url);
        }

        exit;
    }

    public function handleGetAction(AccommodationAdminQuery $query): void
    {
        $action = $query->getAction();

        if ($action === AccommodationImportExportService::ACTION_EXPORT_WORKBOOK) {
            $this->handleWorkbookDownload(false);
            return;
        }

        if ($action === AccommodationImportExportService::ACTION_DOWNLOAD_TEMPLATE) {
            $this->handleWorkbookDownload(true);
            return;
        }

        if ($action === 'delete_category') {
            $this->deleteCategory($query);
            return;
        }

        if ($action === 'toggle_type_status') {
            $this->toggleTypeStatus($query);
            return;
        }

        if ($action === 'toggle_unit_status') {
            $this->toggleUnitStatus($query);
            return;
        }

        if ($action === 'duplicate_type') {
            $this->duplicateType($query);
            return;
        }

        if ($action === 'delete_type') {
            $this->deleteType($query);
            return;
        }

        if ($action === 'delete_unit') {
            $this->deleteUnit($query);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function handleSaveRequest(AccommodationAdminQuery $query): array
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return $this->blankState();
        }

        $action = isset($_POST['must_accommodation_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_accommodation_action'])) : '';

        if ($action === 'save_category') {
            return $this->saveCategory();
        }

        if ($action === 'save_type') {
            return $this->saveType();
        }

        if ($action === 'save_unit') {
            return $this->saveUnit();
        }

        if ($action === AccommodationImportExportService::ACTION_IMPORT_WORKBOOK) {
            (new AccommodationImportExportService())->handleImportUpload($query);
        }

        return $this->blankState();
    }

    private function handleWorkbookDownload(bool $template): void
    {
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';
        $action = $template
            ? AccommodationImportExportService::ACTION_DOWNLOAD_TEMPLATE
            : AccommodationImportExportService::ACTION_EXPORT_WORKBOOK;

        if (!\wp_verify_nonce($nonce, $action)) {
            $this->redirectToRoomsPage([
                'tab' => 'rooms',
                'notice' => 'invalid_nonce',
            ]);
        }

        (new AccommodationImportExportService())->handleExportDownload($template);
    }

    /**
     * @return array<string, mixed>
     */
    private function saveCategory(): array
    {
        $nonce = isset($_POST['must_accommodation_category_nonce']) ? (string) \wp_unslash($_POST['must_accommodation_category_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_accommodation_save_category')) {
            return [
                'category_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'category_form' => null,
                'type_errors' => [],
                'type_form' => null,
                'unit_errors' => [],
                'unit_form' => null,
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $values = $this->sanitizeCategoryValues($rawPost);

        if (!empty($values['errors'])) {
            return [
                'category_errors' => (array) $values['errors'],
                'category_form' => $values,
                'type_errors' => [],
                'type_form' => null,
                'unit_errors' => [],
                'unit_form' => null,
            ];
        }

        $categoryId = (int) $values['category_id'];
        $saved = $categoryId > 0
            ? $this->roomCategoryRepository->updateCategory($categoryId, $values)
            : ($this->roomCategoryRepository->createCategory($values) > 0);

        if (!$saved) {
            return [
                'category_errors' => [\__('Unable to save the accommodation category. Please check your database schema.', 'must-hotel-booking')],
                'category_form' => $values,
                'type_errors' => [],
                'type_form' => null,
                'unit_errors' => [],
                'unit_form' => null,
            ];
        }

        $redirectArgs = [
            'tab' => 'categories',
            'notice' => $categoryId > 0 ? 'category_updated' : 'category_created',
        ];

        if ($categoryId > 0) {
            $redirectArgs['action'] = 'edit_category';
            $redirectArgs['category_id'] = $categoryId;
        }

        $this->redirectToRoomsPage($redirectArgs);
    }

    /**
     * @return array<string, mixed>
     */
    private function saveType(): array
    {
        $nonce = isset($_POST['must_accommodation_nonce']) ? (string) \wp_unslash($_POST['must_accommodation_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_accommodation_save_type')) {
            return [
                'category_errors' => [],
                'category_form' => null,
                'type_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'type_form' => null,
                'unit_errors' => [],
                'unit_form' => null,
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $values = $this->sanitizeTypeValues($rawPost);

        if (!empty($values['errors'])) {
            return [
                'category_errors' => [],
                'category_form' => null,
                'type_errors' => (array) $values['errors'],
                'type_form' => $values,
                'unit_errors' => [],
                'unit_form' => null,
            ];
        }

        $typeId = (int) $values['type_id'];
        $savedId = 0;

        if ($typeId > 0) {
            $updated = $this->roomRepository->updateRoom($typeId, $values);

            if ($updated) {
                $savedId = $typeId;
            }
        } else {
            $savedId = $this->roomRepository->createRoom($values);
        }

        if ($savedId <= 0) {
            return [
                'category_errors' => [],
                'category_form' => null,
                'type_errors' => [\__('Unable to save the room listing. Please check your database schema.', 'must-hotel-booking')],
                'type_form' => $values,
                'unit_errors' => [],
                'unit_form' => null,
            ];
        }

        save_room_meta_data(
            $savedId,
            (int) $values['main_image_id'],
            (string) $values['room_rules'],
            (string) $values['amenities_intro'],
            (array) $values['amenity_keys'],
            (array) $values['gallery_ids']
        );

        $this->inventoryRepository->syncRoomType(
            $savedId,
            [
                'name' => (string) $values['name'],
                'description' => (string) $values['description'],
                'capacity' => (int) $values['max_guests'],
                'base_price' => (float) $values['base_price'],
            ]
        );

        $this->redirectToRoomsPage([
            'tab' => 'rooms',
            'action' => 'edit_room',
            'type_id' => $savedId,
            'notice' => $typeId > 0 ? 'room_updated' : 'room_created',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function saveUnit(): array
    {
        $nonce = isset($_POST['must_accommodation_unit_nonce']) ? (string) \wp_unslash($_POST['must_accommodation_unit_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_accommodation_save_unit')) {
            return [
                'category_errors' => [],
                'category_form' => null,
                'type_errors' => [],
                'type_form' => null,
                'unit_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'unit_form' => null,
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $values = $this->sanitizeUnitValues($rawPost);

        if (!empty($values['errors'])) {
            return [
                'category_errors' => [],
                'category_form' => null,
                'type_errors' => [],
                'type_form' => null,
                'unit_errors' => (array) $values['errors'],
                'unit_form' => $values,
            ];
        }

        $unitId = (int) $values['unit_id'];
        $savedId = 0;

        if ($unitId > 0) {
            $updated = $this->inventoryRepository->updateInventoryRoom($unitId, $values);

            if ($updated) {
                $savedId = $unitId;
            }
        } else {
            $savedId = $this->inventoryRepository->createInventoryRoom($values);
        }

        if ($savedId <= 0) {
            return [
                'category_errors' => [],
                'category_form' => null,
                'type_errors' => [],
                'type_form' => null,
                'unit_errors' => [\__('Unable to save the accommodation unit. Please check your database schema.', 'must-hotel-booking')],
                'unit_form' => $values,
            ];
        }

        $this->redirectToRoomsPage([
            'tab' => 'units',
            'action' => 'edit_unit',
            'unit_id' => $savedId,
            'notice' => $unitId > 0 ? 'unit_updated' : 'unit_created',
        ]);
    }

    private function deleteCategory(AccommodationAdminQuery $query): void
    {
        $categoryId = $query->getCategoryId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($categoryId <= 0 || !\wp_verify_nonce($nonce, 'must_accommodation_delete_category_' . $categoryId)) {
            $this->redirectToRoomsPage(['tab' => 'categories', 'notice' => 'invalid_nonce']);
        }

        $category = $this->roomCategoryRepository->getCategoryById($categoryId);

        if (!\is_array($category)) {
            $this->redirectToRoomsPage(['tab' => 'categories', 'notice' => 'category_missing']);
        }

        $slug = \sanitize_key((string) ($category['slug'] ?? ''));
        $assignedRooms = $slug !== '' ? $this->roomRepository->countRoomsForCategory($slug) : 0;

        if ($assignedRooms > 0) {
            $this->redirectToRoomsPage([
                'tab' => 'categories',
                'notice' => 'category_delete_blocked',
                'category_id' => $categoryId,
            ]);
        }

        $deleted = $this->roomCategoryRepository->deleteCategory($categoryId);
        $this->redirectToRoomsPage([
            'tab' => 'categories',
            'notice' => $deleted ? 'category_deleted' : 'category_delete_failed',
        ]);
    }

    private function toggleTypeStatus(AccommodationAdminQuery $query): void
    {
        $typeId = $query->getTypeId();
        $target = isset($_GET['target']) ? \sanitize_key((string) \wp_unslash($_GET['target'])) : '';
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($typeId <= 0 || !\wp_verify_nonce($nonce, 'must_accommodation_toggle_type_' . $typeId)) {
            $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'invalid_nonce']);
        }

        $room = $this->roomRepository->getRoomById($typeId);

        if (!\is_array($room)) {
            $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'room_missing']);
        }

        $room['is_active'] = $target === 'active' ? 1 : 0;
        $saved = $this->roomRepository->updateRoom($typeId, $room);

        if ($saved) {
            $this->inventoryRepository->syncRoomType(
                $typeId,
                [
                    'name' => (string) ($room['name'] ?? ''),
                    'description' => (string) ($room['description'] ?? ''),
                    'capacity' => (int) ($room['max_guests'] ?? 1),
                    'base_price' => (float) ($room['base_price'] ?? 0.0),
                ]
            );
        }

        $this->redirectToRoomsPage([
            'tab' => 'rooms',
            'notice' => $saved ? ($target === 'active' ? 'room_activated' : 'room_deactivated') : 'room_update_failed',
        ]);
    }

    private function toggleUnitStatus(AccommodationAdminQuery $query): void
    {
        $unitId = $query->getUnitId();
        $target = isset($_GET['target']) ? \sanitize_key((string) \wp_unslash($_GET['target'])) : '';
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($unitId <= 0 || !\wp_verify_nonce($nonce, 'must_accommodation_toggle_unit_' . $unitId)) {
            $this->redirectToRoomsPage(['tab' => 'units', 'notice' => 'invalid_nonce']);
        }

        $unit = $this->inventoryRepository->getInventoryRoomById($unitId);

        if (!\is_array($unit)) {
            $this->redirectToRoomsPage(['tab' => 'units', 'notice' => 'unit_missing']);
        }

        $unit['is_active'] = $target === 'active' ? 1 : 0;
        $saved = $this->inventoryRepository->updateInventoryRoom($unitId, $unit);

        $this->redirectToRoomsPage([
            'tab' => 'units',
            'notice' => $saved ? ($target === 'active' ? 'unit_activated' : 'unit_deactivated') : 'unit_update_failed',
        ]);
    }

    private function duplicateType(AccommodationAdminQuery $query): void
    {
        $typeId = $query->getTypeId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($typeId <= 0 || !\wp_verify_nonce($nonce, 'must_accommodation_duplicate_type_' . $typeId)) {
            $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'invalid_nonce']);
        }

        $room = $this->roomRepository->getRoomById($typeId);

        if (!\is_array($room)) {
            $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'room_missing']);
        }

        $copy = $room;
        $copy['name'] = \sprintf(__('%s Copy', 'must-hotel-booking'), (string) ($room['name'] ?? 'Accommodation'));
        $copy['slug'] = generate_unique_room_slug((string) $copy['name']);
        $copy['is_active'] = 0;
        $copy['is_bookable'] = 0;

        $newTypeId = $this->roomRepository->createRoom($copy);

        if ($newTypeId <= 0) {
            $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'room_duplicate_failed']);
        }

        save_room_meta_data(
            $newTypeId,
            get_room_main_image_id($typeId),
            get_room_rules_text($typeId),
            get_room_amenities_intro_text($typeId),
            get_room_amenities($typeId),
            get_room_gallery_image_ids($typeId)
        );

        $this->inventoryRepository->syncRoomType(
            $newTypeId,
            [
                'name' => (string) $copy['name'],
                'description' => (string) ($copy['description'] ?? ''),
                'capacity' => (int) ($copy['max_guests'] ?? 1),
                'base_price' => (float) ($copy['base_price'] ?? 0.0),
            ]
        );

        $this->redirectToRoomsPage([
            'tab' => 'rooms',
            'action' => 'edit_room',
            'type_id' => $newTypeId,
            'notice' => 'room_duplicated',
        ]);
    }

    private function deleteType(AccommodationAdminQuery $query): void
    {
        $typeId = $query->getTypeId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($typeId <= 0 || !\wp_verify_nonce($nonce, 'must_accommodation_delete_type_' . $typeId)) {
            $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'invalid_nonce']);
        }

        $room = $this->roomRepository->getRoomById($typeId);

        if (!\is_array($room)) {
            $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'room_missing']);
        }

        $dependencySummary = $this->roomRepository->getRoomDeletionGuardSummary($typeId);
        $blockers = isset($dependencySummary['blockers']) && \is_array($dependencySummary['blockers']) ? $dependencySummary['blockers'] : [];

        if (!empty($blockers)) {
            $this->redirectToRoomsPage([
                'tab' => 'rooms',
                'notice' => 'room_delete_blocked',
                'type_id' => $typeId,
            ]);
        }

        $mirrorDeleted = $this->inventoryRepository->deleteDerivedRoomType($typeId);

        if (!$mirrorDeleted) {
            $this->redirectToRoomsPage([
                'tab' => 'rooms',
                'notice' => 'room_delete_failed',
                'type_id' => $typeId,
            ]);
        }

        $deleted = $this->roomRepository->deleteRoom($typeId);

        if (!$deleted) {
            $this->inventoryRepository->syncRoomType(
                $typeId,
                [
                    'name' => (string) ($room['name'] ?? ''),
                    'description' => (string) ($room['description'] ?? ''),
                    'capacity' => (int) ($room['max_guests'] ?? 1),
                    'base_price' => (float) ($room['base_price'] ?? 0.0),
                ]
            );

            $this->redirectToRoomsPage([
                'tab' => 'rooms',
                'notice' => 'room_delete_failed',
                'type_id' => $typeId,
            ]);
        }

        $this->redirectToRoomsPage(['tab' => 'rooms', 'notice' => 'room_deleted']);
    }

    private function deleteUnit(AccommodationAdminQuery $query): void
    {
        $unitId = $query->getUnitId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($unitId <= 0 || !\wp_verify_nonce($nonce, 'must_accommodation_delete_unit_' . $unitId)) {
            $this->redirectToRoomsPage(['tab' => 'units', 'notice' => 'invalid_nonce']);
        }

        if ($this->reservationRepository->countReservationsForInventoryRoom($unitId) > 0) {
            $this->redirectToRoomsPage(['tab' => 'units', 'notice' => 'unit_has_reservations']);
        }

        $deleted = $this->inventoryRepository->deleteInventoryRoom($unitId);
        $this->redirectToRoomsPage(['tab' => 'units', 'notice' => $deleted ? 'unit_deleted' : 'unit_delete_failed']);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sanitizeCategoryValues(array $source): array
    {
        $categoryId = isset($source['category_id']) ? \absint(\wp_unslash($source['category_id'])) : 0;
        $existingCategory = $categoryId > 0 ? $this->roomCategoryRepository->getCategoryById($categoryId) : null;
        $name = isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '';
        $description = isset($source['description']) ? \sanitize_textarea_field((string) \wp_unslash($source['description'])) : '';
        $sortOrder = isset($source['sort_order']) ? (int) \wp_unslash($source['sort_order']) : 0;
        $slug = \is_array($existingCategory)
            ? \sanitize_key((string) ($existingCategory['slug'] ?? ''))
            : $this->generateUniqueCategorySlug($name);
        $errors = [];

        if ($name === '') {
            $errors[] = \__('Category name is required.', 'must-hotel-booking');
        }

        if ($slug === '') {
            $errors[] = \__('A valid category slug could not be generated from the category name.', 'must-hotel-booking');
        }

        return [
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'sort_order' => $sortOrder,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sanitizeTypeValues(array $source): array
    {
        $typeId = isset($source['type_id']) ? \absint(\wp_unslash($source['type_id'])) : 0;
        $existingType = $typeId > 0 ? $this->roomRepository->getRoomById($typeId) : null;
        $name = isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '';
        $slugInput = isset($source['slug']) ? \sanitize_title((string) \wp_unslash($source['slug'])) : '';
        $availableCategories = RoomCatalog::getCategories();
        $rawCategory = isset($source['category']) ? \sanitize_key((string) \wp_unslash($source['category'])) : '';
        $category = $rawCategory;
        $description = isset($source['description']) ? \sanitize_textarea_field((string) \wp_unslash($source['description'])) : '';
        $internalCode = isset($source['internal_code']) ? \sanitize_text_field((string) \wp_unslash($source['internal_code'])) : '';
        $sortOrder = isset($source['sort_order']) ? (int) \wp_unslash($source['sort_order']) : 0;
        $maxAdults = isset($source['max_adults']) ? \max(1, \absint(\wp_unslash($source['max_adults']))) : 1;
        $maxChildren = isset($source['max_children']) ? \max(0, \absint(\wp_unslash($source['max_children']))) : 0;
        $maxGuests = isset($source['max_guests']) ? \max(1, \absint(\wp_unslash($source['max_guests']))) : 1;
        $defaultOccupancy = isset($source['default_occupancy']) ? \max(1, \absint(\wp_unslash($source['default_occupancy']))) : 1;
        $basePrice = isset($source['base_price']) ? \round(\max(0.0, (float) \wp_unslash($source['base_price'])), 2) : 0.0;
        $extraGuestPrice = \array_key_exists('extra_guest_price', $source)
            ? \round(\max(0.0, (float) \wp_unslash($source['extra_guest_price'])), 2)
            : (\is_array($existingType) ? \round(\max(0.0, (float) ($existingType['extra_guest_price'] ?? 0.0)), 2) : 0.0);
        $roomSize = isset($source['room_size']) ? \sanitize_text_field((string) \wp_unslash($source['room_size'])) : '';
        $beds = isset($source['beds']) ? \sanitize_text_field((string) \wp_unslash($source['beds'])) : '';
        $roomRules = isset($source['room_rules']) ? \sanitize_textarea_field((string) \wp_unslash($source['room_rules'])) : '';
        $amenitiesIntro = isset($source['amenities_intro']) ? \sanitize_textarea_field((string) \wp_unslash($source['amenities_intro'])) : '';
        $amenityKeys = isset($source['amenity_keys']) ? parse_room_amenity_keys($source['amenity_keys']) : [];
        $mainImageInput = isset($source['main_image_id']) ? (string) \wp_unslash($source['main_image_id']) : '';
        $galleryInput = isset($source['gallery_ids']) ? (string) \wp_unslash($source['gallery_ids']) : '';
        $adminNotes = isset($source['admin_notes']) ? \sanitize_textarea_field((string) \wp_unslash($source['admin_notes'])) : '';
        $errors = [];

        if ($name === '') {
            $errors[] = \__('Room / listing name is required.', 'must-hotel-booking');
        }

        if (empty($availableCategories)) {
            $errors[] = \__('Create at least one accommodation category before saving a room listing.', 'must-hotel-booking');
            $category = '';
        } elseif ($category === '') {
            $fallbackCategory = \is_array($existingType)
                ? \sanitize_key((string) ($existingType['category'] ?? ''))
                : RoomCatalog::getDefaultCategory();

            if ($fallbackCategory !== '' && isset($availableCategories[$fallbackCategory])) {
                $category = $fallbackCategory;
            } else {
                $errors[] = \__('Select a valid accommodation category.', 'must-hotel-booking');
            }
        } elseif (!isset($availableCategories[$category])) {
            $errors[] = \__('Select a valid accommodation category.', 'must-hotel-booking');
        }

        if ($maxGuests < $maxAdults) {
            $errors[] = \__('Max guests cannot be lower than max adults.', 'must-hotel-booking');
        }

        if ($defaultOccupancy > $maxGuests) {
            $errors[] = \__('Default occupancy cannot exceed max guests.', 'must-hotel-booking');
        }

        if ($maxChildren > 0 && $maxGuests > ($maxAdults + $maxChildren)) {
            $errors[] = \__('Max guests cannot exceed max adults plus max children.', 'must-hotel-booking');
        }

        $slug = generate_unique_room_slug($slugInput !== '' ? $slugInput : $name, $typeId);

        return [
            'type_id' => $typeId,
            'name' => $name,
            'slug' => $slug,
            'category' => $category,
            'description' => $description,
            'internal_code' => $internalCode,
            'is_active' => !empty($source['is_active']) ? 1 : 0,
            'is_bookable' => !empty($source['is_bookable']) ? 1 : 0,
            'is_online_bookable' => !empty($source['is_online_bookable']) ? 1 : 0,
            'is_calendar_visible' => !empty($source['is_calendar_visible']) ? 1 : 0,
            'sort_order' => $sortOrder,
            'max_adults' => $maxAdults,
            'max_children' => $maxChildren,
            'max_guests' => $maxGuests,
            'default_occupancy' => $defaultOccupancy,
            'base_price' => $basePrice,
            'extra_guest_price' => $extraGuestPrice,
            'room_size' => $roomSize,
            'beds' => $beds,
            'room_rules' => $roomRules,
            'amenities_intro' => $amenitiesIntro,
            'amenity_keys' => $amenityKeys,
            'main_image_id' => parse_room_main_image_id($mainImageInput),
            'main_image_id_input' => $mainImageInput,
            'gallery_ids' => parse_room_gallery_ids($galleryInput),
            'gallery_ids_input' => $galleryInput,
            'admin_notes' => $adminNotes,
            'errors' => $errors,
        ];
    }

    private function generateUniqueCategorySlug(string $name, int $excludeCategoryId = 0): string
    {
        $baseSlug = \sanitize_title($name);

        if ($baseSlug === '') {
            return '';
        }

        if (!$this->roomCategoryRepository->slugExists($baseSlug, $excludeCategoryId)) {
            return $baseSlug;
        }

        $index = 2;

        while (true) {
            $candidate = $baseSlug . '-' . $index;

            if (!$this->roomCategoryRepository->slugExists($candidate, $excludeCategoryId)) {
                return $candidate;
            }

            $index++;
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sanitizeUnitValues(array $source): array
    {
        $unitId = isset($source['unit_id']) ? \absint(\wp_unslash($source['unit_id'])) : 0;
        $roomTypeId = isset($source['room_type_id']) ? \absint(\wp_unslash($source['room_type_id'])) : 0;
        $title = isset($source['title']) ? \sanitize_text_field((string) \wp_unslash($source['title'])) : '';
        $roomNumber = isset($source['room_number']) ? \sanitize_text_field((string) \wp_unslash($source['room_number'])) : '';
        $floor = isset($source['floor']) ? (int) \wp_unslash($source['floor']) : 0;
        $status = isset($source['status']) ? \sanitize_key((string) \wp_unslash($source['status'])) : 'available';
        $sortOrder = isset($source['sort_order']) ? (int) \wp_unslash($source['sort_order']) : 0;
        $capacityOverride = isset($source['capacity_override']) ? \max(0, \absint(\wp_unslash($source['capacity_override']))) : 0;
        $building = isset($source['building']) ? \sanitize_text_field((string) \wp_unslash($source['building'])) : '';
        $section = isset($source['section']) ? \sanitize_text_field((string) \wp_unslash($source['section'])) : '';
        $adminNotes = isset($source['admin_notes']) ? \sanitize_textarea_field((string) \wp_unslash($source['admin_notes'])) : '';
        $errors = [];

        if ($roomTypeId <= 0 || !\is_array($this->roomRepository->getRoomById($roomTypeId))) {
            $errors[] = \__('Select a valid room listing.', 'must-hotel-booking');
        }

        if ($roomNumber === '') {
            $errors[] = \__('Unit reference is required.', 'must-hotel-booking');
        } elseif ($this->inventoryRepository->roomNumberExists($roomNumber, $unitId)) {
            $errors[] = \__('Unit reference must be unique.', 'must-hotel-booking');
        }

        $allowedStatuses = ['available', 'maintenance', 'out_of_service', 'blocked'];

        if (!\in_array($status, $allowedStatuses, true)) {
            $status = 'available';
        }

        $typeRow = $roomTypeId > 0 ? $this->roomRepository->getRoomById($roomTypeId) : null;

        if (\is_array($typeRow) && $capacityOverride > 0 && $capacityOverride > (int) ($typeRow['max_guests'] ?? 0)) {
            $errors[] = \__('Capacity override cannot exceed the linked room listing max guests.', 'must-hotel-booking');
        }

        if ($title === '') {
            $title = $roomNumber;
        }

        return [
            'unit_id' => $unitId,
            'room_type_id' => $roomTypeId,
            'title' => $title,
            'room_number' => $roomNumber,
            'floor' => $floor,
            'status' => $status,
            'is_active' => !empty($source['is_active']) ? 1 : 0,
            'is_bookable' => !empty($source['is_bookable']) ? 1 : 0,
            'is_calendar_visible' => !empty($source['is_calendar_visible']) ? 1 : 0,
            'sort_order' => $sortOrder,
            'capacity_override' => $capacityOverride,
            'building' => $building,
            'section' => $section,
            'admin_notes' => $adminNotes,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankState(): array
    {
        return [
            'category_errors' => [],
            'category_form' => null,
            'type_errors' => [],
            'type_form' => null,
            'unit_errors' => [],
            'unit_form' => null,
        ];
    }
}
