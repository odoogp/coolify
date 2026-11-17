<?php

namespace App\Livewire\Project\Concerns;

use App\Jobs\LaunchOdooProjectJob;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\OdooComposeTemplate;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\GetOdoo\GetOdooAreaEntitlements;
use App\Support\OdooGit;
use App\Support\ServiceTemplateCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * One-click catalog launch (Odoo job or compose service → configuration).
 * Used by the environment resource page and the project New assistant.
 */
trait LaunchesCatalogService
{
    public ?string $serverId = null;

    public string $selectedService = 'odoo';

    /** production|staging — chosen on Project New (new environment). Index launches into the current environment. */
    public string $environmentRole = Environment::ROLE_PRODUCTION;

    /** Display name for a New-environment catalog launch (Show). Unused when Index launches into the current env. */
    public string $environmentName = '';

    public string $odooVersion = '';

    /** Version for non-Odoo catalog services that expose more than one option. */
    public string $serviceVersion = '';

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
            $this->assertCatalogEnvironmentRole();

            $project = $this->catalogLaunchProject();
            $environment = $this->catalogLaunchEnvironment();

            if ($name === 'odoo') {
                // Every Odoo launch chooses its Compose version. Do not force the
                // profile version onto a new environment (no duplicate Odoo in the
                // same environment — existingOdooService reuses that row).
                $this->validateCatalogOdooVersion();
                if ($project->odooProfile === null) {
                    $project->enableOdoo($this->odooVersion);
                    $project->refresh();
                }
                $service = $this->existingOdooService($environment)
                    ?? $this->createOdooService($project, $environment, start: false, destination: $this->chosenDestination($project, $environment));
                if (! $service instanceof Service) {
                    return;
                }

                $skipGithubChoice = $this->skipGithubChoice || request()->query('github') === '0';
                $project->loadMissing('odooProfile');

                // Explicit "Launch without GitHub" (skip / github=0) starts containers.
                // Plain catalog Launch only prepares the service and opens configuration.
                if ($skipGithubChoice) {
                    $launchKey = 'launch-odoo-'.$service->uuid;
                    Cache::put($launchKey, ['step' => 1, 'done' => false, 'error' => null, 'redirect' => null], now()->addMinutes(30));
                    LaunchOdooProjectJob::dispatch($service->id, $launchKey, (int) auth()->id());

                    return redirect()->route('project.show', [
                        'project_uuid' => $project->uuid,
                    ]);
                }

                $parameters = [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                ];
                // First GitHub link: launch=choose (new/existing repo) when an App is ready.
                // Later Odoo environments open the GitHub panel so the user can pick a
                // branch or change the project repository — never invent a branch to clone.
                if (
                    blank($project->odooProfile?->git_repository)
                    && OdooGit::installedApp((int) $project->team_id, auth()->id()) instanceof GithubApp
                ) {
                    $parameters['launch'] = 'choose';
                } elseif (filled($project->odooProfile?->git_repository)) {
                    $parameters['odooPanel'] = 'github';
                }

                return redirect()->route('project.service.configuration', $parameters);
            }

            GetOdooAreaEntitlements::assertServiceAllowed(currentTeam(), $name);
            $this->assertCatalogServiceVersion($name);
            $service = $this->createCatalogService($name, $environment, $this->chosenDestination($project, $environment));
            if (! $service instanceof Service) {
                return;
            }

