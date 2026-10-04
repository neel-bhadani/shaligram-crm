<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Lead;
use App\Models\MessageBatch;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One tag to many leads. Admin only, API only.
 *
 * Every lead is still its own MessageLog row, and every row goes through the
 * same delayed SendWhatsAppMessage job a scheduled send does — no second
 * scheduler. Pacing is the job delays: each row gets its own time, spread at
 * `bulk.per_minute`, and the lead is read again when it goes.
 *
 * THE GUARD RAILS
 *
 *   exclusions   opted out, no usable number, an empty value, a number
 *                already in this send, or the same tag sent to the lead or
 *                number in the last 24 hours. Each left-out lead is a
 *                `skipped` row in the batch with its reason, so the list of
 *                who was left out, and why, can be read — not only counted.
 *   the cap      counted after exclusions. Over it the send is refused,
 *                never cut short.
 *   the check    the confirm screen shows "N selected · M will receive".
 *                Starting repeats the plan, and refuses if either number
 *                moved since — the admin confirmed those numbers.
 *   holds        11za's 429, or `failure_streak` failed attempts in a row,
 *                hold the batch (WhatsAppSender::batchFailed). Never resumes
 *                by itself.
 *   stop         cancels every row still waiting. One already handed to
 *                11za finishes.
 */
class BulkSender
{
    public function __construct(
        private WhatsAppSender $whatsapp,
        private TemplateRenderer $renderer,
    ) {}

    /** Why this tag cannot be sent in bulk, or null. */
    public function refusal(MessageTemplate $template): ?string
    {
        if (! $this->whatsapp->apiEnabled()) {
            return 'Bulk sending needs API sending, which is switched off in the WhatsApp settings (Queue tab).';
        }

        if (! $template->is_active) {
            return 'That tag is switched off.';
        }

        if ($reason = $template->apiUnsendableReason()) {
            return "Bulk sending needs API sending, and this tag cannot go by API: {$reason}";
        }

        return null;
    }

    /**
     * Who would receive it, who would not and why, and the message as one
     * named lead would get it.
     *
     * @param  Collection<int, Lead>  $leads
     * @return array{selected: int, recipients: int, cap: int, over_cap: bool,
     *               excluded: list<array{reason: string, leads: list<array{id: int, name: string, number: ?string}>}>,
     *               sample: ?array{name: string, body: ?string, values: list<array{position: int, value: string}>},
     *               rows: list<array{lead: Lead, reason: ?string}>}
     */
    public function plan(Collection $leads, MessageTemplate $template): array
    {
        $leads = (new EloquentCollection($leads->all()))->loadMissing(['project', 'owner'])->values();
        $numbers = $leads->mapWithKeys(fn (Lead $lead) => [$lead->id => $this->renderer->waNumber($lead->mobile_number)]);

        $recent = MessageLog::where('template_id', $template->id)
            ->whereIn('status', ['queued', 'held', 'sending', 'sent', 'opened', 'unknown'])
            ->where('created_at', '>=', now()->subHours((int) config('automation.whatsapp.bulk.dedupe_hours', 24)))
            ->where(fn ($q) => $q->whereIn('lead_id', $leads->pluck('id'))->orWhereIn('to_number', $numbers->filter()->values()))
            ->get(['lead_id', 'to_number']);

        $recentLeads = $recent->pluck('lead_id')->flip();
        $recentNumbers = $recent->pluck('to_number')->filter()->flip();
        $taken = [];
        $hours = (int) config('automation.whatsapp.bulk.dedupe_hours', 24);

        $rows = $leads->map(function (Lead $lead) use ($template, $numbers, $recentLeads, $recentNumbers, &$taken, $hours) {
            $number = $numbers[$lead->id];

            $reason = match (true) {
                $lead->hasOptedOutOfWhatsApp() => 'Opted out of WhatsApp',
                ! $number => 'No usable mobile number',
                isset($taken[$number]) => 'Same number as another lead in this send',
                isset($recentLeads[$lead->id]), isset($recentNumbers[$number]) => "Sent this tag in the last {$hours} hours",
                default => $this->emptyValueReason($template, $lead),
            };

            if ($number && $reason === null) {
                $taken[$number] = true;
            }

            return ['lead' => $lead, 'number' => $number, 'reason' => $reason];
        });

        $recipients = $rows->whereNull('reason');
        $cap = (int) config('automation.whatsapp.bulk.max_recipients', 200);
        $first = $recipients->first()['lead'] ?? null;

        return [
            'selected' => $leads->count(),
            'recipients' => $recipients->count(),
            'cap' => $cap,
            'over_cap' => $recipients->count() > $cap,
            'excluded' => $rows->whereNotNull('reason')
                ->groupBy('reason')
                ->map(fn (Collection $group, string $reason) => [
                    'reason' => $reason,
                    'leads' => $group->map(fn (array $row) => [
                        'id' => $row['lead']->id,
                        'name' => $row['lead']->full_name,
                        'number' => $row['lead']->mobile_number,
                    ])->values()->all(),
                ])
                ->values()
                ->all(),
            'sample' => $first ? [
                'name' => $first->full_name,
                // 11za's wording, or null when not read — never a stand-in
                'body' => $this->renderer->wordingFor($template, $first),
                'values' => collect($this->whatsapp->paramsFor($template, $first))
                    ->map(fn (string $value, int $i) => ['position' => $i + 1, 'value' => $value])
                    ->all(),
            ] : null,
            'rows' => $rows->all(),
        ];
    }

