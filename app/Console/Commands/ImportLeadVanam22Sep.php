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
 * Imports lead-vanam-22-sep-data/leads.json (33 rows from Lead Vanam 22 Sep.xls)
 * into the CRM — a separate, one-off batch from `import:legacy`'s /old-data
 * corpus and from `import:lead-18-sep`:
 *
 *   - source_file 'lead-vanam-22-sep' in lead_import_records, distinct from
 *     'master_sheet' / 'myco' / '12_sep_lead' / 'lead-18-sep' — a second run of
 *     THIS command, or of either earlier one, can never collide with the
 *     other's rows.
 *   - /old-data/ and lead-18-sep-data/ and their own lead_import_records rows
 *     are never read or written here.
 *
 * Decisions follow the lead-18-sep batch the client confirmed (2026-09-22),
 * re-confirmed for this file, baked into leads.json by build.py — see that
 * file's comments:
 *   1. Every lead is assigned to the telecaller Riya Gandhi, regardless of
 *      stage (here the file's every row is Fresh anyway, and its own
 *      sales_person column also says Riya Gandhi — see _legacy).
 *   2. Any row that would break unique(mobile_number, project_id) — against
 *      another row in this file (none) or a lead already in the database — is
 *      skipped, not merged/updated/overwritten. 1 of the 33 rows is skipped
 *      this way (row 28, mobile 9408846346, existing lead 8365); the other 32
 *      are created.
 *
 * Every write goes through the query builder (DB::table()->insert), never
 * an Eloquent model — same discipline as LegacyImporter and ImportLead18Sep,
 * and for the same reason: no model is instantiated, so no `saving`/`saved`
 * hook fires, no LeadFollowUpService/RuleEngine/WhatsApp job runs, no
 * LeadActivityRecorder or AlertService is called. The tripwire below
 * re-checks this per chunk. As with lead-18-sep, every created open lead IS
 * given exactly one pending todo — but here the schedule is read straight
 * from each row's `_follow_up_at` (2026-09-23 10:00:00 Asia/Kolkata, the
 * fallback set by build.py because the Excel carries no time-of-day column),
 * not re-spread by this command.
 *
 * Idempotent: (source_file, source_row) = ('lead-vanam-22-sep', N) is unique
 * in lead_import_records. A second run finds every row already there and
 * writes nothing new — checked with --dry-run first either way.
 */
class ImportLeadVanam22Sep extends Command
{
    protected $signature = 'import:lead-vanam-22-sep
                            {--dry-run : Run every insert for real inside a transaction, then roll it all back}
                            {--path=lead-vanam-22-sep-data : Directory holding leads.json}';

    protected $description = 'Import the 33-row Lead Vanam 22 Sep batch from lead-vanam-22-sep-data/';

    private const SOURCE_FILE = 'lead-vanam-22-sep';

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
        if (count($rows) !== 33) {
            $this->error('Expected 33 rows in leads.json, found '.count($rows).'.');

            return self::FAILURE;
        }

        try {
            [$projectIds, $telecallerId, $telecallerRole] = $this->resolveReferenceData();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
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
        $jobsBefore = DB::table('jobs')->count();

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $stats = $this->write($toCreate, $projectIds, $telecallerId, $telecallerRole, $anchor, (string) Str::uuid());
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
        $project = Project::where('name', 'Vanam')->first();
        if (! $project) {
            throw new RuntimeException("Required project 'Vanam' not found — refusing to guess.");
        }
        $projectIds['vanam'] = $project->id;

        $telecallers = User::where('first_name', 'Riya')->where('last_name', 'Gandhi')->get();
        if ($telecallers->count() !== 1) {
            throw new RuntimeException('Required user Riya Gandhi: expected exactly one existing record, found '.$telecallers->count().'.');
        }
        $telecaller = $telecallers->first();
        if ($telecaller->role !== 'telecaller') {
            throw new RuntimeException("Riya Gandhi must have role 'telecaller', has '{$telecaller->role}'.");
        }

        if (! array_key_exists('fresh', CrmTaxonomy::stages())) {
            throw new RuntimeException("Required stage 'fresh' is not a known stage.");
        }
        if (! array_key_exists('facebook', config('crm.sources'))) {
            throw new RuntimeException("Required source 'facebook' is not in config('crm.sources').");
        }

        return [$projectIds, $telecaller->id, $telecaller->role];
    }

    /**
     * @param  list<array<string, mixed>>  $toCreate
     * @param  array<string, int>  $projectIds
     * @return array{created: int, already_imported: int, todos: int}
     */
    private function write(array $toCreate, array $projectIds, int $telecallerId, string $telecallerRole, Carbon $anchor, string $batch): array
    {
        $stats = ['created' => 0, 'already_imported' => 0, 'todos' => 0];
        $openStages = ['fresh', 'not_connected', 'details_shared'];

        foreach (array_chunk($toCreate, 25) as $chunk) {
            DB::transaction(function () use ($chunk, $projectIds, $telecallerId, $telecallerRole, $anchor, $batch, $openStages, &$stats) {
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
                        // elsewhere in the app.
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
                            'scheduled_at' => $row['_follow_up_at'],
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
     * Same discipline as LegacyImporter::tripwire() and ImportLead18Sep, except
     * a pending todo is required here (exactly one per created open lead).
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
        $ids = DB::table('lead_import_records')->where('source_file', self::SOURCE_FILE)->pluck('lead_id');

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

        $followUpTimes = DB::table('todos')->whereIn('lead_id', $ids)->where('status', 'pending')
            ->selectRaw('TIME(scheduled_at) as t, count(*) as c')->groupBy('t')->orderBy('t')->get();
        $scheduleSplit = DB::table('todos')->whereIn('lead_id', $ids)->where('status', 'pending')
            ->selectRaw('DATE(scheduled_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');

        return [
            'Imported leads (this batch)' => (string) $ids->count(),
            'Lead dates equal to today' => (string) $datesToday.' (file-in dates; 2 rows are dated 2026-09-22)',
            'Oldest / newest created_at (this batch)' => "{$oldest} / {$newest}",
            'Lost leads (this batch) with a pending follow-up' => (string) $lostWithPending.' (must be 0)',
            'This batch: leads with more than one pending todo' => (string) $morePendingThanOne.' (must be 0)',
            'This batch: open leads with no pending todo' => (string) $openWithoutPending.' (must be 0)',
            'Pending follow-ups by day (this batch)' => $scheduleSplit->map(fn ($c, $d) => "{$d}: {$c}")->implode(', '),
            'Pending follow-up times (this batch)' => $followUpTimes->map(fn ($r) => "{$r->t}: {$r->c}")->implode(', '),
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
