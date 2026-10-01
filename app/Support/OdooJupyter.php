<?php

namespace App\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Optional JupyterLab service for an Odoo compose stack.
 *
 * Jupyter mounts the same addon source Odoo already uses:
 * that source on /mnt/extra-addons, and the same source on /workspace/addons.
 * It does not copy files and it does not receive the Docker socket,
 * Odoo config, PostgreSQL, or the instance data directory.
 */
class OdooJupyter
{
    public const IMAGE = 'jupyter/datascience-notebook:latest';

    public const SERVICE_NAME = 'jupyter';

    public const WORKSPACE = '/workspace/addons';

    public const LISTEN_PORT = '8888';

    public static function proxyPort(string $serviceName, ?string $detected): ?string
    {
        if ($serviceName === self::SERVICE_NAME) {
            return self::LISTEN_PORT;
        }

        return $detected;
    }

    public static function isOdooCompose(string $compose): bool
    {
        $yaml = self::parse($compose);

        return is_array($yaml) && self::odooService($yaml['services'] ?? []) !== null;
    }

    public static function inject(string $compose): string
    {
        $yaml = self::parse($compose);
        if (! is_array($yaml)) {
            return $compose;
        }

        $services = $yaml['services'] ?? null;
        if (! is_array($services) || isset($services[self::SERVICE_NAME])) {
            return $compose;
        }

        $odoo = self::odooService($services);
        if ($odoo === null) {
            return $compose;
        }

        $source = self::addonVolumeSource($odoo['volumes'] ?? []);
        if ($source === null) {
            return $compose;
        }

        $services[self::SERVICE_NAME] = self::serviceDefinition($source);
        $yaml['services'] = $services;

        return Yaml::dump($yaml, 8, 2);
    }

    public static function launchCommand(): string
    {
        // ponytail: Compose interpolates $ in the command. $$ is the only escape; a bare $( fails the deploy.
        return str_replace('$', '$$', <<<'BASH'
python3 - <<'PY' || true
import os, time
host = os.environ.get("HOST", "postgresql")
user = os.environ.get("USER") or ""
password = os.environ.get("PASSWORD") or ""
database = os.environ.get("ODOO_DATABASE") or ""
if not database or not user or not password:
    raise SystemExit(0)
def connect(name):
    try:
        import psycopg2
        return psycopg2.connect(host=host, user=user, password=password, dbname=name)
    except ImportError:
        import psycopg
        return psycopg.connect(host=host, user=user, password=password, dbname=name)
conn = None
for _ in range(30):
    try:
        conn = connect("postgres")
        break
    except Exception:
        time.sleep(2)
if conn is None:
    raise SystemExit(0)
conn.autocommit = True
cur = conn.cursor()
cur.execute("SELECT 1 FROM pg_database WHERE datname=%s", (database,))
open("/tmp/odoo-db-exists", "w").write("1" if cur.fetchone() else "0")
PY
args=(--db_host="${HOST:-postgresql}" --db_port="${PORT:-5432}" --db_user="$USER" --db_password="$PASSWORD" --http-interface=0.0.0.0 --proxy-mode)
if [ ! -f /tmp/odoo-db-exists ] || [ "$(cat /tmp/odoo-db-exists)" != "1" ]; then
  odoo "${args[@]}" --without-demo=all -d "$ODOO_DATABASE" -i base --stop-after-init || true
  odoo shell -d "$ODOO_DATABASE" --no-http --db_host="${HOST:-postgresql}" --db_port="${PORT:-5432}" --db_user="$USER" --db_password="$PASSWORD" <<'PY' || true
import os
env.ref("base.user_admin").write({"password": os.environ.get("ODOO_ADMIN_PASSWORD") or "admin"})
env.cr.commit()
PY
fi
odoo "${args[@]}" -d "$ODOO_DATABASE" || exec odoo --http-interface=0.0.0.0 --proxy-mode
BASH);
    }

