<?php

namespace App\Support;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\Todo;
use Illuminate\Support\Collection;

/**
 * What still points at a stage or a source, counted in the three places that
 * can point at one.
 *
 * This is the whole safety net under the Stages & Sources screen. `leads.stage`
 * and `todos.outcome_stage` are plain strings with no foreign key behind them —
 * deliberately, see the migration — so nothing in the database will stop a row
 * being deleted out from under thirty leads. This class is what does, and it
 * has to be right in the direction of refusing: a count this misses is a column
 * of leads whose stage becomes a word the application no longer knows.
 *
 * THREE PLACES, and the third is the one that gets forgotten. Automation rules
 * keep stage and source keys inside JSON — `trigger_config.stage`,
 * `conditions[].value`, `actions[].stage` — where no query can see them, so
 * they are read out in PHP. There are tens of rules at most, never thousands,
 * so a whole-table scan here costs less than the indexes it would take to avoid
 * one.
 */
class TaxonomyReferences
{
    /**
     * @return array{leads: int, history: int, rules: list<string>}
     */
    public static function forStage(string $key): array
    {
        return [
            'leads' => Lead::withTrashed()->where('stage', $key)->count(),
            /*
             | Soft-deleted leads counted too, and history counted separately
             | from them. A trashed lead can be restored, and a completed to-do
             | naming this stage is a row on the Completed tab and a number on
             | three dashboard cards whichever way its lead is filed.
             */
            'history' => Todo::where('outcome_stage', $key)->count(),
            'rules' => self::rulesNaming($key, ['stage']),
        ];
    }

    /**
     * @return array{leads: int, history: int, rules: list<string>}
     */
    public static function forSource(string $key): array
    {
        return [
            'leads' => Lead::withTrashed()->where('source', $key)->count(),
            // `todos` records the stage a lead was moved to and never the source
            // it came from, so a source has no history of its own to protect
            'history' => 0,
            'rules' => self::rulesNaming($key, ['source']),
        ];
    }

    /**
     * The rules themselves that mention a stage, for the decision about what
     * deleting it does to them — see PipelineController::destroyStage().
     *
     * @param  list<string>  $fields
     * @return Collection<int, AutomationRule>
     */
    public static function rulesNamingStage(string $key, array $fields = ['stage']): Collection
    {
        return AutomationRule::query()
            ->get()
            ->filter(fn (AutomationRule $rule) => self::ruleNames($rule, $key, $fields))
            ->values();
    }

    /**
     * The names of the rules that mention this key, so the screen can say
     * "Auto-assign broker leads" rather than "3 rules".
     *
     * @param  list<string>  $fields  the condition fields whose values are keys
     *                                of this vocabulary — 'stage' or 'source'
     * @return list<string>
     */
    private static function rulesNaming(string $key, array $fields): array
    {
        return self::rulesNamingStage($key, $fields)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $fields
     */
    private static function ruleNames(AutomationRule $rule, string $key, array $fields): bool
    {
        // "when a lead moves to X" — the trigger's own setting
        if (in_array('stage', $fields, true)
            && (string) $rule->triggerSetting('stage', '') === $key) {
            return true;
        }

        // "...and its source is X" — a condition on one of this vocabulary's
        // fields. `value` can be a single key or a list of them, depending on
        // the operator the rule was written with.
        foreach ($rule->conditionList() as $condition) {
            if (! in_array($condition['field'] ?? null, $fields, true)) {
                continue;
            }

            $value = $condition['value'] ?? null;

            if (in_array($key, is_array($value) ? $value : [$value], true)) {
                return true;
            }
        }

        // "...then move it to X" — an action that names a stage
        foreach ($rule->actionList() as $action) {
            if (in_array('stage', $fields, true) && ($action['stage'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }
}
