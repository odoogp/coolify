<?php

namespace App\Livewire\Project;

use App\Actions\Service\StartService;
use App\Models\Environment;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Service;
use App\Services\AdminCreationQuota;
use App\Support\OdooGit;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
            $selected = $this->project->environments->firstWhere('uuid', $this->selectedEnvironmentUuid);
            if (! $selected instanceof Environment || strcasecmp($selected->name, 'production') !== 0) {
                throw new RuntimeException('Clone starts from the production environment.');
            }

            $this->project->load('odooProfile.githubApp');
            $profile = $this->project->odooProfile;
            $repository = $profile?->git_repository;
            $app = $profile?->githubApp;
            $from = $selected->odooBranch?->git_branch;
            if (filled($repository) && $app !== null && $this->cloneAddons === 'copy' && blank($from)) {
                throw new RuntimeException('Associate the production environment with its GitHub branch before cloning it.');
            }

            $staging = $this->project->cloneProductionAsStaging();
            if (filled($repository) && $app !== null) {
                if ($this->cloneAddons === 'copy') {
                    OdooGit::cloneBranch($app, (string) $repository, (string) $from, $staging->name);
                } else {
                    OdooGit::ensureRepositoryAndBranch($app, $this->project, $staging->name);
                }
                OdooEnvironmentBranch::query()->updateOrCreate(
                    ['environment_id' => $staging->id],
                    ['git_branch' => $staging->name],
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
            $this->dispatch('error', __($exception->getMessage()));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
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
        $canCreateResource = auth()->user()->can('createAnyResource');

        return view('livewire.project.show', [
            'creationQuota' => app(AdminCreationQuota::class)->summaryForViewer(),
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

                return [
                    'uuid' => $environment->uuid,
                    'name' => $environment->name,
                    'description' => $environment->description,
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
