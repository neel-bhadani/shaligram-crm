<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The follow-up calendar: every task for a month, on the day it is due.
 *
 * One question and one answer a month. The grid shows the follow-ups that fall
 * inside the visible window — the month, padded to whole Sun-to-Sat weeks — and
 * the three summary cards above it count the buckets every other page counts
 * (Today, Upcoming, Overdue), so a number here and a number on the To-do page
 * or the follow-ups report are the same number.
 *
 * The canonical date is `scheduled_at`. It is the column the To-do page's three
 * pending tabs and the report's three pending buckets are measured against
 * (Todo::overdue/dueToday/upcoming), so it is the only date that can place a
 * follow-up on the calendar without inventing a definition. Completed rows stay
 * on the date they were scheduled — a call logged yesterday is shown on the day
 * it was meant to happen, which is the day the grid reads as its schedule.
 * Cancelled rows are shown nowhere, exactly as they are nowhere else.
 */
class CalendarController extends Controller
{
    use ResolvesFilters;

    /** The statuses the filter may pick. `overdue` is a state, not a column. */
    private const STATUSES = ['pending', 'completed', 'overdue'];

    /** A week starts on Sunday, which is how the grid is labelled. */
    private const WEEK_STARTS = Carbon::SUNDAY;

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $month = Carbon::createFromFormat('Y-m', $filters['month']);

        /*
         | The visible window: the month's cells, padded out to the full weeks
         | that contain them. Only these dates are queried, so a month costs one
         | ranged query over a bounded window rather than a read of the whole
         | to-do table.
         */
        $start = $month->copy()->startOfMonth()->startOfWeek(self::WEEK_STARTS)->startOfDay();
        $end = $month->copy()->endOfMonth()->endOfWeek(self::WEEK_STARTS)->endOfDay();

        $status = $filters['status'] ?? null;

        /*
         | forUser() and hasLead() ride along on every query this page makes,
         | pending and completed rows alike: a telecaller sees their own table,
         | an admin sees the office's, and a soft-deleted lead takes its rows
         | off the calendar exactly as it takes them off the To-do page.
         */
        $scope = fn () => $this->applyFilters(
            Todo::forUser($user)
                ->hasLead()
                ->whereIn('status', ['pending', 'completed']),
            $filters,
        )->when($status, fn ($q, $s) => $this->applyStatus($q, $s));

        $events = $scope()
            ->whereBetween('scheduled_at', [$start, $end])
            ->with([
                'lead:id,first_name,middle_name,last_name,stage,project_id',
                'lead.project:id,name',
                'owner:id,first_name,last_name',
            ])
            ->orderBy('scheduled_at')
            ->get(['id', 'lead_id', 'assigned_to', 'scheduled_at', 'status', 'type', 'remarks'])
            ->map(fn (Todo $todo) => $this->event($todo))
            ->values()
            ->all();

        /*
         | The summary cards. Defined against TODAY, not against the month being
         | looked at — they are states a follow-up is in right now, which is the
         | definition the To-do page's badges and the report's pending buckets
         | use. The filters narrow them, so Project = Vanam counts Vanam's
         | follow-ups, and the active status narrows them too: the cards answer
         | "in the list you have filtered, how many are due today / still to
         | come / waiting already".
         */
        $summary = [
            'today' => $scope()->dueToday()->count(),
            'upcoming' => $scope()->upcoming()->count(),
            'overdue' => $scope()->overdue()->count(),
        ];

