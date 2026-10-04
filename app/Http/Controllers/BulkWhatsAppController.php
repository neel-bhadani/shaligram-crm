<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesFilters;
use App\Models\Lead;
use App\Models\MessageBatch;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Services\LeadListQuery;
use App\Services\WhatsApp\BulkSender;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Bulk WhatsApp: one tag to the leads ticked on the leads list, or to every
 * lead matching its filter. Admin only, on the route group.
 *
 * Two steps, always. preview() is the confirm screen: how many are selected,
 * how many will actually receive it, who is left out and why, and the
 * message as one named lead gets it. start() makes the same plan again and
 * goes only if the numbers are the ones the admin confirmed.
 *
 * "Matching the filter" is the leads list's own filter, read from the
 * session the list keeps it in, through the same LeadListQuery — the leads it
 * acts on are the leads the list shows.
 */
class BulkWhatsAppController extends Controller
{
    use ResolvesFilters;

    public function __construct(
        private BulkSender $bulk,
        private LeadListQuery $listQuery,
    ) {}

    public function preview(Request $request): JsonResponse
    {
        [$template, $leads, $selection] = $this->selection($request);

        if ($refusal = $this->bulk->refusal($template)) {
            return response()->json(['ok' => false, 'message' => $refusal], 422);
        }

        $plan = $this->bulk->plan($leads, $template);

        return response()->json(['ok' => true, 'selection' => $selection, 'tag' => $template->name] + $this->forScreen($plan));
    }

    public function start(Request $request): JsonResponse
    {
        [$template, $leads, $selection] = $this->selection($request);

        $data = $request->validate([
            'expect_selected' => ['required', 'integer', 'min:0'],
            'expect_recipients' => ['required', 'integer', 'min:0'],
            'send_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ], [
            'send_at.date_format' => 'Choose a date and a time to send it.',
        ]);

        $sendAt = WhatsAppSender::sendAtFrom($data['send_at'] ?? null);

        if ($sendAt === false) {
            return response()->json(['ok' => false, 'message' => 'Choose a time in the future (India time).'], 422);
        }

        $outcome = $this->bulk->start(
            $request->user(), $template, $leads, $selection, $sendAt,
            (int) $data['expect_selected'], (int) $data['expect_recipients'],
        );

        if (! $outcome['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $outcome['message'],
            ] + (isset($outcome['plan']) ? $this->forScreen($outcome['plan']) : []), 422);
        }

        // shown on the Queue tab the browser goes to next
        $request->session()->flash('success', $outcome['message']);

        return response()->json([
            'ok' => true,
            'message' => $outcome['message'],
            'url' => route('automation.index', ['tab' => 'queue']),
        ]);
    }

    /** Every lead in the send, for the Queue's expanded row — the left out ones too, with why. */
    public function show(MessageBatch $batch): JsonResponse
    {
        return response()->json([
            'messages' => $batch->messages()
                ->with('lead:id,first_name,middle_name,last_name', 'canceller:id,first_name,last_name', 'user:id,first_name,last_name')
                ->orderBy('id')
                ->get()
                ->map(fn (MessageLog $m) => [
                    'id' => $m->id,
                    'name' => $m->to_name ?? $m->lead?->full_name ?? 'Deleted lead',
                    'number' => $m->to_number,
                    'status' => $m->status,
                    'outcome' => $m->outcome(),
                    'error' => $m->error,
                    'at' => ($m->sent_at ?? $m->send_at)?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    public function stop(Request $request, MessageBatch $batch)
    {
        return $this->bulk->stop($batch, $request->user())
            ? back()->with('success', 'Stopped. Nothing more from this send will go; anything already handed to 11za finishes.')
            : back()->with('error', 'That send has already stopped.');
    }

    public function resume(Request $request, MessageBatch $batch)
    {
        return $this->bulk->resume($batch, $request->user())
            ? back()->with('success', 'Resumed. The rest go from now, '.BulkSender::perMinute().' a minute.')
            : back()->with('error', 'Only a held send can be resumed.');
    }

    /**
     * The tag, the leads, and which way they were chosen.
     *
     * @return array{0: MessageTemplate, 1: Collection<int, Lead>, 2: string}
     */
    private function selection(Request $request): array
    {
        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'mode' => ['required', 'in:selected,filter'],
            'lead_ids' => ['required_if:mode,selected', 'array'],
            'lead_ids.*' => ['integer'],
        ], [
            'lead_ids.required_if' => 'Tick at least one lead.',
        ]);

        $template = MessageTemplate::find($data['template_id']);

        abort_unless($template, 422, 'That tag has been deleted.');

        $user = $request->user();

        $leads = $data['mode'] === 'selected'
            ? Lead::visibleTo($user)->whereIn('id', $data['lead_ids'])->latest()->get()
            : $this->listQuery->filtered($user, $this->resolveFilters(
                $request, 'leads', $this->listQuery->rules(), [],
                fn (array $state) => $this->listQuery->normalise($state),
            ))->latest()->get();

        return [$template, $leads, $data['mode']];
    }

    /**
     * The plan as the confirm screen reads it. Each reason's list is trimmed
     * for the screen; the batch keeps every left-out lead as a row.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function forScreen(array $plan): array
    {
        unset($plan['rows']);

        $plan['excluded'] = array_map(fn (array $group) => [
            'reason' => $group['reason'],
            'count' => count($group['leads']),
            'leads' => array_slice($group['leads'], 0, 200),
        ], $plan['excluded']);

        $plan['per_minute'] = BulkSender::perMinute();

        return $plan;
    }
}
