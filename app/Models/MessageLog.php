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
        'params' => 'array',
    ];

    /** Still somewhere between the rule and Meta. */
    public const IN_FLIGHT = ['queued', 'sending', 'sent'];

    public function whatsappTemplate()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    /**
     * What happened, in words that do not overclaim.
     *
     * `sent` is Meta accepting the message and handing back an id. Delivered
     * and read need a webhook this CRM does not have, so neither word appears.
     */
    public function outcome(): string
    {
        return match ($this->status) {
            'sent' => 'Accepted by Meta'.($this->wamid ? " ({$this->wamid})" : ''),
            'opened' => 'Opened in WhatsApp by '.($this->user?->display_name ?? 'somebody').' — not confirmed sent',
            'queued' => $this->mode === 'api' ? 'Waiting to be sent by API' : 'Waiting for somebody to open it',
            'sending' => 'Being sent',
            'failed' => 'Failed'.($this->error_code ? " (error {$this->error_code})" : ''),
            'skipped' => 'Not sent',
            'cancelled' => 'Cancelled',
            default => $this->status,
        };
    }

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
