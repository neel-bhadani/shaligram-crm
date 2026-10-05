<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One automation rule.
 *
 * A rule is deliberately not expressive. One trigger from a fixed list, any
 * number of conditions ANDed together from a fixed list, one or more actions
 * from a fixed list run in order. No OR, no nesting, no expression language —
 * the person writing these runs a builder's office, and every bit of power
 * added here is a rule they cannot read back and confirm.
 *
 * Nothing on this model executes anything. The engine is
 * App\Services\Automation\RuleEngine, and it is the only caller.
 */
class AutomationRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trigger_config' => 'array',
        'project_id' => 'integer',
        'conditions' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
        'last_fired_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Set only on an Auto-send project override; null is "All projects". */
    public function project()
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function logs()
    {
        return $this->hasMany(AutomationLog::class, 'rule_id');
    }

    public function alerts()
    {
        return $this->hasMany(Alert::class, 'rule_id');
    }

    /* ---------------- reading the rule ---------------- */

    /** The whole trigger: its key plus whatever it was configured with. */
    public function triggerSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->trigger_config, $key, $default);
    }

    /** @return array<int, array<string, mixed>> */
    public function actionList(): array
    {
        return array_values($this->actions ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function conditionList(): array
    {
        return array_values($this->conditions ?? []);
    }

    /** Does this rule carry an action of this type? */
    public function hasAction(string $type): bool
    {
        foreach ($this->actionList() as $action) {
            if (($action['type'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * A rule that changes a stage AND fires on a stage change is the shape that
     * can ping-pong: it moves a lead, the move fires the stage-changed trigger,
     * and another rule — or this one — moves it back.
     *
     * Loop protection stops it happening more than three times, so this is not
     * a refusal. It is what the builder shows at save time, before the rule can
     * fire, because "your automation stopped itself" read after the fact is a
     * much worse way to learn this.
     */
    public function couldLoop(): bool
    {
        return $this->trigger === 'stage_changed' && $this->hasAction('change_stage');
    }

    /* ---------------- scopes ---------------- */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrigger($query, string $trigger)
    {
        return $query->where('trigger', $trigger);
    }
}
