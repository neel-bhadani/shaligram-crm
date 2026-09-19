<?php

namespace App\Services;

use App\Models\AutomationLog;
use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\MessageLog;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Everything that happened to one lead, oldest first, as display-ready entries.
 *
 * WHERE EACH ENTRY COMES FROM
 *
 *   lead_activities   every change made since the table existed — creation,
 *                     edits, stage changes, completed and scheduled follow-ups,
 *                     reassignments. One head row and its children make one
 *                     entry; see LeadActivity.
 *   todos             completed follow-ups from BEFORE the lead's first
 *                     activity row, and only those. After that point every
 *                     completion and stage change has its own activity row, and
 *                     reading its to-do as well would show it twice.
 *   automation_logs   the automation actions that change nothing on the lead —
 *                     an alert raised, a message queued. The ones that do
 *                     change it (stage, owner, follow-up) are already activity
 *                     rows written as the system, so they are not read here.
 *   message_logs      WhatsApp messages that were opened or sent.
 *   leads             a "Lead created" entry, synthesised from `created_at` and
 *                     `created_by`, for a lead that predates the activity table
 *                     and so has no creation row of its own. Nothing else is
 *                     synthesised: what was never recorded does not appear.
 *
 * The lead has already passed LeadPolicy::view(), the same rule visibleTo()
 * applies to lists; every query here is pinned to this lead's id.
 *
 * A fixed number of queries whatever the length of the history: one per
 * source, then one per kind of name the entries mention — users, projects,
 * channel partners — each only when something mentions one.
 */
class LeadTimeline
{
    /** Automation actions whose effect exists nowhere else on the timeline. */
    private const AUTOMATION_ACTIONS = [
        'raise_alert' => 'Alert raised',
        'queue_whatsapp' => 'WhatsApp message queued',
    ];

    private const FIELD_LABELS = [
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'mobile_number' => 'Mobile',
        'email' => 'Email',
        'project_id' => 'Project',
        'source' => 'Source',
        'channel_partner_id' => 'Channel partner',
        'requirement' => 'Requirement',
        'reason' => 'Reason for loss',
        'booked_unit' => 'Booked unit',
        'assigned_to' => 'Assigned to',
    ];

    /** Ties on the same second: the lead first, what was done to it, then what it set off. */
    private const RANK = ['lead' => 0, 'todo' => 1, 'activity' => 2, 'automation' => 3, 'message' => 4];

    /** @var Collection<int, User> */
    private Collection $users;

    /** @var Collection<int, Project> */
    private Collection $projects;

    /** @var Collection<int, ChannelPartner> */
    private Collection $partners;

    /**
     * @return list<array{
     *     key: string,
     *     kind: string,
     *     title: string,
     *     at: string,
     *     at_exact: string,
     *     actor: string,
     *     system: bool,
     *     from_stage: ?string,
     *     to_stage: ?string,
     *     change: ?array{field: string, from: ?string, to: ?string},
     *     remark: ?string,
     *     details: list<array{label: string, value: ?string, note?: ?string}>
     * }>
     */
    public function for(Lead $lead): array
    {
        $activities = LeadActivity::where('lead_id', $lead->id)->orderBy('id')->get();
        $recordedFrom = $activities->first()?->created_at;

        $history = Todo::where('lead_id', $lead->id)
            ->where('status', 'completed')
            ->whereNotNull('outcome_stage')
            // strictly before: the first activity is written ahead of the
            // to-do it describes, in the same second or an earlier one
            ->when($recordedFrom, fn ($q, $from) => $q->where('completed_at', '<', $from))
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get();

        $automation = AutomationLog::where('lead_id', $lead->id)
            ->where('result', 'success')
            ->whereIn('action', array_keys(self::AUTOMATION_ACTIONS))
            ->with('rule:id,name')
            ->orderBy('id')
            ->get();

        $messages = MessageLog::where('lead_id', $lead->id)
            ->whereIn('status', ['opened', 'sent'])
            ->whereNotNull('sent_at')
            ->orderBy('id')
            ->get();

        $this->loadNames($lead, $activities, $history, $messages);

        $entries = collect();

        if (! $activities->contains('action', LeadActivity::Created)) {
            $entries->push($this->entry('lead', 0, $lead->created_at, [
                'kind' => 'created',
                'title' => 'Lead created',
                ...$this->actor($lead->created_by),
                'remark' => 'Added before activity history was kept, so only its creation and completed follow-ups are known.',
            ]));
        }

        foreach ($history as $todo) {
            $entries->push($this->entry('todo', $todo->id, $todo->completed_at, [
                'kind' => $this->outcomeKind($todo->outcome_stage, 'history'),
                'title' => 'Outcome recorded',
                ...$this->actor($todo->completed_by),
                'to_stage' => $todo->outcome_stage,
                'remark' => $todo->remarks,
            ]));
        }

        foreach ($this->group($activities) as [$head, $children]) {
            $entries->push($this->activityEntry($head, $children));
        }

        foreach ($automation as $log) {
            $entries->push($this->entry('automation', $log->id, $log->fired_at, [
                'kind' => 'automation',
                'title' => self::AUTOMATION_ACTIONS[$log->action],
                ...$this->actor(null),
                'details' => [['label' => 'Rule', 'value' => $log->rule?->name ?? 'A rule since deleted']],
            ]));
        }

        foreach ($messages as $message) {
            $entries->push($this->entry('message', $message->id, $message->sent_at, [
                'kind' => 'whatsapp',
                'title' => $message->status === 'sent' ? 'WhatsApp message sent' : 'WhatsApp message opened',
                ...$this->actor($message->user_id),
                'remark' => Str::limit($message->body, 160),
                'details' => [['label' => 'To', 'value' => $message->to_number]],
            ]));
        }

        return $entries
            ->sortBy([['sort_at', 'asc'], ['sort_rank', 'asc'], ['sort_id', 'asc']])
            ->map(fn (array $e) => collect($e)->except(['sort_at', 'sort_rank', 'sort_id'])->all())
            ->values()
            ->all();
    }

