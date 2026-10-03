<?php

namespace App\Livewire\Project;

use App\Enums\ProcessStatus;
use App\Models\Environment;
use App\Support\OdooStaging;
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
            if ($project !== null && strcasecmp((string) $environment->name, 'production') === 0) {
                foreach ($project->environments as $staging) {
                    if ($staging->id === $environment->id || ! OdooStaging::isStagingName((string) $staging->name)) {
                        continue;
                    }
                    $this->releaseWork($staging);
                    $staging->delete();
                }
            }
            $this->releaseWork($environment);
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
        $environment = Environment::ownedByCurrentTeam()->with('project.environments')->findOrFail($this->environment_id);
        $resources = $environment->resources();
        $actions = $resources->isEmpty()
            ? [__('This will delete the selected environment.')]
            : [__('Delete the resources in this environment: :resources', [
                'resources' => $resources->map(fn ($resource) => (string) $resource->name)->filter()->implode(', '),
            ])];
        if (strcasecmp((string) $environment->name, 'production') === 0) {
            $staging = $environment->project?->environments
                ->filter(fn (Environment $other): bool => OdooStaging::isStagingName((string) $other->name))
                ->pluck('name')
                ->implode(', ');
            if ($staging !== '') {
                $actions[] = __('Deleting production also deletes these staging environments: :names.', ['names' => $staging]);
            }
        }

        return view('livewire.project.delete-environment', [
            'actions' => $actions,
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
