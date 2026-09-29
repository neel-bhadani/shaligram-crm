<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One message: what it said, who it went to, and how far it actually got.
 *
 * `status` is the honest part. A click-to-send message reaches `opened` and
 * stops — the browser handed the text to WhatsApp and nothing after that is
 * observable from here. Only the API path may write `sent`.
 */
class MessageLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function template()
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rule()
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    /** Still waiting for a person to pick it up. */
    public function scopeQueued($query)
    {
        return $query->where('status', 'queued');
    }

    /**
     * Only messages about leads this user is allowed to see.
     *
     * The queue is an admin screen, and an admin resolves see_all_leads — so in
     * practice this changes nothing today. It is here because the queue shows
     * lead names and mobile numbers, and the day somebody opens it up to sales
     * managers is not the day to remember that.
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $user->can_('see_all_leads')
            ? $query
            : $query->whereHas('lead', fn ($q) => $q->where('assigned_to', $user->id));
    }
}