    /**
     * Heads with the children written after them.
     *
     * @param  Collection<int, LeadActivity>  $activities  in id order
     * @return list<array{0: LeadActivity, 1: list<LeadActivity>}>
     */
    private function group(Collection $activities): array
    {
        $groups = [];

        foreach ($activities as $row) {
            if ($row->isChild() && $groups !== []) {
                $groups[array_key_last($groups)][1][] = $row;

                continue;
            }

            // a head — or a child with nothing before it, which the write order
            // rules out but which is shown on its own rather than dropped
            $groups[] = [$row, []];
        }

        return $groups;
    }

    /** @param  list<LeadActivity>  $children */
    private function activityEntry(LeadActivity $head, array $children): array
    {
        $details = array_map(fn (LeadActivity $child) => $this->detail($child), $children);

        $fields = match ($head->action) {
            LeadActivity::Created => [
                'kind' => 'created',
                'title' => 'Lead created',
                'to_stage' => $head->to_value,
            ],
            LeadActivity::FieldUpdated => [
                'kind' => 'edit',
                'title' => $this->fieldLabel($head->field).' changed',
                'change' => [
                    'field' => $this->fieldLabel($head->field),
                    'from' => $this->value($head->field, $head->from_value),
                    'to' => $this->value($head->field, $head->to_value),
                ],
            ],
            LeadActivity::StageChanged => [
                'kind' => $this->outcomeKind($head->to_value, 'stage'),
                'title' => match ($head->to_value) {
                    'booking_done' => 'Booked',
                    'lost' => 'Marked as lost',
                    default => 'Stage changed',
                },
                'from_stage' => $head->from_value,
                'to_stage' => $head->to_value,
                'remark' => $head->remark,
            ],
            LeadActivity::FollowUpCompleted => [
                'kind' => $this->outcomeKind($head->to_value, 'follow_up_completed'),
                'title' => $this->typeLabel($head->field).' completed',
                // a call that left the stage where it was has no "from"
                'from_stage' => $head->from_value !== $head->to_value ? $head->from_value : null,
                'to_stage' => $head->to_value,
                'remark' => $head->remark,
            ],
            LeadActivity::FollowUpScheduled => [
                'kind' => 'follow_up_scheduled',
                'title' => $this->typeLabel($head->field).' scheduled',
                'remark' => $head->remark,
                'details' => [['label' => 'Due', 'value' => $this->when($head->to_value)]],
            ],
            LeadActivity::Reassigned => [
                'kind' => 'assignment',
                'title' => 'Manually reassigned',
                'change' => [
                    'field' => 'Owner',
                    'from' => $this->userName($head->from_value),
                    'to' => $this->userName($head->to_value),
                ],
                'remark' => $head->remark,
            ],
            LeadActivity::ProjectSwitched => [
                'kind' => 'project_switch',
                'title' => 'Project switched',
                'change' => [
                    'field' => 'Project',
                    'from' => $this->value('project_id', $head->from_value),
                    'to' => $this->value('project_id', $head->to_value),
                ],
            ],
            LeadActivity::FollowUpRescheduled => [
                'kind' => 'follow_up_rescheduled',
                'title' => $this->typeLabel($head->field).' rescheduled',
                'change' => [
                    'field' => 'Scheduled for',
                    'from' => $this->when($head->from_value),
                    'to' => $this->when($head->to_value),
                ],
            ],
            LeadActivity::FollowUpCancelled => [
                'kind' => 'follow_up_cancelled',
                'title' => $this->typeLabel($head->field).' cancelled',
                'details' => [['label' => 'Was due', 'value' => $this->when($head->from_value)]],
            ],
            // an orphaned child, see group()
            default => [
                'kind' => 'edit',
                'title' => $this->fieldLabel($head->field),
                'details' => [$this->detail($head)],
            ],
        };

        $fields['details'] = [...($fields['details'] ?? []), ...$details];

        return $this->entry('activity', $head->id, $head->created_at, $fields + $this->actor($head->user_id));
    }

