<?php

namespace App\Jobs;

use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use App\Support\OdooAddons;
use App\Support\OdooGit;
use App\Support\OdooZipBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class RestoreOdooBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $odooBackupId)
    {
        $this->onQueue('high');
    }

    /**
     * @return array{environment_id: int, volume: ?string, filename: ?string}
     */
    public function plan(): array
    {
        $backup = OdooBackup::query()->with('environment.services')->findOrFail($this->odooBackupId);
        if ($backup->status !== 'complete' || blank($backup->filename)) {
            throw new RuntimeException(__('Restore requires a complete Odoo backup zip.'));
        }

        $service = $backup->environment->services->first(fn ($row): bool => $row->supportsOdooJupyter());

        return [
            'environment_id' => $backup->environment_id,
            'volume' => $service === null ? null : OdooAddons::filestoreVolume($service),
            'filename' => $backup->filename,
        ];
    }

    public function handle(): void
    {
        $plan = $this->plan();
        $backup = OdooBackup::query()->with(['environment.services.destination.server', 'environment.project'])->findOrFail($this->odooBackupId);
        $service = $backup->environment->services->first(fn ($row): bool => $row->supportsOdooJupyter());
        if ($service === null) {
            throw new RuntimeException(__('This environment has no Odoo service yet.'));
        }

        $server = $service->destination?->server;
        if ($server === null) {
            throw new RuntimeException(__('This Odoo service has no server.'));
        }

        try {
            OdooGit::whileServerIsFree($server, function () use ($server, $service, $backup): void {
                instant_remote_process(OdooZipBackup::restoreCommands($service, (string) $backup->filename), $server);
                OdooGit::startIfPossible($service);
            });

            OdooAuditLog::write(auth()->id(), $backup->environment->project_id, $plan['environment_id'], 'odoo.backup.restore', 'finished', $plan);
        } catch (Throwable $exception) {
            OdooAuditLog::write(auth()->id(), $backup->environment->project_id, $plan['environment_id'], 'odoo.backup.restore', 'failed', [
                ...$plan,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
