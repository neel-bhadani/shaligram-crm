<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadCreationService;
use App\Services\LeadImport\LeadImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadImportAutoScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Before 09:00 so Today + Auto Scheduling starts from 09:00 exactly.
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(6, 0));
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

    private function parsed(array $upload, ?array $mapping = null): array
    {
        return $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $mapping ?? $upload['guessedMapping'], 'date_order' => 'auto'])->assertOk()->json();
    }

    private function finalPreview(array $upload, array $settings = [], array $omit = []): array
    {
        $state = app(LeadImportStore::class)->read(auth()->user(), $upload['token']);
        $defaults = $settings + ['mode' => 'today', 'time' => '14:30', 'follow_up_type' => 'call'];
        $defaults = array_diff_key($defaults, array_flip($omit));

        return $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $state['mapping'], 'date_order' => $state['date_order'], 'defaults' => $defaults])->assertOk()->json();
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

    private function storedPlan(array $upload): array
    {
        return app(LeadImportStore::class)->read(auth()->user(), $upload['token'])['plan'];
    }

    private function times(array $plan): array
    {
        $times = array_values(array_filter(array_column($plan, 'follow_up_at'), fn (?string $at): bool => $at !== null));

        return array_map(fn (string $at): string => substr($at, 11, 5), $times);
    }

    public function test_auto_scheduling_single_lead_today_lands_at_09_00(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'One'])]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $this->assertSame(['2026-09-23' => 1], $final['dateDistribution']);
        $this->assertSame('2026-09-23T09:00:00', substr($final['previewRows'][0]['follow_up_at'], 0, 19));
        $this->finish($upload, $final);
        $this->assertSame('2026-09-23 09:00:00', Lead::first()->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('call', Lead::first()->pendingTodo->type);
    }

    public function test_auto_scheduling_two_leads_today_spans_the_full_window(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'One', 'Mobile' => '9000000001']), $this->row(['Name' => 'Two', 'Mobile' => '9000000002'])]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $this->assertSame(['09:00', '17:00'], $this->times($this->storedPlan($upload)));
        $this->assertSame('2026-09-23T09:00:00', substr($final['previewRows'][0]['follow_up_at'], 0, 19));
        $this->assertSame('2026-09-23T17:00:00', substr($final['previewRows'][1]['follow_up_at'], 0, 19));
    }

    public function test_auto_scheduling_three_leads_today_uses_09_13_17(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $rows[] = $this->row(['Name' => "Lead {$i}", 'Mobile' => sprintf('900000000%d', $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $this->assertSame(['09:00', '13:00', '17:00'], $this->times($this->storedPlan($upload)));
    }

    public function test_auto_scheduling_five_leads_today_uses_two_hour_gaps(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->row(['Name' => "Lead {$i}", 'Mobile' => sprintf('900000000%d', $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $this->assertSame(['09:00', '11:00', '13:00', '15:00', '17:00'], $this->times($this->storedPlan($upload)));
    }

    public function test_auto_scheduling_nine_leads_today_fills_the_window_hourly(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 1; $i <= 9; $i++) {
            $rows[] = $this->row(['Name' => "Lead {$i}", 'Mobile' => sprintf('900000000%d', $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $expected = [];
        for ($hour = 9; $hour <= 17; $hour++) {
            $expected[] = sprintf('%02d:00', $hour);
        }
        $this->assertSame($expected, $this->times($this->storedPlan($upload)));
    }

    public function test_auto_scheduling_a_thousand_leads_today_stays_inside_the_window_in_order(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = $this->row(['Name' => "Lead {$i}", 'Mobile' => sprintf('90%08d', $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $plan = $this->storedPlan($upload);
        $times = $this->times($plan);
        $this->assertCount(1000, $times);
        $this->assertSame('09:00', $times[0]);
        $this->assertSame('17:00', $times[999]);
        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'Auto slots are non-decreasing in source-row order.');
        $this->assertGreaterThan(400, count(array_unique($times)), 'A thousand rows are still spread across most of the window');
        foreach ($times as $at) {
            $this->assertTrue($at >= '09:00' && $at <= '17:00', "{$at} outside the 09:00-17:00 window");
        }
        $this->assertSame(1000, $final['summary']['toCreate']);
        $this->assertSame(1000, $final['summary']['followUpsToCreate']);
    }

    public function test_auto_scheduling_ignores_excluded_and_terminal_and_problem_rows(): void
    {
        $a = $this->actors();
        $upload = $this->upload([
            $this->row(['Name' => 'One', 'Mobile' => '9000000001']),
            $this->row(['Name' => 'Terminal', 'Mobile' => '9000000002', 'Stage' => 'booking_done']),
            $this->row(['Name' => 'Bad', 'Mobile' => '123']),
            $this->row(['Name' => 'Four', 'Mobile' => '9000000003']),
        ]);
        $fourRow = collect($this->parsed($upload)['previewRows'])->firstWhere('attributes.mobile_number', '9000000003')['row'];
        $this->postJson(route('leads.import.exclude'), ['token' => $upload['token'], 'row' => $fourRow])->assertOk();
        $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $plan = collect($this->storedPlan($upload));
        $byMobile = $plan->filter(fn (array $r): bool => $r['attributes']['mobile_number'] !== null)->keyBy(fn (array $r): string => $r['attributes']['mobile_number']);
        $this->assertSame('09:00', substr($byMobile['9000000001']['follow_up_at'], 11, 5));
        $this->assertNull($byMobile['9000000002']['follow_up_at'], 'terminal stage leads get no auto follow-up');
        $this->assertSame('error', $plan->firstWhere('attributes.mobile_number', null)['outcome']);
        $this->assertNull($byMobile['9000000003']['follow_up_at'], 'excluded rows get no follow-up');
        $this->assertCount(1, $plan->filter(fn (array $r): bool => $r['follow_up_at'] !== null), 'only the two eligible rows are scheduled');
    }

    public function test_auto_scheduling_ignores_duplicate_rows(): void
    {
        $a = $this->actors();
        Lead::create(['first_name' => 'Old', 'last_name' => '', 'mobile_number' => '9000000005', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']);
        $upload = $this->upload([
            $this->row(['Name' => 'One', 'Mobile' => '9000000001']),
            $this->row(['Name' => 'Dup', 'Mobile' => '9000000001']),
            $this->row(['Name' => 'InDb', 'Mobile' => '9000000005']),
            $this->row(['Name' => 'Four', 'Mobile' => '9000000003']),
        ]);
        $this->parsed($upload);
        $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $plan = $this->storedPlan($upload);
        $this->assertSame(['09:00', '17:00'], $this->times($plan));
        $byOutcome = collect($plan)->pluck('outcome')->all();
        $this->assertContains('skip_duplicate_file', $byOutcome);
        $this->assertContains('skip_duplicate_db', $byOutcome);
    }

    public function test_auto_scheduling_spread_mode_treats_each_date_with_its_own_window(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 1; $i <= 6; $i++) {
            $rows[] = $this->row(['Name' => "Lead {$i}", 'Mobile' => sprintf('900000000%d', $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_mode' => 'auto', 'mode' => 'spread', 'start_date' => '2026-09-27', 'end_date' => '2026-09-30'], ['time', 'fallback_time']);
        $this->assertSame(['2026-09-27' => 2, '2026-09-28' => 2, '2026-09-29' => 1, '2026-09-30' => 1], $final['dateDistribution']);
        $plan = collect($this->storedPlan($upload))->keyBy(fn (array $r): string => $r['attributes']['mobile_number']);
        $this->assertSame('2026-09-27T09:00:00', substr($plan['9000000001']['follow_up_at'], 0, 19));
        $this->assertSame('2026-09-27T17:00:00', substr($plan['9000000002']['follow_up_at'], 0, 19));
        $this->assertSame('2026-09-28T09:00:00', substr($plan['9000000003']['follow_up_at'], 0, 19));
        $this->assertSame('2026-09-28T17:00:00', substr($plan['9000000004']['follow_up_at'], 0, 19));
        $this->assertSame('2026-09-29T09:00:00', substr($plan['9000000005']['follow_up_at'], 0, 19));
        $this->assertSame('2026-09-30T09:00:00', substr($plan['9000000006']['follow_up_at'], 0, 19));
    }

    public function test_auto_scheduling_resolutions_are_persisted_exactly_across_import_chunks(): void
    {
        $this->actors();
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = $this->row(['Name' => "Lead {$i}", 'Mobile' => sprintf('90%08d', $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_mode' => 'auto', 'mode' => 'spread', 'start_date' => '2026-09-27', 'end_date' => '2026-09-29'], ['time', 'fallback_time']);
        $plan = collect($this->storedPlan($upload))
            ->filter(fn (array $r): bool => $r['follow_up_at'] !== null)
            ->mapWithKeys(fn (array $r): array => [$r['attributes']['mobile_number'] => substr($r['follow_up_at'], 0, 19)]);
        $result = $this->finish($upload, $final);
        $this->assertSame(250, $result['created']);
        $this->assertGreaterThan(1, $result['processed'] / 200, 'import spans multiple chunks');
        foreach ($plan as $mobile => $at) {
            $todo = Lead::where('mobile_number', $mobile)->firstOrFail()->pendingTodo;
            $this->assertSame(substr($at, 0, 10), $todo->scheduled_at->format('Y-m-d'), $mobile);
            $this->assertSame(substr($at, 11, 5), $todo->scheduled_at->format('H:i'), $mobile);
        }
    }

    public function test_manual_time_mode_is_unchanged_and_legacy_fixed_still_works(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload);
        $explicit = $this->finalPreview($upload, ['time_mode' => 'manual']);
        $this->assertSame('2026-09-23T14:30:00', substr($explicit['previewRows'][0]['follow_up_at'], 0, 19));
        $legacy = $this->finalPreview($upload);
        $this->assertSame('2026-09-23T14:30:00', substr($legacy['previewRows'][0]['follow_up_at'], 0, 19));
        $this->finish($upload, $legacy);
        $this->assertSame('2026-09-23 14:30:00', Lead::first()->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_auto_scheduling_matches_the_manual_canonical_path_for_the_same_datetime(): void
    {
        $a = $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'Auto'])]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_mode' => 'auto', 'mode' => 'specific', 'start_date' => '2026-10-05'], ['time', 'fallback_time']);
        $this->finish($upload, $final);
        $imported = Lead::first()->pendingTodo;
        $this->assertSame('2026-10-05', $imported->scheduled_at->format('Y-m-d'));
        $this->assertSame('09:00', $imported->scheduled_at->format('H:i'));
        $manual = app(LeadCreationService::class)->create(
            ['first_name' => 'Manual', 'last_name' => '', 'mobile_number' => '9000000099', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh'],
            $a['admin'], $a['admin'], $imported->scheduled_at, 'call', null
        );
        $this->assertSame($imported->scheduled_at->format('Y-m-d H:i:s'), $manual->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($imported->type, $manual->pendingTodo->type);
    }

    public function test_auto_scheduling_today_never_schedules_in_the_past(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(15, 0));
        $this->actors();
        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $rows[] = $this->row(['Name' => "Lead {$i}", 'Mobile' => sprintf('900000000%d', $i)]);
        }
        $upload = $this->upload($rows);
        $this->parsed($upload);
        $final = $this->finalPreview($upload, ['time_mode' => 'auto'], ['time', 'fallback_time']);
        $this->assertSame(['15:01', '16:01', '17:00'], $this->times($this->storedPlan($upload)));
        foreach ($final['previewRows'] as $row) {
            $this->assertTrue(Carbon::parse($row['follow_up_at']) > now('Asia/Kolkata'), 'no follow-up may land in the past');
        }
    }

    public function test_auto_scheduling_today_after_17_00_is_rejected_until_a_later_date_is_chosen(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 23)->setTime(17, 30));
        $a = $this->actors();
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload);
        $state = app(LeadImportStore::class)->read(auth()->user(), $upload['token']);
        $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $state['mapping'], 'date_order' => 'auto', 'defaults' => ['mode' => 'today', 'time_mode' => 'auto', 'follow_up_type' => 'call', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'fresh']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('defaults.mode');
    }

    public function test_uploaded_time_mode_with_a_blank_time_row_uses_the_fallback(): void
    {
        $this->actors();
        $upload = $this->upload([
            $this->row(['Name' => 'WithTime', 'Mobile' => '9000000001', 'Follow-up Date' => '2026-10-05', 'Follow-up Time' => '11:15']),
            $this->row(['Name' => 'NoTime', 'Mobile' => '9000000002', 'Follow-up Date' => '2026-10-05', 'Follow-up Time' => '']),
        ]);
        $preview = $this->parsed($upload);
        $this->assertSame(1, $preview['summary']['missingFollowUpTimes']);
        $final = $this->finalPreview($upload, ['time_mode' => 'uploaded', 'fallback_time' => '09:45'], ['time']);
        $this->finish($upload, $final);
        $this->assertSame('2026-10-05 11:15:00', Lead::where('mobile_number', '9000000001')->firstOrFail()->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 09:45:00', Lead::where('mobile_number', '9000000002')->firstOrFail()->pendingTodo->scheduled_at->format('Y-m-d H:i:s'));
    }
}
