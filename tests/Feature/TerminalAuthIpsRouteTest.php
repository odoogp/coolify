<?php

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.env', 'local');

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $this->team->id,
    ]);
});

it('includes development terminal host aliases for authenticated users', function () {
    Server::factory()->create([
        'name' => 'Localhost',
        'ip' => 'coolify-testing-host',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $response = $this->postJson('/terminal/auth/ips');

    $response->assertSuccessful();
    $response->assertJsonPath('ipAddresses.0', 'coolify-testing-host');

    expect($response->json('ipAddresses'))
        ->toContain('coolify-testing-host')
        ->toContain('localhost')
        ->toContain('127.0.0.1')
        ->toContain('host.docker.internal');
});

it('authorizes a host the team does not own when one of its services runs there', function () {
    config()->set('app.env', 'production');

    $hostTeam = Team::factory()->create();
    $host = Server::factory()->create([
        'name' => 'GPSH host',
        'ip' => 'host.docker.internal',
        'team_id' => $hostTeam->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    expect($this->postJson('/terminal/auth/ips')->assertSuccessful()->json('ipAddresses'))
        ->not->toContain('host.docker.internal');

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first();
    $destination = StandaloneDocker::query()->where('server_id', $host->id)->first();
    Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $host->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    expect($this->postJson('/terminal/auth/ips')->json('ipAddresses'))
        ->toContain('host.docker.internal');
});
