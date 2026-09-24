<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Http\Requests\ProjectRequest;
use App\Http\Requests\ProjectSalespeopleRequest;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Services\AlertService;
use App\Support\CrmTaxonomy;
use App\Support\RecordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The developments this company is selling. Admin only.
 *
 * Every route is behind `role:admin` on the group in routes/web.php — the
 * sidebar link is hidden for everyone else too, but that is presentation; the
 * middleware is the refusal, and a telecaller typing /projects gets a 403
 * rather than an empty page.
 *
 * ---------------------------------------------------------------------------
 * How the numbers are counted
 * ---------------------------------------------------------------------------
 *
 * The same three rules the dashboard and the reports use, because a project's
 * booking count that disagreed with the dashboard's would make both useless:
 *
 *   LEADS are counted on `leads` rows — one row, one lead. Where a range
 *   applies elsewhere it applies to `leads.created_at`; there is no range on
 *   this screen, so this is every lead ever filed against the project.
 *
 *   SITE VISITS AND BOOKINGS are events, and events live in `todos`: a
 *   completed to-do whose `outcome_stage` records where the lead went. Never
 *   `leads.stage` — a lead that visited the site in March and was lost in June
 *   still visited the site. `completed_at` must be set, because only a
 *   completed row is a thing that happened.
 *
 *   DISTINCT BY LEAD, always. A lead can reach the same stage twice — booked,
 *   cancelled, booked again — and that is still one lead that got there.
 *
 * The by-stage breakdown is the one figure that is deliberately NOT an event
 * count. It answers "where do this project's leads stand right now", which is a
 * question about `leads.stage`, and it is labelled that way on screen. Mixing
 * the two is how a transition count ends up under a heading about enquiries.
 *
 * Every percentage is guarded against a zero denominator and reports null,
 * which the page draws as an em dash. A project with no leads has no
 * conversion rate; it does not have a conversion rate of 0%.
 */
class ProjectController extends Controller
{
    use ResolvesFilters;

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $user = $request->user();

