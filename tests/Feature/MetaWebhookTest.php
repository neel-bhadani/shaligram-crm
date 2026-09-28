<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Jobs\ProcessMetaLead;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\IncomingLeadService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * The two routes Meta calls, and everything that happens behind them.
 *
 * Four things are being protected here, and they are not the same thing:
 *
 *   the door        the endpoint is public, so the signature is the only thing
 *                   standing between an anonymous POST and a lead in the CRM.
 *                   An unsigned or wrongly signed body is a 403 and nothing is
 *                   queued.
 *
 *   the speed       Meta gives a webhook a few seconds and redelivers anything
 *                   slower. The route answers 200 and queues; it must never do
 *                   the Graph call inline, because every timeout would arrive
 *                   back as a second copy of the same lead.
 *
 *   idempotency     Meta retries anyway. The same leadgen_id twice is one lead,
 *                   and the same phone number on the same project is a repeat
 *                   enquiry rather than a second row on somebody's call list.
 *
 *   the invariant   Lead::open()->doesntHave('pendingTodo')->count() === 0.
 *                   Scheduling is manual everywhere else in this application,
 *                   so a lead arriving from a machine has nobody to type a date
 *                   and would land with no pending task at all — invisible on
 *                   every list in the CRM. It is asserted after every import
 *                   below.
 *
 * @see MetaWebhookController
 * @see ProcessMetaLead
 * @see IncomingLeadService
 */
class MetaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret-from-meta';

    private const TOKEN = 'page-access-token-from-meta';

    private const URL = '/webhooks/facebook/leads';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:30', 'Asia/Kolkata'));

        $this->owner = $this->user('telecaller', 'Tia');
        $this->project = Project::create(['name' => 'Alpha']);

        $this->connect();
    }

    /* ---------------- the handshake ---------------- */

    public function test_the_handshake_echoes_the_challenge_back_as_plain_text(): void
    {
        $verifyToken = Integration::forProvider('facebook')->setting('verify_token');

        $response = $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => $verifyToken,
            'hub.challenge' => '1158201444',
        ]));

        $response->assertOk();
        // exactly the bytes, no JSON wrapper and no trailing newline: Meta
        // compares the body against the challenge it sent
        $this->assertSame('1158201444', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_the_handshake_is_refused_when_the_token_does_not_match(): void
    {
        $response = $this->get(self::URL.'?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'not-the-stored-token',
            'hub.challenge' => '1158201444',
        ]));

        $response->assertForbidden();
        $this->assertStringNotContainsString('1158201444', $response->getContent());
    }

    /** An unconfigured provider must not accidentally verify against an empty token. */
    public function test_the_handshake_is_refused_when_nothing_is_configured(): void
    {
        Integration::query()->delete();

        $this->get(self::URL.'?'.http_build_query([
            'hub.verify_token' => '',
            'hub.challenge' => '1158201444',
        ]))->assertForbidden();
    }

    /* ---------------- the door ---------------- */

    public function test_an_invalid_signature_returns_403_and_queues_nothing(): void
    {
        Queue::fake();

        $this->deliver($this->payload(), signature: 'sha256='.str_repeat('a', 64))
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(0, Lead::count());
    }

    public function test_a_signature_made_with_the_wrong_secret_returns_403(): void
    {
        Queue::fake();

        $this->deliver($this->payload(), secret: 'someone-elses-secret')->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_missing_signature_header_returns_403(): void
    {
        Queue::fake();

        $body = json_encode($this->payload());

        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /** A body altered after signing must not verify — the HMAC is over the raw bytes. */
    public function test_a_tampered_body_returns_403(): void
    {
        Queue::fake();

        $signed = json_encode($this->payload('111'));
        $sent = json_encode($this->payload('222'));

        $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $signed, self::SECRET),
        ], $sent)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_delivery_with_no_app_secret_stored_returns_403(): void
    {
        $integration = Integration::forProvider('facebook');
        $integration->mergeSettings(['app_secret' => '']);
        $integration->save();

        $this->deliver($this->payload())->assertForbidden();
    }

    /* ---------------- answer first, work later ---------------- */

    public function test_a_signed_delivery_answers_200_and_queues_the_work(): void
    {
        Queue::fake();

        $this->deliver($this->payload('9876543210001'))->assertOk();

        Queue::assertPushed(
            ProcessMetaLead::class,
            fn (ProcessMetaLead $job) => $job->provider === 'facebook'
                && $job->leadgenId === '9876543210001'
                // a real delivery carries no answers; the job fetches them
                && $job->fieldData === null,
        );
    }

    public function test_one_delivery_carrying_several_leads_queues_one_job_each(): void
    {
        Queue::fake();

        $this->deliver([
            'object' => 'page',
            'entry' => [
                ['changes' => [
                    ['field' => 'leadgen', 'value' => ['leadgen_id' => 'aaa']],
                    ['field' => 'leadgen', 'value' => ['leadgen_id' => 'bbb']],
                ]],
                ['changes' => [
                    // the same id twice inside one delivery is still one lead
                    ['field' => 'leadgen', 'value' => ['leadgen_id' => 'bbb']],
                ]],
            ],
        ])->assertOk();

        Queue::assertPushed(ProcessMetaLead::class, 2);
    }

    public function test_a_change_that_is_not_a_leadgen_is_ignored(): void
    {
        Queue::fake();

        $this->deliver([
            'object' => 'page',
            'entry' => [['changes' => [
                ['field' => 'feed',    'value' => ['post_id' => '1']],
                ['field' => 'leadgen', 'value' => []],
            ]]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * The routes are outside the web group entirely: no session, no cookie, no
     * CSRF token to carry — and no auth to pass. That is a structural fact and
     * it is asserted structurally, because a route quietly gaining the `web`
     * group later would start answering Meta with a 419 nobody would think to
     * look for.
     */
    public function test_the_webhook_routes_are_public_stateless_and_rate_limited(): void
    {
        foreach (['webhooks.meta.verify', 'webhooks.meta.handle'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('throttle:webhooks', $middleware, "$name is not rate limited");
            $this->assertNotContains('web', $middleware, "$name is in the web group");
            $this->assertNotContains('auth', $middleware, "$name is behind auth");
            $this->assertNotContains('role:admin', $middleware, "$name is behind role:admin");
        }
    }

    /** Only the platforms that are actually built have a URL at all. */
    public function test_a_provider_that_is_not_built_is_a_404(): void
    {
        foreach (['instagram', 'whatsapp', 'website'] as $provider) {
            $this->post("/webhooks/$provider/leads")->assertNotFound();
            $this->get("/webhooks/$provider/leads")->assertNotFound();
        }
    }

    /* ---------------- the import ---------------- */

    public function test_a_signed_delivery_creates_a_lead_with_a_pending_follow_up(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel Bhadani']],
            ['name' => 'phone_number', 'values' => ['+91 95127-79297']],
            ['name' => 'email',        'values' => ['neel@example.test']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $lead = Lead::firstOrFail();

        $this->assertSame('Neel', $lead->first_name);
        $this->assertSame('Bhadani', $lead->last_name);
        $this->assertSame('neel@example.test', $lead->email);
        $this->assertSame('facebook', $lead->source);
        $this->assertSame('fresh', $lead->stage);
        $this->assertSame('lead-1', $lead->external_id);
        $this->assertSame($this->project->id, $lead->project_id);
        $this->assertSame($this->owner->id, $lead->assigned_to);
        $this->assertSame('telecaller', $lead->assigned_role);
        // nobody typed it in, which is what marks a lead as machine-created
        $this->assertNull($lead->created_by);

        // the follow-up: due now, on the configured user's Due today list
        $todo = $lead->pendingTodo;

        $this->assertNotNull($todo, 'an imported lead arrived with no pending follow-up');
        $this->assertSame($this->owner->id, $todo->assigned_to);
        $this->assertSame('call', $todo->type);
        $this->assertTrue($todo->scheduled_at->isToday());
        $this->assertSame(1, Todo::dueToday()->where('assigned_to', $this->owner->id)->count());

        // the rule the whole To-do page rests on
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());

        // and the admin can see that it happened
        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('facebook', $event->provider);
        $this->assertSame('created', $event->result);
        $this->assertSame('lead-1', $event->external_id);
        $this->assertSame($lead->id, $event->lead_id);

        $this->assertNotNull(Integration::forProvider('facebook')->last_received_at);
    }

    public function test_the_graph_call_uses_the_stored_page_token(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel Bhadani']],
            ['name' => 'phone_number', 'values' => ['9512779297']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/lead-1')
            && $request['access_token'] === self::TOKEN
            && str_contains((string) $request['fields'], 'field_data'));
    }

    /* ---------------- idempotency ---------------- */

    public function test_a_repeated_leadgen_id_does_not_create_a_second_lead(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel Bhadani']],
            ['name' => 'phone_number', 'values' => ['+919512779297']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();
        // Meta decided the first delivery timed out and sent it again
        $this->deliver($this->payload('lead-1'))->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());

        $results = IntegrationEvent::orderBy('id')->pluck('result')->all();
        $this->assertSame(['created', 'duplicate'], $results);
    }

    /** A redelivery of a lead that was imported and then deleted must not come back. */
    public function test_a_repeated_leadgen_id_for_a_deleted_lead_is_still_a_duplicate(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel Bhadani']],
            ['name' => 'phone_number', 'values' => ['9512779297']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();
        Lead::firstOrFail()->delete();

        $this->deliver($this->payload('lead-1'))->assertOk();

        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Lead::withTrashed()->count());
        $this->assertSame('duplicate', IntegrationEvent::latest('id')->first()->result);
    }

    public function test_a_number_already_on_this_project_is_a_repeat_enquiry_not_a_second_lead(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel Bhadani']],
            ['name' => 'phone_number', 'values' => ['9512779297']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        // a different advert, a new leadgen_id, the same person
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel B']],
            ['name' => 'phone_number', 'values' => ['+91 9512779297']],
        ]);

        $this->deliver($this->payload('lead-2'))->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());

        $event = IntegrationEvent::latest('id')->first();
        $this->assertSame('repeat_enquiry', $event->result);
        $this->assertStringContainsString('repeat enquiry', $event->message);
    }

    /**
     * The checks in IncomingLeadService are a read then a write. The index is
     * what holds when two deliveries race past them — and with mobile numbers
     * no longer unique, it is the only one left.
     */
    public function test_the_database_itself_refuses_a_second_lead_with_the_same_external_id(): void
    {
        $lead = fn (string $mobile) => Lead::create([
            'first_name' => 'Neel', 'last_name' => 'Bhadani', 'mobile_number' => $mobile,
            'project_id' => $this->project->id, 'source' => 'facebook', 'stage' => 'fresh',
            'external_id' => 'lead-1',
        ]);

        $lead('9512779297');

        $this->expectException(UniqueConstraintViolationException::class);

        $lead('9000000001');
    }

    /* ---------------- the field names nobody controls ---------------- */

    public function test_separate_first_and_last_name_fields_are_used_when_the_form_asks_for_them(): void
    {
        $this->fakeGraph([
            ['name' => 'first_name',   'values' => ['Neel']],
            ['name' => 'last_name',    'values' => ['Bhadani']],
            ['name' => 'phone_number', 'values' => ['9512779297']],
            ['name' => 'email',        'values' => ['neel@example.test']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $lead = Lead::firstOrFail();
        $this->assertSame('Neel', $lead->first_name);
        $this->assertSame('Bhadani', $lead->last_name);
    }

    public function test_a_full_name_is_split_on_the_first_space(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Bhavesh Kumar Bhatt']],
            ['name' => 'phone_number', 'values' => ['9512779297']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $lead = Lead::firstOrFail();
        $this->assertSame('Bhavesh', $lead->first_name);
        $this->assertSame('Kumar Bhatt', $lead->last_name);
        $this->assertSame('Bhavesh Kumar Bhatt', $lead->full_name);
    }

    public function test_a_one_word_name_renders_without_a_trailing_space(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel']],
            ['name' => 'phone_number', 'values' => ['9512779297']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $this->assertSame('Neel', Lead::firstOrFail()->full_name);
    }

    public function test_an_unrecognised_question_is_logged_and_the_lead_is_still_created(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',     'values' => ['Neel Bhadani']],
            ['name' => 'phone_number',  'values' => ['9512779297']],
            ['name' => 'preferred_bhk', 'values' => ['3 BHK']],
            ['name' => 'budget',        'values' => ['80L']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame('created', IntegrationEvent::firstOrFail()->result);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /** Ads Manager suffixes question names on some accounts. */
    public function test_a_suffixed_field_name_is_still_recognised(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name_en_US',    'values' => ['Neel Bhadani']],
            ['name' => 'phone_number_en_US', 'values' => ['9512779297']],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $this->assertSame('9512779297', Lead::firstOrFail()->mobile_number);
    }

    /* ---------------- the phone number ---------------- */

    #[DataProvider('phoneNumbers')]
    public function test_the_phone_number_is_stripped_to_the_last_ten_digits(string $sent): void
    {
        $this->fakeGraph([
            ['name' => 'full_name',    'values' => ['Neel Bhadani']],
            ['name' => 'phone_number', 'values' => [$sent]],
        ]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $this->assertSame('9512779297', Lead::firstOrFail()->mobile_number);
    }

    public static function phoneNumbers(): array
    {
        return [
            'bare' => ['9512779297'],
            'country code' => ['+919512779297'],
            'spaced' => ['+91 95127 79297'],
            'hyphenated' => ['+91-95127-79297'],
            'double zero' => ['00919512779297'],
        ];
    }

    /* ---------------- the failures an admin has to see ---------------- */

    public function test_a_lead_with_no_usable_phone_number_is_logged_as_failed(): void
    {
        $this->fakeGraph([
            ['name' => 'full_name', 'values' => ['Neel Bhadani']],
            ['name' => 'email',     'values' => ['neel@example.test']],
        ]);

        $this->deliverThenProcess('lead-1');

        $this->assertSame(0, Lead::count());

        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('failed', $event->result);
        $this->assertSame('lead-1', $event->external_id);
        $this->assertStringContainsString('phone number', $event->message);
    }

    public function test_an_expired_page_token_is_logged_with_metas_own_message(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Error validating access token: Session has expired.'],
        ], 401)]);

        $this->deliverThenProcess('lead-1');

        $this->assertSame(0, Lead::count());

        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('failed', $event->result);
        $this->assertStringContainsString('Session has expired', $event->message);
    }

    /**
     * Signed, so it is Meta — but switched off. Recorded rather than dropped:
     * "Meta is delivering and we are ignoring it" is exactly the state an admin
     * needs to be able to see.
     */
    public function test_a_delivery_while_the_integration_is_off_is_logged_and_not_imported(): void
    {
        Integration::forProvider('facebook')->update(['is_active' => false]);

        $this->deliver($this->payload('lead-1'))->assertOk();

        $this->assertSame(0, Lead::count());

        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('failed', $event->result);
        $this->assertStringContainsString('switched off', $event->message);
    }

    /* ---------------- fixtures ---------------- */

    /** A configured, switched-on Facebook integration. */
    private function connect(): Integration
    {
        $integration = Integration::forProvider('facebook');

        $integration->mergeSettings([
            'page_access_token' => self::TOKEN,
            'app_secret' => self::SECRET,
            'page_id' => '102938475600',
            'default_project_id' => $this->project->id,
            'assign_to_user_id' => $this->owner->id,
        ]);

        $integration->is_active = true;
        $integration->save();
        $integration->ensureVerifyToken();

        return $integration;
    }

    /** Meta's answer to the Graph call the job makes. */
    private function fakeGraph(array $fieldData): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => 'lead-1',
            'created_time' => now()->toIso8601String(),
            'field_data' => $fieldData,
        ])]);
    }

    /** One leadgen notification, shaped the way Meta sends it. */
    private function payload(string $leadgenId = 'lead-1'): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => '102938475600',
                'time' => now()->timestamp,
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => $leadgenId,
                        'page_id' => '102938475600',
                        'form_id' => '556677',
                    ],
                ]],
            ]],
        ];
    }

    /**
     * POST the payload the way Meta does: a raw JSON body with an HMAC of those
     * exact bytes in the header. The body is built here rather than by the test
     * client because the signature is over what goes on the wire.
     */
    private function deliver(array $payload, ?string $secret = null, ?string $signature = null)
    {
        $body = json_encode($payload);

        return $this->call('POST', self::URL, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature
                ?? 'sha256='.hash_hmac('sha256', $body, $secret ?? self::SECRET),
        ], $body);
    }

    /**
     * A delivery whose import is going to fail, run the way production runs it.
     *
     * The endpoint answers 200 and queues; the job fails afterwards, out of
     * band. Both halves are asserted here because the split is the point —
     * a lead the importer cannot use must not turn into a non-2xx, which Meta
     * would read as a failed delivery and redeliver for hours.
     *
     * The job rethrows what it failed on so the queue can retry it and then
     * park it in failed_jobs. The row it wrote before rethrowing is what the
     * admin reads on the Integrations page, and is what these tests are about.
     */
    private function deliverThenProcess(string $leadgenId): void
    {
        Queue::fake();

        $this->deliver($this->payload($leadgenId))->assertOk();

        Queue::assertPushed(ProcessMetaLead::class);

        try {
            // dispatchNow rather than dispatchSync: the queue is faked above, and
            // dispatchSync goes through the queue — which would record the job a
            // second time instead of running it
            app(Dispatcher::class)->dispatchNow(new ProcessMetaLead('facebook', $leadgenId));
        } catch (Throwable) {
            // expected: see above
        }
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'User',
            'email' => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
