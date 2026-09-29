<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Send WhatsApp" on the lead view: a Meta template, or free text — one or the
 * other. Whether free text is allowed right now is not a validation question;
 * it depends on the 24-hour window and is answered by WhatsAppSender.
 */
class LeadWhatsAppSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('lead'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'whatsapp_template_id' => ['nullable', 'required_without:text', 'integer', 'exists:whatsapp_templates,id'],
            'text' => ['nullable', 'required_without:whatsapp_template_id', 'prohibits:whatsapp_template_id', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'whatsapp_template_id.required_without' => 'Choose a template, or write a message.',
            'text.required_without' => 'Choose a template, or write a message.',
        ];
    }
}
