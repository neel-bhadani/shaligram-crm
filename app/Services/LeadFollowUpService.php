<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Todo;
use App\Models\User;
use App\Services\Automation\RuleEngine;
use App\Support\CrmTaxonomy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every stage change in the application goes through this class.
 * Nothing else may write leads.stage directly.
 *
 * Follow-ups are scheduled by hand. The user types the date, the type and an
 * optional note on the form they are already filling in — the add-lead form or
 * the log-call modal — and this class saves that datetime exactly as it was
 * entered. There is no interval table, no retry ladder and no working-hours
 * clamp: a time a person chose is a time a person chose, and moving it would be
 * second-guessing them.
 *
 * What has not changed is who owns the rules. This is still the only place that
 * writes a pending to-do, still one transaction per change, and still the
 * keeper of "an open lead has exactly one pending to-do".
 *
 * ---------------------------------------------------------------------------
 * Automation hangs off this class, and off nothing else.
 * ---------------------------------------------------------------------------
 *
 * Three things happen to a lead that a rule can be triggered by: it is created,
 * its stage changes, it changes hands. All three happen here, so this is where
 * they are announced — one call to RuleEngine, from one file, rather than a
 * dispatch scattered across LeadController, TodoController and
 * IncomingLeadService, which would guarantee the fourth caller forgot.
 *
 * Two details of HOW they are announced are load-bearing:
 *
 * AFTER THE COMMIT. Triggers are collected during an operation and fired once
 * it has committed — see operation() and flushTriggers(). A rule that ran
 * inside the user's transaction could roll back the user's own save because
 * somebody misconfigured an action, and it would see a half-finished lead: the
 * stage moved but the follow-up not yet written. Firing afterwards means every
 * rule looks at a lead that is whole and an invariant that already holds.
 *
 * AS THE SYSTEM. When a rule calls back into this class, asSystem() blanks the
 * actor for the duration, so the to-do it writes has `created_by = null`. The
 * admin who wrote the rule three weeks ago did not make this call, and a
 * history that says they did is a history that gets somebody blamed.
 *
 * ---------------------------------------------------------------------------
 * The activity timeline is written from here too, inside the same transaction.
 * ---------------------------------------------------------------------------
 *
 * Every public operation below records what it did through
 * LeadActivityRecorder, as one head row followed by the rows for what it
 * did along the way. Recording changes nothing about the operation itself: it
 * reads what the operation already decided and writes it down, and it rolls
 * back with everything else if the operation fails.
 */
class LeadFollowUpService
{
    public function __construct(
        private LeadAssignmentService $assignment,
        private LeadActivityRecorder $activities,
    ) {}

    /**
     * Triggers waiting for the current operation to commit.
     *
     * @var array<int, array{trigger: string, lead: Lead, context: array<string, mixed>}>
     */
    private array $pending = [];

    /** How deep we are inside operation(). Triggers fire when it reaches zero. */
    private int $operationDepth = 0;

    /** How deep we are inside asSystem(). Non-zero means "no human did this". */
    private int $systemDepth = 0;

    /**
     * Called when a lead is created. Gives it its first task from the date the
     * user picked on the form, because an open lead must never exist without a
     * pending to-do.
     *
     * $when is null only when the lead arrived at a terminal stage — booked or
     * lost on the way in, nothing left to follow up. The form hides the three
     * fields in that case and LeadRequest stops requiring them, so a null here
     * means "no task wanted" rather than "the user forgot".
     */
    public function onLeadCreated(
        Lead $lead,
        ?Carbon $when = null,
        ?string $type = null,
        ?string $remarks = null
    ): void {
        $this->operation(function () use ($lead, $when, $type, $remarks) {
            // first, before the history row below — see LeadActivityRecorder
            $this->activities->created($lead);

            /*
             | Added at anything but `fresh` — a backfill. Someone is typing in
             | a lead that has already been called, already visited, already
             | booked or already lost, and every one of those is an event that
             | happened: the three history cards and "Stage changes in this
             | range" read `todos.outcome_stage`, so a transition with no row
             | did not happen as far as the dashboard is concerned.
             |
             | This used to fire only for the terminal stages, which left one
             | visible hole. A lead added straight at "Site visit done" showed
             | up in the Leads-by-stage chart under Site visit done and was
             | never counted by the Site visits done card, in any range, ever —
             | a card and a chart contradicting each other on the same lead.
             | Booking and Lost were covered only because they happen to be the
             | terminal pair.
             |
             | `fresh` is the one stage that is genuinely not a transition: the
             | lead has just arrived and nothing has been done to it yet.
             | Recording that would fabricate a transition the lead never made
             | and put every new lead on a bar of the stage-changes chart.
             */
            if ($lead->stage !== 'fresh') {
                $this->recordStageChange($lead, $lead->stage, 'Lead added at this stage.');
            }

            if ($when && ! $lead->isTerminal()) {
                $this->activities->followUp(
                    $this->createTodo($lead, $when, $type, $remarks),
                    $this->actorId(),
                    onItsOwn: false,
                );
            }

            /*
             | `lead_created` and not also `lead_assigned`. Every lead is
             | assigned to somebody at birth, so firing both would mean two
             | dispatches for one event and every "when a lead is given to
             | someone" rule running on every new lead. `lead_assigned` means
             | the lead CHANGED HANDS, which is what the trigger's own help
             | text on the builder says.
             */
            $this->queueTrigger('lead_created', $lead);
        });
    }

