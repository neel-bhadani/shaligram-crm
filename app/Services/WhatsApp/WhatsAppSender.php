<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\CrmTaxonomy;

/**
 * Queueing, opening and sending WhatsApp messages. One message, one lead.
 *
 * Two roads, and the second always falls back to the first:
 *
 *   click-to-send  queue() writes a row; a person opens it in WhatsApp from
 *                  the Queue tab. Recorded as `opened`, never `sent`, because
 *                  what happens after the browser hands the text over is not
 *                  observable from here.
 *
 *   API            queueTemplate() writes a row carrying the Meta template and
 *                  its parameters, fixed now, and the SendWhatsAppMessage job
 *                  sends it. Only ever through the queue, never inline. When
 *                  the API is not configured or is switched off, the same call
 *                  writes a click-to-send row instead — a rule never errors
 *                  because WhatsApp is not set up.
 *
 * Two switches, both on the `whatsapp` integrations row:
 *
 *   api_enabled  "Use API sending". Off: everything is click-to-send.
 *   auto_send    "Send queued messages automatically". Off: API messages a
 *                rule writes wait in the Queue for an admin to press Send by
 *                API. A person sending from a lead does not wait for this —
 *                pressing Send is the approval.
 *
 * Deliberately NOT `is_active`. The Integrations page reads that column for
 * its own `whatsapp` card, which is about inbound enquiries and is not built.
 */
class WhatsAppSender
{
    /** What an unconfigured API says, in words the office admin can act on. */
    public const NOT_CONFIGURED =
        'WhatsApp API sending is not set up yet. Nothing was sent. '
        .'The API needs WhatsApp Business Platform access — a paid account through Meta or a '
        .'provider — which is not the same as the free WhatsApp Business app on a phone. '
        .'Until then, open the message from the Queue and send it yourself.';

    /** Settings the Test connection button needs, and what to call them when missing. */
    public const REQUIRED_FOR_TEST = [
        'phone_number_id' => 'Phone Number ID',
        'waba_id' => 'WhatsApp Business Account ID',
        'access_token' => 'access token',
    ];

    public function __construct(
        private TemplateRenderer $renderer,
        private WhatsAppCloudClient $client,
    ) {}

    /* ---------------- configuration ---------------- */

    public function integration(): Integration
    {
        return Integration::forProvider(config('automation.whatsapp.provider', 'whatsapp'));
    }

    /**
     * Enough credentials to send at all: a phone number id and a token.
     *
     * The WABA id is not part of this. Sending never uses it — only syncing
     * templates and testing the connection do, and both ask for it themselves.
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

    /** "Use API sending" is on AND there is something to send with. */
    public function apiEnabled(): bool
    {
        return $this->isConfigured() && (bool) $this->integration()->setting('api_enabled', false);
    }

    /**
     * Whether a rule's API message goes straight to the worker.
     *
     * False unless both switches are on — the default lives in code, not only
     * in a settings row, so a fresh install queues rather than sends.
     */
    public function autoSends(): bool
    {
        return $this->apiEnabled() && (bool) $this->integration()->setting(
            'auto_send',
            config('automation.whatsapp.auto_send_default', false)
        );
    }

    /* ---------------- click-to-send ---------------- */

    /**
     * Put a message in the review queue for somebody to open.
     *
     * Rendered now, against the lead as it is at this moment, and the rendered
     * text is what is stored — a template edited tomorrow does not rewrite
     * what a rule decided to say today. The parameters are stored beside it,
     * so an admin can still choose to send it by API later.
     *
     * Returns null when there is no usable mobile number. A queued message
     * nobody can open is worse than no row at all, because it sits in the
     * queue looking like work.
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
            'whatsapp_template_id' => $template->whatsapp_template_id,
            'user_id' => $user?->id,
            'rule_id' => $rule?->id,
            'mode' => 'click',
            'to_number' => $built['number'],
            'to_name' => $lead->full_name,
            'body' => $built['body'],
            'params' => $this->paramsFor($template, $lead),
            'status' => 'queued',
        ]);
    }

    /**
     * The wa.me link for a queued message.
     */
    public function clickUrlFor(MessageLog $message): ?string
    {
        return $this->renderer->clickUrl($message->to_number, $message->body);
    }

