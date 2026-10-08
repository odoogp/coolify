<?php

namespace App\Support;

use App\Jobs\CreateOdooBackupJob;
use App\Models\OdooBackup;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Service;
use App\Models\ServiceDatabase;

class RecordOdooPlanBackup
{
    /**
     * Plan cron still uses Coolify's database schedule as the timer. When it fires, run an
     * Odoo-native zip backup instead of Coolify pg_dump / volume legs.
     */
    public static function fromDatabaseSchedule(ScheduledDatabaseBackup $schedule): bool
    {
        if (! EnsureOdooBackupSchedules::isPlanDatabaseSchedule($schedule)) {
            return false;
        }

        $database = $schedule->database;
        if (! $database instanceof ServiceDatabase) {
            return false;
        }

        $service = $database->service;
        if (! $service instanceof Service) {
            $database->loadMissing('service.environment.project.team.getodooPlan');
            $service = $database->service;
        }
        if (! $service instanceof Service || $service->environment_id === null) {
            return false;
        }

        $plan = $service->environment?->project?->team?->getodooPlan;
        // Always swallow Coolify dump for plan Odoo schedules; only enqueue a zip when the plan allows it.
        if (! EnsureOdooBackupSchedules::planAllowsBackups($plan)) {
            return true;
        }

        $recent = OdooBackup::query()
            ->where('environment_id', $service->environment_id)
            ->where('kind', OdooBackup::KIND_AUTOMATIC)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->whereIn('status', ['pending', 'running', 'complete'])
            ->exists();
        if ($recent) {
            return true;
        }

        CreateOdooBackupJob::dispatch(
            (int) $service->environment_id,
            bypassPlanRestriction: false,
            kind: OdooBackup::KIND_AUTOMATIC,
        );

        return true;
    }
}
