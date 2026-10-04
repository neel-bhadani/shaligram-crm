<?php

namespace App\Http\Requests;

use App\Models\MessageTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A message: which 11za template, and what goes into each of its variables.
 *
 * The CRM does not hold the wording — 11za does. What only the CRM can know is
 * that 11za's {{1}} is the lead's first name and {{2}} the project, and that is
 * `placeholder_map`: one CRM field per variable, in 11za's numbering. Without
 * it every send goes out with blank or wrong values, so it is the one thing
 * this form exists to collect.
 *
 * `body` is not accepted at all. A message written before the CRM stopped
 * holding wording keeps it untouched; a new one has none.
 */
class MessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'provider_template_name' => trim((string) $this->input('provider_template_name')),
            'provider_template_language' => trim((string) $this->input('provider_template_language')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['boolean'],
            // exactly as named in 11za — picked from 11za's list when it can
            // be read, typed when it cannot
            'provider_template_name' => ['required', 'string', 'max:255', 'regex:/^\S+$/'],
            // WhatsApp's language code: en, hi, en_US
            'provider_template_language' => ['required', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
            // {{1}}, {{2}}… in order. The same field twice is allowed: a
            // template may well say the customer's name in two places
            'placeholder_map' => ['nullable', 'array', 'max:10'],
            'placeholder_map.*' => ['required', 'string', Rule::in(array_keys(config('automation.whatsapp.placeholders')))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the message a name you will recognise on the Auto-send tab.',
            'provider_template_name.required' => 'Choose the 11za template this message is sent as.',
            'provider_template_name.regex' => 'An 11za template name has no spaces in it. Copy it exactly as it appears in the 11za panel.',
            'provider_template_language.required' => 'Give the template\'s language code — usually "en".',
            'provider_template_language.regex' => 'The language is a code like "en", "hi" or "en_US", as shown in the 11za panel.',
            'placeholder_map.*.required' => 'Say what goes into this variable.',
            'placeholder_map.*.in' => 'That is not something the CRM can fill in. Choose again.',
        ];
    }

    /**
     * What gets stored. 11za's wording is never taken from the browser: it is
     * kept when the 11za template is unchanged, and otherwise left empty until
     * the next read of 11za's list copies it on.
     *
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        $name = $this->input('provider_template_name');
        $language = $this->input('provider_template_language');
        $current = $this->route('template');

        return [
            'name' => trim($this->input('name')),
            'is_active' => $this->boolean('is_active'),
            'provider_template_name' => $name,
            'provider_template_language' => $language,
            'provider_body' => $current instanceof MessageTemplate
                && $current->provider_template_name === $name
                && $current->provider_template_language === $language
                    ? $current->provider_body
                    : null,
            'placeholder_map' => array_values((array) $this->input('placeholder_map')),
        ];
    }
}