    /**
     * Start it. The plan is made again here, inside the transaction, and must
     * match what the admin confirmed.
     *
     * @param  Collection<int, Lead>  $leads
     * @return array{ok: bool, message: string, plan?: array<string, mixed>, batch?: MessageBatch}
     */
    public function start(
        User $user,
        MessageTemplate $template,
        Collection $leads,
        string $selection,
        ?CarbonInterface $sendAt,
        int $expectSelected,
        int $expectRecipients,
    ): array {
        if ($refusal = $this->refusal($template)) {
            return ['ok' => false, 'message' => $refusal];
        }

        $plan = $this->plan($leads, $template);

        if ($plan['selected'] !== $expectSelected || $plan['recipients'] !== $expectRecipients) {
            return [
                'ok' => false,
                'message' => "The list changed since you checked it: now {$plan['selected']} selected, {$plan['recipients']} will receive it. Nothing was sent. Check the new numbers and confirm again.",
                'plan' => $plan,
            ];
        }

        if ($plan['over_cap']) {
            return ['ok' => false, 'message' => "{$plan['recipients']} would receive it, and a bulk send is limited to {$plan['cap']}. Nothing was sent. Narrow the selection."];
        }

        if ($plan['recipients'] === 0) {
            return ['ok' => false, 'message' => 'Nobody in this selection can be sent to. Nothing was sent.'];
        }

        $batch = DB::transaction(fn () => $this->write($user, $template, $plan, $selection, $sendAt));

        $count = $plan['recipients'];
        $when = $sendAt ? 'starting '.$sendAt->copy()->setTimezone('Asia/Kolkata')->format('j M, g:i a').' (India time)' : 'starting now';

        return [
            'ok' => true,
            'message' => "Sending \"{$template->name}\" to {$count} lead".($count === 1 ? '' : 's').", {$when}, "
                .self::perMinute().' a minute. Follow it, or stop it, on the Queue tab.',
            'batch' => $batch,
        ];
    }

