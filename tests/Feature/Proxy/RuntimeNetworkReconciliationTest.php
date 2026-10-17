<?php

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('discovers running container networks instead of relying on cached resource statuses', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $commands = connectProxyToNetworks($server)->implode("\n");

    expect($commands)
        ->toContain('docker ps --filter label=coolify.managed=true')
        ->toContain('.NetworkSettings.Networks')
        ->toContain('docker network inspect "$network"')
        ->toContain('docker network connect "$network" coolify-proxy')
        ->not->toContain('docker network create');
});

it('skips Docker system networks during runtime reconciliation', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);

    $commands = connectProxyToNetworks($server)->implode("\n");

    expect($commands)
        ->toContain('"$network" = "bridge"')
        ->toContain('"$network" = "host"')
        ->toContain('"$network" = "none"')
        ->toContain('"$network" = "default"');
});

it('preserves runtime reconciliation loops for non-root SSH users', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => 'ubuntu',
    ]);

    $commands = collect(parseCommandsByLineForSudo(connectProxyToNetworks($server), $server))->implode("\n");

    expect($commands)
        ->toContain('$(sudo docker ps')
        ->toMatch('/sudo\s+docker inspect/')
        ->toMatch('/sudo\s+docker network inspect/')
        ->toMatch('/sudo\s+docker network connect/')
        ->not->toContain('sudo while')
        ->not->toContain('sudo done')
        ->not->toContain('sudo continue');
});

it('does not connect coolify-proxy when proxy type is Custom without an external name', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->type = ProxyTypes::NONE->value;
    $server->save();

    expect(connectProxyToNetworks($server->fresh())->isEmpty())->toBeTrue();
});

it('connects an external proxy filter to coolify.managed networks when Custom is configured', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->forceFill([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
        'is_build_server' => false,
    ])->save();
    $server->proxy->type = ProxyTypes::NONE->value;
    $server->proxy->external_proxy_name = 'easypanel-traefik';
    $server->save();
    $server = $server->fresh(['settings']);

    expect($server->usesExternalProxyNetworkConnect())->toBeTrue();

    $commands = connectProxyToNetworks($server)->implode("\n");

    expect($commands)
        ->toContain("docker ps --filter name='easypanel-traefik'")
        ->toContain('docker ps --filter label=coolify.managed=true')
        ->toContain('docker network connect "$network" "$PROXY"')
        ->not->toContain('docker network connect "$network" coolify-proxy')
        ->toContain('if [ -n "$PROXY" ]; then');
});

it('rejects invalid external proxy names', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->proxy->type = ProxyTypes::NONE->value;
    $server->proxy->external_proxy_name = 'evil; rm -rf /';
    $server->save();

    expect($server->fresh()->externalProxyName())->toBeNull()
        ->and($server->fresh()->usesExternalProxyNetworkConnect())->toBeFalse()
        ->and(connectProxyToNetworks($server->fresh())->isEmpty())->toBeTrue();
});
