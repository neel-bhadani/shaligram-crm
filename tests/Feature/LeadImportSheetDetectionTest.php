<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\LeadImport\LeadImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class LeadImportSheetDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(11, 0));
    }

    private function actors(): Project
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $sales = User::factory()->create(['role' => 'salesperson', 'is_active' => true]);
        User::factory()->create(['role' => 'telecaller', 'is_active' => true]);
        $project = Project::create(['name' => 'Alpha', 'is_active' => true]);
        $project->salespeople()->attach($sales);
        $this->actingAs($admin);

        return $project;
    }

    private function uploadSheet(string $extension, callable $build, string $name = 'sheets.xlsx'): array
    {
        $path = Storage::disk('local')->path('fixture.'.$extension);
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('Leads');
        $book = $build($book);
        IOFactory::createWriter($book, $extension === 'xlsx' ? 'Xlsx' : 'Xls')->save($path);
        $book->disconnectWorksheets();

        return $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, $name, test: true)])->assertOk()->json();
    }

    public function test_lead_sheet_plus_summary_sheet_auto_selects_the_lead_sheet(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $leads = $book->getActiveSheet()->setTitle('Leads');
            $leads->fromArray([['Client Name', 'Mobile', 'Project'], ['Yatin Desai', '9876543210', 'Alpha']]);
            $summary = $book->createSheet()->setTitle('Summary');
            $summary->fromArray([['Project', 'Source', 'Count'], ['Alpha', 'Facebook', 12]]);

            return $book;
        });
        $this->assertSame(['Leads', 'Summary'], $upload['sheets']);
        $this->assertSame('Leads', $upload['sheet']);
        $this->assertFalse($upload['sheetAmbiguous']);
        $this->assertSame('Client Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
    }

    public function test_phone_lookup_sheet_is_not_lead_data_and_does_not_trigger_ambiguity(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $leads = $book->getActiveSheet()->setTitle('Leads');
            $leads->fromArray([['Name', 'Phone'], ['Amit', '9876543210']]);
            $lookup = $book->createSheet()->setTitle('Phone Lookup');
            $lookup->fromArray([['Phone', 'Source'], ['9876543201', 'Facebook'], ['9876543202', 'Instagram']]);

            return $book;
        });
        $this->assertSame(['Leads', 'Phone Lookup'], $upload['sheets']);
        $this->assertSame('Leads', $upload['sheet']);
        $this->assertFalse($upload['sheetAmbiguous']);
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Phone', $upload['guessedMapping']['mobile_number']);
    }

    public function test_two_genuine_lead_sheets_report_ambiguity_and_ask_the_user(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $first = $book->getActiveSheet()->setTitle('Leads A');
            $first->fromArray([['Name', 'Mobile', 'Project'], ['Amit', '9876543210', 'Alpha']]);
            $second = $book->createSheet()->setTitle('Leads B');
            $second->fromArray([['Customer Name', 'Phone', 'Source'], ['Beena', '9876543211', 'Facebook']]);

            return $book;
        });
        $this->assertSame(['Leads A', 'Leads B'], $upload['sheets']);
        $this->assertTrue($upload['sheetAmbiguous']);
        $this->assertSame('Leads A', $upload['sheet']);
    }

    public function test_dashboard_like_sheet_is_ignored_in_favour_of_the_lead_data_sheet(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $dashboard = $book->getActiveSheet()->setTitle('Dashboard');
            $dashboard->fromArray([['Status', 'Project', 'Source'], ['fresh', 'Alpha', 'Facebook']]);
            $leadData = $book->createSheet()->setTitle('Lead Data');
            $leadData->fromArray([['Client Name', 'Mobile'], ['Yatin Desai', '9876543210']]);

            return $book;
        });
        $this->assertSame('Lead Data', $upload['sheet']);
        $this->assertFalse($upload['sheetAmbiguous']);
        $this->assertSame('Client Name', $upload['guessedMapping']['full_name']);
        $this->assertSame('Mobile', $upload['guessedMapping']['mobile_number']);
    }

    public function test_hidden_helper_sheet_never_beats_the_visible_lead_sheet(): void
    {
        $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $helper = $book->getActiveSheet()->setTitle('Phone Lookup');
            $helper->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
            $helper->fromArray([['Phone', 'Project'], ['9876543201', 'Alpha']]);
            $leads = $book->createSheet()->setTitle('Leads');
            $leads->fromArray([['Name', 'Mobile'], ['Amit', '9876543210']]);

            return $book;
        });
        $this->assertSame('Leads', $upload['sheet']);
        $this->assertFalse($upload['sheetAmbiguous']);
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);
    }

    public function test_weak_headers_are_recognised_as_lead_data_from_their_values(): void
    {
        $project = $this->actors();
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $sheet = $book->getActiveSheet()->setTitle('People');
            $sheet->fromArray([['Person', 'Contact'], ['Yatin Desai', '9876543210'], ['Rahul Shah', '9898989898']]);
            $notes = $book->createSheet()->setTitle('Notes');
            $notes->fromArray([['Remark'], ['Call later']]);

            return $book;
        });
        $this->assertSame('People', $upload['sheet']);
        $this->assertFalse($upload['sheetAmbiguous']);
        $this->assertSame('Person', $upload['guessedMapping']['full_name']);
        $this->assertSame('Contact', $upload['guessedMapping']['mobile_number']);
        $this->assertSame(2, $upload['totalRows']);
        $this->importAndAssert($upload, $project);
    }

    private function importAndAssert(array $upload, Project $project): void
    {
        $preview = $this->postJson(route('leads.import.preview'), [
            'token' => $upload['token'], 'mapping' => $upload['guessedMapping'], 'date_order' => 'auto',
        ])->assertOk()->json();
        $this->assertSame(0, $preview['summary']['problems']);
        $state = app(LeadImportStore::class)->read(auth()->user(), $upload['token']);
        $final = $this->postJson(route('leads.import.final-preview'), [
            'token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $state['mapping'],
            'date_order' => $state['date_order'], 'defaults' => ['mode' => 'today', 'time' => '14:30', 'follow_up_type' => 'call', 'source' => 'facebook', 'stage' => 'fresh', 'project_id' => $project->id],
        ])->assertOk()->json();
        $offset = 0;
        do {
            $result = $this->postJson(route('leads.import.chunk'), ['token' => $upload['token'], 'plan_id' => $final['plan_id'], 'confirm' => true, 'offset' => $offset])->assertOk()->json();
            $offset = $result['nextOffset'];
        } while (! $result['done']);
        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas('leads', ['mobile_number' => '9876543210', 'first_name' => 'Yatin']);
        $this->assertDatabaseHas('leads', ['mobile_number' => '9898989898', 'first_name' => 'Rahul']);
    }
}
