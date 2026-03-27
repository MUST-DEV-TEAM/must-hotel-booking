<?php

namespace MustHotelBooking\Admin;

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

    private SimpleXlsxWorkbook $workbook;

    public function __construct()
    {
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->inventoryRepository = \MustHotelBooking\Engine\get_inventory_repository();
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
                'tab' => 'types',
                'notice' => $template ? 'workbook_template_failed' : 'workbook_export_failed',
            ]);
        }

        $downloadName = $template
            ? 'must-hotel-booking-room-units-template.xlsx'
            : 'must-hotel-booking-room-units-' . \gmdate('Ymd-His') . '.xlsx';

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
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>,
     *     protect?: bool
     * }>
     */
    private function buildExportSheets(): array
    {
        return [
            [
                'name' => AccommodationWorkbookSchema::getUnitSheetName(),
                'rows' => $this->buildAccommodationUnitSheetRows(),
            ],
            [
                'name' => AccommodationWorkbookSchema::getReferenceSheetName(),
                'rows' => $this->buildAccommodationTypeReferenceSheetRows(),
                'protect' => true,
            ],
        ];
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>,
     *     protect?: bool
     * }>
     */
    private function buildTemplateSheets(): array
    {
        return [
            [
                'name' => AccommodationWorkbookSchema::getUnitSheetName(),
                'rows' => [$this->buildStyledHeaderRow(AccommodationWorkbookSchema::getUnitSheetColumns())],
            ],
            [
                'name' => AccommodationWorkbookSchema::getReferenceSheetName(),
                'rows' => $this->buildAccommodationTypeReferenceSheetRows(),
                'protect' => true,
            ],
        ];
    }

    /**
     * @return array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>
     */
    private function buildAccommodationUnitSheetRows(): array
    {
        $columns = AccommodationWorkbookSchema::getUnitSheetColumns();
        $rows = [$this->buildStyledHeaderRow($columns)];
        $typeNameIndex = [];

        foreach ($this->roomRepository->getRoomsListRows() as $typeRow) {
            if (!\is_array($typeRow)) {
                continue;
            }

            $typeId = isset($typeRow['id']) ? (int) $typeRow['id'] : 0;

            if ($typeId > 0) {
                $typeNameIndex[$typeId] = (string) ($typeRow['name'] ?? '');
            }
        }

        foreach ($this->inventoryRepository->getInventoryUnitAdminRows() as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomTypeId = (int) ($row['room_type_id'] ?? 0);

            $record = [
                'id' => (int) ($row['id'] ?? 0),
                'room_type_id' => $roomTypeId,
                'room_type_name' => (string) ($typeNameIndex[$roomTypeId] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'room_number' => (string) ($row['room_number'] ?? ''),
                'floor' => (int) ($row['floor'] ?? 0),
                'status' => (string) ($row['status'] ?? 'available'),
                'is_active' => !empty($row['is_active']) ? 1 : 0,
                'is_bookable' => !empty($row['is_bookable']) ? 1 : 0,
                'is_calendar_visible' => !empty($row['is_calendar_visible']) ? 1 : 0,
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'capacity_override' => (int) ($row['capacity_override'] ?? 0),
                'building' => (string) ($row['building'] ?? ''),
                'section' => (string) ($row['section'] ?? ''),
                'admin_notes' => (string) ($row['admin_notes'] ?? ''),
            ];

            $rows[] = $this->buildOrderedRow($columns, $record);
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>
     */
    private function buildAccommodationTypeReferenceSheetRows(): array
    {
        $columns = AccommodationWorkbookSchema::getReferenceSheetColumns();
        $rows = [
            $this->buildStyledWarningRow(
                AccommodationWorkbookSchema::getReferenceSheetWarning(),
                \count($columns)
            ),
            $this->buildStyledHeaderRow($columns),
        ];

        foreach ($this->roomRepository->getRoomsListRows() as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $record = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'status' => !empty($row['is_active'])
                    ? \__('Active', 'must-hotel-booking')
                    : \__('Inactive', 'must-hotel-booking'),
                'max_guests' => (int) ($row['max_guests'] ?? 0),
                'base_price' => (float) ($row['base_price'] ?? 0),
                'beds' => (string) ($row['beds'] ?? ''),
                'room_size' => (string) ($row['room_size'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
            ];

            $rows[] = $this->buildOrderedRow($columns, $record);
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

    /**
     * @param array<string, array<int, array<int, string>>> $sheets
     * @return array<string, mixed>
     */
    private function importWorkbook(array $sheets, string $fileName): array
    {
        $report = [
            'file_name' => $fileName,
            'imported_at' => \current_time('mysql'),
            'units_created' => 0,
            'units_updated' => 0,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'errors' => [],
            'status' => 'success',
        ];

        $unitRows = $this->normalizeSheetRows(
            $sheets,
            AccommodationWorkbookSchema::getUnitSheetName(),
            AccommodationWorkbookSchema::getUnitSheetColumns(),
            $report
        );

        $roomTypeLookup = $this->buildRoomTypeLookup();

        foreach ($unitRows as $sheetRow) {
            $rowNumber = (int) ($sheetRow['row_number'] ?? 0);
            $values = isset($sheetRow['values']) && \is_array($sheetRow['values']) ? $sheetRow['values'] : [];

            if ($this->isBlankImportRow($values)) {
                $report['rows_skipped'] = (int) $report['rows_skipped'] + 1;
                continue;
            }

            $validation = $this->validateAccommodationUnitRow($values, $roomTypeLookup);
            $rowErrors = isset($validation['errors']) && \is_array($validation['errors']) ? $validation['errors'] : [];

            if (!empty($rowErrors)) {
                $report['rows_failed'] = (int) $report['rows_failed'] + 1;

                foreach ($rowErrors as $error) {
                    $this->addImportError(
                        $report,
                        AccommodationWorkbookSchema::getUnitSheetName(),
                        $rowNumber,
                        (string) ($error['field'] ?? ''),
                        (string) ($error['message'] ?? '')
                    );
                }

                continue;
            }

            $payload = isset($validation['payload']) && \is_array($validation['payload']) ? $validation['payload'] : [];
            $operation = (string) ($validation['operation'] ?? 'create');
            $savedId = $this->persistAccommodationUnit($payload, $operation === 'update');

            if ($savedId <= 0) {
                $report['rows_failed'] = (int) $report['rows_failed'] + 1;
                $this->addImportError(
                    $report,
                    AccommodationWorkbookSchema::getUnitSheetName(),
                    $rowNumber,
                    'id',
                    \__('The accommodation unit could not be saved.', 'must-hotel-booking')
                );
                continue;
            }

            if ($operation === 'update') {
                $report['units_updated'] = (int) $report['units_updated'] + 1;
            } else {
                $report['units_created'] = (int) $report['units_created'] + 1;
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

        if (empty($sheetRows) || !isset($sheetRows[0]) || !\is_array($sheetRows[0])) {
            $this->addImportError(
                $report,
                $sheetName,
                0,
                '',
                \__('The workbook sheet is empty and does not include a header row.', 'must-hotel-booking')
            );

            return [];
        }

        $headerRow = \array_map(
            static function ($value): string {
                return \trim((string) $value);
            },
            \array_values($sheetRows[0])
        );
        $headerMap = [];

        foreach ($headerRow as $index => $headerName) {
            if ($headerName === '') {
                continue;
            }

            $headerMap[$headerName] = $index;
        }

        $missingColumns = [];

        foreach ($expectedColumns as $columnName) {
            if (!isset($headerMap[$columnName])) {
                $missingColumns[] = $columnName;
            }
        }

        if (!empty($missingColumns)) {
            foreach ($missingColumns as $missingColumn) {
                $this->addImportError(
                    $report,
                    $sheetName,
                    1,
                    $missingColumn,
                    \__('Missing expected column in workbook header.', 'must-hotel-booking')
                );
            }

            return [];
        }

        $normalizedRows = [];

        foreach (\array_slice($sheetRows, 1, null, true) as $rowIndex => $sheetRow) {
            if (!\is_array($sheetRow)) {
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
     * @param array<string, mixed> $roomTypeLookup
     * @return array<string, mixed>
     */
    private function validateAccommodationUnitRow(array $values, array $roomTypeLookup): array
    {
        $errors = [];
        $unitId = isset($values['id']) ? \absint($values['id']) : 0;
        $existingUnit = $unitId > 0 ? $this->inventoryRepository->getInventoryRoomById($unitId) : null;

        if ($unitId > 0 && !\is_array($existingUnit)) {
            $errors[] = [
                'field' => 'id',
                'message' => \__('No accommodation unit exists for the provided id.', 'must-hotel-booking'),
            ];
        }

        $resolvedTypeId = $this->resolveRoomTypeIdForUnit($values, $roomTypeLookup, $existingUnit, $errors);
        $linkedType = $resolvedTypeId > 0 ? $this->roomRepository->getRoomById($resolvedTypeId) : null;

        if ($resolvedTypeId <= 0 || !\is_array($linkedType)) {
            $errors[] = [
                'field' => 'room_type_id',
                'message' => \__('Use a valid room_type_id or a unique room_type_name that matches an existing accommodation type.', 'must-hotel-booking'),
            ];
        }

        $title = \sanitize_text_field((string) ($values['title'] ?? ''));

        if ($title === '' && \is_array($existingUnit)) {
            $title = \sanitize_text_field((string) ($existingUnit['title'] ?? ''));
        }

        if ($title === '') {
            $errors[] = [
                'field' => 'title',
                'message' => \__('Accommodation unit title is required.', 'must-hotel-booking'),
            ];
        }

        $roomNumber = \sanitize_text_field((string) ($values['room_number'] ?? ''));

        if ($roomNumber === '' && \is_array($existingUnit)) {
            $roomNumber = \sanitize_text_field((string) ($existingUnit['room_number'] ?? ''));
        }

        if ($roomNumber === '') {
            $errors[] = [
                'field' => 'room_number',
                'message' => \__('Accommodation unit room_number is required.', 'must-hotel-booking'),
            ];
        } elseif ($this->inventoryRepository->roomNumberExists($roomNumber, $unitId)) {
            $errors[] = [
                'field' => 'room_number',
                'message' => \__('Accommodation unit room_number must be unique.', 'must-hotel-booking'),
            ];
        }

        $status = \sanitize_key((string) ($values['status'] ?? ''));

        if ($status === '' && \is_array($existingUnit)) {
            $status = \sanitize_key((string) ($existingUnit['status'] ?? 'available'));
        }

        if ($status === '') {
            $status = 'available';
        }

        $allowedStatuses = ['available', 'maintenance', 'out_of_service', 'blocked'];

        if (!\in_array($status, $allowedStatuses, true)) {
            $errors[] = [
                'field' => 'status',
                'message' => \__('Use one of the allowed status values: available, maintenance, out_of_service, blocked.', 'must-hotel-booking'),
            ];
        }

        $floor = $this->parseIntegerField(
            $values['floor'] ?? '',
            'floor',
            \is_array($existingUnit) ? (int) ($existingUnit['floor'] ?? 0) : 0,
            $errors
        );
        $sortOrder = $this->parseIntegerField(
            $values['sort_order'] ?? '',
            'sort_order',
            \is_array($existingUnit) ? (int) ($existingUnit['sort_order'] ?? 0) : 0,
            $errors
        );
        $capacityOverride = $this->parseIntegerField(
            $values['capacity_override'] ?? '',
            'capacity_override',
            \is_array($existingUnit) ? (int) ($existingUnit['capacity_override'] ?? 0) : 0,
            $errors,
            0
        );
        $isActive = $this->parseBinaryField(
            $values['is_active'] ?? '',
            'is_active',
            \is_array($existingUnit) ? (int) ($existingUnit['is_active'] ?? 1) : 1,
            $errors
        );
        $isBookable = $this->parseBinaryField(
            $values['is_bookable'] ?? '',
            'is_bookable',
            \is_array($existingUnit) ? (int) ($existingUnit['is_bookable'] ?? 1) : 1,
            $errors
        );
        $isCalendarVisible = $this->parseBinaryField(
            $values['is_calendar_visible'] ?? '',
            'is_calendar_visible',
            \is_array($existingUnit) ? (int) ($existingUnit['is_calendar_visible'] ?? 1) : 1,
            $errors
        );

        if (\is_array($linkedType) && $capacityOverride > 0 && $capacityOverride > (int) ($linkedType['max_guests'] ?? 0)) {
            $errors[] = [
                'field' => 'capacity_override',
                'message' => \__('Capacity override cannot exceed the linked accommodation type max guests.', 'must-hotel-booking'),
            ];
        }

        return [
            'operation' => $unitId > 0 ? 'update' : 'create',
            'errors' => $errors,
            'payload' => [
                'id' => $unitId,
                'room_type_id' => $resolvedTypeId,
                'title' => $title,
                'room_number' => $roomNumber,
                'floor' => $floor,
                'status' => $status,
                'is_active' => $isActive,
                'is_bookable' => $isBookable,
                'is_calendar_visible' => $isCalendarVisible,
                'sort_order' => $sortOrder,
                'capacity_override' => $capacityOverride,
                'building' => \sanitize_text_field((string) ($values['building'] ?? '')),
                'section' => \sanitize_text_field((string) ($values['section'] ?? '')),
                'admin_notes' => \sanitize_textarea_field((string) ($values['admin_notes'] ?? '')),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $roomTypeLookup
     * @param array<string, mixed>|null $existingUnit
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function resolveRoomTypeIdForUnit(array $values, array $roomTypeLookup, ?array $existingUnit, array &$errors): int
    {
        $roomTypeId = isset($values['room_type_id']) ? \absint($values['room_type_id']) : 0;

        if ($roomTypeId > 0) {
            return $roomTypeId;
        }

        $roomTypeName = \trim((string) ($values['room_type_name'] ?? ''));

        if ($roomTypeName !== '') {
            $normalizedName = $this->normalizeLookupName($roomTypeName);
            $ids = isset($roomTypeLookup['by_name'][$normalizedName]) && \is_array($roomTypeLookup['by_name'][$normalizedName])
                ? $roomTypeLookup['by_name'][$normalizedName]
                : [];

            if (\count($ids) === 1) {
                return (int) $ids[0];
            }

            if (\count($ids) > 1) {
                $errors[] = [
                    'field' => 'room_type_name',
                    'message' => \__('room_type_name matched multiple accommodation types. Use room_type_id instead.', 'must-hotel-booking'),
                ];
            }
        }

        return \is_array($existingUnit) ? (int) ($existingUnit['room_type_id'] ?? 0) : 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistAccommodationUnit(array $payload, bool $isUpdate): int
    {
        $unitId = isset($payload['id']) ? (int) $payload['id'] : 0;

        if ($isUpdate && $unitId > 0) {
            return $this->inventoryRepository->updateInventoryRoom($unitId, $payload) ? $unitId : 0;
        }

        return $this->inventoryRepository->createInventoryRoom($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRoomTypeLookup(): array
    {
        $lookup = [
            'by_id' => [],
            'by_name' => [],
        ];

        foreach ($this->roomRepository->getRoomsListRows() as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $roomId = isset($row['id']) ? (int) $row['id'] : 0;
            $roomName = isset($row['name']) ? (string) $row['name'] : '';

            if ($roomId <= 0 || $roomName === '') {
                continue;
            }

            $lookup['by_id'][$roomId] = $row;
            $lookupName = $this->normalizeLookupName($roomName);

            if (!isset($lookup['by_name'][$lookupName])) {
                $lookup['by_name'][$lookupName] = [];
            }

            $lookup['by_name'][$lookupName][] = $roomId;
        }

        return $lookup;
    }

    private function normalizeLookupName(string $value): string
    {
        $normalized = \function_exists('mb_strtolower')
            ? \mb_strtolower($value)
            : \strtolower($value);

        return \trim($normalized);
    }

    /**
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function parseIntegerField(string $rawValue, string $field, int $default, array &$errors, ?int $minimum = null): int
    {
        $value = \trim($rawValue);

        if ($value === '') {
            return $minimum !== null ? \max($minimum, $default) : $default;
        }

        if (\preg_match('/^-?\d+$/', $value) !== 1) {
            $errors[] = [
                'field' => $field,
                'message' => \__('Use a whole number value for this column.', 'must-hotel-booking'),
            ];

            return $default;
        }

        $parsed = (int) $value;

        if ($minimum !== null && $parsed < $minimum) {
            $errors[] = [
                'field' => $field,
                'message' => \sprintf(
                    /* translators: %d: minimum value */
                    \__('The value must be %d or greater.', 'must-hotel-booking'),
                    $minimum
                ),
            ];
        }

        return $minimum !== null ? \max($minimum, $parsed) : $parsed;
    }

    /**
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function parseBinaryField(string $rawValue, string $field, int $default, array &$errors): int
    {
        $value = \trim($rawValue);

        if ($value === '') {
            return $default === 0 ? 0 : 1;
        }

        if (!\in_array($value, ['0', '1'], true)) {
            $errors[] = [
                'field' => $field,
                'message' => \__('Use 1 for enabled/present and 0 for disabled/absent.', 'must-hotel-booking'),
            ];

            return $default === 0 ? 0 : 1;
        }

        return (int) $value;
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
        $successfulRows = (int) ($report['units_created'] ?? 0)
            + (int) ($report['units_updated'] ?? 0);

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
            'units_created' => 0,
            'units_updated' => 0,
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
