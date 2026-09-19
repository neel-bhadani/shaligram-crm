<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectSwitch;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Move a lead to a different project — see
 * LeadFollowUpService::switchProject(). LeadPolicy::switchProject() is the
 * same "can you see it" gate reassign() uses; there is no candidate to check
 * a project boundary against here, since the thing being picked is the
 * project itself.
 */
class LeadSwitchProjectRequest extends FormRequest
{
    use ValidatesProjectSwitch;

    public function authorize(): bool
    {
        return $this->user()->can('switchProject', $this->route('lead'));
    }

    public function rules(): array
    {
        $lead = $this->route('lead');

        return [
            'project_id' => [
                'required',
                'integer',
                'exists:projects,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($lead) {
                    if ((int) $value === (int) $lead->project_id) {
                        $fail('This lead is already on that project.');

                        return;
                    }

                    $this->projectSwitchClashRule($lead)($attribute, $value, $fail);
                },
            ],
        ];
    }
}
