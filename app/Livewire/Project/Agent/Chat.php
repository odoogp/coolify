<?php

namespace App\Livewire\Project\Agent;

use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\ProjectAgentMessage;
use App\Services\ProjectAgent\ProjectAgentRunner;
use App\Services\ProjectAgent\ProjectAgentToolGate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Component;

class Chat extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public ?string $environmentUuid = null;

    public string $draft = '';

    /** @var list<array{role: string, content: string, id?: int}> */
    public array $messages = [];

    public function mount(string $project_uuid, ?string $environment_uuid = null): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environmentUuid = $environment_uuid;
        $this->loadMessages();
    }

    public function send(): void
    {
        $this->authorize('update', $this->project);

        $validated = Validator::make(['draft' => $this->draft], [
            'draft' => ['required', 'string', 'max:8000'],
        ])->validate();

        $agent = $this->project->agent()->first();
        if (! $agent instanceof ProjectAgent || ! $agent->isReady()) {
            $this->dispatch('error', __('Enable the project agent and set provider + API key first.'));

            return;
        }

        $history = collect($this->messages)
            ->map(fn (array $row): array => [
                'role' => $row['role'],
                'content' => $row['content'],
            ])
            ->take(-20)
            ->values()
            ->all();

        $runner = new ProjectAgentRunner($agent, new ProjectAgentToolGate($this->project, $agent));
        try {
            $reply = $runner->chat($validated['draft'], $history, $this->environmentUuid);
            $runner->persistTurn(
                $validated['draft'],
                $reply['content'],
                auth()->id(),
                $this->environmentUuid,
                $reply['meta'],
            );
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('error', $e->getMessage());

            return;
        }

        $this->draft = '';
        $this->loadMessages();
    }

    public function render(): View
    {
        $agent = $this->project->agent;

        return view('livewire.project.agent.chat', [
            'agentReady' => $agent instanceof ProjectAgent && $agent->isReady(),
            'agentName' => $agent?->name ?? __('Project agent'),
        ]);
    }

    private function loadMessages(): void
    {
        $agent = $this->project->agent()->first();
        if (! $agent instanceof ProjectAgent) {
            $this->messages = [];

            return;
        }

        $this->messages = $agent->messages()
            ->latest('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ProjectAgentMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();
    }
}
