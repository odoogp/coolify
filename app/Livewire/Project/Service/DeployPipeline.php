<?php

namespace App\Livewire\Project\Service;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Service;
use App\Support\OdooDeployPipeline;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class DeployPipeline extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public ?int $applicationId = null;

    public function mount(Service $service, ?Application $application = null): void
    {
        $this->authorize('view', $service);
        $this->service = $service;
        $this->applicationId = $application?->id;
    }

    public function getPipelineProperty(): array
    {
        $deployment = null;
        if ($this->applicationId) {
            $deployment = ApplicationDeploymentQueue::query()
                ->where('application_id', $this->applicationId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
        }

        return OdooDeployPipeline::fromDeployment($deployment);
    }

    public function render(): View
    {
        $pipeline = $this->pipeline;

        return view('livewire.project.service.deploy-pipeline', [
            'pipeline' => $pipeline,
            'polling' => (bool) ($pipeline['active'] ?? false),
        ]);
    }
}
