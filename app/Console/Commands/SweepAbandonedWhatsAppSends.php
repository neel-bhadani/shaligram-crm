<?php

namespace App\Console\Commands;

use App\Models\MessageLog;
use App\Services\AlertService;
use Illuminate\Console\Command;

/**
 * Messages a worker claimed and never came back from.
 *
 * A worker that dies mid-send — killed for running past its timeout, out of
 * memory, a reboot — leaves its row `sending` for ever: the queue hands the job
 * out again, the new attempt cannot claim a row that is not `queued`, and
 * quits. Nothing else would ever touch it.
 *
 * Such a row becomes `unknown`, never `failed` and never re-sent. It was
 * handed to 11za before the worker died, so the customer may well have it,
 * and getting a message twice is worse than not getting it. A person looks it
 * up in 11za's own log and settles it from the Queue tab.
 *
 * ALERTS, without the wallpaper. Admins are told about rows THIS run marked —
 * one alert per message, on its lead — and nothing when a run marks nothing,
 * however many are still waiting to be settled. For those, one reminder a day,
 * only once they have waited more than a day: AlertService's own dedupe is
 * what holds it to one.
 */
class SweepAbandonedWhatsAppSends extends Command
{
    protected $signature = 'whatsapp:sweep-abandoned';

    protected $description = 'Mark WhatsApp sends abandoned by a dead worker as outcome unknown';

    public function handle(AlertService $alerts): int
    {
        $marked = MessageLog::abandoned()->with('lead')->get()
            ->filter(fn (MessageLog $message) => MessageLog::whereKey($message->id)->abandoned()->update([
                'status' => 'unknown',
                'error' => 'The worker sending this stopped before 11za answered.',
            ]) > 0);

        $queueUrl = route('automation.index', ['tab' => 'queue']);

        foreach ($marked as $message) {
            $alerts->raiseMany(
                $alerts->admins(),
                'whatsapp_outcome_unknown',
                "WhatsApp to {$message->to_name}: outcome unknown",
                "The send to {$message->to_number} was interrupted. Check the 11za message log, then settle it on the Queue tab.",
                $message->lead,
                'warning',
                actionUrl: $queueUrl,
            );
        }

        $waiting = MessageLog::where('status', 'unknown')
            ->where('updated_at', '<', now()->subDay())
            ->count();

        if ($waiting > 0) {
            $alerts->raiseMany(
                $alerts->admins(),
                'whatsapp_outcome_unknown_reminder',
                $waiting === 1
                    ? '1 WhatsApp message has had an unknown outcome for over a day'
                    : "{$waiting} WhatsApp messages have had an unknown outcome for over a day",
                'Check each in the 11za message log, then settle it on the Queue tab.',
                severity: 'warning',
                actionUrl: $queueUrl,
            );
        }

        $this->info("Marked {$marked->count()} abandoned send(s) as outcome unknown.");

        return self::SUCCESS;
    }
}
