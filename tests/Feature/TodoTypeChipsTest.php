<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The type chips, and the two things about them that are easy to get wrong.
 *
 * A chip has to answer "how would this list break down by type" — this tab,
 * every other filter applied, and without the type filter itself. Get the last
 * part wrong and selecting Call leaves three chips reading zero; get the tab
 * part wrong and the chips describe a list the user is not looking at.
 *
 * And the date filter belongs to the Completed tab alone — "what did we get
 * done last week" is a question about a period, on completed_at; the three
 * pending tabs are states measured against today, and a range can only break
 * them.
 *
 * @see \App\Http\Controllers\TodoController::typeCounts()
 * @see \App\Http\Controllers\TodoController::applyTab()
 */
class TodoTypeChipsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $telecaller;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin      = $this->user('admin');
        $this->telecaller = $this->user('telecaller');
        $this->project    = Project::create(['name' => 'Green Court']);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00'));
    }

    /* ---------------- the row adds up ---------------- */

    /** Every configured type has a chip, in config order, zeros included. */
    public function test_the_chips_are_zero_filled_across_every_type(): void
    {
        $types = $this->chips();

        $this->assertSame(array_keys(config('crm.todo_types')), array_column($types['bars'], 'key'));
        $this->assertSame(array_values(config('crm.todo_types')), array_column($types['bars'], 'label'));
        $this->assertSame(0, $types['total']);
    }

    /** "All" is the chips, summed — on every tab. */
    public function test_the_chips_sum_to_the_all_count_on_every_tab(): void
    {
        $this->pending('call', now());                       // today
        $this->pending('site_visit', now());                 // today
        $this->pending('call', now()->subDays(3));           // waiting longer
        $this->pending('whatsapp', now()->addDays(4));       // upcoming
        $this->done('meeting', now(), now());                // completed

        foreach (['today', 'overdue', 'upcoming', 'completed'] as $tab) {
            $types = $this->chips(['tab' => $tab]);

            $this->assertSame(
                $types['total'],
                array_sum(array_column($types['bars'], 'value')),
                "the chips do not add up to All on $tab"
            );
        }
    }

    /* ---------------- the tab is part of the question ---------------- */

    /** Switching tabs recomputes them. A tab is a different list, not a view. */
    public function test_switching_tabs_recomputes_the_chips(): void
    {
        $this->pending('call', now());
        $this->pending('call', now());
        $this->pending('site_visit', now()->addDays(2));
        $this->done('meeting', now()->subDay(), now());

        $this->assertSame([2, 0, 0, 0, 0], $this->values(['tab' => 'today']));
        $this->assertSame([0, 0, 0, 1, 0], $this->values(['tab' => 'upcoming']));
        $this->assertSame([0, 0, 1, 0, 0], $this->values(['tab' => 'completed']));
        $this->assertSame([0, 0, 0, 0, 0], $this->values(['tab' => 'overdue']));
    }

    /**
     * The whole point. Selecting a type filters the table and leaves every chip
     * where it was — and leaves the tab badges alone too.
     */
    public function test_the_type_filter_changes_nothing_but_the_rows(): void
    {
        $this->pending('call', now());
        $this->pending('call', now());
        $this->pending('site_visit', now());
        $this->done('call', now()->subDay(), now());

        $before = $this->props(['tab' => 'today']);

        foreach (['call', 'site_visit', 'whatsapp', 'meeting'] as $type) {
            $after = $this->props(['tab' => 'today', 'type' => $type]);

            $this->assertSame($before['types'], $after['types'], "selecting $type moved the chips");
            $this->assertSame($before['counts'], $after['counts'], "selecting $type moved the tab counts");
        }

        // ...while the table itself really is filtered
        $this->assertSame(3, $this->rows(['tab' => 'today']));
        $this->assertSame(2, $this->rows(['tab' => 'today', 'type' => 'call']));
        $this->assertSame(1, $this->rows(['tab' => 'today', 'type' => 'site_visit']));
        $this->assertSame(0, $this->rows(['tab' => 'today', 'type' => 'meeting']));

        // and the Completed badge is untouched by a type chip on another tab
        $this->assertSame(1, $before['counts']['completed']);
    }

    /** Search narrows the chips — every filter but type does. */
    public function test_search_narrows_the_chips(): void
    {
        $this->pending('call', now());
        $this->pending('site_visit', now(), ['first_name' => 'Zarina']);

        $this->assertSame(2, $this->chips(['tab' => 'today'])['total']);

        $found = $this->chips(['tab' => 'today', 'search' => 'Zarina']);

        $this->assertSame(1, $found['total']);
        $this->assertSame([0, 0, 0, 1, 0], $this->values(['tab' => 'today', 'search' => 'Zarina']));
    }

    /* ---------------- who is counted ---------------- */

    /** A telecaller's chips count a telecaller's tasks and nobody else's. */
    public function test_a_telecaller_only_counts_their_own(): void
    {
        $this->pending('call', now());                                  // the admin's
        $this->pending('site_visit', now(), [], $this->telecaller);
        $this->pending('site_visit', now(), [], $this->telecaller);

        $this->assertSame(3, $this->chips(['tab' => 'today'])['total'], 'the admin sees all of them');

        $mine = $this->chips(['tab' => 'today'], $this->telecaller);

        $this->assertSame(2, $mine['total']);
        $this->assertSame([0, 0, 0, 2, 0], $this->values(['tab' => 'today'], $this->telecaller),
            "another owner's tasks must not be counted");
    }

    /** A soft-deleted lead takes its tasks out of the counts. */
    public function test_a_deleted_leads_tasks_leave_the_counts(): void
    {
        $lead = $this->lead();
        $this->todo($lead, 'call', now());
        $this->pending('site_visit', now());

        $this->assertSame(2, $this->chips(['tab' => 'today'])['total']);

        $lead->delete();

        $this->assertSame(1, $this->chips(['tab' => 'today'])['total']);
        $this->assertSame([0, 0, 0, 1, 0], $this->values(['tab' => 'today']));
    }

    /* ---------------- which date the date filter means ---------------- */

    /**
     * The discriminating pair. Two completed tasks whose scheduled and
     * completed dates are swapped: filtering on the wrong column returns the
     * wrong one, not merely a different count.
     */
    public function test_the_completed_tab_filters_on_completed_at(): void
    {
        $planned = $this->done('call', Carbon::parse('2026-03-01 10:00'), now());          // done today
        $done    = $this->done('site_visit', now(), Carbon::parse('2026-03-01 10:00'));    // done in March

        $today = $this->props(['tab' => 'completed', 'range' => 'today']);
        $march = $this->props(['tab' => 'completed', 'from' => '2026-03-01', 'to' => '2026-03-31']);

        $this->assertSame(1, $today['types']['total']);
        $this->assertSame([$planned->id], collect($today['todos']['data'])->pluck('id')->all(),
            'Completed + Today must be the task completed today, whenever it was planned');

        $this->assertSame(1, $march['types']['total']);
        $this->assertSame([$done->id], collect($march['todos']['data'])->pluck('id')->all(),
            'a March window must be what was completed in March, not what was planned then');

        // and the chips follow the same column
        $this->assertSame([1, 0, 0, 0, 0], $this->values(['tab' => 'completed', 'range' => 'today']));
        $this->assertSame([0, 0, 0, 1, 0], $this->values(['tab' => 'completed', 'from' => '2026-03-01', 'to' => '2026-03-31']));
    }

    /**
     * The pending tabs take no date range at all.
     *
     * They are defined against today, not against the picker, so a range can
     * only ever trim them arbitrarily — and on Upcoming it wipes them out, since
     * every range the control offers ends today and nothing scheduled after
     * today can fall inside a window that ends today.
     */
    public function test_the_pending_tabs_ignore_the_date_range(): void
    {
        $this->pending('call', now()->subDays(3));          // due three days ago
        $this->pending('site_visit', now()->subDays(20));   // due twenty days ago

        $this->assertSame(2, $this->chips(['tab' => 'overdue'])['total'], 'all time is the default');
        $this->assertSame(2, $this->chips(['tab' => 'overdue', 'range' => '7'])['total'],
            'a range must not trim a bucket that is defined against today');
        $this->assertSame(2, $this->chips(['tab' => 'overdue', 'range' => '30'])['total']);
        $this->assertSame(2, $this->chips(['tab' => 'overdue', 'range' => 'today'])['total'],
            'Overdue + Today read zero before: overdue is by definition before today');
    }

    /** The regression MAJ-2 was: Upcoming returning zero for every range. */
    public function test_the_upcoming_tab_survives_every_range_the_control_offers(): void
    {
        $this->pending('call', now()->addDays(3));
        $this->pending('site_visit', now()->addDays(20));

        foreach ([null, 'today', '7', '30'] as $range) {
            $props = $this->props(['tab' => 'upcoming'] + ($range ? ['range' => $range] : []));

            $this->assertSame(2, $props['todos']['total'], "Upcoming must hold its rows for range=$range");
            $this->assertSame(2, $props['types']['total'], "the chips must agree for range=$range");
        }

        // and a custom pair, which the control also allows
        $this->assertSame(
            2,
            $this->props(['tab' => 'upcoming', 'from' => '2026-08-01', 'to' => '2026-09-02'])['todos']['total'],
        );
    }

    /**
     * Seven whole days ending today, today counted as one of them. A bare
     * subDays() keeps the current clock time and drops the earliest morning.
     */
    public function test_the_seven_day_range_covers_seven_whole_days(): void
    {
        $this->done('call', now(), Carbon::parse('2026-08-27 00:30'));   // first minute of day one
        $this->done('call', now(), Carbon::parse('2026-09-02 23:30'));   // last minute of today
        $this->done('call', now(), Carbon::parse('2026-08-26 23:30'));   // one minute too early

        $this->assertSame(2, $this->chips(['tab' => 'completed', 'range' => '7'])['total']);
        $this->assertSame(3, $this->chips(['tab' => 'completed', 'range' => '30'])['total']);
    }

    /** A backwards pair, or one ending in the future, is dropped on the way in. */
    public function test_an_impossible_custom_pair_falls_back_to_all_time(): void
    {
        $this->pending('call', now());

        $this->assertSame(1, $this->chips(['tab' => 'today', 'from' => '2026-08-20', 'to' => '2026-08-10'])['total']);
        $this->assertSame(1, $this->chips(['tab' => 'today', 'from' => '2026-08-01', 'to' => '2099-01-01'])['total']);
        $this->assertArrayNotHasKey('from', session('filters.todos'));
    }

    /* ---------------- clearing ---------------- */

    /** Clear resets the chip and the dates, and keeps the tab. */
    public function test_clear_resets_the_type_and_dates_but_not_the_tab(): void
    {
        $this->pending('call', now()->addDays(3));
        $this->pending('site_visit', now()->addDays(3));

        $this->props(['tab' => 'upcoming', 'type' => 'call', 'range' => '7', 'search' => 'Meera']);

        // Clear sends reset=1 and the tab, and nothing else
        $props = $this->props(['tab' => 'upcoming']);

        $this->assertSame(['tab' => 'upcoming'], session('filters.todos'));
        $this->assertSame('upcoming', $props['tab'], 'Clear must not move the user off their tab');
        $this->assertSame(2, $props['types']['total']);
        $this->assertSame(2, $props['todos']['total']);
        $this->assertSame('', $props['filters']['range']);
        $this->assertArrayNotHasKey('type', $props['filters']);
    }

    /* ---------------- helpers ---------------- */

    private function props(array $query, ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin)
            ->get('/todos?' . http_build_query($query + ['reset' => 1]), [
                'X-Inertia'         => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            ])
            ->json('props');
    }

    private function chips(array $query = [], ?User $as = null): array
    {
        return $this->props($query, $as)['types'];
    }

    /** The chip values in config order: call, whatsapp, meeting, site_visit, email. */
    private function values(array $query = [], ?User $as = null): array
    {
        return array_column($this->chips($query, $as)['bars'], 'value');
    }

    private function rows(array $query = [], ?User $as = null): int
    {
        return $this->props($query, $as)['todos']['total'];
    }

    private function pending(string $type, Carbon $at, array $lead = [], ?User $owner = null): Todo
    {
        return $this->todo($this->lead($lead, $owner), $type, $at);
    }

    private function done(string $type, Carbon $scheduled, Carbon $completed): Todo
    {
        $todo = $this->todo($this->lead(), $type, $scheduled);

        $todo->update(['status' => 'completed', 'completed_at' => $completed, 'outcome_stage' => 'connected']);

        return $todo;
    }

    private function todo(Lead $lead, string $type, Carbon $at): Todo
    {
        return Todo::create([
            'lead_id'      => $lead->id,
            'assigned_to'  => $lead->assigned_to,
            'created_by'   => $this->admin->id,
            'scheduled_at' => $at,
            'type'         => $type,
            'status'       => 'pending',
        ]);
    }

    private function lead(array $overrides = [], ?User $owner = null): Lead
    {
        return Lead::create($overrides + [
            'first_name'    => 'Meera',
            'last_name'     => 'Sharma',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id'    => $this->project->id,
            'source'        => 'walk_in',
            'stage'         => 'connected',
            'assigned_to'   => ($owner ?? $this->admin)->id,
            'created_by'    => $this->admin->id,
        ]);
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
