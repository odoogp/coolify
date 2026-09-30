<?php

namespace App\Livewire\Project\Service;

use App\Models\GithubApp;
use App\Models\Service;
use App\Support\OdooGit;
use App\Support\OdooStaging;
use App\Support\OdooVersion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    public bool $odooGithubConnected = false;

    public string $odooRepoMode = 'new';

    public ?int $odooGithubAppId = null;

    public array $odooGithubApps = [];

    public array $odooRepositories = [];

    public ?int $odooRepositoryId = null;

    public array $odooGithubBranches = [];

    public string $odooBranch = '';

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
            $this->odooIsOdoo = $this->service->supportsOdooJupyter();
            $this->syncOdooGithub();
            $this->applications = $this->service->applications->sort();
            $this->databases = $this->service->databases->sort();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshServices()
    {
        $this->service->refresh();
        $this->applications = $this->service->applications->sort();
        $this->databases = $this->service->databases->sort();
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

    public function loadOdooRepositories(): void
    {
        try {
            $this->authorize('update', $this->service);
            $this->odooRepositories = OdooGit::repositories($this->odooGithubApp());
            $this->odooGithubBranches = [];
            $this->odooBranch = '';
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updatedOdooRepositoryId(): void
    {
        if (blank($this->odooRepositoryId)) {
            return;
        }

        try {
            $repository = $this->selectedOdooRepository();
            $this->odooGithubBranches = OdooGit::branchNames($this->odooGithubApp(), $repository['owner'], $repository['name']);
            $this->odooBranch = in_array($repository['default_branch'], $this->odooGithubBranches, true)
                ? $repository['default_branch']
                : (string) ($this->odooGithubBranches[0] ?? '');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function associateOdooRepository(): void
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
                $environment = OdooGit::launchEnvironment($this->project, $this->odooGithubApp(), $classification);
                $this->syncOdooGithub();
                $this->dispatch('success', __('Environment :name launched on :branch.', [
                    'name' => $environment->name,
                    'branch' => $environment->name,
                ]));

                return;
            }

            $repository = $this->selectedOdooRepository(refresh: true);
            $branches = OdooGit::branchNames($this->odooGithubApp(), $repository['owner'], $repository['name']);
            OdooGit::attachExisting(
                $this->project,
                $this->odooGithubApp(),
                $repository['full_name'],
                (int) $repository['id'],
                $branches,
                $this->environment,
                $this->odooBranch,
            );
            $this->syncOdooGithub();
            $this->dispatch('success', __('Repository associated. This environment uses :branch.', [
                'branch' => $this->odooBranch,
            ]));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function syncOdooGithub(): void
    {
        $this->odooIsOdoo = $this->service->supportsOdooJupyter();
        $connected = OdooGit::connectedApps((int) $this->project->team_id);
        $this->odooGithubConnected = $connected->isNotEmpty();
        $this->odooNeedsGithub = $this->odooIsOdoo && ! $this->odooGithubConnected;
        $this->odooGithubApps = $connected
            ->map(fn (GithubApp $app): array => ['value' => $app->id, 'label' => $app->name])
            ->all();
        $profile = $this->project->odooProfile;
        $this->odooGithubAppId = $profile?->github_app_id ?: ($connected->count() === 1 ? $connected->first()->id : $this->odooGithubAppId);
        if (filled($profile?->git_repository)) {
            $this->odooRepoMode = 'existing';
            $this->odooRepositoryId = $profile->repository_id;
        }
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
