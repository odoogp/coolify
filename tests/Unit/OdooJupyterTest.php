<?php

use App\Models\Service;
use App\Support\OdooAddons;
use App\Support\OdooGit;
use App\Support\OdooJupyter;
use App\Support\OdooMonitor;
use Symfony\Component\Yaml\Yaml;

function odooCompose(): string
{
    return <<<'YAML'
services:
  odoo:
    image: 'odoo:18'
    environment:
      - SERVICE_URL_ODOO_8069
    volumes:
      - 'odoo-web-data:/var/lib/odoo'
      - 'odoo-extra-addons:/mnt/extra-addons'
  postgresql:
    image: 'postgres:16-alpine'
    volumes:
      - 'postgresql-data:/var/lib/postgresql/data'
YAML;
}

test('jupyter is injected only for an odoo stack and shares the addon volume', function () {
    expect(OdooJupyter::isOdooCompose(odooCompose()))->toBeTrue();

    $parsed = Yaml::parse(OdooJupyter::inject(odooCompose()));
    $jupyter = $parsed['services']['jupyter'];
    $odoo = $parsed['services']['odoo'];

    expect($jupyter['image'])->toBe('jupyter/datascience-notebook:latest');
    expect($jupyter['user'])->toBe('0:0');
    expect($jupyter['working_dir'])->toBe('/workspace/addons');
    expect($jupyter['restart'])->toBe('always');
    expect($jupyter['entrypoint'][5])->toContain('odoo-logs.sh')
        ->toContain('/workspace/addons/.gpsh/odoo.log')
        ->toContain('chown -R 100:101 /workspace/addons && exec setpriv --reuid=100 --regid=101 --clear-groups "$$0" "$$@"');
    expect($jupyter['command'])->toBe([
        'jupyter',
        'lab',
        '--ServerApp.token=${SERVICE_PASSWORD_JUPYTER}',
        '--ServerApp.allow_password_change=False',
        '--ServerApp.root_dir=/workspace/addons',
        '--MappingKernelManager.cull_idle_timeout=1800',
        '--MappingKernelManager.cull_interval=300',
        '--TerminalManager.cull_inactive_timeout=1800',
        '--TerminalManager.cull_interval=300',
        '--ip=0.0.0.0',
        '--allow-root',
        '--no-browser',
    ]);
    expect($jupyter['volumes'])->toBe(['odoo-extra-addons:/workspace/addons']);
    expect($odoo['volumes'])->toContain('odoo-extra-addons:/mnt/extra-addons');
    expect((string) $jupyter['expose'][0])->toBe('8888');
    expect($jupyter['environment'])->toContain('SERVICE_URL_JUPYTER_8888');
    expect($jupyter['environment'])->toContain('JUPYTER_ENABLE_LAB=yes');
    expect($jupyter['environment'])->toContain('HOME=/tmp');
    expect($jupyter['environment'])->toContain('JUPYTER_CONFIG_DIR=/tmp/jupyter-config');
    expect($jupyter['environment'])->toContain('JUPYTER_DATA_DIR=/tmp/jupyter-data');
    expect($jupyter['environment'])->toContain('JUPYTER_RUNTIME_DIR=/tmp/jupyter-runtime');
    expect($jupyter['environment'])->toContain('JUPYTER_TOKEN=${SERVICE_PASSWORD_JUPYTER}');
    expect($jupyter['healthcheck'])->toBe(['disable' => true]);
    expect($jupyter['entrypoint'][0])->toBe('tini');
    expect($jupyter['entrypoint'][4])->toBe('-c');
    expect($jupyter)->not->toHaveKey('networks');
    expect(json_encode($jupyter))->not->toContain('/home/jovyan');
    expect(json_encode($jupyter))->not->toContain('$target');
    expect(json_encode($jupyter))->not->toContain('$uid');
    expect(json_encode($jupyter))->not->toContain('$gid');
    expect(json_encode($jupyter))->not->toContain('gosu');
    expect(json_encode($jupyter))->not->toContain('docker.sock');
});

test('injecting twice does not add a second jupyter service', function () {
    $once = OdooJupyter::inject(odooCompose());
    $twice = OdooJupyter::inject($once);

    expect($twice)->toBe($once);
    expect(array_keys(Yaml::parse($once)['services']))->toBe(['odoo', 'postgresql', 'jupyter']);
});

test('a non odoo service is left unchanged', function () {
    $compose = <<<'YAML'
services:
  app:
    image: 'nginx:alpine'
YAML;

    expect(OdooJupyter::isOdooCompose($compose))->toBeFalse();
    expect(OdooJupyter::inject($compose))->toBe($compose);
});

