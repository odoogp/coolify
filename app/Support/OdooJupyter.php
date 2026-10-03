<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Optional JupyterLab service for an Odoo compose stack.
 *
 * The client Jupyter mounts that environment's addon volume on /workspace/addons.
 * It does not mount production when the environment is staging.
 * It does not copy files and it does not receive the Docker socket,
 * Odoo config, PostgreSQL, or the instance data directory.
 */
class OdooJupyter
{
    public const IMAGE = 'jupyter/datascience-notebook:latest';

    public const SERVICE_NAME = 'jupyter';

    public const OWNER_SERVICE_NAME = 'jupyterowner';

    public const STDLIB_SERVICE_NAME = 'stdlib';

    public const IMAGE_ADDONS = '/usr/lib/python3/dist-packages/odoo/addons';

    public const WORKSPACE = '/workspace/addons';

    public const LISTEN_PORT = '8888';

    public const OWNER_MODULES_MOUNT = '/data/coolify/gpsh-owner-modules:/gpsh-owner-modules:ro';

    public const ODOO_LOG = '/mnt/extra-addons/.gpsh/odoo.log';

    public const LATER_PROFILE = 'gpsh-later';

    public static function proxyPort(string $serviceName, ?string $detected): ?string
    {
        if ($serviceName === self::SERVICE_NAME || $serviceName === self::OWNER_SERVICE_NAME) {
            return self::LISTEN_PORT;
        }

        return $detected;
    }

    /**
     * External JupyterLab URL for this branch. Empty without the token: there is no anonymous session.
     */
    public static function sessionUrl(Service $service): ?string
    {
        if (! $service->jupyter_enabled || ! auth()->user()?->can('view', $service)) {
            return null;
        }

        $jupyter = $service->applications()->get()->firstWhere('name', self::SERVICE_NAME);
        if (! filled($jupyter?->fqdn)) {
            return null;
        }

        $token = $service->environment_variables()->where('key', 'SERVICE_PASSWORD_JUPYTER')->first()?->value;
        if (! filled($token)) {
            return null;
        }

        return getFqdnWithoutPort(firstDomainFromList((string) $jupyter->fqdn)).'?token='.urlencode((string) $token);
    }

