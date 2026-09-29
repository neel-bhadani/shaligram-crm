<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\AlertService;

/**
 * Queueing, opening and sending WhatsApp messages.
 *
 * Two roads to the customer, and every message row says which it took:
 *
 *   click   the browser hands pre-filled text to wa.me and a person presses
 *           send. Recorded as `opened`, never `sent` — nothing after the
 *           hand-over is observable from here. Works with no account at all.
 *
 *   api     Meta's WhatsApp Cloud API sends it and returns a message id.
 *           The only road that may write `sent`.
 *
 * The API is the road only while it is configured AND switched on. When it is
 * not, every sender here falls back to click rather than failing: a rule's
 * message waits in the Queue, and a manual send opens WhatsApp in the browser.
 *
 * THE 24-HOUR RULE. Meta lets a business send free-form text only within 24
 * hours of the customer's last message; outside that window only an approved
 * template may go. Nothing records inbound messages yet, so today every lead is
 * outside the window and the API sends templates only. See Lead::inWhatsAppWindow().
 */
class WhatsAppSender
{
    /** What an unavailable API says, in words the office admin can act on. */
    public const NOT_CONFIGURED =
        'WhatsApp API sending is not set up or is switched off, so nothing was sent by API. '
        .'The message is waiting in the Queue — open it in WhatsApp and send it yourself. '
        .'API sending needs WhatsApp Business Platform access, which is not the same as the '
        .'free WhatsApp Business app on a phone.';

    public const TEMPLATE_ONLY = 'WhatsApp only allows approved templates until the customer replies.';

    public const NO_NUMBER = 'This lead has no usable mobile number, so there is nothing to send to.';

    public function __construct(
        private TemplateRenderer $renderer,
        private WhatsAppCloudClient $client,
        private AlertService $alerts,
    ) {}

    /* ---------------- configuration ---------------- */

    public function integration(): Integration
    {
        return Integration::forProvider(config('automation.whatsapp.provider', 'whatsapp'));
    }

    /**
     * Enough credentials to make a send call at all.
     *
     * `is_active` is not part of this on purpose: this answers "could it send",
     * and the switch is a separate question — see apiReady().
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

    /** Configured and switched on: the API is the road. */
    public function apiReady(): bool
    {
        return $this->isConfigured() && (bool) $this->integration()->is_active;
    }

    /* ---------------- click-to-send ---------------- */

    /**
     * Put a click-to-send message in the review queue.
     *
     * The message is rendered now, against the lead as it is at this moment,
     * and the rendered text is what is stored — a template edited tomorrow does
     * not rewrite what a rule decided to say today.
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
            'lead_id' => $lead->id,
            'template_id' => $template->id,
            'user_id' => $user?->id,
            'rule_id' => $rule?->id,
            'mode' => 'click',
            'to_number' => $built['number'],
            'body' => $built['body'],
            'status' => 'queued',
        ]);
    }

    /**
     * The wa.me link for a message, and the record that it was opened.
     *
     * `opened`, never `sent`. The browser handed the text to WhatsApp; nothing
     * observable happens after that.
     */
    public function clickUrlFor(MessageLog $message): ?string
    {
        return $this->renderer->clickUrl($message->to_number, $message->body);
    }

    public function markOpened(MessageLog $message, User $user): void
    {
        $message->update([
            'mode' => 'click',
            'user_id' => $user->id,
            'status' => 'opened',
            'sent_at' => now(),
        ]);
    }

    /* ---------------- automatic: a rule ---------------- */

    /**
     * A rule's API send: write the row, hand it to the queue worker.
     *
     * Never sends inline — a slow Meta response must not hold up the stage
     * change that fired the rule. The job is dispatched after the rule's
     * transaction commits, so the worker cannot pick up a row that was rolled
     * back.
     *
     * When the API is not ready the same row is written as a click-to-send
     * message instead and waits in the Queue: `fallback` is true.
     *
     * @return array{message: ?MessageLog, fallback: bool, error: ?string}
     */
    public function queueTemplate(Lead $lead, WhatsAppTemplate $template, ?AutomationRule $rule = null): array
    {
        $prepared = $this->prepareTemplate($lead, $template);

        if ($prepared['error']) {
            return ['message' => null, 'fallback' => false, 'error' => $prepared['error']];
        }

        $ready = $this->apiReady();

        $message = MessageLog::create($prepared['row'] + [
            'rule_id' => $rule?->id,
            'mode' => $ready ? 'api' : 'click',
            'status' => 'queued',
        ]);

        if ($ready) {
            SendWhatsAppMessage::dispatch($message->id)->afterCommit();
        }

        return ['message' => $message, 'fallback' => ! $ready, 'error' => null];
    }

