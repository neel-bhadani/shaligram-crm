<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\LeadFollowUpService;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp by API: one approved template, to one lead, when a rule fires or a
 * person presses Send.
 *
 * Everything that reaches Meta is faked with Http::fake(); the queue is `sync`
 * in phpunit.xml, so a dispatched send has run by the time the call returns.
 *
 * @see WhatsAppSender
 * @see SendWhatsAppMessage
 */
class WhatsAppStageMessagingTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGES_URL = 'https://graph.facebook.com/v26.0/5550001/messages';

    private User $admin;

    private User $tele;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-30 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Skyline Residency']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ================= sending ================= */

    public function test_a_stage_change_sends_the_approved_template_with_parameters_in_stored_order(): void
    {
        $this->fakeMeta();
        $this->configureApi();

        // {project} comes first in the body, so {{1}} is the project: the
        // order is the stored map's, not alphabetical and not re-derived
        $template = $this->linkedTemplate('{project}: hello {first_name}, see you soon.', ['project', 'first_name']);
        $this->apiRule('stage_changed', $template, ['stage' => 'site_visit_done']);

        $lead = $this->lead(['stage' => 'connected']);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->changeStage($lead->fresh(), 'site_visit_done');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::MESSAGES_URL
            && $request['to'] === '919876543210'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'site_visit'
            && $request['template']['language']['code'] === 'en'
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'Skyline Residency'],
                ['type' => 'text', 'text' => 'Rahul'],
            ]);

        $message = MessageLog::sole();
        $this->assertSame('sent', $message->status);
        $this->assertSame('api', $message->mode);
        $this->assertSame('wamid.TEST1', $message->wamid);
        $this->assertSame('Rahul Mehta', $message->to_name);
        $this->assertSame('919876543210', $message->to_number);
        $this->assertSame(['Skyline Residency', 'Rahul'], $message->params);
        $this->assertNotNull($message->rule_id);
        $this->assertSame('Accepted by Meta (wamid.TEST1)', $message->outcome());
    }

    public function test_a_lead_created_api_rule_sends(): void
    {
        $this->fakeMeta();
        $this->configureApi();

        $template = $this->linkedTemplate('Welcome {first_name}.', ['first_name'], body: 'Welcome {{1}}.');
        $this->apiRule('lead_created', $template);

        $lead = $this->lead();
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['template']['components'][0]['parameters'][0]['text'] === 'Rahul');

        $this->assertSame('sent', MessageLog::sole()->status);
    }

    public function test_the_cooldown_stops_a_second_send(): void
    {
        $this->fakeMeta();
        $this->configureApi();

        $template = $this->linkedTemplate('{project}: hello {first_name}, see you soon.', ['project', 'first_name']);
        $this->apiRule('stage_changed', $template, ['stage' => 'site_visit_done']);

        $lead = $this->lead(['stage' => 'connected']);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        $service = app(LeadFollowUpService::class);
        $service->changeStage($lead->fresh(), 'site_visit_done');
        $service->changeStage($lead->fresh(), 'in_discussion');
        $service->changeStage($lead->fresh(), 'site_visit_done');

        Http::assertSentCount(1);
        $this->assertSame(1, MessageLog::count());
        $this->assertSame(1, AutomationLog::where('result', 'cooldown')->count());
    }

    public function test_the_same_message_to_the_same_number_is_sent_once_but_a_different_project_still_gets_its_own(): void
    {
        $this->fakeMeta();
        $this->configureApi();

        $template = $this->linkedTemplate('{project}: hello {first_name}, see you soon.', ['project', 'first_name']);
        $this->apiRule('stage_changed', $template, ['stage' => 'site_visit_done']);

        $this->actingAs($this->admin);
        $service = app(LeadFollowUpService::class);

        // same person, same number, same project: an identical rendered message
        $first = $this->lead(['stage' => 'connected']);
        $twin = $this->lead(['stage' => 'connected']);
        // same person on another project: a different message
        $other = $this->lead(['stage' => 'connected', 'project_id' => Project::create(['name' => 'Vanam'])->id]);

        foreach ([$first, $twin, $other] as $lead) {
            $this->todoFor($lead);
            $service->changeStage($lead->fresh(), 'site_visit_done');
        }

        Http::assertSentCount(2);

        $skipped = MessageLog::where('lead_id', $twin->id)->sole();
        $this->assertSame('skipped', $skipped->status);
        $this->assertStringContainsString("already went to 919876543210 via lead #{$first->id}", $skipped->error);

        $this->assertSame('sent', MessageLog::where('lead_id', $other->id)->sole()->status);
    }

    public function test_a_lead_with_no_usable_number_is_skipped_and_says_why(): void
    {
        $this->fakeMeta();
        $this->configureApi();

        $template = $this->linkedTemplate('Welcome {first_name}.', ['first_name'], body: 'Welcome {{1}}.');
        $this->apiRule('lead_created', $template);

        $lead = $this->lead(['mobile_number' => '98765']);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        Http::assertNothingSent();

        $message = MessageLog::sole();
        $this->assertSame('skipped', $message->status);
        $this->assertStringContainsString('no usable mobile number', $message->error);

        $log = AutomationLog::where('action', 'queue_whatsapp')->sole();
        $this->assertSame('skipped', $log->result);
        $this->assertStringContainsString('no usable mobile number', $log->error);
    }

    public function test_with_the_api_off_an_api_rule_falls_back_to_click_to_send(): void
    {
        $this->fakeMeta();
        $this->configureApi(apiEnabled: false);

        $template = $this->linkedTemplate('Welcome {first_name}.', ['first_name'], body: 'Welcome {{1}}.');
        $this->apiRule('lead_created', $template);

        $lead = $this->lead();
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        Http::assertNothingSent();

        $message = MessageLog::sole();
        $this->assertSame('click', $message->mode);
        $this->assertSame('queued', $message->status);
        $this->assertSame('Welcome Rahul.', $message->body);

        $this->assertSame('success', AutomationLog::where('action', 'queue_whatsapp')->sole()->result);
    }

    public function test_terminal_stages_are_messaged_only_when_the_rule_opts_in(): void
    {
        $this->fakeMeta();
        $this->configureApi();

        $template = $this->linkedTemplate('Thank you {first_name}.', ['first_name'], body: 'Thank you {{1}}.');
        $service = app(LeadFollowUpService::class);
        $this->actingAs($this->admin);

        $number = 9876500000;

        foreach (['booking_done', 'lost'] as $stage) {
            foreach (['skip' => 'skipped', 'send' => 'sent'] as $terminal => $expected) {
                AutomationRule::query()->delete();
                $this->apiRule('stage_changed', $template, ['stage' => $stage], ['terminal' => $terminal]);

                $lead = $this->lead(['stage' => 'in_discussion', 'mobile_number' => (string) $number++]);
                $this->todoFor($lead);

                $service->changeStage($lead->fresh(), $stage);

                $this->assertSame($expected, MessageLog::where('lead_id', $lead->id)->sole()->status, "$stage / $terminal");
            }
        }

        Http::assertSentCount(2);
    }

    public function test_a_number_not_on_whatsapp_fails_once_with_a_plain_reason(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response(['error' => [
            'message' => '(#131026) Message undeliverable', 'code' => 131026,
        ]], 400)]);
        $this->configureApi();

        $template = $this->linkedTemplate('Welcome {first_name}.', ['first_name'], body: 'Welcome {{1}}.');
        $this->apiRule('lead_created', $template);

        $lead = $this->lead();
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        Http::assertSentCount(1);

        $message = MessageLog::sole();
        $this->assertSame('failed', $message->status);
        $this->assertSame('131026', $message->error_code);
        $this->assertStringContainsString('not on WhatsApp', $message->error);
        $this->assertStringContainsString('(#131026) Message undeliverable', $message->error);
    }

    /* ================= rule save ================= */

    public function test_an_unapproved_template_is_refused_at_rule_save(): void
    {
        $pending = $this->linkedTemplate('Welcome {first_name}.', ['first_name'], body: 'Welcome {{1}}.', status: 'PENDING');
        $unlinked = MessageTemplate::create([
            'name' => 'Unlinked', 'category' => 'utility', 'is_active' => true,
            'body' => 'Hi {first_name}', 'placeholder_map' => ['first_name'],
        ]);
        // approved, but Meta's template has two variables and this fills one
        $mismatched = $this->linkedTemplate('Hi {first_name}.', ['first_name'], body: 'Hi {{1}} from {{2}}.', name: 'two_vars');

        $this->actingAs($this->admin);

        foreach ([$pending, $unlinked, $mismatched] as $template) {
            $this->post(route('automation.rules.store'), $this->rulePayload($template))
                ->assertSessionHasErrors('actions.0.template_id');
        }

        $this->assertSame(0, AutomationRule::count());

        // the same message in click mode is fine: nothing is asked of Meta
        $this->post(route('automation.rules.store'), $this->rulePayload($pending, 'click'))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, AutomationRule::count());
    }

    /* ================= settings ================= */

    public function test_test_connection_with_a_bad_token_shows_metas_190_message(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'message' => 'Error validating access token: Session has expired on Monday, 29-Sep-26.',
            'type' => 'OAuthException',
            'code' => 190,
        ]], 401)]);
        $this->configureApi();

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.whatsapp.test'))
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertSame(190, $response->json('code'));
        $this->assertStringContainsString('access token has expired', $response->json('message'));
        $this->assertStringContainsString('Meta said (error 190): Error validating access token', $response->json('message'));
    }

    public function test_test_connection_names_what_is_missing_and_does_not_call_meta(): void
    {
        Http::fake();
        $this->configureApi(wabaId: null);

        $response = $this->actingAs($this->admin)->postJson(route('automation.whatsapp.test'))->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('WhatsApp Business Account ID', $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_test_connection_names_the_number_it_reached(): void
    {
        Http::fake([
            'graph.facebook.com/v26.0/5550001?*' => Http::response([
                'display_phone_number' => '+91 98200 11111', 'verified_name' => 'Shaligram', 'id' => '5550001',
            ]),
            'graph.facebook.com/v26.0/7770001/phone_numbers*' => Http::response(['data' => [['id' => '5550001']]]),
        ]);
        $this->configureApi();

        $response = $this->actingAs($this->admin)->postJson(route('automation.whatsapp.test'))->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertStringContainsString('+91 98200 11111', $response->json('message'));
    }

    public function test_a_phone_number_id_pasted_into_the_token_box_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('automation.whatsapp.update'), [
                'phone_number_id' => '5550001',
                'waba_id' => '7770001',
                'access_token' => '109876543210987',
            ])
            ->assertSessionHasErrors('access_token');

        $this->actingAs($this->admin)
            ->put(route('automation.whatsapp.update'), [
                'phone_number_id' => 'abc-123',
                'access_token' => 'EAAG-secret-token-abcd',
            ])
            ->assertSessionHasErrors('phone_number_id');

        $this->assertNull(Integration::forProvider('whatsapp')->setting('access_token'));
    }

    /* ================= from the lead ================= */

    public function test_the_lead_owner_can_send_one_message_from_the_lead(): void
    {
        $this->fakeMeta();
        $this->configureApi(autoSend: false);   // a person pressing Send does not wait for this

        $template = $this->linkedTemplate('Welcome {first_name}.', ['first_name'], body: 'Welcome {{1}}.');
        $lead = $this->lead(['assigned_to' => $this->tele->id]);

        $this->actingAs($this->tele)
            ->getJson(route('leads.whatsapp.show', $lead))
            ->assertOk()
            ->assertJsonPath('window', 'Window unknown, template required')
            ->assertJsonPath('templates.0.preview', 'Welcome Rahul.')
            ->assertJsonPath('templates.0.by_api', true);

        $this->actingAs($this->tele)
            ->postJson(route('leads.whatsapp.send', $lead), ['template_id' => $template->id])
            ->assertOk()
            ->assertJsonPath('mode', 'api');

        Http::assertSentCount(1);
        $message = MessageLog::sole();
        $this->assertSame('sent', $message->status);
        $this->assertSame($this->tele->id, $message->user_id);
        $this->assertNull($message->rule_id);

        // somebody who cannot open the lead cannot message it either
        $stranger = $this->user('telecaller', 'Sid');
        $this->actingAs($stranger)->getJson(route('leads.whatsapp.show', $lead))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('leads.whatsapp.send', $lead), ['template_id' => $template->id])->assertForbidden();
    }

    /* ================= helpers ================= */

    private function fakeMeta(): void
    {
        $count = 0;

        Http::fake([self::MESSAGES_URL => function () use (&$count) {
            $count++;

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => "wamid.TEST{$count}"]]]);
        }]);
    }

    private function configureApi(bool $apiEnabled = true, bool $autoSend = true, ?string $wabaId = '7770001'): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'phone_number_id' => '5550001',
            'waba_id' => $wabaId,
            'access_token' => 'EAAG'.str_repeat('x', 60),
            'api_enabled' => $apiEnabled,
            'auto_send' => $autoSend,
        ]);
        $integration->save();
    }

    /** @param list<string> $map */
    private function linkedTemplate(
        string $crmBody,
        array $map,
        string $body = '{{1}}: hello {{2}}, see you soon.',
        string $status = 'APPROVED',
        string $name = 'site_visit',
    ): MessageTemplate {
        $meta = WhatsAppTemplate::create([
            'name' => $name, 'language' => 'en', 'status' => $status,
            'category' => 'utility', 'body' => $body,
            'components' => [['type' => 'BODY', 'text' => $body]],
        ]);

        return MessageTemplate::create([
            'name' => ucfirst(str_replace('_', ' ', $name)).' '.$status, 'category' => 'utility', 'is_active' => true,
            'body' => $crmBody, 'placeholder_map' => $map,
            'whatsapp_template_id' => $meta->id,
        ]);
    }

    private function apiRule(string $trigger, MessageTemplate $template, array $triggerConfig = [], array $extra = []): AutomationRule
    {
        return AutomationRule::create([
            'name' => 'WhatsApp by API', 'trigger' => $trigger, 'trigger_config' => $triggerConfig,
            'conditions' => [],
            'actions' => [['type' => 'queue_whatsapp', 'mode' => 'api', 'template_id' => $template->id] + $extra],
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
    }

    private function rulePayload(MessageTemplate $template, string $mode = 'api'): array
    {
        return [
            'name' => 'Site visit message',
            'trigger' => 'stage_changed',
            'trigger_config' => ['stage' => 'site_visit_done'],
            'conditions' => [],
            'actions' => [['type' => 'queue_whatsapp', 'mode' => $mode, 'template_id' => $template->id]],
        ];
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
            'assigned_to' => $this->tele->id,
            'assigned_role' => 'telecaller',
            'stage_changed_at' => now(),
            'created_by' => $this->admin->id,
        ]);
    }

    private function todoFor(Lead $lead): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $this->admin->id,
            'scheduled_at' => now()->addDay(),
            'type' => 'call',
            'status' => 'pending',
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