        $projects = Project::query()
            ->tap(fn (Builder $q) => RecordSearch::apply($q, $filters['search'] ?? null, ['name'], ['name']))
            ->when(
                // 'all' is absence; the two real values are the strings a
                // <select> sends, exactly as the Users page does it
                isset($filters['status']),
                fn ($q) => $q->where('is_active', $filters['status'] === 'active')
            )
            ->withCount([
                'leads',
                /*
                 | Bookings as a constrained count of LEADS rather than of
                 | to-dos, which is what makes it distinct by lead for free: one
                 | row per lead, kept if that lead has ever completed a to-do
                 | recording a booking. Counting the to-dos instead would count
                 | a lead that booked twice as two bookings.
                 */
                'leads as bookings_count' => fn ($q) => $q->whereHas(
                    'todos',
                    fn ($t) => $t->where('outcome_stage', 'booking_done')->whereNotNull('completed_at')
                ),
                /*
                 | Leads including the ones in the bin, and this one is not for
                 | display — it is the exact test destroy() applies, so the
                 | button's state and the server's answer cannot disagree.
                 |
                 | They would otherwise. `leads_count` above excludes trashed
                 | leads, so a project whose leads had all been deleted would
                 | show 0 and offer a Delete button that the server then
                 | refused. A soft-deleted lead is restorable and would come
                 | back to a project that was no longer there.
                 */
                'leads as all_leads_count' => fn ($q) => $q->withTrashed(),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(15)->appends(['reset' => 1] + $filters)
            ->through(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'location' => $p->location,
                'type' => $p->type,
                'type_label' => $p->type_label,
                'description' => $p->description,
                'is_active' => $p->is_active,
                'leads_count' => $p->leads_count,
                'bookings_count' => $p->bookings_count,
                /*
                 | Whether Delete is even offered, on the same count destroy()
                 | applies — trashed leads included. A project with leads is not
                 | deletable at any price, and the button is disabled with the
                 | reason on it rather than failing on click. The server refuses
                 | regardless; this is the explanation, not the guarantee.
                 */
                'deletable' => $p->all_leads_count === 0,
            ]);

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => $filters,
            'options' => [
                'types' => config('crm.project_types'),
            ],
        ]);
    }

    /** The filters this page owns. No defaults: the whole list is the start. */
    private function filters(Request $request): array
    {
        return $this->resolveFilters(
            $request,
            'projects',
            [
                'search' => ['sometimes', 'string', 'max:100'],
                'status' => ['sometimes', 'string', 'in:active,inactive'],
            ],
        );
    }

    /**
     * One project, and what has actually happened on it.
     *
     * `visibleTo` is applied to every count on this page. An admin resolves
     * `see_all_leads`, so today it changes nothing at all — and that is exactly
     * why it is written down now rather than remembered later, on the day
     * somebody opens this screen up to a sales manager.
     */
    public function show(Request $request, Project $project)
    {
        $user = $request->user();

        $total = Lead::visibleTo($user)->where('project_id', $project->id)->count();
        $events = $this->stageEvents($user, $project);
        $booked = $events['booking_done'];

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'location' => $project->location,
                'type' => $project->type,
                'type_label' => $project->type_label,
                'description' => $project->description,
                'is_active' => $project->is_active,
                'created_at' => $project->created_at?->toIso8601String(),
                'creator' => $project->creator?->display_name,
            ],

            'totals' => [
                'leads' => $total,
                'visits' => $events['site_visit_done'],
                'booked' => $booked,
                /*
                 | Bookings ÷ every lead ever filed against this project. Both
                 | halves are all-time, so the two are the same population and
                 | the numerator is a subset of the denominator — it cannot
                 | exceed 100% and needs no clamping.
                 |
                 | Null, never zero, when there is nothing to divide by. The
                 | page draws an em dash. "0%" about a project with no leads is
                 | a claim that nobody bought, which is not what happened.
                 */
                'conversion' => $total > 0 ? round($booked / $total * 100, 1) : null,
                'lost' => $events['lost'],
            ],

            'byStage' => $this->byStage($user, $project, $total),
            'bySource' => $this->bySource($user, $project, $total),
            'leads' => $this->recentLeads($user, $project),
            'salespeople' => $this->salespeople($project),

            'options' => [
                'types' => config('crm.project_types'),
                'stageColors' => CrmTaxonomy::stageColors(),
                // how many of the leads list is shown before the drill-through
                'leadPreview' => self::LEAD_PREVIEW,
            ],
        ]);
    }

    /** How many leads the detail page lists before handing over to /leads. */
    private const LEAD_PREVIEW = 10;

    /**
     * What has HAPPENED on this project, from the to-do history.
     *
     * One query read three times rather than three queries that must agree —
     * the same shape as DashboardController::stageEvents(), and for the same
     * reason: three queries that must stay identical is a promise, one query
     * read three times is a fact.
     *
     * `whereHas('lead')` runs the relation's own query, so Lead's soft-delete
     * scope comes with it and a trashed lead takes its history off this page,
     * exactly as it does off the dashboard.
     *
     * @return array<string, int> zero-filled, keyed by stage
     */
    private function stageEvents(User $user, Project $project): array
    {
        $counts = Todo::whereHas('lead', fn ($q) => $q
            ->visibleTo($user)
            ->where('project_id', $project->id))
            ->whereNotNull('outcome_stage')
            ->whereNotNull('completed_at')
            ->selectRaw('outcome_stage, count(distinct lead_id) as total')
            ->groupBy('outcome_stage')
            ->pluck('total', 'outcome_stage');

        return collect(CrmTaxonomy::allStages())
            ->map(fn ($label, $key) => (int) ($counts[$key] ?? 0))
            ->all();
    }

    /**
     * Where this project's leads STAND, right now.
     *
     * `leads.stage`, not the to-do history — this is the pipeline as it is
     * today, and every lead appears in exactly one row. It sums to the lead
     * total, which the event counts above deliberately do not.
     *
     * Zero-filled and in the admin's stage order, so the bars do not reorder
     * themselves as the numbers change.
     */
    private function byStage(User $user, Project $project, int $total): array
    {
        $counts = Lead::visibleTo($user)
            ->where('project_id', $project->id)
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        return collect(CrmTaxonomy::stageUniverse($counts->keys()))
            ->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'total' => (int) ($counts[$key] ?? 0),
                'share' => $total > 0 ? round(((int) ($counts[$key] ?? 0)) / $total * 100, 1) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Where this project's leads came FROM.
     *
     * Sources with nothing against them are dropped rather than drawn as empty
     * rows: eight sources of which two are used reads as six failures. The
     * stage breakdown keeps its zeros because a pipeline with a hole in it is
     * information; a source nobody used is not.
     */
    private function bySource(User $user, Project $project, int $total): array
    {
        return Lead::visibleTo($user)
            ->where('project_id', $project->id)
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source')
            ->map(fn ($count, $key) => [
                'key' => $key,
                'label' => CrmTaxonomy::sourceLabel((string) $key),
                'total' => (int) $count,
                'share' => $total > 0 ? round(((int) $count) / $total * 100, 1) : null,
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * The first few leads, as a taste. The drill-through is the real list.
     *
     * Newest first, which is the order somebody scanning a project actually
     * wants, and capped — this panel is a sample with a link under it, not a
     * second Leads page that would need its own filters, sorting and paging.
     *
     * `id` as the tiebreaker, and it is not decoration. Leads arrive in batches
     * — the Meta webhook can create several within one second — and
     * `created_at` alone leaves those tied, at which point the database returns
     * them in whatever order it likes. MySQL and SQLite disagree, which is how
     * this was found; worse, with a LIMIT on top, an arbitrary order means the
     * panel can miss the genuinely newest lead. `id` is monotonic, so ties fall
     * back to the order the rows were actually written.
     */
    private function recentLeads(User $user, Project $project): array
    {
        return Lead::visibleTo($user)
            ->where('project_id', $project->id)
            ->with(['owner:id,first_name,last_name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::LEAD_PREVIEW)
            ->get([
                'id', 'first_name', 'middle_name', 'last_name', 'mobile_number',
                'stage', 'source', 'assigned_to', 'created_at', 'stage_changed_at',
            ])
            ->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->full_name,
                'mobile' => $lead->mobile_number,
                'stage' => $lead->stage,
                'stageLabel' => CrmTaxonomy::stageLabel($lead->stage),
                'source' => CrmTaxonomy::sourceLabel($lead->source),
                'owner' => $lead->owner?->display_name,
                'created_at' => $lead->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Every active salesperson, and whether they are ticked for this project.
     *
     * Only the active ones, because only they can take a turn. Somebody
     * switched off keeps their pivot row — see updateSalespeople() — and simply
     * is not listed until they are switched back on.
     *
     * @return list<array{id: int, name: string, assigned: bool}>
     */
    private function salespeople(Project $project): array
    {
        $assigned = $project->salespeople()->pluck('users.id')->all();

        return User::active()
            ->where('role', 'salesperson')
            ->orderBy('first_name')
            ->orderBy('last_name')
            // no 'name': the column was dropped for first_name/last_name, and
            // SQLite would quietly select the string literal where MySQL throws
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->display_name,
                'assigned' => in_array($u->id, $assigned, true),
            ])
            ->all();
    }

    /* ---------------- writes ---------------- */

    /**
     * Save who handles this project — the people its salesperson round robin
     * takes turns among. See LeadAssignmentService.
     *
     * Not a plain sync(). The page lists active salespeople only, so a sync
     * would detach everybody it does not list: a salesperson switched off for
     * a fortnight would come back to find every project they handle had
     * forgotten them the first time an admin saved one. Only the people the
     * page actually showed unticked are removed.
     *
     * Ticking somebody answers the "no salesperson is assigned" alert, so it is
     * cleared from every admin's bell the way an answered sign-up is.
     */
    public function updateSalespeople(ProjectSalespeopleRequest $request, Project $project, AlertService $alerts)
    {
        $ticked = collect($request->validated('salesperson_ids'))->map(fn ($id) => (int) $id);
        $unticked = User::active()->where('role', 'salesperson')->pluck('id')->diff($ticked);

        DB::transaction(function () use ($project, $ticked, $unticked) {
            if ($unticked->isNotEmpty()) {
                $project->salespeople()->detach($unticked->all());
            }

            $project->salespeople()->syncWithoutDetaching($ticked->all());
        });

        if ($ticked->isEmpty()) {
            return back()->with('warning', "Nobody is assigned to \"{$project->name}\" now. Its leads that need a "
                .'salesperson will go to any active salesperson, and admins will be alerted when that happens.');
        }

        $alerts->resolve('project_without_salespeople.'.$project->id);

        return back()->with('success', "Salespeople for \"{$project->name}\" saved.");
    }

    public function store(ProjectRequest $request)
    {
        Project::create($request->projectAttributes() + [
            // stamped once, here, and never again — see projectAttributes()
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Project added.');
    }

    public function update(ProjectRequest $request, Project $project)
    {
        $wasActive = $project->is_active;

        $project->update($request->projectAttributes());

        /*
         | Say what deactivating actually did, because the admin cannot see it
         | from the page they land on: the project vanishes from the add-lead
         | dropdown, and nothing else changes. Every existing lead, follow-up
         | and report is untouched, which is the whole reason to prefer this
         | over deleting.
         */
        if ($wasActive && ! $project->is_active) {
            return back()
                ->with('success', 'Project updated.')
                ->with('warning', "\"{$project->name}\" is now inactive. It has been removed from the "
                    .'Add lead form; its existing leads and history are unchanged.');
        }

        return back()->with('success', 'Project updated.');
    }

    /**
     * Soft delete, and only for a project nobody has ever used.
     *
     * `leads.project_id` is a foreign key with cascadeOnDelete, so a hard
     * DELETE here would take every lead filed against this project and every
     * follow-up hanging off those leads. Project uses SoftDeletes, so
     * `$project->delete()` writes `deleted_at` and the cascade never fires —
     * but that is the second line of defence, not the first.
     *
     * The first is this refusal. A project with leads is not deletable at any
     * price, because "delete" is not what the admin wants in that situation:
     * they want to stop new leads being filed against it, which is what
     * deactivating does, with every lead, chart and report left intact. Saying
     * so is more useful than a confirmation dialog.
     *
     * withTrashed(), and that matters. A soft-deleted LEAD still points at this
     * project and can be restored; a project that looked empty only because its
     * leads were in the bin would come back to a lead with no project.
     */
    public function destroy(Project $project)
    {
        $leads = $project->leads()->withTrashed()->count();

        if ($leads > 0) {
            return back()->with('error',
                "\"{$project->name}\" has {$leads} lead".($leads === 1 ? '' : 's')
                .' filed against it and cannot be deleted — deleting it would take those leads '
                .'and their entire follow-up history with it. Switch the project off instead: '
                .'that removes it from the Add lead form and keeps all of the history.');
        }

        $name = $project->name;

        // soft delete: the row stays, so the cascade on leads.project_id never
        // fires and this is reversible in the database
        $project->delete();

        return back()->with('success', "\"{$name}\" deleted. It had no leads, so nothing was lost.");
    }
}
