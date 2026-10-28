<?php

namespace App\Livewire\Project\Service;

use App\Domain\Odoo\OdooVersion;
use App\Jobs\AssociateOdooGithubRepositoryJob;
use App\Jobs\LaunchOdooProjectJob;
use App\Jobs\SyncOdooAddonsJob;
use App\Models\GithubApp;
use App\Models\Service;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;

class Configuration extends Component
{
    use AuthorizesRequests;

    public $currentRoute;

    public $project;

    public $environment;

    public ?Service $service = null;

    public $applications;

    public $databases;

    public array $query;

    public array $parameters;

    protected $listeners = [
        'refreshServices' => 'refreshServices',
        'refresh' => 'refreshServices',
    ];

    public bool $odooNeedsGithub = false;

    public bool $odooIsOdoo = false;

    public string $odooPanel = 'mounted';

    public bool $odooGithubConnected = false;

    public string $odooRepoMode = 'new';

    public ?int $odooGithubAppId = null;

    public array $odooGithubApps = [];

    public array $odooRepositories = [];

    public bool $odooRepositoriesLoaded = false;

    public bool $odooRepositoriesLoading = false;

    public ?int $odooRepositoryId = null;

    public array $odooGithubBranches = [];

    public string $odooBranch = '';

    public string $odooGithubLogin = '';

    /** True when the GitHub App installation is on a personal user account (cannot auto-create repos). */
    public bool $odooGithubIsPersonal = false;

    public string $odooRepositoryQuery = '';

    public bool $odooAccountChanged = false;

    public bool $awaitingRepositoryChoice = false;

    public bool $associatingWithoutGithub = false;

    /**
     * This environment has no git_branch yet — show the one-time repository picker.
     * After associate, repo + branch stay locked on this panel.
     */
    public bool $needsEnvironmentGithubLink = false;

    public bool $associateRunning = false;

    public ?string $associateKey = null;

    public ?string $associateError = null;

    public string $ownerPackageBranch = '';

    /** @var list<string> */
    public array $ownerPackageBranches = [];

    public string $ownerPackageRepository = '';

    public bool $launchRunning = false;

    public int $launchStep = 0;

    public ?string $launchError = null;

    public ?string $launchKey = null;

    public string $odooCertificateStatus = '';

    public string $odooCertificateMessage = '';

    public array $odooDatabases = [];

    public string $githubLogin = '';

    /** @var list<array{login: string}> */
    public array $odooCollaborators = [];

