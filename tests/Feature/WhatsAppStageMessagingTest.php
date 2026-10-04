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
use App\Services\LeadFollowUpService;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp by API: one approved template, to one lead, when a rule fires or a
 * person presses Send.
 *
 * Everything that reaches 11za is faked with Http::fake(); the queue is `sync`
 * in phpunit.xml, so a dispatched send has run by the time the call returns.
 *
 * @see WhatsAppSender
 * @see SendWhatsAppMessage
 */
class WhatsAppStageMessagingTest extends TestCase
{
    use RefreshDatabase;

    private const SEND_URL = 'https://api.11za.in/apis/template/sendTemplate';

    private const TOKEN = '11za-live-token/abc+123';

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

    public function test_a_stage_change_sends_the_template_with_data_in_stored_order(): void
    {
        $this->fakeElevenZa();
        $this->configureApi();

        // {project} comes first in the body, so {{1}} is the project: the
        // order is the stored map's, not alphabetical and not re-derived
        $template = $this->namedTemplate('{project}: hello {first_name}, see you soon.', ['project', 'first_name']);
        $this->apiRule('stage_changed', $template, ['stage' => 'site_visit_done']);

        $lead = $this->lead(['stage' => 'connected']);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->changeStage($lead->fresh(), 'site_visit_done');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::SEND_URL
            && $request->isJson()
            && $request->hasHeader('Authorization') === false
            && $request->data() === [
                'authToken' => self::TOKEN,
                'name' => 'Rahul Mehta',
                'sendto' => '919876543210',
                'originWebsite' => 'https://shaligram.example',
                'templateName' => 'site_visit',
                'language' => 'en',
                'data' => ['Skyline Residency', 'Rahul'],
            ]);

