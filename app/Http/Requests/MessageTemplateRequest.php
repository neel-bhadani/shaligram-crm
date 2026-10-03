<?php

namespace App\Http\Requests;

use App\Services\WhatsApp\TemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A WhatsApp template.
 *
 * The interesting part is what this does NOT refuse. An unknown placeholder —
 * {custamer_name} — is a warning in the editor, not a validation error: a body
 * may legitimately contain a brace, and blocking a save on a false positive
 * would leave the admin with no way to write the message they meant. The editor
 * shows the misspelling beside a live preview where the gap is obvious.
 *
 * The 11za template name is typed, not picked: templates live in 11za's panel
 * and there is nothing to list them from. A name that does not exist there is
 * found out when 11za answers the first send, and that answer is kept on the
 * message.
 */
class MessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(config('automation.whatsapp.categories')))],
            /*
             | 1024 is the practical ceiling for a WhatsApp template body. The
             | limit is here rather than only in the column so the admin is told
             | while they are writing, instead of after Meta rejects it.
             */
            'body' => ['required', 'string', 'max:1024'],
            'is_active' => ['boolean'],
            // the template this message is sent as by API, exactly as it is
            // named in 11za's panel; empty means click-to-send only
            'provider_template_name' => ['nullable', 'string', 'max:255', 'regex:/^\S+$/'],
            // WhatsApp's language code: en, hi, en_US
            'provider_template_language' => ['nullable', 'required_with:provider_template_name', 'string', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Write the message. Use the placeholder buttons to drop in the customer\'s name.',
            'body.max' => 'WhatsApp templates cannot be longer than 1024 characters.',
            'provider_template_name.regex' => 'An 11za template name has no spaces in it. Copy it exactly as it appears in the 11za panel.',
            'provider_template_language.required_with' => 'Give the template\'s language code too — usually "en".',
            'provider_template_language.regex' => 'The language is a code like "en", "hi" or "en_US", as shown in the 11za panel.',
        ];
    }

    /**
     * What gets stored, including the numbering the WhatsApp template uses.
     *
     * The map is worked out here, at save time, and not at send time. The
     * template wants {{1}} and {{2}}; only this application knows that {{1}}
     * was meant to be the customer's first name. Deriving it later would mean
     * re-deriving it for every template already written and hoping the order
     * had not changed in the meantime.
     *
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        $body = trim($this->input('body'));
        $providerName = trim((string) $this->input('provider_template_name')) ?: null;

        return [
            'name' => trim($this->input('name')),
            'category' => $this->input('category'),
            'body' => $body,
            'placeholder_map' => app(TemplateRenderer::class)->mapFor($body),
            'is_active' => $this->boolean('is_active'),
            'provider_template_name' => $providerName,
            'provider_template_language' => $providerName ? $this->input('provider_template_language') : null,
        ];
    }
}
