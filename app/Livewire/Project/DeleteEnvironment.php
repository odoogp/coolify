<?php

namespace App\Livewire\Project;

use App\Models\Environment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DeleteEnvironment extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $environment_id;

    public bool $disabled = false;

    public string $environmentName = '';

    public function mount()
    {
        $this->environmentName = Environment::ownedByCurrentTeam()->findOrFail($this->environment_id)->name;
    }

    public function delete(?string $password = null, array $selectedActions = [])
    {
        try {
            $this->validate([
                'environment_id' => 'required|int',
            ]);
            $environment = Environment::ownedByCurrentTeam()->with('project')->findOrFail($this->environment_id);
            $this->authorize('delete', $environment);
            $projectUuid = $environment->project?->uuid;
            $environment->delete();

            if (! is_string($projectUuid) || $projectUuid === '') {
                return redirectRoute($this, 'project.index');
            }

            return redirectRoute($this, 'project.show', ['project_uuid' => $projectUuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $environment = Environment::ownedByCurrentTeam()->findOrFail($this->environment_id);
        $resources = $environment->resources();
        $actions = $resources->isEmpty()
            ? [__('This will delete the selected environment.')]
            : [__('Delete the resources in this environment: :resources', [
                'resources' => $resources->map(fn ($resource) => (string) $resource->name)->filter()->implode(', '),
            ])];

        return view('livewire.project.delete-environment', [
            'actions' => $actions,
        ]);
    }
}
