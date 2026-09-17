<?php

namespace App\Policies;

use App\Models\ChannelPartner;
use App\Models\User;

/**
 * Who may do what to a channel partner.
 *
 * One asymmetry, and it is nearly the whole file. The roster is reference data
 * the lead form reads while a lead is being logged, so READING it is open to
 * every signed-in role: viewAny() and view() allow anyone the `auth` route
 * group let through. EDITING is the same crowd — fixing a name or a number a
 * telecaller reads all day is not an admin privilege — and so is MERGING:
 * finding the duplicate and putting it back together is the other half of the
 * same cleanup. update() and merge() therefore allow any authenticated user.
 * Index, update and merge sit outside the `role:admin` group for the same
 * reason — see the channel-partners block in routes/web.php.
 *
 * DELETE is where the asymmetry lands. It is refused by role:admin on the
 * route group, and destroy() has no request of its own to say it again, so the
 * refusal lives here: a DELETE typed by hand cannot reach the controller
 * around the middleware. Both are kept in step — the route group is the door,
 * this is the lock that survives somebody reorganising routes/web.php.
 *
 * Laravel 13 discovers this by name (App\Models\ChannelPartner ->
 * App\Policies\ChannelPartnerPolicy), so there is nothing to register.
 */
class ChannelPartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;   // any authenticated user may open the roster
    }

    public function view(User $user, ChannelPartner $partner): bool
    {
        return true;   // the roster is one list; nothing on it is per-user
    }

    public function update(User $user, ChannelPartner $partner): bool
    {
        return true;   // any authenticated user may fix an existing row
    }

    public function merge(User $user, ChannelPartner $partner): bool
    {
        return true;   // any authenticated user may clean up a duplicate
    }

    public function delete(User $user, ChannelPartner $partner): bool
    {
        return $user->isAdmin();
    }
}