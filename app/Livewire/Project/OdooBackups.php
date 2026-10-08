<?php

namespace App\Livewire\Project;

use App\Domain\Odoo\OdooAbilities;
use App\Jobs\CreateOdooBackupJob;
use App\Jobs\RestoreOdooBackupJob;
use App\Models\Environment;
use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use App\Models\Project;
use App\Support\EnsureOdooBackupSchedules;
use App\Support\GetOdooBackupFrequency;
use App\Support\OdooZipBackup;
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
        $this->dispatch('success', __('Backup queued. Database and filestore are saved together as an Odoo zip.'));
    }

    public function restore(int $backupId): void
    {
        $this->guard('odoo.backup.restore');
        $backup = OdooBackup::query()
            ->whereKey($backupId)
            ->where('environment_id', $this->environment->id)
            ->firstOrFail();

        if (! $backup->hasZip()) {
            $this->dispatch('error', __('Restore requires a complete Odoo backup zip.'));

            return;
        }

        RestoreOdooBackupJob::dispatch($backup->id);
        $this->dispatch('success', __('Restore queued for this branch.'));
    }

    public function deleteBackup(int $backupId): void
    {
        abort_unless(isInstanceOwner(), 403);
        $backup = OdooBackup::query()
            ->whereKey($backupId)
            ->where('environment_id', $this->environment->id)
            ->firstOrFail();

        OdooZipBackup::delete($backup);
        $backup->delete();

        OdooAuditLog::write(auth()->id(), $this->project->id, $this->environment->id, 'odoo.backup.delete', 'finished', [
            'backup_id' => $backupId,
        ]);
        $this->dispatch('success', __('Backup deleted.'));
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
            ->where('environment_id', $this->environment->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(function (OdooBackup $backup): array {
                [$statusLabel, $statusType] = $this->statusPresentation((string) $backup->status);

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
                    'complete' => $backup->hasZip(),
                    'busy' => in_array($backup->status, ['pending', 'running'], true),
                    'downloadUrl' => $backup->hasZip()
                        ? route('download.odoo-backup', ['backupId' => $backup->id])
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
            'canDelete' => isInstanceOwner(),
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
