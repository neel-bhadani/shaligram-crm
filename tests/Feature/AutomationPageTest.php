<?php

namespace Tests\Feature;

use App\Http\Controllers\AutomationController;
use App\Http\Controllers\AutomationRuleController;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Automation page: the door, the guard rails, and the guidance.
 *
 * The guard rails are the point of this file. Every one of them is a promise
 * the page makes on screen, and every one of them would be a lie if it lived
 * only in Vue:
 *
 *   a new rule is inactive          however the form was posted
 *   editing a live rule leaves it   fixing a typo must not silence a rule
 *   deleting reports what it did    a rule that ran 400 times is history
 *
 * @see AutomationController
 * @see AutomationRuleController
 */
class AutomationPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tele;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Skyline Residency']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ================= the door ================= */

    public function test_the_page_is_admin_only_by_route_not_by_hidden_link(): void
    {
        $this->actingAs($this->tele)->get('/automation')->assertForbidden();
        $this->actingAs($this->tele)->get('/automation?tab=queue')->assertForbidden();
        $this->actingAs($this->tele)->get('/automation/guide')->assertForbidden();

        $this->actingAs($this->tele)
            ->post(route('automation.rules.store'), $this->rulePayload())
            ->assertForbidden();

        $this->assertSame(0, AutomationRule::count());
    }

    public function test_the_page_renders_every_tab_in_one_payload(): void
    {
        $this->actingAs($this->admin)->get('/automation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Automation/Index')
                ->where('tab', 'auto_send')
                ->has('autoSend', 9)
                ->has('rules')
                ->has('templates')
                ->has('providerTemplates')
                ->has('queue')
                ->has('activity')
                ->has('alerts')
                ->has('catalog.triggers')
                ->has('catalog.conditions')
                ->has('catalog.actions')
                ->has('catalog.options.stages')
                ->has('catalog.alert_recipients')
                ->has('placeholders')
                ->has('thresholds'));
    }

    public function test_the_old_messages_address_redirects_to_the_tags_tab(): void
    {
        $this->actingAs($this->admin)->get('/automation?tab=templates')
            ->assertRedirect(route('automation.index', ['tab' => 'tags']));

        $this->actingAs($this->admin)->get('/automation?tab=tags')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'tags'));
    }

    public function test_a_deep_link_opens_the_tab_it_names(): void
    {
        $this->actingAs($this->admin)->get('/automation?tab=activity')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'activity'));

        // and a made-up tab falls back rather than erroring
        $this->actingAs($this->admin)->get('/automation?tab=nonsense')
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'auto_send'));
    }

    public function test_the_guide_renders_for_an_admin(): void
    {
        $this->actingAs($this->admin)->get('/automation/guide')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Automation/Guide')
                ->has('triggers')
                ->has('conditions')
                ->has('actions')
                ->has('categories')
                ->has('thresholds')
                ->where('loop.max_touches_per_chain', config('automation.loop_protection.max_touches_per_chain')));
    }

    /* ================= the scheduler heartbeat ================= */

    /**
     * The heartbeat is a scalar, and the page survives it being anything else.
     *
     * This is a regression test for a bug that reached a running server: the
     * hourly command cached `now()` — a Carbon object — and the Automation page
     * read it back and asked Carbon to diff against it. On the `array` cache
     * driver that works perfectly, because the array driver never serialises.
     * On every real driver the value is serialised and comes back as
     * __PHP_Incomplete_Class, and the whole page 500s.
     *
     * phpunit.xml pins CACHE_STORE to `array`, which is exactly why the
     * original tests all passed. So this asserts the two things that actually
     * matter and neither of them depends on the driver: what goes IN is a
     * scalar, and what comes OUT is validated before it is used.
     */
    public function test_the_scheduler_heartbeat_is_a_plain_timestamp(): void
    {
        $this->artisan('automation:run')->assertSuccessful();

        $cached = cache()->get('automation.last_run_at');

        $this->assertIsInt($cached, 'a rich object here would not survive a real cache driver');
        $this->assertSame(now()->getTimestamp(), $cached);
    }

    public function test_the_page_survives_junk_in_the_heartbeat_cache_key(): void
    {
        /*
         | Including the exact shape that took the page down: an object put
         | through a serialise round trip that could not resolve its class.
         | A cache is shared, long-lived and outside this code's control, so
         | "unknown" is the only safe reading of anything unexpected.
         */
        $incomplete = unserialize(serialize(now()), ['allowed_classes' => false]);

        foreach ([$incomplete, now(), ['an', 'array'], 'nonsense', 3.7, true] as $junk) {
            cache()->put('automation.last_run_at', $junk, 600);

            $this->actingAs($this->admin)->get('/automation')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('schedulerRunning', null));
        }
    }

    public function test_the_page_reports_a_stale_scheduler_and_a_healthy_one(): void
    {
        cache()->put('automation.last_run_at', now()->subMinutes(20)->getTimestamp(), 600);
        $this->actingAs($this->admin)->get('/automation')
            ->assertInertia(fn (Assert $page) => $page->where('schedulerRunning', true));

        cache()->put('automation.last_run_at', now()->subHours(9)->getTimestamp(), 600);
        $this->actingAs($this->admin)->get('/automation')
            ->assertInertia(fn (Assert $page) => $page->where('schedulerRunning', false));

        // never run, or the cache was cleared: unknown, and the tab stays quiet
        cache()->forget('automation.last_run_at');
        $this->actingAs($this->admin)->get('/automation')
            ->assertInertia(fn (Assert $page) => $page->where('schedulerRunning', null));
    }

    /* ================= guard rails ================= */

    public function test_a_new_rule_is_always_saved_switched_off(): void
    {
        // is_active posted deliberately: the controller must not read it
        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload(['is_active' => true]))
            ->assertSessionHasNoErrors();

        $rule = AutomationRule::firstOrFail();

        $this->assertFalse($rule->is_active, 'a rule cannot be born switched on');
        $this->assertSame($this->admin->id, $rule->created_by);
        $this->assertSame(0, $rule->fire_count);
    }

    public function test_editing_a_live_rule_leaves_it_live_and_a_dead_one_dead(): void
    {
        $on = $this->rule(['name' => 'Live', 'is_active' => true]);
        $off = $this->rule(['name' => 'Draft', 'is_active' => false]);

        foreach ([$on, $off] as $rule) {
            $this->actingAs($this->admin)
                ->put(route('automation.rules.update', $rule->id), $this->rulePayload(['name' => $rule->name.' edited']))
                ->assertSessionHasNoErrors();
        }

        $this->assertTrue($on->fresh()->is_active, 'fixing a typo must not silence a rule');
        $this->assertFalse($off->fresh()->is_active, 'nor switch one on');
    }

    public function test_deleting_a_rule_that_has_fired_says_how_many_times(): void
    {
        $rule = $this->rule(['name' => 'Busy', 'fire_count' => 412]);

        $this->actingAs($this->admin)
            ->delete(route('automation.rules.destroy', $rule->id))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '412'));

        $this->assertSame(0, AutomationRule::count());
    }

    public function test_switching_a_rule_on_and_off_is_its_own_request(): void
    {
        $rule = $this->rule(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('automation.rules.toggle', $rule->id), ['is_active' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue($rule->fresh()->is_active);

        $this->actingAs($this->admin)
            ->post(route('automation.rules.toggle', $rule->id), ['is_active' => false]);
        $this->assertFalse($rule->fresh()->is_active);
    }

    /* ================= validation against the catalogue ================= */

    public function test_a_rule_with_no_action_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload(['actions' => []]))
            ->assertSessionHasErrors('actions');

        $this->assertSame(0, AutomationRule::count());
    }

    public function test_values_the_dropdown_could_not_have_offered_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload([
                'conditions' => [['field' => 'source', 'value' => 'carrier_pigeon']],
            ]))
            ->assertSessionHasErrors('conditions.0.value');

        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload([
                'trigger' => 'stage_changed',
                'trigger_config' => ['stage' => 'not_a_stage'],
            ]))
            ->assertSessionHasErrors('trigger_config.stage');

        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload([
                'actions' => [['type' => 'create_follow_up', 'hours' => 99999, 'todo_type' => 'call']],
            ]))
            ->assertSessionHasErrors('actions.0.hours');

        $this->assertSame(0, AutomationRule::count());
    }

    public function test_a_stage_trigger_without_a_stage_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload(['trigger' => 'stage_changed']))
            ->assertSessionHasErrors('trigger_config.stage');
    }

    public function test_stale_parameters_from_a_switched_away_action_are_not_stored(): void
    {
        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload([
                'actions' => [[
                    'type' => 'change_stage',
                    'stage' => 'connected',
                    // left over in the form from an action they switched away from
                    'hours' => 12,
                    'role' => 'telecaller',
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [['type' => 'change_stage', 'stage' => 'connected']],
            AutomationRule::firstOrFail()->actionList(),
        );
    }

    public function test_an_alerts_role_box_is_only_required_when_the_recipient_is_a_role(): void
    {
        // one named person: no role needed
        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload([
                'name' => 'To one person',
                'actions' => [[
                    'type' => 'raise_alert', 'recipient' => 'user',
                    'recipient_user_id' => $this->tele->id,
                    'severity' => 'info', 'title' => 'Look',
                ]],
            ]))
            ->assertSessionHasNoErrors();

        // a role, with no role chosen: refused
        $this->actingAs($this->admin)
            ->post(route('automation.rules.store'), $this->rulePayload([
                'name' => 'To a role',
                'actions' => [[
                    'type' => 'raise_alert', 'recipient' => 'role',
                    'severity' => 'info', 'title' => 'Look',
                ]],
            ]))
            ->assertSessionHasErrors('actions.0.recipient_role');
    }

    /* ================= the test button, against real leads ================= */

    public function test_the_test_button_counts_the_leads_a_rule_would_touch(): void
    {
        $facebookRule = $this->rule([
            'name' => 'Facebook leads to the telecaller desk',
            'trigger_config' => [],
            'conditions' => [['field' => 'source', 'value' => 'facebook']],
        ]);

        foreach (['facebook', 'facebook', 'facebook', 'walk_in'] as $i => $source) {
            $lead = Lead::create([
                'first_name' => 'Lead', 'last_name' => (string) $i,
                'mobile_number' => '98765432'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'project_id' => $this->project->id, 'source' => $source, 'stage' => 'fresh',
                'assigned_to' => $this->tele->id, 'assigned_role' => 'telecaller',
                'stage_changed_at' => now(), 'created_by' => $this->admin->id,
            ]);
            Todo::create([
                'lead_id' => $lead->id, 'assigned_to' => $this->tele->id, 'created_by' => $this->admin->id,
                'scheduled_at' => now()->addDay(), 'type' => 'call', 'status' => 'pending',
            ]);
        }

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.rules.match'), [
                'trigger' => $facebookRule->trigger,
                'trigger_config' => $facebookRule->trigger_config,
                'conditions' => $facebookRule->conditionList(),
            ])
            ->assertOk();

        $this->assertSame(3, $response->json('count'));
        $this->assertNotEmpty($response->json('summary'));

        // and it is still switched off, and still has never run
        $this->assertFalse($facebookRule->fresh()->is_active);
        $this->assertSame(0, $facebookRule->fresh()->fire_count);
    }

    /* ================= templates ================= */

    public function test_a_template_a_rule_still_uses_cannot_be_deleted_silently(): void
    {
        $template = MessageTemplate::create([
            'name' => 'Welcome', 'category' => 'utility', 'body' => 'Hi {first_name}',
            'placeholder_map' => ['first_name'], 'is_active' => true,
        ]);

        $this->rule([
            'name' => 'Uses it',
            'actions' => [['type' => 'queue_whatsapp', 'template_id' => $template->id]],
        ]);

        $this->actingAs($this->admin)
            ->delete(route('automation.templates.destroy', $template->id))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Uses it'));

        $this->assertSame(1, MessageTemplate::count());
    }

    /* ================= helpers ================= */

    private function rulePayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'A rule',
            'description' => 'What it is for.',
            'trigger' => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'facebook']],
            'actions' => [['type' => 'assign_round_robin', 'role' => 'telecaller']],
        ];
    }

    private function rule(array $attrs = []): AutomationRule
    {
        return AutomationRule::create($attrs + [
            'name' => 'Rule '.AutomationRule::count(),
            'trigger' => 'lead_created',
            'conditions' => [],
            'actions' => [['type' => 'assign_round_robin', 'role' => 'telecaller']],
            'is_active' => false,
            'created_by' => $this->admin->id,
        ]);
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
