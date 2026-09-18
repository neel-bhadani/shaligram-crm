<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\Automation\LoopGuard;
use App\Services\Automation\RuleEngine;
use App\Services\LeadFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The rule engine: what fires, what does not, and what stops it.
 *
 * Four things are being protected here, and they are the four that would each
 * be quietly catastrophic on their own:
 *
 *   THE INVARIANT   Lead::open()->doesntHave('pendingTodo')->count() === 0,
 *                   after every trigger type. Automation writes stages and
 *                   follow-ups, and if it wrote them itself instead of through
 *                   LeadFollowUpService the To-do page would start losing leads
 *                   and nothing would report it.
 *
 *   LOOP PROTECTION Two rules that undo each other must stop. Not slow down,
 *                   not usually stop — stop, at a countable number of touches,
 *                   with a line in the log saying so.
 *
 *   THE SYSTEM ACTOR  A rule's work is not attributed to the admin who wrote
 *                   the rule. `created_by` is null on everything automation
 *                   writes.
 *
 *   THE TEST BUTTON It reports and does not fire. An admin pressing Test on a
 *                   switched-off rule must not discover afterwards that it ran.
 *
 * @see RuleEngine
 * @see LoopGuard
 */
class AutomationEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tele;

    private User $sales;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->sales = $this->user('salesperson', 'Sam');

        $this->project = Project::create(['name' => 'Skyline Residency']);
        // Sam handles it, so a handover here is a plain one and raises no
        // "no salesperson assigned" alert of its own
        $this->project->salespeople()->attach($this->sales);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ================= the invariant, per trigger ================= */

    public function test_a_lead_created_rule_runs_and_leaves_the_invariant_intact(): void
    {
        $this->rule([
            'trigger' => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'facebook']],
            'actions' => [
                ['type' => 'assign_round_robin', 'role' => 'telecaller'],
                ['type' => 'create_follow_up', 'hours' => 1, 'todo_type' => 'call', 'remarks' => 'First call'],
            ],
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post('/leads', $this->leadPayload(['source' => 'facebook']))
            ->assertSessionHasNoErrors();

        $lead = Lead::firstOrFail();

        $this->assertSame($this->tele->id, $lead->assigned_to, 'round-robin gave it to the telecaller');
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        $this->assertSame(1, Todo::where('lead_id', $lead->id)->where('status', 'pending')->count(),
            'still exactly one pending follow-up');

        $todo = Todo::where('lead_id', $lead->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame('2026-09-08 12:00', $todo->scheduled_at->format('Y-m-d H:i'), 'in 1 hour');
        $this->assertNull($todo->created_by, 'written by automation, not by the admin who wrote the rule');
        $this->assertSame($this->tele->id, $todo->assigned_to, 'the task went with the lead');
    }

    /**
     * A rule sharing leads among salespeople takes the project's own turns,
     * not a counter across every salesperson. Sol handles another project and
     * sits between Sam and Sia by id, so a global round robin would reach him.
     */
    public function test_a_salesperson_round_robin_rule_takes_turns_within_the_leads_project(): void
    {
        $sol = $this->user('salesperson', 'Sol');
        $sia = $this->user('salesperson', 'Sia');
        $this->project->salespeople()->attach($sia);

        $this->rule([
            'trigger' => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'facebook']],
            'actions' => [['type' => 'assign_round_robin', 'role' => 'salesperson']],
            'is_active' => true,
        ]);

        $owners = [];

        foreach (['9876543201', '9876543202', '9876543203'] as $mobile) {
            $this->actingAs($this->admin)
                ->post('/leads', $this->leadPayload(['source' => 'facebook', 'mobile_number' => $mobile]))
                ->assertSessionHasNoErrors();

            $owners[] = Lead::where('mobile_number', $mobile)->firstOrFail()->owner->first_name;
        }

        $this->assertSame(['Sam', 'Sia', 'Sam'], $owners);
        $this->assertSame(0, Lead::where('assigned_to', $sol->id)->count());
        $this->assertSame($this->sales->id, $this->project->fresh()->last_assigned_salesperson_id);
        $this->assertSame(0, Alert::count(), 'the project is set up');
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_stage_changed_rule_runs_only_for_its_own_stage(): void
    {
        $this->rule([
            'trigger' => 'stage_changed',
            'trigger_config' => ['stage' => 'site_visit_done'],
            'actions' => [['type' => 'create_follow_up', 'hours' => 24, 'type' => 'call']],
            'is_active' => true,
        ]);

        $lead = $this->lead(['stage' => 'connected', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead);

        $service = app(LeadFollowUpService::class);

        // a stage the rule does not watch
        $this->actingAs($this->admin);
        $service->changeStage($lead->fresh(), 'details_shared');
        $this->assertSame(0, AutomationLog::where('action', 'rule_fired')->count(), 'wrong stage, no firing');

        // and now the one it does
        $service->changeStage($lead->fresh(), 'site_visit_done');
        $this->assertSame(1, AutomationLog::where('action', 'rule_fired')->count());

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        $this->assertSame(1, Todo::where('lead_id', $lead->id)->where('status', 'pending')->count());
    }

    public function test_a_lead_assigned_rule_runs_on_the_handover(): void
    {
        $this->rule([
            'trigger' => 'lead_assigned',
            'actions' => [[
                'type' => 'raise_alert',
                'recipient' => 'lead_owner',
                'severity' => 'info',
                'title' => '{lead_name} is now yours',
                'body' => 'Booked in for a site visit at {project}.',
            ]],
            'is_active' => true,
        ]);

        $lead = $this->lead(['stage' => 'fresh', 'assigned_to' => $this->tele->id, 'assigned_role' => 'telecaller']);
        $todo = $this->todoFor($lead);

        // scheduling a site visit hands the lead from telecaller to salesperson
        $this->actingAs($this->tele);
        app(LeadFollowUpService::class)->complete(
            $todo, 'site_visit_scheduled', 'Visit booked.', Carbon::parse('2026-09-10 11:00'), 'site_visit'
        );

        $alert = Alert::firstOrFail();
        $this->assertSame($this->sales->id, $alert->user_id, 'the new owner was told');
        $this->assertStringContainsString('Rahul Mehta', $alert->title, 'placeholders were filled in');
        $this->assertStringContainsString('Skyline Residency', $alert->body);

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_time_triggered_rule_runs_from_the_hourly_command(): void
    {
        $this->rule([
            'trigger' => 'follow_up_overdue',
            'trigger_config' => ['days' => 3],
            'actions' => [[
                'type' => 'raise_alert', 'recipient' => 'lead_owner', 'severity' => 'warning',
                'title' => 'Chase {first_name}',
            ]],
            'is_active' => true,
        ]);

        $lead = $this->lead(['stage' => 'connected', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead, Carbon::parse('2026-09-01 10:00'));   // a week late

        $this->artisan('automation:run')->assertSuccessful();

        $this->assertDatabaseHas('alerts', ['user_id' => $this->tele->id, 'title' => 'Chase Rahul']);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_stage_idle_rule_runs_from_the_hourly_command(): void
    {
        $this->rule([
            'trigger' => 'stage_idle',
            'trigger_config' => ['stage' => 'in_discussion', 'days' => 7],
            'actions' => [[
                'type' => 'raise_alert', 'recipient' => 'admins', 'severity' => 'warning',
                'title' => '{lead_name} is stuck in discussion',
            ]],
            'is_active' => true,
        ]);

        $lead = $this->lead([
            'stage' => 'in_discussion',
            'assigned_to' => $this->sales->id,
            'stage_changed_at' => Carbon::parse('2026-08-20 10:00'),
        ]);
        $this->todoFor($lead);

        $this->artisan('automation:run')->assertSuccessful();

        $this->assertDatabaseHas('alerts', [
            'user_id' => $this->admin->id,
            'title' => 'Rahul Mehta is stuck in discussion',
        ]);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ================= loop protection ================= */

    /**
     * The pair everybody eventually writes: two rules that undo each other.
     *
     * What stops it here is the per-rule cooldown rather than the chain cap —
     * A fires, B fires, and A's second turn comes round within the hour, so it
     * is refused before the third touch. Both guards are in play and the test
     * asserts the outcome rather than which one got there first: the chain
     * stops, inside the cap, with the suppression logged and the admins told.
     *
     * The chain cap has its own test below, on a three-rule cycle, where the
     * cooldown cannot be the thing that stops it.
     */
    public function test_two_rules_that_ping_pong_a_stage_are_stopped_and_logged(): void
    {
        $this->rule([
            'name' => 'Bounce to not connected',
            'trigger' => 'stage_changed',
            'trigger_config' => ['stage' => 'connected'],
            'actions' => [['type' => 'change_stage', 'stage' => 'not_connected']],
            'is_active' => true,
        ]);

        $this->rule([
            'name' => 'Bounce back to connected',
            'trigger' => 'stage_changed',
            'trigger_config' => ['stage' => 'not_connected'],
            'actions' => [['type' => 'change_stage', 'stage' => 'connected']],
            'is_active' => true,
        ]);

        $lead = $this->lead(['stage' => 'fresh', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->changeStage($lead->fresh(), 'connected');

        $fired = AutomationLog::where('action', 'rule_fired')->count();

        $this->assertGreaterThan(0, $fired, 'the rules did run');
        $this->assertLessThanOrEqual(
            (int) config('automation.loop_protection.max_touches_per_chain'),
            $fired,
            'the chain stopped rather than running away'
        );

        $suppressed = AutomationLog::where('action', 'suppressed')->get();
        $this->assertTrue($suppressed->isNotEmpty(), 'the suppression was logged');
        $this->assertContains($suppressed->first()->result, ['loop_guard', 'cooldown']);

        $this->assertDatabaseHas('alerts', [
            'user_id' => $this->admin->id,
            'type' => 'automation_suppressed',
            'severity' => 'warning',
        ]);

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        $this->assertSame(1, Todo::where('lead_id', $lead->id)->where('status', 'pending')->count());
    }

    /**
     * Three rules in a ring, so each one's turn comes round only once and the
     * one-hour cooldown never gets a chance to be the thing that stops it.
     *
     * This is the chain cap on its own: three touches of one lead, then the
     * fourth is refused with `loop_guard` however innocent the rule looks.
     */
    public function test_the_chain_cap_stops_a_three_rule_cycle_at_three_touches(): void
    {
        $ring = [
            ['connected',      'details_shared'],
            ['details_shared', 'not_connected'],
            ['not_connected',  'connected'],
        ];

        foreach ($ring as [$from, $to]) {
            $this->rule([
                'name' => "Ring $from to $to",
                'trigger' => 'stage_changed',
                'trigger_config' => ['stage' => $from],
                'actions' => [['type' => 'change_stage', 'stage' => $to]],
                'is_active' => true,
            ]);
        }

        $lead = $this->lead(['stage' => 'fresh', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->changeStage($lead->fresh(), 'connected');

        $this->assertSame(
            (int) config('automation.loop_protection.max_touches_per_chain'),
            AutomationLog::where('action', 'rule_fired')->count(),
            'exactly three touches, then it stopped'
        );

        $this->assertSame(1, AutomationLog::where('result', 'loop_guard')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        $this->assertSame(1, Todo::where('lead_id', $lead->id)->where('status', 'pending')->count());
    }

    public function test_one_rule_does_not_fire_twice_on_the_same_lead_within_the_hour(): void
    {
        $this->rule([
            'trigger' => 'stage_changed',
            'trigger_config' => ['stage' => 'connected'],
            'actions' => [[
                'type' => 'raise_alert', 'recipient' => 'admins', 'severity' => 'info', 'title' => 'Moved',
            ]],
            'is_active' => true,
        ]);

        $lead = $this->lead(['stage' => 'fresh', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead);

        $service = app(LeadFollowUpService::class);
        $this->actingAs($this->admin);

        $service->changeStage($lead->fresh(), 'connected');
        $service->changeStage($lead->fresh(), 'details_shared');
        $service->changeStage($lead->fresh(), 'connected');    // back again, ten seconds later

        $this->assertSame(1, AutomationLog::where('action', 'rule_fired')->count());
        $this->assertSame(1, AutomationLog::where('result', 'cooldown')->count());
    }

    /* ================= the test button ================= */

    public function test_the_test_button_reports_matches_without_firing_anything(): void
    {
        $rule = $this->rule([
            'trigger' => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'facebook']],
            'actions' => [['type' => 'change_stage', 'stage' => 'lost']],
            'is_active' => true,
        ]);

        foreach (['facebook', 'facebook', 'walk_in'] as $i => $source) {
            $lead = $this->lead([
                'source' => $source,
                'mobile_number' => '98765432'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'assigned_to' => $this->tele->id,
            ]);
            $this->todoFor($lead);
        }

        $before = Lead::pluck('stage', 'id')->all();

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.rules.match'), [
                'trigger' => $rule->trigger,
                'conditions' => $rule->conditionList(),
            ])
            ->assertOk();

        $this->assertSame(2, $response->json('count'));
        $this->assertCount(2, $response->json('sample'));

        $this->assertSame($before, Lead::pluck('stage', 'id')->all(), 'nothing moved');
        $this->assertSame(0, AutomationLog::count(), 'nothing was logged, because nothing ran');
        $this->assertSame(0, Alert::count());
    }

    public function test_the_test_button_is_closed_to_non_admins(): void
    {
        $this->actingAs($this->tele)
            ->postJson(route('automation.rules.match'), ['trigger' => 'lead_created'])
            ->assertForbidden();

        $this->actingAs($this->sales)->get('/automation')->assertForbidden();
        $this->actingAs($this->tele)->get('/automation/guide')->assertForbidden();
        $this->actingAs($this->admin)->get('/automation')->assertOk();
    }

    /* ================= alerts ================= */

    public function test_the_same_alert_is_not_raised_twice_for_a_lead_in_24_hours(): void
    {
        $lead = $this->lead(['stage' => 'connected', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead, Carbon::parse('2026-09-01 10:00'));

        $this->artisan('automation:run');
        $this->assertSame(1, Alert::where('type', 'follow_up_overdue')->count());

        // the command runs again an hour later, as it does all day
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00', 'Asia/Kolkata'));
        $this->artisan('automation:run');
        Carbon::setTestNow(Carbon::parse('2026-09-08 22:00', 'Asia/Kolkata'));
        $this->artisan('automation:run');

        $this->assertSame(1, Alert::where('type', 'follow_up_overdue')->count(),
            'still one alert, not fourteen');

        // and the next day it may speak again
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00', 'Asia/Kolkata'));
        $this->artisan('automation:run');

        $this->assertSame(2, Alert::where('type', 'follow_up_overdue')->count());
    }

    public function test_nobody_is_alerted_about_a_lead_they_cannot_see(): void
    {
        $this->rule([
            'trigger' => 'stage_changed',
            'trigger_config' => ['stage' => 'not_connected'],
            'actions' => [[
                'type' => 'raise_alert', 'recipient' => 'role', 'recipient_role' => 'telecaller',
                'severity' => 'info', 'title' => 'Look at {lead_name}',
            ]],
            'is_active' => true,
        ]);

        $other = $this->user('telecaller', 'Nina');   // cannot see other people's leads

        // 'connected' to 'not_connected' stays on the telecaller desk, so the
        // lead is still Tara's to see when the alert fires
        $lead = $this->lead(['stage' => 'connected', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->changeStage($lead->fresh(), 'not_connected');

        $this->assertSame(1, Alert::where('title', 'Look at Rahul Mehta')->count());
        $this->assertDatabaseHas('alerts', ['user_id' => $this->tele->id, 'title' => 'Look at Rahul Mehta']);
        $this->assertDatabaseMissing('alerts', ['user_id' => $other->id]);
    }

    public function test_reading_an_alert_changes_nothing_about_the_lead(): void
    {
        $lead = $this->lead(['stage' => 'connected', 'assigned_to' => $this->tele->id]);
        $todo = $this->todoFor($lead, Carbon::parse('2026-09-01 10:00'));

        $this->artisan('automation:run');

        $alert = Alert::firstOrFail();
        $leadBefore = $lead->fresh()->only(['stage', 'stage_changed_at', 'assigned_to', 'last_activity_at']);
        $todoBefore = $todo->fresh()->only(['status', 'scheduled_at', 'completed_at', 'outcome_stage']);

        $this->actingAs($this->tele)->post(route('alerts.read', $alert))->assertRedirect();

        $this->assertNotNull($alert->fresh()->read_at, 'the alert is read');
        $this->assertEquals($leadBefore, $lead->fresh()->only(array_keys($leadBefore)), 'the lead is untouched');
        $this->assertEquals($todoBefore, $todo->fresh()->only(array_keys($todoBefore)), 'the follow-up is untouched');
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_user_cannot_read_somebody_elses_alert(): void
    {
        $lead = $this->lead(['stage' => 'connected', 'assigned_to' => $this->tele->id]);
        $this->todoFor($lead, Carbon::parse('2026-09-01 10:00'));
        $this->artisan('automation:run');

        $this->actingAs($this->sales)
            ->post(route('alerts.read', Alert::firstOrFail()))
            ->assertForbidden();
    }

    public function test_the_alerts_page_is_open_to_everybody(): void
    {
        $this->actingAs($this->tele)->get(route('alerts.index'))->assertOk();
        $this->actingAs($this->sales)->get(route('alerts.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('alerts.index'))->assertOk();
    }

    /* ================= helpers ================= */

    private function rule(array $attrs): AutomationRule
    {
        return AutomationRule::create($attrs + [
            'name' => 'Test rule '.AutomationRule::count(),
            'trigger' => 'lead_created',
            'conditions' => [],
            'actions' => [],
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'first_name' => 'Rahul',
            'last_name' => 'Mehta',
            'mobile_number' => '9876543210',
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'assigned_role' => 'telecaller',
            'stage_changed_at' => now(),
            'created_by' => $this->admin->id,
        ]);
    }

    private function todoFor(Lead $lead, ?Carbon $when = null): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $this->admin->id,
            'scheduled_at' => $when ?? now()->addDay(),
            'type' => 'call',
            'status' => 'pending',
        ]);
    }

    private function leadPayload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Rahul',
            'last_name' => 'Mehta',
            'mobile_number' => '9876543210',
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'follow_up_at' => '2026-09-12 11:00',
            'follow_up_type' => 'call',
        ];
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
