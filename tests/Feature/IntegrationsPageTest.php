<?php

namespace Tests\Feature;

use App\Http\Controllers\IntegrationController;
use App\Http\Requests\IntegrationSettingsRequest;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Integrations page: the door, the secrets, and the button that proves the
 * whole path works before Meta approval lands.
 *
 * Three things are being protected here:
 *
 *   the door      every route is admin-only, and typing the URL is not a way
 *                 around it. The hidden sidebar link is presentation;
 *                 `role:admin` on the group is the refusal.
 *
 *   the secrets   the page access token and the app secret are encrypted at
 *                 rest and never reach the browser. `settings` decrypts on
 *                 read, so a `$integration->toArray()` anywhere in the
 *                 controller would put a live token in the page source — the
 *                 masking tests below are what would catch that.
 *
 *   the invariant Lead::open()->doesntHave('pendingTodo')->count() === 0, after
 *                 a test lead exactly as after a real one. The button runs the
 *                 same job the webhook queues, so if it did not hold here it
 *                 would not hold in production either.
 *
 * @see IntegrationController
 * @see IntegrationSettingsRequest
 */
class IntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAG-page-access-token-1234';

    private const SECRET = 'app-secret-abcd';

    private User $admin;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:30', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->owner = $this->user('telecaller', 'Tia');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- the door ---------------- */

    public function test_a_non_admin_gets_403_on_every_integrations_route(): void
    {
        foreach (['telecaller', 'salesperson'] as $role) {
            $staff = $this->user($role, ucfirst($role));

            $this->actingAs($staff)->get('/integrations')->assertForbidden();
            $this->actingAs($staff)->put('/integrations/facebook', [])->assertForbidden();
            $this->actingAs($staff)->post('/integrations/facebook/test')->assertForbidden();
        }
    }

    /** A permission toggle is about leads. It must not open this page. */
    public function test_see_all_leads_does_not_let_a_salesperson_in(): void
    {
        $manager = $this->user('salesperson', 'Sal');
        $manager->update(['permissions' => ['see_all_leads' => true]]);

        $this->actingAs($manager)->get('/integrations')->assertForbidden();
    }

    public function test_a_deactivated_admin_is_refused(): void
    {
        $this->admin->update(['is_active' => false]);

        $this->actingAs($this->admin)->get('/integrations')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/integrations')->assertRedirect('/login');
        $this->put('/integrations/facebook', [])->assertRedirect('/login');
        $this->post('/integrations/facebook/test')->assertRedirect('/login');
    }

    /* ---------------- the cards ---------------- */

    public function test_the_page_shows_one_card_per_platform_with_facebook_and_whatsapp_built(): void
    {
        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Index')
                ->has('cards', 4)
                ->where('cards.0.provider', 'facebook')
                ->where('cards.0.built', true)
                ->where('cards.0.connected', false)
                ->where('cards.1.provider', 'instagram')
                ->where('cards.1.built', false)
                ->where('cards.2.provider', 'whatsapp')
                ->where('cards.2.name', 'WhatsApp (11za)')
                ->where('cards.2.built', true)
                ->where('cards.2.connected', false)
                ->where('cards.3.provider', 'website')
                ->where('cards.3.built', false)
            );
    }

    /** A stub card carries no settings and no webhook — there is nothing to configure. */
    public function test_a_stubbed_platform_offers_nothing_to_configure(): void
    {
        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.1.settings', null)
                ->where('cards.1.webhook', null)
                ->where('cards.1.last_received_at', null)
            );

        // and no route to configure it through, either
        $this->actingAs($this->admin)->put('/integrations/instagram', [])->assertNotFound();
        $this->actingAs($this->admin)->post('/integrations/website/test')->assertNotFound();
    }

    public function test_a_configured_but_switched_off_integration_does_not_read_as_connected(): void
    {
        $this->connect(active: false);

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.0.configured', true)
                ->where('cards.0.connected', false)
                ->where('cards.0.is_active', false)
            );
    }

    public function test_the_card_shows_when_a_lead_last_arrived(): void
    {
        $this->connect();
        $this->sendTestLead()->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.0.connected', true)
                ->whereNot('cards.0.last_received_at', null)
            );
    }

    /* ---------------- the secrets ---------------- */

    public function test_the_tokens_are_encrypted_at_rest(): void
    {
        $this->connect();

        $stored = DB::table('integrations')->where('provider', 'facebook')->value('settings');

        $this->assertStringNotContainsString(self::TOKEN, $stored);
        $this->assertStringNotContainsString(self::SECRET, $stored);
        // and it really is our row, decrypting back to what was saved
        $this->assertSame(self::TOKEN, Integration::forProvider('facebook')->setting('page_access_token'));
    }

    public function test_no_token_ever_reaches_the_browser(): void
    {
        $this->connect();

        $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();

        // the whole rendered page, not just the props the assertions below name:
        // this is the test that would catch a stray $integration->toArray()
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());

        $response->assertInertia(fn (Assert $page) => $page
            ->where('cards.0.settings.page_access_token_hint', '••••••••1234')
            ->where('cards.0.settings.app_secret_hint', '••••••••abcd')
            ->missing('cards.0.settings.page_access_token')
            ->missing('cards.0.settings.app_secret')
        );
    }

    public function test_an_unconfigured_card_has_no_mask_to_show(): void
    {
        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.0.settings.page_access_token_hint', null)
                ->where('cards.0.settings.app_secret_hint', null)
            );
    }

    /* ---------------- saving the settings ---------------- */

    public function test_the_admin_saves_the_settings_and_gets_a_webhook_url_and_verify_token(): void
    {
        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings())
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $integration = Integration::forProvider('facebook');

        $this->assertTrue($integration->exists);
        $this->assertTrue($integration->is_active);
        $this->assertSame(self::TOKEN, $integration->setting('page_access_token'));
        $this->assertSame(self::SECRET, $integration->setting('app_secret'));
        $this->assertSame('102938475600', $integration->setting('page_id'));
        $this->assertSame($this->project->id, $integration->setting('default_project_id'));
        $this->assertSame($this->owner->id, $integration->setting('assign_to_user_id'));
        $this->assertNotEmpty($integration->setting('verify_token'));

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.0.webhook.url', url('/webhooks/facebook/leads'))
                ->where('cards.0.webhook.verify_token', $integration->setting('verify_token'))
                ->where('cards.0.connected', true)
            );
    }

    /**
     * The verify token is pasted into the Meta app dashboard. Regenerating it
     * on a later save would silently break the webhook at the next re-verify.
     */
    public function test_the_verify_token_survives_a_second_save(): void
    {
        $this->actingAs($this->admin)->put('/integrations/facebook', $this->settings());

        $first = Integration::forProvider('facebook')->setting('verify_token');

        $this->actingAs($this->admin)->put('/integrations/facebook', $this->settings([
            'page_id' => '999',
        ]));

        $this->assertSame($first, Integration::forProvider('facebook')->setting('verify_token'));
    }

    /** The form never sends the stored tokens back, so blank means "keep them". */
    public function test_leaving_the_token_fields_blank_keeps_the_stored_tokens(): void
    {
        $this->actingAs($this->admin)->put('/integrations/facebook', $this->settings());

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings([
                'page_access_token' => '',
                'app_secret' => '',
                'page_id' => 'changed-page-id',
            ]))
            ->assertSessionHasNoErrors();

        $integration = Integration::forProvider('facebook');

        $this->assertSame(self::TOKEN, $integration->setting('page_access_token'));
        $this->assertSame(self::SECRET, $integration->setting('app_secret'));
        $this->assertSame('changed-page-id', $integration->setting('page_id'));
    }

    public function test_a_new_token_replaces_the_stored_one(): void
    {
        $this->actingAs($this->admin)->put('/integrations/facebook', $this->settings());

        $this->actingAs($this->admin)->put('/integrations/facebook', $this->settings([
            'page_access_token' => 'EAAG-a-brand-new-token',
        ]));

        $this->assertSame(
            'EAAG-a-brand-new-token',
            Integration::forProvider('facebook')->setting('page_access_token')
        );
    }

    public function test_switching_it_on_without_a_token_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings([
                'page_access_token' => '',
                'app_secret' => '',
            ]))
            ->assertSessionHasErrors('is_active');

        $this->assertFalse(Integration::forProvider('facebook')->isReady());
    }

    /** Saving it switched off is how a half-finished setup is parked. */
    public function test_it_can_be_saved_switched_off_with_nothing_filled_in_yet(): void
    {
        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings([
                'page_access_token' => '',
                'app_secret' => '',
                'is_active' => false,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse(Integration::forProvider('facebook')->is_active);
    }

    public function test_the_project_and_the_owner_must_be_real_and_active(): void
    {
        $inactiveProject = Project::create(['name' => 'Old', 'is_active' => false]);
        $inactiveUser = $this->user('salesperson', 'Off');
        $inactiveUser->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['default_project_id' => $inactiveProject->id]))
            ->assertSessionHasErrors('default_project_id');

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['assign_to_user_id' => $inactiveUser->id]))
            ->assertSessionHasErrors('assign_to_user_id');

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['assign_to_user_id' => 99999]))
            ->assertSessionHasErrors('assign_to_user_id');
    }

    /* ---------------- the test lead ---------------- */

    public function test_the_test_lead_button_creates_a_lead_with_a_pending_follow_up(): void
    {
        $this->connect();

        $this->sendTestLead()
            ->assertRedirect()
            ->assertSessionHas('success');

        $lead = Lead::firstOrFail();

        $this->assertSame('Test', $lead->first_name);
        $this->assertSame('Lead', $lead->last_name);
        $this->assertSame('facebook', $lead->source);
        $this->assertSame('fresh', $lead->stage);
        $this->assertSame($this->project->id, $lead->project_id);
        $this->assertSame($this->owner->id, $lead->assigned_to);
        $this->assertStringStartsWith('test_', $lead->external_id);

        // the country code went in and ten bare digits came out, exactly as a
        // real lead's would
        $this->assertSame(10, strlen($lead->mobile_number));
        $this->assertStringStartsWith('99999', $lead->mobile_number);

        $todo = $lead->pendingTodo;

        $this->assertNotNull($todo, 'the test lead arrived with no pending follow-up');
        $this->assertSame($this->owner->id, $todo->assigned_to);
        $this->assertTrue($todo->scheduled_at->isToday());
        $this->assertSame(1, Todo::dueToday()->where('assigned_to', $this->owner->id)->count());

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());

        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('created', $event->result);
        $this->assertSame($lead->id, $event->lead_id);
    }

    /**
     * The same code path, not a shortcut through it: the button supplies the
     * answers Meta would have been asked for and nothing else changes. If it
     * called Graph the token would be wrong; if it skipped the job the
     * idempotency and logging would be untested.
     */
    public function test_the_test_lead_runs_the_real_import_without_calling_graph(): void
    {
        Http::fake();

        $this->connect();
        $this->sendTestLead();

        Http::assertNothingSent();
        $this->assertSame(1, Lead::count());
    }

    /** Two tests are two leads, so a second one is not skipped as a duplicate. */
    public function test_two_test_leads_do_not_collide(): void
    {
        $this->connect();

        $this->sendTestLead();
        $this->sendTestLead();

        $this->assertSame(2, Lead::count());
        $this->assertSame(2, IntegrationEvent::where('result', 'created')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_the_test_lead_is_refused_until_the_integration_is_connected(): void
    {
        $this->sendTestLead()->assertSessionHas('error');

        $this->connect(active: false);
        $this->sendTestLead()->assertSessionHas('error');

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, IntegrationEvent::count());
    }

    /**
     * The commonest way this breaks months later: the person the leads were
     * assigned to has left. The admin gets a sentence and the activity log gets
     * a row — not a 500 page.
     */
    public function test_a_test_lead_that_cannot_be_imported_flashes_an_error_rather_than_failing(): void
    {
        $this->connect();
        $this->owner->delete();

        $this->sendTestLead()
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Lead::count());
        $this->assertSame('failed', IntegrationEvent::firstOrFail()->result);
    }

    /* ---------------- the activity log ---------------- */

    public function test_the_activity_log_shows_the_newest_fifty_events(): void
    {
        foreach (range(1, 60) as $i) {
            IntegrationEvent::create([
                'provider' => 'facebook',
                'result' => 'created',
                'external_id' => "lead-$i",
                'message' => "Lead #$i created.",
            ]);
        }

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 50)
                ->where('events.0.external_id', 'lead-60')   // newest first
                ->where('events.49.external_id', 'lead-11')
                ->where('events.0.provider_name', 'Facebook Lead Ads')
            );
    }

    public function test_a_failure_carries_its_reason_into_the_log(): void
    {
        $this->connect();
        $this->owner->delete();
        $this->sendTestLead();

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.result', 'failed')
                ->where('events.0.message', fn (string $m) => str_contains($m, 'no longer exists'))
            );
    }

    /* ---------------- WhatsApp (11za) ---------------- */

    public function test_the_admin_saves_the_whatsapp_settings_and_gets_a_stable_api_key(): void
    {
        $this->saveWhatsApp()
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'WhatsApp settings saved.');

        $integration = Integration::forProvider('whatsapp');
        $key = $integration->setting('api_key');

        $this->assertTrue($integration->is_active);
        $this->assertSame($this->project->id, $integration->setting('default_project_id'));
        $this->assertSame($this->owner->id, $integration->setting('assign_to_user_id'));
        $this->assertSame(40, strlen($key));

        // pasted into 11za, so a second save must not change it
        $this->saveWhatsApp(['is_active' => false]);
        $this->assertSame($key, Integration::forProvider('whatsapp')->setting('api_key'));
    }

    /** The outbound sending settings live on the same `whatsapp` row. */
    public function test_saving_the_whatsapp_card_keeps_the_outbound_sending_settings(): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings(['auth_token' => 'outbound-auth-token-9876', 'origin_website' => 'https://example.test', 'api_enabled' => true]);
        $integration->save();

        $this->saveWhatsApp()->assertSessionHasNoErrors();

        $integration->refresh();
        $this->assertSame('outbound-auth-token-9876', $integration->setting('auth_token'));
        $this->assertSame('https://example.test', $integration->setting('origin_website'));
        $this->assertTrue($integration->setting('api_enabled'));
    }

    public function test_the_api_key_cannot_be_set_through_a_settings_form(): void
    {
        $this->saveWhatsApp(['api_key' => 'chosen-by-the-browser']);
        $this->connect();
        $this->actingAs($this->admin)->put('/integrations/facebook', $this->settings(['api_key' => 'chosen-by-the-browser']));

        $this->assertNotSame('chosen-by-the-browser', Integration::forProvider('whatsapp')->setting('api_key'));
        $this->assertNull(Integration::forProvider('facebook')->setting('api_key'));
    }

    public function test_the_whatsapp_project_and_owner_must_be_real_and_active(): void
    {
        $this->saveWhatsApp(['default_project_id' => 999999, 'assign_to_user_id' => $this->admin->id])
            ->assertSessionHasErrors(['default_project_id', 'assign_to_user_id']);

        $this->assertFalse(Integration::forProvider('whatsapp')->exists);
    }

    public function test_the_whatsapp_card_shows_the_webhook_url_and_api_key_and_no_other_secret(): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings(['auth_token' => 'outbound-auth-token-9876']);
        $integration->save();
        $this->saveWhatsApp();

        $key = Integration::forProvider('whatsapp')->setting('api_key');

        $response = $this->actingAs($this->admin)->get('/integrations')->assertOk();

        $this->assertStringNotContainsString('outbound-auth-token-9876', $response->getContent());

        $response->assertInertia(fn (Assert $page) => $page
            ->where('cards.2.webhook.url', url('/webhooks/whatsapp/customers'))
            ->where('cards.2.webhook.api_key', $key)
            ->where('cards.2.connected', true)
            ->where('cards.2.forms', null)
            ->missing('cards.2.settings.api_key')
            ->missing('cards.2.settings.auth_token')
            ->missing('cards.2.settings.page_access_token_hint')
        );
    }

    public function test_the_whatsapp_test_lead_button_creates_a_lead_with_a_pending_follow_up(): void
    {
        $this->saveWhatsApp();

        $this->actingAs($this->admin)
            ->post('/integrations/whatsapp/test')
            ->assertRedirect()
            ->assertSessionHas('success');

        $lead = Lead::firstOrFail();

        $this->assertSame('Test', $lead->first_name);
        $this->assertSame('Lead', $lead->last_name);
        $this->assertSame('whatsapp', $lead->source);
        $this->assertStringStartsWith('99999', $lead->mobile_number);
        $this->assertStringStartsWith('11za:'.$lead->mobile_number.':', $lead->external_id);
        $this->assertNotNull($lead->pendingTodo);
    }

    public function test_whatsapp_has_no_lead_forms_to_load(): void
    {
        $this->saveWhatsApp();

        $this->actingAs($this->admin)->post('/integrations/whatsapp/forms/sync')->assertNotFound();
    }

    /* ---------------- fixtures ---------------- */

    private function saveWhatsApp(array $overrides = [])
    {
        return $this->actingAs($this->admin)->put('/integrations/whatsapp', array_merge([
            'default_project_id' => $this->project->id,
            'assign_to_user_id' => $this->owner->id,
            'is_active' => true,
        ], $overrides));
    }

    /** A configured Facebook integration, written the way the form writes one. */
    private function connect(bool $active = true): void
    {
        $this->actingAs($this->admin)->put('/integrations/facebook', $this->settings([
            'is_active' => $active,
        ]));
    }

    private function sendTestLead()
    {
        return $this->actingAs($this->admin)->post('/integrations/facebook/test');
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'page_access_token' => self::TOKEN,
            'app_secret' => self::SECRET,
            'page_id' => '102938475600',
            'default_project_id' => $this->project->id,
            'assign_to_user_id' => $this->owner->id,
            'is_active' => true,
        ], $overrides);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'User',
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
