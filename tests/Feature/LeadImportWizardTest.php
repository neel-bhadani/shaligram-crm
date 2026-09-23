<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadImportRecord;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Automation\RuleEngine;
use App\Services\LeadCreationService;
use App\Services\LeadImport\LeadImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadImportWizardTest extends TestCase
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
            'mode' => 'today', 'time' => '14:30', 'follow_up_type' => 'call',
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

    private function counts(): array
    {
        return collect(['leads', 'todos', 'lead_activities', 'alerts', 'automation_logs', 'message_logs', 'lead_import_records'])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    public function test_admin_and_salesperson_access_and_all_other_roles_are_denied_even_with_add_permission(): void
    {
        $actors = $this->actors();
        foreach ([$actors['admin'], $actors['sales']] as $user) {
            $this->actingAs($user)->get(route('leads.import'))->assertOk();
        }
        foreach (['telecaller', 'sales_manager'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true, 'permissions' => ['add_leads' => true]]);
            $this->actingAs($user)->get(route('leads.import'))->assertForbidden();
            foreach (['upload', 'sheet', 'preview', 'final-preview', 'chunk', 'cancel'] as $endpoint) {
                $this->postJson(route('leads.import.'.$endpoint), [])->assertForbidden();
            }
            $this->getJson(route('leads.import.download'))->assertForbidden();
        }
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_guests_cannot_access_import(): void
    {
        $this->get(route('leads.import'))->assertRedirect(route('login'));
        $this->postJson(route('leads.import.upload'))->assertUnauthorized();
    }

    public function test_preview_one_reports_all_problem_types_without_business_writes(): void
    {
        $a = $this->actors();
        Project::create(['name' => 'Beta']);
        Lead::create(['first_name' => 'Existing', 'last_name' => '', 'mobile_number' => '9000000001', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $before = $this->counts();
        $this->actingAs($a['sales']);
        $rows = [
            $this->row(), $this->row(),
            $this->row(['Mobile' => '12345']),
            $this->row(['Mobile' => '9000000002', 'Stage' => 'Hot Prospect']),
            $this->row(['Mobile' => '9000000003', 'Source' => 'FB Ads 2025']),
            $this->row(['Mobile' => '9000000004', 'Name' => '']),
            $this->row(['Mobile' => '9000000001']),
            $this->row(['Mobile' => '9000000005', 'Project' => 'Beta']),
            $this->row(['Mobile' => '9000000006', 'Project' => 'Missing']),
        ];
        $preview = $this->parsed($this->upload($rows));
        foreach (['invalidMobile', 'unknownStage', 'unknownSource', 'missingRequired', 'duplicatesInFile', 'duplicatesInDatabase', 'unauthorizedProject', 'unknownProject'] as $key) {
            $this->assertSame(1, $preview['summary'][$key], $key);
        }
        $this->assertCount(8, $preview['problemRows']);
        $this->assertSame($before, $this->counts());
    }

    public function test_unmapped_defaults_are_deferred_and_mapping_changes_without_reupload(): void
    {
        $a = $this->actors();
        $before = $this->counts();
        $upload = $this->upload([['Name' => 'Amit', 'Mobile' => '9876543210', 'Alternative' => '12345']]);
        $this->assertSame(['full_name' => 'Name', 'mobile_number' => 'Mobile'], $upload['guessedMapping']);
        $this->assertSame('Amit', $upload['sampleRows'][0]['Name']);
        $first = $this->parsed($upload);
        $this->assertSame(0, $first['summary']['problems']);
        $this->assertContains('Duplicate check pending project selection', $first['previewRows'][0]['pending']);
        $second = $this->parsed($upload, ['first_name' => 'Name', 'mobile_number' => 'Alternative']);
        $this->assertSame(1, $second['summary']['invalidMobile']);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $this->assertSame(1, $final['summary']['toCreate']);
        $this->assertSame($before, $this->counts());
    }

    public function test_default_project_rechecks_duplicates_and_salesperson_membership(): void
    {
        $a = $this->actors();
        $beta = Project::create(['name' => 'Beta']);
        $a['sales']->update(['permissions' => ['see_all_leads' => true, 'add_leads' => true]]);
        $this->actingAs($a['sales']);
        Lead::create(['first_name' => 'Old', 'last_name' => '', 'mobile_number' => '9876543210', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $upload = $this->upload([['Name' => 'New', 'Mobile' => '9876543210']]);
        $this->assertSame(0, $this->parsed($upload)['summary']['duplicatesInDatabase']);
        $settings = ['source' => 'facebook', 'stage' => 'fresh', 'mode' => 'today', 'time' => '14:00', 'follow_up_type' => 'call'];
        $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $upload['guessedMapping'], 'defaults' => $settings + ['project_id' => $beta->id]])->assertUnprocessable()->assertJsonValidationErrors('defaults.project_id');
        $final = $this->finalPreview($upload, $settings + ['project_id' => $a['project']->id]);
        $this->assertSame(1, $final['summary']['duplicatesInDatabase']);
        $this->assertSame(0, $this->finish($upload, $final)['created']);
    }

    public static function formats(): array
    {
        return [['Xls', 'xls'], ['Xlsx', 'xlsx']];
    }

    #[DataProvider('formats')]
    public function test_excel_formats_preserve_numeric_mobiles_dates_and_selectable_sheets(string $writer, string $extension): void
    {
        $this->actors();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('First')->fromArray([['Name', 'Mobile'], ['A', 9876543210]]);
        $sheet = $book->createSheet()->setTitle('Second');
        $sheet->fromArray([['Name', 'Mobile', 'Created At'], ['B', '9876543211', 46266.5]]);
        $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('dd-mm-yyyy hh:mm:ss');
        $path = Storage::disk('local')->path('fixture.'.$extension);
        IOFactory::createWriter($book, $writer)->save($path);
        $book->disconnectWorksheets();
        $upload = $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'leads.'.$extension, test: true)])->assertOk()->json();
        $this->assertSame(['First', 'Second'], $upload['sheets']);
        $this->assertSame('9876543210', $upload['sampleRows'][0]['Mobile']);
        $second = $this->postJson(route('leads.import.sheet'), ['token' => $upload['token'], 'sheet' => 'Second'])->assertOk()->json();
        $this->assertSame('2026-09-01 12:00:00', $second['sampleRows'][0]['Created At']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_csv_bom_quoted_commas_and_newlines(): void
    {
        $this->actors();
        $file = UploadedFile::fake()->createWithContent('leads.csv', "\xEF\xBB\xBFName,Mobile,Notes\r\n\"Amit, Shah\",9876543210,\"line one\nline two\"\r\n");
        $upload = $this->postJson(route('leads.import.upload'), ['file' => $file])->assertOk()->json();
        $this->assertSame('Name', $upload['headers'][0]);
        $this->assertSame('Amit, Shah', $upload['sampleRows'][0]['Name']);
        $this->assertSame("line one\nline two", $upload['sampleRows'][0]['Notes']);
    }

    public static function invalidFiles(): array
    {
        return [['empty.csv', ''], ['header.csv', 'Name,Mobile'], ['bad.xlsx', 'not a zip'], ['bad.xls', 'not excel'], ['file.txt', "Name,Mobile\nA,9876543210"], ['duplicate.csv', "Name,Name\nA,B"], ['malformed.csv', "Name,Mobile\nA,\"9876543210"]];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_files_are_rejected_and_removed(string $name, string $content): void
    {
        $this->actors();
        $this->postJson(route('leads.import.upload'), ['file' => UploadedFile::fake()->createWithContent($name, $content)])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('lead-imports'));
    }

    public function test_ambiguous_dates_require_confirmation_and_created_at_is_preserved(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Created At' => '9-10-26 15:45:12'])]);
        $this->assertSame(1, $this->parsed($upload)['summary']['invalidDate']);
        $preview = $this->parsed($upload, dateOrder: 'dmy');
        $this->assertSame('2026-10-09 15:45:12', $preview['previewRows'][0]['attributes']['created_at']);
        $final = $this->finalPreview($upload);
        $this->finish($upload, $final);
        $this->assertSame('2026-10-09 15:45:12', Lead::first()->created_at->format('Y-m-d H:i:s'));
    }

    public static function schedules(): array
    {
        return [
            'today' => [['mode' => 'today'], ['2026-09-23' => 7]],
            'specific' => [['mode' => 'specific', 'start_date' => '2026-10-01', 'end_date' => '2026-09-23'], ['2026-10-01' => 7]],
            'spread' => [['mode' => 'spread', 'start_date' => '2026-10-01', 'end_date' => '2026-10-03'], ['2026-10-01' => 3, '2026-10-02' => 2, '2026-10-03' => 2]],
        ];
    }

    #[DataProvider('schedules')]
    public function test_schedules_match_final_preview_and_no_dates_are_recomputed(array $settings, array $expected): void
    {
        $this->actors();
        $rows = [];
        for ($i = 0; $i < 7; $i++) {
            $rows[] = $this->row(['Mobile' => (string) (9000000000 + $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $before = $this->counts();
        $final = $this->finalPreview($upload, $settings);
        $this->assertSame($expected, $final['dateDistribution']);
        $this->assertSame($before, $this->counts());
        $this->travel(1)->days();
        $result = $this->finish($upload, $final);
        $this->assertSame(7, $result['followUps']);
        foreach ($final['previewRows'] as $row) {
            $lead = Lead::where('mobile_number', $row['attributes']['mobile_number'])->firstOrFail();
            $this->assertSame(substr($row['follow_up_at'], 0, 10).' 14:30:00', $lead->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-09-24 11:00:00', $lead->created_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_chunk_offsets_do_not_skip_rows_and_retries_do_not_duplicate(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 0; $i < 405; $i++) {
            $rows[] = $this->row(['Mobile' => (string) (9000000000 + $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $final = $this->finalPreview($upload);
        $body = ['token' => $upload['token'], 'plan_id' => $final['plan_id'], 'confirm' => true, 'offset' => 0];
        $this->postJson(route('leads.import.chunk'), $body)->assertOk()->assertJsonPath('created', 200)->assertJsonPath('done', false);
        $this->postJson(route('leads.import.chunk'), $body)->assertOk()->assertJsonPath('created', 200);
        $result = $this->finish($upload, $final);
        $this->assertSame(405, $result['created']);
        $this->assertDatabaseCount('leads', 405);
        $this->assertDatabaseCount('todos', 405);
    }

    public function test_final_plan_is_required_and_invalidated_by_mapping_changes(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row()]);
        $this->postJson(route('leads.import.chunk'), ['token' => $upload['token'], 'confirm' => true, 'offset' => 0])->assertUnprocessable();
        $this->parsed($upload);
        $final = $this->finalPreview($upload);
        $body = ['token' => $upload['token'], 'plan_id' => $final['plan_id'], 'confirm' => true, 'offset' => 0];
        $this->postJson(route('leads.import.chunk'), $body + ['rows' => [['project_id' => 999]]])->assertUnprocessable();
        $this->parsed($upload);
        $this->postJson(route('leads.import.chunk'), $body)->assertConflict();
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_revoked_project_permission_and_assignee_are_rechecked_at_execution(): void
    {
        $a = $this->actors();
        $this->actingAs($a['sales']);
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload);
        $a['project']->salespeople()->detach($a['sales']);
        $result = $this->finish($upload, $final);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('todos', 0);
    }

    public function test_cancel_expiry_and_cross_user_access_keep_uploads_private(): void
    {
        $a = $this->actors();
        $upload = $this->upload([$this->row()]);
        $this->actingAs($a['sales'])->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $upload['guessedMapping']])->assertNotFound();
        $this->actingAs($a['admin'])->postJson(route('leads.import.cancel'), ['token' => $upload['token']])->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles('lead-imports'));
        $this->upload([$this->row()]);
        $this->travel(25)->hours();
        $this->artisan('leads:prune-imports')->assertSuccessful();
        $this->assertSame([], Storage::disk('local')->allFiles('lead-imports'));
    }

    public function test_side_effects_are_suppressed_but_audit_and_pending_todo_are_preserved(): void
    {
        $a = $this->actors();
        Queue::fake();
        Http::preventStrayRequests();
        AutomationRule::create(['name' => 'Alert on create', 'trigger' => 'lead_created', 'conditions' => [], 'actions' => [['type' => 'raise_alert', 'recipient' => 'admins', 'title' => 'Created']], 'is_active' => true, 'created_by' => $a['admin']->id]);
        $template = MessageTemplate::create(['name' => 'Import greeting', 'body' => 'Hello {{first_name}}', 'is_active' => true]);
        AutomationRule::create(['name' => 'Message on create', 'trigger' => 'lead_created', 'conditions' => [], 'actions' => [['type' => 'queue_whatsapp', 'template_id' => $template->id]], 'is_active' => true, 'created_by' => $a['admin']->id]);
        $upload = $this->upload([$this->row(), $this->row(['Mobile' => '9000000002', 'Stage' => 'lost'])]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload);
        $result = $this->finish($upload, $final);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['followUps']);
        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('automation_logs', 0);
        $this->assertDatabaseCount('message_logs', 0);
        $this->assertGreaterThan(0, LeadActivity::count());
        $this->assertSame(1, Lead::where('stage', 'fresh')->first()->todos()->where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $lead = Lead::where('stage', 'fresh')->first();
        app(AlertService::class)->raise($a['admin'], 'test', 'After import', lead: $lead);
        $this->assertDatabaseCount('alerts', 1);
        app(RuleEngine::class)->dispatch('lead_created', $lead);
        $this->assertGreaterThan(0, AutomationLog::count());
        $this->assertDatabaseCount('message_logs', 1);
    }

    public function test_changed_file_still_obeys_mobile_project_uniqueness(): void
    {
        $this->actors();
        Project::create(['name' => 'Beta']);
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload);
        $this->finish($upload, $this->finalPreview($upload));
        $changed = $this->upload([$this->row(['Name' => 'Changed']), $this->row(['Name' => 'Changed', 'Project' => 'Beta'])]);
        $preview = $this->parsed($changed);
        $this->assertSame(1, $preview['summary']['duplicatesInDatabase']);
        $result = $this->finish($changed, $this->finalPreview($changed));
        $this->assertSame(1, $result['created']);
        $this->assertDatabaseCount('leads', 2);
        $this->assertSame('Rahul', Lead::oldest('id')->first()->first_name);
    }

    public function test_ten_thousand_rows_are_fully_validated_with_batched_duplicate_queries(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = $this->row(['Mobile' => (string) (9000000000 + $i)]);
        }
        $upload = $this->upload($rows);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "leads"')) {
                $queries++;
            }
        });
        $preview = $this->parsed($upload);
        $this->assertSame(10000, $preview['summary']['clean']);
        $this->assertCount(30, $preview['previewRows']);
        $this->assertLessThanOrEqual(21, $queries);
        $final = $this->finalPreview($upload);
        $this->assertSame(10000, $final['summary']['toCreate']);
        $this->assertCount(20, $final['previewRows']);
        $result = $this->finish($upload, $final);
        $this->assertSame(10000, $result['created']);
        $this->assertSame(10000, $result['followUps']);
        $this->assertSame(0, $result['afterOpenWithoutPending']);
    }

    public function test_row_failure_rolls_back_its_lead_todo_and_audit_without_losing_other_rows(): void
    {
        $this->actors();
        $real = app(LeadCreationService::class);
        $mock = \Mockery::mock(LeadCreationService::class);
        $mock->shouldReceive('create')->andReturnUsing(function (...$arguments) use ($real) {
            $lead = $real->create(...$arguments);
            if ($lead->first_name === 'Fail') {
                throw new \RuntimeException('Simulated failure after persistence');
            }

            return $lead;
        });
        $this->app->instance(LeadCreationService::class, $mock);
        $upload = $this->upload([$this->row(), $this->row(['Name' => 'Fail', 'Mobile' => '9000000001']), $this->row(['Name' => 'Last', 'Mobile' => '9000000002'])]);
        $this->parsed($upload);
        $result = $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseMissing('leads', ['first_name' => 'Fail']);
        $this->assertDatabaseCount('todos', 2);
        $this->assertDatabaseCount('lead_import_records', 2);
        $this->assertSame(0, LeadActivity::whereNotIn('lead_id', Lead::pluck('id'))->count());
        $csv = $this->get(route('leads.import.download', ['token' => $upload['token']]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Fail,9000000001', $csv);
        $this->assertStringNotContainsString('Rahul', $csv);
    }

    public function test_mapped_assignee_role_membership_and_active_state_are_validated(): void
    {
        $a = $this->actors();
        $outsider = User::factory()->role('salesperson')->create();
        $inactive = User::factory()->inactive()->create();
        $upload = $this->upload([
            $this->row(['Assigned To' => $outsider->email, 'Stage' => 'in_discussion']),
            $this->row(['Assigned To' => $inactive->email, 'Mobile' => '9000000001', 'Stage' => 'in_discussion']),
            $this->row(['Assigned To' => $a['sales']->email, 'Mobile' => '9000000002']),
        ]);
        $preview = $this->parsed($upload);
        $this->assertSame(3, $preview['summary']['invalidAssignee']);
        $this->assertSame(0, $this->finish($upload, $this->finalPreview($upload))['created']);
    }

    public static function dates(): array
    {
        return [
            ['9-23-26 13:14:15', 'auto', '2026-09-23 13:14:15'],
            ['23-9-26 13:14:15', 'auto', '2026-09-23 13:14:15'],
            ['9-10-26', 'mdy', '2026-09-10 00:00:00'],
            ['46266.5', 'auto', '2026-09-01 12:00:00'],
            ['2026-09-23T10:00:00+05:30', 'auto', '2026-09-23 10:00:00'],
        ];
    }

    #[DataProvider('dates')]
    public function test_supported_dates_are_deterministic(string $input, string $order, string $expected): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Created At' => $input])]);
        $preview = $this->parsed($upload, dateOrder: $order);
        $this->assertSame(0, $preview['summary']['invalidDate']);
        $this->assertSame($expected, $preview['previewRows'][0]['attributes']['created_at']);
    }

    public function test_unknown_mapping_keys_and_missing_required_mapping_are_rejected(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row()]);
        foreach ([['password' => 'Name'], ['first_name' => 'Missing', 'mobile_number' => 'Mobile'], ['first_name' => 'Name']] as $mapping) {
            $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $mapping])->assertUnprocessable();
        }
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_renamed_project_cannot_redirect_validation_away_from_the_saved_plan(): void
    {
        $a = $this->actors();
        $this->actingAs($a['sales']);
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload);
        $a['project']->update(['name' => 'Former Alpha']);
        $a['project']->salespeople()->detach($a['sales']);
        $replacement = Project::create(['name' => 'Alpha']);
        $replacement->salespeople()->attach($a['sales']);
        $result = $this->finish($upload, $final);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_unrecoverable_source_failure_removes_private_files(): void
    {
        $a = $this->actors();
        $upload = $this->upload([$this->row()]);
        $state = app(LeadImportStore::class)->read($a['admin'], $upload['token']);
        Storage::disk('local')->put($state['source'], '');
        $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $upload['guessedMapping']])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('lead-imports'));
        $this->assertDatabaseCount('leads', 0);
    }

    public static function nameSplits(): array
    {
        return [
            'one word' => ['Rahul', ['first_name' => 'Rahul', 'middle_name' => '', 'last_name' => '']],
            'two words' => ['Rahul Shah', ['first_name' => 'Rahul', 'middle_name' => '', 'last_name' => 'Shah']],
            'three words' => ['Rahul Kumar Shah', ['first_name' => 'Rahul', 'middle_name' => 'Kumar', 'last_name' => 'Shah']],
            'four words' => ['Rahul Kumar Dev Shah', ['first_name' => 'Rahul', 'middle_name' => 'Kumar Dev', 'last_name' => 'Shah']],
            'prefix kept' => ['Mr. Rahul Shah', ['first_name' => 'Mr.', 'middle_name' => 'Rahul', 'last_name' => 'Shah']],
            'client sample "mit"' => ['mit', ['first_name' => 'mit', 'middle_name' => '', 'last_name' => '']],
            'client sample "p"' => ['p', ['first_name' => 'p', 'middle_name' => '', 'last_name' => '']],
            'client sample "deepak kumar"' => ['deepak kumar', ['first_name' => 'deepak', 'middle_name' => '', 'last_name' => 'kumar']],
        ];
    }

    #[DataProvider('nameSplits')]
    public function test_full_name_auto_split_splits_by_word_count(string $name, array $expected): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => $name])]);
        $this->assertSame('Name', $upload['guessedMapping']['full_name']);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        foreach ($expected as $field => $value) {
            $this->assertSame($value, $preview['previewRows'][0]['attributes'][$field]);
        }
    }

    public function test_full_name_is_trimmed_and_internal_whitespace_collapsed_without_losing_tokens(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => '  Rahul    Kumar   Shah  '])]);
        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['problems']);
        $attributes = $preview['previewRows'][0]['attributes'];
        $this->assertSame('Rahul', $attributes['first_name']);
        $this->assertSame('Kumar', $attributes['middle_name']);
        $this->assertSame('Shah', $attributes['last_name']);
    }

    public function test_client_name_and_other_combined_headers_auto_guess_and_split(): void
    {
        $this->actors();
        foreach (['client_name', 'customer_name', 'full_name', 'lead_name', 'Client Name', 'Customer Name', 'Full Name', 'Lead Name'] as $header) {
            $upload = $this->upload([[$header => 'Yatin Desai', 'Mobile' => '9876543210', 'Project' => 'Alpha', 'Source' => 'Facebook', 'Stage' => 'fresh']]);
            $this->assertSame($header, $upload['guessedMapping']['full_name'], $header);
            $preview = $this->parsed($upload);
            $this->assertSame('Yatin', $preview['previewRows'][0]['attributes']['first_name'], $header);
            $this->assertSame('', $preview['previewRows'][0]['attributes']['middle_name'], $header);
            $this->assertSame('Desai', $preview['previewRows'][0]['attributes']['last_name'], $header);
        }
    }

    public function test_full_name_together_with_separate_first_middle_last_columns_is_rejected(): void
    {
        $this->actors();
        $upload = $this->upload([['First Name' => 'Amit', 'Middle Name' => 'Kumar', 'Last Name' => 'Shah', 'Name' => 'Amit Kumar Shah', 'Mobile' => '9876543210', 'Project' => 'Alpha', 'Source' => 'Facebook', 'Stage' => 'fresh']]);
        $mapping = $upload['guessedMapping'];
        $this->assertSame('Name', $mapping['full_name']);
        $headersOf = ['first_name' => 'First Name', 'middle_name' => 'Middle Name', 'last_name' => 'Last Name'];
        foreach ([['first_name'], ['middle_name'], ['last_name'], ['first_name', 'middle_name', 'last_name']] as $separate) {
            $conflict = $mapping;
            foreach ($separate as $field) {
                $conflict[$field] = $headersOf[$field];
            }
            $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $conflict])->assertUnprocessable()->assertJsonValidationErrors('mapping');
        }
        $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $mapping])->assertUnprocessable()->assertJsonValidationErrors('mapping');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_middle_name_maps_directly_from_its_own_column(): void
    {
        $this->actors();
        $upload = $this->upload([['First Name' => 'Amit', 'Middle Name' => 'Kumar', 'Last Name' => 'Shah', 'Mobile' => '9876543210', 'Project' => 'Alpha', 'Source' => 'Facebook', 'Stage' => 'fresh']]);
        $preview = $this->parsed($upload);
        $attributes = $preview['previewRows'][0]['attributes'];
        $this->assertSame('Amit', $attributes['first_name']);
        $this->assertSame('Kumar', $attributes['middle_name']);
        $this->assertSame('Shah', $attributes['last_name']);
    }

    public function test_full_name_mapping_can_be_changed_without_reuploading(): void
    {
        $a = $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'Rahul Kumar Shah'])]);
        $split = $this->parsed($upload);
        $this->assertSame('Rahul', $split['previewRows'][0]['attributes']['first_name']);
        $overridden = $this->parsed($upload, ['first_name' => 'Name', 'mobile_number' => 'Mobile']);
        $this->assertSame('Rahul Kumar Shah', $overridden['previewRows'][0]['attributes']['first_name']);
        $final = $this->finalPreview($upload, ['project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $this->assertSame(1, $final['summary']['toCreate']);
        $this->finish($upload, $final);
        $this->assertSame('Rahul Kumar Shah', Lead::first()->first_name);
    }

    public function test_file_follow_up_date_and_time_drive_the_exact_scheduled_at(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Follow-up Date' => '2026-10-05', 'Follow-up Time' => '18:30', 'Mobile' => '9000000010'])]);
        $mapping = $upload['guessedMapping'];
        $this->assertSame('Follow-up Date', $mapping['follow_up_date']);
        $this->assertSame('Follow-up Time', $mapping['follow_up_time']);
        $this->parsed($upload, $mapping);
        $final = $this->finalPreview($upload, ['time_source' => 'uploaded', 'fallback_time' => '09:00']);
        $this->assertSame(['2026-10-05' => 1], $final['dateDistribution']);
        $this->assertSame('2026-10-05T18:30:00', substr($final['previewRows'][0]['follow_up_at'], 0, 19));
        $this->finish($upload, $final);
        $todo = Lead::where('mobile_number', '9000000010')->firstOrFail()->pendingTodo;
        $this->assertSame('2026-10-05 18:30:00', $todo->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_a_single_follow_up_datetime_column_maps_both_date_and_time(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Scheduled At' => '2026-10-01 15:30:00', 'Mobile' => '9000000011'])]);
        $this->assertSame('Scheduled At', $upload['guessedMapping']['follow_up_datetime']);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_source' => 'uploaded', 'fallback_time' => '09:00']);
        $this->assertSame('2026-10-01T15:30:00', substr($final['previewRows'][0]['follow_up_at'], 0, 19));
        $this->finish($upload, $final);
        $todo = Lead::where('mobile_number', '9000000011')->firstOrFail()->pendingTodo;
        $this->assertSame('2026-10-01 15:30:00', $todo->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_rows_without_a_file_time_use_the_fallback_time(): void
    {
        $this->actors();
        $upload = $this->upload([
            $this->row(['Follow-up Time' => '11:15', 'Mobile' => '9000000012']),
            $this->row(['Follow-up Time' => '', 'Mobile' => '9000000013']),
        ]);
        $preview = $this->parsed($upload);
        $this->assertSame(1, $preview['summary']['missingFollowUpTimes']);
        $final = $this->finalPreview($upload, ['time_source' => 'uploaded', 'fallback_time' => '09:45']);
        $this->finish($upload, $final);
        $withTime = Lead::where('mobile_number', '9000000012')->firstOrFail()->pendingTodo;
        $without = Lead::where('mobile_number', '9000000013')->firstOrFail()->pendingTodo;
        $this->assertSame('2026-09-23 11:15:00', $withTime->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-23 09:45:00', $without->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_without_a_file_time_column_only_a_single_fixed_time_is_allowed(): void
    {
        $a = $this->actors();
        $upload = $this->upload([$this->row()]);
        $this->assertArrayNotHasKey('follow_up_time', $upload['guessedMapping']);
        $this->parsed($upload);
        $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $upload['guessedMapping'], 'defaults' => ['time_source' => 'uploaded', 'fallback_time' => '09:00', 'mode' => 'today', 'time' => '14:30', 'follow_up_type' => 'call', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']])->assertUnprocessable()->assertJsonValidationErrors('defaults.time_mode');
        $final = $this->finalPreview($upload, ['project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $this->assertSame('2026-09-23T14:30:00', substr($final['previewRows'][0]['follow_up_at'], 0, 19));
        $this->finish($upload, $final);
        $this->assertSame('2026-09-23 14:30:00', Lead::first()->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_invalid_follow_up_time_is_flagged_in_preview_one(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Time' => '10:70 AM', 'Mobile' => '9000000014'])]);
        $preview = $this->parsed($upload);
        $this->assertSame(1, $preview['summary']['invalidTime']);
        $problem = collect($preview['problemRows'])->firstWhere('outcome', 'error');
        $this->assertStringContainsString('Invalid follow-up time: "10:70 AM"', $problem['errors'][0]);
    }

    public function test_invalid_follow_up_date_is_flagged_in_preview_one(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Follow-up Date' => 'not-a-date', 'Mobile' => '9000000015'])]);
        $preview = $this->parsed($upload);
        $this->assertSame(1, $preview['summary']['invalidFollowUpDate']);
        $problem = collect($preview['problemRows'])->firstWhere('outcome', 'error');
        $this->assertStringContainsString('Invalid follow-up date: "not-a-date"', $problem['errors'][0]);
    }

    public function test_specific_mode_uses_file_dates_and_falls_back_to_the_schedule_date(): void
    {
        $this->actors();
        $upload = $this->upload([
            $this->row(['Follow-up Date' => '2026-10-20', 'Mobile' => '9000000016']),
            $this->row(['Follow-up Date' => '', 'Mobile' => '9000000017']),
        ]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['mode' => 'specific', 'start_date' => '2026-10-01']);
        $this->assertSame(['2026-10-20' => 1, '2026-10-01' => 1], $final['dateDistribution']);
        $this->finish($upload, $final);
        $withDate = Lead::where('mobile_number', '9000000016')->firstOrFail()->pendingTodo;
        $without = Lead::where('mobile_number', '9000000017')->firstOrFail()->pendingTodo;
        $this->assertSame('2026-10-20 14:30:00', $withDate->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 14:30:00', $without->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_original_lead_18_sep_file_walkthrough_and_reimport(): void
    {
        $path = '/home/etech7/Downloads/Lead 18 Sep.xls';
        if (! is_file($path)) {
            $rows = array_map(fn (array $row): array => Arr::except($row['_legacy'], ['_row_number']), json_decode(file_get_contents(base_path('lead-18-sep-data/leads.json')), true));
            $book = new Spreadsheet;
            $book->getActiveSheet()->setTitle('Lead')->fromArray([array_keys($rows[0]), ...array_map('array_values', $rows)]);
            $path = Storage::disk('local')->path('Lead 18 Sep.xls');
            IOFactory::createWriter($book, 'Xls')->save($path);
            $book->disconnectWorksheets();
        }
        $a = $this->actors();
        foreach (['Felicity', 'Skydeck'] as $name) {
            $project = Project::create(['name' => $name]);
            $project->salespeople()->attach($a['sales']);
        }
        for ($i = 0; $i < 16; $i++) {
            Lead::create(['first_name' => 'Historical', 'last_name' => '', 'mobile_number' => (string) (8000000000 + $i), 'project_id' => $a['project']->id, 'stage' => 'fresh', 'source' => 'facebook']);
        }
        $hash = hash_file('sha256', $path);
        $before = $this->counts();
        $upload = $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'Lead 18 Sep.xls', test: true)])->assertOk()->json();
        $this->assertSame(55, $upload['totalRows']);
        $this->assertSame('Lead', $upload['sheet']);
        $mapping = $upload['guessedMapping'];
        unset($mapping['assigned_user']);
        $mapping['stage'] = 'lead_sub_status';
        $initial = $this->parsed($upload, $mapping);
        unset($mapping['stage']);
        $preview = $this->parsed($upload, $mapping);
        $today = $this->finalPreview($upload, ['stage' => 'fresh']);
        $this->assertSame(['2026-09-23' => 52], $today['dateDistribution']);
        $specific = $this->finalPreview($upload, ['stage' => 'fresh', 'mode' => 'specific', 'start_date' => '2026-10-01']);
        $this->assertSame(['2026-10-01' => 52], $specific['dateDistribution']);
        $final = $this->finalPreview($upload, ['stage' => 'fresh', 'mode' => 'spread', 'start_date' => '2026-09-23', 'end_date' => '2026-09-27']);
        $this->assertSame($before, $this->counts());
        $this->assertCount(30, $preview['previewRows']);
        $this->assertCount(20, $final['previewRows']);
        $result = $this->finish($upload, $final);
        $this->assertSame(16, $result['beforeOpenWithoutPending']);
        $this->assertSame(16, $result['afterOpenWithoutPending']);
        $csv = $this->get(route('leads.import.download', ['token' => $upload['token']]))->assertOk()->streamedContent();
        $this->assertStringContainsString('skipped', $csv);
        $this->assertSame($result['created'], LeadImportRecord::count());
        $state = app(LeadImportStore::class)->read($a['admin'], $upload['token']);
        $this->assertFalse(Storage::disk('local')->exists($state['source']));
        $again = $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'Lead 18 Sep.xls', test: true)])->assertOk()->json();
        $this->parsed($again, $mapping);
        $second = $this->finish($again, $this->finalPreview($again, ['stage' => 'fresh']));
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['followUps']);
        $this->assertSame($hash, hash_file('sha256', $path));
        file_put_contents('/tmp/lead-import-walkthrough.json', json_encode(compact('upload', 'initial', 'preview', 'final', 'result', 'second'), JSON_PRETTY_PRINT));
    }

    public function test_currently_uploaded_vanam_file_splits_combined_customer_names_end_to_end(): void
    {
        $path = '/home/etech7/Downloads/Lead Vanam 22 Sep.xls';
        if (! is_file($path)) {
            $this->markTestSkipped('Requires the uploaded Lead Vanam 22 Sep.xls file.');
        }
        $a = $this->actors();
        $project = Project::create(['name' => 'Vanam']);
        $project->salespeople()->attach($a['sales']);
        $upload = $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'Lead Vanam 22 Sep.xls', test: true)])->assertOk()->json();
        $this->assertSame(33, $upload['totalRows']);
        $this->assertSame('client_name', $upload['guessedMapping']['full_name']);
        $this->assertSame(0, $this->counts()['leads']);
        $mapping = $upload['guessedMapping'];
        unset($mapping['assigned_user']);
        $preview = $this->parsed($upload, $mapping);
        $this->assertSame(0, $preview['summary']['problems']);
        $expected = [
            ['first_name' => 'mit', 'middle_name' => '', 'last_name' => ''],
            ['first_name' => 'p', 'middle_name' => '', 'last_name' => ''],
            ['first_name' => 'Yatin', 'middle_name' => '', 'last_name' => 'Desai'],
            ['first_name' => 'Vipul', 'middle_name' => '', 'last_name' => 'Pipaliya'],
            ['first_name' => 'deepak', 'middle_name' => '', 'last_name' => 'kumar'],
        ];
        foreach ($expected as $index => $parts) {
            foreach ($parts as $field => $value) {
                $this->assertSame($value, $preview['previewRows'][$index]['attributes'][$field], 'row '.($index + 2)." $field");
            }
        }
        $final = $this->finalPreview($upload, ['project_id' => $project->id]);
        $this->assertSame(33, $final['summary']['toCreate']);
        $yatin = collect($final['previewRows'])->firstWhere('attributes.mobile_number', '8200988245');
        $this->assertSame('Yatin', $yatin['attributes']['first_name']);
        $this->assertSame('', $yatin['attributes']['middle_name']);
        $this->assertSame('Desai', $yatin['attributes']['last_name']);
        $result = $this->finish($upload, $final);
        $this->assertSame(33, $result['created']);
        $lead = Lead::where('mobile_number', '8200988245')->firstOrFail();
        $this->assertSame('Yatin', $lead->first_name);
        $this->assertSame('', $lead->middle_name);
        $this->assertSame('Desai', $lead->last_name);
        $this->assertSame('mit', Lead::where('mobile_number', '8905724411')->firstOrFail()->first_name);
    }

    public function test_advocate_dpak_rathod_edit_flows_through_preview_one_two_and_import(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'ADVOCATE DPAK RATHOD'])]);
        $preview = $this->parsed($upload);
        $row = collect($preview['previewRows'])->firstWhere('row', 2);
        $this->assertSame('ADVOCATE', $row['attributes']['first_name']);
        $this->assertSame('DPAK', $row['attributes']['middle_name']);
        $this->assertSame('RATHOD', $row['attributes']['last_name']);
        $this->assertFalse($row['edited']);
        $edited = $this->parsed($upload, null, 'auto', [2 => ['first_name' => 'DPAK', 'middle_name' => '']]);
        $this->assertSame(0, $edited['summary']['problems']);
        $row = collect($edited['previewRows'])->firstWhere('row', 2);
        $this->assertSame('DPAK', $row['attributes']['first_name']);
        $this->assertSame('', $row['attributes']['middle_name']);
        $this->assertSame('RATHOD', $row['attributes']['last_name']);
        $this->assertTrue($row['edited']);
        $this->assertSame(['first_name' => 'DPAK', 'middle_name' => ''], $edited['overrides'][2]);
        $final = $this->finalPreview($upload);
        $row = collect($final['previewRows'])->firstWhere('row', 2);
        $this->assertSame('DPAK', $row['attributes']['first_name']);
        $this->assertSame('', $row['attributes']['middle_name']);
        $this->assertSame('RATHOD', $row['attributes']['last_name']);
        $this->finish($upload, $final);
        $lead = Lead::firstOrFail();
        $this->assertSame('DPAK', $lead->first_name);
        $this->assertSame('', $lead->middle_name);
        $this->assertSame('RATHOD', $lead->last_name);
    }

    public function test_editing_an_invalid_mobile_revalidates_it(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Mobile' => '12345'])]);
        $this->assertSame(1, $this->parsed($upload)['summary']['invalidMobile']);
        $edited = $this->parsed($upload, null, 'auto', [2 => ['mobile_number' => '9876500099']]);
        $this->assertSame(0, $edited['summary']['invalidMobile']);
        $row = collect($edited['previewRows'])->firstWhere('row', 2);
        $this->assertSame('9876500099', $row['attributes']['mobile_number']);
        $this->assertTrue($row['edited']);
    }

    public function test_editing_a_mobile_into_a_database_duplicate_is_flagged(): void
    {
        $a = $this->actors();
        Lead::create(['first_name' => 'Existing', 'last_name' => '', 'mobile_number' => '9876500088', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $upload = $this->upload([$this->row()]);
        $this->assertSame(0, $this->parsed($upload)['summary']['duplicatesInDatabase']);
        $edited = $this->parsed($upload, null, 'auto', [2 => ['mobile_number' => '9876500088']]);
        $this->assertSame(1, $edited['summary']['duplicatesInDatabase']);
        $problem = collect($edited['problemRows'])->firstWhere('row', 2);
        $this->assertSame('skip_duplicate_db', $problem['outcome']);
        $this->assertTrue($problem['edited']);
    }

    public function test_editing_a_duplicate_mobile_clears_the_in_file_duplicate(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(), $this->row()]);
        $this->assertSame(1, $this->parsed($upload)['summary']['duplicatesInFile']);
        $edited = $this->parsed($upload, null, 'auto', [3 => ['mobile_number' => '9000000077']]);
        $this->assertSame(0, $edited['summary']['duplicatesInFile']);
        $this->assertSame(2, $edited['summary']['clean']);
        $row = collect($edited['previewRows'])->firstWhere('row', 3);
        $this->assertTrue($row['edited']);
    }

    public function test_source_and_stage_edits_resolve_against_the_taxonomy(): void
    {
        $this->actors();
        $upload = $this->upload([
            $this->row(['Source' => 'FB Ads 2025']),
            $this->row(['Stage' => 'Hot Prospect', 'Mobile' => '9000000001']),
        ]);
        $preview = $this->parsed($upload);
        $this->assertSame(1, $preview['summary']['unknownSource']);
        $this->assertSame(1, $preview['summary']['unknownStage']);
        $edited = $this->parsed($upload, null, 'auto', [
            2 => ['source' => 'facebook'],
            3 => ['stage' => 'fresh'],
        ]);
        $this->assertSame(0, $edited['summary']['unknownSource']);
        $this->assertSame(0, $edited['summary']['unknownStage']);
        $this->assertSame(0, $edited['summary']['problems']);
        $this->assertSame('facebook', collect($edited['previewRows'])->firstWhere('row', 2)['attributes']['source']);
        $this->assertSame('fresh', collect($edited['previewRows'])->firstWhere('row', 3)['attributes']['stage']);
    }

    public function test_salesperson_project_override_is_reauthorized_against_their_allowed_projects(): void
    {
        $a = $this->actors();
        Project::create(['name' => 'Beta']);
        $this->actingAs($a['sales']);
        $upload = $this->upload([$this->row()]);
        $this->assertSame(0, $this->parsed($upload)['summary']['unauthorizedProject']);
        $edited = $this->parsed($upload, null, 'auto', [2 => ['project' => 'Beta']]);
        $this->assertSame(1, $edited['summary']['unauthorizedProject']);
        $problem = collect($edited['problemRows'])->firstWhere('row', 2);
        $this->assertSame('error', $problem['outcome']);
        $this->assertStringContainsString('not allowed to import into Beta', $problem['errors'][0]);
    }

    public function test_row_edits_survive_back_and_forward_between_steps(): void
    {
        $a = $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'Rahul Kumar Shah'])]);
        $overrides = [2 => ['first_name' => 'Edited Name']];
        $preview = $this->parsed($upload, null, 'auto', $overrides);
        $this->assertSame('Edited Name', $preview['previewRows'][0]['attributes']['first_name']);
        $final = $this->finalPreview($upload);
        $this->assertSame('Edited Name', collect($final['previewRows'])->firstWhere('row', 2)['attributes']['first_name']);
        $again = $this->parsed($upload, null, 'auto', $overrides);
        $this->assertSame('Edited Name', $again['previewRows'][0]['attributes']['first_name']);
        $this->assertSame(['first_name' => 'Edited Name'], $again['overrides'][2]);
        $finalAgain = $this->finalPreview($upload);
        $this->assertSame('Edited Name', collect($finalAgain['previewRows'])->firstWhere('row', 2)['attributes']['first_name']);
        $this->finish($upload, $finalAgain);
        $this->assertSame('Edited Name', Lead::firstOrFail()->first_name);
    }

    public function test_mapping_change_invalidates_stored_row_edits(): void
    {
        $this->actors();
        $upload = $this->upload([['Name' => 'ADVOCATE DPAK RATHOD', 'Alt Name' => 'Other Person', 'Mobile' => '9876543210', 'Project' => 'Alpha', 'Source' => 'Facebook', 'Stage' => 'fresh']]);
        $overrides = [2 => ['first_name' => 'DPAK']];
        $first = $this->parsed($upload, null, 'auto', $overrides);
        $this->assertTrue($first['previewRows'][0]['edited']);
        $changed = ['first_name' => 'Alt Name', 'mobile_number' => 'Mobile', 'project' => 'Project', 'source' => 'Source', 'stage' => 'Stage'];
        $second = $this->parsed($upload, $changed, 'auto', $overrides);
        $this->assertTrue($second['overridesCleared']);
        $this->assertSame([], $second['overrides']);
        $this->assertSame('Other Person', $second['previewRows'][0]['attributes']['first_name']);
        $this->assertFalse($second['previewRows'][0]['edited']);
    }

    public function test_unknown_override_fields_and_row_numbers_are_ignored(): void
    {
        $a = $this->actors();
        $before = $this->counts();
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload);
        $response = $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $upload['guessedMapping'], 'overrides' => [
            2 => ['first_name' => 'Safe', 'assigned_to' => '99999', 'import_key' => 'fake', 'outcome' => 'create'],
            9999 => ['first_name' => 'Nope'],
        ]])->assertOk()->json();
        $this->assertSame(['first_name' => 'Safe'], $response['overrides'][2]);
        $this->assertSame('Safe', $response['previewRows'][0]['attributes']['first_name']);
        $this->assertSame($before, $this->counts());
    }

    public function test_row_edits_make_no_business_writes(): void
    {
        $a = $this->actors();
        $before = $this->counts();
        $upload = $this->upload([$this->row(), $this->row(['Mobile' => '12345'])]);
        $this->parsed($upload);
        $this->parsed($upload, null, 'auto', [2 => ['first_name' => 'Renamed', 'mobile_number' => '9000000055'], 3 => ['mobile_number' => '9000000066']]);
        $this->parsed($upload);
        $this->assertSame($before, $this->counts());
    }

    public function test_preview_one_edit_endpoint_remains_denied_for_telecaller(): void
    {
        $a = $this->actors();
        $this->actingAs($a['caller']);
        $this->postJson(route('leads.import.preview'), ['token' => 'x', 'mapping' => [], 'overrides' => [2 => ['first_name' => 'X']]])->assertForbidden();
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_high_regression_excluded_open_terminal_and_edited_rows_never_write(): void
    {
        $this->actors();
        $upload = $this->upload([
            $this->row(),
            $this->row(['Name' => 'Terminal', 'Mobile' => '9000000001', 'Stage' => 'booking_done']),
            $this->row(['Name' => 'Edited', 'Mobile' => '9000000002']),
        ]);
        $this->parsed($upload, overrides: [4 => ['first_name' => 'Corrected']]);
        foreach ([2, 3, 4] as $row) {
            $this->postJson(route('leads.import.exclude'), ['token' => $upload['token'], 'row' => $row])->assertOk();
        }
        $before = $this->counts();
        $final = $this->finalPreview($upload);
        $this->postJson(route('leads.import.chunk'), ['token' => $upload['token'], 'plan_id' => $final['plan_id'], 'confirm' => true, 'offset' => 0, 'rows' => [['outcome' => 'create']]])->assertUnprocessable();
        $result = $this->finish($upload, $final);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(3, $result['excluded']);
        $this->assertSame($before, $this->counts());
    }

    public function test_high_regression_partial_edits_accumulate_and_restore_then_import_exactly(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'ADVOCATE DPAK RATHOD'])]);
        $this->parsed($upload, overrides: [2 => ['first_name' => 'DPAK']]);
        $second = $this->parsed($upload, overrides: [2 => ['last_name' => 'Rathod']]);
        $this->assertSame('DPAK', $second['previewRows'][0]['attributes']['first_name']);
        $third = $this->parsed($upload, overrides: [2 => ['middle_name' => '']]);
        $expected = ['first_name' => 'DPAK', 'last_name' => 'Rathod', 'middle_name' => ''];
        $this->assertSame($expected, $third['overrides'][2]);
        $this->postJson(route('leads.import.exclude'), ['token' => $upload['token'], 'row' => 2])->assertOk();
        $this->postJson(route('leads.import.restore'), ['token' => $upload['token'], 'row' => 2])->assertOk();
        $final = $this->finalPreview($upload);
        foreach ($expected as $field => $value) {
            $this->assertSame($value, $final['previewRows'][0]['attributes'][$field]);
        }
        $this->assertSame(1, $this->finish($upload, $final)['created']);
        $this->assertDatabaseHas('leads', $expected);
    }

    public function test_high_regression_reset_explicitly_clears_server_overrides(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload, overrides: [2 => ['first_name' => 'Edited']]);
        $this->assertSame('Edited', $this->parsed($upload)['previewRows'][0]['attributes']['first_name']);
        $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $upload['guessedMapping'], 'reset_rows' => [2]])
            ->assertOk()->assertJsonPath('overrides', [])->assertJsonPath('previewRows.0.attributes.first_name', 'Rahul');
    }

    #[DataProvider('safeImportPhones')]
    public function test_high_regression_import_phone_preserves_identity(string $input, ?string $expected): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Mobile' => $input])]);
        $preview = $this->parsed($upload);
        $this->assertSame($expected, $preview['previewRows'][0]['attributes']['mobile_number']);
        $this->assertSame($expected === null ? 1 : 0, $preview['summary']['invalidMobile']);
        $result = $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame($expected === null ? 0 : 1, $result['created']);
        if ($expected !== null) {
            $this->assertDatabaseHas('leads', ['mobile_number' => $expected]);
        } else {
            $this->assertDatabaseCount('leads', 0);
            $this->assertDatabaseCount('todos', 0);
        }
    }

    public static function safeImportPhones(): array
    {
        return [
            'plain' => ['9876543210', '9876543210'],
            'country' => ['+919876543210', '9876543210'],
            'spaces' => ['91 98765 43210', '9876543210'],
            'hyphen' => ['98765-43210', '9876543210'],
            'parentheses' => ['(+91) 98765-43210', '9876543210'],
            'scientific text' => ['9.876543210E+9', null],
            'extension' => ['9876543210 ext 123', null],
            'short' => ['12345', null],
        ];
    }

    #[DataProvider('importClockTimes')]
    public function test_high_regression_file_clock_matches_preview_and_database(string $input, string $expected): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Follow-up Time' => $input])]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_source' => 'uploaded', 'fallback_time' => '09:15']);
        $this->assertSame($expected, $final['previewRows'][0]['follow_up_time']);
        $this->assertSame(1, $this->finish($upload, $final)['created']);
        $this->assertSame($expected, Todo::where('status', 'pending')->firstOrFail()->scheduled_at->setTimezone('Asia/Kolkata')->format('H:i'));
    }

    public static function importClockTimes(): array
    {
        return [
            'midnight' => ['0.0', '00:00'], 'six' => ['0.25', '06:00'],
            'noon' => ['0.5', '12:00'], 'eighteen' => ['0.75', '18:00'],
            'ten' => ['10:00', '10:00'], 'ten AM' => ['10:00 AM', '10:00'],
            'fourteen' => ['14:30', '14:30'], 'two PM' => ['2:30 PM', '14:30'],
        ];
    }

    public function test_high_regression_ambiguous_sheet_requires_backend_confirmation(): void
    {
        $this->actors();
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('First')->fromArray([['Name', 'Mobile', 'Project', 'Source', 'Stage'], ['First Person', '9876543210', 'Alpha', 'facebook', 'fresh']]);
        $book->createSheet()->setTitle('Second')->fromArray([['Name', 'Mobile', 'Project', 'Source', 'Stage'], ['Second Person', '9876543211', 'Alpha', 'facebook', 'fresh']]);
        $path = Storage::disk('local')->path('ambiguous.xlsx');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();
        $upload = $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'ambiguous.xlsx', test: true)])->assertOk()->json();
        $this->assertTrue($upload['sheetAmbiguous']);
        $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $upload['guessedMapping']])->assertConflict();
        $selected = $this->postJson(route('leads.import.sheet'), ['token' => $upload['token'], 'sheet' => 'Second'])->assertOk()->json();
        $preview = $this->parsed($upload, $selected['guessedMapping']);
        $this->assertSame('Second', $preview['previewRows'][0]['attributes']['first_name']);
        $this->assertSame(1, $this->finish($upload, $this->finalPreview($upload))['created']);
        $this->assertDatabaseMissing('leads', ['mobile_number' => '9876543210']);
    }

    public function test_high_regression_final_preview_rejects_unvalidated_mapping(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Alternate Name' => 'Correct Person'])]);
        $this->parsed($upload);
        $mapping = $upload['guessedMapping'];
        $mapping['full_name'] = 'Alternate Name';
        $this->postJson(route('leads.import.final-preview'), [
            'token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $mapping,
            'defaults' => ['mode' => 'today', 'time' => '10:00', 'follow_up_type' => 'call'],
        ])->assertConflict();
        $this->parsed($upload, $mapping);
        $final = $this->finalPreview($upload);
        $this->assertSame('Correct', $final['previewRows'][0]['attributes']['first_name']);
        $this->assertSame(1, $this->finish($upload, $final)['created']);
        $this->assertDatabaseHas('leads', ['first_name' => 'Correct', 'last_name' => 'Person']);
    }

    #[DataProvider('fixedTimeMappings')]
    public function test_high_regression_fixed_time_is_required_and_persisted(bool $mapped): void
    {
        $this->actors();
        $upload = $this->upload([$this->row($mapped ? ['Follow-up Time' => '08:00'] : [])]);
        $this->parsed($upload);
        $defaults = ['mode' => 'today', 'follow_up_type' => 'call'];
        if ($mapped) {
            $defaults['time_source'] = 'fixed';
        }
        $payload = ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $upload['guessedMapping'], 'defaults' => $defaults];
        $this->postJson(route('leads.import.final-preview'), $payload)->assertUnprocessable()->assertJsonValidationErrors('defaults.time');
        $payload['defaults']['time'] = '10:15';
        $final = $this->postJson(route('leads.import.final-preview'), $payload)->assertOk()->json();
        $this->assertSame('10:15', $final['previewRows'][0]['follow_up_time']);
        $this->assertSame(1, $this->finish($upload, $final)['created']);
        $this->assertSame('10:15', Todo::where('status', 'pending')->firstOrFail()->scheduled_at->setTimezone('Asia/Kolkata')->format('H:i'));
    }

    public static function fixedTimeMappings(): array
    {
        return ['no time column' => [false], 'mapped fixed override' => [true]];
    }

    public function test_high_regression_numeric_excel_mobile_and_midnight_are_preserved(): void
    {
        $this->actors();
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Name', 'Mobile', 'Project', 'Source', 'Stage', 'Follow-up Time'],
            ['Numeric', 9.876543210E+9, 'Alpha', 'facebook', 'fresh', 0],
        ], null, 'A1', true);
        $path = Storage::disk('local')->path('numeric.xlsx');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();
        $upload = $this->postJson(route('leads.import.upload'), ['file' => new UploadedFile($path, 'numeric.xlsx', test: true)])->assertOk()->json();
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_source' => 'uploaded', 'fallback_time' => '09:15']);
        $this->assertSame('9876543210', $final['previewRows'][0]['attributes']['mobile_number']);
        $this->assertSame('00:00', $final['previewRows'][0]['follow_up_time']);
        $this->assertSame(1, $this->finish($upload, $final)['created']);
        $this->assertSame('00:00', Todo::where('status', 'pending')->firstOrFail()->scheduled_at->setTimezone('Asia/Kolkata')->format('H:i'));
    }
}