    public function render()
    {
        return view('livewire.project.service.configuration');
    }

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->currentRoute = request()->route()->getName();
            $this->query = request()->query();
            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'name', 'team_id')
                ->where('uuid', request()->route('project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', request()->route('environment_uuid'))
                ->firstOrFail();
            $this->service = $environment->services()->whereUuid(request()->route('service_uuid'))->firstOrFail();

            $this->authorize('view', $this->service);

            $this->project = $project;
            $this->environment = $environment;
            $project->loadMissing('odooProfile');
            $environment->loadMissing('odooBranch');
            $this->odooIsOdoo = $this->service->supportsOdooJupyter();
            $this->syncOdooGithub();
            if ($this->odooIsOdoo) {
                $this->launchKey = 'launch-odoo-'.$this->service->uuid;
                $status = Cache::get($this->launchKey);
                if (is_array($status) && ($status['done'] ?? false) !== true && request()->query('launch') !== 'choose') {
                    return redirect()->route('project.show', ['project_uuid' => $project->uuid]);
                }
            }
            $this->awaitingRepositoryChoice = request()->query('launch') === 'choose'
                && blank($project->odooProfile?->git_repository);
            $this->associatingWithoutGithub = ! $this->awaitingRepositoryChoice
                && blank($project->odooProfile?->git_repository)
                && $this->odooGithubConnected
                && $this->odooIsOdoo;
            $this->needsEnvironmentGithubLink = $this->odooIsOdoo
                && $this->odooGithubConnected
                && blank($environment->odooBranch?->git_branch);
            if ($this->odooGithubIsPersonal && ($this->awaitingRepositoryChoice || $this->associatingWithoutGithub || $this->needsEnvironmentGithubLink)) {
                $this->odooRepoMode = 'existing';
            }
            if (
                $this->odooAccountChanged
                || $this->awaitingRepositoryChoice
                || $this->associatingWithoutGithub
                || $this->needsEnvironmentGithubLink
                || request()->query('odooPanel') === 'github'
            ) {
                $this->odooPanel = 'github';
            } elseif (request()->query('odooPanel') === 'owner-package' && isInstanceOwner()) {
                $this->odooPanel = 'owner-package';
            }
            if ($this->associatingWithoutGithub || $this->needsEnvironmentGithubLink) {
                if ($this->awaitingRepositoryChoice || $this->associatingWithoutGithub) {
                    $this->odooRepoMode = $this->odooGithubIsPersonal ? 'existing' : 'new';
                } else {
                    // New environment on a project that may already have a repo: pick once.
                    $this->odooRepoMode = 'existing';
                }
                $this->associateKey = 'associate-odoo-'.$this->service->uuid;
                $associateStatus = Cache::get($this->associateKey);
                if (is_array($associateStatus) && ($associateStatus['done'] ?? false) !== true && blank($associateStatus['error'] ?? null)) {
                    $this->associateRunning = true;
                } elseif (is_array($associateStatus) && filled($associateStatus['error'] ?? null)) {
                    $this->associateError = (string) $associateStatus['error'];
                }
                if ($this->odooRepoMode === 'existing' && auth()->user()?->can('update', $this->service)) {
                    $this->loadOdooRepositories();
                }
            }
            if ($this->odooIsOdoo && isInstanceOwner()) {
                $this->ownerPackageRepository = (string) (instanceSettings()->odoo_owner_repository ?? '');
                $this->ownerPackageBranch = (string) ($environment->odooBranch?->owner_package_branch
                    ?? instanceSettings()->odoo_owner_branch
                    ?? '');
            }
            if ($this->odooIsOdoo && request()->query('launch') === 'choose' && ! $this->awaitingRepositoryChoice && OdooGit::useHttps($this->service)) {
                $this->service->unsetRelation('applications');
                if ($this->service->server?->isFunctional()) {
                    OdooGit::startIfPossible($this->service);
                    $this->dispatch('success', __('HTTPS is being applied to the Odoo link. Sign in as admin when it finishes.'));
                }
            }
            $this->applications = $this->service->applications->sortBy(fn ($resource): int => $this->mountOrder((string) $resource->name))->values();
            $this->databases = $this->service->databases->sortBy(fn ($resource): int => $this->mountOrder((string) $resource->name))->values();
            $this->hideClientContainers();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function mountOrder(string $name): int
    {
        $name = strtolower($name);

        return match (true) {
            $name === 'odoo' => 0,
            str_contains($name, 'postgres') => 1,
            $name === 'jupyter' => 2,
            default => 9,
        };
    }

    public function listOdooDatabases(): void
    {
        try {
            $this->odooDatabases = OdooGit::databaseList($this->service);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function checkOdooCertificate(): void
    {
        try {
            $status = OdooGit::certificateStatus($this->service);
            $this->odooCertificateStatus = $status['status'];
            $this->odooCertificateMessage = __($status['message'], ['url' => $status['url']]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshServices()
    {
        $this->service->refresh();
        $this->applications = $this->service->applications->sort();
        $this->databases = $this->service->databases->sort();
        $this->hideClientContainers();
    }

    private function hideClientContainers(): void
    {
        if (! $this->odooIsOdoo || isInstanceAdmin()) {
            return;
        }

        $this->applications = $this->applications
            ->filter(fn ($resource): bool => OdooGit::clientSeesLog((string) $resource->name))
            ->values();
        $this->databases = $this->databases
            ->filter(fn ($resource): bool => OdooGit::clientSeesLog((string) $resource->name))
            ->values();
    }

    public function restartApplication($id)
    {
        try {
            $this->authorize('update', $this->service);
            $application = $this->service->applications->find($id);
            if ($application) {
                $application->restart();
                $this->dispatch('success', __('Service application restarted successfully.'));
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function restartDatabase($id)
    {
        try {
            $this->authorize('update', $this->service);
            $database = $this->service->databases->find($id);
            if ($database) {
                $database->restart();
                $this->dispatch('success', __('Service database restarted successfully.'));
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function connectOdooGithub(): mixed
    {
        try {
            $this->authorize('update', $this->service);
            $parameters = [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'service_uuid' => $this->service->uuid,
            ];
            $githubApp = OdooGit::beginConnect($this->project, 'project.service.configuration', $parameters);
            if (filled($githubApp->installation_id) && filled($githubApp->private_key_id)) {
                $this->syncOdooGithub();

                return null;
            }
            if (filled($githubApp->app_id)) {
                return redirect()->away(getInstallationPath($githubApp));
            }

            return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Start a new GitHub App for another account. Reusing beginConnect without forceNew
     * returns the already-installed app and the button appeared to do nothing.
     */
    public function changeOdooGithubAccount(): mixed
    {
        try {
            $this->authorize('update', $this->service);
            $githubApp = OdooGit::beginConnect(
                $this->project,
                'project.service.configuration',
                [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $this->environment->uuid,
                    'service_uuid' => $this->service->uuid,
                    'odooPanel' => 'github',
                ],
                forceNew: true,
            );
            $this->odooGithubConnected = false;
            $this->odooGithubAppId = null;
            $this->odooNeedsGithub = true;

            return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function inviteGithubUser(): void
    {
        try {
            $this->authorize('update', $this->service);
            $profile = $this->project->odooProfile;
            if ($profile === null) {
                return;
            }
            OdooGit::inviteGithubLogin($profile, $this->githubLogin);
            $this->githubLogin = '';
            $this->odooCollaborators = OdooGit::repositoryCollaborators($profile->fresh());
            $this->dispatch('success', __('Invitation sent.'));
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function removeGithubUser(string $login): void
    {
        try {
            $this->authorize('update', $this->service);
            $profile = $this->project->odooProfile;
            if ($profile === null) {
                return;
            }
            OdooGit::removeGithubLogin($profile, $login);
            $this->odooCollaborators = OdooGit::repositoryCollaborators($profile->fresh());
            $this->dispatch('success', __('Collaborator removed.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updatedOdooRepoMode(): void
    {
        if ($this->odooRepoMode === 'existing') {
            $this->loadOdooRepositories();
        }
    }

    public function updatedOdooGithubAppId(): void
    {
        $this->odooRepositories = [];
        $this->odooRepositoriesLoaded = false;
        $this->odooRepositoriesLoading = false;
        $this->odooGithubBranches = [];
        $this->odooBranch = '';
        if ($this->odooRepoMode === 'existing') {
            $this->loadOdooRepositories();
        }
    }

    public function loadOdooRepositories(): void
    {
        if ($this->odooRepositoriesLoaded || $this->odooRepositoriesLoading) {
            return;
        }

        $this->odooRepositoriesLoading = true;
        try {
            $this->authorize('update', $this->service);
            $this->odooRepositories = OdooGit::repositories($this->odooGithubApp());
            $this->odooRepositoriesLoaded = true;
            $this->odooGithubLogin = (string) ($this->odooRepositories[0]['owner'] ?? $this->odooGithubLogin);
            $this->odooGithubBranches = [];
            $this->odooBranch = '';
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->odooRepositoriesLoading = false;
    }

    public function reloadOdooRepositories(): void
    {
        $this->odooRepositoriesLoaded = false;
        $this->odooRepositoriesLoading = false;
        $this->loadOdooRepositories();
    }

    public function pickOdooRepository(int $id): void
    {
        $this->odooRepositoryId = $id;
        $this->updatedOdooRepositoryId();
    }

    public function updatedOdooRepositoryId(): void
    {
        if (blank($this->odooRepositoryId)) {
            return;
        }

        try {
            $repository = $this->selectedOdooRepository();
            $this->environment->loadMissing('odooBranch');
            $this->odooGithubBranches = OdooGit::branchNames($this->odooGithubApp(), $repository['owner'], $repository['name']);
            $stored = (string) ($this->environment->odooBranch?->git_branch ?? '');
            $this->odooBranch = in_array($stored, $this->odooGithubBranches, true)
                ? $stored
                : (in_array($repository['default_branch'], $this->odooGithubBranches, true)
                    ? $repository['default_branch']
                    : (string) ($this->odooGithubBranches[0] ?? ''));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updatedOdooPanel(string $value): void
    {
        if (
            $value === 'github'
            && $this->odooIsOdoo
            && $this->odooGithubConnected
            && $this->needsEnvironmentGithubLink
            && $this->odooRepoMode === 'existing'
            && auth()->user()?->can('update', $this->service)
        ) {
            $this->loadOdooRepositories();
        }
    }

    public function loadLinkedOdooBranches(): void
    {
        try {
            $this->authorize('update', $this->service);
            [$owner, $name] = $this->linkedRepository();
            $this->odooGithubBranches = OdooGit::branchNames($this->odooGithubApp(), $owner, $name);
            $this->environment->loadMissing('odooBranch');
            $stored = (string) ($this->environment->odooBranch?->git_branch ?? '');
            $this->odooBranch = in_array($stored, $this->odooGithubBranches, true)
                ? $stored
                : (string) ($this->odooGithubBranches[0] ?? '');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function saveLinkedOdooBranch(): void
    {
        try {
            $this->authorize('update', $this->service);
            if ($this->odooBranchIsSyncing()) {
                return;
            }
            $profile = $this->project->odooProfile;
            [$owner, $name] = $this->linkedRepository();
            $branches = $this->odooGithubBranches !== []
                ? $this->odooGithubBranches
                : OdooGit::branchNames($this->odooGithubApp(), $owner, $name);
            $branch = trim($this->odooBranch);
            OdooGit::attachExisting(
                $this->project,
                $this->odooGithubApp(),
                (string) $profile->git_repository,
                (int) $profile->repository_id,
                $branches,
                $this->environment,
                $branch,
            );
            $this->environment->unsetRelation('odooBranch');
            $this->environment->load('odooBranch');
            $row = $this->environment->odooBranch;
            if ($row !== null) {
                $row->update([
                    'status' => 'updating',
                    'service_id' => $row->service_id ?: $this->service->id,
                ]);
                SyncOdooAddonsJob::dispatch(odooEnvironmentBranchId: $row->id);
            }
            $this->dispatch('success', __('Cloning branch :branch into this environment…', ['branch' => $branch]));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function refreshOdooBranchStatus(): void
    {
        $wasUpdating = $this->odooBranchIsSyncing();
        $this->environment->unsetRelation('odooBranch');
        $this->environment->load('odooBranch');
        if ($wasUpdating && ! $this->odooBranchIsSyncing()) {
            $branch = (string) ($this->environment->odooBranch?->git_branch ?? '');
            $failed = ($this->environment->odooBranch?->status ?? '') === 'failed';
            if ($failed) {
                $this->dispatch('error', __('Cloning branch :branch failed. Try saving again.', ['branch' => $branch]));
            } elseif ($branch !== '') {
                $this->dispatch('success', __('GitHub branch :branch is ready.', ['branch' => $branch]));
            }
        }
    }

    public function odooBranchIsSyncing(): bool
    {
        return ($this->environment->odooBranch?->status ?? '') === 'updating';
    }

    public function loadOwnerPackageBranches(): void
    {
        abort_unless(isInstanceOwner(), 403);

        try {
            $this->authorize('update', $this->service);
            $repository = OdooGit::normalizeRepository($this->ownerPackageRepository !== ''
                ? $this->ownerPackageRepository
                : (string) (instanceSettings()->odoo_owner_repository ?? ''));
            if (preg_match('#\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', $repository) !== 1) {
                $this->dispatch('error', __('Set the owner package repository in Settings → Odoo first.'));

                return;
            }

            $this->ownerPackageRepository = $repository;
            $githubApp = OdooGit::ownerGithubApp();
            if (! $githubApp instanceof GithubApp) {
                $this->dispatch('error', __('Connect a GitHub App before loading branches.'));

                return;
            }

            $this->ownerPackageBranches = OdooGit::repositoryBranches($githubApp, $repository);
            if ($this->ownerPackageBranches === []) {
                $this->dispatch('error', __('GitHub did not return branches for that repository.'));

                return;
            }

            if (! in_array($this->ownerPackageBranch, $this->ownerPackageBranches, true)) {
                $this->ownerPackageBranch = $this->ownerPackageBranches[0];
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function installOwnerPackage(): void
    {
        abort_unless(isInstanceOwner(), 403);

        try {
            $this->authorize('update', $this->service);
            OdooGit::installOwnerPackageIntoService($this->service, $this->ownerPackageBranch);
            $this->environment->unsetRelation('odooBranch');
            $this->environment->load('odooBranch');
            $this->ownerPackageBranch = (string) ($this->environment->odooBranch?->owner_package_branch ?? $this->ownerPackageBranch);
            $this->dispatch('success', __('Owner package branch :branch is being copied into this project volume.', [
                'branch' => $this->ownerPackageBranch,
            ]));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function launchWithoutGithub(): mixed
    {
        try {
            $this->authorize('update', $this->service);
            if (! $this->service->supportsOdooJupyter()) {
                return null;
            }

            return $this->startPlannedLaunch();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function associateOdooRepository(): mixed
    {
        try {
            $this->authorize('update', $this->service);
            if (! $this->odooGithubConnected) {
                throw new InvalidArgumentException('Connect a GitHub account before associating a repository.');
            }
            if ($this->project->odooProfile === null) {
                $version = OdooVersion::current((string) $this->service->docker_compose_raw) ?? '18';
                $this->project->enableOdoo($version);
                $this->project->refresh();
            }

            $githubApp = $this->odooGithubApp();

            if ($this->odooRepoMode === 'new') {
                if ($this->odooGithubIsPersonal) {
                    throw new RuntimeException(OdooGit::cannotAutoCreateOnPersonalAccountMessage());
                }
                if ($this->associatingWithoutGithub || (! $this->awaitingRepositoryChoice && blank($this->project->odooProfile?->git_repository))) {
                    // Create + push in a queue job (console can wait out secondary GitHub limits;
                    // Livewire must not hold the request or the CSRF token expires → "Page expired").
                    return $this->queueAssociateOdooRepository($githubApp);
                }

                return $this->startPlannedLaunch(createRepository: true);
            }

            $repository = $this->selectedOdooRepository();
            $branch = trim($this->odooBranch);
            $branches = $this->odooGithubBranches;
            if ($branches !== [] && ! in_array($branch, $branches, true)) {
                throw new InvalidArgumentException('That branch does not exist on this GitHub repository. Use the branch name from GitHub, not the environment name.');
            }
            if ($branch === '') {
                throw new InvalidArgumentException('Choose a GitHub branch for this environment.');
            }

            // Attach + optional launch must run in a job — GitHub / CSRF “Page expired”.
            return $this->queueAssociateOdooRepository(
                $githubApp,
                existingFullName: $repository['full_name'],
                existingRepositoryId: (int) $repository['id'],
                existingBranch: $branch,
                existingBranches: $branches,
                startLaunch: $this->awaitingRepositoryChoice || $this->associatingWithoutGithub,
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @param  list<string>  $existingBranches
     */
    private function queueAssociateOdooRepository(
        GithubApp $githubApp,
        ?string $existingFullName = null,
        ?int $existingRepositoryId = null,
        ?string $existingBranch = null,
        array $existingBranches = [],
        bool $startLaunch = false,
    ): null {
        $this->associateKey = 'associate-odoo-'.$this->service->uuid;
        Cache::put($this->associateKey, ['step' => 1, 'done' => false, 'error' => null], now()->addMinutes(30));
        AssociateOdooGithubRepositoryJob::dispatch(
            $this->service->id,
            $this->associateKey,
            (int) auth()->id(),
            (int) $githubApp->id,
            $existingFullName,
            $existingRepositoryId,
            $existingBranch,
            $existingBranches,
            $startLaunch,
        );
        $this->associateRunning = true;
        $this->associateError = null;

        return null;
    }

    public function refreshLaunchProgress(): void
    {
        if (! is_string($this->launchKey) || $this->launchKey === '') {
            return;
        }

        $status = Cache::get($this->launchKey);
        if (! is_array($status)) {
            return;
        }

        $this->launchStep = (int) ($status['step'] ?? 1);
        $this->launchError = is_string($status['error'] ?? null) ? $status['error'] : null;
        $redirect = $status['redirect'] ?? null;
        if (($status['done'] ?? false) === true && is_array($redirect) && is_string($redirect['name'] ?? null)) {
            $this->launchRunning = false;
            $parameters = is_array($redirect['parameters'] ?? null) ? $redirect['parameters'] : [];
            $this->redirectRoute($redirect['name'], $parameters);
        }
    }

    public function refreshAssociateProgress(): void
    {
        if (! is_string($this->associateKey) || $this->associateKey === '') {
            return;
        }

        $status = Cache::get($this->associateKey);
        if (! is_array($status)) {
            return;
        }

        $this->associateError = is_string($status['error'] ?? null) ? $status['error'] : null;
        if (filled($this->associateError)) {
            $this->associateRunning = false;

            return;
        }

        if (($status['done'] ?? false) === true) {
            $this->associateRunning = false;
            $this->project->unsetRelation('odooProfile');
            $this->project->load('odooProfile');
            $this->environment->unsetRelation('odooBranch');
            $this->environment->load('odooBranch');
            $this->syncOdooGithub();
            $this->associatingWithoutGithub = false;
            $this->awaitingRepositoryChoice = false;
            $this->needsEnvironmentGithubLink = blank($this->environment->odooBranch?->git_branch);
            $redirect = $status['redirect'] ?? null;
            $message = is_string($status['message'] ?? null)
                ? $status['message']
                : __('Repository created. Custom addons were pushed when present.');
            Cache::forget($this->associateKey);
            $this->associateKey = null;
            if (is_array($redirect) && is_string($redirect['name'] ?? null)) {
                $parameters = is_array($redirect['parameters'] ?? null) ? $redirect['parameters'] : [];
                $this->redirectRoute($redirect['name'], $parameters);

                return;
            }
            $this->dispatch('success', $message);
        }
    }

    public function dismissAssociateError(): void
    {
        $this->associateRunning = false;
        $this->associateError = null;
        if (is_string($this->associateKey) && $this->associateKey !== '') {
            Cache::forget($this->associateKey);
        }
        $this->associateKey = null;
    }

    public function dismissLaunchError(): void
    {
        $this->launchRunning = false;
        $this->launchError = null;
        $this->launchStep = 0;
    }

    private function startPlannedLaunch(bool $createRepository = false): mixed
    {
        $this->launchKey = 'launch-odoo-'.$this->service->uuid;
        Cache::put($this->launchKey, ['step' => 1, 'done' => false, 'error' => null, 'redirect' => null], now()->addMinutes(30));
        LaunchOdooProjectJob::dispatch(
            $this->service->id,
            $this->launchKey,
            (int) auth()->id(),
            $createRepository ? (int) $this->odooGithubAppId : null,
            $createRepository,
        );

        return redirect()->route('project.show', ['project_uuid' => $this->project->uuid]);
    }

    private function syncOdooGithub(): void
    {
        $this->odooIsOdoo = $this->service->supportsOdooJupyter();
        $this->project->loadMissing('odooProfile');
        $app = OdooGit::appForProject($this->project, auth()->id());
        $this->odooGithubConnected = $app instanceof GithubApp;
        $this->odooNeedsGithub = $this->odooIsOdoo && ! $this->odooGithubConnected;
        $this->odooGithubAppId = $app?->id;
        $this->odooGithubApps = $app instanceof GithubApp
            ? [['value' => $app->id, 'label' => $app->name]]
            : [];
        $this->odooGithubIsPersonal = false;
        if ($app instanceof GithubApp && filled($app->installation_id) && filled($app->private_key_id)) {
            try {
                $account = OdooGit::installationAccount($app);
                $this->odooGithubLogin = $account['login'];
                $this->odooGithubIsPersonal = $account['type'] === 'User';
            } catch (\Throwable) {
                // Keep the GitHub panel usable if GitHub is briefly unreachable.
            }
        }
        if ($app instanceof GithubApp && auth()->id() !== null) {
            OdooGit::rememberForUser((int) auth()->id(), (int) $this->project->team_id, $app);
        }
        $profile = $this->project->odooProfile;
        $profileApp = (int) ($profile?->github_app_id ?? 0);
        // First successful connect: remember the app on the project (do not overwrite a deliberate Change account).
        if ($app instanceof GithubApp && $profile !== null && $profileApp === 0) {
            $profile->update(['github_app_id' => $app->id]);
            $profileApp = (int) $app->id;
            $this->project->unsetRelation('odooProfile');
            $this->project->load('odooProfile');
            $profile = $this->project->odooProfile;
        }
        $this->odooAccountChanged = $this->odooGithubConnected
            && filled($profile?->git_repository)
            && $profileApp > 0
            && $profileApp !== (int) $this->odooGithubAppId;
        if ($this->odooAccountChanged) {
            $this->odooRepositoryId = null;

            return;
        }
        if (filled($profile?->git_repository)) {
            $this->odooRepoMode = 'existing';
            $this->odooRepositoryId = $profile->repository_id;
        }
        $this->odooCollaborators = [];
        if ($profile !== null && filled($profile->git_repository)) {
            try {
                $this->odooCollaborators = OdooGit::repositoryCollaborators($profile);
            } catch (\Throwable) {
                $this->odooCollaborators = [];
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function linkedRepository(): array
    {
        $repository = (string) $this->project->odooProfile?->git_repository;
        $parts = explode('/', $repository, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('Choose a repository.');
        }

        return [$parts[0], $parts[1]];
    }

    private function odooGithubApp(): GithubApp
    {
        $app = OdooGit::connectedApps((int) $this->project->team_id)->firstWhere('id', (int) $this->odooGithubAppId);
        if (! $app instanceof GithubApp) {
            throw new InvalidArgumentException('Connect a GitHub account before associating a repository.');
        }

        return $app;
    }

    /**
     * @return array{id: int, full_name: string, owner: string, name: string, default_branch: string}
     */
    private function selectedOdooRepository(bool $refresh = false): array
    {
        if ($refresh || $this->odooRepositories === []) {
            $this->odooRepositories = OdooGit::repositories($this->odooGithubApp());
        }
        $repository = collect($this->odooRepositories)->firstWhere('id', (int) $this->odooRepositoryId);
        if (! is_array($repository)) {
            throw new InvalidArgumentException('Choose a repository.');
        }

        return $repository;
    }
}
