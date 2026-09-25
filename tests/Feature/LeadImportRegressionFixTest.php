<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadImport\LeadImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class LeadImportRegressionFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(11, 0));
    }

    private function actors(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $sales = User::factory()->create(['role' => 'salesperson', 'is_active' => true]);
        $caller = User::factory()->create(['role' => 'telecaller', 'is_active' => true]);
        $project = Project::create(['name' => 'Alpha', 'is_active' => true]);
        $project->salespeople()->attach($sales);
        $this->actingAs($admin);

        return compact('admin', 'sales', 'caller', 'project');
    }

    private function row(array $extra = []): array
    {
        return $extra + ['Name' => 'Rahul', 'Mobile' => '9876543210', 'Project' => 'Alpha', 'Source' => 'Facebook', 'Stage' => 'fresh'];
    }

    private function csvUpload(string $content, string $name = 'leads.csv'): array
    {
        return $this->postJson(route('leads.import.upload'), ['file' => UploadedFile::fake()->createWithContent($name, $content)])->json();
    }

    private function upload(array $rows): array
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn (string $header) => $row[$header] ?? '', array_keys($rows[0])), escape: '');
        }
        rewind($stream);
        $file = UploadedFile::fake()->createWithContent('leads.csv', stream_get_contents($stream));
        fclose($stream);

        return $this->postJson(route('leads.import.upload'), ['file' => $file])->assertOk()->json();
    }

    private function parsed(array $upload, ?array $mapping = null, string $dateOrder = 'auto', array $overrides = [], array $query = []): array
    {
        $payload = ['token' => $upload['token'], 'mapping' => $mapping ?? $upload['guessedMapping'], 'date_order' => $dateOrder, ...$query];
        if ($overrides !== []) {
            $payload['overrides'] = $overrides;
        }

        return $this->postJson(route('leads.import.preview'), $payload)->assertOk()->json();
    }

    private function finalPreview(array $upload, array $settings = []): array
    {
        $state = app(LeadImportStore::class)->read(auth()->user(), $upload['token']);
        $response = $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $state['mapping'], 'date_order' => $state['date_order'], 'defaults' => $settings + [
            'mode' => 'today', 'time' => '14:30', 'follow_up_type' => 'call',
        ]]);
        if ($response->getStatusCode() !== 200) {
            dd($response->json());
        }

        return $response->json();
    }

    private function finish(array $upload, array $final): array
    {
        $offset = 0;
        do {
            $result = $this->postJson(route('leads.import.chunk'), ['token' => $upload['token'], 'plan_id' => $final['plan_id'], 'confirm' => true, 'offset' => $offset])->assertOk()->json();
            $offset = $result['nextOffset'];
        } while (! $result['done']);

        return $result;
    }

    public function test_ten_thousand_plus_row_csv_is_rejected_while_streaming_and_wide_csv_is_rejected_for_columns(): void
    {
        $this->actors();
        $lines = ['first_name,mobile_number'];
        for ($i = 1; $i <= 15000; $i++) {
            $lines[] = "User{$i},98765".str_pad((string) $i, 5, '0', STR_PAD_LEFT);
        }
        $this->postJson(route('leads.import.upload'), ['file' => UploadedFile::fake()->createWithContent('huge.csv', implode("\n", $lines))])->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $headers = implode(',', array_map(fn (int $i): string => "C{$i}", range(1, 101)));
        $values = implode(',', array_map(fn (int $i): string => "V{$i}", range(1, 101)));
        $this->postJson(route('leads.import.upload'), ['file' => UploadedFile::fake()->createWithContent('wide.csv', $headers."\n".$values)])->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_title_row_before_the_header_is_allowed_in_csv(): void
    {
        $a = $this->actors();
        $upload = $this->csvUpload("Facebook Leads Report\n\nName,Mobile\nAmit,9876543210\nBeena,9876543211\n");
        $this->assertSame(['Name', 'Mobile'], $upload['headers']);
        $preview = $this->parsed($upload, ['first_name' => 'Name', 'mobile_number' => 'Mobile'], 'auto', [], ['filter' => 'problems']);
        $this->assertSame(2, $preview['summary']['clean']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_numeric_row_with_phone_shape_is_kept_when_name_is_missing_while_plain_numbers_stay_dropped(): void
    {
        $a = $this->actors();
        $upload = $this->csvUpload("Name,Mobile\n,9876543210\n6,\n");
        $preview = $this->parsed($upload);
        // The 10-digit row is a real lead with a missing name and must reach
        // validation; the lone numeric "6" cell is report noise and is dropped.
        $this->assertSame(1, $preview['summary']['total']);
        $this->assertSame(1, $preview['summary']['missingRequired']);
        $this->assertSame(1, $preview['summary']['problems']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_four_digit_years_are_parsed_instead_of_two_digit(): void
    {
        $a = $this->actors();
        $upload = $this->upload([['Name' => 'Amit', 'Mobile' => '9876543210', 'Created' => '31-12-2026']]);
        $preview = $this->parsed($upload, ['full_name' => 'Name', 'mobile_number' => 'Mobile', 'created_at' => 'Created'], 'dmy');
        $this->assertSame(0, $preview['summary']['invalidDate']);
        $this->assertSame('2026-12-31 00:00:00', $preview['rows'][0]['attributes']['created_at']);

        $uploadMdy = $this->upload([['Name' => 'Beena', 'Mobile' => '9876543211', 'Created' => '12-31-2026']]);
        $previewMdy = $this->parsed($uploadMdy, ['full_name' => 'Name', 'mobile_number' => 'Mobile', 'created_at' => 'Created'], 'mdy');
        $this->assertSame('2026-12-31 00:00:00', $previewMdy['rows'][0]['attributes']['created_at']);

        $uploadTwo = $this->upload([['Name' => 'Chinu', 'Mobile' => '9876543212', 'Created' => '31-12-26']]);
        $previewTwo = $this->parsed($uploadTwo, ['full_name' => 'Name', 'mobile_number' => 'Mobile', 'created_at' => 'Created'], 'dmy');
        $this->assertSame('2026-12-31 00:00:00', $previewTwo['rows'][0]['attributes']['created_at']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_am_pm_times_only_accept_a_valid_12_hour_clock(): void
    {
        $a = $this->actors();
        $upload = $this->upload([
            ['Name' => 'Amit', 'Mobile' => '9876543210', 'Time' => ''],
            ['Name' => 'Beena', 'Mobile' => '9876543211', 'Time' => '2:30 PM'],
            ['Name' => 'Chinu', 'Mobile' => '9876543212', 'Time' => '13:30 PM'],
            ['Name' => 'Deepu', 'Mobile' => '9876543213', 'Time' => '0:30 AM'],
        ]);
        $mapping = ['full_name' => 'Name', 'mobile_number' => 'Mobile', 'follow_up_time' => 'Time'];
        $preview = $this->parsed($upload, $mapping);
        // Blank time falls back; a valid 12-hour time is honoured; hours that
        // cannot exist on a 12-hour AM/PM clock are invalid time problems.
        $this->assertSame(2, $preview['summary']['invalidTime']);
        $this->assertSame(1, $preview['summary']['missingFollowUpTimes']);
        $this->assertSame(2, $preview['summary']['clean']);
        $final = $this->finalPreview($upload, ['project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh', 'time_source' => 'uploaded', 'fallback_time' => '18:00']);
        $byRow = collect($final['previewRows'])->keyBy('row');
        $this->assertSame('18:00', $byRow[2]['follow_up_time']);
        $this->assertSame('14:30', $byRow[3]['follow_up_time']);
        $result = $this->finish($upload, $final);
        $this->assertSame(2, $result['created']);
    }

    public function test_project_row_override_is_honoured_when_the_project_column_is_not_mapped(): void
    {
        $a = $this->actors();
        $beta = Project::create(['name' => 'Beta', 'is_active' => true]);
        $upload = $this->upload([
            ['Name' => 'Amit', 'Mobile' => '9876543210'],
            ['Name' => 'Beena', 'Mobile' => '9876543211'],
        ]);
        $preview = $this->parsed($upload, ['full_name' => 'Name', 'mobile_number' => 'Mobile'], 'auto', [2 => ['project' => 'Beta']]);
        $byRow = collect($preview['rows'])->keyBy('row');
        // The row-level override is honoured even though the file has no
        // project column; the untouched row still defers to the Step 3 default.
        $this->assertSame($beta->id, $byRow[2]['attributes']['project_id']);
        $this->assertNull($byRow[3]['attributes']['project_id']);
    }

    public function test_review_table_is_paginated_server_side_and_editing_and_excluding_work_beyond_page_one(): void
    {
        $a = $this->actors();
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = ['Name' => "Lead {$i}", 'Mobile' => '987650'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'Project' => 'Alpha', 'Source' => 'Facebook', 'Stage' => 'fresh'];
        }
        $upload = $this->upload($rows);
        $page1 = $this->parsed($upload, null, 'auto', [], ['page' => 1, 'per_page' => 30]);
        $this->assertCount(30, $page1['rows']);
        $this->assertSame(1, $page1['pagination']['current_page']);
        $this->assertSame(100, $page1['pagination']['total']);
        $this->assertSame(4, $page1['pagination']['last_page']);

        $page2 = $this->parsed($upload, null, 'auto', [], ['page' => 2, 'per_page' => 30]);
        $this->assertCount(30, $page2['rows']);
        $this->assertSame(2, $page2['pagination']['current_page']);
        $this->assertSame(32, $page2['rows'][0]['row']);

        $edited2 = $this->parsed($upload, null, 'auto', [40 => ['first_name' => 'Edited A']], ['page' => 2, 'per_page' => 30]);
        $editedRow = collect($edited2['rows'])->firstWhere('row', 40);
        $this->assertTrue($editedRow['edited']);
        $this->assertSame('Edited A', $editedRow['attributes']['first_name']);

        $page3 = $this->parsed($upload, null, 'auto', [40 => ['first_name' => 'Edited A']], ['page' => 3, 'per_page' => 30]);
        $this->assertSame(3, $page3['pagination']['current_page']);
        $row70 = collect($page3['rows'])->firstWhere('row', 70);
        $this->assertSame('Lead', $row70['attributes']['first_name']);
        $this->assertSame('69', $row70['attributes']['last_name']);

        $excluded = $this->postJson(route('leads.import.exclude'), ['token' => $upload['token'], 'row' => 70, 'page' => 3, 'per_page' => 30, 'filter' => 'all'])->assertOk()->json();
        $this->assertSame(3, $excluded['pagination']['current_page']);
        $excludedRow = collect($excluded['rows'])->firstWhere('row', 70);
        $this->assertSame('excluded', $excludedRow['outcome']);

        $final = $this->finalPreview($upload);
        $result = $this->finish($upload, $final);
        $this->assertSame(99, $result['created']);
        $this->assertSame(1, $result['excluded']);
        $this->assertSame(0, DB::table('leads')->where('mobile_number', '9876500069')->count());
        $this->assertSame('Edited A', DB::table('leads')->where('mobile_number', '9876500039')->value('first_name'));
        $this->assertDatabaseCount('leads', 99);
    }

    public function test_a_budget_column_of_amounts_is_never_mapped_to_created_at(): void
    {
        $a = $this->actors();
        $upload = $this->upload([
            ['Name' => 'Amit', 'Mobile' => '9876543210', 'Budget' => '45000'],
            ['Name' => 'Beena', 'Mobile' => '9876543211', 'Budget' => '46000'],
            ['Name' => 'Chinu', 'Mobile' => '9876543212', 'Budget' => '47000'],
        ]);
        $this->assertArrayNotHasKey('created_at', $upload['guessedMapping']);
        $serial = $this->upload([
            ['Name' => 'Amit', 'Mobile' => '9876543210', 'Created Date' => '45291'],
            ['Name' => 'Beena', 'Mobile' => '9876543211', 'Created Date' => '45292'],
            ['Name' => 'Chinu', 'Mobile' => '9876543212', 'Created Date' => '45293'],
        ]);
        $this->assertSame('Created Date', $serial['guessedMapping']['created_at']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_excel_formula_cells_are_never_imported_as_values(): void
    {
        $this->actors();
        $path = Storage::disk('local')->path('formula.xlsx');
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('Leads');
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue([1, 1], 'Name');
        $sheet->setCellValue([2, 1], 'Mobile');
        $sheet->setCellValue([1, 2], '=B2&B3');
        $sheet->setCellValue([2, 2], 'Amit');
        $sheet->setCellValue([3, 2], ' Shah');
        $sheet->setCellValue([2, 3], 9876543210);
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $book->disconnectWorksheets();
        $upload = $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'leads.xlsx', test: true)])->assertOk()->json();
        $preview = $this->parsed($upload);
        $this->assertSame(2, $preview['summary']['problems']);
        $problem = collect($preview['problemRows'])->firstWhere('row', 2);
        $this->assertStringContainsStringIgnoringCase('formula', implode(' ', $problem['errors']));
        $this->assertSame(0, DB::table('leads')->where('first_name', 'like', '=%')->count());
        $this->assertDatabaseCount('leads', 0);
    }
}
