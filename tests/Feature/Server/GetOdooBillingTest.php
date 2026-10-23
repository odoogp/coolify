<?php

use App\Livewire\Server\Billing;
use App\Livewire\Settings\GetOdooPurchases;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\GetOdooPlan;
use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\SettleGetOdooServerPayment;
use App\Services\GetOdoo\WompiClient;
use App\Support\GetOdooBillingOccupancy;
use App\Support\GetOdooServerBilling;
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

it('shows the billing center to the instance owner and keeps the next charge at the saved price', function () {
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
        ->assertSee(__('Billing'))
        ->assertSee(__('Clients'))
        ->assertSee(__('Purchases'))
        ->assertSee(__('Invoices'))
        ->assertSee(__('Client monthly'))
        ->call('setTab', 'purchases')
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

it('does not open the billing center for a team admin', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(GetOdooPurchases::class)->assertRedirect(route('dashboard'));
});

it('lets the instance owner create a one-time client invoice from purchases', function () {
    getOdooWompiFake();
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create(['name' => 'Cliente']);
    $server = getOdooBillingServer($team, $offer, '2026-10-01');
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->call('openServerInvoiceForm', $server->id)
        ->assertSet('showInvoiceForm', true)
        ->set('invoiceKind', 'once')
        ->set('invoiceAmount', '40.00')
        ->set('invoiceDescription', 'Setup extra')
        ->call('submitInvoice')
        ->assertDispatched('success');

    $invoice = GetOdooServerOrder::query()->where('purpose', 'renewal')->where('server_id', $server->id)->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('awaiting_payment')
        ->and((int) $invoice->team_id)->toBe($team->id)
        ->and((float) $invoice->amount)->toBe(40.0)
        ->and($invoice->description)->toBe('Setup extra')
        ->and($invoice->charge_mode)->toBe('once')
        ->and((float) $server->fresh()->getodoo_monthly_price)->toBe(93.0);
});

it('adds a recurring extra to the server monthly total and can charge it today', function () {
    getOdooWompiFake();
    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create(['name' => 'Cliente']);
    $server = getOdooBillingServer($team, $offer, '2026-10-01');
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->call('openServerInvoiceForm', $server->id)
        ->set('invoiceKind', 'recurring')
        ->set('invoiceAmount', '10.00')
        ->set('invoiceDescription', 'Extra storage')
        ->set('invoiceChargeNow', true)
        ->call('submitInvoice')
        ->assertDispatched('success');

    expect((float) $server->fresh()->getodoo_monthly_price)->toBe(103.0)
        ->and((float) GetOdooServerOrder::query()->where('server_id', $server->id)->where('purpose', 'renewal')->value('amount'))->toBe(10.0);
});

it('can raise the monthly total without creating an invoice today', function () {
    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer);
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->call('openServerInvoiceForm', $server->id)
        ->set('invoiceKind', 'recurring')
        ->set('invoiceAmount', '15')
        ->set('invoiceChargeNow', false)
        ->call('submitInvoice')
        ->assertDispatched('success');

    expect((float) $server->fresh()->getodoo_monthly_price)->toBe(108.0)
        ->and(GetOdooServerOrder::query()->where('purpose', 'renewal')->count())->toBe(0);
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
        ->assertDontSee('No subscriptions yet.')
        ->call('payOrder', $order->id)
        ->assertRedirect('https://lk.wompi.sv/yhDt');

    expect(Server::query()->where('team_id', $team->id)->count())->toBe(0)
        ->and($order->fresh()->status)->toBe('awaiting_payment');
    Http::assertNothingSent();
});

it('groups payments for the same server into one subscription', function () {
    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);

    foreach (['Fdgt', 'fdgt'] as $name) {
        GetOdooServerOrder::query()->create([
            'team_id' => $team->id,
            'user_id' => $admin->id,
            'offer_id' => $offer->id,
            'private_key_id' => $key->id,
            'server_name' => $name,
            'location' => 'fsn1',
            'amount' => 10.19,
            'status' => 'awaiting_payment',
            'purpose' => 'launch',
        ]);
    }

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    $html = Livewire::test(Billing::class)->assertSee('Pending payment')->html();

    expect(substr_count($html, 'Fdgt') + substr_count($html, 'fdgt'))->toBe(1);
});