    /**
     * The service parser rewrites named volumes and relative binds.
     * Jupyter must keep the source Odoo ends up mounting, not a second volume.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    public static function alignParsedServices(array $services, ?string $database = null): array
    {
        foreach ($services as $name => &$service) {
            if (! is_array($service)) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            $isOdoo = $name === 'odoo'
                || str_starts_with($image, 'odoo:')
                || str_contains($image, '/odoo:');
            if (! $isOdoo) {
                continue;
            }
            $command = $service['command'] ?? null;
            $defaultCommand = ! array_key_exists('command', $service) || $command === null || $command === '' || $command === [] || $command === 'odoo';
            if (! $defaultCommand) {
                continue;
            }
            if ($database !== null && $database !== '' && $name === 'odoo') {
                // -c, not -lc: a login shell overwrites Docker's USER (the Postgres role).
                $service['entrypoint'] = ['bash', '-c'];
                $service['command'] = [self::launchCommand()];
                if (is_array($service['healthcheck'] ?? null)) {
                    $service['healthcheck']['start_period'] = '180s';
                }
                $environment = $service['environment'] ?? [];
                if ($environment instanceof \Illuminate\Support\Collection) {
                    $environment = $environment->all();
                }
                if (! is_array($environment)) {
                    $environment = [];
                }
                if (array_is_list($environment)) {
                    $environment[] = 'ODOO_DATABASE='.$database;
                } else {
                    $environment['ODOO_DATABASE'] = $database;
                }
                $service['environment'] = $environment;

                continue;
            }
            $service['command'] = 'odoo --http-interface=0.0.0.0';
        }
        unset($service);

        if (! isset($services[self::SERVICE_NAME]) || ! is_array($services[self::SERVICE_NAME])) {
            return $services;
        }

        $odoo = null;
        foreach ($services as $name => $service) {
            if (! is_array($service) || $name === self::SERVICE_NAME) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            if ($name === 'odoo' || str_starts_with($image, 'odoo:') || str_contains($image, '/odoo:')) {
                $odoo = $service;
                break;
            }
        }
        if ($odoo === null) {
            return $services;
        }

        $source = self::addonVolumeSource($odoo['volumes'] ?? []);
        if ($source === null) {
            return $services;
        }

        $services[self::SERVICE_NAME]['volumes'] = [
            $source.':'.self::WORKSPACE,
        ];

        return $services;
    }

    /**
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>|null
     */
    private static function odooService(array $services): ?array
    {
        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            $isOdoo = $name === 'odoo'
                || str_starts_with($image, 'odoo:')
                || str_contains($image, '/odoo:');
            if ($isOdoo) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Last addon mount wins: Docker hides an earlier mount on the same path.
     *
     * @param  array<int, mixed>  $volumes
     */
    private static function addonVolumeSource(array $volumes): ?string
    {
        $source = null;
        foreach ($volumes as $volume) {
            $parsed = self::parseVolume($volume);
            if ($parsed === null || ! str_contains(strtolower($parsed['target']), 'addon')) {
                continue;
            }
            if (! self::sourceIsShareable($parsed['source'])) {
                continue;
            }
            $source = $parsed['source'];
        }

        return $source;
    }

    /**
     * @return array{source: string, target: string}|null
     */
    private static function parseVolume(mixed $volume): ?array
    {
        if (is_string($volume)) {
            $parts = explode(':', $volume);
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                return null;
            }

            return [
                'source' => $parts[0],
                'target' => $parts[1],
            ];
        }

        if (! is_array($volume)) {
            return null;
        }

        $source = data_get($volume, 'source');
        $target = data_get($volume, 'target');
        if (! is_string($source) || ! is_string($target) || $source === '' || $target === '') {
            return null;
        }

        return [
            'source' => $source,
            'target' => $target,
        ];
    }

    private static function sourceIsShareable(string $source): bool
    {
        $source = str_replace('\\', '/', trim($source));
        $lower = strtolower($source);
        if ($source === '' || $source === '/' || str_contains($source, '..')) {
            return false;
        }
        if (str_contains($lower, 'docker.sock') || str_starts_with($lower, '/var/run')) {
            return false;
        }
        if ($lower === '/root' || str_starts_with($lower, '/root/')) {
            return false;
        }
        if ($lower === '/etc/odoo' || str_starts_with($lower, '/etc/odoo/')) {
            return false;
        }
        if ($lower === '/data/coolify' || $lower === '/data/coolify/') {
            return false;
        }
        if (preg_match('#/services/[^/]+$#', $lower) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serviceDefinition(string $volumeSource): array
    {
        return [
            'image' => self::IMAGE,
            // Root only long enough to give the addon directory to Odoo's user.
            // setpriv drops to 100:101 before Jupyter starts.
            'user' => '0:0',
            'working_dir' => self::WORKSPACE,
            'restart' => 'always',
            'expose' => [self::LISTEN_PORT],
            'entrypoint' => [
                'tini',
                '-g',
                '--',
                'bash',
                '-c',
                'chown -R 100:101 '.self::WORKSPACE.' && exec setpriv --reuid=100 --regid=101 --clear-groups "$$0" "$$@"',
            ],
            // The image healthcheck reads jovyan's runtime dir and stays unhealthy as UID 100.
            // Traefik skips unhealthy containers, so the public URL is a 404.
            'healthcheck' => [
                'disable' => true,
            ],
            'environment' => [
                'SERVICE_URL_JUPYTER_'.self::LISTEN_PORT,
                'JUPYTER_ENABLE_LAB=yes',
                'HOME=/tmp',
                'JUPYTER_CONFIG_DIR=/tmp/jupyter-config',
                'JUPYTER_DATA_DIR=/tmp/jupyter-data',
                'JUPYTER_RUNTIME_DIR=/tmp/jupyter-runtime',
                'JUPYTER_TOKEN=${SERVICE_PASSWORD_JUPYTER}',
            ],
            'command' => [
                'jupyter',
                'lab',
                '--ServerApp.token=${SERVICE_PASSWORD_JUPYTER}',
                '--ServerApp.root_dir='.self::WORKSPACE,
                '--ip=0.0.0.0',
                '--allow-root',
                '--no-browser',
            ],
            'volumes' => [
                $volumeSource.':'.self::WORKSPACE,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parse(string $compose): ?array
    {
        try {
            $yaml = Yaml::parse($compose);
        } catch (\Throwable) {
            return null;
        }

        return is_array($yaml) ? $yaml : null;
    }
}
