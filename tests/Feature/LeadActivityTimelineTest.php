<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\MessageLog;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadActivityRecorder;
use App\Services\LeadFollowUpService;
use App\Services\LeadTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The lead view modal's activity timeline.
 *
 * The one failure worth the most tests here is counting: one action has to be
 * one entry. A stage change already writes a completed to-do, and the timeline
 * writes its own row for it too, so the easy bug is showing it twice — and the
 * other easy bug is a lead created before any of this showing nothing at all.
 *
 * @see LeadTimeline
 * @see LeadActivityRecorder
 */
class LeadActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tele;

    private User $sales;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-12 11:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann');
        $this->tele = $this->user('telecaller', 'Tara');
        $this->sales = $this->user('salesperson', 'Sam');

        $this->project = Project::create(['name' => 'Alpha']);
        // Sam handles Alpha, so a site visit here hands the lead to him
        $this->project->salespeople()->attach($this->sales);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ---------------- the modal's payload ---------------- */

    /**
     * The timeline arrives beside `lead`, and `lead` carries every key the
     * modal reads from it, in the same place, as it did before.
     */
    public function test_the_lead_payload_the_modal_reads_is_unchanged(): void
    {
        $lead = $this->createLead();

        $this->logCall($lead, $this->tele, ['stage' => 'details_shared', 'remarks' => 'Sent.']);

        $response = $this->actingAs($this->admin)->getJson("/leads/{$lead->id}")->assertOk();

        $this->assertSame(['lead', 'timeline', 'reassignCandidates'], array_keys($response->json()));

        $response->assertJsonStructure([
            'lead' => [
                'id', 'full_name', 'mobile_number', 'email', 'source', 'broker_name',
                'channel_partner', 'stage', 'days_in_stage', 'reason', 'booked_unit',
                'project' => ['id', 'name', 'location'],
                'owner' => ['id', 'display_name'],
                'pending_todo' => ['id', 'scheduled_at'],
                'completed_todos' => [['id', 'outcome_stage', 'remarks', 'completed_at', 'completer' => ['display_name']]],
            ],
        ]);

        $this->assertSame('Meera Sharma', $response->json('lead.full_name'));
        $this->assertSame('Alpha', $response->json('lead.project.name'));
        $this->assertSame('Tara User', $response->json('lead.completed_todos.0.completer.display_name'));
    }

    /* ---------------- one action, one entry ---------------- */

    public function test_creating_a_lead_is_one_entry_with_what_it_arrived_with(): void
    {
        $lead = $this->createLead();

        $timeline = $this->timeline($lead);

        $this->assertCount(1, $timeline);
        $this->assertSame('created', $timeline[0]['kind']);
        $this->assertSame('fresh', $timeline[0]['to_stage']);
        $this->assertSame('Ann User', $timeline[0]['actor']);
        $this->assertFalse($timeline[0]['system']);
        $this->assertSame([
            'Source' => 'Walk-in',
            'Project' => 'Alpha',
            'Assigned to' => 'Tara User',
            'Next follow-up' => 'Call · 13 Sep 2026, 11:00 AM',
        ], $this->details($timeline[0]));
    }

    /**
     * Added past `fresh`, the lead also gets a completed to-do the dashboard
     * counts as a transition. That row must not become a second entry.
     */
    public function test_creating_a_lead_past_fresh_is_still_one_entry(): void
    {
        $lead = $this->createLead(['stage' => 'details_shared']);

        $this->assertSame(1, Todo::where('lead_id', $lead->id)->whereNotNull('outcome_stage')->count(),
            'the dashboard history row is still written');

        $timeline = $this->timeline($lead);

        $this->assertCount(1, $timeline);
        $this->assertSame('details_shared', $timeline[0]['to_stage']);
    }

    public function test_editing_a_field_is_one_entry_with_the_old_and_new_value(): void
    {
        $lead = $this->createLead();

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, ['mobile_number' => '9123456780']))
            ->assertSessionHasNoErrors();

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('edit', $timeline[1]['kind']);
        $this->assertSame(['field' => 'Mobile', 'from' => '9876543210', 'to' => '9123456780'], $timeline[1]['change']);
        $this->assertSame('Ann User', $timeline[1]['actor']);
    }

    public function test_saving_the_form_unchanged_adds_nothing(): void
    {
        $lead = $this->createLead();

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead))
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $this->timeline($lead));
    }

    public function test_changing_the_stage_from_the_form_is_one_entry(): void
    {
        $lead = $this->createLead();

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'in_discussion',
                'follow_up_type' => 'meeting',
                'follow_up_at' => '2026-09-15 16:00',
            ]))
            ->assertSessionHasNoErrors();

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('stage', $timeline[1]['kind']);
        $this->assertSame('fresh', $timeline[1]['from_stage']);
        $this->assertSame('in_discussion', $timeline[1]['to_stage']);
        $this->assertSame('Stage changed from the lead form.', $timeline[1]['remark']);
        $this->assertSame('Meeting · 15 Sep 2026, 4:00 PM', $this->details($timeline[1])['Next follow-up']);

        // what the dashboard reads is exactly what it was
        $this->assertSame(1, Todo::where('lead_id', $lead->id)->where('outcome_stage', 'in_discussion')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_logging_a_call_is_one_entry(): void
    {
        $lead = $this->createLead();

        $this->logCall($lead, $this->tele, [
            'stage' => 'details_shared',
            'remarks' => 'Sent the brochure.',
            'follow_up_remarks' => 'Ask about the loan.',
        ]);

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('follow_up_completed', $timeline[1]['kind']);
        $this->assertSame('Call completed', $timeline[1]['title']);
        $this->assertSame('fresh', $timeline[1]['from_stage']);
        $this->assertSame('details_shared', $timeline[1]['to_stage']);
        $this->assertSame('Sent the brochure.', $timeline[1]['remark']);
        $this->assertSame('Tara User', $timeline[1]['actor']);
        $this->assertSame('Call · 13 Sep 2026, 11:00 AM', $this->details($timeline[1])['Next follow-up']);
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_call_that_leaves_the_stage_alone_shows_no_from_stage(): void
    {
        $lead = $this->createLead();

        $this->logCall($lead, $this->tele, ['stage' => 'fresh', 'remarks' => 'No answer.']);

        $entry = $this->timeline($lead)[1];

        $this->assertNull($entry['from_stage']);
        $this->assertSame('fresh', $entry['to_stage']);
    }

    /**
     * The handover is part of the call that caused it: one entry, saying who
     * the lead went from and to, and not a second "Reassigned" line.
     */
    public function test_a_handover_is_one_entry(): void
    {
        $lead = $this->createLead();

        $this->logCall($lead, $this->tele, [
            'stage' => 'site_visit_scheduled',
            'remarks' => 'Coming Sunday.',
            'follow_up_type' => 'site_visit',
        ]);

        $this->assertSame($this->sales->id, $lead->fresh()->assigned_to, 'the handover itself still happens');

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('site_visit_scheduled', $timeline[1]['to_stage']);
        $this->assertSame('Tara User → Sam User', $this->details($timeline[1])['Handed over']);
        $this->assertSame(1, LeadActivity::where('action', LeadActivity::HandedOver)->count());
        $this->assertSame(0, LeadActivity::where('action', LeadActivity::Reassigned)->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /** The unit typed on the same save is part of the booking, not a separate edit. */
    public function test_booking_from_the_form_is_one_entry_with_its_unit(): void
    {
        $lead = $this->createLead();

        $this->actingAs($this->admin)
            ->put("/leads/{$lead->id}", $this->editPayload($lead, [
                'stage' => 'booking_done',
                'booked_unit' => 'A-402',
            ]))
            ->assertSessionHasNoErrors();

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('booking', $timeline[1]['kind']);
        $this->assertSame('A-402', $this->details($timeline[1])['Booked unit']);
    }

    public function test_losing_a_lead_on_a_call_shows_the_reason(): void
    {
        $lead = $this->createLead();

        $this->logCall($lead, $this->tele, [
            'stage' => 'lost', 'remarks' => 'Bought elsewhere.', 'reason' => 'competitor',
            'follow_up_at' => null, 'follow_up_type' => null,
        ]);

        $entry = $this->timeline($lead)[1];

        $this->assertSame('lost', $entry['kind']);
        $this->assertSame('Chose competitor', $this->details($entry)['Reason for loss']);
    }

    /* ---------------- leads from before this table ---------------- */

    public function test_an_old_lead_shows_a_synthesised_creation_entry_and_nothing_invented(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00', 'Asia/Kolkata'));

        $lead = Lead::create([
            'first_name' => 'Old', 'last_name' => 'Lead', 'mobile_number' => '9000000001',
            'project_id' => $this->project->id, 'source' => 'walk_in', 'stage' => 'details_shared',
            'assigned_to' => $this->tele->id, 'assigned_role' => 'telecaller',
            'created_by' => $this->admin->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-03 10:00', 'Asia/Kolkata'));
        $this->historyRow($lead, 'details_shared', 'Sent details.');
        $pending = Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->tele->id, 'created_by' => $this->admin->id,
            'scheduled_at' => now()->addDays(3), 'type' => 'call', 'status' => 'pending',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-12 11:00', 'Asia/Kolkata'));

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline, 'creation and the one follow-up that was recorded — nothing else');
        $this->assertSame('created', $timeline[0]['kind']);
        $this->assertSame('Ann User', $timeline[0]['actor']);
        $this->assertSame('01 Sep 2026, 10:00 AM', $timeline[0]['at_exact']);
        $this->assertSame([], $timeline[0]['details'], 'no source, project or stage claimed for it');
        $this->assertSame('details_shared', $timeline[1]['to_stage']);

        // a call logged on it today: one new entry, and the old history row
        // and the synthesised creation each still appear exactly once
        $this->actingAs($this->tele)->post("/todos/{$pending->id}/complete", [
            'stage' => 'in_discussion', 'remarks' => 'Keen.',
            'follow_up_type' => 'call', 'follow_up_at' => '2026-09-13 11:00',
        ])->assertSessionHasNoErrors();

        $timeline = $this->timeline($lead);

        $this->assertSame(['created', 'history', 'follow_up_completed'], array_column($timeline, 'kind'));
        $this->assertSame('details_shared', $timeline[2]['from_stage']);
    }

    /* ---------------- automation ---------------- */

    public function test_automation_is_shown_as_automation_and_never_as_the_rules_author(): void
    {
        $author = $this->user('admin', 'Ravi');

        AutomationRule::create([
            'name' => 'Warm up new leads',
            'trigger' => 'lead_created',
            'conditions' => [],
            'actions' => [
                ['type' => 'change_stage', 'stage' => 'connected'],
                ['type' => 'raise_alert', 'recipient' => 'admins', 'severity' => 'info', 'title' => 'New lead'],
            ],
            'is_active' => true,
            'created_by' => $author->id,
        ]);

        $lead = $this->createLead();

        $timeline = $this->timeline($lead);

        $this->assertSame(['created', 'stage', 'automation'], array_column($timeline, 'kind'));
        $this->assertSame('Ann User', $timeline[0]['actor'], 'a person created it');

        foreach ([$timeline[1], $timeline[2]] as $entry) {
            $this->assertSame('Automation', $entry['actor']);
            $this->assertTrue($entry['system']);
        }

        $this->assertSame('connected', $timeline[1]['to_stage']);
        $this->assertSame('Warm up new leads', $this->details($timeline[2])['Rule']);
        $this->assertNotContains('Ravi User', array_column($timeline, 'actor'));
    }

    public function test_a_reassignment_by_automation_is_one_entry_from_whom_to_whom(): void
    {
        $lead = $this->createLead();
        $service = app(LeadFollowUpService::class);

        $this->actingAs($this->admin);
        $service->asSystem(fn () => $service->assign($lead->fresh(), $this->sales));

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('assignment', $timeline[1]['kind']);
        $this->assertSame(['field' => 'Owner', 'from' => 'Tara User', 'to' => 'Sam User'], $timeline[1]['change']);
        $this->assertSame('Automation', $timeline[1]['actor'], 'not the admin who happened to be signed in');
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    public function test_a_follow_up_booked_by_automation_is_one_entry(): void
    {
        $lead = $this->createLead();
        $service = app(LeadFollowUpService::class);

        $service->asSystem(fn () => $service->scheduleFollowUp($lead->fresh(), now()->addHours(4), 'whatsapp', 'Nudge'));

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('follow_up_scheduled', $timeline[1]['kind']);
        $this->assertSame('WhatsApp scheduled', $timeline[1]['title']);
        $this->assertSame('12 Sep 2026, 3:00 PM', $this->details($timeline[1])['Due']);
        $this->assertSame('Nudge', $timeline[1]['remark']);
        $this->assertTrue($timeline[1]['system']);
    }

    /* ---------------- messages ---------------- */

    public function test_opened_whatsapp_messages_appear_and_queued_ones_do_not(): void
    {
        $lead = $this->createLead();

        $this->message($lead, 'opened', $this->tele->id, 'Hello from Alpha.');
        $this->message($lead, 'queued', null, 'Not yet.');

        $timeline = $this->timeline($lead);

        $this->assertCount(2, $timeline);
        $this->assertSame('whatsapp', $timeline[1]['kind']);
        $this->assertSame('Tara User', $timeline[1]['actor']);
        $this->assertSame('Hello from Alpha.', $timeline[1]['remark']);
    }

    /* ---------------- atomicity ---------------- */

    public function test_a_lead_that_fails_to_save_leaves_no_activity(): void
    {
        Todo::creating(fn () => throw new RuntimeException('to-do insert failed'));

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->admin)
                ->post('/leads', $this->payload());
            $this->fail('the save should have thrown');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, LeadActivity::count());
    }

    public function test_an_edit_whose_activity_fails_to_save_is_rolled_back(): void
    {
        $lead = $this->createLead();

        LeadActivity::creating(fn () => throw new RuntimeException('activity insert failed'));

        try {
            $this->withoutExceptionHandling()
                ->actingAs($this->admin)
                ->put("/leads/{$lead->id}", $this->editPayload($lead, ['first_name' => 'Meerah']));
            $this->fail('the save should have thrown');
        } catch (RuntimeException) {
        }

        $this->assertSame('Meera', $lead->fresh()->first_name);
    }

    /* ---------------- access and cost ---------------- */

    public function test_the_timeline_is_only_served_with_a_lead_the_user_may_see(): void
    {
        $lead = $this->createLead();
        $other = $this->user('telecaller', 'Tom');

        $this->actingAs($other)->getJson("/leads/{$lead->id}")->assertForbidden();
        $this->actingAs($this->tele)->getJson("/leads/{$lead->id}")->assertOk()->assertJsonCount(1, 'timeline');
    }

    /**
     * The query count is fixed by the number of sources, not the number of
     * entries: a lead with thirty-odd entries costs what a lead with two does.
     */
    public function test_the_timeline_query_count_does_not_grow_with_its_length(): void
    {
        $small = $this->createLead();
        $big = $this->createLead(['mobile_number' => '9876500000', 'source' => 'referral']);

        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($this->admin)
                ->put("/leads/{$big->id}", $this->editPayload($big->fresh(), ['requirement' => "{$i} BHK"]))
                ->assertSessionHasNoErrors();
        }

        // as the admin: these stages bounce the lead between desks, and only
        // volume for the query-count assertion below is the point here
        foreach (['connected', 'details_shared', 'in_discussion', 'connected', 'details_shared', 'in_discussion'] as $stage) {
            $this->logCall($big, $this->admin, ['stage' => $stage]);
        }

        $this->actingAs($this->admin)
            ->put("/leads/{$big->id}", $this->editPayload($big->fresh(), [
                'project_id' => Project::create(['name' => 'Beta'])->id,
                'mobile_number' => '9876500001',
            ]))
            ->assertSessionHasNoErrors();

        $rule = AutomationRule::create([
            'name' => 'Alert on idle', 'trigger' => 'lead_created', 'conditions' => [], 'actions' => [],
            'is_active' => false, 'created_by' => $this->admin->id,
        ]);
        $alert = fn (Lead $lead) => AutomationLog::create([
            'rule_id' => $rule->id, 'lead_id' => $lead->id,
            'action' => 'raise_alert', 'result' => 'success', 'fired_at' => now(),
        ]);

        $alert($small);

        for ($i = 0; $i < 5; $i++) {
            $this->message($big, 'opened', $this->tele->id, "Message {$i}");
            $alert($big);
        }

        $timeline = app(LeadTimeline::class);
        $small = $small->fresh();
        $big = $big->fresh();

        [$smallEntries, $smallQueries] = $this->countQueries(fn () => $timeline->for($small));
        [$bigEntries, $bigQueries] = $this->countQueries(fn () => $timeline->for($big));

        $this->assertCount(2, $smallEntries);
        $this->assertGreaterThanOrEqual(30, count($bigEntries));
        $this->assertSame($smallQueries, $bigQueries, 'the same queries, however many entries');
        // activities, old to-dos, automation logs and their rules, messages,
        // then the names: users and projects
        $this->assertSame(7, $bigQueries);
    }

    /* ---------------- fixtures ---------------- */

    private function createLead(array $overrides = []): Lead
    {
        $this->actingAs($this->admin)
            ->post('/leads', $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return Lead::latest('id')->firstOrFail();
    }

    private function logCall(Lead $lead, User $as, array $data): void
    {
        $todo = Todo::where('lead_id', $lead->id)->where('status', 'pending')->firstOrFail();

        $this->actingAs($as)
            ->post("/todos/{$todo->id}/complete", $data + [
                'remarks' => 'Spoke.',
                'follow_up_type' => 'call',
                'follow_up_at' => '2026-09-13 11:00',
            ])
            ->assertSessionHasNoErrors();
    }

    /** @return list<array<string, mixed>> */
    private function timeline(Lead $lead): array
    {
        return $this->actingAs($this->admin)->getJson("/leads/{$lead->id}")->assertOk()->json('timeline');
    }

    /** @return array<string, ?string> label => value */
    private function details(array $entry): array
    {
        return array_column($entry['details'], 'value', 'label');
    }

    private function historyRow(Lead $lead, string $stage, string $remarks): void
    {
        Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $lead->assigned_to, 'created_by' => $this->tele->id,
            'scheduled_at' => now(), 'type' => 'call', 'status' => 'completed', 'remarks' => $remarks,
            'outcome_stage' => $stage, 'completed_at' => now(), 'completed_by' => $this->tele->id,
        ]);
    }

    private function message(Lead $lead, string $status, ?int $userId, string $body): void
    {
        MessageLog::create([
            'lead_id' => $lead->id, 'user_id' => $userId, 'mode' => 'click', 'to_number' => '919876543210',
            'body' => $body, 'status' => $status, 'sent_at' => $status === 'queued' ? null : now(),
        ]);
    }

    /** @return array{0: mixed, 1: int} */
    private function countQueries(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $count];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => '9876543210',
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            'follow_up_type' => 'call',
            'follow_up_at' => '2026-09-13 11:00',
        ], $overrides);
    }

    /** The lead form resubmits everything it is showing, so an edit does too. */
    private function editPayload(Lead $lead, array $overrides = []): array
    {
        return array_merge([
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'mobile_number' => $lead->mobile_number,
            'project_id' => $lead->project_id,
            'source' => $lead->source,
            'stage' => $lead->stage,
            'requirement' => $lead->requirement,
        ], $overrides);
    }

    private function user(string $role, string $first): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => 'User',
            'email' => strtolower($first).'@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}
