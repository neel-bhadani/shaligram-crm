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
 *   API            queueTemplate() writes a row carrying the 11za template and
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
        .'Save the 11za auth token and origin website in the WhatsApp settings on the Queue tab. '
        .'Until then, open the message from the Queue and send it yourself.';

    /** Why an automatic message did not go to a customer who opted out. */
    public const OPTED_OUT = 'The customer asked not to be messaged on WhatsApp (opted out), so automatic messages skip them.';

    /** Settings sending needs, and what to call them when missing. */
    public const REQUIRED = [
        'auth_token' => '11za auth token',
        'origin_website' => 'origin website',
    ];

    public function __construct(
        private TemplateRenderer $renderer,
        private ElevenZaClient $client,
    ) {}

    /* ---------------- configuration ---------------- */

    public function integration(): Integration
    {
        return Integration::forProvider(config('automation.whatsapp.provider', 'whatsapp'));
    }

    /** Enough settings to send at all: an auth token and an origin website. */
    public function isConfigured(): bool
    {
        return $this->missingSettings() === [];
    }

    /**
     * The 11za host: the admin's override if one is saved, otherwise config.
     *
     * 11za's docs name two hosts; which is right is not yet confirmed, so it
     * can be changed without a deploy.
     */
    public function baseUrl(): string
    {
        return (string) ($this->integration()->setting('base_url') ?: config('automation.whatsapp.api.base'));
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
            'provider_template_name' => $template->provider_template_name,
            'provider_template_language' => $template->provider_template_language,
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
            'provider_template_name' => $template->provider_template_name,
            'provider_template_language' => $template->provider_template_language,
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

            return $this->skipped($row, "{{$name}} is empty for this lead, and WhatsApp refuses a template with an empty variable.");
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
            'provider_template_name' => $message->provider_template_name ?? $message->template?->provider_template_name,
            'provider_template_language' => $message->provider_template_language ?? $message->template?->provider_template_language,
        ]);

        SendWhatsAppMessage::dispatch($message->id)->afterCommit();

        return ['ok' => true, 'message' => 'Sending by API. The outcome appears in the message log.'];
    }

    /**
     * Settle an `unknown` row: a person looked it up in 11za's own message
     * log and found it delivered. Recorded as sent, at the time it was handed
     * to 11za, with who checked and when.
     */
    public function markCheckedDelivered(MessageLog $message, User $user): bool
    {
        return (bool) MessageLog::whereKey($message->id)->where('status', 'unknown')->update([
            'status' => 'sent',
            'sent_at' => $message->sending_started_at ?? now(),
            'check_result' => 'delivered',
            'checked_by' => $user->id,
            'checked_at' => now(),
        ]);
    }

    /**
     * Settle an `unknown` row the other way: a person looked it up in 11za's
     * log and it never went. Sent again, as the same row, through the worker.
     *
     * Checked here, where the admin is looking, rather than left for the job
     * to fail on — and a customer who has opted out since is not sent to.
     *
     * @return array{ok: bool, message: string}
     */
    public function resendCheckedNotSent(MessageLog $message, User $user): array
    {
        if ($message->status !== 'unknown') {
            return ['ok' => false, 'message' => 'That message has already been settled.'];
        }

        if ($message->lead?->hasOptedOutOfWhatsApp()) {
            return ['ok' => false, 'message' => 'This customer has opted out of WhatsApp since. It was not sent again.'];
        }

        if (! $this->apiEnabled()) {
            return ['ok' => false, 'message' => 'API sending is switched off in the WhatsApp settings, so it cannot be sent again from here.'];
        }

        if ($reason = $this->apiBlocker($message)) {
            return ['ok' => false, 'message' => $reason];
        }

        $settled = MessageLog::whereKey($message->id)->where('status', 'unknown')->update([
            'status' => 'queued',
            'sending_started_at' => null,
            'error' => null,
            'user_id' => $user->id,
            'check_result' => 'not_sent',
            'checked_by' => $user->id,
            'checked_at' => now(),
        ]);

        if (! $settled) {
            return ['ok' => false, 'message' => 'That message has already been settled.'];
        }

        SendWhatsAppMessage::dispatch($message->id)->afterCommit();

        return ['ok' => true, 'message' => 'Sending it again. The outcome appears in the message log.'];
    }

    /**
     * Send through 11za. Called by SendWhatsAppMessage and nothing else —
     * except for one case: an unconfigured API, which says so on the row and
     * on screen rather than failing somewhere nobody reads.
     *
     * The parameters are the ones stored on the row when it was queued, in
     * that order. Nothing is re-rendered and nothing is re-ordered here. The
     * template name and language are the row's too, falling back to the
     * message's for a row queued before they were recorded.
     *
     * 11za's response is stored as it came (token removed) whichever way it
     * went, because its shape is not yet known.
     *
     * A 2xx without a recognised message id is still `sent`, with
     * `confirmed` false, and is never retried. The id lookup is a guess; a
     * wrong guess must not turn a delivered message into a failure somebody
     * resends.
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

        // checked again here, at the last moment: a customer can opt out
        // between a rule queueing the message and the worker sending it
        if ($message->rule_id !== null && $message->lead?->hasOptedOutOfWhatsApp()) {
            $message->update(['status' => 'skipped', 'error' => self::OPTED_OUT]);

            return ['ok' => false, 'message' => self::OPTED_OUT, 'retry' => false];
        }

        $templateName = $message->provider_template_name ?: $message->template->provider_template_name;
        $language = $message->provider_template_language ?: $message->template->provider_template_language;

        try {
            $sent = $this->sendTemplate(
                (string) $message->to_number,
                (string) $message->to_name,
                $templateName,
                $language,
                array_map('strval', $message->params ?? []),
            );
        } catch (WhatsAppApiException $e) {
            $message->update([
                'error' => $e->explain(),
                'error_code' => $e->httpStatus ? "http {$e->httpStatus}" : null,
                'provider_response' => $e->raw,
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
            'confirmed' => $sent['id'] !== null,
            'provider_message_id' => $sent['id'],
            'provider_response' => $sent['raw'],
            'provider_template_name' => $templateName,
            'provider_template_language' => $language,
            'sent_at' => now(),
            'error' => null,
            'error_code' => null,
        ]);

        return ['ok' => true, 'message' => $message->outcome(), 'retry' => false];
    }

    /* ---------------- test connection ---------------- */

    /**
     * Send one real message, with the SAVED settings, to a number the admin
     * typed. Always answers.
     *
     * 11za has no endpoint that checks credentials without sending, so this is
     * the test: a chosen message, filled in with the placeholder examples
     * ("Rahul Mehta", "Skyline Residency"), to the admin's own phone. The
     * answer carries 11za's raw response, which is also how the shape of a
     * real response first gets seen. Not written to message_logs — there is
     * no lead.
     *
     * A 2xx without a recognised id is reported as sent-unconfirmed, not as a
     * failure: look at the phone and at the raw response before trying again.
     *
     * @return array{ok: bool, message: string, response: ?string}
     */
    public function testConnection(MessageTemplate $template, string $mobile): array
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            return $this->remember([
                'ok' => false,
                'message' => 'Not tested: save the '.collect($missing)->join(', ', ' and ').' first.',
                'response' => null,
            ]);
        }

        if ($reason = $template->apiUnsendableReason()) {
            return $this->remember(['ok' => false, 'message' => "Not tested: {$reason}", 'response' => null]);
        }

        $number = $this->renderer->waNumber($mobile);

        if (! $number) {
            return $this->remember([
                'ok' => false,
                'message' => "Not tested: \"{$mobile}\" is not a ten-digit mobile number.",
                'response' => null,
            ]);
        }

        $examples = array_map(fn (array $placeholder) => $placeholder['example'], $this->renderer->placeholders());
        $data = array_map(fn (string $name) => (string) ($examples[$name] ?? ''), array_values($template->placeholder_map ?? []));
        $label = $template->providerTemplateLabel();

        try {
            $sent = $this->sendTemplate($number, 'Test', $template->provider_template_name, $template->provider_template_language, $data);
        } catch (WhatsAppApiException $e) {
            return $this->remember([
                'ok' => false,
                'message' => "Test message {$label} to {$number} failed. {$e->getMessage()}",
                'response' => $e->raw,
            ]);
        }

        return $this->remember([
            'ok' => true,
            'message' => $sent['id'] !== null
                ? "11za accepted the test message {$label} to {$number} (message id {$sent['id']}). Check that phone."
                : "Sent (unconfirmed): 11za answered HTTP {$sent['status']} for the test message {$label} to {$number}, "
                    .'but no message id was recognised in its response. '.MessageLog::UNCONFIRMED_ADVICE,
            'response' => $sent['raw'],
        ]);
    }

    /* ---------------- 11za's template list ---------------- */

    /**
     * Read the template list from 11za, for the Messages tab's dropdown.
     *
     * Kept on the settings row with 11za's raw answer, so the dropdown still
     * fills after a reload and the raw answer can be read when the list comes
     * back empty. 11za's wording, when the list carries it, is copied onto
     * every message set up as that template — that copy is the only wording
     * the CRM keeps for a new message, and it is never typed by anybody.
     *
     * Always answers. When 11za cannot be asked, or answers with nothing the
     * parser recognises, the tab falls back to typing the name and language.
     *
     * @return array{ok: bool, message: string, templates: list<array<string, mixed>>, raw: ?string, at: ?string}
     */
    public function refreshProviderTemplates(): array
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            return [
                'ok' => false,
                'message' => 'The 11za template list cannot be read until the '.collect($missing)->join(', ', ' and ')
                    .' are saved. Type the template name and language instead.',
                'templates' => [],
                'raw' => null,
                'at' => null,
            ];
        }

        try {
            $list = $this->client->listTemplates((string) $this->integration()->setting('auth_token'), $this->baseUrl());
        } catch (WhatsAppApiException $e) {
            return [
                'ok' => false,
                'message' => 'Could not read the template list from 11za. '.$e->getMessage().' Type the template name and language instead.',
                'templates' => $this->providerTemplates(),
                'raw' => $e->raw,
                'at' => null,
            ];
        }

        $saved = [
            'templates' => $list['templates'],
            'raw' => $list['raw'],
            'at' => now()->toIso8601String(),
        ];

        $integration = $this->integration();
        $integration->mergeSettings(['template_list' => $saved]);
        $integration->save();

        foreach ($list['templates'] as $template) {
            if ($template['body'] !== null) {
                MessageTemplate::where('provider_template_name', $template['name'])
                    ->when($template['language'], fn ($q, $language) => $q->where('provider_template_language', $language))
                    ->update(['provider_body' => $template['body']]);
            }
        }

        $count = count($list['templates']);

        return $saved + [
            'ok' => $count > 0,
            'message' => $count > 0
                ? "11za lists {$count} template".($count === 1 ? '' : 's').'.'
                : '11za answered, but no template could be recognised in its answer (shown below). Type the template name and language instead.',
        ];
    }

    /**
     * The list as last read from 11za, or empty.
     *
     * @return list<array{name: string, language: ?string, body: ?string, variables: ?int}>
     */
    public function providerTemplates(): array
    {
        return (array) data_get($this->integration()->setting('template_list'), 'templates', []);
    }

    /** 11za's wording for one template, from the last list read, or null. */
    public function providerBodyFor(?string $name, ?string $language): ?string
    {
        if (blank($name)) {
            return null;
        }

        return collect($this->providerTemplates())
            ->first(fn (array $t) => $t['name'] === $name && ($t['language'] === null || $t['language'] === $language))['body'] ?? null;
    }

    /* ---------------- internals ---------------- */

    /**
     * {{1}}, {{2}}… for this lead, in `placeholder_map` order.
     *
     * The map was stored when the message was saved and is the only thing
     * that decides the order. Values are flattened to one line because WhatsApp
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
            return 'The message it was written from has been deleted, so there is no 11za template to send it as.';
        }

        if ($reason = $template->apiUnsendableReason()) {
            return $reason;
        }

        if ($message->params === null || count($message->params) !== count($template->placeholder_map ?? [])) {
            return 'This message was queued before its values were recorded, so it cannot be sent as a template.';
        }

        return null;
    }

    /** @return list<string> labels of the required settings that are blank */
    private function missingSettings(): array
    {
        $integration = $this->integration();

        return collect(self::REQUIRED)
            ->filter(fn (string $label, string $key) => blank($integration->setting($key)))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $data
     * @return array{id: string, status: int, raw: string}
     *
     * @throws WhatsAppApiException
     */
    private function sendTemplate(string $to, string $name, string $templateName, string $language, array $data): array
    {
        $integration = $this->integration();

        return $this->client->sendTemplate(
            (string) $integration->setting('auth_token'),
            $this->baseUrl(),
            (string) $integration->setting('origin_website'),
            $to,
            $name,
            $templateName,
            $language,
            $data,
        );
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
     * @param  array{ok: bool, message: string, response: ?string}  $result
     * @return array{ok: bool, message: string, response: ?string}
     */
    private function remember(array $result): array
    {
        $integration = $this->integration();
        $integration->mergeSettings(['last_test' => $result + ['at' => now()->toIso8601String()]]);
        $integration->save();

        return $result;
    }
}
