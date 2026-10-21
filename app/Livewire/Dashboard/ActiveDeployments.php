<?php

namespace App\Livewire\Dashboard;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class ActiveDeployments extends Component
{
    public const PER_PAGE = 5;

    public Collection $activeDeployments;

    public Collection $recentDeployments;

    public int $activePage = 1;

    public int $recentPage = 1;

    public int $activeTotal = 0;

    public int $recentTotal = 0;

    public function mount(): void
    {
        $this->refreshDeployments();
    }

    public function refreshDeployments(): void
    {
        $serverIds = Server::ownedByCurrentTeamCached()->pluck('id');

        $columns = [
            'id',
            'application_id',
            'application_name',
            'deployment_url',
            'deployment_uuid',
            'pull_request_id',
            'server_name',
            'server_id',
            'status',
            'created_at',
            'finished_at',
        ];

        $baseQuery = ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->whereIn('server_id', $serverIds);

        $activeQuery = (clone $baseQuery)
            ->whereIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ]);

        $this->activeTotal = (clone $activeQuery)->count();
        $this->activePage = $this->clampPage($this->activePage, $this->activeTotal);
        $this->activeDeployments = (clone $activeQuery)
            ->orderBy('status')
            ->orderBy('id')
            ->forPage($this->activePage, self::PER_PAGE)
            ->get($columns);

        $recentQuery = (clone $baseQuery)
            ->whereNotIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ]);

        $this->recentTotal = (clone $recentQuery)->count();
        $this->recentPage = $this->clampPage($this->recentPage, $this->recentTotal);
        $this->recentDeployments = (clone $recentQuery)
            ->orderByDesc('id')
            ->forPage($this->recentPage, self::PER_PAGE)
            ->get($columns);
    }

    public function previousActivePage(): void
    {
        $this->activePage = max(1, $this->activePage - 1);
        $this->refreshDeployments();
    }

    public function nextActivePage(): void
    {
        $this->activePage = $this->clampPage($this->activePage + 1, $this->activeTotal);
        $this->refreshDeployments();
    }

    public function previousRecentPage(): void
    {
        $this->recentPage = max(1, $this->recentPage - 1);
        $this->refreshDeployments();
    }

    public function nextRecentPage(): void
    {
        $this->recentPage = $this->clampPage($this->recentPage + 1, $this->recentTotal);
        $this->refreshDeployments();
    }

    private function clampPage(int $page, int $total): int
    {
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        return max(1, min($page, $lastPage));
    }

    public function render()
    {
        return view('livewire.dashboard.active-deployments');
    }
}
