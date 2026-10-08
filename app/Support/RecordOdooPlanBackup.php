<?php

namespace App\Support;

use App\Jobs\SyncOdooBackupLegsJob;
use App\Models\OdooBackup;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Service;
use App\Models\ServiceDatabase;

class RecordOdooPlanBackup
{
    /**
     * When a plan-driven database schedule finishes an execution, open an OdooBackup
     * aggregator so the branch UI can list automatic backups like Odoo.sh.
     */
    public static function fromDatabaseSchedule(ScheduledDatabaseBackup $schedule): void
    {
        if (! EnsureOdooBackupSchedules::isPlanDatabaseSchedule($schedule)) {
            return;
        }

        $database = $schedule->database;
        if (! $database instanceof ServiceDatabase) {
            return;
        }

        $service = $database->service;
        if (! $service instanceof Service) {
            $database->loadMissing('service.environment.project.team.getodooPlan');
            $service = $database->service;
        }
        if (! $service instanceof Service || $service->environment_id === null) {
            return;
        }

        $plan = $service->environment?->project?->team?->getodooPlan;
        if (! EnsureOdooBackupSchedules::planAllowsBackups($plan)) {
            return;
        }

        $recent = OdooBackup::query()
            ->where('environment_id', $service->environment_id)
            ->where('kind', OdooBackup::KIND_AUTOMATIC)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->exists();
        if ($recent) {
            return;
        }

        $backup = OdooBackup::query()->create([
            'environment_id' => $service->environment_id,
            'status' => 'pending',
            'kind' => OdooBackup::KIND_AUTOMATIC,
        ]);

        SyncOdooBackupLegsJob::dispatch($backup->id)->delay(now()->addSeconds(30));
    }
}
