<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppLead;
use App\Models\Alert;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\LeadFormRoute;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadFormRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * An 11za WhatsApp lead is filed under the project its 11za tag is mapped to.
 *
 *   insert     one mapped project → that project and the route's telecaller;
 *              none, or two different ones → the default project, unrouted
 *
 *   update     already on P → nothing; an unrouted 11za lead still open on the
 *              default project → moved to P; anything else → a new lead on P
 *
 *   settings   "Projects by 11za tag" on the WhatsApp card writes the same
 *              lead_form_routes rows Facebook's lead forms use
 *
 * @see ProcessWhatsAppLead
 * @see LeadFormRouter::routeTags()
 */
class ElevenZaTagRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'eleven-za-api-key-0123456789';

    private const URL = '/webhooks/whatsapp/customers';

    private User $admin;

    private User $tia;

    private User $tom;

    private Project $default;

    private Project $tower;

    private Project $park;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:30', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tia = $this->user('telecaller', 'Tia');
        $this->tom = $this->user('telecaller', 'Tom');
        $this->default = Project::create(['name' => 'Default']);
        $this->tower = Project::create(['name' => 'Tower B']);
        $this->park = Project::create(['name' => 'Park View']);

        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings([
            'api_key' => self::KEY,
            'default_project_id' => $this->default->id,
            'assign_to_user_id' => $this->tia->id,
        ]);
        $integration->is_active = true;
        $integration->save();
    }

    /* ---------------- insert ---------------- */

    public function test_an_insert_with_a_mapped_tag_lands_on_its_project_with_its_telecaller(): void
    {
        $this->map('tower b', $this->tower, $this->tom);

        $this->deliver(['tags' => ['Tower B']]);

        $lead = Lead::sole();
        $this->assertSame($this->tower->id, $lead->project_id);
        $this->assertSame($this->tom->id, $lead->assigned_to);
        $this->assertSame('tower b', $lead->source_form_id);

        $event = IntegrationEvent::sole();
        $this->assertSame('created', $event->result);
        $this->assertTrue($event->routed);
        $this->assertSame(0, Alert::count());
    }

    public function test_an_insert_with_no_tags_goes_to_the_default_project_unrouted_without_an_alert(): void
    {
        $this->deliver(['masterstatus' => null]);

        $this->assertSame($this->default->id, Lead::sole()->project_id);

        $event = IntegrationEvent::sole();
        $this->assertFalse($event->routed);
        $this->assertNull($event->form_id);
        $this->assertStringContainsString('no 11za tag', $event->message);
        $this->assertSame(0, Alert::count());
    }

    public function test_an_unmapped_tag_is_written_to_the_table_and_alerted_once(): void
    {
        $this->deliver(['tags' => ['Spring Offer'], 'masterstatus' => null]);
        $this->deliver(['mobileNo' => '919820099999', 'tags' => ['spring offer'], 'masterstatus' => null]);

        $route = LeadFormRoute::forProvider('whatsapp')->sole();
        $this->assertSame('spring offer', $route->form_id);
        $this->assertSame('Spring Offer', $route->form_name);
        $this->assertNull($route->project_id);

        $alert = Alert::sole();
        $this->assertSame($this->admin->id, $alert->user_id);
        $this->assertSame(LeadFormRouter::alertType($route), $alert->type);
        $this->assertSame(
            'Leads tagged "Spring Offer" in 11za are going to the default project. '
            .'Open Integrations → WhatsApp (11za) → Projects by 11za tag and choose a project.',
            $alert->body,
        );
        $this->assertSame(2, Lead::where('project_id', $this->default->id)->count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function tagShapes(): array
    {
        return [
            'tags as a list' => [['tags' => ['vip', 'Tower B']]],
            'tags as a comma-separated string' => [['tags' => 'vip, Tower B']],
            'the label' => [['tags' => [], 'masterstatus' => 'Tower B']],
            'different case and spacing' => [['tags' => ['  TOWER    b ']]],
        ];
    }

    #[DataProvider('tagShapes')]
    public function test_every_shape_of_tag_routes_the_same_way(array $data): void
    {
        $this->map('tower b', $this->tower);

        $this->deliver($data);

        $this->assertSame($this->tower->id, Lead::sole()->project_id);
    }

    public function test_an_unknown_label_creates_no_row_and_no_alert(): void
    {
        $this->deliver(['tags' => [], 'masterstatus' => 'Interested']);

        $this->assertSame($this->default->id, Lead::sole()->project_id);
        $this->assertSame(0, LeadFormRoute::count());
        $this->assertSame(0, Alert::count());
        $this->assertNull(IntegrationEvent::sole()->form_id);
    }

    public function test_a_label_the_admin_mapped_routes_to_its_project(): void
    {
        $this->map('vanam', $this->park);

        $this->deliver(['tags' => [], 'masterstatus' => 'Vanam']);

        $this->assertSame($this->park->id, Lead::sole()->project_id);
        $this->assertTrue(IntegrationEvent::sole()->routed);
    }

    public function test_a_tag_given_as_an_object_routes_like_the_string(): void
    {
        $this->map('vanam', $this->park);

        $this->deliver(['tags' => [['name' => 'Vanam'], ['colour' => 'red']], 'masterstatus' => null]);

        $this->assertSame($this->park->id, Lead::sole()->project_id);
        // the object with no naming key is ignored, not turned into a row
        $this->assertSame(1, LeadFormRoute::count());
    }

    public function test_tags_for_two_different_projects_go_to_the_default_project_with_the_conflict_logged(): void
    {
        $this->map('tower b', $this->tower);
        $this->map('park view', $this->park);

        $this->deliver(['tags' => ['Tower B', 'Park View']]);

        $this->assertSame($this->default->id, Lead::sole()->project_id);

        $event = IntegrationEvent::sole();
        $this->assertFalse($event->routed);
        $this->assertStringContainsString('different projects', $event->message);
        $this->assertStringContainsString('Tower B', $event->message);
        $this->assertStringContainsString('Park View', $event->message);
    }

    public function test_a_tag_mapped_to_an_archived_project_counts_as_unmapped(): void
    {
        $this->map('tower b', $this->tower);
        $this->tower->update(['is_active' => false]);

        $this->deliver(['tags' => ['Tower B'], 'masterstatus' => null]);

        $this->assertSame($this->default->id, Lead::sole()->project_id);
        $this->assertFalse(IntegrationEvent::sole()->routed);
        $this->assertSame(1, Alert::count());
    }

    /* ---------------- update ---------------- */

    public function test_an_update_adding_a_mapped_tag_moves_the_unrouted_lead_to_its_project(): void
    {
        $this->deliver();
        $lead = Lead::sole();
        $this->map('tower b', $this->tower, $this->tom);

        $this->deliver(['tags' => ['Tower B'], 'updatedAt' => '2026-09-08T06:00:00.000Z'], type: 'update');

        $lead->refresh();
        $this->assertSame(1, Lead::count());
        $this->assertSame($this->tower->id, $lead->project_id);
        $this->assertSame('fresh', $lead->stage);
        $this->assertSame($this->tom->id, $lead->assigned_to);

        $pending = Todo::where('lead_id', $lead->id)->where('status', 'pending')->get();
        $this->assertCount(1, $pending);
        $this->assertSame($this->tom->id, $pending->sole()->assigned_to);

        $event = IntegrationEvent::latest('id')->first();
        $this->assertSame('moved_to_project', $event->result);
        $this->assertSame($lead->id, $event->lead_id);
        $this->assertStringContainsString('from Default to Tower B', $event->message);
    }

    public function test_a_moved_lead_without_a_route_telecaller_keeps_one_pending_follow_up_with_its_owner(): void
    {
        $this->deliver();
        $lead = Lead::sole();
        $this->map('tower b', $this->tower);

        $this->deliver(['tags' => ['Tower B'], 'updatedAt' => '2026-09-08T06:00:00.000Z'], type: 'update');

        $lead->refresh();
        $this->assertSame($this->tower->id, $lead->project_id);
        $this->assertSame($lead->assigned_to, Todo::where('lead_id', $lead->id)->where('status', 'pending')->sole()->assigned_to);
    }

    public function test_an_update_for_a_lead_already_on_the_project_changes_nothing(): void
    {
        $this->map('tower b', $this->tower);
        $this->deliver(['tags' => ['Tower B']]);
        $lead = Lead::sole();

        $this->deliver(['tags' => ['Tower B'], 'name' => 'Someone Else', 'updatedAt' => '2026-09-08T06:00:00.000Z'], type: 'update');

        $this->assertSame(1, Lead::count());
        $this->assertSame('Asha', $lead->fresh()->first_name);
        $this->assertSame($this->tower->id, $lead->fresh()->project_id);

        $event = IntegrationEvent::latest('id')->first();
        $this->assertSame('update_received', $event->result);
        $this->assertSame($lead->id, $event->lead_id);
    }

    public function test_an_update_for_a_second_project_after_routing_opens_a_new_lead_there(): void
    {
        $this->map('tower b', $this->tower);
        $this->map('park view', $this->park);
        $this->deliver(['tags' => ['Tower B']]);
        $first = Lead::sole();

        $this->deliver(['tags' => ['Park View'], 'updatedAt' => '2026-09-08T06:00:00.000Z'], type: 'update');

        $this->assertSame(2, Lead::count());
        $this->assertSame($this->tower->id, $first->fresh()->project_id);
        $this->assertSame(1, Lead::where('project_id', $this->park->id)->count());
        $this->assertSame('created', IntegrationEvent::latest('id')->first()->result);
    }

    public function test_an_update_does_not_move_a_lead_somebody_added_by_hand(): void
    {
        $manual = Lead::create([
            'first_name' => 'Asha', 'last_name' => 'Mehta', 'mobile_number' => '9820012345',
            'project_id' => $this->default->id, 'source' => 'walk_in', 'stage' => 'fresh',
            'assigned_to' => $this->tia->id, 'assigned_role' => 'telecaller',
        ]);
        $this->map('tower b', $this->tower);

        $this->deliver(['tags' => ['Tower B']], type: 'update');

        $this->assertSame($this->default->id, $manual->fresh()->project_id);
        $this->assertSame(1, Lead::where('project_id', $this->tower->id)->count());
    }

    /** @return array<string, array{0: string}> */
    public static function terminalStages(): array
    {
        return ['booking done' => ['booking_done'], 'lost' => ['lost']];
    }

    #[DataProvider('terminalStages')]
    public function test_a_closed_unrouted_lead_is_not_moved(string $stage): void
    {
        $this->deliver();
        $lead = Lead::sole();
        $lead->update(['stage' => $stage]);
        $this->map('tower b', $this->tower);

        $this->deliver(['tags' => ['Tower B'], 'updatedAt' => '2026-09-08T06:00:00.000Z'], type: 'update');

        $lead->refresh();
        $this->assertSame($this->default->id, $lead->project_id);
        $this->assertSame($stage, $lead->stage);
        $this->assertSame(0, IntegrationEvent::where('result', 'moved_to_project')->count());
    }

    /* ---------------- settings ---------------- */

    public function test_the_whatsapp_card_lists_the_known_tags(): void
    {
        $this->deliver(['tags' => ['Spring Offer'], 'masterstatus' => null]);
        $this->map('tower b', $this->tower, $this->tom, 'Tower B');

        $this->actingAs($this->admin)->get('/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('cards.2.tags.0.form_name', 'Spring Offer')
            ->where('cards.2.tags.0.project_id', null)
            ->where('cards.2.tags.0.leads', 1)
            ->where('cards.2.tags.0.unrouted_leads', 1)
            ->where('cards.2.tags.1.form_name', 'Tower B')
            ->where('cards.2.tags.1.project_id', $this->tower->id)
            ->where('cards.2.tags.1.assign_to_user_id', $this->tom->id)
            ->where('cards.2.tags.1.leads', 0)
        );
    }

    public function test_the_admin_maps_a_tag_and_adds_one_without_touching_the_key_or_outbound_settings(): void
    {
        $integration = Integration::forProvider('whatsapp');
        $integration->mergeSettings(['auth_token' => 'outbound-auth-token-9876', 'api_enabled' => true]);
        $integration->save();
        $this->deliver(['tags' => ['Spring Offer'], 'masterstatus' => null]);
        $route = LeadFormRoute::forProvider('whatsapp')->sole();

        $this->save(['tags' => [
            ['form_id' => 'spring offer', 'form_name' => 'Spring Offer', 'project_id' => $this->tower->id, 'assign_to_user_id' => $this->tom->id],
            ['form_id' => '  Park   VIEW ', 'form_name' => 'Park View', 'project_id' => $this->park->id, 'assign_to_user_id' => null],
        ]])->assertSessionHasNoErrors();

        $route->refresh();
        $this->assertSame($this->tower->id, $route->project_id);
        $this->assertSame($this->tom->id, $route->assign_to_user_id);

        $added = LeadFormRoute::forProvider('whatsapp')->where('form_id', 'park view')->sole();
        $this->assertSame('Park View', $added->form_name);
        $this->assertSame($this->park->id, $added->project_id);

        // mapping the tag clears its alert
        $this->assertNotNull(Alert::sole()->read_at);

        $integration->refresh();
        $this->assertSame(self::KEY, $integration->setting('api_key'));
        $this->assertSame('outbound-auth-token-9876', $integration->setting('auth_token'));
        $this->assertTrue($integration->setting('api_enabled'));
    }

    public function test_a_manually_added_tag_routes_the_next_lead(): void
    {
        $this->save(['tags' => [['form_id' => 'Tower B', 'form_name' => 'Tower B', 'project_id' => $this->tower->id]]]);

        $this->deliver(['tags' => ['tower b']]);

        $this->assertSame($this->tower->id, Lead::sole()->project_id);
    }

    public function test_an_unused_tag_can_be_removed_but_one_with_leads_cannot(): void
    {
        $this->map('unused', $this->tower);
        $this->deliver(['tags' => ['Spring Offer'], 'masterstatus' => null]);

        $this->save(['removed_tags' => ['unused']])->assertSessionHasNoErrors();
        $this->assertFalse(LeadFormRoute::where('form_id', 'unused')->exists());

        $this->save(['removed_tags' => ['spring offer']])->assertSessionHasErrors('removed_tags.0');
        $this->assertTrue(LeadFormRoute::where('form_id', 'spring offer')->exists());
    }

    public function test_a_tags_project_and_telecaller_must_be_real_and_active(): void
    {
        $this->tower->update(['is_active' => false]);

        $this->save(['tags' => [['form_id' => 'tower b', 'project_id' => $this->tower->id, 'assign_to_user_id' => $this->admin->id]]])
            ->assertSessionHasErrors(['tags.0.project_id', 'tags.0.assign_to_user_id']);

        $this->assertSame(0, LeadFormRoute::count());
    }

    /* ---------------- fixtures ---------------- */

    private function map(string $tag, Project $project, ?User $telecaller = null, ?string $name = null): LeadFormRoute
    {
        return LeadFormRoute::create([
            'provider' => 'whatsapp',
            'form_id' => $tag,
            'form_name' => $name ?? $tag,
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
            'updatedAt' => '2026-09-07T06:00:00.000Z',
            'masterstatus' => 'New',
        ], $data), fn ($value) => $value !== null);

        $this->postJson(self::URL, ['type' => $type, 'data' => $data, 'changedFields' => []], ['x-api-key' => self::KEY])
            ->assertOk();
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->admin)->put('/integrations/whatsapp', array_merge([
            'default_project_id' => $this->default->id,
            'assign_to_user_id' => $this->tia->id,
            'is_active' => true,
        ], $overrides));
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
