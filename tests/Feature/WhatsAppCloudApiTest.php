<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Alert;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\Project;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\Automation\RuleEngine;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp through Meta's Cloud API: the 24-hour rule, the number, the retry
 * limit, the fallback to click-to-send, and the guards on automatic sends.
 *
 * @see WhatsAppSender
 * @see SendWhatsAppMessage
 */
class WhatsAppCloudApiTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://graph.facebook.com/v26.0/PHONE-ID/messages';

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-29 11:00', 'Asia/Kolkata'));

        $this->admin = User::create([
            'first_name' => 'Ann', 'last_name' => 'Admin', 'email' => 'ann@example.test',
            'mobile_number' => '9000000001', 'role' => 'admin', 'is_active' => true, 'password' => 'password',
        ]);
        $this->project = Project::create(['name' => 'Skyline Residency']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ================= the 24-hour window ================= */

    public function test_free_text_is_refused_outside_the_window(): void
    {
        $this->configure();
        Http::fake();
        $lead = $this->lead();

        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.send', $lead), ['text' => 'Hello'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => WhatsAppSender::TEMPLATE_ONLY]);

        Http::assertNothingSent();
        $this->assertSame(0, MessageLog::count());
    }

    public function test_free_text_is_sent_inside_the_window(): void
    {
        $this->configure();
        $this->fakeMeta();
        $lead = $this->lead(['last_inbound_at' => now()->subHours(2)]);

        $this->actingAs($this->admin)
            ->getJson(route('leads.whatsapp.show', $lead))
            ->assertJsonPath('window.open', true)
            ->assertJsonPath('window.closes_at', now()->addHours(22)->toIso8601String());

        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.send', $lead), ['text' => 'Your brochure is ready'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'sent']);

        Http::assertSent(fn (Request $r) => $r['type'] === 'text'
            && $r['text']['body'] === 'Your brochure is ready'
            && $r->hasHeader('Authorization', 'Bearer SYSTEM-USER-TOKEN'));

        $message = MessageLog::sole();
        $this->assertSame(['api', 'sent', 'wamid.OK'], [$message->mode, $message->status, $message->meta_message_id]);
    }

    public function test_an_approved_template_is_sent_with_the_admins_mapping_and_logged(): void
    {
        $this->configure();
        $this->fakeMeta();
        $template = $this->template();
        $lead = $this->lead();

        $this->actingAs($this->admin)
            ->getJson(route('leads.whatsapp.show', $lead))
            ->assertJsonPath('window.open', false)
            ->assertJsonPath('templates.0.preview', 'Namaste Rahul, thank you for asking about Skyline Residency.');

        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.send', $lead), ['whatsapp_template_id' => $template->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'sent']);

        Http::assertSent(fn (Request $r) => $r->url() === self::SEND_URL
            && $r['to'] === '919876543210'
            && $r['type'] === 'template'
            && $r['template']['name'] === 'welcome'
            && $r['template']['language']['code'] === 'en_US'
            && $r['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'Rahul'],
                ['type' => 'text', 'text' => 'Skyline Residency'],
            ]);

        $message = MessageLog::sole();
        $this->assertSame($template->id, $message->whatsapp_template_id);
        $this->assertSame('wamid.OK', $message->meta_message_id);
        $this->assertSame(1, $message->attempts);
        $this->assertSame(['Rahul', 'Skyline Residency'], array_column($message->parameters, 'value'));
        $this->assertSame($this->admin->id, $message->user_id);
    }

    public function test_a_template_with_an_unmapped_variable_is_not_sent(): void
    {
        $this->configure();
        Http::fake();
        $template = $this->template(['parameter_map' => ['1' => 'first_name']]);

        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.send', $this->lead()), ['whatsapp_template_id' => $template->id])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }

    /* ================= the number ================= */

    public function test_the_number_is_the_last_ten_digits_behind_91(): void
    {
        $renderer = app(TemplateRenderer::class);

        foreach (['9876543210', '+91 98765-43210', '09876543210', '(98765) 43210', '0091 98765 43210'] as $typed) {
            $this->assertSame('919876543210', $renderer->waNumber($typed), $typed);
        }

        $this->assertNull($renderer->waNumber('98765'));
    }

    /* ================= retries ================= */

    public function test_a_failed_automatic_send_is_tried_three_times_then_marked_failed_and_alerted(): void
    {
        config(['queue.default' => 'database', 'automation.whatsapp.api.backoff' => [0, 0]]);
        $this->configure();
        Http::fake([self::SEND_URL => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 131000]], 503)]);

        $lead = $this->lead();
        app(RuleEngine::class)->run($this->apiRule($this->template()), $lead);

        $this->work();

        Http::assertSentCount(3);

        $message = MessageLog::sole();
        $this->assertSame('failed', $message->status);
        $this->assertSame(3, $message->attempts);
        $this->assertStringContainsString('"code":131000', $message->error, 'the whole of Meta\'s error is kept');

        $alert = Alert::where('user_id', $this->admin->id)->where('type', 'whatsapp.failed')->sole();
        $this->assertSame($lead->id, $alert->lead_id);
    }

    public function test_a_refusal_that_cannot_succeed_is_not_retried(): void
    {
        config(['queue.default' => 'database', 'automation.whatsapp.api.backoff' => [0, 0]]);
        $this->configure();
        Http::fake([self::SEND_URL => Http::response(['error' => ['message' => 'Template name does not exist', 'code' => 132001]], 400)]);

        app(RuleEngine::class)->run($this->apiRule($this->template()), $this->lead());
        $this->work();

        Http::assertSentCount(1);
        $this->assertSame('failed', MessageLog::sole()->status);
        $this->assertSame(1, Alert::where('type', 'whatsapp.failed')->count());
    }

    /* ================= fallback ================= */

    public function test_missing_credentials_fall_back_to_click_to_send(): void
    {
        Http::fake();
        $lead = $this->lead();

        $result = app(RuleEngine::class)->run($this->apiRule($this->template()), $lead);

        $this->assertSame('fired', $result);
        Http::assertNothingSent();

        $message = MessageLog::sole();
        $this->assertSame(['click', 'queued'], [$message->mode, $message->status]);
        $this->assertSame('Namaste Rahul, thank you for asking about Skyline Residency.', $message->body);
        $this->assertStringContainsString('queued for click-to-send', AutomationLog::where('action', 'queue_whatsapp')->sole()->error);

        // and by hand: the message opens in WhatsApp instead
        $this->actingAs($this->admin)
            ->postJson(route('leads.whatsapp.send', $lead), ['text' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('status', 'opened')
            ->assertJsonPath('click_url', 'https://wa.me/919876543210?text=Hello');
    }

    public function test_an_integration_switched_off_falls_back_to_click_to_send(): void
    {
        $this->configure(active: false);
        Http::fake();

        app(RuleEngine::class)->run($this->apiRule($this->template()), $this->lead());

        Http::assertNothingSent();
        $this->assertSame('click', MessageLog::sole()->mode);
    }

    /* ================= guards ================= */

    public function test_a_lead_at_a_terminal_stage_is_not_messaged_unless_the_rule_allows_it(): void
    {
        $this->configure();
        $this->fakeMeta();
        $template = $this->template();

        app(RuleEngine::class)->run($this->apiRule($template), $this->lead(['stage' => 'lost']));

        Http::assertNothingSent();
        $this->assertSame(0, MessageLog::count());
        $this->assertSame('skipped', AutomationLog::where('action', 'queue_whatsapp')->sole()->result);

        app(RuleEngine::class)->run($this->apiRule($template, ['terminal' => 'allow']), $this->lead(['stage' => 'booking_done']));

        Http::assertSentCount(1);
        $this->assertSame('sent', MessageLog::sole()->status);
    }

    public function test_the_cooldown_stops_a_rule_messaging_the_same_lead_twice(): void
    {
        $this->configure();
        $this->fakeMeta();
        $rule = $this->apiRule($this->template());
        $lead = $this->lead();

        app(RuleEngine::class)->run($rule, $lead);
        $this->assertSame('cooldown', app(RuleEngine::class)->run($rule, $lead));

        Http::assertSentCount(1);
        $this->assertSame(1, MessageLog::count());
    }

    /* ================= templates from Meta ================= */

    public function test_syncing_templates_keeps_the_mapping_and_reads_the_variables(): void
    {
        $this->configure();
        $existing = $this->template();

        Http::fake(['https://graph.facebook.com/v26.0/WABA-ID/message_templates*' => Http::response(['data' => [
            ['id' => '11', 'name' => 'welcome', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'Namaste {{1}}, thank you for asking about {{2}}.']]],
            ['id' => '12', 'name' => 'offer', 'language' => 'en_US', 'status' => 'PENDING', 'category' => 'MARKETING',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'IMAGE'],
                    ['type' => 'BODY', 'text' => 'Hi {{1}}'],
                ]],
        ]])]);

        $this->actingAs($this->admin)
            ->post(route('automation.whatsapp.templates.sync'))
            ->assertSessionHas('success');

        $this->assertSame(['1' => 'first_name', '2' => 'project'], $existing->fresh()->parameter_map);

        $offer = WhatsAppTemplate::where('name', 'offer')->sole();
        $this->assertSame(['1'], $offer->variables);
        $this->assertNotNull($offer->unsupported_reason);
        $this->assertNotNull($offer->unsendableReason());
    }

    /* ================= helpers ================= */

    /**
     * Run the queue until it is empty. The memory limit is raised because the
     * worker stops after any job once the process is over it, and a full test
     * run is well past the 128MB default.
     */
    private function work(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);
    }

    private function configure(bool $active = true): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'phone_number_id' => 'PHONE-ID',
            'whatsapp_business_account_id' => 'WABA-ID',
            'access_token' => 'SYSTEM-USER-TOKEN',
        ]);
        $integration->is_active = $active;
        $integration->save();
    }

    private function fakeMeta(): void
    {
        Http::fake([self::SEND_URL => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.OK']],
        ])]);
    }

    private function template(array $attrs = []): WhatsAppTemplate
    {
        return WhatsAppTemplate::create($attrs + [
            'name' => 'welcome',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'body' => 'Namaste {{1}}, thank you for asking about {{2}}.',
            'variables' => ['1', '2'],
            'parameter_map' => ['1' => 'first_name', '2' => 'project'],
        ]);
    }

    private function apiRule(WhatsAppTemplate $template, array $action = []): AutomationRule
    {
        return AutomationRule::create([
            'name' => 'Welcome by API '.AutomationRule::count(),
            'trigger' => 'lead_created',
            'conditions' => [],
            'actions' => [$action + ['type' => 'queue_whatsapp', 'mode' => 'api', 'whatsapp_template_id' => $template->id]],
            'is_active' => true,
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
}