    /* ---------------- manual: a person on the lead ---------------- */

    /**
     * Send an approved template now, for a person waiting on the answer.
     *
     * @return array{ok: bool, status: ?string, message: string, click_url: ?string}
     */
    public function sendTemplateNow(Lead $lead, WhatsAppTemplate $template, User $user): array
    {
        $prepared = $this->prepareTemplate($lead, $template);

        if ($prepared['error']) {
            return $this->refused($prepared['error']);
        }

        return $this->deliverNow($prepared['row'] + ['user_id' => $user->id]);
    }

    /**
     * Send free text now. Only inside the 24-hour window when it goes by API;
     * a click-to-send fallback is the user's own WhatsApp and has no window.
     *
     * @return array{ok: bool, status: ?string, message: string, click_url: ?string}
     */
    public function sendTextNow(Lead $lead, string $text, User $user): array
    {
        $number = $this->renderer->waNumber($lead->mobile_number);

        if (! $number) {
            return $this->refused(self::NO_NUMBER);
        }

        if ($this->apiReady() && ! $lead->inWhatsAppWindow()) {
            return $this->refused(self::TEMPLATE_ONLY);
        }

        return $this->deliverNow([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'to_number' => $number,
            'body' => $text,
        ]);
    }

    /**
     * Send by API and report back, or open by click when the API is not ready.
     *
     * A manual send is not retried: the person who pressed the button is told
     * the reason and given the wa.me link to send it by hand instead.
     *
     * @param  array<string, mixed>  $row
     * @return array{ok: bool, status: ?string, message: string, click_url: ?string}
     */
    private function deliverNow(array $row): array
    {
        $url = $this->renderer->clickUrl($row['to_number'], $row['body']);

        if (! $this->apiReady()) {
            MessageLog::create($row + ['mode' => 'click', 'status' => 'opened', 'sent_at' => now()]);

            return [
                'ok' => true,
                'status' => 'opened',
                'message' => 'The WhatsApp API is not set up, so the message was opened in WhatsApp for you to send.',
                'click_url' => $url,
            ];
        }

        $message = MessageLog::create($row + ['mode' => 'api', 'status' => 'queued']);
        $result = $this->attempt($message);

        if ($result['ok']) {
            return ['ok' => true, 'status' => 'sent', 'message' => 'Sent on WhatsApp.', 'click_url' => null];
        }

        $this->markFailed($message);

        return ['ok' => false, 'status' => 'failed', 'message' => $result['reason'], 'click_url' => $url];
    }

    /* ---------------- the Queue tab's "Send by API" ---------------- */

    /**
     * Send one queued message through the API, now.
     *
     * When the API is not ready the message stays queued for click-to-send and
     * the answer says so. A click-to-send message is free text, so outside the
     * 24-hour window it is refused without calling Meta and stays queued.
     *
     * @return array{ok: bool, message: string}
     */
    public function send(MessageLog $message): array
    {
        if (! $this->apiReady()) {
            $message->update(['mode' => 'click', 'error' => self::NOT_CONFIGURED]);

            return ['ok' => false, 'message' => self::NOT_CONFIGURED];
        }

        $result = $this->attempt($message);

        if ($result['ok']) {
            return ['ok' => true, 'message' => 'Message sent.'];
        }

        if ($result['called']) {
            $this->markFailed($message);
        }

        return ['ok' => false, 'message' => $result['reason']];
    }

    /* ---------------- one API call ---------------- */

