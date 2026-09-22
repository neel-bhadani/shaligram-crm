<?php

namespace Tests\Feature;

use App\Http\Requests\LeadRequest;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Policies\LeadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A telecaller holds no `edit_leads` by default, yet still has to be able to
 * open a lead they hold, move its stage, and book its next follow-up — the
 * same two things the Follow-ups page already lets them do. LeadPolicy::update()
 * lets them into this form for exactly that; LeadRequest is what turns "may
 * open this form" into "may save these fields", by rejecting any change to
 * the lead's own core details rather than trusting the form to keep them
 * disabled.
 *
 * @see LeadPolicy::update()
 * @see LeadRequest
 */
class TelecallerLeadEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $telecaller;

    private User $salesperson;

    private Project $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-19 10:00'));

        $this->admin = $this->user('admin', 'Ann');
        $this->telecaller = $this->user('telecaller', 'Tia');
        $this->salesperson = $this->user('salesperson', 'Sam');
        $this->alpha = Project::create(['name' => 'Alpha']);
        $this->alpha->salespeople()->attach($this->salesperson->id);

        $this->assertFalse($this->telecaller->can_('edit_leads'), 'the default this fix works around');
    }

    /* ---------------- the fix: stage and follow-up go through ---------------- */

    public function test_a_telecaller_can_change_stage_and_book_a_follow_up_without_edit_leads(): void
    {
        $lead = $this->openLead();

        $this->actingAs($this->telecaller)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'connected',
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('connected', $lead->fresh()->stage);
    }

    /* ---------------- the boundary: everything else is refused, not silently dropped ---------------- */

    /** Each locked field, one at a time — a crafted request cannot pick one the others miss. */
    public function test_a_telecaller_cannot_change_any_of_the_leads_own_core_details(): void
    {
        $lead = $this->openLead();
        $beta = Project::create(['name' => 'Beta']);

        $attempts = [
            'first_name' => 'Someone',
            'middle_name' => 'Else',
            'last_name' => 'Entirely',
            'mobile_number' => '9998887776',
            'email' => 'someone@example.test',
            'project_id' => $beta->id,
            'source' => 'facebook',
        ];

        foreach ($attempts as $field => $value) {
            $this->actingAs($this->telecaller)
                ->put("/leads/{$lead->id}", $this->editPayload($lead, [$field => $value]))
                ->assertSessionHasErrors($field);
        }

        $lead->refresh();

        foreach ($attempts as $field => $value) {
            $this->assertNotEquals($value, $lead->{$field}, "{$field} must not have been saved");
        }
    }

    /** A crafted request cannot smuggle a locked field in alongside a change it IS allowed to make. */
    public function test_a_telecaller_cannot_smuggle_a_core_detail_change_in_with_a_stage_change(): void
    {
        $lead = $this->openLead();
        $originalMobile = $lead->mobile_number;

        $this->actingAs($this->telecaller)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'connected',
                'mobile_number' => '9998887776',
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ]))
            ->assertSessionHasErrors('mobile_number');

        $this->assertSame($originalMobile, $lead->fresh()->mobile_number);
        $this->assertSame('fresh', $lead->fresh()->stage, 'a rejected save takes nothing with it, including the stage');
    }

    /** The lock is telecaller-specific — it must not leak into anyone else who also lacks `edit_leads`. */
    public function test_a_salesperson_without_edit_leads_stays_fully_blocked_not_field_restricted(): void
    {
        $this->salesperson->update(['permissions' => ['edit_leads' => false]]);
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');   // owned by Sam once past handover

        $this->actingAs($this->salesperson)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, ['stage' => 'in_discussion']))
            ->assertForbidden();
    }

    /* ---------------- everyone else keeps exactly what they had ---------------- */

    public function test_an_admin_keeps_full_edit_access_to_every_field(): void
    {
        $lead = $this->openLead();

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'first_name' => 'Renamed',
                'mobile_number' => '9998887776',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('Renamed', $lead->first_name);
        $this->assertSame('9998887776', $lead->mobile_number);
    }

    public function test_a_salesperson_with_edit_leads_keeps_full_edit_access_to_every_field(): void
    {
        $lead = $this->add($this->admin, 'walk_in', 'site_visit_done');   // owned by Sam once past handover

        $this->actingAs($this->salesperson)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'first_name' => 'Renamed',
                'mobile_number' => '9998887776',
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $lead->refresh();
        $this->assertSame('Renamed', $lead->first_name);
        $this->assertSame('9998887776', $lead->mobile_number);
    }

    /* ---------------- the stage dropdown's own rule is unchanged ---------------- */

    public function test_a_telecallers_stage_change_still_has_to_be_a_real_active_stage(): void
    {
        $lead = $this->openLead();

        $this->actingAs($this->telecaller)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, ['stage' => 'not_a_real_stage']))
            ->assertSessionHasErrors('stage');

        $this->assertSame('fresh', $lead->fresh()->stage);
    }

    /* ---------------- shared activity log ---------------- */

    public function test_a_stage_and_follow_up_change_from_a_telecaller_is_logged_and_attributed_to_them(): void
    {
        $lead = $this->openLead();
        $when = now()->addDay()->format('Y-m-d H:i');

        $this->actingAs($this->telecaller)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'connected',
                'follow_up_type' => 'call',
                'follow_up_at' => $when,
            ]))
            ->assertSessionHasNoErrors();

        $stageChanged = LeadActivity::where('lead_id', $lead->id)
            ->where('action', LeadActivity::StageChanged)
            ->latest('id')->firstOrFail();

        $this->assertSame($this->telecaller->id, $stageChanged->user_id);
        $this->assertSame('fresh', $stageChanged->from_value);
        $this->assertSame('connected', $stageChanged->to_value);

        $nextFollowUp = LeadActivity::where('lead_id', $lead->id)
            ->where('action', LeadActivity::NextFollowUp)
            ->latest('id')->firstOrFail();

        $this->assertSame($this->telecaller->id, $nextFollowUp->user_id);
        $this->assertSame('call', $nextFollowUp->field);
    }

    /* ---------------- replacing an existing pending follow-up ---------------- */

    /**
     * Booking a new follow-up from Edit Lead closes whatever the lead was
     * already holding, regardless of whether that one was overdue, due today,
     * or still upcoming — the same unconditional replace the Log Call flow
     * gets from LeadFollowUpService::schedule(), not a second implementation.
     */
    #[DataProvider('existingFollowUpTimings')]
    public function test_setting_a_new_follow_up_closes_whichever_one_the_lead_already_had(Carbon $existingAt): void
    {
        $lead = $this->openLead();
        $old = $lead->pendingTodo;
        $old->update(['scheduled_at' => $existingAt]);

        $newAt = now()->addDays(2)->format('Y-m-d H:i');

        $this->actingAs($this->telecaller)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'connected',
                'follow_up_type' => 'call',
                'follow_up_at' => $newAt,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $old->fresh()->status);

        $pending = Todo::where('lead_id', $lead->id)->where('status', 'pending')->get();
        $this->assertCount(1, $pending, 'never zero, never two');
        $this->assertSame($newAt, $pending->first()->scheduled_at->format('Y-m-d H:i'));

        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public static function existingFollowUpTimings(): array
    {
        return [
            'overdue' => [Carbon::parse('2026-09-16 09:00')],
            'due today' => [Carbon::parse('2026-09-19 18:00')],
            'upcoming' => [Carbon::parse('2026-09-25 09:00')],
        ];
    }

    /* ---------------- fixtures ---------------- */

    /** An open lead at `fresh`, with its first task, assigned to the telecaller. */
    private function openLead(): Lead
    {
        return $this->add($this->admin, 'walk_in', 'fresh');
    }

    private function add(User $creator, string $source, string $stage): Lead
    {
        $mobile = (string) fake()->unique()->numberBetween(9000000000, 9999999999);

        $this->actingAs($creator)
            ->post('/leads', [
                'first_name' => 'Meera',
                'last_name' => 'Sharma',
                'mobile_number' => $mobile,
                'project_id' => $this->alpha->id,
                'source' => $source,
                'stage' => $stage,
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        return Lead::where('mobile_number', $mobile)->firstOrFail();
    }

    private function editPayload(Lead $lead, array $overrides = []): array
    {
        return array_merge([
            'first_name' => $lead->first_name,
            'middle_name' => $lead->middle_name,
            'last_name' => $lead->last_name,
            'mobile_number' => $lead->mobile_number,
            'email' => $lead->email,
            'project_id' => $lead->project_id,
            'source' => $lead->source,
            'stage' => $lead->stage,
        ], $overrides);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'Tester',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
