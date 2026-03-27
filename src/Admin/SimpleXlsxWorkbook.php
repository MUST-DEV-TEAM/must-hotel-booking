<?php

namespace MustHotelBooking\Admin;

final class SimpleXlsxWorkbook
{
    public const STYLE_DEFAULT = 0;
    public const STYLE_WARNING = 1;
    public const STYLE_HEADER = 2;

    /**
     * @param array<int, array{
     *     name: string,
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>,
     *     protect?: bool
     * }> $sheets
     */
    public function createTemporaryWorkbook(array $sheets): string
    {
        $this->ensureArchiveLibrary();

        $archivePath = \wp_tempnam('must-hotel-booking-accommodations.xlsx');

        if (!\is_string($archivePath) || $archivePath === '') {
            throw new \RuntimeException(\__('Unable to allocate a temporary workbook file.', 'must-hotel-booking'));
        }

        if (\file_exists($archivePath)) {
            @\unlink($archivePath);
        }

        $archive = new \PclZip($archivePath);
        $entries = [
            [
                \PCLZIP_ATT_FILE_NAME => '[Content_Types].xml',
                \PCLZIP_ATT_FILE_CONTENT => $this->buildContentTypesXml($sheets),
            ],
            [
                \PCLZIP_ATT_FILE_NAME => '_rels/.rels',
                \PCLZIP_ATT_FILE_CONTENT => $this->buildPackageRelationshipsXml(),
            ],
            [
                \PCLZIP_ATT_FILE_NAME => 'xl/workbook.xml',
                \PCLZIP_ATT_FILE_CONTENT => $this->buildWorkbookXml($sheets),
            ],
            [
                \PCLZIP_ATT_FILE_NAME => 'xl/_rels/workbook.xml.rels',
                \PCLZIP_ATT_FILE_CONTENT => $this->buildWorkbookRelationshipsXml($sheets),
            ],
            [
                \PCLZIP_ATT_FILE_NAME => 'xl/styles.xml',
                \PCLZIP_ATT_FILE_CONTENT => $this->buildStylesXml(),
            ],
        ];

        foreach ($sheets as $index => $sheet) {
            $entries[] = [
                \PCLZIP_ATT_FILE_NAME => 'xl/worksheets/sheet' . ($index + 1) . '.xml',
                \PCLZIP_ATT_FILE_CONTENT => $this->buildWorksheetXml(
                    isset($sheet['rows']) && \is_array($sheet['rows']) ? $sheet['rows'] : [],
                    !empty($sheet['protect'])
                ),
            ];
        }

        $created = $archive->create($entries);

        if ($created === 0 || !\file_exists($archivePath)) {
            @\unlink($archivePath);

            throw new \RuntimeException(
                \sprintf(
                    /* translators: %s: archive error message */
                    \__('Unable to build workbook archive: %s', 'must-hotel-booking'),
                    \method_exists($archive, 'errorInfo') ? (string) $archive->errorInfo(true) : \__('Unknown archive error', 'must-hotel-booking')
                )
            );
        }

        return $archivePath;
    }

    /**
     * @return array<string, array<int, array<int, string>>>
     */
    public function parseWorkbook(string $filePath): array
    {
        $this->ensureArchiveLibrary();

        if (!\is_file($filePath)) {
            throw new \RuntimeException(\__('The uploaded workbook could not be found.', 'must-hotel-booking'));
        }

        $archive = new \PclZip($filePath);
        $contents = $archive->listContent();

        if (!\is_array($contents) || empty($contents)) {
            throw new \RuntimeException(\__('The uploaded workbook could not be read as a valid .xlsx file.', 'must-hotel-booking'));
        }

        $workbookXml = $this->getArchiveEntryContents($archive, 'xl/workbook.xml');
        $workbookRelationshipsXml = $this->getArchiveEntryContents($archive, 'xl/_rels/workbook.xml.rels');
        $sharedStringsXml = $this->getArchiveEntryContents($archive, 'xl/sharedStrings.xml', false);
        $sharedStrings = $sharedStringsXml !== '' ? $this->parseSharedStrings($sharedStringsXml) : [];
        $sheetTargets = $this->parseSheetTargets($workbookRelationshipsXml);
        $sheetDefinitions = $this->parseWorkbookSheets($workbookXml, $sheetTargets);
        $sheets = [];

        foreach ($sheetDefinitions as $sheetName => $sheetPath) {
            $worksheetXml = $this->getArchiveEntryContents($archive, $sheetPath);
            $sheets[$sheetName] = $this->parseWorksheetRows($worksheetXml, $sharedStrings);
        }

        return $sheets;
    }

