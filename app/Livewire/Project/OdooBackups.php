<?php

namespace App\Livewire\Project;

use App\Domain\Odoo\OdooAbilities;
use App\Jobs\CreateOdooBackupJob;
use App\Jobs\RestoreOdooBackupJob;
use App\Models\Environment;
use App\Models\OdooBackup;
use App\Models\Project;
use App\Support\EnsureOdooBackupSchedules;
use App\Support\GetOdooBackupFrequency;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class OdooBackups extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public Environment $environment;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::query()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('view', $this->project);
        $this->environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        abort_unless($this->project->odooProfile !== null, 404);
    }

    public function createBackup(): void
    {
        $this->guard('odoo.backup.create');
        $this->project->loadMissing('team.getodooPlan');
        $plan = $this->project->team?->getodooPlan;
        $user = auth()->user();
        if (! EnsureOdooBackupSchedules::userMayCreateManualBackup($plan, $user)) {
            $this->dispatch('error', __('Contact an advisor to add backups to your plan.'));

            return;
        }

        $bypass = isInstanceOwner() && ! EnsureOdooBackupSchedules::planAllowsBackups($plan);
        CreateOdooBackupJob::dispatch($this->environment->id, $bypass);
        $this->dispatch('success', __('Backup queued. Database and filestore are saved together.'));
    }

    public function restore(int $backupId): void
    {
        $this->guard('odoo.backup.restore');
        $backup = OdooBackup::query()
            ->whereKey($backupId)
            ->where('environment_id', $this->environment->id)
            ->firstOrFail();

        if ($backup->status !== 'complete') {
            $this->dispatch('error', __('Restore requires a complete database and filestore backup.'));

            return;
        }

        RestoreOdooBackupJob::dispatch($backup->id);
        $this->dispatch('success', __('Restore queued for this branch.'));
    }

    public function render()
    {
        $this->project->loadMissing('team.getodooPlan', 'odooProfile');
        $this->environment->loadMissing('services');
        $plan = $this->project->team?->getodooPlan;
        $planAllows = EnsureOdooBackupSchedules::planAllowsBackups($plan);
        $user = auth()->user();
        $canCreateManual = EnsureOdooBackupSchedules::userMayCreateManualBackup($plan, $user);
        $frequency = (string) ($plan?->backup_frequency ?: GetOdooBackupFrequency::NONE);

        $backups = OdooBackup::query()
            ->with(['databaseExecution', 'volumeExecution'])
            ->where('environment_id', $this->environment->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(function (OdooBackup $backup): array {
                [$statusLabel, $statusType] = $this->statusPresentation((string) $backup->status);
                $databaseExecution = $backup->databaseExecution;
                $volumeExecution = $backup->volumeExecution;
                $databaseReady = $databaseExecution !== null
                    && $databaseExecution->status === 'success'
                    && filled($databaseExecution->filename);
                $volumeReady = $volumeExecution !== null
                    && $volumeExecution->status === 'success'
                    && filled($volumeExecution->filename)
                    && ! (bool) ($volumeExecution->local_storage_deleted ?? false);

                return [
                    'id' => $backup->id,
                    'time' => $backup->created_at?->utc()->format('Y-m-d H:i:s'),
                    'branch' => $this->environment->name,
                    'version' => (string) ($this->project->odooProfile?->odoo_version ?: '-'),
                    'status' => $statusLabel,
                    'statusType' => $statusType,
                    'kind' => $backup->kind === OdooBackup::KIND_AUTOMATIC
                        ? __('Automatic :frequency backup', ['frequency' => GetOdooBackupFrequency::label($frequency)])
                        : __('Manual backup'),
                    'automatic' => $backup->kind === OdooBackup::KIND_AUTOMATIC,
                    'complete' => $backup->status === 'complete',
                    'busy' => in_array($backup->status, ['pending', 'running'], true),
                    'databaseDownloadUrl' => $databaseReady
                        ? route('download.backup', ['executionId' => $databaseExecution->id])
                        : null,
                    'volumeDownloadUrl' => $volumeReady
                        ? route('download.volume-backup', ['executionId' => $volumeExecution->id])
                        : null,
                ];
            })
            ->all();

        $hasBusy = collect($backups)->contains(fn (array $row): bool => $row['busy']);
        $canDownload = $user !== null && (
            OdooAbilities::allows($user, (int) $this->project->team_id, 'odoo.backup.create')
            || OdooAbilities::allows($user, (int) $this->project->team_id, 'odoo.backup.restore')
        );

        return view('livewire.project.odoo-backups', [
            'backups' => $backups,
            'hasBusy' => $hasBusy,
            'planAllowsAutomatic' => $planAllows,
            'backupsBlockedByPlan' => ! $canCreateManual,
            'canCreate' => $canCreateManual
                && $user !== null
                && OdooAbilities::allows($user, (int) $this->project->team_id, 'odoo.backup.create'),
            'canDownload' => $canDownload,
            'canRestore' => $user !== null && OdooAbilities::allows($user, (int) $this->project->team_id, 'odoo.backup.restore'),
            'service' => $this->environment->services->first(fn ($row): bool => $row->supportsOdooJupyter()),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function statusPresentation(string $status): array
    {
        return match ($status) {
            'complete', 'success' => [__('Complete'), 'success'],
            'failed' => [__('Failed'), 'error'],
            'partial' => [__('Partial'), 'warning'],
            'pending', 'running' => [__('In progress'), 'warning'],
            default => [ucfirst($status), 'neutral'],
        };
    }

    private function guard(string $ability): void
    {
        $user = auth()->user();
        if ($user === null || ! OdooAbilities::allows($user, (int) $this->project->team_id, $ability)) {
            abort(403);
        }
    }
}
