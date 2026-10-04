<?php

namespace App\Livewire\Project;

use App\Models\GithubApp;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Services\ProjectIconStorageService;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use App\Support\OdooStaging;
use App\Support\OdooVersion;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

class Edit extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Project $project;

    public string $name;

    public ?string $description = null;

    public string $odooVersion = '18';

    public bool $odooStagingIsEmpty = false;

    public bool $canCloneProductionToStaging = false;

    public bool $odooGithubConnected = false;

    public ?int $odooGithubAppId = null;

    public ?int $odooRepositoryId = null;

    public string $odooLaunchCategory = 'staging';

    public string $odooSubdomain = '';

    public int $odooWorkers = 0;

    public string $githubLogin = '';

    /** @var list<array{login: string}> */
    public array $odooCollaborators = [];

    /** @var list<array{value: int, label: string}> */
    public array $odooGithubApps = [];

    /** @var list<array{id: int, full_name: string, owner: string, name: string, default_branch: string}> */
    public array $odooRepositories = [];

    /** @var list<string> */
    public array $odooGithubBranches = [];

    /** @var array<int|string, string> */
    public array $odooEnvironmentBranches = [];

    /** @var list<array{id: int, name: string, status: string}> */
    public array $odooTrackedEnvironments = [];

    public $icon;

    public function uploadIcon(ProjectIconStorageService $iconStorage): bool
    {
        try {
            $this->authorize('update', $this->project);
            $this->validate([
                'icon' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
            ]);
            $iconStorage->storeProject($this->project, $this->icon);
            $this->reset('icon');
            $this->project->refresh();
            $this->dispatch('success', __('Project icon updated.'));

            return true;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }
    }

    public function removeIcon(ProjectIconStorageService $iconStorage): void
    {
        try {
            $this->authorize('update', $this->project);
            $iconStorage->deleteProject($this->project);
            $this->project->refresh();
            $this->dispatch('success', __('Project icon removed.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function mount(string $project_uuid)
    {
        try {
            $this->project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
            $this->syncOdooState();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->project->update([
                'name' => $this->name,
                'description' => $this->description,
            ]);
        } else {
            $this->name = $this->project->name;
            $this->description = $this->project->description;
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->project);
            $this->syncData(true);
            $this->dispatch('success', __('Project updated.'));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function enableOdoo(): void
    {
        try {
            $this->authorize('update', $this->project);
            if ($this->project->odooProfile !== null) {
                $this->odooVersion = (string) $this->project->odooProfile->odoo_version;
                $this->dispatch('success', __('Odoo profile saved. Nothing was deployed.'));

                return;
            }
            $validated = Validator::make([
                'odooVersion' => $this->odooVersion,
            ], [
                'odooVersion' => ['required', Rule::in(OdooVersion::SUPPORTED)],
            ])->validate();

            $this->project->enableOdoo($validated['odooVersion']);
            $this->project->refresh();
            $this->syncOdooState();
            $this->dispatch('success', __('Odoo profile saved. Nothing was deployed.'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function syncOdooState(): void
    {
        $this->project->load('odooProfile');
        $profile = $this->project->odooProfile;
        $this->odooVersion = $profile?->odoo_version ?? $this->odooVersion;
        $stagings = OdooStaging::stagingEnvironments($this->project);
        $this->odooStagingIsEmpty = $stagings->isNotEmpty() && $stagings->every(fn ($environment): bool => $environment->isEmpty());
        $this->canCloneProductionToStaging = $profile !== null && $this->project->canCreateStagingEnvironment();
        $connected = OdooGit::connectedApps($this->project->team_id);
        $this->odooGithubConnected = $connected->isNotEmpty();
        $this->odooGithubAppId = $profile?->github_app_id;
        if ($this->odooGithubAppId === null) {
            $userAppId = auth()->id() === null ? null : DB::table('team_user')
                ->where('user_id', auth()->id())
                ->where('team_id', $this->project->team_id)
                ->value('github_app_id');
            if ($userAppId !== null && $connected->contains('id', (int) $userAppId)) {
                $this->odooGithubAppId = (int) $userAppId;
            } elseif ($connected->count() === 1) {
                $this->odooGithubAppId = $connected->first()->id;
            }
        }
        $this->odooRepositoryId = $profile?->repository_id;
        $this->odooGithubApps = $connected
            ->map(fn (GithubApp $app): array => ['value' => $app->id, 'label' => $app->name])
            ->all();
        $branchRows = OdooEnvironmentBranch::query()
            ->whereIn('environment_id', $this->project->environments()->pluck('id'))
            ->get()
            ->keyBy('environment_id');
        $this->odooTrackedEnvironments = $this->project->environments()->orderBy('name')->get()
            ->filter(fn ($environment): bool => OdooGit::tracksBranch($environment))
            ->map(fn ($environment): array => [
                'id' => $environment->id,
                'name' => $environment->name,
                'status' => (string) ($branchRows->get($environment->id)?->status ?? 'idle'),
            ])
            ->values()
            ->all();
        $this->odooEnvironmentBranches = $branchRows
            ->mapWithKeys(fn (OdooEnvironmentBranch $row): array => [$row->environment_id => $row->git_branch])
            ->all();
        $this->odooSubdomain = (string) ($profile?->subdomain ?? '');
        $this->odooWorkers = (int) ($profile?->workers ?? 0);
        $this->odooCollaborators = [];
        if ($profile !== null && filled($profile->git_repository)) {
            try {
                $this->odooCollaborators = OdooGit::repositoryCollaborators($profile);
            } catch (\Throwable) {
                $this->odooCollaborators = [];
            }
        }
    }

    public function saveOdooRuntime(): void
    {
        try {
            $this->authorize('update', $this->project);
            $profile = $this->project->odooProfile;
            if ($profile === null) {
                return;
            }
            $subdomain = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->odooSubdomain)), '-');
            if (trim($this->odooSubdomain) !== '' && $subdomain === '') {
                $this->dispatch('error', __('The subdomain can only use letters, numbers, and hyphens.'));

                return;
            }
            $workers = max(0, min(32, (int) $this->odooWorkers));
            $profile->update([
                'subdomain' => $subdomain === '' ? null : $subdomain,
                'workers' => $workers,
            ]);
            OdooEnvironmentBranch::query()
                ->whereIn('environment_id', $this->project->environments()->pluck('id'))
                ->update(['workers' => $workers]);
            $this->project->load('environments.services.server');
            foreach ($this->project->environments as $environment) {
                foreach ($environment->services as $service) {
                    if (! OdooJupyter::isOdooCompose((string) $service->docker_compose_raw)) {
                        continue;
                    }
                    OdooGit::applyProjectHost($service);
                }
            }
            $this->odooSubdomain = $subdomain;
            $this->odooWorkers = $workers;
            $this->dispatch('success', __('Project runtime saved. The next start applies the workers and the address.'));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function inviteGithubUser(): void
    {
        try {
            $this->authorize('update', $this->project);
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
            $this->authorize('update', $this->project);
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

    public function connectOdooGithub(): void
    {
        try {
            $this->authorize('update', $this->project);
            $githubApp = OdooGit::beginConnect($this->project);
            redirectRoute($this, 'source.github.show', ['github_app_uuid' => $githubApp->uuid]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function updatedOdooRepositoryId(): void
    {
        if (blank($this->odooRepositoryId)) {
            return;
        }

        $this->loadOdooBranches();
    }

    public function launchOdooEnvironment(): void
    {
        try {
            $this->authorize('update', $this->project);
            if ($this->odooGithubConnected) {
                $environment = OdooGit::launchEnvironment(
                    $this->project,
                    $this->odooGithubApp(),
                    $this->odooLaunchCategory,
                );
                $message = __('Environment :name launched on :branch.', [
                    'name' => $environment->name,
                    'branch' => $environment->name,
                ]);
            } else {
                $environment = OdooGit::launchLocalEnvironment($this->project, $this->odooLaunchCategory);
                $message = __('Environment :name is ready. JupyterLab shows its addon files until a repository is connected.', [
                    'name' => $environment->name,
                ]);
            }
            $this->project->refresh();
            $this->syncOdooState();
            $this->dispatch('success', $message);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function cloneProductionAsStaging(): void
    {
        try {
            $this->authorize('update', $this->project);
            $staging = $this->project->cloneProductionAsStaging();
            $this->project->refresh();
            $this->syncOdooState();
            $this->dispatch('success', __('Staging :name created from production. Production was left as it is.', ['name' => $staging->name]));
        } catch (RuntimeException $e) {
            $this->dispatch('error', __($e->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function loadOdooRepositories(): void
    {
        try {
            $this->authorize('update', $this->project);
            $this->odooRepositories = OdooGit::repositories($this->odooGithubApp());
            $this->odooGithubBranches = [];
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function loadOdooBranches(): void
    {
        try {
            $this->authorize('update', $this->project);
            $repository = $this->selectedOdooRepository(refresh: true);
            $this->odooGithubBranches = OdooGit::branchNames($this->odooGithubApp(), $repository['owner'], $repository['name']);
            foreach ($this->odooTrackedEnvironments as $environment) {
                $current = $this->odooEnvironmentBranches[$environment['id']] ?? null;
                if (filled($current) || strcasecmp($environment['name'], 'production') !== 0) {
                    continue;
                }
                if (in_array($repository['default_branch'], $this->odooGithubBranches, true)) {
                    $this->odooEnvironmentBranches[$environment['id']] = $repository['default_branch'];
                }
            }
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function saveOdooGit(): void
    {
        try {
            $this->authorize('update', $this->project);
            $app = $this->odooGithubApp();
            $repository = $this->selectedOdooRepository(refresh: true);
            $branches = OdooGit::branchNames($app, $repository['owner'], $repository['name']);
            OdooGit::assign(
                $this->project,
                $app,
                $repository['full_name'],
                $repository['id'],
                $branches,
                $this->odooEnvironmentBranches,
            );
            $this->project->refresh();
            $this->syncOdooState();
            $this->dispatch('success', __('GitHub branches saved. Nothing was deployed.'));
        } catch (InvalidArgumentException $exception) {
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function odooGithubApp(): GithubApp
    {
        if ($this->odooGithubAppId === null) {
            throw new InvalidArgumentException('Choose a GitHub App.');
        }

        return GithubApp::query()
            ->where(function ($query) {
                $query->where('team_id', $this->project->team_id)->orWhere('is_system_wide', true);
            })
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->findOrFail($this->odooGithubAppId);
    }

    /**
     * @return array{id: int, full_name: string, owner: string, name: string, default_branch: string}
     */
    private function selectedOdooRepository(bool $refresh): array
    {
        if ($refresh || $this->odooRepositories === []) {
            $this->odooRepositories = OdooGit::repositories($this->odooGithubApp());
        }
        $repository = collect($this->odooRepositories)->firstWhere('id', (int) $this->odooRepositoryId);
        if (! is_array($repository)) {
            throw new InvalidArgumentException('Choose a repository from this GitHub App.');
        }

        return $repository;
    }
}
