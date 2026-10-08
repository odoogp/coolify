<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use App\Support\EnsureOdooBackupSchedules;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class CreateOdooBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $environmentId)
    {
        $this->onQueue('high');
    }

    public function handle(): OdooBackup
    {
        $environment = Environment::query()->with(['project.team.getodooPlan', 'services'])->findOrFail($this->environmentId);
        $service = $environment->services->first(fn ($row): bool => $row->supportsOdooJupyter());
        if ($service === null) {
            throw new RuntimeException(__('This environment has no Odoo service yet.'));
        }

        $schedules = EnsureOdooBackupSchedules::forService($service, $environment->project?->team?->getodooPlan);
        $backup = OdooBackup::query()->create([
            'environment_id' => $environment->id,
            'status' => 'pending',
        ]);

        DatabaseBackupJob::dispatch($schedules['database']);
        VolumeBackupJob::dispatch($schedules['volume']);
        SyncOdooBackupLegsJob::dispatch($backup->id)->delay(now()->addSeconds(30));

        OdooAuditLog::write(auth()->id(), $environment->project_id, $environment->id, 'odoo.backup.create', 'pending', [
            'backup_id' => $backup->id,
            'database_schedule_id' => $schedules['database']->id,
            'volume_schedule_id' => $schedules['volume']->id,
        ]);

        return $backup;
    }
}
