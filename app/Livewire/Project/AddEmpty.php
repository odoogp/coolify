<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Services\AdminCreationQuota;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class AddEmpty extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $description = '';

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
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
        return view('livewire.project.add-empty', [
            'creationQuota' => app(AdminCreationQuota::class)->summaryForViewer(),
        ]);
    }
}
