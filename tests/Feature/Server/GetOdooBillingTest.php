<?php

use App\Livewire\Server\Billing;
use App\Livewire\Settings\GetOdooPurchases;
use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->root = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
});

function getOdooBillingOffer(): GetOdooServerOffer
{
    return GetOdooServerOffer::query()->create([
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
}

function getOdooBillingServer(Team $team, GetOdooServerOffer $offer, string $paidUntil = '2026-11-05'): Server
{
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create([
        'name' => 'cliente-uno',
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'getodoo_offer_id' => $offer->id,
        'getodoo_monthly_price' => 93,
        'getodoo_paid_until' => $paidUntil,
    ]);
}

function getOdooWompiFake(): void
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

        return Http::response(['error' => 'unexpected '.$url], 500);
    });
}

it('shows every purchase to the instance owner and keeps the next charge at the saved price', function () {
    $offer = getOdooBillingOffer();
    $team = Team::factory()->create(['name' => 'Cliente']);
    $server = getOdooBillingServer($team, $offer);
    GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => User::factory()->create()->id,
        'offer_id' => $offer->id,
        'private_key_id' => $server->private_key_id,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'location' => 'fsn1',
        'amount' => 93,
        'status' => 'provisioned',
        'purpose' => 'launch',
    ]);

    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->assertSee('Cliente')
        ->assertSee('cliente-uno')
        ->assertSee('5 Nov 2026')
        ->set('prices.'.$server->id, '97.65')
        ->call('savePrice', $server->id)
        ->assertHasNoErrors();

    expect((float) $server->fresh()->getodoo_monthly_price)->toBe(97.65);

    Livewire::test(GetOdooPurchases::class)
        ->set('prices.'.$server->id, '0')
        ->call('savePrice', $server->id)
        ->assertHasErrors(['prices.'.$server->id]);

    expect((float) $server->fresh()->getodoo_monthly_price)->toBe(97.65);
});

it('does not open purchases for a team admin', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(GetOdooPurchases::class)->assertRedirect(route('dashboard'));
});

it('shows the next billing date and opens a renewal charge without creating another server', function () {
    getOdooWompiFake();
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer);
    $hidden = getOdooBillingServer($other, $offer);
    $hidden->update(['name' => 'otro-cliente']);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->assertSee('cliente-uno')
        ->assertSee('5 Nov 2026')
        ->assertSee('Pay')
        ->assertDontSee('otro-cliente')
        ->call('pay', $server->id)
        ->assertRedirect('https://lk.wompi.sv/yhDt');

    $renewal = GetOdooServerOrder::query()->where('purpose', 'renewal')->first();

    expect(Server::query()->where('team_id', $team->id)->count())->toBe(1)
        ->and($renewal)->not->toBeNull()
        ->and($renewal->status)->toBe('awaiting_payment')
        ->and((int) $renewal->server_id)->toBe($server->id)
        ->and((float) $renewal->amount)->toBe(93.0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.hetzner.cloud'));

    Livewire::test(Billing::class)
        ->call('pay', $server->id)
        ->assertRedirect('https://lk.wompi.sv/yhDt');

    expect(GetOdooServerOrder::query()->where('purpose', 'renewal')->count())->toBe(1);
});

it('does not open billing for a member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)->assertRedirect(route('server.index'));
});

it('extends the paid date when a renewal charge is approved and does not create a server', function () {
    Http::preventStrayRequests();
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-12-15');
    $admin = User::factory()->create();
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'offer_id' => $offer->id,
        'private_key_id' => $server->private_key_id,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'location' => 'fsn1',
        'amount' => 93,
        'status' => 'awaiting_payment',
        'purpose' => 'renewal',
    ]);

    $payload = [
        'IdTransaccion' => 'tx-renewal-1',
        'Monto' => 93,
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

    expect($server->fresh()->getodoo_paid_until?->toDateString())->toBe('2027-01-15')
        ->and($order->fresh()->status)->toBe('paid')
        ->and(Server::query()->where('team_id', $team->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps the next billing day on the initial payment even when that date already passed', function () {
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-09-01');
    $admin = User::factory()->create();
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'offer_id' => $offer->id,
        'private_key_id' => $server->private_key_id,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'location' => 'fsn1',
        'amount' => 93,
        'status' => 'awaiting_payment',
        'purpose' => 'renewal',
    ]);

    $payload = [
        'IdTransaccion' => 'tx-renewal-past',
        'Monto' => 93,
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

    $server->refresh();

    expect($server->getodoo_paid_until?->toDateString())->toBe('2026-10-01')
        ->and($server->getodoo_billing_anchor?->toDateString())->toBe('2026-08-01');
});

it('opens the existing Wompi link for a cancelled purchase without creating a server', function () {
    Http::preventStrayRequests();
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'offer_id' => $offer->id,
        'private_key_id' => $key->id,
        'server_name' => 'wompy',
        'location' => 'fsn1',
        'amount' => 10.19,
        'status' => 'awaiting_payment',
        'purpose' => 'launch',
        'wompi_link_url' => 'https://lk.wompi.sv/yhDt',
    ]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->assertSee('wompy')
        ->assertSee('Pending payment')
        ->assertSee('Pay')
        ->assertSee('No subscriptions yet.')
        ->call('payOrder', $order->id)
        ->assertRedirect('https://lk.wompi.sv/yhDt');

    expect(Server::query()->where('team_id', $team->id)->count())->toBe(0)
        ->and($order->fresh()->status)->toBe('awaiting_payment');
    Http::assertNothingSent();
});
