<?php

namespace App\Livewire\Team;

use App\Exceptions\AdminCreationQuotaExceeded;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\AdminCreationQuota;
use App\Support\OdooAbilities;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class InviteLink extends Component
{
    use AuthorizesRequests;

    public string $email;

    public string $role = 'member';

    public mixed $maxProjects = null;

    public mixed $maxEnvironments = null;

    public mixed $maxMembers = null;

    public mixed $maxProductionBranches = null;

    public mixed $maxStagingBranches = null;

    public mixed $maxServices = null;

    public mixed $githubAppId = null;

    public bool $canAddServers = false;

    public bool $canLaunchOnInstanceServer = false;

    /** @var list<string> */
    public array $odooAbilities = [];

    protected $rules = [
        'email' => 'required|email',
        'role' => 'required|string',
    ];

    public function mount()
    {
        $this->email = isDev() ? 'test3@example.com' : '';
    }

    public function viaEmail()
    {
        $this->generateInviteLink(sendEmail: true);
    }

    public function viaLink()
    {
        $this->generateInviteLink(sendEmail: false);
    }

    private function invitationUrl(string $routeName, array $parameters): string
    {
        $fqdn = instanceSettings()->fqdn;
        if (filled($fqdn)) {
            return rtrim($fqdn, '/').route($routeName, $parameters, false);
        }

        return route($routeName, $parameters);
    }

    private function generateInviteLink(bool $sendEmail = false)
    {
        try {
            $this->authorize('manageInvitations', currentTeam());
            $this->validate();

            // Prevent privilege escalation: users cannot invite someone with higher privileges
            $userRole = auth()->user()->role();
            if (is_null($userRole) || ($userRole === 'member' && in_array($this->role, ['admin', 'owner']))) {
                throw new \Exception('Members cannot invite admins or owners.');
            }
            if ($userRole === 'admin' && $this->role === 'owner') {
                throw new \Exception('Admins cannot invite owners.');
            }

            $this->email = strtolower($this->email);

            $member_emails = currentTeam()->members()->get()->pluck('email');
            if ($member_emails->contains($this->email)) {
                return handleError(livewire: $this, customErrorMessage: "$this->email is already a member of ".currentTeam()->name.'.');
            }
            $uuid = new_public_id(32);
            $link = $this->invitationUrl('team.invitation.show', ['uuid' => $uuid]);
            $user = User::whereEmail($this->email)->first();

            if (is_null($user)) {
                $password = Str::password();
                $user = User::withoutPersonalTeam(fn () => User::create([
                    'name' => str($this->email)->before('@'),
                    'email' => $this->email,
                    'password' => Hash::make($password),
                    'force_password_reset' => true,
                ]));
                $token = Crypt::encryptString("{$user->email}@@@{$uuid}@@@{$password}");
                $link = $this->invitationUrl('auth.link', ['token' => $token]);
            }
            $invitation = TeamInvitation::whereEmail($this->email)->first();
            if (! is_null($invitation)) {
                $invitationValid = $invitation->isValid();
                if ($invitationValid) {
                    return handleError(livewire: $this, customErrorMessage: "Pending invitation already exists for $this->email.");
                } else {
                    $invitation->delete();
                }
            }

            $team = currentTeam();
            $invitation = app(AdminCreationQuota::class)->createInvitation(auth()->user(), [
                'team_id' => $team->id,
                'uuid' => $uuid,
                'email' => $this->email,
                'role' => $this->role,
                'link' => $link,
                'via' => $sendEmail ? 'email' : 'link',
            ]);
            if (! $user->teams()->where('teams.id', $team->id)->exists()) {
                $user->teams()->attach($team->id, $this->membershipAttributes($team));
            }
            if ($sendEmail) {
                $mail = new MailMessage;
                $mail->view('emails.invitation-link', [
                    'team' => currentTeam()->name,
                    'invitation_link' => $link,
                ]);
                $mail->subject('You have been invited to '.currentTeam()->name.' on '.product_name().'.');
                send_user_an_email($mail, $this->email);
                $this->dispatch('success', __('Invitation sent via email.'));
            } else {
                $this->dispatch('success', __('Invitation link generated.'));
            }
            $this->dispatch('refreshInvitations');
            $this->dispatch('reloadWindow');
        } catch (ValidationException $e) {
            throw $e;
        } catch (AdminCreationQuotaExceeded $e) {
            return handleError(error: $e, livewire: $this);
        } catch (\Throwable $e) {
            $error_message = $e->getMessage();
            if ($e->getCode() === '23505') {
                $error_message = 'Invitation already sent.';
            }

            return handleError(error: $e, livewire: $this, customErrorMessage: $error_message);
        }
    }

    public function render()
    {
        $team = currentTeam();
        $canAssign = $team !== null && auth()->user()?->can('updateCreationLimits', $team);

        return view('livewire.team.invite-link', [
            'creationQuota' => app(AdminCreationQuota::class)->summaryForViewer(),
            'canAssignPermissions' => $canAssign,
            'githubApps' => $canAssign
                ? OdooGit::connectedApps($team->id)->map(fn ($app): array => ['value' => $app->id, 'label' => $app->name])->all()
                : [],
            'grantableOdooAbilities' => OdooAbilities::GRANTABLE,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipAttributes(Team $team): array
    {
        $attributes = [
            'role' => $this->role,
            'added_by' => auth()->id(),
            'can_add_servers' => false,
            'can_launch_on_instance_server' => false,
        ];

        if (! auth()->user()->can('updateCreationLimits', $team) || ! in_array($this->role, ['admin', 'member'], true)) {
            return $attributes;
        }

        foreach (['maxProjects', 'maxEnvironments', 'maxMembers', 'maxProductionBranches', 'maxStagingBranches', 'maxServices', 'githubAppId'] as $field) {
            $this->{$field} = $this->{$field} === '' ? null : $this->{$field};
        }
        $this->validate([
            'maxProjects' => ['nullable', 'integer', 'min:0'],
            'maxEnvironments' => ['nullable', 'integer', 'min:0'],
            'maxMembers' => ['nullable', 'integer', 'min:0'],
            'maxProductionBranches' => ['nullable', 'integer', 'min:0'],
            'maxStagingBranches' => ['nullable', 'integer', 'min:0'],
            'maxServices' => ['nullable', 'integer', 'min:0'],
            'githubAppId' => ['nullable', 'integer'],
        ]);
        if ($this->role === 'admin' && $this->githubAppId !== null && ! OdooGit::connectedApps($team->id)->contains('id', (int) $this->githubAppId)) {
            throw ValidationException::withMessages([
                'githubAppId' => __('Select a connected GitHub account.'),
            ]);
        }

        $attributes = [
            ...$attributes,
            'max_projects' => $this->maxProjects,
            'max_environments' => $this->maxEnvironments,
            'max_members' => $this->maxMembers,
            'max_production_branches' => $this->maxProductionBranches,
            'max_staging_branches' => $this->maxStagingBranches,
            'max_services' => $this->maxServices,
        ];

        if ($this->role === 'admin') {
            $attributes['github_app_id'] = $this->githubAppId;
            $attributes['can_add_servers'] = $this->canAddServers;
            $attributes['can_launch_on_instance_server'] = $this->canLaunchOnInstanceServer;
        }

        if ($this->role === 'member') {
            $attributes['odoo_abilities'] = json_encode(OdooAbilities::onlyGrantable($this->odooAbilities));
        }

        return $attributes;
    }
}
