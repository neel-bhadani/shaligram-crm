<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Queueing, opening and — one day — sending WhatsApp messages.
 *
 * The shape of this class is a deliberate answer to a question the client has
 * not answered yet: which WhatsApp product do they actually have. The free
 * WhatsApp Business APP on a phone has no API and never will; sending from
 * software needs WhatsApp Business PLATFORM access through Meta or a provider,
 * with an approval process and a monthly bill behind it. Until that is settled
 * there are no credentials, so:
 *
 *   queue()    writes a row and stops. A rule may only ever do this. Nothing in
 *              this pass sends a message on its own.
 *
 *   opened()   the click-to-send path, which works today and is what the demo
 *              shows. The browser hands the pre-filled text to WhatsApp; we
 *              record that it was OPENED, because whether the user then pressed
 *              send is not observable from here and "sent" would be a lie.
 *
 *   send()     the API path. Fully built, switched off. An attempt with no
 *              credentials comes back saying exactly that — see
 *              NOT_CONFIGURED — rather than logging a failure nobody reads.
 */
class WhatsAppSender
{
    /** What an unconfigured API says, in words the office admin can act on. */
    public const NOT_CONFIGURED =
        'WhatsApp API sending is not set up yet. Nothing was sent. '
        . 'The API needs WhatsApp Business Platform access — a paid account through Meta or a '
        . 'provider — which is not the same as the free WhatsApp Business app on a phone. '
        . 'Until then, open the message from the Queue and send it yourself.';

    public function __construct(private TemplateRenderer $renderer) {}

    /* ---------------- configuration ---------------- */

    public function integration(): Integration
    {
        return Integration::forProvider(config('automation.whatsapp.provider', 'whatsapp'));
    }

    /**
     * Enough credentials to make an API call at all.
     *
     * `is_active` is not part of this on purpose: this answers "could it send",
     * and the switch is a separate question the caller asks separately.
     */
    public function isConfigured(): bool
    {
        $integration = $this->integration();

        foreach (['access_token', 'phone_number_id'] as $key) {
            if (blank($integration->setting($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether queued messages go out on their own.
     *
     * False unless somebody has explicitly turned it on AND the API is
     * configured — the default lives in code, not only in a settings row, so a
     * fresh install with no row at all queues rather than sends.
     */
    public function autoSends(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return (bool) $this->integration()->setting(
            'auto_send',
            config('automation.whatsapp.auto_send_default', false)
        );
    }

    /* ---------------- queueing ---------------- */

    /**
     * Put a message in the review queue.
     *
     * This is the ONLY thing a rule may do with WhatsApp. The message is
     * rendered now, against the lead as it is at this moment, and the rendered
     * text is what is stored — a template edited tomorrow does not rewrite what
     * a rule decided to say today.
     *
     * Returns null when there is nothing sendable: no usable mobile number.
     * A queued message nobody can open is worse than no row at all, because it
     * sits in the queue looking like work.
     */
    public function queue(
        Lead $lead,
        MessageTemplate $template,
        ?AutomationRule $rule = null,
        ?User $user = null,
    ): ?MessageLog {
        $built = $this->renderer->build($template, $lead);

        if (! $built['number']) {
            return null;
        }

        return MessageLog::create([
            'lead_id'     => $lead->id,
            'template_id' => $template->id,
            'user_id'     => $user?->id,
            'rule_id'     => $rule?->id,
            'mode'        => $this->autoSends() ? 'api' : 'click',
            'to_number'   => $built['number'],
            'body'        => $built['body'],
            'status'      => 'queued',
        ]);
    }

    /* ---------------- click-to-send ---------------- */

    /**
     * The wa.me link for a queued message, and the record that it was opened.
     *
     * `opened`, never `sent`. The browser handed the text to WhatsApp; nothing
     * observable happens after that, and a status claiming otherwise would make
     * the message log worthless as a record of what customers actually
     * received.
     */
    public function clickUrlFor(MessageLog $message): ?string
    {
        return $this->renderer->clickUrl($message->to_number, $message->body);
    }

    public function markOpened(MessageLog $message, User $user): void
    {
        $message->update([
            'mode'    => 'click',
            'user_id' => $user->id,
            'status'  => 'opened',
            'sent_at' => now(),
        ]);
    }

    /* ---------------- API send ---------------- */

    /**
     * Send through the WhatsApp Business Platform.
     *
     * Reached from SendWhatsAppMessage and from the Queue tab's "Send by API"
     * button, and today it always lands on the first branch. That branch is the
     * point of the method: an unconfigured attempt says so, in the response, in
     * the message log and on screen. Failing silently — a job that logs a stack
     * trace to a file nobody opens — is the thing this feature must not do,
     * because the admin's next move would be to send the same message five
     * more times.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(MessageLog $message): array
    {
        if (! $this->isConfigured()) {
            $message->update([
                'status' => 'failed',
                'error'  => self::NOT_CONFIGURED,
            ]);

            return ['ok' => false, 'message' => self::NOT_CONFIGURED];
        }

        $integration = $this->integration();
        $base        = rtrim((string) config('automation.whatsapp.api.base'), '/');
        $version     = config('automation.whatsapp.api.version');
        $phoneId     = $integration->setting('phone_number_id');

        try {
            $response = Http::withToken($integration->setting('access_token'))
                ->timeout((int) config('automation.whatsapp.api.timeout', 15))
                ->post("$base/$version/$phoneId/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $message->to_number,
                    'type'              => 'text',
                    'text'              => ['body' => $message->body],
                ]);
        } catch (Throwable $e) {
            $message->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'WhatsApp did not answer: ' . $e->getMessage()];
        }

        if ($response->failed()) {
            $error = (string) ($response->json('error.message') ?? $response->body());
            $message->update(['status' => 'failed', 'error' => $error]);

            return ['ok' => false, 'message' => 'WhatsApp refused the message: ' . $error];
        }

        $message->update([
            'mode'    => 'api',
            'status'  => 'sent',
            'sent_at' => now(),
            'error'   => null,
        ]);

        return ['ok' => true, 'message' => 'Message sent.'];
    }

    /**
     * Hand a queued message to the API, if and only if auto-send is on.
     *
     * Called from nowhere in this pass. It exists so that switching auto-send
     * on later is a settings change rather than a code change — and so that the
     * job, the status handling and the failure path are all written and
     * readable now, while somebody is looking at them.
     */
    public function dispatchIfAutomatic(MessageLog $message): void
    {
        if ($this->autoSends()) {
            SendWhatsAppMessage::dispatch($message->id);
        }
    }
}
