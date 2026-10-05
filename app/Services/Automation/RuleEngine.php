<?php

namespace App\Services\Automation;

use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Services\AlertService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place rules are evaluated.
 *
 * Everything that can set a rule off calls dispatch() and nothing else knows
 * how a rule works: not the controllers, not the jobs, not the webhook. That is
 * a deliberate choke point. Rule evaluation spread across five controllers is
 * five places to forget the loop guard, five places to get the logging wrong,
 * and five different answers to "why did this fire".
 *
 * Events arrive from LeadFollowUpService, after its transaction has committed.
 * Time triggers arrive from the hourly `automation:run` command, which uses
 * ConditionMatcher to find its leads and then calls run() on each — the same
 * run() an event uses, with the same guards and the same log lines.
 *
 * WHAT A FIRING LOOKS LIKE IN THE LOG
 *
 *   rule_fired / fired      the rule matched and its actions are about to run.
 *                           Written FIRST, outside the actions' transaction, so
 *                           that it survives an action failure — it is what the
 *                           one-hour cooldown reads, and a failing rule is
 *                           precisely the one that must not be allowed to retry
 *                           in a tight loop.
 *   <action> / success      an action did its work.
 *   <action> / skipped      it could not, for a reason that is not a fault.
 *   <action> / failed       it threw. The rule's remaining actions are
 *                           abandoned and everything it had already done rolls
 *                           back.
 *   suppressed / loop_guard
 *   suppressed / cooldown   it matched and was held back. Admins are alerted.
 */
class RuleEngine
{
    /** @see withoutRules() */
    private bool $suspended = false;

    public function __construct(
        private ConditionMatcher $matcher,
        private LoopGuard $guard,
        private ActionRunner $runner,
        private AutoSend $autoSend,
        private AlertService $alerts,
    ) {}

    /**
     * Something happened to a lead. Run whatever watches for it.
     *
     * Never throws. A rule is a configuration mistake waiting to happen, and a
     * configuration mistake must not take down the save that set it off — the
     * user typed a lead, the lead is saved, and a broken rule is a line in the
     * activity log rather than a 500.
     *
     * @param  array<string, mixed>  $context  what the trigger needs to be
     *                                         matched against — `['stage' => 'site_visit_done']` for a stage
     *                                         change. Conditions are about the lead; this is about the event.
     */
    public function dispatch(string $trigger, Lead $lead, array $context = []): void
    {
        if ($this->suspended) {
            return;
        }

        /*
         | The All-projects rules and this lead's project's Auto-send overrides,
         | in the same one query; which override replaces which default is
         | then settled in memory. A lead with no project gets the defaults.
         */
        $rules = AutomationRule::active()
            ->forTrigger($trigger)
            ->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', $lead->project_id))
            ->orderBy('id')
            ->get()
            ->filter(fn (AutomationRule $rule) => $this->triggerMatches($rule, $context));

        $rules = $this->autoSend->applyOverrides($rules->values());

        if ($rules->isEmpty()) {
            return;
        }

        /*
         | One chain for the whole dispatch, so two rules acting on the same
         | lead in response to one event count as two touches rather than one
         | each. Without this, "at most three touches per chain" would be "at
         | most three touches per rule" and the ping-pong would never stop.
         */
        $this->guard->enterChain();

        try {
            foreach ($rules as $rule) {
                $this->run($rule, $lead);
            }
        } finally {
            $this->guard->leaveChain();
        }
    }

    /**
     * Run one rule against one lead, guards and logging included.
     *
     * Public because the hourly command calls it directly: it has already
     * decided which rule and which leads, and going back through dispatch()
     * would make it re-answer a question it just answered.
     *
     * @return string fired | no_match | loop_guard | cooldown | failed
     */
    public function run(AutomationRule $rule, Lead $lead): string
    {
        if ($this->suspended) {
            return 'no_match';
        }

        if (! $this->matcher->matches($rule, $lead)) {
            // deliberately not logged. A rule is evaluated against every lead
            // that trips its trigger and matches a handful; logging the misses
            // would bury the firings under a hundred times their number.
            return 'no_match';
        }

        $this->guard->enterChain();

        try {
            if ($reason = $this->guard->verdict($rule, $lead)) {
                $this->suppress($rule, $lead, $reason);

                return $reason;
            }

            /*
             | Counted before the actions run, and outside their transaction.
             | The touch is what the chain cap reads and the log line is what
             | the cooldown reads, and both have to hold even if every action
             | below fails — otherwise a rule that throws would be free to throw
             | again immediately, for ever.
             */
            $this->guard->recordTouch($lead);
            $this->log($rule, $lead, 'rule_fired', 'fired');

            $rule->forceFill([
                'last_fired_at' => now(),
                'fire_count' => $rule->fire_count + 1,
            ])->save();

            return $this->runActions($rule, $lead);
        } finally {
            $this->guard->leaveChain();
        }
    }

