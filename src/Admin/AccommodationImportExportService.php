<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;

final class AccommodationImportExportService
{
    public const ACTION_EXPORT_WORKBOOK = 'export_accommodation_workbook';
    public const ACTION_DOWNLOAD_TEMPLATE = 'download_accommodation_workbook_template';
    public const ACTION_IMPORT_WORKBOOK = 'import_accommodation_workbook';

    private const IMPORT_REPORT_TRANSIENT_PREFIX = 'must_hotel_booking_accommodation_import_report_';

    /** @var \MustHotelBooking\Database\RoomRepository */
    private $roomRepository;

    /** @var \MustHotelBooking\Database\InventoryRepository */
    private $inventoryRepository;

    /** @var \MustHotelBooking\Database\DefaultInventoryUnitSyncService */
    private $defaultInventoryUnitSyncService;

    private SimpleXlsxWorkbook $workbook;

    public function __construct()
    {
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
        $this->defaultInventoryUnitSyncService = new \MustHotelBooking\Database\DefaultInventoryUnitSyncService(
            $this->roomRepository,
            $this->inventoryRepository
        );
        $this->workbook = new SimpleXlsxWorkbook();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function consumeImportReport(): ?array
    {
        $userId = \get_current_user_id();

        if ($userId <= 0) {
            return null;
        }

        $transientKey = self::IMPORT_REPORT_TRANSIENT_PREFIX . $userId;
        $report = \get_transient($transientKey);

        if (!\is_array($report)) {
            return null;
        }

        \delete_transient($transientKey);

        return $report;
    }

    public function handleExportDownload(bool $template = false): void
    {
        try {
            $archivePath = $this->workbook->createTemporaryWorkbook(
                $template ? $this->buildTemplateSheets() : $this->buildExportSheets()
            );
        } catch (\Throwable $exception) {
            $this->redirectToRoomsPage([
                'tab' => 'rooms',
                'notice' => $template ? 'workbook_template_failed' : 'workbook_export_failed',
            ]);
        }

        $downloadName = $template
            ? 'must-hotel-booking-accommodations-template.xlsx'
            : 'must-hotel-booking-accommodations-' . \gmdate('Ymd-His') . '.xlsx';

        \nocache_headers();
        \header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        \header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        \header('Content-Length: ' . (string) \filesize($archivePath));

        $handle = \fopen($archivePath, 'rb');

        if (\is_resource($handle)) {
            while (!\feof($handle)) {
                echo (string) \fread($handle, 8192); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }

            \fclose($handle);
        }

        @\unlink($archivePath);
        exit;
    }

    public function handleImportUpload(AccommodationAdminQuery $query): void
    {
        $nonce = isset($_POST['must_accommodation_import_nonce']) ? (string) \wp_unslash($_POST['must_accommodation_import_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_accommodation_import_workbook')) {
            $this->storeImportReport(
                $this->buildErrorReport(
                    \__('Security check failed. Please try the import again.', 'must-hotel-booking')
                )
            );
            $this->redirectToRoomsPage(['tab' => $query->getTab()]);
        }

        $uploadedFile = isset($_FILES['accommodation_workbook']) && \is_array($_FILES['accommodation_workbook'])
            ? $_FILES['accommodation_workbook']
            : null;

        if (!\is_array($uploadedFile)) {
            $this->storeImportReport(
                $this->buildErrorReport(
                    \__('Choose an .xlsx workbook before starting the import.', 'must-hotel-booking')
                )
            );
            $this->redirectToRoomsPage(['tab' => $query->getTab()]);
        }

        $fileName = isset($uploadedFile['name']) ? \sanitize_file_name((string) $uploadedFile['name']) : 'accommodations.xlsx';
        $tmpName = isset($uploadedFile['tmp_name']) ? (string) $uploadedFile['tmp_name'] : '';
        $uploadError = isset($uploadedFile['error']) ? (int) $uploadedFile['error'] : \UPLOAD_ERR_NO_FILE;

        if ($uploadError !== \UPLOAD_ERR_OK || $tmpName === '' || !\is_uploaded_file($tmpName)) {
            $this->storeImportReport(
                $this->buildErrorReport(
                    \__('The workbook upload failed before it could be imported.', 'must-hotel-booking'),
                    $fileName
                )
            );
            $this->redirectToRoomsPage(['tab' => $query->getTab()]);
        }

        require_once \ABSPATH . 'wp-admin/includes/file.php';

        $fileType = \wp_check_filetype_and_ext(
            $tmpName,
            $fileName,
            [
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );
        $extension = isset($fileType['ext']) && \is_string($fileType['ext']) ? $fileType['ext'] : '';

        if ($extension !== 'xlsx' && \strtolower((string) \pathinfo($fileName, \PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->storeImportReport(
                $this->buildErrorReport(
                    \__('Only .xlsx workbooks are supported for accommodation import.', 'must-hotel-booking'),
                    $fileName
                )
            );
            $this->redirectToRoomsPage(['tab' => $query->getTab()]);
        }

        try {
            $sheets = $this->workbook->parseWorkbook($tmpName);
            $report = $this->importWorkbook($sheets, $fileName);
        } catch (\Throwable $exception) {
            $report = $this->buildErrorReport($exception->getMessage(), $fileName);
        }

        $this->storeImportReport($report);
        $this->redirectToRoomsPage(['tab' => $query->getTab()]);
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>
     * }>
     */
    private function buildExportSheets(): array
    {
        return [
            [
                'name' => AccommodationWorkbookSchema::getAccommodationSheetName(),
                'rows' => $this->buildAccommodationSheetRows(true),
            ],
        ];
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>
     * }>
     */
    private function buildTemplateSheets(): array
    {
        return [
            [
                'name' => AccommodationWorkbookSchema::getAccommodationSheetName(),
                'rows' => $this->buildAccommodationSheetRows(false),
            ],
        ];
    }

    /**
     * @return array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>
     */
    private function buildAccommodationSheetRows(bool $includeData): array
    {
        $columns = AccommodationWorkbookSchema::getAccommodationSheetColumns();
        $rows = [
            $this->buildStyledWarningRow($this->buildWorkbookGuidance(), \count($columns)),
            $this->buildStyledHeaderRow($columns),
        ];

        if (!$includeData) {
            return $rows;
        }

        foreach ($this->roomRepository->getAccommodationAdminRows() as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $rows[] = $this->buildOrderedRow($columns, [
                'id' => $roomId,
                'title' => (string) ($row['name'] ?? ''),
                'accommodation_category' => RoomCatalog::getCategoryLabel((string) ($row['category'] ?? RoomCatalog::getDefaultCategory())),
                'description' => (string) ($row['description'] ?? ''),
                'internal_code' => (string) ($row['internal_code'] ?? ''),
                'max_adults' => (int) ($row['max_adults'] ?? 1),
                'max_children' => (int) ($row['max_children'] ?? 0),
                'max_guests' => (int) ($row['max_guests'] ?? 1),
                'default_occupancy' => (int) ($row['default_occupancy'] ?? 1),
                'base_price' => (float) ($row['base_price'] ?? 0.0),
                'extra_guest_price' => (float) ($row['extra_guest_price'] ?? 0.0),
                'size' => (string) ($row['room_size'] ?? ''),
                'bed_type' => (string) ($row['beds'] ?? ''),
                'amenities' => $this->formatAmenityLabelsForWorkbook($this->roomRepository->getRoomAmenities($roomId)),
                'amenities_intro' => $this->roomRepository->getRoomMetaTextValue($roomId, 'amenities_intro'),
                'room_rules' => $this->roomRepository->getRoomMetaTextValue($roomId, 'room_rules'),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'active' => !empty($row['is_active']) ? 'yes' : 'no',
                'bookable' => !empty($row['is_bookable']) ? 'yes' : 'no',
                'online_bookable' => !empty($row['is_online_bookable']) ? 'yes' : 'no',
                'calendar_visible' => !empty($row['is_calendar_visible']) ? 'yes' : 'no',
                'admin_notes' => (string) ($row['admin_notes'] ?? ''),
            ]);
        }

        return $rows;
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, bool|float|int|string|null> $record
     * @return array<int, bool|float|int|string|null>
     */
    private function buildOrderedRow(array $columns, array $record): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[] = $record[$column] ?? '';
        }

        return $row;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, array{value: string, style_id: int}>
     */
    private function buildStyledHeaderRow(array $values): array
    {
        $row = [];

        foreach ($values as $value) {
            $row[] = [
                'value' => $value,
                'style_id' => SimpleXlsxWorkbook::STYLE_HEADER,
            ];
        }

        return $row;
    }

    /**
     * @return array<int, array{value: string, style_id: int}>
     */
    private function buildStyledWarningRow(string $warning, int $columnCount): array
    {
        $columnCount = \max(1, $columnCount);
        $row = [];

        for ($index = 0; $index < $columnCount; $index++) {
            $row[] = [
                'value' => $index === 0 ? $warning : '',
                'style_id' => SimpleXlsxWorkbook::STYLE_WARNING,
            ];
        }

        return $row;
    }

    private function buildWorkbookGuidance(): string
    {
        $categoryLabels = \implode(' / ', \array_values(RoomCatalog::getCategories()));

        return \sprintf(
            /* translators: %s: allowed accommodation category labels */
            \__('Edit this sheet only. Leave id blank to create a new room listing, use an existing id to update, keep accommodation_category within %s, use yes/no for booking flags, and manage categories, images, units, and live availability in WordPress admin.', 'must-hotel-booking'),
            $categoryLabels
        );
    }

    /**
     * @param array<string, array<int, array<int, string>>> $sheets
     * @return array<string, mixed>
     */
    private function importWorkbook(array $sheets, string $fileName): array
    {
        $report = [
            'file_name' => $fileName,
            'imported_at' => \current_time('mysql'),
            'accommodations_created' => 0,
            'accommodations_updated' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'errors' => [],
            'status' => 'success',
        ];

        $sheetName = AccommodationWorkbookSchema::getAccommodationSheetName();
        $rows = $this->normalizeSheetRows(
            $sheets,
            $sheetName,
            AccommodationWorkbookSchema::getAccommodationSheetColumns(),
            $report
        );

        foreach ($rows as $sheetRow) {
            $rowNumber = (int) ($sheetRow['row_number'] ?? 0);
            $values = isset($sheetRow['values']) && \is_array($sheetRow['values']) ? $sheetRow['values'] : [];

            if ($this->isBlankImportRow($values)) {
                $report['rows_skipped'] = (int) $report['rows_skipped'] + 1;
                continue;
            }

            $validation = $this->validateAccommodationRow($values);
            $rowErrors = isset($validation['errors']) && \is_array($validation['errors']) ? $validation['errors'] : [];

            if (!empty($rowErrors)) {
                $report['rows_failed'] = (int) $report['rows_failed'] + 1;

                foreach ($rowErrors as $error) {
                    $this->addImportError(
                        $report,
                        $sheetName,
                        $rowNumber,
                        (string) ($error['field'] ?? ''),
                        (string) ($error['message'] ?? '')
                    );
                }

                continue;
            }

            $payload = isset($validation['payload']) && \is_array($validation['payload']) ? $validation['payload'] : [];
            $operation = (string) ($validation['operation'] ?? 'create');
            $savedId = $this->persistAccommodation($payload, $operation === 'update');

            if ($savedId <= 0) {
                $report['rows_failed'] = (int) $report['rows_failed'] + 1;
                $this->addImportError(
                    $report,
                    $sheetName,
                    $rowNumber,
                    'id',
                    \__('The accommodation record could not be saved.', 'must-hotel-booking')
                );
                continue;
            }

            if ($operation === 'update') {
                $report['accommodations_updated'] = (int) $report['accommodations_updated'] + 1;
            } else {
                $report['accommodations_created'] = (int) $report['accommodations_created'] + 1;
            }
        }

        $report['status'] = $this->determineReportStatus($report);

        return $report;
    }

    /**
     * @param array<string, array<int, array<int, string>>> $sheets
     * @param array<int, string> $expectedColumns
     * @return array<int, array{row_number: int, values: array<string, string>}>
     */
    private function normalizeSheetRows(array $sheets, string $sheetName, array $expectedColumns, array &$report): array
    {
        if (!isset($sheets[$sheetName]) || !\is_array($sheets[$sheetName])) {
            $this->addImportError(
                $report,
                $sheetName,
                0,
                '',
                \sprintf(
                    /* translators: %s: sheet name */
                    \__('Missing required sheet: %s', 'must-hotel-booking'),
                    $sheetName
                )
            );

            return [];
        }

        $sheetRows = $sheets[$sheetName];

        if (empty($sheetRows)) {
            $this->addImportError(
                $report,
                $sheetName,
                0,
                '',
                \__('The workbook sheet is empty and does not include a header row.', 'must-hotel-booking')
            );

            return [];
        }

        $headerIndex = null;
        $headerMap = [];
        $aliases = AccommodationWorkbookSchema::getHeaderAliases();

        foreach ($sheetRows as $rowIndex => $sheetRow) {
            if (!\is_array($sheetRow)) {
                continue;
            }

            $candidateHeaderMap = $this->buildHeaderMap($sheetRow, $aliases);
            $missingColumns = [];

            foreach ($expectedColumns as $columnName) {
                if (!isset($candidateHeaderMap[$columnName])) {
                    $missingColumns[] = $columnName;
                }
            }

            if (empty($missingColumns)) {
                $headerIndex = (int) $rowIndex;
                $headerMap = $candidateHeaderMap;
                break;
            }
        }

        if ($headerIndex === null) {
            foreach ($expectedColumns as $columnName) {
                $this->addImportError(
                    $report,
                    $sheetName,
                    0,
                    $columnName,
                    \__('Missing expected column in workbook header.', 'must-hotel-booking')
                );
            }

            return [];
        }

        $normalizedRows = [];

        foreach ($sheetRows as $rowIndex => $sheetRow) {
            if ((int) $rowIndex <= $headerIndex || !\is_array($sheetRow)) {
                continue;
            }

            $values = [];

            foreach ($expectedColumns as $columnName) {
                $values[$columnName] = isset($headerMap[$columnName], $sheetRow[$headerMap[$columnName]])
                    ? \trim((string) $sheetRow[$headerMap[$columnName]])
                    : '';
            }

            $normalizedRows[] = [
                'row_number' => (int) $rowIndex + 1,
                'values' => $values,
            ];
        }

        return $normalizedRows;
    }

    /**
     * @param array<int, string> $sheetRow
     * @return array<string, int>
     */
    private function buildHeaderMap(array $sheetRow, array $aliases = []): array
    {
        $headerMap = [];

        foreach (\array_values($sheetRow) as $index => $value) {
            $headerName = \trim((string) $value);

            if ($headerName === '') {
                continue;
            }

            $canonicalHeader = $headerName;

            foreach ($aliases as $expectedHeader => $acceptedHeaders) {
                if (\in_array($headerName, $acceptedHeaders, true)) {
                    $canonicalHeader = $expectedHeader;
                    break;
                }
            }

            $headerMap[$canonicalHeader] = $index;
        }

        return $headerMap;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function validateAccommodationRow(array $values): array
    {
        $errors = [];
        $roomId = isset($values['id']) ? \absint($values['id']) : 0;
        $existingRoom = $roomId > 0 ? $this->roomRepository->getRoomById($roomId) : null;

        if ($roomId > 0 && !\is_array($existingRoom)) {
            $errors[] = [
                'field' => 'id',
                'message' => \__('No accommodation exists for the provided id.', 'must-hotel-booking'),
            ];
        }

        $existingRoom = \is_array($existingRoom) ? $existingRoom : null;

        $title = $this->parseRequiredTextField(
            $values['title'] ?? '',
            'title',
            $existingRoom !== null ? (string) ($existingRoom['name'] ?? '') : '',
            $errors
        );
        $accommodationType = $this->resolveAccommodationType(
            $values['accommodation_category'] ?? '',
            $existingRoom !== null ? (string) ($existingRoom['category'] ?? '') : '',
            $errors
        );

        $description = $this->parseTextField($values['description'] ?? '', $existingRoom !== null ? (string) ($existingRoom['description'] ?? '') : '', false);
        $internalCode = $this->parseTextField($values['internal_code'] ?? '', $existingRoom !== null ? (string) ($existingRoom['internal_code'] ?? '') : '', false);
        $roomSize = $this->parseTextField($values['size'] ?? '', $existingRoom !== null ? (string) ($existingRoom['room_size'] ?? '') : '', false);
        $bedType = $this->parseTextField($values['bed_type'] ?? '', $existingRoom !== null ? (string) ($existingRoom['beds'] ?? '') : '', false);
        $amenitiesIntro = $this->parseTextField(
            $values['amenities_intro'] ?? '',
            $existingRoom !== null ? $this->roomRepository->getRoomMetaTextValue($roomId, 'amenities_intro') : '',
            false
        );
        $roomRules = $this->parseTextField(
            $values['room_rules'] ?? '',
            $existingRoom !== null ? $this->roomRepository->getRoomMetaTextValue($roomId, 'room_rules') : '',
            false
        );
        $adminNotes = $this->parseTextField($values['admin_notes'] ?? '', $existingRoom !== null ? (string) ($existingRoom['admin_notes'] ?? '') : '', false);
        $amenityKeys = $this->parseAmenityField(
            $values['amenities'] ?? '',
            $existingRoom !== null ? $this->roomRepository->getRoomAmenities($roomId) : [],
            $errors
        );

        $maxAdults = $this->parseIntegerField(
            $values['max_adults'] ?? '',
            'max_adults',
            $existingRoom !== null ? (int) ($existingRoom['max_adults'] ?? 1) : 1,
            $errors,
            1
        );
        $maxChildren = $this->parseIntegerField(
            $values['max_children'] ?? '',
            'max_children',
            $existingRoom !== null ? (int) ($existingRoom['max_children'] ?? 0) : 0,
            $errors,
            0
        );
        $maxGuests = $this->parseIntegerField(
            $values['max_guests'] ?? '',
            'max_guests',
            $existingRoom !== null ? (int) ($existingRoom['max_guests'] ?? 1) : 1,
            $errors,
            1
        );
        $defaultOccupancy = $this->parseIntegerField(
            $values['default_occupancy'] ?? '',
            'default_occupancy',
            $existingRoom !== null ? (int) ($existingRoom['default_occupancy'] ?? 1) : 1,
            $errors,
            1
        );
        $sortOrder = $this->parseIntegerField(
            $values['sort_order'] ?? '',
            'sort_order',
            $existingRoom !== null ? (int) ($existingRoom['sort_order'] ?? 0) : 0,
            $errors,
            0
        );
        $basePrice = $this->parseDecimalField(
            $values['base_price'] ?? '',
            'base_price',
            $existingRoom !== null ? (float) ($existingRoom['base_price'] ?? 0.0) : 0.0,
            $errors,
            0.0
        );
        $extraGuestPrice = $this->parseDecimalField(
            $values['extra_guest_price'] ?? '',
            'extra_guest_price',
            $existingRoom !== null ? (float) ($existingRoom['extra_guest_price'] ?? 0.0) : 0.0,
            $errors,
            0.0
        );
        $isActive = $this->parseBooleanField(
            $values['active'] ?? '',
            'active',
            $existingRoom !== null ? !empty($existingRoom['is_active']) : true,
            $errors
        );
        $isBookable = $this->parseBooleanField(
            $values['bookable'] ?? '',
            'bookable',
            $existingRoom !== null ? !empty($existingRoom['is_bookable']) : true,
            $errors
        );
        $isOnlineBookable = $this->parseBooleanField(
            $values['online_bookable'] ?? '',
            'online_bookable',
            $existingRoom !== null ? !empty($existingRoom['is_online_bookable']) : true,
            $errors
        );
        $isCalendarVisible = $this->parseBooleanField(
            $values['calendar_visible'] ?? '',
            'calendar_visible',
            $existingRoom !== null ? !empty($existingRoom['is_calendar_visible']) : true,
            $errors
        );

        if ($maxGuests < $maxAdults) {
            $errors[] = [
                'field' => 'max_guests',
                'message' => \__('Max guests cannot be lower than max adults.', 'must-hotel-booking'),
            ];
        }

        if ($defaultOccupancy > $maxGuests) {
            $errors[] = [
                'field' => 'default_occupancy',
                'message' => \__('Default occupancy cannot exceed max guests.', 'must-hotel-booking'),
            ];
        }

        if ($maxChildren > 0 && $maxGuests > ($maxAdults + $maxChildren)) {
            $errors[] = [
                'field' => 'max_guests',
                'message' => \__('Max guests cannot exceed max adults plus max children.', 'must-hotel-booking'),
            ];
        }

        $slug = $existingRoom !== null
            ? $this->resolveExistingSlug($existingRoom, $title, $roomId)
            : generate_unique_room_slug($title, 0);

        return [
            'operation' => $roomId > 0 ? 'update' : 'create',
            'errors' => $errors,
            'payload' => [
                'id' => $roomId,
                'name' => $title,
                'slug' => $slug,
                'category' => $accommodationType,
                'description' => $description,
                'internal_code' => $internalCode,
                'is_active' => $isActive ? 1 : 0,
                'is_bookable' => $isBookable ? 1 : 0,
                'is_online_bookable' => $isOnlineBookable ? 1 : 0,
                'is_calendar_visible' => $isCalendarVisible ? 1 : 0,
                'sort_order' => $sortOrder,
                'max_adults' => $maxAdults,
                'max_children' => $maxChildren,
                'max_guests' => $maxGuests,
                'default_occupancy' => $defaultOccupancy,
                'base_price' => $basePrice,
                'extra_guest_price' => $extraGuestPrice,
                'room_size' => $roomSize,
                'beds' => $bedType,
                'room_rules' => $roomRules,
                'amenities_intro' => $amenitiesIntro,
                'amenity_keys' => $amenityKeys,
                'admin_notes' => $adminNotes,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistAccommodation(array $payload, bool $isUpdate): int
    {
        $roomId = isset($payload['id']) ? (int) $payload['id'] : 0;
        $mainImageId = $roomId > 0 ? $this->roomRepository->getRoomMainImageId($roomId) : 0;
        $galleryIds = $roomId > 0 ? $this->roomRepository->getRoomGalleryImageIds($roomId) : [];

        if ($isUpdate && $roomId > 0) {
            $saved = $this->roomRepository->updateRoom($roomId, $payload);

            if (!$saved) {
                return 0;
            }

            $savedId = $roomId;
        } else {
            $payload['created_at'] = \current_time('mysql');
            $savedId = $this->roomRepository->createRoom($payload);

            if ($savedId <= 0) {
                return 0;
            }
        }

        $this->roomRepository->saveRoomMeta(
            $savedId,
            $mainImageId,
            (string) ($payload['room_rules'] ?? ''),
            (string) ($payload['amenities_intro'] ?? ''),
            isset($payload['amenity_keys']) && \is_array($payload['amenity_keys']) ? $payload['amenity_keys'] : [],
            \is_array($galleryIds) ? $galleryIds : []
        );

        $this->inventoryRepository->syncRoomType(
            $savedId,
            [
                'name' => (string) ($payload['name'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
                'capacity' => (int) ($payload['max_guests'] ?? 1),
                'base_price' => (float) ($payload['base_price'] ?? 0.0),
            ]
        );
        $this->defaultInventoryUnitSyncService->syncRoomListing($savedId);

        return $savedId;
    }

    private function resolveExistingSlug(array $existingRoom, string $title, int $roomId): string
    {
        $existingSlug = \sanitize_title((string) ($existingRoom['slug'] ?? ''));

        if ($existingSlug !== '') {
            return $existingSlug;
        }

        return generate_unique_room_slug($title, $roomId);
    }

    /**
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function resolveAccommodationType(string $rawValue, string $defaultCategory, array &$errors): string
    {
        $value = \trim($rawValue);
        $categories = RoomCatalog::getCategories();

        if ($value === '') {
            if ($defaultCategory !== '' && isset($categories[$defaultCategory])) {
                return $defaultCategory;
            }

            $errors[] = [
                'field' => 'accommodation_category',
                'message' => \__('Accommodation category is required.', 'must-hotel-booking'),
            ];

            return '';
        }

        $slugCandidate = \sanitize_key(\str_replace([' ', '_'], '-', $value));

        if ($slugCandidate !== '' && isset($categories[$slugCandidate])) {
            return $slugCandidate;
        }

        $lookupLabel = $this->normalizeLookupName($value);

        foreach ($categories as $slug => $label) {
            if ($this->normalizeLookupName((string) $label) === $lookupLabel) {
                return (string) $slug;
            }
        }

        $errors[] = [
            'field' => 'accommodation_category',
            'message' => \sprintf(
                /* translators: %s: allowed accommodation categories */
                \__('Use one of the existing accommodation categories: %s.', 'must-hotel-booking'),
                \implode(', ', \array_values($categories))
            ),
        ];

        return '';
    }

    private function normalizeLookupName(string $value): string
    {
        $normalized = \function_exists('mb_strtolower')
            ? \mb_strtolower($value)
            : \strtolower($value);

        return \trim($normalized);
    }

    private function formatAmenityLabelsForWorkbook(array $amenityKeys): string
    {
        if (empty($amenityKeys)) {
            return '';
        }

        $availableAmenities = RoomCatalog::getAvailableAmenities();
        $labels = [];

        foreach ($amenityKeys as $amenityKey) {
            $key = (string) $amenityKey;

            if ($key === '') {
                continue;
            }

            if (isset($availableAmenities[$key]['label'])) {
                $labels[] = (string) $availableAmenities[$key]['label'];
                continue;
            }

            $labels[] = $key;
        }

        return \implode(', ', $labels);
    }

    /**
     * @param array<int, string> $default
     * @param array<int, array{field: string, message: string}> $errors
     * @return array<int, string>
     */
    private function parseAmenityField(string $rawValue, array $default, array &$errors): array
    {
        $value = \trim($rawValue);

        if ($value === '') {
            return [];
        }

        $amenityKeys = parse_room_amenity_keys($value);

        if ($value !== '' && empty($amenityKeys)) {
            $errors[] = [
                'field' => 'amenities',
                'message' => \__('Use existing amenity labels or keys separated by commas.', 'must-hotel-booking'),
            ];

            return $default;
        }

        return $amenityKeys;
    }

    private function parseRequiredTextField(string $rawValue, string $field, string $default, array &$errors): string
    {
        $value = \sanitize_text_field($rawValue);

        if ($value !== '') {
            return $value;
        }

        if ($default !== '') {
            return \sanitize_text_field($default);
        }

        $errors[] = [
            'field' => $field,
            'message' => \__('This column is required.', 'must-hotel-booking'),
        ];

        return '';
    }

    private function parseTextField(string $rawValue, string $default, bool $preserveWhenBlank = false): string
    {
        $value = \sanitize_textarea_field($rawValue);

        if ($value === '' && $preserveWhenBlank) {
            return \sanitize_textarea_field($default);
        }

        return $value;
    }

    /**
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function parseIntegerField(string $rawValue, string $field, int $default, array &$errors, int $minimum): int
    {
        $value = \trim($rawValue);

        if ($value === '') {
            return \max($minimum, $default);
        }

        if (\preg_match('/^-?\d+$/', $value) !== 1) {
            $errors[] = [
                'field' => $field,
                'message' => \__('Use a whole number value for this column.', 'must-hotel-booking'),
            ];

            return \max($minimum, $default);
        }

        $parsed = (int) $value;

        if ($parsed < $minimum) {
            $errors[] = [
                'field' => $field,
                'message' => \sprintf(
                    /* translators: %d: minimum value */
                    \__('The value must be %d or greater.', 'must-hotel-booking'),
                    $minimum
                ),
            ];
        }

        return \max($minimum, $parsed);
    }

    /**
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function parseDecimalField(string $rawValue, string $field, float $default, array &$errors, float $minimum): float
    {
        $value = \trim($rawValue);

        if ($value === '') {
            return \max($minimum, $default);
        }

        $normalized = \str_replace(',', '.', $value);

        if (!\is_numeric($normalized)) {
            $errors[] = [
                'field' => $field,
                'message' => \__('Use a numeric value for this column.', 'must-hotel-booking'),
            ];

            return \max($minimum, $default);
        }

        $parsed = \round((float) $normalized, 2);

        if ($parsed < $minimum) {
            $errors[] = [
                'field' => $field,
                'message' => \__('The value cannot be lower than 0.', 'must-hotel-booking'),
            ];
        }

        return \max($minimum, $parsed);
    }

    /**
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function parseBooleanField(string $rawValue, string $field, bool $default, array &$errors): bool
    {
        $value = $this->normalizeLookupName($rawValue);

        if ($value === '') {
            return $default;
        }

        if (\in_array($value, ['1', 'yes', 'true', 'on'], true)) {
            return true;
        }

        if (\in_array($value, ['0', 'no', 'false', 'off'], true)) {
            return false;
        }

        $errors[] = [
            'field' => $field,
            'message' => \__('Use yes/no, true/false, or 1/0 for this column.', 'must-hotel-booking'),
        ];

        return $default;
    }

    /**
     * @param array<string, string> $values
     */
    private function isBlankImportRow(array $values): bool
    {
        foreach ($values as $value) {
            if (\trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function determineReportStatus(array $report): string
    {
        $failedRows = isset($report['rows_failed']) ? (int) $report['rows_failed'] : 0;
        $errorCount = isset($report['errors']) && \is_array($report['errors']) ? \count($report['errors']) : 0;
        $successfulRows = (int) ($report['accommodations_created'] ?? 0)
            + (int) ($report['accommodations_updated'] ?? 0);

        if (($failedRows > 0 || $errorCount > 0) && $successfulRows === 0) {
            return 'error';
        }

        if ($failedRows > 0 || $errorCount > 0) {
            return 'warning';
        }

        return 'success';
    }

    /**
     * @param array<string, mixed> $report
     */
    private function addImportError(array &$report, string $sheet, int $rowNumber, string $field, string $message): void
    {
        if ($message === '') {
            return;
        }

        if (!isset($report['errors']) || !\is_array($report['errors'])) {
            $report['errors'] = [];
        }

        $report['errors'][] = [
            'sheet' => $sheet,
            'row' => $rowNumber,
            'field' => $field,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildErrorReport(string $message, string $fileName = ''): array
    {
        return [
            'file_name' => $fileName,
            'imported_at' => \current_time('mysql'),
            'accommodations_created' => 0,
            'accommodations_updated' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 1,
            'errors' => [
                [
                    'sheet' => '',
                    'row' => 0,
                    'field' => '',
                    'message' => $message,
                ],
            ],
            'status' => 'error',
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function storeImportReport(array $report): void
    {
        $userId = \get_current_user_id();

        if ($userId <= 0) {
            return;
        }

        \set_transient(self::IMPORT_REPORT_TRANSIENT_PREFIX . $userId, $report, \HOUR_IN_SECONDS);
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
}
