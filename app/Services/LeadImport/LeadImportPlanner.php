<?php

namespace App\Services\LeadImport;

use App\Models\Lead;
use App\Models\LeadImportRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadAssignmentService;
use App\Services\MetaLeadNormaliser;
use App\Support\CrmTaxonomy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

/**
 * @phpstan-type ImportRow array{row: int, attributes: array<string, mixed>, raw: array<string, string>, project: ?string, assigned_user: ?string, assigned_to: ?int, errors: list<string>, codes: list<string>, pending: list<string>, outcome: string, import_key: string, edited: bool, excluded: bool, file_follow_up_date?: ?string, file_follow_up_time?: ?string, follow_up_at?: ?string, follow_up_date?: ?string, follow_up_time?: ?string, follow_up_type?: ?string}
 */
class LeadImportPlanner
{
    /** The only fields per-row edits may replace. Everything else is derived or import-session state. */
    private const OVERRIDE_FIELDS = ['first_name', 'middle_name', 'last_name', 'mobile_number', 'email', 'project', 'source', 'stage', 'created_at'];

    public function __construct(private MetaLeadNormaliser $normaliser, private LeadAssignmentService $assignment) {}

    public function projects(User $user): array
    {
        return ($user->isSalesperson() ? $user->projects() : Project::query())
            ->active()->orderBy('projects.name')->get(['projects.id', 'projects.name'])->toArray();
    }

