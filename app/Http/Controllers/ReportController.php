<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LossEvents;
use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The Reporting section: two pages, ten sidebar links.
 *
 * Every link under Reports lands on one of these two actions with a different
 * query string — "Leads · By source" is /reports/leads?group=source, "Follow-ups
 * · Due today" is /reports/followups?status=today. Ten routes and ten Vue files
 * would be ten copies of the same filter bar, the same summary strip, the same
 * chart and the same table, differing in one `group by` each; adding a
 * dimension would then be a new page rather than an entry in config('crm.reports').
 *
 * Two invariants hold every figure on both pages together:
 *
 *   - HISTORY is `todos.outcome_stage` with `todos.completed_at` in the range,
 *     counted `distinct lead_id`. Never `leads.stage`, which says where a lead
 *     stands now and cannot say what happened during a window. A lead created
 *     forty days ago and booked today is a booking that happened today.
 *
 *   - INTAKE is `leads.created_at` in the range. Never inferred from history.
 *
 * They are different populations measured over the same window, which is why
 * they are two queries here rather than one clever one — and why the
 * conversion column has to say out loud what it divides by. See leadRows().
 */
class ReportController extends Controller
{
    use ResolvesDateRange, ResolvesFilters;

    /**
     * The group a null dimension value falls into.
     *
     * `leads.assigned_to` is nullable — UserHandoverService leaves leads
     * unassigned when an admin chooses that — so grouping by it produces a
     * bucket with no user behind it. SQL groups those rows under NULL, and a
     * null array key in PHP is '', which is indistinguishable from a group
     * genuinely keyed by the empty string. A sentinel keeps the row visible,
     * keeps it out of the way of real keys, and gives the front end something
     * to recognise: an Unassigned row has no lead list to drill into, because
     * the Leads page's assigned-to filter takes a user id and there is no id
     * to give it.
     */
    private const NO_GROUP = '__none__';

    /** The three history counts every lead row carries, in column order. */
    private const EVENT_STAGES = ['site_visit_done', 'booking_done', 'lost'];

    /** The follow-up bucket a request that names none is asking for. */
    private const DEFAULT_STATUS = 'today';

    /* ====================================================================
     | Leads
     ==================================================================== */

    public function leads(Request $request)
    {
        $user = $request->user();
        $filters = $this->leadFilters($request, $user);
        $dimension = $filters['group'];

        [$from, $to] = $this->dateWindow($filters);

        /*
         | The population this whole report is about, defined once.
         |
         | No date window: the two questions below are asked over the same set
         | of leads but through different columns — intake through
         | leads.created_at, history through todos.completed_at — so a window
         | baked in here would put the wrong one on the history counts and
         | reproduce exactly the bug the dashboard's stageEvents() exists to
         | avoid.
         |
         | visibleTo() is the privacy boundary and it is inside the closure, so
         | it cannot be left off one of the two queries; the model's soft-delete
         | scope comes along with it. A closure rather than a clone so the two
         | are the same query because they come from the same expression, not
         | because they look alike.
         */
        $scope = fn () => Lead::visibleTo($user);

        $rows = $this->leadRows($scope, $dimension, $from, $to);

        return Inertia::render('Reports/Leads', [
            'rows' => $rows,
            'totals' => $this->leadTotals($rows),
            'filters' => $this->rangeWord($filters),
            'range' => $this->rangePayload($filters, $from, $to),
            'options' => $this->options($user, 'leads'),
        ]);
    }

