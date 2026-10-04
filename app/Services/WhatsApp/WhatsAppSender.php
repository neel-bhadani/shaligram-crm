<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppMessage;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\ProviderTemplate;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Why Send later is not offered. Click-to-send ends with a person pressing
     * send in WhatsApp, so there is nothing that could wait for a time.
     */
    public const NOT_SCHEDULABLE = 'Send later needs API sending. This one would open in WhatsApp for you to press send, so it cannot wait for a time.';

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
     * @param  ?CarbonInterface  $sendAt  send later, at this time. API only:
     *                                    when the message would fall back to
     *                                    click-to-send nothing is written and
     *                                    the result is `refused`
     * @return array{result: string, reason: ?string, message: ?MessageLog}
     *                                                                      result is dispatched | scheduled | queued | fallback | skipped | refused
     */
    public function queueTemplate(
        Lead $lead,
        MessageTemplate $template,
        ?AutomationRule $rule = null,
        ?User $user = null,
        bool $allowTerminal = true,
        ?int $dedupeMinutes = null,
        ?CarbonInterface $sendAt = null,
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

        if ($sendAt && (! $this->apiEnabled() || $unsendable)) {
            return ['result' => 'refused', 'reason' => self::NOT_SCHEDULABLE, 'message' => null];
        }

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

            return $this->skipped($row, $this->missingValueReason($name, $lead));
        }

        if ($sendAt) {
            $message = MessageLog::create($row + ['mode' => 'api', 'status' => 'queued', 'send_at' => $sendAt, 'scheduled_at' => now()]);

            SendWhatsAppMessage::dispatch($message->id)->delay($sendAt)->afterCommit();

            return ['result' => 'scheduled', 'reason' => null, 'message' => $message];
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
    public function dispatchQueued(MessageLog $message, User $user, ?CarbonInterface $sendAt = null): array
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
            'send_at' => $sendAt,
            'scheduled_at' => $sendAt ? now() : null,
            'provider_template_name' => $message->provider_template_name ?? $message->template?->provider_template_name,
            'provider_template_language' => $message->provider_template_language ?? $message->template?->provider_template_language,
        ]);

        if ($sendAt) {
            SendWhatsAppMessage::dispatch($message->id)->delay($sendAt)->afterCommit();

            return ['ok' => true, 'message' => self::scheduledFor($sendAt)];
        }

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

        if ($message->send_at !== null && ($reason = $this->resolveAtSendTime($message))) {
            $message->update(['status' => 'skipped', 'error' => $reason]);

            return ['ok' => false, 'message' => $reason, 'retry' => false];
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
     * Read the template list from 11za, for the Tags tab's dropdown.
     *
     * Kept in `provider_templates`: name, language and variable count, at most
     * `list_cap` of them, replaced whole on every successful read. 11za's
     * wording, when the list carries it, is copied onto every message set up
     * as that template — that copy is the only wording the CRM keeps, and it
     * is never typed by anybody. 11za's raw answer is never stored: when it
     * cannot be read it comes back once, trimmed, for the screen.
     *
     * 11za's maximum page size is undocumented, so the sizes in `list_limits`
     * are tried in turn: an answer from 11za that is a refusal, or carries no
     * template, moves on to the next size. No answer at all (a timeout) does
     * not — that is not about the size. The size that worked is stored and
     * shown, so the first real read says what 11za's limit is.
     *
     * Always answers, and never throws. A timeout, an outage, an answer the
     * parser does not recognise and a database error all come back as `ok`
     * false with a sentence, and the list already stored is left as it was.
     * A failed read is remembered so the tab does not ask again by itself;
     * only the Refresh button does.
     *
     * @return array{ok: bool, message: string, templates: list<array{name: string, language: ?string, variables: ?int}>, total: int, at: ?string, failed: bool, raw: ?string}
     */
    public function refreshProviderTemplates(): array
    {
        try {
            return $this->readProviderTemplates();
        } catch (Throwable $e) {
            // the message only: the token may be in it, and the trace holds the request
            Log::warning('11za template list: '.class_basename($e).': '.$this->redactQuietly($e->getMessage()));

            $this->rememberListFailure();

            return $this->listAnswer(false,
                'Something went wrong reading the template list. Nothing was changed. '
                .'Press Refresh to try again, or type the template name and language instead.');
        }
    }

    /**
     * The list as last read from 11za, and when, for the page.
     *
     * `total` is how many 11za listed at that read; more than the templates
     * here means the list was cut at `list_cap`.
     *
     * @return array{templates: list<array{name: string, language: ?string, variables: ?int}>, total: int, at: ?string, page_size: ?int, failed: bool}
     */
    public function providerTemplateList(): array
    {
        $read = (array) $this->integration()->setting('template_list_read', []);

        $templates = ProviderTemplate::orderBy('id')
            ->limit($this->listCap())
            ->get()
            ->map(fn (ProviderTemplate $t) => $t->toListEntry())
            ->all();

        return [
            'templates' => $templates,
            'total' => max((int) ($read['total'] ?? 0), count($templates)),
            'at' => $read['at'] ?? null,
            'page_size' => $read['page_size'] ?? null,
            'failed' => ($read['failed_at'] ?? null) !== null,
        ];
    }

    /** @return array{ok: bool, message: string, templates: list<array<string, mixed>>, total: int, at: ?string, failed: bool, raw: ?string} */
    private function readProviderTemplates(): array
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            return $this->listAnswer(false,
                'The 11za template list cannot be read until the '.collect($missing)->join(', ', ' and ')
                .' are saved. Type the template name and language instead.');
        }

        $sizes = $this->listPageSizes();
        $refused = [];

        foreach ($sizes as $size) {
            try {
                $list = $this->client->listTemplates((string) $this->integration()->setting('auth_token'), $this->baseUrl(), $size);
            } catch (WhatsAppApiException $e) {
                // no answer at all is not about the size: asking again smaller
                // would only wait out the same timeout
                if ($e->httpStatus === null) {
                    $this->rememberListFailure();

                    return $this->listAnswer(false,
                        'Could not read the template list from 11za. '.$e->getMessage()
                        .$this->triedSizes($refused).' Nothing was changed. Press Refresh to try again, or type the template name and language instead.');
                }

                $refused[] = $size;
                $last = ['message' => $e->getMessage(), 'raw' => $e->raw];

                continue;
            }

            $entries = collect($list['templates'])
                ->filter(fn (array $t) => $t['name'] !== '' && mb_strlen($t['name']) <= 255)
                ->values();

            if ($entries->isNotEmpty()) {
                break;
            }

            $refused[] = $size;
            $last = ['message' => '11za answered, but no template could be recognised in its answer (shown below).', 'raw' => $list['raw']];
        }

        if (count($refused) === count($sizes)) {
            $this->rememberListFailure();

            return $this->listAnswer(false,
                'Could not read the template list from 11za. '.$last['message']
                .' Tried page sizes '.implode(', ', $sizes).'; 11za refused or gave no templates for each — its last answer is shown.'
                .' Nothing was changed. Press Refresh to try again, or type the template name and language instead.',
                $last['raw']);
        }

        try {
            $this->storeProviderTemplates($entries->all(), $size);
        } catch (Throwable $e) {
            Log::warning('11za template list could not be saved: '.class_basename($e).': '.$this->redactQuietly($e->getMessage()));

            $this->rememberListFailure();

            return $this->listAnswer(false,
                'The list was read from 11za but could not be saved. The previous list is unchanged. '
                .'Press Refresh to try again.');
        }

        $total = $entries->count();
        $cap = $this->listCap();

        return $this->listAnswer(true, ($total > $cap
            ? "Showing the first {$cap} of {$total} templates from 11za. Type the name for any other."
            : "11za lists {$total} template".($total === 1 ? '' : 's').'.')
            ." Read with page size {$size}".$this->triedSizes($refused).'.');
    }

    /** ", after 1000 was refused" — so the first real refresh tells us 11za's limit. */
    private function triedSizes(array $refused): string
    {
        if ($refused === []) {
            return '';
        }

        return ', after '.implode(' and ', $refused).' '.(count($refused) === 1 ? 'was' : 'were').' refused';
    }

    /** @return list<int> */
    private function listPageSizes(): array
    {
        $sizes = array_values(array_filter(array_map('intval', (array) config('automation.whatsapp.api.list_limits', [100]))));

        return $sizes === [] ? [100] : $sizes;
    }

    /**
     * Replace the stored list, copy 11za's wording onto the messages set up
     * as each template, and note when — all or nothing.
     *
     * @param  list<array{name: string, language: ?string, body: ?string, variables: ?int}>  $entries
     * @param  int  $pageSize  the size 11za accepted, kept so the tab can show it
     */
    private function storeProviderTemplates(array $entries, int $pageSize): void
    {
        DB::transaction(function () use ($entries, $pageSize) {
            ProviderTemplate::query()->delete();

            $now = now();

            collect($entries)
                ->take($this->listCap())
                ->map(fn (array $t) => [
                    'name' => $t['name'],
                    'language' => $t['language'] !== null && mb_strlen($t['language']) <= 32 ? $t['language'] : null,
                    'variables' => $t['variables'] !== null && $t['variables'] >= 0 && $t['variables'] <= 1000 ? $t['variables'] : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->chunk(100)
                ->each(fn ($chunk) => ProviderTemplate::insert($chunk->values()->all()));

            foreach ($entries as $template) {
                if ($template['body'] !== null) {
                    MessageTemplate::where('provider_template_name', $template['name'])
                        ->when($template['language'], fn ($q, $language) => $q->where('provider_template_language', $language))
                        ->update(['provider_body' => $template['body']]);
                }
            }

            $integration = $this->integration();
            $integration->mergeSettings(['template_list_read' => [
                'at' => $now->toIso8601String(),
                'total' => count($entries),
                'page_size' => $pageSize,
                'failed_at' => null,
            ]]);
            $integration->save();
        });
    }

    /**
     * Note that the last read failed, so the tab stops asking by itself.
     * Best effort: when the database is what failed, this fails too, quietly.
     */
    private function rememberListFailure(): void
    {
        try {
            $integration = $this->integration();
            $read = (array) $integration->setting('template_list_read', []);
            $integration->mergeSettings(['template_list_read' => ['failed_at' => now()->toIso8601String()] + $read]);
            $integration->save();
        } catch (Throwable) {
            // the answer to the admin already says what went wrong
        }
    }

    /**
     * The answer to a refresh: what happened, and the list as it now stands.
     * 11za's raw answer comes back trimmed, and only when it explains a
     * failure. It has had the token removed by ElevenZaClient.
     *
     * @return array{ok: bool, message: string, templates: list<array<string, mixed>>, total: int, at: ?string, failed: bool, raw: ?string}
     */
    private function listAnswer(bool $ok, string $message, ?string $raw = null): array
    {
        try {
            $list = $this->providerTemplateList();
        } catch (Throwable) {
            $list = ['templates' => [], 'total' => 0, 'at' => null, 'page_size' => null, 'failed' => true];
        }

        $limit = (int) config('automation.whatsapp.api.list_raw_excerpt', 2000);

        if ($ok || $raw === null || trim($raw) === '') {
            $raw = null;
        } elseif (mb_strlen($raw) > $limit) {
            $raw = mb_substr($raw, 0, $limit).'…';
        }

        return ['ok' => $ok, 'message' => $message] + $list + ['raw' => $raw];
    }

    /** The token removed from $text, or the whole text withheld when the token cannot be read. */
    private function redactQuietly(string $text): string
    {
        try {
            return $this->client->redact($text, (string) $this->integration()->setting('auth_token'));
        } catch (Throwable) {
            return '(not shown: the settings could not be read to remove the token)';
        }
    }

    private function listCap(): int
    {
        return (int) config('automation.whatsapp.api.list_cap', 500);
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

    /**
     * A scheduled message, read again from the lead at the time it goes: the
     * number, the name and every value are this moment's, not the moment it
     * was scheduled. Written onto the row, so the log shows what was sent.
     *
     * Returns why it must not go, or null. A lead deleted since, a number
     * gone, a tag switched off, an empty value, or a customer who opted out
     * after it was scheduled — each is a `skipped` row with the reason, never
     * a send to a blank number.
     */
    private function resolveAtSendTime(MessageLog $message): ?string
    {
        $lead = Lead::withTrashed()->with('project', 'owner')->find($message->lead_id);

        if (! $lead || $lead->trashed()) {
            return 'The lead was deleted before the scheduled time, so it was not sent.';
        }

        $template = $message->template;

        if ($template && ! $template->is_active) {
            return "The tag \"{$template->name}\" was switched off before the scheduled time, so it was not sent.";
        }

        if (! $template) {
            return null; // apiBlocker() says why
        }

        $optedOutAt = $lead->whatsapp_opted_out_at;

        if ($optedOutAt && ($message->rule_id !== null || $optedOutAt->gt($message->created_at))) {
            return 'The customer opted out of WhatsApp after this was scheduled, so it was not sent.';
        }

        $built = $this->renderer->build($template, $lead);

        if (! $built['number']) {
            return 'By the scheduled time the lead had no usable mobile number'
                .(filled($lead->mobile_number) ? " (\"{$lead->mobile_number}\" is not ten digits)" : '')
                .', so it was not sent.';
        }

        $params = $this->paramsFor($template, $lead);

        $message->update([
            'to_number' => $built['number'],
            'to_name' => $lead->full_name,
            'body' => $built['body'],
            'params' => $params,
            'dedupe_key' => hash('sha256', $built['number'].'|'.$template->id.'|'.json_encode($params)),
        ]);

        $empty = array_search('', $params, true);

        return $empty === false
            ? null
            : $this->missingValueReason(array_values($template->placeholder_map ?? [])[$empty], $lead);
    }

    /**
     * "2026-10-05T15:30" from a date-time box, read as India time whatever
     * the browser's own timezone. Null for send now; false when it is not in
     * the future.
     */
    public static function sendAtFrom(?string $typed): CarbonInterface|false|null
    {
        if ($typed === null || $typed === '') {
            return null;
        }

        $at = Carbon::createFromFormat('Y-m-d\TH:i', $typed, 'Asia/Kolkata');

        return $at && $at->isFuture() ? $at->setTimezone(config('app.timezone')) : false;
    }

    /** "Scheduled for 5 Oct, 3:30 pm (India time)…" — said wherever a send is scheduled. */
    public static function scheduledFor(CarbonInterface $sendAt): string
    {
        return 'Scheduled for '.$sendAt->copy()->setTimezone('Asia/Kolkata')->format('j M, g:i a')
            .' (India time). Until then it can be cancelled, from this lead or the Queue.';
    }

    /**
     * What to fix when a variable would go out empty — WhatsApp refuses a
     * template with an empty one. Names the lead's field, not {{1}}.
     */
    private function missingValueReason(string $field, Lead $lead): string
    {
        $needs = config("automation.whatsapp.placeholders.{$field}.needs")
            ?? config("automation.whatsapp.placeholders.{$field}.label")
            ?? $field;

        if (in_array($field, ['owner_name', 'owner_phone'], true)) {
            $owner = $lead->owner;

            return $owner
                ? "This tag needs {$needs}, and {$owner->display_name} has none saved."
                : "This tag needs {$needs}, and nobody is assigned to this lead.";
        }

        return "This tag needs {$needs}, and this lead has none.";
    }

    /** Why this message cannot go by API, or null. */
    private function apiBlocker(MessageLog $message): ?string
    {
        $template = $message->template;

        if (! $template) {
            return 'The tag it was written from has been deleted, so there is no 11za template to send it as.';
        }

        if ($reason = $template->apiUnsendableReason()) {
            return $reason;
        }

        if ($message->params === null || count($message->params) !== count($template->placeholder_map ?? [])) {
            return 'This message was queued before its values were recorded, so it cannot be sent by API.';
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
