<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a legacy file, and what the import did with it.
 *
 * Every source row gets exactly one of these, whether it became a lead
 * (`created`) or was folded into another lead with the same mobile and
 * project (`absorbed`). `legacy` holds the row in full, `flags` what was wrong
 * with it, `filled` what had to be supplied. Written by `import:legacy`,
 * which inserts through the query builder, so nothing here fires on insert,
 * and by the bulk import wizard, which also records `reset` for a row that
 * moved existing leads back to Fresh.
 */
class LeadImportRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'awaiting_follow_up' => 'boolean',
            'filled' => 'array',
            'flags' => 'array',
            'legacy' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class)->withTrashed();
    }
}
