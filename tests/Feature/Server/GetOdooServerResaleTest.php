<?php

use App\Livewire\Server\New\ByGetOdoo;
use App\Livewire\Settings\GetOdooServers;
use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\GetOdooServerCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function getOdooHetznerFake(): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/server_types')) {
            return Http::response([
                'server_types' => [[
                    'id' => 22,
                    'name' => 'cx22',
                    'description' => 'CX22',
                    'cores' => 2,
                    'memory' => 4,
                    'disk' => 40,
                    'architecture' => 'x86',
                    'prices' => [
                        ['location' => 'fsn1', 'price_monthly' => ['gross' => '5.9400']],
                        ['location' => 'hel1', 'price_monthly' => ['gross' => '6.5000']],
                    ],
                ], [
                    'id' => 11,
                    'name' => 'cx11',
                    'description' => 'CX11',
                    'cores' => 1,
                    'memory' => 2,
                    'disk' => 20,
                    'architecture' => 'x86',
                    'prices' => [
                        ['location' => 'fsn1', 'price_monthly' => ['gross' => '3.2900']],
                    ],
                ]],
                'meta' => ['pagination' => ['last_page' => 1, 'next_page' => null]],
            ]);
        }

        if (str_contains($url, '/datacenters')) {
            return Http::response([
                'datacenters' => [[
                    'name' => 'fsn1-dc14',
                    'location' => ['name' => 'fsn1'],
                    'server_types' => [
                        'supported' => [22, 11],
                        'available' => [22],
                    ],
                ], [
                    'name' => 'hel1-dc2',
                    'location' => ['name' => 'hel1'],
                    'server_types' => [
                        'supported' => [22],
                        'available' => [],
                    ],
                ]],
                'meta' => ['pagination' => ['last_page' => 1, 'next_page' => null]],
            ]);
        }

        if (str_contains($url, '/ssh_keys') && $request->method() === 'POST') {
            return Http::response(['ssh_key' => ['id' => 42, 'fingerprint' => 'aa:bb']], 201);
        }

        if (str_contains($url, '/ssh_keys')) {
            return Http::response([
                'ssh_keys' => [],
                'meta' => ['pagination' => ['last_page' => 1, 'next_page' => null]],
            ]);
        }

        if (str_contains($url, '/images')) {
            return Http::response([
                'images' => [[
                    'id' => 7,
                    'name' => 'ubuntu-24.04',
                    'architecture' => 'x86',
                    'type' => 'system',
                    'status' => 'available',
                ]],
                'meta' => ['pagination' => ['last_page' => 1, 'next_page' => null]],
            ]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/servers')) {
            return Http::response([
                'server' => [
                    'id' => 99,
                    'status' => 'initializing',
                    'public_net' => ['ipv4' => ['ip' => '203.0.113.20']],
                ],
            ], 201);
        }

        return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
    });
}

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->root = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
});

it('keeps the markup when the owner updates the Hetzner connection', function () {
    getOdooHetznerFake();

    CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);

    $catalog = app(GetOdooServerCatalog::class);
    expect($catalog->sync())->toBe(1);

    $offer = GetOdooServerOffer::query()->where('name', 'cx22')->first();
    $offer->update(['markup' => 4.5, 'available_for_admins' => true]);
    GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 11,
        'name' => 'cx11',
        'description' => 'CX11',
        'cores' => 1,
        'memory' => 2,
        'disk' => 20,
        'monthly_price' => 3.29,
        'markup' => 1,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 3.29]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);

    expect($catalog->sync())->toBe(1);

    $offer->refresh();
    $unavailable = GetOdooServerOffer::query()->where('name', 'cx11')->first();
    expect((float) $offer->markup)->toBe(4.5)
        ->and($offer->available_for_admins)->toBeTrue()
        ->and($offer->location)->toBe('fsn1')
        ->and($offer->in_stock)->toBeTrue()
        ->and(collect($offer->locations)->pluck('location')->all())->toBe(['fsn1'])
        ->and($unavailable->in_stock)->toBeFalse()
        ->and($unavailable->available_for_admins)->toBeFalse();
});

it('lets an admin launch a published GetOdoo server with the owner Hetzner connection', function () {
    getOdooHetznerFake();

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);

    app(GetOdooServerCatalog::class)->sync();
    $offer = GetOdooServerOffer::query()->first();
    $offer->update(['markup' => 2, 'available_for_admins' => true]);

    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(ByGetOdoo::class)
        ->set('server_name', 'cliente-uno')
        ->set('location', 'fsn1')
        ->set('private_key_id', $key->id)
        ->call('submit')
        ->assertRedirect();

    $server = Server::query()->where('name', 'cliente-uno')->first();

    expect($server)->not->toBeNull()
        ->and($server->team_id)->toBe($team->id)
        ->and((int) $server->hetzner_server_id)->toBe(99)
        ->and($server->getodoo_offer_id)->toBe($offer->id)
        ->and((float) $server->getodoo_monthly_price)->toBe(7.94)
        ->and($server->ip)->toBe('203.0.113.20')
        ->and($server->cloud_provider_token_id)->toBe($token->id)
        ->and($server->vultr_instance_id)->toBeNull()
        ->and($server->digitalocean_droplet_id)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/servers')
            && ($request->data()['server_type'] ?? null) === 'cx22'
            && ($request->data()['image'] ?? null) === 7
            && ($request->header('Authorization')[0] ?? null) === 'Bearer owner-hetzner-token';
    });
});

it('lets the instance owner publish a server with a markup', function () {
    getOdooHetznerFake();

    CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);

    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    app(GetOdooServerCatalog::class)->sync();
    $offer = GetOdooServerOffer::query()->first();

    Livewire::test(GetOdooServers::class)
        ->set('markups.'.$offer->id, '2.25')
        ->set('available.'.$offer->id, true)
        ->call('saveOffers');

    $offer->refresh();

    expect((float) $offer->markup)->toBe(2.25)
        ->and($offer->available_for_admins)->toBeTrue();
});

it('does not open the resale screen for an admin', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(GetOdooServers::class)->assertRedirect(route('dashboard'));
});
