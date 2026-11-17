<?php

namespace App\Livewire\Team;

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Invitations extends Component
{
    use AuthorizesRequests;

    public $invitations;

    protected $listeners = ['refreshInvitations'];

    public function deleteInvitation(int $invitation_id)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());

            $invitation = TeamInvitation::ownedByCurrentTeam()->findOrFail($invitation_id);
            $invitationEmail = $invitation->email;
            $invitationUuid = $invitation->uuid;
            DB::transaction(function () use ($invitation): void {
                $invitation->delete();
                $user = User::whereEmail($invitation->email)->first();
                if ($user instanceof User) {
                    $user->teams()->detach($invitation->team_id);
                    $user->deleteIfNotVerifiedAndForcePasswordReset();
                }
            });
            auditLog('ui.team_invitation.revoked', [
                'team_id' => currentTeam()->id,
                'invitation_uuid' => $invitationUuid,
                'invitation_email' => $invitationEmail,
            ]);
            $this->refreshInvitations();
            $this->dispatch('success', __('Invitation revoked.'));
        } catch (\Exception) {
            return $this->dispatch('error', __('Invitation not found.'));
        }
    }

    public function refreshInvitations()
    {
        $this->invitations = TeamInvitation::ownedByCurrentTeam()->get();
    }
}
