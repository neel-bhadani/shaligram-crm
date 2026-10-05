<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Illuminate\Support\Collection;

/**
 * The Auto-send tab: "when a lead reaches this stage, send that message".
 *
 * There is no second engine. Each row is an ordinary AutomationRule — a
 * trigger and one WhatsApp action — and RuleEngine runs it exactly as it runs
 * anything else. What this class does is hide the rule: choosing a message
 * writes or updates the rule, choosing None switches it off.
 *
 * A row is one of:
 *
 *   new_enquiry   `lead_created`, NOT `stage_changed` to Fresh. A lead that
 *                 arrives — typed in or from Facebook — is born at Fresh and
 *                 never moves there, so a stage rule would never fire for it.
 *   a stage key   `stage_changed` to that stage. Fresh itself is not a row:
 *                 New enquiry is Fresh.
 *
 * WHICH RULES A ROW OWNS. A "simple" rule — that trigger, no conditions, one
 * WhatsApp action and nothing else — is exactly what a row can show, so the
 * row shows and edits it, whoever made it and however long ago. Anything more
 * (a condition, a second action) cannot be shown as one dropdown without
 * losing part of it, so the row names it and never touches it; it is listed
 * under Other automation, with only a Switch off button.
 *
 * PER PROJECT. The rows above are "All projects": rules with no project_id,
 * the default each stage sends. A project can override a row with a rule of
 * its own — same trigger, `project_id` set — which RuleEngine runs INSTEAD of
 * the default's rule for that project's leads (applyOverrides()). Only this
 * class writes project_id, so every rule carrying one is an override. Its
 * state per row:
 *
 *   same     no switched-on override: the default stands, whatever it is.
 *   own      a switched-on override with a tag.
 *   nothing  a switched-on override with NO tag. Not the same as "same"
 *            while the default is None: it holds when somebody later sets
 *            a default, and "same" does not.
 *
 * Back to "same" switches the override off rather than deleting it, like
 * None on a default row: its run count and log stay.
 */
class AutoSend
{
    public const NEW_ENQUIRY = 'new_enquiry';

    public const SAME = 'same';

    public const OWN = 'own';

    public const NOTHING = 'nothing';

    /** The stage a new lead is born at, which New enquiry stands for. */
    private const ENTRY_STAGE = 'fresh';

    /**
     * Every row, in pipeline order.
     *
     * @return array<string, array{label: string, trigger: string, stage: ?string}>
     */
    public function slots(): array
    {
        $slots = [self::NEW_ENQUIRY => ['label' => 'New enquiry', 'trigger' => 'lead_created', 'stage' => null]];

        foreach (CrmTaxonomy::stages() as $key => $label) {
            if ($key !== self::ENTRY_STAGE) {
                $slots[$key] = ['label' => $label, 'trigger' => 'stage_changed', 'stage' => $key];
            }
        }

        return $slots;
    }

