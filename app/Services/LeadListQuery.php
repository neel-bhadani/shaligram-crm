<?php

namespace App\Services;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Models\Lead;
use App\Models\User;
use App\Support\CrmTaxonomy;
use App\Support\RecordSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The leads list, as a query. One method, so the list on screen and anything
 * that acts on "everything matching this filter" — the bulk WhatsApp send —
 * are the same query, not two that have to agree.
 *
 * visibleTo() is inside it, and the model's soft-delete scope comes along,
 * so a deleted lead or one this user cannot see is never in it.
 */
class LeadListQuery
{
    use ResolvesDateRange;

    /**
     * The filters the leads page owns, as validation rules. The page and the
     * bulk send resolve the same session state with the same rules.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:100'],
            // every key, not only the active ones: filtering a list is
            // reading, and a lead filed under a retired stage is still a
            // lead somebody may want to narrow to
            'stage' => ['sometimes', 'string', Rule::in(CrmTaxonomy::stageKeys())],
            'project_id' => ['sometimes', 'integer', 'min:1'],
            'source' => ['sometimes', 'string', Rule::in(CrmTaxonomy::sourceKeys())],
            'channel_partner_id' => ['sometimes', 'integer', 'min:1'],
            'assigned_to' => ['sometimes', 'integer', 'min:1'],
        ] + $this->dateRangeRules();
    }

    /**
     * What a validator cannot say about the custom date pair.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function normalise(array $state): array
    {
        return $this->sanitiseDates($state);
    }

    /**
     * Every filter applied except the stage. The stage chips count over this,
     * so selecting "Lost" does not make the other chips read zero.
     *
     * @param  array<string, mixed>  $filters  as resolved by the leads page
     */
    public function withoutStage(User $user, array $filters): Builder
    {
        [$from, $to] = $this->dateWindow($filters);

        return Lead::visibleTo($user)
            ->tap(fn (Builder $q) => RecordSearch::apply($q, $filters['search'] ?? null,
                ['first_name', 'middle_name', 'last_name', 'mobile_number', 'email'],
                ['first_name', 'middle_name', 'last_name'], ['mobile_number']))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            // where the leads report's "By channel partner" rows drill through to
            ->when($filters['channel_partner_id'] ?? null, fn ($q, $v) => $q->where('channel_partner_id', $v))
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('assigned_to', $v))
            // one clause, both bounds, on real datetimes rather than DATE() —
            // see dateWindow() for why the boundaries are built where they are
            ->when($from, fn ($q) => $q->whereBetween('created_at', [$from, $to]));
    }

    /**
     * The list exactly as the page shows it, stage included.
     *
     * @param  array<string, mixed>  $filters
     */
    public function filtered(User $user, array $filters): Builder
    {
        return $this->withoutStage($user, $filters)
            ->when($filters['stage'] ?? null, fn ($q, $v) => $q->where('stage', $v));
    }
}
