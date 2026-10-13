<?php

namespace App\Livewire\Project\Concerns;

use App\Domain\Odoo\OdooVersion;
use App\Jobs\LaunchOdooProjectJob;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\OdooComposeTemplate;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\GetOdoo\GetOdooAreaEntitlements;
use App\Support\OdooGit;
use App\Support\ServiceTemplateCatalog;
use Illuminate\Support\Facades\Cache;

/**
 * One-click catalog launch (Odoo job or compose service → configuration).
 * Used by the environment resource page and the project New assistant.
 */
trait LaunchesCatalogService
{
    public ?string $serverId = null;

    public string $selectedService = 'odoo';

    public bool $skipGithubChoice = false;

    abstract protected function catalogLaunchProject(): Project;

    abstract protected function catalogLaunchEnvironment(): Environment;

    public function launchService()
    {
        try {
            $this->authorize('createAnyResource');
            if ($this->serverId === 'new') {
                if (! auth()->user()?->canAddServers()) {
                    throw new \RuntimeException(__('The owner has to add a server, or allow you to add servers, before you can create a project.'));
                }

                return redirect()->route('server.create');
            }

            $name = trim($this->selectedService);
            if ($name === '') {
                throw new \RuntimeException(__('Choose a service to launch.'));
            }

            $project = $this->catalogLaunchProject();
            $environment = $this->catalogLaunchEnvironment();

            if ($name === 'odoo') {
                if ($project->odooProfile === null) {
                    $project->enableOdoo('18');
                    $project->refresh();
                }
                $service = $this->existingOdooService($environment)
                    ?? $this->createOdooService($project, $environment, start: false, destination: $this->chosenDestination($project, $environment));
                if (! $service instanceof Service) {
                    return;
                }

                $skipGithubChoice = $this->skipGithubChoice || request()->query('github') === '0';
                $project->loadMissing('odooProfile');
                if (
                    ! $skipGithubChoice
                    && blank($project->odooProfile?->git_repository)
                    && OdooGit::installedApp((int) $project->team_id, auth()->id()) instanceof GithubApp
                ) {
                    return redirect()->route('project.service.configuration', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                        'service_uuid' => $service->uuid,
                        'launch' => 'choose',
                    ]);
                }

                $launchKey = 'launch-odoo-'.$service->uuid;
                Cache::put($launchKey, ['step' => 1, 'done' => false, 'error' => null, 'redirect' => null], now()->addMinutes(30));
                LaunchOdooProjectJob::dispatch($service->id, $launchKey, (int) auth()->id());

                return redirect()->route('project.show', [
                    'project_uuid' => $project->uuid,
                ]);
            }

            GetOdooAreaEntitlements::assertServiceAllowed(currentTeam(), $name);
            $service = $this->createCatalogService($name, $environment, $this->chosenDestination($project, $environment));
            if (! $service instanceof Service) {
                return;
            }

            return redirect()->route('project.service.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'service_uuid' => $service->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function catalogServiceOptions(): array
    {
        return collect(ServiceTemplateCatalog::launchOptionsForTeam())
            ->map(fn (array $row): array => [
                'value' => $row['value'],
                'label' => $row['label'].(filled($row['category']) ? ' · '.$row['category'] : ''),
            ])
            ->values()
            ->all();
    }

    protected function defaultCatalogServerId(Project $project, Environment $environment): void
    {
        if ($this->serverId !== null && $this->serverId !== '') {
            return;
        }

        $existing = $this->assignedProjectDestination($project, $environment);
        if ($existing instanceof StandaloneDocker || $existing instanceof SwarmDocker) {
            $this->serverId = (string) $existing->server_id;

            return;
        }

        $server = OdooGit::allowedLaunchServers()->first();
        if ($server instanceof Server) {
            $this->serverId = (string) $server->id;
        }
    }

    protected function createCatalogService(string $name, Environment $environment, StandaloneDocker|SwarmDocker $destination): ?Service
    {
        $templates = get_service_templates();
        $compose = ServiceTemplateCatalog::composeFor($name);
        if (! is_string($compose) || $compose === '') {
            $this->dispatch('error', __('That service template has no Compose file.'));

            return null;
        }

        $service = new Service([
            'docker_compose_raw' => $compose,
            'environment_id' => $environment->id,
            'service_type' => $name,
            'server_id' => $destination->server_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'jupyter_enabled' => ServiceTemplateCatalog::includesJupyter($name),
        ]);
        if (in_array($name, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK, true)) {
            $service->connect_to_docker_network = true;
        }
        $service->save();
        $service->name = $name.'-'.$service->uuid;
        $service->save();

        $envs = data_get($templates, $name.'.envs');
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

        return $service;
    }

    protected function existingOdooService(Environment $environment): ?Service
    {
        return $environment->services()->get()->first(
            fn (Service $service): bool => $service->supportsOdooJupyter()
        );
    }

    protected function createOdooService(Project $project, Environment $environment, bool $start, StandaloneDocker|SwarmDocker|null $destination = null): ?Service
    {
        $profile = $project->odooProfile;
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
            'environment_id' => $environment->id,
            'service_type' => 'odoo',
            'server_id' => $destination->server_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'jupyter_enabled' => ServiceTemplateCatalog::includesJupyter('odoo'),
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

    protected function chosenDestination(Project $project, Environment $environment): StandaloneDocker|SwarmDocker
    {
        $assigned = $this->assignedProjectDestination($project, $environment);
        if ($assigned instanceof StandaloneDocker || $assigned instanceof SwarmDocker) {
            return $assigned;
        }

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

    protected function assignedProjectDestination(Project $project, Environment $environment): StandaloneDocker|SwarmDocker|null
    {
        $fromEnvironment = $environment->services()
            ->whereNotNull('destination_id')
            ->orderBy('id')
            ->first();
        if ($fromEnvironment?->destination instanceof StandaloneDocker || $fromEnvironment?->destination instanceof SwarmDocker) {
            return $fromEnvironment->destination;
        }

        foreach ($project->environments()->orderBy('id')->get() as $row) {
            $service = $row->services()
                ->whereNotNull('destination_id')
                ->orderBy('id')
                ->first();
            if ($service?->destination instanceof StandaloneDocker || $service?->destination instanceof SwarmDocker) {
                return $service->destination;
            }
        }

        return null;
    }
}
