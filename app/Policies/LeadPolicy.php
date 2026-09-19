<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\Project;
use App\Models\User;

/**
 * Who may do what to a lead, in one file.
 *
 * Two questions decide every answer here and they are deliberately separate:
 *
 *   *May this user do this kind of thing at all?*  — a permission, resolved by
 *   User::can_(), which reads the per-user JSON and falls back to the role's
 *   default. No role string appears below.
 *
 *   *May they do it to THIS lead?*  — ownership, which is Lead::visibleTo()'s
 *   rule said again for a single row. `see_all_leads` answers it for a manager
 *   and an admin alike.
 *
 * Both must pass. A telecaller granted `edit_leads` may edit the leads they
 * hold, not everybody's — the two toggles are independent on purpose, and
 * conflating them would turn "can edit" into "can see", which is the one
 * mistake in here that would leak data rather than merely annoy someone.
 *
 * Laravel 13 discovers this by name (App\Models\Lead -> App\Policies\LeadPolicy),
 * so there is nothing to register.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // everyone has a list; visibleTo() decides what is on it
    }

    /** Ownership, said for one row. The scope says it for a query. */
    public function view(User $user, Lead $lead): bool
    {
        if ($user->can_('see_all_leads')) {
            return true;
        }

        return $lead->assigned_to === $user->id && $this->onOwnProject($user, $lead);
    }

    public function create(User $user): bool
    {
        return $user->can_('add_leads');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can_('edit_leads') && $this->view($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can_('delete_leads') && $this->view($user, $lead);
    }

    /**
     * The manual "reassign to" action — gated on being able to see the lead
     * at all, not on `edit_leads`.
     *
     * Every role that can see a lead can hand it to someone else, including a
     * telecaller, who holds `edit_leads = false` by default. Reassignment is
     * not an edit of the lead's own fields; it is how the desk that has to
     * work a lead next gets to say so, which is a capability every role
     * needs regardless of whether they are trusted to change what the lead
     * says.
     */
    public function reassign(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead);
    }

    /**
     * The "switch project" action on the Follow-up page — moves a lead to a
     * different project without losing it or duplicating it.
     *
     * Gated exactly like reassign(): being able to see the lead is enough,
     * not `edit_leads`, so every role that can see a lead can switch its
     * project regardless of whether they are trusted to change what the
     * lead's own fields say.
     *
     * Unlike reassign(), there is no further project boundary to check here.
     * onOwnProject() bounds which leads a salesperson can reach at all, and
     * that already ran inside view() above — but reassign() narrows WHO a
     * lead can be moved TO by that same rule (LeadReassignRequest), because
     * its target is a person tied to a project. This action's target is the
     * project itself, chosen from every active one, with no such person to
     * narrow — the client's explicit call, and deliberately not the same
     * boundary reassign()'s candidate list applies.
     */
    public function switchProject(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead);
    }

    /**
     * Project boundary, said for one row — see Lead::scopeVisibleTo() for the
     * same rule as a query. Telecallers are a single company-wide desk, never
     * tied to a project (see LeadAssignmentService), so only a salesperson's
     * own project membership can make a lead they already own unreachable.
     */
    private function onOwnProject(User $user, Lead $lead): bool
    {
        return ! $user->isSalesperson()
            || Project::whereKey($lead->project_id)->whereHas('salespeople', fn ($q) => $q->whereKey($user->id))->exists();
    }
}
