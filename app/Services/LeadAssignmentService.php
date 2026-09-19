<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who a lead belongs to. The one place that decides it.
 *
 * The answer comes from the stage the lead is saved at, never from who typed it
 * in. The stage names a role (`lead_stages.owner_role`, edited on the Stages
 * screen and seeded from `crm.stage_owner_roles`), and the lead goes to a
 * person doing that job:
 *
 *   fresh, connected, not_connected → telecaller
 *   any other open stage            → salesperson
 *   a terminal stage        → nobody new; it stays with whoever added it
 *
 * Three callers, and they must never disagree, which is why none of them
 * decides this itself any more:
 *
 *   LeadController::store() and IncomingLeadService::import(), creating a lead.
 *   The "holder" is the person adding it — or, for an import, the user the
 *   integration is configured to give leads to.
 *
 *   LeadFollowUpService::handover(), moving one — either direction. The holder
 *   is whoever owns the lead now.
 *
 * A holder already doing the job keeps the lead: a salesperson who adds a
 * walk-in after the site visit is the salesperson who will work it. Otherwise
 * it goes to somebody on the right desk, and only when nobody is there does it
 * stay with the holder — `todos.assigned_to` is NOT NULL, and a lead with no
 * owner is on nobody's list.
 *
 * `leads.assigned_role` is always the role of the user this returns, never the
 * role the stage asked for. The two differ exactly when the fallback fired, and
 * that is the case where a label naming the wrong desk hides the problem.
 *
 * ---------------------------------------------------------------------------
 * Salespeople are chosen per project
 * ---------------------------------------------------------------------------
 *
 * The round robin takes turns among the active salespeople ticked for the
 * lead's project (`project_user`, on the project's detail page), in this order
 * of fallback:
 *
 *   1. the project's own active salespeople
 *   2. none ticked, or none of them active: every active salesperson — and
 *      every admin is alerted, because a project nobody is set up for is a
 *      misconfiguration that would otherwise look like it was working
 *   3. no active salesperson anywhere: the holder, as above
 *
 * Each project keeps its own place (`projects.last_assigned_salesperson_id`),
 * read and written under a lock on the project row inside the caller's
 * transaction. Two leads for one project arriving together queue on that lock
 * rather than both reading the same place and landing on the same person, and
 * a lead that fails to save rolls its turn back with it.
 *
 * Telecallers are not chosen per project.
 */
class LeadAssignmentService
{
    public function __construct(private AlertService $alerts) {}

    /** The role a lead at this stage is worked by, or null for a terminal stage. */
    public function roleFor(string $stage): ?string
    {
        return CrmTaxonomy::ownerRoleFor($stage);
    }

    /**
     * The user a lead at `$stage`, on project `$projectId`, should belong to.
     *
     * Call it inside the transaction that saves the lead: taking a turn locks
     * the project row until that transaction ends, and rolls back with it.
     *
     * `$claim = false` asks the same question without taking a turn from the
     * round robin or raising an alert — for the add-lead form, which has to
     * name the person before anything is saved.
     *
     * @return User|null null only when there is no holder and nobody on the desk
     */
    public function ownerFor(string $stage, ?User $holder, int $projectId, bool $claim = true): ?User
    {
        $role = $this->roleFor($stage);

        if ($role === null) {
            return $holder;
        }

        if ($holder && $holder->is_active && $holder->role === $role) {
            return $holder;
        }

        return $this->pick($role, $projectId, $claim) ?? $holder;
    }

    /**
     * Who a lead at `$stage` should belong to after its PROJECT changes.
     *
     * Deliberately not ownerFor(): that method keeps the current holder
     * whenever their role already fits the stage, which is right for a
     * creation or a handover but wrong here — a switch's whole point is to
     * put the lead on the new project's own desk, and the holder almost
     * always already has the right role (assigned_role tracks it), so
     * ownerFor() would hand them straight back without ever looking at the
     * new project's team.
     *
     * pick() is the same call ownerFor() makes once the holder is ruled out —
     * the project-scoped salesperson round robin, or the single telecaller
     * pool — so this is that same desk logic, just never short-circuited by
     * who already holds it. Falls back to the holder only when nobody is on
     * the new project's desk at all, the same fallback ownerFor() itself
     * applies.
     */
    public function ownerForProjectSwitch(string $stage, User $holder, int $projectId): User
    {
        $role = $this->roleFor($stage);

        if ($role === null) {
            return $holder;
        }

        return $this->pick($role, $projectId, claim: true) ?? $holder;
    }

