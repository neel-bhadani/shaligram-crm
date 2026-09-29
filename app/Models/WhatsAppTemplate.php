<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A template as Meta holds it, copied in by "Sync templates from Meta".
 *
 * The CRM cannot write or approve these — that happens in WhatsApp Manager.
 * What the CRM owns is `parameter_map`: which of its own placeholders fills
 * each of the template's variables. Meta only knows {{1}} and {{2}}; the admin
 * says that {{1}} is {first_name}. See App\Services\WhatsApp\WhatsAppTemplateSync.
 */
class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'parameter_map' => 'array',
        'components' => 'array',
        'synced_at' => 'datetime',
    ];

    public const APPROVED = 'APPROVED';

    public function messages()
    {
        return $this->hasMany(MessageLog::class, 'whatsapp_template_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::APPROVED);
    }

    /** Approved, and nothing this CRM cannot fill. */
    public function scopeUsable($query)
    {
        return $query->approved()->whereNull('unsupported_reason');
    }

    /** "booking_update (en_US)" — the name alone is ambiguous across languages. */
    public function getLabelAttribute(): string
    {
        return "{$this->name} ({$this->language})";
    }

    /**
     * The variables nobody has said how to fill yet.
     *
     * @return list<string>
     */
    public function unmappedVariables(): array
    {
        $map = $this->parameter_map ?? [];

        return array_values(array_filter(
            $this->variables ?? [],
            fn (string $variable) => blank($map[$variable] ?? null),
        ));
    }

    /**
     * Why this template cannot be sent, or null when it can.
     *
     * Checked at send time as well as when listing: a template can be paused
     * by Meta, or have its map cleared, between a rule being saved and the
     * rule firing.
     */
    public function unsendableReason(): ?string
    {
        if ($this->status !== self::APPROVED) {
            return "The WhatsApp template “{$this->name}” is not approved by Meta (status: {$this->status}).";
        }

        if ($this->unsupported_reason) {
            return "The WhatsApp template “{$this->name}” cannot be sent from the CRM: {$this->unsupported_reason}";
        }

        if ($missing = $this->unmappedVariables()) {
            $list = implode(', ', array_map(fn ($v) => '{{'.$v.'}}', $missing));

            return "The WhatsApp template “{$this->name}” has variables nobody has mapped yet: {$list}. Map them on the Templates tab.";
        }

        return null;
    }
}
