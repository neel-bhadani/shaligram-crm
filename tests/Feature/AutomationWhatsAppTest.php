<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadFollowUpService;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp: the link that works today, and the API that does not yet.
 *
 * Three things are being pinned down.
 *
 * THE LINK. wa.me accepts the country code and ten digits and nothing else — a
 * single space, dash or bracket and it opens WhatsApp on nobody. The number
 * arrives here typed by people and imported from Facebook, so the formatting
 * has to survive every shape a phone number gets written in.
 *
 * "OPENED", NOT "SENT". Click-to-send hands the text to WhatsApp and that is
 * the last this application hears of it. Whether the user pressed send is not
 * observable, and a log that claimed otherwise would be worthless as a record
 * of what customers received.
 *
 * NOTHING IS SENT AUTOMATICALLY. A rule may put a message in the queue. It may
 * not send one, auto-send is off, and it cannot be switched on while there are
 * no credentials — a request that tries gets the refusal, not just a hidden
 * toggle.
 *
 * @see \App\Services\WhatsApp\TemplateRenderer
 * @see \App\Services\WhatsApp\WhatsAppSender
 */
class AutomationWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tele;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-08 11:00', 'Asia/Kolkata'));

        $this->admin   = $this->user('admin', 'Ann');
        $this->tele    = $this->user('telecaller', 'Tara');
        $this->project = Project::create(['name' => 'Skyline Residency']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ================= the link ================= */

    public function test_the_click_to_send_url_for_a_lead_stored_as_9876543210(): void
    {
        $url = app(TemplateRenderer::class)->clickUrl('9876543210', 'Hello Rahul, welcome!');

        $this->assertSame(
            'https://wa.me/919876543210?text=Hello%20Rahul%2C%20welcome%21',
            $url,
        );
    }

    public function test_the_number_survives_every_way_a_person_writes_it(): void
    {
        $renderer = app(TemplateRenderer::class);

        foreach ([
            '9876543210',
            '+91 98765 43210',
            '(98765) 43210',
            '98765-43210',
            '09876543210',
            '+91-98765-43210',
        ] as $written) {
            $this->assertSame('919876543210', $renderer->waNumber($written), "from: $written");
        }

        // and refuses what it cannot make a number out of, rather than building
        // a link that opens WhatsApp on a stranger
        $this->assertNull($renderer->waNumber('98765'));
        $this->assertNull($renderer->waNumber(''));
        $this->assertNull($renderer->waNumber(null));
        $this->assertNull($renderer->clickUrl('98765', 'Hello'));
    }

    public function test_a_space_is_encoded_as_a_percent_twenty_not_a_plus(): void
    {
        $url = app(TemplateRenderer::class)->clickUrl('9876543210', 'two words');

        $this->assertStringContainsString('two%20words', $url);
        $this->assertStringNotContainsString('+', $url, 'a "+" would show up as a literal plus in the message');
    }

    /* ================= placeholders ================= */

    public function test_placeholders_are_filled_in_from_the_lead(): void
    {
        $lead = $this->lead(['stage' => 'site_visit_done', 'assigned_to' => $this->tele->id]);

        $rendered = app(TemplateRenderer::class)->render(
            'Hi {first_name}, thank you for visiting {project}. {owner_name} ({owner_phone}) will call you. '
            . 'Full name: {lead_name}. Stage: {stage}.',
            $lead->fresh()->load('project', 'owner'),
        );

        $this->assertStringContainsString('Hi Rahul,', $rendered);
        $this->assertStringContainsString('Skyline Residency', $rendered);
        $this->assertStringContainsString('Tara User', $rendered);
        $this->assertStringContainsString('Site visit done', $rendered);
        $this->assertStringContainsString('Rahul Mehta', $rendered);
        $this->assertStringNotContainsString('{', $rendered, 'nothing was left unreplaced');
    }

    public function test_the_meta_numbering_is_stored_when_the_template_is_saved(): void
    {
        $this->actingAs($this->admin)
            ->post(route('automation.templates.store'), [
                'name'      => 'Welcome',
                'category'  => 'utility',
                'body'      => 'Hi {first_name}, welcome to {project}. {first_name}, we will call you soon.',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $template = MessageTemplate::firstOrFail();

        // first appearance wins, and a repeat does not get a second number —
        // which is exactly what Meta expects
        $this->assertSame(['first_name', 'project'], $template->placeholder_map);

        $this->assertSame(
            'Hi {{1}}, welcome to {{2}}. {{1}}, we will call you soon.',
            app(TemplateRenderer::class)->toMetaBody($template->body, $template->placeholder_map),
        );
    }

    /* ================= queue, not send ================= */

    public function test_a_rule_queues_a_message_and_does_not_send_it(): void
    {
        Http::fake();   // if anything tried to send, this would record it

        $template = MessageTemplate::create([
            'name' => 'Welcome', 'category' => 'utility', 'is_active' => true,
            'body' => 'Namaste {first_name}, thank you for visiting {project}.',
            'placeholder_map' => ['first_name', 'project'],
        ]);

        AutomationRule::create([
            'name' => 'Thank a walk-in', 'trigger' => 'lead_created',
            'conditions' => [['field' => 'source', 'value' => 'walk_in']],
            'actions' => [['type' => 'queue_whatsapp', 'template_id' => $template->id]],
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $lead = $this->lead(['assigned_to' => $this->tele->id]);
        $this->todoFor($lead);

        $this->actingAs($this->admin);
        app(LeadFollowUpService::class)->onLeadCreated($lead);

        $message = MessageLog::firstOrFail();

        $this->assertSame('queued', $message->status);
        $this->assertNull($message->sent_at);
        $this->assertNull($message->user_id, 'nobody has picked it up yet');
        $this->assertSame('919876543210', $message->to_number);
        $this->assertSame('Namaste Rahul, thank you for visiting Skyline Residency.', $message->body);

        Http::assertNothingSent();
    }

    public function test_opening_a_queued_message_records_opened_and_never_sent(): void
    {
        $message = $this->queuedMessage();

        $response = $this->actingAs($this->admin)
            ->postJson(route('automation.messages.open', $message))
            ->assertOk();

        $this->assertSame(
            'https://wa.me/919876543210?text=Namaste%20Rahul',
            $response->json('url'),
        );

        $message->refresh();
        $this->assertSame('opened', $message->status);
        $this->assertSame('click', $message->mode);
        $this->assertSame($this->admin->id, $message->user_id);
        $this->assertNotNull($message->sent_at);
        $this->assertNotSame('sent', $message->status, 'we cannot know whether they pressed send');
    }

    public function test_a_message_can_be_cancelled_and_keeps_its_row(): void
    {
        $message = $this->queuedMessage();

        $this->actingAs($this->admin)
            ->post(route('automation.messages.cancel', $message))
            ->assertRedirect();

        $this->assertSame('cancelled', $message->fresh()->status);
        $this->assertSame(1, MessageLog::count(), 'the record survives the decision not to send');
    }

    /* ================= the API that is not configured ================= */

    public function test_an_unconfigured_api_says_so_rather_than_failing_silently(): void
    {
        Http::fake();

        $message = $this->queuedMessage();

        $this->assertFalse(app(WhatsAppSender::class)->isConfigured());

        $this->actingAs($this->admin)
            ->post(route('automation.messages.send', $message))
            ->assertRedirect()
            ->assertSessionHas('error', WhatsAppSender::NOT_CONFIGURED);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame(WhatsAppSender::NOT_CONFIGURED, $message->error);
        $this->assertStringContainsString('WhatsApp Business Platform', $message->error);
        $this->assertStringContainsString('not the same as the free WhatsApp Business app', $message->error);

        Http::assertNothingSent();
    }

    public function test_auto_send_cannot_be_switched_on_without_credentials(): void
    {
        $this->actingAs($this->admin)
            ->put(route('automation.whatsapp.update'), [
                'phone_number_id' => '',
                'access_token'    => '',
                'auto_send'       => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', WhatsAppSender::NOT_CONFIGURED);

        $this->assertFalse(app(WhatsAppSender::class)->autoSends());
    }

    public function test_auto_send_is_off_even_once_credentials_exist(): void
    {
        $this->actingAs($this->admin)
            ->put(route('automation.whatsapp.update'), [
                'phone_number_id' => '123456789',
                'access_token'    => 'EAAG-secret-token-abcd',
                'auto_send'       => false,
            ])
            ->assertSessionHasNoErrors();

        $sender = app(WhatsAppSender::class);

        $this->assertTrue($sender->isConfigured());
        $this->assertFalse($sender->autoSends(), 'configured is not the same as switched on');
    }

    public function test_the_access_token_never_reaches_the_browser(): void
    {
        $this->actingAs($this->admin)->put(route('automation.whatsapp.update'), [
            'phone_number_id' => '123456789',
            'access_token'    => 'EAAG-secret-token-abcd',
            'auto_send'       => false,
        ]);

        /*
         | Encrypted at rest. Read straight off the connection rather than
         | through the model — the `encrypted:array` cast decrypts on read, so
         | Integration::value('settings') would hand back the token in the
         | clear and the assertion would be testing nothing.
         */
        $stored = DB::table('integrations')->where('provider', 'whatsapp')->value('settings');
        $this->assertStringNotContainsString('EAAG-secret-token-abcd', (string) $stored);

        $page = $this->actingAs($this->admin)->get('/automation');
        $page->assertOk();
        $page->assertDontSee('EAAG-secret-token-abcd', false);

        $props = $page->viewData('page')['props'];
        $this->assertSame('••••••••abcd', $props['whatsapp']['access_token_tail']);
        $this->assertArrayNotHasKey('access_token', $props['whatsapp']);
    }

    public function test_editing_the_phone_id_does_not_blank_the_token(): void
    {
        $this->actingAs($this->admin)->put(route('automation.whatsapp.update'), [
            'phone_number_id' => '111', 'access_token' => 'EAAG-first-token', 'auto_send' => false,
        ]);

        // the form ships an empty token box, because it cannot show the real one
        $this->actingAs($this->admin)->put(route('automation.whatsapp.update'), [
            'phone_number_id' => '222', 'access_token' => '', 'auto_send' => false,
        ]);

        $integration = Integration::forProvider('whatsapp');
        $this->assertSame('222', $integration->setting('phone_number_id'));
        $this->assertSame('EAAG-first-token', $integration->setting('access_token'));
    }

    /* ================= the door ================= */

    public function test_the_queue_and_templates_are_admin_only(): void
    {
        $message = $this->queuedMessage();

        $this->actingAs($this->tele)->postJson(route('automation.messages.open', $message))->assertForbidden();
        $this->actingAs($this->tele)->post(route('automation.messages.send', $message))->assertForbidden();
        $this->actingAs($this->tele)->post(route('automation.templates.store'), [
            'name' => 'x', 'category' => 'utility', 'body' => 'x',
        ])->assertForbidden();
        $this->actingAs($this->tele)->put(route('automation.whatsapp.update'), [])->assertForbidden();
    }

    /* ================= helpers ================= */

    private function queuedMessage(): MessageLog
    {
        $lead = $this->lead(['assigned_to' => $this->tele->id]);

        return MessageLog::create([
            'lead_id'   => $lead->id,
            'mode'      => 'click',
            'to_number' => '919876543210',
            'body'      => 'Namaste Rahul',
            'status'    => 'queued',
        ]);
    }

    private function lead(array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'first_name'       => 'Rahul',
            'last_name'        => 'Mehta',
            'mobile_number'    => '9876543210',
            'project_id'       => $this->project->id,
            'source'           => 'walk_in',
            'stage'            => 'fresh',
            'assigned_role'    => 'telecaller',
            'stage_changed_at' => now(),
            'created_by'       => $this->admin->id,
        ]);
    }

    private function todoFor(Lead $lead): Todo
    {
        return Todo::create([
            'lead_id'      => $lead->id,
            'assigned_to'  => $lead->assigned_to,
            'created_by'   => $this->admin->id,
            'scheduled_at' => now()->addDay(),
            'type'         => 'call',
            'status'       => 'pending',
        ]);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name'    => $first,
            'last_name'     => 'User',
            'email'         => strtolower($first) . '@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role'          => $role,
            'is_active'     => true,
            'password'      => 'password',
        ]);
    }
}
