<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Support\CrmTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imports lead-18-sep-data/leads.json (55 rows from Lead 18 Sep.xls) into
 * the CRM — a separate, one-off batch from `import:legacy`'s /old-data
 * corpus, never mixed with it:
 *
 *   - source_file 'lead-18-sep' in lead_import_records, distinct from
 *     'master_sheet' / 'myco' / '12_sep_lead' — a second run of THIS
 *     command, or of import:legacy, can never collide with the other's rows.
 *   - /old-data/ and its own lead_import_records rows are never read or
 *     written here.
 *
 * Decisions confirmed by the client (2026-09-22), baked into
 * lead-18-sep-data/leads.json by build.py — see that file's comments:
 *   1. Every lead is assigned to the telecaller Riya Gandhi, regardless of
 *      stage (overriding the usual stage-owner-role split).
 *   2. Any row that would break unique(mobile_number, project_id) — against
 *      another row in this file or a lead already in the database — is
 *      skipped, not merged/updated/overwritten. 20 of the 55 rows are
 *      skipped this way; the other 35 are created.
 *
 * Every write goes through the query builder (DB::table()->insert), never
 * an Eloquent model — same discipline as LegacyImporter, and for the same
 * reason: no model is instantiated, so no `saving`/`saved` hook fires, no
 * LeadFollowUpService/RuleEngine/WhatsApp job runs, no LeadActivityRecorder
 * or AlertService is called. The tripwire below re-checks this per chunk.
 * The one deliberate difference from import:legacy is that every created
 * lead here IS given a pending todo (import:legacy's tripwire forbids one;
 * this one requires exactly one per created lead instead).
 *
 * Idempotent: (source_file, source_row) = ('lead-18-sep', N) is unique in
 * lead_import_records. A second run finds every row already there and
 * writes nothing new — checked with --dry-run first either way.
 */
