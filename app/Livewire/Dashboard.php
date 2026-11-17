<?php

namespace App\Livewire;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Services\DashboardOverview;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Dashboard extends Component
{
    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public function mount()
    {
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::ownedByCurrentTeam()
            ->with(['environments:id,uuid,name,role,project_id'])
            ->get();
    }

    public function render(): View
    {
        return view('livewire.dashboard', [
            'overview' => app(DashboardOverview::class)->build($this->projects, $this->servers),
        ]);
    }
}
