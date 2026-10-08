<?php

namespace App\Jobs;

use App\Models\OdooBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Support\EnsureOdooBackupSchedules;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOdooBackupLegsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public function __construct(public int $odooBackupId) {}

    public function handle(): void
    {
        $backup = OdooBackup::query()->with(['environment.services', 'environment.project.team.getodooPlan'])->find($this->odooBackupId);
        if ($backup === null || $backup->status === 'complete') {
            return;
        }

        $service = $backup->environment?->services?->first(fn ($row): bool => $row->supportsOdooJupyter());
        if ($service === null) {
            return;
        }

        try {
            $schedules = EnsureOdooBackupSchedules::forService($service, $backup->environment?->project?->team?->getodooPlan);
        } catch (\Throwable) {
            return;
        }

        $databaseExecution = ScheduledDatabaseBackupExecution::query()
            ->where('scheduled_database_backup_id', $schedules['database']->id)
            ->where('created_at', '>=', $backup->created_at->subMinute())
            ->latest('id')
            ->first();
        $volumeExecution = ScheduledVolumeBackupExecution::query()
            ->where('scheduled_volume_backup_id', $schedules['volume']->id)
            ->where('created_at', '>=', $backup->created_at->subMinute())
            ->latest('id')
            ->first();

        $backup->syncLegs($databaseExecution?->id, $volumeExecution?->id);

        if ($backup->fresh()->status === 'pending' && $this->attempts() < $this->tries) {
            $this->release(30);
        }
    }
}
