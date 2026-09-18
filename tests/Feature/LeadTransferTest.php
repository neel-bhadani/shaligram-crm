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
 * Transfer to another project — distinct from reassign(), which
 * LeadReassignmentTest covers. Reassign moves a lead to a different person
 * within its own project; transfer closes the lead out as lost on its
 * current project and opens a NEW lead for the same person on a target
 * project, forcing the same owner onto it regardless of whether they are
 * staffed there — see LeadFollowUpService::transferToProject().
 */
class LeadTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sam;

    private User $sia;

    private Project $alpha;

    private Project $beta;

    private int $phone = 9200000000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-18 11:00'));

        $this->admin = $this->user('admin', 'Ann');
        $this->user('telecaller', 'Tia');
        $this->sam = $this->user('salesperson', 'Sam');
        $this->sia = $this->user('salesperson', 'Sia');
        $this->alpha = $this->projectWith('Alpha', $this->sam, $this->sia);
        // Sam is deliberately not staffed here — the transfer must not care.
        $this->beta = $this->projectWith('Beta', $this->sia);
    }

    public function test_transferring_a_lead_closes_it_lost_here_and_opens_it_on_the_target_project_for_the_same_owner(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $this->alpha);
        $this->assertSame($this->sam->id, $lead->assigned_to);

        $this->actingAs($this->sam)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->beta->id,
                'note' => 'Wants Beta instead.',
            ])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('lost', $lead->stage);
        $this->assertSame('transferred_project', $lead->reason);
        $this->assertNull($lead->pendingTodo, 'a lost lead needs no follow-up');

        $newLead = Lead::where('project_id', $this->beta->id)
            ->where('mobile_number', $lead->mobile_number)
            ->firstOrFail();

        // Sam never needed to be staffed on Beta — this bypasses that rule on purpose.
        $this->assertSame($this->sam->id, $newLead->assigned_to);
        $this->assertSame('salesperson', $newLead->assigned_role);
        $this->assertSame('connected', $newLead->stage);
        $this->assertSame($lead->first_name, $newLead->first_name);
        $this->assertSame($lead->last_name, $newLead->last_name);

        $this->assertNotNull($newLead->pendingTodo, 'manual creation always needs a follow-up');
        $this->assertSame($this->sam->id, $newLead->pendingTodo->assigned_to);
        $this->assertSame(1, Todo::where('status', 'pending')->count());

        $this->assertRoutingHolds();

        // its own kind of history entry, not a reassignment and not a handover
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->where('action', LeadActivity::TransferredOut)->count());
        $this->assertSame(1, LeadActivity::where('lead_id', $newLead->id)->where('action', LeadActivity::TransferredIn)->count());
        $this->assertSame(0, LeadActivity::whereIn('lead_id', [$lead->id, $newLead->id])
            ->whereIn('action', [LeadActivity::Reassigned, LeadActivity::HandedOver])->count());

        $oldTimeline = $this->timelineFor($lead);
        $outEntry = $oldTimeline->firstWhere('title', 'Transferred to another project');
        $this->assertNotNull($outEntry);
        $this->assertStringContainsString('Transferred to Beta', $outEntry['remark']);

        $newTimeline = $this->timelineFor($newLead);
        $inEntry = $newTimeline->firstWhere('title', 'Transferred from another project');
        $this->assertNotNull($inEntry);
        $this->assertStringContainsString('Transferred from Alpha', $inEntry['remark']);
    }

    public function test_an_admin_can_transfer_a_lead_on_a_salespersons_behalf_and_the_owner_stays_the_same(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $this->alpha);

        $this->actingAs($this->admin)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->beta->id,
                'note' => 'Client asked about Beta.',
            ])
            ->assertSessionHasNoErrors();

        $newLead = Lead::where('project_id', $this->beta->id)
            ->where('mobile_number', $lead->mobile_number)
            ->firstOrFail();

        $this->assertSame($this->sam->id, $newLead->assigned_to, 'the original owner, not the admin performing the action');
    }

    public function test_an_unrelated_salesperson_is_rejected_server_side_from_transferring_someone_elses_lead(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $this->alpha);   // Sam's, not Sia's

        $this->actingAs($this->sia)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->beta->id,
                'note' => 'Wants Beta instead.',
            ])
            ->assertForbidden();

        $this->assertSame('site_visit_done', $lead->fresh()->stage, 'the transfer did not happen');
    }

    public function test_transfer_rejects_a_lead_that_is_already_closed(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $this->alpha);

        $this->actingAs($this->sam)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->beta->id,
                'note' => 'Booked already.',
            ])
            ->assertSessionHasNoErrors();

        // $lead is now lost — transferring the same lead again must be rejected
        $this->actingAs($this->sam)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->beta->id,
                'note' => 'Trying again.',
            ])
            ->assertSessionHasErrors('project_id');
    }

    public function test_transfer_rejects_the_leads_own_current_project_as_the_target(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $this->alpha);

        $this->actingAs($this->sam)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->alpha->id,
                'note' => 'Same project.',
            ])
            ->assertSessionHasErrors('project_id');
    }

    public function test_transfer_rejects_a_target_project_where_this_person_already_has_a_lead(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $this->alpha);
        // the same person already has a lead of their own on Beta
        $this->add($this->admin, 'walk_in', 'fresh', $this->beta, $lead->mobile_number);

        $this->actingAs($this->sam)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->beta->id,
                'note' => 'Wants Beta instead.',
            ])
            ->assertSessionHasErrors('project_id');
    }

    public function test_transfer_requires_a_note(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $this->alpha);

        $this->actingAs($this->sam)
            ->put(route('leads.transfer', $lead), [
                'project_id' => $this->beta->id,
                'note' => '',
            ])
            ->assertSessionHasErrors('note');
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

    private function add(User $creator, string $source, string $stage, Project $project, ?string $mobile = null): Lead
    {
        $mobile ??= (string) ++$this->phone;

        $this->actingAs($creator)
            ->post('/leads', [
                'first_name' => 'Meera',
                'last_name' => 'Sharma',
                'mobile_number' => $mobile,
                'project_id' => $project->id,
                'source' => $source,
                'stage' => $stage,
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        return Lead::where('mobile_number', $mobile)->where('project_id', $project->id)->firstOrFail();
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
