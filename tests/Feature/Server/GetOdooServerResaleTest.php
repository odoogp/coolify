<?php

use App\Livewire\Server\New\ByGetOdoo;
use App\Livewire\Settings\GetOdooServers;
use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\GetOdooPrice;
use App\Services\GetOdoo\GetOdooServerCatalog;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function getOdooHetznerFake(): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'id.wompi.sv/connect/token')) {
            return Http::response(['access_token' => 'wompi-token', 'expires_in' => 300]);
        }

        if (str_contains($url, 'api.wompi.sv/EnlacePago') && $request->method() === 'POST') {
            return Http::response([
                'idEnlace' => 15,
                'urlEnlace' => 'https://lk.wompi.sv/yhDt',
                'estaProductivo' => true,
            ]);
        }

        if (str_contains($url, 'api.wompi.sv/TransaccionCompra/')) {
            return Http::response([
                'esAprobada' => true,
                'esReal' => true,
                'monto' => (float) (GetOdooServerOrder::query()->latest('id')->value('amount') ?? 0),
            ]);
        }

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
                    'locations' => [
                        ['name' => 'fsn1', 'available' => true],
                        ['name' => 'hel1', 'available' => false],
                    ],
                    'prices' => [
                        ['location' => 'fsn1', 'price_monthly' => ['net' => '4.9900', 'gross' => '5.9400']],
                        ['location' => 'hel1', 'price_monthly' => ['net' => '5.4600', 'gross' => '6.5000']],
                    ],
                ], [
                    'id' => 11,
                    'name' => 'cx11',
                    'description' => 'CX11',
                    'cores' => 1,
                    'memory' => 2,
                    'disk' => 20,
                    'architecture' => 'x86',
                    'locations' => [
                        ['name' => 'fsn1', 'available' => false],
                    ],
                    'prices' => [
                        ['location' => 'fsn1', 'price_monthly' => ['net' => '2.7600', 'gross' => '3.2900']],
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

it('does not call Hetzner until the owner chooses the account', function () {
    Http::preventStrayRequests();

    CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);

    expect(fn () => app(GetOdooServerCatalog::class)->sync())
        ->toThrow(RuntimeException::class, 'Choose a Hetzner token. Sold servers are created in that account.');

    Http::assertNothingSent();
});

it('keeps the markup when the owner updates the Hetzner connection', function () {
    getOdooHetznerFake();

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update(['getodoo_hetzner_token_id' => $token->id]);

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
        'available_since' => now(),
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
        ->and($unavailable->available_for_admins)->toBeFalse()
        ->and($unavailable->available_since)->toBeNull();
});

it('lets an admin launch a published GetOdoo server with the owner Hetzner connection', function () {
    getOdooHetznerFake();

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update([
        'getodoo_hetzner_token_id' => $token->id,
        'getodoo_eur_usd_rate' => 1.1,
        'getodoo_tax_percent' => 19,
        'getodoo_margin_percent' => 20,
    ]);

    app(GetOdooServerCatalog::class)->sync();
    $offer = GetOdooServerOffer::query()->first();
    $offer->update(['available_for_admins' => true]);

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
        ->and((float) $server->getodoo_monthly_price)->toBe((new GetOdooPrice(1.1, 19, 20))->suggestedUsd((float) $offer->monthlyFor('fsn1')))
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

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update(['getodoo_hetzner_token_id' => $token->id]);

    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    app(GetOdooServerCatalog::class)->sync();
    $offer = GetOdooServerOffer::query()->first();

    Livewire::test(GetOdooServers::class)
        ->set('eurUsd', '1.2')
        ->set('taxPercent', '19')
        ->set('marginPercent', '25')
        ->set('available.'.$offer->id, true)
        ->call('saveOffers');

    $offer->refresh();

    expect((float) instanceSettings()->getodoo_eur_usd_rate)->toBe(1.2)
        ->and((float) instanceSettings()->getodoo_margin_percent)->toBe(25.0)
        ->and($offer->available_for_admins)->toBeTrue()
        ->and((float) $offer->margin_percent)->toBe(25.0)
        ->and($offer->available_since)->not->toBeNull()
        ->and($offer->sellPrice('fsn1'))->toBe((new GetOdooPrice(1.2, 19, 25))->suggestedUsd((float) $offer->monthlyFor('fsn1')));
});

it('keeps a published line margin when the global margin changes', function () {
    getOdooHetznerFake();

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update(['getodoo_hetzner_token_id' => $token->id]);

    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    app(GetOdooServerCatalog::class)->sync();
    $offer = GetOdooServerOffer::query()->where('name', 'cx22')->first();
    $other = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 33,
        'name' => 'cpx11',
        'description' => 'CPX11',
        'cores' => 2,
        'memory' => 2,
        'disk' => 40,
        'monthly_price' => 5.49,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 5.49]],
        'in_stock' => true,
        'available_for_admins' => false,
    ]);

    $component = Livewire::test(GetOdooServers::class)
        ->set('lineMargins.'.$offer->id, '30')
        ->set('available.'.$offer->id, true)
        ->assertSeeHtml('getodoo-offer-published')
        ->call('saveOffers');

    $offer->refresh();
    $since = $offer->available_since->copy();

    expect((float) $offer->margin_percent)->toBe(30.0);

    $this->travel(2)->minutes();

    $component
        ->set('marginPercent', '50')
        ->call('saveOffers');

    $offer->refresh();
    $other->refresh();

    expect((float) $offer->margin_percent)->toBe(30.0)
        ->and($offer->available_since->equalTo($since))->toBeTrue()
        ->and((float) instanceSettings()->getodoo_margin_percent)->toBe(50.0)
        ->and($other->margin_percent)->toBeNull()
        ->and($offer->sellPrice('fsn1'))->toBe((new GetOdooPrice(1.1, 19, 30))->suggestedUsd((float) $offer->monthlyFor('fsn1')));

    $component
        ->set('available.'.$offer->id, false)
        ->set('lineMargins.'.$offer->id, '12')
        ->set('available.'.$offer->id, true)
        ->call('saveOffers');

    $offer->refresh();

    expect((float) $offer->margin_percent)->toBe(12.0)
        ->and($offer->available_since->greaterThan($since))->toBeTrue();
});