it('cancels a pending purchase that never created a server', function () {
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
        'server_name' => 'Fdgt',
        'location' => 'fsn1',
        'amount' => 10.19,
        'status' => 'awaiting_payment',
        'purpose' => 'launch',
        'wompi_link_url' => 'https://lk.wompi.sv/yhDt',
    ]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->assertSee('Cancel')
        ->call('cancelOrder', $order->id);

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->wompi_link_url)->toBeNull()
        ->and(Server::query()->where('team_id', $team->id)->count())->toBe(0);

    instanceSettings()->update([
        'wompi_client_id' => 'client-1',
        'wompi_client_secret' => 'secret-1',
    ]);
    $payload = [
        'IdTransaccion' => 'tx-after-cancel',
        'Monto' => 10.19,
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

    expect(Server::query()->where('name', 'Fdgt')->count())->toBe(0)
        ->and($order->fresh()->status)->toBe('cancelled');
});

it('deletes the Hetzner server when the plan is cancelled', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.hetzner.cloud/v1/servers/99' => Http::response(null, 200),
    ]);

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update(['getodoo_hetzner_token_id' => $token->id]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer);
    $server->update(['hetzner_server_id' => 99]);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);
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
        'wompi_link_url' => 'https://lk.wompi.sv/yhDt',
    ]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)->call('cancelPlan', $server->id);

    expect(Server::query()->whereKey($server->id)->exists())->toBeFalse()
        ->and($order->fresh()->status)->toBe('cancelled')
        ->and($order->fresh()->wompi_link_url)->toBeNull();

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/servers/99');
    });
});

it('enables recurring for the next invoice without charging today', function () {
    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-11-20');
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->assertSee(__('Make recurring'))
        ->call('enableRecurring', $server->id)
        ->assertDispatched('success')
        ->assertSee(__('Cancel recurring'))
        ->assertDontSee(__('Make recurring'));

    $server->refresh();

    expect($server->prefersRecurringBilling())->toBeTrue()
        ->and($server->getodoo_recurring_starts_at?->toDateString())->toBe('2026-11-20')
        ->and(GetOdooServerOrder::query()->where('purpose', 'renewal')->count())->toBe(0);
});

it('turns recurring off again', function () {
    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-11-20');
    $server->update([
        'getodoo_billing_preference' => 'recurring',
        'getodoo_recurring_starts_at' => '2026-11-20',
    ]);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->call('disableRecurring', $server->id)
        ->assertDispatched('success')
        ->assertSee(__('Make recurring'));

    expect($server->fresh()->prefersRecurringBilling())->toBeFalse()
        ->and($server->fresh()->getodoo_recurring_starts_at)->toBeNull();
});

it('stamps once preference from hybrid instance mode and keeps it on renewal', function () {
    instanceSettings()->update(['n1co_server_charge_mode' => 'hybrid']);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-10-20');
    $server->update([
        'getodoo_billing_preference' => 'once',
        'getodoo_recurring_starts_at' => null,
        'getodoo_billing_anchor' => '2026-09-20',
    ]);

    GetOdooServerBilling::stampPreference($server, forceInitial: true);

    expect($server->fresh()->getodoo_billing_preference)->toBe('once')
        ->and($server->fresh()->getodoo_recurring_starts_at)->toBeNull();

    $server->update([
        'getodoo_billing_preference' => 'recurring',
        'getodoo_recurring_starts_at' => '2026-10-20',
    ]);

    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => User::factory()->create()->id,
        'offer_id' => $offer->id,
        'private_key_id' => $server->private_key_id,
        'server_id' => $server->id,
        'server_name' => $server->name,
        'location' => 'fsn1',
        'amount' => 93,
        'status' => 'awaiting_payment',
        'purpose' => 'renewal',
        'payment_gateway' => 'wompi',
        'charge_mode' => 'once',
    ]);

    app(SettleGetOdooServerPayment::class)->settle($order, 'tx-renew-pref');

    $server->refresh();

    expect($server->prefersRecurringBilling())->toBeTrue()
        ->and($server->getodoo_recurring_starts_at?->toDateString())->toBe('2026-10-20')
        ->and($server->getodoo_paid_until?->toDateString())->toBe('2026-11-20')
        ->and($order->fresh()->status)->toBe('paid');
});

