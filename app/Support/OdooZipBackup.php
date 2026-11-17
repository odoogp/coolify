<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\OdooBackup;
use App\Models\Server;
use App\Models\Service;
use RuntimeException;

/**
 * Odoo-native backup zip: dump.sql + filestore/ + manifest.json (same layout as Odoo's Database manager).
 * Odoo-only — not used for other Coolify services.
 */
class OdooZipBackup
{
    /**
     * @return array{filename: string, filesize: int}
     */
    public static function create(Service $service): array
    {
        $service->loadMissing(['environment.project.odooProfile', 'destination.server']);
        $server = $service->destination?->server;
        if ($server === null) {
            throw new RuntimeException(__('This Odoo service has no server.'));
        }

        $database = self::databaseName($service);
        $volume = OdooAddons::filestoreVolume($service);
        $version = (string) ($service->environment?->project?->odooProfile?->odoo_version ?: '18');
        $stamp = (string) time();
        $dir = self::directory($service->environment);
        $work = $dir.'/work-'.$stamp;
        $zipPath = $dir.'/odoo-'.$database.'-'.$stamp.'.zip';

        $script = self::createScript(
            pgContainer: 'postgresql-'.$service->uuid,
            database: $database,
            volume: $volume,
            workDir: $work,
            zipPath: $zipPath,
            version: $version,
        );

        OdooGit::whileServerIsFree($server, function () use ($server, $dir, $script, $zipPath): void {
            instant_remote_process([
                'mkdir -p '.escapeshellarg($dir),
                $script,
            ], $server);

            if (trim((string) instant_remote_process(['test -f '.escapeshellarg($zipPath).' && echo ok'], $server)) !== 'ok') {
                throw new RuntimeException(__('The Odoo backup zip was not created on the server.'));
            }
        });

        $sizeRaw = trim((string) instant_remote_process(
            ['stat -c%s '.escapeshellarg($zipPath).' 2>/dev/null || wc -c < '.escapeshellarg($zipPath)],
            $server
        ));
        $filesize = max(0, (int) preg_replace('/\D+/', '', $sizeRaw));

        return [
            'filename' => $zipPath,
            'filesize' => $filesize,
        ];
    }

    /**
     * Remote shell that builds an Odoo-format zip on the host.
     */
    public static function createScript(
        string $pgContainer,
        string $database,
        string $volume,
        string $workDir,
        string $zipPath,
        string $version,
    ): string {
        self::assertSafeName($database);
        self::assertSafeName($pgContainer);

        $versionNormalized = str_contains($version, '.') ? $version : $version.'.0';
        $manifest = json_encode([
            'odoo_dump_version' => 1,
            'db_name' => $database,
            'version' => $versionNormalized,
            'major_version' => $versionNormalized,
        ], JSON_UNESCAPED_SLASHES);

        $pg = escapeshellarg($pgContainer);
        $work = escapeshellarg($workDir);
        $vol = escapeshellarg($volume);
        $outDir = escapeshellarg(dirname($zipPath));
        $zipBase = basename($zipPath);
        $dumpCmd = escapeshellarg('pg_dump -U "$POSTGRES_USER" --no-owner --format=plain '.escapeshellarg($database));
        $copyFilestore = escapeshellarg(
            'set -e; '
            .'if [ -d /odoo/filestore/'.$database.' ]; then cp -a /odoo/filestore/'.$database.'/. /out/filestore/; '
            .'elif [ -d /odoo/filestore ]; then cp -a /odoo/filestore/. /out/filestore/; fi'
        );
        $zipCmd = escapeshellarg(
            'set -e; apk add --no-cache zip >/dev/null; cd /work && zip -qr /out/'.$zipBase.' dump.sql filestore manifest.json'
        );

        return 'set -e; '
            .'rm -rf '.$work.'; mkdir -p '.$work.'/filestore; '
            .'docker exec '.$pg.' sh -c '.$dumpCmd.' > '.$work.'/dump.sql; '
            .'docker run --rm -v '.$vol.':/odoo:ro -v '.$work.':/out alpine sh -c '.$copyFilestore.'; '
            .'printf %s '.escapeshellarg((string) $manifest).' > '.$work.'/manifest.json; '
            .'docker run --rm -v '.$work.':/work -v '.$outDir.':/out alpine sh -c '.$zipCmd.'; '
            .'rm -rf '.$work;
    }

