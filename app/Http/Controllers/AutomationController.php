<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsAlerts;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\WhatsAppTemplate;
use App\Services\Automation\RuleCatalog;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use App\Services\WhatsApp\WhatsAppTemplateSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The Automation page. Five tabs, one Inertia page, admin only.
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
 * THE SECRET. A WhatsApp access token is stored encrypted in `integrations` and
 * never leaves the server. This controller sends `configured`, `autoSend` and a
 * masked tail, and never `$integration->settings` — the cast decrypts on read,
 * so handing that array to Inertia would put a live token in the page source.
 */
class AutomationController extends Controller
{
    use ListsAlerts;

    public const TABS = ['rules', 'templates', 'queue', 'alerts', 'activity'];

    public function __construct(
        private RuleCatalog $catalogue,
        private WhatsAppSender $whatsapp,
        private TemplateRenderer $renderer,
    ) {}

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), self::TABS, true)
            ? $request->query('tab')
            : 'rules';

        return Inertia::render('Automation/Index', [
            'tab' => $tab,
            'rules' => $this->rules(),
            'templates' => $this->templates(),
            'queue' => $this->queue(),
            'activity' => $this->activity(),
            'catalog' => $this->catalogue->payload(),
            'whatsapp' => $this->whatsappCard(),
            'whatsappTemplates' => $this->whatsappTemplates(),
            'placeholders' => $this->renderer->placeholders(),
            'categories' => config('automation.whatsapp.categories'),
            'thresholds' => config('crm.alerts'),
            'schedulerRunning' => $this->schedulerRunning(),
        ] + $this->alertList($request, $request->user(), 'automation-alerts'));
    }

    /**
     * The guide, on its own route.
     *
     * A separate page rather than a panel on the tabs, because it is read once
     * — properly, start to finish, by somebody who has just been handed this
     * feature — and then never again. A collapsible box on the Rules tab would
     * be skipped by exactly the person it is written for.
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
     * phone number id must not blank the token by leaving the (empty) token
     * box alone.
     *
     * The shape checks are here because Meta's own answer to a wrong value
     * arrives weeks later, as every message failing: a Phone Number ID pasted
     * into the token box was once accepted without a word.
     *
     * Neither switch can be switched on while the API is unconfigured, and the
     * refusal is here rather than only in the UI: they turn "a rule queues a
     * message" into "a rule messages a customer", and that must not be
     * reachable by posting to the route.
     */
    public function updateWhatsApp(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:30'],
            'waba_id' => ['nullable', 'string', 'regex:/^\d+$/', 'max:30', 'different:phone_number_id'],
            'access_token' => ['nullable', 'string', 'min:16', 'max:1000', 'starts_with:EAA', 'regex:/^\S+$/'],
            'api_enabled' => ['boolean'],
            'auto_send' => ['boolean'],
        ], [
            'phone_number_id.regex' => 'The Phone Number ID is digits only — copy it from API Setup in Meta\'s WhatsApp Manager.',
            'waba_id.regex' => 'The WhatsApp Business Account ID is digits only.',
            'waba_id.different' => 'The WhatsApp Business Account ID and the Phone Number ID are two different numbers. One of them is in the wrong box.',
            'access_token.starts_with' => 'That does not look like an access token. Meta tokens start with "EAA" — if you pasted a long number, that is probably the Phone Number ID.',
            'access_token.min' => 'That is too short to be an access token. Meta tokens start with "EAA" and run to well over a hundred characters.',
            'access_token.regex' => 'The access token cannot contain spaces or line breaks. Copy it again without them.',
        ]);

        $integration = $this->whatsapp->integration();

        $changes = [
            'phone_number_id' => $data['phone_number_id'] ?? null,
            'waba_id' => $data['waba_id'] ?? null,
        ];

        if (filled($data['access_token'] ?? null)) {
            $changes['access_token'] = $data['access_token'];
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
     * Test connection. JSON, so the card can show the answer where the button
     * is, and always an answer — success names the number, failure carries
     * Meta's own code and words. It tests what is SAVED, not what is typed.
     */
    public function testWhatsApp(): JsonResponse
    {
        return response()->json($this->whatsapp->testConnection());
    }

    public function syncWhatsAppTemplates(WhatsAppTemplateSync $sync)
    {
        $result = $sync->run();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /* ---------------- the tabs ---------------- */

    private function rules(): array
    {
        return AutomationRule::with('creator:id,first_name,last_name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (AutomationRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'description' => $rule->description,
                'trigger' => $rule->trigger,
                'trigger_config' => $rule->trigger_config ?? [],
                'conditions' => $rule->conditionList(),
                'actions' => $rule->actionList(),
                'is_active' => $rule->is_active,
                'fire_count' => $rule->fire_count,
                'last_fired_at' => $rule->last_fired_at?->toIso8601String(),
                'created_by' => $rule->creator?->display_name,
                // the save-time warning, recomputed on read so a rule written
                // before the check existed still shows it
                'could_loop' => $rule->couldLoop(),
                'is_time_based' => config("automation.triggers.{$rule->trigger}.kind") === 'time',
            ])
            ->all();
    }

    private function templates(): array
    {
        return MessageTemplate::withCount('messages')
            ->with('whatsappTemplate')
            ->orderBy('name')
            ->get()
            ->map(fn (MessageTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'category' => $t->category,
                'body' => $t->body,
                'placeholder_map' => $t->placeholder_map ?? [],
                // what Meta would be sent, shown read-only so the numbering is
                // not a surprise the week somebody submits a template
                'meta_body' => $this->renderer->toMetaBody($t->body, $t->placeholder_map),
                'meta_template_name' => $t->meta_template_name,
                'approval_status' => $t->approval_status,
                'whatsapp_template_id' => $t->whatsapp_template_id,
                'whatsapp_template' => $t->whatsappTemplate?->label,
                'whatsapp_status' => $t->whatsappTemplate?->status,
                // null means it can go by API; anything else is the reason not
                'api_unsendable' => $t->apiUnsendableReason(),
                'is_active' => $t->is_active,
                'messages_count' => $t->messages_count,
                'preview' => $this->renderer->preview($t->body),
                'cost_note' => $t->costNote(),
            ])
            ->all();
    }

    /**
     * The review list and the message log: everything queued, plus what has
     * recently left it, newest first.
     *
     * Recently-sent messages are included on purpose. This is where an admin
     * answers "did Rahul get the site visit message?" — recipient, number,
     * template, rule, outcome in words that do not overclaim, Meta's id, and
     * the error when there was one.
     */
    private function queue(): array
    {
        return MessageLog::with([
            'lead:id,first_name,middle_name,last_name,mobile_number,assigned_to',
            'template:id,name,category,whatsapp_template_id,placeholder_map',
            'template.whatsappTemplate',
            'whatsappTemplate:id,name,language',
            'rule:id,name',
            'user:id,first_name,last_name',
        ])
            ->latest('id')
            ->limit((int) config('automation.queue_limit', 100))
            ->get()
            ->map(fn (MessageLog $m) => [
                'id' => $m->id,
                'status' => $m->status,
                'mode' => $m->mode,
                'outcome' => $m->outcome(),
                'body' => $m->body,
                'to_number' => $m->to_number,
                'to_name' => $m->to_name ?? $m->lead?->full_name,
                'wamid' => $m->wamid,
                'error' => $m->error,
                'error_code' => $m->error_code,
                'sent_at' => $m->sent_at?->toIso8601String(),
                'created_at' => $m->created_at?->toIso8601String(),
                'lead' => $m->lead ? [
                    'id' => $m->lead->id,
                    'name' => $m->lead->full_name,
                ] : null,
                'template' => $m->template?->name,
                'whatsapp_template' => $m->whatsappTemplate?->label,
                'rule' => $m->rule?->name,
                'user' => $m->user?->display_name,
                // built server-side so the browser never has to know the
                // country code or the encoding rules
                'click_url' => $m->status === 'queued' ? $this->whatsapp->clickUrlFor($m) : null,
                // whether Send by API is worth offering on this row at all
                'api_ready' => $m->status === 'queued' && $m->template && $m->params !== null
                    && ! $m->template->apiUnsendableReason(),
            ])
            ->all();
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
            'phone_number_id' => $integration->setting('phone_number_id'),
            'waba_id' => $integration->setting('waba_id'),
            'access_token_tail' => $integration->maskedSetting('access_token'),
            'last_test' => $integration->setting('last_test'),
            'not_configured' => WhatsAppSender::NOT_CONFIGURED,
        ];
    }

    /** The templates Meta has for this account, as of the last sync. */
    private function whatsappTemplates(): array
    {
        return WhatsAppTemplate::orderBy('name')
            ->orderBy('language')
            ->get()
            ->map(fn (WhatsAppTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'label' => $t->label,
                'status' => $t->status,
                'category' => $t->category,
                'body' => $t->body,
                'param_count' => $t->bodyParamCount(),
                'unsupported' => $t->unsupportedReason(),
                'synced_at' => $t->synced_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Has Laravel's scheduler run in the last couple of hours?
     *
     * Time-based rules and every built-in alert depend on `schedule:run` being
     * on a cron. When it is not, nothing errors: event rules go on working
     * perfectly and the time ones simply never fire, which is the worst kind of
     * broken. The Rules tab prints a warning when this is false.
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
     * the Rules tab stays quiet rather than accusing a healthy server.
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
