<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaLead;
use App\Models\Alert;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\LeadFormRoute;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadFormRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A Facebook lead is filed under the project its lead form is mapped to.
 *
 *   routing    a mapped form's lead lands on the form's project, and the
 *              form's telecaller is the holder handed to LeadAssignmentService
 *
 *   fallback   an unmapped form's lead is still created, in the default
 *              project; the form appears in the table with no project, the
 *              activity log flags the row, and the admins are alerted once
 *              with a count that updates in place
 *
 *   naming     stored name, then Graph, then the raw id — and a lead whose
 *              form cannot be named at all is still a lead
 *
 * @see LeadFormRouter
 */
class LeadFormRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'page-access-token-from-meta';

    private const FORM = '781234567890123';

    private User $admin;

    private User $tia;

    private Project $fallback;

    private Project $tower;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:30', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tia = $this->user('telecaller', 'Tia');
        $this->fallback = Project::create(['name' => 'Fallback']);
        $this->tower = Project::create(['name' => 'Tower B']);

        $integration = Integration::forProvider('facebook');
        $integration->mergeSettings([
            'page_access_token' => self::TOKEN,
            'app_secret' => 'app-secret',
            'page_id' => '102938475600',
            'default_project_id' => $this->fallback->id,
            'assign_to_user_id' => $this->tia->id,
        ]);
        $integration->is_active = true;
        $integration->save();
    }

    /* ---------------- routing ---------------- */

    public function test_a_mapped_form_files_its_lead_under_its_own_project(): void
    {
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => self::FORM, 'form_name' => 'Tower B launch', 'project_id' => $this->tower->id]);
        $this->fakeGraph();

        $this->process('lead-1');

        $lead = Lead::firstOrFail();
        $this->assertSame($this->tower->id, $lead->project_id);
        $this->assertSame(self::FORM, $lead->source_form_id);
        $this->assertSame($this->tia->id, $lead->assigned_to);

        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('created', $event->result);
        $this->assertTrue($event->routed);
        $this->assertSame('Tower B launch', $event->form_name);
        $this->assertSame(0, Alert::count());
    }

    public function test_the_forms_telecaller_keeps_the_lead(): void
    {
        $tom = $this->user('telecaller', 'Tom');
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => self::FORM, 'form_name' => 'Tower B', 'project_id' => $this->tower->id, 'assign_to_user_id' => $tom->id]);
        $this->fakeGraph();

        $this->process('lead-1');

        $this->assertSame($tom->id, Lead::firstOrFail()->assigned_to);
    }

    /* ---------------- fallback ---------------- */

    public function test_an_unmapped_form_falls_back_and_waits_in_the_table(): void
    {
        $this->fakeGraph(formName: 'Spring offer');

        $this->process('lead-1');

        $lead = Lead::firstOrFail();
        $this->assertSame($this->fallback->id, $lead->project_id);

        $route = LeadFormRoute::firstOrFail();
        $this->assertSame(self::FORM, $route->form_id);
        $this->assertNull($route->project_id);

        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('created', $event->result);
        $this->assertFalse($event->routed);
        $this->assertStringContainsString('fallback project', $event->message);
    }

    public function test_the_admins_are_alerted_once_and_the_count_updates_in_place(): void
    {
        $this->fakeGraph(formName: 'Spring offer');

        $this->process('lead-1');

        $alert = Alert::where('user_id', $this->admin->id)->sole();
        $this->assertStringContainsString('Spring offer', $alert->title);
        $this->assertStringContainsString('1 lead ', $alert->body);

        // read, and then a day later — past the dedupe window — two more arrive
        $alert->update(['read_at' => now()]);
        $this->travel(2)->days();
        $this->process('lead-2', mobile: '9512779298');
        $this->process('lead-3', mobile: '9512779299');

        $alert = Alert::where('user_id', $this->admin->id)->sole();
        $this->assertStringContainsString('3 leads', $alert->body);
        $this->assertNotNull($alert->read_at, 'a count change is not a new notification');
    }

    public function test_an_archived_project_on_a_form_falls_back(): void
    {
        $this->tower->update(['is_active' => false]);
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => self::FORM, 'form_name' => 'Tower B', 'project_id' => $this->tower->id]);
        $this->fakeGraph();

        $this->process('lead-1');

        $this->assertSame($this->fallback->id, Lead::firstOrFail()->project_id);
        $this->assertFalse(IntegrationEvent::firstOrFail()->routed);
    }

    /* ---------------- naming ---------------- */

    public function test_a_stored_form_name_is_used_without_asking_graph(): void
    {
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => self::FORM, 'form_name' => 'Stored name']);
        $this->fakeGraph(formName: 'Graph name');

        $this->process('lead-1');

        $this->assertSame('Stored name', IntegrationEvent::firstOrFail()->form_name);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/'.self::FORM));
    }

    public function test_graphs_form_name_is_used_and_remembered(): void
    {
        $this->fakeGraph(formName: 'Spring offer');

        $this->process('lead-1');

        $this->assertSame('Spring offer', IntegrationEvent::firstOrFail()->form_name);
        $this->assertSame('Spring offer', LeadFormRoute::firstOrFail()->form_name);
    }

    public function test_a_form_that_cannot_be_named_still_creates_its_lead(): void
    {
        $this->fakeGraph(formLookupFails: true);

        $this->process('lead-1');

        $this->assertSame(1, Lead::count());
        $event = IntegrationEvent::firstOrFail();
        $this->assertSame('created', $event->result);
        $this->assertSame(self::FORM, $event->form_name);
        $this->assertNull(LeadFormRoute::firstOrFail()->form_name);
    }

    /* ---------------- the webhook ---------------- */

    public function test_the_webhook_passes_the_form_id_to_the_job(): void
    {
        Queue::fake();

        $body = json_encode(['object' => 'page', 'entry' => [['changes' => [[
            'field' => 'leadgen',
            'value' => ['leadgen_id' => '120000000001', 'page_id' => '102938475600', 'form_id' => self::FORM],
        ]]]]]);

        $this->call('POST', '/webhooks/facebook/leads', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body)->assertOk();

        Queue::assertPushed(ProcessMetaLead::class, fn (ProcessMetaLead $job) => $job->leadgenId === '120000000001'
            && $job->formId === self::FORM);
    }

    /* ---------------- the settings form ---------------- */

    public function test_only_a_telecaller_can_be_chosen_to_receive_leads(): void
    {
        $sal = $this->user('salesperson', 'Sal');

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['assign_to_user_id' => $sal->id]))
            ->assertSessionHasErrors('assign_to_user_id');

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['forms' => [
                ['form_id' => self::FORM, 'project_id' => $this->tower->id, 'assign_to_user_id' => $sal->id],
            ]]))
            ->assertSessionHasErrors('forms.0.assign_to_user_id');

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->has('options.users', 1)
                ->where('options.users.0.id', $this->tia->id)
            );
    }

    public function test_the_page_id_is_required(): void
    {
        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['page_id' => '']))
            ->assertSessionHasErrors('page_id');
    }

    public function test_mapping_a_form_saves_it_and_clears_its_alert(): void
    {
        $this->fakeGraph(formName: 'Spring offer');
        $this->process('lead-1');
        $this->assertNull(Alert::sole()->read_at);

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['forms' => [
                ['form_id' => self::FORM, 'form_name' => '', 'project_id' => $this->tower->id, 'assign_to_user_id' => null],
            ]]))
            ->assertSessionHasNoErrors();

        $route = LeadFormRoute::sole();
        $this->assertSame($this->tower->id, $route->project_id);
        $this->assertSame('Spring offer', $route->form_name, 'a blank name must not erase the stored one');
        $this->assertNotNull(Alert::sole()->read_at);
    }

    /** A form that arrived while the modal was open must not be deleted by saving it. */
    public function test_saving_deletes_only_the_forms_the_admin_removed(): void
    {
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => 'removed']);
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => 'arrived-meanwhile']);

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['forms' => [], 'removed_forms' => ['removed']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['arrived-meanwhile'], LeadFormRoute::pluck('form_id')->all());
    }

    public function test_a_typed_form_id_that_is_not_a_facebook_id_is_refused(): void
    {
        foreach (['1231231231231321321213213', '12345', 'abc123456789012', '78123456789012a'] as $typed) {
            $this->actingAs($this->admin)
                ->put('/integrations/facebook', $this->settings(['forms' => [
                    ['form_id' => $typed, 'project_id' => $this->tower->id],
                ]]))
                ->assertSessionHasErrors(['forms.0.form_id' => "That doesn't look like a Facebook form ID. Use Load forms from Facebook to pick one."]);
        }

        $this->assertSame(0, LeadFormRoute::count());
    }

    public function test_a_typed_form_id_of_fifteen_or_sixteen_digits_is_accepted(): void
    {
        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['forms' => [
                ['form_id' => '781234567890123', 'project_id' => $this->tower->id],
                ['form_id' => '7812345678901234', 'project_id' => null],
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['781234567890123', '7812345678901234'], LeadFormRoute::pluck('form_id')->all());
    }

    /** An id Meta itself sent is its own, whatever it looks like; re-saving must not refuse it. */
    public function test_a_form_already_in_the_table_is_not_re_checked(): void
    {
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => '556677']);

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['forms' => [
                ['form_id' => '556677', 'project_id' => $this->tower->id],
            ]]))
            ->assertSessionHasNoErrors();
    }

    /* ---------------- removing a form ---------------- */

    public function test_removing_a_form_leaves_its_leads_as_they_are(): void
    {
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => self::FORM, 'form_name' => 'VANAM - 11-08-2026', 'project_id' => $this->tower->id]);
        $this->fakeGraph();
        $this->process('lead-1');
        $lead = Lead::sole();
        $before = $lead->only(['project_id', 'source_form_id', 'assigned_to', 'stage', 'updated_at']);

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['forms' => [], 'removed_forms' => [self::FORM]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, LeadFormRoute::count());
        $this->assertEquals($before, $lead->fresh()->only(array_keys($before)));
        $this->assertSame(self::FORM, $lead->fresh()->source_form_id);
    }

    /**
     * The form comes back as a new, unmapped row: fallback project, the
     * unrouted flag, and a new alert — not the old one quietly re-counted.
     */
    public function test_a_lead_from_a_removed_form_falls_back_with_a_fresh_warning(): void
    {
        $this->fakeGraph(formName: 'VANAM - 11-08-2026');
        $this->process('lead-1');
        $this->assertSame(1, Alert::count());

        $this->actingAs($this->admin)
            ->put('/integrations/facebook', $this->settings(['forms' => [], 'removed_forms' => [self::FORM]]))
            ->assertSessionHasNoErrors();

        // the old alert is about a row that no longer exists
        $this->assertNotNull(Alert::sole()->read_at);

        // inside the 24-hour dedupe window on purpose: it must still be raised
        $this->travel(1)->hours();
        $this->process('lead-2', mobile: '9512779298');

        $lead = Lead::where('external_id', 'lead-2')->sole();
        $this->assertSame($this->fallback->id, $lead->project_id);
        $this->assertFalse(IntegrationEvent::where('external_id', 'lead-2')->sole()->routed);
        $this->assertNull(LeadFormRoute::sole()->project_id);

        $fresh = Alert::where('user_id', $this->admin->id)->whereNull('read_at')->sole();
        $this->assertStringContainsString('VANAM - 11-08-2026', $fresh->title);
        // counts only what arrived since the form came back
        $this->assertStringContainsString('1 lead ', $fresh->body);
        $this->assertSame(2, Alert::count());

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page->where('cards.0.forms.0.unrouted_leads', 1));
    }

    public function test_loading_forms_from_facebook_adds_them_unmapped_and_keeps_existing_mappings(): void
    {
        LeadFormRoute::create(['provider' => 'facebook', 'form_id' => self::FORM, 'form_name' => 'Old name', 'project_id' => $this->tower->id]);

        Http::fake(['graph.facebook.com/*/102938475600/leadgen_forms*' => Http::response(['data' => [
            ['id' => self::FORM, 'name' => 'Tower B launch'],
            ['id' => '781234567890456', 'name' => 'Villa enquiry'],
        ]])]);

        $this->actingAs($this->admin)
            ->post('/integrations/facebook/forms/sync')
            ->assertSessionHas('success');

        $existing = LeadFormRoute::where('form_id', self::FORM)->sole();
        $this->assertSame('Tower B launch', $existing->form_name);
        $this->assertSame($this->tower->id, $existing->project_id);

        $new = LeadFormRoute::where('form_id', '781234567890456')->sole();
        $this->assertSame('Villa enquiry', $new->form_name);
        $this->assertNull($new->project_id);
    }

    public function test_the_activity_log_and_form_table_show_what_went_to_the_fallback(): void
    {
        $this->fakeGraph(formName: 'Spring offer');
        $this->process('lead-1');

        $this->actingAs($this->admin)
            ->get('/integrations')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.routed', false)
                ->where('events.0.form_name', 'Spring offer')
                ->where('cards.0.forms.0.form_id', self::FORM)
                ->where('cards.0.forms.0.project_id', null)
                ->where('cards.0.forms.0.unrouted_leads', 1)
            );
    }

    /* ---------------- fixtures ---------------- */

    private function process(string $leadgenId, string $mobile = '9512779297'): void
    {
        ProcessMetaLead::dispatchSync('facebook', $leadgenId, [
            ['name' => 'full_name', 'values' => ['Neel Bhadani']],
            ['name' => 'phone_number', 'values' => [$mobile]],
        ], self::FORM);
    }

    /** The form-name lookup; the lead's own answers come with the job. */
    private function fakeGraph(?string $formName = null, bool $formLookupFails = false): void
    {
        Http::fake(['graph.facebook.com/*/'.self::FORM.'*' => $formLookupFails
            ? Http::response(['error' => ['message' => 'Unsupported get request.']], 400)
            : Http::response(array_filter(['id' => self::FORM, 'name' => $formName])),
        ]);
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'page_access_token' => '',
            'app_secret' => '',
            'page_id' => '102938475600',
            'default_project_id' => $this->fallback->id,
            'assign_to_user_id' => $this->tia->id,
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
