<?php

use App\Enums\ProxyTypes;
use App\Models\Server;

test('a public https domain without a port still targets the container listen port', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://odoo.example.com']),
        onlyPort: '8069',
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.entryPoints=https');
    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.rule=Host(`odoo.example.com`) && PathPrefix(`/`)');
    expect($labels)->toContain('traefik.http.services.https-0-odoo-service.loadbalancer.server.port=8069');
    // Avoid substring false positives (port=8069 contains "port=80").
    expect(collect($labels)->filter(fn (string $label) => str_ends_with($label, 'loadbalancer.server.port=80')))->toBeEmpty();
});

test('https redirect leaves the lets encrypt challenge on port 80', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://odoo.example.com']),
        is_force_https_enabled: true,
        onlyPort: '8069',
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.http-0-odoo-service.rule=Host(`odoo.example.com`) && PathPrefix(`/`) && !PathPrefix(`/.well-known/acme-challenge/`)');
    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls.certresolver=letsencrypt');
    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls.domains[0].main=odoo.example.com');
    expect($labels)->toContain('traefik.http.routers.http-0-odoo-service.middlewares=redirect-to-https');
});

test('omitting tls certresolver keeps tls enabled for mounted certificates', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://odoo.example.com']),
        onlyPort: '8069',
        tlsCertResolver: null,
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls=true');
    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls.domains[0].main=odoo.example.com');
    expect($labels)->each(fn ($label) => $label->not->toContain('tls.certresolver='));
});

test('an explicit url port wins over the template port', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://odoo.example.com:8070']),
        onlyPort: '8069',
    )->values()->all();

    expect($labels)->toContain('traefik.http.services.https-0-odoo-service.loadbalancer.server.port=8070');
});

test('a scheme-less host and port is not parsed as the scheme', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['odoo.example.com:8069']),
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.http-0-odoo-service.rule=Host(`odoo.example.com`) && PathPrefix(`/`)');
    expect($labels)->toContain('traefik.http.services.http-0-odoo-service.loadbalancer.server.port=8069');
});

test('attaching the proxy to a service network is not hidden when it fails', function () {
    $command = coolifyProxyNetworkConnectCommand('svc-uuid');

    expect($command)->toContain("docker network inspect 'svc-uuid'");
    expect($command)->toContain('grep -qw coolify-proxy');
    expect($command)->toContain("docker network connect 'svc-uuid' coolify-proxy");
    expect($command)->not->toContain('|| true');
    expect($command)->not->toContain('$');
});

test('attaching an external Custom proxy resolves the container by name filter', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::NONE->value);
    $server->shouldReceive('externalProxyName')->andReturn('easypanel-traefik');

    $command = coolifyProxyNetworkConnectCommand('svc-uuid', $server);

    expect($command)
        ->toContain("docker ps --filter name='easypanel-traefik'")
        ->toContain("docker network inspect 'svc-uuid'")
        ->toContain('docker network connect \'svc-uuid\' "$PROXY"')
        ->not->toContain('coolify-proxy');
});

test('Custom without external_proxy_name skips proxy network connect', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('proxyType')->andReturn(ProxyTypes::NONE->value);
    $server->shouldReceive('externalProxyName')->andReturn(null);

    $command = coolifyProxyNetworkConnectCommand('svc-uuid', $server);

    expect($command)
        ->toContain('Skipping proxy network connect')
        ->not->toContain('docker network connect');
});

test('container listen port uses the compose target, not the published host port', function () {
    expect(containerListenPort(['8080:8069']))->toBe('8069');
    expect(containerListenPort([['target' => 8069, 'published' => 8080]]))->toBe('8069');
    expect(containerListenPort(null, ['8888']))->toBe('8888');
});

test('https without a url port still dials the resolved container listen port', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'catalog-service',
        domains: collect(['https://app.example.com']),
        onlyPort: '9000',
    )->values()->all();

    expect($labels)->toContain('traefik.http.services.https-0-catalog-service.loadbalancer.server.port=9000');
    expect($labels)->toContain('traefik.http.routers.https-0-catalog-service.tls.certresolver=letsencrypt');
    expect(collect($labels)->filter(fn (string $label) => str_ends_with($label, 'loadbalancer.server.port=80')))->toBeEmpty();
});

test('magic SERVICE_URL port is read from list-form compose environment', function () {
    expect(serviceMagicUrlPort(['SERVICE_URL_AUTHENTIKSERVER_9000']))->toBe('9000');
    expect(serviceMagicUrlPort(['SERVICE_FQDN_UMAMI_3000' => '']))->toBe('3000');
    expect(serviceMagicUrlPort(['POSTGRES_PASSWORD' => 'x']))->toBeNull();
});

test('compose proxy port prefers magic url port over catalog when compose has no ports', function () {
    expect(resolveComposeProxyPort(
        environment: ['SERVICE_URL_APP_9000'],
        catalogPort: '80',
    ))->toBe('9000');

    expect(resolveComposeProxyPort(
        composeExpose: ['8888'],
        environment: ['SERVICE_URL_APP_9000'],
        catalogPort: '80',
    ))->toBe('8888');

    expect(resolveComposeProxyPort(
        storedPorts: '3000',
        catalogPort: '80',
    ))->toBe('3000');

    expect(resolveComposeProxyPort(catalogPort: '9000'))->toBe('9000');
});

test('catalog template port uses service_type when the compose service name differs', function () {
    $templates = [
        'authentik' => ['port' => '9000'],
        'umami' => ['port' => '3000'],
    ];

    expect(catalogTemplatePort('authentik', 'authentik-server', $templates))->toBe('9000')
        ->and(catalogTemplatePort(null, 'authentik-server', $templates))->toBeNull()
        ->and(catalogTemplatePort('umami', 'dashboard', $templates))->toBe('3000');
});