    /** One child row, as a label and a value under its head. */
    private function detail(LeadActivity $row): array
    {
        return match ($row->action) {
            LeadActivity::NextFollowUp => [
                'label' => 'Next follow-up',
                'value' => $this->typeLabel($row->field).' · '.$this->when($row->to_value),
                'note' => $row->remark,
            ],
            LeadActivity::HandedOver => [
                'label' => 'Handed over',
                'value' => $this->userName($row->from_value).' → '.$this->userName($row->to_value),
                'note' => $row->remark,
            ],
            default => [
                'label' => $this->fieldLabel($row->field),
                'value' => $this->value($row->field, $row->to_value),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function entry(string $source, int $id, ?Carbon $at, array $fields): array
    {
        $at ??= Carbon::createFromTimestamp(0);

        return [
            'key' => "{$source}-{$id}",
            'kind' => $fields['kind'],
            'title' => $fields['title'],
            'at' => $at->toIso8601String(),
            'at_exact' => $at->format('d M Y, g:i A'),
            'actor' => $fields['actor'],
            'system' => $fields['system'],
            'from_stage' => $fields['from_stage'] ?? null,
            'to_stage' => $fields['to_stage'] ?? null,
            'change' => $fields['change'] ?? null,
            'remark' => filled($fields['remark'] ?? null) ? $fields['remark'] : null,
            'details' => $fields['details'] ?? [],
            'sort_at' => $at->getTimestamp(),
            'sort_rank' => self::RANK[$source],
            'sort_id' => $id,
        ];
    }

    /**
     * Who did it. No user means no person did: automation, or an integration
     * creating a lead. Never the author of the rule.
     *
     * @return array{actor: string, system: bool}
     */
    private function actor(?int $userId): array
    {
        if ($userId === null) {
            return ['actor' => 'Automation', 'system' => true];
        }

        return ['actor' => $this->users->get($userId)?->display_name ?? 'Unknown user', 'system' => false];
    }

    /** Booking and loss get their own look, whichever path they came by. */
    private function outcomeKind(?string $stage, string $otherwise): string
    {
        return match ($stage) {
            'booking_done' => 'booking',
            'lost' => 'lost',
            default => $otherwise,
        };
    }

    /* ---------------- names ---------------- */

    /**
     * Every name the entries will print, one query per table.
     *
     * Trashed rows included: a salesperson who left, a project that closed and
     * a partner who was merged away are still who the history is about.
     *
     * The constrained selects carry every column the models' appended
     * attributes read — first and last name for User::display_name, parent_id
     * and the parent for ChannelPartner::display_label.
     */
    private function loadNames(Lead $lead, Collection $activities, Collection $history, Collection $messages): void
    {
        $idsIn = fn (string $field) => $activities
            ->where('field', $field)
            ->flatMap(fn (LeadActivity $a) => [$a->from_value, $a->to_value])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $userIds = $idsIn('assigned_to')
            ->merge($activities->pluck('user_id'))
            ->merge($history->pluck('completed_by'))
            ->merge($messages->pluck('user_id'))
            ->push($lead->created_by)
            ->filter()
            ->unique();

        $this->users = $userIds->isEmpty() ? collect() : User::withTrashed()
            ->whereIn('id', $userIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $projectIds = $idsIn('project_id');
        $this->projects = $projectIds->isEmpty() ? collect() : Project::withTrashed()
            ->whereIn('id', $projectIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $partnerIds = $idsIn('channel_partner_id');
        $this->partners = $partnerIds->isEmpty() ? collect() : ChannelPartner::withTrashed()
            ->with('parent:id,name')
            ->whereIn('id', $partnerIds)
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');
    }

    /** A stored value as it reads on screen. Null stays null; the page prints a dash. */
    private function value(?string $field, ?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return match ($field) {
            'project_id' => $this->projects->get((int) $raw)?->name ?? "Project #{$raw}",
            'channel_partner_id' => $this->partners->get((int) $raw)?->display_label ?? "Partner #{$raw}",
            'assigned_to' => $this->userName($raw),
            'source' => CrmTaxonomy::sourceLabel($raw),
            'reason' => config("crm.lost_reasons.$raw", $raw),
            default => $raw,
        };
    }

    private function userName(?string $id): string
    {
        return $id === null ? 'Nobody' : ($this->users->get((int) $id)?->display_name ?? 'Unknown user');
    }

    private function fieldLabel(?string $field): string
    {
        return self::FIELD_LABELS[$field] ?? Str::headline((string) $field);
    }

    private function typeLabel(?string $type): string
    {
        return (string) config("crm.todo_types.$type", Str::headline((string) $type));
    }

    /** A follow-up's datetime, in the app's timezone, as the rest of the app prints one. */
    private function when(?string $raw): ?string
    {
        return $raw ? Carbon::parse($raw)->format('d M Y, g:i A') : null;
    }
}
