<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\LeadFormRoute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * With `integrations.providers.whatsapp.tag_routing` off, every 11za lead goes
 * to the default project ("Unassigned") and the configured telecaller, who
 * moves it with Switch project. A returning number is a repeat enquiry on its
 * existing lead, and a lead still on the default project cannot be moved to a
 * salesperson's stage.
 */
class WhatsAppNoTagRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'eleven-za-api-key-0123456789';

    private const URL = '/webhooks/whatsapp/customers';

    private User $admin;

    private User $tia;

    private User $tom;

    private User $sam;

    private Project $unassigned;

    private Project $vanam;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-24 11:30', 'Asia/Kolkata'));
        config(['integrations.providers.whatsapp.tag_routing' => false]);

        $this->admin = $this->user('admin', 'Ann');
        $this->tia = $this->user('telecaller', 'Tia');
        $this->tom = $this->user('telecaller', 'Tom');
        $this->sam = $this->user('salesperson', 'Sam');
        $this->unassigned = Project::create(['name' => 'Unassigned']);
        $this->vanam = Project::create(['name' => 'Vanam']);
        $this->vanam->salespeople()->attach($this->sam->id);

        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'api_key' => self::KEY,
            'default_project_id' => $this->unassigned->id,
            'assign_to_user_id' => $this->tia->id,
        ]);
        $integration->is_active = true;
        $integration->save();
    }

    /* ---------------- tag routing off ---------------- */

    public function test_tags_are_ignored_and_the_lead_goes_to_the_default_project(): void
    {
        // a mapped tag that would route to Vanam with tag routing on
        $this->map('vanam', $this->vanam, $this->tom);

        $this->deliver(['tags' => ['Vanam', '2BHK'], 'masterstatus' => 'vanam']);

        $lead = Lead::sole();
        $this->assertSame($this->unassigned->id, $lead->project_id);
        $this->assertSame($this->tia->id, $lead->assigned_to);
        $this->assertNull($lead->source_form_id);

        $this->assertSame(['vanam'], LeadFormRoute::forProvider('whatsapp')->pluck('form_id')->all(), 'no new rows');
        $this->assertSame(0, Alert::count());

        $event = IntegrationEvent::sole();
        $this->assertSame('created', $event->result);
        $this->assertNull($event->form_id);
        $this->assertStringContainsString('Switch project', $event->message);
    }

    public function test_an_update_with_tags_never_moves_the_lead(): void
    {
        $this->map('vanam', $this->vanam, $this->tom);
        $this->deliver();
        $lead = Lead::sole();

        $this->deliver(['tags' => ['Vanam'], 'updatedAt' => '2026-11-25T06:00:00.000Z'], type: 'update');

        $lead->refresh();
        $this->assertSame(1, Lead::count());
        $this->assertSame($this->unassigned->id, $lead->project_id);
        $this->assertSame($this->tia->id, $lead->assigned_to);

        $event = IntegrationEvent::latest('id')->first();
        $this->assertSame('update_received', $event->result);
        $this->assertSame($lead->id, $event->lead_id);
        $this->assertSame(0, Alert::count());
    }

    public function test_an_update_for_an_unknown_number_creates_the_lead_on_the_default_project(): void
    {
        $this->deliver(['tags' => ['Vanam']], type: 'update');

        $this->assertSame($this->unassigned->id, Lead::sole()->project_id);
        $this->assertSame('created', IntegrationEvent::sole()->result);
    }

    public function test_the_settings_page_hides_the_tag_section(): void
    {
        $this->map('vanam', $this->vanam);

        $this->actingAs($this->admin)->get('/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cards.2.provider', 'whatsapp')
            ->where('cards.2.tag_routing', false)
            ->where('cards.2.tags', null)
        );
    }

    public function test_saving_the_settings_leaves_the_tag_rows_alone(): void
    {
        $route = $this->map('vanam', $this->vanam, $this->tom);

        $this->actingAs($this->admin)->put('/integrations/whatsapp', [
            'default_project_id' => $this->unassigned->id,
            'assign_to_user_id' => $this->tia->id,
            'is_active' => true,
            'removed_tags' => ['vanam'],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->vanam->id, $route->fresh()->project_id);
    }

    /* ---------------- returning customers ---------------- */

    public function test_a_number_already_on_another_project_is_a_repeat_enquiry_on_that_lead(): void
    {
        $existing = $this->leadOn($this->vanam, 'connected', '9820012345');

        $this->deliver();

        $this->assertSame(1, Lead::count(), 'no new lead');

        $event = IntegrationEvent::sole();
        $this->assertSame('repeat_enquiry', $event->result);
        $this->assertSame($existing->id, $event->lead_id);
        $this->assertStringContainsString('Already a lead on Vanam', $event->message);

        $alert = Alert::sole();
        $this->assertSame($existing->assigned_to, $alert->user_id);
        $this->assertSame($existing->id, $alert->lead_id);
        $this->assertSame('lead_repeat_enquiry', $alert->type);
    }

    public function test_a_deleted_lead_elsewhere_does_not_count_as_returning(): void
    {
        $this->leadOn($this->vanam, 'connected', '9820012345')->delete();

        $this->deliver();

        $this->assertSame($this->unassigned->id, Lead::sole()->project_id);
        $this->assertSame('created', IntegrationEvent::sole()->result);
    }

    /* ---------------- stage guard ---------------- */

    public function test_a_default_project_lead_cannot_be_moved_to_a_salesperson_stage(): void
    {
        $lead = $this->leadOn($this->unassigned, 'fresh', '9820011111');

        $this->complete($lead, 'site_visit_scheduled')
            ->assertSessionHasErrors(['stage' => 'Choose the project first: use Switch project.']);

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->leadPayload($lead, ['stage' => 'in_discussion']))
            ->assertSessionHasErrors(['stage' => 'Choose the project first: use Switch project.']);

        $this->assertSame('fresh', $lead->fresh()->stage);
    }

    public function test_a_default_project_lead_can_move_to_telecaller_stages_and_lost(): void
    {
        $lead = $this->leadOn($this->unassigned, 'fresh', '9820011111');

        $this->complete($lead, 'connected')->assertSessionHasNoErrors();
        $this->assertSame('connected', $lead->fresh()->stage);

        $this->complete($lead->fresh(), 'lost')->assertSessionHasNoErrors();
        $this->assertSame('lost', $lead->fresh()->stage);
    }

    public function test_after_switch_project_the_stage_change_works(): void
    {
        $lead = $this->leadOn($this->unassigned, 'connected', '9820011111');

        $this->actingAs($this->admin)
            ->put(route('leads.switch-project', $lead), ['project_id' => $this->vanam->id])
            ->assertSessionHasNoErrors();

        $this->complete($lead->fresh(), 'site_visit_scheduled')->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->vanam->id, $lead->project_id);
        $this->assertSame('site_visit_scheduled', $lead->stage);
    }

    public function test_completing_a_follow_up_with_a_project_switch_may_move_to_a_salesperson_stage(): void
    {
        $lead = $this->leadOn($this->unassigned, 'connected', '9820011111');

        $this->complete($lead, 'site_visit_scheduled', ['project_id' => $this->vanam->id])
            ->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame($this->vanam->id, $lead->project_id);
        $this->assertSame('site_visit_scheduled', $lead->stage);
    }

    /* ---------------- helpers ---------------- */

    private function leadOn(Project $project, string $stage, string $mobile): Lead
    {
        $this->actingAs($this->admin)->post('/leads', [
            'first_name' => 'Asha',
            'last_name' => 'Mehta',
            'mobile_number' => $mobile,
            'project_id' => $project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        $lead = Lead::where('mobile_number', $mobile)->where('project_id', $project->id)->firstOrFail();

        if ($stage !== 'fresh') {
            $lead->update(['stage' => $stage]);
        }

        return $lead;
    }

    /** @param  array<string, mixed>  $extra */
    private function complete(Lead $lead, string $stage, array $extra = [])
    {
        $closing = in_array($stage, config('crm.terminal_stages'), true);

        return $this->actingAs($this->admin)->post("/todos/{$lead->pendingTodo()->firstOrFail()->id}/complete", array_filter([
            'stage' => $stage,
            'remarks' => 'Logged in the test.',
            'reason' => $stage === 'lost' ? 'budget' : null,
            'follow_up_type' => $closing ? null : ($stage === 'site_visit_scheduled' ? 'site_visit' : 'call'),
            'follow_up_at' => $closing ? null : now()->addDay()->format('Y-m-d H:i'),
        ]) + $extra);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function leadPayload(Lead $lead, array $overrides): array
    {
        return array_merge([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'mobile_number' => $lead->mobile_number,
            'project_id' => $lead->project_id,
            'source' => $lead->source,
            'stage' => $lead->stage,
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ], $overrides);
    }

    private function map(string $tag, Project $project, ?User $telecaller = null): LeadFormRoute
    {
        return LeadFormRoute::create([
            'provider' => 'whatsapp',
            'form_id' => $tag,
            'form_name' => $tag,
            'project_id' => $project->id,
            'assign_to_user_id' => $telecaller?->id,
        ]);
    }

    /**
     * A Customer Webhook delivery, shaped the way 11za sends it.
     *
     * @param  array<string, mixed>  $data  overrides; a null value removes the key
     */
    private function deliver(array $data = [], string $type = 'insert'): void
    {
        $data = array_filter(array_merge([
            'name' => 'Asha Mehta',
            'mobileNo' => '919820012345',
            'updatedAt' => '2026-11-24T06:00:00.000Z',
            'masterstatus' => 'New',
        ], $data), fn ($value) => $value !== null);

        $this->postJson(self::URL, ['type' => $type, 'data' => $data, 'changedFields' => []], ['x-api-key' => self::KEY])
            ->assertOk();
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
