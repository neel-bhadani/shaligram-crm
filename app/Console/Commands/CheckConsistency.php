<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Todo;
use Illuminate\Console\Command;
use App\Support\CrmTaxonomy;

/**
 * Audits the invariants the dashboard depends on. Reports only — it never
 * writes, so it is safe to run against production.
 *
 * Note on visibility: every lead query in the *application* is scoped with
 * scopeVisibleTo, because it is answering for a signed-in user. This runs from
 * a console with no user, and an audit that hid rows from the operator would be
 * worse than useless — so it deliberately reads everything. Pass --user=ID to
 * scope it to one person's records instead.
 */
class CheckConsistency extends Command
{
    protected $signature = 'crm:check-consistency
                            {--user= : only audit leads visible to this user id}';

    protected $description = 'Report leads and to-dos that disagree with themselves';

    public function handle(): int
    {
        $leads = Lead::query()
            ->when($this->option('user'), function ($q, $id) {
                $user = \App\Models\User::findOrFail($id);
                $this->line("Scoped to {$user->display_name} ({$user->role})");

                return $q->visibleTo($user);
            })
            ->get(['id', 'first_name', 'last_name', 'mobile_number', 'email', 'stage', 'stage_changed_at']);

        $ids = $leads->pluck('id');

        $this->newLine();
        $this->info("Auditing {$leads->count()} leads.");
        $this->newLine();

        $found = 0;
        $found += $this->stageDisagreesWithHistory($leads, $ids);
        $found += $this->duplicateHistoryRows($ids);
        $found += $this->pendingTodoCount($leads, $ids);
        $found += $this->terminalWithPendingTodo($leads, $ids);
        $found += $this->orphanedTodos();

        $this->newLine();

        $found === 0
            ? $this->info('No inconsistencies found.')
            : $this->warn("$found inconsistencies found. Nothing was changed.");

        return self::SUCCESS;
    }

    /**
     * A lead's stage should be whatever its most recent history row says. The
     * history is completed to-dos, newest by completed_at.
     */
    private function stageDisagreesWithHistory($leads, $ids): int
    {
        $latest = Todo::whereIn('lead_id', $ids)
            ->whereNotNull('outcome_stage')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get(['lead_id', 'outcome_stage', 'completed_at'])
            ->keyBy('lead_id');   // ordered ascending, so the last write wins

        $rows = $leads
            ->filter(fn($l) => isset($latest[$l->id]) && $latest[$l->id]->outcome_stage !== $l->stage)
            ->map(fn($l) => [
                $l->id,
                $l->full_name,
                $l->stage,
                $latest[$l->id]->outcome_stage,
                (string) $latest[$l->id]->completed_at,
            ])->values();

        return $this->report(
            'Leads whose stage disagrees with their latest history row',
            ['lead', 'name', 'leads.stage', 'latest history', 'at'],
            $rows,
            'A lead edited backwards after booking is legitimate; a lead that has never been edited is not.'
        );
    }

    /**
     * More than one history row for the same lead and stage.
     *
     * Only the ones sharing a timestamp are faults. A lead reaching the same
     * stage twice at different moments is ordinary: the retry ladder marks a
     * lead "not connected" up to four times, and a booking can fall through and
     * be re-made. Counting those as inconsistencies would have this command
     * crying wolf on healthy data, which is how a checker stops being read.
     */
    private function duplicateHistoryRows($ids): int
    {
        $repeats = Todo::whereIn('lead_id', $ids)
            ->whereNotNull('outcome_stage')
            ->selectRaw('lead_id, outcome_stage, count(*) as total, count(distinct completed_at) as moments')
            ->groupBy('lead_id', 'outcome_stage')
            ->havingRaw('count(*) > 1')
            ->get();

        $faults = $repeats->filter(fn($r) => $r->moments < $r->total)
            ->map(fn($r) => [$r->lead_id, $r->outcome_stage, $r->total, $r->total - $r->moments])
            ->values();

        $found = $this->report(
            'Duplicate history rows (same lead, stage and timestamp)',
            ['lead', 'stage', 'rows', 'duplicates'],
            $faults,
            'Each of these is one transition written twice.'
        );

        $expected = $repeats->count() - $faults->count();

        if ($expected > 0) {
            $this->line("  <fg=gray>      ($expected leads reached a stage more than once at different times — "
                . 'the retry ladder and re-bookings do that; not counted)</>');
        }

        return $found;
    }