    /**
     * Read-only resolution. Unmapped defaults remain pending in the parsed preview.
     *
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, string>  $mapping
     * @param  array{project_id?: ?int, source?: ?string, stage?: ?string, assigned_to?: ?int}  $defaults
     * @param  array<int, array<string, string>>  $overrides  per-row edits keyed by source row number
     * @param  array<string, bool>  $excluded  "sheet|row" keys the user chose not to import
     * @return list<ImportRow>
     */
    public function plan(array $rows, array $mapping, array $defaults, User $user, string $sheet, bool $final = false, string $dateOrder = 'auto', array $overrides = [], array $excluded = []): array
    {
        $projects = Project::active()->get(['id', 'name'])->keyBy('id');
        $allowed = array_column($this->projects($user), 'id');
        $users = User::active()->with('projects:id')->get()->keyBy('id');
        $sourceKeys = CrmTaxonomy::activeSourceKeys();
        $stageKeys = CrmTaxonomy::activeStageKeys();
        $sources = CrmTaxonomy::allSources();
        $stages = CrmTaxonomy::allStages();
        $drafts = [];
        $owners = [];
        foreach ($rows as $number => $raw) {
            $cell = function (string $field) use ($mapping, $raw): ?string {
                $value = isset($mapping[$field]) ? trim((string) ($raw[$mapping[$field]] ?? '')) : '';

                return $value !== '' ? $value : null;
            };
            $o = array_intersect_key($overrides[(int) $number] ?? [], array_flip(self::OVERRIDE_FIELDS));
            $errors = [];
            $codes = [];
            $pending = [];
            $problem = function (string $code, string $message) use (&$errors, &$codes): void {
                $codes[] = $code;
                $errors[] = $message;
            };
            $attributes = [];
            foreach (['first_name', 'middle_name', 'last_name', 'email', 'requirement', 'broker_name'] as $field) {
                $attributes[$field] = $cell($field);
            }
            if ($attributes['first_name'] === null && isset($mapping['full_name'])) {
                $full = $cell('full_name');
                if ($full !== null) {
                    [$first, $middle, $last] = $this->normaliser->splitNameParts($full);
                    $attributes['first_name'] = $first;
                    $attributes['middle_name'] = $middle;
                    $attributes['last_name'] = $last;
                }
            }
            $attributes['first_name'] ??= '';
            $attributes['middle_name'] ??= '';
            $attributes['last_name'] ??= '';
            // Per-row edits land AFTER automatic parsing, so a partial name edit
            // keeps the auto-split parts of the fields the user left alone.
            foreach (['first_name', 'middle_name', 'last_name', 'email'] as $field) {
                if (array_key_exists($field, $o)) {
                    $attributes[$field] = (string) $o[$field];
                }
            }
            // A spreadsheet formula that arrived without a cached value must
            // never be written into the CRM, so the raw '=...' text is flagged
            // and the field is blanked instead of imported.
            foreach (['first_name', 'middle_name', 'last_name', 'email', 'requirement', 'broker_name'] as $field) {
                $value = $attributes[$field];
                if ($value !== null && str_starts_with($value, '=')) {
                    $problem('formulaValue', 'Excel formula values are not imported. Use a saved plain value, not a "=" formula.');
                    $attributes[$field] = null;
                }
            }
            if ($attributes['first_name'] === '') {
                $problem('missingRequired', 'Missing first_name.');
            }
            $mobileValue = array_key_exists('mobile_number', $o) ? $o['mobile_number'] : $cell('mobile_number');
            $attributes['mobile_number'] = $this->importPhone($mobileValue);
            if ($attributes['mobile_number'] === null) {
                $problem('invalidMobile', 'Mobile number "'.($mobileValue ?? '').'" is not a valid 10-digit mobile number.');
                if ($mobileValue === null || $mobileValue === '') {
                    $problem('missingRequired', 'Missing mobile_number.');
                }
            }
            $projectOverride = array_key_exists('project', $o) ? trim((string) $o['project']) : '';
            $projectValue = $projectOverride !== '' ? $projectOverride : (isset($mapping['project']) ? $cell('project') : null);
            $project = $projectValue !== null && $projectValue !== ''
                ? $projects->first(fn (Project $p): bool => strcasecmp($p->name, $projectValue) === 0)
                : (isset($mapping['project']) ? null : $projects->get($defaults['project_id'] ?? null));
            $attributes['project_id'] = $project?->id;
            if (! $project) {
                if (! isset($mapping['project']) && ! $final) {
                    $pending[] = 'project: Not in file — you will choose one in Follow-up Setup.';
                    $pending[] = 'Duplicate check pending project selection';
                } else {
                    if (! $projectValue) {
                        $problem('missingRequired', 'Missing project.');
                    }
                    $problem('unknownProject', 'Unknown project: "'.($projectValue ?? '').'".');
                }
            } elseif (! in_array($project->id, $allowed, true)) {
                $problem('unauthorizedProject', "Salesperson is not allowed to import into {$project->name}.");
            }
            foreach (['source' => [$sources, $sourceKeys], 'stage' => [$stages, $stageKeys]] as $field => [$labels, $keys]) {
                $value = isset($mapping[$field]) ? ($o[$field] ?? $cell($field)) : ($o[$field] ?? ($defaults[$field] ?? null));
                $resolved = null;
                foreach ($keys as $key) {
                    if (strcasecmp($value ?? '', $key) === 0 || strcasecmp($value ?? '', $labels[$key] ?? '') === 0) {
                        $resolved = $key;
                        break;
                    }
                }
                $attributes[$field] = $resolved;
                if ($resolved === null) {
                    if (! isset($mapping[$field]) && ! $final) {
                        $pending[] = "{$field}: Not in file — you will choose one in Follow-up Setup.";
                    } else {
                        if ($value === null || $value === '') {
                            $problem('missingRequired', "Missing {$field}.");
                        }
                        $problem('unknown'.ucfirst($field), "Unknown {$field}: \"{$value}\".");
                    }
                }
            }
            if (isset($mapping['created_at'])) {
                try {
                    $attributes['created_at'] = $this->builtDate($o['created_at'] ?? $cell('created_at'), $dateOrder, 'created_at');
                } catch (\Throwable $e) {
                    $problem('invalidDate', $e->getMessage());
                }
            }
            $fileDate = null;
            if (isset($mapping['follow_up_date']) || isset($mapping['follow_up_datetime'])) {
                $value = $cell('follow_up_date') ?? $cell('follow_up_datetime');
                if ($value !== null) {
                    try {
                        $fileDate = substr($this->builtDate($value, $dateOrder, 'follow-up date'), 0, 10);
                    } catch (\Throwable $e) {
                        $problem('invalidFollowUpDate', 'Invalid follow-up date: "'.$value.'".');
                    }
                }
            }
            $fileTime = null;
            if (isset($mapping['follow_up_time']) || isset($mapping['follow_up_datetime'])) {
                $value = $cell('follow_up_time') ?? $cell('follow_up_datetime');
                if ($value !== null) {
                    $fileTime = $this->timeOnly($value);
                    if ($fileTime === null) {
                        $problem('invalidTime', 'Invalid follow-up time: "'.$value.'".');
                    }
                }
            }
            $shape = Validator::make($attributes, [
                'first_name' => 'string|max:100', 'middle_name' => 'nullable|string|max:100',
                'last_name' => 'nullable|string|max:100', 'email' => 'nullable|email|max:150',
                'requirement' => 'nullable|string|max:100', 'broker_name' => 'nullable|string|max:255',
            ]);
            foreach ($shape->errors()->all() as $message) {
                $problem('invalidField', $message);
            }
            $holder = null;
            if (isset($mapping['assigned_user'])) {
                $matches = $users->filter(fn (User $u): bool => strcasecmp($u->display_name, $cell('assigned_user') ?? '') === 0 || strcasecmp($u->email, $cell('assigned_user') ?? '') === 0);
                if ($matches->count() !== 1) {
                    $problem('invalidAssignee', 'Assigned user must uniquely match an active user name or email.');
                } else {
                    $holder = $matches->first();
                }
            } elseif ($final && ! empty($defaults['assigned_to'])) {
                $holder = $users->get($defaults['assigned_to']);
                if (! $holder) {
                    $problem('invalidAssignee', 'Assigned user is not active.');
                }
            }
            $owner = null;
            if ($project && $attributes['stage'] !== null && ! in_array('unauthorizedProject', $codes, true)) {
                if ($holder) {
                    $role = $this->assignment->roleFor($attributes['stage']);
                    if (! in_array($holder->role, ['admin', 'salesperson', 'telecaller'], true)
                        || ($role !== null && $holder->role !== $role)
                        || ($holder->isSalesperson() && ! $holder->projects->contains('id', $project->id))) {
                        $problem('invalidAssignee', 'Assigned user does not have the required stage role or project membership.');
                    } else {
                        $owner = $holder;
                    }
                } elseif ($final) {
                    $key = $project->id.'|'.$attributes['stage'];
                    $owner = $owners[$key] ??= $this->assignment->ownerFor($attributes['stage'], $user, $project->id, claim: false);
                    if (! $owner || ! $owner->is_active || (($requiredRole = $this->assignment->roleFor($attributes['stage'])) !== null && $owner->role !== $requiredRole) || ($owner->isSalesperson() && ! $users->get($owner->id)?->projects->contains('id', $project->id))) {
                        $problem('invalidAssignee', 'No eligible assignee for this project. Select an eligible assigned user.');
                    }
                }
            }
            $draft = [
                'row' => (int) $number, 'attributes' => $attributes, 'raw' => $raw,
                'project' => $project?->name ?? $projectValue,
                'assigned_user' => $owner?->display_name ?? $holder?->display_name ?? $cell('assigned_user'),
                'assigned_to' => $owner?->id,
                'errors' => $errors, 'codes' => array_values(array_unique($codes)), 'pending' => $pending,
                'outcome' => $errors ? 'error' : 'create',
                'edited' => $o !== [],
                'excluded' => isset($excluded[$sheet.'|'.(int) $number]),
                /** Existing source_file/source_row unique index holds a content-derived row identity, independent of uploads and batch defaults. */
                'import_key' => 'bulk:'.sha1(json_encode([$sheet, (int) $number, $raw], JSON_THROW_ON_ERROR)),
            ];
            if (isset($mapping['follow_up_date']) || isset($mapping['follow_up_datetime'])) {
                $draft['file_follow_up_date'] = $fileDate;
            }
            if (isset($mapping['follow_up_time']) || isset($mapping['follow_up_datetime'])) {
                $draft['file_follow_up_time'] = $fileTime;
            }
            $drafts[] = $draft;
        }

        return $this->duplicates($drafts);
    }

