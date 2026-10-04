<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeadSourceRequest;
use App\Http\Requests\LeadStageRequest;
use App\Models\AutomationRule;
use App\Models\LeadSource;
use App\Models\LeadStage;
use App\Services\Automation\AutoSend;
use App\Services\LeadAssignmentService;
use App\Support\CrmTaxonomy;
use App\Support\TaxonomyReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Stages & Sources — the vocabulary the whole application speaks, edited by the
 * person who runs the office rather than by a developer.
 *
 * ---------------------------------------------------------------------------
 * There is no "move the leads somewhere else" button, and there must not be
 * ---------------------------------------------------------------------------
 *
 * It is the first thing anybody asks for and it is the feature that quietly
 * destroys the reports. Moving thirty leads out of "In discussion" so the stage
 * can be deleted does not tidy the pipeline, it rewrites what happened to those
 * thirty leads: they were in discussion, somebody had that conversation, and
 * the history rows on the Completed tab say so. A bulk stage change would also
 * have to decide what to do about `todos.outcome_stage`, and there is no honest
 * answer — either the history keeps naming a stage that no longer exists, or it
 * is edited, which is falsifying it.
 *
 * So the operation this screen offers instead is DEACTIVATION, which changes
 * nothing at all: the stage stops being offered in dropdowns, every lead stays
 * exactly where it is, every historical report returns the same numbers, and
 * the decision is reversible by clicking the toggle back.
 *
 * ---------------------------------------------------------------------------
 * The three locks
 * ---------------------------------------------------------------------------
 *
 *   is_system   — the row is named in PHP. Cannot be deleted, deactivated or
 *                 re-keyed. Label and colour stay editable, because nothing
 *                 branches on those.
 *   references  — leads, history rows, automation rules. A hard delete needs
 *                 all three at zero, checked here and not in the browser.
 *   the key     — never in a request's validated data after creation. See
 *                 LeadStageRequest.
 */
class PipelineController extends Controller
{
    public function __construct(private LeadAssignmentService $assignment) {}

    /* ====================================================================
     | The page
     ==================================================================== */

    public function index(Request $request)
    {
        return Inertia::render('Pipeline/Index', [
            'tab' => in_array($request->query('tab'), ['stages', 'sources'], true)
                ? $request->query('tab')
                : 'stages',

            'stages' => $this->stageRows(),
            'sources' => $this->sourceRows(),

            'options' => [
                'palette' => config('crm.stage_palette'),
                // the source form's "default stage" picker offers live stages only
                'activeStages' => CrmTaxonomy::stages(),
                'roles' => collect(config('crm.staff_roles'))
                    ->mapWithKeys(fn (string $r) => [$r => config("crm.role_words.$r", $r)])
                    ->all(),
                /*
                 | Named so the screen can explain WHY one of the system rows is
                 | locked harder than the others: this is the stage that moves a
                 | lead from a telecaller to a salesperson, and switching it off
                 | would strand every telecaller lead at the point of handover.
                 */
                'handoverStage' => CrmTaxonomy::handoverStage(),
            ],
        ]);
    }