test('jupyter does not mount postgres data, the docker socket, or host data directories', function () {
    $compose = <<<'YAML'
services:
  odoo:
    image: 'odoo:18'
    volumes:
      - '/var/run/docker.sock:/var/run/docker.sock'
      - '/data/coolify:/data/coolify'
      - '/root:/root'
      - '/etc/odoo:/etc/odoo'
      - 'odoo-extra-addons:/mnt/extra-addons'
      - 'postgresql-data:/var/lib/postgresql/data'
YAML;

    $jupyter = Yaml::parse(OdooJupyter::inject($compose))['services']['jupyter'];
    $rendered = json_encode($jupyter);

    expect($jupyter['volumes'])->toBe(['odoo-extra-addons:/workspace/addons']);
    expect($rendered)->not->toContain('docker.sock');
    expect($rendered)->not->toContain('/data/coolify');
    expect($rendered)->not->toContain('/root');
    expect($rendered)->not->toContain('/etc/odoo');
    expect($rendered)->not->toContain('postgresql-data');
    expect($rendered)->not->toContain('privileged');
});

test('jupyter does not mount the coolify data root or the whole service directory', function () {
    $compose = <<<'YAML'
services:
  odoo:
    image: 'odoo:18'
    volumes:
      - '/data/coolify:/mnt/extra-addons'
      - '/root/addons:/mnt/extra-addons'
YAML;

    expect(OdooJupyter::inject($compose))->toBe($compose);
});

test('the odoo checkbox and jupyter port stay behind the existing service checks', function () {
    $root = dirname(__DIR__, 2);
    $form = file_get_contents($root.'/resources/views/livewire/project/service/stack-form.blade.php');
    $parser = file_get_contents($root.'/bootstrap/helpers/parsers.php');

    expect($form)->toContain('supportsOdooJupyter()');
    expect($form)->toContain('canGate="update"');
    expect($form)->toContain('@if (isInstanceOwner())');
    expect(strpos($form, '@if (isInstanceOwner())'))->toBeLessThan(strpos($form, "__('Network')"));
    expect(strpos($form, "__('Network')"))->toBeLessThan(strpos($form, 'supportsOdooJupyter()'));
    expect($parser)->toContain('if ($resource->jupyter_enabled)');
    expect($parser)->toContain('OdooJupyter::injectOwner($compose)');
    expect($parser)->toContain('OdooJupyter::proxyPort');
    expect(OdooJupyter::proxyPort('jupyter', '80'))->toBe('8888');
    expect(OdooJupyter::proxyPort('odoo', '8069'))->toBe('8069');
});

test('odoo without a custom command listens on every container interface', function () {
    $services = [
        'odoo' => [
            'image' => 'odoo:18',
            'volumes' => ['svc_odoo-extra-addons:/mnt/extra-addons'],
        ],
        'odoo-worker' => [
            'image' => 'myregistry.example/odoo:18',
            'command' => 'odoo',
            'volumes' => ['svc_odoo-extra-addons:/mnt/extra-addons'],
        ],
        'odoo-blank' => [
            'image' => 'odoo:17',
            'command' => '',
            'volumes' => ['svc_odoo-extra-addons:/mnt/extra-addons'],
        ],
        'postgresql' => [
            'image' => 'postgres:16-alpine',
            'command' => 'postgres',
        ],
        'jupyter' => [
            'image' => OdooJupyter::IMAGE,
            'volumes' => ['odoo-extra-addons:/workspace/addons'],
        ],
    ];

    $aligned = OdooJupyter::alignParsedServices($services);

    expect($aligned['odoo']['command'])->toBe('odoo --http-interface=0.0.0.0');
    expect($aligned['odoo-worker']['command'])->toBe('odoo --http-interface=0.0.0.0');
    expect($aligned['odoo-blank']['command'])->toBe('odoo --http-interface=0.0.0.0');
    expect($aligned['postgresql']['command'])->toBe('postgres');
    expect($aligned['jupyter']['volumes'])->toBe(['svc_odoo-extra-addons:/workspace/addons']);
});

