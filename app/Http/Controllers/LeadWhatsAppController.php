<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\LeadActivityRecorder;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The WhatsApp panel on one lead: pick a message, see it filled in with this
 * lead's real values, send it. One lead, one message, one press.
 *
 * Anybody who can open the lead can use it (LeadPolicy@view) — the person
 * handling a customer is the one who knows a message is due.
 *
 * JSON, because the lead opens in a modal that loads itself with axios.
 *
 * The 24-hour window is not reported. Every tag is an 11za template, which
 * can be sent inside or outside it, so it never changes what the user can do.
 */
class LeadWhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppSender $whatsapp,
        private TemplateRenderer $renderer,
        private LeadActivityRecorder $activities,
    ) {}

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $lead->loadMissing('project', 'owner');
        $apiEnabled = $this->whatsapp->apiEnabled();

        $templates = MessageTemplate::active()
            ->orderBy('name')
            ->get()
            ->map(function (MessageTemplate $t) use ($lead, $apiEnabled) {
                $reason = $apiEnabled ? $t->apiUnsendableReason() : null;
                $params = $this->whatsapp->paramsFor($t, $lead);

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'category' => $t->category,
                    // the same text click-to-send would open with: 11za's wording,
                    // the old wording, or the generic line
                    'preview' => $this->renderer->build($t, $lead)['body'],
                    'values' => collect(array_values($t->placeholder_map ?? []))
                        ->map(fn (string $name, int $i) => ['position' => $i + 1, 'name' => $name, 'value' => $params[$i]])
                        ->all(),
                    'provider_template' => $t->providerTemplateLabel(),
                    'by_api' => $apiEnabled && $reason === null,
                    'api_reason' => $reason,
                ];
            })
            ->all();

        $user = $request->user();

        return response()->json([
            'api_enabled' => $apiEnabled,
            'number' => $this->renderer->waNumber($lead->mobile_number),
            'opt_out' => $this->optOutState($lead, $user),
            'templates' => $templates,
            'history' => $this->history($lead),
        ]);
    }

    /**
     * Send one message to this lead.
     *
     * By API when the API is on and the message has an 11za template name — queued for the worker, never sent inline. Otherwise
     * click-to-send: the row is written and the wa.me link handed back for
     * the browser to open.
     */
    public function send(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $data = $request->validate([
            'template_id' => ['required', 'integer'],
        ]);

        $template = MessageTemplate::active()->find($data['template_id']);

        if (! $template) {
            return response()->json(['ok' => false, 'message' => 'That tag has been deleted or switched off.'], 422);
        }

        $user = $request->user();

        /*
         | An opted-out customer can still be sent one message by hand — the
         | person sending may have a reason — but never without being told
         | first. The browser asks; this is what holds when it did not.
         */
        if ($lead->hasOptedOutOfWhatsApp() && ! $request->boolean('confirm_opted_out')) {
            return response()->json([
                'ok' => false,
                'needs_confirmation' => true,
                'message' => 'This customer asked not to be messaged on WhatsApp. Confirm you still want to send this.',
            ], 422);
        }

        if ($this->whatsapp->apiEnabled() && ! $template->apiUnsendableReason()) {
            $outcome = $this->whatsapp->queueTemplate($lead, $template, user: $user);

            if ($outcome['result'] === 'skipped') {
                return response()->json(['ok' => false, 'message' => $outcome['reason'], 'history' => $this->history($lead)], 422);
            }

            return response()->json([
                'ok' => true,
                'mode' => 'api',
                'message' => 'Sending by API. The outcome appears below within a minute.',
                'history' => $this->history($lead),
            ]);
        }

        $message = $this->whatsapp->queue($lead, $template, user: $user);

        if (! $message) {
            return response()->json(['ok' => false, 'message' => 'This lead has no usable mobile number, so there is nothing to send to.'], 422);
        }

        $url = $this->whatsapp->clickUrlFor($message);
        $this->whatsapp->markOpened($message, $user);

        return response()->json([
            'ok' => true,
            'mode' => 'click',
            'url' => $url,
            'message' => 'Opened in WhatsApp. Press send there — the CRM cannot tell whether you did.',
            'history' => $this->history($lead),
        ]);
    }

    /**
     * Switch the WhatsApp opt-out on or off.
     *
     * Deliberately lopsided. ON: anybody who can edit the lead — respecting a
     * customer who said stop must never wait for an admin. OFF: admins only,
     * and recorded on the lead's history with who and when — a telecaller
     * chasing a target must not be able to undo a customer's opt-out.
     */
    public function optOut(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate(['opted_out' => ['required', 'boolean']]);
        $user = $request->user();
        $optingOut = (bool) $data['opted_out'];

        $this->authorize('update', $lead);
        abort_unless($optingOut || $user->isAdmin(), 403, 'Only an admin can allow WhatsApp messages to a customer who opted out.');

        if ($optingOut !== $lead->hasOptedOutOfWhatsApp()) {
            DB::transaction(function () use ($lead, $user, $optingOut) {
                $lead->update($optingOut
                    ? ['whatsapp_opted_out_at' => now(), 'whatsapp_opt_out_source' => 'manual', 'whatsapp_opted_out_by' => $user->id]
                    : ['whatsapp_opted_out_at' => null, 'whatsapp_opt_out_source' => null, 'whatsapp_opted_out_by' => null]);

                $this->activities->whatsAppOptOut($lead, $user->id, $optingOut);
            });
        }

        return response()->json([
            'ok' => true,
            'message' => $optingOut
                ? 'Opted out. Bulk and automatic WhatsApp messages will skip this customer.'
                : 'WhatsApp messages to this customer are allowed again.',
            'opt_out' => $this->optOutState($lead->fresh(), $user),
        ]);
    }

    /** @return array{active: bool, at: ?string, by: ?string, source: ?string, can_switch_on: bool, can_switch_off: bool} */
    private function optOutState(Lead $lead, User $user): array
    {
        $canEdit = $user->can('update', $lead);

        return [
            'active' => $lead->hasOptedOutOfWhatsApp(),
            'at' => $lead->whatsapp_opted_out_at?->toIso8601String(),
            'by' => $lead->whatsapp_opted_out_by ? User::find($lead->whatsapp_opted_out_by)?->display_name : null,
            'source' => $lead->whatsapp_opt_out_source,
            'can_switch_on' => $canEdit,
            'can_switch_off' => $canEdit && $user->isAdmin(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function history(Lead $lead): array
    {
        return MessageLog::where('lead_id', $lead->id)
            ->with(['template:id,name', 'rule:id,name', 'user:id,first_name,last_name'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (MessageLog $m) => [
                'id' => $m->id,
                'template' => $m->template?->name,
                'rule' => $m->rule?->name,
                'by' => $m->rule ? null : $m->user?->display_name,
                'to_number' => $m->to_number,
                'status' => $m->status,
                'unconfirmed' => $m->isUnconfirmed(),
                // shown beside an unconfirmed send so whoever is looking can
                // judge it before resending; already token-free
                'provider_response' => $m->isUnconfirmed() ? $m->provider_response : null,
                'outcome' => $m->outcome(),
                'error' => $m->error,
                'at' => ($m->sent_at ?? $m->created_at)?->toIso8601String(),
            ])
            ->all();
    }
}
