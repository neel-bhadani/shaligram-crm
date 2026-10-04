<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsAlerts;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\MessageBatch;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Services\Automation\AutoSend;
use App\Services\Automation\RuleCatalog;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The Automation page. Six tabs, one Inertia page, admin only.
 *
 * Admin only for real: `role:admin` sits on the route group in routes/web.php,
 * so typing /automation as a telecaller is a 403 rather than an empty screen.
 * The hidden sidebar link is presentation; the middleware is the refusal.
 *
 * Everything is loaded in one response rather than a request per tab. These are
 * small tables — a builder's office will have a dozen rules and a handful of
 * templates — and the alternative is five spinners on a page whose whole
 * purpose is to let somebody see how the pieces fit together.
 *
 * THE SECRET. The 11za auth token is stored encrypted in `integrations` and
 * never leaves the server. This controller sends `configured`, `autoSend` and a
 * masked tail, and never `$integration->settings` — the cast decrypts on read,
 * so handing that array to Inertia would put a live token in the page source.
 */
class AutomationController extends Controller
{
    use ListsAlerts;

    public const TABS = ['auto_send', 'tags', 'rules', 'queue', 'alerts', 'activity'];

    public function __construct(
        private AutoSend $autoSend,
        private RuleCatalog $catalogue,
        private WhatsAppSender $whatsapp,
        private TemplateRenderer $renderer,
    ) {}

    public function index(Request $request)
    {
        // the Tags tab was the Messages tab, at ?tab=templates
        if ($request->query('tab') === 'templates') {
            return redirect()->route('automation.index', ['tab' => 'tags'] + $request->query());
        }

        $tab = in_array($request->query('tab'), self::TABS, true)
            ? $request->query('tab')
            : 'auto_send';

        $rowRuleIds = $this->autoSend->rowRuleIds();

        return Inertia::render('Automation/Index', [
            'tab' => $tab,
            'autoSend' => $this->autoSend->rows(),
            // every rule the Auto-send rows do not show, so none is invisible
            'otherAutomation' => $this->autoSend->otherRules()->map(fn (AutomationRule $rule) => $this->ruleRow($rule))->all(),
            // the Rules tab: not in the tab bar, reached by /automation?tab=rules
            'rules' => $this->rules($rowRuleIds),
            'templates' => $this->templates(),
            'providerTemplates' => $this->providerTemplates(),
            'queue' => $this->queue(),
            'needsChecking' => $this->needsChecking(),
            'scheduled' => $this->scheduled(),
            'batches' => $this->batches(),
            'activity' => $this->activity(),
            'catalog' => $this->catalogue->payload(),
            'whatsapp' => $this->whatsappCard(),
            'placeholders' => $this->renderer->placeholders(),
            'thresholds' => config('crm.alerts'),
            'schedulerRunning' => $this->schedulerRunning(),
        ] + $this->alertList($request, $request->user(), 'automation-alerts'));
    }

    /**
     * The guide, on its own route.
     *
     * A separate page rather than a panel on the tabs, because it is read once
     * — properly, start to finish, by somebody who has just been handed this
     * feature — and then never again. Nothing links to it any more: it is
     * mostly about the rule builder, which is now for troubleshooting only.
     */
    public function guide()
    {
        return Inertia::render('Automation/Guide', [
            'triggers' => config('automation.triggers'),
            'conditions' => config('automation.conditions'),
            'actions' => config('automation.actions'),
            'categories' => config('automation.whatsapp.categories'),
            'thresholds' => config('crm.alerts'),
            'loop' => config('automation.loop_protection'),
            'whatsapp' => $this->whatsappCard(),
        ]);
    }

