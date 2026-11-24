<?php

namespace App\Livewire;

use App\Actions\Service\StartService;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Support\ResourceOverview;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

class Dashboard extends Component
{
    use AuthorizesRequests;

    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public function mount()
    {
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->loadProjects();
    }

    /**
     * "Start" from the "Needs attention" list. Services start in place; applications
     * queue a deployment, the same as the Deploy button on their page.
     */
    public function startResource(string $kind, string $uuid): void
    {
        try {
            if ($kind === 'service') {
                $service = Service::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail();
                $this->authorize('deploy', $service);
                StartService::run($service, pullLatestImages: true);
                $this->dispatch('success', __(':name is starting.', ['name' => ResourceOverview::readableName((string) $service->name, (string) $service->uuid)]));
            } elseif ($kind === 'application') {
                $application = Application::ownedByCurrentTeam()->where('uuid', $uuid)->firstOrFail();
                $this->authorize('deploy', $application);
                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: new_public_id(),
                );
                if (in_array($result['status'] ?? null, ['queue_full', 'skipped'], true)) {
                    $this->dispatch('error', (string) ($result['message'] ?? __('The deployment could not be queued.')));

                    return;
                }
                $this->dispatch('success', __(':name is starting.', ['name' => $application->name]));
            }
        } catch (Throwable $e) {
            handleError($e, $this);
        }

        $this->loadProjects();
    }

    public function render()
    {
        $rows = $this->projects->flatMap(fn (Project $project) => ResourceOverview::rows($project));

        return view('livewire.dashboard', [
            'resourceRows' => $rows,
            'resourceRowsByProject' => $this->projects->mapWithKeys(fn (Project $project) => [
                $project->id => ResourceOverview::rows($project),
            ]),
        ]);
    }

    private function loadProjects(): void
    {
        $this->projects = Project::ownedByCurrentTeam()
            ->with(ResourceOverview::eagerLoads())
            ->withCount([
                'applications',
                'services',
                'postgresqls',
                'redis',
                'keydbs',
                'dragonflies',
                'clickhouses',
                'mongodbs',
                'mysqls',
                'mariadbs',
            ])
            ->get();
    }
}
