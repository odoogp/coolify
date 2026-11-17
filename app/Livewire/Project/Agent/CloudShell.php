<?php

namespace App\Livewire\Project\Agent;

use App\Models\Project;
use App\Models\ProjectAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CloudShell extends Component
{
    public bool $open = false;

    public ?string $projectUuid = null;

    /** Hidden while the canvas already shows the agent chat in its side panel. */
    public bool $suppressedBySideChat = false;

    public function mount(): void
    {
        $userId = auth()->id();
        if ($userId === null) {
            return;
        }
        $cached = Cache::get('project-agent-follow:'.$userId);
        if (is_string($cached) && $cached !== '') {
            $this->projectUuid = $cached;
        }
    }

    #[On('follow-project-agent')]
    public function followProject(string $projectUuid): void
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $projectUuid)->first();
        if (! $project instanceof Project || ! auth()->user()?->can('view', $project)) {
            return;
        }
        $agent = $project->agent;
        if (! $agent instanceof ProjectAgent || ! $agent->is_enabled || ! $agent->follow_in_cloud) {
            return;
        }
        $this->projectUuid = $project->uuid;
        Cache::put('project-agent-follow:'.auth()->id(), $project->uuid, now()->addDays(7));
        if (! $this->suppressedBySideChat) {
            $this->open = true;
        }
    }

    #[On('canvas-agent-side-chat')]
    public function setSideChatActive(bool $active = true): void
    {
        $this->suppressedBySideChat = $active;
        if ($active) {
            $this->open = false;
        }
    }

    public function stopFollowing(): void
    {
        $this->projectUuid = null;
        $this->open = false;
        if (auth()->id()) {
            Cache::forget('project-agent-follow:'.auth()->id());
        }
    }

    public function toggle(): void
    {
        if (! filled($this->projectUuid)) {
            return;
        }
        $this->open = ! $this->open;
    }

    public function render(): View
    {
        $project = null;
        $agent = null;
        if (filled($this->projectUuid)) {
            $project = Project::ownedByCurrentTeam()->where('uuid', $this->projectUuid)->first();
            $agent = $project?->agent;
            if (! $agent instanceof ProjectAgent || ! $agent->follow_in_cloud || ! $agent->is_enabled) {
                $project = null;
                $agent = null;
            }
        }

        return view('livewire.project.agent.cloud-shell', [
            'project' => $project,
            'agent' => $agent,
        ]);
    }
}
