<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TodayLeadFollowupTest extends TestCase
{
    use RefreshDatabase;

    private array $users = [];

    private array $projects = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Kolkata is already on the next date while UTC is still yesterday.
        $this->travelTo(Carbon::parse('2026-09-16 00:15:00', 'Asia/Kolkata'));
        foreach (['Riya Gandhi', 'Mayur Patel', 'Ravi Patel', 'Dhanashri Meshram'] as $name) {
            [$first, $last] = explode(' ', $name, 2);
            $this->users[$name] = User::factory()->create([
                'first_name' => $first, 'last_name' => $last,
                'role' => $first === 'Riya' ? 'telecaller' : 'salesperson',
            ]);
        }
        foreach (['Vanam', 'SkyDeck', 'Felicity', 'Other'] as $name) {
            $this->projects[$name] = Project::create(['name' => $name]);
        }
    }

    public function test_all_assignments_dry_run_rerun_and_unchanged_source_records(): void
    {
        $expected = [];
        foreach ($this->projects as $project) {
            foreach (['fresh', 'not_connected'] as $stage) {
                $expected[$this->lead($stage, $project)->id] = $this->users['Riya Gandhi']->id;
            }
        }
        foreach (['Vanam' => 'Mayur Patel', 'SkyDeck' => 'Ravi Patel', 'Felicity' => 'Dhanashri Meshram'] as $project => $name) {
            foreach (['site_visit_scheduled', 'site_visit_done', 'in_discussion'] as $stage) {
                $expected[$this->lead($stage, $this->projects[$project])->id] = $this->users[$name]->id;
            }
        }
        foreach (['site_visit_scheduled', 'site_visit_done', 'in_discussion'] as $stage) {
            $this->lead($stage, $this->projects['Other']);
        }
        foreach (['lost', 'booking_done', 'closed', 'cancelled', 'connected', 'details_shared'] as $stage) {
            $this->lead($stage, $this->projects['Vanam']);
        }
        $this->lead('fresh', $this->projects['Vanam'])->delete();
        $before = $this->snapshot();
        $this->artisan('followups:create-today --dry-run')->assertSuccessful();
        $this->assertDatabaseCount('todos', 0);
        $this->artisan('followups:create-today')->expectsOutput('Created 17 follow-ups.')->assertSuccessful();
        $this->assertDatabaseCount('todos', 17);
        foreach ($expected as $lead => $assignee) {
            $this->assertDatabaseHas('todos', [
                'lead_id' => $lead, 'assigned_to' => $assignee,
                'status' => 'pending', 'scheduled_at' => '2026-09-16 00:15:00',
                'created_by' => null, 'completed_at' => null, 'type' => 'call',
            ]);
        }
        $this->artisan('followups:create-today')->expectsOutput('Created 0 follow-ups.')->assertSuccessful();
        $this->assertDatabaseCount('todos', 17);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_existing_today_todo_is_preserved_regardless_of_assignee_type_or_time(): void
    {
        $lead = $this->lead('fresh', $this->projects['Vanam']);
        $todo = Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->users['Ravi Patel']->id,
            'scheduled_at' => today()->endOfDay(), 'status' => 'pending', 'type' => 'site_visit',
        ]);
        $before = $todo->fresh()->getRawOriginal();
        $this->artisan('followups:create-today')->expectsOutput('Created 0 follow-ups.')->assertSuccessful();
        $this->assertDatabaseCount('todos', 1);
        $this->assertSame($before, $todo->fresh()->getRawOriginal());
    }

    public function test_other_dates_and_non_pending_statuses_do_not_block_today(): void
    {
        foreach ([['pending', today()->subDay()], ['pending', today()->addDay()], ['completed', today()], ['cancelled', today()]] as [$status, $when]) {
            $lead = $this->lead('fresh', $this->projects['Vanam']);
            Todo::create(['lead_id' => $lead->id, 'assigned_to' => $this->users['Riya Gandhi']->id, 'scheduled_at' => $when, 'status' => $status]);
        }
        $before = DB::table('todos')->orderBy('id')->get()->toJson();
        $last = Todo::max('id');
        $this->artisan('followups:create-today')->expectsOutput('Created 4 follow-ups.')->assertSuccessful();
        $this->artisan('followups:create-today')->expectsOutput('Created 0 follow-ups.')->assertSuccessful();
        $this->assertDatabaseCount('todos', 8);
        $this->assertSame($before, DB::table('todos')->where('id', '<=', $last)->orderBy('id')->get()->toJson());
    }

    public function test_missing_user_inserts_nothing(): void
    {
        $this->lead('fresh', $this->projects['Vanam']);
        $this->users['Dhanashri Meshram']->delete();
        $this->artisan('followups:create-today')->assertFailed();
        $this->assertDatabaseCount('todos', 0);
    }

    public function test_missing_project_inserts_nothing(): void
    {
        $this->lead('fresh', $this->projects['Vanam']);
        $this->projects['Felicity']->delete();
        $this->artisan('followups:create-today')->assertFailed();
        $this->assertDatabaseCount('todos', 0);
    }

    public function test_ambiguous_user_inserts_nothing(): void
    {
        $this->lead('fresh', $this->projects['Vanam']);
        User::factory()->create(['first_name' => 'Riya', 'last_name' => 'Gandhi', 'role' => 'telecaller']);
        $this->artisan('followups:create-today')->assertFailed();
        $this->assertDatabaseCount('todos', 0);
    }

    public function test_database_failure_rolls_back_inserts(): void
    {
        $this->lead('fresh', $this->projects['Vanam']);
        $this->lead('fresh', $this->projects['Vanam']);
        DB::unprepared("CREATE TRIGGER fail_second_todo BEFORE INSERT ON todos WHEN (SELECT COUNT(*) FROM todos) > 0 BEGIN SELECT RAISE(ABORT, 'simulated insert failure'); END");
        $this->artisan('followups:create-today')->assertFailed();
        $this->assertDatabaseCount('todos', 0);
    }

    private function lead(string $stage, Project $project): Lead
    {
        return Lead::create([
            'first_name' => 'Test', 'last_name' => 'Lead', 'source' => 'walk_in',
            'project_id' => $project->id, 'stage' => $stage,
            'assigned_to' => $this->users['Mayur Patel']->id,
        ]);
    }

    private function snapshot(): array
    {
        return collect(['leads', 'users', 'projects'])->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()->toJson(),
        ])->all();
    }
}
