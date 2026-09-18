<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move a lead's person to a different project — see
 * LeadFollowUpService::transferToProject(). Deliberately not scoped to the
 * current user's own project(s): the whole point of this action is offering
 * a project the transferring salesperson may not normally touch.
 */
class LeadTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transfer', $this->route('lead'));
    }

    public function rules(): array
    {
        /** @var Lead $lead */
        $lead = $this->route('lead');

        return [
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where('is_active', true),
                function (string $attribute, mixed $value, \Closure $fail) use ($lead) {
                    if ($lead->isTerminal()) {
                        $fail('This lead is already closed and cannot be transferred.');

                        return;
                    }

                    if ((int) $value === (int) $lead->project_id) {
                        $fail('This lead is already on that project.');

                        return;
                    }

                    if (! $lead->assigned_to) {
                        $fail('This lead has no owner to carry across, so it cannot be transferred.');

                        return;
                    }

                    // the same clash the lead form itself checks — see
                    // LeadRequest — because the unique index is on
                    // (mobile_number, project_id) and this person may
                    // already have a lead of their own on the target project
                    $clash = Lead::withTrashed()
                        ->where('mobile_number', $lead->mobile_number)
                        ->where('project_id', $value)
                        ->first();

                    if ($clash) {
                        $fail($clash->trashed()
                            ? 'This person already has a deleted lead on that project. Restore it instead of transferring another.'
                            : 'This person already has a lead on that project — there is nothing to transfer.');
                    }
                },
            ],
            'note' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.exists' => 'Choose an active project.',
            'note.required' => 'Say why this lead is moving projects.',
        ];
    }
}
