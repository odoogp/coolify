<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdooBackup extends Model
{
    public const KIND_MANUAL = 'manual';

    public const KIND_AUTOMATIC = 'automatic';

    protected $fillable = [
        'environment_id',
        'database_backup_execution_id',
        'volume_backup_execution_id',
        'status',
        'kind',
    ];

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function databaseExecution(): BelongsTo
    {
        return $this->belongsTo(ScheduledDatabaseBackupExecution::class, 'database_backup_execution_id');
    }

    public function volumeExecution(): BelongsTo
    {
        return $this->belongsTo(ScheduledVolumeBackupExecution::class, 'volume_backup_execution_id');
    }

    public function syncLegs(?int $databaseExecutionId, ?int $volumeExecutionId): void
    {
        $this->database_backup_execution_id = $databaseExecutionId;
        $this->volume_backup_execution_id = $volumeExecutionId;
        $this->status = self::statusFor(
            $databaseExecutionId === null ? null : ScheduledDatabaseBackupExecution::query()->whereKey($databaseExecutionId)->value('status'),
            $volumeExecutionId === null ? null : ScheduledVolumeBackupExecution::query()->whereKey($volumeExecutionId)->value('status'),
        );
        $this->save();
    }

    public static function statusFor(?string $database, ?string $volume): string
    {
        if ($database === null || $volume === null) {
            return 'pending';
        }

        if ($database === 'success' && $volume === 'success') {
            return 'complete';
        }

        if ($database === 'failed' || $volume === 'failed') {
            return $database === 'success' || $volume === 'success' ? 'partial' : 'failed';
        }

        return 'pending';
    }
}
