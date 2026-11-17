<?php

namespace App\Livewire\Project\Service\VolumeBackup;

use App\Jobs\DatabaseBackupJob;
use App\Jobs\VolumeBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Traits\ListensToTeamChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    use AuthorizesRequests;
    use ListensToTeamChannel;

    public Service $service;

    public array $parameters;

    public string $search = '';

    #[Url(as: 'backup_uuid', except: '')]
    public string $backupUuid = '';

    public bool $scheduleModalOpen = false;

    public ?ScheduledDatabaseBackup $selectedDatabaseBackup = null;

    public ?ScheduledVolumeBackup $selectedVolumeBackup = null;

    public ?Collection $s3s = null;

    public function getListeners(): array
    {
        return [
            'refreshVolumeBackups' => '$refresh',
            'modalClosed' => 'closeScheduleModal',
            ...$this->teamChannelListeners([
                'ServiceChecked' => '$refresh',
                'BackupCreated' => '$refresh',
            ]),
        ];
    }

    public function mount(?Service $service = null): void
    {
        $this->service = $service ?? $this->findService();
        $this->authorize('view', $this->service);
        $this->parameters = get_route_parameters();
        $this->search = request()->string('search')->toString();

        if ($this->service->supportsOdooJupyter()) {
            $this->service->loadMissing('environment.project');
            $this->redirectRoute('project.odoo.backups', [
                'project_uuid' => $this->service->environment->project->uuid,
                'environment_uuid' => $this->service->environment->uuid,
            ]);
        }
    }

    public function render(): View
    {
        $databaseTargets = $this->service->databases->filter(
            fn (ServiceDatabase $database): bool => $database->isBackupSolutionAvailable(),
        );

        $databaseBackups = ScheduledDatabaseBackup::query()
            ->with(['database', 'latest_log', 's3'])
            ->withCount('executions')
            ->where('database_type', (new ServiceDatabase)->getMorphClass())
            ->whereHasMorph('database', [ServiceDatabase::class], fn ($query) => $query->where('service_id', $this->service->id))
            ->latest()
            ->get();

        $backups = ScheduledVolumeBackup::query()
            ->with(['backupable.resource', 'latestExecution', 's3'])
            ->withCount('executions')
            ->forService($this->service)
            ->latest()
            ->get();

        return view('livewire.project.service.volume-backup.index', [
            'backups' => $backups,
            'databaseBackups' => $databaseBackups,
            'databaseTargets' => $databaseTargets,
        ]);
    }

    private function findService(): Service
    {
        $project = currentTeam()->projects()->where('uuid', request()->route('project_uuid'))->firstOrFail();
        $environment = $project->environments()->where('uuid', request()->route('environment_uuid'))->firstOrFail();

        return $environment->services()
            ->with(['server.settings', 'environment.project'])
            ->where('uuid', request()->route('service_uuid'))
            ->firstOrFail();
    }

    private function loadSelectedSchedule(string $backupUuid): void
    {
        $this->selectedDatabaseBackup = ScheduledDatabaseBackup::query()
            ->with('database')
            ->whereUuid($backupUuid)
            ->where('database_type', (new ServiceDatabase)->getMorphClass())
            ->whereHasMorph('database', [ServiceDatabase::class], fn ($query) => $query->where('service_id', $this->service->id))
            ->first();

        if ($this->selectedDatabaseBackup) {
            return;
        }

        $this->selectedVolumeBackup = ScheduledVolumeBackup::query()
            ->with('backupable.resource')
            ->whereUuid($backupUuid)
            ->forService($this->service)
            ->firstOrFail();
    }
}