    /**
     * One row per group: intake, three history counts, and the two percentages.
     *
     * @param  callable(): Builder  $scope
     * @return list<array<string, mixed>>
     */
    private function leadRows(callable $scope, string $dimension, Carbon $from, Carbon $to): array
    {
        // from config, never from the request — see leadFilters()
        $column = config("crm.reports.lead_dimensions.$dimension.column");

        /* INTAKE — leads.created_at, one row per lead, so a plain count. */
        $totals = $this->keyBy(
            $scope()
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("$column as g, count(*) as total")
                ->groupBy($column)
                ->get(),
            fn ($row) => (int) $row->total,
        );

        /*
         | HISTORY — todos.outcome_stage, over the same leads.
         |
         | joinSub against the scope rather than a join against `leads` with the
         | visibility rule written out a second time. The subquery carries
         | visibleTo(), the soft-delete scope and the page's own filters, so
         | "which leads is this report about" is answered in exactly one place
         | and the history counts cannot quietly cover a wider set than the
         | intake count beside them. It also exposes only `id` and `g`, which is
         | what keeps visibleTo()'s unqualified `assigned_to` clause away from
         | the `todos.assigned_to` column it would otherwise be ambiguous with.
         |
         | Grouped by the LEAD's dimension value, including when that dimension
         | is assigned-to: the row is a statement about a group of leads, so all
         | four numbers on it have to count the same population. Grouping the
         | bookings by todos.assigned_to instead would credit the booking to
         | whoever logged the call and leave the row unable to add up.
         |
         | count(distinct lead_id) because one lead can reach the same stage
         | twice inside a range — rescheduled, re-visited — and that is still
         | one lead that got there.
         */
        $events = $scope()->select(['leads.id', DB::raw("leads.$column as g")]);

        $history = Todo::query()
            ->joinSub($events, 'l', 'l.id', '=', 'todos.lead_id')
            ->whereIn('todos.outcome_stage', self::EVENT_STAGES)
            ->whereBetween('todos.completed_at', [$from, $to])
            ->selectRaw('l.g as g, todos.outcome_stage as os, count(distinct todos.lead_id) as total')
            ->groupBy('l.g', 'todos.outcome_stage')
            ->get();

        $byStage = [];

        foreach ($history as $row) {
            $byStage[$this->groupKey($row->g)][$row->os] = (int) $row->total;
        }

        $groups = $this->leadGroups($dimension, array_merge(array_keys($totals), array_keys($byStage)));
        $sumTotal = array_sum($totals);

        return collect($groups)->map(function (array $group) use ($totals, $byStage, $sumTotal) {
            $key = $group['key'];
            $total = $totals[$key] ?? 0;
            $booked = $byStage[$key]['booking_done'] ?? 0;

            return [
                'key' => $key,
                'label' => $group['label'],
                'total' => $total,
                'visits' => $byStage[$key]['site_visit_done'] ?? 0,
                'booked' => $booked,
                'lost' => $byStage[$key]['lost'] ?? 0,
                /*
                 | Bookings in the range over leads created in the range, which
                 | is what the brief asked for and what makes "conversion by
                 | source" answer "is this channel worth the money".
                 |
                 | The two are different populations, so a group whose bookings
                 | came off leads that arrived before the window can read above
                 | 100%. It is not clamped: a silent 100% would hide the very
                 | thing worth noticing, and the column note on the page says
                 | what the ratio is over. The dashboard's headline conversion
                 | card asks a narrower question — of the leads created in this
                 | range, how many have booked since — and the two are not
                 | expected to agree.
                 |
                 | null, never 0, when there is nothing to divide by. The page
                 | prints an em dash: no leads is not the same answer as no
                 | conversions.
                 */
                'conversion' => $total > 0 ? round($booked / $total * 100, 1) : null,
                // this group's share of the leads that came in during the range
                'share' => $sumTotal > 0 ? round($total / $sumTotal * 100, 1) : null,
                // an Unassigned row has no user id to hand the Leads page
                'drillable' => $key !== self::NO_GROUP,
            ];
        })->all();
    }

