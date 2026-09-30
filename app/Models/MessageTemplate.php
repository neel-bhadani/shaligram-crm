<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp message written once and sent many times.
 *
 * The body carries named placeholders. Rendering it against a lead is
 * App\Services\WhatsApp\TemplateRenderer — this model holds the text and the
 * numbering Meta will want, and nothing else.
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

    /** The approved Meta template this message goes out as by API, if any. */
    public function whatsappTemplate()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    /**
     * Why this message cannot be sent by API, or null when it can.
     *
     * Asked at rule-save time, which is where an admin can do something about
     * the answer, and again at send time, because a template Meta pauses next
     * week stops qualifying without anybody touching the rule.
     *
     * The count check is the one that matters most. The CRM fills {{1}}, {{2}}
     * from `placeholder_map` in order; a Meta body with three variables and a
     * map with two would be refused by Meta on every single send.
     */
    public function apiUnsendableReason(): ?string
    {
        $meta = $this->whatsappTemplate;

        if (! $meta) {
            return "\"{$this->name}\" is not linked to a Meta template. Link it on the Templates tab.";
        }

        if (! $meta->isApproved()) {
            return "\"{$this->name}\" is linked to {$meta->label}, which Meta has not approved (status: {$meta->status}).";
        }

        if ($reason = $meta->unsupportedReason()) {
            return "{$meta->label} cannot be sent by the CRM. {$reason}";
        }

        $mapped = count($this->placeholder_map ?? []);
        $wanted = $meta->bodyParamCount();

        if ($mapped !== $wanted) {
            return "{$meta->label} has {$wanted} variable".($wanted === 1 ? '' : 's')
                ." but \"{$this->name}\" fills in {$mapped}. Make the message use the same number of placeholders, in the same order.";
        }

        return null;
    }
}
