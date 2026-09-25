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

/**
 * Excluding rows from a bulk-lead-import batch: exclude, restore, persistence,
 * duplicate promotion, follow-up math and backend enforcement.
 *
 * @see LeadImportController::exclude
 * @see LeadImportController::restore
 */
class LeadImportExclusionTest extends TestCase
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
        return $extra + ['Name' => 'Rahul', 'Mobile' => '9876543210', 'Project' => 'Alpha', 'Source' => 'walk_in', 'Stage' => 'fresh'];
    }

    private function cleanRows(int $count, int $startMobile = 9000000001): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['Name' => 'Person '.($i + 1), 'Mobile' => (string) ($startMobile + $i), 'Project' => 'Alpha', 'Source' => 'walk_in', 'Stage' => 'fresh'];
        }

        return $rows;
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

    private function parsed(array $upload, ?array $mapping = null, array $overrides = []): array
    {
        $payload = ['token' => $upload['token'], 'mapping' => $mapping ?? $upload['guessedMapping'], 'date_order' => 'auto'];
        if ($overrides !== []) {
            $payload['overrides'] = $overrides;
        }

        return $this->postJson(route('leads.import.preview'), $payload)->assertOk()->json();
    }

    private function finalPreview(array $upload, array $settings = []): array
    {
        $state = app(LeadImportStore::class)->read(auth()->user(), $upload['token']);

        return $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $state['mapping'], 'date_order' => $state['date_order'], 'defaults' => $settings + [
            'mode' => 'today', 'time' => '10:00', 'follow_up_type' => 'call',
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

    private function exclude(array $upload, int $row): array
    {
        return $this->postJson(route('leads.import.exclude'), ['token' => $upload['token'], 'row' => $row])->assertOk()->json();
    }

    private function restore(array $upload, int $row): array
    {
        return $this->postJson(route('leads.import.restore'), ['token' => $upload['token'], 'row' => $row])->assertOk()->json();
    }

    private function counts(): array
    {
        return collect(['leads', 'todos', 'lead_activities', 'alerts', 'automation_logs', 'message_logs', 'lead_import_records'])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    /* ================= TEST 1 — exclude a clean row ================= */

    public function test_excluding_a_clean_row_drops_it_from_the_batch_without_any_database_write(): void
    {
        $this->actors();
        $upload = $this->upload($this->cleanRows(10));
        $before = $this->counts();
        $preview = $this->parsed($upload);

        $this->assertSame(10, $preview['summary']['total']);
        $this->assertSame(10, $preview['summary']['clean']);
        $this->assertSame(0, $preview['summary']['excluded']);

        $target = $preview['previewRows'][4]['row'];
        $res = $this->exclude($upload, $target);

        $this->assertSame(10, $res['summary']['total']);
        $this->assertSame(9, $res['summary']['clean']);
        $this->assertSame(1, $res['summary']['excluded']);
        $this->assertSame(0, $res['summary']['problems']);
        $excludedRow = collect($res['excludedRows'])->firstWhere('row', $target);
        $this->assertSame('excluded', $excludedRow['outcome']);
        $this->assertSame([], $excludedRow['errors']);
        $this->assertSame(['leads', 'todos', 'lead_activities', 'alerts', 'automation_logs', 'message_logs', 'lead_import_records'], array_keys($before));
        $this->assertSame($before, $this->counts());
    }

    /* ================= TEST 2 — restore ================= */

    public function test_restoring_a_row_makes_it_importable_again(): void
    {
        $this->actors();
        $upload = $this->upload($this->cleanRows(10));
        $target = $this->parsed($upload)['previewRows'][4]['row'];

        $this->assertSame(1, $this->exclude($upload, $target)['summary']['excluded']);
        $res = $this->restore($upload, $target);

        $this->assertSame(10, $res['summary']['clean']);
        $this->assertSame(0, $res['summary']['excluded']);
    }

    /* ================= TEST 3 — exclusion persists ================= */

    public function test_exclusions_survive_preview_and_final_review_round_trips(): void
    {
        $this->actors();
        $upload = $this->upload($this->cleanRows(10));
        $target = $this->parsed($upload)['previewRows'][4]['row'];
        $this->exclude($upload, $target);

        // Review -> Follow-up Setup -> Review
        $res = $this->parsed($upload);
        $this->assertSame(1, $res['summary']['excluded']);
        $this->assertSame(9, $res['summary']['clean']);

        // Review -> Follow-up Setup -> Final Review -> back to Review
        $final = $this->finalPreview($upload);
        $this->assertSame(1, $final['summary']['excluded']);
        $this->assertSame(9, $final['summary']['toCreate']);

        $res = $this->parsed($upload);
        $this->assertSame(1, $res['summary']['excluded']);
        $this->assertSame(9, $res['summary']['clean']);
    }

    /* ================= TEST 4 — edit + exclude + restore ================= */

    public function test_edits_survive_exclusion_and_come_back_on_restore(): void
    {
        $this->actors();
        $upload = $this->upload($this->cleanRows(5));
        $target = $this->parsed($upload)['previewRows'][0]['row'];
        $other = 3;

        $overrides = [$target => ['first_name' => 'Edited Name']];
        $edited = $this->parsed($upload, null, $overrides);
        $editedRow = collect($edited['previewRows'])->firstWhere('row', $target);
        $this->assertSame('Edited Name', $editedRow['attributes']['first_name']);
        $this->assertTrue($editedRow['edited']);

        $excluded = $this->exclude($upload, $target);
        $excludedRow = collect($excluded['excludedRows'])->firstWhere('row', $target);
        $this->assertSame('excluded', $excludedRow['outcome']);
        $this->assertSame('Edited Name', $excludedRow['attributes']['first_name']);
        $this->assertTrue($excludedRow['edited']);

        // editing ANOTHER row does not silently restore the excluded one
        $res = $this->parsed($upload, null, $overrides + [$other => ['first_name' => 'Other Person']]);
        $this->assertSame(1, $res['summary']['excluded']);
        $this->assertSame('Other Person', collect($res['previewRows'])->firstWhere('row', $other)['attributes']['first_name']);

        $restored = $this->restore($upload, $target);
        $row = collect($restored['previewRows'])->firstWhere('row', $target);
        $this->assertSame('Edited Name', $row['attributes']['first_name']);
        $this->assertTrue($row['edited']);
        $this->assertSame(0, $restored['summary']['excluded']);
    }

    /* ================= TEST 5 — mapping change ================= */

    public function test_an_exclusion_survives_a_mapping_change_for_the_same_source_row(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 0; $i < 16; $i++) {
            $rows[] = ['Name' => 'P'.($i + 1), 'Mobile' => (string) (9000000300 + $i), 'Project' => 'Alpha', 'Remarks' => 'note '.$i, 'Source' => 'walk_in', 'Stage' => 'fresh'];
        }
        $upload = $this->upload($rows);
        $map = ['first_name' => 'Name', 'mobile_number' => 'Mobile', 'project' => 'Project', 'source' => 'Source', 'stage' => 'Stage'];

        $this->assertSame(0, $this->parsed($upload, $map)['summary']['excluded']);
        $this->assertSame(1, $this->exclude($upload, 15)['summary']['excluded']);

        $res = $this->parsed($upload, $map + ['requirement' => 'Remarks']);
        $this->assertSame(1, $res['summary']['excluded']);
        $this->assertNotNull(collect($res['excludedRows'])->firstWhere('row', 15));
    }

    /* ================= TEST 6 — sheet change ================= */

    public function test_exclusions_are_scoped_to_their_sheet(): void
    {
        $this->actors();
        $map = ['first_name' => 'Name', 'mobile_number' => 'Mobile', 'project' => 'Project', 'source' => 'Source', 'stage' => 'Stage'];
        $upload = $this->uploadSheet('xlsx', function (Spreadsheet $book): Spreadsheet {
            $first = $book->getActiveSheet()->setTitle('A');
            $first->fromArray([['Name', 'Mobile', 'Project', 'Source', 'Stage']]);
            foreach ($this->cleanRows(5, 9000000100) as $data) {
                $first->fromArray(array_values($data), null, 'A'.($first->getHighestDataRow() + 1));
            }
            $second = $book->createSheet()->setTitle('B');
            $second->fromArray([['Name', 'Mobile', 'Project', 'Source', 'Stage']]);
            foreach ($this->cleanRows(5, 9000000200) as $data) {
                $second->fromArray(array_values($data), null, 'A'.($second->getHighestDataRow() + 1));
            }

            return $book;
        });
        $this->assertSame('A', $upload['sheet']);
        $this->postJson(route('leads.import.sheet'), ['token' => $upload['token'], 'sheet' => 'A'])->assertOk();

        $this->parsed($upload, $map);
        $this->assertSame(1, $this->exclude($upload, 2)['summary']['excluded']);
        $this->assertSame(1, $this->parsed($upload, $map)['summary']['excluded']);

        $this->postJson(route('leads.import.sheet'), ['token' => $upload['token'], 'sheet' => 'B'])->assertOk();
        $res = $this->parsed($upload, $map);
        $this->assertSame(0, $res['summary']['excluded']);
        $this->assertSame('create', collect($res['previewRows'])->firstWhere('row', 2)['outcome']);

        // switching back restores the scoped exclusion
        $this->postJson(route('leads.import.sheet'), ['token' => $upload['token'], 'sheet' => 'A'])->assertOk();
        $this->assertSame(1, $this->parsed($upload, $map)['summary']['excluded']);
    }

    /* ================= TEST 7 — duplicate promotion ================= */

    public function test_excluding_the_first_occurrence_promotes_the_next_duplicate_and_restore_reverses_it(): void
    {
        $this->actors();
        $upload = $this->upload([
            ['Name' => 'Amit', 'Mobile' => '9000000051', 'Project' => 'Alpha', 'Source' => 'walk_in', 'Stage' => 'fresh'],
            ['Name' => 'Beena', 'Mobile' => '9000000051', 'Project' => 'Alpha', 'Source' => 'walk_in', 'Stage' => 'fresh'],
            ['Name' => 'Chintu', 'Mobile' => '9000000052', 'Project' => 'Alpha', 'Source' => 'walk_in', 'Stage' => 'fresh'],
        ]);

        $preview = $this->parsed($upload);
        $this->assertSame('create', collect($preview['previewRows'])->firstWhere('row', 2)['outcome']);
        $this->assertSame('skip_duplicate_file', collect($preview['previewRows'])->firstWhere('row', 3)['outcome']);

        $res = $this->exclude($upload, 2);
        $this->assertSame('excluded', collect($res['excludedRows'])->firstWhere('row', 2)['outcome']);
        $this->assertSame('create', collect($res['previewRows'])->firstWhere('row', 3)['outcome']);
        $this->assertSame(2, $res['summary']['clean']);
        $this->assertSame(1, $res['summary']['excluded']);

        $back = $this->restore($upload, 2);
        $this->assertSame('create', collect($back['previewRows'])->firstWhere('row', 2)['outcome']);
        $this->assertSame('skip_duplicate_file', collect($back['previewRows'])->firstWhere('row', 3)['outcome']);
        $this->assertSame(2, $back['summary']['clean']);
    }

    /* ================= TEST 8 — follow-up count ================= */

    public function test_follow_up_calculations_use_only_active_importable_rows(): void
    {
        $this->actors();
        $rows = $this->cleanRows(95);
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['Name' => 'Bad '.($i + 1), 'Mobile' => (string) (9000000199 + $i), 'Project' => 'Alpha', 'Source' => 'walk_in', 'Stage' => 'NotAStage'];
        }
        $upload = $this->upload($rows);

        $preview = $this->parsed($upload);
        $this->assertSame(100, $preview['summary']['total']);
        foreach (range(2, 11) as $row) {
            $this->exclude($upload, $row);
        }

        $res = $this->parsed($upload);
        $this->assertSame(85, $res['summary']['clean']);
        $this->assertSame(10, $res['summary']['excluded']);
        $this->assertSame(5, $res['summary']['problems']);

        $final = $this->finalPreview($upload);
        $this->assertSame(85, $final['summary']['toCreate']);
        $this->assertSame(10, $final['summary']['excluded']);
        $this->assertSame(5, $final['summary']['problems']);
        $this->assertSame(85, $final['summary']['followUpsToCreate']);
        $this->assertSame(['2026-09-23' => 85], $final['dateDistribution']);
    }

    /* ================= TEST 9 — spread across date range ================= */

    public function test_date_spreading_distributes_only_active_rows(): void
    {
        $this->actors();
        $upload = $this->upload($this->cleanRows(10));
        $this->parsed($upload);
        $this->exclude($upload, 2);
        $this->exclude($upload, 3);

        $final = $this->finalPreview($upload, ['mode' => 'spread', 'start_date' => '2026-09-23', 'end_date' => '2026-09-24', 'time' => '10:00']);

        $this->assertSame(8, $final['summary']['toCreate']);
        $this->assertSame(2, $final['summary']['excluded']);
        $this->assertSame(['2026-09-23' => 4, '2026-09-24' => 4], $final['dateDistribution']);
        $this->assertSame(8, $final['summary']['followUpsToCreate']);
    }

    /* ================= TEST 10 — final import ================= */

    public function test_final_import_creates_nothing_for_excluded_rows(): void
    {
        $this->actors();
        $upload = $this->upload($this->cleanRows(6));
        $this->parsed($upload);
        // Physical rows 2-4 are the first three data rows (mobiles 9000000001-003).
        foreach ([2, 3, 4] as $row) {
            $this->exclude($upload, $row);
        }

        $final = $this->finalPreview($upload);
        $this->assertSame(3, $final['summary']['toCreate']);
        $this->assertSame(3, $final['summary']['excluded']);
        $this->assertSame(3, count($final['excludedRows']));

        $result = $this->finish($upload, $final);
        $this->assertSame(3, $result['created']);
        $this->assertSame(3, $result['excluded']);
        $this->assertSame(3, $result['followUps']);
        $this->assertSame(3, Lead::count());
        $this->assertSame(0, Lead::whereIn('mobile_number', ['9000000001', '9000000002', '9000000003'])->count());
        $this->assertSame(3, DB::table('todos')->count());
    }

    /* ================= TEST 11 — tampered request ================= */

    public function test_the_backend_ignores_a_stale_plan_and_never_imports_an_excluded_row(): void
    {
        $this->actors();
        $upload = $this->upload($this->cleanRows(6));
        $this->parsed($upload);
        $final = $this->finalPreview($upload);
        $this->assertSame(6, $final['summary']['toCreate']);

        $this->exclude($upload, 2);

        // a plan_id captured before the exclusion is no longer accepted
        $this->postJson(route('leads.import.chunk'), ['token' => $upload['token'], 'plan_id' => $final['plan_id'], 'confirm' => true, 'offset' => 0])->assertStatus(409);

        // a forged row payload is rejected outright, we never trust the frontend list
        $this->postJson(route('leads.import.chunk'), ['token' => $upload['token'], 'plan_id' => $final['plan_id'], 'confirm' => true, 'offset' => 0, 'rows' => [['first_name' => 'Hax']]])->assertStatus(422);

        $again = $this->finalPreview($upload);
        $this->assertSame(5, $again['summary']['toCreate']);
        $this->assertSame(1, $again['summary']['excluded']);

        $result = $this->finish($upload, $again);
        $this->assertSame(5, $result['created']);
        $this->assertSame(1, $result['excluded']);
        // physical row 2 is the first data row (mobile 9000000001)
        $this->assertSame(0, Lead::where('mobile_number', '9000000001')->count());
        $this->assertSame(5, Lead::count());
    }

    /* ================= TEST 12 — authorization ================= */

    public function test_a_telecaller_is_forbidden_from_exclude_and_restore(): void
    {
        $a = $this->actors();
        $upload = $this->upload($this->cleanRows(3));
        $this->parsed($upload);

        $this->actingAs($a['caller'])->postJson(route('leads.import.exclude'), ['token' => $upload['token'], 'row' => 2])->assertForbidden();
        $this->actingAs($a['caller'])->postJson(route('leads.import.restore'), ['token' => $upload['token'], 'row' => 2])->assertForbidden();
    }

    public function test_a_salesperson_can_exclude_and_restore_rows(): void
    {
        $a = $this->actors();
        $this->actingAs($a['sales']);
        $upload = $this->upload($this->cleanRows(4));
        $this->parsed($upload);

        $this->assertSame(1, $this->exclude($upload, 2)['summary']['excluded']);
        $this->assertSame(0, $this->restore($upload, 2)['summary']['excluded']);
    }
}
