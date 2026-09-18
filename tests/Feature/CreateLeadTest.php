<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /leads returned a 500 whenever a soft-deleted lead already held the
 * (mobile_number, project_id) pair: LeadRequest's unique rule skipped trashed
 * rows, the table's unique index does not, so validation passed and the insert
 * hit the index. These pin the whole create path, duplicates included.
 */
class CreateLeadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $salesperson;

    private User $telecaller;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'Ann');
        $this->salesperson = $this->user('salesperson', 'Sam');
        $this->telecaller = $this->user('telecaller', 'Tia');

        $this->alpha = Project::create(['name' => 'Alpha']);
        $this->beta = Project::create(['name' => 'Beta']);
    }

    /* ---------------- the happy paths ---------------- */

    public function test_an_admin_creates_a_lead_and_it_lands_on_a_telecaller_with_a_pending_todo(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $lead = Lead::firstOrFail();

        $this->assertSame($this->telecaller->id, $lead->assigned_to);
        $this->assertSame('telecaller', $lead->assigned_role);
        $this->assertSame($this->admin->id, $lead->created_by);
        $this->assertSame($this->telecaller->id, $lead->pendingTodo->assigned_to);
        $this->assertSame($this->admin->id, $lead->pendingTodo->created_by);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /**
     * Routed by the stage, not the creator: a salesperson keeps a lead they add
     * at a salesperson's stage, and a fresh one — which only needs calling —
     * goes to the telecaller like anybody else's.
     */
    public function test_a_salesperson_keeps_a_lead_at_a_sales_stage_and_hands_a_fresh_one_to_a_telecaller(): void
    {
        $this->actingAs($this->salesperson)
            ->post('/leads', $this->payload(['stage' => 'in_discussion']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $kept = Lead::firstOrFail();

        $this->assertSame($this->salesperson->id, $kept->assigned_to);
        $this->assertSame('salesperson', $kept->assigned_role);
        $this->assertSame($this->salesperson->id, $kept->pendingTodo->assigned_to);

        $this->actingAs($this->salesperson)
            ->post('/leads', $this->payload(['mobile_number' => '9512779298']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = Lead::where('mobile_number', '9512779298')->firstOrFail();

        $this->assertSame($this->telecaller->id, $fresh->assigned_to);
        $this->assertSame('telecaller', $fresh->assigned_role);
        $this->assertSame($this->salesperson->id, $fresh->created_by);
        $this->assertSame($this->telecaller->id, $fresh->pendingTodo->assigned_to);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /** assigned_to is NOT NULL on todos, so the fallback has to hold. */
    public function test_an_admin_with_no_active_telecaller_keeps_the_lead_rather_than_assigning_null(): void
    {
        $this->telecaller->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $lead = Lead::firstOrFail();

        $this->assertSame($this->admin->id, $lead->assigned_to);
        // QA-REPORT MIN-13: labelled with the role of the person holding it,
        // not with the telecaller desk nobody was at
        $this->assertSame('admin', $lead->assigned_role);
        $this->assertNotNull($lead->pendingTodo);
        $this->assertSame($this->admin->id, $lead->pendingTodo->assigned_to);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_no_active_salesperson_does_not_break_the_create_path(): void
    {
        $this->salesperson->update(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ---------------- duplicates ---------------- */

    public function test_a_duplicate_mobile_on_the_same_project_is_a_validation_error(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload())
            ->assertSessionHasErrors(['mobile_number' => 'This number already exists for this project.']);

        $this->assertSame(1, Lead::count());
    }

    public function test_the_same_mobile_on_a_different_project_is_allowed(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['project_id' => $this->beta->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Lead::count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /** The reported 500: validation used to wave this through, the index did not. */
    public function test_a_soft_deleted_lead_holding_the_number_is_a_validation_error_not_a_500(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        Lead::firstOrFail()->delete();
        $this->assertSame(0, Lead::count());

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload())
            ->assertStatus(302)
            ->assertSessionHasErrors('mobile_number');

        $this->assertStringContainsString(
            'deleted lead',
            session('errors')->first('mobile_number')
        );

        // nothing half-written: no new lead, no orphan todo
        $this->assertSame(0, Lead::count());
        $this->assertSame(1, Lead::withTrashed()->count());
    }

    public function test_the_live_duplicate_check_agrees_with_the_index_about_deleted_leads(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());
        Lead::firstOrFail()->delete();

        $this->actingAs($this->admin)
            ->postJson('/leads/check-duplicate', [
                'mobile_number' => '9512779297',
                'project_id' => $this->alpha->id,
            ])
            ->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonFragment(['message' => 'This number belongs to a deleted lead on this project. Restore that lead instead of adding it again.']);
    }

    /* ---------------- terminal stages ---------------- */

    public function test_creating_a_lead_as_booking_done_saves_the_unit_and_schedules_nothing(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['stage' => 'booking_done', 'booked_unit' => 'A-1201']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $lead = Lead::firstOrFail();

        $this->assertSame('booking_done', $lead->stage);
        $this->assertSame('A-1201', $lead->booked_unit);

        // nothing is *scheduled* — but the booking itself happened today, and
        // the history row is what the Booking card counts
        $this->assertNull($lead->pendingTodo);
        $this->assertSame(0, Todo::where('status', 'pending')->count());
        $this->assertSame(1, Todo::where('outcome_stage', 'booking_done')->where('status', 'completed')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_creating_a_lead_as_lost_saves_the_reason_and_schedules_nothing(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['stage' => 'lost', 'reason' => 'no_response']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $lead = Lead::firstOrFail();

        $this->assertSame('lost', $lead->stage);
        $this->assertSame('no_response', $lead->reason);

        $this->assertSame(0, Todo::where('status', 'pending')->count());
        $this->assertSame(1, Todo::where('outcome_stage', 'lost')->where('status', 'completed')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /* ---------------- mass assignment ---------------- */

    public function test_every_validated_key_is_a_real_column_on_leads(): void
    {
        $columns = \Schema::getColumnListing('leads');

        $validated = [
            'first_name', 'middle_name', 'last_name', 'mobile_number', 'email',
            // `broker_name` is deliberately NOT in this list any more: the lead
            // form does not send it and LeadRequest does not validate it, which
            // is what keeps a pre-existing lead's text from being written over.
            // The column stays on the table — see the channel-partner migration
            'project_id', 'source', 'channel_partner_id', 'stage', 'reason',
            'requirement', 'booked_unit',
        ];

        foreach ($validated as $key) {
            $this->assertContains($key, $columns, "LeadRequest validates `$key`, which is not a column on leads");
        }
    }

    public function test_the_form_cannot_choose_its_own_owner(): void
    {
        $this->actingAs($this->salesperson)
            ->post('/leads', $this->payload([
                'stage' => 'details_shared',
                'assigned_to' => $this->admin->id,
                'assigned_role' => 'telecaller',
            ]))
            ->assertRedirect();

        $lead = Lead::firstOrFail();

        $this->assertSame($this->salesperson->id, $lead->assigned_to);
        $this->assertSame('salesperson', $lead->assigned_role);
    }

    /* ---------------- fixtures ---------------- */

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Neel',
            'last_name' => 'Bhadani',
            'mobile_number' => '9512779297',
            'email' => 'neel@example.test',
            'project_id' => $this->alpha->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            // follow-ups are booked by hand now, so the form carries the first
            // one. Harmless on the terminal-stage cases: LeadRequest stops
            // requiring these and the service creates no task for a closed lead.
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ], $overrides);
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