    /**
     * One API attempt at one message, recorded on its row.
     *
     * The row gets the attempt counted and, on failure, the whole of Meta's
     * error. It does NOT get `failed` — whether a failure is final is the
     * caller's decision: the queue worker retries, a person does not.
     *
     * The 24-hour rule is enforced here, where every API send passes: a row
     * with no Meta template is free text, and free text outside the window is
     * refused before Meta is asked. `called` is false for that and for a
     * template that stopped being sendable since the row was written.
     *
     * @return array{ok: bool, reason: ?string, retryable: bool, called: bool}
     */
    public function attempt(MessageLog $message): array
    {
        $template = $message->whatsapp_template_id ? $message->whatsappTemplate : null;

        $refusal = match (true) {
            $message->whatsapp_template_id && ! $template => 'The WhatsApp template this message used has been deleted.',
            $template !== null => $template->unsendableReason(),
            ! $message->lead?->inWhatsAppWindow() => self::TEMPLATE_ONLY,
            default => null,
        };

        if ($refusal) {
            $message->update(['error' => $refusal]);

            return ['ok' => false, 'reason' => $refusal, 'retryable' => false, 'called' => false];
        }

        $integration = $this->integration();

        $result = $this->client->send(
            (string) $integration->setting('phone_number_id'),
            (string) $integration->setting('access_token'),
            ['to' => $message->to_number] + ($template
                ? $this->templatePayload($template, (array) $message->parameters)
                : ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $message->body]]),
        );

        $message->attempts++;

        if ($result['ok']) {
            $message->fill([
                'mode' => 'api',
                'status' => 'sent',
                'meta_message_id' => $result['message_id'],
                'sent_at' => now(),
                'error' => null,
            ])->save();

            return ['ok' => true, 'reason' => null, 'retryable' => false, 'called' => true];
        }

        $message->error = $result['error'];
        $message->save();

        return ['ok' => false, 'reason' => $result['reason'], 'retryable' => $result['retryable'], 'called' => true];
    }

    /**
     * Give up on a message. Meta's error stays on the row, and the row stays
     * openable from the Queue as a click-to-send fallback.
     */
    public function markFailed(MessageLog $message, ?string $error = null): void
    {
        $message->update(['status' => 'failed', 'error' => $message->error ?? $error]);
    }

    /**
     * Tell the admins an automatic send gave up. Never silent: the rule fired,
     * nobody watched it, and the customer did not get the message.
     */
    public function alertFailed(MessageLog $message): void
    {
        $lead = $message->lead;

        $this->alerts->raiseMany(
            recipients: $this->alerts->admins(),
            type: 'whatsapp.failed',
            title: 'A WhatsApp message to '.($lead?->full_name ?? 'a lead').' could not be sent',
            body: 'Gave up after '.$message->attempts.' '.($message->attempts === 1 ? 'attempt' : 'attempts')
                .'. It is in the Queue — open it in WhatsApp to send it by hand. Meta said: '
                .str($message->error)->limit(300),
            lead: $lead,
            severity: 'warning',
            rule: $message->rule,
            actionUrl: route('automation.index', ['tab' => 'queue']),
        );
    }

    /* ---------------- internals ---------------- */

    /**
     * Everything a template send needs, checked, or the reason it cannot go.
     *
     * @return array{row: array<string, mixed>, error: ?string}
     */
    private function prepareTemplate(Lead $lead, WhatsAppTemplate $template): array
    {
        $number = $this->renderer->waNumber($lead->mobile_number);

        if (! $number) {
            return ['row' => [], 'error' => self::NO_NUMBER];
        }

        if ($reason = $template->unsendableReason()) {
            return ['row' => [], 'error' => $reason];
        }

        $filled = $this->renderer->fillMetaTemplate($template, $lead);

        if ($filled['empty']) {
            $blank = collect($filled['parameters'])
                ->whereIn('variable', $filled['empty'])
                ->map(fn (array $p) => '{'.$p['placeholder'].'}')
                ->implode(', ');

            return ['row' => [], 'error' => "This lead has nothing to fill {$blank} with, and WhatsApp will not send a blank."];
        }

        return ['row' => [
            'lead_id' => $lead->id,
            'whatsapp_template_id' => $template->id,
            'to_number' => $number,
            'body' => $filled['body'],
            'parameters' => $filled['parameters'],
        ], 'error' => null];
    }

    /**
     * The `template` half of a send, from the parameters stored on the row —
     * not re-rendered, so what Meta is sent is what the log says was sent.
     *
     * @param  list<array{variable: string, value: string}>  $parameters
     * @return array<string, mixed>
     */
    private function templatePayload(WhatsAppTemplate $template, array $parameters): array
    {
        $named = $template->parameter_format === 'named';

        $payload = [
            'type' => 'template',
            'template' => [
                'name' => $template->name,
                'language' => ['code' => $template->language],
            ],
        ];

        if ($parameters !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (array $p) => ['type' => 'text'] + ($named ? ['parameter_name' => $p['variable']] : []) + ['text' => $p['value']],
                    $parameters,
                ),
            ]];
        }

        return $payload;
    }

    /** @return array{ok: bool, status: ?string, message: string, click_url: ?string} */
    private function refused(string $why): array
    {
        return ['ok' => false, 'status' => null, 'message' => $why, 'click_url' => null];
    }
}