test('a custom odoo command is left unchanged', function () {
    $services = [
        'odoo' => [
            'image' => 'odoo:18',
            'command' => 'odoo --workers=2 --http-interface=127.0.0.1',
            'volumes' => ['svc_odoo-extra-addons:/mnt/extra-addons'],
        ],
        'jupyter' => [
            'image' => OdooJupyter::IMAGE,
            'volumes' => ['keep:/workspace/addons'],
        ],
    ];

    $aligned = OdooJupyter::alignParsedServices($services);

    expect($aligned['odoo']['command'])->toBe('odoo --workers=2 --http-interface=127.0.0.1');
    expect($aligned['jupyter']['volumes'])->toBe(['svc_odoo-extra-addons:/workspace/addons']);
});

test('jupyter uses the volume name the service parser already assigned to odoo', function () {
    $services = [
        'odoo' => [
            'image' => 'odoo:18',
            'volumes' => [
                'svc_odoo-web-data:/var/lib/odoo',
                'svc_odoo-extra-addons:/mnt/extra-addons',
            ],
        ],
        'postgresql' => [
            'image' => 'postgres:16-alpine',
            'volumes' => ['svc_postgresql-data:/var/lib/postgresql/data'],
        ],
        'jupyter' => [
            'image' => OdooJupyter::IMAGE,
            'volumes' => ['odoo-extra-addons:/workspace/addons'],
        ],
    ];

    $jupyter = OdooJupyter::alignParsedServices($services)['jupyter'];

    expect($jupyter['volumes'])->toBe(['svc_odoo-extra-addons:/workspace/addons']);
    expect(json_encode($jupyter))->not->toContain('postgresql-data');
    expect(json_encode($jupyter))->not->toContain('odoo-web-data');
});

test('jupyter adopts the bind the parser already gave the odoo addon path', function () {
    $services = [
        'odoo' => [
            'image' => 'odoo:18',
            'volumes' => [
                '/data/coolify/services/abc/addons:/mnt/extra-addons',
            ],
        ],
        'jupyter' => [
            'image' => OdooJupyter::IMAGE,
            'volumes' => ['./addons:/workspace/addons'],
        ],
    ];

    expect(OdooJupyter::alignParsedServices($services)['jupyter']['volumes'])
        ->toBe(['/data/coolify/services/abc/addons:/workspace/addons']);
});

test('jupyter does not adopt the docker socket or the coolify data root', function () {
    $services = [
        'odoo' => [
            'image' => 'odoo:18',
            'volumes' => ['/var/run/docker.sock:/mnt/extra-addons'],
        ],
        'jupyter' => [
            'image' => OdooJupyter::IMAGE,
            'volumes' => ['keep-me:/workspace/addons'],
        ],
    ];

    expect(OdooJupyter::alignParsedServices($services)['jupyter']['volumes'])->toBe(['keep-me:/workspace/addons']);
});

test('a relative addon bind is shared with jupyter', function () {
    $compose = <<<'YAML'
services:
  odoo:
    image: 'odoo:18'
    volumes:
      - './addons:/mnt/extra-addons'
YAML;

    $parsed = Yaml::parse(OdooJupyter::inject($compose));

    expect($parsed['services']['odoo']['volumes'])->toBe(['./addons:/mnt/extra-addons']);
    expect($parsed['services']['jupyter']['volumes'])->toBe(['./addons:/workspace/addons']);
});

test('the last addon mount is the one jupyter shares', function () {
    $compose = <<<'YAML'
services:
  odoo:
    image: 'odoo:18'
    volumes:
      - 'odoo-extra-addons:/mnt/extra-addons'
      - './addons:/mnt/extra-addons'
YAML;

    $jupyter = Yaml::parse(OdooJupyter::inject($compose))['services']['jupyter'];

    expect($jupyter['volumes'])->toBe(['./addons:/workspace/addons']);
});

