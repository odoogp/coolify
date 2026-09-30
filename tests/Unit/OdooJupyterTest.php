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

    expect($jupyter['image'])->toBe(OdooJupyter::IMAGE);
    expect($jupyter['command'])->toContain('exec gosu');
    expect($jupyter['command'])->toContain("stat -c '%u'");
    expect($jupyter['volumes'])->toBe(['odoo-extra-addons:/workspace/addons']);
    expect((string) $jupyter['expose'][0])->toBe('8888');
    expect($jupyter['environment'])->toContain('SERVICE_URL_JUPYTER_8888');
    expect($jupyter['environment'])->toContain('JUPYTER_TOKEN=${SERVICE_PASSWORD_JUPYTER}');
    expect(implode("\n", $jupyter['environment']))->not->toContain('docker.sock');
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
      - 'odoo-extra-addons:/mnt/extra-addons'
      - 'postgresql-data:/var/lib/postgresql/data'
YAML;

    $jupyter = Yaml::parse(OdooJupyter::inject($compose))['services']['jupyter'];
    $rendered = json_encode($jupyter);

    expect($jupyter['volumes'])->toBe(['odoo-extra-addons:/workspace/addons']);
    expect($rendered)->not->toContain('docker.sock');
    expect($rendered)->not->toContain('/data/coolify');
    expect($rendered)->not->toContain('/root');
    expect($rendered)->not->toContain('postgresql-data');
    expect($rendered)->not->toContain('privileged');
});

test('an absolute host addon path is not shared with jupyter', function () {
    $compose = <<<'YAML'
services:
  odoo:
    image: 'odoo:18'
    volumes:
      - '/opt/odoo/custom-addons:/mnt/extra-addons'
YAML;

    expect(OdooJupyter::inject($compose))->toBe($compose);
});

test('the odoo checkbox and open action stay behind the existing service checks', function () {
    $root = dirname(__DIR__, 2);
    $form = file_get_contents($root.'/resources/views/livewire/project/service/stack-form.blade.php');
    $parser = file_get_contents($root.'/bootstrap/helpers/parsers.php');

    expect($form)->toContain('supportsOdooJupyter()');
    expect($form)->toContain('canGate="update"');
    expect($parser)->toContain('if ($resource->jupyter_enabled && is_string($compose))');
    expect($parser)->toContain('onlyPort: $proxyPort');
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
      - './custom_addons:/mnt/extra-addons'
YAML;

    $jupyter = Yaml::parse(OdooJupyter::inject($compose))['services']['jupyter'];

    expect($jupyter['volumes'])->toBe(['./custom_addons:/workspace/addons']);
});
