<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadStage;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\Automation\AutoSend;
use App\Services\LeadFollowUpService;
use App\Services\WhatsApp\TemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Auto-send tab — a message per stage, kept as ordinary rules — and
 * click-to-send wording. The 11za template list is in ProviderTemplateListTest.
 *
 * @see AutoSend
 */
class AutoSendTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tele;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Skyline Residency']);
    }

    /* ================= auto-send ================= */

    public function test_new_enquiry_is_the_lead_created_trigger_and_messages_a_lead_born_at_fresh(): void
    {
        $template = $this->message();

        $this->actingAs($this->admin)
            ->put(route('automation.auto_send.update', 'new_enquiry'), ['template_id' => $template->id])
            ->assertSessionHasNoErrors();

        $rule = AutomationRule::sole();
        $this->assertSame('lead_created', $rule->trigger);
        $this->assertTrue($rule->is_active, 'chosen on Auto-send means on');
        // assertEquals: MySQL's JSON column does not keep key order
        $this->assertEquals([['type' => 'queue_whatsapp', 'mode' => 'api', 'template_id' => $template->id]], $rule->actions);

        // the way a Facebook lead arrives: created at fresh, never moved there
        $lead = $this->lead();
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        $this->assertSame($template->id, MessageLog::where('lead_id', $lead->id)->sole()->template_id);
    }

    public function test_choosing_a_message_for_a_stage_writes_a_stage_rule_and_none_switches_it_off(): void
    {
        $template = $this->message();
        $this->actingAs($this->admin);

        $this->put(route('automation.auto_send.update', 'site_visit_done'), ['template_id' => $template->id]);

        $rule = AutomationRule::sole();
        $this->assertSame('stage_changed', $rule->trigger);
        $this->assertSame(['stage' => 'site_visit_done'], $rule->trigger_config);
        $this->assertTrue($rule->is_active);

        $this->put(route('automation.auto_send.update', 'site_visit_done'), ['template_id' => null]);

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame(1, AutomationRule::count(), 'None switches off, it does not delete');

        // choosing again reuses the same rule rather than writing a second
        $this->put(route('automation.auto_send.update', 'site_visit_done'), ['template_id' => $template->id]);
        $this->assertSame(1, AutomationRule::count());
        $this->assertTrue($rule->fresh()->is_active);
    }

    public function test_an_existing_simple_rule_shows_on_its_row_and_editing_keeps_the_rest_of_it(): void
    {
        $old = $this->message('Old');
        $new = $this->message('New');

        $rule = AutomationRule::create([
            'name' => 'Booking thanks', 'trigger' => 'stage_changed', 'trigger_config' => ['stage' => 'booking_done'],
            'conditions' => [],
            'actions' => [['type' => 'queue_whatsapp', 'mode' => 'click', 'template_id' => $old->id, 'terminal' => 'skip']],
            'is_active' => true, 'fire_count' => 12, 'created_by' => $this->admin->id,
        ]);

        $row = collect(app(AutoSend::class)->rows())->firstWhere('key', 'booking_done');
        $this->assertSame($old->id, $row['template_id']);
        $this->assertSame($rule->id, $row['rule_id']);
        $this->assertSame([], $row['others']);

        $this->actingAs($this->admin)
            ->put(route('automation.auto_send.update', 'booking_done'), ['template_id' => $new->id])
            ->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame('Booking thanks', $rule->name);
        $this->assertSame(12, $rule->fire_count);
        $this->assertEquals(
            [['type' => 'queue_whatsapp', 'mode' => 'click', 'template_id' => $new->id, 'terminal' => 'skip']],
            $rule->actions,
        );
    }

    public function test_a_rule_with_conditions_is_listed_on_its_row_but_never_changed_there(): void
    {
        $template = $this->message();
        $actions = [
            ['type' => 'queue_whatsapp', 'mode' => 'api', 'template_id' => $template->id],
            ['type' => 'create_follow_up', 'hours' => 24, 'todo_type' => 'call'],
        ];

        $complex = AutomationRule::create([
            'name' => 'Facebook welcome', 'trigger' => 'lead_created', 'trigger_config' => [],
            'conditions' => [['field' => 'source', 'value' => 'facebook']],
            'actions' => $actions,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $row = collect(app(AutoSend::class)->rows())->firstWhere('key', 'new_enquiry');
        $this->assertNull($row['template_id'], 'a conditional rule is not what the row sends');
        $this->assertSame([['id' => $complex->id, 'name' => 'Facebook welcome', 'is_active' => true]], $row['others']);

        $this->actingAs($this->admin);
        $this->put(route('automation.auto_send.update', 'new_enquiry'), ['template_id' => $template->id]);
        $this->put(route('automation.auto_send.update', 'new_enquiry'), ['template_id' => null]);

        $complex->refresh();
        $this->assertTrue($complex->is_active);
        $this->assertEquals($actions, $complex->actions);
        $this->assertSame(2, AutomationRule::count());

        // it is listed under Other automation; the row's own rule is not
        $this->get('/automation')->assertInertia(fn (Assert $page) => $page
            ->has('otherAutomation', 1)
            ->where('otherAutomation.0.id', $complex->id));
    }

    public function test_every_rule_a_row_does_not_show_is_listed_under_other_automation(): void
    {
        $template = $this->message();
        $this->actingAs($this->admin);
        $this->put(route('automation.auto_send.update', 'connected'), ['template_id' => $template->id]);
        $own = AutomationRule::sole();

        $rule = fn (string $name, string $trigger, array $config, array $actions) => AutomationRule::create([
            'name' => $name, 'trigger' => $trigger, 'trigger_config' => $config, 'conditions' => [],
            'actions' => $actions, 'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $alert = $rule('Alert on booking', 'stage_changed', ['stage' => 'booking_done'], [['type' => 'raise_alert', 'recipient' => 'admins', 'severity' => 'info', 'title' => 'Booked']]);
        $stuck = $rule('Stuck leads', 'stage_idle', ['stage' => 'connected', 'days' => 7], [['type' => 'create_follow_up', 'hours' => 24, 'todo_type' => 'call']]);
        $second = $rule('Second connected message', 'stage_changed', ['stage' => 'connected'], [['type' => 'queue_whatsapp', 'mode' => 'api', 'template_id' => $template->id]]);

        $this->get('/automation')->assertInertia(fn (Assert $page) => $page
            ->where('otherAutomation', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === [$alert->id, $stuck->id, $second->id])
            // the troubleshooting tab still lists every rule, the row's own included
            ->has('rules', 4)
            ->where('rules', fn ($rows) => collect($rows)->firstWhere('id', $own->id)['on_auto_send'] === true));
    }

    public function test_other_automation_can_switch_a_rule_off(): void
    {
        $rule = AutomationRule::create([
            'name' => 'Alert on booking', 'trigger' => 'stage_changed', 'trigger_config' => ['stage' => 'booking_done'],
            'conditions' => [], 'actions' => [['type' => 'raise_alert', 'recipient' => 'admins', 'severity' => 'info', 'title' => 'Booked']],
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('automation.rules.toggle', $rule), ['is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($rule->fresh()->is_active);
        $this->assertSame(1, AutomationRule::count());
    }

    public function test_the_rules_tab_still_opens_by_its_address(): void
    {
        $this->actingAs($this->admin)->get('/automation?tab=rules')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'rules')->has('catalog.actions'));
    }

    /* ================= deleting a stage ================= */

    public function test_a_stage_whose_auto_send_row_is_set_to_none_can_be_deleted_and_takes_the_rule_with_it(): void
    {
        $stage = $this->addStage('Offer sent');
        $this->actingAs($this->admin);

        $this->put(route('automation.auto_send.update', $stage->key), ['template_id' => $this->message()->id]);
        $this->put(route('automation.auto_send.update', $stage->key), ['template_id' => null]);
        $this->assertFalse(AutomationRule::sole()->is_active);

        $this->delete("/pipeline/stages/{$stage->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('lead_stages', ['key' => $stage->key]);
        $this->assertSame(0, AutomationRule::count());
    }

    public function test_a_stage_auto_send_is_sending_at_cannot_be_deleted_until_set_to_none(): void
    {
        $stage = $this->addStage('Offer sent');
        $this->actingAs($this->admin);

        $this->put(route('automation.auto_send.update', $stage->key), ['template_id' => $this->message()->id]);

        $this->delete("/pipeline/stages/{$stage->id}")
            ->assertSessionHasErrors(['stage' => 'Auto-send sends a message when a lead reaches this stage. Set it to None on the Auto-send tab first.']);

        $this->assertDatabaseHas('lead_stages', ['key' => $stage->key]);
        $this->assertSame(1, AutomationRule::count());
    }

    public function test_a_stage_another_rule_names_cannot_be_deleted_and_says_where_the_rule_is(): void
    {
        $stage = $this->addStage('Offer sent');

        // switched off and still blocking: only Auto-send's own rules go with a stage
        AutomationRule::create([
            'name' => 'Chase the offer', 'trigger' => 'stage_changed', 'trigger_config' => ['stage' => $stage->key],
            'conditions' => [], 'actions' => [['type' => 'raise_alert']],
            'is_active' => false, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->delete("/pipeline/stages/{$stage->id}")->assertSessionHasErrors('stage');

        $this->assertStringContainsString('"Chase the offer" (listed under Other automation on the Auto-send tab)', session('errors')->first('stage'));
        $this->assertDatabaseHas('lead_stages', ['key' => $stage->key]);
        $this->assertSame(1, AutomationRule::count());
    }

    public function test_a_message_without_an_11za_name_is_refused_for_auto_send(): void
    {
        $unnamed = MessageTemplate::create([
            'name' => 'Old click-only', 'category' => 'utility', 'is_active' => true,
            'body' => 'Hi {first_name}', 'placeholder_map' => [],
        ]);

        $this->actingAs($this->admin)
            ->put(route('automation.auto_send.update', 'connected'), ['template_id' => $unnamed->id])
            ->assertSessionHasErrors('template_id');

        $this->assertSame(0, AutomationRule::count());
    }

    public function test_auto_send_is_admin_only(): void
    {
        $this->actingAs($this->tele)
            ->put(route('automation.auto_send.update', 'connected'), ['template_id' => $this->message()->id])
            ->assertForbidden();

        $this->assertSame(0, AutomationRule::count());
    }

    /* ================= click-to-send wording ================= */

    public function test_click_to_send_on_a_message_with_no_wording_opens_a_generic_line(): void
    {
        $template = $this->message();
        $this->assertNull($template->body);

        $response = $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.send', $this->lead()), ['template_id' => $template->id])
            ->assertOk()
            ->assertJsonPath('mode', 'click');

        $this->assertSame('Hello Rahul, regarding Skyline Residency.', MessageLog::sole()->body);
        $this->assertStringEndsWith('?text='.rawurlencode('Hello Rahul, regarding Skyline Residency.'), $response->json('url'));
    }

    public function test_click_to_send_prefers_11zas_wording_then_the_old_wording(): void
    {
        $lead = $this->lead();
        $renderer = app(TemplateRenderer::class);

        $template = $this->message();
        $template->update(['body' => 'Old: hi {first_name}', 'provider_body' => 'Hi {{1}}, thanks for asking about {{2}}.']);
        $this->assertSame('Hi Rahul, thanks for asking about Skyline Residency.', $renderer->build($template, $lead)['body']);

        $template->update(['provider_body' => null]);
        $this->assertSame('Old: hi Rahul', $renderer->build($template, $lead)['body']);
    }

    /* ================= helpers ================= */

    private function addStage(string $label): LeadStage
    {
        $this->actingAs($this->admin)
            ->post('/pipeline/stages', ['label' => $label, 'color' => '#334155'])
            ->assertSessionHasNoErrors();

        return LeadStage::where('label', $label)->firstOrFail();
    }

    private function message(string $name = 'Welcome'): MessageTemplate
    {
        return MessageTemplate::create([
            'name' => $name, 'is_active' => true,
            'provider_template_name' => 'welcome', 'provider_template_language' => 'en',
            'placeholder_map' => ['first_name', 'project'],
        ]);
    }

    private function lead(): Lead
    {
        return Lead::create([
            'first_name' => 'Rahul',
            'last_name' => 'Mehta',
            'mobile_number' => '9876543210',
            'project_id' => $this->project->id,
            'source' => 'facebook',
            'stage' => 'fresh',
            'assigned_to' => $this->tele->id,
            'assigned_role' => 'telecaller',
            'stage_changed_at' => now(),
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
