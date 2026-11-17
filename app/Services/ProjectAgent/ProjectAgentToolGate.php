<?php

namespace App\Services\ProjectAgent;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Collection;

/**
 * Hard boundary: the project agent may only read/act inside one Project,
 * and only against canvas nodes the user connected to the agent.
 */
class ProjectAgentToolGate
{
    public function __construct(
        public Project $project,
        public ?ProjectAgent $agent = null,
    ) {}

    public function assertSameProject(Project $candidate): void
    {
        if ((int) $candidate->id !== (int) $this->project->id) {
            throw new \RuntimeException('The project agent cannot leave this project.');
        }
    }

    /**
     * @return list<string>
     */
    public function connectedNodeIds(): array
    {
        return $this->agent?->connectedNodeIds() ?? [];
    }

    public function hasConnections(): bool
    {
        return $this->connectedNodeIds() !== [];
    }

    public function environment(string $uuid): Environment
    {
        $environment = $this->project->environments()->where('uuid', $uuid)->first();
        if (! $environment instanceof Environment) {
            throw new \RuntimeException('Environment is outside this project.');
        }

        return $environment;
    }

    public function service(string $uuid): Service
    {
        $service = Service::query()
            ->where('uuid', $uuid)
            ->whereHas('environment', fn ($q) => $q->where('project_id', $this->project->id))
            ->first();
        if (! $service instanceof Service) {
            throw new \RuntimeException('Service is outside this project.');
        }

        return $service;
    }

    /**
     * Require that the agent is wired (canvas edge) to this service somehow.
     */
    public function assertServiceConnected(string $serviceUuid): Service
    {
        $service = $this->service($serviceUuid);
        if (! $this->isServiceConnected($serviceUuid)) {
            throw new \RuntimeException(
                'Connect the agent to a node of this service on the canvas before using it.'
            );
        }

        return $service;
    }

    public function isServiceConnected(string $serviceUuid): bool
    {
        foreach ($this->connectedNodeIds() as $nodeId) {
            if ($this->serviceUuidForNode($nodeId) === $serviceUuid) {
                return true;
            }
        }

        return false;
    }

    public function serviceUuidForNode(string $nodeId): ?string
    {
        if (str_starts_with($nodeId, 'service:') || str_starts_with($nodeId, 'github:')) {
            $uuid = explode(':', $nodeId, 2)[1] ?? '';

            return $uuid !== '' ? $uuid : null;
        }

        if (str_starts_with($nodeId, 'compose-vol:')) {
            // compose-vol:{serviceUuid}:{volumeName}
            $parts = explode(':', $nodeId, 3);

            return ($parts[1] ?? '') !== '' ? $parts[1] : null;
        }

        if (str_starts_with($nodeId, 'stack-app:')) {
            $uuid = explode(':', $nodeId, 2)[1] ?? '';
            if ($uuid === '') {
                return null;
            }
            $app = ServiceApplication::query()->where('uuid', $uuid)->first();
            if (! $app instanceof ServiceApplication) {
                return null;
            }
            $service = $app->service;
            if (! $service instanceof Service || (int) $service->environment?->project_id !== (int) $this->project->id) {
                return null;
            }

            return $service->uuid;
        }

        if (str_starts_with($nodeId, 'stack-db:')) {
            $uuid = explode(':', $nodeId, 2)[1] ?? '';
            if ($uuid === '') {
                return null;
            }
            $db = ServiceDatabase::query()->where('uuid', $uuid)->first();
            if (! $db instanceof ServiceDatabase) {
                return null;
            }
            $service = $db->service;
            if (! $service instanceof Service || (int) $service->environment?->project_id !== (int) $this->project->id) {
                return null;
            }

            return $service->uuid;
        }

        if (str_starts_with($nodeId, 'volume:') || str_starts_with($nodeId, 'domain:')) {
            // These ids encode storage/domain identity; resolve via owning service when possible.
            return null;
        }

        return null;
    }

    /**
     * Services the agent may touch — intersection of project ownership and canvas edges.
     *
     * @return Collection<int, array{uuid: string, name: string, environment: string, status: string, via_nodes: list<string>}>
     */
    public function listConnectedServices(): Collection
    {
        if (! $this->hasConnections()) {
            return collect();
        }

        $byUuid = [];
        foreach ($this->connectedNodeIds() as $nodeId) {
            $serviceUuid = $this->serviceUuidForNode($nodeId);
            if ($serviceUuid === null) {
                continue;
            }
            try {
                $service = $this->service($serviceUuid);
            } catch (\Throwable) {
                continue;
            }
            $environment = $service->environment;
            if (! isset($byUuid[$serviceUuid])) {
                $byUuid[$serviceUuid] = [
                    'uuid' => $service->uuid,
                    'name' => (string) $service->name,
                    'environment' => (string) ($environment?->name ?? ''),
                    'environment_uuid' => (string) ($environment?->uuid ?? ''),
                    'status' => (string) ($service->status ?? ''),
                    'via_nodes' => [],
                ];
            }
            $byUuid[$serviceUuid]['via_nodes'][] = $nodeId;
        }

        return collect(array_values($byUuid));
    }

