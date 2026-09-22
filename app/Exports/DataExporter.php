<?php

namespace App\Exports;

use App\Models\ChannelPartner;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Todo;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Dompdf\Dompdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place an export is defined.
 *
 * Everything about an export lives here: which data types exist, which formats
 * they may be written in, which filters each type owns, the exact columns each
 * type carries and how a row's value is read. The controller only validates the
 * request and asks this class for a Response, so there is a whitelist in the
 * code and there is nothing a request can name that is not on it — no table
 * name, model name or column name ever reaches a query from the request.
 *
 * Visibility is inside buildQuery(): leads go through Lead::visibleTo(), the
 * same scope every lead query in the application must call, and follow-ups
 * through Todo::forUser(), the boundary the To-do page and calendar use. A
 * salesperson's spreadsheet is their spreadsheet, whatever the request says.
 *
 * Mobile and phone numbers are written as text in every format — string cells
 * in Excel, a leading apostrophe in CSV — so a number like 9822001122 never
 * reaches a client as 9.822E+09.
 */
class DataExporter
{
    /** The formats the page offers, key => label. */
    public const FORMATS = [
        'pdf' => 'PDF',
        'excel' => 'Excel (.xlsx)',
        'csv' => 'CSV',
    ];

    /** The extension each format is written with. */
    private const EXTENSIONS = [
        'pdf' => 'pdf',
        'excel' => 'xlsx',
        'csv' => 'csv',
    ];

    /**
     * every row per chunk — the eager loads run once per chunk, not once per row
     */
    private const CHUNK = 500;

    /**
     * The most rows a single PDF page may be asked to render — dompdf's
     * limit, not a policy choice, so it lives in config rather than here.
     */
    private function pdfRowLimit(): int
    {
        return (int) config('crm.exports.pdf_row_limit', 500);
    }

    /* ====================================================================
     | Catalog — what the page and the request may name
     ==================================================================== */

    /** The exportable data types, key => label. */
    public function types(): array
    {
        return [
            'leads' => 'Leads',
            'followups' => 'Follow-ups',
            'channel_partners' => 'Channel Partners',
        ];
    }

    /** @return list<string> */
    public function typeKeys(): array
    {
        return array_keys($this->types());
    }

    /** @return list<string> */
    public function formatKeys(): array
    {
        return array_keys(self::FORMATS);
    }

    /**
     * The filter keys a data type owns — the only ones its export will honour.
     *
     * A stray `stage` on a channel-partners request is dropped rather than
     * applied; a request invents nothing.
     *
     * @return list<string>
     */
    public function ownedFilters(string $type): array
    {
        return match ($type) {
            'leads' => ['from', 'to', 'project_id', 'stage', 'source', 'channel_partner_id', 'assigned_to'],
            'followups' => ['from', 'to', 'status', 'type', 'project_id', 'stage', 'assigned_to'],
            'channel_partners' => ['search', 'type', 'status'],
            default => throw new \InvalidArgumentException("Unknown export type [$type]."),
        };
    }