    /**
     * Stop it: every row still waiting is cancelled, with who and when. A
     * message already handed to 11za finishes. Who started the send is kept.
     */
    public function stop(MessageBatch $batch, User $user): bool
    {
        return DB::transaction(function () use ($batch, $user) {
            $stopped = MessageBatch::whereKey($batch->id)->whereIn('status', ['running', 'held'])
                ->update(['status' => 'stopped', 'stopped_by' => $user->id, 'stopped_at' => now()]);

            if ($stopped) {
                $batch->messages()->whereIn('status', MessageBatch::WAITING)
                    ->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now()]);
            }

            return (bool) $stopped;
        });
    }

    /**
     * Resume a held send. Every row still waiting starts again from now, at
     * the configured rate, under a new dispatch number — so a retry or a
     * turn queued before the hold wakes, finds a different number, and does
     * nothing. The lead is still read again when each one goes.
     */
    public function resume(MessageBatch $batch, User $user): bool
    {
        return DB::transaction(function () use ($batch, $user) {
            $resumed = MessageBatch::whereKey($batch->id)->where('status', 'held')
                ->update(['status' => 'running', 'failure_streak' => 0, 'resumed_by' => $user->id, 'resumed_at' => now()]);

            if (! $resumed) {
                return false;
            }

            $start = now();

            $batch->messages()->whereIn('status', MessageBatch::WAITING)->orderBy('id')->lockForUpdate()->get()
                ->each(function (MessageLog $message, int $i) use ($start) {
                    $sendAt = $start->copy()->addSeconds($this->offset($i));

                    $message->update([
                        'status' => 'queued',
                        'send_at' => $sendAt,
                        'dispatch' => $message->dispatch + 1,
                    ]);

                    SendWhatsAppMessage::dispatch($message->id, $message->dispatch)->delay($sendAt)->afterCommit();
                });

            return true;
        });
    }

    public static function perMinute(): int
    {
        return max(1, (int) config('automation.whatsapp.bulk.per_minute', 20));
    }

    /** @param array<string, mixed> $plan */
    private function write(User $user, MessageTemplate $template, array $plan, string $selection, ?CarbonInterface $sendAt): MessageBatch
    {
        $batch = MessageBatch::create([
            'template_id' => $template->id,
            'template_name' => $template->name,
            'selection' => $selection,
            'selected_count' => $plan['selected'],
            'excluded_count' => $plan['selected'] - $plan['recipients'],
            'recipient_count' => $plan['recipients'],
            'send_at' => $sendAt,
            'status' => 'running',
            'created_by' => $user->id,
        ]);

        $start = $sendAt ? $sendAt->copy() : now();
        $turn = 0;

        foreach ($plan['rows'] as $row) {
            $lead = $row['lead'];
            $params = $this->whatsapp->paramsFor($template, $lead);

            $base = [
                'batch_id' => $batch->id,
                'lead_id' => $lead->id,
                'template_id' => $template->id,
                'provider_template_name' => $template->provider_template_name,
                'provider_template_language' => $template->provider_template_language,
                'user_id' => $user->id,
                'mode' => 'api',
                'to_number' => $row['number'],
                'to_name' => $lead->full_name,
                'body' => $this->renderer->build($template, $lead)['body'],
                'params' => $params,
            ];

            if ($row['reason'] !== null) {
                MessageLog::create($base + ['status' => 'skipped', 'error' => $row['reason']]);

                continue;
            }

            $at = $start->copy()->addSeconds($this->offset($turn++));

            $message = MessageLog::create($base + [
                'status' => 'queued',
                'send_at' => $at,
                'scheduled_at' => $sendAt ? now() : null,
                'dedupe_key' => hash('sha256', $row['number'].'|'.$template->id.'|'.json_encode($params)),
            ]);

            SendWhatsAppMessage::dispatch($message->id)->delay($at)->afterCommit();
        }

        return $batch;
    }

    /** Seconds after the start for the i-th message, at the configured rate. */
    private function offset(int $i): int
    {
        return intdiv($i * 60, self::perMinute());
    }

    private function emptyValueReason(MessageTemplate $template, Lead $lead): ?string
    {
        $empty = array_search('', $this->whatsapp->paramsFor($template, $lead), true);

        return $empty === false
            ? null
            : $this->whatsapp->missingValueReason(array_values($template->placeholder_map ?? [])[$empty], $lead);
    }
}
