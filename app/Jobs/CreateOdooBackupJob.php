<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use App\Support\EnsureOdooBackupSchedules;
use App\Support\OdooZipBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class CreateOdooBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $environmentId,
        public bool $bypassPlanRestriction = false,
        public string $kind = OdooBackup::KIND_MANUAL,
    ) {
        $this->onQueue('high');
    }

    public function handle(): OdooBackup
    {
        $environment = Environment::query()->with(['project.team.getodooPlan', 'services'])->findOrFail($this->environmentId);
        $service = $environment->services->first(fn ($row): bool => $row->supportsOdooJupyter());
        if ($service === null) {
            throw new RuntimeException(__('This environment has no Odoo service yet.'));
        }

        $plan = $environment->project?->team?->getodooPlan;
        if (! $this->bypassPlanRestriction && ! EnsureOdooBackupSchedules::planAllowsBackups($plan)) {
            throw new RuntimeException(__('Contact an advisor to add backups to your plan.'));
        }

        $kind = $this->kind === OdooBackup::KIND_AUTOMATIC
            ? OdooBackup::KIND_AUTOMATIC
            : OdooBackup::KIND_MANUAL;

        $backup = OdooBackup::query()->create([
            'environment_id' => $environment->id,
            'status' => 'running',
            'kind' => $kind,
        ]);

        OdooAuditLog::write(auth()->id(), $environment->project_id, $environment->id, 'odoo.backup.create', 'pending', [
            'backup_id' => $backup->id,
            'kind' => $kind,
            'bypass_plan' => $this->bypassPlanRestriction,
            'format' => 'odoo-zip',
        ]);

        try {
            // Keep Coolify schedule rows in sync (cron trigger + retention days) without using Coolify dump legs.
            EnsureOdooBackupSchedules::forService($service, $plan);

            $result = OdooZipBackup::create($service);
            $backup->update([
                'status' => 'complete',
                'filename' => $result['filename'],
                'filesize' => $result['filesize'],
                'error' => null,
            ]);

            $retentionDays = max(1, (int) ($plan?->backup_retention_days ?: 7));
            OdooZipBackup::prune($environment, $retentionDays);

            OdooAuditLog::write(auth()->id(), $environment->project_id, $environment->id, 'odoo.backup.create', 'finished', [
                'backup_id' => $backup->id,
                'filename' => $result['filename'],
                'filesize' => $result['filesize'],
            ]);
        } catch (Throwable $exception) {
            $backup->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
            OdooAuditLog::write(auth()->id(), $environment->project_id, $environment->id, 'odoo.backup.create', 'failed', [
                'backup_id' => $backup->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $backup->fresh() ?? $backup;
    }
}
