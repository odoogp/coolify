<?php

namespace App\Livewire\Project;

use App\Models\EnvironmentVariable;
use App\Models\OdooComposeTemplate;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\AdminCreationQuota;
use App\Support\OdooGit;
use App\Support\OdooVersion;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

class AddEmpty extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $description = '';

    public string $service = '';

    public string $odooVersion = '18';

    public bool $connectGithub = true;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'service' => ['nullable', 'string'],
            'odooVersion' => ['required', Rule::in(OdooVersion::SUPPORTED)],
            'connectGithub' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function submit()
    {
        try {
            $this->authorize('create', Project::class);
            $this->validate();
            $project = app(AdminCreationQuota::class)->createProject(auth()->user(), [
                'name' => $this->name,
                'description' => $this->description,
                'team_id' => currentTeam()->id,
                'uuid' => new_public_id(),
            ]);

            $productionEnvironment = $project->environments()->where('name', 'production')->first();
            $created = null;
            if ($this->service === 'odoo') {
                $project->enableOdoo($this->odooVersion);
                $project->refresh();
            }
            if ($this->service !== '') {
                $created = $this->createChosenService($project, $productionEnvironment);
            }

            if ($this->service === 'odoo' && $this->connectGithub) {
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

                return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
            }

            if ($created instanceof Service) {
                return redirect()->route('project.service.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $productionEnvironment->uuid,
                    'service_uuid' => $created->uuid,
                ]);
            }

            return redirect()->route('project.resource.index', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $productionEnvironment->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $names = collect();
        try {
            $names = get_service_templates()->keys()->map(fn ($name): string => (string) $name)->values();
        } catch (\Throwable) {
            $names = collect();
        }
        if (! $names->contains('odoo')) {
            $names->prepend('odoo');
        }

        return view('livewire.project.add-empty', [
            'creationQuota' => app(AdminCreationQuota::class)->summaryForViewer(),
            'serviceOptions' => $names
                ->map(fn (string $name): array => ['value' => $name, 'label' => $name === 'odoo' ? 'Odoo' : $name])
                ->prepend(['value' => '', 'label' => __('No service yet')])
                ->values()
                ->all(),
        ]);
    }

    private function createChosenService(Project $project, $environment): ?Service
    {
        $destination = StandaloneDocker::ownedByCurrentTeam()->first()
            ?? SwarmDocker::ownedByCurrentTeam()->first();
        if ($destination === null || $environment === null) {
            return null;
        }

        $templates = get_service_templates();
        $encoded = data_get($templates, $this->service.'.compose');
        $compose = is_string($encoded) && $encoded !== ''
            ? base64_decode($encoded)
            : ($this->service === 'odoo' ? OdooComposeTemplate::defaultCompose($this->odooVersion) : null);
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
            'jupyter_enabled' => $this->service === 'odoo' && ! $this->connectGithub,
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