    /**
     * The report-wide figures, summed from the rows rather than counted again.
     *
     * Every row is zero-filled across a universe that covers every value
     * present, and each lead has exactly one value for the dimension — one
     * stage, one source, one project, one owner — so these sums are the same
     * populations the dashboard's cards count over the same range. Counting
     * them a second time would be a second answer that has to agree with the
     * first; summing the rows is an answer that cannot disagree with the table
     * printing them.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function leadTotals(array $rows): array
    {
        $total = array_sum(array_column($rows, 'total'));
        $booked = array_sum(array_column($rows, 'booked'));

        return [
            'total' => $total,
            'visits' => array_sum(array_column($rows, 'visits')),
            'booked' => array_sum(array_column($rows, 'booked')),
            'lost' => array_sum(array_column($rows, 'lost')),
            // a dash when there is nothing to divide by. Never 0%.
            'conversion' => $total > 0 ? round($booked / $total * 100, 1) : null,
        ];
    }

    /**
     * Every group a lead dimension can take, in display order, zero-filled.
     *
     * A missing category reads as a bug and makes the chart reflow every time a
     * filter moves; a zero one is information. `$present` is the safety net
     * underneath that: any key the queries actually returned that the universe
     * does not know about — a source retired from config, a project row
     * removed, a soft-deleted salesperson still named on last quarter's leads —
     * is appended rather than dropped, so the rows always add up to the totals
     * above them.
     *
     * @param  list<string>  $present
     * @return list<array{key: string, label: string}>
     */
    private function leadGroups(string $dimension, array $present): array
    {
        $universe = match ($dimension) {
            /*
             | Active stages and sources in the admin's order, plus whichever
             | retired ones this report's own rows actually name. A stage
             | switched off last month keeps its row on last month's report and
             | drops off the ones it has nothing in — which is the whole promise
             | deactivation makes.
             */
            'stage' => CrmTaxonomy::stageUniverse($present),
            'source' => CrmTaxonomy::sourceUniverse($present),
            // every project, not only the active ones: a lead on a project that
            // has since been switched off still has to have a row to sit in
            'project' => Project::orderBy('name')->pluck('name', 'id')->all(),
            /*
             | Which broker actually brings business — the question this whole
             | feature was built to answer.
             |
             | The null bucket is the biggest row on this report and it has to
             | be there: every walk-in, every Facebook lead and every broker
             | lead created before channel partners existed has no
             | `channel_partner_id`. Labelling it "No channel partner" rather
             | than leaving it to zeroFill's "Unassigned" is what stops it
             | reading as a mistake — those leads are not waiting to be
             | attributed to anybody. It is not drillable, for the reason the
             | Unassigned row is not: the Leads page's filter takes an id.
             |
             | The legacy leads sit in that bucket rather than under the names
             | typed into `broker_name`, and that is the honest answer. Those
             | strings were never rows and nothing guessed at matching them —
             | see the migration. A report that grouped by the text would invent
             | three brokers out of "Shreeji", "Shreeji Realty" and "shreeji
             | realtors" and then be believed.
             */
            'channel_partner' => $this->channelPartnerLabels() + [self::NO_GROUP => 'No channel partner'],
            default => $this->staffLabels() + [self::NO_GROUP => 'Unassigned'],
        };

        return $this->zeroFill($universe, $present);
    }

    /* ====================================================================
     | Leads · By loss reason
     ==================================================================== */