    /**
     * `opened`, never `sent`. The browser handed the text to WhatsApp; nothing
     * observable happens after that.
     */
    public function markOpened(MessageLog $message, User $user): void
    {
        $message->update([
            'mode' => 'click',
            'user_id' => $user->id,
            'status' => 'opened',
            'sent_at' => now(),
        ]);
    }

    /* ---------------- API ---------------- */

    /**
     * Send one approved template to one lead, through the queue.
     *
     * Every refusal is written as a `skipped` row with the reason, so the
     * lead's message history answers "did they get it" with "no, because…"
     * rather than with silence.
     *
     * @param  bool  $allowTerminal  a rule has to opt in to messaging booked
     *                               or lost leads; a person sending by hand
     *                               has already decided
     * @param  ?int  $dedupeMinutes  skip if this exact message — same number,
     *                               same template, same values — went out
     *                               this recently, from any lead. Null for a
     *                               hand-sent message.
     * @return array{result: string, reason: ?string, message: ?MessageLog}
     *                                                                      result is dispatched | queued | fallback | skipped
     */
    public function queueTemplate(
        Lead $lead,
        MessageTemplate $template,
        ?AutomationRule $rule = null,
        ?User $user = null,
        bool $allowTerminal = true,
        ?int $dedupeMinutes = null,
    ): array {
        $lead->loadMissing('project', 'owner');

        $built = $this->renderer->build($template, $lead);
        $params = $this->paramsFor($template, $lead);

        $row = [
            'lead_id' => $lead->id,
            'template_id' => $template->id,
            'whatsapp_template_id' => $template->whatsapp_template_id,
            'user_id' => $user?->id,
            'rule_id' => $rule?->id,
            'to_number' => $built['number'],
            'to_name' => $lead->full_name,
            'body' => $built['body'],
            'params' => $params,
        ];

        if (! $allowTerminal && CrmTaxonomy::isTerminal($lead->stage)) {
            return $this->skipped($row, 'The lead is '.CrmTaxonomy::stageLabel($lead->stage)
                .', and this rule is not set to message booked or lost leads.');
        }

        if (! $built['number']) {
            return $this->skipped($row, 'The lead has no usable mobile number'
                .(filled($lead->mobile_number) ? " (\"{$lead->mobile_number}\" is not ten digits)" : '')
                .', so there is nothing to send to.');
        }

        $row['dedupe_key'] = hash('sha256', $built['number'].'|'.$template->id.'|'.json_encode($params));

        if ($dedupeMinutes !== null && ($earlier = $this->recentDuplicate($row['dedupe_key'], $dedupeMinutes))) {
            return $this->skipped($row, "This exact message already went to {$built['number']} via lead #{$earlier->lead_id} "
                ."in the last {$dedupeMinutes} minutes.");
        }

        $unsendable = $this->apiEnabled() ? $template->apiUnsendableReason() : null;

        if (! $this->apiEnabled() || $unsendable) {
            $message = MessageLog::create($row + ['mode' => 'click', 'status' => 'queued']);

            return [
                'result' => 'fallback',
                'reason' => 'Queued for click-to-send: '.($unsendable ?? 'WhatsApp API sending is switched off or not set up.'),
                'message' => $message,
            ];
        }

        $empty = array_search('', $params, true);

        if ($empty !== false) {
            $name = array_values($template->placeholder_map ?? [])[$empty];

            return $this->skipped($row, "{{$name}} is empty for this lead, and Meta refuses a template with an empty variable.");
        }

        $message = MessageLog::create($row + ['mode' => 'api', 'status' => 'queued']);

        if ($user || $this->autoSends()) {
            SendWhatsAppMessage::dispatch($message->id)->afterCommit();

            return ['result' => 'dispatched', 'reason' => null, 'message' => $message];
        }

        return [
            'result' => 'queued',
            'reason' => 'Waiting in the Queue: automatic sending is off, so an admin sends it with Send by API.',
            'message' => $message,
        ];
    }

