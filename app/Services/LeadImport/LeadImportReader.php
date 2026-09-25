<?php

namespace App\Services\LeadImport;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class LeadImportReader
{
    public const MAX_ROWS = 10000;

    public const MAX_COLUMNS = 100;

    /**
     * Read a spreadsheet or CSV and turn it into the grid the planner plans.
     *
     * Where the header row sits, which of several sheets holds the lead data,
     * and which columns are dead space are all discovered — the file says
     * "Name" in row four of "Lead" while "Sheet1" is a phone reference table
     * and neither situation needs a human first.
     *
     * @return array{headers: array, rows: array, sheets: array, sheet: string, headerRow: int, sheetAmbiguous: bool}
     */
    public function read(string $path, ?string $selectedSheet = null): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'csv') {
            return $this->readCsv($path, $selectedSheet);
        }
        $expected = ['csv' => 'Csv', 'xls' => 'Xls', 'xlsx' => 'Xlsx'][$extension] ?? throw new RuntimeException('Unsupported format.');
        $reader = IOFactory::createReader($expected);
        if (! $reader->canRead($path)) {
            throw new RuntimeException('Unreadable file.');
        }
        $info = $reader->listWorksheetInfo($path);
        $sheets = array_values(array_map(fn (array $s): string => $s['worksheetName'], array_filter($info, fn (array $s): bool => $s['totalRows'] >= 2)));
        if ($sheets === []) {
            throw new RuntimeException('Select a sheet with a header and at least one data row.');
        }

        $detector = new LeadImportDetector;
        $scanned = $this->scanAllSheets($expected, $path, $info, $sheets, $detector);
        // A sheet is only a STRONG lead candidate when it pairs a name column
        // with a phone column AND has rows beneath its header. Helper tabs —
        // phone lookups, summaries, dashboards — can never reach that bar on
        // project/source/stage columns alone, so they neither get auto-selected
        // nor force the "multiple sheets" screen. Two genuinely strong lead
        // sheets is a real choice and is offered to the user, first sheet as
        // the safe default (sheetAmbiguous).
        $strong = array_values(array_filter($sheets, fn (string $name): bool => $scanned[$name]['strong']));
        if ($selectedSheet !== null) {
            if (! in_array($selectedSheet, $sheets, true)) {
                throw new RuntimeException('Select a sheet with a header and at least one data row.');
            }
            $sheetAmbiguous = false;
        } elseif (count($strong) === 1) {
            $selectedSheet = $strong[0];
            $sheetAmbiguous = false;
        } elseif ($strong !== []) {
            $selectedSheet = $strong[0];
            $sheetAmbiguous = true;
        } else {
            $bestScore = max(array_column($scanned, 'score'));
            foreach ($sheets as $name) {
                if ($scanned[$name]['score'] === $bestScore) {
                    $selectedSheet = $name;
                    break;
                }
            }
            $sheetAmbiguous = count(array_filter($sheets, fn (string $name): bool => $scanned[$name]['score'] === $bestScore)) > 1;
        }
        $metadata = collect($info)->firstWhere('worksheetName', $selectedSheet);
        if ($metadata['totalRows'] > self::MAX_ROWS + 1 || $metadata['totalColumns'] > self::MAX_COLUMNS) {
            throw new RuntimeException('Maximum 10,000 data rows and 100 columns per sheet.');
        }
        $reader->setLoadSheetsOnly($selectedSheet);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= LeadImportReader::MAX_ROWS + 1;
            }
        });
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheetByName($selectedSheet) ?? $book->getActiveSheet();
            $grid = $this->readGrid($sheet, $metadata['totalColumns'], $metadata['totalRows']);
            $detected = $this->buildSheet($grid, $detector, $scanned[$selectedSheet]['headerRow']);

            return ['headers' => $detected['headers'], 'rows' => $detected['rows'], 'sheets' => $sheets, 'sheet' => $selectedSheet, 'headerRow' => $detected['headerRow'], 'sheetAmbiguous' => $sheetAmbiguous];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /**
     * Score every candidate sheet with a shallow scan, so multi-sheet files
     * pick their own lead sheet and flag ties without loading the workbook
     * four times over.
     *
     * @param  array<int, array<string, mixed>>  $info
     * @param  list<string>  $sheets
     * @return array<string, array{headerRow: ?int, score: int, aliasHits: int, strong: bool, dataRows: int, negativeHits: int}>
     */
    private function scanAllSheets(string $expected, string $path, array $info, array $sheets, LeadImportDetector $detector): array
    {
        $reader = IOFactory::createReader($expected);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= LeadImportDetector::SCAN_LIMIT;
            }
        });
        $book = $reader->load($path);
        $scanned = [];
        try {
            foreach ($sheets as $name) {
                $metadata = collect($info)->firstWhere('worksheetName', $name);
                $sheet = $book->getSheetByName($name);
                $hidden = $sheet !== null && $sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE;
                $grid = $this->readGrid($sheet, $metadata['totalColumns'], min(LeadImportDetector::SCAN_LIMIT, $metadata['totalRows']));
                $scanned[$name] = $detector->scoreSheet($grid, $name, $hidden);
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return $scanned;
    }

    /**
     * Absolute Excel row => 1-based column => trimmed value, with Excel date
     * and float cells normalised the same way they always were. Formula cells
     * keep the value the workbook already cached — never an evaluation of the
     * formula itself — so a cell reading '=A1&B1' cannot smuggle spreadsheet
     * logic into a customer name.
     */
    private function readGrid($sheet, int $totalColumns, int $totalRows): array
    {
        $grid = [];
        for ($row = 1; $row <= $totalRows; $row++) {
            $cells = [];
            for ($column = 1; $column <= $totalColumns; $column++) {
                $cell = $sheet->getCell([$column, $row]);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $value = $cell->getOldCalculatedValue() ?? $cell->getValue();
                } else {
                    $value = $cell->getValue();
                }
                if (is_numeric($value) && Date::isDateTime($cell)) {
                    $value = Date::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
                } elseif (is_float($value)) {
                    $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
                }
                $cells[$column] = trim(ltrim((string) $value, "\xEF\xBB\xBF"));
            }
            $grid[$row] = $cells;
        }

        return $grid;
    }

    /**
     * Headers from the detected row, dead (fully empty) columns dropped, data
     * rows keyed by their absolute sheet row.
     *
     * @param  array<int, array<int, string>>  $grid
     * @return array{headers: array, rows: array, headerRow: int, sheetAmbiguous: bool}
     */
    private function buildSheet(array $grid, LeadImportDetector $detector, ?int $detectedHeaderRow): array
    {
        $headerRow = $detectedHeaderRow ?: $detector->detectHeaderRow($grid);
        if ($headerRow === null) {
            throw new RuntimeException('File must have a header row.');
        }
        $columns = [];
        $namedHeaders = 0;
        foreach (array_keys($grid[$headerRow]) as $column) {
            $value = $grid[$headerRow][$column];
            $hasData = false;
            foreach ($grid as $row => $cells) {
                if ($row <= $headerRow) {
                    continue;
                }
                if (trim((string) ($cells[$column] ?? '')) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (! $hasData) {
                continue;
            }
            $namedHeaders += $value !== '' ? 1 : 0;
            $columns[] = ['name' => $value !== '' ? $value : "Column {$column}", 'column' => $column];
        }
        $headers = array_column($columns, 'name');
        if ($namedHeaders === 0) {
            throw new RuntimeException('File must have a header row.');
        }
        if (count(array_unique($headers)) !== count($headers)) {
            throw new RuntimeException('Column headers must be unique.');
        }
        $rows = [];
        foreach ($grid as $row => $cells) {
            if ($row <= $headerRow) {
                continue;
            }
            $values = [];
            foreach ($columns as $column) {
                $values[$column['name']] = trim((string) ($cells[$column['column']] ?? ''));
            }
            if (array_filter($values, fn (string $value): bool => $value !== '') === []) {
                continue;
            }
            if ($this->isNoiseRow($values, $headers)) {
                continue;
            }
            $rows[$row] = $values;
        }
        if ($rows === []) {
            throw new RuntimeException('File must contain a header and at least one data row.');
        }

        return ['headers' => $headers, 'rows' => $rows, 'headerRow' => $headerRow, 'sheetAmbiguous' => false];
    }

    /**
     * A row that is report furniture rather than a record, so it must never
     * become a CRM lead:
     *
     *   - a repeated header line ("Name | Mobile | ..." again in the middle),
     *   - a totals / summary footer ("TOTAL: 325", "Grand Total , 12"),
     *   - a row whose every filled cell is numeric — there is no name in it,
     *     and in a lead file a name is the one cell no record ships without.
     *
     * @param  array<string, string>  $values  header name => cell value
     * @param  list<string>  $headers  the sheet's column names, in order
     */
    private function isNoiseRow(array $values, array $headers): bool
    {
        $nonEmpty = [];
        $headerIndex = 0;
        foreach ($values as $header => $value) {
            if (trim($value) !== '') {
                $nonEmpty[$headerIndex] = trim($value);
            }
            $headerIndex++;
        }
        if ($nonEmpty === []) {
            return false;
        }
        $allNumeric = true;
        foreach ($nonEmpty as $value) {
            if (! is_numeric($value)) {
                $allNumeric = false;
                break;
            }
        }
        if ($allNumeric) {
            // A row that is purely numeric is report furniture (a totals line,
            // a phone reference table) — unless one of the numbers is the shape
            // of a mobile number, in which case it is a real lead row whose
            // name cell happens to be missing. That row must reach validation
            // so it can be reported and fixed, not silently discarded.
            foreach ($nonEmpty as $value) {
                $digits = strlen(preg_replace('/\D/', '', $value));
                if ($digits >= 10 && $digits <= 14) {
                    return false;
                }
            }

            return true;
        }
        $repeat = true;
        foreach ($nonEmpty as $index => $value) {
            if (strcasecmp($value, trim((string) ($headers[$index] ?? ''))) !== 0) {
                $repeat = false;
                break;
            }
        }
        if ($repeat) {
            return true;
        }
        $first = $this->noiseNormalise((string) reset($nonEmpty));

        return (bool) preg_match('/^(grandtotal|subtotal|total|totals|count|counts|sum|sums|average|averages|avg)\d*$/', $first)
            && (count($nonEmpty) <= 2 || count(array_filter($nonEmpty, fn (string $v): bool => ! is_numeric($v))) === 1);
    }

    /** Letters and digits only, lowercase — the same shape aliases are matched on. */
    private function noiseNormalise(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($value)) ?? '';
    }

    /**
     * Native CSV parsing retains quoted commas/newlines without loading
     * spreadsheet cell objects. The column ceiling applies here too, and the
     * row ceiling is enforced while streaming so a huge file is rejected
     * before its every record is held in memory. Title rows before the real
     * header may carry any width; only data rows after the detected header
     * must line up with it.
     */
    private function readCsv(string $path, ?string $selectedSheet): array
    {
        Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
        $sheet = 'Worksheet';
        if ($selectedSheet !== null && $selectedSheet !== $sheet) {
            throw new RuntimeException('Unknown CSV sheet.');
        }
        $stream = fopen($path, 'rb');
        try {
            if (fread($stream, 3) !== "\xEF\xBB\xBF") {
                rewind($stream);
            }
            $records = [];
            $nonBlank = 0;
            $number = 0;
            while (! feof($stream)) {
                $start = ftell($stream);
                $values = fgetcsv($stream, null, ',', '"', '');
                if ($values === false) {
                    break;
                }
                $end = ftell($stream);
                fseek($stream, $start);
                $record = fread($stream, $end - $start);
                if (substr_count($record, '"') % 2 !== 0 || ! mb_check_encoding($record, 'UTF-8')) {
                    throw new RuntimeException('Malformed CSV quoting or encoding. Use UTF-8 CSV.');
                }
                $number++;
                $values = array_map(fn ($value): string => trim((string) $value), $values);
                if (! array_filter($values)) {
                    continue;
                }
                if (count($values) > self::MAX_COLUMNS) {
                    throw new RuntimeException('Maximum '.self::MAX_ROWS.' data rows and '.self::MAX_COLUMNS.' columns per sheet.');
                }
                $nonBlank++;
                // The header's position is not known yet, so a small allowance
                // covers legitimate title/header rows before the 10,000-data-row
                // ceiling can be applied exactly.
                if ($nonBlank > self::MAX_ROWS + 64) {
                    throw new LeadImportTooLargeException($nonBlank, self::MAX_ROWS);
                }
                $records[$number] = $values;
            }
            if ($records === []) {
                throw new RuntimeException('File must contain a header and at least one data row.');
            }
            $grid = [];
            foreach ($records as $line => $values) {
                foreach ($values as $index => $value) {
                    $grid[$line][$index + 1] = $value;
                }
            }
            $detector = new LeadImportDetector;
            $detected = $this->buildSheet($grid, $detector, null);
            $headerWidth = count($grid[$detected['headerRow']]);
            foreach ($grid as $row => $cells) {
                if ($row <= $detected['headerRow']) {
                    continue;
                }
                if (array_filter($cells, fn (string $value): bool => $value !== '') !== [] && count($cells) !== $headerWidth) {
                    throw new RuntimeException("CSV row {$row} has a different number of columns than the header.");
                }
            }
            $rawHeaders = $detected['headers'];
            $rows = $detected['rows'];
            if (count($rows) > self::MAX_ROWS) {
                throw new LeadImportTooLargeException(count($rows), self::MAX_ROWS);
            }

            return ['headers' => $rawHeaders, 'rows' => $rows, 'sheets' => [$sheet], 'sheet' => $sheet] + array_intersect_key($detected, ['headerRow' => true, 'sheetAmbiguous' => true]);
        } finally {
            fclose($stream);
        }
    }
}