    /**
     * The existing unique mobile/project rule, batched across the complete file.
     *
     * A user-excluded row keeps whatever independent database/imported status it
     * already had for display, but it never reserves an in-file duplicate key:
     * excluding the first occurrence promotes the next one, and restoring it
     * demotes it again. Exclusions themselves always win the outcome column.
     */
    public function duplicates(array $drafts): array
    {
        $mobiles = array_values(array_unique(array_filter(array_column(array_column($drafts, 'attributes'), 'mobile_number'))));
        $existing = [];
        foreach (array_chunk($mobiles, 500) as $chunk) {
            foreach (Lead::withTrashed()->whereIn('mobile_number', $chunk)->get(['mobile_number', 'project_id']) as $lead) {
                $existing[$lead->mobile_number.'|'.$lead->project_id] = true;
            }
        }
        $imported = [];
        foreach (array_chunk(array_column($drafts, 'import_key'), 500) as $chunk) {
            foreach (LeadImportRecord::whereIn('source_file', $chunk)->pluck('source_file') as $key) {
                $imported[$key] = true;
            }
        }
        $seen = [];
        $exact = [];
        foreach ($drafts as &$draft) {
            $a = $draft['attributes'];
            $key = $a['mobile_number'].'|'.$a['project_id'];
            if ($draft['excluded']) {
                if ($a['project_id'] && isset($existing[$key])) {
                    $draft['codes'][] = 'duplicatesInDatabase';
                    $draft['errors'][] = "Mobile {$a['mobile_number']} already exists in project {$draft['project']}.";
                } elseif (isset($imported[$draft['import_key']])) {
                    $draft['codes'][] = 'alreadyImported';
                    $draft['errors'][] = 'This source row was already imported.';
                }
                $draft['outcome'] = 'excluded';

                continue;
            }
            if ($a['project_id'] && isset($existing[$key])) {
                $draft['codes'][] = 'duplicatesInDatabase';
                $draft['errors'][] = "Mobile {$a['mobile_number']} already exists in project {$draft['project']}.";
                $draft['outcome'] = 'skip_duplicate_db';
            } elseif (isset($imported[$draft['import_key']])) {
                $draft['codes'][] = 'alreadyImported';
                $draft['errors'][] = 'This source row was already imported.';
                $draft['outcome'] = 'skip_imported';
            } elseif ($draft['outcome'] === 'create') {
                $exactKey = sha1(json_encode($draft['raw']));
                $earlier = $a['project_id'] ? ($seen[$key] ?? null) : ($exact[$exactKey] ?? null);
                if ($earlier !== null) {
                    $draft['codes'][] = 'duplicatesInFile';
                    $draft['errors'][] = "Duplicate of row {$earlier} in this uploaded file.";
                    $draft['outcome'] = 'skip_duplicate_file';
                } else {
                    if ($a['project_id']) {
                        $seen[$key] = $draft['row'];
                    }
                    $exact[$exactKey] = $draft['row'];
                }
            }
        }
        unset($draft);

        return $drafts;
    }

