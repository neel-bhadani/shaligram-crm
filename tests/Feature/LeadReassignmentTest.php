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
 * Handover moves a lead automatically when a stage change crosses the
 * telecaller/salesperson line, in either direction — see
 * LeadFollowUpService::schedule(). Reassignment moves it by hand, to a named
 * person, without necessarily touching its stage — see
 * LeadFollowUpService::assign(). Both keep the same two invariants
 * LeadRoutingTest checks after every automatic assignment:
 *
 *   Lead::open()->doesntHave('pendingTodo')->count() === 0
 *   leads.assigned_role === the owner's own users.role
 *
 * and a salesperson is further bound to their own project(s) — for viewing,
 * editing and reassigning a lead they hold, and for who they can reassign one
 * to — everywhere except an admin, who is exempt. See LeadPolicy::view() and
 * LeadReassignRequest.
 */
class LeadReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tia;

    private User $sam;

    private User $sia;

    private Project $alpha;

    private int $phone = 9100000000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-18 11:00'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tia = $this->user('telecaller', 'Tia');
        $this->sam = $this->user('salesperson', 'Sam');
        $this->sia = $this->user('salesperson', 'Sia');
        $this->alpha = $this->projectWith('Alpha', $this->sam, $this->sia);
    }

    /* ================================================================
     | Automatic handover, both directions
     ================================================================ */

    /** Forward handover — telecaller to salesperson — is unchanged. */
    public function test_forward_handover_still_moves_a_telecaller_lead_to_a_salesperson(): void
    {
        $lead = $this->add($this->admin, 'facebook', 'fresh');
        $this->assertSame($this->tia->id, $lead->assigned_to);

        $this->completeTo($this->tia, $lead, 'site_visit_scheduled');

        $this->assertSame($this->sam->id, $lead->fresh()->assigned_to);
        $this->assertSame('salesperson', $lead->fresh()->assigned_role);
        $this->assertRoutingHolds();
    }

    /** Backward handover — salesperson to telecaller — is new. */
    public function test_backward_handover_moves_a_salesperson_lead_to_the_telecaller_when_its_stage_resets(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');
        $this->assertSame($this->sam->id, $lead->assigned_to, 'the round robin\'s first turn');

        $this->actingAs($this->sam)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'fresh',
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ]))
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->tia->id, $lead->assigned_to, 'the only telecaller — a single, company-wide desk');
        $this->assertSame('telecaller', $lead->assigned_role);
        $this->assertSame($this->tia->id, $lead->pendingTodo->assigned_to);

        $this->assertSame($this->sam->id, $this->alpha->fresh()->last_assigned_salesperson_id, 'telecallers take no turn from the salesperson round robin');
        $this->assertRoutingHolds();

        $handedOver = $this->timelineFor($lead)->firstWhere('title', 'Stage changed');
        $this->assertNotNull($handedOver);
        $this->assertSame('Handed over', $handedOver['details'][0]['label']);
        $this->assertSame('Sam Tester → Tia Tester', $handedOver['details'][0]['value']);
    }

    /* ================================================================
     | Manual reassignment
     ================================================================ */

    public function test_a_salesperson_can_manually_reassign_their_own_lead_to_a_teammate_on_the_same_project(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');
        $this->assertSame($this->sam->id, $lead->assigned_to);
        $todoId = $lead->pendingTodo->id;

        $this->actingAs($this->sam)
            ->put(route('leads.reassign', $lead), ['assigned_to' => $this->sia->id])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->sia->id, $lead->assigned_to);
        $this->assertSame('salesperson', $lead->assigned_role);
        $this->assertSame('site_visit_done', $lead->stage, 'the stage is untouched by a manual move');

        $this->assertSame(1, Todo::where('status', 'pending')->count(), 'moved, not duplicated');
        $this->assertSame($todoId, $lead->pendingTodo->id);
        $this->assertSame($this->sia->id, $lead->pendingTodo->assigned_to);

        $this->assertSame($this->sam->id, $this->alpha->fresh()->last_assigned_salesperson_id, 'a manual move takes no turn');
        $this->assertRoutingHolds();

        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::Reassigned)->count());
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::HandedOver)->count());

        $entry = $this->timelineFor($lead)->firstWhere('title', 'Manually reassigned');
        $this->assertNotNull($entry, 'a manual reassignment is its own top-level entry, not nested under a stage change');
        $this->assertSame(['field' => 'Owner', 'from' => 'Sam Tester', 'to' => 'Sia Tester'], $entry['change']);
    }

    /**
     * `edit_leads` is false for a telecaller by default — see
     * config('crm.permission_defaults'). LeadPolicy::reassign() gates on
     * being able to see the lead, not on that permission, so a telecaller is
     * not shut out of the one action every role has to have.
     */
    public function test_a_telecaller_can_manually_reassign_a_lead_assigned_to_them_despite_lacking_edit_leads(): void
    {
        $tom = $this->user('telecaller', 'Tom');

        $lead = $this->add($this->admin, 'facebook', 'fresh');
        $this->assertSame($this->tia->id, $lead->assigned_to, 'the only active telecaller at the time it was added');
        $this->assertFalse($this->tia->can_('edit_leads'), 'the default this fix has to work around');

        $this->actingAs($this->tia)
            ->put(route('leads.reassign', $lead), ['assigned_to' => $tom->id])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($tom->id, $lead->assigned_to);
        $this->assertSame('telecaller', $lead->assigned_role);
        $this->assertRoutingHolds();
    }

    /** The same ownership boundary LeadPolicy::view() already enforces everywhere else. */
    public function test_a_telecaller_is_rejected_server_side_from_a_lead_assigned_to_somebody_else(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');   // Sam's, not Tia's

        $this->actingAs($this->tia)->get(route('leads.show', $lead))->assertForbidden();

        $this->actingAs($this->tia)
            ->put(route('leads.reassign', $lead), ['assigned_to' => $this->sia->id])
            ->assertForbidden();
    }

    /* ================================================================
     | Stage and person together
     ================================================================ */

    /**
     * The reassign form lets a stage and a person be picked in the same
     * action — see LeadFollowUpService::reassignTo(). The person is the one
     * asked for, never the round robin's answer, even though the stage
     * crosses from a telecaller stage to a salesperson one.
     */
    public function test_reassigning_can_move_the_stage_and_the_person_together_in_one_action(): void
    {
        $lead = $this->add($this->admin, 'facebook', 'fresh');   // Tia, telecaller
        $todoId = $lead->pendingTodo->id;

        $this->actingAs($this->admin)
            ->put(route('leads.reassign', $lead), [
                'assigned_to' => $this->sia->id,
                'stage' => 'site_visit_scheduled',
            ])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('site_visit_scheduled', $lead->stage);
        $this->assertSame($this->sia->id, $lead->assigned_to);
        $this->assertSame('salesperson', $lead->assigned_role);

        $this->assertSame(1, Todo::where('status', 'pending')->count(), 'moved, not duplicated');
        $this->assertSame($todoId, $lead->pendingTodo->id);
        $this->assertSame($this->sia->id, $lead->pendingTodo->assigned_to);

        $this->assertRoutingHolds();
        $this->assertNull($this->alpha->fresh()->last_assigned_salesperson_id, 'the person was picked, not resolved by the round robin');

        // one entry for the stage, one for the owner — neither mislabelled as a handover
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::HandedOver)->count());
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::Reassigned)->count());
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::StageChanged)->count());

        $timeline = $this->timelineFor($lead);

        $stageEntry = $timeline->firstWhere('title', 'Stage changed');
        $this->assertNotNull($stageEntry);
        $this->assertSame('fresh', $stageEntry['from_stage']);
        $this->assertSame('site_visit_scheduled', $stageEntry['to_stage']);

        $ownerEntry = $timeline->firstWhere('title', 'Manually reassigned');
        $this->assertNotNull($ownerEntry);
        $this->assertSame(['field' => 'Owner', 'from' => 'Tia Tester', 'to' => 'Sia Tester'], $ownerEntry['change']);
    }

    /**
     * The stage field on this form is validated the same way every other
     * stage field in the application is — see ValidatesTaxonomy — an active
     * key, or the value already on the record. Not a bypass, and not an
     * adjacency rule that does not exist anywhere else in the codebase either.
     */
    public function test_reassign_rejects_a_stage_key_that_does_not_exist(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');

        $this->actingAs($this->admin)
            ->put(route('leads.reassign', $lead), [
                'assigned_to' => $this->sia->id,
                'stage' => 'not_a_real_stage',
            ])
            ->assertSessionHasErrors('stage');

        $this->assertSame('site_visit_done', $lead->fresh()->stage);
    }

    /* ================================================================
     | Project boundary
     ================================================================ */

    /**
     * A salesperson can only end up owning a lead outside their project(s)
     * through the "no salesperson staffed" fallback — see
     * LeadAssignmentService. Until it is reassigned, it is not theirs to see.
     */
    public function test_a_non_admin_is_rejected_server_side_from_a_lead_the_fallback_gave_them_outside_their_project(): void
    {
        $gamma = $this->projectWith('Gamma');   // nobody staffed — triggers the fallback

        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $gamma);
        $this->assertSame($this->sam->id, $lead->assigned_to, 'the fallback\'s first turn, company-wide');

        $this->actingAs($this->sam)->get(route('leads.show', $lead))->assertForbidden();

        $this->actingAs($this->sam)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, ['first_name' => 'Meerah']))
            ->assertForbidden();

        $this->actingAs($this->sam)
            ->put(route('leads.reassign', $lead), ['assigned_to' => $this->sia->id])
            ->assertForbidden();
    }

    public function test_a_non_admin_cannot_reassign_a_lead_to_someone_outside_its_project(): void
    {
        $bob = $this->user('salesperson', 'Bob');
        $this->projectWith('Beta', $bob);   // Bob is on Beta, not Alpha

        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');   // Alpha, owned by Sam

        $this->actingAs($this->sam)
            ->put(route('leads.reassign', $lead), ['assigned_to' => $bob->id])
            ->assertSessionHasErrors('assigned_to');

        $this->assertSame($this->sam->id, $lead->fresh()->assigned_to, 'the reassignment did not happen');
    }

    public function test_an_admin_is_exempt_from_the_project_boundary_both_ways(): void
    {
        $gamma = $this->projectWith('Gamma');
        $fallbackLead = $this->add($this->admin, 'walk_in', 'site_visit_done', $gamma);

        $this->actingAs($this->admin)->get(route('leads.show', $fallbackLead))->assertOk();

        $bob = $this->user('salesperson', 'Bob');
        $this->projectWith('Beta', $bob);
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');   // Alpha, owned by Sam

        $this->actingAs($this->admin)
            ->put(route('leads.reassign', $lead), ['assigned_to' => $bob->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($bob->id, $lead->fresh()->assigned_to, 'admin reassigns across projects freely');
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    private function timelineFor(Lead $lead): Collection
    {
        return collect(app(LeadTimeline::class)->for($lead));
    }

    /** Both invariants LeadRoutingTest checks after every automatic assignment. */
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

    private function add(User $creator, string $source, string $stage, ?Project $project = null): Lead
    {
        $mobile = (string) ++$this->phone;

        $this->actingAs($creator)
            ->post('/leads', [
                'first_name' => 'Meera',
                'last_name' => 'Sharma',
                'mobile_number' => $mobile,
                'project_id' => ($project ?? $this->alpha)->id,
                'source' => $source,
                'stage' => $stage,
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        return Lead::where('mobile_number', $mobile)->firstOrFail();
    }

    private function completeTo(User $by, Lead $lead, string $stage): void
    {
        $this->actingAs($by)
            ->post("/todos/{$lead->pendingTodo->id}/complete", [
                'stage' => $stage,
                'remarks' => 'Coming Sunday.',
                'follow_up_type' => 'site_visit',
                'follow_up_at' => now()->addDays(3)->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();
    }

    private function editPayload(Lead $lead, array $overrides = []): array
    {
        return array_merge([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'mobile_number' => $lead->mobile_number,
            'project_id' => $lead->project_id,
            'source' => $lead->source,
            'stage' => $lead->stage,
        ], $overrides);
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