    /**
     * Save the WhatsApp API settings.
     *
     * The token is only written when the admin actually typed a new one. The
     * form ships a masked value it cannot read back, so an admin correcting the
     * origin website must not blank the token by leaving the (empty) token box
     * alone.

     * There is no host to set here: it is config (WHATSAPP_API_BASE).
     *
     * Neither switch can be switched on while the API is unconfigured, and the
     * refusal is here rather than only in the UI: they turn "a rule queues a
     * message" into "a rule messages a customer", and that must not be
     * reachable by posting to the route.
     */
    public function updateWhatsApp(Request $request)
    {
        $data = $request->validate([
            'auth_token' => ['nullable', 'string', 'max:2000', 'regex:/^\S+$/'],
            'origin_website' => ['nullable', 'string', 'max:255'],
            'api_enabled' => ['boolean'],
            'auto_send' => ['boolean'],
        ], [
            'auth_token.regex' => 'The auth token cannot contain spaces or line breaks. Copy it again without them.',
        ]);

        $integration = $this->whatsapp->integration();

        $changes = [
            'origin_website' => filled($data['origin_website'] ?? null) ? trim($data['origin_website']) : null,
        ];

        if (filled($data['auth_token'] ?? null)) {
            $changes['auth_token'] = $data['auth_token'];
        }

        $integration->mergeSettings($changes);
        $integration->save();

        // asked again AFTER the save: an admin pasting credentials and turning
        // a switch on in the same submission should get what they asked for
        $apiWanted = (bool) ($data['api_enabled'] ?? false);
        $autoWanted = (bool) ($data['auto_send'] ?? false);

        if (($apiWanted || $autoWanted) && ! $this->whatsapp->isConfigured()) {
            $integration->mergeSettings(['api_enabled' => false, 'auto_send' => false]);
            $integration->save();

            return back()->with('error', WhatsAppSender::NOT_CONFIGURED);
        }

        $integration->mergeSettings(['api_enabled' => $apiWanted, 'auto_send' => $autoWanted]);
        $integration->save();

        return back()->with('success', 'WhatsApp settings saved.');
    }

