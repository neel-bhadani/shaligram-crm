<?php

namespace App\Services\LeadImport;

use App\Models\Lead;
use App\Models\LeadImportRecord;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Automation\RuleEngine;
use App\Services\LeadCreationService;
use App\Services\LeadFollowUpService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class LeadImportService
{
    /** The history remark written on every lead an import resets to Fresh. */
    public const RESET_REMARK = 'Stage reset to Fresh by bulk import.';

    public function __construct(private LeadCreationService $creation, private LeadFollowUpService $followUps, private RuleEngine $rules, private AlertService $alerts) {}

    /**
     * Each chunk commits atomically; row savepoints isolate recoverable failures.
     *
     * `create` rows become new leads; `update` rows reset the existing leads
     * on that mobile and project back to Fresh.
     */
    public function import(array $rows, User $creator, string $batch, string $importTime): array
    {
        return $this->rules->withoutRules(fn (): array => $this->alerts->withoutAlerts(fn (): array => DB::transaction(function () use ($rows, $creator, $batch, $importTime): array {
            $results = [];
            $records = LeadImportRecord::whereIn('source_file', array_column($rows, 'import_key'))->get()->keyBy('source_file');
            $holders = User::active()->whereIn('id', array_filter(array_column($rows, 'assigned_to')))->get()->keyBy('id');
            foreach ($rows as $row) {
                if ($row['outcome'] === 'excluded') {
                    $results[] = $row + ['result' => 'excluded', 'reason' => 'Excluded by user.'];

                    continue;
                } elseif ($row['outcome'] === 'update') {
                    try {
                        $results[] = DB::transaction(fn (): array => $this->resetToFresh($row, $batch, $records->get($row['import_key'])));
                    } catch (Throwable $e) {
                        report($e);
                        $results[] = $row + ['result' => 'failed', 'reason' => 'Existing lead could not be reset to Fresh. Its stage and follow-up were left unchanged.'];
                    }

                    continue;
                } elseif ($row['outcome'] !== 'create') {
                    $results[] = $row + ['result' => 'skipped', 'reason' => implode('; ', $row['errors'])];

                    continue;
                }
                try {
                    $results[] = DB::transaction(function () use ($row, $creator, $batch, $importTime, $records, $holders): array {
                        $record = $records->get($row['import_key']);
                        if ($record && $record->import_batch === $batch) {
                            return $row + ['result' => 'created', 'lead_id' => $record->lead_id, 'followUps' => $record->imported_todo_count];
                        }
                        if ($record) {
                            return $row + ['result' => 'skipped', 'reason' => 'Already imported.'];
                        }
                        $holder = $holders->get($row['assigned_to']) ?? throw new \RuntimeException('Assigned user is no longer active.');
                        $attributes = $row['attributes'];
                        $attributes['created_at'] ??= $importTime;
                        $lead = $this->creation->create($attributes, $creator, $holder,
                            $row['follow_up_at'] ? Carbon::parse($row['follow_up_at'])->setTimezone(config('app.timezone')) : null,
                            $row['follow_up_type'], null);
                        if ($lead->assigned_to !== $row['assigned_to'] || (! $lead->isTerminal() && $lead->todos()->where('status', 'pending')->count() !== 1)) {
                            throw new \RuntimeException('Assignment or follow-up changed. Regenerate the final preview.');
                        }
                        $followUps = $lead->isTerminal() ? 0 : 1;
                        LeadImportRecord::create([
                            'source_file' => $row['import_key'], 'source_row' => $row['row'], 'lead_id' => $lead->id,
                            'outcome' => 'created', 'awaiting_follow_up' => false, 'imported_todo_count' => $followUps,
                            'filled' => array_keys($attributes), 'flags' => [], 'legacy' => $row['raw'], 'import_batch' => $batch,
                        ]);

                        return $row + ['result' => 'created', 'lead_id' => $lead->id, 'followUps' => $followUps];
                    });
                } catch (UniqueConstraintViolationException) {
                    $results[] = $row + ['result' => 'skipped', 'reason' => 'Mobile already exists for this project, or source row was already imported.'];
                } catch (Throwable $e) {
                    report($e);
                    $results[] = $row + ['result' => 'failed', 'reason' => 'Row could not be saved. No lead or follow-up was retained for this row.'];
                }
            }

            return $results;
        })));
    }

    /**
     * Move every live lead on this row's mobile and project that is not yet
     * Fresh back to Fresh, through the same stage-change path as the lead form:
     * history row, activity timeline, and the row's planned follow-up replacing
     * whatever was pending. The owner is left as it is.
     *
     * The row is recorded like a created one (outcome `reset`), so importing
     * the same file again finds it already imported and changes nothing.
     *
     * @return array<string, mixed>
     */
    private function resetToFresh(array $row, string $batch, ?LeadImportRecord $record): array
    {
        if ($record && $record->import_batch === $batch) {
            return $row + ['result' => 'updated', 'lead_id' => $record->lead_id, 'followUps' => $record->imported_todo_count];
        }
        if ($record) {
            return $row + ['result' => 'skipped', 'reason' => 'Already imported.'];
        }
        $leads = Lead::where('mobile_number', $row['attributes']['mobile_number'])
            ->where('project_id', $row['attributes']['project_id'])
            ->where('stage', '!=', 'fresh')
            ->orderBy('id')
            ->get();
        if ($leads->isEmpty()) {
            return $row + ['result' => 'skipped', 'reason' => 'Lead already exists and is already at Fresh.'];
        }
        $when = $row['follow_up_at'] ? Carbon::parse($row['follow_up_at'])->setTimezone(config('app.timezone')) : null;
        foreach ($leads as $lead) {
            $this->followUps->changeStage($lead, 'fresh', [], $when, $row['follow_up_type'], null, self::RESET_REMARK);
        }
        $followUps = $when ? $leads->count() : 0;
        LeadImportRecord::create([
            'source_file' => $row['import_key'], 'source_row' => $row['row'], 'lead_id' => $leads->first()->id,
            'outcome' => 'reset', 'awaiting_follow_up' => false, 'imported_todo_count' => $followUps,
            'filled' => [], 'flags' => [], 'legacy' => $row['raw'], 'import_batch' => $batch,
        ]);

        return $row + [
            'result' => 'updated', 'lead_id' => $leads->first()->id, 'lead_ids' => $leads->modelKeys(),
            'followUps' => $followUps,
        ];
    }
}
