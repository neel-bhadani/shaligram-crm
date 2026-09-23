<?php

namespace Tests\Feature;

use App\Http\Controllers\LeadImportController;
use App\Models\Alert;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadImport\LeadImportPlanner;
use App\Services\LeadImport\LeadImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The bulk-import wizard: upload, map, set defaults, preview, write.
 *
 * @see LeadImportController
 * @see LeadImportPlanner
 * @see LeadImportService
 */
class LeadBulkImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salesperson;

    private User $telecaller;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Carbon::setTestNow(Carbon::parse('2026-09-23 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->salesperson = $this->user('salesperson', 'Sam');
        $this->telecaller = $this->user('telecaller', 'Tia');

        $this->alpha = Project::create(['name' => 'Alpha']);
        $this->beta = Project::create(['name' => 'Beta']);
        $this->alpha->salespeople()->attach($this->salesperson);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ================= preview ================= */

    public function test_preview_reports_in_file_and_database_duplicates_and_a_validation_error(): void
    {
        $this->lead(['first_name' => 'Existing', 'mobile_number' => '9000000001', 'project_id' => $this->alpha->id]);

        $token = $this->uploadCsv($this->admin, [
            ['first_name' => 'Amit', 'last_name' => 'Shah', 'mobile_number' => '9000000002', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'fresh', 'created_at' => ''],
            ['first_name' => 'Amit', 'last_name' => 'Shah', 'mobile_number' => '9000000002', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'fresh', 'created_at' => ''],
            ['first_name' => 'Old', 'last_name' => 'Contact', 'mobile_number' => '9000000001', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'fresh', 'created_at' => ''],
            ['first_name' => 'Bad', 'last_name' => 'Stage', 'mobile_number' => '9000000003', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'NotAStage', 'created_at' => ''],
        ])['token'];

        $res = $this->actingAs($this->admin)->postJson(route('leads.import.preview'), [
            'token' => $token,
            'mapping' => $this->identityMapping(),
            'defaults' => $this->defaults(),
        ])->assertOk();

        $summary = $res->json('summary');
        $this->assertSame(1, $summary['toCreate']);
        $this->assertSame(1, $summary['duplicatesInFile']);
        $this->assertSame(1, $summary['duplicatesInDatabase']);
        $this->assertSame(1, $summary['errors']);

        $errorRow = collect($res->json('problemRows'))->firstWhere('outcome', 'error');
        $this->assertStringContainsString('Unknown stage', $errorRow['errors'][0]);

        $fileDup = collect($res->json('problemRows'))->firstWhere('outcome', 'skip_duplicate_file');
        $this->assertStringContainsString('Duplicate of row 2', implode('; ', $fileDup['errors']));

        $dbDup = collect($res->json('problemRows'))->firstWhere('outcome', 'skip_duplicate_db');
        $this->assertStringContainsString('already exists in project Alpha', implode('; ', $dbDup['errors']));
    }

    /* ================= idempotency ================= */

    public function test_importing_the_same_file_twice_creates_nothing_the_second_time(): void
    {
        $rows = [
            ['first_name' => 'One', 'last_name' => 'Row', 'mobile_number' => '9111100001', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'booking_done', 'created_at' => ''],
            ['first_name' => 'Two', 'last_name' => 'Row', 'mobile_number' => '9111100002', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'booking_done', 'created_at' => ''],
        ];

        $this->runFullImport($this->admin, $rows);
        $this->assertSame(2, Lead::count());

        // a second, independent upload of the exact same content — the
        // planner re-reads the live table fresh, so both rows resolve as
        // already-existing before anything is written the second time
        $result = $this->runFullImport($this->admin, $rows);

        $this->assertSame(2, Lead::count(), 'no new leads on the second import');
        $this->assertSame(0, $result['preview']['summary']['toCreate']);
        $this->assertSame(2, $result['preview']['summary']['duplicatesInDatabase']);
    }

    /* ================= follow-ups ================= */

    public function test_an_open_stage_row_gets_the_batch_follow_up_and_a_terminal_stage_row_does_not(): void
    {
        $result = $this->runFullImport($this->admin, [
            ['first_name' => 'Open', 'last_name' => 'Lead', 'mobile_number' => '9222200001', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'fresh', 'created_at' => ''],
            ['first_name' => 'Closed', 'last_name' => 'Lead', 'mobile_number' => '9222200002', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'booking_done', 'created_at' => ''],
        ]);

        $this->assertSame(2, $result['preview']['summary']['toCreate']);
        $this->assertSame(1, $result['preview']['summary']['followUpsToCreate']);

        $open = Lead::where('mobile_number', '9222200001')->firstOrFail();
        $closed = Lead::where('mobile_number', '9222200002')->firstOrFail();

        $this->assertNotNull($open->pendingTodo);
        $this->assertSame('call', $open->pendingTodo->type);
        $this->assertSame('2026-09-24 10:00:00', $open->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
        // attributed to the importing admin, same as a lead added by hand —
        // this is not automation, so it is not a system actor
        $this->assertSame($this->admin->id, $open->pendingTodo->created_by);

        $this->assertNull($closed->pendingTodo);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ================= created_at ================= */

    public function test_a_mapped_created_at_is_preserved_and_an_unmapped_one_defaults_to_now(): void
    {
        $this->runFullImport($this->admin, [
            ['first_name' => 'Dated', 'last_name' => 'Lead', 'mobile_number' => '9333300001', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'booking_done', 'created_at' => '2020-01-15 09:30:00'],
        ]);

        $dated = Lead::where('mobile_number', '9333300001')->firstOrFail();
        $this->assertSame('2020-01-15 09:30:00', $dated->created_at->format('Y-m-d H:i:s'));

        $this->runFullImport($this->admin, [
            ['first_name' => 'Undated', 'last_name' => 'Lead', 'mobile_number' => '9333300002', 'project' => 'Alpha', 'source' => 'walk_in', 'stage' => 'booking_done', 'created_at' => ''],
        ]);

        $undated = Lead::where('mobile_number', '9333300002')->firstOrFail();
        $this->assertSame('2026-09-23 11:00:00', $undated->created_at->format('Y-m-d H:i:s'));
    }

    /* ================= project scoping ================= */

    public function test_a_salesperson_cannot_import_against_a_project_they_do_not_own_but_an_admin_can(): void
    {
        $token = $this->uploadCsv($this->salesperson, [
            ['first_name' => 'Sam', 'last_name' => 'Lead', 'mobile_number' => '9444400001', 'project' => 'Beta', 'source' => 'walk_in', 'stage' => 'booking_done', 'created_at' => ''],
        ])['token'];

        // the defaults themselves may not name Beta
        $this->actingAs($this->salesperson)->postJson(route('leads.import.preview'), [
            'token' => $token, 'mapping' => $this->identityMapping(),
        ])->assertOk();
        $this->postJson(route('leads.import.final-preview'), [
            'token' => $token, 'accepted_preview' => true, 'mapping' => $this->identityMapping(),
            'defaults' => $this->defaults(['project_id' => $this->beta->id]),
        ])->assertStatus(422)->assertJsonValidationErrors('defaults.project_id');

        // nor may a mapped column resolve one to Beta — the row itself errors
        $res = $this->actingAs($this->salesperson)->postJson(route('leads.import.preview'), [
            'token' => $token,
            'mapping' => $this->identityMapping(),
            'defaults' => $this->defaults(),
        ])->assertOk();

        $this->assertSame(0, $res->json('summary.toCreate'));
        $this->assertSame(1, $res->json('summary.errors'));

        // the same file, the same defaults, is fine for an admin
        $adminToken = $this->uploadCsv($this->admin, [
            ['first_name' => 'Sam', 'last_name' => 'Lead', 'mobile_number' => '9444400001', 'project' => 'Beta', 'source' => 'walk_in', 'stage' => 'booking_done', 'created_at' => ''],
        ])['token'];

        $this->actingAs($this->admin)->postJson(route('leads.import.preview'), [
            'token' => $adminToken,
            'mapping' => $this->identityMapping(),
            'defaults' => $this->defaults(),
        ])->assertOk()
            ->assertJsonPath('summary.toCreate', 1);
    }

    /* ================= authorization ================= */

    public function test_a_telecaller_is_forbidden_from_every_import_route(): void
    {
        $this->actingAs($this->telecaller)->get(route('leads.import'))->assertForbidden();

        $this->actingAs($this->telecaller)->postJson(route('leads.import.upload'), [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', "first_name\r\n"),
        ])->assertForbidden();

        $this->actingAs($this->telecaller)->postJson(route('leads.import.preview'), [
            'token' => (Str::uuid()->toString()).'.csv',
            'mapping' => [],
            'defaults' => ['stage' => 'fresh'],
        ])->assertForbidden();

        $this->actingAs($this->telecaller)->postJson(route('leads.import.chunk'), [
            'token' => (Str::uuid()->toString()).'.csv',
            'mapping' => [],
            'defaults' => ['stage' => 'fresh'],
        ])->assertForbidden();
    }

    /* ================= no automation, no alerts ================= */

    public function test_import_fires_no_automation_rules_and_raises_no_alerts(): void
    {
        // an active rule that would otherwise fire on every lead created
        AutomationRule::create([
            'name' => 'Notify on create',
            'trigger' => 'lead_created',
            'conditions' => [],
            'actions' => [['type' => 'assign_round_robin', 'role' => 'telecaller']],
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->beta->salespeople()->attach($this->salesperson);

        // Beta has a project salesperson — an 'in_discussion' row would
        // normally raise a "no salesperson assigned" alert
        $this->runFullImport($this->admin, [
            ['first_name' => 'Quiet', 'last_name' => 'Import', 'mobile_number' => '9555500001', 'project' => 'Beta', 'source' => 'walk_in', 'stage' => 'in_discussion', 'created_at' => ''],
        ]);

        $this->assertSame(1, Lead::where('mobile_number', '9555500001')->count());
        $this->assertSame(0, AutomationLog::count());
        $this->assertSame(0, Alert::count());
    }

    /* ================= helpers ================= */

    /** Builds a CSV, uploads it and returns the upload endpoint's JSON body. */
    private function uploadCsv(User $user, array $rows): array
    {
        $headers = array_keys($rows[0]);
        $lines = [implode(',', $headers)];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $row));
        }

        $file = UploadedFile::fake()->createWithContent('leads.csv', implode("\r\n", $lines));

        return $this->actingAs($user)
            ->postJson(route('leads.import.upload'), ['file' => $file])
            ->assertOk()
            ->json();
    }

    /** field => header, given the fixture always names its columns after the field. */
    private function identityMapping(): array
    {
        return [
            'first_name' => 'first_name', 'last_name' => 'last_name', 'mobile_number' => 'mobile_number',
            'project' => 'project', 'source' => 'source', 'stage' => 'stage',
        ];
    }

    private function defaults(array $overrides = []): array
    {
        return $overrides + [
            'project_id' => null,
            'source' => null,
            'stage' => 'fresh',
            'assigned_to' => null,
            'follow_up_type' => 'call',
            'mode' => 'specific', 'start_date' => '2026-09-24', 'end_date' => '2026-09-24', 'time' => '10:00',
            'follow_up_remarks' => 'Imported batch follow-up',
        ];
    }

    /** Upload, preview, and drive the chunk endpoint to completion. */
    private function runFullImport(User $user, array $rows, array $defaultOverrides = []): array
    {
        $upload = $this->uploadCsv($user, $rows);
        $mapping = $this->identityMapping();
        if (collect($rows)->contains(fn (array $row): bool => ! empty($row['created_at']))) {
            $mapping['created_at'] = 'created_at';
        }
        $defaults = $this->defaults($defaultOverrides);

        $preview = $this->actingAs($user)->postJson(route('leads.import.preview'), [
            'token' => $upload['token'],
            'mapping' => $mapping,
            'defaults' => $defaults,
        ])->assertOk()->json();

        $preview = $this->postJson(route('leads.import.final-preview'), [
            'token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $mapping, 'defaults' => $defaults,
        ])->assertOk()->json();
        $offset = 0;
        do {
            $chunk = $this->postJson(route('leads.import.chunk'), [
                'token' => $upload['token'], 'plan_id' => $preview['plan_id'],
                'confirm' => true, 'offset' => $offset,
            ])->assertOk()->json();
            $offset = $chunk['nextOffset'];
        } while (! $chunk['done']);
        $created = $chunk['created'];

        return ['preview' => $preview, 'created' => $created];
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'first_name' => 'Existing',
            'last_name' => 'User',
            'mobile_number' => '9000000099',
            'project_id' => $this->alpha->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_role' => 'telecaller',
            'stage_changed_at' => now(),
            'created_by' => $this->admin->id,
        ]);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'User',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
