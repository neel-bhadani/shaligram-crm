<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\AlertService;
use App\Services\LeadAssignmentService;
use App\Services\LeadFollowUpService;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use App\Support\CrmTaxonomy;

/**
 * Carries out one action of one rule.
 *
 * Nothing here writes `leads.stage`, `leads.assigned_to` or a pending to-do.
 * All three go through LeadFollowUpService, which is the application's only
 * writer of any of them and the keeper of "every open lead has exactly one
 * pending follow-up". An action that inserted a `todos` row directly would
 * break that invariant the first time it ran against a lead that already had
 * one, and nothing would notice until the To-do page started showing a lead
 * twice.
 *
 * Every call is wrapped in asSystem(), so the rows these actions produce carry
 * no user id. Automation is not the admin who wrote the rule.
 *
 * The three outcomes are deliberately distinct:
 *
 *   success  it happened.
 *   skipped  it could not happen and that is not a fault — the rule points at
 *            a person who has left, the lead is already booked, the customer
 *            has no usable mobile number. The reason is logged.
 *   failed   something threw. The reason is logged, the rule's remaining
 *            actions are abandoned, and the whole rule rolls back.
 */
class ActionRunner
{
    public function __construct(
        private LeadFollowUpService $followUps,
        private LeadAssignmentService $assignment,
        private AlertService $alerts,
        private WhatsAppSender $whatsapp,
        private TemplateRenderer $renderer,
        private RuleCatalog $catalogue,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array{result: string, error: ?string}
     */
    public function run(AutomationRule $rule, Lead $lead, array $action): array
    {
        $type = $action['type'] ?? null;

        return match ($type) {
            'assign_user' => $this->assignUser($lead, $action),
            'assign_round_robin' => $this->assignRoundRobin($lead, $action),
            'change_stage' => $this->changeStage($rule, $lead, $action),
            'create_follow_up' => $this->createFollowUp($lead, $action),
            'raise_alert' => $this->raiseAlert($rule, $lead, $action),
            'queue_whatsapp' => $this->queueWhatsApp($rule, $lead, $action),
            // a rule stored before an action was removed from the catalogue.
            // Skipped and logged rather than thrown: one retired action must
            // not stop the other four from running.
            default => $this->skip("This rule uses an action this version does not know: '{$type}'."),
        };
    }

    /* ---------------- assignment ---------------- */

    private function assignUser(Lead $lead, array $action): array
    {
        $user = User::active()->find($action['user_id'] ?? null);

        if (! $user) {
            return $this->skip('The person this rule assigns to is no longer an active user.');
        }

        $this->followUps->asSystem(fn () => $this->followUps->assign($lead, $user));

        return $this->ok();
    }

    /**
     * Round-robin across a role.
     *
     * Salespeople take the lead's project's turn from LeadAssignmentService —
     * the same per-project round robin, fallback and admin alert as lead
     * creation and the handover. A rule keeping its own counter across every
     * salesperson would hand a project's leads to people who do not handle it.
     *
     * Telecallers are not chosen per project: the last id used lives in the
     * cache, and the next person is the first id above it, wrapping to the
     * start. Cache, not a column, because losing the counter costs one
     * slightly uneven assignment and nothing else.
     */
    private function assignRoundRobin(Lead $lead, array $action): array
    {
        $role = $action['role'] ?? null;

        if (! in_array($role, config('crm.staff_roles'), true)) {
            return $this->skip("This rule shares leads out among '{$role}', which is not a role.");
        }

        if ($role === 'salesperson') {
            $next = $this->assignment->takeSalespersonTurn($lead->project_id);

            if (! $next) {
                return $this->skip('There is nobody active on the salesperson desk to give this lead to.');
            }

            $this->followUps->asSystem(fn () => $this->followUps->assign($lead, $next));

            return $this->ok();
        }

        $people = User::active()->where('role', $role)->orderBy('id')->get();

        if ($people->isEmpty()) {
            return $this->skip("There is nobody active on the {$role} desk to give this lead to.");
        }

        $key = "automation.round_robin.$role";
        $lastId = (int) cache()->get($key, 0);
        $next = $people->first(fn (User $u) => $u->id > $lastId) ?? $people->first();

        cache()->forever($key, $next->id);

        $this->followUps->asSystem(fn () => $this->followUps->assign($lead, $next));

        return $this->ok();
    }

    /* ---------------- the lead itself ---------------- */

    private function changeStage(AutomationRule $rule, Lead $lead, array $action): array
    {
        $stage = $action['stage'] ?? null;

        if (! array_key_exists($stage, CrmTaxonomy::allStages())) {
            return $this->skip("This rule moves leads to '{$stage}', which is not a stage.");
        }

        /*
         | The stage exists but has been switched off. Skipped rather than run,
         | and skipped LOUDLY — the reason lands in the activity log, which is
         | where an admin looks when a rule stopped doing anything. Moving leads
         | into a stage the company has retired would go on filling a column
         | nobody reads and nothing can be chosen out of again.
         */
        if (! array_key_exists($stage, CrmTaxonomy::stages())) {
            return $this->skip("This rule moves leads to '{$stage}', which is no longer in use.");
        }

        if ($lead->stage === $stage) {
            // not a failure and not worth a transition row: the lead is already
            // where the rule wants it. Silently writing a history entry saying
            // "moved to In discussion" for a lead that never moved would put
            // phantom transitions on the dashboard's stage-change chart.
            return $this->skip("The lead is already at '{$stage}'.");
        }

        $this->followUps->asSystem(fn () => $this->followUps->changeStage(
            $lead,
            $stage,
            [],
            null,
            null,
            null,
            "Stage changed by automation: {$rule->name}.",
        ));

        return $this->ok();
    }

    private function createFollowUp(Lead $lead, array $action): array
    {
        $hours = (int) ($action['hours'] ?? 0);

        if ($hours < 1) {
            return $this->skip('This rule creates a follow-up in no time at all — set a number of hours.');
        }

        $booked = $this->followUps->asSystem(fn () => $this->followUps->scheduleFollowUp(
            $lead,
            now()->addHours($hours),
            $action['todo_type'] ?? 'call',
            $action['remarks'] ?? null,
        ));

        return $booked
            ? $this->ok()
            : $this->skip('The lead is booked or lost, so there is no next follow-up to create.');
    }

    /* ---------------- telling people ---------------- */

    /**
     * Raise the rule's alert for whoever it names.
     *
     * AlertService drops anybody who cannot see the lead and anybody who has
     * had this same alert in the last day, so "0 people were told" is a normal
     * outcome rather than an error — but it is reported as `skipped` with the
     * count, because a rule that alerts nobody every time is a rule the admin
     * needs to find out about.
     */
    private function raiseAlert(AutomationRule $rule, Lead $lead, array $action): array
    {
        $recipients = $this->recipientsFor($lead, $action);

        if ($recipients === null) {
            return $this->skip('This rule does not say who to alert.');
        }

        $title = $this->renderer->render((string) ($action['title'] ?? ''), $lead);
        $body = filled($action['body'] ?? null)
            ? $this->renderer->render((string) $action['body'], $lead)
            : null;

        if (trim($title) === '') {
            return $this->skip('This rule has no alert headline, so there is nothing to show.');
        }

        $written = $this->alerts->raiseMany(
            recipients: $recipients,
            // per rule, so two rules alerting on the same lead do not silence
            // one another through deduplication
            type: 'rule.'.$rule->id,
            title: $title,
            body: $body,
            lead: $lead,
            severity: $action['severity'] ?? 'info',
            rule: $rule,
        );

        return $written > 0
            ? $this->ok()
            : $this->skip('Nobody was alerted — they were told about this lead already today, or cannot see it.');
    }

    /**
     * @return iterable<User>|null null when the rule names nobody at all
     */
    private function recipientsFor(Lead $lead, array $action): ?iterable
    {
        return match ($action['recipient'] ?? null) {
            'lead_owner' => $lead->owner && $lead->owner->is_active ? [$lead->owner] : [],
            'admins' => $this->alerts->admins(),
            'role' => isset($action['recipient_role'])
                ? $this->alerts->role($action['recipient_role'])
                : null,
            'user' => ($u = User::active()->find($action['recipient_user_id'] ?? null)) ? [$u] : [],
            default => null,
        };
    }

    /**
     * QUEUE a WhatsApp message. Never send one.
     *
     * This is the hard line of the whole WhatsApp feature and it is enforced
     * here rather than in a setting: a rule puts the message in the review list
     * and a person opens it. There are no credentials yet, auto-send is off,
     * and even when both change the switch belongs to an admin looking at the
     * Queue tab — not to a rule written weeks earlier.
     */
    private function queueWhatsApp(AutomationRule $rule, Lead $lead, array $action): array
    {
        $template = MessageTemplate::active()->find($action['template_id'] ?? null);

        if (! $template) {
            return $this->skip('The message this rule queues has been deleted or switched off.');
        }

        $queued = $this->whatsapp->queue($lead, $template, $rule);

        return $queued
            ? $this->ok()
            : $this->skip('This lead has no usable mobile number, so there is nothing to send to.');
    }

    /* ---------------- outcomes ---------------- */

    private function ok(): array
    {
        return ['result' => 'success', 'error' => null];
    }

    private function skip(string $why): array
    {
        return ['result' => 'skipped', 'error' => $why];
    }
}
