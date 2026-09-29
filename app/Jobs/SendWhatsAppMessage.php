<?php

namespace App\Jobs;

use App\Models\MessageLog;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One queued message, sent through the API.
 *
 * Nothing dispatches this in the current pass — auto-send is off and cannot be
 * switched on while the API is unconfigured. It is written now because the
 * alternative is writing it in a hurry the week the credentials arrive, and
 * because the failure path is the part worth reading before then: a message
 * that cannot be sent is marked `failed` with the reason on the row, where the
 * Queue tab shows it. It does not retry into the dark.
 *
 * Takes an id rather than the model: a message can be cancelled or opened by
 * hand between dispatch and running, and a serialised model would send the
 * stale body.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $messageId) {}

    public function handle(WhatsAppSender $sender): void
    {
        $message = MessageLog::find($this->messageId);

        // gone, already opened by somebody, or cancelled while it waited
        if (! $message || $message->status !== 'queued') {
            return;
        }

        $sender->send($message);
    }
}