    /**
     * @return Collection<int, array{uuid: string, name: string, environment: string, status: string}>
     */
    public function listServices(): Collection
    {
        // Prefer connection-scoped inventory. Unconnected agents see nothing actionable.
        if ($this->agent instanceof ProjectAgent) {
            return $this->listConnectedServices()
                ->map(fn (array $row): array => [
                    'uuid' => $row['uuid'],
                    'name' => $row['name'],
                    'environment' => $row['environment'],
                    'environment_uuid' => $row['environment_uuid'],
                    'status' => $row['status'],
                ])
                ->values();
        }

        $this->project->loadMissing(['environments.services']);

        return $this->project->environments
            ->flatMap(function (Environment $environment) {
                return $environment->services->map(fn (Service $service): array => [
                    'uuid' => $service->uuid,
                    'name' => (string) $service->name,
                    'environment' => (string) $environment->name,
                    'environment_uuid' => $environment->uuid,
                    'status' => (string) ($service->status ?? ''),
                ]);
            })
            ->values();
    }

    public function addonsApplicationForService(Service $service): ?Application
    {
        $environment = $service->environment;
        if (! $environment instanceof Environment || (int) $environment->project_id !== (int) $this->project->id) {
            throw new \RuntimeException('Service is outside this project.');
        }

        return $environment->applications()
            ->where('is_odoo_addons', true)
            ->first();
    }

    /**
     * @param  list<string>  $nodeIds
     * @return list<array{id: string, kind: string, summary: string, service_uuid: ?string}>
     */
    public function describeConnectedNodes(array $nodeIds): array
    {
        $out = [];
        foreach ($nodeIds as $nodeId) {
            $kind = str_contains($nodeId, ':') ? explode(':', $nodeId, 2)[0] : 'unknown';
            $serviceUuid = $this->serviceUuidForNode($nodeId);
            $summary = match (true) {
                str_starts_with($nodeId, 'agent:') => 'This project AI agent.',
                str_starts_with($nodeId, 'github:') => 'GitHub source for a service in this project.',
                str_starts_with($nodeId, 'compose-vol:'), str_starts_with($nodeId, 'volume:') => 'Persistent volume inside this project.',
                str_starts_with($nodeId, 'domain:') => 'Public domain inside this project.',
                str_starts_with($nodeId, 'stack-app:') => $this->summarizeStackApp($nodeId),
                str_starts_with($nodeId, 'stack-db:') => $this->summarizeStackDb($nodeId),
                str_starts_with($nodeId, 'service:') && $serviceUuid !== null => $this->summarizeService($serviceUuid),
                default => 'Node inside this project.',
            };
            if ($summary === null) {
                continue;
            }
            $out[] = [
                'id' => $nodeId,
                'kind' => $kind,
                'summary' => $summary,
                'service_uuid' => $serviceUuid,
            ];
        }

        return $out;
    }

    private function summarizeService(string $uuid): ?string
    {
        try {
            $service = $this->service($uuid);

            return 'Service '.$service->name.' ('.$service->status.')';
        } catch (\Throwable) {
            return null;
        }
    }

    private function summarizeStackApp(string $nodeId): ?string
    {
        $uuid = explode(':', $nodeId, 2)[1] ?? '';
        $app = ServiceApplication::query()->where('uuid', $uuid)->first();
        if (! $app instanceof ServiceApplication) {
            return null;
        }
        $service = $app->service;
        if (! $service instanceof Service || (int) $service->environment?->project_id !== (int) $this->project->id) {
            return null;
        }

        return 'Stack app '.$app->name.' on service '.$service->name;
    }

    private function summarizeStackDb(string $nodeId): ?string
    {
        $uuid = explode(':', $nodeId, 2)[1] ?? '';
        $db = ServiceDatabase::query()->where('uuid', $uuid)->first();
        if (! $db instanceof ServiceDatabase) {
            return null;
        }
        $service = $db->service;
        if (! $service instanceof Service || (int) $service->environment?->project_id !== (int) $this->project->id) {
            return null;
        }

        return 'Stack database '.$db->name.' on service '.$service->name;
    }
}
