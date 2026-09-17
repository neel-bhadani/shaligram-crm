<?php

namespace Tests\Feature;

use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Merging one channel partner into another.
 *
 * This is the cleanup for what inline creation costs, and it is the reason the
 * feature can afford to let a salesperson add a partner mid-call at all. Three
 * people logging broker leads on the same afternoon will enter "Shreeji",
 * "Shreeji Realty" and "Shreeji Realty Pvt Ltd"; the typeahead, the near-match
 * warning and the unique index catch most of that and cannot catch somebody who
 * sincerely believes the firm they are typing is a new one. Without merge the
 * broker report degrades into a list of near-identical names within a month.
 *
 * What is being protected:
 *
 *   EVERY lead moves, including the ones the admin running the merge cannot
 *   see and the ones that have been soft deleted — a partial move is worse than
 *   none, because it splits one broker's business across a row that exists and
 *   a row that does not.
 *
 *   the hierarchy survives it. A firm's brokers travel with the firm, and the
 *   one direction that would leave them under a broker is refused.
 *
 *   the door. Merging is open to every signed-in role — the same crowd that
 *   created the duplicates — while deleting stays admin-only, and the shape
 *   rules in MergeChannelPartnerRequest are the real gate whichever role the
 *   request comes from.
 *
 * @see \App\Http\Controllers\ChannelPartnerController::merge()
 * @see \App\Http\Requests\MergeChannelPartnerRequest
 */
class ChannelPartnerMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $alice;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-07 11:00'));

        $this->admin   = $this->user('admin', 'Ann');
        $this->alice   = $this->user('telecaller', 'Alice');
        $this->project = Project::create(['name' => 'Alpha']);
    }

    /* ---------------- the door ---------------- */

    public function test_every_role_can_merge_and_only_delete_stays_admin_only(): void
    {
        foreach (['telecaller', 'salesperson'] as $role) {
            $staff  = $this->user($role, ucfirst($role) . 'X');
            $source = $this->partner('firm', "Shreeji {$role}");
            $target = $this->partner('firm', "Shreeji Realty {$role}");

            $this->actingAs($staff)
                ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
                ->assertSessionHasNoErrors();

            $this->assertSoftDeleted('channel_partners', ['id' => $source->id]);
            $this->assertNotSoftDeleted('channel_partners', ['id' => $target->id]);

            // merging a partner away is open to every role; deleting one is not
            $this->actingAs($staff)->delete("/channel-partners/{$target->id}")->assertForbidden();
        }
    }

    /* ---------------- the move ---------------- */

    public function test_a_merge_moves_every_lead_and_soft_deletes_the_source(): void
    {
        $source = $this->partner('broker', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty');

        $mine   = $this->lead(['channel_partner_id' => $source->id, 'source' => 'broker'], $this->admin);
        $theirs = $this->lead(['channel_partner_id' => $source->id, 'source' => 'broker'], $this->alice);
        $stays  = $this->lead(['channel_partner_id' => $target->id, 'source' => 'broker'], $this->admin);

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($target->id, $mine->fresh()->channel_partner_id);
        $this->assertSame(
            $target->id,
            $theirs->fresh()->channel_partner_id,
            'a lead the admin cannot see still has to move, or the merge splits the broker in two',
        );
        $this->assertSame($target->id, $stays->fresh()->channel_partner_id);

        $this->assertSoftDeleted('channel_partners', ['id' => $source->id]);
        $this->assertNotSoftDeleted('channel_partners', ['id' => $target->id]);
    }

    /**
     * Nothing is left pointing at the row that was merged away — the assertion
     * the whole operation is for.
     */
    public function test_no_lead_is_left_on_the_merged_away_partner(): void
    {
        $source = $this->partner('broker', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty');

        foreach (range(1, 5) as $i) {
            $this->lead(['channel_partner_id' => $source->id, 'source' => 'broker'], $this->admin);
        }

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id]);

        $this->assertSame(0, Lead::withTrashed()->where('channel_partner_id', $source->id)->count());
        $this->assertSame(5, Lead::where('channel_partner_id', $target->id)->count());

        // and the application's own invariant is untouched by a bulk move:
        // every open lead still holds exactly one pending follow-up
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_soft_deleted_lead_moves_with_the_rest(): void
    {
        $source = $this->partner('broker', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty');

        $deleted = $this->lead(['channel_partner_id' => $source->id, 'source' => 'broker'], $this->admin);
        $deleted->delete();

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id]);

        $this->assertSame(
            $target->id,
            Lead::withTrashed()->findOrFail($deleted->id)->channel_partner_id,
            'a restored lead must not be the one row disagreeing with the rest',
        );
    }

    /** The old free text is history, and a merge is not entitled to rewrite it. */
    public function test_a_merge_does_not_touch_broker_name(): void
    {
        $source = $this->partner('broker', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty');

        $lead = $this->lead([
            'channel_partner_id' => $source->id,
            'source'             => 'broker',
            'broker_name'        => 'Shreeji (old text)',
        ], $this->admin);

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id]);

        $this->assertSame('Shreeji (old text)', $lead->fresh()->broker_name);
        // the FK is set now, so the display prefers the partner
        $this->assertSame('Shreeji Realty', $lead->fresh()->broker_label);
    }

    /* ---------------- the hierarchy ---------------- */

    public function test_a_firms_brokers_move_with_it(): void
    {
        $source = $this->partner('firm', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty');
        $broker = $this->partner('broker', 'Ravi Kumar', ['parent_id' => $source->id]);

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
            ->assertSessionHasNoErrors();

        $broker->refresh()->load('parent');

        $this->assertSame($target->id, $broker->parent_id);
        $this->assertSame(
            'Ravi Kumar — Shreeji Realty',
            $broker->display_label,
            'the label keeps the half that tells two Ravis apart',
        );
    }

    public function test_a_firm_holding_brokers_cannot_be_merged_into_a_broker(): void
    {
        $source = $this->partner('firm', 'Shreeji');
        $target = $this->partner('broker', 'Ravi Kumar');
        $this->partner('broker', 'Sunil', ['parent_id' => $source->id]);

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
            ->assertSessionHasErrors('target_id');

        $this->assertNotSoftDeleted('channel_partners', ['id' => $source->id]);
    }

    public function test_a_firm_with_no_brokers_can_be_merged_into_a_broker(): void
    {
        $source = $this->partner('firm', 'Shreeji');
        $target = $this->partner('broker', 'Ravi Kumar');

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('channel_partners', ['id' => $source->id]);
    }

    /* ---------------- the refusals ---------------- */

    public function test_a_partner_cannot_be_merged_into_itself(): void
    {
        $partner = $this->partner('firm', 'Shreeji Realty');

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$partner->id}/merge", ['target_id' => $partner->id])
            ->assertSessionHasErrors('target_id');

        $this->assertNotSoftDeleted('channel_partners', ['id' => $partner->id]);
    }

    public function test_merging_into_a_switched_off_partner_is_refused(): void
    {
        $source = $this->partner('broker', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty', ['is_active' => false]);

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
            ->assertSessionHasErrors('target_id');
    }

    public function test_merging_into_a_deleted_partner_is_refused(): void
    {
        $source = $this->partner('broker', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty');

        $target->delete();

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id])
            ->assertSessionHasErrors('target_id');
    }

    /* ---------------- afterwards ---------------- */

    /**
     * The source's name is released by the soft delete, so the spelling can be
     * entered again if it turns out to be a different firm after all.
     */
    public function test_the_merged_away_name_is_free_again(): void
    {
        $source = $this->partner('firm', 'Shreeji Realty');
        $target = $this->partner('firm', 'Shreeji Realty Pvt Ltd');

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id]);

        $this->assertNull($source->fresh()->name_key);

        $this->actingAs($this->admin)
            ->postJson('/channel-partners/quick', [
                'name' => 'Shreeji Realty', 'type' => 'firm', 'phone' => '9876543210',
            ])
            ->assertCreated();
    }

    /**
     * The whole point: after the cleanup the report reads as one broker with
     * one set of numbers rather than two rows that each tell half the story.
     */
    public function test_the_report_reads_as_one_partner_after_a_merge(): void
    {
        $source = $this->partner('broker', 'Shreeji');
        $target = $this->partner('firm', 'Shreeji Realty');

        $a = $this->lead(['channel_partner_id' => $source->id, 'source' => 'broker'], $this->admin);
        $b = $this->lead(['channel_partner_id' => $target->id, 'source' => 'broker'], $this->admin);

        $this->history($a, 'booking_done', now());
        $this->history($b, 'booking_done', now());

        $before = $this->reportRows();

        $this->assertSame(1, $before[$source->id]['booked']);
        $this->assertSame(1, $before[$target->id]['booked']);

        $this->actingAs($this->admin)
            ->post("/channel-partners/{$source->id}/merge", ['target_id' => $target->id]);

        $after = $this->reportRows();

        $this->assertSame(2, $after[$target->id]['total']);
        $this->assertSame(2, $after[$target->id]['booked']);
        $this->assertSame(0, $after[$source->id]['total'], 'the emptied row stays, marked removed');
        $this->assertSame('Shreeji (removed)', $after[$source->id]['label']);
    }

    /* ---------------- helpers ---------------- */

    private function reportRows(): array
    {
        $response = $this->actingAs($this->admin)
            ->get('/reports/leads?' . http_build_query([
                'group' => 'channel_partner', 'range' => '30', 'reset' => 1,
            ]));

        $response->assertOk();

        return collect($response->viewData('page')['props']['rows'])->keyBy('key')->all();
    }

    private function partner(string $type, string $name, array $attributes = []): ChannelPartner
    {
        return ChannelPartner::create($attributes + [
            'name'  => $name,
            'type'  => $type,
            'phone' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
        ]);
    }

    private function lead(array $attributes, User $owner): Lead
    {
        return Lead::create($attributes + [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            // terminal, so the "every open lead holds a pending to-do" invariant
            // is not broken by the fixture itself
            'stage'         => 'booking_done',
            'assigned_to'   => $owner->id,
            'created_by'    => $this->admin->id,
        ]);
    }

    private function history(Lead $lead, string $stage, Carbon $at): Todo
    {
        return Todo::create([
            'lead_id'       => $lead->id,
            'assigned_to'   => $lead->assigned_to,
            'type'          => 'call',
            'scheduled_at'  => $at->copy()->subDay(),
            'status'        => 'completed',
            'completed_at'  => $at,
            'completed_by'  => $lead->assigned_to,
            'outcome_stage' => $stage,
        ]);
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'first_name'    => $name,
            'last_name'     => 'Test',
            'email'         => strtolower($name) . '@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role'          => $role,
            'is_active'     => true,
            'password'      => 'password',
        ]);
    }
}
