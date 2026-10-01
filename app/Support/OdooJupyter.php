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

    public static function launchCommand(string $database, string $url = '', string $token = '', string $password = ''): string
    {
        $database = preg_replace('/[^a-z0-9_]/', '', $database) ?? '';
        $url = preg_match('#^https://[A-Za-z0-9.-]+$#', $url) === 1 ? $url : '';
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token) ?? '';
        $password = preg_replace('/[^A-Za-z0-9]/', '', $password) ?? '';
        // ponytail: Compose interpolates $ in the command. $$ is the only escape; a bare $( fails the deploy.
        // The database, URL, token and password are literals so they match the GPSH link even when the container env is empty.

        return str_replace('$', '$$', str_replace(
            ['__ODOO_DB__', '__ODOO_URL__', '__ODOO_TOKEN__', '__ODOO_PASSWORD__'],
            [$database, $url, $token, $password],
            <<<'BASH'
python3 - <<'PY' || true
import os, time
from pathlib import Path
host = os.environ.get("HOST", "postgresql")
user = os.environ.get("USER") or ""
password = os.environ.get("PASSWORD") or ""
database = os.environ.get("ODOO_DATABASE") or "__ODOO_DB__"
root = Path("/tmp/gpsh_addons/gpsh_autoconnect")
try:
    (root / "controllers").mkdir(parents=True, exist_ok=True)
    (root / "__manifest__.py").write_text("{'name': 'GPSH connect', 'version': '1.0', 'depends': ['web'], 'installable': True}\n")
    (root / "__init__.py").write_text("from . import controllers\n")
    (root / "controllers" / "__init__.py").write_text("from . import enter\n")
    token = "__ODOO_TOKEN__"
    admin_password = "__ODOO_PASSWORD__" or "admin"
    (root / "controllers" / "enter.py").write_text(
        "import hmac\n"
        "import odoo\n"
        "from odoo import http\n"
        "from odoo.http import request\n"
        "TOKEN = " + repr(token) + "\n"
        "ADMIN_PASSWORD = " + repr(admin_password) + "\n"
        "DATABASE = " + repr(database) + "\n"
        "class GpshEnter(http.Controller):\n"
        "    @http.route('/gpsh/enter', type='http', auth='none', csrf=False, sitemap=False)\n"
        "    def enter(self, token=None, **kwargs):\n"
        "        given = token or ''\n"
        "        if not TOKEN or len(given) != len(TOKEN) or not hmac.compare_digest(given, TOKEN):\n"
        "            return request.redirect('/web/login')\n"
        "        def login(secret):\n"
        "            credential = {'login': 'admin', 'password': secret, 'type': 'password'}\n"
        "            try:\n"
        "                with odoo.modules.registry.Registry(DATABASE).cursor() as cr:\n"
        "                    env = odoo.api.Environment(cr, None, {})\n"
        "                    try:\n"
        "                        from odoo.http.session import authenticate, save_session\n"
        "                        authenticate(request.session, env, credential)\n"
        "                        request.session.db = DATABASE\n"
        "                        save_session(request, env)\n"
        "                    except ImportError:\n"
        "                        request.session.authenticate(DATABASE, credential)\n"
        "                        request.session.db = DATABASE\n"
        "                return True\n"
        "            except Exception:\n"
        "                return False\n"
        "        secrets = [ADMIN_PASSWORD] if ADMIN_PASSWORD == 'admin' else [ADMIN_PASSWORD, 'admin']\n"
        "        if not any(login(secret) for secret in secrets):\n"
        "            return request.redirect('/web/login')\n"
        "        return request.redirect('/odoo')\n"
    )
    import configparser
    parser = configparser.ConfigParser()
    parser.read("/etc/odoo/odoo.conf")
    current = parser.get("options", "addons_path", fallback="/usr/lib/python3/dist-packages/odoo/addons")
    Path("/tmp/gpsh-addons-path").write_text("/tmp/gpsh_addons," + current)
except Exception:
    pass
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
ready = False
if conn is not None:
    conn.autocommit = True
    cur = conn.cursor()
    cur.execute("SELECT 1 FROM pg_database WHERE datname=%s", (database,))
    if cur.fetchone():
        try:
            other = connect(database)
            check = other.cursor()
            check.execute("SELECT 1 FROM information_schema.tables WHERE table_name='ir_module_module'")
            ready = check.fetchone() is not None
            other.close()
        except Exception:
            ready = False
open("/tmp/odoo-db-ready", "w").write("1" if ready else "0")
PY
args=(--db_host="${HOST:-postgresql}" --db_port="${PORT:-5432}" --db_user="$USER" --db_password="$PASSWORD" --http-interface=0.0.0.0 --proxy-mode --no-database-list)
addons=$(cat /tmp/gpsh-addons-path 2>/dev/null || echo /mnt/extra-addons,/usr/lib/python3/dist-packages/odoo/addons)
load=(--db-filter='^__ODOO_DB__$' --addons-path="$addons" --load=base,web,gpsh_autoconnect)
if [ ! -f /tmp/odoo-db-ready ] || [ "$(cat /tmp/odoo-db-ready)" != "1" ]; then
  odoo "${args[@]}" "${load[@]}" --without-demo=all -d __ODOO_DB__ -i base,gpsh_autoconnect --stop-after-init || true
fi
python3 - <<'PY' || true
import os
url = "__ODOO_URL__"
password = "__ODOO_PASSWORD__" or "admin"
database = "__ODOO_DB__"
host = os.environ.get("HOST", "postgresql")
user = os.environ.get("USER") or ""
dbpass = os.environ.get("PASSWORD") or ""
if database and user and dbpass:
    try:
        import psycopg2
        conn = psycopg2.connect(host=host, user=user, password=dbpass, dbname=database)
    except ImportError:
        import psycopg
        conn = psycopg.connect(host=host, user=user, password=dbpass, dbname=database)
    conn.autocommit = True
    cur = conn.cursor()
    if url:
        for key, value in (("web.base.url", url), ("web.base.url.freeze", "True")):
            cur.execute("UPDATE ir_config_parameter SET value=%s WHERE key=%s", (value, key))
            if cur.rowcount == 0:
                cur.execute("INSERT INTO ir_config_parameter (key, value, create_uid, write_uid, create_date, write_date) VALUES (%s, %s, 1, 1, NOW(), NOW())", (key, value))
    conn.close()
PY
python3 - <<'PY' || true
import os
url = "__ODOO_URL__"
password = "__ODOO_PASSWORD__" or "admin"
database = "__ODOO_DB__"
host = os.environ.get("HOST", "postgresql")
user = os.environ.get("USER") or ""
dbpass = os.environ.get("PASSWORD") or ""
port = os.environ.get("PORT", "5432")
if not database or not user or not dbpass:
    raise SystemExit(0)
import odoo
odoo.tools.config.parse_config(["-c", "/etc/odoo/odoo.conf", "--db_host", host, "--db_port", port, "--db_user", user, "--db_password", dbpass, "-d", database])
registry = odoo.modules.registry.Registry(database)
with registry.cursor() as cr:
    env = odoo.api.Environment(cr, odoo.SUPERUSER_ID, {})
    if url:
        env["ir.config_parameter"].sudo().set_param("web.base.url", url)
        env["ir.config_parameter"].sudo().set_param("web.base.url.freeze", "True")
    env.ref("base.user_admin").sudo().write({"password": password})
    cr.commit()
PY
exec odoo "${args[@]}" "${load[@]}" -d __ODOO_DB__
BASH));
    }

    /**
     * Traefik talks to Odoo over HTTP. Without this header Odoo rebuilds links as http:// and the browser leaves HTTPS.
     *
     * @param  array<int|string, mixed>|Collection<int|string, mixed>  $labels
     * @return array<int|string, mixed>
     */
    public static function forwardedProtoLabels(array|\Illuminate\Support\Collection $labels): array
    {
        if ($labels instanceof \Illuminate\Support\Collection) {
            $labels = $labels->all();
        }
        $header = 'traefik.http.middlewares.gpsh-forwarded-proto.headers.customrequestheaders.X-Forwarded-Proto=https';
        $found = false;
        foreach ($labels as $index => $label) {
            if (! is_string($label) || ! str_contains($label, 'traefik.http.routers.') || ! str_contains($label, '.middlewares=')) {
                continue;
            }
            // The HTTP router only redirects. Forcing X-Forwarded-Proto there makes Traefik treat the request as already HTTPS and proxy it, so the browser stays on http://.
            if (str_contains($label, 'redirect-to-https') || ! str_contains($label, 'https-')) {
                continue;
            }
            $found = true;
            if (! str_contains($label, 'gpsh-forwarded-proto')) {
                $labels[$index] = $label.',gpsh-forwarded-proto';
            }
        }
        if (! in_array($header, $labels, true)) {
            $labels[] = $header;
        }
        if (! $found) {
            foreach ($labels as $label) {
                if (! is_string($label) || ! preg_match('/^traefik\.http\.routers\.(https-[^=]+)\.tls=true$/', $label, $matches)) {
                    continue;
                }
                $labels[] = 'traefik.http.routers.'.$matches[1].'.middlewares=gpsh-forwarded-proto';
            }
        }

        return $labels;
    }

    /**
     * The service parser rewrites named volumes and relative binds.
     * Jupyter must keep the source Odoo ends up mounting, not a second volume.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    public static function alignParsedServices(array $services, ?string $database = null, string $url = '', string $token = '', string $password = ''): array
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
                $service['command'] = [self::launchCommand($database, $url, $token, $password)];
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
                $labels = self::forwardedProtoLabels($service['labels'] ?? []);
                if ($token !== '') {
                    foreach ($labels as $index => $label) {
                        if (! is_string($label) || ! str_contains($label, 'traefik.http.routers.https-') || ! str_contains($label, '.middlewares=')) {
                            continue;
                        }
                        if (! str_contains($label, 'gpsh-enter')) {
                            $labels[$index] = $label.',gpsh-enter';
                        }
                    }
                    $labels[] = 'traefik.http.middlewares.gpsh-enter.redirectregex.regex=^https://([^/]+)/?$$';
                    $labels[] = 'traefik.http.middlewares.gpsh-enter.redirectregex.replacement=https://$${1}/gpsh/enter?token='.$token;
                    $labels[] = 'traefik.http.middlewares.gpsh-enter.redirectregex.permanent=false';
                }
                $service['labels'] = $labels;

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
