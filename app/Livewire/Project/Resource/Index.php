<?php

namespace App\Livewire\Project\Resource;

use App\Livewire\Project\Concerns\LaunchesCatalogService;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;
    use LaunchesCatalogService;

    public Project $project;

    public Environment $environment;

    public Collection $allProjects;

    public Collection $allEnvironments;

    public array $parameters;

    protected Collection $applications;

    protected Collection $postgresqls;

    protected Collection $redis;

    protected Collection $mongodbs;

    protected Collection $mysqls;

    protected Collection $mariadbs;

    protected Collection $keydbs;

    protected Collection $dragonflies;

    protected Collection $clickhouses;

    protected Collection $services;

    public function mount(): mixed
    {
        $missingEnvironment = $this->loadResources();
        if ($missingEnvironment instanceof \Illuminate\Http\RedirectResponse) {
            return $missingEnvironment;
        }
        $this->defaultCatalogServerId($this->project, $this->environment);
        $this->skipGithubChoice = request()->query('github') === '0';
        if (request()->query('launch') === 'choose' && $this->project->odooProfile()->exists()) {
            $this->authorize('createAnyResource');

            try {
                $service = $this->existingOdooService($this->environment)
                    ?? $this->createOdooService($this->project, $this->environment, start: false);
            } catch (\Throwable $e) {
                return handleError($e, $this);
            }

            if (! $service instanceof Service) {
                return null;
            }

            return redirect()->route('project.service.configuration', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'service_uuid' => $service->uuid,
                'launch' => 'choose',
            ]);
        }

        // Skip the resource list when the environment already has a primary service.
        // New / catalog stays on this page via ?catalog=1.
        if (! request()->boolean('catalog')) {
            $primary = $this->primaryServiceForEnvironment();
            if ($primary instanceof Service) {
                return redirect()->route('project.service.configuration', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $this->environment->uuid,
                    'service_uuid' => $primary->uuid,
                ]);
            }
        }

        return null;
    }

    /**
     * Prefer the Odoo service; otherwise a lone service when the environment has no other resources.
     */
    private function primaryServiceForEnvironment(): ?Service
    {
        $odoo = $this->services->first(fn (Service $service): bool => $service->supportsOdooJupyter());
        if ($this->project->odooProfile()->exists() && $odoo instanceof Service) {
            return $odoo;
        }

        $otherResources = $this->applications->count()
            + $this->postgresqls->count()
            + $this->redis->count()
            + $this->mongodbs->count()
            + $this->mysqls->count()
            + $this->mariadbs->count()
            + $this->keydbs->count()
            + $this->dragonflies->count()
            + $this->clickhouses->count();

        if ($otherResources === 0 && $this->services->count() === 1) {
            $only = $this->services->first();

            return $only instanceof Service ? $only : null;
        }

        return null;
    }

    private function loadResources(): mixed
    {
        $this->applications = $this->postgresqls = $this->redis = $this->mongodbs = $this->mysqls = $this->mariadbs = $this->keydbs = $this->dragonflies = $this->clickhouses = $this->services = collect();
        if (! isset($this->project)) {
            $this->parameters = get_route_parameters();
            $project = currentTeam()
                ->projects()
                ->select('id', 'uuid', 'team_id', 'name')
                ->where('uuid', request()->route('project_uuid'))
                ->firstOrFail();
            $environment = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->where('uuid', request()->route('environment_uuid'))
                ->first();
            if ($environment === null) {
                return redirect()->route('project.show', ['project_uuid' => $project->uuid]);
            }

            $this->project = $project;

            // Load projects and environments for breadcrumb navigation
            $this->allProjects = Project::ownedByCurrentTeamCached();
            $environmentRelations = [
                'applications:id,uuid,name,environment_id',
                'services:id,uuid,name,environment_id',
                'postgresqls:id,uuid,name,environment_id',
                'redis:id,uuid,name,environment_id',
                'mongodbs:id,uuid,name,environment_id',
                'mysqls:id,uuid,name,environment_id',
                'mariadbs:id,uuid,name,environment_id',
                'keydbs:id,uuid,name,environment_id',
                'dragonflies:id,uuid,name,environment_id',
                'clickhouses:id,uuid,name,environment_id',
            ];

            $this->allEnvironments = $project->environments()
                ->select('id', 'uuid', 'name', 'project_id')
                ->with($environmentRelations)
                ->get();

            $this->environment = $environment->loadCount([
                'applications',
                'redis',
                'postgresqls',
                'mysqls',
                'keydbs',
                'dragonflies',
                'clickhouses',
                'mariadbs',
                'mongodbs',
                'services',
            ]);
        }

        // Eager load relationships for applications
        $this->applications = $this->environment->applications()->with([
            'tags',
            'destination.server.settings',
            'settings',
        ])->get()->sortBy('name');
        $projectUuid = $this->project->uuid;
        $environmentUuid = $this->environment->uuid;
        $this->applications = $this->applications->map(function ($application) use ($projectUuid, $environmentUuid) {
            $application->hrefLink = route('project.application.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'application_uuid' => $application->uuid,
            ]);

            return $application;
        });
        $this->applications = $this->applications->sortBy('name');

        // Load all database resources in a single query per type
        $databaseTypes = [
            'postgresqls' => 'postgresqls',
            'redis' => 'redis',
            'mongodbs' => 'mongodbs',
            'mysqls' => 'mysqls',
            'mariadbs' => 'mariadbs',
            'keydbs' => 'keydbs',
            'dragonflies' => 'dragonflies',
            'clickhouses' => 'clickhouses',
        ];

        foreach ($databaseTypes as $property => $relation) {
            $this->{$property} = $this->environment->{$relation}()->with([
                'tags',
                'destination.server.settings',
            ])->get()->sortBy('name');
            $this->{$property} = $this->{$property}->map(function ($db) use ($projectUuid, $environmentUuid) {
                $db->hrefLink = route('project.database.configuration', [
                    'project_uuid' => $projectUuid,
                    'database_uuid' => $db->uuid,
                    'environment_uuid' => $environmentUuid,
                ]);

                return $db;
            });
        }

        // Load services with their tags and server
        $this->services = $this->environment->services()->with([
            'tags',
            'destination.server.settings',
        ])->get()->sortBy('name');
        $this->services = $this->services->map(function ($service) use ($projectUuid, $environmentUuid) {
            $service->hrefLink = route('project.service.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'service_uuid' => $service->uuid,
            ]);

            return $service;
        });

        return null;
    }

    public function render()
    {
        if (! isset($this->applications)) {
            $this->loadResources();
        }

        return view('livewire.project.resource.index', [
            'applications' => $this->applications,
            'postgresqls' => $this->postgresqls,
            'redis' => $this->redis,
            'mongodbs' => $this->mongodbs,
            'mysqls' => $this->mysqls,
            'mariadbs' => $this->mariadbs,
            'keydbs' => $this->keydbs,
            'dragonflies' => $this->dragonflies,
            'clickhouses' => $this->clickhouses,
            'services' => $this->services,
            'applicationsJs' => $this->toSearchableArray($this->applications, 'application', 'Application'),
            'postgresqlsJs' => $this->toSearchableArray($this->postgresqls, 'database', 'Database'),
            'redisJs' => $this->toSearchableArray($this->redis, 'database', 'Database'),
            'mongodbsJs' => $this->toSearchableArray($this->mongodbs, 'database', 'Database'),
            'mysqlsJs' => $this->toSearchableArray($this->mysqls, 'database', 'Database'),
            'mariadbsJs' => $this->toSearchableArray($this->mariadbs, 'database', 'Database'),
            'keydbsJs' => $this->toSearchableArray($this->keydbs, 'database', 'Database'),
            'dragonfliesJs' => $this->toSearchableArray($this->dragonflies, 'database', 'Database'),
            'clickhousesJs' => $this->toSearchableArray($this->clickhouses, 'database', 'Database'),
            'servicesJs' => $this->toSearchableArray($this->services, 'service', 'Service'),
            'odooOnly' => $this->project->odooProfile()->exists(),
            ...$this->catalogLaunchVersionViewData(),
            'serviceOptions' => $this->catalogServiceOptions(),
            'serverChoices' => OdooGit::launchChoices(),
            'hasOtherServers' => OdooGit::allowedLaunchServers()->contains(fn (Server $server): bool => (int) $server->id !== 0),
            'needsServer' => ! auth()->user()?->canLaunchOnInstanceServer() && OdooGit::allowedLaunchServers()->isEmpty(),
            'canAddServer' => (bool) auth()->user()?->canAddServers(),
            'projectServerLocked' => $this->assignedProjectDestination($this->project, $this->environment) !== null,
            'projectServerName' => $this->assignedProjectDestination($this->project, $this->environment)?->server?->name,
            'hasOdooService' => $this->existingOdooService($this->environment) instanceof Service,
        ]);
    }

    protected function catalogLaunchProject(): Project
    {
        return $this->project;
    }

    protected function catalogLaunchEnvironment(): Environment
    {
        return $this->environment;
    }

    public function installOdoo()
    {
        $this->selectedService = 'odoo';

        return $this->launchService();
    }

    private function toSearchableArray(Collection $items, string $type, string $typeLabel): array
    {
        return $items->map(fn ($item) => [
            'uuid' => $item->uuid,
            'name' => $item->name,
            'type' => $type,
            'typeLabel' => $typeLabel,
            'fqdn' => $item->fqdn ?? null,
            'description' => $item->description ?? null,
            'status' => $item->status ?? '',
            'server_status' => $item->server_status ?? null,
            'hrefLink' => $item->hrefLink ?? '',
            'destination' => [
                'server' => [
                    'name' => $item->destination?->server?->name ?? 'Unknown',
                ],
            ],
            'tags' => $item->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()->toArray(),
        ])->values()->toArray();
    }
}
