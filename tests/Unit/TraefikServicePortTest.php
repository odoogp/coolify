<?php

test('a public https domain without a port still targets the container listen port', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'odoo-service',
        domains: collect(['https://odoo.example.com']),
        onlyPort: '8069',
    )->values()->all();

    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.entryPoints=https');
    expect($labels)->toContain('traefik.http.routers.https-0-odoo-service.rule=Host(`odoo.example.com`) && PathPrefix(`/`)');
    expect($labels)->toContain('traefik.http.services.https-0-odoo-service.loadbalancer.server.port=8069');
    expect($labels)->each(fn ($label) => $label->not->toContain('loadbalancer.server.port=80'));
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

test('container listen port uses the compose target, not the published host port', function () {
    expect(containerListenPort(['8080:8069']))->toBe('8069');
    expect(containerListenPort([['target' => 8069, 'published' => 8080]]))->toBe('8069');
    expect(containerListenPort(null, ['8888']))->toBe('8888');
});
