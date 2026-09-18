<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The inline "+"/"−" activity panel on the Follow-ups page.
 *
 * The expand control is front-end only — it fetches /leads/{id} (the exact
 * endpoint, authorization and payload the lead view modal reads) lazily on the
 * first click and keeps one in-memory, lead-scoped cache for the page visit.
 * Nothing about it writes: no activity row, no to-do, no lead. What the server
 * owes this feature is that every follow-up row on the page carries its own
 * lead's id (so the panel can ask for the right lead) and that reading the page
 * costs nothing more when there are more follow-ups.
 *
 * @see \App\Services\LeadTimeline
 * @see \App\Http\Controllers\LeadController::show
 * @see \App\Http\Controllers\TodoController::index
 */
class FollowUpInlineActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tele;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- what the page owes the expand control ---------------- */

    /**
     * Every follow-up row must carry its own lead's id, and the page must send
     * the stage colours the shared timeline component needs — the two things the
     * panel depends on before it can fetch /leads/{id} and render identically
     * to Lead -> View -> Activity.
     */
    public function test_the_follow_ups_page_ships_each_rows_own_lead_and_the_stage_colours(): void
    {
        $leadA = $this->lead('A');
        $leadB = $this->lead('B');

        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=upcoming')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('options.stageColors')
                ->where('todos.data.0.lead.id', $leadA->id)
                ->where('todos.data.1.lead.id', $leadB->id));
    }

    /* ---------------- the exact scenario: A opens, then B ---------------- */

    /**
     * Two follow-ups on two different leads. Each row's "+" shows that row's own
     * lead's timeline, and what follow-up B opens never contains a record from
     * follow-up A — lead-scoped data, never shared across leads.
     */
    public function test_follow_up_a_and_follow_up_b_open_two_distinct_leads_timelines(): void
    {
        $leadA = $this->lead('A');
        $leadB = $this->lead('B');

        $timelineA = $this->timeline($leadA);
        $timelineB = $this->timeline($leadB);

        // no record of A's history can appear on B's panel
        $this->assertSame([], array_values(array_intersect(
            array_column($timelineA, 'key'),
            array_column($timelineB, 'key'),
        )), 'every record is pinned to the lead that owns it');

        $this->assertSame(['created'], array_column($timelineA, 'kind'));
        $this->assertSame(['created'], array_column($timelineB, 'kind'));
    }

    /**
     * Reading the same activity twice returns the same ordered records — the
     * panel never manufactures a second copy of a record that already exists.
     */
    public function test_the_activity_holds_no_duplicate_records(): void
    {
        $lead = $this->lead('A');

        $first = $this->timeline($lead);
        $second = $this->timeline($lead);

        $this->assertSame(
            array_column($first, 'key'),
            array_column($second, 'key')
        );

        $this->assertCount(count(array_unique(array_column($second, 'key'))), $second,
            'one record per event, never a duplicate');
    }

    /**
     * Two follow-ups on the SAME lead are one lead's history: both rows point at
     * the same lead id, so the in-memory lead-scoped cache serves both with one
     * fetch, and the lead view shows the same timeline.
     */
    public function test_two_follow_ups_on_the_same_lead_share_one_timeline(): void
    {
        $lead = $this->lead('A');
        $todo = Todo::where('lead_id', $lead->id)->where('status', 'pending')->firstOrFail();

        // complete the first call and book its replacement: a second follow-up,
        // still on the same lead
        $this->actingAs($this->tele)
            ->post("/todos/{$todo->id}/complete", [
                'remarks' => 'Sent.',
                'stage' => 'connected',
                'follow_up_type' => 'call',
                'follow_up_at' => '2026-09-14 11:00',
            ])
            ->assertSessionHasNoErrors();

        // the closed follow-up and its replacement are two rows — on different
        // tabs, because an open lead holds exactly one pending task — but both
        // carry the SAME lead id, so the panel's lead-scoped cache serves both
        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=completed')
            ->assertInertia(fn (Assert $page) => $page
                ->where('todos.data.0.lead.id', $lead->id));

        $this->actingAs($this->admin)
            ->get('/todos?reset=1&tab=upcoming')
            ->assertInertia(fn (Assert $page) => $page
                ->where('todos.data.0.lead.id', $lead->id));

        // created + the completed call (its replacement follow-up folds in as a
        // "Next follow-up" detail): one unique record per event, none mentioning
        // another lead
        $timeline = $this->timeline($lead);
        $this->assertSame(['created', 'follow_up_completed'], array_column($timeline, 'kind'));

        $keys = array_column($timeline, 'key');
        $this->assertCount(count(array_unique($keys)), $keys);

        $this->assertContains('Next follow-up', array_column($timeline[1]['details'], 'label'),
            'the replacement follow-up is the same lead history, folded under the completed call');
    }

    /* ---------------- authorization and writes ---------------- */

    /**
     * The panel is read-only: opening it changes no activity row, no follow-up
     * and no lead.
     */
    public function test_viewing_the_expanded_activity_writes_nothing(): void
    {
        $leadA = $this->lead('A');
        $leadB = $this->lead('B');

        [$activities, $todos, $leads] = [LeadActivity::count(), Todo::count(), Lead::count()];

        $this->timeline($leadA);
        $this->timeline($leadB);
        $this->actingAs($this->tele)
            ->get('/todos?reset=1&tab=upcoming')
            ->assertOk();

        $this->assertSame($activities, LeadActivity::count(), 'no activity row written');
        $this->assertSame($todos, Todo::count(), 'no follow-up row written');
        $this->assertSame($leads, Lead::count(), 'no lead row written');
    }

    /**
     * The activity is served by the same policy as the lead itself: the "+" on
     * a row whose lead this user could not open gets the same 403 the lead view
     * gives, so the panel can never leak a lead the user is not authorized to
     * see.
     */
    public function test_a_follow_up_whose_lead_the_user_cannot_open_serves_no_activity(): void
    {
        $lead = $this->lead('A');
        $other = $this->user('telecaller', 'Tom');

        $this->actingAs($other)->getJson("/leads/{$lead->id}")->assertForbidden();

        $this->actingAs($this->tele)->getJson("/leads/{$lead->id}")->assertOk();
    }

    /* ---------------- no N+1 on the page load ---------------- */

    /**
     * The page renders without querying a fresh timeline per row — the panel is
     * fetched only on a "+" click — so the number of queries the page issues
     * must not grow when another follow-up (and its lead) is added.
     */
    public function test_the_page_load_query_count_does_not_grow_with_follow_ups(): void
    {
        $this->lead('A');

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/todos?reset=1&tab=upcoming')->assertOk();
        $oneRow = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->lead('B');

        DB::flushQueryLog();
        $this->actingAs($this->admin)->get('/todos?reset=1&tab=upcoming')->assertOk();
        $twoRows = count(DB::getQueryLog());

        $this->assertSame($oneRow, $twoRows,
            'a second follow-up must not add a query to the page load');
    }

    /* ---------------- fixtures ---------------- */

    /** A fresh lead (with its first pending follow-up), through the real form. */
    private function lead(string $tag): Lead
    {
        $this->actingAs($this->admin)
            ->post('/leads', [
                'first_name' => "Meera {$tag}",
                'last_name' => 'Sharma',
                'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
                'project_id' => $this->project->id,
                'source' => 'walk_in',
                'stage' => 'fresh',
                'follow_up_type' => 'call',
                'follow_up_at' => $tag === 'B'
                    ? '2026-09-14 11:00'
                    : '2026-09-13 11:00',
            ])
            ->assertSessionHasNoErrors();

        return Lead::latest('id')->firstOrFail();
    }

    /** @return list<array<string, mixed>> */
    private function timeline(Lead $lead): array
    {
        return $this->actingAs($this->tele)
            ->getJson("/leads/{$lead->id}")
            ->assertOk()
            ->json('timeline');
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