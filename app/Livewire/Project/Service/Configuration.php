<?php

namespace App\Livewire\Project\Service;

use App\Jobs\LaunchOdooProjectJob;
use App\Models\GithubApp;
use App\Models\Service;
use App\Support\OdooGit;
use App\Support\OdooStaging;
use App\Support\OdooVersion;
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

    public string $odooRepositoryQuery = '';

    public bool $odooAccountChanged = false;

    public bool $awaitingRepositoryChoice = false;

    public bool $launchRunning = false;

    public int $launchStep = 0;

    public ?string $launchError = null;

    public ?string $launchKey = null;

    public string $odooCertificateStatus = '';

    public string $odooCertificateMessage = '';

    public array $odooDatabases = [];

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
            if ($this->odooAccountChanged || $this->awaitingRepositoryChoice) {
                $this->odooPanel = 'github';
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

    public function connectOdooGithub(): void
    {
        try {
            $this->authorize('update', $this->service);
            $githubApp = OdooGit::beginConnect($this->project, 'project.service.configuration', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'service_uuid' => $this->service->uuid,
            ]);
            redirectRoute($this, 'source.github.show', ['github_app_uuid' => $githubApp->uuid]);
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

    public function loadLinkedOdooBranches(): void
    {
        try {
            $this->authorize('update', $this->service);
            [$owner, $name] = $this->linkedRepository();
            $this->odooGithubBranches = OdooGit::branchNames($this->odooGithubApp(), $owner, $name);
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
            $this->dispatch('success', __('GitHub branch :branch.', ['branch' => $branch]));
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

            if ($this->odooRepoMode === 'new') {
                $classification = OdooStaging::isStagingName($this->environment->name) ? 'staging' : 'production';
                OdooGit::launchEnvironment($this->project, $this->odooGithubApp(), $classification);
                $this->syncOdooGithub();

                return $this->startPlannedLaunch();
            }

            $repository = $this->selectedOdooRepository();
            $branches = OdooGit::branchNames($this->odooGithubApp(), $repository['owner'], $repository['name']);
            $branch = trim($this->odooBranch);
            if (! in_array($branch, $branches, true)) {
                throw new InvalidArgumentException('That branch does not exist on this GitHub repository. Use the branch name from GitHub, not the environment name.');
            }
            OdooGit::attachExisting(
                $this->project,
                $this->odooGithubApp(),
                $repository['full_name'],
                (int) $repository['id'],
                $branches,
                $this->environment,
                $branch,
            );
            $this->syncOdooGithub();

            return $this->startPlannedLaunch();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
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

    public function dismissLaunchError(): void
    {
        $this->launchRunning = false;
        $this->launchError = null;
        $this->launchStep = 0;
    }

    private function startPlannedLaunch(): mixed
    {
        OdooGit::cloneIntoService($this->service);
        $this->launchKey = 'launch-odoo-'.$this->service->uuid;
        Cache::put($this->launchKey, ['step' => 1, 'done' => false, 'error' => null, 'redirect' => null], now()->addMinutes(30));
        LaunchOdooProjectJob::dispatch(
            $this->service->id,
            $this->launchKey,
            (int) auth()->id(),
        );

        return redirect()->route('project.show', ['project_uuid' => $this->project->uuid]);
    }

    private function syncOdooGithub(): void
    {
        $this->odooIsOdoo = $this->service->supportsOdooJupyter();
        $app = OdooGit::userApp((int) $this->project->team_id, auth()->id());
        $this->odooGithubConnected = $app instanceof GithubApp;
        $this->odooNeedsGithub = $this->odooIsOdoo && ! $this->odooGithubConnected;
        $this->odooGithubAppId = $app?->id;
        $this->odooGithubApps = $app instanceof GithubApp
            ? [['value' => $app->id, 'label' => $app->name]]
            : [];
        if ($app instanceof GithubApp && auth()->id() !== null) {
            OdooGit::rememberForUser((int) auth()->id(), (int) $this->project->team_id, $app);
        }
        $profile = $this->project->odooProfile;
        $profileApp = (int) ($profile?->github_app_id ?? 0);
        $this->odooAccountChanged = $this->odooGithubConnected
            && filled($profile?->git_repository)
            && $profileApp !== (int) $this->odooGithubAppId;
        if ($this->odooAccountChanged) {
            $this->odooRepositoryId = null;

            return;
        }
        if (filled($profile?->git_repository)) {
            $this->odooRepoMode = 'existing';
            $this->odooRepositoryId = $profile->repository_id;
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
