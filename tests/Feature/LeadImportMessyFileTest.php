<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadImport\LeadImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class LeadImportMessyFileTest extends TestCase
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
        $beta = Project::create(['name' => 'Beta', 'is_active' => true]);
        $project->salespeople()->attach($sales);
        $beta->salespeople()->attach($sales);
        $this->actingAs($admin);

        return compact('admin', 'sales', 'caller', 'project', 'beta');
    }

    private function row(array $extra = []): array
    {
        return $extra + ['Name' => 'Rahul', 'Mobile' => '9876543210', 'Project' => 'Alpha', 'Source' => 'Facebook', 'Stage' => 'fresh'];
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

    private function uploadSheet(string $extension, callable $build): array
    {
        $path = Storage::disk('local')->path('fixture.'.$extension);
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('Leads');
        $book = $build($book);
        IOFactory::createWriter($book, $extension === 'xlsx' ? 'Xlsx' : 'Xls')->save($path);
        $book->disconnectWorksheets();

        return $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'leads.'.$extension, test: true)])->assertOk()->json();
    }

    private function parsed(array $upload, ?array $mapping = null, string $dateOrder = 'auto', array $overrides = []): array
    {
        $payload = ['token' => $upload['token'], 'mapping' => $mapping ?? $upload['guessedMapping'], 'date_order' => $dateOrder];
        if ($overrides !== []) {
            $payload['overrides'] = $overrides;
        }

        return $this->postJson(route('leads.import.preview'), $payload)->assertOk()->json();
    }

    private function finalPreview(array $upload, array $settings = []): array
    {
        $state = app(LeadImportStore::class)->read(auth()->user(), $upload['token']);

        return $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $state['mapping'], 'date_order' => $state['date_order'], 'defaults' => $settings + [
            'mode' => 'today', 'time' => '14:30', 'follow_up_type' => 'call', 'source' => 'facebook', 'stage' => 'fresh',
        ]])->assertOk()->json();
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

    public function test_header_row_on_row_four_is_detected_and_data_rows_keep_their_absolute_numbers(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $sheet = $book->getActiveSheet()->setTitle('Sheet1');
            $sheet->setCellValue([1, 1], 'Weekly Leads Report');
            $sheet->fromArray([['Name', 'Mobile', 'Project']], null, 'A4');
            $sheet->fromArray([['Amit', '9876543210', 'Alpha'], ['Beena', '9876543211', 'Beta']], null, 'A5');

            return $book;
        });
        $this->assertSame(4, $upload['headerRow']);
        $this->assertFalse($upload['sheetAmbiguous']);
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Project', $upload['guessedMapping']['project']);
        $this->assertSame(2, $upload['totalRows']);
        $this->assertSame('Amit', $upload['sampleRows'][0]['Name']);
        $preview = $this->parsed($upload);
        $this->assertSame([5, 6], array_column($preview['previewRows'], 'row'));
        $this->assertSame(0, $preview['summary']['problems']);
        $final = $this->finalPreview($upload);
        $result = $this->finish($upload, $final);
        $this->assertSame(2, $result['created']);
        $this->assertSame('Amit', Lead::where('mobile_number', '9876543210')->firstOrFail()->first_name);
    }

    public function test_blank_rows_between_records_are_skipped_and_physical_row_numbers_survive(): void
    {
        $this->actors();
        $content = "Name,Mobile,Project\nAmit,9876543210,Alpha\n\nBeena,9876543211,Beta\n";
        $upload = $this->postJson(route('leads.import.upload'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', $content)])->assertOk()->json();
        $this->assertSame(2, $upload['totalRows']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame([2, 4], array_column($preview['previewRows'], 'row'));
        $result = $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame(2, $result['created']);
    }

    public function test_blank_columns_are_dropped_from_headers_and_not_mappable(): void
    {
        $this->actors();
        $content = "Name,Mobile,,Project\nAmit,9876543210,,Alpha\nBeena,9876543211,,Beta\n";
        $upload = $this->postJson(route('leads.import.upload'), ['file' => UploadedFile::fake()->createWithContent('leads.csv', $content)])->assertOk()->json();
        $this->assertSame(['Name', 'Mobile', 'Project'], $upload['headers']);
        $this->assertArrayNotHasKey('', $upload['headers']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_client_contact_property_lead_status_and_campaign_source_auto_map_at_high_confidence(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Client Name' => 'Amit', 'Contact' => '9876543210', 'Property' => 'Alpha', 'Lead Status' => 'fresh', 'Campaign Source' => 'Facebook'],
            ['Client Name' => 'Beena', 'Contact' => '9876543211', 'Property' => 'Beta', 'Lead Status' => 'fresh', 'Campaign Source' => 'Facebook'],
        ]);
        $this->assertSame('Client Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Contact', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Property', $upload['guessedMapping']['project']);
        $this->assertSame('Lead Status', $upload['guessedMapping']['stage']);
        $this->assertSame('Campaign Source', $upload['guessedMapping']['source']);
        foreach (['full_name', 'mobile_number', 'project', 'stage', 'source'] as $field) {
            $this->assertSame('high', $upload['mappingConfidence'][$field], $field);
        }
        $this->assertSame([], $upload['unresolvedRequired']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame('facebook', $preview['previewRows'][0]['attributes']['source']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_unrecognised_phone_header_is_found_from_the_data_with_medium_confidence(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Name' => 'Amit', 'Contact Detail' => '9876543210'],
            ['Name' => 'Beena', 'Contact Detail' => '9876543211'],
        ]);
        $this->assertSame('Contact Detail', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('medium', $upload['mappingConfidence']['mobile_number']);
        $this->assertSame('high', $upload['mappingConfidence']['full_name']);
        $this->assertSame([], $upload['unresolvedRequired']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame('9876543210', $preview['previewRows'][0]['attributes']['mobile_number']);
    }

    public function test_multi_sheet_workbook_selects_the_first_lead_like_sheet_and_reports_ambiguity(): void
    {
        $a = $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $summary = $book->getActiveSheet()->setTitle('Summary');
            $summary->fromArray([['Monthly Report'], ['Name', 'Total'], ['Rahul', 12]]);
            $first = $book->createSheet()->setTitle('Lead Data');
            $first->fromArray([['Name', 'Mobile'], ['Amit', '9876543210']]);
            $second = $book->createSheet()->setTitle('Leads 2024');
            $second->fromArray([['Client Name', 'Mobile', 'Created At'], ['Beena', '9876543211', '2026-01-01']]);

            return $book;
        });
        $this->assertSame(['Summary', 'Lead Data', 'Leads 2024'], $upload['sheets']);
        $this->assertTrue($upload['sheetAmbiguous']);
        $this->assertSame('Lead Data', $upload['sheet']);
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);

        $resolved = $this->postJson(route('leads.import.sheet'), ['token' => $upload['token'], 'sheet' => 'Leads 2024'])->assertOk()->json();
        $this->assertFalse($resolved['sheetAmbiguous']);
        $this->assertSame('Leads 2024', $resolved['sheet']);
        $this->assertSame('Client Name', $resolved['guessedMapping']['full_name']);
        $this->assertSame('Created At', $resolved['guessedMapping']['created_at']);
        $this->previewAndCreate($upload, ['project_id' => $a['project']->id], $resolved['guessedMapping']);
        $this->assertSame('Beena', Lead::where('mobile_number', '9876543211')->firstOrFail()->first_name);
    }

    private function previewAndCreate(array $upload, array $settings = [], ?array $mapping = null): void
    {
        $this->parsed($upload, $mapping);
        $this->finish($upload, $this->finalPreview($upload, $settings));
    }

    public function test_noise_sheet_with_unnamed_phone_reference_is_ignored(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $noise = $book->getActiveSheet()->setTitle('Sheet1');
            $noise->fromArray([['Phone Reference', ''], ['9876543201', ''], ['9876543202', '']]);
            $lead = $book->createSheet()->setTitle('Lead');
            $lead->fromArray([['Client Name', 'Mobile', 'Created At'], ['mit', '8905724411', 46266.5], ['Yatin Desai', '8200988245', 46266.5]]);

            return $book;
        });
        $this->assertSame(['Sheet1', 'Lead'], $upload['sheets']);
        $this->assertSame('Lead', $upload['sheet']);
        $this->assertFalse($upload['sheetAmbiguous']);
        $this->assertSame('Client Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Created At', $upload['guessedMapping']['created_at']);
        $this->assertSame(2, $upload['totalRows']);
    }

    public function test_valid_rows_within_a_messy_file_map_and_only_bad_rows_are_flagged(): void
    {
        $this->actors();
        $upload = $this->upload([
            $this->row(),
            $this->row(['Mobile' => '9000000001', 'Source' => 'Unknown Ads']),
            $this->row(['Mobile' => '9000000002', 'Stage' => 'Hot Prospect']),
            $this->row(['Mobile' => '9000000003', 'Project' => 'Missing']),
        ]);
        $preview = $this->parsed($upload);
        $this->assertSame(1, $preview['summary']['unknownSource']);
        $this->assertSame(1, $preview['summary']['unknownStage']);
        $this->assertSame(1, $preview['summary']['unknownProject']);
        $this->assertSame(1, $preview['summary']['clean']);
        $this->assertCount(3, $preview['problemRows']);
        $result = $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame(1, $result['created']);
    }

    public function test_when_there_are_no_project_source_or_stage_columns_batch_defaults_apply(): void
    {
        $a = $this->actors();
        $upload = $this->upload([
            ['Name' => 'Amit', 'Mobile' => '9876543210'],
            ['Name' => 'Beena', 'Mobile' => '9876543211'],
        ]);
        $this->assertArrayNotHasKey('project', $upload['guessedMapping']);
        $this->assertArrayNotHasKey('source', $upload['guessedMapping']);
        $this->assertArrayNotHasKey('stage', $upload['guessedMapping']);
        $this->assertSame([], $upload['unresolvedRequired']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $final = $this->finalPreview($upload, ['project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $this->assertSame(2, $final['summary']['toCreate']);
        $result = $this->finish($upload, $final);
        $this->assertSame(2, $result['created']);
        $lead = Lead::first();
        $this->assertSame($a['project']->id, $lead->project_id);
        $this->assertSame('facebook', $lead->source);
        $this->assertSame('fresh', $lead->stage);
    }

    public function test_unresolved_required_reports_a_missing_mobile_column(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Prospect Name' => 'Amit', 'Location' => 'Chennai', 'Amount' => '1200000'],
            ['Prospect Name' => 'Beena', 'Location' => 'Pune', 'Amount' => '900000'],
        ]);
        $this->assertSame(['mobile_number'], $upload['unresolvedRequired']);
        $this->assertSame('Prospect Name', $upload['guessedMapping']['full_name']);
        $this->assertArrayNotHasKey('mobile_number', $upload['guessedMapping']);
        $this->assertSame('high', $upload['mappingConfidence']['full_name']);
    }

    public function test_mixed_phone_shapes_in_one_column_are_read_as_strings_and_normalised_later(): void
    {
        $a = $this->actors();
        $upload = $this->upload([
            $this->row(['Mobile' => '9876543210']),
            $this->row(['Mobile' => '+91 98765 43211', 'Name' => 'S\'mple Name']),
            $this->row(['Mobile' => '9876-543-212', 'Name' => 'Apostrophe O\'Brien']),
        ]);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['invalidMobile']);
        $this->assertSame(3, $preview['summary']['clean']);
        $result = $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame(3, $result['created']);
        $this->assertSame('9876543211', Lead::where('mobile_number', '9876543211')->firstOrFail()->mobile_number);
        $this->assertSame('9876543212', Lead::where('mobile_number', '9876543212')->firstOrFail()->mobile_number);
    }
}
