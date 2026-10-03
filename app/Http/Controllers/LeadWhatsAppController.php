<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The WhatsApp panel on one lead: pick a message, see it filled in with this
 * lead's real values, send it. One lead, one message, one press.
 *
 * Anybody who can open the lead can use it (LeadPolicy@view) — the person
 * handling a customer is the one who knows a message is due.
 *
 * JSON, because the lead opens in a modal that loads itself with axios.
 *
 * The 24-hour window is reported as unknown, always. Knowing it needs the
 * inbound webhook, which this CRM does not have; the panel says "window
 * unknown, template required" and never claims the lead is outside it.
 */
class LeadWhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppSender $whatsapp,
        private TemplateRenderer $renderer,
    ) {}

    public function show(Lead $lead): JsonResponse
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
                    'preview' => $this->renderer->render($t->body, $lead),
                    'values' => collect(array_values($t->placeholder_map ?? []))
                        ->map(fn (string $name, int $i) => ['position' => $i + 1, 'name' => $name, 'value' => $params[$i]])
                        ->all(),
                    'provider_template' => $t->providerTemplateLabel(),
                    'by_api' => $apiEnabled && $reason === null,
                    'api_reason' => $reason,
                ];
            })
            ->all();

        return response()->json([
            'api_enabled' => $apiEnabled,
            'number' => $this->renderer->waNumber($lead->mobile_number),
            'window' => 'Window unknown, template required',
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
            return response()->json(['ok' => false, 'message' => 'That message has been deleted or switched off.'], 422);
        }

        $user = $request->user();

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