    private function ensureArchiveLibrary(): void
    {
        if (!\class_exists('PclZip', false)) {
            require_once \ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        if (!\class_exists('PclZip', false)) {
            throw new \RuntimeException(\__('The workbook archive library is unavailable on this site.', 'must-hotel-booking'));
        }
    }

    /**
     * @param array<int, array{
     *     name: string,
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>,
     *     protect?: bool
     * }> $sheets
     */
    private function buildContentTypesXml(array $sheets): string
    {
        $overrides = [
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
        ];

        foreach ($sheets as $index => $sheet) {
            unset($sheet);
            $overrides[] = '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . \implode('', $overrides)
            . '</Types>';
    }

    private function buildPackageRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /**
     * @param array<int, array{
     *     name: string,
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>,
     *     protect?: bool
     * }> $sheets
     */
    private function buildWorkbookXml(array $sheets): string
    {
        $sheetXml = [];

        foreach ($sheets as $index => $sheet) {
            $sheetXml[] = '<sheet name="' . $this->escapeXmlAttribute((string) ($sheet['name'] ?? 'Sheet ' . ($index + 1))) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . \implode('', $sheetXml) . '</sheets>'
            . '</workbook>';
    }

    /**
     * @param array<int, array{
     *     name: string,
     *     rows: array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>>,
     *     protect?: bool
     * }> $sheets
     */
    private function buildWorkbookRelationshipsXml(array $sheets): string
    {
        $relationships = [];

        foreach ($sheets as $index => $sheet) {
            unset($sheet);
            $relationships[] = '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }

        $relationships[] = '<Relationship Id="rId' . (\count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . \implode('', $relationships)
            . '</Relationships>';
    }

    private function buildStylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF1F1F1F"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFB42318"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEAE6DB"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param array<int, array<int, array{value?: bool|float|int|string|null, style_id?: int}|bool|float|int|string|null>> $rows
     */
    private function buildWorksheetXml(array $rows, bool $protect = false): string
    {
        $rowXml = [];
        $maxColumns = 1;

        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $cells = [];
            $columnCount = \max(1, \count($row));

            if ($columnCount > $maxColumns) {
                $maxColumns = $columnCount;
            }

            foreach (\array_values($row) as $columnIndex => $value) {
                $cellRef = $this->columnNumberToName($columnIndex + 1) . $rowNumber;
                $styleId = self::STYLE_DEFAULT;

                if (\is_array($value)) {
                    $styleId = isset($value['style_id']) ? (int) $value['style_id'] : self::STYLE_DEFAULT;
                    $value = $value['value'] ?? '';
                }

                $cells[] = $this->buildWorksheetCellXml($cellRef, $value, $styleId);
            }

            $rowXml[] = '<row r="' . $rowNumber . '">' . \implode('', $cells) . '</row>';
        }

        $lastCell = $this->columnNumberToName($maxColumns) . \max(1, \count($rows));
        $sheetProtectionXml = $protect
            ? '<sheetProtection sheet="1" objects="1" scenarios="1"/>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastCell . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $sheetProtectionXml
            . '<sheetData>' . \implode('', $rowXml) . '</sheetData>'
            . '</worksheet>';
    }

    /**
     * @param bool|float|int|string|null $value
     */
    private function buildWorksheetCellXml(string $cellReference, $value, int $styleId = self::STYLE_DEFAULT): string
    {
        $styleAttribute = $styleId > self::STYLE_DEFAULT ? ' s="' . $styleId . '"' : '';

        if (\is_bool($value)) {
            return '<c r="' . $cellReference . '"' . $styleAttribute . ' t="b"><v>' . ($value ? '1' : '0') . '</v></c>';
        }

        if (\is_int($value) || \is_float($value)) {
            return '<c r="' . $cellReference . '"' . $styleAttribute . '><v>' . $value . '</v></c>';
        }

        if ($value === null) {
            $value = '';
        }

        $stringValue = (string) $value;

        return '<c r="' . $cellReference . '"' . $styleAttribute . ' t="inlineStr"><is><t xml:space="preserve">' . $this->escapeXmlText($stringValue) . '</t></is></c>';
    }

    private function getArchiveEntryContents(\PclZip $archive, string $entryName, bool $required = true): string
    {
        $result = $archive->extract(
            \PCLZIP_OPT_BY_NAME,
            $entryName,
            \PCLZIP_OPT_EXTRACT_AS_STRING
        );

        if (!\is_array($result) || !isset($result[0]['content'])) {
            if ($required) {
                throw new \RuntimeException(
                    \sprintf(
                        /* translators: %s: workbook file path inside archive */
                        \__('Missing required workbook part: %s', 'must-hotel-booking'),
                        $entryName
                    )
                );
            }

            return '';
        }

        return (string) $result[0]['content'];
    }

    /**
     * @return array<int, string>
     */
    private function parseSharedStrings(string $xml): array
    {
        $document = $this->loadXmlDocument($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];

        foreach ($xpath->query('//x:si') as $sharedStringItem) {
            if (!$sharedStringItem instanceof \DOMElement) {
                continue;
            }

            $text = '';

            foreach ($xpath->query('.//x:t', $sharedStringItem) as $textNode) {
                $text .= $textNode instanceof \DOMNode ? $textNode->textContent : '';
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function parseSheetTargets(string $xml): array
    {
        $document = $this->loadXmlDocument($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $targets = [];

        foreach ($xpath->query('//r:Relationship') as $relationshipNode) {
            if (!$relationshipNode instanceof \DOMElement) {
                continue;
            }

            $relationshipId = (string) $relationshipNode->getAttribute('Id');
            $target = (string) $relationshipNode->getAttribute('Target');

            if ($relationshipId === '' || $target === '') {
                continue;
            }

            if (\strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . \ltrim($target, '/');
            }

            $targets[$relationshipId] = $target;
        }

        return $targets;
    }

    /**
     * @param array<string, string> $targets
     * @return array<string, string>
     */
    private function parseWorkbookSheets(string $xml, array $targets): array
    {
        $document = $this->loadXmlDocument($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = [];

        foreach ($xpath->query('//x:sheets/x:sheet') as $sheetNode) {
            if (!$sheetNode instanceof \DOMElement) {
                continue;
            }

            $sheetName = (string) $sheetNode->getAttribute('name');
            $relationshipId = (string) $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');

            if ($sheetName === '' || $relationshipId === '' || !isset($targets[$relationshipId])) {
                continue;
            }

            $sheets[$sheetName] = $targets[$relationshipId];
        }

        return $sheets;
    }

    /**
     * @param array<int, string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function parseWorksheetRows(string $xml, array $sharedStrings): array
    {
        $document = $this->loadXmlDocument($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];

        foreach ($xpath->query('//x:sheetData/x:row') as $rowNode) {
            if (!$rowNode instanceof \DOMElement) {
                continue;
            }

            $rowReference = (int) $rowNode->getAttribute('r');
            $rowValues = [];

            foreach ($xpath->query('./x:c', $rowNode) as $cellNode) {
                if (!$cellNode instanceof \DOMElement) {
                    continue;
                }

                $reference = (string) $cellNode->getAttribute('r');
                $columnIndex = $this->extractColumnIndexFromReference($reference);

                if ($columnIndex <= 0) {
                    $columnIndex = \count($rowValues) + 1;
                }

                $type = (string) $cellNode->getAttribute('t');
                $rowValues[$columnIndex - 1] = $this->extractCellValue($xpath, $cellNode, $type, $sharedStrings);
            }

            if (!empty($rowValues)) {
                \ksort($rowValues);
                $maxIndex = \max(\array_keys($rowValues));
                $normalizedRow = \array_fill(0, $maxIndex + 1, '');

                foreach ($rowValues as $columnIndex => $value) {
                    $normalizedRow[$columnIndex] = $value;
                }

                if ($rowReference > 0) {
                    $rows[$rowReference - 1] = $normalizedRow;
                } else {
                    $rows[] = $normalizedRow;
                }
            }
        }

        \ksort($rows);

        return $rows;
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private function extractCellValue(\DOMXPath $xpath, \DOMElement $cellNode, string $type, array $sharedStrings): string
    {
        if ($type === 'inlineStr') {
            $text = '';

            foreach ($xpath->query('./x:is//x:t', $cellNode) as $textNode) {
                $text .= $textNode instanceof \DOMNode ? $textNode->textContent : '';
            }

            return $text;
        }

        $valueNode = $xpath->query('./x:v', $cellNode)->item(0);
        $rawValue = $valueNode instanceof \DOMNode ? (string) $valueNode->textContent : '';

        if ($type === 's') {
            $sharedIndex = (int) $rawValue;

            return isset($sharedStrings[$sharedIndex]) ? (string) $sharedStrings[$sharedIndex] : '';
        }

        if ($type === 'b') {
            return $rawValue === '1' ? '1' : '0';
        }

        return $rawValue;
    }

    private function extractColumnIndexFromReference(string $reference): int
    {
        if ($reference === '' || \preg_match('/^([A-Z]+)\d+$/', \strtoupper($reference), $matches) !== 1) {
            return 0;
        }

        $letters = (string) $matches[1];
        $index = 0;

        for ($i = 0, $length = \strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (\ord($letters[$i]) - 64);
        }

        return $index;
    }

    private function columnNumberToName(int $columnNumber): string
    {
        $name = '';

        while ($columnNumber > 0) {
            $remainder = ($columnNumber - 1) % 26;
            $name = \chr(65 + $remainder) . $name;
            $columnNumber = (int) \floor(($columnNumber - 1) / 26);
        }

        return $name === '' ? 'A' : $name;
    }

    private function escapeXmlText(string $value): string
    {
        return \htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    private function escapeXmlAttribute(string $value): string
    {
        return \htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    private function loadXmlDocument(string $xml): \DOMDocument
    {
        $previous = \libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new \RuntimeException(\__('The workbook contains malformed XML.', 'must-hotel-booking'));
        }

        return $document;
    }
}
