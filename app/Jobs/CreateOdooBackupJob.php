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
        public ?int $backupId = null,
    ) {
        $this->onQueue('high');
    }

    /**
     * Persist a pending backup before the worker starts so the screen can show it
     * and refuse a second launch. Returns null when one is already pending or running.
     */
    public static function enqueue(int $environmentId, bool $bypassPlanRestriction = false, string $kind = OdooBackup::KIND_MANUAL): ?OdooBackup
    {
        if (OdooBackup::environmentIsBusy($environmentId)) {
            return null;
        }

        $kind = $kind === OdooBackup::KIND_AUTOMATIC
            ? OdooBackup::KIND_AUTOMATIC
            : OdooBackup::KIND_MANUAL;

        $backup = OdooBackup::query()->create([
            'environment_id' => $environmentId,
            'status' => 'pending',
            'kind' => $kind,
        ]);

        self::dispatch($environmentId, $bypassPlanRestriction, $kind, $backup->id);

        return $backup;
    }

    public function handle(): OdooBackup
    {
        $environment = Environment::query()->with(['project.team.getodooPlan', 'services'])->findOrFail($this->environmentId);
        $backup = $this->claimedBackup($environment);

        if ($backup !== null && $backup->hasZip()) {
            return $backup;
        }

        if ($backup === null && OdooBackup::environmentIsBusy($environment->id)) {
            $busy = OdooBackup::query()
                ->where('environment_id', $environment->id)
                ->whereIn('status', ['pending', 'running'])
                ->first();
            if ($busy !== null) {
                return $busy;
            }
        }

        try {
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

            if ($backup === null) {
                $backup = OdooBackup::query()->create([
                    'environment_id' => $environment->id,
                    'status' => 'running',
                    'kind' => $kind,
                ]);
            } else {
                $backup->update([
                    'status' => 'running',
                    'kind' => $kind,
                ]);
            }

            OdooAuditLog::write(auth()->id(), $environment->project_id, $environment->id, 'odoo.backup.create', 'pending', [
                'backup_id' => $backup->id,
                'kind' => $kind,
                'bypass_plan' => $this->bypassPlanRestriction,
                'format' => 'odoo-zip',
            ]);

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
            if ($backup !== null) {
                $backup->update([
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ]);
                OdooAuditLog::write(auth()->id(), $environment->project_id, $environment->id, 'odoo.backup.create', 'failed', [
                    'backup_id' => $backup->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            throw $exception;
        }

        return $backup->fresh() ?? $backup;
    }

    private function claimedBackup(Environment $environment): ?OdooBackup
    {
        if ($this->backupId === null) {
            return null;
        }

        return OdooBackup::query()
            ->whereKey($this->backupId)
            ->where('environment_id', $environment->id)
            ->first();
    }
}