it('uses once for the first hybrid charge and subscription after recurring starts', function () {
    instanceSettings()->update(['n1co_server_charge_mode' => 'hybrid']);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-11-20');

    $launch = new GetOdooServerOrder(['purpose' => 'launch']);
    $renewal = new GetOdooServerOrder(['purpose' => 'renewal']);

    expect(GetOdooServerBilling::chargeModeForOrder($launch, $server))->toBe(GetOdooPlan::CHARGE_ONCE)
        ->and(GetOdooServerBilling::chargeModeForOrder($renewal, $server))->toBe(GetOdooPlan::CHARGE_ONCE);

    $server->update([
        'getodoo_billing_preference' => 'recurring',
        'getodoo_recurring_starts_at' => '2026-11-20',
    ]);

    // Still inside the paid period — pay once if they renew early.
    expect(GetOdooServerBilling::chargeModeForOrder($renewal, $server->fresh()))->toBe(GetOdooPlan::CHARGE_ONCE);

    $this->travelTo('2026-11-20 12:00:00');

    expect(GetOdooServerBilling::chargeModeForOrder($renewal, $server->fresh()))->toBe(GetOdooPlan::CHARGE_SUBSCRIPTION);
});

it('stamps recurring preference when the instance mode is subscription', function () {
    instanceSettings()->update(['n1co_server_charge_mode' => 'subscription']);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-12-01');
    $server->update([
        'getodoo_billing_preference' => 'once',
        'getodoo_recurring_starts_at' => null,
    ]);

    GetOdooServerBilling::stampPreference($server, forceInitial: true);

    expect($server->fresh()->getodoo_billing_preference)->toBe('recurring')
        ->and($server->fresh()->getodoo_recurring_starts_at?->toDateString())->toBe('2026-12-01')
        ->and(GetOdooServerBilling::initialPreference())->toBe('recurring');
});

it('opens an n1co subscription checkout when make recurring is used on an overdue invoice', function () {
    $this->travelTo('2026-11-25 12:00:00');
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
        'n1co_location_id' => 578,
        'n1co_client_id' => 'client-n1co',
        'n1co_client_secret' => 'client-secret-n1co',
        'n1co_server_charge_mode' => 'hybrid',
    ]);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/v3/Token') && $request->method() === 'POST') {
            return Http::response(['accessToken' => 'access-n1co', 'expiresIn' => 3600]);
        }

        if (str_contains($request->url(), '/api/v3/Plans') && $request->method() === 'POST') {
            return Http::response([
                'planId' => 1810,
                'paymentLink' => [
                    'code' => 'SUB-RECUR',
                    'linkUrl' => 'https://pay-sandbox.n1co.shop/pl/SUB-RECUR',
                ],
            ]);
        }

        return Http::response(['error' => 'unexpected '.$request->url()], 500);
    });

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-11-20');
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    $component = Livewire::test(Billing::class)->call('enableRecurring', $server->id);
    $order = GetOdooServerOrder::query()->where('purpose', 'renewal')->first();

    expect($order)->not->toBeNull();
    $component->assertRedirect(route('getodoo.n1co.return', ['order' => $order->uuid]));

    expect($server->fresh()->prefersRecurringBilling())->toBeTrue()
        ->and($order->charge_mode)->toBe('subscription')
        ->and($order->n1co_plan_id)->toBe('1810')
        ->and($order->payment_gateway)->toBe('n1co');
});

