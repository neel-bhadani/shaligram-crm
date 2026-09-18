<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The range boundaries, and the two things that used to fall through them.
 *
 * Every range runs startOfDay() to endOfDay(). Today used to stop at now(),
 * which is a different boundary from the one Last 7 days and Last 30 days use,
 * and that difference is visible on the page: an event stamped later today
 * dropped out of Today while still counting in the longer ranges.
 *
 * @see DashboardController::preset()
 */
class DashboardRangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin');
        $this->project = Project::create(['name' => 'Alpha']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
    }

    /* ---------------- the boundary ---------------- */

    /**
     * The shape of the reported bug, in miniature: one booking, two ranges,
     * two answers. With Today ending at now() the 20:00 booking was outside
     * Today and inside Last 7 days — the same event counted by one range and
     * not the other.
     */
    public function test_an_event_stamped_later_today_counts_in_today_not_only_in_the_longer_ranges(): void
    {
        $lead = $this->lead(now()->subDays(40));

        Carbon::setTestNow(Carbon::parse('2026-09-02 20:00'));
        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);

        // ...and the page is looked at earlier in the day
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));

        $this->assertSame(1, $this->card('today', 'booked'), 'Today must reach 23:59:59');
        $this->assertSame(1, $this->card('7', 'booked'));
        $this->assertSame(1, $this->card('30', 'booked'));
    }

    /** 00:00 is inside Today. A range starting at now() would lose the morning. */
    public function test_a_lead_created_at_seven_in_the_morning_counts_in_todays_leads(): void
    {
        $this->lead(Carbon::parse('2026-09-02 07:00'));

        $this->assertSame(1, $this->card('today', 'today'));
        $this->assertSame(1, $this->card('today', 'total'));
        $this->assertSame(1, $this->card('7', 'total'));
    }

    /**
     * Last 7 days counts today as one of the seven and still reaches the
     * midnight at the far end. A raw subDays(7) would keep the current time of
     * day and silently drop that earliest morning.
     */
    public function test_the_seven_day_range_covers_seven_whole_days_ending_today(): void
    {
        $this->lead(Carbon::parse('2026-08-27 00:30'));   // first minute of day one
        $this->lead(Carbon::parse('2026-09-02 23:30'));   // last minute of today
        $this->lead(Carbon::parse('2026-08-26 23:30'));   // one minute too early

        $this->assertSame(2, $this->card('7', 'total'));
        $this->assertSame(3, $this->card('30', 'total'));
    }

    public function test_the_thirty_day_range_covers_thirty_whole_days_ending_today(): void
    {
        $this->lead(Carbon::parse('2026-08-04 00:30'));   // day one of thirty
        $this->lead(Carbon::parse('2026-08-03 23:30'));   // one minute too early

        $this->assertSame(1, $this->card('30', 'total'));
    }

    /** A custom range runs 00:00 on the From date to 23:59:59 on the To date. */
    public function test_a_custom_range_includes_both_end_days_in_full(): void
    {
        $this->lead(Carbon::parse('2026-08-10 00:10'));
        $this->lead(Carbon::parse('2026-08-12 23:50'));
        $this->lead(Carbon::parse('2026-08-09 23:50'));
        $this->lead(Carbon::parse('2026-08-13 00:10'));

        $this->assertSame(2, $this->cards('from=2026-08-10&to=2026-08-12')['total']);
    }

    /* ---------------- the history gap on creation ---------------- */

    /**
     * A lead typed in at "Site visit done" has had a site visit. It used to
     * write no history row — only the terminal stages did — so it appeared in
     * the Leads-by-stage chart under Site visit done and was never counted by
     * the Site visits done card, in any range. A card and a chart disagreeing
     * about the same lead.
     */
    public function test_a_lead_created_at_site_visit_done_counts_in_the_site_visits_card(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'site_visit_done']));

        $this->assertSame(1, Todo::where('outcome_stage', 'site_visit_done')
            ->where('status', 'completed')->count());
        $this->assertSame(1, $this->card('today', 'visits'));
        $this->assertSame(1, $this->card('30', 'visits'));
    }

    /** Still exactly one row, and still exactly one pending to-do behind it. */
    public function test_a_backfilled_creation_writes_one_history_row_and_one_pending_todo(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'connected']));

        $this->assertSame(1, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
        $this->assertSame(0, Lead::open()->doesntHave('pendingTodo')->count());
    }

    /** `fresh` is arrival, not a transition. It must still write nothing. */
    public function test_a_fresh_lead_still_writes_no_history(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'fresh']));

        $this->assertSame(0, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
    }

    /* ---------------- one action, one history row ---------------- */

    /**
     * Scheduling a site visit hands a telecaller's lead to a salesperson, and
     * handover() saves the lead a second time after applyStage() already has.
     * Both have to be holding the same instance: a second, staler copy would
     * write the old stage back over the new one and the lead would silently
     * revert while its history said otherwise.
     */
    public function test_a_handover_does_not_revert_the_stage_it_was_triggered_by(): void
    {
        $telecaller = $this->user('telecaller');
        $salesperson = $this->user('salesperson');

        $lead = $this->lead(now()->subDays(2));
        $lead->update([
            'stage' => 'fresh',
            'assigned_to' => $telecaller->id,
            'assigned_role' => 'telecaller',
        ]);

        $outcome = $this->service()->changeStage($lead->fresh(), 'site_visit_scheduled');

        $lead->refresh();

        $this->assertNotNull($outcome['handed_over_to'], 'the handover should have fired');
        $this->assertSame($salesperson->id, $lead->assigned_to);
        $this->assertSame('site_visit_scheduled', $lead->stage, 'the stage must survive the handover save');

        // and the history agrees with it, exactly once
        $this->assertSame(1, Todo::where('lead_id', $lead->id)
            ->where('outcome_stage', 'site_visit_scheduled')->count());
    }

    /** Logging a call writes the outcome onto the to-do it closed. One row. */
    public function test_logging_a_call_writes_exactly_one_history_row(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'fresh']));

        $lead = Lead::firstOrFail();
        $todo = $lead->pendingTodo;

        $this->actingAs($this->admin)
            ->post("/todos/{$todo->id}/complete", [
                'stage' => 'connected', 'remarks' => 'Spoke.',
                // the call books the next one; nothing schedules it any more
                'follow_up_type' => 'call',
                'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame('connected', Todo::whereNotNull('outcome_stage')->value('outcome_stage'));
        $this->assertSame('connected', $lead->fresh()->stage);
        // and the closed to-do is the history row, not a second one beside it
        $this->assertSame($todo->id, Todo::whereNotNull('outcome_stage')->value('id'));
    }

    /** A stage change from the lead form has no to-do to close, so it writes one. */
    public function test_a_lead_form_stage_change_writes_exactly_one_history_row(): void
    {
        $this->actingAs($this->admin)->post('/leads', $this->payload(['stage' => 'fresh']));

        $lead = Lead::firstOrFail();

        $this->actingAs($this->admin)->put("/leads/{$lead->id}", $this->payload([
            'mobile_number' => $lead->mobile_number,
            'stage' => 'details_shared',
        ]));

        $this->assertSame('details_shared', $lead->fresh()->stage);
        $this->assertSame(1, Todo::whereNotNull('outcome_stage')->count());
        $this->assertSame(1, Todo::where('status', 'pending')->count());
    }

    /* ---------------- the two stage charts ---------------- */

    /**
     * The pair, in one test.
     *
     * Chart 1 is every enquiry ever received, at the stage it stands at now.
     * Chart 2 is the same census narrowed to the leads created in the range.
     * One query with an optional window, so the only thing that can differ
     * between them is the window — which is exactly what this asserts.
     */
    public function test_the_two_charts_differ_only_by_the_date_window(): void
    {
        $this->stageCensusFixture();

        $today = ['fresh' => 1, 'not_connected' => 1, 'site_visit_done' => 1];

        foreach (['range=today', 'range=7', 'range=30'] as $query) {
            $this->assertSame($today, $this->nonZero($query, 'stagesAllTime'), $query);
            $this->assertSame($today, $this->nonZero($query, 'stagesInPeriod'), $query);
        }

        // yesterday only: nothing was created then, so chart 2 empties...
        $yesterday = 'from=2026-09-01&to=2026-09-01';

        $this->assertSame([], $this->nonZero($yesterday, 'stagesInPeriod'));
        $this->assertSame(0, $this->charts($yesterday)['stagesInPeriod']['total']);

        // ...while chart 1 has not moved at all
        $this->assertSame($today, $this->nonZero($yesterday, 'stagesAllTime'));
    }

    /**
     * The date buttons must have no effect on chart 1 whatsoever — not on a
     * bar, not on the total, not on the order. Asserted on the whole payload
     * rather than on a total, because a total can stay put while the bars
     * underneath it move.
     */
    public function test_chart_one_is_byte_identical_in_every_range(): void
    {
        $this->stageCensusFixture();
        $this->lead(now()->subDays(200))->forceFill(['stage' => 'lost'])->save();
        $this->lead(now()->subDays(40))->forceFill(['stage' => 'booking_done'])->save();

        $queries = ['range=today', 'range=7', 'range=30',
            'from=2026-09-01&to=2026-09-01', 'from=2026-01-01&to=2026-09-02'];

        $seen = [];

        foreach ($queries as $query) {
            $seen[$query] = $this->charts($query)['stagesAllTime'];
        }

        $this->assertCount(1, collect($seen)->unique(fn ($c) => json_encode($c)),
            'chart 1 moved with the range: '.json_encode($seen));

        // and it really is every lead, not merely a stable subset of them
        $this->assertSame(5, reset($seen)['total']);
        $this->assertSame(Lead::count(), reset($seen)['total']);
    }

    /** Chart 2 counts leads.created_at, so a lead is in the ranges it arrived in. */
    public function test_chart_two_follows_the_range_on_created_at(): void
    {
        $lead = $this->lead(now()->subDays(40));
        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);

        foreach (['today', '7', '30'] as $range) {
            $bars = $this->bars("range=$range", 'stagesInPeriod');

            // created outside all three ranges, so it is in none of them
            $this->assertSame(0, $bars['booking_done'], "range=$range");
            // ...while the event itself happened today, and the card counts it
            $this->assertSame(1, $this->card($range, 'booked'), "range=$range");
            // ...and it stands at booking_done, so chart 1 has it in every range
            $this->assertSame(1, $this->bars("range=$range", 'stagesAllTime')['booking_done']);
        }

        // and the range that does contain its creation date has it
        $this->assertSame(1, $this->bars('from=2026-07-24&to=2026-09-02', 'stagesInPeriod')['booking_done']);
    }

    /**
     * THE BUG. A lead created today at Fresh, on the Today range.
     *
     * It has never been called, so it has no completed to-do and therefore no
     * `todos.outcome_stage` row. Chart 2 used to count stage TRANSITIONS out of
     * that table, so this lead could not appear in it at any range — it showed
     * in chart 1 and vanished from chart 2, on the very range that should have
     * been most sure to hold it. Counting leads.stage instead is what fixes it,
     * and a lead with no to-dos at all is the case that proves the chart is not
     * reading `todos` any more.
     */
    public function test_a_fresh_lead_created_today_is_in_both_charts(): void
    {
        $this->lead(now())->forceFill(['stage' => 'fresh'])->save();

        $this->assertSame(0, Todo::whereNotNull('outcome_stage')->count(), 'no transition exists');

        $this->assertSame(1, $this->bars('range=today', 'stagesAllTime')['fresh'],
            'a Fresh lead created today is missing from chart 1');
        $this->assertSame(1, $this->bars('range=today', 'stagesInPeriod')['fresh'],
            'a Fresh lead created today is invisible in chart 2 — the reported bug');

        $this->assertSame(1, $this->charts('range=today')['stagesInPeriod']['total']);
        $this->assertSame(1, $this->card('today', 'total'));
    }

    /**
     * Chart 2's bars are the New enquiries card: same table, same column, one
     * grouped and one counted.
     */
    public function test_chart_two_bars_sum_to_the_new_enquiries_card(): void
    {
        $this->stageCensusFixture();
        $this->lead(now()->subDays(10));

        foreach (['today' => 3, '7' => 3, '30' => 4] as $range => $expected) {
            $chart = $this->charts("range=$range")['stagesInPeriod'];

            $this->assertSame($expected, $chart['total'], "range=$range");
            $this->assertSame($expected, array_sum(array_column($chart['bars'], 'value')), "range=$range");
            $this->assertSame($this->card($range, 'total'), $chart['total'], "range=$range");
        }
    }

    /**
     * Chart 1's bars are every visible lead, so its header total is Lead::count()
     * and no range can shrink it.
     */
    public function test_chart_one_accounts_for_every_lead(): void
    {
        $this->lead(now()->subDays(200));
        $this->lead(now()->subDays(40));
        $this->lead(now());

        foreach (['range=today', 'range=7', 'range=30', 'from=2026-09-01&to=2026-09-01'] as $query) {
            $chart = $this->charts($query)['stagesAllTime'];

            $this->assertSame(3, $chart['total'], $query);
            $this->assertSame(3, array_sum(array_column($chart['bars'], 'value')), $query);
            $this->assertSame(Lead::count(), $chart['total'], $query);
        }
    }

    /**
     * Chart 2 groups by where the lead stands now, not by what happened in the
     * range. A lead that moved twice today is still one lead at one stage, and
     * a transition into the range on a lead created before it stays out.
     */
    public function test_chart_two_counts_leads_not_transitions(): void
    {
        // created inside the range, moved twice inside it: one bar, one lead
        $inside = $this->lead(now());
        $this->service()->changeStage($inside, 'booking_done', ['booked_unit' => 'A-1']);
        $this->service()->changeStage($inside->fresh(), 'in_discussion');
        $this->service()->changeStage($inside->fresh(), 'booking_done', ['booked_unit' => 'A-1']);

        // created before the range, moved inside it: not an enquiry from today
        $before = $this->lead(now()->subDays(40));
        $this->service()->changeStage($before, 'site_visit_done');

        $this->assertSame(4, Todo::whereNotNull('outcome_stage')->count(), 'four transitions');

        $bars = $this->bars('range=today', 'stagesInPeriod');

        $this->assertSame(1, $bars['booking_done'], 'two transitions, one lead');
        $this->assertSame(0, $bars['in_discussion'], 'it does not stand there any more');
        $this->assertSame(0, $bars['site_visit_done'], 'created before the range');
        $this->assertSame(1, $this->charts('range=today')['stagesInPeriod']['total']);
    }

    /** A soft-deleted lead leaves both charts. */
    public function test_a_deleted_lead_leaves_both_stage_charts(): void
    {
        $lead = $this->lead(now());
        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);

        $this->assertSame(1, $this->charts('range=today')['stagesAllTime']['total']);
        $this->assertSame(1, $this->charts('range=today')['stagesInPeriod']['total']);

        $this->actingAs($this->admin)->delete("/leads/{$lead->id}");

        $charts = $this->charts('range=today');

        $this->assertSame(0, $charts['stagesAllTime']['total']);
        $this->assertSame(0, $charts['stagesInPeriod']['total']);
        $this->assertSame(0, $this->card('today', 'booked'));
    }

    /** Both charts render all nine stages, in config order, zeros included. */
    public function test_both_stage_charts_are_zero_filled_across_every_stage(): void
    {
        $charts = $this->charts('from=2026-07-01&to=2026-07-05');

        foreach (['stagesAllTime', 'stagesInPeriod'] as $chart) {
            $this->assertCount(9, $charts[$chart]['bars'], $chart);
            $this->assertSame(array_keys(config('crm.stages')),
                array_column($charts[$chart]['bars'], 'key'), $chart);
            $this->assertSame(0, array_sum(array_column($charts[$chart]['bars'], 'value')), $chart);
            $this->assertSame(0, $charts[$chart]['total'], $chart);
        }
    }

    /** An empty range still draws a full set of bars and a dash, not a gap. */
    public function test_an_empty_range_is_zero_filled_and_shows_a_dash(): void
    {
        $cards = $this->cards('from=2026-07-01&to=2026-07-05');

        $this->assertSame(0, $cards['total']);
        $this->assertNull($cards['conversion']);
    }

    /* ---------------- the event cards ---------------- */

    /**
     * The three event cards read todos.completed_at, not leads.created_at. No
     * chart draws these any more, so this is where that column is pinned down:
     * a lead created 40 days ago that books today is a booking that happened
     * today, in every range that contains today.
     */
    public function test_the_event_cards_count_when_the_event_happened(): void
    {
        $visited = $this->lead(now()->subDays(40));
        $this->service()->changeStage($visited, 'site_visit_done');
        $this->service()->changeStage($visited->fresh(), 'booking_done', ['booked_unit' => 'A-1']);

        $this->service()->changeStage($this->lead(now()->subDays(3)), 'lost', ['reason' => 'budget']);

        Carbon::setTestNow(Carbon::parse('2026-08-05 12:00'));
        $this->service()->changeStage($this->lead(now()->subDays(20)), 'booking_done', ['booked_unit' => 'A-2']);
        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));

        foreach (['today', '7'] as $range) {
            $this->assertSame(1, $this->card($range, 'booked'), "range=$range");
            $this->assertSame(1, $this->card($range, 'visits'), "range=$range");
            $this->assertSame(1, $this->card($range, 'lost'), "range=$range");
        }

        // Last 30 days reaches back to 4 August, so it holds both bookings
        $this->assertSame(2, $this->card('30', 'booked'));
        $this->assertSame(1, $this->card('30', 'visits'));
        $this->assertSame(1, $this->card('30', 'lost'));

        // the August booking is in its own window and alone in it
        $august = $this->cards('from=2026-08-01&to=2026-08-15');

        $this->assertSame(1, $august['booked']);
        $this->assertSame(0, $august['visits']);
        $this->assertSame(0, $august['lost']);
    }

    /** One lead reaching a stage twice in a range is still one lead on the card. */
    public function test_an_event_card_counts_a_lead_once_per_stage(): void
    {
        $lead = $this->lead(now()->subDays(40));

        $this->service()->changeStage($lead, 'booking_done', ['booked_unit' => 'A-1']);
        $this->service()->changeStage($lead->fresh(), 'in_discussion');
        $this->service()->changeStage($lead->fresh(), 'booking_done', ['booked_unit' => 'A-1']);

        $this->assertSame(2, Todo::where('outcome_stage', 'booking_done')->count(), 'two events');
        $this->assertSame(1, $this->card('today', 'booked'), 'but one lead');
    }

    /* ---------------- helpers ---------------- */

    private function service(): LeadFollowUpService
    {
        $this->actingAs($this->admin);

        return app(LeadFollowUpService::class);
    }

    private function props(string $only, string $query): array
    {
        return $this->actingAs($this->admin)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Data' => $only,
            'X-Inertia-Partial-Component' => 'Dashboard',
        ])->get("/dashboard?reset=1&$query")->json("props.$only");
    }

    private function cards(string $query): array
    {
        return $this->props('cards', $query);
    }

    private function charts(string $query): array
    {
        return $this->props('charts', $query);
    }

    private function card(string $range, string $key)
    {
        return $this->cards("range=$range")[$key];
    }

    /** One stage chart's bars as stage => value, all nine keys present. */
    private function bars(string $query, string $chart): array
    {
        return collect($this->charts($query)[$chart]['bars'])->pluck('value', 'key')->all();
    }

    /** The same, with the zeros dropped — what the chart actually draws. */
    private function nonZero(string $query, string $chart): array
    {
        return array_filter($this->bars($query, $chart));
    }

    /**
     * The three leads the two charts were reported against: all created today,
     * one at each of three stages, none of them ever called.
     */
    private function stageCensusFixture(): void
    {
        foreach (['fresh', 'not_connected', 'site_visit_done'] as $stage) {
            $this->lead(now())->forceFill(['stage' => $stage])->save();
        }
    }

    private function user(string $role): User
    {
        return User::create([
            'first_name' => ucfirst($role), 'last_name' => 'User',
            'email' => "$role@example.test",
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Meera',
            'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id,
            'source' => 'walk_in',
            'stage' => 'fresh',
            // follow-ups are booked by hand now, so the form carries one
            'follow_up_type' => 'call',
            'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ];
    }

    private function lead(Carbon $createdAt): Lead
    {
        $lead = Lead::create([
            'first_name' => 'Meera', 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->project->id, 'source' => 'walk_in',
            'stage' => 'in_discussion', 'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