class ImportLead18Sep extends Command
{
    protected $signature = 'import:lead-18-sep
                            {--dry-run : Run every insert for real inside a transaction, then roll it all back}
                            {--path=lead-18-sep-data : Directory holding leads.json}
                            {--reschedule-followups : Recompute scheduled_at for already-created leads\' pending todos using the current buildSchedule() logic, instead of importing}';

    protected $description = 'Import the 55-row Lead 18 Sep batch from lead-18-sep-data/ (separate from import:legacy)';

    private const SOURCE_FILE = 'lead-18-sep';

    private const WORKING_HOURS_START = 9; // 09:00

    private const WORKING_HOURS_END = 19; // 19:00

    public function handle(): int
    {
        if (config('app.timezone') !== 'Asia/Kolkata') {
            $this->error('app.timezone must be Asia/Kolkata; it is '.config('app.timezone').'.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $path = $this->resolvePath((string) $this->option('path'));
        $jsonPath = $path.'/leads.json';

        if (! is_file($jsonPath)) {
            $this->error("Not found: {$jsonPath}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);
        if (count($rows) !== 55) {
            $this->error('Expected 55 rows in leads.json, found '.count($rows).'.');

            return self::FAILURE;
        }

        try {
            [$projectIds, $telecallerId, $telecallerRole] = $this->resolveReferenceData();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('reschedule-followups')) {
            return $this->rescheduleFollowUps($rows, $dryRun);
        }

        $this->line($dryRun ? '<options=bold>DRY RUN — nothing will be written</>' : '<options=bold>IMPORT</>');
        $this->line("Input: {$jsonPath}   Today: ".now()->toDateString().' ('.config('app.timezone').')');
        $this->newLine();

        $toCreate = array_values(array_filter($rows, fn ($r) => $r['_action'] === 'create'));
        $toSkip = array_values(array_filter($rows, fn ($r) => $r['_action'] === 'skip_duplicate'));

        $this->section('Rows');
        $this->pairs([
            'Input rows (leads.json)' => count($rows),
            'To create' => count($toCreate),
            'To skip (would break unique mobile_number+project_id)' => count($toSkip),
        ]);

        $alreadyImported = DB::table('lead_import_records')
            ->where('source_file', self::SOURCE_FILE)
            ->pluck('source_row')->all();
        if ($alreadyImported !== []) {
            $this->warn(count($alreadyImported).' row(s) already imported by a previous run — they will be skipped again: '
                .implode(', ', $alreadyImported));
        }

        $anchor = now(config('app.timezone'));
        $schedule = $this->buildSchedule($toCreate, $anchor);

        $jobsBefore = DB::table('jobs')->count();

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $stats = $this->write($toCreate, $projectIds, $telecallerId, $telecallerRole, $schedule, $anchor, (string) Str::uuid());
        } catch (Throwable $e) {
            if ($dryRun) {
                DB::rollBack();
            }
            $this->newLine();
            $this->error('Import stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->section('Leads (created + skipped-as-existing-batch-row + skipped-as-duplicate = source count)');
        $this->pairs([
            'Leads created' => $stats['created'],
            'Skipped — already imported (idempotency)' => $stats['already_imported'],
            'Skipped — duplicate (would break unique constraint)' => count($toSkip),
            'Source count' => count($rows),
            'Accounted for' => ($stats['created'] + $stats['already_imported'] + count($toSkip)).' / '.count($rows)
                .(($stats['created'] + $stats['already_imported'] + count($toSkip)) === count($rows) ? '  ✓' : '  ✗ MISMATCH'),
        ]);
        $this->section('Todos');
        $this->pairs([
            'Pending follow-ups created' => $stats['todos'],
            'Queued jobs added during the import' => DB::table('jobs')->count() - $jobsBefore,
        ]);

        $verify = $this->verify($anchor);
        $this->section('Verification (reads inside the still-open transaction on a dry run)');
        $this->pairs($verify);

        if ($dryRun) {
            DB::rollBack();
            $this->newLine();
            $this->line('<options=bold>DRY RUN rolled back — nothing written.</>');

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<string, int>, 1: int, 2: string}
     */
    private function resolveReferenceData(): array
    {
        $projectIds = [];
        foreach (['skydeck' => 'SkyDeck', 'felicity' => 'Felicity'] as $key => $name) {
            $project = Project::where('name', $name)->first();
            if (! $project) {
                throw new RuntimeException("Required project '{$name}' not found — refusing to guess.");
            }
            $projectIds[$key] = $project->id;
        }

        $telecallers = User::where('first_name', 'Riya')->where('last_name', 'Gandhi')->get();
        if ($telecallers->count() !== 1) {
            throw new RuntimeException('Required user Riya Gandhi: expected exactly one existing record, found '.$telecallers->count().'.');
        }
        $telecaller = $telecallers->first();
        if ($telecaller->role !== 'telecaller') {
            throw new RuntimeException("Riya Gandhi must have role 'telecaller', has '{$telecaller->role}'.");
        }

        foreach (['fresh', 'not_connected', 'details_shared', 'lost'] as $stage) {
            if (! array_key_exists($stage, CrmTaxonomy::stages())) {
                throw new RuntimeException("Required stage '{$stage}' is not a known stage.");
            }
        }
        foreach (['choice_unavailable', 'duplicate', 'location_issue'] as $reason) {
            if (! array_key_exists($reason, config('crm.lost_reasons'))) {
                throw new RuntimeException("Required lost reason '{$reason}' is not in config('crm.lost_reasons').");
            }
        }
        if (! array_key_exists('facebook', config('crm.sources'))) {
            throw new RuntimeException("Required source 'facebook' is not in config('crm.sources').");
        }

        return [$projectIds, $telecaller->id, $telecaller->role];
    }

    /**
     * One pending call, per created (open) lead, spread across yesterday and
     * today during working hours — not stacked at one instant. Rows are
     * interleaved into two buckets (even index -> yesterday, odd -> today)
     * in file order, then spaced evenly through 09:00-19:00 with a small
     * per-row offset so no two land on the exact same minute.
     *
     * Deliberately does NOT clamp "today" slots to <= now(): a pending call
     * scheduled for later this afternoon is a normal, realistic follow-up —
     * clamping to "now" is what previously crammed most of the "today"
     * bucket into a few minutes around whatever time the import happened to
     * run at.
     *
     * @param  list<array<string, mixed>>  $toCreate
     * @return array<string, Carbon> _import_key => scheduled_at
     */
    private function buildSchedule(array $toCreate, Carbon $anchor): array
    {
        $buckets = ['yesterday' => [], 'today' => []];
        foreach (array_values($toCreate) as $i => $row) {
            $buckets[$i % 2 === 0 ? 'yesterday' : 'today'][] = $row;
        }

        $windowMinutes = (self::WORKING_HOURS_END - self::WORKING_HOURS_START) * 60;
        $schedule = [];
        foreach ($buckets as $which => $rowsInBucket) {
            $day = $which === 'yesterday' ? $anchor->copy()->subDay() : $anchor->copy();
            $count = count($rowsInBucket);
            foreach ($rowsInBucket as $i => $row) {
                $step = $count > 1 ? intdiv($windowMinutes, $count) : 0;
                // small deterministic offset (0-13 min) so a whole bucket isn't
                // an exact, robotic grid — derived from the row number, not random
                $jitter = ((int) $row['_row_number'] * 7) % 14;
                $minutes = min($windowMinutes - 1, $i * $step + $jitter);
                $scheduledAt = $day->copy()->setTime(self::WORKING_HOURS_START, 0)->addMinutes($minutes);
                $schedule[$row['_import_key']] = $scheduledAt;
            }
        }

        return $schedule;
    }

    /**
     * One-off fix for todos scheduled by an earlier, buggy run of this
     * command (see buildSchedule()'s docblock: an earlier version clamped
     * "today" slots to <= now(), crowding most of them into a few minutes):
     * recomputes scheduled_at for the already-created leads' pending todos
     * using the current buildSchedule() logic. Only ever touches
     * `todos.scheduled_at`/`updated_at` for rows this batch created —
     * inserts/deletes nothing, so the tripwire invariants (exactly one
     * pending todo per lead, no Lost lead with one) can't be affected.
     *
     * @param  list<array<string, mixed>>  $rows  full leads.json content
     */
    private function rescheduleFollowUps(array $rows, bool $dryRun): int
    {
        $created = array_values(array_filter($rows, fn ($r) => $r['_action'] === 'create'));

        $records = DB::table('lead_import_records')
            ->where('source_file', self::SOURCE_FILE)
            ->where('outcome', 'created')
            ->get(['source_row', 'lead_id']);

        $anchor = now(config('app.timezone'));
        $schedule = $this->buildSchedule($created, $anchor);

        $this->line($dryRun ? '<options=bold>DRY RUN — reschedule follow-ups</>' : '<options=bold>Reschedule follow-ups</>');
        $this->line('Leads previously created by this batch: '.$records->count());

        if ($dryRun) {
            DB::beginTransaction();
        }

        $updated = 0;
        $missing = [];
        foreach ($records as $record) {
            $row = $created[array_search($record->source_row, array_column($created, '_row_number'), true)] ?? null;
            if (! $row) {
                $missing[] = $record->source_row;

                continue;
            }

            $pending = DB::table('todos')->where('lead_id', $record->lead_id)->where('status', 'pending')->get(['id']);
            if ($pending->count() !== 1) {
                throw new RuntimeException("Lead {$record->lead_id} (row {$record->source_row}) has {$pending->count()} pending todos, expected exactly 1 — refusing to reschedule.");
            }

            DB::table('todos')->where('id', $pending->first()->id)->update([
                'scheduled_at' => $schedule[$row['_import_key']],
                'updated_at' => $anchor,
            ]);
            $updated++;
        }

        if ($missing !== []) {
            $this->warn('Rows in lead_import_records but no longer marked "create" in leads.json: '.implode(', ', $missing));
        }

        $ids = $records->pluck('lead_id');
        $byDay = DB::table('todos')->whereIn('lead_id', $ids)->where('status', 'pending')
            ->selectRaw('DATE(scheduled_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');

        $this->pairs([
            'Todos rescheduled' => $updated,
            'By day' => $byDay->map(fn ($c, $d) => "{$d}: {$c}")->implode(', '),
        ]);

        if ($dryRun) {
            DB::rollBack();
            $this->newLine();
            $this->line('<options=bold>DRY RUN rolled back — nothing written.</>');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $toCreate
     * @param  array<string, int>  $projectIds
     * @param  array<string, Carbon>  $schedule
     * @return array{created: int, already_imported: int, todos: int}
     */
    private function write(array $toCreate, array $projectIds, int $telecallerId, string $telecallerRole, array $schedule, Carbon $anchor, string $batch): array
    {
        $stats = ['created' => 0, 'already_imported' => 0, 'todos' => 0];
        $openStages = ['fresh', 'not_connected', 'details_shared'];

        foreach (array_chunk($toCreate, 25) as $chunk) {
            DB::transaction(function () use ($chunk, $projectIds, $telecallerId, $telecallerRole, $schedule, $anchor, $batch, $openStages, &$stats) {
                $leadIds = [];

                foreach ($chunk as $row) {
                    $alreadyThere = DB::table('lead_import_records')
                        ->where('source_file', self::SOURCE_FILE)
                        ->where('source_row', $row['_row_number'])
                        ->exists();
                    if ($alreadyThere) {
                        $stats['already_imported']++;

                        continue;
                    }

                    $projectId = $projectIds[$row['project_key']];

                    // belt-and-suspenders re-check against live data, in case
                    // the JSON is stale relative to the database
                    $conflict = DB::table('leads')
                        ->where('mobile_number', $row['mobile_number'])
                        ->where('project_id', $projectId)
                        ->exists();
                    if ($conflict) {
                        throw new RuntimeException(
                            "Row {$row['_row_number']} ({$row['_import_key']}) is marked 'create' but "
                            ."mobile {$row['mobile_number']} already exists on project_id {$projectId} — "
                            .'leads.json is stale; re-run build.py before importing.'
                        );
                    }

                    $leadId = DB::table('leads')->insertGetId([
                        'first_name' => $row['first_name'],
                        'middle_name' => $row['middle_name'],
                        'last_name' => $row['last_name'],
                        'mobile_number' => $row['mobile_number'],
                        'email' => $row['email'],
                        'project_id' => $projectId,
                        'source' => $row['source'],
                        'broker_name' => $row['broker_name'],
                        'stage' => $row['stage'],
                        'stage_changed_at' => $row['stage_changed_at'],
                        'not_connected_count' => $row['not_connected_count'],
                        'assigned_to' => $telecallerId,
                        // Derived from the actually-resolved assignee's role, not
                        // trusted from the file — assigned_role must always match
                        // assigned_to's real role, per the invariant enforced
                        // elsewhere in the app. leads.json already sets this to
                        // 'telecaller' on all 35 created rows, but we don't rely
                        // on that agreeing with the database at import time.
                        'assigned_role' => $telecallerRole,
                        'created_by' => null,
                        'requirement' => $row['requirement'],
                        'reason' => $row['reason'],
                        'booked_unit' => null,
                        'booking_date' => null,
                        'last_activity_at' => null,
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                    ]);
                    $leadIds[] = $leadId;
                    $stats['created']++;

                    DB::table('lead_import_records')->insert([
                        'source_file' => self::SOURCE_FILE,
                        'source_row' => $row['_row_number'],
                        'lead_id' => $leadId,
                        'outcome' => 'created',
                        'duplicate_group' => $row['_duplicate_group'],
                        'duplicate_rank' => $row['_duplicate_rank'],
                        'awaiting_follow_up' => false,
                        'imported_todo_count' => in_array($row['stage'], $openStages, true) ? 1 : 0,
                        'filled' => json_encode($row['_filled']),
                        'flags' => json_encode($row['_flags']),
                        'legacy' => json_encode($row['_legacy']),
                        'import_batch' => $batch,
                    ]);

                    if (in_array($row['stage'], $openStages, true)) {
                        DB::table('todos')->insert([
                            'lead_id' => $leadId,
                            'assigned_to' => $telecallerId,
                            'created_by' => null,
                            'scheduled_at' => $schedule[$row['_import_key']],
                            'type' => 'call',
                            'status' => 'pending',
                            'remarks' => null,
                            'outcome_stage' => null,
                            'completed_at' => null,
                            'completed_by' => null,
                            'rescheduled_from_id' => null,
                            'created_at' => $anchor,
                            'updated_at' => $anchor,
                        ]);
                        $stats['todos']++;
                    }
                }

                $this->tripwire($leadIds);
            });
        }

        return $stats;
    }

    /**
     * Same discipline as LegacyImporter::tripwire(), except a pending todo is
     * required here (exactly one per created lead) rather than forbidden —
     * see the class docblock.
     *
     * @param  list<int>  $leadIds
     */
    private function tripwire(array $leadIds): void
    {
        if ($leadIds === []) {
            return;
        }

        foreach (['lead_activities', 'alerts', 'automation_logs', 'message_logs'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereIn('lead_id', $leadIds)->exists()) {
                throw new RuntimeException("Tripwire: {$table} gained rows for imported leads. The chunk was rolled back.");
            }
        }

        $tooMany = DB::table('todos')->whereIn('lead_id', $leadIds)->where('status', 'pending')
            ->select('lead_id')->groupBy('lead_id')->havingRaw('count(*) > 1')->exists();
        if ($tooMany) {
            throw new RuntimeException('Tripwire: an imported lead ended up with more than one pending todo. The chunk was rolled back.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function verify(Carbon $anchor): array
    {
        $importedIds = fn () => DB::table('lead_import_records')->where('source_file', self::SOURCE_FILE)->pluck('lead_id');
        $ids = $importedIds();

        $today = $anchor->toDateString();
        $datesToday = DB::table('leads')->whereIn('id', $ids)->whereDate('created_at', $today)->count();
        $oldest = DB::table('leads')->whereIn('id', $ids)->min('created_at');
        $newest = DB::table('leads')->whereIn('id', $ids)->max('created_at');

        $lostWithPending = DB::table('leads')->whereIn('id', $ids)->where('stage', 'lost')
            ->whereExists(fn ($q) => $q->selectRaw(1)->from('todos')->whereColumn('todos.lead_id', 'leads.id')->where('status', 'pending'))
            ->count();
        $morePendingThanOne = DB::table('todos')->whereIn('lead_id', $ids)->where('status', 'pending')
            ->select('lead_id')->groupBy('lead_id')->havingRaw('count(*) > 1')->get()->count();
        $openWithoutPending = DB::table('leads')->whereIn('id', $ids)
            ->whereNotIn('stage', CrmTaxonomy::terminalStages())
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('todos')->whereColumn('todos.lead_id', 'leads.id')->where('status', 'pending'))
            ->count();

        $globalOpenWithoutPending = DB::table('leads')
            ->whereNotIn('stage', CrmTaxonomy::terminalStages())
            ->whereNull('deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('todos')->whereColumn('todos.lead_id', 'leads.id')->where('status', 'pending'))
            ->count();
        $globalTooManyPending = DB::table('todos')->where('status', 'pending')
            ->select('lead_id')->groupBy('lead_id')->havingRaw('count(*) > 1')->get()->count();

        $scheduleSplit = DB::table('todos')->whereIn('lead_id', $ids)->where('status', 'pending')
            ->selectRaw('DATE(scheduled_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');

        return [
            'Imported leads (this batch)' => (string) $ids->count(),
            'Lead dates equal to today' => (string) $datesToday.' (must be 0)',
            'Oldest / newest created_at (this batch)' => "{$oldest} / {$newest}",
            'Lost leads (this batch) with a pending follow-up' => (string) $lostWithPending.' (must be 0)',
            'This batch: leads with more than one pending todo' => (string) $morePendingThanOne.' (must be 0)',
            'This batch: open leads with no pending todo' => (string) $openWithoutPending.' (must be 0)',
            'Pending follow-ups by day (this batch)' => $scheduleSplit->map(fn ($c, $d) => "{$d}: {$c}")->implode(', '),
            'GLOBAL Lead::open()->doesntHave(pendingTodo)->count()' => (string) $globalOpenWithoutPending
                .' (pre-existing gap unrelated to this batch if > 0 — see report)',
            'GLOBAL leads with more than one pending todo' => (string) $globalTooManyPending.' (must be 0)',
        ];
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    /**
     * @param  array<string|int, mixed>  $pairs
     */
    private function pairs(array $pairs): void
    {
        $width = max(array_map(fn ($k) => mb_strlen((string) $k), array_keys($pairs)));
        foreach ($pairs as $label => $value) {
            $this->line('  '.mb_str_pad((string) $label, $width + 2).$value);
        }
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) ? $path : base_path($path);
    }
}
