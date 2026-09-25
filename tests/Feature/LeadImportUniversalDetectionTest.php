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

/**
 * The file-structure palette the import has to handle on its own — every
 * structure is detected purely from the file (header words, then the CRM's
 * own vocabulary, then the values themselves), mapped, previewed and imported
 * without the user handing over a single column. See each test's name for the
 * shape it stands for.
 */
class LeadImportUniversalDetectionTest extends TestCase
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
        Project::create(['name' => 'Beta', 'is_active' => true]);
        $project->salespeople()->attach($sales);
        $this->actingAs($admin);

        return compact('admin', 'sales', 'caller', 'project');
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

    private function parsed(array $upload, ?array $mapping = null): array
    {
        return $this->postJson(route('leads.import.preview'), [
            'token' => $upload['token'], 'mapping' => $mapping ?? $upload['guessedMapping'], 'date_order' => 'auto',
        ])->assertOk()->json();
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

    public function test_structure_a_snake_case_crm_columns_map_and_import(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['client_name' => 'Amit', 'mobile' => '9876543210', 'project' => 'Alpha', 'lead_source' => 'Facebook', 'lead_sub_status' => 'fresh'],
            ['client_name' => 'Beena', 'mobile' => '9876543211', 'project' => 'Alpha', 'lead_source' => 'Facebook', 'lead_sub_status' => 'fresh'],
        ]);
        foreach (['full_name', 'mobile_number', 'project', 'source', 'stage'] as $field) {
            $this->assertSame('high', $upload['mappingConfidence'][$field], $field);
        }
        $this->assertSame([], $upload['unresolvedRequired']);
        $this->assertSame([], $upload['confirmations']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
        $this->assertSame('Amit', Lead::where('mobile_number', '9876543210')->firstOrFail()->first_name);
        $this->assertSame('facebook', Lead::where('mobile_number', '9876543210')->firstOrFail()->source);
        $this->assertSame('fresh', Lead::where('mobile_number', '9876543210')->firstOrFail()->stage);
    }

    public function test_structure_b_plain_english_aliases_map_with_high_confidence(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Customer' => 'Amit', 'Contact No' => '9876543210', 'Property' => 'Alpha', 'Campaign Source' => 'Facebook', 'Status' => 'fresh'],
            ['Customer' => 'Beena', 'Contact No' => '9876543211', 'Property' => 'Alpha', 'Campaign Source' => 'Facebook', 'Status' => 'fresh'],
        ]);
        $this->assertSame('Customer', $upload['guessedMapping']['full_name']);
        $this->assertSame('Contact No', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Property', $upload['guessedMapping']['project']);
        $this->assertSame('Campaign Source', $upload['guessedMapping']['source']);
        $this->assertSame('Status', $upload['guessedMapping']['stage']);
        foreach (['full_name', 'mobile_number', 'project', 'source', 'stage'] as $field) {
            $this->assertSame('high', $upload['mappingConfidence'][$field], $field);
        }
        $this->assertSame([], $upload['unresolvedRequired']);
        $this->assertSame(0, $this->parsed($upload)['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_structure_c_first_middle_last_columns_map_and_combine(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['First' => 'Amit', 'Middle' => 'Kumar', 'Last' => 'Sharma', 'Phone' => '9876543210', 'Property' => 'Alpha', 'Source' => 'Facebook'],
            ['First' => 'Beena', 'Middle' => '', 'Last' => 'Nair', 'Phone' => '9876543211', 'Property' => 'Alpha', 'Source' => 'Facebook'],
        ]);
        $this->assertSame('First', $upload['guessedMapping']['first_name']);
        $this->assertSame('Middle', $upload['guessedMapping']['middle_name']);
        $this->assertSame('Last', $upload['guessedMapping']['last_name']);
        $this->assertSame('Phone', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Property', $upload['guessedMapping']['project']);
        $this->assertSame('Source', $upload['guessedMapping']['source']);
        $this->assertSame([], $upload['unresolvedRequired']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
        $lead = Lead::where('mobile_number', '9876543210')->firstOrFail();
        $this->assertSame('Amit', $lead->first_name);
        $this->assertSame('Kumar', $lead->middle_name);
        $this->assertSame('Sharma', $lead->last_name);
        $this->assertSame('Nair', Lead::where('mobile_number', '9876543211')->firstOrFail()->last_name);
    }

    public function test_structure_d_unrecognised_source_header_is_found_from_the_crm_vocabulary(): void
    {
        $a = $this->actors();
        $upload = $this->upload([
            ['Prospect' => 'Amit', 'Phone' => '9876543210', 'Campaign Name' => 'Facebook', 'Created' => '2026-09-01'],
            ['Prospect' => 'Beena', 'Phone' => '9876543211', 'Campaign Name' => 'Instagram', 'Created' => '2026-09-02'],
        ]);
        $this->assertSame('Campaign Name', $upload['guessedMapping']['source']);
        $this->assertSame('Created', $upload['guessedMapping']['created_at']);
        $this->assertSame('high', $upload['mappingConfidence']['source']);
        $this->assertSame('high', $upload['mappingConfidence']['full_name']);
        $this->assertSame('high', $upload['mappingConfidence']['created_at']);
        $this->assertSame([], $upload['unresolvedRequired']);
        $this->assertSame([], $upload['confirmations']);
        $this->assertSame(0, $this->parsed($upload)['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload, ['project_id' => $a['project']->id]))['created']);
        $this->assertSame('facebook', Lead::where('mobile_number', '9876543210')->firstOrFail()->source);
        $this->assertSame('instagram', Lead::where('mobile_number', '9876543211')->firstOrFail()->source);
        $this->assertSame('2026-09-01 00:00:00', Lead::where('mobile_number', '9876543210')->firstOrFail()->created_at->format('Y-m-d H:i:s'));
    }

    public function test_structure_e_title_rows_then_header_on_row_four(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $sheet = $book->getActiveSheet()->setTitle('Enquiries');
            $sheet->setCellValue([1, 1], 'Weekly Leads Report');
            $sheet->setCellValue([1, 2], 'Prepared for the sales team');
            $sheet->fromArray([['Full Name', 'Phone', 'Project Name']], null, 'A4');
            $sheet->fromArray([['Amit', '9876543210', 'Alpha'], ['Beena', '9876543211', 'Alpha']], null, 'A5');

            return $book;
        });
        $this->assertSame(4, $upload['headerRow']);
        $this->assertSame(2, $upload['totalRows']);
        $this->assertSame('Full Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Phone', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Project Name', $upload['guessedMapping']['project']);
        $preview = $this->parsed($upload);
        $this->assertSame([5, 6], array_column($preview['previewRows'], 'row'));
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_structure_f_two_clean_sheets_report_ambiguity_and_take_the_first_lead_like_one(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $first = $book->getActiveSheet()->setTitle('Lead Data');
            $first->fromArray([['Name', 'Mobile'], ['Amit', '9876543210']]);
            $second = $book->createSheet()->setTitle('Leads 2024');
            $second->fromArray([['Client Name', 'Mobile'], ['Beena', '9876543211']]);

            return $book;
        });
        $this->assertSame(['Lead Data', 'Leads 2024'], $upload['sheets']);
        $this->assertTrue($upload['sheetAmbiguous']);
        $this->assertSame('Lead Data', $upload['sheet']);
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);
    }

    public function test_structure_g_reordered_columns_map_regardless_of_their_order(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Mobile' => '9876543210', 'Project' => 'Alpha', 'Name' => 'Amit', 'Source' => 'Facebook'],
            ['Mobile' => '9876543211', 'Project' => 'Alpha', 'Name' => 'Beena', 'Source' => 'Facebook'],
        ]);
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Project', $upload['guessedMapping']['project']);
        $this->assertSame('Source', $upload['guessedMapping']['source']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems'], json_encode($preview['problemRows'] ?? []));
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_structure_h_ten_irrelevant_columns_are_ignored(): void
    {
        $a = $this->actors();
        $upload = $this->upload([
            ['Participant' => 'North Wing', 'Location' => 'Chennai', 'City' => 'Chennai', 'State' => 'TN', 'Pincode' => '600001', 'Notes' => 'Prefers morning', 'Company' => 'Acme', 'Rating' => '4-star', 'Tags' => 'AAA', 'Bucket' => 'Bucket A', 'Name' => 'Amit', 'Mobile' => '9876543210'],
            ['Participant' => 'West Face', 'Location' => 'Hyderabad', 'City' => 'Hyderabad', 'State' => 'TS', 'Pincode' => '500001', 'Notes' => 'Calls only', 'Company' => 'Bravo', 'Rating' => '3-star', 'Tags' => 'ABB', 'Bucket' => 'Bucket B', 'Name' => 'Beena', 'Mobile' => '9876543211'],
        ]);
        $this->assertSame(['full_name' => 'Name', 'mobile_number' => 'Mobile'], $upload['guessedMapping']);
        $this->assertSame([], $upload['unresolvedRequired']);
        $this->assertSame(2, $upload['totalRows']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload, ['project_id' => $a['project']->id]))['created']);
    }

    public function test_structure_i_standard_export_aliases_detected_as_csv(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Client Name' => 'Amit', 'Mobile Number' => '9876543210', 'Project Name' => 'Alpha', 'Lead Source' => 'Facebook', 'Lead Sub Status' => 'fresh'],
            ['Client Name' => 'Beena', 'Mobile Number' => '9876543211', 'Project Name' => 'Alpha', 'Lead Source' => 'Facebook', 'Lead Sub Status' => 'fresh'],
        ]);
        $this->assertSame('Client Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Mobile Number', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Project Name', $upload['guessedMapping']['project']);
        $this->assertSame('Lead Source', $upload['guessedMapping']['source']);
        $this->assertSame('Lead Sub Status', $upload['guessedMapping']['stage']);
        $this->assertSame(0, $this->parsed($upload)['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_structure_j_legacy_xls_binary_is_detected(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xls', function (Spreadsheet $book): Spreadsheet {
            $book->getActiveSheet()->fromArray([['Name', 'Mobile', 'Project'], ['Amit', '9876543210', 'Alpha']]);

            return $book;
        });
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
        $this->assertSame(1, $upload['totalRows']);
        $this->assertSame(0, $this->parsed($upload)['summary']['problems']);
        $this->assertSame(1, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_structure_k_xlsx_is_detected(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $book->getActiveSheet()->fromArray([['Full Name', 'Mobile', 'Project'], ['Amit', '9876543210', 'Alpha'], ['Beena', '9876543211', 'Alpha']]);

            return $book;
        });
        $this->assertSame('Full Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('high', $upload['mappingConfidence']['full_name']);
        $this->assertSame(2, $upload['totalRows']);
        $this->assertSame(0, $this->parsed($upload)['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_structure_l_casing_and_spacing_variants_are_normalised(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['NAME' => 'AMIT', 'MOBILE NUMBER' => '9876543210', 'PROJECT NAME' => 'Alpha', 'LEAD SOURCE' => 'Facebook', 'LEAD SUB STATUS' => 'fresh'],
            ['NAME' => 'BEENA', 'MOBILE NUMBER' => '9876543211', 'PROJECT NAME' => 'Alpha', 'LEAD SOURCE' => 'Facebook', 'LEAD SUB STATUS' => 'fresh'],
        ]);
        $this->assertSame('NAME', $upload['guessedMapping']['full_name']);
        $this->assertSame('MOBILE NUMBER', $upload['guessedMapping']['mobile_number']);
        foreach (['full_name', 'mobile_number', 'project', 'source', 'stage'] as $field) {
            $this->assertSame('high', $upload['mappingConfidence'][$field], $field);
        }
        $this->assertSame([], $upload['unresolvedRequired']);
        $this->assertSame(0, $this->parsed($upload)['summary']['problems']);
        $this->assertSame(2, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public function test_structure_m_two_phone_columns_are_reported_as_confirmations(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Name' => 'Amit', 'Mobile' => '9876543210', 'Contact' => '9876543210'],
            ['Name' => 'Beena', 'Mobile' => '9876543211', 'Contact' => '9876543211'],
        ]);
        $this->assertSame(['mobile_number' => ['Mobile', 'Contact']], $upload['confirmations']);
        $this->assertArrayNotHasKey('full_name', $upload['confirmations']);
        $this->assertSame([], $upload['unresolvedRequired']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
    }

    public function test_unresolved_required_name_is_reported_when_no_name_column_exists(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Mobile' => '9876543210', 'Source' => 'Facebook'],
            ['Mobile' => '9876543211', 'Source' => 'Facebook'],
        ]);
        $this->assertSame(['first_name'], $upload['unresolvedRequired']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('Source', $upload['guessedMapping']['source']);
    }

    public function test_thin_phone_evidence_is_mapped_but_scored_low(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Name' => 'Amit', 'Contact Info' => '9876543201'],
            ['Name' => 'Beena', 'Contact Info' => '9876543202'],
            ['Name' => 'Charu', 'Contact Info' => '9876543203'],
            ['Name' => 'Deepa', 'Contact Info' => 'NA'],
            ['Name' => 'Esha', 'Contact Info' => 'NA'],
        ]);
        $this->assertSame('Contact Info', $upload['guessedMapping']['mobile_number']);
        $this->assertSame('low', $upload['mappingConfidence']['mobile_number']);
        $this->assertSame('high', $upload['mappingConfidence']['full_name']);
        $this->assertSame([], $upload['unresolvedRequired']);
    }

    public function test_reimporting_the_same_file_creates_no_duplicates(): void
    {
        $a = $this->actors();
        $rows = [
            ['Name' => 'Amit', 'Mobile' => '9876543210', 'Project' => 'Alpha'],
            ['Name' => 'Beena', 'Mobile' => '9876543211', 'Project' => 'Alpha'],
        ];
        $first = $this->upload($rows);
        $settings = ['project_id' => $a['project']->id];
        $this->parsed($first);
        $this->assertSame(2, $this->finish($first, $this->finalPreview($first, $settings))['created']);

        $again = $this->upload($rows);
        $this->parsed($again);
        $this->assertSame(0, $this->finish($again, $this->finalPreview($again, $settings))['created']);
        $this->assertSame(2, Lead::count());
    }
}
