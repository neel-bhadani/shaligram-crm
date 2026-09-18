<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recorded fact about a lead. Written once, never updated.
 *
 * A user action writes one HEAD row, and the things that action did along the
 * way are CHILD rows written straight after it in the same transaction: the
 * next follow-up a call booked, the handover a site visit caused, the unit a
 * booking was for. LeadTimeline folds every child into the head before it, so
 * one action is one line on the timeline.
 *
 * That grouping reads the ids, and it holds because every writer has the lead
 * row locked while it writes — LeadFollowUpService locks it, a lead-form edit
 * UPDATEs it first — so two actions on one lead never interleave their rows.
 */
class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    /* ---------------- heads: one per user action ---------------- */

    /** A lead came into existence. `to_value` is the stage it arrived at. */
    public const Created = 'created';

    /** One lead-form field changed. `field`, `from_value`, `to_value`. */
    public const FieldUpdated = 'field_updated';

    /** The stage moved without a call being logged — the lead form, or automation. `field` is `stage`. */
    public const StageChanged = 'stage_changed';

    /** A follow-up was completed. `field` is its type; `from_value` → `to_value` are the stages. */
    public const FollowUpCompleted = 'follow_up_completed';

    /** A follow-up booked on its own. `field` is the type, `to_value` the datetime. */
    public const FollowUpScheduled = 'follow_up_scheduled';

    /** The lead changed hands on its own. `from_value` → `to_value` are user ids. */
    public const Reassigned = 'reassigned';

    /* ---------------- children: part of the head before them ---------------- */

    /** The follow-up the action booked next. Same columns as FollowUpScheduled. */
    public const NextFollowUp = 'next_follow_up';

    /** The lead moved desks because of the stage it reached. User ids. */
    public const HandedOver = 'handed_over';

    /** A value the action set: source and project at creation, unit on booking, reason on loss. */
    public const Detail = 'detail';

    /** @var list<string> */
    public const CHILDREN = [self::NextFollowUp, self::HandedOver, self::Detail];

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isChild(): bool
    {
        return in_array($this->action, self::CHILDREN, true);
    }
}