    public static function hidesTerminal(string $name): bool
    {
        return in_array(strtolower($name), [self::OWNER_SERVICE_NAME, self::STDLIB_SERVICE_NAME], true);
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

    public static function injectOwner(string $compose): string
    {
        return $compose;
    }

    /**
     * One Jupyter for the instance owner, outside every client stack.
     * ponytail: only volumes on server id 0. A client on another machine is absent until that host has its own copy.
     *
     * @param  list<array{team: string, environment: string, custom: ?string, files: ?string, image: string}>  $instances
     */
    public static function ownerCompose(array $instances, string $token, string $host, string $network = 'coolify'): string
    {
        if (preg_match('/\A[A-Za-z0-9]{16,}\z/', $token) !== 1 || preg_match('/\A[A-Za-z0-9.-]+\z/', $host) !== 1 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,100}\z/', $network) !== 1) {
            throw new \RuntimeException('The owner Jupyter was refused.');
        }

        $mounts = ['/data/coolify/gpsh-owner-modules:/workspace/owner:ro'];
        $stdlib = [];
        $external = [];
        $used = [];
        foreach ($instances as $instance) {
            $team = Str::slug((string) ($instance['team'] ?? ''));
            $environment = Str::slug((string) ($instance['environment'] ?? ''));
            if ($team === '' || $environment === '') {
                continue;
            }
            $folder = $team.'/'.$environment;
            $suffix = 2;
            while (isset($used[$folder])) {
                $folder = $team.'/'.$environment.'-'.$suffix;
                $suffix++;
            }
            $used[$folder] = true;
            $root = '/workspace/'.$folder;
            foreach (['custom' => 'custom', 'files' => 'files'] as $key => $name) {
                $volume = (string) ($instance[$key] ?? '');
                if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,200}\z/', $volume) !== 1) {
                    continue;
                }
                $mounts[] = $volume.':'.$root.'/'.$name.':ro';
                $external[$volume] = ['name' => $volume, 'external' => true];
            }
            $image = (string) ($instance['image'] ?? '');
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/:-]{0,200}\z/', $image) !== 1) {
                continue;
            }
            $volume = self::stdlibVolumeName($image);
            $stdlib[$volume] = $image;
            $mounts[] = $volume.':'.$root.'/odoo:ro';
        }

        $services = [];
        foreach ($stdlib as $volume => $image) {
            $services['stdlib-'.substr($volume, strlen('odoo-stdlib-'))] = [
                'image' => $image,
                'user' => '0:0',
                'restart' => 'unless-stopped',
                'entrypoint' => ['sleep'],
                'command' => ['infinity'],
                'volumes' => [$volume.':'.self::IMAGE_ADDONS],
            ];
        }
        $services['jupyter'] = [
            'image' => self::IMAGE,
            'container_name' => 'gpsh-owner-jupyter',
            'user' => '0:0',
            'working_dir' => '/tmp',
            'restart' => 'unless-stopped',
            'networks' => [$network],
            'expose' => [self::LISTEN_PORT],
            'healthcheck' => ['disable' => true],
            'environment' => [
                'JUPYTER_ENABLE_LAB=yes',
                'HOME=/tmp',
                'JUPYTER_CONFIG_DIR=/tmp/jupyter-config',
                'JUPYTER_DATA_DIR=/tmp/jupyter-data',
                'JUPYTER_RUNTIME_DIR=/tmp/jupyter-runtime',
                'JUPYTER_TOKEN='.$token,
            ],
            'entrypoint' => ['sh', '-c'],
            'command' => [self::ownerStartCommand()],
            'labels' => [
                'traefik.enable=true',
                'traefik.docker.network='.$network,
                'traefik.http.routers.gpsh-owner-jupyter-http.rule=Host(`'.$host.'`) && !PathPrefix(`/.well-known/acme-challenge/`)',
                'traefik.http.routers.gpsh-owner-jupyter-http.entryPoints=http',
                'traefik.http.routers.gpsh-owner-jupyter-http.middlewares=redirect-to-https',
                'traefik.http.routers.gpsh-owner-jupyter-http.service=gpsh-owner-jupyter',
                'traefik.http.routers.gpsh-owner-jupyter.rule=Host(`'.$host.'`)',
                'traefik.http.routers.gpsh-owner-jupyter.entryPoints=https',
                'traefik.http.routers.gpsh-owner-jupyter.tls=true',
                'traefik.http.routers.gpsh-owner-jupyter.tls.certresolver=letsencrypt',
                'traefik.http.routers.gpsh-owner-jupyter.tls.domains[0].main='.$host,
                'traefik.http.services.gpsh-owner-jupyter.loadbalancer.server.port='.self::LISTEN_PORT,
            ],
            'volumes' => $mounts,
        ];
        foreach ($stdlib as $volume => $image) {
            $external[$volume] = ['name' => $volume];
        }

        $yaml = Yaml::dump([
            'services' => $services,
            'networks' => [
                $network => ['name' => $network, 'external' => true],
            ],
        ], 8, 2);
        if ($external !== []) {
            $yaml .= "volumes:\n";
            foreach ($external as $name => $spec) {
                $yaml .= "  {$name}:\n    name: {$name}\n";
                if (($spec['external'] ?? false) === true) {
                    $yaml .= "    external: true\n";
                }
            }
        }

        return $yaml;
    }

    public static function proxyNetworkFrom(string $listed): string
    {
        $names = preg_split('/\s+/', trim($listed)) ?: [];
        $usable = [];
        foreach ($names as $name) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,100}\z/', $name) !== 1 || in_array($name, ['bridge', 'host', 'none', 'ingress'], true)) {
                continue;
            }
            $usable[] = $name;
        }
        if (in_array('coolify', $usable, true)) {
            return 'coolify';
        }

        return $usable[0] ?? 'coolify';
    }

    private static function ownerStartCommand(): string
    {
        $port = self::LISTEN_PORT;

        return <<<BASH
mkdir -p /tmp/jupyter-config /workspace
cat > /tmp/jupyter-config/jupyter_server_config.py << 'EOF'
c.ServerApp.ip = "0.0.0.0"
c.ServerApp.port = {$port}
c.ServerApp.root_dir = "/workspace"
c.ServerApp.allow_root = True
c.MappingKernelManager.cull_idle_timeout = 1800
c.MappingKernelManager.cull_interval = 300
c.TerminalManager.cull_inactive_timeout = 1800
c.TerminalManager.cull_interval = 300
EOF
exec tini -g -- start-notebook.py --ip=0.0.0.0 --port={$port} --allow-root --no-browser
BASH;
    }

    public static function ensureOwner(): string
    {
        $server = Server::query()->find(0);
        if (! $server instanceof Server || ! $server->isFunctional()) {
            throw new \RuntimeException('No server is available for the owner Jupyter.');
        }
        $token = Cache::get('gpsh-owner-jupyter-token');
        if (! is_string($token) || preg_match('/\A[A-Za-z0-9]{16,}\z/', $token) !== 1) {
            $token = bin2hex(random_bytes(16));
            Cache::forever('gpsh-owner-jupyter-token', $token);
        }
        $host = explode('/', (string) preg_replace('#\Ahttps?://#', '', generateFqdn($server, 'gpsh-owner', forceHttps: true)))[0];
        if (preg_match('/\A[a-z0-9.-]+\z/i', $host) !== 1) {
            throw new \RuntimeException('The owner Jupyter host is not valid.');
        }
        $network = 'coolify';
        if (! app()->runningUnitTests()) {
            $listed = instant_remote_process([
                'docker inspect coolify-proxy --format \'{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}\'',
            ], $server);
            $network = self::proxyNetworkFrom((string) $listed);
        }
        $compose = self::ownerCompose(self::ownerInstances(), $token, $host, $network);
        if (! app()->runningUnitTests()) {
            $volumes = self::ownerExternalVolumes($compose);
            if ($volumes !== []) {
                $listed = implode("\n", $volumes);
                $missing = instant_remote_process([<<<BASH
set -eu
while IFS= read -r volume; do
  [ -n "\$volume" ] || continue
  docker volume inspect "\$volume" >/dev/null 2>&1 || printf '%s\n' "\$volume"
done <<'VOLS'
{$listed}
VOLS
BASH], $server);
                $compose = self::withoutVolumes($compose, array_values(array_filter(explode("\n", trim((string) $missing)))));
            }
            $quotedNetwork = escapeshellarg($network);
            $needle = escapeshellarg('"main":"'.$host.'"');
            $needleSpaced = escapeshellarg('"main": "'.$host.'"');
            $quotedHost = escapeshellarg($host);
            $output = instant_remote_process([<<<BASH
set -eu
before=missing
docker inspect gpsh-owner-jupyter >/dev/null 2>&1 && before=present
dir=/data/coolify/gpsh-owner-jupyter
mkdir -p "\$dir"
cat > "\$dir/docker-compose.yml" <<'EOF'
{$compose}
EOF
docker compose -f "\$dir/docker-compose.yml" --project-name gpsh-owner-jupyter up -d
if docker network inspect {$quotedNetwork} >/dev/null 2>&1; then
  docker inspect gpsh-owner-jupyter --format '{{range \$k, \$v := .NetworkSettings.Networks}}{{\$k}} {{end}}' | grep -qw {$quotedNetwork} || docker network connect {$quotedNetwork} gpsh-owner-jupyter || true
fi
running=false
if docker inspect -f '{{.State.Running}}' gpsh-owner-jupyter 2>/dev/null | grep -qx true; then
  running=true
fi
cert=pending
if docker exec coolify-proxy grep -F -e {$needle} -e {$needleSpaced} /traefik/acme.json >/dev/null 2>&1; then
  cert=applied
fi
if [ "\$cert" = pending ]; then
  curl -fsS -o /dev/null -k --connect-timeout 5 --max-time 15 --resolve {$quotedHost}:443:127.0.0.1 https://{$quotedHost}/ || true
fi
echo "gpsh-owner-status before=\$before running=\$running cert=\$cert"
BASH], $server);
            $message = self::ownerRepairMessage((string) $output, $host);
            if ($message !== null && ! Cache::has('gpsh-owner-jupyter-notified')) {
                Cache::put('gpsh-owner-jupyter-notified', true, now()->addMinutes(10));
                GpshNotices::publish(null, 'custom', __('Owner Jupyter'), $message, 'owner', null, auth()->id());
            }
        }

        return 'https://'.$host.'?token='.urlencode($token);
    }

    public static function ownerRepairMessage(string $output, string $host): ?string
    {
        if (preg_match('/gpsh-owner-status before=(present|missing) running=(true|false) cert=(applied|pending)/', $output, $match) !== 1) {
            return null;
        }
        $lines = [];
        if ($match[1] === 'missing' || $match[2] !== 'true') {
            $lines[] = $match[2] === 'true'
                ? __('The owner Jupyter was not running, so it was started.')
                : __('The owner Jupyter did not start.');
        }
        if ($match[3] !== 'applied') {
            $lines[] = __('Let\'s Encrypt has not issued the certificate for :host yet. Opening it asked for that certificate.', ['host' => $host]);
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    /**
     * @return list<array{team: string, environment: string, custom: ?string, files: ?string, image: string}>
     */
    public static function ownerInstances(): array
    {
        $rows = [];
        foreach (Service::query()->with(['environment.project.team', 'applications.persistentStorages'])->get() as $service) {
            if (! $service->supportsOdooJupyter() || (string) $service->server_id !== '0') {
                continue;
            }
            $environment = $service->environment;
            $team = $environment?->project?->team;
            if ($environment === null || $team === null) {
                continue;
            }
            $custom = null;
            $files = null;
            $image = '';
            foreach ($service->applications as $application) {
                $name = strtolower((string) $application->name);
                if (! str_contains($name, 'odoo') || str_contains($name, 'jupyter')) {
                    continue;
                }
                $image = (string) $application->image;
                foreach ($application->persistentStorages as $storage) {
                    $path = (string) $storage->mount_path;
                    if (str_contains($path, 'extra-addons')) {
                        $custom = (string) $storage->name;
                    }
                    if ($path === '/var/lib/odoo') {
                        $files = (string) $storage->name;
                    }
                }
            }
            $rows[] = [
                'team' => (string) $team->name,
                'environment' => (string) $environment->name,
                'custom' => $custom,
                'files' => $files,
                'image' => $image !== '' ? $image : 'odoo:20',
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function ownerExternalVolumes(string $compose): array
    {
        $yaml = self::parse($compose);
        $names = [];
        foreach (($yaml['volumes'] ?? []) as $name => $volume) {
            if (is_array($volume) && ($volume['external'] ?? false) === true && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,200}\z/', (string) $name) === 1) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $missing
     */
    public static function withoutVolumes(string $compose, array $missing): string
    {
        $missing = array_fill_keys($missing, true);
        if ($missing === []) {
            return $compose;
        }
        $yaml = self::parse($compose);
        if ($yaml === null) {
            return $compose;
        }
        if (isset($yaml['volumes']) && is_array($yaml['volumes'])) {
            foreach (array_keys($missing) as $name) {
                unset($yaml['volumes'][$name]);
            }
            if ($yaml['volumes'] === []) {
                unset($yaml['volumes']);
            }
        }
        foreach ($yaml['services'] ?? [] as $serviceName => $service) {
            if (! is_array($service) || ! isset($service['volumes']) || ! is_array($service['volumes'])) {
                continue;
            }
            $yaml['services'][$serviceName]['volumes'] = array_values(array_filter(
                $service['volumes'],
                fn (mixed $mount): bool => ! is_string($mount) || ! array_key_exists(explode(':', $mount, 2)[0], $missing),
            ));
        }

        return Yaml::dump($yaml, 8, 2);
    }

    /**
     * Volumes left on the instance after an environment or its modules were removed.
     *
     * @param  list<string>  $present
     * @param  list<string>  $inUse
     * @return list<string>
     */
    public static function leftoverVolumes(array $present, array $inUse): array
    {
        $used = array_fill_keys($inUse, true);
        $kept = [];
        foreach ($present as $name) {
            $name = trim((string) $name);
            if ($name === '' || isset($used[$name])) {
                continue;
            }
            $odooVolume = preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*_(odoo-extra-addons|odoo-web-data|postgresql-data)\z/', $name) === 1;
            $stdlib = preg_match('/\Aodoo-stdlib-[A-Za-z0-9._-]+\z/', $name) === 1;
            if ($odooVolume || $stdlib) {
                $kept[] = $name;
            }
        }
        sort($kept);

        return $kept;
    }

    /**
     * @param  list<string>  $present
     * @param  list<string>  $inUse
     * @param  array<string, array{client: string, environment: string}>  $owners
     * @return list<array{name: string, client: string, environment: string}>
     */
    public static function leftoverVolumeRows(array $present, array $inUse, array $owners): array
    {
        $rows = [];
        foreach (self::leftoverVolumes($present, $inUse) as $name) {
            $owner = $owners[$name] ?? ['client' => '', 'environment' => ''];
            if (preg_match('/\Aodoo-stdlib-(.+)\z/', $name, $matches) === 1) {
                $owner = ['client' => 'Shared across clients', 'environment' => 'Odoo '.$matches[1]];
            }
            $rows[] = [
                'name' => $name,
                'client' => (string) ($owner['client'] ?? ''),
                'environment' => (string) ($owner['environment'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{name: string, client: string, environment: string}>  $rows
     * @return array{rows: list<array{name: string, client: string, environment: string}>, page: int, pages: int, total: int}
     */
    public static function pageVolumeRows(array $rows, int $page, int $perPage = 10): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            'rows' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'page' => $page,
            'pages' => $pages,
            'total' => count($rows),
        ];
    }

    public static function rememberServiceVolumes(Service $service, ?Environment $environment = null): void
    {
        $environment ??= $service->environment;
        $client = (string) ($environment?->project?->team?->name ?? '');
        $instance = (string) ($environment?->name ?? '');
        if ($client === '' && $instance === '') {
            return;
        }
        $owners = Cache::get('gpsh-volume-owners', []);
        if (! is_array($owners)) {
            $owners = [];
        }
        $service->loadMissing(['applications.persistentStorages', 'databases.persistentStorages']);
        $names = [
            $service->uuid.'_odoo-extra-addons',
            $service->uuid.'_odoo-web-data',
            $service->uuid.'_postgresql-data',
        ];
        foreach ($service->applications as $application) {
            foreach ($application->persistentStorages as $storage) {
                $names[] = (string) $storage->name;
            }
        }
        foreach ($service->databases as $database) {
            foreach ($database->persistentStorages as $storage) {
                $names[] = (string) $storage->name;
            }
        }
        foreach (array_unique($names) as $name) {
            if ($name !== '') {
                $owners[$name] = ['client' => $client, 'environment' => $instance];
            }
        }
        Cache::forever('gpsh-volume-owners', $owners);
    }

    /**
     * @return array<string, array{client: string, environment: string}>
     */
    public static function volumeOwnerIndex(): array
    {
        $owners = Cache::get('gpsh-volume-owners', []);
        if (! is_array($owners)) {
            $owners = [];
        }
        foreach (Service::withTrashed()->with(['environment.project.team'])->get() as $service) {
            if (! $service->supportsOdooJupyter()) {
                continue;
            }
            $client = (string) ($service->environment?->project?->team?->name ?? '');
            $instance = (string) ($service->environment?->name ?? '');
            if ($client === '' && $instance === '') {
                continue;
            }
            foreach ([
                $service->uuid.'_odoo-extra-addons',
                $service->uuid.'_odoo-web-data',
                $service->uuid.'_postgresql-data',
            ] as $name) {
                $owners[$name] = ['client' => $client, 'environment' => $instance];
            }
        }
        Cache::forever('gpsh-volume-owners', $owners);

        return $owners;
    }

    /**
     * @return list<string>
     */
    public static function volumesInUse(): array
    {
        $names = LocalPersistentVolume::query()->pluck('name')->all();
        foreach (self::ownerInstances() as $instance) {
            $names[] = self::stdlibVolumeName((string) ($instance['image'] ?? ''));
        }

        return array_values(array_filter($names, fn (mixed $name): bool => is_string($name) && $name !== ''));
    }

    /**
     * @return list<array{name: string, client: string, environment: string}>
     */
    public static function leftoverVolumeRowsOnInstance(): array
    {
        if (app()->runningUnitTests()) {
            return [];
        }
        $server = Server::query()->find(0);
        if (! $server instanceof Server || ! $server->isFunctional()) {
            return [];
        }
        $raw = instant_remote_process(['docker volume ls -q'], $server, false);
        $present = preg_split('/\R/', trim((string) $raw)) ?: [];

        return self::leftoverVolumeRows($present, self::volumesInUse(), self::volumeOwnerIndex());
    }

    public static function deleteLeftoverVolume(string $name): void
    {
        if (! in_array($name, self::leftoverVolumes([$name], self::volumesInUse()), true) || app()->runningUnitTests()) {
            return;
        }
        $server = Server::query()->find(0);
        if (! $server instanceof Server || ! $server->isFunctional()) {
            return;
        }
        instant_remote_process([
            'docker rm -f gpsh-owner-jupyter',
            'docker volume rm -f '.escapeshellarg($name),
        ], $server, false);
    }

    public static function forgetOwnerModule(string $name): void
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1 || app()->runningUnitTests()) {
            return;
        }
        $server = Server::query()->find(0);
        if (! $server instanceof Server || ! $server->isFunctional()) {
            return;
        }
        instant_remote_process([
            'rm -rf '.escapeshellarg('/data/coolify/gpsh-owner-modules/'.$name),
        ], $server, false);
    }

    /**
     * @param  list<string>  $modules
     */
    public static function launchCommand(string $database, string $url = '', string $token = '', string $password = '', array $modules = []): string
    {
        $database = preg_replace('/[^a-z0-9_]/', '', $database) ?? '';
        $url = preg_match('#^https://[A-Za-z0-9.-]+$#', $url) === 1 ? $url : '';
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token) ?? '';
        $password = preg_replace('/[^A-Za-z0-9]/', '', $password) ?? '';
        $modules = array_values(array_unique(array_filter(
            $modules,
            fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1,
        )));
        // ponytail: Compose interpolates $ in the command. $$ is the only escape; a bare $( fails the deploy.
        // The database, URL, token and password are literals so they match the GPSH link even when the container env is empty.

        return str_replace('$', '$$', str_replace(
            ['__ODOO_DB__', '__ODOO_URL__', '__ODOO_TOKEN__', '__ODOO_PASSWORD__', '__OWNER_KEEP__', '__OWNER_LIST__'],
            [$database, $url, $token, $password, ' '.implode(' ', $modules).' ', implode(' ', $modules)],
            <<<'BASH'
python3 - <<'PY' || true
from pathlib import Path
def page(text):
    Path("/tmp/gpsh-status.html").write_text("<!doctype html><meta charset=\"utf-8\"><meta http-equiv=\"refresh\" content=\"4\"><title>GPSH</title><body style=\"margin:0;background:#0c0c0c;color:#f5f5f5;font-family:sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center\"><div style=\"max-width:28rem;padding:2rem\"><p id=\"m\" style=\"font-size:1.5rem;line-height:1.4\">"+text+"</p><p style=\"opacity:.65\">Esta página se actualiza sola.</p></div><script>var lines=['Estamos preparando todo.','No se vaya, todo comenzará pronto.','Es mejor que vayas por un café.','Ya casi está.','Estamos dejando Odoo listo.','Preparando Odoo.'];var i=0;setInterval(function(){i=(i+1)%lines.length;document.getElementById('m').textContent=lines[i]},5000)</script></body>")
page("Estamos preparando todo.")
Path("/tmp/gpsh-page.py").write_text("import sys\nfrom pathlib import Path\ntext=sys.argv[1] if len(sys.argv)>1 else \"Preparando Odoo.\"\nPath(\"/tmp/gpsh-status.html\").write_text(\"<!doctype html><meta charset=\\\"utf-8\\\"><meta http-equiv=\\\"refresh\\\" content=\\\"4\\\"><title>GPSH</title><body style=\\\"margin:0;background:#0c0c0c;color:#f5f5f5;font-family:sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center\\\"><div style=\\\"max-width:28rem;padding:2rem\\\"><p id=\\\"m\\\" style=\\\"font-size:1.5rem;line-height:1.4\\\">\"+text+\"</p><p style=\\\"opacity:.65\\\">Esta página se actualiza sola.</p></div><script>var lines=['Estamos preparando todo.','No se vaya, todo comenzará pronto.','Es mejor que vayas por un café.','Ya casi está.','Estamos dejando Odoo listo.','Preparando Odoo.'];var i=0;setInterval(function(){i=(i+1)%lines.length;document.getElementById('m').textContent=lines[i]},5000)</script></body>\")\n")
Path("/tmp/gpsh-status.py").write_text("from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer\nimport http.client\nclass H(BaseHTTPRequestHandler):\n    def do_GET(self):\n        self.forward()\n    def do_POST(self):\n        self.forward()\n    def do_HEAD(self):\n        self.forward()\n    def forward(self):\n        try:\n            length=int(self.headers.get('Content-Length') or 0)\n            payload=self.rfile.read(length) if length else None\n            headers={k:v for k,v in self.headers.items() if k.lower() not in ('host','content-length')}\n            conn=http.client.HTTPConnection('127.0.0.1',8071,timeout=60)\n            conn.request(self.command,self.path,body=payload,headers=headers)\n            resp=conn.getresponse()\n            data=resp.read()\n            self.send_response(resp.status)\n            for key,value in resp.getheaders():\n                if key.lower() not in ('transfer-encoding','connection','content-length'):\n                    self.send_header(key,value)\n            self.send_header('Content-Length',str(len(data)))\n            self.end_headers()\n            if self.command!='HEAD':\n                self.wfile.write(data)\n            conn.close()\n        except Exception:\n            page=open('/tmp/gpsh-status.html','rb').read()\n            self.send_response(200)\n            self.send_header('Content-Type','text/html; charset=utf-8')\n            self.send_header('Cache-Control','no-store')\n            self.send_header('Content-Length',str(len(page)))\n            self.end_headers()\n            if self.command!='HEAD':\n                self.wfile.write(page)\n    def log_message(self,*a):\n        return\nThreadingHTTPServer(('0.0.0.0',8069),H).serve_forever()\n")
PY
python3 /tmp/gpsh-status.py >/tmp/gpsh-status.log 2>&1 &
echo $! > /tmp/gpsh-status.pid
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
        "    @http.route('/_odoo/paas/connect', type='http', auth='none', csrf=False, sitemap=False)\n"
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
            other.autocommit = True
            check.execute("SELECT 1 FROM information_schema.tables WHERE table_name='ir_module_module'")
            ready = check.fetchone() is not None
            check.execute("DROP TABLE IF EXISTS orm_signaling_registry, orm_signaling_assets, orm_signaling_default, orm_signaling_templates, orm_signaling_routing, orm_signaling_groups CASCADE")
            other.close()
        except Exception:
            ready = False
open("/tmp/odoo-db-ready", "w").write("1" if ready else "0")
PY
args=(--db_host="${HOST:-postgresql}" --db_port="${PORT:-5432}" --db_user="$USER" --db_password="$PASSWORD" --http-interface=0.0.0.0 --proxy-mode --no-database-list)
addons=$(cat /tmp/gpsh-addons-path 2>/dev/null || echo /mnt/extra-addons,/usr/lib/python3/dist-packages/odoo/addons)
load=(--db-filter='^__ODOO_DB__$' --addons-path="$addons")
if [ ! -f /tmp/odoo-db-ready ] || [ "$(cat /tmp/odoo-db-ready)" != "1" ]; then
  python3 /tmp/gpsh-page.py "Instalando la base." || true
  odoo "${args[@]}" "${load[@]}" --http-port=8071 --without-demo=all -d __ODOO_DB__ -i base --stop-after-init || true
fi
python3 - <<'PY' || true
import os
from pathlib import Path
host = os.environ.get("HOST", "postgresql")
user = os.environ.get("USER") or ""
password = os.environ.get("PASSWORD") or ""
database = os.environ.get("ODOO_DATABASE") or "__ODOO_DB__"
state = ""
conn = None
if database and user and password:
    try:
        import psycopg2
        conn = psycopg2.connect(host=host, user=user, password=password, dbname=database)
    except Exception:
        try:
            import psycopg
            conn = psycopg.connect(host=host, user=user, password=password, dbname=database)
        except Exception:
            conn = None
if conn is not None:
    try:
        cur = conn.cursor()
        cur.execute("SELECT state FROM ir_module_module WHERE name=%s", ("gpsh_autoconnect",))
        row = cur.fetchone()
        state = row[0] if row else ""
    except Exception:
        state = ""
    conn.close()
Path("/tmp/gpsh-connect-state").write_text(state or "")
PY
if [ "$(cat /tmp/gpsh-connect-state 2>/dev/null)" != "installed" ]; then
  python3 /tmp/gpsh-page.py "Preparando el acceso." || true
  odoo "${args[@]}" "${load[@]}" --http-port=8071 --without-demo=all -d __ODOO_DB__ -i gpsh_autoconnect --stop-after-init || true
fi
python3 /tmp/gpsh-page.py "Abriendo Odoo." || true
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
mkdir -p /var/lib/odoo/sessions /var/lib/odoo/filestore /mnt/extra-addons/.gpsh
chmod 755 /mnt/extra-addons/.gpsh || true
keep="__OWNER_KEEP__"
for link in /mnt/extra-addons/*; do
  [ -L "$link" ] || continue
  target=$(readlink "$link" || true)
  case "$target" in
    /gpsh-owner-modules/*)
      base=$(basename "$link")
      case "$keep" in *" $base "*) ;; *) rm -f "$link" ;; esac
      ;;
  esac
done
for module in __OWNER_LIST__; do
  case "$module" in ''|*[!A-Za-z0-9_]*) continue ;; esac
  [ -d "/gpsh-owner-modules/$module" ] || continue
  ln -sfn "/gpsh-owner-modules/$module" "/mnt/extra-addons/$module"
done
umask 022
exec > >(tee -a /mnt/extra-addons/.gpsh/odoo.log) 2>&1
if [ "$(id -u)" = "0" ]; then
  chown -R odoo:odoo /var/lib/odoo 2>/dev/null || true
  if command -v setpriv >/dev/null 2>&1; then
    exec setpriv --reuid=odoo --regid=odoo --init-groups --inh-caps=-all odoo "${args[@]}" "${load[@]}" --http-port=8071 -d __ODOO_DB__
  fi
fi
exec odoo "${args[@]}" "${load[@]}" --http-port=8071 -d __ODOO_DB__
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
     * @param  list<string>  $modules
     * @return array<string, mixed>
     */
    public static function alignParsedServices(array $services, ?string $database = null, string $url = '', string $token = '', string $password = '', array $modules = []): array
    {
        foreach ($services as $name => &$service) {
            if (! is_array($service) || $name === self::STDLIB_SERVICE_NAME || $name === self::OWNER_SERVICE_NAME) {
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
                $service['command'] = [self::launchCommand($database, $url, $token, $password, $modules)];
                $service['user'] = '0:0';
                $service['restart'] = 'unless-stopped';
                $volumes = $service['volumes'] ?? [];
                if ($volumes instanceof \Illuminate\Support\Collection) {
                    $volumes = $volumes->all();
                }
                if (! is_array($volumes)) {
                    $volumes = [];
                }
                if (! in_array(self::OWNER_MODULES_MOUNT, $volumes, true)) {
                    $volumes[] = self::OWNER_MODULES_MOUNT;
                }
                $service['volumes'] = $volumes;
                // The image healthcheck fails for the whole base install. Traefik then has no server and answers "no available server".
                $service['healthcheck'] = ['disable' => true];
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
                $service['labels'] = self::forwardedProtoLabels($service['labels'] ?? []);

                continue;
            }
            $service['command'] = 'odoo --http-interface=0.0.0.0';
        }
        unset($service);

        $odoo = null;
        foreach ($services as $name => $service) {
            if (! is_array($service) || in_array($name, [self::SERVICE_NAME, self::OWNER_SERVICE_NAME, self::STDLIB_SERVICE_NAME], true)) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            if ($name === 'odoo' || str_starts_with($image, 'odoo:') || str_contains($image, '/odoo:')) {
                $odoo = $service;
                break;
            }
        }
        $source = is_array($odoo) ? self::addonVolumeSource($odoo['volumes'] ?? []) : null;
        if ($source !== null && isset($services[self::SERVICE_NAME]) && is_array($services[self::SERVICE_NAME])) {
            $services[self::SERVICE_NAME]['volumes'] = [
                $source.':'.self::WORKSPACE,
            ];
        }
        if (isset($services[self::OWNER_SERVICE_NAME]) && is_array($services[self::OWNER_SERVICE_NAME])) {
            $services[self::OWNER_SERVICE_NAME]['volumes'] = self::ownerVolumes($services, $source);
        }
        foreach ([self::STDLIB_SERVICE_NAME, self::OWNER_SERVICE_NAME] as $later) {
            if (isset($services[$later]) && is_array($services[$later])) {
                $services[$later]['profiles'] = [self::LATER_PROFILE];
            }
        }

        return self::shareOdooCertificate($services);
    }

    /**
     * The owner Jupyter is one container outside the client stacks, so a client start does not launch another one.
     */
    public static function backgroundStartCommand(string $workdir, string $project): string
    {
        if (preg_match('#\A[A-Za-z0-9._/-]+\z#', $workdir) !== 1 || preg_match('/\A[A-Za-z0-9]+\z/', $project) !== 1) {
            throw new \RuntimeException('The background start was refused.');
        }

        return 'true';
    }

    /**
     * One certificate, requested by Odoo, lists the other public hosts as alternate names.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    public static function shareOdooCertificate(array $services): array
    {
        $sans = [];
        foreach ($services as $name => $service) {
            if (! is_array($service) || $name === 'odoo' || ! OdooGit::usesSharedCertificate((string) $name)) {
                continue;
            }
            $labels = $service['labels'] ?? [];
            if ($labels instanceof \Illuminate\Support\Collection) {
                $labels = $labels->all();
            }
            if (! is_array($labels)) {
                continue;
            }
            foreach ($labels as $label) {
                if (! is_string($label) || ! preg_match('/tls\.domains\[0\]\.main=([A-Za-z0-9.-]+)$/', $label, $matches)) {
                    continue;
                }
                $sans[] = $matches[1];
            }
        }
        $sans = array_values(array_unique($sans));
        $odooLabels = $services['odoo']['labels'] ?? null;
        if ($odooLabels instanceof \Illuminate\Support\Collection) {
            $odooLabels = $odooLabels->all();
        }
        if ($sans !== [] && is_array($odooLabels)) {
            $services['odoo']['labels'] = $odooLabels;
            foreach ($services['odoo']['labels'] as $label) {
                if (! is_string($label) || ! preg_match('/^(traefik\.http\.routers\.[^.]+)\.tls\.domains\[0\]\.main=/', $label, $matches)) {
                    continue;
                }
                $line = $matches[1].'.tls.domains[0].sans='.implode(',', $sans);
                if (! in_array($line, $services['odoo']['labels'], true)) {
                    $services['odoo']['labels'][] = $line;
                }
            }
        }
        foreach ($services as $name => $service) {
            if (! is_array($service) || $name === 'odoo' || ! OdooGit::usesSharedCertificate((string) $name)) {
                continue;
            }
            $labels = $service['labels'] ?? null;
            if ($labels instanceof \Illuminate\Support\Collection) {
                $labels = $labels->all();
            }
            if (! is_array($labels)) {
                continue;
            }
            $services[$name]['labels'] = array_values(array_filter(
                $labels,
                fn (mixed $label): bool => ! is_string($label) || ! str_contains($label, '.tls.certresolver='),
            ));
        }

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
                'mkdir -p '.self::WORKSPACE.'/.gpsh && if [ ! -f '.self::WORKSPACE.'/odoo-logs.sh ]; then printf "%s\n" "#!/bin/sh" "exec tail -n 200 -F '.self::WORKSPACE.'/.gpsh/odoo.log" > '.self::WORKSPACE.'/odoo-logs.sh; chmod 755 '.self::WORKSPACE.'/odoo-logs.sh; fi && chown -R 100:101 '.self::WORKSPACE.' && exec setpriv --reuid=100 --regid=101 --clear-groups "$$0" "$$@"',
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
                '--ServerApp.allow_password_change=False',
                '--ServerApp.root_dir='.self::WORKSPACE,
                '--MappingKernelManager.cull_idle_timeout=1800',
                '--MappingKernelManager.cull_interval=300',
                '--TerminalManager.cull_inactive_timeout=1800',
                '--TerminalManager.cull_interval=300',
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
     * @param  list<string>  $volumes
     * @return array<string, mixed>
     */
    private static function ownerServiceDefinition(array $volumes): array
    {
        return [
            'image' => self::IMAGE,
            'user' => '0:0',
            'working_dir' => '/tmp',
            'restart' => 'unless-stopped',
            'profiles' => [self::LATER_PROFILE],
            'expose' => [self::LISTEN_PORT],
            'depends_on' => [self::STDLIB_SERVICE_NAME],
            'entrypoint' => ['tini', '-g', '--', 'setpriv', '--reuid=100', '--regid=101', '--clear-groups'],
            'healthcheck' => ['disable' => true],
            'environment' => [
                'SERVICE_URL_JUPYTEROWNER_'.self::LISTEN_PORT,
                'JUPYTER_ENABLE_LAB=yes',
                'HOME=/tmp',
                'JUPYTER_CONFIG_DIR=/tmp/jupyter-config',
                'JUPYTER_DATA_DIR=/tmp/jupyter-data',
                'JUPYTER_RUNTIME_DIR=/tmp/jupyter-runtime',
                'JUPYTER_TOKEN=${SERVICE_PASSWORD_JUPYTEROWNER}',
            ],
            'command' => [
                'jupyter',
                'lab',
                '--ServerApp.token=${SERVICE_PASSWORD_JUPYTEROWNER}',
                '--ServerApp.allow_password_change=False',
                '--ServerApp.root_dir='.self::WORKSPACE,
                '--MappingKernelManager.cull_idle_timeout=1800',
                '--MappingKernelManager.cull_interval=300',
                '--TerminalManager.cull_inactive_timeout=1800',
                '--TerminalManager.cull_interval=300',
                '--ip=0.0.0.0',
                '--allow-root',
                '--no-browser',
            ],
            'volumes' => $volumes,
        ];
    }

    /**
     * Docker fills this volume from the image only while it is empty.
     * A different image tag uses another volume, so an upgrade does not keep the previous addons.
     */
    private static function stdlibVolumeName(string $image): string
    {
        $tag = 'odoo';
        if (preg_match('/:([^:@]+)$/', $image, $matches) === 1) {
            $tag = strtolower($matches[1]);
        }
        $tag = trim(preg_replace('/[^a-z0-9]+/', '-', $tag) ?? '', '-');

        return 'odoo-stdlib-'.($tag === '' ? 'odoo' : $tag);
    }

    /**
     * @param  array<string, mixed>  $services
     * @return list<string>
     */
    private static function ownerVolumes(array $services, ?string $customSource): array
    {
        $volumes = [];
        $stdlib = $services[self::STDLIB_SERVICE_NAME]['volumes'] ?? [];
        if (is_array($stdlib)) {
            foreach ($stdlib as $volume) {
                $parsed = self::parseVolume($volume);
                if ($parsed !== null && str_contains($parsed['target'], 'dist-packages/odoo/addons')) {
                    $volumes[] = $parsed['source'].':'.self::WORKSPACE.'/odoo:ro';
                    break;
                }
            }
        }
        $volumes[] = '/data/coolify/gpsh-owner-modules:'.self::WORKSPACE.'/owner:ro';
        if ($customSource !== null) {
            $volumes[] = $customSource.':'.self::WORKSPACE.'/custom:ro';
        }

        return $volumes;
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
