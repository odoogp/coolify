<?php

use App\Models\Server;

it('omits the traefik cert resolver for unrelated custom hosts on external proxies', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => null];

    expect($server->traefikTlsCertResolver('cliente.otro-dominio.com', 'getcloud.sh'))->toBeNull()
        ->and($server->traefikTlsCertResolver(null, 'getcloud.sh'))->toBeNull();
});

it('uses letsencrypt for project subdomains under the odoo base domain on an external proxy', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => null];

    expect($server->traefikTlsCertResolver('cliente.getcloud.sh', 'getcloud.sh'))->toBe('letsencrypt')
        ->and($server->traefikTlsCertResolver('cliente-staging-1.getcloud.sh', 'getcloud.sh'))->toBe('letsencrypt')
        ->and($server->traefikTlsCertResolver('getcloud.sh', 'getcloud.sh'))->toBe('letsencrypt');
});

it('uses letsencrypt for sslip hosts on an external proxy without an explicit resolver', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => null];

    expect($server->traefikTlsCertResolver('odoo-abc.159.69.56.112.sslip.io'))->toBe('letsencrypt')
        ->and($server->traefikTlsCertResolver('app.nip.io'))->toBe('letsencrypt');
});

it('uses an explicit cert resolver name on an external proxy when configured', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => 'letsencrypt'];

    expect($server->traefikTlsCertResolver('odoo.getcloud.sh'))->toBe('letsencrypt')
        ->and($server->traefikTlsCertResolver('odoo.sslip.io'))->toBe('letsencrypt');
});

it('defaults to letsencrypt for coolify-managed traefik', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(false);

    expect($server->traefikTlsCertResolver())->toBe('letsencrypt')
        ->and($server->traefikTlsCertResolver('odoo.example.com'))->toBe('letsencrypt');
});

it('detects acme need for sslip and odoo base-domain hosts', function () {
    expect(Server::hostNeedsAcmeOnExternalProxy('odoo.sslip.io'))->toBeTrue()
        ->and(Server::hostNeedsAcmeOnExternalProxy('cliente.getcloud.sh', 'getcloud.sh'))->toBeTrue()
        ->and(Server::hostNeedsAcmeOnExternalProxy('cliente.otro.com', 'getcloud.sh'))->toBeFalse()
        ->and(Server::hostNeedsAcmeOnExternalProxy('cliente.getcloud.sh', ''))->toBeFalse();
});

it('emits certresolver for sslip when the server is passed into fqdn labels', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => null];

    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://odoo-abc.159.69.56.112.sslip.io']),
        onlyPort: '8069',
        tlsCertResolver: $server,
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls.certresolver=letsencrypt');
});

it('emits certresolver for odoo base-domain hosts when the server is passed into fqdn labels', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->shouldReceive('traefikTlsCertResolver')
        ->with('cliente.getcloud.sh')
        ->andReturn('letsencrypt');
    $server->proxy = (object) ['cert_resolver' => null];

    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://cliente.getcloud.sh']),
        onlyPort: '8069',
        tlsCertResolver: $server,
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls.certresolver=letsencrypt');
});

it('omits certresolver for unrelated custom hosts when the server is passed into fqdn labels', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => null];

    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://cliente.otro-dominio.com']),
        onlyPort: '8069',
        tlsCertResolver: $server,
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls=true');
    expect($labels)->each(fn ($label) => $label->not->toContain('tls.certresolver='));
});