    /**
     * Why leads were lost over a window, with each reason broken down by
     * project and by who marked the lead lost.
     *
     * Counted from the loss events — LossEvents::latest(), the HISTORY rule
     * above narrowed to each lead's latest loss in the window — so every lead
     * lost in the window is in exactly one reason's row and the rows sum to
     * the Lost figure the By stage report prints for the same range. The scope
     * is joined the way leadRows() joins it, so visibleTo() and the soft-delete
     * scope decide which leads this is about in the same one place.
     */
    public function lossReasons(Request $request, LossEvents $losses)
    {
        $user = $request->user();
        $filters = $this->resolveFilters(
            $request,
            'reports.loss_reasons',
            $this->dateRangeRules(),
            ['range' => config('crm.reports.default_range')],
            fn (array $state) => $this->sanitiseDates($state),
        );

        [$from, $to] = $this->dateWindow($filters);

        $leads = Lead::visibleTo($user)->select(['leads.id', 'leads.project_id']);

        /*
         | Who marked it lost, as a group key. A loss brought in by
         | import:legacy carries the sheet's lead owner in completed_by, not
         | the person who lost it, so it is "Imported" rather than a name; a
         | loss with nobody behind it was automation's.
         */
        $who = "case when exists (select 1 from todo_import_records tir where tir.todo_id = e.id) then 'imported'"
            ." when e.completed_by is null then 'automation' else e.completed_by end";

        $events = fn () => DB::query()
            ->fromSub($losses->latest($from, $to), 'e')
            ->joinSub($leads, 'l', 'l.id', '=', 'e.lead_id');

        $byReason = $this->keyBy(
            $events()->selectRaw('e.lost_reason as g, count(distinct e.lead_id) as total')->groupBy('g')->get(),
            fn ($row) => (int) $row->total,
        );

        $breakdown = function (string $column) use ($events): array {
            $out = [];

            foreach ($events()->selectRaw("e.lost_reason as g, $column as b, count(distinct e.lead_id) as total")->groupBy('g', 'b')->get() as $row) {
                $out[$this->groupKey($row->g)][$this->groupKey($row->b)] = (int) $row->total;
            }

            return $out;
        };

        $byProject = $breakdown('l.project_id');
        $byPerson = $breakdown($who);

        $total = array_sum($byReason);

        $projectLabels = Project::withTrashed()->pluck('name', 'id')->all();
        $personLabels = $this->staffLabels() + ['imported' => 'Imported', 'automation' => 'Automation'];

        $split = fn (array $counts, array $labels, int $of) => collect($counts)
            ->map(fn (int $n, $key) => [
                'key' => (string) $key,
                'label' => $labels[$key] ?? ($key === self::NO_GROUP ? 'No project' : (string) $key),
                'total' => $n,
                'share' => $of > 0 ? round($n / $of * 100, 1) : null,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        /*
         | Every reason, zero-filled like every other report, plus any reason a
         | loss carries that config has since dropped. The no-reason bucket is
         | always a row of its own — never hidden, never folded into Other —
         | because when the legacy import dominates a period, that row is the
         | finding.
         */
        $universe = (array) config('crm.lost_reasons');

        foreach (array_keys($byReason) as $key) {
            if ($key !== self::NO_GROUP && ! array_key_exists($key, $universe)) {
                $universe[$key] = (string) $key;
            }
        }

        $universe[self::NO_GROUP] = LossEvents::NO_REASON_LABEL;

        $rows = collect($universe)
            ->map(function ($label, $key) use ($byReason, $byProject, $byPerson, $total, $split, $projectLabels, $personLabels) {
                $key = (string) $key;
                $n = $byReason[$key] ?? 0;

                return [
                    // the Leads page's reason filter names the no-reason bucket this way
                    'key' => $key === self::NO_GROUP ? LossEvents::NO_REASON : $key,
                    'label' => (string) $label,
                    'total' => $n,
                    'share' => $total > 0 ? round($n / $total * 100, 1) : null,
                    'projects' => $split($byProject[$key] ?? [], $projectLabels, $n),
                    'people' => $split($byPerson[$key] ?? [], $personLabels, $n),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        $began = $losses->attributionBegan();

        return Inertia::render('Reports/LossReasons', [
            'rows' => $rows,
            'totals' => [
                'lost' => $total,
                'noReason' => $byReason[self::NO_GROUP] ?? 0,
                // a state, not an event: leads.stage, and never date-filtered
                'inLostNow' => Lead::visibleTo($user)->where('stage', 'lost')->count(),
            ],
            'attributionBegan' => $began?->format('j M Y'),
            'filters' => $this->rangeWord($filters),
            'range' => $this->rangePayload($filters, $from, $to),
            'options' => ['ranges' => config('crm.date_ranges')],
        ]);
    }

    /* ====================================================================
     | Follow-ups
     ==================================================================== */

    public function followUps(Request $request)
    {
        $user = $request->user();
        $filters = $this->followUpFilters($request, $user);
        $dimension = $filters['group'];
        $status = $filters['status'];

        [$from, $to] = $this->dateWindow($filters);

        /*
         | forUser() is the privacy boundary on to-dos, hasLead() drops the rows
         | whose lead has been soft-deleted, and both are inside the closure for
         | the same reason the leads report puts visibleTo() inside its own.
         |
         | Nothing else narrows it. The report is what the menu link asked for,
         | over the selected dates, and the only control on the page is the one
         | that moves those dates.
         */
        $base = fn () => $this->applyStatus(
            Todo::forUser($user)->hasLead(),
            $status,
            $from,
            $to,
        );

        $rows = $this->followUpRows($base, $dimension, $status);

        return Inertia::render('Reports/FollowUps', [
            'rows' => $rows,
            'totals' => $this->followUpTotals($rows),
            'filters' => $this->rangeWord($filters),
            'range' => $this->rangePayload($filters, $from, $to),
            'options' => $this->options($user, 'followups'),
        ]);
    }

    /**
     * The status buckets, and the one place the date range is allowed near them.
     *
     * The three pending buckets are defined against TODAY, not against the
     * picker: "waiting longer" is scheduled before today, "due today" is
     * scheduled today, "upcoming" is scheduled after it. They are states a
     * follow-up is in right now, which is the same reason the dashboard's
     * Calls pending card and its two panels take no date range either.
     *
     * Narrowing them by the picker as well would range-filter a question that
     * is not about a range: it is a no-op on Due today, an arbitrary trim on
     * Waiting longer, and on Upcoming it is fatal — every range the filter bar
     * offers ends today, and nothing scheduled after today can also fall inside
     * a window that ends today, so the bucket would read zero for every range
     * forever.
     *
     * Completed is the one that is genuinely about a period — "what got done
     * between these dates" — so it is the one the range applies to, on
     * completed_at. Filtering it on scheduled_at instead would count a call
     * planned inside the window and closed long after it, and filtering a
     * pending bucket on completed_at would match nothing at all: the column is
     * null until the call is logged.
     *
     * The four scopes are the Todo model's own, which is what makes a row here
     * and a row on the Follow-ups page's matching tab the same row — the
     * property the drill-through depends on.
     */
    private function applyStatus($query, string $status, Carbon $from, Carbon $to)
    {
        return match ($status) {
            'overdue' => $query->overdue(),
            'upcoming' => $query->upcoming(),
            'completed' => $query->where('status', 'completed')
                ->whereBetween('completed_at', [$from, $to]),
            default => $query->dueToday(),
        };
    }

    /**
     * @param  callable(): Builder  $base
     * @return list<array<string, mixed>>
     */
    private function followUpRows(callable $base, string $dimension, string $status): array
    {
        $column = config("crm.reports.follow_up_dimensions.$dimension.column");

        $counts = $this->keyBy(
            $base()->selectRaw("$column as g, count(*) as total")->groupBy($column)->get(),
            fn ($row) => (int) $row->total,
        );

        /*
         | How long a follow-up sat between the moment it was due and the moment
         | it was closed, averaged per group — the number that says who works
         | their list promptly. Only Completed has both timestamps; for the
         | three pending buckets completed_at is null and there is nothing to
         | average, so the query is not run at all.
         |
         | Negative means closed ahead of the scheduled time, which is a real
         | thing that happens and is not corrected to zero.
         */
        $averages = $status === 'completed'
            ? $this->keyBy(
                $base()
                    ->selectRaw("$column as g, {$this->avgDaysBetween('scheduled_at', 'completed_at')} as avg_days")
                    ->groupBy($column)
                    ->get(),
                // `?: 0.0` folds a rounded negative zero back into zero; left
                // alone it reaches the page as "-0", which reads as a bug
                fn ($row) => $row->avg_days === null ? null : (round((float) $row->avg_days, 1) ?: 0.0),
            )
            : [];

        $groups = $this->followUpGroups($dimension, array_keys($counts));
        $sum = array_sum($counts);

        return collect($groups)->map(function (array $group) use ($counts, $averages, $sum) {
            $key = $group['key'];

            return [
                'key' => $key,
                'label' => $group['label'],
                'total' => $counts[$key] ?? 0,
                'share' => $sum > 0 ? round(($counts[$key] ?? 0) / $sum * 100, 1) : null,
                // null for a pending status, and for a group with nothing in it
                'avgDays' => $averages[$key] ?? null,
                'drillable' => $key !== self::NO_GROUP,
            ];
        })->all();
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function followUpTotals(array $rows): array
    {
        $total = array_sum(array_column($rows, 'total'));

        /*
         | The report-wide average is weighted by each group's count, not the
         | mean of the group means: two follow-ups closed a day late and twenty
         | closed on time is not an average of half a day late.
         */
        $weighted = 0.0;
        $counted = 0;

        foreach ($rows as $row) {
            if ($row['avgDays'] !== null) {
                $weighted += $row['avgDays'] * $row['total'];
                $counted += $row['total'];
            }
        }

        $largest = collect($rows)->sortByDesc('total')->first();

        return [
            'total' => $total,
            // groups with anything in them, out of every group that could have
            'covered' => count(array_filter($rows, fn ($r) => $r['total'] > 0)),
            'groups' => count($rows),
            'largest' => $largest && $largest['total'] > 0
                ? ['label' => $largest['label'], 'value' => $largest['total']]
                : null,
            'avgDays' => $counted > 0 ? round($weighted / $counted, 1) : null,
        ];
    }

    /**
     * @param  list<string>  $present
     * @return list<array{key: string, label: string}>
     */
    private function followUpGroups(string $dimension, array $present): array
    {
        // todos.assigned_to is NOT NULL behind a foreign key, so unlike the
        // leads report there is no unassigned bucket to make room for
        $universe = $dimension === 'type'
            ? config('crm.todo_types')
            : $this->staffLabels();

        return $this->zeroFill($universe, $present);
    }

    /* ====================================================================
     | Shared
     ==================================================================== */

    /**
     * Average whole days between two datetime columns, in the dialect this
     * connection speaks.
     *
     * There is no portable SQL for it: MySQL runs the application, SQLite runs
     * the tests, and neither understands the other's date arithmetic. Doing it
     * in PHP instead would mean pulling every completed row of the range back
     * to average four numbers out of them.
     *
     * Both column names are literals from this file — nothing here is built
     * from a request.
     */
    private function avgDaysBetween(string $start, string $end): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "avg(julianday($end) - julianday($start))",
            'pgsql' => "avg(extract(epoch from ($end - $start)) / 86400)",
            default => "avg(timestampdiff(second, $start, $end)) / 86400",
        };
    }

    /**
     * A grouped result set as an array keyed by group, nulls folded into the
     * sentinel so an unassigned bucket survives the trip into PHP.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function keyBy($rows, callable $value): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[$this->groupKey($row->g)] = $value($row);
        }

        return $out;
    }

    private function groupKey($value): string
    {
        return $value === null ? self::NO_GROUP : (string) $value;
    }

    /**
     * @param  array<string, string>  $universe  key => label, in display order
     * @param  list<string>  $present  keys the queries actually returned
     * @return list<array{key: string, label: string}>
     */
    private function zeroFill(array $universe, array $present): array
    {
        foreach ($present as $key) {
            if (! array_key_exists($key, $universe)) {
                $universe[$key] = $key === self::NO_GROUP ? 'Unassigned' : (string) $key;
            }
        }

        return collect($universe)
            ->map(fn ($label, $key) => ['key' => (string) $key, 'label' => (string) $label])
            ->values()
            ->all();
    }

    /**
     * Staff who can hold work, keyed by id.
     *
     * withTrashed(), because a soft-deleted user stays named on the completed
     * follow-ups they closed — UserHandoverService moves the pending ones and
     * deliberately leaves history where it happened — and a row labelled with a
     * bare id would be the report failing to explain itself. Admins are in the
     * list for the same reason: an admin who takes a departing user's work
     * unassigned ends up holding it.
     *
     * @return array<int|string, string>
     */
    private function staffLabels(): array
    {
        return User::withTrashed()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'deleted_at'])
            ->mapWithKeys(fn (User $u) => [
                $u->id => trim("$u->first_name $u->last_name").($u->trashed() ? ' (removed)' : ''),
            ])
            ->all();
    }

    /**
     * Every channel partner a lead could name, keyed by id.
     *
     * withTrashed(), for the same reason staffLabels() has it: this screen only
     * ever soft deletes, so a removed firm is still named on the leads it
     * brought and the row has to keep its name — "(removed)" so an admin
     * reading the report knows why they cannot find it on the roster.
     *
     * The parent relation is eager loaded and withTrashed() as well, because
     * display_label reads it and a broker under a deleted firm would otherwise
     * lose the half of its label that tells two brokers with the same first
     * name apart.
     *
     * Inactive partners are in the list. A broker switched off last month still
     * brought in the leads they brought in, and a report that dropped them
     * would stop adding up.
     *
     * @return array<int|string, string>
     */
    private function channelPartnerLabels(): array
    {
        return ChannelPartner::withTrashed()
            ->with(['parent' => fn ($q) => $q->withTrashed()])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (ChannelPartner $p) => [
                $p->id => $p->display_label.($p->trashed() ? ' (removed)' : ''),
            ])
            ->all();
    }

    /**
     * The window, as dates the front end can put straight into a drill-through
     * link.
     *
     * Y-m-d rather than the preset word, deliberately. The Leads and To-do
     * pages accept `from`/`to` but reject `range=month`, which they have never
     * offered — so a row clicked while This month is selected has to hand them
     * the two dates the report actually used, or it would land on a list
     * measuring a different period than the number that was clicked.
     */
    /**
     * The filters as the date control needs to read them.
     *
     * Not the shared withRangeWord(): that fills in a range word only where
     * there is none, and these two pages carry a default range, so
     * resolveFilters() merges `range` back in over the state sanitiseDates()
     * had just emptied of it. The word would then say "Last 30 days" under a
     * pair of custom dates the window was actually built from. A custom pair
     * outranks a preset here, exactly as it does in dateWindow().
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function rangeWord(array $filters): array
    {
        return isset($filters['from'], $filters['to'])
            ? ['range' => 'custom'] + $filters
            : $filters;
    }

    private function rangePayload(array $filters, Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'label' => $from->format('j M').' – '.$to->format('j M'),
            // today in IST. The custom date inputs use this as their max rather
            // than the browser clock, which may be in another timezone entirely.
            'today' => today()->toDateString(),
        ];
    }

    /**
     * The dimensions this user may group by.
     *
     * A dimension marked `admin` in config is dropped for anyone who cannot see
     * past their own rows: grouping by assigned-to when every row is yourself
     * is a one-row report that looks broken. This is not the privacy boundary —
     * scopeVisibleTo and scopeForUser are, and they apply whichever dimension
     * is chosen — it only keeps a useless option off the control. The request
     * is validated against the same list, so typing ?group=assigned_to falls
     * back to the default rather than rendering that one-row report.
     *
     * @return array<string, string> key => label
     */
    private function dimensions(string $report, $user): array
    {
        $wide = $report === 'leads'
            // the same test that decides whether the Leads page offers an
            // assigned-to filter at all
            ? $user->can_('see_all_leads')
            // and the same one the To-do page uses, which is role, not permission
            : $user->isAdmin();

        $key = $report === 'leads' ? 'lead_dimensions' : 'follow_up_dimensions';

        return collect(config("crm.reports.$key"))
            ->reject(fn ($d) => ($d['admin'] ?? false) && ! $wide)
            ->map(fn ($d) => $d['label'])
            ->all();
    }

    /**
     * Everything the two pages render, and nothing else.
     *
     * The project, source and assigned-to filters are gone, and their option
     * lists went with them — a payload that still shipped every project and
     * every user to a page with no control to put them in is the kind of dead
     * weight that outlives the reason for it. `stageColors` stays because the
     * chart reads it when the grouping is stage, so a stage is the colour it is
     * everywhere else in the app.
     */
    private function options($user, string $report): array
    {
        return [
            'dimensions' => $this->dimensions($report, $user),
            'statuses' => collect(config('crm.reports.follow_up_statuses'))
                ->map(fn ($s) => $s['label'])->all(),
            'ranges' => config('crm.date_ranges'),
            'today' => today()->toDateString(),
            'stageColors' => CrmTaxonomy::stageColors(),
        ];
    }

    /* ---------------- filters ---------------- */

    /**
     * `group` is validated against the dimensions this user may actually use,
     * so a dimension they are not offered cannot be selected by typing one —
     * resolveFilters() drops a key that fails its rule and the default takes
     * over. The validated key is then what indexes config for a column name,
     * which is why no request value ever reaches a selectRaw().
     */
    private function leadFilters(Request $request, $user): array
    {
        return $this->resolveFilters(
            $request,
            'reports.leads',
            [
                'group' => ['sometimes', 'string', Rule::in(array_keys($this->dimensions('leads', $user)))],
            ] + $this->dateRangeRules(),
            ['group' => 'source', 'range' => config('crm.reports.default_range')],
            fn (array $state) => $this->sanitiseDates($state),
        );
    }

    private function followUpFilters(Request $request, $user): array
    {
        return $this->resolveFilters(
            $request,
            'reports.followups',
            [
                'group' => ['sometimes', 'string', Rule::in(array_keys($this->dimensions('followups', $user)))],
                'status' => ['sometimes', 'string', Rule::in(array_keys(config('crm.reports.follow_up_statuses')))],
            ] + $this->dateRangeRules(),
            [
                'group' => 'type',
                'status' => self::DEFAULT_STATUS,
                'range' => config('crm.reports.default_range'),
            ],
            fn (array $state) => $this->sanitiseDates($state),
        );
    }
}