            return redirect()->route('project.service.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'service_uuid' => $service->uuid,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @return list<array{value: string, label: string, description: ?string, category: ?string, logo: ?string}>
     */
    protected function catalogServiceOptions(): array
    {
        return collect(ServiceTemplateCatalog::launchOptionsForTeam())
            ->map(fn (array $row): array => [
                'value' => $row['value'],
                'label' => $row['label'],
                'description' => $row['description'] ?? null,
                'category' => $row['category'] ?? null,
                'logo' => $row['logo'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function catalogEnvironmentRoleOptions(): array
    {
        return [
            ['value' => Environment::ROLE_PRODUCTION, 'label' => __('Production')],
            ['value' => Environment::ROLE_STAGING, 'label' => __('Staging')],
        ];
    }

    protected function assertCatalogEnvironmentRole(): void
    {
        if (! in_array($this->environmentRole, [Environment::ROLE_PRODUCTION, Environment::ROLE_STAGING], true)) {
            throw ValidationException::withMessages([
                'environmentRole' => [__('Choose Production or Staging for this launch.')],
            ]);
        }
    }

    /**
     * Version choices for the selected catalog service. Odoo always has 17–20.
     * Other services only appear when the template exposes more than one version.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function catalogVersionOptions(?string $service = null): array
    {
        $service ??= $this->selectedService;

        return ServiceTemplateCatalog::versionOptions($service);
    }

    protected function catalogNeedsVersionChoice(?string $service = null, ?Project $project = null): bool
    {
        $service ??= $this->selectedService;

        if ($service === 'odoo') {
            // Always show the picker for Odoo — including launches after the
            // project profile already exists — so each environment can pick Compose.
            return $this->catalogVersionOptions('odoo') !== [];
        }

        return count($this->catalogVersionOptions($service)) > 1;
    }

    /**
     * Suggested default for the Odoo version listbox (profile version when set).
     * Never used to hide the picker — each launch may choose a different Compose.
     */
    protected function catalogSuggestedOdooVersion(?Project $project = null): ?string
    {
        $project ??= $this->catalogLaunchProject();
        $version = $project->odooProfile?->odoo_version;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /** @deprecated Use catalogSuggestedOdooVersion(); kept for view data key compatibility. */
    protected function catalogLockedOdooVersion(?Project $project = null): ?string
    {
        return null;
    }

    protected function ensureDefaultOdooVersion(): void
    {
        $versions = OdooComposeTemplate::configuredVersions();
        if ($this->odooVersion === '') {
            $suggested = $this->catalogSuggestedOdooVersion();
            if (is_string($suggested) && in_array($suggested, $versions, true)) {
                $this->odooVersion = $suggested;
            }

            return;
        }
        // Drop a stale client value (e.g. old hardcoded 18) that is not configured.
        if ($versions === [] || ! in_array($this->odooVersion, $versions, true)) {
            $this->odooVersion = '';
        }
    }

    /**
     * @return array{versionOptions: list<array{value: string, label: string}>, needsVersionChoice: bool, lockedOdooVersion: ?string}
     */
    protected function catalogLaunchVersionViewData(): array
    {
        $this->ensureDefaultOdooVersion();

        return [
            'versionOptions' => $this->catalogVersionOptions(),
            'needsVersionChoice' => $this->catalogNeedsVersionChoice(),
            // Always null: the Launch UI never locks the version to the profile.
            'lockedOdooVersion' => null,
        ];
    }

    protected function validateCatalogOdooVersion(): void
    {
        $allowed = OdooComposeTemplate::configuredVersions();
        if ($allowed === []) {
            throw ValidationException::withMessages([
                'odooVersion' => [__('No Odoo Compose templates are configured. Add one in Settings → Odoo first.')],
            ]);
        }

        $validator = validator(
            ['odooVersion' => $this->odooVersion],
            ['odooVersion' => ['required', Rule::in($allowed)]],
        );
        if ($validator->fails()) {
            throw ValidationException::withMessages([
                'odooVersion' => [__('Choose which Odoo version to install.')],
            ]);
        }
    }

    protected function assertCatalogServiceVersion(string $service): void
    {
        $options = $this->catalogVersionOptions($service);
        if (count($options) <= 1) {
            return;
        }

        $allowed = collect($options)->pluck('value')->all();
        if (! in_array($this->serviceVersion, $allowed, true)) {
            throw ValidationException::withMessages([
                'serviceVersion' => [__('Choose which version to install.')],
            ]);
        }
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
        // HTTPS FQDNs before the user opens Start — Traefik needs https:// for ACME labels.
        OdooGit::useHttps($service->fresh([
            'applications',
            'server',
            'environment.project.odooProfile',
            'environment.odooBranch',
        ]) ?? $service);

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

        $version = (string) ($this->odooVersion !== '' ? $this->odooVersion : ($profile->odoo_version ?: ''));
        $compose = $version !== '' ? OdooComposeTemplate::composeFor($version) : null;
        if (! is_string($compose) || $compose === '') {
            $this->dispatch('error', __('Odoo has no compose template for this version. Configure it in Settings → Odoo.'));

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

        $envs = data_get(get_service_templates(), 'odoo.envs');
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
        $this->syncEnvironmentOdooVersion($environment, $version, $service->id);
        if ($start) {
            OdooGit::startIfPossible($service);
        }

        return $service;
    }

    protected function syncEnvironmentOdooVersion(Environment $environment, string $version, int $serviceId): void
    {
        if ($version === '') {
            return;
        }

        $row = OdooEnvironmentBranch::query()->firstOrNew([
            'environment_id' => $environment->id,
        ]);
        if (! $row->exists) {
            // Never invent a GitHub branch from the environment name (e.g. a second
            // production named "odoo" is not a branch on origin). Leave empty until
            // Launch-without-GitHub, repository choice, or Save GitHub branch.
            $row->git_branch = '';
            $row->jupyter_enabled = true;
        }
        $row->odoo_version = $version;
        $row->service_id = $serviceId;
        $row->save();
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