    /**
     * Complete a task, move the stage, save the next task the user picked.
     * All of it in one transaction: if the new task fails to save,
     * the stage change rolls back too, so a lead can never end up
     * with no open task and disappear from everyone's list.
     *
     * @return array{handed_over_to: ?string} what was decided without the user
     *                                        asking — see noticeFor()
     */
    public function complete(
        Todo $todo,
        string $stage,
        string $remarks,
        ?Carbon $nextAt = null,
        ?string $nextType = null,
        ?string $nextRemarks = null,
        array $extra = []
    ): array {
        return $this->operation(function () use ($todo, $stage, $remarks, $nextAt, $nextType, $nextRemarks, $extra) {

            $lead = Lead::whereKey($todo->lead_id)->lockForUpdate()->firstOrFail();

            // before the to-do closes and the stage moves: the lead still
            // holds the stage it is leaving
            $this->activities->followUpCompleting($lead, $todo, $this->actorId(), $stage, $remarks);

            $todo->update([
                'status' => 'completed',
                'remarks' => $remarks,
                'outcome_stage' => $stage,
                'completed_at' => now(),
                'completed_by' => $this->actorId(),
            ]);

            // read before applyStage() overwrites it — schedule() needs the
            // stage the lead is leaving to tell whether this crosses a desk
            $fromStage = $lead->stage;

            $this->applyStage($lead, $stage, $extra);
            $this->activities->outcome($lead, $this->actorId());

            $outcome = $this->schedule($lead, $fromStage, $stage, $nextAt, $nextType, $nextRemarks, $todo->id);

            $this->queueTrigger('stage_changed', $lead, ['stage' => $stage]);

            return $outcome;
        });
    }

