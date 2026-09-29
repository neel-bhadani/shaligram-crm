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
