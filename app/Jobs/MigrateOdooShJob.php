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

            $target = $migration->environment_id
                ? $migration->project->environments->firstWhere('id', $migration->environment_id)
                : $migration->project->environments->first(fn ($environment): bool => strcasecmp($environment->name, 'production') === 0);
            if ($target === null) {
                throw new RuntimeException(__('Choose production or a staging environment before migrating.'));
            }

            $service = $target->services->first(fn ($row): bool => $row->supportsOdooJupyter());
            if (! $service instanceof Service) {
                throw new RuntimeException(__('Launch Odoo for that environment first, then run the migration restore.'));
            }

            $databasePath = Storage::disk('local')->path((string) $migration->database_disk_path);
            $filestorePath = Storage::disk('local')->path((string) $migration->filestore_disk_path);
            $addonsPath = $migration->hasAddonsZip()
                ? Storage::disk('local')->path((string) $migration->addons_disk_path)
                : null;
            if (! is_file($databasePath) || ! is_file($filestorePath)) {
                throw new RuntimeException(__('Migration files are missing on disk.'));
            }
            if ($addonsPath !== null && ! is_file($addonsPath)) {
                throw new RuntimeException(__('The modules zip is missing on disk.'));
            }

            $server = $service->destination?->server;
            if ($server === null) {
                throw new RuntimeException(__('This Odoo service has no server.'));
            }

            $remoteDir = '/tmp/gpsh-migrate-'.$migration->uuid;
            $remoteDump = $remoteDir.'/database.dump';
            $remoteStore = $remoteDir.'/filestore.tar.gz';
            $remoteAddons = $remoteDir.'/addons.zip';
            $volume = OdooAddons::filestoreVolume($service);
            $pg = escapeshellarg('postgresql-'.$service->uuid);

            OdooGit::whileServerIsFree($server, function () use ($server, $service, $databasePath, $filestorePath, $addonsPath, $remoteDir, $remoteDump, $remoteStore, $remoteAddons, $volume, $pg, $migration): void {
                instant_remote_process(['mkdir -p '.escapeshellarg($remoteDir)], $server);
                $this->upload($server, $databasePath, $remoteDump);
                $this->upload($server, $filestorePath, $remoteStore);
                if ($addonsPath !== null) {
                    $this->upload($server, $addonsPath, $remoteAddons);
                }

                // ponytail: Odoo.sh exports vary (sql/pg_dump + tar/zip of filestore); extend parsers when a customer dump breaks.
                $commands = [
                    'docker exec '.$pg.' sh -c '.escapeshellarg('psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -c "SELECT 1" >/dev/null'),
                    'docker exec -i '.$pg.' sh -c '.escapeshellarg('(gunzip -cf 2>/dev/null || cat) | psql -U "$POSTGRES_USER" -d "${POSTGRES_DB:-postgres}"').' < '.escapeshellarg($remoteDump),
                    'docker run --rm -v '.escapeshellarg($volume).':/target -v '.escapeshellarg($remoteDir).':/source:ro alpine sh -c '.escapeshellarg(
                        'set -e; mkdir -p /tmp/in /target/filestore /target/sessions; '.
                        '(tar -xzf /source/filestore.tar.gz -C /tmp/in || tar -xf /source/filestore.tar.gz -C /tmp/in); '.
                        'if [ -d /tmp/in/filestore ]; then cp -a /tmp/in/filestore/. /target/filestore/; '.
                        'elif [ -d /tmp/in ]; then cp -a /tmp/in/. /target/filestore/; fi; '.
                        'chown -R 101:101 /target 2>/dev/null || true'
                    ),
                ];
                if ($addonsPath !== null) {
                    $commands = array_merge($commands, OdooAddons::unpackArchiveCommands($service, $remoteDir));
                }
                $commands[] = 'rm -rf '.escapeshellarg($remoteDir);
                instant_remote_process($commands, $server);

                if ($addonsPath === null && filled($migration->git_repository)) {
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

            $migration->update(['status' => 'complete', 'environment_id' => $target->id]);
            OdooAuditLog::write($migration->user_id, $migration->project_id, $target->id, 'odoo.migrate.sh', 'finished', [
                'migration_id' => $migration->id,
                'environment' => $target->name,
                'git_repository' => $migration->git_repository,
                'addons_zip' => $migration->hasAddonsZip(),
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
