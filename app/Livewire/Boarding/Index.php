<?php

namespace App\Livewire\Boarding;

use App\Enums\ProxyTypes;
use App\Exceptions\AdminCreationQuotaExceeded;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\AdminCreationQuota;
use App\Services\ConfigurationRepository;
use App\Support\OdooGit;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    protected $listeners = [
        'refreshBoardingIndex' => 'validateServer',
        'prerequisitesInstalled' => 'handlePrerequisitesInstalled',
    ];

    #[Url(as: 'step', history: true)]
    public string $currentState = 'welcome';

    #[Url(keep: true)]
    public ?string $selectedServerType = null;

    public ?Collection $privateKeys = null;

    #[Url(keep: true)]
    public ?int $selectedExistingPrivateKey = null;

    #[Url(keep: true)]
    public ?string $privateKeyType = null;

    public ?string $privateKey = null;

    public ?string $publicKey = null;

    public ?string $privateKeyName = null;

    public ?string $privateKeyDescription = null;

    public ?PrivateKey $createdPrivateKey = null;

    public ?Collection $servers = null;

    #[Url(keep: true)]
    public ?int $selectedExistingServer = null;

    public ?string $remoteServerName = null;

    public ?string $remoteServerDescription = null;

    public ?string $remoteServerHost = null;

    public ?int $remoteServerPort = 22;

    public ?string $remoteServerUser = 'root';

    public bool $isSwarmManager = false;

    public bool $isCloudflareTunnel = false;

    public ?Server $createdServer = null;

    public Collection $projects;

    #[Url(keep: true)]
    public ?int $selectedProject = null;

    public ?Project $createdProject = null;

    public bool $dockerInstallationStarted = false;

    public string $serverPublicKey;

    public bool $serverReachable = true;

    public ?string $minDockerVersion = null;

    public int $prerequisiteInstallAttempts = 0;

    public int $maxPrerequisiteInstallAttempts = 3;

    public string $projectName = '';

    public ?int $getOdooProjectId = null;

    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'admin';

    public function mount()
    {
        if (auth()->user()?->isMember() && auth()->user()->currentTeam()->show_boarding === true) {
            return redirect()->route('dashboard');
        }

        // Paid GetOdoo plans get the welcome assistant, not the Coolify server wizard.
        if ($this->isGetOdooPlanBoarding() && in_array($this->currentState, ['welcome', 'getodoo-tour'], true)) {
            $this->currentState = $this->currentState === 'getodoo-tour' ? 'getodoo-project' : 'getodoo-welcome';
        }

        $this->minDockerVersion = str(config('constants.docker.minimum_required_version'))->before('.');
        $this->privateKeyName = generate_random_name();
        $this->remoteServerName = generate_random_name();

        // Initialize collections to avoid null errors
        if ($this->privateKeys === null) {
            $this->privateKeys = collect();
        }
        if ($this->servers === null) {
            $this->servers = collect();
        }
        if (! isset($this->projects)) {
            $this->projects = collect();
        }

        // Restore state when coming from URL with query params
        if ($this->selectedServerType === 'localhost' && $this->selectedExistingServer === 0) {
            $this->createdServer = Server::find(0);
            if ($this->createdServer) {
                $this->serverPublicKey = $this->createdServer->privateKey->getPublicKey();
            }
        }

        if ($this->selectedServerType === 'remote') {
            if ($this->privateKeys->isEmpty()) {
                $this->privateKeys = PrivateKey::ownedAndOnlySShKeys(['name'])->where('id', '!=', 0)->get();
            }
            if ($this->servers->isEmpty()) {
                $this->servers = Server::ownedByCurrentTeam(['name'])->where('id', '!=', 0)->get();
            }

            if ($this->selectedExistingServer) {
                $this->createdServer = Server::ownedByCurrentTeam()->find($this->selectedExistingServer);
                if ($this->createdServer) {
                    $this->serverPublicKey = $this->createdServer->privateKey->getPublicKey();
                    $this->updateServerDetails();
                }
            }

            if ($this->selectedExistingPrivateKey) {
                $this->createdPrivateKey = PrivateKey::where('team_id', currentTeam()->id)
                    ->where('id', $this->selectedExistingPrivateKey)
                    ->first();
                if ($this->createdPrivateKey) {
                    $this->privateKey = $this->createdPrivateKey->private_key;
                    $this->publicKey = $this->createdPrivateKey->getPublicKey();
                }
            }

            // Auto-regenerate key pair for "Generate with Coolify" mode on page refresh
            if ($this->privateKeyType === 'create' && empty($this->privateKey)) {
                $this->createNewPrivateKey();
            }
        }

        if ($this->selectedProject) {
            $this->createdProject = Project::ownedByCurrentTeam()->find($this->selectedProject);
            if (! $this->createdProject) {
                $this->projects = Project::ownedByCurrentTeam(['name'])->get();
            }
        }

        // Load projects when on create-project state (for page refresh)
        if ($this->currentState === 'create-project' && $this->projects->isEmpty()) {
            $this->projects = Project::ownedByCurrentTeam(['name'])->get();
        }
    }

    public function explanation()
    {
        if ($this->isGetOdooPlanBoarding()) {
            $this->currentState = 'getodoo-project';

            return;
        }

        if (isCloud()) {
            return $this->setServerType('remote');
        }
        $this->currentState = 'select-server-type';
    }

    public function startGetOdooProject(): void
    {
        abort_unless($this->isGetOdooPlanBoarding(), 403);
        $this->currentState = 'getodoo-project';
    }

    public function createGetOdooProject(): void
    {
        abort_unless($this->isGetOdooPlanBoarding(), 403);
        $this->authorize('create', Project::class);
        $this->validate([
            'projectName' => ValidationPatterns::nameRules(),
        ]);

        if ($this->getOdooProjectId === null) {
            $existing = Project::ownedByCurrentTeam()
                ->where('name', $this->projectName)
                ->where('created_by', auth()->id())
                ->first();

            if ($existing) {
                $this->getOdooProjectId = $existing->id;
            } else {
                try {
                    $project = app(AdminCreationQuota::class)->createProject(auth()->user(), [
                        'name' => $this->projectName,
                        'team_id' => currentTeam()->id,
                        'uuid' => new_public_id(),
                    ]);
                    $this->getOdooProjectId = $project->id;
                } catch (AdminCreationQuotaExceeded $exception) {
                    $this->addError('projectName', $exception->getMessage());

                    return;
                }
            }
        }

        $this->currentState = 'getodoo-invite';
    }

    public function inviteGetOdooTeammate(): void
    {
        abort_unless($this->isGetOdooPlanBoarding(), 403);
        $team = currentTeam();
        $this->authorize('manageInvitations', $team);
        $this->validate([
            'inviteName' => ValidationPatterns::nameRules(),
            'inviteEmail' => ['required', 'email'],
            'inviteRole' => ['required', Rule::in(['admin', 'member'])],
        ]);

        try {
            $this->sendGetOdooInvitation($team);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AdminCreationQuotaExceeded $exception) {
            $this->addError('inviteEmail', $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            $this->addError('inviteEmail', $exception->getMessage());

            return;
        }

        $this->currentState = 'getodoo-servers';
    }

    public function continueGetOdooTour(): mixed
    {
        if (in_array($this->currentState, ['getodoo-welcome', 'getodoo-tour'], true)) {
            $this->currentState = 'getodoo-project';

            return null;
        }

        if ($this->currentState === 'getodoo-servers') {
            return $this->finishGetOdooBoarding();
        }

        return null;
    }

    public function finishGetOdooBoarding(): mixed
    {
        return $this->skipBoarding();
    }

    /**
     * Leave the GetOdoo tour and open the server create assistant.
     * Must clear show_boarding first or DecideWhatToDoWithUser sends the user back to onboarding.
     */
    public function goToCreateServerFromGetOdooTour(): mixed
    {
        abort_unless($this->isGetOdooPlanBoarding(), 403);
        abort_unless((bool) auth()->user()?->canAddServers(), 403);

        return $this->skipBoarding(routeName: 'server.create');
    }

    public function restartBoarding()
    {
        return redirect()->route('onboarding');
    }

    public function skipBoarding(string $routeName = 'dashboard')
    {
        if (auth()->user()?->isMember()) {
            return redirect()->route('dashboard');
        }
        Team::find(currentTeam()->id)->update([
            'show_boarding' => false,
        ]);
        refreshSession();

        return redirect()->route($routeName);
    }

    public function isGetOdooPlanBoarding(): bool
    {
        return filled(currentTeam()?->getodoo_plan_id);
    }

    public function setServerType(string $type)
    {
        $this->selectedServerType = $type;
        if ($this->selectedServerType === 'localhost') {
            $this->createdServer = Server::find(0);
            $this->selectedExistingServer = 0;
            if (! $this->createdServer) {
                return $this->dispatch('error', __('Localhost server is not found. Something went wrong during installation. Please try to reinstall or contact support.'));
            }
            $this->serverPublicKey = $this->createdServer->privateKey->getPublicKey();

            return $this->validateServer('localhost');
        } elseif ($this->selectedServerType === 'remote') {
            $this->privateKeys = PrivateKey::ownedAndOnlySShKeys(['name'])->where('id', '!=', 0)->get();
            // Auto-select first key if available for better UX
            if ($this->privateKeys->count() > 0) {
                $this->selectedExistingPrivateKey = $this->privateKeys->first()->id;
            }
            // Onboarding always creates new servers, skip existing server selection
            $this->currentState = 'private-key';
        }
    }

    private function updateServerDetails()
    {
        if ($this->createdServer) {
            $this->remoteServerPort = $this->createdServer->port;
            $this->remoteServerUser = $this->createdServer->user;
        }
    }

    protected function rules(): array
    {
        return [
            'remoteServerName' => 'required|string',
            'remoteServerHost' => 'required|string',
            'remoteServerPort' => 'required|integer|min:1|max:65535',
            'remoteServerUser' => ValidationPatterns::serverUsernameRules(),
        ];
    }

    protected function messages(): array
    {
        return [
            ...ValidationPatterns::serverUsernameMessages('remoteServerUser', 'SSH User'),
        ];
    }

    public function getProxyType()
    {
        $this->selectProxy(ProxyTypes::TRAEFIK->value);
        $this->getProjects();
    }

    public function selectExistingPrivateKey()
    {
        if (is_null($this->selectedExistingPrivateKey)) {
            $this->dispatch('error', __('Please select a private key.'));

            return;
        }
        $this->createdPrivateKey = PrivateKey::where('team_id', currentTeam()->id)->where('id', $this->selectedExistingPrivateKey)->first();
        $this->privateKey = $this->createdPrivateKey->private_key;
        $this->currentState = 'create-server';
    }

    public function createNewServer()
    {
        $this->selectedExistingServer = null;
        $this->currentState = 'private-key';
    }

    public function setPrivateKey(string $type)
    {
        $this->selectedExistingPrivateKey = null;
        $this->privateKeyType = $type;
        if ($type === 'create') {
            $this->createNewPrivateKey();
        } else {
            $this->privateKey = null;
            $this->publicKey = null;
        }
        $this->currentState = 'create-private-key';
    }

    public function savePrivateKey()
    {
        $this->validate([
            'privateKeyName' => 'required|string|max:255',
            'privateKeyDescription' => 'nullable|string|max:255',
            'privateKey' => 'required|string',
        ]);

        try {
            $this->authorize('create', PrivateKey::class);
            $privateKey = PrivateKey::createAndStore([
                'name' => $this->privateKeyName,
                'description' => $this->privateKeyDescription,
                'private_key' => $this->privateKey,
                'team_id' => currentTeam()->id,
            ]);

            $this->createdPrivateKey = $privateKey;
            $this->currentState = 'create-server';
        } catch (\Exception $e) {
            $this->addError('privateKey', 'Failed to save private key: '.$e->getMessage());
        }
    }

    public function saveServer()
    {
        $this->validate();

        try {
            $this->authorize('create', Server::class);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        $this->privateKey = formatPrivateKey($this->privateKey);
        $foundServer = Server::whereIp($this->remoteServerHost)->first();
        if ($foundServer) {
            if ($foundServer->team_id === currentTeam()->id) {
                return $this->dispatch('error', __('A server with this IP/Domain already exists in your team.'));
            }

            return $this->dispatch('error', __('A server with this IP/Domain is already in use by another team.'));
        }
        $this->createdServer = Server::create([
            'name' => $this->remoteServerName,
            'ip' => $this->remoteServerHost,
            'port' => $this->remoteServerPort,
            'user' => $this->remoteServerUser,
            'description' => $this->remoteServerDescription,
            'private_key_id' => $this->createdPrivateKey->id,
            'team_id' => currentTeam()->id,
        ]);
        $this->createdServer->settings->is_swarm_manager = $this->isSwarmManager;
        $this->createdServer->settings->is_cloudflare_tunnel = $this->isCloudflareTunnel;
        $this->createdServer->settings->save();
        $this->selectedExistingServer = $this->createdServer->id;
        $this->currentState = 'validate-server';
    }

    public function installServer()
    {
        $this->dispatch('init', true);
    }

    public function validateServer()
    {
        try {
            $this->disableSshMux();

            // EC2 does not have `uptime` command, lol
            instant_remote_process(['ls /'], $this->createdServer, true);

            $this->createdServer->settings()->update([
                'is_reachable' => true,
            ]);
            $this->serverReachable = true;
        } catch (\Throwable $e) {
            $this->serverReachable = false;
            $this->createdServer->settings()->update([
                'is_reachable' => false,
            ]);

            return handleError(error: $e, livewire: $this);
        }

        try {
            // Check prerequisites
            $validationResult = $this->createdServer->validatePrerequisites();
            if (! $validationResult['success']) {
                // Check if we've exceeded max attempts
                if ($this->prerequisiteInstallAttempts >= $this->maxPrerequisiteInstallAttempts) {
                    $missingCommands = implode(', ', $validationResult['missing']);
                    throw new \Exception("Prerequisites ({$missingCommands}) could not be installed after {$this->maxPrerequisiteInstallAttempts} attempts. Please install them manually.");
                }

                // Start async installation and wait for completion via ActivityMonitor
                $activity = $this->createdServer->installPrerequisites();
                $this->prerequisiteInstallAttempts++;
                $this->dispatch('activityMonitor', $activity->id, 'prerequisitesInstalled');

                // Return early - handlePrerequisitesInstalled() will be called when installation completes
                return;
            }

            // Prerequisites are already installed, continue with validation
            $this->continueValidation();
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    public function handlePrerequisitesInstalled()
    {
        try {
            // Revalidate prerequisites after installation completes
            $validationResult = $this->createdServer->validatePrerequisites();
            if (! $validationResult['success']) {
                // Installation completed but prerequisites still missing - retry
                $missingCommands = implode(', ', $validationResult['missing']);

                if ($this->prerequisiteInstallAttempts >= $this->maxPrerequisiteInstallAttempts) {
                    throw new \Exception("Prerequisites ({$missingCommands}) could not be installed after {$this->maxPrerequisiteInstallAttempts} attempts. Please install them manually.");
                }

                // Try again
                $activity = $this->createdServer->installPrerequisites();
                $this->prerequisiteInstallAttempts++;
                $this->dispatch('activityMonitor', $activity->id, 'prerequisitesInstalled');

                return;
            }

            // Prerequisites validated successfully - continue with Docker validation
            $this->continueValidation();
        } catch (\Throwable $e) {
            return handleError(error: $e, livewire: $this);
        }
    }

    private function continueValidation()
    {
        try {
            $dockerVersion = instant_remote_process(["docker version|head -2|grep -i version| awk '{print $2}'"], $this->createdServer, true);
            $dockerVersion = checkMinimumDockerEngineVersion($dockerVersion);
            if (is_null($dockerVersion)) {
                $this->currentState = 'validate-server';
                throw new \Exception('Docker not found or old version is installed.');
            }
            $this->createdServer->settings()->update([
                'is_usable' => true,
            ]);
            $this->getProxyType();
        } catch (\Throwable $e) {
            $this->createdServer->settings()->update([
                'is_usable' => false,
            ]);

            return handleError(error: $e, livewire: $this);
        }
    }

    public function selectProxy(?string $proxyType = null)
    {
        if (! $proxyType) {
            return $this->getProjects();
        }
        $this->createdServer->proxy->type = $proxyType;
        $this->createdServer->proxy->status = 'exited';
        $this->createdServer->proxy->last_saved_settings = null;
        $this->createdServer->proxy->last_applied_settings = null;
        $this->createdServer->save();
        $this->getProjects();
    }

    public function getProjects()
    {
        $this->projects = Project::ownedByCurrentTeam(['name'])->get();
        if ($this->projects->count() > 0) {
            $this->selectedProject = $this->projects->first()->id;
        }
        $this->currentState = 'create-project';
    }

    public function selectExistingProject()
    {
        $this->createdProject = Project::ownedByCurrentTeam()->find($this->selectedProject);
        if (! $this->createdProject) {
            return $this->dispatch('error', __('Project not found.'));
        }
        $this->currentState = 'create-resource';
    }

    public function createNewProject()
    {
        try {
            $this->authorize('create', Project::class);
            $this->createdProject = app(AdminCreationQuota::class)->createProject(auth()->user(), [
                'name' => 'My first project',
                'team_id' => currentTeam()->id,
                'uuid' => new_public_id(),
            ]);
            $this->currentState = 'create-resource';
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function showNewResource()
    {
        $this->skipBoarding();

        return redirect()->route(
            'project.resource.create',
            [
                'project_uuid' => $this->createdProject->uuid,
                'environment_uuid' => $this->createdProject->environments->first()->uuid,
                'server' => $this->createdServer->id,
            ]
        );
    }

    public function saveAndValidateServer()
    {
        $this->validate(array_intersect_key($this->rules(), array_flip([
            'remoteServerPort',
            'remoteServerUser',
        ])));

        $this->createdServer->update([
            'port' => $this->remoteServerPort,
            'user' => $this->remoteServerUser,
            'timezone' => 'UTC',
        ]);
        $this->validateServer();
    }

    private function sendGetOdooInvitation(Team $team): void
    {
        $email = strtolower($this->inviteEmail);
        $this->inviteEmail = $email;

        if ($team->members()->where('users.email', $email)->exists()) {
            throw new \Exception(__('This person is already on the team.'));
        }

        $uuid = new_public_id(32);
        $link = $this->getOdooInvitationUrl('team.invitation.show', ['uuid' => $uuid]);
        $user = User::whereEmail($email)->first();

        if ($user === null) {
            $password = Str::password();
            $user = User::withoutPersonalTeam(fn () => User::create([
                'name' => $this->inviteName,
                'email' => $email,
                'password' => Hash::make($password),
                'force_password_reset' => true,
            ]));
            $token = Crypt::encryptString("{$user->email}@@@{$uuid}@@@{$password}");
            $link = $this->getOdooInvitationUrl('auth.link', ['token' => $token]);
        }

        $invitation = TeamInvitation::whereEmail($email)->first();
        if ($invitation !== null) {
            if ($invitation->isValid()) {
                throw new \Exception(__('A pending invitation already exists for this email.'));
            }

            $invitation->delete();
        }

        app(AdminCreationQuota::class)->createInvitation(auth()->user(), [
            'team_id' => $team->id,
            'uuid' => $uuid,
            'email' => $email,
            'role' => $this->inviteRole,
            'link' => $link,
            'via' => 'email',
        ]);

        if (! $user->teams()->where('teams.id', $team->id)->exists()) {
            $user->teams()->attach($team->id, [
                'role' => $this->inviteRole,
                'added_by' => auth()->id(),
                'can_add_servers' => false,
                'can_launch_on_instance_server' => false,
            ]);
        }

        try {
            OdooGit::inviteEmailToRepositories((int) $team->id, $email);
            $mail = new MailMessage;
            $mail->view('emails.invitation-link', [
                'team' => $team->name,
                'invitation_link' => $link,
            ]);
            $mail->subject('You have been invited to '.$team->name.' on '.product_name().'.');
            send_user_an_email($mail, $email);
        } catch (\Throwable) {
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function getOdooInvitationUrl(string $route, array $parameters = []): string
    {
        $url = route($route, $parameters);
        $fqdn = instanceSettings()?->fqdn;
        if (blank($fqdn)) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $query = parse_url($url, PHP_URL_QUERY);

        return rtrim((string) $fqdn, '/').'/'.ltrim($path, '/').($query ? '?'.$query : '');
    }

    private function createNewPrivateKey()
    {
        $this->privateKeyName = generate_random_name();
        $this->privateKeyDescription = 'Created by Coolify';
        ['private' => $this->privateKey, 'public' => $this->publicKey] = generateSSHKey();
    }

    private function disableSshMux(): void
    {
        $configRepository = app(ConfigurationRepository::class);
        $configRepository->disableSshMux();
    }

    public function render()
    {
        $getOdooBoarding = $this->isGetOdooPlanBoarding();

        return view('livewire.boarding.index', [
            'getOdooBoarding' => $getOdooBoarding,
            'canAddServers' => (bool) auth()->user()?->canAddServers(),
            'canLaunchOnInstanceServer' => (bool) auth()->user()?->canLaunchOnInstanceServer(),
            'getOdooShellDescription' => match ($this->currentState) {
                'getodoo-welcome' => __('Your account is ready.'),
                'getodoo-project' => __('Name the project. A production environment is created with it.'),
                'getodoo-invite' => __('Invite someone to the team as an admin or a guest.'),
                'getodoo-servers' => __('Thank you for being part of the :product team.', ['product' => product_name()]),
                default => __('Getting started'),
            },
        ])->layout($getOdooBoarding ? 'layouts.simple' : 'layouts.boarding');
    }
}
