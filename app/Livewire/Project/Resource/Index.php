<?php

namespace App\Livewire\Project\Resource;

use App\Domain\Odoo\OdooVersion;
use App\Jobs\LaunchOdooProjectJob;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\OdooComposeTemplate;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    public Collection $allProjects;

    public Collection $allEnvironments;

    public array $parameters;

    public ?string $serverId = null;

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
        $this->loadResources();
        if (request()->query('launch') === 'choose' && $this->project->odooProfile()->exists()) {
            $this->authorize('createAnyResource');

            try {
                $service = $this->existingOdooService() ?? $this->createOdooService(start: false);
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

        if ($this->project->odooProfile()->exists() && ! $this->environment->isEmpty()) {
            $service = $this->existingOdooService();
            if ($service instanceof Service) {
                return redirect()->route('project.service.configuration', [
                    'project_uuid' => $this->project->uuid,
                    'environment_uuid' => $this->environment->uuid,
                    'service_uuid' => $service->uuid,
                ]);
            }
        }

        return null;
    }

    private function loadResources(): void
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
                ->firstOrFail();

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
            'serverChoices' => OdooGit::launchChoices(),
            'hasOtherServers' => OdooGit::allowedLaunchServers()->contains(fn (Server $server): bool => (int) $server->id !== 0),
            'needsServer' => ! auth()->user()?->canLaunchOnInstanceServer() && OdooGit::allowedLaunchServers()->isEmpty(),
            'canAddServer' => (bool) auth()->user()?->canAddServers(),
        ]);
    }

    public function installOdoo()
    {
        try {
            $this->authorize('createAnyResource');
            if ($this->project->odooProfile === null) {
                return;
            }
            if ($this->serverId === 'new') {
                if (! auth()->user()?->canAddServers()) {
                    throw new \RuntimeException(__('The owner has to add a server, or allow you to add servers, before you can create a project.'));
                }

                return redirect()->route('server.create');
            }
            $service = $this->existingOdooService() ?? $this->createOdooService(start: false, destination: $this->chosenDestination());
            if (! $service instanceof Service) {
                return;
            }

            $launchKey = 'launch-odoo-'.$service->uuid;
            Cache::put($launchKey, ['step' => 1, 'done' => false, 'error' => null, 'redirect' => null], now()->addMinutes(30));
            LaunchOdooProjectJob::dispatch($service->id, $launchKey, (int) auth()->id());

            return redirect()->route('project.show', [
                'project_uuid' => $this->project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function existingOdooService(): ?Service
    {
        return $this->environment->services()->get()->first(
            fn (Service $service): bool => $service->supportsOdooJupyter()
        );
    }

    private function chosenDestination(): StandaloneDocker|SwarmDocker
    {
        if ($this->serverId === null || $this->serverId === '') {
            throw new \RuntimeException(__('Choose a server.'));
        }

        $server = OdooGit::allowedLaunchServers()->firstWhere('id', (int) $this->serverId);
        if (! $server instanceof Server) {
            throw new \RuntimeException(__('Choose a server.'));
        }

        $destination = $server->standaloneDockers()->first() ?? $server->swarmDockers()->first();
        if ($destination === null) {
            throw new \RuntimeException(__('This server has no Docker destination.'));
        }

        return $destination;
    }

    private function createOdooService(bool $start, StandaloneDocker|SwarmDocker|null $destination = null): ?Service
    {
        $profile = $this->project->odooProfile;
        if ($profile === null) {
            return null;
        }

        $destination ??= OdooGit::firstLaunchDestination();
        if ($destination === null) {
            $this->dispatch('error', __('No server is available for this Odoo service.'));

            return null;
        }

        $version = (string) ($profile->odoo_version ?: '18');
        $templates = get_service_templates();
        $encoded = data_get($templates, 'odoo.compose');
        $compose = is_string($encoded) && $encoded !== '' ? base64_decode($encoded) : OdooComposeTemplate::defaultCompose($version);
        $saved = OdooComposeTemplate::composeFor($version);
        $compose = $saved ?? (is_string($compose) ? OdooVersion::apply($compose, $version) : null);
        if (! is_string($compose) || $compose === '') {
            $this->dispatch('error', __('Odoo has no compose template for this version.'));

            return null;
        }

        $service = new Service([
            'docker_compose_raw' => $compose,
            'environment_id' => $this->environment->id,
            'service_type' => 'odoo',
            'server_id' => $destination->server_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'jupyter_enabled' => true,
        ]);
        if (in_array('odoo', NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK, true)) {
            $service->connect_to_docker_network = true;
        }
        $service->save();
        $service->name = 'odoo-'.$service->uuid;
        $service->save();

        $envs = data_get($templates, 'odoo.envs');
        if (is_string($envs) && $envs !== '') {
            collect(preg_split("/\r\n|\r|\n/", base64_decode($envs)))
                ->filter(fn ($line): bool => is_string($line) && str_contains($line, '='))
                ->each(function (string $line) use ($service): void {
                    $key = str($line)->before('=')->value();
                    $value = str($line)->after('=')->value();
                    if ($key === '' || $value === '') {
                        return;
                    }
                    EnvironmentVariable::create([
                        'key' => $key,
                        'value' => $value,
                        'resourceable_id' => $service->id,
                        'resourceable_type' => $service->getMorphClass(),
                        'is_preview' => false,
                    ]);
                });
        }

        $service->parse(isNew: true);
        applyServiceApplicationPrerequisites($service);
        if ($start) {
            OdooGit::startIfPossible($service);
        }

        return $service;
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
