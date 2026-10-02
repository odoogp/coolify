<?php

use App\Support\OdooJupyter;
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
    expect($jupyter['entrypoint'][5])->toBe('chown -R 100:101 /workspace/addons && exec setpriv --reuid=100 --regid=101 --clear-groups "$$0" "$$@"');
    expect($jupyter['command'])->toBe([
        'jupyter',
        'lab',
        '--ServerApp.token=${SERVICE_PASSWORD_JUPYTER}',
        '--ServerApp.root_dir=/workspace/addons',
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
    expect($parser)->toContain('if ($resource->jupyter_enabled && is_string($compose))');
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
        ->and($command)->toContain('res_users')
        ->and($command)->toContain('gpsh_autoconnect')
        ->and($command)->toContain('_odoo/paas/connect')
        ->and($command)->toContain('Preparando Odoo.')
        ->and($command)->toContain('Instalando la base.')
        ->and($command)->toContain('Abriendo Odoo.')
        ->and($command)->toContain('--http-interface=0.0.0.0')
        ->and($command)->toContain('exec odoo "$${args[@]}" "$${load[@]}" -d mi_empresa_staging_1')
        ->and(str_replace('$$', '', $command))->not->toContain('$')
        ->and($aligned['odoo']['environment'])->toBe(['ODOO_DATABASE=mi_empresa_staging_1'])
        ->and($aligned['odoo-worker']['command'])->toBe('odoo --http-interface=0.0.0.0')
        ->and($aligned['odoo']['healthcheck'])->toBe(['disable' => true])
        ->and(implode("\n", $aligned['odoo']['labels'] ?? []))->not->toContain('gpsh-enter');
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
    $service = new \App\Models\Service;
    $service->forceFill([
        'id' => 15,
        'uuid' => 'abc123',
        'jupyter_enabled' => true,
    ]);

    $command = \App\Support\OdooGit::containersReadyCommand($service);

    expect($command)->toStartWith('bash -c ')
        ->and($command)->toContain('label=com.docker.compose.project=abc123')
        ->and($command)->toContain('label=coolify.serviceId=15')
        ->and($command)->toContain('need_jupyter=1')
        ->and($command)->toContain('seq 1 60')
        ->and($command)->toContain('The service containers are running.');
});

test('the odoo deploy waits until https answers', function () {
    $command = \App\Support\OdooGit::httpsReadyCommand('odoo.example.test');

    expect($command)->toStartWith('bash -c ')
        ->and($command)->toContain('odoo.example.test')
        ->and($command)->toContain('seq 1 120')
        ->and($command)->toContain('no available server')
        ->and($command)->toContain('se actualiza sola')
        ->and($command)->toContain('/web/login')
        ->and($command)->toContain('grep -qi encrypt')
        ->and(\App\Support\OdooGit::httpsReadyCommand('not a host'))->toBeNull();
});