it('does not open the resale screen for an admin', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(GetOdooServers::class)->assertRedirect(route('dashboard'));
});

it('sends the buyer to Wompi and does not create the server until the charge is approved', function () {
    getOdooHetznerFake();

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update([
        'getodoo_hetzner_token_id' => $token->id,
        'getodoo_eur_usd_rate' => 1.1,
        'getodoo_tax_percent' => 19,
        'getodoo_margin_percent' => 20,
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 22,
        'name' => 'cx22',
        'description' => 'CX22',
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'architecture' => 'x86',
        'monthly_price' => 4.99,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 4.99]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);
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
        ->assertRedirect('https://lk.wompi.sv/yhDt');

    $order = GetOdooServerOrder::query()->first();

    expect(Server::query()->where('name', 'cliente-uno')->exists())->toBeFalse()
        ->and($order)->not->toBeNull()
        ->and($order->status)->toBe('awaiting_payment')
        ->and($order->team_id)->toBe($team->id)
        ->and((float) $order->amount)->toBe($offer->sellPrice('fsn1'));

    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/servers'));
    Http::assertSent(function ($request) use ($order) {
        return $request->method() === 'POST'
            && str_contains($request->url(), 'api.wompi.sv/EnlacePago')
            && ($request->data()['identificadorEnlaceComercio'] ?? null) === $order->uuid
            && ($request->data()['configuracion']['esMontoEditable'] ?? null) === false
            && ($request->data()['formaPago']['permitirTarjetaCreditoDebido'] ?? null) === true
            && ($request->data()['formaPago']['permitirPagoEnCuotasAgricola'] ?? null) === false
            && ($request->data()['limitesDeUso']['cantidadMaximaPagosExitosos'] ?? null) === 1;
    });
});

