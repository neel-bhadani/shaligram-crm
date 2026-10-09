<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Leads · By loss reason, the reason on each loss, and the Lost filters on the
 * Leads page and Export Data.
 *
 * The thing worth testing above all is that the three screens agree: the
 * report's row, the list it drills into and the export with the same filters
 * are one number, because they are one query (LossEvents). A loss is counted
 * in the period it happened, once per lead, under the reason on that loss.
 *
 * The fixture, inside the default 30-day window ending 2026-09-06:
 *
 *   budget          4   L1 (Alpha, Alice), L2 (Beta, Bob), L6 (reopened since,
 *                       Admin), L8 (Alpha, automation)
 *   competitor      1   L3 — lost for location, then again for competitor
 *   not_interested  1   L7 — brought in by the legacy import
 *   no reason       1   L4
 *
 * plus L5, lost for budget sixty days ago, which no window here includes.
 */
class LossReasonReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $alice;

    private User $bob;

    private Project $alpha;

    private Project $beta;

    /** @var array<string, Lead> */
    private array $leads = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00'));

        $this->admin = $this->user('admin', 'Admin');
        $this->alice = $this->user('telecaller', 'Alice');
        $this->bob = $this->user('telecaller', 'Bob');

        $this->alpha = Project::create(['name' => 'Alpha']);
        $this->beta = Project::create(['name' => 'Beta']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- the report ---------------- */

    public function test_each_lead_lost_in_the_period_counts_once_under_its_latest_reason(): void
    {
        $this->scenario();

        $props = $this->page('/reports/loss-reasons', ['range' => '30']);
        $rows = collect($props['rows'])->keyBy('key');

        $this->assertSame(7, $props['totals']['lost']);
        $this->assertSame(4, $rows['budget']['total'], 'L5 was lost before the window; L6 was reopened since and still counts');
        $this->assertSame(57.1, $rows['budget']['share']);
        $this->assertSame(1, $rows['competitor']['total'], 'L3 is counted once, under its latest loss');
        $this->assertSame(0, $rows['location']['total'], 'and not under the earlier one');
        $this->assertSame(1, $rows['not_interested']['total']);

        $this->assertSame('No reason recorded', $rows['none']['label']);
        $this->assertSame(1, $rows['none']['total']);
        $this->assertSame(1, $props['totals']['noReason']);

        $this->assertSame(7, $rows->sum('total'), 'the no-reason row is part of the total');
        $this->assertSame('budget', $props['rows'][0]['key'], 'highest count first');
    }

    public function test_the_total_is_the_lost_figure_the_by_stage_report_prints(): void
    {
        $this->scenario();

        $byStage = $this->page('/reports/leads', ['group' => 'stage', 'range' => '30']);
        $byReason = $this->page('/reports/loss-reasons', ['range' => '30']);

        $this->assertSame($byStage['totals']['lost'], $byReason['totals']['lost']);
    }

    public function test_in_lost_now_is_the_current_state_and_ignores_the_dates(): void
    {
        $this->scenario();

        // every lead but L6 sits in Lost, L5 included, whatever the window
        $this->assertSame(7, $this->page('/reports/loss-reasons', ['range' => 'today'])['totals']['inLostNow']);
    }

    public function test_each_reason_breaks_down_by_project_and_by_who_marked_it_lost(): void
    {
        $this->scenario();

        $rows = collect($this->page('/reports/loss-reasons', ['range' => '30'])['rows'])->keyBy('key');

        $projects = collect($rows['budget']['projects'])->pluck('total', 'label')->all();
        $this->assertSame(['Alpha' => 3, 'Beta' => 1], $projects);

        $people = collect($rows['budget']['people'])->pluck('total', 'label')->all();
        ksort($people);
        $this->assertSame(['Admin Test' => 1, 'Alice Test' => 1, 'Automation' => 1, 'Bob Test' => 1], $people);

        $this->assertSame(
            [['key' => 'imported', 'label' => 'Imported', 'total' => 1, 'share' => 100.0]],
            $rows['not_interested']['people'],
            'an imported loss names the sheet\'s lead owner, not who lost it, so it is not shown as a person',
        );
    }

    public function test_an_empty_period_reports_nothing_lost(): void
    {
        $this->scenario();

        $props = $this->page('/reports/loss-reasons', ['from' => '2026-07-01', 'to' => '2026-07-02']);

        $this->assertSame(0, $props['totals']['lost']);
        $this->assertSame(0, collect($props['rows'])->sum('total'));
    }

    public function test_a_telecaller_sees_only_the_losses_on_leads_they_can_see(): void
    {
        $this->scenario();

        $props = $this->page('/reports/loss-reasons', ['range' => '30'], $this->alice);

        // L1 and L4 are Alice's; L2 was lost by Bob on a lead she does not hold
        $this->assertSame(2, $props['totals']['lost']);
    }

    public function test_attribution_is_dated_from_the_first_loss_logged_in_the_crm(): void
    {
        $this->scenario();

        // L5's loss, sixty days back, is the first one not brought in by the import
        $this->assertSame('8 Jul 2026', $this->page('/reports/loss-reasons')['attributionBegan']);
    }

    /* ---------------- the reason on each loss ---------------- */

    public function test_losing_a_lead_on_a_call_keeps_the_reason_on_that_loss(): void
    {
        $lead = $this->lead($this->alice, ['stage' => 'in_discussion']);
        $todo = Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->alice->id,
            'scheduled_at' => now(), 'type' => 'call', 'status' => 'pending',
        ]);

        $this->actingAs($this->alice)->post("/todos/{$todo->id}/complete", [
            'stage' => 'lost', 'remarks' => 'Too expensive.', 'reason' => 'budget',
        ])->assertSessionHasNoErrors();

        $this->assertSame('budget', $todo->fresh()->lost_reason);
        $this->assertSame('budget', $lead->fresh()->reason, 'leads.reason is still written');
        $this->assertTrue(LeadActivity::where('lead_id', $lead->id)
            ->where('action', LeadActivity::Detail)->where('field', 'reason')->where('to_value', 'budget')->exists());
    }

    public function test_losing_a_lead_from_the_form_keeps_the_reason_on_that_loss(): void
    {
        $lead = $this->lead($this->alice, ['stage' => 'in_discussion']);

        $this->actingAs($this->admin)->put("/leads/{$lead->id}", [
            'first_name' => $lead->first_name, 'last_name' => $lead->last_name,
            'mobile_number' => $lead->mobile_number, 'project_id' => $lead->project_id,
            'source' => $lead->source, 'stage' => 'lost', 'reason' => 'location',
        ])->assertSessionHasNoErrors();

        $loss = Todo::where('lead_id', $lead->id)->where('outcome_stage', 'lost')->sole();

        $this->assertSame('location', $loss->lost_reason);
        $this->assertTrue(LeadActivity::where('lead_id', $lead->id)
            ->where('action', LeadActivity::Detail)->where('field', 'reason')->where('to_value', 'location')->exists());
    }

    public function test_a_second_loss_does_not_rewrite_the_first(): void
    {
        $lead = $this->lead($this->alice, ['stage' => 'in_discussion']);
        $service = app(LeadFollowUpService::class);

        $this->actingAs($this->admin);
        $service->changeStage($lead, 'lost', ['reason' => 'budget']);
        $service->changeStage($lead, 'in_discussion', [], now()->addDay(), 'call');
        $service->changeStage($lead, 'lost', ['reason' => 'location']);

        $this->assertSame(
            ['budget', 'location'],
            Todo::where('lead_id', $lead->id)->where('outcome_stage', 'lost')->orderBy('id')->pluck('lost_reason')->all(),
        );
    }

    public function test_correcting_the_reason_on_a_lost_lead_corrects_its_latest_loss(): void
    {
        $lead = $this->lead($this->alice, ['stage' => 'lost', 'reason' => 'budget']);
        $earlier = $this->loss($lead, 'other', now()->subDays(20), $this->alice);
        $latest = $this->loss($lead, 'budget', now()->subDays(2), $this->alice);

        $this->actingAs($this->admin)->put("/leads/{$lead->id}", [
            'first_name' => $lead->first_name, 'last_name' => $lead->last_name,
            'mobile_number' => $lead->mobile_number, 'project_id' => $lead->project_id,
            'source' => $lead->source, 'stage' => 'lost', 'reason' => 'competitor',
        ])->assertSessionHasNoErrors();

        $this->assertSame('competitor', $latest->fresh()->lost_reason);
        $this->assertSame('other', $earlier->fresh()->lost_reason, 'an earlier loss is its own record');
    }

    /* ---------------- the three screens agree ---------------- */

    /**
     * The report's row, the Leads page the row drills into, and Export Data
     * with the same filters — over the same window, the same number, for every
     * reason and for all of them together.
     */
    public function test_the_report_the_lead_list_and_the_export_agree(): void
    {
        $this->scenario();

        $props = $this->page('/reports/loss-reasons', ['range' => '30']);
        $window = ['from' => $props['range']['from'], 'to' => $props['range']['to']];
        $lost = $window + ['stage' => 'lost', 'date_basis' => 'lost'];

        $this->assertSame($props['totals']['lost'], $this->exportCount($lost));
        $this->assertSame($props['totals']['lost'], $this->listTotal($lost));

        foreach ($props['rows'] as $row) {
            $this->assertSame($row['total'], $this->exportCount($lost + ['reason' => $row['key']]), "export, {$row['label']}");
            $this->assertSame($row['total'], $this->listTotal($lost + ['reason' => $row['key']]), "list, {$row['label']}");

            foreach ($row['projects'] as $project) {
                $this->assertSame(
                    $project['total'],
                    $this->listTotal($lost + ['reason' => $row['key'], 'project_id' => $project['key']]),
                    "list, {$row['label']} in {$project['label']}",
                );
            }
        }
    }

    /* ---------------- the Leads page ---------------- */

    public function test_the_lost_filters_are_dropped_when_the_stage_is_not_lost(): void
    {
        $this->scenario();

        $props = $this->page('/leads', [
            'stage' => 'in_discussion', 'date_basis' => 'lost', 'reason' => 'budget', 'range' => '30',
        ]);

        $this->assertArrayNotHasKey('date_basis', $props['filters']);
        $this->assertArrayNotHasKey('reason', $props['filters']);
        $this->assertSame(1, $props['leads']['total'], 'L6, created in the window and in discussion now');
    }

    public function test_dates_on_created_keep_their_old_meaning_under_lost(): void
    {
        $this->scenario();

        // every lead was created a year ago, so none was created in the window
        $this->assertSame(0, $this->listTotal(['stage' => 'lost', 'range' => '30']));
        $this->assertSame(0, $this->exportCount(['stage' => 'lost', 'date_basis' => 'created'] + $this->window()));
    }

    /* ---------------- Export Data ---------------- */

    public function test_a_lost_csv_carries_the_loss_reason_and_says_which_dates_it_used(): void
    {
        $this->scenario();

        $response = $this->export('csv', ['stage' => 'lost', 'date_basis' => 'lost'] + $this->window());
        $response->assertOk();

        $rows = $this->parseCsv(ltrim($response->streamedContent(), "\xEF\xBB\xBF"));

        $this->assertStringContainsString('Marked lost between: 2026-08-08  to  2026-09-06', $rows[0][0]);
        $this->assertStringContainsString('Reason for loss: All reasons', $rows[0][0]);
        $this->assertSame('Loss Reason', end($rows[1]));

        $reasons = collect(array_slice($rows, 2))->mapWithKeys(fn ($r) => [$r[0] => end($r)]);

        $this->assertSame('No reason recorded', $reasons[$this->leads['L4']->id], 'a gap is written out, never blank');
        $this->assertSame('Chose competitor', $reasons[$this->leads['L3']->id]);
        $this->assertCount(7, $reasons);
    }

    public function test_excel_and_pdf_carry_the_loss_reason_too(): void
    {
        $this->scenario();
        $filters = ['stage' => 'lost', 'date_basis' => 'lost', 'reason' => 'none'] + $this->window();

        $path = tempnam(sys_get_temp_dir(), 'loss').'.xlsx';
        file_put_contents($path, $this->export('excel', $filters)->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $this->assertStringContainsString('Marked lost between', $sheet[0][0]);
        $this->assertSame('Loss Reason', end($sheet[1]));
        $this->assertSame('No reason recorded', end($sheet[2]));
        $this->assertCount(3, $sheet);

        $pdf = $this->pdfText($this->export('pdf', $filters));

        $this->assertStringContainsString('Loss Reason', $pdf);
        $this->assertStringContainsString('Marked lost between', $pdf);
        $this->assertStringContainsString('No reason recorded', $pdf);
    }

    public function test_a_reason_cannot_narrow_an_export_whose_stage_is_not_lost(): void
    {
        $this->scenario();

        $this->assertSame(
            $this->exportCount(['stage' => 'in_discussion']),
            $this->exportCount(['stage' => 'in_discussion', 'reason' => 'competitor', 'date_basis' => 'lost'] + []),
        );

        $csv = $this->parseCsv(ltrim($this->export('csv', ['stage' => 'in_discussion'])->streamedContent(), "\xEF\xBB\xBF"));
        $this->assertSame('Lead ID', $csv[0][0], 'any other export starts with its header row, as before');
        $this->assertNotContains('Loss Reason', $csv[0]);
    }

    public function test_an_undated_lost_export_filters_on_the_current_reason(): void
    {
        $this->scenario();

        // L1, L2, L5 and L8 sit in Lost for budget now; L6 has been reopened
        $this->assertSame(4, $this->exportCount(['stage' => 'lost', 'reason' => 'budget']));
    }

    /* ---------------- fixture ---------------- */

    private function scenario(): void
    {
        $l = fn (string $name, ?User $owner, array $extra = []) => $this->leads[$name] = $this->lead($owner, $extra);

        $this->loss($l('L1', $this->alice, ['reason' => 'budget']), 'budget', now()->subDays(3), $this->alice);
        $this->loss($l('L2', $this->bob, ['project_id' => $this->beta->id, 'reason' => 'budget']), 'budget', now()->subDays(4), $this->bob);

        $l3 = $l('L3', $this->bob, ['reason' => 'competitor']);
        $this->loss($l3, 'location', now()->subDays(10), $this->bob);
        $this->loss($l3, 'competitor', now()->subDays(2), $this->bob);

        $this->loss($l('L4', $this->alice, ['reason' => null]), null, now()->subDays(5), $this->alice);
        $this->loss($l('L5', $this->alice, ['reason' => 'budget']), 'budget', now()->subDays(60), $this->alice);
        $this->loss($l('L6', $this->bob, ['stage' => 'in_discussion', 'reason' => 'budget', 'created_at' => now()->subDays(5)]), 'budget', now()->subDays(1), $this->admin);
        $this->loss($l('L7', $this->bob, ['reason' => 'not_interested']), 'not_interested', now()->subDays(6), $this->bob, imported: true);
        $this->loss($l('L8', $this->bob, ['reason' => 'budget']), 'budget', now()->subDays(7), null);
    }

    private function loss(Lead $lead, ?string $reason, Carbon $at, ?User $by, bool $imported = false): Todo
    {
        $todo = Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to ?? $this->admin->id,
            'scheduled_at' => $at->copy()->subHour(),
            'type' => 'call', 'status' => 'completed',
            'outcome_stage' => 'lost', 'lost_reason' => $reason,
            'completed_at' => $at, 'completed_by' => $by?->id,
        ]);

        if ($imported) {
            DB::table('todo_import_records')->insert([
                'source_file' => 'master_sheet', 'source_row' => $lead->id, 'source_column' => 'stage',
                'todo_id' => $todo->id, 'import_batch' => 'test',
            ]);
        }

        return $todo;
    }

    private function lead(?User $owner, array $attributes = []): Lead
    {
        $createdAt = $attributes['created_at'] ?? now()->subYear();
        unset($attributes['created_at']);

        $lead = Lead::create($attributes + [
            'first_name' => 'Meera', 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->alpha->id, 'source' => 'walk_in',
            'stage' => 'lost', 'assigned_to' => $owner?->id,
            'created_by' => $this->admin->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    /** The default report window, as the dates Export Data takes. */
    private function window(): array
    {
        return ['from' => '2026-08-08', 'to' => '2026-09-06'];
    }

    private function page(string $url, array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)
            ->get($url.'?'.http_build_query($query + ['reset' => 1]));

        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function listTotal(array $filters): int
    {
        return $this->page('/leads', $filters)['leads']['total'];
    }

    private function exportCount(array $filters): int
    {
        return $this->actingAs($this->admin)
            ->postJson('/export-data/count', $filters + ['data_type' => 'leads'])
            ->assertOk()
            ->json('count');
    }

    private function export(string $format, array $filters): TestResponse
    {
        return $this->actingAs($this->admin)->post('/export-data/download', $filters + [
            'data_type' => 'leads', 'format' => $format,
        ], ['Accept' => 'application/json']);
    }

    private function parseCsv(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    /** The text dompdf embeds — see ExportDataTest::pdfText(). */
    private function pdfText(TestResponse $response): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', (string) $response->getContent(), $matches);

        $text = '';

        foreach ($matches[1] as $data) {
            $decoded = @gzuncompress($data);

            if ($decoded === false) {
                $decoded = @gzinflate($data);
            }

            if ($decoded !== false) {
                $text .= preg_replace('/(.)\x00/s', '$1', $decoded);
            }
        }

        return $text;
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'first_name' => $name, 'last_name' => 'Test',
            'email' => strtolower($name).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }
}