test('an odoo service starts one database with https proxy mode and an admin user', function () {
    $services = [
        'odoo' => [
            'image' => 'odoo:20',
            'command' => 'odoo',
        ],
        'odoo-worker' => [
            'image' => 'odoo:20',
        ],
    ];

    $aligned = OdooJupyter::alignParsedServices($services, 'mi_empresa_staging_1', 'https://odoo.example.test', 'tokentokentoken', 'adminpass');

    $command = $aligned['odoo']['command'][0];

    expect($aligned['odoo']['entrypoint'])->toBe(['bash', '-c'])
        ->and($command)->toContain('--proxy-mode')
        ->and($command)->toContain('--no-database-list')
        ->and($command)->toContain("--db-filter='^mi_empresa_staging_1$$'")
        ->and($command)->toContain('-d mi_empresa_staging_1')
        ->and($command)->toContain('https://odoo.example.test')
        ->and($command)->toContain('tokentokentoken')
        ->and($command)->toContain('from odoo.http.session import authenticate, save_session')
        ->and($command)->toContain('base.user_admin')
        ->and($command)->toContain('web.base.url')
        ->and($command)->toContain('-i gpsh_autoconnect')
        ->and($command)->toContain('tee -a /mnt/extra-addons/.gpsh/odoo.log')
        ->and($command)->toContain('/gpsh-owner-modules/')
        ->and($aligned['odoo']['volumes'] ?? [])->toContain('/data/coolify/gpsh-owner-modules:/gpsh-owner-modules:ro')
        ->and($command)->toContain('gpsh-connect-state')
        ->and($command)->toContain('gpsh_autoconnect')
        ->and($command)->toContain('_odoo/paas/connect')
        ->and($command)->toContain('Estamos preparando todo.')
        ->and($command)->toContain('DROP TABLE IF EXISTS orm_signaling_registry, orm_signaling_assets')
        ->and($command)->toContain('Instalando la base.')
        ->and($command)->toContain('Ya casi está.')
        ->and($command)->not->toContain('setInterval')
        ->and($command)->toContain('websocket')
        ->and($command)->toContain('--http-interface=0.0.0.0')
        ->and($command)->toContain('chown -R odoo:odoo /var/lib/odoo')
        ->and($command)->toContain('setpriv --reuid=odoo')
        ->and($command)->not->toContain('--load=base,web,gpsh_autoconnect')
        ->and($command)->toContain('exec odoo "$${args[@]}" "$${load[@]}" -d mi_empresa_staging_1')
        ->and($command)->not->toContain('--logfile=')
        ->and($aligned['odoo']['user'])->toBe('0:0')
        ->and($aligned['odoo']['restart'])->toBe('unless-stopped')
        ->and(str_replace('$$', '', $command))->not->toContain('$')
        ->and($aligned['odoo']['environment'])->toBe(['ODOO_DATABASE=mi_empresa_staging_1'])
        ->and($aligned['odoo-worker']['command'])->toBe('odoo --http-interface=0.0.0.0')
        ->and($aligned['odoo']['healthcheck'])->toBe(['disable' => true])
        ->and(implode("\n", $aligned['odoo']['labels'] ?? []))->not->toContain('gpsh-enter');
});

test('the waiting page keeps each stage for twenty seconds and says almost there last', function () {
    $command = OdooJupyter::launchCommand('mi_empresa_production');
    $activity = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/project/environment-activity.blade.php');

    expect($command)->toContain('Estamos preparando todo.')
        ->and($command)->toContain('Instalando la base.')
        ->and($command)->toContain('Preparando el acceso.')
        ->and($command)->toContain('Ya casi está.')
        ->and($command)->toContain('time.time() - last < 20')
        ->and($command)->toContain('Esta página se actualiza sola.')
        ->and($command)->not->toContain('setInterval')
        ->and($command)->not->toContain('Es mejor que vayas por un café.')
        ->and($activity)->toContain('20000')
        ->and($activity)->not->toContain('setInterval')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Livewire/Project/Show.php'))->toContain('Almost there.');
});

test('an odoo https router tells odoo the browser used https', function () {
    $aligned = OdooJupyter::alignParsedServices([
        'odoo' => [
            'image' => 'odoo:20',
            'command' => 'odoo',
            'labels' => [
                'traefik.http.routers.https-0-uuid-odoo.middlewares=gzip',
                'traefik.http.routers.https-0-uuid-odoo.tls=true',
                'traefik.http.routers.http-0-uuid-odoo.middlewares=redirect-to-https',
            ],
        ],
    ], 'mi_empresa_production');

    expect($aligned['odoo']['labels'])->toContain('traefik.http.routers.https-0-uuid-odoo.middlewares=gzip,gpsh-forwarded-proto')
        ->and($aligned['odoo']['labels'])->toContain('traefik.http.middlewares.gpsh-forwarded-proto.headers.customrequestheaders.X-Forwarded-Proto=https')
        ->and($aligned['odoo']['labels'])->toContain('traefik.http.routers.http-0-uuid-odoo.middlewares=redirect-to-https');
});

test('the odoo deploy waits until its containers are running before https', function () {
    $service = new Service;
    $service->forceFill([
        'id' => 15,
        'uuid' => 'abc123',
        'jupyter_enabled' => true,
    ]);

    $command = OdooGit::containersReadyCommand($service);

    expect($command)->toStartWith('bash -c ')
        ->and($command)->toContain('project=abc123')
        ->and($command)->toContain('service_id=15')
        ->and($command)->toContain('label=com.docker.compose.project=${project}')
        ->and($command)->toContain('label=coolify.serviceId=${service_id}')
        ->and($command)->toContain('need_jupyter=0')
        ->and($command)->toContain('*stdlib*|*jupyterowner*')
        ->and($command)->toContain('seq 1 60')
        ->and($command)->toContain('The service containers are running.');
});

