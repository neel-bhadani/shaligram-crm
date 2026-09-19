<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Http\Requests\CompleteTodoRequest;
use App\Http\Requests\TodoRequest;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\LeadActivityRecorder;
use App\Services\LeadFollowUpService;
use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TodoController extends Controller
{
    use ResolvesDateRange, ResolvesFilters;

    /** The tab a visit lands on when nothing says otherwise. */
    private const DEFAULT_TAB = 'today';

    /** How many clashing follow-ups checkConflict() reads to pick the nearest. */
    private const CLASH_SCAN = 50;

    public function __construct(
        private LeadFollowUpService $service,
        private LeadActivityRecorder $activities,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $tab = $filters['tab'];

        [$from, $to] = $this->dateWindow($filters);

        /*
         | One base query, built once and read twice.
         |
         | The type chips answer "how would this list break down by type" —
         | this list, this tab, every other filter applied, and without the type
         | filter itself. Selecting Call must not leave the other three chips
         | reading zero.
         |
         | So the type clause is the one thing this closure leaves out: the page
         | of rows adds it, the counts do not. The tab is inside it, which is
         | the difference from the Leads page — a tab is not a view of one list,
         | it is a different list, so switching tabs has to recompute the chips.
         |
         | forUser() and hasLead() are inside it too, so a telecaller's chips
         | count a telecaller's tasks and a soft-deleted lead takes its rows out
         | of the counts exactly as it takes them out of the table.
         |
         | The date window is inside applyTab() rather than out here, because
         | which rows it may narrow is a property of the tab — see that method.
         */
        $base = fn () => $this->applyTab(
            Todo::forUser($user)
                // a deleted lead takes its rows off this page with it
                ->hasLead()
                ->when($filters['search'] ?? null, function ($q, $s) {
                    $q->whereHas('lead', function ($w) use ($s) {
                        $w->where('first_name', 'like', "%$s%")
                            ->orWhere('last_name', 'like', "%$s%")
                            ->orWhere('mobile_number', 'like', "%$s%");
                    });
                })
                ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v)),
            $tab,
            $from,
            $to
        );

        $rows = $base()
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->with([
                /*
                 | stage_changed_at and created_at are not shown here, but the
                 | Lead model appends days_in_stage, and that accessor reads
                 | them. Leaving them out of a constrained select does not
                 | throw — the attribute simply reads as null and the row ships
                 | a silent "unknown" instead of a number.
                 */
                /*
                 | assigned_to and assigned_role are here for CompleteTaskModal's
                 | clash check rather than for anything on screen: the next
                 | follow-up lands on the lead's owner, and the handover stage
                 | moves it to a salesperson instead — which is the one case the
                 | warning has to stay quiet about, because the receiving person
                 | is not known until the round robin runs on save.
                 */
                'lead:id,first_name,middle_name,last_name,mobile_number,stage,project_id,stage_changed_at,created_at,not_connected_count,assigned_to,assigned_role',
                'lead.project:id,name',
                // role too: the Handled by column stacks it under the name
                'owner:id,first_name,last_name,role',
                'completer:id,first_name,last_name',
            ]);

        $rows = $tab === 'completed'
            ? $rows->latest('completed_at')
            : $rows->orderBy('scheduled_at');

        return Inertia::render('Todos/Index', [
            // no withQueryString(): the filters are in the session now, so a
            // page link carries nothing but its page number
            'todos' => $rows->paginate(15),
            'tab' => $tab,
            /*
             | The tab badges, and they are deliberately not the chips. They
             | answer "how much is in each list" and take no filter at all, so
             | selecting a type chip cannot move them — the number on Completed
             | is the number of completed tasks, not the number of completed
             | calls.
             */
            'counts' => $this->counts($user),
            'types' => $this->typeCounts($base),
            'filters' => $this->withRangeWord($filters),
            'options' => [
                // every stage for the labels on the history rows, the
                // active keys for the stage dropdown in CompleteTaskModal
                'stages' => CrmTaxonomy::allStages(),
                'activeStages' => CrmTaxonomy::activeStageKeys(),
                'stageColors' => CrmTaxonomy::stageColors(),
                'types' => config('crm.todo_types'),
                // today in IST. The date inputs use this as their max rather
                // than the browser clock, which may be in another timezone.
                'today' => today()->toDateString(),
                'reasons' => config('crm.lost_reasons'),
                // CompleteTaskModal asks for the next follow-up on every call
                // that leaves the lead open; these two say which those are, and
                // which stage forces the next task to be the site visit
                'terminalStages' => CrmTaxonomy::terminalStages(),
                'handoverStage' => CrmTaxonomy::handoverStage(),
                // and the desk it hands leads to, so the clash warning knows
                // when the next task is about to change hands
                'handoverRole' => CrmTaxonomy::ownerRoleFor(CrmTaxonomy::handoverStage()),
                // CallButtons builds its tel: and wa.me hrefs from this
                'countryCode' => config('crm.country_code'),
                'roleLabels' => config('crm.role_labels'),
                // the "Switch project" action's target picker — every active
                // project, the same list the add-lead form offers
                'projects' => Project::active()->get(['id', 'name']),
                /*
                 | assigned_to rides along so TodoFormModal can ask whether the
                 | person this lead belongs to is already busy at the time being
                 | picked. It is not a control: TodoController::store() takes the
                 | owner from the lead itself and never from the form.
                 */
                'openLeads' => Lead::visibleTo($user)->open()
                    ->doesntHave('pendingTodo')
                    ->get(['id', 'first_name', 'last_name', 'mobile_number', 'assigned_to']),
                'users' => $user->isAdmin()
                    ? User::whereIn('role', ['telecaller', 'salesperson'])
                        ->approved()
                        ->get(['id', 'first_name', 'last_name'])
                    : [],
            ],
        ]);
    }

    /**
     * The filters this page owns. `tab` is one of them — it is a filter like
     * any other, and it is what the dashboard's follow-up panels pass when
     * they link across, so it has to be honoured on the way in even though
     * the front end wipes it off the address bar on the way out.
     */
    private function filters(Request $request): array
    {
        return $this->resolveFilters(
            $request,
            'todos',
            [
                'tab' => ['sometimes', 'string', 'in:overdue,today,upcoming,completed'],
                'search' => ['sometimes', 'string', 'max:100'],
                'type' => ['sometimes', 'string', Rule::in(array_keys(config('crm.todo_types')))],
                'assigned_to' => ['sometimes', 'integer', 'min:1'],
            ] + $this->dateRangeRules(),
            ['tab' => self::DEFAULT_TAB],
            fn (array $state) => $this->sanitiseDates($state),
        );
    }

    /**
     * The rows a tab is made of, and the one place the date range is allowed
     * near them. Ordering is not here: the chip counts run through this too,
     * and an ORDER BY on a GROUP BY is work for nothing.
     *
     * The three pending tabs are defined against TODAY, not against the picker:
     * overdue is scheduled before today, Today is scheduled today, Upcoming is
     * scheduled after it. They are states a follow-up is in right now, which is
     * why the date control is hidden on them — see Todos/Index.vue.
     *
     * Narrowing them by the picker as well would range-filter a question that
     * is not about a range: it is a no-op on Today, an arbitrary trim on
     * Overdue, and on Upcoming it is fatal — every range the filter bar
     * offers ends today, and nothing scheduled after today can also fall inside
     * a window that ends today, so the tab would read zero for every range
     * forever.
     *
     * Completed is the one that is genuinely about a period — "what got done
     * between these dates" — so it is the one the range applies to, on
     * completed_at. Filtering it on scheduled_at instead would count a call
     * planned inside the window and closed long after it, and filtering a
     * pending tab on completed_at would match nothing at all: the column is
     * null until the call is logged.
     *
     * This is ReportController::applyStatus() applied to the page that report
     * drills into, which is what keeps a row here and a row there the same row.
     */
    private function applyTab($query, string $tab, ?Carbon $from = null, ?Carbon $to = null)
    {
        return match ($tab) {
            'overdue' => $query->overdue(),
            'upcoming' => $query->upcoming(),
            'completed' => $query->where('status', 'completed')
                ->when($from, fn ($q) => $q->whereBetween('completed_at', [$from, $to])),
            default => $query->dueToday(),
        };
    }

    /**
     * The type breakdown of this tab, as one grouped query.
     *
     * Zero-filled across every configured type, so a type nobody has any of is
     * a chip reading 0 rather than a chip that is not there — a missing chip
     * reads as a bug, and the row would reflow every time a filter changed.
     *
     * The total is summed from the chips rather than counted again. It is the
     * "All" chip, and "All" disagreeing with the four beside it is the one
     * failure this feature cannot survive.
     *
     * @param  callable(): Builder  $base
     * @return array{total: int, bars: list<array{key: string, label: string, value: int}>}
     */
    private function typeCounts(callable $base): array
    {
        $counts = $base()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $bars = collect(config('crm.todo_types'))
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
            ])->values()->all();

        return ['total' => array_sum(array_column($bars, 'value')), 'bars' => $bars];
    }

    /**
     * Log the call: closes this task, moves the stage, saves the next task the
     * user booked on the same form — and switches the lead's project first,
     * in the same action, when the form's project selector was changed.
     *
     * CompleteTodoRequest has already checked a changed project is a real
     * one and does not clash with an existing lead there; null when the
     * selector was left on the lead's own project, which
     * LeadFollowUpService::complete() reads as no switch at all.
     */
    public function complete(CompleteTodoRequest $request, Todo $todo)
    {
        abort_if($todo->status !== 'pending', 422, 'This follow-up is already closed.');

        try {
            $outcome = $this->service->complete(
                todo: $todo,
                stage: $request->stage,
                remarks: $request->remarks,
                // used exactly as entered; nothing moves a datetime a person chose
                nextAt: $request->filled('follow_up_at')
                    ? Carbon::parse($request->follow_up_at)
                    : null,
                nextType: $request->input('follow_up_type'),
                nextRemarks: $request->input('follow_up_remarks'),
                extra: $request->only('reason', 'booked_unit', 'booking_date'),
                project: $request->filled('project_id') ? Project::find($request->input('project_id')) : null,
            );
        } catch (UniqueConstraintViolationException $e) {
            // the narrow gap between CompleteTodoRequest's check and the save
            // — see LeadController::switchProject() for the same race
            throw ValidationException::withMessages([
                'project_id' => 'This number already has a lead on that project.',
            ]);
        }

        $response = back()->with('success', CrmTaxonomy::isTerminal($request->stage)
            ? 'Call logged and the lead closed.'
            : 'Call logged and next follow-up scheduled.');

        // the service decided something on its own — say so
        $notice = $this->service->noticeFor($outcome);

        return $notice ? $response->with('warning', $notice) : $response;
    }

    /**
     * Does the person this follow-up is for already have one near this time?
     *
     * Advisory, and only advisory. Nothing here refuses anything: it is called
     * from the form while the user is still choosing, it answers with a
     * sentence or with nothing, and a save that goes ahead with a known clash
     * succeeds exactly as it would have done. There is no matching validation
     * rule anywhere and there must not be one — two follow-ups half an hour
     * apart are often deliberate, and the person booking them knows why.
     *
     * The window is one number in config/crm.php, either side of the chosen
     * time. Pending only: a completed or cancelled follow-up is not something
     * anybody is going to turn up for.
     *
     * `exclude_todo_id` is the follow-up being edited. Without it every
     * reschedule would report a clash with the row it is rescheduling.
     */
    public function checkConflict(Request $request)
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['required', 'date'],
            'exclude_todo_id' => ['nullable', 'integer'],
        ]);

        $at = Carbon::parse($data['scheduled_at']);
        $minutes = (int) config('crm.follow_up_clash_minutes');

        /*
         | hasLead(), because a follow-up on a soft-deleted lead is on no list
         | in the application — warning about a clash with something the user
         | cannot open would be a warning they can do nothing about.
         |
         | The index on (assigned_to, status, scheduled_at) covers this exactly.
         */
        $clashes = fn () => Todo::where('assigned_to', $data['assigned_to'])
            ->pending()
            ->hasLead()
            ->whereBetween('scheduled_at', [
                $at->copy()->subMinutes($minutes),
                $at->copy()->addMinutes($minutes),
            ])
            ->when(
                $data['exclude_todo_id'] ?? null,
                fn ($q, $id) => $q->where('id', '!=', $id)
            );

        $total = $clashes()->count();

        if ($total === 0) {
            return response()->json(['conflict' => null]);
        }

        /*
         | The nearest one is the one worth naming, and it is picked here rather
         | than in SQL: ordering by distance means ABS() over a datetime
         | difference, which is spelled differently in MySQL and SQLite and
         | would have the tests exercising a different query from production.
         |
         | The cap is a bound on a query whose window comes from the request. It
         | only decides WHICH of fifty simultaneous clashes gets named; the
         | count below it is exact either way, and fifty pending follow-ups
         | inside one hour for one person is already a data problem.
         */
        $nearest = $clashes()
            ->with(['lead:id,first_name,middle_name,last_name,assigned_to', 'owner:id,first_name,last_name'])
            ->orderBy('scheduled_at')
            ->limit(self::CLASH_SCAN)
            ->get()
            ->sortBy(fn (Todo $t) => abs($t->scheduled_at->diffInSeconds($at)))
            ->first();

        return response()->json(['conflict' => [
            'message' => $this->clashSentence($request->user(), $nearest, $total - 1),
            'count' => $total,
        ]]);
    }

    /**
     * The warning, in one sentence.
     *
     * "Priya Shah already has a call with Meera Vaghela at 3:20 PM." Who, what,
     * which customer and when — a bare "you have a conflict" tells the user
     * nothing they can act on, and acting on it is the only reason this is
     * shown before saving rather than after.
     *
     * The customer's name is the one part that is conditional. It is printed
     * only if this user could open that lead anyway, on the same policy check
     * LeadController::checkDuplicate() uses — otherwise a telecaller would
     * learn a salesperson's client names by picking times until one collided.
     * They still get the time and the type, which is what they need in order to
     * choose a different slot.
     */
    private function clashSentence(User $user, Todo $todo, int $others): string
    {
        $person = $todo->owner?->display_name ?? 'This user';
        $type = $this->typeWord($todo->type);
        $time = $todo->scheduled_at->format('g:i A');

        $sentence = $user->can('view', $todo->lead)
            ? "{$person} already has {$type} with {$todo->lead->full_name} at {$time}"
            : "{$person} already has {$type} at {$time}";

        if ($others > 0) {
            $sentence .= $others === 1 ? ', and 1 other' : ", and {$others} others";
        }

        return $sentence.'.';
    }

    /**
     * A to-do type as it reads mid-sentence, with its article: "a call",
     * "a site visit", "a WhatsApp".
     *
     * Lower-cased, except for a label that carries a capital of its own past
     * the first letter — "WhatsApp" is a name and "a whatsapp" is a typo. The
     * article is chosen rather than hard-coded so that a type added to
     * config('crm.todo_types') later does not read as "a email".
     */
    private function typeWord(string $type): string
    {
        $label = (string) config("crm.todo_types.$type", $type);

        $word = preg_match('/\p{Lu}.*\p{Lu}/u', $label) ? $label : Str::lower($label);

        return (in_array(Str::lower($word[0] ?? ''), ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ').$word;
    }

    public function store(TodoRequest $request)
    {
        $lead = Lead::findOrFail($request->lead_id);

        abort_unless(
            $request->user()->isAdmin() || $lead->assigned_to === $request->user()->id,
            403
        );

        DB::transaction(function () use ($request, $lead) {
            $todo = Todo::create([
                'lead_id' => $lead->id,
                'assigned_to' => $lead->assigned_to,
                'created_by' => $request->user()->id,
                'scheduled_at' => $request->scheduled_at,
                'type' => $request->type,
                'status' => 'pending',
                'remarks' => $request->remarks,
            ]);

            $this->activities->followUp($todo, $request->user()->id, onItsOwn: true);
        });

        return back()->with('success', 'Follow-up added.');
    }

    public function update(TodoRequest $request, Todo $todo)
    {
        abort_if($todo->status !== 'pending', 422, 'Completed follow-ups cannot be edited.');

        abort_unless(
            $request->user()->isAdmin() || $todo->assigned_to === $request->user()->id,
            403
        );

        DB::transaction(function () use ($request, $todo) {
            // read before update() overwrites it — the entry below has to
            // name the date this follow-up is actually moving FROM, not the
            // date it is about to land on
            $from = $todo->scheduled_at;

            $todo->update($request->only('scheduled_at', 'type', 'remarks'));

            /*
             | Its own entry, only when the date/time actually moved. A save
             | that only touched the type or the remarks is not a reschedule,
             | and stamping one on it would print the same date twice —
             | "rescheduled 20 Sep -> 20 Sep" — for an edit that never
             | changed when the follow-up is due.
             */
            if ($todo->wasChanged('scheduled_at')) {
                $this->activities->followUpRescheduled($todo, $request->user()->id, $from, $todo->scheduled_at);
            }
        });

        return back()->with('success', 'Follow-up rescheduled.');
    }

    public function destroy(Request $request, Todo $todo)
    {
        abort_unless($request->user()->isAdmin(), 403);

        DB::transaction(function () use ($request, $todo) {
            $this->activities->followUpCancelled($todo, $request->user()->id);

            $todo->update(['status' => 'cancelled']);
        });

        return back()->with('success', 'Follow-up cancelled.');
    }

    private function counts($user): array
    {
        // hasLead() on every one of them: a badge that counted rows the tab
        // does not render would be worse than the crash it replaced
        return [
            'overdue' => Todo::forUser($user)->hasLead()->overdue()->count(),
            'today' => Todo::forUser($user)->hasLead()->dueToday()->count(),
            'upcoming' => Todo::forUser($user)->hasLead()->upcoming()->count(),
            'completed' => Todo::forUser($user)->hasLead()->where('status', 'completed')->count(),
        ];
    }
}
