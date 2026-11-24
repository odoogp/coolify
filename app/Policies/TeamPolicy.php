<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->teams->contains('id', $team->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->teams()->wherePivot('role', 'owner')->exists();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdminOfTeam($team->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->roleInTeam($team->id) === 'owner';
    }

    /**
     * Determine whether the user can manage team members.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdminOfTeam($team->id);
    }

    /**
     * Determine whether the user can view admin panel.
     */
    public function viewAdmin(User $user, Team $team): bool
    {
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdminOfTeam($team->id);
    }

    /**
     * Determine whether the user can manage invitations.
     */
    public function manageInvitations(User $user, Team $team): bool
    {
        if (! $user->teams->contains('id', $team->id)) {
            return false;
        }

        return $user->isAdminOfTeam($team->id);
    }

    /**
     * Creation quotas and server/launch flags on membership profiles.
     * Instance owner always. Plan principals must not assign them — only the platform owner.
     * On non-plan teams, the team owner still may.
     */
    /**
     * Creation limits, server flags and the instance-server launch flag are
     * platform-owner powers. Client team owners never see or grant them.
     */
    public function updateCreationLimits(User $user, Team $team): bool
    {
        return $user->isInstanceOwner();
    }
}