    public static function directory(?Environment $environment): string
    {
        $slug = $environment?->uuid ?: 'unknown';

        return backup_dir().'/odoo/'.$slug;
    }

    public static function databaseName(Service $service): string
    {
        $database = OdooGit::runtimeValue($service, 'ODOO_DATABASE')
            ?: (string) (OdooGit::databaseName($service) ?? $service->environment?->name ?? '');
        if ($database === '') {
            throw new RuntimeException(__('This Odoo service has no database name.'));
        }
        self::assertSafeName($database);

        return $database;
    }

    public static function deleteRemote(?Server $server, ?string $filename): void
    {
        if ($server === null || blank($filename)) {
            return;
        }

        try {
            instant_remote_process(['rm -f '.escapeshellarg($filename)], $server);
        } catch (\Throwable) {
            // File may already be gone.
        }
    }

    public static function delete(OdooBackup $backup): void
    {
        $backup->loadMissing('environment.services.destination.server');
        $service = $backup->environment?->services?->first(fn ($row): bool => $row->supportsOdooJupyter());
        $server = $service?->destination?->server;
        self::deleteRemote($server, $backup->filename);
    }

    /**
     * Drop zip backups older than the plan retention window for this branch.
     */
    public static function prune(Environment $environment, int $retentionDays): int
    {
        $days = max(1, $retentionDays);
        $old = OdooBackup::query()
            ->where('environment_id', $environment->id)
            ->whereNotNull('filename')
            ->where('created_at', '<', now()->subDays($days))
            ->get();

        $deleted = 0;
        foreach ($old as $backup) {
            self::delete($backup);
            $backup->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    public static function restoreCommands(Service $service, string $zipPath): array
    {
        $database = self::databaseName($service);
        $volume = OdooAddons::filestoreVolume($service);
        $pg = escapeshellarg('postgresql-'.$service->uuid);
        $remoteDir = '/tmp/gpsh-odoo-restore-'.preg_replace('/[^a-zA-Z0-9_-]/', '', basename($zipPath, '.zip'));
        $dir = escapeshellarg($remoteDir);
        $zip = escapeshellarg($zipPath);
        $vol = escapeshellarg($volume);

        $unpack = escapeshellarg(
            'set -e; apk add --no-cache unzip >/dev/null; mkdir -p /work; cd /work; unzip -qo /source/backup.zip; test -f /work/dump.sql'
        );
        $recreate = escapeshellarg(
            'psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -c '.escapeshellarg(
                "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$database}' AND pid <> pg_backend_pid()"
            ).' || true; '
            .'dropdb -U "$POSTGRES_USER" --if-exists '.escapeshellarg($database).'; '
            .'createdb -U "$POSTGRES_USER" -O "$POSTGRES_USER" '.escapeshellarg($database)
        );
        $psqlRestore = escapeshellarg('psql -U "$POSTGRES_USER" -d '.escapeshellarg($database).' -v ON_ERROR_STOP=1');
        $filestore = escapeshellarg(
            'set -e; mkdir -p /target/filestore/'.$database.' /target/sessions; '
            .'if [ -d /source/filestore ]; then cp -a /source/filestore/. /target/filestore/'.$database.'/; fi; '
            .'chown -R 101:101 /target 2>/dev/null || true'
        );

        return [
            'mkdir -p '.$dir,
            'docker run --rm -v '.$zip.':/source/backup.zip:ro -v '.$dir.':/work alpine sh -c '.$unpack,
            'docker exec '.$pg.' sh -c '.$recreate,
            'docker exec -i '.$pg.' sh -c '.$psqlRestore.' < '.$dir.'/dump.sql',
            'docker run --rm -v '.$vol.':/target -v '.$dir.':/source:ro alpine sh -c '.$filestore,
            'rm -rf '.$dir,
        ];
    }

    private static function assertSafeName(string $name): void
    {
        if ($name === '' || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) !== 1) {
            throw new RuntimeException(__('Invalid Odoo database or container name for backup.'));
        }
    }
}