        $message = MessageLog::sole();
        $this->assertSame('sent', $message->status);
        $this->assertSame('api', $message->mode);
        $this->assertSame('11za-TEST1', $message->provider_message_id);
        $this->assertStringContainsString('11za-TEST1', $message->provider_response);
        $this->assertSame('site_visit', $message->provider_template_name);
        $this->assertSame('en', $message->provider_template_language);
        $this->assertSame(['Skyline Residency', 'Rahul'], $message->params);
        $this->assertNotNull($message->rule_id);
        $this->assertTrue($message->confirmed);
        $this->assertSame('Accepted by 11za (11za-TEST1)', $message->outcome());
    }

    public function test_the_saved_base_url_replaces_the_default_host(): void
    {
        Http::fake(['https://app.11za.in/apis/template/sendTemplate' => Http::response(['messageId' => 'X1'])]);
        $this->configureApi(extra: ['base_url' => 'https://app.11za.in']);

        $template = $this->namedTemplate('Welcome {first_name}.', ['first_name']);
        $this->apiRule('lead_created', $template);
        $lead = $this->lead();
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.11za.in/apis/template/sendTemplate');
        $this->assertSame('sent', MessageLog::sole()->status);
    }

    public function test_the_cooldown_stops_a_second_send(): void
    {
        $this->fakeElevenZa();
        $this->configureApi();

        $template = $this->namedTemplate('{project}: hello {first_name}, see you soon.', ['project', 'first_name']);
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
        $this->fakeElevenZa();
        $this->configureApi();

        $template = $this->namedTemplate('{project}: hello {first_name}, see you soon.', ['project', 'first_name']);
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
        $this->fakeElevenZa();
        $this->configureApi();

        $template = $this->namedTemplate('Welcome {first_name}.', ['first_name']);
        $this->apiRule('lead_created', $template);

        $lead = $this->lead(['mobile_number' => '98765']);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        Http::assertNothingSent();

        $message = MessageLog::sole();
        $this->assertSame('skipped', $message->status);
        $this->assertStringContainsString('no usable mobile number', $message->error);
        $this->assertSame('skipped', AutomationLog::where('action', 'queue_whatsapp')->sole()->result);
    }

    public function test_with_the_api_off_an_api_rule_falls_back_to_click_to_send(): void
    {
        $this->fakeElevenZa();
        $this->configureApi(apiEnabled: false);

        $template = $this->namedTemplate('Welcome {first_name}.', ['first_name']);
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
    }

    public function test_a_rule_that_targets_booking_done_or_lost_sends_without_the_opt_in(): void
    {
        $this->fakeElevenZa();
        $this->configureApi();

        $template = $this->namedTemplate('Thank you {first_name}.', ['first_name']);
        $service = app(LeadFollowUpService::class);
        $this->actingAs($this->admin);

        $number = 9876500000;

        foreach (['booking_done', 'lost'] as $stage) {
            AutomationRule::query()->delete();
            // no `terminal` key: the rule was saved without the opt-in
            $this->apiRule('stage_changed', $template, ['stage' => $stage]);

            $lead = $this->lead(['stage' => 'in_discussion', 'mobile_number' => (string) $number++]);
            $this->todoFor($lead);

            $service->changeStage($lead->fresh(), $stage);

            $this->assertSame('sent', MessageLog::where('lead_id', $lead->id)->sole()->status, $stage);
        }

        Http::assertSentCount(2);
    }

    public function test_a_lead_that_is_booked_or_lost_incidentally_is_messaged_only_when_the_rule_opts_in(): void
    {
        $this->fakeElevenZa();
        $this->configureApi();

        $template = $this->namedTemplate('Welcome {first_name}.', ['first_name']);
        $this->actingAs($this->admin);

        $number = 9876500000;

        // a new-lead rule does not name a stage, so a lead that arrives
        // already booked is the case the opt-in guards against
        foreach (['skip' => 'skipped', 'send' => 'sent'] as $terminal => $expected) {
            AutomationRule::query()->delete();
            $this->apiRule('lead_created', $template, [], ['terminal' => $terminal]);

            $lead = $this->lead(['stage' => 'booking_done', 'mobile_number' => (string) $number++]);
            app(LeadFollowUpService::class)->onLeadCreated($lead);

            $this->assertSame($expected, MessageLog::where('lead_id', $lead->id)->sole()->status, $terminal);
        }

        Http::assertSentCount(1);
    }

    /* ================= failures ================= */

    public function test_a_refusal_fails_once_and_keeps_11zas_raw_response(): void
    {
        Http::fake([self::SEND_URL => Http::response(['status' => 'error', 'message' => 'Template not found'], 400)]);
        $message = $this->sendOneByRule();

        Http::assertSentCount(1);
        $this->assertSame('failed', $message->status);
        $this->assertSame('http 400', $message->error_code);
        $this->assertStringContainsString('Template not found', $message->error);
        $this->assertStringContainsString('Template not found', $message->provider_response);
    }

    public function test_a_success_without_a_recognised_id_is_sent_unconfirmed_and_never_retried(): void
    {
        // an id under a field name the lookup does not guess
        $count = 0;
        Http::fake([self::SEND_URL => function () use (&$count) {
            $count++;

            return Http::response(['status' => 'queued', 'requestRef' => 'R-77']);
        }]);
        $message = $this->sendOneByRule();

        $this->assertSame(1, $count, 'sent once, never retried');
        $this->assertSame('sent', $message->status);
        $this->assertFalse($message->confirmed);
        $this->assertTrue($message->isUnconfirmed());
        $this->assertNull($message->provider_message_id);
        $this->assertNull($message->error, 'advice, not an error');
        $this->assertNull($message->error_code);
        $this->assertStringContainsString('R-77', $message->provider_response);
        $this->assertStringStartsWith('Sent (unconfirmed)', $message->outcome());
        $this->assertStringContainsString('Check the 11za panel before resending', $message->outcome());

        // still in flight for dedupe: the same message to the same number is
        // not sent a second time on the strength of a guess
        $this->sendOneByRule();
        $this->assertSame(1, $count);

        $queue = $this->actingAs($this->admin)->get('/automation?tab=queue')->viewData('page')['props']['queue'];
        $row = collect($queue)->firstWhere('id', $message->id);
        $this->assertTrue($row['unconfirmed']);
        $this->assertStringContainsString('R-77', $row['provider_response']);

        $history = $this->actingAs($this->admin)->getJson(route('leads.whatsapp.show', $message->lead_id))->json('history.0');
        $this->assertTrue($history['unconfirmed']);
        $this->assertStringContainsString('R-77', $history['provider_response']);
    }

    public function test_test_connection_reports_an_unrecognised_success_as_unconfirmed(): void
    {
        Http::fake([self::SEND_URL => Http::response(['status' => 'ok'])]);
        $this->configureApi();
        $template = $this->namedTemplate('Hi {first_name}.', ['first_name']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.whatsapp.test'), ['mobile' => '9820011111', 'template_id' => $template->id])
            ->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertStringStartsWith('Sent (unconfirmed)', $response->json('message'));
        $this->assertStringContainsString('Check the 11za panel before resending', $response->json('message'));
        $this->assertStringContainsString('"status":"ok"', $response->json('response'));
    }

    public function test_an_outage_or_timeout_is_put_back_for_a_retry(): void
    {
        Http::fake([self::SEND_URL => Http::response('Service Unavailable', 503)]);
        $message = $this->sendOneByRule();

        $this->assertSame('queued', $message->status, 'released for the next attempt, not failed');
        $this->assertSame('http 503', $message->error_code);

        Http::fake([self::SEND_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $message->update(['status' => 'queued']);
        SendWhatsAppMessage::dispatch($message->id);

        $message->refresh();
        $this->assertSame('queued', $message->status);
        $this->assertStringContainsString('Could not reach 11za', $message->error);
    }

    /* ================= the token ================= */

    public function test_the_auth_token_is_stored_nowhere_even_when_11za_echoes_it(): void
    {
        // 11za, or a proxy in front of it, echoing the request back in an
        // error — as typed and JSON-escaped
        Http::fake([self::SEND_URL => fn (Request $request) => Http::response([
            'error' => 'bad request',
            'echo' => $request->data(),
            'raw' => $request->body(),
        ], 400)]);
        $this->sendOneByRule();

        Http::fake([self::SEND_URL => fn () => throw new ConnectionException('failed for authToken='.self::TOKEN)]);
        $this->sendOneByRule(mobile: '9876500001');

        $this->assertSame(2, MessageLog::where('status', '!=', 'skipped')->count());

        foreach (['message_logs', 'automation_logs', 'lead_activities', 'failed_jobs', 'alerts'] as $table) {
            $dump = json_encode(DB::table($table)->get(), JSON_UNESCAPED_SLASHES);

            $this->assertStringNotContainsString(self::TOKEN, $dump, "{$table} holds the token");
            $this->assertStringNotContainsString(json_encode(self::TOKEN), $dump, "{$table} holds the escaped token");
            $this->assertStringNotContainsString('11za-live-token', $dump, "{$table} holds part of the token");
        }

        $this->assertStringContainsString('[redacted]', MessageLog::where('error_code', 'http 400')->sole()->provider_response);
    }

    /* ================= rule save ================= */

    public function test_a_message_without_an_11za_name_is_refused_for_api_at_rule_save(): void
    {
        $unnamed = MessageTemplate::create([
            'name' => 'Unnamed', 'category' => 'utility', 'is_active' => true,
            'body' => 'Hi {first_name}', 'placeholder_map' => ['first_name'],
        ]);
        $named = $this->namedTemplate('Hi {first_name}.', ['first_name']);

        $this->actingAs($this->admin);

        $this->post(route('automation.rules.store'), $this->rulePayload($unnamed))
            ->assertSessionHasErrors('actions.0.template_id');
        $this->assertSame(0, AutomationRule::count());

        // the same message in click mode is fine: nothing is asked of 11za
        $this->post(route('automation.rules.store'), $this->rulePayload($unnamed, 'click'))->assertSessionHasNoErrors();
        $this->post(route('automation.rules.store'), $this->rulePayload($named))->assertSessionHasNoErrors();
        $this->assertSame(2, AutomationRule::count());
    }

    public function test_the_11za_name_and_language_are_saved_on_the_message(): void
    {
        $this->actingAs($this->admin)
            ->post(route('automation.templates.store'), [
                'name' => 'Welcome', 'category' => 'utility', 'is_active' => true,
                'body' => 'Hi {first_name}', 'provider_template_name' => ' welcome_v2 ', 'provider_template_language' => 'en',
            ])
            ->assertSessionHasNoErrors();

        $template = MessageTemplate::sole();
        $this->assertSame('welcome_v2', $template->provider_template_name);
        $this->assertSame('en', $template->provider_template_language);

        $this->actingAs($this->admin)
            ->post(route('automation.templates.store'), [
                'name' => 'Bad', 'category' => 'utility', 'body' => 'Hi',
                'provider_template_name' => 'welcome v2', 'provider_template_language' => '',
            ])
            ->assertSessionHasErrors(['provider_template_name', 'provider_template_language']);
    }

    /* ================= settings ================= */

    public function test_test_connection_sends_one_message_to_the_typed_number_and_shows_11zas_answer(): void
    {
        Http::fake([self::SEND_URL => Http::response(['status' => 'success', 'messageId' => 'T-1'])]);
        $this->configureApi();
        $template = $this->namedTemplate('{project}: hello {first_name}.', ['project', 'first_name']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.whatsapp.test'), ['mobile' => '+91 98200-11111', 'template_id' => $template->id])
            ->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertStringContainsString('919820011111', $response->json('message'));
        $this->assertStringContainsString('"messageId":"T-1"', $response->json('response'));

        Http::assertSent(fn (Request $request) => $request['sendto'] === '919820011111'
            && $request['data'] === ['Skyline Residency', 'Rahul']);
        $this->assertSame(0, MessageLog::count(), 'a test is not a message to a lead');
    }

    public function test_test_connection_reports_a_refusal_with_the_raw_response(): void
    {
        Http::fake([self::SEND_URL => Http::response(['message' => 'Invalid authToken'], 401)]);
        $this->configureApi();
        $template = $this->namedTemplate('Hi {first_name}.', ['first_name']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.whatsapp.test'), ['mobile' => '9820011111', 'template_id' => $template->id])
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('HTTP 401', $response->json('message'));
        $this->assertStringContainsString('Invalid authToken', $response->json('response'));
    }

    public function test_test_connection_names_what_is_missing_and_does_not_call_11za(): void
    {
        Http::fake();
        $this->configureApi(extra: ['origin_website' => null]);
        $template = $this->namedTemplate('Hi {first_name}.', ['first_name']);

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.whatsapp.test'), ['mobile' => '9820011111', 'template_id' => $template->id])
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('origin website', $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_a_base_url_that_is_not_11za_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('automation.whatsapp.update'), [
                'auth_token' => self::TOKEN,
                'origin_website' => 'https://shaligram.example',
                'base_url' => 'https://api.11za.in.attacker.example',
            ])
            ->assertSessionHasErrors('base_url');

        $this->actingAs($this->admin)
            ->put(route('automation.whatsapp.update'), [
                'auth_token' => 'two words',
                'origin_website' => 'https://shaligram.example',
                'base_url' => 'https://app.11za.in/',
            ])
            ->assertSessionHasErrors('auth_token')
            ->assertSessionDoesntHaveErrors('base_url');

        $this->assertNull(Integration::forProvider('whatsapp')->setting('auth_token'));
    }

    /* ================= from the lead ================= */

    public function test_the_lead_owner_can_send_one_message_from_the_lead(): void
    {
        $this->fakeElevenZa();
        $this->configureApi(autoSend: false);   // a person pressing Send does not wait for this

        $template = $this->namedTemplate('Welcome {first_name}.', ['first_name']);
        $lead = $this->lead(['assigned_to' => $this->tele->id]);

        $this->actingAs($this->tele)
            ->getJson(route('leads.whatsapp.show', $lead))
            ->assertOk()
            ->assertJsonMissingPath('window')
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

    public function test_an_empty_variable_is_skipped_with_the_lead_field_to_fix(): void
    {
        $this->fakeElevenZa();
        $this->configureApi();

        $template = $this->namedTemplate('Call {owner_name}.', ['owner_name']);

        $unassigned = $this->lead(['assigned_to' => null]);
        $result = app(WhatsAppSender::class)->queueTemplate($unassigned, $template, user: $this->admin);

        $this->assertSame('skipped', $result['result']);
        $this->assertSame(
            'This tag needs the name of the staff member handling the lead, and nobody is assigned to this lead.',
            $result['message']->error,
        );

        $this->project->update(['name' => '']);
        $template = $this->namedTemplate('About {project}.', ['project'], 'project_note');
        $result = app(WhatsAppSender::class)->queueTemplate($this->lead(['mobile_number' => '9876500099']), $template, user: $this->admin);

        $this->assertSame("This tag needs the lead's project, and this lead has none.", $result['message']->error);
        Http::assertNothingSent();
    }

    /* ================= helpers ================= */

    /**
     * 11za's success shape is not documented; this is a guess the client
     * must cope with, not a claim about what 11za sends.
     */
    private function fakeElevenZa(): void
    {
        $count = 0;

        Http::fake([self::SEND_URL => function () use (&$count) {
            $count++;

            return Http::response(['status' => 'success', 'data' => ['messageId' => "11za-TEST{$count}"]]);
        }]);
    }

    private function configureApi(bool $apiEnabled = true, bool $autoSend = true, array $extra = []): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings($extra + [
            'auth_token' => self::TOKEN,
            'origin_website' => 'https://shaligram.example',
            'api_enabled' => $apiEnabled,
            'auto_send' => $autoSend,
        ]);
        $integration->save();
    }

    /** One rule-driven API send of a one-variable message, and the row it wrote. */
    private function sendOneByRule(string $mobile = '9876543210'): MessageLog
    {
        $this->configureApi();

        $template = MessageTemplate::where('provider_template_name', 'site_visit')->first()
            ?? $this->namedTemplate('Welcome {first_name}.', ['first_name']);

        if (! AutomationRule::exists()) {
            $this->apiRule('lead_created', $template);
        }

        $lead = $this->lead(['mobile_number' => $mobile]);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        return MessageLog::where('lead_id', $lead->id)->sole();
    }

    /** @param list<string> $map */
    private function namedTemplate(string $body, array $map, string $name = 'site_visit'): MessageTemplate
    {
        return MessageTemplate::create([
            'name' => ucfirst(str_replace('_', ' ', $name)), 'category' => 'utility', 'is_active' => true,
            'body' => $body, 'placeholder_map' => $map,
            'provider_template_name' => $name, 'provider_template_language' => 'en',
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
