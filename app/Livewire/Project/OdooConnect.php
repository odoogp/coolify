<?php

namespace App\Livewire\Project;

use App\Models\Service;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OdooConnect extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $projectUuid;

    #[Locked]
    public string $environmentUuid;

    #[Locked]
    public string $serviceUuid;

    public bool $open = false;

    /** @var list<array{name: string, login: string}> */
    public array $users = [];

    public function connectAs(): void
    {
        $service = $this->service();
        $this->authorize('view', $service);
        $this->users = OdooGit::internalUsers($service);
        $this->open = true;
    }

    public function render()
    {
        return view('livewire.project.odoo-connect', [
            'enterUrl' => route('project.service.odoo.enter', [
                'project_uuid' => $this->projectUuid,
                'environment_uuid' => $this->environmentUuid,
                'service_uuid' => $this->serviceUuid,
            ]),
        ]);
    }

    private function service(): Service
    {
        $service = Service::query()->where('uuid', $this->serviceUuid)->firstOrFail();
        $environment = $service->environment;
        abort_unless(
            $environment !== null
            && $environment->uuid === $this->environmentUuid
            && $environment->project?->uuid === $this->projectUuid,
            404,
        );

        return $service;
    }
}