    /**
     * Bulk imports accept only a complete Indian number with familiar separators.
     * Scientific text and extensions are rejected; numeric Excel cells have
     * already been expanded by the reader. Keep Meta's existing contract intact.
     */
    private function importPhone(?string $value): ?string
    {
        $number = preg_replace('/[\s()\-]+/u', '', trim($value ?? '')) ?? '';
        if (! preg_match('/^(?:\+91|0091|91)?[0-9]{10}$/D', $number)) {
            return null;
        }

        return $this->normaliser->phone($number);
    }

    /** ISO or explicitly ordered numeric dates; ambiguous numeric dates require a mapping choice. */
    private function builtDate(?string $value, string $order, string $label): string
    {
        if (! $value) {
            throw new RuntimeException("Missing mapped {$label} value. Remove its mapping to use import time.");
        }
        if (is_numeric($value) && (float) $value >= 1 && (float) $value <= 2958465) {
            return Date::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
        }
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4}|\d{2})(.*)$/D', $value, $match)) {
            [$a, $b] = [(int) $match[1], (int) $match[2]];
            if ($order === 'auto' && $a <= 12 && $b <= 12 && $a !== $b) {
                throw new RuntimeException("Ambiguous {$label} \"{$value}\": choose day-first or month-first in Mapping.");
            }
            $dayFirst = $order === 'dmy' || ($order === 'auto' && $a > 12);
            [$day, $month] = $dayFirst ? [$a, $b] : [$b, $a];
            $year = (int) $match[3];
            if (strlen($match[3]) === 2) {
                $year += $year <= 69 ? 2000 : 1900;
            }
            if (! checkdate($month, $day, $year)) {
                throw new RuntimeException("Invalid {$label}: {$value}.");
            }
            $value = sprintf('%04d-%02d-%02d', $year, $month, $day).$match[4];
        }
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $value, $match)
            || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw new RuntimeException("Invalid {$label}: {$value}. Use ISO dates or select a numeric date order.");
        }
        $date = Carbon::parse($value, 'Asia/Kolkata');
        if (preg_match('/[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $value, $time)
            && ((int) $time[1] > 23 || (int) $time[2] > 59 || (int) ($time[3] ?? 0) > 59)) {
            throw new RuntimeException("Invalid time in {$label}: {$value}.");
        }

        return $date->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /**
     * A clock value out of a follow-up time column.
     *
     * Accepts "10:00", "2:30 PM"/"2:30PM", 24-hour "14:30", a time alone or
     * embedded in a datetime string (Excel turns a time-only cell into
     * "1899-12-31 10:00:00"), and Excel's numeric fraction of a day. Returns
     * null when the value is not a clock time at all, so the row can be
     * flagged and given the fallback time instead.
     */
    private function timeOnly(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $value, $match)) {
            $clockHour = (int) $match[1];
            $minute = (int) $match[2];
            if ($clockHour < 1 || $clockHour > 12 || $minute > 59) {
                return null;
            }
            $hour = $clockHour % 12;
            if (strcasecmp($match[3], 'PM') === 0) {
                $hour += 12;
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }
        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 1) {
            return Date::excelToDateTimeObject((float) $value)->format('H:i');
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $match)) {
            $hour = (int) $match[1];
            $minute = (int) $match[2];
            $second = (int) ($match[3] ?? 0);

            return $hour <= 23 && $minute <= 59 && $second <= 59 ? sprintf('%02d:%02d', $hour, $minute) : null;
        }

        return null;
    }

    /**
     * Resolves the exact follow-up date, time and datetime for every create-row.
     *
     * time_mode = uploaded: a row's own file time wins, otherwise fallback_time.
     * time_mode = manual: every row gets the same fixed time (the legacy behaviour).
     * time_mode = auto: every date gets its own evenly spaced window from
     * 9:00 AM to 5:00 PM (Asia/Kolkata), in source-row order. Today never
     * schedules in the past. The resolved values are persisted as-is and are not
     * recalculated during the actual import.
     */
    public function schedule(array $rows, array $settings): array
    {
        $mode = $settings['time_mode'] ?? (($settings['time_source'] ?? 'fixed') === 'uploaded' ? 'uploaded' : 'manual');
        $start = Carbon::parse($settings['mode'] === 'today' ? now('Asia/Kolkata')->toDateString() : $settings['start_date'], 'Asia/Kolkata')->startOfDay();
        $end = $settings['mode'] === 'spread' ? Carbon::parse($settings['end_date'], 'Asia/Kolkata')->startOfDay() : $start->copy();
        $days = (int) $start->diffInDays($end) + 1;
        $filers = array_values(array_filter($rows, fn (array $r): bool => $r['outcome'] === 'create' && ! CrmTaxonomy::isTerminal($r['attributes']['stage'])));
        $auto = $mode === 'auto' ? $this->scheduleAuto($filers, $start, $days) : null;
        $withOwnDate = count(array_filter($filers, fn (array $r): bool => ! empty($r['file_follow_up_date'])));
        $count = count($filers) - $withOwnDate;
        $base = intdiv($count, $days);
        $remainder = $count % $days;
        $dates = [];
        for ($day = 0; $day < $days; $day++) {
            for ($i = 0; $i < $base + ($day < $remainder ? 1 : 0); $i++) {
                $dates[] = $start->copy()->addDays($day)->toDateString();
            }
        }
        $index = 0;
        $autoIndex = 0;
        foreach ($rows as &$row) {
            if ($row['outcome'] !== 'create' || CrmTaxonomy::isTerminal($row['attributes']['stage'])) {
                $row['follow_up_at'] = null;
                $row['follow_up_date'] = null;
                $row['follow_up_time'] = null;
                $row['follow_up_type'] = null;

                continue;
            }
            if ($auto !== null) {
                $slot = $auto[$autoIndex++];
                $row['follow_up_date'] = $slot['date'];
                $row['follow_up_time'] = $slot['time'];
                $row['follow_up_at'] = $slot['follow_up_at'];
                $row['follow_up_type'] = $settings['follow_up_type'];

                continue;
            }
            $row['follow_up_date'] = ! empty($row['file_follow_up_date']) ? $row['file_follow_up_date'] : $dates[$index++];
            $time = $mode === 'uploaded'
                ? ($row['file_follow_up_time'] ?: ($settings['fallback_time'] ?? ($settings['time'] ?? '09:00')))
                : ($settings['time'] ?? '09:00');
            $row['follow_up_time'] = $time;
            $row['follow_up_at'] = Carbon::parse($row['follow_up_date'], 'Asia/Kolkata')->setTimeFromTimeString($time)->toIso8601String();
            $row['follow_up_type'] = $settings['follow_up_type'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Deterministic auto scheduling within the 9:00 AM–5:00 PM working window.
     *
     * Slots are spread evenly over 480 minutes per date in source-row order:
     * 2 rows give 09:00 and 17:00, 3 rows give 09:00 / 13:00 / 17:00, 9 rows
     * give one slot every hour. Rows carrying their own follow-up date are
     * scheduled against that date; today's first slot is the next whole minute
     * after now and never lands in the past.
     *
     * @param  list<array>  $filers
     * @return list<array{date: string, time: string, follow_up_at: string}>
     */
    private function scheduleAuto(array $filers, Carbon $start, int $days): array
    {
        $withOwnDate = count(array_filter($filers, fn (array $r): bool => ! empty($r['file_follow_up_date'])));
        $count = count($filers) - $withOwnDate;
        $base = intdiv($count, $days);
        $remainder = $count % $days;
        $bucket = [];
        for ($day = 0; $day < $days; $day++) {
            for ($i = 0; $i < $base + ($day < $remainder ? 1 : 0); $i++) {
                $bucket[] = $start->copy()->addDays($day)->toDateString();
            }
        }
        $bucketIndex = 0;
        $buckets = [];
        foreach ($filers as $position => $row) {
            $date = ! empty($row['file_follow_up_date']) ? $row['file_follow_up_date'] : $bucket[$bucketIndex++];
            $buckets[$date][] = $position;
        }
        $windowStart = 9 * 60;
        $windowEnd = 17 * 60;
        $today = now('Asia/Kolkata')->toDateString();
        $schedule = array_fill(0, count($filers), null);
        foreach ($buckets as $date => $positions) {
            $rowsOnDate = count($positions);
            $startMinute = $windowStart;
            if ($date === $today) {
                $next = now('Asia/Kolkata')->addMinute()->startOfMinute();
                $startMinute = max($windowStart, (int) $next->format('G') * 60 + (int) $next->format('i'));
                if ($startMinute >= $windowEnd) {
                    throw ValidationException::withMessages(['defaults.mode' => 'Auto scheduling for today has no working time left (9:00 AM–5:00 PM Asia/Kolkata). Choose a later date or a manual follow-up time.']);
                }
            }
            foreach (array_values($positions) as $slotIndex => $position) {
                $minute = $rowsOnDate === 1
                    ? $startMinute
                    : $startMinute + (int) round($slotIndex * (($windowEnd - $startMinute) / ($rowsOnDate - 1)));
                $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
                $schedule[$position] = [
                    'date' => $date,
                    'time' => $time,
                    'follow_up_at' => Carbon::parse($date, 'Asia/Kolkata')->setTimeFromTimeString($time)->toIso8601String(),
                ];
            }
        }

        return $schedule;
    }
}
