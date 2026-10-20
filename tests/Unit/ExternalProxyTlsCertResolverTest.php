<?php

use App\Models\Server;

it('omits the traefik cert resolver for custom hosts on external proxies by default', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => null];

    expect($server->traefikTlsCertResolver('odoo.getcloud.sh'))->toBeNull()
        ->and($server->traefikTlsCertResolver())->toBeNull();
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

it('omits certresolver for custom hosts when the server is passed into fqdn labels', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('usesExternalProxyNetworkConnect')->andReturn(true);
    $server->proxy = (object) ['cert_resolver' => null];

    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://odoo.getcloud.sh']),
        onlyPort: '8069',
        tlsCertResolver: $server,
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.tls=true');
    expect($labels)->each(fn ($label) => $label->not->toContain('tls.certresolver='));
});
