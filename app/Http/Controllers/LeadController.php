<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Http\Requests\LeadReassignRequest;
use App\Http\Requests\LeadRequest;
use App\Http\Requests\LeadSwitchProjectRequest;
use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadActivityRecorder;
use App\Services\LeadAssignmentService;
use App\Services\LeadCreationService;
use App\Services\LeadFollowUpService;
use App\Services\LeadTimeline;
use App\Support\CrmTaxonomy;
use App\Support\RecordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LeadController extends Controller
{
    use ResolvesDateRange, ResolvesFilters;

    /** The three fields that book a follow-up; they are not lead columns. */
    private const FOLLOW_UP_FIELDS = ['follow_up_type', 'follow_up_at', 'follow_up_remarks'];

    public function __construct(
        private LeadFollowUpService $service,
        private LeadAssignmentService $assignment,
        private LeadActivityRecorder $activities,
        private LeadTimeline $timeline,
        private LeadCreationService $creation,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $this->filters($request);

        [$from, $to] = $this->dateWindow($filters);

        /*
         | One base query, built once and read twice.
         |
         | The stage chips have to answer "how would this list break down by
         | stage" — which is the list the user is looking at, every filter
         | applied, except the stage filter itself. Selecting "Lost" must not
         | make the other nine chips read zero.
         |
         | So the stage clause is the one thing this closure leaves out: the
         | page of rows adds it, the counts do not. A closure rather than a
         | clone, so the two cannot be the same query "by inspection" — they
         | are the same query because they come from the same expression.
         |
         | visibleTo() is inside it, which is what keeps a telecaller's chips
         | counting a telecaller's leads; the model's soft-delete scope comes
         | along for the same ride.
         */
        $base = fn () => Lead::visibleTo($user)
            ->tap(fn (Builder $q) => RecordSearch::apply($q, $filters['search'] ?? null,
                ['first_name', 'middle_name', 'last_name', 'mobile_number', 'email'],
                ['first_name', 'middle_name', 'last_name'], ['mobile_number']))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            // where the leads report's "By channel partner" rows drill through to
            ->when($filters['channel_partner_id'] ?? null, fn ($q, $v) => $q->where('channel_partner_id', $v))
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            // one clause, both bounds, on real datetimes rather than DATE() —
            // see dateWindow() for why the boundaries are built where they are
            ->when($from, fn ($q) => $q->whereBetween('created_at', [$from, $to]));

        $leads = $base()
            ->when($filters['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v))
            // eager load or a 25-row page fires 50 extra queries
            ->with([
                'project:id,name',
                // role too: the Assigned to column stacks it under the name
                'owner:id,first_name,last_name,role',
                'pendingTodo:id,lead_id,scheduled_at,type',
                /*
                 | The Source column's sub-line. `parent` comes with it because
                 | ChannelPartner appends display_label, which reads it — a
                 | broker under a firm has to arrive as "Ravi Kumar — Shreeji
                 | Realty" or two Ravis are one name on this page.
                 |
                 | A lead with no partner sends null and the column falls back
                 | to `broker_name`, which is where every pre-existing broker
                 | lead's answer still lives.
                 */
                'channelPartner:id,name,parent_id',
                'channelPartner.parent:id,name',
            ])
            ->latest()
            // Carry this view’s filters so another tab cannot change pagination results.
            ->paginate(15)->appends(['reset' => 1] + $filters);

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'stageCounts' => $this->stageCounts($base),
            'filters' => $this->withRangeWord($filters),
            'options' => $this->options($user),
        ]);
    }

    /**
     * The stage breakdown of the list, as one grouped query.
     *
     * Zero-filled across every configured stage, so a stage nobody is sitting
     * in is a chip reading 0 rather than a chip that is not there — a missing
     * chip reads as a bug, and the row of them would reflow every time a filter
     * changed.
     *
     * The total is summed from the chips rather than counted again. It is the
     * "All" chip, and "All" disagreeing with the nine beside it is the one
     * failure this feature cannot survive.
     *
     * @param  callable(): Builder  $base
     * @return array{total: int, bars: list<array{key: string, label: string, value: int}>}
     */
    private function stageCounts(callable $base): array
    {
        $counts = $base()
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        // active stages in the admin's order, plus any retired one leads are
        // still standing in — the total under these chips is summed from them
        $bars = collect(CrmTaxonomy::stageUniverse($counts->keys()))
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'value' => (int) ($counts[$key] ?? 0),
            ])->values()->all();

        return ['total' => array_sum(array_column($bars, 'value')), 'bars' => $bars];
    }

    /**
     * The filters this page owns. Leads have no default — an unfiltered list
     * is the starting point — so a missing key simply means "do not filter",
     * and that is what All time and the All chip are.
     */
    private function filters(Request $request): array
    {
        return $this->resolveFilters(
            $request,
            'leads',
            [
                'search' => ['sometimes', 'string', 'max:100'],
                // every key, not only the active ones: filtering a list is
                // reading, and a lead filed under a retired stage is still a
                // lead somebody may want to narrow to
                'stage' => ['sometimes', 'string', Rule::in(CrmTaxonomy::stageKeys())],
                'project_id' => ['sometimes', 'integer', 'min:1'],
                'source' => ['sometimes', 'string', Rule::in(CrmTaxonomy::sourceKeys())],
                'channel_partner_id' => ['sometimes', 'integer', 'min:1'],
                'assigned_to' => ['sometimes', 'integer', 'min:1'],
            ] + $this->dateRangeRules(),
            [],
            fn (array $state) => $this->sanitiseDates($state),
        );
    }

    public function store(LeadRequest $request)
    {
        $user = $request->user();

        /*
         | Both writes or neither. onLeadCreated() gives the lead the first
         | to-do the user booked on the form, and "an open lead always has one"
         | is an invariant the whole To-do page leans on — a lead that committed
         | while its to-do failed would break it for good, and nothing in the
         | application would notice.
         */
        try {
            $this->creation->create(
                $this->leadAttributes($request), $user, $user,
                $this->followUpAt($request),
                $request->input('follow_up_type'),
                $request->input('follow_up_remarks'),
            );
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicateMobile();
        }

        return back()->with('success', CrmTaxonomy::isTerminal($request->stage)
            ? 'Lead added.'
            : 'Lead added and follow-up scheduled.');
    }

    public function show(Request $request, Lead $lead)
    {
        // LeadPolicy::view() — the same ownership rule visibleTo() applies to
        // the list, said for one row, and `see_all_leads` answers it for a
        // manager as well as an admin
        $this->authorize('view', $lead);

        return response()->json([
            'lead' => $lead->load([
                'project:id,name,location',
                'owner:id,first_name,last_name',
                // and its firm, which display_label reads
                'channelPartner:id,name,parent_id',
                'channelPartner.parent:id,name',
                'pendingTodo',
                'completedTodos.completer:id,first_name,last_name',
            ]),
            // beside `lead`, never inside it: `lead` is exactly what it was
            // before the timeline existed, and every key the modal reads from
            // it is where it always was
            'timeline' => $this->timeline->for($lead),
            'reassignCandidates' => $this->reassignCandidates($request->user(), $lead),
        ]);
    }

    /**
     * Who this lead could be manually reassigned to, by the role each stage
     * in the pipeline is worked by: active users of that role, minus the
     * current owner. Keyed by role rather than fixed to the lead's own
     * stage, because the reassign form lets a stage and a person be picked
     * together — moving a telecaller-stage lead onto a salesperson stage has
     * to offer salespeople without a second round trip to the server.
     *
     * The lead's own current role rides along too (via $lead->assigned_role),
     * so a terminal lead — whose stage answers no role at all — still offers
     * somebody to hand it to.
     *
     * A salesperson role is narrowed to the lead's own project for anybody
     * without `see_all_leads` — telecallers are a single company-wide desk
     * and are never narrowed, and admin is exempt from the project boundary
     * entirely, the same rule LeadReassignRequest checks on the way back in.
     *
     * @return array<string, list<array{id: int, name: string}>>
     */
    private function reassignCandidates(User $user, Lead $lead): array
    {
        return collect(CrmTaxonomy::stageRows())
            ->pluck('owner_role')
            ->push($lead->assigned_role)
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $role) => [
                $role => $this->reassignCandidatesForRole($role, $user, $lead),
            ])
            ->all();
    }

    /** @return list<array{id: int, name: string}> */
    private function reassignCandidatesForRole(string $role, User $user, Lead $lead): array
    {
        $query = $role === 'salesperson' && ! $user->can_('see_all_leads')
            ? $lead->project->salespeople()
            : User::query()->where('role', $role);

        return $query->active()
            ->where('users.id', '!=', $lead->assigned_to)
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->display_name])
            ->values()
            ->all();
    }

    public function update(LeadRequest $request, Lead $lead)
    {
        // LeadRequest::authorize() already ran LeadPolicy::update() on this
        // same bound lead, so there is nothing left to check here

        $data = $this->leadAttributes($request);
        $stage = $data['stage'];
        unset($data['stage']);

        /*
         | The edit and its timeline rows commit together or not at all. This
         | transaction holds exactly what the bare update() used to write and
         | nothing more — the stage change below still commits on its own, as
         | it always has.
         |
         | The unit of a booking, or the reason for a loss, made on this same
         | save belongs to that stage change's entry rather than being a
         | separate edit: changeStage() records it from the saved lead.
         */
        try {
            DB::transaction(function () use ($lead, $data, $stage, $request) {
                $lead->update($data);

                $this->activities->edits(
                    $lead,
                    $request->user()->id,
                    except: $lead->stage === $stage ? [] : match ($stage) {
                        'booking_done' => ['booked_unit'],
                        'lost' => ['reason'],
                        default => [],
                    },
                );
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicateMobile();
        }

        // route every stage change through the service so history
        // and the next task stay consistent
        $notice = null;

        if ($lead->stage !== $stage) {
            $notice = $this->service->noticeFor(
                $this->service->changeStage(
                    $lead,
                    $stage,
                    [
                        'reason' => $request->input('reason'),
                        'booked_unit' => $request->input('booked_unit'),
                    ],
                    $this->followUpAt($request),
                    $request->input('follow_up_type'),
                    $request->input('follow_up_remarks'),
                )
            );
        }

        $response = back()->with('success', 'Lead updated.');

        // the service decided something on its own — say so
        return $notice ? $response->with('warning', $notice) : $response;
    }

    /**
     * Move a lead to a specific person by hand, optionally onto a different
     * stage in the same action. LeadReassignRequest has already checked the
     * role the target stage is worked by, the project boundary and that this
     * is actually a change; LeadFollowUpService::reassignTo() does the rest —
     * moves the pending to-do, writes the activity rows, and never touches
     * the round robin, because the person is the one this form asked for.
     */
    public function reassign(LeadReassignRequest $request, Lead $lead)
    {
        $to = User::findOrFail($request->validated('assigned_to'));

        $this->service->reassignTo($lead, $to, $request->validated('stage'));

        return back()->with('success', "Lead reassigned to {$to->display_name}.");
    }

    /**
     * Move a lead to a different project — the Follow-up page's "Switch
     * project" action. LeadSwitchProjectRequest has already checked the
     * target project is a real change and does not clash with an existing
     * lead there; LeadFollowUpService::switchProject() does the rest — moves
     * the lead itself, re-runs the stage-to-role assignment against the new
     * project, and moves the pending to-do if the owner changes.
     */
    public function switchProject(LeadSwitchProjectRequest $request, Lead $lead)
    {
        $to = Project::findOrFail($request->validated('project_id'));

        try {
            $outcome = $this->service->switchProject($lead, $to);
        } catch (UniqueConstraintViolationException $e) {
            // the narrow gap between LeadSwitchProjectRequest's check and the
            // save — see duplicateMobile() for the same race on the add-lead
            // form
            throw ValidationException::withMessages([
                'project_id' => 'This number already has a lead on that project.',
            ]);
        }

        $notice = "Lead switched to {$outcome['switched_to']}.";

        if ($outcome['reassigned_to']) {
            $notice .= " Reassigned to {$outcome['reassigned_to']}.";
        }

        return back()->with('success', $notice);
    }

    public function destroy(Lead $lead)
    {
        // was route middleware saying `role:admin`; it is a permission now, so
        // an admin who grants `delete_leads` to a manager gets what they asked
        // for rather than a toggle that does nothing
        $this->authorize('delete', $lead);

        $lead->todos()->where('status', 'pending')->update(['status' => 'cancelled']);
        $lead->delete();

        return back()->with('success', 'Lead deleted.');
    }

    /**
     * The lead's own columns, out of a payload that also carries the follow-up
     * this form books alongside it.
     *
     * The one thing normalised here is the partner link. A lead whose source is
     * not `broker` did not come through one, so it holds no
     * `channel_partner_id` — the modal clears the field when the source moves
     * off broker, and this is what makes that true of a stale tab as well.
     *
     * `broker_name` is deliberately absent from both ends of this. It is not in
     * validated() — see LeadRequest — so it is not written, not cleared and not
     * touched: the text on a lead from before channel partners existed survives
     * every edit made after them.
     *
     * @return array<string, mixed>
     */
    private function leadAttributes(LeadRequest $request): array
    {
        $data = Arr::except($request->validated(), self::FOLLOW_UP_FIELDS);

        if (($data['source'] ?? null) !== 'broker') {
            $data['channel_partner_id'] = null;
        }

        return $data;
    }

    /**
     * The follow-up datetime the user typed, as a Carbon in the app timezone,
     * or null when the form did not ask for one.
     *
     * Parsed and never adjusted. It is a moment a person chose, and there is
     * nothing left in the application that would move it.
     */
    private function followUpAt(Request $request): ?Carbon
    {
        return $request->filled('follow_up_at')
            ? Carbon::parse($request->input('follow_up_at'))
            : null;
    }

    /**
     * The unique index is the real guarantee, and LeadRequest checks the same
     * rows it does — so reaching here means the narrow gap between that check
     * and the insert: two people adding the same number at the same moment.
     * The index wins, and the user gets the message they should have seen
     * rather than a 500.
     */
    private function duplicateMobile(): ValidationException
    {
        return ValidationException::withMessages([
            'mobile_number' => 'This number already exists for this project.',
        ]);
    }

    /**
     * Live duplicate check as the user types.
     * The unique index is the real guarantee; this is only for a friendly message.
     */
    public function checkDuplicate(Request $request)
    {
        $request->validate([
            'mobile_number' => ['required', 'digits:10'],
            'project_id' => ['required', 'exists:projects,id'],
        ]);

        // withTrashed(), because the index counts deleted rows and so does the
        // rule in LeadRequest — a check that said "free" here and then failed
        // on submit is what made this look like a random 500
        $lead = Lead::withTrashed()
            ->where('mobile_number', $request->mobile_number)
            ->where('project_id', $request->project_id)
            ->when($request->lead_id, fn ($q, $id) => $q->where('id', '!=', $id))
            ->with('owner:id,first_name,last_name')
            ->first();

        if (! $lead) {
            return response()->json(['exists' => false]);
        }

        if ($lead->trashed()) {
            return response()->json([
                'exists' => true,
                'message' => 'This number belongs to a deleted lead on this project. Restore that lead instead of adding it again.',
            ]);
        }

        $user = $request->user();

        // a salesperson must not learn who owns someone else's lead
        $canSee = $user->can('view', $lead);

        return response()->json([
            'exists' => true,
            'message' => $canSee
                ? "Already exists for this project — {$lead->full_name}, owned by {$lead->owner?->display_name}."
                : 'This number already exists for this project. Please contact the admin.',
        ]);
    }

    /**
     * The active projects a NEW or edited lead may be filed against.
     *
     * The same project boundary Lead::scopeVisibleTo() and
     * LeadPolicy::onOwnProject() already apply: `see_all_leads` sees
     * everything, same as admin, and a salesperson without it is narrowed to
     * the project(s) they are tied to via `project_user` — the same
     * relationship the Reassign candidate list and the visibility scope both
     * read. Telecallers carry no such tie, so they are never narrowed either;
     * in practice they never reach this list at all, since `add_leads` is off
     * for them by default.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function visibleProjects(User $user)
    {
        $query = $user->isSalesperson() && ! $user->can_('see_all_leads')
            ? $user->projects()
            : Project::query();

        return $query->active()
            ->orderBy('projects.name')
            ->get(['projects.id', 'projects.name'])
            ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])
            ->values();
    }

    private function options($user): array
    {
        // the projects the add/edit-lead dropdown offers — see visibleProjects()
        $projects = $this->visibleProjects($user);

        return [
            /*
              | Every stage for the LABELS, the active keys for the CONTROLS.
              | StageBadge and the lead view read the first; the stage dropdown
              | on the form filters by the second, keeping whichever stage the
              | lead is already in even when that one has been switched off.
              */
            'stages' => CrmTaxonomy::allStages(),
            'activeStages' => CrmTaxonomy::activeStageKeys(),
            // which desk each stage belongs to — the reassign form reads this
            // to work out who to offer as the lead's stage changes under the
            // person picker, without asking the server again
            'stageOwnerRoles' => CrmTaxonomy::stageOwnerRoles(),
            /*
             | Who a NEW lead would be assigned to, stage by stage, for the
             | follow-up clash warning on the add-lead form and for nothing
             | else. By stage because the owner depends on the stage picked.
             |
             | Per project as well, because a salesperson is chosen from the
             | project's own team: project id => stage => user id, over EVERY
             | active project rather than only the ones the dropdown below
             | offers this user — a salesperson may hold a lead on a project
             | they are not tied to (see LeadAssignmentService::ownerFor()'s
             | "holder already doing the job" fallback), so the preview has to
             | cover it too, even though the dropdown itself does not.
             |
             | Read-only and advisory. It is LeadAssignmentService's answer
             | without taking a turn from the round robin; store() asks the
             | same service again and takes no owner from the request, so a
             | tampered value changes a sentence on screen and cannot change a
             | single row. An existing lead is not covered by this — the form
             | reads that one's own assigned_to.
             */
            'defaultOwners' => $this->assignment->preview($user, Project::active()->pluck('id')),
            'stageColors' => CrmTaxonomy::stageColors(),
            // today in IST. The date inputs use this as their max rather than
            // the browser clock, which may be in another timezone entirely.
            'today' => today()->toDateString(),
            'sources' => CrmTaxonomy::allSources(),
            'activeSources' => CrmTaxonomy::activeSourceKeys(),
            'reasons' => config('crm.lost_reasons'),
            'projects' => $projects,
            /*
             | The picker that replaced the free-text broker field.
             |
             | Active partners only, firms and brokers alike — a lead can come
             | through the firm itself, through a broker inside it, or through
             | an individual broker with no firm at all, and all three are rows
             | here. `parent` is eager loaded so a broker arrives labelled
             | "Ravi Kumar — Shreeji Realty" rather than as one of two Ravis.
             |
             | Firms first, then brokers, each alphabetically — the same order
             | the Channel Partners page uses.
             |
             | Everyone who can reach the lead form gets this list, not only
             | admins: a telecaller filing a broker lead has to be able to name
             | the broker. That is a narrower thing than the roster on
             | /channel-partners, which carries phone numbers, addresses and
             | contact people and stays behind `role:admin`.
             */
            'channelPartners' => ChannelPartner::active()
                ->with('parent:id,name')
                ->orderByRaw("CASE type WHEN 'firm' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'parent_id'])
                ->map(fn (ChannelPartner $p) => [
                    'id' => $p->id,
                    // the raw name as well as the joined label: the near-match
                    // warning compares names, and similarity-keying
                    // "Ravi Kumar — Shreeji Realty" would fold the firm into
                    // the broker's own name and never match a bare "Ravi Kumar"
                    'name' => $p->name,
                    'label' => $p->display_label,
                    'type' => $p->type,
                ]),

            /*
             | What the inline "add a partner" form on this modal needs, and
             | nothing more: the two type labels, and the active firms a new
             | broker may be filed under.
             |
             | Shipped to everyone who can reach the lead form rather than to
             | admins only, for the same reason the partner list itself is: a
             | salesperson logging a broker lead has to be able to say which
             | firm that broker works for. These are names, not the roster —
             | the phone numbers, addresses and contact people stay behind
             | `role:admin` on the Channel Partners page.
             */
            'partnerTypes' => config('crm.channel_partner_types'),
            'partnerFirms' => ChannelPartner::firms()->active()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (ChannelPartner $f) => ['id' => $f->id, 'name' => $f->name]),
            'roleLabels' => config('crm.role_labels'),
            // the follow-up the form now books itself: what kind of task it is,
            // and which stages end the chain instead of continuing it
            'types' => config('crm.todo_types'),
            'terminalStages' => CrmTaxonomy::terminalStages(),
            'handoverStage' => CrmTaxonomy::handoverStage(),
            // the desk that stage hands leads to: a lead held by anyone else
            // changes hands when it gets there
            'handoverRole' => CrmTaxonomy::ownerRoleFor(CrmTaxonomy::handoverStage()),
            // the Assigned-to filter only means anything to someone who can
            // see past their own rows
            'users' => $user->can_('see_all_leads')
                ? User::whereIn('role', ['telecaller', 'salesperson'])
                // switched-off staff stay, their leads are still here; a
                // sign-up nobody approved never held one
                    ->approved()
                    ->get(['id', 'first_name', 'last_name'])
                : [],
            /*
             | What this user may do, resolved server-side. The page shows or
             | hides Add / Edit / Delete from these rather than from the role,
             | so a telecaller granted `add_leads` gets the button — and the
             | policy is still what actually decides on the way back in.
             */
            'can' => [
                'add' => $user->can_('add_leads'),
                'edit' => $user->can_('edit_leads'),
                'delete' => $user->can_('delete_leads'),
            ],
        ];
    }
}