    /**
     * Hand a queued message to the worker — the Queue tab's Send by API.
     *
     * Checked here, where the admin is looking, rather than left for the job
     * to fail on: a message that cannot go by API says why and stays queued
     * for click-to-send.
     *
     * @return array{ok: bool, message: string}
     */
    public function dispatchQueued(MessageLog $message, User $user): array
    {
        if (! $this->apiEnabled()) {
            return ['ok' => false, 'message' => 'API sending is switched off in the WhatsApp settings. Open the message in WhatsApp instead.'];
        }

        if ($reason = $this->apiBlocker($message)) {
            return ['ok' => false, 'message' => $reason.' Open it in WhatsApp instead.'];
        }

        $message->update([
            'mode' => 'api',
            'user_id' => $user->id,
            'whatsapp_template_id' => $message->whatsapp_template_id ?? $message->template?->whatsapp_template_id,
        ]);

        SendWhatsAppMessage::dispatch($message->id)->afterCommit();

        return ['ok' => true, 'message' => 'Sending by API. The outcome appears in the message log.'];
    }

    /**
     * Send through the Cloud API. Called by SendWhatsAppMessage and nothing
     * else — except for one case: an unconfigured API, which says so on the
     * row and on screen rather than failing somewhere nobody reads.
     *
     * The parameters are the ones stored on the row when it was queued, in
     * that order. Nothing is re-rendered and nothing is re-ordered here.
     *
     * @return array{ok: bool, message: string, retry: bool}
     */
    public function send(MessageLog $message): array
    {
        if (! $this->isConfigured()) {
            $message->update(['status' => 'failed', 'error' => self::NOT_CONFIGURED]);

            return ['ok' => false, 'message' => self::NOT_CONFIGURED, 'retry' => false];
        }

        if ($reason = $this->apiBlocker($message)) {
            $message->update(['status' => 'failed', 'error' => $reason]);

            return ['ok' => false, 'message' => $reason, 'retry' => false];
        }

        $meta = $message->whatsappTemplate ?? $message->template->whatsappTemplate;
        $integration = $this->integration();

        try {
            $wamid = $this->client->sendTemplate(
                (string) $integration->setting('access_token'),
                (string) $integration->setting('phone_number_id'),
                (string) $message->to_number,
                $meta->name,
                $meta->language,
                array_map('strval', $message->params ?? []),
            );
        } catch (WhatsAppApiException $e) {
            $message->update([
                'error' => $e->explain(),
                'error_code' => $e->metaCode !== null ? (string) $e->metaCode : ($e->httpStatus ? "http {$e->httpStatus}" : null),
            ]);

            if ($e->isRetryable()) {
                return ['ok' => false, 'message' => $e->explain(), 'retry' => true];
            }

            $message->update(['status' => 'failed']);

            return ['ok' => false, 'message' => $e->explain(), 'retry' => false];
        }

        $message->update([
            'mode' => 'api',
            'status' => 'sent',
            'wamid' => $wamid,
            'whatsapp_template_id' => $meta->id,
            'sent_at' => now(),
            'error' => null,
            'error_code' => null,
        ]);

        return ['ok' => true, 'message' => "Accepted by Meta ({$wamid}).", 'retry' => false];
    }

    /* ---------------- test connection ---------------- */

