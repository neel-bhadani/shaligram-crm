<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadImportRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadImport\LeadImportDetector;
use App\Services\LeadImport\LeadImportPlanner;
use App\Services\LeadImport\LeadImportReader;
use App\Services\LeadImport\LeadImportService;
use App\Services\LeadImport\LeadImportStore;
use App\Support\CrmTaxonomy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadImportController extends Controller
{
    private const MAPPABLE_FIELDS = [
        'first_name', 'middle_name', 'last_name', 'full_name', 'mobile_number', 'email',
        'project', 'source', 'stage', 'requirement', 'broker_name', 'created_at', 'assigned_user',
        'follow_up_date', 'follow_up_time', 'follow_up_datetime',
    ];

    public function __construct(private LeadImportReader $reader, private LeadImportPlanner $planner, private LeadImportService $importer, private LeadImportStore $store, private LeadImportDetector $detector) {}

    private function authorizeImport(Request $request): void
    {
        $this->authorize('create', Lead::class);
        abort_unless($request->user()->is_active && in_array($request->user()->role, ['admin', 'salesperson'], true), 403);
    }

    public function create(Request $request): Response
    {
        $this->authorizeImport($request);

        return Inertia::render('Leads/Import/Index', ['options' => [
            'fields' => self::MAPPABLE_FIELDS, 'projects' => $this->planner->projects($request->user()),
            'sources' => CrmTaxonomy::sources(), 'stages' => CrmTaxonomy::stages(),
            'todoTypes' => config('crm.todo_types'), 'today' => now('Asia/Kolkata')->toDateString(),
            'assignableUsers' => User::active()->whereIn('role', ['admin', 'salesperson', 'telecaller'])->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->display_name, 'role' => $u->role]),
        ]]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate(['file' => 'required|file|extensions:csv,xls,xlsx|mimes:csv,txt,xls,xlsx|max:20480']);
        $this->store->prune();
        $token = (string) Str::uuid();
        $directory = $this->store->directory($request->user(), $token);
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        $source = $request->file('file')->storeAs($directory, 'source.'.$extension, 'local');
        try {
            $parsed = $this->reader->read(Storage::disk('local')->path($source));
            $state = [
                'source' => $source, 'filename' => $request->file('file')->getClientOriginalName(),
                'expires_at' => now()->addHours(24)->timestamp, 'phase' => 'upload',
                'sheet' => $parsed['sheet'], 'sheets' => $parsed['sheets'], 'mapping' => [],
                'sheet_confirmed' => ! $parsed['sheetAmbiguous'],
            ];
            $this->store->write($request->user(), $token, $state);
        } catch (\Throwable $e) {
            $this->store->delete($request->user(), $token);
            throw ValidationException::withMessages(['file' => 'Cannot read file: '.$e->getMessage()]);
        }

        return response()->json(['token' => $token, 'filename' => $state['filename']] + $this->metadata($parsed));
    }

    public function sheet(Request $request): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate(['token' => 'required|string', 'sheet' => 'required|string']);

        return $this->store->locked($request->user(), $request->token, function (array $state) use ($request): JsonResponse {
            abort_if(in_array($state['phase'], ['importing', 'complete']), 409);
            $parsed = $this->readSheet($state, $request->sheet);
            $state['sheet'] = $parsed['sheet'];
            $state['sheet_confirmed'] = true;
            $state['mapping'] = [];
            $state['phase'] = 'upload';
            unset($state['plan'], $state['plan_id'], $state['overrides']);
            $this->store->write($request->user(), $request->token, $state);

            return response()->json($this->metadata($parsed));
        });
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate([
            'token' => 'required|string', 'mapping' => 'required|array:'.implode(',', self::MAPPABLE_FIELDS),
            'mapping.*' => 'nullable|string', 'date_order' => ['nullable', Rule::in(['auto', 'dmy', 'mdy'])],
            'overrides' => 'nullable|array', 'overrides.*' => 'array', 'overrides.*.*' => 'nullable|string|max:255',
            'reset_rows' => 'sometimes|array', 'reset_rows.*' => 'integer|min:1',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'filter' => ['nullable', Rule::in(['all', 'clean', 'problems', 'edited', 'duplicates', 'excluded'])],
        ]);

        return $this->store->locked($request->user(), $request->token, function (array $state) use ($request): JsonResponse {
            abort_if(in_array($state['phase'], ['importing', 'complete']), 409);
            abort_unless($state['sheet_confirmed'] ?? false, 409, 'Choose and confirm a worksheet before reviewing parsed data.');
            $parsed = $this->readSheet($state, $state['sheet']);
            $mapping = array_filter($request->mapping);
            if (isset($mapping['full_name']) && array_intersect(['first_name', 'middle_name', 'last_name'], array_keys($mapping)) !== []) {
                throw ValidationException::withMessages(['mapping' => 'Map either the full name column (Full Name split) OR separate First/Middle/Last columns, not both.']);
            }
            if ((empty($mapping['first_name']) && empty($mapping['full_name'])) || empty($mapping['mobile_number']) || array_diff(array_values($mapping), $parsed['headers']) || count(array_unique($mapping)) !== count($mapping)) {
                throw ValidationException::withMessages(['mapping' => 'Map first_name (or full_name) and mobile_number to distinct existing columns. Each source column may be used only once.']);
            }
            // A changed mapping makes every stored override stale (auto-split,
            // phone column or the project/source/stage columns moved). Drop them
            // and tell the frontend, instead of silently combining old edits
            // with new columns. upload() persists an empty mapping marker, so a
            // truthy check keeps the first preview from treating that
            // placeholder as a confirmed mapping.
            $overridesCleared = false;
            if (($state['mapping'] ?? null) && $state['mapping'] !== $mapping) {
                $overrides = [];
                $overridesCleared = true;
            } else {
                $overrides = $this->sanitizeOverrides($state['overrides'] ?? [], $parsed['rows'], $mapping);
                foreach ($request->input('reset_rows', []) as $row) {
                    unset($overrides[$row]);
                }
                foreach ($this->sanitizeOverrides($request->input('overrides', []), $parsed['rows'], $mapping) as $row => $fields) {
                    $overrides[$row] = array_replace($overrides[$row] ?? [], $fields);
                }
            }
            $state['mapping'] = $mapping;
            $state['date_order'] = $request->input('date_order', 'auto');
            $state['overrides'] = $overrides;
            $rows = $this->planner->plan($parsed['rows'], $mapping, [], $request->user(), $state['sheet'], dateOrder: $state['date_order'], overrides: $overrides, excluded: $state['excluded_rows'] ?? []);
            $state['phase'] = 'parsed';
            unset($state['plan'], $state['plan_id']);
            $this->store->write($request->user(), $request->token, $state);

            return response()->json(['overrides' => $overrides, 'overridesCleared' => $overridesCleared] + $this->present($rows, false, $this->pagingParams($request)));
        });
    }

    public function finalPreview(Request $request): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate([
            'token' => 'required|string', 'accepted_preview' => 'accepted', 'defaults' => 'required|array',
            'mapping' => 'required|array:'.implode(',', self::MAPPABLE_FIELDS), 'mapping.*' => 'nullable|string',
            'date_order' => ['nullable', Rule::in(['auto', 'dmy', 'mdy'])],
        ]);

        return $this->store->locked($request->user(), $request->token, function (array $state) use ($request): JsonResponse {
            abort_unless(in_array($state['phase'], ['parsed', 'final']), 409, 'Review parsed data first.');
            $mapping = $state['mapping'];
            if (array_filter($request->mapping) != $mapping || $request->input('date_order', 'auto') !== $state['date_order']) {
                $state['phase'] = 'stale';
                unset($state['plan'], $state['plan_id']);
                $this->store->write($request->user(), $request->token, $state);
                abort(409, 'Mapping changed. Refresh the parsed preview before continuing.');
            }
            $allowedProjects = array_column($this->planner->projects($request->user()), 'id');
            $hasTimeColumn = isset($mapping['follow_up_time']) || isset($mapping['follow_up_datetime']);
            // The frontend sends a canonical time_mode (manual / auto / uploaded).
            // Legacy payloads still carry time_source; map that so old clients keep
            // working: 'uploaded' stays uploaded, anything else is manual.
            $defaults = $request->input('defaults', []);
            if (is_array($defaults) && ! array_key_exists('time_mode', $defaults)) {
                $defaults['time_mode'] = ($defaults['time_source'] ?? 'fixed') === 'uploaded' ? 'uploaded' : 'manual';
                $request->merge(['defaults' => $defaults]);
            }
            $validated = $request->validate([
                'defaults.project_id' => [isset($mapping['project']) ? 'nullable' : 'required', 'integer', Rule::in($allowedProjects)],
                'defaults.source' => [isset($mapping['source']) ? 'nullable' : 'required', Rule::in(CrmTaxonomy::activeSourceKeys())],
                'defaults.stage' => [isset($mapping['stage']) ? 'nullable' : 'required', Rule::in(CrmTaxonomy::activeStageKeys())],
                'defaults.assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
                'defaults.mode' => ['required', Rule::in(['today', 'specific', 'spread'])],
                'defaults.start_date' => ['required_unless:defaults.mode,today', 'nullable', 'date_format:Y-m-d'],
                'defaults.end_date' => ['exclude_unless:defaults.mode,spread', 'required', 'date_format:Y-m-d', 'after_or_equal:defaults.start_date'],
                'defaults.time_mode' => ['required', Rule::in($hasTimeColumn ? ['manual', 'auto', 'uploaded'] : ['manual', 'auto'])],
                'defaults.time_source' => ['nullable', Rule::in(['uploaded', 'fixed'])],
                'defaults.time' => ['required_if:defaults.time_mode,manual', 'required_if:defaults.time_source,fixed', 'prohibited_if:defaults.time_mode,auto', 'date_format:H:i'],
                'defaults.fallback_time' => ['required_if:defaults.time_mode,uploaded', 'required_if:defaults.time_source,uploaded', 'date_format:H:i'],
                'defaults.follow_up_type' => ['required', Rule::in(array_keys(config('crm.todo_types')))],
            ]);
            $settings = $validated['defaults'];
            if ($settings['mode'] === 'spread' && Carbon::parse($settings['start_date'])->diffInDays($settings['end_date']) > 366) {
                throw ValidationException::withMessages(['defaults.end_date' => 'Choose a range of at most 367 days.']);
            }
            $parsed = $this->readSheet($state, $state['sheet']);
            $rows = $this->planner->plan($parsed['rows'], $mapping, $settings, $request->user(), $state['sheet'], true, $state['date_order'], $state['overrides'] ?? [], $state['excluded_rows'] ?? []);
            $state['plan'] = $this->planner->schedule($rows, $settings);
            $state['settings'] = $settings;
            $state['plan_id'] = (string) Str::uuid();
            $state['phase'] = 'final';
            $state['offset'] = 0;
            $state['results'] = [];
            $this->store->write($request->user(), $request->token, $state);

            return response()->json(['plan_id' => $state['plan_id']] + $this->present($state['plan'], true));
        });
    }

    public function exclude(Request $request): JsonResponse
    {
        return $this->setExcluded($request, true);
    }

    public function restore(Request $request): JsonResponse
    {
        return $this->setExcluded($request, false);
    }

    /**
     * Exclude or restore one source row for this import session.
     *
     * Exclusions live in the session state only, scoped by "sheet|row" so a
     * different worksheet's row of the same number is never affected, and a
     * stored final plan is invalidated the moment it happens — the backend
     * re-reads them on final import, so a tampered frontend payload can never
     * sneak an excluded row back in. Nothing business-side is written.
     */
    private function setExcluded(Request $request, bool $exclude): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate(['token' => 'required|string', 'row' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'filter' => ['nullable', Rule::in(['all', 'clean', 'problems', 'edited', 'duplicates', 'excluded'])]]);

        return $this->store->locked($request->user(), $request->token, function (array $state) use ($request, $exclude): JsonResponse {
            abort_if(in_array($state['phase'], ['importing', 'complete']), 409);
            abort_unless(in_array($state['phase'], ['parsed', 'final']), 409, 'Map and review parsed data first.');
            $parsed = $this->readSheet($state, $state['sheet']);
            $row = $request->integer('row');
            abort_unless(array_key_exists($row, $parsed['rows']), 422, 'Source row not found.');
            $set = $state['excluded_rows'] ?? [];
            $key = $state['sheet'].'|'.$row;
            if ($exclude) {
                $set[$key] = true;
            } else {
                unset($set[$key]);
            }
            $state['excluded_rows'] = $set;
            // A stored final plan is stale once the batch membership changed.
            $state['phase'] = 'parsed';
            unset($state['plan'], $state['plan_id'], $state['settings'], $state['offset'], $state['results']);
            $rows = $this->planner->plan($parsed['rows'], $state['mapping'], [], $request->user(), $state['sheet'], dateOrder: $state['date_order'] ?? 'auto', overrides: $state['overrides'] ?? [], excluded: $set);
            $this->store->write($request->user(), $request->token, $state);

            return response()->json(['overrides' => $state['overrides'] ?? []] + $this->present($rows, false, $this->pagingParams($request)));
        });
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate(['token' => 'required|string', 'plan_id' => 'required|uuid', 'confirm' => 'accepted', 'offset' => 'required|integer|min:0', 'mapping' => 'prohibited', 'defaults' => 'prohibited', 'rows' => 'prohibited']);

        return $this->store->locked($request->user(), $request->token, function (array $state) use ($request): JsonResponse {
            abort_unless(in_array($state['phase'], ['final', 'importing', 'complete']) && hash_equals($state['plan_id'], $request->plan_id), 409, 'Final preview changed. Review it again.');
            if ($request->integer('offset') < $state['offset'] || $state['phase'] === 'complete') {
                return response()->json($this->progress($state));
            }
            abort_unless($request->integer('offset') === $state['offset'], 409, 'Invalid progress offset.');
            if ($state['phase'] === 'final') {
                $state['before_open_without_pending'] = Lead::open()->doesntHave('pendingTodo')->count();
                $state['import_time'] = now()->format('Y-m-d H:i:s');
                $state['started_at'] = microtime(true);
                $state['phase'] = 'importing';
                $this->store->write($request->user(), $request->token, $state);
            }
            $slice = array_slice($state['plan'], $state['offset'], 200);
            // The session state is authoritative, not the frontend. Re-apply
            // exclusions on every chunk so even a stale or forged plan can
            // never import a row the user chose to leave out.
            $excludedSet = $state['excluded_rows'] ?? [];
            foreach ($slice as &$row) {
                if (isset($excludedSet[$state['sheet'].'|'.$row['row']])) {
                    $row['outcome'] = 'excluded';
                    $row['errors'] = [];
                }
            }
            unset($row);
            $raw = [];
            foreach ($slice as $row) {
                if ($row['outcome'] === 'create') {
                    $raw[$row['row']] = $row['raw'];
                }
            }
            $rechecked = collect($this->planner->plan($raw, $state['mapping'], $state['settings'], $request->user(), $state['sheet'], true, $state['date_order'], $state['overrides'] ?? [], $excludedSet))->keyBy('row');
            $batchRecords = LeadImportRecord::whereIn('source_file', array_column($slice, 'import_key'))->where('import_batch', $state['plan_id'])->pluck('source_file')->flip();
            $owners = User::active()->with('projects:id')->whereIn('id', array_filter(array_column($slice, 'assigned_to')))->get()->keyBy('id');
            foreach ($slice as &$row) {
                $fresh = $rechecked->get($row['row']);
                if ($fresh && $fresh['outcome'] === 'create' && $fresh['attributes'] !== $row['attributes']) {
                    $row['outcome'] = 'error';
                    $row['errors'] = ['Project, source, stage or normalized values changed since preview. Regenerate the final preview.'];
                }
                if ($fresh && $fresh['outcome'] !== 'create') {
                    $sameBatch = $batchRecords->has($row['import_key']);
                    if (! $sameBatch) {
                        $row['outcome'] = $fresh['outcome'];
                        $row['errors'] = $fresh['errors'];
                    }
                }
                if ($row['outcome'] === 'create') {
                    $owner = $owners->get($row['assigned_to']);
                    $role = CrmTaxonomy::ownerRoleFor($row['attributes']['stage']);
                    if (! $owner || ($role && $owner->role !== $role) || ($owner->isSalesperson() && ! $owner->projects->contains('id', $row['attributes']['project_id']))) {
                        $row['outcome'] = 'error';
                        $row['errors'] = ['Assignee eligibility changed since preview.'];
                    }
                }
            }
            unset($row);
            $results = $this->importer->import($slice, $request->user(), $state['plan_id'], $state['import_time']);
            $state['results'] = array_merge($state['results'], $results);
            $state['offset'] += count($slice);
            if ($state['offset'] >= count($state['plan'])) {
                $state['phase'] = 'complete';
                $state['after_open_without_pending'] = Lead::open()->doesntHave('pendingTodo')->count();
                $state['duration'] = round(microtime(true) - $state['started_at'], 2);
                Storage::disk('local')->delete($state['source']);
            }
            $this->store->write($request->user(), $request->token, $state);

            return response()->json($this->progress($state));
        });
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate(['token' => 'required|string']);
        $this->store->locked($request->user(), $request->token, function () use ($request): void {
            $this->store->delete($request->user(), $request->token);
        });

        return response()->json(['cancelled' => true]);
    }

    public function download(Request $request): StreamedResponse
    {
        $this->authorizeImport($request);
        $request->validate(['token' => 'required|string']);
        $state = $this->store->read($request->user(), $request->token);
        abort_unless($state['phase'] === 'complete', 409);

        return response()->streamDownload(function () use ($state): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['row_number', 'first_name', 'mobile_number', 'project', 'source', 'stage', 'result', 'reason'], escape: '');
            foreach ($state['results'] as $row) {
                if ($row['result'] === 'created' || $row['result'] === 'excluded') {
                    continue;
                }
                $a = $row['attributes'];
                $values = [$row['row'], $a['first_name'], $a['mobile_number'] ?? ($row['raw'][$state['mapping']['mobile_number']] ?? ''), $row['project'], $a['source'], $a['stage'], $row['result'], $row['reason']];
                $values = array_map(fn ($v): string => preg_match('/^[\s]*[=+@\-]/u', (string) $v) ? "'".$v : (string) $v, $values);
                fputcsv($stream, $values, escape: '');
            }
            fclose($stream);
        }, 'lead-import-skipped-failed.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function progress(array $state): array
    {
        $results = collect($state['results']);

        return [
            'total' => count($state['plan']), 'processed' => $state['offset'], 'nextOffset' => $state['offset'],
            'created' => $results->where('result', 'created')->count(), 'skipped' => $results->where('result', 'skipped')->count(),
            'excluded' => $results->where('result', 'excluded')->count(), 'failed' => $results->where('result', 'failed')->count(), 'followUps' => $results->sum('followUps'),
            'done' => $state['phase'] === 'complete', 'duration' => $state['duration'] ?? null,
            'beforeOpenWithoutPending' => $state['before_open_without_pending'] ?? null,
            'afterOpenWithoutPending' => $state['after_open_without_pending'] ?? null,
        ];
    }

    private function readSheet(array $state, string $sheet): array
    {
        try {
            return $this->reader->read(Storage::disk('local')->path($state['source']), $sheet);
        } catch (\Throwable $e) {
            if (in_array($sheet, $state['sheets'], true)) {
                Storage::disk('local')->deleteDirectory(dirname($state['source']));
            }
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
    }

    /**
     * Reduce a frontend overrides payload to explicit, per-row string edits.
     *
     * Only the fields this import can actually honour are kept, row keys must
     * name a real source row, and values are clamped. Anything else is dropped,
     * so a tampered request can never reach the planner.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, string>  $mapping
     * @return array<int, array<string, string>>
     */
    private function sanitizeOverrides(mixed $payload, array $rows, array $mapping): array
    {
        $editable = ['first_name', 'middle_name', 'last_name', 'mobile_number', 'project', 'source', 'stage'];
        if (isset($mapping['email'])) {
            $editable[] = 'email';
        }
        if (isset($mapping['created_at'])) {
            $editable[] = 'created_at';
        }
        $editable = array_flip($editable);
        $clean = [];
        if (! is_array($payload)) {
            return $clean;
        }
        foreach ($payload as $key => $fields) {
            $row = filter_var($key, FILTER_VALIDATE_INT);
            if ($row === false || $row < 1 || ! array_key_exists($row, $rows) || ! is_array($fields)) {
                continue;
            }
            $rowOverrides = [];
            foreach ($fields as $field => $value) {
                if (! isset($editable[$field])) {
                    continue;
                }
                // JSON delivers cleared text inputs as null, not ''. Name and
                // email fields may legitimately be emptied (e.g. middle name);
                // every other field still needs a real string value.
                if (in_array($field, ['first_name', 'middle_name', 'last_name', 'email'], true) && $value === null) {
                    $rowOverrides[$field] = '';

                    continue;
                }
                if (! is_string($value)) {
                    continue;
                }
                $rowOverrides[$field] = mb_substr($value, 0, 255);
            }
            if ($rowOverrides !== []) {
                $clean[$row] = $rowOverrides;
            }
        }

        return $clean;
    }

    private function metadata(array $parsed): array
    {
        $taxonomy = [
            'projects' => Project::query()->active()->orderBy('name')->pluck('name')->all(),
            'sources' => CrmTaxonomy::activeSourceKeys(),
            'stages' => CrmTaxonomy::activeStageKeys(),
            'source_labels' => CrmTaxonomy::allSources(),
            'stage_labels' => CrmTaxonomy::allStages(),
        ];
        $mapping = $this->detector->guessMapping($parsed['headers'], $parsed['rows'], $taxonomy);

        return [
            'headers' => $parsed['headers'], 'sampleRows' => array_values(array_slice($parsed['rows'], 0, 5, true)),
            'totalRows' => count($parsed['rows']), 'sheets' => $parsed['sheets'], 'sheet' => $parsed['sheet'],
            'headerRow' => $parsed['headerRow'] ?? 1, 'sheetAmbiguous' => $parsed['sheetAmbiguous'] ?? false,
            'guessedMapping' => $mapping,
            'mappingConfidence' => $this->detector->confidence($mapping, $parsed['headers'], $parsed['rows'], $taxonomy),
            'confirmations' => $this->detector->ambiguousRequired($parsed['headers'], $parsed['rows']),
            'unresolvedRequired' => $this->detector->unresolvedRequired($mapping),
        ];
    }

    /**
     * The non-final preview no longer sends every planned row. Instead it
     * serves one page of the main review table — filtered server-side on the
     * same dataset the user is about to import, so a 10,000-row file stays
     * reviewable page by page without one oversized response. The legacy
     * previewRows/problemRows/excludedRows buckets stay for compatibility;
     * editing and excluding act on the paged "rows" list.
     *
     * @param  list<array>  $rows
     * @param  array{page: int, per_page: int, filter: string}  $page
     * @return array<string, mixed>
     */
    private function present(array $rows, bool $final, array $page = ['page' => 1, 'per_page' => 30, 'filter' => 'all']): array
    {
        $collection = collect($rows);
        $creates = $collection->where('outcome', 'create');
        $excluded = $collection->where('outcome', 'excluded');
        // Exclusions are an intentional batch decision, not a validation
        // problem: they get their own bucket so problems + excluded + creates
        // always adds back up to the source row total.
        $summary = [
            'total' => count($rows), 'clean' => $creates->count(), 'problems' => count($rows) - $creates->count() - $excluded->count(),
            'excluded' => $excluded->count(), 'toCreate' => $creates->count(), 'errors' => $collection->where('outcome', 'error')->count(),
        ];
        foreach (['duplicatesInFile', 'duplicatesInDatabase', 'invalidMobile', 'unknownStage', 'unknownSource', 'unknownProject', 'missingRequired', 'unauthorizedProject', 'alreadyImported', 'invalidAssignee', 'invalidDate', 'invalidFollowUpDate', 'invalidTime'] as $code) {
            $summary[$code] = $collection->filter(fn (array $r): bool => in_array($code, $r['codes'], true))->count();
        }
        $summary['missingFollowUpTimes'] = $collection->filter(fn (array $r): bool => $r['outcome'] === 'create' && array_key_exists('file_follow_up_time', $r) && $r['file_follow_up_time'] === null)->count();
        $summary['followUpsToCreate'] = $final ? $creates->filter(fn (array $r): bool => $r['follow_up_at'] !== null)->count() : 0;

        $filtered = match ($page['filter']) {
            'clean' => $creates,
            'problems' => $collection->reject(fn (array $r): bool => in_array($r['outcome'], ['create', 'excluded'], true)),
            'edited' => $collection->filter(fn (array $r): bool => ($r['edited'] ?? false) === true),
            'duplicates' => $collection->filter(fn (array $r): bool => in_array($r['outcome'], ['skip_duplicate_file', 'skip_duplicate_db', 'skip_imported'], true)),
            'excluded' => $excluded,
            default => $collection,
        };
        $perPage = $page['per_page'];
        $lastPage = max(1, (int) ceil($filtered->count() / $perPage));
        $currentPage = min(max(1, $page['page']), $lastPage);

        return [
            'summary' => $summary,
            'previewRows' => ($final ? $creates->take(20) : $collection->take(30))->values(),
            'problemRows' => $collection->reject(fn (array $r): bool => in_array($r['outcome'], ['create', 'excluded'], true))->values(),
            'excludedRows' => $excluded->values(),
            'dateDistribution' => $final ? $creates->filter(fn (array $r): bool => $r['follow_up_at'] !== null)->countBy(fn (array $r): string => substr($r['follow_up_at'], 0, 10)) : [],
            'rows' => $filtered->forPage($currentPage, $perPage)->values()->all(),
            'pagination' => ['current_page' => $currentPage, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $filtered->count()],
            'filter' => $page['filter'],
        ];
    }

    private function pagingParams(Request $request): array
    {
        return [
            'page' => max(1, $request->integer('page', 1)),
            'per_page' => min(100, max(1, $request->integer('per_page', 30))),
            'filter' => $request->input('filter', 'all') ?: 'all',
        ];
    }
}
