<?php

namespace App\Livewire\Project\Agent;

use App\Models\Project;
use App\Models\ProjectAgent;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Component;

class Settings extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public string $name = 'Project agent';

    public string $provider = 'openai';

    public string $model = 'gpt-4o-mini';

    public string $apiKey = '';

    public string $baseUrl = '';

    public bool $isEnabled = false;

    public bool $followInCloud = true;

    public bool $apiKeySet = false;

    public function mount(string $project_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->hydrateFromAgent();
    }

    public function save(): void
    {
        $this->authorize('update', $this->project);

        $validated = Validator::make([
            'name' => $this->name,
            'provider' => $this->provider,
            'model' => $this->model,
            'apiKey' => $this->apiKey,
            'baseUrl' => $this->baseUrl,
            'isEnabled' => $this->isEnabled,
            'followInCloud' => $this->followInCloud,
        ], [
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'in:'.implode(',', array_keys(ProjectAgent::PROVIDERS))],
            'model' => ['required', 'string', 'max:128'],
            'apiKey' => ['nullable', 'string', 'max:500'],
            'baseUrl' => ['nullable', 'url', 'max:255'],
            'isEnabled' => ['boolean'],
            'followInCloud' => ['boolean'],
        ])->validate();

        $agent = ProjectAgent::query()->firstOrNew(['project_id' => $this->project->id]);
        $agent->fill([
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'model' => $validated['model'],
            'base_url' => $validated['baseUrl'] ?: null,
            'is_enabled' => $validated['isEnabled'],
            'follow_in_cloud' => $validated['followInCloud'],
        ]);
        if (filled($validated['apiKey'])) {
            $agent->api_key = $validated['apiKey'];
        }
        $agent->save();

        $this->apiKey = '';
        $this->hydrateFromAgent();
        $this->dispatch('success', __('Project agent saved.'));
        $this->dispatch('project-agent-updated');
    }

    public function render(): View
    {
        return view('livewire.project.agent.settings', [
            'providers' => collect(ProjectAgent::PROVIDERS)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ]);
    }

    private function hydrateFromAgent(): void
    {
        $agent = $this->project->agent()->first();
        if (! $agent instanceof ProjectAgent) {
            $this->apiKeySet = false;

            return;
        }

        $this->name = (string) $agent->name;
        $this->provider = (string) $agent->provider;
        $this->model = (string) $agent->model;
        $this->baseUrl = (string) ($agent->base_url ?? '');
        $this->isEnabled = (bool) $agent->is_enabled;
        $this->followInCloud = (bool) $agent->follow_in_cloud;
        $this->apiKeySet = filled($agent->api_key);
    }
}
