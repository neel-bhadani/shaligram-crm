<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SyncLeadAssigneesTest extends TestCase
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

    public function test_all_rules_preserve_unrelated_data_and_reach_the_leads_page(): void
    {
        $expected = [];
        foreach ($this->projects as $project) {
            foreach (['fresh', 'not_connected'] as $stage) {
                $expected[$this->lead($stage, $project)->id] = $this->users['Riya Gandhi'];
            }
        }
        foreach (['Vanam' => 'Mayur Patel', 'SkyDeck' => 'Ravi Patel', 'Felicity' => 'Dhanashri Meshram'] as $project => $name) {
            foreach (['site_visit_scheduled', 'site_visit_done', 'in_discussion'] as $stage) {
                $expected[$this->lead($stage, $this->projects[$project])->id] = $this->users[$name];
            }
        }
        foreach (['site_visit_scheduled', 'site_visit_done', 'in_discussion', 'lost', 'booking_done', 'connected', 'closed', 'cancelled'] as $stage) {
            $this->lead($stage, $this->projects['Other']);
        }
        $this->lead('fresh', $this->projects['Vanam'])->delete();
        $admin = User::factory()->create(['role' => 'admin']);
        $before = $this->snapshot();
        $this->artisan('leads:sync-assignees --dry-run')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('leads:sync-assignees')->assertSuccessful();
        $after = $this->snapshot();
        foreach ($expected as $id => $user) {
            $this->assertSame($user->id, Lead::find($id)->assigned_to);
            $before['leads'][$id]['assigned_to'] = $user->id;
            foreach ($before['todos'] as $todoId => $todo) {
                if ($todo['lead_id'] === $id && $todo['status'] === 'pending' && str_starts_with($todo['scheduled_at'], '2026-09-16')) {
                    $before['todos'][$todoId]['assigned_to'] = $user->id;
                }
            }
        }
        $this->assertSame($before, $after);
        $this->artisan('leads:sync-assignees')->expectsOutput('Updated 0 leads and 0 todos.')->assertSuccessful();
        $this->assertSame($after, $this->snapshot());
        $this->actingAs($admin)->get('/leads?reset=1')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($expected) {
                $page->where('leads.data', function ($rows) use ($expected) {
                    foreach ($rows as $row) {
                        if (isset($expected[$row['id']])) {
                            $this->assertSame($expected[$row['id']]->display_name, $row['owner']['display_name']);
                        }
                    }

                    return true;
                });
            });
    }

    public function test_missing_user_project_or_today_todo_stops_without_changes(): void
    {
        $lead = $this->lead('fresh', $this->projects['Vanam']);
        $this->users['Riya Gandhi']->delete();
        $before = $this->snapshot();
        $this->artisan('leads:sync-assignees')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->users['Riya Gandhi']->restore();
        $this->projects['Felicity']->delete();
        $before = $this->snapshot();
        $this->artisan('leads:sync-assignees')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->projects['Felicity']->restore();
        Todo::where('lead_id', $lead->id)->whereDate('scheduled_at', today())->update(['status' => 'completed']);
        $before = $this->snapshot();
        $this->artisan('leads:sync-assignees')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ambiguous_user_stops_without_changes(): void
    {
        $this->lead('fresh', $this->projects['Vanam']);
        User::factory()->create(['first_name' => 'Riya', 'last_name' => 'Gandhi', 'role' => 'telecaller']);
        $before = $this->snapshot();
        $this->artisan('leads:sync-assignees')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_todo_failure_rolls_back_lead_assignment_too(): void
    {
        $this->lead('fresh', $this->projects['Vanam']);
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER fail_todo_update BEFORE UPDATE ON todos BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        $this->artisan('leads:sync-assignees')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    private function lead(string $stage, Project $project): Lead
    {
        $lead = Lead::create([
            'first_name' => 'Test', 'last_name' => 'Lead', 'source' => 'walk_in',
            'project_id' => $project->id, 'stage' => $stage, 'assigned_to' => null,
            'assigned_role' => 'salesperson',
        ]);
        foreach ([['pending', now()], ['pending', today()->subDay()], ['pending', today()->addDay()], ['completed', now()]] as [$status, $when]) {
            Todo::create(['lead_id' => $lead->id, 'assigned_to' => $this->users['Mayur Patel']->id, 'status' => $status, 'scheduled_at' => $when]);
        }

        return $lead;
    }

    private function snapshot(): array
    {
        return collect(['leads', 'todos', 'users', 'projects', 'lead_activities', 'alerts', 'jobs', 'automation_logs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all()])->all();
    }
}