it('keeps make recurring charge-free while the period is still paid and drops a pending once link', function () {
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
        'n1co_server_charge_mode' => 'hybrid',
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-12-20');
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    GetOdooServerOrder::query()->create([
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
        'payment_gateway' => 'n1co',
        'charge_mode' => 'once',
        'wompi_link_url' => 'https://pay.n1co.shop/ONCE',
    ]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->call('enableRecurring', $server->id)
        ->assertDispatched('success');

    expect($server->fresh()->getodoo_recurring_starts_at?->toDateString())->toBe('2026-12-20')
        ->and(GetOdooServerOrder::query()->where('purpose', 'renewal')->where('status', 'awaiting_payment')->count())->toBe(0)
        ->and(GetOdooServerOrder::query()->where('status', 'cancelled')->count())->toBe(1);
});

it('cancels the n1co subscription when recurring is turned off', function () {
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
        'n1co_location_id' => 578,
        'n1co_client_id' => 'client-n1co',
        'n1co_client_secret' => 'client-secret-n1co',
        'n1co_server_charge_mode' => 'hybrid',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        '*/api/v3/Token' => Http::response(['accessToken' => 'access-n1co', 'expiresIn' => 3600]),
        '*/api/v3/Subscriptions/*' => Http::response([], 204),
    ]);

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-12-20');
    $server->update([
        'getodoo_billing_preference' => 'recurring',
        'getodoo_recurring_starts_at' => '2026-12-20',
        'n1co_plan_id' => '1810',
        'n1co_subscription_id' => '242',
        'n1co_subscription_status' => 'active',
    ]);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Billing::class)
        ->call('disableRecurring', $server->id)
        ->assertDispatched('success');

    expect($server->fresh()->prefersRecurringBilling())->toBeFalse()
        ->and($server->fresh()->n1co_subscription_status)->toBe('cancelled');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/Subscriptions/242'));
});

it('lists organization principals and lets the owner toggle plan recurring', function () {
    $plan = GetOdooPlan::query()->create([
        'name' => 'Starter',
        'price' => 29,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'hybrid',
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 3,
    ]);
    $org = Team::factory()->create([
        'name' => 'Acme Org',
        'getodoo_plan_id' => $plan->id,
        'getodoo_plan_paid_until' => '2026-12-01',
        'getodoo_plan_billing_preference' => 'once',
    ]);
    $principal = User::factory()->create(['name' => 'Ada Principal', 'email' => 'ada@acme.test']);
    $org->members()->attach($principal->id, ['role' => 'owner']);

    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->assertSee('Acme Org')
        ->assertSee('Ada Principal')
        ->assertSee('ada@acme.test')
        ->assertSee('Starter')
        ->assertSee(__('One-time'))
        ->call('enablePlanRecurring', $org->id)
        ->assertDispatched('success')
        ->assertSee(__('Cancel recurring'));

    expect($org->fresh()->prefersPlanRecurringBilling())->toBeTrue()
        ->and($org->fresh()->getodoo_plan_recurring_starts_at?->toDateString())->toBe('2026-12-01');

    Livewire::test(GetOdooPurchases::class)
        ->call('disablePlanRecurring', $org->id)
        ->assertDispatched('success');

    expect($org->fresh()->prefersPlanRecurringBilling())->toBeFalse()
        ->and($org->fresh()->getodoo_plan_recurring_starts_at)->toBeNull();
});

it('lets the owner make a sold server recurring without charging today', function () {
    $offer = getOdooBillingOffer();
    $team = Team::factory()->create(['name' => 'Cliente']);
    $server = getOdooBillingServer($team, $offer, '2026-12-15');
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->call('setTab', 'purchases')
        ->assertSee(__('Your cost'))
        ->assertSee(__('Client pays'))
        ->call('enableServerRecurring', $server->id)
        ->assertDispatched('success');

    expect($server->fresh()->prefersRecurringBilling())->toBeTrue()
        ->and($server->fresh()->getodoo_recurring_starts_at?->toDateString())->toBe('2026-12-15')
        ->and(GetOdooServerOrder::query()->where('purpose', 'renewal')->count())->toBe(0);
});