    /**
     * What the tab shows.
     *
     * `template_id` is null for None: no simple rule, or one switched off.
     * `others` are every other WhatsApp rule that fires on the same moment —
     * a second simple one, or one with conditions — so nobody believes a row
     * set to None means nothing is sent.
     *
     * @return list<array{key: string, label: string, template_id: ?int, rule_id: ?int, others: list<array{id: int, name: string, is_active: bool}>}>
     */
    public function rows(): array
    {
        $rules = $this->whatsAppRules();

        return collect($this->slots())
            ->map(function (array $slot, string $key) use ($rules) {
                $here = $rules->filter(fn (AutomationRule $rule) => $this->firesOn($rule, $slot))->values();
                $primary = $this->primary($here->filter(fn (AutomationRule $rule) => $this->isSimple($rule)));

                return [
                    'key' => $key,
                    'label' => $slot['label'],
                    'template_id' => $primary?->is_active ? (int) $primary->actionList()[0]['template_id'] : null,
                    'rule_id' => $primary?->id,
                    'others' => $here
                        ->reject(fn (AutomationRule $rule) => $rule->is($primary))
                        ->map(fn (AutomationRule $rule) => ['id' => $rule->id, 'name' => $rule->name, 'is_active' => $rule->is_active])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Choose a message for a row, or None.
     *
     * A message: the row's simple rule is pointed at it and switched on — or,
     * when there is none, one is written, already on. Everything else about an
     * existing rule stays as it was: name, history, click-or-API, and the
     * booked-and-lost setting.
     *
     * None: every simple rule on the row is switched off, not deleted — its
     * run count and activity log stay. Rules with conditions are not touched.
     */
    public function set(string $key, ?MessageTemplate $template, User $user): void
    {
        $slot = $this->slots()[$key];
        $simple = $this->whatsAppRules()
            ->filter(fn (AutomationRule $rule) => $this->firesOn($rule, $slot) && $this->isSimple($rule));

        if (! $template) {
            $simple->each(fn (AutomationRule $rule) => $rule->update(['is_active' => false]));

            return;
        }

        if ($primary = $this->primary($simple)) {
            $actions = $primary->actionList();
            $actions[0]['template_id'] = $template->id;

            $primary->update(['actions' => $actions, 'is_active' => true]);

            return;
        }

        AutomationRule::create([
            'name' => "Auto-send: {$slot['label']}",
            'trigger' => $slot['trigger'],
            'trigger_config' => $slot['stage'] ? ['stage' => $slot['stage']] : [],
            'conditions' => [],
            'actions' => [['type' => 'queue_whatsapp', 'mode' => 'api', 'template_id' => $template->id]],
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    /**
     * The projects for the picker, each with its switched-on overrides.
     *
     * Switched-off projects are listed too: their leads still move through
     * the stages, so their overrides still send. Deleted ones are not.
     *
     * @return list<array{id: int, name: string, is_active: bool, overrides: array<string, array{choice: string, template_id: ?int}>}>
     */
    public function projects(): array
    {
        $overrides = $this->overrideRules()->filter(fn (AutomationRule $rule) => $rule->is_active);

        return Project::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'is_active' => $project->is_active,
                'overrides' => collect($this->slots())
                    ->map(fn (array $slot) => $overrides->first(
                        fn (AutomationRule $rule) => $rule->project_id === $project->id && $this->firesOn($rule, $slot)
                    ))
                    ->filter()
                    ->map(fn (AutomationRule $rule) => [
                        'choice' => $this->templateOf($rule) ? self::OWN : self::NOTHING,
                        'template_id' => $this->templateOf($rule),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Choose for a project's row: same, a tag of its own, or nothing.
     *
     * Own or nothing points the row's override at the tag (or at none) and
     * switches it on — writing one, already on, when there is none. A new one
     * sends the way the default's rule does: by API unless that was set to
     * click-to-send.
     */
    public function setForProject(string $key, Project $project, string $choice, ?MessageTemplate $template, User $user): void
    {
        $slot = $this->slots()[$key];
        $mine = $this->overrideRules()
            ->filter(fn (AutomationRule $rule) => $rule->project_id === $project->id && $this->firesOn($rule, $slot));

        if ($choice === self::SAME) {
            $mine->each(fn (AutomationRule $rule) => $rule->update(['is_active' => false]));

            return;
        }

        $templateId = $choice === self::OWN ? $template?->id : null;

        if ($override = $this->primary($mine)) {
            $actions = $override->actionList();
            $actions[0]['template_id'] = $templateId;

            $override->update(['actions' => $actions, 'is_active' => true]);

            return;
        }

        AutomationRule::create([
            'name' => "Auto-send: {$slot['label']} ({$project->name})",
            'trigger' => $slot['trigger'],
            'trigger_config' => $slot['stage'] ? ['stage' => $slot['stage']] : [],
            'project_id' => $project->id,
            'conditions' => [],
            'actions' => [['type' => 'queue_whatsapp', 'mode' => $this->modeFor($key), 'template_id' => $templateId]],
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    /** The project's override for a row, switched on or not. */
    public function overrideFor(string $key, Project $project): ?AutomationRule
    {
        $slot = $this->slots()[$key];

        return $this->primary($this->overrideRules()
            ->filter(fn (AutomationRule $rule) => $rule->project_id === $project->id && $this->firesOn($rule, $slot)));
    }

    /** How a row's message goes: by API unless its rule was set to click-to-send. */
    public function modeFor(string $key, ?Project $project = null): string
    {
        $rule = ($project ? $this->overrideFor($key, $project) : null) ?? $this->ruleFor($key);

        return $rule?->actionList()[0]['mode'] ?? 'api';
    }

    /**
     * A project is deleted: its overrides are switched off, not deleted. It
     * can have no leads — ProjectController refuses otherwise — so they could
     * never send; off, they also stop counting against the stage.
     */
    public function switchOffProject(Project $project): void
    {
        AutomationRule::where('project_id', $project->id)->update(['is_active' => false]);
    }

    /**
     * The rules one lead's event will run, with its project's overrides
     * applied.
     *
     * Given the switched-on rules RuleEngine loaded for the event — the
     * All-projects ones and this lead's project's, nothing else — each
     * override takes the place of its row's default rule. One that sends
     * nothing takes its place and is dropped itself. Every other rule on the
     * row (Other automation) still runs: it is listed there as also sending.
     *
     * @param  Collection<int, AutomationRule>  $rules  ordered by id
     * @return Collection<int, AutomationRule>
     */
    public function applyOverrides(Collection $rules): Collection
    {
        $overrides = $rules->filter(fn (AutomationRule $rule) => $rule->project_id !== null);

        if ($overrides->isEmpty()) {
            return $rules;
        }

        $replaced = [];
        $used = [];

        foreach ($this->slots() as $slot) {
            $override = $overrides->first(fn (AutomationRule $rule) => $this->firesOn($rule, $slot));

            if (! $override) {
                continue;
            }

            $default = $rules->first(fn (AutomationRule $rule) => $rule->project_id === null
                && $this->firesOn($rule, $slot)
                && $this->isSimple($rule));

            if ($default) {
                $replaced[] = $default->id;
            }

            if ($this->templateOf($override)) {
                $used[] = $override->id;
            }
        }

        return $rules
            ->reject(fn (AutomationRule $rule) => in_array($rule->id, $replaced, true)
                || ($rule->project_id !== null && ! in_array($rule->id, $used, true)))
            ->values();
    }

    /**
     * Every rule a row does not show as its own: rules with conditions or
     * other actions, time-based rules, a second WhatsApp rule on a row that
     * already has one, a rule on a stage that is switched off. Listed under
     * Other automation, because a rule nobody can see is automation nobody
     * can audit.
     *
     * @return Collection<int, AutomationRule>
     */
    public function otherRules(): Collection
    {
        $own = $this->rowRuleIds();

        return AutomationRule::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->reject(fn (AutomationRule $rule) => in_array($rule->id, $own, true))
            ->values();
    }

    /** @return list<int> the rule each row shows as its own, and every project override */
    public function rowRuleIds(): array
    {
        return collect($this->rows())->pluck('rule_id')->filter()
            ->merge($this->overrideRules()->pluck('id'))
            ->values()
            ->all();
    }

    /** The row's own rule when there is one: the switched-on one first, then the oldest. */
    public function ruleFor(string $key): ?AutomationRule
    {
        $slot = $this->slots()[$key];

        return $this->primary($this->whatsAppRules()
            ->filter(fn (AutomationRule $rule) => $this->firesOn($rule, $slot) && $this->isSimple($rule)));
    }

    /**
     * Shown and edited on Auto-send rather than in the rule builder: a
     * new-lead or stage trigger, no conditions, one WhatsApp action. Its tag
     * is required, except on a project's "send nothing".
     */
    public function isSimple(AutomationRule $rule): bool
    {
        $actions = $rule->actionList();

        return in_array($rule->trigger, ['lead_created', 'stage_changed'], true)
            && $rule->conditionList() === []
            && count($actions) === 1
            && ($actions[0]['type'] ?? null) === 'queue_whatsapp'
            && (filled($actions[0]['template_id'] ?? null) || $rule->project_id !== null)
            && ($rule->trigger === 'lead_created' || filled($rule->triggerSetting('stage')));
    }

    /* ---------------- internals ---------------- */

    /** @return Collection<int, AutomationRule> the All-projects ones */
    private function whatsAppRules(): Collection
    {
        return AutomationRule::query()
            ->whereNull('project_id')
            ->whereIn('trigger', ['lead_created', 'stage_changed'])
            ->where('actions', 'like', '%"queue_whatsapp"%')
            ->orderBy('id')
            ->get()
            ->filter(fn (AutomationRule $rule) => $rule->hasAction('queue_whatsapp'))
            ->values();
    }

    /** @return Collection<int, AutomationRule> every project override, on or off */
    private function overrideRules(): Collection
    {
        return AutomationRule::query()
            ->whereNotNull('project_id')
            ->whereIn('trigger', ['lead_created', 'stage_changed'])
            ->orderBy('id')
            ->get();
    }

    private function templateOf(AutomationRule $rule): ?int
    {
        $id = $rule->actionList()[0]['template_id'] ?? null;

        return filled($id) ? (int) $id : null;
    }

    /** @param array{trigger: string, stage: ?string} $slot */
    private function firesOn(AutomationRule $rule, array $slot): bool
    {
        return $rule->trigger === $slot['trigger']
            && ($slot['stage'] === null || $rule->triggerSetting('stage') === $slot['stage']);
    }

    /** @param Collection<int, AutomationRule> $rules */
    private function primary(Collection $rules): ?AutomationRule
    {
        return $rules->first(fn (AutomationRule $rule) => $rule->is_active) ?? $rules->first();
    }
}