    /**
     * A to-do whose lead is gone. lead_id is NOT NULL behind a cascading key,
     * so the column cannot dangle — but Lead soft deletes, and a deleted lead's
     * completed to-dos are kept as history. Those rows are excluded everywhere
     * on purpose; this is here to say how many there are.
     */
    private function orphanedTodos(): int
    {
        $rows = Todo::whereDoesntHave('lead')
            ->with(['lead' => fn($q) => $q->withTrashed()])
            ->get(['id', 'lead_id', 'status', 'outcome_stage'])
            ->map(fn($t) => [
                $t->id,
                $t->lead_id,
                $t->lead?->full_name ?? '(row gone)',
                $t->status,
                $t->outcome_stage ?? '-',
                (string) $t->lead?->deleted_at,
            ]);

        return $this->report(
            'To-dos whose lead is missing or soft-deleted',
            ['todo', 'lead', 'name', 'status', 'outcome', 'lead deleted at'],
            $rows,
            'Excluded from every card, chart and panel by Todo::hasLead().'
        );
    }

    /**
     * The core rule: an open lead has exactly one pending to-do. None means it
     * has fallen off everyone's list; more than one means duplicate work.
     */
    private function pendingTodoCount($leads, $ids): int
    {
        $pending = Todo::whereIn('lead_id', $ids)
            ->where('status', 'pending')
            ->selectRaw('lead_id, count(*) as total')
            ->groupBy('lead_id')
            ->pluck('total', 'lead_id');

        $terminal = CrmTaxonomy::terminalStages();

        $rows = $leads
            ->reject(fn($l) => in_array($l->stage, $terminal))
            // the one exception: a lead with no phone and no email is given no
            // to-do on arrival, because there is nothing anybody could do
            ->reject(fn($l) => $l->hasNoContactDetails() && ! isset($pending[$l->id]))
            ->map(fn($l) => [$l->id, $l->full_name, $l->stage, (int) ($pending[$l->id] ?? 0)])
            ->filter(fn($r) => $r[3] !== 1)
            ->values();

        return $this->report(
            'Open leads without exactly one pending to-do',
            ['lead', 'name', 'stage', 'pending'],
            $rows,
            'Lead::open()->doesntHave("pendingTodo") must return 0, except leads with no phone and no email.'
        );
    }

    /** A closed lead should not still be asking someone to call it. */
    private function terminalWithPendingTodo($leads, $ids): int
    {
        $pending = Todo::whereIn('lead_id', $ids)
            ->where('status', 'pending')
            ->selectRaw('lead_id, count(*) as total')
            ->groupBy('lead_id')
            ->pluck('total', 'lead_id');

        $terminal = CrmTaxonomy::terminalStages();

        $rows = $leads
            ->filter(fn($l) => in_array($l->stage, $terminal) && ($pending[$l->id] ?? 0) > 0)
            ->map(fn($l) => [$l->id, $l->full_name, $l->stage, (int) $pending[$l->id]])
            ->values();

        return $this->report(
            'Terminal leads that still have a pending to-do',
            ['lead', 'name', 'stage', 'pending'],
            $rows,
            'Booking or losing a lead cancels its pending to-do.'
        );
    }

    /** @return int how many rows the check found */
    private function report(string $title, array $headers, $rows, string $note): int
    {
        $count = count($rows);

        if ($count === 0) {
            $this->line("  <fg=green>OK</>    $title");

            return 0;
        }

        $this->line("  <fg=yellow>FOUND</> $title — $count");
        $this->newLine();
        $this->table($headers, $rows);
        $this->line("  <fg=gray>$note</>");
        $this->newLine();

        return $count;
    }
}
