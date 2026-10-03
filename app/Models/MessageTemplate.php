<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp message written once and sent many times.
 *
 * The body carries named placeholders. Rendering it against a lead is
 * App\Services\WhatsApp\TemplateRenderer — this model holds the text, the
 * numbering the WhatsApp template uses, and the 11za template it goes out as.
 */
class MessageTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'placeholder_map' => 'array',
        'is_active' => 'boolean',
    ];

    public function messages()
    {
        return $this->hasMany(MessageLog::class, 'template_id');
    }

    /**
     * What this template costs to send, in words rather than rupees.
     *
     * Deliberately relative and deliberately vague. Meta's per-conversation
     * pricing moves by country and by month, so a number printed here would be
     * wrong within a quarter; the ratio — utility is roughly an eighth of
     * marketing — is what actually changes the admin's mind, and it has been
     * stable for years.
     */
    public function costNote(): string
    {
        return (string) config("automation.whatsapp.categories.{$this->category}.cost_note", '');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** "site_visit (en)" — the 11za template this message goes out as by API. */
    public function providerTemplateLabel(): ?string
    {
        if (blank($this->provider_template_name)) {
            return null;
        }

        return "{$this->provider_template_name} ({$this->provider_template_language})";
    }

    /**
     * Why this message cannot be sent by API, or null when it can.
     *
     * Asked at rule-save time, which is where an admin can do something about
     * the answer, and again at send time.
     *
     * Only the name and language are checked. Templates live in 11za's panel
     * and there is nothing to read them back from, so whether the name exists,
     * is approved, and has as many variables as `placeholder_map` fills in is
     * only found out when 11za answers — and that answer is stored on the
     * message, not swallowed.
     */
    public function apiUnsendableReason(): ?string
    {
        if (blank($this->provider_template_name)) {
            return "\"{$this->name}\" has no 11za template name. Add it on the Templates tab.";
        }

        if (blank($this->provider_template_language)) {
            return "\"{$this->name}\" has no template language. Add it on the Templates tab.";
        }

        return null;
    }
}
