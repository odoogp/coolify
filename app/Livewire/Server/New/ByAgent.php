<?php

namespace App\Livewire\Server\New;

use App\Actions\Server\StartServerAgentEnrollment;
use App\Models\Server;
use App\Models\ServerAgentEnrollment;
use App\Services\Cloudflare\CloudflareTunnelApi;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * "Connect your server with one command": the client names the server, copies a
 * single command, runs it on their server and watches it come online here.
 */
class ByAgent extends Component
{
    use AuthorizesRequests;

    public bool $limit_reached = false;

    public string $name = '';

    #[Locked]
    public ?int $enrollmentId = null;

    #[Locked]
    public string $command = '';

    public function mount(bool $limit_reached = false): void
    {
        $this->limit_reached = $limit_reached;
        $this->name = __('My server');
    }

    public function startOver(): void
    {
        $this->enrollmentId = null;
        $this->command = '';
    }

    public function start(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        try {
            $this->authorize('create', Server::class);
            $result = StartServerAgentEnrollment::run(auth()->user(), currentTeam(), trim($this->name));
            $this->enrollmentId = $result['enrollment']->id;
            $this->command = $result['command'];
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());
        }
    }

    /**
     * @return array{state: string, title: string, detail: string, server: ?Server}
     */
    public function connectionState(): array
    {
        $enrollment = $this->enrollment();
        if (! $enrollment instanceof ServerAgentEnrollment) {
            return ['state' => 'idle', 'title' => '', 'detail' => '', 'server' => null];
        }

        $server = $enrollment->server;
        if (! $server instanceof Server) {
            return ['state' => 'failed', 'title' => __('This server was removed'), 'detail' => __('Start again to get a new command.'), 'server' => null];
        }

        if ($server->settings?->is_reachable && $server->settings?->is_usable) {
            if ($enrollment->status !== ServerAgentEnrollment::STATUS_CONNECTED) {
                $enrollment->update(['status' => ServerAgentEnrollment::STATUS_CONNECTED, 'connected_at' => now()]);
            }

            return ['state' => 'ready', 'title' => __('Your server is connected'), 'detail' => __('You can launch apps on it now.'), 'server' => $server];
        }

        if ($enrollment->status === ServerAgentEnrollment::STATUS_PENDING) {
            if ($enrollment->expires_at->isPast()) {
                return ['state' => 'failed', 'title' => __('The command expired'), 'detail' => __('Start again to get a new command.'), 'server' => $server];
            }

            return ['state' => 'waiting', 'title' => __('Waiting for your server'), 'detail' => __('Run the command on your server. This page updates by itself.'), 'server' => $server];
        }

        if (! $server->is_validating && filled($server->validation_logs)) {
            return ['state' => 'failed', 'title' => __('Your server answered, but it could not be prepared'), 'detail' => strip_tags((string) $server->validation_logs), 'server' => $server];
        }

        return ['state' => 'preparing', 'title' => __('Connected. Preparing your server'), 'detail' => __('Installing what your apps need. This takes a few minutes.'), 'server' => $server];
    }

    public function render(): View
    {
        return view('livewire.server.new.by-agent', [
            'available' => CloudflareTunnelApi::isConfigured(),
            'status' => $this->connectionState(),
        ]);
    }

    private function enrollment(): ?ServerAgentEnrollment
    {
        if ($this->enrollmentId === null || currentTeam() === null) {
            return null;
        }

        return ServerAgentEnrollment::query()
            ->ownedByTeam((int) currentTeam()->id)
            ->with('server.settings')
            ->find($this->enrollmentId);
    }
}
