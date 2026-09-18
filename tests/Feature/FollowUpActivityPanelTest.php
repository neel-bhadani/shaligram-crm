<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The follow-up update modal's activity panel.
 *
 * "Update" on a follow-up reuses the lead view's activity, fed by the exact
 * endpoint and component the lead view uses: CompleteTaskModal fetches
 * /leads/{the follow-up's lead}, the same JSON LeadViewModal reads there, and
 * renders the shared LeadActivityTimeline. The panel is display-only — it
 * must never leak another lead's history, read anything the user could not
 * open on the lead page, or write a single row while the update form keeps
 * doing what it always did.
 *
 * @see \App\Services\LeadTimeline
 * @see \App\Http\Controllers\LeadController::show
 */
class FollowUpActivityPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tele;

    private User $sales;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->sales = $this->user('salesperson', 'Sam');

        $this->project = Project::create(['name' => 'Alpha']);
        // Sam handles Alpha, so a site visit here hands the lead to him
        $this->project->salespeople()->attach($this->sales);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- the panel's data source ---------------- */

    /**
     * The panel is fed by /leads/{id} for the follow-up's own lead — the same
     * endpoint and payload the lead view modal reads — so the two can never
     * disagree, and the follow-up can never inherit another lead's history.
     */
    public function test_the_panel_serves_the_follow_ups_own_leads_timeline(): void
    {
        $todo = $this->openFollowUp();
        $lead = $todo->lead;

        $this->assertSame($lead->id, $todo->lead_id, 'the follow-up is pinned to this lead');

        // `connected` is a telecaller-owned stage, so the lead stays with Tara
        // and she can still open it; a salesperson-owned stage would hand it to
        // Sam and that 403 would belong in the authorization test below.
        $this->logCall($lead, $this->tele, ['stage' => 'connected', 'remarks' => 'Sent.']);

        $response = $this->actingAs($this->tele)->getJson("/leads/{$lead->id}")->assertOk();

        $this->assertSame(['lead', 'timeline', 'reassignCandidates'], array_keys($response->json()));

        $timeline = $response->json('timeline');

        $this->assertCount(2, $timeline);
        $this->assertSame('created', $timeline[0]['kind']);
        $this->assertSame('follow_up_completed', $timeline[1]['kind']);
        $this->assertSame('connected', $timeline[1]['to_stage']);
    }

    /**
     * Open follow-up A, then follow-up B: each update shows its own lead's
     * activity, and neither response ever carries the other lead's history —
     * every key is lead-scoped, and the panel re-fetches on every open.
     */
    public function test_follow_up_a_and_b_show_their_own_leads_activity(): void
    {
        [$todoA, $leadA] = $this->followUpAt('connected');
        [$todoB, $leadB] = $this->followUpAt('fresh');

        $this->logCall($leadA, $this->tele, [
            'stage' => 'site_visit_scheduled',
            'remarks' => 'Coming Sunday.',
            'follow_up_type' => 'site_visit',
        ]);

        $this->assertSame($leadA->id, $todoA->lead_id);
        $this->assertSame($leadB->id, $todoB->lead_id);

        $timelineA = $this->timeline($leadA);
        $timelineB = $this->timeline($leadB);

        $this->assertSame(['created', 'follow_up_completed'], array_column($timelineA, 'kind'));
        $this->assertSame(['created'], array_column($timelineB, 'kind'));

        // B's history is B's alone: nothing from A's site-visit move appears
        $this->assertNotContains('site_visit_scheduled', array_column($timelineB, 'to_stage'));
        $this->assertNotContains('follow_up_completed', array_column($timelineB, 'kind'));

        // no key is shared: the two timelines are two leads' histories, never
        // interleaved or read across the follow-ups
        $this->assertSame([], array_values(array_intersect(
            array_column($timelineA, 'key'),
            array_column($timelineB, 'key'),
        )));
    }

    /* ---------------- the panel leaves everything else alone ---------------- */

    public function test_logging_the_follow_up_still_saves_normally(): void
    {
        $todo = $this->openFollowUp();

        $this->actingAs($this->tele)
            ->post("/todos/{$todo->id}/complete", [
                'remarks' => 'Brochure sent.',
                'stage' => 'details_shared',
                'follow_up_type' => 'call',
                'follow_up_at' => '2026-09-13 11:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $todo->fresh()->status);
        $this->assertSame('Brochure sent.', $todo->fresh()->remarks);
        $this->assertSame(1, Todo::where('lead_id', $todo->lead_id)->where('status', 'pending')->count(),
            'the next follow-up is still scheduled');
    }

    public function test_viewing_the_activity_changes_nothing(): void
    {
        $todo = $this->openFollowUp();

        [$activities, $todos, $leads] = [LeadActivity::count(), Todo::count(), Lead::count()];

        $this->actingAs($this->tele)->getJson("/leads/{$todo->lead_id}")
            ->assertOk()
            ->assertJsonCount(1, 'timeline');

        $this->assertSame($activities, LeadActivity::count(), 'no activity row written');
        $this->assertSame($todos, Todo::count(), 'no follow-up row written');
        $this->assertSame($leads, Lead::count(), 'no lead row written');
    }

    public function test_a_user_who_could_not_open_the_lead_cannot_see_its_activity(): void
    {
        $todo = $this->openFollowUp();
        $other = $this->user('telecaller', 'Tom');

        $this->actingAs($other)->getJson("/leads/{$todo->lead_id}")->assertForbidden();

        $this->actingAs($this->tele)->getJson("/leads/{$todo->lead_id}")->assertOk();
    }

    /* ---------------- fixtures ---------------- */

    /** An open lead at its first pending follow-up, through the real forms. */
    private function openFollowUp(string $stage = 'fresh'): Todo
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['stage' => $stage]))
            ->assertSessionHasNoErrors();

        $lead = Lead::latest('id')->firstOrFail();

        return Todo::where('lead_id', $lead->id)->where('status', 'pending')->firstOrFail();
    }

    /** @return array{0: Todo, 1: Lead} */
    private function followUpAt(string $stage): array
    {
        $todo = $this->openFollowUp($stage);

        return [$todo, $todo->lead];
    }

    private function logCall(Lead $lead, User $as, array $data): void
    {
        $todo = Todo::where('lead_id', $lead->id)->where('status', 'pending')->firstOrFail();

        $this->actingAs($as)
            ->post("/todos/{$todo->id}/complete", $data + [
                'follow_up_type' => 'call',
                'follow_up_at' => '2026-09-13 11:00',
            ])
            ->assertSessionHasNoErrors();
    }

    /** @return list<array<string, mixed>> */
    private function timeline(Lead $lead): array
    {
        return $this->actingAs($this->admin)->getJson("/leads/{$lead->id}")->assertOk()->json('timeline');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => '2026-09-13 11:00',
        ], $overrides);
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