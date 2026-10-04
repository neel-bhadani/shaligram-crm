<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One template in the 11za account, as last listed by 11za.
 *
 * Only what the dropdown needs: the name, the language and how many variables
 * it has. No wording and no raw answer — the wording 11za sends is copied onto
 * each message (MessageTemplate::$provider_body) at read time, and the raw
 * answer is shown once on screen and never kept.
 *
 * Written only by WhatsAppSender::refreshProviderTemplates(), which replaces
 * the whole table on every successful read.
 */
class ProviderTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'variables' => 'integer',
    ];

    /** @return array{name: string, language: ?string, variables: ?int} */
    public function toListEntry(): array
    {
        return [
            'name' => $this->name,
            'language' => $this->language,
            'variables' => $this->variables,
        ];
    }
}