test('the odoo deploy waits until https answers', function () {
    $command = OdooGit::httpsReadyCommand('odoo.example.test');

    expect($command)->toStartWith('bash -c ')
        ->and($command)->toContain('odoo.example.test')
        ->and($command)->toContain('seq 1 120')
        ->and($command)->toContain('no available server')
        ->and($command)->toContain('se actualiza sola')
        ->and($command)->toContain('/web/login')
        ->and($command)->toContain('grep -qi encrypt')
        ->and(OdooGit::httpsReadyCommand('not a host'))->toBeNull();
});

test('an owner module is linked into the addon folder and odoo logs go to the shared file', function () {
    $command = OdooJupyter::launchCommand('mi_empresa_production', '', '', '', ['sale_owner', 'not-valid']);

    expect($command)->toContain('for module in sale_owner;')
        ->and($command)->toContain('ln -sfn "/gpsh-owner-modules/$$module" "/mnt/extra-addons/$$module"')
        ->and($command)->not->toContain('not-valid')
        ->and($command)->toContain('tee -a /mnt/extra-addons/.gpsh/odoo.log');
});

test('owner jupyter mounts the image addons, owner modules, and branch addons', function () {
    $compose = OdooJupyter::ownerCompose([
        ['team' => 'Cliente 1', 'environment' => 'production', 'custom' => 'abc_odoo-extra-addons', 'files' => 'abc_odoo-web-data', 'image' => 'odoo:18'],
        ['team' => 'Cliente 1', 'environment' => 'staging-1', 'custom' => 'def_odoo-extra-addons', 'files' => null, 'image' => 'odoo:18'],
    ], 'abcdef0123456789', 'jupyter.example.test');
    $services = Yaml::parse($compose)['services'];
    $owner = $services['jupyter'];

    expect($services)->not->toHaveKey('jupyterowner')
        ->and($services['stdlib-18']['image'])->toBe('odoo:18')
        ->and($services['stdlib-18']['volumes'])->toBe(['odoo-stdlib-18:/usr/lib/python3/dist-packages/odoo/addons'])
        ->and($owner['volumes'])->toContain('/data/coolify/gpsh-owner-modules:/workspace/owner:ro')
        ->and($owner['volumes'])->toContain('abc_odoo-extra-addons:/workspace/cliente-1/production/custom:ro')
        ->and($owner['volumes'])->toContain('abc_odoo-web-data:/workspace/cliente-1/production/files:ro')
        ->and($owner['volumes'])->toContain('odoo-stdlib-18:/workspace/cliente-1/production/odoo:ro')
        ->and($owner['volumes'])->toContain('def_odoo-extra-addons:/workspace/cliente-1/staging-1/custom:ro')
        ->and($owner['volumes'])->toContain('odoo-stdlib-18:/workspace/cliente-1/staging-1/odoo:ro')
        ->and(json_encode($owner))->not->toContain('docker.sock')
        ->and(OdooJupyter::injectOwner($compose))->toBe($compose)
        ->and(OdooJupyter::ownerExternalVolumes($compose))->toBe(['abc_odoo-extra-addons', 'abc_odoo-web-data', 'def_odoo-extra-addons'])
        ->and(OdooJupyter::hidesTerminal('jupyterowner'))->toBeTrue()
        ->and(OdooJupyter::hidesTerminal('jupyter'))->toBeFalse()
        ->and(OdooGit::clientSeesLog('odoo-abc'))->toBeTrue()
        ->and(OdooGit::clientSeesLog('postgresql-abc'))->toBeTrue()
        ->and(OdooGit::clientSeesLog('monitor-abc'))->toBeFalse()
        ->and(OdooGit::clientSeesLog('beszelagent-abc'))->toBeFalse()
        ->and(OdooGit::clientSeesLog('jupyterowner-abc'))->toBeFalse()
        ->and(OdooGit::clientSeesLog('stdlib-abc'))->toBeFalse()
        ->and(OdooGit::isOdooContainerLog('odoo-abc'))->toBeTrue()
        ->and(OdooGit::isOdooContainerLog('postgresql-abc'))->toBeFalse()
        ->and(OdooGit::isOdooContainerLog('jupyter-abc'))->toBeFalse()
        ->and(OdooGit::isOdooContainerLog('stdlib-abc'))->toBeFalse()
        ->and(OdooGit::isOdooContainerLog('monitor-abc'))->toBeFalse()
        ->and(OdooGit::usesSharedCertificate('monitor_3000'))->toBeTrue()
        ->and(OdooGit::usesSharedCertificate('jupyterowner'))->toBeTrue()
        ->and(OdooGit::usesSharedCertificate('cadvisor'))->toBeFalse();
});

