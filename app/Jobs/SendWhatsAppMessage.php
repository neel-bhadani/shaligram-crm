<?php

namespace App\Jobs;

use App\Models\MessageLog;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One queued message, sent through the Cloud API. The only thing that calls
 * WhatsAppSender::send() once the API is configured.
 *
 * Takes an id rather than a model: the message can be cancelled or opened by
 * hand between dispatch and running, and a serialised model would send a
 * stale row.
 *
 * The row is claimed (queued → sending) in one UPDATE before anything goes
 * to Meta, so two workers, or a worker and a retry, cannot both send it.
 *
 * Retries only what is worth retrying — rate limits and Meta outages, as
 * decided by WhatsAppApiException. An expired token, a number not on
 * WhatsApp or a closed 24-hour window fail once, with the reason on the row.
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
            ->update(['status' => 'sending']);

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
     * Something threw that send() did not catch. The row must not be left
     * at `sending`, where it would look busy for ever.
     */
    public function failed(?Throwable $e): void
    {
        MessageLog::whereKey($this->messageId)
            ->whereIn('status', ['queued', 'sending'])
            ->update([
                'status' => 'failed',
                'error' => 'The send job stopped unexpectedly: '.($e?->getMessage() ?? 'no reason given'),
            ]);
    }

    private function backoffFor(int $attempt): int
    {
        $steps = (array) config('automation.whatsapp.api.backoff', [30, 120]);

        return (int) ($steps[$attempt - 1] ?? end($steps));
    }
}
