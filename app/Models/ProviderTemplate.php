<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * One template in the 11za account, as last listed by 11za.
 *
 * One row per template and language: the name, the language, Meta's approval
 * status for that language, the category, how many body variables it has,
 * how many header and carousel ones, and 11za's BODY wording. No raw answer,
 * which is shown once on screen and never kept.
 *
 * Written only by WhatsAppSender::refreshProviderTemplates(), which replaces
 * the whole table on every successful read.
 */
class ProviderTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'variables' => 'integer',
        'extra_variables' => 'integer',
    ];

    /** @return array{name: string, language: ?string, status: ?string, category: ?string, variables: ?int, extra_variables: ?int, body: ?string} */
    public function toListEntry(): array
    {
        return [
            'name' => $this->name,
            'language' => $this->language,
            'status' => $this->status,
            'category' => $this->category,
            'variables' => $this->variables,
            'extra_variables' => $this->extra_variables,
            'body' => $this->body,
        ];
    }

    /** 11za's wording for this template and language, or null. */
    public static function bodyFor(?string $name, ?string $language): ?string
    {
        if (blank($name)) {
            return null;
        }

        return static::where('name', $name)
            ->where(fn ($q) => $q->where('language', $language)->orWhereNull('language'))
            ->orderByRaw('language is null')
            ->value('body');
    }

    /**
     * The whole list, keyed "name|language", for looking tags up against
     * without a query per tag.
     *
     * @return Collection<string, self>
     */
    public static function keyed(): Collection
    {
        return static::all()->keyBy(fn (self $t) => $t->name.'|'.$t->language);
    }

    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    public function isMarketing(): bool
    {
        return $this->category === 'MARKETING';
    }
}