test('owner jupyter uses the proxy network and the notebook start script', function () {
    expect(OdooJupyter::proxyNetworkFrom('bridge coolify other'))->toBe('coolify')
        ->and(OdooJupyter::proxyNetworkFrom('coolify-overlay'))->toBe('coolify-overlay')
        ->and(OdooJupyter::proxyNetworkFrom('bridge host'))->toBe('coolify')
        ->and(OdooJupyter::ownerCompose([], 'abcdef0123456789', 'jupyter.example.test', 'coolify-overlay'))
        ->toContain('traefik.docker.network=coolify-overlay')
        ->toContain('start-notebook.py')
        ->not->toContain('allow_remote_access')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Support/OdooJupyter.php'))->toContain('cmp -s')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Support/OdooJupyter.php'))->toContain('502|503|000');
});

test('owner jupyter drops a missing volume without leaving a broken volumes key', function () {
    $compose = OdooJupyter::ownerCompose([
        ['team' => 'Cliente 1', 'environment' => 'production', 'custom' => 'abc_odoo-extra-addons', 'files' => 'abc_odoo-web-data', 'image' => 'odoo:18'],
    ], 'abcdef0123456789', 'jupyter.example.test');
    $stripped = Yaml::parse(OdooJupyter::withoutVolumes($compose, ['abc_odoo-extra-addons']));
    $gone = Yaml::parse(OdooJupyter::withoutVolumes($compose, ['abc_odoo-extra-addons', 'abc_odoo-web-data', 'odoo-stdlib-18']));
    $remote = Yaml::parse(OdooJupyter::withoutVolumes($compose, ['abc_odoo-extra-addons', 'abc_odoo-web-data']));

    expect($stripped['volumes'])->not->toHaveKey('abc_odoo-extra-addons')
        ->and($stripped['volumes'])->toHaveKey('abc_odoo-web-data')
        ->and($stripped['services']['jupyter']['volumes'])->not->toContain('abc_odoo-extra-addons:/workspace/cliente-1/production/custom:ro')
        ->and($stripped['services']['jupyter']['volumes'])->toContain('/data/coolify/gpsh-owner-modules:/workspace/owner:ro')
        ->and($gone)->not->toHaveKey('volumes')
        ->and($gone['services']['jupyter']['volumes'])->toContain('/data/coolify/gpsh-owner-modules:/workspace/owner:ro')
        ->and($remote['services']['jupyter']['volumes'])->toContain('odoo-stdlib-18:/workspace/cliente-1/production/odoo:ro')
        ->and($remote['services']['jupyter']['volumes'])->toContain('/data/coolify/gpsh-owner-modules:/workspace/owner:ro');
});

test('owner jupyter shows another server as that team folder with its custom addons and the image addons', function () {
    $ready = OdooJupyter::prepareOwnerInstances([[
        'team' => 'Cliente 1',
        'environment' => 'production',
        'custom' => 'abc_odoo-extra-addons',
        'custom_fallback' => 'abc_odoo-extra-addons',
        'files' => 'abc_odoo-web-data',
        'files_fallback' => 'abc_odoo-web-data',
        'image' => 'odoo:18',
        'server_id' => '4',
    ]], []);
    $compose = OdooJupyter::ownerCompose($ready, 'abcdef0123456789', 'jupyter.example.test');
    $volumes = Yaml::parse($compose)['services']['jupyter']['volumes'];

    expect($ready[0]['custom'])->toBeNull()
        ->and($ready[0]['custom_bind'])->toBe('/data/coolify/gpsh-owner-jupyter/clients/cliente-1/production/custom')
        ->and($ready[0]['import_volume'])->toBe('abc_odoo-extra-addons')
        ->and($volumes)->toContain('/data/coolify/gpsh-owner-jupyter/clients/cliente-1/production/custom:/workspace/cliente-1/production/custom:ro')
        ->and($volumes)->toContain('odoo-stdlib-18:/workspace/cliente-1/production/odoo:ro')
        ->and($volumes)->not->toContain('abc_odoo-extra-addons:/workspace/cliente-1/production/custom:ro');

    $local = OdooJupyter::prepareOwnerInstances([[
        'team' => 'Root Team',
        'environment' => 'production',
        'custom' => 'root_odoo-extra-addons',
        'custom_fallback' => 'root_odoo-extra-addons',
        'files' => null,
        'image' => 'odoo:20',
        'server_id' => '0',
    ]], ['root_odoo-extra-addons']);

    expect($local[0]['custom'])->toBe('root_odoo-extra-addons')
        ->and($local[0]['custom_bind'])->toBeNull();
});

