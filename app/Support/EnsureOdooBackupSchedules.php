<?php

namespace App\Support;

use App\Models\GetOdooPlan;
use App\Models\LocalPersistentVolume;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use App\Models\ServiceDatabase;
use App\Models\Team;
use RuntimeException;

class EnsureOdooBackupSchedules
{
    /**
     * @return array{database: ScheduledDatabaseBackup, volume: ScheduledVolumeBackup}
     */
    public static function forService(Service $service, ?GetOdooPlan $plan = null): array
    {
        $team = self::team($service);
        $plan ??= $team?->getodooPlan;
        $frequencyKey = $plan?->backup_frequency ?: GetOdooBackupFrequency::DAILY;
        $cron = GetOdooBackupFrequency::cron($frequencyKey) ?? GetOdooBackupFrequency::cron(GetOdooBackupFrequency::DAILY);
        $enabled = $frequencyKey !== GetOdooBackupFrequency::NONE;
        $retentionDays = max(1, (int) ($plan?->backup_retention_days ?: 7));

        $database = self::postgresDatabase($service);
        $volume = self::filestoreVolume($service);
        if ($database === null || $volume === null) {
            throw new RuntimeException(__('This Odoo service has no PostgreSQL database or filestore volume yet.'));
        }

        $dbSchedule = ScheduledDatabaseBackup::query()
            ->where('database_type', $database->getMorphClass())
            ->where('database_id', $database->id)
            ->where('team_id', $team?->id)
            ->orderBy('id')
            ->first();

        if ($dbSchedule === null) {
            $dbSchedule = ScheduledDatabaseBackup::query()->create([
                'enabled' => $enabled,
                'frequency' => $cron,
                'save_s3' => false,
                's3_storage_id' => null,
                'database_id' => $database->id,
                'database_type' => $database->getMorphClass(),
                'team_id' => $team?->id,
                'database_backup_retention_days_locally' => $retentionDays,
                'database_backup_retention_amount_locally' => $retentionDays,
                'description' => 'Odoo database + plan schedule',
            ]);
        } else {
            $dbSchedule->update([
                'enabled' => $enabled,
                'frequency' => $cron,
                'database_backup_retention_days_locally' => $retentionDays,
                'database_backup_retention_amount_locally' => $retentionDays,
            ]);
        }

        $volumeSchedule = ScheduledVolumeBackup::query()
            ->where('backupable_type', $volume->getMorphClass())
            ->where('backupable_id', $volume->id)
            ->where('team_id', $team?->id)
            ->orderBy('id')
            ->first();

        if ($volumeSchedule === null) {
            $volumeSchedule = ScheduledVolumeBackup::query()->create([
                'backupable_type' => $volume->getMorphClass(),
                'backupable_id' => $volume->id,
                'team_id' => $team?->id,
                'frequency' => $cron,
                'enabled' => $enabled,
                'save_s3' => false,
                'disable_local_backup' => false,
                'stop_during_backup' => false,
                'retention_amount_locally' => $retentionDays,
                'retention_days_locally' => $retentionDays,
            ]);
        } else {
            $volumeSchedule->update([
                'frequency' => $cron,
                'enabled' => $enabled,
                'retention_amount_locally' => $retentionDays,
                'retention_days_locally' => $retentionDays,
            ]);
        }

        return [
            'database' => $dbSchedule->fresh(),
            'volume' => $volumeSchedule->fresh(),
        ];
    }

    public static function postgresDatabase(Service $service): ?ServiceDatabase
    {
        return $service->databases()->get()->first(function (ServiceDatabase $database): bool {
            $haystack = strtolower(($database->name ?? '').' '.($database->image ?? '').' '.($database->custom_type ?? ''));

            return str_contains($haystack, 'postgres');
        });
    }

    public static function filestoreVolume(Service $service): ?LocalPersistentVolume
    {
        $expected = OdooAddons::filestoreVolume($service);

        foreach ($service->applications()->with('persistentStorages')->get() as $application) {
            foreach ($application->persistentStorages as $storage) {
                if (! $storage instanceof LocalPersistentVolume) {
                    continue;
                }
                if ($storage->name === $expected || str_contains((string) $storage->name, 'odoo-web-data')) {
                    return $storage;
                }
                if (str_contains((string) $storage->mount_path, '/var/lib/odoo')) {
                    return $storage;
                }
            }
        }

        return LocalPersistentVolume::query()->where('name', $expected)->first();
    }

    private static function team(Service $service): ?Team
    {
        $service->loadMissing('environment.project.team');

        return $service->environment?->project?->team;
    }
}