    /**
     * Stage change made from the lead form rather than from a call.
     *
     * @return array{handed_over_to: ?string}
     */
    public function changeStage(
        Lead $lead,
        string $stage,
        array $extra = [],
        ?Carbon $nextAt = null,
        ?string $nextType = null,
        ?string $nextRemarks = null,
        ?string $historyRemark = null
    ): array {
        return $this->operation(function () use ($lead, $stage, $extra, $nextAt, $nextType, $nextRemarks, $historyRemark) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();

            $remark = $historyRemark ?? 'Stage changed from the lead form.';

            // before the stage moves: the lead still holds the stage it is
            // leaving, and this row has to precede the history row below
            $this->activities->stageChanging($lead, $this->actorId(), $stage, $remark);

            // read before applyStage() overwrites it — schedule() needs the
            // stage the lead is leaving to tell whether this crosses a desk
            $fromStage = $lead->stage;

            $this->applyStage($lead, $stage, $extra);
            $this->activities->outcome($lead, $this->actorId());

            // complete() records its transition on the to-do being closed; this
            // path has no such to-do, so without this the change leaves no
            // history at all
            $this->recordStageChange($lead, $stage, $remark);

            $outcome = $this->schedule($lead, $fromStage, $stage, $nextAt, $nextType, $nextRemarks);

            $this->queueTrigger('stage_changed', $lead, ['stage' => $stage]);

            return $outcome;
        });
    }

    /**
     * Give a lead to somebody.
     *
     * The only place `leads.assigned_to` is written outside of lead creation,
     * and therefore the only place `lead_assigned` can be announced from. The
     * handover below uses it, and so do automation's two assign actions — which
     * is what stops the engine writing the column itself and skipping the
     * pending to-do that has to move with it.
     *
     * `assigned_role` is `$to`'s own role, always, and there is no parameter
     * to say otherwise. It used to accept one, and an admin taking a lead was
     * stamped `telecaller` — a label naming a desk nobody was at, which is
     * exactly what hid QA-REPORT MIN-13. The handover asks
     * LeadAssignmentService who holds a lead, not this label, so nothing needs
     * it to lie.
     *
     * @return bool whether anything actually changed
     */
    public function assign(Lead $lead, User $to): bool
    {
        if ($lead->assigned_to === $to->id) {
            return false;
        }

        /*
         | Already inside another operation means handover() called this: the
         | lead is moving desks because of the stage it just reached, and the
         | timeline shows that as part of that stage change. Called from the top
         | — automation's assign actions — it is a reassignment in its own
         | right. Read before operation() raises the depth for this call.
         */
        $isHandover = $this->operationDepth > 0;

        return (bool) $this->operation(function () use ($lead, $to, $isHandover) {
            /*
             | Lock the row, then write the instance the CALLER is holding.
             |
             | Not `$lead = Lead::...->firstOrFail()`. schedule() hands its own
             | lead down to handover() and then reads `assigned_to` back off it
             | to decide who the new follow-up belongs to; rebinding the name
             | here would leave that outer object stale, and every handed-over
             | lead would book its site visit onto the telecaller who just gave
             | it away.
             */
            $locked = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();

            $lead->assigned_to = $to->id;
            $lead->assigned_role = $to->role;
            $lead->last_activity_at = now();
            $lead->save();

            // from the locked row: who actually held it, whatever the caller's
            // copy says
            $this->activities->reassigned($lead, $this->actorId(), $locked->assigned_to, $to->id, $isHandover);

            /*
             | The task goes with the lead. A pending to-do left on the previous
             | owner's Follow-ups page is a handover the application never
             | carried out: they see work for a lead they no longer own, and the
             | new owner sees nothing to do.
             */
            Todo::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->update(['assigned_to' => $to->id]);

            $this->queueTrigger('lead_assigned', $lead);

            return true;
        });
    }

    /**
     * Book the next follow-up on a lead without touching its stage.
     *
     * Exists for automation's create_follow_up action, and deliberately routes
     * through the same createTodo() everything else uses: an action that
     * inserted a `todos` row itself would be the second writer of pending
     * to-dos in the application, and "one pending to-do per lead" would last
     * about a fortnight.
     *
     * A terminal lead is refused rather than skipped quietly at the call site,
     * so the reason lands in the activity log: booked and lost leads have no
     * next task by design, and a rule that keeps trying to give them one is a
     * rule the admin should see failing.
     *
     * @return bool false when the lead is terminal and there is nothing to book
     */
    public function scheduleFollowUp(
        Lead $lead,
        Carbon $when,
        ?string $type = null,
        ?string $remarks = null
    ): bool {
        return (bool) $this->operation(function () use ($lead, $when, $type, $remarks) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if ($lead->isTerminal()) {
                return false;
            }

            // one pending to-do per lead: the replacement arrives in the same
            // transaction as the cancellation, so there is never a moment where
            // an open lead has none
            Todo::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);

            $this->activities->followUp(
                $this->createTodo($lead, $when, $type, $remarks),
                $this->actorId(),
                onItsOwn: true,
            );

            return true;
        });
    }

    /**
     * Turns the outcome above into the one line worth telling the user.
     * Pure string building — the controller owns the flash, this class
     * never touches the session.
     */
    public function noticeFor(array $outcome): ?string
    {
        if ($outcome['handed_over_to'] ?? null) {
            return "Lead handed over to {$outcome['handed_over_to']}.";
        }

        return null;
    }

    /* ---------------------------------------------------------- */
    /*  Who is doing this */
    /* ---------------------------------------------------------- */

    /**
     * Run $work with no human attached to it.
     *
     * Automation is a system actor. The to-dos and history rows it writes carry
     * `created_by = null` and `completed_by = null`, which reads on screen as
     * "Automation" and not as the name of whoever happened to be signed in when
     * a rule fired. That distinction is not cosmetic: the admin who wrote a
     * rule in September should not appear in November's audit trail as the
     * person who moved forty leads.
     */
    public function asSystem(callable $work): mixed
    {
        $this->systemDepth++;

        try {
            return $work();
        } finally {
            $this->systemDepth--;
        }
    }

    /** Null while automation is acting. See asSystem(). */
    private function actorId(): ?int
    {
        return $this->systemDepth > 0 ? null : Auth::id();
    }

    /* ---------------------------------------------------------- */
    /*  Announcing what happened */
    /* ---------------------------------------------------------- */

    /**
     * One unit of work, and the triggers it produced.
     *
     * The transaction is the same one this class always had. What is new is the
     * bookkeeping around it: nested calls — automation changing a stage from
     * inside an assignment from inside a completion — share the outermost
     * commit, and the triggers all fire once, after it, in the order they
     * happened.
     */
    private function operation(callable $work): mixed
    {
        $this->operationDepth++;

        try {
            $result = DB::transaction($work);
        } finally {
            $this->operationDepth--;
        }

        if ($this->operationDepth === 0) {
            $this->flushTriggers();
        }

        return $result;
    }

    private function queueTrigger(string $trigger, Lead $lead, array $context = []): void
    {
        $this->pending[] = ['trigger' => $trigger, 'lead' => $lead, 'context' => $context];
    }

    /**
     * Hand everything that happened to the rule engine, now that it has
     * committed.
     *
     * The list is taken and cleared before it is walked, because a rule is very
     * likely to come straight back into this class — a change_stage action is a
     * changeStage() call — and appending to an array that is being iterated is
     * how a foreach quietly becomes an infinite loop. The engine's own loop
     * protection is what bounds the recursion; this only stops it corrupting
     * the queue.
     */
    private function flushTriggers(): void
    {
        while ($this->pending !== []) {
            $batch = $this->pending;
            $this->pending = [];

            foreach ($batch as $event) {
                app(RuleEngine::class)->dispatch(
                    $event['trigger'],
                    $event['lead'],
                    $event['context'],
                );
            }
        }
    }

    /* ---------------------------------------------------------- */

    private function applyStage(Lead $lead, string $stage, array $extra = []): void
    {
        $lead->stage = $stage;
        $lead->stage_changed_at = now();
        $lead->last_activity_at = now();

        /*
         | Still counted, and still reset by any other outcome, because the
         | to-do rows and the follow-up panels print "attempt 3" beside a lead
         | nobody can reach. Nothing acts on the number any more: the ladder
         | that used to close a lead at five failed attempts went with the
         | automatic scheduling, so a lead is lost only when a user says so.
         */
        $lead->not_connected_count = $stage === 'not_connected'
            ? $lead->not_connected_count + 1
            : 0;

        if ($stage === 'lost') {
            $lead->reason = $extra['reason'] ?? $lead->reason;
        }

        if ($stage === 'booking_done') {
            $lead->booked_unit = $extra['booked_unit'] ?? $lead->booked_unit;
            $lead->booking_date = $extra['booking_date'] ?? now()->toDateString();
        }

        $lead->save();
    }

    /**
     * Cancel what is pending, hand over if this move crosses a desk, and
     * write the task the user asked for.
     *
     * @return array{handed_over_to: ?string}
     */
    private function schedule(
        Lead $lead,
        string $fromStage,
        string $stage,
        ?Carbon $when,
        ?string $type,
        ?string $remarks,
        ?int $fromTodo = null
    ): array {
        $outcome = ['handed_over_to' => null];
        $terminal = CrmTaxonomy::isTerminal($stage);

        /*
         | Exactly one pending task per lead — so the one it is holding goes
         | when a closed lead should have none, and when a replacement date has
         | arrived to take its place. Not otherwise.
         |
         | That last clause is the whole reason this is a condition rather than
         | the unconditional cancel it used to be. The scheduler always had an
         | answer, so cancelling first and creating second could never leave a
         | gap. Now the date comes from a form, and a stage change that carries
         | no date — editing a lead's stage without touching its follow-up —
         | must leave the existing task standing rather than cancel it and put
         | nothing back. An open lead with no pending to-do is invisible on
         | every list in the application and would never be called again.
         */
        if ($terminal || $when) {
            Todo::where('lead_id', $lead->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }

        /*
         | Handover — not one named stage any more, and not any role change
         | either. `lead_stages.owner_role` already says who works each
         | stage, and the desk only ever moves one direction: telecaller to
         | salesperson. `fresh` to `site_visit_scheduled` crosses it, and so
         | does `not_connected` straight to `site_visit_done` — a call logged
         | as "already visited" that used to leave the lead on the telecaller
         | forever, because the old check only recognised the one stage named
         | in `crm.handover_stage`.
         |
         | Moving between two telecaller stages (`fresh` to `connected`) or
         | two salesperson stages (`in_discussion` to `booking_done`) is not a
         | handover — the lead stays exactly where it was, with no round robin
         | re-run. Nor is landing on a terminal stage, or moving a stage the
         | admin has routed backwards onto the telecaller desk: its owner_role
         | is `null` or `telecaller`, neither of which this checks for, so the
         | lead stays with whoever was already holding it — see
         | CrmTaxonomy::ownerRoleFor().
         |
         | `crm.handover_stage` itself is untouched and still read directly by
         | LeadAssignmentService::stagesPastHandover() and routingWarning(),
         | which measure positions in the pipeline rather than role changes.
         */
        $fromRole = CrmTaxonomy::ownerRoleFor($fromStage);
        $toRole = CrmTaxonomy::ownerRoleFor($stage);

        if ($fromRole === 'telecaller' && $toRole === 'salesperson') {
            $outcome['handed_over_to'] = $this->handover($lead, $stage);
        }

        if ($terminal || ! $when) {
            return $outcome;
        }

        $this->activities->followUp(
            $this->createTodo($lead, $when, $type, $remarks, $fromTodo),
            $this->actorId(),
            onItsOwn: false,
        );

        return $outcome;
    }

    /**
     * Write a stage transition into the history.
     *
     * The history is completed to-dos: `outcome_stage` is where the lead went,
     * `completed_at` is when. Everything that asks "how many bookings this
     * week" reads that, so a transition with no row is a transition that never
     * happened as far as the dashboard is concerned.
     *
     * complete() already writes one — it closes the to-do the user was working
     * on and stamps the outcome onto it — so this is only for the paths that
     * have no to-do to close: the lead form, and a lead created straight into a
     * stage other than `fresh`.
     *
     * The row is `completed`, so it never becomes someone's task and cannot
     * affect "every open lead has a pending to-do". It does appear on the
     * Completed tab, which is the point: the remark says where it came from.
     */
    private function recordStageChange(Lead $lead, string $stage, string $remarks): void
    {
        Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $this->actorId(),
            'scheduled_at' => now(),
            'type' => 'call',
            'status' => 'completed',
            'remarks' => $remarks,
            'outcome_stage' => $stage,
            'completed_at' => now(),
            'completed_by' => $this->actorId(),
        ]);
    }

    /**
     * The next task, from what the user typed.
     *
     * `$when` is used verbatim. It is a datetime a person chose — the customer
     * is free on Sunday evening or they are not — and there is nothing left in
     * the application that would move it.
     */
    private function createTodo(
        Lead $lead,
        Carbon $when,
        ?string $type,
        ?string $remarks,
        ?int $fromTodo = null
    ): Todo {
        return Todo::create([
            'lead_id' => $lead->id,
            'assigned_to' => $lead->assigned_to,
            'created_by' => $this->actorId(),
            'scheduled_at' => $when,
            'type' => $type ?? 'call',
            'status' => 'pending',
            'remarks' => $remarks,
            'rescheduled_from_id' => $fromTodo,
        ]);
    }

    /**
     * Move the lead to whoever LeadAssignmentService says a lead at `$stage`
     * belongs to — the same answer, from the same code, that LeadController
     * gets for a lead created at that stage. Two places deciding this is how a
     * created lead and a handed-over one end up on different desks.
     *
     * A telecaller's lead takes the next turn from its project's salesperson
     * round robin — the same per-project turns, the same fallback and the same
     * admin alert as a lead created at this stage; a lead an admin was holding
     * because no telecaller was active goes the same way, which a check on
     * `assigned_role === 'telecaller'` used to miss; a salesperson's lead stays
     * put. Nobody on the desk, or `handover_mode` set to `admin`, and it stays
     * put too.
     *
     * Already inside this class's transaction, so the turn is taken under the
     * project lock and rolls back with the stage change if anything fails.
     *
     * @return string|null the name the lead went to, or null if it stayed put
     */
    private function handover(Lead $lead, string $stage): ?string
    {
        $holder = $lead->assigned_to ? User::find($lead->assigned_to) : null;
        $next = $this->assignment->ownerFor($stage, $holder, $lead->project_id);

        if (! $next || $next->id === $lead->assigned_to) {
            return null;
        }

        /*
         | assign() rather than four lines of column writing, because it also
         | moves the pending to-do and announces `lead_assigned`. That
         | announcement is why the handover no longer writes the lead itself:
         | the trigger has to be raised from one place or a rule watching for
         | handovers would work when automation reassigned a lead and not when
         | the application did.
         |
         | The new task, if there is one, is created after this returns and
         | reads $lead->assigned_to, so it lands on the salesperson by itself.
         */
        $this->assign($lead, $next);

        return $next->display_name;
    }
}
