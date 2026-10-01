<?php

namespace App\Livewire\Project;

use App\Jobs\CloneOdooStagingJob;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Rules\ValidGitBranch;
use App\Services\AdminCreationQuota;
use App\Support\OdooStaging;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;

class Show extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public string $name;

    public ?string $description = null;

    public ?string $selectedEnvironmentUuid = null;

    public bool $showCloneWizard = false;

    public string $cloneAddons = 'copy';

    public string $stagingBranch = '';

    public bool $cloneRunning = false;

    public int $cloneStep = 0;

    public ?string $cloneError = null;

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
            $this->project = Project::where('team_id', currentTeam()->id)
                ->where('uuid', $project_uuid)
                ->with([
                    'environments' => fn ($query) => $query
                        ->withCount([
                            'applications',
                            'services',
                            'postgresqls',
                            'redis',
                            'keydbs',
                            'dragonflies',
                            'clickhouses',
                            'mongodbs',
                            'mysqls',
                            'mariadbs',
                        ])
                        ->orderBy('created_at'),
                ])
                ->firstOrFail();
            $this->loadCloneProgress();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('create', Environment::class);
            $this->validate();
            $environment = app(AdminCreationQuota::class)->createEnvironment(auth()->user(), $this->project, [
                'name' => $this->name,
                'uuid' => new_public_id(),
            ]);

            return redirectRoute($this, 'project.resource.index', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function openCloneWizard(): void
    {
        $production = $this->project->environments->first(
            fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0
        );
        if ($production instanceof Environment) {
            $this->selectedEnvironmentUuid = $production->uuid;
        }
        $this->stagingBranch = OdooStaging::nextName($this->project);
        $this->showCloneWizard = true;
    }

    public function selectEnvironment(string $uuid): void
    {
        $this->selectedEnvironmentUuid = $uuid;
        $this->showCloneWizard = false;
    }

    public function cloneToStaging()
    {
        try {
            $this->authorize('update', $this->project);
            $this->project->load('odooProfile.githubApp', 'environments.odooBranch');
            $selected = $this->project->environments->firstWhere('uuid', $this->selectedEnvironmentUuid);
            if (! $selected instanceof Environment || strcasecmp($selected->name, 'production') !== 0) {
                throw new RuntimeException('Clone starts from the production environment.');
            }

            $branch = trim($this->stagingBranch);
            if ($branch === '') {
                $branch = OdooStaging::nextName($this->project);
            }
            if (in_array($branch, $this->usedOdooBranches(), true)) {
                throw new InvalidArgumentException('That branch is already used by this repository.');
            }
            $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
            if ($check->fails()) {
                throw new InvalidArgumentException('The GitHub branch name is invalid.');
            }

            $this->cloneError = null;
            $this->cloneStep = 1;
            $this->cloneRunning = true;
            $this->showCloneWizard = false;
            Cache::put($this->cloneCacheKey(), [
                'step' => 1,
                'done' => false,
                'error' => null,
                'url' => null,
            ], now()->addMinutes(30));
            CloneOdooStagingJob::dispatch(
                $this->project->id,
                $selected->uuid,
                $branch,
                $this->cloneAddons,
                $this->cloneCacheKey(),
                (int) auth()->id(),
            );

            return $this->refreshCloneProgress();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->cloneRunning = false;
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            $this->cloneRunning = false;

            return handleError($e, $this);
        }
    }

    public function dismissCloneError(): void
    {
        $this->cloneError = null;
        $this->cloneStep = 0;
        $this->cloneRunning = false;
    }

    public function refreshCloneProgress()
    {
        $this->authorize('view', $this->project);
        $status = Cache::get($this->cloneCacheKey());
        if (! is_array($status)) {
            $this->cloneRunning = false;

            return;
        }

        $this->cloneStep = (int) ($status['step'] ?? 1);
        if (filled($status['error'] ?? null)) {
            $this->cloneRunning = false;
            $this->cloneError = (string) $status['error'];
            Cache::forget($this->cloneCacheKey());

            return;
        }
        if (($status['done'] ?? false) && filled($status['url'] ?? null)) {
            Cache::forget($this->cloneCacheKey());
            $this->cloneRunning = false;

            return redirect()->to((string) $status['url']);
        }
    }

    /**
     * @return list<string>
     */
    private function usedOdooBranches(): array
    {
        $this->project->loadMissing('environments.odooBranch');

        return $this->project->environments
            ->map(fn (Environment $environment): ?string => $environment->odooBranch?->git_branch)
            ->filter(fn (?string $branch): bool => filled($branch))
            ->unique()
            ->values()
            ->all();
    }

    private function cloneCacheKey(): string
    {
        return 'odoo-clone-'.$this->project->id;
    }

    private function loadCloneProgress(): void
    {
        $status = Cache::get($this->cloneCacheKey());
        if (! is_array($status) || ($status['done'] ?? false)) {
            return;
        }
        $this->cloneStep = (int) ($status['step'] ?? 1);
        if (filled($status['error'] ?? null)) {
            $this->cloneError = (string) $status['error'];

            return;
        }
        $this->cloneRunning = true;
        $this->showCloneWizard = false;
    }

    public function navigateToEnvironment($projectUuid, $environmentUuid)
    {
        return redirectRoute($this, 'project.resource.index', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
        ]);
    }

    public function render(): View
    {
        $canUpdateProject = auth()->user()->can('update', $this->project);
        $odooOnly = $this->project->odooProfile()->exists();
        $canCreateResource = auth()->user()->can('createAnyResource') && ! $odooOnly;
        $this->project->loadMissing('environments.odooBranch', 'environments.services');

        return view('livewire.project.show', [
            'creationQuota' => app(AdminCreationQuota::class)->summaryForViewer(),
            'usedBranches' => $this->usedOdooBranches(),
            'selectedEnvironment' => $this->project->environments->firstWhere('uuid', $this->selectedEnvironmentUuid),
            'environmentsJs' => $this->project->environments->map(function (Environment $environment) use ($canCreateResource, $canUpdateProject): array {
                $resourceCount = collect([
                    $environment->applications_count,
                    $environment->services_count,
                    $environment->postgresqls_count,
                    $environment->redis_count,
                    $environment->keydbs_count,
                    $environment->dragonflies_count,
                    $environment->clickhouses_count,
                    $environment->mongodbs_count,
                    $environment->mysqls_count,
                    $environment->mariadbs_count,
                ])->sum();

                $service = $environment->services->first(fn (Service $service): bool => $service->supportsOdooJupyter());
                $serviceHref = $service instanceof Service
                    ? route('project.service.configuration', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                    ])
                    : null;

                return [
                    'uuid' => $environment->uuid,
                    'name' => $environment->name,
                    'description' => $environment->description,
                    'branch' => $environment->odooBranch?->git_branch,
                    'serviceHref' => $serviceHref,
                    'resourceCount' => $resourceCount,
                    'href' => $serviceHref ?? route('project.resource.index', [
                        'project_uuid' => $this->project->uuid,
                        'environment_uuid' => $environment->uuid,
                    ]),
                    'settingsHref' => $canUpdateProject
                        ? route('project.environment.edit', [
                            'project_uuid' => $this->project->uuid,
                            'environment_uuid' => $environment->uuid,
                        ])
                        : null,
                    'addResourceHref' => $canCreateResource
                        ? route('project.resource.create', [
                            'project_uuid' => $this->project->uuid,
                            'environment_uuid' => $environment->uuid,
                        ])
                        : null,
                ];
            })->values()->toArray(),
        ]);
    }
}
