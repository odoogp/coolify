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
use App\Services\GetOdoo\N1coClient;
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

it('keeps a saved n1co secret when the owner leaves the field empty', function () {
    $owner = User::factory()->create();
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(GetOdooServers::class)
        ->set('n1coSecret', 'secret-n1co')
        ->set('n1coWebhookSecret', 'whsec-n1co')
        ->set('n1coSandbox', true)
        ->set('n1coCharges', true)
        ->call('saveN1co')
        ->assertHasNoErrors();

    $settings = instanceSettings()->fresh();

    expect($settings->n1co_secret)->toBe('secret-n1co')
        ->and($settings->n1co_webhook_secret)->toBe('whsec-n1co')
        ->and($settings->n1co_sandbox)->toBeTrue()
        ->and($settings->n1co_charges)->toBeTrue();

    Livewire::test(GetOdooServers::class)
        ->set('n1coSecret', '')
        ->set('n1coWebhookSecret', '')
        ->set('n1coSandbox', true)
        ->set('n1coCharges', true)
        ->call('saveN1co')
        ->assertHasNoErrors();

    $settings = instanceSettings()->fresh();

    expect($settings->n1co_secret)->toBe('secret-n1co')
        ->and($settings->n1co_webhook_secret)->toBe('whsec-n1co');
});

it('sends the buyer to the n1co sandbox link and does not create the server yet', function () {
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'api-pay-sandbox.n1co.shop/api/paymentlink/checkout') && $request->method() === 'POST') {
            return Http::response([
                'orderCode' => 'ABC123',
                'orderId' => 21566,
                'paymentLinkUrl' => 'https://pay.n1co.shop/ABC123',
            ]);
        }

        return Http::response(['error' => 'unexpected '.$request->url()], 500);
    });

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
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
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
        ->set('server_name', 'cliente-n1co')
        ->set('location', 'fsn1')
        ->set('private_key_id', $key->id)
        ->call('submit')
        ->assertRedirect('https://pay.n1co.shop/ABC123');

    $order = GetOdooServerOrder::query()->first();

    expect(Server::query()->where('name', 'cliente-n1co')->exists())->toBeFalse()
        ->and($order)->not->toBeNull()
        ->and($order->status)->toBe('awaiting_payment')
        ->and($order->payment_gateway)->toBe('n1co')
        ->and($order->wompi_link_id)->toBe('ABC123')
        ->and((float) $order->amount)->toBe($offer->sellPrice('fsn1'));

    Http::assertSent(function ($request) use ($order) {
        $authorization = $request->header('Authorization')[0] ?? '';

        return $request->method() === 'POST'
            && str_contains($request->url(), 'api-pay-sandbox.n1co.shop/api/paymentlink/checkout')
            && $authorization === 'Bearer secret-n1co'
            && ($request->data()['orderReference'] ?? null) === $order->uuid
            && round((float) ($request->data()['amount'] ?? 0), 2) === round((float) $order->amount, 2);
    });
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.wompi.sv'));
});

it('rejects an n1co notice with a bad signature', function () {
    instanceSettings()->update([
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
    ]);

    $this->call('POST', '/webhooks/payments/n1co', [], [], [], [
        'HTTP_X_H4B_HMAC_SHA256' => 'not-the-signature',
        'CONTENT_TYPE' => 'application/json',
    ], '{"type":"SuccessPayment"}')->assertStatus(401);
});

it('creates the server when n1co signs a paid sandbox charge', function () {
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'api-pay-sandbox.n1co.shop/api/paymentlink/order/')) {
            $amount = (float) (GetOdooServerOrder::query()->latest('id')->value('amount') ?? 0);

            return Http::response([
                'orderCode' => 'ABC123',
                'orderStatus' => 'PAID',
                'total' => $amount,
                'payment' => ['authorizationCode' => '831000'],
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

        return Http::response(['error' => 'unexpected '.$url], 500);
    });

    $token = CloudProviderToken::create([
        'team_id' => 0,
        'provider' => 'hetzner',
        'token' => 'owner-hetzner-token',
        'name' => 'Instance Hetzner',
    ]);
    instanceSettings()->update([
        'getodoo_hetzner_token_id' => $token->id,
        'n1co_secret' => 'secret-n1co',
        'n1co_webhook_secret' => 'whsec-n1co',
        'n1co_sandbox' => true,
        'n1co_charges' => true,
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
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $order = GetOdooServerOrder::query()->create([
        'team_id' => $team->id,
        'user_id' => $admin->id,
        'offer_id' => $offer->id,
        'private_key_id' => $key->id,
        'server_name' => 'cliente-n1co',
        'location' => 'fsn1',
        'amount' => $offer->sellPrice('fsn1'),
        'status' => 'awaiting_payment',
        'purpose' => 'launch',
        'payment_gateway' => 'n1co',
        'wompi_link_id' => 'ABC123',
        'wompi_link_url' => 'https://pay.n1co.shop/ABC123',
    ]);

    $body = json_encode([
        'orderId' => '21566',
        'orderReference' => $order->uuid,
        'orderType' => 'FASTLINK_CHARGE',
        'description' => 'La orden fue pagada exitosamente',
        'level' => 'Info',
        'type' => 'SuccessPayment',
        'metadata' => [
            'PaymentId' => 'pay-n1co-1',
            'Status' => 'SUCCEEDED',
        ],
    ], JSON_THROW_ON_ERROR);
    $hash = app(N1coClient::class)->webhookHash($body);

    $this->call('POST', '/webhooks/payments/n1co', [], [], [], [
        'HTTP_X_H4B_HMAC_SHA256' => $hash,
        'HTTP_X_H4B_EVENT_ID' => '1201',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    $this->call('POST', '/webhooks/payments/n1co', [], [], [], [
        'HTTP_X_H4B_HMAC_SHA256' => $hash,
        'HTTP_X_H4B_EVENT_ID' => '1201',
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    expect(Server::query()->where('name', 'cliente-n1co')->count())->toBe(1)
        ->and($order->fresh()->status)->toBe('provisioned')
        ->and($order->fresh()->wompi_transaction_id)->toBe('pay-n1co-1');

    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/servers'))->count())->toBe(1);
});
