<?php

namespace App\Livewire\Project;

use App\Actions\Service\StartService;
use App\Models\Environment;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Service;
use App\Services\AdminCreationQuota;
use App\Models\GithubApp;
use App\Rules\ValidGitBranch;
use App\Support\OdooGit;
use App\Support\OdooStaging;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
        $createdId = null;
        try {
            $this->authorize('update', $this->project);
            $this->project->load('odooProfile.githubApp', 'environments.odooBranch');
            $selected = $this->project->environments->firstWhere('uuid', $this->selectedEnvironmentUuid);
            if (! $selected instanceof Environment || strcasecmp($selected->name, 'production') !== 0) {
                throw new RuntimeException('Clone starts from the production environment.');
            }

            $profile = $this->project->odooProfile;
            $repository = $profile?->git_repository;
            $app = $profile?->githubApp;
            $branch = trim($this->stagingBranch);
            if ($branch === '') {
                $branch = OdooStaging::nextName($this->project);
            }
            $used = $this->usedOdooBranches();
            if (in_array($branch, $used, true)) {
                throw new InvalidArgumentException('That branch is already used by this repository.');
            }
            $check = Validator::make(['branch' => $branch], ['branch' => ['required', 'string', new ValidGitBranch]]);
            if ($check->fails()) {
                throw new InvalidArgumentException('The GitHub branch name is invalid.');
            }
            if (filled($repository) && $app instanceof GithubApp) {
                $source = $this->cloneAddons === 'copy'
                    ? (string) ($selected->odooBranch?->git_branch ?: $selected->name)
                    : '';
                OdooGit::prepareStagingBranch($app, (string) $repository, $source, $branch, $used);
            }

            $staging = $this->project->cloneProductionAsStaging();
            $createdId = $staging->id;
            if (filled($repository) && $app instanceof GithubApp) {
                OdooEnvironmentBranch::query()->updateOrCreate(
                    ['environment_id' => $staging->id],
                    ['git_branch' => $branch],
                );
            }

            $copied = $this->copyProductionService($selected, $staging);
            if ($copied instanceof Service && filled($repository) && $this->cloneAddons === 'copy') {
                OdooGit::cloneIntoService($copied);
            }
            if ($copied instanceof Service && $copied->server?->isFunctional()) {
                StartService::run($copied, pullLatestImages: true);
            }

            $this->showCloneWizard = false;
            if ($copied instanceof Service) {
                return redirectRoute($this, 'project.service.configuration', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $staging->uuid,
                    'service_uuid' => $copied->uuid,
                ]);
            }

            $this->dispatch('success', __('Staging :name created without modules.', ['name' => $staging->name]));

            return redirectRoute($this, 'project.show', ['project_uuid' => $this->project->uuid]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->discardEmptyStaging($createdId);
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            $this->discardEmptyStaging($createdId);

            return handleError($e, $this);
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

    private function discardEmptyStaging(?int $environmentId): void
    {
        if ($environmentId === null) {
            return;
        }

        $environment = Environment::query()->find($environmentId);
        if (! $environment instanceof Environment) {
            return;
        }
        if ($environment->services()->exists() || $environment->applications()->exists()) {
            return;
        }

        $environment->delete();
        $this->project->unsetRelation('environments');
        $this->project->load(['environments' => fn ($query) => $query
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
            ->with('odooBranch')
            ->orderBy('created_at'),
        ]);
    }

    private function copyProductionService(Environment $source, Environment $staging): ?Service
    {
        $original = $source->services()->get()->first(
            fn (Service $service): bool => $service->supportsOdooJupyter()
        );
        if (! $original instanceof Service) {
            return null;
        }

        $copy = $original->replicate();
        $copy->uuid = new_public_id();
        $copy->environment_id = $staging->id;
        $copy->config_hash = null;
        $copy->name = 'odoo-'.$staging->name;
        $copy->save();

        foreach ($original->environment_variables as $variable) {
            $cloned = $variable->replicate();
            $cloned->uuid = new_public_id();
            $cloned->resourceable_id = $copy->id;
            $cloned->resourceable_type = $copy->getMorphClass();
            $cloned->save();
        }

        return $copy;
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

                return [
                    'uuid' => $environment->uuid,
                    'name' => $environment->name,
                    'description' => $environment->description,
                    'branch' => $environment->odooBranch?->git_branch,
                    'serviceHref' => $service instanceof Service
                        ? route('project.service.configuration', [
                            'project_uuid' => $this->project->uuid,
                            'environment_uuid' => $environment->uuid,
                            'service_uuid' => $service->uuid,
                        ])
                        : null,
                    'resourceCount' => $resourceCount,
                    'href' => route('project.resource.index', [
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