test('owner jupyter omits an empty volumes key and leftover volumes are the unused odoo ones', function () {
    $compose = OdooJupyter::ownerCompose([], 'abcdef0123456789', 'jupyter.example.test');
    $service = new Service;
    $service->uuid = 'abc123';
    $copy = OdooAddons::copyCommands($service, '/tmp/addons');

    expect(Yaml::parse($compose))->not->toHaveKey('volumes')
        ->and(OdooJupyter::leftoverVolumes(
            ['abc_odoo-extra-addons', 'abc_odoo-web-data', 'abc_postgresql-data', 'odoo-stdlib-18', 'coolify-db', 'xyz_odoo-web-data'],
            ['xyz_odoo-web-data'],
        ))->toBe(['abc_odoo-extra-addons', 'abc_odoo-web-data', 'abc_postgresql-data', 'odoo-stdlib-18'])
        ->and(OdooJupyter::leftoverVolumeRows(
            ['abc_odoo-extra-addons', 'odoo-stdlib-18'],
            [],
            ['abc_odoo-extra-addons' => ['client' => 'Cliente 1', 'environment' => 'production']],
        ))->toBe([
            ['name' => 'abc_odoo-extra-addons', 'client' => 'Cliente 1', 'environment' => 'production'],
            ['name' => 'odoo-stdlib-18', 'client' => 'Shared across clients', 'environment' => 'Odoo 18'],
        ])
        ->and(OdooJupyter::pageVolumeRows(array_map(fn (int $i): array => ['name' => "v{$i}", 'client' => 'c', 'environment' => 'e'], range(1, 25)), 3)['rows'])->toHaveCount(5)
        ->and(implode("\n", Yaml::parse($compose)['services']['jupyter']['labels']))
        ->toContain('!PathPrefix(`/.well-known/acme-challenge/`)')
        ->toContain('tls.certresolver=letsencrypt')
        ->toContain('tls.domains[0].main=jupyter.example.test')
        ->toContain('traefik.docker.network=coolify')
        ->and(Yaml::parse($compose)['networks']['coolify']['external'])->toBeTrue()
        ->and(Yaml::parse($compose)['services']['jupyter']['networks'])->toBe(['coolify'])
        ->and($copy[1])->toContain('.gpsh')
        ->and($copy[1])->toContain('rm -rf')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Actions/Service/DeleteService.php'))->toContain('docker rm -f gpsh-owner-jupyter');
});

test('client jupyter mounts that environment addon volume', function () {
    $aligned = OdooJupyter::alignParsedServices([
        'odoo' => [
            'image' => 'odoo:20',
            'command' => 'already-set',
            'volumes' => ['abc_odoo-extra-addons:/mnt/extra-addons'],
        ],
        'jupyter' => [
            'image' => 'jupyter/datascience-notebook:latest',
            'volumes' => ['wrong:/workspace/addons'],
        ],
    ]);

    expect($aligned['jupyter']['volumes'])->toBe([
        'abc_odoo-extra-addons:/workspace/addons',
    ]);
});