it('shows instance-host occupancy for client teams with services on server 0', function () {
    $key = PrivateKey::factory()->create(['team_id' => $this->root->id]);
    Server::factory()->create([
        'id' => 0,
        'name' => 'localhost',
        'team_id' => $this->root->id,
        'private_key_id' => $key->id,
    ]);

    $plan = GetOdooPlan::query()->create([
        'name' => 'Shared',
        'price' => 19,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'charge_mode' => 'once',
        'max_projects' => 1,
        'max_environments' => 2,
        'max_members' => 2,
    ]);
    $clientTeam = Team::factory()->create([
        'name' => 'Hosted Client',
        'getodoo_plan_id' => $plan->id,
    ]);
    $principal = User::factory()->create(['name' => 'Hosted Principal']);
    $clientTeam->members()->attach($principal->id, ['role' => 'owner']);

    $destination = StandaloneDocker::factory()->create([
        'server_id' => 0,
        'network' => 'coolify-billing-occupancy',
    ]);
    $project = Project::factory()->create(['team_id' => $clientTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    Service::factory()->create([
        'name' => 'odoo-hosted',
        'server_id' => 0,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'environment_id' => $environment->id,
    ]);

    expect(GetOdooBillingOccupancy::teamsOnInstanceHost()->pluck('id')->all())->toContain($clientTeam->id);

    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->call('setTab', 'purchases')
        ->assertSee(__('Instance host'))
        ->assertSee('Hosted Client')
        ->assertSee('Hosted Principal')
        ->assertSee('Shared');
});

it('lists client-connected servers on the purchases tab', function () {
    $team = Team::factory()->create(['name' => 'Bring Own Box']);
    $principal = User::factory()->create(['name' => 'Own Box Owner']);
    $team->members()->attach($principal->id, ['role' => 'owner']);
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    Server::factory()->create([
        'name' => 'cliente-ssh',
        'team_id' => $team->id,
        'private_key_id' => $key->id,
        'getodoo_offer_id' => null,
        'ip' => '203.0.113.10',
    ]);

    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooPurchases::class)
        ->call('setTab', 'purchases')
        ->assertSee(__('Client-connected servers'))
        ->assertSee('cliente-ssh')
        ->assertSee('Bring Own Box')
        ->assertSee('Own Box Owner')
        ->assertSee(__('Connected'));
});

it('pays a due renewal with an n1co subscription after the client opted in', function () {
    $this->travelTo('2026-11-20 12:00:00');
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
        'n1co_location_id' => 578,
        'n1co_client_id' => 'client-n1co',
        'n1co_client_secret' => 'client-secret-n1co',
        'n1co_server_charge_mode' => 'hybrid',
    ]);
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/v3/Token') && $request->method() === 'POST') {
            return Http::response(['accessToken' => 'access-n1co', 'expiresIn' => 3600]);
        }

        if (str_contains($request->url(), '/api/v3/Plans') && $request->method() === 'POST') {
            return Http::response([
                'planId' => 1900,
                'paymentLink' => [
                    'code' => 'SUB-PAY',
                    'linkUrl' => 'https://pay-sandbox.n1co.shop/pl/SUB-PAY',
                ],
            ]);
        }

        return Http::response(['error' => 'unexpected '.$request->url()], 500);
    });

    $offer = getOdooBillingOffer();
    $team = Team::factory()->create();
    $server = getOdooBillingServer($team, $offer, '2026-11-20');
    $server->update([
        'getodoo_billing_preference' => 'recurring',
        'getodoo_recurring_starts_at' => '2026-11-20',
    ]);
    $admin = User::factory()->create();
    $team->members()->attach($admin->id, ['role' => 'admin', 'can_add_servers' => true]);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    $component = Livewire::test(Billing::class)->call('pay', $server->id);
    $order = GetOdooServerOrder::query()->where('purpose', 'renewal')->first();

    expect($order)->not->toBeNull();
    $component->assertRedirect(route('getodoo.n1co.return', ['order' => $order->uuid]));

    expect($order->charge_mode)->toBe('subscription')
        ->and($order->n1co_plan_id)->toBe('1900');
});
