<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * "Switch project" — a lead on one project is moved to a different one, on
 * the Follow-up page, without being closed or duplicated. Same lead row,
 * same stage; only `project_id` and who owns it move — see
 * LeadFollowUpService::switchProject().
 *
 * The two invariants LeadReassignmentTest checks after every automatic
 * assignment hold here too:
 *
 *   Lead::open()->doesntHave('pendingTodo')->count() === 0
 *   leads.assigned_role === the owner's own users.role
 *
 * Unlike reassign(), there is NO project boundary on who may trigger this —
 * the client's explicit call, see LeadPolicy::switchProject().
 */
class LeadProjectSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tia;

    private User $sam;

    private User $pat;

    private Project $alpha;

    private Project $pqr;

    private int $phone = 9200000000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-19 11:00'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tia = $this->user('telecaller', 'Tia');
        $this->sam = $this->user('salesperson', 'Sam');
        $this->pat = $this->user('salesperson', 'Pat');

        $this->alpha = $this->projectWith('Project ABC', $this->sam);
        $this->pqr = $this->projectWith('Project PQR', $this->pat);
    }

    /* ================================================================
     | Telecaller-owned stage
     ================================================================ */

    public function test_switching_a_fresh_lead_reassigns_it_to_a_telecaller_tied_to_the_new_project(): void
    {
        // telecallers are a single, company-wide desk — not project-scoped —
        // so Tia is the answer either way; the point is that the switch
        // itself runs, the lead id, its stage and its pending to-do survive.
        $lead = $this->add($this->admin, $this->alpha, 'fresh');
        $leadId = $lead->id;
        $todoId = $lead->pendingTodo->id;
        $this->assertSame($this->tia->id, $lead->assigned_to);

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($leadId, $lead->id, 'the same row, never a new one');
        $this->assertSame($this->pqr->id, $lead->project_id);
        $this->assertSame('fresh', $lead->stage, 'a switch never moves the stage');
        $this->assertSame($this->tia->id, $lead->assigned_to);
        $this->assertSame('telecaller', $lead->assigned_role);

        $this->assertSame(1, Todo::where('status', 'pending')->count(), 'moved, not duplicated');
        $this->assertSame($todoId, $lead->pendingTodo->id);
        $this->assertSame($this->tia->id, $lead->pendingTodo->assigned_to);
        $this->assertRoutingHolds();

        $entry = $this->timelineFor($lead)->firstWhere('title', 'Project switched');
        $this->assertNotNull($entry);
        $this->assertSame(['field' => 'Project', 'from' => 'Project ABC', 'to' => 'Project PQR'], $entry['change']);
    }

    /* ================================================================
     | Salesperson-owned stage
     ================================================================ */

    public function test_switching_an_in_discussion_lead_reassigns_it_to_a_salesperson_tied_to_the_new_project(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');
        $leadId = $lead->id;
        $todoId = $lead->pendingTodo->id;
        $this->assertSame($this->sam->id, $lead->assigned_to, 'Alpha\'s own salesperson');

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($leadId, $lead->id);
        $this->assertSame($this->pqr->id, $lead->project_id);
        $this->assertSame('in_discussion', $lead->stage, 'a switch never moves the stage');
        $this->assertSame($this->pat->id, $lead->assigned_to, 'PQR\'s own salesperson, via the same round robin as a new lead');
        $this->assertSame('salesperson', $lead->assigned_role);

        $this->assertSame(1, Todo::where('status', 'pending')->count(), 'moved, not duplicated');
        $this->assertSame($todoId, $lead->pendingTodo->id, 'the follow-up moved with the lead, never replaced');
        $this->assertSame($this->pat->id, $lead->pendingTodo->assigned_to);
        $this->assertRoutingHolds();

        // one head for the switch, one nested reassignment child — not a
        // second top-level "Manually reassigned" entry
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::ProjectSwitched)->count());
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::HandedOver)->count());
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::Reassigned)->count());

        $entry = $this->timelineFor($lead)->firstWhere('title', 'Project switched');
        $this->assertNotNull($entry);
        $this->assertSame(['field' => 'Project', 'from' => 'Project ABC', 'to' => 'Project PQR'], $entry['change']);
        $this->assertSame('Handed over', $entry['details'][0]['label']);
        $this->assertSame('Sam Tester → Pat Tester', $entry['details'][0]['value']);
    }

    /* ================================================================
     | The follow-up moves with assigned_to — assign()'s shared rule,
     | not anything switchProject() has to repeat. scheduled_at is never
     | touched, whatever state the follow-up was in.
     ================================================================ */

    public function test_switching_moves_an_overdue_follow_up_to_the_new_assignee_with_its_scheduled_at_unchanged(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');
        $todo = $lead->pendingTodo;
        $overdueAt = now()->subDays(3);
        $todo->update(['scheduled_at' => $overdueAt]);

        $this->assertTrue(Todo::overdue()->forUser($this->sam)->whereKey($todo->id)->exists(), 'on Sam\'s overdue list beforehand');

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->pat->id, $lead->assigned_to);

        $todo->refresh();
        $this->assertSame($this->pat->id, $todo->assigned_to);
        $this->assertTrue($todo->scheduled_at->equalTo($overdueAt), 'scheduled_at is untouched by the move');

        $this->assertFalse(Todo::overdue()->forUser($this->sam)->whereKey($todo->id)->exists(), 'gone from Sam\'s overdue list');
        $this->assertTrue(Todo::overdue()->forUser($this->pat)->whereKey($todo->id)->exists(), 'now on Pat\'s overdue list, still flagged overdue');
        $this->assertRoutingHolds();
    }

    public function test_switching_moves_a_follow_up_due_today_to_the_new_assignee_with_its_scheduled_at_unchanged(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');
        $todo = $lead->pendingTodo;
        $dueToday = now()->setTime(16, 0);
        $todo->update(['scheduled_at' => $dueToday]);

        $this->assertTrue(Todo::dueToday()->forUser($this->sam)->whereKey($todo->id)->exists());

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $todo->refresh();
        $this->assertSame($this->pat->id, $todo->assigned_to);
        $this->assertTrue($todo->scheduled_at->equalTo($dueToday));

        $this->assertFalse(Todo::dueToday()->forUser($this->sam)->whereKey($todo->id)->exists());
        $this->assertTrue(Todo::dueToday()->forUser($this->pat)->whereKey($todo->id)->exists());
        $this->assertRoutingHolds();
    }

    public function test_switching_moves_an_upcoming_follow_up_to_the_new_assignee_with_its_scheduled_at_unchanged(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');
        $todo = $lead->pendingTodo;
        $upcomingAt = now()->addDays(5);
        $todo->update(['scheduled_at' => $upcomingAt]);

        $this->assertTrue(Todo::upcoming()->forUser($this->sam)->whereKey($todo->id)->exists());

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $todo->refresh();
        $this->assertSame($this->pat->id, $todo->assigned_to);
        $this->assertTrue($todo->scheduled_at->equalTo($upcomingAt));

        $this->assertFalse(Todo::upcoming()->forUser($this->sam)->whereKey($todo->id)->exists());
        $this->assertTrue(Todo::upcoming()->forUser($this->pat)->whereKey($todo->id)->exists());
        $this->assertRoutingHolds();
    }

    /* ================================================================
     | Duplicate mobile on the target project
     ================================================================ */

    public function test_switching_to_a_project_that_already_has_this_mobile_number_is_blocked(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'fresh');

        // the same number, already filed on the target project
        Lead::create([
            'first_name' => 'Other',
            'last_name' => 'Lead',
            'mobile_number' => $lead->mobile_number,
            'project_id' => $this->pqr->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_to' => $this->pat->id,
            'assigned_role' => 'salesperson',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertSessionHasErrors('project_id');

        $this->assertSame($this->alpha->id, $lead->fresh()->project_id, 'the switch did not happen');
    }

    public function test_switching_to_the_same_project_is_rejected(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'fresh');

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->alpha->id])
            ->assertSessionHasErrors('project_id');
    }

    /* ================================================================
     | No project-scoping restriction on who may trigger it
     ================================================================ */

    public function test_a_telecaller_a_salesperson_and_an_admin_can_all_switch_a_project_they_own(): void
    {
        $telecallerLead = $this->add($this->admin, $this->alpha, 'fresh');
        $this->actingAs($this->tia)
            ->put(route('leads.switch-project', $telecallerLead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $salespersonLead = $this->add($this->admin, $this->alpha, 'in_discussion');
        $this->actingAs($this->sam)
            ->put(route('leads.switch-project', $salespersonLead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $adminLead = $this->add($this->admin, $this->alpha, 'fresh');
        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $adminLead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->pqr->id, $telecallerLead->fresh()->project_id);
        $this->assertSame($this->pqr->id, $salespersonLead->fresh()->project_id);
        $this->assertSame($this->pqr->id, $adminLead->fresh()->project_id);
    }

    /**
     * Reassign narrows a salesperson candidate to the lead's own project for
     * anybody without `see_all_leads` — see LeadReassignRequest. Switch
     * project carries no such boundary: a salesperson may switch a lead they
     * own to any active project, including one they are not themselves tied
     * to, because the target is the project, not a person.
     */
    public function test_a_salesperson_can_switch_their_own_lead_to_a_project_they_are_not_tied_to(): void
    {
        $beta = $this->projectWith('Beta');   // Sam is not on Beta

        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');
        $this->assertSame($this->sam->id, $lead->assigned_to);

        $this->actingAs($this->sam)
            ->put(route('leads.switch-project', $lead), ['project_id' => $beta->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($beta->id, $lead->fresh()->project_id);
    }

    /** The same ownership boundary LeadPolicy::view() enforces everywhere else. */
    public function test_a_telecaller_is_rejected_server_side_from_switching_a_lead_assigned_to_somebody_else(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');   // Sam's, not Tia's

        $this->actingAs($this->tia)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertForbidden();

        $this->assertSame($this->alpha->id, $lead->fresh()->project_id);
    }

    /* ================================================================
     | Combined: project switch + stage change in one follow-up
     | completion — see LeadFollowUpService::complete()'s $project and
     | schedule()'s $projectSwitched / handoverForNewProject().
     ================================================================ */

    /**
     * The scenario the fix exists for: a telecaller on the call hears the
     * customer wants a different project AND is ready for a site visit, in
     * the same breath. The switch must apply before the stage, so the
     * handover the stage change causes resolves against the NEW project —
     * not the salesperson the lead was about to leave behind on the old one.
     */
    public function test_completing_a_follow_up_with_both_a_new_project_and_the_handover_stage_reassigns_to_the_new_projects_salesperson(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'fresh');
        $todo = $lead->pendingTodo;
        $this->assertSame($this->tia->id, $lead->assigned_to);

        $this->actingAs($this->tia)
            ->post(route('todos.complete', $todo), [
                'stage' => 'site_visit_scheduled',
                'project_id' => $this->pqr->id,
                'remarks' => 'Wants to see PQR instead.',
                'follow_up_type' => 'site_visit',
                'follow_up_at' => now()->addDays(2)->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->pqr->id, $lead->project_id, 'moved to the new project');
        $this->assertSame('site_visit_scheduled', $lead->stage);
        $this->assertSame($this->pat->id, $lead->assigned_to, 'PQR\'s own salesperson, not Alpha\'s');
        $this->assertSame('salesperson', $lead->assigned_role);

        $next = $lead->pendingTodo;
        $this->assertNotSame($todo->id, $next->id, 'the completed call, not a still-pending one');
        $this->assertSame($this->pat->id, $next->assigned_to, 'the site visit belongs to the new owner');
        $this->assertRoutingHolds();

        $titles = $this->timelineFor($lead)->pluck('title');
        $this->assertTrue($titles->contains('Project switched'));
        $switched = $this->timelineFor($lead)->firstWhere('title', 'Project switched');
        $this->assertSame(['field' => 'Project', 'from' => 'Project ABC', 'to' => 'Project PQR'], $switched['change']);

        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::ProjectSwitched)->count());
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::HandedOver)->count());
    }

    /**
     * The gap a plain role-crossing check would miss: `in_discussion` to
     * `details_shared` never crosses a desk, both are salesperson stages —
     * but the project changed under the lead in the same action, so the
     * salesperson who held it is still ALPHA's, not PQR's. Without
     * handoverForNewProject() this would silently leave Sam holding a PQR
     * lead.
     */
    public function test_completing_a_follow_up_with_a_new_project_but_no_role_crossing_stage_still_reassigns_to_the_new_projects_owner(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');
        $todo = $lead->pendingTodo;
        $this->assertSame($this->sam->id, $lead->assigned_to);

        $this->actingAs($this->sam)
            ->post(route('todos.complete', $todo), [
                'stage' => 'details_shared',
                'project_id' => $this->pqr->id,
                'remarks' => 'Actually wants PQR.',
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDays(1)->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->pqr->id, $lead->project_id);
        $this->assertSame('details_shared', $lead->stage);
        $this->assertSame($this->pat->id, $lead->assigned_to, 'PQR\'s own salesperson, even though the stage stayed salesperson-owned');
        $this->assertSame('salesperson', $lead->assigned_role);
        $this->assertSame($this->pat->id, $lead->pendingTodo->assigned_to);
        $this->assertRoutingHolds();
    }

    /** No regression: leaving the project field alone still behaves exactly as before. */
    public function test_completing_a_follow_up_without_touching_the_project_field_still_hands_over_normally(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'fresh');
        $todo = $lead->pendingTodo;

        $this->actingAs($this->tia)
            ->post(route('todos.complete', $todo), [
                'stage' => 'site_visit_scheduled',
                'project_id' => (string) $this->alpha->id, // untouched selector, submits the lead's own project
                'remarks' => 'Coming Sunday.',
                'follow_up_type' => 'site_visit',
                'follow_up_at' => now()->addDays(3)->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->alpha->id, $lead->project_id, 'no switch happened');
        $this->assertSame($this->sam->id, $lead->assigned_to, 'Alpha\'s own salesperson, the ordinary handover');
        $this->assertRoutingHolds();

        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::ProjectSwitched)->count());
    }

    /** Same as above with the field omitted entirely — the modal's own default, but a bare API caller too. */
    public function test_completing_a_follow_up_with_no_project_field_at_all_still_hands_over_normally(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'fresh');
        $todo = $lead->pendingTodo;

        $this->actingAs($this->tia)
            ->post(route('todos.complete', $todo), [
                'stage' => 'site_visit_scheduled',
                'remarks' => 'Coming Sunday.',
                'follow_up_type' => 'site_visit',
                'follow_up_at' => now()->addDays(3)->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->alpha->id, $lead->project_id);
        $this->assertSame($this->sam->id, $lead->assigned_to);
        $this->assertRoutingHolds();
    }

    /** CompleteTodoRequest runs the same mobile+project clash check LeadSwitchProjectRequest does. */
    public function test_completing_a_follow_up_into_a_project_that_already_has_this_mobile_number_is_blocked(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'fresh');
        $todo = $lead->pendingTodo;

        Lead::create([
            'first_name' => 'Other',
            'last_name' => 'Lead',
            'mobile_number' => $lead->mobile_number,
            'project_id' => $this->pqr->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_to' => $this->pat->id,
            'assigned_role' => 'salesperson',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->tia)
            ->post(route('todos.complete', $todo), [
                'stage' => 'site_visit_scheduled',
                'project_id' => $this->pqr->id,
                'remarks' => 'Wants PQR.',
                'follow_up_type' => 'site_visit',
                'follow_up_at' => now()->addDays(2)->format('Y-m-d H:i'),
            ])
            ->assertSessionHasErrors('project_id');

        $lead->refresh();
        $this->assertSame($this->alpha->id, $lead->project_id, 'the switch did not happen');
        $this->assertSame('fresh', $lead->stage, 'nor did the stage change');
        $this->assertSame($todo->id, $lead->pendingTodo->id, 'the call was never completed');
    }

    /** The standalone action from LeadFollowUpService::switchProject() still works, unaffected by this addition. */
    public function test_the_standalone_switch_project_action_still_works_independently_of_the_follow_up_completion_form(): void
    {
        $lead = $this->add($this->admin, $this->alpha, 'in_discussion');

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->pqr->id])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->pqr->id, $lead->project_id);
        $this->assertSame('in_discussion', $lead->stage, 'unlike the completion form, a standalone switch never touches the stage');
        $this->assertSame($this->pat->id, $lead->assigned_to);
        $this->assertRoutingHolds();
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    private function timelineFor(Lead $lead): Collection
    {
        return collect(app(LeadTimeline::class)->for($lead));
    }

    private function assertRoutingHolds(): void
    {
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count(), 'an open lead with no pending to-do');

        $this->assertSame(0, Lead::withTrashed()
            ->join('users', 'users.id', '=', 'leads.assigned_to')
            ->whereColumn('leads.assigned_role', '!=', 'users.role')
            ->count(), 'assigned_role disagrees with the owner\'s role');
    }

    private function projectWith(string $name, User ...$salespeople): Project
    {
        $project = Project::create(['name' => $name]);
        $project->salespeople()->attach(array_map(fn (User $u) => $u->id, $salespeople));

        return $project;
    }

    private function add(User $creator, Project $project, string $stage): Lead
    {
        $mobile = (string) ++$this->phone;

        $this->actingAs($creator)
            ->post('/leads', [
                'first_name' => 'Meera',
                'last_name' => 'Sharma',
                'mobile_number' => $mobile,
                'project_id' => $project->id,
                'source' => 'walk_in',
                'stage' => $stage,
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        return Lead::where('mobile_number', $mobile)->firstOrFail();
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'Tester',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
