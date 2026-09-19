<?php

namespace App\Http\Requests\Concerns;

use App\Models\Lead;
use Closure;

/**
 * The one clash a lead's project may not be switched into — LeadRequest's own
 * uniqueness check, on the way in, is mobile_number + project_id, trashed
 * rows included, because the plain unique index counts them too. A null
 * mobile_number clashes with nothing: NULL is never equal to NULL in the
 * index — see make_mobile_number_nullable_on_leads_table.
 *
 * Shared by LeadSwitchProjectRequest (the standalone Follow-up page action)
 * and CompleteTodoRequest (a project switch made in the same action as
 * completing a call) — the same rule, not a second copy of it.
 */
trait ValidatesProjectSwitch
{
    protected function projectSwitchClashRule(Lead $lead): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($lead) {
            if ($lead->mobile_number === null) {
                return;
            }

            $clash = Lead::withTrashed()
                ->where('mobile_number', $lead->mobile_number)
                ->where('project_id', $value)
                ->where('id', '!=', $lead->id)
                ->exists();

            if ($clash) {
                $fail('This number already has a lead on that project.');
            }
        };
    }
}
