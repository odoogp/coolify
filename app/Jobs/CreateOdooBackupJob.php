<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateOdooBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $environmentId)
    {
        $this->onQueue('high');
    }

    public function handle(): OdooBackup
    {
        $environment = Environment::query()->findOrFail($this->environmentId);
        $backup = OdooBackup::query()->create([
            'environment_id' => $environment->id,
            'status' => 'pending',
        ]);

        OdooAuditLog::write(auth()->id(), $environment->project_id, $environment->id, 'odoo.backup.create', 'pending', [
            'backup_id' => $backup->id,
        ]);

        return $backup;
    }
}
