<?php

namespace App\Livewire\Project;

use App\Enums\ProcessStatus;
use App\Models\Environment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class DeleteEnvironment extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public int $environment_id;

    public bool $disabled = false;

    public string $environmentName = '';

    public bool $delete_volumes = true;

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
            $environment = Environment::ownedByCurrentTeam()->with('project.environments')->findOrFail($this->environment_id);
            $this->authorize('delete', $environment);
            $project = $environment->project;
            $projectUuid = $project?->uuid;
            $deleteVolumes = in_array('delete_volumes', $selectedActions, true);
            if ($project !== null && $environment->isProduction()) {
                foreach ($project->environments as $staging) {
                    if ($staging->id === $environment->id || ! $staging->isStaging()) {
                        continue;
                    }
                    $this->releaseWork($staging);
                    $staging->deleteVolumesWithResources = $deleteVolumes;
                    $staging->delete();
                }
            }
            $this->releaseWork($environment);
            $environment->deleteVolumesWithResources = $deleteVolumes;
            $environment->delete();

            // Full page redirect (navigate: false). Nested Livewire + wire:navigate
            // often keeps /environment/{uuid} after delete → Resource Index 404.
            if (! is_string($projectUuid) || $projectUuid === '') {
                return $this->redirect(route('project.index'), navigate: false);
            }

            return $this->redirect(
                route('project.show', ['project_uuid' => $projectUuid]),
                navigate: false,
            );
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $environment = Environment::ownedByCurrentTeam()->with('project.environments')->find($this->environment_id);
        if ($environment === null) {
            return view('livewire.project.delete-environment', [
                'actions' => [],
                'checkboxes' => [],
            ]);
        }
        $resources = $environment->resources();
        $actions = $resources->isEmpty()
            ? [__('This will delete the selected environment.')]
            : [__('Delete the resources in this environment: :resources', [
                'resources' => $resources->map(fn ($resource) => (string) $resource->name)->filter()->implode(', '),
            ])];
        if ($environment->isProduction()) {
            $staging = $environment->project?->environments
                ->filter(fn (Environment $other): bool => $other->isStaging())
                ->pluck('name')
                ->implode(', ');
            if ($staging !== '') {
                $actions[] = __('Deleting production also deletes these staging environments: :names.', ['names' => $staging]);
            }
        }

        return view('livewire.project.delete-environment', [
            'actions' => $actions,
            'checkboxes' => [
                ['id' => 'delete_volumes', 'label' => __('resource.delete_volumes')],
            ],
        ]);
    }

    private function releaseWork(Environment $environment): void
    {
        $projectId = $environment->project_id;
        Cache::forget('odoo-clone-project-'.$projectId);
        Cache::forget('odoo-clone-'.$projectId.'-'.auth()->id());
        $environment->loadMissing('services');
        foreach ($environment->services as $service) {
            Cache::forget('launch-odoo-'.$service->uuid);
            Activity::query()
                ->where('properties->type_uuid', $service->uuid)
                ->where(function ($query): void {
                    $query->where('properties->status', ProcessStatus::IN_PROGRESS->value)
                        ->orWhere('properties->status', ProcessStatus::QUEUED->value);
                })
                ->get(['id', 'properties'])
                ->each(function (Activity $activity): void {
                    $activity->properties = collect($activity->properties)->put('status', ProcessStatus::CANCELLED->value);
                    $activity->save();
                });
        }
    }
}
