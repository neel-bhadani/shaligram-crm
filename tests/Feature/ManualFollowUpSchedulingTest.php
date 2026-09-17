<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Follow-ups are scheduled by hand.
 *
 * Nothing decides when the next task is due any more — no interval table, no
 * retry ladder, no working-hours clamp. The user types a datetime on the form
 * they are already filling in and it is saved exactly as entered, Sunday
 * evening included.
 *
 * That removed the one thing guaranteeing an open lead always had a task, so
 * the guarantee moved into validation: the date is required whenever the save
 * leaves the lead open. Most of what is below is that rule, from both ends —
 * the forms that must demand it, and the invariant it exists to protect.
 *
 * @see \App\Services\LeadFollowUpService
 * @see \App\Http\Requests\Concerns\SchedulesFollowUp
 */
class ManualFollowUpSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $telecaller;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin      = $this->user('admin', 'Ann');
        $this->telecaller = $this->user('telecaller', 'Tara');

        $this->project = Project::create(['name' => 'Alpha']);

        // a Wednesday evening, deliberately: 8:30 PM on a Sunday used to be
        // dragged to Monday morning, and now nothing may touch it
        Carbon::setTestNow(Carbon::parse('2026-09-02 14:03'));
    }

    /* ---------------- adding a lead ---------------- */

    public function test_adding_an_open_lead_books_the_follow_up_that_was_typed(): void
    {
        $when = '2026-09-06 20:30';   // a Sunday evening, outside any office hours

        $this->actingAs($this->admin)
            ->post('/leads', $this->payload([
                'stage'          => 'connected',
                'follow_up_type' => 'whatsapp',
                'follow_up_at'   => $when,
                'follow_up_remarks' => 'Send the floor plan first.',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $todo = Todo::where('status', 'pending')->firstOrFail();

        $this->assertSame($when, $todo->scheduled_at->format('Y-m-d H:i'), 'saved exactly as entered');
        $this->assertSame('whatsapp', $todo->type);
        $this->assertSame('Send the floor plan first.', $todo->remarks);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_adding_a_lead_at_a_terminal_stage_creates_no_task_and_needs_no_date(): void
    {
        foreach ([['booking_done', ['booked_unit' => 'A-1']], ['lost', ['reason' => 'budget']]] as [$stage, $extra]) {
            $this->actingAs($this->admin)
                ->post('/leads', $this->payload($extra + [
                    'stage'          => $stage,
                    'follow_up_type' => null,
                    'follow_up_at'   => null,
                ]))
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(2, Lead::count());
        $this->assertSame(0, Todo::where('status', 'pending')->count());
    }

    public function test_adding_an_open_lead_without_a_date_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['stage' => 'connected', 'follow_up_at' => null]))
            ->assertSessionHasErrors('follow_up_at');

        $this->assertSame(0, Lead::count());
    }

    public function test_a_follow_up_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['follow_up_at' => now()->subHour()->format('Y-m-d H:i')]))
            ->assertSessionHasErrors('follow_up_at');

        $this->assertSame(0, Lead::count());
    }

    public function test_a_type_that_is_not_configured_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['follow_up_type' => 'carrier_pigeon']))
            ->assertSessionHasErrors('follow_up_type');
    }

    /** Every open stage a lead can be typed in at leaves it with one task. */
    public function test_every_open_creation_stage_leaves_the_invariant_intact(): void
    {
        $open = array_diff(array_keys(config('crm.stages')), config('crm.terminal_stages'));

        foreach ($open as $stage) {
            $this->actingAs($this->admin)
                ->post('/leads', $this->payload(['stage' => $stage]))
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(count($open), Lead::count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        // exactly one pending task per open lead, never two
        $this->assertSame(Lead::open()->count(), Todo::where('status', 'pending')->count());
    }

    /* ---------------- logging a call ---------------- */

    public function test_logging_a_call_books_the_next_task_at_the_date_the_user_picked(): void
    {
        $todo = $this->openLead();
        $when = '2026-09-06 20:30';

        $this->actingAs($this->admin)
            ->post("/todos/{$todo->id}/complete", [
                'stage'   => 'details_shared',
                'remarks' => 'Sent the brochure.',
                'follow_up_type'    => 'call',
                'follow_up_at'      => $when,
                'follow_up_remarks' => 'Ask about the loan.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $next = Todo::where('status', 'pending')->firstOrFail();

        $this->assertSame($when, $next->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('Ask about the loan.', $next->remarks);
        $this->assertSame($todo->id, $next->rescheduled_from_id);

        // the call just logged keeps its own remark, and they are not the same
        $this->assertSame('Sent the brochure.', $todo->fresh()->remarks);
        $this->assertSame('completed', $todo->fresh()->status);
    }

    /**
     * The save is scoped to one task. Completing Follow-up A must leave
     * Follow-up B's row exactly where it was — the stale-form bug is
     * frontend state, but the wall between the two rows is worth guarding
     * from the server side too.
     */
    public function test_completing_one_follow_up_leaves_another_untouched(): void
    {
        $a = $this->openLead();
        $b = $this->openLead();

        $this->actingAs($this->admin)
            ->post("/todos/{$a->id}/complete", [
                'stage'             => 'details_shared',
                'remarks'           => 'A TEST VALUE',
                'follow_up_type'    => 'call',
                'follow_up_at'      => '2026-09-06 20:30',
                'follow_up_remarks' => 'A NEXT REMARK',
            ])->assertRedirect()->assertSessionHasNoErrors();

        // A changed as expected: closed, with the remark the form sent
        $this->assertSame('completed', $a->fresh()->status);
        $this->assertSame('A TEST VALUE', $a->fresh()->remarks);

        // B untouched: still pending, still carrying its own values
        $this->assertSame('pending', $b->fresh()->status);
        $this->assertSame($b->scheduled_at->toDateTimeString(), $b->fresh()->scheduled_at->toDateTimeString());
        $this->assertSame($b->remarks, $b->fresh()->remarks);
    }

    public function test_logging_a_call_that_leaves_the_lead_open_demands_a_date(): void
    {
        $todo = $this->openLead();

        $this->actingAs($this->admin)
            ->post("/todos/{$todo->id}/complete", [
                'stage' => 'connected', 'remarks' => 'Spoke.',
            ])->assertSessionHasErrors(['follow_up_at', 'follow_up_type']);

        // and nothing moved: the task is still pending, the stage still fresh
        $this->assertSame('pending', $todo->fresh()->status);
        $this->assertSame('fresh', $todo->lead->fresh()->stage);
    }

    public function test_booking_the_lead_closes_it_and_asks_for_no_date(): void
    {
        $todo = $this->openLead();

        $this->actingAs($this->admin)
            ->post("/todos/{$todo->id}/complete", [
                'stage' => 'booking_done', 'remarks' => 'Booked A-402.',
                'booked_unit' => 'A-402',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $lead = $todo->lead->fresh();

        $this->assertSame('booking_done', $lead->stage);
        $this->assertSame('A-402', $lead->booked_unit);
        $this->assertSame(0, Todo::where('status', 'pending')->count());
        $this->assertSame('booking_done', $todo->fresh()->outcome_stage);
    }

    public function test_losing_the_lead_cancels_the_task_it_was_holding(): void
    {
        $todo = $this->openLead();

        $this->actingAs($this->admin)
            ->post("/todos/{$todo->id}/complete", [
                'stage' => 'lost', 'remarks' => 'Bought elsewhere.', 'reason' => 'competitor',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Todo::where('status', 'pending')->count());
        $this->assertSame('lost', $todo->lead->fresh()->stage);
    }

    /* ---------------- the site visit and the handover ---------------- */

    /**
     * One date, not two. The visit the customer agreed to *is* the next task,
     * and scheduling it hands the lead — and that task — to a salesperson.
     */
    public function test_the_site_visit_is_the_next_task_and_goes_to_the_salesperson(): void
    {
        $sales = $this->user('salesperson', 'Sam');
        $todo  = $this->openLead();
        $when  = '2026-09-06 20:30';

        $this->actingAs($this->telecaller)
            ->post("/todos/{$todo->id}/complete", [
                'stage'   => 'site_visit_scheduled',
                'remarks' => 'Customer will come Sunday evening.',
                'follow_up_type' => 'site_visit',
                'follow_up_at'   => $when,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $lead = $todo->lead->fresh();
        $next = Todo::where('status', 'pending')->firstOrFail();

        $this->assertSame($sales->id, $lead->assigned_to, 'the lead moves to the salesperson');
        $this->assertSame('salesperson', $lead->assigned_role);
        $this->assertSame($sales->id, $next->assigned_to, 'and so does the task');
        $this->assertSame('site_visit', $next->type);
        $this->assertSame($when, $next->scheduled_at->format('Y-m-d H:i'), 'the visit is not moved');
    }

    /* ---------------- editing a lead ---------------- */

    /**
     * A stage change from the lead form replaces the pending task, so it has to
     * bring a date with it — otherwise the cancel would leave the lead open
     * with nothing on anyone's list.
     */
    public function test_a_stage_change_from_the_lead_form_needs_a_date_and_replaces_the_task(): void
    {
        $todo = $this->openLead();
        $lead = $todo->lead;

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, ['stage' => 'in_discussion']))
            ->assertSessionHasErrors('follow_up_at');

        $when = '2026-09-08 11:00';

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage'          => 'in_discussion',
                'follow_up_type' => 'meeting',
                'follow_up_at'   => $when,
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $pending = Todo::where('status', 'pending')->get();

        $this->assertCount(1, $pending, 'the old task went with the stage change');
        $this->assertSame($when, $pending->first()->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('meeting', $pending->first()->type);
        $this->assertSame('cancelled', $todo->fresh()->status);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /**
     * Editing anything else leaves the task alone. Asking for a date on a save
     * that is not moving the stage would cancel and recreate a perfectly good
     * task every time somebody fixed a spelling.
     */
    public function test_editing_a_lead_without_moving_its_stage_keeps_its_task(): void
    {
        $todo = $this->openLead();
        $lead = $todo->lead;

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, ['first_name' => 'Meerah']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Meerah', $lead->fresh()->first_name);
        $this->assertSame('pending', $todo->fresh()->status, 'the task must survive an ordinary edit');
        $this->assertSame(1, Todo::where('status', 'pending')->count());
    }

    /** Closing a lead from the form cancels its task and asks for no date. */
    public function test_closing_a_lead_from_the_form_cancels_its_task(): void
    {
        $todo = $this->openLead();
        $lead = $todo->lead;

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'lost', 'reason' => 'budget',
            ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $todo->fresh()->status);
        $this->assertSame(0, Todo::where('status', 'pending')->count());
    }

    /* ---------------- the manual to-do form ---------------- */

    /**
     * The Add to-do form is the third way a follow-up date is typed, and it
     * goes through TodoRequest rather than the trait — so the same rule has to
     * be proven here separately.
     */
    public function test_adding_a_to_do_by_hand_in_the_past_is_rejected(): void
    {
        $lead = $this->leadWithoutTask();

        $this->actingAs($this->admin)
            ->post('/todos', [
                'lead_id'      => $lead->id,
                'type'         => 'call',
                'scheduled_at' => now()->subMinute()->format('Y-m-d H:i'),
            ])
            ->assertSessionHasErrors('scheduled_at');

        $this->assertSame(0, Todo::where('lead_id', $lead->id)->count());
    }

    public function test_adding_a_to_do_by_hand_in_the_future_is_saved_as_entered(): void
    {
        $lead = $this->leadWithoutTask();
        $when = '2026-09-06 20:30';

        $this->actingAs($this->admin)
            ->post('/todos', [
                'lead_id'      => $lead->id,
                'type'         => 'site_visit',
                'scheduled_at' => $when,
                'remarks'      => 'Bring the sample flat keys.',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $todo = Todo::where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame($when, $todo->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('site_visit', $todo->type);
    }

    /* ---------------- rescheduling ---------------- */

    /**
     * The exception worth naming: an overdue task holds a datetime in the past
     * by definition, and that is the record being edited. The rule must judge
     * the value being submitted and nothing else, or a task could never be
     * rescheduled once it slipped — which is the only moment anyone opens the
     * form.
     */
    public function test_an_overdue_task_can_be_rescheduled_into_the_future(): void
    {
        $todo = $this->openLead();

        // let it go overdue, exactly as a real one does
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00'));
        $this->assertTrue($todo->fresh()->scheduled_at->isPast(), 'the record itself is stale');

        $when = '2026-09-11 16:00';

        $this->actingAs($this->admin)
            ->put("/todos/{$todo->id}", [
                'lead_id'      => $todo->lead_id,
                'type'         => $todo->type,
                'scheduled_at' => $when,
            ])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($when, $todo->fresh()->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('pending', $todo->fresh()->status);
    }

    /** Rescheduling to another past datetime is still refused. */
    public function test_rescheduling_into_the_past_is_rejected(): void
    {
        $todo = $this->openLead();
        $was  = $todo->fresh()->scheduled_at;

        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00'));

        $this->actingAs($this->admin)
            ->put("/todos/{$todo->id}", [
                'lead_id'      => $todo->lead_id,
                'type'         => $todo->type,
                'scheduled_at' => now()->subHour()->format('Y-m-d H:i'),
            ])
            ->assertSessionHasErrors('scheduled_at');

        $this->assertTrue($was->equalTo($todo->fresh()->scheduled_at), 'nothing moved');
    }

    /* ---------------- the invariant, after all of it ---------------- */

    public function test_the_pending_todo_invariant_survives_the_whole_sequence(): void
    {
        $sales = $this->user('salesperson', 'Sam');

        // added open, then a call, then a site visit, then booked
        $todo = $this->openLead();

        $this->actingAs($this->telecaller)->post("/todos/{$todo->id}/complete", [
            'stage' => 'details_shared', 'remarks' => 'Sent.',
            'follow_up_type' => 'call', 'follow_up_at' => '2026-09-04 10:00',
        ])->assertSessionHasNoErrors();

        $second = Todo::where('status', 'pending')->firstOrFail();

        $this->actingAs($this->telecaller)->post("/todos/{$second->id}/complete", [
            'stage' => 'site_visit_scheduled', 'remarks' => 'Coming Sunday.',
            'follow_up_type' => 'site_visit', 'follow_up_at' => '2026-09-06 20:30',
        ])->assertSessionHasNoErrors();

        $visit = Todo::where('status', 'pending')->firstOrFail();

        // a lead added at a terminal stage alongside, which must stay taskless
        $this->actingAs($this->admin)->post('/leads', $this->payload([
            'stage' => 'lost', 'reason' => 'budget', 'follow_up_at' => null, 'follow_up_type' => null,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());

        $this->actingAs($sales)->post("/todos/{$visit->id}/complete", [
            'stage' => 'booking_done', 'remarks' => 'Booked on the spot.', 'booked_unit' => 'C-101',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
        $this->assertSame(0, Todo::where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->count(), 'both leads are closed');
    }

    /* ---------------- fixtures ---------------- */

    /**
     * An open lead at `fresh`, with its first task, through the real form.
     *
     * Always added by the admin — only admins and salespeople may post the lead
     * form — which is also what puts the lead on the telecaller: the controller
     * assigns an admin's new lead to the first active telecaller, so the task
     * comes back owned by Tara and ready to be handed over.
     */
    private function openLead(): Todo
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload(['stage' => 'fresh']))
            ->assertSessionHasNoErrors();

        $lead = Lead::latest('id')->firstOrFail();

        return Todo::where('lead_id', $lead->id)->where('status', 'pending')->firstOrFail();
    }

    /**
     * An open lead holding no pending task — the only kind the Add to-do form
     * offers, since a lead may hold just one.
     */
    private function leadWithoutTask(): Lead
    {
        $lead = $this->openLead()->lead;

        // deleted rather than cancelled, so the assertions below can count
        // every row on the lead and mean the one the form just made
        Todo::where('lead_id', $lead->id)->delete();

        return $lead;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at'   => now()->addDay()->format('Y-m-d H:i'),
        ], $overrides);
    }

    /** The lead form resubmits everything it is showing, so an edit does too. */
    private function editPayload(Lead $lead, array $overrides = []): array
    {
        return array_merge([
            'first_name'    => $lead->first_name,
            'last_name'     => $lead->last_name,
            'mobile_number' => $lead->mobile_number,
            'project_id'    => $lead->project_id,
            'source'        => $lead->source,
            'stage'         => $lead->stage,
        ], $overrides);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first, 'last_name' => 'User',
            'email' => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
