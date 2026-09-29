<?php

namespace App\Jobs;

use App\Models\MessageLog;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * One rule-written message, sent through the API by the queue worker.
 *
 * Three attempts at most (config automation.whatsapp.api.tries). A failure that
 * might clear on its own — no answer, a 5xx, a rate limit — is thrown so the
 * worker tries again; one that will not — a bad number, an unapproved template
 * — gives up at once rather than spending the other two attempts on a refusal.
 * Either way the end is failed(): the row is marked `failed` with Meta's error
 * on it, and the admins are alerted. It never retries into the dark.
 *
 * Takes an id rather than the model: a message can be opened by hand or
 * cancelled from the Queue between dispatch and running, and a serialised
 * model would send it anyway.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public function __construct(public int $messageId)
    {
        $this->tries = (int) config('automation.whatsapp.api.tries', 3);
    }

    /** @return list<int> seconds before the second and third attempts */
    public function backoff(): array
    {
        return (array) config('automation.whatsapp.api.backoff', [30, 120]);
    }

    public function handle(WhatsAppSender $sender): void
    {
        $message = MessageLog::find($this->messageId);

        // gone, opened or cancelled by somebody while it waited, or already sent
        if (! $message || $message->status !== 'queued' || $message->mode !== 'api') {
            return;
        }

        // the credentials were removed or the API switched off since the rule
        // fired: it waits in the Queue for click-to-send instead
        if (! $sender->apiReady()) {
            $message->update(['mode' => 'click', 'error' => WhatsAppSender::NOT_CONFIGURED]);

            return;
        }

        $result = $sender->attempt($message);

        if ($result['ok']) {
            return;
        }

        $error = new RuntimeException($result['reason']);

        if (! $result['retryable']) {
            $this->fail($error);

            return;
        }

        throw $error;
    }

    public function failed(?Throwable $exception): void
    {
        $message = MessageLog::find($this->messageId);

        if (! $message || $message->status !== 'queued') {
            return;
        }

        $sender = app(WhatsAppSender::class);
        $sender->markFailed($message, $exception?->getMessage());
        $sender->alertFailed($message->fresh());
    }
}
