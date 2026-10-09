<?php

namespace App\Services;

use App\Models\Todo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "Leads marked lost between these dates", defined once.
 *
 * The loss-reason report, the Leads page and Export Data all ask that question,
 * and they have to get the same answer to it, so none of them writes the query
 * itself. It is the reports' HISTORY rule — `todos.outcome_stage` with
 * `completed_at` in the window, once per lead — with one step added: a lead
 * lost twice inside the window is counted under the reason of its LATEST loss
 * there, so it lands in exactly one reason's row and the rows add up to the
 * total.
 *
 * The reason is the one on the loss (`todos.lost_reason`), never
 * `leads.reason`, which only says why the lead's most recent loss happened.
 * The current state — how many sit in Lost right now — is still `leads.stage`
 * and `leads.reason`, and is never date-filtered.
 */
class LossEvents
{
    /** The filter value for a loss with no reason on it. Never a config key. */
    public const NO_REASON = 'none';

    public const NO_REASON_LABEL = 'No reason recorded';

    /**
     * Each lead's latest loss inside the window: one row per lead, carrying
     * `id`, `lead_id`, `lost_reason` (null for a blank one too) and
     * `completed_by`.
     *
     * Latest is completed_at, then id — the order the backfill migration used
     * to decide which loss a lead's reason belonged to.
     */
    public function latest(Carbon $from, Carbon $to): QueryBuilder
    {
        $ranked = DB::table('todos')
            ->where('outcome_stage', 'lost')
            ->whereBetween('completed_at', [$from, $to])
            ->select(['id', 'lead_id', 'completed_by'])
            ->selectRaw("nullif(lost_reason, '') as lost_reason")
            ->selectRaw('row_number() over (partition by lead_id order by completed_at desc, id desc) as rn');

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->select(['id', 'lead_id', 'lost_reason', 'completed_by']);
    }

    /**
     * Narrow a leads query to the leads marked lost in the window, and expose
     * the reason of that loss as `loss.lost_reason`.
     *
     * An inner join is the population filter: a lead with no loss in the
     * window has no row to join. It is NOT narrowed by `leads.stage` — a lead
     * lost in the window and reopened since was still lost in the window, and
     * the report counts it. The joined table carries only `lead_id` and
     * `lost_reason`, so visibleTo()'s unqualified columns stay unambiguous.
     */
    public function narrowLeads(Builder $leads, Carbon $from, Carbon $to, ?string $reason): Builder
    {
        $events = $this->latest($from, $to)->select(['lead_id', 'lost_reason']);

        return $leads
            ->joinSub($events, 'loss', 'loss.lead_id', '=', 'leads.id')
            ->tap(fn ($q) => $this->whereReason($q, 'loss.lost_reason', $reason));
    }

    /**
     * One reason, or the no-reason bucket, on any reason column. Null is no
     * filter. A blank string is the same gap as null and is matched with it.
     */
    public function whereReason($query, string $column, ?string $reason)
    {
        return match ($reason) {
            null => $query,
            self::NO_REASON => $query->where(fn ($q) => $q->whereNull($column)->orWhere($column, '')),
            default => $query->where($column, $reason),
        };
    }

    /**
     * The rules for the two loss filters the Leads page and Export Data share.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(string $presence = 'sometimes'): array
    {
        return [
            'date_basis' => [$presence, 'string', 'in:created,lost'],
            'reason' => [$presence, 'string', Rule::in($this->reasonKeys())],
        ];
    }

    /**
     * A loss filter only means anything while the stage filter is Lost, so a
     * stage change takes both away with it — a hidden filter can never narrow
     * a list or an export. `created` is the default, so it is not stored.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function normalise(array $filters): array
    {
        if (($filters['stage'] ?? null) !== 'lost') {
            unset($filters['date_basis'], $filters['reason']);
        }

        if (($filters['date_basis'] ?? null) !== 'lost') {
            unset($filters['date_basis']);
        }

        return $filters;
    }

    /**
     * Whether a filter set asks for "marked lost between these dates": the
     * stage is Lost, the dates are set to apply to the loss, and there are
     * dates.
     *
     * @param  array<string, mixed>  $filters  normalised
     */
    public function byLossDate(array $filters, ?Carbon $from): bool
    {
        return ($filters['date_basis'] ?? null) === 'lost' && $from !== null;
    }

    /** @return list<string> every configured reason, then the no-reason bucket */
    public function reasonKeys(): array
    {
        return [...array_keys((array) config('crm.lost_reasons')), self::NO_REASON];
    }

    /** @return array<string, string> key => label, the no-reason bucket last */
    public function reasonOptions(): array
    {
        return (array) config('crm.lost_reasons') + [self::NO_REASON => self::NO_REASON_LABEL];
    }

    public function reasonLabel(?string $reason): string
    {
        return $reason === null || $reason === '' || $reason === self::NO_REASON
            ? self::NO_REASON_LABEL
            : (string) config("crm.lost_reasons.$reason", $reason);
    }

    /**
     * When per-person attribution became trustworthy: the first follow-up
     * completed in the CRM rather than brought in by `import:legacy`.
     *
     * The importer wrote each imported loss's `completed_by` as the lead's
     * owner on the sheet, so before this date a name says who held the lead,
     * not who lost it. Null while nothing has been logged in the CRM at all.
     */
    public function attributionBegan(): ?Carbon
    {
        $first = Todo::query()
            ->whereNotNull('completed_at')
            ->whereNotExists(fn ($q) => $q->from('todo_import_records')->whereColumn('todo_import_records.todo_id', 'todos.id'))
            ->min('completed_at');

        return $first ? Carbon::parse($first) : null;
    }
}
