<?php

namespace App\Http\Controllers;

use App\Exports\DataExporter;
use App\Exports\ExportException;
use App\Support\CrmTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The Export Data page: one screen for every downloadable CSV, spreadsheet and
 * PDF the CRM offers.
 *
 * GET /export-data renders the page. POST /export-data/download validates the
 * chosen data type, format and filters, asks DataExporter for a Response, and
 * hands it back as a plain file download — the page never moves.
 *
 * The door is the `export_data` permission, the one reserved toggle
 * config('crm.permissions') has carried unused. Like every other permission it
 * is a per-user override with a role default (admin true, everyone else false),
 * so an admin who needs the Downloads button does not grant it to the office.
 *
 * Visibility is NOT decided here. The whitelist of data types is
 * DataExporter::types(), and each type's query runs through the same scopes the
 * other pages rely on — Lead::visibleTo() for leads, Todo::forUser() for
 * follow-ups — so a salesperson with the toggle can only ever export what they
 * can already see.
 */
class ExportDataController extends Controller
{
    public function __construct(private DataExporter $exporter) {}

    public function index(Request $request)
    {
        $this->authorizeExport($request->user());

        return Inertia::render('Exports/Index', [
            'options' => $this->exporter->options($request->user()),
        ]);
    }

    /**
     * The "Matching records: X" the page previews: an exact count of what the
     * current filters and the user's visibility would export, shared with the
     * download so the figure never drifts from the file.
     */
    public function count(Request $request)
    {
        $user = $request->user();

        $this->authorizeExport($user);

        $validated = $this->validateDownload($request, requireFormat: false);

        return response()->json([
            'count' => $this->exporter->count(
                $user,
                $validated['data_type'],
                $this->whitelisted($validated),
            ),
        ]);
    }

    public function download(Request $request)
    {
        $user = $request->user();

        $this->authorizeExport($user);

        $validated = $this->validateDownload($request);

        $filters = $this->whitelisted($validated);

        try {
            return $this->exporter->response(
                $user,
                $validated['data_type'],
                $validated['format'],
                $filters,
                $validated['page'] ?? 1,
            );
        } catch (ExportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * `export_data` is the whole gate: the leave-the-app-wide-open-path is the
     * one thing the permission was always reserved for. The page link only
     * renders for users who have it; typing the URL is refused here.
     */
    private function authorizeExport($user): void
    {
        abort_unless($user?->can_('export_data'), 403, 'You do not have permission to export data.');
    }

    /**
     * Whitelisted everywhere: the data type and format are Rule::in() against
     * the catalog's own keys, so nothing a request names ever reaches a query.
     *
     * @param  bool  $requireFormat  the download needs a format; the count
     *                               preview has every filter but no format yet.
     */
    private function validateDownload(Request $request, bool $requireFormat = true): array
    {
        $rules = [
            'data_type' => ['required', Rule::in($this->exporter->typeKeys())],
            'format' => [$requireFormat ? 'required' : 'nullable', Rule::in($this->exporter->formatKeys())],
            // PDF alone: which page of pdfRowLimit()-sized batches this
            // download is. Ignored by CSV and Excel, which are never paged.
            'page' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'stage' => ['nullable', 'string', Rule::in(CrmTaxonomy::stageKeys())],
            'source' => ['nullable', 'string', Rule::in(CrmTaxonomy::sourceKeys())],
            'channel_partner_id' => ['nullable', 'integer', 'min:1'],
            'assigned_to' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:pending,completed,active,inactive'],
            'type' => ['nullable', 'string', Rule::in(array_merge(array_keys(config('crm.todo_types')), array_keys(config('crm.channel_partner_types'))))],
            'search' => ['nullable', 'string', 'max:100'],
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        // the custom pair is all or nothing, and must not run backwards
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;

        if (($from === null) !== ($to === null)) {
            abort(422, 'Choose both a From and a To date, or neither.');
        }

        if ($from !== null && $to !== null && $from > $to) {
            abort(422, 'From must not be after To.');
        }

        return $data;
    }

    /**
     * The only filter keys that survive into a query are the ones the data
     * type owns; everything else a request can name is dropped.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function whitelisted(array $validated): array
    {
        return array_intersect_key(
            $validated,
            array_flip($this->exporter->ownedFilters($validated['data_type'])),
        );
    }
}
