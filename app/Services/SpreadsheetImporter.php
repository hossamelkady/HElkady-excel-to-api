<?php

namespace App\Services;

use App\Models\Dataset;
use App\Models\DatasetRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class SpreadsheetImporter
{
    /**
     * Read the first row as headers and every other non-empty row as data.
     *
     * @return array{columns: array, rows: array}
     */
    public function parse(string $path, string $extension): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(false); // needed to detect date formats
        }
        $sheet = $reader->load($path)->getActiveSheet();

        $maxRows = config('excel_api.max_rows');
        $maxColumns = config('excel_api.max_columns');

        $matrix = [];
        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex > $maxRows + 1) {
                throw new InvalidArgumentException("File has more than {$maxRows} rows.");
            }
            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);
            foreach ($iterator as $cell) {
                if (count($cells) >= $maxColumns) {
                    // Refuse the file instead of silently dropping data in extra columns.
                    $extra = $cell->getValue();
                    if ($extra !== null && $extra !== '') {
                        throw new InvalidArgumentException("File has more than {$maxColumns} columns.");
                    }
                    continue;
                }
                $value = $cell->getCalculatedValue();
                if (is_numeric($value) && Date::isDateTime($cell)) {
                    $value = Date::excelToDateTimeObject($value)->format('Y-m-d');
                }
                $cells[] = is_string($value) ? trim($value) : $value;
            }
            $matrix[] = $cells;
        }

        // Drop trailing empty columns/rows.
        $matrix = array_values(array_filter($matrix, fn ($r) => $this->hasValue($r)));
        if (count($matrix) < 1) {
            throw new InvalidArgumentException('The file is empty.');
        }

        $headers = array_shift($matrix);
        $width = $this->lastFilledIndex($headers) + 1;
        if ($width === 0) {
            throw new InvalidArgumentException('The first row must contain column headers.');
        }

        $columns = $this->buildColumns(array_slice($headers, 0, $width));
        $rows = [];
        foreach ($matrix as $r) {
            $data = [];
            foreach ($columns as $i => $column) {
                $value = $r[$i] ?? null;
                $data[$column['key']] = ($value === '' ? null : $value);
            }
            $rows[] = $data;
        }

        foreach ($columns as $i => $column) {
            $type = $this->detectType(array_column($rows, $column['key']));
            $columns[$i]['type'] = $type;
            if ($type === 'number') {
                // CSV cells arrive as strings: store real numbers so filters and sums work.
                foreach ($rows as $n => $data) {
                    if ($data[$column['key']] !== null) {
                        $rows[$n][$column['key']] = $data[$column['key']] + 0;
                    }
                }
            }
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    public function import(string $path, string $extension, string $name, string $originalFilename): Dataset
    {
        $parsed = $this->parse($path, $extension);

        return DB::transaction(function () use ($parsed, $name, $originalFilename) {
            $dataset = Dataset::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'original_filename' => $originalFilename,
                'columns' => $parsed['columns'],
                'row_count' => count($parsed['rows']),
            ]);

            foreach (array_chunk($parsed['rows'], 500) as $chunk) {
                DatasetRow::insert(array_map(fn ($data) => [
                    'dataset_id' => $dataset->id,
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ], $chunk));
            }

            return $dataset;
        });
    }

    private function buildColumns(array $headers): array
    {
        $columns = [];
        $used = [];
        foreach ($headers as $i => $header) {
            $label = trim((string) $header);
            if ($label === '') {
                $label = 'Column ' . ($i + 1);
            }
            $base = $label;
            $n = 2;
            while (in_array($label, $used, true)) {
                $label = "{$base} ({$n})";
                $n++;
            }
            $used[] = $label;
            $columns[] = ['key' => 'c' . ($i + 1), 'label' => $label, 'type' => 'text'];
        }

        return $columns;
    }

    private function detectType(array $values): string
    {
        $values = array_filter($values, fn ($v) => $v !== null);
        if ($values === []) {
            return 'text';
        }
        // Numbers only. Text like "0100123" (leading zero) stays text so ids and phones are not changed.
        $isNumber = fn ($v) => is_numeric($v) && ! (is_string($v) && preg_match('/^0\d/', $v));
        if (count(array_filter($values, fn ($v) => ! $isNumber($v))) === 0) {
            return 'number';
        }
        if (count(array_filter($values, fn ($v) => ! (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)))) === 0) {
            return 'date';
        }

        return 'text';
    }

    private function hasValue(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && $v !== '') {
                return true;
            }
        }

        return false;
    }

    private function lastFilledIndex(array $row): int
    {
        for ($i = count($row) - 1; $i >= 0; $i--) {
            if ($row[$i] !== null && $row[$i] !== '') {
                return $i;
            }
        }

        return -1;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'dataset';
        $slug = $base;
        $n = 2;
        while (Dataset::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
