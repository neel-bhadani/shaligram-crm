<?php

namespace App\Http\Requests;

use App\Models\Integration;
use App\Models\LeadFormRoute;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The Facebook settings form.
 *
 * Two things here are not ordinary validation. The token fields are `nullable`
 * even though the integration cannot work without them, because the form never
 * sends the stored values back — an empty token field means "leave it alone",
 * not "clear it", and only `changedSecrets()` below can tell those apart. And
 * `is_active` is checked against readiness in withValidator(), because
 * switching on an integration with no token would leave the card saying
 * Connected while every delivery failed.
 */
class IntegrationSettingsRequest extends FormRequest
{
    /**
     * Route-level `role:admin` has already run. Repeating it here would be a
     * second answer to a question already settled, and the two could drift.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // never required: blank means "keep the stored one"
            'page_access_token' => ['nullable', 'string', 'max:1000'],
            'app_secret' => ['nullable', 'string', 'max:255'],

            // required: "Load forms from Facebook" lists this page's forms
            'page_id' => ['required', 'string', 'max:100'],
            'default_project_id' => ['required', 'integer', $this->activeProject()],
            'assign_to_user_id' => ['required', 'integer', $this->activeTelecaller()],

            'is_active' => ['required', 'boolean'],

            // the lead-form routing table; a blank project means "not mapped
            // yet — use the fallback", a blank person means "the default above"
            'forms' => ['array'],
            'forms.*.form_id' => ['required', 'string', 'max:100', 'distinct', $this->plausibleNewFormId()],
            'forms.*.form_name' => ['nullable', 'string', 'max:255'],
            'forms.*.project_id' => ['nullable', 'integer', $this->activeProject()],
            'forms.*.assign_to_user_id' => ['nullable', 'integer', $this->activeTelecaller()],
            'removed_forms' => ['array'],
            'removed_forms.*' => ['string', 'max:100'],
        ];
    }

    /**
     * Only a telecaller, and only an active one.
     *
     * A new lead is `fresh`, and LeadAssignmentService gives a fresh lead to
     * whoever is configured only when they are on the telecaller desk —
     * anyone else is passed over for the first active telecaller. Offering a
     * salesperson here would be offering a choice that is saved and then
     * silently ignored, so the list and this rule both leave them out.
     */
    private function activeTelecaller(): Exists
    {
        return Rule::exists('users', 'id')
            ->where('role', 'telecaller')
            ->where('is_active', true)
            ->whereNull('deleted_at');
    }

    /**
     * A form id the admin typed must look like one. Forms already in the table
     * came from Meta, or passed this check when they were added, and are left
     * alone — see LeadFormRoute::FORM_ID_PATTERN.
     */
    private function plausibleNewFormId(): Closure
    {
        $known = LeadFormRoute::forProvider((string) $this->route('provider'))->pluck('form_id')->all();

        return function (string $attribute, mixed $value, Closure $fail) use ($known) {
            if (! in_array($value, $known, true) && ! preg_match(LeadFormRoute::FORM_ID_PATTERN, (string) $value)) {
                $fail("That doesn't look like a Facebook form ID. Use Load forms from Facebook to pick one.");
            }
        };
    }

    private function activeProject(): Exists
    {
        return Rule::exists('projects', 'id')->where('is_active', true)->whereNull('deleted_at');
    }

    public function messages(): array
    {
        return [
            'default_project_id.required' => 'Choose the project these leads belong to.',
            'default_project_id.exists' => 'Choose an active project.',
            'page_id.required' => 'Enter the ID of the Facebook page the lead forms are on.',
            'assign_to_user_id.required' => 'Choose who these leads should be assigned to.',
            'assign_to_user_id.exists' => 'Choose an active telecaller.',
            'forms.*.form_id.required' => 'Every lead form needs its form ID.',
            'forms.*.form_id.distinct' => 'This form is listed twice.',
            'forms.*.project_id.exists' => 'Choose an active project.',
            'forms.*.assign_to_user_id.exists' => 'Choose an active telecaller.',
        ];
    }

    /**
     * The secrets the admin actually typed, ready to merge.
     *
     * A field left blank is dropped rather than saved, which is what makes the
     * masked display safe: an admin editing the page ID does not have to
     * re-paste a 200-character token to avoid wiping it.
     *
     * @return array<string, string>
     */
    public function changedSecrets(): array
    {
        return collect(Integration::SECRET_KEYS)
            ->mapWithKeys(fn (string $key) => [$key => trim((string) $this->input($key))])
            ->filter(fn (string $value) => $value !== '')
            ->all();
    }

    /**
     * Switching it on is a claim that it will work, so it is checked.
     *
     * The tokens may be arriving in this same request, so readiness is judged
     * against what the row WILL hold once saved, not against what it holds now.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->boolean('is_active')) {
                return;
            }

            $integration = Integration::forProvider($this->route('provider'));
            $integration->mergeSettings($this->changedSecrets() + [
                'default_project_id' => $this->input('default_project_id'),
                'assign_to_user_id' => $this->input('assign_to_user_id'),
            ]);

            if (! $integration->isConfigured()) {
                $validator->errors()->add(
                    'is_active',
                    'Add the page access token and app secret before switching this on.'
                );
            }
        });
    }
}