    /**
     * For each project and every stage a new lead can be added at, the id of
     * the user it would land on if `$creator` added it now. Read-only: no
     * round robin moves and no alert is raised.
     *
     * Only a salesperson's answer depends on the project, so everything else
     * is worked out once per role rather than once per project and stage.
     *
     * @param  iterable<int>  $projectIds
     * @return array<int, array<string, int|null>> project id => stage => user id
     */
    public function preview(User $creator, iterable $projectIds): array
    {
        $answers = [];
        $preview = [];

        foreach ($projectIds as $projectId) {
            foreach (CrmTaxonomy::activeStageKeys() as $stage) {
                $role = $this->roleFor($stage) ?? '';
                $key = $role === 'salesperson' ? "salesperson.{$projectId}" : $role;

                if (! array_key_exists($key, $answers)) {
                    $answers[$key] = $this->ownerFor($stage, $creator, $projectId, claim: false)?->id;
                }

                $preview[$projectId][$stage] = $answers[$key];
            }
        }

        return $preview;
    }

    /* ====================================================================
     | The mapping, as the admin edits it
     ==================================================================== */

    /**
     * The open stages at or past the handover — the ones a telecaller has
     * already handed on by the time a lead reaches them.
     *
     * By position, not by a fixed list, because the admin can add and reorder
     * stages: a new stage lands at the end, and dragging "Not connected" below
     * "Site visit scheduled" makes it an advanced stage whatever its name says.
     * Only when there is no handover stage to measure from does it fall back to
     * `crm.advanced_stages`.
     *
     * @return list<string>
     */
    public function stagesPastHandover(): array
    {
        $rows = collect(CrmTaxonomy::stageRows())->reject(fn (array $s) => $s['is_terminal'])->values();
        $index = $rows->search(fn (array $s) => $s['key'] === CrmTaxonomy::handoverStage());

        if ($index === false) {
            return $rows->pluck('key')
                ->intersect((array) config('crm.advanced_stages', []))
                ->values()
                ->all();
        }

        return $rows->slice($index)->pluck('key')->values()->all();
    }

    /**
     * The advanced stages whose new leads go to a telecaller. Allowed — it is
     * the admin's mapping — but never what the seeded rule says, so the Stages
     * screen marks each one and warns the moment one appears.
     *
     * @return array<string, string> key => label
     */
    public function telecallerStagesPastHandover(): array
    {
        $labels = CrmTaxonomy::allStages();

        return collect($this->stagesPastHandover())
            ->filter(fn (string $key) => $this->roleFor($key) === 'telecaller')
            ->mapWithKeys(fn (string $key) => [$key => $labels[$key] ?? $key])
            ->all();
    }

    /**
     * What saving the mapping just did, in words, for the stages that have
     * newly started sending advanced leads to a telecaller. Null when none did.
     *
     * Pure string building, like LeadFollowUpService::noticeFor() — the
     * controller owns the flash.
     *
     * @param  array<string, string>  $stages  key => label, as telecallerStagesPastHandover() returns
     */
    public function routingWarning(array $stages): ?string
    {
        if ($stages === []) {
            return null;
        }

        $names = collect($stages)->map(fn (string $label) => '"'.$label.'"')->values();
        $list = $names->count() === 1
            ? $names->first()
            : $names->slice(0, -1)->implode(', ').' and '.$names->last();
        $one = $names->count() === 1;

        $warning = "Saved, but check this: new leads added at {$list} will now go to a telecaller. "
            .($one ? 'A lead at that stage is' : 'Leads at those stages are')
            .' already past the calling stage — the site visit is booked or has happened — so the telecaller'
            .' gets a customer they have no reason to call, and no salesperson sees it until someone moves it by hand.';

        $handover = CrmTaxonomy::handoverStage();

        if ($handover !== null && array_key_exists($handover, $stages)) {
            $warning .= " Leads a telecaller moves to {$names[array_search($handover, array_keys($stages), true)]}"
                .' will also stay with their telecaller instead of being handed to a salesperson.';
        }

        return $warning;
    }

    /* ====================================================================
     | Choosing somebody
     ==================================================================== */

