<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A template as Meta has it: name, language, approval status, components.
 *
 * Written by WhatsAppTemplateSync and nothing else. The CRM never edits these
 * — a template is written and submitted in Meta's WhatsApp Manager, and this
 * row is what the last sync saw. A MessageTemplate links to one to be sendable
 * by API; see MessageTemplate::apiUnsendableReason().
 */
class WhatsAppTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $guarded = [];

    protected $casts = [
        'components' => 'array',
        'synced_at' => 'datetime',
    ];

    public const APPROVED = 'APPROVED';

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class, 'whatsapp_template_id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    /** "site_visit (en)" — the name alone is ambiguous across languages. */
    public function getLabelAttribute(): string
    {
        return "{$this->name} ({$this->language})";
    }

    /**
     * How many {{n}} variables the body carries.
     *
     * The highest number rather than a count of matches: "{{1}} … {{1}}" is one
     * variable used twice, and Meta wants one parameter for it.
     */
    public function bodyParamCount(): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', (string) $this->body, $matches);

        return $matches[1] === [] ? 0 : max(array_map('intval', $matches[1]));
    }

    /**
     * Why the CRM cannot fill this template in, or null when it can.
     *
     * The CRM sends body text variables and nothing else. A media header needs
     * a file, a URL button variable needs a link, and named variables need a
     * different payload — each of those would be refused by Meta at send time
     * with an error nobody could act on, so they are refused here instead.
     */
    public function unsupportedReason(): ?string
    {
        foreach ($this->components ?? [] as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'HEADER') {
                $format = strtoupper((string) ($component['format'] ?? 'TEXT'));

                if ($format !== 'TEXT') {
                    return "It has a {$format} header, which needs a file the CRM cannot attach.";
                }

                if (str_contains((string) ($component['text'] ?? ''), '{{')) {
                    return 'Its header has a variable. Only variables in the body can be filled in.';
                }
            }

            if ($type === 'BUTTONS') {
                foreach ($component['buttons'] ?? [] as $button) {
                    if (str_contains((string) ($button['url'] ?? ''), '{{')) {
                        return 'It has a button with a variable in its link, which the CRM cannot fill in.';
                    }
                }
            }
        }

        if (preg_match('/\{\{[^}\d]+\}\}/', (string) $this->body)) {
            return 'It uses named variables. Submit it with numbered ones — {{1}}, {{2}} — instead.';
        }

        return null;
    }
}
