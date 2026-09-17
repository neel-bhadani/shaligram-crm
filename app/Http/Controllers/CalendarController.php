<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * The follow-up calendar.
 *
 * One read-only page that renders a month of follow-ups on their scheduled
 * dates. It deliberately reuses every question the rest of the application
 * already answers:
 *
 *   - which date matters                 `todos.scheduled_at`, the same column
 *                                        the Todos page and the dashboard list
 *                                        against — there is no due_date/due_at.
 *   - whose follow-ups may be seen       Todo::forUser() + hasLead(), exactly
 *                                        as every other follow-up list chains.
 *   - what "today / upcoming / overdue"  the Todo scopes dueToday(), upcoming()
 *     means                              and overdue(), so a counter here can
 *                                        never disagree with the Todos page.
 *   - what a row links to                the lead detail page (leads.show) —
 *                                        there is no follow-up detail page.
 *
 * Everything is scoped to the visible calendar range (the month's grid, plus
 * enough of the adjacent months to fill the weeks), so viewing a month does
 * not load the whole table. Dates are formatted in the application timezone
 * (Asia/Kolkata) before they leave the server — a follow-up at 00:30 IST is
 * grouped with its local day, not the previous UTC day.
 */
class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $clean = $this->clean($request);
        $month = $clean['month'];

        /*
         | The assignee filter is admin-only, the same way the Todos page only
         | offers it to an admin. A non-admin's rows are already their own, so
         | honouring the parameter would merge two competing "whose" clauses and
         | answer with a suspiciously empty month.
         */
        $assignee = $user->isAdmin() ? $clean['assignee'] : null;

        $range = $this->visibleRange($month);

        $events = $this->base(
            $user,
            $assignee,
            $clean['status'],
            $clean['project'],
            $clean['stage'],
        )
            ->whereBetween('scheduled_at', [$range['start'], $range['end']])
            ->with([
                'lead:id,first_name,middle_name,last_name,stage,project_id',
                'lead.project:id,name',
                'owner:id,first_name,last_name,role',
            ])
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Todo $todo) => $this->event($todo))
            ->values()
            ->all();

        return Inertia::render('Calendar/Index', [
            'month' => $month->format('Y-m'),
            'events' => $events,
            /*
             | The three counters are the Todo scopes under the same filters the
             | calendar is showing — the filters have to move the numbers, or a
             | filtered calendar would be describing a different set of follow-ups
             | to the one it draws.
             */
            'summary' => [
                'today' => $this->base($user, $assignee, $clean['status'], $clean['project'], $clean['stage'])
                    ->dueToday()
                    ->count(),
                'upcoming' => $this->base($user, $assignee, $clean['status'], $clean['project'], $clean['stage'])
                    ->upcoming()
                    ->count(),
                'overdue' => $this->base($user, $assignee, $clean['status'], $clean['project'], $clean['stage'])
                    ->overdue()
                    ->count(),
            ],
            'filters' => [
                'assignee' => $assignee ? (string) $assignee : '',
                'status' => $clean['status'] ?? '',
                'project' => $clean['project'] ? (string) $clean['project'] : '',
                'stage' => $clean['stage'] ?? '',
            ],
            'options' => [
                /*
                 | The assignee dropdown is admin-only, exactly like the Todos
                 | page's: a non-admin's query is already scoped to their own
                 | rows by forUser(), so picking between "all" and "me" is not a
                 | question they can ask.
                 */
                'users' => $user->isAdmin()
                    ? User::whereIn('role', ['telecaller', 'salesperson'])
                        ->approved()
                        ->orderBy('first_name')
                        ->get(['id', 'first_name', 'last_name'])
                    : [],
                'projects' => Project::orderBy('name')->get(['id', 'name']),
                'stages' => CrmTaxonomy::allStages(),
                'stageColors' => CrmTaxonomy::stageColors(),
                'todoTypes' => config('crm.todo_types'),
                'roleLabels' => config('crm.role_labels'),
                'today' => today()->toDateString(),
            ],
        ]);
    }

    /**
     * The follow-ups a query may show, before the visible range is applied.
     *
     * Everyone gets forUser() (a non-admin sees only their own rows, an admin
     * sees everything) and hasLead() (a follow-up on a soft-deleted lead is on
     * no list in the application). The four filters narrow from there.
     *
     * Cancelled follow-ups are the one status hidden by default, the same way
     * the application treats a cancelled task everywhere else. The status
     * filter's three values each ask for one status explicitly, so "All"
     * becoming everything-but-cancelled is the only case that says so here.
     */
    private function base(User $user, ?int $assignee, ?string $status, ?int $project, ?string $stage): Builder
    {
        return Todo::forUser($user)
            ->hasLead()
            ->when($status === null, fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->when($assignee, fn ($q, $v) => $q->where('assigned_to', $v))
            ->when($project, fn ($q, $v) => $q->whereHas('lead', fn ($l) => $l->where('project_id', $v)))
            ->when($stage, fn ($q, $v) => $q->whereHas('lead', fn ($l) => $l->where('stage', $v)))
            ->when(
                $status,
                fn ($q, $v) => match ($v) {
                    'pending' => $q->where('status', 'pending'),
                    'completed' => $q->where('status', 'completed'),
                    'overdue' => $q->overdue(),
                }
            );
    }

    /**
     * The day boundaries of the month's grid.
     *
     * The grid starts on the Sunday before the 1st and ends on the Saturday
     * after the last day, so the leading/trailing weeks are fetched too — the
     * month is what the user asked for, and the adjacent days are what make it
     * render as a grid.
     */
    private function visibleRange(Carbon $month): array
    {
        return [
            'start' => $month->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY),
            'end' => $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY),
        ];
    }

    /**
     * The one question a row answers, timed and grouped in app timezone.
     *
     * `date` is the local (Asia/Kolkata) calendar day of scheduled_at — the
     * front end groups by this string, so a follow-up near midnight lands on
     * its own day no matter which timezone the browser is sitting in. `time`
     * is already formatted so nothing client-side has to re-interpret it.
     */
    private function event(Todo $todo): array
    {
        return [
            'id' => $todo->id,
            'date' => $todo->scheduled_at->format('Y-m-d'),
            'time' => $todo->scheduled_at->format('g:i A'),
            'status' => $todo->status,
            'display_status' => $this->displayStatus($todo),
            'type' => $todo->type,
            'lead' => $todo->lead ? [
                'id' => $todo->lead->id,
                'name' => $todo->lead->full_name,
                'stage' => $todo->lead->stage,
            ] : null,
            'assignee' => $todo->owner
                ? ['id' => $todo->owner->id, 'name' => $todo->owner->display_name]
                : null,
            'project' => $todo->lead?->project
                ? ['id' => $todo->lead->project->id, 'name' => $todo->lead->project->name]
                : null,
            'url' => route('leads.show', $todo->lead_id),
        ];
    }

    /**
     * The colour a follow-up is drawn with, decided here in app timezone.
     *
     * Overdue is strictly before today, pending is today, upcoming is after —
     * the same three buckets the Todo scopes define, plus completed and
     * cancelled for the rows those scopes do not see.
     */
    private function displayStatus(Todo $todo): string
    {
        if ($todo->status === 'cancelled') {
            return 'cancelled';
        }

        if ($todo->status === 'completed') {
            return 'completed';
        }

        $scheduled = $todo->scheduled_at;

        if ($scheduled->lt(now()->startOfDay())) {
            return 'overdue';
        }

        if ($scheduled->gt(now()->copy()->endOfDay())) {
            return 'upcoming';
        }

        return 'pending';
    }

    /**
     * Read the query string, quietly.
     *
     * A malformed month or a filter value that was not offered falls back to
     * "don't filter" rather than erroring the page — this is a read-only view
     * and a strange URL should cost a month's rendering, not a 422.
     */
    private function clean(Request $request): array
    {
        $rawMonth = (string) $request->query('month', '');

        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $rawMonth)
            ? Carbon::createFromFormat('Y-m', $rawMonth)->startOfMonth()
            : today()->startOfMonth();

        return [
            'month' => $month,
            'assignee' => ctype_digit((string) $request->query('assignee', '')) ? (int) $request->query('assignee') : null,
            'status' => in_array($request->query('status'), ['pending', 'completed', 'overdue'], true)
                ? $request->query('status')
                : null,
            'project' => ctype_digit((string) $request->query('project', '')) ? (int) $request->query('project') : null,
            'stage' => is_string($request->query('stage')) && $request->query('stage') !== ''
                ? $request->query('stage')
                : null,
        ];
    }
}