test('launching odoo adds one beszel container for that stack', function () {
    $compose = OdooMonitor::inject(odooCompose(), 'abc123');
    $parsed = Yaml::parse($compose);
    $services = $parsed['services'];
    $again = OdooMonitor::inject($compose, 'abc123');
    $script = $services['monitor']['command'][0];

    expect(array_keys($services))->toContain('monitor')
        ->and(array_keys($services))->not->toContain('beszelagent')
        ->and(array_keys($services))->not->toContain('beszelfilter')
        ->and(array_keys($services))->not->toContain('cadvisor')
        ->and(array_keys($services))->not->toContain('prometheus')
        ->and($again)->toBe($compose)
        ->and($services['monitor']['image'])->toBe('python:3.12-alpine')
        ->and($services['monitor']['environment'])->toContain('SERVICE_URL_MONITOR_8090')
        ->and($services['monitor']['environment'])->toContain('APP_URL=https://${SERVICE_FQDN_MONITOR}')
        ->and($services['monitor']['environment'])->toContain('AUTO_LOGIN=monitor@gpsh.local')
        ->and($services['monitor']['environment'])->toContain('ALLOW=odoo-abc123,postgresql-abc123,postgres-abc123')
        ->and($services['monitor']['volumes'])->toBe(OdooMonitor::filterVolumes())
        ->and($script)->toContain('universal-token')
        ->and($script)->toContain('/containers/json')
        ->and($script)->toContain('0.21.0')
        ->and($script)->toContain('beszel-agent_linux_')
        ->and(OdooMonitor::alignServices([
            'monitor' => [
                'image' => 'python:3.12-alpine',
                'volumes' => ['abc_beszel-data:/beszel_data', '/var/run/docker.sock:/elsewhere'],
            ],
        ])['monitor']['volumes'])->toBe([
            '/var/run/docker.sock:/var/run/docker.sock:ro',
            'abc_beszel-data:/beszel_data',
        ])
        ->and($parsed['volumes'])->toHaveKey('beszel-data')
        ->and($parsed['volumes'])->not->toHaveKey('beszel-run')
        ->and(OdooMonitor::hidesTerminal('monitor'))->toBeTrue()
        ->and(OdooMonitor::hidesTerminal('odoo'))->toBeFalse()
        ->and(OdooMonitor::dashboardUrl('https://monitor.example.test', 'odoo-abc123', 'postgresql-abc123'))
        ->toBe('https://monitor.example.test')
        ->and(OdooMonitor::inject(odooCompose(), 'not a project'))->toBe(odooCompose());
});

test('the odoo terminal opens the odoo shell and the other containers keep theirs', function () {
    $shell = OdooGit::terminalShell('odoo-abc123');

    expect($shell)->toContain('--db_host="$HOST"')
        ->and($shell)->toContain('--db_port="$PORT"')
        ->and($shell)->not->toContain('exec bash')
        ->and($shell)->not->toContain('exec odoo shell')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Livewire/Project/Shared/Terminal.php'))->toContain('--rcfile /tmp/gpsh-shell.sh')
        ->and(OdooGit::loginOpenCommand('odoo.example.test'))->toContain('/web/login')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Jobs/CloneOdooStagingJob.php'))->toContain('loginAnswers')
        ->and(OdooGit::terminalShell('jupyter-abc123'))->toBeNull()
        ->and(OdooGit::terminalShell('postgresql-abc123'))->toBeNull();

    expect(OdooGit::loginOpenCommand('odoo.example.test'))->not->toContain('letsencrypt')
        ->and(OdooGit::loginOpenCommand('odoo.example.test'))->toContain('se actualiza sola')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Support/OdooJupyter.php'))->toContain('--http-port=8071')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Support/OdooJupyter.php'))->toContain('HTTPConnection("127.0.0.1", 8071, timeout=60)')
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Support/OdooJupyter.php'))->toContain('== "websocket"');
});

test('odoo shares its certificate and the owner jupyter starts later', function () {
    $services = OdooJupyter::shareOdooCertificate([
        'odoo' => ['labels' => [
            'traefik.http.routers.https-0-abc.tls.domains[0].main=odoo-abc.sslip.io',
            'traefik.http.routers.https-0-abc.tls.certresolver=letsencrypt',
        ]],
        'monitor' => ['labels' => [
            'traefik.http.routers.https-0-abc-monitor.tls.domains[0].main=monitor-abc.sslip.io',
            'traefik.http.routers.https-0-abc-monitor.tls.certresolver=letsencrypt',
        ]],
        'jupyter' => ['labels' => [
            'traefik.http.routers.https-0-abc-jupyter.tls.domains[0].main=jupyter-abc.sslip.io',
            'traefik.http.routers.https-0-abc-jupyter.tls.certresolver=letsencrypt',
        ]],
    ]);

    expect(implode("\n", $services['odoo']['labels']))
        ->toContain('traefik.http.routers.https-0-abc.tls.domains[0].sans=monitor-abc.sslip.io,jupyter-abc.sslip.io')
        ->toContain('tls.certresolver=letsencrypt')
        ->and(implode("\n", $services['monitor']['labels']))->not->toContain('certresolver')
        ->and(implode("\n", $services['jupyter']['labels']))->not->toContain('certresolver')
        ->and(OdooJupyter::backgroundStartCommand('/data/coolify/services/abc123', 'abc123'))
        ->toBe('true');
});
