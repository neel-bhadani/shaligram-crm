<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The only thing that writes an alert.
 *
 * Two rules stand between "something happened" and a row in `alerts`, and both
 * of them are the reason this is a service rather than an Alert::create() call
 * scattered around the application.
 *
 * PRIVACY. An alert's title carries the lead's name — "Rahul Mehta has been in
 * Discussion for 9 days" — so an alert sent to the wrong person leaks precisely
 * what scopeVisibleTo exists to protect. Every recipient is checked against the
 * lead through that same scope before a row is written, and anybody who fails
 * is dropped in silence. Not "skipped with a message": telling somebody there
 * is a lead they may not see is itself the leak.
 *
 * NOISE. The hourly command asks "which follow-ups are three days overdue"
 * every hour and gets the same lead back every hour. Twenty-four identical
 * alerts a day and the bell is wallpaper by tomorrow — the feature is not
 * broken, it is worse, it is ignored. The same type, for the same lead and the
 * same person, is refused inside config('crm.alerts.dedupe_hours').
 *
 * That refusal happens HERE, at creation. Filtering duplicates on display was
 * the obvious alternative and it is wrong: the bell's unread count is a
 * `count()` over the table, so the rows would still be there, still unread,
 * still counted, and "mark all read" would be marking two hundred rows nobody
 * ever saw.
 */
class AlertService
{
    /**
     * Set while a bulk operation — the lead importer — is running.
     *
     * Mirrors RuleEngine::$suspended (App\Services\Automation\RuleEngine). A
     * rule action that raises an alert is already silenced by
     * RuleEngine::withoutRules() because the rule never dispatches; this
     * covers the one alert raised outside a rule — LeadAssignmentService's
     * "no salesperson is staffed on this project" — which an import can
     * trigger dozens of times in a few seconds for what is really one
     * misconfiguration, not dozens of them.
     */
    private bool $suspended = false;

    /** Run $work with every alert() call silently doing nothing. */
    public function withoutAlerts(callable $work): mixed
    {
        $was = $this->suspended;
        $this->suspended = true;

        try {
            return $work();
        } finally {
            $this->suspended = $was;
        }
    }

    /**
     * Raise one alert for one person.
     *
     * @param  string  $type  what this is ABOUT, and the key deduplication
     *                        groups on. Two different rules raising alerts on
     *                        the same lead are two different types, so one does
     *                        not silence the other.
     * @return Alert|null null when it was deduplicated, suspended, or the
     *                    recipient may not see the lead. Callers do not need
     *                    to care which.
     */
    public function raise(
        User $recipient,
        string $type,
        string $title,
        ?string $body = null,
        ?Lead $lead = null,
        string $severity = 'info',
        ?AutomationRule $rule = null,
        ?string $actionUrl = null,
    ): ?Alert {
        if ($this->suspended) {
            return null;
        }

        // a deactivated account still has rows in `alerts`; adding to the pile
        // of somebody who cannot sign in is not a notification
        if (! $recipient->is_active) {
            return null;
        }

        if ($lead && ! $this->canSee($recipient, $lead)) {
            return null;
        }

        if ($this->recentlyRaised($recipient, $type, $lead)) {
            return null;
        }

        return Alert::create([
            'user_id' => $recipient->id,
            'lead_id' => $lead?->id,
            'rule_id' => $rule?->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'severity' => in_array($severity, ['info', 'warning', 'urgent'], true) ? $severity : 'info',
            'action_url' => $actionUrl ?? ($lead ? $this->leadUrl($lead) : null),
            'created_at' => now(),
        ]);
    }

    /**
     * Raise the same alert for several people, dropping the ones who may not
     * see the lead.
     *
     * @param  iterable<User>  $recipients
     * @return int how many were actually written
     */
    public function raiseMany(
        iterable $recipients,
        string $type,
        string $title,
        ?string $body = null,
        ?Lead $lead = null,
        string $severity = 'info',
        ?AutomationRule $rule = null,
        ?string $actionUrl = null,
    ): int {
        $written = 0;

        foreach ($recipients as $recipient) {
            if ($this->raise($recipient, $type, $title, $body, $lead, $severity, $rule, $actionUrl)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Mark every unread alert of one type as read, for everybody it went to.
     *
     * For the alerts that are a question with one answer. "Priya is waiting
     * for approval" goes to every admin; once one of them approves her, the
     * others' bells would go on counting a request that no longer exists and
     * lead them to a Pending filter with nobody in it. Marked read rather than
     * deleted, so the Alerts page still shows it was raised.
     *
     * @return int how many were marked
     */
    public function resolve(string $type): int
    {
        return Alert::where('type', $type)->unread()->update(['read_at' => now()]);
    }

    /* ---------------- recipients ---------------- */

    /** Every active admin. */
    public function admins(): Collection
    {
        return User::active()->where('role', 'admin')->get();
    }

    /** Everybody active in one role. */
    public function role(string $role): Collection
    {
        return User::active()->where('role', $role)->get();
    }

    /* ---------------- reading ---------------- */

    public function unreadCount(User $user): int
    {
        return Alert::for($user)->unread()->count();
    }

    /**
     * What drops down from the bell: the newest few, read or not.
     *
     * Read ones are included deliberately. A bell that empties itself the
     * moment you look at it gives you no way back to the alert you glanced at
     * and closed — the count is what tracks unread, the list is recent history.
     */
    public function recent(User $user, ?int $limit = null): Collection
    {
        return Alert::for($user)
            ->with('lead:id,first_name,middle_name,last_name,mobile_number,stage')
            ->latest('created_at')
            ->limit($limit ?? config('crm.alerts.bell_limit', 10))
            ->get();
    }

    /* ---------------- the two refusals ---------------- */

    /**
     * The privacy check, run through the lead's own scope rather than a copy
     * of its logic — a second implementation of "who may see this lead" is a
     * second thing to get wrong.
     */
    private function canSee(User $user, Lead $lead): bool
    {
        return Lead::whereKey($lead->id)->visibleTo($user)->exists();
    }

    /**
     * Has this person already been told this about this lead today?
     *
     * `lead_id` is part of the key, including when it is null: two alerts of
     * the same type with no lead — "automation was suppressed" raised over the
     * whole system rather than one lead — are still the same alert, and the
     * null matches itself here through whereNull.
     */
    private function recentlyRaised(User $user, string $type, ?Lead $lead): bool
    {
        $since = now()->subHours((int) config('crm.alerts.dedupe_hours', 24));

        return Alert::where('user_id', $user->id)
            ->where('type', $type)
            ->when($lead, fn ($q) => $q->where('lead_id', $lead->id))
            ->when(! $lead, fn ($q) => $q->whereNull('lead_id'))
            ->where('created_at', '>=', $since)
            ->exists();
    }

    /**
     * Where an alert takes you when you click it.
     *
     * The Leads page filtered to this lead's mobile number. There is no
     * per-lead page in this application — a lead opens in a modal on the list
     * — and the list's search box already matches on `mobile_number`, so this
     * is a link that works today rather than a route that would have to be
     * invented for it.
     */
    private function leadUrl(Lead $lead): string
    {
        return route('leads.index', ['search' => $lead->mobile_number]);
    }
}
