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
 * The Reporting section.
 *
 * Two things are worth a test here and the rest follows from them:
 *
 *   - the counting rules. History comes from todos.outcome_stage with
 *     completed_at in range, counted once per lead; intake comes from
 *     leads.created_at. Getting either from the other is the failure this whole
 *     feature is built to avoid, and it is invisible on screen — a wrong number
 *     looks exactly like a right one.
 *
 *   - the boundaries. A telecaller must see their own rows and no others,
 *     whatever they type into the address bar, and the row totals must equal
 *     the dashboard's cards over the same range or one of the two is lying.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $alice;
    private User $bob;
    private Project $alpha;
    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-06 12:00'));

        $this->admin = $this->user('admin', 'Admin');
        $this->alice = $this->user('telecaller', 'Alice');
        $this->bob   = $this->user('telecaller', 'Bob');

        $this->alpha = Project::create(['name' => 'Alpha']);
        $this->beta  = Project::create(['name' => 'Beta']);
    }

    /* ---------------- intake vs history ---------------- */

    public function test_a_lead_created_before_the_range_but_booked_inside_it_counts_as_a_booking_only(): void
    {
        $lead = $this->lead($this->alice, now()->subDays(90), ['source' => 'broker']);

        // the booking happened today, ninety days after the lead arrived
        $this->history($lead, 'booking_done', now());

        $rows = $this->leadRows('source', '30');

        $this->assertSame(0, $rows['broker']['total'], 'the lead arrived before the range');
        $this->assertSame(1, $rows['broker']['booked'], 'but the booking happened inside it');
        $this->assertNull(
            $rows['broker']['conversion'],
            'no leads in range is nothing to divide by, which is a dash and never 0%',
        );
    }

    /** Paid-for clicks that produced nothing callable, counted per source. */
    public function test_leads_with_no_phone_and_no_contact_details_are_counted_per_group(): void
    {
        $this->lead($this->alice, now()->subDays(2), ['source' => 'facebook']);
        $this->lead($this->alice, now()->subDays(2), ['source' => 'facebook', 'mobile_number' => null, 'email' => 'a@example.test']);
        $this->lead($this->alice, now()->subDays(2), ['source' => 'facebook', 'mobile_number' => null, 'email' => null]);
        $this->lead($this->alice, now()->subDays(90), ['source' => 'facebook', 'mobile_number' => null]);

        $props = $this->page('/reports/leads', ['group' => 'source', 'range' => '30'], $this->admin);
        $facebook = collect($props['rows'])->keyBy('key')['facebook'];

        $this->assertSame(3, $facebook['total']);
        $this->assertSame(2, $facebook['no_phone'], 'the lead before the range is not counted');
        $this->assertSame(1, $facebook['no_contact']);
        $this->assertSame(2, $props['totals']['no_phone']);
        $this->assertSame(1, $props['totals']['no_contact']);
    }

    public function test_history_counts_a_lead_once_however_many_rows_it_has(): void
    {
        $lead = $this->lead($this->alice, now()->subDays(2), ['source' => 'facebook']);

        // the same lead reaching the same stage twice inside the range
        $this->history($lead, 'site_visit_done', now()->subDay());
        $this->history($lead, 'site_visit_done', now());

        $this->assertSame(1, $this->leadRows('source', '30')['facebook']['visits']);
    }

    public function test_history_outside_the_range_is_not_counted(): void
    {
        $lead = $this->lead($this->alice, now()->subDays(2), ['source' => 'referral']);

        $this->history($lead, 'booking_done', now()->subDays(45));

        $rows = $this->leadRows('source', '30');

        $this->assertSame(1, $rows['referral']['total']);
        $this->assertSame(0, $rows['referral']['booked']);
        $this->assertSame(0.0, $rows['referral']['conversion'], 'a real zero, not a dash');
    }

    public function test_a_pending_row_is_not_history_however_its_stage_reads(): void
    {
        $lead = $this->lead($this->alice, now(), ['source' => 'walk_in']);

        // outcome_stage set but never completed — completed_at is null, so it
        // is a plan rather than a thing that happened
        Todo::create([
            'lead_id' => $lead->id, 'assigned_to' => $this->alice->id,
            'scheduled_at' => now()->addDay(), 'type' => 'call', 'status' => 'pending',
            'outcome_stage' => 'booking_done',
        ]);

        $this->assertSame(0, $this->leadRows('source', '30')['walk_in']['booked']);
    }

    public function test_a_soft_deleted_lead_leaves_the_report_with_its_history(): void
    {
        $lead = $this->lead($this->alice, now()->subDay(), ['source' => 'instagram']);
        $this->history($lead, 'booking_done', now());

        $this->assertSame(1, $this->leadRows('source', '30')['instagram']['booked']);

        $lead->delete();

        $rows = $this->leadRows('source', '30');

        $this->assertSame(0, $rows['instagram']['total']);
        $this->assertSame(0, $rows['instagram']['booked']);
    }

    /* ---------------- zero-fill and totals ---------------- */

    public function test_every_configured_group_gets_a_row_even_with_nothing_in_it(): void
    {
        $this->lead($this->alice, now(), ['source' => 'walk_in']);

        $rows = $this->leadRows('source', '30');

        $this->assertSame(array_keys(config('crm.sources')), array_keys($rows));
        $this->assertSame(0, $rows['hoarding']['total']);
    }

    public function test_leads_with_no_owner_get_an_unassigned_row_that_does_not_drill_through(): void
    {
        $this->lead(null, now(), ['source' => 'walk_in']);

        $rows = $this->leadRows('assigned_to', '30');

        $this->assertSame(1, $rows['__none__']['total']);
        $this->assertFalse(
            $rows['__none__']['drillable'],
            'the Leads page assigned-to filter takes a user id and there is none to give it',
        );
    }

    public function test_the_row_totals_equal_the_dashboard_cards_over_the_same_range(): void
    {
        $a = $this->lead($this->alice, now()->subDays(3), ['source' => 'broker']);
        $b = $this->lead($this->bob, now()->subDays(10), ['source' => 'facebook']);
        $c = $this->lead($this->alice, now()->subDays(70), ['source' => 'walk_in']);

        $this->history($a, 'site_visit_done', now()->subDay());
        $this->history($a, 'booking_done', now());
        $this->history($b, 'lost', now()->subDays(2));
        // an older lead whose visit lands inside the range
        $this->history($c, 'site_visit_done', now());

        $cards = $this->dashboardCards('30');

        // every dimension has to agree with the cards, not just the default one
        foreach (['source', 'stage', 'project', 'channel_partner', 'assigned_to'] as $dimension) {
            $rows = $this->leadRows($dimension, '30');

            foreach (['total', 'visits', 'booked', 'lost'] as $key) {
                $this->assertSame(
                    $cards[$key],
                    array_sum(array_column($rows, $key)),
                    "grouped by $dimension, the $key column must sum to the dashboard card",
                );
            }
        }
    }

    /* ---------------- follow-ups: the date column per status ---------------- */

    public function test_completed_filters_on_completed_at_and_the_pending_buckets_on_scheduled_at(): void
    {
        $lead = $this->lead($this->alice, now()->subDays(2));

        // scheduled long before the range, closed inside it: Completed counts
        // it, and a report filtering completed on scheduled_at would not
        $this->todo($lead, now()->subDays(60), 'completed', now()->subDay());

        // scheduled before today and still open: Waiting longer
        $this->todo($lead, now()->subDays(3), 'pending');

        // scheduled after today: Upcoming, which no range ending today contains
        $this->todo($lead, now()->addDays(5), 'pending');

        $this->assertSame(1, $this->followUpTotal('completed', '30'));
        $this->assertSame(1, $this->followUpTotal('overdue', '30'));
        $this->assertSame(1, $this->followUpTotal('upcoming', '30'), 'Upcoming is never emptied by the range');
        $this->assertSame(0, $this->followUpTotal('today', '30'));
    }

    public function test_a_completion_outside_the_range_is_not_counted(): void
    {
        $lead = $this->lead($this->alice, now()->subDays(2));

        $this->todo($lead, now()->subDays(80), 'completed', now()->subDays(70));

        $this->assertSame(0, $this->followUpTotal('completed', '30'));
        $this->assertSame(1, $this->followUpTotal('completed', '30', null, ['2026-06-01', '2026-09-06']));
    }

    public function test_average_days_to_close_measures_scheduled_to_completed(): void
    {
        $lead = $this->lead($this->alice, now()->subDays(5));

        // two days late, and one day early
        $this->todo($lead, now()->subDays(4), 'completed', now()->subDays(2));
        $this->todo($lead, now()->subDays(3), 'completed', now()->subDays(4));

        $rows = $this->followUpRows('completed', 'type', '30');

        $this->assertEqualsWithDelta(0.5, $rows['call']['avgDays'], 0.05,
            'the mean of two days late and one day early');
    }

    /* ---------------- role scoping ---------------- */

    public function test_a_telecaller_sees_only_their_own_leads_and_follow_ups(): void
    {
        $mine  = $this->lead($this->alice, now()->subDay(), ['source' => 'broker']);
        $theirs = $this->lead($this->bob, now()->subDay(), ['source' => 'broker']);

        $this->history($mine, 'booking_done', now());
        $this->history($theirs, 'booking_done', now());

        $this->todo($mine, now()->subDays(2), 'pending');
        $this->todo($theirs, now()->subDays(2), 'pending');

        $rows = $this->leadRows('source', '30', $this->alice);

        $this->assertSame(1, $rows['broker']['total'], 'their own lead only');
        $this->assertSame(1, $rows['broker']['booked'], 'and their own history only');

        $this->assertSame(2, $this->followUpTotal('overdue', '30'), 'the admin sees both');
        $this->assertSame(1, $this->followUpTotal('overdue', '30', $this->alice));
    }

    public function test_a_telecaller_cannot_group_by_assigned_to_even_by_typing_it(): void
    {
        $this->lead($this->alice, now(), ['source' => 'walk_in']);

        $props = $this->page('/reports/leads', ['group' => 'assigned_to'], $this->alice);

        $this->assertSame('source', $props['filters']['group'], 'dropped, and the default takes over');
        $this->assertArrayNotHasKey('assigned_to', $props['options']['dimensions']);

        $follow = $this->page('/reports/followups', ['group' => 'assigned_to'], $this->alice);

        $this->assertSame('type', $follow['filters']['group']);
        $this->assertArrayNotHasKey('assigned_to', $follow['options']['dimensions']);
    }

    public function test_an_admin_is_offered_the_assigned_to_grouping_on_both_reports(): void
    {
        $this->assertArrayHasKey(
            'assigned_to',
            $this->page('/reports/leads', [], $this->admin)['options']['dimensions'],
        );
        $this->assertArrayHasKey(
            'assigned_to',
            $this->page('/reports/followups', [], $this->admin)['options']['dimensions'],
        );
    }

    /* ---------------- the range the other pages do not have ---------------- */

    /* ---------------- the two axes ---------------- */

    /*
     | The grouping is a sidebar link and the status is a tab, and neither may
     | move the other. Every filter visit sends reset=1 — the request is the
     | whole instruction — so a control that changes one axis has to resend the
     | other two or it switches them off: clicking a tab would have quietly put
     | the report back on its default grouping and its default thirty days.
     */
    public function test_changing_the_status_keeps_the_grouping_and_the_window(): void
    {
        // what the tab row sends: the status it wants, plus everything else in force
        $after = $this->page('/reports/followups', [
            'group' => 'assigned_to', 'status' => 'upcoming', 'range' => '7',
        ], $this->admin);

        $this->assertSame('assigned_to', $after['filters']['group'], 'the tab must not move the sidebar');
        $this->assertSame('upcoming', $after['filters']['status']);
        $this->assertSame('7', $after['filters']['range'], 'nor the window');
    }

    public function test_changing_the_grouping_keeps_the_status_and_the_window(): void
    {
        // the sidebar link names the grouping alone and carries no reset, so the
        // rest is whatever the session already holds
        $this->page('/reports/followups', ['group' => 'type', 'status' => 'completed', 'range' => '7'], $this->admin);

        $response = $this->actingAs($this->admin)->get('/reports/followups?group=assigned_to');
        $response->assertOk();
        $after = $response->viewData('page')['props'];

        $this->assertSame('assigned_to', $after['filters']['group']);
        $this->assertSame('completed', $after['filters']['status'], 'the sidebar must not move the tabs');
        $this->assertSame('7', $after['filters']['range']);
    }

    public function test_the_follow_ups_report_opens_on_due_today(): void
    {
        $props = $this->page('/reports/followups', [], $this->admin);

        $this->assertSame('today', $props['filters']['status']);
        $this->assertSame('type', $props['filters']['group']);
    }

    /** Every status the tab row can offer is one the server accepts. */
    public function test_every_tab_resolves_to_its_own_status(): void
    {
        foreach (array_keys(config('crm.reports.follow_up_statuses')) as $status) {
            foreach (['type', 'assigned_to'] as $group) {
                $props = $this->page('/reports/followups', [
                    'group' => $group, 'status' => $status,
                ], $this->admin);

                $this->assertSame($status, $props['filters']['status'], "$group + $status");
                $this->assertSame($group, $props['filters']['group'], "$group + $status");
            }
        }
    }

    /* ---------------- the date control ---------------- */

    /**
     * Three presets plus Custom, in that order, and the same list the dashboard
     * draws. They are one config entry rendered by one component now, so the
     * thing worth pinning is that both report pages ship it and that nothing
     * else survives in it.
     */
    public function test_both_reports_offer_exactly_the_dashboard_presets_in_order(): void
    {
        $expected = [
            ['key' => 'today', 'label' => 'Today'],
            ['key' => '7',     'label' => 'Last 7 days'],
            ['key' => '30',    'label' => 'Last 30 days'],
        ];

        /*
         | A list, and asserted as one. Written as a key => label map, '7' and
         | '30' are integer-like keys and JavaScript iterates them ahead of
         | 'today' — the control drew "Last 7 days, Last 30 days, Today" and
         | nothing in PHP could see it. assertSame pins the order, not just the
         | contents.
         */
        $this->assertSame($expected, config('crm.date_ranges'));

        foreach (['/reports/leads', '/reports/followups', '/dashboard'] as $url) {
            $props = $this->page($url, [], $this->admin);

            $this->assertSame(
                $expected,
                $props['options']['ranges'],
                "$url must draw the same three presets in the same order",
            );
        }
    }

    public function test_this_month_is_gone_and_falls_back_to_the_default(): void
    {
        $props = $this->page('/reports/leads', ['group' => 'source', 'range' => 'month'], $this->admin);

        // dropped by validation, so the 30-day default takes over
        $this->assertSame('30', $props['filters']['range']);
        $this->assertSame('2026-08-08', $props['range']['from']);
        $this->assertSame('2026-09-06', $props['range']['to']);
    }

    /** The boundaries each preset resolves to, in IST. */
    public function test_each_preset_resolves_to_the_specified_boundaries(): void
    {
        foreach ([
            'today' => ['2026-09-06', '2026-09-06'],
            '7'     => ['2026-08-31', '2026-09-06'],
            '30'    => ['2026-08-08', '2026-09-06'],
        ] as $preset => [$from, $to]) {
            $range = $this->page('/reports/leads', ['range' => $preset], $this->admin)['range'];

            $this->assertSame($from, $range['from'], "$preset starts on $from");
            $this->assertSame($to, $range['to'], "$preset ends on $to");
        }

        $custom = $this->page('/reports/leads', [
            'from' => '2026-08-20', 'to' => '2026-08-25',
        ], $this->admin)['range'];

        $this->assertSame('2026-08-20', $custom['from']);
        $this->assertSame('2026-08-25', $custom['to']);
    }

    public function test_a_custom_range_that_runs_backwards_or_into_the_future_is_refused(): void
    {
        foreach ([
            'backwards' => ['from' => '2026-09-05', 'to' => '2026-09-01'],
            'future'    => ['from' => '2026-09-01', 'to' => '2026-12-01'],
        ] as $why => $pair) {
            $range = $this->page('/reports/leads', $pair, $this->admin)['range'];

            $this->assertSame('2026-08-08', $range['from'], "a $why pair is dropped for the default");
            $this->assertSame('2026-09-06', $range['to']);
        }
    }

    /* ---------------- the grouping is the menu's, and it sticks ---------------- */

    /*
     | There is no Group by control on either page any more — the sidebar link
     | is what chooses the grouping and the status, and the heading states them.
     | That makes two things load-bearing that a dropdown used to paper over:
     | the link's query string has to be honoured, and the choice has to survive
     | every later filter visit, which sends `reset=1` and would otherwise drop
     | the report back to its default grouping the moment somebody picked a
     | project.
     */
    public function test_the_menu_link_chooses_the_grouping_on_both_reports(): void
    {
        foreach (['stage', 'source', 'project', 'assigned_to'] as $group) {
            $props = $this->page('/reports/leads', ['group' => $group], $this->admin);

            $this->assertSame($group, $props['filters']['group']);
        }

        foreach (config('crm.reports.follow_up_statuses') as $status => $spec) {
            $props = $this->page('/reports/followups', ['status' => $status], $this->admin);

            $this->assertSame($status, $props['filters']['status']);
        }
    }

    public function test_a_date_visit_keeps_the_grouping_the_menu_chose(): void
    {
        $this->lead($this->alice, now(), ['source' => 'broker']);

        // arrive from the menu
        $this->page('/reports/leads', ['group' => 'project'], $this->admin);

        // then move the date range, which is the only control left on the page.
        // It sends every key it owns plus reset=1 — `group` among them, which is
        // the whole reason the grouping stays in the state now that nothing on
        // screen can set it.
        $after = $this->page('/reports/leads', ['group' => 'project', 'range' => '7'], $this->admin);

        $this->assertSame('project', $after['filters']['group'], 'the grouping must survive a range change');
        $this->assertSame('7', $after['filters']['range']);
    }

    public function test_a_follow_ups_date_visit_keeps_both_the_grouping_and_the_status(): void
    {
        $after = $this->page('/reports/followups', [
            'group' => 'assigned_to', 'status' => 'completed', 'range' => '7',
        ], $this->admin);

        $this->assertSame('assigned_to', $after['filters']['group']);
        $this->assertSame('completed', $after['filters']['status']);
        $this->assertSame('7', $after['filters']['range']);
    }

    /**
     * The three filters are gone from the pages, from the controller rules and
     * from the payload. A stale link or a typed query string must not quietly
     * narrow a report that no longer says it is narrowed.
     */
    public function test_the_removed_filters_are_neither_honoured_nor_shipped(): void
    {
        $mine  = $this->lead($this->alice, now()->subDay(), ['source' => 'broker']);
        $other = $this->lead($this->bob, now()->subDay(), [
            'source' => 'facebook', 'project_id' => $this->beta->id,
        ]);

        $this->history($mine, 'booking_done', now());
        $this->history($other, 'booking_done', now());

        $props = $this->page('/reports/leads', [
            'group'      => 'source',
            'project_id' => $this->alpha->id,
            'source'     => 'broker',
            'assigned_to' => $this->alice->id,
        ], $this->admin);

        $this->assertSame(2, $props['totals']['total'], 'both leads, none of the filters applied');
        $this->assertSame(2, $props['totals']['booked']);

        foreach (['project_id', 'source', 'assigned_to'] as $dead) {
            $this->assertArrayNotHasKey($dead, $props['filters'], "$dead must not survive into the filters");
        }

        foreach (['projects', 'sources', 'users', 'stages', 'types'] as $dead) {
            $this->assertArrayNotHasKey($dead, $props['options'], "$dead must not be shipped to a page with no control for it");
        }
    }

    /* ---------------- drill-through ---------------- */

    public function test_a_leads_drill_through_lands_on_the_same_population(): void
    {
        $this->lead($this->alice, now()->subDay(), ['source' => 'broker']);
        $this->lead($this->alice, now()->subDay(), ['source' => 'facebook']);
        $this->lead($this->alice, now()->subDays(60), ['source' => 'broker']);

        $report = $this->leadRows('source', '30');

        $leads = $this->page('/leads', [
            'reset' => 1, 'source' => 'broker',
            'from'  => '2026-08-08', 'to' => '2026-09-06',
        ], $this->admin);

        $this->assertSame(
            $report['broker']['total'],
            $leads['leads']['total'],
            'the list behind the row must be the length of the row',
        );
    }

    public function test_a_follow_up_drill_through_lands_on_the_matching_tab(): void
    {
        $lead = $this->lead($this->alice, now()->subDays(2));

        $this->todo($lead, now()->subDays(3), 'pending');
        $this->todo($lead, now()->subDays(4), 'pending');
        $this->todo($lead, now()->addDay(), 'pending');

        $report = $this->followUpTotal('overdue', '30');

        // no dates: the pending buckets are not measured over the range, so the
        // list must not be narrowed by one either
        $todos = $this->page('/todos', ['reset' => 1, 'tab' => 'overdue', 'type' => 'call'], $this->admin);

        $this->assertSame($report, $todos['todos']['total']);
    }

    /* ---------------- helpers ---------------- */

    /** @return array<string, array<string, mixed>> rows keyed by group */
    private function leadRows(string $dimension, string $range, ?User $as = null): array
    {
        $props = $this->page('/reports/leads', ['group' => $dimension, 'range' => $range], $as);

        return collect($props['rows'])->keyBy('key')->all();
    }

    private function followUpRows(string $status, string $dimension, string $range, ?User $as = null): array
    {
        $props = $this->page('/reports/followups', [
            'status' => $status, 'group' => $dimension, 'range' => $range,
        ], $as);

        return collect($props['rows'])->keyBy('key')->all();
    }

    /** @param  ?array{0: string, 1: string}  $custom  a from/to pair instead of a preset */
    private function followUpTotal(
        string $status,
        string $range,
        ?User $as = null,
        ?array $custom = null,
    ): int {
        $query = ['status' => $status, 'group' => 'type']
            + ($custom ? ['from' => $custom[0], 'to' => $custom[1]] : ['range' => $range]);

        return $this->page('/reports/followups', $query, $as)['totals']['total'];
    }

    private function dashboardCards(string $range): array
    {
        return $this->page('/dashboard', ['range' => $range], $this->admin)['cards'];
    }

    /**
     * One page visit, as Inertia, returning the props.
     *
     * reset=1 on every call: the filters live in the session, so without it one
     * test's grouping would survive into the next assertion in the same test.
     */
    private function page(string $url, array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->admin)
            ->get($url . '?' . http_build_query($query + ['reset' => 1]));

        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function user(string $role, string $name): User
    {
        return User::create([
            'first_name' => $name, 'last_name' => 'Test',
            'email' => strtolower($name) . '@example.test',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role, 'is_active' => true, 'password' => 'password',
        ]);
    }

    private function lead(?User $owner, Carbon $createdAt, array $attributes = []): Lead
    {
        $lead = Lead::create($attributes + [
            'first_name' => 'Meera', 'last_name' => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $this->alpha->id, 'source' => 'walk_in',
            'stage' => 'in_discussion', 'assigned_to' => $owner?->id,
            'created_by' => $this->admin->id,
        ]);

        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    /** A completed to-do carrying a stage transition — the only shape history has. */
    private function history(Lead $lead, string $stage, Carbon $completedAt): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to ?? $this->admin->id,
            'scheduled_at' => $completedAt->copy()->subHour(),
            'type' => 'call', 'status' => 'completed',
            'outcome_stage' => $stage, 'completed_at' => $completedAt,
        ]);
    }

    private function todo(Lead $lead, Carbon $scheduledAt, string $status, ?Carbon $completedAt = null): Todo
    {
        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to ?? $this->admin->id,
            'scheduled_at' => $scheduledAt,
            'type' => 'call', 'status' => $status, 'completed_at' => $completedAt,
        ]);
    }
}