    /**
     * Everything the Export Data page renders. The filter option lists are the
     * same ones every other screen offers, so a filter here and a filter on the
     * Leads page mean the same thing.
     */
    public function options(User $user): array
    {
        $wideLeads = $user->can_('see_all_leads');
        $checkUser = fn () => User::whereIn('role', ['telecaller', 'salesperson'])
            ->approved()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return [
            'types' => $this->types(),
            'formats' => self::FORMATS,
            'projects' => $this->visibleProjects($user),
            'stages' => CrmTaxonomy::allStages(),
            'sources' => CrmTaxonomy::allSources(),
            'todoStatuses' => ['pending' => 'Pending', 'completed' => 'Completed'],
            'todoTypes' => config('crm.todo_types'),
            'channelPartnerTypes' => config('crm.channel_partner_types'),
            'partnerStatus' => ['active' => 'Active', 'inactive' => 'Inactive'],
            'channelPartners' => ChannelPartner::active()
                ->with('parent:id,name')
                ->orderByRaw("CASE type WHEN 'firm' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get(['id', 'name', 'type', 'parent_id'])
                ->map(fn (ChannelPartner $p) => ['id' => $p->id, 'label' => $p->display_label])
                ->values()
                ->all(),
            /*
             | Assigned-to filters only mean anything to somebody who can see
             | past their own rows, exactly as on the Leads page and the To-do
             | page. `users` is empty for everyone else and the page hides the
             | control; the visibility scope stays the real boundary regardless.
             */
            'users' => $wideLeads ? $checkUser() : [],
            'seeAllLeads' => $wideLeads,
            'isAdmin' => $user->isAdmin(),
            // today in IST. The date inputs use this as their max rather than
            // the browser clock, which may be in another timezone entirely.
            'today' => today()->toDateString(),
            // PDF's own page size, so the page can work out how many
            // downloads a PDF export will take before anyone clicks Export
            'pdfRowLimit' => $this->pdfRowLimit(),
        ];
    }

    /** The projects a user may filter by — the same list the lead form offers. */
    private function visibleProjects(User $user): array
    {
        $query = $user->isSalesperson() && ! $user->can_('see_all_leads')
            ? $user->projects()
            : Project::query();

        return $query->active()
            ->orderBy('projects.name')
            ->get(['projects.id', 'projects.name'])
            ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();
    }

    /* ====================================================================
     | Columns
     ==================================================================== */

    /**
     * The columns a type is exported with, in order.
     *
     * `text` marks a column whose value must survive as text in Excel and CSV —
     * a phone number written as a number arrives as 9.822E+09. Traffic goes:
     * column key -> model value, so these keys and mapRow() agree by construction.
     *
     * @return list<array{key: string, label: string, text?: true}>
     */
    public function columns(string $type): array
    {
        return match ($type) {
            'leads' => [
                ['key' => 'id',                'label' => 'Lead ID'],
                ['key' => 'first_name',        'label' => 'First Name'],
                ['key' => 'last_name',         'label' => 'Last Name'],
                ['key' => 'mobile_number',     'label' => 'Mobile Number', 'text' => true],
                ['key' => 'email',             'label' => 'Email'],
                ['key' => 'project',           'label' => 'Project'],
                ['key' => 'stage',             'label' => 'Stage'],
                ['key' => 'assigned_to',       'label' => 'Assigned To'],
                ['key' => 'assigned_role',     'label' => 'Assigned Role'],
                ['key' => 'source',            'label' => 'Source'],
                ['key' => 'channel_partner',   'label' => 'Channel Partner'],
                ['key' => 'created_at',        'label' => 'Created At'],
                ['key' => 'updated_at',        'label' => 'Updated At'],
            ],
            'followups' => [
                ['key' => 'id',                'label' => 'Follow-up ID'],
                ['key' => 'lead_name',         'label' => 'Lead Name'],
                ['key' => 'mobile_number',     'label' => 'Mobile Number', 'text' => true],
                ['key' => 'project',           'label' => 'Project'],
                ['key' => 'stage',             'label' => 'Stage'],
                ['key' => 'type',              'label' => 'Follow-up Type'],
                ['key' => 'scheduled_at',      'label' => 'Scheduled At'],
                ['key' => 'status',            'label' => 'Status'],
                ['key' => 'assigned_to',       'label' => 'Assigned To'],
                ['key' => 'completed_at',      'label' => 'Completed At'],
                ['key' => 'remarks',           'label' => 'Remarks'],
                ['key' => 'outcome_stage',     'label' => 'Outcome Stage'],
            ],
            'channel_partners' => [
                ['key' => 'id',                'label' => 'Channel Partner ID'],
                ['key' => 'name',              'label' => 'Name'],
                ['key' => 'type',              'label' => 'Type'],
                ['key' => 'contact_person',    'label' => 'Contact Person'],
                ['key' => 'phone',             'label' => 'Phone', 'text' => true],
                ['key' => 'alt_phone',         'label' => 'Alt Phone', 'text' => true],
                ['key' => 'email',             'label' => 'Email'],
                ['key' => 'firm',              'label' => 'Firm / Company'],
                ['key' => 'address',           'label' => 'Address'],
                ['key' => 'status',            'label' => 'Status'],
                ['key' => 'created_at',        'label' => 'Created At'],
            ],
            default => throw new \InvalidArgumentException("Unknown export type [$type]."),
        };
    }

    /** @return list<string> */
    public function columnKeys(string $type): array
    {
        return array_column($this->columns($type), 'key');
    }

    /**
     * A row as the flat list of values the writers need, in column order.
     */
    public function mapRow(string $type, $model): array
    {
        $values = [];

        foreach ($this->columns($type) as $column) {
            $values[] = $this->cellValue($type, $column['key'], $model);
        }

        return $values;
    }

    /* ====================================================================
     | Queries — visibility first, then filters
     ==================================================================== */

    /**
     * The rows the user is allowed to see for this type, narrowable by the
     * filters the request was validated against.
     *
     * @param  array<string, mixed>  $f  validated filter values
     */
    public function query(User $user, string $type, array $f): Builder
    {
        return match ($type) {
            'leads' => $this->leadsQuery($user, $f),
            'followups' => $this->followUpsQuery($user, $f),
            'channel_partners' => $this->channelPartnerQuery($f),
        };
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function leadsQuery(User $user, array $f): Builder
    {
        $query = Lead::visibleTo($user)
            ->select([
                'id', 'first_name', 'middle_name', 'last_name', 'mobile_number',
                'email', 'project_id', 'stage', 'source', 'assigned_to', 'assigned_role',
                'broker_name', 'channel_partner_id', 'created_at', 'updated_at',
            ])
            ->with([
                'project:id,name',
                'owner:id,first_name,last_name',
                'channelPartner:id,name,type,parent_id',
                'channelPartner.parent:id,name',
            ]);

        $window = $this->window($f);

        return $query
            ->when($window, fn ($q) => $q->whereBetween('created_at', $window))
            ->when($f['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($f['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($f['channel_partner_id'] ?? null, fn ($q, $v) => $q->where('channel_partner_id', $v))
            ->when($f['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v));
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function followUpsQuery(User $user, array $f): Builder
    {
        $query = Todo::forUser($user)
            // a deleted lead takes its follow-ups off the export with it
            ->hasLead()
            ->select([
                'todos'.'.id', 'lead_id', 'assigned_to', 'scheduled_at', 'type',
                'status', 'remarks', 'outcome_stage', 'completed_at',
            ])
            ->with([
                'lead:id,first_name,middle_name,last_name,mobile_number,stage,project_id',
                'lead.project:id,name',
                'owner:id,first_name,last_name',
            ]);

        $window = $this->window($f);

        return $query
            ->when($window, fn ($q) => $q->whereBetween('todos.scheduled_at', $window))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('todos.status', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('todos.type', $v))
            ->when($f['project_id'] ?? null, fn ($q, $v) => $q->whereHas('lead', fn ($l) => $l->where('project_id', $v)))
            ->when($f['stage'] ?? null, fn ($q, $v) => $q->whereHas('lead', fn ($l) => $l->where('stage', $v)))
            ->when($f['assigned_to'] ?? null, fn ($q, $v) => $q->where('todos.assigned_to', $v));
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function channelPartnerQuery(array $f): Builder
    {
        $query = ChannelPartner::query()
            ->select([
                'id', 'name', 'type', 'contact_person', 'phone', 'alt_phone',
                'email', 'address', 'parent_id', 'is_active', 'created_at',
            ])
            ->with('parent:id,name');

        return $query
            ->when($f['search'] ?? null, function ($q, $s) {
                $q->where(function ($w) use ($s) {
                    $w->where('name', 'like', "%$s%")
                        ->orWhere('contact_person', 'like', "%$s%")
                        ->orWhere('phone', 'like', "%$s%")
                        ->orWhere('alt_phone', 'like', "%$s%")
                        ->orWhere('email', 'like', "%$s%")
                        ->orWhereHas('parent', fn ($p) => $p->where('name', 'like', "%$s%"));
                });
            })
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(
                isset($f['status']),
                fn ($q) => $q->where('is_active', $f['status'] === 'active')
            );
    }

    /* ====================================================================
     | Responses
     ==================================================================== */

    /**
     * The exact number of rows the current filters and the user's visibility
     * would export — the "Matching records: X" the page previews before any
     * download, never an approximation.
     *
     * @param  array<string, mixed>  $f
     */
    public function count(User $user, string $type, array $f): int
    {
        return $this->query($user, $type, $f)->count();
    }

    /**
     * A downloadable response for the given type and format.
     *
     * `page` is PDF's alone — dompdf cannot be trusted with an unbounded
     * document, so a PDF export past the row limit is not refused, it is
     * paged: this call returns one page of at most pdfRowLimit() rows, and
     * the page after that is just this same call again with `page` one
     * higher. CSV and Excel ignore it; they still return the whole result in
     * one file.
     *
     * @param  array<string, mixed>  $f
     *
     * @throws ExportException when there is nothing to export, or the
     *                         requested PDF page does not exist — the
     *                         controller turns either into a clear message
     *                         rather than a file.
     */
    public function response(User $user, string $type, string $format, array $f, int $page = 1): Response
    {
        $columns = $this->columns($type);
        $query = $this->query($user, $type, $f);

        /*
         | One count before anything is written: an empty export is rejected
         | outright (a header-only sheet reads as a bug).
         */
        $count = $query->count();

        if ($count === 0) {
            throw new ExportException('No records match your filters. Adjust the filters and try again.');
        }

        $filename = $this->filename($type, $format, $page, $count);

        return match ($format) {
            'pdf' => $this->pdfResponse($type, $columns, $query, $f, $count, $filename, $page),
            'excel' => $this->excelResponse($type, $columns, $query, $filename),
            'csv' => $this->csvResponse($type, $columns, $query, $filename),
        };
    }

    /**
     * A summary of the filters for the PDF's header, in the words the page
     * itself uses.
     *
     * @param  array<string, mixed>  $f
     * @return list<array{label: string, value: string}>
     */
    public function describeFilters(string $type, array $f): array
    {
        $out = [];

        if ($window = $this->window($f)) {
            $verb = match ($type) {
                'leads' => 'Created',
                'followups' => 'Scheduled',
                'channel_partners' => 'Added',
            };

            $out[] = [
                'label' => "$verb between",
                'value' => $window[0]->format('Y-m-d').'  to  '.$window[1]->format('Y-m-d'),
            ];
        }

        if (! empty($f['project_id'])) {
            $out[] = ['label' => 'Project', 'value' => (string) (Project::find($f['project_id'])?->name ?? $f['project_id'])];
        }

        if (! empty($f['stage'])) {
            $out[] = ['label' => 'Stage', 'value' => CrmTaxonomy::stageLabel($f['stage'])];
        }

        if (! empty($f['source'])) {
            $out[] = ['label' => 'Source', 'value' => CrmTaxonomy::sourceLabel($f['source'])];
        }

        if (! empty($f['channel_partner_id'])) {
            $partner = ChannelPartner::withTrashed()->with('parent:id,name')->find($f['channel_partner_id']);
            $out[] = ['label' => 'Channel partner', 'value' => $partner ? $partner->display_label : (string) $f['channel_partner_id']];
        }

        if (! empty($f['assigned_to'])) {
            $user = User::withTrashed()->find($f['assigned_to']);
            $out[] = ['label' => 'Assigned to', 'value' => $user ? $user->display_name : (string) $f['assigned_to']];
        }

        if (! empty($f['type'])) {
            $label = $type === 'followups'
                ? (string) (config('crm.todo_types')[$f['type']] ?? $f['type'])
                : (string) (config('crm.channel_partner_types')[$f['type']] ?? $f['type']);

            $out[] = ['label' => 'Type', 'value' => $label];
        }

        if (array_key_exists('status', $f) && $f['status'] !== '') {
            $label = $type === 'channel_partners'
                ? ($f['status'] === 'active' ? 'Active' : 'Inactive')
                : ($f['status'] === 'completed' ? 'Completed' : 'Pending');

            $out[] = ['label' => 'Status', 'value' => $label];
        }

        if (! empty($f['search'])) {
            $out[] = ['label' => 'Search', 'value' => (string) $f['search']];
        }

        return $out;
    }

    /* ====================================================================
     | Writers
     ==================================================================== */

    /**
     * PDF via dompdf: a structured HTML table, landscape when the export is
     * wide, with the thead repeated at the top of each page.
     *
     * dompdf cannot be trusted with an unbounded document — it slows to a
     * crawl by a few thousand rows and OOM-kills the process by ten thousand
     * — so a PDF is never asked to render more than pdfRowLimit() rows at
     * once. Past that, the export is pages: this call renders exactly one
     * page, `$page` names which one, and the page's own row range is printed
     * in the PDF's header and baked into its filename so a batch is
     * identifiable on its own. Because a page is always at most
     * pdfRowLimit() rows, it is fetched directly rather than chunked; `id` is
     * the tiebreaker that makes the slice repeatable across requests — what
     * keeps page 2 from ever repeating or skipping a row page 1 already
     * covered. The temp file is removed by the time the response is sent.
     *
     * @param  array<int, array{key: string, label: string, text?: true}>  $columns
     * @param  array<string, mixed>  $f
     */
    private function pdfResponse(string $type, array $columns, Builder $query, array $f, int $count, string $filename, int $page): Response
    {
        $limit = $this->pdfRowLimit();
        $totalPages = (int) ceil($count / $limit);

        if ($page < 1 || $page > $totalPages) {
            throw new ExportException(sprintf(
                'Page %d does not exist. This export has %d page%s of %s rows each.',
                $page,
                $totalPages,
                $totalPages === 1 ? '' : 's',
                number_format($limit),
            ));
        }

        // a large PDF page still takes a while to render — give this one
        // request room to finish without touching anyone else's limits
        @set_time_limit(600);
        $this->bumpMemory();

        // dompdf will not load a file without a known extension, so the temp
        // file carries one; the 48-bit random suffix keeps concurrent exports
        // from colliding in the shared temp directory
        $tmp = sys_get_temp_dir().'/shaligram-pdf-'.bin2hex(random_bytes(6)).'.html';

        $generated = now()->format('Y-m-d H:i:s');
        $offset = ($page - 1) * $limit;
        $rangeStart = $offset + 1;
        $rangeEnd = min($offset + $limit, $count);
        $out = null;

        try {
            $out = fopen($tmp, 'w');

            if ($out === false) {
                throw new ExportException('Could not prepare the PDF export. Please try again.');
            }

            fwrite($out, view('exports.pdf_head', [
                'type' => $this->types()[$type],
                'columns' => $columns,
                'filters' => $this->describeFilters($type, $f),
                'generated' => $generated,
                'count' => $count,
                'page' => $page,
                'totalPages' => $totalPages,
                'rangeStart' => $rangeStart,
                'rangeEnd' => $rangeEnd,
                'landscape' => count($columns) >= 8,
            ])->render());

            $rows = (clone $query)->orderBy('id')->skip($offset)->take($limit)->get();

            foreach ($rows as $model) {
                fwrite($out, $this->pdfRowHtml($this->mapRow($type, $model)));
            }

            fwrite($out, view('exports.pdf_foot', [
                'count' => $count,
                'generated' => $generated,
            ])->render());
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
        }

        try {
            $dompdf = new Dompdf([
                'isRemoteEnabled' => false,
                'tempDir' => sys_get_temp_dir(),
                'chroot' => sys_get_temp_dir(),
            ]);
            $dompdf->loadHtmlFile($tmp);
            $dompdf->setPaper('A4', count($columns) >= 8 ? 'landscape' : 'portrait');
            $dompdf->render();

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * One data row as the <tr> the PDF's table is built from. Values are
     * escaped for HTML so an ampersand or "<" in a real record can never break
     * the document the way it can break a page.
     *
     * @param  array<int, string>  $values
     */
    private function pdfRowHtml(array $values): string
    {
        $cells = '';

        foreach ($values as $value) {
            $cells .= '<td>'.htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8').'</td>';
        }

        return '<tr>'.$cells.'</tr>';
    }

    /**
     * A big multi-page PDF can exceed the process's usual memory budget long
     * before it is done, and that limit lives in the process, not the PDF.
     * Raise it only for this export and only when the current ceiling is
     * actually lower — php.ini is never touched.
     */
    private function bumpMemory(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit === false || $limit === '-1') {
            return;
        }

        $unit = strtolower(substr($limit, -1));
        $bytes = (int) $limit;

        $bytes *= match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        if ($bytes < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    /**
     * Excel via PhpSpreadsheet: a header row, then each value written as an
     * explicit string cell. The default value binder would read "9822001122"
     * as a number and the phone would arrive as 9.822E+09, so every cell goes
     * through setValueExplicit() and the `text` columns get the Excel "degrees"
     * number format on top.
     *
     * The dataset is pulled in chunks so the spreadsheet is assembled in bounded
     * memory; PhpSpreadsheet still holds the finished workbook, which is the
     * price of a real .xlsx rather than a renamed CSV.
     *
     * @param  array<int, array{key: string, label: string, text?: true}>  $columns
     */
    private function excelResponse(string $type, array $columns, Builder $query, string $filename): Response
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $textKeys = collect($columns)->where('text', true)->pluck('key')->all();
        $sheet->fromArray(collect($columns)->pluck('label')->all(), null, 'A1');
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($columns)).'1')->getFont()->setBold(true);

        $row = 2;
        $order = $this->columnKeys($type);

        $query->orderBy('id')->chunkById(self::CHUNK, function ($models) use ($sheet, $type, $textKeys, $order, &$row): void {
            foreach ($models as $model) {
                foreach ($this->mapRow($type, $model) as $i => $value) {
                    $address = Coordinate::stringFromColumnIndex($i + 1).$row;
                    $cell = $sheet->getCell($address);

                    $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

                    if (in_array($order[$i], $textKeys, true)) {
                        $cell->getStyle()->getNumberFormat()->setFormatCode('@');
                    }
                }

                $row++;
            }
        });

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        return response()->streamDownload(
            fn () => $writer->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    /**
     * CSV: a UTF-8 BOM (Excel's cue for a UTF-8 file), then one fputcsv row per
     * record, streamed in chunks so the whole dataset never sits in memory at
     * once.
     *
     * CSV has no cell types, so a phone number has to be told apart from a
     * number another way: a `text` column whose value is pure digits gets a
     * leading apostrophe, the same convention Excel uses for "keep this as
     * text". Everything else is fputcsv's own quoting, which handles commas,
     * quotes and line breaks exactly as a parser expects.
     *
     * @param  array<int, array{key: string, label: string, text?: true}>  $columns
     */
    private function csvResponse(string $type, array $columns, Builder $query, string $filename): StreamedResponse
    {
        $textKeys = collect($columns)->where('text', true)->pluck('key')->all();
        $headers = collect($columns)->pluck('label')->all();

        return new StreamedResponse(function () use ($query, $type, $headers, $textKeys): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            $queried = clone $query;
            $indexBy = array_flip($this->columnKeys($type));

            $queried->chunkById(self::CHUNK, function ($models) use ($out, $type, $textKeys, $indexBy): void {
                foreach ($models as $model) {
                    $row = $this->mapRow($type, $model);

                    foreach ($textKeys as $key) {
                        $value = $row[$indexBy[$key]] ?? '';

                        if (preg_match('/^\d+$/', $value)) {
                            $row[$indexBy[$key]] = "'".$value;
                        }
                    }

                    fputcsv($out, $row);
                }
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /* ====================================================================
     | Helpers
     ==================================================================== */

    /** @param  array<string, mixed>  $f  @return array{0: Carbon, 1: Carbon}|null */
    private function window(array $f): ?array
    {
        if (empty($f['from']) || empty($f['to'])) {
            return null;
        }

        return [
            Carbon::createFromFormat('Y-m-d', $f['from'])->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $f['to'])->endOfDay(),
        ];
    }

    /**
     * A paginated PDF's filename carries its own row range — `-rows-501-1000`
     * — so a batch downloaded on its own is identifiable without opening it.
     * Every other export, and a PDF small enough to need only one page, keeps
     * the plain name it always had.
     */
    private function filename(string $type, string $format, int $page = 1, ?int $count = null): string
    {
        $slug = match ($type) {
            'leads' => 'leads',
            'followups' => 'followups',
            'channel_partners' => 'channel-partners',
        };

        $base = sprintf('shaligram-%s-%s', $slug, today()->toDateString());

        if ($format === 'pdf' && $count !== null && $count > $this->pdfRowLimit()) {
            $limit = $this->pdfRowLimit();
            $start = (($page - 1) * $limit) + 1;
            $end = min($page * $limit, $count);

            $base .= sprintf('-rows-%d-%d', $start, $end);
        }

        return sprintf('%s.%s', $base, self::EXTENSIONS[$format]);
    }

    /**
     * The value of one cell of one row, in the vocabulary of the application
     * rather than the raw database — stage keys become labels, ids become names —
     * and kept short enough to read in a table, PDF or spreadsheet cell.
     */
    private function cellValue(string $type, string $key, $model): string
    {
        $lead = $type === 'followups' ? $model->lead : $model;
        $date = fn ($v) => $v ? $v->format('Y-m-d H:i:s') : '';
        $partner = $type === 'channel_partners' ? $model : $lead;

        return match ($key) {
            'id' => (string) $model->id,
            'first_name' => (string) $model->first_name,
            'last_name' => (string) $model->last_name,
            'mobile_number' => (string) ($lead->mobile_number ?? ''),
            'email' => (string) ($lead->email ?? ''),
            'project' => (string) ($lead->project?->name ?? ''),
            'stage' => CrmTaxonomy::stageLabel($lead->stage),
            'assigned_to' => (string) ($model->owner?->display_name ?? ''),
            'assigned_role' => (string) config('crm.role_words.'.$lead->assigned_role, $lead->assigned_role ?? ''),
            'source' => CrmTaxonomy::sourceLabel($lead->source),
            'channel_partner' => (string) ($lead->channelPartner?->display_label ?? $lead->broker_name ?? ''),
            'created_at' => $date($model->created_at),
            'updated_at' => $date($model->updated_at),
            'lead_name' => (string) ($model->lead?->full_name ?? ''),
            'type' => (string) (config('crm.todo_types')[$model->type] ?? $model->type),
            'scheduled_at' => $date($model->scheduled_at),
            'status' => $type === 'channel_partners'
                ? ($model->is_active ? 'Active' : 'Inactive')
                : ucfirst((string) $model->status),
            'completed_at' => $date($model->completed_at),
            'remarks' => (string) ($model->remarks ?? ''),
            'outcome_stage' => $model->outcome_stage ? CrmTaxonomy::stageLabel($model->outcome_stage) : '',
            'name' => (string) $model->name,
            'contact_person' => (string) ($model->contact_person ?? ''),
            'phone' => (string) ($model->phone ?? ''),
            'alt_phone' => (string) ($model->alt_phone ?? ''),
            'firm' => (string) ($model->parent?->name ?? ''),
            'address' => (string) ($model->address ?? ''),
            default => '',
        };
    }
}