    /**
     * All of a rule's actions, in order, in one transaction.
     *
     * All or nothing. A rule that assigned a lead to a salesperson and then
     * failed to create the follow-up would leave that salesperson holding an
     * open lead with nothing to do about it — invisible on the To-do page,
     * which is where their day comes from.
     *
     * The failure is logged AFTER the rollback, on purpose: a log line written
     * inside the transaction would be rolled back with everything else, and the
     * one thing that must survive a failed rule is the record that it failed.
     */
    private function runActions(AutomationRule $rule, Lead $lead): string
    {
        $outcomes = [];

        try {
            DB::transaction(function () use ($rule, $lead, &$outcomes) {
                foreach ($rule->actionList() as $action) {
                    $outcome = $this->runner->run($rule, $lead, $action);

                    $outcomes[] = [$action['type'] ?? 'unknown', $outcome];

                    if ($outcome['result'] === 'failed') {
                        throw new \RuntimeException($outcome['error'] ?? 'Action failed.');
                    }
                }
            });
        } catch (Throwable $e) {
            foreach ($outcomes as [$type, $outcome]) {
                // everything before the failure was rolled back with it, so it
                // is logged as abandoned rather than as the success it briefly
                // was
                $this->log($rule, $lead, $type, 'failed', 'Rolled back: '.$e->getMessage());
            }

            $this->log($rule, $lead, 'rule_failed', 'failed', $e->getMessage());

            return 'failed';
        }

        foreach ($outcomes as [$type, $outcome]) {
            $this->log($rule, $lead, $type, $outcome['result'], $outcome['error']);
        }

        return 'fired';
    }

    /* ---------------- trigger matching ---------------- */

    /**
     * Does this rule care about this particular event?
     *
     * Only `stage_changed` has anything to check — it watches one stage, and a
     * rule on "moves to Site visit done" must not run when a lead moves to
     * Lost. The time triggers never reach here: their leads are found by
     * ConditionMatcher, which applies the same configuration as a query.
     */
    private function triggerMatches(AutomationRule $rule, array $context): bool
    {
        if ($rule->trigger !== 'stage_changed') {
            return true;
        }

        $want = $rule->triggerSetting('stage');

        // a rule saved without a stage watches nothing rather than everything.
        // The builder will not save one, but a rule stored before a stage was
        // retired from config can end up here.
        return $want !== null && $want === ($context['stage'] ?? null);
    }

    /* ---------------- suppression ---------------- */

    /**
     * A rule was held back. Say so twice: once in the log, once to the admins.
     *
     * The alert is the part that matters. A suppressed rule looks, from the
     * outside, exactly like a rule that does not work: it is switched on, the
     * admin can see it matches, and nothing happens. Without being told, the
     * next thing they do is edit the rule that was never broken.
     *
     * AlertService deduplicates, so a rule fighting itself all afternoon
     * produces one alert per admin per day rather than one per bounce.
     */
    private function suppress(AutomationRule $rule, Lead $lead, string $reason): void
    {
        $this->log($rule, $lead, 'suppressed', $reason, $this->suppressionReason($reason, $rule));

        if (! config('automation.loop_protection.alert_admins', true)) {
            return;
        }

        $this->alerts->raiseMany(
            recipients: $this->alerts->admins(),
            type: 'automation_suppressed',
            title: "Automation held back: {$rule->name}",
            body: $this->suppressionReason($reason, $rule)
                .' Open the Activity tab on the Automation page to see what happened.',
            lead: $lead,
            severity: 'warning',
            rule: $rule,
            actionUrl: route('automation.index', ['tab' => 'activity']),
        );
    }

    private function suppressionReason(string $reason, AutomationRule $rule): string
    {
        $max = config('automation.loop_protection.max_touches_per_chain', 3);
        $cooldown = $this->guard->cooldownFor($rule);

        return $reason === 'loop_guard'
            ? "Automation had already changed this lead {$max} times in a row, so it stopped. "
                .'That usually means two rules are undoing each other.'
            : "This rule already ran on this lead within the last {$cooldown} minutes, "
                .'so it was not run again.';
    }

    /* ---------------- logging ---------------- */

    private function log(
        AutomationRule $rule,
        ?Lead $lead,
        string $action,
        string $result,
        ?string $error = null,
    ): void {
        AutomationLog::create([
            'rule_id' => $rule->id,
            'lead_id' => $lead?->id,
            'action' => $action,
            'result' => $result,
            'error' => $error,
            'fired_at' => now(),
        ]);
    }

    /* ---------------- switching it off ---------------- */

    /**
     * Run $work with automation asleep.
     *
     * For seeding and for the demo data: creating four hundred leads with rules
     * switched on would fire four hundred chains and take a minute a hundred
     * times over. Not a permission and not reachable from a request — it is a
     * tool for code that is building a database, and the Test button does not
     * need it because testing a rule never calls dispatch() in the first place.
     */
    public function withoutRules(callable $work): mixed
    {
        $was = $this->suspended;
        $this->suspended = true;

        try {
            return $work();
        } finally {
            $this->suspended = $was;
        }
    }
}