    /**
     * Somebody active on `$role`'s desk.
     *
     * Salespeople take turns within the project, and only while
     * `crm.handover_mode` is `round_robin` — in `admin` mode the admin hands
     * salesperson work out by hand and nothing here chooses for them.
     * Telecallers are the first active one, as lead creation always did, and
     * are not chosen per project.
     */
    private function pick(string $role, int $projectId, bool $claim): ?User
    {
        if ($role === 'salesperson') {
            return $this->nextSalesperson($projectId, $claim);
        }

        return User::active()->where('role', $role)->orderBy('id')->first();
    }

    /**
     * The project's next salesperson, and — when `$claim` — their turn taken.
     */
    private function nextSalesperson(int $projectId, bool $claim): ?User
    {
        if (config('crm.handover_mode') !== 'round_robin') {
            return null;
        }

        if (! $claim) {
            $project = Project::withTrashed()->find($projectId);

            return $this->turnAfter(
                $this->candidates($project)['people'],
                $project?->last_assigned_salesperson_id,
            );
        }

        return $this->takeSalespersonTurn($projectId);
    }

    /**
     * Take the next turn from the project's salesperson round robin — with
     * the same fallback and the same admin alert as every other assignment.
     *
     * Public for the automation engine's "share it out among salespeople"
     * action, which is an admin's explicit instruction to take turns and so is
     * not switched off by `crm.handover_mode`. Everything else goes through
     * ownerFor().
     *
     * Locks the project row before reading its place, and writes the new place
     * back before the lock is released. DB::transaction() is a savepoint when
     * the caller already has one open, so the lock is held until the caller's
     * own commit, and a caller with no transaction still gets a read and a
     * write that nothing can come between.
     *
     * @return User|null null only when there is no active salesperson at all
     */
    public function takeSalespersonTurn(int $projectId): ?User
    {
        return DB::transaction(function () use ($projectId) {
            $project = Project::withTrashed()->whereKey($projectId)->lockForUpdate()->firstOrFail();

            ['people' => $people, 'ownTeam' => $ownTeam] = $this->candidates($project);

            if (! $ownTeam) {
                $this->alertUnstaffed($project, $people->isNotEmpty());
            }

            $next = $this->turnAfter($people, $project->last_assigned_salesperson_id);

            if ($next) {
                // toBase(): a turn taken is not an edit to the project, and
                // must not move its `updated_at`
                Project::withTrashed()
                    ->whereKey($project->id)
                    ->toBase()
                    ->update(['last_assigned_salesperson_id' => $next->id]);
            }

            return $next;
        });
    }

    /**
     * Who takes turns on this project: its own active salespeople, or — when
     * it has none — every active salesperson. `ownTeam` says which it was.
     *
     * @return array{people: Collection<int, User>, ownTeam: bool}
     */
    private function candidates(?Project $project): array
    {
        $own = $project
            ? $project->salespeople()->active()->where('role', 'salesperson')->orderBy('users.id')->get()
            : collect();

        if ($own->isNotEmpty()) {
            return ['people' => $own, 'ownTeam' => true];
        }

        return [
            'people' => User::active()->where('role', 'salesperson')->orderBy('id')->get(),
            'ownTeam' => false,
        ];
    }

    /** The first person after `$lastId`, wrapping to the start. */
    private function turnAfter(Collection $people, ?int $lastId): ?User
    {
        return $people->first(fn (User $u) => $u->id > (int) $lastId) ?? $people->first();
    }

    /**
     * Tell every admin that a lead on this project needed a salesperson and the
     * project has none.
     *
     * No lead on the alert: it is about the project, not the customer, and
     * leaving the lead off is also what makes AlertService deduplicate it to
     * one per project per admin per day rather than one per lead.
     */
    private function alertUnstaffed(Project $project, bool $sharedOut): void
    {
        $this->alerts->raiseMany(
            recipients: $this->alerts->admins(),
            type: 'project_without_salespeople.'.$project->id,
            title: "No salesperson is assigned to \"{$project->name}\"",
            body: $sharedOut
                ? 'A lead on this project needed a salesperson and none is ticked for it (or none of '
                    .'those ticked is active), so it went to the next of every active salesperson. '
                    .'Open the project and tick the salespeople who handle it.'
                : 'A lead on this project needed a salesperson, and there is no active salesperson '
                    .'at all, so it stayed with whoever held it. Add or switch on a salesperson, '
                    .'then tick them on the project.',
            severity: 'warning',
            actionUrl: route('projects.show', $project),
        );
    }
}
