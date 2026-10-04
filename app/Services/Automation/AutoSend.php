<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
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
 */
class AutoSend
{
    public const NEW_ENQUIRY = 'new_enquiry';

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

    /** @return list<int> the rule each row shows as its own */
    public function rowRuleIds(): array
    {
        return collect($this->rows())->pluck('rule_id')->filter()->values()->all();
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
     * new-lead or stage trigger, no conditions, one WhatsApp action.
     */
    public function isSimple(AutomationRule $rule): bool
    {
        $actions = $rule->actionList();

        return in_array($rule->trigger, ['lead_created', 'stage_changed'], true)
            && $rule->conditionList() === []
            && count($actions) === 1
            && ($actions[0]['type'] ?? null) === 'queue_whatsapp'
            && filled($actions[0]['template_id'] ?? null)
            && ($rule->trigger === 'lead_created' || filled($rule->triggerSetting('stage')));
    }

    /* ---------------- internals ---------------- */

    /** @return Collection<int, AutomationRule> */
    private function whatsAppRules(): Collection
    {
        return AutomationRule::query()
            ->whereIn('trigger', ['lead_created', 'stage_changed'])
            ->where('actions', 'like', '%"queue_whatsapp"%')
            ->orderBy('id')
            ->get()
            ->filter(fn (AutomationRule $rule) => $rule->hasAction('queue_whatsapp'))
            ->values();
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
