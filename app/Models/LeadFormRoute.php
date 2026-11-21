<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One lead form, and the project its leads belong to.
 *
 * Read by LeadFormRouter for every incoming lead, written by the Facebook
 * settings modal, by "Load forms from Facebook", and by the router itself when
 * a form nobody has mapped sends its first lead — that row has no project, and
 * is how the form shows up in the modal waiting to be assigned.
 *
 * 11za WhatsApp leads use the same table with provider `whatsapp`: each row is
 * one 11za tag (or label), `form_id` holding it normalised — see
 * normaliseTag() — and `form_name` holding it as 11za or the admin wrote it.
 */
class LeadFormRoute extends Model
{
    protected $guarded = [];

    /**
     * What a Meta lead form id looks like: digits only, 15 or 16 of them.
     *
     * Checked only on ids an admin types in. An id that is wrong by one digit
     * is accepted by nothing on Meta's side and matches no delivery, so every
     * lead from the real form goes to the fallback and nobody is told why.
     * Ids that came from Meta itself — a delivery or "Load forms from
     * Facebook" — are Meta's own and are not second-guessed.
     */
    public const FORM_ID_PATTERN = '/^\d{15,16}$/';

    /**
     * The routing key for an 11za tag: trimmed, lower case, inner spaces
     * collapsed, so "Tower B", " tower  b " and "TOWER B" are one route.
     */
    public static function normaliseTag(string $tag): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $tag)));
    }

    /**
     * How many leads have arrived carrying this 11za tag — the activity log's
     * count of distinct leads whose event lists it. A tag with none can be
     * removed from the settings; one with leads is part of their history.
     */
    public static function leadCountForTag(string $tag): int
    {
        return IntegrationEvent::forProvider('whatsapp')
            ->taggedWith($tag)
            ->whereNotNull('lead_id')
            ->distinct()
            ->count('lead_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assign_to_user_id');
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /** What the admin and the activity log call this form. */
    public function label(): string
    {
        return $this->form_name ?: $this->form_id;
    }
}
