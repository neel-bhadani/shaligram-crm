<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeadWhatsAppSendRequest;
use App\Models\Lead;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateRenderer;
use App\Services\WhatsApp\WhatsAppSender;

/**
 * "Send WhatsApp" on the lead view, for anybody who can open the lead.
 *
 * Both endpoints answer JSON: the lead view is a modal loaded over axios, and
 * the send result has to appear in it immediately — sent, or the actual reason
 * it was not.
 */
class LeadWhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppSender $whatsapp,
        private TemplateRenderer $renderer,
    ) {}

    /**
     * What this user can send to this lead right now.
     *
     * The templates come back already filled in with the lead's real values,
     * so the preview is exactly what the customer would receive. A template
     * that cannot be filled for this lead is listed with the reason rather
     * than hidden, so "why is Welcome not selectable" answers itself.
     */
    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);

        $closesAt = $lead->whatsAppWindowClosesAt();

        return response()->json([
            'number' => $this->renderer->waNumber($lead->mobile_number),
            'api_ready' => $this->whatsapp->apiReady(),
            'window' => [
                'open' => $closesAt !== null,
                'closes_at' => $closesAt?->toIso8601String(),
            ],
            'template_only' => WhatsAppSender::TEMPLATE_ONLY,
            'templates' => WhatsAppTemplate::usable()
                ->orderBy('name')
                ->get()
                ->filter(fn (WhatsAppTemplate $t) => $t->unmappedVariables() === [])
                ->map(function (WhatsAppTemplate $t) use ($lead) {
                    $filled = $this->renderer->fillMetaTemplate($t, $lead);

                    return [
                        'id' => $t->id,
                        'label' => $t->label,
                        'category' => $t->category,
                        'preview' => $filled['body'],
                        'blank' => collect($filled['parameters'])
                            ->whereIn('variable', $filled['empty'])
                            ->pluck('placeholder')
                            ->values(),
                    ];
                })
                ->values(),
        ]);
    }

    public function send(LeadWhatsAppSendRequest $request, Lead $lead)
    {
        $outcome = $request->filled('whatsapp_template_id')
            ? $this->whatsapp->sendTemplateNow(
                $lead,
                WhatsAppTemplate::findOrFail($request->integer('whatsapp_template_id')),
                $request->user(),
            )
            : $this->whatsapp->sendTextNow($lead, trim((string) $request->input('text')), $request->user());

        return response()->json($outcome, $outcome['ok'] || $outcome['status'] === 'failed' ? 200 : 422);
    }
}
