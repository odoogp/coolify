<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\OdooComposeTemplate;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Optional JupyterLab for file browsing on a service stack.
 *
 * Templates with includes_jupyter set service.jupyter_enabled. Inject adds a
 * jupyter sidecar (Odoo addon volume preferred; otherwise shareable named volumes).
 * A second jupyter-enabled service in the same environment reuses the first
 * Jupyter container by attaching volumes instead of injecting another one.
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
     * Reuses another Jupyter in the same environment when this service has none.
     */
    public static function sessionUrl(Service $service): ?string
    {
        if (! $service->jupyter_enabled || ! auth()->user()?->can('view', $service)) {
            return null;
        }

        $host = $service;
        $jupyter = $service->applications()->get()->firstWhere('name', self::SERVICE_NAME);
        if (! filled($jupyter?->fqdn)) {
            $existing = self::existingJupyterService($service);
            if ($existing === null) {
                return null;
            }
            $host = $existing;
            $jupyter = $existing->applications()->get()->firstWhere('name', self::SERVICE_NAME);
            if (! filled($jupyter?->fqdn)) {
                return null;
            }
        }

        $token = $host->environment_variables()->where('key', 'SERVICE_PASSWORD_JUPYTER')->first()?->value;
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

    /**
     * Whether this service should get a jupyter sidecar injected into its own compose.
     * False when another service in the environment already hosts Jupyter.
     */
    public static function shouldInjectInto(Service $service): bool
    {
        if (! $service->jupyter_enabled) {
            return false;
        }

        return self::existingJupyterService($service) === null;
    }

    /**
     * First other service in the same environment (same project/client) that already has Jupyter.
     * Never reuses owner Jupyter or a Jupyter from another project.
     */
    public static function existingJupyterService(Service $service): ?Service
    {
        $service->loadMissing('environment.project', 'environment.services');
        $environment = $service->environment;
        if (! $environment instanceof Environment || $environment->project_id === null) {
            return null;
        }

        foreach ($environment->services as $other) {
            if ((int) $other->id === (int) $service->id) {
                continue;
            }
            if ((int) ($other->environment?->project_id ?? $environment->project_id) !== (int) $environment->project_id) {
                continue;
            }
            if (self::composeHasJupyter((string) $other->docker_compose_raw)) {
                return $other;
            }
            $other->loadMissing('applications');
            if ($other->applications->firstWhere('name', self::SERVICE_NAME) !== null) {
                return $other;
            }
        }

        return null;
    }

    public static function composeHasJupyter(string $compose): bool
    {
        $yaml = self::parse($compose);

        return is_array($yaml) && isset($yaml['services'][self::SERVICE_NAME]) && is_array($yaml['services'][self::SERVICE_NAME]);
    }

    /**
     * Attach shareable volumes from $guest onto the environment's existing Jupyter host.
     * Restarts the host when mounts change so files become visible.
     */
    public static function attachShareableVolumesFrom(Service $guest): void
    {
        if (! $guest->jupyter_enabled) {
            return;
        }

        $host = self::existingJupyterService($guest);
        if ($host === null || self::composeHasJupyter((string) $guest->docker_compose_raw)) {
            return;
        }

        $guestYaml = self::parse((string) $guest->docker_compose_raw);
        if (! is_array($guestYaml)) {
            return;
        }

        $sources = self::shareableVolumeSources($guestYaml['services'] ?? []);
        if ($sources === []) {
            return;
        }

        $hostCompose = (string) $host->docker_compose_raw;
        if (! self::composeHasJupyter($hostCompose)) {
            $hostCompose = self::inject($hostCompose);
        }

        $hostYaml = self::parse($hostCompose);
        if (! is_array($hostYaml) || ! isset($hostYaml['services'][self::SERVICE_NAME]) || ! is_array($hostYaml['services'][self::SERVICE_NAME])) {
            return;
        }

        $jupyter = $hostYaml['services'][self::SERVICE_NAME];
        $allowed = self::environmentShareableSources($host->environment ?? $guest->environment);
        foreach ($sources as $source) {
            $allowed[$source] = true;
        }

        $mounts = is_array($jupyter['volumes'] ?? null) ? $jupyter['volumes'] : [];
        $kept = [];
        $existingSources = [];
        foreach ($mounts as $volume) {
            $parsed = self::parseVolume($volume);
            if ($parsed === null || ! isset($allowed[$parsed['source']])) {
                continue;
            }
            $kept[] = $volume;
            $existingSources[$parsed['source']] = true;
        }

        $changed = count($kept) !== count($mounts);
        foreach (array_keys($allowed) as $source) {
            if (isset($existingSources[$source])) {
                continue;
            }
            $kept[] = $source.':'.self::workspaceMountTarget($source, $kept === []);
            $existingSources[$source] = true;
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        $jupyter['volumes'] = array_values($kept);
        $hostYaml['services'][self::SERVICE_NAME] = $jupyter;
        $host->docker_compose_raw = Yaml::dump($hostYaml, 8, 2);
        $host->save();
        OdooGit::startIfPossible($host->fresh() ?? $host);
    }

    /**
     * Named volumes belonging to services in this environment only (one client branch).
     *
     * @return array<string, true>
     */
    public static function environmentShareableSources(?Environment $environment): array
    {
        if (! $environment instanceof Environment) {
            return [];
        }

        $environment->loadMissing('services');
        $allowed = [];
        foreach ($environment->services as $service) {
            $yaml = self::parse((string) $service->docker_compose_raw);
            if (! is_array($yaml)) {
                continue;
            }
            foreach (self::shareableVolumeSources($yaml['services'] ?? []) as $source) {
                $allowed[$source] = true;
            }
        }

        return $allowed;
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

        $sources = self::shareableVolumeSources($services);
        if ($sources === []) {
            return $compose;
        }

        $services[self::SERVICE_NAME] = self::serviceDefinitionWithVolumes($sources);
        $yaml['services'] = $services;

        return Yaml::dump($yaml, 8, 2);
    }

    public static function injectOwner(string $compose): string
    {
        return $compose;
    }

    /**
     * One Jupyter for the instance owner, outside every client stack.
     * Each client team and environment gets custom_addons, addons_odoo (image addons), and files.
     *
     * @param  list<array{team: string, project?: string, environment: string, custom: ?string, files: ?string, image: string, custom_bind?: ?string}>  $instances
     */
    public static function ownerCompose(array $instances, string $token, string $host, string $network = 'coolify'): string
    {
        if (preg_match('/\A[A-Za-z0-9]{16,}\z/', $token) !== 1 || preg_match('/\A[A-Za-z0-9.-]+\z/', $host) !== 1 || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,100}\z/', $network) !== 1) {
            throw new \RuntimeException('The owner Jupyter was refused.');
        }

        // House modules stay next to team folders; /workspace/owner is Root Team's project tree.
        $mounts = ['/data/coolify/gpsh-owner-modules:/workspace/owner-modules:ro'];
        $stdlib = [];
        $external = [];
        $used = [];
        foreach ($instances as $instance) {
            $folder = self::ownerWorkspaceFolder(
                (string) ($instance['team'] ?? ''),
                (string) ($instance['project'] ?? ''),
                (string) ($instance['environment'] ?? ''),
            );
            if ($folder === null) {
                continue;
            }
            $suffix = 2;
            $unique = $folder;
            while (isset($used[$unique])) {
                $unique = $folder.'-'.$suffix;
                $suffix++;
            }
            $used[$unique] = true;
            $root = '/workspace/'.$unique;
            $bind = (string) ($instance['custom_bind'] ?? '');
            if (preg_match('#\A/data/coolify/gpsh-owner-jupyter/clients/(?:[a-z0-9-]+/){1,4}custom\z#', $bind) === 1) {
                $mounts[] = $bind.':'.$root.'/custom_addons:ro';
            } else {
                $volume = (string) ($instance['custom'] ?? '');
                if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,200}\z/', $volume) === 1) {
                    $mounts[] = $volume.':'.$root.'/custom_addons:ro';
                    $external[$volume] = ['name' => $volume, 'external' => true];
                }
            }
            $files = (string) ($instance['files'] ?? '');
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,200}\z/', $files) === 1) {
                $mounts[] = $files.':'.$root.'/files:ro';
                $external[$files] = ['name' => $files, 'external' => true];
            }
            $image = (string) ($instance['image'] ?? '');
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/:-]{0,200}\z/', $image) !== 1) {
                continue;
            }
            $volume = self::stdlibVolumeName($image);
            $stdlib[$volume] = $image;
            $mounts[] = $volume.':'.$root.'/addons_odoo:ro';
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
            $proxyInspect = 'coolify-proxy';
            if ($server->usesExternalProxyNetworkConnect()) {
                $filter = escapeshellarg((string) $server->externalProxyName());
                $proxyInspect = "\$(docker ps --filter name={$filter} --format '{{.Names}}' | head -1)";
            }
            $listed = instant_remote_process([
                "PROXY={$proxyInspect}; [ -n \"\$PROXY\" ] && docker inspect \"\$PROXY\" --format '{{range \$k, \$v := .NetworkSettings.Networks}}{{\$k}} {{end}}'",
            ], $server, false);
            $network = self::proxyNetworkFrom((string) $listed);
        }
        $instances = self::ownerInstances();
        if (! app()->runningUnitTests()) {
            $listed = instant_remote_process(['docker volume ls -q'], $server, false);
            $present = array_values(array_filter(preg_split('/\R/', trim((string) $listed)) ?: []));
            if ($present !== []) {
                $instances = self::prepareOwnerInstances($instances, $present);
                foreach ($instances as $index => $instance) {
                    if (! is_string($instance['import_volume'] ?? null) || ! is_string($instance['custom_bind'] ?? null)) {
                        continue;
                    }
                    if (! self::importRemoteCustom($instance)) {
                        $instances[$index]['custom_bind'] = null;
                    }
                }
            }
        }
        $compose = self::ownerCompose($instances, $token, $host, $network);
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
            $proxyResolve = 'PROXY=coolify-proxy';
            if ($server->usesExternalProxyNetworkConnect()) {
                $proxyResolve = 'PROXY=$(docker ps --filter name='.escapeshellarg((string) $server->externalProxyName())." --format '{{.Names}}' | head -1)";
            }
            $output = instant_remote_process([<<<BASH
set -eu
before=missing
docker inspect gpsh-owner-jupyter >/dev/null 2>&1 && before=present
dir=/data/coolify/gpsh-owner-jupyter
mkdir -p "\$dir"
cat > "\$dir/docker-compose.yml.new" <<'EOF'
{$compose}
EOF
changed=0
if [ ! -f "\$dir/docker-compose.yml" ] || ! cmp -s "\$dir/docker-compose.yml" "\$dir/docker-compose.yml.new"; then
  changed=1
fi
mv "\$dir/docker-compose.yml.new" "\$dir/docker-compose.yml"
running=false
if docker inspect -f '{{.State.Running}}' gpsh-owner-jupyter 2>/dev/null | grep -qx true; then
  running=true
fi
if [ "\$changed" = 1 ] || [ "\$running" != true ]; then
  docker compose -f "\$dir/docker-compose.yml" --project-name gpsh-owner-jupyter up -d
  if docker network inspect {$quotedNetwork} >/dev/null 2>&1; then
    docker inspect gpsh-owner-jupyter --format '{{range \$k, \$v := .NetworkSettings.Networks}}{{\$k}} {{end}}' | grep -qw {$quotedNetwork} || docker network connect {$quotedNetwork} gpsh-owner-jupyter || true
  fi
  running=false
  if docker inspect -f '{{.State.Running}}' gpsh-owner-jupyter 2>/dev/null | grep -qx true; then
    running=true
  fi
fi
cert=pending
{$proxyResolve}
if [ -n "\$PROXY" ] && docker exec "\$PROXY" grep -F -e {$needle} -e {$needleSpaced} /traefik/acme.json >/dev/null 2>&1; then
  cert=applied
fi
if [ "\$cert" = pending ]; then
  curl -fsS -o /dev/null -k --connect-timeout 5 --max-time 15 --resolve {$quotedHost}:443:127.0.0.1 https://{$quotedHost}/ || true
fi
ready=000
i=0
while [ "\$i" -lt 12 ]; do
  ready=\$(curl -sk -o /dev/null -w '%{http_code}' --connect-timeout 2 --max-time 5 --resolve {$quotedHost}:443:127.0.0.1 https://{$quotedHost}/ || true)
  case "\$ready" in
    502|503|000|"") sleep 2 ;;
    *) break ;;
  esac
  i=\$((i + 1))
done
echo "gpsh-owner-status before=\$before running=\$running cert=\$cert ready=\$ready"
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
     * @return list<array{team: string, project: string, environment: string, custom: ?string, files: ?string, image: string, server_id: ?string, custom_fallback: ?string, files_fallback: ?string}>
     */
    public static function ownerInstances(): array
    {
        $rows = [];
        foreach (Service::query()->with(['environment.project.team', 'applications.persistentStorages', 'destination'])->get() as $service) {
            if (! $service->supportsOdooJupyter()) {
                continue;
            }
            $environment = $service->environment;
            $project = $environment?->project;
            $team = $project?->team;
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
            $serverId = $service->server_id;
            if (($serverId === null || $serverId === '') && $service->destination !== null) {
                $serverId = $service->destination->server_id ?? null;
            }
            $rows[] = [
                'team' => (string) $team->name,
                'project' => (string) ($project->name ?? ''),
                'environment' => (string) $environment->name,
                'custom' => $custom !== '' ? $custom : null,
                'files' => $files !== '' ? $files : null,
                'image' => $image !== '' ? $image : 'odoo:20',
                'server_id' => ($serverId === null || $serverId === '') ? null : (string) $serverId,
                'custom_fallback' => self::volumeName(OdooAddons::extraAddonsVolume($service)),
                'files_fallback' => self::volumeName(OdooAddons::filestoreVolume($service)),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return [$a['team'], $a['project'], $a['environment']] <=> [$b['team'], $b['project'], $b['environment']];
        });

        return $rows;
    }

    /**
     * /workspace/{team}/{environment} — team, then branch (production / staging-N).
     * Minimum tree: Cliente 1 > production > custom_addons / addons_odoo.
     * Root Team is the instance owner: folder name is "owner" so it sits next to client teams.
     * $project is ignored in the path (kept for call-site compatibility).
     */
    public static function ownerWorkspaceFolder(string $team, string $project, string $environment): ?string
    {
        $team = Str::slug($team);
        $environment = Str::slug($environment);
        if ($environment === '') {
            return null;
        }

        if ($team === '' || $team === 'root-team') {
            $team = 'owner';
        }

        return $team.'/'.$environment;
    }

    /**
     * A volume on this machine is mounted directly. A volume on another server is copied into custom_bind.
     * The image addons stay in the compose either way.
     *
     * @param  list<array<string, mixed>>  $instances
     * @param  list<string>  $present
     * @return list<array<string, mixed>>
     */
    public static function prepareOwnerInstances(array $instances, array $present): array
    {
        $present = array_fill_keys($present, true);
        foreach ($instances as $index => $instance) {
            $custom = self::presentVolume($instance, 'custom', $present);
            $instances[$index]['custom'] = $custom;
            $instances[$index]['files'] = self::presentVolume($instance, 'files', $present);
            $instances[$index]['custom_bind'] = null;
            $instances[$index]['import_volume'] = null;
            if ($custom !== null || (string) ($instance['server_id'] ?? '') === '' || (string) $instance['server_id'] === '0') {
                continue;
            }
            $remote = self::volumeName((string) ($instance['custom'] ?? ''))
                ?? self::volumeName((string) ($instance['custom_fallback'] ?? ''));
            $bind = self::customBind(
                (string) ($instance['team'] ?? ''),
                (string) ($instance['project'] ?? ''),
                (string) ($instance['environment'] ?? ''),
            );
            if ($remote === null || $bind === null) {
                continue;
            }
            $instances[$index]['import_volume'] = $remote;
            $instances[$index]['custom_bind'] = $bind;
        }

        return $instances;
    }

    public static function customBind(string $team, string $project, string $environment = ''): ?string
    {
        // Backward compatible: old callers passed (team, environment).
        if ($environment === '' && $project !== '') {
            $environment = $project;
            $project = '';
        }
        $folder = self::ownerWorkspaceFolder($team, $project, $environment);
        if ($folder === null) {
            return null;
        }

        return '/data/coolify/gpsh-owner-jupyter/clients/'.$folder.'/custom';
    }

    /**
     * ponytail: one snapshot of the remote extra-addons at open, capped at 8MB of base64 (~6MB). A bigger tree stays on that server.
     *
     * @param  array<string, mixed>  $instance
     */
    private static function importRemoteCustom(array $instance): bool
    {
        $volume = self::volumeName((string) ($instance['import_volume'] ?? ''));
        $image = (string) ($instance['image'] ?? '');
        $dir = (string) ($instance['custom_bind'] ?? '');
        if ($volume === null || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/:-]{0,200}\z/', $image) !== 1 || preg_match('#\A/data/coolify/gpsh-owner-jupyter/clients/(?:[a-z0-9-]+/){1,4}custom\z#', $dir) !== 1) {
            return false;
        }
        $remote = Server::query()->find($instance['server_id'] ?? null);
        $local = Server::query()->find(0);
        if (! $remote instanceof Server || ! $local instanceof Server || ! $remote->isFunctional() || ! $local->isFunctional()) {
            return false;
        }
        $encoded = instant_remote_process([
            'docker run --rm --pull never --user 0:0 --entrypoint sh -v '.escapeshellarg($volume).':/src:ro '.escapeshellarg($image).' -c '.escapeshellarg('tar -c -C /src . | base64 -w 0'),
        ], $remote, false);
        if (! is_string($encoded)) {
            return false;
        }
        $encoded = preg_replace('/[^A-Za-z0-9+\/=]/', '', $encoded) ?? '';
        if ($encoded === '' || strlen($encoded) > 8000000) {
            return false;
        }
        instant_remote_process([<<<BASH
set -eu
dir={$dir}
mkdir -p "\$dir"
find "\$dir" -mindepth 1 -delete
base64 -d <<'END' | tar -x -C "\$dir" --no-absolute-names
{$encoded}
END
BASH], $local, false);

        return true;
    }

    /**
     * @param  array<string, mixed>  $instance
     * @param  array<string, true>  $present
     */
    private static function presentVolume(array $instance, string $key, array $present): ?string
    {
        foreach ([$key, $key.'_fallback'] as $name) {
            $volume = self::volumeName((string) ($instance[$name] ?? ''));
            if ($volume !== null && isset($present[$volume])) {
                return $volume;
            }
        }

        return null;
    }

    private static function volumeName(string $name): ?string
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,200}\z/', $name) === 1 ? $name : null;
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
     * Platform logo as a data URI so the public waiting page (on the Odoo host) can show it.
     */
    public static function waitingLogoDataUri(): ?string
    {
        $candidates = array_values(array_unique(array_filter([
            product_logo_path(),
            'brand/getodoo-isologo.png',
            'brand/getodoo-mark.png',
            'brand/favicon.png',
        ])));
        foreach ($candidates as $relative) {
            $file = public_path($relative);
            if (! is_file($file) || ! is_readable($file)) {
                continue;
            }
            $size = filesize($file);
            // Keep the launch command small: the page is base64-wrapped again for Compose.
            if ($size === false || $size < 1 || $size > 180_000) {
                continue;
            }
            $bytes = file_get_contents($file);
            if (! is_string($bytes) || $bytes === '') {
                continue;
            }
            $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'svg' => 'image/svg+xml',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'gif' => 'image/gif',
                default => 'image/png',
            };

            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        }

        return null;
    }

    /**
     * Public waiting page while Odoo mounts (same atmosphere as plan signup).
     *
     * @param  list<string>  $tips
     */
    public static function waitingPageMarkup(array $tips = []): string
    {
        $tips = array_values(array_map(
            fn (string $tip): string => (string) __($tip),
            OdooComposeTemplate::normalizeWaitingTips($tips),
        ));
        $product = htmlspecialchars(product_name(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tipLabel = htmlspecialchars((string) __('Tip'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $readyText = (string) __('Everything is ready.');
        $readySub = (string) __('Opening Odoo…');
        $tipsJson = json_encode($tips, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        if (! is_string($tipsJson)) {
            $tipsJson = '[]';
        }
        $logoUri = self::waitingLogoDataUri();
        $logoHtml = $logoUri !== null
            ? '<img class="logo" src="'.htmlspecialchars($logoUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="'.$product.'">'
            : '<p class="brand">'.$product.'</p>';
        $tipBlock = $tips === []
            ? ''
            : '<div class="tip-wrap" id="gpsh-tip-wrap"><p class="tip-label">'.$tipLabel.'</p><p class="tip" id="gpsh-tip"></p></div>';
        $script = '<script>(function(){'
            .'var tips='.$tipsJson.';'
            .'var readyText='.json_encode($readyText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS).';'
            .'var readySub='.json_encode($readySub, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS).';'
            .'var tipEl=document.getElementById("gpsh-tip");'
            .'var tipWrap=document.getElementById("gpsh-tip-wrap");'
            .'var msgEl=document.getElementById("gpsh-msg");'
            .'var subEl=document.getElementById("gpsh-sub");'
            .'var shell=document.getElementById("gpsh-shell");'
            .'var tipTimer=null;'
            .'var done=false;'
            .'var reduce=window.matchMedia&&window.matchMedia("(prefers-reduced-motion: reduce)").matches;'
            .'var fadeMs=reduce?0:750;'
            .'var holdMs=reduce?5000:9000;'
            .'var i=0;'
            .'function showTip(){if(!tipEl||!tips.length||done)return;tipEl.classList.remove("is-on");'
            .'window.setTimeout(function(){if(done)return;tipEl.textContent=tips[i%tips.length];i++;'
            .'window.requestAnimationFrame(function(){tipEl.classList.add("is-on");});},fadeMs);}'
            .'function startTips(){if(!tipEl||!tips.length)return;showTip();if(tips.length>1){tipTimer=window.setInterval(showTip,holdMs);}}'
            .'function finish(){if(done)return;done=true;if(tipTimer){window.clearInterval(tipTimer);}if(tipWrap){tipWrap.classList.add("is-gone");}'
            .'if(msgEl){msgEl.classList.add("is-swap");window.setTimeout(function(){msgEl.textContent=readyText;msgEl.classList.remove("is-swap");msgEl.classList.add("is-ready");},fadeMs);}'
            .'if(subEl){subEl.classList.add("is-swap");window.setTimeout(function(){subEl.textContent=readySub;subEl.classList.remove("is-swap");},fadeMs);}'
            .'if(shell){shell.setAttribute("data-gpsh-ready","1");}'
            .'window.setTimeout(function(){location.reload();},reduce?900:2600);}'
            .'function poll(){if(done)return;fetch(location.href,{cache:"no-store",credentials:"same-origin",headers:{"Accept":"text/html"}})'
            .'.then(function(r){return r.text();}).then(function(t){if(done)return;if(t.indexOf("data-gpsh-waiting")===-1){finish();}}).catch(function(){});}'
            .'startTips();window.setInterval(poll,3500);window.setTimeout(poll,1200);'
            .'})();</script>';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><noscript><meta http-equiv="refresh" content="20"></noscript><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$product.'</title><style>'
            .'html,body{margin:0;min-height:100%;background:#07080e;color:#f6f7fb;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif}'
            .'.shell{position:relative;isolation:isolate;display:flex;min-height:100dvh;align-items:center;justify-content:center;overflow:hidden;'
            .'background:radial-gradient(ellipse 48% 40% at 28% 44%,rgba(108,92,231,.42),transparent 70%),radial-gradient(ellipse 34% 28% at 78% 76%,rgba(40,180,120,.22),transparent 70%),linear-gradient(180deg,#140c28 0%,#07080e 100%)}'
            .'.stage{position:absolute;inset:0;pointer-events:none;overflow:hidden}'
            .'.glow,.orbit,.shard,.mark,.ridge{animation-timing-function:ease-in-out;animation-iteration-count:infinite}'
            .'.glow{position:absolute;width:28rem;height:28rem;border-radius:50%;background:radial-gradient(circle,rgba(108,92,231,.55),transparent 68%);filter:blur(8px);left:8%;top:8%;animation:auth-glow 14s infinite}'
            .'.glow-b{left:auto;right:6%;top:auto;bottom:4%;width:22rem;height:22rem;background:radial-gradient(circle,rgba(40,180,120,.35),transparent 68%);animation-duration:18s;animation-delay:-6s}'
            .'.orbit{position:absolute;left:50%;top:42%;width:min(440px,70vw);height:150px;margin-left:calc(min(440px,70vw)/-2);margin-top:-75px;border:1px solid rgba(168,148,255,.32);border-radius:50%;animation:auth-spin 22s infinite}'
            .'.orbit-b{width:min(300px,48vw);height:110px;margin-left:calc(min(300px,48vw)/-2);margin-top:-55px;opacity:.7;animation-duration:28s;animation-direction:reverse}'
            .'.mark-slot{position:absolute;left:50%;top:42%;transform:translate(-50%,-50%);opacity:.22}'
            .'.mark{display:block;width:min(160px,36vw);filter:drop-shadow(0 24px 40px rgba(40,20,120,.35));animation:auth-float 9s infinite}'
            .'.flare{position:absolute;left:12%;right:12%;top:18%;height:42%;background:radial-gradient(ellipse at center,rgba(255,255,255,.55),transparent 68%);filter:blur(10px);animation:auth-flare 8s ease-in-out infinite}'
            .'.shard{position:absolute;background:linear-gradient(160deg,rgba(255,255,255,.85),rgba(198,188,255,.22));box-shadow:inset 0 0 0 1px rgba(255,255,255,.55),0 12px 24px rgba(40,20,90,.28);clip-path:polygon(50% 0,100% 25%,100% 75%,50% 100%,0 75%,0 25%);animation:auth-drift 15s infinite}'
            .'.s-a{top:14%;left:12%;width:34px;height:40px}.s-b{top:64%;left:16%;width:26px;height:30px;opacity:.8;animation-delay:-3s}'
            .'.s-c{top:18%;right:12%;width:48px;height:56px;animation-delay:-5s}.s-d{top:60%;right:18%;width:20px;height:24px;opacity:.75;animation-delay:-8s}'
            .'.ridge{position:absolute;bottom:-8px;height:18%;background:#1a2140;border-radius:60% 40% 0 0;animation:auth-ridge 16s infinite}'
            .'.ridge-a{left:-4%;width:46%}.ridge-b{right:-6%;width:38%;height:14%;background:#14192e;animation-direction:reverse}'
            .'.card{position:relative;z-index:1;width:min(100% - 2rem,28rem);padding:2rem 1.5rem 1.75rem;text-align:center}'
            .'.logo{display:block;width:min(240px,78%);height:auto;margin:0 auto 1.25rem;object-fit:contain;filter:drop-shadow(0 10px 24px rgba(20,10,60,.4))}'
            .'.brand{margin:0 0 .85rem;font-size:.75rem;letter-spacing:.18em;text-transform:uppercase;opacity:.55}'
            .'.msg{margin:0;font-size:clamp(1.35rem,4vw,1.75rem);line-height:1.35;font-weight:600;transition:opacity .75s ease,transform .75s ease}'
            .'.msg.is-swap,.sub.is-swap{opacity:0;transform:translateY(8px)}'
            .'.msg.is-ready{color:#b8f0d0}'
            .'.sub{margin:.85rem 0 0;opacity:.65;font-size:.95rem;transition:opacity .75s ease,transform .75s ease}'
            .'.tip-wrap{margin-top:1.75rem;min-height:4.75rem;padding:1rem 1.1rem;border:1px solid rgba(255,255,255,.1);border-radius:1rem;background:rgba(255,255,255,.04);backdrop-filter:blur(10px);text-align:left;transition:opacity .75s ease,transform .75s ease}'
            .'.tip-wrap.is-gone{opacity:0;transform:translateY(10px);pointer-events:none}'
            .'.tip-label{margin:0 0 .35rem;font-size:.68rem;letter-spacing:.16em;text-transform:uppercase;opacity:.5}'
            .'.tip{margin:0;font-size:.98rem;line-height:1.45;opacity:0;transform:translateY(8px);transition:opacity .75s ease,transform .75s ease}'
            .'.tip.is-on{opacity:.92;transform:translateY(0)}'
            .'@keyframes auth-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}'
            .'@keyframes auth-drift{0%,100%{transform:translate3d(0,0,0) rotate(0)}50%{transform:translate3d(8px,-12px,0) rotate(8deg)}}'
            .'@keyframes auth-spin{0%,100%{transform:rotate(-18deg)}50%{transform:rotate(12deg)}}'
            .'@keyframes auth-glow{0%,100%{transform:translate3d(0,0,0) scale(1);opacity:.8}50%{transform:translate3d(16px,12px,0) scale(1.06);opacity:1}}'
            .'@keyframes auth-ridge{0%,100%{transform:translateX(0)}50%{transform:translateX(10px)}}'
            .'@keyframes auth-flare{0%,100%{opacity:.4}50%{opacity:.9}}'
            .'@media (prefers-reduced-motion:reduce){.glow,.orbit,.shard,.mark,.flare,.ridge{animation:none!important}.tip,.msg,.sub,.tip-wrap{transition:none!important}}'
            .'</style></head><body><div class="shell" id="gpsh-shell" data-gpsh-waiting="1"><div class="stage" aria-hidden="true">'
            .'<span class="glow"></span><span class="glow glow-b"></span>'
            .'<span class="orbit"></span><span class="orbit orbit-b"></span>'
            .'<span class="shard s-a"></span><span class="shard s-b"></span><span class="shard s-c"></span><span class="shard s-d"></span>'
            .'<div class="mark-slot"><span class="flare"></span>'
            .'<svg class="mark" viewBox="0 0 200 200"><defs>'
            .'<linearGradient id="g" x1="28" y1="8" x2="172" y2="188" gradientUnits="userSpaceOnUse">'
            .'<stop offset="0" stop-color="#ffffff" stop-opacity="0.94"/><stop offset="0.32" stop-color="#efeaff" stop-opacity="0.5"/>'
            .'<stop offset="0.68" stop-color="#8ea0ff" stop-opacity="0.26"/><stop offset="1" stop-color="#241a4a" stop-opacity="0.18"/></linearGradient>'
            .'<linearGradient id="r" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffffff" stop-opacity="0.95"/>'
            .'<stop offset="0.5" stop-color="#c9bdff" stop-opacity="0.4"/><stop offset="1" stop-color="#ffffff" stop-opacity="0.12"/></linearGradient>'
            .'<filter id="b" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="8"/></filter></defs>'
            .'<polygon points="100,18 166,56 166,130 100,168 34,130 34,56" fill="#b7a6ff" opacity="0.45" filter="url(#b)"/>'
            .'<polygon points="100,16 168,54 168,132 100,170 32,132 32,54" fill="url(#g)" stroke="url(#r)" stroke-width="2.4"/>'
            .'<polygon points="100,16 168,54 100,92 32,54" fill="rgb(255 255 255 / 0.28)"/>'
            .'<polygon points="100,48 134,67 134,110 100,130 66,110 66,67" fill="rgb(255 255 255 / 0.08)" stroke="rgb(255 255 255 / 0.62)" stroke-width="1.5"/>'
            .'<path d="M78 112 L100 70 L122 112 L109 112 L100 94 L91 112 Z" fill="#ffffff"/></svg></div>'
            .'<span class="ridge ridge-a"></span><span class="ridge ridge-b"></span></div>'
            .'<div class="card">'.$logoHtml
            .'<p class="msg" id="gpsh-msg">__GPSH_WAITING_MESSAGE__</p>'
            .'<p class="sub" id="gpsh-sub">Esta página se actualiza sola.</p>'
            .$tipBlock
            .'</div></div>'.$script.'</body></html>';
    }

    /**
     * @param  list<string>  $modules
     * @param  list<string>|null  $tips
     */
    public static function launchCommand(string $database, string $url = '', string $token = '', string $password = '', array $modules = [], int $workers = 0, ?array $tips = null): string
    {
        $database = preg_replace('/[^a-z0-9_]/', '', $database) ?? '';
        $url = preg_match('#^https://[A-Za-z0-9.-]+$#', $url) === 1 ? $url : '';
        $token = preg_replace('/[^A-Za-z0-9]/', '', $token) ?? '';
        $password = preg_replace('/[^A-Za-z0-9]/', '', $password) ?? '';
        $modules = array_values(array_unique(array_filter(
            $modules,
            fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1,
        )));
        $workers = max(0, min(32, $workers));
        $tips = OdooComposeTemplate::normalizeWaitingTips(
            $tips ?? OdooComposeTemplate::defaultWaitingTips(),
        );
        $waitingPageB64 = base64_encode(self::waitingPageMarkup($tips));
        // ponytail: Compose interpolates $ in the command. $$ is the only escape; a bare $( fails the deploy.
        // The database, URL, token and password are literals so they match the GPSH link even when the container env is empty.

        return str_replace('$', '$$', str_replace(
            ['__ODOO_DB__', '__ODOO_URL__', '__ODOO_TOKEN__', '__ODOO_PASSWORD__', '__OWNER_KEEP__', '__OWNER_LIST__', '__ODOO_WORKERS__', '__WAITING_PAGE_B64__'],
            [$database, $url, $token, $password, ' '.implode(' ', $modules).' ', implode(' ', $modules), (string) $workers, $waitingPageB64],
            <<<'BASH'
python3 - <<'PY' || true
import base64, html, time
from pathlib import Path
Path("/tmp/gpsh-waiting.tpl").write_text(base64.b64decode("__WAITING_PAGE_B64__").decode("utf-8"))
def page(text):
    tpl = Path("/tmp/gpsh-waiting.tpl").read_text()
    Path("/tmp/gpsh-status.html").write_text(tpl.replace("__GPSH_WAITING_MESSAGE__", html.escape(text)))
    Path("/tmp/gpsh-page-at").write_text(str(time.time()))
page("Estamos preparando todo.")
PY
cat > /tmp/gpsh-page.py << 'ENDPAGE'
import html, sys, time
from pathlib import Path
text = sys.argv[1] if len(sys.argv) > 1 else "Estamos preparando todo."
html_path = Path("/tmp/gpsh-status.html")
stamp = Path("/tmp/gpsh-page-at")
pending = Path("/tmp/gpsh-page-next")
tpl = Path("/tmp/gpsh-waiting.tpl")
now = time.time()
last = 0.0
if stamp.exists():
    try:
        last = float(stamp.read_text() or "0")
    except Exception:
        last = 0.0
def document(message):
    body = tpl.read_text() if tpl.exists() else "<!doctype html><title>GPSH</title><body>__GPSH_WAITING_MESSAGE__</body>"
    return body.replace("__GPSH_WAITING_MESSAGE__", html.escape(message))
if html_path.exists() and now - last < 20:
    pending.write_text(text)
else:
    html_path.write_text(document(text))
    stamp.write_text(str(now))
    if pending.exists():
        pending.unlink()
ENDPAGE
cat > /tmp/gpsh-status.py << 'ENDSTATUS'
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import html
import http.client
import os
import select
import socket
import time

class H(BaseHTTPRequestHandler):
    def do_GET(self):
        self.forward()
    def do_POST(self):
        self.forward()
    def do_HEAD(self):
        self.forward()
    def do_OPTIONS(self):
        self.forward()
    def do_PUT(self):
        self.forward()
    def do_PATCH(self):
        self.forward()
    def do_DELETE(self):
        self.forward()
    def forward(self):
        if (self.headers.get("Upgrade") or "").lower() == "websocket":
            self.tunnel()
            return
        try:
            length = int(self.headers.get("Content-Length") or 0)
            payload = self.rfile.read(length) if length else None
            headers = {k: v for k, v in self.headers.items() if k.lower() not in ("host", "content-length")}
            conn = http.client.HTTPConnection("127.0.0.1", 8071, timeout=60)
            conn.request(self.command, self.path, body=payload, headers=headers)
            resp = conn.getresponse()
            data = resp.read()
            self.send_response(resp.status)
            for key, value in resp.getheaders():
                if key.lower() not in ("transfer-encoding", "connection", "content-length"):
                    self.send_header(key, value)
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(data)
            conn.close()
        except Exception:
            self.waiting()
    def tunnel(self):
        upstream = None
        try:
            upstream = socket.create_connection(("127.0.0.1", 8072 if "__ODOO_WORKERS__" not in ("", "0") else 8071), timeout=10)
            upstream.settimeout(None)
            request = "%s %s %s\r\n" % (self.command, self.path, self.request_version)
            for key, value in self.headers.items():
                request += "%s: %s\r\n" % (key, value)
            request += "\r\n"
            upstream.sendall(request.encode("latin1", "replace"))
            client = self.connection
            client.settimeout(None)
            pair = [client, upstream]
            while True:
                ready, _, _ = select.select(pair, [], [], 120)
                if not ready:
                    continue
                for sock in ready:
                    data = sock.recv(65536)
                    if not data:
                        upstream.close()
                        return
                    (upstream if sock is client else client).sendall(data)
        except Exception:
            if upstream is not None:
                upstream.close()
            self.waiting()
    def waiting(self):
        self.promote()
        try:
            page = open("/tmp/gpsh-status.html", "rb").read()
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Cache-Control", "no-store")
            self.send_header("Content-Length", str(len(page)))
            self.end_headers()
            if self.command != "HEAD":
                self.wfile.write(page)
        except Exception:
            return
    def promote(self):
        try:
            pending = open("/tmp/gpsh-page-next", "r", encoding="utf-8").read().strip()
            last = float(open("/tmp/gpsh-page-at", "r", encoding="utf-8").read() or "0")
        except Exception:
            return
        if pending == "" or time.time() - last < 20:
            return
        try:
            tpl = open("/tmp/gpsh-waiting.tpl", "r", encoding="utf-8").read()
        except Exception:
            tpl = "<!doctype html><title>GPSH</title><body>__GPSH_WAITING_MESSAGE__</body>"
        page = tpl.replace("__GPSH_WAITING_MESSAGE__", html.escape(pending))
        open("/tmp/gpsh-status.html", "w", encoding="utf-8").write(page)
        open("/tmp/gpsh-page-at", "w", encoding="utf-8").write(str(time.time()))
        try:
            os.remove("/tmp/gpsh-page-next")
        except Exception:
            return
    def log_message(self, *args):
        return

ThreadingHTTPServer(("0.0.0.0", 8069), H).serve_forever()
ENDSTATUS
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
    (root / "__init__.py").write_text("from . import controllers\nfrom . import models\n")
    (root / "controllers" / "__init__.py").write_text("from . import enter\n")
    (root / "models").mkdir(parents=True, exist_ok=True)
    (root / "models" / "__init__.py").write_text("from . import mail\n")
    (root / "models" / "mail.py").write_text(
        "import logging\n"
        "from odoo import models\n"
        "class GpshMail(models.Model):\n"
        "    _inherit = 'ir.mail_server'\n"
        "    def _find_mail_server(self, email_from, mail_servers=None):\n"
        "        try:\n"
        "            if self._gpsh_own_mail():\n"
        "                if mail_servers is None:\n"
        "                    mail_servers = self.sudo().search([('name', '!=', 'GPSH'), ('active', '=', True)], order='sequence')\n"
        "                else:\n"
        "                    mail_servers = mail_servers.filtered(lambda server: server.name != 'GPSH')\n"
        "            else:\n"
        "                mail_servers = None\n"
        "        except Exception:\n"
        "            pass\n"
        "        return super()._find_mail_server(email_from, mail_servers)\n"
        "    def send_email(self, message, *args, **kwargs):\n"
        "        if self._gpsh_neutral():\n"
        "            self._gpsh_mail_note('gpsh.mail_last', 'blocked')\n"
        "            return False\n"
        "        if not self._gpsh_mail_slot():\n"
        "            self._gpsh_mail_note('gpsh.mail_last', 'limited')\n"
        "            return False\n"
        "        try:\n"
        "            result = super().send_email(message, *args, **kwargs)\n"
        "        except Exception:\n"
        "            self._gpsh_mail_note('gpsh.mail_last', 'failed')\n"
        "            raise\n"
        "        self._gpsh_mail_note('gpsh.mail_last', 'sent')\n"
        "        return result\n"
        "    def _gpsh_own_mail(self):\n"
        "        param = self.env['ir.config_parameter'].sudo()\n"
        "        stored = param.get_param('gpsh.own_mail') or ''\n"
        "        if stored.isdigit():\n"
        "            own = self.sudo().browse(int(stored))\n"
        "            if own.exists() and own.active and own.name != 'GPSH':\n"
        "                self._gpsh_mail_note('gpsh.mail_route', 'own')\n"
        "                return True\n"
        "        own = self.sudo().search([('name', '!=', 'GPSH'), ('active', '=', True)], limit=1)\n"
        "        if not own:\n"
        "            if stored:\n"
        "                param.set_param('gpsh.own_mail', '')\n"
        "                row = self.sudo().search([('name', '=', 'GPSH')], limit=1)\n"
        "                if row and not row.active:\n"
        "                    row.write({'active': True})\n"
        "            self._gpsh_mail_note('gpsh.mail_route', 'gpsh')\n"
        "            return False\n"
        "        param.set_param('gpsh.own_mail', str(own.id))\n"
        "        row = self.sudo().search([('name', '=', 'GPSH')], limit=1)\n"
        "        if row and row.active:\n"
        "            row.write({'active': False})\n"
        "        self._gpsh_mail_note('gpsh.mail_route', 'own')\n"
        "        return True\n"
        "    def _gpsh_neutral(self):\n"
        "        flag = self.env['ir.config_parameter'].sudo().get_param('database.is_neutralized') or ''\n"
        "        return flag in ('true', 'True', '1')\n"
        "    def _gpsh_mail_slot(self):\n"
        "        import json, os, urllib.request\n"
        "        limit = os.environ.get('GPSH_MAIL_LIMIT') or '20'\n"
        "        url = os.environ.get('GPSH_MAIL_URL') or ''\n"
        "        token = os.environ.get('GPSH_MAIL_TOKEN') or ''\n"
        "        team = os.environ.get('GPSH_TEAM_ID') or ''\n"
        "        if url and token and team:\n"
        "            try:\n"
        "                body = json.dumps({'team_id': int(team), 'token': token}).encode()\n"
        "                req = urllib.request.Request(url, data=body, headers={'Content-Type': 'application/json'})\n"
        "                with urllib.request.urlopen(req, timeout=5) as resp:\n"
        "                    payload = json.loads(resp.read().decode() or '{}')\n"
        "                return bool(payload.get('allowed'))\n"
        "            except Exception:\n"
        "                return self._gpsh_mail_local(limit)\n"
        "        return self._gpsh_mail_local(limit)\n"
        "    def _gpsh_mail_local(self, limit_text):\n"
        "        import datetime\n"
        "        try:\n"
        "            limit = int(limit_text)\n"
        "        except Exception:\n"
        "            limit = 20\n"
        "        if limit < 1:\n"
        "            return False\n"
        "        param = self.env['ir.config_parameter'].sudo()\n"
        "        day = datetime.date.today().isoformat()\n"
        "        stored = param.get_param('gpsh.mail_count') or ''\n"
        "        count = 0\n"
        "        if stored.startswith(day + ':'):\n"
        "            try:\n"
        "                count = int(stored.split(':', 1)[1])\n"
        "            except Exception:\n"
        "                count = 0\n"
        "        if count >= limit:\n"
        "            return False\n"
        "        param.set_param('gpsh.mail_count', day + ':' + str(count + 1))\n"
        "        return True\n"
        "    def _gpsh_mail_note(self, key, value):\n"
        "        param = self.env['ir.config_parameter'].sudo()\n"
        "        if param.get_param(key) == value:\n"
        "            return\n"
        "        param.set_param(key, value)\n"
        "        logging.getLogger('odoo.addons.gpsh_autoconnect').info('gpsh mail %s %s', key, value)\n"
    )
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
        "    def _ok(self, token):\n"
        "        given = token or ''\n"
        "        return bool(TOKEN) and len(given) == len(TOKEN) and hmac.compare_digest(given, TOKEN)\n"
        "    def _open(self):\n"
        "        registry = odoo.modules.registry.Registry(DATABASE)\n"
        "        cr = registry.cursor()\n"
        "        return cr, odoo.api.Environment(cr, odoo.SUPERUSER_ID, {})\n"
        "    @http.route('/_odoo/paas/users', type='http', auth='none', csrf=False, sitemap=False)\n"
        "    def users(self, token=None, **kwargs):\n"
        "        import json\n"
        "        if not self._ok(token):\n"
        "            return request.make_response('[]', headers=[('Content-Type', 'application/json')])\n"
        "        cr, env = self._open()\n"
        "        try:\n"
        "            rows = env['res.users'].sudo().search([('share', '=', False), ('active', '=', True)])\n"
        "            payload = [{'name': row.name, 'login': row.login} for row in rows]\n"
        "        finally:\n"
        "            cr.close()\n"
        "        return request.make_response(json.dumps(payload), headers=[('Content-Type', 'application/json')])\n"
        "    @http.route('/_odoo/paas/connect', type='http', auth='none', csrf=False, sitemap=False)\n"
        "    def enter(self, token=None, login=None, **kwargs):\n"
        "        if not self._ok(token):\n"
        "            return request.redirect('/web/login')\n"
        "        wanted = login or 'admin'\n"
        "        cr, env = self._open()\n"
        "        try:\n"
        "            user = env['res.users'].sudo().search([('login', '=', wanted), ('share', '=', False), ('active', '=', True)], limit=1)\n"
        "            if not user:\n"
        "                return request.redirect('/web/login')\n"
        "            request.session.uid = user.id\n"
        "            request.session.login = user.login\n"
        "            request.session.db = DATABASE\n"
        "            request.session.session_token = user._compute_session_token(request.session.sid)\n"
        "        finally:\n"
        "            cr.close()\n"
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
case "__ODOO_WORKERS__" in ''|0) ;; *) args+=(--workers=__ODOO_WORKERS__) ;; esac
for link in /mnt/extra-addons/*; do
  [ -L "$link" ] || continue
  target=$(readlink "$link" || true)
  case "$target" in
    /gpsh-owner-modules/*) rm -f "$link" ;;
  esac
done
image=/usr/lib/python3/dist-packages/odoo/addons
if [ -d "$image" ] && [ -d /gpsh-owner-modules ]; then
  for installed in "$image"/*; do
    [ -d "$installed" ] || continue
    [ -f "$installed/.gpsh-owner" ] || continue
    base=$(basename "$installed")
    if [ -f /gpsh-owner-modules/__manifest__.py ] || [ -f /gpsh-owner-modules/__openerp__.py ]; then
      kept=$(tr -cd 'A-Za-z0-9_' < /gpsh-owner-modules/.gpsh-module-name 2>/dev/null | head -c 64)
      [ "$base" = "$kept" ] || rm -rf "$installed"
    else
      [ -d "/gpsh-owner-modules/$base" ] || rm -rf "$installed"
    fi
  done
  if [ -f /gpsh-owner-modules/__manifest__.py ] || [ -f /gpsh-owner-modules/__openerp__.py ]; then
    base=$(tr -cd 'A-Za-z0-9_' < /gpsh-owner-modules/.gpsh-module-name 2>/dev/null | head -c 64)
    if [ -n "$base" ]; then
      rm -rf "$image/$base"
      mkdir -p "$image/$base"
      for item in /gpsh-owner-modules/* /gpsh-owner-modules/.[!.]*; do
        [ -e "$item" ] || continue
        name=$(basename "$item")
        case "$name" in .git|.gpsh-module-name) continue ;; esac
        cp -a "$item" "$image/$base/" || true
      done
      touch "$image/$base/.gpsh-owner" || true
    fi
  else
    for module in /gpsh-owner-modules/*; do
      [ -d "$module" ] || continue
      base=$(basename "$module")
      case "$base" in ''|*[!A-Za-z0-9_]*) continue ;; esac
      if [ -f "$module/__manifest__.py" ] || [ -f "$module/__openerp__.py" ]; then
        rm -rf "$image/$base"
        cp -a "$module" "$image/$base" || true
        touch "$image/$base/.gpsh-owner" || true
      fi
    done
  fi
fi
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
python3 /tmp/gpsh-page.py "Ya casi está." || true
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
import odoo.tools
odoo.tools.config.parse_config(["-c", "/etc/odoo/odoo.conf", "--db_host", host, "--db_port", port, "--db_user", user, "--db_password", dbpass, "-d", database])
registry = odoo.modules.registry.Registry(database)
with registry.cursor() as cr:
    env = odoo.api.Environment(cr, odoo.SUPERUSER_ID, {})
    if url:
        env["ir.config_parameter"].sudo().set_param("web.base.url", url)
        env["ir.config_parameter"].sudo().set_param("web.base.url.freeze", "True")
    env.ref("base.user_admin").sudo().write({"password": password})
    Server = env["ir.mail_server"].sudo()
    neutralized = (env["ir.config_parameter"].sudo().get_param("database.is_neutralized") or "") in ("true", "True", "1")
    if neutralized:
        Server.search([]).write({"active": False})
    host_smtp = "" if neutralized else (os.environ.get("GPSH_SMTP_HOST") or "")
    fields = Server._fields
    row = Server.browse()
    stored_mail = env["ir.config_parameter"].sudo().get_param("gpsh.own_mail") or ""
    if stored_mail.isdigit():
        host_smtp = ""
    else:
        row = Server.search([("name", "=", "GPSH")], limit=1)
    if not host_smtp:
        if row:
            row.write({"active": False})
    else:
        values = {}
        port_text = os.environ.get("GPSH_SMTP_PORT") or "25"
        encryption = os.environ.get("GPSH_SMTP_ENCRYPTION") or "none"
        if encryption not in ("none", "starttls", "ssl"):
            encryption = "none"
        smtp_user = os.environ.get("GPSH_SMTP_USER") or ""
        sender = os.environ.get("GPSH_SMTP_FROM") or ""
        candidates = {
            "name": "GPSH",
            "smtp_host": host_smtp,
            "smtp_port": int(port_text) if port_text.isdigit() else 25,
            "smtp_encryption": encryption,
            "smtp_user": smtp_user or False,
            "smtp_pass": os.environ.get("GPSH_SMTP_PASSWORD") or False,
            "smtp_authentication": "login" if smtp_user else False,
            "from_filter": sender or False,
            "sequence": 1,
            "active": True,
        }
        for key, value in candidates.items():
            if key in fields:
                values[key] = value
        if row:
            row.write(values)
        else:
            Server.create(values)
        if sender:
            env["ir.config_parameter"].sudo().set_param("mail.default.from", sender)
    cr.commit()
PY
cat > /tmp/gpsh-shell-listen.py << 'ENDSHELL'
import os, pty, pwd, select, signal, socket
signal.signal(signal.SIGCHLD, signal.SIG_IGN)
path = "/mnt/extra-addons,/usr/lib/python3/dist-packages/odoo/addons"
try:
    found = open("/tmp/gpsh-addons-path", encoding="utf-8").read().strip()
    if found:
        path = found
except OSError:
    pass
cmd = ["odoo", "shell", "--no-http", "--max-cron-threads=0", "--no-database-list", "--db_host", os.environ.get("HOST") or "postgresql", "--db_port", os.environ.get("PORT") or "5432", "--db_user", os.environ.get("USER") or "", "--db_password", os.environ.get("PASSWORD") or "", "-d", os.environ.get("ODOO_DATABASE") or "", "--addons-path", path]
def drop():
    try:
        account = pwd.getpwnam("odoo")
    except KeyError:
        return
    os.setgid(account.pw_gid)
    os.setuid(account.pw_uid)
def pump(conn, fd, pid):
    try:
        while True:
            ready, _, _ = select.select([conn, fd], [], [])
            for item in ready:
                if item is conn:
                    data = conn.recv(65536)
                    if not data:
                        return
                    os.write(fd, data)
                else:
                    data = os.read(fd, 65536)
                    if not data:
                        return
                    conn.sendall(data)
    finally:
        conn.close()
        try:
            os.close(fd)
            os.kill(pid, 15)
            os.waitpid(pid, 0)
        except OSError:
            pass
server = socket.socket()
server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind(("0.0.0.0", 8079))
server.listen(8)
while True:
    conn, _ = server.accept()
    pid, fd = pty.fork()
    if pid == 0:
        drop()
        os.execvp(cmd[0], cmd)
        os._exit(1)
    if os.fork() == 0:
        pump(conn, fd, pid)
        os._exit(0)
    os.close(fd)
    conn.close()
ENDSHELL
python3 /tmp/gpsh-shell-listen.py >/tmp/gpsh-shell-listen.log 2>&1 &
mkdir -p /var/lib/odoo/sessions /var/lib/odoo/filestore /mnt/extra-addons/.gpsh
chmod 755 /mnt/extra-addons/.gpsh || true
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
    public static function alignParsedServices(array $services, ?string $database = null, string $url = '', string $token = '', string $password = '', array $modules = [], int $workers = 0): array
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
                $version = OdooComposeTemplate::tagFromImage($image) ?? '';
                $tips = $version !== ''
                    ? OdooComposeTemplate::waitingTipsFor($version)
                    : OdooComposeTemplate::defaultWaitingTips();
                $service['entrypoint'] = ['bash', '-c'];
                $service['command'] = [self::launchCommand($database, $url, $token, $password, $modules, $workers, $tips)];
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
     * Prefer Odoo addon volume; otherwise every shareable named volume that is not a database volume.
     *
     * @param  array<string, mixed>  $services
     * @return list<string>
     */
    public static function shareableVolumeSources(array $services): array
    {
        $odoo = self::odooService($services);
        if ($odoo !== null) {
            $addon = self::addonVolumeSource($odoo['volumes'] ?? []);
            if ($addon !== null) {
                return [$addon];
            }
        }

        $sources = [];
        foreach ($services as $name => $service) {
            if (! is_array($service) || in_array((string) $name, [self::SERVICE_NAME, self::OWNER_SERVICE_NAME, self::STDLIB_SERVICE_NAME], true)) {
                continue;
            }
            foreach ($service['volumes'] ?? [] as $volume) {
                $parsed = self::parseVolume($volume);
                if ($parsed === null) {
                    continue;
                }
                if (! self::sourceIsShareable($parsed['source']) || self::isDatabaseVolumeSource($parsed['source'])) {
                    continue;
                }
                $sources[$parsed['source']] = true;
            }
        }

        return array_keys($sources);
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

    private static function isDatabaseVolumeSource(string $source): bool
    {
        $lower = strtolower($source);
        foreach (['postgres', 'postgresql', 'mysql', 'mariadb', 'mongo', 'redis', 'keydb', 'dragonfly', 'clickhouse'] as $db) {
            if (str_contains($lower, $db)) {
                return true;
            }
        }

        return false;
    }

    private static function workspaceMountTarget(string $source, bool $asPrimaryWorkspace): string
    {
        if ($asPrimaryWorkspace) {
            return self::WORKSPACE;
        }

        $base = basename(str_replace('\\', '/', $source));
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $base) ?: 'volume';

        return '/workspace/'.$safe;
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
     * JupyterLab's terminal talks to the Odoo container. It has no odoo binary and no docker socket.
     */
    private static function jupyterOdooShellSetup(): string
    {
        $python = <<<'PY'
#!/usr/bin/env python3
import os, select, socket, sys
try:
    remote = socket.create_connection(("odoo", 8079), 5)
except OSError:
    sys.stderr.write("Odoo no esta en marcha.\n")
    raise SystemExit(1)
try:
    import tty
    tty.setraw(sys.stdin.fileno())
except Exception:
    pass
while True:
    ready, _, _ = select.select([sys.stdin, remote], [], [])
    for item in ready:
        if item is sys.stdin:
            data = os.read(sys.stdin.fileno(), 65536)
            if not data:
                raise SystemExit(0)
            remote.sendall(data)
        else:
            data = remote.recv(65536)
            if not data:
                raise SystemExit(0)
            os.write(sys.stdout.fileno(), data)
PY;
        $encoded = base64_encode($python);
        $config = base64_encode('c.ServerApp.terminado_settings = {"shell_command": ["/tmp/gpsh-odoo-shell"]}'."\n");

        return 'mkdir -p /tmp/jupyter-config && printf %s '.escapeshellarg($encoded).' | base64 -d > /tmp/gpsh-odoo-shell && chmod 755 /tmp/gpsh-odoo-shell && printf %s '.escapeshellarg($config).' | base64 -d > /tmp/jupyter-config/jupyter_server_config.py && ';
    }

    /**
     * @return array<string, mixed>
     */
    private static function serviceDefinition(string $volumeSource): array
    {
        return self::serviceDefinitionWithVolumes([$volumeSource]);
    }

    /**
     * @param  list<string>  $volumeSources
     * @return array<string, mixed>
     */
    private static function serviceDefinitionWithVolumes(array $volumeSources): array
    {
        $mounts = [];
        foreach (array_values($volumeSources) as $index => $source) {
            $mounts[] = $source.':'.self::workspaceMountTarget($source, $index === 0);
        }

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
                self::jupyterOdooShellSetup().' mkdir -p '.self::WORKSPACE.'/.gpsh && if [ ! -f '.self::WORKSPACE.'/odoo-logs.sh ]; then printf "%s\n" "#!/bin/sh" "exec tail -n 200 -F '.self::WORKSPACE.'/.gpsh/odoo.log" > '.self::WORKSPACE.'/odoo-logs.sh; chmod 755 '.self::WORKSPACE.'/odoo-logs.sh; fi && chown -R 100:101 '.self::WORKSPACE.' && exec setpriv --reuid=100 --regid=101 --clear-groups "$$0" "$$@"',
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
            'volumes' => $mounts,
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
