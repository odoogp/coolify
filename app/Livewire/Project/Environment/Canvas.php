<?php

namespace App\Livewire\Project\Environment;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Environment;
use App\Models\EnvironmentCanvasLayout;
use App\Models\Project;
use App\Models\Service;
use App\Support\EnvironmentCanvasGraph;
use App\Support\OdooGit;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Process;
use Illuminate\View\View;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

class Canvas extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    /** @var array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>} */
    public array $graph = ['nodes' => [], 'edges' => [], 'meta' => []];

    #[Url(as: 'inspect')]
    public ?string $inspector = null;

    #[Url(as: 'node')]
    public ?string $inspectorNodeId = null;

    public string $inspectorTab = 'details';

    public ?string $inspectorTitle = null;

    public ?string $inspectorKind = null;

    public ?string $inspectorStatus = null;

    public ?string $monitorEmbedUrl = null;

    public string $logOutput = '';

    /** @var list<string> */
    public array $stackRules = [];

    public ?string $githubServiceUuid = null;

    public ?string $sentinelUrl = null;

    public ?string $sentinelStatus = null;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->refreshGraph(replacePositions: false, notify: false);

        if (filled($this->inspector) && filled($this->inspectorNodeId)) {
            $this->openInspector($this->inspectorNodeId, $this->inspector === 'github' ? 'github' : 'details');
        }
    }

    public function refreshGraph(bool $replacePositions = false, bool $notify = true): void
    {
        $this->authorize('view', $this->project);
        $this->environment->refresh();
        $this->graph = EnvironmentCanvasGraph::build($this->environment);

        if ($notify) {
            $this->dispatch(
                'canvas-graph-updated',
                graph: $this->graph,
                replacePositions: $replacePositions,
            );
        }
    }

    public function pollGraph(): void
    {
        $this->refreshGraph(replacePositions: false, notify: true);
        if ($this->inspectorTab === 'logs' && filled($this->inspectorNodeId)) {
            $this->refreshLogs();
        }
    }

    public function openInspector(string $nodeId, string $panel = 'details'): void
    {
        $this->authorize('view', $this->project);
        $node = collect($this->graph['nodes'])->firstWhere('id', $nodeId);
        if (! is_array($node)) {
            return;
        }

        $kind = (string) ($node['kind'] ?? 'service');
        $this->inspectorNodeId = $nodeId;
        $this->inspectorTitle = (string) ($node['label'] ?? $kind);
        $this->inspectorKind = $kind;
        $this->inspectorStatus = (string) ($node['status_label'] ?? $node['status'] ?? '');
        $this->monitorEmbedUrl = is_string($node['monitor_url'] ?? null) ? $node['monitor_url'] : null;
        $this->logOutput = '';
        $this->stackRules = is_array($node['rules'] ?? null)
            ? array_values(array_filter($node['rules'], fn ($rule): bool => is_string($rule) && $rule !== ''))
            : [];
        $this->githubServiceUuid = is_string($node['service_uuid'] ?? null) ? $node['service_uuid'] : null;
        $this->resolveSentinel($node);

        $panel = trim($panel);
        if ($kind === 'github' || $panel === 'github') {
            $this->inspector = 'github';
            $this->inspectorTab = 'github';
            if (! filled($this->githubServiceUuid) && str_starts_with($nodeId, 'github:')) {
                $this->githubServiceUuid = substr($nodeId, strlen('github:'));
            }

            return;
        }

        $this->inspector = 'node';
        $this->inspectorTab = match ($panel) {
            'logs' => 'logs',
            'monitor', 'metrics' => 'metrics',
            default => 'details',
        };

        if ($this->inspectorTab === 'logs') {
            $this->refreshLogs();
        }
    }

    public function setInspectorTab(string $tab): void
    {
        if (! in_array($tab, ['details', 'logs', 'metrics', 'github'], true)) {
            return;
        }
        $this->inspectorTab = $tab;
        if ($tab === 'logs') {
            $this->refreshLogs();
        }
        if ($tab === 'github') {
            $this->inspector = 'github';
        }
    }

    public function closeInspector(): void
    {
        $this->inspector = null;
        $this->inspectorNodeId = null;
        $this->inspectorTab = 'details';
        $this->inspectorTitle = null;
        $this->inspectorKind = null;
        $this->inspectorStatus = null;
        $this->monitorEmbedUrl = null;
        $this->logOutput = '';
        $this->stackRules = [];
        $this->githubServiceUuid = null;
        $this->sentinelUrl = null;
        $this->sentinelStatus = null;
    }

    public function refreshLogs(): void
    {
        if ($this->inspectorTab !== 'logs' || ! filled($this->inspectorNodeId)) {
            return;
        }

        $this->authorize('view', $this->project);
        $node = collect($this->graph['nodes'])->firstWhere('id', $this->inspectorNodeId);
        if (! is_array($node)) {
            $this->logOutput = __('Node not found.');

            return;
        }

        $container = is_string($node['container'] ?? null) ? $node['container'] : '';
        $serviceUuid = is_string($node['service_uuid'] ?? null) ? $node['service_uuid'] : '';
        if ($container === '' || $serviceUuid === '' || ! ValidationPatterns::isValidContainerName($container)) {
            $this->logOutput = __('No container logs for this node.');

            return;
        }

        $service = $this->environment->services->firstWhere('uuid', $serviceUuid)
            ?? Service::ownedByCurrentTeam()->where('uuid', $serviceUuid)->first();
        if (! $service instanceof Service || (int) $service->environment_id !== (int) $this->environment->id) {
            $this->logOutput = __('Service not found.');

            return;
        }

        if ($service->supportsOdooJupyter() && ! isInstanceOwner() && ! OdooGit::clientSeesLog($container)) {
            $this->logOutput = __('Unauthorized.');

            return;
        }

        $server = $service->server;
        if (! $server || ! $server->isFunctional()) {
            $this->logOutput = __('Server is not ready.');

            return;
        }

        try {
            $command = $server->isSwarm()
                ? "docker service logs -n 200 -t {$container}"
                : "docker logs -n 200 -t {$container}";
            if ($server->isNonRoot()) {
                $command = parseCommandsByLineForSudo(collect($command), $server)[0];
            }
            $ssh = SshMultiplexingHelper::generateSshCommand($server, "({$command}) 2>&1 | head -c 200000");
            $output = Process::timeout(config('constants.ssh.command_timeout'))->run($ssh)->output();
            $this->logOutput = rtrim(removeAnsiColors($output), "\n");
            if ($this->logOutput === '') {
                $this->logOutput = __('No log lines yet.');
            }
        } catch (\Throwable $e) {
            report($e);
            $this->logOutput = __('Could not load logs.');
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolveSentinel(array $node): void
    {
        $this->sentinelUrl = null;
        $this->sentinelStatus = null;
        $serviceUuid = is_string($node['service_uuid'] ?? null) ? $node['service_uuid'] : null;
        if ($serviceUuid === null && is_string($this->githubServiceUuid)) {
            $serviceUuid = $this->githubServiceUuid;
        }
        if ($serviceUuid === null) {
            return;
        }
        $service = $this->environment->services->firstWhere('uuid', $serviceUuid);
        $server = $service?->server;
        if (! $server) {
            return;
        }
        $this->sentinelStatus = $server->isSentinelEnabled()
            ? $server->sentinelStatus()
            : 'disabled';
        if ($server->isSentinelEnabled()) {
            $this->sentinelUrl = route('server.sentinel', ['server_uuid' => $server->uuid]);
        }
    }

    #[Renderless]
    public function savePosition(string $nodeId, float $x, float $y): void
    {
        $this->authorize('update', $this->project);

        $nodeId = trim($nodeId);
        if ($nodeId === '') {
            return;
        }

        $layout = EnvironmentCanvasLayout::query()->firstOrCreate(
            ['environment_id' => $this->environment->id],
            ['positions' => [], 'groups' => []],
        );

        $positions = $layout->positionsMap();
        $positions[$nodeId] = [
            'x' => max(0, round($x, 1)),
            'y' => max(0, round($y, 1)),
        ];
        $layout->positions = $positions;
        $layout->save();
    }

    public function resetLayout(): void
    {
        $this->authorize('update', $this->project);
        EnvironmentCanvasLayout::query()
            ->where('environment_id', $this->environment->id)
            ->delete();
        $this->refreshGraph(replacePositions: true, notify: true);
        $this->dispatch('success', __('Canvas layout reset.'));
    }

    public function render(): View
    {
        return view('livewire.project.environment.canvas', [
            'canUpdate' => auth()->user()?->can('update', $this->project) ?? false,
        ]);
    }
}