    /**
     * Every stage, with the three counts that decide what may be done to it.
     *
     * The counts are computed per row rather than in one grouped query, and
     * that is a deliberate trade: there are nine stages, this page is opened
     * rarely, and the alternative is three GROUP BYs that have to be zero-filled
     * and joined back by hand for a saving nobody can measure.
     *
     * @return list<array<string, mixed>>
     */
    private function stageRows(): array
    {
        $pastHandover = $this->assignment->stagesPastHandover();

        return LeadStage::ordered()->get()->map(function (LeadStage $stage) use ($pastHandover) {
            $refs = TaxonomyReferences::forStage($stage->key);

            return [
                'id' => $stage->id,
                'key' => $stage->key,
                'label' => $stage->label,
                'color' => $stage->color,
                'sort_order' => $stage->sort_order,
                'is_terminal' => $stage->is_terminal,
                'is_system' => $stage->is_system,
                'is_active' => $stage->is_active,
                'is_handover' => $stage->key === CrmTaxonomy::handoverStage(),
                /*
                 | The desk a new lead here goes to (null: whoever adds it), and
                 | whether this stage is past the handover — together they are
                 | what the row's warning marker and the form's live note read.
                 */
                'owner_role' => $stage->owner_role,
                'past_handover' => in_array($stage->key, $pastHandover, true),
                'leads' => $refs['leads'],
                'history' => $refs['history'],
                'rules' => $refs['rules'],
                /*
                 | Why each button is disabled, or null when it is not.
                 |
                 | Sent from the server rather than worked out in the browser, so
                 | the sentence on the greyed-out button and the sentence in the
                 | refusal are the same sentence. Neither is the guard: destroy()
                 | and update() below refuse the same cases again, for a request
                 | that never went through this page.
                 */
                'cannot_delete' => $this->stageDeleteBlocker($stage, $refs),
                'cannot_deactivate' => $this->stageDeactivateBlocker($stage),
                'cannot_retype' => $this->stageTerminalBlocker($stage, $refs),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function sourceRows(): array
    {
        $stages = CrmTaxonomy::allStages();

        return LeadSource::ordered()->get()->map(function (LeadSource $source) use ($stages) {
            $refs = TaxonomyReferences::forSource($source->key);

            return [
                'id' => $source->id,
                'key' => $source->key,
                'label' => $source->label,
                'sort_order' => $source->sort_order,
                'default_stage_key' => $source->default_stage_key,
                'default_stage' => $source->default_stage_key
                    ? ($stages[$source->default_stage_key] ?? $source->default_stage_key)
                    : null,
                'default_owner_role' => $source->default_owner_role,
                'is_system' => $source->is_system,
                'is_active' => $source->is_active,
                'leads' => $refs['leads'],
                'rules' => $refs['rules'],
                'cannot_delete' => $this->sourceDeleteBlocker($source, $refs),
                'cannot_deactivate' => $this->sourceDeactivateBlocker($source),
            ];
        })->values()->all();
    }

    /* ====================================================================
     | Stages — writes
     ==================================================================== */

    public function storeStage(LeadStageRequest $request)
    {
        return $this->routingChange(function () use ($request) {
            LeadStage::create($request->validated() + [
                // slugged from the label, and locked from here on
                'key' => $request->slugKey(),
                // on the end of the list: a new stage is not silently inserted
                // in the middle of a pipeline somebody has already ordered
                'sort_order' => (int) LeadStage::max('sort_order') + 10,
                'is_system' => false,
            ]);
        }, 'Stage added.');
    }

    public function updateStage(LeadStageRequest $request, LeadStage $stage)
    {
        $data = $request->validated();
        $refs = TaxonomyReferences::forStage($stage->key);

        $switchingOff = array_key_exists('is_active', $data) && ! $data['is_active'];

        if ($switchingOff && ($blocker = $this->stageDeactivateBlocker($stage))) {
            throw ValidationException::withMessages(['is_active' => $blocker]);
        }

        $reTyping = array_key_exists('is_terminal', $data)
            && (bool) $data['is_terminal'] !== $stage->is_terminal;

        if ($reTyping && ($blocker = $this->stageTerminalBlocker($stage, $refs))) {
            throw ValidationException::withMessages(['is_terminal' => $blocker]);
        }

        // `key` is not in validated() — see LeadStageRequest
        return $this->routingChange(fn () => $stage->update($data), 'Stage saved.');
    }

    /**
     * Hard delete, and only when the stage is a word nothing has ever been
     * written in.
     */
    public function destroyStage(LeadStage $stage)
    {
        if (($blocker = $this->stageDeleteBlocker($stage, TaxonomyReferences::forStage($stage->key))) !== null) {
            throw ValidationException::withMessages(['stage' => $blocker]);
        }

        /*
         | The only rules that can still name the stage here are switched-off
         | Auto-send rules — a row set to None. They go with it: otherwise None
         | would leave a rule behind that blocks this delete for ever, with no
         | screen that can remove it.
         */
        DB::transaction(function () use ($stage) {
            $this->stageRules($stage->key)['removable']->each->delete();
            $stage->delete();
        });

        return back()->with('success', 'Stage deleted.');
    }

    public function reorderStages(Request $request)
    {
        // the order is part of the routing: dragging a telecaller stage below
        // the handover stage makes it an advanced stage on the telecaller desk
        return $this->routingChange(
            fn () => $this->applyOrder($request, LeadStage::class, 'lead_stages'),
            'Stage order saved.',
        );
    }

    /**
     * Save a change to the stages, and say so if it has just started sending
     * leads that are past the calling stage to a telecaller.
     *
     * Allowed, because the mapping is the admin's to set. Never silent, because
     * the consequence — a telecaller holding a customer who has already visited
     * the site, and no salesperson seeing them — shows up nowhere on this
     * screen by itself. Only a stage that NEWLY lands in that state is named:
     * saving one that already was, for a label change, is not news. The row
     * itself stays marked on the Stages screen for as long as it is set.
     */
    private function routingChange(callable $save, string $success)
    {
        $before = $this->assignment->telecallerStagesPastHandover();

        $save();

        $warning = $this->assignment->routingWarning(
            array_diff_key($this->assignment->telecallerStagesPastHandover(), $before)
        );

        $response = back()->with('success', $success);

        return $warning ? $response->with('warning', $warning) : $response;
    }

    /* ====================================================================
     | Sources — writes
     ==================================================================== */

    public function storeSource(LeadSourceRequest $request)
    {
        LeadSource::create($request->validated() + [
            'key' => $request->slugKey(),
            'sort_order' => (int) LeadSource::max('sort_order') + 10,
            'is_system' => false,
        ]);

        return back()->with('success', 'Source added.');
    }

    public function updateSource(LeadSourceRequest $request, LeadSource $source)
    {
        $data = $request->validated();

        $switchingOff = array_key_exists('is_active', $data) && ! $data['is_active'];

        if ($switchingOff && ($blocker = $this->sourceDeactivateBlocker($source))) {
            throw ValidationException::withMessages(['is_active' => $blocker]);
        }

        $source->update($data);

        return back()->with('success', 'Source saved.');
    }

    public function destroySource(LeadSource $source)
    {
        if (($blocker = $this->sourceDeleteBlocker($source, TaxonomyReferences::forSource($source->key))) !== null) {
            throw ValidationException::withMessages(['source' => $blocker]);
        }

        $source->delete();

        return back()->with('success', 'Source deleted.');
    }

    public function reorderSources(Request $request)
    {
        $this->applyOrder($request, LeadSource::class, 'lead_sources');

        return back()->with('success', 'Source order saved.');
    }

    /* ====================================================================
     | The refusals
     ==================================================================== */

    /**
     * @param  array{leads: int, history: int, rules: list<string>}  $refs
     */
    private function stageDeleteBlocker(LeadStage $stage, array $refs): ?string
    {
        if ($stage->is_system) {
            return 'This stage is part of how the application works and cannot be deleted. Switch it off instead.';
        }

        if ($refs['leads'] > 0) {
            return $this->plural($refs['leads'], 'lead is', 'leads are')
                .' sitting in this stage. Switch it off instead — deleting it would leave them with no stage at all.';
        }

        if ($refs['history'] > 0) {
            return $this->plural($refs['history'], 'completed follow-up records', 'completed follow-ups record')
                .' a move to this stage. Switch it off instead — deleting it would change what those reports say.';
        }

        if ($refs['rules'] !== []) {
            $rules = $this->stageRules($stage->key);
            $reasons = [];

            if ($rules['auto_send']->isNotEmpty()) {
                $reasons[] = 'Auto-send sends a message when a lead reaches this stage. Set it to None on the Auto-send tab first.';
            }

            if ($rules['other']->isNotEmpty()) {
                $names = $rules['other']->pluck('name')->all();
                $reasons[] = 'Used by '.$this->ruleList($names).' (listed under Other automation on the Auto-send tab). '
                    .'Switch '.(count($names) === 1 ? 'it' : 'them').' off there, then ask your developer to remove '
                    .(count($names) === 1 ? 'it' : 'them').'.';
            }

            if ($reasons !== []) {
                return implode(' ', $reasons);
            }
        }

        return null;
    }

    /**
     * The rules naming a stage, in the three groups deleting it treats
     * differently:
     *
     *   removable  an Auto-send rule that is switched off — the row was set
     *              to None. Deleted along with the stage.
     *   auto_send  the switched-on rule an Auto-send row shows. Blocks, and
     *              the way out is setting the row to None.
     *   other      anything else: conditions, other actions, a second
     *              message rule. Blocks; listed under Other automation.
     *
     * @return array{removable: Collection<int, AutomationRule>, auto_send: Collection<int, AutomationRule>, other: Collection<int, AutomationRule>}
     */
    private function stageRules(string $key): array
    {
        $autoSend = app(AutoSend::class);
        $rowRuleIds = $autoSend->rowRuleIds();
        $rules = TaxonomyReferences::rulesNamingStage($key);

        $removable = $rules->filter(fn (AutomationRule $rule) => ! $rule->is_active && $autoSend->isSimple($rule));
        $rest = $rules->diff($removable);

        return [
            'removable' => $removable->values(),
            'auto_send' => $rest->filter(fn (AutomationRule $rule) => in_array($rule->id, $rowRuleIds, true))->values(),
            'other' => $rest->reject(fn (AutomationRule $rule) => in_array($rule->id, $rowRuleIds, true))->values(),
        ];
    }

    private function stageDeactivateBlocker(LeadStage $stage): ?string
    {
        if ($stage->key === CrmTaxonomy::handoverStage()) {
            return 'This is the handover stage — it is what moves a lead from a telecaller to a salesperson. It cannot be switched off.';
        }

        if ($stage->is_system) {
            return 'This stage is part of how the application works and cannot be switched off.';
        }

        /*
         | Never the last one standing. Every lead form has a stage dropdown and
         | every new lead needs a stage, so an empty active list is an
         | application that cannot take a lead — and no screen would explain why.
         | In practice the system rows make this unreachable; it is here because
         | "in practice unreachable" is how the reachable ones get written.
         */
        if ($stage->is_active && LeadStage::active()->count() <= 1) {
            return 'This is the only stage still in use. There has to be at least one.';
        }

        return null;
    }

    /**
     * Why this stage's "ends the journey" flag is frozen.
     *
     * The reason is the one invariant this application has:
     * `Lead::open()->doesntHave('pendingTodo')->count()` must be zero. A lead in
     * a terminal stage carries no pending to-do, by design. Clear the flag on a
     * stage holding leads and every one of them becomes open, with nothing
     * scheduled and no way to notice — the count breaks on the next request and
     * stays broken. Setting the flag is the same trap from the other side once
     * anybody has to unset it again.
     *
     * With no leads in the stage there is nothing to strand, so the flag is
     * free. That is also the state a stage is in the moment it is created,
     * which is when this decision actually gets made.
     *
     * @param  array{leads: int, history: int, rules: list<string>}  $refs
     */
    private function stageTerminalBlocker(LeadStage $stage, array $refs): ?string
    {
        if ($stage->is_system) {
            return 'Whether this stage ends a lead\'s journey is part of how the application works and cannot be changed.';
        }

        if ($refs['leads'] > 0) {
            return $this->plural($refs['leads'], 'lead is', 'leads are')
                .' sitting in this stage, so whether it ends the journey cannot be changed now — their follow-ups depend on the answer.';
        }

        return null;
    }

    /**
     * @param  array{leads: int, history: int, rules: list<string>}  $refs
     */
    private function sourceDeleteBlocker(LeadSource $source, array $refs): ?string
    {
        if ($source->is_system) {
            return 'This source is part of how the application works and cannot be deleted. Switch it off instead.';
        }

        if ($refs['leads'] > 0) {
            return $this->plural($refs['leads'], 'lead came', 'leads came')
                .' from this source. Switch it off instead — deleting it would change every report that has ever counted them.';
        }

        if ($refs['rules'] !== []) {
            return 'Used by '.$this->ruleList($refs['rules']).' (listed under Other automation on the Auto-send tab). '
                .'Switch '.(count($refs['rules']) === 1 ? 'it' : 'them').' off there, then ask your developer to remove '
                .(count($refs['rules']) === 1 ? 'it' : 'them').'.';
        }

        return null;
    }

    private function sourceDeactivateBlocker(LeadSource $source): ?string
    {
        if ($source->is_system) {
            return 'This source is part of how the application works and cannot be switched off.';
        }

        if ($source->is_active && LeadSource::active()->count() <= 1) {
            return 'This is the only source still in use. There has to be at least one.';
        }

        return null;
    }

    /* ====================================================================
     | Helpers
     ==================================================================== */

    /**
     * Drag-to-reorder, as one numbered list rather than a pile of moves.
     *
     * The request carries the ids in their new order and this rewrites every
     * row's `sort_order` from that, in one transaction. A "move row 4 above row
     * 2" API would be shorter to send and impossible to make idempotent: two
     * admins dragging at once, or a dropped response the browser retries, and
     * the order is something neither of them chose.
     *
     * `whereIn` on the ids the table actually holds, so an id from another
     * table — or one deleted between the page loading and the drag landing —
     * simply does not match and the rest of the order still applies.
     *
     * @param  class-string<Model>  $model
     */
    private function applyOrder(Request $request, string $model, string $table): void
    {
        $ids = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer', 'exists:'.$table.',id'],
        ])['order'];

        DB::transaction(function () use ($ids, $model) {
            foreach (array_values($ids) as $index => $id) {
                $model::whereKey($id)->update(['sort_order' => ($index + 1) * 10]);
            }
        });

        // update() on a query builder fires no model events, so the vocabulary
        // cache would otherwise still be serving the old order
        CrmTaxonomy::flush();
    }

    private function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    /** @param  list<string>  $names */
    private function ruleList(array $names): string
    {
        $quoted = array_map(fn (string $n) => '"'.$n.'"', array_slice($names, 0, 3));
        $extra = count($names) - count($quoted);

        return implode(', ', $quoted).($extra > 0 ? " and $extra more" : '');
    }
}
