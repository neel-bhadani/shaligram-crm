<?php

namespace Tests\Feature;

use App\Http\Controllers\Webhooks\ElevenZaWebhookController;
use App\Jobs\ProcessWhatsAppLead;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The 11za Customer Webhook, and everything behind it.
 *
 *   the door        the URL is public, so the x-api-key header is the only
 *                   thing between an anonymous POST and a lead. A wrong or
 *                   missing key is a 401 and nothing is queued.
 *
 *   always 200      once the key matches. 11za deactivates a webhook after
 *                   five failed retries, and a bad payload will not improve
 *                   on retry, so problems go to the activity log instead.
 *
 *   idempotency     11za may redeliver; mobileNo + updatedAt (or a hash of the
 *                   event when updatedAt is missing) is one lead.
 *
 * @see ElevenZaWebhookController
 * @see ProcessWhatsAppLead
 */
class ElevenZaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'eleven-za-api-key-0123456789';

    private const URL = '/webhooks/whatsapp/customers';

    private const UPDATED_AT = '2026-09-07T06:00:00.000Z';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:30', 'Asia/Kolkata'));

        $this->owner = User::create([
            'first_name' => 'Tia',
            'last_name' => 'User',
            'email' => 'telecaller@example.test',
            'mobile_number' => '9000000001',
            'role' => 'telecaller',
            'is_active' => true,
            'password' => 'password',
        ]);
        $this->project = Project::create(['name' => 'Alpha']);

        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'api_key' => self::KEY,
            'default_project_id' => $this->project->id,
            'assign_to_user_id' => $this->owner->id,
        ]);
        $integration->is_active = true;
        $integration->save();
    }

    /* ---------------- the door ---------------- */

    public function test_a_wrong_api_key_returns_401_and_queues_nothing(): void
    {
        Queue::fake();

        $this->deliver($this->payload(), key: 'not-our-key')->assertUnauthorized();

        Queue::assertNothingPushed();
        $this->assertSame(0, IntegrationEvent::count());
    }

    public function test_a_missing_api_key_returns_401_and_queues_nothing(): void
    {
        Queue::fake();

        $this->deliver($this->payload(), key: null)->assertUnauthorized();

        Queue::assertNothingPushed();
        $this->assertSame(0, IntegrationEvent::count());
    }

    public function test_the_route_is_public_stateless_and_rate_limited(): void
    {
        $middleware = Route::getRoutes()->getByName('webhooks.whatsapp.customers')->gatherMiddleware();

        $this->assertContains('throttle:webhooks', $middleware);
        $this->assertNotContains('web', $middleware);
        $this->assertNotContains('auth', $middleware);
    }

    public function test_it_is_a_404_while_the_provider_is_not_built(): void
    {
        config(['integrations.providers.whatsapp.built' => false]);

        $this->deliver($this->payload())->assertNotFound();
    }

    /* ---------------- always 200 ---------------- */

    public function test_a_valid_insert_answers_200_and_queues_the_work(): void
    {
        Queue::fake();

        $this->deliver($this->payload())->assertOk();

        Queue::assertPushed(ProcessWhatsAppLead::class, fn (ProcessWhatsAppLead $job) => $job->type === 'insert'
            && $job->data['mobileNo'] === '919820012345');
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badPayloads(): array
    {
        return [
            'unknown type' => [['type' => 'delete', 'data' => ['mobileNo' => '919820012345']]],
            'no type' => [['data' => ['mobileNo' => '919820012345']]],
            'empty mobileNo' => [['type' => 'insert', 'data' => ['name' => 'Asha', 'mobileNo' => '']]],
            'no data' => [['type' => 'insert']],
        ];
    }

    #[DataProvider('badPayloads')]
    public function test_a_bad_payload_answers_200_and_is_logged_failed(array $body): void
    {
        Queue::fake();

        $this->deliver($body)->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame('failed', IntegrationEvent::sole()->result);
    }

    public function test_a_delivery_while_the_integration_is_off_answers_200_and_is_logged_failed(): void
    {
        Queue::fake();
        Integration::forProvider('whatsapp')->update(['is_active' => false]);

        $this->deliver($this->payload())->assertOk();

        Queue::assertNothingPushed();
        $event = IntegrationEvent::sole();
        $this->assertSame('failed', $event->result);
        $this->assertStringContainsString('switched off', $event->message);
    }

    /* ---------------- the import ---------------- */

    public function test_an_insert_creates_a_whatsapp_lead_with_a_pending_follow_up(): void
    {
        $this->deliver($this->payload())->assertOk();

        $lead = Lead::sole();

        $this->assertSame('Asha', $lead->first_name);
        $this->assertSame('Mehta', $lead->last_name);
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame('9820012345', $lead->mobile_number);
        $this->assertSame('11za:9820012345:'.self::UPDATED_AT, $lead->external_id);
        $this->assertSame($this->project->id, $lead->project_id);
        $this->assertSame($this->owner->id, $lead->assigned_to);

        $todo = $lead->pendingTodo;
        $this->assertNotNull($todo, 'a WhatsApp lead arrived with no pending follow-up');
        $this->assertSame('call', $todo->type);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());

        $event = IntegrationEvent::sole();
        $this->assertSame('created', $event->result);
        $this->assertSame($lead->id, $event->lead_id);
    }

    public function test_a_contact_with_no_name_is_called_whatsapp_lead(): void
    {
        $this->deliver($this->payload(data: ['name' => '']))->assertOk();

        $lead = Lead::sole();
        $this->assertSame('WhatsApp', $lead->first_name);
        $this->assertSame('Lead', $lead->last_name);
    }

    public function test_the_same_payload_twice_is_a_duplicate_not_a_second_lead(): void
    {
        $this->deliver($this->payload())->assertOk();
        $this->deliver($this->payload())->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame(['created', 'duplicate'], IntegrationEvent::orderBy('id')->pluck('result')->all());
    }

    public function test_the_same_number_with_a_new_updated_at_is_a_repeat_enquiry(): void
    {
        $this->deliver($this->payload())->assertOk();
        $this->deliver($this->payload(data: ['updatedAt' => '2026-09-08T06:00:00.000Z']))->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame(['created', 'repeat_enquiry'], IntegrationEvent::orderBy('id')->pluck('result')->all());
    }

    public function test_without_updated_at_an_identical_redelivery_is_still_a_duplicate(): void
    {
        $body = $this->payload(data: ['updatedAt' => null]);

        $this->deliver($body)->assertOk();
        $this->deliver($body)->assertOk();

        $this->assertMatchesRegularExpression('/^11za:9820012345:[0-9a-f]{16}$/', Lead::sole()->external_id);
        $this->assertSame(['created', 'duplicate'], IntegrationEvent::orderBy('id')->pluck('result')->all());
    }

    public function test_without_updated_at_a_different_event_is_not_a_duplicate(): void
    {
        $this->deliver($this->payload(data: ['updatedAt' => null]))->assertOk();
        $this->deliver($this->payload(data: ['updatedAt' => null, 'tags' => ['site-visit']]))->assertOk();

        $events = IntegrationEvent::orderBy('id')->get();

        // a new enquiry from a number already on the project: a repeat, not a redelivery
        $this->assertSame(['created', 'repeat_enquiry'], $events->pluck('result')->all());
        $this->assertNotSame($events[0]->external_id, $events[1]->external_id);
        $this->assertSame(1, Lead::count());
    }

    public function test_an_update_for_an_unknown_number_creates_the_lead(): void
    {
        $this->deliver($this->payload(type: 'update'))->assertOk();

        $this->assertSame('whatsapp', Lead::sole()->source);
        $this->assertSame('created', IntegrationEvent::sole()->result);
    }

    public function test_an_update_for_a_known_number_changes_nothing_and_is_logged(): void
    {
        $this->deliver($this->payload())->assertOk();
        $lead = Lead::sole();

        $this->deliver($this->payload(type: 'update', data: [
            'name' => 'Someone Else',
            'updatedAt' => '2026-09-08T06:00:00.000Z',
        ]))->assertOk();

        $this->assertSame(1, Lead::count());
        $this->assertSame('Asha', $lead->fresh()->first_name);

        $event = IntegrationEvent::latest('id')->first();
        $this->assertSame('update_received', $event->result);
        $this->assertSame($lead->id, $event->lead_id);
    }

    public function test_a_mobile_number_with_fewer_than_ten_digits_is_logged_failed(): void
    {
        $this->deliver($this->payload(data: ['mobileNo' => '12345']))->assertOk();

        $this->assertSame(0, Lead::count());
        $this->assertSame('failed', IntegrationEvent::sole()->result);
    }

    /* ---------------- fixtures ---------------- */

    /**
     * A Customer Webhook body, shaped the way 11za sends it.
     *
     * @param  array<string, mixed>  $data  overrides; a null value removes the key
     * @return array<string, mixed>
     */
    private function payload(string $type = 'insert', array $data = []): array
    {
        $data = array_filter(array_merge([
            'name' => 'Asha Mehta',
            'mobileNo' => '919820012345',
            'updatedAt' => self::UPDATED_AT,
            'masterstatus' => 'New',
        ], $data), fn ($value) => $value !== null);

        return ['type' => $type, 'data' => $data, 'changedFields' => []];
    }

    private function deliver(array $body, ?string $key = self::KEY)
    {
        return $this->postJson(self::URL, $body, $key === null ? [] : ['x-api-key' => $key]);
    }
}
