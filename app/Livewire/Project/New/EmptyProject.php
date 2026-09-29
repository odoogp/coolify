<?php

namespace App\Livewire\Project\New;

use App\Models\Project;
use App\Services\AdminCreationQuota;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class EmptyProject extends Component
{
    use AuthorizesRequests;

    public function createEmptyProject()
    {
        $this->authorize('create', Project::class);

        $project = app(AdminCreationQuota::class)->createProject(auth()->user(), [
            'name' => generate_random_name(),
            'team_id' => currentTeam()->id,
            'uuid' => new_public_id(),
        ]);

        return redirectRoute($this, 'project.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $project->environments->first()->uuid]);
    }
}
