<?php

use App\Livewire\Server\New\ByAdditionalCloud;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->linodeToken = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'linode',
        'token' => 'test-linode-token',
        'name' => 'Test Linode Token',
    ]);

    $this->privateKey = PrivateKey::create([
        'team_id' => $this->team->id,
        'name' => 'Test Private Key',
        'description' => 'Test private key',
        'private_key' => additionalCloudTestPrivateKey(),
    ]);
});

function additionalCloudTestPrivateKey(): string
{
    return <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
}

it('creates a linode and stores it without changing the other provider columns', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances' => Http::response([
            'id' => 42,
            'status' => 'provisioning',
            'ipv4' => ['203.0.113.10'],
        ]),
    ]);

    Livewire::test(ByAdditionalCloud::class, [
        'provider' => 'linode',
        'selectedTokenUuid' => $this->linodeToken->uuid,
    ])
        ->assertSet('current_step', 2)
        ->set('server_name', 'test-linode-server')
        ->set('selected_region', 'us-east')
        ->set('selected_plan', 'g6-nanode-1')
        ->set('selected_image', 'linode/ubuntu24.04')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit');

    $this->assertDatabaseHas('servers', [
        'name' => 'test-linode-server',
        'ip' => '203.0.113.10',
        'user' => 'root',
        'team_id' => $this->team->id,
        'cloud_provider_token_id' => $this->linodeToken->id,
        'provider_server_id' => '42',
        'provider_server_status' => 'provisioning',
        'hetzner_server_id' => null,
        'vultr_instance_id' => null,
        'digitalocean_droplet_id' => null,
    ]);
});

it('keeps the placeholder address when the provider has not assigned an ip', function () {
    Http::fake([
        'https://api.linode.com/v4/linode/instances' => Http::response([
            'id' => 43,
            'status' => 'provisioning',
            'ipv4' => ['0.0.0.0'],
        ]),
    ]);

    Livewire::test(ByAdditionalCloud::class, [
        'provider' => 'linode',
        'selectedTokenUuid' => $this->linodeToken->uuid,
    ])
        ->set('server_name', 'test-linode-waiting')
        ->set('selected_region', 'us-east')
        ->set('selected_plan', 'g6-nanode-1')
        ->set('selected_image', 'linode/debian12')
        ->set('private_key_id', $this->privateKey->id)
        ->call('submit')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('servers', [
        'name' => 'test-linode-waiting',
        'ip' => Server::PLACEHOLDER_IP,
        'provider_server_id' => '43',
    ]);
});
