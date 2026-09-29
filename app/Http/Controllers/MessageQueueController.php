<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\Request;

/**
 * The review queue: messages a rule wrote, waiting for a person to send them.
 *
 * This is where "automation never sends a message on its own" is actually
 * enforced. A rule can put a row here and nothing else; getting it to a
 * customer takes somebody opening it.
 *
 * Admin only on the route group, and `visibleTo` on top of that — the queue
 * prints lead names and mobile numbers, and an admin resolves see_all_leads, so
 * today the scope changes nothing. It is there for the day the tab is opened up
 * to sales managers, which is not a day anybody will remember to add it.
 */
class MessageQueueController extends Controller
{
    public function __construct(private WhatsAppSender $whatsapp) {}

    /**
     * Open a queued message in WhatsApp.
     *
     * Marked `opened`, never `sent`. The browser hands the pre-filled text to
     * WhatsApp and that is the last this application hears of it: whether the
     * user then pressed the send button is not observable, and a log claiming
     * otherwise would make the message history worthless as a record of what
     * customers actually received.
     *
     * The wa.me link is built on the server, so the browser never has to know
     * the country code or how the text has to be encoded.
     */
    public function open(Request $request, MessageLog $message)
    {
        $this->authoriseFor($request, $message);

        $url = $this->whatsapp->clickUrlFor($message);

        if (! $url) {
            return response()->json([
                'ok'      => false,
                'message' => 'This lead has no usable mobile number, so there is nothing to open.',
            ], 422);
        }

        $this->whatsapp->markOpened($message, $request->user());

        return response()->json(['ok' => true, 'url' => $url]);
    }

    /**
     * Send through the API.
     *
     * Today this always comes back saying the API is not set up, and saying it
     * in a sentence the office admin can act on rather than a stack trace in a
     * log file. That is the whole reason the button exists now: an admin who
     * presses it finds out what is missing, instead of watching nothing happen
     * and pressing it four more times.
     */
    public function send(Request $request, MessageLog $message)
    {
        $this->authoriseFor($request, $message);

        if ($message->status !== 'queued') {
            return back()->with('error', 'That message has already left the queue.');
        }

        $outcome = $this->whatsapp->send($message);

        return back()->with($outcome['ok'] ? 'success' : 'error', $outcome['message']);
    }

    /**
     * Take a message out of the queue without sending it.
     *
     * Kept as a row rather than deleted: "we decided not to send this" is a
     * fact worth as much as "we sent this", and a rule that queues messages
     * nobody ever sends is a rule to switch off.
     */
    public function cancel(Request $request, MessageLog $message)
    {
        $this->authoriseFor($request, $message);

        if ($message->status !== 'queued') {
            return back()->with('error', 'That message has already left the queue.');
        }

        $message->update(['status' => 'cancelled', 'user_id' => $request->user()->id]);

        return back()->with('success', 'Message cancelled. It will not be sent.');
    }

    /** The lead behind the message has to be one this user could open. */
    private function authoriseFor(Request $request, MessageLog $message): void
    {
        abort_unless(
            MessageLog::whereKey($message->id)->visibleTo($request->user())->exists(),
            403,
        );
    }
}
