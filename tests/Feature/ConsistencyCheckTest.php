<?php

namespace Tests\Feature;

use App\Console\Commands\CheckConsistency;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `crm:check-consistency` agrees that what the application writes is
 * consistent.
 *
 * This used to be asserted against the demo seed. The leads here are made
 * through the real lead form and moved through the real follow-up form
 * instead, so what is audited is the stage history the application itself
 * writes: newest row matching leads.stage, one pending to-do per open lead,
 * none on a closed one, no transition written twice.
 *
 * @see CheckConsistency
 */
class ConsistencyCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $telecaller;

    private User $salesperson;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-02 10:00'));

        $this->admin = User::factory()->role('admin')->create();
        $this->telecaller = User::factory()->role('telecaller')->create();
        $this->salesperson = User::factory()->role('salesperson')->create();

        $this->project = Project::create(['name' => 'Skyline Residency']);
        $this->project->salespeople()->attach($this->salesperson);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_leads_moved_through_the_real_forms_audit_clean(): void
    {
        $open = $this->addLead();
        $this->logCall($open, $this->telecaller, 'connected');
        // 'connected' is still telecaller-owned: it's this call, reaching
        // the salesperson-owned 'details_shared', that hands the lead over
        $this->logCall($open, $this->telecaller, 'details_shared');

        $booked = $this->addLead();
        $this->logCall($booked, $this->telecaller, 'site_visit_scheduled', 'site_visit');
        $this->logCall($booked, $this->salesperson, 'booking_done');

        $lost = $this->addLead();
        $this->logCall($lost, $this->telecaller, 'lost');

        $this->assertSame(
            ['details_shared', 'booking_done', 'lost'],
            [$open->fresh()->stage, $booked->fresh()->stage, $lost->fresh()->stage],
        );

        $this->artisan('crm:check-consistency')
            ->expectsOutputToContain('Auditing 3 leads.')
            ->expectsOutputToContain('No inconsistencies found.')
            ->assertSuccessful();
    }

    /** So the clean result above is the audit passing, not the audit being blind. */
    public function test_an_open_lead_with_no_pending_to_do_is_reported(): void
    {
        $lead = $this->addLead();
        Todo::where('lead_id', $lead->id)->where('status', 'pending')->delete();

        $this->artisan('crm:check-consistency')
            ->expectsOutputToContain('1 inconsistencies found.')
            ->assertSuccessful();
    }

    /* ---------------- fixtures ---------------- */

    /** Through the lead form, which books the first follow-up and gives it to the telecaller. */
    private function addLead(): Lead
    {
        $this->actingAs($this->admin)
            ->post('/leads', [
                'first_name' => 'Meera',
                'last_name' => 'Sharma',
                'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
                'project_id' => $this->project->id,
                'source' => 'walk_in',
                'stage' => 'fresh',
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ])
            ->assertSessionHasNoErrors();

        return Lead::latest('id')->firstOrFail();
    }

    /**
     * Completes the lead's pending to-do at `$stage`, an hour after the last
     * one, so each history row has its own moment.
     */
    private function logCall(Lead $lead, User $as, string $stage, string $nextType = 'call'): void
    {
        Carbon::setTestNow(now()->addHour());

        $closing = in_array($stage, config('crm.terminal_stages'), true);

        $this->actingAs($as)
            ->post("/todos/{$lead->pendingTodo()->firstOrFail()->id}/complete", array_filter([
                'stage' => $stage,
                'remarks' => 'Logged in the test.',
                'booked_unit' => $stage === 'booking_done' ? 'A-402' : null,
                'reason' => $stage === 'lost' ? 'budget' : null,
                'follow_up_type' => $closing ? null : $nextType,
                'follow_up_at' => $closing ? null : now()->addDay()->format('Y-m-d H:i'),
            ]))
            ->assertSessionHasNoErrors();
    }
}
