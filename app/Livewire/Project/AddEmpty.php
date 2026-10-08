<?php

namespace App\Livewire\Project;

use App\Actions\Service\StartService;
use App\Domain\Odoo\OdooVersion;
use App\Jobs\LaunchOdooProjectJob;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\OdooComposeTemplate;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\AdminCreationQuota;
use App\Services\GetOdoo\GetOdooAreaEntitlements;
use App\Support\OdooGit;
use App\Support\ServiceTemplateCatalog;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

class AddEmpty extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $description = '';

    public string $service = '';

    public string $odooVersion = '18';

    public bool $connectGithub = true;

    public bool $migrateFromOdoo = false;

    public ?string $serverId = null;

    public bool $launchRunning = false;

    public int $launchStep = 0;

    public ?string $launchError = null;

    public ?string $launchKey = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'service' => ['nullable', 'string'],
            'odooVersion' => ['required', Rule::in(OdooVersion::SUPPORTED)],
            'connectGithub' => ['boolean'],
            'migrateFromOdoo' => ['boolean'],
        ];
    }

    public function updatedMigrateFromOdoo(bool $value): void
    {
        if ($value) {
            $this->connectGithub = false;
        }
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function mount(): void
    {
        $server = OdooGit::allowedLaunchServers()->first();
        if ($server instanceof Server) {
            $this->serverId = (string) $server->id;
        }
    }

    public function submit()
    {
        $guard = 'create-project-user-'.auth()->id();
        if (! Cache::add($guard, 1, 15)) {
            return handleError(new RuntimeException(__('A project is already being created.')), $this);
        }

        try {
            $this->authorize('create', Project::class);
            if ($this->needsServerBeforeProject()) {
                throw new RuntimeException(__('Do you want to create a server?'));
            }
            if ($this->serverId === 'new') {
                if (! auth()->user()?->canAddServers()) {
                    throw new RuntimeException(__('The owner has to add a server, or allow you to add servers, before you can create a project.'));
                }

                return redirect()->route('server.create');
            }
            $this->validate();

            $created = null;
            $project = null;
            $productionEnvironment = null;
            $destination = $this->service === '' ? null : $this->destinationForLaunch();

            DB::beginTransaction();
            try {
                $project = app(AdminCreationQuota::class)->createProject(auth()->user(), [
                    'name' => $this->name,
                    'description' => $this->description,
                    'team_id' => currentTeam()->id,
                    'uuid' => new_public_id(),
                ]);

                $productionEnvironment = $project->environments()->where('name', 'production')->first();
                if ($this->service === 'odoo') {
                    $project->enableOdoo($this->odooVersion);
                    $project->refresh();
                }
                if ($destination !== null) {
                    if ($this->service !== '') {
                        GetOdooAreaEntitlements::assertServiceAllowed(currentTeam(), $this->service);
                    }
                    $created = $this->createChosenService($project, $productionEnvironment, $destination);
                }
                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            $githubApp = $this->service === 'odoo' && $this->connectGithub
                ? OdooGit::installedApp((int) $project->team_id, auth()->id())
                : null;

            if ($this->service === 'odoo' && $this->connectGithub && $githubApp instanceof GithubApp && $created instanceof Service) {
                return redirect()->route('project.service.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $productionEnvironment->uuid,
                    'service_uuid' => $created->uuid,
                    'launch' => 'choose',
                ]);
            }

            if ($this->service === 'odoo' && $this->connectGithub && ! $githubApp instanceof GithubApp) {
                $parameters = [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $productionEnvironment->uuid,
                ];
                $back = 'project.resource.index';
                if ($created instanceof Service) {
                    $parameters['service_uuid'] = $created->uuid;
                    $back = 'project.service.configuration';
                }
                $githubApp = OdooGit::beginConnect($project, $back, $parameters);
                if (filled($githubApp->app_id)) {
                    return redirect()->away(getInstallationPath($githubApp));
                }

                return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
            }

            if ($created instanceof Service && $this->service === 'odoo') {
                if ($githubApp instanceof GithubApp) {
                    OdooGit::cloneIntoService($created);
                }
                $this->launchKey = 'launch-odoo-'.$created->uuid;
                Cache::put($this->launchKey, ['step' => 1, 'done' => false, 'error' => null, 'redirect' => null], now()->addMinutes(30));
                LaunchOdooProjectJob::dispatch(
                    $created->id,
                    $this->launchKey,
                    (int) auth()->id(),
                );
                $this->launchStep = 1;
                $this->launchError = null;
                $this->launchRunning = true;

                if ($this->migrateFromOdoo) {
                    return redirect()->route('project.odoo.migrate', ['project_uuid' => $project->uuid]);
                }

                return redirect()->route('project.show', ['project_uuid' => $project->uuid]);
            }

            if ($this->service === 'odoo' && $this->migrateFromOdoo) {
                return redirect()->route('project.odoo.migrate', ['project_uuid' => $project->uuid]);
            }

            if ($created instanceof Service) {
                StartService::dispatch($created);

                return redirect()->route('project.service.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $productionEnvironment->uuid,
                    'service_uuid' => $created->uuid,
                ]);
            }

            if ($this->service === 'odoo' && $this->connectGithub && $productionEnvironment !== null) {
                return redirect()->route('project.resource.index', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $productionEnvironment->uuid,
                    'launch' => 'choose',
                ]);
            }

            return redirect()->route('project.resource.index', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $productionEnvironment->uuid,
            ]);
        } catch (\Throwable $e) {
            Cache::forget($guard);

            return handleError($e, $this);
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

    public function render()
    {
        $options = collect(ServiceTemplateCatalog::launchOptionsForTeam())
            ->map(fn (array $row): array => [
                'value' => $row['value'],
                'label' => $row['label'].(filled($row['category']) ? ' · '.$row['category'] : ''),
            ])
            ->prepend(['value' => '', 'label' => __('No service yet')])
            ->values()
            ->all();

        return view('livewire.project.add-empty', [
            'creationQuota' => app(AdminCreationQuota::class)->summaryForViewer(),
            'serviceOptions' => $options,
            'serverChoices' => $this->serverChoices(),
            'hasOtherServers' => $this->launchServers()->contains(fn (Server $server): bool => (int) $server->id !== 0),
            'needsServer' => $this->needsServerBeforeProject(),
            'canAddServer' => (bool) auth()->user()?->canAddServers(),
        ]);
    }

    /**
     * @return Collection<int, Server>
     */
    /**
     * @return list<array{value: string, label: string}>
     */
    private function serverChoices(): array
    {
        return OdooGit::launchChoices();
    }

    private function launchServers()
    {
        return OdooGit::allowedLaunchServers();
    }

    private function needsServerBeforeProject(): bool
    {
        if (auth()->user()?->canLaunchOnInstanceServer()) {
            return false;
        }

        return $this->launchServers()->isEmpty();
    }

    private function destinationForLaunch(): StandaloneDocker|SwarmDocker|null
    {
        $servers = $this->launchServers();
        if ($servers->isEmpty()) {
            return null;
        }

        if ($this->serverId === null || $this->serverId === '') {
            throw new RuntimeException(__('Choose a server.'));
        }

        $server = $servers->firstWhere('id', (int) $this->serverId);
        if (! $server instanceof Server) {
            throw new RuntimeException(__('Choose a server.'));
        }

        $destination = $server->standaloneDockers()->first() ?? $server->swarmDockers()->first();
        if ($destination === null) {
            throw new RuntimeException(__('This server has no Docker destination.'));
        }

        return $destination;
    }

    private function createChosenService(Project $project, $environment, StandaloneDocker|SwarmDocker $destination): ?Service
    {
        if ($environment === null) {
            return null;
        }

        $templates = get_service_templates();
        $compose = ServiceTemplateCatalog::composeFor($this->service)
            ?? ($this->service === 'odoo' ? OdooComposeTemplate::defaultCompose($this->odooVersion) : null);
        if (! is_string($compose) || $compose === '') {
            return null;
        }

        if ($this->service === 'odoo') {
            $saved = OdooComposeTemplate::composeFor($this->odooVersion);
            $compose = $saved ?? OdooVersion::apply($compose, $this->odooVersion);
        }

        $service = new Service([
            'docker_compose_raw' => $compose,
            'environment_id' => $environment->id,
            'service_type' => $this->service,
            'server_id' => $destination->server_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'jupyter_enabled' => ServiceTemplateCatalog::includesJupyter($this->service),
        ]);
        if (in_array($this->service, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK, true)) {
            $service->connect_to_docker_network = true;
        }
        $service->save();
        $service->name = $this->service.'-'.$service->uuid;
        $service->save();

        $envs = data_get($templates, $this->service.'.envs');
        if (is_string($envs) && $envs !== '') {
            collect(preg_split('/\r\n|\r|\n/', base64_decode($envs)))
                ->filter()
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
}
