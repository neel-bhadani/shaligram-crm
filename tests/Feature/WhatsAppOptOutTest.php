<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadFollowUpService;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The WhatsApp opt-out: who can set it, who can undo it, and what it stops.
 */
class WhatsAppOptOutTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://api.11za.in/apis/template/sendTemplate';

    private User $admin;

    private User $tele;

    private Lead $lead;

    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $project = Project::create(['name' => 'Skyline Residency']);

        $this->lead = Lead::create([
            'first_name' => 'Rahul', 'last_name' => 'Mehta', 'mobile_number' => '9876543210',
            'project_id' => $project->id, 'source' => 'walk_in', 'stage' => 'fresh',
            'assigned_to' => $this->tele->id, 'assigned_role' => 'telecaller',
            'stage_changed_at' => now(), 'created_by' => $this->admin->id,
        ]);

        $this->template = MessageTemplate::create([
            'name' => 'Welcome', 'is_active' => true, 'placeholder_map' => ['first_name'],
            'provider_template_name' => 'welcome', 'provider_template_language' => 'en',
        ]);
    }

    public function test_anyone_who_can_edit_the_lead_can_opt_it_out_but_only_an_admin_can_undo_it(): void
    {
        $this->actingAs($this->tele)
            ->putJson(route('leads.whatsapp.opt-out', $this->lead), ['opted_out' => true])
            ->assertOk()
            ->assertJsonPath('opt_out.active', true)
            ->assertJsonPath('opt_out.can_switch_off', false);

        $this->lead->refresh();
        $this->assertTrue($this->lead->hasOptedOutOfWhatsApp());
        $this->assertSame('manual', $this->lead->whatsapp_opt_out_source);
        $this->assertSame($this->tele->id, $this->lead->whatsapp_opted_out_by);

        $this->actingAs($this->tele)
            ->putJson(route('leads.whatsapp.opt-out', $this->lead), ['opted_out' => false])
            ->assertForbidden();
        $this->assertTrue($this->lead->fresh()->hasOptedOutOfWhatsApp());

        $this->actingAs($this->admin)
            ->putJson(route('leads.whatsapp.opt-out', $this->lead), ['opted_out' => false])
            ->assertOk()
            ->assertJsonPath('opt_out.active', false);
        $this->assertFalse($this->lead->fresh()->hasOptedOutOfWhatsApp());

        // who did each, and when, is on the lead's history
        $rows = LeadActivity::where('lead_id', $this->lead->id)->where('field', 'whatsapp_opt_out')->orderBy('id')->get();
        $this->assertSame([[$this->tele->id, 'opted_out'], [$this->admin->id, 'allowed']],
            $rows->map(fn (LeadActivity $a) => [$a->user_id, $a->to_value])->all());
        $this->assertNotNull($rows[1]->created_at);
    }

    public function test_a_single_send_to_an_opted_out_customer_needs_confirming_first(): void
    {
        $this->optOut();

        $this->actingAs($this->tele)
            ->getJson(route('leads.whatsapp.show', $this->lead))
            ->assertJsonPath('opt_out.active', true);

        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.send', $this->lead), ['template_id' => $this->template->id])
            ->assertStatus(422)
            ->assertJsonPath('needs_confirmation', true);
        $this->assertSame(0, MessageLog::count());

        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.send', $this->lead), ['template_id' => $this->template->id, 'confirm_opted_out' => true])
            ->assertOk();
        $this->assertSame(1, MessageLog::count());
    }

    public function test_automatic_sends_skip_an_opted_out_customer(): void
    {
        Http::fake();
        $this->configureApi();
        $this->optOut();

        AutomationRule::create([
            'name' => 'Welcome', 'trigger' => 'lead_created', 'trigger_config' => [], 'conditions' => [],
            'actions' => [['type' => 'queue_whatsapp', 'mode' => 'api', 'template_id' => $this->template->id]],
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($this->lead);

        Http::assertNothingSent();
        $this->assertSame(0, MessageLog::count());

        $log = AutomationLog::where('action', 'queue_whatsapp')->sole();
        $this->assertSame('skipped', $log->result);
        $this->assertSame(WhatsAppSender::OPTED_OUT, $log->error);
    }

    public function test_an_automatic_message_queued_before_the_opt_out_is_skipped_when_it_comes_to_send(): void
    {
        Http::fake();
        $this->configureApi();

        $rule = AutomationRule::create([
            'name' => 'Welcome', 'trigger' => 'lead_created', 'trigger_config' => [], 'conditions' => [],
            'actions' => [], 'is_active' => true, 'created_by' => $this->admin->id,
        ]);
        $message = MessageLog::create([
            'lead_id' => $this->lead->id, 'template_id' => $this->template->id, 'rule_id' => $rule->id,
            'provider_template_name' => 'welcome', 'provider_template_language' => 'en',
            'mode' => 'api', 'to_number' => '919876543210', 'to_name' => 'Rahul Mehta',
            'body' => 'Hello Rahul.', 'params' => ['Rahul'], 'status' => 'queued',
        ]);

        $this->optOut();
        SendWhatsAppMessage::dispatchSync($message->id);

        Http::assertNothingSent();
        $this->assertSame('skipped', $message->fresh()->status);
        $this->assertSame(WhatsAppSender::OPTED_OUT, $message->fresh()->error);
    }

    public function test_the_queue_buttons_refuse_an_opted_out_customer(): void
    {
        Http::fake();
        $this->configureApi();
        $message = MessageLog::create([
            'lead_id' => $this->lead->id, 'template_id' => $this->template->id,
            'mode' => 'click', 'to_number' => '919876543210', 'to_name' => 'Rahul Mehta',
            'body' => 'Hello Rahul.', 'params' => ['Rahul'], 'status' => 'queued',
        ]);
        $this->optOut();

        $this->actingAs($this->admin)
            ->postJson(route('automation.messages.open', $message))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->post(route('automation.messages.send', $message))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame('queued', $message->fresh()->status);
    }

    /* ================= helpers ================= */

    private function optOut(): void
    {
        $this->lead->update(['whatsapp_opted_out_at' => now(), 'whatsapp_opt_out_source' => 'manual', 'whatsapp_opted_out_by' => $this->tele->id]);
    }

    private function configureApi(): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'auth_token' => '11za-live-token', 'origin_website' => 'https://shaligram.example',
            'api_enabled' => true, 'auto_send' => true,
        ]);
        $integration->save();
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first, 'last_name' => 'User',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }
}
