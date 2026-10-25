<?php

namespace App\Livewire;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Docker\GetContainersStatus;
use App\Models\Server;
use App\Models\Service;
use App\Support\ServiceStartupStages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ServiceStartupMonitor extends Component
{
    public Service $service;

    #[Locked]
    public $activityId = null;

    public $activity;

    public bool $isPollingActive = false;

    public bool $showLogs = false;

    public bool $isOnline = false;

    public bool $failed = false;

    public bool $notifiedOnline = false;

    public bool $requestedStatusCheck = false;

    /** @var list<array{key: string, title: string, description: string, status: string}> */
    public array $stages = [];

    public ?string $openUrl = null;

    public function mount(Service $service): void
    {
        $this->service = $service;
        $this->openUrl = $service->publicHttpsUrl() ?: null;
        $this->stages = ServiceStartupStages::fromOutput('');
    }

    public function getListeners()
    {
        $teamId = Auth::user()?->currentTeam()?->id;

        $listeners = [
            'activityMonitor' => 'newMonitorActivity',
            'processDialogClosed' => 'clearActivity',
        ];

        if ($teamId) {
            $listeners["echo-private:team.{$teamId},ServiceStatusChanged"] = 'serviceStatusChanged';
            $listeners["echo-private:team.{$teamId},ServiceChecked"] = 'serviceStatusChanged';
        }

        return $listeners;
    }

    public function newMonitorActivity($activityId): void
    {
        $this->activityId = $activityId;
        $this->isPollingActive = true;
        $this->isOnline = false;
        $this->failed = false;
        $this->notifiedOnline = false;
        $this->requestedStatusCheck = false;
        $this->showLogs = false;
        $this->hydrateActivity();
        $this->refreshStages();
    }

    public function clearActivity(): void
    {
        $this->activityId = null;
        $this->activity = null;
        $this->isPollingActive = false;
        $this->failed = false;
        $this->requestedStatusCheck = false;
    }

    public function serviceStatusChanged(): void
    {
        $this->service->refresh();
        $this->service->load(['applications', 'databases']);
        $this->openUrl = $this->service->publicHttpsUrl() ?: null;
        $this->refreshStages();
        $this->maybeFinish();
    }

    public function polling(): void
    {
        $this->hydrateActivity();
        $this->service->refresh();
        $this->service->load(['applications', 'databases']);
        $this->openUrl = $this->service->publicHttpsUrl() ?: null;
        $this->refreshStages();
        $this->maybeFinish();
    }

    public function toggleLogs(): void
    {
        $this->showLogs = ! $this->showLogs;
    }

    public function hydrateActivity(): void
    {
        if ($this->activityId === null) {
            $this->activity = null;

            return;
        }

        $activity = Activity::find($this->activityId);
        if (! $activity) {
            $this->activity = null;

            return;
        }

        $currentTeamId = currentTeam()?->id;
        $activityTeamId = data_get($activity, 'properties.team_id');
        if ($activityTeamId !== null) {
            if ((int) $activityTeamId !== (int) $currentTeamId) {
                $this->activity = null;

                return;
            }
            $this->activity = $activity;

            return;
        }

        $serverUuid = data_get($activity, 'properties.server_uuid');
        if ($serverUuid) {
            $server = Server::where('uuid', $serverUuid)->first();
            if ($server && (int) $server->team_id !== (int) $currentTeamId) {
                $this->activity = null;

                return;
            }
            if ($server) {
                $this->activity = $activity;
            }
        }
    }

    private function refreshStages(): void
    {
        $output = $this->activity ? RunRemoteProcess::decodeOutput($this->activity) : '';
        $exitCode = data_get($this->activity, 'properties.exitCode');
        $exitCode = is_numeric($exitCode) ? (int) $exitCode : null;
        $online = $this->serviceLooksOnline();
        $failed = $exitCode !== null && $exitCode !== 0;

        $this->failed = $failed;
        $this->isOnline = $online && ! $failed;
        $this->stages = ServiceStartupStages::fromOutput($output, $exitCode, $this->isOnline, $failed);
    }

    private function maybeFinish(): void
    {
        $exitCode = data_get($this->activity, 'properties.exitCode');
        if ($exitCode === null) {
            return;
        }

        $exitCode = (int) $exitCode;
        if ($exitCode !== 0) {
            $this->isPollingActive = false;
            $this->failed = true;
            $this->showLogs = true;

            return;
        }

        if (! $this->requestedStatusCheck && $this->service->server?->isFunctional()) {
            $this->requestedStatusCheck = true;
            GetContainersStatus::dispatch($this->service->server);
        }

        if ($this->serviceLooksOnline()) {
            $this->isOnline = true;
            $this->isPollingActive = false;
            $this->refreshStages();
            if (! $this->notifiedOnline) {
                $this->notifiedOnline = true;
                $this->dispatch('success', __('The service is online.'));
            }

            return;
        }

        // Compose finished; keep polling until containers report running.
        $this->isPollingActive = true;
    }

    private function serviceLooksOnline(): bool
    {
        if ($this->service->isRunning()) {
            return true;
        }

        $status = strtolower((string) $this->service->status);

        return str_contains($status, 'running') && ! str_contains($status, 'unhealthy');
    }

    public function getLogOutputProperty(): string
    {
        if (! $this->activity) {
            return '';
        }

        return RunRemoteProcess::decodeOutput($this->activity);
    }

    public function render()
    {
        return view('livewire.service-startup-monitor');
    }
}
