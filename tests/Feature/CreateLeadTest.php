<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The whole create path, duplicates included. The same number may be on
 * several leads, even in one project: the form and the save both warn, and
 * neither refuses. A repeated submission of one form is still one lead.
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

    /* ---------------- duplicates: a warning, never a refusal ---------------- */

    public function test_same_project_names_the_customer_and_who_to_talk_to_and_still_saves(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['submission_key' => 'first']));

        $this->checkDuplicate($this->admin, $this->alpha->id)
            ->assertJsonPath('exists', true)
            ->assertJsonPath('headline', 'Already in the system — Neel Bhadani · Alpha · Fresh · with Tia User')
            ->assertJsonPath('lines', [])
            ->assertJsonPath('detail', 'You can still save. If this is the same person, talk to Tia first.');

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['first_name' => 'Second', 'submission_key' => 'second']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'Already in the system — Neel Bhadani · Alpha · Fresh · with Tia User. You can still save. If this is the same person, talk to Tia first.');

        $this->assertSame(2, Lead::where('mobile_number', '9512779297')->where('project_id', $this->alpha->id)->count());
        $this->assertSame(2, Todo::where('status', 'pending')->count(), 'each lead has its own follow-up');
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_different_project_says_it_is_normal_and_still_saves(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        $this->checkDuplicate($this->admin, $this->beta->id)
            ->assertJsonPath('headline', 'This number is also on Alpha — Neel Bhadani · Fresh · with Tia User')
            ->assertJsonPath('detail', 'Same person enquiring about another project is normal. Save as usual.');

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['project_id' => $this->beta->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Lead::count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_several_leads_are_listed_newest_first_up_to_three_and_a_count(): void
    {
        foreach (['Alpha one', 'Beta one', 'Alpha two', 'Beta two'] as $i => $name) {
            $this->travel($i)->minutes();
            $this->actingAs($this->admin)->post('/leads', $this->payload([
                'first_name' => $name,
                'project_id' => str_starts_with($name, 'Alpha') ? $this->alpha->id : $this->beta->id,
                'submission_key' => $name,
            ]));
        }
        Lead::where('first_name', 'Alpha one')->update(['stage' => 'lost', 'reason' => 'budget']);

        $this->checkDuplicate($this->admin, $this->alpha->id)
            ->assertJsonPath('headline', 'Already in the system — 4 leads with this number')
            ->assertJsonPath('lines', ['Beta · Fresh · Tia User', 'Alpha · Fresh · Tia User', 'Beta · Fresh · Tia User', 'and 1 more'])
            ->assertJsonPath('detail', 'You can still save.');

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['first_name' => 'Fifth', 'submission_key' => 'fifth']))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, Lead::count());
    }

    /**
     * A telecaller who does not own the matching lead cannot open it, so is
     * told the project and nothing else — not the customer, not the owner.
     */
    public function test_a_telecaller_who_cannot_open_the_lead_gets_the_masked_warning_and_still_saves(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        $other = User::create([
            'first_name' => 'Tara', 'last_name' => 'User', 'email' => 'tara@example.test',
            'mobile_number' => '9100000001', 'role' => 'telecaller', 'is_active' => true,
            'password' => 'password', 'permissions' => ['add_leads' => true],
        ]);

        $response = $this->checkDuplicate($other, $this->alpha->id)
            ->assertJsonPath('headline', 'Already in the system — on Alpha, handled by another team member.')
            ->assertJsonPath('detail', 'You can still save. Check with the admin before calling.');

        $this->assertStringNotContainsString('Neel', $response->getContent());
        $this->assertStringNotContainsString('Tia', $response->getContent());

        $this->actingAs($other)
            ->post('/leads', $this->payload(['first_name' => 'Second', 'submission_key' => 'second']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn (string $warning) => ! str_contains($warning, 'Neel') && ! str_contains($warning, 'Tia'));

        $this->assertSame(2, Lead::count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_closed_lead_says_how_long_ago_and_wins_over_the_same_project_wording(): void
    {
        $this->travelTo(now('Asia/Kolkata')->subMonths(2)->subDay());
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'lost', 'reason' => 'budget', 'submission_key' => 'old']));
        $this->travelBack();

        $this->checkDuplicate($this->admin, $this->alpha->id)
            ->assertJsonPath('headline', 'Was in the system — Neel Bhadani · Alpha · Lost (budget) · 2 months ago')
            ->assertJsonPath('detail', "Saving creates a fresh enquiry. That's fine if they're back.");

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['submission_key' => 'new']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Lead::count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_an_existing_number_with_no_project_chosen_still_warns(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        $this->checkDuplicate($this->admin, null)
            ->assertJsonPath('exists', true)
            ->assertJsonPath('headline', 'Already in the system — Neel Bhadani · Alpha · Fresh · with Tia User');
    }

    public function test_with_no_project_chosen_only_projects_the_user_can_see_are_searched(): void
    {
        $this->salesperson->projects()->attach($this->beta);
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        $this->checkDuplicate($this->salesperson, null)->assertExactJson(['exists' => false]);
    }

    public function test_a_new_number_gives_no_warning(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload());

        foreach ([$this->alpha->id, null] as $project) {
            $this->checkDuplicate($this->admin, $project, '9000000009')->assertExactJson(['exists' => false]);
        }

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['mobile_number' => '9000000009']))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');
    }

    public function test_editing_a_lead_onto_a_number_another_lead_has_saves_with_a_warning(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['submission_key' => 'first']));
        $this->actingAs($this->admin)->post('/leads', $this->payload(['first_name' => 'Other', 'mobile_number' => '9000000001', 'submission_key' => 'second']));

        $other = Lead::where('mobile_number', '9000000001')->firstOrFail();

        // the edit form excludes the lead being edited from its own check
        $this->actingAs($this->admin)
            ->postJson('/leads/check-duplicate', ['mobile_number' => '9512779297', 'project_id' => $this->alpha->id, 'lead_id' => $other->id])
            ->assertJsonPath('headline', 'Already in the system — Neel Bhadani · Alpha · Fresh · with Tia User');

        $this->actingAs($this->admin)
            ->put("/leads/{$other->id}", $this->payload(['first_name' => 'Other']))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', 'Already in the system — Neel Bhadani · Alpha · Fresh · with Tia User. You can still save. If this is the same person, talk to Tia first.');

        $this->assertSame('9512779297', $other->fresh()->mobile_number);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_soft_deleted_lead_holding_the_number_is_a_warning_not_a_refusal(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['submission_key' => 'first']));
        Lead::firstOrFail()->delete();

        $this->checkDuplicate($this->admin, $this->alpha->id)
            ->assertJsonPath('headline', 'Was in the system — a deleted lead on Alpha');

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['submission_key' => 'second']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Lead::count());
        $this->assertSame(2, Lead::withTrashed()->count());
    }

    /* ---------------- double submit ---------------- */

    /** A triple-clicked Save posts the same form, submission key and all, three times. */
    public function test_three_identical_posts_from_one_form_make_one_lead(): void
    {
        foreach (range(1, 3) as $click) {
            $this->actingAs($this->admin)
                ->post('/leads', $this->payload(['submission_key' => 'one-opening']))
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
    }

    public function test_a_post_with_no_submission_key_is_guarded_on_its_payload(): void
    {
        foreach (range(1, 3) as $click) {
            $this->actingAs($this->admin)->post('/leads', $this->payload());
        }

        $this->assertSame(1, Lead::count());
    }

    public function test_a_rejected_submission_does_not_use_up_its_key(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['submission_key' => 'retry', 'first_name' => '']))
            ->assertSessionHasErrors('first_name');

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['submission_key' => 'retry']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Lead::count());
    }

    /* ---------------- the lead view ---------------- */

    public function test_the_lead_view_lists_other_leads_on_the_same_number(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['submission_key' => 'first']));
        $this->actingAs($this->admin)->post('/leads', $this->payload(['first_name' => 'Second', 'submission_key' => 'second']));
        $this->actingAs($this->admin)->post('/leads', $this->payload(['first_name' => 'Third', 'project_id' => $this->beta->id]));
        $this->actingAs($this->admin)->post('/leads', $this->payload(['first_name' => 'Elsewhere', 'mobile_number' => '9000000001']));

        $first = Lead::where('first_name', 'Neel')->firstOrFail();

        $same = $this->actingAs($this->admin)->getJson("/leads/{$first->id}")->assertOk()->json('sameMobile');

        $this->assertSame(['Second Bhadani', 'Third Bhadani'], array_column($same, 'name'));
        $this->assertSame(['Alpha', 'Beta'], array_column($same, 'project'));
        $this->assertSame(['fresh', 'fresh'], array_column($same, 'stage'));
        $this->assertSame(['Tia User', 'Tia User'], array_column($same, 'owner'));
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
                'stage' => 'in_discussion',
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

    private function checkDuplicate(User $as, ?int $projectId, string $mobile = '9512779297'): TestResponse
    {
        return $this->actingAs($as)
            ->postJson('/leads/check-duplicate', array_filter(['mobile_number' => $mobile, 'project_id' => $projectId]))
            ->assertOk();
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