        return Inertia::render('Calendar/Index', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'events' => $events,
            'summary' => $summary,
            'filters' => $filters,
            'options' => $this->options($user),
        ]);
    }

    /**
     * The filters this page owns.
     *
     * `month` lives as much in the URL as in the session: the front end sends
     * it on every visit and leaves it in the address bar, so a refresh keeps
     * the month and the back and forward buttons walk the months. The other
     * keys are ordinary page filters — resolved, stored and sent the same way
     * every other page sends theirs.
     */
    private function filters(Request $request): array
    {
        return $this->resolveFilters(
            $request,
            'calendar',
            [
                'month' => ['sometimes', 'string', 'date_format:Y-m'],
                'assigned_to' => ['sometimes', 'integer', 'min:1'],
                'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
                'project_id' => ['sometimes', 'integer', 'min:1'],
                // every stage, active or not: filtering a list is reading
                'stage' => ['sometimes', 'string', Rule::in(CrmTaxonomy::stageKeys())],
            ],
            ['month' => now()->format('Y-m')],
        );
    }

    /**
     * @param  Builder  $query
     * @return Builder
     */
    private function applyFilters($query, array $filters)
    {
        return $query
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            // the todo has no project column of its own; it belongs to a lead, and the lead belongs to a project
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->whereHas('lead', fn ($w) => $w->where('project_id', $v)))
            ->when($filters['stage'] ?? null, fn ($q, $v) => $q->whereHas('lead', fn ($w) => $w->where('stage', $v)));
    }

    /**
     * The status filter, as a query clause.
     *
     * `overdue` is not a stored status — it is the Todo model's own state, so it
     * reuses the scope the To-do page and the reports use. Everything else maps
     * straight onto the column.
     */
    private function applyStatus($query, string $status)
    {
        return match ($status) {
            'completed' => $query->where('status', 'completed'),
            'overdue' => $query->overdue(),
            default => $query->where('status', 'pending'),
        };
    }

    /**
     * The wire shape of one follow-up.
     *
     * Only what the grid and the day panel need: the date it belongs on, the
     * state it is in (which decides its colour), and the four facts a row
     * might print. The lead's name and stage, the assignee's name and the
     * project's name are picked by their eager-loaded relations, so nothing
     * here demands a query per row. Nothing a user without the lead could not
     * open is sent.
     */
    private function event(Todo $todo): array
    {
        return [
            'id' => $todo->id,
            'date' => $todo->scheduled_at->format('Y-m-d'),
            'time' => $todo->scheduled_at->format('H:i'),
            'state' => $this->eventState($todo),
            'status' => $todo->status,
            'type' => $todo->type,
            'remark' => $todo->remarks,
            'lead' => [
                'id' => $todo->lead->id,
                'name' => $todo->lead->full_name,
                'stage' => $todo->lead->stage,
            ],
            'assignee' => $todo->owner
                ? ['id' => $todo->owner->id, 'name' => $todo->owner->display_name]
                : null,
            'project' => $todo->lead->project
                ? ['id' => $todo->lead->project->id, 'name' => $todo->lead->project->name]
                : null,
            // where the row opens — the lead view, which is this app's alone destination
            'url' => route('leads.show', $todo->lead->id),
        ];
    }

    /**
     * Where a follow-up stands, as the grid reads it.
     *
     * Completed is completed. A pending one is overdue if its day is behind
     * today, pending if it is today, upcoming if it is still ahead. The date
     * is the stored `scheduled_at` in the app timezone (Asia/Kolkata), which is
     * the exact date a person chose when the follow-up was booked.
     */
    private function eventState(Todo $todo): string
    {
        if ($todo->status === 'completed') {
            return 'completed';
        }

        $date = $todo->scheduled_at->toDateString();
        $today = today()->toDateString();

        if ($date < $today) {
            return 'overdue';
        }

        return $date === $today ? 'pending' : 'upcoming';
    }

    /**
     * The option lists the page renders, and nothing else.
     *
     * The assignee filter exists only for somebody who can see past their own
     * rows — a telecaller is the only person ever on their calendar, so an
     * "assign to" control would be a control that cannot change anything.
     * Sources and reasons ride along for LeadViewModal, the lead detail the
     * rows open into.
     */
    private function options(User $user): array
    {
        return [
            'users' => $user->isAdmin()
                ? User::whereIn('role', ['telecaller', 'salesperson'])
                    ->approved()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get(['id', 'first_name', 'last_name'])
                : [],
            // every project, active or not: a lead on a switched-off project
            // still has follow-ups somebody may want to narrow to
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'stages' => CrmTaxonomy::allStages(),
            // the active keys for CompleteTaskModal's stage dropdown, opened
            // from a follow-up row to log the call without leaving the page
            'activeStages' => CrmTaxonomy::activeStageKeys(),
            'stageColors' => CrmTaxonomy::stageColors(),
            'sources' => CrmTaxonomy::allSources(),
            'reasons' => config('crm.lost_reasons'),
            'types' => config('crm.todo_types'),
            'roleLabels' => config('crm.role_labels'),
            // which stages leave no pending task behind, and which one hands
            // the lead to a salesperson — CompleteTaskModal needs both to
            // decide whether to ask for a next follow-up and to stay quiet
            // about the handover clash it cannot yet name a person for
            'terminalStages' => CrmTaxonomy::terminalStages(),
            'handoverStage' => CrmTaxonomy::handoverStage(),
            'handoverRole' => CrmTaxonomy::ownerRoleFor(CrmTaxonomy::handoverStage()),
            // CallButtons builds its tel: and wa.me hrefs from this
            'countryCode' => config('crm.country_code'),
            // today in IST, so the grid and the browser agree on which cell is today
            'today' => today()->toDateString(),
        ];
    }
}
