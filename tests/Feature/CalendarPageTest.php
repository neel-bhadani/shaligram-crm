<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The follow-up calendar.
 *
 * The page's contract is that it never invents a definition:
 *
 *   - a follow-up sits on scheduled_at, the same field the Todos page and the
 *     dashboard list against — there is no due date anywhere else to pick;
 *   - today / upcoming / overdue are the Todo scopes, so a counter here can
 *     never disagree with the Follow-ups page;
 *   - who may see a follow-up is Todo::forUser() + hasLead(), so deleting a
 *     lead hides its follow-ups here exactly as it does everywhere else;
 *   - a visible range query, not the whole table, so a month costs one
 *     BETWEEN on the indexed datetime column.
 */
class CalendarPageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/calendar';

    private User $admin;

    private User $priya;

    private User $sam;

    private Project $vanam;

    private Project $meridian;

    protected function setUp(): void
    {
        parent::setUp();

        // a fixed Thursday in IST, so "today", the month under test and the
        // grid boundaries are the same in every test
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00', 'Asia/Kolkata'));

        $this->admin = $this->user('admin', 'Ann', 'Admin');
        $this->priya = $this->user('telecaller', 'Priya', 'Shah');
        $this->sam = $this->user('salesperson', 'Sam', 'Rao');
        $this->vanam = Project::create(['name' => 'Vanam']);
        $this->meridian = Project::create(['name' => 'Meridian']);
    }

    /* ---------------- door ---------------- */

    public function test_an_authenticated_user_can_open_the_calendar(): void
    {
        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Calendar/Index'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(self::URL)
            ->assertRedirect(route('login'));
    }

    /* ---------------- month ---------------- */

    public function test_the_current_month_loads_by_default(): void
    {
        $this->actingAs($this->admin)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('month', '2026-09')
                ->where('options.today', '2026-09-15'));
    }

    public function test_previous_month_is_asked_for(): void
    {
        $this->actingAs($this->admin)
            ->get(self::URL.'?month=2026-08')
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-08'));
    }

    public function test_next_month_is_asked_for(): void
    {
        $this->actingAs($this->admin)
            ->get(self::URL.'?month=2026-10')
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-10'));
    }

    public function test_a_month_outside_january_to_december_falls_back_to_this_month(): void
    {
        $this->actingAs($this->admin)
            ->get(self::URL.'?month=nonsense')
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-09'));
    }

    /* ---------------- where a follow-up lands ---------------- */

    public function test_todays_follow_up_lands_on_today(): void
    {
        $todo = $this->todo($this->priya, '2026-09-15 11:30');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->where('id', $todo->id)
                    ->where('date', '2026-09-15')
                    ->count() === 1));
    }

    public function test_an_upcoming_follow_up_lands_on_its_date(): void
    {
        $todo = $this->todo($this->priya, '2026-09-20 09:30');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->where('id', $todo->id)
                    ->where('date', '2026-09-20')
                    ->count() === 1));
    }

    /** A completed follow-up still appears, on its scheduled date, marked done. */
    public function test_a_completed_follow_up_appears_with_a_completed_state(): void
    {
        $todo = $this->todo($this->priya, '2026-09-12 14:00', status: 'completed');
        $todo->update(['completed_at' => '2026-09-12 14:05']);

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->where('id', $todo->id)
                    ->where('date', '2026-09-12')
                    ->where('display_status', 'completed')
                    ->count() === 1));
    }

    public function test_an_overdue_follow_up_is_marked_overdue(): void
    {
        $todo = $this->todo($this->priya, '2026-09-10 09:00');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->where('id', $todo->id)
                    ->where('display_status', 'overdue')
                    ->count() === 1));
    }

    public function test_a_pending_follow_up_today_is_pending_and_a_future_one_upcoming(): void
    {
        $this->todo($this->priya, '2026-09-15 11:30');
        $this->todo($this->priya, '2026-09-20 09:30');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) =>
                    collect($events)->where('display_status', 'pending')->count() === 1
                    && collect($events)->where('display_status', 'upcoming')->count() === 1));
    }

    /* ---------------- what is NOT fetched ---------------- */

    public function test_distant_dates_are_not_returned_for_the_viewed_month(): void
    {
        $distant = $this->todo($this->priya, '2026-12-25 10:00');
        $before = $this->todo($this->priya, '2026-08-15 10:00');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) =>
                    collect($events)->where('id', $distant->id)->isEmpty()
                    && collect($events)->where('id', $before->id)->isEmpty()));
    }

    /** A follow-up exactly one day outside the grid week is also not loaded. */
    public function test_grid_trail_days_are_included_but_no_further(): void
    {
        // Sunday 2026-09-06 and Tuesday 2026-09-08 are both inside the month;
        // the boundary case is the week start before the month (Sun Aug 30).
        $border = $this->todo($this->priya, '2026-08-30 10:00'); // grid start, included
        $justOut = $this->todo($this->priya, '2026-08-29 10:00'); // Sat before grid start

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) =>
                    collect($events)->where('id', $border->id)->isNotEmpty()
                    && collect($events)->where('id', $justOut->id)->isEmpty()));
    }

    /* ---------------- filters ---------------- */

    public function test_the_assignee_filter_narrows_the_calendar(): void
    {
        $mine = $this->todo($this->priya, '2026-09-15 11:30');
        $this->todo($this->sam, '2026-09-15 12:30');

        $this->actingAs($this->admin)
            ->get(self::URL."?assignee={$this->priya->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$mine->id]));
    }

    public function test_the_status_filter_narrows_the_calendar(): void
    {
        $this->todo($this->priya, '2026-09-15 11:30');
        $completed = $this->todo($this->priya, '2026-09-12 14:00', status: 'completed');

        $this->actingAs($this->priya)
            ->get(self::URL.'?status=completed')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$completed->id]));
    }

    public function test_the_overdue_status_filter_shows_only_past_pending_follow_ups(): void
    {
        $overdue = $this->todo($this->priya, '2026-09-10 09:00');
        $this->todo($this->priya, '2026-09-15 11:30');

        $this->actingAs($this->priya)
            ->get(self::URL.'?status=overdue')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$overdue->id]));
    }

    public function test_the_project_filter_narrows_the_calendar(): void
    {
        $mine = $this->todo($this->priya, '2026-09-15 11:30', project: $this->vanam);
        $this->todo($this->priya, '2026-09-15 12:30', project: $this->meridian);

        $this->actingAs($this->admin)
            ->get(self::URL."?project={$this->vanam->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$mine->id]));
    }

    public function test_the_stage_filter_narrows_the_calendar(): void
    {
        $mine = $this->todo($this->priya, '2026-09-15 11:30', stage: 'connected');
        $this->todo($this->priya, '2026-09-15 12:30', stage: 'fresh');

        $this->actingAs($this->admin)
            ->get(self::URL.'?stage=connected')
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$mine->id]));
    }

    public function test_combined_filters_narrow_against_each_other(): void
    {
        $first = $this->todo($this->priya, '2026-09-15 11:30', project: $this->vanam, stage: 'fresh');
        $this->todo($this->sam, '2026-09-15 12:30', project: $this->vanam, stage: 'fresh');
        $this->todo($this->priya, '2026-09-15 13:30', project: $this->meridian, stage: 'fresh');
        $this->todo($this->priya, '2026-09-15 14:30', project: $this->vanam, stage: 'connected');

        $this->actingAs($this->admin)
            ->get(self::URL."?assignee={$this->priya->id}&project={$this->vanam->id}&stage=fresh&status=pending")
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$first->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.today', 1));
    }

    public function test_the_filters_are_echoed_back_to_the_page(): void
    {
        $this->actingAs($this->admin)
            ->get(self::URL."?assignee={$this->priya->id}&project={$this->vanam->id}&stage=fresh&status=pending")
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.assignee', (string) $this->priya->id)
                ->where('filters.status', 'pending')
                ->where('filters.project', (string) $this->vanam->id)
                ->where('filters.stage', 'fresh'));
    }

    /* ---------------- visibility ---------------- */

    public function test_a_telecaller_only_sees_their_own_follow_ups(): void
    {
        $mine = $this->todo($this->priya, '2026-09-15 11:30');
        $this->todo($this->sam, '2026-09-15 12:30');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$mine->id]));
    }

    public function test_an_admin_sees_everybody(): void
    {
        $a = $this->todo($this->priya, '2026-09-15 11:30');
        $b = $this->todo($this->sam, '2026-09-15 12:30');

        $this->actingAs($this->admin)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)->pluck('id')->sort()->values()->all()
                    === collect([$a->id, $b->id])->sort()->values()->all()));
    }

    /** A non-admin's assignee query string is ignored, not honoured. */
    public function test_a_non_admin_cannot_widen_with_the_assignee_filter(): void
    {
        $mine = $this->todo($this->priya, '2026-09-15 11:30');
        $this->todo($this->sam, '2026-09-15 12:30');

        $this->actingAs($this->priya)
            ->get(self::URL."?assignee={$this->sam->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$mine->id]));
    }

    public function test_a_soft_deleted_lead_takes_its_follow_ups_with_it(): void
    {
        $visible = $this->todo($this->priya, '2026-09-15 11:30');
        $hidden = $this->todo($this->priya, '2026-09-15 12:30');
        $hidden->lead->delete();

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->pluck('id')
                    ->all() === [$visible->id]));
    }

    /* ---------------- timezone ---------------- */

    /**
     * A follow-up at 00:30 IST is the same instant as 19:00 UTC the day before.
     * Grouping has to keep it on its local day, or the "after midnight" slot
     * silently moves a day.
     *
     * @see https://en.wikipedia.org/wiki/India_Standard_Time
     */
    public function test_a_midnight_follow_up_groups_on_its_local_day(): void
    {
        $todo = $this->todo($this->priya, '2026-09-15 00:30');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->where('id', $todo->id)
                    ->where('date', '2026-09-15')
                    ->count() === 1));
    }

    /** The time a follow-up ships is pre-formatted, not a timestamp to re-read. */
    public function test_the_event_time_is_pre_formatted_in_app_timezone(): void
    {
        $todo = $this->todo($this->priya, '2026-09-15 15:20');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) => collect($events)
                    ->where('id', $todo->id)
                    ->where('time', '3:20 PM')
                    ->count() === 1));
    }

    /* ---------------- summary counters ---------------- */

    public function test_the_summary_matches_the_todo_scopes(): void
    {
        $this->todo($this->priya, '2026-09-15 11:30'); // today
        $this->todo($this->priya, '2026-09-15 13:00'); // today
        $this->todo($this->priya, '2026-09-20 09:30'); // upcoming
        $this->todo($this->priya, '2026-09-10 09:00'); // overdue

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.today', Todo::forUser($this->priya)->hasLead()->dueToday()->count())
                ->where('summary.upcoming', Todo::forUser($this->priya)->hasLead()->upcoming()->count())
                ->where('summary.overdue', Todo::forUser($this->priya)->hasLead()->overdue()->count()));
    }

    public function test_the_summary_moves_with_a_filter(): void
    {
        $this->todo($this->priya, '2026-09-15 11:30');
        $other = $this->todo($this->sam, '2026-09-20 09:30');

        // none of Sam's follow-ups are today's, so as an admin filtering by him
        // the "today" counter reads the same as the Todos page's own count
        $this->actingAs($this->admin)
            ->get(self::URL."?assignee={$this->sam->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.today', Todo::forUser($this->admin)->hasLead()
                    ->where('assigned_to', $this->sam->id)->dueToday()->count())
                ->where('summary.upcoming', 1));
    }

    /* ---------------- event shape / duplication ---------------- */

    public function test_the_events_carry_only_what_the_calendar_needs(): void
    {
        $todo = $this->todo($this->priya, '2026-09-15 11:30', lead: 'Meera Vaghela');

        $this->actingAs($this->admin)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.id', $todo->id)
                ->where('events.0.date', '2026-09-15')
                ->where('events.0.lead.name', 'Meera Vaghela')
                ->where('events.0.lead.stage', 'fresh')
                ->where('events.0.assignee.name', 'Priya Shah')
                ->where('events.0.project.id', $this->vanam->id)
                ->where('events.0.url', route('leads.show', $todo->lead_id)));
    }

    /** The lead's own columns are the whole payload — nothing sensitive rides along. */
    public function test_events_do_not_leak_lead_contact_fields(): void
    {
        $todo = $this->todo($this->priya, '2026-09-15 11:30');

        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.lead', fn ($lead) => empty(array_intersect(
                    ['mobile_number', 'email', 'assigned_to'],
                    array_keys(is_array($lead) ? $lead : $lead->toArray()),
                ))));
    }

    /** whereHas for the project/stage filters is an EXISTS — one row per todo. */
    public function test_there_are_no_duplicate_events_from_joins(): void
    {
        $a = $this->todo($this->priya, '2026-09-15 11:30', project: $this->vanam, stage: 'connected');
        $b = $this->todo($this->sam, '2026-09-15 12:30', project: $this->vanam, stage: 'fresh');

        $dbCount = Todo::forUser($this->admin)->hasLead()
            ->whereBetween('scheduled_at', ['2026-08-30 00:00:00', '2026-10-03 23:59:59'])
            ->count();

        $this->actingAs($this->admin)
            ->get(self::URL."?project={$this->vanam->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('events', fn ($events) =>
                    count($events) === $dbCount
                    && collect($events)->where('id', $a->id)->count() === 1
                    && collect($events)->where('id', $b->id)->count() === 1));
    }

    public function test_the_assignee_dropdown_is_admin_only(): void
    {
        $this->actingAs($this->priya)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page->where('options.users', []));

        $this->actingAs($this->admin)
            ->get(self::URL)
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.users', fn ($users) => collect($users)->pluck('id')->all()
                    === [$this->priya->id, $this->sam->id]));
    }

    /* ---------------- fixtures ---------------- */

    private function todo(
        User $owner,
        string $at,
        string $lead = 'Meera Vaghela',
        ?Project $project = null,
        string $status = 'pending',
        string $stage = 'fresh',
    ): Todo {
        $project ??= $this->vanam;

        [$first, $last] = explode(' ', $lead, 2) + [null, null];

        $leadRow = Lead::create([
            'first_name' => $first,
            'last_name' => $last ?? '',
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'project_id' => $project->id,
            'source' => 'walk_in',
            'stage' => $stage,
            'assigned_to' => $owner->id,
            'assigned_role' => $owner->role,
            'created_by' => $this->admin->id,
        ]);

        return Todo::create([
            'lead_id' => $leadRow->id,
            'assigned_to' => $owner->id,
            'created_by' => $this->admin->id,
            'scheduled_at' => $at,
            'type' => 'call',
            'status' => $status,
        ]);
    }

    private function user(string $role, string $first, string $last): User
    {
        return User::create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => fake()->unique()->safeEmail(),
            'mobile_number' => (string) fake()->unique()->numberBetween(9000000000, 9999999999),
            'role' => $role,
            'is_active' => true,
            'password' => 'password',
        ]);
    }
}