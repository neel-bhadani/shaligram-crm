<?php

namespace App\Http\Requests;

use App\Models\WhatsAppTemplate;
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
 * `approval_status` and `meta_template_name` are not here either. They belong
 * to Meta's side of the template and are only meaningful once somebody has
 * submitted one, which nothing can do until there are credentials.
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
            // the Meta template this message is sent as by API; linking one
            // that is not approved is allowed, sending by it is not
            'whatsapp_template_id' => ['nullable', 'integer', Rule::exists('whatsapp_templates', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Write the message. Use the placeholder buttons to drop in the customer\'s name.',
            'body.max' => 'WhatsApp templates cannot be longer than 1024 characters.',
        ];
    }

    /**
     * What gets stored, including the numbering Meta will ask for.
     *
     * The map is worked out here, at save time, and not at submission time.
     * Meta wants {{1}} and {{2}}; only this application knows that {{1}} was
     * meant to be the customer's first name. Deriving it later would mean
     * re-deriving it for every template already written and hoping the order
     * had not changed in the meantime.
     *
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        $body = trim($this->input('body'));

        return [
            'name' => trim($this->input('name')),
            'category' => $this->input('category'),
            'body' => $body,
            'placeholder_map' => app(TemplateRenderer::class)->mapFor($body),
            'is_active' => $this->boolean('is_active'),
        ] + $this->metaLink();
    }

    /**
     * The link, with Meta's name and status copied beside it so everything
     * reading `approval_status` agrees with the last sync.
     *
     * @return array{whatsapp_template_id: ?int, meta_template_name: ?string, approval_status: string}
     */
    private function metaLink(): array
    {
        $meta = WhatsAppTemplate::find($this->input('whatsapp_template_id'));

        return [
            'whatsapp_template_id' => $meta?->id,
            'meta_template_name' => $meta?->name,
            'approval_status' => $meta ? strtolower($meta->status) : 'draft',
        ];
    }
}
