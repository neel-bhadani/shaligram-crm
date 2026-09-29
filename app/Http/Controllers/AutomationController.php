<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsAlerts;
use App\Models\AutomationLog;
use App\Models\AutomationRule;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Services\Automation\RuleCatalog;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
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
     * Auto-send cannot be switched on while the API is unconfigured, and the
     * refusal is here rather than only in the UI: it is the one setting that
     * turns "a rule queues a message" into "a rule messages a customer", and it
     * must not be reachable by posting to the route.
     */
    public function updateWhatsApp(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => ['nullable', 'string', 'max:80'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'auto_send' => ['boolean'],
        ]);

        $integration = $this->whatsapp->integration();

        $changes = ['phone_number_id' => $data['phone_number_id'] ?? null];

        if (filled($data['access_token'] ?? null)) {
            $changes['access_token'] = $data['access_token'];
        }

        $integration->mergeSettings($changes);
        $integration->save();

        // asked again AFTER the save: an admin pasting credentials and turning
        // auto-send on in the same submission should get what they asked for
        $wanted = (bool) ($data['auto_send'] ?? false);

        if ($wanted && ! $this->whatsapp->isConfigured()) {
            $integration->mergeSettings(['auto_send' => false]);
            $integration->save();

            return back()->with('error', WhatsAppSender::NOT_CONFIGURED);
        }

        $integration->mergeSettings(['auto_send' => $wanted]);
        $integration->save();

        return back()->with('success', 'WhatsApp settings saved.');
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
                'is_active' => $t->is_active,
                'messages_count' => $t->messages_count,
                'preview' => $this->renderer->preview($t->body),
                'cost_note' => $t->costNote(),
            ])
            ->all();
    }

    /**
     * The review list: everything queued, plus what has recently left it.
     *
     * Recently-sent messages are included on purpose. The queue is the only
     * place a message log is visible, and "did that welcome message actually go
     * out" is the question somebody asks five minutes after pressing the
     * button.
     */
    private function queue(): array
    {
        return MessageLog::with([
            'lead:id,first_name,middle_name,last_name,mobile_number,assigned_to',
            'template:id,name,category',
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
                'body' => $m->body,
                'to_number' => $m->to_number,
                'error' => $m->error,
                'sent_at' => $m->sent_at?->toIso8601String(),
                'created_at' => $m->created_at?->toIso8601String(),
                'lead' => $m->lead ? [
                    'id' => $m->lead->id,
                    'name' => $m->lead->full_name,
                ] : null,
                'template' => $m->template?->name,
                'rule' => $m->rule?->name,
                'user' => $m->user?->display_name,
                // built server-side so the browser never has to know the
                // country code or the encoding rules
                'click_url' => $m->status === 'queued' ? $this->whatsapp->clickUrlFor($m) : null,
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
            'auto_send' => $this->whatsapp->autoSends(),
            'phone_number_id' => $integration->setting('phone_number_id'),
            'access_token_tail' => $integration->maskedSetting('access_token'),
            'not_configured' => WhatsAppSender::NOT_CONFIGURED,
        ];
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
