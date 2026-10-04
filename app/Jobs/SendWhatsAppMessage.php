<?php

namespace App\Jobs;

use App\Models\MessageLog;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One queued message, sent through 11za. The only thing that calls
 * WhatsAppSender::send() once the API is configured.
 *
 * Takes an id rather than a model: the message can be cancelled or opened by
 * hand between dispatch and running, and a serialised model would send a
 * stale row.
 *
 * The row is claimed (queued → sending) in one UPDATE before anything goes
 * to 11za, so two workers, or a worker and a retry, cannot both send it. The
 * claim is timed: a worker that dies mid-send leaves the row `sending`, and
 * `whatsapp:sweep-abandoned` marks it `unknown` once it is clearly abandoned.
 *
 * Retries only what is worth retrying — timeouts, 429 and 5xx, as decided by
 * WhatsAppApiException. Any other refusal from 11za fails once, with 11za's
 * raw response on the row.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public int $messageId)
    {
        $this->tries = (int) config('automation.whatsapp.api.tries', 3);
    }

    public function handle(WhatsAppSender $sender): void
    {
        $claimed = MessageLog::whereKey($this->messageId)
            ->where('status', 'queued')
            ->update(['status' => 'sending', 'sending_started_at' => now()]);

        // gone, already opened by somebody, cancelled, or another worker has it
        if (! $claimed) {
            return;
        }

        $message = MessageLog::find($this->messageId);
        $result = $sender->send($message);

        if (! $result['retry']) {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            $message->update(['status' => 'failed']);

            return;
        }

        $message->update(['status' => 'queued']);

        $this->release($this->backoffFor($this->attempts()));
    }

    /**
     * The job gave up — something threw that send() did not catch, or the
     * worker ran out of time on the last attempt. The row must not be left
     * looking busy for ever.
     *
     * Which way depends on how far it got. Still `queued`: it never left, so
     * `failed` is the truth. `sending`: it had been handed to 11za, and
     * whether 11za sent it is not known — `unknown`, never `failed`, because
     * "failed" invites somebody to send it again and the customer may already
     * have it.
     */
    public function failed(?Throwable $e): void
    {
        $why = 'The send job stopped unexpectedly: '.($e?->getMessage() ?? 'no reason given');

        MessageLog::whereKey($this->messageId)->where('status', 'queued')
            ->update(['status' => 'failed', 'error' => $why]);

        MessageLog::whereKey($this->messageId)->where('status', 'sending')
            ->update(['status' => 'unknown', 'error' => $why]);
    }

    private function backoffFor(int $attempt): int
    {
        $steps = (array) config('automation.whatsapp.api.backoff', [30, 120]);

        return (int) ($steps[$attempt - 1] ?? end($steps));
    }
}
