<?php

namespace App\Services\LeadImport;

use App\Models\LeadImportRecord;
use App\Models\User;
use App\Services\AlertService;
use App\Services\Automation\RuleEngine;
use App\Services\LeadCreationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class LeadImportService
{
    public function __construct(private LeadCreationService $creation, private RuleEngine $rules, private AlertService $alerts) {}

    /** Each chunk commits atomically; row savepoints isolate recoverable failures. */
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
}
