<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DatasetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['excel_api.api_key' => 'test-key']);
    }

    private function headers(): array
    {
        return ['X-API-Key' => 'test-key', 'Accept' => 'application/json'];
    }

    private function xlsx(array $rows, string $name = 'sales.xlsx'): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $coord = chr(ord('A') + $c) . ($r + 1);
                if ($value instanceof \DateTimeInterface) {
                    $sheet->setCellValue($coord, Date::PHPToExcel($value));
                    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
                } else {
                    $sheet->setCellValue($coord, $value);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->post('/api/datasets', ['file' => $file] + $extra, $this->headers());
    }

    private function sampleRows(): array
    {
        return [
            ['Invoice', 'Customer', 'Amount', 'Date'],
            ['INV-1', 'أحمد', 100.5, new \DateTimeImmutable('2026-01-05')],
            ['INV-2', 'Sara', 200, new \DateTimeImmutable('2026-01-06')],
            ['INV-3', 'أحمد', 50, new \DateTimeImmutable('2026-02-01')],
        ];
    }

    public function test_api_key_is_required(): void
    {
        $this->getJson('/api/datasets')->assertStatus(401);
        $this->getJson('/api/datasets', ['X-API-Key' => 'wrong'])->assertStatus(401);
        $this->getJson('/api/datasets', ['X-API-Key' => 'test-key'])->assertOk();
    }

    public function test_server_without_key_configured_refuses(): void
    {
        config(['excel_api.api_key' => null]);

        $this->getJson('/api/datasets', ['X-API-Key' => ''])->assertStatus(503);
    }

    public function test_file_with_too_many_columns_is_rejected(): void
    {
        config(['excel_api.max_columns' => 3]);

        $this->upload($this->xlsx([['A', 'B', 'C', 'D'], [1, 2, 3, 4]]), ['name' => 'Wide'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'File has more than 3 columns.');
    }

    public function test_upload_xlsx_creates_dataset_with_types(): void
    {
        $response = $this->upload($this->xlsx($this->sampleRows()), ['name' => 'Sales 2026']);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'sales-2026')
            ->assertJsonPath('data.row_count', 3)
            ->assertJsonPath('data.columns.0.name', 'Invoice')
            ->assertJsonPath('data.columns.2.type', 'number')
            ->assertJsonPath('data.columns.3.type', 'date');
    }

    public function test_rows_are_returned_with_original_headers_and_dates(): void
    {
        $this->upload($this->xlsx($this->sampleRows()), ['name' => 'Sales']);

        $this->getJson('/api/datasets/sales/rows', $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.Invoice', 'INV-1')
            ->assertJsonPath('data.0.Customer', 'أحمد')
            ->assertJsonPath('data.0.Amount', 100.5)
            ->assertJsonPath('data.0.Date', '2026-01-05');
    }

    public function test_filter_search_and_sort(): void
    {
        $this->upload($this->xlsx($this->sampleRows()), ['name' => 'Sales']);

        $this->getJson('/api/datasets/sales/rows?filter[Customer]=Sara', $this->headers())
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.Invoice', 'INV-2');

        $this->getJson('/api/datasets/sales/rows?search=' . urlencode('أحمد'), $this->headers())
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/datasets/sales/rows?sort=-Amount', $this->headers())
            ->assertJsonPath('data.0.Invoice', 'INV-2')
            ->assertJsonPath('data.2.Invoice', 'INV-3');

        $this->getJson('/api/datasets/sales/rows?filter[Nope]=1', $this->headers())->assertStatus(422);
    }

    public function test_summary_of_numeric_column(): void
    {
        $this->upload($this->xlsx($this->sampleRows()), ['name' => 'Sales']);

        $response = $this->getJson('/api/datasets/sales/summary?column=Amount', $this->headers())->assertOk();
        $data = $response->json('data');

        $this->assertSame(3, $data['count']);
        $this->assertEquals(350.5, $data['sum']);
        $this->assertEquals(50, $data['min']);
        $this->assertEquals(200, $data['max']);

        $this->getJson('/api/datasets/sales/summary?column=Customer', $this->headers())->assertStatus(422);
    }

    public function test_csv_upload_converts_numbers_and_keeps_leading_zeros(): void
    {
        $csv = UploadedFile::fake()->createWithContent('phones.csv', "Name,Phone,Total\nAli,01012345678,10\nMona,01198765432,15.5\n");

        $this->upload($csv)->assertCreated()
            ->assertJsonPath('data.columns.1.type', 'text')
            ->assertJsonPath('data.columns.2.type', 'number');

        $this->getJson('/api/datasets/phones/rows', $this->headers())
            ->assertJsonPath('data.0.Phone', '01012345678')
            ->assertJsonPath('data.1.Total', 15.5);
    }

    public function test_duplicate_and_empty_headers_get_unique_names(): void
    {
        $this->upload($this->xlsx([['Name', 'Name', null, 'X'], ['a', 'b', 'c', 'd']], 'dup.xlsx'))->assertCreated();

        $names = collect($this->getJson('/api/datasets/dup', $this->headers())->json('data.columns'))->pluck('name')->all();
        $this->assertSame(['Name', 'Name (2)', 'Column 3', 'X'], $names);
    }

    public function test_same_name_gets_a_new_slug(): void
    {
        $this->upload($this->xlsx($this->sampleRows()), ['name' => 'Sales'])->assertJsonPath('data.slug', 'sales');
        $this->upload($this->xlsx($this->sampleRows()), ['name' => 'Sales'])->assertJsonPath('data.slug', 'sales-2');
    }

    public function test_rejects_wrong_file_type_and_empty_file(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('x.pdf', 'hello'))->assertStatus(422);
        $this->upload($this->xlsx([[null]], 'empty.xlsx'))->assertStatus(422);
        $this->post('/api/datasets', [], $this->headers())->assertStatus(422);
    }

    public function test_delete_dataset_removes_its_rows(): void
    {
        $this->upload($this->xlsx($this->sampleRows()), ['name' => 'Sales']);

        $this->deleteJson('/api/datasets/sales', [], $this->headers())->assertNoContent();
        $this->getJson('/api/datasets/sales', $this->headers())->assertNotFound();
        $this->assertDatabaseCount('dataset_rows', 0);
    }
}
