<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Support\CrmTaxonomy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Two questions about the same rule, answered by one piece of code.
 *
 *   matches()     does THIS lead satisfy the rule, right now? Asked by the
 *                 engine, once per lead, as events happen.
 *
 *   matchQuery()  which leads satisfy it? Asked by the Test button, which shows
 *                 the admin the blast radius before they switch anything on,
 *                 and by the hourly command, which is how time triggers find
 *                 their work.
 *
 * They have to agree. A Test button that counted a different set from the one
 * the rule would act on is worse than no Test button — it is a confident wrong
 * answer. So the conditions are compiled into a query once, and matches() is
 * that query with a `whereKey` on the end rather than a second implementation
 * in PHP.
 *
 * Conditions are ANDed and only ANDed. `column` is read from
 * config('automation.conditions') and never from the stored rule: the rule
 * names a KEY, the key is looked up, and only the trusted column beside it
 * reaches the query.
 */
class ConditionMatcher
{
    /**
     * Every lead this rule would currently act on.
     *
     * The trigger contributes its own state, where it has one that can be
     * observed at rest — "moves to Site visit done" tests as "is in Site visit
     * done", and the two time triggers test exactly what the hourly command
     * looks for. `lead_created` has no such state, so it contributes nothing
     * and the answer is the conditions alone, which is the honest reading of
     * "which leads match this rule".
     */
    public function matchQuery(AutomationRule $rule): Builder
    {
        $query = Lead::query();

        $this->applyTrigger($query, $rule);
        $this->applyConditions($query, $rule->conditionList());

        if ($rule->project_id !== null) {
            $query->where('project_id', $rule->project_id);
        }

        return $query;
    }

    /**
     * Does this lead satisfy the rule's CONDITIONS?
     *
     * Conditions only — the trigger has already happened by the time the engine
     * asks, and re-testing it here would refuse a legitimate firing. A rule on
     * "moves to Site visit done" is evaluated at the moment of the move, when
     * the lead is in that stage, so the two agree; but a rule on "a lead is
     * created" would never match, because there is no state that says a lead
     * was just created. The trigger belongs to matchQuery(), which is asking a
     * different question.
     */
    public function matches(AutomationRule $rule, Lead $lead): bool
    {
        // an Auto-send project override is about that project's leads only
        if ($rule->project_id !== null && (int) $rule->project_id !== (int) $lead->project_id) {
            return false;
        }

        $conditions = $rule->conditionList();

        if ($conditions === []) {
            return true;
        }

        $query = Lead::whereKey($lead->id);
        $this->applyConditions($query, $conditions);

        return $query->exists();
    }

    /**
     * @param  array<int, array{field?: string, value?: mixed}>  $conditions
     */
    public function applyConditions(Builder $query, array $conditions): Builder
    {
        $catalogue = config('automation.conditions');

        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? null;
            $value = $condition['value'] ?? null;

            // a key the catalogue does not know, or a blank value, is not a
            // condition that matches everything — it is a condition that was
            // never finished, and applying it as "true" would silently widen
            // the rule. Skipped, and AutomationRuleRequest refuses to store it.
            if (! $field || ! isset($catalogue[$field]) || $value === null || $value === '') {
                continue;
            }

            $query->where($catalogue[$field]['column'], $value);
        }

        return $query;
    }

    /**
     * The trigger's own criteria, as a query over leads standing still.
     *
     * This is also the definition the hourly command runs on, which is why the
     * two time triggers are spelled out here rather than in the command: "what
     * this rule matches" has to have one answer.
     */
    private function applyTrigger(Builder $query, AutomationRule $rule): void
    {
        switch ($rule->trigger) {

            case 'stage_changed':
                $stage = $rule->triggerSetting('stage');
                // no stage chosen yet: match nothing rather than everything.
                // A half-built rule showing "matches 412 leads" would be read
                // as the finished rule's blast radius.
                $query->where('stage', $stage ?? '__none__');
                break;

            case 'stage_idle':
                $stage = $rule->triggerSetting('stage');
                $days = (int) $rule->triggerSetting('days', 0);

                $query->where('stage', $stage ?? '__none__')
                    // still moving forward is not stuck
                    ->whereNotIn('stage', CrmTaxonomy::terminalStages())
                    /*
                     | `stage_changed_at` is null on leads created before it was
                     | recorded and on some imported rows, so `created_at` is
                     | the fallback — exactly what Lead::getDaysInStageAttribute
                     | does, and the number the admin is reading on screen when
                     | they write the rule.
                     */
                    ->whereRaw(
                        'COALESCE(stage_changed_at, created_at) <= ?',
                        [now()->subDays(max($days, 1))]
                    );
                break;

            case 'follow_up_overdue':
                $days = (int) $rule->triggerSetting('days', 0);

                $query->open()
                    ->whereHas('todos', fn ($q) => $q
                        ->where('status', 'pending')
                        ->where('scheduled_at', '<=', now()->subDays(max($days, 1))));
                break;

            case 'lead_assigned':
                $query->whereNotNull('assigned_to');
                break;

            case 'lead_created':
            default:
                // no observable state — the conditions are the whole answer
                break;
        }
    }
}