it('creates the GetOdoo server when Wompi signs a live charge', function () {
    getOdooHetznerFake();

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update([
        'getodoo_hetzner_token_id' => $token->id,
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 22,
        'name' => 'cx22',
        'description' => 'CX22',
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'architecture' => 'x86',
        'monthly_price' => 4.99,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 4.99]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'offer_id' => $offer->id,
        'private_key_id' => $key->id,
        'server_name' => 'cliente-uno',
        'location' => 'fsn1',
        'amount' => $offer->sellPrice('fsn1'),
        'status' => 'awaiting_payment',
    ]);

    $payload = [
        'IdTransaccion' => 'tx-webhook-1',
        'Monto' => (float) $order->amount,
        'ResultadoTransaccion' => 'ExitosaAprobada',
        'EsProductiva' => true,
        'EnlacePago' => [
            'IdentificadorEnlaceComercio' => $order->uuid,
        ],
    ];
    $body = json_encode($payload);
    $hash = app(WompiClient::class)->webhookHash($body);

    $this->call('POST', '/webhooks/payments/wompi', [], [], [], [
        'HTTP_WOMPI_HASH' => $hash,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $this->call('POST', '/webhooks/payments/wompi', [], [], [], [
        'HTTP_WOMPI_HASH' => $hash,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $server = Server::query()->where('name', 'cliente-uno')->first();

    expect($server)->not->toBeNull()
        ->and(Server::query()->where('name', 'cliente-uno')->count())->toBe(1)
        ->and((int) $server->hetzner_server_id)->toBe(99)
        ->and((float) $server->getodoo_monthly_price)->toBe((float) $order->amount)
        ->and($server->getodoo_billing_anchor?->toDateString())->toBe(now()->startOfDay()->toDateString())
        ->and($server->getodoo_paid_until?->toDateString())->toBe(now()->startOfDay()->addMonth()->toDateString())
        ->and($order->fresh()->status)->toBe('provisioned')
        ->and($order->fresh()->server_id)->toBe($server->id);

    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/servers'))->count())->toBe(1);
});

it('ignores a Wompi notice that is not a live charge', function () {
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 22,
        'name' => 'cx22',
        'description' => 'CX22',
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'monthly_price' => 4.99,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 4.99]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'offer_id' => $offer->id,
        'private_key_id' => $key->id,
        'server_name' => 'cliente-uno',
        'location' => 'fsn1',
        'amount' => 9.41,
        'status' => 'awaiting_payment',
    ]);

    $notice = function (array $payload) use ($order) {
        $payload['EnlacePago'] = ['IdentificadorEnlaceComercio' => $order->uuid];
        $body = json_encode($payload);

        return [$body, app(WompiClient::class)->webhookHash($body)];
    };

    [$body, $hash] = $notice([
        'IdTransaccion' => 'tx-test',
        'Monto' => 9.41,
        'ResultadoTransaccion' => 'ExitosaAprobada',
        'EsProductiva' => false,
    ]);
    $this->call('POST', '/webhooks/payments/wompi', [], [], [], [
        'HTTP_WOMPI_HASH' => $hash,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    [$body, $hash] = $notice([
        'IdTransaccion' => 'tx-short',
        'Monto' => 1,
        'ResultadoTransaccion' => 'ExitosaAprobada',
        'EsProductiva' => true,
    ]);
    $this->call('POST', '/webhooks/payments/wompi', [], [], [], [
        'HTTP_WOMPI_HASH' => $hash,
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $this->call('POST', '/webhooks/payments/wompi', [], [], [], [
        'HTTP_WOMPI_HASH' => 'not-the-hash',
        'CONTENT_TYPE' => 'application/json',
    ], '{"Monto":9.41}')->assertStatus(400);

    expect(Server::query()->where('name', 'cliente-uno')->exists())->toBeFalse()
        ->and($order->fresh()->status)->toBe('awaiting_payment');
});

it('creates the server when the buyer returns from a live Wompi charge', function () {
    getOdooHetznerFake();

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update([
        'getodoo_hetzner_token_id' => $token->id,
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 22,
        'name' => 'cx22',
        'description' => 'CX22',
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'architecture' => 'x86',
        'monthly_price' => 4.99,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 4.99]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'offer_id' => $offer->id,
        'private_key_id' => $key->id,
        'server_name' => 'cliente-uno',
        'location' => 'fsn1',
        'amount' => $offer->sellPrice('fsn1'),
        'status' => 'awaiting_payment',
        'wompi_link_id' => '15',
    ]);
    $amount = number_format((float) $order->amount, 2, '.', '');
    $hash = app(WompiClient::class)->paymentLinkHash($order->uuid, 'tx-return-1', '15', $amount);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    $url = route('getodoo.wompi.return', ['order' => $order]).'?'.http_build_query([
        'idTransaccion' => 'tx-return-1',
        'idEnlace' => '15',
        'monto' => $amount,
        'hash' => $hash,
    ]);

    $this->get($url)->assertRedirect();

    $server = Server::query()->where('name', 'cliente-uno')->first();

    expect($server)->not->toBeNull()
        ->and($order->fresh()->status)->toBe('provisioned');

    $this->get($url)->assertRedirect(route('server.show', ['server_uuid' => $server->uuid]));
    expect(Server::query()->where('name', 'cliente-uno')->count())->toBe(1);
});

it('keeps a saved Wompi secret when the owner leaves the field empty', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooServers::class)
        ->set('wompiClientId', 'client-1')
        ->set('wompiClientSecret', 'secret-1')
        ->call('saveWompi')
        ->assertHasNoErrors();

    expect(instanceSettings()->wompi_client_id)->toBe('client-1')
        ->and(instanceSettings()->wompi_client_secret)->toBe('secret-1');

    Livewire::test(GetOdooServers::class)
        ->set('wompiClientSecret', '')
        ->call('saveWompi')
        ->assertHasNoErrors();

    expect(instanceSettings()->wompi_client_id)->toBe('client-1')
        ->and(instanceSettings()->wompi_client_secret)->toBe('secret-1');
});