    /**
     * Test connection — which, with 11za, means sending one real message to a
     * number the admin types, with the SAVED settings. JSON, so the card can
     * show the answer where the button is, and always an answer: 11za's raw
     * response, or why nothing was sent.
     */
    public function testWhatsApp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:30'],
            'template_id' => ['required', 'integer', 'exists:message_templates,id'],
        ], [
            'mobile.required' => 'Type the mobile number the test message should go to.',
            'template_id.required' => 'Choose which tag to send as the test.',
        ]);

        return response()->json($this->whatsapp->testConnection(
            MessageTemplate::findOrFail($data['template_id']),
            $data['mobile'],
        ));
    }

    /* ---------------- the tabs ---------------- */

    /**
     * The Rules tab — every rule, for whoever is troubleshooting. It is not
     * in the tab bar; /automation?tab=rules opens it. A rule an Auto-send row
     * owns is marked, because changing it here changes that row.
     *
     * @param  list<int>  $rowRuleIds
     * @return list<array<string, mixed>>
     */
    private function rules(array $rowRuleIds): array
    {
        return AutomationRule::with('creator:id,first_name,last_name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (AutomationRule $rule) => $this->ruleRow($rule) + [
                'description' => $rule->description,
                'created_by' => $rule->creator?->display_name,
                'on_auto_send' => in_array($rule->id, $rowRuleIds, true),
                // the save-time warning, recomputed on read so a rule written
                // before the check existed still shows it
                'could_loop' => $rule->couldLoop(),
            ])
            ->all();
    }

    /**
     * What it takes to say a rule in plain words (rulePhrase.js) and show
     * whether it is on and has run.
     *
     * @return array<string, mixed>
     */
    private function ruleRow(AutomationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'trigger' => $rule->trigger,
            'trigger_config' => $rule->trigger_config ?? [],
            'conditions' => $rule->conditionList(),
            'actions' => $rule->actionList(),
            'is_active' => $rule->is_active,
            'fire_count' => $rule->fire_count,
            'last_fired_at' => $rule->last_fired_at?->toIso8601String(),
            'is_time_based' => config("automation.triggers.{$rule->trigger}.kind") === 'time',
        ];
    }

    private function templates(): array
    {
        $tags = MessageTemplate::withCount('messages')->orderBy('name')->get();
        $facts = $this->whatsapp->tagFacts($tags);

        return $tags
            ->map(fn (MessageTemplate $t) => $facts[$t->id] + [
                'id' => $t->id,
                'name' => $t->name,
                'placeholder_map' => $t->placeholder_map ?? [],
                // 11za's own wording, when its template list carried it
                'provider_body' => $t->provider_body,
                // wording written in the CRM before it stopped holding any;
                // read-only, and only used when 11za's is not known
                'old_body' => $t->body,
                'old_body_in_use' => filled($t->body) && blank($t->provider_body),
                'provider_template_name' => $t->provider_template_name,
                'provider_template_language' => $t->provider_template_language,
                'provider_template' => $t->providerTemplateLabel(),
                // null means it can go by API; anything else is the reason not
                'api_unsendable' => $t->apiUnsendableReason(),
                'is_active' => $t->is_active,
                'messages_count' => $t->messages_count,
                // 11za's wording for the example customer, or null when it has
                // not been read — never a stand-in
                'preview' => $this->renderer->exampleWording($t),
            ])
            ->all();
    }

    /**
     * 11za's template list as last read, for the Tags dropdown before it
     * is refreshed. `failed` stops the tab asking 11za again by itself.
     *
     * @return array{templates: list<array<string, mixed>>, total: int, at: ?string, failed: bool}
     */
    private function providerTemplates(): array
    {
        return $this->whatsapp->providerTemplateList();
    }

    /**
     * The review list and the message log: everything queued, plus what has
     * recently left it, newest first.
     *
     * Recently-sent messages are included on purpose. This is where an admin
     * answers "did Rahul get the site visit message?" — recipient, number,
     * template, rule, outcome in words that do not overclaim, 11za's id and
     * raw response, and the error when there was one.
     */
    private function queue(): array
    {
        return $this->messageQuery()
            // a bulk send is one row in batches(), not a few hundred here
            ->whereNull('batch_id')
            ->latest('id')
            ->limit((int) config('automation.queue_limit', 100))
            ->get()
            ->map(fn (MessageLog $m) => $this->messageRow($m))
            ->all();
    }

    /**
     * Every message whose outcome is unknown, however old. Kept out of the
     * capped log above on purpose: one that scrolled off the end would never
     * be settled, and the customer would never hear either way.
     *
     * @return list<array<string, mixed>>
     */
    private function needsChecking(): array
    {
        return $this->messageQuery()
            ->where('status', 'unknown')
            ->oldest('id')
            ->get()
            ->map(fn (MessageLog $m) => $this->messageRow($m))
            ->all();
    }

    /**
     * Every message waiting for a chosen time, soonest first. Kept out of the
     * capped log for the same reason: one scheduled a week ahead must still
     * be there to cancel.
     *
     * @return list<array<string, mixed>>
     */
    private function scheduled(): array
    {
        return $this->messageQuery()
            ->whereNull('batch_id')
            ->where('status', 'queued')
            ->where('send_at', '>', now())
            ->orderBy('send_at')
            ->get()
            ->map(fn (MessageLog $m) => $this->messageRow($m))
            ->all();
    }

    /**
     * The recent bulk sends, one row each: who started it, what it is doing,
     * and how many of its messages are in each state. Who stopped, held or
     * resumed it are their own fields — none writes over who started it.
     *
     * @return list<array<string, mixed>>
     */
    private function batches(): array
    {
        return MessageBatch::with('creator:id,first_name,last_name', 'stopper:id,first_name,last_name', 'resumer:id,first_name,last_name')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(function (MessageBatch $batch) {
                $counts = $batch->counts();

                return [
                    'id' => $batch->id,
                    'tag' => $batch->template_name,
                    'state' => $batch->state($counts),
                    'counts' => $counts,
                    'selected' => $batch->selected_count,
                    'excluded' => $batch->excluded_count,
                    'recipients' => $batch->recipient_count,
                    'selection' => $batch->selection,
                    'send_at' => $batch->send_at?->toIso8601String(),
                    'created_by' => $batch->creator?->display_name,
                    'created_at' => $batch->created_at?->toIso8601String(),
                    'held_reason' => $batch->held_reason,
                    'held_at' => $batch->held_at?->toIso8601String(),
                    'stopped_by' => $batch->stopper?->display_name,
                    'stopped_at' => $batch->stopped_at?->toIso8601String(),
                    'resumed_by' => $batch->resumer?->display_name,
                    'resumed_at' => $batch->resumed_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    private function messageQuery(): Builder
    {
        return MessageLog::with([
            'lead:id,first_name,middle_name,last_name,mobile_number,assigned_to,whatsapp_opted_out_at',
            'template:id,name,category,provider_template_name,provider_template_language,placeholder_map',
            'rule:id,name',
            'user:id,first_name,last_name',
            'checker:id,first_name,last_name',
            'canceller:id,first_name,last_name',
        ]);
    }

    /** @return array<string, mixed> */
    private function messageRow(MessageLog $m): array
    {
        return [
            'id' => $m->id,
            'status' => $m->status,
            'mode' => $m->mode,
            'outcome' => $m->outcome(),
            'body' => $m->body,
            'to_number' => $m->to_number,
            'to_name' => $m->to_name ?? $m->lead?->full_name,
            'provider_message_id' => $m->provider_message_id,
            'unconfirmed' => $m->isUnconfirmed(),
            // when it was handed to 11za: where to look in 11za's log
            'handed_at' => $m->sending_started_at?->toIso8601String(),
            'provider_response' => $m->provider_response,
            'error' => $m->error,
            'error_code' => $m->error_code,
            'sent_at' => $m->sent_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
            'send_at' => $m->send_at?->toIso8601String(),
            'scheduled' => $m->isScheduled(),
            'lead' => $m->lead ? [
                'id' => $m->lead->id,
                'name' => $m->lead->full_name,
                'opted_out' => $m->lead->hasOptedOutOfWhatsApp(),
            ] : null,
            'template' => $m->template?->name,
            'provider_template' => $m->provider_template_name
                ? "{$m->provider_template_name} ({$m->provider_template_language})"
                : null,
            'rule' => $m->rule?->name,
            'user' => $m->user?->display_name,
            // built server-side so the browser never has to know the
            // country code or the encoding rules
            'click_url' => $m->status === 'queued' ? $this->whatsapp->clickUrlFor($m) : null,
            // whether Send by API is worth offering on this row at all
            'api_ready' => $m->status === 'queued' && $m->template && $m->params !== null
                && ! $m->template->apiUnsendableReason(),
        ];
    }

    private function activity(): array
    {
        return AutomationLog::with(['rule:id,name', 'lead:id,first_name,middle_name,last_name'])
            ->latest('id')
            ->limit((int) config('automation.log_limit', 100))
            ->get()
            ->map(fn (AutomationLog $log) => [
                'id' => $log->id,
                'rule' => $log->rule?->name ?? 'A deleted rule',
                'lead' => $log->lead?->full_name,
                'action' => $log->action,
                'result' => $log->result,
                'error' => $log->error,
                'fired_at' => $log->fired_at?->toIso8601String(),
                'bad' => $log->isBad(),
            ])
            ->all();
    }

    /**
     * What the browser is allowed to know about the WhatsApp credentials.
     *
     * Never the token. `maskedSetting` gives the last four characters, which is
     * enough to tell "the token I pasted on Tuesday" from "some other token"
     * and not enough to be worth stealing.
     */
    private function whatsappCard(): array
    {
        $integration = $this->whatsapp->integration();

        return [
            'configured' => $this->whatsapp->isConfigured(),
            'api_enabled' => $this->whatsapp->apiEnabled(),
            'auto_send' => $this->whatsapp->autoSends(),
            'origin_website' => $integration->setting('origin_website'),
            'auth_token_tail' => $integration->maskedSetting('auth_token'),
            'last_test' => $integration->setting('last_test'),
            'not_configured' => WhatsAppSender::NOT_CONFIGURED,
        ];
    }

    /**
     * Has Laravel's scheduler run in the last couple of hours?
     *
     * Time-based rules and every built-in alert depend on `schedule:run` being
     * on a cron. When it is not, nothing errors: event rules go on working
     * perfectly and the time ones simply never fire, which is the worst kind of
     * broken. The top of the Automation page prints a warning when this is
     * false.
     *
     * A unix timestamp written by `automation:run` itself, and read back
     * defensively. Two things make that worth spelling out.
     *
     * It is a scalar because the cache serialises: this used to store a Carbon,
     * which the `array` driver hands back untouched and the database driver
     * hands back as __PHP_Incomplete_Class — a 500 on the whole page, on every
     * environment except the one the tests run in.
     *
     * And it is validated because a cache is shared, long-lived and outside
     * this code's control: an old value from before that change, a key somebody
     * set by hand, or anything else at all must degrade to "unknown" rather
     * than throw. This is a hint in the corner of a tab, and nothing about it
     * is worth taking a page down for.
     *
     * Null means unknown — the cache was cleared, or nothing has run yet — and
     * the page stays quiet rather than accusing a healthy server.
     */
    private function schedulerRunning(): ?bool
    {
        $last = cache()->get('automation.last_run_at');

        if (! is_int($last) && ! (is_string($last) && ctype_digit($last))) {
            return null;
        }

        return (now()->getTimestamp() - (int) $last) < 150 * 60;
    }
}
