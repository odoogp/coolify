<?php

namespace App\Livewire\Project\Service;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Deployments extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    public Service $service;

    public ?Application $addonsApplication = null;

    public function mount(string $project_uuid, string $environment_uuid, string $service_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->service = $this->environment->services()->where('uuid', $service_uuid)->firstOrFail();
        $this->authorize('view', $this->service);

        if ($this->service->supportsOdooJupyter()) {
            $this->addonsApplication = OdooGit::ensureAddonsApplication($this->environment, $this->service);
        }

        if (! $this->addonsApplication instanceof Application) {
            $this->addonsApplication = $this->environment->applications()
                ->where('is_odoo_addons', true)
                ->first();
        }
    }

    public function render()
    {
        return view('livewire.project.service.deployments');
    }
}
