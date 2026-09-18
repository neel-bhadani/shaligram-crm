<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Todo;
use App\Support\CrmTaxonomy;
use Illuminate\Support\Arr;

/**
 * The only writer of `lead_activities`.
 *
 * LeadFollowUpService and LeadController both call this, and neither opens a
 * transaction for it: every method here is called from inside the transaction
 * of the change being described, so the row commits with that change or rolls
 * back with it. Nothing here may be called after a commit.
 *
 * The actor is always passed in, never read from the session. The follow-up
 * service knows when automation is acting (asSystem()) and passes null then;
 * reading Auth here would hand the rule's run to whoever happened to be
 * signed in.
 *
 * ORDER MATTERS. A head row is written before anything else the action does,
 * and its children after it. LeadTimeline groups children under the head
 * before them by id, and decides which old to-dos predate this table by the
 * first row's timestamp — a head written after the to-do it describes could
 * let that to-do show a second time.
 */
class LeadActivityRecorder
{
    /** Columns a lead-form save touches that are bookkeeping, not an edit. */
    private const NOT_EDITS = ['created_at', 'updated_at', 'stage_changed_at', 'last_activity_at'];

    /**
     * A lead was created: the stage it arrived at, and what it arrived with.
     *
     * The creator is the lead's own `created_by` — null for an imported lead,
     * which nobody typed in.
     */
    public function created(Lead $lead): void
    {
        $this->write($lead, $lead->created_by, LeadActivity::Created, to: $lead->stage);

        foreach (['source', 'project_id', 'channel_partner_id', 'assigned_to', 'booked_unit', 'reason'] as $field) {
            if (filled($lead->{$field})) {
                $this->write($lead, $lead->created_by, LeadActivity::Detail, $field, to: $lead->{$field});
            }
        }
    }

    /**
     * Whatever the lead's last save() changed, one row per field.
     *
     * Reads the model's own change set, so it must be called straight after
     * the save and before anything else saves the same instance.
     *
     * @param  list<string>  $except  fields that belong to another action's
     *                                entry — the unit of a booking made on the
     *                                same form, say
     */
    public function edits(Lead $lead, ?int $userId, array $except = []): void
    {
        $previous = $lead->getPrevious();

        foreach (Arr::except($lead->getChanges(), [...self::NOT_EDITS, ...$except]) as $field => $value) {
            $this->write($lead, $userId, LeadActivity::FieldUpdated, $field, $previous[$field] ?? null, $value);
        }
    }

    /**
     * The stage is about to move without a call being logged. Called before
     * the move is written, so the lead still holds the stage it is leaving.
     */
    public function stageChanging(Lead $lead, ?int $userId, string $to, string $remark): void
    {
        $this->write($lead, $userId, LeadActivity::StageChanged, 'stage', $lead->stage, $to, $remark);
    }

    /**
     * A follow-up is about to be completed. Called before the to-do is closed
     * and before the stage moves, so the lead still holds the stage it is
     * leaving. `field` is the follow-up's type: a call, a site visit.
     */
    public function followUpCompleting(Lead $lead, Todo $todo, ?int $userId, string $to, string $remark): void
    {
        $this->write($lead, $userId, LeadActivity::FollowUpCompleted, $todo->type, $lead->stage, $to, $remark);
    }

    /**
     * What a booking was for, or why a lead was lost. Called after the stage
     * has been applied, so these are the values that were actually saved.
     */
    public function outcome(Lead $lead, ?int $userId): void
    {
        $field = match ($lead->stage) {
            'booking_done' => 'booked_unit',
            'lost' => 'reason',
            default => null,
        };

        if ($field && filled($lead->{$field})) {
            $this->write($lead, $userId, LeadActivity::Detail, $field, to: $lead->{$field});
        }
    }

    /**
     * A follow-up was booked.
     *
     * @param  bool  $onItsOwn  true when booking it was the whole action; false
     *                          when it is the next task of a call, a stage
     *                          change or a new lead
     */
    public function followUp(Todo $todo, ?int $userId, bool $onItsOwn): void
    {
        $this->write(
            $todo->lead_id,
            $userId,
            $onItsOwn ? LeadActivity::FollowUpScheduled : LeadActivity::NextFollowUp,
            $todo->type,
            to: $todo->scheduled_at->toDateTimeString(),
            remark: $todo->remarks,
        );
    }

    /**
     * The lead changed hands.
     *
     * @param  bool  $handover  true when reaching the handover stage moved it,
     *                          which makes this part of that stage change
     */
    public function reassigned(Lead $lead, ?int $userId, ?int $from, int $to, bool $handover): void
    {
        $this->write(
            $lead,
            $userId,
            $handover ? LeadActivity::HandedOver : LeadActivity::Reassigned,
            'assigned_to',
            $from,
            $to,
            $handover
                ? 'Handed over on reaching '.CrmTaxonomy::stageLabel($lead->stage).'.'
                : null,
        );
    }

    private function write(
        Lead|int $lead,
        ?int $userId,
        string $action,
        ?string $field = null,
        mixed $from = null,
        mixed $to = null,
        ?string $remark = null,
    ): void {
        LeadActivity::create([
            'lead_id' => $lead instanceof Lead ? $lead->id : $lead,
            'user_id' => $userId,
            'action' => $action,
            'field' => $field,
            'from_value' => $from === null ? null : (string) $from,
            'to_value' => $to === null ? null : (string) $to,
            'remark' => $remark,
        ]);
    }
}
