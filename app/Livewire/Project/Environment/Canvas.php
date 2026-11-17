<?php

namespace App\Livewire\Project\Environment;

use App\Models\Environment;
use App\Models\EnvironmentCanvasLayout;
use App\Models\Project;
use App\Support\EnvironmentCanvasGraph;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class Canvas extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    /** @var array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, meta: array<string, mixed>} */
    public array $graph = ['nodes' => [], 'edges' => [], 'meta' => []];

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->refreshGraph(replacePositions: false, notify: false);
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
    }

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
