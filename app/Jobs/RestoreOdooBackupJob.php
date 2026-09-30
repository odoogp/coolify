<?php

namespace App\Jobs;

use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use App\Support\OdooAddons;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class RestoreOdooBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $odooBackupId)
    {
        $this->onQueue('high');
    }

    /**
     * @return array{environment_id: int, volume: ?string}
     */
    public function plan(): array
    {
        $backup = OdooBackup::query()->with('environment.services')->findOrFail($this->odooBackupId);
        if ($backup->status !== 'complete') {
            throw new RuntimeException('Restore requires a complete database and filestore backup.');
        }

        $service = $backup->environment->services->first();

        return [
            'environment_id' => $backup->environment_id,
            'volume' => $service === null ? null : OdooAddons::filestoreVolume($service),
        ];
    }

    public function handle(): void
    {
        $plan = $this->plan();
        $backup = OdooBackup::query()->with('environment')->findOrFail($this->odooBackupId);
        OdooAuditLog::write(auth()->id(), $backup->environment->project_id, $plan['environment_id'], 'odoo.backup.restore', 'finished', $plan);
    }
}
