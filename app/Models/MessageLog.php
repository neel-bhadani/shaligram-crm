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
        'confirmed' => 'boolean',
        'sent_at' => 'datetime',
        'sending_started_at' => 'datetime',
        'checked_at' => 'datetime',
        'send_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'params' => 'array',
    ];

    /**
     * Said beside a `sent` row whose id was not recognised. Advice, not an
     * error: the id lookup is a guess, and the message may well have arrived.
     */
    public const UNCONFIRMED_ADVICE =
        'Check the 11za panel before resending — it may well have been delivered.';

    /**
     * Still somewhere between the rule and 11za — or may already have reached
     * the customer. `unknown` is here on purpose: a message that may have
     * arrived must not be sent again by a rule.
     */
    public const IN_FLIGHT = ['queued', 'sending', 'sent', 'unknown'];

    /**
     * Beside an `unknown` row. Where to look, before pressing either button:
     * a guess here marks a customer contacted who never was, or messages
     * them twice.
     */
    public const UNKNOWN_ADVICE =
        'The send was interrupted after it was handed to 11za, so it may or may not have reached the customer. '
        .'Look for it in the 11za panel\'s message log — this number, around this time — before deciding.';

    /**
     * What happened, in words that do not overclaim.
     *
     * `sent` is 11za accepting the message. With an id it is "Accepted by
     * 11za"; without one it is "Sent (unconfirmed)", because 11za said yes and
     * the id lookup — a guess at its response shape — found nothing. Delivered
     * and read need a webhook this CRM does not have, so neither word appears.
     */
    public function outcome(): string
    {
        return match ($this->status) {
            'sent' => $this->sentOutcome(),
            'opened' => 'Opened in WhatsApp by '.($this->user?->display_name ?? 'somebody').' — not confirmed sent',
            'queued' => match (true) {
                $this->isScheduled() => 'Scheduled for '.$this->send_at->format('j M, g:i a').' (India time)',
                $this->mode === 'api' => 'Waiting to be sent by API',
                default => 'Waiting for somebody to open it',
            },
            'sending' => 'Being sent',
            'unknown' => 'Outcome unknown — '.self::UNKNOWN_ADVICE,
            'failed' => 'Failed'.($this->error_code ? " (error {$this->error_code})" : ''),
            'skipped' => 'Not sent',
            'cancelled' => $this->cancelledOutcome(),
            default => $this->status,
        };
    }

    /**
     * "Cancelled by Ann on 5 Oct, 2:10 pm. Scheduled by Tara on 5 Oct,
     * 11:00 am for 5 Oct, 3:30 pm." Who stopped it, and who set it up.
     */
    private function cancelledOutcome(): string
    {
        $at = fn ($time) => $time->format('j M, g:i a');

        $outcome = 'Cancelled'
            .($this->cancelled_by ? ' by '.($this->canceller?->display_name ?? 'a removed user') : '')
            .($this->cancelled_at ? ' on '.$at($this->cancelled_at) : '');

        if ($this->send_at) {
            $outcome .= '. Scheduled'
                .($this->user ? " by {$this->user->display_name}" : '')
                .($this->scheduled_at ? ' on '.$at($this->scheduled_at) : '')
                .' for '.$at($this->send_at);
        }

        return $outcome;
    }

    /** Waiting for the time a person chose. Cancellable until the worker claims it. */
    public function isScheduled(): bool
    {
        return $this->status === 'queued' && $this->send_at !== null && $this->send_at->isFuture();
    }

    /** An API send 11za answered 2xx to, without a message id the CRM recognised. */
    public function isUnconfirmed(): bool
    {
        return $this->status === 'sent' && $this->mode === 'api' && ! $this->confirmed && $this->check_result !== 'delivered';
    }

    /** The words for a `sent` row, which has the most ways to have got there. */
    private function sentOutcome(): string
    {
        if ($this->check_result === 'delivered') {
            return 'Sent — '.($this->checker?->display_name ?? 'somebody').' found it in the 11za log on '
                .$this->checked_at?->format('j M, g:i a');
        }

        $outcome = $this->isUnconfirmed()
            ? 'Sent (unconfirmed) — 11za accepted it but no message id was recognised. '.self::UNCONFIRMED_ADVICE
            : 'Accepted by 11za'.($this->provider_message_id ? " ({$this->provider_message_id})" : '');

        return $outcome.($this->check_result === 'not_sent' ? $this->resentNote() : '');
    }

    /** " — sent again after Ann found no trace of it in the 11za log (4 Oct)" */
    private function resentNote(): string
    {
        return ' — sent again after '.($this->checker?->display_name ?? 'somebody')
            .' found no trace of the first attempt in the 11za log'
            .($this->checked_at ? ' ('.$this->checked_at->format('j M').')' : '');
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

    /** Who cancelled it. `user` stays whoever sent or scheduled it. */
    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** Who looked it up in 11za and settled an `unknown` row. */
    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function rule()
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    /**
     * Claimed by a worker that never came back: `sending` for longer than any
     * real send can take — the request times out in seconds and the worker
     * itself in a minute.
     */
    public function scopeAbandoned($query)
    {
        return $query->where('status', 'sending')
            ->where('sending_started_at', '<', now()->subMinutes((int) config('automation.whatsapp.api.stuck_after_minutes', 5)));
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