    /**
     * Ask Meta whether the SAVED settings work. Always answers.
     *
     * Three checks: the token can read the phone number (and what number it
     * is), the token can read the business account, and the phone number is
     * on that account — the last one is what catches a Phone Number ID and a
     * WABA ID pasted into each other's boxes.
     *
     * @return array{ok: bool, message: string, code: ?int}
     */
    public function testConnection(): array
    {
        $integration = $this->integration();

        $missing = collect(self::REQUIRED_FOR_TEST)
            ->filter(fn (string $label, string $key) => blank($integration->setting($key)))
            ->values();

        if ($missing->isNotEmpty()) {
            return $this->remember([
                'ok' => false,
                'code' => null,
                'message' => 'Not tested: save the '.$missing->join(', ', ' and ').' first.',
            ]);
        }

        $token = (string) $integration->setting('access_token');
        $phoneId = (string) $integration->setting('phone_number_id');
        $wabaId = (string) $integration->setting('waba_id');

        try {
            $phone = $this->client->phoneNumber($token, $phoneId);
            $onAccount = $this->client->phoneNumberIds($token, $wabaId);
        } catch (WhatsAppApiException $e) {
            return $this->remember([
                'ok' => false,
                'code' => $e->metaCode,
                'message' => 'Connection failed. '.$e->explain(),
            ]);
        }

        if (! in_array($phoneId, $onAccount, true)) {
            return $this->remember([
                'ok' => false,
                'code' => null,
                'message' => "Meta answered, but Phone Number ID {$phoneId} is not on WhatsApp Business Account {$wabaId}. "
                    .'Check the two IDs have not been swapped.',
            ]);
        }

        $number = $phone['display_phone_number'] ?? $phoneId;
        $name = $phone['verified_name'] ? " ({$phone['verified_name']})" : '';

        return $this->remember([
            'ok' => true,
            'code' => null,
            'message' => "Connected. Messages will be sent from {$number}{$name}.",
        ]);
    }

    /* ---------------- internals ---------------- */

    /**
     * {{1}}, {{2}}… for this lead, in `placeholder_map` order.
     *
     * The map was stored when the message was saved and is the only thing
     * that decides the order. Values are flattened to one line because Meta
     * refuses a parameter with a newline, a tab or a run of spaces in it.
     *
     * @return list<string>
     */
    public function paramsFor(MessageTemplate $template, Lead $lead): array
    {
        $values = $this->renderer->valuesFor($lead);

        return array_map(
            fn (string $name) => trim((string) preg_replace('/\s+/', ' ', (string) ($values[$name] ?? ''))),
            array_values($template->placeholder_map ?? []),
        );
    }

    /** Why this message cannot go by API, or null. */
    private function apiBlocker(MessageLog $message): ?string
    {
        $template = $message->template;

        if (! $template) {
            return 'The message it was written from has been deleted, so there is no Meta template to send it as.';
        }

        if ($reason = $template->apiUnsendableReason()) {
            return $reason;
        }

        if ($message->params === null || count($message->params) !== count($template->placeholder_map ?? [])) {
            return 'This message was queued before its values were recorded, so it cannot be sent as a template.';
        }

        return null;
    }

    private function recentDuplicate(string $key, int $minutes): ?MessageLog
    {
        return MessageLog::where('dedupe_key', $key)
            ->whereIn('status', MessageLog::IN_FLIGHT)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->latest('id')
            ->first();
    }

    /** @return array{result: string, reason: string, message: MessageLog} */
    private function skipped(array $row, string $reason): array
    {
        $message = MessageLog::create($row + ['mode' => 'api', 'status' => 'skipped', 'error' => $reason]);

        return ['result' => 'skipped', 'reason' => $reason, 'message' => $message];
    }

    /**
     * Keep the last result on the settings row, so the card still shows it
     * after a reload.
     *
     * @param  array{ok: bool, message: string, code: ?int}  $result
     * @return array{ok: bool, message: string, code: ?int}
     */
    private function remember(array $result): array
    {
        $integration = $this->integration();
        $integration->mergeSettings(['last_test' => $result + ['at' => now()->toIso8601String()]]);
        $integration->save();

        return $result;
    }
}
