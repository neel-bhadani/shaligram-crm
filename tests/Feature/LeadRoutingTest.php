<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ChannelPartner;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\Project;
use App\Models\User;
use App\Services\IncomingLeadService;
use App\Services\LeadAssignmentService;
use App\Services\LeadFollowUpService;
use App\Support\CrmTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A new lead is routed by the stage it is saved at, never by who added it.
 *
 *   fresh, connected, not_connected → telecaller
 *   any other open stage            → salesperson (the creator, if they are one)
 *   a terminal stage                → whoever added it
 *
 * The same LeadAssignmentService answers for lead creation and for the handover,
 * the mapping is the admin's to change on the Stages screen, and a change that
 * puts a stage past the handover back on the telecaller desk is saved with a
 * warning rather than silently.
 *
 * Two things hold through every one of these:
 *
 *   Lead::open()->doesntHave('pendingTodo')->count() === 0
 *   leads.assigned_role === the owner's own users.role
 *
 * @see LeadAssignmentService
 */
class LeadRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tia;

    private User $sam;

    private User $sia;

    private Project $project;

    private ChannelPartner $partner;

    private int $phone = 9000000000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-11 11:00'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tia = $this->user('telecaller', 'Tia');
        // two salespeople, so "the round robin chose" and "the creator kept
        // it" cannot be the same person by accident
        $this->sam = $this->user('salesperson', 'Sam');
        $this->sia = $this->user('salesperson', 'Sia');

        // both on Alpha, so everything above the per-project section runs on
        // a project that is set up — the fallback has tests of its own
        $this->project = $this->projectWith('Alpha', $this->sam, $this->sia);
        $this->partner = ChannelPartner::create(['name' => 'Shreeji Realty', 'type' => 'firm', 'phone' => '9811111111']);
    }

    /* ================================================================
     | Creation
     ================================================================ */

    /** Every source, from each kind of creator, at the stage the form opens on. */
    public function test_every_source_is_routed_by_its_stage_whoever_adds_it(): void
    {
        $this->assertCount(8, CrmTaxonomy::sourceKeys());

        $expected = $actual = [];

        foreach (CrmTaxonomy::sourceKeys() as $source) {
            foreach (['admin' => $this->admin, 'salesperson' => $this->sia] as $who => $creator) {
                $lead = $this->add($creator, $source, 'fresh');

                $expected[] = [$source, $who, 'fresh', 'telecaller', 'Tia'];
                $actual[] = [$source, $who, $lead->stage, $lead->assigned_role, $lead->owner->first_name];
            }
        }

        $this->assertSame($expected, $actual);
        $this->assertRoutingHolds();
    }

    /**
     * The reported bug: a walk-in or broker lead an admin added after the site
     * visit landed on a telecaller, who has no reason to call somebody who has
     * already been to the site.
     */
    public function test_a_walk_in_or_broker_lead_added_after_the_site_visit_goes_to_a_salesperson(): void
    {
        $rows = [
            // an admin's goes to the next salesperson in the round robin...
            [$this->admin, 'walk_in', 'Sam'],
            [$this->admin, 'broker',  'Sia'],
            // ...and a salesperson who adds one is the salesperson who works it
            [$this->sia,   'walk_in', 'Sia'],
            [$this->sia,   'broker',  'Sia'],
        ];

        foreach ($rows as [$creator, $source, $owner]) {
            $lead = $this->add($creator, $source, 'site_visit_done');

            $this->assertSame('salesperson', $lead->assigned_role);
            $this->assertSame($owner, $lead->owner->first_name, "$source by {$creator->first_name}");
            $this->assertSame($lead->assigned_to, $lead->pendingTodo->assigned_to, 'the follow-up goes with it');
        }

        $this->assertRoutingHolds();
    }

    /** Every stage, pinned to the rule as written — not read back from the table. */
    public function test_every_stage_routes_to_the_desk_the_rule_names(): void
    {
        foreach (CrmTaxonomy::activeStageKeys() as $stage) {
            foreach ([$this->admin, $this->sia] as $creator) {
                $lead = $this->add($creator, 'walk_in', $stage);

                $expected = match (true) {
                    in_array($stage, ['fresh', 'connected', 'not_connected'], true) => $this->tia,
                    CrmTaxonomy::isTerminal($stage) => $creator,
                    $creator->role === 'salesperson' => $creator,
                    default => null,   // the round robin's pick
                };

                $label = "$stage by {$creator->first_name}";

                if ($expected) {
                    $this->assertSame($expected->id, $lead->assigned_to, $label);
                } else {
                    $this->assertSame('salesperson', $lead->owner->role, $label);
                }
            }
        }

        $this->assertRoutingHolds();
    }

    /* ================================================================
     | One resolver for creation and handover
     ================================================================ */

    /**
     * Creation and the handover take turns from the same round robin, because
     * they are the same service — a creation that kept its own counter would
     * hand the next site visit to whoever it had just given a lead to.
     */
    public function test_creation_and_the_handover_share_one_round_robin(): void
    {
        $this->add($this->admin, 'walk_in', 'site_visit_done');                    // Sam's turn
        $calling = $this->add($this->admin, 'facebook', 'fresh');                   // Tia's lead

        $this->completeTo($this->tia, $calling, 'site_visit_scheduled');            // Sia's turn

        $this->assertSame($this->sia->id, $calling->fresh()->assigned_to);
        $this->assertSame('salesperson', $calling->fresh()->assigned_role);

        $this->assertSame($this->sam->id, $this->add($this->admin, 'referral', 'in_discussion')->assigned_to, 'and round again');
        $this->assertRoutingHolds();
    }

    /**
     * The fallback lead of QA-REPORT MIN-13: no telecaller, so it stays on the
     * admin — labelled `admin`, not the telecaller desk nobody was at — and
     * the handover still moves it on, because it asks who owns the lead rather
     * than trusting the label.
     */
    public function test_an_admin_held_lead_is_still_handed_over_at_the_site_visit(): void
    {
        $this->tia->update(['is_active' => false]);

        $lead = $this->add($this->admin, 'walk_in', 'fresh');

        $this->assertSame($this->admin->id, $lead->assigned_to);
        $this->assertSame('admin', $lead->assigned_role);

        $this->completeTo($this->admin, $lead, 'site_visit_scheduled');

        $this->assertSame($this->sam->id, $lead->fresh()->assigned_to);
        $this->assertSame('salesperson', $lead->fresh()->assigned_role);
        $this->assertRoutingHolds();
    }

    /**
     * The third creation path. The integration's configured user is only the
     * holder: a fresh lead goes to the telecaller desk whoever was configured,
     * and falls back to them — labelled with their own role — when nobody is
     * on it.
     */
    public function test_an_imported_lead_is_routed_by_its_stage_too(): void
    {
        $integration = Integration::forProvider('facebook');
        $integration->mergeSettings(['default_project_id' => $this->project->id, 'assign_to_user_id' => $this->sam->id]);
        $integration->save();

        $import = fn (string $id) => app(IncomingLeadService::class)->import($integration, $id, [
            'first_name' => 'Meera', 'last_name' => 'Sharma',
            'mobile_number' => (string) ++$this->phone, 'email' => null,
        ], 'facebook')['lead'];

        $lead = $import('lead-1');
        $this->assertSame([$this->tia->id, 'telecaller'], [$lead->assigned_to, $lead->assigned_role]);

        $this->tia->update(['is_active' => false]);

        $lead = $import('lead-2');
        $this->assertSame([$this->sam->id, 'salesperson'], [$lead->assigned_to, $lead->assigned_role]);

        $this->assertRoutingHolds();
    }

    /**
     * The form names who a new lead goes to, per project; asking must not take
     * anybody's turn or raise an alert, even for a project with nobody on it.
     */
    public function test_the_form_preview_is_what_store_does_and_moves_no_round_robin(): void
    {
        $sol = $this->user('salesperson', 'Sol');
        $beta = $this->projectWith('Beta', $sol);
        $gamma = $this->projectWith('Gamma');
        $ids = [$this->project->id, $beta->id, $gamma->id];

        $preview = app(LeadAssignmentService::class)->preview($this->admin, $ids);

        $this->assertSame($preview, app(LeadAssignmentService::class)->preview($this->admin, $ids), 'asking twice gives the same answer');
        $this->assertSame($this->tia->id, $preview[$this->project->id]['fresh']);
        $this->assertSame($this->tia->id, $preview[$beta->id]['fresh'], 'telecallers are not per project');
        $this->assertSame($this->admin->id, $preview[$this->project->id]['lost']);
        $this->assertSame($this->sam->id, $preview[$this->project->id]['in_discussion']);
        $this->assertSame($sol->id, $preview[$beta->id]['in_discussion'], 'a salesperson is');
        $this->assertSame($this->sam->id, $preview[$gamma->id]['in_discussion'], 'the fallback, previewed');
        $this->assertSame(0, Alert::count(), 'a preview is not an assignment');

        $this->assertSame($preview[$this->project->id]['site_visit_done'], $this->add($this->admin, 'walk_in', 'site_visit_done')->assigned_to);
        $this->assertSame($preview[$beta->id]['site_visit_done'], $this->add($this->admin, 'walk_in', 'site_visit_done', $beta)->assigned_to);
    }

    /**
     * QA-REPORT-2 MIN-2: a role change used to leave every lead the person
     * held claiming the old role. The label follows the person — closed and
     * deleted leads too — and the leads themselves stay put.
     */
    public function test_changing_a_users_role_relabels_the_leads_they_hold(): void
    {
        $open = $this->add($this->sia, 'walk_in', 'site_visit_done');
        $booked = $this->add($this->sia, 'walk_in', 'booking_done');
        $deleted = $this->add($this->sia, 'walk_in', 'in_discussion');
        $deleted->delete();

        $this->sia->update(['role' => 'telecaller']);

        foreach ([$open, $booked, $deleted] as $lead) {
            $lead = Lead::withTrashed()->findOrFail($lead->id);

            $this->assertSame($this->sia->id, $lead->assigned_to);
            $this->assertSame('telecaller', $lead->assigned_role);
        }

        $this->assertRoutingHolds();
    }

    /** assign() takes no role of its own any more: an admin holding a lead is an admin. */
    public function test_assigning_a_lead_to_an_admin_labels_it_admin(): void
    {
        $lead = $this->add($this->admin, 'facebook', 'fresh');

        app(LeadFollowUpService::class)->assign($lead, $this->admin);

        $this->assertSame('admin', $lead->fresh()->assigned_role);
        $this->assertSame($this->admin->id, $lead->pendingTodo()->first()->assigned_to);
        $this->assertRoutingHolds();
    }

    /* ================================================================
     | Salespeople per project
     ================================================================ */

    /**
     * Sia is on both projects and Sol only on Beta. A single counter would
     * give Alpha's third lead back to Sam (Beta's turn having moved it past
     * Sia); a round robin across every salesperson would give Alpha's leads
     * to Sol. Each project takes its own turns among its own people.
     */
    public function test_the_round_robin_takes_turns_within_the_project_not_across_every_salesperson(): void
    {
        $sol = $this->user('salesperson', 'Sol');
        $beta = $this->projectWith('Beta', $this->sia, $sol);

        $turns = [];

        foreach ([$this->project, $beta, $this->project, $beta, $this->project, $beta] as $project) {
            $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $project);
            $turns[] = $project->name.' → '.$lead->owner->first_name;
        }

        $this->assertSame([
            'Alpha → Sam', 'Beta → Sia',
            'Alpha → Sia', 'Beta → Sol',
            'Alpha → Sam', 'Beta → Sia',
        ], $turns);

        $this->assertSame($this->sam->id, $this->project->fresh()->last_assigned_salesperson_id);
        $this->assertSame($this->sia->id, $beta->fresh()->last_assigned_salesperson_id);
        $this->assertSame(0, Alert::count(), 'both projects are set up');
        $this->assertRoutingHolds();
    }

    /** The handover goes through the same per-project turns as creation. */
    public function test_the_handover_takes_its_turn_from_the_leads_own_project(): void
    {
        $sol = $this->user('salesperson', 'Sol');
        $beta = $this->projectWith('Beta', $sol);

        $lead = $this->add($this->admin, 'facebook', 'fresh', $beta);
        $this->completeTo($this->tia, $lead, 'site_visit_scheduled');

        // across every salesperson this would have been Sam, the lowest id
        $this->assertSame($sol->id, $lead->fresh()->assigned_to);
        $this->assertSame($sol->id, $lead->fresh()->pendingTodo->assigned_to, 'the visit went with it');
        $this->assertNull($this->project->fresh()->last_assigned_salesperson_id, "Alpha's turn is untouched");
        $this->assertRoutingHolds();
    }

    /**
     * Fallback 2: nobody on the project, so every active salesperson takes
     * turns — the lead is still assigned — and every admin is told, once a
     * day per project rather than once per lead.
     */
    public function test_a_project_with_no_salespeople_still_assigns_and_alerts_every_admin(): void
    {
        $ben = $this->user('admin', 'Ben');
        $gamma = $this->projectWith('Gamma');

        $added = $this->add($this->admin, 'walk_in', 'site_visit_done', $gamma);
        $handed = $this->add($this->admin, 'facebook', 'fresh', $gamma);   // the telecaller's: no turn, no alert
        $this->completeTo($this->tia, $handed, 'site_visit_scheduled');

        $this->assertSame($this->sam->id, $added->assigned_to);
        $this->assertSame($this->sia->id, $handed->fresh()->assigned_to, 'the handover takes the same fallback');

        $alerts = Alert::where('type', "project_without_salespeople.{$gamma->id}")->get();

        $this->assertEqualsCanonicalizing([$this->admin->id, $ben->id], $alerts->pluck('user_id')->all(), 'one per admin, not one per lead');
        $this->assertSame(2, Alert::count(), 'and nothing else');

        $alert = $alerts->first();
        $this->assertSame('No salesperson is assigned to "Gamma"', $alert->title);
        $this->assertStringContainsString('went to the next of every active salesperson', $alert->body);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame(route('projects.show', $gamma), $alert->action_url);
        $this->assertNull($alert->lead_id, 'about the project, not the customer');

        $this->assertRoutingHolds();
    }

    /** Ticked but switched off is the same as not ticked. */
    public function test_a_project_whose_salespeople_are_all_switched_off_falls_back_too(): void
    {
        $sol = $this->user('salesperson', 'Sol');
        $beta = $this->projectWith('Beta', $sol);
        $sol->update(['is_active' => false]);

        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done', $beta);

        $this->assertSame($this->sam->id, $lead->assigned_to);
        $this->assertTrue(Alert::where('type', "project_without_salespeople.{$beta->id}")->exists());
        $this->assertRoutingHolds();
    }

    /**
     * Fallback 3: no active salesperson anywhere. The lead stays with whoever
     * added it — `todos.assigned_to` is NOT NULL — and the admins are still told.
     */
    public function test_with_no_salesperson_anywhere_the_lead_stays_with_its_creator(): void
    {
        $this->sam->update(['is_active' => false]);
        $this->sia->update(['is_active' => false]);

        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');

        $this->assertSame([$this->admin->id, 'admin'], [$lead->assigned_to, $lead->assigned_role]);
        $this->assertSame($this->admin->id, $lead->pendingTodo->assigned_to);
        $this->assertStringContainsString(
            'there is no active salesperson at all',
            Alert::where('type', "project_without_salespeople.{$this->project->id}")->value('body'),
        );
        $this->assertRoutingHolds();
    }

    /**
     * The project's place is read and written inside the caller's
     * transaction: a lead that fails to save gives its turn back. And it is on
     * the project row, not in the cache, so clearing the cache loses nothing.
     */
    public function test_a_turn_is_taken_inside_the_callers_transaction_and_kept_on_the_project(): void
    {
        $service = app(LeadAssignmentService::class);

        try {
            DB::transaction(function () use ($service) {
                $this->assertSame($this->sam->id, $service->ownerFor('site_visit_done', $this->admin, $this->project->id)->id);
                $this->assertSame($this->sam->id, $this->project->fresh()->last_assigned_salesperson_id);

                throw new \RuntimeException('the lead failed to save');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull($this->project->fresh()->last_assigned_salesperson_id, 'the turn went back with the lead');

        $this->assertSame($this->sam->id, $this->add($this->admin, 'walk_in', 'site_visit_done')->assigned_to);
        Cache::flush();
        $this->assertSame($this->sia->id, $this->add($this->admin, 'walk_in', 'site_visit_done')->assigned_to);
    }

    /* ================================================================
     | The mapping, as the admin edits it
     ================================================================ */

    public function test_the_mapping_is_seeded_from_the_rule(): void
    {
        $this->assertSame([
            'fresh' => 'telecaller',
            'connected' => 'telecaller',
            'not_connected' => 'telecaller',
            'details_shared' => 'salesperson',
            'site_visit_scheduled' => 'salesperson',
            'site_visit_done' => 'salesperson',
            'in_discussion' => 'salesperson',
            'booking_done' => null,
            'lost' => null,
        ], LeadStage::ordered()->pluck('owner_role', 'key')->all());
    }

    public function test_an_early_stage_can_be_moved_to_the_telecaller_desk_without_a_warning(): void
    {
        $this->routeStage('connected', 'telecaller')->assertSessionMissing('warning');

        $this->assertSame($this->tia->id, $this->add($this->admin, 'walk_in', 'connected')->assigned_to);
    }

    /** Allowed, never silent: the admin is told what the save means. */
    public function test_routing_a_stage_past_the_handover_to_a_telecaller_saves_with_a_warning(): void
    {
        $this->routeStage('site_visit_done', 'telecaller')->assertSessionHas('warning');

        $warning = session('warning');

        $this->assertStringContainsString('"Site visit done" will now go to a telecaller', $warning);
        $this->assertStringContainsString('already past the calling stage', $warning);
        $this->assertStringNotContainsString('handed to a salesperson', $warning, 'only the handover stage stops handovers');

        // saved, and acted on
        $this->assertSame($this->tia->id, $this->add($this->admin, 'walk_in', 'site_visit_done')->assigned_to);

        // saving it again is not news
        $this->routeStage('site_visit_done', 'telecaller')->assertSessionMissing('warning');
    }

    public function test_putting_the_handover_stage_on_the_telecaller_desk_says_the_handover_stops(): void
    {
        $this->routeStage('site_visit_scheduled', 'telecaller')->assertSessionHas('warning');

        $this->assertStringContainsString('will also stay with their telecaller', session('warning'));

        // and it does
        $lead = $this->add($this->admin, 'walk_in', 'fresh');
        $this->completeTo($this->tia, $lead, 'site_visit_scheduled');

        $this->assertSame($this->tia->id, $lead->fresh()->assigned_to);
        $this->assertRoutingHolds();
    }

    /** Dragging a telecaller stage past the handover is the same change by another route. */
    public function test_reordering_a_telecaller_stage_past_the_handover_warns(): void
    {
        $order = LeadStage::ordered()->pluck('id', 'key');
        $moved = $order->pull('not_connected');
        $order = $order->values()->all();
        array_splice($order, 4, 0, [$moved]);   // just after Site visit scheduled

        $this->actingAs($this->admin)
            ->post('/pipeline/stages/order', ['order' => $order])
            ->assertSessionHas('warning');

        $this->assertStringContainsString('"Not connected"', session('warning'));
    }

    /**
     * An open stage always has a desk and a terminal one never does, whatever
     * the request said. A new stage lands at the end, past the handover, so
     * putting it on the telecaller desk is warned about like any other.
     */
    public function test_a_new_stage_gets_a_desk_only_if_it_is_open(): void
    {
        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => 'Offer sent', 'color' => '#334155'])
            ->assertSessionMissing('warning');

        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => 'Cancelled', 'color' => '#334155', 'is_terminal' => true, 'owner_role' => 'telecaller'])
            ->assertSessionMissing('warning');

        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => 'Revisit', 'color' => '#334155', 'owner_role' => 'telecaller'])
            ->assertSessionHas('warning');

        $this->assertSame('salesperson', LeadStage::where('key', 'offer_sent')->value('owner_role'));
        $this->assertNull(LeadStage::where('key', 'cancelled')->value('owner_role'));
        $this->assertSame('telecaller', LeadStage::where('key', 'revisit')->value('owner_role'));
    }

    public function test_an_admin_role_is_not_a_desk(): void
    {
        $stage = LeadStage::where('key', 'connected')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/pipeline/stages/{$stage->id}", ['label' => $stage->label, 'color' => $stage->color, 'owner_role' => 'admin'])
            ->assertSessionHasErrors('owner_role');

        $this->assertSame('telecaller', $stage->fresh()->owner_role);
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    /** Both invariants, checked against every lead there is. */
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
                'project_id' => ($project ?? $this->project)->id,
                'source' => $source,
                'channel_partner_id' => $source === 'broker' ? $this->partner->id : null,
                'stage' => $stage,
                'reason' => $stage === 'lost' ? 'budget' : null,
                'booked_unit' => $stage === 'booking_done' ? 'A-402' : null,
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

    private function routeStage(string $key, string $role)
    {
        $stage = LeadStage::where('key', $key)->firstOrFail();

        return $this->actingAs($this->admin)->put("/pipeline/stages/{$stage->id}", [
            'label' => $stage->label,
            'color' => $stage->color,
            'is_terminal' => $stage->is_terminal,
            'owner_role' => $role,
        ])->assertSessionHasNoErrors();
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
