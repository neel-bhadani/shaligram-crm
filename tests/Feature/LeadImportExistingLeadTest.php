<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadFollowUpService;
use App\Services\LeadImport\LeadImportService;
use App\Services\LeadImport\LeadImportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A bulk-import row whose mobile is already a lead on its project resets that
 * lead to Fresh instead of being skipped; a mapped full-name column can be
 * split into First / Last only.
 */
class LeadImportExistingLeadTest extends TestCase
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

    private function existingLead(Project $project, User $owner, string $stage, bool $withPendingTodo = true): Lead
    {
        $lead = Lead::create([
            'first_name' => 'Existing', 'last_name' => '', 'mobile_number' => '9876543210', 'project_id' => $project->id,
            'source' => 'facebook', 'stage' => $stage, 'assigned_to' => $owner->id, 'assigned_role' => $owner->role,
        ]);
        if ($withPendingTodo) {
            Todo::create(['lead_id' => $lead->id, 'assigned_to' => $owner->id, 'scheduled_at' => now()->addDays(3), 'type' => 'call', 'status' => 'pending']);
        }

        return $lead;
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

    private function parsed(array $upload, string $nameSplit = 'first_middle_last'): array
    {
        return $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $upload['guessedMapping'], 'date_order' => 'auto', 'name_split' => $nameSplit])->assertOk()->json();
    }

    private function finalPreview(array $upload): array
    {
        $state = app(LeadImportStore::class)->read(auth()->user(), $upload['token']);

        return $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $state['mapping'], 'date_order' => $state['date_order'], 'name_split' => $state['name_split'], 'defaults' => [
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

    public function test_an_existing_lead_is_reset_to_fresh_with_the_planned_follow_up_instead_of_being_skipped(): void
    {
        $a = $this->actors();
        $lead = $this->existingLead($a['project'], $a['sales'], 'site_visit_scheduled');
        $upload = $this->upload([$this->row(['Name' => 'Rahul Again'])]);

        $preview = $this->parsed($upload);
        $this->assertSame(1, $preview['summary']['toUpdate']);
        $this->assertSame(0, $preview['summary']['toCreate']);
        $this->assertSame(0, $preview['summary']['problems']);
        $this->assertSame(1, $preview['summary']['duplicatesInDatabase']);
        $this->assertSame('update', $preview['rows'][0]['outcome']);
        $this->assertSame([['id' => $lead->id, 'stage' => 'site_visit_scheduled', 'stage_label' => 'Site visit scheduled']], $preview['rows'][0]['existing_leads']);

        $final = $this->finalPreview($upload);
        $this->assertSame(1, $final['summary']['followUpsToCreate']);
        $result = $this->finish($upload, $final);

        $this->assertSame(['created' => 0, 'updated' => 1, 'skipped' => 0, 'failed' => 0, 'followUps' => 1], array_intersect_key($result, array_flip(['created', 'updated', 'skipped', 'failed', 'followUps'])));
        $this->assertDatabaseCount('leads', 1);
        $lead->refresh();
        $this->assertSame('fresh', $lead->stage);
        $this->assertSame('Existing', $lead->first_name, 'only the stage changes');
        $this->assertSame($a['sales']->id, $lead->assigned_to, 'the owner is kept');
        $pending = $lead->todos()->where('status', 'pending')->get();
        $this->assertCount(1, $pending, 'the planned follow-up replaces the old pending one');
        $this->assertSame('2026-09-23 14:30', $pending->first()->scheduled_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame(1, $lead->todos()->where('status', 'cancelled')->count());
        $this->assertTrue($lead->todos()->where('status', 'completed')->where('outcome_stage', 'fresh')->where('remarks', LeadImportService::RESET_REMARK)->exists(), 'the reset is in the stage history');
    }

    public function test_a_terminal_existing_lead_is_reopened_at_fresh_with_a_follow_up(): void
    {
        $a = $this->actors();
        $lead = $this->existingLead($a['project'], $a['sales'], 'lost', withPendingTodo: false);
        $upload = $this->upload([$this->row(['Stage' => 'booking_done'])]);
        $this->parsed($upload);

        $result = $this->finish($upload, $this->finalPreview($upload));

        $this->assertSame(1, $result['updated']);
        $this->assertSame('fresh', $lead->refresh()->stage);
        $this->assertSame(1, $lead->todos()->where('status', 'pending')->count(), 'a reopened lead is never left without a pending follow-up');
    }

    public function test_an_already_fresh_or_deleted_existing_lead_is_still_skipped_and_left_untouched(): void
    {
        $a = $this->actors();
        $fresh = $this->existingLead($a['project'], $a['caller'], 'fresh');
        $deleted = Lead::create(['first_name' => 'Gone', 'last_name' => '', 'mobile_number' => '9876500001', 'project_id' => $a['project']->id, 'source' => 'facebook', 'stage' => 'connected']);
        $deleted->delete();
        $upload = $this->upload([$this->row(), $this->row(['Mobile' => '9876500001'])]);

        $preview = $this->parsed($upload);
        $this->assertSame(0, $preview['summary']['toUpdate']);
        $this->assertSame(['skip_duplicate_db', 'skip_duplicate_db'], array_column($preview['rows'], 'outcome'));
        $this->assertStringContainsString('already at Fresh', $preview['rows'][0]['errors'][0]);
        $this->assertStringContainsString('deleted lead', $preview['rows'][1]['errors'][0]);

        $result = $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(1, $fresh->todos()->count(), 'nothing was written to the fresh lead');
        $this->assertSame('connected', Lead::withTrashed()->find($deleted->id)->stage);
    }

    public function test_repeats_of_an_existing_lead_in_one_file_reset_it_once(): void
    {
        $a = $this->actors();
        $lead = $this->existingLead($a['project'], $a['sales'], 'connected');
        $upload = $this->upload([$this->row(), $this->row(['Name' => 'Rahul Dup'])]);

        $preview = $this->parsed($upload);
        $this->assertSame(['update', 'skip_duplicate_file'], array_column($preview['rows'], 'outcome'));

        $result = $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $lead->todos()->where('status', 'pending')->count());
        $this->assertSame(1, $lead->todos()->where('outcome_stage', 'fresh')->count());
    }

    public function test_a_salesperson_cannot_reset_an_existing_lead_they_cannot_edit(): void
    {
        $a = $this->actors();
        $lead = $this->existingLead($a['project'], $a['caller'], 'connected');
        $this->actingAs($a['sales']);
        $upload = $this->upload([$this->row()]);

        $preview = $this->parsed($upload);
        $this->assertSame('error', $preview['rows'][0]['outcome']);
        $this->assertContains('unauthorizedLead', $preview['rows'][0]['codes']);

        $this->finish($upload, $this->finalPreview($upload));
        $this->assertSame('connected', $lead->refresh()->stage);
    }

    public function test_a_lead_reset_by_someone_else_after_the_final_preview_is_skipped_not_reset_twice(): void
    {
        $a = $this->actors();
        $lead = $this->existingLead($a['project'], $a['sales'], 'connected');
        $upload = $this->upload([$this->row()]);
        $this->parsed($upload);
        $final = $this->finalPreview($upload);
        $lead->update(['stage' => 'fresh']);

        $result = $this->finish($upload, $final);

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $lead->todos()->where('outcome_stage', 'fresh')->count());
    }

    public function test_reimporting_the_same_file_changes_nothing_even_after_the_lead_moved_on(): void
    {
        $a = $this->actors();
        $rows = [$this->row()];
        $first = $this->upload($rows);
        $this->parsed($first);
        $this->assertSame(1, $this->finish($first, $this->finalPreview($first))['created']);
        $lead = Lead::sole();
        app(LeadFollowUpService::class)->changeStage($lead, 'site_visit_scheduled', [], now()->addDays(2), 'call');
        $lead->refresh();
        $pending = $lead->todos()->where('status', 'pending')->sole();
        $todoCount = $lead->todos()->count();

        $again = $this->upload($rows);
        $preview = $this->parsed($again);
        $this->assertSame('skip_imported', $preview['rows'][0]['outcome']);
        $this->assertSame(0, $preview['summary']['toUpdate']);
        $result = $this->finish($again, $this->finalPreview($again));

        $this->assertSame(0, $result['created'] + $result['updated']);
        $lead->refresh();
        $this->assertSame('site_visit_scheduled', $lead->stage);
        $this->assertSame($pending->id, $lead->todos()->where('status', 'pending')->sole()->id);
        $this->assertEquals($pending->scheduled_at, $lead->todos()->where('status', 'pending')->sole()->scheduled_at);
        $this->assertSame($todoCount, $lead->todos()->count());
    }

    public function test_reimporting_a_file_that_reset_a_lead_does_not_reset_it_again(): void
    {
        $a = $this->actors();
        $lead = $this->existingLead($a['project'], $a['sales'], 'connected');
        $rows = [$this->row()];
        $first = $this->upload($rows);
        $this->parsed($first);
        $this->assertSame(1, $this->finish($first, $this->finalPreview($first))['updated']);
        app(LeadFollowUpService::class)->changeStage($lead->refresh(), 'in_discussion', [], now()->addDay(), 'call');

        $again = $this->upload($rows);
        $this->assertSame('skip_imported', $this->parsed($again)['rows'][0]['outcome']);
        $this->assertSame(0, $this->finish($again, $this->finalPreview($again))['updated']);
        $this->assertSame('in_discussion', $lead->refresh()->stage);
    }

    public function test_a_full_name_can_be_split_into_first_and_last_name_only(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'Rahul Kumar Sharma'])]);

        $default = $this->parsed($upload)['rows'][0]['attributes'];
        $this->assertSame(['Rahul', 'Kumar', 'Sharma'], [$default['first_name'], $default['middle_name'], $default['last_name']]);

        $split = $this->parsed($upload, 'first_last')['rows'][0]['attributes'];
        $this->assertSame(['Rahul', '', 'Kumar Sharma'], [$split['first_name'], $split['middle_name'], $split['last_name']]);

        $this->finish($upload, $this->finalPreview($upload));
        $lead = Lead::sole();
        $this->assertSame(['Rahul', null, 'Kumar Sharma'], [$lead->first_name, $lead->middle_name ?: null, $lead->last_name]);
    }

    public function test_changing_the_name_split_after_the_preview_requires_a_fresh_preview(): void
    {
        $this->actors();
        $upload = $this->upload([$this->row(['Name' => 'Rahul Kumar Sharma'])]);
        $this->parsed($upload, 'first_last');

        $this->postJson(route('leads.import.final-preview'), ['token' => $upload['token'], 'accepted_preview' => true, 'mapping' => $upload['guessedMapping'], 'date_order' => 'auto', 'name_split' => 'first_middle_last', 'defaults' => [
            'mode' => 'today', 'time' => '14:30', 'follow_up_type' => 'call',
        ]])->assertConflict();
        $this->postJson(route('leads.import.preview'), ['token' => $upload['token'], 'mapping' => $upload['guessedMapping'], 'name_split' => 'last_first'])->assertUnprocessable()->assertJsonValidationErrors('name_split');
    }
}
