<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesTaxonomy;
use App\Models\Project;
use App\Support\CrmTaxonomy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move a lead to a specific person by hand, optionally onto a different
 * stage in the same action — see LeadFollowUpService::reassignTo(). No round
 * robin turn is taken either way: the person is the one the form asked for,
 * not whoever the round robin would have picked.
 */
class LeadReassignRequest extends FormRequest
{
    use ValidatesTaxonomy;

    public function authorize(): bool
    {
        return $this->user()->can('reassign', $this->route('lead'));
    }

    public function rules(): array
    {
        $lead = $this->route('lead');

        // the stage this reassignment would leave the lead at — the one
        // submitted, or, when the form left it untouched, the one it is
        // already in
        $stage = $this->input('stage') ?: $lead->stage;

        // the role a lead at THAT stage is worked by — falling back to
        // whoever already holds it when the stage is terminal, so a booked or
        // lost lead can still be handed to another person of the same role
        $role = CrmTaxonomy::ownerRoleFor($stage) ?? $lead->assigned_role;

        return [
            // the same "active, or the lead's own value" rule LeadRequest and
            // CompleteTodoRequest already use for a stage — see
            // ValidatesTaxonomy for why an exists-only rule would be wrong
            'stage' => ['nullable', 'string', $this->activeStageRule($lead->stage)],
            'assigned_to' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', $role)->where('is_active', true)),
                function (string $attribute, mixed $value, \Closure $fail) use ($lead, $role, $stage) {
                    if ((int) $value === (int) $lead->assigned_to && $stage === $lead->stage) {
                        $fail('This lead is already assigned to that person at that stage.');

                        return;
                    }

                    /*
                     | The project boundary. Only a salesperson target is tied
                     | to a project at all — telecallers are a single
                     | company-wide desk, see LeadAssignmentService — and
                     | admin picks from anybody, same as LeadPolicy::view().
                     */
                    if ($role !== 'salesperson' || $this->user()->can_('see_all_leads')) {
                        return;
                    }

                    $onProject = Project::whereKey($lead->project_id)
                        ->whereHas('salespeople', fn ($q) => $q->whereKey($value))
                        ->exists();

                    if (! $onProject) {
                        $fail('That person is not assigned to this lead\'s project.');
                    }
                },
            ],
        ];
    }
}
