<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of the activity log: what arrived, what happened to it, and why.
 *
 * Written by IntegrationLogger and read by the Integrations page. Nothing
 * updates a row — an event is a thing that happened.
 */
class IntegrationEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        // null: not a created lead, or logged before form routing existed
        'routed' => 'boolean',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /** Newest first, which is the only order this is ever read in. */
    public function scopeRecent($query, int $limit)
    {
        return $query->latest('id')->limit($limit);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
