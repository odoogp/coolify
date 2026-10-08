<?php

namespace App\Jobs;

use App\Models\OdooAuditLog;
use App\Models\OdooMigration;
use App\Models\Service;
use App\Support\EnsureOdooBackupSchedules;
use App\Support\OdooAddons;
use App\Support\OdooGit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class MigrateOdooShJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $migrationId)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $migration = OdooMigration::query()->with('project.environments.services', 'project.team.getodooPlan', 'project.odooProfile')->findOrFail($this->migrationId);
        $migration->update(['status' => 'running', 'error' => null]);

        try {
            if (! $migration->hasFiles()) {
                throw new RuntimeException(__('Upload both the database dump and the filestore archive.'));
            }

            $production = $migration->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
            if ($production === null) {
                throw new RuntimeException(__('Create a production environment before migrating.'));
            }

            $service = $production->services->first(fn ($row): bool => $row->supportsOdooJupyter());
            if (! $service instanceof Service) {
                throw new RuntimeException(__('Launch Odoo for this project first, then run the migration restore.'));
            }

            $databasePath = Storage::disk('local')->path((string) $migration->database_disk_path);
            $filestorePath = Storage::disk('local')->path((string) $migration->filestore_disk_path);
            if (! is_file($databasePath) || ! is_file($filestorePath)) {
                throw new RuntimeException(__('Migration files are missing on disk.'));
            }

            $server = $service->destination?->server;
            if ($server === null) {
                throw new RuntimeException(__('This Odoo service has no server.'));
            }

            $remoteDir = '/tmp/gpsh-migrate-'.$migration->uuid;
            $remoteDump = $remoteDir.'/database.dump';
            $remoteStore = $remoteDir.'/filestore.tar.gz';
            $volume = OdooAddons::filestoreVolume($service);
            $pg = escapeshellarg('postgresql-'.$service->uuid);

            OdooGit::whileServerIsFree($server, function () use ($server, $service, $databasePath, $filestorePath, $remoteDir, $remoteDump, $remoteStore, $volume, $pg, $migration): void {
                instant_remote_process(['mkdir -p '.escapeshellarg($remoteDir)], $server);
                $this->upload($server, $databasePath, $remoteDump);
                $this->upload($server, $filestorePath, $remoteStore);

                // ponytail: Odoo.sh exports vary (sql/pg_dump + tar/zip of filestore); extend parsers when a customer dump breaks.
                instant_remote_process([
                    'docker exec '.$pg.' sh -c '.escapeshellarg('psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -c "SELECT 1" >/dev/null'),
                    'docker exec -i '.$pg.' sh -c '.escapeshellarg('(gunzip -cf 2>/dev/null || cat) | psql -U "$POSTGRES_USER" -d "${POSTGRES_DB:-postgres}"').' < '.escapeshellarg($remoteDump),
                    'docker run --rm -v '.escapeshellarg($volume).':/target -v '.escapeshellarg($remoteDir).':/source:ro alpine sh -c '.escapeshellarg(
                        'set -e; mkdir -p /tmp/in /target/filestore /target/sessions; '.
                        '(tar -xzf /source/filestore.tar.gz -C /tmp/in || tar -xf /source/filestore.tar.gz -C /tmp/in); '.
                        'if [ -d /tmp/in/filestore ]; then cp -a /tmp/in/filestore/. /target/filestore/; '.
                        'elif [ -d /tmp/in ]; then cp -a /tmp/in/. /target/filestore/; fi; '.
                        'chown -R 101:101 /target 2>/dev/null || true'
                    ),
                    'rm -rf '.escapeshellarg($remoteDir),
                ], $server);

                if (filled($migration->git_repository)) {
                    $profile = $migration->project->odooProfile;
                    if ($profile !== null) {
                        $profile->git_repository = $migration->git_repository;
                        $profile->save();
                    }
                    OdooGit::cloneIntoService($service);
                }

                EnsureOdooBackupSchedules::forService($service, $migration->project->team?->getodooPlan);
                OdooGit::startIfPossible($service);
            });

            $migration->update(['status' => 'complete']);
            OdooAuditLog::write($migration->user_id, $migration->project_id, $production->id, 'odoo.migrate.sh', 'finished', [
                'migration_id' => $migration->id,
                'git_repository' => $migration->git_repository,
            ]);
        } catch (Throwable $exception) {
            $migration->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
            OdooAuditLog::write($migration->user_id, $migration->project_id, null, 'odoo.migrate.sh', 'failed', [
                'migration_id' => $migration->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function upload($server, string $local, string $remote): void
    {
        // ponytail: base64 over SSH; ceiling ~30MB decoded. Large dumps need SFTP/rsync later.
        $data = base64_encode((string) file_get_contents($local));
        if (strlen($data) > 40_000_000) {
            throw new RuntimeException(__('Migration files are too large for this channel. Use a smaller dump or ask the owner to restore on the server.'));
        }

        instant_remote_process([
            'printf %s '.escapeshellarg($data).' | base64 -d > '.escapeshellarg($remote),
        ], $server);
    }
}
