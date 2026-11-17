<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DashboardOverview
{
    public const ATTENTION_LIMIT = 6;

    public const STATE_ONLINE = 'online';

    public const STATE_STARTING = 'starting';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_OFFLINE = 'offline';

    /**
     * Build the dashboard state for projects and servers the caller already scoped to the current team.
     *
     * @param  Collection<int, Project>  $projects  Projects with `environments` loaded.
     * @param  Collection<int, Server>  $servers
     * @return array{
     *     projects: Collection<int, array<string, mixed>>,
     *     servers: Collection<int, array<string, mixed>>,
     *     attention: Collection<int, array<string, mixed>>,
     *     attentionTotal: int,
     *     totals: array{projects: int, environments: int, resources: int, online: int, starting: int, degraded: int, offline: int, servers: int, serversReady: int}
     * }
     */
    public function build(Collection $projects, Collection $servers): array
    {
        $environments = $projects->flatMap(function (Project $project) {
            $bareProject = $project->withoutRelations();

            return $project->environments->map(fn (Environment $environment) => $environment->withoutRelations()->setRelation('project', $bareProject));
        })->keyBy('id');

        $resources = $this->resourcesFor($environments);

        $projectRows = $projects->map(function (Project $project) use ($resources) {
            $own = $resources->filter(fn (array $resource) => $resource['project_id'] === $project->id);
            $states = $own->countBy('state');

            return [
                'model' => $project,
                'environments' => $project->environments->count(),
                'production' => $project->environments->filter(fn (Environment $environment) => $environment->isProduction())->count(),
                'staging' => $project->environments->filter(fn (Environment $environment) => $environment->isStaging())->count(),
                'resources' => $own->count(),
                'online' => $states->get(self::STATE_ONLINE, 0),
                'starting' => $states->get(self::STATE_STARTING, 0),
                'degraded' => $states->get(self::STATE_DEGRADED, 0),
                'offline' => $states->get(self::STATE_OFFLINE, 0),
                'health' => $this->projectHealth($states, $own->count(), $own->where('crashed', true)->count()),
            ];
        })
            ->sortByDesc(fn (array $row) => [$row['health'] === 'degraded' ? 1 : 0, $row['model']->updated_at?->timestamp ?? 0])
            ->values();

        $serverRows = $servers->map(fn (Server $server) => [
            'model' => $server,
            ...$this->serverState($server),
        ])->sortBy(fn (array $row) => [$row['type'] === 'success' ? 1 : 0, $row['model']->name])->values();

        $attention = $this->attentionItems($resources, $serverRows);
        $states = $resources->countBy('state');

        return [
            'projects' => $projectRows,
            'servers' => $serverRows,
            'attention' => $attention->take(self::ATTENTION_LIMIT)->values(),
            'attentionTotal' => $attention->count(),
            'totals' => [
                'projects' => $projects->count(),
                'environments' => $environments->count(),
                'resources' => $resources->count(),
                'online' => $states->get(self::STATE_ONLINE, 0),
                'starting' => $states->get(self::STATE_STARTING, 0),
                'degraded' => $states->get(self::STATE_DEGRADED, 0),
                'offline' => $states->get(self::STATE_OFFLINE, 0),
                'servers' => $servers->count(),
                'serversReady' => $serverRows->where('type', 'success')->count(),
            ],
        ];
    }

    public static function stateFor(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match (true) {
            $status === '', str_starts_with($status, 'exited') => self::STATE_OFFLINE,
            str_starts_with($status, 'starting'), str_starts_with($status, 'restarting') => self::STATE_STARTING,
            str_contains($status, 'unhealthy'), str_starts_with($status, 'degraded') => self::STATE_DEGRADED,
            str_starts_with($status, 'running') => self::STATE_ONLINE,
            default => self::STATE_OFFLINE,
        };
    }

    /**
     * @return array{status: string, type: string}
     */
    public function serverState(Server $server): array
    {
        $proxyNeedsAttention = $server->proxySet() && ($server->proxy->status !== 'running' || $server->hasCurrentTraefikOutdatedInfo());
        $sentinelNeedsAttention = $server->isSentinelEnabled() && ! $server->isSentinelLive();

        [$status, $type] = match (true) {
            (bool) $server->settings->force_disabled => ['Disabled', 'error'],
            ! $server->settings->is_reachable && ! $server->settings->is_usable => ['Unavailable', 'error'],
            ! $server->settings->is_reachable => ['Unreachable', 'error'],
            ! $server->settings->is_usable => ['Not ready', 'warning'],
            $proxyNeedsAttention || $sentinelNeedsAttention => ['Attention required', 'warning'],
            default => ['Ready', 'success'],
        };

        return ['status' => $status, 'type' => $type];
    }

    /**
     * @param  Collection<int, Environment>  $environments
     * @return Collection<int, array{name: string, kind: string, state: string, crashed: bool, status: ?string, link: ?string, project_id: int, project: string, environment: string, updated_at: mixed}>
     */
    private function resourcesFor(Collection $environments): Collection
    {
        if ($environments->isEmpty()) {
            return collect();
        }

        $environmentIds = $environments->keys();
        $models = collect();

        $models->push(...Application::query()
            ->whereIn('environment_id', $environmentIds)
            ->get(['id', 'uuid', 'name', 'status', 'environment_id', 'updated_at', 'restart_count', 'max_restart_count', 'last_restart_type'])
            ->all());

        $models->push(...Service::query()
            ->whereIn('environment_id', $environmentIds)
            ->with([
                'applications:id,service_id,status,exclude_from_status',
                'databases:id,service_id,status,exclude_from_status',
            ])
            ->get(['id', 'uuid', 'name', 'environment_id', 'updated_at'])
            ->all());

        foreach (STANDALONE_DATABASE_MODELS as $modelClass) {
            $models->push(...$modelClass::query()
                ->whereIn('environment_id', $environmentIds)
                ->get(['id', 'uuid', 'name', 'status', 'environment_id', 'updated_at'])
                ->all());
        }

        return $models->map(function (Model $resource) use ($environments) {
            $environment = $environments->get($resource->environment_id);
            $resource->setRelation('environment', $environment);
            $status = $resource->status;

            return [
                'name' => $resource->name,
                'kind' => $resource instanceof Application ? 'application' : ($resource instanceof Service ? 'service' : 'database'),
                'state' => self::stateFor($status),
                'crashed' => $resource instanceof Application && $resource->stoppedAfterRestartLimit(),
                'status' => $status,
                'link' => $resource->link(),
                'project_id' => $environment->project_id,
                'project' => $environment->project->name,
                'environment' => $environment->name,
                'updated_at' => $resource->updated_at,
            ];
        })->values();
    }

    /**
     * @param  Collection<string, int>  $states
     */
    private function projectHealth(Collection $states, int $total, int $crashed): string
    {
        return match (true) {
            $total === 0 => 'empty',
            $states->get(self::STATE_DEGRADED, 0) > 0 || $crashed > 0 => 'degraded',
            $states->get(self::STATE_OFFLINE, 0) === $total => 'offline',
            $states->get(self::STATE_OFFLINE, 0) > 0 => 'partial',
            $states->get(self::STATE_STARTING, 0) > 0 => 'starting',
            default => 'online',
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $resources
     * @param  Collection<int, array<string, mixed>>  $servers
     * @return Collection<int, array{severity: string, kind: string, name: string, context: string, status: string, link: ?string}>
     */
    private function attentionItems(Collection $resources, Collection $servers): Collection
    {
        $serverItems = $servers
            ->filter(fn (array $row) => $row['type'] !== 'success')
            ->map(fn (array $row) => [
                'severity' => $row['type'] === 'error' ? 'error' : 'warning',
                'kind' => 'server',
                'name' => $row['model']->name,
                'context' => __('Server'),
                'status' => __($row['status']),
                'link' => route('server.show', ['server_uuid' => $row['model']->uuid]),
            ]);

        $resourceItems = $resources
            ->filter(fn (array $resource) => $resource['state'] === self::STATE_DEGRADED || $resource['crashed'])
            ->sortBy(fn (array $resource) => [$resource['project'], $resource['name']])
            ->map(fn (array $resource) => [
                'severity' => 'error',
                'kind' => $resource['kind'],
                'name' => $resource['name'],
                'context' => $resource['project'].' / '.$resource['environment'],
                'status' => $resource['crashed'] ? __('Crashed') : __('Unhealthy'),
                'link' => $resource['link'],
            ]);

        return $serverItems->concat($resourceItems)
            ->sortBy(fn (array $item) => $item['severity'] === 'error' ? 0 : 1)
            ->values();
    }
}
