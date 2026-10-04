<?php

namespace App\Http\Controllers;

use App\Models\MessageLog;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\Request;

/**
 * The review queue: messages a rule wrote, waiting for a person to send them.
 *
 * With the API off, this is where every rule's message ends up and getting it
 * to a customer takes somebody opening it. With the API on and automatic
 * sending off, API messages wait here for Send by API.
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

        if ($refusal = $this->optedOutRefusal($message)) {
            return response()->json(['ok' => false, 'message' => $refusal], 422);
        }

        $url = $this->whatsapp->clickUrlFor($message);

        if (! $url) {
            return response()->json([
                'ok' => false,
                'message' => 'This lead has no usable mobile number, so there is nothing to open.',
            ], 422);
        }

        $this->whatsapp->markOpened($message, $request->user());

        return response()->json(['ok' => true, 'url' => $url]);
    }

    /**
     * Send by API — handed to the queue worker, never sent inline.
     *
     * An unconfigured API still gets its answer here, in a sentence the office
     * admin can act on and on the row itself: an admin who presses the button
     * finds out what is missing, instead of watching nothing happen.
     */
    public function send(Request $request, MessageLog $message)
    {
        $this->authoriseFor($request, $message);

        if ($message->status !== 'queued') {
            return back()->with('error', 'That message has already left the queue.');
        }

        if ($refusal = $this->optedOutRefusal($message)) {
            return back()->with('error', $refusal);
        }

        if (! $this->whatsapp->isConfigured()) {
            $outcome = $this->whatsapp->send($message);

            return back()->with('error', $outcome['message']);
        }

        $outcome = $this->whatsapp->dispatchQueued($message, $request->user());

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

    /**
     * "I checked 11za — it delivered." Settles an `unknown` row as sent.
     *
     * Only after looking: the wording on the button and in the dialog sends
     * the admin to 11za's log first, because a guess here marks a customer
     * contacted who never was.
     */
    public function checkedDelivered(Request $request, MessageLog $message)
    {
        $this->authoriseFor($request, $message);

        return $this->whatsapp->markCheckedDelivered($message, $request->user())
            ? back()->with('success', 'Recorded as sent, with your name as the person who checked 11za.')
            : back()->with('error', 'That message has already been settled.');
    }

    /** "I checked 11za — it never went." Sends it again, as the same row. */
    public function checkedNotSent(Request $request, MessageLog $message)
    {
        $this->authoriseFor($request, $message);

        $outcome = $this->whatsapp->resendCheckedNotSent($message, $request->user());

        return back()->with($outcome['ok'] ? 'success' : 'error', $outcome['message']);
    }

    /**
     * The Queue's buttons send without a warning, so they refuse a customer
     * who opted out. The lead's own WhatsApp section can still send one,
     * after asking.
     */
    private function optedOutRefusal(MessageLog $message): ?string
    {
        return $message->lead?->hasOptedOutOfWhatsApp()
            ? 'This customer asked not to be messaged on WhatsApp. If you must, send from the WhatsApp section on the lead, which asks you to confirm — or cancel this message.'
            : null;
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
